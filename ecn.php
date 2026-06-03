<?php
/**
 * MagDyn — ECN dispatcher (Phase B)
 *
 * Inventory-focused Engineering Change Notice module. Handles:
 *
 *   ?action=list           (default) — datatable of ECNs
 *   ?action=new            — render form to create a new ECN
 *   ?action=save  POST     — insert / update an ECN
 *   ?action=view&id=N      — view an ECN
 *   ?action=edit&id=N      — render form to edit a draft ECN
 *   ?action=submit&id=N  POST — submit draft → submitted
 *   ?action=signoff&id=N POST — record a slot's decision
 *   ?action=make_effective&id=N POST — approved → effective (creates DMS rev)
 *   ?action=close&id=N   POST — effective → closed
 *   ?action=cancel&id=N  POST — cancel from any pre-effective status
 *   ?action=delete&id=N  POST — hard delete (draft only, ecn.delete)
 *   ?action=add_item&id=N    POST — add affected inventory item
 *   ?action=remove_item&id=N POST — remove affected inventory item
 *
 * Permissions:
 *   ecn.view     — see list and individual ECNs
 *   ecn.create   — originate new ECNs
 *   ecn.signoff  — sign off on a slot (further gated by slot/role mapping)
 *   ecn.manage   — admin override (can act on any slot, edit anything)
 *   ecn.delete   — hard-delete draft ECNs
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_ecn.php';
require_once __DIR__ . '/includes/_dms.php';
require_once __DIR__ . '/includes/datatable.php';

require_permission('ecn', 'view');

$action = (string)input('action', 'list');
$id     = (int)input('id', 0);
$uid    = current_user_id();

// =============================================================
// POST: save (create / edit)
// =============================================================
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');

    $ecnId    = (int)input('id', 0);
    $ecnType  = (string)input('ecn_type', '');
    $title    = trim((string)input('title', ''));
    $reason   = trim((string)input('business_reason', '')) ?: null;
    $descr    = trim((string)input('description', '')) ?: null;

    $effMode  = (string)input('effectivity_mode', 'date');
    if (!in_array($effMode, ['date','lot','manual'], true)) $effMode = 'date';
    $effDate  = (string)input('effective_date', '') ?: null;

    $dispUse  = input('disp_use_as_is', '') === '' ? null : (float)input('disp_use_as_is', 0);
    $dispRew  = input('disp_rework',    '') === '' ? null : (float)input('disp_rework',    0);
    $dispScr  = input('disp_scrap',     '') === '' ? null : (float)input('disp_scrap',     0);
    $dispSort = input('disp_sort',      '') === '' ? null : (float)input('disp_sort',      0);
    $dispNotes= trim((string)input('disp_notes', '')) ?: null;
    $alsoRev  = !empty($_POST['also_revise_drawings']) ? 1 : 0;

    if (!isset(ecn_types()[$ecnType])) {
        flash_set('error', 'Pick an ECN type.');
        header('Location: ' . url('/ecn.php?action=' . ($ecnId ? 'edit&id=' . $ecnId : 'new')));
        exit;
    }
    if ($title === '') {
        flash_set('error', 'Title is required.');
        header('Location: ' . url('/ecn.php?action=' . ($ecnId ? 'edit&id=' . $ecnId : 'new')));
        exit;
    }

    // Type-specific payload — pulled from form by ecn_type
    $details = [];
    switch ($ecnType) {
        case 'item_change':
            $details['item_id']         = (int)input('item_id', 0) ?: null;
            $details['new_part_rev_no'] = trim((string)input('new_part_rev_no', '')) ?: null;
            $details['change_summary']  = trim((string)input('change_summary', '')) ?: null;
            break;
        case 'drawing_rev':
            $details['document_id']    = (int)input('document_id', 0) ?: null;
            $details['new_rev_label']  = trim((string)input('new_rev_label', '')) ?: null;
            $details['change_summary'] = trim((string)input('change_summary', '')) ?: null;
            break;
        case 'bom_change':
            $details['parent_item_id'] = (int)input('parent_item_id', 0) ?: null;
            // The simplest possible "edits" field — a free-text description
            // of the BOM edits. A full editor can come later.
            $details['edits']          = trim((string)input('bom_edits_summary', ''));
            if ($details['edits']) {
                $details['edits'] = [['summary' => $details['edits']]];
            } else {
                $details['edits'] = [];
            }
            break;
        case 'material_sub':
            $details['from_item_id']   = (int)input('from_item_id', 0) ?: null;
            $details['to_item_id']     = (int)input('to_item_id', 0)   ?: null;
            break;
        case 'uom_change':
            $details['item_id']           = (int)input('item_id', 0) ?: null;
            $details['from_uom']          = trim((string)input('from_uom', '')) ?: null;
            $details['to_uom']            = trim((string)input('to_uom', '')) ?: null;
            $details['conversion_factor'] = (float)input('conversion_factor', 0);
            break;
        case 'vendor_change':
            $details['item_id']        = (int)input('item_id', 0) ?: null;
            $details['from_vendor_id'] = (int)input('from_vendor_id', 0) ?: null;
            $details['to_vendor_id']   = (int)input('to_vendor_id', 0)   ?: null;
            break;
        case 'obsolescence':
            $details['item_id']              = (int)input('item_id', 0) ?: null;
            $details['supersede_to_item_id'] = (int)input('supersede_to_item_id', 0) ?: null;
            break;
    }
    $detailsJson = ecn_encode_details($details);

    // A drawing-revision ECN must carry the new revision label — it's
    // never auto-generated. Enforce server-side (the field is also marked
    // required in the UI) so a blank rev can't be saved and then silently
    // skip the item rev bump at Effective.
    if ($ecnType === 'drawing_rev' && empty($details['new_rev_label'])) {
        flash_set('error', 'New revision label is required for a drawing-revision ECN. Enter the new revision number.');
        header('Location: ' . url('/ecn.php?action=' . ($ecnId ? 'edit&id=' . $ecnId : 'new')
            . '&ecn_type=drawing_rev'));
        exit;
    }

    // An item-change ECN must carry: the subject item, the new part rev,
    // and (on create) a new part report file. The part_rev_no is never
    // auto-generated.
    if ($ecnType === 'item_change') {
        $icItem    = (int)($details['item_id'] ?? 0);
        $icPartRev = (string)($details['new_part_rev_no'] ?? '');
        $icErr = null;
        if (!$icItem)             $icErr = 'Pick the inventory item this change applies to.';
        elseif ($icPartRev === '') $icErr = 'New part revision is required — it is not auto-generated.';
        elseif (!$ecnId && (!isset($_FILES['pending_file']) || $_FILES['pending_file']['error'] !== UPLOAD_ERR_OK))
            $icErr = 'A new part report file is required for an item-change ECN.';
        if ($icErr) {
            flash_set('error', $icErr);
            header('Location: ' . url('/ecn.php?action=' . ($ecnId ? 'edit&id=' . $ecnId : 'new')
                . '&ecn_type=item_change' . ($icItem ? '&item_id=' . $icItem : '')));
            exit;
        }
    }

    // For drawing_rev: pull pending_doc_id / pending_rev_label out of
    // details to denormalize onto the ECN row (used for queries + JOINs)
    $pendingDocId = ($ecnType === 'drawing_rev') ? ($details['document_id'] ?: null) : null;
    $pendingRevLabel = ($ecnType === 'drawing_rev') ? ($details['new_rev_label'] ?: null) : null;
    // For item_change: the new part rev is the pending rev label; the
    // pending doc (the item's Part Report) is resolved at create time.
    if ($ecnType === 'item_change') {
        $pendingRevLabel = $details['new_part_rev_no'] ?: null;
    }

    if ($ecnId) {
        // UPDATE — only drafts editable (unless admin)
        $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [$ecnId]);
        if (!$ecn) { flash_set('error', 'ECN not found.'); header('Location: ' . url('/ecn.php')); exit; }
        if ($ecn['status'] !== 'draft' && !permission_check('ecn', 'manage')) {
            flash_set('error', 'Only draft ECNs can be edited.');
            header('Location: ' . url('/ecn.php?action=view&id=' . $ecnId));
            exit;
        }
        db_exec(
            "UPDATE ecns SET title = ?, ecn_type = ?, business_reason = ?, description = ?,
                             type_details = ?, pending_doc_id = ?, pending_rev_label = ?,
                             effectivity_mode = ?, effective_date = ?,
                             disp_use_as_is = ?, disp_rework = ?, disp_scrap = ?, disp_sort = ?, disp_notes = ?,
                             also_revise_drawings = ?
                 WHERE id = ?",
            [$title, $ecnType, $reason, $descr, $detailsJson,
             $pendingDocId, $pendingRevLabel,
             $effMode, $effDate,
             $dispUse, $dispRew, $dispScr, $dispSort, $dispNotes,
             $alsoRev,
             $ecnId]
        );
        ecn_history_append($ecnId, 'edited', null, null, null, 'Metadata updated', $uid);
        flash_set('success', 'ECN updated.');
        header('Location: ' . url('/ecn.php?action=view&id=' . $ecnId));
        exit;
    }

    // CREATE
    $no = ecn_next_no();
    $pendingItemId = ($ecnType === 'item_change') ? (int)($details['item_id'] ?? 0) : null;
    if (!$pendingItemId) $pendingItemId = null;
    db_exec(
        "INSERT INTO ecns (ecn_no, title, ecn_type, status, originator_id,
                           business_reason, description, type_details,
                           pending_doc_id, pending_item_id, pending_rev_label,
                           effectivity_mode, effective_date,
                           disp_use_as_is, disp_rework, disp_scrap, disp_sort, disp_notes,
                           also_revise_drawings)
         VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$no, $title, $ecnType, (int)$uid,
         $reason, $descr, $detailsJson,
         $pendingDocId, $pendingItemId, $pendingRevLabel,
         $effMode, $effDate,
         $dispUse, $dispRew, $dispScr, $dispSort, $dispNotes,
         $alsoRev]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()');
    ecn_history_append($newId, 'created', null, 'draft', null, "ECN $no created", $uid);

    // material_sub ECNs require BOM sweep before Effective
    if ($ecnType === 'material_sub') {
        ecn_mark_bom_sweep_required($newId);
    }

    // Handle the pending file for drawing_rev and item_change (both stage
    // an uploaded file that becomes a doc revision at Effective).
    if (($ecnType === 'drawing_rev' || $ecnType === 'item_change')
        && isset($_FILES['pending_file'])
        && $_FILES['pending_file']['error'] === UPLOAD_ERR_OK) {
        try {
            $meta = ecn_store_pending_file($_FILES['pending_file'], $newId);
            db_exec(
                "UPDATE ecns SET pending_file_name = ?, pending_file_path = ?,
                                 pending_file_size = ?, pending_file_mime = ?, pending_file_hash = ?
                 WHERE id = ?",
                [$meta['name'], $meta['path'], $meta['size'], $meta['mime'], $meta['hash'], $newId]
            );
        } catch (Exception $e) {
            flash_set('warning', 'ECN created but file upload failed: ' . $e->getMessage());
        }
    }

    // ---- item_change wiring ----
    // Resolve (or auto-create) the item's Part Report document, point the
    // ECN's pending_doc at it (so the uploaded file becomes its next
    // revision at Effective), link the item as affected, and auto-draft
    // drawing_rev ECNs for any linked docs the operator flagged.
    if ($ecnType === 'item_change') {
        $icItem = (int)($details['item_id'] ?? 0);
        if ($icItem > 0) {
            try {
                $prDocId = ecn_ensure_item_part_report($icItem, (int)$uid);
                if ($prDocId) {
                    db_exec("UPDATE ecns SET pending_doc_id = ? WHERE id = ?", [(int)$prDocId, $newId]);
                } else {
                    flash_set('warning', 'Could not resolve a Part Report document (category missing?). The part_rev_no will still update at Effective, but no part report revision will be created.');
                }
            } catch (Exception $e) {
                flash_set('warning', 'ECN created but part report setup failed: ' . $e->getMessage());
            }
            // Link the subject item as an affected item so the effect applies.
            ecn_add_affected_item($newId, $icItem, 'Subject of item-change ECN');

            // Auto-draft drawing_rev ECNs for each linked doc flagged for change.
            $changeDocs = input('change_linked_doc_ids', []);
            if (is_array($changeDocs) && $changeDocs && permission_check('ecn', 'create')) {
                $made = 0;
                foreach ($changeDocs as $cdId) {
                    $cdId = (int)$cdId;
                    if ($cdId <= 0) continue;
                    $cdoc = db_one("SELECT id, code FROM documents WHERE id = ? AND deleted_at IS NULL", [$cdId]);
                    if (!$cdoc) continue;
                    try {
                        // Draft a placeholder drawing_rev ECN (no rev label yet —
                        // engineer completes it). We can't bump a rev without a
                        // label, so create it as a draft for them to finish.
                        $sub = ecn_next_no();
                        $subDetails = ecn_encode_details([
                            'document_id'    => $cdId,
                            'new_rev_label'  => null,
                            'change_summary' => 'Raised from item-change ECN ' . $no,
                        ]);
                        db_exec(
                            "INSERT INTO ecns (ecn_no, title, ecn_type, status, originator_id,
                                               type_details, pending_doc_id, effectivity_mode, effective_date)
                             VALUES (?, ?, 'drawing_rev', 'draft', ?, ?, ?, 'date', NULL)",
                            [$sub, 'Drawing revision: ' . $cdoc['code'] . ' (from ' . $no . ')',
                             (int)$uid, $subDetails, $cdId]
                        );
                        $subId = (int)db_val('SELECT LAST_INSERT_ID()');
                        ecn_history_append($subId, 'created', null, 'draft', $cdId,
                            'Auto-drafted from item-change ECN ' . $no, $uid);
                        $made++;
                    } catch (Exception $e) {
                        // best-effort; continue
                    }
                }
                if ($made > 0) {
                    flash_set('success', $made . ' linked-document revision ECN' . ($made === 1 ? '' : 's') . ' drafted.');
                }
            }
        }
    }

    // Link any affected inventory items chosen on the new-ECN form.
    $affIds = input('affected_item_ids', []);
    if (is_array($affIds)) {
        $seen = [];
        foreach ($affIds as $aid) {
            $aid = (int)$aid;
            if ($aid <= 0 || isset($seen[$aid])) continue;
            $seen[$aid] = true;
            ecn_add_affected_item($newId, $aid, 'Linked at ECN creation');
        }
    }

    flash_set('success', "ECN $no created.");
    header('Location: ' . url('/ecn.php?action=view&id=' . $newId));
    exit;
}

// =============================================================
// POST: submit
// =============================================================
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        ecn_submit($id, $uid);
        flash_set('success', 'ECN submitted for review.');
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: signoff
// =============================================================
if ($action === 'signoff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'signoff');
    $slot = (string)input('slot_code', '');
    $decision = (string)input('decision', '');
    $comment = trim((string)input('comment', '')) ?: null;
    try {
        $res = ecn_record_signoff($id, $slot, $decision, $uid, $comment);
        flash_set('success', ucfirst($decision) . ' recorded for ' . $slot . '. ECN is now ' . $res['status'] . '.');
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: make_effective
// =============================================================
if ($action === 'make_effective' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'manage');
    $effDate = (string)input('effective_date', '') ?: null;
    try {
        $res = ecn_make_effective($id, $uid, $effDate);
        $msg = 'ECN is now Effective.';
        if (!empty($res['rev_id'])) {
            $msg .= ' Document revision #' . $res['rev_id'] . ' was created.';
        }
        $nEffects = count($res['effects'] ?? []);
        if ($nEffects > 0) {
            $msg .= ' ' . $nEffects . ' inventory side-effect' . ($nEffects === 1 ? '' : 's') . ' applied.';
        }
        flash_set('success', $msg);
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: close / cancel
// =============================================================
if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'manage');
    try { ecn_close($id, $uid); flash_set('success', 'ECN closed.'); }
    catch (Exception $e) { flash_set('error', $e->getMessage()); }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ecn = db_one("SELECT originator_id FROM ecns WHERE id = ?", [$id]);
    if (!$ecn) { flash_set('error', 'ECN not found.'); header('Location: ' . url('/ecn.php')); exit; }
    if ((int)$ecn['originator_id'] !== (int)$uid && !permission_check('ecn', 'manage')) {
        flash_set('error', 'Only the originator or an admin can cancel an ECN.');
        header('Location: ' . url('/ecn.php?action=view&id=' . $id)); exit;
    }
    $reason = trim((string)input('reason', '')) ?: null;
    try { ecn_cancel($id, $uid, $reason); flash_set('success', 'ECN cancelled.'); }
    catch (Exception $e) { flash_set('error', $e->getMessage()); }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: delete (drafts only)
// =============================================================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'delete');
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [$id]);
    if (!$ecn) { flash_set('error', 'ECN not found.'); header('Location: ' . url('/ecn.php')); exit; }
    if ($ecn['status'] !== 'draft') {
        flash_set('error', 'Only draft ECNs can be deleted.');
        header('Location: ' . url('/ecn.php?action=view&id=' . $id)); exit;
    }
    db_exec("DELETE FROM ecns WHERE id = ?", [$id]);
    flash_set('success', 'ECN ' . $ecn['ecn_no'] . ' deleted.');
    header('Location: ' . url('/ecn.php'));
    exit;
}

// =============================================================
// POST: add_item / remove_item
// =============================================================
if ($action === 'add_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');
    $itemIds = isset($_POST['item_ids']) && is_array($_POST['item_ids']) ? $_POST['item_ids'] : [];
    $singleId = (int)input('item_id', 0);
    if ($singleId) $itemIds[] = $singleId;
    $note = trim((string)input('item_note', '')) ?: null;
    $added = 0;
    foreach ($itemIds as $iid) {
        if ((int)$iid > 0) {
            ecn_add_affected_item($id, (int)$iid, $note);
            $added++;
        }
    }
    flash_set('success', $added . ' affected item' . ($added === 1 ? '' : 's') . ' linked.');
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}
if ($action === 'remove_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');
    $iid = (int)input('item_id', 0);
    if ($iid) ecn_remove_affected_item($id, $iid);
    flash_set('success', 'Item unlinked.');
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: create_successor — material_sub helper
// Build a new inv_items row cloned from the from_item with operator
// overrides, link it to the ECN as successor_item_id.
// =============================================================
if ($action === 'create_successor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');
    $overrides = [];
    foreach (['code','name','short_description','long_description',
              'uom','manufacturer_type','dwg_no','dwg_rev_no',
              'part_no','part_rev_no','unit_cost','rev_label'] as $k) {
        $v = trim((string)input($k, ''));
        if ($v !== '') $overrides[$k] = $v;
    }
    // Numeric coercions
    foreach (['uom_id','category_id','division_id'] as $k) {
        $v = (int)input($k, 0);
        if ($v > 0) $overrides[$k] = $v;
    }
    try {
        $newId = ecn_create_successor_item($id, $overrides, $uid);
        flash_set('success', "Successor item created (#$newId).");
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: apply_bom_sweep — rewrite selected BOM lines
// Expected form: rewrite[<bom_line_id>] = 1, qty[<bom_line_id>] = <float>
// =============================================================
if ($action === 'apply_bom_sweep' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');
    $rewrite = isset($_POST['rewrite']) && is_array($_POST['rewrite']) ? $_POST['rewrite'] : [];
    $qtys    = isset($_POST['qty'])     && is_array($_POST['qty'])     ? $_POST['qty']     : [];
    $choices = [];
    foreach ($rewrite as $bomLineId => $_) {
        $choices[(int)$bomLineId] = [
            'rewrite' => true,
            'qty' => isset($qtys[$bomLineId]) ? $qtys[$bomLineId] : null,
        ];
    }
    try {
        $n = ecn_apply_bom_sweep($id, $choices, $uid);
        flash_set('success', "BOM sweep applied: $n line(s) rewritten.");
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: skip_bom_sweep — operator says "no BOMs need rewriting"
// =============================================================
if ($action === 'skip_bom_sweep' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');
    try {
        ecn_skip_bom_sweep($id, $uid);
        flash_set('success', 'BOM sweep skipped. You can now Make Effective.');
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: create_linked_drawings — fire off cascade Draft ECNs
// =============================================================
if ($action === 'create_linked_drawings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ecn', 'create');
    try {
        $created = ecn_create_linked_drawing_ecns($id, $uid);
        $n = count($created);
        flash_set('success', "Created $n linked drawing_rev ECN" . ($n === 1 ? '' : 's') . ' in Draft.');
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . url('/ecn.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// VIEW (single ECN) / EDIT / NEW
// =============================================================
require __DIR__ . '/includes/header.php';

if ($action === 'view' && $id > 0) {
    $ecn = db_one(
        "SELECT e.*, u.full_name AS originator_name,
                d.code AS pending_doc_code, d.title AS pending_doc_title
           FROM ecns e
      LEFT JOIN users u ON u.id = e.originator_id
      LEFT JOIN documents d ON d.id = e.pending_doc_id
          WHERE e.id = ?",
        [$id]
    );
    if (!$ecn) {
        echo '<div class="alert alert-error">ECN not found.</div>';
        require __DIR__ . '/includes/footer.php'; exit;
    }
    render_ecn_view($ecn, $uid);
}
elseif ($action === 'new') {
    require_permission('ecn', 'create');
    render_ecn_form(null, $uid);
}
elseif ($action === 'edit' && $id > 0) {
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [$id]);
    if (!$ecn) { echo '<div class="alert alert-error">ECN not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    if ($ecn['status'] !== 'draft' && !permission_check('ecn', 'manage')) {
        echo '<div class="alert alert-warning">Only draft ECNs can be edited.</div>';
        echo '<a class="btn btn-ghost" href="' . h(url('/ecn.php?action=view&id=' . $id)) . '">← Back</a>';
        require __DIR__ . '/includes/footer.php'; exit;
    }
    render_ecn_form($ecn, $uid);
}
else {
    render_ecn_list();
}

require __DIR__ . '/includes/footer.php';

// =============================================================
// LIST
// =============================================================
function render_ecn_list()
{
    $dtCfg = [
        'id' => 'ecns',
        'base_sql' =>
            "SELECT e.id, e.ecn_no, e.title, e.ecn_type, e.status,
                    e.effective_date, e.created_at, e.updated_at,
                    u.full_name AS originator_name,
                    (SELECT COUNT(*) FROM ecn_affected_items ai WHERE ai.ecn_id = e.id) AS aff_count
               FROM ecns e
          LEFT JOIN users u ON u.id = e.originator_id",
        'default_order' => 'e.id DESC',
        'columns' => [
            ['key' => 'ecn_no',          'label' => 'No',          'sortable' => true, 'searchable' => true, 'sql_col' => 'e.ecn_no'],
            ['key' => 'title',           'label' => 'Title',       'sortable' => true, 'searchable' => true, 'sql_col' => 'e.title'],
            ['key' => 'ecn_type',        'label' => 'Type',        'sortable' => true, 'searchable' => true, 'sql_col' => 'e.ecn_type'],
            ['key' => 'status',          'label' => 'Status',      'sortable' => true, 'searchable' => true, 'sql_col' => 'e.status'],
            ['key' => 'originator_name', 'label' => 'Originator',  'sortable' => true, 'searchable' => true, 'sql_col' => 'u.full_name'],
            ['key' => 'aff_count',       'label' => 'Items',       'sortable' => false, 'searchable' => false],
            ['key' => 'effective_date',  'label' => 'Eff. date',   'sortable' => true, 'searchable' => false, 'sql_col' => 'e.effective_date'],
            ['key' => 'updated_at',      'label' => 'Updated',     'sortable' => true, 'searchable' => false, 'sql_col' => 'e.updated_at'],
        ],
    ];

    $rowRenderer = function ($row) {
        $types = ecn_types();
        $typeDef = isset($types[$row['ecn_type']]) ? $types[$row['ecn_type']] : null;
        $pill = ecn_status_pill($row['status']);
        $statusCell = '<span class="pill ' . h($pill['class']) . '">' . h($pill['label']) . '</span>';
        $typeCell = $typeDef
            ? ($typeDef['icon'] . ' ' . h($typeDef['label']))
            : h($row['ecn_type']);
        $titleCell = '<a href="' . h(url('/ecn.php?action=view&id=' . (int)$row['id'])) . '">' . h($row['title']) . '</a>';
        $noCell    = '<a href="' . h(url('/ecn.php?action=view&id=' . (int)$row['id'])) . '"><strong>' . h($row['ecn_no']) . '</strong></a>';
        return [
            'ecn_no'          => $noCell,
            'title'           => $titleCell,
            'ecn_type'        => $typeCell,
            'status'          => $statusCell,
            'originator_name' => h($row['originator_name'] ?: ''),
            'aff_count'       => (int)$row['aff_count'],
            'effective_date'  => $row['effective_date'] ? date('d M Y', strtotime($row['effective_date'])) : '<span class="muted small">—</span>',
            'updated_at'      => dt_display($row['updated_at']),
        ];
    };

    $dt = data_table_run($dtCfg, $rowRenderer);
    ?>
    <div class="page-head">
        <div>
            <h1>Engineering Change Notices</h1>
            <p class="muted">Track and approve changes to inventory items, drawings, BOMs, vendors, and UOMs.</p>
        </div>
        <div class="head-actions">
            <?php if (permission_check('ecn', 'create')): ?>
                <a class="btn btn-primary" href="<?= h(url('/ecn.php?action=new')) ?>">+ New ECN</a>
            <?php endif; ?>
            <?php if (permission_check('ecn', 'manage')): ?>
                <a class="btn btn-ghost" href="<?= h(url('/ecn_admin.php')) ?>">⚙ Slot config</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    data_table_render($dtCfg, $dt, $rowRenderer);
}

// =============================================================
// FORM (new / edit)
// =============================================================
function render_ecn_form($ecn, $uid)
{
    $isNew = !$ecn;
    $types = ecn_types();

    // Load select-option data for the various type-specific fields
    $documents = db_all(
        "SELECT id, code, title FROM documents
          WHERE deleted_at IS NULL AND status IN ('draft','in_review','approved','released','received','accepted','filed')
          ORDER BY code"
    );
    $items = db_all(
        "SELECT id, code, name, uom, part_rev_no FROM inv_items
          WHERE is_active = 1 ORDER BY code"
    );
    $vendors = db_all(
        "SELECT id, code, name FROM vendors WHERE is_active = 1 ORDER BY name"
    );

    $details = $ecn ? ecn_decode_details($ecn['type_details']) : [];

    // When arriving from a document's "Raise revision ECN" button, the
    // document is passed as ?doc_id= — preselect it on a new drawing_rev
    // ECN so the operator doesn't have to re-find it.
    if ($isNew && empty($details['document_id'])) {
        $preDoc = (int)input('doc_id', 0);
        if ($preDoc > 0) $details['document_id'] = $preDoc;
    }

    // When arriving from an item's "Raise ECN" button (?item_id=), fetch
    // that item's CURRENT revision so we can show it as read-only context
    // next to the New revision label. We never auto-fill the new-rev box —
    // the operator must type the new revision explicitly.
    $preItemRev = null; $preItemCode = null; $preItemPartRev = null;
    if ($isNew) {
        $preItemForRev = (int)input('item_id', 0);
        if ($preItemForRev > 0) {
            $pir = db_one("SELECT code, rev_label, part_rev_no FROM inv_items WHERE id = ?", [$preItemForRev]);
            if ($pir) {
                $preItemCode    = $pir['code'];
                $preItemRev     = $pir['rev_label'];
                $preItemPartRev = $pir['part_rev_no'];
            }
        }
    }

    // Default type: item_change when arriving from an inventory item
    // (?item_id=), otherwise drawing_rev. An explicit ?ecn_type= always wins.
    $defaultType = ((int)input('item_id', 0) > 0) ? 'item_change' : 'drawing_rev';
    $currentType = $ecn ? $ecn['ecn_type'] : (string)input('ecn_type', $defaultType);
    ?>
    <div class="page-head">
        <div>
            <h1><?= $isNew ? 'New ECN' : 'Edit ' . h($ecn['ecn_no']) ?></h1>
            <p class="muted">
                <?= $isNew
                    ? 'Pick a change type, describe what\'s changing, and add affected items. The ECN starts in Draft — submit it when ready for review.'
                    : 'Editing draft. Submit when ready for sign-off.' ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url($ecn ? '/ecn.php?action=view&id=' . (int)$ecn['id'] : '/ecn.php')) ?>">← Back</a>
        </div>
    </div>

    <form method="post" action="<?= h(url('/ecn.php?action=save')) ?>" enctype="multipart/form-data" id="ecn-form">
        <?= csrf_field() ?>
        <?php if ($ecn): ?><input type="hidden" name="id" value="<?= (int)$ecn['id'] ?>"><?php endif; ?>

        <!-- TYPE + IDENTITY -->
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Change type &amp; identity</h3>
            <div class="form-grid-2">
                <div class="field span-2">
                    <label>Change type <span style="color: var(--danger);">*</span></label>
                    <select name="ecn_type" id="ecn-type" required <?= $ecn ? 'disabled' : '' ?>>
                        <?php foreach ($types as $tcode => $t): ?>
                            <option value="<?= h($tcode) ?>" <?= $tcode === $currentType ? 'selected' : '' ?>>
                                <?= $t['icon'] ?> <?= h($t['label']) ?> — <?= h($t['short']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($ecn): ?>
                        <input type="hidden" name="ecn_type" value="<?= h($ecn['ecn_type']) ?>">
                        <span class="field-hint">Type can't be changed after creation. Cancel and re-create to switch.</span>
                    <?php endif; ?>
                </div>
                <div class="field span-2">
                    <label>Title <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="title" required maxlength="240"
                           value="<?= h($ecn['title'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label>Business reason</label>
                    <textarea name="business_reason" rows="2"
                              placeholder="Why is this change being made?"><?= h($ecn['business_reason'] ?? '') ?></textarea>
                </div>
                <div class="field span-2">
                    <label>Description / scope</label>
                    <textarea name="description" rows="3"
                              placeholder="What exactly is changing?"><?= h($ecn['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- TYPE-SPECIFIC FIELDS -->
        <?php foreach ($types as $tcode => $t): ?>
            <div class="card form-card ecn-type-panel" data-type="<?= h($tcode) ?>"
                 style="margin-bottom: 18px; <?= $tcode === $currentType ? '' : 'display: none;' ?>">
                <h3 style="margin: 0 0 14px; font-size: 14px;">
                    <?= $t['icon'] ?> <?= h($t['label']) ?> details
                </h3>
                <div class="form-grid-2">
                    <?php if ($tcode === 'item_change'): ?>
                        <div class="field span-2">
                            <label>Inventory item <span style="color: var(--danger);">*</span></label>
                            <select name="item_id" id="item_change_item">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        data-part-rev="<?= h($i['part_rev_no'] ?? '') ?>"
                                        <?= (isset($details['item_id']) && (int)$details['item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>New part revision <span style="color: var(--danger);">*</span></label>
                            <?php if ($isNew && $preItemRev !== null): ?>
                                <p class="muted small" style="margin:0 0 6px;" id="item_change_curr">
                                    Current part rev of <strong><?= h($preItemCode) ?></strong>:
                                    <strong><?= h($preItemPartRev !== '' && $preItemPartRev !== null ? $preItemPartRev : '—') ?></strong>.
                                    Enter the new part revision below.
                                </p>
                            <?php else: ?>
                                <p class="muted small" style="margin:0 0 6px; display:none;" id="item_change_curr"></p>
                            <?php endif; ?>
                            <input type="text" name="new_part_rev_no" maxlength="32"
                                   placeholder="e.g. B, 02, Rev 3"
                                   value="<?= h($details['new_part_rev_no'] ?? '') ?>">
                            <span class="field-hint">Not auto-generated — the item's part_rev_no becomes exactly this when the ECN goes Effective.</span>
                        </div>
                        <div class="field">
                            <label>New part report <span style="color: var(--danger);">*</span></label>
                            <input type="file" name="pending_file">
                            <span class="field-hint">
                                Required. Uploaded with the ECN; becomes a new revision of the item's Part Report document when the ECN goes Effective.
                                If the item has no Part Report document yet, one is created automatically.
                                <?php if ($ecn && $ecn['pending_file_name']): ?>
                                    <br>Current: <strong><?= h($ecn['pending_file_name']) ?></strong>
                                    (<?= number_format((int)$ecn['pending_file_size']) ?> bytes)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="field span-2">
                            <label>Change summary</label>
                            <textarea name="change_summary" rows="2"
                                      placeholder="What changed on the part? Goes on the part report rev's change note."><?= h($details['change_summary'] ?? '') ?></textarea>
                        </div>
                        <?php
                        // Linked documents on the subject item — let the operator
                        // flag which ones also need changing. Each checked doc gets
                        // a draft drawing_rev ECN auto-created at save time.
                        $icItemId = (int)($details['item_id'] ?? input('item_id', 0));
                        if ($isNew && $icItemId > 0):
                            $linkedDocs = db_all(
                                "SELECT d.id, d.code, d.title, dc.name AS cat_name
                                   FROM doc_entity_links l
                                   JOIN documents d ON d.id = l.document_id AND d.deleted_at IS NULL
                              LEFT JOIN doc_categories dc ON dc.id = d.category_id
                                  WHERE l.entity_type = 'inv_item' AND l.entity_id = ?
                                    AND (dc.code IS NULL OR dc.code <> 'part_report')
                               ORDER BY d.code",
                                [$icItemId]
                            );
                            if ($linkedDocs):
                        ?>
                        <div class="field span-2">
                            <label>Linked documents that also need changing <span class="muted small">(optional)</span></label>
                            <div style="border:1px solid var(--border); border-radius:6px; padding:8px 12px;">
                                <?php foreach ($linkedDocs as $ld): ?>
                                    <label class="nowrap" style="display:flex; align-items:center; gap:8px; font-weight:normal; padding:3px 0;">
                                        <input type="checkbox" name="change_linked_doc_ids[]" value="<?= (int)$ld['id'] ?>" style="width:auto;">
                                        <code><?= h($ld['code']) ?></code> <?= h($ld['title']) ?>
                                        <?php if ($ld['cat_name']): ?><span class="muted small">(<?= h($ld['cat_name']) ?>)</span><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <span class="field-hint">Checked documents get a Draft drawing-revision ECN created automatically so they can be revised separately.</span>
                        </div>
                        <?php endif; endif; ?>
                    <?php elseif ($tcode === 'drawing_rev'): ?>
                        <div class="field">
                            <label>Document <span style="color: var(--danger);">*</span></label>
                            <select name="document_id">
                                <option value="">— pick —</option>
                                <?php foreach ($documents as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"
                                        <?= (isset($details['document_id']) && (int)$details['document_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                                        <?= h($d['code']) ?> — <?= h($d['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>New revision label <span style="color: var(--danger);">*</span></label>
                            <?php if ($isNew && $preItemRev !== null): ?>
                                <p class="muted small" style="margin:0 0 6px;">
                                    Current revision of <strong><?= h($preItemCode) ?></strong>:
                                    <strong><?= h($preItemRev !== '' ? $preItemRev : '—') ?></strong>.
                                    Enter the new revision below.
                                </p>
                            <?php endif; ?>
                            <input type="text" name="new_rev_label" maxlength="64"
                                   placeholder="e.g. B, 2.0, Rev 3"
                                   value="<?= h($details['new_rev_label'] ?? '') ?>">
                            <span class="field-hint">Type the new revision number. It is not auto-generated — the item's revision changes to exactly what you enter here when the ECN goes Effective.</span>
                        </div>
                        <div class="field span-2">
                            <label>New revision file <span style="color: var(--danger);">*</span></label>
                            <input type="file" name="pending_file">
                            <span class="field-hint">
                                Uploaded with the ECN. When approval reaches Effective, this file becomes the new revision in DMS.
                                <?php if ($ecn && $ecn['pending_file_name']): ?>
                                    <br>Current: <strong><?= h($ecn['pending_file_name']) ?></strong>
                                    (<?= number_format((int)$ecn['pending_file_size']) ?> bytes)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="field span-2">
                            <label>Change summary</label>
                            <textarea name="change_summary" rows="2"
                                      placeholder="Goes on the rev's change note in DMS"><?= h($details['change_summary'] ?? '') ?></textarea>
                        </div>
                    <?php elseif ($tcode === 'bom_change'): ?>
                        <div class="field span-2">
                            <label>Parent item <span style="color: var(--danger);">*</span></label>
                            <select name="parent_item_id">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['parent_item_id']) && (int)$details['parent_item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field span-2">
                            <label>BOM edits</label>
                            <textarea name="bom_edits_summary" rows="3"
                                      placeholder="Describe each edit: add Item X qty 2; remove Item Y; change Z qty from 1 to 3"><?= h(
                                        isset($details['edits'][0]['summary']) ? $details['edits'][0]['summary'] : ''
                                      ) ?></textarea>
                            <span class="field-hint">Free-form for now; a structured editor can be added later.</span>
                        </div>
                    <?php elseif ($tcode === 'material_sub'): ?>
                        <div class="field">
                            <label>From item <span style="color: var(--danger);">*</span></label>
                            <select name="from_item_id">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['from_item_id']) && (int)$details['from_item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>To item <span style="color: var(--danger);">*</span></label>
                            <select name="to_item_id">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['to_item_id']) && (int)$details['to_item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($tcode === 'uom_change'): ?>
                        <div class="field span-2">
                            <label>Item <span style="color: var(--danger);">*</span></label>
                            <select name="item_id">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['item_id']) && (int)$details['item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?> (<?= h($i['uom']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>From UOM</label>
                            <input type="text" name="from_uom" maxlength="16"
                                   value="<?= h($details['from_uom'] ?? '') ?>"
                                   placeholder="e.g. pcs">
                        </div>
                        <div class="field">
                            <label>To UOM</label>
                            <input type="text" name="to_uom" maxlength="16"
                                   value="<?= h($details['to_uom'] ?? '') ?>"
                                   placeholder="e.g. kg">
                        </div>
                        <div class="field span-2">
                            <label>Conversion factor <span style="color: var(--danger);">*</span></label>
                            <input type="number" name="conversion_factor" step="0.000001" min="0"
                                   value="<?= h($details['conversion_factor'] ?? '') ?>">
                            <span class="field-hint">1 of "From UOM" = N of "To UOM". E.g. 1 box = 24 pcs → 24.</span>
                        </div>
                    <?php elseif ($tcode === 'vendor_change'): ?>
                        <div class="field span-2">
                            <label>Item <span style="color: var(--danger);">*</span></label>
                            <select name="item_id">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['item_id']) && (int)$details['item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>From vendor</label>
                            <select name="from_vendor_id">
                                <option value="">— none —</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>"
                                        <?= (isset($details['from_vendor_id']) && (int)$details['from_vendor_id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                        <?= h($v['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>To vendor <span style="color: var(--danger);">*</span></label>
                            <select name="to_vendor_id">
                                <option value="">— pick —</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>"
                                        <?= (isset($details['to_vendor_id']) && (int)$details['to_vendor_id'] === (int)$v['id']) ? 'selected' : '' ?>>
                                        <?= h($v['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php elseif ($tcode === 'obsolescence'): ?>
                        <div class="field">
                            <label>Item to obsolete <span style="color: var(--danger);">*</span></label>
                            <select name="item_id">
                                <option value="">— pick —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['item_id']) && (int)$details['item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Supersede with <span class="muted small">(optional)</span></label>
                            <select name="supersede_to_item_id">
                                <option value="">— none —</option>
                                <?php foreach ($items as $i): ?>
                                    <option value="<?= (int)$i['id'] ?>"
                                        <?= (isset($details['supersede_to_item_id']) && (int)$details['supersede_to_item_id'] === (int)$i['id']) ? 'selected' : '' ?>>
                                        <?= h($i['code']) ?> — <?= h($i['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($isNew): ?>
        <!-- AFFECTED INVENTORY ITEMS (new ECN only — existing ECNs manage
             these on the view page's dedicated panel). Lets the operator
             link items at creation time so the ECN's effects apply to them
             when it goes Effective. -->
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Affected inventory items</h3>
            <div class="form-grid-2">
                <div class="field span-2">
                    <label>Items affected by this change</label>
                    <?php $preItemId = (int)input('item_id', 0); ?>
                    <select name="affected_item_ids[]" multiple size="6"
                            style="width:100%; min-height:120px;">
                        <?php foreach ($items as $i): ?>
                            <option value="<?= (int)$i['id'] ?>"
                                <?= ($preItemId && $preItemId === (int)$i['id']) ? 'selected' : '' ?>>
                                <?= h($i['code']) ?> — <?= h($i['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint">
                        Hold Ctrl (Cmd on Mac) to select more than one. These items are linked to the ECN now; the ECN's effect
                        (rev bump, vendor/UOM change, obsolescence, etc.) is applied to them when it goes Effective.
                        You can also add or remove items later on the ECN view page.
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- EFFECTIVITY -->
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Effectivity</h3>
            <div class="form-grid-2">
                <div class="field span-2">
                    <label>Mode</label>
                    <select name="effectivity_mode" id="ecn-eff-mode">
                        <option value="date"   <?= ($ecn['effectivity_mode'] ?? 'date') === 'date'   ? 'selected' : '' ?>>Date-based — all transactions on/after this date use the new rev</option>
                        <option value="lot"    <?= ($ecn['effectivity_mode'] ?? '')     === 'lot'    ? 'selected' : '' ?>>Lot-based — next inv txn after the effective date triggers it</option>
                        <option value="manual" <?= ($ecn['effectivity_mode'] ?? '')     === 'manual' ? 'selected' : '' ?>>Manual cutover — operator confirms when ready</option>
                    </select>
                </div>
                <div class="field span-2 ecn-eff-date">
                    <label>Effective date</label>
                    <input type="date" name="effective_date"
                           value="<?= h($ecn['effective_date'] ?? '') ?>">
                    <span class="field-hint">Required for date-based; advisory for lot-based; informational for manual.</span>
                </div>
            </div>
        </div>

        <!-- CROSS-SYSTEM EFFECTS (Phase B-2) -->
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Cross-system effects</h3>
            <div class="form-grid-2">
                <div class="field span-2">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; text-transform: none; letter-spacing: 0; color: var(--text);">
                        <input type="checkbox" name="also_revise_drawings" value="1"
                               <?= !empty($ecn['also_revise_drawings']) ? 'checked' : '' ?>
                               style="width: auto;">
                        Also revise linked drawing(s)
                    </label>
                    <span class="field-hint">
                        When this ECN goes Effective, a button will appear to auto-create Draft <code>drawing_rev</code> ECNs for every document linked to the affected items (via Linked Entities on the doc view). The engineer then completes each with the new file.
                    </span>
                </div>
            </div>
        </div>

        <!-- STOCK DISPOSITION -->
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Stock disposition <span class="muted small" style="font-weight: normal;">— what to do with existing on-hand stock</span></h3>
            <div class="form-grid-2">
                <div class="field">
                    <label>Use as-is qty</label>
                    <input type="number" step="0.001" min="0" name="disp_use_as_is"
                           value="<?= h($ecn['disp_use_as_is'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Rework qty</label>
                    <input type="number" step="0.001" min="0" name="disp_rework"
                           value="<?= h($ecn['disp_rework'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Scrap qty</label>
                    <input type="number" step="0.001" min="0" name="disp_scrap"
                           value="<?= h($ecn['disp_scrap'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Sort qty</label>
                    <input type="number" step="0.001" min="0" name="disp_sort"
                           value="<?= h($ecn['disp_sort'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label>Disposition notes</label>
                    <textarea name="disp_notes" rows="2"
                              placeholder="Free-text justification or instructions"><?= h($ecn['disp_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create ECN' : 'Save changes' ?></button>
        </div>
    </form>

    <script>
    // Toggle the type-specific panels when the type dropdown changes
    (function () {
        var typeSel = document.getElementById('ecn-type');
        if (!typeSel) return;
        function refresh() {
            var t = typeSel.value;
            document.querySelectorAll('.ecn-type-panel').forEach(function (el) {
                el.style.display = (el.getAttribute('data-type') === t) ? '' : 'none';
            });
        }
        typeSel.addEventListener('change', refresh);
        refresh();
    })();

    // item_change: when the operator picks a different inventory item,
    // update the "current part rev" context line from the option's
    // data-part-rev. Never touches the new-part-rev input.
    (function () {
        var sel = document.getElementById('item_change_item');
        var ctx = document.getElementById('item_change_curr');
        if (!sel || !ctx) return;
        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) { ctx.style.display = 'none'; return; }
            var pr = opt.getAttribute('data-part-rev') || '';
            var code = opt.textContent.split('—')[0].trim();
            ctx.innerHTML = 'Current part rev of <strong>' + code + '</strong>: <strong>'
                          + (pr !== '' ? pr.replace(/[<>&]/g, '') : '—')
                          + '</strong>. Enter the new part revision below.';
            ctx.style.display = '';
        });
    })();
    </script>
    <?php
}

// =============================================================
// VIEW (single ECN)
// =============================================================
function render_ecn_view($ecn, $uid)
{
    $types = ecn_types();
    $typeDef = isset($types[$ecn['ecn_type']]) ? $types[$ecn['ecn_type']] : null;
    $pill = ecn_status_pill($ecn['status']);
    $details = ecn_decode_details($ecn['type_details']);
    $signoffs = ecn_signoff_state((int)$ecn['id']);
    $affected = ecn_affected_items((int)$ecn['id']);
    $docRevs  = ecn_doc_revs((int)$ecn['id']);
    $history  = db_all(
        "SELECT h.*, u.full_name AS actor_name
           FROM ecn_history h
      LEFT JOIN users u ON u.id = h.actor_id
          WHERE h.ecn_id = ?
          ORDER BY h.id DESC",
        [(int)$ecn['id']]
    );

    $canEdit    = $ecn['status'] === 'draft' || permission_check('ecn', 'manage');
    $canSubmit  = $ecn['status'] === 'draft' &&
                  ((int)$ecn['originator_id'] === (int)$uid || permission_check('ecn', 'manage'));
    $canMakeEff = $ecn['status'] === 'approved' && permission_check('ecn', 'manage');
    $canClose   = $ecn['status'] === 'effective' && permission_check('ecn', 'manage');
    $canCancel  = in_array($ecn['status'], ['draft','submitted','in_review','approved'], true) &&
                  ((int)$ecn['originator_id'] === (int)$uid || permission_check('ecn', 'manage'));
    $canDelete  = $ecn['status'] === 'draft' && permission_check('ecn', 'delete');
    ?>
    <div class="page-head">
        <div>
            <h1>
                <?= $typeDef ? $typeDef['icon'] : '' ?>
                <?= h($ecn['ecn_no']) ?>
                <span class="pill <?= h($pill['class']) ?>" style="margin-left: 10px; font-size: 11px;">
                    <?= h($pill['label']) ?>
                </span>
            </h1>
            <p class="muted">
                <?= h($ecn['title']) ?>
                <?php if ($typeDef): ?> · <?= h($typeDef['label']) ?><?php endif; ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/ecn.php')) ?>">← All ECNs</a>
            <?php if ($canEdit): ?>
                <a class="btn btn-ghost" href="<?= h(url('/ecn.php?action=edit&id=' . (int)$ecn['id'])) ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ecn['status'] === 'draft' && $ecn['rejection_reason']): ?>
        <div class="card" style="margin-bottom: 18px; background: #fef2f2; border-left: 3px solid var(--danger);">
            <div class="card-body">
                <strong>Rejected on the previous cycle:</strong>
                <p style="margin: 6px 0 0;"><?= h($ecn['rejection_reason']) ?></p>
                <p class="muted small" style="margin: 4px 0 0;">Fix the issue above and re-submit when ready.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- WORKFLOW ACTIONS -->
    <?php if ($canSubmit || $canMakeEff || $canClose || $canCancel): ?>
    <div class="card" style="margin-bottom: 18px; background: #fffce8; border-left: 3px solid #d97706;">
        <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <strong style="margin-right: 8px;">Workflow:</strong>
            <?php if ($canSubmit): ?>
                <form method="post" action="<?= h(url('/ecn.php?action=submit&id=' . (int)$ecn['id'])) ?>" style="display: inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('Submit this ECN for review? All three sign-offs will be required.')">
                        Submit for review
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canMakeEff): ?>
                <form method="post" action="<?= h(url('/ecn.php?action=make_effective&id=' . (int)$ecn['id'])) ?>"
                      style="display: inline; gap: 6px; display: inline-flex; align-items: center;">
                    <?= csrf_field() ?>
                    <input type="date" name="effective_date" value="<?= h($ecn['effective_date'] ?? date('Y-m-d')) ?>"
                           style="width: 150px;">
                    <button type="submit" class="btn btn-success"
                            onclick="return confirm('Make this ECN effective?\n\nFor drawing_rev ECNs, this will create the new revision in DMS.')">
                        Make Effective
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canClose): ?>
                <form method="post" action="<?= h(url('/ecn.php?action=close&id=' . (int)$ecn['id'])) ?>" style="display: inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            onclick="return confirm('Close this ECN?')">Close</button>
                </form>
            <?php endif; ?>
            <?php if ($canCancel): ?>
                <form method="post" action="<?= h(url('/ecn.php?action=cancel&id=' . (int)$ecn['id'])) ?>"
                      style="display: inline-flex; align-items: center; gap: 6px;">
                    <?= csrf_field() ?>
                    <input type="text" name="reason" placeholder="Reason (optional)" style="width: 200px;">
                    <button type="submit" class="btn btn-warning"
                            onclick="return confirm('Cancel this ECN? This cannot be undone.')">Cancel ECN</button>
                </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" action="<?= h(url('/ecn.php?action=delete&id=' . (int)$ecn['id'])) ?>" style="display: inline; margin-left: auto;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('PERMANENTLY DELETE this ECN? This cannot be undone.')">Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TWO-COL LAYOUT: metadata + details on left, signoffs + items + history on right -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

        <!-- LEFT COLUMN -->
        <div>
            <!-- METADATA -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Metadata</h3></div>
                <div class="card-body">
                    <table class="data-table">
                        <tbody>
                            <tr><th>ECN no</th><td><strong><?= h($ecn['ecn_no']) ?></strong></td></tr>
                            <tr><th>Type</th><td><?= $typeDef ? ($typeDef['icon'] . ' ' . h($typeDef['label'])) : h($ecn['ecn_type']) ?></td></tr>
                            <tr><th>Originator</th><td><?= h($ecn['originator_name'] ?: '—') ?></td></tr>
                            <tr><th>Effectivity</th><td>
                                <?= h(ucfirst($ecn['effectivity_mode'])) ?>
                                <?php if ($ecn['effective_date']): ?> · <?= h(date('d M Y', strtotime($ecn['effective_date']))) ?><?php endif; ?>
                            </td></tr>
                            <tr><th>Created</th><td><?= h(dt_display($ecn['created_at'])) ?></td></tr>
                            <?php if ($ecn['submitted_at']): ?>
                                <tr><th>Submitted</th><td><?= h(dt_display($ecn['submitted_at'])) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($ecn['approved_at']): ?>
                                <tr><th>Approved</th><td><?= h(dt_display($ecn['approved_at'])) ?></td></tr>
                            <?php endif; ?>
                            <?php if ($ecn['effective_at']): ?>
                                <tr><th>Effective at</th><td><?= h(dt_display($ecn['effective_at'])) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- BUSINESS REASON + DESCRIPTION -->
            <?php if ($ecn['business_reason'] || $ecn['description']): ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Background</h3></div>
                    <div class="card-body">
                        <?php if ($ecn['business_reason']): ?>
                            <p><strong>Reason:</strong> <?= nl2br(h($ecn['business_reason'])) ?></p>
                        <?php endif; ?>
                        <?php if ($ecn['description']): ?>
                            <p><strong>Description:</strong> <?= nl2br(h($ecn['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TYPE-SPECIFIC DETAILS -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Change details</h3></div>
                <div class="card-body">
                    <?php render_ecn_details_panel($ecn, $details); ?>
                </div>
            </div>

            <!-- STOCK DISPOSITION -->
            <?php if ($ecn['disp_use_as_is'] !== null || $ecn['disp_rework'] !== null
                    || $ecn['disp_scrap'] !== null || $ecn['disp_sort'] !== null
                    || $ecn['disp_notes']): ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Stock disposition</h3></div>
                    <div class="card-body">
                        <table class="data-table">
                            <tbody>
                                <?php if ($ecn['disp_use_as_is'] !== null): ?>
                                    <tr><th>Use as-is</th><td><?= h(rtrim(rtrim((string)$ecn['disp_use_as_is'], '0'), '.')) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($ecn['disp_rework'] !== null): ?>
                                    <tr><th>Rework</th><td><?= h(rtrim(rtrim((string)$ecn['disp_rework'], '0'), '.')) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($ecn['disp_scrap'] !== null): ?>
                                    <tr><th>Scrap</th><td><?= h(rtrim(rtrim((string)$ecn['disp_scrap'], '0'), '.')) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($ecn['disp_sort'] !== null): ?>
                                    <tr><th>Sort</th><td><?= h(rtrim(rtrim((string)$ecn['disp_sort'], '0'), '.')) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($ecn['disp_notes']): ?>
                                    <tr><th>Notes</th><td><?= nl2br(h($ecn['disp_notes'])) ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- INVENTORY SIDE-EFFECTS -->
            <?php
                $invPreview = ecn_preview_inventory_effects((int)$ecn['id']);
                if (!empty($invPreview)):
                    $isEffective = in_array($ecn['status'], ['effective', 'closed'], true);
            ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head">
                        <h3 style="margin: 0; font-size: 15px;">
                            Inventory side-effects
                            <span class="muted small" style="font-weight: normal;">
                                <?= $isEffective ? '— applied at Effective' : '— preview, will apply on Make Effective' ?>
                            </span>
                        </h3>
                    </div>
                    <div class="card-body">
                        <table class="data-table">
                            <thead><tr><th>Effect</th><th>Item</th><th>Detail</th></tr></thead>
                            <tbody>
                                <?php foreach ($invPreview as $p):
                                    $kindLabels = [
                                        'rev_bump'   => ['pill-info',    'Rev bump'],
                                        'vendor_set' => ['pill-info',    'Vendor set'],
                                        'uom_change' => ['pill-warning', 'UOM change'],
                                        'noop'       => ['pill-neutral', 'No-op'],
                                        'deferred'   => ['pill-neutral', 'Deferred'],
                                    ];
                                    $kl = $kindLabels[$p['kind']] ?? ['pill-neutral', $p['kind']];
                                ?>
                                    <tr>
                                        <td><span class="pill <?= h($kl[0]) ?>"><?= h($kl[1]) ?></span></td>
                                        <td>
                                            <?= h($p['item_code']) ?>
                                            <?php if ($p['item_name']): ?>
                                                <br><span class="muted small"><?= h($p['item_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($p['detail']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
            <!-- SIGN-OFFS -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Sign-offs</h3></div>
                <div class="card-body">
                    <?php if (!in_array($ecn['status'], ['submitted','in_review','approved','effective','closed'], true)): ?>
                        <p class="muted">Sign-offs will be requested once the ECN is submitted.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead><tr><th>Slot</th><th>Role</th><th>Status</th><th>Decided</th></tr></thead>
                            <tbody>
                                <?php foreach ($signoffs as $sg):
                                    $sgPill = $sg['decision'] === 'approved' ? ['pill-success', 'Approved']
                                            : ($sg['decision'] === 'rejected' ? ['pill-danger', 'Rejected']
                                            : ['pill-pending', 'Pending']);
                                    $canActOnThis = $sg['decision'] === 'pending'
                                                  && in_array($ecn['status'], ['submitted','in_review'], true)
                                                  && ecn_user_can_signoff_slot($uid, $sg['slot_code']);
                                ?>
                                    <tr>
                                        <td><strong><?= h($sg['slot_name']) ?></strong></td>
                                        <td><?= h($sg['role_name'] ?: '—') ?></td>
                                        <td>
                                            <span class="pill <?= h($sgPill[0]) ?>"><?= h($sgPill[1]) ?></span>
                                            <?php if ($sg['comment']): ?>
                                                <br><span class="muted small"><?= h($sg['comment']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $sg['decided_at'] ? h(date('d M Y H:i', strtotime($sg['decided_at']))) : '—' ?>
                                            <?php if ($sg['decided_by_name']): ?>
                                                <br><span class="muted small"><?= h($sg['decided_by_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($canActOnThis): ?>
                                        <tr><td colspan="4" style="background: #f9fafb;">
                                            <form method="post" action="<?= h(url('/ecn.php?action=signoff&id=' . (int)$ecn['id'])) ?>"
                                                  style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="slot_code" value="<?= h($sg['slot_code']) ?>">
                                                <input type="text" name="comment" placeholder="Optional comment"
                                                       style="flex: 1; min-width: 180px;" maxlength="500">
                                                <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm">Approve</button>
                                                <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Reject this ECN? It will return to Draft and the originator will need to re-submit.')">
                                                    Reject
                                                </button>
                                            </form>
                                        </td></tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AFFECTED ITEMS -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Affected inventory items <span class="muted small">(<?= count($affected) ?>)</span></h3></div>
                <div class="card-body">
                    <?php if ($ecn['status'] === 'draft' && permission_check('ecn', 'create')): ?>
                        <?php $allItems = db_all("SELECT id, code, name FROM inv_items WHERE is_active = 1 ORDER BY code"); ?>
                        <form method="post" action="<?= h(url('/ecn.php?action=add_item&id=' . (int)$ecn['id'])) ?>"
                              style="margin-bottom: 12px;">
                            <?= csrf_field() ?>
                            <div class="form-grid-2">
                                <div class="field span-2">
                                    <label>Add item</label>
                                    <select name="item_id">
                                        <option value="">— pick —</option>
                                        <?php foreach ($allItems as $i): ?>
                                            <option value="<?= (int)$i['id'] ?>"><?= h($i['code']) ?> — <?= h($i['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field span-2">
                                    <label>Note <span class="muted small">(optional)</span></label>
                                    <input type="text" name="item_note" maxlength="500">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary btn-sm">+ Link item</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <?php if (empty($affected)): ?>
                        <p class="muted">No items linked yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead><tr><th>Code</th><th>Name</th><th>Note</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($affected as $a): ?>
                                    <tr>
                                        <td><?= h($a['item_code']) ?></td>
                                        <td><?= h($a['item_name']) ?></td>
                                        <td><?= h($a['note'] ?: '') ?></td>
                                        <td>
                                            <?php if ($ecn['status'] === 'draft' && permission_check('ecn', 'create')): ?>
                                                <form method="post" action="<?= h(url('/ecn.php?action=remove_item&id=' . (int)$ecn['id'])) ?>" style="display: inline;"
                                                      onsubmit="return confirm('Unlink this item?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="item_id" value="<?= (int)$a['item_id'] ?>">
                                                    <button type="submit" class="btn btn-ghost btn-xs">×</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DOCUMENTS REVISED -->
            <?php if (!empty($docRevs) || $ecn['ecn_type'] === 'drawing_rev'): ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Documents revised <span class="muted small">(<?= count($docRevs) ?>)</span></h3></div>
                    <div class="card-body">
                        <?php if (empty($docRevs)): ?>
                            <p class="muted">
                                <?php if ($ecn['status'] === 'effective' || $ecn['status'] === 'closed'): ?>
                                    No revisions created — the pending file may have been removed or the doc deleted.
                                <?php else: ?>
                                    The revision will be created in DMS when this ECN reaches Effective.
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <table class="data-table">
                                <thead><tr><th>Doc</th><th>Rev</th><th>Stage</th><th>File</th><th>Created</th></tr></thead>
                                <tbody>
                                    <?php foreach ($docRevs as $r): ?>
                                        <tr>
                                            <td><a href="<?= h(url('/documents.php?action=view&id=' . (int)$r['document_id'])) ?>"><?= h($r['doc_code']) ?></a></td>
                                            <td><strong><?= h($r['rev_label']) ?></strong></td>
                                            <td><?= h($r['stage']) ?></td>
                                            <td><?= h($r['file_name'] ?: '—') ?></td>
                                            <td><?= h(dt_display($r['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- SUCCESSOR ITEM (material_sub) -->
            <?php if ($ecn['ecn_type'] === 'material_sub'):
                $detailsTmp = ecn_decode_details($ecn['type_details']);
                $fromItemTmp = !empty($detailsTmp['from_item_id'])
                    ? db_one("SELECT id, code, name, short_description, long_description, uom, uom_id, unit_cost,
                                     manufacturer_type, dwg_no, dwg_rev_no, part_no, part_rev_no, rev_label
                                FROM inv_items WHERE id = ?", [(int)$detailsTmp['from_item_id']])
                    : null;
                $successorItemTmp = $ecn['successor_item_id']
                    ? db_one("SELECT id, code, name, is_active, is_obsolete
                                FROM inv_items WHERE id = ?", [(int)$ecn['successor_item_id']])
                    : null;
                $uoms = db_all("SELECT id, code, label FROM inv_uom WHERE is_active = 1 ORDER BY sort_order, label");
            ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Successor item</h3></div>
                    <div class="card-body">
                        <?php if ($successorItemTmp): ?>
                            <p>
                                <strong><?= h($successorItemTmp['code']) ?></strong>
                                — <?= h($successorItemTmp['name']) ?>
                                <?php if ($successorItemTmp['is_obsolete']): ?>
                                    <span class="pill pill-danger">Obsolete</span>
                                <?php elseif ($successorItemTmp['is_active']): ?>
                                    <span class="pill pill-success">Active</span>
                                <?php else: ?>
                                    <span class="pill pill-pending">Pending activation on Effective</span>
                                <?php endif; ?>
                            </p>
                            <p class="muted small">Linked as the successor for this ECN. Will be activated and the predecessor obsoleted when this ECN goes Effective.</p>
                        <?php elseif ($ecn['status'] === 'draft' && $fromItemTmp): ?>
                            <p class="muted">Two ways to set the successor:</p>
                            <ol style="margin: 4px 0 14px 22px; font-size: 13px;">
                                <li>Pick an existing item via the "To item" field on the edit form, OR</li>
                                <li>Click "Create successor item" below to clone <strong><?= h($fromItemTmp['code']) ?></strong> with optional overrides.</li>
                            </ol>
                            <details style="margin-top: 12px;">
                                <summary style="cursor: pointer; font-weight: 600;">+ Create successor item</summary>
                                <form method="post" action="<?= h(url('/ecn.php?action=create_successor&id=' . (int)$ecn['id'])) ?>"
                                      style="margin-top: 12px;">
                                    <?= csrf_field() ?>
                                    <div class="form-grid-2">
                                        <div class="field">
                                            <label>New code</label>
                                            <input type="text" name="code" maxlength="64"
                                                   placeholder="Auto-generate from <?= h($fromItemTmp['code']) ?>">
                                            <span class="field-hint">Leave blank to auto-increment.</span>
                                        </div>
                                        <div class="field">
                                            <label>Name</label>
                                            <input type="text" name="name" maxlength="255"
                                                   value="<?= h($fromItemTmp['name']) ?>">
                                        </div>
                                        <div class="field">
                                            <label>UOM</label>
                                            <select name="uom_id">
                                                <?php foreach ($uoms as $u): ?>
                                                    <option value="<?= (int)$u['id'] ?>"
                                                        <?= (int)$u['id'] === (int)($fromItemTmp['uom_id'] ?? 0) ? 'selected' : '' ?>>
                                                        <?= h($u['code']) ?> — <?= h($u['label']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label>Unit cost</label>
                                            <input type="number" step="0.01" name="unit_cost"
                                                   value="<?= h($fromItemTmp['unit_cost'] ?? '') ?>">
                                        </div>
                                        <div class="field">
                                            <label>Part no</label>
                                            <input type="text" name="part_no" maxlength="64"
                                                   value="<?= h($fromItemTmp['part_no'] ?? '') ?>">
                                        </div>
                                        <div class="field">
                                            <label>Part rev no</label>
                                            <input type="text" name="part_rev_no" maxlength="32"
                                                   value="<?= h($fromItemTmp['part_rev_no'] ?? '') ?>">
                                        </div>
                                        <div class="field">
                                            <label>Drawing no</label>
                                            <input type="text" name="dwg_no" maxlength="64"
                                                   value="<?= h($fromItemTmp['dwg_no'] ?? '') ?>">
                                        </div>
                                        <div class="field">
                                            <label>Dwg rev no</label>
                                            <input type="text" name="dwg_rev_no" maxlength="32"
                                                   value="<?= h($fromItemTmp['dwg_rev_no'] ?? '') ?>">
                                        </div>
                                        <div class="field span-2">
                                            <label>Short description</label>
                                            <input type="text" name="short_description" maxlength="255"
                                                   value="<?= h($fromItemTmp['short_description'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-primary btn-sm">+ Create successor</button>
                                    </div>
                                </form>
                            </details>
                        <?php else: ?>
                            <p class="muted">Pick a "From item" first, then return here to create a successor.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- BOM SWEEP PANEL (material_sub, status=approved) -->
            <?php if ($ecn['ecn_type'] === 'material_sub'
                     && $ecn['status'] === 'approved'
                     && empty($ecn['bom_sweep_completed_at'])
                     && permission_check('ecn', 'create')):
                $bomCandidates = ecn_bom_sweep_candidates((int)$ecn['id']);
            ?>
                <div class="card" style="margin-bottom: 18px; border-left: 3px solid var(--warning, #d97706);">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">BOM sweep — required before Make Effective</h3></div>
                    <div class="card-body">
                        <?php if (empty($bomCandidates)): ?>
                            <p class="muted">No BOMs reference the predecessor item. You can skip the sweep.</p>
                            <form method="post" action="<?= h(url('/ecn.php?action=skip_bom_sweep&id=' . (int)$ecn['id'])) ?>">
                                <?= csrf_field() ?>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">Skip BOM sweep</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="muted">
                                Tick which BOMs to rewrite. The predecessor item will be replaced by the successor on each ticked line. Edit qty if the conversion ratio differs.
                            </p>
                            <form method="post" action="<?= h(url('/ecn.php?action=apply_bom_sweep&id=' . (int)$ecn['id'])) ?>">
                                <?= csrf_field() ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;"><input type="checkbox" id="bom-sweep-all"></th>
                                            <th>Parent</th>
                                            <th>Ref</th>
                                            <th>Current qty</th>
                                            <th>New qty</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bomCandidates as $b): ?>
                                            <tr>
                                                <td><input type="checkbox" name="rewrite[<?= (int)$b['bom_line_id'] ?>]" value="1" class="bom-sweep-check"></td>
                                                <td>
                                                    <strong><?= h($b['parent_code']) ?></strong>
                                                    <br><span class="muted small"><?= h($b['parent_name']) ?></span>
                                                </td>
                                                <td><?= h($b['ref_designator'] ?: '—') ?></td>
                                                <td><?= h(rtrim(rtrim((string)$b['qty'],'0'),'.')) ?></td>
                                                <td><input type="number" step="0.001" min="0"
                                                           name="qty[<?= (int)$b['bom_line_id'] ?>]"
                                                           value="<?= h($b['qty']) ?>"
                                                           style="width: 100px;"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Rewrite selected BOMs</button>
                                    <button type="submit" formaction="<?= h(url('/ecn.php?action=skip_bom_sweep&id=' . (int)$ecn['id'])) ?>"
                                            class="btn btn-ghost"
                                            onclick="return confirm('Skip the BOM sweep entirely? No BOM lines will be rewritten.')">
                                        Skip sweep
                                    </button>
                                </div>
                                <script>
                                (function () {
                                    var all = document.getElementById('bom-sweep-all');
                                    if (!all) return;
                                    all.addEventListener('change', function () {
                                        document.querySelectorAll('.bom-sweep-check').forEach(function (c) { c.checked = all.checked; });
                                    });
                                })();
                                </script>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($ecn['ecn_type'] === 'material_sub' && $ecn['bom_sweep_completed_at']): ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">BOM sweep</h3></div>
                    <div class="card-body">
                        <p class="muted small">✓ Completed on <?= h(dt_display($ecn['bom_sweep_completed_at'])) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CASCADE: linked drawing_rev ECNs (post-Effective, opt-in) -->
            <?php if (!empty($ecn['also_revise_drawings'])):
                $linkedDocs = ecn_linked_docs((int)$ecn['id']);
                $isPostEff = in_array($ecn['status'], ['effective', 'closed'], true);
            ?>
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Linked drawings <span class="muted small">(<?= count($linkedDocs) ?>)</span></h3></div>
                    <div class="card-body">
                        <?php if (empty($linkedDocs)): ?>
                            <p class="muted">No documents are linked to the affected items (via doc Linked Entities). Nothing to cascade.</p>
                        <?php else: ?>
                            <table class="data-table" style="margin-bottom: 12px;">
                                <thead><tr><th>Doc</th><th>Title</th><th>Current rev</th></tr></thead>
                                <tbody>
                                    <?php foreach ($linkedDocs as $d): ?>
                                        <tr>
                                            <td><a href="<?= h(url('/documents.php?action=view&id=' . (int)$d['id'])) ?>"><?= h($d['code']) ?></a></td>
                                            <td><?= h($d['title']) ?></td>
                                            <td><?= h($d['current_rev_label'] ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (!$isPostEff): ?>
                                <p class="muted small">Once this ECN is Effective, a button will appear here to auto-create Draft <code>drawing_rev</code> ECNs for each linked doc.</p>
                            <?php elseif (!empty($ecn['drawings_drafted_at'])): ?>
                                <p class="muted small">✓ Linked drawing_rev ECNs were created on <?= h(dt_display($ecn['drawings_drafted_at'])) ?>. See History for the resulting ECN numbers.</p>
                            <?php else: ?>
                                <form method="post" action="<?= h(url('/ecn.php?action=create_linked_drawings&id=' . (int)$ecn['id'])) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-primary">
                                        Create <?= count($linkedDocs) ?> linked drawing_rev ECN<?= count($linkedDocs) === 1 ? '' : 's' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- HISTORY -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">History</h3></div>
                <div class="card-body">
                    <?php if (empty($history)): ?>
                        <p class="muted">No history yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Comment</th></tr></thead>
                            <tbody>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td><span class="muted small"><?= h(dt_display($h['created_at'])) ?></span></td>
                                        <td><?= h(str_replace('_', ' ', $h['event'])) ?></td>
                                        <td><?= h($h['actor_name'] ?: '—') ?></td>
                                        <td><?= h($h['comment'] ?: '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php
}

// =============================================================
// Render the type-specific details panel on the view page.
// =============================================================
function render_ecn_details_panel($ecn, $details)
{
    switch ($ecn['ecn_type']) {
        case 'drawing_rev':
            echo '<table class="data-table"><tbody>';
            echo '<tr><th>Document</th><td>';
            if ($ecn['pending_doc_id']) {
                echo '<a href="' . h(url('/documents.php?action=view&id=' . (int)$ecn['pending_doc_id'])) . '">'
                   . h($ecn['pending_doc_code']) . ' — ' . h($ecn['pending_doc_title']) . '</a>';
            } else echo '—';
            echo '</td></tr>';
            echo '<tr><th>New rev label</th><td><strong>' . h($ecn['pending_rev_label'] ?: '—') . '</strong></td></tr>';
            echo '<tr><th>File</th><td>';
            if ($ecn['pending_file_name']) {
                echo h($ecn['pending_file_name']) . ' <span class="muted small">(' . number_format((int)$ecn['pending_file_size']) . ' bytes)</span>';
            } else echo '<span class="muted">No file uploaded yet</span>';
            echo '</td></tr>';
            if (!empty($details['change_summary'])) {
                echo '<tr><th>Change summary</th><td>' . nl2br(h($details['change_summary'])) . '</td></tr>';
            }
            echo '</tbody></table>';
            break;

        case 'bom_change':
            echo '<table class="data-table"><tbody>';
            echo '<tr><th>Parent item</th><td>';
            if (!empty($details['parent_item_id'])) {
                $p = db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['parent_item_id']]);
                echo $p ? h($p['code']) . ' — ' . h($p['name']) : '—';
            } else echo '—';
            echo '</td></tr>';
            echo '<tr><th>BOM edits</th><td>';
            if (!empty($details['edits']) && is_array($details['edits'])) {
                foreach ($details['edits'] as $e) {
                    if (isset($e['summary'])) echo nl2br(h($e['summary']));
                }
            } else echo '<span class="muted">—</span>';
            echo '</td></tr>';
            echo '</tbody></table>';
            break;

        case 'material_sub':
            $from = !empty($details['from_item_id']) ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['from_item_id']]) : null;
            $to   = !empty($details['to_item_id'])   ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['to_item_id']])   : null;
            echo '<table class="data-table"><tbody>';
            echo '<tr><th>From</th><td>' . ($from ? h($from['code']) . ' — ' . h($from['name']) : '—') . '</td></tr>';
            echo '<tr><th>To</th><td>'   . ($to   ? h($to['code'])   . ' — ' . h($to['name'])   : '—') . '</td></tr>';
            echo '</tbody></table>';
            break;

        case 'uom_change':
            $item = !empty($details['item_id']) ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['item_id']]) : null;
            echo '<table class="data-table"><tbody>';
            echo '<tr><th>Item</th><td>' . ($item ? h($item['code']) . ' — ' . h($item['name']) : '—') . '</td></tr>';
            echo '<tr><th>From UOM</th><td>' . h($details['from_uom'] ?? '—') . '</td></tr>';
            echo '<tr><th>To UOM</th><td>'   . h($details['to_uom']   ?? '—') . '</td></tr>';
            echo '<tr><th>Conversion factor</th><td>1 ' . h($details['from_uom'] ?? '') . ' = ' . h($details['conversion_factor'] ?? '?') . ' ' . h($details['to_uom'] ?? '') . '</td></tr>';
            echo '</tbody></table>';
            break;

        case 'vendor_change':
            $item = !empty($details['item_id']) ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['item_id']]) : null;
            $from = !empty($details['from_vendor_id']) ? db_one("SELECT name FROM vendors WHERE id = ?", [(int)$details['from_vendor_id']]) : null;
            $to   = !empty($details['to_vendor_id'])   ? db_one("SELECT name FROM vendors WHERE id = ?", [(int)$details['to_vendor_id']])   : null;
            echo '<table class="data-table"><tbody>';
            echo '<tr><th>Item</th><td>' . ($item ? h($item['code']) . ' — ' . h($item['name']) : '—') . '</td></tr>';
            echo '<tr><th>From vendor</th><td>' . ($from ? h($from['name']) : '—') . '</td></tr>';
            echo '<tr><th>To vendor</th><td>'   . ($to   ? h($to['name'])   : '—') . '</td></tr>';
            echo '</tbody></table>';
            break;

        case 'obsolescence':
            $item = !empty($details['item_id']) ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['item_id']]) : null;
            $sup  = !empty($details['supersede_to_item_id']) ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$details['supersede_to_item_id']]) : null;
            echo '<table class="data-table"><tbody>';
            echo '<tr><th>Item to obsolete</th><td>' . ($item ? h($item['code']) . ' — ' . h($item['name']) : '—') . '</td></tr>';
            echo '<tr><th>Superseded by</th><td>' . ($sup ? h($sup['code']) . ' — ' . h($sup['name']) : '<span class="muted">— none —</span>') . '</td></tr>';
            echo '</tbody></table>';
            break;

        default:
            echo '<p class="muted">No details</p>';
    }
}
