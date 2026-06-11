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

        // Shared component — same code already seen earlier in the CSV.
        // Don't create the item again; just record the extra parent→child
        // edge and keep the depth path live so any children of this
        // re-used component still resolve correctly.
        if (isset($items[$code])) {
            // Update depth path so children nest under this occurrence.
            $depthPath[$depth] = $code;
            foreach (array_keys($depthPath) as $d) {
                if ($d > $depth) unset($depthPath[$d]);
            }
            // Resolve parent from depth path BEFORE the update above.
            if ($depth > 0 && isset($depthPath[$depth - 1])) {
                $sharedParent = $depthPath[$depth - 1];
                $parentChildren = isset($items[$sharedParent]['tree_child_field'])
                    ? bom_import_parse_tree_child_field($items[$sharedParent]['tree_child_field'])
                    : [];
                $qty = isset($parentChildren[$code]) ? $parentChildren[$code] : $parsed['qty_row'];
                if ($qty <= 0) $qty = 1.0;
                if (!isset($childCounter[$sharedParent])) $childCounter[$sharedParent] = 0;
                $childCounter[$sharedParent] += 10;
                $edges[] = [
                    'parent_code' => $sharedParent,
                    'child_code'  => $code,
                    'qty'         => $qty,
                    'sort_order'  => $childCounter[$sharedParent],
                    'line'        => $lineNo,
                ];
            }
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

    // Deduplicate edges: keep the first occurrence of each (parent, child) pair.
    // This handles shared components whose subtrees are traversed multiple times
    // in the CSV (e.g. a component used by two different parent assemblies).
    $uniqueEdges = [];
    $edgeSeen    = [];
    foreach ($edges as $edge) {
        $key = $edge['parent_code'] . "\x00" . $edge['child_code'];
        if (!isset($edgeSeen[$key])) {
            $edgeSeen[$key] = true;
            $uniqueEdges[]  = $edge;
        }
    }

    return ['items' => $items, 'edges' => $uniqueEdges, 'row_errors' => $rowErrors];
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
// Insert ONE new inv_items row from a parsed item. Returns the new id.
// Shared by the single-shot (bom_import_commit_hierarchical) and the
// batched (bom_batch_commit_items) commit paths so the column list lives
// in exactly one place.
// ------------------------------------------------------------
function bom_import_insert_one_item($code, array $it, $fks, array &$divCache, array &$divCreatedNow) {
    $minStock = is_numeric($it['min_stock_level']) ? (float)$it['min_stock_level'] : null;
    $minOrder = is_numeric($it['min_order_qty'])  ? (float)$it['min_order_qty']  : null;
    // Resolve (or auto-create) the division for this row. Empty I_Division
    // resolves to a fallback '__unknown' division so the FK stays valid.
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
    return (int)db_val('SELECT LAST_INSERT_ID()');
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
            $codeToId[$code] = bom_import_insert_one_item($code, $it, $fks, $divCache, $divCreatedNow);
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
function bom_import_render_preview_hier($title, $token, $upsert, array $items, array $edgeResult, $commitUrl, $cancelUrl, array $divisionExists = [], $batched = false) {
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

    <?php if ($batched):
        // Enable if there is anything to do: new items, edges, OR stock to post
        // (a re-import may have all items "reuse" but still carry stock).
        $hasStockData = false;
        foreach ($items as $___it) {
            if (!empty($___it['stock_locations'])) { $hasStockData = true; break; }
        }
        $disabled = (($totalEdges + count($itemsCreate)) === 0) && !$hasStockData;
    ?>
    <div class="import-actions" id="bomBatchActions" style="margin-bottom: 18px;"
         data-commit-url="<?= h($commitUrl) ?>"
         data-cancel-url="<?= h($cancelUrl) ?>"
         data-token="<?= h($token) ?>"
         data-upsert="<?= $upsert ? '1' : '0' ?>"
         data-csrf-field="<?= h($GLOBALS['APP']['csrf_field']) ?>"
         data-csrf="<?= h(csrf_token()) ?>">
        <button type="button" class="btn btn-primary" id="bomBatchStart" <?= $disabled ? 'disabled' : '' ?>>
            Start import — <?= count($itemsCreate) ?> new item<?= count($itemsCreate) === 1 ? '' : 's' ?>
            + <?= $totalEdges ?> edge<?= $totalEdges === 1 ? '' : 's' ?>
        </button>
        <a class="btn btn-ghost" id="bomBatchCancel" href="<?= h($cancelUrl) ?>">Cancel</a>
        <?php if ($hasErrors): ?>
            <span class="muted small" style="margin-left: 12px;">Red edges will be skipped on commit.</span>
        <?php endif; ?>
    </div>

    <div id="bomBatchProgress" class="card" style="display:none; margin-bottom:18px;">
        <div class="card-body">
            <?php
            $bars = ['items' => 'Items', 'edges' => 'BOM edges', 'stock' => 'Stock quantities'];
            foreach ($bars as $pk => $plabel): ?>
            <div class="bom-prog-row" data-phase="<?= $pk ?>" style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                    <strong><?= h($plabel) ?></strong>
                    <span class="bom-prog-count" style="font-variant-numeric:tabular-nums;color:#555;">– / –</span>
                </div>
                <div style="height:14px;background:#eee;border-radius:7px;overflow:hidden;">
                    <div class="bom-prog-fill" style="height:100%;width:0%;background:#2e7d32;transition:width .2s ease;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <div id="bomBatchStatus" class="small" style="margin-top:10px;color:#555;"></div>
            <div id="bomBatchLiveStats" class="small muted" style="margin-top:4px;"></div>
        </div>
    </div>

    <script>
    (function () {
        var box = document.getElementById('bomBatchActions');
        if (!box) return;
        var startBtn = document.getElementById('bomBatchStart');
        var cancelLink = document.getElementById('bomBatchCancel');
        var prog = document.getElementById('bomBatchProgress');
        var statusEl = document.getElementById('bomBatchStatus');
        var liveEl = document.getElementById('bomBatchLiveStats');

        var url       = box.getAttribute('data-commit-url');
        var token     = box.getAttribute('data-token');
        var upsert    = box.getAttribute('data-upsert');
        var csrfField = box.getAttribute('data-csrf-field');
        var csrf      = box.getAttribute('data-csrf');

        var phases = ['items', 'edges', 'stock'];

        function setBar(phase, done, total) {
            var row = prog.querySelector('.bom-prog-row[data-phase="' + phase + '"]');
            if (!row) return;
            var pct = total > 0 ? Math.round((done / total) * 100) : 100;
            row.querySelector('.bom-prog-fill').style.width = pct + '%';
            row.querySelector('.bom-prog-count').textContent = done + ' / ' + total + ' (' + pct + '%)';
        }
        function setStatus(msg, isErr) {
            statusEl.textContent = msg;
            statusEl.style.color = isErr ? '#b3261e' : '#555';
        }
        function showStats(s) {
            if (!s) return;
            liveEl.textContent =
                'items: ' + s.items_created + ' created / ' + s.items_reused + ' reused · ' +
                'edges: ' + s.edges_inserted + ' inserted / ' + s.edges_updated + ' updated · ' +
                'stock: ' + s.stocks_imported + ' set';
        }

        function runBatch(phase, offset) {
            var body = new URLSearchParams();
            body.set('token', token);
            body.set('upsert', upsert);
            body.set('phase', phase);
            body.set('offset', offset);
            body.set(csrfField, csrf);
            return fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (res) {
                return res.text().then(function (text) {
                    var data;
                    try { data = JSON.parse(text); }
                    catch (e) { throw new Error('Server returned non-JSON (HTTP ' + res.status + '): ' + text.slice(0, 200)); }
                    if (!data.ok) throw new Error(data.error || 'Unknown server error');
                    return data;
                });
            });
        }

        function drivePhase(pi) {
            var phase = phases[pi];
            function loop(offset) {
                return runBatch(phase, offset).then(function (d) {
                    setBar(d.phase, d.done, d.total);
                    showStats(d.stats);
                    if (d.all_done) { finish(d); return 'DONE'; }
                    if (d.phase_done) return 'PHASE';
                    return loop(d.next_offset);
                });
            }
            setStatus('Importing ' + phase + '…');
            return loop(0);
        }

        function driveAll() {
            function next(pi) {
                if (pi >= phases.length) return;
                return drivePhase(pi).then(function (r) {
                    if (r === 'DONE') return;
                    return next(pi + 1);
                });
            }
            return next(0);
        }

        function finish(d) {
            // All three phase bars are already filled by the loop's setBar calls.
            setStatus('✓ ' + (d.summary || 'Import complete.') + ' Redirecting…');
            if (d.redirect) setTimeout(function () { window.location.href = d.redirect; }, 1200);
        }

        startBtn.addEventListener('click', function () {
            startBtn.disabled = true;
            startBtn.textContent = 'Importing…';
            cancelLink.style.display = 'none';
            prog.style.display = '';
            driveAll().catch(function (e) {
                setStatus('Error: ' + e.message + ' — fix and retry.', true);
                startBtn.disabled = false;
                startBtn.textContent = 'Retry import';
                cancelLink.style.display = '';
            });
        });
    })();
    </script>
    <?php else: ?>
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
    <?php endif; ?>

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

// ============================================================
// BOM auto-import from old inventory server (tree7-5.php)
// JSON-based: fetches structured items+edges, no CSV pipeline.
// ============================================================

// ------------------------------------------------------------
// Convert the JSON response from tree7-5.php into the same
// $parsedH structure that bom_import_resolve_edges / commit
// functions expect.  No CSV parsing needed.
// ------------------------------------------------------------
function bom_import_parse_json_response(array $jsonData) {
    $items     = [];
    $edges     = [];
    $rowErrors = [];

    $itemList = isset($jsonData['items']) && is_array($jsonData['items'])
        ? $jsonData['items'] : [];
    $edgeList = isset($jsonData['edges']) && is_array($jsonData['edges'])
        ? $jsonData['edges'] : [];

    // ── items ────────────────────────────────────────────────
    foreach ($itemList as $idx => $jItem) {
        $code = trim((string)(isset($jItem['code']) ? $jItem['code'] : ''));
        if ($code === '') {
            $rowErrors[] = ['line' => $idx + 2, 'reason' => 'Item missing code field'];
            continue;
        }
        $legacyCatId = trim((string)(isset($jItem['category_id']) ? $jItem['category_id'] : ''));
        $catCode     = bom_import_category_code_for_legacy_id($legacyCatId);
        $existing    = db_one('SELECT id FROM inv_items WHERE code = ?', [$code]);
        $isRoot      = !empty($jItem['is_root']);

        $items[$code] = [
            'action'           => $existing ? 'reuse' : 'create',
            'line'             => $idx + 2,
            'existing_id'      => $existing ? (int)$existing['id'] : null,
            'code'             => $code,
            'name'             => trim((string)(isset($jItem['name'])
                                    ? $jItem['name'] : '')) ?: $code,
            'long_description' => trim((string)(isset($jItem['long_description'])
                                    ? $jItem['long_description'] : '')),
            'dwg_no'           => trim((string)(isset($jItem['dwg_no'])
                                    ? $jItem['dwg_no'] : '')),
            'dwg_rev_no'       => trim((string)(isset($jItem['rev_no'])
                                    ? $jItem['rev_no'] : '')),
            'part_no'          => trim((string)(isset($jItem['part_no'])
                                    ? $jItem['part_no'] : '')),
            'process_spec'     => trim((string)(isset($jItem['process_spec'])
                                    ? $jItem['process_spec'] : '')),
            'material_spec'    => trim((string)(isset($jItem['material_spec'])
                                    ? $jItem['material_spec'] : '')),
            'min_stock_level'  => trim((string)(isset($jItem['min_stock_level'])
                                    ? $jItem['min_stock_level'] : '')),
            'min_order_qty'    => trim((string)(isset($jItem['min_order_qty'])
                                    ? $jItem['min_order_qty'] : '')),
            'category_code'    => $catCode,
            'division_name'    => trim((string)(isset($jItem['i_division'])
                                    ? $jItem['i_division'] : '')),
            'depth'            => 0,   // not depth-encoded in JSON; 0 is display-only
            'is_root'          => $isRoot,
            'tree_child_field' => '',  // not needed — edges are explicit in JSON
            // stock_locations: [{location: "Magdyn", qty: 43.27}, ...]
            // Populated when old server returns inventory_location data.
            // Only used on commit for newly-created items (reused items keep
            // their existing MagDyn stock history untouched).
            'stock_locations'  => (isset($jItem['stock_locations']) && is_array($jItem['stock_locations']))
                                  ? $jItem['stock_locations'] : [],
        ];
    }

    // ── edges ────────────────────────────────────────────────
    $edgeSeen = [];
    foreach ($edgeList as $idx => $jEdge) {
        $pCode = trim((string)(isset($jEdge['parent_code']) ? $jEdge['parent_code'] : ''));
        $cCode = trim((string)(isset($jEdge['child_code'])  ? $jEdge['child_code']  : ''));
        if ($pCode === '' || $cCode === '') continue;

        $key = $pCode . "\x00" . $cCode;
        if (isset($edgeSeen[$key])) continue;
        $edgeSeen[$key] = true;

        $qty  = max(0.001, (float)(isset($jEdge['qty']) ? $jEdge['qty'] : 1.0));
        $sort = (int)(isset($jEdge['sort_order']) ? $jEdge['sort_order'] : (($idx + 1) * 10));

        $edges[] = [
            'parent_code' => $pCode,
            'child_code'  => $cCode,
            'qty'         => $qty,
            'sort_order'  => $sort,
            'line'        => $idx + 2,
        ];
    }

    return ['items' => $items, 'edges' => $edges, 'row_errors' => $rowErrors];
}

// ── shared helper: derive a MagDyn location code from an old-system name ─────
// "Magdyn" → "MAGDYN"   "To Be Received" → "TO_BE_RECEIVED"
// Uppercase, spaces → underscores, non-alphanumeric stripped, max 40 chars.
function bom_import_location_code($name) {
    $code = strtoupper(str_replace(' ', '_', trim((string)$name)));
    $code = preg_replace('/[^A-Z0-9_]/', '', $code);
    return substr($code, 0, 40);
}

// ── shared helper: apply old-system → MagDyn location name aliases ───────────
// Maps known old-system location names to their canonical MagDyn equivalents.
// Normalises spaces/hyphens to underscores before comparing so "Rejection Return"
// and "Rejection_Return" both hit the same alias entry.
// Returns the mapped MagDyn name, or the original name if no alias matches.
function bom_import_resolve_location_alias($name) {
    static $aliases = [
        'rejection_return' => 'Rej',
        'to_be_received'   => 'TBR',
    ];
    $key = strtolower(str_replace([' ', '-'], '_', trim((string)$name)));
    return isset($aliases[$key]) ? $aliases[$key] : $name;
}

// ── shared helper: is this old-system location eligible for stock import? ─────
// Only stock held in the "Magdyn" (In-Hand) location is brought into MagDyn.
// Other old-system locations such as "Rejection Return" and "To Be Received"
// are intentionally ignored.  Used by both the preview and the commit so the
// two never diverge.  Add more names here to widen the whitelist.
function bom_import_stock_location_allowed($name) {
    static $allowed = ['magdyn'];
    $key = strtolower(trim((string)$name));
    return in_array($key, $allowed, true);
}

// ── shared helper: find or auto-create a MagDyn location by name ─────────────
// Matching priority (most-to-least specific):
//   1. Derived code exact match  (e.g. "MAGDYN" in locations.code)
//   2. Name exact match          (e.g. "Magdyn" in locations.name)
//   3. Name case-insensitive     (e.g. "magdyn" = "Magdyn")
//   4. Auto-create with derived code + original name
//
// Old-system name aliases (e.g. "Rejection_Return" → "Rej") are applied first
// via bom_import_resolve_location_alias() so they resolve to the correct
// existing MagDyn location rather than creating a duplicate.
//
// Returns the location id, or null if $name is empty.
// $cache is passed by reference so repeated calls within one import
// don't hit the DB for every row.
function bom_import_find_or_create_location($name, &$cache) {
    $name = trim((string)$name);
    if ($name === '') return null;

    $name = bom_import_resolve_location_alias($name);

    $code = bom_import_location_code($name);
    if ($code === '') return null;

    if (isset($cache[$code])) return $cache[$code];

    // 1. Derived code exact
    $row = db_one('SELECT id FROM locations WHERE code = ?', [$code]);
    // 2. Name exact
    if (!$row) $row = db_one('SELECT id FROM locations WHERE name = ?', [$name]);
    // 3. Name case-insensitive (LOWER comparison)
    if (!$row) $row = db_one('SELECT id FROM locations WHERE LOWER(name) = LOWER(?)', [$name]);

    if ($row) {
        $cache[$code] = (int)$row['id'];
        return $cache[$code];
    }

    // 4. Auto-create — derived code, original name, sort_order=200, active
    db_exec(
        'INSERT INTO locations (code, name, sort_order, is_active, created_at)
         VALUES (?, ?, 200, 1, NOW())',
        [$code, $name]
    );
    $id = (int)db_val('SELECT LAST_INSERT_ID()');
    $cache[$code] = $id;
    return $id;
}

// ── shared helper: resolve all distinct location names from stock data ─────────
// Returns a map keyed by location name:
//   [
//     'Magdyn' => [
//         'code'       => 'MAGDYN',
//         'id'         => 3,          // or null if not found
//         'status'     => 'exists',   // 'exists' | 'will_create'
//         'item_count' => 14,
//         'entry_count'=> 14,
//     ],
//     'To Be Received' => [...],
//   ]
// Does NOT modify the database — read-only lookup for preview.
function bom_import_preview_locations(array $items) {
    $map = []; // name => [code, item_count, entry_count]
    foreach ($items as $it) {
        if (empty($it['stock_locations'])) continue;
        $counted = [];
        foreach ($it['stock_locations'] as $sl) {
            $locName = trim((string)(isset($sl['location']) ? $sl['location'] : ''));
            if ($locName === '') continue;
            // Only preview locations that will actually be imported (Magdyn).
            // "Rejection Return", "To Be Received", etc. are skipped at commit
            // time, so they must not appear in the preview either.
            if (!bom_import_stock_location_allowed($locName)) continue;
            $locName = bom_import_resolve_location_alias($locName);
            if (!isset($map[$locName])) {
                $map[$locName] = [
                    'code'        => bom_import_location_code($locName),
                    'item_count'  => 0,
                    'entry_count' => 0,
                ];
            }
            if (!isset($counted[$locName])) {
                $counted[$locName] = true;
                $map[$locName]['item_count']++;
            }
            $map[$locName]['entry_count']++;
        }
    }

    // Now resolve each against the DB (read-only)
    foreach ($map as $locName => &$info) {
        $code = $info['code'];
        $row  = null;
        if ($code !== '') $row = db_one('SELECT id FROM locations WHERE code = ?', [$code]);
        if (!$row)        $row = db_one('SELECT id FROM locations WHERE name = ?', [$locName]);
        if (!$row)        $row = db_one('SELECT id FROM locations WHERE LOWER(name) = LOWER(?)', [$locName]);

        $info['id']     = $row ? (int)$row['id'] : null;
        $info['status'] = $row ? 'exists' : 'will_create';
    }
    unset($info);

    // Sort: existing first, then will_create; alpha within each group
    $names = array_keys($map);
    usort($names, function ($a, $b) use ($map) {
        $sa = $map[$a]['status'];
        $sb = $map[$b]['status'];
        if ($sa !== $sb) return $sa === 'exists' ? -1 : 1;
        return strcasecmp($a, $b);
    });
    $sorted = [];
    foreach ($names as $n) $sorted[$n] = $map[$n];

    return $sorted;
}

// ── shared helper: make sure the stock-related stat keys exist ───────────────
function bom_old_import_stock_init_stats(array &$stats) {
    foreach ([
        'stocks_imported', 'stocks_items_with_data', 'stocks_zero_skip',
        'stocks_has_stock_skip', 'stocks_location_skip',
    ] as $k) {
        if (!isset($stats[$k])) $stats[$k] = 0;
    }
    if (!isset($stats['stock_errors']) || !is_array($stats['stock_errors'])) {
        $stats['stock_errors'] = [];
    }
}

// ── shared helper: post old-system stock for a SUBSET of item codes ──────────
// This is the unit of work for the batched importer.  Stats are ACCUMULATED
// (+=) so the function can be called repeatedly over successive slices.
//
// Per-location guard:
//   • current stock = 0  → post 'receive' to set the old-system qty
//   • current stock > 0  → skip (item already has live MagDyn stock)
//
// LOCATION FILTER: only stock held in the "Magdyn" (In-Hand) location is
// imported.  Other old-system locations ("Rejection Return", "To Be Received",
// …) are intentionally ignored — see bom_import_stock_location_allowed().
//
// $items   — full code => item map (carries stock_locations)
// $codes   — the slice of item codes to process this call
// $locCache, $txnDate — passed in so they persist across slices
function bom_old_import_commit_stock_slice(array $items, array $codes, array &$stats, array &$locCache, $txnDate) {
    bom_old_import_stock_init_stats($stats);

    // Stock update removed from the BOM import.
    //
    // The BOM import used to post an opening-balance 'receive' txn per item
    // (inv_post_txn → inv_item_location_stock + an inv_txns row tagged
    // 'old-system-import'). That collided with the inventory-transaction
    // import, which now owns the inv_txns ledger and writes rows with their
    // original old-system IDs. To keep stock a single source of truth and
    // free the inv_txns ID space, the BOM import no longer touches stock.
    //
    // Kept as a no-op (rather than deleted) so the batched / single-shot
    // callers and the progress UI keep their phase structure unchanged.
    $stats['stocks_disabled'] = true;
}

// ── shared helper: post initial stock from old-system data (single-shot) ─────
// Called AFTER bom_import_commit_hierarchical() succeeds in the non-batched
// path. Delegates to the slice function over EVERY item code at once.
function bom_old_import_commit_stock(array $items, array &$stats) {
    require_once dirname(__DIR__, 2) . '/includes/_inventory_txn.php';
    bom_old_import_stock_init_stats($stats);
    $locCache = [];
    $txnDate  = date('Y-m-d H:i:s');
    bom_old_import_commit_stock_slice($items, array_keys($items), $stats, $locCache, $txnDate);
}

// ============================================================
// BATCHED OLD-SYSTEM IMPORT
//   The single-shot commit (bom_old_import_commit) does everything in one
//   request, which times out on large trees. The batched path splits the work
//   into small slices driven by JS so each HTTP request stays short, and feeds
//   a progress bar.
//
//   Work is processed in three phases, in order: items → edges → stock.
//   A "plan" (parsed items, resolved edges, FKs, running stats) is built once
//   and parked in the session, keyed by the same stash token. Every batch is
//   its own DB transaction and is retry-safe (existence checks), so a partial
//   import can be safely resumed / retried.
// ============================================================

// Batch sizes per phase — DB ops per HTTP request. Tuned to stay well under
// PHP's max_execution_time even on slow hardware.
if (!defined('BOM_BATCH_ITEMS')) define('BOM_BATCH_ITEMS', 200);
if (!defined('BOM_BATCH_EDGES')) define('BOM_BATCH_EDGES', 200);
if (!defined('BOM_BATCH_STOCK')) define('BOM_BATCH_STOCK', 80);

// ── plan storage (session-backed, keyed by the stash token) ──────────────────
function bom_batch_plan_load($token) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $k = '__bom_import_plan_' . $token;
    if (empty($_SESSION[$k]) || !is_array($_SESSION[$k])) return null;
    if (time() - (int)$_SESSION[$k]['stamped'] > 3600) { unset($_SESSION[$k]); return null; }
    return $_SESSION[$k];
}
function bom_batch_plan_save($token, array $plan) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $plan['stamped'] = time();
    $_SESSION['__bom_import_plan_' . $token] = $plan;
}
function bom_batch_plan_clear($token) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    unset($_SESSION['__bom_import_plan_' . $token]);
}

// ── resolve an item code → id with a per-call cache ──────────────────────────
function bom_batch_item_id($code, array &$cache) {
    if ($code === null || $code === '') return null;
    if (array_key_exists($code, $cache)) return $cache[$code];
    $id = (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$code], 0);
    $cache[$code] = $id > 0 ? $id : null;
    return $cache[$code];
}

// ── phase worker: create items for one slice. Returns # of codes consumed. ───
function bom_batch_commit_items(array $plan, $offset, array &$stats) {
    $codes = array_slice($plan['item_codes'], $offset, BOM_BATCH_ITEMS);
    if (empty($codes)) return 0;
    $fks = $plan['fks'];

    db_exec('START TRANSACTION');
    try {
        $divCache      = [];
        $divCreatedNow = [];
        $createdIds    = [];
        foreach ($codes as $code) {
            $it = isset($plan['items'][$code]) ? $plan['items'][$code] : null;
            if ($it === null) continue;
            if ($it['action'] === 'reuse') { $stats['items_reused']++; continue; }
            // Retry-safe: if a prior (partial) run already created it, reuse.
            $existing = (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$code], 0);
            if ($existing > 0) { $stats['items_reused']++; continue; }
            $createdIds[] = bom_import_insert_one_item($code, $it, $fks, $divCache, $divCreatedNow);
            $stats['items_created']++;
        }
        $stats['divisions_created'] += count($divCreatedNow);
        $stats['divisions_created_names'] = array_values(array_unique(array_merge(
            isset($stats['divisions_created_names']) ? $stats['divisions_created_names'] : [],
            array_keys($divCreatedNow)
        )));
        db_exec('COMMIT');
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        throw $e;
    }

    // Mirror newly-created finished goods to billing (best-effort, post-commit).
    if (function_exists('billing_product_push_if_needed')) {
        foreach ($createdIds as $iid) {
            try {
                billing_product_push_if_needed((int)$iid, function_exists('current_user_id') ? current_user_id() : null);
            } catch (Exception $e) { /* never let billing break the import */ }
        }
    }
    return count($codes);
}

// ── phase worker: write edges for one slice. Returns # of rows consumed. ─────
function bom_batch_commit_edges(array $plan, $offset, array &$stats) {
    $slice = array_slice($plan['edges'], $offset, BOM_BATCH_EDGES);
    if (empty($slice)) return 0;

    db_exec('START TRANSACTION');
    try {
        $idCache = [];
        foreach ($slice as $er) {
            if (!in_array($er['status'], ['insert', 'update'], true)) continue;
            $d   = $er['data'];
            $pId = $d['parent_id'] !== null ? (int)$d['parent_id'] : bom_batch_item_id($d['parent_code'], $idCache);
            $cId = $d['child_id']  !== null ? (int)$d['child_id']  : bom_batch_item_id($d['child_code'], $idCache);
            if (!$pId || !$cId) {
                $stats['edge_errors'][] = 'Edge ' . $d['parent_code'] . ' → ' . $d['child_code'] . ': item id not found';
                continue;
            }
            if ($er['status'] === 'update') {
                db_exec(
                    'UPDATE inv_bom_lines SET qty=?, sort_order=?, ref_designator=NULL, notes=NULL WHERE id=?',
                    [$d['qty'], $d['sort_order'], (int)$er['existing_id']]
                );
                $stats['edges_updated']++;
            } else {
                // Retry-safe: skip if this edge already exists.
                $exists = (int)db_val(
                    'SELECT id FROM inv_bom_lines WHERE parent_item_id = ? AND child_item_id = ?',
                    [$pId, $cId], 0
                );
                if ($exists > 0) continue;
                db_exec(
                    'INSERT INTO inv_bom_lines (parent_item_id, child_item_id, qty, sort_order, ref_designator, notes)
                     VALUES (?, ?, ?, ?, NULL, NULL)',
                    [$pId, $cId, $d['qty'], $d['sort_order']]
                );
                $stats['edges_inserted']++;
            }
        }
        db_exec('COMMIT');
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        throw $e;
    }
    return count($slice);
}

// ── phase worker: post stock for one slice. Returns # of codes consumed. ─────
function bom_batch_commit_stock(array $plan, $offset, array &$stats) {
    require_once dirname(__DIR__, 2) . '/includes/_inventory_txn.php';
    $codes = array_slice($plan['item_codes'], $offset, BOM_BATCH_STOCK);
    if (empty($codes)) return 0;
    $locCache = [];
    $txnDate  = isset($plan['txn_date']) ? $plan['txn_date'] : date('Y-m-d H:i:s');
    bom_old_import_commit_stock_slice($plan['items'], $codes, $stats, $locCache, $txnDate);
    return count($codes);
}

// ── build the human-readable completion message from accumulated stats ───────
function bom_old_import_build_summary(array $stats) {
    $msg = sprintf(
        'BOM import complete · items: %d created / %d reused · edges: %d inserted / %d updated',
        (int)$stats['items_created'], (int)$stats['items_reused'],
        (int)$stats['edges_inserted'], (int)$stats['edges_updated']
    );
    if (!empty($stats['divisions_created'])) {
        $names = !empty($stats['divisions_created_names'])
            ? ' (' . implode(', ', $stats['divisions_created_names']) . ')'
            : '';
        $msg .= sprintf(' · divisions: %d created%s', (int)$stats['divisions_created'], $names);
    }
    $stockItemsWithData = isset($stats['stocks_items_with_data']) ? (int)$stats['stocks_items_with_data'] : 0;
    if (!empty($stats['stocks_disabled'])) {
        $msg .= ' · stock: import disabled (stock is managed via inventory transactions)';
    } elseif ($stockItemsWithData === 0) {
        $msg .= ' · stock: 0 entries (old server returned no stock_locations — re-deploy tree7-5.php to old server)';
    } else {
        $msg .= sprintf(
            ' · stock: %d entries set (from %d items with stock data)',
            (int)$stats['stocks_imported'], $stockItemsWithData
        );
        if (!empty($stats['stocks_has_stock_skip'])) $msg .= sprintf(', %d skipped (already had stock)', (int)$stats['stocks_has_stock_skip']);
        if (!empty($stats['stocks_zero_skip']))      $msg .= sprintf(', %d skipped (qty=0)', (int)$stats['stocks_zero_skip']);
        if (!empty($stats['stocks_location_skip']))  $msg .= sprintf(', %d skipped (other location)', (int)$stats['stocks_location_skip']);
    }
    if (!empty($stats['stock_errors'])) $msg .= ' · ' . count($stats['stock_errors']) . ' stock error(s) — BOM itself committed OK';
    if (!empty($stats['edge_errors']))  $msg .= ' · ' . count($stats['edge_errors']) . ' edge error(s)';
    return $msg;
}

// ── compact stats for the live progress display ──────────────────────────────
function bom_old_import_stats_brief(array $stats) {
    return [
        'items_created'   => (int)$stats['items_created'],
        'items_reused'    => (int)$stats['items_reused'],
        'edges_inserted'  => (int)$stats['edges_inserted'],
        'edges_updated'   => (int)$stats['edges_updated'],
        'stocks_imported' => isset($stats['stocks_imported']) ? (int)$stats['stocks_imported'] : 0,
    ];
}

// ── shared helper: load config + fetch JSON from old server ──────────────────
function bom_old_import_fetch_json(&$errMsg) {
    $cfg     = require dirname(__DIR__, 2) . '/config/old_inventory_api.php';
    $treeUrl = rtrim(isset($cfg['tree_url']) ? $cfg['tree_url'] : '', '/');
    $token   = isset($cfg['token'])   ? $cfg['token']   : '';
    $timeout = (int)(isset($cfg['timeout']) ? $cfg['timeout'] : 30);

    if (empty($treeUrl)) {
        $errMsg = 'tree_url not set in config/old_inventory_api.php.';
        return null;
    }

    $fetchUrl = $treeUrl . '?action=all_trees_json&token=' . urlencode($token);
    $configuredRootIds = isset($cfg['root_ids'])
        ? array_filter(array_map('intval', (array)$cfg['root_ids'])) : [];
    if (!empty($configuredRootIds)) {
        $fetchUrl .= '&root_ids=' . implode(',', $configuredRootIds);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($fetchUrl, false, $ctx);

    if ($raw === false || trim($raw) === '') {
        $errMsg = 'Could not reach old inventory server at ' . $treeUrl
                . '. Make sure tree7-5.php is deployed and accessible.';
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $errMsg = 'Old server returned invalid JSON. Response starts with: '
                . substr($raw, 0, 120);
        return null;
    }
    if (isset($data['error'])) {
        $errMsg = 'Old server error: ' . $data['error'];
        return null;
    }

    return $data; // ['items' => [...], 'edges' => [...]]
}

// ── GET inventory.php?action=bom_old_import ───────────────────────────────────
//   Fetches JSON from old server → shows preview → commit goes to
//   bom_old_import_commit (below).
if ($action === 'bom_old_import') {
    if (!$canCreateBoms && !$canManageBoms) {
        require_permission('inventory_view_boms', 'create');
    }
    if (!$canCreateItems && !$canManageItems) {
        flash_set('error',
            'This importer auto-creates missing items — you need '
          . 'inventory_view_items.create. Ask an administrator to grant it.');
        redirect(url('/inventory.php?action=bom_grid'));
    }

    $upsert = !empty($_GET['upsert']) || !empty($_POST['upsert']);

    // ── Step 1: fetch JSON ────────────────────────────────────
    $fetchErr = '';
    $jsonData = bom_old_import_fetch_json($fetchErr);
    if ($jsonData === null) {
        flash_set('error', $fetchErr);
        redirect(url('/inventory.php?action=bom_grid'));
    }

    // ── Step 2: FK check ─────────────────────────────────────
    $fks = bom_import_load_fks();
    if (!$fks['ok']) {
        flash_set('error', $fks['reason']);
        redirect(url('/inventory.php?action=bom_grid'));
    }

    // ── Step 3: parse JSON → items / edges ───────────────────
    $parsedH = bom_import_parse_json_response($jsonData);
    $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
    bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

    // ── Step 4: stash raw JSON for commit step ────────────────
    $token_session = import_stash(json_encode($jsonData), 'inv_bom_json');

    // ── Step 5: build division-exists map for preview ─────────
    $divisionsInData = [];
    foreach ($parsedH['items'] as $it) {
        $name = trim((string)$it['division_name']);
        if ($name === '') $name = '__unknown';
        $divisionsInData[$name] = true;
    }
    $divisionExists = [];
    foreach (array_keys($divisionsInData) as $name) {
        $divRow = db_one("SELECT id FROM categories WHERE type='division' AND code = ?", [$name]);
        $divisionExists[$name] = $divRow !== null;
    }

    // ── Step 6: render preview ────────────────────────────────
    $page_title  = 'Import BOM from Old System · preview';
    $page_module = 'inventory_view_boms';
    require dirname(__DIR__, 2) . '/includes/header.php';

    if (!empty($parsedH['row_errors'])) {
        echo '<div class="card" style="margin-bottom:14px; border-left: 3px solid #b3261e;">';
        echo '<div class="card-head"><h3 style="margin:0;font-size:15px;color:#b3261e;">JSON parse notices ('
           . count($parsedH['row_errors']) . ')</h3></div>';
        echo '<div class="card-body"><ul style="margin:0;padding-left:20px;">';
        foreach ($parsedH['row_errors'] as $err) {
            echo '<li>item ' . (int)$err['line'] . ': ' . h($err['reason']) . '</li>';
        }
        echo '</ul></div></div>';
    }

    bom_import_render_preview_hier(
        'Import BOM from Old Inventory · preview',
        $token_session,
        $upsert,
        $parsedH['items'],
        $edges,
        url('/inventory.php?action=bom_old_import_commit_batch'),
        url('/inventory.php?action=bom_grid'),
        $divisionExists,
        true   // batched: JS-driven commit + progress bar (avoids timeouts on large trees)
    );

    // ── Stock + locations preview card ───────────────────────────────────────
    $locPreview     = bom_import_preview_locations($parsedH['items']);
    $stockItemCount = 0;
    $stockEntryCount= 0;
    foreach ($parsedH['items'] as $it) {
        if (!empty($it['stock_locations'])) {
            $stockItemCount++;
            $stockEntryCount += count($it['stock_locations']);
        }
    }
    if ($stockItemCount > 0):
        $willCreate = array_filter($locPreview, function ($l) { return $l['status'] === 'will_create'; });
    ?>
    <div class="card" style="margin-top:14px;">
        <div class="card-head" style="display:flex;align-items:center;gap:10px;">
            <h3 style="margin:0;font-size:15px;">
                Stock quantities &amp; locations from old system
            </h3>
            <?php if (!empty($willCreate)): ?>
            <span style="background:#fff3cd;color:#856404;border:1px solid #ffc107;
                         border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;">
                <?= count($willCreate) ?> location<?= count($willCreate) !== 1 ? 's' : '' ?> will be created
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p style="margin:0 0 10px;font-size:13px;color:#555;">
                <strong><?= $stockItemCount ?></strong> items ·
                <strong><?= $stockEntryCount ?></strong> location&nbsp;×&nbsp;qty entries.
                A <em>receive</em> transaction is posted per entry for
                <strong>newly-created</strong> items only — reused items keep
                their existing MagDyn stock history.
            </p>

            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f5f5f5;text-align:left;">
                        <th style="padding:5px 10px;border-bottom:1px solid #ddd;">Old-system location</th>
                        <th style="padding:5px 10px;border-bottom:1px solid #ddd;">MagDyn code</th>
                        <th style="padding:5px 10px;border-bottom:1px solid #ddd;">Items</th>
                        <th style="padding:5px 10px;border-bottom:1px solid #ddd;">Status on commit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($locPreview as $locName => $li):
                    $exists = ($li['status'] === 'exists');
                ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:5px 10px;"><?= h($locName) ?></td>
                        <td style="padding:5px 10px;font-family:monospace;"><?= h($li['code']) ?></td>
                        <td style="padding:5px 10px;"><?= (int)$li['item_count'] ?></td>
                        <td style="padding:5px 10px;">
                        <?php if ($exists): ?>
                            <span style="color:#2e7d32;font-weight:600;">✓ Exists</span>
                            <span style="color:#888;font-size:11px;">&nbsp;(id&nbsp;<?= (int)$li['id'] ?>)</span>
                        <?php else: ?>
                            <span style="color:#e65100;font-weight:600;">＋ Will be created</span>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif;

    require dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
}

// ── POST inventory.php?action=bom_old_import_commit ───────────────────────────
//   Re-reads stashed JSON, re-parses, and commits.
if ($action === 'bom_old_import_commit') {
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

    $rawJson = import_unstash($token, 'inv_bom_json');
    if ($rawJson === null) {
        flash_set('error', 'Import session expired. Please re-run the import.');
        redirect(url('/inventory.php?action=bom_grid'));
    }
    $jsonData = json_decode($rawJson, true);
    if (!is_array($jsonData)) {
        flash_set('error', 'Stashed import data is not valid JSON.');
        redirect(url('/inventory.php?action=bom_grid'));
    }

    $fks = bom_import_load_fks();
    if (!$fks['ok']) {
        flash_set('error', $fks['reason']);
        redirect(url('/inventory.php?action=bom_grid'));
    }

    $parsedH = bom_import_parse_json_response($jsonData);
    $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
    bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

    $stats = ['items_created' => 0, 'items_reused' => 0,
              'edges_inserted' => 0, 'edges_updated' => 0,
              'divisions_created' => 0, 'divisions_created_names' => [],
              'stocks_imported' => 0, 'stocks_zero_skip' => 0,
              'stock_errors' => [],
              'error' => ''];
    $ok = bom_import_commit_hierarchical($parsedH['items'], $edges, $fks, $stats);
    if (!$ok) {
        flash_set('error', 'Import failed: ' . $stats['error']);
        redirect(url('/inventory.php?action=bom_grid'));
    }

    // ── Step 4: import stock quantities from old system ───────────────────────
    // Posts a 'receive' transaction per (new item, location) pair.
    // Skips reused items (they already have MagDyn stock history).
    // Auto-creates locations in MagDyn's 'locations' table if needed.
    bom_old_import_commit_stock($parsedH['items'], $stats);

    // Find first root for redirect target
    $rootCode = null;
    foreach ($parsedH['items'] as $code => $it) {
        if ($it['is_root']) { $rootCode = $code; break; }
    }
    $rootDbId = $rootCode !== null
        ? (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$rootCode])
        : 0;

    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details)
         VALUES (?, 'inventory.bom.old_import_json', ?, ?)",
        [current_user_id(), $rootDbId, json_encode($stats)]
    );

    flash_set('success', bom_old_import_build_summary($stats));

    if ($rootDbId > 0) {
        redirect(url('/inventory.php?action=bom_view&id=' . $rootDbId));
    }
    redirect(url('/inventory.php?action=bom_grid'));
}

// ── POST inventory.php?action=bom_old_import_commit_batch (AJAX) ───────────────
//   JS-driven batched commit. Each call processes ONE slice of ONE phase
//   (items → edges → stock) and returns JSON progress. The plan is built once
//   (first call) and parked in the session keyed by the stash token.
if ($action === 'bom_old_import_commit_batch') {
    header('Content-Type: application/json; charset=utf-8');

    $deny = null;
    if (!$canCreateBoms && !$canManageBoms)   $deny = 'Permission denied (need BOM create).';
    if (!$canCreateItems && !$canManageItems) $deny = 'Missing inventory_view_items.create permission.';
    if ($deny !== null) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $deny]);
        exit;
    }
    csrf_check();

    $token  = (string)input('token', '');
    $upsert = !empty($_POST['upsert']) && (string)$_POST['upsert'] !== '0';
    $phase  = (string)input('phase', 'items');
    $offset = max(0, (int)input('offset', 0));

    try {
        $plan = bom_batch_plan_load($token);

        // ── Build the plan once, on the very first call (items @ 0) ──────────
        if ($plan === null) {
            if ($phase !== 'items' || $offset !== 0) {
                echo json_encode(['ok' => false, 'error' => 'Import session expired. Please re-run the import.']);
                exit;
            }
            $rawJson = import_unstash_peek($token, 'inv_bom_json');
            if ($rawJson === null) {
                echo json_encode(['ok' => false, 'error' => 'Import session expired. Please re-run the import.']);
                exit;
            }
            $jsonData = json_decode($rawJson, true);
            if (!is_array($jsonData)) {
                echo json_encode(['ok' => false, 'error' => 'Stashed import data is not valid JSON.']);
                exit;
            }
            $fks = bom_import_load_fks();
            if (!$fks['ok']) {
                echo json_encode(['ok' => false, 'error' => $fks['reason']]);
                exit;
            }
            $parsedH = bom_import_parse_json_response($jsonData);
            $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
            bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

            $stats = [
                'items_created'  => 0, 'items_reused'   => 0,
                'edges_inserted' => 0, 'edges_updated'  => 0, 'edge_errors' => [],
                'divisions_created' => 0, 'divisions_created_names' => [],
            ];
            bom_old_import_stock_init_stats($stats);

            $plan = [
                'item_codes' => array_keys($parsedH['items']),
                'items'      => $parsedH['items'],
                'edges'      => $edges['rows'],
                'fks'        => $fks,
                'upsert'     => $upsert ? 1 : 0,
                'txn_date'   => date('Y-m-d H:i:s'),
                'stats'      => $stats,
            ];
            bom_batch_plan_save($token, $plan);
            // The raw JSON stash is now superseded by the plan — free it.
            import_unstash($token, 'inv_bom_json');
        }

        $stats  = $plan['stats'];
        $totals = [
            'items' => count($plan['item_codes']),
            'edges' => count($plan['edges']),
            'stock' => count($plan['item_codes']),
        ];
        if (!isset($totals[$phase])) {
            echo json_encode(['ok' => false, 'error' => 'Unknown phase: ' . $phase]);
            exit;
        }

        // ── Process one batch for the requested phase ────────────────────────
        if ($phase === 'items') {
            $processed = bom_batch_commit_items($plan, $offset, $stats);
        } elseif ($phase === 'edges') {
            $processed = bom_batch_commit_edges($plan, $offset, $stats);
        } else {
            $processed = bom_batch_commit_stock($plan, $offset, $stats);
        }

        $nextOffset = $offset + $processed;
        $phaseTotal = (int)$totals[$phase];
        $phaseDone  = ($nextOffset >= $phaseTotal) || ($processed === 0);

        // Persist accumulated stats back to the plan.
        $plan['stats'] = $stats;
        bom_batch_plan_save($token, $plan);

        $resp = [
            'ok'          => true,
            'phase'       => $phase,
            'total'       => $phaseTotal,
            'done'        => $phaseDone ? $phaseTotal : $nextOffset,
            'next_offset' => $nextOffset,
            'phase_done'  => $phaseDone,
            'all_done'    => false,
            'stats'       => bom_old_import_stats_brief($stats),
        ];

        // ── Finalize when the LAST phase (stock) completes ───────────────────
        if ($phase === 'stock' && $phaseDone) {
            $rootCode = null;
            foreach ($plan['items'] as $code => $it) {
                if (!empty($it['is_root'])) { $rootCode = $code; break; }
            }
            $rootDbId = $rootCode !== null
                ? (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$rootCode], 0)
                : 0;

            db_exec(
                "INSERT INTO audit_log (actor_id, action, target_id, details)
                 VALUES (?, 'inventory.bom.old_import_json', ?, ?)",
                [current_user_id(), $rootDbId, json_encode($stats)]
            );

            flash_set('success', bom_old_import_build_summary($stats));
            bom_batch_plan_clear($token);

            $resp['all_done'] = true;
            $resp['summary']  = bom_old_import_build_summary($stats);
            $resp['redirect'] = $rootDbId > 0
                ? url('/inventory.php?action=bom_view&id=' . $rootDbId)
                : url('/inventory.php?action=bom_grid');
        }

        echo json_encode($resp);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Batch failed (' . $phase . ' @ ' . $offset . '): ' . $e->getMessage(),
        ]);
        exit;
    }
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
