<?php
/**
 * MagDyn — Generic module dispatcher
 * Created: 20260515_090000_IST
 *
 * Renders a "Coming soon" placeholder for any module that doesn't yet have
 * its own PHP file. The sidebar's route() helper sends unknown modules here
 * via /module.php?m=<code>.
 *
 * Once you ship a real page for a module (e.g. /inv_stock_levels.php), add
 * its code to _routed_modules() in includes/helpers.php and links will
 * automatically prefer the real page over this dispatcher.
 *
 * Permission gate: requires <module>.view. Other actions inside the real
 * implementation will enforce their own permissions.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$code = trim((string)input('m', ''));
if ($code === '') {
    flash_set('error', 'Module not specified.');
    redirect(url('/index.php'));
}

// Look the module up. It must exist in the DB and be active.
$mod = db_one('SELECT * FROM modules WHERE code = ? AND is_active = 1', [$code]);
if (!$mod) {
    flash_set('error', 'Module not found or inactive: ' . $code);
    redirect(url('/index.php'));
}

// Only leaves are dispatchable here. Groups are nav-only; if someone lands
// on a group URL, send them to the dashboard rather than rendering an
// empty page.
if (!empty($mod['is_group'])) {
    flash_set('error', 'That module is a navigation group, not a page.');
    redirect(url('/index.php'));
}

// If this module has a virtual_url (points into another page like
// asset.php?action=...), forward instead of rendering here.
if (!empty($mod['virtual_url'])) {
    redirect(url($mod['virtual_url']));
}

// Permission gate
require_permission($code, 'view');

// Resolve the parent group (if any) for breadcrumb display
$parent = null;
if (!empty($mod['parent_id'])) {
    $parent = db_one('SELECT id, code, name FROM modules WHERE id = ?', [(int)$mod['parent_id']]);
}

$canManage = permission_check($code, 'manage');

$page_title  = $mod['name'];
$page_module = $code;
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1><?= h($mod['name']) ?></h1>
        <p class="muted">
            <?php if ($parent): ?>
                <a href="<?= h(url('/module.php?m=' . $parent['code'])) ?>"><?= h($parent['name']) ?></a>
                &nbsp;<span aria-hidden="true">›</span>&nbsp;
            <?php endif; ?>
            <?= h($mod['description'] ?: 'Module placeholder') ?>
        </p>
    </div>
</div>

<div class="card" style="padding: 32px; text-align: center;">
    <div style="font-size: 48px; opacity: 0.5; margin-bottom: 12px;">
        <?= h(module_icon($code, "\xF0\x9F\x9A\xA7")) ?>  <?php // 🚧 ?>
    </div>
    <h2 style="margin: 0 0 8px 0;">Coming soon</h2>
    <p class="muted" style="max-width: 480px; margin: 0 auto 16px;">
        The <strong><?= h($mod['name']) ?></strong> page hasn't been built yet.
        This is a placeholder rendered by the generic module dispatcher.
    </p>
    <p class="muted small" style="max-width: 480px; margin: 0 auto;">
        Module code: <code><?= h($code) ?></code>
        <?php if ($canManage): ?>
            &nbsp;·&nbsp; You have <strong>manage</strong> permission on this module.
        <?php endif; ?>
    </p>
    <div style="margin-top: 24px;">
        <a class="btn btn-ghost" href="<?= h(url('/index.php')) ?>"
           data-shortcut="D" accesskey="d"><?= shortcut_label('← Back to Dashboard', 'D') ?></a>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
