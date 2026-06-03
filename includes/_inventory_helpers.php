<?php
/**
 * MagDyn — Inventory shared helpers
 * Extracted Stage 1: 20260517_223400_IST
 *
 * Helpers used by more than one cluster file. function_exists() guard
 * so an accidental double-load is harmless.
 */

if (!function_exists('inv_ancestors_of')) {
/**
 * Walk up from $startItemId through ancestor edges. Returns the set of
 * ancestor item ids (inclusive of $startItemId). Used to prevent cycles
 * and to detect "is item X a descendant of item Y".
 */
function inv_ancestors_of($itemId) {
    $itemId = (int)$itemId;
    $seen = [$itemId => true];
    $queue = [$itemId];
    while ($queue) {
        $cur = array_shift($queue);
        $parents = db_all('SELECT parent_item_id FROM inv_bom_lines WHERE child_item_id = ?', [$cur]);
        foreach ($parents as $p) {
            $pid = (int)$p['parent_item_id'];
            if (!isset($seen[$pid])) {
                $seen[$pid] = true;
                $queue[] = $pid;
            }
        }
    }
    return array_keys($seen);
}
}
