<?php
if (php_sapi_name() !== 'cli') { exit('CLI only'); }

$url = 'http://192.168.1.249/inventory/api_export_assets.php?action=assets&offset=0&limit=5&token=MAGDYN_IMPORT_SECRET';
$ctx = stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => 30, 'ignore_errors' => true],
]);
$raw = file_get_contents($url, false, $ctx);
$data = json_decode($raw, true);

if (isset($data['assets'][0])) {
    $a = $data['assets'][0];
    echo "Keys in first asset:\n";
    foreach (array_keys($a) as $k) {
        echo "  $k => " . (is_null($a[$k]) ? 'NULL' : (is_array($a[$k]) ? '[array]' : $a[$k])) . "\n";
    }
}
