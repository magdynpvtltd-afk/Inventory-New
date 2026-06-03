<?php
/**
 * MagDyn — Asset Lookups
 * Created: 20260515_073000_IST
 *
 * Single admin page for editing all five asset-related dropdown sources:
 *   asset_aliases, asset_cal_frequencies, asset_calibration_options,
 *   asset_engraved_options, asset_checked_ok_options
 *
 * Each table has the same shape (id / label / sort_order / is_active),
 * plus asset_cal_frequencies has an extra `months` column for the
 * automatic next-due date calculation.
 *
 * Actions:
 *   ?type=alias|cal_frequency|calibration|engraved|checked_ok  (default first)
 *   ?action=add (POST)       add a value
 *   ?action=update (POST)    update a value's label/sort_order/months/is_active
 *   ?action=toggle&id=N      flip is_active
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('asset_lookups', 'view');

// Whitelist mapping: short key in URL <-> table name + display + has-months?
$TYPES = [
    'alias'        => ['table' => 'asset_aliases',             'name' => 'Alias',                 'has_months' => false],
    'cal_frequency'=> ['table' => 'asset_cal_frequencies',     'name' => 'Calibration Frequency', 'has_months' => true],
    'calibration'  => ['table' => 'asset_calibration_options', 'name' => 'Calibration',           'has_months' => false],
    'engraved'     => ['table' => 'asset_engraved_options',    'name' => 'Engraved',              'has_months' => false],
    'checked_ok'   => ['table' => 'asset_checked_ok_options',  'name' => 'Checked OK',            'has_months' => false],
];

$typeKey = (string)input('type', 'alias');
if (!isset($TYPES[$typeKey])) $typeKey = 'alias';
$type    = $TYPES[$typeKey];
$table   = $type['table'];

$canManage = permission_check('asset_lookups', 'manage');
$canDelete = permission_check('asset_lookups', 'delete');
$action    = (string)input('action', 'index');

if (in_array($action, ['add', 'update', 'toggle'], true)) {
    require_permission('asset_lookups', 'manage');
    csrf_check();
}

if ($action === 'add') {
    $label  = trim((string)input('label'));
    $sort   = (int)input('sort_order', 100);
    $months = $type['has_months'] ? (input('months') === '' ? null : (int)input('months')) : null;
    if ($label === '') {
        flash_set('error', 'Label is required.');
    } else {
        if ($type['has_months']) {
            db_exec(
                "INSERT INTO `$table` (label, months, sort_order, is_active) VALUES (?, ?, ?, 1)",
                [$label, $months, $sort]
            );
        } else {
            db_exec(
                "INSERT INTO `$table` (label, sort_order, is_active) VALUES (?, ?, 1)",
                [$label, $sort]
            );
        }
        flash_set('success', 'Value added.');
    }
    redirect(url('/asset_lookups.php?type=' . $typeKey));
}

if ($action === 'update') {
    $id     = (int)input('id', 0);
    $label  = trim((string)input('label'));
    $sort   = (int)input('sort_order', 100);
    $active = input('is_active') ? 1 : 0;
    $months = $type['has_months'] ? (input('months') === '' ? null : (int)input('months')) : null;

    if (!$id || $label === '') {
        flash_set('error', 'Label is required.');
    } else {
        if ($type['has_months']) {
            db_exec(
                "UPDATE `$table` SET label=?, months=?, sort_order=?, is_active=? WHERE id=?",
                [$label, $months, $sort, $active, $id]
            );
        } else {
            db_exec(
                "UPDATE `$table` SET label=?, sort_order=?, is_active=? WHERE id=?",
                [$label, $sort, $active, $id]
            );
        }
        flash_set('success', 'Value updated.');
    }
    redirect(url('/asset_lookups.php?type=' . $typeKey));
}

if ($action === 'toggle') {
    $id = (int)input('id', 0);
    db_exec("UPDATE `$table` SET is_active = 1 - is_active WHERE id = ?", [$id]);
    flash_set('success', 'Toggled.');
    redirect(url('/asset_lookups.php?type=' . $typeKey));
}

if ($action === 'delete') {
    require_permission('asset_lookups', 'delete');
    csrf_check();
    $id = (int)input('id', 0);

    // Block delete if any asset references this lookup value.
    // The FK column on assets depends on which lookup table we're in.
    $assetCol = [
        'asset_aliases'             => 'alias_id',
        'asset_cal_frequencies'     => 'cal_frequency_id',
        'asset_calibration_options' => 'calibration_id',
        'asset_engraved_options'    => 'engraved_id',
        'asset_checked_ok_options'  => 'checked_ok_id',
    ][$table] ?? null;

    if ($assetCol) {
        $linked = 0;
        try {
            $linked = db_val("SELECT COUNT(*) FROM assets WHERE `$assetCol` = ?", [$id], 0);
        } catch (Exception $e) { /* assets may not exist yet */ }
        if ($linked > 0) {
            flash_set('error', sprintf('Cannot delete — %d asset(s) reference this value.', $linked));
            redirect(url('/asset_lookups.php?type=' . $typeKey));
        }
        // Also block deleting a frequency that's a model default
        if ($table === 'asset_cal_frequencies') {
            $modelLinked = 0;
            try {
                $modelLinked = db_val("SELECT COUNT(*) FROM asset_models WHERE default_cal_frequency_id = ?", [$id], 0);
            } catch (Exception $e) {}
            if ($modelLinked > 0) {
                flash_set('error', sprintf('Cannot delete — %d asset model(s) use this as their default frequency.', $modelLinked));
                redirect(url('/asset_lookups.php?type=' . $typeKey));
            }
        }
    }

    db_exec("DELETE FROM `$table` WHERE id = ?", [$id]);
    flash_set('success', 'Value deleted.');
    redirect(url('/asset_lookups.php?type=' . $typeKey));
}

// =====================================================================
// RENDER
// =====================================================================
$rows = db_all("SELECT * FROM `$table` ORDER BY sort_order, label");

$page_title  = 'Asset Lookups — ' . $type['name'];
$page_module = 'asset_lookups';
$focus_id    = 'f_new_label';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Asset Lookups</h1>
        <p class="muted">Edit the dropdown values that appear on asset records.</p>
    </div>
</div>

<div class="tabs" style="margin-bottom: 16px;">
    <?php $i = 0; foreach ($TYPES as $k => $t):
        $letter = strtoupper(substr($t['name'], 0, 1));
        $i++; ?>
        <a class="tab <?= $k === $typeKey ? 'active' : '' ?>"
           href="<?= h(url('/asset_lookups.php?type=' . $k)) ?>"
           data-shortcut="<?= h($letter) ?>"
           tabindex="<?= $i ?>">
            <?= shortcut_label($t['name'], $letter) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($canManage): ?>
<form class="card form-card" method="post"
      action="<?= h(url('/asset_lookups.php?type=' . $typeKey . '&action=add')) ?>"
      style="margin-bottom: 20px;">
    <?= csrf_field() ?>
    <div class="card-head"><h2>Add a new <?= h($type['name']) ?> value</h2></div>
    <div class="form-grid">
        <div class="field span-2">
            <label for="f_new_label"><?= shortcut_label('Label', 'L') ?> *</label>
            <input id="f_new_label" name="label" type="text" required tabindex="10">
        </div>
        <?php if ($type['has_months']): ?>
            <div class="field">
                <label for="f_new_months"><?= shortcut_label('Months', 'M') ?></label>
                <input id="f_new_months" name="months" type="number" min="0" max="120" tabindex="11"
                       placeholder="e.g. 12 for annual">
                <span class="muted small">Leave blank for "on demand". Used to compute the next due date.</span>
            </div>
        <?php endif; ?>
        <div class="field">
            <label for="f_new_sort">Sort order</label>
            <input id="f_new_sort" name="sort_order" type="number" value="100" tabindex="12">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit" data-shortcut="A" accesskey="a">
            <?= shortcut_label('Add value', 'A') ?>
        </button>
    </div>
</form>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= h($type['name']) ?> values</h2>
        <span class="muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <table class="data-table">
        <thead>
        <tr>
            <th>Label</th>
            <?php if ($type['has_months']): ?><th>Months</th><?php endif; ?>
            <th class="r">Order</th>
            <th>Status</th>
            <?php if ($canManage): ?><th class="r">Actions</th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $canManage ? ($type['has_months'] ? 5 : 4) : ($type['has_months'] ? 4 : 3) ?>" class="empty">
                No values yet. Add one above.
            </td></tr>
        <?php else: $tabIdx = 100; foreach ($rows as $r):
            $rowFormId = 'lk_' . (int)$r['id']; ?>
            <tr>
                <?php if ($canManage): ?>
                <td>
                    <form id="<?= h($rowFormId) ?>" method="post"
                          action="<?= h(url('/asset_lookups.php?type=' . $typeKey . '&action=update')) ?>"
                          style="display:contents;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    </form>
                    <input form="<?= h($rowFormId) ?>" name="label" type="text"
                           value="<?= h($r['label']) ?>" tabindex="<?= $tabIdx++ ?>" style="width: 100%;">
                </td>
                <?php if ($type['has_months']): ?>
                    <td><input form="<?= h($rowFormId) ?>" name="months" type="number" min="0" max="120"
                               value="<?= h($r['months'] ?? '') ?>" tabindex="<?= $tabIdx++ ?>" style="width: 80px;"></td>
                <?php endif; ?>
                <td class="r"><input form="<?= h($rowFormId) ?>" name="sort_order" type="number"
                                     value="<?= (int)$r['sort_order'] ?>" tabindex="<?= $tabIdx++ ?>"
                                     style="width: 80px; text-align: right;"></td>
                <td><label class="nowrap" style="font-weight: normal;">
                    <input form="<?= h($rowFormId) ?>" type="checkbox" name="is_active" value="1"
                           <?= $r['is_active'] ? 'checked' : '' ?> tabindex="<?= $tabIdx++ ?>"> active
                </label></td>
                <td class="r nowrap">
                    <button form="<?= h($rowFormId) ?>" class="btn btn-sm btn-primary" type="submit"
                            tabindex="<?= $tabIdx++ ?>">Save</button>
                    <?php if ($canDelete): ?>
                        <form method="post" style="display:inline"
                              action="<?= h(url('/asset_lookups.php?type=' . $typeKey . '&action=delete')) ?>"
                              onsubmit="return confirm('Delete &quot;<?= h(addslashes($r['label'])) ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                </td>
                <?php else: ?>
                    <td><?= h($r['label']) ?></td>
                    <?php if ($type['has_months']): ?><td><?= h($r['months'] ?? '—') ?></td><?php endif; ?>
                    <td class="r"><?= (int)$r['sort_order'] ?></td>
                    <td><?php if ($r['is_active']): ?>
                        <span class="pill pill-active">active</span>
                    <?php else: ?>
                        <span class="pill pill-neutral">inactive</span>
                    <?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
