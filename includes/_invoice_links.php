<?php
/**
 * MagDyn — Invoice per-line linking helpers.
 * Created: 2026-05-24 23:18 IST
 *
 * Encapsulates the invoice_items ↔ inv_receipts / asset_transactions
 * link table (invoice_lines). After migration_20260524_231813,
 * invoice_lines.invoice_item_id is the parent key (not invoice_id).
 *
 * Concerns covered:
 *   - Code-match validation (asset_tag vs inv_items.code) so an
 *     invoice line for SKU-A can't be linked to a receipt of SKU-B.
 *   - "How much qty is linked" derived per invoice_item, per receipt,
 *     per asset_txn — used by the unlinked-qty displays.
 *   - "Is this receipt / asset_txn linked to ANY invoice?" — used by
 *     the hard-block on receipt edits.
 *
 * All helpers are pure (no side effects beyond the DB write in the
 * mutating ones). They throw RuntimeException on validation failure
 * so callers can catch and convert to flash messages.
 */

if (!function_exists('invoice_item_resolve_txn_code')) {
    /**
     * Resolve the item code that backs a given txn (asset_txn or
     * inv_receipt). Returns the canonical code string used by
     * invoice_items.item_code, or null if the txn can't be resolved.
     *
     * For asset_txn → assets.asset_tag.
     * For inv_receipt → inv_shipment_lines.item_id → inv_items.code.
     */
    function invoice_item_resolve_txn_code($kind, $txnId)
    {
        $txnId = (int)$txnId;
        if ($txnId <= 0) return null;
        if ($kind === 'asset') {
            return db_val(
                'SELECT a.asset_tag
                   FROM asset_transactions at
                   JOIN assets a ON a.id = at.asset_id
                  WHERE at.id = ?',
                [$txnId], null
            );
        }
        if ($kind === 'inv' || $kind === 'inv_item') {
            return db_val(
                'SELECT i.code
                   FROM inv_receipts r
                   JOIN inv_shipment_lines sl ON sl.id = r.shipment_line_id
                   JOIN inv_items i           ON i.id = sl.item_id
                  WHERE r.id = ?',
                [$txnId], null
            );
        }
        return null;
    }
}

if (!function_exists('invoice_item_validate_link')) {
    /**
     * Verify a proposed link is legal. Throws RuntimeException with a
     * user-readable reason on rejection; returns the normalized
     * (item_kind, target_code) tuple on success.
     *
     * Rules:
     *   1. invoice_item must exist.
     *   2. Link kind must match invoice_item.item_kind (asset → asset_txn,
     *      inv_item → inv_receipt).
     *   3. Underlying txn must exist + carry +qty (we don't link to
     *      voided receipts).
     *   4. Item code must match exactly (per user policy: strict match).
     *   5. linked_qty > 0 and ≤ remaining unlinked-on-txn AND
     *      ≤ remaining unlinked-on-invoice-item.
     *
     * Returns ['item_kind', 'invoice_item_id', 'link_kind', 'target_id',
     *          'qty', 'invoice_id'] ready to INSERT.
     */
    function invoice_item_validate_link($invoiceItemId, $linkKind, $targetId, $qty)
    {
        $invoiceItemId = (int)$invoiceItemId;
        $targetId      = (int)$targetId;
        $qty           = (float)$qty;
        $linkKind      = (string)$linkKind;

        $item = db_one(
            'SELECT * FROM invoice_items WHERE id = ?', [$invoiceItemId]
        );
        if (!$item) {
            throw new RuntimeException('Invoice line not found.');
        }
        $expectedKind = ($item['item_kind'] === 'asset') ? 'asset' : 'inv';
        if ($linkKind !== $expectedKind) {
            throw new RuntimeException(sprintf(
                'Wrong link kind: invoice line is %s but link is %s.',
                $item['item_kind'], $linkKind
            ));
        }
        if ($qty <= 0) {
            throw new RuntimeException('Linked quantity must be greater than zero.');
        }

        // Resolve & code-match.
        $txnCode = invoice_item_resolve_txn_code($linkKind, $targetId);
        if ($txnCode === null) {
            throw new RuntimeException('Target transaction not found or has no resolvable item code.');
        }
        if ((string)$txnCode !== (string)$item['item_code']) {
            throw new RuntimeException(sprintf(
                'Item code mismatch: invoice line is for "%s" but the transaction is for "%s". '
              . 'Linking is restricted to exact code matches.',
                $item['item_code'], $txnCode
            ));
        }

        // Remaining-qty checks. We compute both:
        //   - remaining on invoice line  = item.qty − SUM(linked)
        //   - remaining on target txn    = txn.qty  − SUM(linked across ALL invoices)
        $itemRemaining = invoice_item_qty_unlinked($invoiceItemId);
        if ($qty > $itemRemaining + 0.0001) {
            throw new RuntimeException(sprintf(
                'Over-link on invoice line: trying to link %s but only %s unlinked.',
                rtrim(rtrim(number_format($qty, 3), '0'), '.'),
                rtrim(rtrim(number_format($itemRemaining, 3), '0'), '.')
            ));
        }
        $txnRemaining = invoice_link_txn_qty_unlinked($linkKind, $targetId);
        if ($qty > $txnRemaining + 0.0001) {
            throw new RuntimeException(sprintf(
                'Over-link on transaction: trying to link %s but only %s unlinked on the txn.',
                rtrim(rtrim(number_format($qty, 3), '0'), '.'),
                rtrim(rtrim(number_format($txnRemaining, 3), '0'), '.')
            ));
        }

        return [
            'invoice_id'      => (int)$item['invoice_id'],
            'invoice_item_id' => $invoiceItemId,
            'link_kind'       => $linkKind,
            'target_id'       => $targetId,
            'qty'             => $qty,
            'item_kind'       => $item['item_kind'],
            'item_code'       => (string)$item['item_code'],
        ];
    }
}

if (!function_exists('invoice_item_link_create')) {
    /**
     * Create a link row after validating. Returns the new id.
     * Caller should wrap in a transaction if multiple links are
     * being inserted together.
     */
    function invoice_item_link_create($invoiceItemId, $linkKind, $targetId, $qty)
    {
        $v = invoice_item_validate_link($invoiceItemId, $linkKind, $targetId, $qty);
        $uid = function_exists('current_user_id') ? (int)current_user_id() : null;
        $col = ($v['link_kind'] === 'asset') ? 'asset_txn_id' : 'inv_receipt_id';
        db_exec(
            'INSERT INTO invoice_lines
               (invoice_item_id, link_kind, ' . $col . ', qty, created_by)
             VALUES (?, ?, ?, ?, ?)',
            [$v['invoice_item_id'], $v['link_kind'], $v['target_id'], $v['qty'], $uid]
        );
        return (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
    }
}

if (!function_exists('invoice_item_link_delete')) {
    /**
     * Delete a link row. Used by the linker page's per-row Remove
     * button. Returns true if a row was deleted.
     */
    function invoice_item_link_delete($linkId)
    {
        $linkId = (int)$linkId;
        if ($linkId <= 0) return false;
        db_exec('DELETE FROM invoice_lines WHERE id = ?', [$linkId]);
        return true;
    }
}

if (!function_exists('invoice_item_links')) {
    /**
     * List all link rows for a given invoice_item, joined to the
     * underlying receipt or asset_txn so the linker page can display
     * a readable summary (date, ref, qty, who created it).
     *
     * Returns rows shaped:
     *   ['id', 'link_kind', 'qty', 'created_at', 'created_by_name',
     *    'target_id', 'target_label', 'target_date', 'target_qty']
     *
     * target_label: receipt_no (inv) or asset_tag + txn_type (asset)
     */
    function invoice_item_links($invoiceItemId)
    {
        return db_all(
            "SELECT il.id, il.link_kind, il.qty,
                    il.created_at, u.full_name AS created_by_name,
                    CASE il.link_kind
                        WHEN 'asset' THEN at.id
                        WHEN 'inv'   THEN r.id
                    END AS target_id,
                    CASE il.link_kind
                        WHEN 'asset' THEN CONCAT(a.asset_tag, ' · ', at.txn_type)
                        WHEN 'inv'   THEN r.receipt_no
                    END AS target_label,
                    CASE il.link_kind
                        WHEN 'asset' THEN DATE(at.at)
                        WHEN 'inv'   THEN r.receipt_date
                    END AS target_date,
                    CASE il.link_kind
                        WHEN 'asset' THEN 1
                        WHEN 'inv'   THEN r.qty_received
                    END AS target_qty
               FROM invoice_lines il
          LEFT JOIN users u                 ON u.id = il.created_by
          LEFT JOIN asset_transactions at   ON at.id = il.asset_txn_id
          LEFT JOIN assets a                ON a.id = at.asset_id
          LEFT JOIN inv_receipts r          ON r.id = il.inv_receipt_id
              WHERE il.invoice_item_id = ?
              ORDER BY il.id",
            [(int)$invoiceItemId]
        );
    }
}

if (!function_exists('invoice_item_qty_linked')) {
    /**
     * Total qty linked to a given invoice_item (across all its
     * link rows).
     */
    function invoice_item_qty_linked($invoiceItemId)
    {
        return (float)db_val(
            'SELECT COALESCE(SUM(qty), 0) FROM invoice_lines WHERE invoice_item_id = ?',
            [(int)$invoiceItemId], 0.0
        );
    }
}

if (!function_exists('invoice_item_qty_unlinked')) {
    /**
     * invoice_item.qty − qty_linked, clamped to ≥ 0. (Over-link is
     * blocked at insert time but a clamp here defends against any
     * out-of-band data correction the user might do.)
     */
    function invoice_item_qty_unlinked($invoiceItemId)
    {
        $row = db_one(
            'SELECT qty FROM invoice_items WHERE id = ?', [(int)$invoiceItemId]
        );
        if (!$row) return 0.0;
        $remaining = (float)$row['qty'] - invoice_item_qty_linked($invoiceItemId);
        return $remaining > 0 ? $remaining : 0.0;
    }
}

if (!function_exists('invoice_link_txn_qty_linked')) {
    /**
     * Total qty linked TO a given transaction (asset_txn or
     * inv_receipt), summed across however many invoices reference
     * it. Used for the unlinked-on-txn displays.
     */
    function invoice_link_txn_qty_linked($kind, $txnId)
    {
        $kind  = (string)$kind;
        $txnId = (int)$txnId;
        $col   = ($kind === 'asset') ? 'asset_txn_id' : 'inv_receipt_id';
        return (float)db_val(
            "SELECT COALESCE(SUM(qty), 0) FROM invoice_lines WHERE $col = ?",
            [$txnId], 0.0
        );
    }
}

if (!function_exists('invoice_link_txn_qty_unlinked')) {
    /**
     * For an inv_receipt: qty_received − linked. For an asset_txn:
     * 1 − linked (asset txns are 1-unit by definition). Clamped ≥ 0.
     */
    function invoice_link_txn_qty_unlinked($kind, $txnId)
    {
        $kind  = (string)$kind;
        $txnId = (int)$txnId;
        if ($kind === 'asset') {
            // Asset txns always represent 1 unit.
            $linked = invoice_link_txn_qty_linked('asset', $txnId);
            return max(0.0, 1.0 - $linked);
        }
        $rcv = (float)db_val(
            'SELECT qty_received FROM inv_receipts WHERE id = ?', [$txnId], 0.0
        );
        $linked = invoice_link_txn_qty_linked('inv', $txnId);
        return max(0.0, $rcv - $linked);
    }
}

if (!function_exists('invoice_link_inv_receipt_is_linked')) {
    /**
     * True if a given inv_receipt has ANY invoice link rows. Used
     * by the hard-block on receipt edits / deletes — when a receipt
     * is linked, edits are refused with a message pointing at the
     * invoice(s) that need to be unlinked first.
     *
     * Returns the list of invoice ids holding links (for the error
     * message), or an empty array if none.
     */
    function invoice_link_inv_receipt_linked_invoices($receiptId)
    {
        $rows = db_all(
            'SELECT DISTINCT ii.invoice_id, i.invoice_no
               FROM invoice_lines il
               JOIN invoice_items ii ON ii.id = il.invoice_item_id
               JOIN invoices i       ON i.id  = ii.invoice_id
              WHERE il.inv_receipt_id = ?
              ORDER BY i.invoice_no',
            [(int)$receiptId]
        );
        return $rows;
    }
}

if (!function_exists('invoice_link_asset_txn_linked_invoices')) {
    /**
     * Mirror of the receipt helper for asset transactions. Returns
     * the list of invoices currently linked to a given asset_txn.
     */
    function invoice_link_asset_txn_linked_invoices($assetTxnId)
    {
        return db_all(
            'SELECT DISTINCT ii.invoice_id, i.invoice_no
               FROM invoice_lines il
               JOIN invoice_items ii ON ii.id = il.invoice_item_id
               JOIN invoices i       ON i.id  = ii.invoice_id
              WHERE il.asset_txn_id = ?
              ORDER BY i.invoice_no',
            [(int)$assetTxnId]
        );
    }
}

if (!function_exists('invoice_link_format_invoice_list')) {
    /**
     * Render a comma-separated list of invoice numbers for use in a
     * flash error message when an edit is hard-blocked.
     *
     *   ['INV-2026-001', 'INV-2026-003'] → "INV-2026-001, INV-2026-003"
     *
     * Caller passes the rows from the *_linked_invoices() helpers.
     */
    function invoice_link_format_invoice_list($rows)
    {
        if (!$rows) return '';
        $nos = [];
        foreach ($rows as $r) $nos[] = (string)$r['invoice_no'];
        return implode(', ', $nos);
    }
}
