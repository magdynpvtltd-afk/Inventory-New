<?php
/**
 * MagDyn — Old Inventory Asset Import Service
 *
 * Reads asset records from the legacy inventory_live database and
 * imports them into the new MagDyn assets system.
 *
 * Field mapping (old → new):
 *   asset.asset_code                    → assets.asset_tag          (migration key)
 *   asset_model.asset_model_code        → asset_models.code
 *   asset_model.short_description       → asset_models.name
 *   category.short_description          → asset_models.category
 *   location.short_description          → locations.name  (matched by name)
 *   asset_transaction_checkout.due_date → assets.checkout_due_on    (most recent)
 *   asset_custom_field_helper.cfv_22    → stored as note (no direct column)
 *   asset_custom_field_helper.cfv_23    → assets.next_cal_due_on
 *   (status hardcoded)                  → assets.status = 'active'
 *   inv_notes where class='A'           → notes (entity_type='asset')
 *   notes_attachments                   → note body (files listed; not physically copied)
 *
 * Duplicate handling:
 *   asset.asset_code already in assets.asset_tag → UPDATE existing record.
 *   Otherwise → INSERT new record.
 *
 * Usage:
 *   $svc    = new OldInventoryAssetImportService($actorUserId);
 *   $result = $svc->run();
 *   // $result = ['total'=>N,'imported'=>N,'updated'=>N,'failed'=>N,'skipped'=>N,'errors'=>[...]]
 */

require_once __DIR__ . '/../includes/old_inventory_db.php';

class OldInventoryAssetImportService
{
    /** Records per DB transaction batch */
    private const BATCH_SIZE = 100;

    /** @var PDO  New (MagDyn) database */
    private PDO $new;

    /** @var PDO  Old (inventory_live) database */
    private PDO $old;

    /** @var int  User ID credited as creator/editor for imported records */
    private int $actorId;

    /** @var array<string,int>  location name → new locations.id cache */
    private array $locationCache = [];

    /** @var array<string,int>  model code → new asset_models.id cache */
    private array $modelCache = [];

    /** @var array  Accumulated import log entries */
    private array $errors = [];

    /** @var array{total:int,imported:int,updated:int,failed:int,skipped:int} */
    private array $counts = [
        'total'    => 0,
        'imported' => 0,
        'updated'  => 0,
        'failed'   => 0,
        'skipped'  => 0,
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Run the full import.  Returns a summary array.
     *
     * @return array{total:int,imported:int,updated:int,failed:int,skipped:int,errors:array}
     */
    public function run(): array
    {
        // Open both connections.  Propagate connection errors to caller.
        $this->new = db();
        $this->old = old_inventory_db();

        $this->counts['total'] = $this->countSourceAssets();
        $this->log("Starting import — {$this->counts['total']} active assets found in source.");

        $offset = 0;

        while (true) {
            $batch = $this->fetchBatch($offset, self::BATCH_SIZE);
            if (empty($batch)) {
                break;
            }

            $this->processBatch($batch);
            $offset += self::BATCH_SIZE;
        }

        $this->log(
            "Import complete. " .
            "Imported: {$this->counts['imported']}, " .
            "Updated: {$this->counts['updated']}, " .
            "Failed: {$this->counts['failed']}, " .
            "Skipped: {$this->counts['skipped']}."
        );

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    // ----------------------------------------------------------------
    // Batch fetching from old DB
    // ----------------------------------------------------------------

    /** Count total active (non-archived) assets in old system. */
    private function countSourceAssets(): int
    {
        $stmt = $this->old->query(
            "SELECT COUNT(*) FROM asset
              WHERE archived_flag IS NULL OR archived_flag = 0"
        );
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch one batch of assets from the old DB with all needed joins.
     *
     * @return array[]
     */
    private function fetchBatch(int $offset, int $limit): array
    {
        $sql = "
            SELECT
                a.asset_id          AS old_asset_id,
                a.asset_code,
                am.asset_model_code,
                am.short_description AS model_name,
                cat.short_description AS category_name,
                loc.short_description AS location_name,
                acfh.cfv_22          AS due_back,
                acfh.cfv_23          AS next_cal_due
            FROM asset a
            LEFT JOIN asset_model am
                   ON am.asset_model_id = a.asset_model_id
            LEFT JOIN category cat
                   ON cat.category_id  = am.category_id
            LEFT JOIN location loc
                   ON loc.location_id  = a.location_id
            LEFT JOIN asset_custom_field_helper acfh
                   ON acfh.asset_id    = a.asset_id
            WHERE (a.archived_flag IS NULL OR a.archived_flag = 0)
            ORDER BY a.asset_id
            LIMIT :lim OFFSET :off
        ";

        $stmt = $this->old->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------------
    // Batch processing — wrapped in a single transaction per batch
    // ----------------------------------------------------------------

    private function processBatch(array $batch): void
    {
        $this->new->beginTransaction();

        try {
            foreach ($batch as $row) {
                $this->processOneAsset($row);
            }
            $this->new->commit();
        } catch (Throwable $e) {
            $this->new->rollBack();
            // Log batch-level failure and continue with next batch
            $this->log("Batch transaction rolled back: " . $e->getMessage(), 'error');
            $this->counts['failed'] += count($batch);
        }
    }

    // ----------------------------------------------------------------
    // Single-asset processing
    // ----------------------------------------------------------------

    private function processOneAsset(array $row): void
    {
        $assetCode = trim((string) ($row['asset_code'] ?? ''));

        // Skip records with no identifier
        if ($assetCode === '') {
            $this->counts['skipped']++;
            $this->log("Skipped record old_id={$row['old_asset_id']}: empty asset_code.", 'warn');
            return;
        }

        try {
            // Resolve FK dependencies
            $modelId    = $this->resolveModel($row);
            $locationId = $this->resolveLocation($row['location_name'] ?? '');

            // Parse dates
            $checkoutDue = $this->fetchLatestCheckoutDue((int) $row['old_asset_id']);
            $nextCalDue  = $this->parseOldDate($row['next_cal_due'] ?? '');
            $dueBack     = $this->parseOldDate($row['due_back'] ?? '');  // cfv_22

            // Check for existing record (duplicate detection by asset_tag)
            $existing = $this->findExistingAsset($assetCode);

            if ($existing) {
                // UPDATE existing record
                $this->updateAsset($existing['id'], [
                    'model_id'        => $modelId,
                    'location_id'     => $locationId,
                    'checkout_due_on' => $checkoutDue,
                    'next_cal_due_on' => $nextCalDue,
                    'status'          => 'active',
                ]);
                $newAssetId = $existing['id'];
                $this->counts['updated']++;
            } else {
                // INSERT new record
                $newAssetId = $this->insertAsset([
                    'asset_tag'       => $assetCode,
                    'model_id'        => $modelId,
                    'location_id'     => $locationId,
                    'checkout_due_on' => $checkoutDue,
                    'next_cal_due_on' => $nextCalDue,
                    'status'          => 'active',
                ]);
                $this->counts['imported']++;
            }

            // Migrate notes and file references from inv_notes (class='A')
            $this->migrateNotes((int) $row['old_asset_id'], $newAssetId, $dueBack);

        } catch (Throwable $e) {
            $this->counts['failed']++;
            $this->log(
                "Failed asset_code={$assetCode} (old_id={$row['old_asset_id']}): " . $e->getMessage(),
                'error'
            );
            // Re-throw so the batch transaction catches it
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

        // Cache look-up
        if (isset($this->modelCache[$code])) {
            return $this->modelCache[$code];
        }

        // Check new DB
        $existing = db_one(
            'SELECT id FROM asset_models WHERE code = ? LIMIT 1',
            [$code]
        );
        if ($existing) {
            return $this->modelCache[$code] = (int) $existing['id'];
        }

        // Create new model
        db_exec(
            'INSERT INTO asset_models (code, name, category, is_active)
             VALUES (?, ?, ?, 1)',
            [$code, $name, $category ?: null]
        );

        $id = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
        $this->log("Created new model: code={$code}, name={$name}");

        return $this->modelCache[$code] = $id;
    }

    // ----------------------------------------------------------------
    // Location resolution
    // ----------------------------------------------------------------

    /**
     * Find a matching location in the new system by name (case-insensitive).
     * Returns null when no match — asset will be imported with null location_id.
     */
    private function resolveLocation(string $oldName): ?int
    {
        $name = trim($oldName);
        if ($name === '') {
            return null;
        }

        if (array_key_exists($name, $this->locationCache)) {
            return $this->locationCache[$name];
        }

        $row = db_one(
            'SELECT id FROM locations WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1',
            [$name]
        );

        $id = $row ? (int) $row['id'] : null;
        $this->locationCache[$name] = $id;

        if ($id === null) {
            $this->log("Location not matched: '{$name}' — asset imported with no location.", 'warn');
        }

        return $id;
    }

    // ----------------------------------------------------------------
    // Checkout due date
    // ----------------------------------------------------------------

    /**
     * Fetch the most recent checkout due_date for the given old asset_id.
     * Joins asset_transaction → asset_transaction_checkout.
     */
    private function fetchLatestCheckoutDue(int $oldAssetId): ?string
    {
        $sql = "
            SELECT atc.due_date
            FROM asset_transaction_checkout atc
            JOIN asset_transaction atr
                ON atr.asset_transaction_id = atc.asset_transaction_id
            WHERE atr.asset_id = :aid
              AND atc.due_date IS NOT NULL
            ORDER BY atc.creation_date DESC
            LIMIT 1
        ";
        $stmt = $this->old->prepare($sql);
        $stmt->execute([':aid' => $oldAssetId]);
        $val = $stmt->fetchColumn();

        if (!$val) {
            return null;
        }

        // due_date is a datetime in old DB — take date part only
        return substr((string) $val, 0, 10);
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
                (asset_tag, model_id, location_id, checkout_due_on, next_cal_due_on,
                 status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $d['asset_tag'],
                $d['model_id'],
                $d['location_id'],
                $d['checkout_due_on'],
                $d['next_cal_due_on'],
                $d['status'],
                $this->actorId,
            ]
        );

        return (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
    }

    private function updateAsset(int $id, array $d): void
    {
        db_exec(
            'UPDATE assets
             SET model_id        = ?,
                 location_id     = ?,
                 checkout_due_on = ?,
                 next_cal_due_on = ?,
                 status          = ?
             WHERE id = ?',
            [
                $d['model_id'],
                $d['location_id'],
                $d['checkout_due_on'],
                $d['next_cal_due_on'],
                $d['status'],
                $id,
            ]
        );
    }

    // ----------------------------------------------------------------
    // Notes migration
    // ----------------------------------------------------------------

    /**
     * Import inv_notes records (class='A') for one asset into the new
     * notes table.  Physical files are NOT copied (remote FS inaccessible);
     * instead their filenames are appended to the note body so no data is lost.
     *
     * $dueBack (cfv_22) is added as an informational note if set.
     */
    private function migrateNotes(int $oldAssetId, int $newAssetId, ?string $dueBack): void
    {
        // Write cfv_22 (Due Back) as a note if it has a meaningful value
        if ($dueBack !== null) {
            $this->createNote(
                $newAssetId,
                "<p><strong>[Migration]</strong> Due Back (cfv_22): " . htmlspecialchars($dueBack) . "</p>"
            );
        }

        // Fetch all asset notes from old system
        $sql = "
            SELECT n.noteid, n.notes, n.priority, n.created_date, n.files
            FROM inv_notes n
            WHERE n.class = 'A'
              AND n.id    = :aid
              AND n.redact = 0
            ORDER BY n.noteid ASC
        ";
        $stmt = $this->old->prepare($sql);
        $stmt->execute([':aid' => $oldAssetId]);
        $oldNotes = $stmt->fetchAll();

        foreach ($oldNotes as $on) {
            $noteHtml = $this->buildNoteHtml($on);
            if ($noteHtml === '') {
                continue;
            }
            $this->createNote($newAssetId, $noteHtml);
        }
    }

    /**
     * Build HTML body for a migrated note, appending any file references
     * from notes_attachments that cannot be physically transferred.
     */
    private function buildNoteHtml(array $on): string
    {
        $text = trim((string) ($on['notes'] ?? ''));

        // Start with the note text (convert newlines to <br>)
        $html = '';
        if ($text !== '') {
            $html .= '<p>' . nl2br(htmlspecialchars($text)) . '</p>';
        }

        // Append priority as a tag if set
        $priority = trim((string) ($on['priority'] ?? ''));
        if ($priority !== '' && $priority !== 'General') {
            $html .= '<p><em>Priority: ' . htmlspecialchars($priority) . '</em></p>';
        }

        // Append file attachment names from notes_attachments table
        $attachments = $this->fetchOldAttachments((int) $on['noteid']);
        if (!empty($attachments)) {
            $html .= '<p><strong>[Migration] Attached files (not physically transferred):</strong><br>';
            foreach ($attachments as $att) {
                $html .= '• ' . htmlspecialchars($att['filename']) . '<br>';
            }
            $html .= '</p>';
        }

        // Also check the inline `files` JSON column in inv_notes
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
     * Fetch file attachment records from old notes_attachments for one note.
     */
    private function fetchOldAttachments(int $noteId): array
    {
        $stmt = $this->old->prepare(
            'SELECT filename, type FROM notes_attachments WHERE noteid = ? AND redact = 0'
        );
        $stmt->execute([$noteId]);
        return $stmt->fetchAll();
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
