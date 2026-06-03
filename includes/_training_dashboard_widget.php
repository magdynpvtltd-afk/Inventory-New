<?php
/**
 * MagDyn — Training Certifications Dashboard Widget
 *
 * Three panels:
 *   - My expiring soon  — certifications belonging to the current user
 *                         that expire within the next 30 days
 *   - My expired        — certifications already expired (need re-take)
 *   - Team status (managers only) — count of users with expiring/expired
 *                         certifications across the team
 *
 * Include from index.php after a permission check:
 *
 *   if (permission_check('training', 'view')) {
 *       include __DIR__ . '/includes/_training_dashboard_widget.php';
 *   }
 *
 * Created: 20260520_080000_IST
 */
require_once __DIR__ . '/_training.php';

$_trUid = current_user_id();
$_trCanManage = permission_check('training', 'manage');

// My expiring soon (within 30 days, not expired yet)
$_trMyExpiring = db_all(
    "SELECT tp.course_id, tp.completed_at, tp.expires_at, c.title, c.validity_months
       FROM training_progress tp
       JOIN training_courses c ON c.id = tp.course_id
      WHERE tp.user_id = ?
        AND tp.expires_at IS NOT NULL
        AND tp.expires_at >= NOW()
        AND tp.expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY)
      ORDER BY tp.expires_at ASC",
    [$_trUid]
);

// My expired
$_trMyExpired = db_all(
    "SELECT tp.course_id, tp.completed_at, tp.expires_at, c.title
       FROM training_progress tp
       JOIN training_courses c ON c.id = tp.course_id
      WHERE tp.user_id = ?
        AND tp.expires_at IS NOT NULL
        AND tp.expires_at < NOW()
      ORDER BY tp.expires_at DESC",
    [$_trUid]
);

// Team rollup (managers only)
$_trTeam = null;
if ($_trCanManage) {
    $_trTeam = db_all(
        "SELECT u.id, u.full_name,
                SUM(CASE WHEN tp.expires_at IS NOT NULL AND tp.expires_at < NOW() THEN 1 ELSE 0 END) AS n_expired,
                SUM(CASE WHEN tp.expires_at IS NOT NULL
                          AND tp.expires_at >= NOW()
                          AND tp.expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS n_expiring
           FROM users u
      LEFT JOIN training_progress tp ON tp.user_id = u.id
          WHERE u.is_active = 1
          GROUP BY u.id, u.full_name
         HAVING n_expired > 0 OR n_expiring > 0
          ORDER BY n_expired DESC, n_expiring DESC, u.full_name
          LIMIT 20"
    );
}

if (empty($_trMyExpiring) && empty($_trMyExpired) && empty($_trTeam)) {
    // Nothing to show — render nothing
    return;
}
?>

<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2 style="margin: 0; font-size: 16px;">Training certifications</h2>
        <a class="btn btn-ghost btn-xs" href="<?= h(url('/training.php')) ?>">All courses →</a>
    </div>
    <div class="card-body" style="display: grid; grid-template-columns: <?= $_trCanManage ? '1fr 1fr 1fr' : '1fr 1fr' ?>; gap: 20px;">

        <!-- MY EXPIRING SOON -->
        <div>
            <h3 style="margin: 0 0 8px; font-size: 13px; text-transform: uppercase; color: var(--text-muted, #6b7280); letter-spacing: 0.04em;">
                My expiring soon
                <span style="font-weight: normal;">(<?= count($_trMyExpiring) ?>)</span>
            </h3>
            <?php if (empty($_trMyExpiring)): ?>
                <p class="muted small">Nothing expiring in the next 30 days.</p>
            <?php else: ?>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($_trMyExpiring as $r):
                        $days = max(0, ceil((strtotime($r['expires_at']) - time()) / 86400));
                    ?>
                        <li style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                            <a href="<?= h(url('/training.php?action=view&id=' . (int)$r['course_id'])) ?>" style="font-weight: 600;">
                                <?= h($r['title']) ?>
                            </a>
                            <br>
                            <span class="pill pill-warning" style="font-size: 10px;">expires in <?= $days ?> day<?= $days === 1 ? '' : 's' ?></span>
                            <span class="muted small"><?= h(date('d M Y', strtotime($r['expires_at']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- MY EXPIRED -->
        <div>
            <h3 style="margin: 0 0 8px; font-size: 13px; text-transform: uppercase; color: var(--text-muted, #6b7280); letter-spacing: 0.04em;">
                My expired
                <span style="font-weight: normal;">(<?= count($_trMyExpired) ?>)</span>
            </h3>
            <?php if (empty($_trMyExpired)): ?>
                <p class="muted small">No expired certifications.</p>
            <?php else: ?>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($_trMyExpired as $r): ?>
                        <li style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                            <a href="<?= h(url('/training.php?action=view&id=' . (int)$r['course_id'])) ?>" style="font-weight: 600;">
                                <?= h($r['title']) ?>
                            </a>
                            <br>
                            <span class="pill pill-danger" style="font-size: 10px;">expired</span>
                            <span class="muted small">on <?= h(date('d M Y', strtotime($r['expires_at']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- TEAM ROLLUP (managers only) -->
        <?php if ($_trCanManage): ?>
        <div>
            <h3 style="margin: 0 0 8px; font-size: 13px; text-transform: uppercase; color: var(--text-muted, #6b7280); letter-spacing: 0.04em;">
                Team status
                <span style="font-weight: normal;">(<?= count($_trTeam) ?> users)</span>
            </h3>
            <?php if (empty($_trTeam)): ?>
                <p class="muted small">All team certifications current.</p>
            <?php else: ?>
                <table class="data-table" style="font-size: 12px;">
                    <thead><tr><th>User</th><th>Expired</th><th>Expiring</th></tr></thead>
                    <tbody>
                        <?php foreach ($_trTeam as $u): ?>
                            <tr>
                                <td><?= h($u['full_name']) ?></td>
                                <td>
                                    <?php if ((int)$u['n_expired'] > 0): ?>
                                        <span class="pill pill-danger"><?= (int)$u['n_expired'] ?></span>
                                    <?php else: ?>
                                        <span class="muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$u['n_expiring'] > 0): ?>
                                        <span class="pill pill-warning"><?= (int)$u['n_expiring'] ?></span>
                                    <?php else: ?>
                                        <span class="muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
