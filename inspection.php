<?php
/**
 * MagDyn — Inspection module
 * Created: 2026-05-17 IST
 *
 * Actions:
 *   list             default: paginated table of all inspections
 *   new              plan a new inspection (pick type, target, template)
 *   view  &id=N      single inspection record + results grid + verdict
 *   execute &id=N    inspector's input form (record measurements)
 *   save             POST handler for save / status transitions
 *   templates        list of inspection templates
 *   template_new     create a new template
 *   template_edit &id=N  edit a template's items
 *   template_save    POST handler for templates
 *   print &id=N      browser-printable IR (opens in new tab)
 *   download_pdf &id=N  stream IR as PDF attachment
 *   entity_picker    AJAX json — same shape as running_notes
 *
 * Permissions
 *   inspection.view     read everything
 *   inspection.create   plan new inspections (and create new templates)
 *   inspection.execute  record measurements + submit for approval
 *   inspection.approve  pass / fail / rework / cancel — sign off
 *                       enforced two-person rule: inspected_by != approved_by
 *   inspection.manage   delete inspections, edit any template, hard-deletes
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/datatable.php';
require_once __DIR__ . '/includes/_inventory_txn.php';
require_once __DIR__ . '/includes/_qc.php';
require_once __DIR__ . '/includes/_inspection_ir.php';

$action = (string)input('action', 'list');

// =================================================================
// Helpers
// =================================================================

/**
 * Generate the next INSP-NNNNNN code. Parses the numeric suffix from
 * existing codes (not the row id, since those can drift) and increments.
 * Retries on uniqueness clash up to 50 times — defends against the
 * narrow race where two new() handlers run in parallel.
 */
function inspection_next_code()
{
    $prefix = 'INSP-';
    $pad    = 6;
    $like   = $prefix . '%';

    $rows = db_all(
        'SELECT code FROM inspections WHERE code LIKE ? ORDER BY id DESC LIMIT 50',
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
        $clash = db_one('SELECT id FROM inspections WHERE code = ?', [$candidate]);
        if (!$clash) return $candidate;
        $next++;
    }
    // Last-resort fallback if 50 candidates all clashed — should never happen
    return $prefix . date('YmdHis');
}

/**
 * Look up the display symbol for a UOM by code. Cached per-request
 * so result-table rendering doesn't hit the DB for every row. Returns
 * the symbol if the UOM exists and is active, otherwise echoes the
 * code itself (handles historical rows whose UOM was later deleted).
 */
function inspection_uom_display($code)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (db_one("SELECT 1 FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = 'inspection_uoms'")) {
            foreach (db_all('SELECT code, symbol FROM inspection_uoms') as $u) {
                $cache[(string)$u['code']] = (string)$u['symbol'];
            }
        }
    }
    if ($code === null || $code === '') return '';
    return $cache[(string)$code] ?? (string)$code;
}

/**
 * Generate the next TPL-NNN template code. Same parse-the-suffix
 * pattern as inspection_next_code — keeps codes contiguous-ish and
 * tolerates manual code edits without colliding.
 */
function inspection_template_next_code()
{
    $prefix = 'TPL-';
    $pad    = 3;
    $like   = $prefix . '%';

    $rows = db_all(
        'SELECT code FROM inspection_templates WHERE code LIKE ? ORDER BY id DESC LIMIT 50',
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
        $clash = db_one('SELECT id FROM inspection_templates WHERE code = ?', [$candidate]);
        if (!$clash) return $candidate;
        $next++;
    }
    return $prefix . date('YmdHis');
}

/**
 * Fetch the entity-target rows for a template, joined to their parent
 * tables so the editor can display friendly labels. Returns rows shaped
 * [entity_type, entity_id, label]. Stale links (entity row deleted)
 * fall back to "<type> #<id>".
 */
function inspection_template_targets($templateId)
{
    $templateId = (int)$templateId;
    if (!$templateId) return [];
    $rows = db_all(
        "SELECT t.entity_type, t.entity_id,
                CASE
                    WHEN t.entity_type = 'asset'    THEN COALESCE(a.asset_tag, CONCAT('Asset #', t.entity_id))
                    WHEN t.entity_type = 'inv_item' THEN COALESCE(
                        CONCAT(i.code, ' — ', COALESCE(NULLIF(i.short_description, ''), i.name)),
                        CONCAT('Item #', t.entity_id))
                END AS label
           FROM inspection_template_targets t
           LEFT JOIN assets    a ON t.entity_type = 'asset'    AND a.id = t.entity_id
           LEFT JOIN inv_items i ON t.entity_type = 'inv_item' AND i.id = t.entity_id
          WHERE t.template_id = ?
          ORDER BY t.entity_type, t.id",
        [$templateId]
    );
    return $rows;
}

/** Pretty label for a status enum value. */
function inspection_status_pill($status)
{
    $map = [
        'draft'        => ['Draft',        'neutral'],
        'in_progress'  => ['In progress',  'info'],
        'passed'       => ['Passed',       'active'],
        'failed'       => ['Failed',       'danger'],
        'rework'       => ['Rework',       'warn'],
        'hold'         => ['On hold',      'warn'],
        'cancelled'    => ['Cancelled',    'neutral'],
    ];
    list($label, $variant) = $map[$status] ?? [$status, 'neutral'];
    return '<span class="pill pill-' . $variant . '">' . h($label) . '</span>';
}

/** Pretty label for the inspection_type enum. */
function inspection_type_label($type)
{
    $map = [
        'incoming'       => 'Incoming material',
        'asset_cal'      => 'Asset calibration',
        'finished_goods' => 'Finished goods QC',
        'first_article'  => 'First article',
        'adhoc'          => 'Ad-hoc',
    ];
    return $map[$type] ?? $type;
}

/** Resolve a (entity_type, entity_id) into [label, link] for display. */
function inspection_resolve_entity($entityType, $entityId)
{
    $entityId = (int)$entityId;
    if (!$entityType || $entityType === 'none' || !$entityId) {
        return ['—', '#'];
    }
    if ($entityType === 'asset') {
        $a = db_one('SELECT asset_tag FROM assets WHERE id = ?', [$entityId]);
        if ($a) return [$a['asset_tag'], url('/asset.php?action=view&id=' . $entityId)];
    } elseif ($entityType === 'inv_item') {
        $i = db_one('SELECT code FROM inv_items WHERE id = ?', [$entityId]);
        if ($i) return [$i['code'], url('/inventory.php?action=item_edit&id=' . $entityId)];
    } elseif ($entityType === 'inv_txn') {
        $t = db_one(
            'SELECT t.id, t.txn_type, i.code, t.item_id
               FROM inv_txns t JOIN inv_items i ON i.id = t.item_id
              WHERE t.id = ?',
            [$entityId]
        );
        if ($t) return [
            'Txn #' . (int)$t['id'] . ' (' . $t['code'] . ' · ' . $t['txn_type'] . ')',
            url('/inventory.php?action=item_edit&id=' . (int)$t['item_id']),
        ];
    }
    return [$entityType . ' #' . $entityId, '#'];
}

/** Permissions on a specific inspection row for the current user. */
function inspection_can_execute($row)
{
    if (!permission_check('inspection', 'execute')) return false;
    // Inspections that are already in a terminal state cannot be executed.
    return in_array($row['status'], ['draft', 'in_progress', 'rework', 'hold'], true);
}
function inspection_can_approve($row)
{
    if (!permission_check('inspection', 'approve')) return false;
    if (!in_array($row['status'], ['in_progress'], true)) return false;
    // Nothing to approve yet — results haven't been recorded.
    if (!$row['inspected_by']) return false;
    // Two-person rule: approver must differ from the inspector who
    // recorded the results. inspected_by is set when execute is
    // submitted. The 'bypass_two_person' permission (granted to
    // admin by default; see migration 20260524_214623) lets the
    // holder approve their own inspection — an explicit override
    // for single-admin installs and similar small-team scenarios
    // where the strict rule would block the QC flow entirely.
    $uid = (int)current_user_id();
    if ((int)$row['inspected_by'] === $uid
        && !permission_check('inspection', 'bypass_two_person')) {
        return false;
    }
    return true;
}
function inspection_can_delete($row)
{
    return permission_check('inspection', 'manage');
}

/**
 * Persist an uploaded inspection attachment to disk. Mirrors
 * _notes_store_upload() but writes to /uploads/inspections/YYYY/MM/.
 * Returns array [stored_path, filename, mime, size] or null on error.
 */
function inspection_store_upload($file)
{
    if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) return null;
    $maxBytes = 10 * 1024 * 1024; // 10 MB
    if ((int)$file['size'] > $maxBytes) return null;

    $base = __DIR__ . '/uploads/inspections';
    if (!is_dir($base) && !@mkdir($base, 0775, true)) return null;
    $sub = date('Y/m');
    $dir = $base . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;

    $origName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$file['name']);
    if ($origName === '' || $origName === '.') $origName = 'file';
    $hex    = bin2hex(random_bytes(8));
    $stored = $sub . '/' . $hex . '_' . $origName;
    $dest   = $dir . '/' . $hex . '_' . $origName;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) return null;

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)finfo_file($fi, $dest); finfo_close($fi); }
    }
    if ($mime === '') $mime = (string)$file['type'];

    return [
        'stored_path' => $stored,
        'filename'    => $origName,
        'mime'        => $mime,
        'size'        => (int)$file['size'],
    ];
}

// =================================================================
// INSPECTION RECORD IMPORT — two-step, header-only
// =================================================================
// CSV columns (all optional except as noted):
//   code              — auto-generated INSP-NNNNNN if blank
//                       (lookup key when upsert=on)
//   inspection_type   — incoming / asset_cal / finished_goods /
//                       first_article / adhoc (default adhoc)
//   entity_type       — asset / inv_item / none (default none)
//                       inv_txn is NOT supported on import — those
//                       inspections are created from the receipt UI
//   entity_code       — required if entity_type != none. For asset →
//                       matches assets.asset_tag; for inv_item →
//                       matches inv_items.code
//   template_code     — optional; matches inspection_templates.code
//                       (or .name as fallback). When set AND status
//                       is 'draft', the template's items seed
//                       inspection_results just like the UI's "create"
//                       flow does
//   status            — draft / in_progress / pending_approval /
//                       passed / failed / rework / hold / cancelled
//                       (default draft)
//   due_date          — YYYY-MM-DD
//   verdict_notes     — free text
//
// What this DOES NOT import: the per-line results (numeric readings,
// pass/fail per item). Those are entered via the execute UI. If you
// need bulk historical results, that's a separate phase.
require_once __DIR__ . '/includes/_import.php';

function inspection_import_adapter(array $row, bool $upsert) {
    $code = trim((string)($row['code'] ?? ''));

    $type = strtolower(trim((string)($row['inspection_type'] ?? 'adhoc')));
    $validTypes = ['incoming','asset_cal','finished_goods','first_article','adhoc'];
    if (!in_array($type, $validTypes, true)) {
        return ['status' => 'error',
                'reason' => 'inspection_type must be one of ' . implode(' / ', $validTypes)];
    }

    $entityType = strtolower(trim((string)($row['entity_type'] ?? 'none')));
    $validEntities = ['asset','inv_item','none'];
    if (!in_array($entityType, $validEntities, true)) {
        return ['status' => 'error',
                'reason' => 'entity_type must be one of ' . implode(' / ', $validEntities)
                          . ' (inv_txn target is not importable)'];
    }

    $entityId = null;
    $entityCode = trim((string)($row['entity_code'] ?? ''));
    if ($entityType !== 'none') {
        if ($entityCode === '') {
            return ['status' => 'error',
                    'reason' => 'entity_code is required when entity_type=' . $entityType];
        }
        if ($entityType === 'asset') {
            $e = db_one('SELECT id FROM assets WHERE asset_tag = ?', [$entityCode]);
            if (!$e) return ['status' => 'error',
                             'reason' => 'Unknown asset_tag "' . $entityCode . '"'];
            $entityId = (int)$e['id'];
        } else { // inv_item
            $e = db_one('SELECT id FROM inv_items WHERE code = ?', [$entityCode]);
            if (!$e) return ['status' => 'error',
                             'reason' => 'Unknown inv_item code "' . $entityCode . '"'];
            $entityId = (int)$e['id'];
        }
    } else {
        $entityCode = '';
    }

    $templateId = null;
    $templateCode = trim((string)($row['template_code'] ?? ''));
    if ($templateCode !== '') {
        $t = db_one('SELECT id FROM inspection_templates WHERE code = ?', [$templateCode]);
        if (!$t) $t = db_one('SELECT id FROM inspection_templates WHERE name = ?', [$templateCode]);
        if (!$t) {
            return ['status' => 'error',
                    'reason' => 'Unknown template_code "' . $templateCode . '"'];
        }
        $templateId = (int)$t['id'];
    }

    $statusVal = strtolower(trim((string)($row['status'] ?? 'draft')));
    $validStatuses = ['draft','in_progress','pending_approval','passed','failed','rework','hold','cancelled'];
    if (!in_array($statusVal, $validStatuses, true)) {
        return ['status' => 'error',
                'reason' => 'status must be one of ' . implode(' / ', $validStatuses)];
    }

    $dueRaw = trim((string)($row['due_date'] ?? ''));
    $dueDate = null;
    if ($dueRaw !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueRaw)) {
            return ['status' => 'error', 'reason' => 'due_date must be YYYY-MM-DD'];
        }
        $dueDate = $dueRaw;
    }

    $clean = [
        'code'            => $code,
        'inspection_type' => $type,
        'entity_type'     => $entityType,
        'entity_id'       => $entityId,
        'entity_code'     => $entityCode,
        'template_id'     => $templateId,
        'template_code'   => $templateCode,
        'status'          => $statusVal,
        'verdict_notes'   => trim((string)($row['verdict_notes'] ?? '')),
        'due_date'        => $dueDate,
    ];

    // Upsert lookup by code
    if ($code !== '') {
        $e = db_one('SELECT id FROM inspections WHERE code = ?', [$code]);
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

function inspection_import_committer(array $previewRow) {
    $d = $previewRow['data'];
    $uid = (int)current_user_id();

    if ($previewRow['status'] === 'update') {
        $id = (int)$previewRow['existing_id'];
        db_exec(
            'UPDATE inspections SET
                inspection_type=?, entity_type=?, entity_id=?, template_id=?,
                status=?, verdict_notes=?, due_date=?
              WHERE id=?',
            [$d['inspection_type'], $d['entity_type'], $d['entity_id'],
             $d['template_id'], $d['status'],
             $d['verdict_notes'] !== '' ? $d['verdict_notes'] : null,
             $d['due_date'], $id]
        );
        return $id;
    }

    // Insert
    $code = $d['code'] !== '' ? $d['code'] : inspection_next_code();
    db_exec(
        'INSERT INTO inspections
           (code, inspection_type, entity_type, entity_id, template_id,
            status, verdict_notes, planned_by, due_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$code, $d['inspection_type'], $d['entity_type'], $d['entity_id'],
         $d['template_id'], $d['status'],
         $d['verdict_notes'] !== '' ? $d['verdict_notes'] : null,
         $uid, $d['due_date']]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);

    // Seed result rows from template, matching the UI's create flow.
    // Only applies on insert + status='draft' + template chosen — once
    // an inspection's been executed or approved we don't backfill items.
    if ($d['template_id'] && $d['status'] === 'draft') {
        $items = db_all(
            'SELECT * FROM inspection_template_items
              WHERE template_id = ? ORDER BY sort_order, id',
            [$d['template_id']]
        );
        foreach ($items as $it) {
            db_exec(
                'INSERT INTO inspection_results
                   (inspection_id, template_item_id, sort_order, label, bubble_no, gdt_symbol,
                    check_type, target_value, tolerance_lower, tolerance_upper,
                    unit, pass_fail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$newId, $it['id'], $it['sort_order'], $it['label'],
                 $it['bubble_no'] ?? null, $it['gdt_symbol'] ?? null,
                 $it['check_type'], $it['target_value'], $it['tolerance_lower'],
                 $it['tolerance_upper'], $it['unit'], 'pending']
            );
        }
    }
    return $newId;
}

if ($action === 'inspection_import_preview') {
    require_permission('inspection', 'create');
    csrf_check();
    $upsert = !empty($_POST['upsert']);
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/inspection.php'));
    }
    $token  = import_stash($parsed['csv_text'], 'inspections');
    $result = import_run_adapter($parsed['rows'], 'inspection_import_adapter', $upsert);

    $page_title  = 'Import inspection records · preview';
    $page_module = 'inspection';
    require __DIR__ . '/includes/header.php';
    import_render_preview([
        'title'      => 'Import inspection records · preview',
        'commit_url' => url('/inspection.php?action=inspection_import_commit'),
        'cancel_url' => url('/inspection.php'),
        'token'      => $token,
        'upsert'     => $upsert,
        'counts'     => $result['counts'],
        'rows'       => $result['rows'],
        'columns'    => [
            ['code',            'Code'],
            ['inspection_type', 'Type'],
            ['entity_type',     'Target type'],
            ['entity_code',     'Target'],
            ['template_code',   'Template'],
            ['status',          'Status'],
            ['due_date',        'Due'],
        ],
    ]);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'inspection_import_commit') {
    require_permission('inspection', 'create');
    csrf_check();
    $token  = (string)input('token', '');
    $upsert = !empty($_POST['upsert']);
    $csv = import_unstash($token, 'inspections');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/inspection.php'));
    }
    $res = import_run_commit($csv, 'inspection_import_adapter', $upsert, 'inspection_import_committer');
    if (empty($res['ok'])) {
        flash_set('error', 'Import failed: ' . ($res['error'] ?? 'unknown'));
    } else {
        $msg = 'Imported ' . (int)$res['inserted'] . ' new inspection'
             . ($res['inserted'] === 1 ? '' : 's')
             . ', updated ' . (int)$res['updated']
             . '.' . ($res['errors'] > 0 ? ' ' . (int)$res['errors'] . ' rows failed (see server log).' : '');
        flash_set('success', $msg);
    }
    redirect(url('/inspection.php'));
}

// =================================================================
// Generic save dispatch — keeps POST handlers near the top so each
// action handler can assume GET state.
// =================================================================
if ($action === 'save') {
    require_login();
    csrf_check();
    $op = (string)input('op', '');

    // ---------------------------------------------------------
    // CREATE — plan a new inspection
    // ---------------------------------------------------------
    if ($op === 'create') {
        require_permission('inspection', 'create');
        $type       = (string)input('inspection_type', 'adhoc');
        $entityType = (string)input('entity_type', 'none');
        $entityId   = (int)input('entity_id', 0);
        $templateId = (int)input('template_id', 0) ?: null;
        $dueDate    = (string)input('due_date', '') ?: null;
        $verdict    = trim((string)input('verdict_notes', ''));

        // IR additions
        $jobCardId   = (int)input('job_card_id', 0) ?: null;
        $sampleCount = max(1, min(60, (int)input('sample_count', 1)));
        $chkdQty     = input('chkd_qty', '') !== '' ? (int)input('chkd_qty', 0) : null;

        $validTypes = ['incoming','asset_cal','finished_goods','first_article','adhoc'];
        if (!in_array($type, $validTypes, true)) $type = 'adhoc';
        $validEnts  = ['asset','inv_item','inv_txn','none'];
        if (!in_array($entityType, $validEnts, true)) $entityType = 'none';
        if ($entityType === 'none') $entityId = null;
        elseif (!$entityId) {
            flash_set('error', 'Pick a target for this inspection.');
            redirect(url('/inspection.php?action=new'));
        }

        // Snapshot part identity from inv_items if the target is one.
        // For other entity types (asset / inv_txn / none) the snapshot
        // stays NULL and the IR header just shows blanks — operators
        // can patch in a follow-up edit if they need the printed doc
        // populated.
        $partSnap = ['part_no' => null, 'part_rev' => null, 'part_description' => null, 'pid' => null];
        if ($entityType === 'inv_item' && $entityId) {
            $partSnap = ir_snapshot_part_from_inv_item($entityId);
        }

        $uid  = (int)current_user_id();
        $code = inspection_next_code();
        $irNo = ir_next_no();

        try {
            db()->beginTransaction();
            db_exec(
                'INSERT INTO inspections
                   (code, ir_no, inspection_type, entity_type, entity_id, template_id,
                    status, verdict_notes, planned_by, due_date,
                    job_card_id, sample_count, chkd_qty,
                    part_no, part_rev, part_description, pid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $irNo, $type, $entityType, $entityId, $templateId,
                 'draft', $verdict ?: null, $uid, $dueDate,
                 $jobCardId, $sampleCount, $chkdQty,
                 $partSnap['part_no'], $partSnap['part_rev'],
                 $partSnap['part_description'], $partSnap['pid']]
            );
            $id = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);

            // Seed N rows per template item (one per sample). When
            // sample_count = 1, this produces the same shape as the
            // legacy single-sample seed — so existing inspection
            // workflows (asset cal, etc.) keep working unchanged.
            if ($templateId) {
                ir_seed_results_with_samples($id, $templateId, $sampleCount);
            }
            db()->commit();
        } catch (\Throwable $e) {
            db()->rollBack();
            flash_set('error', 'Could not create inspection: ' . $e->getMessage());
            redirect(url('/inspection.php?action=new'));
        }
        flash_set('success', 'Inspection ' . $code . ' (' . $irNo . ') planned.');
        redirect(url('/inspection.php?action=view&id=' . $id));
    }

    // ---------------------------------------------------------
    // EXECUTE START — capture date / inspector / samples before
    // the inspector fills in measurements. Re-seeds the results
    // grid when the sample count changes.
    // ---------------------------------------------------------
    if ($op === 'execute_start') {
        require_permission('inspection', 'execute');
        $id  = (int)input('id', 0);
        $row = db_one('SELECT * FROM inspections WHERE id = ? AND is_deleted = 0', [$id]);
        if (!$row || !inspection_can_execute($row)) {
            flash_set('error', 'Cannot start execution for this inspection.');
            redirect(url('/inspection.php?action=view&id=' . $id));
        }

        $inspDate = trim((string)input('inspection_date', ''));
        if (!$inspDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $inspDate)) {
            $inspDate = date('Y-m-d');
        }
        $inspectorId  = (int)input('inspector_id', 0) ?: (int)current_user_id();
        $samplesTaken = max(1, (int)input('samples_taken', 1));

        // Cap samples to the txn's positive qty so we can't inspect
        // more pieces than were actually received.
        if ($row['entity_type'] === 'inv_txn' && $row['entity_id']) {
            $txnQtyRow = db_one('SELECT qty_delta FROM inv_txns WHERE id = ?', [(int)$row['entity_id']]);
            if ($txnQtyRow && (float)$txnQtyRow['qty_delta'] > 0) {
                $samplesTaken = min($samplesTaken, (int)ceil((float)$txnQtyRow['qty_delta']));
            }
        }

        $oldSamples = max(1, (int)($row['sample_count'] ?? 1));
        if ($samplesTaken !== $oldSamples) {
            db_exec('UPDATE inspections SET sample_count = ? WHERE id = ?', [$samplesTaken, $id]);
            if ($row['template_id']) {
                ir_seed_results_with_samples($id, (int)$row['template_id'], $samplesTaken);
            }
        }

        redirect(url('/inspection.php?action=execute&id=' . $id
            . '&started=1'
            . '&insp_date='   . urlencode($inspDate)
            . '&inspector='   . $inspectorId));
    }

    // ---------------------------------------------------------
    // EXECUTE — submit measurements and verdict
    // ---------------------------------------------------------
    if ($op === 'execute') {
        $id  = (int)input('id', 0);
        $row = db_one('SELECT * FROM inspections WHERE id = ? AND is_deleted = 0', [$id]);
        if (!$row) { flash_set('error', 'Inspection not found.'); redirect(url('/inspection.php')); }
        if (!inspection_can_execute($row)) {
            flash_set('error', 'You cannot execute this inspection in its current state.');
            redirect(url('/inspection.php?action=view&id=' . $id));
        }

        // Read the per-result inputs. The execute form posts arrays keyed by
        // result_id: result_value[ID]. For multi-sample IRs each cell is one
        // result_id (param × sample), so the same flat shape works.
        // result_passfail[ID] / result_notes[ID] are optional — the modern
        // multi-sample grid omits them in favour of auto-evaluation, but the
        // single-sample legacy form (if anyone hits it) still posts them.
        $values   = is_array(input('result_value', []))     ? input('result_value', [])     : [];
        $verdicts = is_array(input('result_passfail', []))  ? input('result_passfail', [])  : [];
        $rnotes   = is_array(input('result_notes', []))     ? input('result_notes', [])     : [];

        $uid = (int)current_user_id();

        // Inspector and date come from the execute_start modal.
        // Fall back to current user / now if not present (e.g. legacy direct submit).
        $inspectorId = (int)input('inspector_id', 0);
        $inspectedBy = ($inspectorId > 0) ? $inspectorId : $uid;
        $inspDateRaw = trim((string)input('inspection_date', ''));
        $now = ($inspDateRaw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inspDateRaw))
            ? $inspDateRaw . ' ' . date('H:i:s')
            : date('Y-m-d H:i:s');

        $existing = db_all(
            'SELECT id, check_type, target_value, tolerance_lower, tolerance_upper
               FROM inspection_results WHERE inspection_id = ?',
            [$id]
        );
        $allowedPF = ['pass','fail','na','pending'];
        foreach ($existing as $r) {
            $rid = (int)$r['id'];
            $val = isset($values[$rid])   ? (string)$values[$rid]   : null;
            // Determine pass/fail by check type.
            $ct = (string)($r['check_type'] ?? '');
            if (isset($verdicts[$rid])) {
                $pf = (string)$verdicts[$rid];
                if (!in_array($pf, $allowedPF, true)) $pf = 'pending';
            } elseif ($ct === 'notes') {
                $pf = 'na';
            } elseif ($ct === 'logic') {
                // User selected pass/fail directly via dropdown; stored in measured_value.
                $lv = strtolower(trim((string)$val));
                $pf = ($lv === 'pass') ? 'pass' : (($lv === 'fail') ? 'fail' : 'pending');
            } elseif (ir_auto_passfail($ct)) {
                // Auto-evaluate: numeric, logical-nom, logical-min-max.
                list($minV, $maxV) = ir_min_max_for_type(
                    $ct, $r['target_value'], $r['tolerance_lower'], $r['tolerance_upper']
                );
                $pf = ir_evaluate($val, $minV, $maxV);
                if ($pf === 'na' && ($val === null || $val === '')) {
                    $pf = 'pending';
                }
            } else {
                // NOM, MIN/MAX, boolean, visual, text — no auto pass/fail.
                $pf = ($val === null || $val === '') ? 'pending' : 'pending';
            }
            $nt = isset($rnotes[$rid]) ? (string)$rnotes[$rid] : null;
            db_exec(
                'UPDATE inspection_results
                    SET measured_value = ?, pass_fail = ?, notes = ?,
                        recorded_by = ?, recorded_at = ?
                  WHERE id = ?',
                [$val, $pf, $nt ?: null, $uid, $now, $rid]
            );
        }

        // Per-sample remarks ("Accepted" footer row on the IR). Posted
        // as sample_remarks[sample_no] from the multi-sample form;
        // sparse map stored in inspections.sample_remarks_json.
        $remarksIn = (array)input('sample_remarks', []);
        $remarksMap = [];
        foreach ($remarksIn as $sno => $rem) {
            $sno = (int)$sno;
            if ($sno < 1) continue;
            $rem = trim((string)$rem);
            if ($rem === '') continue;
            $remarksMap[$sno] = $rem;
        }
        $remarksJson = ir_remarks_encode($remarksMap);

        // Inspector's free-form verdict summary.
        $verdict = trim((string)input('verdict_notes', ''));
        db_exec(
            'UPDATE inspections
                SET verdict_notes = ?, inspected_by = ?, inspected_at = ?,
                    sample_remarks_json = ?,
                    status = ?
              WHERE id = ?',
            [$verdict ?: null, $inspectedBy, $now, $remarksJson, 'in_progress', $id]
        );

        // Attachments
        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $files = $_FILES['attachments'];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i])) continue;
                $one = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $stored = inspection_store_upload($one);
                if (!$stored) {
                    flash_set('error', 'Failed to store attachment "' . htmlspecialchars($one['name']) . '" (max 10 MB).');
                    continue;
                }
                db_exec(
                    'INSERT INTO inspection_attachments
                       (inspection_id, filename, stored_path, mime_type, size_bytes, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $stored['filename'], $stored['stored_path'], $stored['mime'], $stored['size'], $uid]
                );
            }
        }

        flash_set('success', 'Inspection results saved. Awaiting approval.');
        redirect(url('/inspection.php?action=view&id=' . $id));
    }

    // ---------------------------------------------------------
    // APPROVE — final sign-off into passed/failed/rework
    // ---------------------------------------------------------
    if ($op === 'approve') {
        $id     = (int)input('id', 0);
        $verdict = (string)input('verdict', 'passed');
        $row    = db_one('SELECT * FROM inspections WHERE id = ? AND is_deleted = 0', [$id]);
        if (!$row) { flash_set('error', 'Inspection not found.'); redirect(url('/inspection.php')); }
        if (!inspection_can_approve($row)) {
            flash_set('error', 'You cannot approve this inspection. '
                . '(Two-person rule: an inspector must record results first, '
                . 'and the approver must not be the inspector.)');
            redirect(url('/inspection.php?action=view&id=' . $id));
        }
        $valid = ['passed','failed','rework','hold','cancelled'];
        if (!in_array($verdict, $valid, true)) $verdict = 'passed';

        // For rework, the approver picks where the stock goes
        // (O-Rework = send back to vendor; I-Rework = fix in-house).
        // The form posts rework_dst as one of those two codes; anything
        // else is ignored and qc_release_for_verdict falls back to the
        // source-type heuristic.
        $reworkDst = null;
        if ($verdict === 'rework') {
            $candidate = strtoupper(trim((string)input('rework_dst', '')));
            if (in_array($candidate, ['O-REWORK', 'I-REWORK'], true)) {
                $reworkDst = $candidate;
            }
        }

        $uid = (int)current_user_id();
        try {
            db()->beginTransaction();
            db_exec(
                'UPDATE inspections
                    SET status = ?, approved_by = ?, approved_at = NOW()
                  WHERE id = ?',
                [$verdict, $uid, $id]
            );

            // Move stock out of LOC-QCH for inv_txn-linked inspections.
            // Helper is a no-op for verdicts that don't move stock
            // (hold/cancelled) and for inspections already released.
            $releaseInfo = qc_release_for_verdict($row, $verdict, $reworkDst);
            db()->commit();
        } catch (Exception $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash_set('error', 'Approval failed: ' . $e->getMessage());
            redirect(url('/inspection.php?action=view&id=' . $id));
        }

        $msg = 'Inspection marked ' . $verdict . '.';
        if (!empty($releaseInfo['moved'])) {
            $dstLoc = db_one('SELECT code, name FROM locations WHERE id = ?',
                [(int)$releaseInfo['dst_loc_id']]);
            $dstLabel = $dstLoc ? ($dstLoc['name'] . ' (' . $dstLoc['code'] . ')')
                                : '#' . (int)$releaseInfo['dst_loc_id'];
            $msg .= sprintf(' Moved %s to %s.',
                rtrim(rtrim(number_format($releaseInfo['qty'], 3), '0'), '.'),
                $dstLabel);
        }
        flash_set('success', $msg);
        redirect(url('/inspection.php?action=view&id=' . $id));
    }

    // ---------------------------------------------------------
    // DELETE — soft delete; manage perm only
    // ---------------------------------------------------------
    if ($op === 'delete') {
        require_permission('inspection', 'manage');
        $id = (int)input('id', 0);
        db_exec('UPDATE inspections SET is_deleted = 1 WHERE id = ?', [$id]);
        flash_set('success', 'Inspection deleted.');
        redirect(url('/inspection.php'));
    }

    flash_set('error', 'Unknown operation.');
    redirect(url('/inspection.php'));
}

// =================================================================
// TEMPLATE save dispatch
// =================================================================
if ($action === 'template_save') {
    require_login();
    csrf_check();
    require_permission('inspection', 'create');
    $op = (string)input('op', '');

    if ($op === 'upsert') {
        $id          = (int)input('id', 0);
        // Code is system-controlled — auto-generated on create, immutable on edit.
        // The form may submit a value but we ignore it.
        if ($id > 0) {
            $existing = db_one('SELECT code FROM inspection_templates WHERE id = ?', [$id]);
            $code = $existing ? (string)$existing['code'] : inspection_template_next_code();
        } else {
            $code = inspection_template_next_code();
        }
        $name        = trim((string)input('name', ''));
        $description = trim((string)input('description', ''));
        $itype       = (string)input('inspection_type', '');
        $isActive    = (int)(input('is_active', 0) ? 1 : 0);

        // The form's "any" option represents NULL in the DB — the column
        // is an ENUM that doesn't allow 'any'. Coerce explicitly so the
        // INSERT/UPDATE doesn't fail.
        $itypeDb = ($itype === 'any' || $itype === '') ? null : $itype;

        // Name is required; code is system-assigned (see above).
        if ($name === '') {
            flash_set('error', 'Template name is required.');
            redirect(url('/inspection.php?action=template_edit' . ($id ? '&id=' . $id : '')));
        }

        $uid = (int)current_user_id();
        if ($id > 0) {
            db_exec(
                'UPDATE inspection_templates
                    SET code = ?, name = ?, description = ?, inspection_type = ?, is_active = ?
                  WHERE id = ?',
                [$code, $name, $description ?: null, $itypeDb, $isActive, $id]
            );
        } else {
            db_exec(
                'INSERT INTO inspection_templates
                   (code, name, description, inspection_type, is_active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$code, $name, $description ?: null, $itypeDb, $isActive, $uid]
            );
            $id = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        }

        // Replace items. Read parallel arrays from the form:
        //   item_label[], item_gdt_symbol[], item_check_type[],
        //   item_target_value[], item_tolerance_lower[],
        //   item_tolerance_upper[], item_unit[], item_required[],
        //   item_attachment_id[], item_bubble_page[], item_bubble_x[], item_bubble_y[]
        // Empty rows (no label) are ignored. We don't try to preserve existing
        // ids — templates are small enough that delete+reinsert is fine.
        //
        // Bubble metadata (attachment_id / page / x / y) is per-row and lets
        // the inspection executor jump back to the position on the drawing.
        // It's populated when the bubble tool's "Save to template" round-trip
        // brings rows in; manual rows leave it null.
        db_exec('DELETE FROM inspection_template_items WHERE template_id = ?', [$id]);
        $labels  = is_array(input('item_label', [])) ? input('item_label', []) : [];
        $bubbles = is_array(input('item_bubble_no', [])) ? input('item_bubble_no', []) : [];
        $gdts    = is_array(input('item_gdt_symbol', [])) ? input('item_gdt_symbol', []) : [];
        $types   = is_array(input('item_check_type', [])) ? input('item_check_type', []) : [];
        $tgts    = is_array(input('item_target_value', [])) ? input('item_target_value', []) : [];
        $los     = is_array(input('item_tolerance_lower', [])) ? input('item_tolerance_lower', []) : [];
        $his     = is_array(input('item_tolerance_upper', [])) ? input('item_tolerance_upper', []) : [];
        $units   = is_array(input('item_unit', [])) ? input('item_unit', []) : [];
        $instrs  = is_array(input('item_instrument_id', [])) ? input('item_instrument_id', []) : [];
        $reqs    = is_array(input('item_required', [])) ? input('item_required', []) : [];
        $attIds  = is_array(input('item_attachment_id', [])) ? input('item_attachment_id', []) : [];
        $bbPages = is_array(input('item_bubble_page', [])) ? input('item_bubble_page', []) : [];
        $bbCells = is_array(input('item_bubble_grid_cell', [])) ? input('item_bubble_grid_cell', []) : [];
        $bbXs    = is_array(input('item_bubble_x', [])) ? input('item_bubble_x', []) : [];
        $bbYs    = is_array(input('item_bubble_y', [])) ? input('item_bubble_y', []) : [];

        // First-save adoption of a pending annotated PDF. The bubble flow may
        // have written the annotated PDF to uploads/template_drawings/ with no
        // attachment row (because the template didn't exist yet). Now that
        // the template has an id, we create the attachment row and remap any
        // item_attachment_id[] = -1 markers to the real attachment id.
        // -1 is the sentinel because 0 is "no attachment" (manual row).
        $pendingPath = trim((string)input('pending_annotated_path', ''));
        $pendingName = trim((string)input('pending_annotated_name', '')) ?: 'annotated.pdf';
        $adoptedAttachmentId = 0;
        if ($pendingPath !== ''
            && strpos($pendingPath, 'uploads/template_drawings/') === 0
            && is_file(__DIR__ . '/' . $pendingPath)) {
            $sz = filesize(__DIR__ . '/' . $pendingPath);
            db_exec(
                'INSERT INTO inspection_template_attachments
                   (template_id, filename, stored_path, mime_type, size_bytes, kind, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $pendingName, $pendingPath, 'application/pdf',
                 $sz, 'annotated_drawing', (int)current_user_id()]
            );
            $adoptedAttachmentId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);

            // Mirror the bonus-note creation that runs in template_bubble_return
            // for already-saved templates. Best-effort.
            _tpl_bubble_attach_to_running_notes(
                $id,
                $pendingPath,
                $pendingName,
                $sz,
                (int)current_user_id()
            );
        }

        $sort = 0;
        foreach ($labels as $i => $lbl) {
            $lbl = trim((string)$lbl);
            if ($lbl === '') continue;
            $ct = isset($types[$i]) ? (string)$types[$i] : 'boolean';
            $validTypes = ['numeric','boolean','text','visual','nom','min-max','logic','logical-min-max','logical-nom','notes'];
            if (!in_array($ct, $validTypes, true)) $ct = 'boolean';
            $gd = isset($gdts[$i]) ? trim((string)$gdts[$i]) : '';
            $gd = $gd === '' ? null : mb_substr($gd, 0, 8);
            $bb = isset($bubbles[$i]) ? trim((string)$bubbles[$i]) : '';
            $bb = $bb === '' ? null : mb_substr($bb, 0, 8);
            $tg = isset($tgts[$i]) && $tgts[$i] !== '' ? (float)$tgts[$i] : null;
            $lo = isset($los[$i])  && $los[$i]  !== '' ? (float)$los[$i]  : null;
            $hi = isset($his[$i])  && $his[$i]  !== '' ? (float)$his[$i]  : null;
            $un = isset($units[$i]) ? trim((string)$units[$i]) : '';
            // Instrument asset id (FK to assets) — picker shows is_active=1
            // only; 0/'' means "none". No DB-level active gate so historical
            // templates linking a later-deactivated instrument still work.
            $inAsset = isset($instrs[$i]) && $instrs[$i] !== '' ? (int)$instrs[$i] : null;
            if ($inAsset === 0) $inAsset = null;
            $rq = isset($reqs[$i]) && $reqs[$i] ? 1 : 0;

            // Bubble metadata. Treat 0 (or '') attachment_id as NULL. If the
            // row was staged before the template had an id (first save of a
            // new template), client sends 0 + pending_annotated_path; we
            // remap to the just-adopted attachment id.
            $aId = isset($attIds[$i]) ? (int)$attIds[$i] : 0;
            if ($aId === 0 && $adoptedAttachmentId > 0
                && isset($bbPages[$i]) && $bbPages[$i] !== '') {
                // This row came from the bubble flow on a brand-new template.
                $aId = $adoptedAttachmentId;
            }
            $aId = $aId > 0 ? $aId : null;
            $bbPage = isset($bbPages[$i]) && $bbPages[$i] !== '' ? (int)$bbPages[$i] : null;
            $bbCell = isset($bbCells[$i]) && $bbCells[$i] !== '' ? mb_substr((string)$bbCells[$i], 0, 8) : null;
            $bbX    = isset($bbXs[$i])    && $bbXs[$i]    !== '' ? (float)$bbXs[$i]  : null;
            $bbY    = isset($bbYs[$i])    && $bbYs[$i]    !== '' ? (float)$bbYs[$i]  : null;

            db_exec(
                'INSERT INTO inspection_template_items
                   (template_id, sort_order, label, bubble_no, gdt_symbol, check_type,
                    target_value, tolerance_lower, tolerance_upper, unit, is_required,
                    instrument_asset_id,
                    source_attachment_id, bubble_page, bubble_grid_cell, bubble_x, bubble_y)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $sort++, $lbl, $bb, $gd, $ct, $tg, $lo, $hi, $un ?: null, $rq,
                 $inAsset,
                 $aId, $bbPage, $bbCell, $bbX, $bbY]
            );
        }

        // Replace target links (many-to-many: asset / inv_item). Two
        // parallel arrays from the form:
        //   target_asset_id[]    — array of asset.id integers
        //   target_inv_item_id[] — array of inv_items.id integers
        // We delete+reinsert; the unique key uq_link (template_id,
        // entity_type, entity_id) makes accidental duplicates harmless,
        // and the table is small enough that this is fine.
        db_exec('DELETE FROM inspection_template_targets WHERE template_id = ?', [$id]);
        $tgtAssets = is_array(input('target_asset_id', [])) ? input('target_asset_id', []) : [];
        $tgtItems  = is_array(input('target_inv_item_id', [])) ? input('target_inv_item_id', []) : [];
        $seen = [];  // dedupe within a single form submit
        foreach ($tgtAssets as $aid) {
            $aid = (int)$aid;
            if ($aid <= 0 || isset($seen['a' . $aid])) continue;
            $seen['a' . $aid] = true;
            db_exec(
                "INSERT IGNORE INTO inspection_template_targets
                    (template_id, entity_type, entity_id)
                 VALUES (?, 'asset', ?)",
                [$id, $aid]
            );
        }
        foreach ($tgtItems as $iid) {
            $iid = (int)$iid;
            if ($iid <= 0 || isset($seen['i' . $iid])) continue;
            $seen['i' . $iid] = true;
            db_exec(
                "INSERT IGNORE INTO inspection_template_targets
                    (template_id, entity_type, entity_id)
                 VALUES (?, 'inv_item', ?)",
                [$id, $iid]
            );
        }

        flash_set('success', 'Template ' . $code . ' saved.');
        redirect(url('/inspection.php?action=template_edit&id=' . $id));
    }

    if ($op === 'delete') {
        require_permission('inspection', 'manage');
        $id = (int)input('id', 0);
        // FK on inspections.template_id is SET NULL by default? We didn't set
        // a CASCADE — check before deleting.
        $usedBy = (int)db_val('SELECT COUNT(*) FROM inspections WHERE template_id = ? AND is_deleted = 0', [$id], 0);
        if ($usedBy > 0) {
            // Soft path: deactivate instead of hard delete.
            db_exec('UPDATE inspection_templates SET is_active = 0 WHERE id = ?', [$id]);
            flash_set('success', 'Template deactivated (still referenced by ' . $usedBy . ' inspection' . ($usedBy === 1 ? '' : 's') . ').');
        } else {
            db_exec('DELETE FROM inspection_templates WHERE id = ?', [$id]);
            flash_set('success', 'Template deleted.');
        }
        redirect(url('/inspection.php?action=templates'));
    }

    flash_set('error', 'Unknown template operation.');
    redirect(url('/inspection.php?action=templates'));
}

// =================================================================
// TEMPLATE CLONE — duplicate an existing template, its items, and
// its entity targets. Lands the user on the editor for the new copy.
// =================================================================
if ($action === 'template_clone') {
    require_login();
    csrf_check();
    require_permission('inspection', 'create');
    $srcId = (int)input('id', 0);
    $src = $srcId > 0 ? db_one('SELECT * FROM inspection_templates WHERE id = ?', [$srcId]) : null;
    if (!$src) {
        flash_set('error', 'Template not found.');
        redirect(url('/inspection.php?action=templates'));
    }
    $uid = (int)current_user_id();
    $newCode = inspection_template_next_code();
    // Append " (copy)" to the name unless already a copy
    $newName = $src['name'] . ' (copy)';

    db_exec(
        'INSERT INTO inspection_templates
           (code, name, description, inspection_type, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$newCode, $newName, $src['description'], $src['inspection_type'], 1, $uid]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);

    // Copy items
    foreach (db_all('SELECT * FROM inspection_template_items WHERE template_id = ? ORDER BY sort_order, id', [$srcId]) as $it) {
        db_exec(
            'INSERT INTO inspection_template_items
               (template_id, sort_order, label, bubble_no, gdt_symbol, description, check_type,
                target_value, tolerance_lower, tolerance_upper, unit, is_required,
                instrument_asset_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$newId, $it['sort_order'], $it['label'],
             $it['bubble_no'] ?? null, $it['gdt_symbol'] ?? null, $it['description'] ?? null,
             $it['check_type'], $it['target_value'], $it['tolerance_lower'],
             $it['tolerance_upper'], $it['unit'], $it['is_required'],
             $it['instrument_asset_id'] ?? null]
        );
    }
    // Copy entity links (only if the targets table exists)
    if (db_one("SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'inspection_template_targets'")) {
        foreach (db_all('SELECT entity_type, entity_id FROM inspection_template_targets WHERE template_id = ?', [$srcId]) as $t) {
            db_exec(
                'INSERT IGNORE INTO inspection_template_targets (template_id, entity_type, entity_id) VALUES (?, ?, ?)',
                [$newId, $t['entity_type'], $t['entity_id']]
            );
        }
    }

    flash_set('success', 'Template duplicated as ' . $newCode . '. Adjust as needed.');
    redirect(url('/inspection.php?action=template_edit&id=' . $newId));
}

// =================================================================
// TEMPLATE BUBBLE INTEGRATION — launch the bubble tool with a drawing
// staged in session, accept the bubbles back, and surface them in the
// template editor as draft items.
//
// Flow:
//   1. POST template_bubble_launch with the drawing file + template_id
//      → stashes a session token, redirects to bubble_tool with
//        ?template_session=<token>
//   2. Bubble tool fetches the drawing via template_bubble_drawing,
//      lets the user bubble it, POSTs bubbles back to
//      template_bubble_return as JSON
//   3. template_bubble_return persists the annotated PDF as a
//      template attachment, stashes the bubble list under a
//      "bubble_stash" token, redirects to template_edit with
//      ?bubble_stash=<token>
//   4. The template editor pulls the stash and renders the bubbles
//      as additional unsaved rows (the user reviews and saves)
//
// Tokens live in $_SESSION['magdyn_tpl_bubble'][token] = [...].
// They're single-use and time out after 1 hour.
// =================================================================

function _tpl_bubble_session_init()
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['magdyn_tpl_bubble']) || !is_array($_SESSION['magdyn_tpl_bubble'])) {
        $_SESSION['magdyn_tpl_bubble'] = [];
    }
    // Evict expired entries
    $now = time();
    foreach ($_SESSION['magdyn_tpl_bubble'] as $k => $v) {
        if (!isset($v['expires']) || $v['expires'] < $now) {
            // Best-effort delete the staged file if still present
            if (isset($v['drawing_path']) && is_file($v['drawing_path'])
                && strpos($v['drawing_path'], sys_get_temp_dir()) === 0) {
                @unlink($v['drawing_path']);
            }
            unset($_SESSION['magdyn_tpl_bubble'][$k]);
        }
    }
}

function _tpl_bubble_session_token()
{
    return bin2hex(random_bytes(8));
}

/**
 * Create a running note on an inspection_template with the just-saved
 * annotated PDF attached. The PDF lives at uploads/template_drawings/...
 * and we point note_attachments.stored_path straight at it (no need to
 * copy into uploads/notes/ — the file is owned by the system anyway).
 *
 * Returns the new note_id, or 0 if anything went wrong (logged, not
 * thrown — the bubble flow shouldn't fail because the bonus note didn't
 * get created).
 */
function _tpl_bubble_attach_to_running_notes($templateId, $sourcePath, $filename, $sizeBytes, $userId)
{
    if ($templateId <= 0) return 0;

    $tpl = db_one('SELECT code, name FROM inspection_templates WHERE id = ?', [$templateId]);
    if (!$tpl) {
        error_log('[tpl_bubble_attach_to_running_notes] template ' . $templateId . ' not found; skipping note');
        return 0;
    }
    $body = '<p>Annotated drawing saved from the bubble tool '
          . 'for template <strong>' . h($tpl['code']) . '</strong> — ' . h($tpl['name']) . '. '
          . 'See attachment.</p>';

    // The note-attachments infrastructure expects files to live under
    // uploads/notes/YYYY/MM/ (note_attachments.stored_path is relative
    // to that base, and note_attach.php's download path is hard-coded
    // to prefix uploads/notes/). The annotated PDF was written to
    // uploads/template_drawings/ — we copy it across so it slots into
    // the notes pathway cleanly. The duplication cost (one PDF per
    // template save) is minor and keeps both attachment systems
    // independent of each other.
    $appRoot = __DIR__;
    $srcFs   = $appRoot . '/' . $sourcePath;
    if (!is_file($srcFs)) {
        error_log('[tpl_bubble_attach_to_running_notes] source ' . $sourcePath . ' missing; skipping note');
        return 0;
    }

    $sub = date('Y/m');
    $notesDir = $appRoot . '/uploads/notes/' . $sub;
    if (!is_dir($notesDir) && !@mkdir($notesDir, 0775, true)) {
        error_log('[tpl_bubble_attach_to_running_notes] could not mkdir ' . $notesDir);
        return 0;
    }
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($filename ?: 'annotated.pdf'));
    if ($safeName === '' || $safeName === '.') $safeName = 'annotated.pdf';
    $hex      = bin2hex(random_bytes(8));
    $stored   = $sub . '/' . $hex . '_' . $safeName;
    $destFs   = $appRoot . '/uploads/notes/' . $stored;

    if (!@copy($srcFs, $destFs)) {
        error_log('[tpl_bubble_attach_to_running_notes] copy failed: ' . $srcFs . ' → ' . $destFs);
        return 0;
    }

    try {
        db_exec(
            'INSERT INTO notes (entity_type, entity_id, note_type_id, body_html, author_id)
             VALUES (?, ?, NULL, ?, ?)',
            ['inspection_template', $templateId, $body, $userId]
        );
        $noteId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        if ($noteId <= 0) {
            @unlink($destFs);
            return 0;
        }

        db_exec(
            'INSERT INTO note_attachments
               (note_id, filename, stored_path, mime_type, size_bytes, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$noteId, $safeName, $stored, 'application/pdf',
             (int)$sizeBytes, $userId]
        );
        return $noteId;
    } catch (Exception $e) {
        error_log('[tpl_bubble_attach_to_running_notes] failed for template '
                  . $templateId . ': ' . $e->getMessage());
        @unlink($destFs);
        return 0;
    }
}


if ($action === 'template_bubble_launch') {
    require_permission('inspection', 'create');
    csrf_check();
    _tpl_bubble_session_init();

    $tplId = (int)input('template_id', 0);
    // template_id 0 = brand-new template not yet saved. We pass the
    // launch through to the bubble tool either way; on return we'll
    // route back to template_new (no id) or template_edit&id=N.

    if (!isset($_FILES['drawing']) || !is_uploaded_file($_FILES['drawing']['tmp_name'])) {
        flash_set('error', 'No drawing uploaded.');
        redirect(url('/inspection.php?action=' . ($tplId ? 'template_edit&id=' . $tplId : 'template_new')));
    }
    $up = $_FILES['drawing'];
    if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_set('error', 'Upload failed (PHP error ' . $up['error'] . ').');
        redirect(url('/inspection.php?action=' . ($tplId ? 'template_edit&id=' . $tplId : 'template_new')));
    }

    // Sniff mime — we accept PDFs and common raster image types.
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime  = $finfo ? finfo_file($finfo, $up['tmp_name']) : ($up['type'] ?? 'application/octet-stream');
    if ($finfo) finfo_close($finfo);
    $allowed = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        flash_set('error', 'Unsupported file type: ' . $mime . '. PDF, PNG, JPEG, GIF, WEBP only.');
        redirect(url('/inspection.php?action=' . ($tplId ? 'template_edit&id=' . $tplId : 'template_new')));
    }

    // Stash in a private temp file. We don't put it in uploads/ yet —
    // it only becomes a real attachment if the user actually saves
    // bubbles back. If they bail, the temp file gets garbage-collected
    // on next session-init.
    $ext = $mime === 'application/pdf' ? '.pdf' :
           ($mime === 'image/png'      ? '.png' :
           ($mime === 'image/jpeg'     ? '.jpg' :
           ($mime === 'image/gif'      ? '.gif' : '.webp')));
    $tmpPath = tempnam(sys_get_temp_dir(), 'magdyn_tplbub_') . $ext;
    if (!move_uploaded_file($up['tmp_name'], $tmpPath)) {
        flash_set('error', 'Could not stage drawing on the server.');
        redirect(url('/inspection.php?action=' . ($tplId ? 'template_edit&id=' . $tplId : 'template_new')));
    }

    // Compute the highest existing bubble number for this template so
    // the bubble tool starts numbering at max+1 (no duplicates across
    // multiple launches). If the template is brand new (tplId 0) there
    // are no DB items yet; the form's posted item_bubble_no[] (if any
    // — unusual on the launch path, but theoretically possible if the
    // user typed numbers before clicking Bubble drawing) is also
    // checked.
    $maxBubbleNo = 0;
    if ($tplId > 0) {
        $row = db_one(
            "SELECT MAX(CAST(bubble_no AS UNSIGNED)) AS m
               FROM inspection_template_items
              WHERE template_id = ? AND bubble_no REGEXP '^[0-9]+'",
            [$tplId]
        );
        if ($row && $row['m'] !== null) $maxBubbleNo = (int)$row['m'];
    }
    $postedBubbles = is_array(input('item_bubble_no', [])) ? input('item_bubble_no', []) : [];
    foreach ($postedBubbles as $bn) {
        if (preg_match('/^\d+/', (string)$bn, $mm)) {
            $maxBubbleNo = max($maxBubbleNo, (int)$mm[0]);
        }
    }

    $token = _tpl_bubble_session_token();
    $_SESSION['magdyn_tpl_bubble'][$token] = [
        'kind'         => 'launch',
        'template_id'  => $tplId,
        'drawing_path' => $tmpPath,
        'drawing_mime' => $mime,
        'drawing_name' => $up['name'] ?? 'drawing',
        'max_bubble_no'=> $maxBubbleNo,
        'created_by'   => (int)current_user_id(),
        'expires'      => time() + 3600,
    ];

    // Hop to the bubble tool with the token. The tool reads the token
    // and fetches the drawing via template_bubble_drawing.
    redirect(url('/tools/bubble_tool.php?template_session=' . urlencode($token) . '&embed=1'));
}

if ($action === 'template_bubble_drawing') {
    // Streams the staged drawing back to the bubble tool's loader.
    // Same-origin, gated by the session token (which is held only by
    // the user who launched the bubble flow).
    require_login();
    _tpl_bubble_session_init();
    $token = (string)input('token', '');
    $stash = $_SESSION['magdyn_tpl_bubble'][$token] ?? null;
    if (!$stash || ($stash['kind'] ?? '') !== 'launch') {
        http_response_code(404);
        echo 'Drawing not found or session expired.';
        exit;
    }
    if ($stash['created_by'] !== (int)current_user_id()) {
        // Tokens are user-scoped; don't leak across users even if a
        // token were guessed.
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
    if (!is_file($stash['drawing_path'])) {
        http_response_code(410);
        echo 'Staged drawing file is gone.';
        exit;
    }
    header('Content-Type: ' . $stash['drawing_mime']);
    header('Content-Length: ' . filesize($stash['drawing_path']));
    header('Content-Disposition: inline; filename="' . basename($stash['drawing_name']) . '"');
    header('Cache-Control: private, no-store');
    readfile($stash['drawing_path']);
    exit;
}

if ($action === 'template_bubble_return') {
    // Bubble tool POSTs JSON here when the user clicks "Save to template".
    // Body: { token, bubbles: [...], annotated_pdf_b64: "..." (optional) }
    require_permission('inspection', 'create');
    _tpl_bubble_session_init();

    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad JSON body']);
        exit;
    }
    // CSRF: bubble tool sends the token in the X-CSRF-Token header
    // (POST body is JSON so the normal _csrf field isn't applicable).
    // We compare against the session token directly. The session-token
    // gating (created_by check below) is the real defense; this guards
    // against a forged origin even with credentials in place.
    $hdrCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$hdrCsrf || !hash_equals(csrf_token(), $hdrCsrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
        exit;
    }

    $token = (string)($body['token'] ?? '');
    $stash = $_SESSION['magdyn_tpl_bubble'][$token] ?? null;
    if (!$stash || ($stash['kind'] ?? '') !== 'launch') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Session expired — re-open the bubble tool from the template editor']);
        exit;
    }
    if ($stash['created_by'] !== (int)current_user_id()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $bubbles = is_array($body['bubbles'] ?? null) ? $body['bubbles'] : [];
    if (count($bubbles) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No bubbles posted — nothing to import']);
        exit;
    }

    // Optionally save the annotated PDF as a template attachment. We
    // only do this if the template has been saved at least once
    // (template_id > 0). If template_id == 0 the user is creating a
    // brand-new template; we stash the PDF bytes in session and write
    // them on first save (template_save handles this).
    $attachmentId  = null;
    $annotatedB64  = (string)($body['annotated_pdf_b64'] ?? '');
    $annotatedSrc  = null;  // either an attachment id (existing template) or 'pending'
    $tplId = (int)$stash['template_id'];

    if ($annotatedB64 !== '') {
        // Decode and write the PDF to uploads/template_drawings/
        $dir = __DIR__ . '/uploads/template_drawings';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $stamp = date('Ymd_His');
        $fname = 'tpl_' . ($tplId ?: 'new') . '_' . $stamp . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.pdf';
        $fpath = $dir . '/' . $fname;
        $pdfBytes = base64_decode($annotatedB64, true);
        if ($pdfBytes === false || strlen($pdfBytes) < 100) {
            // Don't fail the whole return — the bubbles are still useful.
            // Just skip the attachment.
            error_log('[template_bubble_return] annotated PDF decode failed; skipping attachment');
        } else {
            file_put_contents($fpath, $pdfBytes);
            if ($tplId > 0) {
                db_exec(
                    'INSERT INTO inspection_template_attachments
                       (template_id, filename, stored_path, mime_type, size_bytes, kind, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$tplId, $stash['drawing_name'] ?: 'annotated.pdf',
                     'uploads/template_drawings/' . $fname, 'application/pdf',
                     strlen($pdfBytes), 'annotated_drawing', (int)current_user_id()]
                );
                $attachmentId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
                $annotatedSrc = $attachmentId;

                // Also surface the annotated PDF in Running Notes so it's
                // discoverable from the notes module without having to
                // dig into the template attachments table. Best-effort;
                // a note-creation failure shouldn't break the bubble flow.
                _tpl_bubble_attach_to_running_notes(
                    $tplId,
                    'uploads/template_drawings/' . $fname,
                    $stash['drawing_name'] ?: 'annotated.pdf',
                    strlen($pdfBytes),
                    (int)current_user_id()
                );
            } else {
                // No template id yet — stash the path so template_save
                // can adopt it as an attachment on first save.
                $annotatedSrc = 'pending:' . 'uploads/template_drawings/' . $fname;
            }
        }
    }

    // Replace the launch stash with a "result" stash keyed by a new
    // token. The template editor will use this token to pull the
    // bubbles + attachment id on its next render.
    $returnToken = _tpl_bubble_session_token();
    $_SESSION['magdyn_tpl_bubble'][$returnToken] = [
        'kind'                => 'result',
        'template_id'         => $tplId,
        'bubbles'             => $bubbles,
        'attachment_id'       => $attachmentId,
        'pending_attach'      => is_string($annotatedSrc) && strpos($annotatedSrc, 'pending:') === 0
                                    ? substr($annotatedSrc, 8) : null,
        'pending_attach_name' => $stash['drawing_name'] ?: 'annotated.pdf',
        'created_by'          => (int)current_user_id(),
        'expires'             => time() + 3600,
    ];

    // Drop the launch stash + its temp drawing file.
    if (isset($stash['drawing_path']) && is_file($stash['drawing_path'])
        && strpos($stash['drawing_path'], sys_get_temp_dir()) === 0) {
        @unlink($stash['drawing_path']);
    }
    unset($_SESSION['magdyn_tpl_bubble'][$token]);

    $returnUrl = $tplId > 0
        ? url('/inspection.php?action=template_edit&id=' . $tplId . '&bubble_stash=' . urlencode($returnToken))
        : url('/inspection.php?action=template_new&bubble_stash=' . urlencode($returnToken));

    echo json_encode(['ok' => true, 'redirect' => $returnUrl, 'bubble_count' => count($bubbles)]);
    exit;
}


if ($action === 'template_export') {
    require_permission('inspection', 'view');
    $id = (int)input('id', 0);
    $tpl = $id > 0 ? db_one('SELECT * FROM inspection_templates WHERE id = ?', [$id]) : null;
    if (!$tpl) {
        flash_set('error', 'Template not found.');
        redirect(url('/inspection.php?action=templates'));
    }
    $items = db_all(
        'SELECT * FROM inspection_template_items
          WHERE template_id = ? ORDER BY sort_order, id',
        [$id]
    );
    // Filename: TPL-001-rough-name.csv
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $tpl['code'] . '-' . $tpl['name']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '.csv"');
    // BOM so Excel reads UTF-8 GD&T symbols correctly. Most modern
    // spreadsheet apps respect this; numbers, calc, gsheets all do.
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['bubble_no', 'label', 'gdt_symbol', 'check_type', 'target_value',
                   'tolerance_lower', 'tolerance_upper', 'unit', 'is_required', 'description']);
    foreach ($items as $it) {
        fputcsv($out, [
            $it['bubble_no'] ?? '',
            $it['label'],
            $it['gdt_symbol'] ?? '',
            $it['check_type'],
            $it['target_value'] !== null ? $it['target_value'] : '',
            $it['tolerance_lower'] !== null ? $it['tolerance_lower'] : '',
            $it['tolerance_upper'] !== null ? $it['tolerance_upper'] : '',
            $it['unit'] ?? '',
            (int)$it['is_required'],
            $it['description'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// =================================================================
// TEMPLATE IMPORT — accept a CSV upload, create a new template
// =================================================================
if ($action === 'template_import_csv') {
    require_login();
    csrf_check();
    require_permission('inspection', 'create');
    $newName = trim((string)input('name', ''));
    if ($newName === '') {
        flash_set('error', 'A name for the new template is required.');
        redirect(url('/inspection.php?action=templates'));
    }
    if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'No CSV file uploaded or upload failed.');
        redirect(url('/inspection.php?action=templates'));
    }
    if ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
        flash_set('error', 'CSV file too large (max 2MB).');
        redirect(url('/inspection.php?action=templates'));
    }
    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$fh) {
        flash_set('error', 'Could not read uploaded CSV.');
        redirect(url('/inspection.php?action=templates'));
    }
    // Strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    // Header row — find which column index each field lives at.
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        flash_set('error', 'CSV is empty.');
        redirect(url('/inspection.php?action=templates'));
    }
    $colIdx = array_change_key_case(array_flip(array_map('trim', $header)), CASE_LOWER);

    // Required columns: label + check_type. Everything else is optional.
    if (!isset($colIdx['label']) || !isset($colIdx['check_type'])) {
        fclose($fh);
        flash_set('error', 'CSV header must include at least "label" and "check_type" columns.');
        redirect(url('/inspection.php?action=templates'));
    }

    // Create the template shell
    $uid = (int)current_user_id();
    $newCode = inspection_template_next_code();
    db_exec(
        'INSERT INTO inspection_templates (code, name, inspection_type, is_active, created_by)
         VALUES (?, ?, NULL, 1, ?)',
        [$newCode, $newName, $uid]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);

    // Read each row → one template item
    $sort = 0;
    $skipped = 0;
    $imported = 0;
    while (($row = fgetcsv($fh)) !== false) {
        // Skip blank lines
        $hasContent = false;
        foreach ($row as $v) { if (trim((string)$v) !== '') { $hasContent = true; break; } }
        if (!$hasContent) continue;

        $get = function ($key) use ($row, $colIdx) {
            if (!isset($colIdx[$key])) return '';
            return isset($row[$colIdx[$key]]) ? trim((string)$row[$colIdx[$key]]) : '';
        };
        $label = $get('label');
        if ($label === '') { $skipped++; continue; }

        $ct = $get('check_type') ?: 'boolean';
        if (!in_array($ct, ['numeric','boolean','text','visual'], true)) $ct = 'boolean';
        $bub = mb_substr($get('bubble_no'), 0, 8) ?: null;
        $gdt = mb_substr($get('gdt_symbol'), 0, 8) ?: null;
        $tg  = $get('target_value');     $tg = $tg !== '' ? (float)$tg : null;
        $lo  = $get('tolerance_lower');  $lo = $lo !== '' ? (float)$lo : null;
        $hi  = $get('tolerance_upper');  $hi = $hi !== '' ? (float)$hi : null;
        $un  = $get('unit') ?: null;
        $rq  = (int)$get('is_required') ? 1 : 0;
        $desc = $get('description') ?: null;

        db_exec(
            'INSERT INTO inspection_template_items
               (template_id, sort_order, label, bubble_no, gdt_symbol, description, check_type,
                target_value, tolerance_lower, tolerance_upper, unit, is_required)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$newId, $sort++, $label, $bub, $gdt, $desc, $ct, $tg, $lo, $hi, $un, $rq]
        );
        $imported++;
    }
    fclose($fh);

    flash_set('success', 'Imported ' . $imported . ' check item' . ($imported === 1 ? '' : 's')
        . ' into template ' . $newCode . '.'
        . ($skipped > 0 ? ' Skipped ' . $skipped . ' empty-label row' . ($skipped === 1 ? '' : 's') . '.' : ''));
    redirect(url('/inspection.php?action=template_edit&id=' . $newId));
}

// =================================================================
// ENTITY PICKER — AJAX json for the new-inspection page
// =================================================================
if ($action === 'entity_picker') {
    header('Content-Type: application/json; charset=utf-8');
    $et = (string)input('entity_type', '');
    $q  = trim((string)input('q', ''));
    $like = '%' . $q . '%';
    $rows = [];
    if ($et === 'asset') {
        $rows = db_all(
            'SELECT id, asset_tag AS code,
                    COALESCE((SELECT name FROM asset_models WHERE id = a.model_id), "") AS label
               FROM assets a
              WHERE asset_tag LIKE ? OR a.notes LIKE ?
              ORDER BY asset_tag LIMIT 30',
            [$like, $like]
        );
    } elseif ($et === 'inv_item') {
        $rows = db_all(
            'SELECT id, code,
                    COALESCE(NULLIF(short_description, ""), name) AS label
               FROM inv_items
              WHERE code LIKE ? OR name LIKE ? OR short_description LIKE ?
              ORDER BY code LIMIT 30',
            [$like, $like, $like]
        );
    } elseif ($et === 'inv_txn') {
        // Limit to qty-increasing transactions (receive, ship_in, adjust+, process+)
        $rows = db_all(
            "SELECT t.id, CONCAT('Txn #', t.id) AS code,
                    CONCAT(i.code, ' · ', t.txn_type, ' · +',
                           FORMAT(t.qty_delta, 3)) AS label
               FROM inv_txns t
               JOIN inv_items i ON i.id = t.item_id
              WHERE t.qty_delta > 0
                AND (i.code LIKE ? OR t.txn_type LIKE ? OR i.name LIKE ?)
              ORDER BY t.id DESC LIMIT 30",
            [$like, $like, $like]
        );
    }
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

// =================================================================
// TEMPLATE FOR ENTITY — AJAX: linked templates for a given entity
// Used by the new-inspection form to auto-select a template when
// the user picks an item/asset that has a pre-linked template.
// =================================================================
if ($action === 'template_for_entity') {
    header('Content-Type: application/json; charset=utf-8');
    $et  = (string)input('entity_type', '');
    $eid = (int)input('entity_id', 0);
    if (!$eid || !in_array($et, ['asset', 'inv_item'], true)) {
        echo json_encode(['ok' => true, 'templates' => []]);
        exit;
    }
    $rows = db_all(
        "SELECT t.id, t.code, t.name, t.inspection_type
           FROM inspection_template_targets tt
           JOIN inspection_templates t ON t.id = tt.template_id AND t.is_active = 1
          WHERE tt.entity_type = ? AND tt.entity_id = ?
          ORDER BY t.name",
        [$et, $eid]
    );
    echo json_encode(['ok' => true, 'templates' => $rows]);
    exit;
}

// =================================================================
// JOB CARD PICKER — AJAX json for the IR job-card selector
// Returns rows shaped { id, code, po_no, line_no, part_no, pdn_qty }
// so the front end can build "code + PO + L + part" labels itself.
// =================================================================
if ($action === 'job_card_picker') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)input('q', ''));
    $rows = ir_job_card_picker($q, 25);
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

// =================================================================
// TEMPLATES LIST
// =================================================================
if ($action === 'templates') {
    require_permission('inspection', 'view');
    $canCreate = permission_check('inspection', 'create');
    $canManage = permission_check('inspection', 'manage');

    $dtCfg = [
        'id'       => 'inspection_templates',
        'base_sql' =>
            "SELECT t.*, u.full_name AS creator_name,
                    (SELECT COUNT(*) FROM inspection_template_items WHERE template_id = t.id) AS item_count,
                    (SELECT COUNT(*) FROM inspections WHERE template_id = t.id AND is_deleted = 0) AS use_count,
                    (SELECT COUNT(*) FROM inspection_template_targets WHERE template_id = t.id) AS target_count
               FROM inspection_templates t
               LEFT JOIN users u ON u.id = t.created_by",
        'columns' => [
            ['key'=>'code',            'label'=>'Code',       'sortable'=>true, 'searchable'=>true, 'sql_col'=>'t.code'],
            ['key'=>'name',            'label'=>'Name',       'sortable'=>true, 'searchable'=>true, 'sql_col'=>'t.name'],
            ['key'=>'inspection_type', 'label'=>'For', 'sortable'=>true, 'sql_col'=>'t.inspection_type',
             'filter' => [
                 'type' => 'select',
                 'placeholder' => 'all types',
                 'options' => [
                     ['value' => 'any',            'label' => 'Any'],
                     ['value' => 'incoming',        'label' => 'Incoming material'],
                     ['value' => 'asset_cal',       'label' => 'Asset calibration'],
                     ['value' => 'finished_goods',  'label' => 'Finished goods QC'],
                     ['value' => 'first_article',   'label' => 'First article'],
                     ['value' => 'adhoc',           'label' => 'Ad-hoc'],
                 ],
             ]],
            ['key'=>'item_count',      'label'=>'Items',      'sortable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'target_count',    'label'=>'Linked to',  'sortable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'use_count',       'label'=>'Used by',    'sortable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'creator_name',    'label'=>'Created by','sortable'=>true,  'sql_col'=>'u.full_name'],
            ['key'=>'is_active',       'label'=>'Status',     'sortable'=>true,  'sql_col'=>'t.is_active',
             'filter' => [
                 'type' => 'select',
                 'placeholder' => 'all',
                 'options' => [
                     ['value' => '1', 'label' => 'Active'],
                     ['value' => '0', 'label' => 'Inactive'],
                 ],
             ]],
            ['key'=>'_actions',        'label'=>'',           'sortable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['name', 'asc'],
    ];

    $rowRenderer = function ($r) use ($canCreate, $canManage) {
        $status = $r['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">inactive</span>';
        // View button — available to all users with inspection.view
        $actions = '<a class="btn btn-icon" href="' . h(url('/inspection.php?action=template_view&id=' . (int)$r['id'])) . '"'
                 . ' title="View" aria-label="View">👁 <span class="dt-action-label">View</span></a> ';
        if ($canCreate) {
            $actions .= '<a class="btn btn-icon" href="' . h(url('/inspection.php?action=template_edit&id=' . (int)$r['id'])) . '"'
                      . ' title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a> ';
            // Clone — POSTs to template_clone so the action requires CSRF.
            // Named "Clone" (not "Duplicate") to match the equivalent
            // action across inv items, BOMs, assets, roles, locations.
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inspection.php?action=template_clone')) . '"'
                      . ' onsubmit="return confirm(\'Clone template &quot;' . h(addslashes($r['name']))
                      . '&quot;? Items, targets, and attachments are copied; usage history is not.\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon" type="submit" title="Clone" aria-label="Clone">'
                      . '⎘ <span class="dt-action-label">Clone</span></button></form> ';
        }
        // Export CSV — view permission is enough; no destructive side effect.
        $actions .= '<a class="btn btn-icon" href="' . h(url('/inspection.php?action=template_export&id=' . (int)$r['id'])) . '"'
                  . ' title="Download as CSV" aria-label="Export CSV">⤓ <span class="dt-action-label">Export CSV</span></a> ';
        if ($canManage) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inspection.php?action=template_save')) . '"'
                      . ' onsubmit="return confirm(\'Delete template &quot;' . h(addslashes($r['name'])) . '&quot;? '
                      . 'If it\\\'s in use, it will be deactivated instead.\');">'
                      . csrf_field()
                      . '<input type="hidden" name="op" value="delete">'
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
        }
        return [
            'code'            => '<a href="' . h(url('/inspection.php?action=template_view&id=' . (int)$r['id'])) . '"><code>' . h($r['code']) . '</code></a>',
            'name'            => '<strong>' . h($r['name']) . '</strong>',
            'inspection_type' => '<span class="pill pill-neutral">' . h(inspection_type_label($r['inspection_type'])) . '</span>',
            'item_count'      => (int)$r['item_count'],
            'target_count'    => (int)$r['target_count']
                                  ? (int)$r['target_count']
                                  : '<span class="muted small">any</span>',
            'use_count'       => (int)$r['use_count'],
            'creator_name'    => h($r['creator_name'] ?: '—'),
            'is_active'       => $status,
            '_actions'        => dt_actions_wrap($actions),
        ];
    };

    $dt = data_table_run($dtCfg, $rowRenderer);
    $dtCfg['title']        = 'Inspection templates';
    $dtCfg['actions_html'] =
        '<a class="btn btn-ghost btn-sm" href="' . h(url('/inspection.php')) . '">← Inspections</a>'
        . ($canCreate
            ? ' <button type="button" class="btn btn-ghost btn-sm" id="tpl-import-btn"'
              . ' title="Create a new template from a CSV file">⤒ Import CSV</button>'
              . ' <a class="btn btn-primary btn-sm" href="' . h(url('/inspection.php?action=template_new')) . '"'
              . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New template', 'N') . '</a>'
            : '');

    $page_title  = 'Inspection templates';
    $page_module = 'inspection';
    require __DIR__ . '/includes/header.php';
    data_table_render($dtCfg, $dt, $rowRenderer);
    ?>
    <?php if ($canCreate): ?>
    <!-- CSV import modal: hidden by default, triggered by the Import CSV button. -->
    <div id="tpl-import-modal" class="att-preview-modal" hidden>
        <div class="att-preview-backdrop" data-tpl-import-close></div>
        <div class="att-preview-dialog" role="dialog" aria-label="Import template from CSV"
             style="max-width: 520px; margin: auto; height: auto;">
            <div class="att-preview-head">
                <span class="att-preview-name">Import template from CSV</span>
                <button type="button" class="btn btn-icon att-preview-close-btn" data-tpl-import-close title="Close">✕</button>
            </div>
            <form method="post" action="<?= h(url('/inspection.php?action=template_import_csv')) ?>"
                  enctype="multipart/form-data" style="padding: 18px;">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="tpl-imp-name">New template name *</label>
                    <input id="tpl-imp-name" name="name" type="text" required maxlength="200"
                           placeholder="e.g. Imported · 5-point dimensional">
                </div>
                <div class="field" style="margin-top: 12px;" data-drop-zone="tpl-import-csv">
                    <label for="tpl-imp-file">CSV file * <span class="muted small">(or drag onto this area)</span></label>
                    <input id="tpl-imp-file" name="csv" type="file" accept=".csv,text/csv" required>
                </div>
                <div class="muted small" style="margin-top: 8px;">
                    Required columns: <code>label</code>, <code>check_type</code>.
                    Optional: <code>gdt_symbol</code>, <code>target_value</code>,
                    <code>tolerance_lower</code>, <code>tolerance_upper</code>, <code>unit</code>,
                    <code>is_required</code>, <code>description</code>.
                    Tip: download an existing template (⤓ Export CSV) for the exact format.
                </div>
                <div style="margin-top: 16px; display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-tpl-import-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Import</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('tpl-import-btn');
        var modal = document.getElementById('tpl-import-modal');
        if (!btn || !modal) return;
        btn.addEventListener('click', function () {
            modal.hidden = false;
            document.body.classList.add('att-preview-modal-open');
            var nm = document.getElementById('tpl-imp-name');
            if (nm) nm.focus();
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-tpl-import-close]')) {
                modal.hidden = true;
                document.body.classList.remove('att-preview-modal-open');
            }
        });
    })();
    </script>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// =================================================================
// TEMPLATE — new (just redirects to edit with id=0)
// =================================================================
if ($action === 'template_new') {
    require_permission('inspection', 'create');
    // Render template_edit with no id
}

// =================================================================
// TEMPLATE — view (read-only)
// =================================================================
if ($action === 'template_view') {
    require_permission('inspection', 'view');
    $id  = (int)input('id', 0);
    $tpl = $id > 0 ? db_one('SELECT t.*, u.full_name AS creator_name
                                FROM inspection_templates t
                           LEFT JOIN users u ON u.id = t.created_by
                               WHERE t.id = ?', [$id]) : null;
    if (!$tpl) { flash_set('error', 'Template not found.'); redirect(url('/inspection.php?action=templates')); }

    $items = db_all(
        'SELECT * FROM inspection_template_items WHERE template_id = ? ORDER BY sort_order, id',
        [$id]
    );
    $targets = inspection_template_targets($id);
    $canCreate = permission_check('inspection', 'create');

    $page_title  = 'Template ' . $tpl['code'];
    $page_module = 'inspection';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'title'        => h($tpl['code']) . ' — ' . h($tpl['name']),
        'back_href'    => url('/inspection.php?action=templates'),
        'back_label'   => 'Templates',
        'actions_html' => $canCreate
            ? '<a class="btn btn-primary btn-sm" href="' . h(url('/inspection.php?action=template_edit&id=' . $id)) . '">✎ Edit</a>'
            : '',
    ]) ?>

    <div class="form-page">
        <div class="form-page-body">

            <!-- Header card -->
            <div class="card" style="padding: 18px; margin-bottom: 16px;">
                <div class="grid-2col">
                    <div><div class="muted small">Code</div><code><?= h($tpl['code']) ?></code></div>
                    <div><div class="muted small">Status</div>
                        <?= $tpl['is_active']
                            ? '<span class="pill pill-active">Active</span>'
                            : '<span class="pill pill-neutral">Inactive</span>' ?>
                    </div>
                    <div><div class="muted small">Name</div><strong><?= h($tpl['name']) ?></strong></div>
                    <div><div class="muted small">For</div>
                        <span class="pill pill-neutral"><?= h(inspection_type_label($tpl['inspection_type'] ?? 'any')) ?></span>
                    </div>
                    <?php if ($tpl['description']): ?>
                        <div style="grid-column:span 2;">
                            <div class="muted small">Description</div>
                            <div style="white-space:pre-wrap;"><?= h($tpl['description']) ?></div>
                        </div>
                    <?php endif; ?>
                    <div><div class="muted small">Created by</div><?= h($tpl['creator_name'] ?: '—') ?></div>
                    <div><div class="muted small">Created</div><?= h(substr((string)$tpl['created_at'], 0, 16)) ?></div>
                </div>
            </div>

            <!-- Applies to -->
            <?php if ($targets): ?>
            <div class="card" style="padding: 18px; margin-bottom: 16px;">
                <h3 style="margin: 0 0 10px; font-size: 14px;">Applies to</h3>
                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                    <?php foreach ($targets as $tgt): ?>
                        <span class="pill pill-info"><?= h($tgt['entity_type'] === 'asset' ? '📦 ' : '🔩 ') . h($tgt['label']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Checklist items -->
            <div class="card" style="padding: 18px;">
                <h3 style="margin: 0 0 10px; font-size: 14px;">
                    Checklist items <span class="muted small">(<?= count($items) ?>)</span>
                </h3>
                <?php if (!$items): ?>
                    <p class="muted empty">No checklist items defined.</p>
                <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Label</th>
                            <th style="width:90px;">Type</th>
                            <th style="width:80px;">Target</th>
                            <th style="width:120px;">Tolerance</th>
                            <th style="width:60px;">Unit</th>
                            <th style="width:60px;">GD&T</th>
                            <th style="width:60px;">Req?</th>
                            <th style="width:80px;">Instrument</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $idx => $it):
                        $instrTag = '';
                        if (!empty($it['instrument_asset_id'])) {
                            $instrRow = db_one('SELECT asset_tag FROM assets WHERE id = ?', [(int)$it['instrument_asset_id']]);
                            $instrTag = $instrRow['asset_tag'] ?? ('Asset #' . $it['instrument_asset_id']);
                        }
                    ?>
                        <tr>
                            <td class="muted small"><?= (int)($it['bubble_no'] ?: ($idx + 1)) ?></td>
                            <td><strong><?= h($it['label']) ?></strong>
                                <?php if ($it['notes'] ?? ''): ?>
                                    <div class="muted small"><?= h($it['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill pill-neutral" style="font-size:11px;"><?= h($it['check_type'] ?? 'visual') ?></span></td>
                            <td class="r muted small"><?= $it['target_value'] !== null ? h($it['target_value']) : '—' ?></td>
                            <td class="muted small">
                                <?php
                                $lo = $it['tolerance_lower'] ?? null;
                                $hi = $it['tolerance_upper'] ?? null;
                                if ($lo !== null || $hi !== null) {
                                    echo h(($lo !== null ? $lo : '—') . ' / ' . ($hi !== null ? '+' . $hi : '—'));
                                } else {
                                    echo '<span class="muted">—</span>';
                                }
                                ?>
                            </td>
                            <td class="muted small"><?= h($it['unit'] ?: '—') ?></td>
                            <td class="muted small"><?= h($it['gdt_symbol'] ?: '—') ?></td>
                            <td><?= $it['is_required'] ? '<span class="pill pill-warn" style="font-size:10px;">req</span>' : '<span class="muted small">opt</span>' ?></td>
                            <td class="muted small"><?= h($instrTag ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// =================================================================
// TEMPLATE — edit (also handles new when no ?id)
// =================================================================
if ($action === 'template_edit' || $action === 'template_new') {
    require_permission('inspection', 'create');
    $id  = (int)input('id', 0);
    $tpl = $id > 0
        ? db_one('SELECT * FROM inspection_templates WHERE id = ?', [$id])
        : null;
    if ($id > 0 && !$tpl) {
        flash_set('error', 'Template not found.');
        redirect(url('/inspection.php?action=templates'));
    }
    $items = $id > 0
        ? db_all('SELECT * FROM inspection_template_items WHERE template_id = ? ORDER BY sort_order, id', [$id])
        : [];

    // Pre-existing attachments (annotated drawings the user has uploaded
    // on prior round-trips). Each appears in the form with an "Edit
    // bubbles" button that re-launches the bubble tool seeded with the
    // attachment's bubbles, so the user can add more or modify them.
    // For new (unsaved) templates this is always empty.
    $attachments = $id > 0
        ? db_all(
            'SELECT a.*,
                    (SELECT COUNT(*) FROM inspection_template_items it
                       WHERE it.template_id = a.template_id
                         AND it.source_attachment_id = a.id) AS item_count
               FROM inspection_template_attachments a
              WHERE a.template_id = ?
              ORDER BY a.id DESC',
            [$id]
          )
        : [];

    // ---- Bubble tool integration: pull staged bubbles from session ----
    // The bubble tool's "Save to template" endpoint redirects back here
    // with ?bubble_stash=<token>. We pull the bubble list and convert
    // each bubble into an unsaved item row appended to $items. The user
    // reviews them inline and submits the form to persist.
    //
    // We also surface staged-but-not-yet-persisted attachment info via
    // hidden form fields so template_save can adopt them on first save
    // of a brand-new template.
    $pendingAnnotatedAttach     = '';
    $pendingAnnotatedAttachName = '';
    $bubbleStash = (string)input('bubble_stash', '');
    if ($bubbleStash !== '') {
        _tpl_bubble_session_init();
        $stash = $_SESSION['magdyn_tpl_bubble'][$bubbleStash] ?? null;
        if ($stash
            && ($stash['kind'] ?? '') === 'result'
            && (int)$stash['created_by'] === (int)current_user_id()) {

            $attachmentId = isset($stash['attachment_id']) ? (int)$stash['attachment_id'] : 0;
            $pendingAnnotatedAttach     = (string)($stash['pending_attach'] ?? '');
            $pendingAnnotatedAttachName = (string)($stash['pending_attach_name'] ?? '');

            // Convert each bubble into an item-row shape. The bubble
            // tool's bubble shape: { id, page, ax, ay, dim: {nominal,
            // unit, tolPlus, tolMinus, gdtSym, ...} }. We derive:
            //   label        — dimension formatted, or "Bubble N"
            //   bubble_no    — bubble.id (the label drawn on the page)
            //   gdt_symbol   — dim.gdtSym (truncated to 8 chars)
            //   check_type   — 'numeric' if a nominal was captured,
            //                  else 'visual' (so manual checks still
            //                  make sense)
            //   target_value — dim.nominal (numeric coerce)
            //   tolerance_lower / _upper — derived from dim.tolMinus
            //                              (negated) and dim.tolPlus
            //   unit         — dim.unit, only if it matches the UOM
            //                  table (otherwise NULL so the dropdown
            //                  doesn't show a phantom option)
            foreach (($stash['bubbles'] ?? []) as $b) {
                $dim = is_array($b['dim'] ?? null) ? $b['dim'] : [];
                $nom = trim((string)($dim['nominal'] ?? ''));
                $tolMinus = trim((string)($dim['tolMinus'] ?? ''));
                $tolPlus  = trim((string)($dim['tolPlus']  ?? ''));
                $unitRaw  = trim((string)($dim['unit']     ?? ''));
                $gdtSym   = trim((string)($dim['gdtSym']   ?? ''));

                // The bubble number drawn on the page. The newer wire
                // format sends `num` (the canonical number); the older
                // format mistakenly sent `id` (an internal UUID), which
                // produced garbage bubble_no values. We honor `num` if
                // present and fall back through label → id-but-only-if-
                // it-looks-numeric, so a payload from a half-deployed
                // client still produces something sensible.
                $bubbleNum = '';
                if (isset($b['num']) && $b['num'] !== '' && $b['num'] !== null) {
                    $bubbleNum = (string)$b['num'];
                } elseif (isset($b['label']) && trim((string)$b['label']) !== '') {
                    $bubbleNum = trim((string)$b['label']);
                } elseif (isset($b['id']) && preg_match('/^\d+$/', (string)$b['id'])) {
                    // Old client sent id but only accept if it looks
                    // like a plain integer (not a 'b_<ts>_<rand>' uuid).
                    $bubbleNum = (string)$b['id'];
                }

                // Optional secondary text label set on the bubble itself
                // — used as a label-row prefix when present.
                $bubbleTextLabel = trim((string)($b['label'] ?? ''));

                $labelParts = [];
                if ($bubbleTextLabel !== '' && $bubbleTextLabel !== $bubbleNum) {
                    $labelParts[] = $bubbleTextLabel;
                }
                if ($gdtSym !== '') $labelParts[] = $gdtSym;
                if ($nom !== '') {
                    $labelParts[] = $nom . ($unitRaw !== '' ? ' ' . $unitRaw : '');
                }
                if ($tolMinus !== '' || $tolPlus !== '') {
                    $tStr = '';
                    if ($tolMinus !== '' && $tolMinus === $tolPlus) {
                        $tStr = '±' . $tolPlus;
                    } else {
                        if ($tolPlus !== '')  $tStr .= '+' . $tolPlus;
                        if ($tolMinus !== '') $tStr .= ($tStr !== '' ? ' ' : '') . '-' . $tolMinus;
                    }
                    if ($tStr !== '') $labelParts[] = $tStr;
                }
                $label = implode(' ', $labelParts);
                if ($label === '') $label = 'Bubble ' . ($bubbleNum !== '' ? $bubbleNum : '?');

                $checkType = $nom !== '' && is_numeric($nom) ? 'numeric' : 'visual';
                $targetVal = $nom !== '' && is_numeric($nom) ? (float)$nom : null;
                // tolerance_lower/upper are stored as positive magnitudes:
                //   min = target - lower,  max = target + upper
                // The bubble tool sends tolMinus as a positive value (magnitude
                // of the lower deviation), so store it as-is.
                $tolLow    = $tolMinus !== '' && is_numeric($tolMinus) ? (float)$tolMinus : null;
                $tolHi     = $tolPlus  !== '' && is_numeric($tolPlus)  ? (float)$tolPlus  : null;

                // Only map known UOM codes to the unit column; the
                // dropdown wouldn't render an unknown unit. Bubble
                // tool uses 'mm', 'in', 'deg', 'none' — map deg→deg
                // which is in the seed.
                $unit = '';
                if (in_array($unitRaw, ['mm','in','deg'], true)) $unit = $unitRaw;

                $items[] = [
                    'id'                   => 0,    // unsaved
                    'template_id'          => $id,
                    'sort_order'           => 9999, // sorts to bottom; user can reorder
                    'label'                => $label,
                    'bubble_no'            => $bubbleNum !== '' ? mb_substr($bubbleNum, 0, 8) : null,
                    'gdt_symbol'           => $gdtSym !== '' ? mb_substr($gdtSym, 0, 8) : null,
                    'check_type'           => $checkType,
                    'target_value'         => $targetVal,
                    'tolerance_lower'      => $tolLow,
                    'tolerance_upper'      => $tolHi,
                    'unit'                 => $unit ?: null,
                    'is_required'          => 1,
                    'source_attachment_id' => $attachmentId ?: null,
                    'bubble_page'          => isset($b['page']) ? (int)$b['page'] : null,
                    'bubble_grid_cell'     => isset($b['grid_cell']) && $b['grid_cell'] !== ''
                                                ? mb_substr((string)$b['grid_cell'], 0, 8) : null,
                    'bubble_x'             => isset($b['ax']) ? (float)$b['ax'] : null,
                    'bubble_y'             => isset($b['ay']) ? (float)$b['ay'] : null,
                    '_from_bubble_stash'   => true,
                ];
            }

            // Consume the stash so a refresh doesn't re-import the rows.
            unset($_SESSION['magdyn_tpl_bubble'][$bubbleStash]);
            $bubbleCount = count($stash['bubbles'] ?? []);
            flash_set('success', $bubbleCount . ' bubble'
                . ($bubbleCount === 1 ? '' : 's')
                . ' loaded from the drawing. Review the rows below, edit as needed, and click "Save template".');
        }
    }
    // ---- End bubble integration ----

    // Load existing target links for the editor's multi-select. For
    // new templates, accept a preset target from the query string so
    // the asset/inventory gear menu's "+ Template" action can land
    // here with the entity already chosen.
    $existingTargets = inspection_template_targets($id);
    if (!$tpl) {
        $preTargetType = (string)input('target_entity_type', '');
        $preTargetId   = (int)input('target_entity_id', 0);
        if ($preTargetId && in_array($preTargetType, ['asset', 'inv_item'], true)) {
            // Build a synthetic target row in the same shape as the
            // existing-targets list so the renderer below treats both
            // uniformly. Resolve the label so the chip reads correctly.
            if ($preTargetType === 'asset') {
                $row = db_one('SELECT asset_tag FROM assets WHERE id = ?', [$preTargetId]);
                $lbl = $row ? $row['asset_tag'] : ('Asset #' . $preTargetId);
            } else {
                $row = db_one('SELECT code, short_description, name FROM inv_items WHERE id = ?', [$preTargetId]);
                $lbl = $row
                    ? ($row['code'] . ' — ' . ($row['short_description'] ?: $row['name']))
                    : ('Item #' . $preTargetId);
            }
            $existingTargets[] = [
                'entity_type' => $preTargetType,
                'entity_id'   => $preTargetId,
                'label'       => $lbl,
            ];
        }
    }

    // Fetch active UOMs for the unit dropdown. Sorted by category +
    // sort_order so the dropdown can render <optgroup> sections.
    // Falls back to empty array (and a free-text input) if the
    // inspection_uoms table doesn't exist yet — defensive for users
    // running on a half-migrated DB.
    $uoms = [];
    if (db_one("SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'inspection_uoms'")) {
        $uoms = db_all(
            'SELECT code, symbol, name, category
               FROM inspection_uoms
              WHERE is_active = 1
              ORDER BY category, sort_order, symbol'
        );
    }
    $uomsByCategory = [];
    foreach ($uoms as $u) {
        $cat = $u['category'] ?: 'other';
        $uomsByCategory[$cat][] = $u;
    }

    // GD&T symbol palette — the 14 standard geometric tolerance
    // symbols from ASME Y14.5 / ISO 1101 plus a "no symbol" option.
    // Stored on the row as the unicode character (compact, portable).
    $gdtSymbols = [
        ''  => ['label' => '— None —',                 'group' => ''],
        '⌀' => ['label' => 'Diameter',                 'group' => 'modifier'],
        '⌭' => ['label' => 'Counterbore / spotface',   'group' => 'modifier'],
        'R' => ['label' => 'Radius',                   'group' => 'modifier'],
        '□' => ['label' => 'Square',                   'group' => 'modifier'],
        '⏤' => ['label' => 'Straightness',             'group' => 'form'],
        '⏥' => ['label' => 'Flatness',                 'group' => 'form'],
        '○' => ['label' => 'Circularity / roundness',  'group' => 'form'],
        '⌭' => ['label' => 'Cylindricity',             'group' => 'form'],
        '⌒' => ['label' => 'Profile of a line',        'group' => 'profile'],
        '⌓' => ['label' => 'Profile of a surface',     'group' => 'profile'],
        '∠' => ['label' => 'Angularity',               'group' => 'orientation'],
        '⟂' => ['label' => 'Perpendicularity',         'group' => 'orientation'],
        '∥' => ['label' => 'Parallelism',              'group' => 'orientation'],
        '⊕' => ['label' => 'Position',                 'group' => 'location'],
        '◎' => ['label' => 'Concentricity',            'group' => 'location'],
        '⌯' => ['label' => 'Symmetry',                 'group' => 'location'],
        '↗' => ['label' => 'Circular runout',          'group' => 'runout'],
        '⌰' => ['label' => 'Total runout',             'group' => 'runout'],
    ];
    // Note: the unicode for cylindricity above (⌭) clashes with
    // counterbore; canonically cylindricity is ⌭ but counterbore is
    // ⌴ (U+2334). Fix:
    $gdtSymbols = [
        ''  => ['label' => '— None —',                 'group' => ''],
        '⌀' => ['label' => 'Diameter',                 'group' => 'modifier'],
        '⌴' => ['label' => 'Counterbore / spotface',   'group' => 'modifier'],
        'R' => ['label' => 'Radius',                   'group' => 'modifier'],
        '□' => ['label' => 'Square',                   'group' => 'modifier'],
        '⏤' => ['label' => 'Straightness',             'group' => 'form'],
        '⏥' => ['label' => 'Flatness',                 'group' => 'form'],
        '○' => ['label' => 'Circularity',              'group' => 'form'],
        '⌭' => ['label' => 'Cylindricity',             'group' => 'form'],
        '⌒' => ['label' => 'Line profile',             'group' => 'profile'],
        '⌓' => ['label' => 'Surface profile',          'group' => 'profile'],
        '∠' => ['label' => 'Angularity',               'group' => 'orientation'],
        '⟂' => ['label' => 'Perpendicularity',         'group' => 'orientation'],
        '∥' => ['label' => 'Parallelism',              'group' => 'orientation'],
        '⊕' => ['label' => 'Position',                 'group' => 'location'],
        '◎' => ['label' => 'Concentricity',            'group' => 'location'],
        '⌯' => ['label' => 'Symmetry',                 'group' => 'location'],
        '↗' => ['label' => 'Circular runout',          'group' => 'runout'],
        '⌰' => ['label' => 'Total runout',             'group' => 'runout'],
    ];

    /**
     * Helper to render the unit <select>. Returns HTML. Falls back to
     * a plain text input if no UOMs are configured. Optgroups keep the
     * dropdown scannable across categories (length / angle / pressure).
     */
    $renderUomSelect = function ($currentCode) use ($uomsByCategory, $uoms) {
        if (!$uoms) {
            // No UOMs seeded — degrade to text input
            return '<input type="text" name="item_unit[]" value="' . h($currentCode) . '"'
                 . ' placeholder="mm" title="No UOMs configured. Add some at /inspection_uoms.php">';
        }
        $h = '<select name="item_unit[]" class="no-combobox">';
        $h .= '<option value="">—</option>';
        foreach ($uomsByCategory as $cat => $rows) {
            $h .= '<optgroup label="' . h($cat) . '">';
            foreach ($rows as $u) {
                $sel = ((string)$currentCode === (string)$u['code']) ? ' selected' : '';
                $h .= '<option value="' . h($u['code']) . '"' . $sel . '>'
                    . h($u['symbol']) . ' — ' . h($u['name']) . '</option>';
            }
            $h .= '</optgroup>';
        }
        $h .= '</select>';
        return $h;
    };

    /**
     * Helper to render the GD&T select.
     */
    $renderGdtSelect = function ($currentSymbol) use ($gdtSymbols) {
        $h = '<select name="item_gdt_symbol[]" class="no-combobox tpl-gdt-select" title="GD&T symbol">';
        foreach ($gdtSymbols as $sym => $info) {
            $sel = ((string)$currentSymbol === (string)$sym) ? ' selected' : '';
            $label = $sym === '' ? '— None —' : ($sym . '  ' . $info['label']);
            $h .= '<option value="' . h($sym) . '"' . $sel . '>' . h($label) . '</option>';
        }
        $h .= '</select>';
        return $h;
    };

    // Active assets list for the per-row instrument picker. Fetched
    // once up here so every row's <select> reuses the same option set
    // (no N+1). Filter is hard is_active=1 — no asset category gate,
    // per spec answer 9. Order by code to match how the picker on
    // inspection.php?action=new sorts.
    // The assets table has no is_active column — activity is tracked
    // via status. Exclude archived assets; with_vendor / with_user
    // assets are still valid instruments to associate with a template.
    $activeAssets = db_all("SELECT id, asset_tag AS code FROM assets WHERE status <> 'archived' ORDER BY asset_tag");
    // Also pre-fetch a name lookup so the option labels read "TAG —
    // model name" where available. Done as a separate query because
    // model_id is a FK on assets, not a denormalised name.
    $assetModelName = [];
    if ($activeAssets) {
        $ids = array_map(function ($a) { return (int)$a['id']; }, $activeAssets);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach (db_all(
            "SELECT a.id, m.name AS model_name
               FROM assets a
          LEFT JOIN asset_models m ON m.id = a.model_id
              WHERE a.id IN ($placeholders)",
            $ids
        ) as $r) {
            $assetModelName[(int)$r['id']] = $r['model_name'] ?? '';
        }
    }
    $renderInstrumentSelect = function ($currentId) use ($activeAssets, $assetModelName) {
        $h = '<select name="item_instrument_id[]" class="no-combobox tpl-instr-select" title="Instrument (active assets only)">';
        $h .= '<option value="">— None —</option>';
        foreach ($activeAssets as $a) {
            $sel = ((int)$currentId === (int)$a['id']) ? ' selected' : '';
            $label = $a['code'];
            $nm = $assetModelName[(int)$a['id']] ?? '';
            if ($nm !== '') $label .= ' — ' . $nm;
            $h .= '<option value="' . (int)$a['id'] . '"' . $sel . '>' . h($label) . '</option>';
        }
        $h .= '</select>';
        return $h;
    };

    $page_title  = $tpl ? ('Template: ' . $tpl['name']) : 'New template';
    $page_module = 'inspection';
    $focus_id    = 'f_name';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'        => $tpl ? 'Edit template' : 'New template',
            'subtitle'     => $tpl ? h($tpl['code']) : 'Define the checklist items inspectors will see',
            'back_href'    => url('/inspection.php?action=templates'),
            'back_label'   => 'Templates',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Save template', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inspection.php?action=templates')) . '">Cancel</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/inspection.php?action=template_save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="upsert">
            <input type="hidden" name="id" value="<?= (int)$id ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="f_code">Code</label>
                    <?php if (!$tpl):
                        // NEW: show a server-generated preview of the next code
                        // as readonly. The actual code is assigned at save time
                        // (race-safe). No `name` attribute → value doesn't post.
                        $tplPreview = inspection_template_next_code();
                    ?>
                        <input id="f_code" type="text" tabindex="-1" readonly
                               value="<?= h($tplPreview) ?>"
                               style="font-family: var(--font-mono, monospace); background: var(--surface-alt, #f6f7f9);">
                        <span class="muted small">Auto-generated when you save. Preview shown.</span>
                    <?php else:
                        // EDIT: code is immutable — system-assigned at create.
                    ?>
                        <input id="f_code" readonly tabindex="-1" maxlength="40"
                               value="<?= h($tpl['code']) ?>"
                               style="font-family: var(--font-mono, monospace); background: var(--surface-alt, #f6f7f9);">
                        <span class="muted small">System-assigned · immutable.</span>
                    <?php endif; ?>
                </div>
                <div class="field span-2">
                    <label for="f_name">Name *</label>
                    <input id="f_name" name="name" required maxlength="200" value="<?= h($tpl['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_type">For inspection type</label>
                    <select id="f_type" name="inspection_type" class="no-combobox">
                        <?php $cur = $tpl['inspection_type'] ?? 'any'; ?>
                        <option value="any"            <?= $cur==='any'?'selected':''?>>Any</option>
                        <option value="incoming"       <?= $cur==='incoming'?'selected':''?>>Incoming material</option>
                        <option value="asset_cal"      <?= $cur==='asset_cal'?'selected':''?>>Asset calibration</option>
                        <option value="finished_goods" <?= $cur==='finished_goods'?'selected':''?>>Finished goods QC</option>
                        <option value="first_article"  <?= $cur==='first_article'?'selected':''?>>First article</option>
                        <option value="adhoc"          <?= $cur==='adhoc'?'selected':''?>>Ad-hoc</option>
                    </select>
                </div>
                <div class="field span-4">
                    <label for="f_desc">Description</label>
                    <textarea id="f_desc" name="description" rows="2"><?= h($tpl['description'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="f_is_active">Status</label>
                    <select id="f_is_active" name="is_active" class="no-combobox">
                        <option value="1" <?= ($tpl['is_active'] ?? 1) ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= !($tpl['is_active'] ?? 1) ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <h3 style="margin-top: 28px;">Applies to</h3>
            <p class="muted small">
                Optionally link this template to specific assets or inventory items. When planning an
                inspection from an asset's or item's page, linked templates surface first in the picker.
                Leave empty to make the template available everywhere.
            </p>
            <div class="form-grid">
                <div class="field span-2">
                    <label for="t_asset_search">Assets</label>
                    <div class="entity-picker tpl-tgt-picker" data-entity-type="asset" data-field-name="target_asset_id">
                        <input id="t_asset_search" type="text" placeholder="Type asset tag…" class="entity-picker-search" autocomplete="off">
                        <div class="entity-picker-dropdown" hidden></div>
                        <div class="tpl-tgt-chips"></div>
                    </div>
                </div>
                <div class="field span-2">
                    <label for="t_item_search">Inventory items</label>
                    <div class="entity-picker tpl-tgt-picker" data-entity-type="inv_item" data-field-name="target_inv_item_id">
                        <input id="t_item_search" type="text" placeholder="Type item code…" class="entity-picker-search" autocomplete="off">
                        <div class="entity-picker-dropdown" hidden></div>
                        <div class="tpl-tgt-chips"></div>
                    </div>
                </div>
            </div>

            <h3 style="margin-top: 28px;">Checklist items</h3>
            <p class="muted small">
                Each row is one check the inspector records. Leave a row's label empty to delete it on save.
                <button type="button" id="tpl-bubble-launch-btn" class="btn btn-ghost btn-sm"
                        style="margin-left: 12px; vertical-align: middle;"
                        title="Upload a drawing (PDF or image) and add inspection items by drawing bubbles on it">⊕ Bubble drawing → items</button>
            </p>
            <?php if ($pendingAnnotatedAttach !== ''): ?>
                <input type="hidden" name="pending_annotated_path" value="<?= h($pendingAnnotatedAttach) ?>">
                <input type="hidden" name="pending_annotated_name" value="<?= h($pendingAnnotatedAttachName) ?>">
                <p class="small" style="color: #2563eb;">📎 Annotated drawing staged — will attach to this template on save.</p>
            <?php endif; ?>

            <?php if (!empty($attachments)): ?>
                <!--
                  Attachments list — read-only listing of drawings already
                  attached to this template. Each row shows View (open the
                  drawing in a new tab) and a bubble count so the user can
                  see what's been annotated. The "Edit bubbles" re-launch
                  flow was removed pending a rework; to add more bubbles
                  to a drawing today, the user uploads it again via
                  "+ Bubble drawing → items" below.
                -->
                <h4 style="margin-top: 20px; margin-bottom: 6px;">Attached drawings</h4>
                <p class="muted small" style="margin-bottom: 8px;">
                    Drawings previously attached to this template. To add more
                    bubbles to one, re-upload it via the <strong>+ Bubble drawing → items</strong>
                    button below.
                </p>
                <div class="tpl-attachments-list" style="margin-bottom: 18px; border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
                <?php foreach ($attachments as $att):
                    $relPath = (string)$att['stored_path'];
                    $downloadUrl = url('/' . ltrim($relPath, '/'));
                    switch ((string)$att['kind']) {
                        case 'annotated_drawing': $kindLabel = '📐 Annotated'; break;
                        case 'drawing':           $kindLabel = '📎 Drawing'; break;
                        case 'reference':         $kindLabel = '🗒 Reference'; break;
                        default:                  $kindLabel = '📎'; break;
                    }
                ?>
                    <div class="tpl-att-row"
                         style="display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom: 1px solid var(--border); background: var(--surface);">
                        <div style="flex: 0 0 auto; font-size: 13px; color: var(--text-muted);">
                            <?= $kindLabel ?>
                        </div>
                        <div style="flex: 1 1 auto; min-width: 0;">
                            <div style="font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= h($att['filename']) ?>
                            </div>
                            <div class="muted small">
                                <?= (int)$att['item_count'] ?> bubble<?= (int)$att['item_count'] === 1 ? '' : 's' ?>
                                · <?= number_format(((int)$att['size_bytes']) / 1024, 1) ?> KB
                                · <?= h(substr((string)$att['uploaded_at'], 0, 16)) ?>
                            </div>
                        </div>
                        <div style="flex: 0 0 auto; display: flex; gap: 6px;">
                            <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener"
                               href="<?= h($downloadUrl) ?>"
                               title="Open the drawing in a new tab">View</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="tpl-items-wrap">
                <table class="data-table tpl-items">
                    <thead>
                        <tr>
                            <th style="width: 5%;" title="Balloon number on the engineering drawing">Bbl</th>
                            <th style="width: 5%;" title="Grid cell location on the drawing (e.g. A3)">Grid</th>
                            <th style="width: 17%;">Label</th>
                            <th style="width: 8%;">GD&amp;T</th>
                            <th style="width: 10%;">Check type</th>
                            <th style="width: 9%;">Target</th>
                            <th style="width: 9%;">Tol −</th>
                            <th style="width: 9%;">Tol +</th>
                            <th style="width: 12%;">Unit</th>
                            <th style="width: 12%;" title="Instrument used for this measurement (active assets only)">Instrument</th>
                            <th style="width: 6%;">Req.</th>
                            <th style="width: 7%;"></th>
                        </tr>
                    </thead>
                    <tbody id="tpl-items-body">
                    <?php
                    // Render existing rows. We always emit a couple of empty
                    // rows at the bottom so the user can add new ones inline.
                    $rowIdx = 0;
                    foreach ($items as $it):
                    ?>
                        <tr class="tpl-item-row<?= !empty($it['_from_bubble_stash']) ? ' tpl-item-row-from-bubble' : '' ?>"
                            <?= !empty($it['_from_bubble_stash']) ? 'title="From bubble drawing"' : '' ?>>
                            <td>
                                <input type="text" class="tpl-bubble" name="item_bubble_no[]" maxlength="8"
                                       value="<?= h($it['bubble_no'] ?? '') ?>" placeholder="#">
                                <input type="hidden" name="item_attachment_id[]" value="<?= (int)($it['source_attachment_id'] ?? 0) ?>">
                                <input type="hidden" name="item_bubble_page[]"   value="<?= h($it['bubble_page'] ?? '') ?>">
                                <input type="hidden" name="item_bubble_x[]"      value="<?= h($it['bubble_x'] ?? '') ?>">
                                <input type="hidden" name="item_bubble_y[]"      value="<?= h($it['bubble_y'] ?? '') ?>">
                            </td>
                            <td>
                                <input type="text" class="tpl-bubble" name="item_bubble_grid_cell[]" maxlength="8"
                                       value="<?= h($it['bubble_grid_cell'] ?? '') ?>" placeholder="A1">
                            </td>
                            <td><input type="text" name="item_label[]" value="<?= h($it['label']) ?>"></td>
                            <td><?= $renderGdtSelect($it['gdt_symbol'] ?? '') ?></td>
                            <td>
                                <select name="item_check_type[]" class="no-combobox item-check-type-sel">
                                    <option value="boolean"          <?= $it['check_type']==='boolean'         ?'selected':''?>>Pass/Fail</option>
                                    <option value="numeric"          <?= $it['check_type']==='numeric'         ?'selected':''?>>Numeric</option>
                                    <option value="text"             <?= $it['check_type']==='text'            ?'selected':''?>>Text</option>
                                    <option value="visual"           <?= $it['check_type']==='visual'          ?'selected':''?>>Visual</option>
                                    <option value="nom"              <?= $it['check_type']==='nom'             ?'selected':''?>>NOM</option>
                                    <option value="min-max"          <?= $it['check_type']==='min-max'         ?'selected':''?>>MIN/MAX</option>
                                    <option value="logic"            <?= $it['check_type']==='logic'           ?'selected':''?>>LOGIC</option>
                                    <option value="logical-min-max"  <?= $it['check_type']==='logical-min-max' ?'selected':''?>>LOGICAL-MIN/MAX</option>
                                    <option value="logical-nom"      <?= $it['check_type']==='logical-nom'     ?'selected':''?>>LOGICAL-NOM</option>
                                    <option value="notes"            <?= $it['check_type']==='notes'           ?'selected':''?>>NOTES</option>
                                </select>
                            </td>
                            <td><input type="text" name="item_target_value[]"     class="item-spec-target" value="<?= h($it['target_value']) ?>"></td>
                            <td><input type="text" name="item_tolerance_lower[]"  class="item-spec-lower"  value="<?= h($it['tolerance_lower']) ?>"></td>
                            <td><input type="text" name="item_tolerance_upper[]"  class="item-spec-upper"  value="<?= h($it['tolerance_upper']) ?>"></td>
                            <td><?= $renderUomSelect($it['unit'] ?? '') ?></td>
                            <td><?= $renderInstrumentSelect($it['instrument_asset_id'] ?? 0) ?></td>
                            <td class="c"><input type="checkbox" name="item_required[<?= $rowIdx ?>]" value="1" <?= $it['is_required'] ? 'checked' : '' ?>></td>
                            <td class="r"><button type="button" class="btn btn-icon btn-danger tpl-item-del" title="Remove">🗑</button></td>
                        </tr>
                    <?php $rowIdx++; endforeach; ?>
                    <?php for ($k = 0; $k < 3; $k++): $i = $rowIdx + $k; ?>
                        <tr class="tpl-item-row">
                            <td>
                                <input type="text" class="tpl-bubble" name="item_bubble_no[]" maxlength="8" placeholder="#">
                                <input type="hidden" name="item_attachment_id[]" value="0">
                                <input type="hidden" name="item_bubble_page[]"   value="">
                                <input type="hidden" name="item_bubble_x[]"      value="">
                                <input type="hidden" name="item_bubble_y[]"      value="">
                            </td>
                            <td>
                                <input type="text" class="tpl-bubble" name="item_bubble_grid_cell[]" maxlength="8" placeholder="A1">
                            </td>
                            <td><input type="text" name="item_label[]" placeholder="(empty row — fill to add)"></td>
                            <td><?= $renderGdtSelect('') ?></td>
                            <td>
                                <select name="item_check_type[]" class="no-combobox item-check-type-sel">
                                    <option value="boolean" selected>Pass/Fail</option>
                                    <option value="numeric">Numeric</option>
                                    <option value="text">Text</option>
                                    <option value="visual">Visual</option>
                                    <option value="nom">NOM</option>
                                    <option value="min-max">MIN/MAX</option>
                                    <option value="logic">LOGIC</option>
                                    <option value="logical-min-max">LOGICAL-MIN/MAX</option>
                                    <option value="logical-nom">LOGICAL-NOM</option>
                                    <option value="notes">NOTES</option>
                                </select>
                            </td>
                            <td><input type="text" name="item_target_value[]"     class="item-spec-target"></td>
                            <td><input type="text" name="item_tolerance_lower[]"  class="item-spec-lower"></td>
                            <td><input type="text" name="item_tolerance_upper[]"  class="item-spec-upper"></td>
                            <td><?= $renderUomSelect('') ?></td>
                            <td><?= $renderInstrumentSelect(0) ?></td>
                            <td class="c"><input type="checkbox" name="item_required[<?= $i ?>]" value="1"></td>
                            <td class="r"><button type="button" class="btn btn-icon btn-danger tpl-item-del" title="Remove">🗑</button></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
                <button type="button" id="tpl-item-add" class="btn btn-ghost btn-sm" style="margin-top: 8px;">+ Add row</button>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var body = document.getElementById('tpl-items-body');
        var addBtn = document.getElementById('tpl-item-add');
        // Pre-rendered <select> markup for the GD&T and Unit columns,
        // generated server-side so the JS-cloned blank row matches the
        // server-emitted rows exactly (same options, same labels).
        var gdtSelectHtml   = <?= json_encode($renderGdtSelect(''), JSON_UNESCAPED_UNICODE) ?>;
        var uomSelectHtml   = <?= json_encode($renderUomSelect(''), JSON_UNESCAPED_UNICODE) ?>;
        var instrSelectHtml = <?= json_encode($renderInstrumentSelect(0), JSON_UNESCAPED_UNICODE) ?>;

        function blankRow() {
            var tr = document.createElement('tr');
            tr.className = 'tpl-item-row';
            tr.innerHTML =
              '<td>' +
                '<input type="text" class="tpl-bubble" name="item_bubble_no[]" maxlength="8" placeholder="#">' +
                '<input type="hidden" name="item_attachment_id[]" value="0">' +
                '<input type="hidden" name="item_bubble_page[]"   value="">' +
                '<input type="hidden" name="item_bubble_x[]"      value="">' +
                '<input type="hidden" name="item_bubble_y[]"      value="">' +
              '</td>' +
              '<td>' +
                '<input type="text" class="tpl-bubble" name="item_bubble_grid_cell[]" maxlength="8" placeholder="A1">' +
              '</td>' +
              '<td><input type="text" name="item_label[]" placeholder="(empty row — fill to add)"></td>' +
              '<td>' + gdtSelectHtml + '</td>' +
              '<td><select name="item_check_type[]" class="no-combobox item-check-type-sel">' +
                '<option value="boolean" selected>Pass/Fail</option>' +
                '<option value="numeric">Numeric</option>' +
                '<option value="text">Text</option>' +
                '<option value="visual">Visual</option>' +
                '<option value="nom">NOM</option>' +
                '<option value="min-max">MIN/MAX</option>' +
                '<option value="logic">LOGIC</option>' +
                '<option value="logical-min-max">LOGICAL-MIN/MAX</option>' +
                '<option value="logical-nom">LOGICAL-NOM</option>' +
                '<option value="notes">NOTES</option>' +
                '</select></td>' +
              '<td><input type="text" name="item_target_value[]"    class="item-spec-target"></td>' +
              '<td><input type="text" name="item_tolerance_lower[]" class="item-spec-lower"></td>' +
              '<td><input type="text" name="item_tolerance_upper[]" class="item-spec-upper"></td>' +
              '<td>' + uomSelectHtml + '</td>' +
              '<td>' + instrSelectHtml + '</td>' +
              '<td class="c"><input type="checkbox" name="item_required[]" value="1"></td>' +
              '<td class="r"><button type="button" class="btn btn-icon btn-danger tpl-item-del" title="Remove">🗑</button></td>';
            return tr;
        }
        addBtn.addEventListener('click', function () { body.appendChild(blankRow()); });
        body.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.tpl-item-del');
            if (!btn) return;
            var tr = btn.closest('tr');
            if (tr) tr.parentNode.removeChild(tr);
        });

        // ---- Spec column behaviour per check type ----
        // NOM / LOGICAL-NOM / numeric : Target=Nominal, Lower=Tol-Neg, Upper=Tol-Pos
        // MIN-MAX / LOGICAL-MIN-MAX   : Target dimmed, Lower=Min, Upper=Max
        // everything else             : all three dimmed
        //
        // clearValues=true only when the user explicitly changes the type
        // (change event). On initial page load we pass false so that
        // already-saved values are not wiped by the initialisation sweep.
        function updateSpecCols(row, clearValues) {
            var sel    = row.querySelector('.item-check-type-sel');
            if (!sel) return;
            var ct     = sel.value;
            var tInp   = row.querySelector('.item-spec-target');
            var lInp   = row.querySelector('.item-spec-lower');
            var uInp   = row.querySelector('.item-spec-upper');
            if (!tInp || !lInp || !uInp) return;
            var tTd = tInp.parentNode, lTd = lInp.parentNode, uTd = uInp.parentNode;
            // Reset visual state
            [tTd, lTd, uTd].forEach(function(td){ td.style.opacity=''; });
            tInp.readOnly = lInp.readOnly = uInp.readOnly = false;
            tInp.placeholder = lInp.placeholder = uInp.placeholder = '';
            if (ct === 'nom' || ct === 'logical-nom' || ct === 'numeric') {
                tInp.placeholder = 'Nominal';
                lInp.placeholder = 'Tol-Neg';
                uInp.placeholder = 'Tol-Pos';
            } else if (ct === 'min-max' || ct === 'logical-min-max') {
                if (clearValues) tInp.value = '';
                tInp.readOnly = true; tTd.style.opacity = '0.25';
                lInp.placeholder = 'Min';
                uInp.placeholder = 'Max';
            } else {
                // logic, notes, boolean, visual, text
                if (clearValues) { tInp.value = ''; lInp.value = ''; uInp.value = ''; }
                tInp.readOnly = true; tTd.style.opacity = '0.25';
                lInp.readOnly = true; lTd.style.opacity = '0.25';
                uInp.readOnly = true; uTd.style.opacity = '0.25';
            }
        }
        // Wire change handler on all existing rows and run once on load
        function wireRow(row) {
            var sel = row.querySelector('.item-check-type-sel');
            if (!sel) return;

            // Record the PHP-rendered (authoritative) value so we can restore it
            // if a combobox enhancement or other script resets the select.
            var savedType = sel.value;

            // A "real" user change is only possible after the user opens the
            // select via pointer or keyboard. Flag lets programmatic change
            // events (combobox init, autofill) pass through without clearing.
            var userInteracted = false;
            sel.addEventListener('mousedown', function () { userInteracted = true; });
            sel.addEventListener('keydown',   function () { userInteracted = true; });

            sel.addEventListener('change', function () {
                if (userInteracted) {
                    // Genuine type switch — clear stale spec values
                    savedType = sel.value;
                    updateSpecCols(row, true);
                } else {
                    // Programmatic change (combobox init, etc.) — restore the
                    // PHP-rendered value and apply visual state without clearing
                    sel.value = savedType;
                    updateSpecCols(row, false);
                }
            });

            // Initial pass: visual state only, never wipe existing saved values
            updateSpecCols(row, false);
        }
        Array.prototype.forEach.call(body.querySelectorAll('tr.tpl-item-row'), wireRow);
        // Wire rows added dynamically
        var _origAddBtn = addBtn.onclick;
        addBtn.addEventListener('click', function(){
            var rows = body.querySelectorAll('tr.tpl-item-row');
            if (rows.length) wireRow(rows[rows.length - 1]);
        });

        // -----------------------------------------------------------
        // Target pickers — multi-select for asset / inv_item targets.
        // Each .tpl-tgt-picker is one picker. Selected targets render
        // as chips; each chip holds the hidden form input named after
        // data-field-name (e.g. target_asset_id[]).
        // -----------------------------------------------------------
        var initialTargets = <?= json_encode($existingTargets, JSON_UNESCAPED_UNICODE) ?>;

        function addChip(pickerEl, id, label) {
            id = parseInt(id, 10);
            if (!id) return;
            var chipsBox  = pickerEl.querySelector('.tpl-tgt-chips');
            var fieldName = pickerEl.getAttribute('data-field-name');
            // Dedupe by id
            if (chipsBox.querySelector('input[value="' + id + '"]')) return;
            var chip = document.createElement('span');
            chip.className = 'tpl-tgt-chip';
            chip.innerHTML =
                '<input type="hidden" name="' + fieldName + '[]" value="' + id + '">' +
                '<span class="chip-label"></span>' +
                ' <button type="button" class="chip-remove" title="Remove">✕</button>';
            chip.querySelector('.chip-label').textContent = label;
            chip.querySelector('.chip-remove').addEventListener('click', function () {
                chip.parentNode.removeChild(chip);
            });
            chipsBox.appendChild(chip);
        }

        // Seed chips from server-provided list
        var pickers = document.querySelectorAll('.tpl-tgt-picker');
        pickers.forEach(function (p) {
            var wantType = p.getAttribute('data-entity-type');
            initialTargets.forEach(function (t) {
                if (t.entity_type === wantType) {
                    addChip(p, t.entity_id, t.label);
                }
            });
        });

        // Wire each picker's search → /inspection.php?action=entity_picker
        pickers.forEach(function (picker) {
            var search   = picker.querySelector('.entity-picker-search');
            var dropdown = picker.querySelector('.entity-picker-dropdown');
            var entType  = picker.getAttribute('data-entity-type');
            var timer    = null;

            function doSearch() {
                var q = search.value.trim();
                var u = (window.MAGDYN_BASE || '') + '/inspection.php?action=entity_picker'
                      + '&entity_type=' + encodeURIComponent(entType)
                      + '&q=' + encodeURIComponent(q);
                fetch(u, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        dropdown.innerHTML = '';
                        if (!data.rows || !data.rows.length) {
                            dropdown.innerHTML = '<div class="entity-picker-empty muted small">No matches.</div>';
                        } else {
                            data.rows.forEach(function (r) {
                                var item = document.createElement('div');
                                item.className = 'entity-picker-item';
                                item.dataset.id = r.id;
                                item.dataset.label = r.code + (r.label ? ' — ' + r.label : '');
                                item.textContent = item.dataset.label;
                                dropdown.appendChild(item);
                            });
                        }
                        dropdown.hidden = false;
                    });
            }

            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(doSearch, 200);
            });
            search.addEventListener('focus', function () { doSearch(); });
            // Picking an item adds a chip; clicking outside closes.
            picker.addEventListener('mousedown', function (e) {
                var item = e.target.closest && e.target.closest('.entity-picker-item');
                if (item) {
                    addChip(picker, item.dataset.id, item.dataset.label);
                    search.value = '';
                    dropdown.hidden = true;
                    e.preventDefault();  // keep focus from jumping
                }
            });
        });
        document.addEventListener('mousedown', function (e) {
            if (e.target.closest && e.target.closest('.tpl-tgt-picker')) return;
            document.querySelectorAll('.tpl-tgt-picker .entity-picker-dropdown').forEach(function (d) {
                d.hidden = true;
            });
        });
    })();
    </script>

    <!-- Bubble-tool launch modal: lives outside #main-form so its file
         upload submits to /inspection.php?action=template_bubble_launch
         without colliding with the main form. -->
    <div id="tpl-bubble-launch-modal" class="att-preview-modal" hidden>
        <div class="att-preview-backdrop" data-tpl-bubble-close></div>
        <div class="att-preview-dialog" role="dialog" aria-label="Bubble drawing to items"
             style="max-width: 540px; margin: auto; height: auto;">
            <div class="att-preview-head">
                <span class="att-preview-name">Bubble drawing → inspection items</span>
                <button type="button" class="btn btn-icon att-preview-close-btn"
                        data-tpl-bubble-close title="Close">✕</button>
            </div>
            <form method="post"
                  action="<?= h(url('/inspection.php?action=template_bubble_launch')) ?>"
                  enctype="multipart/form-data"
                  style="padding: 18px;">
                <?= csrf_field() ?>
                <input type="hidden" name="template_id" value="<?= (int)$id ?>">
                <div class="field" data-drop-zone="tpl-bubble-drawing">
                    <label>Drawing file (PDF or image) * <span class="muted small">(or drag onto this area)</span></label>
                    <input name="drawing" type="file"
                           accept="application/pdf,image/png,image/jpeg,image/gif,image/webp"
                           required>
                </div>
                <div class="muted small" style="margin-top: 10px;">
                    Opens the bubble tool with the uploaded drawing. Place bubbles by
                    clicking, or use the Auto-bubble button to detect dimensions
                    automatically (server OCR with a Tesseract fallback when the
                    server is unavailable). Click <strong>Save to template</strong>
                    in the bubble tool to return here with the bubbles staged as
                    new checklist items.
                    <?php if (!$id): ?>
                        <br><br><strong>Heads-up:</strong> this template hasn't been saved yet.
                        Save it once with at least a name + code, then re-open it to attach
                        the annotated drawing as a template attachment. (Bubble items will
                        still come back attached to the un-saved form on the first round.)
                    <?php endif; ?>
                </div>
                <div style="margin-top: 16px; display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-tpl-bubble-close>Cancel</button>
                    <button type="submit" class="btn btn-primary" data-bubble-submit>Open bubble tool →</button>
                </div>
            </form>
        </div>
    </div>
    <style>
    .tpl-item-row-from-bubble { background: #fff7ed; }
    .tpl-item-row-from-bubble td:first-child input.tpl-bubble {
        border-color: #f59e0b;
        background: #fffbeb;
    }
    </style>
    <script>
    (function () {
        var modal = document.getElementById('tpl-bubble-launch-modal');
        var btn   = document.getElementById('tpl-bubble-launch-btn');
        if (modal && btn) {
            btn.addEventListener('click', function () {
                modal.hidden = false;
                document.body.classList.add('att-preview-modal-open');
            });
            document.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('[data-tpl-bubble-close]')) {
                    modal.hidden = true;
                    document.body.classList.remove('att-preview-modal-open');
                }
            });
        }

        // Press-state for any submit that opens the bubble tool. The
        // launch round-trip (upload → server stage → redirect) can take
        // several seconds for large drawings; without feedback users
        // assume the click did nothing and press repeatedly, which
        // queues duplicate session stashes. We disable the button and
        // swap its label to "Opening…" as soon as the form starts to
        // submit. A guard timer re-enables after 30s in case the
        // navigation gets interrupted (browser-side error, redirect
        // loop, etc.) so the user isn't permanently stuck.
        document.querySelectorAll('[data-bubble-submit]').forEach(function (sb) {
            var form = sb.form || sb.closest('form');
            if (!form) return;
            form.addEventListener('submit', function () {
                if (sb._magdynPressed) return;
                sb._magdynPressed = true;
                sb.dataset.origLabel = sb.textContent;
                sb.textContent = 'Opening…';
                sb.disabled = true;
                // Also dim any sibling submits inside the same form
                // (Cancel etc. shouldn't be killed, but extra submits
                // should). Most forms here have just the one submit.
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (s) {
                    if (s !== sb) s.disabled = true;
                });
                setTimeout(function () {
                    if (!sb.disabled) return;
                    sb._magdynPressed = false;
                    sb.disabled = false;
                    if (sb.dataset.origLabel) sb.textContent = sb.dataset.origLabel;
                }, 30000);
            });
        });
    })();
    </script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =================================================================
// NEW — plan a new inspection
// =================================================================
if ($action === 'new') {
    require_permission('inspection', 'create');

    $preType = (string)input('inspection_type', '');
    $preEntityType = (string)input('entity_type', '');
    $preEntityId   = (int)input('entity_id', 0);

    // When the page is opened via deep link from another module (e.g.
    // inv_txn gear menu's "Inspect" item), resolve the target's label
    // so the picker's "chosen" chip shows what was picked. Without
    // this the user lands on the form with a hidden ID and no visible
    // confirmation.
    $prefilledLabel = '';
    if ($preEntityType && $preEntityId) {
        $resolved = inspection_resolve_entity($preEntityType, $preEntityId);
        if (is_array($resolved) && count($resolved) >= 1) {
            $prefilledLabel = (string)$resolved[0];
        }
    }

    // Fetch all active templates, plus a flag marking which ones are
    // linked to the prefilled entity (if any). The picker will show
    // linked templates first; the "Show all" toggle reveals the rest.
    // When no entity is prefilled, every template's "linked" flag
    // stays 0 and the toggle effectively shows all templates from
    // the start.
    if ($preEntityType && $preEntityId
        && in_array($preEntityType, ['asset', 'inv_item'], true)
    ) {
        $templates = db_all(
            "SELECT t.id, t.code, t.name, t.inspection_type,
                    CASE WHEN tt.template_id IS NOT NULL THEN 1 ELSE 0 END AS linked
               FROM inspection_templates t
               LEFT JOIN inspection_template_targets tt
                 ON tt.template_id = t.id
                AND tt.entity_type = ?
                AND tt.entity_id   = ?
              WHERE t.is_active = 1
              ORDER BY linked DESC, t.name",
            [$preEntityType, $preEntityId]
        );
    } else {
        $templates = db_all(
            'SELECT id, code, name, inspection_type, 0 AS linked
               FROM inspection_templates
              WHERE is_active = 1
              ORDER BY name'
        );
    }
    $hasLinkedTemplates = false;
    foreach ($templates as $t) {
        if ((int)$t['linked'] === 1) { $hasLinkedTemplates = true; break; }
    }

    $page_title  = 'Plan inspection';
    $page_module = 'inspection';
    $focus_id    = 'f_type';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'        => 'Plan inspection',
            'subtitle'     => 'Create a new inspection record to be executed by an inspector',
            'back_href'    => url('/inspection.php'),
            'back_label'   => 'Inspections',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Create', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inspection.php')) . '">Cancel</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/inspection.php?action=save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="create">

            <div class="form-grid">
                <div class="field">
                    <label for="f_type">Inspection type *</label>
                    <select id="f_type" name="inspection_type" required class="no-combobox">
                        <option value="incoming"       <?= $preType==='incoming'?'selected':''?>>Incoming material</option>
                        <option value="asset_cal"      <?= $preType==='asset_cal'?'selected':''?>>Asset calibration</option>
                        <option value="finished_goods" <?= $preType==='finished_goods'?'selected':''?>>Finished goods QC</option>
                        <option value="first_article"  <?= $preType==='first_article'?'selected':''?>>First article</option>
                        <option value="adhoc"          <?= $preType==='adhoc' || $preType===''?'selected':''?>>Ad-hoc</option>
                    </select>
                </div>
                <div class="field">
                    <label for="f_ent_type">Attached to</label>
                    <select id="f_ent_type" name="entity_type" class="no-combobox">
                        <option value="none"     <?= $preEntityType==='none'     || $preEntityType==='' ?'selected':''?>>— Standalone —</option>
                        <option value="asset"    <?= $preEntityType==='asset'    ?'selected':''?>>Asset</option>
                        <option value="inv_item" <?= $preEntityType==='inv_item' ?'selected':''?>>Inventory item</option>
                        <option value="inv_txn"  <?= $preEntityType==='inv_txn'  ?'selected':''?>>Inventory txn (qty+)</option>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="f_ent_search">Target</label>
                    <div class="entity-picker">
                        <input id="f_ent_search" type="text" placeholder="Type to search…" class="entity-picker-search" autocomplete="off">
                        <input id="f_ent_id" name="entity_id" type="hidden" value="<?= (int)$preEntityId ?>">
                        <div class="entity-picker-dropdown" hidden></div>
                        <div class="entity-picker-chosen muted small"><?= $prefilledLabel !== '' ? '✓ ' . h($prefilledLabel) : '' ?></div>
                    </div>
                </div>
                <div class="field span-2">
                    <label for="f_template">
                        Template (optional)
                        <?php if ($hasLinkedTemplates): ?>
                            <label class="inline" style="float:right; font-weight:normal;">
                                <input type="checkbox" id="f_show_all_tpl">
                                <span class="muted small">Show all templates</span>
                            </label>
                        <?php endif; ?>
                    </label>
                    <select id="f_template" name="template_id" class="no-combobox">
                        <option value="">— None (free-form) —</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"
                                    data-type="<?= h($t['inspection_type']) ?>"
                                    data-linked="<?= (int)$t['linked'] ?>">
                                <?php if ((int)$t['linked'] === 1): ?>★ <?php endif; ?><?= h($t['code']) ?> — <?= h($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_due">Due date</label>
                    <input id="f_due" type="date" name="due_date">
                </div>
                <div class="field span-4">
                    <label for="f_notes">Initial notes</label>
                    <textarea id="f_notes" name="verdict_notes" rows="2" placeholder="Optional context for the inspector"></textarea>
                </div>
            </div>

            <!-- =================================================== -->
            <!-- IR (printed report) fields                          -->
            <!-- Only finished-goods / first-article inspections     -->
            <!-- usually need these, but we show them for all types  -->
            <!-- since e.g. ad-hoc inspections of a sample lot also  -->
            <!-- benefit from the multi-sample grid + IR header.     -->
            <!-- =================================================== -->
            <fieldset style="margin-top: 16px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 6px;">
                <legend style="padding: 0 6px; font-size: 13px; font-weight: 600; color: #555;">
                    IR document &amp; sampling
                </legend>
                <div class="form-grid">
                    <div class="field span-4">
                        <label for="f_jc_search">Job card <span class="muted small">(for finished-goods IRs — PO no/line/PDN qty come from here)</span></label>
                        <div class="entity-picker" id="jc_picker">
                            <input type="hidden" name="job_card_id" id="f_job_card_id" value="">
                            <input type="search" id="f_jc_search" placeholder="Search code / PO / part…" autocomplete="off">
                            <div class="entity-picker-dropdown" id="jc_dropdown" hidden></div>
                            <div id="jc_chosen" class="muted small" style="margin-top: 4px;"></div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="f_sample_count">Sample columns *</label>
                        <input id="f_sample_count" type="number" name="sample_count"
                               min="1" max="60" value="1" required>
                        <p class="muted small" style="margin: 3px 0 0;">
                            How many sample parts the inspector will measure (S1..SN on the printed IR).
                            1 = traditional single-sample inspection. The number of result rows seeded
                            from the template will be multiplied by this.
                        </p>
                    </div>
                    <div class="field">
                        <label for="f_chkd_qty">Checked qty</label>
                        <input id="f_chkd_qty" type="number" name="chkd_qty" min="0"
                               placeholder="Defaults to sample columns">
                        <p class="muted small" style="margin: 3px 0 0;">
                            Manual — no sampling-plan column. Often equals sample columns.
                        </p>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>

    <script>
    // ---------------------------------------------------------------
    // Job card picker — same search/select pattern as the entity
    // picker above, but talks to ?action=job_card_picker and renders
    // a richer label (code · PO · L · part).
    // ---------------------------------------------------------------
    (function () {
        var search   = document.getElementById('f_jc_search');
        var hidden   = document.getElementById('f_job_card_id');
        var dropdown = document.getElementById('jc_dropdown');
        var chosen   = document.getElementById('jc_chosen');
        var timer    = null;
        if (!search) return;

        function fmtLabel(r) {
            // code + po_no + line_no + part_no, with separators only
            // between non-empty segments so we don't print "·· · ··"
            // for sparse job cards.
            var bits = [];
            if (r.code)    bits.push(r.code);
            if (r.po_no)   bits.push('PO ' + r.po_no);
            if (r.line_no !== null && r.line_no !== '') bits.push('L:' + r.line_no);
            if (r.part_no) bits.push(r.part_no);
            return bits.join(' · ');
        }

        function doSearch() {
            var q = search.value.trim();
            var url = '<?= h(url('/inspection.php?action=job_card_picker')) ?>&q=' + encodeURIComponent(q);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) return;
                    dropdown.innerHTML = '';
                    if (!j.rows.length) {
                        var none = document.createElement('div');
                        none.className = 'muted small';
                        none.style.padding = '6px';
                        none.textContent = 'No job cards match.';
                        dropdown.appendChild(none);
                    } else {
                        j.rows.forEach(function (r) {
                            var div = document.createElement('div');
                            div.className = 'entity-picker-item';
                            div.dataset.id    = r.id;
                            div.dataset.label = fmtLabel(r);
                            div.dataset.pdnQty = r.pdn_qty || '';
                            div.style.padding = '4px 6px';
                            div.style.cursor  = 'pointer';
                            div.innerHTML = '<strong>' + (r.code || '') + '</strong> '
                                          + '<span class="muted">'
                                          + (r.po_no ? 'PO ' + r.po_no + ' ' : '')
                                          + (r.line_no !== null && r.line_no !== '' ? 'L:' + r.line_no + ' ' : '')
                                          + (r.part_no || '')
                                          + '</span>';
                            dropdown.appendChild(div);
                        });
                    }
                    dropdown.hidden = false;
                });
        }
        search.addEventListener('input', function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(doSearch, 200);
        });
        search.addEventListener('focus', doSearch);
        document.addEventListener('mousedown', function (e) {
            var item = e.target.closest && e.target.closest('#jc_dropdown .entity-picker-item');
            if (item) {
                hidden.value   = item.dataset.id;
                chosen.textContent = '✓ ' + item.dataset.label;
                search.value  = '';
                dropdown.hidden = true;
                // Auto-suggest sample count = pdn_qty if operator hasn't
                // touched the field yet (still on the default 1). Doesn't
                // override an explicit entry.
                var sc = document.getElementById('f_sample_count');
                if (sc && (sc.value === '' || sc.value === '1') && item.dataset.pdnQty) {
                    sc.placeholder = 'Suggested: ' + item.dataset.pdnQty;
                }
                return;
            }
            if (!e.target.closest('#jc_picker')) dropdown.hidden = true;
        });
    })();
    </script>

    <script>
    (function () {
        // Entity picker — same pattern as running_notes.php
        var typeSel  = document.getElementById('f_ent_type');
        var search   = document.getElementById('f_ent_search');
        var hidden   = document.getElementById('f_ent_id');
        var dropdown = document.querySelector('.entity-picker-dropdown');
        var chosen   = document.querySelector('.entity-picker-chosen');
        var timer = null;

        function clearChoice() {
            hidden.value = ''; chosen.textContent = '';
            // Reset all template options to unlinked state
            if (tplSel) {
                for (var i = 0; i < tplSel.options.length; i++) {
                    var opt = tplSel.options[i];
                    if (!opt.value) continue;
                    if (opt.getAttribute('data-linked') === '1') {
                        opt.setAttribute('data-linked', '0');
                        opt.text = opt.text.replace(/^★\s*/, '');
                    }
                }
                applyTplFilter();
            }
        }
        typeSel.addEventListener('change', function () {
            clearChoice(); search.value = ''; dropdown.hidden = true;
            search.disabled = typeSel.value === 'none';
        });
        search.disabled = typeSel.value === 'none';

        function doSearch() {
            var et = typeSel.value;
            if (et === 'none') { dropdown.hidden = true; return; }
            var q = search.value.trim();
            var url = (window.MAGDYN_BASE || '') + '/inspection.php?action=entity_picker'
                    + '&entity_type=' + encodeURIComponent(et)
                    + '&q=' + encodeURIComponent(q);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    dropdown.innerHTML = '';
                    if (!d || !d.ok || !d.rows.length) {
                        dropdown.innerHTML = '<div class="entity-picker-empty muted small">No matches</div>';
                    } else {
                        d.rows.forEach(function (row) {
                            var div = document.createElement('div');
                            div.className = 'entity-picker-item';
                            div.dataset.id    = row.id;
                            div.dataset.label = row.code + ' · ' + row.label;
                            div.innerHTML = '<strong>' + row.code + '</strong> <span class="muted">' + row.label + '</span>';
                            dropdown.appendChild(div);
                        });
                    }
                    dropdown.hidden = false;
                });
        }
        search.addEventListener('input', function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(doSearch, 200);
        });
        search.addEventListener('focus', function () { if (typeSel.value !== 'none') doSearch(); });
        document.addEventListener('mousedown', function (e) {
            var item = e.target.closest && e.target.closest('.entity-picker-item');
            if (item) {
                hidden.value = item.dataset.id;
                chosen.textContent = '✓ ' + item.dataset.label;
                search.value = ''; dropdown.hidden = true;
                // Auto-select linked template when entity has one assigned
                var et = typeSel.value;
                if ((et === 'inv_item' || et === 'asset') && item.dataset.id) {
                    var tplFetchUrl = (window.MAGDYN_BASE || '') + '/inspection.php?action=template_for_entity'
                        + '&entity_type=' + encodeURIComponent(et)
                        + '&entity_id='   + encodeURIComponent(item.dataset.id);
                    fetch(tplFetchUrl, { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d || !d.ok) return;
                            var linked = d.templates || [];
                            var linkedIds = linked.map(function (t) { return String(t.id); });
                            // Update data-linked attributes and ★ prefixes on all options
                            if (tplSel) {
                                for (var i = 0; i < tplSel.options.length; i++) {
                                    var opt = tplSel.options[i];
                                    if (!opt.value) continue;
                                    var wasLinked = opt.getAttribute('data-linked') === '1';
                                    var isLinked  = linkedIds.indexOf(opt.value) !== -1;
                                    opt.setAttribute('data-linked', isLinked ? '1' : '0');
                                    if (isLinked && !wasLinked) {
                                        opt.text = '★ ' + opt.text;
                                    } else if (!isLinked && wasLinked) {
                                        opt.text = opt.text.replace(/^★\s*/, '');
                                    }
                                }
                                // Auto-select the one linked template
                                if (linkedIds.length === 1) {
                                    tplSel.value = linkedIds[0];
                                }
                                applyTplFilter();
                            }
                        });
                }
                return;
            }
            if (!e.target.closest('.entity-picker')) dropdown.hidden = true;
        });

        // -----------------------------------------------------------
        // Template picker filtering — when the page is opened from an
        // asset or item, hide templates not linked to that entity
        // unless the user ticks "Show all templates". The toggle is
        // only rendered when at least one linked template exists.
        // -----------------------------------------------------------
        var showAllCb = document.getElementById('f_show_all_tpl');
        var tplSel    = document.getElementById('f_template');
        function applyTplFilter() {
            if (!tplSel) return;
            var showAll = !showAllCb || showAllCb.checked;
            var hidUnlinked = !showAll;
            for (var i = 0; i < tplSel.options.length; i++) {
                var opt = tplSel.options[i];
                if (!opt.value) continue; // keep "None" option always
                var linked = opt.getAttribute('data-linked') === '1';
                opt.hidden = hidUnlinked && !linked;
            }
            // If the currently selected option was hidden, reset to None
            if (tplSel.selectedOptions[0] && tplSel.selectedOptions[0].hidden) {
                tplSel.value = '';
            }
        }
        if (showAllCb) {
            showAllCb.addEventListener('change', applyTplFilter);
            applyTplFilter();  // run once on load
        }
    })();
    </script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =================================================================
// VIEW — full inspection record
// =================================================================
if ($action === 'view') {
    require_permission('inspection', 'view');
    $id = (int)input('id', 0);
    $row = db_one(
        "SELECT i.*,
                pu.full_name AS planned_by_name,
                iu.full_name AS inspected_by_name,
                au.full_name AS approved_by_name,
                ru.full_name AS qc_release_by_name,
                rl.code      AS qc_release_loc_code,
                rl.name      AS qc_release_loc_name,
                t.code AS template_code, t.name AS template_name
           FROM inspections i
           LEFT JOIN users pu ON pu.id = i.planned_by
           LEFT JOIN users iu ON iu.id = i.inspected_by
           LEFT JOIN users au ON au.id = i.approved_by
           LEFT JOIN users ru ON ru.id = i.qc_release_by
           LEFT JOIN locations rl ON rl.id = i.qc_release_loc_id
           LEFT JOIN inspection_templates t ON t.id = i.template_id
          WHERE i.id = ? AND i.is_deleted = 0",
        [$id]
    );
    if (!$row) {
        flash_set('error', 'Inspection not found.');
        redirect(url('/inspection.php'));
    }
    list($entLabel, $entLink) = inspection_resolve_entity($row['entity_type'], (int)$row['entity_id']);

    // Result rows are loaded inside the grid render below via ir_results_grid().
    $atts = db_all('SELECT * FROM inspection_attachments WHERE inspection_id = ? ORDER BY id', [$id]);

    // ----- IR-format extras -----------------------------------------
    // Live read of job_card header (PO no, PO line, PDN qty) — never
    // snapshotted, so a later job_card correction flows through.
    $jcInfo = ir_job_card_header($row['job_card_id'] ?? null);
    // Drawing no/rev live from inv_items when the target is an item.
    // No snapshot (per spec) — if the drawing is re-revisioned, the
    // IR view shows the latest. If the operator wants the IR locked
    // to a specific drawing rev, they edit the inv_items row before
    // closing the inspection.
    $dwgNo = $dwgRev = null;
    if ($row['entity_type'] === 'inv_item' && (int)$row['entity_id']) {
        $dwgRow = db_one(
            'SELECT dwg_no, dwg_rev_no FROM inv_items WHERE id = ?',
            [(int)$row['entity_id']]
        );
        if ($dwgRow) {
            $dwgNo  = $dwgRow['dwg_no']     ?: null;
            $dwgRev = $dwgRow['dwg_rev_no'] ?: null;
        }
    }
    // Has-any-IR-data switch — older inspections that predate the IR
    // migration have none of these and we skip the IR header card for
    // a cleaner look.
    $hasIrData = !empty($row['ir_no'])
              || !empty($row['part_no'])
              || !empty($row['pid'])
              || !empty($row['job_card_id'])
              || (int)($row['sample_count'] ?? 1) > 1;

    $canExecute = inspection_can_execute($row);
    $canApprove = inspection_can_approve($row);
    $canDelete  = inspection_can_delete($row);

    // True when the current user is about to use the bypass_two_person
    // permission to self-approve. Shown as a quiet hint next to the
    // approve buttons so the override is never invisible to the user
    // applying it (audit-trail awareness).
    $usingTwoPersonBypass = $canApprove
        && (int)$row['inspected_by'] === (int)current_user_id()
        && permission_check('inspection', 'bypass_two_person');

    // If the approve buttons aren't shown but the inspection is in a
    // state where SOMEONE could approve it, compute a short note
    // explaining why this particular user can't. Without this the
    // missing buttons look like a UI bug ("approve button missing")
    // when in fact the gate is intentional (two-person rule, wrong
    // status, no inspector recorded yet, no permission).
    $approveGateNote = '';
    if (!$canApprove && in_array($row['status'], ['in_progress','draft','rework','hold'], true)) {
        $uid = (int)current_user_id();
        if (!permission_check('inspection', 'approve')) {
            $approveGateNote = 'You do not have permission to approve inspections. Ask a manager or admin to sign off.';
        } elseif ($row['status'] === 'draft') {
            $approveGateNote = 'Approval is locked until the inspection has been executed (status moves to "in progress" once results are recorded).';
        } elseif ($row['status'] === 'hold') {
            $approveGateNote = 'This inspection is on hold. Re-open it (Execute) before it can be approved.';
        } elseif (!$row['inspected_by']) {
            $approveGateNote = 'Approval is locked until results are recorded. Click Execute to enter measurements first.';
        } elseif ((int)$row['inspected_by'] === $uid) {
            $approveGateNote = 'You recorded the results on this inspection, so the two-person rule prevents you from also approving it. A different user with approve permission must sign off. (Admins may be granted "Bypass two-person approval rule" under Roles & Permissions to override this.)';
        }
    }

    $page_title  = 'Inspection ' . $row['code'];
    $page_module = 'inspection';
    require __DIR__ . '/includes/header.php';

    $actionsHtml = '';
    // Notes button — opens the running notes modal scoped to this
    // inspection. Always shown (view-only users can still read notes).
    require_once __DIR__ . '/includes/_notes.php';
    $actionsHtml .= notes_popup_button('inspection', $id, 'Notes', 'N') . ' ';

    if ($canExecute) {
        $actionsHtml .= '<a class="btn btn-primary btn-sm" href="' . h(url('/inspection.php?action=execute&id=' . $id)) . '"'
                     .  ' data-shortcut="E" accesskey="e">' . shortcut_label('Execute', 'E') . '</a> ';
    }
    if ($canApprove) {
        $actionsHtml .= '<form method="post" style="display:inline" action="' . h(url('/inspection.php?action=save')) . '"'
                     .  ' onsubmit="return confirm(\'Approve and mark this inspection passed?\');">'
                     .  csrf_field()
                     .  '<input type="hidden" name="op" value="approve">'
                     .  '<input type="hidden" name="id" value="' . $id . '">'
                     .  '<input type="hidden" name="verdict" value="passed">'
                     .  '<button class="btn btn-success btn-sm" type="submit">✓ Approve · Pass</button></form> ';
        $actionsHtml .= '<form method="post" style="display:inline" action="' . h(url('/inspection.php?action=save')) . '"'
                     .  ' onsubmit="return confirm(\'Approve and mark this inspection FAILED?\');">'
                     .  csrf_field()
                     .  '<input type="hidden" name="op" value="approve">'
                     .  '<input type="hidden" name="id" value="' . $id . '">'
                     .  '<input type="hidden" name="verdict" value="failed">'
                     .  '<button class="btn btn-danger btn-sm" type="submit">✗ Fail</button></form> ';
        // Rework: opens a small inline chooser modal so the approver
        // can pick the destination (O-Rework = send back to vendor;
        // I-Rework = fix in-house). The two real forms submit
        // verdict=rework + rework_dst; the visible button is just a
        // toggle. CSS lives at the bottom of the page (see
        // inspection-rework-chooser style block).
        $actionsHtml .= '<button type="button" class="btn btn-ghost btn-sm"'
                     .  ' onclick="document.getElementById(\'rework-chooser-' . $id . '\').style.display=\'flex\';">'
                     .  '↻ Rework</button> ';
    }
    if ($canDelete) {
        $actionsHtml .= '<form method="post" style="display:inline" action="' . h(url('/inspection.php?action=save')) . '"'
                     .  ' onsubmit="return confirm(\'Delete this inspection record? Cannot be undone.\');">'
                     .  csrf_field()
                     .  '<input type="hidden" name="op" value="delete">'
                     .  '<input type="hidden" name="id" value="' . $id . '">'
                     .  '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑</button></form>';
    }
    $actionsHtml .= ' <a class="btn btn-icon" title="Print IR" aria-label="Print IR" target="_blank"'
                 .  ' href="' . h(url('/inspection.php?action=print&id=' . $id)) . '">'
                 .  '🖨 <span class="dt-action-label">Print IR</span></a>';
    $actionsHtml .= ' <a class="btn btn-icon" title="Download PDF" aria-label="Download PDF" target="_blank"'
                 .  ' href="' . h(url('/inspection.php?action=download_pdf&id=' . $id)) . '">'
                 .  '⬇ <span class="dt-action-label">PDF</span></a>';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'        => 'Inspection ' . h($row['code']),
            'subtitle'     => inspection_type_label($row['inspection_type']) . ' · ' . dt_display($row['created_at']),
            'back_href'    => url('/inspection.php'),
            'back_label'   => 'Inspections',
            'actions_html' => $actionsHtml,
        ]) ?>

        <div class="form-page-body">
            <?php if ($approveGateNote !== ''): ?>
                <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:10px 14px;margin-bottom:14px;">
                    <strong style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#92400e;margin-bottom:4px;">Approval gated</strong>
                    <div style="color:#78350f;font-size:13px;"><?= h($approveGateNote) ?></div>
                </div>
            <?php elseif ($usingTwoPersonBypass): ?>
                <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:10px 14px;margin-bottom:14px;">
                    <strong style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#1e40af;margin-bottom:4px;">Two-person rule overridden</strong>
                    <div style="color:#1e3a8a;font-size:13px;">You recorded the results on this inspection. Approving now will use the <code>inspection.bypass_two_person</code> permission to self-sign-off. The audit log will record both actions against your user.</div>
                </div>
            <?php endif; ?>

            <?php if ($hasIrData): ?>
                <!-- ============================================== -->
                <!-- IR document header — the printed-report fields  -->
                <!-- (snapshot part identity + live job_card / inv   -->
                <!-- _items lookups). Rendered as a flat grid that    -->
                <!-- mirrors the cells across the top of the printed  -->
                <!-- IR. Skipped entirely for legacy inspections that -->
                <!-- predate the IR migration.                        -->
                <!-- ============================================== -->
                <div style="background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:12px 16px; margin-bottom:14px;">
                    <div style="display:flex; align-items:baseline; gap:12px; margin-bottom:10px;">
                        <strong style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#475569;">Inspection report</strong>
                        <?php if (!empty($row['ir_no'])): ?>
                            <code style="font-size:14px; font-weight:600;"><?= h($row['ir_no']) ?></code>
                        <?php endif; ?>
                        <span class="muted small">(internal id <code><?= h($row['code']) ?></code>)</span>
                    </div>
                    <div class="form-grid">
                        <div class="field"><label>Inspection date</label>
                            <div><?= h($row['inspected_at'] ? substr((string)$row['inspected_at'], 0, 10) : '—') ?></div>
                        </div>
                        <div class="field"><label>Inspected by</label>
                            <div><?= h($row['inspected_by_name'] ?: '—') ?></div>
                        </div>
                        <div class="field"><label>Part no.</label>
                            <div>
                                <strong><?= h($row['part_no'] ?: '—') ?></strong>
                                <?php if (!empty($row['part_rev'])): ?>
                                    <span class="muted small">Rev <?= h($row['part_rev']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="field"><label>PID</label>
                            <div><?= h($row['pid'] ?: '—') ?></div>
                        </div>
                        <div class="field"><label>Drawing no.</label>
                            <div>
                                <?= h($dwgNo ?: '—') ?>
                                <?php if ($dwgRev): ?>
                                    <span class="muted small">Rev <?= h($dwgRev) ?></span>
                                <?php endif; ?>
                                <?php if ($dwgNo && $row['entity_type'] === 'inv_item'): ?>
                                    <span class="muted small" title="Live read from inv_items — not snapshotted">(live)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="field"><label>Customer PO</label>
                            <div>
                                <?php if ($jcInfo): ?>
                                    <strong><?= h($jcInfo['po_no'] ?: '—') ?></strong>
                                    <?php if ($jcInfo['line_no'] !== null && $jcInfo['line_no'] !== ''): ?>
                                        <span class="muted small">L:<?= h($jcInfo['line_no']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                        // Accepted qty = PDN qty per confirmed business
                        // rule. If the inspection later moves to
                        // status=failed/rework/hold/cancelled we surface
                        // that via the status pill above; we don't
                        // mutate the printed Accepted figure.
                        $pdnQty      = $jcInfo['pdn_qty'] ?? null;
                        $acceptedQty = $pdnQty;
                        ?>
                        <div class="field"><label>PDN qty</label>
                            <div><strong><?= $pdnQty !== null ? (int)$pdnQty : '—' ?></strong></div>
                        </div>
                        <div class="field"><label>Chkd qty</label>
                            <div><strong><?= $row['chkd_qty'] !== null ? (int)$row['chkd_qty'] : '—' ?></strong></div>
                        </div>
                        <div class="field"><label>Accepted qty</label>
                            <div><strong><?= $acceptedQty !== null ? (int)$acceptedQty : '—' ?></strong></div>
                        </div>
                    </div>
                    <?php if (!empty($row['part_description'])): ?>
                        <div style="margin-top:8px;"><span class="muted small">Description:</span>
                            <?= h($row['part_description']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field"><label>Status</label><div><?= inspection_status_pill($row['status']) ?></div></div>
                <div class="field"><label>Type</label><div><?= h(inspection_type_label($row['inspection_type'])) ?></div></div>
                <div class="field"><label>Target</label><div>
                    <?php if ($row['entity_type'] === 'none' || !$row['entity_id']): ?>
                        <span class="muted">Standalone</span>
                    <?php else: ?>
                        <a href="<?= h($entLink) ?>"><?= h($entLabel) ?></a>
                        <span class="muted small">(<?= h($row['entity_type']) ?>)</span>
                    <?php endif; ?>
                </div></div>
                <div class="field"><label>Due</label><div><?= h($row['due_date'] ?: '—') ?></div></div>

                <div class="field"><label>Planned by</label><div>
                    <?= h($row['planned_by_name'] ?: '—') ?>
                    <span class="muted small"><?= h(dt_display($row['planned_at'])) ?></span>
                </div></div>
                <div class="field"><label>Inspected by</label><div>
                    <?php if ($row['inspected_by_name']): ?>
                        <?= h($row['inspected_by_name']) ?>
                        <span class="muted small"><?= h(dt_display($row['inspected_at'])) ?></span>
                    <?php else: ?>
                        <span class="muted">— pending —</span>
                    <?php endif; ?>
                </div></div>
                <div class="field"><label>Approved by</label><div>
                    <?php if ($row['approved_by_name']): ?>
                        <?= h($row['approved_by_name']) ?>
                        <span class="muted small"><?= h(dt_display($row['approved_at'])) ?></span>
                    <?php else: ?>
                        <span class="muted">— pending —</span>
                    <?php endif; ?>
                </div></div>
                <div class="field"><label>QC release</label><div>
                    <?php if ((int)$row['qc_release_done'] === 1): ?>
                        Moved to <code><?= h($row['qc_release_loc_code'] ?: '?') ?></code>
                        <span class="muted small"><?= h($row['qc_release_loc_name'] ?: '') ?></span>
                        <br><span class="muted small">
                            <?= h($row['qc_release_by_name'] ?: '—') ?>
                            · <?= h(dt_display($row['qc_release_at'])) ?>
                        </span>
                    <?php elseif (($row['entity_type'] ?? '') === 'inv_txn'): ?>
                        <span class="muted">— stock still at LOC-QCH —</span>
                    <?php else: ?>
                        <span class="muted">n/a</span>
                    <?php endif; ?>
                </div></div>
                <div class="field"><label>Template</label><div>
                    <?php if ($row['template_id']): ?>
                        <a href="<?= h(url('/inspection.php?action=template_edit&id=' . (int)$row['template_id'])) ?>">
                            <code><?= h($row['template_code']) ?></code> — <?= h($row['template_name']) ?>
                        </a>
                    <?php else: ?>
                        <span class="muted">Free-form</span>
                    <?php endif; ?>
                </div></div>

                <?php if ($row['verdict_notes']): ?>
                <div class="field span-4"><label>Inspector verdict</label>
                    <div class="ins-verdict-notes"><?= nl2br(h($row['verdict_notes'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <h3 style="margin-top: 24px;">Results</h3>
            <?php
            // Multi-sample grid (read-only). Same layout as the
            // execute UI: rows = parameters, cols = samples. Cells
            // are coloured by pass_fail (computed at execute time).
            $sampleCountView = max(1, (int)($row['sample_count'] ?? 1));
            $bundleView      = ir_results_grid($id);
            $paramRowsView   = $bundleView['params'];
            $gridView        = $bundleView['grid'];
            $remarksMapView  = ir_remarks_decode($row['sample_remarks_json'] ?? null);

            // Resolve instrument labels for the Notes (instrument) column.
            $instrIdsView = array_filter(array_unique(array_map(
                function ($p) { return (int)($p['instrument_asset_id'] ?? 0); }, $paramRowsView
            )));
            $instrLabelView = [];
            if ($instrIdsView) {
                $placeholders = implode(',', array_fill(0, count($instrIdsView), '?'));
                foreach (db_all(
                    "SELECT a.id, a.asset_tag, m.name
                       FROM assets a LEFT JOIN asset_models m ON m.id = a.model_id
                      WHERE a.id IN ($placeholders)",
                    array_values($instrIdsView)
                ) as $a) {
                    $instrLabelView[(int)$a['id']] = $a['asset_tag']
                        . ($a['name'] ? ' — ' . $a['name'] : '');
                }
            }
            ?>
            <?php if (!$paramRowsView): ?>
                <p class="muted empty" style="padding: 14px 0;">No result rows yet. <?= $canExecute ? 'Click <strong>Execute</strong> to record measurements.' : '' ?></p>
            <?php else: ?>
                <style>
                    .ir-view-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 4px; background: #fff; }
                    .ir-view { width: 100%; border-collapse: collapse; font-size: 12px; }
                    .ir-view th, .ir-view td { border: 1px solid var(--border); padding: 4px 6px; text-align: center; vertical-align: middle; }
                    .ir-view thead th { background: #f3f4f6; font-weight: 600; position: sticky; top: 0; z-index: 1; }
                    .ir-view .col-bbl     { width: 36px;  font-variant-numeric: tabular-nums; }
                    .ir-view .col-param   { width: 200px; text-align: left; }
                    .ir-view .col-nom     { width: 70px;  font-variant-numeric: tabular-nums; }
                    .ir-view .col-tol     { width: 60px;  font-variant-numeric: tabular-nums; }
                    .ir-view .col-minmax  { width: 80px;  font-variant-numeric: tabular-nums; }
                    .ir-view .col-uom     { width: 44px; }
                    .ir-view .col-instr   { width: 140px; text-align: left; }
                    .ir-view .col-sample  { width: 72px;  font-variant-numeric: tabular-nums; }
                    .ir-view tr.is-note td { background: #f9fafb; }
                    .ir-view tr.is-note td.note-span { text-align: left; font-style: italic; }
                    /* Pass → black text, no background; Fail → red text only, no background */
                    .ir-view td.cell-pass    { color: #000; }
                    .ir-view td.cell-fail    { color: #dc2626; font-weight: 600; }
                    .ir-view td.cell-pending { color: #854d0e; }
                    .ir-view tfoot td { background: #f9fafb; font-weight: 600; }
                    .ir-view tfoot td.tf-label { text-align: right; }
                </style>

                <div class="ir-view-wrap">
                    <table class="ir-view">
                        <thead>
                            <tr>
                                <th class="col-bbl" title="Balloon number">Bbl</th>
                                <th class="col-param">Parameter</th>
                                <th class="col-nom">Nominal</th>
                                <th class="col-tol">Tol −/+</th>
                                <th class="col-minmax">Min / Max</th>
                                <th class="col-uom">UOM</th>
                                <th class="col-instr">Notes (instrument)</th>
                                <?php for ($s = 1; $s <= $sampleCountView; $s++): ?>
                                    <th class="col-sample">S<?= $s ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $sampleFailed   = array_fill(1, $sampleCountView, false);
                        $sampleHasValue = array_fill(1, $sampleCountView, false);
                        foreach ($paramRowsView as $p):
                            $tid    = (int)$p['template_item_id'];
                            $ctv    = (string)($p['check_type'] ?? '');
                            $isNote = ($ctv === 'text' || $ctv === 'notes');
                            $bub    = $p['bubble_no']  ?? '';
                            $gdt    = $p['gdt_symbol'] ?? '';
                            list($minV, $maxV) = ir_min_max_for_type(
                                $ctv, $p['target_value'], $p['tolerance_lower'], $p['tolerance_upper']
                            );
                            $unitDisp = inspection_uom_display($p['unit'] ?? '');
                            $instrId  = (int)($p['instrument_asset_id'] ?? 0);
                            $instr    = $instrId && isset($instrLabelView[$instrId])
                                      ? $instrLabelView[$instrId]
                                      : '';
                            // For note rows, surface the first sample's
                            // measured_value as the note body (execute UI
                            // mirrors it to every sample's row).
                            $noteText = $isNote
                                ? (string)($gridView[$tid][1]['measured_value'] ?? '')
                                : '';
                        ?>
                            <tr class="<?= $isNote ? 'is-note' : '' ?>">
                                <td class="col-bbl"><?= h($bub) ?></td>
                                <td class="col-param">
                                    <?php if ($gdt !== ''): ?><span class="gdt-symbol" title="GD&amp;T"><?= h($gdt) ?></span> <?php endif; ?>
                                    <strong><?= h($p['label']) ?></strong>
                                </td>
                                <?php if ($isNote): ?>
                                    <td colspan="<?= 4 + $sampleCountView ?>" class="note-span">
                                        <?= $noteText !== '' ? h($noteText) : '<span class="muted small">(no note text)</span>' ?>
                                    </td>
                                    <td class="col-instr"><?= h($instr) ?></td>
                                <?php else:
                                    $isMinMaxV = ($ctv === 'min-max' || $ctv === 'logical-min-max');
                                    $noSpecV   = ($ctv === 'logic' || $ctv === 'boolean' || $ctv === 'visual');
                                ?>
                                    <?php if ($noSpecV): ?>
                                        <td class="col-nom muted">—</td>
                                        <td class="col-tol muted">—</td>
                                        <td class="col-minmax muted">—</td>
                                    <?php elseif ($isMinMaxV): ?>
                                        <td class="col-nom muted">—</td>
                                        <td class="col-tol muted">—</td>
                                        <td class="col-minmax">
                                            <?php if ($minV !== null): ?><?= h(ir_fmt_num($minV)) ?><?php endif; ?>
                                            <?php if ($maxV !== null): ?><br><?= h(ir_fmt_num($maxV)) ?><?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td class="col-nom"><?= h(ir_fmt_num($p['target_value'])) ?></td>
                                        <td class="col-tol">
                                            <?php if ($p['tolerance_lower'] !== null && $p['tolerance_lower'] !== ''): ?>−<?= h(ir_fmt_num($p['tolerance_lower'])) ?><?php endif; ?>
                                            <?php if ($p['tolerance_upper'] !== null && $p['tolerance_upper'] !== ''): ?><br>+<?= h(ir_fmt_num($p['tolerance_upper'])) ?><?php endif; ?>
                                        </td>
                                        <td class="col-minmax">
                                            <?php if ($minV !== null): ?><?= h(ir_fmt_num($minV)) ?><?php endif; ?>
                                            <?php if ($maxV !== null): ?><br><?= h(ir_fmt_num($maxV)) ?><?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-uom"><?= h($unitDisp) ?></td>
                                    <td class="col-instr"><?= h($instr) ?></td>
                                    <?php for ($s = 1; $s <= $sampleCountView; $s++):
                                        $cell = $gridView[$tid][$s] ?? null;
                                        $val  = $cell ? (string)($cell['measured_value'] ?? '') : '';
                                        $pf   = $cell ? (string)($cell['pass_fail'] ?? '') : '';
                                        $cls = '';
                                        if ($val !== '') {
                                            $sampleHasValue[$s] = true;
                                            if (is_numeric($val) && ($minV !== null || $maxV !== null)) {
                                                $numVal  = (float)$val;
                                                $inRange = true;
                                                if ($minV !== null && $numVal < (float)$minV) $inRange = false;
                                                if ($maxV !== null && $numVal > (float)$maxV) $inRange = false;
                                                $cls = $inRange ? 'cell-pass' : 'cell-fail';
                                            } elseif ($pf === 'pass') {
                                                $cls = 'cell-pass';
                                            } elseif ($pf === 'fail') {
                                                $cls = 'cell-fail';
                                            } else {
                                                $cls = 'cell-pending';
                                            }
                                            if ($cls === 'cell-fail') $sampleFailed[$s] = true;
                                        }
                                        // For LOGIC type, show pass/fail text as the display value
                                        $dispVal = ($ctv === 'logic' && $val !== '')
                                            ? ucfirst($val) : $val;
                                    ?>
                                        <td class="col-sample <?= h($cls) ?>"><?= h($dispVal) ?></td>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if ($remarksMapView || $sampleCountView > 0): ?>
                            <tfoot>
                                <tr>
                                    <td colspan="6" class="tf-label">Remarks (per sample)</td>
                                    <td class="col-instr"></td>
                                    <?php for ($s = 1; $s <= $sampleCountView; $s++):
                                        $footerLabel = ($sampleHasValue[$s] && !$sampleFailed[$s]) ? 'Accepted' : '';
                                    ?>
                                        <td class="col-sample"><?= h($footerLabel) ?></td>
                                    <?php endfor; ?>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>

            <h3 style="margin-top: 24px;">Attachments</h3>
            <?php if (!$atts): ?>
                <p class="muted empty" style="padding: 14px 0;">No attachments.</p>
            <?php else: ?>
                <div class="note-attachments">
                    <?php foreach ($atts as $a): ?>
                        <a class="note-attachment" href="<?= h(url('/inspection_attach.php?id=' . (int)$a['id'])) ?>"
                           title="<?= h($a['filename']) ?>">
                            📎 <?= h($a['filename']) ?>
                            <span class="muted small">(<?= number_format((int)$a['size_bytes'] / 1024, 1) ?> KB)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    // Mount the notes popup modal (for the Notes button in the toolbar)
    // and the attachment-preview modal (for clicks on .note-attachment
    // — clicks routed to /cad_viewer.php for CAD files or shown inline
    // in an iframe for previewable types). notes_popup_assets calls
    // notes_attachment_preview_assets internally, so one suffices.
    require_once __DIR__ . '/includes/_notes.php';
    notes_popup_assets();
    ?>
    <?php if ($canApprove): ?>
    <!-- Rework destination chooser. Shown by the Rework button click;
         user picks O-Rework (vendor return) or I-Rework (in-house fix).
         Each option submits its own form with verdict=rework + rework_dst. -->
    <div id="rework-chooser-<?= (int)$id ?>" class="rework-chooser-overlay"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1000;align-items:center;justify-content:center;"
         onclick="if(event.target===this){this.style.display='none';}">
        <div style="display:flex;background:#fff;border-radius:8px;padding:24px;max-width:520px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,0.25);flex-direction:column;gap:14px;">
            <div>
                <h3 style="margin:0 0 4px;font-size:18px;">Send for rework — where to?</h3>
                <p class="muted" style="margin:0;font-size:13px;">
                    Pick the rework destination. The stock will be moved out of LOC-QCH to the chosen location, and the inspection will be marked
                    <strong>rework</strong>.
                </p>
            </div>
            <form method="post" action="<?= h(url('/inspection.php?action=save')) ?>"
                  onsubmit="return confirm('Send to O-Rework (back to the vendor)?');">
                <?= csrf_field() ?>
                <input type="hidden" name="op"      value="approve">
                <input type="hidden" name="id"      value="<?= (int)$id ?>">
                <input type="hidden" name="verdict" value="rework">
                <input type="hidden" name="rework_dst" value="O-Rework">
                <button type="submit" class="btn btn-primary btn-block" style="width:100%;text-align:left;padding:12px 16px;">
                    <strong>O-Rework</strong> — send back to the vendor
                    <div class="muted small" style="margin-top:2px;">For ship_in / receive lots that the vendor will rework or replace.</div>
                </button>
            </form>
            <form method="post" action="<?= h(url('/inspection.php?action=save')) ?>"
                  onsubmit="return confirm('Send to I-Rework (fix in-house)?');">
                <?= csrf_field() ?>
                <input type="hidden" name="op"      value="approve">
                <input type="hidden" name="id"      value="<?= (int)$id ?>">
                <input type="hidden" name="verdict" value="rework">
                <input type="hidden" name="rework_dst" value="I-Rework">
                <button type="submit" class="btn btn-primary btn-block" style="width:100%;text-align:left;padding:12px 16px;">
                    <strong>I-Rework</strong> — fix in-house
                    <div class="muted small" style="margin-top:2px;">Internal rework. Once finished, Process Inventory will auto-tick the Rework checkbox when this lot is reprocessed.</div>
                </button>
            </form>
            <div style="text-align:right;">
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="document.getElementById('rework-chooser-<?= (int)$id ?>').style.display='none';">
                    Cancel
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// =================================================================
// PRINT — browser-printable IR (opens in new tab)
// =================================================================
if ($action === 'print') {
    require_permission('inspection', 'view');
    $id = (int)input('id', 0);
    require_once __DIR__ . '/includes/_inspection_ir_print.php';
    $html = ir_render_print_html($id, ['include_actions_bar' => true]);
    if (!$html) {
        flash_set('error', 'Inspection not found.');
        redirect(url('/inspection.php'));
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// =================================================================
// DOWNLOAD_PDF — stream IR as PDF attachment
// =================================================================
if ($action === 'download_pdf') {
    require_permission('inspection', 'view');
    $id = (int)input('id', 0);
    require_once __DIR__ . '/includes/_inspection_ir_pdf.php';
    $att = ir_render_pdf($id);
    if (!$att) {
        flash_set('error', 'Inspection not found or could not generate PDF.');
        redirect(url('/inspection.php'));
    }
    header('Content-Type: ' . $att['mime']);
    header('Content-Disposition: attachment; filename="' . addslashes($att['name']) . '"');
    header('Content-Length: ' . filesize($att['path']));
    readfile($att['path']);
    @unlink($att['path']);
    @rmdir(dirname($att['path']));
    exit;
}

// =================================================================
// EXECUTE — inspector's input form
// =================================================================
if ($action === 'execute') {
    require_permission('inspection', 'execute');
    $id = (int)input('id', 0);
    $row = db_one('SELECT * FROM inspections WHERE id = ? AND is_deleted = 0', [$id]);
    if (!$row) {
        flash_set('error', 'Inspection not found.');
        redirect(url('/inspection.php'));
    }
    if (!inspection_can_execute($row)) {
        flash_set('error', 'This inspection cannot be executed in its current state.');
        redirect(url('/inspection.php?action=view&id=' . $id));
    }

    // Seed checklist items from the linked template when they are missing.
    // Case 1: inspection has no template yet but the entity has one linked
    //         (common for auto-created QC inspections).
    // Case 2: inspection has a template but results were never seeded
    //         (legacy auto-create path that predates the seeding fix).
    $sampleCountForSeed = max(1, (int)($row['sample_count'] ?? 1));
    if (empty($row['template_id'])) {
        $seedEntityId   = null;
        $seedEntityType = null;
        if ($row['entity_type'] === 'inv_item') {
            $seedEntityId   = (int)$row['entity_id'];
            $seedEntityType = 'inv_item';
        } elseif ($row['entity_type'] === 'inv_txn' && $row['entity_id']) {
            $txnForSeed = db_one('SELECT item_id FROM inv_txns WHERE id = ?', [(int)$row['entity_id']]);
            if ($txnForSeed && $txnForSeed['item_id']) {
                $seedEntityId   = (int)$txnForSeed['item_id'];
                $seedEntityType = 'inv_item';
            }
        } elseif ($row['entity_type'] === 'asset') {
            $seedEntityId   = (int)$row['entity_id'];
            $seedEntityType = 'asset';
        }
        if ($seedEntityId && $seedEntityType) {
            $linkedTplRow = db_one(
                "SELECT t.id FROM inspection_template_targets tt
                   JOIN inspection_templates t ON t.id = tt.template_id AND t.is_active = 1
                  WHERE tt.entity_type = ? AND tt.entity_id = ?
                  ORDER BY t.id LIMIT 1",
                [$seedEntityType, $seedEntityId]
            );
            if ($linkedTplRow) {
                $autoTplId = (int)$linkedTplRow['id'];
                db_exec('UPDATE inspections SET template_id = ? WHERE id = ?', [$autoTplId, $id]);
                $row['template_id'] = $autoTplId;
                ir_seed_results_with_samples($id, $autoTplId, $sampleCountForSeed);
            }
        }
    } elseif (!(int)db_val('SELECT COUNT(*) FROM inspection_results WHERE inspection_id = ?', [$id], 0)) {
        ir_seed_results_with_samples($id, (int)$row['template_id'], $sampleCountForSeed);
    }

    // ---- Data for the "start inspection" modal ----------------------
    $started = (string)input('started', '') === '1';
    // GET params passed back from execute_start so the form can relay them.
    $execInspDate  = trim((string)input('insp_date', ''));
    if (!$execInspDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $execInspDate)) {
        $execInspDate = date('Y-m-d');
    }
    $execInspectorId = (int)input('inspector', 0) ?: (int)current_user_id();
    // All active users for the inspector picker
    $execUsers = db_all(
        'SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name'
    );
    // Max samples = txn qty (NULL when not txn-linked)
    $execMaxSamples = null;
    if ($row['entity_type'] === 'inv_txn' && $row['entity_id']) {
        $execTxnRow = db_one('SELECT qty_delta FROM inv_txns WHERE id = ?', [(int)$row['entity_id']]);
        if ($execTxnRow && (float)$execTxnRow['qty_delta'] > 0) {
            $execMaxSamples = (int)ceil((float)$execTxnRow['qty_delta']);
        }
    }
    $execCurrentSamples = max(1, (int)($row['sample_count'] ?? 1));

    $page_title  = 'Execute ' . $row['code'];
    $page_module = 'inspection';
    $focus_id    = '';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'        => 'Execute ' . h($row['code']),
            'subtitle'     => 'Record measurements and verdicts for each check',
            'back_href'    => url('/inspection.php?action=view&id=' . $id),
            'back_label'   => 'Back to record',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Submit results', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inspection.php?action=view&id=' . $id)) . '">Cancel</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/inspection.php?action=save')) ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="execute">
            <input type="hidden" name="id" value="<?= $id ?>">
            <?php if ($started): ?>
            <input type="hidden" name="inspection_date" value="<?= h($execInspDate) ?>">
            <input type="hidden" name="inspector_id"    value="<?= (int)$execInspectorId ?>">
            <?php endif; ?>

            <?php
            // ---------------------------------------------------------
            // Multi-sample grid: rows = parameters, cols = samples.
            // Note rows (check_type='text') render as a full-width
            // free-text input spanning all sample columns. Boolean/
            // visual rows reuse the same text input mechanism — the
            // inspector types "OK"/"NG" (handled by ir_evaluate).
            // ---------------------------------------------------------
            $sampleCount   = max(1, (int)($row['sample_count'] ?? 1));
            $bundle        = ir_results_grid($id);
            $paramRows     = $bundle['params'];
            $grid          = $bundle['grid'];
            $remarksMap    = ir_remarks_decode($row['sample_remarks_json'] ?? null);

            // Pre-resolve any instrument labels referenced from results.
            $instrIds = array_filter(array_unique(array_map(
                function ($p) { return (int)($p['instrument_asset_id'] ?? 0); }, $paramRows
            )));
            $instrLabel = [];
            if ($instrIds) {
                $placeholders = implode(',', array_fill(0, count($instrIds), '?'));
                foreach (db_all(
                    "SELECT a.id, a.asset_tag, m.name
                       FROM assets a LEFT JOIN asset_models m ON m.id = a.model_id
                      WHERE a.id IN ($placeholders)",
                    array_values($instrIds)
                ) as $a) {
                    $instrLabel[(int)$a['id']] = $a['asset_tag']
                        . ($a['name'] ? ' — ' . $a['name'] : '');
                }
            }
            ?>

            <?php if (!$paramRows): ?>
                <p class="muted">This inspection has no checklist items. Add a verdict note below and submit.</p>
            <?php else: ?>
                <style>
                    /* Scope all rules to this page only. */
                    .ir-exec-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 4px; background: #fff; }
                    .ir-exec { width: 100%; border-collapse: collapse; font-size: 12px; }
                    .ir-exec th, .ir-exec td { border: 1px solid var(--border); padding: 3px 4px; text-align: center; vertical-align: middle; }
                    .ir-exec thead th { background: #f3f4f6; font-weight: 600; position: sticky; top: 0; z-index: 1; }
                    .ir-exec .col-bbl    { width: 36px;  font-variant-numeric: tabular-nums; }
                    .ir-exec .col-param  { width: 200px; text-align: left; }
                    .ir-exec .col-nom    { width: 70px;  font-variant-numeric: tabular-nums; }
                    .ir-exec .col-tol    { width: 60px;  font-variant-numeric: tabular-nums; }
                    .ir-exec .col-minmax { width: 80px;  font-variant-numeric: tabular-nums; }
                    .ir-exec .col-uom    { width: 44px; }
                    .ir-exec .col-instr  { width: 140px; text-align: left; }
                    .ir-exec .col-sample { width: 72px; }
                    .ir-exec input       { width: 100%; border: none; background: transparent; font: inherit; padding: 2px 4px; text-align: center; font-variant-numeric: tabular-nums; }
                    .ir-exec input:focus { outline: 1px solid var(--primary); background: #fff; }
                    .ir-exec input.note-input { text-align: left; }
                    .ir-exec tr.is-note td { background: #f9fafb; }
                    .ir-exec tr.is-note td.note-span { text-align: left; font-style: italic; }
                    .ir-exec tfoot td { background: #f9fafb; }
                    .ir-exec tfoot td.tf-label { text-align: right; font-weight: 600; }
                    .ir-exec select.logic-sel { width: 100%; border: none; background: transparent; font: inherit; text-align: center; }
                    /* live-pf-badge is hidden — kept only for CSS :has() targeting */
                    .ir-exec .live-pf-badge { display: none; }
                    /* Pass → value text in black (default); Fail → value text in red, no background */
                    .ir-exec td.col-sample:has(.live-pf-badge.lp-pass) input { color: #000; }
                    .ir-exec td.col-sample:has(.live-pf-badge.lp-fail)  input { color: #dc2626; font-weight: 600; }
                </style>

                <div class="ir-exec-wrap">
                    <table class="ir-exec">
                        <thead>
                            <tr>
                                <th class="col-bbl" title="Balloon number">Bbl</th>
                                <th class="col-param">Parameter</th>
                                <th class="col-nom">Nominal</th>
                                <th class="col-tol">Tol −/+</th>
                                <th class="col-minmax">Min / Max</th>
                                <th class="col-uom">UOM</th>
                                <th class="col-instr">Notes (instrument)</th>
                                <?php for ($s = 1; $s <= $sampleCount; $s++): ?>
                                    <th class="col-sample">S<?= $s ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($paramRows as $p):
                            $tid  = (int)$p['template_item_id'];
                            $ct   = (string)($p['check_type'] ?? '');
                            $isNote = ($ct === 'text' || $ct === 'notes');
                            $isLogic = ($ct === 'logic');
                            $bub  = $p['bubble_no'] ?? '';
                            $gdt  = $p['gdt_symbol'] ?? '';
                            list($minV, $maxV) = ir_min_max_for_type(
                                $ct, $p['target_value'], $p['tolerance_lower'], $p['tolerance_upper']
                            );
                            $unitDisp = inspection_uom_display($p['unit'] ?? '');
                            $instrId = (int)($p['instrument_asset_id'] ?? 0);
                            $instr   = $instrId && isset($instrLabel[$instrId])
                                     ? $instrLabel[$instrId]
                                     : '';
                        ?>
                            <tr class="<?= $isNote ? 'is-note' : '' ?>">
                                <td class="col-bbl"><?= h($bub) ?></td>
                                <td class="col-param">
                                    <?php if ($gdt !== ''): ?><span class="gdt-symbol" title="GD&amp;T"><?= h($gdt) ?></span> <?php endif; ?>
                                    <strong><?= h($p['label']) ?></strong>
                                </td>
                                <?php if ($isNote): ?>
                                    <?php
                                    // Note rows: collapse all the spec columns and span
                                    // the sample columns as ONE big text input. We post
                                    // the same text into every sample's result row so
                                    // each (param, sample) cell stays a single source
                                    // of truth (used by the view layer for printing).
                                    // The first sample's input is the editable one;
                                    // the rest are mirror hidden fields wired via JS.
                                    $colspan = 4 + $sampleCount;  // Nom + Tol + Min/Max + UOM + samples
                                    $firstResultId = isset($grid[$tid][1]) ? (int)$grid[$tid][1]['id'] : 0;
                                    $noteText = $firstResultId ? (string)($grid[$tid][1]['measured_value'] ?? '') : '';
                                    ?>
                                    <td colspan="<?= $colspan ?>" class="note-span">
                                        <?php if ($firstResultId): ?>
                                            <input type="text" class="note-input"
                                                   name="result_value[<?= $firstResultId ?>]"
                                                   value="<?= h($noteText) ?>"
                                                   placeholder="Note text (applies to all samples)"
                                                   data-note-tid="<?= $tid ?>">
                                            <?php for ($s = 2; $s <= $sampleCount; $s++):
                                                $rid = isset($grid[$tid][$s]) ? (int)$grid[$tid][$s]['id'] : 0;
                                                if ($rid): ?>
                                                <input type="hidden" name="result_value[<?= $rid ?>]"
                                                       data-note-mirror="<?= $tid ?>"
                                                       value="<?= h($noteText) ?>">
                                            <?php endif; endfor; ?>
                                        <?php else: ?>
                                            <span class="muted small">(no row seeded)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-instr"><?= h($instr) ?></td>
                                <?php else:
                                    $isMinMax = ($ct === 'min-max' || $ct === 'logical-min-max');
                                    $noSpec   = ($ct === 'logic' || $ct === 'boolean' || $ct === 'visual');
                                    $autoLive = ($ct === 'logical-nom' || $ct === 'logical-min-max' || $ct === 'numeric');
                                ?>
                                    <?php if ($noSpec): ?>
                                        <td class="col-nom muted">—</td>
                                        <td class="col-tol muted">—</td>
                                        <td class="col-minmax muted">—</td>
                                    <?php elseif ($isMinMax): ?>
                                        <td class="col-nom muted">—</td>
                                        <td class="col-tol muted">—</td>
                                        <td class="col-minmax">
                                            <?php if ($minV !== null): ?><?= h(ir_fmt_num($minV)) ?><?php endif; ?>
                                            <?php if ($maxV !== null): ?><br><?= h(ir_fmt_num($maxV)) ?><?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td class="col-nom"><?= h(ir_fmt_num($p['target_value'])) ?></td>
                                        <td class="col-tol">
                                            <?php if ($p['tolerance_lower'] !== null && $p['tolerance_lower'] !== ''): ?>−<?= h(ir_fmt_num($p['tolerance_lower'])) ?><?php endif; ?>
                                            <?php if ($p['tolerance_upper'] !== null && $p['tolerance_upper'] !== ''): ?><br>+<?= h(ir_fmt_num($p['tolerance_upper'])) ?><?php endif; ?>
                                        </td>
                                        <td class="col-minmax">
                                            <?php if ($minV !== null): ?><?= h(ir_fmt_num($minV)) ?><?php endif; ?>
                                            <?php if ($maxV !== null): ?><br><?= h(ir_fmt_num($maxV)) ?><?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="col-uom"><?= h($unitDisp) ?></td>
                                    <td class="col-instr"><?= h($instr) ?></td>
                                    <?php for ($s = 1; $s <= $sampleCount; $s++):
                                        $cell = $grid[$tid][$s] ?? null;
                                        $rid  = $cell ? (int)$cell['id'] : 0;
                                        $val  = $cell ? (string)($cell['measured_value'] ?? '') : '';
                                    ?>
                                        <td class="col-sample">
                                            <?php if ($rid): ?>
                                                <?php if ($isLogic): ?>
                                                    <select name="result_value[<?= $rid ?>]" class="no-combobox logic-sel">
                                                        <option value="">—</option>
                                                        <option value="pass" <?= $val==='pass'?'selected':'' ?>>Pass</option>
                                                        <option value="fail" <?= $val==='fail'?'selected':'' ?>>Fail</option>
                                                    </select>
                                                <?php else: ?>
                                                    <input type="text"
                                                           name="result_value[<?= $rid ?>]"
                                                           value="<?= h($val) ?>"
                                                           <?php if ($autoLive): ?>
                                                           data-live-pf="1"
                                                           data-rmin="<?= h($minV !== null ? $minV : '') ?>"
                                                           data-rmax="<?= h($maxV !== null ? $maxV : '') ?>"
                                                           <?php endif; ?>>
                                                    <?php if ($autoLive): ?>
                                                        <span class="live-pf-badge"></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="tf-label">Remarks (per sample)</td>
                                <td class="col-instr"></td>
                                <?php for ($s = 1; $s <= $sampleCount; $s++): ?>
                                    <td class="col-sample">
                                        <input type="text" name="sample_remarks[<?= $s ?>]" maxlength="60"
                                               value="<?= h($remarksMap[$s] ?? 'Accepted') ?>"
                                               placeholder="Accepted">
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <script>
                    // Keep mirrored hidden inputs in sync for note rows
                    document.querySelectorAll('.ir-exec input.note-input').forEach(function (inp) {
                        var tid = inp.dataset.noteTid;
                        inp.addEventListener('input', function () {
                            document.querySelectorAll(
                                '.ir-exec input[data-note-mirror="' + tid + '"]'
                            ).forEach(function (m) { m.value = inp.value; });
                        });
                    });

                    // Live pass/fail badges for LOGICAL types
                    function evalLivePF(inp) {
                        var badge = inp.nextElementSibling;
                        if (!badge || !badge.classList.contains('live-pf-badge')) return;
                        var v = inp.value.trim();
                        if (v === '') { badge.className = 'live-pf-badge'; return; }
                        var mn = parseFloat(inp.dataset.rmin), mx = parseFloat(inp.dataset.rmax);
                        var hasMn = inp.dataset.rmin !== '', haMx = inp.dataset.rmax !== '';
                        var pf = 'pass';
                        if (!isNaN(parseFloat(v))) {
                            var fv = parseFloat(v);
                            if (hasMn && !isNaN(mn) && fv < mn) pf = 'fail';
                            else if (haMx && !isNaN(mx) && fv > mx) pf = 'fail';
                        } else {
                            var tl = v.toLowerCase();
                            if (tl === 'pass' || tl === 'ok') pf = 'pass';
                            else if (tl === 'fail' || tl.indexOf('not ok') === 0 || tl.indexOf('ng') !== -1) pf = 'fail';
                            else { badge.className = 'live-pf-badge'; return; }
                        }
                        // Only update class — no text label shown (text colour handled by CSS :has())
                        badge.className = 'live-pf-badge lp-' + pf;
                    }
                    document.querySelectorAll('.ir-exec input[data-live-pf]').forEach(function (inp) {
                        inp.addEventListener('input', function () { evalLivePF(inp); });
                        evalLivePF(inp); // run once on load for pre-filled values
                    });
                </script>
            <?php endif; ?>

            <div class="form-grid" style="margin-top: 16px;">
                <div class="field span-4">
                    <label for="f_verdict">Verdict notes</label>
                    <textarea id="f_verdict" name="verdict_notes" rows="3" placeholder="Summary observations, deviations, anything an approver should see"><?= h($row['verdict_notes']) ?></textarea>
                </div>
                <div class="field span-4" data-drop-zone="insp-attach">
                    <label>Attachments <span class="muted small">(or drag files onto this area)</span></label>
                    <input type="file" name="attachments[]" multiple>
                    <span class="muted small">Photos, measurement printouts, cert PDFs. Max 10 MB each.</span>
                </div>
            </div>
        </form>
    </div>

    <!-- ============================================================
         Start-inspection modal — shown immediately on page load
         unless ?started=1 (i.e. execute_start already ran).
         Captures: inspection date, inspector, sample count.
    ============================================================ -->
    <div id="exec-start-modal" class="att-preview-modal" <?= $started ? 'hidden' : '' ?>>
        <div class="att-preview-backdrop"></div>
        <div class="att-preview-dialog" role="dialog" aria-label="Start inspection"
             style="max-width: 480px; margin: auto; height: auto;">
            <div class="att-preview-head">
                <span class="att-preview-name">Start Inspection — <?= h($row['code']) ?></span>
            </div>
            <form method="post" action="<?= h(url('/inspection.php?action=save')) ?>"
                  style="padding: 20px;">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="execute_start">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="esm_date">Date of inspection *</label>
                        <input id="esm_date" name="inspection_date" type="date" required
                               value="<?= h(date('Y-m-d')) ?>">
                    </div>
                    <div class="field span-2">
                        <label for="esm_inspector">Inspected by *</label>
                        <select id="esm_inspector" name="inspector_id" required class="no-combobox">
                            <option value="">— Select inspector —</option>
                            <?php foreach ($execUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"
                                        <?= (int)$u['id'] === (int)current_user_id() ? 'selected' : '' ?>>
                                    <?= h($u['full_name'] ?: $u['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field span-4">
                        <label for="esm_samples">
                            Samples taken *
                            <?php if ($execMaxSamples !== null): ?>
                                <span class="muted small">(max <?= (int)$execMaxSamples ?> — txn qty)</span>
                            <?php endif; ?>
                        </label>
                        <input id="esm_samples" name="samples_taken" type="number" required
                               min="1"
                               <?= $execMaxSamples !== null ? 'max="' . (int)$execMaxSamples . '"' : '' ?>
                               value="<?= (int)$execCurrentSamples ?>">
                    </div>
                </div>
                <div style="margin-top: 20px; display:flex; gap:8px; justify-content:flex-end;">
                    <a class="btn btn-ghost"
                       href="<?= h(url('/inspection.php?action=view&id=' . $id)) ?>">Cancel</a>
                    <button type="submit" class="btn btn-primary">Begin Inspection</button>
                </div>
            </form>
        </div>
    </div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// =================================================================
// LIST (default)
// =================================================================
require_permission('inspection', 'view');
$canCreate = permission_check('inspection', 'create');

// Status filter: "pending" is a virtual group covering draft + in_progress.
$pendingMode = (string)input('pending', '');

$extraWhere = [['i.is_deleted = 0', []]];
if ($pendingMode === '1') {
    $extraWhere[] = ["i.status IN ('draft','in_progress','rework','hold')", []];
}

$dtCfg = [
    'id'       => 'inspections',
    'base_sql' =>
        "SELECT i.id, i.code, i.ir_no, i.inspection_type, i.entity_type, i.entity_id,
                i.status, i.due_date, i.planned_at, i.inspected_at, i.approved_at,
                t.name AS template_name,
                -- Template linked to the entity itself via inspection_template_targets
                -- (shown in the list when the inspection has no explicitly assigned template)
                (SELECT t2.name
                   FROM inspection_template_targets tt2
                   JOIN inspection_templates t2 ON t2.id = tt2.template_id AND t2.is_active = 1
                  WHERE (i.entity_type = 'inv_item' AND tt2.entity_type = 'inv_item' AND tt2.entity_id = i.entity_id)
                     OR (i.entity_type = 'inv_txn'  AND tt2.entity_type = 'inv_item' AND tt2.entity_id = et.item_id)
                     OR (i.entity_type = 'asset'    AND tt2.entity_type = 'asset'    AND tt2.entity_id = i.entity_id)
                  ORDER BY t2.id LIMIT 1) AS entity_linked_tpl_name,
                -- The linked txn (only set when entity_type='inv_txn')
                et.id         AS txn_id,
                et.item_id    AS txn_item_id,
                et.qty_delta  AS txn_qty,
                et.created_at AS txn_at,
                et.txn_type   AS txn_type,
                -- Receipt number for ship_in txns. NULL for other types.
                er.receipt_no AS receipt_no,
                -- Vendor name (only set on ship_in via receipt → shipment → vendor)
                v.name        AS vendor_name,
                -- Customer PO (live read from job_cards via i.job_card_id;
                -- populated for finished-goods IRs, NULL for legacy /
                -- incoming inspections).
                jc.po_no    AS jc_po_no,
                jc.line_no  AS jc_po_line,
                -- Part name / code with cascading fallback so the column
                -- populates for every kind of inspection:
                --   1) Linked txn's item (legacy incoming flow)
                --   2) Direct inv_items target (IR for finished goods)
                --   3) Snapshot fields on the inspection itself
                --      (one-off IRs with no inv_items row)
                COALESCE(NULLIF(et_i.short_description, ''),
                         et_i.name,
                         ii.long_description,
                         i.part_description) AS part_name,
                COALESCE(et_i.code, ii.code, i.part_no, i.pid) AS part_code,
                -- 'From' source label, used for sort + filter (display
                -- adds the receipt_no prefix in the row renderer).
                CASE
                    WHEN et.txn_type = 'ship_in'
                        THEN COALESCE(v.name, 'Vendor (unknown)')
                    WHEN et.txn_type = 'process' THEN 'Internal (process)'
                    WHEN et.txn_type = 'receive' THEN 'Manual receive'
                    WHEN et.txn_type IS NULL     THEN NULL
                    ELSE et.txn_type
                END AS from_label
           FROM inspections i
           LEFT JOIN inspection_templates t ON t.id = i.template_id
           LEFT JOIN inv_txns     et   ON i.entity_type = 'inv_txn' AND et.id   = i.entity_id
           LEFT JOIN inv_items    et_i ON et_i.id = et.item_id
           LEFT JOIN inv_items    ii   ON i.entity_type = 'inv_item' AND ii.id = i.entity_id
           LEFT JOIN job_cards    jc   ON jc.id = i.job_card_id
           LEFT JOIN inv_receipts er   ON er.txn_id = et.id
           LEFT JOIN inv_shipments es  ON es.id = er.shipment_id
           LEFT JOIN vendors      v    ON v.id = es.vendor_id",
    'extra_where' => $extraWhere,
    'columns' => [
        ['key'=>'ir_no',          'label'=>'IR #',          'sortable'=>true, 'searchable'=>true,
            'sql_col'=>'i.ir_no', 'td_class'=>'nowrap'],
        ['key'=>'inspection_type','label'=>'Type',          'sortable'=>true, 'sql_col'=>'i.inspection_type',
            'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
                ['value'=>'incoming','label'=>'Incoming'],
                ['value'=>'asset_cal','label'=>'Asset cal'],
                ['value'=>'finished_goods','label'=>'Finished'],
                ['value'=>'first_article','label'=>'First article'],
                ['value'=>'adhoc','label'=>'Ad-hoc'],
            ]]],
        ['key'=>'txn_id',         'label'=>'Txn ID',        'sortable'=>true, 'searchable'=>true,
            'sql_col'=>'et.id', 'td_class'=>'nowrap mono'],
        ['key'=>'from_label',     'label'=>'From',          'sortable'=>true, 'searchable'=>true,
            'sql_col'=>"CASE
                WHEN et.txn_type = 'ship_in'
                    THEN CONCAT(COALESCE(er.receipt_no, ''),
                                CASE WHEN er.receipt_no IS NOT NULL AND v.name IS NOT NULL THEN ' · ' ELSE '' END,
                                COALESCE(v.name, ''))
                WHEN et.txn_type = 'process' THEN 'Internal (process)'
                WHEN et.txn_type = 'receive' THEN 'Manual receive'
                ELSE et.txn_type
            END"],
        ['key'=>'part_name',      'label'=>'Part / PO',     'sortable'=>true, 'searchable'=>true,
            // Searchable on inv code, part name AND customer PO no — so
            // the inspector can find a record by typing the customer's
            // PO number alone.
            'sql_col'=>"CONCAT('(', COALESCE(et_i.code, ii.code, i.part_no, i.pid, ''), ')-',
                               COALESCE(NULLIF(et_i.short_description, ''),
                                        et_i.name,
                                        ii.long_description,
                                        i.part_description, ''),
                               ' ', COALESCE(jc.po_no, ''))"],
        ['key'=>'txn_at',         'label'=>'Date',          'sortable'=>true, 'sql_col'=>'et.created_at',
            'td_class'=>'nowrap'],
        ['key'=>'txn_qty',        'label'=>'Qty',           'sortable'=>true, 'sql_col'=>'et.qty_delta',
            'th_class'=>'r', 'td_class'=>'r'],
        ['key'=>'template_name',  'label'=>'Template',      'sortable'=>true, 'searchable'=>true, 'sql_col'=>'t.name'],
        ['key'=>'status',         'label'=>'Status',        'sortable'=>true, 'sql_col'=>'i.status',
            'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
                ['value'=>'draft','label'=>'Draft'],
                ['value'=>'in_progress','label'=>'In progress'],
                ['value'=>'passed','label'=>'Passed'],
                ['value'=>'failed','label'=>'Failed'],
                ['value'=>'rework','label'=>'Rework'],
                ['value'=>'hold','label'=>'On hold'],
                ['value'=>'cancelled','label'=>'Cancelled'],
            ]]],
        ['key'=>'_actions',       'label'=>'',              'sortable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['code', 'desc'],
];

$rowRenderer = function ($r) use ($canCreate) {
    // Txn id cell — clickable when present, otherwise a muted dash.
    if (!empty($r['txn_id'])) {
        $txnCell = '<a href="' . h(url('/inventory.php?action=ledger&id=' . (int)$r['txn_item_id'])) . '">#' . (int)$r['txn_id'] . '</a>';
    } else {
        $txnCell = '<span class="muted small">—</span>';
    }

    // Part / PO cell — "(CODE)-Name" on top line, customer PO no
    // (live from job_cards via i.job_card_id) on a small subtitle so
    // the inspector can find a record by either part or PO.
    if (!empty($r['part_name']) || !empty($r['part_code'])) {
        $partCell = '<strong>('
                  . h($r['part_code'] ?: '')
                  . ')-'
                  . h($r['part_name'] ?: '')
                  . '</strong>';
    } else {
        $partCell = '<span class="muted small">—</span>';
    }
    if (!empty($r['jc_po_no'])) {
        $partCell .= '<br><span class="muted small">PO ' . h($r['jc_po_no']);
        if ($r['jc_po_line'] !== null && $r['jc_po_line'] !== '') {
            $partCell .= ' · L:' . h($r['jc_po_line']);
        }
        $partCell .= '</span>';
    }

    // IR # cell — clickable link to the inspection view. Falls back
    // to the internal code (INSP-…) for legacy inspections that have
    // no ir_no yet.
    $idHref = h(url('/inspection.php?action=view&id=' . (int)$r['id']));
    if (!empty($r['ir_no'])) {
        $irCell = '<a href="' . $idHref . '"><strong>' . h($r['ir_no']) . '</strong></a>'
                . '<br><span class="muted small">' . h($r['code']) . '</span>';
    } else {
        $irCell = '<a href="' . $idHref . '"><span class="muted small">' . h($r['code']) . '</span></a>';
    }

    // Qty cell — signed, green for positive. Trim trailing zeros.
    if ($r['txn_qty'] !== null && $r['txn_qty'] !== '') {
        $q  = (float)$r['txn_qty'];
        $qs = rtrim(rtrim(number_format($q, 3), '0'), '.');
        $qtyCell = '<span class="' . ($q >= 0 ? 'text-success' : 'text-danger') . '">'
                 . ($q >= 0 ? '+' : '') . $qs . '</span>';
    } else {
        $qtyCell = '<span class="muted small">—</span>';
    }

    // Date cell — uses the txn's created_at if linked, otherwise the
    // inspection's own planned_at as a fallback so standalone /
    // asset_cal rows still show a meaningful date.
    $dateRaw = $r['txn_at'] ?: $r['planned_at'];
    $dateCell = $dateRaw ? h(dt_display($dateRaw)) : '<span class="muted small">—</span>';

    $actions  = '<a class="btn btn-icon" href="' . h(url('/inspection.php?action=view&id=' . (int)$r['id'])) . '"'
              . ' title="View" aria-label="View">👁 <span class="dt-action-label">View</span></a> ';
    if (in_array($r['status'], ['draft','in_progress','rework','hold'], true) && permission_check('inspection', 'execute')) {
        $actions .= '<a class="btn btn-icon" href="' . h(url('/inspection.php?action=execute&id=' . (int)$r['id'])) . '"'
                  . ' title="Execute" aria-label="Execute">▶ <span class="dt-action-label">Execute</span></a> ';
    }
    if (permission_check('inspection', 'manage')) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/inspection.php?action=save')) . '"'
                  . ' onsubmit="return confirm(\'Delete inspection ' . h($r['code']) . '?\');">'
                  . csrf_field()
                  . '<input type="hidden" name="op" value="delete">'
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
    }

    // From cell — for ship_in, show "<receipt_no> · <vendor>" (either
    // piece may be missing). For other txn types, fall back to
    // from_label as computed by the SQL CASE.
    if ($r['txn_type'] === 'ship_in') {
        $bits = [];
        if (!empty($r['receipt_no'])) {
            $bits[] = '<strong>' . h($r['receipt_no']) . '</strong>';
        }
        if (!empty($r['vendor_name'])) {
            $bits[] = h($r['vendor_name']);
        }
        $fromCell = $bits ? implode(' <span class="muted small">·</span> ', $bits)
                          : '<span class="muted small">Vendor (unknown)</span>';
    } else {
        $fromCell = h($r['from_label'] ?: '—');
    }

    return [
        'ir_no'             => $irCell,
        'inspection_type'   => '<span class="pill pill-neutral">' . h(inspection_type_label($r['inspection_type'])) . '</span>',
        'txn_id'            => $txnCell,
        'from_label'        => $fromCell,
        'part_name'         => $partCell,
        'txn_at'            => $dateCell,
        'txn_qty'           => $qtyCell,
        'template_name'     => $r['template_name']
            ? h($r['template_name'])
            : ($r['entity_linked_tpl_name']
                ? '<span title="Template linked to this item">★ ' . h($r['entity_linked_tpl_name']) . '</span>'
                : '<span class="muted small">free-form</span>'),
        'status'            => inspection_status_pill($r['status']),
        '_actions'          => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);

// One unified "Inspection list" page now — the previous All/Pending
// toggle pill was removed when the two sidebar entries were merged.
// The Status column's select filter handles the same need (and more
// granularly). The ?pending=1 query param is still honored as a
// deep-link convenience.

$rightActions =
    '<a class="btn btn-ghost btn-sm" href="' . h(url('/inspection.php?action=templates')) . '">Templates</a>'
  . ($canCreate
        ? ' <button type="button" class="btn btn-ghost btn-sm"'
          . ' data-open-import="inspection-import-modal"'
          . ' title="Import inspection records from CSV">⤒ Import CSV</button>'
          . ' <a class="btn btn-primary btn-sm" href="' . h(url('/inspection.php?action=new')) . '"'
          . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New inspection', 'N') . '</a>'
        : '');

$dtCfg['title']        = 'Inspection list';
$dtCfg['actions_html'] = $rightActions;

$page_title  = 'Inspection list';
$page_module = 'inspection';
require __DIR__ . '/includes/header.php';
data_table_render($dtCfg, $dt, $rowRenderer);
if ($canCreate) {
    require_once __DIR__ . '/includes/_import.php';
    import_modal_html(
        'inspection-import-modal',
        'Import inspection records from CSV',
        url('/inspection.php?action=inspection_import_preview'),
        'Creates inspection record headers (results entered later via the execute UI). '
          . 'All columns optional: <code>code</code> (auto-generated if blank), '
          . '<code>inspection_type</code> (incoming / asset_cal / finished_goods / first_article / adhoc; '
          . 'default adhoc), '
          . '<code>entity_type</code> (asset / inv_item / none; default none), '
          . '<code>entity_code</code> (required if entity_type set — asset_tag for asset, '
          . 'item code for inv_item), '
          . '<code>template_code</code> (matches template code or name; if set and status=draft, '
          . 'template items seed the results just like the create form does), '
          . '<code>status</code> (default draft), '
          . '<code>due_date</code> (YYYY-MM-DD), '
          . '<code>verdict_notes</code>.'
    );
}
require __DIR__ . '/includes/footer.php';
