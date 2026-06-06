<?php
/**
 * MagDyn — Job Card API (Approval-to-Ship workflow)
 * Created: 2026-05-27 IST
 *
 * Service-to-service endpoint. Two ops, both POST:
 *
 *   ?op=create_from_so
 *     Called by the billing/SO system when a sales-order line is
 *     ready to be put on the production queue. Creates a new job
 *     card in status 'qc_pending'. partNo/partName are NOT in the
 *     payload — the inv_code is the link to inv_items and naming
 *     is always derived at render time.
 *
 *     Body:
 *       {
 *         "inv_code":      "ITEM-001",      // required, FK to inv_items.code
 *         "po_no":         "PO-2024-001",   // required
 *         "line_no":       "01",            // optional, free text
 *         "qty":           120,             // required, numeric, > 0
 *         "delivery_date": "2026-06-15",    // optional ISO date
 *         "supplier_name": "Acme Corp",     // optional (called "customer" in user terms)
 *         "location":      "Bangalore",     // optional
 *         "ack_no":        "ACK-2024-09",   // optional (billing-system ack)
 *         "ds":            false            // optional bool, default false (drop ship?)
 *       }
 *
 *     Returns 200 {ok:true, id, jc_no} on success.
 *     Returns 404 {ok:false, error:"item_not_found"} if inv_code doesn't match.
 *     Returns 409 {ok:false, error:"duplicate"} if (po_no,line_no) already exists
 *               and is in a non-closed state.
 *     Returns 422 {ok:false, error:"validation", details:[...]} on bad input.
 *
 *   ?op=set_invoice
 *     Called by the billing system when an invoice has been raised
 *     against the job card and the goods can ship. Transitions the
 *     card to 'closed' AND triggers the inventory move:
 *       1. Creates inv_shipments + inv_shipment_lines
 *       2. Decrements stock at SHP location (item's produced qty)
 *       3. Records invoice number in shipment.notes
 *       4. Sets job_card.status = 'closed', closed_at = now,
 *          shipment_id = <new shipment id>
 *
 *     The whole thing runs in a transaction. If any step fails the
 *     job card stays at billing_pending and the API returns 422 with
 *     details — the billing team can investigate and retry.
 *
 *     Body:
 *       {
 *         "jc_no":        "JC-000123",     // required
 *         "invoice_no":   "INV-2024-555",  // required
 *         "invoice_date": "2026-06-20"     // optional, defaults today
 *       }
 *
 *     Returns 200 {ok:true, jc_no, shipment_id, shipment_no}.
 *     Returns 404 {ok:false, error:"jc_not_found"}.
 *     Returns 409 {ok:false, error:"wrong_status", current_status:"..."}
 *               if the card isn't in billing_pending.
 *     Returns 422 {ok:false, error:"inventory_fail", details:"..."} if
 *               the SHP-to-shipout move fails (no stock, location missing,
 *               etc.).
 *
 * Auth: Bearer token in Authorization header. Same token as the SO API
 *       (so_integration.bearer_token in config/app.config.php). The
 *       billing system uses the same shared secret — easier to manage
 *       and the threat model is the same.
 *
 * The probe URL ?probe=1 returns a static JSON before any bootstrap
 * runs — useful for verifying the file is reachable.
 *
 * Bootstrap is intentionally LEAN — no session, no permissions, no SSO.
 * See /api/so_pending.php for the same pattern; the comments there
 * apply here too.
 */

// ============================================================
// CORS
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// Pre-bootstrap probe — returns before any DB / config load.
// ============================================================
if (isset($_GET['probe']) && $_GET['probe'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode([
        'ok'          => true,
        'service'     => 'job_card_api',
        'php_version' => PHP_VERSION,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    exit;
}

// ============================================================
// Pre-bootstrap hardening
// ============================================================
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    error_log(sprintf('[job_card_api] fatal: %s in %s:%d', $err['message'], $err['file'], $err['line']));
    echo json_encode([
        'ok' => false, 'error' => 'server_fatal',
        'message' => 'PHP fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
    ]);
});

// ============================================================
// Lean bootstrap — config + db only.
// ============================================================
$startMs = microtime(true);
try {
    $ROOT = dirname(__DIR__);
    $APP  = require $ROOT . '/config/app.config.php';
    $DB   = require $ROOT . '/config/db.config.php';
    $GLOBALS['APP']  = $APP;
    $GLOBALS['DB']   = $DB;
    $GLOBALS['ROOT'] = $ROOT;
    if (!empty($APP['timezone'])) date_default_timezone_set($APP['timezone']);
    require_once $ROOT . '/includes/db.php';
} catch (\Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    error_log('[job_card_api] lean bootstrap failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'bootstrap_fail', 'message' => $e->getMessage()]);
    exit;
}

/**
 * Emit a JSON response and exit cleanly. Drains all output buffers
 * (eats stray echo/warning output) before writing the final body,
 * and uses fastcgi_finish_request() if available to release the
 * client connection before any post-emit cleanup runs.
 */
function jc_api_emit($status, array $payload) {
    global $startMs;
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        $elapsed = (int)round((microtime(true) - $startMs) * 1000);
        header('X-Server-Time-Ms: ' . $elapsed);
        http_response_code($status);
    }
    echo json_encode($payload);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    exit;
}

// ============================================================
// Method + auth
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jc_api_emit(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use POST.']);
}

$expected = $APP['so_integration']['bearer_token'] ?? null;
if (empty($expected)) {
    jc_api_emit(503, [
        'ok' => false, 'error' => 'not_configured',
        'message' => 'so_integration.bearer_token is not set in config/app.config.php. Add it to enable the job-card API.',
    ]);
}
$hdr = '';
if (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
    }
}
if ($hdr === '') $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (stripos($hdr, 'Bearer ') !== 0 || !hash_equals($expected, trim(substr($hdr, 7)))) {
    jc_api_emit(401, ['ok' => false, 'error' => 'unauthorized', 'message' => 'Missing or invalid bearer token.']);
}

// ============================================================
// Parse JSON body
// ============================================================
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    jc_api_emit(400, ['ok' => false, 'error' => 'bad_json', 'message' => 'Body must be a JSON object.']);
}

$op = $_GET['op'] ?? '';

// ============================================================
// op=create_from_so — create a new job card
// ============================================================
if ($op === 'create_from_so') {
    // Required: inv_code, po_no, qty.
    $invCode = isset($body['inv_code']) ? trim((string)$body['inv_code']) : '';
    $poNo    = isset($body['po_no'])    ? trim((string)$body['po_no'])    : '';
    $qty     = isset($body['qty'])      ? (float)$body['qty']             : 0.0;
    $errors = [];
    if ($invCode === '') $errors[] = 'inv_code required';
    if ($poNo === '')    $errors[] = 'po_no required';
    if ($qty <= 0)       $errors[] = 'qty must be > 0';
    if ($errors) {
        jc_api_emit(422, ['ok' => false, 'error' => 'validation', 'details' => $errors]);
    }

    // Resolve inv_code to inv_items.id.
    $item = db_one("SELECT id, code FROM inv_items WHERE code = ? AND is_active = 1", [$invCode]);
    if (!$item) {
        jc_api_emit(404, [
            'ok' => false, 'error' => 'item_not_found',
            'message' => 'No active inv_items row with code = ' . $invCode,
        ]);
    }

    // Optional fields.
    $lineNo       = isset($body['line_no'])       ? trim((string)$body['line_no']) : null;
    $deliveryDate = isset($body['delivery_date']) ? trim((string)$body['delivery_date']) : null;
    $supplier     = isset($body['supplier_name']) ? trim((string)$body['supplier_name']) : null;
    $location     = isset($body['location'])      ? trim((string)$body['location']) : null;
    $ackNo        = isset($body['ack_no'])        ? trim((string)$body['ack_no']) : null;
    $ds           = !empty($body['ds']) ? 1 : 0;

    // Validate delivery_date format (YYYY-MM-DD) without bombing on empty.
    if ($deliveryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        jc_api_emit(422, ['ok' => false, 'error' => 'validation', 'details' => ['delivery_date must be YYYY-MM-DD']]);
    }

    // Duplicate guard: if a NON-closed job card already exists for this
    // (po_no, line_no), refuse. The billing system should send each
    // line exactly once; receiving the same line twice is a bug we
    // want to surface, not silently merge.
    $dup = db_one(
        "SELECT id, jc_no, status FROM job_cards
          WHERE po_no = ? AND COALESCE(line_no,'') = COALESCE(?, '')
            AND status NOT IN ('closed','cancelled')
          LIMIT 1",
        [$poNo, $lineNo]
    );
    if ($dup) {
        jc_api_emit(409, [
            'ok' => false, 'error' => 'duplicate',
            'message' => 'A job card for this PO+line already exists and is not closed.',
            'existing' => ['id' => (int)$dup['id'], 'jc_no' => $dup['jc_no'], 'status' => $dup['status']],
        ]);
    }

    // Generate jc_no. Auto-increment ID is the source of truth; we
    // pad it to "JC-000123". This means: insert first to get the ID,
    // then update with the formatted number. Single transaction so
    // there's no window where the row has a NULL jc_no.
    try {
        db()->beginTransaction();
        db_exec(
            "INSERT INTO job_cards
               (jc_no, status, item_id, po_no, line_no, po_qty, delivery_date,
                supplier_name, location, ack_no, ds, created_by)
             VALUES (?, 'qc_pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)",
            ['__pending__', (int)$item['id'], $poNo, $lineNo, $qty,
             $deliveryDate, $supplier, $location, $ackNo, $ds]
        );
        $newId = (int)db()->lastInsertId();
        $jcNo  = sprintf('JC-%06d', $newId);
        db_exec("UPDATE job_cards SET jc_no = ? WHERE id = ?", [$jcNo, $newId]);

        // Audit event
        db_exec(
            "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_label)
             VALUES (?, 'created', ?, 'so-api')",
            [$newId, json_encode(['source' => 'so_api', 'po_no' => $poNo, 'line_no' => $lineNo, 'qty' => $qty])]
        );

        // Notify users with job_card.qc_update permission
        jc_notify_step($newId, 'qc_update', sprintf('New job card %s — QC pending', $jcNo),
                       'PO ' . $poNo . ($lineNo ? ' / line ' . $lineNo : '') . ' — ' . $qty . ' units of ' . $item['code']);

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[job_card_api/create_from_so] db error: ' . $e->getMessage());
        jc_api_emit(500, ['ok' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
    }

    jc_api_emit(200, ['ok' => true, 'id' => $newId, 'jc_no' => $jcNo]);
}

// ============================================================
// op=set_invoice — record invoice + auto-ship, transition to closed
// ============================================================
if ($op === 'set_invoice') {
    $jcNo       = isset($body['jc_no'])        ? trim((string)$body['jc_no'])        : '';
    $invoiceNo  = isset($body['invoice_no'])   ? trim((string)$body['invoice_no'])   : '';
    $invoiceDate = isset($body['invoice_date']) ? trim((string)$body['invoice_date']) : date('Y-m-d');
    $errors = [];
    if ($jcNo === '')      $errors[] = 'jc_no required';
    if ($invoiceNo === '') $errors[] = 'invoice_no required';
    if ($invoiceDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) $errors[] = 'invoice_date must be YYYY-MM-DD';
    if ($errors) {
        jc_api_emit(422, ['ok' => false, 'error' => 'validation', 'details' => $errors]);
    }

    $jc = db_one("SELECT * FROM job_cards WHERE jc_no = ? LIMIT 1", [$jcNo]);
    if (!$jc) {
        jc_api_emit(404, ['ok' => false, 'error' => 'jc_not_found', 'message' => 'No job card with jc_no = ' . $jcNo]);
    }
    if ($jc['status'] !== 'billing_pending') {
        jc_api_emit(409, [
            'ok' => false, 'error' => 'wrong_status',
            'message' => 'Job card must be in billing_pending state to receive an invoice.',
            'current_status' => $jc['status'],
        ]);
    }

    // Lookup SHP location (the produced-stock holding location).
    $shp = db_one("SELECT id, code, name FROM locations WHERE code = 'SHP' AND is_active = 1 LIMIT 1");
    if (!$shp) {
        jc_api_emit(422, [
            'ok' => false, 'error' => 'inventory_fail',
            'details' => 'SHP location not found / inactive. The Step 4 ATS save should have created stock there.',
        ]);
    }

    // Verify enough stock at SHP for this item.
    $itemId = (int)$jc['item_id'];
    $qty    = (float)$jc['sub_qty'];
    if ($qty <= 0) {
        jc_api_emit(422, [
            'ok' => false, 'error' => 'inventory_fail',
            'details' => 'Job card sub_qty is 0 or missing — Step 3 production was never completed?',
        ]);
    }
    $stockRow = db_one(
        "SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?",
        [$itemId, (int)$shp['id']]
    );
    $stockOnHand = $stockRow ? (float)$stockRow['qty'] : 0.0;
    if ($stockOnHand < $qty) {
        jc_api_emit(422, [
            'ok' => false, 'error' => 'inventory_fail',
            'details' => sprintf('Insufficient SHP stock: have %s, need %s for item id %d.',
                                 $stockOnHand, $qty, $itemId),
        ]);
    }

    // Transactional inventory move + shipment create + card close.
    try {
        db()->beginTransaction();

        // Create the shipment header.
        // ship_no: SH-NNNNNN matching MagDyn's pattern.
        // status: 'closed' since we're shipping atomically here (no
        //         draft/approved workflow — billing API says "ship now").
        // mode:   'ship' (vendor-outgoing semantics; we're sending the
        //         goods OUT to the customer).
        // vendor_id: NULL — the customer comes from supplier_name
        //         string, not a vendors record (vendors are our suppliers
        //         in MagDyn's domain model, not our customers).
        // ref_doc: jc_no so the shipment back-links to the job card.
        // notes:   invoice + customer summary so it's visible without
        //         opening the linked job card.
        db_exec(
            "INSERT INTO inv_shipments
               (ship_no, vendor_id, mode,
                status, ref_doc, notes, is_rework, created_by)
             VALUES (?, NULL, 'ship',
                     'closed', ?, ?, 0, NULL)",
            [
                '__pending__',
                $jcNo,
                sprintf("Auto-shipped on close of %s.\nInvoice: %s (%s)\nCustomer: %s\nDelivery: %s",
                        $jcNo, $invoiceNo, $invoiceDate,
                        $jc['supplier_name'] ?: '—',
                        $jc['location'] ?: '—'),
            ]
        );
        $shipId = (int)db()->lastInsertId();
        $shipNo = sprintf('SH-%06d', $shipId);
        db_exec("UPDATE inv_shipments SET ship_no = ? WHERE id = ?", [$shipNo, $shipId]);

        // Shipment line — one ship line for the produced qty out of SHP.
        // qty_planned + qty_shipped both set (shipping is happening atomically).
        db_exec(
            "INSERT INTO inv_shipment_lines
               (shipment_id, sort_order, line_kind, item_id, qty_planned, qty_shipped, src_location_id)
             VALUES (?, 0, 'ship', ?, ?, ?, ?)",
            [$shipId, $itemId, $qty, $qty, (int)$shp['id']]
        );

        // Decrement SHP stock.
        db_exec(
            "UPDATE inv_item_location_stock
                SET qty = qty - ?
              WHERE item_id = ? AND location_id = ?",
            [$qty, $itemId, (int)$shp['id']]
        );

        // Close the job card. Record invoice details + shipment link.
        db_exec(
            "UPDATE job_cards
                SET status = 'closed',
                    invoice_no = ?,
                    invoice_date = ?,
                    shipment_id = ?,
                    closed_at = NOW()
              WHERE id = ?",
            [$invoiceNo, $invoiceDate, $shipId, (int)$jc['id']]
        );

        // Audit event.
        db_exec(
            "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_label)
             VALUES (?, 'closed', ?, 'billing-api')",
            [(int)$jc['id'], json_encode([
                'invoice_no'   => $invoiceNo,
                'invoice_date' => $invoiceDate,
                'shipment_id'  => $shipId,
                'shipment_no'  => $shipNo,
                'qty_shipped'  => $qty,
            ])]
        );

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[job_card_api/set_invoice] db error: ' . $e->getMessage());
        jc_api_emit(500, ['ok' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
    }

    jc_api_emit(200, [
        'ok' => true,
        'jc_no'       => $jcNo,
        'shipment_id' => $shipId,
        'shipment_no' => $shipNo,
        'qty_shipped' => $qty,
    ]);
}

// ============================================================
// op=update_from_so — amendment push (qty change, date change, etc.)
// ============================================================
// Same payload as create_from_so. The billing system POSTs the full
// current state of the SO line; MagDyn matches it to the existing
// job card by (po_no, line_no) and updates the mutable fields.
//
// Field mutability is gated by card status. Once Production has
// touched the card (status >= prod_pending), the po_qty becomes
// LOCKED — changing it silently would break the partial-split math
// and confuse anyone who's already started producing against the
// original quantity. Other Step 1 fields (delivery_date, supplier_name,
// location, ack_no, ds) remain mutable through ats_pending because
// they're display-only metadata that doesn't drive any side-effects.
//
// Closed and cancelled cards refuse the update — the card is done,
// no further changes accepted via API. (A human supervisor can still
// edit via the supervisor permission, but that's outside the billing
// system's path.)
//
// Returns:
//   200 ok=true, action=updated|no_changes, with the field diff
//   404 jc_not_found     — no card matches (po_no, line_no)
//   409 wrong_status     — card is closed or cancelled
//   422 qty_locked       — po_qty change requested but production has
//                          started (status >= prod_pending)
//   422 item_change      — inv_code in payload differs from card's item;
//                          treat as a different SO line, not an amendment
//   422 validation       — bad payload (missing required, bad date format)
if ($op === 'update_from_so') {
    // Required: inv_code, po_no, qty (same as create_from_so). line_no
    // is optional but is used as part of the match key.
    $invCode = isset($body['inv_code']) ? trim((string)$body['inv_code']) : '';
    $poNo    = isset($body['po_no'])    ? trim((string)$body['po_no'])    : '';
    $qty     = isset($body['qty'])      ? (float)$body['qty']             : 0.0;
    $lineNo  = isset($body['line_no'])  ? trim((string)$body['line_no'])  : null;
    $errors = [];
    if ($invCode === '') $errors[] = 'inv_code required';
    if ($poNo === '')    $errors[] = 'po_no required';
    if ($qty <= 0)       $errors[] = 'qty must be > 0';
    if ($errors) {
        jc_api_emit(422, ['ok' => false, 'error' => 'validation', 'details' => $errors]);
    }

    // Optional Step-1 metadata fields. NULL semantics: if a field is
    // absent from the payload, treat as "no change" rather than "clear".
    // (The billing system always re-pushes the full state, but defensive
    // handling for partial pushes / older clients.)
    $hasDelivery = array_key_exists('delivery_date', $body);
    $hasSupplier = array_key_exists('supplier_name', $body);
    $hasLocation = array_key_exists('location',      $body);
    $hasAck      = array_key_exists('ack_no',        $body);
    $hasDs       = array_key_exists('ds',            $body);
    $deliveryDate = $hasDelivery ? (trim((string)$body['delivery_date']) ?: null) : null;
    $supplier     = $hasSupplier ? (trim((string)$body['supplier_name']) ?: null) : null;
    $location     = $hasLocation ? (trim((string)$body['location'])      ?: null) : null;
    $ackNo        = $hasAck      ? (trim((string)$body['ack_no'])        ?: null) : null;
    $ds           = $hasDs       ? (!empty($body['ds']) ? 1 : 0)               : null;
    if ($deliveryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        jc_api_emit(422, ['ok' => false, 'error' => 'validation', 'details' => ['delivery_date must be YYYY-MM-DD']]);
    }

    // Find the target card. Match on (po_no, line_no) — same key as
    // the duplicate guard in create_from_so. We deliberately match
    // only NON-cancelled, NON-closed cards: amendments don't reopen
    // closed cards (use a new SO line for that). We also pick the
    // PARENT card preferentially (parent_id IS NULL) so amendments
    // hit the head of any partial-split chain, not the child carrying
    // the balance.
    $jc = db_one(
        "SELECT * FROM job_cards
          WHERE po_no = ?
            AND COALESCE(line_no, '') = COALESCE(?, '')
            AND status NOT IN ('closed','cancelled')
          ORDER BY parent_id IS NULL DESC, id DESC
          LIMIT 1",
        [$poNo, $lineNo]
    );
    if (!$jc) {
        jc_api_emit(404, [
            'ok' => false, 'error' => 'jc_not_found',
            'message' => 'No active job card matches PO ' . $poNo . ' / line ' . ($lineNo ?: '(empty)') . '. If the original create_from_so push failed, retry that first.',
        ]);
    }

    // Validate the inv_code matches the card's item. Changing item is
    // not an amendment — it's a different SO line. Refuse rather than
    // silently rewrite the item_id (which would orphan stock and
    // confuse production).
    $item = db_one("SELECT id, code FROM inv_items WHERE id = ?", [(int)$jc['item_id']]);
    if (!$item || strcasecmp((string)$item['code'], $invCode) !== 0) {
        jc_api_emit(422, [
            'ok' => false, 'error' => 'item_change',
            'message' => "Card $jc[jc_no] is for item " . ($item['code'] ?? '(unknown)')
                       . ", but amendment payload's inv_code is $invCode. "
                       . "Amendments cannot change the item — cancel this card and create a new one.",
        ]);
    }

    // Diff: what's actually changing? Skip the write entirely if nothing
    // differs from the stored values — keeps the event log clean and
    // avoids spurious notifications.
    $before = [
        'po_qty'        => (float)$jc['po_qty'],
        'delivery_date' => $jc['delivery_date'],
        'supplier_name' => $jc['supplier_name'],
        'location'      => $jc['location'],
        'ack_no'        => $jc['ack_no'],
        'ds'            => (int)$jc['ds'],
    ];
    $after = $before; // start from current, overlay payload
    $after['po_qty'] = $qty;
    if ($hasDelivery) $after['delivery_date'] = $deliveryDate;
    if ($hasSupplier) $after['supplier_name'] = $supplier;
    if ($hasLocation) $after['location']      = $location;
    if ($hasAck)      $after['ack_no']        = $ackNo;
    if ($hasDs)       $after['ds']            = $ds;

    $diff = [];
    foreach ($after as $k => $v) {
        // Float compare with epsilon for po_qty; strict compare for others.
        if ($k === 'po_qty') {
            if (abs((float)$v - (float)$before[$k]) > 0.00001) $diff[$k] = ['before' => $before[$k], 'after' => $v];
        } else {
            if ((string)$v !== (string)$before[$k]) $diff[$k] = ['before' => $before[$k], 'after' => $v];
        }
    }
    if (!$diff) {
        // Nothing changed — fast-path success.
        jc_api_emit(200, [
            'ok' => true, 'action' => 'no_changes',
            'jc_no' => $jc['jc_no'],
            'message' => 'Payload matches current values; no update performed.',
        ]);
    }

    // -----------------------------------------------------------------
    // Qty change rules (revised — qty changes allowed during production):
    //
    //   qc_pending        decrease: in place
    //                     increase: in place (no production yet, just bump)
    //   prod_pending      decrease: in place
    //                     increase: create child card for the DELTA at qc_pending
    //   ats_pending       decrease: in place
    //                     increase: create child card for the DELTA at qc_pending
    //   billing_pending   decrease: REFUSED (ATS submitted, stock at SHP)
    //                     increase: REFUSED (same)
    //   closed/cancelled  already filtered out by the match query
    //
    // The child-for-increase keeps the original card's production
    // commitments intact — operators were producing against the old qty;
    // changing po_qty out from under them mid-production would be a
    // foot-gun. The child carries only the additional units and goes
    // through its own QC cycle (different lot may need re-inspection).
    // -----------------------------------------------------------------
    $qtyChanged = isset($diff['po_qty']);
    $qtyDelta   = $qtyChanged ? ((float)$after['po_qty'] - (float)$before['po_qty']) : 0.0;
    $isIncrease = $qtyChanged && $qtyDelta > 0;
    $isDecrease = $qtyChanged && $qtyDelta < 0;

    if ($qtyChanged && $jc['status'] === 'billing_pending') {
        jc_api_emit(422, [
            'ok' => false, 'error' => 'qty_locked',
            'message' => "Cannot change po_qty on $jc[jc_no] — ATS has been submitted (status 'billing_pending'). "
                       . "Stock has moved to SHP. Cancel and reissue if a qty change is required.",
            'current_status' => $jc['status'],
            'current_qty'    => $before['po_qty'],
            'requested_qty'  => $after['po_qty'],
        ]);
    }

    // For increases past qc_pending, we DON'T change po_qty on the
    // parent — we'll spin off a child for the delta. Remove po_qty
    // from the parent's diff so the UPDATE below leaves it alone.
    // Capture the delta in a separate variable for the child INSERT.
    $createChildForDelta = false;
    $childDeltaQty       = 0.0;
    if ($isIncrease && in_array($jc['status'], ['prod_pending','ats_pending'], true)) {
        $createChildForDelta = true;
        $childDeltaQty       = $qtyDelta;
        unset($diff['po_qty']);                // parent's po_qty stays
        $after['po_qty']     = $before['po_qty']; // keep parent display value
    }

    // After the qty-handling above, it's possible the only thing in
    // the diff was a po_qty increase that we just removed — so the
    // diff is now empty. Re-check the no-op case.
    if (empty($diff) && !$createChildForDelta) {
        jc_api_emit(200, [
            'ok' => true, 'action' => 'no_changes',
            'jc_no' => $jc['jc_no'],
            'message' => 'Payload matches current values; no update performed.',
        ]);
    }

    // Apply the diff. Build the UPDATE dynamically so we only touch
    // columns that actually changed. Two parallel arrays for the SET
    // clause and params.
    try {
        db()->beginTransaction();

        if (!empty($diff)) {
            $setParts = [];
            $params   = [];
            foreach ($diff as $col => $pair) {
                $setParts[] = "$col = ?";
                $params[]   = $pair['after'];
            }
            $params[] = (int)$jc['id'];
            db_exec("UPDATE job_cards SET " . implode(', ', $setParts) . " WHERE id = ?", $params);

            // Event log: record the diff for the audit timeline.
            db_exec(
                "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_label)
                 VALUES (?, 'edited', ?, 'so-api')",
                [(int)$jc['id'], json_encode(['source' => 'so_api_amendment', 'diff' => $diff])]
            );
        }

        // Spin off a child card for qty increases past qc_pending. The
        // child carries ONLY the delta (not the new total), inherits the
        // PO context (po_no, line_no, supplier, location, ack, ds) and
        // the item, but starts FRESH at qc_pending — the extra units
        // may be a different lot and warrant their own inspection.
        // parent_id links the child back. partial_reason records why.
        $childId   = null;
        $childJcNo = null;
        if ($createChildForDelta) {
            db_exec(
                "INSERT INTO job_cards
                   (jc_no, status, item_id, po_no, line_no, po_qty, delivery_date,
                    supplier_name, location, ack_no, ds,
                    parent_id, partial_reason, created_by)
                 VALUES (?, 'qc_pending', ?, ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, NULL)",
                ['__pending__', (int)$jc['item_id'], $jc['po_no'], $jc['line_no'],
                 $childDeltaQty,
                 // delivery_date / supplier / location / ack / ds — use
                 // the amended values if present, else the parent's
                 // current values.
                 $hasDelivery ? $deliveryDate : $jc['delivery_date'],
                 $hasSupplier ? $supplier     : $jc['supplier_name'],
                 $hasLocation ? $location     : $jc['location'],
                 $hasAck      ? $ackNo        : $jc['ack_no'],
                 $hasDs       ? (int)$ds      : (int)$jc['ds'],
                 (int)$jc['id'],
                 sprintf('Qty increased from %s to %s during amendment; child carries +%s units',
                         rtrim(rtrim((string)$before['po_qty'], '0'), '.'),
                         rtrim(rtrim((string)((float)$before['po_qty'] + $childDeltaQty), '0'), '.'),
                         rtrim(rtrim((string)$childDeltaQty, '0'), '.'))]
            );
            $childId   = (int)db()->lastInsertId();
            $childJcNo = sprintf('JC-%06d', $childId);
            db_exec("UPDATE job_cards SET jc_no = ? WHERE id = ?", [$childJcNo, $childId]);

            // Audit events on both ends of the relationship.
            db_exec(
                "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_label)
                 VALUES (?, 'created', ?, 'so-api')",
                [$childId, json_encode([
                    'source'           => 'so_api_qty_increase',
                    'parent_id'        => (int)$jc['id'],
                    'parent_jc_no'     => $jc['jc_no'],
                    'parent_status'    => $jc['status'],
                    'delta_qty'        => $childDeltaQty,
                    'parent_original'  => $before['po_qty'],
                    'amended_total'    => $before['po_qty'] + $childDeltaQty,
                ])]
            );
            db_exec(
                "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_label)
                 VALUES (?, 'edited', ?, 'so-api')",
                [(int)$jc['id'], json_encode([
                    'source'        => 'so_api_amendment_qty_increase',
                    'note'          => 'qty increased; parent kept at original, child spun off for delta',
                    'parent_qty'    => $before['po_qty'],
                    'amended_total' => $before['po_qty'] + $childDeltaQty,
                    'child_id'      => $childId,
                    'child_jc_no'   => $childJcNo,
                    'child_qty'     => $childDeltaQty,
                ])]
            );
        }

        // Notify the current step's owners — they're working on this
        // card and need to know it changed. Status maps to permission:
        //   qc_pending      -> qc_update
        //   prod_pending    -> prod_update
        //   ats_pending     -> ats_update
        //   billing_pending -> close
        static $stepPermMap = [
            'qc_pending'      => 'qc_update',
            'prod_pending'    => 'prod_update',
            'ats_pending'     => 'ats_update',
            'billing_pending' => 'close',
        ];
        $perm = $stepPermMap[$jc['status']] ?? null;
        if ($perm && !empty($diff)) {
            $changedKeys = implode(', ', array_keys($diff));
            jc_notify_step((int)$jc['id'], $perm,
                sprintf('%s amended by billing system', $jc['jc_no']),
                sprintf('Updated: %s. Review the card before continuing.', $changedKeys));
        }
        // If a child was spawned, notify QC (the child lands there).
        if ($childId) {
            jc_notify_step($childId, 'qc_update',
                sprintf('%s — new card from PO qty increase', $childJcNo),
                sprintf('Parent %s qty increased; %s additional units to inspect.',
                        $jc['jc_no'], rtrim(rtrim((string)$childDeltaQty, '0'), '.')));
        }

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[job_card_api/update_from_so] db error: ' . $e->getMessage());
        jc_api_emit(500, ['ok' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
    }

    jc_api_emit(200, [
        'ok'         => true,
        'action'     => $childId ? 'updated_with_child' : 'updated',
        'id'         => (int)$jc['id'],
        'jc_no'      => $jc['jc_no'],
        'status'     => $jc['status'],
        'diff'       => $diff,
        'child_id'   => $childId,
        'child_jc_no'=> $childJcNo,
        'child_qty'  => $childId ? $childDeltaQty : null,
    ]);
}

// ============================================================
// op=cancel_from_so — line was removed in an amendment
// ============================================================
// When a sales-order amendment drops a line that was previously on
// the PO, the billing system calls this op so MagDyn cancels the
// matching job card. Matches by (po_no, line_no) — same key as
// create/update_from_so.
//
// Cancellation rules:
//   - Card in qc_pending: cancellable freely (no work done yet).
//   - Card in prod_pending: cancellable. QC was completed, but
//     production hasn't physically produced units yet. Cancel and
//     the QC time is sunk cost.
//   - Card in ats_pending / billing_pending: REFUSED with 409.
//     Production has already submitted units; cancellation here is
//     operationally complex (what happens to the stock at SHP?
//     who absorbs the cost?). Requires human supervisor intervention.
//   - Card already closed: REFUSED with 409. Card has shipped.
//   - Card already cancelled: success no-op. Idempotent.
//
// If the line never existed in MagDyn (no matching card), returns
// 404 jc_not_found. The billing system should log + continue;
// this isn't a failure case (nothing to cancel = nothing to do).
// Response includes 'action': 'no_card' so the caller can branch.
//
// Returns:
//   200 ok=true, action=cancelled        — card was cancelled now
//   200 ok=true, action=already_cancelled — card was already cancelled (idempotent)
//   200 ok=true, action=no_card           — no matching card to cancel
//   409 wrong_status                      — too late to cancel via API
//   422 validation                        — bad payload
if ($op === 'cancel_from_so') {
    $poNo   = isset($body['po_no'])   ? trim((string)$body['po_no']) : '';
    $lineNo = isset($body['line_no']) ? trim((string)$body['line_no']) : null;
    $reason = isset($body['reason'])  ? trim((string)$body['reason']) : '';
    $errors = [];
    if ($poNo === '') $errors[] = 'po_no required';
    if ($errors) {
        jc_api_emit(422, ['ok' => false, 'error' => 'validation', 'details' => $errors]);
    }

    // Find the matching card. Same match logic as update_from_so:
    // newest non-CLOSED card on the (po_no, line_no) key, preferring
    // the parent on a partial-split chain.
    $jc = db_one(
        "SELECT * FROM job_cards
          WHERE po_no = ?
            AND COALESCE(line_no, '') = COALESCE(?, '')
            AND status <> 'closed'
          ORDER BY parent_id IS NULL DESC, id DESC
          LIMIT 1",
        [$poNo, $lineNo]
    );
    if (!$jc) {
        // No active card to cancel. Could be: line was never pushed
        // here, or the matching card was already closed (shipped) —
        // either way, billing's "cancel this line" has nothing to do
        // on our side. Return success-with-no-card so the billing
        // system can log it as informational rather than an error.
        jc_api_emit(200, [
            'ok' => true,
            'action' => 'no_card',
            'message' => 'No active job card found for PO ' . $poNo . ' / line ' . ($lineNo ?: '(empty)') . '. Either the line was never pushed or its card is already closed/shipped.',
        ]);
    }

    if ($jc['status'] === 'cancelled') {
        // Already cancelled — idempotent success.
        jc_api_emit(200, [
            'ok' => true,
            'action' => 'already_cancelled',
            'jc_no'  => $jc['jc_no'],
            'message' => 'Card was already cancelled previously.',
        ]);
    }

    // Refuse cancellation past production handoff.
    if (in_array($jc['status'], ['ats_pending','billing_pending'], true)) {
        jc_api_emit(409, [
            'ok' => false,
            'error' => 'wrong_status',
            'message' => "Cannot cancel $jc[jc_no] via API — production has already submitted units (status '$jc[status]'). "
                       . "Stock has been moved to SHP. A human supervisor must reconcile inventory before cancelling.",
            'current_status' => $jc['status'],
            'jc_no'          => $jc['jc_no'],
        ]);
    }

    // OK to cancel. Single status update + event log. We don't touch
    // any other fields — keep the historical values intact for the
    // audit trail.
    try {
        db()->beginTransaction();

        db_exec(
            "UPDATE job_cards SET status = 'cancelled' WHERE id = ?",
            [(int)$jc['id']]
        );
        db_exec(
            "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_label)
             VALUES (?, 'cancelled', ?, 'so-api')",
            [(int)$jc['id'], json_encode([
                'source'        => 'so_api_cancel',
                'reason'        => $reason !== '' ? $reason : 'amendment dropped this line',
                'previous_status' => $jc['status'],
            ])]
        );

        // Notify everyone who currently views this card (current step's
        // owners) — they were about to work on it and need to know
        // they shouldn't anymore.
        static $stepPermMap2 = [
            'qc_pending'   => 'qc_update',
            'prod_pending' => 'prod_update',
        ];
        $perm = $stepPermMap2[$jc['status']] ?? null;
        if ($perm) {
            jc_notify_step((int)$jc['id'], $perm,
                sprintf('%s cancelled by billing system', $jc['jc_no']),
                sprintf('PO line was dropped in amendment. Reason: %s', $reason ?: '(none given)'));
        }

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[job_card_api/cancel_from_so] db error: ' . $e->getMessage());
        jc_api_emit(500, ['ok' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
    }

    jc_api_emit(200, [
        'ok'              => true,
        'action'          => 'cancelled',
        'id'              => (int)$jc['id'],
        'jc_no'           => $jc['jc_no'],
        'previous_status' => $jc['status'],
        'reason'          => $reason,
    ]);
}

// ============================================================
// Unknown op
// ============================================================
jc_api_emit(400, [
    'ok' => false, 'error' => 'unknown_op',
    'message' => 'Unknown op="' . $op . '". Valid ops: create_from_so, update_from_so, cancel_from_so, set_invoice.',
]);


/**
 * Notify all users with the given job_card permission about an event
 * on a specific job card. Used at transitions to nudge the next step's
 * owners.
 *
 * permission_code is the 'code' column of the job_card module's
 * permissions table — e.g. 'qc_update', 'prod_update', 'ats_update'.
 */
function jc_notify_step($jobCardId, $permissionCode, $headline, $body = null) {
    $jc = db_one("SELECT id, jc_no FROM job_cards WHERE id = ?", [$jobCardId]);
    if (!$jc) return;

    // Resolve users who have this permission. Walks roles -> users.
    $users = db_all(
        "SELECT DISTINCT ur.user_id
           FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p       ON p.id = rp.permission_id
           JOIN modules m           ON m.id = p.module_id
          WHERE m.code = 'job_card' AND p.code = ?",
        [$permissionCode]
    );
    if (!$users) return;

    $href = '/job_card.php?action=view&id=' . (int)$jobCardId;
    foreach ($users as $u) {
        db_exec(
            "INSERT INTO notifications (user_id, entity_type, entity_id, headline, body, href)
             VALUES (?, 'job_card', ?, ?, ?, ?)",
            [(int)$u['user_id'], $jobCardId, $headline, $body, $href]
        );
    }
}
