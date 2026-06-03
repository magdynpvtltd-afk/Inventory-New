<?php
/**
 * MagDyn — NDA Template library
 *
 * Stores the master NDA templates that empanelment applications
 * reference. Operators upload PDF/DOC templates; they get listed,
 * downloaded, activated/deactivated, or deleted (when nothing
 * references them).
 *
 * Actions:
 *   (default)       list + upload form
 *   action=download&id=N   stream the template file
 *   action=toggle_active&id=N  flip is_active
 *   action=delete&id=N    delete (refused if any application uses it)
 *   action=upload (POST)  add new template
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_vendor_empanelment.php';

require_permission('nda_templates', 'view');
$action = (string)input('action', 'list');
$uid    = current_user_id();
$canManage = permission_check('nda_templates', 'manage');


// ============================================================
// DOWNLOAD
// ============================================================
if ($action === 'download') {
    $id = (int)input('id', 0);
    $t = ve_nda_template_get($id);
    if (!$t) { http_response_code(404); echo 'Template not found.'; exit; }
    $abs = __DIR__ . '/' . ltrim($t['file_path'], '/');
    if (!is_file($abs)) { http_response_code(404); echo 'File missing on disk.'; exit; }
    header('Content-Type: ' . ($t['file_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . addslashes($t['file_name']) . '"');
    readfile($abs);
    exit;
}


// ============================================================
// UPLOAD
// ============================================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) require_permission('nda_templates', 'manage');
    csrf_check();
    $name        = trim((string)input('name', ''));
    $version     = trim((string)input('version', '1.0'));
    $description = trim((string)input('description', '')) ?: null;
    $isActive    = (int)!!input('is_active', 0);
    $notes       = trim((string)input('notes', '')) ?: null;

    if ($name === '' || $version === '') {
        flash_set('error', 'Name and version are required.');
        redirect(url('/nda_templates.php'));
    }
    if (empty($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Pick a template file to upload.');
        redirect(url('/nda_templates.php'));
    }
    if ((int)$_FILES['file']['size'] > 15 * 1024 * 1024) {
        flash_set('error', 'File too large (15 MB max).');
        redirect(url('/nda_templates.php'));
    }
    $orig = basename($_FILES['file']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) {
        flash_set('error', 'NDA templates must be PDF, DOC, or DOCX.');
        redirect(url('/nda_templates.php'));
    }

    // Store at uploads/nda_templates/
    $dir = __DIR__ . '/uploads/nda_templates';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    $stored = date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
    $abs = $dir . '/' . $stored;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $abs)) {
        flash_set('error', 'Failed to save the uploaded file.');
        redirect(url('/nda_templates.php'));
    }
    $rel = 'uploads/nda_templates/' . $stored;

    db_exec(
        "INSERT INTO nda_templates
            (name, version, description, file_name, file_path, file_mime, file_size,
             is_active, notes, created_by)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$name, $version, $description, $orig, $rel,
         @mime_content_type($abs), (int)filesize($abs),
         $isActive, $notes, (int)$uid]
    );
    flash_set('success', "Template '$name' v$version uploaded.");
    redirect(url('/nda_templates.php'));
}


// ============================================================
// TOGGLE ACTIVE
// ============================================================
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) require_permission('nda_templates', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    db_exec(
        "UPDATE nda_templates SET is_active = 1 - is_active WHERE id = ?",
        [$id]
    );
    flash_set('success', 'Active flag toggled.');
    redirect(url('/nda_templates.php'));
}


// ============================================================
// DELETE
// ============================================================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) require_permission('nda_templates', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $t = ve_nda_template_get($id);
    if (!$t) { flash_set('error', 'Not found.'); redirect(url('/nda_templates.php')); }

    // Refuse if any application uses it
    $usage = (int)db_val(
        "SELECT COUNT(*) FROM vendor_applications WHERE nda_template_id = ?",
        [$id], 0
    );
    if ($usage > 0) {
        flash_set('error', "Cannot delete: $usage application(s) reference this template. Deactivate instead.");
        redirect(url('/nda_templates.php'));
    }

    $abs = __DIR__ . '/' . ltrim($t['file_path'], '/');
    if (is_file($abs)) @unlink($abs);
    db_exec("DELETE FROM nda_templates WHERE id = ?", [$id]);
    flash_set('success', 'Template deleted.');
    redirect(url('/nda_templates.php'));
}


// ============================================================
// LIST
// ============================================================
$templates = db_all(
    "SELECT t.*, u.full_name AS creator_name,
            (SELECT COUNT(*) FROM vendor_applications va WHERE va.nda_template_id = t.id) AS usage_count
       FROM nda_templates t
  LEFT JOIN users u ON u.id = t.created_by
      ORDER BY t.is_active DESC, t.name, t.version DESC",
    []
);

$page_title  = 'NDA Templates';
$page_module = 'nda_templates';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>NDA Templates</h1>
    <p class="muted small">Used by vendor empanelment. Active templates appear in the selector when creating an application.</p>
</div>

<?php if ($canManage): ?>
<div class="card" style="padding: 16px; margin-bottom: 16px; max-width: 760px;">
    <h2 style="margin-top: 0;">Upload new template</h2>
    <form method="post" action="<?= h(url('/nda_templates.php?action=upload')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="f_name">Name <span class="muted small">*</span></label>
                <input type="text" id="f_name" name="name" required maxlength="190" placeholder="e.g. Standard NDA - Manufacturing">
            </div>
            <div class="field">
                <label for="f_version">Version</label>
                <input type="text" id="f_version" name="version" maxlength="20" value="1.0">
            </div>
        </div>
        <div class="field">
            <label for="f_description">Description</label>
            <input type="text" id="f_description" name="description" maxlength="255">
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="f_file">File <span class="muted small">(PDF / DOC / DOCX, 15 MB max)</span></label>
                <input type="file" id="f_file" name="file" required accept=".pdf,.doc,.docx">
            </div>
            <div class="field">
                <label class="inline" style="gap: 6px;">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Make active immediately
                </label>
            </div>
        </div>
        <div class="field">
            <label for="f_notes">Notes (internal)</label>
            <textarea id="f_notes" name="notes" rows="2"></textarea>
        </div>
        <div class="form-actions" style="margin-top: 12px;">
            <button type="submit" class="btn btn-primary">📎 Upload template</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card" style="padding: 14px 18px;">
    <h2 style="margin-top: 0;">Templates <span class="muted small">(<?= count($templates) ?>)</span></h2>
    <?php if (!$templates): ?>
        <p class="muted">No templates yet. Upload one above to get started.</p>
    <?php else: ?>
        <table class="data-table" style="margin: 0;">
            <thead><tr>
                <th>Name</th>
                <th style="width: 80px;">Version</th>
                <th>File</th>
                <th style="width: 80px;">Used by</th>
                <th style="width: 90px;">Status</th>
                <th style="width: 130px;">Added</th>
                <?php if ($canManage): ?><th style="width: 200px;">Actions</th><?php endif; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                    <tr>
                        <td>
                            <strong><?= h($t['name']) ?></strong>
                            <?php if ($t['description']): ?>
                                <div class="muted small"><?= h($t['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= h($t['version']) ?></code></td>
                        <td>
                            <a href="<?= h(url('/nda_templates.php?action=download&id=' . (int)$t['id'])) ?>"><?= h($t['file_name']) ?></a>
                            <?php if ($t['file_size']): ?>
                                <div class="muted small"><?= h(number_format((float)$t['file_size'] / 1024, 1)) ?> KB</div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$t['usage_count'] ?></td>
                        <td>
                            <?php if ($t['is_active']): ?>
                                <span class="pill pill-active">active</span>
                            <?php else: ?>
                                <span class="muted small">inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h(substr((string)$t['created_at'], 0, 10)) ?>
                            <div class="muted small"><?= h($t['creator_name'] ?: '—') ?></div>
                        </td>
                        <?php if ($canManage): ?>
                            <td>
                                <form method="post" action="<?= h(url('/nda_templates.php?action=toggle_active')) ?>" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Toggle active">
                                        <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <?php if ((int)$t['usage_count'] === 0): ?>
                                    <form method="post" action="<?= h(url('/nda_templates.php?action=delete')) ?>" style="display:inline;"
                                          onsubmit="return confirm('Delete this template permanently?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="btn btn-icon" title="Delete">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
