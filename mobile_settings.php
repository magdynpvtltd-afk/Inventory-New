<?php
/**
 * MagDyn — Mobile Access settings
 * Created: 20260515_060024_IST
 *
 * Each user picks which modules they want exposed on the mobile/PWA shell.
 * The mobile shell (mobile/index.php) reads user_mobile_modules to render
 * only the chosen modules — the user still has to have permission for them.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('mobile', 'view');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $chosen = isset($_POST['modules']) && is_array($_POST['modules'])
        ? array_map('intval', $_POST['modules']) : [];
    db_exec('DELETE FROM user_mobile_modules WHERE user_id = ?', [$uid]);
    foreach ($chosen as $mid) {
        db_exec('INSERT INTO user_mobile_modules (user_id, module_id) VALUES (?, ?)', [$uid, $mid]);
    }
    flash_set('success', 'Mobile access preferences saved.');
    redirect(url('/mobile_settings.php'));
}

// Modules the user has at least *some* permission on (so they can pick from these)
$visible = visible_modules();
$ids = array_map(function ($m) { return (int)$m['id']; }, $visible);

$selected = $ids
    ? array_column(
        db_all(
            'SELECT module_id FROM user_mobile_modules WHERE user_id = ?',
            [$uid]
        ),
        'module_id'
    )
    : [];

$vapidPublic = $GLOBALS['APP']['vapid']['public_key'];
$pushReady   = $vapidPublic !== '';

$page_title  = 'Mobile Access';
$page_module = 'mobile';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Mobile Access</h1>
        <p class="muted">Choose which modules appear on the mobile / PWA shell.</p>
    </div>
    <a class="btn btn-ghost" href="<?= h(url('/mobile/')) ?>" target="_blank"
       data-shortcut="O" accesskey="o">
        <?= shortcut_label('Open mobile view', 'O') ?>
    </a>
</div>

<form class="card form-card" method="post" novalidate>
    <?= csrf_field() ?>
    <div class="card-head">
        <h2>Modules on mobile</h2>
        <span class="muted small">Only modules you have permission for are listed.</span>
    </div>

    <?php if (!$visible): ?>
        <div class="alert alert-info">You don't have access to any modules to expose on mobile.</div>
    <?php else: ?>
        <div class="form-grid">
            <?php $idx = 1; foreach ($visible as $m): ?>
                <div class="field">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="modules[]" value="<?= (int)$m['id'] ?>"
                               tabindex="<?= $idx++ ?>"
                               <?= in_array($m['id'], $selected) ? 'checked' : '' ?>>
                        <span style="font-size: 16px;"><?= h(module_icon($m['code'], $m['icon'])) ?></span>
                        <?= h($m['name']) ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit" data-shortcut="S">
            <?= shortcut_label('Save preferences', 'S') ?>
        </button>
    </div>
</form>

<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2>Push notifications on this device</h2>
        <span class="muted small">Native Android / iOS notifications via Web Push.</span>
    </div>
    <div class="card-body">
        <?php if (!$pushReady): ?>
            <div class="alert alert-warn">
                Push notifications are unavailable until VAPID keys are filled in at
                <code>config/app.config.php</code> → <code>vapid</code>.
            </div>
        <?php else: ?>
            <p>This will request notification permission, register this device with
            the server, and start delivering web-push notifications to your phone
            (when installed as a PWA) or browser.</p>
            <button id="btnEnablePush" class="btn btn-primary" type="button"
                    data-shortcut="E" accesskey="e">
                <?= shortcut_label('Enable push on this device', 'E') ?>
            </button>
            <p class="muted small" style="margin-top: 12px;">
                On iOS this only works when the app has been added to the Home Screen
                from Safari. On Android it works in any modern browser.
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('btnEnablePush');
    if (!btn) return;
    btn.addEventListener('click', function () {
        window.MagDyn.subscribePush(<?= json_encode($vapidPublic) ?>);
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
