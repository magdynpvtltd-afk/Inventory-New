<?php
/**
 * MagDyn — Old Inventory Asset Import Service (API version)
 *
 * Fetches asset records from the legacy inventory_live system via the
 * HTTP API (api_export_assets.php deployed on the old server) and
 * imports them into the new MagDyn assets system.
 *
 * Field mapping (old → new):
 *   asset.asset_id               → assets.asset_tag       (upsert key)
 *   asset.asset_code             → assets.asset_name      (individual asset name)
 *   asset_model.asset_model_code → asset_models.code      (created if missing)
 *   asset_model.short_description→ asset_models.name
 *   category.short_description   → asset_models.category
 *   location.short_description   → locations.name         (matched by name)
 *   checkout_due  (API field)    → assets.checkout_due_on (most recent)
 *   checked_out_flag (API field) → status: 0=active, 1+company=with_vendor, 1+no company=with_user
 *   checkout_due_on cleared to NULL when checked_out_flag=0 (asset returned, old tx history ignored)
 *   cfv_22 / due_back (API)      → informational note
 *   cfv_23 / next_cal_due (API)  → assets.next_cal_due_on
 *   inv_notes class='A' (API)    → notes (entity_type='asset')
 *   notes_attachments filenames  → appended to note body (not physically copied)
 *
 * Duplicate handling:
 *   asset_id already in assets.asset_tag → UPDATE. Otherwise → INSERT.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/old_inventory_api.php';
 *   require_once __DIR__ . '/../services/OldInventoryAssetImportService.php';
 *   $svc    = new OldInventoryAssetImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryAssetImportService
{
    /** Records per API call / DB transaction batch */
    private const BATCH_SIZE = 100;

    /** @var int  User ID credited as creator/editor for imported records */
    private int $actorId;

    /** @var array<string,int>  location name → new locations.id cache */
    private array $locationCache = [];

    /** @var array<string,int>  model code → new asset_models.id cache */
    private array $modelCache = [];

    /** @var array<string,int>  vendor name → vendors.id cache */
    private array $vendorCache = [];

    /** @var array<string,int|null>  username → users.id cache (null = no match) */
    private array $userCache = [];

    /** @var int  Magdyn location ID — fallback when old-system name has no match */
    private int $defaultLocationId = 0;

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var array{total:int,imported:int,updated:int,failed:int,skipped:int,txn_total:int,txn_imported:int,txn_failed:int,txn_skipped:int} */
    private array $counts = [
        'total'        => 0,
        'imported'     => 0,
        'updated'      => 0,
        'failed'       => 0,
        'skipped'      => 0,
        'txn_total'    => 0,
        'txn_imported' => 0,
        'txn_failed'   => 0,
        'txn_skipped'  => 0,
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Run the full import — assets first, then transaction history.
     *
     * @return array{total:int,imported:int,updated:int,failed:int,skipped:int,txn_total:int,txn_imported:int,txn_failed:int,txn_skipped:int,errors:array}
     */
    public function run(): array
    {
        // ── Phase 1: Assets ──────────────────────────────────────────────────
        $countData = old_inventory_api('count');
        $this->counts['total'] = (int) ($countData['count'] ?? 0);
        $this->log("Phase 1 — assets: {$this->counts['total']} found in source.");

        $offset = 0;

        while (true) {
            $data  = old_inventory_api('assets', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['assets'] ?? [];

            if (empty($batch)) {
                break;
            }

            $this->processBatch($batch);
            $offset += self::BATCH_SIZE;

            if (count($batch) < self::BATCH_SIZE) {
                break;  // last page
            }
        }

        $this->log(
            "Assets done — " .
            "Imported: {$this->counts['imported']}, " .
            "Updated: {$this->counts['updated']}, " .
            "Failed: {$this->counts['failed']}, " .
            "Skipped: {$this->counts['skipped']}."
        );

        // ── Phase 2: Transaction history ─────────────────────────────────────
        try {
            $this->importTransactions();
        } catch (\Throwable $e) {
            $this->log('Transaction import aborted: ' . $e->getMessage(), 'error');
        }

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    // ----------------------------------------------------------------
    // Batch processing — each batch in a single DB transaction
    // ----------------------------------------------------------------

    private function processBatch(array $batch): void
    {
        db()->beginTransaction();

        try {
            foreach ($batch as $row) {
                $this->processOneAsset($row);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            // Any models/locations created inside this transaction were rolled
            // back too — clear caches so the next batch re-resolves from the
            // actual DB state instead of using now-invalid IDs.
            $this->modelCache    = [];
            $this->locationCache = [];
            $this->log("Batch transaction rolled back: " . $e->getMessage(), 'error');
            $this->counts['failed'] += count($batch);
        }
    }

    // ----------------------------------------------------------------
    // Single-asset processing
    // ----------------------------------------------------------------

    private function processOneAsset(array $row): void
    {
        $assetId = trim((string) ($row['asset_id'] ?? ''));

        if ($assetId === '') {
            $this->counts['skipped']++;
            $this->log("Skipped record: empty asset_id.", 'warn');
            return;
        }

        try {
            $modelId     = $this->resolveModel($row);
            // Use internal_location (the physical base location in the old system),
            // NOT location_name — location_name is the "effective" location and
            // becomes the vendor/user name when an asset is checked out, which
            // would create spurious location records in MagDyn.
            $locationId  = $this->resolveLocation((string) ($row['internal_location'] ?? ''));
            $nextCalDue  = $this->parseOldDate((string) ($row['next_cal_due'] ?? ''));
            $dueBack     = $this->parseOldDate((string) ($row['due_back']     ?? ''));
            $assetName      = trim((string) ($row['asset_code'] ?? '')) ?: null;
            $companyName    = $row['company_name']   ?? null;
            $checkedOutFlag = (int) ($row['checked_out_flag'] ?? 0); // 1 = currently checked out

            // Use checked_out_flag as the authoritative source.
            // The transaction history alone is unreliable — old inventory
            // doesn't always create a return transaction on check-in;
            // it just flips checked_out_flag back to 0 on the asset row.
            //
            //   checked_out_flag=1 + company_name → with_vendor  (resolve vendor record)
            //   checked_out_flag=1, no company    → with_user    (resolve user by username)
            //   checked_out_flag=0                → active
            //
            // checkout_due_on is only meaningful while checked out.
            $vendorId       = null;
            $userId         = null;
            $issuedDate     = $row['issued_date'] ?? null;   // YYYY-MM-DD from API
            $checkedOutUser = trim((string) ($row['checked_out_user'] ?? ''));

            if ($checkedOutFlag && $companyName) {
                // Checked out to a company/vendor
                $importStatus = 'with_vendor';
                $checkoutDue  = $row['checkout_due'] ?? null;
                $vendorId     = $this->resolveVendor($companyName);
            } elseif ($checkedOutFlag && $checkedOutUser !== '') {
                // Checked out to a named user
                $importStatus = 'with_user';
                $checkoutDue  = $row['checkout_due'] ?? null;
                $userId       = $this->resolveUser($checkedOutUser);
            } else {
                // Not checked out (or no identifiable recipient) — treat as active.
                $importStatus = 'active';
                $checkoutDue  = null;
                $issuedDate   = null;
            }

            $existing = $this->findExistingAsset($assetId);

            if ($existing) {
                $this->updateAsset($existing['id'], [
                    'model_id'          => $modelId,
                    'location_id'       => $locationId,
                    'checkout_due_on'   => $checkoutDue,
                    'next_cal_due_on'   => $nextCalDue,
                    'asset_name'        => $assetName,
                    'status'            => $importStatus,
                    'current_vendor_id' => $vendorId,
                    'current_user_id'   => $userId,
                ]);
                $newAssetId = $existing['id'];
                $this->counts['updated']++;
            } else {
                $newAssetId = $this->insertAsset([
                    'asset_tag'         => $assetId,
                    'asset_name'        => $assetName,
                    'model_id'          => $modelId,
                    'location_id'       => $locationId,
                    'checkout_due_on'   => $checkoutDue,
                    'next_cal_due_on'   => $nextCalDue,
                    'status'            => $importStatus,
                    'current_vendor_id' => $vendorId,
                    'current_user_id'   => $userId,
                ]);
                $this->counts['imported']++;
            }

            // Create / refresh the checkout transaction so checkout_issued_at
            // is populated for with_vendor / with_user assets. This lets the
            // existing subquery in the asset list work without any schema change.
            if ($importStatus === 'with_vendor' || $importStatus === 'with_user') {
                $this->upsertCheckoutTransaction(
                    $newAssetId, $importStatus, $vendorId, $userId,
                    $locationId, $checkoutDue, $issuedDate
                );
            } else {
                // If the asset is now active, remove any old import checkout txn
                db_exec(
                    "DELETE FROM asset_transactions
                      WHERE asset_id = ? AND notes = 'old-inventory-import'",
                    [$newAssetId]
                );
            }

            // Notes come pre-fetched from API
            $this->migrateNotes($row['notes'] ?? [], $newAssetId, $dueBack);

        } catch (Throwable $e) {
            $this->counts['failed']++;
            $this->log(
                "Failed asset_id={$assetId}: " . $e->getMessage(),
                'error'
            );
            throw $e;
        }
    }

    // ----------------------------------------------------------------
    // Model resolution / creation
    // ----------------------------------------------------------------

    /**
     * Return existing asset_models.id for the given model code, or create
     * a new model record if one doesn't exist yet.
     */
    private function resolveModel(array $row): int
    {
        $code     = trim((string) ($row['asset_model_code'] ?? ''));
        $name     = trim((string) ($row['model_name']       ?? ''));
        $category = trim((string) ($row['category_name']    ?? ''));

        // Use model name as fallback code when code is blank
        if ($code === '') {
            $code = $name !== '' ? $name : 'UNKNOWN';
        }
        if ($name === '') {
            $name = $code;
        }

        // Cache key is the original (pre-truncation) code so re-lookups within
        // the same batch always hit the cache even if the stored code differs.
        $cacheKey = $code;

        if (isset($this->modelCache[$cacheKey])) {
            return $this->modelCache[$cacheKey];
        }

        // Check DB by exact truncated code first
        $existing = db_one(
            'SELECT id FROM asset_models WHERE code = ? LIMIT 1',
            [substr($code, 0, 40)]
        );
        if ($existing) {
            return $this->modelCache[$cacheKey] = (int) $existing['id'];
        }

        // Also check by name — handles the case where a previous batch already
        // inserted this model under a suffixed code.
        $existing = db_one(
            'SELECT id FROM asset_models WHERE name = ? LIMIT 1',
            [$name]
        );
        if ($existing) {
            return $this->modelCache[$cacheKey] = (int) $existing['id'];
        }

        // Generate a unique code (max 40 chars). Long names are truncated; if
        // the truncated value already exists, append -1, -2 … until unique.
        $baseCode = substr($code, 0, 40);
        $dbCode   = $baseCode;
        $suffix   = 1;
        while (db_one('SELECT id FROM asset_models WHERE code = ? LIMIT 1', [$dbCode])) {
            $tag    = '-' . $suffix++;
            $dbCode = substr($baseCode, 0, 40 - strlen($tag)) . $tag;
        }

        db_exec(
            'INSERT INTO asset_models (code, name, category, is_active)
             VALUES (?, ?, ?, 1)',
            [$dbCode, $name, $category ?: null]
        );

        $id = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
        $this->log("Created new model: code={$dbCode}, name={$name}");

        return $this->modelCache[$cacheKey] = $id;
    }

    // ----------------------------------------------------------------
    // Location resolution
    // ----------------------------------------------------------------

    /**
     * Match an old-inventory location name against MagDyn's known internal
     * locations (by name or code, case-insensitive).
     *
     * Never creates a new location record — if nothing matches the import
     * defaults to Magdyn so vendor names / unknown old-system locations
     * cannot pollute the locations table.
     */
    private function resolveLocation(string $oldName): int
    {
        $name = trim($oldName);

        if (array_key_exists($name, $this->locationCache)) {
            return $this->locationCache[$name];
        }

        // Lazy-load the Magdyn fallback ID once (0 means not yet loaded)
        if ($this->defaultLocationId === 0) {
            $row = db_one("SELECT id FROM locations WHERE code = 'Magdyn' LIMIT 1");
            $this->defaultLocationId = $row ? (int) $row['id'] : (int) db_val('SELECT id FROM locations ORDER BY id LIMIT 1', [], 1);
        }

        if ($name === '') {
            return $this->locationCache[$name] = $this->defaultLocationId;
        }

        // Match by name first, then by code (both case-insensitive)
        $row = db_one(
            'SELECT id FROM locations
              WHERE is_active = 1
                AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?))
              LIMIT 1',
            [$name, $name]
        );

        if ($row) {
            return $this->locationCache[$name] = (int) $row['id'];
        }

        // No match — fall back to Magdyn; do NOT create a new location record
        $this->log("Location '{$name}' not found in MagDyn — defaulting to Magdyn.", 'warn');
        return $this->locationCache[$name] = $this->defaultLocationId;
    }

    /**
     * Find or create a vendor in MagDyn by company name.
     * Matched case-insensitively by name; created with a derived code if new.
     */
    private function resolveVendor(string $companyName): int
    {
        $name = trim($companyName);
        if (isset($this->vendorCache[$name])) {
            return $this->vendorCache[$name];
        }

        $row = db_one(
            'SELECT id FROM vendors WHERE LOWER(name) = LOWER(?) LIMIT 1',
            [$name]
        );
        if ($row) {
            return $this->vendorCache[$name] = (int) $row['id'];
        }

        // Generate a unique code (max 40 chars)
        $baseCode = substr(preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $name)), 0, 40);
        if ($baseCode === '') $baseCode = 'VND';
        $code   = $baseCode;
        $suffix = 1;
        while (db_one('SELECT id FROM vendors WHERE code = ? LIMIT 1', [$code])) {
            $tag  = '-' . $suffix++;
            $code = substr($baseCode, 0, 40 - strlen($tag)) . $tag;
        }

        db_exec(
            'INSERT INTO vendors (code, name, is_active) VALUES (?, ?, 1)',
            [$code, $name]
        );
        $id = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
        $this->log("Created new vendor: '{$name}' (code={$code})");

        return $this->vendorCache[$name] = $id;
    }

    /**
     * Match an old-inventory username to a MagDyn user (by username, case-insensitive).
     * Returns the user ID or null if no match found (no user is created).
     */
    private function resolveUser(string $username): ?int
    {
        if (isset($this->userCache[$username])) {
            return $this->userCache[$username];
        }

        $row = db_one(
            'SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND is_active = 1 LIMIT 1',
            [$username]
        );

        if ($row) {
            return $this->userCache[$username] = (int) $row['id'];
        }

        $this->log("User '{$username}' not found in MagDyn — current_user_id will be NULL.", 'warn');
        return $this->userCache[$username] = null;
    }

    // ----------------------------------------------------------------
    // Date parsing
    // ----------------------------------------------------------------

    /**
     * Convert old-system date strings to YYYY-MM-DD.
     *
     * Old system stores dates as "dd-mm-yyyy" (e.g. "15-03-2022").
     * Returns null on empty, placeholder (01-01-2000), or parse failure.
     */
    private function parseOldDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '01-01-2000') {
            return null;
        }

        // Try dd-mm-yyyy
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
            $date = "{$m[3]}-{$m[2]}-{$m[1]}";
            return $this->isValidDate($date) ? $date : null;
        }

        // Try yyyy-mm-dd passthrough
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            $date = substr($raw, 0, 10);
            return $this->isValidDate($date) ? $date : null;
        }

        return null;
    }

    private function isValidDate(string $date): bool
    {
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return false;
        }
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    // ----------------------------------------------------------------
    // New DB — asset read / write
    // ----------------------------------------------------------------

    private function findExistingAsset(string $assetTag): ?array
    {
        return db_one(
            'SELECT id, asset_tag FROM assets WHERE asset_tag = ? LIMIT 1',
            [$assetTag]
        ) ?: null;
    }

    private function insertAsset(array $d): int
    {
        db_exec(
            'INSERT INTO assets
                (asset_tag, asset_name, model_id, location_id, checkout_due_on, next_cal_due_on,
                 status, current_vendor_id, current_user_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['asset_tag'],
                $d['asset_name'],
                $d['model_id'],
                $d['location_id'],
                $d['checkout_due_on'],
                $d['next_cal_due_on'],
                $d['status'],
                $d['current_vendor_id'] ?? null,
                $d['current_user_id']   ?? null,
                $this->actorId,
            ]
        );

        return (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
    }

    private function updateAsset(int $id, array $d): void
    {
        db_exec(
            'UPDATE assets
             SET model_id          = ?,
                 location_id       = ?,
                 checkout_due_on   = ?,
                 next_cal_due_on   = ?,
                 asset_name        = ?,
                 status            = ?,
                 current_vendor_id = ?,
                 current_user_id   = ?
             WHERE id = ?',
            [
                $d['model_id'],
                $d['location_id'],
                $d['checkout_due_on'],
                $d['next_cal_due_on'],
                $d['asset_name'],
                $d['status'],
                $d['current_vendor_id'] ?? null,
                $d['current_user_id']   ?? null,
                $id,
            ]
        );
    }

    // ----------------------------------------------------------------
    // Checkout transaction (issued date)
    // ----------------------------------------------------------------

    /**
     * Create or refresh a single import-marker checkout transaction for the
     * asset so that the asset list's checkout_issued_at subquery returns the
     * real issued date from the old inventory.
     *
     * We tag these rows with notes='old-inventory-import' so re-imports can
     * replace them cleanly without touching real MagDyn transactions.
     */
    private function upsertCheckoutTransaction(
        int     $assetId,
        string  $status,
        ?int    $vendorId,
        ?int    $userId,
        int     $locationId,
        ?string $dueDate,
        ?string $issuedDate
    ): void {
        $txnType = ($status === 'with_vendor') ? 'send_vendor' : 'send_user';

        // Use the issued_date from old inventory; fall back to today if absent
        $at = $issuedDate ? $issuedDate . ' 00:00:00' : date('Y-m-d 00:00:00');

        // Remove previous import-marker transaction for this asset (if any)
        db_exec(
            "DELETE FROM asset_transactions
              WHERE asset_id = ? AND notes = 'old-inventory-import'",
            [$assetId]
        );

        db_exec(
            "INSERT INTO asset_transactions
                (asset_id, txn_type, from_location_id,
                 to_vendor_id, to_user_id,
                 due_date, actor_id, at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'old-inventory-import')",
            [
                $assetId,
                $txnType,
                $locationId,
                $vendorId,
                $userId,
                $dueDate,
                $this->actorId,
                $at,
            ]
        );
    }

    // ----------------------------------------------------------------
    // Notes migration
    // ----------------------------------------------------------------

    /**
     * Import notes pre-fetched from the API into the new notes table.
     * Physical files are NOT copied; filenames are appended to the note body.
     * $dueBack (cfv_22) is added as an informational note if set.
     *
     * @param array[]   $oldNotes  Notes array from the API response
     * @param int       $newAssetId
     * @param string|null $dueBack  Parsed cfv_22 date or null
     */
    private function migrateNotes(array $oldNotes, int $newAssetId, ?string $dueBack): void
    {
        if ($dueBack !== null) {
            $this->createNote(
                $newAssetId,
                '<p><strong>[Migration]</strong> Due Back (cfv_22): ' . htmlspecialchars($dueBack) . '</p>'
            );
        }

        foreach ($oldNotes as $on) {
            $html = $this->buildNoteHtml($on);
            if ($html !== '') {
                $this->createNote($newAssetId, $html);
            }
        }
    }

    /**
     * Build HTML body for a migrated note.
     * Attachments are already embedded in the API response — no extra DB call needed.
     */
    private function buildNoteHtml(array $on): string
    {
        $text = trim((string) ($on['notes'] ?? ''));
        $html = '';

        if ($text !== '') {
            $html .= '<p>' . nl2br(htmlspecialchars($text)) . '</p>';
        }

        $priority = trim((string) ($on['priority'] ?? ''));
        if ($priority !== '' && $priority !== 'General') {
            $html .= '<p><em>Priority: ' . htmlspecialchars($priority) . '</em></p>';
        }

        // Attachment filenames from API (physical files not copied)
        $attachments = $on['attachments'] ?? [];
        if (!empty($attachments)) {
            $html .= '<p><strong>[Migration] Attached files (not physically transferred):</strong><br>';
            foreach ($attachments as $att) {
                $html .= '• ' . htmlspecialchars((string) ($att['filename'] ?? '')) . '<br>';
            }
            $html .= '</p>';
        }

        // Inline files JSON column
        $filesJson = trim((string) ($on['files'] ?? ''));
        if ($filesJson !== '' && $filesJson !== '[]' && $filesJson !== 'null') {
            $filePaths = @json_decode($filesJson, true);
            if (is_array($filePaths) && !empty($filePaths)) {
                $html .= '<p><strong>[Migration] Legacy file paths:</strong><br>';
                foreach ($filePaths as $fp) {
                    $html .= '• ' . htmlspecialchars(basename((string) $fp)) . '<br>';
                }
                $html .= '</p>';
            }
        }

        return $html;
    }

    /**
     * Insert a single note into the new notes table.
     */
    private function createNote(int $assetId, string $bodyHtml): void
    {
        if (trim(strip_tags($bodyHtml)) === '') {
            return;
        }

        db_exec(
            "INSERT INTO notes
                (entity_type, entity_id, note_type_id, body_html, author_id)
             VALUES ('asset', ?, NULL, ?, ?)",
            [$assetId, $bodyHtml, $this->actorId]
        );
    }

    // ----------------------------------------------------------------
    // Transaction history import
    // ----------------------------------------------------------------

    /**
     * Import the full asset transaction history from the old inventory.
     *
     * Type mapping (old transaction_type_id → new txn_type):
     *   1  Move              → move
     *   2  Check In + vendor → receive_vendor
     *   2  Check In + user   → receive_user
     *   2  Check In (plain)  → move
     *   3  Check Out + vendor→ send_vendor
     *   3  Check Out + user  → send_user
     *   10 Archive           → archive
     *   11 Unarchive         → restore
     *
     * Each imported row is tagged with "[old-txn:<id>]" in the notes
     * column so re-imports can wipe and re-insert cleanly without
     * touching transactions created natively in MagDyn.
     *
     * The per-asset "old-inventory-import" placeholder checkout
     * transaction (written by upsertCheckoutTransaction) is also
     * removed here — the real checkout row from history replaces it.
     */
    private function importTransactions(): void
    {
        // Remove placeholder checkout transactions created during
        // Phase 1 (upsertCheckoutTransaction) and any rows left over
        // from a previous full transaction import.
        db_exec(
            "DELETE FROM asset_transactions
              WHERE notes = 'old-inventory-import'
                 OR notes LIKE '[old-txn:%'"
        );

        $countData = old_inventory_api('txn_count');
        $this->counts['txn_total'] = (int) ($countData['count'] ?? 0);
        $this->log("Phase 2 — transactions: {$this->counts['txn_total']} found in source.");

        $offset = 0;

        while (true) {
            $data  = old_inventory_api('transactions', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['transactions'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $row) {
                try {
                    $outcome = $this->importOneTransaction($row);
                    if ($outcome === 'skipped') {
                        $this->counts['txn_skipped']++;
                    } else {
                        $this->counts['txn_imported']++;
                    }
                } catch (\Throwable $e) {
                    $this->counts['txn_failed']++;
                    $this->log(
                        "Txn {$row['transaction_id']} failed: " . $e->getMessage(),
                        'error'
                    );
                }
            }

            $offset += self::BATCH_SIZE;

            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }

        $this->log(
            "Transactions done — " .
            "Imported: {$this->counts['txn_imported']}, " .
            "Failed: {$this->counts['txn_failed']}, " .
            "Skipped: {$this->counts['txn_skipped']}."
        );
    }

    /**
     * Import a single transaction row from the old inventory into
     * asset_transactions.  Returns 'imported' or 'skipped'.
     */
    private function importOneTransaction(array $row): string
    {
        $oldTxnId   = (int) $row['transaction_id'];
        $oldAssetId = (string) $row['asset_id'];
        $typeId     = (int) $row['transaction_type_id'];
        $company    = trim((string) ($row['company_name']     ?? ''));
        $user       = trim((string) ($row['checked_out_user'] ?? ''));

        // Resolve new asset ID (old asset_id stored as asset_tag)
        $asset = db_one(
            'SELECT id FROM assets WHERE asset_tag = ? LIMIT 1',
            [$oldAssetId]
        );
        if (!$asset) {
            $this->log(
                "Skipped txn {$oldTxnId}: asset_id={$oldAssetId} not in MagDyn.",
                'warn'
            );
            return 'skipped';
        }
        $newAssetId = (int) $asset['id'];

        // Map transaction type
        switch ($typeId) {
            case 1:   // Move
                $txnType = 'move';
                break;
            case 2:   // Check In
                if ($company !== '')    $txnType = 'receive_vendor';
                elseif ($user !== '')   $txnType = 'receive_user';
                else                    $txnType = 'move';
                break;
            case 3:   // Check Out
                if ($company !== '')    $txnType = 'send_vendor';
                elseif ($user !== '')   $txnType = 'send_user';
                else                    $txnType = 'send_vendor'; // fallback
                break;
            case 10:  // Archive
                $txnType = 'archive';
                break;
            case 11:  // Unarchive
                $txnType = 'restore';
                break;
            default:
                $this->log("Skipped txn {$oldTxnId}: unknown type_id={$typeId}.", 'warn');
                return 'skipped';
        }

        // Resolve locations — 'Checked Out' is a virtual old-system
        // location; it has no MagDyn equivalent so we leave it NULL.
        $fromLocId = $this->resolveLocationOrNull((string) ($row['source_location'] ?? ''));
        $toLocId   = $this->resolveLocationOrNull((string) ($row['dest_location']   ?? ''));

        // Resolve vendor / user for the four checkout/receive types
        $toVendorId   = null;
        $toUserId     = null;
        $fromVendorId = null;
        $fromUserId   = null;

        if ($txnType === 'send_vendor'    && $company !== '') $toVendorId   = $this->resolveVendor($company);
        if ($txnType === 'send_user'      && $user   !== '') $toUserId     = $this->resolveUser($user);
        if ($txnType === 'receive_vendor' && $company !== '') $fromVendorId = $this->resolveVendor($company);
        if ($txnType === 'receive_user'   && $user   !== '') $fromUserId   = $this->resolveUser($user);

        // Actor: match old username to MagDyn user; fall back to import actor
        $actorId = $this->resolveActorUser((string) ($row['created_by_username'] ?? ''));

        // Timestamp
        $at = !empty($row['at']) ? (string) $row['at'] : date('Y-m-d H:i:s');

        // Notes — embed old transaction_id as dedup marker; preserve original text
        $origNote = trim((string) ($row['notes'] ?? ''));
        $note     = "[old-txn:{$oldTxnId}]" . ($origNote !== '' ? ' ' . $origNote : '');
        $note     = substr($note, 0, 500);

        // Due date (only meaningful for checkout rows)
        $dueDate = !empty($row['due_date']) ? (string) $row['due_date'] : null;

        db_exec(
            "INSERT INTO asset_transactions
                (asset_id, txn_type,
                 from_location_id, to_location_id,
                 from_vendor_id,   to_vendor_id,
                 from_user_id,     to_user_id,
                 due_date, actor_id, at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $newAssetId, $txnType,
                $fromLocId,    $toLocId,
                $fromVendorId, $toVendorId,
                $fromUserId,   $toUserId,
                $dueDate, $actorId, $at, $note,
            ]
        );

        return 'imported';
    }

    /**
     * Resolve a location name for use in transaction history.
     * Returns null (not the Magdyn fallback) for unmatched or virtual
     * names like "Checked Out" — those carry no physical meaning in MagDyn.
     */
    private function resolveLocationOrNull(string $oldName): ?int
    {
        $name = trim($oldName);

        // Virtual old-system location — no MagDyn equivalent
        if ($name === '' || strtolower($name) === 'checked out') {
            return null;
        }

        $row = db_one(
            'SELECT id FROM locations
              WHERE is_active = 1
                AND (LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?))
              LIMIT 1',
            [$name, $name]
        );

        return $row ? (int) $row['id'] : null;
    }

    /**
     * Resolve an old-system username to a MagDyn user ID.
     * Falls back to the import actor if no match (never returns null).
     */
    private function resolveActorUser(string $username): int
    {
        $uid = $this->resolveUser(trim($username));
        return $uid ?? $this->actorId;
    }

    // ----------------------------------------------------------------
    // Internal logging
    // ----------------------------------------------------------------

    private function log(string $message, string $level = 'info'): void
    {
        $this->errors[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => date('H:i:s'),
        ];
    }
}
