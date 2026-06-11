-- migration_20260611_140000_IST
-- Index inv_txns.ref_doc so the running-notes rollup join used by the
-- Ship & Receipt list (notes attached to a shipment's transactions) is
-- sargable. Without it, `t.ref_doc = CONCAT('OLD-ITX-', o.old_id)` forces a
-- full scan of old_inv_txns/inv_txns on every evaluation, hanging the list
-- page (~6s) and its Notes/Attachments filter. ref_doc is varchar(64), null.

ALTER TABLE `inv_txns`
    ADD KEY `ix_inv_txns_ref_doc` (`ref_doc`);
