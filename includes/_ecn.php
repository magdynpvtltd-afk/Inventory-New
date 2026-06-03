<?php
/**
 * MagDyn — ECN helper module (Phase B rebuild)
 *
 * Inventory-focused Engineering Change Notices.
 *
 * Lifecycle:
 *   draft → submitted → in_review (any one slot starts reviewing)
 *           ↓
 *           approved (when ALL three slots have approved)
 *           ↓
 *           effective (operator promotes from approved)
 *           ↓
 *           closed
 *
 * Any slot rejecting at any point during in_review sends it BACK to
 * draft. All signoff rows are cleared on rejection; the rejection
 * reason is preserved on ecns.rejection_reason and as an ecn_history
 * row. The next submit starts a fresh signing cycle.
 *
 * DMS coupling:
 *   - When an ECN with ecn_type='drawing_rev' goes effective, the
 *     pending file uploaded on the ECN is materialized as a new
 *     doc_revisions row on pending_doc_id, with ecn_id set.
 *   - When DMS releases a Major rev, the documents.php transition
 *     handler auto-creates a Draft ECN of type 'drawing_rev' linked
 *     to that doc + the new rev label.
 *
 * Created: 2026-05-19 IST  (Phase B rebuild)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_codes.php';
require_once __DIR__ . '/_billing_products.php';   // billing_product_push_if_needed / deactivate_if_needed (called at the end of ecn_apply_inventory_effects)

// =============================================================
// TYPES
// =============================================================
function ecn_types()
{
    return [
        'item_change' => [
            'icon'  => "\xF0\x9F\x93\xA6",
            'label' => 'Inventory item change',
            'short' => 'Change to a part — bumps part_rev_no; needs a new part report',
            'fields' => ['item_id', 'new_part_rev_no', 'change_summary'],
        ],
        'drawing_rev' => [
            'icon'  => "\xF0\x9F\x93\x90",
            'label' => 'Drawing revision',
            'short' => 'New revision of an existing controlled drawing or spec',
            'fields' => ['document_id', 'new_rev_label', 'change_summary'],
        ],
        'bom_change' => [
            'icon'  => "\xF0\x9F\x94\xA9",
            'label' => 'BOM change',
            'short' => 'Parent product\'s bill of materials edited',
            'fields' => ['parent_item_id', 'edits'],
        ],
        'material_sub' => [
            'icon'  => "\xF0\x9F\x94\x84",
            'label' => 'Material substitution',
            'short' => 'One inventory item replaces another in one or more BOMs',
            'fields' => ['from_item_id', 'to_item_id'],
        ],
        'uom_change' => [
            'icon'  => "\xF0\x9F\x93\x8F",
            'label' => 'UOM / packaging change',
            'short' => 'Unit of measure or packaging configuration changed',
            'fields' => ['item_id', 'from_uom', 'to_uom', 'conversion_factor'],
        ],
        'vendor_change' => [
            'icon'  => "\xF0\x9F\x8F\xAA",
            'label' => 'Vendor change',
            'short' => 'Primary vendor swapped for an inventory item',
            'fields' => ['item_id', 'from_vendor_id', 'to_vendor_id'],
        ],
        'obsolescence' => [
            'icon'  => "\xE2\x9B\x94",
            'label' => 'Obsolescence / supersession',
            'short' => 'Item retired; optionally superseded by another',
            'fields' => ['item_id', 'supersede_to_item_id'],
        ],
    ];
}

function ecn_type_def($code)
{
    $types = ecn_types();
    return isset($types[$code]) ? $types[$code] : null;
}

// =============================================================
// STATUS PILLS
// =============================================================
function ecn_status_pill($status)
{
    $map = [
        'draft'     => ['class' => 'pill-draft',   'label' => 'Draft'],
        'submitted' => ['class' => 'pill-pending', 'label' => 'Submitted'],
        'in_review' => ['class' => 'pill-warning', 'label' => 'In Review'],
        'approved'  => ['class' => 'pill-info',    'label' => 'Approved'],
        'effective' => ['class' => 'pill-success', 'label' => 'Effective'],
        'closed'    => ['class' => 'pill-neutral', 'label' => 'Closed'],
        'cancelled' => ['class' => 'pill-neutral', 'label' => 'Cancelled'],
        'rejected'  => ['class' => 'pill-danger',  'label' => 'Rejected'],
    ];
    return isset($map[$status]) ? $map[$status] : ['class' => 'pill-neutral', 'label' => $status];
}

// =============================================================
// STATE MACHINE
// =============================================================
function ecn_allowed_transitions($from)
{
    switch ($from) {
        case 'draft':     return ['submitted', 'cancelled'];
        case 'submitted': return ['in_review', 'draft', 'cancelled'];
        case 'in_review': return ['approved', 'draft', 'cancelled'];
        case 'approved':  return ['effective', 'cancelled'];
        case 'effective': return ['closed'];
        default:          return [];
    }
}

function ecn_can_transition($from, $to)
{
    return in_array($to, ecn_allowed_transitions($from), true);
}

// =============================================================
// TYPE DETAILS encode / decode / validate
// =============================================================
function ecn_encode_details($arr)
{
    if (!is_array($arr)) $arr = [];
    return json_encode($arr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ecn_decode_details($json)
{
    if ($json === null || $json === '') return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function ecn_validate_details($type, $details)
{
    if (!is_array($details)) $details = [];
    $errors = [];
    switch ($type) {
        case 'item_change':
            if (empty($details['item_id']))          $errors[] = 'Inventory item is required.';
            if (empty($details['new_part_rev_no']))  $errors[] = 'New part revision is required.';
            break;
        case 'drawing_rev':
            if (empty($details['document_id']))    $errors[] = 'Document is required.';
            if (empty($details['new_rev_label']))  $errors[] = 'New revision label is required.';
            break;
        case 'bom_change':
            if (empty($details['parent_item_id'])) $errors[] = 'Parent item is required.';
            if (empty($details['edits']) || !is_array($details['edits'])) {
                $errors[] = 'At least one BOM edit is required.';
            }
            break;
        case 'material_sub':
            if (empty($details['from_item_id'])) $errors[] = '"From" item is required.';
            if (empty($details['to_item_id']))   $errors[] = '"To" item is required.';
            if (!empty($details['from_item_id']) && !empty($details['to_item_id'])
                && (int)$details['from_item_id'] === (int)$details['to_item_id']) {
                $errors[] = '"From" and "To" items must differ.';
            }
            break;
        case 'uom_change':
            if (empty($details['item_id']))    $errors[] = 'Item is required.';
            if (empty($details['from_uom']))   $errors[] = 'From UOM is required.';
            if (empty($details['to_uom']))     $errors[] = 'To UOM is required.';
            if (!isset($details['conversion_factor']) || (float)$details['conversion_factor'] <= 0) {
                $errors[] = 'Conversion factor must be a positive number.';
            }
            break;
        case 'vendor_change':
            if (empty($details['item_id']))      $errors[] = 'Item is required.';
            if (empty($details['to_vendor_id'])) $errors[] = 'New vendor is required.';
            break;
        case 'obsolescence':
            if (empty($details['item_id'])) $errors[] = 'Item to obsolete is required.';
            break;
        default:
            $errors[] = 'Unknown ECN type: ' . $type;
    }
    return $errors;
}

// =============================================================
// CODE GENERATION
// =============================================================
function ecn_next_no()
{
    return code_next('ecn');
}

// =============================================================
// SIGNOFF SLOTS
// =============================================================
function ecn_signoff_slots()
{
    return db_all(
        "SELECT s.code, s.name, s.sort_order, s.role_id, s.is_active,
                r.name AS role_name, r.code AS role_code
           FROM ecn_signoff_slots s
      LEFT JOIN roles r ON r.id = s.role_id
          WHERE s.is_active = 1
          ORDER BY s.sort_order, s.code"
    );
}

function ecn_signoff_slot_set_role($slotCode, $roleId)
{
    db_exec(
        "UPDATE ecn_signoff_slots SET role_id = ? WHERE code = ?",
        [$roleId ? (int)$roleId : null, $slotCode]
    );
}

function ecn_user_can_signoff_slot($userId, $slotCode)
{
    if (!$userId) return false;
    if (permission_check('ecn', 'manage')) return true;
    if (!permission_check('ecn', 'signoff')) return false;
    $slot = db_one(
        "SELECT role_id FROM ecn_signoff_slots WHERE code = ? AND is_active = 1",
        [$slotCode]
    );
    if (!$slot || !$slot['role_id']) return false;
    $has = db_val(
        "SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?",
        [(int)$userId, (int)$slot['role_id']]
    );
    return (bool)$has;
}

function ecn_signoff_state($ecnId)
{
    return db_all(
        "SELECT s.code        AS slot_code,
                s.name        AS slot_name,
                s.sort_order,
                s.role_id,
                r.name        AS role_name,
                COALESCE(sg.decision, 'pending')  AS decision,
                sg.decided_at,
                sg.comment,
                u.full_name   AS decided_by_name
           FROM ecn_signoff_slots s
      LEFT JOIN roles r ON r.id = s.role_id
      LEFT JOIN ecn_signoffs sg ON sg.slot_code = s.code AND sg.ecn_id = ?
      LEFT JOIN users u ON u.id = sg.decided_by
          WHERE s.is_active = 1
          ORDER BY s.sort_order",
        [(int)$ecnId]
    );
}

// =============================================================
// HISTORY
// =============================================================
function ecn_history_append($ecnId, $event, $fromStatus = null, $toStatus = null,
                            $relatedId = null, $comment = null, $actorId = null)
{
    db_exec(
        "INSERT INTO ecn_history (ecn_id, event, from_status, to_status, related_id, comment, actor_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [(int)$ecnId, $event, $fromStatus, $toStatus,
         $relatedId ? (int)$relatedId : null, $comment,
         $actorId ? (int)$actorId : null]
    );
}

// =============================================================
// LIFECYCLE
// =============================================================
function ecn_submit($ecnId, $actorId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if (!ecn_can_transition($ecn['status'], 'submitted')) {
        throw new RuntimeException("ECN cannot be submitted from status " . $ecn['status']);
    }
    $details = ecn_decode_details($ecn['type_details']);
    $errors = ecn_validate_details($ecn['ecn_type'], $details);
    if ($errors) {
        throw new RuntimeException("Cannot submit — " . implode(' ', $errors));
    }
    if ($ecn['ecn_type'] === 'drawing_rev' && empty($ecn['pending_file_path'])) {
        throw new RuntimeException("Cannot submit — a file is required for drawing revisions.");
    }

    db_exec("UPDATE ecns SET status = 'submitted', submitted_at = NOW() WHERE id = ?", [(int)$ecnId]);
    db_exec("DELETE FROM ecn_signoffs WHERE ecn_id = ?", [(int)$ecnId]);
    db_exec(
        "INSERT INTO ecn_signoffs (ecn_id, slot_code, decision)
         SELECT ?, s.code, 'pending' FROM ecn_signoff_slots s WHERE s.is_active = 1",
        [(int)$ecnId]
    );
    db_exec("UPDATE ecns SET rejection_reason = NULL WHERE id = ?", [(int)$ecnId]);
    ecn_history_append($ecnId, 'submitted', $ecn['status'], 'submitted', null, null, $actorId);
}

function ecn_record_signoff($ecnId, $slotCode, $decision, $actorId, $comment = null)
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new RuntimeException("Invalid decision: $decision");
    }
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if (!in_array($ecn['status'], ['submitted', 'in_review'], true)) {
        throw new RuntimeException("ECN is not currently in a review phase (status: " . $ecn['status'] . ")");
    }
    if (!ecn_user_can_signoff_slot($actorId, $slotCode)) {
        throw new RuntimeException("You don't have permission to sign off on the $slotCode slot.");
    }

    db_exec(
        "INSERT INTO ecn_signoffs (ecn_id, slot_code, decision, decided_by, decided_at, comment)
              VALUES (?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE decision = VALUES(decision), decided_by = VALUES(decided_by),
                                 decided_at = NOW(), comment = VALUES(comment)",
        [(int)$ecnId, $slotCode, $decision, (int)$actorId, $comment]
    );

    if ($decision === 'rejected') {
        db_exec(
            "UPDATE ecns SET status = 'draft', rejection_reason = ? WHERE id = ?",
            [($comment ?: 'Rejected by ' . $slotCode), (int)$ecnId]
        );
        db_exec("DELETE FROM ecn_signoffs WHERE ecn_id = ?", [(int)$ecnId]);
        ecn_history_append($ecnId, 'signoff_rejected', $ecn['status'], 'draft', null,
            "Rejected by $slotCode" . ($comment ? ": $comment" : ''), $actorId);
        ecn_history_append($ecnId, 'rejected',         $ecn['status'], 'draft', null,
            ($comment ?: "Rejected by $slotCode"), $actorId);
        return ['status' => 'draft'];
    }

    ecn_history_append($ecnId, 'signoff_approved', null, null, null,
        "Approved by $slotCode" . ($comment ? ": $comment" : ''), $actorId);

    if ($ecn['status'] === 'submitted') {
        db_exec("UPDATE ecns SET status = 'in_review' WHERE id = ?", [(int)$ecnId]);
        ecn_history_append($ecnId, 'edited', 'submitted', 'in_review', null,
            "Entered review (first signoff received)", $actorId);
    }

    $pending = (int)db_val(
        "SELECT COUNT(*) FROM ecn_signoff_slots s
       LEFT JOIN ecn_signoffs sg ON sg.slot_code = s.code AND sg.ecn_id = ?
           WHERE s.is_active = 1 AND (sg.decision IS NULL OR sg.decision <> 'approved')",
        [(int)$ecnId]
    );
    if ($pending === 0) {
        db_exec("UPDATE ecns SET status = 'approved', approved_at = NOW() WHERE id = ?", [(int)$ecnId]);
        ecn_history_append($ecnId, 'approved', 'in_review', 'approved', null,
            "All slots approved", $actorId);
        return ['status' => 'approved'];
    }
    return ['status' => 'in_review'];
}

function ecn_make_effective($ecnId, $actorId, $effectiveDateOverride = null)
{
    require_once __DIR__ . '/_dms.php';
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if (!ecn_can_transition($ecn['status'], 'effective')) {
        throw new RuntimeException("ECN cannot be made effective from status " . $ecn['status']);
    }
    $effDate = $effectiveDateOverride ?: $ecn['effective_date'];
    if ($ecn['effectivity_mode'] === 'date' && !$effDate) {
        throw new RuntimeException("Effective date is required for date-based effectivity.");
    }

    // Phase B-2: material_sub blocks on BOM sweep completion
    if ($ecn['ecn_type'] === 'material_sub'
        && !empty($ecn['bom_sweep_required'])
        && empty($ecn['bom_sweep_completed_at'])) {
        throw new RuntimeException(
            "BOM sweep must be completed (or explicitly skipped) before this ECN can be made Effective. " .
            "See the BOM sweep panel on the ECN view."
        );
    }
    // material_sub also requires a successor item
    if ($ecn['ecn_type'] === 'material_sub' && empty($ecn['successor_item_id'])) {
        $details = ecn_decode_details($ecn['type_details']);
        if (empty($details['to_item_id'])) {
            throw new RuntimeException(
                "Pick an existing 'To item' OR click 'Create successor item' before making this ECN Effective."
            );
        }
    }

    $createdRevId = null;
    if (($ecn['ecn_type'] === 'drawing_rev' || $ecn['ecn_type'] === 'item_change')
        && $ecn['pending_doc_id'] && $ecn['pending_file_path']) {
        // For drawing_rev: pending_doc_id is the controlled drawing/spec.
        // For item_change: pending_doc_id is the item's Part Report
        // document. Both stage their uploaded file in pending_file_* and
        // the new revision label in pending_rev_label, so the same
        // materialization applies — create (or reuse) the revision and
        // point the document's current rev at it.
        $pendingDocId = (int)$ecn['pending_doc_id'];
        $pendingLabel = (string)$ecn['pending_rev_label'];

        // If a revision with this label already exists on the document,
        // don't try to create a duplicate (doc_add_revision enforces a
        // unique label per document and would throw). Instead reuse the
        // existing revision: point the document's current rev at it and
        // associate it with this ECN. This makes the ECN effective even
        // when the revision is already on file.
        $dupRev = db_one(
            "SELECT id FROM doc_revisions
              WHERE document_id = ? AND LOWER(rev_label) = LOWER(?)
              LIMIT 1",
            [$pendingDocId, $pendingLabel]
        );
        if ($dupRev) {
            $createdRevId = (int)$dupRev['id'];
            doc_set_current_rev($pendingDocId, $createdRevId);
            db_exec("UPDATE doc_revisions SET ecn_id = ? WHERE id = ?",
                [(int)$ecnId, (int)$createdRevId]);
            ecn_history_append($ecnId, 'edited', null, null, $createdRevId,
                "Rev " . $pendingLabel . " already existed on the document; "
                . "linked the existing revision instead of creating a duplicate.",
                $actorId);
        } else {
            $fileMeta = [
                'name' => $ecn['pending_file_name'],
                'path' => $ecn['pending_file_path'],
                'size' => $ecn['pending_file_size'],
                'mime' => $ecn['pending_file_mime'],
                'hash' => $ecn['pending_file_hash'],
            ];
            $changeNote = "Created by ECN " . $ecn['ecn_no'] .
                          ($ecn['description'] ? ": " . $ecn['description'] : '');
            $createdRevId = doc_add_revision(
                $pendingDocId,
                $pendingLabel,
                'release',
                $fileMeta,
                $changeNote,
                (int)$actorId
            );
            doc_set_current_rev($pendingDocId, $createdRevId);
            db_exec("UPDATE doc_revisions SET ecn_id = ? WHERE id = ?",
                [(int)$ecnId, (int)$createdRevId]);
        }
    }

    db_exec(
        "UPDATE ecns SET status = 'effective', effective_at = NOW(),
                        effective_date = COALESCE(?, effective_date)
         WHERE id = ?",
        [$effDate, (int)$ecnId]
    );
    ecn_history_append($ecnId, 'effective', $ecn['status'], 'effective',
        $createdRevId, "ECN made effective" .
        ($createdRevId ? " (doc rev #$createdRevId created)" : ''), $actorId);
    if ($createdRevId) {
        ecn_history_append($ecnId, 'doc_rev_created', null, null, $createdRevId,
            "Doc rev created with label " . $ecn['pending_rev_label'], $actorId);
    }

    // Phase B-1 side-effect engine: apply inv_items changes AFTER the
    // status flip + DMS rev creation. Errors here are caught so the
    // ECN stays effective even if a side-effect fails; the failure is
    // surfaced in the flash and history.
    $appliedEffects = [];
    try {
        $appliedEffects = ecn_apply_inventory_effects($ecnId, $actorId);
    } catch (Exception $e) {
        ecn_history_append($ecnId, 'edited', null, null, null,
            "Inventory side-effects failed: " . $e->getMessage(), $actorId);
    }
    return ['rev_id' => $createdRevId, 'effects' => $appliedEffects];
}

function ecn_close($ecnId, $actorId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if (!ecn_can_transition($ecn['status'], 'closed')) {
        throw new RuntimeException("ECN cannot be closed from status " . $ecn['status']);
    }
    db_exec("UPDATE ecns SET status = 'closed', closed_at = NOW() WHERE id = ?", [(int)$ecnId]);
    ecn_history_append($ecnId, 'closed', $ecn['status'], 'closed', null, null, $actorId);
}

function ecn_cancel($ecnId, $actorId, $reason = null)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    // Hard block: once an ECN is effective, inventory side-effects have
    // already applied. Cancellation would leave those changes orphaned.
    // Operator must raise a NEW ECN to undo.
    if (in_array($ecn['status'], ['effective', 'closed'], true)) {
        throw new RuntimeException(
            "Cannot cancel an ECN that has already gone Effective. " .
            "Inventory side-effects (rev_label, UOM, vendor changes, posted txns) " .
            "have already been applied. To reverse, raise a new ECN."
        );
    }
    if (!ecn_can_transition($ecn['status'], 'cancelled')) {
        throw new RuntimeException("ECN cannot be cancelled from status " . $ecn['status']);
    }
    db_exec(
        "UPDATE ecns SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE id = ?",
        [$reason, (int)$ecnId]
    );
    db_exec("DELETE FROM ecn_signoffs WHERE ecn_id = ?", [(int)$ecnId]);
    ecn_history_append($ecnId, 'cancelled', $ecn['status'], 'cancelled', null, $reason, $actorId);
}

// =============================================================
// FILE STORAGE for drawing_rev pending file
// =============================================================
function ecn_store_pending_file($fileSlot, $ecnId)
{
    if (!is_array($fileSlot) || ($fileSlot['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload failed (error " . ($fileSlot['error'] ?? '?') . ").");
    }
    $dir = __DIR__ . '/../uploads/ecn/' . (int)$ecnId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create upload directory.");
    }
    $orig = $fileSlot['name'];
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
    $dest = $dir . '/pending_' . time() . '_' . $safe;
    if (!move_uploaded_file($fileSlot['tmp_name'], $dest)) {
        throw new RuntimeException("Could not move uploaded file.");
    }
    $rel = 'uploads/ecn/' . (int)$ecnId . '/' . basename($dest);
    return [
        'name' => $orig,
        'path' => $rel,
        'size' => (int)$fileSlot['size'],
        'mime' => $fileSlot['type'] ?: 'application/octet-stream',
        'hash' => @hash_file('sha256', $dest) ?: null,
    ];
}

// =============================================================
// ITEM PART REPORT — find-or-create the Part Report document for an
// inventory item. Every item is expected to have one; the item_change
// ECN flow uploads a new revision of it (auto-creating the document if
// the item doesn't have one yet).
// =============================================================

/**
 * Resolve the 'part_report' external doc category id (seeded by
 * migration 20260528_162228). Returns id or null.
 */
function ecn_part_report_category_id()
{
    $r = db_one("SELECT id FROM doc_categories WHERE code = 'part_report' AND kind = 'external' AND is_active = 1 LIMIT 1");
    return $r ? (int)$r['id'] : null;
}

/**
 * Find the Part Report document linked to an inventory item. Returns the
 * documents row or null. "Linked" = a doc_entity_links row of
 * entity_type='inv_item' to a document in the part_report category.
 */
function ecn_item_part_report($itemId)
{
    $catId = ecn_part_report_category_id();
    if (!$catId) return null;
    return db_one(
        "SELECT d.*
           FROM doc_entity_links l
           JOIN documents d ON d.id = l.document_id AND d.deleted_at IS NULL
          WHERE l.entity_type = 'inv_item' AND l.entity_id = ?
            AND d.category_id = ?
          ORDER BY d.id DESC
          LIMIT 1",
        [(int)$itemId, $catId]
    );
}

/**
 * Ensure an inventory item has a Part Report document. If one already
 * exists (linked, part_report category), returns its id. Otherwise
 * creates a new external document in the part_report category, links it
 * to the item, and returns the new id. The document is created WITHOUT a
 * revision/file — the item_change ECN supplies the file as the first/next
 * revision when it goes Effective. Returns document id, or null if the
 * part_report category is missing.
 */
function ecn_ensure_item_part_report($itemId, $actorId)
{
    require_once __DIR__ . '/_dms.php';
    $existing = ecn_item_part_report($itemId);
    if ($existing) return (int)$existing['id'];

    $catId = ecn_part_report_category_id();
    if (!$catId) return null;

    $item = db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$itemId]);
    $code  = function_exists('doc_next_code') ? doc_next_code($catId) : ('PR-' . date('YmdHis'));
    $title = 'Part Report — ' . ($item['code'] ?? ('item #' . (int)$itemId))
           . ($item && $item['name'] ? ' (' . $item['name'] . ')' : '');
    // doc_no mirrors the item code so the importer's doc matching and the
    // DMS lookups can find it predictably.
    $docNo = $item['code'] ?? null;

    db_exec(
        "INSERT INTO documents
            (code, doc_no, title, category_id, kind, status, owner_id,
             description, created_by, updated_by)
         VALUES (?, ?, ?, ?, 'external', 'received', ?, ?, ?, ?)",
        [$code, $docNo, $title, $catId, (int)$actorId,
         'Auto-created part report for inventory item.', (int)$actorId, (int)$actorId]
    );
    $docId = (int)db_val('SELECT LAST_INSERT_ID()');
    doc_history_append($docId, 'created', null, 'received', null,
        "Auto-created part report for inventory item #" . (int)$itemId, $actorId);
    doc_link_entity($docId, 'inv_item', (int)$itemId,
        'Part report for this item', $actorId);
    return $docId;
}

// =============================================================
// AUTO-DRAFT from DMS Major-rev hook
// =============================================================
function ecn_auto_draft_for_major_rev($documentId, $newRevLabel, $actorId)
{
    $doc = db_one("SELECT * FROM documents WHERE id = ?", [(int)$documentId]);
    if (!$doc) throw new RuntimeException("Document not found.");

    $code = ecn_next_no();
    $title = "Drawing revision: " . $doc['code'] . " → " . $newRevLabel;
    $details = ecn_encode_details([
        'document_id'    => (int)$documentId,
        'new_rev_label'  => $newRevLabel,
        'change_summary' => null,
    ]);

    db_exec(
        "INSERT INTO ecns (ecn_no, title, ecn_type, status, originator_id,
                           type_details, pending_doc_id, pending_rev_label,
                           effectivity_mode, effective_date)
         VALUES (?, ?, 'drawing_rev', 'draft', ?, ?, ?, ?, 'date', NULL)",
        [$code, $title, (int)$actorId, $details, (int)$documentId, $newRevLabel]
    );
    $ecnId = (int)db_val('SELECT LAST_INSERT_ID()');
    ecn_history_append($ecnId, 'auto_drafted', null, 'draft', (int)$documentId,
        "Auto-drafted by DMS Major rev on " . $doc['code'], $actorId);
    return $ecnId;
}

// =============================================================
// REVERSE LOOKUP / FORWARD: doc revs an ECN created
// =============================================================
function ecn_for_doc_rev($revId)
{
    return db_one(
        "SELECT e.id, e.ecn_no, e.status, e.title
           FROM doc_revisions r
           JOIN ecns e ON e.id = r.ecn_id
          WHERE r.id = ?",
        [(int)$revId]
    );
}

function ecn_doc_revs($ecnId)
{
    return db_all(
        "SELECT r.id, r.rev_label, r.stage, r.created_at, r.file_name,
                r.document_id, d.code AS doc_code, d.title AS doc_title
           FROM doc_revisions r
           JOIN documents d ON d.id = r.document_id
          WHERE r.ecn_id = ?
          ORDER BY r.created_at",
        [(int)$ecnId]
    );
}

// =============================================================
// AFFECTED ITEMS
// =============================================================
function ecn_affected_items($ecnId)
{
    return db_all(
        "SELECT ai.id, ai.item_id, ai.note, ai.created_at,
                i.code AS item_code, i.name AS item_name
           FROM ecn_affected_items ai
           JOIN inv_items i ON i.id = ai.item_id
          WHERE ai.ecn_id = ?
          ORDER BY ai.created_at",
        [(int)$ecnId]
    );
}

function ecn_add_affected_item($ecnId, $itemId, $note = null)
{
    db_exec(
        "INSERT IGNORE INTO ecn_affected_items (ecn_id, item_id, note) VALUES (?, ?, ?)",
        [(int)$ecnId, (int)$itemId, $note]
    );
}

function ecn_remove_affected_item($ecnId, $itemId)
{
    db_exec(
        "DELETE FROM ecn_affected_items WHERE ecn_id = ? AND item_id = ?",
        [(int)$ecnId, (int)$itemId]
    );
}

// =============================================================
// INVENTORY SIDE-EFFECTS (Phase B-1)
//
// Triggered from ecn_make_effective() AFTER the ECN status flip and
// (for drawing_rev) AFTER the DMS rev is materialized. These functions
// apply changes to inv_items / inv_txns based on the ECN type. Each
// returns an array of [type=>...,  summary=>'human-readable'] entries
// so ecn_history can record what happened and the operator gets clear
// feedback.
//
// Types covered in Phase B-1:
//   - drawing_rev   → bump inv_items.rev_label on every affected item
//                     to match the new doc rev label.
//   - vendor_change → set inv_items.primary_vendor_id on the affected
//                     item to to_vendor_id.
//   - uom_change    → update inv_items.uom and post inv_txn 'adjust'
//                     rows at every location with stock to convert
//                     stock_on_hand by conversion_factor.
//
// Types deferred to Phase B-2:
//   - material_sub  → operator-controlled BOM sweep + new-row creation
//   - obsolescence  → mark is_obsolete + record supersession
//   - bom_change    → BOM edits (the heavy structured part)
// =============================================================

/**
 * Preview what the inventory side-effects WOULD be if this ECN were
 * made effective right now. Used to populate the "Inventory side-
 * effects" panel on the ECN view BEFORE Effective.
 *
 * Returns array of preview rows: [
 *   ['kind' => 'rev_bump'|'vendor_set'|'uom_change'|'noop', 'item_code' => ..., 'item_name' => ..., 'detail' => '...'],
 *   ...
 * ]
 */
function ecn_preview_inventory_effects($ecnId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) return [];
    $details = ecn_decode_details($ecn['type_details']);
    $affected = ecn_affected_items((int)$ecnId);
    $preview = [];

    switch ($ecn['ecn_type']) {
        case 'item_change':
            $newPartRev = $ecn['pending_rev_label'] ?: ($details['new_part_rev_no'] ?? '?');
            $ids = array_unique(array_filter(array_merge(
                [(int)($details['item_id'] ?? 0)],
                array_column($affected, 'item_id')
            )));
            foreach ($ids as $iid) {
                if ($iid <= 0) continue;
                $ic = db_one("SELECT code, name, part_rev_no FROM inv_items WHERE id = ?", [$iid]);
                $preview[] = [
                    'kind'      => 'part_rev_bump',
                    'item_code' => $ic['code'] ?? "#$iid",
                    'item_name' => $ic['name'] ?? '',
                    'detail'    => 'part_rev_no ' . ($ic['part_rev_no'] ?: '—') . ' → ' . $newPartRev,
                ];
            }
            if (empty($ids)) {
                $preview[] = ['kind' => 'noop', 'item_code' => '', 'item_name' => '',
                    'detail' => 'No item linked — nothing to change.'];
            }
            break;

        case 'drawing_rev':

        case 'vendor_change':
            $toVendorId = (int)($details['to_vendor_id'] ?? 0);
            $toVendor = $toVendorId ? db_one("SELECT name FROM vendors WHERE id = ?", [$toVendorId]) : null;
            $vname = $toVendor ? $toVendor['name'] : '?';
            $itemIds = array_unique(array_filter(array_merge(
                [(int)($details['item_id'] ?? 0)],
                array_column($affected, 'item_id')
            )));
            foreach ($itemIds as $iid) {
                $i = db_one("SELECT code, name FROM inv_items WHERE id = ?", [(int)$iid]);
                if ($i) $preview[] = [
                    'kind' => 'vendor_set',
                    'item_code' => $i['code'],
                    'item_name' => $i['name'],
                    'detail' => 'primary_vendor → ' . $vname,
                ];
            }
            break;

        case 'uom_change':
            $itemId = (int)($details['item_id'] ?? 0);
            $factor = (float)($details['conversion_factor'] ?? 0);
            $newUom = $details['to_uom'] ?? '?';
            if ($itemId) {
                $i = db_one("SELECT code, name, uom, stock_on_hand FROM inv_items WHERE id = ?", [$itemId]);
                if ($i) {
                    $newStock = (float)$i['stock_on_hand'] * $factor;
                    $preview[] = [
                        'kind' => 'uom_change',
                        'item_code' => $i['code'],
                        'item_name' => $i['name'],
                        'detail' => 'uom: ' . $i['uom'] . ' → ' . $newUom .
                                    '; stock: ' . rtrim(rtrim((string)$i['stock_on_hand'],'0'),'.') .
                                    ' → ' . rtrim(rtrim(sprintf('%.3f', $newStock),'0'),'.') .
                                    ' (×' . $factor . ' at each location)',
                    ];
                }
            }
            break;

        case 'material_sub':
            $fromItemId = (int)($details['from_item_id'] ?? 0);
            $ecnFresh = db_one("SELECT successor_item_id FROM ecns WHERE id = ?", [(int)$ecnId]);
            $toItemId = (int)($ecnFresh['successor_item_id'] ?? 0) ?: (int)($details['to_item_id'] ?? 0);
            $from = $fromItemId ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [$fromItemId]) : null;
            $to   = $toItemId   ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [$toItemId]) : null;
            if ($from) {
                $preview[] = [
                    'kind' => 'obsolete',
                    'item_code' => $from['code'],
                    'item_name' => $from['name'],
                    'detail' => 'Will be marked obsolete' . ($to ? ' (superseded by ' . $to['code'] . ')' : ''),
                ];
            }
            if ($to) {
                $preview[] = [
                    'kind' => 'activate',
                    'item_code' => $to['code'],
                    'item_name' => $to['name'],
                    'detail' => 'Will be activated as successor',
                ];
            } else {
                $preview[] = ['kind' => 'noop', 'item_code' => '', 'item_name' => '',
                    'detail' => 'Successor item not yet selected — pick an existing item or click "Create successor item" before submitting.'];
            }
            break;

        case 'obsolescence':
            $itemId = (int)($details['item_id'] ?? 0);
            $supId  = (int)($details['supersede_to_item_id'] ?? 0);
            $i = $itemId ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [$itemId]) : null;
            $sup = $supId ? db_one("SELECT code, name FROM inv_items WHERE id = ?", [$supId]) : null;
            if ($i) {
                $preview[] = [
                    'kind' => 'obsolete',
                    'item_code' => $i['code'],
                    'item_name' => $i['name'],
                    'detail' => 'Will be marked obsolete' . ($sup ? ' (superseded by ' . $sup['code'] . ')' : ' (no successor)'),
                ];
            }
            break;

        case 'bom_change':
            $preview[] = ['kind' => 'deferred', 'item_code' => '', 'item_name' => '',
                'detail' => 'BOM change side-effects (structured edits) will be added in a later release. For now, the ECN records the change but does not auto-modify inv_bom_lines.'];
            break;
    }
    return $preview;
}

/**
 * APPLY inventory side-effects. Called from ecn_make_effective() AFTER
 * the status flip + DMS rev creation. Returns array of applied-event
 * descriptors that get logged into ecn_history.
 */
function ecn_apply_inventory_effects($ecnId, $actorId)
{
    require_once __DIR__ . '/_inventory_txn.php';
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) return [];
    $details = ecn_decode_details($ecn['type_details']);
    $affected = ecn_affected_items((int)$ecnId);
    $applied = [];

    switch ($ecn['ecn_type']) {
        case 'item_change':
            // The change point for an inventory item is part_rev_no. Bump
            // it to EXACTLY the operator-entered value (pending_rev_label /
            // new_part_rev_no). Never auto-generated. Applies to the subject
            // item plus any additional affected items. The part report doc
            // revision itself is materialized in ecn_make_effective (shared
            // pending-file block).
            $newPartRev = $ecn['pending_rev_label'] ?: ($details['new_part_rev_no'] ?? null);
            $newPartRev = is_string($newPartRev) ? trim($newPartRev) : $newPartRev;
            $targetIds = array_unique(array_filter(array_merge(
                [(int)($details['item_id'] ?? 0)],
                array_map(function ($a) { return (int)$a['item_id']; }, $affected)
            )));
            if ($newPartRev !== null && $newPartRev !== '') {
                foreach ($targetIds as $iid) {
                    if ($iid <= 0) continue;
                    db_exec("UPDATE inv_items SET part_rev_no = ? WHERE id = ?",
                        [$newPartRev, $iid]);
                    $ic = db_one("SELECT code FROM inv_items WHERE id = ?", [$iid]);
                    $applied[] = [
                        'event'   => 'inv_part_rev_bump',
                        'item_id' => $iid,
                        'summary' => "Set part_rev_no of " . ($ic['code'] ?? "#$iid") . " to " . $newPartRev,
                    ];
                }
            } elseif (!empty($targetIds)) {
                ecn_history_append($ecnId, 'edited', null, null, null,
                    "No new part revision provided; affected items' part_rev_no left unchanged.",
                    $actorId);
            }
            break;

        case 'drawing_rev':
            // Bump rev_label on every affected item to EXACTLY the rev the
            // operator entered on the ECN (pending_rev_label / new_rev_label).
            // It is never auto-generated. If the label is blank we do NOT
            // touch any item's rev (no silent default) and record why.
            $newRev = $ecn['pending_rev_label'] ?: ($details['new_rev_label'] ?? null);
            $newRev = is_string($newRev) ? trim($newRev) : $newRev;
            if ($newRev !== null && $newRev !== '') {
                foreach ($affected as $a) {
                    db_exec(
                        "UPDATE inv_items SET rev_label = ? WHERE id = ?",
                        [$newRev, (int)$a['item_id']]
                    );
                    $applied[] = [
                        'event'   => 'inv_rev_bump',
                        'item_id' => (int)$a['item_id'],
                        'summary' => "Set rev_label of " . $a['item_code'] . " to " . $newRev,
                    ];
                }
            } elseif (!empty($affected)) {
                // Affected items exist but no new revision was provided —
                // leave their revs untouched and log it instead of guessing.
                ecn_history_append($ecnId, 'edited', null, null, null,
                    "No new revision label provided; affected items' revisions left unchanged.",
                    $actorId);
            }
            break;

        case 'vendor_change':
            $toVendorId = (int)($details['to_vendor_id'] ?? 0);
            if ($toVendorId) {
                // Set primary_vendor on the type-details item AND every affected item
                $itemIds = array_unique(array_filter(array_merge(
                    [(int)($details['item_id'] ?? 0)],
                    array_column($affected, 'item_id')
                )));
                foreach ($itemIds as $iid) {
                    $iid = (int)$iid;
                    if ($iid <= 0) continue;
                    db_exec(
                        "UPDATE inv_items SET primary_vendor_id = ? WHERE id = ?",
                        [$toVendorId, $iid]
                    );
                    $i = db_one("SELECT code FROM inv_items WHERE id = ?", [$iid]);
                    $v = db_one("SELECT name FROM vendors WHERE id = ?", [$toVendorId]);
                    $applied[] = [
                        'event'   => 'inv_vendor_set',
                        'item_id' => $iid,
                        'summary' => "Set primary_vendor of " . ($i['code'] ?? "#$iid") .
                                     " to " . ($v['name'] ?? "#$toVendorId"),
                    ];
                }
            }
            break;

        case 'uom_change':
            $itemId = (int)($details['item_id'] ?? 0);
            $factor = (float)($details['conversion_factor'] ?? 0);
            $newUom = $details['to_uom'] ?? null;
            $effDate = $ecn['effective_date'] ?: date('Y-m-d');
            if ($itemId && $factor > 0 && $newUom) {
                // For each location with stock, post an 'adjust' for the qty difference
                $stockRows = db_all(
                    "SELECT location_id, qty FROM inv_item_location_stock
                      WHERE item_id = ? AND qty <> 0",
                    [$itemId]
                );
                foreach ($stockRows as $sr) {
                    $oldQty = (float)$sr['qty'];
                    $newQty = $oldQty * $factor;
                    $delta  = $newQty - $oldQty;
                    if (abs($delta) > 0.0001) {
                        inv_post_txn(
                            'adjust',
                            $effDate,
                            $itemId,
                            (int)$sr['location_id'],
                            $delta,
                            null,
                            'ECN ' . $ecn['ecn_no'],
                            'UOM conversion (×' . $factor . ') via ECN ' . $ecn['ecn_no']
                        );
                    }
                }
                // Update the item's UOM. inv_items has BOTH a legacy
                // free-text `uom` column AND a `uom_id` FK to inv_uom
                // (the real source of truth). Update both — uom_id if
                // we can resolve the to_uom string to an inv_uom row,
                // and the legacy column unconditionally so historical
                // queries still work.
                $newUomId = db_val(
                    "SELECT id FROM inv_uom WHERE code = ? OR label = ?",
                    [$newUom, $newUom]
                );
                if ($newUomId) {
                    db_exec(
                        "UPDATE inv_items SET uom = ?, uom_id = ? WHERE id = ?",
                        [$newUom, (int)$newUomId, $itemId]
                    );
                } else {
                    db_exec(
                        "UPDATE inv_items SET uom = ? WHERE id = ?",
                        [$newUom, $itemId]
                    );
                }
                $applied[] = [
                    'event'   => 'inv_uom_change',
                    'item_id' => $itemId,
                    'summary' => "Changed UOM of item #$itemId to $newUom"
                               . ($newUomId ? ' (linked inv_uom id ' . $newUomId . ')' : ' (legacy text only; no matching inv_uom row found)')
                               . '; posted ' . count($stockRows) . " adjust txn(s) (×$factor)",
                ];
            }
            break;

        case 'material_sub':
            // Mark predecessor obsolete + activate successor + record
            // supersede chain. The successor row was already created
            // pre-submit via ecn_create_successor_item; here we just
            // flip the flags and write the audit trail.
            $fromItemId = (int)($details['from_item_id'] ?? 0);
            $toItemId   = (int)$ecn['successor_item_id'] ?: (int)($details['to_item_id'] ?? 0);
            if ($fromItemId && $toItemId) {
                // Activate the successor (was inactive during review)
                db_exec(
                    "UPDATE inv_items SET is_active = 1 WHERE id = ?",
                    [$toItemId]
                );
                // Obsolete the predecessor
                db_exec(
                    "UPDATE inv_items SET is_obsolete = 1, is_active = 0, obsoleted_by_item_id = ?
                     WHERE id = ?",
                    [$toItemId, $fromItemId]
                );
                // Audit trail
                db_exec(
                    "INSERT INTO inv_supersede_chain (from_item_id, to_item_id, ecn_id, reason, created_by)
                     VALUES (?, ?, ?, 'material_sub', ?)",
                    [$fromItemId, $toItemId, (int)$ecnId, (int)$actorId]
                );
                $fromItem = db_one("SELECT code FROM inv_items WHERE id = ?", [$fromItemId]);
                $toItem   = db_one("SELECT code FROM inv_items WHERE id = ?", [$toItemId]);
                $applied[] = [
                    'event'   => 'inv_material_sub',
                    'item_id' => $toItemId,
                    'summary' => "Activated " . ($toItem['code'] ?? "#$toItemId") .
                                 "; obsoleted " . ($fromItem['code'] ?? "#$fromItemId") .
                                 " (superseded)",
                ];
            }
            break;

        case 'obsolescence':
            // Mark the item obsolete; if a supersede is specified,
            // also link it and write the chain row.
            $itemId = (int)($details['item_id'] ?? 0);
            $supersedeToId = (int)($details['supersede_to_item_id'] ?? 0);
            if ($itemId) {
                if ($supersedeToId) {
                    db_exec(
                        "UPDATE inv_items SET is_obsolete = 1, is_active = 0,
                                              obsoleted_by_item_id = ?
                         WHERE id = ?",
                        [$supersedeToId, $itemId]
                    );
                    db_exec(
                        "INSERT INTO inv_supersede_chain (from_item_id, to_item_id, ecn_id, reason, created_by)
                         VALUES (?, ?, ?, 'obsolescence', ?)",
                        [$itemId, $supersedeToId, (int)$ecnId, (int)$actorId]
                    );
                } else {
                    db_exec(
                        "UPDATE inv_items SET is_obsolete = 1, is_active = 0 WHERE id = ?",
                        [$itemId]
                    );
                }
                $i = db_one("SELECT code FROM inv_items WHERE id = ?", [$itemId]);
                $sup = $supersedeToId ? db_one("SELECT code FROM inv_items WHERE id = ?", [$supersedeToId]) : null;
                $applied[] = [
                    'event'   => 'inv_obsolescence',
                    'item_id' => $itemId,
                    'summary' => "Obsoleted " . ($i['code'] ?? "#$itemId") .
                                 ($sup ? " (superseded by " . $sup['code'] . ")" : ' (no successor)'),
                ];
            }
            break;
    }

    // Append all applied events to ecn_history
    foreach ($applied as $ev) {
        ecn_history_append($ecnId, 'edited', null, null, $ev['item_id'],
            $ev['summary'], $actorId);
    }

    // ---- Mirror changes to the billing-product catalogue ----
    // For each affected item, fire the appropriate hook:
    //   - obsolescence / material_sub of predecessor → deactivate (is_active flipped to 0)
    //   - everything else → push_if_needed (hash check decides if anything's actually sent)
    //
    // We re-fetch the item inside each helper so the post-UPDATE state
    // is what gets pushed/inspected, not stale.
    if (function_exists('billing_product_push_if_needed')) {
        $obsoletedIds = [];
        $touchedIds   = [];
        foreach ($applied as $ev) {
            $ev_type = (string)$ev['event'];
            $iid     = (int)$ev['item_id'];
            if ($iid <= 0) continue;
            if ($ev_type === 'inv_obsolescence') {
                // Predecessor went is_active 1 → 0 just now.
                $obsoletedIds[$iid] = true;
            } elseif ($ev_type === 'inv_material_sub') {
                // The applied row points to the SUCCESSOR (which was
                // activated 0 → 1) — push_if_needed will re-mirror it.
                $touchedIds[$iid] = true;
                // The predecessor is NOT in $applied for material_sub
                // events but we still need to deactivate it. Decode
                // from_item_id from the type_details we stored earlier.
                // Simpler: also collect any item whose is_active is now 0
                // and whose billing_product_id is set — see the second
                // pass below.
            } else {
                // part_rev_bump, rev_bump, vendor_set, uom_set, etc.
                $touchedIds[$iid] = true;
            }
        }
        // For material_sub, also deactivate the predecessor. We don't
        // have its id in $applied, but the UPDATE wrote is_active=0 on
        // it; we can find it from inv_supersede_chain rows just inserted
        // by this ecnId.
        try {
            $chainRows = db_all(
                "SELECT from_item_id FROM inv_supersede_chain WHERE ecn_id = ?",
                [(int)$ecnId]
            );
            foreach ($chainRows as $cr) {
                $obsoletedIds[(int)$cr['from_item_id']] = true;
            }
        } catch (\Throwable $e) {
            // table or column shape change — skip silently
        }

        foreach (array_keys($obsoletedIds) as $iid) {
            // is_active was 1 before our UPDATE; pass 1 as the "old" value.
            billing_product_deactivate_if_needed($iid, 1, $actorId);
        }
        foreach (array_keys($touchedIds) as $iid) {
            // Skip if we just deactivated it — deactivate already wrote
            // last_push state.
            if (isset($obsoletedIds[$iid])) continue;
            billing_product_push_if_needed($iid, $actorId);
        }
    }

    return $applied;
}


// =============================================================
// PHASE B-2 HELPERS
// =============================================================

/**
 * Generate a fresh inv_items code that mimics the predecessor's
 * prefix pattern. Splits the code at the last numeric run, increments,
 * keeps trying until the result is unique.
 *
 * Examples:
 *   I-00123   → I-00124
 *   PART-005  → PART-006
 *   P-00123-B → P-00123-C (alpha suffix bump if it ends in a letter)
 *   FOO       → FOO-2 (no numeric segment — append -2)
 */
function ecn_next_item_code($predecessorCode)
{
    $code = (string)$predecessorCode;
    // Strategy 1: find the LAST run of digits, increment it, keep trying
    if (preg_match('/^(.*?)(\d+)([^\d]*)$/', $code, $m)) {
        $prefix = $m[1];
        $num    = (int)$m[2];
        $padLen = strlen($m[2]);
        $suffix = $m[3];
        for ($i = 1; $i < 1000; $i++) {
            $candidate = $prefix . str_pad((string)($num + $i), $padLen, '0', STR_PAD_LEFT) . $suffix;
            $taken = db_val("SELECT id FROM inv_items WHERE code = ?", [$candidate]);
            if (!$taken) return $candidate;
        }
    }
    // Strategy 2: no numeric segment — append -N
    for ($i = 2; $i < 1000; $i++) {
        $candidate = $code . '-' . $i;
        $taken = db_val("SELECT id FROM inv_items WHERE code = ?", [$candidate]);
        if (!$taken) return $candidate;
    }
    throw new RuntimeException("Could not generate a unique code derived from $code.");
}

/**
 * Clone the predecessor inv_items row as a fresh "successor" record.
 * The new row starts is_active=0 (it shouldn't be selectable for new
 * stock txns until the ECN is Effective). is_obsolete=0 too — it's
 * a brand-new item, not retired.
 *
 * Caller provides $overrides — an array of column→value pairs to
 * override the cloned values (e.g. operator-edited name, uom_id).
 *
 * Returns the new inv_items.id.
 */
function ecn_create_successor_item($ecnId, $overrides, $actorId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if ($ecn['ecn_type'] !== 'material_sub') {
        throw new RuntimeException("Successor items are only valid for material_sub ECNs.");
    }
    if ($ecn['successor_item_id']) {
        throw new RuntimeException("This ECN already has a successor item.");
    }
    if (!in_array($ecn['status'], ['draft'], true)) {
        throw new RuntimeException("Successor item can only be created while the ECN is in Draft.");
    }
    $details = ecn_decode_details($ecn['type_details']);
    $fromItemId = (int)($details['from_item_id'] ?? 0);
    if (!$fromItemId) {
        throw new RuntimeException("Pick a 'From item' on the ECN before creating a successor.");
    }
    $from = db_one("SELECT * FROM inv_items WHERE id = ?", [$fromItemId]);
    if (!$from) throw new RuntimeException("From item not found.");

    // Generate code
    $newCode = isset($overrides['code']) && $overrides['code']
              ? trim((string)$overrides['code'])
              : ecn_next_item_code($from['code']);
    if (db_val("SELECT id FROM inv_items WHERE code = ?", [$newCode])) {
        throw new RuntimeException("Item code '$newCode' is already taken.");
    }

    // Resolve fields. Anything in $overrides wins over predecessor copy.
    $f = function ($col, $default) use ($overrides, $from) {
        if (array_key_exists($col, $overrides)) return $overrides[$col];
        return $from[$col] ?? $default;
    };

    db_exec(
        "INSERT INTO inv_items
            (code, name, short_description, long_description,
             category_id, division_id, manufacturer_type, uom_id, uom,
             dwg_no, dwg_rev_no, part_no, part_rev_no,
             process_spec, process_step_id, step_no,
             step_time_min, step_cost,
             min_stock_level, min_order_qty, min_sample_qty, min_sample_pct,
             material_spec, remarks, notes,
             unit_cost, stock_on_hand, is_product, is_active, is_obsolete,
             supersedes_item_id, primary_vendor_id, rev_label)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)",
        [
            $newCode,
            $f('name', ''),
            $f('short_description', null),
            $f('long_description', null),
            $f('category_id', null),
            $f('division_id', null),
            $f('manufacturer_type', 'internal'),
            $f('uom_id', null),
            $f('uom', 'pcs'),
            $f('dwg_no', null),
            $f('dwg_rev_no', null),
            $f('part_no', null),
            $f('part_rev_no', null),
            $f('process_spec', null),
            $f('process_step_id', null),
            $f('step_no', null),
            $f('step_time_min', null),
            $f('step_cost', null),
            $f('min_stock_level', null),
            $f('min_order_qty', null),
            $f('min_sample_qty', 0),
            $f('min_sample_pct', 0),
            $f('material_spec', null),
            $f('remarks', null),
            $f('notes', null),
            $f('unit_cost', null),
            0, // stock_on_hand always 0 for new item
            $from['is_product'] ?? 0,
            $fromItemId,   // supersedes_item_id
            $from['primary_vendor_id'] ?? null,
            $f('rev_label', null),
        ]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()');

    // Stamp on the ECN
    db_exec(
        "UPDATE ecns SET successor_item_id = ? WHERE id = ?",
        [$newId, (int)$ecnId]
    );
    // Update type_details JSON to_item_id so the rest of the form/preview path resolves it
    $details['to_item_id'] = $newId;
    db_exec(
        "UPDATE ecns SET type_details = ? WHERE id = ?",
        [ecn_encode_details($details), (int)$ecnId]
    );
    ecn_history_append($ecnId, 'edited', null, null, $newId,
        "Created successor item $newCode (id #$newId) cloned from " . $from['code'], $actorId);
    return $newId;
}

/**
 * For a material_sub ECN, return all inv_bom_lines that reference the
 * predecessor (from_item) as their child. Each row also includes the
 * parent item's code/name so the UI can display "<parent> uses <from>".
 */
function ecn_bom_sweep_candidates($ecnId)
{
    $ecn = db_one("SELECT type_details FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) return [];
    $details = ecn_decode_details($ecn['type_details']);
    $fromItemId = (int)($details['from_item_id'] ?? 0);
    if (!$fromItemId) return [];

    return db_all(
        "SELECT b.id AS bom_line_id, b.parent_item_id, b.child_item_id, b.qty,
                b.ref_designator, b.sort_order,
                p.code AS parent_code, p.name AS parent_name
           FROM inv_bom_lines b
           JOIN inv_items p ON p.id = b.parent_item_id
          WHERE b.child_item_id = ?
          ORDER BY p.code, b.sort_order",
        [$fromItemId]
    );
}

/**
 * Apply the BOM sweep. $rowChoices is an array keyed by bom_line_id;
 * each entry: ['rewrite' => bool, 'qty' => float|null].
 * Returns the count of rows actually rewritten.
 *
 * Only valid for material_sub ECNs and only when status = 'approved'
 * (not yet effective). Sets bom_sweep_completed_at when done.
 */
function ecn_apply_bom_sweep($ecnId, $rowChoices, $actorId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if ($ecn['ecn_type'] !== 'material_sub') {
        throw new RuntimeException("BOM sweep only applies to material_sub ECNs.");
    }
    if ($ecn['status'] !== 'approved') {
        throw new RuntimeException("BOM sweep can only run on an approved ECN (before Effective). Current status: " . $ecn['status']);
    }
    $details = ecn_decode_details($ecn['type_details']);
    $fromItemId = (int)($details['from_item_id'] ?? 0);
    $toItemId   = (int)$ecn['successor_item_id'] ?: (int)($details['to_item_id'] ?? 0);
    if (!$fromItemId || !$toItemId) {
        throw new RuntimeException("BOM sweep requires both from_item_id and a resolved successor.");
    }

    $rewritten = 0;
    foreach ($rowChoices as $bomLineId => $choice) {
        if (empty($choice['rewrite'])) continue;
        $bomLineId = (int)$bomLineId;
        $line = db_one(
            "SELECT * FROM inv_bom_lines WHERE id = ? AND child_item_id = ?",
            [$bomLineId, $fromItemId]
        );
        if (!$line) continue; // defensive — line may have changed since preview
        $newQty = isset($choice['qty']) && $choice['qty'] !== '' ? (float)$choice['qty'] : (float)$line['qty'];
        db_exec(
            "UPDATE inv_bom_lines SET child_item_id = ?, qty = ? WHERE id = ?",
            [$toItemId, $newQty, $bomLineId]
        );
        $rewritten++;
    }

    // Stamp completion (whether or not any rows were rewritten — operator
    // explicitly clicked the button which counts as "I'm done")
    db_exec(
        "UPDATE ecns SET bom_sweep_completed_at = NOW() WHERE id = ?",
        [(int)$ecnId]
    );
    ecn_history_append($ecnId, 'edited', null, null, null,
        "BOM sweep completed: $rewritten line(s) rewritten from item #$fromItemId to #$toItemId",
        $actorId);
    return $rewritten;
}

/**
 * Skip the BOM sweep (operator explicitly says "no BOMs to sweep").
 * Just stamps bom_sweep_completed_at so Make-Effective unblocks.
 */
function ecn_skip_bom_sweep($ecnId, $actorId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if ($ecn['ecn_type'] !== 'material_sub') return;
    if ($ecn['status'] !== 'approved') {
        throw new RuntimeException("Can only skip BOM sweep on an approved ECN.");
    }
    db_exec(
        "UPDATE ecns SET bom_sweep_completed_at = NOW() WHERE id = ?",
        [(int)$ecnId]
    );
    ecn_history_append($ecnId, 'edited', null, null, null,
        "BOM sweep explicitly skipped (no rewrites)", $actorId);
}

/**
 * Find documents linked to any of this ECN's affected items via the
 * doc_entity_links polymorphic table.
 */
function ecn_linked_docs($ecnId)
{
    return db_all(
        "SELECT DISTINCT d.id, d.code, d.title, d.kind, d.status,
                rv.rev_label AS current_rev_label
           FROM ecn_affected_items ai
           JOIN doc_entity_links del ON del.entity_type = 'inv_item' AND del.entity_id = ai.item_id
           JOIN documents d ON d.id = del.document_id
      LEFT JOIN doc_revisions rv ON rv.id = d.current_rev_id
          WHERE ai.ecn_id = ?
            AND d.deleted_at IS NULL
          ORDER BY d.code",
        [(int)$ecnId]
    );
}

/**
 * Create Draft drawing_rev ECNs for each linked document of the
 * affected items. Returns array of new ECN ids.
 *
 * Only valid post-Effective. Stamps drawings_drafted_at so the button
 * only fires once.
 */
function ecn_create_linked_drawing_ecns($ecnId, $actorId)
{
    $ecn = db_one("SELECT * FROM ecns WHERE id = ?", [(int)$ecnId]);
    if (!$ecn) throw new RuntimeException("ECN not found.");
    if (!$ecn['also_revise_drawings']) {
        throw new RuntimeException("This ECN doesn't have 'also revise linked drawings' enabled.");
    }
    if (!in_array($ecn['status'], ['effective', 'closed'], true)) {
        throw new RuntimeException("Linked DMS ECNs can only be created after this ECN is Effective.");
    }
    if ($ecn['drawings_drafted_at']) {
        throw new RuntimeException("Linked DMS ECNs were already created on " . $ecn['drawings_drafted_at']);
    }

    $linkedDocs = ecn_linked_docs($ecnId);
    $created = [];
    foreach ($linkedDocs as $d) {
        // Each linked doc gets a fresh drawing_rev ECN. The new rev
        // label is empty (operator fills it in); description references
        // the parent ECN.
        $code = ecn_next_no();
        $title = "Drawing revision driven by " . $ecn['ecn_no'] . ": " . $d['code'];
        $detailsJson = ecn_encode_details([
            'document_id'   => (int)$d['id'],
            'new_rev_label' => null,
            'change_summary' => "Linked to parent ECN " . $ecn['ecn_no'],
        ]);
        db_exec(
            "INSERT INTO ecns (ecn_no, title, ecn_type, status, originator_id,
                               type_details, pending_doc_id,
                               business_reason, description,
                               effectivity_mode)
             VALUES (?, ?, 'drawing_rev', 'draft', ?, ?, ?, ?, ?, 'date')",
            [$code, $title, (int)$actorId, $detailsJson, (int)$d['id'],
             "Cascaded from " . $ecn['ecn_no'],
             "This Draft was auto-created from " . $ecn['ecn_no'] .
             ". Upload the revised file and complete the change details, then submit for sign-off."]
        );
        $newId = (int)db_val('SELECT LAST_INSERT_ID()');
        ecn_history_append($newId, 'auto_drafted', null, 'draft', (int)$ecnId,
            "Auto-drafted from parent ECN " . $ecn['ecn_no'], $actorId);
        $created[] = $newId;
        // Cross-link back on the parent's history too
        ecn_history_append($ecnId, 'doc_rev_created', null, null, $newId,
            "Auto-drafted linked drawing_rev ECN $code for doc " . $d['code'], $actorId);
    }

    db_exec(
        "UPDATE ecns SET drawings_drafted_at = NOW() WHERE id = ?",
        [(int)$ecnId]
    );
    return $created;
}

/**
 * Set bom_sweep_required = 1 on creation of a material_sub ECN.
 * Called from the save handler.
 */
function ecn_mark_bom_sweep_required($ecnId)
{
    db_exec(
        "UPDATE ecns SET bom_sweep_required = 1 WHERE id = ? AND ecn_type = 'material_sub'",
        [(int)$ecnId]
    );
}
