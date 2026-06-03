<?php
/**
 * MagDyn — Calibration due-date notification cron
 * Created: 20260515_073000_IST
 *
 * Run daily from cron, e.g.:
 *   0 8 * * *  /usr/bin/php /var/www/magdyn/cron/calibration_notify.php
 *
 * Scans the assets table for items with next_cal_due_on in (overdue,
 * today, +7d, +30d) and queues a notification for every user who has
 * the 'calibration_due' type enabled. The actual delivery (in-app /
 * email / push) is the job of your delivery worker — this script only
 * inserts into the audit log so you can see it fired, and respects the
 * user's per-channel preferences in user_notification_prefs.
 *
 * Idempotency: keeps a small "calibration_notify_log" table so an asset
 * is only flagged at each milestone once.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Ensure the idempotency table exists. Safe to call every run.
db_exec("
    CREATE TABLE IF NOT EXISTS calibration_notify_log (
        asset_id   INT UNSIGNED NOT NULL,
        milestone  VARCHAR(16)  NOT NULL,   -- 'overdue','due','7d','30d'
        notified_on DATE        NOT NULL,
        PRIMARY KEY (asset_id, milestone, notified_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$today = date('Y-m-d');

// Helper: pick which milestone an asset falls into today
function classify($dueDate, $today) {
    if ($dueDate < $today)          return 'overdue';
    if ($dueDate === $today)        return 'due';
    $diff = (strtotime($dueDate) - strtotime($today)) / 86400;
    if ($diff <= 7)                 return '7d';
    if ($diff <= 30)                return '30d';
    return null;
}

$assets = db_all(
    "SELECT a.id, a.asset_tag, a.next_cal_due_on, m.name AS model_name, l.name AS location_name
       FROM assets a
       LEFT JOIN asset_models m ON m.id = a.model_id
       LEFT JOIN locations l    ON l.id = a.location_id
      WHERE a.status IN ('active','with_vendor','with_user')
        AND a.next_cal_due_on IS NOT NULL
        AND a.next_cal_due_on <= DATE_ADD(?, INTERVAL 30 DAY)",
    [$today]
);

// Find the notification type and the subscribed user ids per channel
$ntype = db_one("SELECT id FROM notification_types WHERE code = 'calibration_due'");
if (!$ntype) {
    fwrite(STDERR, "calibration_due notification type missing — apply migration first.\n");
    exit(1);
}
$typeId = (int)$ntype['id'];

$subscribers = db_all(
    "SELECT user_id, channel_web, channel_email, channel_push
       FROM user_notification_prefs
      WHERE notification_type_id = ?
        AND (channel_web = 1 OR channel_email = 1 OR channel_push = 1)",
    [$typeId]
);

$fired = 0; $skipped = 0;
foreach ($assets as $a) {
    $milestone = classify($a['next_cal_due_on'], $today);
    if (!$milestone) { $skipped++; continue; }

    // Idempotency: already notified for this milestone on this day?
    $already = db_one(
        "SELECT 1 FROM calibration_notify_log
          WHERE asset_id = ? AND milestone = ? AND notified_on = ?",
        [(int)$a['id'], $milestone, $today]
    );
    if ($already) { $skipped++; continue; }

    db_exec(
        "INSERT INTO calibration_notify_log (asset_id, milestone, notified_on) VALUES (?, ?, ?)",
        [(int)$a['id'], $milestone, $today]
    );

    $msg = sprintf(
        "Calibration %s for %s (%s) at %s — due %s",
        $milestone === 'overdue' ? 'OVERDUE' : "due in {$milestone}",
        $a['asset_tag'], $a['model_name'] ?: 'unknown model',
        $a['location_name'] ?: 'unknown location',
        $a['next_cal_due_on']
    );

    foreach ($subscribers as $s) {
        // Web / in-app: write to audit_log so users see it in Notifications
        // (Replace with a real notifications table if you add one.)
        if ($s['channel_web']) {
            db_exec(
                "INSERT INTO audit_log (actor_id, target_id, action, details)
                 VALUES (NULL, ?, 'notify.calibration_due', ?)",
                [(int)$s['user_id'], $msg]
            );
        }
        // Email: stub — wire your SMTP / queue here
        // if ($s['channel_email']) { mail_user($s['user_id'], 'Calibration alert', $msg); }
        // Push: stub — iterate push_subscriptions for this user and
        // send via minishlink/web-push using config/app.config.php VAPID keys.
    }
    $fired++;
}

fprintf(STDOUT, "calibration_notify: %d notifications queued, %d skipped\n", $fired, $skipped);
