<?php
/**
 * MagDyn — ECN signoff-slot administration
 *
 * Three ECN sign-off slots (engineering, quality, production) each
 * map to one role. Anyone with that role gets ecn.signoff for that
 * slot when the ECN is in submitted/in_review.
 *
 * Admin-only: gated by ecn.manage.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_ecn.php';

require_permission('ecn', 'manage');

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $slots = isset($_POST['slot']) && is_array($_POST['slot']) ? $_POST['slot'] : [];
    foreach ($slots as $slotCode => $roleId) {
        $roleId = (int)$roleId ?: null;
        ecn_signoff_slot_set_role($slotCode, $roleId);
    }
    flash_set('success', 'ECN sign-off slot mapping saved.');
    header('Location: ' . url('/ecn_admin.php'));
    exit;
}

require __DIR__ . '/includes/header.php';

$slots = ecn_signoff_slots();
$roles = db_all("SELECT id, code, name FROM roles ORDER BY name");
?>
<div class="page-head">
    <div>
        <h1>ECN sign-off slot configuration</h1>
        <p class="muted">
            Map each of the three ECN sign-off slots to a role. Users with that role will be able to approve or reject that slot on any submitted ECN.
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/ecn.php')) ?>">← Back to ECNs</a>
    </div>
</div>

<form method="post" action="<?= h(url('/ecn_admin.php')) ?>">
    <?= csrf_field() ?>
    <div class="card form-card" style="margin-bottom: 18px;">
        <h3 style="margin: 0 0 14px; font-size: 14px;">Slot → role mapping</h3>
        <table class="data-table">
            <thead>
                <tr><th style="width: 25%;">Slot</th><th style="width: 50%;">Role</th><th>Current mapping</th></tr>
            </thead>
            <tbody>
                <?php foreach ($slots as $s): ?>
                    <tr>
                        <td><strong><?= h($s['name']) ?></strong> <span class="muted small">(<?= h($s['code']) ?>)</span></td>
                        <td>
                            <select name="slot[<?= h($s['code']) ?>]">
                                <option value="">— none (slot disabled) —</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === (int)$s['role_id'] ? 'selected' : '' ?>>
                                        <?= h($r['name']) ?> <span class="muted small">(<?= h($r['code']) ?>)</span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <?php if ($s['role_name']): ?>
                                <span class="pill pill-info"><?= h($s['role_name']) ?></span>
                            <?php else: ?>
                                <span class="muted small">Not configured — slot will not accept sign-offs</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save mapping</button>
    </div>
</form>

<div class="card" style="background: #f0f9ff; border-left: 3px solid var(--info, #0284c7);">
    <div class="card-body">
        <strong>How sign-offs work</strong>
        <ul style="margin: 8px 0 0; padding-left: 22px;">
            <li>When an ECN is submitted, one pending sign-off row is created for each of the three slots.</li>
            <li>Any user holding the slot's mapped role can approve or reject that slot.</li>
            <li>The first approval moves the ECN from <strong>Submitted</strong> → <strong>In Review</strong>.</li>
            <li>When all three slots are approved, the ECN moves to <strong>Approved</strong>.</li>
            <li>Any rejection sends the ECN back to <strong>Draft</strong>, clears all sign-offs, and saves the rejection reason for the originator.</li>
            <li>Users with <code>ecn.manage</code> (admins) can sign any slot regardless of role.</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
