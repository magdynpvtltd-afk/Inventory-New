<?php
/**
 * MagDyn — Self-discovering BOM diagnostic for the "416 leaf" issue.
 * TEMPORARY — delete after use.
 *
 * Takes NO parameters. It looks up the item by CODE '416', finds its
 * children, finds every place it's used, walks up to the root product,
 * and flattens the whole tree the SAME way the grid does — printing
 * has-children/LEAF for each node. This removes all id-vs-code ambiguity.
 *
 * Usage:  /erp/tools/diag_bom416.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
header('Content-Type: text/plain; charset=utf-8');

function row($sql, $p = []) { return db_one($sql, $p); }
function rows($sql, $p = []) { return db_all($sql, $p); }

// 1. Resolve code 416 -> id.
$it416 = row("SELECT id, code, name FROM inv_items WHERE code = '416'");
if (!$it416) { echo "No item with code 416. Codes present sample:\n";
    foreach (rows("SELECT code FROM inv_items ORDER BY code LIMIT 20") as $r) echo "  {$r['code']}\n";
    exit;
}
$id416 = (int)$it416['id'];
echo "Item code 416 => id $id416 (name: {$it416['name']})\n\n";

// 2. Its children (what SHOULD render under every occurrence).
echo "=== Children of id $id416 (code 416) ===\n";
$kids416 = rows("SELECT bl.id line_id, bl.child_item_id, ci.code child_code
                   FROM inv_bom_lines bl JOIN inv_items ci ON ci.id = bl.child_item_id
                  WHERE bl.parent_item_id = ? ORDER BY bl.sort_order, bl.id", [$id416]);
if (!$kids416) echo "  (NONE — 416 genuinely has no children!)\n";
foreach ($kids416 as $k) echo "  line {$k['line_id']}: 416 -> {$k['child_code']} (id {$k['child_item_id']})\n";
echo "\n";

// 3. Where 416 is used (its parents).
echo "=== Parents of id $id416 (places 416 appears) ===\n";
$parents = rows("SELECT bl.id line_id, bl.parent_item_id, pi.code parent_code
                   FROM inv_bom_lines bl JOIN inv_items pi ON pi.id = bl.parent_item_id
                  WHERE bl.child_item_id = ? ORDER BY bl.id", [$id416]);
foreach ($parents as $p) echo "  line {$p['line_id']}: parent {$p['parent_code']} (id {$p['parent_item_id']}) -> 416\n";
echo "\n";

// 4. Build maps exactly like the grid.
$allItems = [];
foreach (rows("SELECT id, code, name, short_description, is_active FROM inv_items") as $r)
    $allItems[(int)$r['id']] = $r;
$allLines = [];
foreach (rows('SELECT id, parent_item_id, child_item_id, qty, sort_order
                 FROM inv_bom_lines ORDER BY sort_order, id') as $r)
    $allLines[(int)$r['parent_item_id']][] = $r;

echo "Total inv_items loaded: " . count($allItems) . "\n";
echo "Total parents-with-lines: " . count($allLines) . "\n";
echo "Is id $id416 present in \$allItems map? " . (isset($allItems[$id416]) ? "YES" : "NO  <-- PROBLEM") . "\n";
echo "Is id $id416 present in \$allLines map (has children)? " . (isset($allLines[$id416]) ? "YES (" . count($allLines[$id416]) . " lines)" : "NO  <-- PROBLEM") . "\n\n";

// 5. Find the top-most root(s): walk parents up until none.
function find_roots($id, $allLines, &$seen = []) {
    // parents of $id
    $ps = [];
    foreach ($allLines as $parentId => $lines) {
        foreach ($lines as $l) if ((int)$l['child_item_id'] === (int)$id) $ps[] = (int)$parentId;
    }
    if (!$ps) return [$id];
    $roots = [];
    foreach (array_unique($ps) as $p) {
        if (isset($seen[$p])) continue; $seen[$p] = true;
        foreach (find_roots($p, $allLines, $seen) as $r) $roots[$r] = true;
    }
    return array_keys($roots);
}
$roots = find_roots($id416, $allLines);
echo "=== Root product(s) above 416: " . implode(', ', array_map(function($r) use($allItems){ return ($allItems[$r]['code'] ?? '?') . " (id $r)"; }, $roots)) . " ===\n\n";

// 6. Flatten from each root the same way the grid does.
function flat($itemId,$depth,$myLineId,$qty,&$out,$chain,&$im,&$lm){
    if ($depth>50){ $out[]=['_t'=>1,'depth'=>$depth]; return; }
    if (isset($chain[$itemId])){ $out[]=['_cyc'=>1,'id'=>$itemId,'depth'=>$depth]; return; }
    $chain[$itemId]=true;
    if(!isset($im[$itemId])){ $out[]=['_miss'=>$itemId,'depth'=>$depth]; return; }
    $out[]=['id'=>$itemId,'depth'=>$depth];
    foreach (($lm[$itemId] ?? []) as $k)
        flat((int)$k['child_item_id'],$depth+1,(int)$k['id'],$qty*(float)$k['qty'],$out,$chain,$im,$lm);
}
foreach ($roots as $rootId) {
    echo "----- flatten from root " . ($allItems[$rootId]['code'] ?? '?') . " (id $rootId) -----\n";
    $out=[];
    flat($rootId,0,null,1,$out,[],$allItems,$allLines);
    foreach ($out as $i=>$n) {
        $pad=str_repeat('  ',(int)$n['depth']);
        if (!empty($n['_t'])) { echo "{$pad}[TRUNCATED >50]\n"; continue; }
        if (!empty($n['_miss'])) { echo "{$pad}[MISSING id {$n['_miss']}]\n"; continue; }
        if (!empty($n['_cyc'])) { $c=$allItems[$n['id']]['code']??'?'; echo "{$pad}$c (id {$n['id']})  [CYCLE STOP]\n"; continue; }
        $c=$allItems[$n['id']]['code']??'?';
        $hk=(isset($out[$i+1]) && (int)$out[$i+1]['depth']>(int)$n['depth'] && empty($out[$i+1]['_cyc'])) ? 'has-children' : 'LEAF';
        $mark=((int)$n['id']===$id416)?'   <<< THIS IS 416':'';
        echo "{$pad}$c (id {$n['id']})  [$hk]$mark\n";
    }
    echo "\n";
}
echo "Done. Look at every '<<< THIS IS 416' line: do they all say has-children?\n";
