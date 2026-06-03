<?php
/**
 * MagDyn — Dashboard
 * Created: 20260515_060024_IST
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$page_title  = 'Dashboard';
$page_module = 'dashboard';
$focus_id    = 'global-search';

$me = current_user();

// Stat queries
$userCount   = db_val('SELECT COUNT(*) FROM users WHERE is_active = 1', [], 0);
$roleCount   = db_val('SELECT COUNT(*) FROM roles', [], 0);
$courseCount = db_val('SELECT COUNT(*) FROM training_courses WHERE is_active = 1', [], 0);
$myProgress  = db_val(
    'SELECT COUNT(*) FROM training_progress WHERE user_id = ? AND completed_at IS NOT NULL',
    [current_user_id()], 0
);

$recent = db_all(
    'SELECT a.*, u.full_name AS actor_name, t.full_name AS target_name
       FROM audit_log a
       LEFT JOIN users u ON u.id = a.actor_id
       LEFT JOIN users t ON t.id = a.target_id
      ORDER BY a.at DESC LIMIT 10'
);

// Calibration health — only query if assets table exists (so dashboards
// still render before the asset migration is applied)
$calOverdueCount  = 0;
$calDueSoonCount  = 0;
$calOverdueAssets = [];
$calDueSoonAssets = [];
if (db_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'assets'")) {
    $calOverdueCount = (int)db_val(
        "SELECT COUNT(*) FROM assets
          WHERE status IN ('active','with_vendor','with_user')
            AND next_cal_due_on IS NOT NULL
            AND next_cal_due_on < CURDATE()", [], 0
    );
    $calDueSoonCount = (int)db_val(
        "SELECT COUNT(*) FROM assets
          WHERE status IN ('active','with_vendor','with_user')
            AND next_cal_due_on IS NOT NULL
            AND next_cal_due_on >= CURDATE()
            AND next_cal_due_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)", [], 0
    );
    if (permission_check('asset', 'view')) {
        $calOverdueAssets = db_all(
            "SELECT a.id, a.asset_tag, a.next_cal_due_on,
                    m.name AS model_name, l.name AS location_name,
                    DATEDIFF(CURDATE(), a.next_cal_due_on) AS days_overdue
               FROM assets a
               LEFT JOIN asset_models m ON m.id = a.model_id
               LEFT JOIN locations l    ON l.id = a.location_id
              WHERE a.status IN ('active','with_vendor','with_user')
                AND a.next_cal_due_on IS NOT NULL
                AND a.next_cal_due_on < CURDATE()
              ORDER BY a.next_cal_due_on
              LIMIT 8"
        );
        $calDueSoonAssets = db_all(
            "SELECT a.id, a.asset_tag, a.next_cal_due_on,
                    m.name AS model_name, l.name AS location_name,
                    DATEDIFF(a.next_cal_due_on, CURDATE()) AS days_until
               FROM assets a
               LEFT JOIN asset_models m ON m.id = a.model_id
               LEFT JOIN locations l    ON l.id = a.location_id
              WHERE a.status IN ('active','with_vendor','with_user')
                AND a.next_cal_due_on IS NOT NULL
                AND a.next_cal_due_on >= CURDATE()
                AND a.next_cal_due_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              ORDER BY a.next_cal_due_on
              LIMIT 8"
        );
    }
}

// Inspection health — pending + overdue counts plus a short list of
// open records. Guarded by table existence so this works before the
// inspection migration is applied.
$inspPendingCount  = 0;
$inspOverdueCount  = 0;
$inspPendingRows   = [];
if (db_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inspections'")) {
    $inspPendingCount = (int)db_val(
        "SELECT COUNT(*) FROM inspections
          WHERE is_deleted = 0
            AND status IN ('draft','in_progress','pending_approval','rework','hold')", [], 0
    );
    $inspOverdueCount = (int)db_val(
        "SELECT COUNT(*) FROM inspections
          WHERE is_deleted = 0
            AND due_date IS NOT NULL
            AND due_date < CURDATE()
            AND status IN ('draft','in_progress','pending_approval','rework','hold')", [], 0
    );
    if (permission_check('inspection', 'view')) {
        // Pending list ordered by: overdue first (oldest first), then
        // due-soon, then no-due. The CASE forces overdue/due-soon ahead
        // of NULL dues regardless of their date value.
        $inspPendingRows = db_all(
            "SELECT i.id, i.code, i.inspection_type, i.status, i.due_date,
                    i.entity_type, i.entity_id, i.planned_at,
                    iu.full_name AS inspected_by_name,
                    CASE
                        WHEN i.entity_type = 'asset'    THEN ea.asset_tag
                        WHEN i.entity_type = 'inv_item' THEN
                            CONCAT('(', COALESCE(ei.code, ''), ')-',
                                   COALESCE(NULLIF(ei.short_description, ''), ei.name, ''))
                        WHEN i.entity_type = 'inv_txn'  THEN
                            COALESCE(
                                CONCAT('(', et_i.code, ')-',
                                       COALESCE(NULLIF(et_i.short_description, ''), et_i.name, '')),
                                CONCAT('Txn #', i.entity_id))
                        ELSE NULL
                    END AS entity_label
               FROM inspections i
               LEFT JOIN users iu ON iu.id = i.inspected_by
               LEFT JOIN assets    ea ON i.entity_type = 'asset'    AND ea.id = i.entity_id
               LEFT JOIN inv_items ei ON i.entity_type = 'inv_item' AND ei.id = i.entity_id
               LEFT JOIN inv_txns  et   ON i.entity_type = 'inv_txn' AND et.id   = i.entity_id
               LEFT JOIN inv_items et_i ON i.entity_type = 'inv_txn' AND et_i.id = et.item_id
              WHERE i.is_deleted = 0
                AND i.status IN ('draft','in_progress','pending_approval','rework','hold')
              ORDER BY
                CASE WHEN i.due_date IS NULL THEN 1 ELSE 0 END,
                i.due_date ASC,
                i.id DESC
              LIMIT 10"
        );
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Welcome, <?= h($me['full_name']) ?>. Hold <kbd>Alt</kbd> to reveal shortcuts.</p>
    </div>
    <div class="head-actions">
        <form class="search" role="search" onsubmit="event.preventDefault();">
            <input id="global-search" type="search" placeholder="Search (Alt+/)" tabindex="1">
        </form>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Active users</div>
        <div class="stat-value"><?= (int)$userCount ?></div>
        <div class="stat-sub">Across all roles</div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-label">Roles</div>
        <div class="stat-value"><?= (int)$roleCount ?></div>
        <div class="stat-sub">Defined in this tenant</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-label">Training courses</div>
        <div class="stat-value"><?= (int)$courseCount ?></div>
        <div class="stat-sub">Visible to at least one role</div>
    </div>
    <div class="stat-card stat-warn">
        <div class="stat-label">Your completed</div>
        <div class="stat-value"><?= (int)$myProgress ?></div>
        <div class="stat-sub">Out of <?= (int)$courseCount ?> available</div>
    </div>
    <?php if ($calOverdueCount + $calDueSoonCount > 0 || permission_check('asset', 'view')): ?>
    <div class="stat-card <?= $calOverdueCount > 0 ? 'stat-danger' : 'stat-info' ?>">
        <div class="stat-label">Calibration overdue</div>
        <div class="stat-value"><?= (int)$calOverdueCount ?></div>
        <div class="stat-sub">
            <?php if ($calDueSoonCount > 0): ?>
                <?= (int)$calDueSoonCount ?> due in next 30 days
            <?php else: ?>
                No upcoming in 30 days
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($inspPendingCount + $inspOverdueCount > 0 || permission_check('inspection', 'view')): ?>
    <div class="stat-card <?= $inspOverdueCount > 0 ? 'stat-danger' : 'stat-info' ?>">
        <div class="stat-label">Inspections pending</div>
        <div class="stat-value"><?= (int)$inspPendingCount ?></div>
        <div class="stat-sub">
            <?php if ($inspOverdueCount > 0): ?>
                <?= (int)$inspOverdueCount ?> overdue
            <?php else: ?>
                No overdue
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($calOverdueAssets || $calDueSoonAssets): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2>Calibration health</h2>
        <a class="btn btn-sm btn-ghost" href="<?= h(url('/asset.php')) ?>"
           data-shortcut="V" accesskey="v">
            <?= shortcut_label('View all assets', 'V') ?>
        </a>
    </div>
    <?php if ($calOverdueAssets): ?>
        <div style="padding: 12px 16px 4px;">
            <strong class="text-danger">Overdue (<?= count($calOverdueAssets) ?>)</strong>
        </div>
        <table class="data-table">
            <thead><tr>
                <th>Asset Tag</th><th>Model</th><th>Location</th><th>Due</th><th class="r">Days overdue</th>
            </tr></thead>
            <tbody>
            <?php foreach ($calOverdueAssets as $a): ?>
                <tr>
                    <td><strong><a href="<?= h(url('/asset.php?action=view&id=' . (int)$a['id'])) ?>"><?= h($a['asset_tag']) ?></a></strong></td>
                    <td><?= h($a['model_name'] ?: '—') ?></td>
                    <td><?= h($a['location_name'] ?: '—') ?></td>
                    <td><?= h($a['next_cal_due_on']) ?></td>
                    <td class="r text-danger"><?= (int)$a['days_overdue'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ($calDueSoonAssets): ?>
        <div style="padding: 12px 16px 4px; <?= $calOverdueAssets ? 'border-top: 1px solid var(--border);' : '' ?>">
            <strong class="text-warn">Due in next 30 days (<?= count($calDueSoonAssets) ?>)</strong>
        </div>
        <table class="data-table">
            <thead><tr>
                <th>Asset Tag</th><th>Model</th><th>Location</th><th>Due</th><th class="r">Days until</th>
            </tr></thead>
            <tbody>
            <?php foreach ($calDueSoonAssets as $a): ?>
                <tr>
                    <td><strong><a href="<?= h(url('/asset.php?action=view&id=' . (int)$a['id'])) ?>"><?= h($a['asset_tag']) ?></a></strong></td>
                    <td><?= h($a['model_name'] ?: '—') ?></td>
                    <td><?= h($a['location_name'] ?: '—') ?></td>
                    <td><?= h($a['next_cal_due_on']) ?></td>
                    <td class="r text-warn"><?= (int)$a['days_until'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($inspPendingRows): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2>Open inspections</h2>
        <a class="btn btn-sm btn-ghost" href="<?= h(url('/inspection.php?pending=1')) ?>">
            View all pending
        </a>
    </div>
    <table class="data-table">
        <thead>
            <tr><th>Code</th><th>Type</th><th>Target</th><th>Status</th><th>Inspector</th><th>Due</th></tr>
        </thead>
        <tbody>
        <?php
        $dashInspStatusMap = [
            'draft'             => ['Draft', 'neutral'],
            'in_progress'       => ['In progress', 'info'],
            'pending_approval'  => ['Pending approval', 'warn'],
            'rework'            => ['Rework', 'warn'],
            'hold'              => ['On hold', 'warn'],
        ];
        $dashInspTypeMap = [
            'incoming'       => 'Incoming',
            'asset_cal'      => 'Asset cal',
            'finished_goods' => 'Finished',
            'first_article'  => 'First article',
            'adhoc'          => 'Ad-hoc',
        ];
        foreach ($inspPendingRows as $insp):
            list($pillLabel, $pillCls) = $dashInspStatusMap[$insp['status']] ?? [$insp['status'], 'neutral'];
            $typeLabel = $dashInspTypeMap[$insp['inspection_type']] ?? $insp['inspection_type'];
            $isOverdue = $insp['due_date'] && $insp['due_date'] < date('Y-m-d');
        ?>
            <tr>
                <td><a href="<?= h(url('/inspection.php?action=view&id=' . (int)$insp['id'])) ?>"><strong><?= h($insp['code']) ?></strong></a></td>
                <td class="muted small"><?= h($typeLabel) ?></td>
                <td><?= $insp['entity_label'] ? '<code>' . h($insp['entity_label']) . '</code>' : '<span class="muted small">standalone</span>' ?></td>
                <td><span class="pill pill-<?= h($pillCls) ?>"><?= h($pillLabel) ?></span></td>
                <td><?= $insp['inspected_by_name'] ? h($insp['inspected_by_name']) : '<span class="muted small">—</span>' ?></td>
                <td class="<?= $isOverdue ? 'text-danger' : '' ?> nowrap">
                    <?= h($insp['due_date'] ?: '—') ?>
                    <?php if ($isOverdue): ?>
                        <span class="muted small">(<?= (int)((strtotime(date('Y-m-d')) - strtotime($insp['due_date'])) / 86400) ?>d)</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
// DMS dashboard widget — shows docs needing attention. Only renders
// for users with documents_dashboard.view; safely degrades if the
// helper file or tables aren't present (older installs).
if (function_exists('user_has_permission') && user_has_permission('documents_dashboard', 'view')) {
    $_dmsWidget = __DIR__ . '/includes/_dms_dashboard_widget.php';
    if (file_exists($_dmsWidget)) {
        try {
            include $_dmsWidget;
        } catch (Exception $e) {
            // DMS tables not yet migrated; ignore silently.
        }
    }
}

// Training certifications widget — only renders for users with
// training.view. Safely degrades if validity_months / expires_at columns
// aren't present yet (Phase 1 migration not run).
if (permission_check('training', 'view')) {
    $_trWidget = __DIR__ . '/includes/_training_dashboard_widget.php';
    if (file_exists($_trWidget)) {
        try {
            include $_trWidget;
        } catch (Exception $e) {
            // Training tables not yet migrated; ignore silently.
        }
    }
}
?>

<div class="card" style="margin-top: 24px;">
    <div class="card-head">
        <h2>Recent activity</h2>
        <span class="muted small">Last 10 events</span>
    </div>
    <table class="data-table">
        <thead>
        <tr>
            <th>When</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Target</th>
            <th>Details</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$recent): ?>
            <tr><td colspan="5" class="empty">No activity recorded yet.</td></tr>
        <?php else: foreach ($recent as $r): ?>
            <tr>
                <td><?= h(dt_display($r['at'])) ?></td>
                <td><?= h($r['actor_name'] ?: '—') ?></td>
                <td><code><?= h($r['action']) ?></code></td>
                <td><?= h($r['target_name'] ?: '—') ?></td>
                <td class="muted small"><?= h($r['details'] ?: '') ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
