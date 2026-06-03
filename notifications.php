<?php
/**
 * MagDyn — Notification preferences
 * Created: 20260515_060024_IST
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('notifications', 'view');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    db_exec('DELETE FROM user_notification_prefs WHERE user_id = ?', [$uid]);
    $types = db_all('SELECT id FROM notification_types');
    foreach ($types as $t) {
        $tid = (int)$t['id'];
        $web   = !empty($_POST["w_$tid"]) ? 1 : 0;
        $email = !empty($_POST["e_$tid"]) ? 1 : 0;
        $push  = !empty($_POST["p_$tid"]) ? 1 : 0;
        if ($web || $email || $push) {
            db_exec(
                'INSERT INTO user_notification_prefs (user_id, notification_type_id, channel_web, channel_email, channel_push)
                 VALUES (?, ?, ?, ?, ?)',
                [$uid, $tid, $web, $email, $push]
            );
        }
    }
    flash_set('success', 'Notification preferences saved.');
    redirect(url('/notifications.php'));
}

$types = db_all(
    'SELECT nt.*, m.name AS module_name
       FROM notification_types nt
       LEFT JOIN modules m ON m.id = nt.module_id
      ORDER BY m.sort_order, nt.name'
);
$prefs = [];
foreach (db_all('SELECT * FROM user_notification_prefs WHERE user_id = ?', [$uid]) as $row) {
    $prefs[(int)$row['notification_type_id']] = $row;
}

$page_title  = 'Notifications';
$page_module = 'notifications';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Notification preferences</h1>
        <p class="muted">Choose which kinds of notifications you receive, and through which channel.</p>
    </div>
</div>

<form class="card" method="post" novalidate>
    <?= csrf_field() ?>
    <table class="data-table">
        <thead>
        <tr>
            <th>Type</th>
            <th>Module</th>
            <th class="r">In-app</th>
            <th class="r">Email</th>
            <th class="r">Push</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$types): ?>
            <tr><td colspan="5" class="empty">No notification types defined yet.</td></tr>
        <?php else: $tabIdx = 1; foreach ($types as $t):
            $p = $prefs[(int)$t['id']] ?? ['channel_web' => 1, 'channel_email' => 1, 'channel_push' => 1]; ?>
            <tr>
                <td><strong><?= h($t['name']) ?></strong>
                    <div class="muted small"><code><?= h($t['code']) ?></code></div>
                </td>
                <td><?= h($t['module_name'] ?: '—') ?></td>
                <td class="r"><input type="checkbox" tabindex="<?= $tabIdx++ ?>"
                                      name="w_<?= (int)$t['id'] ?>" <?= $p['channel_web']   ? 'checked' : '' ?>></td>
                <td class="r"><input type="checkbox" tabindex="<?= $tabIdx++ ?>"
                                      name="e_<?= (int)$t['id'] ?>" <?= $p['channel_email'] ? 'checked' : '' ?>></td>
                <td class="r"><input type="checkbox" tabindex="<?= $tabIdx++ ?>"
                                      name="p_<?= (int)$t['id'] ?>" <?= $p['channel_push']  ? 'checked' : '' ?>></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <div class="form-actions" style="padding: 16px;">
        <button class="btn btn-primary" type="submit" data-shortcut="S">
            <?= shortcut_label('Save', 'S') ?>
        </button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
