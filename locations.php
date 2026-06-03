<?php
/**
 * MagDyn — Locations
 * Created: 20260515_071000_IST
 *
 * Hierarchical register (parent_id). Used by Assets for current location,
 * moves, etc. Lives under the Admin sidebar group.
 *
 * Actions:
 *   ?action=index   list (default)
 *   ?action=new     new location form
 *   ?action=edit&id=N   edit
 *   ?action=save (POST)
 *   ?action=toggle&id=N   activate/deactivate
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('locations', 'view');

$action = (string)input('action', 'index');
$canManage = permission_check('locations', 'manage');
$canDelete = permission_check('locations', 'delete');

if ($action === 'save') {
    require_permission('locations', 'manage');
    csrf_check();
    $id    = (int)input('id', 0);
    $code  = trim((string)input('code'));
    $name  = trim((string)input('name'));
    $notes = trim((string)input('notes'));
    $sort  = (int)input('sort_order', 100);
    $parent = (int)input('parent_id', 0) ?: null;
    $active = input('is_active') ? 1 : 0;

    $errors = [];
    if ($code === '') $errors[] = 'Code is required.';
    if ($name === '') $errors[] = 'Name is required.';

    // Cycle prevention
    if ($id && $parent) {
        $walker = (int)$parent;
        $seen   = [];
        while ($walker) {
            if ($walker === $id) { $errors[] = 'Cannot set a descendant as the parent.'; break; }
            if (isset($seen[$walker])) break;
            $seen[$walker] = 1;
            $walker = (int)db_val('SELECT parent_id FROM locations WHERE id = ?', [$walker], 0);
        }
    }
    $clash = db_one('SELECT id FROM locations WHERE code = ? AND id <> ?', [$code, $id]);
    if ($clash) $errors[] = 'A location with that code already exists.';

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        redirect($id ? url('/locations.php?action=edit&id=' . $id) : url('/locations.php?action=new'));
    }

    if ($id) {
        db_exec(
            'UPDATE locations SET parent_id=?, code=?, name=?, notes=?, sort_order=?, is_active=? WHERE id=?',
            [$parent, $code, $name, $notes, $sort, $active, $id]
        );
    } else {
        db_exec(
            'INSERT INTO locations (parent_id, code, name, notes, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$parent, $code, $name, $notes, $sort, $active]
        );
    }
    flash_set('success', 'Location saved.');
    redirect(url('/locations.php'));
}

if ($action === 'toggle') {
    require_permission('locations', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    db_exec('UPDATE locations SET is_active = 1 - is_active WHERE id = ?', [$id]);
    flash_set('success', 'Location toggled.');
    redirect(url('/locations.php'));
}

if ($action === 'delete') {
    require_permission('locations', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $loc = db_one('SELECT * FROM locations WHERE id = ?', [$id]);
    if (!$loc) { flash_set('error', 'Location not found.'); redirect(url('/locations.php')); }

    // Block if any child locations
    $kids = db_val('SELECT COUNT(*) FROM locations WHERE parent_id = ?', [$id], 0);
    if ($kids > 0) {
        flash_set('error', sprintf('Cannot delete "%s" — it has %d child locations. Reassign or delete those first.', $loc['name'], $kids));
        redirect(url('/locations.php'));
    }
    // Block if any assets reference it
    $assets = 0;
    try {
        $assets = db_val('SELECT COUNT(*) FROM assets WHERE location_id = ?', [$id], 0);
    } catch (Exception $e) { /* table may not exist yet */ }
    if ($assets > 0) {
        flash_set('error', sprintf('Cannot delete "%s" — %d assets are currently at this location.', $loc['name'], $assets));
        redirect(url('/locations.php'));
    }

    db_exec('DELETE FROM locations WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'location.delete', ?)",
            [real_user_id(), 'deleted location ' . $loc['code']]);
    flash_set('success', 'Location deleted.');
    redirect(url('/locations.php'));
}

// ============================================================
// CLONE — duplicate a single location (header only, no children)
// ============================================================
// The clone keeps the same parent_id so it sits as a sibling of the
// original in the tree. We do NOT recurse into children — cloning a
// whole subtree is rarely what users want and gets confusing fast.
if ($action === 'clone') {
    require_permission('locations', 'manage');
    csrf_check();
    $id  = (int)input('id', 0);
    $src = db_one('SELECT * FROM locations WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Location not found.');
        redirect(url('/locations.php'));
    }

    $newCode = clone_unique_code('locations', 'code', $src['code']);
    $newName = 'Copy of ' . $src['name'];

    $newId = clone_row('locations', $id, [
        'code'      => $newCode,
        'name'      => $newName,
        'is_active' => 1,
    ], ['created_at']);
    if ($newId <= 0) {
        flash_set('error', 'Location clone failed.');
        redirect(url('/locations.php'));
    }

    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'location.clone', ?)",
        [real_user_id(), 'cloned ' . $src['code'] . ' → ' . $newCode]);
    flash_set('success', 'Location cloned to "' . $newCode . '". Adjust and save.');
    redirect(url('/locations.php?action=edit&id=' . $newId));
}

// ============================================================
// NEW / EDIT
// ============================================================
if ($action === 'new' || $action === 'edit') {
    require_permission('locations', 'manage');
    $editing = null;
    if ($action === 'edit') {
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM locations WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Location not found.'); redirect(url('/locations.php')); }
    }
    // Exclude self + descendants from parent choices to prevent cycles
    $excludeIds = [];
    if ($editing) {
        $excludeIds[] = (int)$editing['id'];
        $stack = [(int)$editing['id']];
        while ($stack) {
            $current = array_pop($stack);
            $kids = db_all('SELECT id FROM locations WHERE parent_id = ?', [$current]);
            foreach ($kids as $k) { $excludeIds[] = (int)$k['id']; $stack[] = (int)$k['id']; }
        }
    }
    $allLocs = db_all('SELECT * FROM locations ORDER BY sort_order, name');

    $page_title  = $editing ? 'Edit location' : 'New location';
    $page_module = 'locations';
    $focus_id    = 'f_code';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit location' : 'New location',
            'subtitle'    => $editing ? $editing['name'] : 'Plant / building / room',
            'back_href'   => url('/locations.php'),
            'back_label'  => 'Locations',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/locations.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/locations.php?action=save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="f_code"><?= shortcut_label('Code', 'C') ?> *</label>
                    <input id="f_code" name="code" type="text" required tabindex="1"
                           value="<?= h($editing['code'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_name"><?= shortcut_label('Name', 'N') ?> *</label>
                    <input id="f_name" name="name" type="text" required tabindex="2"
                           value="<?= h($editing['name'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_parent"><?= shortcut_label('Parent location', 'P') ?></label>
                    <select id="f_parent" name="parent_id" tabindex="3">
                        <option value="0">— Top-level —</option>
                        <?php foreach ($allLocs as $l):
                            if (in_array((int)$l['id'], $excludeIds, true)) continue; ?>
                            <option value="<?= (int)$l['id'] ?>"
                                <?= ($editing && (int)$editing['parent_id'] === (int)$l['id']) ? 'selected' : '' ?>>
                                <?= h($l['name']) ?> <span class="muted">(<?= h($l['code']) ?>)</span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="f_notes">Notes</label>
                    <input id="f_notes" name="notes" type="text" tabindex="4"
                           value="<?= h($editing['notes'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_sort">Sort order</label>
                    <input id="f_sort" name="sort_order" type="number" tabindex="5"
                           value="<?= (int)($editing['sort_order'] ?? 100) ?>">
                </div>
                <div class="field">
                    <label class="nowrap" style="font-weight:normal;">
                        <input type="checkbox" name="is_active" value="1" tabindex="6"
                               <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                        <?= shortcut_label('Active', 'A') ?>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <?php require __DIR__ . '/includes/footer.php'; exit; }

// ============================================================
// LIST — tree view by default; flat sortable/searchable datatable
// when the user activates any dt_* URL param.
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

// Detect "datatable mode" by sniffing for any dt_* URL param.
$useDataTable = false;
foreach (array_keys(array_merge($_GET, $_POST)) as $k) {
    if (strpos($k, 'dt_') === 0) { $useDataTable = true; break; }
}

if ($useDataTable) {
    // -------- Flat datatable view --------
    $dtCfg = [
        'id'       => 'locations',
        'base_sql' => 'SELECT l.*,
                              p.name AS parent_name
                         FROM locations l
                         LEFT JOIN locations p ON p.id = l.parent_id',
        'columns'  => [
            ['key'=>'name',        'label'=>'Name',   'sortable'=>true, 'searchable'=>true, 'sql_col'=>'l.name'],
            ['key'=>'code',        'label'=>'Code',   'sortable'=>true, 'searchable'=>true, 'sql_col'=>'l.code'],
            ['key'=>'parent_name', 'label'=>'Parent', 'sortable'=>true, 'searchable'=>true, 'sql_col'=>'p.name'],
            ['key'=>'notes',       'label'=>'Notes',  'sortable'=>false,'searchable'=>true, 'sql_col'=>'l.notes', 'td_class'=>'muted small'],
            ['key'=>'is_active',   'label'=>'Status', 'sortable'=>true, 'searchable'=>false,'sql_col'=>'l.is_active'],
            ['key'=>'_actions',    'label'=>'Actions','sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['name', 'asc'],
    ];

    $rowRenderer = function ($r) use ($canManage, $canDelete) {
        $name = $canManage
            ? '<strong><a href="' . h(url('/locations.php?action=edit&id=' . (int)$r['id'])) . '">' . h($r['name']) . '</a></strong>'
            : '<strong>' . h($r['name']) . '</strong>';
        $status = $r['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">inactive</span>';
        $actions = '';
        if ($canManage) {
            $toggleTitle = $r['is_active'] ? 'Disable' : 'Enable';
            $toggleGlyph = $r['is_active'] ? '🚫' : '✅';
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/locations.php?action=toggle')) . '">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon" type="submit" title="' . $toggleTitle . '" aria-label="' . $toggleTitle . '">'
                      . $toggleGlyph . ' <span class="dt-action-label">' . $toggleTitle . '</span></button></form> ';
            // Clone: duplicates this location header (no children, no
            // FK-targets — they stay with the original). Lands on edit
            // page of the new row.
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/locations.php?action=clone')) . '"'
                      . ' onsubmit="return confirm(\'Clone &quot;' . h(addslashes($r['name'])) . '&quot;? '
                      . 'A copy is created as a sibling — children, stock, and assets at this location are NOT cloned.\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon" type="submit" title="Clone" aria-label="Clone">'
                      . '⎘ <span class="dt-action-label">Clone</span></button></form> ';
        }
        if ($canDelete) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/locations.php?action=delete')) . '"'
                      . ' onsubmit="return confirm(\'Delete location &quot;' . h(addslashes($r['name'])) . '&quot;?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
        }
        return [
            'name'        => $name,
            'code'        => '<code>' . h($r['code']) . '</code>',
            'parent_name' => h($r['parent_name'] ?: '—'),
            'notes'       => h($r['notes'] ?: ''),
            'is_active'   => $status,
            '_actions'    => dt_actions_wrap($actions),
        ];
    };

    $dt = data_table_run($dtCfg, $rowRenderer);

    $page_title  = 'Locations';
    $page_module = 'locations';
    $focus_id    = '';

    $actionsHtml = '';
    if ($canManage) {
        $actionsHtml = '<a class="btn btn-primary btn-sm" href="' . h(url('/locations.php?action=new')) . '"'
                     . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New location', 'N') . '</a>';
    }
    $actionsHtml .= ' <a class="btn btn-ghost btn-sm" href="' . h(url('/locations.php')) . '">← Tree view</a>';
    $dtCfg['title']        = 'Locations (flat)';
    $dtCfg['actions_html'] = $actionsHtml;

    require __DIR__ . '/includes/header.php';
    ?>
    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// -------- Tree view (default) --------
$rows = db_all('SELECT * FROM locations ORDER BY sort_order, name');

// Build adjacency map
$kids = [];
$byId = [];
foreach ($rows as $r) { $byId[(int)$r['id']] = $r; $kids[(int)$r['parent_id']][] = $r; }

function render_loc_tree($parentId, $kids, $canManage, $canDelete = false) {
    if (empty($kids[$parentId])) return;
    foreach ($kids[$parentId] as $r):
        $id = (int)$r['id']; ?>
        <tr>
            <td>
                <?php
                $depth = 0; $walk = (int)$r['parent_id'];
                while ($walk) {
                    $depth++;
                    $walk = (int)($GLOBALS['__loc_byid'][$walk]['parent_id'] ?? 0);
                }
                echo str_repeat("\xC2\xA0\xC2\xA0\xC2\xA0", $depth);
                if ($depth > 0) echo '└ ';
                if ($canManage): ?>
                    <strong><a href="<?= h(url('/locations.php?action=edit&id=' . $id)) ?>"><?= h($r['name']) ?></a></strong>
                <?php else: ?>
                    <strong><?= h($r['name']) ?></strong>
                <?php endif; ?>
            </td>
            <td><code><?= h($r['code']) ?></code></td>
            <td class="muted small"><?= h($r['notes'] ?: '') ?></td>
            <td><?php if ($r['is_active']): ?>
                <span class="pill pill-active">active</span>
            <?php else: ?>
                <span class="pill pill-neutral">inactive</span>
            <?php endif; ?></td>
            <td class="r">
                <?php if ($canManage): ?>
                    <form method="post" style="display:inline" action="<?= h(url('/locations.php?action=toggle')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-sm btn-ghost" type="submit">
                            <?= $r['is_active'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="post" style="display:inline"
                          action="<?= h(url('/locations.php?action=delete')) ?>"
                          onsubmit="return confirm('Delete location &quot;<?= h(addslashes($r['name'])) ?>&quot;?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php
        render_loc_tree($id, $kids, $canManage, $canDelete);
    endforeach;
}
$GLOBALS['__loc_byid'] = $byId;

$page_title  = 'Locations';
$page_module = 'locations';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Locations</h1>
        <p class="muted"><?= count($rows) ?> location<?= count($rows) === 1 ? '' : 's' ?> defined ·
            <a href="<?= h(url('/locations.php?dt_sort=name')) ?>">switch to flat list (sort/search)</a></p>
    </div>
    <div class="head-actions">
        <?php if ($canManage): ?>
            <a class="btn btn-primary" href="<?= h(url('/locations.php?action=new')) ?>"
               data-shortcut="N" accesskey="n"><?= shortcut_label('+ New location', 'N') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <table class="data-table">
        <thead>
        <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Notes</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="empty">No locations yet.</td></tr>
        <?php else: render_loc_tree(0, $kids, $canManage, $canDelete); endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
