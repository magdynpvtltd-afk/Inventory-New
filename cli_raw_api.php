<?php
if (php_sapi_name() !== 'cli') { exit('CLI only'); }
define('IN_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/old_inventory_api.php';

// Fetch first batch - show raw data for asset IDs 1 and 5
$data = old_inventory_api('assets', ['offset' => 0, 'limit' => 50]);
$assets = $data['assets'] ?? [];

foreach ($assets as $a) {
    if (in_array($a['asset_id'], [1, 5, 11, 22])) {
        echo "--- asset_id=" . $a['asset_id'] . " ---\n";
        echo "  asset_code:       " . $a['asset_code'] . "\n";
        echo "  checked_out_flag: " . $a['checked_out_flag'] . "\n";
        echo "  location_name:    " . ($a['location_name'] ?? 'KEY_MISSING') . "\n";
        echo "  internal_location:" . ($a['internal_location'] ?? 'KEY_MISSING') . "\n";
        echo "  company_name:     " . ($a['company_name'] ?? 'KEY_MISSING') . "\n";
        echo "  checked_out_user: " . ($a['checked_out_user'] ?? 'KEY_MISSING') . "\n";
        echo "  checkout_due:     " . ($a['checkout_due'] ?? 'NULL') . "\n";
        echo "  issued_date:      " . (array_key_exists('issued_date', $a) ? ($a['issued_date'] ?? 'NULL') : 'KEY_MISSING') . "\n";
    }
}
