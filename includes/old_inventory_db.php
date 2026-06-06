<?php
/**
 * MagDyn — Old Inventory database connection helper.
 *
 * Returns a dedicated PDO instance for the legacy inventory_live database.
 * Intentionally separate from the main db() singleton so that writes to the
 * new system are never accidentally routed through this connection.
 *
 * Usage:
 *   $pdo = old_inventory_db();
 *   $rows = $pdo->prepare('SELECT ...');
 *
 * Throws PDOException on connection failure — callers should catch it.
 */
function old_inventory_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = require __DIR__ . '/../config/old_inventory_db.php';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$cfg['charset']}",
    ]);

    return $pdo;
}
