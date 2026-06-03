<?php
/**
 * MagDyn — Code sequences admin page
 * Created: 20260518_083000_IST
 *
 * Lists every auto-generated code-sequence (asset tags, inventory
 * item codes, shipment numbers, receipt numbers, etc.) and lets
 * admins edit the prefix, pad width, and date format. The structural
 * columns (name, format, target table) are read-only — those tie to
 * how the application calls code_next() and shouldn't change at
 * runtime.
 *
 * Lives at /code_sequences.php under the Admin sidebar group.
 *
 * Permissions: requires the 'code_sequences' module with 'manage'
 * permission to edit; 'view' is enough to see the list.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_codes.php';
require_login();
require_once __DIR__ . '/includes/datatable.php';

$action = (string)input('action', 'index');
$canManage = permission_check('code_sequences', 'manage');

if (!permission_check('code_sequences', 'view')) {
    require_permission('code_sequences', 'view');
}

// ----------------------------------------------------------------
// SAVE (edit existing row — no add/delete since sequences are
// registered structurally by application code, not by operators).
// ----------------------------------------------------------------
if ($action === 'save') {
    require_permission('code_sequences', 'manage');
    csrf_check();

    $name   = trim((string)input('name', ''));
    $prefix = trim((string)input('prefix', ''));
    $pad    = (int)input('pad', 5);
    $dateFmt = trim((string)input('date_format', ''));
    $label  = trim((string)input('label', ''));
    $desc   = trim((string)input('description', ''));
    $active = input('is_active') ? 1 : 0;

    $row = db_one('SELECT * FROM code_sequences WHERE name = ?', [$name]);
    if (!$row) {
        flash_set('error', 'Sequence not found.');
        redirect(url('/code_sequences.php'));
    }
    if ($prefix === '') {
        flash_set('error', 'Prefix is required.');
        redirect(url('/code_sequences.php?action=edit&name=' . urlencode($name)));
    }
    if ($pad < 1 || $pad > 20) {
        flash_set('error', 'Pad width must be between 1 and 20.');
        redirect(url('/code_sequences.php?action=edit&name=' . urlencode($name)));
    }
    // date_format only meaningful when format='prefix_date_seq'
    $dateFmtToSave = $row['format'] === 'prefix_date_seq'
        ? ($dateFmt !== '' ? $dateFmt : 'ymd')
        : null;

    db_exec(
        'UPDATE code_sequences
            SET label = ?, description = ?, prefix = ?, pad = ?,
                date_format = ?, is_active = ?
          WHERE name = ?',
        [$label ?: $row['label'], $desc ?: null, $prefix, $pad,
         $dateFmtToSave, $active, $name]
    );
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'code_sequences.update', 0, ?)",
        [(int)current_user_id(), $name . ': prefix=' . $prefix . ' pad=' . $pad]
    );
    flash_set('success', 'Sequence updated. New codes will use the new format.');
    redirect(url('/code_sequences.php'));
}

// ----------------------------------------------------------------
// EDIT form
// ----------------------------------------------------------------
if ($action === 'edit') {
    require_permission('code_sequences', 'manage');
    $name = (string)input('name', '');
    $row = db_one('SELECT * FROM code_sequences WHERE name = ?', [$name]);
    if (!$row) {
        flash_set('error', 'Sequence not found.');
        redirect(url('/code_sequences.php'));
    }
    // Show a preview of what the next code will look like under the
    // current settings, computed live so the operator sees the effect
    // of their proposed changes before saving.
    $previewNow = code_next($name);

    $page_title  = 'Edit code sequence: ' . $row['label'];
    $page_module = 'code_sequences';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/code_sequences.php'),
        'back_label' => 'Back to list',
        'title'      => 'Edit: ' . h($row['label']),
    ]) ?>

    <form method="post" action="<?= h(url('/code_sequences.php?action=save')) ?>"
          class="card" style="padding: 18px; max-width: 720px;">
        <?= csrf_field() ?>
        <input type="hidden" name="name" value="<?= h($row['name']) ?>">

        <div class="field">
            <label>Sequence name</label>
            <input type="text" value="<?= h($row['name']) ?>" disabled>
            <span class="muted small">Read-only — referenced from application code.</span>
        </div>

        <div class="field">
            <label>Format type</label>
            <input type="text" value="<?= h($row['format']) ?>" disabled>
            <span class="muted small">
                <?php if ($row['format'] === 'prefix_date_seq'): ?>
                    Codes are <code>PREFIX + date + '-' + zero-padded seq</code>; sequence resets each day.
                <?php else: ?>
                    Codes are <code>PREFIX + zero-padded sequence</code>; sequence is global.
                <?php endif; ?>
            </span>
        </div>

        <div class="grid-2col">
            <div class="field">
                <label for="f_label">Display label <span class="required">*</span></label>
                <input id="f_label" type="text" name="label" required maxlength="80"
                       value="<?= h($row['label']) ?>">
            </div>
            <div class="field">
                <label for="f_prefix">Prefix <span class="required">*</span></label>
                <input id="f_prefix" type="text" name="prefix" required maxlength="20"
                       value="<?= h($row['prefix']) ?>">
                <span class="muted small">Literal text at the start of every code.</span>
            </div>
            <div class="field">
                <label for="f_pad">Pad width <span class="required">*</span></label>
                <input id="f_pad" type="number" name="pad" required min="1" max="20"
                       value="<?= (int)$row['pad'] ?>">
                <span class="muted small">Number of digits in the zero-padded sequence (e.g. 5 → 00042).</span>
            </div>
            <?php if ($row['format'] === 'prefix_date_seq'): ?>
                <div class="field">
                    <label for="f_datefmt">Date format</label>
                    <input id="f_datefmt" type="text" name="date_format" maxlength="10"
                           value="<?= h($row['date_format'] ?: 'ymd') ?>">
                    <span class="muted small">PHP date() format. e.g. <code>ymd</code> = YYMMDD, <code>Ymd</code> = YYYYMMDD.</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="f_desc">Description</label>
            <textarea id="f_desc" name="description" rows="2"><?= h($row['description'] ?? '') ?></textarea>
        </div>

        <div class="field">
            <label class="inline">
                <input type="checkbox" name="is_active" value="1" <?= $row['is_active'] ? 'checked' : '' ?>>
                Active
            </label>
            <span class="muted small">If unchecked, code_next() falls back to a timestamp-based default.</span>
        </div>

        <div class="card" style="background: var(--surface-alt, #f5f6f8); padding: 12px; margin-top: 12px;">
            <div class="muted small">Current next code preview (under existing settings)</div>
            <strong style="font-family: var(--font-mono, monospace); font-size: 16px;"><?= h($previewNow) ?></strong>
            <div class="muted small" style="margin-top: 6px;">
                Save the form to apply the new prefix/pad. The next minted code will follow the updated format.
            </div>
        </div>

        <div class="form-actions" style="margin-top: 16px;">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>

    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ----------------------------------------------------------------
// LIST (default)
// ----------------------------------------------------------------
$rows = db_all('SELECT * FROM code_sequences ORDER BY label');

$page_title  = 'Code sequences';
$page_module = 'code_sequences';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Code sequences</h1>
        <p class="muted">Auto-generated codes used throughout the app. Edit a sequence to change its prefix or pad width — newly-minted codes will use the new format.</p>
    </div>
</div>

<table class="data-table">
    <thead>
        <tr>
            <th>Label</th>
            <th>Name</th>
            <th>Prefix</th>
            <th class="r">Pad</th>
            <th>Format</th>
            <th>Sample next code</th>
            <th>Active</th>
            <?php if ($canManage): ?><th class="r">Actions</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $sample = '';
        try { $sample = code_next($r['name']); }
        catch (\Throwable $e) { $sample = '(error)'; }
    ?>
        <tr<?= !$r['is_active'] ? ' class="muted"' : '' ?>>
            <td><strong><?= h($r['label']) ?></strong>
                <?php if ($r['description']): ?>
                    <div class="muted small"><?= h($r['description']) ?></div>
                <?php endif; ?>
            </td>
            <td><code><?= h($r['name']) ?></code></td>
            <td><code><?= h($r['prefix']) ?></code></td>
            <td class="r"><?= (int)$r['pad'] ?></td>
            <td>
                <?php if ($r['format'] === 'prefix_date_seq'): ?>
                    prefix + date(<code><?= h($r['date_format'] ?: 'ymd') ?></code>) + seq
                <?php else: ?>
                    prefix + seq
                <?php endif; ?>
            </td>
            <td><code style="font-family: var(--font-mono, monospace);"><?= h($sample) ?></code></td>
            <td>
                <?php if ($r['is_active']): ?>
                    <span class="pill pill-active">active</span>
                <?php else: ?>
                    <span class="pill pill-neutral">disabled</span>
                <?php endif; ?>
            </td>
            <?php if ($canManage): ?>
                <td class="r nowrap">
                    <a class="btn btn-icon" title="Edit"
                       href="<?= h(url('/code_sequences.php?action=edit&name=' . urlencode($r['name']))) ?>">✎ <span class="dt-action-label">Edit</span></a>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="empty muted">No sequences registered yet. Run the latest migration.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
