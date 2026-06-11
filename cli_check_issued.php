<?php
if (php_sapi_name() !== 'cli') { exit('CLI only'); }
define('IN_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';

// Sample of issued dates for with_vendor / with_user assets
$rows = db_all("
    SELECT a.asset_tag, a.status, at2.at AS issued_at, at2.due_date
    FROM assets a
    JOIN asset_transactions at2 ON at2.asset_id = a.id
        AND at2.notes = 'old-inventory-import'
    WHERE a.status IN ('with_vendor','with_user')
    ORDER BY a.asset_tag
    LIMIT 20
");
echo "=== Sample issued dates (with_vendor / with_user) ===\n";
foreach ($rows as $r) {
    printf("tag=%-6s  status=%-11s  issued=%-10s  due=%s\n",
        $r['asset_tag'], $r['status'],
        substr($r['issued_at'], 0, 10),
        $r['due_date'] ?: 'NULL');
}

// Count today vs real dates
$todayCount = (int) db_val(
    "SELECT COUNT(*) FROM asset_transactions WHERE notes='old-inventory-import' AND DATE(at) = CURDATE()"
);
$totalCount = (int) db_val(
    "SELECT COUNT(*) FROM asset_transactions WHERE notes='old-inventory-import'"
);
echo "\nTransactions with today's date: $todayCount / $totalCount\n";
echo "Transactions with real past date: " . ($totalCount - $todayCount) . " / $totalCount\n";
