<?php
/**
 * MagDyn — Users
 * Created: 20260515_060024_IST
 *
 * Actions:
 *   ?action=index           list (default)
 *   ?action=new             new user form
 *   ?action=edit&id=N       edit existing
 *   ?action=save  (POST)    create/update
 *   ?action=toggle&id=N     activate/deactivate
 *   ?action=impersonate&id=N  start "view as"
 *   ?action=stop_impersonate  stop "view as"
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$action = (string)input('action', 'index');

// Special: stop impersonation from anywhere on the site
if ($action === 'stop_impersonate') {
    impersonate_stop();
    flash_set('success', 'Exited view-as mode.');
    redirect(url('/index.php'));
}

// Everything else needs at least users.view
require_permission('users', 'view');

if ($action === 'save') {
    require_permission('users', 'manage');   // edit
    csrf_check();
    $id = (int)input('id', 0);
    $username  = trim((string)input('username'));
    $email     = trim((string)input('email'));
    $fullName  = trim((string)input('full_name'));
    $isActive  = input('is_active') ? 1 : 0;
    $password  = (string)input('password');
    $roles     = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('intval', $_POST['roles']) : [];

    $errors = [];
    if ($username === '')  $errors[] = 'Username is required.';
    if ($email === '')     $errors[] = 'Email is required.';
    if ($fullName === '')  $errors[] = 'Full name is required.';

    // Uniqueness checks
    $clash = db_one(
        'SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1',
        [$username, $email, $id]
    );
    if ($clash) $errors[] = 'Another user with that username or email already exists.';

    if (!$id && $password === '') $errors[] = 'Password is required for new users.';
    if ($password !== '' && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        $back = $id ? url('/users.php?action=edit&id=' . $id) : url('/users.php?action=new');
        redirect($back);
    }

    $pepper = $GLOBALS['APP']['password_pepper'];

    if ($id) {
        // Update
        if ($password !== '') {
            $hash = password_hash($password . $pepper, PASSWORD_DEFAULT);
            db_exec(
                'UPDATE users SET username=?, email=?, full_name=?, is_active=?, password_hash=? WHERE id=?',
                [$username, $email, $fullName, $isActive, $hash, $id]
            );
        } else {
            db_exec(
                'UPDATE users SET username=?, email=?, full_name=?, is_active=? WHERE id=?',
                [$username, $email, $fullName, $isActive, $id]
            );
        }
        db_exec('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        foreach ($roles as $rid) {
            db_exec('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $rid]);
        }
        flash_set('success', 'User updated.');
    } else {
        require_permission('users', 'create');
        $hash = password_hash($password . $pepper, PASSWORD_DEFAULT);
        db_exec(
            'INSERT INTO users (username, email, full_name, password_hash, sso_provider, is_active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$username, $email, $fullName, $hash, 'local', $isActive]
        );
        $newId = db()->lastInsertId();
        foreach ($roles as $rid) {
            db_exec('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$newId, $rid]);
        }
        flash_set('success', 'User created.');
    }
    redirect(url('/users.php'));
}

if ($action === 'toggle') {
    require_permission('users', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    if ($id && $id !== real_user_id()) {
        db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash_set('success', 'User active state toggled.');
    }
    redirect(url('/users.php'));
}

if ($action === 'impersonate') {
    require_permission('users', 'impersonate');
    $id = (int)input('id', 0);
    if (impersonate_start($id)) {
        flash_set('success', 'Now viewing as the selected user.');
    }
    redirect(url('/index.php'));
}

if ($action === 'delete') {
    require_permission('users', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    if (!$id) { flash_set('error', 'No user specified.'); redirect(url('/users.php')); }
    if ($id === real_user_id()) {
        flash_set('error', 'You cannot delete your own account.');
        redirect(url('/users.php'));
    }
    $u = db_one('SELECT username FROM users WHERE id = ?', [$id]);
    if (!$u) { flash_set('error', 'User not found.'); redirect(url('/users.php')); }

    // Safety: prevent delete if user owns audit/asset/training history.
    // (FKs use ON DELETE SET NULL which would orphan rows; better to refuse.)
    $hasAudit = db_val('SELECT COUNT(*) FROM audit_log WHERE actor_id = ? OR target_id = ?', [$id, $id], 0);
    $hasAsset = 0;
    try {
        $hasAsset = db_val("SELECT COUNT(*) FROM asset_transactions WHERE actor_id = ? OR from_user_id = ? OR to_user_id = ?", [$id, $id, $id], 0);
    } catch (Exception $e) { /* table may not exist yet */ }

    if ($hasAudit > 0 || $hasAsset > 0) {
        flash_set('error', sprintf(
            'Cannot delete %s — they have %d audit entries and %d asset transactions. Disable the account instead.',
            $u['username'], $hasAudit, $hasAsset
        ));
        redirect(url('/users.php'));
    }

    db_exec('DELETE FROM users WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'user.delete', ?)",
            [real_user_id(), 'deleted user ' . $u['username']]);
    flash_set('success', 'User deleted.');
    redirect(url('/users.php'));
}

// ============================================================
// RENDER
// ============================================================

if ($action === 'new' || $action === 'edit') {
    $editing = null;
    $userRoles = [];
    if ($action === 'edit') {
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$editing) {
            flash_set('error', 'User not found.');
            redirect(url('/users.php'));
        }
        $userRoles = array_column(
            db_all('SELECT role_id FROM user_roles WHERE user_id = ?', [$id]),
            'role_id'
        );
    } else {
        require_permission('users', 'create');
    }
    $allRoles = db_all('SELECT * FROM roles ORDER BY name');

    $page_title  = $editing ? 'Edit user' : 'New user';
    $page_module = 'users';
    $focus_id    = 'f_username';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit user' : 'New user',
            'subtitle'    => $editing ? $editing['full_name'] : 'Create a new local account',
            'back_href'   => url('/users.php'),
            'back_label'  => 'Users',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/users.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/users.php?action=save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="f_username"><?= shortcut_label('Username', 'U') ?> *</label>
                    <input id="f_username" name="username" type="text" tabindex="1" required
                           value="<?= h($editing['username'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_email"><?= shortcut_label('Email', 'E') ?> *</label>
                    <input id="f_email" name="email" type="email" tabindex="2" required
                           value="<?= h($editing['email'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_fullname"><?= shortcut_label('Full name', 'F') ?> *</label>
                    <input id="f_fullname" name="full_name" type="text" tabindex="3" required
                           value="<?= h($editing['full_name'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_password">
                        <?= shortcut_label('Password', 'P') ?>
                        <?php if ($editing): ?><span class="muted small">— leave blank to keep current</span><?php endif; ?>
                    </label>
                    <input id="f_password" name="password" type="password" tabindex="4"
                           autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
                </div>
                <div class="field span-2">
                    <label>Roles</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 4px;">
                        <?php foreach ($allRoles as $r): ?>
                            <label class="nowrap" style="font-weight: normal;">
                                <input type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>" tabindex="5"
                                       <?= in_array($r['id'], $userRoles) ? 'checked' : '' ?>>
                                <?= h($r['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="is_active" value="1" tabindex="6"
                               <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                        <?= shortcut_label('Active', 'A') ?>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// LIST
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

$canCreate = permission_check('users', 'create');
$canManage = permission_check('users', 'manage');
$canImpersonate = permission_check('users', 'impersonate');
$canDelete = permission_check('users', 'delete');

$dtCfg = [
    'id'         => 'users',
    'base_sql'   => 'SELECT u.*,
                            GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS role_names
                       FROM users u
                       LEFT JOIN user_roles ur ON ur.user_id = u.id
                       LEFT JOIN roles r       ON r.id       = ur.role_id',
    'group_by'   => 'u.id',
    'columns'    => [
        ['key'=>'full_name', 'label'=>'Name',     'sortable'=>true, 'searchable'=>true,  'sql_col'=>'u.full_name'],
        ['key'=>'username',  'label'=>'Username', 'sortable'=>true, 'searchable'=>true,  'sql_col'=>'u.username'],
        ['key'=>'email',     'label'=>'Email',    'sortable'=>true, 'searchable'=>true,  'sql_col'=>'u.email'],
        ['key'=>'roles',     'label'=>'Roles',    'sortable'=>false,'searchable'=>false, 'td_class'=>'muted small'],
        ['key'=>'is_active', 'label'=>'Status',   'sortable'=>true, 'searchable'=>false, 'sql_col'=>'u.is_active'],
        ['key'=>'_actions',  'label'=>'Actions',  'sortable'=>false,'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r nowrap'],
    ],
    'default_sort' => ['full_name', 'asc'],
];

$realUserId = real_user_id();
$rowRenderer = function ($u) use ($canManage, $canImpersonate, $canDelete, $realUserId) {
    $name = (int)$u['id'] !== $realUserId && $canManage
          ? '<strong><a href="' . h(url('/users.php?action=edit&id=' . (int)$u['id'])) . '">' . h($u['full_name']) . '</a></strong>'
          : '<strong>' . h($u['full_name']) . '</strong>';

    $status = $u['is_active']
        ? '<span class="pill pill-active">active</span>'
        : '<span class="pill pill-neutral">disabled</span>';

    // Action column: icon buttons with title tooltips. The explicit
    // edit icon (✎) is shown when the user has manage permission and
    // the row isn't the user's own real account.
    $actions = '';
    if ($canManage && (int)$u['id'] !== $realUserId) {
        $actions .= '<a class="btn btn-icon" title="Edit user" aria-label="Edit user" href="'
                  . h(url('/users.php?action=edit&id=' . (int)$u['id'])) . '">✎ <span class="dt-action-label">Edit user</span></a> ';
    }
    if ($canImpersonate && (int)$u['id'] !== $realUserId && $u['is_active']) {
        $actions .= '<a class="btn btn-icon" title="View the app as this user" aria-label="Impersonate" href="'
                  . h(url('/users.php?action=impersonate&id=' . (int)$u['id'])) . '">👁 <span class="dt-action-label">Impersonate</span></a> ';
    }
    if ($canManage && (int)$u['id'] !== $realUserId) {
        $toggleTitle = $u['is_active'] ? 'Disable user' : 'Enable user';
        $toggleGlyph = $u['is_active'] ? '🚫' : '✅';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/users.php?action=toggle')) . '">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$u['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="' . h($toggleTitle) . '" aria-label="' . h($toggleTitle) . '">'
                  . $toggleGlyph . ' <span class="dt-action-label">' . h($toggleTitle) . '</span></button></form> ';
    }
    if ($canDelete && (int)$u['id'] !== $realUserId) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/users.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Permanently delete ' . h(addslashes($u['username'])) . '? This cannot be undone.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$u['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete user" aria-label="Delete user">🗑 <span class="dt-action-label">Delete user</span></button></form>';
    }

    return [
        'full_name' => $name,
        'username'  => '<span class="mono">' . h($u['username']) . '</span>',
        'email'     => h($u['email']),
        'roles'     => h($u['role_names'] ?: '—'),
        'is_active' => $status,
        '_actions'  => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);
// data_table_run() exits before reaching here on JSON requests.

$page_title  = 'Users';
$page_module = 'users';
$focus_id    = '';

$actionsHtml = '';
if ($canCreate) {
    $actionsHtml = '<a class="btn btn-primary btn-sm" href="' . h(url('/users.php?action=new')) . '"'
                 . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New user', 'N') . '</a>';
}
$dtCfg['title']        = 'Users';
$dtCfg['actions_html'] = $actionsHtml;

require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
