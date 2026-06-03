<?php
/**
 * MagDyn — First-run setup
 * Created: 20260515_060024_IST
 *
 * Run this ONCE after importing sql/schema.sql, by visiting it in a browser
 * or running php -f setup.php from the CLI.
 *
 * It will:
 *   1. Create the seed admin / viewer users with real password_hash() values
 *      (peppered to match auth_local() in includes/auth.php).
 *   2. Wire up the role assignments for them.
 *   3. Attribute the seeded "Welcome to MagDyn" course to admin.
 *
 * DELETE this file once it has been run on a production install — its sole
 * purpose is bootstrapping and it should not stay reachable on the web.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// Refuse to run twice
$adminExists = db_one("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
$viewerExists = db_one("SELECT id FROM users WHERE username = 'viewer' LIMIT 1");

$messages = [];
$pepper = $GLOBALS['APP']['password_pepper'];

if (!$adminExists) {
    $hash = password_hash('admin123' . $pepper, PASSWORD_DEFAULT);
    db_exec(
        "INSERT INTO users (username, email, full_name, password_hash, sso_provider, is_active)
         VALUES ('admin', 'admin@example.com', 'System Administrator', ?, 'local', 1)",
        [$hash]
    );
    $adminId = db()->lastInsertId();
    db_exec(
        "INSERT INTO user_roles (user_id, role_id)
         SELECT ?, id FROM roles WHERE code = 'admin'",
        [$adminId]
    );
    $messages[] = "Created admin (password: admin123)";
} else {
    $adminId = $adminExists['id'];
    $messages[] = "admin user already exists — skipped";
}

if (!$viewerExists) {
    $hash = password_hash('viewer123' . $pepper, PASSWORD_DEFAULT);
    db_exec(
        "INSERT INTO users (username, email, full_name, password_hash, sso_provider, is_active)
         VALUES ('viewer', 'viewer@example.com', 'Demo Viewer', ?, 'local', 1)",
        [$hash]
    );
    $viewerId = db()->lastInsertId();
    db_exec(
        "INSERT INTO user_roles (user_id, role_id)
         SELECT ?, id FROM roles WHERE code = 'viewer'",
        [$viewerId]
    );
    $messages[] = "Created viewer (password: viewer123)";
} else {
    $messages[] = "viewer user already exists — skipped";
}

// Attribute the welcome course to admin
db_exec("UPDATE training_courses SET created_by = ? WHERE created_by IS NULL", [$adminId]);

// Subscribe both seed users to all default notification types
db_exec(
    "INSERT IGNORE INTO user_notification_prefs (user_id, notification_type_id, channel_web, channel_email, channel_push)
     SELECT u.id, nt.id, 1, 1, 1 FROM users u, notification_types nt"
);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup — MagDyn</title>
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/magdyn-base.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-wrap">
    <div class="login-card" style="max-width: 540px;">
        <div class="login-brand">
            <img src="<?= h(url('/assets/img/logo.png')) ?>" alt="" style="width: 96px;">
            <div class="brand-title">Setup complete</div>
        </div>
        <ul style="margin: 16px 0; padding-left: 20px;">
            <?php foreach ($messages as $m): ?>
                <li><?= h($m) ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="alert alert-warn">
            <strong>Delete setup.php now.</strong> Its job is done and it should not be
            reachable on a production install.
        </div>
        <a class="btn btn-primary btn-block" href="<?= h(url('/login.php')) ?>">Go to sign-in</a>
    </div>
</div>
</body>
</html>
