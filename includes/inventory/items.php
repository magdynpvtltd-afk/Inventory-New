<?php
/**
 * MagDyn — Inventory: Items (CRUD, import, clone, list, new/edit)
 * Extracted Stage 1: 20260517_223400_IST
 *
 * Item register operations: list, create, edit, save, delete, clone, and CSV import. Helper inv_id_generate lives here.
 *
 * PARTIAL — not a standalone page. Routed by inventory.php (the
 * dispatcher). Variables already in scope from the dispatcher:
 *   $action, $canViewItems, $canCreateItems, $canManageItems,
 *   $canDeleteItems, $canViewBoms, $canCreateBoms, $canManageBoms,
 *   $canDeleteBoms.
 */

// ============================================================
// inv_id_generate — next inventory code
// ============================================================
/**
 * Generate the next Inventory Code.
 *
 * Delegates to the admin-managed code_sequences row named 'inv_item'
 * via code_next() — the SAME source the Code Sequences admin page edits.
 * Changing the prefix/pad there (e.g. I- to P-) takes effect everywhere
 * inventory codes are minted: manual item creation, clone, and the XML
 * importer.
 *
 * Falls back to the legacy $APP['inv_id'] prefix scan only if code_next()
 * is unavailable (pre-code_sequences installs).
 */
function inv_id_generate() {
    if (function_exists('code_next')) {
        $code = code_next('inv_item');
        if (is_string($code) && $code !== '') return $code;
    }
    // ---- Legacy fallback ----
    $cfg = $GLOBALS['APP']['inv_id'] ?? ['prefix' => 'I-', 'pad' => 5, 'start' => 1];
    $prefix = (string)$cfg['prefix'];
    $pad    = (int)$cfg['pad'];
    $start  = (int)$cfg['start'];

    $rows = db_all(
        "SELECT code FROM inv_items WHERE code LIKE ? ORDER BY id DESC LIMIT 50",
        [$prefix . '%']
    );
    $max = $start - 1;
    foreach ($rows as $r) {
        $suffix = substr($r['code'], strlen($prefix));
        if (ctype_digit($suffix)) {
            $n = (int)$suffix;
            if ($n > $max) $max = $n;
        }
    }
    $next = $max + 1;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = $prefix . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
        $clash = db_one('SELECT id FROM inv_items WHERE code = ?', [$candidate]);
        if (!$clash) return $candidate;
        $next++;
    }
    return $prefix . date('YmdHis');
}

// ============================================================
// POST handlers (item / bom-line actions)
// ============================================================
// ============================================================
// ITEM IMPORT — two-step (preview + commit)
// ============================================================
// CSV columns (case-insensitive):
//   code               — auto-generated INV-NNN if blank (lookup key on upsert)
//   short_description *  required
//   long_description
//   category_code *    required, matches categories.code WHERE type='inventory'
//   division_code *    required, matches categories.code WHERE type='division'
//   uom_code *         required, matches inv_uom.code
//   manufacturer_type  internal | external (default internal)
//   dwg_no, dwg_rev_no, part_no, part_rev_no
//   process_spec, process_step_code (matches inv_process_steps.code), step_no
//   step_time_min, step_cost (numeric)
//   min_stock_level, min_order_qty, min_sample_qty, min_sample_pct (numeric)
//   material_spec, remarks, notes
//   is_active          0/1, default 1
//
// Skipped on import (set later via edit form):
//   vendor_ids (M2M), cert_type_ids (M2M), stock_on_hand
require_once dirname(__DIR__, 2) . '/includes/_import.php';
require_once dirname(__DIR__, 2) . '/includes/_billing_products.php';

/**
 * Robust "is this category a Finished Good?" test. Matches by NAME
 * (case-insensitive, starts with "finished good") or a couple of known
 * codes — NOT by a specific id — so it survives category id/code drift
 * when categories are re-imported from CSV. This drives the per-item
 * is_product flag, which is what makes an item a TOP ELEMENT in the BOM tree.
 */
function inv_is_finished_good_category($catId) {
    $catId = (int)$catId;
    if ($catId <= 0) return false;
    $row = db_one('SELECT name, code FROM categories WHERE id = ?', [$catId]);
    if (!$row) return false;
    $name = strtolower(trim((string)$row['name']));
    return (strpos($name, 'finished good') === 0)
        || in_array((string)$row['code'], ['finshd', 'FINISHED_GOO'], true);
}

function item_import_adapter(array $row, bool $upsert) {
    $code = isset($row['code']) ? trim((string)$row['code']) : '';
    $sd   = isset($row['short_description']) ? trim((string)$row['short_description']) : '';
    if ($sd === '') {
        return ['status' => 'error', 'reason' => 'short_description is required'];
    }

    // --- Required FK: category (type='inventory') ---
    $catCode = isset($row['category_code']) ? trim((string)$row['category_code']) : '';
    if ($catCode === '') {
        return ['status' => 'error', 'reason' => 'category_code is required'];
    }
    $c = db_one("SELECT id FROM categories WHERE type IN ('inventory', 'all') AND code = ? ORDER BY FIELD(type, 'inventory', 'all') LIMIT 1", [$catCode]);
    if (!$c) {
        return ['status' => 'error',
                'reason' => 'Unknown category_code "' . $catCode . '" (must be a categories row with type=inventory or type=all)'];
    }
    $categoryId = (int)$c['id'];

    // --- Required FK: division (a categories row with type='division') ---
    $divCode = isset($row['division_code']) ? trim((string)$row['division_code']) : '';
    if ($divCode === '') {
        return ['status' => 'error', 'reason' => 'division_code is required'];
    }
    $d = db_one("SELECT id FROM categories WHERE type = 'division' AND code = ?", [$divCode]);
    if (!$d) {
        return ['status' => 'error',
                'reason' => 'Unknown division_code "' . $divCode . '" (must be a categories row with type=division)'];
    }
    $divisionId = (int)$d['id'];

    // --- Required FK: uom ---
    $uomCode = isset($row['uom_code']) ? trim((string)$row['uom_code']) : '';
    if ($uomCode === '') {
        return ['status' => 'error', 'reason' => 'uom_code is required'];
    }
    $u = db_one('SELECT id FROM inv_uom WHERE code = ?', [$uomCode]);
    if (!$u) {
        return ['status' => 'error',
                'reason' => 'Unknown uom_code "' . $uomCode . '"'];
    }
    $uomId = (int)$u['id'];

    // --- Optional FK: process_step ---
    $procStepId = null;
    $stepCode = isset($row['process_step_code']) ? trim((string)$row['process_step_code']) : '';
    if ($stepCode !== '') {
        $ps = db_one('SELECT id FROM inv_process_steps WHERE code = ?', [$stepCode]);
        if (!$ps) {
            return ['status' => 'error',
                    'reason' => 'Unknown process_step_code "' . $stepCode . '"'];
        }
        $procStepId = (int)$ps['id'];
    }

    // --- Manufacturer type enum ---
    $mfrType = strtolower(trim((string)($row['manufacturer_type'] ?? 'internal')));
    if ($mfrType !== 'external') $mfrType = 'internal';

    // --- Numeric helpers: empty allowed, non-numeric is an error ---
    $numOpt = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return ['ok' => true, 'value' => null];
        if (!is_numeric($v)) return ['ok' => false];
        return ['ok' => true, 'value' => (float)$v];
    };
    foreach (['step_time_min','step_cost','min_stock_level','min_order_qty'] as $f) {
        $r = $numOpt($row[$f] ?? '');
        if (!$r['ok']) return ['status' => 'error', 'reason' => $f . ' must be numeric'];
        $$f = $r['value'];
    }
    // min_sample_qty / min_sample_pct: non-null default 0; pct bounded
    $minSampleQty = trim((string)($row['min_sample_qty'] ?? ''));
    $minSampleQty = $minSampleQty === '' ? 0 : (is_numeric($minSampleQty) ? (float)$minSampleQty : null);
    if ($minSampleQty === null) {
        return ['status' => 'error', 'reason' => 'min_sample_qty must be numeric'];
    }
    $minSamplePct = trim((string)($row['min_sample_pct'] ?? ''));
    $minSamplePct = $minSamplePct === '' ? 0 : (is_numeric($minSamplePct) ? (float)$minSamplePct : null);
    if ($minSamplePct === null) {
        return ['status' => 'error', 'reason' => 'min_sample_pct must be numeric'];
    }
    if ($minSamplePct < 0 || $minSamplePct > 100) {
        return ['status' => 'error', 'reason' => 'min_sample_pct must be 0–100'];
    }

    // --- is_active default 1 ---
    $isActive = (isset($row['is_active']) && $row['is_active'] !== '')
              ? ((int)$row['is_active'] ? 1 : 0)
              : 1;

    $clean = [
        'code'              => $code,
        'name'              => $sd,             // legacy NOT NULL column mirrors short_description
        'short_description' => $sd,
        'long_description'  => trim((string)($row['long_description'] ?? '')) ?: null,
        'category_id'       => $categoryId,
        'category_code'     => $catCode,        // for preview display
        'is_product'        => inv_is_finished_good_category($categoryId) ? 1 : 0,
        'division_id'       => $divisionId,
        'division_code'     => $divCode,
        'uom_id'            => $uomId,
        'uom_code'          => $uomCode,
        'manufacturer_type' => $mfrType,
        'dwg_no'            => trim((string)($row['dwg_no'] ?? '')) ?: null,
        'dwg_rev_no'        => trim((string)($row['dwg_rev_no'] ?? '')) ?: null,
        'part_no'           => trim((string)($row['part_no'] ?? '')) ?: null,
        'part_rev_no'       => trim((string)($row['part_rev_no'] ?? '')) ?: null,
        'process_spec'      => trim((string)($row['process_spec'] ?? '')) ?: null,
        'process_step_id'   => $procStepId,
        'step_no'           => trim((string)($row['step_no'] ?? '')) ?: null,
        'step_time_min'     => $step_time_min,
        'step_cost'         => $step_cost,
        'min_stock_level'   => $min_stock_level,
        'min_order_qty'     => $min_order_qty,
        'min_sample_qty'    => $minSampleQty,
        'min_sample_pct'    => $minSamplePct,
        'material_spec'     => trim((string)($row['material_spec'] ?? '')) ?: null,
        'remarks'           => trim((string)($row['remarks'] ?? '')) ?: null,
        'notes'             => trim((string)($row['notes'] ?? '')) ?: null,
        'is_active'         => $isActive,
    ];

    // Look up existing by code if provided
    if ($code !== '') {
        $e = db_one('SELECT id FROM inv_items WHERE code = ?', [$code]);
        if ($e) {
            if (!$upsert) {
                return ['status' => 'skip',
                        'reason' => 'code "' . $code . '" already exists (upsert is off)',
                        'data'   => $clean];
            }
            return ['status' => 'update', 'data' => $clean, 'existing_id' => (int)$e['id']];
        }
    }
    return ['status' => 'insert', 'data' => $clean];
}

function item_import_committer(array $previewRow) {
    $d = $previewRow['data'];
    if ($previewRow['status'] === 'update') {
        $id = (int)$previewRow['existing_id'];
        // Capture the OLD is_active so we can detect a 1 → 0 transition
        // after the UPDATE and fire the deactivate hook accordingly.
        $oldIsActive = (int)db_val("SELECT is_active FROM inv_items WHERE id = ?", [$id], 1);
        db_exec(
            'UPDATE inv_items SET
                name=?, short_description=?, long_description=?,
                category_id=?, division_id=?, uom_id=?, manufacturer_type=?,
                dwg_no=?, dwg_rev_no=?, part_no=?, part_rev_no=?,
                process_spec=?, process_step_id=?, step_no=?,
                step_time_min=?, step_cost=?, min_stock_level=?, min_order_qty=?,
                min_sample_qty=?, min_sample_pct=?,
                material_spec=?, remarks=?, notes=?, is_active=?,
                is_product = GREATEST(is_product, ?)
             WHERE id = ?',
            [$d['name'], $d['short_description'], $d['long_description'],
             $d['category_id'], $d['division_id'], $d['uom_id'], $d['manufacturer_type'],
             $d['dwg_no'], $d['dwg_rev_no'], $d['part_no'], $d['part_rev_no'],
             $d['process_spec'], $d['process_step_id'], $d['step_no'],
             $d['step_time_min'], $d['step_cost'], $d['min_stock_level'], $d['min_order_qty'],
             $d['min_sample_qty'], $d['min_sample_pct'],
             $d['material_spec'], $d['remarks'], $d['notes'], $d['is_active'],
             (int)($d['is_product'] ?? 0),
             $id]
        );
        // Order matters: deactivate FIRST (so we don't push fresh state
        // for an item that's also going inactive on the same import row),
        // then push_if_needed to mirror any other field changes.
        billing_product_deactivate_if_needed($id, $oldIsActive, current_user_id());
        billing_product_push_if_needed($id, current_user_id());
        return $id;
    }
    // Insert — auto-gen code if blank
    $codeForInsert = $d['code'] !== '' ? $d['code'] : inv_id_generate();
    db_exec(
        'INSERT INTO inv_items (
            code, name, short_description, long_description,
            category_id, division_id, uom_id, manufacturer_type,
            dwg_no, dwg_rev_no, part_no, part_rev_no,
            process_spec, process_step_id, step_no,
            step_time_min, step_cost, min_stock_level, min_order_qty,
            min_sample_qty, min_sample_pct,
            material_spec, remarks, notes, is_active, is_product
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
         )',
        [$codeForInsert, $d['name'], $d['short_description'], $d['long_description'],
         $d['category_id'], $d['division_id'], $d['uom_id'], $d['manufacturer_type'],
         $d['dwg_no'], $d['dwg_rev_no'], $d['part_no'], $d['part_rev_no'],
         $d['process_spec'], $d['process_step_id'], $d['step_no'],
         $d['step_time_min'], $d['step_cost'], $d['min_stock_level'], $d['min_order_qty'],
         $d['min_sample_qty'], $d['min_sample_pct'],
         $d['material_spec'], $d['remarks'], $d['notes'], $d['is_active'], (int)($d['is_product'] ?? 0)]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
    billing_product_push_if_needed($newId, current_user_id());
    return $newId;
}

if ($action === 'item_import_preview') {
    if (!$canCreateItems && !$canManageItems) {
        require_permission('inventory_view_items', 'create');
    }
    csrf_check();
    $upsert = !empty($_POST['upsert']);
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/inventory.php?action=items'));
    }
    $token  = import_stash($parsed['csv_text'], 'inv_items');
    $result = import_run_adapter($parsed['rows'], 'item_import_adapter', $upsert);
    $page_title  = 'Import inventory items · preview';
    $page_module = 'inventory_view_items';
    require dirname(__DIR__, 2) . '/includes/header.php';
    import_render_preview([
        'title'      => 'Import inventory items · preview',
        'commit_url' => url('/inventory.php?action=item_import_commit'),
        'cancel_url' => url('/inventory.php?action=items'),
        'token'      => $token,
        'upsert'     => $upsert,
        'counts'     => $result['counts'],
        'rows'       => $result['rows'],
        'columns'    => [
            ['code',              'Code'],
            ['short_description', 'Short description'],
            ['category_code',     'Category'],
            ['division_code',     'Division'],
            ['uom_code',          'UoM'],
            ['manufacturer_type', 'Mfr'],
            ['part_no',           'Part #'],
        ],
    ]);
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

if ($action === 'item_import_commit') {
    if (!$canCreateItems && !$canManageItems) {
        require_permission('inventory_view_items', 'create');
    }
    csrf_check();
    $token  = (string)input('token', '');
    $upsert = !empty($_POST['upsert']);
    $csv = import_unstash($token, 'inv_items');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/inventory.php?action=items'));
    }
    $res = import_run_commit($csv, 'item_import_adapter', $upsert, 'item_import_committer');
    if (empty($res['ok'])) {
        flash_set('error', 'Import failed: ' . ($res['error'] ?? 'unknown'));
    } else {
        $msg = 'Imported ' . (int)$res['inserted'] . ' new item'
             . ($res['inserted'] === 1 ? '' : 's')
             . ', updated ' . (int)$res['updated']
             . '.' . ($res['errors'] > 0 ? ' ' . (int)$res['errors'] . ' rows failed (see server log).' : '');
        flash_set('success', $msg);
    }
    redirect(url('/inventory.php?action=items'));
}

if ($action === 'item_save') {
    csrf_check();
    if (!$canCreateItems && !$canManageItems) {
        flash_set('error', 'You do not have permission to save items.');
        redirect(url('/inventory.php?action=items'));
    }
    $id = (int)input('id', 0);

    // Code is system-controlled — auto-generated on create, immutable on edit.
    // The form may submit a value but we ignore it.
    if ($id) {
        $existing = db_one('SELECT code FROM inv_items WHERE id = ?', [$id]);
        $code = $existing ? $existing['code'] : '';
    } else {
        $code = inv_id_generate();
    }

    // `name` is the legacy column required NOT NULL. We mirror
    // short_description into it so old queries keep working.
    $shortDesc = trim((string)input('short_description'));

    $data = [
        'code'                => $code,
        'name'                => $shortDesc,
        'short_description'   => $shortDesc,
        'long_description'    => trim((string)input('long_description')),
        'category_id'         => (int)input('category_id', 0) ?: null,
        'division_id'         => (int)input('division_id', 0) ?: null,
        'manufacturer_type'   => input('manufacturer_type') === 'external' ? 'external' : 'internal',
        'uom_id'              => (int)input('uom_id', 0) ?: null,
        'dwg_no'              => trim((string)input('dwg_no'))      ?: null,
        'dwg_rev_no'          => trim((string)input('dwg_rev_no'))  ?: null,
        'part_no'             => trim((string)input('part_no'))     ?: null,
        'part_rev_no'         => trim((string)input('part_rev_no')) ?: null,
        'ecn'                 => trim((string)input('ecn')) ?: null,
        'process_spec'        => trim((string)input('process_spec'))?: null,
        'process_step_id'     => (int)input('process_step_id', 0) ?: null,
        'step_no'             => trim((string)input('step_no')) ?: null,
        'step_time_min'       => input('step_time_min') === '' ? null : (float)input('step_time_min'),
        'step_cost'           => input('step_cost')     === '' ? null : (float)input('step_cost'),
        'min_stock_level'     => input('min_stock_level') === '' ? null : (float)input('min_stock_level'),
        'min_order_qty'       => input('min_order_qty')   === '' ? null : (float)input('min_order_qty'),
        'min_sample_qty'      => (float)input('min_sample_qty', 0),
        'min_sample_pct'      => (float)input('min_sample_pct', 0),
        'material_spec'       => trim((string)input('material_spec')) ?: null,
        'remarks'             => trim((string)input('remarks')) ?: null,
        'notes'               => trim((string)input('notes')) ?: null,
    ];

    $errors = [];
    if ($shortDesc === '')        $errors[] = 'Short Description is required.';
    if ($data['code'] === '')     $errors[] = 'Inventory Code could not be generated.';
    if (!$data['category_id'])    $errors[] = 'Category is required.';
    // I_Division is optional — items without a division still appear in the
    // BOM tree's "All" view (and just won't show under a specific division tab).
    if (!$data['uom_id'])         $errors[] = 'I_UOM is required.';
    // Manufacturer toggle is required; when external, at least one vendor must be picked.
    $vendorIds = array_filter(array_map('intval', (array)input('vendor_ids', [])));
    if ($data['manufacturer_type'] === 'external' && !$vendorIds) {
        $errors[] = 'When manufacturer is External, at least one vendor must be selected.';
    }
    if ($data['min_sample_pct'] < 0 || $data['min_sample_pct'] > 100) {
        $errors[] = 'Min sample percentage must be between 0 and 100.';
    }

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        // Preserve the user's input in flash so they don't have to retype.
        // (Skipped for brevity — the form re-renders blank on POST-fail; future enhancement.)
        redirect($id ? url('/inventory.php?action=item_edit&id=' . $id) : url('/inventory.php?action=item_new'));
    }

    $certIds = array_filter(array_map('intval', (array)input('cert_ids', [])));

    // Finished good → top element of a BOM tree. Selecting a "Finished Good"
    // category sets the stable is_product flag (the BOM tree reads this).
    $isFinishedGood = inv_is_finished_good_category($data['category_id']) ? 1 : 0;

    if ($id) {
        if (!$canManageItems) { flash_set('error', 'No permission to edit items.'); redirect(url('/inventory.php?action=items')); }
        db_exec(
            'UPDATE inv_items SET
                name = ?, short_description = ?, long_description = ?,
                category_id = ?, division_id = ?, manufacturer_type = ?,
                uom_id = ?, dwg_no = ?, dwg_rev_no = ?, part_no = ?, part_rev_no = ?, ecn = ?,
                process_spec = ?, process_step_id = ?, step_no = ?,
                step_time_min = ?, step_cost = ?,
                min_stock_level = ?, min_order_qty = ?,
                min_sample_qty = ?, min_sample_pct = ?,
                material_spec = ?, remarks = ?, notes = ?,
                is_product = GREATEST(is_product, ?)
              WHERE id = ?',
            [$data['name'], $data['short_description'], $data['long_description'],
             $data['category_id'], $data['division_id'], $data['manufacturer_type'],
             $data['uom_id'], $data['dwg_no'], $data['dwg_rev_no'], $data['part_no'], $data['part_rev_no'], $data['ecn'],
             $data['process_spec'], $data['process_step_id'], $data['step_no'],
             $data['step_time_min'], $data['step_cost'],
             $data['min_stock_level'], $data['min_order_qty'],
             $data['min_sample_qty'], $data['min_sample_pct'],
             $data['material_spec'], $data['remarks'], $data['notes'],
             $isFinishedGood,
             $id]
        );
        db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.item.update', ?, ?)",
            [current_user_id(), $id, $data['code']]);
        flash_set('success', 'Item updated.');
    } else {
        try {
            db_exec(
                'INSERT INTO inv_items (
                    code, name, short_description, long_description,
                    category_id, division_id, manufacturer_type,
                    uom_id, dwg_no, dwg_rev_no, part_no, part_rev_no, ecn,
                    process_spec, process_step_id, step_no,
                    step_time_min, step_cost,
                    min_stock_level, min_order_qty,
                    min_sample_qty, min_sample_pct,
                    material_spec, remarks, notes,
                    is_product, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$data['code'], $data['name'], $data['short_description'], $data['long_description'],
                 $data['category_id'], $data['division_id'], $data['manufacturer_type'],
                 $data['uom_id'], $data['dwg_no'], $data['dwg_rev_no'], $data['part_no'], $data['part_rev_no'], $data['ecn'],
                 $data['process_spec'], $data['process_step_id'], $data['step_no'],
                 $data['step_time_min'], $data['step_cost'],
                 $data['min_stock_level'], $data['min_order_qty'],
                 $data['min_sample_qty'], $data['min_sample_pct'],
                 $data['material_spec'], $data['remarks'], $data['notes'],
                 $isFinishedGood]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                flash_set('error', 'An item with Part No "' . $data['part_no'] . '" and Rev "' . $data['part_rev_no'] . '" already exists. Please use a different Part No / Rev combination.');
            } else {
                flash_set('error', 'Could not create item: ' . $e->getMessage());
            }
            redirect(url('/inventory.php?action=item_new'));
        }
        $id = (int)db()->lastInsertId();
        db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.item.create', ?, ?)",
            [current_user_id(), $id, $data['code']]);
        flash_set('success', 'Item created: ' . $data['code']);
    }

    // ---- Sync the vendor + cert junction tables ----
    // Simplest: wipe and reinsert. Tables are small, no FK fan-out.
    db_exec('DELETE FROM inv_item_vendors WHERE item_id = ?', [$id]);
    if ($data['manufacturer_type'] === 'external') {
        $order = 0;
        foreach ($vendorIds as $vid) {
            db_exec('INSERT INTO inv_item_vendors (item_id, vendor_id, sort_order) VALUES (?, ?, ?)',
                [$id, $vid, ++$order * 10]);
        }
    }
    db_exec('DELETE FROM inv_item_certs WHERE item_id = ?', [$id]);
    foreach ($certIds as $cid) {
        db_exec('INSERT INTO inv_item_certs (item_id, cert_id) VALUES (?, ?)', [$id, $cid]);
    }

    // ---- Mirror to billing if this is a finished good ----
    // Fire-and-forget within the request: the helper handles failures
    // internally and logs them; we don't surface a flash error here
    // because the local save succeeded, which is what the operator
    // cares about. The view page's push-history panel surfaces failures.
    billing_product_push_if_needed($id, current_user_id());

    redirect(url('/inventory.php?action=item_edit&id=' . $id));
}

// ============================================================
// ITEM_BILLING_PUSH — operator-initiated "Push to billing" button
// from the item edit view. Forces a push (clears the hash so the
// helper doesn't short-circuit on "no mirrored field changed").
// Used as a retry after a previous failure, or to refresh billing's
// copy after a config / external change.
// ============================================================
if ($action === 'item_billing_push') {
    csrf_check();
    require_permission('inventory_view_items', 'push_to_billing');
    $id = (int)input('id', 0);
    try {
        $r = billing_product_push_force($id, current_user_id());
        if (!empty($r['result']['ok'])) {
            flash_set('success', 'Pushed to billing successfully.');
        } elseif (!empty($r['result'])) {
            flash_set('error', sprintf(
                'Push failed (HTTP %d / %s): %s',
                (int)$r['result']['http'],
                (string)($r['result']['error_code'] ?? 'unknown'),
                (string)($r['result']['error'] ?? 'no detail')
            ));
        } else {
            flash_set('info', 'Push skipped: ' . ($r['reason'] ?? 'unknown reason'));
        }
    } catch (\Throwable $e) {
        flash_set('error', 'Could not push to billing: ' . $e->getMessage());
    }
    redirect(url('/inventory.php?action=item_edit&id=' . $id));
}

if ($action === 'item_delete') {
    csrf_check();
    if (!$canDeleteItems) {
        flash_set('error', 'No permission to delete items.');
        redirect(url('/inventory.php?action=items'));
    }
    $id = (int)input('id', 0);
    // Block delete if the item is referenced as a parent OR child anywhere.
    $usedAs = db_val('SELECT COUNT(*) FROM inv_bom_lines WHERE parent_item_id = ? OR child_item_id = ?', [$id, $id], 0);
    if ($usedAs) {
        flash_set('error', 'Cannot delete: item is referenced in a BOM. Remove the references first.');
        redirect(url('/inventory.php?action=item_edit&id=' . $id));
    }
    $txnCount = db_val('SELECT COUNT(*) FROM inv_txns WHERE item_id = ?', [$id], 0);
    if ($txnCount) {
        flash_set('error', 'Cannot delete: item has ' . $txnCount . ' transaction record(s). Delete or reassign the transactions first.');
        redirect(url('/inventory.php?action=item_edit&id=' . $id));
    }
    db_exec('DELETE FROM inv_items WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.item.delete', ?, ?)", [current_user_id(), $id, '']);
    flash_set('success', 'Item deleted.');
    redirect(url('/inventory.php?action=items'));
}

// ============================================================
// ITEM TOGGLE ACTIVE
// ============================================================
if ($action === 'item_toggle_active') {
    csrf_check();
    if (!$canManageItems) {
        flash_set('error', 'No permission to edit items.');
        redirect(url('/inventory.php?action=items'));
    }
    $id = (int)input('id', 0);
    $item = db_one('SELECT id, short_description, is_active FROM inv_items WHERE id = ?', [$id]);
    if (!$item) { flash_set('error', 'Item not found.'); redirect(url('/inventory.php?action=items')); }
    $newActive = $item['is_active'] ? 0 : 1;
    db_exec('UPDATE inv_items SET is_active = ? WHERE id = ?', [$newActive, $id]);
    flash_set('success', 'Item "' . $item['short_description'] . '" marked ' . ($newActive ? 'active' : 'inactive') . '.');
    redirect(url('/inventory.php?action=item_edit&id=' . $id));
}

// ITEM CLONE — duplicate an item header + its vendor/cert children
// ============================================================
// We copy:
//   - inv_items row (new unique code, name prefixed "Copy of", stock
//     counters reset to 0, is_active=1)
//   - inv_item_vendors associations
//   - inv_item_certs associations
// We do NOT copy:
//   - inv_item_location_stock (per-instance state, not catalogue)
//   - inv_txns (transaction history)
//   - inv_bom_lines (handled by the bom_clone action below; cloning
//     an item via item_clone leaves the original's BOM intact and
//     the clone gets a fresh empty one)
if ($action === 'item_clone') {
    csrf_check();
    if (!$canCreateItems) {
        flash_set('error', 'No permission to create items.');
        redirect(url('/inventory.php?action=items'));
    }
    $id = (int)input('id', 0);
    $src = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=items'));
    }

    $newCode = clone_unique_code('inv_items', 'code', $src['code']);
    $newName = 'Copy of ' . $src['name'];

    // clone_row reads the column list at runtime, so we don't hardcode
    // names. Override code/name and reset stock counters; let timestamps
    // default-fill (DEFAULT CURRENT_TIMESTAMP / ON UPDATE).
    $newId = clone_row('inv_items', $id, [
        'code'              => $newCode,
        'name'              => $newName,
        'is_active'         => 1,
        'stock_on_hand'     => 0,
        'stock_rejected'    => 0,
        'stock_on_order'    => 0,
        'follow_up_note'    => null,
    ], ['created_at', 'updated_at']);

    if ($newId <= 0) {
        flash_set('error', 'Clone failed.');
        redirect(url('/inventory.php?action=item_edit&id=' . $id));
    }

    // Copy vendor + cert associations. Using INSERT-SELECT here rather
    // than clone_row because we're copying MANY child rows, not one.
    db_exec(
        'INSERT INTO inv_item_vendors (item_id, vendor_id, sort_order)
         SELECT ?, vendor_id, sort_order
           FROM inv_item_vendors WHERE item_id = ?',
        [$newId, $id]
    );
    db_exec(
        'INSERT INTO inv_item_certs (item_id, cert_id)
         SELECT ?, cert_id
           FROM inv_item_certs WHERE item_id = ?',
        [$newId, $id]
    );

    db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.item.clone', ?, ?)",
        [current_user_id(), $newId, 'cloned from ' . $src['code']]);
    flash_set('success', 'Cloned to "' . $newCode . '" — review and save.');
    redirect(url('/inventory.php?action=item_edit&id=' . $newId));
}


// ============================================================
// ITEMS list (datatable)
// ============================================================
if ($action === 'items') {
    if (!$canViewItems) require_permission('inventory_view_items', 'view');

    // ----------------------------------------------------------------
    // Per-location breakdown. The list shows ONE ROW PER (item, location)
    // pair so the operator can see at a glance where stock is sitting
    // without drilling into each item's ledger. Items with no stock
    // anywhere still get a single row (LEFT JOIN keeps them in the
    // result set with NULL location columns); this preserves the
    // page's role as the primary item-navigation surface.
    //
    // Sort defaults are by item code so an item's locations stay
    // grouped together; the user can re-sort by Stock, Location, etc.
    // ----------------------------------------------------------------
    $dtCfg = [
        'id'       => 'inv_items',
        'base_sql' => 'SELECT i.id, i.code, i.name, i.short_description, i.unit_cost,
                              i.manufacturer_type, i.is_active, i.uom_id, i.category_id,
                              i.stock_on_hand,
                              u.label AS uom_label,
                              c.name  AS category_name,
                              s.location_id AS s_location_id,
                              s.qty         AS loc_qty,
                              l.code AS location_code,
                              l.name AS location_name,
                              (SELECT COUNT(*) FROM inv_bom_lines bl WHERE bl.parent_item_id = i.id) AS child_count
                         FROM inv_items i
                         LEFT JOIN inv_uom u    ON u.id = i.uom_id
                         LEFT JOIN categories c ON c.id = i.category_id
                         LEFT JOIN inv_item_location_stock s ON s.item_id = i.id
                         LEFT JOIN locations l  ON l.id = s.location_id',
        'columns'  => [
            ['key'=>'short_description', 'label'=>'Short Description', 'sortable'=>true, 'searchable'=>true,
             // Searchable on both code and description so the
             // displayed "(CODE)-Name" matches either fragment. The
             // separate Inv Id column was dropped — its content is
             // included in the prefix here and the cell is also the
             // link to item_edit.
             'sql_col'=>"CONCAT('(', i.code, ')-', COALESCE(NULLIF(i.short_description, ''), i.name))"],
            ['key'=>'category_name', 'label'=>'Category', 'sortable'=>true, 'searchable'=>true, 'sql_col'=>'c.name'],
            ['key'=>'uom_label',     'label'=>'UoM',      'sortable'=>true, 'searchable'=>true, 'sql_col'=>'u.label'],
            ['key'=>'location_code', 'label'=>'Location', 'sortable'=>true, 'searchable'=>true, 'sql_col'=>'l.code'],
            ['key'=>'loc_qty',       'label'=>'Stock',    'sortable'=>true, 'searchable'=>false,'sql_col'=>'s.qty', 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'manufacturer_type', 'label'=>'Mfr', 'sortable'=>true, 'sql_col'=>'i.manufacturer_type',
             'filter' => [
                 'type' => 'select',
                 'placeholder' => 'all',
                 'options' => [
                     ['value' => 'internal', 'label' => 'Internal'],
                     ['value' => 'external', 'label' => 'External'],
                 ],
             ]],
            ['key'=>'child_count',   'label'=>'BOM lines','sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'is_active',     'label'=>'Status',   'sortable'=>true, 'sql_col'=>'i.is_active',
             'filter' => [
                 'type' => 'select',
                 'placeholder' => 'all',
                 'options' => [
                     ['value' => '1', 'label' => 'Active'],
                     ['value' => '0', 'label' => 'Inactive'],
                 ],
             ]],
            ['key'=>'_actions',      'label'=>'Actions',  'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        // Sort by item code then by location so an item's location
        // rows stay together. The second key applies because
        // data_table_query passes the default sort through to ORDER BY.
        'default_sort' => ['code', 'asc'],
    ];

    $canCreateInspection = permission_check('inspection', 'create');

    $canCreateEcn = function_exists('permission_check') && permission_check('ecn', 'create');
    $rowRenderer = function ($i) use ($canCreateItems, $canManageItems, $canDeleteItems, $canCreateInspection, $canCreateEcn) {
        // The Short Description cell carries the "(CODE)-Name" prefix
        // AND the link to item_edit — we used to have a separate Inv
        // Id column for that link, but the code is now redundant with
        // the prefix shown here, so the column was dropped.
        $itemLabel = '<strong><a href="' . h(url('/inventory.php?action=item_edit&id=' . (int)$i['id'])) . '">'
                   . '(' . h($i['code']) . ')-' . h($i['short_description'] ?: $i['name'])
                   . '</a></strong>';
        $mfr = $i['manufacturer_type'] === 'external'
            ? '<span class="pill pill-info">external</span>'
            : '<span class="pill pill-neutral">internal</span>';
        $status = $i['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">inactive</span>';
        $childLink = (int)$i['child_count'] > 0
            ? '<a href="' . h(url('/inventory.php?action=bom_view&id=' . (int)$i['id'])) . '">' . (int)$i['child_count'] . '</a>'
            : '<span class="muted">0</span>';

        // Location cell — the location code with the human-readable
        // name as a tooltip. Items with no stock anywhere render '—'.
        if (!empty($i['location_code'])) {
            $locCell = '<code title="' . h($i['location_name'] ?: '') . '">' . h($i['location_code']) . '</code>';
        } else {
            $locCell = '<span class="muted small">no stock</span>';
        }

        // Stock cell — per-location qty. Trim trailing zeros so it
        // doesn't read "12.000" everywhere.
        if ($i['loc_qty'] !== null) {
            $q = (float)$i['loc_qty'];
            $qStr = rtrim(rtrim(number_format($q, 3), '0'), '.');
            $stockCell = $qStr !== '' ? $qStr : '0';
        } else {
            $stockCell = '<span class="muted">0</span>';
        }

        // Action buttons — icon + label, styled as dropdown menu items
        // by the .dt-actions-dropdown CSS once wrapped via dt_actions_wrap.
        $actions  = '<a class="btn btn-icon" href="' . h(url('/inventory.php?action=item_edit&id=' . (int)$i['id']))
                  . '" title="Edit item" aria-label="Edit item">✎ <span class="dt-action-label">Edit item</span></a> ';
        $actions .= '<a class="btn btn-icon" href="' . h(url('/inventory.php?action=ledger&id=' . (int)$i['id']))
                  . '" title="Ledger / stock history" aria-label="Ledger">📒 <span class="dt-action-label">Ledger</span></a> ';
        if ($canManageItems) {
            // Move pre-fills source location with the row's own
            // location when stock exists, so the operator doesn't
            // have to re-pick it.
            $moveUrl = url('/inventory.php?action=move&item_id=' . (int)$i['id']
                . (!empty($i['s_location_id']) ? '&src_location_id=' . (int)$i['s_location_id'] : ''));
            $actions .= '<a class="btn btn-icon" href="' . h($moveUrl)
                      . '" title="Move stock between locations" aria-label="Move">⇄ <span class="dt-action-label">Move</span></a> ';
        }
        // BOM designer is available for every item. When the item has no
        // children yet we surface a distinct accented "+ Create BOM" icon
        // so the empty-state affordance is obvious; when it has children
        // we show the standard designer icon.
        if ((int)$i['child_count'] > 0) {
            $actions .= '<a class="btn btn-icon" href="' . h(url('/inventory.php?action=bom_designer&id=' . (int)$i['id']))
                      . '" title="BOM designer" aria-label="BOM designer">🛠 <span class="dt-action-label">BOM designer</span></a>';
        } else {
            $actions .= '<a class="btn btn-icon btn-icon-accent" href="' . h(url('/inventory.php?action=bom_designer&id=' . (int)$i['id']))
                      . '" title="Create BOM" aria-label="Create BOM">＋🛠 <span class="dt-action-label">Create BOM</span></a>';
        }
        // Clone: duplicates the item header + vendor/cert associations.
        // POST (mutating) so wrapped in a form with CSRF + confirm.
        if ($canCreateItems) {
            $actions .= ' <form method="post" style="display:inline;"'
                     . ' action="' . h(url('/inventory.php?action=item_clone')) . '"'
                     . ' onsubmit="return confirm(\'Clone ' . h(addslashes($i['code']))
                     . ' to a new item? Stock counts will reset to 0.\');">'
                     . csrf_field()
                     . '<input type="hidden" name="id" value="' . (int)$i['id'] . '">'
                     . '<button type="submit" class="btn btn-icon" title="Clone item"'
                     . ' aria-label="Clone item">⎘ <span class="dt-action-label">Clone</span></button>'
                     . '</form>';
        }
        $actions .= notes_popup_menu_item('inv_item', (int)$i['id']);
        // Create an inspection template pre-linked to this inventory
        // item — useful for incoming-material checks on receipts of
        // this SKU.
        if ($canCreateInspection) {
            $actions .= ' <a class="btn btn-icon"'
                     . ' href="' . h(url('/inspection.php?action=template_new&target_entity_type=inv_item&target_entity_id=' . (int)$i['id'])) . '"'
                     . ' title="Create an inspection template linked to this item" aria-label="Add inspection template">'
                     . '📋 <span class="dt-action-label">+ Inspection template</span></a>';
        }
        // Raise ECN — start a new Engineering Change Notice with this
        // item pre-linked as an affected item. Defaults to drawing_rev
        // (the most common single-item change); the operator can switch
        // type on the form. Mirrors the document page's "Raise revision
        // ECN" button, but from the inventory side.
        if ($canCreateEcn) {
            $actions .= ' <a class="btn btn-icon"'
                     . ' href="' . h(url('/ecn.php?action=new&ecn_type=item_change&item_id=' . (int)$i['id'])) . '"'
                     . ' title="Raise an item-change ECN for this item" aria-label="Raise ECN">'
                     . '🧾 <span class="dt-action-label">Raise ECN</span></a>';
        }
        return [
            'short_description' => $itemLabel,
            'category_name'     => h($i['category_name'] ?: '—'),
            'uom_label'         => h($i['uom_label'] ?: '—'),
            'location_code'     => $locCell,
            'loc_qty'           => $stockCell,
            'manufacturer_type' => $mfr,
            'child_count'       => $childLink,
            'is_active'         => $status,
            '_actions'          => dt_actions_wrap($actions),
        ];
    };
    $dt = data_table_run($dtCfg, $rowRenderer);

    $page_title  = 'Inventory items';
    $page_module = 'inventory';
    $focus_id    = '';

    // Title + action buttons are rendered inside the data-table toolbar
    // instead of a separate page-head. Build the actions HTML here so
    // it can be slotted into the toolbar via data_table_render config.
    $actionsHtml  = '<a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=boms')) . '"'
                  . ' data-shortcut="B" accesskey="b">' . shortcut_label('View BOMs', 'B') . '</a>';
    if ($canCreateItems) {
        $actionsHtml .= ' <button type="button" class="btn btn-ghost btn-sm"'
                      . ' data-open-import="item-import-modal"'
                      . ' title="Import inventory items from CSV">⤒ Import CSV</button>';
        $actionsHtml .= ' <a class="btn btn-primary btn-sm" href="' . h(url('/inventory.php?action=item_new')) . '"'
                      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New item', 'N') . '</a>';
    }
    $dtCfg['title']        = 'Inventory items';
    $dtCfg['actions_html'] = $actionsHtml;

    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    <?php notes_popup_assets(); ?>
    <?php if ($canCreateItems):
        import_modal_html(
            'item-import-modal',
            'Import inventory items from CSV',
            url('/inventory.php?action=item_import_preview'),
            'Required columns: <code>short_description</code>, <code>category_code</code> '
              . '(matches a categories row with type=inventory), '
              . '<code>division_code</code> (categories type=division), '
              . '<code>uom_code</code> (inv_uom code). '
              . 'Optional: <code>code</code> (auto-generated if blank), '
              . '<code>long_description</code>, <code>manufacturer_type</code> (internal/external), '
              . '<code>dwg_no</code>, <code>dwg_rev_no</code>, <code>part_no</code>, '
              . '<code>part_rev_no</code>, <code>process_spec</code>, '
              . '<code>process_step_code</code>, <code>step_no</code>, '
              . '<code>step_time_min</code>, <code>step_cost</code>, '
              . '<code>min_stock_level</code>, <code>min_order_qty</code>, '
              . '<code>min_sample_qty</code>, <code>min_sample_pct</code>, '
              . '<code>material_spec</code>, <code>remarks</code>, <code>notes</code>, '
              . '<code>is_active</code>. '
              . 'Vendor / cert links and stock-on-hand are not imported '
              . '(use the edit form / receipts).'
        );
    endif; ?>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}


// ============================================================
// ITEM new / edit (shared form)
// ============================================================
if ($action === 'item_new' || $action === 'item_edit') {
    $isEdit = $action === 'item_edit';
    $id = (int)input('id', 0);
    if ($isEdit) {
        require_once dirname(__DIR__, 2) . '/includes/_notes.php';
        if (notes_handle_action()) {
            redirect(url('/inventory.php?action=item_edit&id=' . (int)input('entity_id', 0)));
        }
    }
    $editing = null;
    if ($isEdit) {
        if (!$canManageItems) require_permission('inventory_view_items', 'manage');
        $editing = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
        if (!$editing) {
            flash_set('error', 'Item not found.');
            redirect(url('/inventory.php?action=items'));
        }
    } else {
        if (!$canCreateItems) require_permission('inventory_view_items', 'create');
    }

    // ---- Load dropdown sources ----
    $categories = db_all("SELECT id, name FROM categories WHERE type IN ('inventory', 'all') AND is_active = 1 ORDER BY sort_order, name");
    $divisions  = db_all("SELECT id, name FROM categories WHERE type = 'division'  AND is_active = 1 ORDER BY sort_order, name");
    $uoms       = db_all("SELECT id, label FROM inv_uom           WHERE is_active = 1 ORDER BY sort_order, label");
    $certTypes  = db_all("SELECT id, label FROM inv_cert_types    WHERE is_active = 1 ORDER BY sort_order, label");
    $steps      = db_all("SELECT id, label FROM inv_process_steps WHERE is_active = 1 ORDER BY sort_order, label");
    $vendors    = db_all("SELECT id, name, code FROM vendors WHERE is_active = 1 ORDER BY name");

    // Optional category prefill via ?prefill_category=<code>. Used by the
    // BOM tree "+ New BOM" button to land on item_new with Finished Good
    // pre-selected. Only honored on create (?action=item_new), not edit.
    $prefillCategoryId = 0;
    if (!$isEdit) {
        $prefillCode = trim((string)input('prefill_category', ''));
        if ($prefillCode !== '') {
            $pc = db_one("SELECT id FROM categories WHERE type='inventory' AND code = ?", [$prefillCode]);
            if ($pc) $prefillCategoryId = (int)$pc['id'];
        }
    }

    // Current selections
    $selVendors = $isEdit
        ? array_map('intval', array_column(db_all('SELECT vendor_id FROM inv_item_vendors WHERE item_id = ? ORDER BY sort_order', [$id]), 'vendor_id'))
        : [];
    $selCerts = $isEdit
        ? array_map('intval', array_column(db_all('SELECT cert_id FROM inv_item_certs WHERE item_id = ?', [$id]), 'cert_id'))
        : [];

    // Preview the next Inventory Code for new items
    $previewCode = $isEdit ? $editing['code'] : inv_id_generate();

    $page_title  = $isEdit ? 'Edit item: ' . $editing['short_description'] : 'New inventory item';
    $page_module = 'inventory';
    $focus_id    = 'f_short_desc';

    $deleteHtml = '';
    if ($isEdit && $canDeleteItems) {
        $deleteHtml =
            ' <form method="post" style="display:inline;"'
          . ' action="' . h(url('/inventory.php?action=item_delete')) . '"'
          . ' onsubmit="return confirm(\'Delete item ' . h(addslashes($editing['code'])) . '? Cannot be undone.\');">'
          . csrf_field()
          . '<input type="hidden" name="id" value="' . (int)$editing['id'] . '">'
          . '<button type="submit" class="btn btn-danger btn-sm">Delete</button>'
          . '</form>';
    }
    $toggleActiveHtml = '';
    if ($isEdit && $canManageItems) {
        $isActive = (int)($editing['is_active'] ?? 1);
        $toggleActiveHtml =
            ' <form method="post" style="display:inline;"'
          . ' action="' . h(url('/inventory.php?action=item_toggle_active')) . '">'
          . csrf_field()
          . '<input type="hidden" name="id" value="' . (int)$editing['id'] . '">'
          . '<button type="submit" class="btn ' . ($isActive ? 'btn-warn' : 'btn-ghost') . ' btn-sm">'
          . ($isActive ? 'Mark Inactive' : 'Mark Active') . '</button>'
          . '</form>';
    }
    $bomHtml = '';
    if ($isEdit) {
        $bomHtml = ' <a class="btn btn-ghost btn-sm" href="'
                 . h(url('/inventory.php?action=bom_view&id=' . (int)$editing['id'])) . '">View BOM tree →</a>';
    }
    $notesBtnHtml = '';
    if ($isEdit) {
        $notesBtnHtml = ' ' . notes_popup_button('inv_item', (int)$editing['id'], 'Notes', 'N');
    }

    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $isEdit ? 'Edit item' : 'New inventory item',
            'subtitle'    => $isEdit
                ? $editing['code'] . ' — ' . $editing['short_description']
                : 'Add a new part or product to the inventory',
            'back_href'   => url('/inventory.php?action=items'),
            'back_label'  => 'Inventory',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=items')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>'
              . $notesBtnHtml
              . $bomHtml
              . $toggleActiveHtml
              . $deleteHtml,
        ]) ?>
        <form id="main-form" method="post" action="<?= h(url('/inventory.php?action=item_save')) ?>"
              class="form-page-body form-grid">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <?php endif; ?>

            <!-- ============ Identification ============ -->
            <div class="field">
                <label for="f_code">Inventory Code</label>
                <input id="f_code" tabindex="-1" readonly type="text"
                       value="<?= h($previewCode) ?>"
                       style="font-family: var(--font-mono, monospace); background: var(--surface-alt, #f6f7f9);">
                <span class="muted small">
                    <?php if (!$isEdit): ?>
                        Auto-generated when you save. Preview shown.
                    <?php else: ?>
                        System-assigned · immutable.
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($isEdit): ?>
            <div class="field">
                <label>Location(s)</label>
                <?php
                $locationStocks = db_all(
                    'SELECT l.code AS loc_code, l.name AS loc_name, s.qty
                       FROM inv_item_location_stock s
                       JOIN locations l ON l.id = s.location_id
                      WHERE s.item_id = ?
                      ORDER BY l.name',
                    [$id]
                );
                if ($locationStocks): ?>
                    <div style="padding: 4px 0; display: flex; flex-wrap: wrap; gap: 4px;">
                        <?php foreach ($locationStocks as $ls):
                            $qty = rtrim(rtrim(number_format((float)$ls['qty'], 3), '0'), '.');
                            if ($qty === '' || $qty === '.') $qty = '0';
                        ?>
                            <span class="pill pill-neutral" title="<?= h($ls['loc_name']) ?>"><?= h($ls['loc_code']) ?>: <?= h($qty) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="muted small">No stock at any location</span>
                <?php endif; ?>
                <span class="muted small">Current stock locations — read-only.</span>
            </div>
            <?php endif; ?>
            <div class="field">
                <label for="f_short_desc">Short Description *</label>
                <input id="f_short_desc" name="short_description" type="text" required tabindex="1"
                       value="<?= h($editing['short_description'] ?? '') ?>">
            </div>

            <!-- ============ Classification ============ -->
            <div class="field">
                <label for="f_category">Category *</label>
                <select id="f_category" name="category_id" required tabindex="2">
                    <option value="">— Select One —</option>
                    <?php foreach ($categories as $c):
                        $isSel = ($isEdit && (int)$editing['category_id'] === (int)$c['id'])
                              || (!$isEdit && $prefillCategoryId && $prefillCategoryId === (int)$c['id']);
                    ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $isSel ? 'selected' : '' ?>>
                            <?= h($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_division">I_Division</label>
                <select id="f_division" name="division_id" tabindex="3">
                    <option value="">— None (optional) —</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= ($isEdit && (int)$editing['division_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                            <?= h($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ============ Manufacturer (toggle + conditional vendor multi-select) ============ -->
            <div class="field span-2">
                <label>Manufacturer *</label>
                <div style="display: flex; gap: 16px; padding: 6px 0;">
                    <?php $mt = $isEdit ? $editing['manufacturer_type'] : 'internal'; ?>
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="radio" name="manufacturer_type" value="internal" id="f_mfr_internal"
                               <?= $mt === 'internal' ? 'checked' : '' ?> tabindex="4">
                        Internal
                    </label>
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="radio" name="manufacturer_type" value="external" id="f_mfr_external"
                               <?= $mt === 'external' ? 'checked' : '' ?> tabindex="4">
                        External
                    </label>
                </div>
                <div id="vendor-block" style="<?= $mt === 'external' ? '' : 'display: none;' ?>">
                    <label for="f_vendors" class="muted small">External vendors</label>
                    <select id="f_vendors" name="vendor_ids[]" multiple class="chips"
                            data-placeholder="Search vendors…">
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $selVendors, true) ? 'selected' : '' ?>>
                                <?= h($v['name']) ?> (<?= h($v['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- ============ UoM ============ -->
            <div class="field">
                <label for="f_uom">I_UOM *</label>
                <select id="f_uom" name="uom_id" required tabindex="5">
                    <option value="">— Select One —</option>
                    <?php foreach ($uoms as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= ($isEdit && (int)$editing['uom_id'] === (int)$u['id']) ? 'selected' : '' ?>>
                            <?= h($u['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Spacer to align the row -->
            <div class="field"></div>

            <!-- ============ Descriptions + notes ============ -->
            <div class="field span-2">
                <label for="f_long_desc">Part Name</label>
                <textarea id="f_long_desc" name="long_description" rows="3" tabindex="6"
                          placeholder="Full part name / long-form description"><?= h($editing['long_description'] ?? '') ?></textarea>
            </div>
            <div class="field span-2">
                <label for="f_notes">Notes</label>
                <textarea id="f_notes" name="notes" rows="5" tabindex="7"
                          placeholder="Multi-line notes — supports line breaks"><?= h($editing['notes'] ?? '') ?></textarea>
            </div>

            <!-- ============ Drawing & part numbers ============ -->
            <div class="field">
                <label for="f_dwg_no">Dwg_No</label>
                <input id="f_dwg_no" name="dwg_no" type="text" tabindex="8"
                       value="<?= h($editing['dwg_no'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_dwg_rev">Dwg Rev_No</label>
                <input id="f_dwg_rev" name="dwg_rev_no" type="text" tabindex="9"
                       value="<?= h($editing['dwg_rev_no'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_part_no">Part_No</label>
                <input id="f_part_no" name="part_no" type="text" tabindex="10"
                       value="<?= h($editing['part_no'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_part_rev">Part Rev No</label>
                <input id="f_part_rev" name="part_rev_no" type="text" tabindex="11"
                       value="<?= h($editing['part_rev_no'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_ecn">ECN</label>
                <input id="f_ecn" name="ecn" type="text" tabindex="11"
                       value="<?= h($editing['ecn'] ?? '') ?>">
            </div>

            <!-- ============ Process info ============ -->
            <div class="field span-2">
                <label for="f_process_spec">Process Spec</label>
                <textarea id="f_process_spec" name="process_spec" rows="2" tabindex="12"><?= h($editing['process_spec'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label for="f_step">ProcessStep</label>
                <select id="f_step" name="process_step_id" tabindex="13">
                    <option value="">— Select One —</option>
                    <?php foreach ($steps as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ($isEdit && (int)$editing['process_step_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                            <?= h($s['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_step_no">StepNo</label>
                <input id="f_step_no" name="step_no" type="text" tabindex="14"
                       value="<?= h($editing['step_no'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_step_time">I_Step time (min)</label>
                <input id="f_step_time" name="step_time_min" type="number" step="0.01" tabindex="15"
                       value="<?= h($editing['step_time_min'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_step_cost">I_Step Cost (₹)</label>
                <input id="f_step_cost" name="step_cost" type="number" step="0.01" tabindex="16"
                       value="<?= h($editing['step_cost'] ?? '') ?>">
            </div>

            <!-- ============ Stock thresholds ============ -->
            <div class="field">
                <label for="f_min_stock">Min Stock Level</label>
                <input id="f_min_stock" name="min_stock_level" type="number" step="0.001" tabindex="17"
                       value="<?= h($editing['min_stock_level'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_min_order">Min Order Qty</label>
                <input id="f_min_order" name="min_order_qty" type="number" step="0.001" tabindex="18"
                       value="<?= h($editing['min_order_qty'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_min_sample">Min sample Qty</label>
                <input id="f_min_sample" name="min_sample_qty" type="number" step="0.001" tabindex="19"
                       value="<?= h($editing['min_sample_qty'] ?? '0') ?>">
            </div>
            <div class="field">
                <label for="f_min_sample_pct">Min sample percentage (%)</label>
                <input id="f_min_sample_pct" name="min_sample_pct" type="number" step="0.01" min="0" max="100" tabindex="20"
                       value="<?= h($editing['min_sample_pct'] ?? '0') ?>">
            </div>

            <!-- ============ Certifications + specs ============ -->
            <div class="field">
                <label for="f_certs">CERT</label>
                <select id="f_certs" name="cert_ids[]" multiple class="chips" tabindex="21"
                        data-placeholder="Search certifications…">
                    <?php foreach ($certTypes as $ct): ?>
                        <option value="<?= (int)$ct['id'] ?>" <?= in_array((int)$ct['id'], $selCerts, true) ? 'selected' : '' ?>>
                            <?= h($ct['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_material">Material Spec</label>
                <input id="f_material" name="material_spec" type="text" tabindex="22"
                       value="<?= h($editing['material_spec'] ?? '') ?>">
            </div>

            <div class="field span-2">
                <label for="f_remarks">Remarks</label>
                <input id="f_remarks" name="remarks" type="text" tabindex="23"
                       value="<?= h($editing['remarks'] ?? '') ?>">
            </div>

        </form>
    </div><!-- /.form-page -->

    <script>
    /* Manufacturer toggle: show/hide the vendor multi-select when the
       Internal/External radio changes. */
    (function () {
        var rInt = document.getElementById('f_mfr_internal');
        var rExt = document.getElementById('f_mfr_external');
        var vb   = document.getElementById('vendor-block');
        function apply() {
            if (!vb) return;
            vb.style.display = (rExt && rExt.checked) ? '' : 'none';
        }
        if (rInt) rInt.addEventListener('change', apply);
        if (rExt) rExt.addEventListener('change', apply);
        apply();
    })();
    </script>

    <?php if ($isEdit) notes_popup_assets(); ?>

    <?php
    // ---- Billing-product mirror panel ----
    // Only shown on edit (we need an item id) and only when the item is
    // a finished good (the only kind we mirror). The panel shows the
    // current push state, the manual "Push to billing" button (gated by
    // inventory_view_items.push_to_billing), and the last 10 push
    // history rows for diagnosis.
    if ($isEdit && $editing
        && ((int)($editing['is_product'] ?? 0) === 1
            || billing_products_is_finished_category($editing['category_id']))):
        $billingReady = billing_products_config() !== null;
        $canPush      = function_exists('permission_check') && permission_check('inventory_view_items', 'push_to_billing');
        $history      = billing_product_history((int)$editing['id'], 10);
        $lastErr      = (string)($editing['billing_last_push_error'] ?? '');
        $bpid         = (int)($editing['billing_product_id'] ?? 0);
    ?>
        <div class="card" style="margin-top: 20px;">
            <div class="card-head">
                <h3 style="margin:0; font-size:14px;">
                    Billing-product mirror
                    <?php if ($bpid): ?>
                        <span class="pill pill-info" style="margin-left:6px;">linked · billing id <?= $bpid ?></span>
                    <?php else: ?>
                        <span class="pill pill-muted" style="margin-left:6px;">not yet pushed</span>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-body">
                <p class="muted small">
                    Finished-good items auto-mirror to the billing app's product catalogue on save.
                    This keeps ATS pushes from failing with <code>item_not_found</code>.
                </p>

                <dl class="kv" style="margin-top:10px;">
                    <dt>Last push</dt>
                    <dd>
                        <?php if (!empty($editing['billing_last_push_at'])): ?>
                            <?= h($editing['billing_last_push_at']) ?>
                            · <?= h($editing['billing_last_push_op'] ?: '—') ?>
                            · HTTP <?= (int)($editing['billing_last_push_http'] ?? 0) ?>
                            <?php if ($lastErr !== ''): ?>
                                <br><span style="color: var(--danger);">⚠ <?= h($lastErr) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">never</span>
                        <?php endif; ?>
                    </dd>
                </dl>

                <div style="margin-top: 12px; display: flex; gap: 8px; align-items: center;">
                    <?php if ($billingReady && $canPush): ?>
                        <form method="post" action="<?= h(url('/inventory.php?action=item_billing_push')) ?>" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-ghost"
                                    title="Force a push to billing now. Useful for retry after a failure, or to refresh billing's copy.">
                                ↑ Push to billing now
                            </button>
                        </form>
                    <?php elseif (!$billingReady): ?>
                        <span class="pill pill-warn"
                              title="config/app.config.php → billing_integration.product_url is not set">
                            billing not configured
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($history): ?>
                    <details style="margin-top:14px;">
                        <summary class="muted small">Push history (<?= count($history) ?>)</summary>
                        <table class="dt-table" style="margin-top:8px;">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Op</th>
                                    <th>HTTP</th>
                                    <th>Result</th>
                                    <th>Actor</th>
                                    <th>Error / detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td class="small"><?= h($h['created_at']) ?></td>
                                        <td><?= h($h['op']) ?></td>
                                        <td><?= (int)$h['http'] ?: '<span class="muted">—</span>' ?></td>
                                        <td>
                                            <?php if ((int)$h['ok'] === 1): ?>
                                                <span class="pill pill-success">OK</span>
                                            <?php else: ?>
                                                <span class="pill pill-danger"><?= h($h['error_code'] ?: 'fail') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= h($h['actor_name'] ?: '—') ?></td>
                                        <td class="small">
                                            <?php if ($h['error']): ?>
                                                <?= h($h['error']) ?>
                                            <?php elseif ($h['response']): ?>
                                                <details>
                                                    <summary class="muted">view response</summary>
                                                    <pre style="white-space: pre-wrap; max-height: 200px; overflow: auto; font-size: 11px;"><?= h(substr((string)$h['response'], 0, 2000)) ?></pre>
                                                </details>
                                            <?php else: ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}
