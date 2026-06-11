<?php
/**
 * Old Inventory — Transaction/Shipment/Receipt/PO Export API
 *
 * Deploy to: old server at /inventory/api_export_transactions.php
 * PHP 5.6 compatible — no ??, no return types, no scalar hints.
 *
 * Endpoints (all require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=ping                          health check
 *   ?action=counts_json                   total row counts for all 4 tables
 *   ?action=all_txns_json      [&offset=0&limit=500]
 *   ?action=all_shipments_json [&offset=0&limit=500]
 *   ?action=all_receipts_json  [&offset=0&limit=500]
 *   ?action=all_po_json        [&offset=0&limit=500]
 */

define('API_TOKEN', 'MAGDYN_IMPORT_SECRET');

ob_start();
include 'config.php';
ob_end_clean();

// ------------------------------------------------------------------
// Token guard
// ------------------------------------------------------------------
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token !== API_TOKEN) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

// Pagination params — cap limit at 1000 to protect server memory
$offset = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
$limit  = min(1000, max(1, (int)(isset($_GET['limit'])  ? $_GET['limit']  : 500)));

// ------------------------------------------------------------------
// ping
// ------------------------------------------------------------------
if ($action === 'ping') {
    echo json_encode(array('ok' => true, 'server' => 'api_export_transactions'));
    exit;
}

// ------------------------------------------------------------------
// counts_json — row counts for all 4 tables (fast, no joins needed)
// ------------------------------------------------------------------
if ($action === 'counts_json') {
    $queries = array(
        'txns'      => 'SELECT COUNT(*) FROM inventory_transaction',
        'shipments' => 'SELECT COUNT(*) FROM shipment',
        'receipts'  => 'SELECT COUNT(*) FROM receipt',
        'po'        => 'SELECT COUNT(*) FROM po',
    );
    $counts = array();
    foreach ($queries as $key => $sql) {
        $res = mysqli_query($con, $sql);
        if (!$res) {
            echo json_encode(array('error' => 'Count query failed for ' . $key . ': ' . mysqli_error($con)));
            exit;
        }
        $row = mysqli_fetch_row($res);
        $counts[$key] = (int)$row[0];
    }
    echo json_encode($counts);
    exit;
}

// ------------------------------------------------------------------
// all_txns_json — inventory_transaction with joins, paginated
// ------------------------------------------------------------------
if ($action === 'all_txns_json') {
    $sql = "
        SELECT
            it.inventory_transaction_id  AS old_id,
            it.transaction_id            AS old_transaction_id,
            it.quantity,
            it.file_url,
            t.note,
            t.creation_date              AS txn_date,
            t.modified_date              AS txn_modified_date,
            tt.short_description         AS txn_type,
            im.inventory_model_id        AS item_model_id,
            im.inventory_model_code      AS item_code,
            im.short_description         AS item_name,
            loc_src.short_description    AS source_location,
            loc_dst.short_description    AS dest_location,
            CONCAT(ua.first_name, ' ', ua.last_name) AS created_by_name
        FROM inventory_transaction it
        JOIN `transaction` t
            ON t.transaction_id = it.transaction_id
        JOIN transaction_type tt
            ON tt.transaction_type_id = t.transaction_type_id
        JOIN inventory_location il
            ON il.inventory_location_id = it.inventory_location_id
        JOIN inventory_model im
            ON im.inventory_model_id = il.inventory_model_id
        LEFT JOIN location loc_src
            ON loc_src.location_id = it.source_location_id
        LEFT JOIN location loc_dst
            ON loc_dst.location_id = it.destination_location_id
        LEFT JOIN user_account ua
            ON ua.user_account_id = t.created_by
        ORDER BY it.inventory_transaction_id ASC
        LIMIT " . $limit . " OFFSET " . $offset . "
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Query failed: ' . mysqli_error($con)));
        exit;
    }
    $rows = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    echo json_encode(array('transactions' => $rows, 'count' => count($rows)));
    exit;
}

// ------------------------------------------------------------------
// all_shipments_json — shipment with joins, paginated
// ------------------------------------------------------------------
if ($action === 'all_shipments_json') {
    $sql = "
        SELECT
            s.shipment_id                       AS old_shipment_id,
            s.shipment_number,
            s.transaction_id                    AS old_transaction_id,
            s.ship_date,
            s.tracking_number,
            IF(s.shipped_flag = b'1', 1, 0)     AS shipped,
            cour.short_description              AS courier_name,
            fc.short_description                AS from_company,
            tc.short_description                AS to_company,
            t.note                              AS txn_note,
            t.creation_date                     AS txn_date,
            t.modified_date                     AS txn_modified_date,
            (SELECT COALESCE(MAX(it2.modified_date), MAX(it2.creation_date))
               FROM inventory_transaction it2
              WHERE it2.transaction_id = s.transaction_id) AS event_date,
            -- S_Order No = the old PO number. Stored in the shipment custom-field
            -- helper column cfv_9, which is custom_field_id 9 ('S_Order No' in the
            -- `custom_field` table). This becomes the system PO number on import.
            scf.cfv_9                           AS s_order_no
        FROM shipment s
        JOIN `transaction` t
            ON t.transaction_id = s.transaction_id
        LEFT JOIN courier cour
            ON cour.courier_id = s.courier_id
        LEFT JOIN company fc
            ON fc.company_id = s.from_company_id
        LEFT JOIN company tc
            ON tc.company_id = s.to_company_id
        LEFT JOIN shipment_custom_field_helper scf
            ON scf.shipment_id = s.shipment_id
        ORDER BY s.shipment_id ASC
        LIMIT " . $limit . " OFFSET " . $offset . "
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Query failed: ' . mysqli_error($con)));
        exit;
    }
    $rows = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    echo json_encode(array('shipments' => $rows, 'count' => count($rows)));
    exit;
}

// ------------------------------------------------------------------
// all_receipts_json — receipt with joins, paginated
// ------------------------------------------------------------------
if ($action === 'all_receipts_json') {
    $sql = "
        SELECT
            r.receipt_id                AS old_receipt_id,
            r.receipt_number,
            r.transaction_id            AS old_transaction_id,
            r.receipt_date,
            r.due_date,
            r.received_flag,
            fc.short_description        AS from_company,
            t.note                      AS txn_note,
            t.creation_date             AS txn_date,
            t.modified_date             AS txn_modified_date,
            (SELECT COALESCE(MAX(it2.modified_date), MAX(it2.creation_date))
               FROM inventory_transaction it2
              WHERE it2.transaction_id = r.transaction_id) AS event_date,
            -- S_Order No = old PO number (receipt custom-field helper cfv_9 =
            -- custom_field_id 9, 'S_Order No'). Becomes the system PO number.
            rcf.cfv_9                   AS s_order_no
        FROM receipt r
        JOIN `transaction` t
            ON t.transaction_id = r.transaction_id
        LEFT JOIN company fc
            ON fc.company_id = r.from_company_id
        LEFT JOIN receipt_custom_field_helper rcf
            ON rcf.receipt_id = r.receipt_id
        ORDER BY r.receipt_id ASC
        LIMIT " . $limit . " OFFSET " . $offset . "
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Query failed: ' . mysqli_error($con)));
        exit;
    }
    $rows = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    echo json_encode(array('receipts' => $rows, 'count' => count($rows)));
    exit;
}

// ------------------------------------------------------------------
// all_po_json — purchase orders, paginated
// ------------------------------------------------------------------
if ($action === 'all_po_json') {
    $sql = "
        SELECT
            p.po                        AS old_po_id,
            p.Customer                  AS customer,
            p.`Customer Contact`        AS customer_contact,
            p.Address                   AS address,
            p.`Shipping Courier`        AS shipping_courier,
            p.`Shipment Type`           AS shipment_type,
            p.Notes                     AS notes,
            p.po_type,
            p.Product                   AS product,
            p.Quantity                  AS quantity,
            p.due_date,
            p.po_ref_no,
            p.price,
            p.GST                       AS gst,
            p.UOM                       AS uom,
            p.gst_per,
            p.po_create_date,
            p.payment_terms,
            p.internal_notes,
            p.special_instruction,
            p.reference,
            p.long_description
        FROM po p
        ORDER BY p.po ASC
        LIMIT " . $limit . " OFFSET " . $offset . "
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Query failed: ' . mysqli_error($con)));
        exit;
    }
    $rows = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    echo json_encode(array('po' => $rows, 'count' => count($rows)));
    exit;
}

// ------------------------------------------------------------------
// all_shipments_with_lines_json
//
// Join path (verified against schema):
//   shipment.transaction_id
//     → inventory_transaction.transaction_id
//     → inventory_transaction.inventory_location_id
//     → inventory_location.inventory_location_id
//     → inventory_location.inventory_model_id
//     → inventory_model.inventory_model_id
//     → inventory_model.inventory_model_code  (item code)
//     → inventory_model.short_description     (item name)
//     + inventory_transaction.quantity
//
// Step 1: page shipment_ids with LIMIT/OFFSET.
// Step 2: one JOIN query over those IDs — returns one row per
//         (shipment × inventory_transaction line).
// PHP groups rows back into {shipment header + lines[]}.
// ------------------------------------------------------------------
if ($action === 'all_shipments_with_lines_json') {

    // Step 1 — get this page of shipment_ids
    $idRes = mysqli_query($con,
        'SELECT shipment_id FROM shipment ORDER BY shipment_id ASC'
        . ' LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    if (!$idRes) {
        echo json_encode(array('error' => 'ID query failed: ' . mysqli_error($con)));
        exit;
    }
    $shipmentIds = array();
    while ($r = mysqli_fetch_row($idRes)) {
        $shipmentIds[] = (int)$r[0];
    }

    if (empty($shipmentIds)) {
        echo json_encode(array('shipments' => array(), 'count' => 0));
        exit;
    }

    $idList = implode(',', $shipmentIds);

    // Step 2 — full join: shipment header + inventory_transaction lines
    $sql = "
        SELECT
            s.shipment_id                       AS old_shipment_id,
            s.shipment_number,
            s.transaction_id                    AS old_transaction_id,
            s.ship_date,
            s.tracking_number,
            IF(s.shipped_flag = b'1', 1, 0)     AS shipped,
            cour.short_description              AS courier_name,
            fc.short_description                AS from_company,
            tc.short_description                AS to_company,
            t.note                              AS txn_note,
            t.creation_date                     AS txn_date,
            t.modified_date                     AS txn_modified_date,
            (SELECT COALESCE(MAX(it2.modified_date), MAX(it2.creation_date))
               FROM inventory_transaction it2
              WHERE it2.transaction_id = s.transaction_id) AS event_date,
            -- S_Order No = old PO number (shipment custom-field helper cfv_9 =
            -- custom_field_id 9, 'S_Order No'). Drives the system PO number and
            -- the combine-by-order grouping on import.
            scf.cfv_9                           AS s_order_no,
            im.inventory_model_id               AS item_model_id,
            im.inventory_model_code             AS item_code,
            im.short_description                AS item_name,
            it.quantity                         AS quantity
        FROM shipment s
        JOIN `transaction` t
            ON t.transaction_id = s.transaction_id
        LEFT JOIN courier cour
            ON cour.courier_id = s.courier_id
        LEFT JOIN company fc
            ON fc.company_id = s.from_company_id
        LEFT JOIN company tc
            ON tc.company_id = s.to_company_id
        LEFT JOIN shipment_custom_field_helper scf
            ON scf.shipment_id = s.shipment_id
        LEFT JOIN inventory_transaction it
            ON it.transaction_id = s.transaction_id
        LEFT JOIN inventory_location il
            ON il.inventory_location_id = it.inventory_location_id
        LEFT JOIN inventory_model im
            ON im.inventory_model_id = il.inventory_model_id
        WHERE s.shipment_id IN ($idList)
        ORDER BY s.shipment_id ASC, it.inventory_transaction_id ASC
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Join query failed: ' . mysqli_error($con)));
        exit;
    }

    // Group flat rows into shipments with lines[]
    $shipments    = array();
    $shipmentIdx  = array(); // shipment_id → index in $shipments
    while ($row = mysqli_fetch_assoc($res)) {
        $sid = (int)$row['old_shipment_id'];
        if (!isset($shipmentIdx[$sid])) {
            $shipmentIdx[$sid]  = count($shipments);
            $shipments[]        = array(
                'old_shipment_id'    => $row['old_shipment_id'],
                'shipment_number'    => $row['shipment_number'],
                'old_transaction_id' => $row['old_transaction_id'],
                'ship_date'          => $row['ship_date'],
                'tracking_number'    => $row['tracking_number'],
                'shipped'            => $row['shipped'],
                'courier_name'       => $row['courier_name'],
                'from_company'       => $row['from_company'],
                'to_company'         => $row['to_company'],
                'txn_note'           => $row['txn_note'],
                'txn_date'           => $row['txn_date'],
                'txn_modified_date'  => $row['txn_modified_date'],
                'event_date'         => $row['event_date'],
                's_order_no'         => $row['s_order_no'],   // old PO number (cfv_9)
                'lines'              => array(),
            );
        }
        // Only add a line when an inventory_transaction row was joined
        if ($row['item_model_id'] !== null && $row['item_model_id'] !== '') {
            $shipments[$shipmentIdx[$sid]]['lines'][] = array(
                'item_model_id' => $row['item_model_id'],   // numeric id — BOM import uses this as inv_items.code
                'item_code'     => $row['item_code'],        // inventory_model_code (alphanumeric barcode)
                'item_name'     => $row['item_name'],
                'quantity'      => $row['quantity'],
            );
        }
    }

    echo json_encode(array('shipments' => $shipments, 'count' => count($shipments)));
    exit;
}

// ------------------------------------------------------------------
// all_receipts_with_lines_json
//
// Join path (verified against schema):
//   receipt.transaction_id
//     → inventory_transaction.transaction_id
//     → inventory_transaction.inventory_location_id
//     → inventory_location.inventory_location_id
//     → inventory_location.inventory_model_id
//     → inventory_model.inventory_model_id
//     → inventory_model.inventory_model_code  (item code)
//     → inventory_model.short_description     (item name)
//     + inventory_transaction.quantity
// ------------------------------------------------------------------
if ($action === 'all_receipts_with_lines_json') {

    // Step 1 — get this page of receipt_ids
    $idRes = mysqli_query($con,
        'SELECT receipt_id FROM receipt ORDER BY receipt_id ASC'
        . ' LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    if (!$idRes) {
        echo json_encode(array('error' => 'ID query failed: ' . mysqli_error($con)));
        exit;
    }
    $receiptIds = array();
    while ($r = mysqli_fetch_row($idRes)) {
        $receiptIds[] = (int)$r[0];
    }

    if (empty($receiptIds)) {
        echo json_encode(array('receipts' => array(), 'count' => 0));
        exit;
    }

    $idList = implode(',', $receiptIds);

    // Step 2 — full join: receipt header + inventory_transaction lines
    $sql = "
        SELECT
            r.receipt_id                AS old_receipt_id,
            r.receipt_number,
            r.transaction_id            AS old_transaction_id,
            r.receipt_date,
            r.due_date,
            r.received_flag,
            fc.short_description        AS from_company,
            t.note                      AS txn_note,
            t.creation_date             AS txn_date,
            t.modified_date             AS txn_modified_date,
            (SELECT COALESCE(MAX(it2.modified_date), MAX(it2.creation_date))
               FROM inventory_transaction it2
              WHERE it2.transaction_id = r.transaction_id) AS event_date,
            -- S_Order No = old PO number (receipt custom-field helper cfv_9 =
            -- custom_field_id 9, 'S_Order No'). Drives the system PO number and
            -- the combine-by-order grouping on import.
            rcf.cfv_9                   AS s_order_no,
            im.inventory_model_id       AS item_model_id,
            im.inventory_model_code     AS item_code,
            im.short_description        AS item_name,
            it.quantity                 AS quantity
        FROM receipt r
        JOIN `transaction` t
            ON t.transaction_id = r.transaction_id
        LEFT JOIN company fc
            ON fc.company_id = r.from_company_id
        LEFT JOIN receipt_custom_field_helper rcf
            ON rcf.receipt_id = r.receipt_id
        LEFT JOIN inventory_transaction it
            ON it.transaction_id = r.transaction_id
        LEFT JOIN inventory_location il
            ON il.inventory_location_id = it.inventory_location_id
        LEFT JOIN inventory_model im
            ON im.inventory_model_id = il.inventory_model_id
        WHERE r.receipt_id IN ($idList)
        ORDER BY r.receipt_id ASC, it.inventory_transaction_id ASC
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Join query failed: ' . mysqli_error($con)));
        exit;
    }

    // Group flat rows into receipts with lines[]
    $receipts   = array();
    $receiptIdx = array(); // receipt_id → index in $receipts
    while ($row = mysqli_fetch_assoc($res)) {
        $rid = (int)$row['old_receipt_id'];
        if (!isset($receiptIdx[$rid])) {
            $receiptIdx[$rid] = count($receipts);
            $receipts[]       = array(
                'old_receipt_id'     => $row['old_receipt_id'],
                'receipt_number'     => $row['receipt_number'],
                'old_transaction_id' => $row['old_transaction_id'],
                'receipt_date'       => $row['receipt_date'],
                'due_date'           => $row['due_date'],
                'received_flag'      => $row['received_flag'],
                'from_company'       => $row['from_company'],
                'txn_note'           => $row['txn_note'],
                'txn_date'           => $row['txn_date'],
                'txn_modified_date'  => $row['txn_modified_date'],
                'event_date'         => $row['event_date'],
                's_order_no'         => $row['s_order_no'],   // old PO number (cfv_9)
                'lines'              => array(),
            );
        }
        if ($row['item_model_id'] !== null && $row['item_model_id'] !== '') {
            $receipts[$receiptIdx[$rid]]['lines'][] = array(
                'item_model_id' => $row['item_model_id'],   // numeric id — BOM import uses this as inv_items.code
                'item_code'     => $row['item_code'],        // inventory_model_code (alphanumeric barcode)
                'item_name'     => $row['item_name'],
                'quantity'      => $row['quantity'],
            );
        }
    }

    echo json_encode(array('receipts' => $receipts, 'count' => count($receipts)));
    exit;
}

echo json_encode(array('error' => 'Unknown action: ' . $action));
