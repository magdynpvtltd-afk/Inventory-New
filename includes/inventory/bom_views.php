<?php
/**
 * MagDyn — Inventory: BOM views (list, view, edit, grid, designer, clone)
 * Extracted Stage 1: 20260517_223400_IST
 *
 * Read-side BOM operations and visual editing surfaces.
 *
 * PARTIAL — not a standalone page. Routed by inventory.php (the
 * dispatcher). Variables already in scope from the dispatcher:
 *   $action, $canViewItems, $canCreateItems, $canManageItems,
 *   $canDeleteItems, $canViewBoms, $canCreateBoms, $canManageBoms,
 *   $canDeleteBoms.
 */

// Shared CSV-import helpers — used near the bottom of bom_grid to render
// the Import BOM modal. Without this, calls to import_modal_html() throw
// a fatal that's silenced by production error settings; symptom is that
// the button appears but clicking it does nothing (no modal rendered, no
// click handler bound).
require_once dirname(__DIR__, 2) . '/includes/_import.php';

// ============================================================
// inv_tree — recursive BOM walk (used by view, clone)
// ============================================================
/**
 * Walk down from $rootItemId through child edges, returning the BOM tree
 * as a nested array. Cycle-safe via a visited set; if a cycle exists
 * (shouldn't, but defensive) the recursion stops at the second visit.
 *
 * Each node: ['item' => row, 'qty' => x, 'line_id' => y, 'ref_designator' => z,
 *             'children' => [...], 'unit_cost' => from item, 'ext_cost' => qty*unit]
 *
 * The qty/ext_cost on the root reflect "1 of this product".
 */
function inv_tree($rootItemId, $multiplier = 1, &$visited = []) {
    $rootItemId = (int)$rootItemId;
    if (isset($visited[$rootItemId])) {
        // Cycle. Return a stub.
        return null;
    }
    $visited[$rootItemId] = true;
    $item = db_one('SELECT * FROM inv_items WHERE id = ?', [$rootItemId]);
    if (!$item) return null;

    // The root item: also resolve uom_label and prefer short_description.
    if ($item) {
        $uomLabel = db_val('SELECT label FROM inv_uom WHERE id = ?', [(int)$item['uom_id']], $item['uom']);
        $item['uom_label'] = $uomLabel ?: ($item['uom'] ?: '');
        $item['display_name'] = $item['short_description'] ?: $item['name'];
    }

    $kids = db_all(
        'SELECT bl.*,
                ci.code AS child_code,
                COALESCE(NULLIF(ci.short_description, ""), ci.name) AS child_name,
                COALESCE(u.label, ci.uom) AS uom_label,
                ci.unit_cost, ci.stock_on_hand
           FROM inv_bom_lines bl
           JOIN inv_items ci ON ci.id = bl.child_item_id
           LEFT JOIN inv_uom u ON u.id = ci.uom_id
          WHERE bl.parent_item_id = ?
          ORDER BY bl.sort_order, bl.id',
        [$rootItemId]
    );
    $tree = [
        'item'     => $item,
        'line_id'  => null,
        'qty'      => $multiplier,
        'ref_designator' => null,
        'notes'    => null,
        'unit_cost' => $item['unit_cost'] !== null ? (float)$item['unit_cost'] : null,
        'ext_cost' => null,
        'children' => [],
    ];
    if ($tree['unit_cost'] !== null) {
        $tree['ext_cost'] = $tree['unit_cost'] * $multiplier;
    }

    foreach ($kids as $k) {
        $child = inv_tree((int)$k['child_item_id'], $multiplier * (float)$k['qty'], $visited);
        if (!$child) continue;
        // Override with line-specific metadata
        $child['line_id']        = (int)$k['id'];
        $child['qty']            = (float)$k['qty'] * $multiplier;
        $child['per_parent_qty'] = (float)$k['qty'];
        $child['ref_designator'] = $k['ref_designator'];
        $child['notes']          = $k['notes'];
        $tree['children'][] = $child;
    }
    // Roll up cost: if the item has no unit_cost of its own but does have
    // children, compute its assembly cost from the sum of children's ext_costs.
    if ($tree['ext_cost'] === null && $tree['children']) {
        $sum = 0; $anyKnown = false;
        foreach ($tree['children'] as $c) {
            if ($c['ext_cost'] !== null) { $sum += $c['ext_cost']; $anyKnown = true; }
        }
        if ($anyKnown) $tree['ext_cost'] = $sum;
    }
    unset($visited[$rootItemId]); // allow same item to appear in sibling branches
    return $tree;
}

// ============================================================
// ============================================================
// BOM CLONE — prefix-rewrite preview + commit
// ============================================================
// Cloning a BOM is rarely "duplicate everything verbatim". Most of the
// time a user is creating a variant of an existing assembly where the
// finished good and a subset of sub-assemblies share a common part-no
// prefix (e.g. "PN-1234 spindle housing", "PN-1234 bearing carrier").
// The variant uses a NEW prefix (e.g. "PN-5678"). Items NOT prefixed
// (COTS screws, standard bushings, etc.) are shared and not cloned.
//
// Flow:
//   1. bom_clone_preview  GET  → renders the form (old/new prefix inputs)
//   2. bom_clone_compute  POST → walks the tree, builds the plan, renders
//      it as an annotated tree (NEW / REUSE badges). Has a Save button.
//   3. bom_clone_commit   POST → executes the plan in a transaction:
//      - For each item to clone: clone the inv_items row (new code via
//        clone_unique_code), substitute the prefix in short_description,
//        copy vendor + cert associations.
//      - Then for each cloned parent, rebuild its inv_bom_lines pointing
//        at the cloned children where available, falling back to the
//        original child where not.
//      - Redirect to the new root's BOM designer.
//
// The plan is carried between compute and commit as a hidden JSON blob
// (signed with the CSRF token implicitly by virtue of being POSTed from
// a CSRF-protected form). The user can edit the new short_descriptions
// inline in the preview before committing.
// ============================================================

/**
 * Walk a tree returned by inv_tree() and produce a clone plan.
 *
 * Returns an array:
 *   [
 *     'old_prefix' => 'PN-1234',
 *     'new_prefix' => 'PN-5678',
 *     'nodes' => [                 // flat list, root first, depth-first
 *       [
 *         'src_id'           => int,
 *         'src_code'         => string,
 *         'src_short_desc'   => string,
 *         'will_clone'       => bool,
 *         'new_short_desc'   => string|null,   // null if reuse
 *         'parent_src_id'    => int|null,      // null for root
 *         'qty'              => float,         // from parent edge
 *         'sort_order'       => int,
 *         'ref_designator'   => string|null,
 *         'line_notes'       => string|null,
 *         'depth'            => int,
 *       ],
 *       ...
 *     ],
 *   ]
 *
 * Cloning rule: a node will_clone iff its short_description starts with
 * the old_prefix (literal, case-sensitive, optionally followed by a
 * space or punctuation). Otherwise it's reused (shared part).
 */
function bom_clone_build_plan($tree, $oldPrefix, $newPrefix)
{
    $nodes = [];
    $walk = function ($node, $parentSrcId, $depth) use (&$walk, &$nodes, $oldPrefix, $newPrefix) {
        $item = $node['item'];
        $srcShort = (string)$item['short_description'];
        // Match: starts with old prefix exactly, followed by end-of-string
        // OR a non-alphanumeric character (space, dash, colon, etc.).
        // This avoids spuriously matching "PN-1" against "PN-12".
        $willClone = false;
        if ($oldPrefix !== '' && strpos($srcShort, $oldPrefix) === 0) {
            $rest = substr($srcShort, strlen($oldPrefix));
            if ($rest === '' || !preg_match('/^[A-Za-z0-9]/', $rest)) {
                $willClone = true;
            }
        }
        $newShort = $willClone ? ($newPrefix . substr($srcShort, strlen($oldPrefix))) : null;

        $nodes[] = [
            'src_id'         => (int)$item['id'],
            'src_code'       => (string)$item['code'],
            'src_short_desc' => $srcShort,
            'will_clone'     => $willClone,
            'new_short_desc' => $newShort,
            'parent_src_id'  => $parentSrcId,
            'qty'            => isset($node['per_parent_qty']) ? (float)$node['per_parent_qty'] : 1.0,
            'sort_order'     => isset($node['sort_order']) ? (int)$node['sort_order'] : 0,
            'ref_designator' => isset($node['ref_designator']) ? (string)$node['ref_designator'] : null,
            'line_notes'     => isset($node['notes']) ? (string)$node['notes'] : null,
            'depth'          => $depth,
        ];
        foreach ($node['children'] as $c) {
            $walk($c, (int)$item['id'], $depth + 1);
        }
    };
    $walk($tree, null, 0);
    return [
        'old_prefix' => $oldPrefix,
        'new_prefix' => $newPrefix,
        'nodes'      => $nodes,
    ];
}

if ($action === 'bom_clone_preview') {
    csrf_check();
    if (!$canManageBoms) {
        flash_set('error', 'No permission to manage BOMs.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $id = (int)input('id', 0);
    $src = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Source item not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $tree = inv_tree($id, 1);
    if (!$tree['children']) {
        flash_set('error', 'This item has no BOM to clone. Create one first.');
        redirect(url('/inventory.php?action=bom_view&id=' . $id));
    }
    // Heuristic for the old-prefix placeholder: first whitespace-delimited
    // token of the root's short_description, if it looks like a prefix
    // (contains a digit or a dash). User can override.
    $rootShort = (string)$src['short_description'];
    $tokens = preg_split('/\s+/', trim($rootShort), 2);
    $oldPrefixGuess = '';
    if (!empty($tokens[0]) && preg_match('/[\d-]/', $tokens[0])) {
        $oldPrefixGuess = $tokens[0];
    }

    $page_title  = 'Clone BOM: ' . ($src['short_description'] ?: $src['name']);
    $page_module = 'inventory';
    $focus_id    = 'f_old_prefix';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Clone BOM',
            'subtitle'    => h($src['code']) . ' — ' . h($src['short_description'] ?: $src['name']),
            'back_href'   => url('/inventory.php?action=bom_view&id=' . $id),
            'back_label'  => 'BOM tree',
        ]) ?>
        <form method="post" action="<?= h(url('/inventory.php?action=bom_clone_compute')) ?>"
              class="form-page-body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="f_old_prefix">Old prefix</label>
                    <input id="f_old_prefix" name="old_prefix" type="text" required tabindex="1"
                           value="<?= h($oldPrefixGuess) ?>"
                           placeholder="e.g. PN-1234"
                           style="font-family: var(--font-mono, monospace);">
                    <span class="muted small">
                        Items whose short description starts with this token (followed by
                        a space or punctuation) will be cloned with the new prefix.
                        Items NOT matching will be reused as shared parts.
                    </span>
                </div>
                <div class="field">
                    <label for="f_new_prefix">New prefix</label>
                    <input id="f_new_prefix" name="new_prefix" type="text" required tabindex="2"
                           placeholder="e.g. PN-5678"
                           style="font-family: var(--font-mono, monospace);">
                    <span class="muted small">
                        Substituted into the cloned items' short descriptions.
                    </span>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" tabindex="3">
                    Compute preview →
                </button>
                <!-- Cancel is auto-injected by assets/js/app.js -->
            </div>
        </form>
    </div>
    <?php
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

if ($action === 'bom_clone_compute') {
    csrf_check();
    if (!$canManageBoms) {
        flash_set('error', 'No permission to manage BOMs.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $id        = (int)input('id', 0);
    $oldPrefix = trim((string)input('old_prefix', ''));
    $newPrefix = trim((string)input('new_prefix', ''));
    $src = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$src) {
        flash_set('error', 'Source item not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    if ($oldPrefix === '' || $newPrefix === '') {
        flash_set('error', 'Both prefixes are required.');
        redirect(url('/inventory.php?action=bom_clone_preview&id=' . $id));
    }
    if ($oldPrefix === $newPrefix) {
        flash_set('error', 'Old and new prefixes must differ.');
        redirect(url('/inventory.php?action=bom_clone_preview&id=' . $id));
    }
    $tree = inv_tree($id, 1);
    if (!$tree['children']) {
        flash_set('error', 'This item has no BOM to clone.');
        redirect(url('/inventory.php?action=bom_view&id=' . $id));
    }
    $plan = bom_clone_build_plan($tree, $oldPrefix, $newPrefix);

    // If the root itself isn't going to be cloned, the user's prefix
    // choice doesn't match the BOM. Refuse rather than silently doing
    // something weird (cloning only children of a reused root would
    // leave the cloned children orphaned).
    if (!$plan['nodes'][0]['will_clone']) {
        flash_set('error', 'The root item "' . h($src['short_description'])
            . '" does not start with the old prefix "' . h($oldPrefix)
            . '". Pick a prefix that matches the root.');
        redirect(url('/inventory.php?action=bom_clone_preview&id=' . $id));
    }

    $cloneCount = 0;
    $reuseCount = 0;
    foreach ($plan['nodes'] as $n) {
        if ($n['will_clone']) $cloneCount++; else $reuseCount++;
    }

    $page_title  = 'Clone BOM: preview';
    $page_module = 'inventory';
    $focus_id    = '';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Clone BOM: preview',
            'subtitle'    => $cloneCount . ' new item' . ($cloneCount === 1 ? '' : 's')
                           . ' will be created, ' . $reuseCount . ' will be reused. '
                           . 'Review the plan and click Save to commit.',
            'back_href'   => url('/inventory.php?action=bom_clone_preview&id=' . $id),
            'back_label'  => 'Prefix step',
        ]) ?>
        <form method="post" action="<?= h(url('/inventory.php?action=bom_clone_commit')) ?>"
              class="form-page-body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="plan" value="<?= h(json_encode($plan)) ?>">

            <div class="card" style="padding: 16px;">
                <ul class="bom-tree" role="tree" style="padding-left: 0;">
                    <?php
                    // Render the plan as a nested list. Each node shows:
                    //   - depth-indented row
                    //   - NEW (green) or REUSE (gray) pill
                    //   - source code + arrow + new short description (editable)
                    //   - qty / refdes from the original edge
                    $iter = function ($parentSrcId, $depth) use (&$iter, $plan) {
                        foreach ($plan['nodes'] as $idx => $n) {
                            if ($n['parent_src_id'] !== $parentSrcId) continue;
                            if ($n['depth'] !== $depth) continue;
                            $isClone = $n['will_clone'];
                            $pillCls = $isClone ? 'pill-active' : 'pill-neutral';
                            $pillTxt = $isClone ? 'NEW' : 'REUSE';
                            ?>
                            <li role="treeitem">
                                <div style="display:flex; gap:10px; align-items:center; padding:6px 8px; border-bottom:1px solid var(--border, #e2e8f0); margin-left: <?= $depth * 20 ?>px;">
                                    <span class="pill <?= $pillCls ?>"><?= $pillTxt ?></span>
                                    <code class="mono"><?= h($n['src_code']) ?></code>
                                    <?php if ($isClone): ?>
                                        <span class="muted">→</span>
                                        <input type="text" name="new_short_desc[<?= (int)$n['src_id'] ?>]"
                                               value="<?= h($n['new_short_desc']) ?>"
                                               required
                                               style="flex:1; font-family: var(--font-mono, monospace); min-width: 200px;">
                                    <?php else: ?>
                                        <span><?= h($n['src_short_desc']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($n['parent_src_id'] !== null): ?>
                                        <span class="muted small">×<?= rtrim(rtrim(number_format($n['qty'], 3), '0'), '.') ?></span>
                                        <?php if (!empty($n['ref_designator'])): ?>
                                            <span class="muted small">@ <?= h($n['ref_designator']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <ul style="list-style:none; padding:0; margin:0;">
                                    <?php $iter($n['src_id'], $depth + 1); ?>
                                </ul>
                            </li>
                            <?php
                        }
                    };
                    // Root has parent_src_id = null
                    $iter(null, 0);
                    ?>
                </ul>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    💾 Save — create <?= $cloneCount ?> new item<?= $cloneCount === 1 ? '' : 's' ?>
                </button>
                <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=bom_clone_preview&id=' . $id)) ?>">← Back</a>
                <!-- Cancel auto-injected by assets/js/app.js (uses history.back) -->
            </div>
        </form>
    </div>
    <?php
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

if ($action === 'bom_clone_commit') {
    csrf_check();
    if (!$canManageBoms) {
        flash_set('error', 'No permission to manage BOMs.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $id   = (int)input('id', 0);
    $planJson = (string)input('plan', '');
    $plan = json_decode($planJson, true);
    if (!is_array($plan) || empty($plan['nodes'])) {
        flash_set('error', 'Bad plan. Restart the clone flow.');
        redirect(url('/inventory.php?action=bom_clone_preview&id=' . $id));
    }
    // Merge user-edited new_short_desc values back into the plan.
    $edited = (array)input('new_short_desc', []);
    foreach ($plan['nodes'] as $i => $n) {
        if ($n['will_clone'] && isset($edited[$n['src_id']])) {
            $plan['nodes'][$i]['new_short_desc'] = trim((string)$edited[$n['src_id']]);
        }
    }
    // Validate: every clone-node has a non-empty new_short_desc, no
    // duplicates, prefix still substituted (well, we trust the form here).
    $seen = [];
    foreach ($plan['nodes'] as $n) {
        if (!$n['will_clone']) continue;
        $sd = (string)$n['new_short_desc'];
        if ($sd === '') {
            flash_set('error', 'All cloned items need a short description. One was left blank.');
            redirect(url('/inventory.php?action=bom_clone_compute&id=' . $id
                . '&old_prefix=' . urlencode($plan['old_prefix'])
                . '&new_prefix=' . urlencode($plan['new_prefix'])));
        }
        if (isset($seen[$sd])) {
            flash_set('error', 'Duplicate short description in the plan: "' . h($sd) . '".');
            redirect(url('/inventory.php?action=bom_clone_compute&id=' . $id));
        }
        $seen[$sd] = true;
    }

    // Build a src_id → cloned_id map as we go. Walk nodes in order
    // (root first, then DFS) so parents exist before their children
    // try to reference them.
    db_exec('START TRANSACTION');
    try {
        $cloneMap = []; // src_id → new_id (only entries for will_clone nodes)
        foreach ($plan['nodes'] as $n) {
            if (!$n['will_clone']) continue;
            $srcId = (int)$n['src_id'];
            $newCode = clone_unique_code('inv_items', 'code',
                (string)db_val('SELECT code FROM inv_items WHERE id = ?', [$srcId], ''));
            $newId = clone_row('inv_items', $srcId, [
                'code'              => $newCode,
                'name'              => $n['new_short_desc'],
                'short_description' => $n['new_short_desc'],
                'is_active'         => 1,
                'stock_on_hand'     => 0,
                'stock_rejected'    => 0,
                'stock_on_order'    => 0,
                'follow_up_note'    => null,
            ], ['created_at', 'updated_at']);
            if ($newId <= 0) {
                throw new Exception('Clone failed for item ' . $srcId);
            }
            $cloneMap[$srcId] = $newId;
            // Copy vendor + cert associations
            db_exec(
                'INSERT INTO inv_item_vendors (item_id, vendor_id, sort_order)
                 SELECT ?, vendor_id, sort_order FROM inv_item_vendors WHERE item_id = ?',
                [$newId, $srcId]
            );
            db_exec(
                'INSERT INTO inv_item_certs (item_id, cert_id)
                 SELECT ?, cert_id FROM inv_item_certs WHERE item_id = ?',
                [$newId, $srcId]
            );
        }

        // Now rebuild bom_lines for each cloned parent. For each node
        // that was cloned, look at its children in the plan, and for
        // each child decide which item_id to point to (cloneMap if the
        // child was cloned, otherwise the original src_id).
        foreach ($plan['nodes'] as $parentNode) {
            if (!$parentNode['will_clone']) continue;
            $parentSrcId = (int)$parentNode['src_id'];
            $parentNewId = $cloneMap[$parentSrcId];
            foreach ($plan['nodes'] as $childNode) {
                if ($childNode['parent_src_id'] !== $parentSrcId) continue;
                $childSrcId = (int)$childNode['src_id'];
                $childResolvedId = isset($cloneMap[$childSrcId])
                    ? $cloneMap[$childSrcId]
                    : $childSrcId;
                db_exec(
                    'INSERT INTO inv_bom_lines
                       (parent_item_id, child_item_id, qty, sort_order, ref_designator, notes)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$parentNewId, $childResolvedId,
                     (float)$childNode['qty'],
                     (int)$childNode['sort_order'],
                     $childNode['ref_designator'],
                     $childNode['line_notes']]
                );
            }
        }

        $rootNewId = $cloneMap[(int)$plan['nodes'][0]['src_id']] ?? 0;
        $cloneCount = count($cloneMap);

        db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.bom.clone_prefix', ?, ?)",
            [current_user_id(), $rootNewId,
             'cloned BOM root ' . $plan['nodes'][0]['src_code']
             . ' with prefix "' . $plan['old_prefix'] . '" → "' . $plan['new_prefix'] . '" — '
             . $cloneCount . ' new items']);

        db_exec('COMMIT');
        flash_set('success', 'BOM cloned: ' . $cloneCount . ' new item'
            . ($cloneCount === 1 ? '' : 's') . ' created.');
        redirect(url('/inventory.php?action=bom_designer&id=' . $rootNewId));
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        error_log('[bom_clone_commit] failed: ' . $e->getMessage());
        flash_set('error', 'BOM clone failed: ' . $e->getMessage());
        redirect(url('/inventory.php?action=bom_clone_preview&id=' . $id));
    }
}



// ============================================================
// BOMs list (datatable showing only is_product=1 items)
// ============================================================
if ($action === 'boms') {
    if (!$canViewBoms) require_permission('inventory_view_boms', 'view');

    // "Finished good" predicate — same definition used by the products
    // grid (action=bom_grid). An item counts as a finished good if:
    //   (a) the boolean is_product = 1 is set, OR
    //   (b) it is tagged with the inventory category code 'finshd'
    //       ("Finished Good"), which is what most users actually manage.
    // The bare is_product check missed items where the category was set
    // but the (hidden) boolean wasn't, which is the common case.
    // Finished-good (BOM top element) predicate. An item is a top element
    // ONLY if its CATEGORY is a Finished Good — matched by NAME (starts with
    // "finished good", case-insensitive) or a known code, never by id, so it
    // survives category re-imports that renumber the "Finished Good" category.
    // Items without a Finished Good category are NOT top elements.
    $finishedGoodPred =
        "(i.category_id IN
            (SELECT id FROM categories
              WHERE LOWER(name) LIKE 'finished good%' OR code IN ('finshd','FINISHED_GOO')))";

    $dtCfg = [
        'id'       => 'inv_boms',
        'base_sql' => 'SELECT i.*,
                              (SELECT COUNT(*) FROM inv_bom_lines bl WHERE bl.parent_item_id = i.id) AS line_count
                         FROM inv_items i',
        'extra_where' => [[$finishedGoodPred, []]],
        'columns'  => [
            ['key'=>'short_description', 'label'=>'Product', 'sortable'=>true, 'searchable'=>true,
             // Searchable on both inv code and name so "(CODE)-Name"
             // appears in results whether the user typed the code or
             // any part of the description. The cell is also the link
             // to the BOM view — we used to have a separate Inv Id
             // column for that, but the code is now redundant with
             // the prefix shown here, so the column was dropped.
             'sql_col'=>"CONCAT('(', i.code, ')-', COALESCE(NULLIF(i.short_description, ''), i.name))"],
            ['key'=>'line_count',  'label'=>'Top-level lines', 'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'unit_cost',   'label'=>'Cost/unit',  'sortable'=>true, 'searchable'=>false,'sql_col'=>'i.unit_cost', 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'stock_on_hand','label'=>'Stock',     'sortable'=>true, 'searchable'=>false,'sql_col'=>'i.stock_on_hand', 'th_class'=>'r','td_class'=>'r'],
            ['key'=>'is_active',   'label'=>'Status',     'sortable'=>true, 'sql_col'=>'i.is_active',
             'filter' => [
                 'type' => 'select',
                 'placeholder' => 'all',
                 'options' => [
                     ['value' => '1', 'label' => 'Active'],
                     ['value' => '0', 'label' => 'Inactive'],
                 ],
             ]],
            ['key'=>'_actions',    'label'=>'Actions',    'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['short_description', 'asc'],
    ];
    $rowRenderer = function ($i) use ($canManageBoms, $canDeleteBoms) {
        $cost = $i['unit_cost'] !== null ? '₹ ' . number_format((float)$i['unit_cost'], 2) : '—';
        $status = $i['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">inactive</span>';
        $actions = '<a class="btn btn-icon" href="' . h(url('/inventory.php?action=bom_view&id=' . (int)$i['id'])) . '" title="View BOM tree" aria-label="View BOM tree">🌳 <span class="dt-action-label">View BOM tree</span></a>'
                 . ' <a class="btn btn-icon" href="' . h(url('/inventory.php?action=bom_designer&id=' . (int)$i['id'])) . '" title="BOM designer" aria-label="BOM designer">🛠 <span class="dt-action-label">BOM designer</span></a>'
                 . ' <a class="btn btn-icon" href="' . h(url('/inventory.php?action=bom_edit&id=' . (int)$i['id'])) . '" title="Tabular edit" aria-label="Tabular edit">✎ <span class="dt-action-label">Tabular edit</span></a>';
        if ($canManageBoms) {
            $actions .= ' <form method="post" style="display:inline;"'
                     . ' action="' . h(url('/inventory.php?action=bom_clone_preview')) . '">'
                     . csrf_field()
                     . '<input type="hidden" name="id" value="' . (int)$i['id'] . '">'
                     . '<button type="submit" class="btn btn-icon" title="Clone BOM (with prefix rewrite)"'
                     . ' aria-label="Clone BOM">⎘ <span class="dt-action-label">Clone BOM</span></button>'
                     . '</form>';
        }
        if (($canDeleteBoms || $canManageBoms) && (int)$i['line_count'] > 0) {
            $actions .= ' <a class="btn btn-icon btn-danger"'
                     . ' href="' . h(url('/inventory.php?action=bom_delete_preview&id=' . (int)$i['id'])) . '"'
                     . ' title="Delete BOM (edges + orphan items)"'
                     . ' aria-label="Delete BOM">🗑 <span class="dt-action-label">Delete BOM</span></a>';
        }
        $actions .= notes_popup_menu_item('inv_item', (int)$i['id']);
        return [
            'short_description' => '<strong><a href="' . h(url('/inventory.php?action=bom_view&id=' . (int)$i['id'])) . '">'
                                 . '(' . h($i['code']) . ')-' . h($i['short_description'] ?: $i['name'])
                                 . '</a></strong>',
            'line_count'        => (int)$i['line_count'],
            'unit_cost'         => $cost,
            'stock_on_hand'     => number_format((float)$i['stock_on_hand'], 3),
            'is_active'         => $status,
            '_actions'          => dt_actions_wrap($actions),
        ];
    };
    $dt = data_table_run($dtCfg, $rowRenderer);

    $page_title  = 'BOMs';
    $page_module = 'inventory';
    $focus_id    = '';

    $actionsHtml  = '<a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=items')) . '"'
                  . ' data-shortcut="I" accesskey="i">' . shortcut_label('View Inventory', 'I') . '</a>';
    $actionsHtml .= ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=bom_grid')) . '"'
                  . ' data-shortcut="T" accesskey="t">' . shortcut_label('Tree view', 'T') . '</a>';
    if ($canCreateItems) {
        $actionsHtml .= ' <a class="btn btn-primary btn-sm" href="' . h(url('/inventory.php?action=item_new')) . '"'
                      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New item', 'N') . '</a>';
    }
    $dtCfg['title']        = 'Bills of Materials';
    $dtCfg['actions_html'] = $actionsHtml;

    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    <?php notes_popup_assets(); ?>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// BOM view (read-only tree)
// ============================================================
if ($action === 'bom_view') {
    if (!$canViewBoms) require_permission('inventory_view_boms', 'view');
    $id = (int)input('id', 0);
    $item = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$item) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $tree = inv_tree($id, 1);

    // Count totals
    function _count_nodes($t) {
        if (!$t) return 0;
        $n = 1;
        foreach ($t['children'] as $c) $n += _count_nodes($c);
        return $n;
    }
    $totalNodes = _count_nodes($tree) - 1; // exclude root

    $page_title  = 'BOM: ' . ($item['short_description'] ?: $item['name']);
    $page_module = 'inventory';
    $focus_id    = '';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($item["short_description"] ?: $item["name"]) ?> <span class="muted small mono"><?= h($item['code']) ?></span></h1>
            <p class="muted">
                Engineering BOM ·
                <?= (int)$totalNodes ?> total lines ·
                Rolled-up cost: <?= $tree['ext_cost'] !== null ? '₹ ' . number_format($tree['ext_cost'], 2) : '—' ?>
            </p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=boms')) ?>"
               data-shortcut="B" accesskey="b"><?= shortcut_label('← Back to BOMs', 'B') ?></a>
            <?php if ($canManageBoms): ?>
                <a class="btn btn-primary" href="<?= h(url('/inventory.php?action=bom_designer&id=' . $id)) ?>"
                   data-shortcut="C" accesskey="c"><?= shortcut_label($tree['children'] ? 'Edit BOM (Designer)' : '+ Create BOM', 'C') ?></a>
                <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=bom_edit&id=' . $id)) ?>"
                   data-shortcut="E" accesskey="e"><?= shortcut_label('Tabular edit', 'E') ?></a>
                <?php if ($tree['children']): // only offer Clone when there's a BOM to clone ?>
                <form method="post" style="display:inline" action="<?= h(url('/inventory.php?action=bom_clone_preview')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button type="submit" class="btn btn-ghost" title="Clone this BOM to a new product with prefix rewrite">⎘ Clone BOM</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (($canDeleteBoms || $canManageBoms) && $tree['children']): ?>
                <a class="btn btn-danger"
                   href="<?= h(url('/inventory.php?action=bom_delete_preview&id=' . $id)) ?>"
                   title="Remove the entire BOM tree (edges + orphan items)">🗑 Delete BOM</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <?php if (!$tree['children']): ?>
            <p class="muted empty">This item has no BOM yet.
                <?php if ($canManageBoms): ?>
                    <a class="btn btn-primary" href="<?= h(url('/inventory.php?action=bom_designer&id=' . $id)) ?>"
                       style="margin-left: 8px;">+ Create BOM (drag-and-drop designer) →</a>
                    <br><span class="muted small">Or use the <a href="<?= h(url('/inventory.php?action=bom_edit&id=' . $id)) ?>">tabular editor</a> instead.</span>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <div class="bom-tree-toolbar">
                <button type="button" class="btn btn-sm btn-ghost" id="bom-expand-all">Expand all</button>
                <button type="button" class="btn btn-sm btn-ghost" id="bom-collapse-all">Collapse all</button>
                <span class="muted small" style="margin-left:auto;" title="Renderer build — confirms which version is live">tree renderer build 2026-05-28b (shared-node)</span>
            </div>
            <ul class="bom-tree" role="tree">
                <?php
                /**
                 * Recursive renderer producing a list-of-lists structure
                 * suitable for the tree CSS / JS. The root is rendered
                 * as a single <li> with the product info; each child
                 * BOM line becomes a nested <li>.
                 */
                function render_bom_node($node, $depth = 0) {
                    $item = $node['item'];
                    $hasKids = !empty($node['children']);
                    $stockLow = (float)$item['stock_on_hand'] < $node['qty'];
                    ?>
                    <li class="bom-node <?= $depth === 0 ? 'bom-root' : '' ?>" role="treeitem"
                        aria-expanded="<?= $hasKids ? 'true' : 'false' ?>">
                        <div class="bom-row">
                            <?php if ($hasKids): ?>
                                <button type="button" class="bom-toggle" aria-label="Toggle children">▾</button>
                            <?php else: ?>
                                <span class="bom-toggle bom-leaf" aria-hidden="true">·</span>
                            <?php endif; ?>
                            <span class="bom-icon"><?= $hasKids ? '📁' : '🧩' ?></span>
                            <div class="bom-main">
                                <strong class="mono"><?= h($item['code']) ?></strong>
                                <span class="bom-name"><?= h($item['display_name'] ?? ($item['short_description'] ?: $item['name'])) ?></span>
                                <?php if (!empty($node['ref_designator'])): ?>
                                    <span class="bom-ref">[<?= h($node['ref_designator']) ?>]</span>
                                <?php endif; ?>
                            </div>
                            <div class="bom-meta">
                                <?php if ($depth > 0): ?>
                                    <span class="bom-qty" title="Quantity needed for one of the top-level product">
                                        <?= number_format($node['qty'], 3) ?> <?= h($item['uom_label'] ?? $item['uom'] ?? '') ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($node['ext_cost'] !== null): ?>
                                    <span class="bom-cost">₹ <?= number_format($node['ext_cost'], 2) ?></span>
                                <?php endif; ?>
                                <span class="bom-stock <?= $stockLow ? 'bom-stock-low' : '' ?>"
                                      title="Current stock on hand for this item">
                                    stock: <?= number_format((float)$item['stock_on_hand'], 3) ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($hasKids): ?>
                            <ul class="bom-children" role="group">
                                <?php foreach ($node['children'] as $c) render_bom_node($c, $depth + 1); ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                    <?php
                }
                render_bom_node($tree, 0);
                ?>
            </ul>
        <?php endif; ?>
    </div>

    <script>
    /* Tree expand/collapse. Event-delegated on document so listeners
       survive SPA navigation. Initial state is COLLAPSED — only the root
       and its direct children are visible. Click a chevron to expand. */
    (function () {
        function setOpen(node, open) {
            if (!node || !node.classList.contains('bom-node')) return;
            var hasKids = node.querySelector(':scope > .bom-children');
            if (!hasKids) return;
            node.classList.toggle('bom-closed', !open);
            node.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        function applyDefault() {
            // Collapse every node except the root, so the page opens
            // showing one level of children. The user expands further
            // by clicking chevrons.
            document.querySelectorAll('.bom-tree .bom-node').forEach(function (n) {
                if (n.classList.contains('bom-root')) {
                    setOpen(n, true);
                } else {
                    setOpen(n, false);
                }
            });
        }
        window.__BomView = { applyDefault: applyDefault };

        if (window.__bomViewBound) { applyDefault(); return; }
        window.__bomViewBound = true;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.bom-toggle');
            if (btn && !btn.classList.contains('bom-leaf')) {
                var node = btn.closest('.bom-node');
                if (node) setOpen(node, node.classList.contains('bom-closed'));
                return;
            }
            if (e.target.id === 'bom-expand-all') {
                document.querySelectorAll('.bom-tree .bom-node').forEach(function (n) { setOpen(n, true); });
            } else if (e.target.id === 'bom-collapse-all') {
                document.querySelectorAll('.bom-tree .bom-node').forEach(function (n) {
                    if (!n.classList.contains('bom-root')) setOpen(n, false);
                });
            }
        });

        applyDefault();
    })();
    </script>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// BOM edit (one level at a time)
// ============================================================
if ($action === 'bom_edit') {
    if (!$canManageBoms) require_permission('inventory_view_boms', 'manage');
    $id = (int)input('id', 0);
    $item = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$item) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $lines = db_all(
        'SELECT bl.*,
                ci.code AS child_code,
                COALESCE(NULLIF(ci.short_description, ""), ci.name) AS child_name,
                COALESCE(u.label, ci.uom) AS uom,
                ci.unit_cost
           FROM inv_bom_lines bl
           JOIN inv_items ci ON ci.id = bl.child_item_id
           LEFT JOIN inv_uom u ON u.id = ci.uom_id
          WHERE bl.parent_item_id = ?
          ORDER BY bl.sort_order, bl.id',
        [$id]
    );
    // Item picker: every item EXCEPT this item and its ancestors (which
    // would create cycles). For large catalogues this is fine — a few
    // hundred rows; if it grows, switch to typeahead.
    $forbidden = inv_ancestors_of($id);
    $forbiddenSql = '(' . implode(',', array_map('intval', $forbidden)) . ')';
    $candidates = db_all(
        "SELECT id, code,
                COALESCE(NULLIF(short_description, ''), name) AS name,
                COALESCE((SELECT label FROM inv_uom WHERE id = uom_id), uom) AS uom
           FROM inv_items
          WHERE is_active = 1 AND id NOT IN $forbiddenSql
          ORDER BY code"
    );

    $page_title  = 'Edit BOM: ' . ($item['short_description'] ?: $item['name']);
    $page_module = 'inventory';
    $focus_id    = 'f_child';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>Edit structure: <?= h($item['short_description'] ?: $item['name']) ?>
                <span class="muted small mono"><?= h($item['code']) ?></span></h1>
            <p class="muted">Add, update, or remove direct children of this item.
                To edit deeper levels, navigate into the child and edit that item's BOM.</p>
        </div>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=bom_view&id=' . $id)) ?>"
               data-shortcut="V" accesskey="v"><?= shortcut_label('← View tree', 'V') ?></a>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Add a child line</h2></div>
        <form method="post" action="<?= h(url('/inventory.php?action=bom_line_add')) ?>" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="parent_item_id" value="<?= (int)$id ?>">
            <div class="field">
                <label for="f_child">Child item *</label>
                <select id="f_child" name="child_item_id" required tabindex="1">
                    <option value="">— Select —</option>
                    <?php foreach ($candidates as $c): ?>
                        <option value="<?= (int)$c['id'] ?>">
                            <?= h($c['code']) ?> — <?= h($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_qty">Qty *</label>
                <input id="f_qty" name="qty" type="number" step="0.001" min="0.001" value="1" required tabindex="2">
            </div>
            <div class="field">
                <label for="f_ref">Ref designator</label>
                <input id="f_ref" name="ref_designator" type="text" placeholder="R1 / U3 / Pos-04" tabindex="3">
            </div>
            <div class="field span-2">
                <label for="f_notes">Notes</label>
                <input id="f_notes" name="notes" type="text" tabindex="4">
            </div>
            <div class="field span-2">
                <button type="submit" class="btn btn-primary" tabindex="5"
                        data-shortcut="A" accesskey="a"><?= shortcut_label('Add line', 'A') ?></button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-head">
            <h2>Current children</h2>
            <span class="muted small"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
        </div>
        <?php if (!$lines): ?>
            <p class="empty muted">No children yet. Use the form above to add the first one.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Child</th>
                        <th>Ref</th>
                        <th class="r">Qty</th>
                        <th>UoM</th>
                        <th class="r">Ext. cost</th>
                        <th>Notes</th>
                        <th class="r">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $L): ?>
                        <?php
                        $ext = $L['unit_cost'] !== null ? (float)$L['unit_cost'] * (float)$L['qty'] : null;
                        ?>
                        <tr>
                            <form method="post" action="<?= h(url('/inventory.php?action=bom_line_update')) ?>" id="upd-<?= (int)$L['id'] ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="line_id" value="<?= (int)$L['id'] ?>">
                            </form>
                            <td>
                                <input form="upd-<?= (int)$L['id'] ?>" name="sort_order" type="number"
                                       value="<?= (int)$L['sort_order'] ?>" style="width: 60px;">
                            </td>
                            <td>
                                <strong class="mono"><?= h($L['child_code']) ?></strong>
                                <div class="muted small"><?= h($L['child_name']) ?></div>
                            </td>
                            <td>
                                <input form="upd-<?= (int)$L['id'] ?>" name="ref_designator" type="text"
                                       value="<?= h($L['ref_designator'] ?? '') ?>" style="width: 100px;">
                            </td>
                            <td class="r">
                                <input form="upd-<?= (int)$L['id'] ?>" name="qty" type="number"
                                       step="0.001" min="0.001" value="<?= h($L['qty']) ?>"
                                       style="width: 80px; text-align: right;" required>
                            </td>
                            <td><?= h($L['uom']) ?></td>
                            <td class="r"><?= $ext !== null ? '₹ ' . number_format($ext, 2) : '—' ?></td>
                            <td>
                                <input form="upd-<?= (int)$L['id'] ?>" name="notes" type="text"
                                       value="<?= h($L['notes'] ?? '') ?>" style="width: 100%;">
                            </td>
                            <td class="r nowrap">
                                <button form="upd-<?= (int)$L['id'] ?>" type="submit" class="btn btn-sm btn-ghost">Save</button>
                                <a class="btn btn-sm btn-ghost"
                                   href="<?= h(url('/inventory.php?action=bom_edit&id=' . (int)$L['child_item_id'])) ?>">Drill into</a>
                                <form method="post" style="display:inline"
                                      action="<?= h(url('/inventory.php?action=bom_line_delete')) ?>"
                                      onsubmit="return confirm('Remove line for <?= h(addslashes($L['child_code'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="line_id" value="<?= (int)$L['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// BOM tree view, tabular ("Pending PO"-style grid)
//
// Shows products grouped by division. Each row is one node in the
// engineering BOM. Columns:
//   Product Name — tree-indented with toggle
//   Avb          — total qty across locations OTHER than O-Rework,
//                  I-Rework, LOC-REJ (i.e. truly available stock)
//   REJ          — qty at LOC-REJ
//   TBR          — open receive balance from Ship & Receipt
//                  (SUM of qty_planned - qty_received on non-cancelled,
//                   non-closed receive lines)
//   Rework       — qty at O-Rework + qty at I-Rework
//                  (data-bgrid-col key remains 'mb' for stable column
//                   width localStorage compatibility)
//   Options      — actions menu
// ============================================================
if ($action === 'bom_grid') {
    if (!$canViewBoms) require_permission('inventory_view_boms', 'view');

    // Tabs from categories where type='division'. The "active" tab is in
    // the URL (?division=<id>); the count in each tab is the number of
    // is_product=1 items belonging to that division.
    $divisions = db_all("SELECT id, name FROM categories WHERE type='division' AND is_active=1 ORDER BY sort_order, name");

    // "Finished good" predicate, used in every product-loading query below.
    // An item counts as a finished good if EITHER:
    //   (a) the boolean column is_product = 1 is set, OR
    //   (b) it is tagged with the inventory category whose code is 'finshd'
    //       ("Finished Good"), which is what users see in the Category column
    //       and what most people naturally manage.
    // Doing both is more forgiving than just is_product, which is a hidden
    // boolean that gets out of sync with the visible category easily.
    // Finished-good (BOM top element) predicate. An item is a top element
    // ONLY if its CATEGORY is a Finished Good — matched by NAME (starts with
    // "finished good", case-insensitive) or a known code, never by id, so it
    // survives category re-imports that renumber the "Finished Good" category.
    // Items without a Finished Good category are NOT top elements.
    $finishedGoodPred =
        "(i.category_id IN
            (SELECT id FROM categories
              WHERE LOWER(name) LIKE 'finished good%' OR code IN ('finshd','FINISHED_GOO')))";

    // Per-division counts for the tabs (treat NULL division as 0 so we
    // can show a count next to each tab plus an overall total in "All").
    $counts = [];
    $totalCount = 0;
    foreach (db_all(
        "SELECT COALESCE(i.division_id, 0) AS d, COUNT(*) AS c
           FROM inv_items i
          WHERE $finishedGoodPred
       GROUP BY d"
    ) as $r) {
        $counts[(int)$r['d']] = (int)$r['c'];
        $totalCount += (int)$r['c'];
    }

    // 'all' (or no value) means show every finished good. Otherwise the
    // tab carries the division id as an integer string.
    $divFilter = (string)input('division', 'all');
    if ($divFilter !== 'all') $divFilter = (string)(int)$divFilter;

    // Build the products query: with or without division filter.
    if ($divFilter === 'all') {
        $products = db_all(
            "SELECT i.* FROM inv_items i
              WHERE $finishedGoodPred
              ORDER BY i.code"
        );
    } else {
        $products = db_all(
            "SELECT i.* FROM inv_items i
              WHERE $finishedGoodPred AND i.division_id = ?
              ORDER BY i.code",
            [(int)$divFilter]
        );
    }

    // ----- Diagnostic strip (temporary). Reveals what the page actually
    // loaded so the user can confirm against expectations. Visible only
    // when ?debug=1 is in the URL. Once the issue is identified this
    // block can be removed.
    $debug = (int)input('debug', 0) === 1;
    if ($debug) {
        $allProductsDebug = db_all(
            "SELECT i.id, i.code, COALESCE(NULLIF(i.short_description, ''), i.name) AS label,
                    i.is_product, i.is_active, i.division_id, i.category_id,
                    c.name AS category_name, c.code AS category_code
               FROM inv_items i
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE $finishedGoodPred
              ORDER BY i.code"
        );
    }

    // For the grid we want each product's full BOM tree flattened into
    // an ordered list of (item, depth, parent_line_id, line_id, qty) for
    // efficient rendering. The product itself is depth 0.
    // ----------------------------------------------------------------
    // Bulk-fetch ALL items + BOM lines into two maps, then walk in
    // memory. This replaces the previous N+1 implementation (per-item
    // db_one + db_all) which was the main performance bottleneck on
    // realistic BOMs. For a 35-row demo BOM the saving is ~70 queries.
    //
    // Important: we load EVERY item row regardless of is_active or
    // is_product. Earlier this query filtered to active rows (with a
    // narrow finished-goods exception for root products); that caused
    // inactive sub-components — a deactivated raw material, a retired
    // fastener — to silently drop out of `$itemsMap`, which made
    // `flatten_bom_mem` return at the `if (!$item) return;` guard and
    // truncate the entire subtree under that node. The grid showed
    // only the visible-tip of the tree while the BOM designer (which
    // loads each child with an unfiltered `SELECT * WHERE id=?`)
    // showed the full structure — a confusing discrepancy.
    //
    // Loading the full inv_items table costs a single query and at
    // most tens of MB of PHP memory even for a large catalogue;
    // perfectly safe.
    // ----------------------------------------------------------------
    $allItems = [];
    foreach (db_all(
        "SELECT i.*, u.label AS uom_label, c.name AS category_name, c.code AS category_code
           FROM inv_items i
           LEFT JOIN inv_uom u ON u.id = i.uom_id
           LEFT JOIN categories c ON c.id = i.category_id"
    ) as $r) {
        $allItems[(int)$r['id']] = $r;
    }
    $allLines = [];
    foreach (db_all(
        'SELECT bl.id, bl.parent_item_id, bl.child_item_id, bl.qty, bl.sort_order
           FROM inv_bom_lines bl
          ORDER BY bl.sort_order, bl.id'
    ) as $r) {
        $allLines[(int)$r['parent_item_id']][] = $r;
    }

    // ----------------------------------------------------------------
    // Pending-SO summary. The external sales-order app pushes
    // aggregates to MagDyn's /api/so_pending endpoint whenever an SO
    // is added/amended/closed; rows live in inv_so_pending_summary
    // keyed by item_code. Here we just pull them all into a code-
    // indexed map and use it to render a (N · Q) badge next to each
    // part name in the tree below. No HTTP, no timeout risk.
    //
    // Defensive try/catch so a fresh install that hasn't yet run
    // migration_20260525_073234 (which creates the table) still
    // renders the grid — falling back to "no badges anywhere"
    // instead of throwing a fatal.
    // ----------------------------------------------------------------
    $soPending = [];
    try {
        foreach (db_all(
            'SELECT item_code, so_count, qty_pending
               FROM inv_so_pending_summary'
        ) as $r) {
            $soPending[(string)$r['item_code']] = [
                'so_count'    => (int)$r['so_count'],
                'qty_pending' => (float)$r['qty_pending'],
            ];
        }
    } catch (\Throwable $e) {
        // Table missing or DB hiccup — silently degrade. The grid
        // still renders; badges just don't appear.
        $soPending = [];
    }

    // ----------------------------------------------------------------
    // Per-item aggregates for the four numeric columns. These are
    // computed ONCE for all items the grid might render, then looked up
    // by item_id during row rendering. Items with no rows in a given
    // aggregate default to 0.
    //
    //   $qtyRejByItem[id]    = qty at LOC-REJ
    //   $qtyReworkByItem[id] = qty at O-Rework + I-Rework
    //   $qtyTotalByItem[id]  = SUM(qty) across ALL locations
    //   $avbByItem[id]       = qtyTotal - qtyRej - qtyRework (clamped to >= 0)
    //   $tbrByItem[id]       = open receive balance from Ship & Receipt
    //
    // Location filter is by CODE (case-insensitive, exact match), so the
    // semantics are stable even if location IDs change. If any of the
    // three special locations don't exist in this database, the matching
    // aggregate for that location is just empty — safe, no error.
    // ----------------------------------------------------------------
    $rejCodes    = ['LOC-REJ'];
    $reworkCodes = ['O-Rework', 'I-Rework'];

    $qtyRejByItem    = [];
    $qtyReworkByItem = [];
    $qtyTotalByItem  = [];

    // Build a single query that returns per-item totals split into three
    // buckets — rej, rework, all — in one pass. CASE-style sum is faster
    // than three separate queries on Hostinger's MariaDB.
    //
    // NOTE: the location table is `locations` (Assets and Inventory share
    // it after the 2026-05-17 unify-locations migration). The old
    // `inv_locations` table no longer exists.
    $rejInList    = "'" . implode("','", array_map(function ($c) { return addslashes($c); }, $rejCodes))    . "'";
    $reworkInList = "'" . implode("','", array_map(function ($c) { return addslashes($c); }, $reworkCodes)) . "'";

    foreach (db_all(
        "SELECT s.item_id,
                SUM(CASE WHEN l.code COLLATE utf8mb4_unicode_ci IN ($rejInList)    THEN s.qty ELSE 0 END) AS rej_qty,
                SUM(CASE WHEN l.code COLLATE utf8mb4_unicode_ci IN ($reworkInList) THEN s.qty ELSE 0 END) AS rework_qty,
                SUM(s.qty) AS total_qty
           FROM inv_item_location_stock s
           JOIN locations l ON l.id = s.location_id
       GROUP BY s.item_id"
    ) as $r) {
        $iid = (int)$r['item_id'];
        $qtyRejByItem[$iid]    = (float)$r['rej_qty'];
        $qtyReworkByItem[$iid] = (float)$r['rework_qty'];
        $qtyTotalByItem[$iid]  = (float)$r['total_qty'];
    }

    // TBR — open receive balance from Ship & Receipt. A line contributes
    // GREATEST(qty_planned - qty_received, 0) to its item's TBR if its
    // parent shipment is still open (anything except 'cancelled' or
    // 'closed'). Multiple lines on different shipments aggregate.
    $tbrByItem = [];
    foreach (db_all(
        "SELECT sl.item_id,
                SUM(GREATEST(sl.qty_planned - sl.qty_received, 0)) AS tbr_qty
           FROM inv_shipment_lines sl
           JOIN inv_shipments s ON s.id = sl.shipment_id
          WHERE sl.line_kind = 'receive'
            AND s.status NOT IN ('cancelled', 'closed')
       GROUP BY sl.item_id"
    ) as $r) {
        $tbrByItem[(int)$r['item_id']] = (float)$r['tbr_qty'];
    }

    /**
     * Format a quantity for the BOM grid numeric columns.
     * Blank when zero (so the grid stays uncluttered), 3-decimal otherwise
     * with trailing zeros and bare decimal points trimmed. Defined as a
     * free function (not a closure inside the row loop) so it's reused
     * across thousands of cells without per-row construction overhead.
     */
    if (!function_exists('bom_fmt_qty')) {
        function bom_fmt_qty($q) {
            if ($q <= 0) return '';
            return rtrim(rtrim(number_format($q, 3), '0'), '.');
        }
    }

    /**
     * Walk the in-memory BOM into a flat list of [item, depth, parent_line, line_id, qty].
     *
     * Semantics:
     *   - `line_id`     : the BOM edge that brought THIS row into the tree
     *                     (null for the root)
     *   - `parent_line` : the BOM edge that brought this row's PARENT in
     *                     (null for the root and for direct children of root)
     *   - We use these two to produce stable row IDs ("pX-lineN") and
     *     correct parent links ("pX-parentLine" or "pX-root"). Earlier
     *     code was setting both to the same value, causing the JS
     *     descendant walk to infinite-loop on a self-parent reference
     *     and freeze the browser.
     *
     * Cycle protection: cap recursion depth and reject any path that
     * revisits an item already in the CURRENT chain. (Re-visits across
     * sibling subtrees are allowed, since a shared fastener legitimately
     * appears under multiple parents.)
     *
     * Depth cap: 50. Matches the BOM designer so the two views agree
     * on what's "too deep". Anything past 50 is almost certainly a
     * pathological BOM (real assemblies fit in 5-10 levels). When the
     * cap is hit we DON'T silently return — that would truncate the
     * subtree invisibly. Instead we push a `_truncated` sentinel row
     * so the table renderer can show a marker.
     */
    function flatten_bom_mem($itemId, $depth, $parentLineId, $myLineId, $qty, &$out, $chain, &$itemsMap, &$linesMap, $parentSeq = null) {
        if ($depth > 50) {
            $out[] = [
                'item'        => null,
                'depth'       => $depth,
                'parent_line' => $parentLineId,
                'line_id'     => $myLineId,
                'parent_seq'  => $parentSeq,
                'seq'         => count($out),
                'qty'         => $qty,
                '_truncated'  => true,
            ];
            return;
        }
        if (isset($chain[$itemId])) return;
        $chain[$itemId] = true;
        $item = $itemsMap[$itemId] ?? null;
        if (!$item) return;
        // `seq` is this row's UNIQUE position in the flattened output. We
        // key the DOM row ids off it (not line_id) because a shared
        // sub-assembly appears under multiple parents and therefore reuses
        // the same BOM edge (line_id) in several places — duplicate
        // line_ids would create duplicate data-row-id attributes and the
        // collapse/expand JS could not tell the occurrences apart (it was
        // hiding one occurrence's children). The per-occurrence seq is
        // unique by construction, so each rendered node is addressable.
        $mySeq = count($out);
        $out[] = [
            'item'        => $item,
            'depth'       => $depth,
            'parent_line' => $parentLineId,   // the edge that brought my parent in (or null)
            'line_id'     => $myLineId,       // the edge that brought ME in (null only for the tab's root product)
            'parent_seq'  => $parentSeq,      // unique seq of my parent row (null for root)
            'seq'         => $mySeq,          // my unique seq (DOM row key)
            'qty'         => $qty,
        ];
        foreach (($linesMap[$itemId] ?? []) as $k) {
            $childQty = $qty * (float)$k['qty'];
            flatten_bom_mem(
                (int)$k['child_item_id'],
                $depth + 1,
                $myLineId,            // child's parent_line = MY line_id
                (int)$k['id'],        // child's own line_id = this BOM edge
                $childQty,
                $out, $chain, $itemsMap, $linesMap,
                $mySeq                // child's parent_seq = MY seq
            );
        }
    }

    // Legacy DB-driven version is kept available in case any caller still
    // needs it; we route the grid through the in-memory variant.
    function flatten_bom($itemId, $depth, $parentLineId, $qty, &$out, $multiplier = 1, &$visited = []) {
        if (isset($visited[$itemId])) return;
        $visited[$itemId] = true;
        $item = db_one('SELECT i.*, u.label AS uom_label FROM inv_items i LEFT JOIN inv_uom u ON u.id = i.uom_id WHERE i.id = ?', [$itemId]);
        if (!$item) return;
        $out[] = [
            'item'       => $item,
            'depth'      => $depth,
            'parent_line'=> $parentLineId,
            'line_id'    => null,
            'qty'        => $qty,
        ];
        $kids = db_all(
            'SELECT bl.id, bl.child_item_id, bl.qty
               FROM inv_bom_lines bl
              WHERE bl.parent_item_id = ?
              ORDER BY bl.sort_order, bl.id',
            [$itemId]
        );
        foreach ($kids as $k) {
            $childQty = $multiplier * (float)$k['qty'];
            $beforeCount = count($out);
            flatten_bom((int)$k['child_item_id'], $depth + 1, (int)$k['id'], $childQty, $out, $childQty, $visited);
            if (isset($out[$beforeCount])) {
                $out[$beforeCount]['line_id'] = (int)$k['id'];
            }
        }
        unset($visited[$itemId]);
    }

    $page_title  = 'BOM tree';
    $page_module = 'inventory';
    $focus_id    = '';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="dt-wrap bom-grid-wrap" data-dt-id="bom_grid">
        <div class="bom-tabs-row">
            <a class="bom-tab <?= $divFilter === 'all' ? 'active' : '' ?>"
               href="<?= h(url('/inventory.php?action=bom_grid&division=all')) ?>">
                All <span class="count">(<?= $totalCount ?>)</span>
            </a>
            <?php foreach ($divisions as $d):
                $c = $counts[(int)$d['id']] ?? 0;
            ?>
                <a class="bom-tab <?= $divFilter === (string)(int)$d['id'] ? 'active' : '' ?>"
                   href="<?= h(url('/inventory.php?action=bom_grid&division=' . (int)$d['id'])) ?>">
                    <?= h($d['name']) ?>
                    <span class="count">(<?= $c ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="dt-toolbar bom-actions-row">
            <h2 class="dt-toolbar-title bom-actions-title">BOM tree</h2>
            <div class="dt-toolbar-right">
                <?php if ($canCreateBoms || $canManageBoms): ?>
                    <a class="btn btn-sm btn-ghost" href="<?= h(url('/bom_old_import.php')) ?>"
                       title="Auto-fetch all BOM trees from old inventory server and import">⬇ Import from Old System</a>
                    <button type="button" class="btn btn-sm btn-ghost"
                            data-open-import="bom-import-modal"
                            title="Import BOM lines from CSV">⤒ Import BOM CSV</button>
                <?php endif; ?>
                <?php if ($canManageBoms): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            data-open-bom-picker="1"
                            title="Build a BOM for any item — picks the parent item, then opens the designer">+ New BOM</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-ghost bom-tree-toggle"
                        id="bom-grid-toggle-all"
                        data-state="collapsed"
                        title="Toggle expand/collapse of every row">⊞ Expand all</button>
                <a class="btn btn-sm btn-ghost" href="<?= h(url('/inventory.php?action=boms')) ?>">List view</a>
            </div>
        </div>

        <div class="bom-grid-scroll">
        <?php if ($debug): ?>
            <div style="background: #fff7d6; border: 1px solid #d4ac0d; padding: 8px 12px; font-size: 12px; font-family: monospace;">
                <strong>DEBUG</strong> · divFilter=<code><?= h($divFilter) ?></code> · products in this view=<?= count($products) ?> · finished goods in DB=<?= count($allProductsDebug) ?>
                <details style="margin-top: 6px;"><summary>All finished-good rows in DB (is_product=1 OR category=Finished Good)</summary>
                <ul style="margin: 6px 0 0 16px; padding: 0;">
                <?php foreach ($allProductsDebug as $ap): ?>
                    <li>id=<?= (int)$ap['id'] ?> · code=<code><?= h($ap['code']) ?></code> · <?= h($ap['label']) ?> · is_product=<?= (int)$ap['is_product'] ?> · is_active=<?= (int)$ap['is_active'] ?> · division_id=<?= $ap['division_id'] === null ? 'NULL' : (int)$ap['division_id'] ?> · category=<?= h($ap['category_name'] ?: '—') ?> (<?= h($ap['category_code'] ?: '—') ?>)</li>
                <?php endforeach; ?>
                </ul>
                </details>
                <details style="margin-top: 6px;"><summary>Products being rendered in this view</summary>
                <ul style="margin: 6px 0 0 16px; padding: 0;">
                <?php foreach ($products as $pp): ?>
                    <li>id=<?= (int)$pp['id'] ?> · code=<code><?= h($pp['code']) ?></code> · <?= h($pp['short_description'] ?: $pp['name']) ?></li>
                <?php endforeach; ?>
                </ul>
                </details>
            </div>
        <?php endif; ?>
        <table class="bom-grid dt-table bgrid-fixed-layout" id="bomGrid">
            <?php /* <colgroup> pins every column to a fixed width.
                     Combined with table-layout:fixed this guarantees the
                     layout never changes based on cell content — long product
                     names clip with "..." instead of expanding the column.
                     bom-col-name has no width so it takes all remaining space.
                     JS resize handles may override individual th.style.width
                     at runtime; that's fine — colgroup is just the default. */ ?>
            <colgroup>
                <col class="bom-col-name">
                <col class="bom-col-cat">
                <col class="bom-col-num">
                <col class="bom-col-num">
                <col class="bom-col-num">
                <col class="bom-col-rework">
                <col class="bom-col-actions">
            </colgroup>
            <caption style="caption-side:top; text-align:right; padding:2px 4px;">
                <span class="muted small" title="Renderer build — confirms which version is live">grid renderer build 2026-05-28d (two-row header)</span>
            </caption>
            <thead>
                <tr>
                    <th data-bgrid-col="name">Product Name <span class="bom-cog" title="Column settings (coming soon)">⚙</span><span class="bgrid-resize-handle"></span></th>
                    <th class="bom-cat-col" data-bgrid-col="cat">Category</th>
                    <th class="r" data-bgrid-col="avb">Avb <span class="bom-cog">⚙</span><span class="bgrid-resize-handle"></span></th>
                    <th class="r" data-bgrid-col="rej">REJ <span class="bom-cog">⚙</span><span class="bgrid-resize-handle"></span></th>
                    <th class="r" data-bgrid-col="tbr">TBR <span class="bom-cog">⚙</span><span class="bgrid-resize-handle"></span></th>
                    <th class="r" data-bgrid-col="mb">Rework <span class="bom-cog">⚙</span><span class="bgrid-resize-handle"></span></th>
                    <th data-bgrid-col="actions">Options</th>
                </tr>
            </thead>
        <tbody id="bom-grid-body">
            <?php if (!$products): ?>
                <tr><td colspan="7" class="empty">No finished goods<?= $divFilter === 'all' ? '' : ' in this division' ?>.</td></tr>
            <?php else: foreach ($products as $p):
                $flat = [];
                // Root row: depth 0, no parent_line, no line_id, qty 1.
                flatten_bom_mem((int)$p['id'], 0, null, null, 1, $flat, [], $allItems, $allLines);
                foreach ($flat as $idx => $row):
                    // Truncation sentinel — emitted by flatten_bom_mem when the
                    // depth cap is hit. Render a clear warning row and skip
                    // the normal item-driven path which would deref null.
                    if (!empty($row['_truncated'])) {
                        ?>
                        <tr class="bom-grid-row bom-truncated-row" data-depth="<?= (int)$row['depth'] ?>">
                            <td class="bom-name-cell" colspan="7" style="padding-left: <?= 10 + $row['depth'] * 24 ?>px;">
                                <span class="bom-toggle-spacer"></span>
                                <span class="pill pill-warn" title="BOM tree truncated at depth 50">⚠ tree truncated (depth&nbsp;&gt;&nbsp;50)</span>
                            </td>
                        </tr>
                        <?php
                        continue;
                    }
                    $item = $row['item'];
                    $hasKids = false;
                    // Quick peek: are there any children for this item?
                    if (isset($flat[$idx + 1]) && $flat[$idx + 1]['depth'] > $row['depth']) {
                        $hasKids = true;
                    }
                    // Row ids are keyed by the UNIQUE per-occurrence seq, not
                    // the BOM edge line_id — a shared sub-assembly reuses the
                    // same line_id under several parents, which would create
                    // duplicate data-row-id values and break collapse/expand.
                    $rowId = 'p' . (int)$p['id'] . '-s' . (int)$row['seq'];
                    $parentRowId = ($row['parent_seq'] !== null)
                        ? 'p' . (int)$p['id'] . '-s' . (int)$row['parent_seq']
                        : '';
                ?>
                <tr class="bom-grid-row<?= $row['depth'] === 0 ? ' bom-root-row' : '' ?><?= (int)$item['is_active'] === 0 ? ' bom-inactive-row' : '' ?>"
                    data-row-id="<?= h($rowId) ?>"
                    data-parent-row-id="<?= h($parentRowId) ?>"
                    data-depth="<?= (int)$row['depth'] ?>"
                    data-product-id="<?= (int)$p['id'] ?>">
                    <td class="bom-name-cell" style="padding-left: <?= 10 + $row['depth'] * 24 ?>px;">
                        <?php if ($hasKids): ?>
                            <button type="button" class="bom-toggle-btn" data-toggle-for="<?= h($rowId) ?>"><?= $row['depth'] === 0 ? '−' : '−' ?></button>
                        <?php else: ?>
                            <span class="bom-toggle-spacer"></span>
                        <?php endif; ?>
                        <a class="bom-product-link" href="<?= h(url('/inventory.php?action=bom_designer&id=' . (int)$item['id'])) ?>">
                            (<?= h($item['code']) ?>)-<?= h($item['short_description'] ?: $item['name']) ?>
                        </a>
                        <?php
                            // Pending-SO badge (open sales orders for this part).
                            $soE = $soPending[$item['code']] ?? null;
                            if ($soE && ($soE['so_count'] > 0 || $soE['qty_pending'] > 0)):
                                $soQ = rtrim(rtrim(number_format((float)$soE['qty_pending'], 3, '.', ''), '0'), '.');
                                $soN = (int)$soE['so_count'];
                                $soTitle = $soN . ' open sales order' . ($soN === 1 ? '' : 's')
                                         . ' · ' . $soQ . ' unit'
                                         . (abs((float)$soE['qty_pending'] - 1.0) < 0.0001 ? '' : 's') . ' pending';
                                ?>
                                <span class="bom-so-badge" title="<?= h($soTitle) ?>">(<?= (int)$soN ?> · <?= h($soQ) ?>)</span>
                                <?php
                            endif;
                        ?>
                        <?php if ((int)$item['is_active'] === 0): ?>
                            <span class="pill pill-warn" style="margin-left:6px;" title="Item is marked inactive">inactive</span>
                        <?php endif; ?>
                        <?php if ($row['depth'] === 0 && (int)$item['stock_on_hand']): ?>
                            <span class="muted" style="color: #b00; margin-left: 6px;">(<?= (int)$item['stock_on_hand'] ?>)</span>
                        <?php endif; ?>
                    </td>
                    <?php
                        // Dedicated category column — always aligned regardless of name length.
                        $catName = $item['category_name'] ?? '';
                    ?>
                    <td class="bom-cat-col">
                        <?php if ($catName !== ''): ?>
                            <span class="bom-cat-badge" title="<?= h($catName) ?>"><?= h($catName) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php
                        // Per-item numeric column values. All four come from
                        // the bulk-fetched aggregates assembled above.
                        $iidThis  = (int)$item['id'];
                        $rejQty    = isset($qtyRejByItem[$iidThis])    ? $qtyRejByItem[$iidThis]    : 0.0;
                        $reworkQty = isset($qtyReworkByItem[$iidThis]) ? $qtyReworkByItem[$iidThis] : 0.0;
                        $totalQty  = isset($qtyTotalByItem[$iidThis])  ? $qtyTotalByItem[$iidThis]  : 0.0;
                        $avbQty    = $totalQty - $rejQty - $reworkQty;
                        if ($avbQty < 0) $avbQty = 0.0;   // clamp; shouldn't go negative in normal data
                        $tbrQty    = isset($tbrByItem[$iidThis]) ? $tbrByItem[$iidThis] : 0.0;
                    ?>
                    <td class="r"><?= bom_fmt_qty($avbQty) ?></td>
                    <td class="r"><?= bom_fmt_qty($rejQty) ?></td>
                    <td class="r"><?= bom_fmt_qty($tbrQty) ?></td>
                    <td class="r"><?= bom_fmt_qty($reworkQty) ?></td>
                    <td>
                        <div class="bom-actions-menu">
                            <button type="button" class="btn btn-icon bom-actions-trigger"
                                    aria-haspopup="true" aria-expanded="false" title="Actions">⚙</button>
                            <div class="bom-actions-dropdown" hidden>
                                <a class="bom-actions-item" href="<?= h(url('/inventory.php?action=item_edit&id=' . (int)$item['id'])) ?>">
                                    <span class="bom-actions-icon">✎</span> Edit item
                                </a>
                                <a class="bom-actions-item" href="<?= h(url('/inventory.php?action=ledger&id=' . (int)$item['id'])) ?>">
                                    <span class="bom-actions-icon">📒</span> Ledger / stock history
                                </a>
                                <a class="bom-actions-item" href="<?= h(url('/inventory.php?action=move&item_id=' . (int)$item['id'])) ?>">
                                    <span class="bom-actions-icon">⇄</span> Move stock
                                </a>
                                <a class="bom-actions-item" href="<?= h(url('/inventory.php?action=bom_designer&id=' . (int)$item['id'])) ?>">
                                    <span class="bom-actions-icon">🛠</span> BOM designer
                                </a>
                                <a class="bom-actions-item" href="<?= h(url('/inventory.php?action=process&product_id=' . (int)$item['id'])) ?>">
                                    <span class="bom-actions-icon">⚙</span> Process
                                </a>
                                <?php if ($canManageBoms): ?>
                                <!-- Clone BOM: opens the prefix-rewrite preview flow.
                                     Mutating action so we use a POST form. The form is
                                     display:contents so it doesn't disturb layout; the
                                     <button> inside takes the .bom-actions-item shape
                                     identically to the sibling <a> items above/below. -->
                                <form method="post" action="<?= h(url('/inventory.php?action=bom_clone_preview')) ?>"
                                      style="display:contents;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <button type="submit" class="bom-actions-item"
                                            style="background:none; border:0; cursor:pointer; font:inherit; width:100%;">
                                        <span class="bom-actions-icon">⎘</span> Clone BOM
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a class="bom-actions-item notes-popup-btn" href="#"
                                   data-entity-type="inv_item" data-entity-id="<?= (int)$item['id'] ?>">
                                    <span class="bom-actions-icon">📝</span> Notes
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endforeach; endif; ?>
        </tbody>
        <tfoot class="bom-filter-foot">
            <tr class="bom-filter-row">
                <th><div class="dt-filter-pill"><span class="dt-filter-icon">🔍</span><input type="text" data-bgcol="name" placeholder="Product Name…"></div></th>
                <th class="bom-cat-col"></th>
                <th><div class="dt-filter-pill"><span class="dt-filter-icon">🔍</span><input type="text" data-bgcol="avb"  placeholder="Avb…"></div></th>
                <th><div class="dt-filter-pill"><span class="dt-filter-icon">🔍</span><input type="text" data-bgcol="rej"  placeholder="REJ…"></div></th>
                <th><div class="dt-filter-pill"><span class="dt-filter-icon">🔍</span><input type="text" data-bgcol="tbr"  placeholder="TBR…"></div></th>
                <th><div class="dt-filter-pill"><span class="dt-filter-icon">🔍</span><input type="text" data-bgcol="mb"   placeholder="Rework…"></div></th>
                <th></th>
            </tr>
        </tfoot>
    </table>
    </div><!-- /.bom-grid-scroll -->
    </div><!-- /.dt-wrap.bom-grid-wrap -->

    <script>
    /* BOM grid tree toggle + per-column contains filters.

       Default state: COLLAPSED — only root rows visible. The user expands
       individual rows by clicking +, or expands everything with the
       Expand All toolbar button.

       Re-entry: on first execution the document-level listeners are
       attached. On SPA re-visits the listeners persist (they're on
       `document`) so we only need to re-apply the default collapsed
       state to the freshly-rendered rows.
    */
    (function () {
        // Hoisted helpers — defined at the top so the guarded branch can
        // call applyDefault(). The previous version's bug was calling
        // applyDefault from outside its enclosing scope.
        function getBody() { return document.getElementById('bom-grid-body'); }

        function descendantRows(body, rowId) {
            // BFS with a `seen` set, so any future bug that produces a
            // self-parent or cycle in row IDs can't freeze the browser.
            var out = [];
            var queue = [rowId];
            var seen = {};
            seen[rowId] = true;
            while (queue.length) {
                var cur = queue.shift();
                body.querySelectorAll('tr[data-parent-row-id="' + cur + '"]').forEach(function (r) {
                    var childId = r.getAttribute('data-row-id');
                    if (!childId || seen[childId]) return;
                    seen[childId] = true;
                    out.push(r);
                    queue.push(childId);
                });
            }
            return out;
        }

        function applyDefault() {
            var body = getBody();
            if (!body) return;
            // Hide every row whose depth > 0 (only roots stay visible on
            // first paint).
            body.querySelectorAll('tr.bom-grid-row').forEach(function (r) {
                var d = parseInt(r.getAttribute('data-depth') || '0', 10);
                r.classList.toggle('bom-hidden', d > 0);
            });
            // Every toggle button starts as '+' since children are hidden.
            // We also stamp a data-state attribute so the click handler
            // doesn't depend on textContent comparison (Unicode-sensitive,
            // whitespace-sensitive, breaks if CSS adds pseudo-content).
            body.querySelectorAll('.bom-toggle-btn').forEach(function (b) {
                b.textContent = '+';
                b.setAttribute('data-state', 'closed');
            });
        }

        function setRowOpen(body, rowId, open) {
            var btn = body.querySelector('.bom-toggle-btn[data-toggle-for="' + rowId + '"]');
            if (btn) {
                btn.textContent = open ? '−' : '+';
                btn.setAttribute('data-state', open ? 'open' : 'closed');
            }
            if (open) {
                // Expand the ENTIRE subtree: reveal every descendant and
                // flip each of their toggle buttons to 'open' so the user
                // doesn't have to walk the tree click-by-click. Collapsing
                // (below) already collapses the whole subtree, so this
                // restores symmetry between the two directions.
                descendantRows(body, rowId).forEach(function (gr) {
                    gr.classList.remove('bom-hidden');
                    var grId  = gr.getAttribute('data-row-id');
                    var gbtn  = body.querySelector('.bom-toggle-btn[data-toggle-for="' + grId + '"]');
                    if (gbtn) {
                        gbtn.textContent = '−';
                        gbtn.setAttribute('data-state', 'open');
                    }
                });
                return;
            }
            // Closing: hide direct children, and recursively collapse any
            // already-open grandchildren so reopening starts from a known
            // clean state (entire subtree closed).
            body.querySelectorAll('tr[data-parent-row-id="' + rowId + '"]').forEach(function (r) {
                r.classList.add('bom-hidden');
                descendantRows(body, r.getAttribute('data-row-id')).forEach(function (gr) {
                    gr.classList.add('bom-hidden');
                    var gbtn = body.querySelector('.bom-toggle-btn[data-toggle-for="' + gr.getAttribute('data-row-id') + '"]');
                    if (gbtn) {
                        gbtn.textContent = '+';
                        gbtn.setAttribute('data-state', 'closed');
                    }
                });
            });
        }

        // Expose for SPA re-entry calls
        window.__BomGrid = { applyDefault: applyDefault, setRowOpen: setRowOpen };

        // ----------------------------------------------------------------
        // Column resize. Each <th data-bgrid-col> gets a thin handle on
        // its right edge; dragging the handle resizes that column. Widths
        // are persisted per-column in localStorage so they survive
        // reloads and SPA navigation. applyStoredWidths re-paints the
        // remembered widths on fresh DOM after each SPA swap.
        // ----------------------------------------------------------------
        function applyStoredWidths() {
            var grid = document.getElementById('bomGrid');
            if (!grid) return;
            var hasSaved = false;
            grid.querySelectorAll('th[data-bgrid-col]').forEach(function (th) {
                var key = 'magdyn.bomGrid.colWidth.' + th.getAttribute('data-bgrid-col');
                try {
                    var w = parseInt(localStorage.getItem(key) || '0', 10);
                    if (w > 30) { th.style.width = w + 'px'; hasSaved = true; }
                } catch (_) {}
            });
            if (hasSaved) {
                grid.classList.add('bgrid-fixed-layout');
                // Pin the table width to the sum of column widths so
                // saved widths are honored without squishing. See the
                // resize-handler comment for why this is needed.
                requestAnimationFrame(function () {
                    var totalW = 0;
                    grid.querySelectorAll('thead th').forEach(function (th) {
                        totalW += th.getBoundingClientRect().width;
                    });
                    if (totalW > 0) {
                        grid.style.width = Math.round(totalW) + 'px';
                    }
                });
            }
        }
        function bindResizeHandles() {
            var grid = document.getElementById('bomGrid');
            if (!grid) return;
            grid.querySelectorAll('.bgrid-resize-handle').forEach(function (handle) {
                if (handle.dataset.bgridBound) return;
                handle.dataset.bgridBound = '1';
                handle.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    var th = handle.parentElement;
                    if (!th) return;
                    var startX = e.clientX;
                    var startW = th.getBoundingClientRect().width;
                    var key = 'magdyn.bomGrid.colWidth.' + th.getAttribute('data-bgrid-col');
                    document.body.classList.add('bgrid-resizing');
                    // Snapshot every OTHER column's width before we flip
                    // to fixed-layout. Without this, auto-layout (default)
                    // lets the browser redistribute widths across columns
                    // when one grows — making it feel like resizing one
                    // column compresses the others.
                    if (!grid.classList.contains('bgrid-fixed-layout')) {
                        grid.querySelectorAll('thead th').forEach(function (otherTh) {
                            if (!otherTh.style.width) {
                                otherTh.style.width = otherTh.getBoundingClientRect().width + 'px';
                            }
                        });
                        grid.classList.add('bgrid-fixed-layout');
                        startW = th.getBoundingClientRect().width;
                    }
                    // Pin the table's overall width to the SUM of column
                    // widths and adjust it inline as the user drags. The
                    // explicit table-width pin is what actually prevents
                    // sibling shrink: fixed-layout alone honors width:100%
                    // from base CSS and would still redistribute. The
                    // .bom-grid-scroll wrapper gives horizontal scroll
                    // for the overflow.
                    var initialTableW = 0;
                    grid.querySelectorAll('thead th').forEach(function (anyTh) {
                        initialTableW += anyTh.getBoundingClientRect().width;
                    });
                    grid.style.width = Math.round(initialTableW) + 'px';

                    function onMove(ev) {
                        var w = Math.max(40, Math.round(startW + (ev.clientX - startX)));
                        th.style.width = w + 'px';
                        var newTableW = Math.round(initialTableW + (w - startW));
                        if (newTableW > 0) {
                            grid.style.width = newTableW + 'px';
                        }
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup',   onUp);
                        document.body.classList.remove('bgrid-resizing');
                        // Persist final width
                        try {
                            var finalW = parseInt(th.style.width, 10) || 0;
                            if (finalW > 30) localStorage.setItem(key, String(finalW));
                        } catch (_) {}
                    }
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup',   onUp);
                });
                // Double-click to reset to auto
                handle.addEventListener('dblclick', function (e) {
                    e.preventDefault();
                    var th = handle.parentElement;
                    if (!th) return;
                    var key = 'magdyn.bomGrid.colWidth.' + th.getAttribute('data-bgrid-col');
                    th.style.width = '';
                    try { localStorage.removeItem(key); } catch (_) {}
                });
            });
        }
        // Re-apply on every (first paint AND SPA re-entry) since the
        // <th> elements come from a freshly-swapped main and lose any
        // earlier style/listener attachments.
        applyStoredWidths();
        bindResizeHandles();

        if (window.__bomGridBound) {
            // Listeners on document already attached from a prior page
            // load; just re-apply the initial collapsed state to the
            // freshly-rendered rows.
            applyDefault();
            return;
        }
        window.__bomGridBound = true;

        // Toggle button clicks + toolbar (delegated on document)
        document.addEventListener('click', function (e) {
            var t = e.target;
            var btn = t.closest && t.closest('.bom-toggle-btn');
            if (btn) {
                var body = getBody();
                if (!body || !body.contains(btn)) return;
                var rowId = btn.getAttribute('data-toggle-for');
                // Use the data-state attribute, NOT textContent. textContent
                // comparisons against Unicode minus (U+2212) were unreliable
                // when the browser sometimes normalised whitespace inside
                // the button.
                var isOpen = btn.getAttribute('data-state') === 'open';
                setRowOpen(body, rowId, !isOpen);
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            if (t.id === 'bom-grid-toggle-all') {
                var body = getBody();
                if (!body) return;
                var nowCollapsed = t.getAttribute('data-state') === 'collapsed';
                if (nowCollapsed) {
                    // Currently collapsed → expand everything
                    body.querySelectorAll('tr.bom-grid-row').forEach(function (r) { r.classList.remove('bom-hidden'); });
                    body.querySelectorAll('.bom-toggle-btn').forEach(function (b) {
                        b.textContent = '−';
                        b.setAttribute('data-state', 'open');
                    });
                    t.setAttribute('data-state', 'expanded');
                    t.textContent = '⊟ Collapse all';
                    t.setAttribute('title', 'Collapse every row to top level');
                } else {
                    // Currently expanded → collapse to roots
                    applyDefault();
                    t.setAttribute('data-state', 'collapsed');
                    t.textContent = '⊞ Expand all';
                    t.setAttribute('title', 'Expand every row');
                }
            }
        });

        // Per-column "contains" filter inputs — combine across columns
        // with AND semantics. On any keystroke we re-read every filter
        // input and only keep rows whose every column matches its
        // respective query (substring, case-insensitive).
        //
        // Tree-collapse interaction: a matched row inside a collapsed
        // parent would still carry .bom-hidden (display:none from the
        // expand/collapse system). To make matches visible, we also
        // walk up each match's data-parent-row-id chain and mark every
        // ancestor with .bom-filter-shown — its CSS rule overrides the
        // .bom-hidden display:none. When all filters are empty, we drop
        // both classes and rows return to whatever collapse state they
        // were in.
        document.addEventListener('input', function (e) {
            var inp = e.target;
            if (!inp.matches || !inp.matches('.bom-grid .bom-filter-row input')) return;
            applyAllFilters();
        });

        function applyAllFilters() {
            var body = getBody();
            if (!body) return;
            var rows = body.querySelectorAll('tr.bom-grid-row');

            // Collect every active filter (column -> query string).
            // We look up inputs by data-bgcol, which is stable whether
            // the filter row lives in <thead> or <tfoot>.
            var colIdx = { name:0, avb:2, rej:3, tbr:4, mb:5 }; // col 1 = category (no filter)
            var queries = [];
            Object.keys(colIdx).forEach(function (col) {
                var el = document.querySelector('.bom-filter-row input[data-bgcol="' + col + '"]');
                if (!el) return;
                var v = el.value.trim().toLowerCase();
                if (v) queries.push({ idx: colIdx[col], q: v });
            });

            if (!queries.length) {
                // No filters active: drop both classes everywhere.
                rows.forEach(function (r) {
                    r.classList.remove('bom-filter-out');
                    r.classList.remove('bom-filter-shown');
                });
                return;
            }

            var rowsById = {};
            rows.forEach(function (r) { rowsById[r.getAttribute('data-row-id')] = r; });

            // Pass 1: find rows where ALL active filters match.
            var keepIds = {};
            rows.forEach(function (r) {
                for (var i = 0; i < queries.length; i++) {
                    var cell = r.cells[queries[i].idx];
                    var text = (cell && cell.textContent || '').trim().toLowerCase();
                    if (text.indexOf(queries[i].q) === -1) return;
                }
                keepIds[r.getAttribute('data-row-id')] = true;
            });

            // Pass 2: include each match's ancestor chain so the path is
            // visible (a leaf deep in a tree makes no sense alone).
            Object.keys(keepIds).forEach(function (id) {
                var cur = rowsById[id];
                while (cur) {
                    var pid = cur.getAttribute('data-parent-row-id');
                    if (!pid) break;
                    var parent = rowsById[pid];
                    if (!parent) break;
                    keepIds[pid] = true;
                    cur = parent;
                }
            });

            // Pass 3: apply the classes.
            rows.forEach(function (r) {
                var id = r.getAttribute('data-row-id');
                if (keepIds[id]) {
                    r.classList.remove('bom-filter-out');
                    r.classList.add('bom-filter-shown');
                } else {
                    r.classList.add('bom-filter-out');
                    r.classList.remove('bom-filter-shown');
                }
            });
        }

        // ---- Per-row Actions dropdown -------------------------------
        // Clicking the gear opens a dropdown next to it. The dropdown is
        // positioned with `position: fixed` and coordinates computed from
        // the trigger's getBoundingClientRect(), so the .bom-grid-scroll
        // container's overflow:auto won't clip it.
        function closeAllDropdowns() {
            document.querySelectorAll('.bom-actions-dropdown[data-open="1"]').forEach(function (dd) {
                dd.hidden = true;
                dd.style.top = '';
                dd.style.left = '';
                dd.style.position = '';
                dd.removeAttribute('data-open');
                var trigger = dd.parentElement && dd.parentElement.querySelector('.bom-actions-trigger');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        }
        function openDropdown(trigger, dd) {
            // First reveal so we can measure its size
            dd.hidden = false;
            dd.style.position = 'fixed';
            // Default placement: directly below the trigger, right edges aligned.
            var rect = trigger.getBoundingClientRect();
            // Width of the dropdown after it's revealed (min-width fallback).
            var ddRect = dd.getBoundingClientRect();
            var left = rect.right - ddRect.width;
            var top  = rect.bottom + 2;
            // Keep within viewport horizontally
            if (left < 4) left = 4;
            if (left + ddRect.width > window.innerWidth - 4) {
                left = window.innerWidth - ddRect.width - 4;
            }
            // Flip above if not enough room below
            if (top + ddRect.height > window.innerHeight - 4) {
                top = rect.top - ddRect.height - 2;
                if (top < 4) top = 4;
            }
            dd.style.left = left + 'px';
            dd.style.top  = top + 'px';
            dd.setAttribute('data-open', '1');
            trigger.setAttribute('aria-expanded', 'true');
        }
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest && e.target.closest('.bom-actions-trigger');
            if (trigger) {
                e.preventDefault();
                var dd = trigger.parentElement && trigger.parentElement.querySelector('.bom-actions-dropdown');
                if (!dd) return;
                var wasOpen = dd.getAttribute('data-open') === '1';
                closeAllDropdowns();
                if (!wasOpen) openDropdown(trigger, dd);
                return;
            }
            // Click inside an open dropdown: let the navigation happen
            // but close the menu as a courtesy.
            if (e.target.closest && e.target.closest('.bom-actions-dropdown')) {
                closeAllDropdowns();
                return;
            }
            // Click anywhere else closes the open dropdown.
            closeAllDropdowns();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAllDropdowns();
        });
        // Scroll inside the grid OR window resize should close the open
        // dropdown, since its fixed coordinates are no longer accurate.
        var bomScroll = document.querySelector('.bom-grid-scroll');
        if (bomScroll) bomScroll.addEventListener('scroll', closeAllDropdowns);
        window.addEventListener('resize', closeAllDropdowns);

        applyDefault();
    })();
    </script>
    <style>
    tr.bom-filter-out { display: none; }
    /* When filtering, matched rows and their ancestors override the
       collapsed-tree display:none from .bom-hidden. */
    tr.bom-grid-row.bom-filter-shown { display: table-row !important; }
    </style>
    <?php notes_popup_assets(); ?>
    <?php if ($canManageBoms):
        // List of items available as a BOM root. We expose every active
        // item — not just finished-goods — so the user can build a BOM
        // on any part. The combobox handles fuzzy search across the
        // visible options.
        $pickerItems = db_all(
            "SELECT id, code,
                    COALESCE(NULLIF(short_description, ''), name) AS label
               FROM inv_items
              WHERE is_active = 1
              ORDER BY code"
        );
    ?>
    <div id="bom-picker-modal" class="att-preview-modal" hidden>
        <div class="att-preview-backdrop" data-bom-picker-close></div>
        <div class="att-preview-dialog" role="dialog" aria-label="Pick an item to build a BOM for"
             style="max-width: 540px; margin: auto; height: auto;">
            <div class="att-preview-head">
                <span class="att-preview-name">New BOM — pick an item</span>
                <button type="button" class="btn btn-icon att-preview-close-btn"
                        data-bom-picker-close title="Close">✕</button>
            </div>
            <form method="get" action="<?= h(url('/inventory.php')) ?>" style="padding: 18px;">
                <input type="hidden" name="action" value="bom_designer">
                <div class="field">
                    <label for="bom-picker-item">Item *</label>
                    <select id="bom-picker-item" name="id" required autofocus>
                        <option value="">— Select an item —</option>
                        <?php foreach ($pickerItems as $pi): ?>
                            <option value="<?= (int)$pi['id'] ?>">
                                <?= h($pi['code']) ?> — <?= h($pi['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="muted small" style="margin-top: 10px;">
                    Any active item can be the root of a BOM — not just finished goods.
                    Pick the item, then drag children into its tree in the designer.
                </div>
                <div style="margin-top: 16px; display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-bom-picker-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Open designer →</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var modal = document.getElementById('bom-picker-modal');
        if (!modal) return;
        document.querySelectorAll('[data-open-bom-picker="1"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                modal.hidden = false;
                document.body.classList.add('att-preview-modal-open');
                // Re-run combobox init so the freshly-revealed <select> gets
                // wrapped with the searchable combobox UI.
                if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                    window.MagDynCombobox.initAll();
                }
                // Focus into the picker (the autofocus attr only fires on
                // first render; modal toggle requires this re-focus).
                setTimeout(function () {
                    var sel = modal.querySelector('select');
                    if (sel) sel.focus();
                }, 30);
            });
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-bom-picker-close]')) {
                if (modal.contains(e.target)) {
                    modal.hidden = true;
                    document.body.classList.remove('att-preview-modal-open');
                }
            }
        });
    })();
    </script>
    <?php endif; ?>
    <?php if ($canCreateBoms || $canManageBoms):
        import_modal_html(
            'bom-import-modal',
            'Import BOM (hierarchical CSV)',
            url('/inventory.php?action=bom_import_preview'),
            'Imports the depth-encoded CSV format: '
              . 'first column is the tree cell (<code>Name (code)~qty~code</code>) with '
              . 'leading <code>|---</code> characters defining the depth. '
              . 'Per-edge quantities come from each row\'s <code>I_Tree Child</code> column, '
              . 'formatted as <code>&lt;child_code&gt;-&lt;qty&gt;;</code>. '
              . 'Missing items are auto-created from row data (long_description, dwg_no, rev_no, part_no, '
              . 'material_spec, min_stock_level). Category derives from <code>category_id</code>: '
              . '1 → finished good, 2 → sub assembly, 3 → raw material. '
              . 'Division defaults to <strong>mech</strong>, UoM to <strong>nos</strong>. '
              . 'Cycle prevention runs across the union of existing BOM + this CSV.'
        );
    endif; ?>
    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}

// ============================================================
// BOM Designer — drag-and-drop editor
// ============================================================
if ($action === 'bom_designer') {
    if (!$canManageBoms) require_permission('inventory_view_boms', 'manage');
    $id = (int)input('id', 0);
    $item = db_one('SELECT * FROM inv_items WHERE id = ?', [$id]);
    if (!$item) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }

    // Catalogue for the side panel: all active items except the root itself
    // and its transitive ancestors (cycle prevention).
    $forbidden = inv_ancestors_of($id);
    $forbiddenSql = '(' . implode(',', array_map('intval', $forbidden)) . ')';
    $catalogue = db_all(
        "SELECT id, code, COALESCE(NULLIF(short_description, ''), name) AS name
           FROM inv_items
          WHERE is_active = 1 AND id NOT IN $forbiddenSql
          ORDER BY code"
    );

    $page_title  = 'BOM Designer: ' . ($item['short_description'] ?: $item['name']);
    $page_module = 'inventory';
    $focus_id    = '';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'BOM Designer',
            'subtitle'    => ($item['short_description'] ?: $item['name']) . ' · ' . $item['code'],
            'back_href'   => url('/inventory.php?action=bom_grid'),
            'back_label'  => 'BOM tree',
            'actions_html' =>
                '<a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=bom_view&id=' . $id)) . '">View tree</a>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/inventory.php?action=bom_edit&id=' . $id)) . '">Tabular edit</a>',
        ]) ?>
        <div class="form-page-body">
            <p class="muted" style="margin-top:0;">Drag items from the side panel onto the tree to add them as children of the root.
                Within the tree, drag a row by its handle to reorder it among its siblings, or drop it into another item's children to reparent.</p>

            <div class="bd-bar" style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                <button type="button" class="btn btn-sm btn-ghost" id="bd-expand-all" title="Expand all assemblies">▾ Expand all</button>
                <button type="button" class="btn btn-sm btn-ghost" id="bd-collapse-all" title="Collapse all assemblies">▸ Collapse all</button>
                <span class="muted small" style="margin-left:auto;">Click the ▾ on an assembly to collapse just that branch.</span>
            </div>

            <div id="bom-designer" class="bd-wrap"
                 data-root-item-id="<?= (int)$id ?>"
                 data-endpoint="<?= h(url('/inventory.php?action=bom_designer_api&id=' . (int)$id)) ?>"
                 data-csrf="<?= h(csrf_token()) ?>"
                 data-csrf-field="<?= h($GLOBALS['APP']['csrf_field'] ?? '_csrf') ?>">
                <div class="bd-palette">
                    <h3>Item catalogue</h3>
                    <input type="text" class="bd-palette-search" placeholder="Search items…">
                    <?php foreach ($catalogue as $c): ?>
                        <div class="bd-palette-item" data-item-id="<?= (int)$c['id'] ?>">
                            <span class="mono"><?= h($c['code']) ?></span>
                            <?= h($c['name']) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$catalogue): ?>
                        <p class="muted small">No other items in the catalogue. Create some first via Inventory.</p>
                    <?php endif; ?>
                </div>
                <div class="bd-tree-holder">
                    <?= render_bom_designer_tree($id) ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    /* BOM designer tree — toggle + expand/collapse-all wiring.
       Mirrors modules.php's tree behavior: clicking a row's ▾ button
       flips the parent <li>'s aria-expanded; CSS hides .bd-children
       when collapsed. We use event delegation so this works for
       both server-rendered rows and any new rows that SortableJS
       adds via drag-drop without us needing to re-bind. */
    (function () {
        var wrap = document.getElementById('bom-designer');
        if (!wrap) return;
        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.bd-toggle');
            if (!btn || btn.classList.contains('bd-leaf')) return;
            var li = btn.closest('.bd-node');
            if (!li) return;
            var expanded = li.getAttribute('aria-expanded') !== 'false';
            li.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        });
        var setAll = function (state) {
            wrap.querySelectorAll('.bd-node[aria-expanded]').forEach(function (li) {
                /* Only flip nodes that actually have children — leaves
                   don't have a meaningful expanded state but the
                   attribute may be set anyway from the renderer. */
                if (li.querySelector(':scope > .bd-children')) {
                    li.setAttribute('aria-expanded', state ? 'true' : 'false');
                }
            });
        };
        var expandBtn = document.getElementById('bd-expand-all');
        var collapseBtn = document.getElementById('bd-collapse-all');
        if (expandBtn) expandBtn.addEventListener('click', function () { setAll(true); });
        if (collapseBtn) collapseBtn.addEventListener('click', function () { setAll(false); });
    })();
    </script>

    <?php require dirname(__DIR__, 2) . '/includes/footer.php'; exit;
}
// ============================================================
// BOM Designer API — JSON endpoint for drag-drop operations
// ============================================================
if ($action === 'bom_designer_api') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canManageBoms) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No permission']);
        exit;
    }
    // Manual CSRF check so a failure returns JSON instead of an HTML 419
    // page (which would surface as "Network error" in the JS).
    $tokenField = $GLOBALS['APP']['csrf_field'] ?? '_csrf';
    $given = isset($_POST[$tokenField]) ? $_POST[$tokenField] : '';
    if (!$given || !hash_equals(csrf_token(), $given)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Session expired or invalid CSRF token. Reload the page.']);
        exit;
    }
    $rootId = (int)input('id', 0);
    $op     = (string)input('op', '');

    try {
        if ($op === 'add_line') {
            $parentId = (int)input('parent_item_id', 0);
            $childId  = (int)input('child_item_id', 0);
            $relTo    = (int)input('relative_to_line_id', 0);
            $pos      = (string)input('position', 'last');
            $qty      = 1; // default; user can edit afterwards
            if (!$parentId || !$childId) throw new Exception('Missing parent or child');
            if ($parentId === $childId)  throw new Exception('Item cannot be a child of itself');
            $anc = inv_ancestors_of($parentId);
            if (in_array($childId, $anc, true)) throw new Exception('Would create a cycle');
            // Compute sort_order based on position
            $sort = inv_compute_sort_order($parentId, $relTo, $pos);
            db_exec(
                'INSERT INTO inv_bom_lines (parent_item_id, child_item_id, qty, sort_order) VALUES (?, ?, ?, ?)',
                [$parentId, $childId, $qty, $sort]
            );
        } elseif ($op === 'move_line') {
            $lineId    = (int)input('line_id', 0);
            $newParent = (int)input('new_parent_item_id', 0);
            $relTo     = (int)input('relative_to_line_id', 0);
            $pos       = (string)input('position', 'last');
            $line = db_one('SELECT * FROM inv_bom_lines WHERE id = ?', [$lineId]);
            if (!$line) throw new Exception('Line not found');
            if ($newParent === (int)$line['child_item_id']) throw new Exception('Cannot parent an item under itself');
            // Cycle: new_parent must not be a descendant of child_item_id
            // (i.e. the child item must not be among new_parent's ancestors)
            $anc = inv_ancestors_of($newParent);
            if (in_array((int)$line['child_item_id'], $anc, true)) throw new Exception('Would create a cycle');
            $sort = inv_compute_sort_order($newParent, $relTo, $pos);
            db_exec('UPDATE inv_bom_lines SET parent_item_id = ?, sort_order = ? WHERE id = ?',
                [$newParent, $sort, $lineId]);
        } elseif ($op === 'update_line') {
            $lineId = (int)input('line_id', 0);
            $qty    = (float)input('qty', 0);
            if ($qty <= 0) throw new Exception('Quantity must be greater than zero');
            db_exec('UPDATE inv_bom_lines SET qty = ? WHERE id = ?', [$qty, $lineId]);
        } elseif ($op === 'delete_line') {
            $lineId = (int)input('line_id', 0);
            db_exec('DELETE FROM inv_bom_lines WHERE id = ?', [$lineId]);
        } else {
            throw new Exception('Unknown op');
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    // Re-render the tree HTML and return it
    echo json_encode(['ok' => true, 'tree_html' => render_bom_designer_tree($rootId)]);
    exit;
}

// ============================================================
// Designer helpers: sort order + tree HTML renderers
// ============================================================
// Helper used by the designer + API to compute a sort_order for the
// new/moved line based on a "position" hint.
function inv_compute_sort_order($parentItemId, $relativeToLineId, $position) {
    if ($position === 'last' || !$relativeToLineId) {
        $max = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) FROM inv_bom_lines WHERE parent_item_id = ?', [$parentItemId], 0);
        return $max + 10;
    }
    $relLine = db_one('SELECT sort_order, parent_item_id FROM inv_bom_lines WHERE id = ?', [$relativeToLineId]);
    if (!$relLine || (int)$relLine['parent_item_id'] !== (int)$parentItemId) {
        // Fallback: append at end
        $max = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) FROM inv_bom_lines WHERE parent_item_id = ?', [$parentItemId], 0);
        return $max + 10;
    }
    $relSort = (int)$relLine['sort_order'];
    if ($position === 'before') {
        // Find the sort_order of the previous sibling (smaller than relSort)
        $prev = db_val(
            'SELECT MAX(sort_order) FROM inv_bom_lines WHERE parent_item_id = ? AND sort_order < ?',
            [$parentItemId, $relSort], null
        );
        return $prev !== null ? (int)(($prev + $relSort) / 2) : $relSort - 5;
    }
    // after
    $next = db_val(
        'SELECT MIN(sort_order) FROM inv_bom_lines WHERE parent_item_id = ? AND sort_order > ?',
        [$parentItemId, $relSort], null
    );
    return $next !== null ? (int)(($relSort + $next) / 2) : $relSort + 10;
}

// Helper: render the designer tree HTML for a root item.
// One <ul> of <li class="bd-node"> rows; each row has the metadata
// attributes the JS needs (line-id, item-id, parent-item-id).
//
// Cycle / depth protection: render_bom_designer_node tracks a $visited
// set passed by reference. If a child item appears twice in the same
// branch (cycle from a manual DB edit or a pre-cycle-check import), the
// recursion stops and renders a "cycle detected" placeholder instead.
// A hard depth cap (50 levels, inlined as a literal in
// render_bom_designer_node) is a second line of defence: shared-hosting workers kill long-running
// requests with a 503, and a deep BOM tree with no cycles can still
// take long enough to trip that. The cap is generous; anything
// legitimately hitting it is a sign the BOM model is being misused.

function render_bom_designer_tree($rootId) {
    $rootId = (int)$rootId;
    $root = db_one(
        'SELECT i.*, u.label AS uom_label
           FROM inv_items i
           LEFT JOIN inv_uom u ON u.id = i.uom_id
          WHERE i.id = ?',
        [$rootId]
    );
    if (!$root) return '<p class="empty">Root item not found.</p>';

    ob_start();
    echo '<ul class="bd-tree">';
    $visited = [$rootId => true];
    render_bom_designer_node($root, 0, null, 1, true, $visited);
    echo '</ul>';
    return ob_get_clean();
}

function render_bom_designer_node($item, $depth, $parentItemId, $qty, $isRoot = false, &$visited = []) {
    // Hard depth cap: anything past 50 levels is almost certainly a
    // pathological BOM (most real assemblies fit in 5-10 levels).
    // Literal rather than a constant because constants defined in a
    // require'd partial proved unreliable on this host under repeated
    // testing — a literal can't be undefined.
    if ($depth > 50) {
        echo '<li class="bd-node bd-error"><div class="bd-row">'
           . '<span class="bd-icon">⚠</span>'
           . '<div class="bd-label"><em>(max depth 50 reached — tree truncated to prevent runaway)</em></div>'
           . '</div></li>';
        return;
    }
    $kids = db_all(
        'SELECT bl.id AS line_id, bl.qty AS line_qty, bl.child_item_id
           FROM inv_bom_lines bl
          WHERE bl.parent_item_id = ?
          ORDER BY bl.sort_order, bl.id',
        [(int)$item['id']]
    );

    $cls = 'bd-node' . ($isRoot ? ' bd-root' : '');
    // Beyond depth 7, switch to a tighter indent so a 15-level BOM
    // doesn't run out of horizontal real estate. CSS handles the rest
    // via .bd-node.bd-depth-deep > .bd-children.
    if ($depth >= 8) $cls .= ' bd-depth-deep';
    // is-assembly = node has children (a sub-assembly). Used by CSS
    // for the subtle blue tint that mirrors modules-tree's mod-group
    // styling — visually distinguishes assemblies from leaf parts.
    if (!empty($kids)) $cls .= ' bd-assembly';
    ?>
    <li class="<?= $cls ?>"
        <?= !$isRoot ? 'data-line-id="' . (int)($item['_line_id'] ?? 0) . '"' : '' ?>
        data-item-id="<?= (int)$item['id'] ?>"
        data-parent-item-id="<?= (int)$parentItemId ?>"
        aria-expanded="<?= !empty($kids) ? 'true' : 'false' ?>">
        <div class="bd-row">
            <?php /* Toggle button to collapse/expand children, mirroring
                    modules.php's .bom-toggle pattern. Leaf nodes show a
                    static dot for visual alignment. JS in this file
                    handles the click to toggle the children's display. */ ?>
            <?php if (!empty($kids)): ?>
                <button type="button" class="bd-toggle" aria-label="Toggle children" tabindex="-1">▾</button>
            <?php else: ?>
                <span class="bd-toggle bd-leaf" aria-hidden="true">·</span>
            <?php endif; ?>
            <span class="bd-icon"><?= $kids ? '📁' : '🧩' ?></span>
            <div class="bd-label">
                <strong class="mono"><?= h($item['code']) ?></strong>
                <?= h($item['short_description'] ?: $item['name']) ?>
                <?php /* Pill badge — distinguishes assemblies from leaf parts,
                        analogous to the "group" pill in modules-tree. Helps
                        the user spot which rows have descendants without
                        having to scan toggles. */ ?>
                <?php if (!empty($kids)): ?>
                    <span class="pill pill-info bd-pill" title="<?= count($kids) ?> child line<?= count($kids) === 1 ? '' : 's' ?>">
                        assembly · <?= count($kids) ?>
                    </span>
                <?php elseif (!$isRoot): ?>
                    <span class="pill pill-neutral bd-pill">leaf</span>
                <?php endif; ?>
            </div>
            <?php if (!$isRoot): ?>
                <input type="number" class="bd-qty" step="0.001" min="0.001"
                       value="<?= h(rtrim(rtrim(number_format($qty, 3), '0'), '.')) ?>"
                       data-line-id="<?= (int)($item['_line_id'] ?? 0) ?>"
                       title="Qty per parent"
                       draggable="false">
                <span class="muted small bd-uom"><?= h($item['uom_label'] ?? '') ?></span>
                <button type="button" class="bd-delete"
                        data-line-id="<?= (int)($item['_line_id'] ?? 0) ?>"
                        title="Remove" draggable="false">✕</button>
            <?php else: ?>
                <span class="muted small bd-uom">(root)</span>
                <span></span>
                <span></span>
            <?php endif; ?>
        </div>
        <?php if ($kids): ?>
            <ul class="bd-children">
                <?php foreach ($kids as $k):
                    $childItemId = (int)$k['child_item_id'];
                    // Cycle protection: if this child has already been
                    // rendered as an ancestor in the current branch,
                    // render a placeholder and STOP. Anything else would
                    // recurse forever, exhaust memory or the PHP execution
                    // time limit, and shared-hosting workers would return
                    // 503 to the browser instead of a PHP error page.
                    //
                    // $visited is keyed by item id and holds ONLY the
                    // current ancestor chain (each id is unset after its
                    // subtree finishes), so this fires only for a genuine
                    // loop on this path — the same sub-assembly appearing
                    // in two *sibling* branches is fine and renders fully
                    // in both. We surface the actual ancestor path so the
                    // user can find and break the loop in the data.
                    if (isset($visited[$childItemId])) {
                        $pathCodes = array_map(function ($iid) {
                            $r = db_one('SELECT code FROM inv_items WHERE id = ?', [(int)$iid]);
                            return $r ? $r['code'] : ('#' . (int)$iid);
                        }, array_keys($visited));
                        $loopChild = db_one('SELECT code FROM inv_items WHERE id = ?', [$childItemId]);
                        $loopCode  = $loopChild ? $loopChild['code'] : ('#' . $childItemId);
                        echo '<li class="bd-node bd-error">'
                           . '<div class="bd-row">'
                           . '<span class="bd-icon">⚠</span>'
                           . '<div class="bd-label">'
                           . '<strong>Cycle detected</strong> — item <code>' . h($loopCode) . '</code>'
                           . ' (id ' . (int)$childItemId . ') is already an ancestor on this branch: '
                           . '<span class="muted small">' . h(implode(' › ', $pathCodes)) . ' › ' . h($loopCode) . '</span>'
                           . '. Remove the looping BOM line to fix.'
                           . '</div></div></li>';
                        continue;
                    }
                    $child = db_one(
                        'SELECT i.*, u.label AS uom_label
                           FROM inv_items i LEFT JOIN inv_uom u ON u.id = i.uom_id
                          WHERE i.id = ?',
                        [$childItemId]
                    );
                    if (!$child) continue;
                    $child['_line_id'] = (int)$k['line_id'];
                    // Track this child in the current branch; un-set after
                    // recursing so siblings can still appear (depth-first
                    // cycle detection, not subtree-wide deduplication).
                    $visited[$childItemId] = true;
                    render_bom_designer_node($child, $depth + 1, (int)$item['id'], (float)$k['line_qty'], false, $visited);
                    unset($visited[$childItemId]);
                endforeach; ?>
            </ul>
        <?php endif; ?>
    </li>
    <?php
}

