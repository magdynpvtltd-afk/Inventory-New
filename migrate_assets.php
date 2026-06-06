<?php
/**
 * MagDyn — One-time Asset Migration Script
 *
 * Imports asset_models + assets from the old "inventory_live" database
 * SQL dumps into the new MagDyn schema.
 *
 * Sources:
 *   C:\Users\ADMIN\Downloads\asset_model.sql
 *   C:\Users\ADMIN\Downloads\asset.sql
 *
 * Prerequisites:
 *   - Locations already migrated (matched by name)
 *   - Categories already imported (stored as text in asset_models.category)
 *
 * Run once via browser, then DELETE this file.
 * URL: http://localhost/magdyn/migrate_assets.php
 *      Add ?confirm=1 to actually execute (dry-run by default).
 *      Add ?confirm=1&wipe=1 to re-run (drops previously migrated data first).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!permission_check('asset', 'manage')) {
    die('<pre style="color:red">Permission denied. Requires asset manage permission.</pre>');
}

set_time_limit(600);
ignore_user_abort(true);

$DRY_RUN = !isset($_GET['confirm']);
$WIPE    = isset($_GET['wipe']) && !$DRY_RUN;

$MODEL_SQL = 'C:/Users/ADMIN/Downloads/asset_model.sql';
$ASSET_SQL = 'C:/Users/ADMIN/Downloads/asset.sql';

// ================================================================
// SOURCE FILE CHECK
// ================================================================
foreach ([$MODEL_SQL, $ASSET_SQL] as $f) {
    if (!file_exists($f)) {
        die("<pre style='color:red'>File not found: $f\nPlace the SQL dump at that path and retry.</pre>");
    }
}

// ================================================================
// CATEGORY MAP  old category_id => category name (text)
// ================================================================
$CAT_MAP = [
    1  => 'Finished Goods',        2  => 'Intermediate Goods',
    3  => 'Raw Material',          4  => 'Consumables',
    5  => 'Fasteners',             6  => 'Lathe Tool',
    7  => 'Gauges',                8  => 'Dies_Tools',
    9  => 'Packing',              10  => 'Measuring Instruments',
    11 => 'Machines',             12  => 'Fixtures',
    13 => 'Air Conditoner',       14  => 'Machine Parts',
    15 => 'Tools',                16  => 'Cupboard',
    17 => 'Office Assets',        18  => 'Electronics Items',
    19 => 'Tool Part',            20  => 'Insert',
];

// ================================================================
// MANUFACTURER MAP  old manufacturer_id => manufacturer name
// ================================================================
$MFG_MAP = [1 => 'Magneto Dynamics', 2 => ''];

// ================================================================
// LOCATION MAP  old location_id => new DB location id + status
// ================================================================
$locs = db_all('SELECT id, name FROM locations ORDER BY id');
$locByName = [];
foreach ($locs as $r) {
    $locByName[strtolower(trim($r['name']))] = (int)$r['id'];
}
$magdynId = $locByName['magdyn'] ?? null;

// Names of real physical locations in old system
$OLD_LOC_NAMES = [
    115 => 'magdyn',
    116 => 'lathe-dpt',
    117 => 'edm-dpt',
    118 => 'cal-asy-dpt',
    119 => 'cal-tst-dpt',
    121 => 'rejection return',
    122 => 'lost in process',
    123 => 'ats',
    124 => 'sample',
];

// old_location_id => new location row id (null = no physical location)
$LOC_ID_MAP = [];
foreach ($OLD_LOC_NAMES as $oldId => $name) {
    $LOC_ID_MAP[$oldId] = $locByName[$name] ?? $magdynId;
}
foreach ([1, 2, 3, 4, 5, 6] as $v) {
    $LOC_ID_MAP[$v] = $magdynId;  // virtual statuses → default to Magdyn
}
$LOC_ID_MAP[120] = null;  // "Vendor" → status='with_vendor'
$LOC_ID_MAP[125] = null;  // "Users"  → status='with_user'

// ================================================================
// SQL VALUES PARSER
// State-machine parser for phpMyAdmin-style multi-value INSERT dumps.
// Handles: NULL, b'0'/b'1', single-quoted strings with '' escapes,
// integers, decimals, and datetime strings.
// ================================================================
function parse_sql_insert_values(string $filePath): array
{
    $content = file_get_contents($filePath);
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $allRows = [];
    $p       = 0;
    $len     = strlen($content);

    while ($p < $len) {
        // Find next INSERT INTO
        $insertPos = strpos($content, 'INSERT INTO `', $p);
        if ($insertPos === false) break;

        // Find VALUES keyword after INSERT INTO
        $valStart = strpos($content, ') VALUES', $insertPos);
        if ($valStart === false) { $p = $insertPos + 1; continue; }
        $valStart += 8; // skip ') VALUES'

        // Skip optional whitespace/newline
        while ($valStart < $len && ($content[$valStart] === ' ' || $content[$valStart] === "\n")) {
            $valStart++;
        }

        // Read until the terminating semicolon of this INSERT
        // We parse row by row to avoid loading the whole block into memory
        $p = $valStart;
        while ($p < $len) {
            // Skip whitespace and commas between rows
            while ($p < $len && ($content[$p] === ' ' || $content[$p] === "\n" || $content[$p] === "\t" || $content[$p] === ',')) $p++;

            if ($p >= $len) break;
            if ($content[$p] === ';') { $p++; break; }  // end of this INSERT
            if ($content[$p] !== '(') { $p++; continue; }

            $p++; // skip '('
            $row = [];

            // Parse comma-separated values until matching ')'
            while ($p < $len) {
                // Skip leading whitespace
                while ($p < $len && ($content[$p] === ' ' || $content[$p] === "\t")) $p++;

                if ($p >= $len) break;
                $c = $content[$p];

                if ($c === ')') { $p++; break; }  // end of row
                if ($c === ',') { $p++; continue; }

                if ($c === "'") {
                    // Quoted string
                    $p++;
                    $val = '';
                    while ($p < $len) {
                        if ($content[$p] === "'" && isset($content[$p + 1]) && $content[$p + 1] === "'") {
                            $val .= "'"; $p += 2;
                        } elseif ($content[$p] === "'") {
                            $p++; break;
                        } else {
                            $val .= $content[$p++];
                        }
                    }
                    $row[] = $val;
                } elseif (strtoupper(substr($content, $p, 4)) === 'NULL') {
                    $row[] = null;
                    $p += 4;
                } elseif ($content[$p] === 'b' && isset($content[$p + 1]) && $content[$p + 1] === "'") {
                    // Binary literal  b'0' or b'1'
                    $p += 2;
                    $bit = (int)$content[$p]; $p++;
                    if (isset($content[$p]) && $content[$p] === "'") $p++;
                    $row[] = $bit;
                } else {
                    // Number or bare word
                    $num = '';
                    while ($p < $len && $content[$p] !== ',' && $content[$p] !== ')' && $content[$p] !== "\n") {
                        $num .= $content[$p++];
                    }
                    $num = trim($num);
                    if (is_numeric($num)) {
                        $row[] = strpos($num, '.') !== false ? (float)$num : (int)$num;
                    } else {
                        $row[] = $num;
                    }
                }
            }

            if (!empty($row)) $allRows[] = $row;
        }
    }

    return $allRows;
}

// ================================================================
// COLUMN INDICES from old schema
//
// asset_model:  0=id, 1=category_id, 2=manufacturer_id,
//               3=model_code, 4=short_desc, 5=long_desc
//
// asset:        0=id, 1=parent_id, 2=model_id, 3=location_id,
//               4=asset_code, 6=checked_out_flag, 8=archived_flag,
//               15=purchase_date, 16=purchase_cost
// ================================================================

// ================================================================
// HELPER: generate next asset model code
// ================================================================
function next_model_code(): string
{
    $last = db_val(
        "SELECT code FROM asset_models WHERE code LIKE 'MDL-%' ORDER BY id DESC LIMIT 1",
        [], ''
    );
    $next = 1;
    if ($last && preg_match('/^MDL-(\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
    $code = 'MDL-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    if (db_val('SELECT id FROM asset_models WHERE code = ?', [$code], 0)) {
        $code = 'MDL-' . date('YmdHis') . rand(10, 99);
    }
    return $code;
}

// ================================================================
// PARSE BOTH FILES
// ================================================================
echo "<pre style='font-family:monospace;font-size:13px;'>";
echo "=== MagDyn Asset Migration ===\n\n";
echo $DRY_RUN
    ? "MODE: DRY RUN (no changes will be written). Add ?confirm=1 to execute.\n\n"
    : "MODE: LIVE — changes will be committed to the database.\n\n";

echo "Parsing asset_model.sql ... ";
$rawModels = parse_sql_insert_values($MODEL_SQL);
echo count($rawModels) . " model rows found.\n";

echo "Parsing asset.sql ... ";
$rawAssets = parse_sql_insert_values($ASSET_SQL);
echo count($rawAssets) . " asset rows found.\n\n";

// ================================================================
// WIPE previously migrated data if requested
// ================================================================
if ($WIPE) {
    echo "WIPE mode: removing all assets and asset_models inserted by a previous migration run...\n";
    // We can only wipe if we added a migration marker. Since we didn't,
    // safest: inform the user to do it manually.
    echo "WARNING: Automatic wipe not implemented to prevent data loss.\n";
    echo "To re-run: manually truncate asset_models and assets tables if they are empty in the new system.\n\n";
}

// ================================================================
// STEP 1 — MIGRATE ASSET MODELS
// ================================================================
echo "--- STEP 1: Asset Models ---\n";

$modelIdMap    = [];  // old_model_id => new_model_id
$modelInserted = 0;
$modelSkipped  = 0;
$modelErrors   = [];

if (!$DRY_RUN) {
    db()->beginTransaction();
    try {
        foreach ($rawModels as $r) {
            $oldId    = (int)$r[0];
            $catId    = isset($r[1]) ? (int)$r[1] : null;
            $mfgId    = isset($r[2]) ? (int)$r[2] : null;
            $modelNum = isset($r[3]) && $r[3] !== '' ? (string)$r[3] : null;
            $name     = isset($r[4]) ? trim((string)$r[4]) : '';
            $notes    = isset($r[5]) && $r[5] !== '' ? trim((string)$r[5]) : null;
            if ($notes !== null) $notes = str_replace(['\r\n', '\r', '\n'], "\n", $notes);

            $category     = $CAT_MAP[$catId]   ?? '';
            $manufacturer = $MFG_MAP[$mfgId]   ?? '';

            if ($name === '') { $modelErrors[] = "Model old_id=$oldId: empty name, skipped."; $modelSkipped++; continue; }

            // Idempotency: skip if already exists with the same name + model_number
            $existCheck = db_one(
                'SELECT id FROM asset_models WHERE name = ? AND (model_number = ? OR (model_number IS NULL AND ? IS NULL)) LIMIT 1',
                [$name, $modelNum, $modelNum]
            );
            if ($existCheck) {
                $modelIdMap[$oldId] = (int)$existCheck['id'];
                $modelSkipped++;
                continue;
            }

            $code = next_model_code();
            db_exec(
                'INSERT INTO asset_models (code, name, category, manufacturer, model_number, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$code, $name, $category ?: null, $manufacturer ?: null, $modelNum, $notes]
            );
            $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
            $modelIdMap[$oldId] = $newId;
            $modelInserted++;
        }
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        echo "<span style='color:red'>ERROR during asset_model migration: " . htmlspecialchars($e->getMessage()) . "</span>\n";
        echo "Transaction rolled back. No models were inserted.\n</pre>";
        exit;
    }
} else {
    // Dry-run: just simulate
    foreach ($rawModels as $r) {
        $oldId = (int)$r[0];
        $name  = isset($r[4]) ? trim((string)$r[4]) : '';
        if ($name === '') { $modelSkipped++; continue; }
        $modelIdMap[$oldId] = 0; // placeholder
        $modelInserted++;
    }
}

echo "  Inserted : $modelInserted\n";
echo "  Skipped  : $modelSkipped (already exist)\n";
if ($modelErrors) {
    echo "  Warnings :\n";
    foreach ($modelErrors as $e) echo "    - $e\n";
}
echo "\n";

// ================================================================
// STEP 2 — MIGRATE ASSETS (first pass: insert)
// ================================================================
echo "--- STEP 2: Assets ---\n";

$assetInserted  = 0;
$assetSkipped   = 0;
$assetErrors    = [];
$oldToNewAsset  = [];  // old_asset_id => new_asset_id (for parent resolution)
$parentPairs    = [];  // [[new_asset_id, old_parent_id], ...] — resolved in second pass

if (!$DRY_RUN) {
    db()->beginTransaction();
    try {
        foreach ($rawAssets as $r) {
            $oldId       = (int)$r[0];
            $oldParentId = isset($r[1]) ? (int)$r[1] : null;
            $oldModelId  = (int)$r[2];
            $oldLocId    = isset($r[3]) ? (int)$r[3] : null;
            $assetTag    = isset($r[4]) ? trim((string)$r[4]) : '';
            // col 6 = checked_out_flag (b'0'/b'1')
            // col 8 = archived_flag
            $archivedFlag = isset($r[8]) ? (int)$r[8] : 0;
            $purchaseDate = (isset($r[15]) && $r[15] !== null && $r[15] !== '' && $r[15] !== '0000-00-00') ? $r[15] : null;
            $purchaseCost = (isset($r[16]) && $r[16] !== null) ? (float)$r[16] : null;

            if ($assetTag === '') {
                $assetErrors[] = "Asset old_id=$oldId: empty asset_code, skipped.";
                $assetSkipped++;
                continue;
            }

            // Resolve model_id
            $newModelId = $modelIdMap[$oldModelId] ?? null;
            if (!$newModelId) {
                $assetErrors[] = "Asset '$assetTag': model old_id=$oldModelId not found in mapping, skipped.";
                $assetSkipped++;
                continue;
            }

            // Idempotency: skip if asset_tag already exists
            $existing = db_one('SELECT id FROM assets WHERE asset_tag = ?', [$assetTag]);
            if ($existing) {
                $oldToNewAsset[$oldId] = (int)$existing['id'];
                $assetSkipped++;
                continue;
            }

            // Resolve location + status
            $status   = 'active';
            $locNewId = null;
            if ($archivedFlag) {
                $status = 'archived';
                $locNewId = isset($LOC_ID_MAP[$oldLocId]) ? $LOC_ID_MAP[$oldLocId] : $magdynId;
            } elseif ($oldLocId === 120) {
                $status   = 'with_vendor';
                $locNewId = null;
            } elseif ($oldLocId === 125) {
                $status   = 'with_user';
                $locNewId = null;
            } else {
                $status   = 'active';
                $locNewId = isset($LOC_ID_MAP[$oldLocId]) ? $LOC_ID_MAP[$oldLocId] : $magdynId;
            }

            db_exec(
                "INSERT INTO assets
                  (asset_tag, model_id, location_id, status, a_price, created_by)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [$assetTag, $newModelId, $locNewId, $status, $purchaseCost]
            );
            $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
            $oldToNewAsset[$oldId] = $newId;
            $assetInserted++;

            // Record for parent resolution (second pass)
            if ($oldParentId) {
                $parentPairs[] = [$newId, $oldParentId];
            }
        }
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        echo "<span style='color:red'>ERROR during asset migration: " . htmlspecialchars($e->getMessage()) . "</span>\n";
        echo "Transaction rolled back. No assets were inserted.\n</pre>";
        exit;
    }
} else {
    // Dry run
    foreach ($rawAssets as $r) {
        $oldId      = (int)$r[0];
        $oldParentId = isset($r[1]) ? (int)$r[1] : null;
        $assetTag   = isset($r[4]) ? trim((string)$r[4]) : '';
        if ($assetTag === '') { $assetSkipped++; continue; }
        $oldToNewAsset[$oldId] = 0;
        $assetInserted++;
        if ($oldParentId) $parentPairs[] = [0, $oldParentId];
    }
}

echo "  Inserted : $assetInserted\n";
echo "  Skipped  : $assetSkipped (already exist or model missing)\n";
if ($assetErrors) {
    echo "  Warnings :\n";
    foreach (array_slice($assetErrors, 0, 30) as $e) echo "    - $e\n";
    if (count($assetErrors) > 30) echo "    ... and " . (count($assetErrors) - 30) . " more.\n";
}
echo "\n";

// ================================================================
// STEP 3 — PARENT ASSET RESOLUTION (second pass)
// ================================================================
echo "--- STEP 3: Parent Asset Links ---\n";

$parentLinked = 0;
$parentMissed = 0;

if (!$DRY_RUN) {
    foreach ($parentPairs as [$newId, $oldParentId]) {
        $newParentId = $oldToNewAsset[$oldParentId] ?? null;
        if ($newParentId) {
            db_exec('UPDATE assets SET parent_asset_id = ? WHERE id = ?', [$newParentId, $newId]);
            $parentLinked++;
        } else {
            $parentMissed++;
        }
    }
} else {
    foreach ($parentPairs as [$newId, $oldParentId]) {
        if (isset($oldToNewAsset[$oldParentId])) $parentLinked++;
        else $parentMissed++;
    }
}

echo "  Linked   : $parentLinked parent relationships resolved\n";
if ($parentMissed) echo "  Unresolved: $parentMissed (parent was skipped or not in dump)\n";
echo "\n";

// ================================================================
// SUMMARY
// ================================================================
echo "=== SUMMARY ===\n";
if ($DRY_RUN) {
    echo "DRY RUN complete — no database changes made.\n";
    echo "Would insert: $modelInserted models, $assetInserted assets, link $parentLinked parents.\n\n";
    echo "<a href='?confirm=1' style='color:blue;font-weight:bold'>Click here to execute the migration (LIVE)</a>\n";
} else {
    echo "Migration complete.\n";
    echo "Models inserted : $modelInserted  |  skipped: $modelSkipped\n";
    echo "Assets inserted : $assetInserted  |  skipped: $assetSkipped\n";
    echo "Parent links    : $parentLinked resolved\n\n";
    echo "IMPORTANT: Delete this file now that migration is complete.\n";
    echo "  Path: " . __FILE__ . "\n";
}
echo "</pre>";
