<?php
/**
 * MagDyn — BOM grid diagnostic (TEMPORARY, delete after use)
 *
 * Dumps exactly what flatten_bom_mem() produces for a given product id,
 * using the REAL database, plus the hasKids peek the grid uses. This
 * tells us why item 416 renders as a leaf under 419 on the live server
 * when the code and data both look correct.
 *
 * Usage:  /erp/tools/diag_bomgrid.php?id=727
 * Access: requires login; read-only; no writes.
 *
 * DELETE THIS FILE once the issue is resolved.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

header('Content-Type: text/plain; charset=utf-8');

// Accept EITHER ?id=<inv_items.id> OR ?code=<inv_items.code>. The BOM
// grid renders the CODE in brackets, so the numbers you see on screen
// (727, 304, 416 …) are CODES, not ids. Resolve a code to its id here.
$rootId = (int)($_GET['id'] ?? 0);
$rootCode = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
if (!$rootId && $rootCode !== '') {
    $rr = db_one("SELECT id FROM inv_items WHERE code = ?", [$rootCode]);
    if ($rr) $rootId = (int)$rr['id'];
    else { echo "No inv_items row with code '$rootCode'.\n"; exit; }
}
if (!$rootId) {
    echo "Pass ?code=<code> (e.g. ?code=727) — the bracketed numbers on the\n";
    echo "BOM grid are CODES, not ids. Or pass ?id=<inv_items.id> directly.\n";
    exit;
}
$rootRow = db_one("SELECT id, code, name FROM inv_items WHERE id = ?", [$rootId]);
echo "Resolved root: id={$rootId}, code=" . ($rootRow['code'] ?? '?')
   . ", name=" . ($rootRow['name'] ?? '?') . "\n\n";

// Build the same maps the grid builds.
$allItems = [];
foreach (db_all("SELECT id, code, name, short_description, is_active FROM inv_items") as $r) {
    $allItems[(int)$r['id']] = $r;
}
$allLines = [];
foreach (db_all(
    'SELECT bl.id, bl.parent_item_id, bl.child_item_id, bl.qty, bl.sort_order
       FROM inv_bom_lines bl ORDER BY bl.sort_order, bl.id'
) as $r) {
    $allLines[(int)$r['parent_item_id']][] = $r;
}

echo "=== Lines where parent_item_id = 188 (item 416's children) ===\n";
if (empty($allLines[188])) {
    echo "  (none) <-- if this is empty, 416 has NO children in the data\n";
} else {
    foreach ($allLines[188] as $l) {
        $cc = $allItems[(int)$l['child_item_id']]['code'] ?? '?';
        echo "  line {$l['id']}: 188 -> {$l['child_item_id']} ($cc)\n";
    }
}
echo "\n";

// Verbatim copy of the grid's flatten_bom_mem.
function diag_flatten($itemId, $depth, $parentLineId, $myLineId, $qty, &$out, $chain, &$itemsMap, &$linesMap) {
    if ($depth > 50) { $out[] = ['item'=>null,'depth'=>$depth,'_truncated'=>true]; return; }
    if (isset($chain[$itemId])) { $out[] = ['item'=>$itemsMap[$itemId]??['id'=>$itemId,'code'=>'?'],'depth'=>$depth,'line_id'=>$myLineId,'qty'=>$qty,'_cyclestop'=>true]; return; }
    $chain[$itemId] = true;
    $item = $itemsMap[$itemId] ?? null;
    if (!$item) { $out[] = ['item'=>null,'depth'=>$depth,'_missing'=>$itemId]; return; }
    $out[] = ['item'=>$item,'depth'=>$depth,'line_id'=>$myLineId,'qty'=>$qty];
    foreach (($linesMap[$itemId] ?? []) as $k) {
        diag_flatten((int)$k['child_item_id'], $depth+1, $myLineId, (int)$k['id'], $qty*(float)$k['qty'], $out, $chain, $itemsMap, $linesMap);
    }
}

$flat = [];
diag_flatten($rootId, 0, null, null, 1, $flat, [], $allItems, $allLines);

echo "=== flatten output for product $rootId (depth-indented) ===\n";
foreach ($flat as $idx => $row) {
    $pad = str_repeat('  ', (int)$row['depth']);
    if (!empty($row['_truncated'])) { echo "{$pad}[TRUNCATED depth>50]\n"; continue; }
    if (!empty($row['_missing'])) { echo "{$pad}[MISSING item id {$row['_missing']} — not in inv_items!]\n"; continue; }
    $code = $row['item']['code'] ?? '?';
    $id   = $row['item']['id'] ?? '?';
    $cyc  = !empty($row['_cyclestop']) ? '  <-- CYCLE STOP (would render as leaf)' : '';
    // The grid's hasKids peek:
    $hasKids = (isset($flat[$idx+1]) && $flat[$idx+1]['depth'] > $row['depth']) ? 'has-children' : 'LEAF';
    echo "$pad$code (id $id)  [$hasKids]$cyc\n";
}

echo "\n=== Look for: the SECOND occurrence of 416 (id 188). ===\n";
echo "If it shows [LEAF] but the FIRST shows [has-children], the divergence\n";
echo "is in flatten/chain. If it shows [CYCLE STOP], 188 was already in the\n";
echo "ancestor chain on that branch (a real loop). Either way this pinpoints it.\n";
