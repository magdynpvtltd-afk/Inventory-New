<?php
/**
 * MagDyn — Inventory Lookups
 * Created: 20260515_153000_IST
 *
 * Admin page for the three lookup tables backing inventory item dropdowns:
 *   inv_uom, inv_cert_types, inv_process_steps
 *
 * Each table has identical shape (id / code / label / sort_order / is_active).
 * Tabbed UI matches the asset_lookups.php pattern: one row per value with
 * an inline form for label/sort_order/is_active edits + an "add new" form
 * at the top of each tab.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
// Reachable from two Admin entries: "Inventory Lookups" (inventory_lookups
// permission) and the dedicated "Divisions" entry (inventory_divisions
// permission). Accept either so both entry points work.
if (!permission_check('inventory_lookups', 'view') && !permission_check('inventory_divisions', 'view')) {
    require_permission('inventory_lookups', 'view');   // renders the standard 403
}

$TYPES = [
    'uom'           => ['table' => 'inv_uom',           'name' => 'Unit of Measure'],
    'cert_type'     => ['table' => 'inv_cert_types',    'name' => 'Certification Type'],
    'process_step'  => ['table' => 'inv_process_steps', 'name' => 'Process Step'],
    // Division lives in the shared `categories` table (type='division'),
    // which inv_items.division_id references. We surface it here so it's
    // editable alongside the other inventory dropdowns. It's flagged
    // categories-backed so the handlers map categories.name <-> 'label'
    // and scope every query to type='division'.
    'division'      => ['table' => 'categories', 'name' => 'Division', 'categories' => true],
];

$typeKey = (string)input('type', 'uom');
if (!isset($TYPES[$typeKey])) $typeKey = 'uom';
$type  = $TYPES[$typeKey];
$table = $type['table'];
$isCat = !empty($type['categories']);   // categories-backed (division)

$canManage = permission_check('inventory_lookups', 'manage') || permission_check('inventory_divisions', 'manage');
$action    = (string)input('action', 'index');

if (in_array($action, ['add', 'update', 'toggle', 'delete'], true)) {
    if (!permission_check('inventory_lookups', 'manage') && !permission_check('inventory_divisions', 'manage')) {
        require_permission('inventory_lookups', 'manage');   // renders the standard 403
    }
    csrf_check();
}

if ($action === 'add') {
    $code  = trim((string)input('code'));
    $label = trim((string)input('label'));
    $sort  = (int)input('sort_order', 100);
    $errors = [];
    if ($code === '')  $errors[] = 'Code is required.';
    if ($label === '') $errors[] = 'Label is required.';
    if (!$errors) {
        if ($isCat) {
            $clash = db_one("SELECT id FROM categories WHERE type = 'division' AND code = ?", [$code]);
        } else {
            $clash = db_one("SELECT id FROM `$table` WHERE code = ?", [$code]);
        }
        if ($clash) $errors[] = "Code '$code' already exists.";
    }
    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
    } else {
        if ($isCat) {
            db_exec("INSERT INTO categories (type, code, name, sort_order, is_active) VALUES ('division', ?, ?, ?, 1)",
                [$code, $label, $sort]);
        } else {
            db_exec("INSERT INTO `$table` (code, label, sort_order, is_active) VALUES (?, ?, ?, 1)",
                [$code, $label, $sort]);
        }
        flash_set('success', $type['name'] . ' added.');
    }
    redirect(url('/inventory_lookups.php?type=' . $typeKey));
}

if ($action === 'update') {
    $id    = (int)input('id', 0);
    $code  = trim((string)input('code'));
    $label = trim((string)input('label'));
    $sort  = (int)input('sort_order', 100);
    $active = input('is_active') ? 1 : 0;
    if ($code === '' || $label === '') {
        flash_set('error', 'Code and label are required.');
    } else {
        if ($isCat) {
            $clash = db_one("SELECT id FROM categories WHERE type = 'division' AND code = ? AND id <> ?", [$code, $id]);
        } else {
            $clash = db_one("SELECT id FROM `$table` WHERE code = ? AND id <> ?", [$code, $id]);
        }
        if ($clash) {
            flash_set('error', "Code '$code' is already used by another row.");
        } else {
            if ($isCat) {
                db_exec("UPDATE categories SET code = ?, name = ?, sort_order = ?, is_active = ? WHERE id = ? AND type = 'division'",
                    [$code, $label, $sort, $active, $id]);
            } else {
                db_exec("UPDATE `$table` SET code = ?, label = ?, sort_order = ?, is_active = ? WHERE id = ?",
                    [$code, $label, $sort, $active, $id]);
            }
            flash_set('success', 'Updated.');
        }
    }
    redirect(url('/inventory_lookups.php?type=' . $typeKey));
}

if ($action === 'toggle') {
    $id = (int)input('id', 0);
    if ($isCat) {
        db_exec("UPDATE categories SET is_active = 1 - is_active WHERE id = ? AND type = 'division'", [$id]);
    } else {
        db_exec("UPDATE `$table` SET is_active = 1 - is_active WHERE id = ?", [$id]);
    }
    redirect(url('/inventory_lookups.php?type=' . $typeKey));
}

if ($action === 'delete') {
    $id = (int)input('id', 0);
    // Best-effort delete; FK constraints will block if rows reference it.
    try {
        if ($isCat) {
            db_exec("DELETE FROM categories WHERE id = ? AND type = 'division'", [$id]);
        } else {
            db_exec("DELETE FROM `$table` WHERE id = ?", [$id]);
        }
        flash_set('success', 'Deleted.');
    } catch (Exception $e) {
        flash_set('error', 'Cannot delete: this value is in use.');
    }
    redirect(url('/inventory_lookups.php?type=' . $typeKey));
}

// ============================================================
// LIST
// ============================================================
if ($isCat) {
    $rows = db_all("SELECT id, code, name AS label, sort_order, is_active
                      FROM categories WHERE type = 'division'
                     ORDER BY sort_order, name");
} else {
    $rows = db_all("SELECT * FROM `$table` ORDER BY sort_order, label");
}

$page_title  = 'Inventory Lookups';
$page_module = 'inventory_lookups';
$focus_id    = 'add_code';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Inventory Lookups</h1>
        <p class="muted">Manage dropdown values used on the inventory item form.</p>
    </div>
</div>

<div class="tabs" style="margin-bottom: 16px;">
    <?php $i = 0; foreach ($TYPES as $k => $t):
        $letter = strtoupper(substr($t['name'], 0, 1));
        $i++; ?>
        <a class="tab <?= $k === $typeKey ? 'active' : '' ?>"
           href="<?= h(url('/inventory_lookups.php?type=' . $k)) ?>"
           data-shortcut="<?= h($letter) ?>"
           tabindex="<?= $i ?>">
            <?= shortcut_label($t['name'], $letter) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= h($type['name']) ?> values</h2>
        <span class="muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>

    <?php if ($canManage): ?>
        <form method="post" action="<?= h(url('/inventory_lookups.php?action=add&type=' . $typeKey)) ?>"
              class="form-grid" style="margin-bottom: 16px;">
            <?= csrf_field() ?>
            <div class="field">
                <label for="add_code">Code</label>
                <input id="add_code" name="code" type="text" required placeholder="short identifier" tabindex="1">
            </div>
            <div class="field">
                <label for="add_label">Label</label>
                <input id="add_label" name="label" type="text" required placeholder="display name" tabindex="2">
            </div>
            <div class="field">
                <label for="add_sort">Sort order</label>
                <input id="add_sort" name="sort_order" type="number" value="100" tabindex="3" style="width: 90px;">
            </div>
            <div class="field">
                <button type="submit" class="btn btn-primary" tabindex="4"
                        data-shortcut="A" accesskey="a" style="margin-top: 24px;">
                    <?= shortcut_label('Add', 'A') ?>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <table class="data-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Label</th>
                <th class="r">Order</th>
                <th>Active</th>
                <?php if ($canManage): ?><th class="r">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="empty">No values yet.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <form method="post" action="<?= h(url('/inventory_lookups.php?action=update&type=' . $typeKey)) ?>"
                          id="upd-<?= (int)$r['id'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    </form>
                    <td>
                        <?php if ($canManage): ?>
                            <input form="upd-<?= (int)$r['id'] ?>" name="code" type="text"
                                   value="<?= h($r['code']) ?>" required style="width: 140px;">
                        <?php else: ?>
                            <code><?= h($r['code']) ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canManage): ?>
                            <input form="upd-<?= (int)$r['id'] ?>" name="label" type="text"
                                   value="<?= h($r['label']) ?>" required style="width: 100%;">
                        <?php else: ?>
                            <?= h($r['label']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="r">
                        <?php if ($canManage): ?>
                            <input form="upd-<?= (int)$r['id'] ?>" name="sort_order" type="number"
                                   value="<?= (int)$r['sort_order'] ?>" style="width: 70px;">
                        <?php else: ?>
                            <?= (int)$r['sort_order'] ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canManage): ?>
                            <label class="nowrap" style="font-weight: normal;">
                                <input form="upd-<?= (int)$r['id'] ?>" type="checkbox"
                                       name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>>
                                active
                            </label>
                        <?php else: ?>
                            <?= $r['is_active']
                                ? '<span class="pill pill-active">active</span>'
                                : '<span class="pill pill-neutral">inactive</span>' ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($canManage): ?>
                        <td class="r nowrap">
                            <button form="upd-<?= (int)$r['id'] ?>" type="submit" class="btn btn-sm btn-ghost">Save</button>
                            <form method="post" style="display:inline"
                                  action="<?= h(url('/inventory_lookups.php?action=delete&type=' . $typeKey)) ?>"
                                  onsubmit="return confirm('Delete &quot;<?= h(addslashes($r['label'])) ?>&quot;? Blocked if it is in use.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
