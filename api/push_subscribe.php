<?php
/**
 * MagDyn — Push subscription endpoint
 * Created: 20260515_060024_IST
 *
 * Receives the PushSubscription JSON the browser produces after the user
 * grants permission, and stores it against the current user so the server
 * can push notifications later.
 *
 * Expected JSON body shape (what the browser yields):
 *   {
 *     "endpoint": "https://fcm.googleapis.com/fcm/send/...",
 *     "expirationTime": null,
 *     "keys": { "p256dh": "...", "auth": "..." }
 *   }
 *
 * The actual *sending* of pushes is a separate server-side job; this file
 * is only the receiver. To send, use a library like minishlink/web-push,
 * pass it the VAPID keys from config/app.config.php, and iterate over
 * rows in push_subscriptions.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!real_user_id()) {
    http_response_code(401);
    echo json_encode(['error' => 'not signed in']);
    exit;
}

$raw = file_get_contents('php://input');
$sub = json_decode($raw, true);
if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid subscription payload']);
    exit;
}

$uid       = current_user_id();
$endpoint  = (string)$sub['endpoint'];
$p256dh    = (string)$sub['keys']['p256dh'];
$auth      = (string)$sub['keys']['auth'];
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

// Upsert by endpoint
$existing = db_one('SELECT id FROM push_subscriptions WHERE endpoint = ?', [$endpoint]);
if ($existing) {
    db_exec(
        'UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth_key = ?, user_agent = ? WHERE id = ?',
        [$uid, $p256dh, $auth, $userAgent, $existing['id']]
    );
} else {
    db_exec(
        'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, user_agent)
         VALUES (?, ?, ?, ?, ?)',
        [$uid, $endpoint, $p256dh, $auth, $userAgent]
    );
}

echo json_encode(['ok' => true]);
