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
 *   asset.parent_asset_id        → assets.parent_asset_id (resolved via asset_tag, second pass)
 *   asset.asset_code             → assets.asset_name      (individual asset name)
 *   asset_model.asset_model_id   → asset_models.code         (stable numeric join key)
 *   asset_model.asset_model_code → asset_models.model_number (raw model number from old system)
 *   asset_model.short_description→ asset_models.name
 *   category.short_description   → asset_models.category
 *   manufacturer.short_description→asset_models.manufacturer
 *   location.short_description   → locations.name         (matched by name)
 *   checkout_due  (API field)    → assets.checkout_due_on (most recent)
 *   checked_out_flag (API field) → status: 0=active, 1+company=with_vendor, 1+no company=with_user
 *   archived_flag (API field)    → status=archived (overrides checked_out_flag; clears holder)
 *   checkout_due_on cleared to NULL when checked_out_flag=0 (asset returned, old tx history ignored)
 *   cfv_2  / asset_notes  (API)  → assets.notes
 *   cfv_3  / a_price      (API)  → assets.a_price
 *   cfv_22 / cal_done_on  (API)  → assets.cal_done_on
 *   cfv_23 / next_cal_due (API)  → assets.next_cal_due_on
 *   cfv_51 / cal_frequency(API)  → assets.cal_frequency_id (asset_cal_frequencies, find/create)
 *   cfv_54 / engraved     (API)  → assets.engraved_id      (asset_engraved_options, find/create)
 *   cfv_55 / calibration  (API)  → assets.calibration_id   (asset_calibration_options, find/create)
 *   cfv_56 / checked_ok   (API)  → assets.checked_ok_id    (asset_checked_ok_options, find/create)
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

    /** @var array<string,int>  "table|lower(label)" → lookup row id cache */
    private array $lookupCache = [];

    /** @var array<int,bool>  old asset_transaction_id values imported this run (dedup guard) */
    private array $importedAtxnIds = [];

    /** @var array<int,int>  new assets.id → old parent asset_id (0 = no parent); resolved post-pass */
    private array $pendingParents = [];

    /** @var int  Magdyn location ID — fallback when old-system name has no match */
    private int $defaultLocationId = 0;

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var callable|null  Progress reporter: fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'model_total'   => 0,
        'model_created' => 0,
        'total'         => 0,
        'imported'      => 0,
        'updated'       => 0,
        'failed'        => 0,
        'skipped'       => 0,
        'parent_linked' => 0,
        'txn_total'     => 0,
        'txn_imported'  => 0,
        'txn_failed'    => 0,
        'txn_skipped'   => 0,
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    /**
     * Register a progress reporter, invoked as fn(string $phase, int $done, int $total)
     * after each batch / phase so callers (e.g. the streaming import page) can show
     * a bar. Phase is one of: 'Models', 'Assets', 'Transactions'.
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
        // ── Phase 0: Models ──────────────────────────────────────────────────
        // Import ALL models first so every asset (even those whose model
        // would otherwise be missing) resolves correctly in Phase 1.
        try {
            $this->importModels();
        } catch (\Throwable $e) {
            $this->log('Model import aborted: ' . $e->getMessage(), 'error');
        }

        // ── Phase 1: Assets ──────────────────────────────────────────────────
        $countData = old_inventory_api('count');
        $this->counts['total'] = (int) ($countData['count'] ?? 0);
        $this->log("Phase 1 — assets: {$this->counts['total']} found in source.");

        $assetTotal = $this->counts['total'];
        $processed  = 0;
        $this->emitProgress('Assets', 0, $assetTotal);

        $offset = 0;

        while (true) {
            $data  = old_inventory_api('assets', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['assets'] ?? [];

            if (empty($batch)) {
                break;
            }

            $this->processBatch($batch);

            $processed += count($batch);
            $this->emitProgress('Assets', min($processed, $assetTotal), $assetTotal);

            $offset += self::BATCH_SIZE;

            if (count($batch) < self::BATCH_SIZE) {
                break;  // last page
            }
        }

        $this->emitProgress('Assets', $assetTotal, $assetTotal);

        $this->log(
            "Assets done — " .
            "Imported: {$this->counts['imported']}, " .
            "Updated: {$this->counts['updated']}, " .
            "Failed: {$this->counts['failed']}, " .
            "Skipped: {$this->counts['skipped']}."
        );

        // ── Phase 1b: Parent asset links ─────────────────────────────────────
        // Resolved only after every asset exists, since a child may be
        // processed before its parent within the paged batches.
        try {
            $this->resolveParents();
        } catch (\Throwable $e) {
            $this->log('Parent link resolution aborted: ' . $e->getMessage(), 'error');
        }

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
            $this->lookupCache   = [];
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
            $assetName      = trim((string) ($row['asset_code'] ?? '')) ?: null;

            // Custom fields (asset_custom_field_helper → custom_field):
            //   cfv_2  Notes        cfv_3  A_Price       cfv_22 Calibration Done On
            //   cfv_51 Cal Freq     cfv_54 Engraved      cfv_55 Calibration (Calib/AMC)
            //   cfv_56 Checked OK
            // Select-type fields arrive as option text and resolve to a row
            // in the matching asset_* lookup table (created on first sight).
            $calDoneOn   = $this->parseOldDate((string) ($row['cal_done_on'] ?? ''));
            $aPrice      = $this->parsePrice((string) ($row['a_price'] ?? ''));
            $assetNotes  = trim((string) ($row['asset_notes'] ?? '')) ?: null;
            $calFreqId   = $this->resolveLookup('asset_cal_frequencies',     $this->normalizeFrequency((string) ($row['cal_frequency'] ?? '')));
            $engravedId  = $this->resolveLookup('asset_engraved_options',    (string) ($row['engraved']      ?? ''));
            $calibId     = $this->resolveLookup('asset_calibration_options', (string) ($row['calibration']   ?? ''));
            $checkedId   = $this->resolveLookup('asset_checked_ok_options',  (string) ($row['checked_ok']    ?? ''));
            $companyName    = $row['company_name']   ?? null;
            $checkedOutFlag = (int) ($row['checked_out_flag'] ?? 0); // 1 = currently checked out
            $archivedFlag   = (int) ($row['archived_flag'] ?? 0);    // 1 = archived in old system
            // Old asset.parent_asset_id (points to another old asset_id). Stored
            // now and resolved to a new assets.id in resolveParents() once all
            // assets exist — a child can precede its parent across paged batches.
            $oldParentId    = isset($row['parent_asset_id']) && $row['parent_asset_id'] !== null
                ? (int) $row['parent_asset_id'] : 0;

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

            // archived_flag wins over the checkout state: an archived asset in
            // the old system becomes an inactive (archived) asset in MagDyn,
            // with no current holder or checkout placeholder transaction.
            if ($archivedFlag === 1) {
                $importStatus = 'archived';
                $vendorId     = null;
                $userId       = null;
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
                    'cal_done_on'       => $calDoneOn,
                    'asset_name'        => $assetName,
                    'notes'             => $assetNotes,
                    'a_price'           => $aPrice,
                    'cal_frequency_id'  => $calFreqId,
                    'engraved_id'       => $engravedId,
                    'calibration_id'    => $calibId,
                    'checked_ok_id'     => $checkedId,
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
                    'cal_done_on'       => $calDoneOn,
                    'notes'             => $assetNotes,
                    'a_price'           => $aPrice,
                    'cal_frequency_id'  => $calFreqId,
                    'engraved_id'       => $engravedId,
                    'calibration_id'    => $calibId,
                    'checked_ok_id'     => $checkedId,
                    'status'            => $importStatus,
                    'current_vendor_id' => $vendorId,
                    'current_user_id'   => $userId,
                ]);
                $this->counts['imported']++;
            }

            // Remember the old parent link for the post-pass resolver.
            $this->pendingParents[$newAssetId] = $oldParentId;

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
        $oldModelId   = isset($row['asset_model_id']) ? (int) $row['asset_model_id'] : 0;
        $name         = trim((string) ($row['model_name']        ?? ''));
        $category     = trim((string) ($row['category_name']     ?? ''));
        $manufacturer = trim((string) ($row['manufacturer_name'] ?? ''));
        // The old asset_model_code text is preserved as model_number; the new
        // Code is the old asset_model_id (a stable, unique numeric join key).
        $modelNumber  = trim((string) ($row['asset_model_code']  ?? ''));

        // Code = old asset_model_id. If it is somehow missing, fall back to the
        // old code text, then the name, so the model still imports uniquely.
        if ($oldModelId > 0) {
            $code = (string) $oldModelId;
        } elseif ($modelNumber !== '') {
            $code = $modelNumber;
        } elseif ($name !== '') {
            $code = $name;
        } else {
            $code = 'UNKNOWN';
        }
        if ($name === '') {
            $name = $modelNumber !== '' ? $modelNumber : $code;
        }

        $cacheKey = $code;

        if (isset($this->modelCache[$cacheKey])) {
            return $this->modelCache[$cacheKey];
        }

        $dbCode = substr($code, 0, 40);

        // Match by Code (old asset_model_id). Refresh descriptive fields that
        // may have been absent when the row was first created.
        $existing = db_one(
            'SELECT id FROM asset_models WHERE code = ? LIMIT 1',
            [$dbCode]
        );
        if ($existing) {
            db_exec(
                'UPDATE asset_models
                 SET name         = COALESCE(NULLIF(?, \'\'), name),
                     category     = COALESCE(NULLIF(?, \'\'), category),
                     manufacturer = COALESCE(NULLIF(?, \'\'), manufacturer),
                     model_number = COALESCE(NULLIF(?, \'\'), model_number)
                 WHERE id = ?',
                [
                    $name,
                    $category     ?: null,
                    $manufacturer ?: null,
                    $modelNumber  ?: null,
                    (int) $existing['id'],
                ]
            );
            return $this->modelCache[$cacheKey] = (int) $existing['id'];
        }

        db_exec(
            'INSERT INTO asset_models (code, name, category, manufacturer, model_number, is_active)
             VALUES (?, ?, ?, ?, ?, 1)',
            [
                $dbCode,
                $name,
                $category     ?: null,
                $manufacturer ?: null,
                $modelNumber  ?: null,
            ]
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
    // Lookup-table resolution (cal frequency / engraved / calibration / checked-ok)
    // ----------------------------------------------------------------

    /**
     * Find or create a row in one of the asset dropdown lookup tables
     * (asset_cal_frequencies, asset_engraved_options,
     *  asset_calibration_options, asset_checked_ok_options) by its label.
     *
     * Old-system select fields store their option text directly in
     * asset_custom_field_helper, so we match case-insensitively on label
     * and create the option (is_active=1) the first time it's seen.
     * Returns the row id, or null when the label is blank.
     */
    private function resolveLookup(string $table, string $label): ?int
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $cacheKey = $table . '|' . strtolower($label);
        if (array_key_exists($cacheKey, $this->lookupCache)) {
            return $this->lookupCache[$cacheKey];
        }

        $row = db_one(
            "SELECT id FROM `$table` WHERE LOWER(label) = LOWER(?) LIMIT 1",
            [$label]
        );
        if ($row) {
            return $this->lookupCache[$cacheKey] = (int) $row['id'];
        }

        db_exec(
            "INSERT INTO `$table` (label, sort_order, is_active) VALUES (?, 100, 1)",
            [$label]
        );
        $id = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
        $this->log("Created new {$table} option: '{$label}'");

        return $this->lookupCache[$cacheKey] = $id;
    }

    /**
     * Map old-system calibration-frequency labels onto MagDyn's seeded
     * asset_cal_frequencies labels so imports reuse the existing options
     * (which carry the `months` value used for next-due auto-calc) instead
     * of creating month-less duplicates. Unknown labels pass through and are
     * created verbatim by resolveLookup().
     */
    private function normalizeFrequency(string $label): string
    {
        $map = [
            'yearly'        => 'Annual',
            'annually'      => 'Annual',
            'every 2 years' => 'Bi-Annual',
            'every 2 year'  => 'Bi-Annual',
            'biennial'      => 'Bi-Annual',
            'half yearly'   => 'Half-Yearly',
            'half-yearly'   => 'Half-Yearly',
            'monthly'       => 'Monthly',
            'quarterly'     => 'Quarterly',
            'on demand'     => 'On Demand',
        ];
        $key = strtolower(trim($label));
        return $map[$key] ?? trim($label);
    }

    // ----------------------------------------------------------------
    // Price parsing
    // ----------------------------------------------------------------

    /**
     * Parse the old A_Price custom field (cfv_3) into a float.
     * Strips currency symbols / thousands separators; returns null when
     * blank or non-numeric.
     */
    private function parsePrice(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($clean === '' || $clean === '-' || $clean === '.' || !is_numeric($clean)) {
            return null;
        }
        return (float) $clean;
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
                 cal_done_on, notes, a_price, cal_frequency_id, engraved_id, calibration_id, checked_ok_id,
                 status, current_vendor_id, current_user_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['asset_tag'],
                $d['asset_name'],
                $d['model_id'],
                $d['location_id'],
                $d['checkout_due_on'],
                $d['next_cal_due_on'],
                $d['cal_done_on'],
                $d['notes'],
                $d['a_price'],
                $d['cal_frequency_id'],
                $d['engraved_id'],
                $d['calibration_id'],
                $d['checked_ok_id'],
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
                 cal_done_on       = ?,
                 asset_name        = ?,
                 notes             = ?,
                 a_price           = ?,
                 cal_frequency_id  = ?,
                 engraved_id       = ?,
                 calibration_id    = ?,
                 checked_ok_id     = ?,
                 status            = ?,
                 current_vendor_id = ?,
                 current_user_id   = ?
             WHERE id = ?',
            [
                $d['model_id'],
                $d['location_id'],
                $d['checkout_due_on'],
                $d['next_cal_due_on'],
                $d['cal_done_on'],
                $d['asset_name'],
                $d['notes'],
                $d['a_price'],
                $d['cal_frequency_id'],
                $d['engraved_id'],
                $d['calibration_id'],
                $d['checked_ok_id'],
                $d['status'],
                $d['current_vendor_id'] ?? null,
                $d['current_user_id']   ?? null,
                $id,
            ]
        );
    }

    // ----------------------------------------------------------------
    // Parent asset linkage (Phase 1b)
    // ----------------------------------------------------------------

    /**
     * Resolve old parent_asset_id values into new assets.parent_asset_id.
     *
     * Old asset.parent_asset_id references another old asset_id; in MagDyn the
     * old asset_id is stored in assets.asset_tag, so the parent is found by
     * asset_tag lookup. Runs after every asset has been imported (a child can
     * appear before its parent across paginated batches), and re-clears the
     * link when the old system no longer has a parent so re-imports stay in sync.
     */
    private function resolveParents(): void
    {
        if (empty($this->pendingParents)) {
            return;
        }

        $linked = 0;
        $missing = 0;

        foreach ($this->pendingParents as $newAssetId => $oldParentId) {
            $parentNewId = null;

            if ($oldParentId > 0) {
                $parent = db_one(
                    'SELECT id FROM assets WHERE asset_tag = ? LIMIT 1',
                    [(string) $oldParentId]
                );
                if ($parent && (int) $parent['id'] !== $newAssetId) {
                    $parentNewId = (int) $parent['id'];
                } elseif (!$parent) {
                    $missing++;
                    $this->log(
                        "Parent asset_id={$oldParentId} for new asset id={$newAssetId} not found — link left empty.",
                        'warn'
                    );
                }
            }

            db_exec(
                'UPDATE assets SET parent_asset_id = ? WHERE id = ?',
                [$parentNewId, $newAssetId]
            );

            if ($parentNewId !== null) {
                $linked++;
            }
        }

        $this->counts['parent_linked'] = $linked;
        $this->log("Parent links resolved — {$linked} linked, {$missing} parent not found.");
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
    // Model import (Phase 0)
    // ----------------------------------------------------------------

    /**
     * Import every model from the old inventory independently of assets.
     * This guarantees all 511 (or however many) models exist in MagDyn
     * before Phase 1 runs, so no asset is silently skipped due to a
     * missing model.
     *
     * Uses the existing resolveModel() cache so Phase 1 never re-creates
     * models that were just imported here.
     */
    private function importModels(): void
    {
        $countData = old_inventory_api('model_count');
        $this->counts['model_total'] = (int) ($countData['count'] ?? 0);
        $this->log("Phase 0 — models: {$this->counts['model_total']} found in source.");

        // Snapshot the current model count so we can report net-new creates
        $beforeCount = (int) db_val('SELECT COUNT(*) FROM asset_models', [], 0);

        $modelTotal = $this->counts['model_total'];
        $processed  = 0;
        $this->emitProgress('Models', 0, $modelTotal);

        $offset = 0;

        while (true) {
            $data  = old_inventory_api('models', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['models'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $row) {
                try {
                    $this->resolveModel($row);
                } catch (\Throwable $e) {
                    $code = $row['asset_model_code'] ?? '?';
                    $this->log("Model '{$code}' failed: " . $e->getMessage(), 'error');
                }
            }

            $processed += count($batch);
            $this->emitProgress('Models', min($processed, $modelTotal), $modelTotal);

            $offset += self::BATCH_SIZE;

            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }

        $this->emitProgress('Models', $modelTotal, $modelTotal);

        $afterCount = (int) db_val('SELECT COUNT(*) FROM asset_models', [], 0);
        $this->counts['model_created'] = max(0, $afterCount - $beforeCount);
        $this->log(
            "Models done — {$this->counts['model_created']} created, " .
            ($this->counts['model_total'] - $this->counts['model_created']) . " already existed."
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

        $txnTotal  = $this->counts['txn_total'];
        $processed = 0;
        $this->emitProgress('Transactions', 0, $txnTotal);

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

            $processed += count($batch);
            $this->emitProgress('Transactions', min($processed, $txnTotal), $txnTotal);

            $offset += self::BATCH_SIZE;

            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }

        $this->emitProgress('Transactions', $txnTotal, $txnTotal);

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
        $oldAtxnId  = (int) ($row['asset_transaction_id'] ?? 0);
        $oldAssetId = (string) $row['asset_id'];

        // Dedup guard: GROUP BY in the API prevents duplicates at source,
        // but defensively skip if the same asset_transaction_id has already
        // been imported this run (e.g. if an older API version is deployed).
        if ($oldAtxnId > 0) {
            if (isset($this->importedAtxnIds[$oldAtxnId])) {
                $this->log("Skipped duplicate asset_transaction_id={$oldAtxnId} (txn {$oldTxnId}).", 'warn');
                return 'skipped';
            }
            $this->importedAtxnIds[$oldAtxnId] = true;
        }
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
