<?php
/**
 * MagDyn — Inventory: BOM lines (add, update, delete, import, delete-tree)
 * Extracted Stage 1: 20260517_223400_IST
 *
 * Line-level operations on inv_bom_lines: add edges, update qty, delete,
 * hierarchical CSV import, and whole-BOM-tree delete with orphan item
 * cleanup. Cycle prevention uses inv_ancestors_of() from
 * _inventory_helpers.php.
 *
 * PARTIAL — not a standalone page. Routed by inventory.php (the
 * dispatcher). Variables already in scope from the dispatcher:
 *   $action, $canViewItems, $canCreateItems, $canManageItems,
 *   $canDeleteItems, $canViewBoms, $canCreateBoms, $canManageBoms,
 *   $canDeleteBoms.
 */

// ============================================================
// BOM IMPORT — hierarchical (depth-encoded) CSV
// ============================================================
//
// FORMAT
// ------
// The CSV's first column encodes the tree via leading `|`/`-` prefix
// characters and a "<name> (<code>)~<qty>~<code>" suffix. The depth is
// the number of `|` characters in the prefix.
//
//   Adjusting Stem - Mechanism (183)~1~183     ← depth 0, code 183
//   |---Stem Chromated (437)~1~437             ← depth 1
//   |   |--Adj Stem Machined (754)~1~754       ← depth 2
//
// Item code is the parenthesized number near the end of the name
// portion (e.g. `(183)`). The `~q~code` suffix is informational only —
// `code` always matches the parenthesized one; `q` is the row's own
// qty (typically 1) which we use as a fallback when the parent doesn't
// list this child in I_Tree Child.
//
// Per-edge qty is authoritatively read from the PARENT row's
// `I_Tree Child` column, formatted as `<code>-<qty>;<code>-<qty>;`.
// E.g. row 183 says `437-1;438-1;192-1;196-2;198-2;` — Spring (196)
// and Ball (198) are needed 2× each in this assembly.
//
// AUTO-ITEM CREATION
// ------------------
// Items that don't exist in inv_items are created automatically from
// the CSV row data: short/long descriptions, dwg/rev/part_no, material
// spec, min stock level. Category derives from the legacy category_id
// column: 1 → finshd, 2 → subasm, 3 → rawmat. Division defaults to
// `mech`, UoM to `nos`, manufacturer_type=internal.
//
// IDEMPOTENCY
// -----------
// Items keyed on `code`: pre-existing items are reused, never modified
// by the importer. Edges keyed on (parent_id, child_id) with no
// ref_designator. With upsert ON, an existing edge has its qty/sort
// updated. With upsert OFF, it's skipped.
//
// CYCLE PREVENTION
// ----------------
// Self-edges are rejected per-row. Cross-row cycles (whether against
// existing DB edges or against other rows in the same CSV) are
// detected in a second pass over the virtual graph and demoted to
// errors.

require_once dirname(__DIR__, 2) . '/includes/_import.php';
require_once dirname(__DIR__, 2) . '/includes/_inventory_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/_billing_products.php';

// ------------------------------------------------------------
// Parse one CSV row's first column into structural metadata.
// Returns ['ok'=>bool, ...] with depth/code/qty_row/name fields on
// success, or ['ok'=>false, 'reason'=>str] on failure.
// ------------------------------------------------------------
function bom_import_parse_tree_cell($raw) {
    $cell = (string)$raw;
    if ($cell === '') {
        return ['ok' => false, 'reason' => 'first column is empty'];
    }
    // The format is: <prefix><name with code in parens>~<qty>~<code>.
    // Split out the ~q~code suffix first.
    $rowQty = 1.0;
    $base   = $cell;
    if (preg_match('/^(.+?)~([\d.]+)~([^~]+)$/', $cell, $m)) {
        $base   = $m[1];
        $rowQty = (float)$m[2];
    }
    // Extract the parenthesized code from the END of the name portion.
    if (!preg_match('/\(([A-Za-z0-9_-]+)\)\s*$/', $base, $m2)) {
        return ['ok' => false,
                'reason' => 'first column does not end with "(<code>)" — got: '
                          . substr($cell, 0, 80)];
    }
    $code = $m2[1];
    // Compute depth from the leading prefix run (|, -, spaces)
    $prefixLen = strspn($base, "| \t-");
    $prefix    = substr($base, 0, $prefixLen);
    $depth     = substr_count($prefix, '|');
    // Name = base after prefix, with the trailing "(code)" stripped
    $name = trim(substr($base, $prefixLen));
    $name = preg_replace('/\s*\(' . preg_quote($code, '/') . '\)\s*$/', '', $name);
    $name = ltrim($name, "- \t");
    if ($name === '') $name = $code;

    return [
        'ok'      => true,
        'depth'   => $depth,
        'code'    => $code,
        'qty_row' => $rowQty,
        'name'    => $name,
    ];
}

// ------------------------------------------------------------
// Parse a parent's `I_Tree Child` cell into [child_code => qty].
// Handles "437-1;438-1;196-2;198-2;" or "194-0.005;" patterns.
// Empty / "-" returns an empty array.
// ------------------------------------------------------------
function bom_import_parse_tree_child_field($raw) {
    $s = trim((string)$raw);
    if ($s === '' || $s === '-') return [];
    $out = [];
    foreach (explode(';', $s) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '-') continue;
        // Codes can in theory contain hyphens, so split on the LAST `-`
        $pos = strrpos($seg, '-');
        if ($pos === false) continue;
        $code = trim(substr($seg, 0, $pos));
        $qty  = trim(substr($seg, $pos + 1));
        if ($code === '' || !is_numeric($qty)) continue;
        $out[$code] = (float)$qty;
    }
    return $out;
}

// ------------------------------------------------------------
// Map legacy CSV category_id (1/2/3) → MagDyn inventory category code.
// Anything else falls back to 'subasm'.
// ------------------------------------------------------------
function bom_import_category_code_for_legacy_id($legacyId) {
    $legacyId = trim((string)$legacyId);
    if ($legacyId === '1') return 'finshd';
    if ($legacyId === '2') return 'subasm';
    if ($legacyId === '3') return 'rawmat';
    return 'subasm';
}

// ------------------------------------------------------------
// Pre-load FK rows we need at parse time. Returns ['ok'=>false,
// 'reason'=>...] on failure, else a struct with uom_id / cat_id_by_code.
// Division is now resolved per-row in the parser (auto-created from
// I_Division if missing), so it's not a hard precondition here anymore.
// ------------------------------------------------------------
function bom_import_load_fks() {
    $uom = db_one("SELECT id FROM inv_uom WHERE code = 'nos'");
    if (!$uom) {
        return ['ok' => false, 'reason' => "Required UoM 'nos' is missing in inv_uom — seed it first."];
    }
    $catMap = [];
    foreach (['finshd', 'subasm', 'rawmat'] as $cc) {
        $c = db_one("SELECT id FROM categories WHERE type='inventory' AND code = ?", [$cc]);
        if (!$c) {
            return ['ok' => false,
                    'reason' => "Required inventory category '$cc' is missing in categories — seed it first."];
        }
        $catMap[$cc] = (int)$c['id'];
    }
    return [
        'ok'             => true,
        'uom_id'         => (int)$uom['id'],
        'cat_id_by_code' => $catMap,
    ];
}

// ------------------------------------------------------------
// Resolve (or create) a division by code. Code is taken verbatim from
// the CSV's I_Division column (case-preserved). Name = code, no
// further normalization.
//
// Returns the division's category id. Caches in a per-request map so
// repeated lookups (one per row) cost a single DB hit per unique name.
// Empty / missing I_Division falls back to a synthetic '__unknown'
// division so the FK constraint can be satisfied.
// ------------------------------------------------------------
function bom_import_resolve_division($name, &$cache, &$createdNames) {
    $name = trim((string)$name);
    if ($name === '') $name = '__unknown';
    if (isset($cache[$name])) return $cache[$name];

    $row = db_one(
        "SELECT id FROM categories WHERE type='division' AND code = ?",
        [$name]
    );
    if ($row) {
        $cache[$name] = (int)$row['id'];
        return $cache[$name];
    }
    // Auto-create
    db_exec(
        "INSERT INTO categories (type, code, name, sort_order, is_active, created_at)
         VALUES ('division', ?, ?, 500, 1, NOW())",
        [$name, $name]
    );
    $id = (int)db_val('SELECT LAST_INSERT_ID()');
    $cache[$name] = $id;
    $createdNames[$name] = $id;
    return $id;
}

// ------------------------------------------------------------
// Walk the parsed CSV rows and build the items map + edges list.
//
// $parsedRows is the output of import_parse_csv_text(...)['rows']:
// each row is an associative array with lowercased column keys.
//
// Returns:
//   'items'      => [code => ['action','data',...,'line']]
//   'edges'      => list of ['parent_code','child_code','qty','sort','line']
//   'row_errors' => [['line','reason'], ...]
// ------------------------------------------------------------
function bom_import_hierarchical_parse(array $parsedRows) {
    $items        = [];
    $edges        = [];
    $rowErrors    = [];
    $depthPath    = [];   // depth → active code at that depth
    $childCounter = [];   // parent_code → running int for sort_order

    $lineNo = 1; // header is line 1
    foreach ($parsedRows as $rowOriginal) {
        $lineNo++;
        // Normalize keys to lowercase so we can read "I_Tree Child" as "i_tree child"
        $row = [];
        foreach ($rowOriginal as $k => $v) $row[strtolower($k)] = $v;

        $treeCell = (string)($row['inventory_model_id'] ?? '');
        $parsed = bom_import_parse_tree_cell($treeCell);
        if (!$parsed['ok']) {
            $rowErrors[] = ['line' => $lineNo, 'reason' => $parsed['reason']];
            continue;
        }
        $code  = $parsed['code'];
        $depth = $parsed['depth'];

        // Duplicate code in this CSV?
        if (isset($items[$code])) {
            $rowErrors[] = ['line'   => $lineNo,
                            'reason' => 'Duplicate code "' . $code
                                      . '" (also on line ' . $items[$code]['line'] . ')'];
            continue;
        }

        // Resolve parent via the depth path
        $parentCode = null;
        if ($depth > 0) {
            if (!isset($depthPath[$depth - 1])) {
                $rowErrors[] = ['line'   => $lineNo,
                                'reason' => 'Row at depth ' . $depth
                                          . ' has no parent in the depth path'];
                continue;
            }
            $parentCode = $depthPath[$depth - 1];
        }

        // Update the path
        $depthPath[$depth] = $code;
        foreach (array_keys($depthPath) as $d) {
            if ($d > $depth) unset($depthPath[$d]);
        }

        // Build the item record (whether we'll create it or just reuse)
        $catLegacy = trim((string)($row['category_id'] ?? ''));
        $catCode   = bom_import_category_code_for_legacy_id($catLegacy);
        $divisionName = trim((string)($row['i_division'] ?? ''));
        $existing  = db_one("SELECT id FROM inv_items WHERE code = ?", [$code]);
        $items[$code] = [
            'action'           => $existing ? 'reuse' : 'create',
            'line'             => $lineNo,
            'existing_id'      => $existing ? (int)$existing['id'] : null,
            'code'             => $code,
            'name'             => $parsed['name'],
            'long_description' => trim((string)($row['long_description'] ?? '')),
            'dwg_no'           => trim((string)($row['dwg_no'] ?? '')),
            'dwg_rev_no'       => trim((string)($row['rev_no'] ?? '')),
            'part_no'          => trim((string)($row['part_no'] ?? '')),
            'process_spec'     => trim((string)($row['process spec'] ?? '')),
            'material_spec'    => trim((string)($row['material spec'] ?? '')),
            'min_stock_level'  => trim((string)($row['min stock level'] ?? '')),
            'min_order_qty'    => trim((string)($row['min order qty'] ?? '')),
            'category_code'    => $catCode,
            'division_name'    => $divisionName,    // verbatim from CSV; resolved at commit
            'depth'            => $depth,
            'is_root'          => $depth === 0,
            'tree_child_field' => (string)($row['i_tree child'] ?? ''),
        ];

        // Edge from parent → this row, with qty resolved from parent's
        // I_Tree Child cell. Falls back to row's own qty if missing.
        if ($parentCode !== null) {
            $parentChildren = isset($items[$parentCode]['tree_child_field'])
                ? bom_import_parse_tree_child_field($items[$parentCode]['tree_child_field'])
                : [];
            $qty = isset($parentChildren[$code]) ? $parentChildren[$code] : $parsed['qty_row'];
            if ($qty <= 0) $qty = 1.0;
            if (!isset($childCounter[$parentCode])) $childCounter[$parentCode] = 0;
            $childCounter[$parentCode] += 10;
            $edges[] = [
                'parent_code' => $parentCode,
                'child_code'  => $code,
                'qty'         => $qty,
                'sort_order'  => $childCounter[$parentCode],
                'line'        => $lineNo,
            ];
        }
    }

    return ['items' => $items, 'edges' => $edges, 'row_errors' => $rowErrors];
}

// ------------------------------------------------------------
// For each parsed edge, classify against the existing DB as
// insert/update/skip/error. Brand-new items (no existing_id) can't
// be DB-cycle-checked individually — the cross-row pass handles them.
// ------------------------------------------------------------
function bom_import_resolve_edges(array $items, array $edges, $upsert) {
    $counts = ['insert' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
    $rows   = [];

    foreach ($edges as $e) {
        $pCode = $e['parent_code'];
        $cCode = $e['child_code'];
        if (!isset($items[$pCode]) || !isset($items[$cCode])) {
            $rows[] = ['line' => $e['line'], 'status' => 'error',
                       'reason' => 'internal: edge endpoint missing from items map',
                       'data'   => $e];
            $counts['error']++;
            continue;
        }
        $pId = $items[$pCode]['existing_id'];
        $cId = $items[$cCode]['existing_id'];

        $clean = [
            'parent_code' => $pCode,
            'parent_id'   => $pId,
            'child_code'  => $cCode,
            'child_id'    => $cId,
            'qty'         => $e['qty'],
            'sort_order'  => $e['sort_order'],
        ];

        // Self-edge?
        if ($pCode === $cCode) {
            $rows[] = ['line' => $e['line'], 'status' => 'error',
                       'reason' => 'parent and child code are the same (no self-edges)',
                       'data'   => $clean];
            $counts['error']++;
            continue;
        }

        // DB-cycle check if BOTH endpoints already exist
        if ($pId !== null && $cId !== null) {
            $ancestors = inv_ancestors_of($pId);
            if (in_array($cId, $ancestors, true)) {
                $rows[] = ['line' => $e['line'], 'status' => 'error',
                           'reason' => 'Would create a cycle: "' . $cCode
                                     . '" is already an ancestor of "' . $pCode . '"',
                           'data'   => $clean];
                $counts['error']++;
                continue;
            }
            // Existing edge?
            $existing = db_one(
                'SELECT id FROM inv_bom_lines
                  WHERE parent_item_id = ? AND child_item_id = ?
                    AND ref_designator IS NULL',
                [$pId, $cId]
            );
            if ($existing) {
                if (!$upsert) {
                    $rows[] = ['line' => $e['line'], 'status' => 'skip',
                               'reason' => 'Edge already exists; upsert is off',
                               'data'   => $clean];
                    $counts['skip']++;
                } else {
                    $rows[] = ['line' => $e['line'], 'status' => 'update',
                               'data' => $clean, 'existing_id' => (int)$existing['id']];
                    $counts['update']++;
                }
                continue;
            }
        }

        $rows[] = ['line' => $e['line'], 'status' => 'insert', 'data' => $clean];
        $counts['insert']++;
    }

    return ['counts' => $counts, 'rows' => $rows];
}

// ------------------------------------------------------------
// Cross-row cycle check. Builds the virtual graph of existing DB
// edges (keyed by item code) + all proposed inserts/updates, then
// checks whether any proposed edge closes a cycle.
// ------------------------------------------------------------
function bom_import_check_cross_row_cycles_hier(array $items, array &$edgeResult) {
    $childrenOf = [];
    // Existing DB edges → adjacency keyed by code
    $dbEdges = db_all(
        "SELECT pi.code AS pc, ci.code AS cc
           FROM inv_bom_lines b
           JOIN inv_items pi ON pi.id = b.parent_item_id
           JOIN inv_items ci ON ci.id = b.child_item_id"
    );
    foreach ($dbEdges as $de) {
        $childrenOf[$de['pc']][] = $de['cc'];
    }
    // Layer in proposed inserts/updates
    foreach ($edgeResult['rows'] as $er) {
        if (!in_array($er['status'], ['insert', 'update'], true)) continue;
        $childrenOf[$er['data']['parent_code']][] = $er['data']['child_code'];
    }

    // For each proposed edge, BFS from child to see if we can reach parent.
    foreach ($edgeResult['rows'] as &$er) {
        if (!in_array($er['status'], ['insert', 'update'], true)) continue;
        $pc = $er['data']['parent_code'];
        $cc = $er['data']['child_code'];
        $visited = [];
        $queue   = [$cc];
        $found   = false;
        while ($queue) {
            $cur = array_shift($queue);
            if (isset($visited[$cur])) continue;
            $visited[$cur] = true;
            if (!isset($childrenOf[$cur])) continue;
            foreach ($childrenOf[$cur] as $next) {
                if ($next === $pc) { $found = true; break 2; }
                if (!isset($visited[$next])) $queue[] = $next;
            }
        }
        if ($found) {
            $prev = $er['status'];
            $er['status'] = 'error';
            $er['reason'] = 'Cross-row cycle detected (path from ' . $cc . ' back to ' . $pc . ')';
            $edgeResult['counts'][$prev]--;
            $edgeResult['counts']['error']++;
        }
    }
}

// ------------------------------------------------------------
// Commit: in one transaction, create missing items then write edges.
// ------------------------------------------------------------
function bom_import_commit_hierarchical(array $items, array $edgeResult, $fks, array &$stats) {
    db_exec('START TRANSACTION');
    try {
        $codeToId = [];
        $divCache = [];          // [division_name => id] — cached lookups
        $divCreatedNow = [];     // [division_name => id] for divisions auto-created in THIS commit
        // 1) Items
        foreach ($items as $code => $it) {
            if ($it['action'] === 'reuse') {
                $codeToId[$code] = $it['existing_id'];
                $stats['items_reused']++;
                continue;
            }
            $minStock = is_numeric($it['min_stock_level']) ? (float)$it['min_stock_level'] : null;
            $minOrder = is_numeric($it['min_order_qty'])  ? (float)$it['min_order_qty']  : null;
            // Resolve (or auto-create) the division for this row. Empty
            // I_Division resolves to a fallback '__unknown' division so
            // the FK constraint stays valid.
            $divId = bom_import_resolve_division($it['division_name'], $divCache, $divCreatedNow);
            db_exec(
                "INSERT INTO inv_items
                    (code, name, short_description, long_description,
                     category_id, division_id, manufacturer_type,
                     uom_id, dwg_no, dwg_rev_no, part_no, part_rev_no,
                     process_spec, process_step_id, step_no,
                     step_time_min, step_cost,
                     min_stock_level, min_order_qty,
                     min_sample_qty, min_sample_pct,
                     material_spec, remarks, notes,
                     is_active, is_product, created_at, updated_at)
                 VALUES (?, ?, ?, ?,
                         ?, ?, 'internal',
                         ?, ?, ?, ?, NULL,
                         ?, NULL, NULL,
                         NULL, NULL,
                         ?, ?,
                         0, 0,
                         ?, NULL, NULL,
                         1, ?, NOW(), NOW())",
                [
                    $code,
                    $it['name'],
                    $it['name'],
                    $it['long_description'] !== '' ? $it['long_description'] : null,
                    $fks['cat_id_by_code'][$it['category_code']],
                    $divId,
                    $fks['uom_id'],
                    $it['dwg_no']     !== '' ? $it['dwg_no']     : null,
                    $it['dwg_rev_no'] !== '' ? $it['dwg_rev_no'] : null,
                    $it['part_no']    !== '' ? $it['part_no']    : null,
                    $it['process_spec'] !== '' ? $it['process_spec'] : null,
                    $minStock, $minOrder,
                    $it['material_spec'] !== '' ? $it['material_spec'] : null,
                    $it['is_root'] ? 1 : 0,
                ]
            );
            $codeToId[$code] = (int)db_val('SELECT LAST_INSERT_ID()');
            $stats['items_created']++;
        }
        $stats['divisions_created'] = count($divCreatedNow);
        $stats['divisions_created_names'] = array_keys($divCreatedNow);
        // 2) Edges
        foreach ($edgeResult['rows'] as $er) {
            if (!in_array($er['status'], ['insert', 'update'], true)) continue;
            $d = $er['data'];
            $pId = $d['parent_id'] !== null ? $d['parent_id'] : $codeToId[$d['parent_code']];
            $cId = $d['child_id']  !== null ? $d['child_id']  : $codeToId[$d['child_code']];
            if ($er['status'] === 'update') {
                db_exec(
                    'UPDATE inv_bom_lines SET qty=?, sort_order=?, ref_designator=NULL, notes=NULL WHERE id=?',
                    [$d['qty'], $d['sort_order'], (int)$er['existing_id']]
                );
                $stats['edges_updated']++;
            } else {
                db_exec(
                    'INSERT INTO inv_bom_lines (parent_item_id, child_item_id, qty, sort_order, ref_designator, notes)
                     VALUES (?, ?, ?, ?, NULL, NULL)',
                    [$pId, $cId, $d['qty'], $d['sort_order']]
                );
                $stats['edges_inserted']++;
            }
        }
        db_exec('COMMIT');
        // After the transaction commits, mirror newly-created finished
        // goods to billing. The helper filters by category itself, so
        // we hand it every created item id — only finished-good rows
        // result in a network call.
        if (function_exists('billing_product_push_if_needed')) {
            foreach ($codeToId as $code => $iid) {
                billing_product_push_if_needed((int)$iid, function_exists('current_user_id') ? current_user_id() : null);
            }
        }
        return true;
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        $stats['error'] = $e->getMessage();
        return false;
    }
}

// ------------------------------------------------------------
// Preview renderer — two cards (items + edges) with pills and counts.
// ------------------------------------------------------------
function bom_import_render_preview_hier($title, $token, $upsert, array $items, array $edgeResult, $commitUrl, $cancelUrl, array $divisionExists = []) {
    $itemsCreate = array_filter($items, function ($i) { return $i['action'] === 'create'; });
    $itemsReuse  = array_filter($items, function ($i) { return $i['action'] === 'reuse'; });
    $totalEdges  = $edgeResult['counts']['insert'] + $edgeResult['counts']['update'];
    $hasErrors   = $edgeResult['counts']['error'] > 0;
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($title) ?></h1>
            <p class="muted">
                Review the items and edges below. Items keyed on code — pre-existing items
                are reused (never modified). Edges flagged red will be skipped on commit.
            </p>
        </div>
    </div>

    <div class="import-summary" style="margin-bottom: 14px;">
        <span class="pill pill-success">+ Items create: <?= count($itemsCreate) ?></span>
        <span class="pill pill-neutral">⊙ Items reuse: <?= count($itemsReuse) ?></span>
        <span class="pill pill-success">✓ Edges insert: <?= (int)$edgeResult['counts']['insert'] ?></span>
        <span class="pill pill-info">⟳ Edges update: <?= (int)$edgeResult['counts']['update'] ?></span>
        <span class="pill pill-neutral">⊘ Edges skip: <?= (int)$edgeResult['counts']['skip'] ?></span>
        <span class="pill pill-danger">✗ Edges error: <?= (int)$edgeResult['counts']['error'] ?></span>
    </div>

    <div class="import-actions" style="margin-bottom: 18px;">
        <form method="post" action="<?= h($commitUrl) ?>" style="display:inline"
              onsubmit="return confirm('Commit this BOM import? Items and edges will be created.');">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="upsert" value="<?= $upsert ? '1' : '0' ?>">
            <button type="submit" class="btn btn-primary"
                    <?= ($totalEdges + count($itemsCreate)) === 0 ? 'disabled' : '' ?>>
                Commit <?= count($itemsCreate) ?> new item<?= count($itemsCreate) === 1 ? '' : 's' ?>
                + <?= $totalEdges ?> edge<?= $totalEdges === 1 ? '' : 's' ?>
            </button>
        </form>
        <a class="btn btn-ghost" href="<?= h($cancelUrl) ?>">Cancel</a>
        <?php if ($hasErrors): ?>
            <span class="muted small" style="margin-left: 12px;">
                Red edges will be skipped on commit.
            </span>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <div class="card-head"><h3 style="margin:0;font-size:15px;">Items (<?= count($items) ?>)</h3></div>
        <div class="card-body" style="padding:0">
            <table class="data-table">
                <thead><tr>
                    <th>Action</th><th>Code</th><th>Depth</th><th>Name</th>
                    <th>Category</th><th>Division</th><th>Dwg / Rev / Part</th><th>CSV line</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($items as $i):
                        $divName = trim((string)$i['division_name']);
                        $divDisplay = $divName === '' ? '__unknown' : $divName;
                        $divIsNew = !isset($divisionExists[$divDisplay]) || $divisionExists[$divDisplay] === false;
                    ?>
                        <tr>
                            <td>
                                <?php if ($i['action'] === 'create'): ?>
                                    <span class="pill pill-success">CREATE</span>
                                <?php else: ?>
                                    <span class="pill pill-neutral">REUSE</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= h($i['code']) ?></code></td>
                            <td><?= str_repeat('· ', (int)$i['depth']) ?><?= (int)$i['depth'] ?></td>
                            <td><?= h($i['name']) ?></td>
                            <td><code><?= h($i['category_code']) ?></code></td>
                            <td>
                                <code><?= h($divDisplay) ?></code>
                                <?php if ($divIsNew): ?>
                                    <span class="pill pill-warning" style="font-size:10px;" title="Division does not exist; will be auto-created at commit">+NEW</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($i['dwg_no'] ?: '—') ?> / <?= h($i['dwg_rev_no'] ?: '—') ?> / <?= h($i['part_no'] ?: '—') ?></td>
                            <td class="muted small"><?= (int)$i['line'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:18px;">
        <div class="card-head"><h3 style="margin:0;font-size:15px;">BOM edges (<?= count($edgeResult['rows']) ?>)</h3></div>
        <div class="card-body" style="padding:0">
            <table class="data-table">
                <thead><tr>
                    <th>Status</th><th>Parent</th><th>Child</th>
                    <th>Qty</th><th>Sort</th><th>CSV line</th><th>Note</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($edgeResult['rows'] as $er):
                        $d = $er['data'];
                        $pillClass = 'pill-neutral';
                        if      ($er['status'] === 'insert') $pillClass = 'pill-success';
                        elseif  ($er['status'] === 'update') $pillClass = 'pill-info';
                        elseif  ($er['status'] === 'error')  $pillClass = 'pill-danger';
                        $qtyStr = rtrim(rtrim(number_format((float)$d['qty'], 6, '.', ''), '0'), '.') ?: '0';
                    ?>
                        <tr>
                            <td><span class="pill <?= $pillClass ?>"><?= strtoupper(h($er['status'])) ?></span></td>
                            <td><code><?= h($d['parent_code']) ?></code></td>
                            <td><code><?= h($d['child_code']) ?></code></td>
                            <td><?= h($qtyStr) ?></td>
                            <td class="muted small"><?= (int)$d['sort_order'] ?></td>
                            <td class="muted small"><?= (int)$er['line'] ?></td>
                            <td class="muted small"><?= isset($er['reason']) ? h($er['reason']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// ============================================================
// ACTIONS: bom_import_preview / bom_import_commit
// ============================================================

if ($action === 'bom_import_preview') {
    if (!$canCreateBoms && !$canManageBoms) {
        require_permission('inventory_view_boms', 'create');
    }
    // Item creation is implicit in this importer; require the permission.
    if (!$canCreateItems && !$canManageItems) {
        flash_set('error',
            'This importer auto-creates missing items, so you need '
          . 'inventory_view_items.create. Ask an administrator to grant it.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    csrf_check();
    $upsert = !empty($_POST['upsert']);
    $parsed = import_parse_uploaded_csv('csv');
    if (empty($parsed['ok'])) {
        flash_set('error', $parsed['error']);
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $fks = bom_import_load_fks();
    if (!$fks['ok']) {
        flash_set('error', $fks['reason']);
        redirect(url('/inventory.php?action=bom_grid'));
    }

    $token   = import_stash($parsed['csv_text'], 'inv_bom');
    $parsedH = bom_import_hierarchical_parse($parsed['rows']);
    $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
    bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

    $page_title  = 'Import BOM · preview';
    $page_module = 'inventory_view_boms';
    require dirname(__DIR__, 2) . '/includes/header.php';

    if (!empty($parsedH['row_errors'])) {
        echo '<div class="card" style="margin-bottom:14px; border-left: 3px solid #b3261e;">';
        echo '<div class="card-head"><h3 style="margin:0;font-size:15px;color:#b3261e;">CSV parse errors ('
           . count($parsedH['row_errors']) . ')</h3></div>';
        echo '<div class="card-body"><ul style="margin:0;padding-left:20px;">';
        foreach ($parsedH['row_errors'] as $err) {
            echo '<li>line ' . (int)$err['line'] . ': ' . h($err['reason']) . '</li>';
        }
        echo '</ul></div></div>';
    }

    // Pre-compute which divisions referenced in the CSV exist (so the
    // preview can flag the ones that will be auto-created at commit).
    $divisionsInCsv = [];
    foreach ($parsedH['items'] as $it) {
        $name = trim((string)$it['division_name']);
        if ($name === '') $name = '__unknown';
        $divisionsInCsv[$name] = true;
    }
    $divisionExists = [];
    foreach (array_keys($divisionsInCsv) as $name) {
        $row = db_one("SELECT id FROM categories WHERE type='division' AND code = ?", [$name]);
        $divisionExists[$name] = $row !== null;
    }

    bom_import_render_preview_hier(
        'Import BOM lines · preview',
        $token,
        $upsert,
        $parsedH['items'],
        $edges,
        url('/inventory.php?action=bom_import_commit'),
        url('/inventory.php?action=bom_grid'),
        $divisionExists
    );
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

if ($action === 'bom_import_commit') {
    if (!$canCreateBoms && !$canManageBoms) {
        require_permission('inventory_view_boms', 'create');
    }
    if (!$canCreateItems && !$canManageItems) {
        flash_set('error', 'Missing inventory_view_items.create permission.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    csrf_check();
    $token  = (string)input('token', '');
    $upsert = !empty($_POST['upsert']);
    $csv = import_unstash($token, 'inv_bom');
    if ($csv === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $fks = bom_import_load_fks();
    if (!$fks['ok']) {
        flash_set('error', $fks['reason']);
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $parsed = import_parse_csv_text($csv);
    if (empty($parsed['ok'])) {
        flash_set('error', 'Re-parse failed: ' . ($parsed['error'] ?? 'unknown'));
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $parsedH = bom_import_hierarchical_parse($parsed['rows']);
    $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
    bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

    $stats = ['items_created' => 0, 'items_reused' => 0,
              'edges_inserted' => 0, 'edges_updated' => 0,
              'divisions_created' => 0, 'divisions_created_names' => [],
              'error' => ''];
    $ok = bom_import_commit_hierarchical($parsedH['items'], $edges, $fks, $stats);
    if (!$ok) {
        flash_set('error', 'Import failed: ' . $stats['error']);
        redirect(url('/inventory.php?action=bom_grid'));
    }

    // Audit log + redirect to the root assembly's BOM view if discoverable
    $rootCode = null;
    foreach ($parsedH['items'] as $code => $it) {
        if ($it['is_root']) { $rootCode = $code; break; }
    }
    $rootDbId = $rootCode !== null
        ? (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$rootCode])
        : 0;
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details)
         VALUES (?, 'inventory.bom.import_hierarchical', ?, ?)",
        [current_user_id(), $rootDbId, json_encode($stats)]
    );

    $msg = sprintf(
        'BOM import complete · items: %d created / %d reused · edges: %d inserted / %d updated',
        $stats['items_created'], $stats['items_reused'],
        $stats['edges_inserted'], $stats['edges_updated']
    );
    if (!empty($stats['divisions_created'])) {
        $names = !empty($stats['divisions_created_names'])
            ? ' (' . implode(', ', $stats['divisions_created_names']) . ')'
            : '';
        $msg .= sprintf(' · divisions: %d created%s', (int)$stats['divisions_created'], $names);
    }
    flash_set('success', $msg);

    if ($rootDbId > 0) {
        redirect(url('/inventory.php?action=bom_view&id=' . $rootDbId));
    }
    redirect(url('/inventory.php?action=bom_grid'));
}

if ($action === 'bom_line_add') {
    csrf_check();
    if (!$canManageBoms) {
        flash_set('error', 'No permission to manage BOMs.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $parentId = (int)input('parent_item_id', 0);
    $childId  = (int)input('child_item_id', 0);
    $qty      = (float)input('qty', 1);
    $ref      = trim((string)input('ref_designator'));
    $notes    = trim((string)input('notes'));

    $back = url('/inventory.php?action=bom_edit&id=' . $parentId);
    if (!$parentId || !$childId) {
        flash_set('error', 'Both parent and child must be set.');
        redirect($back);
    }
    if ($parentId === $childId) {
        flash_set('error', 'An item cannot be a child of itself.');
        redirect($back);
    }
    if ($qty <= 0) {
        flash_set('error', 'Quantity must be greater than zero.');
        redirect($back);
    }
    // Cycle check: the proposed child must NOT be an ancestor of the parent.
    $ancestors = inv_ancestors_of($parentId);
    if (in_array($childId, $ancestors, true)) {
        flash_set('error', 'That would create a cycle (child is already an ancestor of this assembly).');
        redirect($back);
    }
    // Compute next sort_order
    $maxOrder = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) FROM inv_bom_lines WHERE parent_item_id = ?', [$parentId], 0);
    db_exec(
        'INSERT INTO inv_bom_lines (parent_item_id, child_item_id, qty, sort_order, ref_designator, notes)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$parentId, $childId, $qty, $maxOrder + 10, $ref ?: null, $notes ?: null]
    );
    db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.bom.add_line', ?, ?)", [current_user_id(), $parentId, "child=$childId qty=$qty"]);
    flash_set('success', 'Line added.');
    redirect($back);
}

if ($action === 'bom_line_update') {
    csrf_check();
    if (!$canManageBoms) {
        flash_set('error', 'No permission to manage BOMs.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $lineId   = (int)input('line_id', 0);
    $qty      = (float)input('qty', 0);
    $ref      = trim((string)input('ref_designator'));
    $notes    = trim((string)input('notes'));
    $sort     = (int)input('sort_order', 0);
    $line = db_one('SELECT * FROM inv_bom_lines WHERE id = ?', [$lineId]);
    if (!$line) {
        flash_set('error', 'Line not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    if ($qty <= 0) {
        flash_set('error', 'Quantity must be greater than zero.');
        redirect(url('/inventory.php?action=bom_edit&id=' . (int)$line['parent_item_id']));
    }
    db_exec(
        'UPDATE inv_bom_lines SET qty=?, ref_designator=?, notes=?, sort_order=? WHERE id = ?',
        [$qty, $ref ?: null, $notes ?: null, $sort, $lineId]
    );
    db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.bom.update_line', ?, ?)", [current_user_id(), $lineId, "qty=$qty"]);
    flash_set('success', 'Line updated.');
    redirect(url('/inventory.php?action=bom_edit&id=' . (int)$line['parent_item_id']));
}

if ($action === 'bom_line_delete') {
    csrf_check();
    if (!$canDeleteBoms && !$canManageBoms) {
        flash_set('error', 'No permission to delete BOM lines.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $lineId = (int)input('line_id', 0);
    $line = db_one('SELECT * FROM inv_bom_lines WHERE id = ?', [$lineId]);
    if (!$line) {
        flash_set('error', 'Line not found.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    db_exec('DELETE FROM inv_bom_lines WHERE id = ?', [$lineId]);
    db_exec("INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'inventory.bom.delete_line', ?, ?)", [current_user_id(), $lineId, '']);
    flash_set('success', 'Line removed.');
    redirect(url('/inventory.php?action=bom_edit&id=' . (int)$line['parent_item_id']));
}

// ============================================================
// BOM DELETE — remove entire BOM tree, with orphan item cleanup
// ============================================================
//
// Workflow:
//   1. GET  ?action=bom_delete_preview&id=N → shows the plan
//   2. POST ?action=bom_delete_commit       → executes it
//
// Scope: edges in the tree rooted at item N are always removed.
// Items in the tree are deleted ONLY when they're "orphans" w.r.t.
// other BOMs — i.e., no edges exist outside this tree that reference
// them (as parent or as child). The root item is treated the same
// way; if some OTHER BOM uses it (as a sub-assembly), it's kept.
//
// Item deletion requires inventory_view_items.delete in addition to
// inventory_view_boms.delete. If the user lacks items.delete, the
// edges are still removed but items are kept (degraded mode); the
// preview flags this so the user knows what to expect.

// ------------------------------------------------------------
// Walk the BOM tree rooted at $rootId, returning all distinct
// descendant item ids (including the root itself). Cycle-safe
// via a visited set (matches inv_tree() behaviour).
// ------------------------------------------------------------
function bom_delete_collect_tree_ids($rootId) {
    $rootId = (int)$rootId;
    $visited = [];
    $stack = [$rootId];
    while ($stack) {
        $cur = array_pop($stack);
        if (isset($visited[$cur])) continue;
        $visited[$cur] = true;
        $children = db_all(
            'SELECT DISTINCT child_item_id FROM inv_bom_lines WHERE parent_item_id = ?',
            [$cur]
        );
        foreach ($children as $c) {
            $cid = (int)$c['child_item_id'];
            if (!isset($visited[$cid])) $stack[] = $cid;
        }
    }
    return array_keys($visited);
}

// ------------------------------------------------------------
// External references on inv_items. Lists every other table that
// could prevent a DELETE FROM inv_items (i.e. tables with an FK
// pointing at inv_items.id where the on-delete action is RESTRICT
// or where deleting would otherwise be undesirable).
//
// inv_bom_lines is intentionally OMITTED here — the BOM-delete plan
// already accounts for tree edges separately. Pass the tree-edge
// internal references in via $internalBomLines to avoid double-
// counting the edges we're about to delete.
//
// Returns [] if no external references (safe to delete), otherwise
// keyed by table name → count.
// ------------------------------------------------------------
function bom_delete_item_external_refs($itemId) {
    $itemId = (int)$itemId;
    $refs = [];
    // (table, columns_that_reference_inv_items.id)
    // Columns listed here come from a sweep of all migrations for
    // `FOREIGN KEY ... REFERENCES inv_items(id)`. Tables with
    // ON DELETE CASCADE are also listed because we want to count
    // "this item is used elsewhere" even when the FK wouldn't
    // technically block — the user should know they're nuking
    // certs / vendor links / location stock when they delete.
    static $sources = [
        'ecn_affected_items'         => ['item_id'],
        'ecns'                       => ['successor_item_id'],
        'inv_item_certs'             => ['item_id'],
        'inv_item_vendors'           => ['item_id'],
        'inv_item_location_stock'    => ['item_id'],
        'inv_receipts'               => ['item_id'],
        'inv_shipment_lines'         => ['item_id'],
        'inv_shipment_receive_lines' => ['item_id'],
        'inv_shipments'              => ['target_item_id'],
        'inv_supersede_chain'        => ['from_item_id'],
        'inv_txns'                   => ['item_id'],
        // Self-FKs on inv_items (obsoleted_by / supersedes) use SET NULL,
        // won't block but ARE worth flagging so the user knows their
        // supersede chain will be cleared.
        'inv_items'                  => ['obsoleted_by_item_id', 'supersedes_item_id'],
    ];
    foreach ($sources as $table => $cols) {
        // Be defensive: the table might not exist yet on installations
        // that haven't applied all migrations. Wrap each count in a try.
        try {
            $where = implode(' OR ', array_map(function ($c) { return "$c = ?"; }, $cols));
            $params = array_fill(0, count($cols), $itemId);
            $n = (int)db_val("SELECT COUNT(*) FROM `$table` WHERE $where", $params);
            if ($n > 0) $refs[$table] = $n;
        } catch (Exception $e) {
            // Table doesn't exist on this install; skip silently
        }
    }
    return $refs;
}

// ------------------------------------------------------------
// Plan the delete. Returns:
//   'edges_in_tree'   - list of edge ids that will be deleted
//   'orphan_items'    - list of inv_items rows that will be deleted
//                       (no external references at all)
//   'shared_items'    - list of inv_items rows that will be KEPT
//                       (referenced by another BOM, an ECN, an
//                        inventory transaction, etc.)
//   'shared_reasons'  - keyed by item id, ['bom_edges'=>n,
//                       'ecn_affected_items'=>n, ...]
// ------------------------------------------------------------
function bom_delete_plan($rootId) {
    $rootId = (int)$rootId;
    $treeIds = bom_delete_collect_tree_ids($rootId);
    if (!$treeIds) {
        return [
            'tree_ids'       => [],
            'edges_in_tree'  => [],
            'orphan_items'   => [],
            'shared_items'   => [],
            'shared_reasons' => [],
        ];
    }
    $placeholders = implode(',', array_fill(0, count($treeIds), '?'));
    $edgesInTree = db_all(
        "SELECT id FROM inv_bom_lines WHERE parent_item_id IN ($placeholders)",
        $treeIds
    );
    $edgesInTreeIds = array_map(function ($e) { return (int)$e['id']; }, $edgesInTree);

    $orphanItems  = [];
    $sharedItems  = [];
    $sharedReason = [];
    foreach ($treeIds as $itemId) {
        $reasons = [];

        // (1) BOM references outside this tree
        $totalBomRefs = (int)db_val(
            'SELECT COUNT(*) FROM inv_bom_lines WHERE parent_item_id = ? OR child_item_id = ?',
            [$itemId, $itemId]
        );
        $internalAsParent = (int)db_val(
            'SELECT COUNT(*) FROM inv_bom_lines WHERE parent_item_id = ?',
            [$itemId]
        );
        $internalAsChild = (int)db_val(
            "SELECT COUNT(*) FROM inv_bom_lines WHERE child_item_id = ?
              AND parent_item_id IN ($placeholders)",
            array_merge([$itemId], $treeIds)
        );
        $externalBomRefs = $totalBomRefs - $internalAsParent - $internalAsChild;
        if ($externalBomRefs > 0) $reasons['inv_bom_lines (other BOMs)'] = $externalBomRefs;

        // (2) Every OTHER table that references inv_items
        $otherRefs = bom_delete_item_external_refs($itemId);
        foreach ($otherRefs as $tbl => $n) $reasons[$tbl] = $n;

        $itemRow = db_one('SELECT id, code, name, short_description FROM inv_items WHERE id = ?', [$itemId]);
        if (!$itemRow) continue;
        if (empty($reasons)) {
            $orphanItems[] = $itemRow;
        } else {
            $sharedItems[] = $itemRow;
            $sharedReason[$itemId] = $reasons;
        }
    }
    return [
        'tree_ids'       => $treeIds,
        'edges_in_tree'  => $edgesInTreeIds,
        'orphan_items'   => $orphanItems,
        'shared_items'   => $sharedItems,
        'shared_reasons' => $sharedReason,
    ];
}

// ------------------------------------------------------------
// PREVIEW: show what will be removed.
// ------------------------------------------------------------
if ($action === 'bom_delete_preview') {
    if (!$canDeleteBoms && !$canManageBoms) {
        require_permission('inventory_view_boms', 'delete');
    }
    $id   = (int)input('id', 0);
    $root = db_one('SELECT id, code, name, short_description FROM inv_items WHERE id = ?', [$id]);
    if (!$root) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=boms'));
    }
    $plan = bom_delete_plan($id);
    if (empty($plan['edges_in_tree'])) {
        flash_set('error', 'This item has no BOM to delete (no edges found).');
        redirect(url('/inventory.php?action=bom_view&id=' . $id));
    }

    $canDeleteOrphans = $canDeleteItems || $canManageItems;

    $page_title  = 'Delete BOM · ' . ($root['short_description'] ?: $root['name']);
    $page_module = 'inventory_view_boms';
    require dirname(__DIR__, 2) . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>Delete BOM: <?= h($root['short_description'] ?: $root['name']) ?>
                <span class="muted small mono"><?= h($root['code']) ?></span></h1>
            <p class="muted">
                This removes the BOM structure rooted at this item. Items that aren't used
                by any other BOM are also removed (orphan cleanup); items shared with other
                BOMs are kept.
            </p>
        </div>
    </div>

    <div class="import-summary" style="margin-bottom: 14px;">
        <span class="pill pill-danger">✗ Edges to remove: <?= count($plan['edges_in_tree']) ?></span>
        <span class="pill pill-danger">✗ Orphan items to remove: <?= count($plan['orphan_items']) ?></span>
        <span class="pill pill-neutral">⊙ Items kept (shared): <?= count($plan['shared_items']) ?></span>
    </div>

    <?php if (!$canDeleteOrphans && !empty($plan['orphan_items'])): ?>
        <div class="card" style="margin-bottom: 14px; border-left: 3px solid #b88500;">
            <div class="card-body" style="padding: 12px 14px;">
                <strong style="color: #b88500;">Partial delete:</strong>
                You don't have <code>inventory_view_items.delete</code> permission, so the
                <?= count($plan['orphan_items']) ?> orphan items will be KEPT in inv_items
                with no BOM. Only the <?= count($plan['edges_in_tree']) ?> edges will be removed.
                Ask an admin to grant items.delete if you want the orphan cleanup.
            </div>
        </div>
    <?php endif; ?>

    <div class="import-actions" style="margin-bottom: 18px;">
        <form method="post" action="<?= h(url('/inventory.php?action=bom_delete_commit')) ?>" style="display:inline"
              onsubmit="return confirm('Permanently remove <?= count($plan['edges_in_tree']) ?> BOM edges<?= ($canDeleteOrphans && !empty($plan['orphan_items'])) ? ' and ' . count($plan['orphan_items']) . ' orphan items' : '' ?>? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <button type="submit" class="btn btn-danger">
                Delete <?= count($plan['edges_in_tree']) ?> edge<?= count($plan['edges_in_tree']) === 1 ? '' : 's' ?><?php if ($canDeleteOrphans && $plan['orphan_items']): ?>
                + <?= count($plan['orphan_items']) ?> orphan item<?= count($plan['orphan_items']) === 1 ? '' : 's' ?><?php endif; ?>
            </button>
        </form>
        <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=bom_view&id=' . $id)) ?>">Cancel</a>
    </div>

    <?php if ($plan['orphan_items']): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head">
                <h3 style="margin:0;font-size:15px;">Orphan items to remove (<?= count($plan['orphan_items']) ?>)</h3>
            </div>
            <div class="card-body" style="padding:0">
                <table class="data-table">
                    <thead><tr><th>Code</th><th>Name</th></tr></thead>
                    <tbody>
                        <?php foreach ($plan['orphan_items'] as $it): ?>
                            <tr>
                                <td><code><?= h($it['code']) ?></code></td>
                                <td><?= h($it['short_description'] ?: $it['name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($plan['shared_items']): ?>
        <div class="card" style="margin-bottom: 18px;">
            <div class="card-head">
                <h3 style="margin:0;font-size:15px;">Items kept (referenced elsewhere) — <?= count($plan['shared_items']) ?></h3>
            </div>
            <div class="card-body" style="padding:0">
                <table class="data-table">
                    <thead><tr><th>Code</th><th>Name</th><th>Why it's kept</th></tr></thead>
                    <tbody>
                        <?php foreach ($plan['shared_items'] as $it):
                            $reasons = $plan['shared_reasons'][$it['id']] ?? [];
                            $bits = [];
                            foreach ($reasons as $tbl => $n) {
                                $bits[] = h($tbl) . ' (' . (int)$n . ')';
                            }
                        ?>
                            <tr>
                                <td><code><?= h($it['code']) ?></code></td>
                                <td><?= h($it['short_description'] ?: $it['name']) ?></td>
                                <td class="muted small"><?= $bits ? implode(', ', $bits) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php
    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

// ------------------------------------------------------------
// COMMIT: execute the delete plan in one transaction.
// ------------------------------------------------------------
if ($action === 'bom_delete_commit') {
    if (!$canDeleteBoms && !$canManageBoms) {
        require_permission('inventory_view_boms', 'delete');
    }
    csrf_check();
    $id   = (int)input('id', 0);
    $root = db_one('SELECT id, code, name FROM inv_items WHERE id = ?', [$id]);
    if (!$root) {
        flash_set('error', 'Item not found.');
        redirect(url('/inventory.php?action=boms'));
    }
    $plan = bom_delete_plan($id);
    if (empty($plan['edges_in_tree'])) {
        flash_set('error', 'No BOM to delete.');
        redirect(url('/inventory.php?action=bom_view&id=' . $id));
    }
    $canDeleteOrphans = $canDeleteItems || $canManageItems;

    db_exec('START TRANSACTION');
    try {
        // 1. Delete the edges
        $edgePlaceholders = implode(',', array_fill(0, count($plan['edges_in_tree']), '?'));
        db_exec(
            "DELETE FROM inv_bom_lines WHERE id IN ($edgePlaceholders)",
            $plan['edges_in_tree']
        );
        $edgesDeleted = count($plan['edges_in_tree']);

        // 2. Delete orphan items if the user has the perm. Each item
        //    delete is wrapped in a savepoint so an unexpected FK
        //    violation (e.g. a reference in a table the planner didn't
        //    know about) doesn't poison the whole transaction. The
        //    failing item just gets skipped and reported as kept.
        $itemsDeleted   = 0;
        $itemsSkippedFk = [];     // [id => exception message]
        if ($canDeleteOrphans && !empty($plan['orphan_items'])) {
            foreach ($plan['orphan_items'] as $it) {
                $oid = (int)$it['id'];
                $spName = 'sp_item_' . $oid;
                db_exec("SAVEPOINT $spName");
                try {
                    db_exec('DELETE FROM inv_items WHERE id = ?', [$oid]);
                    $itemsDeleted++;
                } catch (Exception $e) {
                    db_exec("ROLLBACK TO SAVEPOINT $spName");
                    $itemsSkippedFk[$oid] = $e->getMessage();
                }
            }
        }

        db_exec('COMMIT');
        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details)
             VALUES (?, 'inventory.bom.delete_all', ?, ?)",
            [current_user_id(), $id, json_encode([
                'edges_deleted'    => $edgesDeleted,
                'items_deleted'    => $itemsDeleted,
                'items_fk_skipped' => count($itemsSkippedFk),
                'shared_kept'      => count($plan['shared_items']),
                'root_code'        => $root['code'],
            ])]
        );
        $msg = sprintf(
            'BOM deleted · %d edge%s removed · %d orphan item%s removed · %d shared item%s kept',
            $edgesDeleted, $edgesDeleted === 1 ? '' : 's',
            $itemsDeleted, $itemsDeleted === 1 ? '' : 's',
            count($plan['shared_items']),
            count($plan['shared_items']) === 1 ? '' : 's'
        );
        if ($itemsSkippedFk) {
            $msg .= sprintf(' · %d item%s could not be deleted (FK references from tables not in the planner; kept)',
                count($itemsSkippedFk), count($itemsSkippedFk) === 1 ? '' : 's');
        }
        flash_set('success', $msg);

        // If the root item itself got deleted as an orphan, redirect to
        // the BOM list. Otherwise back to the (now-childless) view page.
        $rootStillExists = db_val('SELECT id FROM inv_items WHERE id = ?', [$id]);
        if ($rootStillExists) {
            redirect(url('/inventory.php?action=bom_view&id=' . $id));
        }
        redirect(url('/inventory.php?action=boms'));
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        flash_set('error', 'Delete failed: ' . $e->getMessage());
        redirect(url('/inventory.php?action=bom_view&id=' . $id));
    }
}
