<?php
/**
 * MagDyn — Old Inventory Vendor Import Service (API version)
 *
 * Fetches companies (vendors) from the legacy inventory system via the
 * HTTP API (api_export_vendors.php deployed on the old server) and imports
 * them — together with their contacts and addresses — into MagDyn.
 *
 * Source join (performed server-side in api_export_vendors.php):
 *   company  + company_custom_field_helper
 *   address  + address_custom_field_helper   (1 company → many addresses)
 *   contact  + contact_custom_field_helper   (1 company → many contacts)
 *
 * Field mapping (old → new):
 *   company.short_description        → vendors.name           (match key #2)
 *   cfv_50 "Vendor Code"             → vendors.code           (match key #1, derived if blank)
 *   company.email                    → vendors.email          (fallback: primary contact email)
 *   company.telephone                → vendors.phone          (fallback: primary contact phone)
 *   cfv_18 "GST"                     → vendors.gst_no
 *   primary address (composed)       → vendors.address
 *   long_description + cfv_33 bank   → vendors.notes
 *   address.*                        → vendor_addresses.*
 *   contact.*                        → vendor_contacts.*
 *
 * Duplicate handling:
 *   Vendor matched by code, else by name → UPDATE header; its contacts and
 *   addresses are wiped and re-inserted fresh each run.  Otherwise INSERT.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryVendorImportService.php';
 *   $svc    = new OldInventoryVendorImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryVendorImportService
{
    /** Records per API call / DB transaction batch */
    private const BATCH_SIZE = 100;

    /** @var int  User ID credited as creator/editor for imported records */
    private int $actorId;

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var callable|null  Progress reporter: fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'vendor_total'      => 0,
        'vendor_imported'   => 0,
        'vendor_updated'    => 0,
        'vendor_failed'     => 0,
        'vendor_skipped'    => 0,
        'contact_imported'  => 0,
        'address_imported'  => 0,
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
     * Run the vendor import.
     *
     * @return array{vendor_total:int,vendor_imported:int,vendor_updated:int,vendor_failed:int,vendor_skipped:int,contact_imported:int,address_imported:int,errors:array}
     */
    public function run(): array
    {
        $countData = old_inventory_vendor_api('vendor_count');
        $this->counts['vendor_total'] = (int) ($countData['count'] ?? 0);
        $this->log("Vendors: {$this->counts['vendor_total']} companies found in source.");

        $total     = $this->counts['vendor_total'];
        $processed = 0;
        $this->emitProgress('Vendors', 0, $total);

        $offset = 0;
        while (true) {
            $data  = old_inventory_vendor_api('vendors', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['vendors'] ?? [];

            if (empty($batch)) {
                break;
            }

            $this->processBatch($batch);

            $processed += count($batch);
            $this->emitProgress('Vendors', min($processed, $total), $total);

            $offset += self::BATCH_SIZE;

            if (count($batch) < self::BATCH_SIZE) {
                break;  // last page
            }
        }

        $this->emitProgress('Vendors', $total, $total);

        $this->log(
            "Vendors done — " .
            "Imported: {$this->counts['vendor_imported']}, " .
            "Updated: {$this->counts['vendor_updated']}, " .
            "Failed: {$this->counts['vendor_failed']}, " .
            "Skipped: {$this->counts['vendor_skipped']}. " .
            "Contacts: {$this->counts['contact_imported']}, " .
            "Addresses: {$this->counts['address_imported']}."
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
                $this->processOneVendor($row);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            $this->log("Batch transaction rolled back: " . $e->getMessage(), 'error');
            $this->counts['vendor_failed'] += count($batch);
        }
    }

    private function processOneVendor(array $row): void
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            $this->counts['vendor_skipped']++;
            $this->log("Skipped company_id={$row['company_id']}: empty name.", 'warn');
            return;
        }

        try {
            $contacts  = is_array($row['contacts']  ?? null) ? $row['contacts']  : [];
            $addresses = is_array($row['addresses'] ?? null) ? $row['addresses'] : [];

            // Primary contact / address feed the denormalised vendor header
            $primaryContact = $contacts[0]  ?? [];
            $primaryAddress = $addresses[0] ?? [];

            $email = $this->clean($row['email'] ?? '', 190)
                  ?: $this->clean($primaryContact['email'] ?? '', 190);
            $phone = $this->clean($row['telephone'] ?? '', 40)
                  ?: $this->clean($this->bestPhone($primaryContact), 40);
            $gst   = $this->cleanGst($row['gst_no'] ?? '');
            $addrStr = $this->composeAddress($primaryAddress);
            $notes   = $this->composeNotes($row);
            $contactName = trim((string) ($this->personName($primaryContact)));

            $vendorId = $this->upsertVendor([
                'code'    => $this->resolveCode($row, $name),
                'name'    => substr($name, 0, 190),
                'contact' => $contactName !== '' ? substr($contactName, 0, 150) : null,
                'email'   => $email ?: null,
                'phone'   => $phone ?: null,
                'gst_no'  => $gst   ?: null,
                'address' => $addrStr ?: null,
                'notes'   => $notes  ?: null,
            ]);

            // Replace child rows wholesale so re-imports never duplicate
            db_exec('DELETE FROM vendor_contacts  WHERE vendor_id = ?', [$vendorId]);
            db_exec('DELETE FROM vendor_addresses WHERE vendor_id = ?', [$vendorId]);

            $this->insertContacts($vendorId, $contacts);
            $this->insertAddresses($vendorId, $addresses);

        } catch (Throwable $e) {
            $this->counts['vendor_failed']++;
            $this->log("Failed company_id={$row['company_id']} ({$name}): " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    // ----------------------------------------------------------------
    // Vendor header upsert
    // ----------------------------------------------------------------

    private function upsertVendor(array $d): int
    {
        $existing = db_one(
            'SELECT id FROM vendors WHERE code = ? LIMIT 1',
            [$d['code']]
        );
        if (!$existing) {
            $existing = db_one(
                'SELECT id FROM vendors WHERE LOWER(name) = LOWER(?) LIMIT 1',
                [$d['name']]
            );
        }

        if ($existing) {
            db_exec(
                'UPDATE vendors
                    SET name    = ?,
                        contact = ?,
                        email   = ?,
                        phone   = ?,
                        gst_no  = ?,
                        address = ?,
                        notes   = ?
                  WHERE id = ?',
                [
                    $d['name'], $d['contact'], $d['email'], $d['phone'],
                    $d['gst_no'], $d['address'], $d['notes'], (int) $existing['id'],
                ]
            );
            $this->counts['vendor_updated']++;
            return (int) $existing['id'];
        }

        db_exec(
            'INSERT INTO vendors (code, name, contact, email, phone, gst_no, address, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $d['code'], $d['name'], $d['contact'], $d['email'],
                $d['phone'], $d['gst_no'], $d['address'], $d['notes'],
            ]
        );
        $this->counts['vendor_imported']++;
        return (int) db_val('SELECT LAST_INSERT_ID()', [], 0);
    }

    /**
     * Resolve a unique vendor code. Prefer the legacy "Vendor Code"
     * custom field (cfv_50); otherwise derive one from the name.
     * Keeps an existing vendor's code stable when matched.
     */
    private function resolveCode(array $row, string $name): string
    {
        $code = $this->clean($row['vendor_code'] ?? '', 40);

        if ($code === '') {
            $code = substr(preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $name)), 0, 40);
            if ($code === '') {
                $code = 'VND-' . (int) ($row['company_id'] ?? 0);
            }
        }

        // If this code already belongs to a *different* vendor whose name
        // doesn't match, suffix until unique so the INSERT can't collide.
        $base   = $code;
        $suffix = 1;
        while (true) {
            $hit = db_one('SELECT id, name FROM vendors WHERE code = ? LIMIT 1', [$code]);
            if (!$hit) {
                break; // free
            }
            if (strcasecmp((string) $hit['name'], $name) === 0) {
                break; // same vendor — reuse its code
            }
            $tag  = '-' . $suffix++;
            $code = substr($base, 0, 40 - strlen($tag)) . $tag;
        }

        return $code;
    }

    // ----------------------------------------------------------------
    // Contacts
    // ----------------------------------------------------------------

    private function insertContacts(int $vendorId, array $contacts): void
    {
        $sort = 0;
        foreach ($contacts as $c) {
            $name = $this->personName($c);
            if ($name === '') {
                continue; // vendor_contacts.name is NOT NULL
            }

            db_exec(
                'INSERT INTO vendor_contacts
                    (vendor_id, salutation, name, designation, email, phone, is_primary, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $vendorId,
                    $this->clean($c['title'] ?? '', 16) ?: null,
                    substr($name, 0, 190),
                    null,
                    $this->clean($c['email'] ?? '', 190) ?: null,
                    $this->clean($this->bestPhone($c), 40) ?: null,
                    $sort === 0 ? 1 : 0,
                    $sort,
                ]
            );
            $this->counts['contact_imported']++;
            $sort++;
        }
    }

    // ----------------------------------------------------------------
    // Addresses
    // ----------------------------------------------------------------

    private function insertAddresses(int $vendorId, array $addresses): void
    {
        $sort = 0;
        foreach ($addresses as $a) {
            $line1 = $this->clean($a['line1'] ?? '', 190);
            if ($line1 === '') {
                // vendor_addresses.line1 is NOT NULL — fall back to label/city
                $line1 = $this->clean($a['label'] ?? '', 190)
                      ?: $this->clean($a['city'] ?? '', 190);
            }
            if ($line1 === '') {
                continue; // nothing usable
            }

            db_exec(
                'INSERT INTO vendor_addresses
                    (vendor_id, label, line1, line2, city, state, pincode, country, is_primary, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $vendorId,
                    $this->clean($a['label'] ?? '', 80) ?: null,
                    $line1,
                    $this->clean($a['line2'] ?? '', 190) ?: null,
                    $this->clean($a['city'] ?? '', 100) ?: null,
                    null,                                   // state name not resolved from legacy id
                    $this->clean($a['pincode'] ?? '', 20) ?: null,
                    'India',
                    $sort === 0 ? 1 : 0,
                    $sort,
                ]
            );
            $this->counts['address_imported']++;
            $sort++;
        }
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /** Trim a value, collapse 'NULL', and cap to a max length. */
    private function clean($val, int $max): string
    {
        $s = trim((string) $val);
        if ($s === '' || strcasecmp($s, 'null') === 0) {
            return '';
        }
        return substr($s, 0, $max);
    }

    /** GST values like 'NA' / 'na' / '' are noise — drop them. */
    private function cleanGst($val): string
    {
        $s = $this->clean($val, 20);
        if ($s === '' || strcasecmp($s, 'na') === 0) {
            return '';
        }
        return $s;
    }

    /** Build a display name from first/last, de-duplicating identical halves. */
    private function personName(array $c): string
    {
        $first = trim((string) ($c['first_name'] ?? ''));
        $last  = trim((string) ($c['last_name']  ?? ''));
        if ($first !== '' && strcasecmp($first, $last) === 0) {
            return $first; // legacy often stores the same value in both
        }
        return trim($first . ' ' . $last);
    }

    /** Pick the most useful phone number from a contact. */
    private function bestPhone(array $c): string
    {
        foreach (['phone_mobile', 'phone_office', 'phone_home'] as $k) {
            $v = trim((string) ($c[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /** Compose a one-line address string for vendors.address (max 500). */
    private function composeAddress(array $a): string
    {
        if (empty($a)) {
            return '';
        }
        $parts = array_filter([
            trim((string) ($a['line1'] ?? '')),
            trim((string) ($a['line2'] ?? '')),
            trim((string) ($a['city'] ?? '')),
            trim((string) ($a['pincode'] ?? '')),
        ], fn($p) => $p !== '' && strcasecmp($p, 'null') !== 0);

        return substr(implode(', ', $parts), 0, 500);
    }

    /** Combine free-text fields into vendors.notes. */
    private function composeNotes(array $row): string
    {
        $bits = [];

        $desc = $this->clean($row['long_description'] ?? '', 5000);
        if ($desc !== '') {
            $bits[] = $desc;
        }

        $bank = trim((string) ($row['bank_details'] ?? ''));
        if ($bank !== '' && strcasecmp($bank, 'null') !== 0) {
            $bits[] = "Bank Details:\n" . $bank;
        }

        $svc = $this->clean($row['service_category'] ?? '', 190);
        if ($svc !== '') {
            $bits[] = "Service Category: " . $svc;
        }

        return trim(implode("\n\n", $bits));
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
