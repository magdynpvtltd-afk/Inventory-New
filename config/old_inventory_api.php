<?php
/**
 * MagDyn — Old Inventory API connection config.
 *
 * Points to the api_export_assets.php file deployed on the old
 * inventory server.  The token must match API_TOKEN in that file.
 */
return [
    // Full URL to api_export_assets.php on the old server
    'url'     => 'http://192.168.1.249/inventory/api_export_assets.php',

    // Shared secret — must match API_TOKEN defined in api_export_assets.php
    'token'   => 'MAGDYN_IMPORT_SECRET',

    // HTTP timeout in seconds for each API call
    'timeout' => 30,
];
