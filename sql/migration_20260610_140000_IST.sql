-- MagDyn — migration_20260610_140000_IST
-- Old-inventory shipment/receipt import: distinguish a completed shipment
-- ('shipped') from a completed receipt ('received'). The inv_shipments.status
-- enum had no 'received' value (receipts were lumped into 'closed'), so the
-- import couldn't mark received receipts distinctly. Add 'received'.
--
-- Idempotent + phpMyAdmin-safe (guards on information_schema so re-running
-- is a no-op and the table is only rebuilt once).

SET NAMES utf8mb4;

SET @has_received := (
    SELECT LOCATE("'received'", COLUMN_TYPE)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'inv_shipments'
       AND COLUMN_NAME  = 'status'
);
SET @ddl := IF(@has_received = 0,
    "ALTER TABLE `inv_shipments`
        MODIFY COLUMN `status`
        ENUM('draft','approved','shipped','received','closed','cancelled')
        NOT NULL DEFAULT 'draft'",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
