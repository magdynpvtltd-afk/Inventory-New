<?php
/**
 * CLI runner for OldInventoryAssetImportService.
 * Run: php cli_import_old_inventory.php
 * Delete this file after use.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Bootstrap enough of MagDyn to run the service
define('MAGDYN_ROOT', __DIR__);
define('IN_APP', true);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/old_inventory_api.php';
require_once __DIR__ . '/services/OldInventoryAssetImportService.php';

// Use actor_id = 1 (admin) for the import transactions
$actorId = 1;

echo "Starting old inventory import...\n";
$start = microtime(true);

try {
    $svc    = new OldInventoryAssetImportService($actorId);
    $result = $svc->run();
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

$elapsed = round(microtime(true) - $start, 1);

echo "\n=== Import Complete ({$elapsed}s) ===\n";
echo "Inserted : " . ($result['inserted'] ?? 0) . "\n";
echo "Updated  : " . ($result['updated']  ?? 0) . "\n";
echo "Skipped  : " . ($result['skipped']  ?? 0) . "\n";
echo "Failed   : " . ($result['failed']   ?? 0) . "\n";
echo "Total    : " . ($result['total']    ?? 0) . "\n";

if (!empty($result['errors'])) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $err) {
        echo "  - {$err}\n";
    }
}

if (!empty($result['warnings'])) {
    echo "\nWarnings (first 20):\n";
    $shown = 0;
    foreach ($result['warnings'] as $w) {
        if ($shown++ >= 20) { echo "  ... (truncated)\n"; break; }
        echo "  - {$w}\n";
    }
}
