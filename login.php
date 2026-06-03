<?php
/**
 * MagDyn — Login page
 * Created: 20260515_060024_IST
 *
 * Tab order: username (1) -> password (2) -> sign-in button (3) -> SSO (4).
 * Username is auto-focused on load.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$APP = $GLOBALS['APP'];

// Already signed in?
if (real_user_id()) {
    redirect(url('/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim((string)input('username'));
    $p = (string)input('password');
    if ($u === '' || $p === '') {
        $error = 'Enter both username and password.';
    } else {
        $row = auth_local($u, $p);
        if ($row) {
            auth_sign_in($row['id']);
            $ret = $_SESSION['_return_to'] ?? '';
            unset($_SESSION['_return_to']);
            redirect($ret ?: url('/index.php'));
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
$flashes = flash_pull();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · <?= h($APP['app_name']) ?></title>
    <link rel="icon" href="<?= h(url('/assets/img/icon-192.png')) ?>">
    <meta name="theme-color" content="<?= h($APP['pwa']['theme_color']) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/magdyn-base.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/app.css')) ?>">
    <script>window.MAGDYN_BASE = <?= json_encode(rtrim($APP['base_url'], '/')) ?>; window.MAGDYN_SW = false;</script>
</head>
<body class="login-body">
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <img src="<?= h(url('/assets/img/logo.png')) ?>" alt="<?= h($APP['app_name']) ?>">
            <div class="brand-title"><?= h($APP['app_name']) ?></div>
            <div class="muted"><?= h($APP['app_tagline']) ?></div>
        </div>

        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form class="login-form" method="post" autocomplete="on" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="username"><?= shortcut_label('Username or email', 'U') ?></label>
                <input id="username" name="username" type="text" tabindex="1"
                       autocomplete="username" required
                       value="<?= h(input('username')) ?>">
            </div>
            <div class="field">
                <label for="password"><?= shortcut_label('Password', 'P') ?></label>
                <input id="password" name="password" type="password" tabindex="2"
                       autocomplete="current-password" required>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary btn-block" type="submit" tabindex="3"
                        data-shortcut="S">
                    <?= shortcut_label('Sign in', 'S') ?>
                </button>
            </div>
        </form>

        <?php if (!empty($APP['sso']['enabled'])): ?>
            <div class="login-divider"><span>or</span></div>
            <a class="btn btn-ghost btn-block" href="<?= h(url('/sso_begin.php')) ?>"
               tabindex="4" data-shortcut="O">
                <?= shortcut_label('Sign in with SSO', 'O') ?>
            </a>
        <?php else: ?>
            <p class="muted small center" style="margin-top: 16px;">
                SSO is not configured. Enable it in <code>config/app.config.php</code>.
            </p>
        <?php endif; ?>
    </div>
</div>
<script>window.__FOCUS_ID = "username";</script>
<script src="<?= h(asset_url('/assets/js/shortcuts.js')) ?>"></script>
</body>
</html>
