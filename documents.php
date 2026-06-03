<?php
/**
 * MagDyn — Documents (DMS) module
 * Created: 20260519_120000_IST
 *
 * Single dispatcher for both internal (authored) and external
 * (incoming) documents. The ?kind=internal|external query param
 * gates permission checks and tunes the form / list columns. The
 * default landing is the internal list.
 *
 * Actions:
 *   list (default)     Datatable of documents in the chosen kind
 *   new                New-doc form (kind=internal or external)
 *   save (POST)        Create / update doc metadata
 *   view&id=N          Read-only view (lifecycle controls + rev/file/recipient/entity panels)
 *   edit&id=N          Edit form (only when status allows edits)
 *   upload_rev&id=N (POST)    DISABLED (ECN-only) — routes to raise a revision ECN
 *   download_rev&rid=N        Download a specific revision's file
 *   transition&id=N (POST)    Lifecycle status change
 *   release&id=N (POST)       Internal: release with effective_date + recipients
 *   accept&id=N (POST)        External: accept (revision unchanged)
 *   reject&id=N (POST)        External: reject with reason
 *   add_recipient&id=N (POST) Add a recipient to a released doc
 *   acknowledge&rid=N (POST)  Recipient acknowledges receipt
 *   add_entity&id=N (POST)    Link doc to an entity
 *   remove_entity&lid=N (POST) Unlink an entity
 *   delete&id=N (POST)        Hard delete (delete permission required)
 *
 * Permission model:
 *   documents_internal.view/create/manage/approve/delete  for internal kind
 *   documents_external.view/create/manage/approve/delete  for external kind
 *   note_cat_doc_review.view/manage   for the In Review running-notes category
 *
 * Files: stored under uploads/documents/<doc_id>/<rev_id>_<filename>.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/_dms.php';
require_once __DIR__ . '/includes/_codes.php';
require_once __DIR__ . '/includes/_notes.php';
require_once __DIR__ . '/includes/datatable.php';

// --------- request shape ---------
$action = (string)input('action', 'list');
$kind   = (string)input('kind', 'internal');
if (!in_array($kind, ['internal', 'external'], true)) {
    $kind = 'internal';
}
$id  = (int)input('id', 0);
$uid = current_user_id();

// View permission is required for everything
doc_require_view($kind);

// Determine page module (for sidebar highlight)
$page_module = ($kind === 'external') ? 'documents_external' : 'documents_internal';

// =============================================================
// Datatable config + row renderer — defined once at module top so
// both the AJAX short-circuit and the page-render path can use them.
// The shape mirrors the precedent in ecn.php / inspection.php:
//   data_table_run() inside the AJAX branch emits JSON and exits;
//   data_table_run() in the page-render path returns $dt, which is
//   then passed to data_table_render($dtCfg, $dt, $rowRenderer).
// =============================================================
$dtCfg = [
    'id'  => 'doc_list_' . $kind,
    'url' => url('/documents.php?action=list&kind=' . $kind),
    'base_sql' => "
        SELECT d.id, d.code, d.title, d.status, d.kind,
               c.name AS category_name, c.prefix,
               d.effective_date, d.expiry_date, d.next_review_date,
               d.received_date, d.issued_date,
               v.name AS vendor_name,
               u.full_name AS owner_name,
               d.updated_at,
               rv.rev_label AS current_rev
          FROM documents d
          JOIN doc_categories c ON c.id = d.category_id
     LEFT JOIN vendors v ON v.id = d.vendor_id
     LEFT JOIN users   u ON u.id = d.owner_id
     LEFT JOIN doc_revisions rv ON rv.id = d.current_rev_id
         WHERE d.deleted_at IS NULL AND d.kind = " . ($kind === 'external' ? "'external'" : "'internal'"),
    'columns' => [
        ['key' => 'code',           'label' => 'Code',     'sortable' => true, 'searchable' => true, 'sql_col' => 'd.code'],
        ['key' => 'title',          'label' => 'Title',    'sortable' => true, 'searchable' => true, 'sql_col' => 'd.title'],
        ['key' => 'category_name',  'label' => 'Category', 'sortable' => true, 'searchable' => true, 'sql_col' => 'c.name'],
        ['key' => 'current_rev',    'label' => 'Rev',      'sortable' => false,'searchable' => true, 'sql_col' => 'rv.rev_label'],
        ['key' => 'status',         'label' => 'Status',   'sortable' => true, 'searchable' => true, 'sql_col' => 'd.status'],
        ['key' => ($kind === 'external' ? 'vendor_name' : 'owner_name'),
            'label' => ($kind === 'external' ? 'Vendor' : 'Owner'),
            'sortable' => true, 'searchable' => true,
            'sql_col' => ($kind === 'external' ? 'v.name' : 'u.full_name')],
        ['key' => ($kind === 'external' ? 'expiry_date' : 'effective_date'),
            'label' => ($kind === 'external' ? 'Expiry' : 'Effective'),
            'sortable' => true, 'searchable' => true,
            'sql_col' => ($kind === 'external' ? 'd.expiry_date' : 'd.effective_date')],
        ['key' => 'updated_at',     'label' => 'Updated',  'sortable' => true, 'searchable' => false, 'sql_col' => 'd.updated_at'],
    ],
    'default_sort' => ['updated_at', 'desc'],
    'page_size'    => 25,
];
$rowRenderer = function ($row) use ($kind) {
    $pill = doc_status_pill($row['status']);
    $statusCell = '<span class="' . h($pill['class']) . '">' . h($pill['label']) . '</span>';
    $codeCell   = '<a href="' . url('/documents.php?action=view&id=' . (int)$row['id']) . '">' . h($row['code']) . '</a>';
    $titleCell  = h($row['title']);
    $catCell    = h($row['category_name']);
    $revCell    = $row['current_rev'] ? h($row['current_rev']) : '<span class="muted">—</span>';
    $partyCell  = $kind === 'external' ? h($row['vendor_name'] ?: '—') : h($row['owner_name'] ?: '—');
    $dateCol    = $kind === 'external' ? $row['expiry_date'] : $row['effective_date'];
    $dateCell   = $dateCol ? date('d M Y', strtotime($dateCol)) : '<span class="muted">—</span>';
    $updCell    = date('d M Y H:i', strtotime($row['updated_at']));
    // Return keyed by column key — data_table_render_rows looks up by key.
    return [
        'code'                                                          => $codeCell,
        'title'                                                         => $titleCell,
        'category_name'                                                 => $catCell,
        'current_rev'                                                   => $revCell,
        'status'                                                        => $statusCell,
        ($kind === 'external' ? 'vendor_name' : 'owner_name')           => $partyCell,
        ($kind === 'external' ? 'expiry_date' : 'effective_date')       => $dateCell,
        'updated_at'                                                    => $updCell,
    ];
};

// =============================================================
// AJAX SHORT-CIRCUIT: datatable list endpoint
// =============================================================
if ($action === 'list' && input('dt') === '1') {
    data_table_run($dtCfg, $rowRenderer);
    exit;
}

// =============================================================
// POST: save (create / update doc metadata)
// =============================================================
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    doc_require_manage($kind);

    $docId       = (int)input('id', 0);
    $categoryId  = (int)input('category_id', 0);
    $title       = trim((string)input('title', ''));
    $description = trim((string)input('description', ''));
    $vendorId    = (int)input('vendor_id', 0) ?: null;
    $ownerId     = (int)input('owner_id', 0) ?: null;
    $externalRef = trim((string)input('external_ref', '')) ?: null;
    $docNo       = trim((string)input('doc_no', '')) ?: null;
    $issuedDate  = (string)input('issued_date', '') ?: null;
    $receivedDate= (string)input('received_date', '') ?: null;
    $expiryDate  = (string)input('expiry_date', '') ?: null;
    $nextReview  = (string)input('next_review_date', '') ?: null;
    $trainingCourseId = (int)input('training_course_id', 0) ?: null;

    if ($title === '' || $categoryId <= 0) {
        flash_set('error', 'Title and category are required.');
        header('Location: ' . url('/documents.php?action=' . ($docId ? 'edit&id=' . $docId : 'new') . '&kind=' . $kind));
        exit;
    }

    if ($docId) {
        // UPDATE existing — metadata only; rev uploads happen on the view page
        $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$docId]);
        if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php?kind=' . $kind)); exit; }
        // Only certain statuses allow metadata edits
        $editableStatuses = ($kind === 'internal')
            ? ['draft', 'in_review', 'approved']
            : ['received', 'in_review'];
        if (!in_array($doc['status'], $editableStatuses, true)) {
            flash_set('error', 'Document cannot be edited in status ' . $doc['status']);
            header('Location: ' . url('/documents.php?action=view&id=' . $docId));
            exit;
        }
        db_exec(
            "UPDATE documents
                SET title=?, category_id=?, description=?, vendor_id=?, owner_id=?,
                    doc_no=?, external_ref=?, issued_date=?, received_date=?, expiry_date=?, next_review_date=?,
                    training_course_id=?, updated_by=?
              WHERE id=?",
            [$title, $categoryId, $description, $vendorId, $ownerId,
             $docNo, $externalRef, $issuedDate, $receivedDate, $expiryDate, $nextReview,
             $trainingCourseId, $uid, $docId]
        );
        doc_history_append($docId, 'edited', null, null, null, 'Metadata updated', $uid);
        flash_set('success', 'Document updated.');
        header('Location: ' . url('/documents.php?action=view&id=' . $docId));
        exit;
    }

    // CREATE — file + rev_label required.
    $revLabel  = trim((string)input('rev_label', ''));
    $changeNote = trim((string)input('change_note', '')) ?: null;
    $hasFile = isset($_FILES['file']) && is_array($_FILES['file'])
               && isset($_FILES['file']['error']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

    if ($revLabel === '' || !$hasFile) {
        flash_set('error', 'Initial revision label and file are both required.');
        header('Location: ' . url('/documents.php?action=new&kind=' . $kind));
        exit;
    }

    // Pre-check file size against server limits to surface a friendly error
    if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'File upload failed (error code ' . (int)$_FILES['file']['error'] . '). The file may be too large.');
        header('Location: ' . url('/documents.php?action=new&kind=' . $kind));
        exit;
    }

    $code = doc_next_code($categoryId);
    $initialStatus = ($kind === 'internal') ? 'draft' : 'received';
    db_exec(
        "INSERT INTO documents
            (code, doc_no, title, category_id, kind, status, owner_id, vendor_id,
             external_ref, issued_date, received_date, expiry_date, next_review_date,
             training_course_id, description, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$code, $docNo, $title, $categoryId, $kind, $initialStatus,
         $ownerId ?: $uid, $vendorId,
         $externalRef, $issuedDate, $receivedDate, $expiryDate, $nextReview,
         $trainingCourseId, $description, $uid, $uid]
    );
    $newId = (int)db_val('SELECT LAST_INSERT_ID()');
    doc_history_append($newId, 'created', null, $initialStatus, null,
        "Created document $code", $uid);

    // Store the uploaded file and create the initial revision row
    // using the operator-supplied label. If anything fails, the doc
    // row is already committed — we surface the error and let them
    // try the upload again from the document view.
    try {
        $meta = doc_store_uploaded_file($_FILES['file'], (int)$newId);
        $fileMeta = ['name' => $meta['name'], 'path' => $meta['path'],
                     'size' => $meta['size'], 'mime' => $meta['mime'], 'hash' => $meta['hash']];
        $revId = doc_add_revision((int)$newId, $revLabel, 'draft', $fileMeta, $changeNote, $uid);
        doc_finalize_staged_file($revId, (int)$newId, $meta);
        doc_set_current_rev((int)$newId, $revId);
        doc_history_append((int)$newId, 'file_uploaded', null, null, $revId,
            'Initial file: ' . $meta['name'] . ' (' . number_format($meta['size']) . ' bytes)', $uid);
        flash_set('success', "Document $code created with rev $revLabel.");
    } catch (Exception $e) {
        flash_set('error', "Document $code created but initial revision failed: " . $e->getMessage()
                  . ' You can upload the rev from the document view.');
    }
    header('Location: ' . url('/documents.php?action=view&id=' . $newId));
    exit;
}

// =============================================================
// POST: upload_rev — revisions are ECN-ONLY
//
// Policy: all revisions to existing documents (internal AND external)
// are created through an ECN. The direct-upload form has been removed
// from the document view. Internal 'release' uploads already auto-draft
// an ECN (kept below); any other direct revision attempt is refused and
// the operator is routed to raise a drawing-revision ECN. The only place
// a revision is still written directly is the INITIAL revision when a
// brand-new document is created (the 'save' CREATE path above).
// =============================================================
if ($action === 'upload_rev' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php')); exit; }
    doc_require_manage($doc['kind']);

    $revLabel = trim((string)input('rev_label', ''));
    $changeNote = trim((string)input('change_note', '')) ?: null;
    $stage = (string)input('stage', 'draft');
    if (!in_array($stage, ['draft','review','release','correction'], true)) $stage = 'draft';

    // Revisions are ECN-only. The only direct path still honoured is the
    // internal-release auto-draft below (kept for back-compat). Anything
    // else is refused immediately and routed to the ECN flow — we don't
    // require a file or label for the refusal path.
    if (!($stage === 'release' && $doc['kind'] === 'internal')) {
        require_once __DIR__ . '/includes/_ecn.php';
        if (permission_check('ecn', 'create')) {
            flash_set('error',
                'Revisions are made through an ECN, not by direct upload. '
              . 'Use "Raise revision ECN" on the document, attach the new file there, '
              . 'and the revision is created when the ECN becomes effective.');
            header('Location: ' . url('/ecn.php?action=new&doc_id=' . (int)$id));
            exit;
        }
        flash_set('error',
            'Revisions are made through an ECN. You need ECN create permission to raise one — please contact an administrator.');
        header('Location: ' . url('/documents.php?action=view&id=' . $id));
        exit;
    }

    // From here, this is the internal-release case only.
    if ($revLabel === '') {
        flash_set('error', 'Please type a revision label.');
        header('Location: ' . url('/documents.php?action=view&id=' . $id));
        exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please choose a file to upload.');
        header('Location: ' . url('/documents.php?action=view&id=' . $id));
        exit;
    }

    // ----- ECN GATING -----
    // Internal-doc release uploads route through ECN: we don't create
    // the DMS rev directly. Instead, an auto-Draft ECN is created
    // owning the file; the real rev appears when the ECN reaches Effective.
    if ($stage === 'release' && $doc['kind'] === 'internal') {
        require_once __DIR__ . '/includes/_ecn.php';
        try {
            // Check that the rev_label is unique on this doc — fail
            // fast here so the operator gets a clear error rather
            // than discovering it inside the ECN later.
            $dup = db_val(
                "SELECT id FROM doc_revisions WHERE document_id = ? AND rev_label = ?",
                [(int)$id, $revLabel]
            );
            if ($dup) {
                flash_set('error', "A revision labelled \"$revLabel\" already exists on this document. Pick a different label.");
                header('Location: ' . url('/documents.php?action=view&id=' . $id));
                exit;
            }

            // Create the Draft ECN
            $ecnId = ecn_auto_draft_for_major_rev((int)$id, $revLabel, $uid);

            // Move the uploaded file into the ECN's pending slot.
            // We stage it under uploads/ecn/<ecn_id>/ — same as the
            // ECN form would.
            $meta = ecn_store_pending_file($_FILES['file'], $ecnId);
            db_exec(
                "UPDATE ecns SET pending_file_name = ?, pending_file_path = ?,
                                 pending_file_size = ?, pending_file_mime = ?, pending_file_hash = ?,
                                 description = ?
                 WHERE id = ?",
                [$meta['name'], $meta['path'], $meta['size'], $meta['mime'], $meta['hash'],
                 $changeNote, $ecnId]
            );

            flash_set('success',
                "Released revisions go through ECN. A draft ECN has been created with your file — "
              . "fill in the change details and submit for sign-off.");
            header('Location: ' . url('/ecn.php?action=edit&id=' . $ecnId));
            exit;
        } catch (Exception $e) {
            flash_set('error', 'Could not start ECN: ' . $e->getMessage());
            header('Location: ' . url('/documents.php?action=view&id=' . $id));
            exit;
        }
    }
    // (Unreachable: the only path into this handler that isn't refused
    // above is internal-release, which always exits within the block.)
    header('Location: ' . url('/documents.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// download_rev — stream a specific revision's file
// =============================================================
if ($action === 'download_rev') {
    $rid = (int)input('rid', 0);
    $rev = db_one("SELECT r.*, d.code, d.kind FROM doc_revisions r
                   JOIN documents d ON d.id = r.document_id
                  WHERE r.id = ?", [$rid]);
    if (!$rev || !$rev['file_path']) {
        http_response_code(404); echo 'Not found'; exit;
    }
    doc_require_view($rev['kind']);
    $abs = __DIR__ . '/' . $rev['file_path'];
    if (!is_file($abs)) { http_response_code(404); echo 'File missing'; exit; }
    $name = $rev['file_name'] ?: basename($rev['file_path']);
    header('Content-Type: ' . ($rev['file_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
    readfile($abs);
    exit;
}

// =============================================================
// POST: transition — generic status change
// =============================================================
if ($action === 'transition' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php')); exit; }

    $to      = (string)input('to', '');
    $comment = trim((string)input('comment', '')) ?: null;
    if (!doc_can_transition($doc['kind'], $doc['status'], $to)) {
        flash_set('error', "Transition from {$doc['status']} to $to is not allowed.");
        header('Location: ' . url('/documents.php?action=view&id=' . $id));
        exit;
    }
    // Approve / release / accept require approve permission; others manage.
    if (in_array($to, ['approved', 'released', 'accepted', 'rejected'], true)) {
        doc_require_approve($doc['kind']);
    } else {
        doc_require_manage($doc['kind']);
    }

    $from = $doc['status'];
    $updates = ['status' => $to, 'updated_by' => $uid];

    if ($to === 'approved') {
        $updates['approver_id'] = $uid;
        $updates['approved_at'] = date('Y-m-d H:i:s');
    }
    if ($to === 'released') {
        $effective = (string)input('effective_date', '') ?: date('Y-m-d');
        $updates['effective_date'] = $effective;
        $updates['released_by'] = $uid;
        $updates['released_at'] = date('Y-m-d H:i:s');
        // Major rev bump on release
        $rev = doc_add_revision((int)$id, 'major', 'release', null,
            "Major rev bumped on release", $uid);
        doc_set_current_rev((int)$id, $rev);
    }
    if ($to === 'accepted') {
        // Acceptance does NOT change the revision. The accepted version
        // is whatever revision was entered when the document/rev was
        // logged in. Any revision change must be a deliberate, manually
        // entered "add revision" action — never a side effect of the
        // status transition.
        //
        // If for some reason no current revision is set yet, point it at
        // the latest existing revision rather than minting a new one.
        if (empty($doc['current_rev_id'])) {
            $latest = doc_latest_rev((int)$id);
            if ($latest) {
                doc_set_current_rev((int)$id, (int)$latest['id']);
            }
        }
    }
    if ($to === 'rejected') {
        // Capture reason as a doc_history comment (already happens below)
        // (Could later add a rejection_reason column.)
    }

    // Build dynamic UPDATE
    $setSql = []; $vals = [];
    foreach ($updates as $col => $val) { $setSql[] = "$col = ?"; $vals[] = $val; }
    $vals[] = (int)$id;
    db_exec("UPDATE documents SET " . implode(', ', $setSql) . " WHERE id = ?", $vals);

    doc_history_append((int)$id, 'status_change', $from, $to, null, $comment, $uid);
    flash_set('success', "Status changed to $to.");
    header('Location: ' . url('/documents.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: add_recipient — attach a recipient to the current rev
// =============================================================
if ($action === 'add_recipient' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php')); exit; }
    doc_require_manage($doc['kind']);
    if (!$doc['current_rev_id']) { flash_set('error', 'Document has no current revision.');
        header('Location: ' . url('/documents.php?action=view&id=' . $id)); exit; }

    $rcpUserId = (int)input('rcp_user_id', 0) ?: null;
    $rcpRoleId = (int)input('rcp_role_id', 0) ?: null;
    $rcpExt    = trim((string)input('rcp_external', '')) ?: null;
    $dueDate   = (string)input('rcp_due_date', '') ?: null;

    if (!$rcpUserId && !$rcpRoleId && !$rcpExt) {
        flash_set('error', 'Pick a user, role, or external name for the recipient.');
        header('Location: ' . url('/documents.php?action=view&id=' . $id)); exit;
    }
    doc_add_recipient((int)$id, (int)$doc['current_rev_id'], $rcpUserId, $rcpRoleId, $rcpExt, $dueDate, $uid);
    flash_set('success', 'Recipient added.');
    header('Location: ' . url('/documents.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: acknowledge — recipient confirms receipt
// =============================================================
if ($action === 'acknowledge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $recipientId = (int)input('rid', 0);
    $rcp = db_one("SELECT r.*, d.kind FROM doc_recipients r
                    JOIN documents d ON d.id = r.document_id
                   WHERE r.id = ?", [$recipientId]);
    if (!$rcp) { flash_set('error', 'Recipient not found.'); header('Location: ' . url('/documents.php')); exit; }
    if ($rcp['user_id'] && (int)$rcp['user_id'] !== $uid) {
        // Only the named user can ack their own recipient row, unless they have manage
        if (!permission_check(doc_module_for_kind($rcp['kind']), 'manage')) {
            flash_set('error', 'You can only acknowledge your own recipient assignments.');
            header('Location: ' . url('/documents.php?action=view&id=' . $rcp['document_id'])); exit;
        }
    }
    $comments = trim((string)input('comments', '')) ?: null;
    doc_acknowledge($recipientId, $uid, $comments);
    flash_set('success', 'Acknowledgment recorded.');
    header('Location: ' . url('/documents.php?action=view&id=' . $rcp['document_id']));
    exit;
}

// =============================================================
// POST: add_entity / remove_entity
// =============================================================
if ($action === 'add_entity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php')); exit; }
    doc_require_manage($doc['kind']);

    $linkNote = trim((string)input('link_note', '')) ?: null;

    // Collect refs from any of the three input shapes:
    //   entity_refs[]    — array of "type:id" tokens (multi-pick form)
    //   entity_ref       — single "type:id" token (single-pick combobox)
    //   entity_type + entity_id  — legacy split fields (kept for back-compat)
    $refs = [];
    if (isset($_POST['entity_refs']) && is_array($_POST['entity_refs'])) {
        foreach ($_POST['entity_refs'] as $r) {
            $r = trim((string)$r);
            if ($r !== '') $refs[] = $r;
        }
    }
    $single = trim((string)input('entity_ref', ''));
    if ($single !== '') $refs[] = $single;

    // Legacy fallback
    if (empty($refs)) {
        $eType = (string)input('entity_type', '');
        $eId   = (int)input('entity_id', 0);
        if ($eType && $eId) $refs[] = $eType . ':' . $eId;
    }

    if (empty($refs)) {
        flash_set('error', 'Pick at least one entity to link.');
        header('Location: ' . url('/documents.php?action=view&id=' . $id)); exit;
    }

    $linkedCount = 0;
    $allowedTypes = ['asset','inv_item','inspection','inspection_template','invoice','shipment','ecn'];
    foreach ($refs as $ref) {
        // Expect "type:id" — split once on the first ':'.
        $pos = strpos($ref, ':');
        if ($pos === false) continue;
        $eType = substr($ref, 0, $pos);
        $eId   = (int)substr($ref, $pos + 1);
        if (!in_array($eType, $allowedTypes, true) || $eId <= 0) continue;
        doc_link_entity((int)$id, $eType, $eId, $linkNote, $uid);
        $linkedCount++;
    }

    if ($linkedCount === 0) {
        flash_set('error', 'No valid entities found in the selection.');
    } elseif ($linkedCount === 1) {
        flash_set('success', 'Entity linked.');
    } else {
        flash_set('success', $linkedCount . ' entities linked.');
    }
    header('Location: ' . url('/documents.php?action=view&id=' . $id));
    exit;
}

if ($action === 'remove_entity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $linkId = (int)input('lid', 0);
    $link = db_one("SELECT * FROM doc_entity_links WHERE id = ?", [$linkId]);
    if (!$link) { flash_set('error', 'Link not found.'); header('Location: ' . url('/documents.php')); exit; }
    $doc = db_one("SELECT * FROM documents WHERE id = ?", [$link['document_id']]);
    doc_require_manage($doc['kind']);
    doc_unlink_entity((int)$link['document_id'], $link['entity_type'], (int)$link['entity_id'], $uid);
    flash_set('success', 'Entity unlinked.');
    header('Location: ' . url('/documents.php?action=view&id=' . $link['document_id']));
    exit;
}

// =============================================================
// POST: delete (soft)
// =============================================================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php')); exit; }
    require_permission(doc_module_for_kind($doc['kind']), 'delete');
    db_exec("UPDATE documents SET deleted_at = NOW(), updated_by = ? WHERE id = ?", [$uid, $id]);
    doc_history_append((int)$id, 'edited', null, null, null, 'Document soft-deleted', $uid);
    flash_set('success', 'Document deleted.');
    header('Location: ' . url('/documents.php?kind=' . $doc['kind']));
    exit;
}

// =============================================================
// GET: new — show new-document form
// =============================================================
if ($action === 'new') {
    require_permission(doc_module_for_kind($kind), 'create');
    $page_title = 'New ' . ucfirst($kind) . ' Document';
    $categories = doc_categories($kind);
    $vendors    = ($kind === 'external') ? db_all("SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name") : [];
    $users      = db_all("SELECT id, full_name AS name, email FROM users WHERE is_active = 1 ORDER BY full_name");
    $courses    = db_all("SELECT id, title FROM training_courses WHERE is_active = 1 ORDER BY title");
    require __DIR__ . '/includes/header.php';
    render_doc_form(null, $kind, $categories, $vendors, $users, $courses);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =============================================================
// GET: edit
// =============================================================
if ($action === 'edit') {
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php')); exit; }
    doc_require_manage($doc['kind']);
    $page_title = 'Edit ' . $doc['code'];
    $categories = doc_categories($doc['kind']);
    $vendors    = ($doc['kind'] === 'external') ? db_all("SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name") : [];
    $users      = db_all("SELECT id, full_name AS name, email FROM users WHERE is_active = 1 ORDER BY full_name");
    $courses    = db_all("SELECT id, title FROM training_courses WHERE is_active = 1 ORDER BY title");
    require __DIR__ . '/includes/header.php';
    render_doc_form($doc, $doc['kind'], $categories, $vendors, $users, $courses);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =============================================================
// GET: view (default page for one doc)
// =============================================================
if ($action === 'view') {
    $doc = db_one(
        "SELECT d.*, c.name AS category_name, c.code AS category_code, c.kind AS category_kind, c.prefix,
                v.name AS vendor_name,
                u_owner.full_name AS owner_name,
                u_appr.full_name  AS approver_name,
                u_rel.full_name   AS released_by_name,
                rv.rev_label AS current_rev_label,
                rv.file_name AS current_file_name,
                rv.id AS current_rev_pk,
                tc.title AS training_course_title
           FROM documents d
           JOIN doc_categories c ON c.id = d.category_id
      LEFT JOIN vendors v       ON v.id = d.vendor_id
      LEFT JOIN users u_owner   ON u_owner.id = d.owner_id
      LEFT JOIN users u_appr    ON u_appr.id  = d.approver_id
      LEFT JOIN users u_rel     ON u_rel.id   = d.released_by
      LEFT JOIN doc_revisions rv ON rv.id = d.current_rev_id
      LEFT JOIN training_courses tc ON tc.id = d.training_course_id
          WHERE d.id = ? AND d.deleted_at IS NULL",
        [$id]
    );
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/documents.php?kind=' . $kind)); exit; }
    doc_require_view($doc['kind']);

    $page_title = $doc['code'] . ' — ' . $doc['title'];
    $page_module = doc_module_for_kind($doc['kind']);

    $revisions  = db_all("SELECT r.*, u.full_name AS created_by_name,
                                  e.ecn_no AS ecn_no, e.status AS ecn_status
                           FROM doc_revisions r
                           LEFT JOIN users u ON u.id = r.created_by
                           LEFT JOIN ecns  e ON e.id = r.ecn_id
                          WHERE r.document_id = ?
                          ORDER BY r.id DESC", [$id]);
    $history    = db_all("SELECT h.*, u.full_name AS actor_name FROM doc_history h
                           LEFT JOIN users u ON u.id = h.actor_id
                          WHERE h.document_id = ?
                          ORDER BY h.id DESC", [$id]);
    $recipients = $doc['current_rev_pk'] ? doc_recipients_for_rev((int)$id, (int)$doc['current_rev_pk']) : [];
    $entityLinks= doc_entity_links((int)$id);
    $transmittals = db_all("SELECT t.*, rv.rev_label, u.full_name AS created_by_name
                             FROM doc_transmittals t
                             LEFT JOIN doc_revisions rv ON rv.id = t.revision_id
                             LEFT JOIN users u ON u.id = t.created_by
                            WHERE t.document_id = ?
                            ORDER BY t.sent_date DESC, t.id DESC", [$id]);
    $allowedTransitions = doc_allowed_transitions($doc['kind'], $doc['status']);

    // Entity-picker options for the Linked Entities form. Two groups
    // by default (Asset, Inventory Item) since those are the day-to-
    // day links. Each option carries a "type:id" value; the
    // add_entity handler splits this back into (type, id).
    // Defensive try/catch in case a table is missing on an older install.
    $entityOptions = ['assets' => [], 'inv_items' => []];
    try {
        // Active assets: status != 'archived'. Join asset_models for a
        // readable label alongside the tag.
        $entityOptions['assets'] = db_all(
            "SELECT a.id, a.asset_tag AS code,
                    CONCAT_WS(' — ', a.asset_tag,
                             NULLIF(CONCAT_WS(' ', am.manufacturer, am.model_number), '')) AS label
               FROM assets a
          LEFT JOIN asset_models am ON am.id = a.model_id
              WHERE a.status <> 'archived'
              ORDER BY a.asset_tag LIMIT 2000"
        );
    } catch (Exception $e) { /* assets schema may vary on older installs */ }
    try {
        $entityOptions['inv_items'] = db_all(
            "SELECT id, code, CONCAT(code, ' — ', name) AS label
               FROM inv_items
              WHERE is_active = 1
              ORDER BY code LIMIT 2000"
        );
    } catch (Exception $e) { /* inv_items may not exist on legacy installs */ }

    require __DIR__ . '/includes/header.php';
    render_doc_view($doc, $revisions, $history, $recipients, $entityLinks, $transmittals, $allowedTransitions, $entityOptions);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =============================================================
// GET: list (default) — page render
// =============================================================
$page_title = ($kind === 'external' ? 'External' : 'Internal') . ' Documents';
// Run the datatable query before page chrome so $dt is ready for
// data_table_render below. The AJAX short-circuit earlier in this
// file already handled the JSON case for ?dt=1 requests.
$dt = data_table_run($dtCfg, $rowRenderer);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1><?= h($page_title) ?></h1>
        <p class="muted"><?= $kind === 'external' ? 'Documents received from outside (vendor certs, customer specs, etc.)' : 'Authored documents (SOPs, drawings, procedures, etc.)' ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/documents.php?kind=' . ($kind === 'internal' ? 'external' : 'internal'))) ?>">
            Switch to <?= h($kind === 'internal' ? 'External' : 'Internal') ?>
        </a>
        <?php if (permission_check(doc_module_for_kind($kind), 'create')): ?>
            <a class="btn btn-primary" href="<?= h(url('/documents.php?action=new&kind=' . $kind)) ?>">+ New <?= h(ucfirst($kind)) ?> Document</a>
        <?php endif; ?>
    </div>
</div>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php
require __DIR__ . '/includes/footer.php';

// =============================================================
// VIEW RENDERERS (kept in this file to avoid partials sprawl)
// =============================================================

function render_doc_form($doc, $kind, $categories, $vendors, $users, $courses)
{
    $isInternal = $kind === 'internal';
?>
<div class="page-head">
    <div>
        <h1><?= $doc ? 'Edit ' . h($doc['code']) : 'New ' . h(ucfirst($kind)) . ' document' ?></h1>
        <p class="muted"><?= $doc ? 'Editing metadata. Revisions and files are managed from the document view.' : ($isInternal ? 'A new internal document starts in Draft. Upload files and assign recipients once it\'s released.' : 'Log a document received from outside (vendor cert, customer spec, MTR, etc.).') ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url($doc ? '/documents.php?action=view&id=' . (int)$doc['id'] : '/documents.php?kind=' . $kind)) ?>">← Back</a>
    </div>
</div>

<form method="post" action="<?= h(url('/documents.php?action=save&kind=' . h($kind))) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php if ($doc): ?><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><?php endif; ?>

    <!-- Identity -->
    <div class="card form-card" style="margin-bottom: 18px;">
        <h3 style="margin: 0 0 14px; font-size: 14px;">Identity</h3>
        <div class="form-grid-2">
            <div class="field span-2">
                <label>Category <span style="color: var(--danger);">*</span></label>
                <select name="category_id" required>
                    <option value="">— pick —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                            <?= $doc && (int)$doc['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= h($c['name']) ?> (<?= h($c['prefix']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field span-2">
                <label>Title <span style="color: var(--danger);">*</span></label>
                <input type="text" name="title" required maxlength="255" value="<?= h($doc['title'] ?? '') ?>">
            </div>
            <div class="field span-2">
                <label>Description</label>
                <textarea name="description" rows="3"><?= h($doc['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <?php if (!$doc): ?>
    <!-- Initial revision (CREATE only). Edits to metadata don't touch
         the rev history; new revs are uploaded from the document view. -->
    <div class="card form-card" style="margin-bottom: 18px;">
        <h3 style="margin: 0 0 14px; font-size: 14px;">
            Initial revision
            <span class="muted small" style="font-weight: normal; letter-spacing: 0; text-transform: none;">
                — required to create the document
            </span>
        </h3>
        <div class="form-grid-2">
            <div class="field">
                <label>Revision label <span style="color: var(--danger);">*</span></label>
                <input type="text" name="rev_label" required maxlength="64"
                       placeholder="e.g. A, 0.1, 1.0, Rev B"
                       value="<?= h((string)input('rev_label', '')) ?>">
                <span class="field-hint">Free text. Must be unique on this document.</span>
            </div>
            <div class="field">
                <label>File <span style="color: var(--danger);">*</span></label>
                <input type="file" name="file" required>
                <span class="field-hint">One file per revision. Add more revisions from the document view after save.</span>
            </div>
            <div class="field span-2">
                <label>Change note <span class="muted small">(optional)</span></label>
                <textarea name="change_note" rows="2"
                          placeholder="What's in this initial revision?"><?= h((string)input('change_note', '')) ?></textarea>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isInternal): ?>
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Ownership &amp; training</h3>
            <div class="form-grid-2">
                <div class="field">
                    <label>Owner</label>
                    <select name="owner_id">
                        <option value="">— pick —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $doc && (int)($doc['owner_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= h($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Linked training course</label>
                    <select name="training_course_id">
                        <option value="">— none —</option>
                        <?php foreach ($courses as $tc): ?>
                            <option value="<?= (int)$tc['id'] ?>" <?= $doc && (int)($doc['training_course_id'] ?? 0) === (int)$tc['id'] ? 'selected' : '' ?>>
                                <?= h($tc['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint">Recipients are enrolled on acknowledgment.</span>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Source</h3>
            <div class="form-grid-2">
                <div class="field">
                    <label>Vendor</label>
                    <select name="vendor_id">
                        <option value="">— pick —</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" <?= $doc && (int)($doc['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
                                <?= h($v['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Doc No</label>
                    <input type="text" name="doc_no" maxlength="120" value="<?= h($doc['doc_no'] ?? '') ?>"
                           placeholder="Document number (e.g. E55201, Q00501)">
                </div>
                <div class="field">
                    <label>External reference</label>
                    <input type="text" name="external_ref" maxlength="120" value="<?= h($doc['external_ref'] ?? '') ?>"
                           placeholder="Document no as printed by the issuer">
                </div>
            </div>
        </div>

        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Dates</h3>
            <div class="form-grid-2">
                <div class="field">
                    <label>Issued date</label>
                    <input type="date" name="issued_date" value="<?= h($doc['issued_date'] ?? '') ?>">
                    <span class="field-hint">Printed on the document.</span>
                </div>
                <div class="field">
                    <label>Received date</label>
                    <input type="date" name="received_date" value="<?= h($doc['received_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label>Expiry date</label>
                    <input type="date" name="expiry_date" value="<?= h($doc['expiry_date'] ?? '') ?>">
                    <span class="field-hint">Optional.</span>
                </div>
                <div class="field">
                    <label>Next review date</label>
                    <input type="date" name="next_review_date" value="<?= h($doc['next_review_date'] ?? '') ?>">
                    <span class="field-hint">Periodic re-review.</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $doc ? 'Save changes' : 'Create document' ?></button>
    </div>
</form>
<?php
}

function render_doc_view($doc, $revisions, $history, $recipients, $entityLinks, $transmittals, $allowedTransitions, $entityOptions = ['assets' => [], 'inv_items' => []])
{
    $pill        = doc_status_pill($doc['status']);
    $isInternal  = $doc['kind'] === 'internal';
    $canManage   = permission_check(doc_module_for_kind($doc['kind']), 'manage');
    $canApprove  = permission_check(doc_module_for_kind($doc['kind']), 'approve');
    $canDelete   = permission_check(doc_module_for_kind($doc['kind']), 'delete');
?>
<div class="page-head">
    <div>
        <h1>
            <?= h($doc['code']) ?>
            <span class="pill <?= h($pill['class']) ?>" style="margin-left: 10px; font-size: 11px;"><?= h($pill['label']) ?></span>
        </h1>
        <p class="muted">
            <?= h($doc['title']) ?>
            · <?= h($doc['category_name']) ?>
            · Rev <?= h($doc['current_rev_label'] ?? '—') ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/documents.php?kind=' . h($doc['kind']))) ?>">← All <?= h(ucfirst($doc['kind'])) ?> Docs</a>
        <?php if ($canManage): ?>
            <a class="btn btn-ghost" href="<?= h(url('/documents.php?action=edit&id=' . (int)$doc['id'])) ?>">Edit</a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= h(url('/documents.php?action=delete&id=' . (int)$doc['id'])) ?>" style="display:inline"
                  onsubmit="return confirm('Soft-delete this document? You can recover it from the database if needed.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- WORKFLOW ACTIONS -->
<?php if (!empty($allowedTransitions)): ?>
<div class="card" style="margin-bottom: 20px; background: #fffce8; border-left: 3px solid #d97706;">
    <div class="card-body">
        <h4 style="margin-top:0;">Workflow actions</h4>
        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start;">
            <?php foreach ($allowedTransitions as $t):
                $tp = doc_status_pill($t);
                // approve/release/accept/reject require the approve perm; others manage.
                $needsApprove = in_array($t, ['approved', 'released', 'accepted', 'rejected'], true);
                if ($needsApprove && !$canApprove) continue;
                if (!$needsApprove && !$canManage) continue;
                $btnClass = 'btn-primary';
                if ($t === 'released' || $t === 'accepted' || $t === 'approved') $btnClass = 'btn-success';
                if ($t === 'rejected' || $t === 'obsolete') $btnClass = 'btn-ghost';
            ?>
                <form method="post" action="<?= h(url('/documents.php?action=transition&id=' . (int)$doc['id'])) ?>"
                      style="display: flex; flex-direction: column; gap: 6px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="to" value="<?= h($t) ?>">
                    <?php if ($t === 'released'): ?>
                        <label class="muted small">Effective date
                            <input type="date" name="effective_date" value="<?= h(date('Y-m-d')) ?>">
                        </label>
                    <?php endif; ?>
                    <button type="submit" class="btn <?= h($btnClass) ?> btn-sm">→ <?= h($tp['label']) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- METADATA + UPLOAD REVISION (two-column, but flex so they wrap on small screens) -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px;">
    <div class="card" style="margin-bottom: 0;">
        <div class="card-head"><h3 style="margin:0; font-size:15px;">Metadata</h3></div>
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr><th style="width:32%; text-align:left;">Code</th>
                        <td><?= h($doc['code']) ?></td></tr>
                    <tr><th style="text-align:left;">Kind</th>
                        <td><?= h(ucfirst($doc['kind'])) ?></td></tr>
                    <tr><th style="text-align:left;">Category</th>
                        <td><?= h($doc['category_name']) ?></td></tr>
                    <?php if ($isInternal): ?>
                        <tr><th style="text-align:left;">Owner</th>
                            <td><?= h($doc['owner_name'] ?: '—') ?></td></tr>
                        <tr><th style="text-align:left;">Approved by</th>
                            <td>
                                <?= h($doc['approver_name'] ?: '—') ?>
                                <?= $doc['approved_at'] ? '<span class="muted small">· ' . h(dt_display($doc['approved_at'])) . '</span>' : '' ?>
                            </td></tr>
                        <tr><th style="text-align:left;">Released by</th>
                            <td>
                                <?= h($doc['released_by_name'] ?: '—') ?>
                                <?= $doc['released_at'] ? '<span class="muted small">· ' . h(dt_display($doc['released_at'])) . '</span>' : '' ?>
                            </td></tr>
                        <tr><th style="text-align:left;">Effective date</th>
                            <td><?= $doc['effective_date'] ? h(date('d M Y', strtotime($doc['effective_date']))) : '—' ?></td></tr>
                        <?php if (!empty($doc['training_course_title'])): ?>
                            <tr><th style="text-align:left;">Training course</th>
                                <td><?= h($doc['training_course_title']) ?></td></tr>
                        <?php endif; ?>
                    <?php else: ?>
                        <tr><th style="text-align:left;">Vendor</th>
                            <td><?= h($doc['vendor_name'] ?: '—') ?></td></tr>
                        <tr><th style="text-align:left;">External ref</th>
                            <td><?= h($doc['external_ref'] ?: '—') ?></td></tr>
                        <tr><th style="text-align:left;">Issued</th>
                            <td><?= $doc['issued_date'] ? h(date('d M Y', strtotime($doc['issued_date']))) : '—' ?></td></tr>
                        <tr><th style="text-align:left;">Received</th>
                            <td><?= $doc['received_date'] ? h(date('d M Y', strtotime($doc['received_date']))) : '—' ?></td></tr>
                        <tr><th style="text-align:left;">Expiry</th>
                            <td><?= $doc['expiry_date'] ? h(date('d M Y', strtotime($doc['expiry_date']))) : '—' ?></td></tr>
                        <tr><th style="text-align:left;">Next review</th>
                            <td><?= $doc['next_review_date'] ? h(date('d M Y', strtotime($doc['next_review_date']))) : '—' ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($doc['description']): ?>
                <h4 style="margin: 18px 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color: var(--text-muted);">Description</h4>
                <p style="margin: 0; line-height: 1.6;"><?= nl2br(h($doc['description'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <div class="card-head"><h3 style="margin:0; font-size:15px;">New revision</h3></div>
        <div class="card-body">
            <?php if ($canManage): ?>
                <p class="muted" style="margin:0 0 12px;">
                    Revisions to this document are made through an
                    <strong>Engineering Change Notice</strong>. Direct uploads
                    are disabled so every revision has an approval trail.
                </p>
                <p class="muted" style="margin:0 0 14px;">
                    Raise an ECN of type <em>Drawing revision</em> against this
                    document, attach the new file, and submit it for sign-off.
                    The new revision is created automatically when the ECN
                    reaches <strong>Effective</strong>.
                </p>
                <?php if (permission_check('ecn', 'create')): ?>
                    <a class="btn btn-primary"
                       href="<?= h(url('/ecn.php?action=new&doc_id=' . (int)$doc['id'])) ?>">
                        Raise revision ECN &rarr;
                    </a>
                <?php else: ?>
                    <p class="muted small" style="margin:0;">You need ECN create permission to raise a revision. Ask an administrator.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">You don't have permission to manage this document.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- REVISIONS -->
<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Revisions <span class="muted small">(<?= count($revisions) ?>)</span></h3></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($revisions)): ?>
            <p class="muted" style="padding: 18px;">No revisions yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Rev</th><th>Stage</th><th>File</th><th>Size</th><th>Change note</th><th>ECN</th><th>Created</th><th>By</th></tr></thead>
                <tbody>
                    <?php foreach ($revisions as $r):
                        $isCurrent = (int)$r['id'] === (int)($doc['current_rev_id'] ?? 0);
                    ?>
                        <tr<?= $isCurrent ? ' style="background:#f0fdf4;"' : '' ?>>
                            <td><strong><?= h($r['rev_label']) ?></strong>
                                <?= $isCurrent ? ' <span class="pill pill-success" style="font-size:9px;">current</span>' : '' ?></td>
                            <td><span class="muted small"><?= h($r['stage']) ?></span></td>
                            <td><?php if ($r['file_path']): ?>
                                <a href="<?= h(url('/documents.php?action=download_rev&rid=' . (int)$r['id'])) ?>">
                                    <?= h($r['file_name'] ?: basename($r['file_path'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="muted small">(no file)</span>
                            <?php endif; ?></td>
                            <td class="r"><?= $r['file_size'] ? h(number_format((int)$r['file_size'])) . ' B' : '—' ?></td>
                            <td><?= h($r['change_note'] ?: '') ?></td>
                            <td>
                                <?php if (!empty($r['ecn_no'])): ?>
                                    <a href="<?= h(url('/ecn.php?action=view&id=' . (int)$r['ecn_id'])) ?>">
                                        <?= h($r['ecn_no']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="muted small"><?= h(dt_display($r['created_at'])) ?></span></td>
                            <td><?= h($r['created_by_name'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- RECIPIENTS (only for released internal docs) -->
<?php if ($isInternal && $doc['status'] === 'released'): ?>
<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Recipients &amp; acknowledgments <span class="muted small">(<?= count($recipients) ?>)</span></h3></div>
    <div class="card-body">
        <?php if ($canManage): ?>
            <form method="post" action="<?= h(url('/documents.php?action=add_recipient&id=' . (int)$doc['id'])) ?>"
                  style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 14px;">
                <?= csrf_field() ?>
                <div style="min-width: 220px;">
                    <label class="muted small">User</label>
                    <select name="rcp_user_id">
                        <option value="">— pick user —</option>
                        <?php foreach (db_all("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name") as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= h($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width: 180px;">
                    <label class="muted small">OR external name</label>
                    <input type="text" name="rcp_external" maxlength="160">
                </div>
                <div>
                    <label class="muted small">Due date</label>
                    <input type="date" name="rcp_due_date">
                </div>
                <button class="btn btn-primary btn-sm" type="submit">+ Add recipient</button>
            </form>
        <?php endif; ?>

        <?php if (empty($recipients)): ?>
            <p class="muted">No recipients assigned yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Recipient</th><th>Assigned</th><th>Due</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recipients as $r):
                        $who = $r['user_name'] ?: ($r['role_name'] ? 'Role: ' . $r['role_name'] : $r['external_name']);
                    ?>
                        <tr>
                            <td><?= h($who) ?></td>
                            <td><span class="muted small"><?= h(dt_display($r['assigned_at'])) ?></span></td>
                            <td><?= $r['due_date'] ? h(date('d M Y', strtotime($r['due_date']))) : '—' ?></td>
                            <td><?= $r['ack_id']
                                    ? '<span class="pill pill-success">Acked ' . h(date('d M', strtotime($r['acknowledged_at']))) . '</span>'
                                    : '<span class="pill pill-pending">Pending</span>' ?></td>
                            <td>
                                <?php if (!$r['ack_id'] && (int)($r['user_id'] ?? 0) === current_user_id()): ?>
                                    <form method="post" action="<?= h(url('/documents.php?action=acknowledge&rid=' . (int)$r['id'])) ?>" style="display:inline">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-primary btn-sm" type="submit">Acknowledge</button>
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
<?php endif; ?>

<!-- LINKED ENTITIES -->
<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Linked entities <span class="muted small">(<?= count($entityLinks) ?>)</span></h3></div>
    <div class="card-body">
        <?php if ($canManage):
            // Flatten the two option groups into a single JSON catalogue
            // so the chip-picker JS can search across both at once. Each
            // entry has the type:id token, the display label, and a kind
            // tag for the optgroup heading.
            $entityCatalog = [];
            foreach ($entityOptions['assets'] as $a) {
                $entityCatalog[] = [
                    'ref'   => 'asset:' . (int)$a['id'],
                    'label' => $a['label'],
                    'kind'  => 'Asset',
                ];
            }
            foreach ($entityOptions['inv_items'] as $i) {
                $entityCatalog[] = [
                    'ref'   => 'inv_item:' . (int)$i['id'],
                    'label' => $i['label'],
                    'kind'  => 'Inventory item',
                ];
            }
        ?>
            <form method="post" action="<?= h(url('/documents.php?action=add_entity&id=' . (int)$doc['id'])) ?>"
                  id="doc-entity-form" style="margin-bottom: 14px;">
                <?= csrf_field() ?>
                <div class="form-grid-2">
                    <div class="field span-2">
                        <label>Assets &amp; inventory items <span class="muted small">(pick one or more)</span></label>
                        <!-- Multi-picker shell. Chips of selected items + search input render inside;
                             a hidden bag of <input name="entity_refs[]"> mirrors the selection for submit. -->
                        <div class="entity-picker" id="doc-entity-picker">
                            <div class="entity-picker-chips" id="doc-entity-chips">
                                <input type="text" id="doc-entity-search"
                                       class="entity-picker-search"
                                       placeholder="Type to search assets &amp; inventory items…"
                                       autocomplete="off">
                            </div>
                            <div class="entity-picker-menu" id="doc-entity-menu" hidden></div>
                            <div class="entity-picker-hidden" id="doc-entity-hidden"></div>
                        </div>
                    </div>
                    <div class="field span-2">
                        <label>Note <span class="muted small">(applied to all picks)</span></label>
                        <input type="text" name="link_note" maxlength="500">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit" id="doc-entity-submit" disabled>+ Link selected</button>
                </div>
            </form>

            <!-- Scoped styles for the multi-picker only. Keeps the rest of the
                 app's combobox styling untouched. -->
            <style>
            .entity-picker { position: relative; }
            .entity-picker-chips {
                display: flex; flex-wrap: wrap; gap: 6px;
                padding: 6px 8px; min-height: 38px;
                border: 1px solid var(--border-strong);
                border-radius: var(--radius);
                background: white;
                align-items: center;
            }
            .entity-picker-chips:focus-within {
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            }
            .entity-picker .chip {
                display: inline-flex; align-items: center; gap: 4px;
                background: var(--surface-alt, #eef2ff);
                color: var(--text);
                font-size: 12px;
                padding: 3px 6px 3px 9px;
                border-radius: 12px;
                border: 1px solid var(--border);
                line-height: 1.3;
            }
            .entity-picker .chip .chip-kind {
                font-size: 10px;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin-right: 4px;
            }
            .entity-picker .chip button {
                background: none; border: none; padding: 0; margin-left: 2px;
                cursor: pointer; color: var(--text-muted);
                font-size: 14px; line-height: 1;
                display: inline-flex; align-items: center; justify-content: center;
                width: 18px; height: 18px; border-radius: 50%;
            }
            .entity-picker .chip button:hover { background: rgba(0,0,0,0.08); color: var(--danger); }
            .entity-picker-search {
                flex: 1; min-width: 180px;
                border: none; outline: none; background: transparent;
                padding: 4px 2px; font-size: 13.5px;
                font-family: inherit; color: var(--text);
            }
            .entity-picker-menu {
                position: absolute; left: 0; right: 0; top: calc(100% + 4px);
                background: white;
                border: 1px solid var(--border);
                border-radius: var(--radius);
                box-shadow: 0 8px 24px rgba(0,0,0,0.12);
                max-height: 420px;       /* taller than the default 240px combobox menu */
                overflow-y: auto;
                z-index: 50;
            }
            .entity-picker-menu-group-label {
                font-size: 10px; font-weight: 700;
                text-transform: uppercase; letter-spacing: 0.06em;
                color: var(--text-muted);
                padding: 8px 12px 4px;
                background: var(--surface-alt, #f9fafb);
                position: sticky; top: 0;
            }
            .entity-picker-menu .opt {
                padding: 7px 12px; cursor: pointer; font-size: 13px;
                display: flex; justify-content: space-between; gap: 8px;
                border-bottom: 1px solid var(--border);
            }
            .entity-picker-menu .opt:last-child { border-bottom: none; }
            .entity-picker-menu .opt:hover,
            .entity-picker-menu .opt.active { background: var(--surface-alt, #eef2ff); }
            .entity-picker-menu .opt.picked {
                opacity: 0.45; cursor: default; font-style: italic;
            }
            .entity-picker-menu .opt .opt-kind {
                font-size: 11px; color: var(--text-muted);
                white-space: nowrap;
            }
            .entity-picker-menu .empty {
                padding: 14px; text-align: center; color: var(--text-muted);
                font-size: 12.5px;
            }
            .entity-picker-hidden { display: none; }
            </style>

            <script>
            (function () {
                var CATALOG = <?= json_encode($entityCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                var picker  = document.getElementById('doc-entity-picker');
                if (!picker) return;
                var chipsEl   = document.getElementById('doc-entity-chips');
                var searchEl  = document.getElementById('doc-entity-search');
                var menuEl    = document.getElementById('doc-entity-menu');
                var hiddenBag = document.getElementById('doc-entity-hidden');
                var submitBtn = document.getElementById('doc-entity-submit');

                // Map ref -> {label, kind} for fast lookup
                var byRef = {};
                CATALOG.forEach(function (e) { byRef[e.ref] = e; });

                var picked = []; // array of refs (in selection order)
                var hi = -1;     // highlighted index in current filtered list

                function render() {
                    // Re-render chips (preserve search input)
                    chipsEl.querySelectorAll('.chip').forEach(function (c) { c.remove(); });
                    hiddenBag.innerHTML = '';
                    picked.forEach(function (ref) {
                        var entry = byRef[ref];
                        if (!entry) return;
                        var chip = document.createElement('span');
                        chip.className = 'chip';
                        chip.innerHTML =
                            '<span class="chip-kind">' + escapeHTML(entry.kind) + '</span>' +
                            escapeHTML(entry.label) +
                            '<button type="button" aria-label="Remove" data-ref="' + escapeAttr(ref) + '">×</button>';
                        chipsEl.insertBefore(chip, searchEl);
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'entity_refs[]';
                        hidden.value = ref;
                        hiddenBag.appendChild(hidden);
                    });
                    submitBtn.disabled = (picked.length === 0);
                    submitBtn.textContent = picked.length > 1
                        ? ('+ Link ' + picked.length + ' selected')
                        : '+ Link selected';
                }

                function escapeHTML(s) {
                    return String(s).replace(/[&<>"']/g, function (c) {
                        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                    });
                }
                function escapeAttr(s) { return escapeHTML(s); }

                function buildMenu(q) {
                    q = (q || '').trim().toLowerCase();
                    var matches = CATALOG.filter(function (e) {
                        if (!q) return true;
                        return e.label.toLowerCase().indexOf(q) !== -1
                            || e.kind.toLowerCase().indexOf(q) !== -1;
                    });
                    if (matches.length === 0) {
                        menuEl.innerHTML = '<div class="empty">No matches.</div>';
                        hi = -1;
                        return;
                    }
                    // Group by kind, with sticky kind headers
                    var byKind = {};
                    matches.forEach(function (e) {
                        if (!byKind[e.kind]) byKind[e.kind] = [];
                        byKind[e.kind].push(e);
                    });
                    var html = '';
                    Object.keys(byKind).forEach(function (kind) {
                        html += '<div class="entity-picker-menu-group-label">' + escapeHTML(kind) + '</div>';
                        byKind[kind].forEach(function (e) {
                            var isPicked = picked.indexOf(e.ref) !== -1;
                            html +=
                                '<div class="opt' + (isPicked ? ' picked' : '') + '" data-ref="' + escapeAttr(e.ref) + '">' +
                                  '<span>' + escapeHTML(e.label) + '</span>' +
                                  '<span class="opt-kind">' + escapeHTML(e.kind) + '</span>' +
                                '</div>';
                        });
                    });
                    menuEl.innerHTML = html;
                    hi = -1;
                }

                function openMenu() {
                    buildMenu(searchEl.value);
                    menuEl.hidden = false;
                }
                function closeMenu() { menuEl.hidden = true; hi = -1; }

                function toggleRef(ref) {
                    if (!byRef[ref]) return;
                    var idx = picked.indexOf(ref);
                    if (idx >= 0) {
                        picked.splice(idx, 1);
                    } else {
                        picked.push(ref);
                    }
                    render();
                    buildMenu(searchEl.value);  // refresh "picked" greyed state
                }

                function moveHi(delta) {
                    var opts = menuEl.querySelectorAll('.opt:not(.picked)');
                    if (!opts.length) return;
                    hi += delta;
                    if (hi < 0) hi = opts.length - 1;
                    if (hi >= opts.length) hi = 0;
                    opts.forEach(function (o) { o.classList.remove('active'); });
                    opts[hi].classList.add('active');
                    opts[hi].scrollIntoView({ block: 'nearest' });
                }

                searchEl.addEventListener('focus', openMenu);
                searchEl.addEventListener('input', function () { openMenu(); });
                searchEl.addEventListener('keydown', function (ev) {
                    if (ev.key === 'ArrowDown') { ev.preventDefault(); openMenu(); moveHi(+1); }
                    else if (ev.key === 'ArrowUp') { ev.preventDefault(); moveHi(-1); }
                    else if (ev.key === 'Enter') {
                        var opts = menuEl.querySelectorAll('.opt:not(.picked)');
                        if (hi >= 0 && opts[hi]) {
                            ev.preventDefault();
                            toggleRef(opts[hi].getAttribute('data-ref'));
                            searchEl.value = '';
                            openMenu();
                        }
                    }
                    else if (ev.key === 'Escape') { closeMenu(); }
                    else if (ev.key === 'Backspace' && !searchEl.value && picked.length) {
                        picked.pop();
                        render();
                        buildMenu(searchEl.value);
                    }
                });

                menuEl.addEventListener('mousedown', function (ev) {
                    // mousedown so blur doesn't close before click registers
                    var opt = ev.target.closest('.opt');
                    if (!opt || opt.classList.contains('picked')) return;
                    ev.preventDefault();
                    toggleRef(opt.getAttribute('data-ref'));
                    searchEl.value = '';
                    searchEl.focus();
                });

                chipsEl.addEventListener('click', function (ev) {
                    var btn = ev.target.closest('.chip button');
                    if (!btn) {
                        // Clicking the empty area focuses search
                        if (ev.target === chipsEl) searchEl.focus();
                        return;
                    }
                    toggleRef(btn.getAttribute('data-ref'));
                });

                // Click outside closes the menu
                document.addEventListener('mousedown', function (ev) {
                    if (!picker.contains(ev.target)) closeMenu();
                });

                render();
            })();
            </script>
        <?php endif; ?>

        <?php if (empty($entityLinks)): ?>
            <p class="muted">No linked entities.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Type</th><th>Entity</th><th>Note</th><th>Linked</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($entityLinks as $l): ?>
                        <tr>
                            <td><span class="pill pill-neutral"><?= h($l['entity_type']) ?></span></td>
                            <td><?= h(doc_resolve_entity_label($l['entity_type'], (int)$l['entity_id'])) ?></td>
                            <td><?= h($l['link_note'] ?: '') ?></td>
                            <td><span class="muted small"><?= h(dt_display($l['created_at'])) ?></span></td>
                            <td>
                                <?php if ($canManage): ?>
                                    <form method="post" action="<?= h(url('/documents.php?action=remove_entity&lid=' . (int)$l['id'])) ?>" style="display:inline"
                                          onsubmit="return confirm('Unlink this entity?')">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-ghost btn-sm" type="submit">Unlink</button>
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

<!-- TRANSMITTALS (only shown if any exist) -->
<?php if (!empty($transmittals)): ?>
<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Transmittals <span class="muted small">(<?= count($transmittals) ?>)</span></h3></div>
    <div class="card-body" style="padding: 0;">
        <table class="data-table">
            <thead><tr><th>No</th><th>Sent</th><th>Recipient</th><th>Method</th><th>Rev</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($transmittals as $t):
                    $recip = $t['recipient_attn'] ?: $t['external_party'] ?: ('User #' . (int)($t['user_id'] ?? 0));
                ?>
                    <tr>
                        <td><a href="<?= h(url('/transmittals.php?action=view&id=' . (int)$t['id'])) ?>"><?= h($t['transmittal_no']) ?></a></td>
                        <td><span class="muted small"><?= h(date('d M Y', strtotime($t['sent_date']))) ?></span></td>
                        <td><?= h($recip) ?></td>
                        <td><span class="muted small"><?= h($t['method']) ?></span></td>
                        <td><?= h($t['rev_label']) ?></td>
                        <td><span class="pill pill-neutral"><?= h($t['delivery_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- RUNNING NOTES -->
<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Running notes</h3></div>
    <div class="card-body">
        <?php
            try {
                notes_render('document', (int)$doc['id'], 'inline');
            } catch (Exception $e) {
                echo '<p class="muted">Notes unavailable: ' . h($e->getMessage()) . '</p>';
            }
        ?>
    </div>
</div>

<!-- HISTORY -->
<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">History <span class="muted small">(<?= count($history) ?>)</span></h3></div>
    <div class="card-body" style="padding: 0;">
        <table class="data-table">
            <thead><tr><th>When</th><th>Event</th><th>From → To</th><th>Comment</th><th>By</th></tr></thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><span class="muted small"><?= h(dt_display($h['created_at'])) ?></span></td>
                        <td><span class="muted small"><?= h($h['event_type']) ?></span></td>
                        <td><?= h(($h['from_status'] ?: '') . ($h['to_status'] ? ' → ' . $h['to_status'] : '')) ?></td>
                        <td><?= h($h['comment'] ?: '') ?></td>
                        <td><?= h($h['actor_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
}
