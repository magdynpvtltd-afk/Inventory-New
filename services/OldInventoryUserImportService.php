<?php
/**
 * MagDyn — Old Inventory User Import Service (API version)
 *
 * Fetches application users from the legacy inventory system via the
 * HTTP API (api_export_vendors.php → action=users) and imports them into
 * the MagDyn users table.
 *
 * Field mapping (old → new):
 *   user_account.username            → users.username     (match key)
 *   user_account.first/last_name     → users.full_name
 *   user_account.email_address       → users.email        (synthesised if blank)
 *   user_account.active_flag         → users.is_active
 *   (constant) "admin123"            → users.password_hash (new users only)
 *
 * Password & roles:
 *   Every newly-imported user is given the default password "admin123"
 *   (hashed with the app pepper, exactly like users.php / setup.php) and
 *   the "Viewer" role.
 *   Existing MagDyn users are left completely untouched — not updated, and
 *   their password is never changed — so real accounts (admins included)
 *   are never disturbed.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryUserImportService.php';
 *   $svc    = new OldInventoryUserImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryUserImportService
{
    /** Records per API call / DB transaction batch */
    private const BATCH_SIZE = 200;

    /** Default password assigned to every imported (new) user */
    private const DEFAULT_PASSWORD = 'admin123';

    /** @var int  User ID credited as actor (unused for writes but kept for parity) */
    private int $actorId;

    /** @var int|null  Cached "Viewer" role id (false-y resolution guarded by $viewerResolved) */
    private ?int $viewerRoleId = null;

    /** @var bool  Whether the Viewer role lookup has been attempted */
    private bool $viewerResolved = false;

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var callable|null  Progress reporter: fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'user_total'    => 0,
        'user_imported' => 0,
        'user_updated'  => 0,
        'user_failed'   => 0,
        'user_skipped'  => 0,
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    /**
     * Register a progress reporter, invoked as fn(string $phase, int $done, int $total)
     * after each batch so callers (e.g. the streaming import page) can show a bar.
     */
    public function setProgressCallback(callable $cb): void
    {
        $this->onProgress = $cb;
    }

    private function emitProgress(string $phase, int $done, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($phase, $done, $total);
        }
    }

    /**
     * @return array{user_total:int,user_imported:int,user_updated:int,user_failed:int,user_skipped:int,errors:array}
     */
    public function run(): array
    {
        $countData = old_inventory_vendor_api('user_count');
        $this->counts['user_total'] = (int) ($countData['count'] ?? 0);
        $this->log("Users: {$this->counts['user_total']} accounts found in source.");

        $total     = $this->counts['user_total'];
        $processed = 0;
        $this->emitProgress('Users', 0, $total);

        $offset = 0;
        while (true) {
            $data  = old_inventory_vendor_api('users', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['users'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $row) {
                try {
                    $this->processOneUser($row);
                } catch (Throwable $e) {
                    $this->counts['user_failed']++;
                    $this->log("Failed user '{$row['username']}': " . $e->getMessage(), 'error');
                }
            }

            $processed += count($batch);
            $this->emitProgress('Users', min($processed, $total), $total);

            $offset += self::BATCH_SIZE;
            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }

        $this->emitProgress('Users', $total, $total);

        $this->log(
            "Users done — " .
            "Imported: {$this->counts['user_imported']}, " .
            "Updated: {$this->counts['user_updated']}, " .
            "Failed: {$this->counts['user_failed']}, " .
            "Skipped: {$this->counts['user_skipped']}."
        );

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    private function processOneUser(array $row): void
    {
        $username = trim((string) ($row['username'] ?? ''));
        if ($username === '') {
            $this->counts['user_skipped']++;
            $this->log("Skipped user_account_id={$row['user_account_id']}: empty username.", 'warn');
            return;
        }
        $username = substr($username, 0, 64);

        $fullName = $this->personName($row);
        if ($fullName === '') {
            $fullName = $username;
        }
        $fullName = substr($fullName, 0, 190);

        $isActive = (int) ($row['active'] ?? 0) ? 1 : 0;

        // Existing users are never touched — no profile update, no password
        // change. This protects real MagDyn accounts (admins included).
        $existing = db_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]);
        if ($existing) {
            $this->counts['user_skipped']++;
            return;
        }

        $email = $this->resolveEmail($row, $username, null);

        $pepper = $GLOBALS['APP']['password_pepper'] ?? '';
        $hash   = password_hash(self::DEFAULT_PASSWORD . $pepper, PASSWORD_DEFAULT);

        db_exec(
            'INSERT INTO users (username, email, full_name, password_hash, sso_provider, is_active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$username, $email, $fullName, $hash, 'local', $isActive]
        );
        $newId = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);

        // Every imported user gets the Viewer role.
        $viewerId = $this->viewerRoleId();
        if ($viewerId !== null) {
            db_exec(
                'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
                [$newId, $viewerId]
            );
        }

        $this->counts['user_imported']++;
    }

    /** Resolve (and cache) the "Viewer" role id, or null if it doesn't exist. */
    private function viewerRoleId(): ?int
    {
        if (!$this->viewerResolved) {
            $row = db_one("SELECT id FROM roles WHERE code = 'viewer' LIMIT 1");
            $this->viewerRoleId   = $row ? (int) $row['id'] : null;
            $this->viewerResolved = true;
            if ($this->viewerRoleId === null) {
                $this->log("Viewer role (code='viewer') not found — imported users get no role.", 'warn');
            }
        }
        return $this->viewerRoleId;
    }

    /**
     * Resolve a unique, non-empty email for the target row.
     * users.email is NOT NULL + UNIQUE, so synthesise one when the legacy
     * value is blank and suffix on collision with a different user.
     */
    private function resolveEmail(array $row, string $username, $selfId): string
    {
        $email = trim((string) ($row['email_address'] ?? ''));
        if ($email === '' || strcasecmp($email, 'null') === 0) {
            $email = strtolower($username) . '@imported.local';
        }
        $email = substr($email, 0, 190);

        $base   = $email;
        $suffix = 1;
        while (true) {
            $hit = db_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
            if (!$hit || ($selfId !== null && (int) $hit['id'] === (int) $selfId)) {
                break;
            }
            // Collision with a different user — insert +N before the @
            $at    = strpos($base, '@');
            $local = $at === false ? $base : substr($base, 0, $at);
            $dom   = $at === false ? '@imported.local' : substr($base, $at);
            $email = substr($local . '+' . $suffix++, 0, 190 - strlen($dom)) . $dom;
        }

        return $email;
    }

    private function personName(array $row): string
    {
        $first = trim((string) ($row['first_name'] ?? ''));
        $last  = trim((string) ($row['last_name']  ?? ''));
        if ($first !== '' && strcasecmp($first, $last) === 0) {
            return $first;
        }
        if ($last === '-' || $last === '') {
            return $first;
        }
        return trim($first . ' ' . $last);
    }

    private function log(string $message, string $level = 'info'): void
    {
        $this->errors[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => date('H:i:s'),
        ];
    }
}
