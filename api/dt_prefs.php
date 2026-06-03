<?php
/**
 * MagDyn — Datatable preferences AJAX endpoint
 *
 * POST ?op=save_width   — single column width
 * POST ?op=save_layout  — full layout (order + visibility)
 * POST ?op=reset        — wipe all prefs for the dt
 *
 * Auth: session-based (require_login). No bearer token — the JS that
 * calls this lives in the same origin and already carries the cookie.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../includes/user_dt_prefs.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$op  = (string)($_GET['op'] ?? '');
$uid = current_user_id();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

// All ops read JSON from the body.
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);
if (!is_array($body)) $body = [];

// CSRF: every request must carry the session CSRF token in the
// X-CSRF-Token header. The header-based check sidesteps form-encoded
// CSRF expectations while still giving us protection against
// cross-origin POSTs.
$sentTok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$expTok  = function_exists('csrf_token') ? csrf_token() : '';
if ($expTok === '' || !hash_equals($expTok, $sentTok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad_csrf']);
    exit;
}

$dtId = trim((string)($body['dt_id'] ?? ''));
if ($dtId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'dt_id_required']);
    exit;
}

switch ($op) {
    case 'save_width':
        $col = trim((string)($body['column_key'] ?? ''));
        $w   = (int)($body['width_px'] ?? 0);
        if ($col === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'column_key_required']);
            exit;
        }
        $ok = user_dt_prefs_save_width($uid, $dtId, $col, $w);
        echo json_encode(['ok' => $ok]);
        exit;

    case 'save_layout':
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (!$items) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'items_required']);
            exit;
        }
        $ok = user_dt_prefs_save_layout($uid, $dtId, $items);
        echo json_encode(['ok' => $ok]);
        exit;

    case 'reset':
        $ok = user_dt_prefs_reset($uid, $dtId);
        echo json_encode(['ok' => $ok]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_op']);
        exit;
}
