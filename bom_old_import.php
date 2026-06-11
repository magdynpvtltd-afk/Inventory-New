<?php
/**
 * MagDyn — BOM Import from Old Inventory (standalone page)
 *
 * Dedicated page for migrating BOM tree data from the legacy inventory server.
 * Mirrors the old_inventory_import.php pattern used for assets.
 *
 * GET  — connection status + confirmation screen
 * POST — fetch, parse, and commit; show results
 *
 * Permissions: inventory_view_boms.create  +  inventory_view_items.create
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$canViewItems   = permission_check('inventory_view_items', 'view');
$canCreateItems = permission_check('inventory_view_items', 'create');
$canManageItems = permission_check('inventory_view_items', 'manage');
$canDeleteItems = permission_check('inventory_view_items', 'delete');
$canViewBoms    = permission_check('inventory_view_boms',  'view');
$canCreateBoms  = permission_check('inventory_view_boms',  'create');
$canManageBoms  = permission_check('inventory_view_boms',  'manage');
$canDeleteBoms  = permission_check('inventory_view_boms',  'delete');

if (!$canCreateBoms && !$canManageBoms) {
    require_permission('inventory_view_boms', 'create');
}

// Load BOM import helper functions without firing any action handler.
$action = '__noop';
require_once __DIR__ . '/includes/_inventory_helpers.php';
require_once __DIR__ . '/includes/inventory/bom_lines.php';
require_once __DIR__ . '/includes/_purchase_orders.php';
require_once __DIR__ . '/includes/_codes.php';

$page_title  = 'Import BOM from Old System';
$page_module = 'inventory_view_boms';

// ── POST: delete all inventory records ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'delete_all') {
    csrf_check();
    require_permission('inventory_view_items', 'delete');

    try {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // ── inventory items & BOM ────────────────────────────────────────────
        $pdo->exec('TRUNCATE TABLE inv_bom_lines');
        $pdo->exec('TRUNCATE TABLE inv_item_certs');
        $pdo->exec('TRUNCATE TABLE inv_item_location_stock');
        $pdo->exec('TRUNCATE TABLE inv_process_steps');
        $pdo->exec("DELETE FROM notes WHERE entity_type = 'inv_item'");
        $pdo->exec('TRUNCATE TABLE inv_items');
        // ── inventory transactions ───────────────────────────────────────────
        $pdo->exec('TRUNCATE TABLE inv_txn_cmm_runs');
        $pdo->exec('TRUNCATE TABLE inv_txn_done_by');
        $pdo->exec('TRUNCATE TABLE inv_txns');
        // ── shipments, receipts, purchase orders ─────────────────────────────
        $pdo->exec('TRUNCATE TABLE inv_so_pending_summary');
        $pdo->exec('TRUNCATE TABLE inv_receipts');
        $pdo->exec('TRUNCATE TABLE inv_shipment_lines');
        $pdo->exec('TRUNCATE TABLE purchase_orders');
        $pdo->exec('TRUNCATE TABLE inv_shipments');
        // ── old-inventory staging tables ─────────────────────────────────────
        $pdo->exec('TRUNCATE TABLE old_inv_txns');
        $pdo->exec('TRUNCATE TABLE old_inv_shipments');
        $pdo->exec('TRUNCATE TABLE old_inv_receipts');
        $pdo->exec('TRUNCATE TABLE old_inv_po');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        flash_set('success', 'All inventory records (items, BOM edges, certs, notes, transactions, shipments, receipts, purchase orders) have been deleted.');
    } catch (Throwable $e) {
        try { db()->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_) {}
        flash_set('error', 'Delete failed: ' . $e->getMessage());
    }

    redirect(url('/bom_old_import.php'));
}

// ── helpers: resolve or auto-create vendors / couriers / items ───────────────

/**
 * Find an existing vendor by exact name, or create a new one.
 * Always returns a valid vendor ID — never null.
 */
function old_inv_resolve_vendor(string $name): int
{
    $name = trim($name);
    if ($name === '') $name = 'Unknown (Old Import)';

    $row = db_one('SELECT id FROM vendors WHERE name = ? LIMIT 1', [$name]);
    if ($row) return (int)$row['id'];

    // Generate a unique vendor code
    $base = 'IMP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 12));
    if ($base === 'IMP-') $base = 'IMP-VENDOR';
    $code = $base;
    $n    = 1;
    while ((int)db_val('SELECT COUNT(*) FROM vendors WHERE code = ?', [$code], 0) > 0) {
        $code = $base . $n++;
    }

    db_exec('INSERT INTO vendors (code, name, is_active) VALUES (?, ?, 1)', [$code, $name]);
    return (int)db()->lastInsertId();
}

/**
 * Find an existing shipping courier by name, or create one.
 * Returns null when name is blank.
 */
function old_inv_resolve_courier(string $name): ?int
{
    $name = trim($name);
    if ($name === '') return null;

    $row = db_one('SELECT id FROM shipping_couriers WHERE name = ? LIMIT 1', [$name]);
    if ($row) return (int)$row['id'];

    $base = 'IMP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 12));
    if ($base === 'IMP-') $base = 'IMP-COURIER';
    $code = $base;
    $n    = 1;
    while ((int)db_val('SELECT COUNT(*) FROM shipping_couriers WHERE code = ?', [$code], 0) > 0) {
        $code = $base . $n++;
    }

    db_exec('INSERT INTO shipping_couriers (code, name) VALUES (?, ?)', [$code, $name]);
    return (int)db()->lastInsertId();
}

/**
 * Find or auto-create an inv_items row for an item from the old server.
 *
 * Lookup priority (matches the BOM import convention so both imports
 * share the same item rows):
 *   1. $modelId  — inventory_model_id as string (e.g. "342").
 *                  The BOM import (tree7-5.php) uses this as inv_items.code.
 *   2. $code     — inventory_model_code (alphanumeric barcode, fallback).
 *
 * When neither lookup finds a match, a new inv_items row is created using
 * $modelId as the code (keeping it consistent with what the BOM import
 * would create for the same item).
 *
 * Defaults are resolved once per request (static cache):
 *   category  → 'rawmat' or first available inventory category
 *   division  → '__old_import' (auto-created if missing)
 *   uom       → 'nos' or first available UoM
 *
 * Returns null only when modelId, code, and name are all empty, or when
 * the required FK defaults are unavailable.
 */
function old_inv_ensure_item(string $modelId, string $code, string $name): ?int
{
    static $catId = false;   // false = not yet resolved; null = unavailable
    static $divId = false;
    static $uomId = false;

    $modelId = trim($modelId);
    $code    = trim($code);
    $name    = trim($name);

    if ($modelId === '' && $code === '' && $name === '') return null;

    // 1. Primary lookup: inventory_model_id string — matches BOM import convention
    if ($modelId !== '') {
        $row = db_one('SELECT id FROM inv_items WHERE code = ? LIMIT 1', [$modelId]);
        if ($row) return (int)$row['id'];
    }

    // 2. Secondary lookup: inventory_model_code (barcode)
    if ($code !== '') {
        $row = db_one('SELECT id FROM inv_items WHERE code = ? LIMIT 1', [$code]);
        if ($row) return (int)$row['id'];
    }

    // ── Lazy-init default category / division / UoM ─────────────────────
    if ($catId === false) {
        $cat = db_one("SELECT id FROM categories WHERE type='inventory' AND code='rawmat' LIMIT 1");
        if (!$cat) $cat = db_one("SELECT id FROM categories WHERE type='inventory' ORDER BY id LIMIT 1");
        $catId = $cat ? (int)$cat['id'] : null;
    }
    if ($divId === false) {
        $div = db_one("SELECT id FROM categories WHERE type='division' AND code='__old_import' LIMIT 1");
        if (!$div) {
            db_exec(
                "INSERT INTO categories (type, code, name, sort_order, is_active, created_at)
                 VALUES ('division', '__old_import', 'Old Import', 500, 1, NOW())"
            );
            $div = db_one("SELECT id FROM categories WHERE type='division' AND code='__old_import' LIMIT 1");
        }
        $divId = $div ? (int)$div['id'] : null;
    }
    if ($uomId === false) {
        $uom = db_one("SELECT id FROM inv_uom WHERE code='nos' LIMIT 1");
        if (!$uom) $uom = db_one('SELECT id FROM inv_uom ORDER BY id LIMIT 1');
        $uomId = $uom ? (int)$uom['id'] : null;
    }

    if (!$catId || !$divId || !$uomId) return null;   // FK defaults unavailable

    // Use inventory_model_id as code (consistent with BOM import convention).
    // Fall back to inventory_model_code when model_id is missing.
    $createCode = $modelId !== '' ? $modelId : $code;
    if ($createCode === '') {
        $base = 'IMP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 15));
        $createCode = $base;
        $n = 1;
        while ((int)db_val('SELECT COUNT(*) FROM inv_items WHERE code = ?', [$createCode], 0) > 0) {
            $createCode = $base . '-' . $n++;
        }
    }
    if ($name === '') $name = $createCode;

    db_exec(
        "INSERT INTO inv_items
            (code, name, short_description, category_id, division_id,
             manufacturer_type, uom_id, is_active, is_product, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'internal', ?, 1, 0, NOW(), NOW())",
        [$createCode, $name, $name, $catId, $divId, $uomId]
    );
    return (int)db()->lastInsertId();
}

/**
 * Resolve an old-system location NAME to a new locations.id.
 *
 * Matches by name or code (case-insensitive). Never returns null — when
 * nothing matches (or the name is blank) it falls back to a default
 * location ('Store', else the lowest-id location) so inv_txns.location_id
 * (NOT NULL) is always satisfiable. Results are cached per request.
 */
function old_inv_resolve_location_id(string $name): int
{
    static $cache   = [];
    static $default = 0;

    if ($default === 0) {
        $row = db_one("SELECT id FROM locations WHERE code = 'Store' LIMIT 1");
        if (!$row) $row = db_one('SELECT id FROM locations ORDER BY id LIMIT 1');
        $default = $row ? (int)$row['id'] : 0;
    }

    $name = trim($name);
    if ($name === '') return $default;

    if (array_key_exists($name, $cache)) return $cache[$name];

    $row = db_one(
        'SELECT id FROM locations
          WHERE LOWER(name) = LOWER(?) OR LOWER(code) = LOWER(?)
          LIMIT 1',
        [$name, $name]
    );
    return $cache[$name] = $row ? (int)$row['id'] : $default;
}

/**
 * Map an old transaction_type label (transaction_type.short_description) to
 * the new inv_txns.txn_type enum, the stock-delta sign, and which side's
 * location the resulting ledger row should sit on.
 *
 * New enum: receive | issue | adjust | process | ship_out | ship_in | move.
 * The old model has one row per (item, transaction) carrying both a source
 * and destination; the new ledger is single-location per row, so a move is
 * represented at its destination leg. reserve/unreserve/archive/unarchive
 * don't move stock → 'adjust' with a zero delta (audit marker only).
 *
 * Returns ['type' => string, 'sign' => -1|0|1, 'prefer' => 'source'|'dest'].
 */
function old_inv_map_txn_type(string $oldType): array
{
    static $map = [
        'ship'      => ['ship_out', -1, 'source'],
        'receive'   => ['receive',   1, 'dest'],
        'restock'   => ['receive',   1, 'dest'],
        'check in'  => ['receive',   1, 'dest'],
        'take out'  => ['issue',    -1, 'source'],
        'check out' => ['issue',    -1, 'source'],
        'move'      => ['move',      1, 'dest'],
        'reserve'   => ['adjust',    0, 'dest'],
        'unreserve' => ['adjust',    0, 'source'],
        'archive'   => ['adjust',    0, 'source'],
        'unarchive' => ['adjust',    0, 'dest'],
    ];
    $key = strtolower(trim($oldType));
    $m   = $map[$key] ?? ['adjust', 0, 'dest'];
    return ['type' => $m[0], 'sign' => $m[1], 'prefer' => $m[2]];
}

// ── helper: call one action on the transactions API ──────────────────────────
// $extra: additional query params, e.g. ['offset' => 0, 'limit' => 500]
function txn_api_fetch(string $action, string &$errMsg, array $extra = []): ?array
{
    $cfg     = require __DIR__ . '/config/old_inventory_api.php';
    $baseUrl = rtrim((string)($cfg['transactions_url'] ?? ''), '/');
    $token   = (string)($cfg['token'] ?? '');
    $timeout = (int)($cfg['timeout'] ?? 30);

    if (empty($baseUrl)) {
        $errMsg = 'transactions_url not set in config/old_inventory_api.php.';
        return null;
    }

    $params = array_merge(['action' => $action, 'token' => $token], $extra);
    $url    = $baseUrl . '?' . http_build_query($params);

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false || trim($raw) === '') {
        $errMsg = 'No response from ' . $baseUrl . ' (action=' . $action . ').';
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $errMsg = 'Server did not return JSON for action=' . $action
                . '. Make sure api_export_transactions.php is deployed on the old server.'
                . ' Response starts with: ' . substr($raw, 0, 80);
        return null;
    }
    if (isset($data['error'])) {
        $errMsg = 'API error (action=' . $action . '): ' . $data['error'];
        return null;
    }
    return $data;
}

// ── AJAX GET: ?ajax=txn_counts — proxy counts from old API ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'txn_counts') {
    header('Content-Type: application/json; charset=utf-8');
    $err  = '';
    $data = txn_api_fetch('counts_json', $err);
    echo $data === null ? json_encode(['error' => $err]) : json_encode($data);
    exit;
}

// ── AJAX POST: action=import_txns_chunk — import one paginated chunk ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_txns_chunk') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();

    $table  = (string)($_POST['table']  ?? '');
    $offset = max(0,   (int)($_POST['offset'] ?? 0));
    $limit  = min(500, max(1, (int)($_POST['limit'] ?? 500)));

    $apiActionMap = [
        'txns'      => 'all_txns_json',
        'shipments' => 'all_shipments_with_lines_json',   // embeds inventory_transaction lines
        'receipts'  => 'all_receipts_with_lines_json',    // embeds inventory_transaction lines
        'po'        => 'all_po_json',
    ];

    if (!isset($apiActionMap[$table])) {
        echo json_encode(['error' => 'Unknown table: ' . $table]);
        exit;
    }

    $result = ['inserted' => 0, 'skipped' => 0, 'count' => 0, 'done' => false];

    try {
        $err  = '';
        $data = txn_api_fetch($apiActionMap[$table], $err, ['offset' => $offset, 'limit' => $limit]);
        if ($data === null) throw new RuntimeException($err);

        $pdo = db();

        switch ($table) {
            // ── transactions ─────────────────────────────────────────────────
            // Two destinations per row:
            //   1. old_inv_txns  — raw staging mirror for the data viewer.
            //   2. inv_txns      — audit-only ledger row whose PK is forced to
            //                      the old inventory_transaction_id. It records
            //                      history for the per-item ledger but does NOT
            //                      touch inv_item_location_stock (stock stays as
            //                      set by the BOM stock import). qty_after is a
            //                      replayed running balance for display only.
            //
            // Dates (old `transaction` table):
            //   creation_date  → recorded_date / inv_txns.created_at (recorded)
            //   modified_date  → txn_date / inv_txns.txn_date (event occurred),
            //                    falling back to creation_date when NULL.
            case 'txns':
                $rows = $data['transactions'] ?? [];

                // Forced-PK reset guard: inv_txns.id is set to the old id, so
                // the ledger must be empty before a fresh import. Check once,
                // at the first chunk.
                if ($offset === 0 && (int)db_val('SELECT COUNT(*) FROM inv_txns', [], 0) > 0) {
                    throw new RuntimeException(
                        'inv_txns is not empty. Transactions are imported with their original '
                      . 'IDs, so run "Delete All Inventory Records" before importing.'
                    );
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO old_inv_txns
                        (old_id, old_transaction_id, item_code, item_name, quantity,
                         txn_type, txn_date, recorded_date, source_location, dest_location, note,
                         created_by_name, file_url)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        item_code       = VALUES(item_code),
                        item_name       = VALUES(item_name),
                        quantity        = VALUES(quantity),
                        txn_type        = VALUES(txn_type),
                        txn_date        = VALUES(txn_date),
                        recorded_date   = VALUES(recorded_date),
                        source_location = VALUES(source_location),
                        dest_location   = VALUES(dest_location),
                        note            = VALUES(note),
                        created_by_name = VALUES(created_by_name),
                        file_url        = VALUES(file_url)'
                );

                $txnStmt = $pdo->prepare(
                    'INSERT INTO inv_txns
                        (id, txn_type, txn_date, item_id, location_id, qty_delta,
                         qty_after, parent_txn_id, ref_doc, notes, is_correction,
                         created_by, created_at)
                     VALUES (?,?,?,?,?,?,?,NULL,?,?,0,?,?)'
                );

                $uid = (int)current_user_id();

                foreach ($rows as $row) {
                    $oldId = (int)($row['old_id'] ?? 0);
                    if ($oldId <= 0) { $result['skipped']++; continue; }

                    // recorded = creation_date; event = modified_date ?? creation_date
                    $recorded = $row['txn_date'] ?: null;
                    $modified = $row['txn_modified_date'] ?? null;
                    $event    = (!empty($modified) && $modified !== '0000-00-00 00:00:00')
                                ? $modified : $recorded;

                    $existed = (int)db_val('SELECT COUNT(*) FROM old_inv_txns WHERE old_id = ?', [$oldId]);
                    $stmt->execute([
                        $oldId,
                        (int)($row['old_transaction_id'] ?? 0),
                        (string)($row['item_code'] ?? ''),
                        (string)($row['item_name'] ?? ''),
                        (float)($row['quantity'] ?? 0),
                        (string)($row['txn_type'] ?? ''),
                        $event,
                        $recorded,
                        (string)($row['source_location'] ?? ''),
                        (string)($row['dest_location'] ?? ''),
                        $row['note'] ?: null,
                        (string)($row['created_by_name'] ?? ''),
                        (string)($row['file_url'] ?? ''),
                    ]);
                    if ($existed) $result['skipped']++; else $result['inserted']++;

                    // ── audit-only inv_txns ledger row (forced PK = old id) ──
                    $itemId = old_inv_ensure_item(
                        (string)($row['item_model_id'] ?? ''),
                        (string)($row['item_code']     ?? ''),
                        (string)($row['item_name']     ?? '')
                    );
                    if (!$itemId) continue;  // can't place a stock row without an item

                    $map     = old_inv_map_txn_type((string)($row['txn_type'] ?? ''));
                    $source  = (string)($row['source_location'] ?? '');
                    $dest    = (string)($row['dest_location']   ?? '');
                    $locName = $map['prefer'] === 'source' ? ($source ?: $dest) : ($dest ?: $source);
                    $locId   = old_inv_resolve_location_id($locName);

                    $qty   = (float)($row['quantity'] ?? 0);
                    $delta = $map['sign'] * $qty;

                    // Replayed running balance (audit only — stock table untouched).
                    // Rows arrive in ascending old_id order, so the last-inserted
                    // ledger row for this (item, location) holds the prior balance.
                    $prior    = (float)db_val(
                        'SELECT qty_after FROM inv_txns
                          WHERE item_id = ? AND location_id = ?
                          ORDER BY id DESC LIMIT 1',
                        [$itemId, $locId], 0.0
                    );
                    $qtyAfter = $prior + $delta;

                    $note = trim((string)($row['txn_type'] ?? '') . ' ' . (string)($row['note'] ?? ''));
                    $txnStmt->execute([
                        $oldId,
                        $map['type'],
                        $event ? substr($event, 0, 10) : date('Y-m-d'),
                        $itemId,
                        $locId,
                        $delta,
                        $qtyAfter,
                        'OLD-ITX-' . $oldId,
                        $note !== '' ? substr($note, 0, 255) : null,
                        $uid,
                        $recorded ?: date('Y-m-d H:i:s'),
                    ]);
                }
                break;

            // ── shipments → combined Ship # by S_Order No + PO (po_no = S_Order No) ──
            //
            // Every old shipment AND receipt that shares the same S_Order No
            // (old custom field cfv_9, custom_field_id 9) collapses into ONE
            // inv_shipments row (mode='both'), keyed by ref_doc 'OLD-SORD-<n>'.
            // That single Ship # carries one PO whose po_no IS the S_Order No, so
            // the system PO number matches the old order number exactly. Rows
            // with a blank S_Order No keep their own Ship # (ref_doc
            // 'OLD-SHP-<id>') with an auto-generated PO number.
            case 'shipments':
                $rows = $data['shipments'] ?? [];
                $uid  = (int)current_user_id();

                // Clean-slate guard (checked once, first shipments chunk): the
                // combine logic find-or-creates Ship # records by ref_doc and
                // assumes an empty table, so require a reset before importing.
                if ($offset === 0 && (int)db_val('SELECT COUNT(*) FROM inv_shipments', [], 0) > 0) {
                    throw new RuntimeException(
                        'inv_shipments is not empty. Shipments/receipts are combined by '
                      . 'S_Order No on import, so run "Delete All Inventory Records" first.'
                    );
                }

                foreach ($rows as $row) {
                    $oldId    = (int)($row['old_shipment_id'] ?? 0);
                    $oldTxnId = (int)($row['old_transaction_id'] ?? 0);
                    if ($oldId <= 0 || $oldTxnId <= 0) { $result['skipped']++; continue; }

                    // S_Order No (old cfv_9) → the PO number. '-'/'' = blank.
                    $sOrder = trim((string)($row['s_order_no'] ?? ''));
                    if ($sOrder === '-') $sOrder = '';

                    // Recorded vs event date:
                    //   created_at  ← transaction.creation_date (recorded)
                    //   event date  ← joined inventory_transaction.modified_date,
                    //                 else its creation_date; falls back to the
                    //                 transaction's own modified/creation date.
                    $recorded = $row['txn_date'] ?: null;
                    $modified = $row['txn_modified_date'] ?? null;
                    $eventIt  = $row['event_date'] ?? null;
                    $event    = (!empty($eventIt)   && $eventIt   !== '0000-00-00 00:00:00') ? $eventIt
                              : ((!empty($modified) && $modified !== '0000-00-00 00:00:00') ? $modified : $recorded);

                    // Resolve vendor (to_company = the party we're shipping to)
                    $company   = trim((string)($row['to_company'] ?? $row['from_company'] ?? ''));
                    $vendorId  = old_inv_resolve_vendor($company);
                    $courierId = old_inv_resolve_courier((string)($row['courier_name'] ?? ''));
                    $shipped   = (int)($row['shipped'] ?? 0);
                    $shipDate  = $row['ship_date'] ?: null;
                    // shipped_flag = 1 → 'shipped'; otherwise still 'approved'.
                    $status    = $shipped ? 'shipped' : 'approved';
                    // Event date drives the actual ship date / shipped timestamp.
                    $actualShipDate = $event ? substr($event, 0, 10) : $shipDate;
                    $shippedAt      = $shipped ? ($event ?: ($shipDate ? $shipDate . ' 00:00:00' : null)) : null;

                    if ($sOrder !== '') {
                        // ── combined Ship # keyed by S_Order No ──
                        $refDoc = 'OLD-SORD-' . $sOrder;
                        $shipId = (int)db_val('SELECT id FROM inv_shipments WHERE ref_doc = ? LIMIT 1', [$refDoc], 0);
                        if (!$shipId) {
                            // First row for this order — create the combined header
                            // (mode='both': it may carry ship and/or receive lines).
                            $shipNo = code_next('shipment');
                            db_exec(
                                'INSERT INTO inv_shipments
                                    (ship_no, vendor_id, courier_id, mode, status,
                                     ref_doc, notes, actual_ship_date, shipped_at, created_by,
                                     created_at, updated_at)
                                 VALUES (?, ?, ?, "both", ?, ?, ?, ?, ?, ?, ?, ?)',
                                [
                                    $shipNo, $vendorId, $courierId, $status,
                                    $refDoc, $row['txn_note'] ?: null,
                                    $actualShipDate, $shippedAt, $uid,
                                    $recorded ?: date('Y-m-d H:i:s'),
                                    $event    ?: date('Y-m-d H:i:s'),
                                ]
                            );
                            $shipId = (int)db()->lastInsertId();
                            // The system PO number IS the S_Order No.
                            po_ensure_for_shipment($shipId, $uid, $sOrder);
                        } else {
                            // Subsequent row for this order — fill any header gaps.
                            db_exec(
                                'UPDATE inv_shipments
                                    SET vendor_id        = COALESCE(vendor_id, ?),
                                        courier_id       = COALESCE(courier_id, ?),
                                        actual_ship_date = COALESCE(actual_ship_date, ?),
                                        shipped_at       = COALESCE(shipped_at, ?),
                                        status           = IF(status IN ("received","closed"), status, ?),
                                        updated_at       = GREATEST(updated_at, ?)
                                  WHERE id = ?',
                                [
                                    $vendorId, $courierId, $actualShipDate, $shippedAt, $status,
                                    $event ?: date('Y-m-d H:i:s'), $shipId,
                                ]
                            );
                        }
                    } else {
                        // ── blank S_Order No: own Ship # + auto-generated PO ──
                        $refDoc = 'OLD-SHP-' . $oldId;
                        if (db_val('SELECT id FROM inv_shipments WHERE ref_doc = ? LIMIT 1', [$refDoc], null)) {
                            $result['skipped']++;
                            continue;
                        }
                        $shipNo = code_next('shipment');
                        db_exec(
                            'INSERT INTO inv_shipments
                                (ship_no, vendor_id, courier_id, mode, status,
                                 ref_doc, notes, actual_ship_date, shipped_at, created_by,
                                 created_at, updated_at)
                             VALUES (?, ?, ?, "ship", ?, ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $shipNo, $vendorId, $courierId, $status,
                                $refDoc, $row['txn_note'] ?: null,
                                $actualShipDate, $shippedAt, $uid,
                                $recorded ?: date('Y-m-d H:i:s'),
                                $event    ?: date('Y-m-d H:i:s'),
                            ]
                        );
                        $shipId = (int)db()->lastInsertId();
                        po_ensure_for_shipment($shipId, $uid);   // auto PO number
                    }

                    // Append ship lines from embedded inventory_transaction rows.
                    // sort_order continues after any lines already on this Ship #.
                    $sortOrder = (int)db_val(
                        'SELECT COALESCE(MAX(sort_order)+1,0) FROM inv_shipment_lines WHERE shipment_id = ?',
                        [$shipId], 0
                    );
                    foreach ((array)($row['lines'] ?? []) as $line) {
                        $qty    = (float)($line['quantity'] ?? 0);
                        if ($qty == 0) continue;
                        $modelId = (string)($line['item_model_id'] ?? '');
                        $code    = (string)($line['item_code']    ?? '');
                        $itemId  = old_inv_ensure_item($modelId, $code, (string)($line['item_name'] ?? ''));
                        // For a shipped shipment, record qty_shipped = qty_planned
                        // so the line surfaces as a ship-out EVENT (dated by
                        // actual_ship_date = event date) in the shipment list.
                        db_exec(
                            'INSERT INTO inv_shipment_lines
                                (shipment_id, sort_order, line_kind, entity_type,
                                 item_id, pending_name, qty_planned, qty_shipped,
                                 old_transaction_id)
                             VALUES (?, ?, "ship", "inv_item", ?, ?, ?, ?, ?)',
                            [
                                $shipId, $sortOrder++,
                                $itemId ?: null,
                                $itemId ? null : ($line['item_name'] ?: ($code ?: 'Unknown')),
                                $qty,
                                $shipped ? $qty : 0,
                                $oldTxnId ?: null,
                            ]
                        );
                    }

                    $result['inserted']++;
                }
                break;

            // ── receipts → combined Ship # by S_Order No (receive lines) + PO ──
            //
            // Receipts run after the shipments pass, so a receipt that shares an
            // S_Order No with an already-imported shipment finds that combined
            // Ship # and appends its receive lines to it (one PO = the S_Order
            // No). Receipts with a blank S_Order No get their own receive-only
            // Ship # (ref_doc 'OLD-RCV-<id>') and an auto-generated PO number.
            case 'receipts':
                $rows = $data['receipts'] ?? [];
                $uid  = (int)current_user_id();
                foreach ($rows as $row) {
                    $oldId    = (int)($row['old_receipt_id'] ?? 0);
                    $oldTxnId = (int)($row['old_transaction_id'] ?? 0);
                    if ($oldId <= 0 || $oldTxnId <= 0) { $result['skipped']++; continue; }

                    // S_Order No (old cfv_9) → the PO number. '-'/'' = blank.
                    $sOrder = trim((string)($row['s_order_no'] ?? ''));
                    if ($sOrder === '-') $sOrder = '';

                    // Recorded vs event date (same rule as shipments):
                    //   created_at  ← transaction.creation_date (recorded)
                    //   event date  ← inventory_transaction.modified_date else
                    //                 creation_date; falls back to the transaction's
                    //                 own modified/creation date.
                    $recorded = $row['txn_date'] ?: null;
                    $modified = $row['txn_modified_date'] ?? null;
                    $eventIt  = $row['event_date'] ?? null;
                    $event    = (!empty($eventIt)   && $eventIt   !== '0000-00-00 00:00:00') ? $eventIt
                              : ((!empty($modified) && $modified !== '0000-00-00 00:00:00') ? $modified : $recorded);

                    // Vendor = the company we received from
                    $company  = trim((string)($row['from_company'] ?? ''));
                    $vendorId = old_inv_resolve_vendor($company);
                    $received = (int)($row['received_flag'] ?? 0);
                    $rcptDate = $row['receipt_date'] ?: null;
                    $dueDate  = $row['due_date'] ?: null;
                    // received_flag = 1 → 'received'; otherwise still 'approved'.
                    $status   = $received ? 'received' : 'approved';

                    if ($sOrder !== '') {
                        // ── combined Ship # keyed by S_Order No (find-or-create) ──
                        $refDoc = 'OLD-SORD-' . $sOrder;
                        $shipId = (int)db_val('SELECT id FROM inv_shipments WHERE ref_doc = ? LIMIT 1', [$refDoc], 0);
                        if (!$shipId) {
                            // No shipment carried this order — create a combined
                            // header now (mode='both' so a later/earlier ship side
                            // can attach too).
                            $shipNo = code_next('shipment');
                            db_exec(
                                'INSERT INTO inv_shipments
                                    (ship_no, vendor_id, mode, status,
                                     ref_doc, notes, receive_due_date, created_by,
                                     created_at, updated_at)
                                 VALUES (?, ?, "both", ?, ?, ?, ?, ?, ?, ?)',
                                [
                                    $shipNo, $vendorId, $status,
                                    $refDoc, $row['txn_note'] ?: null, $dueDate, $uid,
                                    $recorded ?: date('Y-m-d H:i:s'),
                                    $event    ?: date('Y-m-d H:i:s'),
                                ]
                            );
                            $shipId = (int)db()->lastInsertId();
                            // The system PO number IS the S_Order No.
                            po_ensure_for_shipment($shipId, $uid, $sOrder);
                        } else {
                            // Append to an existing combined Ship # — fill header gaps.
                            db_exec(
                                'UPDATE inv_shipments
                                    SET vendor_id        = COALESCE(vendor_id, ?),
                                        receive_due_date = COALESCE(receive_due_date, ?),
                                        status           = IF(status = "closed", status, ?),
                                        updated_at       = GREATEST(updated_at, ?)
                                  WHERE id = ?',
                                [$vendorId, $dueDate, $status, $event ?: date('Y-m-d H:i:s'), $shipId]
                            );
                        }
                    } else {
                        // ── blank S_Order No: own receive-only Ship # + auto PO ──
                        $refDoc = 'OLD-RCV-' . $oldId;
                        if (db_val('SELECT id FROM inv_shipments WHERE ref_doc = ? LIMIT 1', [$refDoc], null)) {
                            $result['skipped']++;
                            continue;
                        }
                        $shipNo = code_next('shipment');
                        db_exec(
                            'INSERT INTO inv_shipments
                                (ship_no, vendor_id, mode, status,
                                 ref_doc, notes, receive_due_date, created_by,
                                 created_at, updated_at)
                             VALUES (?, ?, "receive", ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $shipNo, $vendorId, $status,
                                $refDoc, $row['txn_note'] ?: null, $dueDate, $uid,
                                $recorded ?: date('Y-m-d H:i:s'),
                                $event    ?: date('Y-m-d H:i:s'),
                            ]
                        );
                        $shipId = (int)db()->lastInsertId();
                        po_ensure_for_shipment($shipId, $uid);   // auto PO number
                    }

                    // Append receive lines, continuing sort_order on this Ship #.
                    $sortOrder = (int)db_val(
                        'SELECT COALESCE(MAX(sort_order)+1,0) FROM inv_shipment_lines WHERE shipment_id = ?',
                        [$shipId], 0
                    );
                    foreach ((array)($row['lines'] ?? []) as $line) {
                        $qty    = (float)($line['quantity'] ?? 0);
                        if ($qty == 0) continue;
                        $modelId = (string)($line['item_model_id'] ?? '');
                        $code    = (string)($line['item_code']    ?? '');
                        $itemId  = old_inv_ensure_item($modelId, $code, (string)($line['item_name'] ?? ''));
                        // Actual received qty goes into qty_received for closed receipts
                        db_exec(
                            'INSERT INTO inv_shipment_lines
                                (shipment_id, sort_order, line_kind, entity_type,
                                 item_id, pending_name, qty_planned, qty_received,
                                 delivery_date, old_transaction_id)
                             VALUES (?, ?, "receive", "inv_item", ?, ?, ?, ?, ?, ?)',
                            [
                                $shipId, $sortOrder++,
                                $itemId ?: null,
                                $itemId ? null : ($line['item_name'] ?: ($code ?: 'Unknown')),
                                $qty,
                                $received ? $qty : 0,
                                $rcptDate,
                                $oldTxnId ?: null,
                            ]
                        );
                    }

                    $result['inserted']++;
                }
                break;

            // ── purchase orders ───────────────────────────────────────────────
            case 'po':
                $rows = $data['po'] ?? [];
                $stmt = $pdo->prepare(
                    'INSERT INTO old_inv_po
                        (old_po_id, po_ref_no, po_type, customer, customer_contact, address,
                         shipping_courier, shipment_type, product, quantity, price, gst, uom,
                         gst_per, due_date, po_create_date, payment_terms, notes,
                         internal_notes, special_instruction, reference, long_description)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        po_ref_no           = VALUES(po_ref_no),
                        po_type             = VALUES(po_type),
                        customer            = VALUES(customer),
                        customer_contact    = VALUES(customer_contact),
                        address             = VALUES(address),
                        shipping_courier    = VALUES(shipping_courier),
                        shipment_type       = VALUES(shipment_type),
                        product             = VALUES(product),
                        quantity            = VALUES(quantity),
                        price               = VALUES(price),
                        gst                 = VALUES(gst),
                        uom                 = VALUES(uom),
                        gst_per             = VALUES(gst_per),
                        due_date            = VALUES(due_date),
                        po_create_date      = VALUES(po_create_date),
                        payment_terms       = VALUES(payment_terms),
                        notes               = VALUES(notes),
                        internal_notes      = VALUES(internal_notes),
                        special_instruction = VALUES(special_instruction),
                        reference           = VALUES(reference),
                        long_description    = VALUES(long_description)'
                );
                foreach ($rows as $row) {
                    $oldId = (int)($row['old_po_id'] ?? 0);
                    if ($oldId <= 0) { $result['skipped']++; continue; }
                    $existed = (int)db_val('SELECT COUNT(*) FROM old_inv_po WHERE old_po_id = ?', [$oldId]);
                    $stmt->execute([
                        $oldId,
                        (int)($row['po_ref_no'] ?? 0),
                        (int)($row['po_type'] ?? 0),
                        (string)($row['customer'] ?? ''),
                        (string)($row['customer_contact'] ?? ''),
                        (string)($row['address'] ?? ''),
                        (string)($row['shipping_courier'] ?? ''),
                        (string)($row['shipment_type'] ?? ''),
                        (string)($row['product'] ?? ''),
                        (float)($row['quantity'] ?? 0),
                        (float)($row['price'] ?? 0),
                        (float)($row['gst'] ?? 0),
                        (string)($row['uom'] ?? ''),
                        (float)($row['gst_per'] ?? 0),
                        $row['due_date'] ?: null,
                        $row['po_create_date'] ?: null,
                        $row['payment_terms'] ?: null,
                        $row['notes'] ?: null,
                        $row['internal_notes'] ?: null,
                        $row['special_instruction'] ?: null,
                        $row['reference'] ?: null,
                        $row['long_description'] ?: null,
                    ]);
                    if ($existed) $result['skipped']++; else $result['inserted']++;
                }
                break;
        }

        $result['count'] = count($rows ?? []);
        // "done" = API returned fewer rows than we asked for → we're at the end
        $result['done']  = $result['count'] < $limit;

        // Audit log on the last chunk of the last table
        if ($result['done'] && $table === 'po') {
            db_exec(
                "INSERT INTO audit_log (actor_id, action, target_id, details)
                 VALUES (?, 'inventory.old_import.transactions_chunked', 0, ?)",
                [current_user_id(), json_encode(['offset' => $offset, 'limit' => $limit])]
            );
        }

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    echo json_encode($result);
    exit;
}

// ── AJAX POST: action=import_stock_from_tree ─────────────────────────────────
//   Final step of "Import Transactions & Shipments". Fetches the per-item
//   stock_locations from tree7-5.php and writes the on-hand (available)
//   quantities into MagDyn, mapping ONLY these two old-system locations:
//
//       old "Magdyn"            → MagDyn location  Magdyn   (available qty)
//       old "Rejection_Return"  → MagDyn location  LOC-REJ
//
//   All other old-system stock locations are ignored. Quantities are set
//   absolutely (the old system's current balance), straight into
//   inv_item_location_stock — no inv_txns rows are written, so the audit
//   ledger imported with original IDs is left untouched.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_stock_from_tree') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    if (!$canCreateItems && !$canManageItems) {
        echo json_encode(['error' => 'Missing inventory_view_items.create permission.']);
        exit;
    }
    @set_time_limit(300);

    // old-system stock location (normalised) → MagDyn locations.code
    $locMap = [
        'magdyn'           => 'Magdyn',
        'rejection_return' => 'LOC-REJ',
    ];

    try {
        $fetchErr = '';
        $jsonData = bom_old_import_fetch_json($fetchErr);
        if ($jsonData === null) throw new RuntimeException($fetchErr);

        $parsed = bom_import_parse_json_response($jsonData);

        // Resolve each target MagDyn location id once (by code, then name).
        $locIds = [];
        foreach (array_unique(array_values($locMap)) as $lc) {
            $r = db_one('SELECT id FROM locations WHERE code = ? LIMIT 1', [$lc]);
            if (!$r) $r = db_one('SELECT id FROM locations WHERE LOWER(name) = LOWER(?) LIMIT 1', [$lc]);
            $locIds[$lc] = $r ? (int)$r['id'] : null;
        }

        $stats = [
            'items'           => 0,   // items with stock data that matched an inv_item
            'updated'         => 0,   // (item, location) balances written
            'skipped_no_item' => 0,   // stock rows whose item isn't in MagDyn yet
            'skipped_no_loc'  => 0,   // target MagDyn location missing
        ];
        $touched = [];

        foreach ($parsed['items'] as $code => $it) {
            if (empty($it['stock_locations'])) continue;

            $itemRow = db_one('SELECT id FROM inv_items WHERE code = ? LIMIT 1', [$code]);
            if (!$itemRow) { $stats['skipped_no_item']++; continue; }
            $itemId = (int)$itemRow['id'];
            $matchedAny = false;

            foreach ($it['stock_locations'] as $sl) {
                $rawName = trim((string)($sl['location'] ?? ''));
                $key     = strtolower(str_replace([' ', '-'], '_', $rawName));
                if (!isset($locMap[$key])) continue;           // only Magdyn + Rejection_Return

                $locId = $locIds[$locMap[$key]] ?? null;
                if (!$locId) { $stats['skipped_no_loc']++; continue; }

                $qty = (float)($sl['qty'] ?? 0);
                // Absolute set — this is the old system's current available qty.
                db_exec(
                    'INSERT INTO inv_item_location_stock (item_id, location_id, qty)
                          VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
                    [$itemId, $locId, $qty]
                );
                $stats['updated']++;
                $touched[$itemId] = true;
                $matchedAny = true;
            }

            if ($matchedAny) $stats['items']++;
        }

        // Refresh the denormalised stock_on_hand for every touched item.
        foreach (array_keys($touched) as $itemId) {
            db_exec(
                'UPDATE inv_items
                    SET stock_on_hand = (SELECT COALESCE(SUM(qty), 0)
                                           FROM inv_item_location_stock
                                          WHERE item_id = ?)
                  WHERE id = ?',
                [$itemId, $itemId]
            );
        }

        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details)
             VALUES (?, 'inventory.old_import.stock_from_tree', 0, ?)",
            [current_user_id(), json_encode($stats)]
        );

        echo json_encode(array_merge(['ok' => true], $stats));
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ── AJAX POST: action=import_bom_prepare — fetch+parse, build & stash plan ────
//   One request: pulls all trees from the old server, parses, resolves edges
//   and parks a "plan" in the session. Does NO inserts, so it stays fast even
//   for large trees. Returns a token + per-phase totals for the progress bar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_bom_prepare') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    if (!$canCreateItems && !$canManageItems) {
        echo json_encode(['ok' => false, 'error' => 'Missing inventory_view_items.create permission.']);
        exit;
    }
    @set_time_limit(300);
    try {
        $fetchErr = '';
        $jsonData = bom_old_import_fetch_json($fetchErr);
        if ($jsonData === null) throw new RuntimeException($fetchErr);

        $fks = bom_import_load_fks();
        if (!$fks['ok']) throw new RuntimeException($fks['reason']);

        $upsert  = !empty($_POST['upsert']) && (string)$_POST['upsert'] !== '0';
        $parsedH = bom_import_parse_json_response($jsonData);
        $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
        bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

        $stats = [
            'items_created'  => 0, 'items_reused'  => 0,
            'edges_inserted' => 0, 'edges_updated' => 0, 'edge_errors' => [],
            'divisions_created' => 0, 'divisions_created_names' => [],
        ];
        bom_old_import_stock_init_stats($stats);

        $token = bin2hex(random_bytes(8));
        $plan  = [
            'item_codes' => array_keys($parsedH['items']),
            'items'      => $parsedH['items'],
            'edges'      => $edges['rows'],
            'fks'        => $fks,
            'upsert'     => $upsert ? 1 : 0,
            'txn_date'   => date('Y-m-d H:i:s'),
            'stats'      => $stats,
        ];
        bom_batch_plan_save($token, $plan);

        echo json_encode([
            'ok'     => true,
            'token'  => $token,
            'totals' => [
                'items' => count($plan['item_codes']),
                'edges' => count($plan['edges']),
                'stock' => count($plan['item_codes']),
            ],
            'row_errors' => count($parsedH['row_errors']),
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── AJAX POST: action=import_bom_chunk — commit ONE slice of ONE phase ────────
//   Phases run in order items → edges → stock. Each call is its own short
//   request (its own DB transaction(s)) so nothing ever times out. Finalizes
//   (audit log) when the stock phase completes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_bom_chunk') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    if (!$canCreateItems && !$canManageItems) {
        echo json_encode(['ok' => false, 'error' => 'Missing inventory_view_items.create permission.']);
        exit;
    }
    @set_time_limit(120);

    $token  = (string)($_POST['token']  ?? '');
    $phase  = (string)($_POST['phase']  ?? 'items');
    $offset = max(0, (int)($_POST['offset'] ?? 0));

    try {
        $plan = bom_batch_plan_load($token);
        if ($plan === null) throw new RuntimeException('Import session expired. Please start the import again.');

        $stats  = $plan['stats'];
        $totals = [
            'items' => count($plan['item_codes']),
            'edges' => count($plan['edges']),
            'stock' => count($plan['item_codes']),
        ];
        if (!isset($totals[$phase])) throw new RuntimeException('Unknown phase: ' . $phase);

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

        $plan['stats'] = $stats;
        bom_batch_plan_save($token, $plan);

        $resp = [
            'ok'          => true,
            'phase'       => $phase,
            'total'       => $phaseTotal,
            'done'        => $phaseDone ? $phaseTotal : $nextOffset,
            'count'       => $processed,
            'next_offset' => $nextOffset,
            'phase_done'  => $phaseDone,
            'all_done'    => false,
            'stats'       => bom_old_import_stats_brief($stats),
        ];

        if ($phase === 'stock' && $phaseDone) {
            $rootDbId = 0;
            foreach ($plan['items'] as $code => $it) {
                if (!empty($it['is_root'])) {
                    $rootDbId = (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$code], 0);
                    break;
                }
            }
            db_exec(
                "INSERT INTO audit_log (actor_id, action, target_id, details)
                 VALUES (?, 'inventory.bom.old_import_page', ?, ?)",
                [current_user_id(), $rootDbId, json_encode($stats)]
            );
            bom_batch_plan_clear($token);

            $resp['all_done'] = true;
            $resp['summary']  = bom_old_import_build_summary($stats);
        }

        echo json_encode($resp);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Chunk failed (' . $phase . ' @ ' . $offset . '): ' . $e->getMessage(),
        ]);
        exit;
    }
}

// ── POST: run the BOM import (legacy single-shot fallback) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$canCreateItems && !$canManageItems) {
        flash_set('error',
            'This importer auto-creates missing items — you need '
          . 'inventory_view_items.create. Ask an administrator to grant it.');
        redirect(url('/bom_old_import.php'));
    }

    $fatalError = null;
    $stats      = null;
    $parsedH    = null;

    try {
        $fetchErr = '';
        $jsonData = bom_old_import_fetch_json($fetchErr);
        if ($jsonData === null) {
            throw new RuntimeException($fetchErr);
        }

        $fks = bom_import_load_fks();
        if (!$fks['ok']) {
            throw new RuntimeException($fks['reason']);
        }

        $upsert  = !empty($_POST['upsert']);
        $parsedH = bom_import_parse_json_response($jsonData);
        $edges   = bom_import_resolve_edges($parsedH['items'], $parsedH['edges'], $upsert);
        bom_import_check_cross_row_cycles_hier($parsedH['items'], $edges);

        $stats = [
            'items_created'          => 0,
            'items_reused'           => 0,
            'edges_inserted'         => 0,
            'edges_updated'          => 0,
            'divisions_created'      => 0,
            'divisions_created_names'=> [],
            'stocks_imported'        => 0,
            'stocks_items_with_data' => 0,
            'stocks_zero_skip'       => 0,
            'stocks_has_stock_skip'  => 0,
            'stock_errors'           => [],
            'error'                  => '',
        ];
        $ok = bom_import_commit_hierarchical($parsedH['items'], $edges, $fks, $stats);
        if (!$ok) {
            throw new RuntimeException('Import failed: ' . $stats['error']);
        }

        bom_old_import_commit_stock($parsedH['items'], $stats);

        $rootDbId = 0;
        foreach ($parsedH['items'] as $code => $it) {
            if ($it['is_root']) {
                $rootDbId = (int)db_val('SELECT id FROM inv_items WHERE code = ?', [$code]);
                break;
            }
        }
        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details)
             VALUES (?, 'inventory.bom.old_import_page', ?, ?)",
            [current_user_id(), $rootDbId, json_encode($stats)]
        );

    } catch (Throwable $e) {
        $fatalError = $e->getMessage();
    }

    require __DIR__ . '/includes/header.php';
?>
<div class="form-page">
    <?= form_toolbar([
        'title'      => 'Import BOM from Old System — Results',
        'back_href'  => url('/bom_old_import.php'),
        'back_label' => 'Back to Import',
    ]) ?>
    <div class="form-page-body" style="max-width:820px;">

    <?php if ($fatalError): ?>
        <div class="alert alert-error">
            <strong>Import failed:</strong><br>
            <code><?= h($fatalError) ?></code>
        </div>

    <?php else: ?>

        <h3 style="margin:0 0 8px;">Items</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
            <?php foreach ([
                ['Created', $stats['items_created'], '#d1fae5', '#065f46'],
                ['Reused',  $stats['items_reused'],  '#dbeafe', '#1e40af'],
            ] as [$label, $val, $bg, $color]): ?>
            <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                        padding:14px 24px;min-width:130px;text-align:center;
                        box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="margin:0 0 8px;">BOM Edges</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px;">
            <?php foreach ([
                ['Inserted', $stats['edges_inserted'], '#d1fae5', '#065f46'],
                ['Updated',  $stats['edges_updated'],  '#dbeafe', '#1e40af'],
            ] as [$label, $val, $bg, $color]): ?>
            <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                        padding:14px 24px;min-width:130px;text-align:center;
                        box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($stats['divisions_created']) && !empty($stats['divisions_created_names'])): ?>
        <div class="alert alert-info" style="margin-bottom:20px;">
            <?= (int)$stats['divisions_created'] ?> new division(s) created:
            <strong><?= h(implode(', ', $stats['divisions_created_names'])) ?></strong>
        </div>
        <?php endif; ?>

        <h3 style="margin:0 0 8px;">Stock Quantities</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
            <?php
            $stockItemsWithData = (int)($stats['stocks_items_with_data'] ?? 0);
            if (!empty($stats['stocks_disabled'])):
            ?>
            <div class="alert alert-info" style="margin:0;">
                Stock import is disabled — the BOM import no longer sets stock levels.
                Stock is managed via the inventory transaction ledger (Import Transactions &amp; Shipments) and manual entry.
            </div>
            <?php elseif ($stockItemsWithData === 0): ?>
            <div class="alert alert-info" style="margin:0;">
                Old server returned no stock_locations data — re-deploy tree7-5.php to the old server to enable stock import.
            </div>
            <?php else: ?>
            <?php foreach ([
                ['Imported',      $stats['stocks_imported'],       '#d1fae5', '#065f46'],
                ['Had stock',     $stats['stocks_has_stock_skip'], '#dbeafe', '#1e40af'],
                ['Zero qty skip', $stats['stocks_zero_skip'],      '#f3f4f6', '#374151'],
            ] as [$label, $val, $bg, $color]): ?>
            <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                        padding:14px 24px;min-width:130px;text-align:center;
                        box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($stats['stock_errors'])): ?>
        <div class="alert alert-error" style="margin-bottom:20px;">
            <?= count($stats['stock_errors']) ?> stock error(s) — BOM itself committed OK:<br>
            <?php foreach ($stats['stock_errors'] as $se): ?>
            <code style="font-size:12px;"><?= h($se) ?></code><br>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($parsedH !== null && !empty($parsedH['row_errors'])): ?>
        <h3 style="margin-bottom:8px;">Parse Notices (<?= count($parsedH['row_errors']) ?>)</h3>
        <div style="background:#f9fafb;border:1px solid var(--border);border-radius:6px;
                    max-height:300px;overflow-y:auto;padding:12px;
                    font-size:12px;font-family:monospace;line-height:1.6;">
            <?php foreach ($parsedH['row_errors'] as $err): ?>
            <div style="color:#991b1b;margin-bottom:2px;">
                item <?= (int)$err['line'] ?>: <?= h($err['reason']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>

        <div style="margin-top:24px;display:flex;gap:10px;">
            <a class="btn btn-primary" href="<?= h(url('/inventory.php?action=bom_grid')) ?>">View BOM Grid</a>
            <a class="btn btn-ghost"   href="<?= h(url('/bom_old_import.php')) ?>">Run Again</a>
        </div>
    </div>
</div>
<?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ── GET — status / confirmation page ─────────────────────────────────────────
$connectError    = null;
$txnConnectError = null;

$cfg   = require __DIR__ . '/config/old_inventory_api.php';
$token = (string)($cfg['token'] ?? '');

// Ping BOM tree API
try {
    $treeUrl = rtrim((string)($cfg['tree_url'] ?? ''), '/');
    if (empty($treeUrl)) {
        throw new RuntimeException('tree_url not set in config/old_inventory_api.php.');
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($treeUrl . '?action=ping&token=' . urlencode($token), false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('Cannot reach ' . $treeUrl . '. Ensure tree7-5.php is deployed.');
    }
} catch (Throwable $e) {
    $connectError = $e->getMessage();
}

// Ping transactions API
try {
    $txnsUrl = rtrim((string)($cfg['transactions_url'] ?? ''), '/');
    if (empty($txnsUrl)) {
        throw new RuntimeException('transactions_url not set in config/old_inventory_api.php.');
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($txnsUrl . '?action=ping&token=' . urlencode($token), false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('Cannot reach ' . $txnsUrl . '.');
    }
    $ping = json_decode($raw, true);
    if (!is_array($ping) || empty($ping['ok'])) {
        throw new RuntimeException(
            'api_export_transactions.php not deployed or returned an error. '
          . 'Copy api_export_transactions.php to the old server at /inventory/. '
          . 'Response: ' . substr($raw, 0, 80)
        );
    }
} catch (Throwable $e) {
    $txnConnectError = $e->getMessage();
}

$localCounts = [
    'items' => (int)db_val('SELECT COUNT(*) FROM inv_items',     [], 0),
    'edges' => (int)db_val('SELECT COUNT(*) FROM inv_bom_lines', [], 0),
];

require __DIR__ . '/includes/header.php';
?>
<div class="form-page">
    <?= form_toolbar([
        'title'    => 'Import BOM from Old Inventory',
        'subtitle' => 'Fetch all BOM trees from <code>tree7-5.php</code> on the old inventory server and import them.',
        'back_href'  => url('/inventory.php?action=bom_grid'),
        'back_label' => 'Back to BOM Grid',
    ]) ?>

    <div class="form-page-body" style="max-width:720px;">

        <?php if ($connectError): ?>
        <div class="alert alert-error" style="margin-bottom:12px;">
            <strong>BOM API unreachable.</strong><br>
            <code style="font-size:12px;"><?= h($connectError) ?></code><br>
            Make sure <code>tree7-5.php</code> is deployed on the old server.
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:12px;">
            ✅ BOM API reachable (<code>tree7-5.php</code>) — BOM import ready.
        </div>
        <?php endif; ?>

        <?php if ($txnConnectError): ?>
        <div class="alert alert-error" style="margin-bottom:20px;">
            <strong>Transactions API unreachable.</strong><br>
            <code style="font-size:12px;"><?= h($txnConnectError) ?></code><br>
            Copy <code>api_export_transactions.php</code> to <code>/inventory/</code> on the old server,
            then reload this page.
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:20px;">
            ✅ Transactions API reachable (<code>api_export_transactions.php</code>) — transaction import ready.
        </div>
        <?php endif; ?>

        <h3 style="margin:0 0 10px;">Currently in MagDyn</h3>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
            <?php foreach (['Items' => $localCounts['items'], 'BOM Edges' => $localCounts['edges']] as $label => $cnt): ?>
            <div style="text-align:center;min-width:90px;background:#f3f4f6;
                        border-radius:8px;padding:14px 20px;">
                <div style="font-size:26px;font-weight:700;color:#374151;"><?= number_format($cnt) ?></div>
                <div style="font-size:12px;color:#6b7280;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="margin:0 0 10px;">Reset</h3>
        <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
            <p style="margin:0 0 12px;font-size:14px;color:#7f1d1d;">
                <strong>Delete all inventory records</strong> — removes every item, BOM edge,
                cert, process step, inventory note <strong>and</strong> all imported transactions,
                shipments, receipts and purchase orders from this system.
                Use this to start a clean re-import.
            </p>
            <?php
            $delCounts = [
                'Items'    => (int)db_val('SELECT COUNT(*) FROM inv_items',          [], 0),
                'BOM Edges'=> (int)db_val('SELECT COUNT(*) FROM inv_bom_lines',      [], 0),
                'Notes'    => (int)db_val("SELECT COUNT(*) FROM notes WHERE entity_type='inv_item'", [], 0),
            ];
            ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                <?php foreach ($delCounts as $label => $cnt): ?>
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:22px;font-weight:700;color:#991b1b;"><?= number_format($cnt) ?></div>
                    <div style="font-size:11px;color:#7f1d1d;"><?= h($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?= h(url('/bom_old_import.php')) ?>"
                  onsubmit="return confirm('This will permanently delete ALL inventory items, BOM edges, certs, notes AND all imported transactions, shipments, receipts and purchase orders.\n\nAre you sure?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_all">
                <button type="submit" class="btn btn-danger">🗑 Delete All Inventory Records</button>
            </form>
        </div>

        <h3 style="margin:0 0 10px;">What this import does</h3>
        <table class="info-table" style="margin-bottom:24px;width:100%;">
            <tr><th style="width:40%;">Items</th>
                <td>Auto-created if they don't exist in MagDyn. Pre-existing items are <strong>never modified</strong>.</td></tr>
            <tr><th>BOM Edges</th>
                <td>Inserted fresh. With <em>Upsert</em> on, existing edges have qty/sort updated.</td></tr>
            <tr><th>Divisions</th>
                <td>Created automatically when a division name from the old system is not found.</td></tr>
            <tr><th>Cycle prevention</th>
                <td>Self-edges and cross-row cycles are detected and skipped with warnings.</td></tr>
            <tr><th>Duration</th>
                <td>Imported in batches with a live progress bar — large trees won't time out.</td></tr>
        </table>

        <h3 style="margin:0 0 10px;">Run Import</h3>
        <div style="margin-bottom:14px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
                <input type="checkbox" id="bom-upsert" value="1">
                Upsert mode — update qty/sort on existing BOM edges
            </label>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <button id="bom-import-btn" class="btn btn-primary"<?= $connectError ? ' disabled' : '' ?>
                    data-csrf-name="<?= h($GLOBALS['APP']['csrf_field']) ?>"
                    data-csrf-token="<?= h(csrf_token()) ?>"
                    data-url="<?= h(url('/bom_old_import.php')) ?>"
                    data-grid-url="<?= h(url('/inventory.php?action=bom_grid')) ?>">
                ⬇ Start Import
            </button>
            <a class="btn btn-ghost" href="<?= h(url('/inventory.php?action=bom_grid')) ?>">Cancel</a>
            <span class="muted small">Runs in batches — no timeout on large trees.</span>
        </div>

        <!-- BOM progress widget (hidden until import starts) -->
        <div id="bom-import-widget" style="display:none;margin:20px 0;">
            <!-- Overall bar -->
            <div style="margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;color:#374151;margin-bottom:4px;">
                    <span id="bom-overall-label">Starting…</span>
                    <span id="bom-overall-pct">0%</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:14px;overflow:hidden;">
                    <div id="bom-overall-bar"
                         style="height:100%;width:0%;background:linear-gradient(90deg,#10b981,#059669);
                                border-radius:999px;transition:width .4s ease;"></div>
                </div>
            </div>

            <!-- Per-phase stats table -->
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb;text-align:left;">
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;">Phase</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">Total</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">Done</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ([
                        'items' => 'Items',
                        'edges' => 'BOM Edges',
                        'stock' => 'Stock Quantities',
                    ] as $key => $lbl): ?>
                    <tr>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;"><?= h($lbl) ?></td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;text-align:right;" id="bom-total-<?= $key ?>">—</td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;text-align:right;" id="bom-done-<?= $key ?>">0</td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;" id="bom-status-<?= $key ?>">Waiting</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="bom-live-stats" style="font-size:12px;color:#6b7280;margin-top:10px;"></div>
            <div id="bom-import-error" style="display:none;margin-top:14px;" class="alert alert-error"></div>
            <div id="bom-import-done-msg" style="display:none;margin-top:14px;" class="alert alert-success"></div>
        </div>

        <script>
        (function () {
            'use strict';
            var btn = document.getElementById('bom-import-btn');
            if (!btn) return;
            var widget    = document.getElementById('bom-import-widget');
            var errBox    = document.getElementById('bom-import-error');
            var doneMsg   = document.getElementById('bom-import-done-msg');
            var liveStats = document.getElementById('bom-live-stats');

            var PHASES = [['items', 'Items'], ['edges', 'BOM Edges'], ['stock', 'Stock Quantities']];
            var totals = { items: 0, edges: 0, stock: 0 };
            var done   = { items: 0, edges: 0, stock: 0 };

            function el(id)  { return document.getElementById(id); }
            function fmt(n)  { return Number(n).toLocaleString(); }
            function pct(d, t) { return t > 0 ? Math.min(100, Math.round(d / t * 100)) : 100; }
            function setBar(id, p) { var b = el(id); if (b) b.style.width = p + '%'; }

            function updateOverall() {
                var T = totals.items + totals.edges + totals.stock;
                var D = done.items + done.edges + done.stock;
                var p = pct(D, T);
                setBar('bom-overall-bar', p);
                el('bom-overall-pct').textContent = p + '%';
            }
            function updatePhase(ph) {
                el('bom-total-' + ph).textContent = totals[ph] ? fmt(totals[ph]) : '—';
                el('bom-done-'  + ph).textContent = fmt(done[ph]);
            }
            function showStats(s) {
                if (!s) return;
                liveStats.textContent =
                    'items: ' + fmt(s.items_created) + ' created / ' + fmt(s.items_reused) + ' reused · ' +
                    'edges: ' + fmt(s.edges_inserted) + ' inserted / ' + fmt(s.edges_updated) + ' updated · ' +
                    'stock: ' + fmt(s.stocks_imported) + ' set';
            }
            function showError(msg) { errBox.textContent = '❌ ' + msg; errBox.style.display = 'block'; }

            async function post(params) {
                var body = new URLSearchParams();
                Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
                body.append(btn.dataset.csrfName, btn.dataset.csrfToken);
                var resp = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString()
                });
                var text = await resp.text(), data;
                try { data = JSON.parse(text); }
                catch (e) { throw new Error('Server returned non-JSON (HTTP ' + resp.status + '): ' + text.substring(0, 200)); }
                if (!data.ok) throw new Error(data.error || 'Unknown server error');
                return data;
            }

            function finish(d) {
                setBar('bom-overall-bar', 100);
                el('bom-overall-pct').textContent = '100%';
                el('bom-overall-label').textContent = 'Import complete';
                PHASES.forEach(function (p) {
                    el('bom-status-' + p[0]).textContent = '✅ Done';
                    el('bom-status-' + p[0]).style.color = '#059669';
                });
                doneMsg.innerHTML = '✅ ' + (d.summary || 'Import complete.') +
                    ' &nbsp; <a href="' + btn.dataset.gridUrl + '" style="font-weight:600;">View BOM Grid →</a>';
                doneMsg.style.display = 'block';
                btn.textContent = '✅ Done — run again?';
                btn.disabled = false;
            }

            async function runPhase(ph, token) {
                el('bom-status-' + ph).textContent = '⏳ 0%';
                el('bom-status-' + ph).style.color = '#2563eb';
                var offset = 0;
                while (true) {
                    var d = await post({ action: 'import_bom_chunk', token: token, phase: ph, offset: offset });
                    done[ph] = d.done;
                    updatePhase(ph);
                    updateOverall();
                    showStats(d.stats);
                    el('bom-status-' + ph).textContent = '⏳ ' + pct(done[ph], totals[ph]) + '%';
                    if (d.all_done) { finish(d); return true; }
                    if (d.phase_done) break;
                    offset = d.next_offset;
                }
                el('bom-status-' + ph).textContent = '✅ Done';
                el('bom-status-' + ph).style.color = '#059669';
                return false;
            }

            btn.addEventListener('click', async function () {
                btn.disabled = true;
                btn.textContent = '⏳ Preparing…';
                widget.style.display = 'block';
                errBox.style.display = 'none';
                doneMsg.style.display = 'none';
                try {
                    el('bom-overall-label').textContent = 'Fetching & parsing trees from old server…';
                    var upsert = el('bom-upsert').checked ? '1' : '0';
                    var prep = await post({ action: 'import_bom_prepare', upsert: upsert });

                    totals.items = prep.totals.items;
                    totals.edges = prep.totals.edges;
                    totals.stock = prep.totals.stock;
                    PHASES.forEach(function (p) { updatePhase(p[0]); });
                    updateOverall();

                    btn.textContent = '⏳ Importing…';
                    for (var i = 0; i < PHASES.length; i++) {
                        el('bom-overall-label').textContent = 'Importing ' + PHASES[i][1] + '…';
                        var allDone = await runPhase(PHASES[i][0], prep.token);
                        if (allDone) return;
                    }
                } catch (err) {
                    showError(err.message);
                    btn.textContent = '⬇ Retry Import';
                    btn.disabled = false;
                }
            });
        })();
        </script>

        <hr style="margin:32px 0;border:none;border-top:1px solid var(--border);">

        <!-- ── Import Transactions & Shipments (chunked AJAX) ─────────────── -->
        <div style="margin-bottom:10px;">
            <h3 style="margin:0 0 4px;">Import Transactions &amp; Shipments</h3>
        </div>

        <?php
        // Native table counts. Shipments/receipts are combined by S_Order No into
        // inv_shipments (ref_doc 'OLD-SORD-%' for combined, 'OLD-SHP-%'/'OLD-RCV-%'
        // for blank-order rows), so count Ship # records by the kind of line they
        // carry rather than by ref_doc prefix. POs go to purchase_orders.
        $nativeShipCount = (int)db_val(
            "SELECT COUNT(DISTINCT l.shipment_id)
               FROM inv_shipment_lines l
               JOIN inv_shipments sh ON sh.id = l.shipment_id
              WHERE sh.ref_doc LIKE 'OLD-%' AND l.line_kind = 'ship'", [], 0);
        $nativeRcvCount  = (int)db_val(
            "SELECT COUNT(DISTINCT l.shipment_id)
               FROM inv_shipment_lines l
               JOIN inv_shipments sh ON sh.id = l.shipment_id
              WHERE sh.ref_doc LIKE 'OLD-%' AND l.line_kind = 'receive'", [], 0);
        $nativePoCount   = (int)db_val(
            "SELECT COUNT(*) FROM purchase_orders po
             JOIN inv_shipments sh ON sh.id = po.shipment_id
             WHERE sh.ref_doc LIKE 'OLD-%'", [], 0);
        $nativeTxnCount  = (int)db_val('SELECT COUNT(*) FROM old_inv_txns', [], 0);
        $txnCounts = [
            'Transactions'    => $nativeTxnCount,
            'Shipments'       => $nativeShipCount,
            'Receipts'        => $nativeRcvCount,
            'Purchase Orders' => $nativePoCount,
        ];
        ?>

        <!-- Currently-stored row counts -->
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:6px;" id="txn-stored-counts">
            <?php foreach ($txnCounts as $label => $cnt): ?>
            <div style="text-align:center;min-width:90px;background:#f3f4f6;
                        border-radius:8px;padding:14px 20px;">
                <div style="font-size:22px;font-weight:700;color:#374151;"><?= number_format($cnt) ?></div>
                <div style="font-size:11px;color:#6b7280;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($nativeShipCount > 0 || $nativeRcvCount > 0): ?>
        <div style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <a class="btn btn-ghost" href="<?= h(url('/inventory_shiprcpt.php')) ?>" style="font-size:13px;">
                📦 View Shipment List →
            </a>
            <?php if ($nativePoCount > 0): ?>
            <a class="btn btn-ghost" href="<?= h(url('/purchase_orders.php')) ?>" style="font-size:13px;">
                🧾 View Purchase Orders →
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p style="font-size:13px;color:#6b7280;margin:0 0 14px;">
            Fetches all transactions, shipments and receipts from
            <code>api_export_transactions.php</code> on the old server in chunks of 500 rows.
            Transactions create audit-only <code>inv_txns</code> ledger rows (no stock change).
            Shipments and receipts are <strong>combined by their old S_Order No</strong>
            (custom field <code>cfv_9</code>): every shipment and receipt sharing an S_Order No
            collapses into one <code>inv_shipments</code> record (one Ship #) carrying both ship
            and receive lines, and a single purchase order whose <strong>PO number is that exact
            S_Order No</strong>. Rows with no S_Order No get their own Ship # and an
            auto-generated PO number. Run <strong>Delete All Inventory Records</strong> before
            re-importing — the import refuses to run if <code>inv_txns</code> / <code>inv_shipments</code>
            already contain rows. As a final step it fetches stock from
            <code>tree7-5.php</code> and writes the available quantities into MagDyn —
            old <strong>Magdyn</strong> → location <code>Magdyn</code> (available),
            old <strong>Rejection_Return</strong> → location <code>LOC-Rej</code>.
        </p>

        <!-- Progress widget (hidden until import starts) -->
        <div id="txn-import-widget" style="display:none;margin-bottom:20px;">

            <!-- Overall bar -->
            <div style="margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;
                            font-size:13px;color:#374151;margin-bottom:4px;">
                    <span id="txn-overall-label">Starting…</span>
                    <span id="txn-overall-pct">0%</span>
                </div>
                <div style="background:#e5e7eb;border-radius:999px;height:14px;overflow:hidden;">
                    <div id="txn-overall-bar"
                         style="height:100%;width:0%;background:linear-gradient(90deg,#3b82f6,#6366f1);
                                border-radius:999px;transition:width .4s ease;"></div>
                </div>
            </div>

            <!-- Per-table stats table -->
            <table style="width:100%;border-collapse:collapse;font-size:13px;" id="txn-stats-table">
                <thead>
                    <tr style="background:#f9fafb;text-align:left;">
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;">Table</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">Total</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">Done</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">New</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;text-align:right;">Updated</th>
                        <th style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ([
                        'txns'      => 'Transactions',
                        'shipments' => 'Shipments',
                        'receipts'  => 'Receipts',
                        'po'        => 'Purchase Orders',
                        'stock'     => 'Stock (Magdyn → Available, Rejection_Return → LOC-Rej)',
                    ] as $key => $lbl): ?>
                    <tr id="txn-row-<?= $key ?>">
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;"><?= h($lbl) ?></td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;text-align:right;" id="txn-total-<?= $key ?>">—</td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;text-align:right;" id="txn-done-<?= $key ?>">0</td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;text-align:right;" id="txn-new-<?= $key ?>">0</td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;text-align:right;" id="txn-upd-<?= $key ?>">0</td>
                        <td style="padding:7px 10px;border:1px solid #e5e7eb;color:#6b7280;" id="txn-status-<?= $key ?>">Waiting</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Per-table progress bars -->
            <div style="margin-top:14px;" id="txn-sub-bars">
                <?php foreach ([
                    'txns'      => 'Transactions',
                    'shipments' => 'Shipments',
                    'receipts'  => 'Receipts',
                    'po'        => 'Purchase Orders',
                    'stock'     => 'Stock Quantities',
                ] as $key => $lbl): ?>
                <div style="margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;
                                font-size:12px;color:#6b7280;margin-bottom:2px;">
                        <span><?= h($lbl) ?></span>
                        <span id="txn-sub-pct-<?= $key ?>">0%</span>
                    </div>
                    <div style="background:#e5e7eb;border-radius:999px;height:8px;overflow:hidden;">
                        <div id="txn-sub-bar-<?= $key ?>"
                             style="height:100%;width:0%;background:#10b981;
                                    border-radius:999px;transition:width .3s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="txn-import-error" style="display:none;margin-top:14px;"
                 class="alert alert-error"></div>
            <div id="txn-import-done-msg" style="display:none;margin-top:14px;"
                 class="alert alert-success">
                ✅ Import complete! &nbsp;
                <a href="<?= h(url('/inventory_shiprcpt.php')) ?>" style="font-weight:600;">
                    📦 View Shipment List →
                </a>
                &nbsp;
                <a href="<?= h(url('/purchase_orders.php')) ?>" style="font-weight:600;">
                    🧾 View Purchase Orders →
                </a>
                &nbsp; or reload this page to refresh the row counts.
            </div>
        </div>

        <!-- Start button -->
        <div id="txn-import-btn-wrap">
            <button id="txn-import-btn"
                    class="btn btn-primary"
                    <?= $txnConnectError ? 'disabled' : '' ?>
                    data-csrf-name="<?= h($GLOBALS['APP']['csrf_field']) ?>"
                    data-csrf-token="<?= h(csrf_token()) ?>"
                    data-url="<?= h(url('/bom_old_import.php')) ?>">
                ⬇ Import Transactions &amp; Shipments
            </button>
            <?php if ($txnConnectError): ?>
            <p style="font-size:12px;color:#dc2626;margin:6px 0 0;">
                Transactions API unreachable — deploy <code>api_export_transactions.php</code>
                to <code>/inventory/</code> on the old server first.
            </p>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            'use strict';

            var btn      = document.getElementById('txn-import-btn');
            var widget   = document.getElementById('txn-import-widget');
            var btnWrap  = document.getElementById('txn-import-btn-wrap');
            var errBox   = document.getElementById('txn-import-error');
            var doneMsg  = document.getElementById('txn-import-done-msg');

            var TABLES   = ['txns', 'shipments', 'receipts', 'po'];
            var CHUNK    = 500;

            // Accumulators per table
            var state = {};
            TABLES.forEach(function(t) {
                state[t] = { total: 0, done: 0, inserted: 0, skipped: 0, finished: false };
            });

            function el(id) { return document.getElementById(id); }
            function fmt(n)  { return Number(n).toLocaleString(); }

            function pct(done, total) {
                if (!total) return 0;
                return Math.min(100, Math.round(done / total * 100));
            }

            function setBar(id, pctVal) {
                var bar = el(id);
                if (bar) bar.style.width = pctVal + '%';
            }

            function updateOverall(counts) {
                var totalSum = 0, doneSum = 0;
                TABLES.forEach(function(t) {
                    totalSum += counts[t] || 0;
                    doneSum  += state[t].done;
                });
                var p = pct(doneSum, totalSum);
                setBar('txn-overall-bar', p);
                el('txn-overall-pct').textContent = p + '%';
                var active = TABLES.filter(function(t) { return !state[t].finished; });
                el('txn-overall-label').textContent = active.length
                    ? 'Importing ' + active[0] + '…'
                    : 'Finishing up…';
            }

            function updateTableRow(t, counts) {
                var s = state[t];
                el('txn-total-'  + t).textContent  = counts[t] ? fmt(counts[t]) : '—';
                el('txn-done-'   + t).textContent  = fmt(s.done);
                el('txn-new-'    + t).textContent  = fmt(s.inserted);
                el('txn-upd-'    + t).textContent  = fmt(s.skipped);
                var p = pct(s.done, counts[t]);
                setBar('txn-sub-bar-' + t, p);
                el('txn-sub-pct-' + t).textContent = p + '%';
                if (s.finished) {
                    el('txn-status-' + t).textContent  = '✅ Done';
                    el('txn-status-' + t).style.color  = '#059669';
                } else if (s.done > 0) {
                    el('txn-status-' + t).textContent  = '⏳ ' + p + '%';
                    el('txn-status-' + t).style.color  = '#2563eb';
                }
            }

            function showError(msg) {
                errBox.textContent = '❌ ' + msg;
                errBox.style.display = 'block';
            }

            async function postChunk(url, csrfName, csrfToken, table, offset) {
                var body = new URLSearchParams();
                body.append('action',  'import_txns_chunk');
                body.append('table',   table);
                body.append('offset',  offset);
                body.append('limit',   CHUNK);
                body.append(csrfName,  csrfToken);

                var resp = await fetch(url, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                               'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString()
                });
                if (!resp.ok) {
                    var txt = await resp.text();
                    throw new Error('HTTP ' + resp.status + ': ' + txt.substring(0, 200));
                }
                return resp.json();
            }

            async function importTable(url, csrfName, csrfToken, counts, table) {
                el('txn-status-' + table).textContent = '⏳ 0%';
                el('txn-status-' + table).style.color = '#2563eb';
                el('txn-total-'  + table).textContent = counts[table] ? fmt(counts[table]) : '?';

                var offset = 0;
                while (true) {
                    var result = await postChunk(url, csrfName, csrfToken, table, offset);
                    if (result.error) throw new Error('[' + table + '] ' + result.error);

                    state[table].inserted += result.inserted || 0;
                    state[table].skipped  += result.skipped  || 0;
                    state[table].done     += result.count    || 0;
                    updateTableRow(table, counts);
                    updateOverall(counts);

                    if (result.done) break;
                    offset += CHUNK;
                }
                state[table].finished = true;
                updateTableRow(table, counts);
                updateOverall(counts);
            }

            // Final step: fetch stock from tree7-5.php and write the available
            // quantities (Magdyn → Available, Rejection_Return → LOC-Rej).
            // Single request — not chunked like the four data tables above.
            async function importStock(url, csrfName, csrfToken) {
                el('txn-status-stock').textContent = '⏳ …';
                el('txn-status-stock').style.color = '#2563eb';

                var body = new URLSearchParams();
                body.append('action', 'import_stock_from_tree');
                body.append(csrfName, csrfToken);

                var resp = await fetch(url, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                               'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString()
                });
                var data;
                try { data = await resp.json(); }
                catch (e) { throw new Error('[stock] non-JSON response (HTTP ' + resp.status + ')'); }
                if (!resp.ok || data.error) throw new Error('[stock] ' + (data.error || ('HTTP ' + resp.status)));

                el('txn-total-stock').textContent = fmt(data.items   || 0);
                el('txn-done-stock').textContent  = fmt(data.items   || 0);
                el('txn-new-stock').textContent   = fmt(data.updated || 0);
                setBar('txn-sub-bar-stock', 100);
                el('txn-sub-pct-stock').textContent = '100%';
                el('txn-status-stock').textContent = '✅ Done';
                el('txn-status-stock').style.color = '#059669';
            }

            btn.addEventListener('click', async function () {
                btn.disabled   = true;
                btn.textContent = '⏳ Importing…';
                widget.style.display = 'block';
                errBox.style.display = 'none';
                doneMsg.style.display = 'none';

                var csrfName  = btn.dataset.csrfName;
                var csrfToken = btn.dataset.csrfToken;
                var url       = btn.dataset.url;

                try {
                    // 1. Fetch row counts from old server
                    el('txn-overall-label').textContent = 'Fetching counts…';
                    var countsResp = await fetch(url + '?ajax=txn_counts', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!countsResp.ok) throw new Error('Could not fetch row counts (HTTP ' + countsResp.status + ')');
                    var counts = await countsResp.json();
                    if (counts.error) throw new Error('Counts error: ' + counts.error);

                    // 2. Import each table sequentially
                    for (var i = 0; i < TABLES.length; i++) {
                        await importTable(url, csrfName, csrfToken, counts, TABLES[i]);
                    }

                    // 3. Final step — update stock quantities from tree7-5.php
                    el('txn-overall-label').textContent = 'Updating stock quantities from tree7-5.php…';
                    await importStock(url, csrfName, csrfToken);

                    // Done!
                    el('txn-overall-label').textContent = 'Import complete';
                    setBar('txn-overall-bar', 100);
                    el('txn-overall-pct').textContent   = '100%';
                    doneMsg.style.display = 'block';
                    btn.textContent = '✅ Done — run again?';
                    btn.disabled = false;

                } catch (err) {
                    showError(err.message);
                    btn.textContent = '⬇ Retry Import';
                    btn.disabled = false;
                }
            });
        })();
        </script>

    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
