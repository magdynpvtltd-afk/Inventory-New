<?php
/**
 * MagDyn — Asset module
 * Created: 20260515_071000_IST
 *
 * Single file with sub-actions:
 *
 *   ?action=index           dashboard (cal due soon/overdue + asset list)
 *   ?action=list            full asset list with filters
 *   ?action=view&id=N       single asset + its transaction history
 *   ?action=new             new asset form
 *   ?action=edit&id=N       edit an asset
 *   ?action=save  (POST)    asset save
 *   ?action=archive  (POST) archive an asset
 *   ?action=unarchive(POST) unarchive
 *
 *   ?action=models                  model list
 *   ?action=model_new               new model form
 *   ?action=model_edit&id=N         edit model
 *   ?action=model_save (POST)
 *
 *   ?action=txn&id=N                start move/send/receive form
 *   ?action=txn_save (POST)         record transaction
 *
 * Permissions used:
 *   asset.view        — read everything
 *   asset.create      — create assets
 *   asset.manage      — edit asset fields
 *   asset.transact    — move / send / receive / archive
 *   asset.manage_model — model CRUD
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_notes.php';
require_once __DIR__ . '/includes/_invoice_links.php';
require_login();
require_permission('asset', 'view');

$action = (string)input('action', 'index');
$uid    = current_user_id();

$canCreate     = permission_check('asset', 'create');
$canManage     = permission_check('asset', 'manage');
$canTransact   = permission_check('asset', 'transact');
$canArchive    = permission_check('asset', 'archive');
$canModelMgr   = permission_check('asset', 'manage_model');
$canDelete     = permission_check('asset', 'delete');

// ============================================================
// LOOKUPS (helpers used by multiple actions)
// ============================================================
function asset_lookup($table) {
    return db_all("SELECT * FROM `$table` WHERE is_active = 1 ORDER BY sort_order, label");
}
function dropdown(string $name, array $rows, $current, $tabindex = 0, $id = '') {
    $idAttr = $id ? "id=\"" . h($id) . "\"" : '';
    $tab    = $tabindex ? "tabindex=\"$tabindex\"" : '';
    $out  = "<select name=\"" . h($name) . "\" $idAttr $tab>";
    $out .= '<option value="">— Select One —</option>';
    foreach ($rows as $r) {
        $sel = ((int)$current === (int)$r['id']) ? 'selected' : '';
        $out .= '<option value="' . (int)$r['id'] . "\" $sel>" . h($r['label']) . '</option>';
    }
    return $out . '</select>';
}

/**
 * Generate the next Asset ID using the configured prefix + pad width.
 *
 * Walks forward from the highest existing numeric suffix to find an
 * unused slot. The unique constraint on asset_tag makes a wasted call
 * harmless — a concurrent insert would just bump us to the next number.
 *
 * Falls back to a wall-clock-based suffix if the table is in a weird
 * state (no numeric IDs at all).
 */
function asset_id_generate() {
    $cfg = $GLOBALS['APP']['asset_id'] ?? ['prefix' => 'A-', 'pad' => 5, 'start' => 1];
    $prefix = (string)$cfg['prefix'];
    $pad    = (int)$cfg['pad'];
    $start  = (int)$cfg['start'];

    // Find the highest numeric suffix already in use for this prefix.
    $like = $prefix . '%';
    $rows = db_all(
        "SELECT asset_tag FROM assets WHERE asset_tag LIKE ? ORDER BY id DESC LIMIT 50",
        [$like]
    );
    $max = $start - 1;
    foreach ($rows as $r) {
        $suffix = substr($r['asset_tag'], strlen($prefix));
        if (ctype_digit($suffix)) {
            $n = (int)$suffix;
            if ($n > $max) $max = $n;
        }
    }
    $next = $max + 1;

    // Try up to a handful of attempts in case of concurrent inserts.
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = $prefix . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
        $clash = db_one('SELECT id FROM assets WHERE asset_tag = ?', [$candidate]);
        if (!$clash) return $candidate;
        $next++;
    }
    // Fallback — should never happen in practice
    return $prefix . date('YmdHis');
}

/**
 * Generate the next MDL-NNN code for asset_models. Same parse-the-
 * suffix-and-increment pattern as asset_id_generate(), specialised
 * for the asset_models.code column (added by migration 060000).
 */
function asset_model_next_code() {
    $prefix = 'MDL-';
    $pad    = 3;

    $like = $prefix . '%';
    $rows = db_all(
        "SELECT code FROM asset_models WHERE code LIKE ? ORDER BY id DESC LIMIT 50",
        [$like]
    );
    $max = 0;
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
        $clash = db_one('SELECT id FROM asset_models WHERE code = ?', [$candidate]);
        if (!$clash) return $candidate;
        $next++;
    }
    return $prefix . date('YmdHis');
}

// ============================================================
// ASSET IMPORT — two-step (preview + commit)
// ============================================================
// CSV columns (all optional except `model_code`):
//   asset_tag        — auto-generated if blank
//   model_code *     — required, must match an existing asset_models.code
//   location_code    — matches locations.code (or locations.name)
//   parent_asset_tag — matches an existing assets.asset_tag (parent in hierarchy)
//   notes
//   a_price          — numeric
//   pid_used_in
//   cal_done_on      — YYYY-MM-DD
//   next_cal_due_on  — YYYY-MM-DD
//   cal_frequency    — matches asset_cal_frequencies.label
//   status           — active / archived / with_vendor / with_user (default active)
//   is_active        — for parity with other entities; archived if 0
// Resolution is preview-time so users see FK failures before commit.
require_once __DIR__ . '/includes/_import.php';

function asset_import_adapter(array $row, bool $upsert) {
    $assetTag = isset($row['asset_tag']) ? trim((string)$row['asset_tag']) : '';
    $modelCode = isset($row['model_code']) ? trim((string)$row['model_code']) : '';
    if ($modelCode === '') {
        return ['status' => 'error', 'reason' => 'model_code is required'];
    }
    // Resolve model_id from model_code. If asset_models.code column hasn't
    // been added yet (legacy DBs), fall back to model name.
    $hasModelCodeCol = false;
    try {
        $hasModelCodeCol = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'code'"));
    } catch (Exception $e) {}
    if ($hasModelCodeCol) {
        $m = db_one('SELECT id FROM asset_models WHERE code = ?', [$modelCode]);
    } else {
        $m = db_one('SELECT id FROM asset_models WHERE name = ?', [$modelCode]);
    }
    if (!$m) {
        return ['status' => 'error',
                'reason' => 'Unknown model_code "' . $modelCode . '"'];
    }
    $modelId = (int)$m['id'];

    // Optional FKs — empty means leave NULL.
    $locationId = null;
    $locationCode = isset($row['location_code']) ? trim((string)$row['location_code']) : '';
    if ($locationCode !== '') {
        $l = db_one('SELECT id FROM locations WHERE code = ?', [$locationCode]);
        if (!$l) $l = db_one('SELECT id FROM locations WHERE name = ?', [$locationCode]);
        if (!$l) {
            return ['status' => 'error',
                    'reason' => 'Unknown location_code "' . $locationCode . '"'];
        }
        $locationId = (int)$l['id'];
    }

    $parentAssetId = null;
    $parentTag = isset($row['parent_asset_tag']) ? trim((string)$row['parent_asset_tag']) : '';
    if ($parentTag !== '') {
        $p = db_one('SELECT id FROM assets WHERE asset_tag = ?', [$parentTag]);
        if (!$p) {
            return ['status' => 'error',
                    'reason' => 'Unknown parent_asset_tag "' . $parentTag . '"'];
        }
        $parentAssetId = (int)$p['id'];
    }

    $calFreqId = null;
    $freqLabel = isset($row['cal_frequency']) ? trim((string)$row['cal_frequency']) : '';
    if ($freqLabel !== '') {
        $f = db_one('SELECT id FROM asset_cal_frequencies WHERE label = ?', [$freqLabel]);
        if (!$f) {
            return ['status' => 'error',
                    'reason' => 'Unknown cal_frequency "' . $freqLabel . '"'];
        }
        $calFreqId = (int)$f['id'];
    }

    $status = isset($row['status']) ? trim((string)$row['status']) : 'active';
    if (!in_array($status, ['active','archived','with_vendor','with_user'], true)) {
        return ['status' => 'error',
                'reason' => 'Bad status "' . $status . '" (expected active/archived/with_vendor/with_user)'];
    }

    // Validate dates lightly (YYYY-MM-DD or blank)
    $validateDate = function ($s) {
        $s = trim((string)$s);
        if ($s === '') return ['ok' => true, 'value' => null];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return ['ok' => false];
        }
        return ['ok' => true, 'value' => $s];
    };
    $vDone = $validateDate($row['cal_done_on'] ?? '');
    if (!$vDone['ok']) return ['status' => 'error', 'reason' => 'cal_done_on must be YYYY-MM-DD'];
    $vDue  = $validateDate($row['next_cal_due_on'] ?? '');
    if (!$vDue['ok']) return ['status' => 'error', 'reason' => 'next_cal_due_on must be YYYY-MM-DD'];

    // a_price — numeric or blank
    $aPrice = null;
    $priceRaw = trim((string)($row['a_price'] ?? ''));
    if ($priceRaw !== '') {
        if (!is_numeric($priceRaw)) {
            return ['status' => 'error', 'reason' => 'a_price must be numeric'];
        }
        $aPrice = (float)$priceRaw;
    }

    $clean = [
        'asset_tag'        => $assetTag,
        'model_id'         => $modelId,
        'model_code'       => $modelCode,   // for preview display
        'location_id'      => $locationId,
        'location_code'    => $locationCode,
        'parent_asset_id'  => $parentAssetId,
        'parent_asset_tag' => $parentTag,
        'notes'            => trim((string)($row['notes'] ?? '')),
        'a_price'          => $aPrice,
        'pid_used_in'      => trim((string)($row['pid_used_in'] ?? '')),
        'cal_done_on'      => $vDone['value'],
        'next_cal_due_on'  => $vDue['value'],
        'cal_frequency_id' => $calFreqId,
        'cal_frequency'    => $freqLabel,
        'status'           => $status,
    ];

    // Look up existing by asset_tag if one was supplied. Empty asset_tag
    // always means insert (it'll get auto-generated at commit).
    if ($assetTag !== '') {
        $e = db_one('SELECT id FROM assets WHERE asset_tag = ?', [$assetTag]);
        if ($e) {
            if (!$upsert) {
                return ['status' => 'skip',
                        'reason' => 'asset_tag "' . $assetTag . '" already exists (upsert is off)',
                        'data'   => $clean];
            }
            return ['status' => 'update', 'data' => $clean, 'existing_id' => (int)$e['id']];
        }
    }
    return ['status' => 'insert', 'data' => $clean];
}

function asset_import_committer(array $previewRow) {
    $d = $previewRow['data'];
    $uid = current_user_id();
    if ($previewRow['status'] === 'update') {
        $id = (int)$previewRow['existing_id'];
        db_exec(
            'UPDATE assets SET model_id=?, location_id=?, parent_asset_id=?, notes=?,
                a_price=?, pid_used_in=?, cal_done_on=?, next_cal_due_on=?,
                cal_frequency_id=?, status=?
              WHERE id=?',
            [$d['model_id'], $d['location_id'], $d['parent_asset_id'], $d['notes'] ?: null,
             $d['a_price'], $d['pid_used_in'] ?: null, $d['cal_done_on'], $d['next_cal_due_on'],
             $d['cal_frequency_id'], $d['status'], $id]
        );
        return $id;
    }
    // Insert — auto-generate asset_tag if blank
    $tag = $d['asset_tag'] !== '' ? $d['asset_tag'] : asset_id_generate();
    db_exec(
        'INSERT INTO assets (asset_tag, model_id, location_id, parent_asset_id, notes,
            a_price, pid_used_in, cal_done_on, next_cal_due_on, cal_frequency_id,
            status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$tag, $d['model_id'], $d['location_id'], $d['parent_asset_id'], $d['notes'] ?: null,
         $d['a_price'], $d['pid_used_in'] ?: null, $d['cal_done_on'], $d['next_cal_due_on'],
         $d['cal_frequency_id'], $d['status'], $uid]
    );
    return (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
}

if ($action === 'import_preview') {
    require_permission('asset', 'create');
    csrf_check();
    $upsert = !empty($_POST['upsert']);
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/asset.php?action=list'));
    }
    $token  = import_stash($parsed['csv_text'], 'assets');
    $result = import_run_adapter($parsed['rows'], 'asset_import_adapter', $upsert);
    $page_title  = 'Import assets · preview';
    $page_module = 'asset';
    require __DIR__ . '/includes/header.php';
    import_render_preview([
        'title'      => 'Import assets · preview',
        'commit_url' => url('/asset.php?action=import_commit'),
        'cancel_url' => url('/asset.php?action=list'),
        'token'      => $token,
        'upsert'     => $upsert,
        'counts'     => $result['counts'],
        'rows'       => $result['rows'],
        'columns'    => [
            ['asset_tag',     'Tag'],
            ['model_code',    'Model'],
            ['location_code', 'Location'],
            ['status',        'Status'],
            ['cal_done_on',   'Cal done'],
            ['next_cal_due_on','Next cal due'],
            ['notes',         'Notes'],
        ],
    ]);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'import_commit') {
    require_permission('asset', 'create');
    csrf_check();
    $token  = (string)input('token', '');
    $upsert = !empty($_POST['upsert']);
    $csv = import_unstash($token, 'assets');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/asset.php?action=list'));
    }
    $res = import_run_commit($csv, 'asset_import_adapter', $upsert, 'asset_import_committer');
    if (empty($res['ok'])) {
        flash_set('error', 'Import failed: ' . ($res['error'] ?? 'unknown'));
    } else {
        $msg = 'Imported ' . (int)$res['inserted'] . ' new asset'
             . ($res['inserted'] === 1 ? '' : 's')
             . ', updated ' . (int)$res['updated']
             . '.' . ($res['errors'] > 0 ? ' ' . (int)$res['errors'] . ' rows failed (see server log).' : '');
        flash_set('success', $msg);
    }
    redirect(url('/asset.php?action=list'));
}

// ============================================================
// SAVE handlers
// ============================================================
if ($action === 'save') {
    csrf_check();
    $id     = (int)input('id', 0);

    // Asset ID is system-controlled: auto-generated on create, immutable
    // on edit. The form may submit a value but we ignore it.
    if ($id) {
        $existing = db_one('SELECT asset_tag FROM assets WHERE id = ?', [$id]);
        $assetTag = $existing ? $existing['asset_tag'] : '';
    } else {
        $assetTag = asset_id_generate();
    }

    $data = [
        'asset_tag'        => $assetTag,
        'asset_name'       => trim((string)input('asset_name')) ?: null,
        'model_id'         => (int)input('model_id', 0),
        'location_id'      => (int)input('location_id', 0) ?: null,
        'parent_asset_id'  => (int)input('parent_asset_id', 0) ?: null,
        'lock_to_parent'   => input('lock_to_parent') ? 1 : 0,
        'notes'            => trim((string)input('notes')),
        'a_price'          => input('a_price') === '' ? null : (float)input('a_price'),
        'pid_used_in'      => trim((string)input('pid_used_in')),
        'cal_done_on'      => input('cal_done_on') ?: null,
        'next_cal_due_on'  => input('next_cal_due_on') ?: null,
        'alias_id'         => (int)input('alias_id', 0) ?: null,
        'cal_frequency_id' => (int)input('cal_frequency_id', 0) ?: null,
        'engraved_id'      => (int)input('engraved_id', 0) ?: null,
        'calibration_id'   => (int)input('calibration_id', 0) ?: null,
        'checked_ok_id'    => (int)input('checked_ok_id', 0) ?: null,
    ];

    $errors = [];
    if ($data['asset_tag'] === '') $errors[] = 'Asset ID could not be generated.';
    if (!$data['model_id'])        $errors[] = 'Model is required.';
    // Cycle check
    if ($id && $data['parent_asset_id'] === $id) $errors[] = 'An asset cannot be its own parent.';

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect($id ? url('/asset.php?action=edit&id=' . $id) : url('/asset.php?action=new'));
    }

    if ($id) {
        require_permission('asset', 'manage');
        db_exec(
            "UPDATE assets SET
                asset_tag=?, asset_name=?, model_id=?, location_id=?, parent_asset_id=?, lock_to_parent=?,
                notes=?, a_price=?, pid_used_in=?, cal_done_on=?, next_cal_due_on=?,
                alias_id=?, cal_frequency_id=?, engraved_id=?, calibration_id=?, checked_ok_id=?
             WHERE id=?",
            [$data['asset_tag'], $data['asset_name'], $data['model_id'], $data['location_id'], $data['parent_asset_id'],
             $data['lock_to_parent'], $data['notes'], $data['a_price'], $data['pid_used_in'],
             $data['cal_done_on'], $data['next_cal_due_on'],
             $data['alias_id'], $data['cal_frequency_id'], $data['engraved_id'],
             $data['calibration_id'], $data['checked_ok_id'], $id]
        );
        flash_set('success', 'Asset updated.');
    } else {
        require_permission('asset', 'create');
        db_exec(
            "INSERT INTO assets
              (asset_tag, asset_name, model_id, location_id, parent_asset_id, lock_to_parent,
               notes, a_price, pid_used_in, cal_done_on, next_cal_due_on,
               alias_id, cal_frequency_id, engraved_id, calibration_id, checked_ok_id,
               status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)",
            [$data['asset_tag'], $data['asset_name'], $data['model_id'], $data['location_id'], $data['parent_asset_id'],
             $data['lock_to_parent'], $data['notes'], $data['a_price'], $data['pid_used_in'],
             $data['cal_done_on'], $data['next_cal_due_on'],
             $data['alias_id'], $data['cal_frequency_id'], $data['engraved_id'],
             $data['calibration_id'], $data['checked_ok_id'], $uid]
        );
        $id = db()->lastInsertId();
        flash_set('success', 'Asset created.');
    }
    redirect(url('/asset.php?action=view&id=' . $id));
}

if ($action === 'archive' || $action === 'unarchive') {
    require_permission('asset', 'archive');
    csrf_check();
    $id = (int)input('id', 0);
    $a  = db_one('SELECT * FROM assets WHERE id = ?', [$id]);
    if (!$a) { flash_set('error', 'Asset not found.'); redirect(url('/asset.php')); }
    if ($action === 'archive') {
        db_exec("UPDATE assets SET status='archived' WHERE id=?", [$id]);
        db_exec(
            "INSERT INTO asset_transactions (asset_id, txn_type, from_location_id, actor_id, notes)
             VALUES (?, 'archive', ?, ?, ?)",
            [$id, $a['location_id'], $uid, trim((string)input('notes'))]
        );
        flash_set('success', 'Asset archived.');
    } else {
        db_exec("UPDATE assets SET status='active' WHERE id=?", [$id]);
        db_exec(
            "INSERT INTO asset_transactions (asset_id, txn_type, actor_id, notes)
             VALUES (?, 'restore', ?, ?)",
            [$id, $uid, trim((string)input('notes'))]
        );
        flash_set('success', 'Asset restored.');
    }
    redirect(url('/asset.php?action=view&id=' . $id));
}

if ($action === 'delete') {
    require_permission('asset', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $a  = db_one('SELECT * FROM assets WHERE id = ?', [$id]);
    if (!$a) { flash_set('error', 'Asset not found.'); redirect(url('/asset.php')); }

    // Direct delete is allowed from the asset view (archive-first is no
    // longer required). The referential safeguards below still apply, and
    // the deletion is recorded in the audit log.
    // Refuse if any other asset has this as a parent
    $children = db_val('SELECT COUNT(*) FROM assets WHERE parent_asset_id = ?', [$id], 0);
    if ($children > 0) {
        flash_set('error', sprintf('Cannot delete — %d asset(s) list this as their parent.', $children));
        redirect(url('/asset.php?action=view&id=' . $id));
    }

    // Hard-block: if any of this asset's transactions are linked to
    // an invoice, refuse delete. Without this, the CASCADE on
    // asset_transactions deletion would orphan the invoice_lines rows
    // pointing at those txns (ON DELETE SET NULL leaves them dangling
    // with link_kind = 'asset' and no asset_txn_id — confusing for audit).
    $blockingInvoices = db_all(
        'SELECT DISTINCT i.invoice_no
           FROM asset_transactions at
           JOIN invoice_lines il    ON il.asset_txn_id = at.id
           JOIN invoice_items ii    ON ii.id = il.invoice_item_id
           JOIN invoices i          ON i.id = ii.invoice_id
          WHERE at.asset_id = ?
          ORDER BY i.invoice_no',
        [$id]
    );
    if ($blockingInvoices) {
        $nos = invoice_link_format_invoice_list($blockingInvoices);
        flash_set('error', 'Cannot delete: transactions on this asset are linked to invoice(s): '
            . $nos . '. Unlink them on each invoice\'s Links page first.');
        redirect(url('/asset.php?action=view&id=' . $id));
    }

    // CASCADE handles asset_transactions
    db_exec('DELETE FROM assets WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'asset.delete', ?)",
            [real_user_id(), 'deleted asset ' . $a['asset_tag']]);
    flash_set('success', 'Asset permanently deleted.');
    redirect(url('/asset.php'));
}

// ============================================================
// ASSET CLONE — duplicate an asset header (no txn history)
// ============================================================
// Each asset has a unique asset_tag (auto-generated). Clone:
//   - Auto-generates a fresh asset_tag via asset_id_generate()
//   - Copies model, calibration settings, alias/engraved options,
//     parent_asset_id (siblings under the same parent), notes, price
//   - Resets:
//       status              → 'active'
//       location_id         → NULL (user picks placement after clone)
//       current_vendor_id   → NULL
//       current_user_id     → NULL
//       cal_done_on         → NULL (new physical asset, never calibrated yet)
//       next_cal_due_on     → NULL
//   - Does NOT copy:
//       asset_transactions (audit history is per-instance)
// Land on view page for the new id.
if ($action === 'clone') {
    require_permission('asset', 'create');
    csrf_check();
    $id = (int)input('id', 0);
    $src = db_one('SELECT * FROM assets WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Asset not found.');
        redirect(url('/asset.php'));
    }

    $newTag = asset_id_generate();

    $newId = clone_row('assets', $id, [
        'asset_tag'         => $newTag,
        'status'            => 'active',
        'location_id'       => null,
        'current_vendor_id' => null,
        'current_user_id'   => null,
        'cal_done_on'       => null,
        'next_cal_due_on'   => null,
        'created_by'        => (int)current_user_id(),
    ], ['created_at']);

    if ($newId <= 0) {
        flash_set('error', 'Asset clone failed.');
        redirect(url('/asset.php?action=view&id=' . $id));
    }

    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'asset.clone', ?)",
        [real_user_id(), 'cloned ' . $src['asset_tag'] . ' → ' . $newTag]);
    flash_set('success', 'Asset cloned to "' . $newTag . '". Set its location, then save.');
    redirect(url('/asset.php?action=edit&id=' . $newId));
}

if ($action === 'model_delete') {
    require_permission('asset', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $m  = db_one('SELECT * FROM asset_models WHERE id = ?', [$id]);
    if (!$m) { flash_set('error', 'Model not found.'); redirect(url('/asset.php?action=models')); }
    // Block if any assets reference this model. List the blocking assets
    // (by tag) so the operator knows exactly which ones to reassign to
    // another model — or delete — before this model can go. Assets require
    // a model (assets.model_id is NOT NULL), so we never silently orphan them.
    $linked = (int)db_val('SELECT COUNT(*) FROM assets WHERE model_id = ?', [$id], 0);
    if ($linked > 0) {
        $sample = db_all('SELECT asset_tag FROM assets WHERE model_id = ? ORDER BY asset_tag LIMIT 10', [$id]);
        $tags   = array_map(function ($s) { return $s['asset_tag']; }, $sample);
        $more   = $linked > count($tags) ? sprintf(' (+%d more)', $linked - count($tags)) : '';
        flash_set('error', sprintf(
            'Cannot delete model "%s" — %d asset(s) still use it: %s%s. Reassign those assets to another model (or delete them) first.',
            $m['name'], $linked, implode(', ', $tags), $more
        ));
        redirect(url('/asset.php?action=models'));
    }
    db_exec('DELETE FROM asset_models WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'asset_model.delete', ?)",
            [real_user_id(), 'deleted model ' . $m['name']]);
    flash_set('success', 'Model deleted.');
    redirect(url('/asset.php?action=models'));
}

// ============================================================
// MODEL TOGGLE ACTIVE
// ============================================================
if ($action === 'model_toggle_active') {
    require_permission('asset', 'manage_model');
    csrf_check();
    $id = (int)input('id', 0);
    $m  = db_one('SELECT id, name, is_active FROM asset_models WHERE id = ?', [$id]);
    if (!$m) { flash_set('error', 'Model not found.'); redirect(url('/asset.php?action=models')); }
    $newActive = $m['is_active'] ? 0 : 1;
    db_exec('UPDATE asset_models SET is_active = ? WHERE id = ?', [$newActive, $id]);
    flash_set('success', 'Model "' . $m['name'] . '" marked ' . ($newActive ? 'active' : 'inactive') . '.');
    redirect(url('/asset.php?action=models'));
}

// ============================================================
// MODEL IMPORT — two-step (preview + commit)
// ============================================================
// Adapter: takes a CSV row, returns an action with normalized data.
// Committer: takes a previewed row, persists it. Both share the same
// FK resolution helpers so preview-time validation matches commit-time
// behavior exactly.
//
// CSV columns (case-insensitive, all optional except `name`):
//   code           — auto-generated MDL-NNN if blank
//   name *         — model name (required)
//   category       — free-text category
//   manufacturer
//   model_number
//   cal_frequency  — matches asset_cal_frequencies.label (or code if set)
//   notes
//   is_active      — 0/1, default 1
require_once __DIR__ . '/includes/_import.php';

/** Shared adapter: validate + resolve FKs for one model row. */
function model_import_adapter(array $row, bool $upsert) {
    $code = isset($row['code']) ? trim((string)$row['code']) : '';
    $name = isset($row['name']) ? trim((string)$row['name']) : '';
    if ($name === '') {
        return ['status' => 'error', 'reason' => 'Name is required'];
    }
    // Resolve cal_frequency by label first, then code if column exists.
    $freqId = null;
    $freqLabel = isset($row['cal_frequency']) ? trim((string)$row['cal_frequency']) : '';
    if ($freqLabel !== '') {
        $f = db_one('SELECT id FROM asset_cal_frequencies WHERE label = ?', [$freqLabel]);
        if (!$f) {
            // Try by code column if it exists
            try {
                $hasCode = !empty(db_one("SHOW COLUMNS FROM asset_cal_frequencies LIKE 'code'"));
                if ($hasCode) {
                    $f = db_one('SELECT id FROM asset_cal_frequencies WHERE code = ?', [$freqLabel]);
                }
            } catch (Exception $e) {}
        }
        if (!$f) {
            return ['status' => 'error',
                    'reason' => 'Unknown cal_frequency "' . $freqLabel . '"'];
        }
        $freqId = (int)$f['id'];
    }
    $clean = [
        'code'                     => $code,
        'name'                     => $name,
        'category'                 => isset($row['category'])     ? trim((string)$row['category']) : '',
        'manufacturer'             => isset($row['manufacturer']) ? trim((string)$row['manufacturer']) : '',
        'model_number'             => isset($row['model_number']) ? trim((string)$row['model_number']) : '',
        'default_cal_frequency_id' => $freqId,
        'notes'                    => isset($row['notes'])        ? trim((string)$row['notes']) : '',
        'is_active'                => (isset($row['is_active']) && $row['is_active'] !== ''
                                       ? ((int)$row['is_active'] ? 1 : 0)
                                       : 1),
    ];

    // Look for existing row by code (if code provided and column exists).
    $existingId = null;
    $hasCodeCol = false;
    try {
        $hasCodeCol = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'code'"));
    } catch (Exception $e) {}

    if ($hasCodeCol && $code !== '') {
        $existing = db_one('SELECT id FROM asset_models WHERE code = ?', [$code]);
        if ($existing) {
            if (!$upsert) {
                return ['status' => 'skip',
                        'reason' => 'Code "' . $code . '" already exists (upsert is off)',
                        'data'   => $clean];
            }
            return ['status' => 'update', 'data' => $clean, 'existing_id' => (int)$existing['id']];
        }
    }
    // No code given → always treated as insert. Code auto-generated at commit.
    return ['status' => 'insert', 'data' => $clean];
}

/** Committer: persists one validated row. */
function model_import_committer(array $previewRow) {
    $d = $previewRow['data'];
    $hasCodeCol = false;
    try {
        $hasCodeCol = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'code'"));
    } catch (Exception $e) {}

    if ($previewRow['status'] === 'update') {
        $id = (int)$previewRow['existing_id'];
        if ($hasCodeCol) {
            db_exec(
                'UPDATE asset_models SET code=?, name=?, category=?, manufacturer=?, model_number=?,
                  default_cal_frequency_id=?, notes=?, is_active=? WHERE id=?',
                [$d['code'], $d['name'], $d['category'] ?: null, $d['manufacturer'] ?: null,
                 $d['model_number'] ?: null, $d['default_cal_frequency_id'], $d['notes'] ?: null,
                 $d['is_active'], $id]
            );
        } else {
            db_exec(
                'UPDATE asset_models SET name=?, category=?, manufacturer=?, model_number=?,
                  default_cal_frequency_id=?, notes=?, is_active=? WHERE id=?',
                [$d['name'], $d['category'] ?: null, $d['manufacturer'] ?: null,
                 $d['model_number'] ?: null, $d['default_cal_frequency_id'], $d['notes'] ?: null,
                 $d['is_active'], $id]
            );
        }
        return $id;
    }
    // Insert
    $codeForInsert = $d['code'];
    if ($hasCodeCol && $codeForInsert === '') {
        $codeForInsert = asset_model_next_code();
    }
    if ($hasCodeCol) {
        db_exec(
            'INSERT INTO asset_models (code, name, category, manufacturer, model_number,
              default_cal_frequency_id, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$codeForInsert, $d['name'], $d['category'] ?: null, $d['manufacturer'] ?: null,
             $d['model_number'] ?: null, $d['default_cal_frequency_id'], $d['notes'] ?: null,
             $d['is_active']]
        );
    } else {
        db_exec(
            'INSERT INTO asset_models (name, category, manufacturer, model_number,
              default_cal_frequency_id, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$d['name'], $d['category'] ?: null, $d['manufacturer'] ?: null,
             $d['model_number'] ?: null, $d['default_cal_frequency_id'], $d['notes'] ?: null,
             $d['is_active']]
        );
    }
    return (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
}

// ?action=model_import_preview — POST multipart with `csv` + optional `upsert`
if ($action === 'model_import_preview') {
    require_permission('asset', 'manage_model');
    csrf_check();
    $upsert = !empty($_POST['upsert']);
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/asset.php?action=models'));
    }
    $token  = import_stash($parsed['csv_text'], 'asset_models');
    $result = import_run_adapter($parsed['rows'], 'model_import_adapter', $upsert);

    $page_title  = 'Import asset models · preview';
    $page_module = 'asset';
    require __DIR__ . '/includes/header.php';
    import_render_preview([
        'title'      => 'Import asset models · preview',
        'commit_url' => url('/asset.php?action=model_import_commit'),
        'cancel_url' => url('/asset.php?action=models'),
        'token'      => $token,
        'upsert'     => $upsert,
        'counts'     => $result['counts'],
        'rows'       => $result['rows'],
        'columns'    => [
            ['code',         'Code'],
            ['name',         'Name'],
            ['category',     'Category'],
            ['manufacturer', 'Manufacturer'],
            ['model_number', 'Model #'],
            ['notes',        'Notes'],
        ],
    ]);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ?action=model_import_commit — POST with token + upsert flag
if ($action === 'model_import_commit') {
    require_permission('asset', 'manage_model');
    csrf_check();
    $token  = (string)input('token', '');
    $upsert = !empty($_POST['upsert']);
    $csv = import_unstash($token, 'asset_models');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/asset.php?action=models'));
    }
    $res = import_run_commit($csv, 'model_import_adapter', $upsert, 'model_import_committer');
    if (empty($res['ok'])) {
        flash_set('error', 'Import failed: ' . ($res['error'] ?? 'unknown'));
    } else {
        $msg = 'Imported ' . (int)$res['inserted'] . ' new model'
             . ($res['inserted'] === 1 ? '' : 's')
             . ', updated ' . (int)$res['updated']
             . '.' . ($res['errors'] > 0 ? ' ' . (int)$res['errors'] . ' rows failed (see server log).' : '');
        flash_set('success', $msg);
    }
    redirect(url('/asset.php?action=models'));
}

// ---- Model save ----
if ($action === 'model_save') {
    require_permission('asset', 'manage_model');
    csrf_check();
    $id = (int)input('id', 0);
    $name = trim((string)input('name'));
    // Prefer the dropdown selection (category_id) since migration 093000.
    // Legacy free-text `category` is kept in sync so old rows still display
    // and reports without the FK still work.
    $category_id  = (int)input('category_id', 0) ?: null;
    $category     = trim((string)input('category'));
    // If a dropdown choice was made, override the free-text with the cat name
    if ($category_id) {
        $catRow = db_one('SELECT name FROM categories WHERE id = ?', [$category_id]);
        if ($catRow) $category = $catRow['name'];
    }
    $manufacturer = trim((string)input('manufacturer'));
    $model_number = trim((string)input('model_number'));
    $cal_freq_id = (int)input('default_cal_frequency_id', 0) ?: null;
    $notes = trim((string)input('notes'));
    $active = input('is_active') ? 1 : 0;

    if ($name === '') {
        flash_set('error', 'Model name is required.');
        redirect($id ? url('/asset.php?action=model_edit&id=' . $id) : url('/asset.php?action=model_new'));
    }

    // category_id column was added by migration 093000. Detect once whether
    // it exists so this code keeps working on un-migrated databases.
    static $hasCatFk = null;
    if ($hasCatFk === null) {
        try {
            $hasCatFk = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'category_id'"));
        } catch (Exception $e) { $hasCatFk = false; }
    }

    // `code` column was added by migration 060000. Detect similarly so the
    // page degrades gracefully on un-migrated installs.
    static $hasCodeCol = null;
    if ($hasCodeCol === null) {
        try {
            $hasCodeCol = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'code'"));
        } catch (Exception $e) { $hasCodeCol = false; }
    }

    // Resolve the code value to persist:
    //   - New: always auto-generate (form's readonly preview is ignored)
    //   - Edit: always reuse the existing DB value (codes are immutable)
    $codeForSave = null;
    if ($hasCodeCol) {
        if (!$id) {
            $codeForSave = asset_model_next_code();
        } else {
            $existing = db_one('SELECT code FROM asset_models WHERE id = ?', [$id]);
            $codeForSave = $existing ? (string)$existing['code'] : asset_model_next_code();
        }
    }

    if ($id) {
        if ($hasCatFk && $hasCodeCol) {
            db_exec(
                'UPDATE asset_models SET code=?, name=?, category=?, category_id=?, manufacturer=?, model_number=?,
                  default_cal_frequency_id=?, notes=?, is_active=? WHERE id=?',
                [$codeForSave, $name, $category, $category_id, $manufacturer, $model_number, $cal_freq_id, $notes, $active, $id]
            );
        } elseif ($hasCatFk) {
            db_exec(
                'UPDATE asset_models SET name=?, category=?, category_id=?, manufacturer=?, model_number=?,
                  default_cal_frequency_id=?, notes=?, is_active=? WHERE id=?',
                [$name, $category, $category_id, $manufacturer, $model_number, $cal_freq_id, $notes, $active, $id]
            );
        } else {
            db_exec(
                'UPDATE asset_models SET name=?, category=?, manufacturer=?, model_number=?,
                  default_cal_frequency_id=?, notes=?, is_active=? WHERE id=?',
                [$name, $category, $manufacturer, $model_number, $cal_freq_id, $notes, $active, $id]
            );
        }
    } else {
        if ($hasCatFk && $hasCodeCol) {
            db_exec(
                'INSERT INTO asset_models (code, name, category, category_id, manufacturer, model_number,
                  default_cal_frequency_id, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$codeForSave, $name, $category, $category_id, $manufacturer, $model_number, $cal_freq_id, $notes, $active]
            );
        } elseif ($hasCatFk) {
            db_exec(
                'INSERT INTO asset_models (name, category, category_id, manufacturer, model_number,
                  default_cal_frequency_id, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $category, $category_id, $manufacturer, $model_number, $cal_freq_id, $notes, $active]
            );
        } else {
            db_exec(
                'INSERT INTO asset_models (name, category, manufacturer, model_number,
                  default_cal_frequency_id, notes, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, $category, $manufacturer, $model_number, $cal_freq_id, $notes, $active]
            );
        }
    }
    flash_set('success', 'Model saved.');
    redirect(url('/asset.php?action=models'));
}

// ============================================================
// ASSET TRANSACTION IMPORT — two-step, append-only
// ============================================================
// CSV columns (case-insensitive headers):
//   asset_tag *        required, matches assets.asset_tag
//   txn_type *         required, one of: move / send_vendor /
//                      receive_vendor / send_user / receive_user
//   to_location_code   required for move, receive_vendor, receive_user
//   to_vendor_code     required for send_vendor (matches vendors.code,
//                      falls back to vendors.name)
//   to_user_username   required for send_user (matches users.username)
//   notes              optional
//
// Each row applies in CSV order. The "from" side is read from the
// asset's current state at the moment of application (not preview
// time), since intermediate rows may have changed it. There is no
// upsert — transactions are immutable history.
require_once __DIR__ . '/includes/_import.php';

function asset_txn_import_adapter(array $row, bool $upsert) {
    $tag = trim((string)($row['asset_tag'] ?? ''));
    if ($tag === '') return ['status' => 'error', 'reason' => 'asset_tag is required'];
    $a = db_one('SELECT id, status FROM assets WHERE asset_tag = ?', [$tag]);
    if (!$a) return ['status' => 'error', 'reason' => 'Unknown asset_tag "' . $tag . '"'];
    $assetId = (int)$a['id'];

    $type = strtolower(trim((string)($row['txn_type'] ?? '')));
    $allowed = ['move','send_vendor','receive_vendor','send_user','receive_user'];
    if (!in_array($type, $allowed, true)) {
        return ['status' => 'error',
                'reason' => 'txn_type must be one of ' . implode(' / ', $allowed)];
    }

    $toLocationId = null;
    $toVendorId   = null;
    $toUserId     = null;

    if (in_array($type, ['move','receive_vendor','receive_user'], true)) {
        $locCode = trim((string)($row['to_location_code'] ?? ''));
        if ($locCode === '') {
            return ['status' => 'error',
                    'reason' => 'to_location_code is required for txn_type=' . $type];
        }
        $l = db_one('SELECT id FROM locations WHERE code = ?', [$locCode]);
        if (!$l) $l = db_one('SELECT id FROM locations WHERE name = ?', [$locCode]);
        if (!$l) {
            return ['status' => 'error',
                    'reason' => 'Unknown to_location_code "' . $locCode . '"'];
        }
        $toLocationId = (int)$l['id'];
    }

    if ($type === 'send_vendor') {
        $vCode = trim((string)($row['to_vendor_code'] ?? ''));
        if ($vCode === '') {
            return ['status' => 'error',
                    'reason' => 'to_vendor_code is required for txn_type=send_vendor'];
        }
        $v = db_one('SELECT id FROM vendors WHERE code = ?', [$vCode]);
        if (!$v) $v = db_one('SELECT id FROM vendors WHERE name = ?', [$vCode]);
        if (!$v) {
            return ['status' => 'error',
                    'reason' => 'Unknown to_vendor_code "' . $vCode . '"'];
        }
        $toVendorId = (int)$v['id'];
    }

    if ($type === 'send_user') {
        $uName = trim((string)($row['to_user_username'] ?? ''));
        if ($uName === '') {
            return ['status' => 'error',
                    'reason' => 'to_user_username is required for txn_type=send_user'];
        }
        $u = db_one('SELECT id FROM users WHERE username = ?', [$uName]);
        if (!$u) {
            return ['status' => 'error',
                    'reason' => 'Unknown to_user_username "' . $uName . '"'];
        }
        $toUserId = (int)$u['id'];
    }

    $clean = [
        'asset_id'      => $assetId,
        'asset_tag'     => $tag,
        'txn_type'      => $type,
        'to_location_id'=> $toLocationId,
        'to_location_code' => trim((string)($row['to_location_code'] ?? '')),
        'to_vendor_id'  => $toVendorId,
        'to_vendor_code'=> trim((string)($row['to_vendor_code'] ?? '')),
        'to_user_id'    => $toUserId,
        'to_user_username' => trim((string)($row['to_user_username'] ?? '')),
        'notes'         => trim((string)($row['notes'] ?? '')),
    ];
    return ['status' => 'insert', 'data' => $clean];
}

if ($action === 'asset_txn_import_preview') {
    require_permission('asset', 'transact');
    csrf_check();
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/asset.php?action=list'));
    }
    $token  = import_stash($parsed['csv_text'], 'asset_txns');
    $result = import_run_adapter($parsed['rows'], 'asset_txn_import_adapter', false);

    $page_title  = 'Import asset transactions · preview';
    $page_module = 'asset';
    require __DIR__ . '/includes/header.php';
    import_render_preview([
        'title'      => 'Import asset transactions · preview',
        'commit_url' => url('/asset.php?action=asset_txn_import_commit'),
        'cancel_url' => url('/asset.php?action=list'),
        'token'      => $token,
        'upsert'     => false,
        'counts'     => $result['counts'],
        'rows'       => $result['rows'],
        'columns'    => [
            ['asset_tag',         'Asset'],
            ['txn_type',          'Type'],
            ['to_location_code',  'To location'],
            ['to_vendor_code',    'To vendor'],
            ['to_user_username',  'To user'],
            ['notes',             'Notes'],
        ],
    ]);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'asset_txn_import_commit') {
    require_permission('asset', 'transact');
    csrf_check();
    $token = (string)input('token', '');
    $csv = import_unstash($token, 'asset_txns');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/asset.php?action=list'));
    }
    $parsed = import_parse_csv_text($csv);
    if (empty($parsed['ok'])) {
        flash_set('error', 'Re-parse failed: ' . ($parsed['error'] ?? 'unknown'));
        redirect(url('/asset.php?action=list'));
    }
    $result = import_run_adapter($parsed['rows'], 'asset_txn_import_adapter', false);

    $applied = 0; $errors = 0; $errorLines = [];
    $uid = current_user_id();
    foreach ($result['rows'] as $r) {
        if ($r['status'] !== 'insert') continue;
        $d = $r['data'];
        try {
            // Read current state at the moment of application
            $a = db_one('SELECT * FROM assets WHERE id = ?', [$d['asset_id']]);
            if (!$a) throw new Exception('Asset disappeared mid-import');
            $from_loc    = $a['location_id'];
            $from_vendor = $a['current_vendor_id'];
            $from_user   = $a['current_user_id'];

            // Compute new state by txn_type (mirrors txn_save below)
            $newLocation = $a['location_id'];
            $newVendor   = $a['current_vendor_id'];
            $newUser     = $a['current_user_id'];
            $newStatus   = $a['status'];

            switch ($d['txn_type']) {
                case 'move':
                    $newLocation = $d['to_location_id'];
                    $newStatus   = 'active';
                    $newVendor   = null;
                    $newUser     = null;
                    break;
                case 'send_vendor':
                    $newVendor   = $d['to_vendor_id'];
                    $newStatus   = 'with_vendor';
                    $newLocation = null;
                    $newUser     = null;
                    break;
                case 'receive_vendor':
                    $newLocation = $d['to_location_id'];
                    $newVendor   = null;
                    $newStatus   = 'active';
                    break;
                case 'send_user':
                    $newUser     = $d['to_user_id'];
                    $newStatus   = 'with_user';
                    $newLocation = null;
                    $newVendor   = null;
                    break;
                case 'receive_user':
                    $newLocation = $d['to_location_id'];
                    $newUser     = null;
                    $newStatus   = 'active';
                    break;
            }

            db()->beginTransaction();
            db_exec(
                "INSERT INTO asset_transactions
                  (asset_id, txn_type, from_location_id, from_vendor_id, from_user_id,
                   to_location_id, to_vendor_id, to_user_id, actor_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$d['asset_id'], $d['txn_type'], $from_loc, $from_vendor, $from_user,
                 $d['to_location_id'], $d['to_vendor_id'], $d['to_user_id'], $uid,
                 $d['notes'] !== '' ? $d['notes'] : null]
            );
            db_exec(
                "UPDATE assets SET location_id=?, current_vendor_id=?, current_user_id=?, status=?
                  WHERE id=?",
                [$newLocation, $newVendor, $newUser, $newStatus, $d['asset_id']]
            );
            db()->commit();
            $applied++;
        } catch (Exception $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors++;
            $errorLines[] = (int)$r['line'];
            error_log('[asset_txn_import] line ' . $r['line'] . ': ' . $e->getMessage());
        }
    }
    $msg = 'Applied ' . $applied . ' asset transaction' . ($applied === 1 ? '' : 's') . '.';
    if ($errors > 0) {
        $msg .= ' ' . $errors . ' failed (lines: ' . implode(', ', array_slice($errorLines, 0, 20))
              . (count($errorLines) > 20 ? '…' : '') . '). See server log for details.';
    }
    flash_set($errors > 0 ? 'warn' : 'success', $msg);
    redirect(url('/asset.php?action=list'));
}

// ---- Transaction save (move / send / receive) ----
if ($action === 'txn_save') {
    require_permission('asset', 'transact');
    csrf_check();
    $assetId    = (int)input('id', 0);
    $redirectTo = trim((string)input('redirect_to', ''));   // 'list' when called from list-page modal
    $a = db_one('SELECT * FROM assets WHERE id = ?', [$assetId]);
    if (!$a) { flash_set('error', 'Asset not found.'); redirect(url('/asset.php')); }

    $type = (string)input('txn_type');
    $notes = trim((string)input('notes'));
    $valid = ['move','send_vendor','receive_vendor','send_user','receive_user'];
    if (!in_array($type, $valid, true)) {
        flash_set('error', 'Unknown transaction type.');
        redirect($redirectTo === 'list'
            ? url('/asset.php?action=list')
            : url('/asset.php?action=view&id=' . $assetId));
    }

    // Expected-return date — only meaningful when handing the asset OUT
    // (to a vendor or a user). Accept a strict YYYY-MM-DD value; ignore it
    // for moves and check-ins. Stored on the transaction (history) and
    // mirrored onto the asset as its current checkout_due_on (cleared on
    // any non-checkout transaction).
    $isCheckout = in_array($type, ['send_vendor', 'send_user'], true);
    $dueDate = null;
    if ($isCheckout) {
        $dueRaw = trim((string)input('due_date', ''));
        if ($dueRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueRaw)) {
            $ts = strtotime($dueRaw);
            if ($ts !== false && date('Y-m-d', $ts) === $dueRaw) {
                $dueDate = $dueRaw;
            }
        }
    }

    // What we're moving FROM is determined by the asset's current state
    $from_loc    = $a['location_id'];
    $from_vendor = $a['current_vendor_id'];
    $from_user   = $a['current_user_id'];

    $to_loc = $to_vendor = $to_user = null;
    $newLocation = $a['location_id'];
    $newVendor   = null;
    $newUser     = null;
    $newStatus   = 'active';

    $errRedir = $redirectTo === 'list'
        ? url('/asset.php?action=list')
        : url('/asset.php?action=txn&id=' . $assetId);

    switch ($type) {
        case 'move':
            $to_loc = (int)input('to_location_id', 0) ?: null;
            if (!$to_loc) { flash_set('error', 'Pick a destination location.'); redirect($errRedir); }
            $newLocation = $to_loc;
            break;
        case 'send_vendor':
            $to_vendor = (int)input('to_vendor_id', 0) ?: null;
            if (!$to_vendor) { flash_set('error', 'Pick a vendor.'); redirect($errRedir); }
            $to_loc = (int)input('to_location_id', 0) ?: null;  // optional: expected return-to location
            $newVendor = $to_vendor; $newStatus = 'with_vendor';
            $newLocation = null;
            break;
        case 'receive_vendor':
            $to_loc = (int)input('to_location_id', 0) ?: null;
            if (!$to_loc) { flash_set('error', 'Pick a destination location.'); redirect($errRedir); }
            $newLocation = $to_loc; $newVendor = null;
            break;
        case 'send_user':
            $to_user = (int)input('to_user_id', 0) ?: null;
            if (!$to_user) { flash_set('error', 'Pick a user.'); redirect($errRedir); }
            $to_loc = (int)input('to_location_id', 0) ?: null;  // optional: expected return-to location
            $newUser = $to_user; $newStatus = 'with_user';
            $newLocation = null;
            break;
        case 'receive_user':
            $to_loc = (int)input('to_location_id', 0) ?: null;
            if (!$to_loc) { flash_set('error', 'Pick a destination location.'); redirect($errRedir); }
            $newLocation = $to_loc; $newUser = null;
            break;
    }

    db_exec(
        "INSERT INTO asset_transactions
          (asset_id, txn_type, from_location_id, from_vendor_id, from_user_id,
           to_location_id, to_vendor_id, to_user_id, actor_id, notes, due_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$assetId, $type, $from_loc, $from_vendor, $from_user,
         $to_loc, $to_vendor, $to_user, $uid, $notes, $dueDate]
    );
    // checkout_due_on tracks the asset's CURRENT expected return. On a
    // checkout it takes the new due date; on a move/check-in it clears.
    db_exec(
        "UPDATE assets SET location_id=?, current_vendor_id=?, current_user_id=?, status=?, checkout_due_on=? WHERE id=?",
        [$newLocation, $newVendor, $newUser, $newStatus, $dueDate, $assetId]
    );

    flash_set('success', 'Transaction recorded for ' . $a['asset_tag'] . '.');
    redirect($redirectTo === 'list'
        ? url('/asset.php?action=list')
        : url('/asset.php?action=view&id=' . $assetId));
}

// ============================================================
// MODEL EXPORT CSV
// ============================================================
if ($action === 'model_export') {
    require_permission('asset', 'view');
    $rows = db_all(
        'SELECT code, name, category, manufacturer, model_number, notes, is_active
           FROM asset_models ORDER BY code'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="asset_models_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['code', 'name', 'category', 'manufacturer', 'model_number', 'notes', 'is_active']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['code'] ?? '', $r['name'], $r['category'] ?? '',
            $r['manufacturer'] ?? '', $r['model_number'] ?? '',
            str_replace(["\r\n", "\r"], "\n", $r['notes'] ?? ''), $r['is_active'],
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// ASSET LIST EXPORT CSV
// ============================================================
if ($action === 'list_export') {
    require_permission('asset', 'view');
    $rows = db_all(
        'SELECT a.asset_tag, am.code AS model_code, l.code AS location_code,
                a.status, a.notes, a.a_price, p.asset_tag AS parent_asset_tag
           FROM assets a
           LEFT JOIN asset_models am ON am.id = a.model_id
           LEFT JOIN locations l     ON l.id  = a.location_id
           LEFT JOIN assets p        ON p.id  = a.parent_asset_id
          ORDER BY a.asset_tag'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assets_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['asset_tag', 'model_code', 'location_code', 'status', 'notes', 'a_price', 'parent_asset_tag']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['asset_tag'], $r['model_code'] ?? '', $r['location_code'] ?? '',
            $r['status'], $r['notes'] ?? '', $r['a_price'] ?? '', $r['parent_asset_tag'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// MODEL list / new / edit (forms)
// ============================================================
if ($action === 'models') {
    require_permission('asset', 'view');
    require_once __DIR__ . '/includes/datatable.php';

    // Whether migration 060000 has added asset_models.code yet. Used to
    // conditionally show the Code column so un-migrated installs don't
    // get an "Unknown column 'am.code'" error when sorting.
    $hasModelCode = false;
    try {
        $hasModelCode = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'code'"));
    } catch (Exception $e) {}

    $columns = [];
    if ($hasModelCode) {
        $columns[] = ['key'=>'code', 'label'=>'Code', 'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.code'];
    }
    $columns = array_merge($columns, [
        ['key'=>'name',           'label'=>'Name',             'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.name'],
        ['key'=>'category',       'label'=>'Category',         'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.category'],
        ['key'=>'manufacturer',   'label'=>'Manufacturer',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.manufacturer'],
        ['key'=>'model_number',   'label'=>'Model number',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.model_number'],
        ['key'=>'cal_freq_label', 'label'=>'Default cal freq', 'sortable'=>true, 'searchable'=>false,'sql_col'=>'cf.label'],
        ['key'=>'asset_count',    'label'=>'Assets',           'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'is_active',      'label'=>'Status',           'sortable'=>true, 'searchable'=>false,'sql_col'=>'am.is_active',
         'filter' => [
             'type' => 'select',
             'placeholder' => 'all',
             'options' => [
                 ['value' => '1', 'label' => 'Active'],
                 ['value' => '0', 'label' => 'Inactive'],
             ],
         ]],
        ['key'=>'_actions',       'label'=>'Actions',          'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ]);

    $dtCfg = [
        'id'       => 'asset_models',
        'base_sql' => 'SELECT am.*, cf.label AS cal_freq_label,
                              (SELECT COUNT(*) FROM assets a WHERE a.model_id = am.id) AS asset_count
                         FROM asset_models am
                         LEFT JOIN asset_cal_frequencies cf ON cf.id = am.default_cal_frequency_id',
        'columns'  => $columns,
        'default_sort' => ['name', 'asc'],
    ];

    $rowRenderer = function ($m) use ($canModelMgr, $canDelete) {
        $name = $canModelMgr
            ? '<strong><a href="' . h(url('/asset.php?action=model_edit&id=' . (int)$m['id'])) . '">' . h($m['name']) . '</a></strong>'
            : '<strong>' . h($m['name']) . '</strong>';
        $status = $m['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">inactive</span>';

        $actions = '';
        if ($canModelMgr) {
            $actions .= '<a class="btn btn-icon" href="' . h(url('/asset.php?action=model_edit&id=' . (int)$m['id'])) . '" title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a> ';
            $toggleLabel = $m['is_active'] ? 'Deactivate' : 'Activate';
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/asset.php?action=model_toggle_active')) . '">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$m['id'] . '">'
                      . '<button class="btn btn-icon' . ($m['is_active'] ? '' : ' btn-warn') . '" type="submit" title="' . $toggleLabel . '">'
                      . ($m['is_active'] ? '⏸' : '▶') . ' <span class="dt-action-label">' . $toggleLabel . '</span></button></form> ';
        }
        if ($canDelete && (int)$m['asset_count'] === 0) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/asset.php?action=model_delete')) . '"'
                      . ' onsubmit="return confirm(\'Delete model &quot;' . h(addslashes($m['name'])) . '&quot;?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$m['id'] . '">'
                      . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
        } elseif ($canDelete) {
            $actions .= '<span class="muted small" title="' . (int)$m['asset_count'] . ' assets use this model">in use</span>';
        }

        return [
            'code'           => '<code>' . h($m['code'] ?? '') . '</code>',
            'name'           => $name,
            'category'       => h($m['category'] ?: '—'),
            'manufacturer'   => h($m['manufacturer'] ?: '—'),
            'model_number'   => '<code>' . h($m['model_number'] ?: '—') . '</code>',
            'cal_freq_label' => h($m['cal_freq_label'] ?: '—'),
            'asset_count'    => (int)$m['asset_count'],
            'is_active'      => $status,
            '_actions'       => dt_actions_wrap($actions),
        ];
    };

    $dt = data_table_run($dtCfg, $rowRenderer);

    $page_title  = 'Asset models';
    $page_module = 'asset';
    $focus_id    = '';

    $actionsHtml = '<a class="btn btn-ghost btn-sm" href="' . h(url('/asset.php')) . '"'
                 . ' data-shortcut="B" accesskey="b">' . shortcut_label('← Assets', 'B') . '</a>';
    if ($canModelMgr) {
        $actionsHtml .= ' <a class="btn btn-ghost btn-sm" href="' . h(url('/asset.php?action=model_export')) . '"'
                      . ' title="Download all asset models as CSV">⤓ Export CSV</a>';
        $actionsHtml .= ' <button type="button" class="btn btn-ghost btn-sm"'
                      . ' data-open-import="model-import-modal"'
                      . ' title="Import asset models from CSV">⤒ Import CSV</button>';
        $actionsHtml .= ' <a class="btn btn-primary btn-sm" href="' . h(url('/asset.php?action=model_new')) . '"'
                      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New model', 'N') . '</a>';
    }
    $dtCfg['title']        = 'Asset models';
    $dtCfg['actions_html'] = $actionsHtml;

    require __DIR__ . '/includes/header.php';
    ?>
    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    <?php if ($canModelMgr):
        import_modal_html(
            'model-import-modal',
            'Import asset models from CSV',
            url('/asset.php?action=model_import_preview'),
            'Required column: <code>name</code>. '
              . 'Optional: <code>code</code>, <code>category</code>, '
              . '<code>manufacturer</code>, <code>model_number</code>, '
              . '<code>cal_frequency</code> (matches the cal-frequency label), '
              . '<code>notes</code>, <code>is_active</code> (0/1).'
        );
    endif; ?>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

if ($action === 'model_new' || $action === 'model_edit') {
    require_permission('asset', 'manage_model');
    $editing = null;
    if ($action === 'model_edit') {
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM asset_models WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Model not found.'); redirect(url('/asset.php?action=models')); }
    }
    $freqs = asset_lookup('asset_cal_frequencies');
    $page_title  = $editing ? 'Edit model' : 'New model';
    $page_module = 'asset';
    $focus_id    = 'f_name';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit model' : 'New model',
            'subtitle'    => $editing ? $editing['name'] : 'Catalog entry shared across assets',
            'back_href'   => url('/asset.php?action=models'),
            'back_label'  => 'Asset models',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/asset.php?action=models')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>'
              . (($editing && permission_check('asset', 'delete'))
                    ? ' <form method="post" style="display:inline" action="' . h(url('/asset.php?action=model_delete')) . '"'
                      . ' onsubmit="return confirm(\'Delete model &quot;' . h(addslashes($editing['name'])) . '&quot;? Blocked if any asset still uses it.\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$editing['id'] . '">'
                      . '<button class="btn btn-danger btn-sm" type="submit" data-shortcut="D" accesskey="d">' . shortcut_label('Delete', 'D') . '</button></form>'
                    : ''),
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/asset.php?action=model_save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
            <div class="form-grid">
                <?php
                // Code field — present only after migration 060000 has added
                // the asset_models.code column. Readonly preview on New;
                // editable on Edit for users with asset.manage_model.
                $hasModelCodeCol = false;
                try {
                    $hasModelCodeCol = !empty(db_one("SHOW COLUMNS FROM asset_models LIKE 'code'"));
                } catch (Exception $e) {}
                if ($hasModelCodeCol):
                ?>
                <div class="field">
                    <label for="f_mcode">Code</label>
                    <?php if (!$editing):
                        $mdlPreview = asset_model_next_code();
                    ?>
                        <input id="f_mcode" type="text" tabindex="-1" readonly
                               value="<?= h($mdlPreview) ?>"
                               style="font-family: var(--font-mono, monospace); background: var(--surface-alt, #f6f7f9);">
                        <span class="muted small">Auto-generated when you save. Preview shown.</span>
                    <?php else: ?>
                        <input id="f_mcode" tabindex="-1" readonly type="text"
                               value="<?= h($editing['code'] ?? '') ?>"
                               style="font-family: var(--font-mono, monospace); background: var(--surface-alt, #f6f7f9);">
                        <span class="muted small">System-assigned · immutable.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="field span-2"><label for="f_name"><?= shortcut_label('Name','N') ?> *</label>
                    <input id="f_name" name="name" type="text" required tabindex="1"
                           value="<?= h($editing['name'] ?? '') ?>"></div>
                <div class="field">
                    <label for="f_cat"><?= shortcut_label('Category','C') ?></label>
                    <?php
                    // Load asset-type categories if the table exists (post-migration 093000).
                    $catRows = [];
                    try {
                        $catRows = db_all(
                            "SELECT id, parent_id, name FROM categories
                              WHERE type IN ('asset', 'all') AND is_active = 1
                              ORDER BY sort_order, name"
                        );
                    } catch (Exception $e) { /* table absent on legacy installs */ }
                    if ($catRows):
                        // Build hierarchical labels (Parent > Child)
                        $catById = [];
                        foreach ($catRows as $c) { $catById[(int)$c['id']] = $c; }
                        $catLabel = function ($id) use (&$catById, &$catLabel) {
                            if (!isset($catById[$id])) return '';
                            $row = $catById[$id];
                            if (!empty($row['parent_id'])) {
                                $p = $catLabel((int)$row['parent_id']);
                                return ($p !== '' ? $p . ' › ' : '') . $row['name'];
                            }
                            return $row['name'];
                        };
                        $currentCatId = (int)($editing['category_id'] ?? 0);
                    ?>
                        <select id="f_cat" name="category_id" class="no-combobox" tabindex="2">
                            <option value="" <?= $currentCatId === 0 ? 'selected' : '' ?>>— None —</option>
                            <?php foreach ($catRows as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                        <?= $currentCatId === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= h($catLabel((int)$c['id'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="muted small">
                            Manage in <a href="<?= h(url('/categories.php?type=asset')) ?>">Admin → Categories</a>.
                        </span>
                        <?php // Carry the legacy free-text along silently so legacy data stays put ?>
                        <input type="hidden" name="category" value="<?= h($editing['category'] ?? '') ?>">
                    <?php else: ?>
                        <input id="f_cat" name="category" type="text" tabindex="2"
                               value="<?= h($editing['category'] ?? '') ?>">
                        <span class="muted small">Apply the Categories migration to use a managed list.</span>
                    <?php endif; ?>
                </div>
                <div class="field"><label for="f_mfr"><?= shortcut_label('Manufacturer','M') ?></label>
                    <input id="f_mfr" name="manufacturer" type="text" tabindex="3"
                           value="<?= h($editing['manufacturer'] ?? '') ?>"></div>
                <div class="field"><label for="f_mno">Model number</label>
                    <input id="f_mno" name="model_number" type="text" tabindex="4"
                           value="<?= h($editing['model_number'] ?? '') ?>"></div>
                <div class="field"><label for="f_freq">Default cal frequency</label>
                    <?= dropdown('default_cal_frequency_id', $freqs,
                                 $editing['default_cal_frequency_id'] ?? 0, 5, 'f_freq') ?></div>
                <div class="field span-2"><label for="f_notes">Notes</label>
                    <input id="f_notes" name="notes" type="text" tabindex="6"
                           value="<?= h($editing['notes'] ?? '') ?>"></div>
                <div class="field">
                    <label class="nowrap" style="font-weight:normal;">
                        <input type="checkbox" name="is_active" value="1" tabindex="7"
                               <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                        <?= shortcut_label('Active','A') ?>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// ASSET new / edit
// ============================================================
if ($action === 'new' || $action === 'edit') {
    $editing = null;
    if ($action === 'edit') {
        require_permission('asset', 'manage');
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM assets WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Asset not found.'); redirect(url('/asset.php')); }
    } else {
        require_permission('asset', 'create');
    }
    $models    = db_all('SELECT id, name, category, manufacturer, model_number, default_cal_frequency_id FROM asset_models WHERE is_active = 1 ORDER BY name');
    $locations = db_all('SELECT id, code, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name');
    $aliases   = asset_lookup('asset_aliases');
    $freqs     = asset_lookup('asset_cal_frequencies');
    $engraved  = asset_lookup('asset_engraved_options');
    $calOpts   = asset_lookup('asset_calibration_options');
    $checked   = asset_lookup('asset_checked_ok_options');

    $modelMeta = [];
    foreach ($models as $m) {
        $modelMeta[(int)$m['id']] = [
            'category'                 => $m['category'],
            'manufacturer'             => $m['manufacturer'],
            'model_number'             => $m['model_number'],
            'default_cal_frequency_id' => $m['default_cal_frequency_id'] ? (int)$m['default_cal_frequency_id'] : 0,
        ];
    }

    // Build a map of frequency_id -> months for the next-due-date calculator.
    // 'On Demand' has months = NULL -> no auto-calc.
    $freqMonths = [];
    foreach (db_all('SELECT id, months FROM asset_cal_frequencies') as $f) {
        $freqMonths[(int)$f['id']] = $f['months'] !== null ? (int)$f['months'] : null;
    }

    // Identify which calibration option means "Not Required" so the JS can
    // hide the cal-params block when that's selected. Match by label
    // (case-insensitive) so admin-renamed labels still work as long as the
    // text starts with 'not'.
    $calNotReqIds = [];
    foreach ($calOpts as $opt) {
        if (stripos(ltrim($opt['label']), 'not') === 0) {
            $calNotReqIds[] = (int)$opt['id'];
        }
    }

    $page_title  = $editing ? 'Edit asset' : 'New asset';
    $page_module = 'asset';
    $focus_id    = 'f_model_id';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit asset' : 'New asset',
            'subtitle'    => $editing ? $editing['asset_tag'] : 'Register a piece of equipment',
            'back_href'   => url('/asset.php'),
            'back_label'  => 'Assets',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/asset.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/asset.php?action=save')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

        <div class="form-grid">
            <!-- Model + inline "create new" -->
            <div class="field">
                <label for="f_model_id"><?= shortcut_label('Model','M') ?> *</label>
                <div class="field-with-add">
                    <select id="f_model_id" name="model_id" required tabindex="1">
                        <option value="">— Select One —</option>
                        <?php foreach ($models as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                <?= ($editing && (int)$editing['model_id'] === (int)$m['id']) ? 'selected' : '' ?>>
                                <?= h($m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($canModelMgr): ?>
                        <a class="btn btn-success btn-sm" href="<?= h(url('/asset.php?action=model_new')) ?>"
                           title="Create new model">+</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label for="f_asset_tag">Asset ID</label>
                <?php
                // Show the existing ID for edits; for new records show a
                // preview of the next auto-generated value. The actual ID
                // assignment happens server-side at save time so racing
                // creates still resolve to distinct IDs.
                $previewId = $editing ? $editing['asset_tag'] : asset_id_generate();
                ?>
                <input id="f_asset_tag" type="text" tabindex="-1" readonly
                       value="<?= h($previewId) ?>"
                       style="font-family: var(--font-mono, monospace); background: var(--surface-alt, #f6f7f9);">
                <span class="muted small">
                    <?php if ($editing): ?>
                        System-generated · cannot be changed.
                    <?php else: ?>
                        Auto-generated when you save. Preview shown.
                    <?php endif; ?>
                </span>
            </div>

            <div class="field span-2">
                <label for="f_asset_name">Asset Name</label>
                <input id="f_asset_name" name="asset_name" type="text" tabindex="2"
                       value="<?= h($editing['asset_name'] ?? '') ?>">
                <span class="muted small">Optional individual name/code for this specific asset.</span>
            </div>

            <!-- Derived fields (read-only, JS populates from model selection) -->
            <div class="field">
                <label>Category</label>
                <input id="f_category" type="text" readonly value="">
                <span class="muted small">Derived from the chosen model. Change in <a href="<?= h(url('/categories.php?type=asset')) ?>">Categories</a> or edit the model.</span>
            </div>
            <div class="field">
                <label>Manufacturer</label>
                <input id="f_manufacturer" type="text" readonly value="">
            </div>
            <div class="field">
                <label>Model number</label>
                <input id="f_model_number" type="text" readonly value="">
            </div>

            <!-- PID used In + Alias have been removed from the form per
                 product spec. Existing values on edited assets are
                 preserved by submitting them as hidden inputs. New
                 assets simply leave these columns empty. -->
            <input type="hidden" name="pid_used_in" value="<?= h($editing['pid_used_in'] ?? '') ?>">
            <input type="hidden" name="alias_id"    value="<?= (int)($editing['alias_id'] ?? 0) ?: '' ?>">

            <div class="field">
                <label for="f_location"><?= shortcut_label('Location','L') ?></label>
                <select id="f_location" name="location_id" tabindex="4">
                    <option value="">— Select One —</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= (int)$l['id'] ?>"
                            <?= ($editing && (int)$editing['location_id'] === (int)$l['id']) ? 'selected' : '' ?>>
                            <?= h($l['name']) ?> <span class="muted">(<?= h($l['code']) ?>)</span>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field span-2">
                <label for="f_notes">Notes</label>
                <input id="f_notes" name="notes" type="text" tabindex="6"
                       value="<?= h($editing['notes'] ?? '') ?>">
            </div>

            <div class="field">
                <label for="f_price">A_Price</label>
                <input id="f_price" name="a_price" type="number" step="0.01" tabindex="7"
                       value="<?= h($editing['a_price'] ?? '') ?>">
            </div>

            <!-- Calib/AMC dropdown — when set to "Not Required" the
                 calibration frequency / done / due block hides. The JS
                 below watches this select. The "Clear values when hidden"
                 checkbox decides whether existing values are wiped on
                 toggle or preserved (useful when re-enabling later). -->
            <div class="field">
                <label for="f_cal">Calib/AMC</label>
                <?= dropdown('calibration_id', $calOpts, $editing['calibration_id'] ?? 0, 8, 'f_cal') ?>
                <label class="nowrap" style="font-weight: normal; margin-top: 6px; display: block;">
                    <input type="checkbox" id="f_clear_cal">
                    <span class="muted small">Clear calibration values when hidden</span>
                </label>
            </div>

            <!-- Calibration parameters: only meaningful when Calib/AMC is
                 not "Not Required". Wrapped so JS can hide the whole block. -->
            <div id="cal-params" class="field span-2"
                 style="display: contents;">
                <div class="field">
                    <label for="f_freq">Calibration frequency</label>
                    <?= dropdown('cal_frequency_id', $freqs, $editing['cal_frequency_id'] ?? 0, 9, 'f_freq') ?>
                    <span class="muted small">Auto-filled from the chosen model. Override if needed.</span>
                </div>
                <div class="field">
                    <label for="f_done">Calibration done on</label>
                    <input id="f_done" name="cal_done_on" type="date" tabindex="10"
                           value="<?= h($editing['cal_done_on'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_due">Next cal due on</label>
                    <input id="f_due" name="next_cal_due_on" type="date" tabindex="11"
                           value="<?= h($editing['next_cal_due_on'] ?? '') ?>">
                    <span class="muted small">Auto-calculated from frequency + done date. Override if needed.</span>
                </div>
            </div>

            <div class="field">
                <label for="f_engr">Engraved</label>
                <?= dropdown('engraved_id', $engraved, $editing['engraved_id'] ?? 0, 12, 'f_engr') ?>
            </div>
            <div class="field">
                <label for="f_ok">Checked OK</label>
                <?= dropdown('checked_ok_id', $checked, $editing['checked_ok_id'] ?? 0, 13, 'f_ok') ?>
            </div>
            <div class="field">
                <label for="f_parent">Parent asset</label>
                <input id="f_parent" name="parent_asset_id" type="number"
                       placeholder="Asset ID (optional)" tabindex="14"
                       value="<?= h($editing['parent_asset_id'] ?? '') ?>">
                <label class="nowrap" style="font-weight:normal; margin-top: 4px;">
                    <input type="checkbox" name="lock_to_parent" value="1" tabindex="15"
                           <?= !empty($editing['lock_to_parent']) ? 'checked' : '' ?>>
                    Lock to parent
                </label>
            </div>
        </div>

        </form>
    </div><!-- /.form-page -->

    <script>
    // Asset form behaviour:
    //   1. Selecting a Model populates Category / Manufacturer / Model number
    //      (read-only displays), and pre-selects the model's default
    //      calibration frequency if the asset has no frequency yet.
    //   2. Choosing a Calibration frequency or a Calibration-done date
    //      recomputes Next cal due (done + months from the frequency).
    //   3. Setting Calib/AMC to "Not Required" hides freq / done / due.
    (function () {
        var meta        = <?= json_encode($modelMeta) ?>;
        var freqMonths  = <?= json_encode($freqMonths) ?>;
        var calNotReq   = <?= json_encode($calNotReqIds) ?>;

        var selModel = document.getElementById('f_model_id');
        var cat   = document.getElementById('f_category');
        var mfr   = document.getElementById('f_manufacturer');
        var mno   = document.getElementById('f_model_number');
        var fFreq = document.getElementById('f_freq');
        var fDone = document.getElementById('f_done');
        var fDue  = document.getElementById('f_due');
        var fCal  = document.getElementById('f_cal');
        var block = document.getElementById('cal-params');

        // ---- (1) Model -> derived fields + default freq ----
        function applyModel(initial) {
            var v = selModel.value;
            var m = v && meta[v] ? meta[v] : { category:'', manufacturer:'', model_number:'', default_cal_frequency_id:0 };
            cat.value = m.category || '';
            mfr.value = m.manufacturer || '';
            mno.value = m.model_number || '';

            // On model change (not the first paint), if the user hasn't
            // picked a frequency yet, adopt the model's default.
            if (fFreq && m.default_cal_frequency_id) {
                if (initial) {
                    // First paint: only pre-fill if the asset row has no
                    // frequency saved (i.e. dropdown is still on "Select One").
                    if (!fFreq.value) {
                        fFreq.value = String(m.default_cal_frequency_id);
                        recomputeDue();
                    }
                } else {
                    // Active model change: always update unless the user has
                    // explicitly chosen something else. We treat "empty" or
                    // "matches previously-suggested" as still-default.
                    fFreq.value = String(m.default_cal_frequency_id);
                    recomputeDue();
                }
            }
        }

        // ---- (2) Recompute next cal due from freq + done ----
        function recomputeDue() {
            if (!fFreq || !fDone || !fDue) return;
            var freqId = parseInt(fFreq.value || '0', 10);
            var months = freqMonths[freqId];   // may be null (On Demand)
            var done   = fDone.value;
            if (!done || !months) {
                // Don't clobber a manually entered due date with nothing.
                // Only clear if there's no done-date AND no months — i.e.
                // the prerequisites for auto-calc are simply not there.
                return;
            }
            // Parse YYYY-MM-DD and add `months` to it.
            var parts = done.split('-');
            if (parts.length !== 3) return;
            var d = new Date(parseInt(parts[0],10), parseInt(parts[1],10) - 1, parseInt(parts[2],10));
            if (isNaN(d.getTime())) return;
            d.setMonth(d.getMonth() + months);
            // Re-format as YYYY-MM-DD
            var y = d.getFullYear();
            var mo = ('0' + (d.getMonth() + 1)).slice(-2);
            var da = ('0' + d.getDate()).slice(-2);
            fDue.value = y + '-' + mo + '-' + da;
        }

        // ---- (3) Calib/AMC visibility ----
        // When Calib/AMC is set to "Not Required", hide freq/done/due.
        // The "Clear values when hidden" checkbox decides whether the
        // values are cleared on toggle or preserved for later restore.
        function applyCalToggle() {
            if (!fCal || !block) return;
            var v = parseInt(fCal.value || '0', 10);
            var notReq = calNotReq.indexOf(v) !== -1;
            var fields = block.querySelectorAll('.field');
            for (var i = 0; i < fields.length; i++) {
                fields[i].style.display = notReq ? 'none' : '';
            }
            var fClear = document.getElementById('f_clear_cal');
            var shouldClear = fClear && fClear.checked;
            if (notReq && applyCalToggle._userChanged && shouldClear) {
                if (fFreq) fFreq.value = '';
                if (fDone) fDone.value = '';
                if (fDue)  fDue.value  = '';
            }
        }

        // ---- Wire up ----
        if (selModel) {
            selModel.addEventListener('change', function () { applyModel(false); });
        }
        if (fFreq) fFreq.addEventListener('change', recomputeDue);
        if (fDone) fDone.addEventListener('change', recomputeDue);
        if (fCal) {
            fCal.addEventListener('change', function () {
                applyCalToggle._userChanged = true;
                applyCalToggle();
            });
        }

        // Initial paint
        applyModel(true);
        applyCalToggle();
    })();
    </script>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// VIEW single asset (with transaction history)
// ============================================================
if ($action === 'view') {
    require_once __DIR__ . '/includes/_notes.php';
    if (notes_handle_action()) {
        redirect(url('/asset.php?action=view&id=' . (int)input('entity_id', 0)));
    }
    $id = (int)input('id', 0);
    $a = db_one(
        "SELECT a.*,
                am.name AS model_name, am.category, am.manufacturer, am.model_number,
                l.name AS location_name, l.code AS location_code,
                v.name AS vendor_name,
                u.full_name AS user_name,
                cu.full_name AS created_by_name,
                al.label AS alias_label, cf.label AS cal_freq_label,
                eg.label AS engraved_label, co.label AS calibration_label,
                ck.label AS checked_label
           FROM assets a
           LEFT JOIN asset_models am          ON am.id = a.model_id
           LEFT JOIN locations l              ON l.id  = a.location_id
           LEFT JOIN vendors v                ON v.id  = a.current_vendor_id
           LEFT JOIN users u                  ON u.id  = a.current_user_id
           LEFT JOIN users cu                 ON cu.id = a.created_by
           LEFT JOIN asset_aliases al         ON al.id = a.alias_id
           LEFT JOIN asset_cal_frequencies cf ON cf.id = a.cal_frequency_id
           LEFT JOIN asset_engraved_options eg ON eg.id = a.engraved_id
           LEFT JOIN asset_calibration_options co ON co.id = a.calibration_id
           LEFT JOIN asset_checked_ok_options ck ON ck.id = a.checked_ok_id
          WHERE a.id = ?",
        [$id]
    );
    if (!$a) { flash_set('error', 'Asset not found.'); redirect(url('/asset.php')); }

    $txns = db_all(
        "SELECT t.*,
                fl.name AS from_loc_name, tl.name AS to_loc_name,
                fv.name AS from_vendor_name, tv.name AS to_vendor_name,
                fu.full_name AS from_user_name, tu.full_name AS to_user_name,
                ac.full_name AS actor_name
           FROM asset_transactions t
           LEFT JOIN locations fl ON fl.id = t.from_location_id
           LEFT JOIN locations tl ON tl.id = t.to_location_id
           LEFT JOIN vendors fv   ON fv.id = t.from_vendor_id
           LEFT JOIN vendors tv   ON tv.id = t.to_vendor_id
           LEFT JOIN users fu     ON fu.id = t.from_user_id
           LEFT JOIN users tu     ON tu.id = t.to_user_id
           LEFT JOIN users ac     ON ac.id = t.actor_id
          WHERE t.asset_id = ?
          ORDER BY t.at DESC, t.id DESC",
        [$id]
    );
    // Batch-fetch running-note counts for these txn rows so the
    // gear-menu Notes item can show a (N) badge.
    $txnIds = array_map(function ($t) { return (int)$t['id']; }, $txns);
    $txnNoteCounts = notes_counts_for('asset_txn', $txnIds);
    // Attachment counts per txn (attachments live on notes attached to the
    // transaction). Used for the 📎 indicator in the history table.
    $txnAttCounts = [];
    if ($txnIds) {
        $inTxn = implode(',', array_map('intval', $txnIds));
        foreach (db_all(
            "SELECT n.entity_id AS tid, COUNT(na.id) AS c
               FROM notes n
               JOIN note_attachments na ON na.note_id = n.id
              WHERE n.entity_type = 'asset_txn' AND n.is_deleted = 0 AND n.redacted_at IS NULL
                AND n.entity_id IN ($inTxn)
              GROUP BY n.entity_id"
        ) as $r) {
            $txnAttCounts[(int)$r['tid']] = (int)$r['c'];
        }
    }

    // Fetch recent inspections of this asset for the small panel
    // shown below the txn history. Guarded by table existence so
    // a deployment that hasn't run the inspection migration yet
    // still renders the asset view page cleanly.
    $assetInspections = [];
    $hasInspectionTable = db_one(
        "SELECT 1 FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'inspections'"
    );
    if ($hasInspectionTable && permission_check('inspection', 'view')) {
        $assetInspections = db_all(
            "SELECT i.id, i.code, i.inspection_type, i.status, i.due_date,
                    i.planned_at, i.inspected_at, i.approved_at,
                    iu.full_name AS inspected_by_name
               FROM inspections i
               LEFT JOIN users iu ON iu.id = i.inspected_by
              WHERE i.entity_type = 'asset' AND i.entity_id = ?
                AND i.is_deleted = 0
              ORDER BY i.id DESC
              LIMIT 10",
            [$id]
        );
    }

    $page_title  = 'Asset ' . $a['asset_tag'];
    $page_module = 'asset';
    $focus_id    = '';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($a['asset_tag']) ?>
                <?php
                $statusPill = [
                    'active'      => 'pill-active',
                    'archived'    => 'pill-neutral',
                    'with_vendor' => 'pill-warn',
                    'with_user'   => 'pill-info',
                ];
                ?>
                <span class="pill <?= h($statusPill[$a['status']] ?? 'pill-neutral') ?>"><?= h(str_replace('_',' ', $a['status'])) ?></span>
            </h1>
            <p class="muted"><?= h($a['model_name'] ?: '—') ?> ·
                <?= h($a['category'] ?: '—') ?> · <?= h($a['manufacturer'] ?: '—') ?></p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/asset.php')) ?>"
               data-shortcut="B" accesskey="b"><?= shortcut_label('← Back', 'B') ?></a>
            <?= notes_popup_button('asset', (int)$a['id'], 'Notes', 'N') ?>
            <?php if (permission_check('inspection', 'create') && $a['status'] !== 'archived'): ?>
                <a class="btn btn-ghost" href="<?= h(url('/inspection.php?action=new&inspection_type=asset_cal&entity_type=asset&entity_id=' . (int)$a['id'])) ?>"
                   data-shortcut="I" accesskey="i"
                   title="Plan a calibration / inspection record for this asset">
                    <?= shortcut_label('🔍 Inspect', 'I') ?>
                </a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a class="btn btn-ghost" href="<?= h(url('/asset.php?action=edit&id=' . $id)) ?>"
                   data-shortcut="E" accesskey="e"><?= shortcut_label('Edit', 'E') ?></a>
            <?php endif; ?>
            <?php if ($canTransact && $a['status'] !== 'archived'):
                if ($a['status'] === 'with_vendor') {
                    $txnLabel  = 'Check In';
                    $txnPreset = 'receive_vendor';
                } elseif ($a['status'] === 'with_user') {
                    $txnLabel  = 'Check In';
                    $txnPreset = 'receive_user';
                } else {
                    $txnLabel  = 'Check Out';
                    $txnPreset = 'send_vendor';
                }
            ?>
                <a class="btn btn-primary"
                   href="<?= h(url('/asset.php?action=txn&id=' . $id . '&preset=' . $txnPreset)) ?>"
                   data-shortcut="T" accesskey="t"><?= shortcut_label($txnLabel, 'T') ?></a>
            <?php endif; ?>
            <?php if ($canArchive && $a['status'] === 'active'): ?>
                <form method="post" style="display:inline"
                      action="<?= h(url('/asset.php?action=archive')) ?>"
                      onsubmit="return confirm('Mark this asset as inactive (archived)?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button class="btn btn-warn" type="submit">Mark Inactive</button>
                </form>
            <?php endif; ?>
            <?php if ($canArchive && $a['status'] !== 'active'): ?>
                <form method="post" style="display:inline"
                      action="<?= h(url('/asset.php?action=unarchive')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button class="btn btn-ghost" type="submit">Mark Active</button>
                </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" style="display:inline"
                      action="<?= h(url('/asset.php?action=delete')) ?>"
                      onsubmit="return confirm('Permanently delete <?= h(addslashes($a['asset_tag'])) ?>? This destroys its transaction history and cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button class="btn btn-danger" type="submit" data-shortcut="D" accesskey="d"><?= shortcut_label('Delete', 'D') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="form-grid">
                <div class="field"><label>Asset ID</label><div><strong><?= h($a['asset_tag']) ?></strong></div></div>
                <div class="field"><label>Asset Name</label><div><?= ($a['asset_name'] ?? '') !== '' ? h($a['asset_name']) : '—' ?></div></div>
                <div class="field"><label>Model</label><div><?= h($a['model_name'] ?: '—') ?></div></div>
                <div class="field"><label>Category</label><div><?= h($a['category'] ?: '—') ?></div></div>
                <div class="field"><label>Manufacturer</label><div><?= h($a['manufacturer'] ?: '—') ?></div></div>
                <div class="field"><label>Model number</label><div><code><?= h($a['model_number'] ?: '—') ?></code></div></div>
                <div class="field"><label>Location</label><div>
                    <?= h($a['location_name'] ?: '—') ?>
                    <?php if ($a['location_code']): ?> <span class="muted small">(<?= h($a['location_code']) ?>)</span><?php endif; ?>
                </div></div>
                <?php if ($a['vendor_name']): ?>
                    <div class="field"><label>Currently with vendor</label><div><?= h($a['vendor_name']) ?></div></div>
                <?php endif; ?>
                <?php if ($a['user_name']): ?>
                    <div class="field"><label>Currently with user</label><div><?= h($a['user_name']) ?></div></div>
                <?php endif; ?>
                <div class="field"><label>Engraved</label><div><?= h($a['engraved_label'] ?: '—') ?></div></div>
                <div class="field"><label>Calib/AMC</label><div><?= h($a['calibration_label'] ?: '—') ?></div></div>
                <div class="field"><label>Checked OK</label><div><?= h($a['checked_label'] ?: '—') ?></div></div>
                <?php
                // Show the three calibration-parameter fields only when
                // calibration is required. Match "not required" loosely.
                $isNotReq = !empty($a['calibration_label'])
                            && stripos(ltrim($a['calibration_label']), 'not') === 0;
                if (!$isNotReq):
                ?>
                    <div class="field"><label>Cal frequency</label><div><?= h($a['cal_freq_label'] ?: '—') ?></div></div>
                    <div class="field"><label>Cal done on</label><div><?= h($a['cal_done_on'] ?: '—') ?></div></div>
                    <div class="field">
                        <label>Next cal due on</label>
                        <?php
                        $due = $a['next_cal_due_on'];
                        $today = date('Y-m-d');
                        $cls = '';
                        if ($due && $due < $today) $cls = 'text-danger';
                        elseif ($due && $due <= date('Y-m-d', strtotime('+30 days'))) $cls = 'text-warn';
                        ?>
                        <div class="<?= $cls ?>"><?= h($due ?: '—') ?></div>
                    </div>
                <?php endif; ?>
                <div class="field"><label>A_Price</label><div><?= $a['a_price'] !== null ? '₹ ' . number_format($a['a_price'], 2) : '—' ?></div></div>
                <div class="field span-2"><label>Notes</label><div><?= h($a['notes'] ?: '—') ?></div></div>
                <?php if ($a['parent_asset_id']): ?>
                    <div class="field"><label>Parent asset</label><div>
                        <a href="<?= h(url('/asset.php?action=view&id=' . (int)$a['parent_asset_id'])) ?>">#<?= (int)$a['parent_asset_id'] ?></a>
                        <?= !empty($a['lock_to_parent']) ? '<span class="pill pill-info">locked</span>' : '' ?>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 24px;">
        <div class="card-head">
            <h2>Transaction history</h2>
            <span class="muted small"><?= count($txns) ?> event<?= count($txns) === 1 ? '' : 's' ?></span>
        </div>
        <table class="data-table">
            <thead>
            <tr><th>Issued</th><th>Due</th><th>Type</th><th>In / Out</th><th>From</th><th>To</th><th>By</th><th>Notes</th><th class="r">Files</th><th class="r">Actions</th></tr>
            </thead>
            <tbody>
            <?php
            // Check-in / check-out indicator maps.
            $txnCicoLabel = [
                'send_vendor'    => '↑ Check Out',
                'send_user'      => '↑ Check Out',
                'receive_vendor' => '↓ Check In',
                'receive_user'   => '↓ Check In',
            ];
            $txnCicoCls = [
                'send_vendor'    => 'pill-warn',
                'send_user'      => 'pill-warn',
                'receive_vendor' => 'pill-active',
                'receive_user'   => 'pill-active',
            ];
            ?>
            <?php if (!$txns): ?>
                <tr><td colspan="10" class="empty">No transactions yet.</td></tr>
            <?php else: foreach ($txns as $t):
                $from = $t['from_loc_name'] ?: ($t['from_vendor_name'] ?: ($t['from_user_name'] ?: '—'));
                $to   = $t['to_loc_name']   ?: ($t['to_vendor_name']   ?: ($t['to_user_name']   ?: '—'));
                // Build the gear-menu cell: Notes always, plus a
                // "+ Invoice" deep-link when the txn type is qty-
                // increasing AND no invoice is already attached.
                $rowActions = notes_popup_menu_item('asset_txn', (int)$t['id'], 'Notes', $txnNoteCounts[(int)$t['id']] ?? 0);
                if (in_array($t['txn_type'], ['create','receive_vendor','receive_user'], true)
                    && permission_check('invoice', 'manage')) {
                    $rowActions .= '<a href="' . h(url('/invoice.php?action=new&link_type=asset_txn&link_id=' . (int)$t['id']))
                                 . '" title="Create an invoice linked to this transaction">+ Invoice</a>';
                }
                $tt = $t['txn_type'];
            ?>
                <?php
                // Due date only applies to checkout rows (send_user / send_vendor).
                $rowDue = (!empty($t['due_date']) && in_array($tt, ['send_vendor','send_user'], true)) ? (string)$t['due_date'] : '';
                $rowDueCls = '';
                if ($rowDue && $rowDue < date('Y-m-d'))                              $rowDueCls = 'text-danger';
                elseif ($rowDue && $rowDue <= date('Y-m-d', strtotime('+7 days')))   $rowDueCls = 'text-warn';
                $attCount = (int)($txnAttCounts[(int)$t['id']] ?? 0);
                ?>
                <tr>
                    <td class="nowrap"><?= h(dt_display($t['at'])) ?></td>
                    <td class="nowrap"><?php if ($rowDue): ?><span class="<?= $rowDueCls ?>"><?= h($rowDue) ?></span><?php else: ?><span class="muted small">—</span><?php endif; ?></td>
                    <td><code><?= h(str_replace('_',' ', $tt)) ?></code></td>
                    <td>
                        <?php if (isset($txnCicoLabel[$tt])): ?>
                            <span class="pill <?= $txnCicoCls[$tt] ?>">
                                <?= $txnCicoLabel[$tt] ?>
                            </span>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($from) ?></td>
                    <td><?= h($to) ?></td>
                    <td><?= h($t['actor_name'] ?: '—') ?></td>
                    <td class="muted small"><?= h($t['notes'] ?: '') ?></td>
                    <td class="r"><?= note_att_indicator('asset_txn', (int)$t['id'], $attCount) ?></td>
                    <td class="r"><?= dt_actions_wrap($rowActions) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($hasInspectionTable && (permission_check('inspection', 'view') || permission_check('inspection', 'create'))): ?>
    <div class="card" style="margin-top: 24px;">
        <div class="card-head">
            <h2>Inspections</h2>
            <span style="display:flex; gap:8px; align-items:center;">
                <span class="muted small"><?= count($assetInspections) ?> recent</span>
                <?php if (permission_check('inspection', 'create') && $a['status'] !== 'archived'): ?>
                    <a class="btn btn-sm btn-ghost"
                       href="<?= h(url('/inspection.php?action=new&inspection_type=asset_cal&entity_type=asset&entity_id=' . (int)$id)) ?>">
                        + Schedule inspection
                    </a>
                <?php endif; ?>
            </span>
        </div>
        <table class="data-table">
            <thead>
                <tr><th>Code</th><th>Type</th><th>Status</th><th>Due</th><th>Inspector</th><th>Planned</th></tr>
            </thead>
            <tbody>
            <?php if (!$assetInspections): ?>
                <tr><td colspan="6" class="empty">No inspections recorded for this asset.</td></tr>
            <?php else:
                // Inline status pill — same map used by inspection.php.
                $inspStatusMap = [
                    'draft'             => ['Draft', 'neutral'],
                    'in_progress'       => ['In progress', 'info'],
                    'pending_approval'  => ['Pending approval', 'warn'],
                    'passed'            => ['Passed', 'active'],
                    'failed'            => ['Failed', 'danger'],
                    'rework'            => ['Rework', 'warn'],
                    'hold'              => ['On hold', 'warn'],
                    'cancelled'         => ['Cancelled', 'neutral'],
                ];
                $inspTypeMap = [
                    'incoming'       => 'Incoming',
                    'asset_cal'      => 'Asset cal',
                    'finished_goods' => 'Finished',
                    'first_article'  => 'First article',
                    'adhoc'          => 'Ad-hoc',
                ];
                foreach ($assetInspections as $insp):
                    list($pillLabel, $pillCls) = $inspStatusMap[$insp['status']] ?? [$insp['status'], 'neutral'];
                    $typeLabel = $inspTypeMap[$insp['inspection_type']] ?? $insp['inspection_type'];
                    $dueCls = '';
                    if ($insp['due_date'] && $insp['due_date'] < date('Y-m-d')
                        && in_array($insp['status'], ['draft','in_progress','rework','hold','pending_approval'], true)) {
                        $dueCls = 'text-danger';
                    }
            ?>
                <tr>
                    <td><a href="<?= h(url('/inspection.php?action=view&id=' . (int)$insp['id'])) ?>"><strong><?= h($insp['code']) ?></strong></a></td>
                    <td><span class="muted small"><?= h($typeLabel) ?></span></td>
                    <td><span class="pill pill-<?= h($pillCls) ?>"><?= h($pillLabel) ?></span></td>
                    <td class="<?= h($dueCls) ?>"><?= h($insp['due_date'] ?: '—') ?></td>
                    <td><?= $insp['inspected_by_name'] ? h($insp['inspected_by_name']) : '<span class="muted">—</span>' ?></td>
                    <td class="muted small nowrap"><?= h(dt_display($insp['planned_at'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($canTransact): ?>
    <div class="card" style="margin-top: 24px;">
        <div class="card-head"><h2>Archive / unarchive</h2></div>
        <div class="card-body">
            <?php if ($a['status'] !== 'archived'): ?>
                <form method="post" action="<?= h(url('/asset.php?action=archive')) ?>"
                      onsubmit="return confirm('Archive this asset?');"
                      style="display:flex; gap:8px; align-items:center;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input name="notes" type="text" placeholder="Archive notes (optional)" style="flex:1;">
                    <button class="btn btn-danger" type="submit">Archive</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= h(url('/asset.php?action=unarchive')) ?>"
                      style="display:flex; gap:8px; align-items:center;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input name="notes" type="text" placeholder="Unarchive notes (optional)" style="flex:1;">
                    <button class="btn btn-success" type="submit">Unarchive</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php notes_popup_assets(); ?>

    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// TXN form (move / send / receive)
// ============================================================
if ($action === 'txn') {
    require_permission('asset', 'transact');
    $id = (int)input('id', 0);
    $a = db_one('SELECT a.*, l.name AS location_name, v.name AS vendor_name, u.full_name AS user_name
                  FROM assets a
                  LEFT JOIN locations l ON l.id = a.location_id
                  LEFT JOIN vendors v   ON v.id = a.current_vendor_id
                  LEFT JOIN users u     ON u.id = a.current_user_id
                 WHERE a.id = ?', [$id]);
    if (!$a) { flash_set('error', 'Asset not found.'); redirect(url('/asset.php')); }

    // Auto-select the transaction type when arriving from the view-page
    // Check In / Check Out button (preset= query param). Fall back to a
    // sensible default based on current asset status:
    //   • at a location  → Move (location → location)
    //   • with a vendor  → Receive from vendor   (check-in)
    //   • with a user    → Receive back from user (check-in)
    $validPresets = ['move', 'send_vendor', 'receive_vendor', 'send_user', 'receive_user'];
    $txnPreset = (string)input('preset', '');
    if (!in_array($txnPreset, $validPresets, true)) {
        if ($a['status'] === 'with_vendor')   $txnPreset = 'receive_vendor';
        elseif ($a['status'] === 'with_user') $txnPreset = 'receive_user';
        else                                  $txnPreset = 'move'; // asset is at a location
    }
    $locations = db_all('SELECT id, code, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name');
    $vendors   = db_all('SELECT id, code, name FROM vendors   WHERE is_active = 1 ORDER BY name');
    $users     = db_all('SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name');

    $page_title  = 'Transact ' . $a['asset_tag'];
    $page_module = 'asset';
    $focus_id    = 'f_txn_type';

    $subtitleParts = ['Asset ' . $a['asset_tag']];
    if ($a['status'] === 'with_vendor')      $subtitleParts[] = 'at vendor ' . $a['vendor_name'];
    elseif ($a['status'] === 'with_user')    $subtitleParts[] = 'with user ' . $a['user_name'];
    else                                     $subtitleParts[] = 'at ' . ($a['location_name'] ?: '—');
    $subtitle = implode(' · ', $subtitleParts);

    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Asset transaction',
            'subtitle'    => $subtitle,
            'back_href'   => url('/asset.php?action=view&id=' . $id),
            'back_label'  => $a['asset_tag'],
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/asset.php?action=view&id=' . $id)) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/asset.php?action=txn_save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">

            <div class="form-grid">
                <div class="field span-2">
                    <label for="f_txn_type">Transaction type</label>
                    <select id="f_txn_type" name="txn_type" tabindex="1">
                        <?php
                        $txnTypeOptions = [
                            'move'           => 'Move (location → location)',
                            'send_vendor'    => 'Send to vendor',
                            'receive_vendor' => 'Receive from vendor',
                            'send_user'      => 'Hand out to user',
                            'receive_user'   => 'Receive back from user',
                        ];
                        foreach ($txnTypeOptions as $val => $lbl): ?>
                            <option value="<?= h($val) ?>"<?= $val === $txnPreset ? ' selected' : '' ?>>
                                <?= h($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                    // ── Destination fields ───────────────────────────────────
                    // Each txn type shows exactly one destination selector:
                    //   move            → location list
                    //   send_vendor     → vendor list  + due date
                    //   send_user       → user list    + due date
                    //   receive_vendor  → location list (where asset goes back to)
                    //   receive_user    → location list (where asset goes back to)
                    // JS hide/show mirrors this logic on type change.
                    $showLoc = in_array($txnPreset, ['move','receive_vendor','receive_user']);
                    $showVen = ($txnPreset === 'send_vendor');
                    $showUsr = ($txnPreset === 'send_user');
                    $showDue = in_array($txnPreset, ['send_vendor','send_user']);
                ?>

                <div class="field js-loc-target"<?= $showLoc ? '' : ' style="display:none;"' ?>>
                    <label>Destination location</label>
                    <select name="to_location_id" tabindex="2">
                        <option value="">— Select location —</option>
                        <?php foreach ($locations as $l): ?>
                            <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?> (<?= h($l['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field js-vendor-target"<?= $showVen ? '' : ' style="display:none;"' ?>>
                    <label>Vendor</label>
                    <select name="to_vendor_id" tabindex="3">
                        <option value="">— Select vendor —</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?> (<?= h($v['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field js-user-target"<?= $showUsr ? '' : ' style="display:none;"' ?>>
                    <label>User</label>
                    <select name="to_user_id" tabindex="4">
                        <option value="">— Select user —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= h($u['full_name']) ?> (<?= h($u['username']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field js-due-target"<?= $showDue ? '' : ' style="display:none;"' ?>>
                    <label for="f_txn_due">Due date <span class="muted small">(expected return)</span></label>
                    <input id="f_txn_due" name="due_date" type="date" tabindex="5">
                </div>

                <div class="field span-2">
                    <label for="f_txn_notes">Notes</label>
                    <input id="f_txn_notes" name="notes" type="text" tabindex="6">
                </div>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var sel = document.getElementById('f_txn_type');
        var loc = document.querySelector('.js-loc-target');
        var ven = document.querySelector('.js-vendor-target');
        var usr = document.querySelector('.js-user-target');
        var due = document.querySelector('.js-due-target');

        // ── Destination visibility rules ─────────────────────────────────
        // move            → location list  (location → location)
        // send_vendor     → vendor list    + due date
        // send_user       → user list      + due date
        // receive_vendor  → location list  (asset comes back, goes to a location)
        // receive_user    → location list  (asset comes back, goes to a location)
        function apply() {
            var t = sel.value;
            var wantsLoc = (t === 'move' || t === 'receive_vendor' || t === 'receive_user');
            var wantsVen = (t === 'send_vendor');
            var wantsUsr = (t === 'send_user');
            var wantsDue = (t === 'send_vendor' || t === 'send_user');
            loc.style.display = wantsLoc ? '' : 'none';
            ven.style.display = wantsVen ? '' : 'none';
            usr.style.display = wantsUsr ? '' : 'none';
            due.style.display = wantsDue ? '' : 'none';
        }

        sel.addEventListener('change', apply);
        // Apply preset immediately (server-rendered preset value).
        var preset = '<?= h($txnPreset) ?>';
        if (preset) { sel.value = preset; apply(); }
        // Re-apply after a tick to beat browser session-autofill which
        // may fire asynchronously after DOMContentLoaded on some browsers.
        if (preset) {
            setTimeout(function () { sel.value = preset; apply(); }, 0);
        }
    })();
    </script>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// TRANSACTION HISTORY — global asset audit log
// ============================================================
// Lives at /asset.php?action=txn_history. Mirrors the inventory
// transaction-history page (inventory.php?action=txn_history) but
// for asset_transactions: every move / send / receive / archive /
// calibrate / edit event across all assets, with filters by type,
// asset, location, and date.
//
// Permissions: gated on asset.view (anyone who can see the asset
// register can see its transaction history). The 'asset_transactions'
// sidebar entry was registered in migration_20260515_090000_IST
// pointing at action=list&view=transactions, which never actually
// did anything different from the normal list. Migration
// 20260518_094500_IST repoints it here.
//
// Each row's "from → to" cell intelligently picks among location,
// user, or vendor depending on the txn_type — only one of the three
// pairs is populated per row. For 'calibrate' txns the "from → to"
// is empty and we show calibration_done_on / next_cal_due_on
// instead. The gear-menu lets the viewer jump to the asset's main
// view page.
if ($action === 'txn_history') {
    require_permission('asset', 'view');
    require_once __DIR__ . '/includes/datatable.php';

    // Pull every actionable txn type into a single select-filter.
    // Listing them inline rather than enumerating asset_txn types from
    // INFORMATION_SCHEMA so the order stays the order I want for
    // readability rather than the order the ENUM happens to declare.
    $typeFilterOptions = [
        ['value' => 'create',          'label' => 'Create'],
        ['value' => 'edit',            'label' => 'Edit'],
        ['value' => 'move',            'label' => 'Move'],
        ['value' => 'send_vendor',     'label' => 'Send to vendor'],
        ['value' => 'receive_vendor',  'label' => 'Receive from vendor'],
        ['value' => 'send_user',       'label' => 'Issue to user'],
        ['value' => 'receive_user',    'label' => 'Return from user'],
        ['value' => 'archive',         'label' => 'Archive'],
        ['value' => 'restore',         'label' => 'Restore'],
        ['value' => 'calibrate',       'label' => 'Calibrate'],
    ];

    $dtCfg = [
        'id'       => 'asset_txn_history',
        // The base_sql carries the join graph. We join three sets of
        // location / user / vendor — one for the "from" side and one
        // for the "to" side — because a single row can name (say) a
        // from_user and a to_vendor (a send-to-vendor that pulls the
        // asset back from whoever had it). Aliases f_* and t_* keep
        // the column list legible.
        'base_sql' => "SELECT t.id, t.at, t.txn_type,
                              t.asset_id, t.notes, t.actor_id,
                              t.from_location_id, t.to_location_id,
                              t.from_user_id, t.to_user_id,
                              t.from_vendor_id, t.to_vendor_id,
                              t.calibration_done_on, t.next_cal_due_on,
                              a.asset_tag,
                              am.name AS model_name,
                              f_loc.name AS from_loc_name, t_loc.name AS to_loc_name,
                              f_u.full_name AS from_user_name, t_u.full_name AS to_user_name,
                              f_v.name      AS from_vendor_name, t_v.name      AS to_vendor_name,
                              act.full_name AS actor_name
                         FROM asset_transactions t
                         JOIN assets a       ON a.id = t.asset_id
                         LEFT JOIN asset_models am ON am.id = a.model_id
                         LEFT JOIN locations f_loc ON f_loc.id = t.from_location_id
                         LEFT JOIN locations t_loc ON t_loc.id = t.to_location_id
                         LEFT JOIN users     f_u   ON f_u.id   = t.from_user_id
                         LEFT JOIN users     t_u   ON t_u.id   = t.to_user_id
                         LEFT JOIN vendors   f_v   ON f_v.id   = t.from_vendor_id
                         LEFT JOIN vendors   t_v   ON t_v.id   = t.to_vendor_id
                         LEFT JOIN users     act   ON act.id   = t.actor_id",
        'columns'  => [
            ['key'=>'at',           'label'=>'When',     'sortable'=>true,  'searchable'=>false, 'sql_col'=>'t.at',
             'td_class'=>'nowrap'],
            ['key'=>'txn_type',     'label'=>'Type',     'sortable'=>true,  'sql_col'=>'t.txn_type',
             'filter' => ['type'=>'select', 'placeholder'=>'all', 'options'=>$typeFilterOptions]],
            ['key'=>'asset_tag',    'label'=>'Asset',    'sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.asset_tag'],
            ['key'=>'model_name',   'label'=>'Model',    'sortable'=>true,  'searchable'=>true,  'sql_col'=>'am.name'],
            ['key'=>'from_to',      'label'=>'From → To','sortable'=>false, 'searchable'=>true,
             // The from/to label search composes all six possible names
             // so typing a vendor name finds rows where that vendor
             // appears on either side.
             'sql_col'=>"CONCAT_WS(' ', f_loc.name, t_loc.name, f_u.full_name, t_u.full_name, f_v.name, t_v.name)"],
            ['key'=>'inv_linked',   'label'=>'Linked',   'sortable'=>false, 'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'inv_unlinked', 'label'=>'Unlinked', 'sortable'=>false, 'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'cal_info',     'label'=>'Calibration', 'sortable'=>false, 'searchable'=>false],
            ['key'=>'notes',        'label'=>'Notes',    'sortable'=>false, 'searchable'=>true,  'sql_col'=>'t.notes',
             'td_class'=>'muted small'],
            ['key'=>'actor_name',   'label'=>'By',       'sortable'=>false, 'searchable'=>false],
            ['key'=>'_actions',     'label'=>'Actions',  'sortable'=>false, 'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r nowrap'],
        ],
        'default_sort' => ['at', 'desc'],
    ];

    $rowRenderer = function ($r) {
        $type = $r['txn_type'];
        // Pill colour matches the semantic feel of the event. Receive
        // events are positive (green-ish), sends/archives are warning
        // (yellow-ish), edits/info are neutral.
        $pillClass = [
            'create'         => 'active',
            'edit'           => 'neutral',
            'move'           => 'info',
            'send_vendor'    => 'warn',
            'receive_vendor' => 'active',
            'send_user'      => 'warn',
            'receive_user'   => 'active',
            'archive'        => 'warn',
            'restore'        => 'active',
            'calibrate'      => 'info',
        ][$type] ?? 'neutral';
        // Human-readable type label inside the pill — the raw enum
        // values like 'send_vendor' aren't great to look at.
        $typeLabel = [
            'create'         => 'create',
            'edit'           => 'edit',
            'move'           => 'move',
            'send_vendor'    => 'send → vendor',
            'receive_vendor' => 'receive ← vendor',
            'send_user'      => 'issue → user',
            'receive_user'   => 'return ← user',
            'archive'        => 'archive',
            'restore'        => 'restore',
            'calibrate'      => 'calibrate',
        ][$type] ?? $type;

        // Check-in / check-out indicator shown below the type pill.
        $cicoMap = [
            'send_vendor'    => ['↑ Check Out', 'warn'],
            'send_user'      => ['↑ Check Out', 'warn'],
            'receive_vendor' => ['↓ Check In',  'active'],
            'receive_user'   => ['↓ Check In',  'active'],
        ];
        $cicoPill = isset($cicoMap[$type])
            ? ' <span class="pill pill-' . $cicoMap[$type][1] . '" style="font-size:10px;">'
              . $cicoMap[$type][0] . '</span>'
            : '';

        $typePill = '<span class="pill pill-' . $pillClass . '">' . h($typeLabel) . '</span>' . $cicoPill;

        // Build the from→to cell. Whichever of the three pairs has
        // a non-null value gets rendered. For calibrate / archive /
        // restore / create / edit there's typically no from→to to
        // show; we keep the cell empty rather than emit "— → —"
        // which is just noise.
        $fromLbl = null; $toLbl = null;
        if ($r['from_loc_name']    !== null) $fromLbl = '📍 ' . h($r['from_loc_name']);
        elseif ($r['from_user_name'] !== null) $fromLbl = '👤 ' . h($r['from_user_name']);
        elseif ($r['from_vendor_name'] !== null) $fromLbl = '🏢 ' . h($r['from_vendor_name']);
        if ($r['to_loc_name']    !== null) $toLbl = '📍 ' . h($r['to_loc_name']);
        elseif ($r['to_user_name'] !== null) $toLbl = '👤 ' . h($r['to_user_name']);
        elseif ($r['to_vendor_name'] !== null) $toLbl = '🏢 ' . h($r['to_vendor_name']);
        if ($fromLbl !== null && $toLbl !== null) {
            $fromTo = $fromLbl . ' <span class="muted">→</span> ' . $toLbl;
        } elseif ($toLbl !== null) {
            $fromTo = '<span class="muted">→</span> ' . $toLbl;
        } elseif ($fromLbl !== null) {
            $fromTo = $fromLbl . ' <span class="muted">→</span>';
        } else {
            $fromTo = '<span class="muted">—</span>';
        }

        // Calibration column: only meaningful for txn_type='calibrate',
        // where we recorded the date completed and the next due date.
        $calInfo = '<span class="muted">—</span>';
        if ($r['calibration_done_on'] || $r['next_cal_due_on']) {
            $parts = [];
            if ($r['calibration_done_on']) {
                $parts[] = 'done ' . h($r['calibration_done_on']);
            }
            if ($r['next_cal_due_on']) {
                $parts[] = 'next ' . h($r['next_cal_due_on']);
            }
            $calInfo = '<span class="small">' . implode(' · ', $parts) . '</span>';
        }

        $assetLink = '<a href="' . h(url('/asset.php?action=view&id=' . (int)$r['asset_id'])) . '">'
                   . '<code>' . h($r['asset_tag']) . '</code></a>';

        // Linked / Unlinked qty against invoices. Only meaningful for
        // qty-increasing txn types (create / receive_vendor / receive_user)
        // — other types (move, send_*, edit, archive, etc.) can't be
        // invoiced, so show n/a to avoid implying zero-unlinked when
        // really the row isn't invoiceable. Helpers from
        // includes/_invoice_links.php.
        $invoiceableTypes = ['create','receive_vendor','receive_user'];
        if (in_array($r['txn_type'], $invoiceableTypes, true)) {
            $aLinked   = invoice_link_txn_qty_linked('asset', (int)$r['id']);
            $aUnlinked = invoice_link_txn_qty_unlinked('asset', (int)$r['id']);
            $fmtQ = function ($v) {
                return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
            };
            $invLinked   = $aLinked   > 0 ? '<strong style="color:#059669">' . h($fmtQ($aLinked))   . '</strong>' : '<span class="muted">0</span>';
            $invUnlinked = $aUnlinked > 0 ? '<strong style="color:#b45309">' . h($fmtQ($aUnlinked)) . '</strong>' : '<span class="muted">0</span>';
        } else {
            $invLinked   = '<span class="muted">—</span>';
            $invUnlinked = '<span class="muted">—</span>';
        }

        $viewUrl = url('/asset.php?action=view&id=' . (int)$r['asset_id']);
        $actions = '<a class="btn btn-icon" href="' . h($viewUrl) . '"'
                 . ' title="View asset" aria-label="View asset">'
                 . '👁 <span class="dt-action-label">View asset</span></a>';

        return [
            'at'           => h(dt_display($r['at'])),
            'txn_type'     => $typePill,
            'asset_tag'    => $assetLink,
            'model_name'   => h($r['model_name'] ?: '—'),
            'from_to'      => $fromTo,
            'inv_linked'   => $invLinked,
            'inv_unlinked' => $invUnlinked,
            'cal_info'     => $calInfo,
            'notes'        => h($r['notes'] ?: ''),
            'actor_name'   => h($r['actor_name'] ?: '—'),
            '_actions'     => dt_actions_wrap($actions),
        ];
    };

    // Run the query NOW so we have $dt for both the JSON-AJAX branch
    // and the page render branch below.
    $dt = data_table_query($dtCfg);

    // Handle the dt_format=json case (SPA-style page-internal swap)
    // the same way the inventory txn_history page does.
    if ((string)input('dt_format', '') === 'json') {
        ob_start();
        data_table_render_rows($dtCfg, $dt, $rowRenderer);
        $rowsHtml = ob_get_clean();
        ob_start();
        data_table_render_pager($dt);
        $pagerHtml = ob_get_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true, 'rows_html' => $rowsHtml, 'pager_html' => $pagerHtml,
            'total' => (int)$dt['total'], 'page' => (int)$dt['page'],
            'pages' => (int)$dt['pages'], 'page_size' => (int)$dt['page_size'],
            'sort' => $dt['sort'], 'dir' => $dt['dir'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $page_title  = 'Asset transaction history';
    $page_module = 'asset_transactions';
    $dtCfg['title'] = 'Asset transaction history';
    $dtCfg['actions_html'] = '<a class="btn btn-ghost btn-sm" href="'
                           . h(url('/asset.php?action=list')) . '">← Assets</a>';
    require __DIR__ . '/includes/header.php';
    data_table_render($dtCfg, $dt, $rowRenderer);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// LIST (full)
// ============================================================
if ($action === 'list') {
    require_once __DIR__ . '/includes/datatable.php';

    $dtCfg = [
        'id'       => 'assets',
        'base_sql' => 'SELECT a.*, am.name AS model_name, am.category, am.manufacturer,
                              l.name AS location_name, v.name AS vendor_name, u.full_name AS user_name,
                              cf.label AS cal_freq_label, eg.label AS engraved_label,
                              co.label AS calibration_label, ck.label AS checked_label,
                              (SELECT t.at FROM asset_transactions t
                                WHERE t.asset_id = a.id AND t.txn_type IN ("send_vendor","send_user")
                                ORDER BY t.at DESC, t.id DESC LIMIT 1) AS checkout_issued_at,
                              (SELECT COUNT(na.id) FROM note_attachments na
                                 JOIN notes n ON n.id = na.note_id
                                WHERE n.entity_type = "asset" AND n.entity_id = a.id AND n.is_deleted = 0 AND n.redacted_at IS NULL) AS att_count
                         FROM assets a
                         LEFT JOIN asset_models am ON am.id = a.model_id
                         LEFT JOIN locations l     ON l.id  = a.location_id
                         LEFT JOIN vendors v       ON v.id  = a.current_vendor_id
                         LEFT JOIN users u         ON u.id  = a.current_user_id
                         LEFT JOIN asset_cal_frequencies cf     ON cf.id = a.cal_frequency_id
                         LEFT JOIN asset_engraved_options eg    ON eg.id = a.engraved_id
                         LEFT JOIN asset_calibration_options co ON co.id = a.calibration_id
                         LEFT JOIN asset_checked_ok_options ck  ON ck.id = a.checked_ok_id',
        'columns'  => [
            ['key'=>'asset_tag',       'label'=>'Asset ID',          'sortable'=>true, 'searchable'=>true, 'sql_col'=>'a.asset_tag',
             'sort_sql'=>'CAST(SUBSTRING_INDEX(a.asset_tag, \'-\', -1) AS UNSIGNED)'],
            ['key'=>'model_name',      'label'=>'Model',             'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.name'],
            ['key'=>'asset_name',      'label'=>'Asset Name',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'a.asset_name'],
            ['key'=>'category',        'label'=>'Category',          'sortable'=>true, 'searchable'=>true, 'sql_col'=>'am.category'],
            ['key'=>'holder',          'label'=>'Location / holder', 'sortable'=>false,'searchable'=>true, 'sql_col'=>'CONCAT_WS(" ", l.name, v.name, u.full_name)'],
            // Issued / Due back — set when the asset is checked out; only
            // meaningful while it's with a vendor/user.
            ['key'=>'checkout_issued_on', 'label'=>'Issued',         'sortable'=>false,'searchable'=>false],
            ['key'=>'checkout_due_on', 'label'=>'Due back',          'sortable'=>true, 'searchable'=>true, 'sql_col'=>'a.checkout_due_on'],
            // Next cal due is now text-filterable. The column stores
            // YYYY-MM-DD so typing '2026-03' or '03-14' both work via LIKE.
            ['key'=>'next_cal_due_on', 'label'=>'Next cal due',      'sortable'=>true, 'searchable'=>true, 'sql_col'=>'a.next_cal_due_on'],
            // Calibration / custom-field columns imported from old inventory.
            ['key'=>'cal_done_on',     'label'=>'Cal done on',       'sortable'=>true, 'searchable'=>true, 'sql_col'=>'a.cal_done_on'],
            ['key'=>'cal_freq_label',  'label'=>'Cal frequency',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'cf.label'],
            ['key'=>'calibration_label','label'=>'Calib/AMC',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'co.label'],
            ['key'=>'engraved_label',  'label'=>'Engraved',          'sortable'=>true, 'searchable'=>true, 'sql_col'=>'eg.label'],
            ['key'=>'checked_label',   'label'=>'Checked OK',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'ck.label'],
            ['key'=>'a_price',         'label'=>'A_Price',           'sortable'=>true, 'searchable'=>true, 'sql_col'=>'a.a_price', 'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'notes',           'label'=>'Notes',             'sortable'=>false,'searchable'=>true, 'sql_col'=>'a.notes'],
            // Status uses a select dropdown in the filter row (exact match).
            ['key'=>'status',          'label'=>'Status',            'sortable'=>true, 'sql_col'=>'a.status',
             'filter' => [
                 'type' => 'select',
                 'placeholder' => 'all',
                 'options' => [
                     ['value' => 'active',      'label' => 'Active'],
                     ['value' => 'with_vendor', 'label' => 'With vendor'],
                     ['value' => 'with_user',   'label' => 'With user'],
                     ['value' => 'archived',    'label' => 'Archived'],
                 ],
             ]],
            ['key'=>'att_count',       'label'=>'Files',             'sortable'=>false,'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'_actions',        'label'=>'Actions',           'sortable'=>false,'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r nowrap'],
        ],
        'default_sort' => ['asset_tag', 'asc'],
    ];

    $canCreateInspection = permission_check('inspection', 'create');

    $rowRenderer = function ($a) use ($canCreate, $canManage, $canDelete, $canCreateInspection, $canTransact) {
        $today = date('Y-m-d');
        $due   = $a['next_cal_due_on'];
        $dueCls = '';
        if ($due && $due < $today)                                   $dueCls = 'text-danger';
        elseif ($due && $due <= date('Y-m-d', strtotime('+30 days'))) $dueCls = 'text-warn';

        // When checked out, show who has the asset (vendor / user).
        // When in stock, show the physical location.
        if ($a['status'] === 'with_vendor') {
            $holder = $a['vendor_name'] ?: '—';
        } elseif ($a['status'] === 'with_user') {
            $holder = $a['user_name'] ?: '—';
        } else {
            $holder = $a['location_name'] ?: '—';
        }

        // Issued / Due-back dates: only meaningful while checked out.
        $isOut    = ($a['status'] === 'with_vendor' || $a['status'] === 'with_user');
        $coIssued = $isOut && !empty($a['checkout_issued_at']) ? substr((string)$a['checkout_issued_at'], 0, 10) : '';
        // Always show checkout_due_on when a value is stored, regardless of
        // status. Imported assets from old inventory arrive as 'active' but
        // may still carry a due-back date from when they were last checked out.
        $coDue    = $a['checkout_due_on'] ?? '';
        $coDueCls = '';
        if ($coDue && $coDue < $today)                                    $coDueCls = 'text-danger';
        elseif ($coDue && $coDue <= date('Y-m-d', strtotime('+7 days')))  $coDueCls = 'text-warn';

        // Clickable attachment indicator (📎 + count). 1 → opens directly,
        // >1 → popup of filenames. See note_att_indicator()/_assets().
        $attHtml = note_att_indicator('asset', (int)$a['id'], (int)($a['att_count'] ?? 0));

        $statusPill = $a['status'] === 'active' ? 'active'
                    : ($a['status'] === 'archived' ? 'neutral'
                    : ($a['status'] === 'with_vendor' ? 'warn' : 'info'));

        $actions  = '<a class="btn btn-icon" href="' . h(url('/asset.php?action=view&id=' . (int)$a['id'])) . '" title="Open" aria-label="Open">👁 <span class="dt-action-label">Open</span></a> ';
        if ($canManage) {
            $actions .= '<a class="btn btn-icon" href="' . h(url('/asset.php?action=edit&id=' . (int)$a['id'])) . '" title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a> ';
        }
        if ($canTransact && $a['status'] !== 'archived') {
            if ($a['status'] === 'with_vendor') {
                $txnBtnLabel = 'Check In'; $txnBtnPreset = 'receive_vendor'; $txnBtnIcon = '↓';
            } elseif ($a['status'] === 'with_user') {
                $txnBtnLabel = 'Check In'; $txnBtnPreset = 'receive_user';   $txnBtnIcon = '↓';
            } else {
                $txnBtnLabel = 'Check Out'; $txnBtnPreset = 'move';          $txnBtnIcon = '↑';
            }
            $actions .= '<button type="button" class="btn btn-icon asset-txn-trigger"'
                      . ' data-id="' . (int)$a['id'] . '"'
                      . ' data-tag="' . h($a['asset_tag']) . '"'
                      . ' data-preset="' . $txnBtnPreset . '"'
                      . ' data-label="' . h($txnBtnLabel) . '"'
                      . ' title="' . h($txnBtnLabel) . '" aria-label="' . h($txnBtnLabel) . '">'
                      . $txnBtnIcon . ' <span class="dt-action-label">' . h($txnBtnLabel) . '</span></button> ';
        }
        if ($canCreate) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/asset.php?action=clone')) . '"'
                      . ' onsubmit="return confirm(\'Clone ' . h(addslashes($a['asset_tag']))
                      . ' to a fresh asset? A new tag will be generated; location, holder, and calibration dates will be cleared.\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$a['id'] . '">'
                      . '<button class="btn btn-icon" type="submit" title="Clone asset" aria-label="Clone asset">⎘ <span class="dt-action-label">Clone</span></button></form>';
        }
        if ($canDelete && $a['status'] === 'archived') {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/asset.php?action=delete')) . '"'
                      . ' onsubmit="return confirm(\'Permanently delete ' . h(addslashes($a['asset_tag'])) . '?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$a['id'] . '">'
                      . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
        }
        // Append a Notes action — opens the notes modal for this asset.
        $actions .= notes_popup_menu_item('asset', (int)$a['id']);
        // Inspection module integrations: plan an inspection or create
        // a template pre-linked to this asset.
        if ($canCreateInspection && $a['status'] !== 'archived') {
            $actions .= ' <a class="btn btn-icon"'
                     . ' href="' . h(url('/inspection.php?action=new&inspection_type=asset_cal&entity_type=asset&entity_id=' . (int)$a['id'])) . '"'
                     . ' title="Plan an inspection for this asset" aria-label="Inspect">'
                     . '🔍 <span class="dt-action-label">Inspect</span></a>';
            $actions .= ' <a class="btn btn-icon"'
                     . ' href="' . h(url('/inspection.php?action=template_new&target_entity_type=asset&target_entity_id=' . (int)$a['id'])) . '"'
                     . ' title="Create an inspection template linked to this asset" aria-label="Add inspection template">'
                     . '📋 <span class="dt-action-label">+ Inspection template</span></a>';
        }

        return [
            'asset_tag'       => '<strong><a href="' . h(url('/asset.php?action=view&id=' . (int)$a['id'])) . '">' . h($a['asset_tag']) . '</a></strong>',
            'model_name'      => h($a['model_name'] ?: '—'),
            'asset_name'      => $a['asset_name'] !== null && $a['asset_name'] !== '' ? h($a['asset_name']) : '<span class="muted">—</span>',
            'category'        => h($a['category'] ?: '—'),
            'holder'             => h($holder),
            'checkout_issued_on' => $coIssued !== '' ? h($coIssued) : '<span class="muted small">—</span>',
            'checkout_due_on'    => '<span class="' . $coDueCls . '">' . h($coDue ?: '—') . '</span>',
            'att_count'          => $attHtml,
            'next_cal_due_on' => '<span class="' . $dueCls . '">' . h($due ?: '—') . '</span>',
            'cal_done_on'     => h($a['cal_done_on'] ?: '—'),
            'cal_freq_label'  => h($a['cal_freq_label'] ?: '—'),
            'calibration_label' => h($a['calibration_label'] ?: '—'),
            'engraved_label'  => h($a['engraved_label'] ?: '—'),
            'checked_label'   => h($a['checked_label'] ?: '—'),
            'a_price'         => $a['a_price'] !== null && $a['a_price'] !== '' ? '₹ ' . number_format((float)$a['a_price'], 2) : '<span class="muted">—</span>',
            'notes'           => $a['notes'] !== null && $a['notes'] !== '' ? h($a['notes']) : '<span class="muted">—</span>',
            'status'          => '<span class="pill pill-' . $statusPill . '">' . h(str_replace('_', ' ', $a['status'])) . '</span>',
            '_actions'        => dt_actions_wrap($actions),
        ];
    };

    $dt = data_table_run($dtCfg, $rowRenderer);

    // Lookup data for the inline transact modal.
    $txnLocations = $txnVendors = $txnUsers = [];
    if ($canTransact) {
        $txnLocations = db_all('SELECT id, code, name FROM locations WHERE is_active = 1 ORDER BY sort_order, name');
        $txnVendors   = db_all('SELECT id, code, name FROM vendors   WHERE is_active = 1 ORDER BY name');
        $txnUsers     = db_all('SELECT id, full_name FROM users       WHERE is_active = 1 ORDER BY full_name');
    }

    $page_title  = 'All assets';
    $page_module = 'asset';
    $focus_id    = '';

    $actionsHtml = '';
    if ($canCreate) {
        $actionsHtml = '<a class="btn btn-ghost btn-sm" href="' . h(url('/asset.php?action=list_export')) . '"'
                     . ' title="Download all assets as CSV">⤓ Export CSV</a> ';
        $actionsHtml .= '<button type="button" class="btn btn-ghost btn-sm"'
                      . ' data-open-import="asset-import-modal"'
                      . ' title="Import assets from CSV">⤒ Import CSV</button> ';
        $actionsHtml .= '<a class="btn btn-primary btn-sm" href="' . h(url('/asset.php?action=new')) . '"'
                      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New asset', 'N') . '</a>';
        $actionsHtml .= ' <a class="btn btn-ghost btn-sm" href="' . h(url('/old_inventory_import.php')) . '"'
                      . ' title="Import assets from old inventory_live database">⤒ Import Old Inventory</a>';
    }
    if ($canTransact) {
        // Append after the create-side buttons so the order reads
        // "Import assets · New asset · Import transactions".
        $actionsHtml .= ' <button type="button" class="btn btn-ghost btn-sm"'
                     . ' data-open-import="asset-txn-import-modal"'
                     . ' title="Import asset transactions (move / send / receive) from CSV">⤒ Import transactions</button>';
    }
    $dtCfg['title']        = 'All assets';
    $dtCfg['actions_html'] = $actionsHtml;

    require __DIR__ . '/includes/header.php';
    ?>
    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>

    <?php if ($canTransact): ?>
    <!-- ── Inline Transact modal ──────────────────────────────────── -->
    <div id="asset-txn-modal"
         style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,.45);"
         role="dialog" aria-modal="true" aria-labelledby="asset-txn-modal-title">
        <div style="background:var(--surface); border-radius:8px; max-width:460px; width:calc(100% - 32px);
                    margin:80px auto; padding:24px 26px 20px; position:relative;
                    box-shadow:0 8px 32px rgba(0,0,0,.28);">
            <button type="button" id="asset-txn-modal-close" aria-label="Close"
                    style="position:absolute;top:12px;right:14px;background:none;border:none;
                           font-size:20px;line-height:1;cursor:pointer;color:var(--text-muted);">✕</button>
            <h3 id="asset-txn-modal-title" style="margin:0 0 18px; font-size:16px; font-weight:600;">
                <span id="asset-txn-modal-action">Check Out</span> — <span id="asset-txn-modal-tag" style="color:var(--primary,#2563eb);"></span>
            </h3>

            <form id="asset-txn-modal-form" method="post" autocomplete="off"
                  action="<?= h(url('/asset.php?action=txn_save')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id"          id="asset-txn-modal-id">
                <input type="hidden" name="redirect_to" value="list">

                <div class="field">
                    <label for="modal-txn-type">Transaction type</label>
                    <select id="modal-txn-type" name="txn_type" autocomplete="off" class="no-combobox">
                        <option value="move">Move (location → location)</option>
                        <option value="send_vendor">Send to vendor</option>
                        <option value="receive_vendor">Receive from vendor</option>
                        <option value="send_user">Hand out to user</option>
                        <option value="receive_user">Receive back from user</option>
                    </select>
                </div>

                <div class="field" id="modal-loc-field" style="display:none;">
                    <label>Destination location</label>
                    <select name="to_location_id">
                        <option value="">— Select —</option>
                        <?php foreach ($txnLocations as $l): ?>
                            <option value="<?= (int)$l['id'] ?>">
                                <?= h($l['name']) ?> (<?= h($l['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" id="modal-vendor-field" style="display:none;">
                    <label>Vendor</label>
                    <select name="to_vendor_id" class="no-combobox">
                        <option value="">— Select —</option>
                        <?php foreach ($txnVendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" id="modal-user-field" style="display:none;">
                    <label>User</label>
                    <select name="to_user_id" class="no-combobox">
                        <option value="">— Select —</option>
                        <?php foreach ($txnUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= h($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" id="modal-due-field" style="display:none;">
                    <label for="modal-txn-due">Due date <span class="muted small">(expected return)</span></label>
                    <input id="modal-txn-due" name="due_date" type="date">
                </div>

                <div class="field">
                    <label for="modal-txn-notes">Notes</label>
                    <input id="modal-txn-notes" name="notes" type="text" maxlength="255"
                           placeholder="Optional">
                </div>

                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                    <button type="button" id="asset-txn-modal-cancel"
                            class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save transaction</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var modal   = document.getElementById('asset-txn-modal');
        var idInp   = document.getElementById('asset-txn-modal-id');
        var tagSpan = document.getElementById('asset-txn-modal-tag');
        var typeSel = document.getElementById('modal-txn-type');
        var locF    = document.getElementById('modal-loc-field');
        var venF    = document.getElementById('modal-vendor-field');
        var usrF    = document.getElementById('modal-user-field');
        var dueF    = document.getElementById('modal-due-field');
        var dueInp  = document.getElementById('modal-txn-due');
        var notesInp= document.getElementById('modal-txn-notes');

        function applyType(t) {
            if (t === undefined) {
                var ts = document.getElementById('modal-txn-type');
                t = ts ? ts.value : 'move';
            }
            var isSendOut = (t === 'send_vendor' || t === 'send_user');
            // Location: shown for move, receive_vendor, receive_user
            locF.style.display = isSendOut              ? 'none'  : 'block';
            venF.style.display = (t === 'send_vendor')  ? 'block' : 'none';
            usrF.style.display = (t === 'send_user')    ? 'block' : 'none';
            dueF.style.display = isSendOut              ? 'block' : 'none';
        }
        typeSel.addEventListener('change', function () { applyType(this.value); });

        function openModal(id, tag, preset, label) {
            idInp.value         = id;
            tagSpan.textContent = tag;
            var titleAction = document.getElementById('asset-txn-modal-action');
            if (titleAction) titleAction.textContent = label || 'Transact';
            // Reset destination selects and inputs first
            [locF, venF, usrF].forEach(function (f) {
                var s = f.querySelector('select');
                if (s) s.selectedIndex = 0;
            });
            notesInp.value = '';
            if (dueInp) dueInp.value = '';
            modal.style.display = '';

            var targetType = preset || 'move';

            function forcePreset() {
                var ts = document.getElementById('modal-txn-type');
                if (!ts) return;
                ts.value = targetType;
                for (var i = 0; i < ts.options.length; i++) {
                    ts.options[i].selected = (ts.options[i].value === targetType);
                }
                applyType(targetType);
            }

            forcePreset();                          // synchronous pass
            setTimeout(function () {
                forcePreset();                      // async pass — beats browser autofill
                var ts = document.getElementById('modal-txn-type');
                if (ts) ts.focus();
            }, 50);
        }

        function closeModal() { modal.style.display = 'none'; }

        document.getElementById('asset-txn-modal-close').addEventListener('click',  closeModal);
        document.getElementById('asset-txn-modal-cancel').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
        });

        // Wire every Check In / Check Out button — including rows added later by datatable pagination.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.asset-txn-trigger');
            if (!btn) return;
            openModal(btn.dataset.id, btn.dataset.tag, btn.dataset.preset, btn.dataset.label);
        });
    })();
    </script>
    <?php endif; ?>

    <?php notes_popup_assets(); ?>
    <?php if ($canCreate):
        import_modal_html(
            'asset-import-modal',
            'Import assets from CSV',
            url('/asset.php?action=import_preview'),
            'Required column: <code>model_code</code>. '
              . 'Optional: <code>asset_tag</code> (auto-generated if blank), '
              . '<code>location_code</code>, <code>parent_asset_tag</code>, '
              . '<code>cal_frequency</code> (the label), '
              . '<code>cal_done_on</code> &amp; <code>next_cal_due_on</code> (YYYY-MM-DD), '
              . '<code>status</code> (active/archived/with_vendor/with_user), '
              . '<code>a_price</code>, <code>pid_used_in</code>, <code>notes</code>.'
        );
    endif; ?>
    <?php if ($canTransact):
        import_modal_html(
            'asset-txn-import-modal',
            'Import asset transactions from CSV',
            url('/asset.php?action=asset_txn_import_preview'),
            'Each row is one asset transaction, applied in CSV order. '
              . 'Required columns: <code>asset_tag</code>, '
              . '<code>txn_type</code> (move / send_vendor / receive_vendor / '
              . 'send_user / receive_user). '
              . 'Type-specific required columns: '
              . '<code>to_location_code</code> for move / receive_vendor / receive_user; '
              . '<code>to_vendor_code</code> for send_vendor; '
              . '<code>to_user_username</code> for send_user. '
              . 'Optional: <code>notes</code>. '
              . 'The "from" side is read from the asset\'s current state at the moment '
              . 'of application, so rows must be ordered chronologically. '
              . '<strong>Transactions are append-only</strong> — there is no upsert.',
            /* showUpsert: */ false
        );
    endif; ?>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// DEFAULT — Asset dashboard (calibration alerts + recent)
// ============================================================
$soon = db_all(
    "SELECT a.*, am.name AS model_name, l.name AS location_name
       FROM assets a
       LEFT JOIN asset_models am ON am.id = a.model_id
       LEFT JOIN locations l     ON l.id  = a.location_id
      WHERE a.status = 'active'
        AND a.next_cal_due_on IS NOT NULL
        AND a.next_cal_due_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      ORDER BY a.next_cal_due_on
      LIMIT 50"
);
$overdue = db_all(
    "SELECT a.*, am.name AS model_name, l.name AS location_name
       FROM assets a
       LEFT JOIN asset_models am ON am.id = a.model_id
       LEFT JOIN locations l     ON l.id  = a.location_id
      WHERE a.status = 'active'
        AND a.next_cal_due_on IS NOT NULL
        AND a.next_cal_due_on < CURDATE()
      ORDER BY a.next_cal_due_on
      LIMIT 50"
);
$totalActive  = db_val("SELECT COUNT(*) FROM assets WHERE status='active'", [], 0);
$totalVendor  = db_val("SELECT COUNT(*) FROM assets WHERE status='with_vendor'", [], 0);
$totalUser    = db_val("SELECT COUNT(*) FROM assets WHERE status='with_user'", [], 0);
$totalArch    = db_val("SELECT COUNT(*) FROM assets WHERE status='archived'", [], 0);

$page_title  = 'Asset Dashboard';
$page_module = 'asset';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Asset</h1>
        <p class="muted">Calibration alerts and asset overview</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/asset.php?action=list')) ?>"
           data-shortcut="A" accesskey="a"><?= shortcut_label('All assets', 'A') ?></a>
        <a class="btn btn-ghost" href="<?= h(url('/asset.php?action=models')) ?>"
           data-shortcut="M" accesskey="m"><?= shortcut_label('Models', 'M') ?></a>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= h(url('/asset.php?action=new')) ?>"
               data-shortcut="N" accesskey="n"><?= shortcut_label('+ New asset', 'N') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card stat-success">
        <div class="stat-label">Active</div>
        <div class="stat-value"><?= (int)$totalActive ?></div>
        <div class="stat-sub">In current locations</div>
    </div>
    <div class="stat-card stat-warn">
        <div class="stat-label">With vendor</div>
        <div class="stat-value"><?= (int)$totalVendor ?></div>
        <div class="stat-sub">Out for service / repair</div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-label">With user</div>
        <div class="stat-value"><?= (int)$totalUser ?></div>
        <div class="stat-sub">Handed out</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Archived</div>
        <div class="stat-value"><?= (int)$totalArch ?></div>
        <div class="stat-sub">Retired</div>
    </div>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2>Overdue calibration</h2>
        <span class="muted small"><?= count($overdue) ?> asset<?= count($overdue) === 1 ? '' : 's' ?></span>
    </div>
    <table class="data-table">
        <thead><tr><th>Asset ID</th><th>Model</th><th>Location</th><th>Due date</th><th class="r">Actions</th></tr></thead>
        <tbody>
        <?php if (!$overdue): ?>
            <tr><td colspan="5" class="empty">Nothing overdue — well done.</td></tr>
        <?php else: foreach ($overdue as $a): ?>
            <tr class="cal-overdue-row">
                <td><strong><a href="<?= h(url('/asset.php?action=view&id=' . (int)$a['id'])) ?>"><?= h($a['asset_tag']) ?></a></strong></td>
                <td><?= h($a['model_name'] ?: '—') ?></td>
                <td><?= h($a['location_name'] ?: '—') ?></td>
                <td class="text-danger"><?= h($a['next_cal_due_on']) ?></td>
                <td class="r"><a class="btn btn-sm btn-ghost" href="<?= h(url('/asset.php?action=view&id=' . (int)$a['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2>Due in next 30 days</h2>
        <span class="muted small"><?= count($soon) ?> asset<?= count($soon) === 1 ? '' : 's' ?></span>
    </div>
    <table class="data-table">
        <thead><tr><th>Asset ID</th><th>Model</th><th>Location</th><th>Due date</th><th class="r">Actions</th></tr></thead>
        <tbody>
        <?php if (!$soon): ?>
            <tr><td colspan="5" class="empty">Nothing due soon.</td></tr>
        <?php else: foreach ($soon as $a): ?>
            <tr class="cal-warn-row">
                <td><strong><a href="<?= h(url('/asset.php?action=view&id=' . (int)$a['id'])) ?>"><?= h($a['asset_tag']) ?></a></strong></td>
                <td><?= h($a['model_name'] ?: '—') ?></td>
                <td><?= h($a['location_name'] ?: '—') ?></td>
                <td class="text-warn"><?= h($a['next_cal_due_on']) ?></td>
                <td class="r"><a class="btn btn-sm btn-ghost" href="<?= h(url('/asset.php?action=view&id=' . (int)$a['id'])) ?>">Open</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

// import_old_preview and import_old_run moved to old_inventory_import.php
if ($action === 'import_old_preview' || $action === 'import_old_run') {
    redirect(url('/old_inventory_import.php'));
}

<?php require __DIR__ . '/includes/footer.php'; ?>
