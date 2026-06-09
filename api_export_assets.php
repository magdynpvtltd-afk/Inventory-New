<?php
/**
 * MagDyn — Old Inventory Asset Export API
 *
 * Deploy this file on the OLD inventory server (PHP 5.6, 192.168.1.249).
 * Place it in the web root (or any publicly accessible path) and update
 * config/old_inventory_api.php on the new MagDyn system to point to it.
 *
 * Endpoints (all GET):
 *   ?action=count&token=SECRET
 *       Returns: {"count": 350}
 *
 *   ?action=assets&offset=0&limit=100&token=SECRET
 *       Returns: {"assets": [...]}
 *       Each asset includes: asset_id, asset_code, asset_model_code,
 *       model_name, category_name, location_name, due_back (cfv_22),
 *       next_cal_due (cfv_23), checkout_due, notes[] with attachments[].
 *
 * PHP 5.6 compatible — no null coalescing, no return types, no scalar hints.
 */

// ── Shared secret ────────────────────────────────────────────────────────────
define('API_TOKEN', 'MAGDYN_IMPORT_SECRET');   // ← change this on both sides

// ── Auth check ───────────────────────────────────────────────────────────────
header('Content-Type: application/json');

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token !== API_TOKEN) {
    http_response_code(403);
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

// ── DB connection (local inventory_live) ─────────────────────────────────────
$db_host = '127.0.0.1';
$db_name = 'inventory_live';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO(
        'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8',
        $db_user,
        $db_pass,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('error' => 'DB connection failed: ' . $e->getMessage()));
    exit;
}

// ── Route ────────────────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : 'assets';

// ── COUNT ────────────────────────────────────────────────────────────────────
if ($action === 'count') {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM asset WHERE asset_id IS NOT NULL AND TRIM(asset_id) != ''"
    );
    echo json_encode(array('count' => (int) $stmt->fetchColumn()));
    exit;
}

// ── ASSETS (paginated, with notes + attachments embedded) ────────────────────
if ($action === 'assets') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit  = isset($_GET['limit'])  ? min((int) $_GET['limit'], 200) : 100;

    // Main asset query — internal location + base fields
    $sql = "
        SELECT
            a.asset_id,
            a.asset_code,
            a.checked_out_flag,
            am.asset_model_code,
            am.short_description  AS model_name,
            cat.short_description AS category_name,
            loc.short_description AS internal_location,
            acfh.cfv_22           AS due_back,
            acfh.cfv_23           AS next_cal_due
        FROM asset a
        LEFT JOIN asset_model am
               ON am.asset_model_id = a.asset_model_id
        LEFT JOIN category cat
               ON cat.category_id   = am.category_id
        LEFT JOIN location loc
               ON loc.location_id   = a.location_id
        LEFT JOIN asset_custom_field_helper acfh
               ON acfh.asset_id     = a.asset_id
        WHERE a.asset_id IS NOT NULL AND TRIM(a.asset_id) != ''
        ORDER BY a.asset_id
        LIMIT :lim OFFSET :off
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $assets = $stmt->fetchAll();

    if (empty($assets)) {
        echo json_encode(array('assets' => array()));
        exit;
    }

    // Collect asset IDs for sub-queries
    $assetIds = array();
    foreach ($assets as $a) {
        $assetIds[] = (int) $a['asset_id'];
    }
    $inList = implode(',', $assetIds);

    // Effective location per asset:
    // If the asset is currently checked out to a company → use company name (vendor)
    // If checked out to a user → use username
    // Otherwise → use internal location (already in main query)
    //
    // Strategy: find the latest asset_transaction per asset, then follow
    // asset_transaction_checkout → contact → company / user_account
    $locationOverrides = array();
    $locSql = "
        SELECT
            att.asset_id,
            com.short_description AS company_name,
            ua.username           AS checked_out_user
        FROM asset_transaction att
        INNER JOIN (
            SELECT asset_id, MAX(asset_transaction_id) AS max_tx_id
            FROM asset_transaction
            WHERE asset_id IN ($inList)
            GROUP BY asset_id
        ) latest_tx
               ON latest_tx.asset_id  = att.asset_id
              AND latest_tx.max_tx_id  = att.asset_transaction_id
        LEFT JOIN asset_transaction_checkout atc
               ON atc.asset_transaction_id = att.asset_transaction_id
        LEFT JOIN contact c
               ON c.contact_id        = atc.to_contact_id
        LEFT JOIN company com
               ON com.company_id      = c.company_id
        LEFT JOIN user_account ua
               ON ua.user_account_id  = atc.to_user_id
        WHERE att.asset_id IN ($inList)
          AND (com.company_id IS NOT NULL OR ua.user_account_id IS NOT NULL)
    ";
    foreach ($pdo->query($locSql)->fetchAll() as $row) {
        $aid = (int) $row['asset_id'];
        $locationOverrides[$aid] = array(
            'company_name'     => $row['company_name'],
            'checked_out_user' => $row['checked_out_user'],
        );
    }

    // Checkout due_date: latest checkout transaction (has an asset_transaction_checkout
    // record) that also has a due_date set.
    // Using MAX over only checkout-type transactions so that a later non-checkout
    // transaction (e.g. a return record with no atc row) doesn't cause the JOIN
    // to fail and hide the due_date.
    $checkouts = array();
    $coDueSql = "
        SELECT sub.asset_id, atc.due_date
        FROM (
            SELECT att.asset_id, MAX(att.asset_transaction_id) AS max_co_tx_id
            FROM asset_transaction att
            INNER JOIN asset_transaction_checkout atc2
                ON atc2.asset_transaction_id = att.asset_transaction_id
            WHERE att.asset_id IN ($inList)
            GROUP BY att.asset_id
        ) sub
        JOIN asset_transaction_checkout atc
            ON atc.asset_transaction_id = sub.max_co_tx_id
        WHERE atc.due_date IS NOT NULL
    ";
    foreach ($pdo->query($coDueSql)->fetchAll() as $row) {
        $checkouts[(int) $row['asset_id']] = substr($row['due_date'], 0, 10);
    }

    // issued_date: COALESCE(atc.modified_date, atc.creation_date) from the latest
    // checkout transaction for each asset.  No due_date filter — every asset that
    // has ever been checked out should expose when it was last issued.
    // Mirrors old inventory's show_date logic (ssp_assetlist.php).
    //
    // Key difference from coDueSql: MAX() is taken over only transactions that
    // HAVE an asset_transaction_checkout row, so later non-checkout transactions
    // (returns, notes, etc.) do not obscure the real checkout date.
    $issuedDates = array();
    $issuedSql = "
        SELECT sub.asset_id,
               COALESCE(atc.modified_date, atc.creation_date) AS issued_date
        FROM (
            SELECT att.asset_id, MAX(att.asset_transaction_id) AS max_co_tx_id
            FROM asset_transaction att
            INNER JOIN asset_transaction_checkout atc2
                ON atc2.asset_transaction_id = att.asset_transaction_id
            WHERE att.asset_id IN ($inList)
            GROUP BY att.asset_id
        ) sub
        JOIN asset_transaction_checkout atc
            ON atc.asset_transaction_id = sub.max_co_tx_id
    ";
    foreach ($pdo->query($issuedSql)->fetchAll() as $row) {
        $aid = (int) $row['asset_id'];
        $issuedDates[$aid] = $row['issued_date'] ? substr($row['issued_date'], 0, 10) : null;
    }

    // Top-level notes (class='A', tid IS NULL) per asset
    // tid IS NULL excludes thread replies (matches old system behaviour)
    // notes IS NOT NULL ensures the note has actual content
    $notesByAsset = array();
    $allNoteIds   = array();
    $notesSql = "
        SELECT n.noteid, n.id AS asset_id, n.notes, n.priority,
               n.created_date, n.files
        FROM inv_notes n
        WHERE n.class   = 'A'
          AND n.id      IN ($inList)
          AND n.tid     IS NULL
          AND n.notes   IS NOT NULL
          AND n.redact  = 0
        ORDER BY n.noteid ASC
    ";
    foreach ($pdo->query($notesSql)->fetchAll() as $row) {
        $aid = (int) $row['asset_id'];
        if (!isset($notesByAsset[$aid])) {
            $notesByAsset[$aid] = array();
        }
        $notesByAsset[$aid][] = $row;
        $allNoteIds[] = (int) $row['noteid'];
    }

    // Attachments linked to those notes via noteid
    // tmp_name IS NOT NULL ensures a file actually exists (SHA-256 hash stored there)
    $attachmentsByNote = array();
    if (!empty($allNoteIds)) {
        $noteInList = implode(',', $allNoteIds);
        $attSql = "
            SELECT na.attachment_id, na.noteid, na.filename, na.type, na.tmp_name
            FROM notes_attachments na
            WHERE na.noteid      IN ($noteInList)
              AND na.tmp_name    IS NOT NULL
              AND na.tmp_name    != ''
              AND na.redact      = 0
        ";
        foreach ($pdo->query($attSql)->fetchAll() as $row) {
            $nid = (int) $row['noteid'];
            if (!isset($attachmentsByNote[$nid])) {
                $attachmentsByNote[$nid] = array();
            }
            $attachmentsByNote[$nid][] = array(
                'attachment_id' => (int) $row['attachment_id'],
                'filename'      => $row['filename'],
                'type'          => $row['type'],
                'tmp_name'      => $row['tmp_name'],  // SHA-256 hash (file not transferred)
            );
        }
    }

    // Build response
    $output = array();
    foreach ($assets as $a) {
        $aid   = (int) $a['asset_id'];
        $notes = array();

        if (isset($notesByAsset[$aid])) {
            foreach ($notesByAsset[$aid] as $n) {
                $nid = (int) $n['noteid'];
                $notes[] = array(
                    'noteid'       => $nid,
                    'notes'        => $n['notes'],
                    'priority'     => $n['priority'],
                    'created_date' => $n['created_date'],
                    'files'        => $n['files'],
                    'attachments'  => isset($attachmentsByNote[$nid])
                                        ? $attachmentsByNote[$nid]
                                        : array(),
                );
            }
        }

        // Effective location: vendor/company > checked-out user > internal location
        $effectiveLocation = $a['internal_location'];
        $companyName       = null;
        if (isset($locationOverrides[$aid])) {
            $ov = $locationOverrides[$aid];
            if (!empty($ov['company_name'])) {
                $effectiveLocation = $ov['company_name'];
                $companyName       = $ov['company_name'];
            } elseif (!empty($ov['checked_out_user'])) {
                $effectiveLocation = $ov['checked_out_user'];
            }
        }

        $checkedOutUser = null;
        if (isset($locationOverrides[$aid]) && !empty($locationOverrides[$aid]['checked_out_user'])) {
            $checkedOutUser = $locationOverrides[$aid]['checked_out_user'];
        }

        $output[] = array(
            'asset_id'           => $aid,
            'asset_code'         => $a['asset_code'],
            'checked_out_flag'   => (int) $a['checked_out_flag'],  // 1 = currently checked out
            'asset_model_code'   => $a['asset_model_code'],
            'model_name'         => $a['model_name'],
            'category_name'      => $a['category_name'],
            'location_name'      => $effectiveLocation,    // vendor/user name or internal location
            'internal_location'  => $a['internal_location'], // always the physical base location
            'company_name'       => $companyName,           // set only when checked out to a company
            'checked_out_user'   => $checkedOutUser,        // username when checked out to a user
            'due_back'           => $a['due_back'],
            'next_cal_due'       => $a['next_cal_due'],
            'checkout_due'       => isset($checkouts[$aid])   ? $checkouts[$aid]   : null,
            'issued_date'        => isset($issuedDates[$aid]) ? $issuedDates[$aid] : null,
            'notes'              => $notes,
        );
    }

    echo json_encode(array('assets' => $output));
    exit;
}

// ── TXN_COUNT ────────────────────────────────────────────────────────────────
if ($action === 'txn_count') {
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM `transaction` t
         LEFT JOIN `asset_transaction` att ON att.`transaction_id` = t.`transaction_id`
         WHERE t.`entity_qtype_id` = 1 AND att.`asset_id` IS NOT NULL"
    );
    echo json_encode(array('count' => (int) $stmt->fetchColumn()));
    exit;
}

// ── TRANSACTIONS (paginated) ──────────────────────────────────────────────────
// Mirrors the query in asset_transaction_ssp.php on the old server.
// Each row exposes every field needed to reconstruct the transaction in MagDyn:
//   transaction_id      — old PK (embedded in notes for dedup on re-import)
//   transaction_type_id — 1=Move 2=CheckIn 3=CheckOut 10=Archive 11=Unarchive
//   asset_id            — old asset PK (matched to assets.asset_tag in MagDyn)
//   source_location     — locS.short_description
//   dest_location       — locD.short_description
//   company_name        — set when the checkout was to a company/vendor
//   checked_out_user    — set when the checkout was to a user
//   due_date            — from asset_transaction_checkout (checkout rows only)
//   at                  — COALESCE(modified_date, creation_date)
//   notes               — transaction note text
//   created_by_username — user who created the transaction
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'transactions') {
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $limit  = isset($_GET['limit'])  ? min((int) $_GET['limit'], 200) : 100;

    $sql = "
        SELECT
            t.transaction_id,
            t.transaction_type_id,
            att.asset_id,
            locS.short_description  AS source_location,
            locD.short_description  AS dest_location,
            com.short_description   AS company_name,
            ua1.username            AS checked_out_user,
            atc.due_date,
            COALESCE(t.modified_date, t.creation_date) AS at,
            t.note                  AS notes,
            ua.username             AS created_by_username
        FROM `transaction` t
        LEFT JOIN `asset_transaction` att
               ON att.`transaction_id`       = t.`transaction_id`
        LEFT JOIN `asset_transaction_checkout` atc
               ON atc.`asset_transaction_id` = att.`asset_transaction_id`
        LEFT JOIN `contact` c
               ON c.`contact_id`             = atc.`to_contact_id`
        LEFT JOIN `company` com
               ON com.`company_id`           = c.`company_id`
        LEFT JOIN `user_account` ua1
               ON ua1.`user_account_id`      = atc.`to_user_id`
        LEFT JOIN `location` locS
               ON locS.`location_id`         = att.`source_location_id`
        LEFT JOIN `location` locD
               ON locD.`location_id`         = att.`destination_location_id`
        LEFT JOIN `user_account` ua
               ON ua.`user_account_id`       = t.`created_by`
        WHERE t.`entity_qtype_id` = 1
          AND att.`asset_id` IS NOT NULL
        ORDER BY t.transaction_id ASC
        LIMIT :lim OFFSET :off
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $output = array();
    foreach ($rows as $t) {
        $output[] = array(
            'transaction_id'      => (int) $t['transaction_id'],
            'transaction_type_id' => (int) $t['transaction_type_id'],
            'asset_id'            => (int) $t['asset_id'],
            'source_location'     => $t['source_location'],
            'dest_location'       => $t['dest_location'],
            'company_name'        => $t['company_name'],
            'checked_out_user'    => $t['checked_out_user'],
            'due_date'            => $t['due_date'] ? substr($t['due_date'], 0, 10) : null,
            'at'                  => $t['at']       ? substr($t['at'], 0, 19)       : null,
            'notes'               => $t['notes'],
            'created_by_username' => $t['created_by_username'],
        );
    }

    echo json_encode(array('transactions' => $output));
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(array('error' => 'Unknown action. Supported: count, assets, txn_count, transactions'));
