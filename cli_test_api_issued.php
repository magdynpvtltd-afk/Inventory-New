<?php
if (php_sapi_name() !== 'cli') { exit('CLI only'); }
define('IN_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/old_inventory_api.php';

// Fetch first batch and show issued_date for checked-out assets
$data = old_inventory_api('assets', ['offset' => 0, 'limit' => 50]);
$assets = $data['assets'] ?? [];

$found = 0;
foreach ($assets as $a) {
    if ($a['checked_out_flag']) {
        printf("id=%-4d tag=%-6s  flag=%d  issued=%-12s  due=%s\n",
            $a['asset_id'], $a['asset_code'], $a['checked_out_flag'],
            $a['issued_date'] ?: 'NULL',
            $a['checkout_due'] ?: 'NULL');
        $found++;
        if ($found >= 10) break;
    }
}
echo "\nTotal in batch: " . count($assets) . ", checked out shown: $found\n";
