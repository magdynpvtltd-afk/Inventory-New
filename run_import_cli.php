<?php
/**
 * CLI runner for OldInventoryAssetImportService
 * Run: php run_import_cli.php
 */

// Simulate a minimal web environment so bootstrap doesn't choke on session/headers
if (!defined('STDIN')) {
    die("Run via CLI only.\n");
}

// Suppress header-related errors in CLI
ini_set('display_errors', '1');
error_reporting(E_ALL);

$ROOT = __DIR__;
$APP  = require $ROOT . '/config/app.config.php';
$DB   = require $ROOT . '/config/db.config.php';

$GLOBALS['APP']  = $APP;
$GLOBALS['DB']   = $DB;
$GLOBALS['ROOT'] = $ROOT;

date_default_timezone_set($APP['timezone'] ?? 'UTC');

require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/helpers.php';
require_once $ROOT . '/includes/old_inventory_api.php';
require_once $ROOT . '/services/OldInventoryAssetImportService.php';

echo "Starting import...\n";

$svc    = new OldInventoryAssetImportService(1); // actor_id = 1 (admin)
$result = $svc->run();

echo "\n=== IMPORT RESULT ===\n";
echo "Models   : found={$result['model_total']}, created={$result['model_created']}\n";
echo "Assets   : total={$result['total']}, imported={$result['imported']}, updated={$result['updated']}, failed={$result['failed']}, skipped={$result['skipped']}\n";
echo "Txns     : total={$result['txn_total']}, imported={$result['txn_imported']}, failed={$result['txn_failed']}, skipped={$result['txn_skipped']}\n";

if (!empty($result['errors'])) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $e) {
        echo "  - $e\n";
    }
}

echo "\nDone.\n";
