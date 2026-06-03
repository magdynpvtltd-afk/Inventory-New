<?php
/**
 * MagDyn — Documents (DMS) helpers
 *
 * Shared functions used by dispatcher and views. Pure functions where
 * possible; DB-touching functions are clearly suffixed.
 *
 * Naming: doc_*(), dms_*(), with the entity-action shape used elsewhere
 * (e.g. doc_status_pill, doc_can_transition, doc_next_rev).
 *
 * Created: 20260519_120000_IST
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_codes.php';

// =============================================================
// CATEGORIES
// =============================================================

/**
 * All active categories, optionally filtered by kind.
 * @param string|null $kind 'internal' | 'external' | null (both)
 * @return array
 */
function doc_categories($kind = null)
{
    if ($kind === null) {
        return db_all(
            "SELECT id, code, name, kind, prefix, description, is_active, sort_order
               FROM doc_categories
              WHERE is_active = 1
              ORDER BY sort_order, name"
        );
    }
    return db_all(
        "SELECT id, code, name, kind, prefix, description, is_active, sort_order
           FROM doc_categories
          WHERE is_active = 1 AND kind = ?
          ORDER BY sort_order, name",
        [$kind]
    );
}

function doc_category($id)
{
    return db_one("SELECT * FROM doc_categories WHERE id = ?", [(int)$id]);
}

function doc_category_by_code($code)
{
    return db_one("SELECT * FROM doc_categories WHERE code = ?", [$code]);
}

// =============================================================
// CODE GENERATION
// Each category gets its own code_sequences row named doc_<cat_code>
// (created in the migration). doc_next_code() pulls from there.
// =============================================================

function doc_next_code($categoryId)
{
    $cat = doc_category($categoryId);
    if (!$cat) {
        throw new RuntimeException("Unknown document category id $categoryId");
    }
    return code_next('doc_' . $cat['code']);
}

function dms_next_transmittal_no()
{
    return code_next('doc_transmittal');
}

// =============================================================
// STATUS / LIFECYCLE
// Two separate state machines, one per kind. doc_can_transition()
// gates UI buttons; do_*() functions in the dispatcher actually apply.
// =============================================================

/**
 * All possible statuses for a kind, in canonical order.
 */
function doc_statuses($kind)
{
    if ($kind === 'internal') {
        return ['draft', 'in_review', 'approved', 'released', 'obsolete'];
    }
    if ($kind === 'external') {
        return ['received', 'in_review', 'accepted', 'rejected', 'filed'];
    }
    return [];
}

/**
 * Map a status to a CSS pill class + display label.
 * Returns ['class' => ..., 'label' => ...].
 */
function doc_status_pill($status)
{
    $map = [
        // Internal
        'draft'     => ['class' => 'pill pill-draft',     'label' => 'Draft'],
        'in_review' => ['class' => 'pill pill-pending',   'label' => 'In Review'],
        'approved'  => ['class' => 'pill pill-active',    'label' => 'Approved'],
        'released'  => ['class' => 'pill pill-success',   'label' => 'Released'],
        'obsolete'  => ['class' => 'pill pill-neutral',   'label' => 'Obsolete'],
        // External
        'received'  => ['class' => 'pill pill-draft',     'label' => 'Received'],
        'accepted'  => ['class' => 'pill pill-success',   'label' => 'Accepted'],
        'rejected'  => ['class' => 'pill pill-danger',    'label' => 'Rejected'],
        'filed'     => ['class' => 'pill pill-active',    'label' => 'Filed'],
    ];
    if (isset($map[$status])) return $map[$status];
    return ['class' => 'pill pill-neutral', 'label' => $status];
}

/**
 * Allowed forward transitions from $from for kind. Used to populate
 * the action menu on the document view. (Returns the set of valid $to.)
 */
function doc_allowed_transitions($kind, $from)
{
    if ($kind === 'internal') {
        switch ($from) {
            case 'draft':     return ['in_review', 'obsolete'];
            case 'in_review': return ['approved', 'draft'];   // approve or send-back
            case 'approved':  return ['released', 'in_review'];
            case 'released':  return ['obsolete'];
            case 'obsolete':  return [];
        }
    } elseif ($kind === 'external') {
        switch ($from) {
            case 'received':  return ['in_review', 'accepted', 'rejected'];
            case 'in_review': return ['accepted', 'rejected', 'received'];
            case 'accepted':  return ['filed'];
            case 'rejected':  return ['received'];   // re-receive a corrected version
            case 'filed':     return [];
        }
    }
    return [];
}

function doc_can_transition($kind, $from, $to)
{
    return in_array($to, doc_allowed_transitions($kind, $from), true);
}

// =============================================================
// REVISIONS
// Major.Minor numbering with auto-Minor bump on each save and Major
// bump at release (internal) / accept (external).
// =============================================================

/**
 * Find the latest rev (highest major then minor) for a document.
 * @return array|null
 */
function doc_latest_rev($documentId)
{
    return db_one(
        "SELECT * FROM doc_revisions
          WHERE document_id = ?
          ORDER BY rev_major DESC, rev_minor DESC
          LIMIT 1",
        [(int)$documentId]
    );
}

/**
 * Compute the next rev label given a bump kind.
 *  - 'minor': latest.minor + 1 (latest.major unchanged)
 *  - 'major': latest.major + 1, minor reset to 0
 *  - 'initial': 0.1 if no revs exist, otherwise behave like 'minor'
 *
 * Returns ['major' => N, 'minor' => N, 'label' => 'N.N'].
 */
function doc_next_rev($documentId, $bump = 'minor')
{
    $latest = doc_latest_rev($documentId);
    if (!$latest) {
        return ['major' => 0, 'minor' => 1, 'label' => '0.1'];
    }
    $maj = (int)$latest['rev_major'];
    $min = (int)$latest['rev_minor'];
    if ($bump === 'major') {
        return ['major' => $maj + 1, 'minor' => 0, 'label' => ($maj + 1) . '.0'];
    }
    // default: minor bump
    return ['major' => $maj, 'minor' => $min + 1, 'label' => $maj . '.' . ($min + 1)];
}

/**
 * Persist a new revision row for a document.
 *
 * Phase A change: $bump is now a free-text label string. The previous
 * 'major'/'minor'/'initial' magic strings are still accepted for back-
 * compat — when one of those is passed, the helper computes a numeric
 * label via doc_next_rev() and uses it. Anything else is treated as
 * the literal label the operator wants ("A", "Rev B", "1.0", etc.).
 *
 * Uniqueness: (document_id, rev_label) must be unique. If the label
 * already exists on this doc, throws RuntimeException — caller should
 * surface a friendly message.
 *
 * @param int    $documentId
 * @param string $bump        Free-text rev label, OR legacy magic
 *                            string 'major' / 'minor' / 'initial'.
 * @param string $stage       'draft' | 'review' | 'release' | 'correction'
 * @param array|null $fileMeta Optional file metadata (name, path, size, mime, hash)
 * @param string|null $changeNote
 * @param int|null $createdBy
 * @return int new revision id
 * @throws RuntimeException on duplicate label or DB error
 */
function doc_add_revision($documentId, $bump, $stage, $fileMeta = null, $changeNote = null, $createdBy = null)
{
    $fm = is_array($fileMeta) ? $fileMeta : [];

    // Legacy magic strings keep the old Major.Minor behaviour for
    // callers (e.g. the lifecycle transition code that bumps Major
    // on release). Anything else is a free-text label.
    $isLegacyBump = in_array($bump, ['major', 'minor', 'initial'], true);
    if ($isLegacyBump) {
        $rev      = doc_next_rev($documentId, $bump);
        $major    = $rev['major'];
        $minor    = $rev['minor'];
        $label    = $rev['label'];
    } else {
        $label = trim((string)$bump);
        if ($label === '') {
            throw new RuntimeException("Revision label cannot be empty.");
        }
        if (strlen($label) > 64) {
            throw new RuntimeException("Revision label is too long (max 64 chars).");
        }
        $major = null;
        $minor = null;
    }

    // Uniqueness check — friendlier than a 1062 SQL error
    $existing = db_one(
        "SELECT id FROM doc_revisions WHERE document_id = ? AND rev_label = ?",
        [(int)$documentId, $label]
    );
    if ($existing) {
        throw new RuntimeException("A revision labelled \"$label\" already exists on this document.");
    }

    db_exec(
        "INSERT INTO doc_revisions
            (document_id, rev_major, rev_minor, rev_label, stage,
             file_name, file_path, file_size, file_mime, file_hash,
             change_note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            (int)$documentId, $major, $minor, $label, $stage,
            isset($fm['name']) ? $fm['name'] : null,
            isset($fm['path']) ? $fm['path'] : null,
            isset($fm['size']) ? (int)$fm['size'] : null,
            isset($fm['mime']) ? $fm['mime'] : null,
            isset($fm['hash']) ? $fm['hash'] : null,
            $changeNote,
            $createdBy ? (int)$createdBy : null,
        ]
    );
    $revId = (int)db_val('SELECT LAST_INSERT_ID()');
    doc_history_append($documentId, 'rev_added', null, null, $revId,
        "Rev " . $label . " added" . ($changeNote ? ": " . $changeNote : ""), $createdBy);
    return $revId;
}

/**
 * Set the document's current_rev_id pointer (the "public" version).
 */
function doc_set_current_rev($documentId, $revId)
{
    db_exec(
        "UPDATE documents SET current_rev_id = ?, updated_at = NOW() WHERE id = ?",
        [(int)$revId, (int)$documentId]
    );
}

// =============================================================
// FILE STORAGE
// Files land under uploads/documents/<doc_id>/<rev_id>_<safe_name>.
// =============================================================

/**
 * Move an uploaded $_FILES element into the per-doc folder and return
 * the structured file metadata ready for doc_add_revision().
 *
 * @param array $fileSlot one element of $_FILES (e.g. $_FILES['file'])
 * @param int   $docId
 * @return array file metadata array
 */
function doc_store_uploaded_file($fileSlot, $docId)
{
    if (!is_array($fileSlot) || !isset($fileSlot['tmp_name']) || $fileSlot['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload failed (error=" . (isset($fileSlot['error']) ? $fileSlot['error'] : 'no file') . ")");
    }

    $baseDir = __DIR__ . '/../uploads/documents/' . (int)$docId;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException("Could not create document upload directory: $baseDir");
    }

    $orig = $fileSlot['name'];
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
    $hash = hash_file('sha256', $fileSlot['tmp_name']);

    // Stage the file with a placeholder revision-index 'tmp'; the
    // caller renames after doc_add_revision returns the real rev id.
    $stagedPath = $baseDir . '/staging_' . substr($hash, 0, 12) . '_' . $safe;
    if (!move_uploaded_file($fileSlot['tmp_name'], $stagedPath)) {
        // For non-multipart contexts (rare; allow fallback)
        if (!@rename($fileSlot['tmp_name'], $stagedPath) && !@copy($fileSlot['tmp_name'], $stagedPath)) {
            throw new RuntimeException("Could not move uploaded file to $stagedPath");
        }
    }

    return [
        'name'      => $orig,
        'safe_name' => $safe,
        'path'      => 'uploads/documents/' . (int)$docId . '/' . basename($stagedPath),
        'abs_path'  => $stagedPath,
        'size'      => filesize($stagedPath),
        'mime'      => function_exists('mime_content_type') ? mime_content_type($stagedPath) : null,
        'hash'      => $hash,
    ];
}

/**
 * Finalize a staged file by renaming staging_... → <rev_id>_<safe_name>.
 * Updates the doc_revisions row's file_path to the final relative path.
 */
function doc_finalize_staged_file($revId, $docId, $stagedMeta)
{
    $finalName = (int)$revId . '_' . $stagedMeta['safe_name'];
    $absFinal  = __DIR__ . '/../uploads/documents/' . (int)$docId . '/' . $finalName;
    if (!@rename($stagedMeta['abs_path'], $absFinal)) {
        // If rename fails, the file is still accessible at the staging path —
        // log and continue with staging path as the final.
        return;
    }
    $relFinal = 'uploads/documents/' . (int)$docId . '/' . $finalName;
    db_exec(
        "UPDATE doc_revisions SET file_path = ? WHERE id = ?",
        [$relFinal, (int)$revId]
    );
}

// =============================================================
// HISTORY
// =============================================================

function doc_history_append($documentId, $eventType, $fromStatus = null, $toStatus = null, $relatedId = null, $comment = null, $actorId = null)
{
    db_exec(
        "INSERT INTO doc_history
            (document_id, event_type, from_status, to_status, related_id, comment, actor_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            (int)$documentId, $eventType, $fromStatus, $toStatus,
            $relatedId !== null ? (int)$relatedId : null,
            $comment,
            $actorId !== null ? (int)$actorId : null,
        ]
    );
}

// =============================================================
// ENTITY LINKING
// =============================================================

function doc_link_entity($documentId, $entityType, $entityId, $linkNote = null, $actorId = null)
{
    db_exec(
        "INSERT IGNORE INTO doc_entity_links
            (document_id, entity_type, entity_id, link_note, created_by)
         VALUES (?, ?, ?, ?, ?)",
        [(int)$documentId, $entityType, (int)$entityId, $linkNote, $actorId ? (int)$actorId : null]
    );
    doc_history_append($documentId, 'entity_linked', null, null, (int)$entityId,
        $entityType . '#' . (int)$entityId . ($linkNote ? ' — ' . $linkNote : ''),
        $actorId);
}

function doc_unlink_entity($documentId, $entityType, $entityId, $actorId = null)
{
    db_exec(
        "DELETE FROM doc_entity_links
          WHERE document_id = ? AND entity_type = ? AND entity_id = ?",
        [(int)$documentId, $entityType, (int)$entityId]
    );
    doc_history_append($documentId, 'entity_unlinked', null, null, (int)$entityId,
        $entityType . '#' . (int)$entityId, $actorId);
}

function doc_entity_links($documentId)
{
    return db_all(
        "SELECT * FROM doc_entity_links WHERE document_id = ? ORDER BY entity_type, entity_id",
        [(int)$documentId]
    );
}

/**
 * Find an external document by its doc_no (the source/printed document
 * number, e.g. 'E55201'). Returns the most-recently-updated match, with
 * its current revision label resolved, or null.
 *
 * Used by the XML inventory-items importer to decide link vs upload vs
 * rev-change. Restricted to external docs (kind='external') since the
 * documents_bom block in part-report XML references external specs.
 *
 * The returned row includes:
 *   - all documents.* columns
 *   - cur_rev_label : the rev_label of current_rev_id (or latest rev if
 *                     current_rev_id is null), or '' if no revisions
 */
function doc_find_by_no($docNo, $kind = 'external')
{
    $docNo = trim((string)$docNo);
    if ($docNo === '') return null;
    $row = db_one(
        "SELECT * FROM documents
          WHERE doc_no = ? AND kind = ? AND deleted_at IS NULL
          ORDER BY updated_at DESC
          LIMIT 1",
        [$docNo, $kind]
    );
    if (!$row) return null;

    $label = '';
    if (!empty($row['current_rev_id'])) {
        $r = db_one("SELECT rev_label FROM doc_revisions WHERE id = ?", [(int)$row['current_rev_id']]);
        if ($r) $label = (string)$r['rev_label'];
    }
    if ($label === '') {
        $latest = doc_latest_rev((int)$row['id']);
        if ($latest) $label = (string)$latest['rev_label'];
    }
    $row['cur_rev_label'] = $label;
    return $row;
}

/**
 * Does a revision with this exact label already exist on the document?
 * Case-insensitive, trimmed. Used to avoid drafting an ECN (or adding a
 * revision) for a label that's already present in the document's
 * history — which would otherwise fail with a duplicate-label error
 * when the revision is materialized.
 */
function doc_rev_label_exists($documentId, $revLabel)
{
    $revLabel = trim((string)$revLabel);
    if ($revLabel === '') return false;
    $r = db_one(
        "SELECT id FROM doc_revisions
          WHERE document_id = ?
            AND LOWER(rev_label) = LOWER(?)
          LIMIT 1",
        [(int)$documentId, $revLabel]
    );
    return (bool)$r;
}

/**
 * Reverse lookup: every doc linked to a given entity.
 */
function doc_for_entity($entityType, $entityId)
{
    return db_all(
        "SELECT d.*, c.name AS category_name, c.prefix AS category_prefix
           FROM doc_entity_links l
           JOIN documents d ON d.id = l.document_id AND d.deleted_at IS NULL
           JOIN doc_categories c ON c.id = d.category_id
          WHERE l.entity_type = ? AND l.entity_id = ?
          ORDER BY d.updated_at DESC",
        [$entityType, (int)$entityId]
    );
}

/**
 * Human label for an entity reference (best-effort, defensive on
 * tables that may not exist on every install).
 */
function doc_resolve_entity_label($entityType, $entityId)
{
    try {
        switch ($entityType) {
            case 'asset':
                $r = db_one('SELECT tag, model_id FROM assets WHERE id = ?', [(int)$entityId]);
                return $r ? ('Asset ' . $r['tag']) : ('Asset #' . (int)$entityId);
            case 'inv_item':
                $r = db_one('SELECT code, name FROM inv_items WHERE id = ?', [(int)$entityId]);
                return $r ? ($r['code'] . ' — ' . $r['name']) : ('Item #' . (int)$entityId);
            case 'inspection':
                $r = db_one('SELECT code FROM inspections WHERE id = ?', [(int)$entityId]);
                return $r ? ('Inspection ' . $r['code']) : ('Inspection #' . (int)$entityId);
            case 'inspection_template':
                $r = db_one('SELECT code, name FROM inspection_templates WHERE id = ?', [(int)$entityId]);
                return $r ? ($r['code'] . ' — ' . $r['name']) : ('Template #' . (int)$entityId);
            case 'invoice':
                $r = db_one('SELECT invoice_no FROM invoices WHERE id = ?', [(int)$entityId]);
                return $r ? ('Invoice ' . $r['invoice_no']) : ('Invoice #' . (int)$entityId);
            case 'shipment':
                $r = db_one('SELECT code FROM inv_shipments WHERE id = ?', [(int)$entityId]);
                return $r ? ('Shipment ' . $r['code']) : ('Shipment #' . (int)$entityId);
            case 'ecn':
                $r = db_one('SELECT code FROM ecns WHERE id = ?', [(int)$entityId]);
                return $r ? ('ECN ' . $r['code']) : ('ECN #' . (int)$entityId);
        }
    } catch (Exception $e) {
        // table may not exist on this install; fall through to default
    }
    return $entityType . ' #' . (int)$entityId;
}

// =============================================================
// PERMISSIONS HELPERS
// =============================================================

/**
 * Resolve the gating module code for a document. Internal docs use
 * documents_internal.*, external use documents_external.*.
 */
function doc_module_for_kind($kind)
{
    return ($kind === 'external') ? 'documents_external' : 'documents_internal';
}

function doc_require_view($kind)
{
    require_permission(doc_module_for_kind($kind), 'view');
}

function doc_require_manage($kind)
{
    require_permission(doc_module_for_kind($kind), 'manage');
}

function doc_require_approve($kind)
{
    require_permission(doc_module_for_kind($kind), 'approve');
}

// =============================================================
// DASHBOARD QUERIES
// Used by both the documents dashboard page and the home-page widget.
// =============================================================

/**
 * Released internal docs whose effective_date is today or future
 * within $windowDays days. Sorted by date ascending.
 */
function doc_dashboard_effective_due($windowDays = 7)
{
    return db_all(
        "SELECT d.id, d.code, d.title, d.effective_date, c.name AS category_name
           FROM documents d
           JOIN doc_categories c ON c.id = d.category_id
          WHERE d.kind = 'internal'
            AND d.status = 'released'
            AND d.deleted_at IS NULL
            AND d.effective_date IS NOT NULL
            AND d.effective_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
          ORDER BY d.effective_date ASC, d.code ASC",
        [(int)$windowDays]
    );
}

/**
 * Incoming docs whose next_review_date is today or earlier OR within window.
 */
function doc_dashboard_review_due($windowDays = 14)
{
    return db_all(
        "SELECT d.id, d.code, d.title, d.next_review_date, c.name AS category_name,
                DATEDIFF(d.next_review_date, CURDATE()) AS days_remaining
           FROM documents d
           JOIN doc_categories c ON c.id = d.category_id
          WHERE d.kind = 'external'
            AND d.status IN ('accepted','filed')
            AND d.deleted_at IS NULL
            AND d.next_review_date IS NOT NULL
            AND d.next_review_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          ORDER BY d.next_review_date ASC, d.code ASC",
        [(int)$windowDays]
    );
}

/**
 * Incoming docs whose expiry_date is past or approaching.
 */
function doc_dashboard_expiring($windowDays = 30)
{
    return db_all(
        "SELECT d.id, d.code, d.title, d.expiry_date, c.name AS category_name,
                DATEDIFF(d.expiry_date, CURDATE()) AS days_remaining
           FROM documents d
           JOIN doc_categories c ON c.id = d.category_id
          WHERE d.kind = 'external'
            AND d.status IN ('accepted','filed')
            AND d.deleted_at IS NULL
            AND d.expiry_date IS NOT NULL
            AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          ORDER BY d.expiry_date ASC, d.code ASC",
        [(int)$windowDays]
    );
}

/**
 * Internal docs released but with unacknowledged recipients.
 * Returns one row per (doc, recipient) pair pending ack.
 */
function doc_dashboard_pending_acks_for_user($userId)
{
    return db_all(
        "SELECT d.id AS doc_id, d.code, d.title, c.name AS category_name,
                r.id AS recipient_id, r.due_date,
                rv.rev_label
           FROM doc_recipients r
           JOIN documents d ON d.id = r.document_id AND d.kind = 'internal' AND d.deleted_at IS NULL
           JOIN doc_categories c ON c.id = d.category_id
           JOIN doc_revisions rv ON rv.id = r.revision_id
      LEFT JOIN doc_acknowledgments a ON a.recipient_id = r.id
          WHERE r.user_id = ?
            AND a.id IS NULL
            AND d.status = 'released'
          ORDER BY r.due_date ASC, d.code ASC",
        [(int)$userId]
    );
}

// =============================================================
// RECIPIENT / ACK HELPERS
// =============================================================

function doc_recipients_for_rev($documentId, $revisionId)
{
    return db_all(
        "SELECT r.*,
                u.email AS user_email,
                u.full_name AS user_name,
                ro.name AS role_name,
                a.id AS ack_id,
                a.acknowledged_at,
                au.full_name AS ack_user_name
           FROM doc_recipients r
      LEFT JOIN users u  ON u.id = r.user_id
      LEFT JOIN roles ro ON ro.id = r.role_id
      LEFT JOIN doc_acknowledgments a ON a.recipient_id = r.id
      LEFT JOIN users au ON au.id = a.user_id
          WHERE r.document_id = ? AND r.revision_id = ?
          ORDER BY r.assigned_at ASC",
        [(int)$documentId, (int)$revisionId]
    );
}

function doc_add_recipient($documentId, $revisionId, $userId = null, $roleId = null, $externalName = null, $dueDate = null, $assignedBy = null)
{
    db_exec(
        "INSERT INTO doc_recipients (document_id, revision_id, user_id, role_id, external_name, due_date, assigned_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [
            (int)$documentId, (int)$revisionId,
            $userId ? (int)$userId : null,
            $roleId ? (int)$roleId : null,
            $externalName,
            $dueDate,
            $assignedBy ? (int)$assignedBy : null,
        ]
    );
    $rid = (int)db_val('SELECT LAST_INSERT_ID()');
    doc_history_append($documentId, 'recipient_added', null, null, $rid,
        $userId ? "User #$userId" : ($roleId ? "Role #$roleId" : $externalName),
        $assignedBy);
    return $rid;
}

function doc_acknowledge($recipientId, $userId, $comments = null)
{
    $rec = db_one("SELECT * FROM doc_recipients WHERE id = ?", [(int)$recipientId]);
    if (!$rec) {
        throw new RuntimeException("Unknown recipient $recipientId");
    }
    // Guard: each recipient can only ack once.
    $existing = db_one("SELECT id FROM doc_acknowledgments WHERE recipient_id = ?", [(int)$recipientId]);
    if ($existing) {
        return (int)$existing['id'];
    }
    db_exec(
        "INSERT INTO doc_acknowledgments (recipient_id, document_id, revision_id, user_id, comments)
         VALUES (?, ?, ?, ?, ?)",
        [(int)$recipientId, (int)$rec['document_id'], (int)$rec['revision_id'], (int)$userId, $comments]
    );
    $ackId = (int)db_val('SELECT LAST_INSERT_ID()');
    doc_history_append((int)$rec['document_id'], 'acknowledged', null, null, $ackId,
        $comments ? ("Comments: " . $comments) : null,
        $userId);
    return $ackId;
}
