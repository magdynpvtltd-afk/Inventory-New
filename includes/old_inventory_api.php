<?php
/**
 * MagDyn — Old Inventory HTTP API client helper.
 *
 * Calls api_export_assets.php on the old inventory server and returns
 * the decoded JSON response as a PHP array.
 *
 * Usage:
 *   $data  = old_inventory_api('count');
 *   $total = $data['count'];
 *
 *   $data   = old_inventory_api('assets', ['offset' => 0, 'limit' => 100]);
 *   $assets = $data['assets'];
 *
 * Throws RuntimeException on network error, bad JSON, or API-level error.
 */
function old_inventory_api(string $action, array $params = []): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/old_inventory_api.php';
    }

    $params['action'] = $action;
    $params['token']  = $cfg['token'];

    $url = rtrim($cfg['url'], '/') . '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => $cfg['timeout'],
            'header'  => "Accept: application/json\r\nConnection: close\r\n",
            'ignore_errors' => true,   // capture 4xx/5xx bodies too
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        throw new RuntimeException(
            'Could not reach old inventory API at ' . $cfg['url'] .
            '. Check that the server is reachable and the file is deployed.'
        );
    }

    $data = json_decode($raw, true);

    if ($data === null) {
        throw new RuntimeException(
            'Old inventory API returned invalid JSON. ' .
            'Response (first 200 chars): ' . substr($raw, 0, 200)
        );
    }

    if (isset($data['error'])) {
        throw new RuntimeException('Old inventory API error: ' . $data['error']);
    }

    return $data;
}

/**
 * MagDyn — Old Inventory vendor/user export API client.
 *
 * Same contract as old_inventory_api() but targets the dedicated
 * api_export_vendors.php endpoint (config key 'vendors_url'), used to
 * import vendors, contacts, addresses and application users.
 *
 * Usage:
 *   $data    = old_inventory_vendor_api('vendor_count');
 *   $total   = $data['count'];
 *   $data    = old_inventory_vendor_api('vendors', ['offset' => 0, 'limit' => 100]);
 *   $vendors = $data['vendors'];
 *
 * Throws RuntimeException on network error, bad JSON, or API-level error.
 */
function old_inventory_vendor_api(string $action, array $params = []): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/old_inventory_api.php';
    }

    if (empty($cfg['vendors_url'])) {
        throw new RuntimeException(
            "vendors_url not set in config/old_inventory_api.php."
        );
    }

    $params['action'] = $action;
    $params['token']  = $cfg['token'];

    $url = rtrim($cfg['vendors_url'], '/') . '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => $cfg['timeout'],
            'header'  => "Accept: application/json\r\nConnection: close\r\n",
            'ignore_errors' => true,   // capture 4xx/5xx bodies too
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);

    if ($raw === false) {
        throw new RuntimeException(
            'Could not reach old inventory vendor API at ' . $cfg['vendors_url'] .
            '. Check that the server is reachable and api_export_vendors.php is deployed.'
        );
    }

    $data = json_decode($raw, true);

    if ($data === null) {
        throw new RuntimeException(
            'Old inventory vendor API returned invalid JSON. ' .
            'Response (first 200 chars): ' . substr($raw, 0, 200)
        );
    }

    if (isset($data['error'])) {
        throw new RuntimeException('Old inventory vendor API error: ' . $data['error']);
    }

    return $data;
}
