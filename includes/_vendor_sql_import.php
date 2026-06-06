<?php
/**
 * MagDyn — Vendor SQL-dump import helpers
 *
 * Parses a legacy inventory_live MySQL dump and maps the old schema to
 * the new vendors / vendor_contacts / vendor_addresses tables.
 *
 * Old tables consumed:
 *   company                    → vendors (name, gst_no, notes)
 *   company_custom_field_helper→ vendors.gst_no + bank details in notes
 *   address                    → vendor_addresses
 *   contact                    → vendor_contacts
 *   country                    → id → name lookup for vendor_addresses.country
 *   state_province             → id → name lookup for vendor_addresses.state
 */

if (!defined('MAGDYN_VENDOR_SQL_IMPORT_LOADED')) {
define('MAGDYN_VENDOR_SQL_IMPORT_LOADED', 1);

// ---------------------------------------------------------------------------
// SQL row parser
// ---------------------------------------------------------------------------

/**
 * Parse one MySQL INSERT value row string, e.g.
 *   (1, 3, 'Factory\'s', NULL, 98)
 * Returns a flat array of PHP values (string | int | float | null).
 */
function vsql_parse_row(string $row): array
{
    $row = trim($row);
    if (isset($row[0]) && $row[0] === '(') $row = substr($row, 1);
    if (strlen($row) && substr($row, -1) === ')') $row = substr($row, 0, -1);

    $values = [];
    $i   = 0;
    $len = strlen($row);

    while ($i < $len) {
        while ($i < $len && $row[$i] === ' ') $i++;
        if ($i >= $len) break;

        $ch = $row[$i];

        if ($ch === "'") {
            // Quoted string — handle \' and \\ escapes
            $str = '';
            $i++;
            while ($i < $len) {
                $c = $row[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $nc = $row[$i + 1];
                    if     ($nc === "'")  { $str .= "'";  $i += 2; }
                    elseif ($nc === '\\') { $str .= '\\'; $i += 2; }
                    elseif ($nc === 'n')  { $str .= "\n"; $i += 2; }
                    elseif ($nc === 'r')  { $str .= "\r"; $i += 2; }
                    elseif ($nc === 't')  { $str .= "\t"; $i += 2; }
                    else                  { $str .= $c;   $i++;    }
                } elseif ($c === "'") {
                    $i++;
                    break;
                } else {
                    $str .= $c;
                    $i++;
                }
            }
            $values[] = $str;
        } elseif ($ch === 'b' && $i + 1 < $len && $row[$i + 1] === "'") {
            // Binary literal b'0' / b'1'
            $i += 2;
            $bits = '';
            while ($i < $len && $row[$i] !== "'") { $bits .= $row[$i]; $i++; }
            if ($i < $len) $i++;
            $values[] = $bits === '1' ? 1 : 0;
        } elseif (strtoupper(substr($row, $i, 4)) === 'NULL') {
            $values[] = null;
            $i += 4;
        } else {
            // Numeric
            $num = '';
            while ($i < $len && $row[$i] !== ',' && $row[$i] !== ' ') {
                $num .= $row[$i];
                $i++;
            }
            $num = trim($num);
            if ($num === '') break;
            $values[] = strpos($num, '.') !== false ? (float)$num : (int)$num;
        }

        // Consume comma separator
        while ($i < $len && $row[$i] === ' ') $i++;
        if ($i < $len && $row[$i] === ',') $i++;
    }

    return $values;
}

// ---------------------------------------------------------------------------
// Table reader
// ---------------------------------------------------------------------------

/**
 * Stream-read all rows for one table from an open SQL file handle.
 * Handles multiple INSERT blocks for the same table.
 *
 * Returns array of associative rows.
 */
function vsql_load_table(string $filepath, string $table): array
{
    $handle = fopen($filepath, 'r');
    if (!$handle) return [];

    $columns  = [];
    $rows     = [];
    $inInsert = false;
    $search   = 'INSERT INTO `' . $table . '` (';

    while (($line = fgets($handle)) !== false) {
        $line    = rtrim($line, "\r\n");
        $trimmed = ltrim($line);

        if (!$inInsert) {
            if (strpos($trimmed, $search) !== false) {
                // Parse column list from this line
                if (preg_match('/INSERT INTO `[^`]+` \((.+)\) VALUES/', $trimmed, $m)) {
                    $columns  = array_map(function ($c) {
                        return trim(trim($c), '`');
                    }, explode(',', $m[1]));
                    $inInsert = true;
                }
            }
        } else {
            if ($trimmed === '' || $trimmed === ';') {
                continue;   // blank lines / lone semicolons between INSERT blocks
            }
            if ($trimmed[0] === '(') {
                // Value row
                $parsed = vsql_parse_row(rtrim($trimmed, ',;'));
                if (count($parsed) === count($columns)) {
                    $rows[] = array_combine($columns, $parsed);
                }
            } elseif (strpos($trimmed, $search) !== false) {
                // Another INSERT block for the same table — update column list
                if (preg_match('/INSERT INTO `[^`]+` \((.+)\) VALUES/', $trimmed, $m)) {
                    $columns = array_map(function ($c) {
                        return trim(trim($c), '`');
                    }, explode(',', $m[1]));
                }
            } elseif (strpos($trimmed, 'INSERT INTO `') === 0) {
                // INSERT for a different table — stop tracking
                $inInsert = false;
            } elseif (substr($trimmed, 0, 2) === '--') {
                // SQL comment — end of this table block
                $inInsert = false;
            }
            // Other lines (ALTER TABLE, SET, etc.) just keep looping
        }
    }

    fclose($handle);
    return $rows;
}

// ---------------------------------------------------------------------------
// Main parse + classification
// ---------------------------------------------------------------------------

/**
 * Parse the SQL dump and return structured import data.
 *
 * Returns:
 *   [
 *     'vendors' => [
 *       [
 *         'company_id'  => int,
 *         'name'        => string,
 *         'gst_no'      => string|null,
 *         'notes'       => string|null,
 *         'addresses'   => [ ['label','line1','line2','city','state','pincode','country'], ... ],
 *         'contacts'    => [ ['salutation','name','designation','email','phone'], ... ],
 *         'status'      => 'insert'|'skip',
 *         'existing_id' => int|null,
 *       ], ...
 *     ],
 *     'counts' => ['insert'=>N, 'skip'=>N, 'addresses'=>N, 'contacts'=>N],
 *     'warnings' => [...],
 *   ]
 */
function vsql_prepare_import(string $filepath): array
{
    $warnings = [];

    // Load tables from the SQL file
    $companies    = vsql_load_table($filepath, 'company');
    $customFields = vsql_load_table($filepath, 'company_custom_field_helper');
    $addresses    = vsql_load_table($filepath, 'address');
    $contacts     = vsql_load_table($filepath, 'contact');
    $countries    = vsql_load_table($filepath, 'country');
    $states       = vsql_load_table($filepath, 'state_province');

    if (empty($companies)) {
        $warnings[] = 'No rows found in the "company" table — check that this is the correct SQL file.';
    }

    // Build lookup maps
    $countryMap = [];
    foreach ($countries as $c) {
        $countryMap[(int)$c['country_id']] = (string)$c['short_description'];
    }

    $stateMap = [];
    foreach ($states as $s) {
        $stateMap[(int)$s['state_province_id']] = (string)$s['short_description'];
    }

    // Custom fields keyed by company_id
    $cfMap = [];
    foreach ($customFields as $cf) {
        $cid = (int)$cf['company_id'];
        $cfMap[$cid] = [
            'gst_no' => trim((string)($cf['cfv_18'] ?? '')),
            'bank'   => trim((string)($cf['cfv_33'] ?? '')),
        ];
    }

    // Addresses grouped by company_id — keep addr_id for primary detection
    $addrByCompany = [];
    foreach ($addresses as $a) {
        $cid     = (int)$a['company_id'];
        $country = $countryMap[(int)($a['country_id'] ?? 0)] ?? '';
        // country_id=98 was used as a placeholder for India in old data
        if ($country === 'USA' || $country === '') {
            // Try to infer from state — if state is an Indian state, use India
            $stateId = (int)($a['state_province_id'] ?? 0);
            $stateName = $stateMap[$stateId] ?? '';
            $country = ($stateName !== '') ? $countryMap[(int)($a['country_id'] ?? 0)] : 'India';
            // Still default to India for empty/USA country with 0 state
            if ($country === 'USA' && $stateName === '') $country = 'India';
        }
        $addrByCompany[$cid][] = [
            'addr_id' => (int)$a['address_id'],
            'label'   => trim((string)($a['short_description'] ?? '')),
            'line1'   => trim((string)($a['address_1'] ?? '')),
            'line2'   => trim((string)($a['address_2'] ?? '')),
            'city'    => trim((string)($a['city'] ?? '')),
            'state'   => $stateMap[(int)($a['state_province_id'] ?? 0)] ?? '',
            'pincode' => trim((string)($a['postal_code'] ?? '')),
            'country' => $country ?: 'India',
        ];
    }

    // Contacts grouped by company_id
    $contByCompany = [];
    foreach ($contacts as $c) {
        $cid       = (int)$c['company_id'];
        $firstName = trim((string)($c['first_name'] ?? ''));
        $lastName  = trim((string)($c['last_name']  ?? ''));

        // If first_name === last_name the old system used the company name as a placeholder
        if (strtolower($firstName) === strtolower($lastName) && $firstName !== '') {
            $name = $firstName;
        } else {
            $name = trim($firstName . ' ' . $lastName);
        }
        if ($name === '') continue;

        // Prefer mobile phone, fall back to office, then home
        $phone = trim((string)($c['phone_mobile'] ?? ''));
        if ($phone === '') $phone = trim((string)($c['phone_office'] ?? ''));
        if ($phone === '') $phone = trim((string)($c['phone_home']   ?? ''));

        $contByCompany[$cid][] = [
            'salutation'  => vsql_normalize_salutation((string)($c['title'] ?? '')),
            'name'        => $name,
            'designation' => trim((string)($c['description'] ?? '')) ?: null,
            'email'       => trim((string)($c['email']       ?? '')) ?: null,
            'phone'       => $phone ?: null,
        ];
    }

    // Build structured vendor list
    $vendors        = [];
    $totalAddresses = 0;
    $totalContacts  = 0;

    foreach ($companies as $co) {
        $cid  = (int)$co['company_id'];
        $name = trim((string)($co['short_description'] ?? ''));
        if ($name === '') continue;

        // Custom fields
        $cf     = $cfMap[$cid] ?? [];
        $gst    = trim((string)($cf['gst_no'] ?? ''));
        $placeholders = ['na', 'na-', 'nil', 'n/a', 'none', 'a', 'na ', ' na', ''];
        if (in_array(strtolower($gst), $placeholders, true)) $gst = '';
        // vendors.gst_no is varchar(20); a valid GSTIN is 15 chars. Anything
        // longer is free-text junk from the legacy custom field — drop it to
        // the notes block rather than risk a commit-time overflow error.
        $gstOverflow = '';
        if ($gst !== '' && mb_strlen($gst) > 20) {
            $gstOverflow = $gst;
            $gst = '';
        }

        // Notes: website + long_description + bank details
        $noteParts = [];
        $website   = trim((string)($co['website']          ?? ''));
        $longDesc  = trim((string)($co['long_description'] ?? ''));
        $bank      = trim((string)($cf['bank']             ?? ''));
        if ($website     !== '') $noteParts[] = 'Website: ' . $website;
        if ($longDesc    !== '') $noteParts[] = $longDesc;
        if ($gstOverflow !== '') $noteParts[] = 'GST/Tax (from legacy): ' . $gstOverflow;
        if ($bank        !== '') $noteParts[] = "Bank details:\n" . str_replace('\n', "\n", $bank);
        $notes = $noteParts ? implode("\n\n", $noteParts) : null;

        // Addresses — sort primary address (company.address_id) first
        $primaryAddrId = (int)($co['address_id'] ?? 0);
        $addrs = $addrByCompany[$cid] ?? [];
        usort($addrs, function ($a, $b) use ($primaryAddrId) {
            return ($b['addr_id'] === $primaryAddrId) - ($a['addr_id'] === $primaryAddrId);
        });
        // Drop internal addr_id key before storing
        $addrs = array_map(function ($a) {
            unset($a['addr_id']);
            return $a;
        }, $addrs);
        // Filter out addresses with no line1
        $addrs = array_values(array_filter($addrs, function ($a) {
            return $a['line1'] !== '';
        }));

        $conts = $contByCompany[$cid] ?? [];

        // Dedup check: match by name (case-insensitive trim)
        $existing = db_one(
            'SELECT id FROM vendors WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1',
            [$name]
        );

        $status = $existing ? 'skip' : 'insert';

        $vendors[] = [
            'company_id'  => $cid,
            'name'        => $name,
            'gst_no'      => $gst ?: null,
            'notes'       => $notes,
            'addresses'   => $addrs,
            'contacts'    => $conts,
            'status'      => $status,
            'existing_id' => $existing ? (int)$existing['id'] : null,
        ];

        if ($status === 'insert') {
            $totalAddresses += count($addrs);
            $totalContacts  += count($conts);
        }
    }

    $insertCount = 0;
    $skipCount   = 0;
    foreach ($vendors as $v) {
        if ($v['status'] === 'insert') $insertCount++;
        else $skipCount++;
    }

    return [
        'vendors'  => $vendors,
        'counts'   => [
            'insert'    => $insertCount,
            'skip'      => $skipCount,
            'addresses' => $totalAddresses,
            'contacts'  => $totalContacts,
        ],
        'warnings' => $warnings,
    ];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Normalise a title string to one of the accepted salutation values
 * (Mr, Ms, Mrs, Mx, Dr, Prof) or null.
 */
function vsql_normalize_salutation(string $title): ?string
{
    $map = [
        'mr'    => 'Mr',
        'mr.'   => 'Mr',
        'mrs'   => 'Mrs',
        'mrs.'  => 'Mrs',
        'ms'    => 'Ms',
        'ms.'   => 'Ms',
        'mx'    => 'Mx',
        'dr'    => 'Dr',
        'dr.'   => 'Dr',
        'prof'  => 'Prof',
        'prof.' => 'Prof',
    ];
    return $map[strtolower(trim($title))] ?? null;
}

/**
 * Store the uploaded SQL file to a persistent temp location.
 * Returns metadata array or null on failure.
 */
function vsql_persist_upload(string $tmpPath, string $origName): ?array
{
    if (!is_uploaded_file($tmpPath)) return null;
    $base = dirname(__DIR__) . '/uploads/notes/import_sql';
    if (!is_dir($base) && !@mkdir($base, 0775, true)) return null;
    $hex   = bin2hex(random_bytes(8));
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $origName);
    if ($clean === '' || $clean === '.') $clean = 'import.sql';
    $dest  = $base . '/' . $hex . '_' . $clean;
    if (!@move_uploaded_file($tmpPath, $dest)) {
        if (!@copy($tmpPath, $dest)) return null;
    }
    return [
        'path'     => $dest,
        'filename' => $clean,
        'size'     => (int)@filesize($dest),
    ];
}

/**
 * Auto-generate the next V-NNNNN vendor code (mirrors vendors.php logic).
 */
function vsql_vendor_code_generate(): string
{
    $last = db_val('SELECT code FROM vendors ORDER BY id DESC LIMIT 1', [], '');
    $next = 1;
    if ($last && preg_match('/^V-(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    $candidate = 'V-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
    if (db_val('SELECT id FROM vendors WHERE code = ?', [$candidate], 0)) {
        $candidate = 'V-' . date('YmdHis');
    }
    return $candidate;
}

/**
 * Commit a single vendor row + its contacts and addresses.
 * Returns the new vendor id.
 */
function vsql_commit_vendor(array $v, int $actorId): int
{
    $code = vsql_vendor_code_generate();
    db_exec(
        'INSERT INTO vendors (code, name, gst_no, notes, is_active) VALUES (?, ?, ?, ?, 1)',
        [$code, $v['name'], $v['gst_no'], $v['notes']]
    );
    $vendorId = (int)db()->lastInsertId();

    // Contacts — first contact is primary
    $sort       = 10;
    $primarySet = false;
    foreach ($v['contacts'] as $c) {
        $isPrimary  = $primarySet ? 0 : 1;
        $primarySet = true;
        if ($isPrimary) {
            db_exec('UPDATE vendor_contacts SET is_primary = 0 WHERE vendor_id = ?', [$vendorId]);
        }
        db_exec(
            'INSERT INTO vendor_contacts
                (vendor_id, salutation, name, designation, email, phone, is_primary, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$vendorId, $c['salutation'], $c['name'], $c['designation'],
             $c['email'], $c['phone'], $isPrimary, $sort]
        );
        $sort += 10;
    }

    // Addresses — first non-empty address is primary
    $sort       = 10;
    $primarySet = false;
    foreach ($v['addresses'] as $a) {
        if ($a['line1'] === '') continue;
        $isPrimary  = $primarySet ? 0 : 1;
        $primarySet = true;
        if ($isPrimary) {
            db_exec('UPDATE vendor_addresses SET is_primary = 0 WHERE vendor_id = ?', [$vendorId]);
        }
        db_exec(
            'INSERT INTO vendor_addresses
                (vendor_id, label, line1, line2, city, state, pincode, country, is_primary, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$vendorId, $a['label'] ?: null, $a['line1'],
             $a['line2'] ?: null, $a['city'] ?: null,
             $a['state'] ?: null, $a['pincode'] ?: null,
             $a['country'] ?: 'India', $isPrimary, $sort]
        );
        $sort += 10;
    }

    db_exec(
        "INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'vendor.import', ?)",
        [$actorId, 'imported vendor ' . $code . ' from legacy SQL dump']
    );

    return $vendorId;
}

} // MAGDYN_VENDOR_SQL_IMPORT_LOADED
