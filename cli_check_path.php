<?php
if (php_sapi_name() !== 'cli') { exit('CLI only'); }

// Try the test file at different URL paths
$urls = [
    'http://192.168.1.249/inventory/magdyn_test_path.php',
    'http://192.168.1.249/magdyn_test_path.php',
    'http://192.168.1.249/share_software/magdyn_test_path.php',
];

foreach ($urls as $url) {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    echo $url . " => " . ($raw ?: '(no response)') . "\n";
}
