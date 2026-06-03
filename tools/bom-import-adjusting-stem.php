<?php
/**
 * MagDyn — One-shot BOM importer for the Adjusting Stem Mechanism
 * Created: 2026-05-22 IST
 *
 * Parses the depth-encoded BOM CSV (BOM_Import.csv) and creates:
 *   - 22 inv_items (synthesized codes BOM-183, BOM-437, etc.)
 *   - 21 inv_bom_lines edges between them
 *
 * Idempotent: items keyed on `code`, BOM edges on (parent_id, child_id,
 * ref_designator). Re-running this page after a successful import is a
 * no-op. Edges are not duplicated; items are not double-created. If an
 * item with the same code already exists, the existing row is kept and
 * the BOM edge is built against it.
 *
 * The 22 rows are baked into this file (BOM_DATA below) so there is no
 * file-upload step. To re-use this for a different BOM, edit BOM_DATA.
 *
 * Permissions: requires inventory_view_items.create AND inventory_view_boms.create.
 *
 * Drop in /tools/, hit /tools/bom-import-adjusting-stem.php, preview the
 * proposed actions, click Commit.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/_codes.php';
require_once dirname(__DIR__) . '/includes/_billing_products.php';
require_login();

// -- Permissions ----------------------------------------------------------
if (!permission_check('inventory_view_items', 'create')
 && !permission_check('inventory_view_items', 'manage')) {
    flash_set('error', 'You need inventory_view_items.create to use this tool.');
    redirect(url('/inventory.php?action=items'));
}
if (!permission_check('inventory_view_boms', 'create')
 && !permission_check('inventory_view_boms', 'manage')) {
    flash_set('error', 'You need inventory_view_boms.create to use this tool.');
    redirect(url('/inventory.php?action=bom_grid'));
}

// =============================================================
// SOURCE DATA — verbatim rows from BOM_Import.csv
// Each entry: [legacy_id, depth, name, long_description, dwg_no, rev_no, part_no, category_code]
//
// `qty_in_parent` is read from the parent's I_Tree Child column; we
// hard-code the special cases (root → 196/198 are qty 2; 192 → 194 is
// qty 0.005). Everything else defaults to qty 1.
// =============================================================
$BOM_DATA = [
    // id, depth, name,                                       long_description,                                                                                                                                          dwg_no,        rev_no, part_no,    category
    [183, 0, 'Adjusting Stem - Mechanism',                    'Adjusting Stem - Mechanism Complete Assembly',                                                                                                            '9838000',     '2',    '9838000',  'finshd'],
    [437, 1, 'Stem Chromated',                                'Stem Chromated - Internal process',                                                                                                                       '3551-000',    '14',   '',         'subasm'],
    [754, 2, 'Adj Stem Machined (Insp OP-1)',                 'Adj Stem Machined (Inspection OP-1)',                                                                                                                     '',            '',     '',         'subasm'],
    [184, 3, 'Adj Stem Machined (File OP-1)',                 'Stem Machined - All machining Operations completed',                                                                                                      '355100',      '14',   '',         'subasm'],
    [506, 4, 'Stem Casting (Drill OP-1)',                     'Stem Casting Drilling OP-1, Depth maintain inside stem',                                                                                                  '3551-000',    '14',   '',         'subasm'],
    [505, 5, 'Stem Casting (CNC OP-2)',                       'Stem Casting CNC OP-2, Stem Length, OD, Groove',                                                                                                          '3551000',     '14',   '',         'subasm'],
    [504, 6, 'Stem Casting (CNC OP-1)',                       'Stem Casting CNC OP-1, Facing and OD mild Head Side',                                                                                                     '',            '',     '',         'subasm'],
    [186, 7, 'Stem Casting',                                  'Stem Casting - Bought out Mazac 3, ~50g weight',                                                                                                          '',            '',     '',         'rawmat'],

    [438, 1, 'Base Plate-Adj Stem Chromated',                 'Base Plate-Adj Stem Chromated',                                                                                                                           '',            '',     '',         'subasm'],
    [753, 2, 'Base Plate-Adj Stem (File OP-1)',               'Base Plate-Adj Stem (File OP-1)',                                                                                                                         '',            '',     '',         'subasm'],
    [512, 3, 'Base Plate-Adj Stem (Tap OP-1)',                'Base Plate-Adj Stem Tapping OP-1, 4.8mm 24UNC',                                                                                                           '',            '',     '',         'subasm'],
    [511, 4, 'Base Plate-Adj Stem (Drill OP-4)',              'Base Plate-Adj Stem Drilling OP-4, 3.3mm Drill',                                                                                                          '',            '',     '',         'subasm'],
    [510, 5, 'Base Plate-Adj Stem (Drill OP-3)',              'Base Plate-Adj Stem Drilling OP-3, 3.8mm drill',                                                                                                          '',            '',     '',         'subasm'],
    [509, 6, 'Base Plate-Adj Stem (Drill OP-2)',              'Base Plate-Adj Stem Drilling OP-2, Reamer',                                                                                                               '',            '',     '',         'subasm'],
    [508, 7, 'Base Plate-Adj Stem (Drill OP-1)',              'Base Plate-Adj Stem Drilling OP-1, Chamfer 6.5 dia',                                                                                                      '',            '',     '',         'subasm'],
    [188, 8, 'Base Plate-Adj Stem Machined (Cutter OP-1)',    'Base Plate-Adjusting Stem Machined Mazac 3 ~50g, after CNC OP-1, 2 + Drilling OP-1 to 4 + Tapping OP-1',                                                  '3552000',     '13',   '',         'subasm'],
    [507, 9, 'Base Plate-Adj Stem (CNC OP-1)',                'Base Plate-Adj Stem CNC OP-1, Facing',                                                                                                                    '003552-000',  'A',    '',         'subasm'],
    [190, 10,'Base Plate Casting-Adj Stem',                   'Base Plate Casting-Adjusting Stem unmachined bought out Mazac 3 ~50g weight',                                                                              '',            '',     '',         'rawmat'],

    [192, 1, 'Retainer Plate-Adj Stem',                       'Retainer Plate-Adj Stem Stamped Brass',                                                                                                                   '003556000',   '5',    '',         'subasm'],
    [194, 2, 'Brass Sheet-Adj Stem',                          'Strip 12" x 48" (.032 thick) — also stocked as 12" x 96"',                                                                                                'XYZ',         'Z',    '',         'rawmat'],

    [196, 1, 'Spring-Adj Stem',                               'Spring (helical compression, constant pitch). OD .117 ±.005, wire dia .016, free length .438 ±.031, 8 active coils, 10 total, squared ends.',             '',            '',     '',         'rawmat'],
    [198, 1, 'Ball-Adj Stem',                                 'Ball 1/8 dia, grade 100, stainless steel AISI 440C, 55-60 RC, plain finish',                                                                              '',            '',     '',         'rawmat'],
];

// Qty overrides — anything not listed defaults to 1.0
// Keyed by child legacy_id; value is qty in its parent.
$QTY_OVERRIDES = [
    196 => 2.0,    // Spring qty 2 in root
    198 => 2.0,    // Ball qty 2 in root
    194 => 0.005,  // Brass strip — slug stamped out, 0.005 per part
];

// =============================================================
// Resolve depth → parent_id chain, build edges
// =============================================================
$pathByDepth = [];   // depth → legacy_id of last item seen at that depth
$edges = [];          // [parent_legacy, child_legacy, qty]
foreach ($BOM_DATA as $row) {
    list($id, $depth) = $row;
    if ($depth > 0) {
        $parentLegacy = $pathByDepth[$depth - 1] ?? null;
        if ($parentLegacy === null) {
            throw new RuntimeException("Row $id at depth $depth has no parent in the path — CSV malformed.");
        }
        $qty = $QTY_OVERRIDES[$id] ?? 1.0;
        $edges[] = [$parentLegacy, $id, $qty];
    }
    $pathByDepth[$depth] = $id;
    // Prune deeper depths (a shallower row resets the path below it)
    foreach (array_keys($pathByDepth) as $d) {
        if ($d > $depth) unset($pathByDepth[$d]);
    }
}

// =============================================================
// FK lookups
// =============================================================
$uom    = db_one("SELECT id FROM inv_uom WHERE code = 'nos'");
$div    = db_one("SELECT id FROM categories WHERE type='division' AND code='mech'");
$catMap = [];
foreach (['finshd','subasm','rawmat'] as $cc) {
    $c = db_one("SELECT id FROM categories WHERE type='inventory' AND code = ?", [$cc]);
    if (!$c) throw new RuntimeException("Inventory category '$cc' not found — seed your DB first.");
    $catMap[$cc] = (int)$c['id'];
}
if (!$uom) throw new RuntimeException("uom 'nos' not found — seed your DB first.");
if (!$div) throw new RuntimeException("division 'mech' not found — seed your DB first.");
$uomId = (int)$uom['id'];
$divId = (int)$div['id'];

// =============================================================
// Plan: figure out what will be created vs reused (preview)
// =============================================================
$itemsPlan = [];   // [legacy_id => ['action'=>'create'|'reuse', 'code'=>..., 'name'=>..., 'existing_id'=>?]]
foreach ($BOM_DATA as $row) {
    list($legacyId, $depth, $name, $longDesc, $dwg, $rev, $partNo, $catCode) = $row;
    $code = 'BOM-' . $legacyId;
    $existing = db_one("SELECT id FROM inv_items WHERE code = ?", [$code]);
    $itemsPlan[$legacyId] = [
        'action'      => $existing ? 'reuse' : 'create',
        'code'        => $code,
        'name'        => $name,
        'long_desc'   => $longDesc,
        'dwg'         => $dwg,
        'rev'         => $rev,
        'part_no'     => $partNo,
        'category'    => $catCode,
        'depth'       => $depth,
        'existing_id' => $existing ? (int)$existing['id'] : null,
    ];
}

// =============================================================
// HANDLE COMMIT
// =============================================================
$action = input('action', '');
if ($action === 'commit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $createdItems = 0; $reusedItems = 0;
    $createdEdges = 0; $skippedEdges = 0;
    $legacyToDbId = [];

    db_exec('START TRANSACTION');
    try {
        // 1) Create / locate items
        foreach ($BOM_DATA as $row) {
            list($legacyId, $depth, $name, $longDesc, $dwg, $rev, $partNo, $catCode) = $row;
            $code = 'BOM-' . $legacyId;
            $existing = db_one("SELECT id FROM inv_items WHERE code = ?", [$code]);
            if ($existing) {
                $legacyToDbId[$legacyId] = (int)$existing['id'];
                $reusedItems++;
                continue;
            }
            db_exec(
                "INSERT INTO inv_items
                   (code, name, short_description, long_description,
                    category_id, division_id, uom_id, uom,
                    manufacturer_type,
                    dwg_no, dwg_rev_no, part_no,
                    min_sample_qty, min_sample_pct,
                    is_active, is_product, created_at, updated_at)
                 VALUES (?, ?, ?, ?,
                         ?, ?, ?, 'Nos',
                         'internal',
                         ?, ?, ?,
                         0, 0,
                         1, ?, NOW(), NOW())",
                [
                    $code,
                    $name,
                    $name,
                    $longDesc !== '' ? $longDesc : null,
                    $catMap[$catCode],
                    $divId,
                    $uomId,
                    $dwg !== '' ? $dwg : null,
                    $rev !== '' ? $rev : null,
                    $partNo !== '' ? $partNo : null,
                    $depth === 0 ? 1 : 0,    // top-level row is the product
                ]
            );
            $legacyToDbId[$legacyId] = (int)db_val('SELECT LAST_INSERT_ID()');
            $createdItems++;
        }

        // 2) Create BOM edges (idempotent on parent_item_id, child_item_id, ref_designator IS NULL)
        foreach ($edges as $e) {
            list($parentLegacy, $childLegacy, $qty) = $e;
            $parentDbId = $legacyToDbId[$parentLegacy];
            $childDbId  = $legacyToDbId[$childLegacy];
            $existing = db_one(
                "SELECT id FROM inv_bom_lines
                  WHERE parent_item_id = ? AND child_item_id = ? AND ref_designator IS NULL",
                [$parentDbId, $childDbId]
            );
            if ($existing) { $skippedEdges++; continue; }
            db_exec(
                "INSERT INTO inv_bom_lines
                   (parent_item_id, child_item_id, qty, ref_designator, sort_order, notes, created_at)
                 VALUES (?, ?, ?, NULL, 0, NULL, NOW())",
                [$parentDbId, $childDbId, $qty]
            );
            $createdEdges++;
        }

        db_exec('COMMIT');
        // Mirror created finished-goods to the billing catalogue. Helper
        // filters by finished-category itself; we just hand it every
        // newly-created id.
        if (function_exists('billing_product_push_if_needed')) {
            foreach ($legacyToDbId as $legacyId => $dbId) {
                billing_product_push_if_needed((int)$dbId, current_user_id());
            }
        }
        flash_set('success',
            "BOM import complete · items: $createdItems new / $reusedItems existed · edges: $createdEdges new / $skippedEdges existed");

        // Audit log
        $rootDbId = $legacyToDbId[183] ?? 0;
        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details, created_at)
             VALUES (?, 'bom_import_oneshot', ?, ?, NOW())",
            [current_user_id(), $rootDbId,
             json_encode(['source'=>'tools/bom-import-adjusting-stem.php',
                          'items_created'=>$createdItems,
                          'items_reused'=>$reusedItems,
                          'edges_created'=>$createdEdges,
                          'edges_skipped'=>$skippedEdges])]
        );

        redirect(url('/inventory.php?action=bom_view&id=' . $rootDbId));
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        flash_set('error', 'Import failed: ' . $e->getMessage());
        redirect(url('/tools/bom-import-adjusting-stem.php'));
    }
}

// =============================================================
// PREVIEW PAGE
// =============================================================
$page_title  = 'BOM Import — Adjusting Stem Mechanism';
$page_module = 'inventory_view_boms';
require dirname(__DIR__) . '/includes/header.php';

$newItems = array_filter($itemsPlan, function($p){return $p['action']==='create';});
$reuseItems = array_filter($itemsPlan, function($p){return $p['action']==='reuse';});
?>
<div class="page-head">
    <div>
        <h1>BOM Import — Adjusting Stem Mechanism</h1>
        <p class="muted">One-shot import of 22 items + 21 BOM edges from the legacy hierarchical CSV.
           Idempotent: items and edges are keyed so re-running is safe.</p>
    </div>
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="card-head"><h3 style="margin:0;font-size:15px;">Summary</h3></div>
    <div class="card-body">
        <p style="margin:0">
            <strong><?= count($newItems) ?></strong> new items will be created,
            <strong><?= count($reuseItems) ?></strong> already exist (will be reused).
            <strong><?= count($edges) ?></strong> BOM edges in total.
            All items use code <code>BOM-&lt;legacy_id&gt;</code>, UoM <code>nos</code>, division <code>mech</code>.
        </p>
    </div>
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="card-head"><h3 style="margin:0;font-size:15px;">Items to create / reuse</h3></div>
    <div class="card-body" style="padding:0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Code</th>
                    <th>Depth</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Dwg / Rev / Part No</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itemsPlan as $legacyId => $p): ?>
                    <tr>
                        <td>
                            <?php if ($p['action'] === 'create'): ?>
                                <span class="pill pill-success">CREATE</span>
                            <?php else: ?>
                                <span class="pill pill-neutral">REUSE</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= h($p['code']) ?></code></td>
                        <td><?= str_repeat('· ', $p['depth']) ?><?= (int)$p['depth'] ?></td>
                        <td><?= h($p['name']) ?></td>
                        <td><code><?= h($p['category']) ?></code></td>
                        <td>
                            <?= h($p['dwg'] ?: '—') ?> / <?= h($p['rev'] ?: '—') ?> / <?= h($p['part_no'] ?: '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-bottom:18px;">
    <div class="card-head"><h3 style="margin:0;font-size:15px;">BOM edges (<?= count($edges) ?>)</h3></div>
    <div class="card-body" style="padding:0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Parent</th>
                    <th>Child</th>
                    <th>Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($edges as $e):
                    list($p, $c, $q) = $e;
                    $pName = $itemsPlan[$p]['name'];
                    $cName = $itemsPlan[$c]['name'];
                ?>
                    <tr>
                        <td><code>BOM-<?= (int)$p ?></code> · <?= h($pName) ?></td>
                        <td><code>BOM-<?= (int)$c ?></code> · <?= h($cName) ?></td>
                        <td>
                            <?php if ($q != 1.0): ?>
                                <strong><?= rtrim(rtrim(number_format($q, 6, '.', ''), '0'), '.') ?></strong>
                            <?php else: ?>
                                1
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="post" action="<?= h(url('/tools/bom-import-adjusting-stem.php?action=commit')) ?>"
      onsubmit="return confirm('Commit this BOM import? Items and edges will be created in your DB.');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary">Commit import</button>
    <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=bom_grid')) ?>">Cancel</a>
</form>

<?php require dirname(__DIR__) . '/includes/footer.php';
