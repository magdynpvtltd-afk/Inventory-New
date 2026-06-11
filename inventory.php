<?php
/**
 * MagDyn — Inventory module (dispatcher)
 * Original: 20260515_141500_IST
 * Refactored to dispatcher: 20260517_223400_IST (Stage 1)
 *
 * This file used to be a 4700-line monolith handling every action
 * for items, transactions, BOMs, and the BOM designer. It is now a
 * thin router. Routing stays at /inventory.php?action=... so no
 * URLs change, no sidebar update is needed, no audit-log impact.
 *
 * Stage 1 scope: PHP partition only. The inline <script> blocks
 * inside each action's HTML output have been carried along with
 * their handlers and remain inline — spa.js's existing
 * mainEl.querySelectorAll('script').forEach(...) loop re-executes
 * them on SPA navigation as it always has. footer.php and spa.js
 * are unchanged from the monolith era.
 *
 * Cluster files (under includes/inventory/):
 *   - items.php       — item CRUD, import, clone, list, new/edit
 *                       form, plus the inv_id_generate helper
 *   - txns.php        — txn_save, txn_process, process, txn_history,
 *                       ledger, inv_txn_* import
 *   - bom_lines.php   — bom_line_{add,update,delete}, bom_import_*
 *   - bom_views.php   — boms, bom_view, bom_edit, bom_grid,
 *                       bom_designer, bom_designer_api,
 *                       bom_clone_{preview,compute,commit}, plus
 *                       inv_tree, bom_clone_build_plan, the
 *                       designer rendering helpers
 *
 * Shared helpers:
 *   - includes/_inventory_helpers.php — inv_ancestors_of (used by
 *     bom_lines + bom_views)
 *   - includes/_inventory_txn.php — inv_post_txn (also used by
 *     inventory_shiprcpt.php)
 *
 * Each cluster file contains its handlers verbatim. Each handler
 * ends with its own exit; if no action matches, control falls
 * through to the items list redirect at the bottom.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_notes.php';
require_login();
require_once __DIR__ . '/includes/datatable.php';
require_once __DIR__ . '/includes/_inventory_helpers.php';
require_once __DIR__ . '/includes/_codes.php';  // for code_next('inv_item') used by inv_id_generate()

$action = (string)input('action', 'items');

// ============================================================
// Shared permission flags — visible to all cluster files.
// ============================================================
$canViewItems   = permission_check('inventory_view_items', 'view');
$canCreateItems = permission_check('inventory_view_items', 'create');
$canManageItems = permission_check('inventory_view_items', 'manage');
$canDeleteItems = permission_check('inventory_view_items', 'delete');

$canViewBoms   = permission_check('inventory_view_boms', 'view');
$canCreateBoms = permission_check('inventory_view_boms', 'create');
$canManageBoms = permission_check('inventory_view_boms', 'manage');
$canDeleteBoms = permission_check('inventory_view_boms', 'delete');

if (!$canViewItems && !$canViewBoms) {
    require_permission('inventory_view_items', 'view');
}

// ============================================================
// Action → cluster routing. Kept verbose rather than relying on
// prefix matching so any future renames don't accidentally
// re-route to the wrong file.
// ============================================================
$itemActions = [
    'items', 'item_new', 'item_edit', 'item_save', 'item_delete',
    'item_clone', 'item_toggle_active',
    'item_import_preview', 'item_import_commit',
];
$txnActions = [
    'ledger', 'process', 'txn_save', 'txn_process', 'txn_history',
    'move', 'move_save',
    'inv_txn_import_preview', 'inv_txn_import_commit',
];
$bomLineActions = [
    'bom_line_add', 'bom_line_update', 'bom_line_delete',
    'bom_import_preview', 'bom_import_commit',
    'bom_old_import', 'bom_old_import_commit', 'bom_old_import_commit_batch',
    'bom_delete_preview', 'bom_delete_commit',
];
$bomViewActions = [
    'boms', 'bom_view', 'bom_edit', 'bom_grid',
    'bom_designer', 'bom_designer_api',
    'bom_clone_preview', 'bom_clone_compute', 'bom_clone_commit',
];

if (in_array($action, $itemActions, true)) {
    require __DIR__ . '/includes/inventory/items.php';
} elseif (in_array($action, $txnActions, true)) {
    require __DIR__ . '/includes/inventory/txns.php';
} elseif (in_array($action, $bomLineActions, true)) {
    require __DIR__ . '/includes/inventory/bom_lines.php';
} elseif (in_array($action, $bomViewActions, true)) {
    require __DIR__ . '/includes/inventory/bom_views.php';
}

// Fallback: any unrouted action lands on the items list.
redirect(url('/inventory.php?action=items'));
