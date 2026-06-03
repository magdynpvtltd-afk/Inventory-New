<?php
/**
 * MagDyn — SO pending data ingestion API
 * Created: 2026-05-25 07:32 IST
 * Hardened: 2026-05-25 — emit JSON on bootstrap failures.
 * CORS:     2026-05-25 — allow cross-origin calls (any origin) so
 *                       test pages on other subdomains/folders work.
 *
 * Service-to-service endpoint. The external sales-order app pushes
 * pending-SO aggregates here whenever an SO is added / amended /
 * closed. MagDyn stores the latest snapshot per part code in
 * inv_so_pending_summary; the BOM tree grid reads from that table
 * at render time.
 *
 * Authentication
 *   Bearer token in the Authorization header. The token is configured
 *   on MagDyn's side under so_integration.bearer_token in
 *   config/app.config.php; the SO app must send the same value.
 *   Constant-time compare to avoid timing-attack leakage.
 *
 * Methods (dispatched by ?op= query string)
 *
 *   POST ?op=upsert         — upsert ONE part's aggregate
 *     body: {"item_code": "...", "so_count": N, "qty_pending": Q}
 *
 *   POST ?op=bulk_replace   — wipe + insert ALL parts (initial sync /
 *                             drift recovery)
 *     body: {"parts": [{"item_code":"...","so_count":N,"qty_pending":Q}, ...]}
 *
 *   POST ?op=delete         — remove ONE part's row (e.g. no more
 *                             open SOs reference it)
 *     body: {"item_code": "..."}
 *
 * All endpoints respond with JSON. 200 on success, 4xx on bad input,
 * 401 on auth failure, 500 on unexpected DB error.
 *
 * Bypasses the session-auth layer (the SO app is a service, not a
 * user) by inlining bootstrap below WITHOUT calling require_login.
 *
 * Spec for the SO app's HTTP integration: see docs/SO_INTEGRATION_API.md.
 */

// ============================================================
// CORS — allow cross-origin browser calls.
// ============================================================
// Browser-based callers from other subdomains (e.g. an SO-app admin
// page on a different host) need CORS headers or Chrome blocks the
// request at the client side with "blocked:origin" before it ever
// reaches us. Server-to-server callers (curl, the SO app's PHP)
// ignore these headers — no harm.
//
// Security note: the bearer token is the only protection on this
// endpoint. CORS does not weaken that — browsers do NOT auto-attach
// Authorization headers cross-origin without explicit code, so a
// random malicious site cannot piggyback on a logged-in user's
// session here. Setting Access-Control-Allow-Origin to "*" is safe.
//
// We DO restrict the methods + headers honored on preflight to
// exactly what this endpoint actually accepts.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');  // 24h preflight cache

// Capture request start time for the optional X-Server-Time-Ms response
// header. Lets the caller see exactly how much wall-clock time the
// server spent on this request, so client-side perceived latency can
// be split from server-side processing time when triaging slow calls.
// In $GLOBALS so so_api_emit() can see it.
$GLOBALS['_t_start'] = microtime(true);

// Preflight (OPTIONS) — Chrome sends this before any cross-origin
// POST with custom headers. Respond 204 with the CORS headers above
// and exit; never reach bootstrap or auth.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// PROBE — confirm the file is being executed at all.
// ============================================================
// GET /api/so_pending.php?probe=1 returns a static JSON document
// without touching bootstrap, config, or the DB. If this works,
// PHP is running the file and we know the 500 is happening in
// bootstrap or later. If THIS also returns a 500, something at the
// web-server level (.htaccess, PHP-FPM, file permissions) is
// blocking PHP execution.
//
// Safe to leave in production — it discloses nothing sensitive
// and there's no auth on it because there's no data to protect.
if (isset($_GET['probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'             => true,
        'probe'          => 'alive',
        'php_version'    => PHP_VERSION,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? '?',
        'received_at'    => date('c'),
    ]);
    exit;
}

// ============================================================
// Pre-bootstrap hardening
// ============================================================
// Force-set the JSON header BEFORE bootstrap loads, so if bootstrap
// itself dies (config parse error, missing file, DB unreachable)
// the response is still served as application/json — the caller can
// at least see the error code without parsing HTML.
//
// Also install a shutdown handler that converts uncaught fatals
// into a JSON 500 with the error details from error_get_last(). This
// is how the SO app sees "your config is broken" instead of "HTTP
// 500 with non-JSON body".
//
// Both safeguards are no-ops on the happy path.
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);  // tentative; overridden later if anything goes wrong

// Stop PHP from injecting warnings/notices into the HTTP body. This
// is a service endpoint, not a browsable page; warnings still get
// logged via error_log() (we don't set log_errors to off), they
// just don't pollute the JSON response. Critical for clients that
// strict-parse JSON.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Buffer any stray output (echoes/prints from bootstrap-included
// files) so it doesn't leak into the JSON response. We clean the
// buffer explicitly before each JSON emit (see so_api_emit).
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    // Normal exit path: do NOTHING. so_api_emit already drained the
    // buffer and echoed the JSON body before exit. Touching the
    // output buffer here would eat the response — that bug was
    // observed as "RESPONSE: <empty>" on a 401 even though so_api_emit
    // did echo the body.
    if (!$err) return;

    // Non-fatal lingering error (notice/warning): also nothing to do.
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    // Fatal occurred. Discard any partial output and emit JSON. Note
    // this only runs if so_api_emit never got a chance to fire — by
    // definition, a fatal interrupts whatever was happening.
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    error_log(sprintf(
        '[so_pending.php] fatal: %s in %s:%d',
        $err['message'], $err['file'], $err['line']
    ));
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_fatal',
        'message' => 'PHP fatal: ' . $err['message']
                   . ' in ' . basename($err['file']) . ':' . $err['line'],
    ]);
});

// ============================================================
// Lean bootstrap — load ONLY what this API endpoint needs.
// ============================================================
// The full bootstrap.php starts a session, loads helpers / csrf /
// auth / permissions / sso — none of which apply to a stateless,
// bearer-token-authed service endpoint. session_start() in
// particular does filesystem locking on shared hosting and was
// observed adding ~1.5s of latency to every request. We sidestep
// it entirely.
//
// What we DO need:
//   - the $APP config (for the bearer token)
//   - the $DB config + db.php (for the upsert)
//
// We replicate just those two steps. If bootstrap.php gets fancier
// in the future this file may need to be revisited.
try {
    $ROOT = dirname(__DIR__);
    $APP  = require $ROOT . '/config/app.config.php';
    $DB   = require $ROOT . '/config/db.config.php';
    $GLOBALS['APP']  = $APP;
    $GLOBALS['DB']   = $DB;
    $GLOBALS['ROOT'] = $ROOT;
    if (!empty($APP['timezone'])) {
        date_default_timezone_set($APP['timezone']);
    }
    require_once $ROOT . '/includes/db.php';
} catch (\Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    error_log('[so_pending.php] lean bootstrap failed: ' . $e->getMessage());
    echo json_encode([
        'ok'      => false,
        'error'   => 'bootstrap_failed',
        'message' => 'Bootstrap failed: ' . $e->getMessage(),
    ]);
    exit;
}

// Now safe to operate. Keep the output buffer active — we will
// drain it explicitly via so_api_emit() right before each JSON
// response so any warnings/notices/stray echos from db code get
// logged to error_log (not flushed to the HTTP body).

// --------------------------------------------------------------------
// Helpers (local)
// --------------------------------------------------------------------

/**
 * Drain the output buffer to error_log and emit a JSON body.
 * Used by every code path that finalizes the response — both
 * success and failure. This is the SINGLE source of HTTP output
 * after bootstrap returns; nothing else echoes directly.
 */
function so_api_emit($status, array $payload)
{
    // Drain ALL active output buffers. PHP may have an implicit
    // buffer from the `output_buffering` ini setting on top of the
    // one we opened with ob_start, so a single ob_get_clean() can
    // leave a parent buffer intact — which then swallows our echo.
    // Drain everything to be safe; log stray content server-side.
    $stray = '';
    while (ob_get_level() > 0) {
        $chunk = ob_get_clean();
        if ($chunk !== false) $stray .= $chunk;
    }
    if ($stray !== '') {
        error_log('[so_pending.php] suppressed stray output: ' . substr($stray, 0, 1000));
    }
    http_response_code((int)$status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        // X-Server-Time-Ms: pure server wall-clock for triaging slow
        // requests. If the client sees 2000ms total but this header
        // says 30ms, the slowness is network/TLS — not us.
        if (isset($GLOBALS['_t_start'])) {
            $elapsedMs = (int)round((microtime(true) - $GLOBALS['_t_start']) * 1000);
            header('X-Server-Time-Ms: ' . $elapsedMs);
        }
    }
    echo json_encode($payload);
    // Flush via PHP-FPM's fastcgi_finish_request if available, so the
    // response goes out before any PHP-level cleanup kicks in. Falls
    // back to plain flush() on hosting that doesn't have it.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @flush();
    }
    exit;
}

/** Emit a JSON error and exit. Thin wrapper over so_api_emit. */
function so_api_fail($status, $code, $message)
{
    so_api_emit($status, ['ok' => false, 'error' => $code, 'message' => $message]);
}

/** Emit a JSON success and exit. Thin wrapper over so_api_emit. */
function so_api_ok(array $payload)
{
    $payload = array_merge(['ok' => true], $payload);
    so_api_emit(200, $payload);
}

/** Read JSON body or fail with 400. */
function so_api_read_json()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        so_api_fail(400, 'empty_body', 'Request body is empty; expected JSON.');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        so_api_fail(400, 'bad_json', 'Request body is not valid JSON.');
    }
    return $body;
}

/**
 * Validate one aggregate row. Returns the normalized tuple
 * [item_code, so_count, qty_pending] or fails with 400.
 */
function so_api_validate_row($row)
{
    if (!is_array($row)) {
        so_api_fail(400, 'bad_row', 'A part entry is not an object.');
    }
    if (!isset($row['item_code']) || !is_string($row['item_code']) || trim($row['item_code']) === '') {
        so_api_fail(400, 'missing_item_code', 'item_code is required and must be a non-empty string.');
    }
    $code = trim($row['item_code']);
    if (strlen($code) > 64) {
        so_api_fail(400, 'item_code_too_long', 'item_code exceeds 64 characters: ' . $code);
    }
    // so_count: required integer >= 0.
    if (!isset($row['so_count'])) {
        so_api_fail(400, 'missing_so_count', 'so_count is required.');
    }
    if (!is_int($row['so_count']) && !ctype_digit((string)$row['so_count'])) {
        so_api_fail(400, 'bad_so_count', 'so_count must be a non-negative integer.');
    }
    $soCount = (int)$row['so_count'];
    if ($soCount < 0) $soCount = 0;
    // qty_pending: required number >= 0.
    if (!isset($row['qty_pending'])) {
        so_api_fail(400, 'missing_qty_pending', 'qty_pending is required.');
    }
    if (!is_numeric($row['qty_pending'])) {
        so_api_fail(400, 'bad_qty_pending', 'qty_pending must be a non-negative number.');
    }
    $qty = (float)$row['qty_pending'];
    if ($qty < 0) $qty = 0.0;
    return [$code, $soCount, $qty];
}

// --------------------------------------------------------------------
// Auth check (bearer token, constant-time)
// --------------------------------------------------------------------

$cfg = $GLOBALS['APP']['so_integration'] ?? null;
$expected = (is_array($cfg) && !empty($cfg['bearer_token'])) ? (string)$cfg['bearer_token'] : '';
if ($expected === '') {
    // Integration is not configured. Refuse cleanly so the SO app
    // gets a useful 503 instead of guessing the token is wrong.
    so_api_fail(503, 'not_configured',
        'so_integration.bearer_token is not set in MagDyn config. Ask MagDyn admin to configure it.');
}

$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION']))      $authHeader = (string)$_SERVER['HTTP_AUTHORIZATION'];
elseif (function_exists('getallheaders')) {
    // Some shared-hosting setups strip Authorization from $_SERVER
    // unless an .htaccess rule reinjects it. getallheaders is the
    // most portable backstop.
    foreach (getallheaders() ?: [] as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authHeader = (string)$value; break; }
    }
}

if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    so_api_fail(401, 'missing_bearer',
        'Authorization header missing or not "Bearer <token>".');
}
$presented = $m[1];
if (!hash_equals($expected, $presented)) {
    so_api_fail(401, 'bad_token', 'Bearer token does not match.');
}

// --------------------------------------------------------------------
// Method + op dispatch
// --------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    so_api_fail(405, 'method_not_allowed', 'Use POST.');
}

$op = isset($_GET['op']) ? (string)$_GET['op'] : '';
if (!in_array($op, ['upsert', 'bulk_replace', 'delete'], true)) {
    so_api_fail(400, 'bad_op',
        'Unknown ?op=. Valid values: upsert, bulk_replace, delete.');
}

$body = so_api_read_json();

// --------------------------------------------------------------------
// op=upsert — single-part update
// --------------------------------------------------------------------
if ($op === 'upsert') {
    list($code, $soCount, $qty) = so_api_validate_row($body);

    try {
        // INSERT ... ON DUPLICATE KEY UPDATE — atomic upsert keyed
        // on the PRIMARY KEY (item_code). updated_at refreshes via
        // ON UPDATE CURRENT_TIMESTAMP automatically.
        db_exec(
            'INSERT INTO inv_so_pending_summary (item_code, so_count, qty_pending)
                  VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE
                  so_count    = VALUES(so_count),
                  qty_pending = VALUES(qty_pending)',
            [$code, $soCount, $qty]
        );
    } catch (\Throwable $e) {
        so_api_fail(500, 'db_error', 'Database write failed: ' . $e->getMessage());
    }

    so_api_ok([
        'op'          => 'upsert',
        'item_code'   => $code,
        'so_count'    => $soCount,
        'qty_pending' => $qty,
    ]);
}

// --------------------------------------------------------------------
// op=delete — remove one part's row
// --------------------------------------------------------------------
if ($op === 'delete') {
    if (!isset($body['item_code']) || !is_string($body['item_code']) || trim($body['item_code']) === '') {
        so_api_fail(400, 'missing_item_code', 'item_code is required.');
    }
    $code = trim($body['item_code']);

    try {
        db_exec('DELETE FROM inv_so_pending_summary WHERE item_code = ?', [$code]);
    } catch (\Throwable $e) {
        so_api_fail(500, 'db_error', 'Database delete failed: ' . $e->getMessage());
    }

    so_api_ok([
        'op'        => 'delete',
        'item_code' => $code,
    ]);
}

// --------------------------------------------------------------------
// op=bulk_replace — wipe + insert the entire snapshot
// --------------------------------------------------------------------
if ($op === 'bulk_replace') {
    if (!isset($body['parts']) || !is_array($body['parts'])) {
        so_api_fail(400, 'missing_parts', 'parts array is required.');
    }
    $parts = $body['parts'];
    if (count($parts) > 10000) {
        so_api_fail(413, 'too_many', 'bulk_replace caps at 10000 parts per call. Split into batches.');
    }

    // Validate ALL rows BEFORE writing anything (fail-fast keeps the
    // table consistent — we don't want a half-bulk_replace with some
    // rows applied and some refused).
    $normalized = [];
    $seen = [];
    foreach ($parts as $i => $row) {
        list($code, $soCount, $qty) = so_api_validate_row($row);
        if (isset($seen[$code])) {
            so_api_fail(400, 'duplicate_code',
                'Duplicate item_code in payload: ' . $code . ' (index ' . $i . ').');
        }
        $seen[$code] = true;
        $normalized[] = [$code, $soCount, $qty];
    }

    try {
        db()->beginTransaction();
        // TRUNCATE would be faster but cannot be rolled back in a
        // transaction in MariaDB. DELETE keeps the operation atomic.
        db_exec('DELETE FROM inv_so_pending_summary');
        foreach ($normalized as $tuple) {
            list($code, $soCount, $qty) = $tuple;
            db_exec(
                'INSERT INTO inv_so_pending_summary (item_code, so_count, qty_pending) VALUES (?, ?, ?)',
                [$code, $soCount, $qty]
            );
        }
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        so_api_fail(500, 'db_error', 'Bulk replace failed: ' . $e->getMessage());
    }

    so_api_ok([
        'op'    => 'bulk_replace',
        'count' => count($normalized),
    ]);
}
