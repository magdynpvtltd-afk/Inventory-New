<?php
/**
 * MagDyn — Old Inventory database connection configuration.
 *
 * Used exclusively by OldInventoryAssetImportService to read from the
 * legacy inventory_live database.  Never mixed with the main db() connection.
 *
 * Change host / credentials here if the remote server moves.
 */
return [
    'host'    => '192.168.1.249',
    'port'    => 3306,
    'name'    => 'inventory_live',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
];
