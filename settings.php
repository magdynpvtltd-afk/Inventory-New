<?php
/**
 * MagDyn — Settings (Phase D2)
 *
 * Tabs:
 *   tab=smtp   — SMTP credentials for outbound email (Phase D2)
 *
 * Future tabs go here too. Each tab has a corresponding `_render`
 * function and uses POST against ?action=save&tab=<tab>.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_email.php';

require_permission('settings', 'view');
$action = (string)input('action', '');
$tab    = (string)input('tab', 'smtp');
$canManage = permission_check('settings', 'manage');

// ============================================================
// SAVE handler — POST ?action=save&tab=...
// ============================================================
if ($action === 'save') {
    if (!$canManage) {
        flash_set('error', 'You do not have permission to edit settings.');
        redirect(url('/settings.php?tab=' . urlencode($tab)));
    }
    csrf_check();

    if ($tab === 'smtp') {
        $keys = [
            'smtp.enabled'    => (string)((int)input('enabled', 0)),
            'smtp.host'       => trim((string)input('host', '')),
            'smtp.port'       => (string)(int)input('port', 587),
            'smtp.user'       => trim((string)input('user', '')),
            'smtp.secure'     => in_array(input('secure', 'tls'), ['tls','ssl','none'], true) ? (string)input('secure', 'tls') : 'tls',
            'smtp.from_email' => trim((string)input('from_email', '')),
            'smtp.from_name'  => trim((string)input('from_name', '')),
            'smtp.reply_to'   => trim((string)input('reply_to', '')),
        ];
        // Password: empty means "keep existing". Operator must type a
        // value once to set it. UI shows "(set)" hint when stored.
        $newPass = (string)input('pass', '');
        if ($newPass !== '') {
            $keys['smtp.pass'] = $newPass;
        }
        foreach ($keys as $k => $v) {
            db_exec(
                "INSERT INTO magdyn_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [$k, $v]
            );
        }
        // Bust the static cache inside magdyn_setting() by reloading.
        // Easiest: php-level redirect, which gives us a fresh request.
        flash_set('success', 'SMTP settings saved.');
        redirect(url('/settings.php?tab=smtp'));
    }

    flash_set('error', 'Unknown settings tab.');
    redirect(url('/settings.php'));
}

// ============================================================
// SEND TEST handler — sends a quick test email to the operator
// ============================================================
if ($action === 'smtp_test') {
    if (!$canManage) { redirect(url('/settings.php?tab=smtp')); }
    csrf_check();
    $to = trim((string)input('test_to', ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email address for the test send.');
        redirect(url('/settings.php?tab=smtp'));
    }
    $res = smtp_send([
        'related_type' => 'smtp_test',
        'related_id'   => null,
        'to'           => $to,
        'subject'      => 'MagDyn SMTP test',
        'body_html'    => '<p>This is a test from your MagDyn ERP SMTP setup. If you can read this, outbound email is working.</p>'
                        . '<p style="color:#999;">Sent at ' . h(date('Y-m-d H:i:s')) . '</p>',
        'actor_id'     => current_user_id(),
    ]);
    if ($res['ok']) {
        flash_set('success', 'Test email sent. Check the inbox at ' . h($to) . '.');
    } else {
        flash_set('error', 'Test send failed: ' . $res['error']);
    }
    redirect(url('/settings.php?tab=smtp'));
}

// ============================================================
// RENDER
// ============================================================
$page_title  = 'Settings';
$page_module = 'settings';
$focus_id    = '';
require __DIR__ . '/includes/header.php';

// Build the tab strip. Add new tabs here as more settings sections appear.
$tabs = [
    'smtp' => 'SMTP',
];
?>
<div class="page-head">
    <h1>Settings</h1>
</div>

<div style="display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 14px;">
    <?php foreach ($tabs as $tCode => $tLabel):
        $active = ($tCode === $tab);
    ?>
        <a href="<?= h(url('/settings.php?tab=' . urlencode($tCode))) ?>"
           class="btn btn-ghost btn-sm"
           style="border-radius: 0; border-bottom: 2px solid <?= $active ? 'var(--primary)' : 'transparent' ?>; <?= $active ? 'font-weight:600;' : '' ?>">
            <?= h($tLabel) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'smtp'):
    $enabled   = (string)magdyn_setting('smtp.enabled', '0');
    $host      = (string)magdyn_setting('smtp.host', '');
    $port      = (string)magdyn_setting('smtp.port', '587');
    $user      = (string)magdyn_setting('smtp.user', '');
    $passSet   = ((string)magdyn_setting('smtp.pass', '')) !== '';
    $secure    = (string)magdyn_setting('smtp.secure', 'tls');
    $fromEmail = (string)magdyn_setting('smtp.from_email', '');
    $fromName  = (string)magdyn_setting('smtp.from_name', 'Magneto Dynamics');
    $replyTo   = (string)magdyn_setting('smtp.reply_to', '');
?>
    <div class="card" style="padding: 16px 18px; max-width: 760px;">
        <h2 style="margin-top: 0;">SMTP credentials</h2>
        <p class="muted small">
            Outbound email (Send mail from PO, future system notifications) goes through your
            Hostinger mailbox via authenticated SMTP. Get these values from your Hostinger
            email panel: <em>Email accounts → your mailbox → Connect apps and devices</em>.
        </p>

        <form method="post" action="<?= h(url('/settings.php?action=save&tab=smtp')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label class="inline" style="gap: 8px;">
                    <input type="checkbox" name="enabled" value="1" <?= $enabled === '1' ? 'checked' : '' ?>>
                    <strong>Enable SMTP</strong> — when off, send-mail actions refuse with a clear error.
                </label>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="f_host">Host</label>
                    <input type="text" id="f_host" name="host" value="<?= h($host) ?>" placeholder="smtp.hostinger.com" <?= $canManage ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label for="f_port">Port</label>
                    <input type="number" id="f_port" name="port" value="<?= h($port) ?>" min="1" max="65535" <?= $canManage ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="f_user">Username</label>
                    <input type="text" id="f_user" name="user" value="<?= h($user) ?>" placeholder="no-reply@yourdomain.com" autocomplete="off" <?= $canManage ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label for="f_pass">Password <span class="muted small"><?= $passSet ? '(currently set — leave blank to keep)' : '(not set)' ?></span></label>
                    <input type="password" id="f_pass" name="pass" value="" autocomplete="new-password" <?= $canManage ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="f_secure">Security</label>
                    <select id="f_secure" name="secure" class="no-combobox" <?= $canManage ? '' : 'disabled' ?>>
                        <option value="tls"  <?= $secure === 'tls'  ? 'selected' : '' ?>>STARTTLS (port 587, recommended)</option>
                        <option value="ssl"  <?= $secure === 'ssl'  ? 'selected' : '' ?>>SSL/TLS (port 465)</option>
                        <option value="none" <?= $secure === 'none' ? 'selected' : '' ?>>None (port 25, not recommended)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="f_from_email">From email</label>
                    <input type="text" id="f_from_email" name="from_email" value="<?= h($fromEmail) ?>" placeholder="no-reply@yourdomain.com" <?= $canManage ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="f_from_name">From name</label>
                    <input type="text" id="f_from_name" name="from_name" value="<?= h($fromName) ?>" placeholder="Magneto Dynamics" <?= $canManage ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label for="f_reply_to">Default Reply-To <span class="muted small">(optional)</span></label>
                    <input type="text" id="f_reply_to" name="reply_to" value="<?= h($replyTo) ?>" placeholder="purchases@yourdomain.com" <?= $canManage ? '' : 'disabled' ?>>
                </div>
            </div>

            <?php if ($canManage): ?>
                <div class="form-actions" style="margin-top: 12px;">
                    <button type="submit" class="btn btn-primary">💾 Save SMTP settings</button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($canManage): ?>
        <div class="card" style="padding: 16px 18px; margin-top: 14px; max-width: 760px;">
            <h2 style="margin-top: 0;">Test send</h2>
            <p class="muted small">Send a short test message to verify your SMTP setup actually delivers.</p>
            <form method="post" action="<?= h(url('/settings.php?action=smtp_test&tab=smtp')) ?>" style="display:flex; gap:8px; align-items:flex-end;">
                <?= csrf_field() ?>
                <div class="field" style="flex: 1;">
                    <label for="f_test_to">Send a test to</label>
                    <input type="email" id="f_test_to" name="test_to" required placeholder="you@yourdomain.com">
                </div>
                <button type="submit" class="btn btn-ghost">📤 Send test</button>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
