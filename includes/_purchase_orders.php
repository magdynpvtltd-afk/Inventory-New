<?php
/**
 * MagDyn — Purchase Orders helper (Phase C)
 *
 * One PO per shipment. Generated on save of the shipment header by
 * `po_ensure_for_shipment()` — idempotent: returns the existing PO
 * if one is already linked.
 *
 * Settings (T&C, blank-price system note) sourced from magdyn_settings.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_codes.php';

/**
 * Read a value from magdyn_settings, with a fallback default.
 */
function magdyn_setting($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = db_all("SELECT setting_key, setting_value FROM magdyn_settings");
            $cache = [];
            foreach ($rows as $r) $cache[$r['setting_key']] = $r['setting_value'];
        } catch (\Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Ensure a PO exists for the given shipment. Returns the PO row.
 * Idempotent — if a PO is already linked, returns it unchanged.
 *
 * Triggered from inventory_shiprcpt.php's save handler after the
 * shipment header insert/update commits.
 */
function po_ensure_for_shipment($shipmentId, $actorId = null)
{
    $shipmentId = (int)$shipmentId;
    if ($shipmentId <= 0) return null;

    $existing = db_one(
        "SELECT * FROM purchase_orders WHERE shipment_id = ? ORDER BY id DESC LIMIT 1",
        [$shipmentId]
    );
    if ($existing) return $existing;

    $sh = db_one("SELECT id, vendor_id FROM inv_shipments WHERE id = ?", [$shipmentId]);
    if (!$sh) return null;

    $poNo = code_next('po');
    db_exec(
        "INSERT INTO purchase_orders
            (po_no, shipment_id, vendor_id, version, po_date, created_by)
          VALUES (?, ?, ?, 1, ?, ?)",
        [$poNo, $shipmentId, (int)$sh['vendor_id'], date('Y-m-d'), $actorId ? (int)$actorId : null]
    );
    $id = (int)db()->lastInsertId();
    return db_one("SELECT * FROM purchase_orders WHERE id = ?", [$id]);
}

/**
 * Load a PO with the joined shipment header + line + vendor info
 * needed to render the print view.
 */
function po_load_full($poId)
{
    $poId = (int)$poId;
    $po = db_one("SELECT * FROM purchase_orders WHERE id = ?", [$poId]);
    if (!$po) return null;

    $shipment = db_one(
        "SELECT s.*, c.name AS courier_name
           FROM inv_shipments s
      LEFT JOIN shipping_couriers c ON c.id = s.courier_id
          WHERE s.id = ?",
        [(int)$po['shipment_id']]
    );
    $vendor = db_one("SELECT * FROM vendors WHERE id = ?", [(int)$po['vendor_id']]);

    // Pull primary contact / address for the print view.
    $primaryContact = db_one(
        "SELECT * FROM vendor_contacts WHERE vendor_id = ? AND is_primary = 1 LIMIT 1",
        [(int)$po['vendor_id']]
    );
    $primaryAddress = db_one(
        "SELECT * FROM vendor_addresses WHERE vendor_id = ? AND is_primary = 1 LIMIT 1",
        [(int)$po['vendor_id']]
    );

    // Lines — join inv_items for code/name, or fall back to pending_name
    // for not-yet-existing items, or assets table for asset lines.
    $lines = db_all(
        "SELECT l.*,
                i.code AS item_code, i.name AS item_name,
                a.asset_tag AS asset_tag,
                am.name AS asset_model,
                COALESCE(u.label, pu.label) AS uom_label
           FROM inv_shipment_lines l
      LEFT JOIN inv_items i  ON i.id = l.item_id
      LEFT JOIN assets    a  ON a.id = l.asset_id
      LEFT JOIN asset_models am ON am.id = a.model_id
      LEFT JOIN inv_uom   u  ON u.id = i.uom_id
      LEFT JOIN inv_uom   pu ON pu.id = l.pending_uom_id
          WHERE l.shipment_id = ?
          ORDER BY l.sort_order, l.id",
        [(int)$po['shipment_id']]
    );

    // Receive-side pricing rows (inv_shipment_receive_lines holds the
    // price, gst, expected_date in the existing schema).
    $receiveLines = [];
    try {
        $receiveLines = db_all(
            "SELECT * FROM inv_shipment_receive_lines WHERE shipment_id = ? ORDER BY sort_order, id",
            [(int)$po['shipment_id']]
        );
    } catch (\Throwable $e) {
        // Table may not exist on a partial migration; degrade silently.
    }

    return [
        'po'              => $po,
        'shipment'        => $shipment,
        'vendor'          => $vendor,
        'primary_contact' => $primaryContact,
        'primary_address' => $primaryAddress,
        'lines'           => $lines,
        'receive_lines'   => $receiveLines,
    ];
}

/**
 * Does any line on this shipment have a blank price? Drives the
 * conditional "Before raising Invoice, please share Proforma invoice."
 * system-note banner on the PO and the shipment edit page.
 */
function po_has_blank_priced_lines($shipmentId)
{
    try {
        $n = db_val(
            "SELECT COUNT(*)
               FROM inv_shipment_receive_lines
              WHERE shipment_id = ? AND (price IS NULL OR price = 0)",
            [(int)$shipmentId], 0
        );
        return (int)$n > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

// ============================================================
// PHASE D1 — Amendments / PO version chain
// ============================================================

/**
 * Return the latest (highest-version) PO for a given shipment, or
 * null if no PO has been generated yet. Used by view pages and the
 * amend flow to know what's "current".
 */
function po_latest_for_shipment($shipmentId)
{
    return db_one(
        "SELECT * FROM purchase_orders
          WHERE shipment_id = ?
          ORDER BY version DESC, id DESC
          LIMIT 1",
        [(int)$shipmentId]
    );
}

/**
 * Return the full version chain for a shipment, oldest first. One
 * row per amendment. Empty array if no PO yet.
 */
function po_version_chain($shipmentId)
{
    return db_all(
        "SELECT po.id, po.po_no, po.version, po.po_date, po.parent_po_id,
                po.created_at, u.full_name AS created_by_name
           FROM purchase_orders po
      LEFT JOIN users u ON u.id = po.created_by
          WHERE po.shipment_id = ?
          ORDER BY po.version ASC, po.id ASC",
        [(int)$shipmentId]
    );
}

/**
 * Create a new PO version for the given shipment. Reads the latest
 * version, increments it, inserts a fresh row with a new po_no and
 * parent_po_id pointing at the previous version.
 *
 * Use this from the shipment save handler when $isAmending is true.
 * For first-save / brand-new shipments, keep using
 * po_ensure_for_shipment() — it creates v1.
 *
 * Returns the newly-inserted PO row, or null on failure.
 */
function po_create_amendment_for_shipment($shipmentId, $actorId = null)
{
    $shipmentId = (int)$shipmentId;
    if ($shipmentId <= 0) return null;

    $latest = po_latest_for_shipment($shipmentId);
    if (!$latest) {
        // No previous PO — fall through to v1 creation. This shouldn't
        // happen in normal use (amend implies prior PO existed) but is
        // a safe fallback rather than failing loud.
        return po_ensure_for_shipment($shipmentId, $actorId);
    }

    $sh = db_one("SELECT id, vendor_id FROM inv_shipments WHERE id = ?", [$shipmentId]);
    if (!$sh) return null;

    $newVersion = (int)$latest['version'] + 1;
    $newPoNo    = code_next('po');

    db_exec(
        "INSERT INTO purchase_orders
            (po_no, shipment_id, vendor_id, version, parent_po_id, po_date, created_by)
          VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$newPoNo, $shipmentId, (int)$sh['vendor_id'],
         $newVersion, (int)$latest['id'], date('Y-m-d'),
         $actorId ? (int)$actorId : null]
    );
    $id = (int)db()->lastInsertId();
    return db_one("SELECT * FROM purchase_orders WHERE id = ?", [$id]);
}

/**
 * Friendly label for a PO in chain context: "PO-00042 (v3)".
 */
function po_label_with_version(array $po)
{
    return $po['po_no'] . ' (v' . (int)$po['version'] . ')';
}

