<?php
/**
 * BOM Tree Export  —  API endpoints for MagDyn + original HTML download.
 *
 * Deploy to: old server at /inventory/custom/tree7-5.php
 * PHP 5.6 compatible — no null coalescing (??), no return types, no scalar hints.
 *
 * API endpoints (require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=all_trees_json   primary — returns JSON {items:[...], edges:[...]}
 *   ?action=root_ids         returns JSON {root_ids:[...], count:N}
 *   ?action=tree_csv&mid=N   returns CSV for one tree
 *   ?action=all_trees_csv    returns combined CSV for all trees
 *   ?action=debug            returns diagnostic JSON
 *
 * No token = original HTML page (pass ?mid=NNN to show download button).
 */

define('API_TOKEN', 'MAGDYN_IMPORT_SECRET');

/* -------------------------------------------------------------------------
 * DB connection  (load once, buffer any whitespace config.php might emit)
 * ---------------------------------------------------------------------- */
ob_start();
include 'config.php';
ob_end_clean();

/* =========================================================================
 * Shared helper functions
 * ====================================================================== */

function bom_fetch_row($id, $con)
{
    $sql = 'SELECT im.*, ich.*'
         . ' FROM inventory_model im'
         . ' LEFT JOIN inventory_model_custom_field_helper ich'
         . '        ON ich.inventory_model_id = im.inventory_model_id'
         . ' WHERE im.inventory_model_id = ' . intval($id);
    $res = mysqli_query($con, $sql);
    if (!$res) return false;
    return mysqli_fetch_assoc($res);
}

function bom_child_ids($cfv8)
{
    $cfv8 = trim((string)$cfv8);
    if ($cfv8 === '' || $cfv8 === '-' || $cfv8 === '0') return array();
    $ids = array();
    foreach (explode(';', $cfv8) as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $pos = strrpos($seg, '-');
        if ($pos === false) continue;
        $cid = intval(trim(substr($seg, 0, $pos)));
        if ($cid > 0) $ids[] = $cid;
    }
    return $ids;
}

function bom_child_qtys($cfv8)
{
    $cfv8 = trim((string)$cfv8);
    if ($cfv8 === '' || $cfv8 === '-' || $cfv8 === '0') return array();
    $out = array();
    foreach (explode(';', $cfv8) as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $pos = strrpos($seg, '-');
        if ($pos === false) continue;
        $cid = intval(trim(substr($seg, 0, $pos)));
        $qty = floatval(trim(substr($seg, $pos + 1)));
        if ($cid > 0) $out[$cid] = ($qty > 0 ? $qty : 1.0);
    }
    return $out;
}

function bom_csv_field($val)
{
    $s = ($val === null) ? '' : (string)$val;
    if (strpos($s, ',') !== false || strpos($s, '"') !== false
        || strpos($s, "\n") !== false || strpos($s, "\r") !== false) {
        $s = '"' . str_replace(
                array("\r\n", "\r", "\n"), ' ',
                str_replace('"', '""', $s)
            ) . '"';
    }
    return $s;
}

function bom_get_root_ids($con)
{
    $res = mysqli_query($con,
        'SELECT im.inventory_model_id, ich.cfv_8'
        . ' FROM inventory_model im'
        . ' JOIN inventory_model_custom_field_helper ich'
        . '      ON ich.inventory_model_id = im.inventory_model_id'
        . " WHERE ich.cfv_8 IS NOT NULL AND ich.cfv_8 != '' AND ich.cfv_8 != '-' AND ich.cfv_8 != '0'"
        . ' ORDER BY im.inventory_model_id ASC'
    );
    if (!$res) return array();
    $rows = array();
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[intval($r['inventory_model_id'])] = (string)$r['cfv_8'];
    }
    $childSet = array();
    foreach ($rows as $cfv8) {
        foreach (bom_child_ids($cfv8) as $cid) {
            $childSet[$cid] = true;
        }
    }
    $roots = array();
    foreach (array_keys($rows) as $id) {
        if (!isset($childSet[$id])) $roots[] = $id;
    }
    sort($roots);
    return $roots;
}

/* --------------------------------------------------------------------------
 * CSV export helpers
 * ----------------------------------------------------------------------- */
define('CSV_HEADER',
    'inventory_model_id,category_id,manufacturer_id,inventory_model_code,'
    . 'long_description,image_path,price,created_by,creation_date,modified_by,'
    . 'modified_date,sap_pos,level,Notes,I_Tree Child,I_Division,I_UOM,'
    . 'I_Step time,I_Step Cost,CERT,Material Spec,Dwg_No,Rev_No,Part_No,'
    . 'Part_Rev_No,Process Spec,Certificates,Min Stock Level,Min Order Qty'
);

function bom_build_rows($id, $con, $depth, &$rows, &$gout, $path)
{
    if (isset($path[$id])) return;
    $row = bom_fetch_row($id, $con);
    if (!$row) return;

    $pfx = ($depth > 0) ? str_repeat('|   ', $depth - 1) . '|--' : '';
    $sd  = isset($row['short_description']) ? $row['short_description'] : '';
    $mid = isset($row['manufacturer_id'])   ? $row['manufacturer_id']   : '1';
    $tc  = $pfx . $sd . ' (' . $row['inventory_model_id'] . ')~' . $mid . '~' . $mid;

    $cols = array(
        $tc,
        isset($row['category_id'])          ? $row['category_id']          : '',
        isset($row['manufacturer_id'])       ? $row['manufacturer_id']       : '',
        isset($row['inventory_model_code'])  ? $row['inventory_model_code']  : '',
        isset($row['long_description'])      ? $row['long_description']      : '',
        isset($row['image_path'])            ? $row['image_path']            : '',
        isset($row['price'])                 ? $row['price']                 : '',
        isset($row['created_by'])            ? $row['created_by']            : '',
        isset($row['creation_date'])         ? $row['creation_date']         : '',
        isset($row['modified_by'])           ? $row['modified_by']           : '',
        isset($row['modified_date'])         ? $row['modified_date']         : '',
        isset($row['sap_pos'])               ? $row['sap_pos']               : '',
        isset($row['level'])                 ? $row['level']                 : '',
        isset($row['cfv_2'])                 ? $row['cfv_2']                 : '',
        isset($row['cfv_8'])                 ? $row['cfv_8']                 : '',
        isset($row['cfv_12'])                ? $row['cfv_12']                : '',
        isset($row['cfv_14'])                ? $row['cfv_14']                : '',
        isset($row['cfv_16'])                ? $row['cfv_16']                : '',
        isset($row['cfv_17'])                ? $row['cfv_17']                : '',
        isset($row['cfv_24'])                ? $row['cfv_24']                : '',
        isset($row['cfv_25'])                ? $row['cfv_25']                : '',
        isset($row['cfv_26'])                ? $row['cfv_26']                : '',
        isset($row['cfv_27'])                ? $row['cfv_27']                : '',
        isset($row['cfv_28'])                ? $row['cfv_28']                : '',
        isset($row['cfv_44'])                ? $row['cfv_44']                : '',
        isset($row['cfv_29'])                ? $row['cfv_29']                : '',
        isset($row['cfv_30'])                ? $row['cfv_30']                : '',
        isset($row['cfv_31'])                ? $row['cfv_31']                : '',
        isset($row['cfv_32'])                ? $row['cfv_32']                : '',
    );

    $esc = array();
    foreach ($cols as $c) { $esc[] = bom_csv_field($c); }
    $rows[] = implode(',', $esc);

    if (!isset($gout[$id])) {
        $gout[$id]  = true;
        $path[$id]  = true;
        $cfv8 = isset($row['cfv_8']) ? $row['cfv_8'] : '';
        foreach (bom_child_ids($cfv8) as $cid) {
            bom_build_rows($cid, $con, $depth + 1, $rows, $gout, $path);
        }
    }
}

/* --------------------------------------------------------------------------
 * JSON export helpers
 * ----------------------------------------------------------------------- */
function bom_collect_json($id, $con, &$items, &$edges, &$eseen, &$gout, $path)
{
    if (isset($path[$id])) return;
    $row = bom_fetch_row($id, $con);
    if (!$row) return;

    $code = (string)$id;

    if (!isset($items[$code])) {
        $items[$code] = array(
            'code'             => $code,
            'name'             => isset($row['short_description']) ? (string)$row['short_description'] : $code,
            'long_description' => isset($row['long_description'])  ? (string)$row['long_description']  : '',
            'category_id'      => isset($row['category_id'])       ? (string)$row['category_id']       : '',
            'i_division'       => isset($row['cfv_12'])            ? (string)$row['cfv_12']            : '',
            'dwg_no'           => isset($row['cfv_26'])            ? (string)$row['cfv_26']            : '',
            'rev_no'           => isset($row['cfv_27'])            ? (string)$row['cfv_27']            : '',
            'part_no'          => isset($row['cfv_28'])            ? (string)$row['cfv_28']            : '',
            'process_spec'     => isset($row['cfv_29'])            ? (string)$row['cfv_29']            : '',
            'material_spec'    => isset($row['cfv_25'])            ? (string)$row['cfv_25']            : '',
            'min_stock_level'  => isset($row['cfv_31'])            ? (string)$row['cfv_31']            : '',
            'min_order_qty'    => isset($row['cfv_32'])            ? (string)$row['cfv_32']            : '',
            'is_root'          => false,
            'stock_locations'  => array(), // filled in batch after tree traversal
        );
    }

    if (!isset($gout[$id])) {
        $gout[$id] = true;
        $path[$id] = true;
        $cfv8  = isset($row['cfv_8']) ? (string)$row['cfv_8'] : '';
        $cids  = bom_child_ids($cfv8);
        $cqtys = bom_child_qtys($cfv8);
        $sort  = 10;
        foreach ($cids as $cid) {
            $cc = (string)$cid;
            $ek = $code . "\x00" . $cc;
            if (!isset($eseen[$ek])) {
                $eseen[$ek] = true;
                $edges[] = array(
                    'parent_code' => $code,
                    'child_code'  => $cc,
                    'qty'         => isset($cqtys[$cid]) ? (float)$cqtys[$cid] : 1.0,
                    'sort_order'  => $sort,
                );
            }
            $sort += 10;
            bom_collect_json($cid, $con, $items, $edges, $eseen, $gout, $path);
        }
    }
}

function bom_resolve_roots($con)
{
    $ids = array();
    if (!empty($_GET['root_ids'])) {
        foreach (explode(',', (string)$_GET['root_ids']) as $seg) {
            $n = intval(trim($seg));
            if ($n > 0) $ids[] = $n;
        }
    }
    if (empty($ids)) {
        $ids = bom_get_root_ids($con);
    }
    return $ids;
}

/* =========================================================================
 * API mode  (requires ?token=MAGDYN_IMPORT_SECRET)
 * ====================================================================== */
if (isset($_GET['token'])) {

    if (trim($_GET['token']) !== API_TOKEN) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(array('error' => 'Unauthorized'));
        mysqli_close($con);
        exit;
    }

    $action = isset($_GET['action']) ? trim($_GET['action']) : '';

    /* -- all_trees_json -------------------------------------------------- */
    if ($action === 'all_trees_json') {
        @set_time_limit(300);
        $rootIds = bom_resolve_roots($con);
        $items   = array();
        $edges   = array();
        $eseen   = array();
        $gout    = array();
        foreach ($rootIds as $rid) {
            bom_collect_json($rid, $con, $items, $edges, $eseen, $gout, array());
            $rc = (string)$rid;
            if (isset($items[$rc])) $items[$rc]['is_root'] = true;
        }

        // Batch-fetch location quantities for ALL collected items in ONE query.
        // Joins inventory_location → location to get the human-readable location name.
        // Only rows with quantity > 0 are included.
        if (!empty($items)) {
            $idCsv = implode(',', array_map('intval', array_keys($items)));
            $stockRes = mysqli_query($con,
                'SELECT il.inventory_model_id, l.short_description AS loc_name, il.quantity AS qty'
                . ' FROM inventory_location il'
                . ' JOIN location l ON l.location_id = il.location_id'
                . ' WHERE il.inventory_model_id IN (' . $idCsv . ')'
                . ' AND il.quantity > 0'
                . ' ORDER BY il.inventory_model_id, l.location_id'
            );
            if ($stockRes) {
                while ($sr = mysqli_fetch_assoc($stockRes)) {
                    $sc = (string)intval($sr['inventory_model_id']);
                    if (isset($items[$sc])) {
                        $items[$sc]['stock_locations'][] = array(
                            'location' => (string)$sr['loc_name'],
                            'qty'      => (float)$sr['qty'],
                        );
                    }
                }
            }
        }

        mysqli_close($con);
        header('Content-Type: application/json');
        echo json_encode(array('items' => array_values($items), 'edges' => $edges));
        exit;
    }

    /* -- root_ids -------------------------------------------------------- */
    if ($action === 'root_ids') {
        $ids = bom_get_root_ids($con);
        mysqli_close($con);
        header('Content-Type: application/json');
        echo json_encode(array('root_ids' => $ids, 'count' => count($ids)));
        exit;
    }

    /* -- tree_csv -------------------------------------------------------- */
    if ($action === 'tree_csv') {
        $mid  = isset($_GET['mid']) ? intval($_GET['mid']) : 0;
        $rows = array();
        $gout = array();
        if ($mid > 0) bom_build_rows($mid, $con, 0, $rows, $gout, array());
        mysqli_close($con);
        header('Content-Type: text/csv');
        header('Content-Disposition: inline; filename="bom_tree_' . $mid . '.csv"');
        echo CSV_HEADER . "\n" . implode("\n", $rows);
        exit;
    }

    /* -- all_trees_csv --------------------------------------------------- */
    if ($action === 'all_trees_csv') {
        @set_time_limit(300);
        $rootIds = bom_resolve_roots($con);
        $rows = array();
        $gout = array();
        foreach ($rootIds as $rid) {
            bom_build_rows(intval($rid), $con, 0, $rows, $gout, array());
        }
        mysqli_close($con);
        header('Content-Type: text/csv');
        header('Content-Disposition: inline; filename="bom_all_trees.csv"');
        echo CSV_HEADER . "\n" . implode("\n", $rows);
        exit;
    }

    /* -- debug ----------------------------------------------------------- */
    if ($action === 'debug') {
        $totalItems = 0;
        $r = mysqli_query($con, 'SELECT COUNT(*) AS n FROM inventory_model');
        if ($r) {
            $rw = mysqli_fetch_assoc($r);
            $totalItems = intval($rw['n']);
        }

        $withCfv8 = 0;
        $r = mysqli_query($con,
            'SELECT COUNT(*) AS n'
            . ' FROM inventory_model im'
            . ' LEFT JOIN inventory_model_custom_field_helper ich'
            . '        ON ich.inventory_model_id = im.inventory_model_id'
            . " WHERE ich.cfv_8 IS NOT NULL AND ich.cfv_8 != '' AND ich.cfv_8 != '-' AND ich.cfv_8 != '0'"
        );
        if ($r) {
            $rw = mysqli_fetch_assoc($r);
            $withCfv8 = intval($rw['n']);
        }

        $sample = array();
        $r = mysqli_query($con,
            'SELECT im.inventory_model_id, im.category_id, im.short_description, ich.cfv_8'
            . ' FROM inventory_model im'
            . ' LEFT JOIN inventory_model_custom_field_helper ich'
            . '        ON ich.inventory_model_id = im.inventory_model_id'
            . " WHERE ich.cfv_8 IS NOT NULL AND ich.cfv_8 != '' AND ich.cfv_8 != '-' AND ich.cfv_8 != '0'"
            . ' LIMIT 5'
        );
        if ($r) {
            while ($rw = mysqli_fetch_assoc($r)) {
                $sample[] = array(
                    'id'    => intval($rw['inventory_model_id']),
                    'name'  => (string)$rw['short_description'],
                    'cfv_8' => substr((string)$rw['cfv_8'], 0, 80),
                );
            }
        }

        $autoRoots = bom_get_root_ids($con);
        mysqli_close($con);
        header('Content-Type: application/json');
        echo json_encode(array(
            'total_items'         => $totalItems,
            'items_with_cfv8'     => $withCfv8,
            'auto_detected_roots' => $autoRoots,
            'sample'              => $sample,
        ), JSON_PRETTY_PRINT);
        exit;
    }

    /* -- unknown action -------------------------------------------------- */
    mysqli_close($con);
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(array('error' => 'Unknown action. Supported: all_trees_json, root_ids, tree_csv, all_trees_csv, debug'));
    exit;
}

/* =========================================================================
 * HTML / download mode  (no token — original behaviour)
 * All PHP work is done HERE before any output so there are no embedded
 * PHP blocks inside the <script> section below.
 * ====================================================================== */

function get_node_data($mod_id, $con)
{
    $result = mysqli_query($con,
        'SELECT im.*, ic.*'
        . ' FROM inventory_model im'
        . ' LEFT JOIN inventory_model_custom_field_helper ic'
        . '        ON im.inventory_model_id = ic.inventory_model_id'
        . ' WHERE im.inventory_model_id = ' . intval($mod_id)
    );
    if (!$result) return null;
    $row = mysqli_fetch_assoc($result);
    if (!$row) return null;

    $data = array(
        'customId' => $row['short_description'] . ' (' . $row['inventory_model_id'] . ')~'
                    . $row['manufacturer_id'] . '~' . $row['manufacturer_id'],
        'csvData'  => array(
            $row['inventory_model_id'],
            $row['category_id'],
            $row['manufacturer_id'],
            $row['inventory_model_code'],
            $row['long_description'],
            $row['image_path'],
            $row['price'],
            $row['created_by'],
            $row['creation_date'],
            $row['modified_by'],
            $row['modified_date'],
            $row['sap_pos'],
            $row['level'],
            $row['cfv_2'],
            $row['cfv_8'],
            $row['cfv_12'],
            $row['cfv_14'],
            $row['cfv_16'],
            $row['cfv_17'],
            $row['cfv_24'],
            $row['cfv_25'],
            $row['cfv_26'],
            $row['cfv_27'],
            $row['cfv_28'],
            $row['cfv_44'],
            $row['cfv_29'],
            $row['cfv_30'],
            $row['cfv_31'],
            $row['cfv_32'],
        ),
        'Nodes' => array(),
    );

    $cfv8 = isset($row['cfv_8']) ? $row['cfv_8'] : '';
    if ($cfv8 !== '' && $cfv8 !== '-' && $cfv8 !== '0') {
        foreach (explode(';', $cfv8) as $seg) {
            $parts = explode('-', $seg);
            if (!empty($parts[0])) {
                $child = get_node_data($parts[0], $con);
                if ($child) $data['Nodes'][] = $child;
            }
        }
    }
    return $data;
}

$html_root_id      = isset($_GET['mid']) ? intval($_GET['mid']) : 0;
$html_tree_json    = 'null';
$html_headers_json = json_encode(CSV_HEADER);
$html_filename     = 'Product_Tree_' . $html_root_id . '.csv';

if ($html_root_id > 0) {
    $node = get_node_data($html_root_id, $con);
    if ($node) {
        $html_tree_json = json_encode(array($node));
    }
}
mysqli_close($con);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Product Tree</title>
    <meta http-equiv="Cache-control" content="no-cache">
    <meta http-equiv="Expires" content="-1">
    <script src="https://code.jquery.com/jquery-1.9.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.10.3/jquery-ui.min.js"></script>
    <script type="text/javascript" src="https://cdn-na.infragistics.com/igniteui/2020.1/latest/js/infragistics.loader.js"></script>
</head>
<body>
    <button id="test">Download CSV File!</button>

    <script type="text/javascript">
        $.ig.loader({
            scriptPath: "https://cdn-na.infragistics.com/igniteui/2020.1/latest/js/",
            cssPath:    "https://cdn-na.infragistics.com/igniteui/2020.1/latest/css",
            resources:  "igTree"
        });

        $.ig.loader(function () {
            var toc      = <?php echo $html_tree_json; ?>;
            var headers  = <?php echo $html_headers_json; ?>;
            var filename = <?php echo json_encode($html_filename); ?>;

            function processToCSV(nodes, depth, rows) {
                if (depth === undefined) depth = 0;
                if (rows  === undefined) rows  = [];
                nodes.forEach(function (node) {
                    var prefix = "";
                    if (depth > 0) {
                        var parts = [];
                        for (var i = 0; i < depth - 1; i++) parts.push("|   ");
                        prefix = parts.join("") + "|--";
                    }
                    var rowData  = node.csvData.slice();
                    rowData[0]   = prefix + node.customId;
                    var cleanRow = rowData.map(function (field) {
                        var s = String(field === null ? "" : field);
                        if (s.indexOf(',')  !== -1 || s.indexOf('"') !== -1
                         || s.indexOf('\n') !== -1 || s.indexOf('\r') !== -1) {
                            s = '"' + s.replace(/"/g, '""').replace(/[\r\n]+/g, " ") + '"';
                        }
                        return s;
                    }).join(',');
                    rows.push(cleanRow);
                    if (node.Nodes && node.Nodes.length > 0) {
                        processToCSV(node.Nodes, depth + 1, rows);
                    }
                });
                return rows.join('\n');
            }

            function download_csv() {
                if (!toc) { alert('No tree data. Please open this page with ?mid=NNN'); return; }
                var fullCsv  = headers + '\n' + processToCSV(toc);
                var blob     = new Blob([fullCsv], { type: 'text/csv;charset=utf-8;' });
                var link     = document.createElement("a");
                link.setAttribute("href", URL.createObjectURL(blob));
                link.setAttribute("download", filename);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            document.getElementById('test').onclick = download_csv;
        });
    </script>
</body>
</html>
