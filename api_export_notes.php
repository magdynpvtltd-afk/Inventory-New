<?php
/**
 * MagDyn — Old Inventory Running-Notes Export API
 *
 * Deploy this file on the OLD inventory server (PHP 5.6, 192.168.1.249).
 * Place it in the web root (/inventory/) and point `notes_url` in
 * config/old_inventory_api.php on the new MagDyn system at it.
 *
 * It exports the legacy `inv_notes` table (running notes attached to assets
 * and inventory items) together with the `notes_attachments` rows linked to
 * each note. Files are NOT transferred — only metadata + the SHA-256
 * `tmp_name` the old server stored each attachment under, so MagDyn can
 * rebuild the link once the physical files are copied across.
 *
 * Endpoints (all GET, all require ?token=MAGDYN_IMPORT_SECRET):
 *   ?action=ping
 *       Returns: {"ok": true, "server": "api_export_notes"}
 *
 *   ?action=notes_count
 *       Returns: {"count": 1234}   (non-redacted rows only)
 *
 *   ?action=all_notes_json&offset=0&limit=500
 *       Returns: {"notes": [ {
 *           noteid, id, tid, class, notes, priority,
 *           created_by, created_date, modified_date, files,
 *           attachments: [ {attachment_id, filename, type, tmp_name}, ... ]
 *       }, ... ], "count": N}
 *
 * PHP 5.6 compatible — no null coalescing, no return types, no scalar hints.
 */

// ── Shared secret ────────────────────────────────────────────────────────────
define('API_TOKEN', 'MAGDYN_IMPORT_SECRET');   // ← must match config/old_inventory_api.php

// ── Auth check ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

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
        'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
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

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

// Pagination — cap limit at 1000 to protect server memory.
$offset = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
$limit  = min(1000, max(1, (int)(isset($_GET['limit']) ? $_GET['limit'] : 500)));

// ── ping ──────────────────────────────────────────────────────────────────────
if ($action === 'ping') {
    echo json_encode(array('ok' => true, 'server' => 'api_export_notes'));
    exit;
}

// ── notes_count — non-redacted inv_notes rows ──────────────────────────────────
if ($action === 'notes_count') {
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM inv_notes WHERE redact = 0')->fetchColumn();
        echo json_encode(array('count' => $n));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Count query failed: ' . $e->getMessage()));
    }
    exit;
}

// ── all_notes_json — inv_notes (+ attachments) paginated ───────────────────────
if ($action === 'all_notes_json') {
    try {
        // Step 1 — this page of notes (skip redacted, skip empty bodies).
        $notesSql = "
            SELECT n.noteid, n.id, n.tid, n.class, n.notes, n.priority,
                   n.created_by, n.created_date, n.modified_date, n.files
            FROM inv_notes n
            WHERE n.redact = 0
            ORDER BY n.noteid ASC
            LIMIT " . $limit . " OFFSET " . $offset . "
        ";
        $notes   = $pdo->query($notesSql)->fetchAll();
        $noteIds = array();
        foreach ($notes as $n) {
            $noteIds[] = (int)$n['noteid'];
        }

        // Step 2 — attachments for those notes (skip redacted / file-less rows).
        $attByNote = array();
        if (!empty($noteIds)) {
            $inList  = implode(',', $noteIds);
            $attSql  = "
                SELECT na.attachment_id, na.noteid, na.filename, na.type, na.tmp_name
                FROM notes_attachments na
                WHERE na.noteid IN ($inList)
                  AND na.redact = 0
                  AND na.tmp_name IS NOT NULL
                  AND na.tmp_name != ''
                ORDER BY na.attachment_id ASC
            ";
            foreach ($pdo->query($attSql)->fetchAll() as $a) {
                $nid = (int)$a['noteid'];
                if (!isset($attByNote[$nid])) {
                    $attByNote[$nid] = array();
                }
                $attByNote[$nid][] = array(
                    'attachment_id' => (int)$a['attachment_id'],
                    'filename'      => $a['filename'],
                    'type'          => $a['type'],
                    'tmp_name'      => $a['tmp_name'],   // SHA-256 hash — old stored filename
                );
            }
        }

        // Step 3 — stitch attachments onto each note.
        $out = array();
        foreach ($notes as $n) {
            $nid = (int)$n['noteid'];
            $out[] = array(
                'noteid'        => $nid,
                'id'            => (int)$n['id'],
                'tid'           => ($n['tid'] === null ? null : (int)$n['tid']),
                'class'         => $n['class'],
                'notes'         => $n['notes'],
                'priority'      => $n['priority'],
                'created_by'    => (int)$n['created_by'],
                'created_date'  => $n['created_date'],
                'modified_date' => $n['modified_date'],
                'files'         => $n['files'],
                'attachments'   => isset($attByNote[$nid]) ? $attByNote[$nid] : array(),
            );
        }

        echo json_encode(array('notes' => $out, 'count' => count($out)));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Query failed: ' . $e->getMessage()));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action: ' . $action));
