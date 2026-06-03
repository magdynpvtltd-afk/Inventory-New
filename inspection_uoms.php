<?php
/**
 * MagDyn — Inspection UOMs
 * Created: 20260517_030000_IST
 *
 * Admin CRUD for the inspection_uoms table — the master list of
 * units of measure available in inspection template checklist items.
 * Separate from inv_uom (the inventory side) by design choice:
 * inspection UOMs lean toward metrology (μm, °, MPa) while inventory
 * UOMs lean toward stocking units (each, box, pallet). Some entries
 * overlap (mm, kg) and that's fine.
 *
 * Flat list (no hierarchy). Categories like 'length', 'angle',
 * 'pressure' are free-text strings used for grouping the dropdown
 * inside the template editor.
 *
 * Permissions:
 *   inspection_uoms.{view,manage}
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('inspection_uoms', 'view');
require_once __DIR__ . '/includes/datatable.php';

$canManage = permission_check('inspection_uoms', 'manage');
$action    = (string)input('action', 'list');

// =====================================================================
// SAVE
// =====================================================================
if ($action === 'save') {
    csrf_check();
    if (!$canManage) { flash_set('error', 'No permission.'); redirect(url('/inspection_uoms.php')); }
    $id = (int)input('id', 0);
    $data = [
        'code'        => trim((string)input('code')),
        'symbol'      => trim((string)input('symbol')),
        'name'        => trim((string)input('name')),
        'category'    => trim((string)input('category')) ?: null,
        'description' => trim((string)input('description')) ?: null,
        'sort_order'  => (int)input('sort_order', 100),
        'is_active'   => input('is_active') ? 1 : 0,
    ];
    $errors = [];
    if ($data['code'] === '')   $errors[] = 'Code is required (e.g. mm, deg, MPa).';
    if ($data['symbol'] === '') $errors[] = 'Display symbol is required (e.g. mm, °, MPa).';
    if ($data['name'] === '')   $errors[] = 'Name is required.';
    $clash = db_one('SELECT id FROM inspection_uoms WHERE code = ? AND id <> ?', [$data['code'], $id]);
    if ($clash) $errors[] = 'A UOM with that code already exists.';

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect($id ? url('/inspection_uoms.php?action=edit&id=' . $id) : url('/inspection_uoms.php?action=new'));
    }

    $uid = (int)current_user_id();
    if ($id) {
        db_exec(
            'UPDATE inspection_uoms
                SET code = ?, symbol = ?, name = ?, category = ?, description = ?,
                    sort_order = ?, is_active = ?
              WHERE id = ?',
            [$data['code'], $data['symbol'], $data['name'], $data['category'],
             $data['description'], $data['sort_order'], $data['is_active'], $id]
        );
        flash_set('success', 'UOM updated.');
    } else {
        db_exec(
            'INSERT INTO inspection_uoms
               (code, symbol, name, category, description, sort_order, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['code'], $data['symbol'], $data['name'], $data['category'],
             $data['description'], $data['sort_order'], $data['is_active'], $uid]
        );
        flash_set('success', 'UOM created.');
    }
    redirect(url('/inspection_uoms.php'));
}

// =====================================================================
// TOGGLE
// =====================================================================
if ($action === 'toggle') {
    csrf_check();
    if (!$canManage) { redirect(url('/inspection_uoms.php')); }
    $id = (int)input('id', 0);
    db_exec('UPDATE inspection_uoms SET is_active = 1 - is_active WHERE id = ?', [$id]);
    flash_set('success', 'Status changed.');
    redirect(url('/inspection_uoms.php'));
}

// =====================================================================
// DELETE
// =====================================================================
if ($action === 'delete') {
    csrf_check();
    if (!$canManage) { redirect(url('/inspection_uoms.php')); }
    $id = (int)input('id', 0);
    // Check references first — if any template items or results use this
    // UOM we deactivate rather than hard-delete to preserve history.
    $itemRefs = (int)db_val(
        'SELECT COUNT(*) FROM inspection_template_items WHERE unit = (SELECT code FROM inspection_uoms WHERE id = ?)',
        [$id], 0
    );
    $resultRefs = (int)db_val(
        'SELECT COUNT(*) FROM inspection_results WHERE unit = (SELECT code FROM inspection_uoms WHERE id = ?)',
        [$id], 0
    );
    if ($itemRefs + $resultRefs > 0) {
        db_exec('UPDATE inspection_uoms SET is_active = 0 WHERE id = ?', [$id]);
        flash_set('success', 'UOM is in use (' . ($itemRefs + $resultRefs) . ' references); deactivated instead of deleted.');
    } else {
        db_exec('DELETE FROM inspection_uoms WHERE id = ?', [$id]);
        flash_set('success', 'UOM deleted.');
    }
    redirect(url('/inspection_uoms.php'));
}

// =====================================================================
// NEW / EDIT — single shared form
// =====================================================================
if ($action === 'new' || $action === 'edit') {
    if (!$canManage) { flash_set('error', 'No permission.'); redirect(url('/inspection_uoms.php')); }
    $id  = (int)input('id', 0);
    $row = $id > 0 ? db_one('SELECT * FROM inspection_uoms WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$row) {
        flash_set('error', 'UOM not found.');
        redirect(url('/inspection_uoms.php'));
    }
    // Suggested categories for the datalist — admin can still type a
    // new category. The seed migration uses the same set.
    $categories = ['length', 'angle', 'mass', 'pressure', 'temperature',
                   'time', 'force', 'torque', 'roughness', 'dimensionless'];

    $page_title  = $row ? ('Edit UOM: ' . $row['symbol']) : 'New inspection UOM';
    $page_module = 'inspection_uoms';
    $focus_id    = 'f_code';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'        => $row ? 'Edit UOM' : 'New UOM',
            'subtitle'     => $row ? h($row['code']) : 'A unit of measure for inspection templates',
            'back_href'    => url('/inspection_uoms.php'),
            'back_label'   => 'UOMs',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inspection_uoms.php')) . '">Cancel</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post" action="<?= h(url('/inspection_uoms.php?action=save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="f_code">Code *</label>
                    <input id="f_code" name="code" required maxlength="20"
                           value="<?= h($row['code'] ?? '') ?>"
                           placeholder="mm, deg, MPa…">
                    <span class="muted small">Short identifier; stored on inspection records.</span>
                </div>
                <div class="field">
                    <label for="f_symbol">Display symbol *</label>
                    <input id="f_symbol" name="symbol" required maxlength="30"
                           value="<?= h($row['symbol'] ?? '') ?>"
                           placeholder="mm, °, MPa, μm…">
                    <span class="muted small">What inspectors see in dropdowns and read-outs.</span>
                </div>
                <div class="field span-2">
                    <label for="f_name">Name *</label>
                    <input id="f_name" name="name" required maxlength="120"
                           value="<?= h($row['name'] ?? '') ?>"
                           placeholder="Millimetre, Megapascal…">
                </div>
                <div class="field">
                    <label for="f_category">Category</label>
                    <input id="f_category" name="category" maxlength="50"
                           list="uom-categories"
                           value="<?= h($row['category'] ?? '') ?>"
                           placeholder="length, angle, pressure…">
                    <datalist id="uom-categories">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= h($c) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="muted small">Groups dropdown options in the template editor.</span>
                </div>
                <div class="field">
                    <label for="f_sort">Sort order</label>
                    <input id="f_sort" name="sort_order" type="number" min="0" max="9999"
                           value="<?= (int)($row['sort_order'] ?? 100) ?>">
                </div>
                <div class="field span-2">
                    <label for="f_desc">Description</label>
                    <textarea id="f_desc" name="description" rows="2"
                              placeholder="Optional clarification (e.g. 'ISO-compliant Ra average')"><?= h($row['description'] ?? '') ?></textarea>
                </div>
                <div class="field span-4">
                    <label class="inline">
                        <input type="checkbox" name="is_active" value="1" <?= ($row['is_active'] ?? 1) ? 'checked' : '' ?>>
                        Active (visible in template editor dropdowns)
                    </label>
                </div>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =====================================================================
// LIST (default)
// =====================================================================
$dtCfg = [
    'id'       => 'inspection_uoms',
    'base_sql' => 'SELECT u.*, usr.full_name AS creator_name
                     FROM inspection_uoms u
                     LEFT JOIN users usr ON usr.id = u.created_by',
    'columns'  => [
        ['key'=>'symbol',     'label'=>'Symbol',   'sortable'=>true, 'searchable'=>true, 'sql_col'=>'u.symbol'],
        ['key'=>'code',       'label'=>'Code',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'u.code'],
        ['key'=>'name',       'label'=>'Name',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'u.name'],
        ['key'=>'category',   'label'=>'Category', 'sortable'=>true, 'searchable'=>true, 'sql_col'=>'u.category'],
        ['key'=>'sort_order', 'label'=>'Sort',     'sortable'=>true, 'sql_col'=>'u.sort_order', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'is_active',  'label'=>'Status',   'sortable'=>true, 'sql_col'=>'u.is_active'],
        ['key'=>'_actions',   'label'=>'Actions',  'sortable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['sort_order', 'asc'],
];

$rowRenderer = function ($r) use ($canManage) {
    $status = $r['is_active']
        ? '<span class="pill pill-active">active</span>'
        : '<span class="pill pill-neutral">inactive</span>';
    $actions = '';
    if ($canManage) {
        $actions .= '<a class="btn btn-icon" href="' . h(url('/inspection_uoms.php?action=edit&id=' . (int)$r['id'])) . '"'
                  . ' title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a> ';
        $toggleTitle = $r['is_active'] ? 'Disable' : 'Enable';
        $toggleGlyph = $r['is_active'] ? '🚫' : '✅';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/inspection_uoms.php?action=toggle')) . '">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="' . $toggleTitle . '" aria-label="' . $toggleTitle . '">'
                  . $toggleGlyph . ' <span class="dt-action-label">' . $toggleTitle . '</span></button></form> ';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/inspection_uoms.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Delete UOM &quot;' . h(addslashes($r['symbol'])) . '&quot;? '
                  . 'If it\\\'s referenced anywhere, it will be deactivated instead.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
    }
    return [
        'symbol'     => '<strong>' . h($r['symbol']) . '</strong>',
        'code'       => '<code>' . h($r['code']) . '</code>',
        'name'       => h($r['name']),
        'category'   => $r['category'] ? '<span class="pill pill-neutral">' . h($r['category']) . '</span>' : '<span class="muted">—</span>',
        'sort_order' => (int)$r['sort_order'],
        'is_active'  => $status,
        '_actions'   => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);
$dtCfg['title']        = 'Inspection UOMs';
$dtCfg['actions_html'] = $canManage
    ? '<a class="btn btn-primary btn-sm" href="' . h(url('/inspection_uoms.php?action=new')) . '"'
      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New UOM', 'N') . '</a>'
    : '';

$page_title  = 'Inspection UOMs';
$page_module = 'inspection_uoms';
require __DIR__ . '/includes/header.php';
data_table_render($dtCfg, $dt, $rowRenderer);
require __DIR__ . '/includes/footer.php';
