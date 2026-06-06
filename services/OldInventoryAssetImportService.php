<?php
/**
 * MagDyn — Old Inventory Asset Import Service (API version)
 *
 * Fetches asset records from the legacy inventory_live system via the
 * HTTP API (api_export_assets.php deployed on the old server) and
 * imports them into the new MagDyn assets system.
 *
 * Field mapping (old → new):
 *   asset.asset_code             → assets.asset_tag       (upsert key)
 *   asset_model.asset_model_code → asset_models.code      (created if missing)
 *   asset_model.short_description→ asset_models.name
 *   category.short_description   → asset_models.category
 *   location.short_description   → locations.name         (matched by name)
 *   checkout_due  (API field)    → assets.checkout_due_on (most recent)
 *   cfv_22 / due_back (API)      → informational note
 *   cfv_23 / next_cal_due (API)  → assets.next_cal_due_on
 *   inv_notes class='A' (API)    → notes (entity_type='asset')
 *   notes_attachments filenames  → appended to note body (not physically copied)
 *
 * Duplicate handling:
 *   asset_code already in assets.asset_tag → UPDATE. Otherwise → INSERT.
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

    /** @var array  Accumulated log entries */
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
     * Run the full import.
     *
     * @return array{total:int,imported:int,updated:int,failed:int,skipped:int,errors:array}
     */
    public function run(): array
    {
        $countData = old_inventory_api('count');
        $this->counts['total'] = (int) ($countData['count'] ?? 0);
        $this->log("Starting import — {$this->counts['total']} active assets found in source.");

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
            "Import complete. " .
            "Imported: {$this->counts['imported']}, " .
            "Updated: {$this->counts['updated']}, " .
            "Failed: {$this->counts['failed']}, " .
            "Skipped: {$this->counts['skipped']}."
        );

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

        if ($assetCode === '') {
            $this->counts['skipped']++;
            $this->log("Skipped record old_id={$row['asset_id']}: empty asset_code.", 'warn');
            return;
        }

        try {
            $modelId     = $this->resolveModel($row);
            $locationId  = $this->resolveLocation((string) ($row['location_name'] ?? ''));
            $nextCalDue  = $this->parseOldDate((string) ($row['next_cal_due'] ?? ''));
            $dueBack     = $this->parseOldDate((string) ($row['due_back']     ?? ''));
            $checkoutDue = $row['checkout_due'] ?? null;   // already YYYY-MM-DD from API

            $existing = $this->findExistingAsset($assetCode);

            if ($existing) {
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

            // Notes come pre-fetched from API
            $this->migrateNotes($row['notes'] ?? [], $newAssetId, $dueBack);

        } catch (Throwable $e) {
            $this->counts['failed']++;
            $this->log(
                "Failed asset_code={$assetCode} (old_id={$row['asset_id']}): " . $e->getMessage(),
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
     * If no match is found, a new location record is created automatically
     * so vendor names and other locations from the old system are preserved.
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

        // Try to find an existing active location (case-insensitive)
        $row = db_one(
            'SELECT id FROM locations WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1',
            [$name]
        );

        if ($row) {
            return $this->locationCache[$name] = (int) $row['id'];
        }

        // Not found — create it so vendor names and other locations are preserved
        db_exec(
            'INSERT INTO locations (name, is_active) VALUES (?, 1)',
            [$name]
        );
        $id = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
        $this->locationCache[$name] = $id;
        $this->log("Created new location: '{$name}'");

        return $id;
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
