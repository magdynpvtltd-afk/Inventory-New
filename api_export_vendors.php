<?php
/**
 * Old Inventory — Vendor / Contact / Address / User Export API
 *
 * Deploy to: old server at /inventory/api_export_vendors.php
 * PHP 5.6 compatible — no ??, no return types, no scalar hints.
 *
 * Source tables (legacy "true tracking" schema):
 *   company                       → vendor header
 *   company_custom_field_helper   → cfv_18 GST, cfv_33 Bank Details,
 *                                    cfv_50 Vendor Code, cfv_20 Service Category
 *   address / address_cfh         → vendor addresses (cfv_2 Notes, cfv_21 Loc desc)
 *   contact / contact_cfh         → vendor contacts  (cfv_2 Notes)
 *   user_account                  → application users
 *
 * Endpoints (all require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=ping                          health check
 *   ?action=vendor_count                  COUNT(*) FROM company
 *   ?action=vendors  [&offset=0&limit=100] companies + nested addresses[]+contacts[]
 *   ?action=user_count                    COUNT(*) FROM user_account
 *   ?action=users    [&offset=0&limit=200] user_account rows
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

// ------------------------------------------------------------------
// JSON output helpers
//
// Legacy free-text columns (addresses, descriptions) sometimes hold
// Windows-1252 bytes stored in a utf8 column. Those are invalid UTF-8,
// which makes json_encode() return false and emit an EMPTY body — the
// client then reports "invalid JSON". Coerce every string to valid
// UTF-8 before encoding so the whole page survives one dirty row.
// ------------------------------------------------------------------
function wt_utf8_deep($v) {
    if (is_array($v)) {
        $out = array();
        foreach ($v as $k => $x) { $out[$k] = wt_utf8_deep($x); }
        return $out;
    }
    if (is_string($v) && $v !== '') {
        if (function_exists('mb_check_encoding') && mb_check_encoding($v, 'UTF-8')) {
            return $v;
        }
        if (function_exists('mb_convert_encoding')) {
            $c = @mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
            if ($c !== false && (!function_exists('mb_check_encoding') || mb_check_encoding($c, 'UTF-8'))) {
                return $c;
            }
        }
        if (function_exists('iconv')) {
            $c = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
            if ($c !== false) { return $c; }
        }
    }
    return $v;
}

function wt_emit($payload) {
    $flags = defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0;
    $json  = json_encode(wt_utf8_deep($payload), $flags);
    if ($json === false) {
        $json = json_encode(array('error' => 'JSON encode failed: ' . json_last_error_msg()));
    }
    echo $json;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

// Pagination params — cap limit at 1000 to protect server memory
$offset = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
$limit  = min(1000, max(1, (int)(isset($_GET['limit'])  ? $_GET['limit']  : 100)));

// ------------------------------------------------------------------
// ping
// ------------------------------------------------------------------
if ($action === 'ping') {
    echo json_encode(array('ok' => true, 'server' => 'api_export_vendors'));
    exit;
}

// ------------------------------------------------------------------
// vendor_count — number of companies (vendors)
// ------------------------------------------------------------------
if ($action === 'vendor_count') {
    $res = mysqli_query($con, 'SELECT COUNT(*) FROM company');
    if (!$res) {
        echo json_encode(array('error' => 'Count query failed: ' . mysqli_error($con)));
        exit;
    }
    $row = mysqli_fetch_row($res);
    echo json_encode(array('count' => (int)$row[0]));
    exit;
}

// ------------------------------------------------------------------
// user_count — number of user accounts
// ------------------------------------------------------------------
if ($action === 'user_count') {
    $res = mysqli_query($con, 'SELECT COUNT(*) FROM user_account');
    if (!$res) {
        echo json_encode(array('error' => 'Count query failed: ' . mysqli_error($con)));
        exit;
    }
    $row = mysqli_fetch_row($res);
    echo json_encode(array('count' => (int)$row[0]));
    exit;
}

// ------------------------------------------------------------------
// vendors — companies (paginated) with nested addresses[] + contacts[]
//
// Join path:
//   company.company_id
//     ← company_custom_field_helper.company_id   (GST / bank / vendor code)
//     ← address.company_id  ← address_custom_field_helper.address_id
//     ← contact.company_id  ← contact_custom_field_helper.contact_id
//
// Addresses and contacts are fetched in separate queries and grouped in
// PHP to avoid an address × contact cartesian product per company.
// ------------------------------------------------------------------
if ($action === 'vendors') {

    // Step 1 — this page of company_ids
    $idRes = mysqli_query($con,
        'SELECT company_id FROM company ORDER BY company_id ASC'
        . ' LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    if (!$idRes) {
        echo json_encode(array('error' => 'ID query failed: ' . mysqli_error($con)));
        exit;
    }
    $companyIds = array();
    while ($r = mysqli_fetch_row($idRes)) {
        $companyIds[] = (int)$r[0];
    }
    if (empty($companyIds)) {
        echo json_encode(array('vendors' => array(), 'count' => 0));
        exit;
    }
    $idList = implode(',', $companyIds);

    // Step 2 — company headers + custom fields
    $sql = "
        SELECT
            c.company_id,
            c.short_description   AS name,
            c.website,
            c.telephone,
            c.fax,
            c.email,
            c.long_description,
            ccf.cfv_18            AS gst_no,
            ccf.cfv_33            AS bank_details,
            ccf.cfv_50            AS vendor_code,
            ccf.cfv_20            AS service_category
        FROM company c
        LEFT JOIN company_custom_field_helper ccf
            ON ccf.company_id = c.company_id
        WHERE c.company_id IN ($idList)
        ORDER BY c.company_id ASC
    ";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        echo json_encode(array('error' => 'Company query failed: ' . mysqli_error($con)));
        exit;
    }

    $vendors    = array();
    $vendorIdx  = array(); // company_id → index in $vendors
    while ($row = mysqli_fetch_assoc($res)) {
        $cid = (int)$row['company_id'];
        $vendorIdx[$cid] = count($vendors);
        $vendors[] = array(
            'company_id'       => $row['company_id'],
            'name'             => $row['name'],
            'website'          => $row['website'],
            'telephone'        => $row['telephone'],
            'fax'              => $row['fax'],
            'email'            => $row['email'],
            'long_description' => $row['long_description'],
            'gst_no'           => $row['gst_no'],
            'bank_details'     => $row['bank_details'],
            'vendor_code'      => $row['vendor_code'],
            'service_category' => $row['service_category'],
            'addresses'        => array(),
            'contacts'         => array(),
        );
    }

    // Step 3 — addresses + custom fields for this page
    $sqlA = "
        SELECT
            a.address_id,
            a.company_id,
            a.short_description   AS label,
            a.address_1           AS line1,
            a.address_2           AS line2,
            a.city,
            a.postal_code         AS pincode,
            a.state_province_id,
            a.country_id,
            acf.cfv_2             AS notes,
            acf.cfv_21            AS location_description
        FROM address a
        LEFT JOIN address_custom_field_helper acf
            ON acf.address_id = a.address_id
        WHERE a.company_id IN ($idList)
        ORDER BY a.company_id ASC, a.address_id ASC
    ";
    $resA = mysqli_query($con, $sqlA);
    if (!$resA) {
        echo json_encode(array('error' => 'Address query failed: ' . mysqli_error($con)));
        exit;
    }
    while ($row = mysqli_fetch_assoc($resA)) {
        $cid = (int)$row['company_id'];
        if (!isset($vendorIdx[$cid])) { continue; }
        $vendors[$vendorIdx[$cid]]['addresses'][] = array(
            'address_id'           => $row['address_id'],
            'label'                => $row['label'],
            'line1'                => $row['line1'],
            'line2'                => $row['line2'],
            'city'                 => $row['city'],
            'pincode'              => $row['pincode'],
            'state_province_id'    => $row['state_province_id'],
            'country_id'           => $row['country_id'],
            'notes'                => $row['notes'],
            'location_description' => $row['location_description'],
        );
    }

    // Step 4 — contacts + custom fields for this page
    $sqlC = "
        SELECT
            ct.contact_id,
            ct.company_id,
            ct.first_name,
            ct.last_name,
            ct.title,
            ct.email,
            ct.phone_office,
            ct.phone_home,
            ct.phone_mobile,
            ct.fax,
            ct.description,
            ctcf.cfv_2            AS notes
        FROM contact ct
        LEFT JOIN contact_custom_field_helper ctcf
            ON ctcf.contact_id = ct.contact_id
        WHERE ct.company_id IN ($idList)
        ORDER BY ct.company_id ASC, ct.contact_id ASC
    ";
    $resC = mysqli_query($con, $sqlC);
    if (!$resC) {
        echo json_encode(array('error' => 'Contact query failed: ' . mysqli_error($con)));
        exit;
    }
    while ($row = mysqli_fetch_assoc($resC)) {
        $cid = (int)$row['company_id'];
        if (!isset($vendorIdx[$cid])) { continue; }
        $vendors[$vendorIdx[$cid]]['contacts'][] = array(
            'contact_id'   => $row['contact_id'],
            'first_name'   => $row['first_name'],
            'last_name'    => $row['last_name'],
            'title'        => $row['title'],
            'email'        => $row['email'],
            'phone_office' => $row['phone_office'],
            'phone_home'   => $row['phone_home'],
            'phone_mobile' => $row['phone_mobile'],
            'fax'          => $row['fax'],
            'description'  => $row['description'],
            'notes'        => $row['notes'],
        );
    }

    wt_emit(array('vendors' => $vendors, 'count' => count($vendors)));
    exit;
}

// ------------------------------------------------------------------
// users — user_account rows (paginated)
// ------------------------------------------------------------------
if ($action === 'users') {
    $sql = "
        SELECT
            ua.user_account_id,
            ua.first_name,
            ua.last_name,
            ua.username,
            ua.email_address,
            IF(ua.active_flag = b'1', 1, 0) AS active,
            IF(ua.admin_flag  = b'1', 1, 0) AS is_admin,
            ua.role_id
        FROM user_account ua
        ORDER BY ua.user_account_id ASC
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
    wt_emit(array('users' => $rows, 'count' => count($rows)));
    exit;
}

echo json_encode(array('error' => 'Unknown action: ' . $action));
