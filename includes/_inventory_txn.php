<?php
/**
 * MagDyn — shared inventory transaction helper.
 *
 * Hosts `inv_post_txn()` so any page that mutates stock writes through
 * a single code path: balance update + ledger row + denormalised total
 * sync. Wrapped in function_exists so inventory.php's earlier direct
 * declaration and any later include don't collide.
 *
 * Created: 20260515_180000_IST
 */

if (!function_exists('inv_post_txn')) {
    /**
     * Apply a stock delta to (item_id, location_id) and write a ledger row.
     *
     * - Positive delta: increment. Negative delta: decrement; rejects if it
     *   would push the balance below zero. Caller wraps multi-line ops in a
     *   DB transaction for all-or-nothing semantics.
     * - Returns ['txn_id' => N, 'qty_after' => X] so cascading callers can
     *   link child consumption rows via parent_txn_id.
     */
    function inv_post_txn($txnType, $txnDate, $itemId, $locationId, $delta, $parentTxnId = null, $refDoc = null, $notes = null, $isCorrection = false)
    {
        $itemId     = (int)$itemId;
        $locationId = (int)$locationId;
        $delta      = (float)$delta;

        $current = (float)db_val(
            'SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?',
            [$itemId, $locationId],
            0.0
        );
        $newQty = $current + $delta;
        if ($newQty < -0.0001) {  // tiny tolerance for float math
            $item = db_one('SELECT code, name FROM inv_items WHERE id = ?', [$itemId]);
            $loc  = db_one('SELECT code, name FROM locations WHERE id = ?', [$locationId]);
            throw new Exception(sprintf(
                'Insufficient stock for "%s" at "%s": have %s, need %s.',
                $item['name'] ?? '#' . $itemId,
                $loc['name']  ?? '#' . $locationId,
                number_format($current, 3),
                number_format(-$delta, 3)
            ));
        }

        db_exec(
            'INSERT INTO inv_item_location_stock (item_id, location_id, qty)
                  VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
            [$itemId, $locationId, $newQty]
        );

        // is_correction column was added by migration_20260515_203000_IST;
        // we INSERT through it conditionally so older installs without the
        // column still work.
        $hasCorrCol = (bool)db_one("SHOW COLUMNS FROM inv_txns LIKE 'is_correction'");
        if ($hasCorrCol) {
            db_exec(
                'INSERT INTO inv_txns
                    (txn_type, txn_date, item_id, location_id, qty_delta,
                     qty_after, parent_txn_id, ref_doc, notes, is_correction, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$txnType, $txnDate, $itemId, $locationId, $delta,
                 $newQty, $parentTxnId, $refDoc, $notes, $isCorrection ? 1 : 0, current_user_id()]
            );
        } else {
            db_exec(
                'INSERT INTO inv_txns
                    (txn_type, txn_date, item_id, location_id, qty_delta,
                     qty_after, parent_txn_id, ref_doc, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$txnType, $txnDate, $itemId, $locationId, $delta,
                 $newQty, $parentTxnId, $refDoc, $notes, current_user_id()]
            );
        }
        $txnId = (int)db()->lastInsertId();

        db_exec(
            'UPDATE inv_items
                SET stock_on_hand = (SELECT COALESCE(SUM(qty), 0)
                                       FROM inv_item_location_stock
                                      WHERE item_id = ?)
              WHERE id = ?',
            [$itemId, $itemId]
        );

        return ['txn_id' => $txnId, 'qty_after' => $newQty];
    }
}
