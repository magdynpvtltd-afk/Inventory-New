<?php
/**
 * Standard signed-in page header (sidebar layout).
 * Page-specific JS can be queued by setting $extra_js before include.
 *
 *   $page_title  — string  (window title fragment)
 *   $focus_id    — string  (id of element to autofocus on page load)
 *   $page_module — string  (e.g. 'users') — used to highlight sidebar
 *
 * Created: 20260515_060024_IST
 */
$APP = $GLOBALS['APP'];
$pageTitle = isset($page_title) ? $page_title : '';
$focusId   = isset($focus_id)   ? $focus_id   : '';
$module    = isset($page_module) ? $page_module : '';
$user      = current_user();
$realUser  = real_user();
$modules   = visible_modules();
$navTree   = visible_module_tree();
$flashes   = flash_pull();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ? ($pageTitle . ' · ' . $APP['app_name']) : $APP['app_name']) ?></title>
    <link rel="icon" href="<?= url('/assets/img/icon-192.png') ?>">
    <link rel="apple-touch-icon" href="<?= url('/assets/img/icon-192.png') ?>">
    <link rel="manifest" href="<?= url('/manifest.php') ?>">
    <meta name="theme-color" content="<?= h($APP['pwa']['theme_color']) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/magdyn-base.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/app.css')) ?>">
    <?php if ($focusId): ?>
        <script>window.__FOCUS_ID = <?= json_encode($focusId) ?>;</script>
    <?php endif; ?>
    <script>window.MAGDYN_BASE = <?= json_encode(rtrim($APP['base_url'], '/')) ?>;</script>
    <script>
    /* Pre-paint: read persisted nav-group state before the body parses so
       the sidebar paints in its final state with no flicker on every
       navigation. We use a CSS class (.nav-group-open) instead of toggling
       the `hidden` attribute, so the pre-paint and app.js never fight each
       other for which state wins. Behaviour: ACCORDION — only one group
       open at a time. */
    (function () {
        try {
            var open = localStorage.getItem('magdyn.nav.openGroup') || '';
            window.__navOpenGroup = open;
            // Tiny stylesheet honouring the persisted choice. If the user
            // has no persisted choice we let the server-rendered HTML win
            // (the group containing the current page is auto-expanded).
            if (open) {
                var style = document.createElement('style');
                style.textContent =
                    '.nav-group-children{display:none}' +
                    '#' + open + '{display:block}';
                document.head.appendChild(style);
            }
            // Sidebar collapse pre-paint: read persisted state and apply
            // the class to <html> early so the sidebar renders narrow
            // immediately. We use <html> rather than <aside> so the CSS
            // selector doesn't depend on script execution order.
            //
            // Auto-collapse pages (like the manuals viewer) also force
            // the class even when the user's global preference is
            // expanded — they need the screen space. The runtime toggle
            // handler in app.js notices the is-manual-view body class
            // and skips writing localStorage so the user's global
            // preference is preserved.
            var forceCollapse = <?= !empty($page_force_collapse_sidebar) ? 'true' : 'false' ?>;
            if (forceCollapse || localStorage.getItem('magdyn.sidebar.collapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    })();
    </script>
</head>
<body<?= !empty($page_body_class) ? ' class="' . h($page_body_class) . '"' : '' ?>>
<div class="layout">
    <aside class="sidebar" aria-label="Primary navigation">
        <div class="brand">
            <div class="brand-mark"><img src="<?= url('/assets/img/logo.png') ?>" alt=""></div>
            <div class="brand-text">
                <div class="brand-title"><?= h($APP['app_name']) ?></div>
            </div>
            <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn"
                    title="Collapse / expand sidebar" aria-label="Collapse sidebar">«</button>
        </div>
        <nav class="nav" id="sidebarNav">
            <?php foreach ($navTree as $entry): ?>
                <?php if ($entry['type'] === 'module'):
                    $m = $entry['data']; $code = $m['code'];
                    $chord = $m['shortcut'];
                    // accesskey is single-char only; use it only when our chord
                    // is also single-char to avoid contention with chord mode.
                    $accesskey = (strlen($chord) === 1) ? strtolower($chord) : ''; ?>
                    <a class="nav-item<?= $module === $code ? ' active' : '' ?>"
                       href="<?= h(route($code, 'index')) ?>"
                       title="<?= h($m['name']) ?>"
                       data-shortcut="<?= h($chord) ?>"
                       <?= $accesskey ? 'accesskey="' . h($accesskey) . '"' : '' ?>
                       tabindex="0">
                        <span class="nav-icon"><?= h(module_icon($code, $m['icon'])) ?></span>
                        <span class="nav-label"><?= shortcut_label($m['name'], $chord) ?></span>
                    </a>
                <?php else:
                    $g = $entry['data']; $gid = 'grp-' . $g['code'];
                    // Auto-open the group ONLY if the current page is one of
                    // its children OR (one level deeper) one of a sub-group's
                    // grandchildren. JS state in localStorage can override
                    // either way for user preference.
                    $childCodes = array_column($g['children'], 'code');
                    // Also gather grandchild codes from any nested sub-groups
                    // so the parent group auto-opens when a grandchild page
                    // is active. Without this, opening e.g. /tools.php?tool=calc&view=stackup
                    // would leave the Tools group collapsed.
                    foreach ($g['children'] as $_c) {
                        if (!empty($_c['is_group']) && !empty($_c['children'])) {
                            foreach ($_c['children'] as $_gc) {
                                $childCodes[] = $_gc['code'];
                            }
                        }
                    }
                    $open = in_array($module, $childCodes, true);
                    $childCount = count($g['children']);
                    $chord = $g['shortcut'];
                ?>
                    <button type="button"
                            class="nav-item nav-group-toggle<?= $open ? ' open' : '' ?>"
                            data-group="<?= h($gid) ?>"
                            title="<?= h($g['name']) ?>"
                            aria-expanded="<?= $open ? 'true' : 'false' ?>"
                            aria-controls="<?= h($gid) ?>"
                            tabindex="0">
                        <span class="nav-icon"><?= h(module_icon('group_' . $g['code'], "\xE2\x96\xA0")) ?></span>
                        <span class="nav-label"><?= shortcut_label($g['name'], $chord) ?></span>
                        <?php if ($childCount): ?>
                            <span class="nav-group-count" aria-hidden="true"><?= (int)$childCount ?></span>
                        <?php endif; ?>
                        <span class="nav-chevron" aria-hidden="true">▾</span>
                    </button>
                    <div class="nav-group-children" id="<?= h($gid) ?>" <?= $open ? '' : 'hidden' ?>>
                        <?php foreach ($g['children'] as $m): $code = $m['code']; ?>
                            <?php if (!empty($m['is_group'])):
                                // Nested sub-group: render as a second-level toggle
                                // that mirrors the top-level group pattern, just
                                // visually indented. Its own children render below.
                                $sgid = 'sgrp-' . $code;
                                $sgChildCodes = array_column($m['children'] ?? [], 'code');
                                $sgOpen = in_array($module, $sgChildCodes, true);
                                $sgChildCount = count($m['children'] ?? []);
                                ?>
                                <button type="button"
                                        class="nav-item nav-child nav-subgroup-toggle<?= $sgOpen ? ' open' : '' ?>"
                                        data-group="<?= h($sgid) ?>"
                                        aria-expanded="<?= $sgOpen ? 'true' : 'false' ?>"
                                        aria-controls="<?= h($sgid) ?>"
                                        tabindex="0">
                                    <span class="nav-icon"><?= h(module_icon($code, $m['icon'])) ?></span>
                                    <span class="nav-label"><?= h($m['name']) ?></span>
                                    <?php if ($sgChildCount): ?>
                                        <span class="nav-group-count" aria-hidden="true"><?= (int)$sgChildCount ?></span>
                                    <?php endif; ?>
                                    <span class="nav-chevron" aria-hidden="true">▾</span>
                                </button>
                                <div class="nav-subgroup-children" id="<?= h($sgid) ?>" <?= $sgOpen ? '' : 'hidden' ?>>
                                    <?php foreach ($m['children'] as $sgm): $sgcode = $sgm['code'];
                                        $sgvu = !empty($sgm['virtual_url']) ? $sgm['virtual_url']
                                              : (!empty($sgm['_virtual_url']) ? $sgm['_virtual_url'] : '');
                                        $sgHref = $sgvu !== '' ? url($sgvu) : route($sgcode, 'index');
                                    ?>
                                        <a class="nav-item nav-child nav-grandchild<?= $module === $sgcode ? ' active' : '' ?>"
                                           href="<?= h($sgHref) ?>"
                                           tabindex="0">
                                            <span class="nav-icon"><?= h(module_icon($sgcode, $sgm['icon'])) ?></span>
                                            <span class="nav-label"><?= h($sgm['name']) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else:
                                // Leaf child: standard link.
                                $vu = !empty($m['virtual_url']) ? $m['virtual_url']
                                    : (!empty($m['_virtual_url']) ? $m['_virtual_url'] : '');
                                $childHref = $vu !== '' ? url($vu) : route($code, 'index');
                                $cchord = $m['shortcut'];
                                // accesskey is only safe for 1-char chords. Children
                                // typically have 2-char chords (e.g. "RA") so we skip
                                // accesskey for them entirely — the chord engine
                                // handles them via keydown.
                                $cak = (strlen($cchord) === 1) ? strtolower($cchord) : '';
                                // The full chord (data-shortcut) goes to the JS so
                                // the chord engine can match the 2-key sequence. The
                                // visible label only underlines the LOCAL letter (the
                                // second char) since the parent letter is implicit
                                // from the group context.
                                $visibleChord = strlen($cchord) >= 2 ? substr($cchord, 1, 1) : $cchord;
                            ?>
                                <a class="nav-item nav-child<?= $module === $code ? ' active' : '' ?>"
                                   href="<?= h($childHref) ?>"
                                   data-shortcut="<?= h($cchord) ?>"
                                   <?= $cak ? 'accesskey="' . h($cak) . '"' : '' ?>
                                   tabindex="0">
                                    <span class="nav-icon"><?= h(module_icon($code, $m['icon'])) ?></span>
                                    <span class="nav-label"><?= shortcut_label($m['name'], $visibleChord) ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <?php
                // 2-letter initial from full_name for the avatar
                $initials = '';
                $parts = preg_split('/\s+/', trim($user['full_name'] ?? ''));
                foreach ($parts as $p) { if ($p !== '' && strlen($initials) < 2) $initials .= strtoupper(substr($p, 0, 1)); }
                if ($initials === '') $initials = strtoupper(substr($user['username'] ?? '?', 0, 2));

                // Unread notification count for the current user. Cached per
                // request via the static below since header.php is included
                // once per page render. Notifications table was introduced
                // by the job_card module migration; we check for table
                // existence to stay safe on installs that haven't run it
                // yet. Once every install has migrated this check can be
                // removed — it's a couple of microseconds per render.
                static $unreadCount = null;
                if ($unreadCount === null) {
                    $unreadCount = 0;
                    try {
                        $unreadCount = (int)db_val(
                            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
                            [(int)$user['id']], 0
                        );
                    } catch (\Throwable $e) {
                        // Table missing — pre-migration install. Quietly
                        // render zero; the bell still works as a link.
                        $unreadCount = 0;
                    }
                }
            ?>
            <a href="<?= h(url('/job_card.php?action=notifications')) ?>"
               class="notif-bell"
               title="Notifications<?= $unreadCount ? ' (' . $unreadCount . ' unread)' : '' ?>"
               aria-label="Notifications">
                <span class="notif-bell-icon">🔔</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <div class="user-card">
                <div class="user-avatar"><?= h($initials) ?></div>
                <div class="user-meta">
                    <div class="user-name"><?= h($user['full_name']) ?></div>
                    <div class="user-role"><?= h($user['email']) ?></div>
                </div>
            </div>
            <a href="<?= h(url('/logout.php')) ?>" class="logout"
               data-shortcut="L" accesskey="l" data-no-spa>
                <?= shortcut_label('Sign out', 'L') ?>
            </a>
        </div>
        <style>
            /* Notification bell — sits above the user card in the sidebar
               footer. Compact pill with the bell glyph + an optional red
               badge. Click → /job_card.php?action=notifications. */
            .notif-bell {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 12px;
                margin-bottom: 8px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.05);
                color: rgba(255, 255, 255, 0.78);
                text-decoration: none;
                font-size: 13px;
                position: relative;
                transition: background 0.12s, color 0.12s;
            }
            .notif-bell:hover { background: rgba(255, 255, 255, 0.12); color: white; }
            .notif-bell-icon { font-size: 16px; line-height: 1; }
            .notif-bell::after {
                content: 'Notifications';
                font-weight: 500;
            }
            /* When sidebar is collapsed (icon-only), hide the label. */
            .sidebar.is-collapsed .notif-bell::after { display: none; }
            .sidebar.is-collapsed .notif-bell { justify-content: center; }
            /* Red unread badge — small pill at top-right of the bell */
            .notif-bell-badge {
                position: absolute;
                top: 2px; left: 24px;
                background: #ef4444;
                color: white;
                font-size: 10px;
                font-weight: 700;
                line-height: 1;
                padding: 2px 5px;
                border-radius: 999px;
                min-width: 14px;
                text-align: center;
                box-shadow: 0 0 0 2px var(--sb-bg, #0f172a);
            }
        </style>
    </aside>
    <main class="main">
        <?php if (is_impersonating()): ?>
            <div class="impersonate-banner">
                <span>
                    👁 You are viewing as <strong><?= h($user['full_name']) ?></strong>
                    (real user: <?= h($realUser['full_name']) ?>)
                </span>
                <a href="<?= h(url('/users.php?action=stop_impersonate')) ?>"
                   class="btn btn-warn btn-sm" data-shortcut="X" accesskey="x">
                    <?= shortcut_label('Exit view-as', 'X') ?>
                </a>
            </div>
        <?php endif; ?>

        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= h($f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'error' : ($f['type'] === 'warn' ? 'warn' : 'info'))) ?>">
                <?= h($f['msg']) ?>
            </div>
        <?php endforeach; ?>
