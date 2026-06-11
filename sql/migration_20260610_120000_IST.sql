-- MagDyn — migration_20260610_120000_IST
-- Old-inventory transaction import: split the single txn_date into two
-- distinct timestamps so the importer can record BOTH dates from the old
-- `transaction` table:
--   * recorded_date  ← transaction.creation_date  (when it was recorded)
--   * txn_date       ← transaction.modified_date  (when the event occurred,
--                       falling back to creation_date when modified_date is NULL)
--
-- Adds old_inv_txns.recorded_date. Idempotent + phpMyAdmin-safe (guards on
-- information_schema so re-running is a no-op).

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'old_inv_txns'
       AND COLUMN_NAME  = 'recorded_date'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `old_inv_txns` ADD COLUMN `recorded_date` DATETIME NULL DEFAULT NULL AFTER `txn_date`',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
