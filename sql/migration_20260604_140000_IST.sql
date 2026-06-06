-- MagDyn — migration_20260604_140000_IST
-- Asset checkout due date. Captured when an asset is handed out
-- (send_user / send_vendor) and surfaced in the asset list, highlighted
-- when overdue.
--
--   asset_transactions.due_date   — the due date recorded for THAT checkout
--                                   event (immutable history).
--   assets.checkout_due_on        — the asset's CURRENT expected-return date;
--                                   set on checkout, cleared on check-in/move.
--
-- Idempotent + phpMyAdmin-safe. No SET @var usage.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_asset_checkout_due;
DELIMITER //
CREATE PROCEDURE magdyn_p_asset_checkout_due()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'asset_transactions'
                      AND COLUMN_NAME = 'due_date') THEN
        ALTER TABLE asset_transactions ADD COLUMN due_date DATE NULL AFTER notes;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'assets'
                      AND COLUMN_NAME = 'checkout_due_on') THEN
        ALTER TABLE assets ADD COLUMN checkout_due_on DATE NULL AFTER current_user_id;
    END IF;
END //
DELIMITER ;
CALL magdyn_p_asset_checkout_due();
DROP PROCEDURE magdyn_p_asset_checkout_due;
