<?php
/**
 * MagDyn — Mobile / PWA shell
 * Created: 20260515_060024_IST
 *
 * The PWA start_url. Renders a tap-friendly module grid composed of:
 *   - the user's permission set, AND
 *   - the modules they explicitly enabled for mobile (mobile_settings.php)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$APP = $GLOBALS['APP'];
$uid = current_user_id();
$user = current_user();
$realUser = real_user();

// Modules visible (permission-wise)
$visible = visible_modules();

// User's mobile picks
$picks = array_column(
    db_all('SELECT module_id FROM user_mobile_modules WHERE user_id = ?', [$uid]),
    'module_id'
);
// Fall back to all visible if no picks made yet
$shown = $picks
    ? array_values(array_filter($visible, function ($m) use ($picks) { return in_array($m['id'], $picks); }))
    : array_values($visible);

$flashes = flash_pull();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h($APP['app_name']) ?> · Mobile</title>
    <link rel="icon" href="<?= h(url('/assets/img/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= h(url('/assets/img/icon-192.png')) ?>">
    <link rel="manifest" href="<?= h(url('/manifest.php')) ?>">
    <meta name="theme-color" content="<?= h($APP['pwa']['theme_color']) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= h($APP['app_name']) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/magdyn-base.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/app.css')) ?>">
    <style>
        /* Mobile shell — chrome-less, native feel */
        body { background: var(--bg); }
        .m-header {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 16px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 10;
        }
        .m-header img { width: 28px; height: 28px; }
        .m-header h1 { font-size: 16px; font-weight: 600; flex: 1; }
        .m-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 16px;
        }
        .m-tile {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px;
            display: flex; flex-direction: column; gap: 6px;
            min-height: 110px;
            text-decoration: none;
            color: var(--text);
            transition: transform 0.06s ease;
        }
        .m-tile:active { transform: scale(0.98); text-decoration: none; }
        .m-tile-icon { font-size: 28px; }
        .m-tile-name { font-weight: 600; }
        .m-tile-desc { font-size: 12px; color: var(--text-muted); }
        .m-banner {
            margin: 12px 16px 0;
            padding: 10px 12px;
            background: var(--warn-bg); color: var(--warn);
            border-radius: var(--radius);
            font-size: 13px;
        }
        .m-foot {
            padding: 16px;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }
        @media (min-width: 480px) { .m-grid { grid-template-columns: repeat(3, 1fr); } }
    </style>
    <script>window.MAGDYN_BASE = <?= json_encode(rtrim($APP['base_url'], '/')) ?>;</script>
</head>
<body>
<header class="m-header">
    <img src="<?= h(url('/assets/img/logo.png')) ?>" alt="">
    <h1><?= h($APP['app_name']) ?></h1>
    <a class="btn btn-ghost btn-sm" href="<?= h(url('/index.php')) ?>">Desktop</a>
    <a class="btn btn-ghost btn-sm" href="<?= h(url('/logout.php')) ?>">Sign out</a>
</header>

<?php if (is_impersonating()): ?>
    <div class="m-banner">
        👁 Viewing as <strong><?= h($user['full_name']) ?></strong>
        <a href="<?= h(url('/users.php?action=stop_impersonate')) ?>" style="float:right;">Exit</a>
    </div>
<?php endif; ?>

<?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>" style="margin: 12px 16px 0;"><?= h($f['msg']) ?></div>
<?php endforeach; ?>

<main>
    <?php if (!$shown): ?>
        <div class="m-banner">
            No modules are enabled for mobile. Open
            <a href="<?= h(url('/mobile_settings.php')) ?>">mobile settings</a> on desktop to pick some.
        </div>
    <?php else: ?>
        <div class="m-grid">
            <?php foreach ($shown as $m): ?>
                <a class="m-tile" href="<?= h(route($m['code'], 'index')) ?>">
                    <span class="m-tile-icon"><?= h(module_icon($m['code'], $m['icon'])) ?></span>
                    <span class="m-tile-name"><?= h($m['name']) ?></span>
                    <?php if (!empty($m['description'])): ?>
                        <span class="m-tile-desc"><?= h($m['description']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<div class="m-foot">
    Signed in as <?= h($user['full_name']) ?> ·
    <a href="<?= h(url('/mobile_settings.php')) ?>">Edit mobile modules</a>
</div>

<script src="<?= h(asset_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
