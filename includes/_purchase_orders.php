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

// ---------------------------------------------------------------
// Auto-migration: add lines_snapshot column if it doesn't exist.
// Runs once per PHP process via a static guard.
// ---------------------------------------------------------------
(static function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = db_all("SHOW COLUMNS FROM purchase_orders LIKE 'lines_snapshot'");
        if (empty($cols)) {
            db_exec("ALTER TABLE purchase_orders
                     ADD COLUMN lines_snapshot LONGTEXT NULL
                     COMMENT 'JSON snapshot of shipment lines+prices frozen at amendment time'
                     AFTER po_date");
        }
    } catch (\Throwable $e) {
        // Non-fatal — table might not exist yet on a fresh install.
    }
})();

/**
 * Ensure a PO exists for the given shipment. Returns the PO row.
 * Idempotent — if a PO is already linked, returns it unchanged.
 *
 * Triggered from inventory_shiprcpt.php's save handler after the
 * shipment header insert/update commits.
 *
 * $poNoOverride — when supplied (and non-empty), the PO is created with
 * this exact po_no instead of the auto-generated code_next('po') sequence.
 * Used by the old-inventory import so the system PO number matches the old
 * S_Order No. po_no is unique, so callers must ensure one PO per number
 * (the importer creates exactly one combined shipment per S_Order No).
 */
function po_ensure_for_shipment($shipmentId, $actorId = null, $poNoOverride = null)
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

    $poNo = ($poNoOverride !== null && trim((string)$poNoOverride) !== '')
        ? substr(trim((string)$poNoOverride), 0, 32)
        : code_next('po');
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

    // If this PO version has a frozen snapshot (set when it was superseded
    // by an amendment), serve that historical data instead of the live
    // shipment lines — so old versions always show what they looked like.
    if (!empty($po['lines_snapshot'])) {
        $snap         = json_decode($po['lines_snapshot'], true) ?: [];
        $lines        = $snap['lines']         ?? [];
        $receiveLines = $snap['receive_lines'] ?? [];
    } else {
        // Latest (or only) version — query live data as normal.
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

        $receiveLines = [];
        try {
            $receiveLines = db_all(
                "SELECT * FROM inv_shipment_receive_lines WHERE shipment_id = ? ORDER BY sort_order, id",
                [(int)$po['shipment_id']]
            );
        } catch (\Throwable $e) {
            // Table may not exist on a partial migration; degrade silently.
        }
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
               FROM inv_shipment_lines
              WHERE shipment_id = ? AND (unit_price IS NULL OR unit_price = 0)",
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
 * Create a new PO version for the given shipment — same po_no, version+1.
 * Freezes the BEFORE-state lines as a snapshot on the superseded row so
 * history views always show what that version looked like.
 *
 * $preComputedSnapshot — optional JSON string of lines/receive_lines captured
 * BEFORE the amendment's DB changes committed. When provided it is used as-is
 * for the historical snapshot (correct "before" state). When omitted the
 * function falls back to querying live data, which may show the post-amend
 * values — only acceptable for programmatic callers that don't need history.
 */
function po_create_amendment_for_shipment($shipmentId, $actorId = null, $preComputedSnapshot = null)
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

    // ── Freeze the BEFORE-state lines into the about-to-be-superseded
    //    PO row so that viewing that version later shows the OLD values.
    //    Prefer the caller-supplied pre-amend snapshot (taken before the
    //    transaction committed); fall back to a live query only when no
    //    snapshot was passed (e.g. programmatic calls). ─────────────────
    if ($preComputedSnapshot !== null) {
        $snapshot = $preComputedSnapshot;
    } else {
        $liveLines = db_all(
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
            [$shipmentId]
        );
        $liveReceiveLines = [];
        try {
            $liveReceiveLines = db_all(
                "SELECT * FROM inv_shipment_receive_lines WHERE shipment_id = ? ORDER BY sort_order, id",
                [$shipmentId]
            );
        } catch (\Throwable $e) { /* table may not exist yet */ }

        $snapshot = json_encode([
            'lines'          => $liveLines,
            'receive_lines'  => $liveReceiveLines,
            'snapshotted_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    }

    db_exec(
        "UPDATE purchase_orders SET lines_snapshot = ? WHERE id = ?",
        [$snapshot, (int)$latest['id']]
    );

    // ── Create the new version row — SAME po_no, just version + 1. ──
    $newVersion = (int)$latest['version'] + 1;
    $samePoNo   = $latest['po_no'];   // reuse, do NOT call code_next()

    db_exec(
        "INSERT INTO purchase_orders
            (po_no, shipment_id, vendor_id, version, parent_po_id, po_date, created_by)
          VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$samePoNo, $shipmentId, (int)$sh['vendor_id'],
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

