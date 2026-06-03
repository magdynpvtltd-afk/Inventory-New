<?php
/**
 * MagDyn — Roles & Permissions
 * Created: 20260515_060024_IST
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('roles', 'view');

$action = (string)input('action', 'index');

if ($action === 'save') {
    require_permission('roles', 'manage');
    csrf_check();
    $id   = (int)input('id', 0);
    $code = trim((string)input('code'));
    $name = trim((string)input('name'));
    $desc = trim((string)input('description'));
    $perms = isset($_POST['perms']) && is_array($_POST['perms'])
        ? array_map('intval', $_POST['perms']) : [];

    if ($code === '' || $name === '') {
        flash_set('error', 'Code and name are required.');
        redirect($id ? url('/roles.php?action=edit&id=' . $id) : url('/roles.php?action=new'));
    }

    if ($id) {
        $row = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$row) { flash_set('error', 'Role not found.'); redirect(url('/roles.php')); }
        // System roles: name/desc/perms editable, code locked.
        if ($row['is_system']) {
            db_exec('UPDATE roles SET name = ?, description = ? WHERE id = ?', [$name, $desc, $id]);
        } else {
            db_exec('UPDATE roles SET code = ?, name = ?, description = ? WHERE id = ?', [$code, $name, $desc, $id]);
        }
        db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
        foreach ($perms as $pid) {
            db_exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$id, $pid]);
        }
        flash_set('success', 'Role updated.');
    } else {
        $exists = db_one('SELECT id FROM roles WHERE code = ?', [$code]);
        if ($exists) { flash_set('error', 'A role with that code already exists.'); redirect(url('/roles.php?action=new')); }
        db_exec('INSERT INTO roles (code, name, description, is_system) VALUES (?, ?, ?, 0)', [$code, $name, $desc]);
        $newId = db()->lastInsertId();
        foreach ($perms as $pid) {
            db_exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$newId, $pid]);
        }
        flash_set('success', 'Role created.');
    }
    redirect(url('/roles.php'));
}

if ($action === 'delete') {
    require_permission('roles', 'manage');
    csrf_check();
    $id  = (int)input('id', 0);
    $row = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
    if (!$row) { flash_set('error', 'Role not found.'); }
    elseif ($row['is_system']) { flash_set('error', 'System roles cannot be deleted.'); }
    else {
        db_exec('DELETE FROM roles WHERE id = ?', [$id]);
        flash_set('success', 'Role deleted.');
    }
    redirect(url('/roles.php'));
}

// ============================================================
// CLONE — duplicate role + role_permissions
// ============================================================
// Copies the role + every role_permissions row. Does NOT copy
// user_roles assignments (cloning a role + auto-granting it to every
// user the original had is a dangerous default). is_system always
// resets to 0 — a clone is never system-protected.
if ($action === 'clone') {
    require_permission('roles', 'manage');
    csrf_check();
    $id  = (int)input('id', 0);
    $src = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Role not found.');
        redirect(url('/roles.php'));
    }

    $newCode = clone_unique_code('roles', 'code', $src['code']);
    $newName = 'Copy of ' . $src['name'];

    $newId = clone_row('roles', $id, [
        'code'      => $newCode,
        'name'      => $newName,
        'is_system' => 0,
    ]);
    if ($newId <= 0) {
        flash_set('error', 'Role clone failed.');
        redirect(url('/roles.php'));
    }

    // Copy permission grants
    db_exec(
        'INSERT INTO role_permissions (role_id, permission_id)
         SELECT ?, permission_id FROM role_permissions WHERE role_id = ?',
        [$newId, $id]
    );

    $permCount = (int)db_val('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$newId], 0);
    flash_set('success', 'Role cloned to "' . $newCode . '" with ' . $permCount . ' permission'
        . ($permCount === 1 ? '' : 's') . '. Adjust as needed and save.');
    redirect(url('/roles.php?action=edit&id=' . $newId));
}

// ============================================================
// EDIT / NEW
// ============================================================
if ($action === 'new' || $action === 'edit') {
    require_permission('roles', 'manage');
    $editing = null;
    $rolePerms = [];
    if ($action === 'edit') {
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM roles WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Role not found.'); redirect(url('/roles.php')); }
        $rolePerms = array_column(
            db_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$id]),
            'permission_id'
        );
    }
    $matrix = permissions_matrix();

    $page_title  = $editing ? 'Edit role' : 'New role';
    $page_module = 'roles';
    $focus_id    = 'f_code';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit role' : 'New role',
            'subtitle'    => $editing ? $editing['name'] : 'Define a new role and its permissions',
            'back_href'   => url('/roles.php'),
            'back_label'  => 'Roles',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/roles.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/roles.php?action=save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="f_code"><?= shortcut_label('Code', 'C') ?> *</label>
                    <input id="f_code" name="code" type="text" required tabindex="1"
                           value="<?= h($editing['code'] ?? '') ?>"
                           <?= ($editing && $editing['is_system']) ? 'readonly' : '' ?>>
                    <?php if ($editing && $editing['is_system']): ?>
                        <span class="muted small">System role — code is locked.</span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="f_name"><?= shortcut_label('Name', 'N') ?> *</label>
                    <input id="f_name" name="name" type="text" required tabindex="2"
                           value="<?= h($editing['name'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_desc"><?= shortcut_label('Description', 'D') ?></label>
                    <input id="f_desc" name="description" type="text" tabindex="3"
                           value="<?= h($editing['description'] ?? '') ?>">
                </div>
            </div>

            <div class="form-section">
                <h2>Permissions</h2>
                <p class="muted small">Tick the permissions this role should grant. Use the column header to toggle a whole module.</p>

                <?php
                // Split the matrix: regular modules render in the main
                // table; note_cat_* modules go into a separate collapsible
                // section so the page stays clean as note categories grow.
                // Skip inactive note_cat_* modules — those correspond to
                // disabled categories and shouldn't be grant-able.
                $regularMods   = [];
                $noteCatMods   = [];
                foreach ($matrix as $m) {
                    if (strpos($m['code'], 'note_cat_') === 0) {
                        if ((int)$m['is_active'] !== 1) continue;
                        $noteCatMods[] = $m;
                    } else {
                        $regularMods[] = $m;
                    }
                }
                ?>

                <table class="data-table perm-matrix" style="margin-top: 12px;">
                    <thead>
                    <tr>
                        <th>Module</th>
                        <th>Permissions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $tabIdx = 4; foreach ($regularMods as $m): ?>
                        <tr>
                            <th>
                                <label class="nowrap">
                                    <input type="checkbox" class="mod-toggle"
                                           data-target="mod-<?= (int)$m['id'] ?>"
                                           tabindex="<?= $tabIdx++ ?>">
                                    <?= h(module_icon($m['code'], $m['icon'])) ?> <?= h($m['name']) ?>
                                </label>
                            </th>
                            <td style="text-align: left;">
                                <?php foreach ($m['permissions'] as $p): ?>
                                    <label class="nowrap" style="margin-right: 16px; font-weight: normal;">
                                        <input type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>"
                                               class="mod-<?= (int)$m['id'] ?>"
                                               tabindex="<?= $tabIdx++ ?>"
                                               <?= in_array($p['id'], $rolePerms) ? 'checked' : '' ?>>
                                        <?= h($p['name']) ?>
                                        <code class="muted"><?= h($p['code']) ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($noteCatMods): ?>
                    <details class="perm-matrix-group" style="margin-top: 16px;">
                        <summary style="cursor: pointer; padding: 8px 12px; background: var(--surface-alt, #f3f4f7); border: 1px solid var(--border); border-radius: 6px; font-weight: 600; font-size: 13px;">
                            📝 Note categories
                            <span class="muted small" style="font-weight: normal;">
                                (<?= count($noteCatMods) ?> categor<?= count($noteCatMods) === 1 ? 'y' : 'ies' ?> — controls who can view/post notes per category)
                            </span>
                        </summary>
                        <table class="data-table perm-matrix" style="margin-top: 8px;">
                            <thead>
                            <tr>
                                <th>Category</th>
                                <th>Permissions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($noteCatMods as $m):
                                // Strip the "Note category: " prefix from the
                                // display since the section header already
                                // explains the context.
                                $displayName = preg_replace('/^Note category:\s*/', '', $m['name']);
                            ?>
                                <tr>
                                    <th>
                                        <label class="nowrap">
                                            <input type="checkbox" class="mod-toggle"
                                                   data-target="mod-<?= (int)$m['id'] ?>"
                                                   tabindex="<?= $tabIdx++ ?>">
                                            <?= h($displayName) ?>
                                        </label>
                                    </th>
                                    <td style="text-align: left;">
                                        <?php foreach ($m['permissions'] as $p): ?>
                                            <label class="nowrap" style="margin-right: 16px; font-weight: normal;">
                                                <input type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>"
                                                       class="mod-<?= (int)$m['id'] ?>"
                                                       tabindex="<?= $tabIdx++ ?>"
                                                       <?= in_array($p['id'], $rolePerms) ? 'checked' : '' ?>>
                                                <?= h(ucfirst($p['code'])) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
    // Whole-module toggle (column header checkbox toggles every child checkbox)
    document.querySelectorAll('.mod-toggle').forEach(function (cb) {
        var children = document.querySelectorAll('.' + cb.getAttribute('data-target'));
        // Initialise: if all children are checked, check the master
        var allChecked = Array.prototype.every.call(children, function (c) { return c.checked; });
        cb.checked = allChecked && children.length > 0;
        cb.addEventListener('change', function () {
            children.forEach(function (c) { c.checked = cb.checked; });
        });
    });
    </script>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// LIST
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

$canManage = permission_check('roles', 'manage');

$dtCfg = [
    'id'       => 'roles',
    'base_sql' => 'SELECT r.*,
                          (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count,
                          (SELECT COUNT(*) FROM user_roles ur     WHERE ur.role_id = r.id) AS user_count
                     FROM roles r',
    'columns'  => [
        ['key'=>'name',        'label'=>'Role',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'r.name'],
        ['key'=>'code',        'label'=>'Code',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'r.code'],
        ['key'=>'description', 'label'=>'Description', 'sortable'=>false,'searchable'=>true, 'sql_col'=>'r.description', 'td_class'=>'muted small'],
        ['key'=>'perm_count',  'label'=>'Permissions', 'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'user_count',  'label'=>'Users',       'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'is_system',   'label'=>'Type',        'sortable'=>true, 'searchable'=>false,'sql_col'=>'r.is_system'],
        ['key'=>'_actions',    'label'=>'Actions',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['name', 'asc'],
];

$rowRenderer = function ($r) use ($canManage) {
    $name = $canManage
        ? '<strong><a href="' . h(url('/roles.php?action=edit&id=' . (int)$r['id'])) . '">' . h($r['name']) . '</a></strong>'
        : '<strong>' . h($r['name']) . '</strong>';
    $type = $r['is_system']
        ? '<span class="pill pill-info">system</span>'
        : '<span class="pill pill-neutral">custom</span>';
    $actions = '';
    if ($canManage) {
        $actions .= '<a class="btn btn-icon" title="Edit role" aria-label="Edit role" href="'
                  . h(url('/roles.php?action=edit&id=' . (int)$r['id'])) . '">✎ <span class="dt-action-label">Edit role</span></a> ';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/roles.php?action=clone')) . '"'
                  . ' onsubmit="return confirm(\'Clone role &quot;' . h($r['name']) . '&quot;? Permission grants will be copied; user assignments will not.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="Clone role" aria-label="Clone role">⎘ <span class="dt-action-label">Clone role</span></button></form>';
    }
    if ($canManage && !$r['is_system']) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/roles.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Delete role &quot;' . h($r['name']) . '&quot;?\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete role" aria-label="Delete role">🗑 <span class="dt-action-label">Delete role</span></button></form>';
    }
    return [
        'name'        => $name,
        'code'        => '<code>' . h($r['code']) . '</code>',
        'description' => h($r['description'] ?: ''),
        'perm_count'  => (int)$r['perm_count'],
        'user_count'  => (int)$r['user_count'],
        'is_system'   => $type,
        '_actions'    => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);

$page_title  = 'Roles & Permissions';
$page_module = 'roles';
$focus_id    = '';

$actionsHtml = '';
if ($canManage) {
    $actionsHtml = '<a class="btn btn-primary btn-sm" href="' . h(url('/roles.php?action=new')) . '"'
                 . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New role', 'N') . '</a>';
}
$dtCfg['title']        = 'Roles & Permissions';
$dtCfg['actions_html'] = $actionsHtml;

require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
