<?php
/**
 * MagDyn — Process flows dispatcher
 *
 * Module separate from training. SOPs, work instructions, decision trees.
 * Two modes per process:
 *   - document   — rich body_html + screenshots
 *   - structured — typed node graph rendered as Mermaid; editable via
 *                  the dedicated editor at ?action=editor
 *
 * Actions:
 *   ?action=index               list visible processes (default)
 *   ?action=view&id=N           view a process (Mermaid for structured)
 *   ?action=new                 admin: new process form  (processes.create)
 *   ?action=edit&id=N           admin: edit metadata     (processes.manage)
 *   ?action=save  POST          save metadata
 *   ?action=delete&id=N POST    delete process           (processes.delete)
 *   ?action=publish&id=N POST   toggle draft/published   (processes.publish)
 *   ?action=archive&id=N POST   archive a process        (processes.publish)
 *   ?action=editor&id=N         structured-mode editor (nodes + edges + Mermaid preview)
 *   ?action=node_save  POST     create/update node
 *   ?action=node_delete POST    delete node (cascades edges)
 *   ?action=edge_save  POST     create/update edge
 *   ?action=edge_delete POST    delete edge
 *   ?action=upload&id=N POST    upload screenshot
 *   ?action=screenshot_delete POST
 *   ?action=revisions&id=N      revision history page
 *   ?action=revision_view&id=N&rev=R  view a single revision (read-only)
 *   ?action=revision_restore&id=N&rev=R POST  restore a revision
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_processes.php';
require_login();
require_permission('processes', 'view');

$action = (string)input('action', 'index');
$uid    = current_user_id();

$canCreate  = permission_check('processes', 'create');
$canManage  = permission_check('processes', 'manage');
$canPublish = permission_check('processes', 'publish');
$canDelete  = permission_check('processes', 'delete');

// =============================================================
// POST handlers
// =============================================================

if ($action === 'save') {
    csrf_check();
    $id        = (int)input('id', 0);
    $title     = trim((string)input('title'));
    $desc      = trim((string)input('description'));
    $body      = (string)input('body_html');
    $mode      = (string)input('mode', 'document');
    if (!in_array($mode, ['document', 'structured'], true)) $mode = 'document';
    $tags      = trim((string)input('tags')) ?: null;
    $ownerId   = (int)input('owner_id', 0) ?: null;
    $roles     = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('intval', $_POST['roles']) : [];

    if ($title === '') {
        flash_set('error', 'Title is required.');
        redirect($id ? url('/processes.php?action=edit&id=' . $id) : url('/processes.php?action=new'));
    }

    $slug = process_slugify($title);
    // Ensure slug uniqueness (append -2, -3, ...)
    $existingId = (int)db_val(
        "SELECT id FROM processes WHERE slug = ? AND id <> ?",
        [$slug, $id]
    );
    $i = 2;
    while ($existingId) {
        $candidate = substr($slug, 0, 60) . '-' . $i;
        $existingId = (int)db_val(
            "SELECT id FROM processes WHERE slug = ? AND id <> ?",
            [$candidate, $id]
        );
        if (!$existingId) { $slug = $candidate; break; }
        $i++;
        if ($i > 99) { $slug = substr($slug, 0, 56) . '-' . bin2hex(random_bytes(2)); break; }
    }

    if ($id) {
        require_permission('processes', 'manage');
        if (!process_passes_module_gate_for_id($id)) {
            flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
            redirect(url('/processes.php'));
        }
        db_exec(
            "UPDATE processes SET
                title = ?, slug = ?, description = ?, mode = ?, body_html = ?,
                tags = ?, owner_id = ?, updated_by = ?
             WHERE id = ?",
            [$title, $slug, $desc, $mode, $body, $tags,
             $ownerId, (int)$uid, $id]
        );
        process_save_revision($id, 'metadata', 'Metadata updated', $uid);
    } else {
        require_permission('processes', 'create');
        db_exec(
            "INSERT INTO processes
                (title, slug, description, mode, body_html, tags, owner_id, created_by, updated_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [$title, $slug, $desc, $mode, $body, $tags,
             $ownerId ?: $uid, (int)$uid, (int)$uid]
        );
        $id = (int)db_val('SELECT LAST_INSERT_ID()');
        process_save_revision($id, 'metadata', 'Process created', $uid);
    }
    db_exec("DELETE FROM process_role_access WHERE process_id = ?", [$id]);
    foreach ($roles as $rid) {
        db_exec(
            "INSERT INTO process_role_access (process_id, role_id) VALUES (?, ?)",
            [$id, $rid]
        );
    }
    flash_set('success', 'Process saved.');
    redirect(url('/processes.php?action=edit&id=' . $id));
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'delete');
    $id = (int)input('id', 0);
    if (!process_passes_module_gate_for_id($id)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    db_exec("DELETE FROM processes WHERE id = ?", [$id]);
    flash_set('success', 'Process deleted.');
    redirect(url('/processes.php'));
}

if ($action === 'publish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'publish');
    $id = (int)input('id', 0);
    $process = process_load($id);
    if (!$process) { flash_set('error', 'Process not found.'); redirect(url('/processes.php')); }
    if (!process_passes_module_gate($process['slug'])) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    if ($process['status'] === 'published') {
        db_exec("UPDATE processes SET status = 'draft', updated_by = ? WHERE id = ?", [(int)$uid, $id]);
        process_save_revision($id, 'publish', 'Reverted to draft', $uid);
        flash_set('success', 'Process moved back to draft.');
    } else {
        db_exec("UPDATE processes SET status = 'published', published_at = NOW(), updated_by = ? WHERE id = ?", [(int)$uid, $id]);
        process_save_revision($id, 'publish', 'Published', $uid);
        flash_set('success', 'Process published.');
    }
    redirect(url('/processes.php?action=view&id=' . $id));
}

if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'publish');
    $id = (int)input('id', 0);
    if (!process_passes_module_gate_for_id($id)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    db_exec("UPDATE processes SET status = 'archived', updated_by = ? WHERE id = ?", [(int)$uid, $id]);
    process_save_revision($id, 'archive', 'Archived', $uid);
    flash_set('success', 'Process archived.');
    redirect(url('/processes.php?action=view&id=' . $id));
}

// ============================================================
// NODE / EDGE CRUD (structured mode only)
// ============================================================
if ($action === 'node_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $processId = (int)input('process_id', 0);
    if (!process_passes_module_gate_for_id($processId)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    $nodeId    = (int)input('node_id', 0);
    $nodeKey   = trim((string)input('node_key'));
    $nodeType  = (string)input('node_type', 'step');
    $label     = trim((string)input('label'));
    $body      = trim((string)input('body')) ?: null;
    $refUrl    = trim((string)input('ref_url')) ?: null;

    if (!in_array($nodeType, ['start', 'end', 'step', 'action', 'decision', 'reference'], true)) {
        $nodeType = 'step';
    }
    if ($label === '') {
        flash_set('error', 'Label is required.');
        redirect(url('/processes.php?action=editor&id=' . $processId));
    }
    if ($nodeKey === '') {
        $nodeKey = process_next_node_key($processId);
    }
    try { process_validate_node_key($nodeKey); }
    catch (Exception $e) {
        flash_set('error', $e->getMessage());
        redirect(url('/processes.php?action=editor&id=' . $processId));
    }
    // Uniqueness check
    $dup = (int)db_val(
        "SELECT id FROM process_nodes WHERE process_id = ? AND node_key = ? AND id <> ?",
        [$processId, $nodeKey, $nodeId]
    );
    if ($dup) {
        flash_set('error', "Node key '$nodeKey' is already used in this process.");
        redirect(url('/processes.php?action=editor&id=' . $processId));
    }

    if ($nodeId) {
        if (!db_val("SELECT id FROM process_nodes WHERE id = ? AND process_id = ?", [$nodeId, $processId])) {
            flash_set('error', 'Node not found.');
            redirect(url('/processes.php?action=editor&id=' . $processId));
        }
        db_exec(
            "UPDATE process_nodes
                SET node_key = ?, node_type = ?, label = ?, body = ?, ref_url = ?
              WHERE id = ?",
            [$nodeKey, $nodeType, $label, $body, $refUrl, $nodeId]
        );
        process_save_revision($processId, 'node_edit',
            "Edited node $nodeKey ({$nodeType}): " . mb_substr($label, 0, 60), $uid);
        flash_set('success', 'Node updated.');
    } else {
        $next = (int)db_val("SELECT COALESCE(MAX(sort_order),0)+1 FROM process_nodes WHERE process_id = ?", [$processId]);
        db_exec(
            "INSERT INTO process_nodes
                (process_id, node_key, node_type, label, body, ref_url, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$processId, $nodeKey, $nodeType, $label, $body, $refUrl, $next]
        );
        process_save_revision($processId, 'node_add',
            "Added $nodeType node $nodeKey: " . mb_substr($label, 0, 60), $uid);
        flash_set('success', 'Node added.');
    }
    db_exec("UPDATE processes SET updated_by = ? WHERE id = ?", [(int)$uid, $processId]);
    redirect(url('/processes.php?action=editor&id=' . $processId));
}

if ($action === 'node_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $processId = (int)input('process_id', 0);
    if (!process_passes_module_gate_for_id($processId)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    $nodeId    = (int)input('node_id', 0);
    $node = db_one("SELECT node_key FROM process_nodes WHERE id = ? AND process_id = ?", [$nodeId, $processId]);
    if ($node) {
        db_exec("DELETE FROM process_nodes WHERE id = ?", [$nodeId]);
        process_save_revision($processId, 'node_delete', "Deleted node " . $node['node_key'], $uid);
        flash_set('success', 'Node deleted (along with any connecting edges).');
    }
    redirect(url('/processes.php?action=editor&id=' . $processId));
}

if ($action === 'edge_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $processId = (int)input('process_id', 0);
    if (!process_passes_module_gate_for_id($processId)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    $edgeId    = (int)input('edge_id', 0);
    $fromId    = (int)input('from_node_id', 0);
    $toId      = (int)input('to_node_id', 0);
    $label     = trim((string)input('label')) ?: null;
    $style     = (string)input('line_style', 'solid');
    if (!in_array($style, ['solid', 'dashed', 'thick'], true)) $style = 'solid';

    if (!$fromId || !$toId) {
        flash_set('error', 'Pick both From and To nodes.');
        redirect(url('/processes.php?action=editor&id=' . $processId));
    }
    // Verify both nodes belong to this process
    $okF = db_val("SELECT id FROM process_nodes WHERE id = ? AND process_id = ?", [$fromId, $processId]);
    $okT = db_val("SELECT id FROM process_nodes WHERE id = ? AND process_id = ?", [$toId, $processId]);
    if (!$okF || !$okT) {
        flash_set('error', 'One of the selected nodes does not belong to this process.');
        redirect(url('/processes.php?action=editor&id=' . $processId));
    }

    if ($edgeId) {
        if (!db_val("SELECT id FROM process_edges WHERE id = ? AND process_id = ?", [$edgeId, $processId])) {
            flash_set('error', 'Edge not found.');
            redirect(url('/processes.php?action=editor&id=' . $processId));
        }
        db_exec(
            "UPDATE process_edges
                SET from_node_id = ?, to_node_id = ?, label = ?, line_style = ?
              WHERE id = ?",
            [$fromId, $toId, $label, $style, $edgeId]
        );
        process_save_revision($processId, 'edge_edit', "Edited edge", $uid);
        flash_set('success', 'Edge updated.');
    } else {
        $next = (int)db_val("SELECT COALESCE(MAX(sort_order),0)+1 FROM process_edges WHERE process_id = ?", [$processId]);
        db_exec(
            "INSERT INTO process_edges
                (process_id, from_node_id, to_node_id, label, line_style, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$processId, $fromId, $toId, $label, $style, $next]
        );
        process_save_revision($processId, 'edge_add', "Added edge", $uid);
        flash_set('success', 'Edge added.');
    }
    db_exec("UPDATE processes SET updated_by = ? WHERE id = ?", [(int)$uid, $processId]);
    redirect(url('/processes.php?action=editor&id=' . $processId));
}

if ($action === 'edge_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $processId = (int)input('process_id', 0);
    if (!process_passes_module_gate_for_id($processId)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    $edgeId    = (int)input('edge_id', 0);
    db_exec("DELETE FROM process_edges WHERE id = ? AND process_id = ?", [$edgeId, $processId]);
    process_save_revision($processId, 'edge_delete', "Deleted edge", $uid);
    flash_set('success', 'Edge deleted.');
    redirect(url('/processes.php?action=editor&id=' . $processId));
}

// ============================================================
// SCREENSHOT CRUD
// ============================================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $id = (int)input('id', 0);
    if (!db_val("SELECT id FROM processes WHERE id = ?", [$id])) {
        flash_set('error', 'Process not found.');
        redirect(url('/processes.php'));
    }
    if (!process_passes_module_gate_for_id($id)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    if (empty($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Upload failed.');
        redirect(url('/processes.php?action=edit&id=' . $id));
    }
    $maxBytes = (int)$GLOBALS['APP']['upload_max_mb'] * 1024 * 1024;
    if ($_FILES['screenshot']['size'] > $maxBytes) {
        flash_set('error', 'File too large.');
        redirect(url('/processes.php?action=edit&id=' . $id));
    }
    $info = @getimagesize($_FILES['screenshot']['tmp_name']);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        flash_set('error', 'Only PNG, JPG, GIF or WEBP are accepted.');
        redirect(url('/processes.php?action=edit&id=' . $id));
    }
    $ext = $allowed[$info[2]];
    $dir = __DIR__ . '/uploads/processes';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = 'proc' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['screenshot']['tmp_name'], $dir . '/' . $fname)) {
        flash_set('error', 'Could not save uploaded file.');
        redirect(url('/processes.php?action=edit&id=' . $id));
    }
    $caption = trim((string)input('caption'));
    $nextOrder = (int)db_val("SELECT COALESCE(MAX(sort_order),0)+1 FROM process_screenshots WHERE process_id = ?", [$id]);
    db_exec(
        "INSERT INTO process_screenshots (process_id, file_path, caption, sort_order, uploaded_by)
         VALUES (?, ?, ?, ?, ?)",
        [$id, 'uploads/processes/' . $fname, $caption, $nextOrder, (int)$uid]
    );
    flash_set('success', 'Screenshot uploaded.');
    redirect(url('/processes.php?action=edit&id=' . $id));
}

if ($action === 'screenshot_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $sid = (int)input('sid', 0);
    $row = db_one("SELECT * FROM process_screenshots WHERE id = ?", [$sid]);
    if ($row && !process_passes_module_gate_for_id($row['process_id'])) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    if ($row) {
        if (strpos($row['file_path'], 'uploads/') === 0) {
            $full = __DIR__ . '/' . $row['file_path'];
            if (is_file($full)) @unlink($full);
        }
        db_exec("DELETE FROM process_screenshots WHERE id = ?", [$sid]);
        flash_set('success', 'Screenshot removed.');
    }
    redirect(url('/processes.php?action=edit&id=' . (int)($row['process_id'] ?? 0)));
}

// ============================================================
// REVISIONS — restore
// ============================================================
if ($action === 'revision_restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('processes', 'manage');
    $id = (int)input('id', 0);
    if (!process_passes_module_gate_for_id($id)) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    $rev = (int)input('rev', 0);
    try {
        process_restore_revision($id, $rev, $uid);
        flash_set('success', "Restored process from revision $rev.");
    } catch (Exception $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(url('/processes.php?action=view&id=' . $id));
}

// =============================================================
// GET pages
// =============================================================

if ($action === 'editor') {
    require_permission('processes', 'manage');
    $id = (int)input('id', 0);
    $process = process_load($id);
    if (!$process) { flash_set('error', 'Process not found.'); redirect(url('/processes.php')); }
    // Module-permission gate applies to editor too.
    if (!process_passes_module_gate($process['slug'])) {
        flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
        redirect(url('/processes.php'));
    }
    if ($process['mode'] !== 'structured') {
        flash_set('error', 'This process is in document mode — switch mode to structured on the metadata page.');
        redirect(url('/processes.php?action=edit&id=' . $id));
    }
    $nodes  = process_load_nodes($id);
    $edges  = process_load_edges($id);
    $mermaid = process_render_mermaid($id);

    // Optionally pre-fill the node/edge form when ?edit_node or ?edit_edge in URL
    $editingNode = null; $editingEdge = null;
    if ($eid = (int)input('edit_node', 0)) {
        $editingNode = db_one("SELECT * FROM process_nodes WHERE id = ? AND process_id = ?", [$eid, $id]);
    }
    if ($eid = (int)input('edit_edge', 0)) {
        $editingEdge = db_one("SELECT * FROM process_edges WHERE id = ? AND process_id = ?", [$eid, $id]);
    }

    $page_title  = 'Edit flow: ' . $process['title'];
    $page_module = 'processes';
    require __DIR__ . '/includes/header.php';
    render_process_editor($process, $nodes, $edges, $mermaid, $editingNode, $editingEdge);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'revisions') {
    $id = (int)input('id', 0);
    $process = process_load($id);
    if (!$process) { flash_set('error', 'Process not found.'); redirect(url('/processes.php')); }
    // Module gate applies; no canManage bypass (see view action note).
    if (!process_user_can_view($uid, $id)) {
        flash_set('error', 'Access denied.');
        redirect(url('/processes.php'));
    }
    $revisions = process_load_revisions($id);

    $page_title  = 'Revisions: ' . $process['title'];
    $page_module = 'processes';
    require __DIR__ . '/includes/header.php';
    render_process_revisions($process, $revisions);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'revision_view') {
    $id = (int)input('id', 0);
    $rev = (int)input('rev', 0);
    $process = process_load($id);
    if (!$process) { flash_set('error', 'Process not found.'); redirect(url('/processes.php')); }
    if (!process_user_can_view($uid, $id)) {
        flash_set('error', 'Access denied.');
        redirect(url('/processes.php'));
    }
    $r = process_load_revision($id, $rev);
    if (!$r) { flash_set('error', 'Revision not found.'); redirect(url('/processes.php?action=revisions&id=' . $id)); }

    $page_title  = 'Revision ' . $rev . ': ' . $process['title'];
    $page_module = 'processes';
    require __DIR__ . '/includes/header.php';
    render_process_revision_view($process, $r);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $id = (int)input('id', 0);
    $process = process_load($id);
    if (!$process) { flash_set('error', 'Process not found.'); redirect(url('/processes.php')); }
    // Module-permission gate is enforced INSIDE process_user_can_view; we
    // intentionally do NOT add a `|| $canManage` fallback here, because
    // processes.manage must not override the module gate (e.g. an admin
    // of the Processes module without cmm.view must not see the CMM flow).
    if (!process_user_can_view($uid, $id)) {
        flash_set('error', 'Access denied.');
        redirect(url('/processes.php'));
    }
    $screens  = db_all("SELECT * FROM process_screenshots WHERE process_id = ? ORDER BY sort_order, id", [$id]);
    $mermaid  = $process['mode'] === 'structured' ? process_render_mermaid($id) : '';
    $revCount = (int)db_val("SELECT COUNT(*) FROM process_revisions WHERE process_id = ?", [$id]);

    $page_title  = $process['title'];
    $page_module = 'processes';
    require __DIR__ . '/includes/header.php';
    render_process_view($process, $screens, $mermaid, $revCount);
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'new' || $action === 'edit') {
    $editing = null;
    $processRoles = [];
    if ($action === 'edit') {
        require_permission('processes', 'manage');
        $id = (int)input('id', 0);
        $editing = process_load($id);
        if (!$editing) { flash_set('error', 'Process not found.'); redirect(url('/processes.php')); }
        // Module-permission gate applies to edit too — an admin of the
        // Processes module can't edit prose about a module they don't
        // have view permission for.
        if (!process_passes_module_gate($editing['slug'])) {
            flash_set('error', 'Access denied: you lack permission for this process flow\'s module.');
            redirect(url('/processes.php'));
        }
        $processRoles = array_column(
            db_all("SELECT role_id FROM process_role_access WHERE process_id = ?", [$id]),
            'role_id'
        );
    } else {
        require_permission('processes', 'create');
    }
    $allRoles = db_all("SELECT * FROM roles ORDER BY name");
    $allUsers = db_all("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
    $screens  = $editing ? db_all("SELECT * FROM process_screenshots WHERE process_id = ? ORDER BY sort_order, id", [$editing['id']]) : [];

    $page_title  = $editing ? 'Edit process' : 'New process';
    $page_module = 'processes';
    require __DIR__ . '/includes/header.php';
    render_process_form($editing, $processRoles, $allRoles, $allUsers, $screens);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// Default: list page
// ============================================================
$visible = $canManage ? process_visible_to_user($uid, true) : process_visible_to_user($uid, false);
$page_title  = 'Process flows';
$page_module = 'processes';
require __DIR__ . '/includes/header.php';
render_process_list($visible);
require __DIR__ . '/includes/footer.php';

// =============================================================
// RENDERERS
// =============================================================

function render_process_list($visible) {
    global $canCreate, $canManage;
    ?>
    <div class="page-head">
        <div>
            <h1>Process flows</h1>
            <p class="muted">SOPs, work instructions, decision trees. Each process can be a document or a structured node graph.</p>
        </div>
        <div class="head-actions">
            <?php if ($canCreate): ?>
                <a class="btn btn-primary" href="<?= h(url('/processes.php?action=new')) ?>">+ New process</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Mode</th>
                    <th>Status</th>
                    <th>Tags</th>
                    <th>Owner</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($visible)): ?>
                    <tr><td colspan="7" class="empty">
                        <?= $canManage
                            ? 'No processes yet. Click "+ New process" to add one.'
                            : 'No processes are currently visible to your role(s).' ?>
                    </td></tr>
                <?php else: foreach ($visible as $p):
                    $pill = process_status_pill($p['status']);
                ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="<?= h(url('/processes.php?action=view&id=' . (int)$p['id'])) ?>">
                                    <?= h($p['title']) ?>
                                </a>
                            </strong>
                            <?php if ($p['description']): ?>
                                <br><span class="muted small"><?= h($p['description']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['mode'] === 'structured'): ?>
                                <span class="pill pill-info">structured</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">document</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill <?= h($pill['class']) ?>"><?= h($pill['label']) ?></span></td>
                        <td class="muted small"><?= h($p['tags'] ?: '') ?></td>
                        <td class="muted small"><?= h(($p['owner_name'] ?? '') ?: '—') ?></td>
                        <td class="muted small"><?= h(dt_display($p['updated_at'])) ?></td>
                        <td class="r nowrap">
                            <a class="btn btn-xs btn-ghost"
                               href="<?= h(url('/processes.php?action=view&id=' . (int)$p['id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function render_process_view($process, $screens, $mermaid, $revCount) {
    global $canManage, $canPublish, $canDelete, $uid;
    $pill = process_status_pill($process['status']);
    ?>
    <div class="page-head">
        <div>
            <h1>
                <?= h($process['title']) ?>
                <span class="pill <?= h($pill['class']) ?>" style="margin-left: 10px; font-size: 11px;">
                    <?= h($pill['label']) ?>
                </span>
            </h1>
            <?php if ($process['description']): ?>
                <p class="muted"><?= h($process['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/processes.php')) ?>">← All processes</a>
            <a class="btn btn-ghost" href="<?= h(url('/processes.php?action=revisions&id=' . (int)$process['id'])) ?>">
                History (<?= $revCount ?>)
            </a>
            <?php if ($canManage): ?>
                <a class="btn btn-ghost" href="<?= h(url('/processes.php?action=edit&id=' . (int)$process['id'])) ?>">Edit</a>
                <?php if ($process['mode'] === 'structured'): ?>
                    <a class="btn btn-primary" href="<?= h(url('/processes.php?action=editor&id=' . (int)$process['id'])) ?>">
                        🔧 Editor
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canPublish): ?>
                <?php if ($process['status'] === 'published'): ?>
                    <form method="post" action="<?= h(url('/processes.php?action=publish&id=' . (int)$process['id'])) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-warning"
                                onclick="return confirm('Move back to draft? Non-admins will no longer see it.');">
                            Unpublish
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= h(url('/processes.php?action=publish&id=' . (int)$process['id'])) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-success">Publish</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($process['tags']): ?>
        <div style="margin-bottom: 14px;">
            <span class="muted small">Tags:</span>
            <?php foreach (explode(',', $process['tags']) as $t):
                $t = trim($t); if ($t === '') continue;
            ?>
                <span class="pill pill-info" style="font-size: 11px; margin-left: 4px;"><?= h($t) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($process['mode'] === 'structured' && $mermaid): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Process flow</h3></div>
            <div class="card-body">
                <pre class="mermaid" style="background: #fff; text-align: center;"><?= h($mermaid) ?></pre>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
        <script>
            // Robust Mermaid rendering for SPA navigation.
            // Three scenarios this handles:
            //   1. First page load: script tag executes, Mermaid attaches, we call run().
            //   2. SPA re-navigation: spa.js re-creates this <script>; window.mermaid
            //      already exists from a prior visit; startOnLoad fired against an
            //      earlier DOM. We re-run against the CURRENT .mermaid blocks.
            //   3. Race: script tag is added to DOM but hasn't loaded yet; we wait
            //      until window.mermaid appears, then run.
            (function () {
                function renderAll() {
                    if (!window.mermaid) return false;
                    if (!window.__magdynMermaidInitDone) {
                        window.mermaid.initialize({
                            startOnLoad: false,    // we drive it ourselves
                            theme: 'default',
                            securityLevel: 'loose'
                        });
                        window.__magdynMermaidInitDone = true;
                    }
                    // Find every .mermaid block on the current page that hasn't
                    // been processed yet (Mermaid leaves a data-processed flag).
                    var blocks = document.querySelectorAll('.mermaid:not([data-processed="true"])');
                    if (blocks.length === 0) return true;
                    try {
                        // Mermaid 10.x async API
                        window.mermaid.run({ nodes: blocks });
                    } catch (e) {
                        // Fallback for older API shape
                        try { window.mermaid.init(undefined, blocks); }
                        catch (_) { console.error('Mermaid render failed', e); }
                    }
                    return true;
                }
                if (!renderAll()) {
                    // Script not loaded yet — poll briefly
                    var tries = 0;
                    var iv = setInterval(function () {
                        if (renderAll() || ++tries > 40) clearInterval(iv);
                    }, 100);
                }
            })();
        </script>
    <?php endif; ?>

    <?php if ($process['body_html']): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Details</h3></div>
            <div class="card-body"><?= $process['body_html'] ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($screens)): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Screenshots</h3></div>
            <div class="card-body">
                <div class="screenshot-strip">
                    <?php foreach ($screens as $s): ?>
                        <figure>
                            <img src="<?= h(url('/' . ltrim($s['file_path'], '/'))) ?>" alt="<?= h($s['caption']) ?>">
                            <figcaption><?= h($s['caption'] ?: 'Screenshot') ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php
}

function render_process_form($editing, $processRoles, $allRoles, $allUsers, $screens) {
    global $canDelete;
    ?>
    <div class="page-head">
        <div>
            <h1><?= $editing ? 'Edit process' : 'New process' ?></h1>
            <p class="muted">
                <?= $editing
                    ? 'Edit the metadata, mode and roles. For structured processes, use the Editor to manage nodes and edges.'
                    : 'Create a new SOP / work instruction / decision tree.' ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url($editing ? '/processes.php?action=view&id=' . (int)$editing['id'] : '/processes.php')) ?>">← Back</a>
        </div>
    </div>

    <form method="post" action="<?= h(url('/processes.php?action=save')) ?>">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Basics</h3>
            <div class="form-grid-2">
                <div class="field span-2">
                    <label>Title <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="title" required maxlength="200"
                           value="<?= h($editing['title'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label>Description</label>
                    <input type="text" name="description" maxlength="500"
                           value="<?= h($editing['description'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Mode</label>
                    <select name="mode">
                        <option value="document"   <?= (($editing['mode'] ?? 'document') === 'document')   ? 'selected' : '' ?>>
                            Document — rich body + screenshots
                        </option>
                        <option value="structured" <?= (($editing['mode'] ?? '') === 'structured') ? 'selected' : '' ?>>
                            Structured — node graph with auto-generated flowchart
                        </option>
                    </select>
                    <span class="field-hint">Document mode is best for prose SOPs. Structured mode generates a Mermaid flowchart from typed nodes (use the Editor after saving).</span>
                </div>
                <div class="field">
                    <label>Tags <span class="muted small">(comma-separated)</span></label>
                    <input type="text" name="tags" maxlength="255" placeholder="e.g. safety, machining, weekly"
                           value="<?= h($editing['tags'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Owner</label>
                    <select name="owner_id">
                        <option value="">— none —</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"
                                    <?= ($editing && (int)$editing['owner_id'] === (int)$u['id']) ? 'selected' : '' ?>>
                                <?= h($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Body</h3>
            <div class="field">
                <label>Rich body (HTML allowed)</label>
                <textarea name="body_html" rows="10"
                          style="font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;"><?= h($editing['body_html'] ?? '') ?></textarea>
                <span class="field-hint">For document mode this IS the process. For structured mode, body shows beneath the diagram as context / notes.</span>
            </div>
        </div>

        <div class="card form-card" style="margin-bottom: 18px;">
            <h3 style="margin: 0 0 14px; font-size: 14px;">Role access</h3>
            <div class="field">
                <label>Roles that can view this process when published</label>
                <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:4px;">
                    <?php foreach ($allRoles as $r): ?>
                        <label class="nowrap" style="font-weight:normal;">
                            <input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>"
                                   <?= in_array($r['id'], $processRoles) ? 'checked' : '' ?>>
                            <?= h($r['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?= $editing ? 'Save changes' : 'Create process' ?>
            </button>
            <?php if ($editing && $canDelete): ?>
                <form method="post" style="display: inline;"
                      action="<?= h(url('/processes.php?action=delete')) ?>"
                      onsubmit="return confirm('Delete this process and all its nodes / edges / revisions?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($editing): ?>
        <div class="form-section" style="margin-top: 24px;">
            <h2>Screenshots <span class="muted small" style="font-weight: normal;">(<?= count($screens) ?>)</span></h2>
            <form method="post" enctype="multipart/form-data"
                  action="<?= h(url('/processes.php?action=upload&id=' . (int)$editing['id'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <div class="form-grid">
                    <div class="field span-2">
                        <label>Image</label>
                        <input name="screenshot" type="file"
                               accept="image/png,image/jpeg,image/gif,image/webp" required>
                    </div>
                    <div class="field span-2">
                        <label>Caption (optional)</label>
                        <input name="caption" type="text">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary btn-sm" type="submit">Upload</button>
                </div>
            </form>
            <?php if (!empty($screens)): ?>
                <div class="screenshot-strip" style="margin-top: 16px;">
                    <?php foreach ($screens as $s): ?>
                        <figure>
                            <img src="<?= h(url('/' . ltrim($s['file_path'], '/'))) ?>" alt="<?= h($s['caption']) ?>">
                            <figcaption>
                                <?= h($s['caption'] ?: 'Screenshot') ?>
                                <form method="post" style="float:right; margin-top:-2px;"
                                      action="<?= h(url('/processes.php?action=screenshot_delete')) ?>"
                                      onsubmit="return confirm('Remove this screenshot?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
                                    <button class="btn btn-xs btn-ghost" type="submit">Remove</button>
                                </form>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
}

function render_process_editor($process, $nodes, $edges, $mermaid, $editingNode, $editingEdge) {
    ?>
    <div class="page-head">
        <div>
            <h1>🔧 Flow editor: <?= h($process['title']) ?></h1>
            <p class="muted">Add nodes, connect them with edges. The diagram on the right re-renders after every save.</p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/processes.php?action=view&id=' . (int)$process['id'])) ?>">← Back to view</a>
            <a class="btn btn-ghost" href="<?= h(url('/processes.php?action=edit&id=' . (int)$process['id'])) ?>">Edit metadata</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

        <!-- LEFT: nodes + edges editing -->
        <div>
            <!-- Nodes panel -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Nodes <span class="muted small">(<?= count($nodes) ?>)</span></h3></div>
                <div class="card-body">
                    <?php if (!empty($nodes)): ?>
                        <table class="data-table" style="margin-bottom: 14px;">
                            <thead><tr><th>Key</th><th>Type</th><th>Label</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($nodes as $n):
                                    $typePill = [
                                        'start'     => 'pill-success',
                                        'end'       => 'pill-danger',
                                        'decision'  => 'pill-warning',
                                        'action'    => 'pill-info',
                                        'reference' => 'pill-neutral',
                                        'step'      => 'pill-neutral',
                                    ][$n['node_type']] ?? 'pill-neutral';
                                ?>
                                    <tr>
                                        <td><code><?= h($n['node_key']) ?></code></td>
                                        <td><span class="pill <?= h($typePill) ?>"><?= h($n['node_type']) ?></span></td>
                                        <td><?= h(mb_substr($n['label'], 0, 60)) ?><?= mb_strlen($n['label']) > 60 ? '…' : '' ?></td>
                                        <td class="r nowrap">
                                            <a class="btn btn-xs btn-ghost"
                                               href="<?= h(url('/processes.php?action=editor&id=' . (int)$process['id'] . '&edit_node=' . (int)$n['id'])) ?>">Edit</a>
                                            <form method="post" action="<?= h(url('/processes.php?action=node_delete')) ?>"
                                                  style="display:inline;"
                                                  onsubmit="return confirm('Delete this node? Connected edges will also be removed.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="process_id" value="<?= (int)$process['id'] ?>">
                                                <input type="hidden" name="node_id" value="<?= (int)$n['id'] ?>">
                                                <button class="btn btn-xs btn-ghost" type="submit">×</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <details <?= $editingNode ? 'open' : '' ?>>
                        <summary style="cursor: pointer; font-weight: 600;">
                            <?= $editingNode ? '✎ Editing node: ' . h($editingNode['node_key']) : '+ Add node' ?>
                        </summary>
                        <form method="post" action="<?= h(url('/processes.php?action=node_save')) ?>" style="margin-top: 12px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="process_id" value="<?= (int)$process['id'] ?>">
                            <?php if ($editingNode): ?>
                                <input type="hidden" name="node_id" value="<?= (int)$editingNode['id'] ?>">
                            <?php endif; ?>
                            <div class="form-grid-2">
                                <div class="field">
                                    <label>Node key</label>
                                    <input type="text" name="node_key" maxlength="32"
                                           value="<?= h($editingNode['node_key'] ?? '') ?>"
                                           placeholder="auto-generate if blank (n1, n2, …)">
                                    <span class="field-hint">Letters / digits / underscores; used in Mermaid.</span>
                                </div>
                                <div class="field">
                                    <label>Type</label>
                                    <select name="node_type">
                                        <?php foreach (['start','step','decision','action','reference','end'] as $t):
                                            $sel = ($editingNode['node_type'] ?? 'step') === $t ? 'selected' : ''; ?>
                                            <option value="<?= $t ?>" <?= $sel ?>><?= ucfirst($t) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field span-2">
                                    <label>Label <span style="color: var(--danger);">*</span></label>
                                    <input type="text" name="label" required maxlength="255"
                                           value="<?= h($editingNode['label'] ?? '') ?>">
                                </div>
                                <div class="field span-2">
                                    <label>Body / notes <span class="muted small">(optional, shown on click)</span></label>
                                    <textarea name="body" rows="2"><?= h($editingNode['body'] ?? '') ?></textarea>
                                </div>
                                <div class="field span-2">
                                    <label>Reference URL <span class="muted small">(only for 'reference' nodes)</span></label>
                                    <input type="text" name="ref_url" maxlength="500"
                                           value="<?= h($editingNode['ref_url'] ?? '') ?>"
                                           placeholder="e.g. /documents.php?action=view&id=42">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <?= $editingNode ? 'Update node' : '+ Add node' ?>
                                </button>
                                <?php if ($editingNode): ?>
                                    <a class="btn btn-ghost btn-sm"
                                       href="<?= h(url('/processes.php?action=editor&id=' . (int)$process['id'])) ?>">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </details>
                </div>
            </div>

            <!-- Edges panel -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Edges <span class="muted small">(<?= count($edges) ?>)</span></h3></div>
                <div class="card-body">
                    <?php if (!empty($edges)): ?>
                        <table class="data-table" style="margin-bottom: 14px;">
                            <thead><tr><th>From</th><th>To</th><th>Label</th><th>Style</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($edges as $e): ?>
                                    <tr>
                                        <td><code><?= h($e['from_key']) ?></code></td>
                                        <td><code><?= h($e['to_key']) ?></code></td>
                                        <td><?= h($e['label'] ?: '') ?></td>
                                        <td><span class="pill pill-neutral"><?= h($e['line_style']) ?></span></td>
                                        <td class="r nowrap">
                                            <a class="btn btn-xs btn-ghost"
                                               href="<?= h(url('/processes.php?action=editor&id=' . (int)$process['id'] . '&edit_edge=' . (int)$e['id'])) ?>">Edit</a>
                                            <form method="post" action="<?= h(url('/processes.php?action=edge_delete')) ?>"
                                                  style="display:inline;"
                                                  onsubmit="return confirm('Delete this edge?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="process_id" value="<?= (int)$process['id'] ?>">
                                                <input type="hidden" name="edge_id" value="<?= (int)$e['id'] ?>">
                                                <button class="btn btn-xs btn-ghost" type="submit">×</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (count($nodes) < 2): ?>
                        <p class="muted">Add at least two nodes before connecting edges.</p>
                    <?php else: ?>
                        <details <?= $editingEdge ? 'open' : '' ?>>
                            <summary style="cursor: pointer; font-weight: 600;">
                                <?= $editingEdge ? '✎ Editing edge' : '+ Add edge' ?>
                            </summary>
                            <form method="post" action="<?= h(url('/processes.php?action=edge_save')) ?>" style="margin-top: 12px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="process_id" value="<?= (int)$process['id'] ?>">
                                <?php if ($editingEdge): ?>
                                    <input type="hidden" name="edge_id" value="<?= (int)$editingEdge['id'] ?>">
                                <?php endif; ?>
                                <div class="form-grid-2">
                                    <div class="field">
                                        <label>From node <span style="color: var(--danger);">*</span></label>
                                        <select name="from_node_id" required>
                                            <option value="">— pick —</option>
                                            <?php foreach ($nodes as $n): ?>
                                                <option value="<?= (int)$n['id'] ?>"
                                                        <?= ($editingEdge && (int)$editingEdge['from_node_id'] === (int)$n['id']) ? 'selected' : '' ?>>
                                                    <?= h($n['node_key']) ?> — <?= h(mb_substr($n['label'], 0, 40)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>To node <span style="color: var(--danger);">*</span></label>
                                        <select name="to_node_id" required>
                                            <option value="">— pick —</option>
                                            <?php foreach ($nodes as $n): ?>
                                                <option value="<?= (int)$n['id'] ?>"
                                                        <?= ($editingEdge && (int)$editingEdge['to_node_id'] === (int)$n['id']) ? 'selected' : '' ?>>
                                                    <?= h($n['node_key']) ?> — <?= h(mb_substr($n['label'], 0, 40)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Label <span class="muted small">(optional)</span></label>
                                        <input type="text" name="label" maxlength="120"
                                               value="<?= h($editingEdge['label'] ?? '') ?>"
                                               placeholder="e.g. Yes / No / 'over limit'">
                                    </div>
                                    <div class="field">
                                        <label>Line style</label>
                                        <select name="line_style">
                                            <?php foreach (['solid','dashed','thick'] as $ls):
                                                $sel = ($editingEdge['line_style'] ?? 'solid') === $ls ? 'selected' : ''; ?>
                                                <option value="<?= $ls ?>" <?= $sel ?>><?= ucfirst($ls) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <?= $editingEdge ? 'Update edge' : '+ Add edge' ?>
                                    </button>
                                    <?php if ($editingEdge): ?>
                                        <a class="btn btn-ghost btn-sm"
                                           href="<?= h(url('/processes.php?action=editor&id=' . (int)$process['id'])) ?>">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Mermaid preview -->
        <div>
            <div class="card" style="position: sticky; top: 10px;">
                <div class="card-head">
                    <h3 style="margin: 0; font-size: 15px;">Live preview</h3>
                </div>
                <div class="card-body" style="overflow: auto;">
                    <?php if (count($nodes) === 0): ?>
                        <p class="muted">Add at least one node to see the diagram.</p>
                    <?php else: ?>
                        <pre class="mermaid" style="background: #fff; text-align: center;"><?= h($mermaid) ?></pre>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="border-top: 1px solid #e5e7eb;">
                    <details>
                        <summary class="muted small" style="cursor: pointer;">View Mermaid source</summary>
                        <pre style="font-size: 11px; background: #f9fafb; padding: 10px; border-radius: 4px; margin-top: 8px; overflow: auto;"><?= h($mermaid) ?></pre>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
    <script>
        // See render_process_view for the rationale; same SPA hardening.
        (function () {
            function renderAll() {
                if (!window.mermaid) return false;
                if (!window.__magdynMermaidInitDone) {
                    window.mermaid.initialize({
                        startOnLoad: false,
                        theme: 'default',
                        securityLevel: 'loose'
                    });
                    window.__magdynMermaidInitDone = true;
                }
                var blocks = document.querySelectorAll('.mermaid:not([data-processed="true"])');
                if (blocks.length === 0) return true;
                try {
                    window.mermaid.run({ nodes: blocks });
                } catch (e) {
                    try { window.mermaid.init(undefined, blocks); }
                    catch (_) { console.error('Mermaid render failed', e); }
                }
                return true;
            }
            if (!renderAll()) {
                var tries = 0;
                var iv = setInterval(function () {
                    if (renderAll() || ++tries > 40) clearInterval(iv);
                }, 100);
            }
        })();
    </script>
    <?php
}

function render_process_revisions($process, $revisions) {
    global $canManage;
    ?>
    <div class="page-head">
        <div>
            <h1>Revisions: <?= h($process['title']) ?></h1>
            <p class="muted">Every save creates a revision. Click any revision to view its snapshot; admins can restore from any revision.</p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/processes.php?action=view&id=' . (int)$process['id'])) ?>">← Back</a>
        </div>
    </div>

    <div class="card">
        <table class="data-table">
            <thead><tr><th>Rev #</th><th>Kind</th><th>Summary</th><th>Actor</th><th>When</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($revisions)): ?>
                    <tr><td colspan="6" class="empty">No revisions yet.</td></tr>
                <?php else: foreach ($revisions as $r): ?>
                    <tr>
                        <td><strong><?= (int)$r['rev_no'] ?></strong></td>
                        <td><span class="pill pill-neutral"><?= h(str_replace('_', ' ', $r['change_kind'])) ?></span></td>
                        <td><?= h($r['change_summary']) ?></td>
                        <td><?= h($r['actor_name'] ?: '—') ?></td>
                        <td class="muted small"><?= h(dt_display($r['created_at'])) ?></td>
                        <td class="r nowrap">
                            <a class="btn btn-xs btn-ghost"
                               href="<?= h(url('/processes.php?action=revision_view&id=' . (int)$process['id'] . '&rev=' . (int)$r['rev_no'])) ?>">View</a>
                            <?php if ($canManage): ?>
                                <form method="post" style="display:inline;"
                                      action="<?= h(url('/processes.php?action=revision_restore')) ?>"
                                      onsubmit="return confirm('Restore the process to revision <?= (int)$r['rev_no'] ?>? Current state will be overwritten but kept as a new revision.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$process['id'] ?>">
                                    <input type="hidden" name="rev" value="<?= (int)$r['rev_no'] ?>">
                                    <button class="btn btn-xs btn-warning" type="submit">Restore</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function render_process_revision_view($process, $rev) {
    $snap = json_decode($rev['snapshot_json'], true);
    if (!is_array($snap)) {
        echo '<div class="alert alert-error">Revision snapshot is corrupt or unreadable.</div>';
        return;
    }
    $sp = isset($snap['process']) ? $snap['process'] : [];
    $sn = isset($snap['nodes'])   ? $snap['nodes']   : [];
    $se = isset($snap['edges'])   ? $snap['edges']   : [];
    ?>
    <div class="page-head">
        <div>
            <h1>Revision <?= (int)$rev['rev_no'] ?>: <?= h($process['title']) ?></h1>
            <p class="muted">
                <?= h(str_replace('_', ' ', $rev['change_kind'])) ?>
                · by <?= h($rev['actor_name'] ?: '—') ?>
                · <?= h(dt_display($rev['created_at'])) ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/processes.php?action=revisions&id=' . (int)$process['id'])) ?>">← All revisions</a>
        </div>
    </div>

    <?php if ($rev['change_summary']): ?>
        <p><strong>Change:</strong> <?= h($rev['change_summary']) ?></p>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 18px;">
        <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Process state at this revision</h3></div>
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr><th>Title</th><td><?= h($sp['title'] ?? '') ?></td></tr>
                    <tr><th>Mode</th><td><?= h($sp['mode'] ?? '') ?></td></tr>
                    <tr><th>Status</th><td><?= h($sp['status'] ?? '') ?></td></tr>
                    <tr><th>Description</th><td><?= h($sp['description'] ?? '') ?></td></tr>
                    <tr><th>Tags</th><td><?= h($sp['tags'] ?? '') ?></td></tr>
                    <tr><th>Nodes</th><td><?= count($sn) ?></td></tr>
                    <tr><th>Edges</th><td><?= count($se) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($sn)): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Nodes at this revision</h3></div>
            <div class="card-body">
                <table class="data-table">
                    <thead><tr><th>Key</th><th>Type</th><th>Label</th></tr></thead>
                    <tbody>
                        <?php foreach ($sn as $n): ?>
                            <tr>
                                <td><code><?= h($n['node_key']) ?></code></td>
                                <td><?= h($n['node_type']) ?></td>
                                <td><?= h($n['label']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($sp['body_html'])): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Body (snapshot)</h3></div>
            <div class="card-body"><?= $sp['body_html'] ?></div>
        </div>
    <?php endif; ?>
    <?php
}
