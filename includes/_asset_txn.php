<?php
/**
 * MagDyn — Asset transaction helper (Phase C extraction)
 *
 * Records a row in asset_transactions and updates the asset's
 * location / current vendor / current user / status atomically.
 *
 * This was previously inlined inside asset.php's txn_save handler.
 * Phase C lifts it out so the Ship/Receipt module can record
 * send_vendor / receive_vendor transactions automatically when
 * asset lines are shipped or received.
 *
 * Returns the inserted asset_transactions row id, or 0 on failure.
 */

require_once __DIR__ . '/db.php';

/**
 * @param int    $assetId   The asset to transact on
 * @param string $type      One of: move, send_vendor, receive_vendor,
 *                          send_user, receive_user
 * @param array  $params    Keyed by:
 *                            to_location_id, to_vendor_id, to_user_id
 *                          Only the relevant ones for $type need be set.
 * @param int    $actorId   The user recording the transaction
 * @param string $notes     Free-text annotation
 *
 * @return int Inserted asset_transactions row id (0 on failure)
 */
function asset_txn_record($assetId, $type, array $params, $actorId = null, $notes = '')
{
    $assetId = (int)$assetId;
    if ($assetId <= 0) return 0;
    $valid = ['move', 'send_vendor', 'receive_vendor', 'send_user', 'receive_user'];
    if (!in_array($type, $valid, true)) return 0;

    $a = db_one("SELECT * FROM assets WHERE id = ?", [$assetId]);
    if (!$a) return 0;

    $from_loc    = $a['location_id'];
    $from_vendor = $a['current_vendor_id'] ?? null;
    $from_user   = $a['current_user_id'] ?? null;

    $to_loc = $to_vendor = $to_user = null;
    $newLocation = $a['location_id'];
    $newVendor   = null;
    $newUser     = null;
    $newStatus   = 'active';

    switch ($type) {
        case 'move':
            $to_loc = (int)($params['to_location_id'] ?? 0) ?: null;
            if (!$to_loc) return 0;
            $newLocation = $to_loc;
            break;
        case 'send_vendor':
            $to_vendor = (int)($params['to_vendor_id'] ?? 0) ?: null;
            if (!$to_vendor) return 0;
            $newVendor = $to_vendor;
            $newStatus = 'with_vendor';
            $newLocation = null;
            break;
        case 'receive_vendor':
            $to_loc = (int)($params['to_location_id'] ?? 0) ?: null;
            if (!$to_loc) return 0;
            $newLocation = $to_loc;
            $newVendor = null;
            break;
        case 'send_user':
            $to_user = (int)($params['to_user_id'] ?? 0) ?: null;
            if (!$to_user) return 0;
            $newUser = $to_user;
            $newStatus = 'with_user';
            $newLocation = null;
            break;
        case 'receive_user':
            $to_loc = (int)($params['to_location_id'] ?? 0) ?: null;
            if (!$to_loc) return 0;
            $newLocation = $to_loc;
            $newUser = null;
            break;
    }

    db_exec(
        "INSERT INTO asset_transactions
            (asset_id, txn_type, from_location_id, from_vendor_id, from_user_id,
             to_location_id, to_vendor_id, to_user_id, actor_id, notes)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$assetId, $type, $from_loc, $from_vendor, $from_user,
         $to_loc, $to_vendor, $to_user, $actorId ? (int)$actorId : null, (string)$notes]
    );
    $txnId = (int)db()->lastInsertId();

    db_exec(
        "UPDATE assets SET location_id = ?, current_vendor_id = ?, current_user_id = ?, status = ? WHERE id = ?",
        [$newLocation, $newVendor, $newUser, $newStatus, $assetId]
    );
    return $txnId;
}
