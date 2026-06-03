<?php
/**
 * ocr_proxy.php — forwards browser auto-bubble OCR requests to the local
 * PaddleOCR Python service (typically on 127.0.0.1:8765).
 *
 * This sits on the same origin as the rest of the MagDyn tools, so the
 * browser hits it without CORS concerns. The Python service stays bound
 * to localhost and is not directly internet-reachable.
 *
 * Request:
 *   POST ocr_proxy.php
 *   Content-Type: application/json
 *   Body:
 *     {
 *       "image": "<base64 PNG or JPEG, with or without data: prefix>",
 *       "lang": "en"   // optional
 *     }
 *
 * Response (success):
 *   200 application/json — passes through whatever the OCR service returned.
 *
 * Response (errors):
 *   400 — bad request from the browser (missing field, etc.)
 *   502 — OCR service unreachable or returned an error
 *   503 — proxy disabled / not configured
 */

// MagDyn integration: gate OCR proxy access to logged-in users.
// Returns JSON 401 instead of redirecting since this is an AJAX endpoint.
require_once __DIR__ . '/../includes/bootstrap.php';
if (!function_exists('current_user_id') || !current_user_id()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not authenticated']);
    exit;
}

// ---- Config -----------------------------------------------------------
// Adjust these for your install. Defaults match paddleocr_server.py defaults.
$OCR_HOST          = '127.0.0.1';
$OCR_PORT          = 8765;
$OCR_PATH          = '/ocr';
$OCR_TIMEOUT_SECS  = 60;     // generous: cold start of paddle can take ~15s
$MAX_BODY_BYTES    = 25 * 1024 * 1024;   // 25 MB — enough for a hi-res page render

// ---- Set response headers --------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

// ---- Read & validate the incoming body --------------------------------
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty body']);
    exit;
}
if (strlen($raw) > $MAX_BODY_BYTES) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'image too large']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "missing 'image' field"]);
    exit;
}

// Re-encode (don't trust the raw shape) so the upstream gets exactly what
// it expects — and so any extraneous fields don't get smuggled along.
$forward = json_encode([
    'image' => $payload['image'],
    'lang'  => isset($payload['lang']) && is_string($payload['lang']) ? $payload['lang'] : 'en',
]);

// ---- Forward via cURL -------------------------------------------------
$url = 'http://' . $OCR_HOST . ':' . $OCR_PORT . $OCR_PATH;

$ch = curl_init($url);
if ($ch === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'curl unavailable']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => $forward,
    CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_CONNECTTIMEOUT  => 5,
    CURLOPT_TIMEOUT         => $OCR_TIMEOUT_SECS,
    // We're talking to ourselves on localhost — no need to verify TLS,
    // and we're using plain HTTP anyway.
    CURLOPT_FOLLOWLOCATION  => false,
]);

$response = curl_exec($ch);
$errno    = curl_errno($ch);
$errstr   = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($errno !== 0) {
    error_log("[ocr_proxy] curl error $errno: $errstr (url=$url)");
    http_response_code(502);
    echo json_encode([
        'ok'    => false,
        'error' => 'OCR service unreachable: ' . $errstr,
        'hint'  => 'Is the Python paddleocr_server running on ' . $OCR_HOST . ':' . $OCR_PORT . '?'
    ]);
    exit;
}

// Pass through the upstream response. If it returned a non-200 we still
// surface its body so the browser can show useful errors.
if ($httpCode >= 500) {
    http_response_code(502);
} elseif ($httpCode === 0) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'no response from OCR service']);
    exit;
} else {
    http_response_code($httpCode);
}

echo $response;
