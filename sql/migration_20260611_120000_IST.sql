-- migration_20260611_120000_IST
-- Record the old-inventory transaction_id (header) on each imported shipment/
-- receipt line, so the new shipment can be tied back to its legacy
-- `transaction` rows. This is what lets running notes that were attached to a
-- transaction (inv_notes.tid -> inventory_transaction -> transaction) surface
-- in the shipment/receipt detail view.
--
-- A combined Ship # (collapsed by S_Order No) spans MANY old header
-- transaction_ids, so the id is stored per line (line grain), not per header.
-- NULL for natively-created lines.

ALTER TABLE `inv_shipment_lines`
    ADD COLUMN `old_transaction_id` INT(10) UNSIGNED DEFAULT NULL
        COMMENT 'Legacy transaction.transaction_id this line was imported from (shipment/receipt.transaction_id). NULL for native lines.'
        AFTER `notes`;

ALTER TABLE `inv_shipment_lines`
    ADD KEY `ix_invsl_old_txn` (`old_transaction_id`);
