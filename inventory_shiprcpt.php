<?php
/**
 * MagDyn — Inventory: Ship & Receipt (v2)
 * Rewritten: 20260517_233000_IST
 *
 * New flow:
 *   1. CREATE. User picks a mode: 'receive' (incoming only), 'ship'
 *      (outgoing only), or 'both' (the classic process / rework cycle).
 *      Header carries vendor. Line items live in
 *      inv_shipment_lines, each tagged 'ship' or 'receive'. SOURCE
 *      LOCATION is per-line (only required on 'ship' lines — where
 *      the item is drawn from). NO destination location on the
 *      header — destination is captured at receipt time.
 *
 *   2. APPROVE. Same gate as the invoice flow. After approval the
 *      ship/receive event handlers unlock.
 *
 *   3. SHIP (one-shot). For 'ship' or 'both' modes. Posts a single
 *      ship_out txn per ship-line, deducts stock from each line's
 *      src_location_id, flips status to 'shipped'.
 *
 *   4. RECEIVE (multi-event, partial allowed). For 'receive' or
 *      'both' modes. Each receipt event is one inv_receipts row +
 *      one ship_in txn, capturing receipt_date, due_date_snapshot
 *      (for vendor-perf analytics), and the destination location
 *      the user picks at that moment.
 *
 *   5. CLOSE. Manual button when the user considers the shipment
 *      done — or implicit: when every receive-line's qty_received
 *      hits qty_planned, the UI surfaces a "fully received" pill
 *      and the close-out is one click.
 *
 * Status transitions:
 *   draft   → approved   (via Approve)
 *   approved → shipped   (via Ship — only if mode != 'receive')
 *   any of {approved, shipped} → closed   (via Close)
 *   any of {draft, approved} → cancelled  (via Cancel)
 *
 * Receipts can be recorded as soon as status is 'approved' (or
 * 'shipped' for 'both' mode). The system doesn't hard-block early
 * receipts because shipped/received timelines often overlap with
 * vendor lead-time realities.
 *
 * Behaviors deferred from the v1 module (not built here): BOM
 * auto-populate of receive lines from the ship-side items'
 * sub-assembly definition, vendor-cascading data refresh on edit,
 * scrap/correction txns when editing posted ship lines. These can
 * be added back as separate features.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('inventory_shiprcpt', 'view');
require_once __DIR__ . '/includes/datatable.php';
require_once __DIR__ . '/includes/_inventory_txn.php';
require_once __DIR__ . '/includes/_codes.php';
require_once __DIR__ . '/includes/_qc.php';
require_once __DIR__ . '/includes/_purchase_orders.php';   // Phase C — auto-PO on save
require_once __DIR__ . '/includes/_asset_txn.php';         // Phase C — asset send/receive on ship/receive
require_once __DIR__ . '/includes/_invoice_links.php';

$action    = (string)input('action', 'index');
$canManage = permission_check('inventory_shiprcpt', 'manage');

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

function shr_modes_with_ship()    { return ['ship', 'both']; }
function shr_modes_with_receive() { return ['receive', 'both']; }

/** Editable: draft only (line items still mutable). Once approved
 *  the header + line items become read-only for everything except
 *  the structured ship/receive event actions. */
function shr_is_editable($status) { return $status === 'draft'; }

/** Generate the next ship_no. Delegates to the central code_next()
 *  helper which reads format settings from the code_sequences admin
 *  table. The legacy hardcoded "SH-YYMMDD-N" format is the default
 *  seed; admins can change the prefix or pad via the Code Sequences
 *  admin page. */
function shr_next_ship_no()
{
    return code_next('shipment');
}

function shr_next_receipt_no()
{
    return code_next('receipt');
}

/** Convenience: refresh a shipment line's qty_received aggregate from
 *  the sum of its inv_receipts. Called after receipt insert/delete. */
function shr_recompute_line_received($lineId)
{
    db_exec(
        'UPDATE inv_shipment_lines
            SET qty_received = (
                SELECT COALESCE(SUM(qty_received), 0)
                  FROM inv_receipts WHERE shipment_line_id = ?
            )
          WHERE id = ?',
        [(int)$lineId, (int)$lineId]
    );
}

/** Item picker options — used in the line-item form. Mirrors the
 *  invoice picker pattern (active items only, capped). */
function shr_item_picker_options()
{
    return db_all(
        'SELECT id, code,
                CONCAT(code, " — ", COALESCE(NULLIF(short_description, ""), name)) AS label,
                uom
           FROM inv_items
          WHERE is_active = 1
          ORDER BY code
          LIMIT 2000'
    );
}

// ----------------------------------------------------------------
// SHIPMENT ↔ RUNNING-NOTES ROLLUP
// ----------------------------------------------------------------
// A running note "belongs" to a shipment when it was attached (in the
// old system) to one of the transactions behind the shipment's lines.
// Chain:
//   notes(entity_type='inv_txn', entity_id = inv_txns.id)
//     → inv_txns.ref_doc = 'OLD-ITX-<old_id>'
//     → old_inv_txns.old_id → old_inv_txns.old_transaction_id
//     → inv_shipment_lines.old_transaction_id
//
// PERF: these queries drive from `notes` (the small end — only the
// inv_txn-class rows) DOWN to inv_shipment_lines, reaching old_inv_txns
// via its UNIQUE old_id index. We deliberately DO NOT join the other
// direction with `t.ref_doc = CONCAT('OLD-ITX-', o.old_id)` as the driving
// predicate: per-row that re-derives the string and (combined with the
// event-union derived table) full-scans old_inv_txns — the ~6s hang that
// motivated this. The `o.old_id = CAST(SUBSTRING(ref_doc,9) AS UNSIGNED)`
// form keeps old_id sargable. (inv_txns.ref_doc is also indexed now —
// see migration_20260611_140000_IST.sql.)
// ----------------------------------------------------------------

/** Shared FROM/WHERE for the notes→shipment rollup, driven from `notes`.
 *  Callers append their own shipment filter + projection. */
function _shr_txn_notes_join()
{
    return "FROM notes n
            JOIN inv_txns t            ON t.id = n.entity_id
            JOIN old_inv_txns o        ON o.old_id = CAST(SUBSTRING(t.ref_doc, 9) AS UNSIGNED)
            JOIN inv_shipment_lines sl ON sl.old_transaction_id = o.old_transaction_id
           WHERE n.entity_type = 'inv_txn' AND n.is_deleted = 0
             AND t.ref_doc LIKE 'OLD-ITX-%'";
}

/** Batched note counts for a set of shipments. Returns
 *  [shipment_id => count]; shipments with no notes are absent. */
function shr_shipment_txn_note_counts(array $shipmentIds)
{
    $ids = array_filter(array_unique(array_map('intval', $shipmentIds)), function ($v) { return $v > 0; });
    if (empty($ids)) return [];
    $in = implode(',', $ids);
    $rows = db_all(
        "SELECT sl.shipment_id, COUNT(DISTINCT n.id) AS c
         " . _shr_txn_notes_join() . "
           AND sl.shipment_id IN ($in)
         GROUP BY sl.shipment_id"
    );
    $out = [];
    foreach ($rows as $r) $out[(int)$r['shipment_id']] = (int)$r['c'];
    return $out;
}

/** Distinct shipment ids that have at least one rolled-up running note.
 *  Run ONCE per request to seed the list filter (a literal IN-list keeps
 *  the per-row Available/Not-available filter a cheap indexed compare —
 *  never a correlated subquery). Returns a flat array of ints. */
function shr_shipment_ids_with_txn_notes()
{
    $rows = db_all("SELECT DISTINCT sl.shipment_id " . _shr_txn_notes_join());
    return array_map(function ($r) { return (int)$r['shipment_id']; }, $rows);
}

/** Full list of running notes rolled up to one shipment, newest first.
 *  Each note carries its attachments as ['id'=>.., 'filename'=>..]. */
function shr_shipment_txn_notes($shipmentId)
{
    $shipmentId = (int)$shipmentId;
    $notes = db_all(
        "SELECT DISTINCT n.id, n.body_html, n.created_at, n.entity_id AS inv_txn_id,
                o.old_transaction_id,
                u.full_name AS author_name, u.email AS author_email,
                c.name AS note_type_name
           FROM notes n
           JOIN inv_txns t            ON t.id = n.entity_id
           JOIN old_inv_txns o        ON o.old_id = CAST(SUBSTRING(t.ref_doc, 9) AS UNSIGNED)
           JOIN inv_shipment_lines sl ON sl.old_transaction_id = o.old_transaction_id
      LEFT JOIN users u               ON u.id = n.author_id
      LEFT JOIN categories c          ON c.id = n.note_type_id
          WHERE n.entity_type = 'inv_txn' AND n.is_deleted = 0
            AND t.ref_doc LIKE 'OLD-ITX-%'
            AND sl.shipment_id = ?
          ORDER BY n.created_at DESC, n.id DESC",
        [$shipmentId]
    );
    $attByNote = [];
    if ($notes) {
        $in = implode(',', array_map('intval', array_column($notes, 'id')));
        foreach (db_all("SELECT id, note_id, filename FROM note_attachments WHERE note_id IN ($in) ORDER BY note_id, id") as $a) {
            $attByNote[(int)$a['note_id']][] = ['id' => (int)$a['id'], 'filename' => (string)$a['filename']];
        }
    }
    foreach ($notes as &$n) {
        $n['attachments'] = $attByNote[(int)$n['id']] ?? [];
    }
    unset($n);
    return $notes;
}

/** Emit the clip-icon popup CSS + JS once. The popup fetches the note
 *  list from ?action=txn_notes and renders each note (author, date,
 *  type, body, attachment links). Click handler binds on
 *  .shr-notes-indicator buttons rendered in the list rows. */
function shr_txn_notes_popup_assets()
{
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
        .shr-notes-indicator { background:none;border:none;padding:0;cursor:pointer;font:inherit;line-height:1;white-space:nowrap;color:#1d4ed8; }
        .shr-notes-indicator:hover { text-decoration:underline; }
        .shr-notes-badge { font-size:11px;font-weight:700; }
        #shrnotes-backdrop { position:fixed;inset:0;z-index:99998;display:none;background:rgba(0,0,0,.35); }
        #shrnotes-pop {
            position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);
            z-index:99999;display:none;background:#fff;color:#111827;
            border:1px solid #d1d5db;border-radius:10px;box-shadow:0 18px 50px rgba(0,0,0,.30);
            width:min(560px,94vw);max-height:78vh;overflow:auto;
        }
        #shrnotes-pop .shrnotes-head {
            display:flex;align-items:center;justify-content:space-between;
            padding:12px 14px;border-bottom:1px solid #eef0f3;font-size:13px;font-weight:700;
            position:sticky;top:0;background:#fff;
        }
        #shrnotes-pop .shrnotes-close { background:none;border:none;font-size:18px;line-height:1;cursor:pointer;color:#6b7280;padding:0 2px; }
        #shrnotes-pop .shrnotes-body { padding:10px 12px; }
        #shrnotes-pop .shrnote { border:1px solid #eef0f3;border-radius:8px;padding:10px 12px;margin-bottom:10px;background:#fafafa; }
        #shrnotes-pop .shrnote-meta { font-size:12px;color:#6b7280;margin-bottom:6px;display:flex;gap:8px;flex-wrap:wrap;align-items:center; }
        #shrnotes-pop .shrnote-author { font-weight:700;color:#111827; }
        #shrnotes-pop .shrnote-type { background:#dbeafe;color:#1e40af;border-radius:10px;padding:1px 8px;font-size:11px; }
        #shrnotes-pop .shrnote-body { font-size:13.5px;color:#111827;line-height:1.5; }
        #shrnotes-pop .shrnote-atts { margin-top:8px;display:flex;flex-direction:column;gap:4px; }
        #shrnotes-pop .shrnote-att { display:inline-flex;align-items:center;gap:6px;color:#1d4ed8;text-decoration:none;font-size:13px; }
        #shrnotes-pop .shrnote-att:hover { text-decoration:underline; }
        #shrnotes-pop .shrnotes-empty { padding:14px;color:#6b7280;font-size:13px; }
    </style>
    <script>
    (function () {
        if (window.__shrNotesBound) return;
        window.__shrNotesBound = true;
        var base = (window.MAGDYN_BASE || '');

        var backdrop = document.getElementById('shrnotes-backdrop');
        if (!backdrop) { backdrop = document.createElement('div'); backdrop.id = 'shrnotes-backdrop'; document.body.appendChild(backdrop); }
        var pop = document.getElementById('shrnotes-pop');
        if (!pop) { pop = document.createElement('div'); pop.id = 'shrnotes-pop'; document.body.appendChild(pop); }

        function hide() { pop.style.display = 'none'; backdrop.style.display = 'none'; pop.innerHTML = ''; }
        function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

        function render(shipNo, list) {
            pop.innerHTML = '';
            var head = document.createElement('div');
            head.className = 'shrnotes-head';
            var label = document.createElement('span');
            label.textContent = '📎 Notes — ' + (shipNo || '') + ' (' + list.length + ')';
            var x = document.createElement('button');
            x.type = 'button'; x.className = 'shrnotes-close'; x.textContent = '✕';
            x.addEventListener('click', hide);
            head.appendChild(label); head.appendChild(x);
            pop.appendChild(head);

            var body = document.createElement('div');
            body.className = 'shrnotes-body';
            if (!list.length) {
                var em = document.createElement('div');
                em.className = 'shrnotes-empty';
                em.textContent = 'No notes found.';
                body.appendChild(em);
            } else {
                list.forEach(function (n) {
                    var card = document.createElement('div'); card.className = 'shrnote';
                    var meta = document.createElement('div'); meta.className = 'shrnote-meta';
                    var html = '<span class="shrnote-author">' + esc(n.author) + '</span>'
                             + '<span>' + esc(n.created_at) + '</span>';
                    if (n.note_type) html += '<span class="shrnote-type">' + esc(n.note_type) + '</span>';
                    if (n.old_txn)   html += '<span title="Legacy transaction id">Txn #' + esc(n.old_txn) + '</span>';
                    meta.innerHTML = html;
                    card.appendChild(meta);
                    var b = document.createElement('div'); b.className = 'shrnote-body';
                    b.innerHTML = n.body_html || '';   // sanitized server-side at save time
                    card.appendChild(b);
                    if (n.attachments && n.attachments.length) {
                        var atts = document.createElement('div'); atts.className = 'shrnote-atts';
                        n.attachments.forEach(function (a) {
                            var link = document.createElement('a');
                            link.className = 'shrnote-att';
                            link.href = base + '/note_attach.php?id=' + a.id;
                            link.target = '_blank'; link.rel = 'noopener';
                            link.title = a.filename;
                            link.textContent = '📎 ' + a.filename;
                            atts.appendChild(link);
                        });
                        card.appendChild(atts);
                    }
                    body.appendChild(card);
                });
            }
            pop.appendChild(body);
            backdrop.style.display = 'block';
            pop.style.display = 'block';
        }

        // Capture phase so the row's own click handlers don't swallow it.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.shr-notes-indicator');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var sid    = btn.getAttribute('data-shipment-id');
            var shipNo = btn.getAttribute('data-ship-no') || '';
            render(shipNo, []);
            pop.querySelector('.shrnotes-body').innerHTML = '<div class="shrnotes-empty">Loading…</div>';
            var url = base + '/inventory_shiprcpt.php?action=txn_notes&id=' + encodeURIComponent(sid);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (list) { render(shipNo, Array.isArray(list) ? list : []); })
                .catch(function () {
                    pop.querySelector('.shrnotes-body').innerHTML =
                        '<div class="shrnotes-empty">Could not load notes.</div>';
                });
        }, true);

        backdrop.addEventListener('click', hide);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    })();
    </script>
    <?php
}

// ----------------------------------------------------------------
// VENDOR DATA — JSON endpoint that returns a vendor's contacts +
// addresses so the new/edit form can cascade-update its dropdowns
// after the user picks a vendor. Read-only; minimal data.
// ----------------------------------------------------------------
if ($action === 'vendor_data') {
    header('Content-Type: application/json; charset=utf-8');
    $vendorId = (int)input('vendor_id', 0);
    if ($vendorId <= 0) {
        echo json_encode(['contacts' => [], 'addresses' => []]);
        exit;
    }
    $contacts = db_all(
        'SELECT id, name, designation, email, phone, is_primary
           FROM vendor_contacts WHERE vendor_id = ?
          ORDER BY is_primary DESC, sort_order, name',
        [$vendorId]
    );
    $addresses = db_all(
        'SELECT id, label, line1, line2, city, state, pincode, country, is_primary
           FROM vendor_addresses WHERE vendor_id = ?
          ORDER BY is_primary DESC, sort_order, id',
        [$vendorId]
    );
    echo json_encode(['contacts' => $contacts, 'addresses' => $addresses]);
    exit;
}

// ----------------------------------------------------------------
// TXN_NOTES — JSON list of running notes rolled up to one shipment.
// Used by the list page's "Notes/Attachments" clip-icon popup. The
// page-level view permission (required at the top of this file) gates
// access. Read-only.
// ----------------------------------------------------------------
if ($action === 'txn_notes') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)input('id', 0);
    if ($id <= 0) { echo json_encode([]); exit; }
    $notes = shr_shipment_txn_notes($id);
    $out = array_map(function ($n) {
        return [
            'id'          => (int)$n['id'],
            'body_html'   => (string)$n['body_html'],
            'created_at'  => (string)$n['created_at'],
            'author'      => (string)($n['author_name'] ?: $n['author_email'] ?: '—'),
            'note_type'   => (string)($n['note_type_name'] ?: ''),
            'old_txn'     => (int)$n['old_transaction_id'],
            'attachments' => array_map(function ($a) {
                return ['id' => (int)$a['id'], 'filename' => (string)$a['filename']];
            }, $n['attachments']),
        ];
    }, $notes);
    echo json_encode($out);
    exit;
}

// ----------------------------------------------------------------
// BOM POPULATE — add ship lines for a chosen sub-assembly's BOM
// children, plus a receive line for the sub-assembly itself.
// Draft-only. The user picks an inv_items.id + a multiplier qty;
// for each BOM child we add a 'ship' line at (child.qty * multiplier),
// and append one 'receive' line for the parent at the multiplier qty.
// This restores the v1 rework-cycle convenience: pick the sub-assy
// you want made, get the raw-mat ship lines auto-populated.
// ----------------------------------------------------------------
if ($action === 'populate_from_bom') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id        = (int)input('id', 0);
    $itemId    = (int)input('bom_item_id', 0);
    $mult      = (float)input('bom_multiplier', 1);
    $defaultSrcLocId = (int)input('bom_default_src_location_id', 0);

    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    if (!shr_is_editable($sh['status'])) {
        flash_set('error', 'Can only populate lines on a draft shipment.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    if ($itemId <= 0 || $mult <= 0) {
        flash_set('error', 'Pick an item and a positive multiplier.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }

    $children = db_all(
        'SELECT bl.child_item_id, bl.qty AS line_qty, bl.sort_order, i.uom
           FROM inv_bom_lines bl
           JOIN inv_items i ON i.id = bl.child_item_id
          WHERE bl.parent_item_id = ?
          ORDER BY bl.sort_order, bl.id',
        [$itemId]
    );
    if (empty($children)) {
        flash_set('error', 'Picked item has no BOM. Define BOM children first or add lines manually.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }

    // Next sort_order to append after existing lines.
    $nextSort = (int)db_val(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM inv_shipment_lines WHERE shipment_id = ?',
        [$id], 0
    );
    try {
        db()->beginTransaction();

        // 1. Ship lines for the BOM children (only meaningful for
        //    ship or both modes; for receive-only we still add them
        //    but they'll be filtered out at save — defensive against
        //    user changing mode after populate.).
        if (in_array($sh['mode'], shr_modes_with_ship(), true)) {
            foreach ($children as $c) {
                $required = (float)$c['line_qty'] * $mult;
                db_exec(
                    'INSERT INTO inv_shipment_lines
                       (shipment_id, sort_order, line_kind, item_id, qty_planned, src_location_id)
                     VALUES (?, ?, "ship", ?, ?, ?)',
                    [$id, $nextSort++, (int)$c['child_item_id'], $required,
                     $defaultSrcLocId > 0 ? $defaultSrcLocId : null]
                );
            }
        }

        // 2. Receive line for the sub-assembly itself (only for receive
        //    or both modes).
        if (in_array($sh['mode'], shr_modes_with_receive(), true)) {
            db_exec(
                'INSERT INTO inv_shipment_lines
                   (shipment_id, sort_order, line_kind, item_id, qty_planned, src_location_id)
                 VALUES (?, ?, "receive", ?, ?, NULL)',
                [$id, $nextSort++, $itemId, $mult]
            );
        }
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Could not populate from BOM: ' . $e->getMessage());
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    flash_set('success', 'Lines added from BOM. Review and edit before approval.');
    redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
}

// ----------------------------------------------------------------
// SUGGEST_RECEIVE_LINES — scan ship lines, infer candidate parents
// ----------------------------------------------------------------
// Inverse of populate_from_bom. Given the current ship lines on a
// draft shipment, scan inv_bom_lines for parents whose children appear
// in the ship lines, compute the implied parent qty (limited by the
// least-covered child), and present the candidates for the user to
// pick from. Doesn't add lines directly — the user confirms via
// add_receive_lines below.
//
// The implied qty formula: for a candidate parent P with BOM children
// (c1×q1, c2×q2, ...), and ship lines that include some of those
// children at qtys (s_c1, s_c2, ...), implied_qty = min(s_ci / qi)
// across children present in BOTH the BOM and the ship lines.
// Children that aren't in the ship lines reduce coverage but don't
// zero out the suggestion — partial coverage is reported as "X of Y
// children covered" so the user can decide if the suggestion makes
// sense for their case.
// ----------------------------------------------------------------
function shr_compute_receive_candidates($shipmentId) {
    $shipmentId = (int)$shipmentId;

    // Active ship lines, grouped by item id (sum qty in case the same
    // item appears multiple times).
    $shipLines = db_all(
        "SELECT item_id, SUM(qty_planned) AS total_qty
           FROM inv_shipment_lines
          WHERE shipment_id = ? AND line_kind = 'ship'
            AND item_id IS NOT NULL
          GROUP BY item_id",
        [$shipmentId]
    );
    if (empty($shipLines)) return [];
    $shipQtyByItem = [];
    foreach ($shipLines as $sl) {
        $shipQtyByItem[(int)$sl['item_id']] = (float)$sl['total_qty'];
    }
    $childItemIds = array_keys($shipQtyByItem);
    $placeholders = implode(',', array_fill(0, count($childItemIds), '?'));

    // Find all distinct parent items that have at least one of our
    // ship-line items as a BOM child.
    $candidateParents = db_all(
        "SELECT DISTINCT bl.parent_item_id, i.code AS parent_code, i.name AS parent_name,
                i.short_description AS parent_short_desc
           FROM inv_bom_lines bl
           JOIN inv_items i ON i.id = bl.parent_item_id
          WHERE bl.child_item_id IN ($placeholders)
          ORDER BY i.code",
        $childItemIds
    );
    if (empty($candidateParents)) return [];

    $results = [];
    foreach ($candidateParents as $cp) {
        $parentId = (int)$cp['parent_item_id'];
        // Get the full BOM for this candidate parent
        $bom = db_all(
            "SELECT child_item_id, qty FROM inv_bom_lines WHERE parent_item_id = ?",
            [$parentId]
        );
        if (empty($bom)) continue;

        $coveredCount = 0;
        $totalChildren = count($bom);
        $impliedByChild = []; // array of implied parent counts per child
        $details = [];        // per-child detail rows for the UI
        foreach ($bom as $b) {
            $childId   = (int)$b['child_item_id'];
            $childQty  = (float)$b['qty'];
            $isCovered = isset($shipQtyByItem[$childId]);
            if ($isCovered && $childQty > 0) {
                $coveredCount++;
                $impliedByChild[] = $shipQtyByItem[$childId] / $childQty;
            }
            $details[] = [
                'child_id'    => $childId,
                'bom_qty'     => $childQty,
                'shipped_qty' => $isCovered ? $shipQtyByItem[$childId] : null,
                'covered'     => $isCovered,
            ];
        }

        // Skip candidates with zero coverage (no overlap at all)
        if ($coveredCount === 0) continue;

        // Implied qty = floor of the minimum ratio across covered children
        $impliedQty = floor(min($impliedByChild));
        if ($impliedQty <= 0) continue;

        $results[] = [
            'parent_id'         => $parentId,
            'parent_code'       => $cp['parent_code'],
            'parent_name'       => $cp['parent_short_desc'] ?: $cp['parent_name'],
            'implied_qty'       => $impliedQty,
            'covered_count'     => $coveredCount,
            'total_children'    => $totalChildren,
            'coverage_pct'      => round(($coveredCount / $totalChildren) * 100),
            'details'           => $details,
        ];
    }

    // Sort: best-coverage first, then highest implied qty
    usort($results, function ($a, $b) {
        if ($a['coverage_pct'] !== $b['coverage_pct']) {
            return $b['coverage_pct'] <=> $a['coverage_pct'];
        }
        return $b['implied_qty'] <=> $a['implied_qty'];
    });
    return $results;
}

if ($action === 'suggest_receive_lines') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    if (!shr_is_editable($sh['status'])) {
        flash_set('error', 'Can only populate lines on a draft shipment.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    if (!in_array($sh['mode'], shr_modes_with_receive(), true)) {
        flash_set('error', 'This shipment\'s mode doesn\'t include receive lines.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    $candidates = shr_compute_receive_candidates($id);
    if (empty($candidates)) {
        flash_set('error', 'No BOM matches found. Add ship lines first, or define BOMs that include those items as children.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    // Stash candidates in session for the picker page
    $_SESSION['shr_receive_candidates_' . $id] = $candidates;
    redirect(url('/inventory_shiprcpt.php?action=pick_receive_lines&id=' . $id));
}

if ($action === 'pick_receive_lines') {
    require_permission('inventory_shiprcpt', 'manage');
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    $candidates = $_SESSION['shr_receive_candidates_' . $id] ?? null;
    if (!$candidates) {
        // Session expired or never set — recompute on the fly
        $candidates = shr_compute_receive_candidates($id);
        if (empty($candidates)) {
            flash_set('error', 'No BOM matches found. Add ship lines first.');
            redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
        }
        $_SESSION['shr_receive_candidates_' . $id] = $candidates;
    }
    $page_title  = 'Add receive lines from ship items';
    $page_module = 'inventory_shiprcpt';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>Add receive lines</h1>
            <p class="muted">
                Based on the ship lines in this draft, here are the parent items whose BOMs
                they could be producing. Check the ones you want to receive — one receive line
                will be added for each at the implied quantity (floor of the minimum
                <code>ship_qty / bom_child_qty</code> ratio across the covered children).
            </p>
        </div>
    </div>

    <form method="post" action="<?= h(url('/inventory_shiprcpt.php?action=add_receive_lines')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="card" style="margin-bottom: 14px;">
            <div class="card-head"><h3 style="margin: 0; font-size: 15px;">Candidate parent items (<?= count($candidates) ?>)</h3></div>
            <div class="card-body" style="padding: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">Add</th>
                            <th>Parent code</th>
                            <th>Parent name</th>
                            <th class="r">Implied qty</th>
                            <th class="r">Coverage</th>
                            <th>Children matched</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $i => $c): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="add_parent_id[]" value="<?= (int)$c['parent_id'] ?>"
                                           <?= $c['coverage_pct'] >= 80 ? 'checked' : '' ?>>
                                </td>
                                <td><code><?= h($c['parent_code']) ?></code></td>
                                <td><?= h($c['parent_name']) ?></td>
                                <td class="r"><?= (int)$c['implied_qty'] ?></td>
                                <td class="r">
                                    <?php $pillCls = $c['coverage_pct'] >= 80 ? 'pill-success' : ($c['coverage_pct'] >= 50 ? 'pill-info' : 'pill-warning'); ?>
                                    <span class="pill <?= $pillCls ?>"><?= (int)$c['coverage_pct'] ?>%</span>
                                </td>
                                <td class="muted small"><?= (int)$c['covered_count'] ?> of <?= (int)$c['total_children'] ?> BOM children present in ship lines</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top: 14px;">
            <button type="submit" class="btn btn-primary">Add selected receive lines</button>
            <a class="btn btn-ghost" href="<?= h(url('/inventory_shiprcpt.php?action=edit&id=' . $id)) ?>">Cancel</a>
        </div>
    </form>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'add_receive_lines') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    if (!shr_is_editable($sh['status'])) {
        flash_set('error', 'Can only modify a draft shipment.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    $candidates = $_SESSION['shr_receive_candidates_' . $id] ?? null;
    if (!$candidates) {
        flash_set('error', 'Session expired. Re-run the suggest step.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    $pickedIds = (array)input('add_parent_id', []);
    $pickedIds = array_map('intval', $pickedIds);
    if (empty($pickedIds)) {
        flash_set('error', 'Nothing selected.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    // Map candidate by parent_id for fast qty lookup
    $byParentId = [];
    foreach ($candidates as $c) $byParentId[(int)$c['parent_id']] = $c;

    $nextSort = (int)db_val(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM inv_shipment_lines WHERE shipment_id = ?',
        [$id], 0
    );
    $added = 0;
    try {
        db()->beginTransaction();
        foreach ($pickedIds as $pid) {
            if (!isset($byParentId[$pid])) continue;
            $c = $byParentId[$pid];
            db_exec(
                'INSERT INTO inv_shipment_lines
                   (shipment_id, sort_order, line_kind, item_id, qty_planned, src_location_id)
                 VALUES (?, ?, "receive", ?, ?, NULL)',
                [$id, $nextSort++, $pid, (float)$c['implied_qty']]
            );
            $added++;
        }
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Could not add receive lines: ' . $e->getMessage());
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    unset($_SESSION['shr_receive_candidates_' . $id]);
    flash_set('success', $added . ' receive line' . ($added === 1 ? '' : 's') . ' added. Review and edit before approval.');
    redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
}

// ----------------------------------------------------------------
// BOM_FOR_RECEIVE_ITEM — JSON endpoint for the Add-receive-item panel
// ----------------------------------------------------------------
// Returns the immediate BOM children of an item as JSON, so the client
// JS can render a checklist. No checklisting logic on the server.
// Read-only; no CSRF needed (it's a GET).
// ----------------------------------------------------------------
if ($action === 'bom_for_receive_item') {
    require_permission('inventory_shiprcpt', 'view');
    header('Content-Type: application/json');
    $itemId = (int)input('item_id', 0);
    if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'reason' => 'Missing item_id']);
        exit;
    }
    $parent = db_one(
        'SELECT i.id, i.code, i.short_description, i.name, u.label AS uom_label
           FROM inv_items i
           LEFT JOIN inv_uom u ON u.id = i.uom_id
          WHERE i.id = ?',
        [$itemId]
    );
    if (!$parent) {
        echo json_encode(['ok' => false, 'reason' => 'Item not found']);
        exit;
    }
    $children = db_all(
        'SELECT bl.child_item_id AS id, bl.qty AS bom_qty,
                i.code, i.short_description, i.name, u.label AS uom_label
           FROM inv_bom_lines bl
           JOIN inv_items i ON i.id = bl.child_item_id
           LEFT JOIN inv_uom u ON u.id = i.uom_id
          WHERE bl.parent_item_id = ?
          ORDER BY bl.sort_order, bl.id',
        [$itemId]
    );
    // Build a per-child stock_by_loc map so the UI can render a Source
    // dropdown listing ONLY locations that actually have stock for that
    // specific child. Single batched query keyed by child_item_id is
    // cheaper than N separate AJAX calls.
    $childIds = array_map(function ($c) { return (int)$c['id']; }, $children);
    $stockByItem = [];
    if (!empty($childIds)) {
        $placeholders = implode(',', array_fill(0, count($childIds), '?'));
        $stockRows = db_all(
            "SELECT s.item_id, s.location_id, s.qty, l.name AS loc_name, l.code AS loc_code
               FROM inv_item_location_stock s
               JOIN locations l ON l.id = s.location_id
              WHERE s.item_id IN ($placeholders)
                AND s.qty > 0
                AND l.is_active = 1
              ORDER BY s.qty DESC, l.name",
            $childIds
        );
        foreach ($stockRows as $sr) {
            $stockByItem[(int)$sr['item_id']][] = [
                'location_id' => (int)$sr['location_id'],
                'name'        => $sr['loc_name'],
                'code'        => $sr['loc_code'],
                'qty'         => (float)$sr['qty'],
            ];
        }
    }
    echo json_encode([
        'ok'       => true,
        'parent'   => [
            'id'        => (int)$parent['id'],
            'code'      => $parent['code'],
            'label'     => $parent['short_description'] ?: $parent['name'],
            'uom_label' => $parent['uom_label'] ?? '',
        ],
        'children' => array_map(function ($c) use ($stockByItem) {
            return [
                'id'           => (int)$c['id'],
                'code'         => $c['code'],
                'label'        => $c['short_description'] ?: $c['name'],
                'bom_qty'      => (float)$c['bom_qty'],
                'uom_label'    => $c['uom_label'] ?? '',
                // Locations with positive stock for this child. Empty
                // array means "no stock anywhere" — the UI surfaces a
                // warning so the user can't accidentally pick a zero-
                // stock location.
                'stock_by_loc' => $stockByItem[(int)$c['id']] ?? [],
            ];
        }, $children),
    ]);
    exit;
}

// ----------------------------------------------------------------
// STOCK_BY_LOCATION — AJAX endpoint for manual-line entry
// ----------------------------------------------------------------
// Returns locations with positive stock for one item. Used to filter
// the Source dropdown on a manual ship line so users can't pick a
// location that doesn't actually have the item.
// ----------------------------------------------------------------
if ($action === 'stock_by_location') {
    require_permission('inventory_shiprcpt', 'view');
    header('Content-Type: application/json');
    $itemId = (int)input('item_id', 0);
    if ($itemId <= 0) {
        echo json_encode(['ok' => false, 'reason' => 'Missing item_id']);
        exit;
    }
    $rows = db_all(
        'SELECT l.id, l.name, l.code, s.qty
           FROM inv_item_location_stock s
           JOIN locations l ON l.id = s.location_id
          WHERE s.item_id = ? AND s.qty > 0 AND l.is_active = 1
          ORDER BY s.qty DESC, l.name',
        [$itemId]
    );
    echo json_encode([
        'ok'        => true,
        'locations' => array_map(function ($r) {
            return [
                'id'   => (int)$r['id'],
                'name' => $r['name'],
                'code' => $r['code'],
                'qty'  => (float)$r['qty'],
            ];
        }, $rows),
    ]);
    exit;
}

// ----------------------------------------------------------------
// ADD_FROM_BOM_CHECKLIST — commit user's checklist submission
// ----------------------------------------------------------------
// POST from the Add-receive-item panel. Inputs:
//   id                       — shipment id
//   parent_item_id           — the item to receive
//   parent_qty               — qty of the parent (multiplier)
//   default_src_location_id  — optional, applied to every checked ship line
//   child_include[]          — array of child item ids the user checked
//   child_qty_{childId}      — qty for each checked child (already
//                              multiplied client-side, but server uses
//                              this as the source of truth)
//
// Appends to existing lines (never replaces). Receive line for the
// parent + one ship line per checked child. Draft-only.
// ----------------------------------------------------------------
if ($action === 'add_from_bom_checklist') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id           = (int)input('id', 0);
    $parentItemId = (int)input('parent_item_id', 0);
    $parentQty    = (float)input('parent_qty', 0);
    $included     = (array)input('child_include', []);
    $included     = array_map('intval', $included);

    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    if (!shr_is_editable($sh['status'])) {
        flash_set('error', 'Can only modify a draft shipment.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    if ($parentItemId <= 0 || $parentQty <= 0) {
        flash_set('error', 'Pick an item and a positive qty.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }

    // Sanity-check the parent has a BOM. The panel only loads if so,
    // but defend against direct-POST. If parent has no BOM, we still
    // add the receive line — the user might just want a receive-only
    // shipment for that parent.
    $bom = db_all(
        'SELECT child_item_id, qty FROM inv_bom_lines WHERE parent_item_id = ? ORDER BY sort_order, id',
        [$parentItemId]
    );
    $bomByChild = [];
    foreach ($bom as $b) $bomByChild[(int)$b['child_item_id']] = (float)$b['qty'];

    // Validate that every checked child has a non-zero source location
    // picked AND that the picked location actually holds stock for the
    // item. Mirrors the client-side guard but defends against direct
    // POSTs and stale UI state.
    $missingSrcCodes = [];
    foreach ($included as $childId) {
        if ($childId <= 0) continue;
        if (!isset($bomByChild[$childId])) continue;
        $srcKey = 'child_src_' . $childId;
        $srcId = (int)input($srcKey, 0);
        if ($srcId <= 0) {
            $codeRow = db_one('SELECT code FROM inv_items WHERE id = ?', [$childId]);
            $missingSrcCodes[] = $codeRow['code'] ?? ('#' . $childId);
            continue;
        }
        $stockRow = db_one(
            'SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?',
            [$childId, $srcId]
        );
        if (!$stockRow || (float)$stockRow['qty'] <= 0) {
            $codeRow = db_one('SELECT code FROM inv_items WHERE id = ?', [$childId]);
            $missingSrcCodes[] = ($codeRow['code'] ?? ('#' . $childId)) . ' (picked location has no stock)';
        }
    }
    if (!empty($missingSrcCodes)) {
        flash_set('error', 'Source location issue for: ' . implode(', ', $missingSrcCodes)
                         . '. Pick locations with positive stock for each ship line.');
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }

    // Next sort_order
    $nextSort = (int)db_val(
        'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM inv_shipment_lines WHERE shipment_id = ?',
        [$id], 0
    );
    $shipLinesAdded = 0;
    try {
        db()->beginTransaction();
        // 1. Receive line for the parent
        db_exec(
            'INSERT INTO inv_shipment_lines
               (shipment_id, sort_order, line_kind, item_id, qty_planned, src_location_id)
             VALUES (?, ?, "receive", ?, ?, NULL)',
            [$id, $nextSort++, $parentItemId, $parentQty]
        );
        // 2. Ship lines for each checked child
        foreach ($included as $childId) {
            if ($childId <= 0) continue;
            if (!isset($bomByChild[$childId])) continue; // not actually in the BOM; skip
            $qtyKey = 'child_qty_' . $childId;
            $qty = (float)input($qtyKey, 0);
            if ($qty <= 0) {
                // Fall back to bom_qty × parent_qty if missing
                $qty = $bomByChild[$childId] * $parentQty;
            }
            if ($qty <= 0) continue;
            $srcId = (int)input('child_src_' . $childId, 0);
            db_exec(
                'INSERT INTO inv_shipment_lines
                   (shipment_id, sort_order, line_kind, item_id, qty_planned, src_location_id)
                 VALUES (?, ?, "ship", ?, ?, ?)',
                [$id, $nextSort++, $childId, $qty, $srcId]
            );
            $shipLinesAdded++;
        }
        // 3. Auto-derive and write back the new mode on the shipment.
        // It's idempotent — recomputes from inv_shipment_lines.
        $kindsNow = db_all(
            "SELECT DISTINCT line_kind FROM inv_shipment_lines WHERE shipment_id = ?",
            [$id]
        );
        $hasShip = $hasRecv = false;
        foreach ($kindsNow as $r) {
            if ($r['line_kind'] === 'ship')    $hasShip = true;
            if ($r['line_kind'] === 'receive') $hasRecv = true;
        }
        $newMode = $hasShip && $hasRecv ? 'both' : ($hasShip ? 'ship' : ($hasRecv ? 'receive' : 'both'));
        db_exec('UPDATE inv_shipments SET mode = ? WHERE id = ?', [$newMode, $id]);

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Could not add receive item: ' . $e->getMessage());
        redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
    }
    flash_set('success',
        'Receive line added' .
        ($shipLinesAdded > 0
            ? ' along with ' . $shipLinesAdded . ' ship line' . ($shipLinesAdded === 1 ? '' : 's')
            : '') .
        '. Review and edit before approval.');
    redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
}

// ----------------------------------------------------------------
// SAVE — create / edit header + lines
// ----------------------------------------------------------------
if ($action === 'save') {
    // Diagnostic logging — written to PHP error_log so we can confirm
    // the request actually reached this handler and inspect what was
    // POSTed. Remove once the 503 diagnosis is complete.
    error_log('[shiprcpt save] entered. POST keys: ' . implode(',', array_keys($_POST))
            . '; line_kind count=' . count((array)($_POST['line_kind'] ?? []))
            . '; line_item_id count=' . count((array)($_POST['line_item_id'] ?? []))
            . '; line_qty count=' . count((array)($_POST['line_qty'] ?? []))
            . '; line_src_location_id count=' . count((array)($_POST['line_src_location_id'] ?? []))
            . '; src values=[' . implode(',', (array)($_POST['line_src_location_id'] ?? [])) . ']');

    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();

    $id            = (int)input('id', 0);
    // Mode is now DERIVED from line content (no user-picked radio in the
    // new form). Inspect the submitted line_kind[] values to decide:
    //   - has ship + has receive  → 'both'
    //   - has ship only           → 'ship'
    //   - has receive only        → 'receive'
    //   - no lines at all         → default 'both' (existing behavior)
    {
        $kindsIn = (array)input('line_kind', []);
        $itemsIn = (array)input('line_item_id', []);
        $qtysIn  = (array)input('line_qty', []);
        $hasShip = $hasRecv = false;
        $n = max(count($kindsIn), count($itemsIn));
        for ($i = 0; $i < $n; $i++) {
            $kk = isset($kindsIn[$i]) ? (string)$kindsIn[$i] : '';
            $ii = isset($itemsIn[$i]) ? (int)$itemsIn[$i] : 0;
            $qq = isset($qtysIn[$i])  ? (float)$qtysIn[$i] : 0;
            if ($ii <= 0 || $qq <= 0) continue;   // skip incomplete rows
            if ($kk === 'ship')    $hasShip = true;
            if ($kk === 'receive') $hasRecv = true;
        }
        if ($hasShip && $hasRecv) $mode = 'both';
        else if ($hasShip)        $mode = 'ship';
        else if ($hasRecv)        $mode = 'receive';
        else                      $mode = 'both';
    }
    $vendorId      = (int)input('vendor_id', 0);
    $vendorContactId = (int)input('vendor_contact_id', 0) ?: null;
    // Phase D1 — is_amending=1 (hidden field) means this save is an
    // amendment of a past-draft shipment, NOT a draft edit. It bypasses
    // the editable() check and triggers po_create_amendment_for_shipment
    // (new PO version) instead of po_ensure_for_shipment (idempotent v1).
    $isAmendingSave = (int)input('is_amending', 0) === 1;
    $vendorAddressId = (int)input('vendor_address_id', 0) ?: null;
    $refDoc        = trim((string)input('ref_doc', ''));
    $notes         = trim((string)input('notes', ''));
    $isRework      = input('is_rework') ? 1 : 0;
    // PO-style header fields (all optional)
    $paymentTerms       = trim((string)input('payment_terms', ''));
    $packingForwarding  = trim((string)input('packing_forwarding', ''));
    $freightInsurance   = trim((string)input('freight_insurance', ''));
    $notesPo            = trim((string)input('notes_po', ''));
    $specialInstr       = trim((string)input('special_instructions', ''));
    $internalNotes      = trim((string)input('internal_notes', ''));
    // Phase C — new header fields
    $courierId          = (int)input('courier_id', 0) ?: null;
    $reference          = trim((string)input('reference', ''));
    // Terms & Conditions snapshot at save time. We pull the current
    // Settings value once and freeze it on the shipment row so the
    // PO print continues showing the T&C that was in force when the
    // PO was issued, even if Settings is edited later.
    $termsConditions    = magdyn_setting('shiprcpt.terms_conditions', '');

    if (!in_array($mode, ['receive', 'ship', 'both'], true)) {
        flash_set('error', 'Invalid mode.');
        redirect(url('/inventory_shiprcpt.php?action=' . ($id ? 'edit&id=' . $id : 'new')));
    }
    $errors = [];
    if ($vendorId <= 0) $errors[] = 'Vendor is required.';

    if ($errors) {
        flash_set('error', implode(' ', $errors));
        redirect(url('/inventory_shiprcpt.php?action=' . ($id ? 'edit&id=' . $id : 'new')));
    }

    $uid = (int)current_user_id();
    $isNew = ($id === 0);

    // Phase D1 — snapshot the CURRENT lines BEFORE the transaction
    // modifies them. Passed to po_create_amendment_for_shipment() after
    // the commit so the historical PO version shows the OLD values, not
    // the newly-saved ones.
    $preAmendSnapshot = null;
    if ($isAmendingSave && $id > 0) {
        $snapLines = db_all(
            "SELECT l.*,
                    i.code AS item_code, i.name AS item_name,
                    a.asset_tag AS asset_tag,
                    am.name AS asset_model,
                    COALESCE(lu.code, u.code, pu.code) AS uom_code,
                    COALESCE(lu.label, u.label, pu.label) AS uom_label
               FROM inv_shipment_lines l
          LEFT JOIN inv_items i   ON i.id = l.item_id
          LEFT JOIN assets    a   ON a.id = l.asset_id
          LEFT JOIN asset_models am ON am.id = a.model_id
          LEFT JOIN inv_uom   lu  ON lu.id = l.uom_id
          LEFT JOIN inv_uom   u   ON u.id = i.uom_id
          LEFT JOIN inv_uom   pu  ON pu.id = l.pending_uom_id
              WHERE l.shipment_id = ?
              ORDER BY l.sort_order, l.id",
            [$id]
        );
        $snapReceive = [];
        try {
            $snapReceive = db_all(
                "SELECT * FROM inv_shipment_receive_lines WHERE shipment_id = ? ORDER BY sort_order, id",
                [$id]
            );
        } catch (\Throwable $e) { /* table may not exist yet */ }

        $preAmendSnapshot = json_encode([
            'lines'          => $snapLines,
            'receive_lines'  => $snapReceive,
            'snapshotted_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    }

    try {
        db()->beginTransaction();

        if ($isNew) {
            $shipNo = shr_next_ship_no();
            db_exec(
                'INSERT INTO inv_shipments
                   (ship_no, vendor_id, courier_id, reference,
                    vendor_contact_id, vendor_address_id,
                    mode, payment_terms, packing_forwarding, freight_insurance,
                    status, ref_doc, notes, notes_po, special_instructions, internal_notes,
                    terms_conditions, is_rework, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$shipNo, $vendorId, $courierId, $reference ?: null,
                 $vendorContactId, $vendorAddressId,
                 $mode, $paymentTerms ?: null, $packingForwarding ?: null, $freightInsurance ?: null,
                 'draft', $refDoc ?: null, $notes ?: null,
                 $notesPo ?: null, $specialInstr ?: null, $internalNotes ?: null,
                 $termsConditions ?: null, $isRework, $uid]
            );
            $id = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        } else {
            $existing = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
            if (!$existing) {
                db()->rollBack();
                flash_set('error', 'Shipment not found.');
                redirect(url('/inventory_shiprcpt.php'));
            }
            if (!shr_is_editable($existing['status']) && !$isAmendingSave) {
                db()->rollBack();
                flash_set('error', 'Cannot edit shipment after approval. Use Amend instead.');
                redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
            }
            if ($isAmendingSave && in_array($existing['status'], ['cancelled'], true)) {
                db()->rollBack();
                flash_set('error', 'A cancelled shipment cannot be amended.');
                redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
            }
            db_exec(
                'UPDATE inv_shipments
                    SET vendor_id = ?, courier_id = ?, reference = ?,
                        vendor_contact_id = ?, vendor_address_id = ?,
                        mode = ?, payment_terms = ?, packing_forwarding = ?, freight_insurance = ?,
                        ref_doc = ?, notes = ?, notes_po = ?,
                        special_instructions = ?, internal_notes = ?, is_rework = ?
                  WHERE id = ?',
                [$vendorId, $courierId, $reference ?: null,
                 $vendorContactId, $vendorAddressId,
                 $mode, $paymentTerms ?: null, $packingForwarding ?: null, $freightInsurance ?: null,
                 $refDoc ?: null, $notes ?: null, $notesPo ?: null,
                 $specialInstr ?: null, $internalNotes ?: null, $isRework, $id]
            );
        }

        // Save line items. Read parallel form arrays, validate per-row,
        // wipe + reinsert (simpler than diffing; safe at draft status
        // because nothing references inv_shipment_lines.id yet).
        $kinds = (array)input('line_kind', []);
        $items = (array)input('line_item_id', []);
        $qtys  = (array)input('line_qty', []);
        $srcs  = (array)input('line_src_location_id', []);
        $lnotes= (array)input('line_notes', []);
        // Phase C — entity_type per line, plus the optional asset_id
        // (for entity_type='asset' lines), pending_name (for not-yet-
        // existing inv_items), and per-line before/delivery dates.
        $entities  = (array)input('line_entity_type', []);
        $assetIds  = (array)input('line_asset_id', []);
        $pNames    = (array)input('line_pending_name', []);
        $pUoms     = (array)input('line_pending_uom_id', []);
        $lineUoms  = (array)input('line_uom_id', []);
        $linePrices= (array)input('line_unit_price', []);
        $lineGsts  = (array)input('line_gst_rate', []);
        $beforeDts = (array)input('line_before_date', []);
        $deliveryDts = (array)input('line_delivery_date', []);
        // Phase D1 fix — line_id[] carries existing row ids (0 for new
        // rows). Lets us diff against existing DB rows on amendment so
        // we never wipe lines that have receipts behind them.
        $lineIds   = (array)input('line_id', []);
        $n = max(count($kinds), count($items), count($qtys), count($lineIds));

        // ------------------------------------------------------------
        // Step 1 — validate + build a $specs list. Same gates as
        // before; bad rows still bail out via redirect.
        // ------------------------------------------------------------
        $specs = [];
        for ($i = 0; $i < $n; $i++) {
            $lid    = isset($lineIds[$i]) ? (int)$lineIds[$i] : 0;
            $kind   = isset($kinds[$i]) ? (string)$kinds[$i] : '';
            $itemId = isset($items[$i]) ? (int)$items[$i] : 0;
            $qty    = isset($qtys[$i])  ? (float)$qtys[$i] : 0;
            $srcId  = isset($srcs[$i])  ? (int)$srcs[$i]  : 0;
            $ln     = isset($lnotes[$i]) ? trim((string)$lnotes[$i]) : '';
            $entity = isset($entities[$i]) ? (string)$entities[$i] : 'inv_item';
            if (!in_array($entity, ['inv_item', 'asset'], true)) $entity = 'inv_item';
            $assetId   = isset($assetIds[$i]) ? (int)$assetIds[$i] : 0;
            $pName     = isset($pNames[$i])   ? trim((string)$pNames[$i]) : '';
            $pUomId    = isset($pUoms[$i])    ? (int)$pUoms[$i]    : 0;
            $lineUomId = isset($lineUoms[$i]) && $lineUoms[$i] !== '' ? (int)$lineUoms[$i] : 0;
            // Fall back to pending_uom_id when uom_id not submitted (old BOM-populate rows)
            if (!$lineUomId && $pUomId) $lineUomId = $pUomId;
            $linePrice = isset($linePrices[$i]) && $linePrices[$i] !== '' ? (float)$linePrices[$i] : null;
            $lineGst   = isset($lineGsts[$i])   && $lineGsts[$i]   !== '' ? (float)$lineGsts[$i]   : null;
            $beforeDt  = isset($beforeDts[$i])   ? trim((string)$beforeDts[$i])   : '';
            $deliveryDt= isset($deliveryDts[$i]) ? trim((string)$deliveryDts[$i]) : '';

            if ($qty <= 0) continue;
            if ($entity === 'asset') {
                if ($assetId <= 0) continue;
            } else if ($pName !== '') {
                // Pending — itemId optional/zero
            } else {
                if ($itemId <= 0) continue;
            }
            if (!in_array($kind, ['ship', 'receive'], true)) continue;
            if ($mode === 'ship'    && $kind !== 'ship')    continue;
            if ($mode === 'receive' && $kind !== 'receive') continue;
            if ($kind === 'ship' && $entity === 'inv_item' && $itemId > 0 && $srcId <= 0) {
                db()->rollBack();
                flash_set('error', 'Each ship line for an inventory item needs a source location.');
                redirect(url('/inventory_shiprcpt.php?action=' . ($isNew ? 'new' : 'edit&id=' . $id)));
            }
            if ($kind === 'ship' && $entity === 'inv_item' && $itemId > 0) {
                try {
                    $stockRow = db_one(
                        'SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?',
                        [$itemId, $srcId]
                    );
                    if (!$stockRow || (float)$stockRow['qty'] <= 0) {
                        db()->rollBack();
                        flash_set('error', 'Ship line source location has no stock for item #' . $itemId . '.');
                        redirect(url('/inventory_shiprcpt.php?action=' . ($isNew ? 'new' : 'edit&id=' . $id)));
                    }
                } catch (\Throwable $sve) {
                    error_log('[shiprcpt save] stock check skipped: ' . $sve->getMessage());
                }
            }
            $specs[] = [
                'line_id'         => $lid,
                'sort_order'      => $i,
                'kind'            => $kind,
                'entity'          => $entity,
                'item_id'         => ($entity === 'asset' || $pName !== '') ? ($itemId > 0 ? $itemId : null) : $itemId,
                'asset_id'        => $entity === 'asset' ? $assetId : null,
                'pending_name'    => ($entity === 'inv_item' && $pName !== '') ? $pName : null,
                'pending_uom_id'  => ($entity === 'inv_item' && $pName !== '' && $pUomId > 0) ? $pUomId : null,
                'qty_planned'     => $qty,
                'uom_id'          => $lineUomId > 0 ? $lineUomId : null,
                'unit_price'      => $linePrice,
                'gst_rate'        => $lineGst,
                'src_location_id' => ($kind === 'ship' && $entity === 'inv_item' && $itemId > 0) ? $srcId : null,
                'before_date'     => $beforeDt   !== '' ? $beforeDt   : null,
                'delivery_date'   => $deliveryDt !== '' ? $deliveryDt : null,
                'notes'           => $ln ?: null,
            ];
        }
        if (empty($specs)) {
            db()->rollBack();
            flash_set('error', 'Add at least one line item before saving.');
            redirect(url('/inventory_shiprcpt.php?action=' . ($isNew ? 'new' : 'edit&id=' . $id)));
        }

        // ------------------------------------------------------------
        // Step 2 — write the lines.
        // Draft saves: wipe-and-reinsert (no events to protect).
        // Amendments: UPDATE existing / INSERT new / DELETE only if
        //   the existing line has no events behind it.
        // ------------------------------------------------------------
        $written = 0;
        if ($isAmendingSave) {
            $existingIds = [];
            foreach (db_all('SELECT id, qty_shipped FROM inv_shipment_lines WHERE shipment_id = ?', [$id]) as $r) {
                $existingIds[(int)$r['id']] = (float)$r['qty_shipped'];
            }
            $submittedIds = [];
            foreach ($specs as $s) {
                if ($s['line_id'] > 0) $submittedIds[$s['line_id']] = true;
            }
            // Lines the operator removed from the form. Refuse to drop
            // any that have shipped qty or any receipt rows behind them.
            foreach ($existingIds as $eid => $shippedQty) {
                if (isset($submittedIds[$eid])) continue;
                if ($shippedQty > 0) {
                    db()->rollBack();
                    flash_set('error', "Cannot remove line #$eid from the shipment — it has already been shipped. Keep the line and adjust the rest of the amendment.");
                    redirect(url('/inventory_shiprcpt.php?action=amend&id=' . $id));
                }
                $rcptCnt = (int)db_val('SELECT COUNT(*) FROM inv_receipts WHERE shipment_line_id = ?', [$eid], 0);
                if ($rcptCnt > 0) {
                    db()->rollBack();
                    flash_set('error', "Cannot remove line #$eid from the shipment — it has $rcptCnt receipt(s) recorded against it. Cancel those receipts first if you really need to drop this line.");
                    redirect(url('/inventory_shiprcpt.php?action=amend&id=' . $id));
                }
            }
            // UPDATE / INSERT
            foreach ($specs as $s) {
                if ($s['line_id'] > 0 && isset($existingIds[$s['line_id']])) {
                    db_exec(
                        'UPDATE inv_shipment_lines
                            SET sort_order = ?, line_kind = ?, entity_type = ?,
                                item_id = ?, asset_id = ?,
                                pending_name = ?, pending_uom_id = ?,
                                qty_planned = ?, uom_id = ?, unit_price = ?, gst_rate = ?,
                                src_location_id = ?,
                                before_date = ?, delivery_date = ?, notes = ?
                          WHERE id = ?',
                        [$s['sort_order'], $s['kind'], $s['entity'],
                         $s['item_id'], $s['asset_id'],
                         $s['pending_name'], $s['pending_uom_id'],
                         $s['qty_planned'], $s['uom_id'], $s['unit_price'], $s['gst_rate'],
                         $s['src_location_id'],
                         $s['before_date'], $s['delivery_date'], $s['notes'],
                         (int)$s['line_id']]
                    );
                } else {
                    db_exec(
                        'INSERT INTO inv_shipment_lines
                            (shipment_id, sort_order, line_kind, entity_type,
                             item_id, asset_id, pending_name, pending_uom_id,
                             qty_planned, uom_id, unit_price, gst_rate,
                             src_location_id, before_date, delivery_date, notes)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$id, $s['sort_order'], $s['kind'], $s['entity'],
                         $s['item_id'], $s['asset_id'],
                         $s['pending_name'], $s['pending_uom_id'],
                         $s['qty_planned'], $s['uom_id'], $s['unit_price'], $s['gst_rate'],
                         $s['src_location_id'],
                         $s['before_date'], $s['delivery_date'], $s['notes']]
                    );
                }
                $written++;
            }
            // Safe DELETEs
            foreach ($existingIds as $eid => $shippedQty) {
                if (!isset($submittedIds[$eid])) {
                    db_exec('DELETE FROM inv_shipment_lines WHERE id = ?', [$eid]);
                }
            }
        } else {
            // Draft mode — keep the original wipe-and-reinsert. Cheap
            // and correct because drafts can't have events yet.
            db_exec('DELETE FROM inv_shipment_lines WHERE shipment_id = ?', [$id]);
            foreach ($specs as $s) {
                db_exec(
                    'INSERT INTO inv_shipment_lines
                        (shipment_id, sort_order, line_kind, entity_type,
                         item_id, asset_id, pending_name, pending_uom_id,
                         qty_planned, uom_id, unit_price, gst_rate,
                         src_location_id, before_date, delivery_date, notes)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $s['sort_order'], $s['kind'], $s['entity'],
                     $s['item_id'], $s['asset_id'],
                     $s['pending_name'], $s['pending_uom_id'],
                     $s['qty_planned'], $s['uom_id'], $s['unit_price'], $s['gst_rate'],
                     $s['src_location_id'],
                     $s['before_date'], $s['delivery_date'], $s['notes']]
                );
                $written++;
            }
        }

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Could not save shipment: ' . $e->getMessage());
        redirect(url('/inventory_shiprcpt.php?action=' . ($isNew ? 'new' : 'edit&id=' . $id)));
    }

    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details)
         VALUES (?, ?, ?, ?)",
        [$uid, $isNew ? 'shiprcpt.create' : 'shiprcpt.update', $id, $mode]
    );

    // Phase C/D1 — PO generation.
    //   - First save / draft edit  → po_ensure_for_shipment (idempotent v1)
    //   - Amendment of past-draft   → po_create_amendment_for_shipment (new version)
    // Never throws — wrap so a downstream issue (e.g. code_sequences
    // misconfigured) doesn't block the local save flash.
    $newPo = null;
    try {
        if ($isAmendingSave) {
            $newPo = po_create_amendment_for_shipment($id, $uid, $preAmendSnapshot);
        } else {
            $newPo = po_ensure_for_shipment($id, $uid);
        }
    } catch (\Throwable $ePo) {
        error_log('[shiprcpt save] PO generation failed: ' . $ePo->getMessage());
    }

    if ($isAmendingSave) {
        $msg = 'Shipment amended';
        if ($newPo) $msg .= ' — PO <strong>' . h($newPo['po_no']) . '</strong> updated to v' . (int)$newPo['version'];
        $msg .= '.';
        flash_set('success', $msg);
    } else {
        flash_set('success', $isNew ? 'Shipment created as draft. Approve it to start movements.' : 'Shipment updated.');
    }
    redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// APPROVE
// ----------------------------------------------------------------
if ($action === 'approve') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    if ($sh['status'] !== 'draft') {
        flash_set('error', 'Only draft shipments can be approved.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    $uid = (int)current_user_id();
    db_exec(
        'UPDATE inv_shipments SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?',
        ['approved', $uid, $id]
    );
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.approve', ?, ?)",
        [$uid, $id, $sh['ship_no']]
    );
    flash_set('success', 'Shipment approved.');
    redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// SHIP — post the one-shot ship event
// ----------------------------------------------------------------
if ($action === 'ship') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    if (!in_array($sh['mode'], shr_modes_with_ship(), true)) {
        flash_set('error', 'This shipment has no ship side — nothing to send.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    if ($sh['status'] !== 'approved') {
        flash_set('error', 'Only approved shipments can be shipped.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    $actualShipDate = trim((string)input('actual_ship_date', '')) ?: date('Y-m-d');

    $shipLines = db_all(
        "SELECT * FROM inv_shipment_lines WHERE shipment_id = ? AND line_kind = 'ship' ORDER BY sort_order, id",
        [$id]
    );
    if (empty($shipLines)) {
        flash_set('error', 'No ship lines to post.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }

    $uid = (int)current_user_id();
    try {
        db()->beginTransaction();
        foreach ($shipLines as $L) {
            $entity = $L['entity_type'] ?? 'inv_item';
            if ($entity === 'asset') {
                // Phase C — ship an asset = send_vendor transaction.
                // No inventory ledger movement because the asset isn't
                // tracked in inv_txns. Just write the audit row.
                if (!empty($L['asset_id'])) {
                    asset_txn_record(
                        (int)$L['asset_id'],
                        'send_vendor',
                        ['to_vendor_id' => (int)$sh['vendor_id']],
                        $uid,
                        'Auto: shipped via ' . $sh['ship_no']
                    );
                }
            } elseif (!empty($L['item_id'])) {
                // Regular inv_item ship line — post the ledger txn.
                inv_post_txn(
                    'ship_out',
                    $actualShipDate,
                    (int)$L['item_id'],
                    (int)$L['src_location_id'],
                    -1 * (float)$L['qty_planned'],
                    null,
                    $sh['ref_doc'] ?: null,
                    'Ship-out for ' . $sh['ship_no']
                );
            }
            // Pending-name lines on the ship side don't ship anything
            // — they describe an expected receipt. Skip them safely.
            db_exec(
                'UPDATE inv_shipment_lines SET qty_shipped = qty_planned WHERE id = ?',
                [(int)$L['id']]
            );
        }
        db_exec(
            'UPDATE inv_shipments
                SET status = ?, shipped_by = ?, shipped_at = NOW(), actual_ship_date = ?
              WHERE id = ?',
            ['shipped', $uid, $actualShipDate, $id]
        );
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Ship-out failed: ' . $e->getMessage());
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.ship', ?, ?)",
        [$uid, $id, $sh['ship_no'] . ' on ' . $actualShipDate]
    );
    flash_set('success', 'Shipped.');
    redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// RECEIVE — record one receipt event (partial allowed)
// ----------------------------------------------------------------
if ($action === 'receive_save') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id     = (int)input('id', 0);
    $lineId = (int)input('shipment_line_id', 0);
    $qty    = (float)input('qty_received', 0);
    $rcptDate = trim((string)input('receipt_date', '')) ?: date('Y-m-d');
    $locId  = (int)input('dst_location_id', 0);
    $notes  = trim((string)input('notes', ''));

    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) { flash_set('error', 'Shipment not found.'); redirect(url('/inventory_shiprcpt.php')); }
    $line = db_one(
        "SELECT * FROM inv_shipment_lines WHERE id = ? AND shipment_id = ? AND line_kind = 'receive'",
        [$lineId, $id]
    );
    if (!$line) {
        flash_set('error', 'Receive line not found.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    if (!in_array($sh['status'], ['approved', 'shipped'], true)) {
        flash_set('error', 'Receipts can be recorded only after approval.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    if ($qty <= 0) {
        flash_set('error', 'Receipt qty must be > 0.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }

    // Force destination to LOC-QCH (Quality Check Hold). All incoming
    // material lands here pending inspection regardless of what the
    // operator picked on the form. The inspection's approval routes
    // the qty out to the appropriate location automatically. We still
    // accept whatever was posted (so old forms don't error) but the
    // override below ignores it.
    $qchLocId = qc_loc_id('LOC-QCH');
    if (!$qchLocId) {
        flash_set('error', 'LOC-QCH location is missing. Run the migration that seeds it.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    $locId = $qchLocId;

    $uid = (int)current_user_id();
    $entity = $line['entity_type'] ?? 'inv_item';

    // ---- Phase C — asset receive branch ----
    if ($entity === 'asset') {
        if (empty($line['asset_id'])) {
            flash_set('error', 'Asset line has no linked asset_id.');
            redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
        }
        try {
            db()->beginTransaction();
            asset_txn_record(
                (int)$line['asset_id'],
                'receive_vendor',
                ['to_location_id' => $locId],
                $uid,
                'Auto: received via ' . $sh['ship_no']
            );
            db_exec(
                'UPDATE inv_shipment_lines SET qty_received = qty_planned WHERE id = ?',
                [(int)$line['id']]
            );
            db()->commit();
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash_set('error', 'Asset receipt failed: ' . $e->getMessage());
            redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
        }
        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.receive_asset', ?, ?)",
            [$uid, $id, $sh['ship_no'] . ' asset ' . $line['asset_id']]
        );
        flash_set('success', 'Asset receipt recorded.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }

    // ---- Phase C — pending (not-yet-existing) item branch ----
    // Create the inv_items row at receipt time, bind it to the line,
    // then fall through to the normal ledger-post receive flow below.
    if (empty($line['item_id']) && !empty($line['pending_name'])) {
        $newCode = function_exists('inv_id_generate') ? inv_id_generate() : ('I-' . date('YmdHis'));
        try {
            db_exec(
                'INSERT INTO inv_items (code, name, uom_id, is_active)
                  VALUES (?, ?, ?, 1)',
                [$newCode, (string)$line['pending_name'],
                 !empty($line['pending_uom_id']) ? (int)$line['pending_uom_id'] : null]
            );
            $newItemId = (int)db()->lastInsertId();
            db_exec(
                'UPDATE inv_shipment_lines SET item_id = ?, pending_name = NULL WHERE id = ?',
                [$newItemId, (int)$line['id']]
            );
            $line['item_id'] = $newItemId;
        } catch (\Throwable $e) {
            flash_set('error', 'Could not create the pending item on receipt: ' . $e->getMessage());
            redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
        }
    }

    try {
        db()->beginTransaction();
        // inv_post_txn returns ['txn_id' => N, 'qty_after' => X] — extract
        // the id for the FK on inv_receipts.txn_id. Passing the whole
        // array would coerce to the string "Array" which then casts to 0
        // and the FK constraint fk_invr_txn rejects the insert.
        $txnResult = inv_post_txn(
            'ship_in',
            $rcptDate,
            (int)$line['item_id'],
            $locId,
            $qty,
            null,
            $sh['ref_doc'] ?: null,
            'Receipt for ' . $sh['ship_no']
        );
        $txnId = is_array($txnResult) ? (int)$txnResult['txn_id'] : (int)$txnResult;
        $rcptNo = shr_next_receipt_no();
        db_exec(
            'INSERT INTO inv_receipts
               (receipt_no, shipment_id, shipment_line_id, qty_received, receipt_date,
                due_date_snapshot, dst_location_id, txn_id, ref_doc, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$rcptNo, $id, (int)$line['id'], $qty, $rcptDate,
             null, $locId, $txnId,
             $sh['ref_doc'] ?: null, $notes ?: null, $uid]
        );
        shr_recompute_line_received((int)$line['id']);

        // Auto-create the QC inspection. Linked to this txn so it shows
        // in the pending QC list and the approve step can move the
        // qty out of LOC-QCH atomically.
        qc_auto_create_inspection_for_txn($txnId);

        // Phase C2 — persist Price + GST% to the receive-side pricing
        // table so the PO print can render them and Phase D invoicing
        // can pick them up. Upsert by (shipment_id, item_id). Wrapped
        // in try so a missing table on older installs degrades quietly.
        try {
            $rPrice = input('price', null);
            $rGst   = input('gst_pct', null);
            $rPrice = ($rPrice === '' || $rPrice === null) ? null : (float)$rPrice;
            $rGst   = ($rGst   === '' || $rGst   === null) ? null : (float)$rGst;
            if (($rPrice !== null || $rGst !== null) && !empty($line['item_id'])) {
                $existing = db_one(
                    "SELECT id FROM inv_shipment_receive_lines WHERE shipment_id = ? AND item_id = ?",
                    [$id, (int)$line['item_id']]
                );
                if ($existing) {
                    db_exec(
                        "UPDATE inv_shipment_receive_lines
                            SET price = COALESCE(?, price),
                                gst_pct = COALESCE(?, gst_pct)
                          WHERE id = ?",
                        [$rPrice, $rGst, (int)$existing['id']]
                    );
                } else {
                    db_exec(
                        "INSERT INTO inv_shipment_receive_lines
                            (shipment_id, item_id, qty_expected, qty_received, price, gst_pct)
                          VALUES (?, ?, ?, 0, ?, ?)",
                        [$id, (int)$line['item_id'], (float)$line['qty_planned'], $rPrice, $rGst]
                    );
                }
            }
        } catch (\Throwable $ePr) {
            error_log('[shiprcpt receive_save] receive-line pricing upsert skipped: ' . $ePr->getMessage());
        }

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash_set('error', 'Receipt failed: ' . $e->getMessage());
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.receive', ?, ?)",
        [$uid, $id, $sh['ship_no'] . ' line ' . $line['id'] . ' qty ' . $qty . ' to LOC-QCH']
    );
    flash_set('success', 'Receipt recorded at LOC-QCH; inspection pending.');
    redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// CLOSE / CANCEL
// ----------------------------------------------------------------
if ($action === 'close') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) { flash_set('error', 'Shipment not found.'); redirect(url('/inventory_shiprcpt.php')); }
    if (!in_array($sh['status'], ['approved', 'shipped'], true)) {
        flash_set('error', 'Only active shipments can be closed.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    db_exec('UPDATE inv_shipments SET status = ? WHERE id = ?', ['closed', $id]);
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.close', ?, ?)",
        [(int)current_user_id(), $id, $sh['ship_no']]
    );
    flash_set('success', 'Shipment closed.');
    redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
}

if ($action === 'cancel') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) { flash_set('error', 'Shipment not found.'); redirect(url('/inventory_shiprcpt.php')); }
    if (!in_array($sh['status'], ['draft', 'approved'], true)) {
        flash_set('error', 'Only draft or approved shipments can be cancelled (no stock has moved).');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    // Hard-block: if any receipt on this shipment is linked to an
    // invoice, the user must unlink first. We list the invoices in
    // the flash so they know where to go.
    $blockingInvoices = db_all(
        'SELECT DISTINCT i.invoice_no
           FROM inv_receipts r
           JOIN invoice_lines il    ON il.inv_receipt_id = r.id
           JOIN invoice_items ii    ON ii.id = il.invoice_item_id
           JOIN invoices i          ON i.id = ii.invoice_id
          WHERE r.shipment_id = ?
          ORDER BY i.invoice_no',
        [$id]
    );
    if ($blockingInvoices) {
        $nos = invoice_link_format_invoice_list($blockingInvoices);
        flash_set('error', 'Cannot cancel: receipts on this shipment are linked to invoice(s): '
            . $nos . '. Unlink them first on each invoice\'s Links page.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    db_exec('UPDATE inv_shipments SET status = ? WHERE id = ?', ['cancelled', $id]);
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.cancel', ?, ?)",
        [(int)current_user_id(), $id, $sh['ship_no']]
    );
    flash_set('success', 'Shipment cancelled.');
    redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
}

if ($action === 'delete') {
    require_permission('inventory_shiprcpt', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) { flash_set('error', 'Shipment not found.'); redirect(url('/inventory_shiprcpt.php')); }
    if (!in_array($sh['status'], ['draft', 'cancelled'], true)) {
        flash_set('error', 'Only draft or cancelled shipments can be deleted (anything else has stock implications).');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    // Hard-block: shipments with linked receipts must have those
    // invoice links removed first. Without this, deleting the
    // shipment would CASCADE through inv_receipts → invoice_lines
    // (we have ON DELETE SET NULL on inv_receipt_id) and orphan
    // the link rows — bad audit trail.
    $blockingInvoices = db_all(
        'SELECT DISTINCT i.invoice_no
           FROM inv_receipts r
           JOIN invoice_lines il    ON il.inv_receipt_id = r.id
           JOIN invoice_items ii    ON ii.id = il.invoice_item_id
           JOIN invoices i          ON i.id = ii.invoice_id
          WHERE r.shipment_id = ?
          ORDER BY i.invoice_no',
        [$id]
    );
    if ($blockingInvoices) {
        $nos = invoice_link_format_invoice_list($blockingInvoices);
        flash_set('error', 'Cannot delete: receipts on this shipment are linked to invoice(s): '
            . $nos . '. Unlink them first.');
        redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
    }
    db_exec('DELETE FROM inv_shipments WHERE id = ?', [$id]);
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'shiprcpt.delete', ?, ?)",
        [(int)current_user_id(), $id, $sh['ship_no']]
    );
    flash_set('success', 'Shipment deleted.');
    redirect(url('/inventory_shiprcpt.php'));
}

// ----------------------------------------------------------------
// NEW / EDIT / AMEND — form
// ----------------------------------------------------------------
// 'amend' is a Phase D1 variant of 'edit' that unlocks the form on
// a past-draft shipment so the operator can change locked fields
// (item_id, qty). On save (driven by the hidden is_amending flag
// posted with the form), a NEW PO version is created instead of
// the idempotent v1 ensure-call.
if ($action === 'new' || $action === 'edit' || $action === 'amend') {
    if ($action === 'new') require_permission('inventory_shiprcpt', 'manage');
    if ($action === 'amend') require_permission('inventory_shiprcpt', 'manage');
    $isAmending = ($action === 'amend');
    $id = ($action === 'edit' || $action === 'amend') ? (int)input('id', 0) : 0;
    $sh = $id > 0 ? db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]) : null;
    if ($action === 'edit') {
        if (!$sh) { flash_set('error', 'Shipment not found.'); redirect(url('/inventory_shiprcpt.php')); }
        if (!shr_is_editable($sh['status'])) {
            flash_set('error', 'This shipment is past draft and cannot be edited.');
            redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
        }
    }
    if ($action === 'amend') {
        if (!$sh) { flash_set('error', 'Shipment not found.'); redirect(url('/inventory_shiprcpt.php')); }
        // Amend is only meaningful on a past-draft shipment that has
        // a PO to amend FROM. A draft shipment hasn't issued a PO yet,
        // so the operator should just keep editing it normally.
        if (shr_is_editable($sh['status'])) {
            flash_set('info', 'Shipment is still a draft — edit it directly instead of amending.');
            redirect(url('/inventory_shiprcpt.php?action=edit&id=' . $id));
        }
        if (in_array($sh['status'], ['cancelled'], true)) {
            flash_set('error', 'A cancelled shipment cannot be amended.');
            redirect(url('/inventory_shiprcpt.php?action=view&id=' . $id));
        }
    }
    $lines = $id > 0
        ? db_all('SELECT * FROM inv_shipment_lines WHERE shipment_id = ? ORDER BY sort_order, id', [$id])
        : [];
    if (empty($lines)) $lines = [null];
    else $lines[] = null;   // one trailing empty for adding

    $vendors   = db_all('SELECT id, code, name, is_active FROM vendors ORDER BY name');
    $locations = db_all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name');
    $itemOpts  = shr_item_picker_options();

    $vMode        = $sh['mode']             ?? 'both';
    $vVendorId    = (int)($sh['vendor_id']  ?? 0);
    $vContactId   = (int)($sh['vendor_contact_id'] ?? 0);
    $vAddressId   = (int)($sh['vendor_address_id'] ?? 0);
    $vRefDoc      = $sh['ref_doc']          ?? '';
    $vNotes       = $sh['notes']            ?? '';
    $vIsRework    = (int)($sh['is_rework']  ?? 0);
    // PO-style fields (added by migration 220000)
    $vPaymentTerms      = $sh['payment_terms']       ?? '';
    $vPackForw          = $sh['packing_forwarding']  ?? '';
    $vFreightIns        = $sh['freight_insurance']   ?? '';
    $vNotesPo           = $sh['notes_po']            ?? '';
    $vSpecialInstr      = $sh['special_instructions'] ?? '';
    $vInternalNotes     = $sh['internal_notes']      ?? '';
    // Phase C2 — new header fields
    $vCourierId         = (int)($sh['courier_id'] ?? 0);
    $vReference         = (string)($sh['reference'] ?? '');
    // Terms & conditions: prefer the snapshot stored on this row
    // (frozen at save time); fall back to the current Settings value
    // for a never-saved new shipment.
    $vTermsConditions   = (string)($sh['terms_conditions'] ?? '');
    if ($vTermsConditions === '') {
        $vTermsConditions = magdyn_setting('shiprcpt.terms_conditions', '');
    }
    $couriers = db_all('SELECT id, code, name FROM shipping_couriers WHERE is_active = 1 ORDER BY sort_order, name');
    // Existing lines (for edit) — fetch the Phase C extras too so the
    // line grid renders them. New-mode starts with empty rows.
    $existingLines = $id > 0
        ? db_all(
            "SELECT l.*,
                    i.code AS item_code, i.name AS item_name,
                    a.asset_tag AS asset_tag,
                    am.name AS asset_model_name
               FROM inv_shipment_lines l
          LEFT JOIN inv_items i  ON i.id = l.item_id
          LEFT JOIN assets    a  ON a.id = l.asset_id
          LEFT JOIN asset_models am ON am.id = a.model_id
              WHERE l.shipment_id = ?
              ORDER BY l.sort_order, l.id",
            [$id]
          )
        : [];
    // Lists for the line pickers
    $allAssets = db_all(
        "SELECT a.id, a.asset_tag, COALESCE(am.name, '') AS model_name
           FROM assets a
      LEFT JOIN asset_models am ON am.id = a.model_id
          WHERE a.status <> 'archived'
          ORDER BY a.asset_tag"
    );
    $allUoms = [];
    try {
        $allUoms = db_all("SELECT id, code, label FROM inv_uom ORDER BY code");
    } catch (\Throwable $e) { /* table may be named differently in older installs */ }

    // Pre-fetch contacts + addresses for the CURRENT vendor so the
    // dropdowns render with the right options on first paint. JS
    // cascade replaces these client-side when the vendor changes.
    $vendorContacts  = $vVendorId > 0
        ? db_all('SELECT id, name, designation, is_primary FROM vendor_contacts
                   WHERE vendor_id = ? ORDER BY is_primary DESC, sort_order, name', [$vVendorId])
        : [];
    $vendorAddresses = $vVendorId > 0
        ? db_all('SELECT id, label, line1, city FROM vendor_addresses
                   WHERE vendor_id = ? ORDER BY is_primary DESC, sort_order, id', [$vVendorId])
        : [];

    $page_title  = $id ? 'Edit shipment' : 'New shipment';
    $page_module = 'inventory_shiprcpt';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/inventory_shiprcpt.php' . ($id ? '?action=view&id=' . $id : '')),
        'back_label' => $id ? 'Back to shipment' : 'Back to list',
        'title'      => $id ? ('Edit ' . h($sh['ship_no'])) : 'New shipment',
    ]) ?>

    <?php /* The "Suggest receive lines" inner form lives OUTSIDE the main
            shipment form because HTML doesn't allow nested <form> tags
            — nesting orphans every element after the inner </form> from
            the outer form, breaking submission. The submit button stays
            inside the main form visually and links here via form="...".
            Only relevant for existing drafts ($id > 0). */ ?>
    <?php if (($action === 'new' || $action === 'edit' || $action === 'amend') && $id > 0 && ($sh === null || shr_is_editable($sh['status']) || $isAmending)): ?>
    <form id="shr-suggest-form" method="post"
          action="<?= h(url('/inventory_shiprcpt.php?action=suggest_receive_lines')) ?>"
          style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    </form>
    <?php endif; ?>

    <form id="shr-main-form" method="post" action="<?= h(url('/inventory_shiprcpt.php?action=save')) ?>"
          class="shr-form">
        <?= csrf_field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
        <?php if ($isAmending): ?>
            <!-- Phase D1 — amendment banner + flag. The save handler
                 reads is_amending and, on success, creates a NEW PO
                 version (po_create_amendment_for_shipment) instead of
                 the idempotent ensure call used on first save. -->
            <input type="hidden" name="is_amending" value="1">
            <?php
                $latestPo = po_latest_for_shipment((int)$id);
                $nextVer  = $latestPo ? ((int)$latestPo['version'] + 1) : 1;
            ?>
            <div class="alert alert-warn" style="margin-bottom: 16px;">
                <strong>Amending this shipment.</strong>
                Saving will create a new PO version
                <?php if ($latestPo): ?>
                    (current: <code><?= h(po_label_with_version($latestPo)) ?></code>, next: <strong>v<?= (int)$nextVer ?></strong>)
                <?php endif; ?>.
                The original PO stays unchanged for audit purposes.
                Lifecycle status remains <strong><?= h($sh['status']) ?></strong>.
            </div>
        <?php endif; ?>

        <!-- ============================================================
             STEP 1 — Shipment basics
             ============================================================ -->
        <section class="shr-step">
            <div class="shr-step-head">
                <span class="shr-step-num">1</span>
                <div>
                    <h3 class="shr-step-title">Shipment basics</h3>
                    <p class="shr-step-help">Pick the vendor. The direction is set automatically based on the lines you add below.</p>
                </div>
            </div>
            <div class="shr-step-body">
                <div class="grid-2col">
                    <div class="field">
                        <label>Direction</label>
                        <?php
                          $hasShipLine = $hasRecvLine = false;
                          foreach ($lines as $L) {
                              if (!$L) continue;
                              if (($L['line_kind'] ?? '') === 'ship')    $hasShipLine = true;
                              if (($L['line_kind'] ?? '') === 'receive') $hasRecvLine = true;
                          }
                          if ($hasShipLine && $hasRecvLine) { $derivedMode = 'both';    $derivedLabel = '⇅ Ship & receive'; $pillCls = 'pill-info'; }
                          else if ($hasShipLine)            { $derivedMode = 'ship';    $derivedLabel = '↑ Ship only';     $pillCls = 'pill-warning'; }
                          else if ($hasRecvLine)            { $derivedMode = 'receive'; $derivedLabel = '↓ Receive only';  $pillCls = 'pill-success'; }
                          else                              { $derivedMode = '';        $derivedLabel = 'No lines yet';    $pillCls = 'pill-neutral'; }
                        ?>
                        <div style="margin-top: 4px;">
                            <span class="pill <?= $pillCls ?>" id="shr-mode-pill" data-mode="<?= h($derivedMode) ?>"><?= h($derivedLabel) ?></span>
                        </div>
                        <span class="muted small">Auto-determined from the lines below.</span>
                    </div>
                    <div class="field">
                        <label for="f_vendor">Vendor <span class="required">*</span></label>
                        <select id="f_vendor" name="vendor_id" required>
                            <option value="">— pick a vendor —</option>
                            <?php foreach ($vendors as $v):
                                if (!$v['is_active'] && (int)$v['id'] !== $vVendorId) continue; ?>
                                <option value="<?= (int)$v['id'] ?>"
                                        <?= (int)$v['id'] === $vVendorId ? 'selected' : '' ?>>
                                    <?= h($v['code']) ?> — <?= h($v['name']) ?>
                                    <?= !$v['is_active'] ? ' (disabled)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Phase C2 — vendor contact & address selects.
                         Default to the primary contact / address on
                         vendor change (PRD §2.1). PRD calls for the
                         contact field to be a multi-select; the
                         backing column inv_shipments.vendor_contact_id
                         is a single FK right now, so for v1 we keep
                         it single-select and pre-select the primary.
                         Phase D will lift to a join table if
                         multi-recipient handling needs to persist on
                         the shipment row itself. -->
                    <div class="field">
                        <label for="f_vendor_contact">Vendor contact</label>
                        <select id="f_vendor_contact" name="vendor_contact_id">
                            <option value="">— primary —</option>
                            <?php foreach ($vendorContacts as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"
                                        <?= (int)$c['id'] === $vContactId ? 'selected' : '' ?>>
                                    <?= h(trim(($c['designation'] ?? '') . ' ' . $c['name'])) ?>
                                    <?= !empty($c['is_primary']) ? ' (primary)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="f_vendor_address">Vendor address</label>
                        <select id="f_vendor_address" name="vendor_address_id">
                            <option value="">— primary —</option>
                            <?php foreach ($vendorAddresses as $a): ?>
                                <option value="<?= (int)$a['id'] ?>"
                                        <?= (int)$a['id'] === $vAddressId ? 'selected' : '' ?>>
                                    <?= h(($a['label'] ?: $a['line1']) . ($a['city'] ? ' · ' . $a['city'] : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="f_ref">Reference doc</label>
                        <input id="f_ref" name="ref_doc" type="text" maxlength="64"
                               value="<?= h($vRefDoc) ?>" placeholder="PO / WO / etc.">
                    </div>
                    <div class="field">
                        <label class="inline" style="margin-top: 22px;">
                            <input type="checkbox" name="is_rework" value="1" <?= $vIsRework ? 'checked' : '' ?>>
                            This shipment is for rework
                        </label>
                    </div>
                </div>
            </div>

            <!-- Phase C — PRD §2.2 header fields. Sit after vendor /
                 address per PRD requirement. Most are free-text;
                 Courier comes from the shipping_couriers lookup;
                 Terms & Conditions is a read-only snapshot pulled
                 from Settings (frozen at save time on this row). -->
            <div class="shr-step-body" style="border-top: 1px solid var(--border); padding-top: 16px; margin-top: 8px;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;">Commercial details</h4>
                <div class="shr-grid">
                    <div class="field">
                        <label for="f_courier">Shipping courier</label>
                        <select id="f_courier" name="courier_id">
                            <option value="">— pick a courier —</option>
                            <?php foreach ($couriers as $cr): ?>
                                <option value="<?= (int)$cr['id'] ?>" <?= (int)$cr['id'] === $vCourierId ? 'selected' : '' ?>>
                                    <?= h($cr['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="f_reference">Reference</label>
                        <input id="f_reference" name="reference" type="text" maxlength="190"
                               value="<?= h($vReference) ?>" placeholder="e.g. vendor's quotation no. / tracking no.">
                    </div>
                    <div class="field">
                        <label for="f_payment_terms">Payment terms</label>
                        <input id="f_payment_terms" name="payment_terms" type="text" maxlength="255"
                               value="<?= h($vPaymentTerms) ?>" placeholder="e.g. Net 30 / 50% advance + 50% on delivery">
                    </div>
                    <div class="field">
                        <label for="f_packing_forwarding">Packing &amp; forwarding</label>
                        <input id="f_packing_forwarding" name="packing_forwarding" type="text" maxlength="255"
                               value="<?= h($vPackForw) ?>" placeholder="e.g. As actuals">
                    </div>
                    <div class="field">
                        <label for="f_freight_insurance">Freight &amp; insurance</label>
                        <input id="f_freight_insurance" name="freight_insurance" type="text" maxlength="255"
                               value="<?= h($vFreightIns) ?>" placeholder="e.g. To-pay / Pre-paid">
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="f_special_instructions">Special instructions</label>
                        <textarea id="f_special_instructions" name="special_instructions" rows="2"
                                  placeholder="Visible on the PO"><?= h($vSpecialInstr) ?></textarea>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="f_internal_notes">Notes for internal use</label>
                        <textarea id="f_internal_notes" name="internal_notes" rows="2"
                                  placeholder="Not shown on the PO"><?= h($vInternalNotes) ?></textarea>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label>Terms &amp; Conditions <span class="muted small">(non-editable — change in Settings → defaults)</span></label>
                        <textarea readonly rows="5"
                                  style="background: var(--surface-alt, #f5f6f8); color: var(--text-muted); font-size: 11.5px; white-space: pre-wrap;"><?= h($vTermsConditions) ?></textarea>
                        <div class="muted small">Snapshotted onto this shipment when first saved. The PO print shows this exact text.</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============================================================
             STEP 2 — Add items (BOM-driven, primary path)
             ============================================================ -->
        <section class="shr-step">
            <div class="shr-step-head">
                <span class="shr-step-num">2</span>
                <div>
                    <h3 class="shr-step-title">Add items</h3>
                    <p class="shr-step-help">Pick a finished good or sub-assembly to <strong>receive</strong>. Its BOM children appear as a checklist — tick the ones to ship to the vendor and click <em>Add to list</em>. Repeat for multiple items. The whole list is saved when you click <em>Create shipment</em> at the bottom.</p>
                </div>
            </div>
            <div class="shr-step-body">

        <?php if ($sh === null || shr_is_editable($sh['status'])): ?>
            <!--
              "Add a receive item" panel. Two modes:
                - Existing draft ($id > 0): POSTs to add_from_bom_checklist
                - New (unsaved): JS appends rows to the table below
            -->
            <div class="shr-add-panel">
                <div class="shr-add-row">
                    <div class="field" style="margin: 0; flex: 1; min-width: 240px;">
                        <label>Item to receive (finished good OR sub-assembly)</label>
                        <select id="shr-recv-item">
                            <option value="">— pick an item —</option>
                            <?php foreach ($itemOpts as $opt): ?>
                                <option value="<?= (int)$opt['id'] ?>"><?= h($opt['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="margin: 0; width: 100px;">
                        <label>Qty</label>
                        <input id="shr-recv-mult" type="number" value="1" min="0.001" step="0.001">
                    </div>
                    <button type="button" id="shr-load-bom" class="btn btn-primary">Load BOM →</button>
                </div>
                <div id="shr-bom-checklist" style="margin-top: 14px; display: none;"></div>
            </div>

            <?php if ($id > 0): ?>
            <div style="margin-top: 10px;">
                <button type="submit" form="shr-suggest-form" class="btn btn-ghost btn-sm"
                        title="Scan existing ship lines and suggest parent items whose BOMs they could be producing">
                    ⤒ Suggest receive lines from existing ship items
                </button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Manual line entry — two separate sections for ship and receive lines.
             Collapsed by default; opens if there are existing lines. -->
        <?php
            $shipLines = [];
            $recvLines = [];
            foreach ($lines as $L) {
                if (!is_array($L)) continue;
                if (($L['line_kind'] ?? '') === 'ship') $shipLines[] = $L;
                else                                    $recvLines[] = $L;
            }
        ?>
        <details class="shr-manual" id="shr-manual-toggle" <?= count($lines) > 1 ? 'open' : '' ?>>
            <summary>
                Manual line entry
                <span class="shr-manual-hint muted small">— for one-off shipments without a BOM</span>
            </summary>

        <style>
            .shr-lines-table th { padding: 8px; font-size: 12px; }
            .shr-lines-table td { padding: 6px 8px; vertical-align: middle; }
            .shr-lines-table input[type="text"],
            .shr-lines-table input[type="number"],
            .shr-lines-table select {
                width: 100%;
                box-sizing: border-box;
                font-size: 13px;
                padding: 7px 10px;
                line-height: 1.3;
                border: 1px solid var(--border);
                border-radius: 4px;
                background: var(--surface);
                color: var(--text);
            }
            .shr-lines-table select { padding-right: 24px; }
            .shr-section-label {
                display: flex; align-items: center; gap: 8px;
                font-size: 12px; font-weight: 700;
                text-transform: uppercase; letter-spacing: 0.06em;
                color: var(--text-muted);
                padding: 14px 0 6px;
                margin-top: 10px;
                border-top: 1px solid var(--border);
            }
            .shr-section-label:first-of-type { border-top: none; margin-top: 6px; }
        </style>

        <?php foreach ([
            ['kind' => 'ship',    'bodyId' => 'shr-ship-body', 'addId' => 'shr-ship-add',
             'icon' => '↑', 'iconColor' => 'var(--warn, #b45309)',
             'label' => 'Ship lines', 'hint' => '— items going OUT to the vendor'],
            ['kind' => 'receive', 'bodyId' => 'shr-recv-body', 'addId' => 'shr-recv-add',
             'icon' => '↓', 'iconColor' => 'var(--success, #1e7b30)',
             'label' => 'Receive lines', 'hint' => '— items coming IN from the vendor'],
        ] as $sec):
            $secKind  = $sec['kind'];
            $secLines = ($secKind === 'ship' ? $shipLines : $recvLines);
            $secLines[] = null;   // one trailing empty row for the "add" clone template
        ?>

        <div class="shr-section-label">
            <span style="color: <?= $sec['iconColor'] ?>; font-size: 14px;"><?= $sec['icon'] ?></span>
            <span><?= $sec['label'] ?></span>
            <span class="muted small" style="font-weight:400; text-transform:none; letter-spacing:0;"><?= $sec['hint'] ?></span>
        </div>

        <table class="data-table shr-lines-table">
            <thead>
                <tr>
                    <th style="width: 100px;">Type</th>
                    <th>Item / Asset</th>
                    <th class="r" style="width: 90px;">Qty</th>
                    <th style="width: 90px;">UOM</th>
                    <?php if ($secKind === 'receive'): ?>
                        <th class="r" style="width: 110px;">Unit Price</th>
                        <th class="r" style="width: 70px;">GST %</th>
                    <?php endif; ?>
                    <?php if ($secKind === 'ship'): ?>
                        <th style="width: 150px;">Source</th>
                        <th style="width: 130px;">Before date</th>
                    <?php else: ?>
                        <th style="width: 130px;">Delivery date</th>
                    <?php endif; ?>
                    <th>Notes</th>
                    <th style="width: 44px;"></th>
                </tr>
            </thead>
            <tbody id="<?= h($sec['bodyId']) ?>">
            <?php foreach ($secLines as $L):
                $isExisting = is_array($L);
                $lLineId  = $isExisting ? (int)$L['id']                 : 0;
                $lItemId  = $isExisting ? (int)$L['item_id']            : 0;
                $lQty     = $isExisting ? rtrim(rtrim(number_format((float)$L['qty_planned'], 3, '.', ''), '0'), '.') : '';
                $lSrc     = $isExisting ? (int)($L['src_location_id']  ?? 0) : 0;
                $lNote    = $isExisting ? ($L['notes']                  ?? '') : '';
                $lEntity  = $isExisting ? (string)($L['entity_type']   ?? 'inv_item') : 'inv_item';
                $lAssetId = $isExisting ? (int)($L['asset_id']         ?? 0) : 0;
                $lPending = $isExisting ? (string)($L['pending_name']  ?? '') : '';
                $lPendUom = $isExisting ? (int)($L['pending_uom_id']   ?? 0) : 0;
                $lUomId   = $isExisting ? (int)($L['uom_id']           ?? $lPendUom) : 0;
                $lPrice   = $isExisting ? ($L['unit_price'] !== null ? rtrim(rtrim(number_format((float)$L['unit_price'], 4, '.', ''), '0'), '.') : '') : '';
                $lGst     = $isExisting ? ($L['gst_rate']   !== null ? rtrim(rtrim(number_format((float)$L['gst_rate'],  2, '.', ''), '0'), '.') : '') : '';
                $lBefore  = $isExisting ? (string)($L['before_date']   ?? '') : '';
                $lDelivery= $isExisting ? (string)($L['delivery_date'] ?? '') : '';
                $lSubType = $lEntity === 'asset'
                          ? 'asset'
                          : (!$lItemId && $lPending !== '' ? 'pending' : 'item');
            ?>
                <tr class="shr-line-row" data-subtype="<?= h($lSubType) ?>">
                    <!-- line_id[] for UPDATE/INSERT/DELETE diff on amendment. 0 = new row. -->
                    <input type="hidden" name="line_id[]"   value="<?= (int)$lLineId ?>">
                    <!-- kind is fixed per section — hidden input, not a dropdown -->
                    <input type="hidden" name="line_kind[]" value="<?= h($secKind) ?>" class="shr-line-kind">
                    <?php if ($secKind === 'receive'): ?>
                        <!-- receive rows have no source; emit hidden to keep parallel arrays aligned -->
                        <input type="hidden" name="line_src_location_id[]" value="">
                    <?php endif; ?>
                    <td>
                        <select class="no-combobox shr-line-subtype">
                            <option value="item"    <?= $lSubType === 'item'    ? 'selected' : '' ?>>Item</option>
                            <option value="asset"   <?= $lSubType === 'asset'   ? 'selected' : '' ?>>Asset</option>
                            <option value="pending" <?= $lSubType === 'pending' ? 'selected' : '' ?>>New item</option>
                        </select>
                        <input type="hidden" name="line_entity_type[]"
                               value="<?= $lSubType === 'asset' ? 'asset' : 'inv_item' ?>">
                    </td>
                    <td>
                        <!-- Always wrap all three slots so applySubtype() can reliably
                             show/hide each one. Initial display is set by $lSubType. -->
                        <span class="shr-slot shr-slot-item"
                              style="<?= $lSubType === 'item' ? '' : 'display:none;' ?>">
                            <select name="line_item_id[]" class="shr-line-item">
                                <option value="">— pick an item —</option>
                                <?php foreach ($itemOpts as $opt): ?>
                                    <option value="<?= (int)$opt['id'] ?>"
                                            <?= (int)$opt['id'] === $lItemId ? 'selected' : '' ?>>
                                        <?= h($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </span>

                        <span class="shr-slot shr-slot-asset"
                              style="<?= $lSubType === 'asset' ? '' : 'display:none;' ?>">
                            <select name="line_asset_id[]" class="shr-line-asset">
                                <option value="">— pick an asset —</option>
                                <?php foreach ($allAssets as $A): ?>
                                    <option value="<?= (int)$A['id'] ?>"
                                            <?= (int)$A['id'] === $lAssetId ? 'selected' : '' ?>>
                                        <?= h($A['asset_tag']) ?><?php if ($A['model_name']): ?> — <?= h($A['model_name']) ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </span>

                        <span class="shr-slot shr-slot-pending"
                              style="<?= $lSubType === 'pending' ? 'display:flex;' : 'display:none;' ?> gap: 6px;">
                            <input type="text" name="line_pending_name[]" maxlength="190"
                                   class="shr-line-pending"
                                   placeholder="Name of new item (created on receipt)"
                                   value="<?= h($lPending) ?>" style="flex: 1;">
                            <select name="line_pending_uom_id[]" class="no-combobox shr-line-pending-uom"
                                    style="flex: 0 0 110px;">
                                <option value="">UOM</option>
                                <?php foreach ($allUoms as $U): ?>
                                    <option value="<?= (int)$U['id'] ?>"
                                            <?= (int)$U['id'] === $lPendUom ? 'selected' : '' ?>>
                                        <?= h($U['code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    </td>
                    <td class="r">
                        <input type="number" step="0.001" min="0" class="r"
                               name="line_qty[]" value="<?= h($lQty) ?>" placeholder="0">
                    </td>
                    <td>
                        <select name="line_uom_id[]" class="no-combobox shr-line-uom">
                            <option value="">— UOM —</option>
                            <?php foreach ($allUoms as $U): ?>
                                <option value="<?= (int)$U['id'] ?>"
                                        <?= (int)$U['id'] === $lUomId ? 'selected' : '' ?>>
                                    <?= h($U['code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <?php if ($secKind === 'receive'): ?>
                        <td class="r">
                            <input type="number" step="0.0001" min="0" class="r"
                                   name="line_unit_price[]" value="<?= h($lPrice) ?>" placeholder="0.00">
                        </td>
                        <td class="r">
                            <input type="number" step="0.01" min="0" max="100" class="r"
                                   name="line_gst_rate[]" value="<?= h($lGst) ?>" placeholder="0">
                        </td>
                    <?php else: ?>
                        <!-- price/gst not used on ship lines; hidden to keep parallel arrays aligned -->
                        <input type="hidden" name="line_unit_price[]" value="">
                        <input type="hidden" name="line_gst_rate[]" value="">
                    <?php endif; ?>
                    <?php if ($secKind === 'ship'): ?>
                        <td>
                            <select name="line_src_location_id[]" class="shr-line-src">
                                <option value="">— pick a source —</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= (int)$loc['id'] ?>"
                                            <?= (int)$loc['id'] === $lSrc ? 'selected' : '' ?>>
                                        <?= h($loc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php endif; ?>
                    <?php if ($secKind === 'ship'): ?>
                        <td>
                            <input type="date" name="line_before_date[]" value="<?= h($lBefore) ?>">
                        </td>
                        <!-- delivery date not used on ship lines; emit hidden to keep parallel arrays aligned -->
                        <input type="hidden" name="line_delivery_date[]" value="">
                    <?php else: ?>
                        <!-- before date not used on receive lines; emit hidden to keep parallel arrays aligned -->
                        <input type="hidden" name="line_before_date[]" value="">
                        <td>
                            <input type="date" name="line_delivery_date[]" value="<?= h($lDelivery) ?>">
                        </td>
                    <?php endif; ?>
                    <td>
                        <input type="text" maxlength="255" name="line_notes[]"
                               value="<?= h($lNote) ?>">
                    </td>
                    <td class="r">
                        <button type="button" class="btn btn-icon btn-danger shr-line-remove" title="Remove">🗑</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top: 8px;">
            <button type="button" class="btn btn-ghost btn-sm" id="<?= h($sec['addId']) ?>">
                + Add <?= $secKind === 'ship' ? 'ship' : 'receive' ?> line
            </button>
        </div>

        <?php endforeach; // end ship / receive sections ?>

        </details><!-- /.shr-manual -->

            </div><!-- /.shr-step-body -->
        </section><!-- /STEP 2 -->

        <!-- ============================================================
             STEP 3 — Notes & submit
             ============================================================ -->
        <section class="shr-step">
            <div class="shr-step-head">
                <span class="shr-step-num">3</span>
                <div>
                    <h3 class="shr-step-title">Notes <span class="muted small">(optional)</span></h3>
                    <p class="shr-step-help">Any context for whoever processes this shipment.</p>
                </div>
            </div>
            <div class="shr-step-body">
                <div class="field" style="margin: 0;">
                    <label for="f_notes" class="visually-hidden">Notes</label>
                    <textarea id="f_notes" name="notes" rows="3" placeholder="e.g. handle with care, expected freight by 3 PM, etc."><?= h($vNotes) ?></textarea>
                </div>
            </div>
        </section>

        <?php
            // Phase C — surface the blank-price system note on the
            // shipment form itself so the operator sees it BEFORE save,
            // not just on the printed PO. We check the receive-side
            // table (where prices live) for any blank/zero entries.
            // Only meaningful for an existing shipment ($id > 0).
            if ($id > 0 && po_has_blank_priced_lines($id)):
                $blankNote = magdyn_setting('shiprcpt.system_note_blank_price', '');
                if ($blankNote !== ''):
        ?>
            <div class="alert alert-warn" style="margin: 16px 0;">
                <strong>Heads-up:</strong> <?= h($blankNote) ?>
                <div class="muted small" style="margin-top:4px;">
                    The same note will print on the PO if line prices are not entered before the PO is shared.
                </div>
            </div>
        <?php endif; endif; ?>

        <div class="form-actions" style="margin-top: 16px;">
            <button type="submit" class="btn btn-primary btn-lg">
                <?= $id ? '💾 Save changes' : '✓ Create shipment' ?>
            </button>
        </div>
    </form>

    <script>
    (function () {
        // Two separate tbodies — one per direction.
        var shipBody = document.getElementById('shr-ship-body');
        var recvBody = document.getElementById('shr-recv-body');
        if (!shipBody && !recvBody) return;

        var stockUrl = <?= json_encode(url('/inventory_shiprcpt.php?action=stock_by_location')) ?>;

        // Per-item stock cache — avoids redundant XHR for the same item.
        var stockCache = {};

        function fetchStockByLocation(itemId, cb) {
            if (!itemId) { cb([]); return; }
            if (stockCache[itemId]) { cb(stockCache[itemId]); return; }
            var xhr = new XMLHttpRequest();
            xhr.open('GET', stockUrl + '&item_id=' + itemId, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                if (xhr.status !== 200) { cb([]); return; }
                try {
                    var data = JSON.parse(xhr.responseText);
                    var locs = (data.ok ? data.locations : []) || [];
                    stockCache[itemId] = locs;
                    cb(locs);
                } catch (e) { cb([]); }
            };
            xhr.send();
        }

        function fmtQty(n) {
            return (Math.round(n * 1000) / 1000).toString();
        }

        // Rebuild Source dropdown for a ship row from the stock-by-loc response.
        function rebuildSourceOptions(row, locs) {
            var srcSel = row.querySelector('.shr-line-src');
            if (!srcSel) return;   // receive rows have no source select
            var prevVal = srcSel.value;
            var wrap = srcSel.closest('.cb-wrap');
            if (wrap && wrap.parentNode) {
                wrap.parentNode.insertBefore(srcSel, wrap);
                wrap.parentNode.removeChild(wrap);
            }
            srcSel.classList.remove('cb-bound', 'cb-native');
            srcSel.innerHTML = '';

            var placeholderOpt = document.createElement('option');
            placeholderOpt.value = '';
            if (locs.length === 0) {
                placeholderOpt.textContent = '— no stock available —';
                placeholderOpt.disabled = true;
            } else {
                placeholderOpt.textContent = '— pick a source —';
            }
            srcSel.appendChild(placeholderOpt);

            locs.forEach(function (loc) {
                var opt = document.createElement('option');
                opt.value = loc.id;
                opt.textContent = loc.name + ' (' + fmtQty(loc.qty) + ')';
                if (String(loc.id) === String(prevVal)) opt.selected = true;
                srcSel.appendChild(opt);
            });
            if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                window.MagDynCombobox.initAll(row);
            }
        }

        // Refresh the source dropdown when the item changes (ship rows only).
        function refreshRowSource(row) {
            var kindEl = row.querySelector('.shr-line-kind');
            if (!kindEl || kindEl.value !== 'ship') return;   // receive rows: skip
            var itemEl = row.querySelector('.shr-line-item');
            var itemId = itemEl ? parseInt(itemEl.value || '0', 10) : 0;
            if (!itemId) { rebuildSourceOptions(row, []); return; }
            fetchStockByLocation(itemId, function (locs) { rebuildSourceOptions(row, locs); });
        }

        // For ship rows, ensure the source select is enabled and styled normally.
        // Receive rows have no .shr-line-src so this is a no-op for them.
        function syncRow(row) {
            var srcSel = row.querySelector('.shr-line-src');
            if (!srcSel) return;   // receive row — nothing to sync
            srcSel.disabled = false;
            srcSel.tabIndex  = 0;
            var wrap = srcSel.closest('.cb-wrap');
            if (wrap) {
                wrap.style.opacity       = '';
                wrap.style.pointerEvents = '';
                var visibleInput = wrap.querySelector('input.cb-input');
                if (visibleInput) visibleInput.tabIndex = 0;
            }
            if (window.MagDynCombobox && typeof window.MagDynCombobox.resync === 'function') {
                window.MagDynCombobox.resync(srcSel);
            }
        }

        function wireRow(row) {
            if (row._wired) return;
            row._wired = true;
            syncRow(row);

            // On first paint (edit mode) refresh source if item is already set.
            var kindEl  = row.querySelector('.shr-line-kind');
            var itemEl  = row.querySelector('.shr-line-item');
            if (kindEl && kindEl.value === 'ship' && itemEl && parseInt(itemEl.value || '0', 10) > 0) {
                refreshRowSource(row);
            }

            // Kind is fixed per section (hidden input) — no change listener needed.
            if (itemEl) {
                itemEl.addEventListener('change', function () { refreshRowSource(row); });
            }

            row.querySelector('.shr-line-remove').addEventListener('click', function () {
                row.parentNode.removeChild(row);
                if (window.__shrRecomputeModePill) window.__shrRecomputeModePill();
            });

            // Subtype switcher (Item / Asset / New item).
            var subSel = row.querySelector('.shr-line-subtype');
            if (subSel) {
                var entHidden = row.querySelector('input[name="line_entity_type[]"]');
                var slots = {
                    item:    row.querySelector('.shr-slot-item'),
                    asset:   row.querySelector('.shr-slot-asset'),
                    pending: row.querySelector('.shr-slot-pending')
                };
                function applySubtype() {
                    var v = subSel.value;
                    Object.keys(slots).forEach(function (k) {
                        if (!slots[k]) return;
                        slots[k].style.display = (k === v) ? '' : 'none';
                    });
                    if (entHidden) entHidden.value = (v === 'asset') ? 'asset' : 'inv_item';
                    row.setAttribute('data-subtype', v);
                }
                subSel.addEventListener('change', applySubtype);
                applySubtype();
            }
        }

        // Clone the last row of a given tbody, clear its values, append + wire it.
        function cloneEmptyRow(tbodyEl) {
            var rows = tbodyEl.querySelectorAll('tr.shr-line-row');
            var tmpl;
            if (rows.length > 0) {
                tmpl = rows[rows.length - 1].cloneNode(true);
            } else {
                // Section is empty — borrow a template row from the other body.
                var other = (tbodyEl === shipBody) ? recvBody : shipBody;
                var otherRows = other ? other.querySelectorAll('tr.shr-line-row') : [];
                if (!otherRows.length) return null;
                tmpl = otherRows[0].cloneNode(true);
                // Fix the kind hidden input to match this section.
                var kindHid = tmpl.querySelector('.shr-line-kind');
                if (kindHid) kindHid.value = (tbodyEl === shipBody) ? 'ship' : 'receive';
            }
            // Clear user-entered values.
            tmpl.querySelectorAll('input').forEach(function (inp) {
                if (inp.type === 'number' || inp.type === 'text' || inp.type === 'date') inp.value = '';
            });
            // Reset line_id to 0 so save handler INSERTs instead of UPDATEs.
            var lidInput = tmpl.querySelector('input[name="line_id[]"]');
            if (lidInput) lidInput.value = '0';
            tmpl.querySelectorAll('select').forEach(function (sel) {
                sel.classList.remove('cb-bound', 'cb-native');
                sel.disabled     = false;
                sel.style.opacity = '';
                sel.selectedIndex = 0;
            });
            tmpl.querySelectorAll('.cb-wrap').forEach(function (wrap) {
                var sel = wrap.querySelector('select');
                if (sel) { wrap.parentNode.insertBefore(sel, wrap); wrap.parentNode.removeChild(wrap); }
            });
            tmpl._wired = false;
            tbodyEl.appendChild(tmpl);
            wireRow(tmpl);
            return tmpl;
        }

        // "Add ship line" button.
        if (shipBody) {
            var shipAddBtn = document.getElementById('shr-ship-add');
            if (shipAddBtn) {
                shipAddBtn.addEventListener('click', function () {
                    cloneEmptyRow(shipBody);
                    if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                        window.MagDynCombobox.initAll();
                    }
                    if (window.__shrRecomputeModePill) window.__shrRecomputeModePill();
                });
            }
            shipBody.querySelectorAll('tr.shr-line-row').forEach(wireRow);
        }

        // "Add receive line" button.
        if (recvBody) {
            var recvAddBtn = document.getElementById('shr-recv-add');
            if (recvAddBtn) {
                recvAddBtn.addEventListener('click', function () {
                    cloneEmptyRow(recvBody);
                    if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                        window.MagDynCombobox.initAll();
                    }
                    if (window.__shrRecomputeModePill) window.__shrRecomputeModePill();
                });
            }
            recvBody.querySelectorAll('tr.shr-line-row').forEach(wireRow);
        }

        // setRowField helper — sets a select or input value, resyncs combobox.
        function setRowField(row, selector, value) {
            var el = row.querySelector(selector);
            if (!el) { console.warn('[shr setRowField] not found:', selector); return; }
            if (el.tagName === 'SELECT') {
                el.value = String(value);
                if (el.value !== String(value)) {
                    var opt = document.createElement('option');
                    opt.value = String(value);
                    opt.textContent = 'Loc #' + value;
                    el.appendChild(opt);
                    el.value = String(value);
                }
                if (window.MagDynCombobox && typeof window.MagDynCombobox.resync === 'function') {
                    window.MagDynCombobox.resync(el);
                }
            } else {
                el.value = String(value);
            }
        }

        // Programmatic line-append used by the BOM checklist panel.
        // Spec: { kind: 'ship'|'receive', itemId, qty, srcId }
        // Routes to the correct tbody based on spec.kind.
        window.__shrAppendLineRow = function (spec) {
            var targetBody = (spec.kind === 'ship') ? shipBody : recvBody;
            if (!targetBody) return null;
            var row = cloneEmptyRow(targetBody);
            if (!row) return null;
            if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                window.MagDynCombobox.initAll(row);
            }
            // Set source BEFORE item so rebuildSourceOptions preserves the value.
            if (spec.srcId && spec.kind === 'ship') setRowField(row, '.shr-line-src', spec.srcId);
            setRowField(row, '.shr-line-item', spec.itemId || '');
            setRowField(row, 'input[name="line_qty[]"]', spec.qty || '');
            syncRow(row);
            return row;
        };

        // Pre-submit diagnostic.
        var mainForm = document.getElementById('shr-main-form');
        if (mainForm) {
            mainForm.addEventListener('submit', function () {
                var k = mainForm.querySelectorAll('input[name="line_kind[]"]');
                var i = mainForm.querySelectorAll('select[name="line_item_id[]"]');
                var q = mainForm.querySelectorAll('input[name="line_qty[]"]');
                var s = mainForm.querySelectorAll('[name="line_src_location_id[]"]');
                console.log('[shiprcpt submit] kinds=' + k.length +
                            ' items=' + i.length + ' qtys=' + q.length + ' srcs=' + s.length);
                console.log('[shiprcpt submit] kinds:',
                    Array.from(k).map(function (e) { return e.value; }).join(','));
            });
        }
    })();

    // Mode pill at top of form is auto-derived from the two direction sections.
    // A section "has content" when at least one of its rows has qty > 0.
    // We check qty (a plain number input) rather than item selects because
    // combobox-enhanced selects don't always fire native change events, and
    // qty correctly captures Asset/Pending subtypes too.
    (function () {
        function bodyHasContent(tbodyId) {
            var tbody = document.getElementById(tbodyId);
            if (!tbody) return false;
            var qtys = tbody.querySelectorAll('input[name="line_qty[]"]');
            for (var i = 0; i < qtys.length; i++) {
                if (parseFloat(qtys[i].value || '0') > 0) return true;
            }
            return false;
        }

        function recomputeModePill() {
            var pill = document.getElementById('shr-mode-pill');
            if (!pill) return;
            var hasShip = bodyHasContent('shr-ship-body');
            var hasRecv = bodyHasContent('shr-recv-body');
            var label, cls, mode;
            if (hasShip && hasRecv) { mode='both';    label='⇅ Ship & receive'; cls='pill pill-info'; }
            else if (hasShip)       { mode='ship';    label='↑ Ship only';      cls='pill pill-warning'; }
            else if (hasRecv)       { mode='receive'; label='↓ Receive only';   cls='pill pill-success'; }
            else                    { mode='';        label='No lines yet';     cls='pill pill-neutral'; }
            pill.textContent = label;
            pill.className = cls;
            pill.setAttribute('data-mode', mode);
        }

        // Initial paint.
        recomputeModePill();

        // Re-run on any qty change — covers typing, spinner, paste, and blur.
        document.addEventListener('input',  function (ev) {
            if (ev.target && ev.target.name === 'line_qty[]') recomputeModePill();
        });
        document.addEventListener('change', function (ev) {
            if (ev.target && ev.target.name === 'line_qty[]') recomputeModePill();
        });

        // Expose for line-add / remove handlers.
        window.__shrRecomputeModePill = recomputeModePill;
    })();

    // -----------------------------------------------------------------
    // Phase C2 — vendor cascade.
    // When the operator changes the vendor select, fetch the new
    // vendor's contacts + addresses and rebuild the dropdowns, with
    // the primary entries pre-selected per PRD §2.1.
    // -----------------------------------------------------------------
    (function () {
        var vendorSel  = document.getElementById('f_vendor');
        var contactSel = document.getElementById('f_vendor_contact');
        var addressSel = document.getElementById('f_vendor_address');
        if (!vendorSel || !contactSel || !addressSel) return;
        var fetchUrl = <?= json_encode(url('/inventory_shiprcpt.php?action=vendor_data')) ?>;

        function repopulate(sel, items, labelFn, autoPickPrimary) {
            // Tear down any existing combobox wrapper first; combobox.js
            // will re-attach when MagDynCombobox.initAll() runs.
            sel.classList.remove('cb-bound', 'cb-native');
            var wrap = sel.parentNode && sel.parentNode.classList && sel.parentNode.classList.contains('cb-wrap')
                     ? sel.parentNode : null;
            if (wrap && wrap.parentNode) { wrap.parentNode.insertBefore(sel, wrap); wrap.parentNode.removeChild(wrap); }

            sel.innerHTML = '';
            var ph = document.createElement('option');
            ph.value = ''; ph.textContent = '— primary —';
            sel.appendChild(ph);
            var picked = null;
            items.forEach(function (it) {
                var o = document.createElement('option');
                o.value = String(it.id);
                o.textContent = labelFn(it);
                if (autoPickPrimary && it.is_primary && picked === null) {
                    o.selected = true; picked = it.id;
                }
                sel.appendChild(o);
            });
        }

        vendorSel.addEventListener('change', function () {
            var vid = parseInt(vendorSel.value || '0', 10);
            if (!vid) {
                repopulate(contactSel, [], function () { return ''; }, false);
                repopulate(addressSel, [], function () { return ''; }, false);
                return;
            }
            fetch(fetchUrl + '&vendor_id=' + vid, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var contacts  = (data && data.contacts)  || [];
                    var addresses = (data && data.addresses) || [];
                    repopulate(contactSel, contacts, function (c) {
                        var label = (c.designation ? c.designation + ' ' : '') + (c.name || '');
                        return label.trim() + (c.is_primary ? ' (primary)' : '');
                    }, true);
                    repopulate(addressSel, addresses, function (a) {
                        var lbl = a.label || a.line1 || '';
                        return lbl + (a.city ? ' · ' + a.city : '');
                    }, true);
                    if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                        window.MagDynCombobox.initAll();
                    }
                })
                .catch(function (err) {
                    console.warn('[shr vendor cascade] fetch failed:', err);
                });
        });
    })();

    // -----------------------------------------------------------------
    // Add-receive-item panel logic.
    //   1. User picks an item + qty (multiplier) + optional default src
    //   2. Click "Load BOM →" → AJAX GET /inventory_shiprcpt.php?action=bom_for_receive_item
    //   3. We render a small form (POST) with a checklist of BOM children
    //   4. On submit, server appends one receive line for the parent + N
    //      ship lines for the checked children; redirects back to edit.
    // -----------------------------------------------------------------
    (function () {
        var btnLoad   = document.getElementById('shr-load-bom');
        var itemSel   = document.getElementById('shr-recv-item');
        var multInput = document.getElementById('shr-recv-mult');
        var listHost  = document.getElementById('shr-bom-checklist');
        if (!btnLoad || !itemSel || !listHost) return;

        var shipmentId = <?= (int)$id ?>;
        var csrfToken  = <?= json_encode(csrf_token()) ?>;
        var commitUrl  = <?= json_encode(url('/inventory_shiprcpt.php?action=add_from_bom_checklist')) ?>;
        var fetchUrl   = <?= json_encode(url('/inventory_shiprcpt.php?action=bom_for_receive_item')) ?>;

        function fmt(n) {
            // Trim trailing zeros and decimal point for display
            return (Math.round(n * 1000) / 1000).toString();
        }

        function renderChecklist(data, multiplier) {
            if (!data.ok) {
                listHost.style.display = 'block';
                listHost.innerHTML = '<p class="muted small" style="color:#b3261e;">Could not load BOM: '
                                   + (data.reason || 'unknown error') + '</p>';
                return;
            }
            var p = data.parent;
            var kids = data.children || [];
            if (!kids.length) {
                // Parent has no BOM. Still allow adding a receive-only line.
                // Render as <div> (not <form>) for the same nested-form
                // reason as the main checklist below.
                listHost.style.display = 'block';
                listHost.innerHTML =
                    '<p class="muted small">This item has no BOM. A receive line will still be added; no ship lines.</p>' +
                    '<div id="shr-bom-form-noBom" style="margin-top:8px;">' +
                    '<button type="button" class="btn btn-primary btn-sm" id="shr-bom-add-noBom">Add to list</button> ' +
                    '<button type="button" class="btn btn-ghost btn-sm" id="shr-bom-cancel">Cancel</button>' +
                    '</div>';
                document.getElementById('shr-bom-cancel').addEventListener('click', function () {
                    listHost.style.display = 'none';
                    listHost.innerHTML = '';
                });
                document.getElementById('shr-bom-add-noBom').addEventListener('click', function () {
                    var panel = document.getElementById('shr-bom-form-noBom');
                    if (shipmentId === 0) {
                        bufferLinesClientSide(p, [], multiplier, panel);
                    } else {
                        submitChecklistToServer(p, [], multiplier, panel);
                    }
                });
                return;
            }
            // Render the checklist as a <div> (NOT a <form>) so the
            // browser doesn't auto-close the parent main-form at the
            // checklist's opening tag — HTML disallows nested forms,
            // and nesting orphans every element after the inner </form>
            // from the parent form, breaking the eventual save POST.
            // The "Add to list" button is type=button; the click handler
            // either runs the client-side buffer (new shipments) or
            // builds a hidden form on the fly and submits it (existing
            // drafts).
            var html = '';
            html += '<p class="muted small">BOM of <strong>' + p.code + '</strong> — ' + p.label
                  + ' &nbsp;(receive qty <strong>' + fmt(multiplier) + '</strong> ' + (p.uom_label || '') + ')</p>';
            html += '<div id="shr-bom-form">';
            html += '<table class="data-table" style="margin:6px 0;">';
            html += '<thead><tr>'
                  + '<th style="width:40px;">Ship</th>'
                  + '<th>Code</th><th>Name</th>'
                  + '<th class="r" style="width:90px;">BOM qty</th>'
                  + '<th class="r" style="width:110px;">Ship qty</th>'
                  + '<th style="width:60px;">UoM</th>'
                  + '<th style="width:220px;">Source location</th>'
                  + '</tr></thead><tbody>';
            kids.forEach(function (c) {
                var shipQty = c.bom_qty * multiplier;
                var srcHtml = '';
                var stockLocs = c.stock_by_loc || [];
                if (stockLocs.length === 0) {
                    srcHtml = '<span class="pill pill-warn" title="No stock available for this item">⚠ no stock</span>'
                            + '<input type="hidden" name="child_src_' + c.id + '" value="0">';
                } else {
                    srcHtml += '<select name="child_src_' + c.id + '" class="shr-bom-src" '
                             + 'style="width:100%;">';
                    srcHtml += '<option value="">— pick source —</option>';
                    stockLocs.forEach(function (loc) {
                        srcHtml += '<option value="' + loc.location_id + '">'
                                 + loc.name + ' (' + fmt(loc.qty) + ' ' + (c.uom_label || '') + ')'
                                 + '</option>';
                    });
                    srcHtml += '</select>';
                }
                var disabledHint = stockLocs.length === 0
                    ? ' title="No stock anywhere — uncheck to skip this ship line"'
                    : '';
                html += '<tr data-bom-row="' + c.id + '">';
                html += '<td><input type="checkbox" name="child_include[]" value="' + c.id + '"'
                      + (stockLocs.length === 0 ? '' : ' checked') + disabledHint + '></td>';
                html += '<td><code>' + c.code + '</code></td>';
                html += '<td>' + c.label + '</td>';
                html += '<td class="r">' + fmt(c.bom_qty) + '</td>';
                html += '<td class="r"><input type="number" name="child_qty_' + c.id + '" value="' + fmt(shipQty)
                      +    '" step="0.001" min="0" style="width:100%;"></td>';
                html += '<td class="muted small">' + (c.uom_label || '') + '</td>';
                html += '<td>' + srcHtml + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '<div style="margin-top:6px; display:flex; gap:8px;">';
            html += '<button type="button" class="btn btn-primary btn-sm" id="shr-bom-add">Add to list</button> ';
            html += '<button type="button" class="btn btn-ghost btn-sm" id="shr-bom-cancel">Cancel</button>';
            html += '<span class="muted small" style="margin-left:auto; align-self:center;">'
                  + 'Uncheck rows to skip · pick a source location for each ship line (only locations with stock are shown).</span>';
            html += '</div>';
            html += '</div>';
            listHost.style.display = 'block';
            listHost.innerHTML = html;
            if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                window.MagDynCombobox.initAll(listHost);
            }
            document.getElementById('shr-bom-cancel').addEventListener('click', function () {
                listHost.style.display = 'none';
                listHost.innerHTML = '';
            });
            // "Add to list" click handler. Two paths:
            //   - New shipment (shipmentId === 0): buffer rows client-side
            //     into the manual lines table; nothing posts yet.
            //   - Existing draft (shipmentId > 0): build a temp <form>
            //     OUTSIDE the main form, mirror this panel's inputs onto
            //     it, submit. Server adds rows + redirects back to edit.
            document.getElementById('shr-bom-add').addEventListener('click', function () {
                var panel = document.getElementById('shr-bom-form');
                if (shipmentId === 0) {
                    bufferLinesClientSide(p, kids, multiplier, panel);
                } else {
                    submitChecklistToServer(p, kids, multiplier, panel);
                }
            });
        }

        // Existing-draft submit path. Constructs a hidden <form> as a
        // sibling of the main shipment form (NOT inside it), populates
        // it with the checklist's current state, and submits to the
        // server's add_from_bom_checklist endpoint. Must be a sibling
        // because nesting forms breaks the main shipment form.
        function submitChecklistToServer(parent, kids, multiplier, panel) {
            var f = document.createElement('form');
            f.method = 'post';
            f.action = commitUrl;
            f.style.display = 'none';
            // Append BEFORE the main form so it's a sibling, not nested.
            var mainForm = document.getElementById('shr-main-form');
            if (mainForm && mainForm.parentNode) {
                mainForm.parentNode.insertBefore(f, mainForm);
            } else {
                document.body.appendChild(f);
            }
            function addHidden(name, value) {
                var i = document.createElement('input');
                i.type = 'hidden'; i.name = name; i.value = String(value);
                f.appendChild(i);
            }
            addHidden('csrf_token', csrfToken);
            addHidden('id', shipmentId);
            addHidden('parent_item_id', parent.id);
            addHidden('parent_qty', multiplier);
            // Mirror every named input in the panel onto the temp form
            // (checkboxes, qty inputs, src selects). querySelectorAll
            // returns elements in document order so parallel arrays
            // align correctly.
            panel.querySelectorAll('[name]').forEach(function (el) {
                if (el.type === 'checkbox') {
                    if (el.checked) addHidden(el.name, el.value);
                } else {
                    addHidden(el.name, el.value);
                }
            });
            f.submit();
        }

        // Client-side buffering path (new shipments). Reads the checklist
        // state from the rendered panel, calls __shrAppendLineRow for the
        // parent + each checked child, then clears the panel.
        //
        // Each ship line gets its source location from the per-row
        // child_src_<id> select that the checklist renders — only
        // locations with positive stock for THAT child are offered.
        function bufferLinesClientSide(parent, kids, multiplier, form) {
            if (!window.__shrAppendLineRow) {
                listHost.innerHTML = '<p class="muted small" style="color:#b3261e;">'
                                   + 'Lines table not ready. Reload the page and try again.</p>';
                return;
            }
            // 1. Receive line for the parent
            window.__shrAppendLineRow({
                kind:   'receive',
                itemId: parent.id,
                qty:    multiplier,
            });
            // 2. Ship lines for each CHECKED child
            var checkedIds = {};
            form.querySelectorAll('input[name="child_include[]"]:checked').forEach(function (cb) {
                checkedIds[cb.value] = true;
            });
            var shipLinesAdded = 0;
            var missingSrc = [];
            kids.forEach(function (c) {
                if (!checkedIds[String(c.id)]) return;
                var qtyInput = form.querySelector('input[name="child_qty_' + c.id + '"]');
                var srcInput = form.querySelector('[name="child_src_' + c.id + '"]');
                var qty   = qtyInput ? parseFloat(qtyInput.value || '0') : (c.bom_qty * multiplier);
                var srcId = srcInput ? parseInt(srcInput.value || '0', 10) : 0;
                console.log('[shr buffer] child', c.id, c.code,
                            'srcInput=', srcInput,
                            'srcInput.value=', srcInput ? srcInput.value : '(no input)',
                            'parsed srcId=', srcId);
                if (!qty || qty <= 0) return;
                if (!srcId) {
                    missingSrc.push(c.code);
                    return;
                }
                window.__shrAppendLineRow({
                    kind:   'ship',
                    itemId: c.id,
                    qty:    qty,
                    srcId:  srcId,
                });
                shipLinesAdded++;
            });
            if (missingSrc.length > 0) {
                listHost.style.display = 'block';
                var existingForm = document.getElementById('shr-bom-form');
                if (existingForm) {
                    // Restore the form and show an error banner above it
                    var msg = document.createElement('p');
                    msg.className = 'muted small';
                    msg.style.color = '#b3261e';
                    msg.textContent = '⚠ Pick a source location for: ' + missingSrc.join(', ');
                    existingForm.parentNode.insertBefore(msg, existingForm);
                }
                return;
            }
            // 3. Sync mode pill + finalize: reset panel + flash feedback
            if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                window.MagDynCombobox.initAll();
            }
            if (window.__shrRecomputeModePill) window.__shrRecomputeModePill();
            listHost.style.display = 'block';
            listHost.innerHTML = '<p class="muted small" style="color:var(--success, #1e7b30);">'
                               + '✓ Added receive line for <strong>' + parent.code + '</strong>'
                               + (shipLinesAdded > 0
                                   ? ' along with ' + shipLinesAdded + ' ship line' + (shipLinesAdded === 1 ? '' : 's')
                                   : '')
                               + '. They\'ll save when you click Create shipment.</p>';
            // Reset the picker so the user can add another receive item
            itemSel.selectedIndex = 0;
            multInput.value = '1';
            // Auto-hide the success message after 4s
            setTimeout(function () {
                if (listHost.innerHTML.indexOf('✓ Added') !== -1) {
                    listHost.style.display = 'none';
                    listHost.innerHTML = '';
                }
            }, 4000);
        }

        btnLoad.addEventListener('click', function () {
            var itemId = parseInt(itemSel.value || '0', 10);
            var mult   = parseFloat(multInput.value || '0');
            if (!itemId) { itemSel.focus(); return; }
            if (!mult || mult <= 0) { multInput.focus(); return; }
            listHost.style.display = 'block';
            listHost.innerHTML = '<p class="muted small">Loading BOM…</p>';
            var url = fetchUrl + '&item_id=' + itemId;
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                if (xhr.status !== 200) {
                    listHost.innerHTML = '<p class="muted small" style="color:#b3261e;">Failed to load BOM (HTTP '
                                       + xhr.status + '). URL: <code>' + url + '</code></p>';
                    return;
                }
                try {
                    var data = JSON.parse(xhr.responseText);
                    renderChecklist(data, mult);
                } catch (e) {
                    var snippet = (xhr.responseText || '').slice(0, 200);
                    listHost.innerHTML = '<p class="muted small" style="color:#b3261e;">Bad JSON response: '
                                       + e.message + '<br>Response starts with: <code>'
                                       + snippet.replace(/</g, '&lt;') + '</code></p>';
                }
            };
            xhr.send();
        });
    })();
    </script>

    <?php require __DIR__ . '/includes/footer.php'; exit; }

// ----------------------------------------------------------------
// VIEW — detail page
// ----------------------------------------------------------------
if ($action === 'view') {
    $id = (int)input('id', 0);
    $sh = db_one('SELECT * FROM inv_shipments WHERE id = ?', [$id]);
    if (!$sh) {
        flash_set('error', 'Shipment not found.');
        redirect(url('/inventory_shiprcpt.php'));
    }
    $vendor   = db_one('SELECT code, name FROM vendors WHERE id = ?', [(int)$sh['vendor_id']]);
    $approver = $sh['approved_by'] ? db_one('SELECT full_name FROM users WHERE id = ?', [(int)$sh['approved_by']]) : null;
    $shipper  = $sh['shipped_by']  ? db_one('SELECT full_name FROM users WHERE id = ?', [(int)$sh['shipped_by']])  : null;
    $lines = db_all(
        'SELECT sl.*, i.code AS item_code,
                COALESCE(NULLIF(i.short_description, ""), i.name, sl.pending_name) AS item_label,
                i.uom AS item_uom,
                l.name AS src_location_name
           FROM inv_shipment_lines sl
      LEFT JOIN inv_items i ON i.id = sl.item_id
      LEFT JOIN locations l ON l.id = sl.src_location_id
          WHERE sl.shipment_id = ?
          ORDER BY sl.line_kind, sl.sort_order, sl.id',
        [$id]
    );
    $receipts = db_all(
        'SELECT r.*, sl.item_id, i.code AS item_code,
                COALESCE(NULLIF(i.short_description, ""), i.name, sl.pending_name) AS item_label,
                l.name AS loc_name, u.full_name AS rcvd_by
           FROM inv_receipts r
      LEFT JOIN inv_shipment_lines sl ON sl.id = r.shipment_line_id
      LEFT JOIN inv_items i           ON i.id  = sl.item_id
      LEFT JOIN locations l           ON l.id  = r.dst_location_id
      LEFT JOIN users u               ON u.id  = r.created_by
          WHERE r.shipment_id = ?
          ORDER BY r.receipt_date DESC, r.id DESC',
        [$id]
    );
    $locations = db_all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name');

    // Running notes that were attached, in the old system, to the transactions
    // behind this shipment's lines. Chain:
    //   inv_shipment_lines.old_transaction_id  (legacy transaction header)
    //     → old_inv_txns.old_transaction_id → old_inv_txns.old_id (inventory_transaction_id)
    //     → inv_txns.ref_doc = 'OLD-ITX-<old_id>'
    //     → notes(entity_type='inv_txn', entity_id = inv_txns.id)
    // (inv_notes.tid is an inventory_transaction_id; the importer attached the
    //  note to the matching inv_txn, so we roll those up to the shipment here.)
    $txnNotes = db_all(
        "SELECT DISTINCT n.id, n.body_html, n.created_at, n.entity_id AS inv_txn_id,
                o.old_transaction_id,
                u.full_name AS author_name, u.email AS author_email,
                c.name AS note_type_name
           FROM inv_shipment_lines sl
           JOIN old_inv_txns o ON o.old_transaction_id = sl.old_transaction_id
           JOIN inv_txns t     ON t.ref_doc = CONCAT('OLD-ITX-', o.old_id)
           JOIN notes n        ON n.entity_type = 'inv_txn' AND n.entity_id = t.id AND n.is_deleted = 0
      LEFT JOIN users u        ON u.id = n.author_id
      LEFT JOIN categories c   ON c.id = n.note_type_id
          WHERE sl.shipment_id = ? AND sl.old_transaction_id IS NOT NULL
          ORDER BY n.created_at DESC, n.id DESC",
        [$id]
    );
    $txnNoteAtts = [];
    if ($txnNotes) {
        $in = implode(',', array_map('intval', array_column($txnNotes, 'id')));
        foreach (db_all("SELECT id, note_id, filename FROM note_attachments WHERE note_id IN ($in) ORDER BY note_id, id") as $a) {
            $txnNoteAtts[(int)$a['note_id']][] = $a;
        }
    }

    $hasShipSide    = in_array($sh['mode'], shr_modes_with_ship(), true);
    $hasReceiveSide = in_array($sh['mode'], shr_modes_with_receive(), true);

    // Amend is only meaningful when at least one line is still "planned"
    // (i.e. no event posted yet). If every line already has a receipt or
    // has been shipped, there is nothing left to amend.
    $hasPlannedLines = (int)db_val(
        "SELECT COUNT(*) FROM inv_shipment_lines sl
          WHERE sl.shipment_id = ?
            AND (
                  (sl.line_kind = 'receive'
                   AND NOT EXISTS (SELECT 1 FROM inv_receipts r WHERE r.shipment_line_id = sl.id))
                  OR
                  (sl.line_kind = 'ship' AND (sl.qty_shipped IS NULL OR sl.qty_shipped = 0))
                )",
        [$id], 0
    ) > 0;

    $statusPill = '<span class="pill pill-'
        . ($sh['status'] === 'closed' ? 'active'
            : ($sh['status'] === 'cancelled' ? 'danger'
                : ($sh['status'] === 'shipped' ? 'info' : 'neutral')))
        . '">' . h($sh['status']) . '</span>';

    $page_title  = 'Shipment ' . $sh['ship_no'];
    $page_module = 'inventory_shiprcpt';
    require __DIR__ . '/includes/header.php';

    // Header action bar
    $actions = '';
    if ($canManage) {
        if ($sh['status'] === 'draft') {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inventory_shiprcpt.php?action=approve')) . '">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<button type="submit" class="btn btn-primary btn-sm">✓ Approve</button></form> ';
            $actions .= '<a class="btn btn-ghost btn-sm" href="' . h(url('/inventory_shiprcpt.php?action=edit&id=' . $id)) . '">✎ Edit</a> ';
        }
        if ($sh['status'] === 'approved' && $hasShipSide) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inventory_shiprcpt.php?action=ship')) . '"'
                      . ' onsubmit="var d = prompt(\'Actual ship date (YYYY-MM-DD)?\', \'' . date('Y-m-d') . '\');'
                      . ' if (d === null) return false; this.elements.actual_ship_date.value = d; return confirm(\'Post ship-out? Stock will leave the source locations.\');">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<input type="hidden" name="actual_ship_date" value="">'
                      . '<button type="submit" class="btn btn-primary btn-sm">📦 Mark shipped</button></form> ';
        }
        if (in_array($sh['status'], ['approved', 'shipped'], true)) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inventory_shiprcpt.php?action=close')) . '"'
                      . ' onsubmit="return confirm(\'Close this shipment? No further movements will be allowed.\');">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<button type="submit" class="btn btn-ghost btn-sm">🔒 Close</button></form> ';
        }
        // Phase D1 — Amend opens the form unlocked + flagged so save
        // creates a new PO version. Available on any past-draft, non-
        // cancelled shipment that still has at least one planned line.
        // (Drafts use Edit instead.)
        if (in_array($sh['status'], ['approved', 'shipped', 'closed'], true)) {
            if ($hasPlannedLines) {
                $actions .= '<a class="btn btn-ghost btn-sm" href="'
                          . h(url('/inventory_shiprcpt.php?action=amend&id=' . $id))
                          . '" title="Edit the shipment and update the PO. Previous version kept for audit.">✎ Amend</a> ';
            } else {
                $actions .= '<button class="btn btn-ghost btn-sm" disabled'
                          . ' title="Amend is only available when at least one line is still planned (no receipt or shipment posted yet).">'
                          . '✎ Amend</button> ';
            }
        }
        if (in_array($sh['status'], ['draft', 'approved'], true)) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inventory_shiprcpt.php?action=cancel')) . '"'
                      . ' onsubmit="return confirm(\'Cancel this shipment?\');">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<button type="submit" class="btn btn-ghost btn-sm">✗ Cancel</button></form> ';
        }
        if (in_array($sh['status'], ['draft', 'cancelled'], true)) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/inventory_shiprcpt.php?action=delete')) . '"'
                      . ' onsubmit="return confirm(\'Delete this shipment permanently?\');">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button></form>';
        }
    }
    ?>
    <?= form_toolbar([
        'back_href'    => url('/inventory_shiprcpt.php'),
        'back_label'   => 'Back to list',
        'title'        => 'Shipment ' . h($sh['ship_no']),
        'actions_html' => $actions,
    ]) ?>

    <?php
    // Phase D1 — Purchase Order chain card. Shows every PO version
    // issued against this shipment. v1 from the first save; vN from
    // subsequent Amend actions. The print and (Phase D2) email
    // buttons are per-version since each PO is a distinct artifact.
    $poChain = po_version_chain((int)$id);
    if ($poChain):
        $canPrintPo = permission_check('purchase_orders', 'print');
    ?>
        <div class="card" style="padding: 14px 18px; margin-bottom: 14px;">
            <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:8px;">
                <strong>Purchase Orders</strong>
                <span class="muted small"><?= count($poChain) ?> version<?= count($poChain) === 1 ? '' : 's' ?></span>
            </div>
            <table class="data-table" style="margin: 0;">
                <thead><tr>
                    <th style="width: 60px;">Ver</th>
                    <th>PO No</th>
                    <th>Issued</th>
                    <th>By</th>
                    <th class="r">Actions</th>
                </tr></thead>
                <tbody>
                    <?php foreach (array_reverse($poChain) as $i => $p):
                        $isLatest = ($i === 0);
                    ?>
                        <tr<?= $isLatest ? ' style="font-weight:600;"' : '' ?>>
                            <td>
                                v<?= (int)$p['version'] ?>
                                <?php if ($isLatest && count($poChain) > 1): ?>
                                    <span class="pill pill-info" style="margin-left:4px;">latest</span>
                                <?php elseif (!$isLatest): ?>
                                    <span class="pill pill-muted" style="margin-left:4px;">history</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$p['id'])) ?>">
                                    <code><?= h($p['po_no']) ?></code>
                                </a>
                            </td>
                            <td><?= h(substr((string)$p['created_at'], 0, 16)) ?></td>
                            <td><?= h($p['created_by_name'] ?: '—') ?></td>
                            <td class="r nowrap">
                                <a class="btn btn-icon" href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$p['id'])) ?>" title="<?= $isLatest ? 'View current PO' : 'View historical version' ?>">👁</a>
                                <?php if ($canPrintPo): ?>
                                    <a class="btn btn-icon" target="_blank" href="<?= h(url('/purchase_orders.php?action=print&id=' . (int)$p['id'])) ?>" title="Print v<?= (int)$p['version'] ?>">🖨</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card" style="padding: 18px; margin-bottom: 14px;">
        <div class="grid-2col">
            <div><div class="muted small">Ship #</div><strong><?= h($sh['ship_no']) ?></strong></div>
            <div><div class="muted small">Status</div><?= $statusPill ?></div>
            <div><div class="muted small">Mode</div>
                <?= $sh['mode'] === 'both' ? 'Ship & receive' : ($sh['mode'] === 'ship' ? 'Ship only' : 'Receive only') ?>
                <?= $sh['is_rework'] ? ' <span class="pill pill-warning">rework</span>' : '' ?>
            </div>
            <div><div class="muted small">Vendor</div><?= $vendor ? '<code>' . h($vendor['code']) . '</code> ' . h($vendor['name']) : '—' ?></div>
            <?php if ($approver): ?>
                <div><div class="muted small">Approved by</div><?= h($approver['full_name']) ?> · <?= h(substr((string)$sh['approved_at'], 0, 16)) ?></div>
            <?php endif; ?>
            <?php if ($shipper): ?>
                <div><div class="muted small">Shipped by</div><?= h($shipper['full_name']) ?> · <?= h(substr((string)$sh['shipped_at'], 0, 16)) ?></div>
            <?php endif; ?>
            <?php if ($sh['ref_doc']): ?>
                <div><div class="muted small">Ref</div><?= h($sh['ref_doc']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($sh['notes']): ?>
            <div style="margin-top: 14px;"><div class="muted small">Notes</div><div style="white-space: pre-wrap;"><?= h($sh['notes']) ?></div></div>
        <?php endif; ?>
    </div>

    <!-- Lines -->
    <div class="card" style="padding: 18px; margin-bottom: 14px;">
        <h3 style="margin: 0 0 10px;">Line items</h3>
        <?php if (empty($lines)): ?>
            <p class="muted empty">No lines.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Direction</th>
                        <th>Item</th>
                        <th class="r">Planned</th>
                        <th class="r">Shipped</th>
                        <th class="r">Received</th>
                        <th>Source</th>
                        <th class="r">Txn ID</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $L):
                    $kindBadge = $L['line_kind'] === 'ship'
                        ? '<span class="pill pill-info">ship</span>'
                        : '<span class="pill pill-neutral">receive</span>';
                ?>
                    <tr>
                        <td><?= $kindBadge ?></td>
                        <td>(<?= h($L['item_code']) ?>)-<?= h($L['item_label']) ?></td>
                        <td class="r">
                            <?php
                            if ($L['line_kind'] === 'receive') {
                                // Show remaining (planned − received) so the column reflects
                                // how much is still outstanding, not the original planned qty.
                                $remaining = max(0, (float)$L['qty_planned'] - (float)$L['qty_received']);
                                echo h(rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.'));
                            } else {
                                echo h(rtrim(rtrim(number_format((float)$L['qty_planned'], 3, '.', ''), '0'), '.'));
                            }
                            ?>
                        </td>
                        <td class="r">
                            <?php if ($L['line_kind'] === 'ship'): ?>
                                <?= h(rtrim(rtrim(number_format((float)$L['qty_shipped'], 3, '.', ''), '0'), '.')) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="r">
                            <?php if ($L['line_kind'] === 'receive'): ?>
                                <?= h(rtrim(rtrim(number_format((float)$L['qty_received'], 3, '.', ''), '0'), '.')) ?>
                                <?php
                                $rem = (float)$L['qty_planned'] - (float)$L['qty_received'];
                                if ($rem > 0.0001) echo ' <span class="pill pill-warn" style="font-size:11px;">' . h(rtrim(rtrim(number_format($rem, 3, '.', ''), '0'), '.')) . ' open</span>';
                                else echo ' <span class="pill pill-active">full</span>';
                                ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($L['line_kind'] === 'ship'): ?>
                                <?= h($L['src_location_name'] ?: '—') ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="r">
                            <?php if (!empty($L['old_transaction_id'])): ?>
                                <code title="Legacy transaction.transaction_id this line was imported from"><?= (int)$L['old_transaction_id'] ?></code>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted small"><?= h($L['notes'] ?: '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Receipts -->
    <?php if ($hasReceiveSide): ?>
        <div class="card" style="padding: 18px; margin-bottom: 14px;">
            <h3 style="margin: 0 0 10px;">Receipt history</h3>
            <?php if (empty($receipts)): ?>
                <p class="muted empty" style="text-align: left; padding: 8px 0;">No receipts recorded yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Item</th>
                            <th class="r">Qty</th>
                            <th class="r">Linked</th>
                            <th class="r">Unlinked</th>
                            <th>Receipt date</th>
                            <th>Due</th>
                            <th class="r">Lateness</th>
                            <th>Location</th>
                            <th>By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($receipts as $R):
                        $due = $R['due_date_snapshot'];
                        $rd  = $R['receipt_date'];
                        $lateness = ($due && $rd) ? (int)((strtotime($rd) - strtotime($due)) / 86400) : null;
                        // Per-receipt linked/unlinked qty against invoices.
                        // Helpers from includes/_invoice_links.php.
                        $rcvLinked   = invoice_link_txn_qty_linked('inv', (int)$R['id']);
                        $rcvUnlinked = invoice_link_txn_qty_unlinked('inv', (int)$R['id']);
                        $fmtQty = function ($v) {
                            return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
                        };
                        ?>
                        <tr>
                            <td><code><?= h($R['receipt_no']) ?></code></td>
                            <td>(<?= h($R['item_code']) ?>)-<?= h($R['item_label']) ?></td>
                            <td class="r"><?= h($fmtQty($R['qty_received'])) ?></td>
                            <td class="r"><?php if ($rcvLinked > 0): ?><strong style="color:#059669"><?= h($fmtQty($rcvLinked)) ?></strong><?php else: ?><span class="muted">0</span><?php endif; ?></td>
                            <td class="r"><?php if ($rcvUnlinked > 0): ?><strong style="color:#b45309"><?= h($fmtQty($rcvUnlinked)) ?></strong><?php else: ?><span class="muted">0</span><?php endif; ?></td>
                            <td><?= h($rd) ?></td>
                            <td><?= h($due ?: '—') ?></td>
                            <td class="r">
                                <?php if ($lateness === null): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($lateness > 0): ?>
                                    <span class="pill pill-danger"><?= $lateness ?>d late</span>
                                <?php elseif ($lateness < 0): ?>
                                    <span class="pill pill-active"><?= abs($lateness) ?>d early</span>
                                <?php else: ?>
                                    <span class="pill pill-active">on time</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($R['loc_name'] ?: '—') ?></td>
                            <td><?= h($R['rcvd_by'] ?: '—') ?></td>
                            <td class="muted small"><?= h($R['notes'] ?: '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($canManage && in_array($sh['status'], ['approved', 'shipped'], true)): ?>
                <h4 style="margin: 18px 0 6px;">Record a receipt</h4>
                <p class="muted small">
                    Pick a receive-line, enter the qty + actual date + the bin you're putting it in. Partial
                    receipts are fine — record additional events as more material arrives.
                </p>
                <form method="post" action="<?= h(url('/inventory_shiprcpt.php?action=receive_save')) ?>"
                      style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1.5fr 2fr auto; gap: 10px; align-items: end;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <!-- Receive line spans full width on its own row -->
                    <div class="field" style="grid-column: 1 / -1;">
                        <label>Receive line</label>
                        <select name="shipment_line_id" required>
                            <option value="">—</option>
                            <?php foreach ($lines as $L):
                                if ($L['line_kind'] !== 'receive') continue;
                                $rem = max(0, (float)$L['qty_planned'] - (float)$L['qty_received']);
                            ?>
                                <option value="<?= (int)$L['id'] ?>">
                                    <?= h($L['item_code']) ?> — open: <?= h(rtrim(rtrim(number_format($rem, 3, '.', ''), '0'), '.')) ?> <?= h($L['item_uom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Qty</label>
                        <input type="number" step="0.001" min="0.001" name="qty_received" required placeholder="0">
                    </div>
                    <div class="field">
                        <label>Price (each)</label>
                        <input type="number" step="0.01" min="0" name="price" placeholder="0.00">
                    </div>
                    <div class="field">
                        <label>GST %</label>
                        <input type="number" step="0.01" min="0" max="100" name="gst_pct" placeholder="18">
                    </div>
                    <div class="field">
                        <label>Receipt date</label>
                        <input type="date" name="receipt_date" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="field">
                        <label>Destination</label>
                        <div class="muted small" style="padding: 6px 0;">
                            All receipts land at <strong>LOC-QCH</strong> (Quality Check Hold)
                            pending inspection. The store team routes from there once the
                            inspection is approved.
                        </div>
                        <input type="hidden" name="dst_location_id" value="">
                    </div>
                    <div class="field">
                        <label>Notes</label>
                        <input type="text" name="notes" maxlength="255">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">Record</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Transaction notes imported from old inventory (read-only) -->
    <?php if (!empty($txnNotes)): ?>
        <div class="card" style="padding: 18px; margin-bottom: 14px;">
            <h3 style="margin: 0 0 4px;">Transaction notes
                <span class="muted small">(imported from old inventory)</span></h3>
            <p class="muted small" style="margin: 0 0 12px;">
                Running notes that were attached to the legacy transactions behind this shipment's lines.
            </p>
            <?php foreach ($txnNotes as $N):
                $atts = $txnNoteAtts[(int)$N['id']] ?? [];
            ?>
                <div style="border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px;">
                    <div class="muted small" style="margin-bottom: 4px;">
                        <strong>Txn <?= (int)$N['old_transaction_id'] ?></strong>
                        · <?= h($N['author_name'] ?: $N['author_email'] ?: 'Imported') ?>
                        · <?= h(substr((string)$N['created_at'], 0, 16)) ?>
                        <?php if ($N['note_type_name']): ?>
                            · <span class="pill pill-info"><?= h($N['note_type_name']) ?></span>
                        <?php endif; ?>
                        · <a href="<?= h(url('/running_notes.php?action=view&id=' . (int)$N['id'])) ?>">note #<?= (int)$N['id'] ?></a>
                    </div>
                    <div class="note-body"><?= $N['body_html'] ?></div>
                    <?php if ($atts): ?>
                        <div class="note-attachments" style="margin-top: 6px;">
                            <?php foreach ($atts as $a): ?>
                                <a class="note-att-link" href="<?= h(url('/note_attach.php?id=' . (int)$a['id'])) ?>"
                                   title="<?= h($a['filename']) ?>">📎 <?= h($a['filename']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    if (function_exists('notes_render')) {
        notes_render('shiprcpt', $id, 'inline');
    }
    if (function_exists('notes_attachment_preview_assets')) {
        notes_attachment_preview_assets();
    }
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ----------------------------------------------------------------
// SHIPMENTS LIST — one row per inv_shipments header
// ----------------------------------------------------------------
// Browse view at the shipment-header granularity. Useful for finding
// drafts awaiting approval, in-flight shipments, completed batches.
// Sibling to the txn-history (event-level) view; both are reachable
// from the sidebar.
// ----------------------------------------------------------------
if ($action === 'shipments_list') {
    // Legacy URL — the dedicated shipment-header list has been folded
    // into the default unified feed. Keep the action understood so
    // bookmarks / sidebar links don't 404, just redirect.
    redirect(url('/inventory_shiprcpt.php'));
}



// ----------------------------------------------------------------
// UNIFIED LIST (default) — one row per event AND per zero-event shipment
// ----------------------------------------------------------------
// Combines the previous event-level view with the previous shipments
// list into a single feed. Each row is one of:
//
//   1. Receipt event    — inv_receipts row (material arrived).
//      direction='receive'.
//   2. Ship-out event   — inv_shipment_lines line_kind='ship', on a
//      shipment in status shipped/closed. direction='ship_out'.
//   3. Planned (no event yet) — a shipment row that has no receipt
//      rows and no posted ship lines yet. One row per such shipment.
//      direction='planned'. Lets drafts and approved-but-not-shipped
//      shipments appear in the same feed; previously they were only
//      visible via the dedicated shipments_list page that this view
//      replaces.
//
// All three branches project a uniform column set including shipment
// header context (status, mode, ship/receive dues, line count,
// receipt count). The header columns repeat across event rows that
// belong to the same shipment — that's expected, and the operator
// can hide any column they don't need via the Phase A datatable
// preferences.
// ----------------------------------------------------------------

$baseUnion = "
    (
      -- Branch 1: receipt events
      SELECT
          CONCAT('R', r.id)                    AS event_uid,
          'receive'                            AS direction,
          r.receipt_date                       AS event_date,
          r.created_at                         AS event_at,
          r.id                                 AS receipt_id,
          NULL                                 AS ship_line_id,
          sh.id                                AS shipment_id,
          sh.ship_no                           AS ship_no,
          sh.status                            AS sh_status,
          sh.mode                              AS sh_mode,
          sh.is_rework                         AS sh_is_rework,
          v.code                               AS vendor_code,
          v.name                               AS vendor_name,
          i.id                                 AS item_id,
          i.code                               AS item_code,
          COALESCE(NULLIF(i.short_description, ''), i.name, sl.pending_name) AS item_name,
          r.qty_received                       AS qty,
          sl.qty_planned                       AS line_qty_planned,
          l.name                               AS location_name,
          l.code                               AS location_code,
          u.full_name                          AS actor_name,
          r.notes                              AS notes,
          COALESCE(
              (SELECT SUM(il.qty) FROM invoice_lines il WHERE il.inv_receipt_id = r.id),
              0
          )                                    AS inv_linked_qty,
          (SELECT COUNT(*) FROM inv_shipment_lines sl2 WHERE sl2.shipment_id = sh.id) AS line_count,
          (SELECT COUNT(*) FROM inv_receipts r2      WHERE r2.shipment_id = sh.id) AS receipt_count,
          (SELECT po_no FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_no,
          (SELECT id    FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_id,
          sl.old_transaction_id                AS old_transaction_id
        FROM inv_receipts r
        JOIN inv_shipments sh        ON sh.id = r.shipment_id
        JOIN inv_shipment_lines sl   ON sl.id = r.shipment_line_id
   LEFT JOIN inv_items i             ON i.id  = sl.item_id
   LEFT JOIN vendors v               ON v.id  = sh.vendor_id
   LEFT JOIN locations l             ON l.id  = r.dst_location_id
   LEFT JOIN users u                 ON u.id  = r.created_by

      UNION ALL

      -- Branch 2: ship-out events
      SELECT
          CONCAT('S', sl.id)                   AS event_uid,
          'ship_out'                           AS direction,
          sh.actual_ship_date                  AS event_date,
          sh.shipped_at                        AS event_at,
          NULL                                 AS receipt_id,
          sl.id                                AS ship_line_id,
          sh.id                                AS shipment_id,
          sh.ship_no                           AS ship_no,
          sh.status                            AS sh_status,
          sh.mode                              AS sh_mode,
          sh.is_rework                         AS sh_is_rework,
          v.code                               AS vendor_code,
          v.name                               AS vendor_name,
          i.id                                 AS item_id,
          i.code                               AS item_code,
          COALESCE(NULLIF(i.short_description, ''), i.name, sl.pending_name) AS item_name,
          sl.qty_shipped                       AS qty,
          sl.qty_planned                       AS line_qty_planned,
          l.name                               AS location_name,
          l.code                               AS location_code,
          u.full_name                          AS actor_name,
          sl.notes                             AS notes,
          0                                    AS inv_linked_qty,
          (SELECT COUNT(*) FROM inv_shipment_lines sl2 WHERE sl2.shipment_id = sh.id) AS line_count,
          (SELECT COUNT(*) FROM inv_receipts r2      WHERE r2.shipment_id = sh.id) AS receipt_count,
          (SELECT po_no FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_no,
          (SELECT id    FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_id,
          sl.old_transaction_id                AS old_transaction_id
        FROM inv_shipment_lines sl
        JOIN inv_shipments sh        ON sh.id = sl.shipment_id
   LEFT JOIN inv_items i             ON i.id  = sl.item_id
   LEFT JOIN vendors v               ON v.id  = sh.vendor_id
   LEFT JOIN locations l             ON l.id  = sl.src_location_id
   LEFT JOIN users u                 ON u.id  = sh.shipped_by
       WHERE sl.line_kind = 'ship'
         AND sl.qty_shipped > 0
         AND sh.status IN ('shipped', 'closed')

      UNION ALL

      -- Branch 3: per-line planned rows.
      --   - receive lines that still have qty left to receive
      --     (qty_planned > qty_received — persists until fully received)
      --   - ship lines with qty_shipped = 0 (no shipment event yet)
      -- For partially-received lines this row shows the REMAINING qty
      -- alongside the individual receipt rows from Branch 1, so the
      -- operator always sees how much is still outstanding.
      SELECT
          CONCAT('P', sl.id)                   AS event_uid,
          'planned'                            AS direction,
          NULL                                 AS event_date,
          sh.created_at                        AS event_at,
          NULL                                 AS receipt_id,
          NULL                                 AS ship_line_id,
          sh.id                                AS shipment_id,
          sh.ship_no                           AS ship_no,
          sh.status                            AS sh_status,
          sh.mode                              AS sh_mode,
          sh.is_rework                         AS sh_is_rework,
          v.code                               AS vendor_code,
          v.name                               AS vendor_name,
          sl.item_id                           AS item_id,
          i.code                               AS item_code,
          COALESCE(NULLIF(i.short_description, ''), i.name, sl.pending_name) AS item_name,
          NULL                                 AS qty,
          -- Show remaining (planned − received) so the planned row always
          -- reflects what is still outstanding, not the original total.
          GREATEST(0, sl.qty_planned - sl.qty_received) AS line_qty_planned,
          NULL                                 AS location_name,
          NULL                                 AS location_code,
          uc.full_name                         AS actor_name,
          sl.notes                             AS notes,
          0                                    AS inv_linked_qty,
          (SELECT COUNT(*) FROM inv_shipment_lines sl2 WHERE sl2.shipment_id = sh.id) AS line_count,
          (SELECT COUNT(*) FROM inv_receipts r2      WHERE r2.shipment_id = sh.id) AS receipt_count,
          (SELECT po_no FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_no,
          (SELECT id    FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_id,
          sl.old_transaction_id                AS old_transaction_id
        FROM inv_shipment_lines sl
        JOIN inv_shipments sh    ON sh.id = sl.shipment_id
   LEFT JOIN inv_items i         ON i.id  = sl.item_id
   LEFT JOIN vendors v           ON v.id  = sh.vendor_id
   LEFT JOIN users uc            ON uc.id = sh.created_by
       WHERE sh.status <> 'cancelled'
         AND (
                (sl.line_kind = 'receive'
                    AND sl.qty_planned > sl.qty_received)
                OR
                (sl.line_kind = 'ship' AND sl.qty_shipped = 0)
             )

      UNION ALL

      -- Branch 4: imported received receipts (status = 'received').
      -- The old-inventory import marks a fully-received receipt directly on
      -- the shipment line (qty_received) and sets status='received' WITHOUT
      -- creating granular inv_receipts rows (those need a stock-affecting
      -- inv_txns row, which the audit-only import avoids). Surface them here
      -- as receive events dated by the imported event date (sh.updated_at).
      -- NOT EXISTS guards against double-counting native receipts (Branch 1,
      -- which always have inv_receipts rows and never use status='received').
      SELECT
          CONCAT('IR', sl.id)                  AS event_uid,
          'receive'                            AS direction,
          COALESCE(sh.actual_ship_date, DATE(sh.updated_at)) AS event_date,
          sh.updated_at                        AS event_at,
          NULL                                 AS receipt_id,
          NULL                                 AS ship_line_id,
          sh.id                                AS shipment_id,
          sh.ship_no                           AS ship_no,
          sh.status                            AS sh_status,
          sh.mode                              AS sh_mode,
          sh.is_rework                         AS sh_is_rework,
          v.code                               AS vendor_code,
          v.name                               AS vendor_name,
          i.id                                 AS item_id,
          i.code                               AS item_code,
          COALESCE(NULLIF(i.short_description, ''), i.name, sl.pending_name) AS item_name,
          sl.qty_received                      AS qty,
          sl.qty_planned                       AS line_qty_planned,
          NULL                                 AS location_name,
          NULL                                 AS location_code,
          uc.full_name                         AS actor_name,
          sl.notes                             AS notes,
          0                                    AS inv_linked_qty,
          (SELECT COUNT(*) FROM inv_shipment_lines sl2 WHERE sl2.shipment_id = sh.id) AS line_count,
          (SELECT COUNT(*) FROM inv_receipts r2      WHERE r2.shipment_id = sh.id) AS receipt_count,
          (SELECT po_no FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_no,
          (SELECT id    FROM purchase_orders WHERE shipment_id = sh.id ORDER BY id DESC LIMIT 1) AS po_id,
          sl.old_transaction_id                AS old_transaction_id
        FROM inv_shipment_lines sl
        JOIN inv_shipments sh        ON sh.id = sl.shipment_id
   LEFT JOIN inv_items i             ON i.id  = sl.item_id
   LEFT JOIN vendors v               ON v.id  = sh.vendor_id
   LEFT JOIN users uc                ON uc.id = sh.created_by
       WHERE sl.line_kind  = 'receive'
         AND sl.qty_received > 0
         AND sh.status      = 'received'
         AND NOT EXISTS (SELECT 1 FROM inv_receipts r3 WHERE r3.shipment_line_id = sl.id)
    ) AS e";

// Boolean expression (1/0): does this event's shipment have any running
// notes rolled up from its transactions? Drives the Notes/Attachments
// column filter (Available / Not available). The set of note-bearing
// shipment ids is resolved ONCE here and embedded as a literal integer
// IN-list, so the per-row filter is a cheap indexed comparison — never a
// correlated subquery (which, with the non-sargable CONCAT join, hangs
// the page). Empty list falls back to IN (0) (matches nothing).
$noteShipmentIds = shr_shipment_ids_with_txn_notes();
$noteIdList   = !empty($noteShipmentIds) ? implode(',', $noteShipmentIds) : '0';
$hasNotesExpr = "(CASE WHEN e.shipment_id IN ($noteIdList) THEN 1 ELSE 0 END)";

$dtCfg = [
    'id'       => 'shiprcpt_txn_history',
    'base_sql' => 'SELECT * FROM ' . $baseUnion,
    'columns'  => [
        ['key'=>'event_at',      'label'=>'When',         'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.event_at',  'td_class'=>'nowrap'],
        ['key'=>'event_date',    'label'=>'Event date',   'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.event_date'],
        ['key'=>'direction',     'label'=>'Direction',    'sortable'=>true, 'sql_col'=>'e.direction',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'receive',  'label'=>'Receipt (incoming)'],
             ['value'=>'ship_out', 'label'=>'Ship out (outgoing)'],
             ['value'=>'planned',  'label'=>'Planned (no event yet)'],
         ]]],
        ['key'=>'sh_status',     'label'=>'Status',       'sortable'=>true, 'sql_col'=>'e.sh_status',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'draft',     'label'=>'Draft'],
             ['value'=>'approved',  'label'=>'Approved'],
             ['value'=>'shipped',   'label'=>'Shipped'],
             ['value'=>'received',  'label'=>'Received'],
             ['value'=>'closed',    'label'=>'Closed'],
             ['value'=>'cancelled', 'label'=>'Cancelled'],
         ]]],
        ['key'=>'sh_mode',       'label'=>'Mode',         'sortable'=>true, 'sql_col'=>'e.sh_mode',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'receive', 'label'=>'Receive only'],
             ['value'=>'ship',    'label'=>'Ship only'],
             ['value'=>'both',    'label'=>'Both'],
         ]]],
        ['key'=>'ship_no',       'label'=>'Ship #',       'sortable'=>true, 'searchable'=>true,  'sql_col'=>'e.ship_no'],
        ['key'=>'po_no',         'label'=>'PO #',         'sortable'=>true, 'searchable'=>true,  'sql_col'=>'e.po_no'],
        ['key'=>'old_txn',       'label'=>'Txn ID',       'sortable'=>true, 'searchable'=>true,  'sql_col'=>'e.old_transaction_id', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'vendor',        'label'=>'Vendor',       'sortable'=>true, 'searchable'=>true,  'sql_col'=>'e.vendor_name'],
        ['key'=>'item_label',    'label'=>'Item',         'sortable'=>true, 'searchable'=>true,
         // Searchable on both code and name; displayed as (CODE)-Name
         // per the app-wide convention. Use COALESCE so the planned
         // branch (NULL code) doesn't blow up the CONCAT result.
         'sql_col'=>"CONCAT('(', COALESCE(e.item_code,''), ')-', COALESCE(e.item_name,''))"],
        ['key'=>'qty',           'label'=>'Qty',          'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.qty',          'th_class'=>'r','td_class'=>'r'],
        ['key'=>'line_qty_planned', 'label'=>'Planned',    'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.line_qty_planned', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'line_count',    'label'=>'Lines',        'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.line_count',    'th_class'=>'r','td_class'=>'r'],
        ['key'=>'receipt_count', 'label'=>'Receipts',     'sortable'=>true, 'searchable'=>false, 'sql_col'=>'e.receipt_count', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'inv_linked',    'label'=>'Linked',       'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'inv_unlinked',  'label'=>'Unlinked',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'location_name', 'label'=>'Location',     'sortable'=>true, 'searchable'=>true,  'sql_col'=>'e.location_name'],
        ['key'=>'actor_name',    'label'=>'By',           'sortable'=>false,'searchable'=>false],
        ['key'=>'notes',         'label'=>'Notes',        'sortable'=>false,'searchable'=>true,  'sql_col'=>'e.notes', 'td_class'=>'muted small'],
        ['key'=>'txn_notes',     'label'=>'Notes/Attachments', 'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap',
         'sql_col'=>$hasNotesExpr,
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'1', 'label'=>'Available'],
             ['value'=>'0', 'label'=>'Not available'],
         ]]],
        ['key'=>'_actions',      'label'=>'Actions',      'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    // Sort by the captured server-side timestamp (event_at) so events
    // ordered within a single business day still come out in real
    // chronological order. Planned rows fall back to sh.created_at
    // (aliased into event_at) so drafts surface near the top too.
    'default_sort' => ['event_at', 'desc'],
];

// Per-shipment running-note counts for the rows on the current page.
// Populated AFTER data_table_run() returns (it knows the page slice);
// the renderer reads it by reference, so the order of assignment is fine.
$shipmentNoteCounts = [];
$rowRenderer = function ($r) use ($canManage, &$shipmentNoteCounts) {
    // Direction pill — three flavors now.
    //   receive  → green: material arrived
    //   ship_out → amber: material dispatched
    //   planned  → muted: shipment exists, no event yet (draft / approved / etc.)
    if ($r['direction'] === 'receive') {
        $dirPill = '<span class="pill pill-active" title="Material arrived">↓ receipt</span>';
    } elseif ($r['direction'] === 'ship_out') {
        $dirPill = '<span class="pill pill-warn" title="Material dispatched">↑ ship out</span>';
    } else {
        $dirPill = '<span class="pill pill-muted" title="Shipment exists but no event posted yet">◌ planned</span>';
    }

    // Shipment status pill (header context).
    $sStatus = (string)($r['sh_status'] ?? '');
    $statusCls = ($sStatus === 'closed' || $sStatus === 'received' ? 'active'
        : ($sStatus === 'cancelled' ? 'danger'
            : ($sStatus === 'shipped' ? 'info'
                : ($sStatus === 'approved' ? 'info' : 'muted'))));
    $statusPill = $sStatus !== ''
        ? '<span class="pill pill-' . $statusCls . '">' . h($sStatus) . '</span>'
        : '<span class="muted">—</span>';

    // Mode label (compact).
    $modeRaw = (string)($r['sh_mode'] ?? '');
    $modeLabel = $modeRaw === 'both' ? 'Ship & rcv'
                : ($modeRaw === 'ship' ? 'Ship only'
                    : ($modeRaw === 'receive' ? 'Receive only' : '—'));

    $vendor = !empty($r['vendor_name'])
        ? '<code>' . h($r['vendor_code']) . '</code> ' . h($r['vendor_name'])
        : '<span class="muted">—</span>';

    // Item cell. Event rows link the item to its ledger; planned rows
    // don't have a specific event item, so we show the first line's
    // code-name without a link (or '—' if there are no lines yet).
    if ($r['direction'] === 'planned') {
        // Per-line planned rows — each row IS one line, so no
        // "+N more" suffix. Link the item to its ledger when an
        // item_id exists; pending lines (no item yet) just show
        // the typed pending_name plain.
        if (!empty($r['item_id'])) {
            $itemCell = '<a href="' . h(url('/inventory.php?action=ledger&id=' . (int)$r['item_id'])) . '">'
                      . '(' . h($r['item_code']) . ')-' . h($r['item_name'])
                      . '</a>';
        } elseif (!empty($r['item_name'])) {
            $itemCell = h($r['item_name']) . ' <span class="muted small">(pending)</span>';
        } else {
            $itemCell = '<span class="muted">—</span>';
        }
    } else {
        $itemCell = '<a href="' . h(url('/inventory.php?action=ledger&id=' . (int)$r['item_id'])) . '">'
                  . '(' . h($r['item_code']) . ')-' . h($r['item_name'])
                  . '</a>';
    }

    $shipLink = '<a href="' . h(url('/inventory_shiprcpt.php?action=view&id=' . (int)$r['shipment_id'])) . '">'
              . '<code>' . h($r['ship_no']) . '</code></a>';

    $poCell = !empty($r['po_id'])
        ? '<a href="' . h(url('/purchase_orders.php?action=view_pdf&id=' . (int)$r['po_id'])) . '" target="_blank" title="View PO PDF">'
          . '<code>' . h($r['po_no']) . '</code></a>'
        : '<span class="muted">—</span>';

    // Qty formatting — trim trailing zeros. Planned rows have no qty.
    $fmtQ = function ($v) {
        return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    };
    // For planned rows (no event yet), show line_qty_planned directly in
    // the Qty cell so the operator sees the number without hunting for it
    // in the Planned column.
    if ($r['direction'] === 'planned') {
        $qtyCell = ($r['line_qty_planned'] !== null && $r['line_qty_planned'] !== '')
                 ? h($fmtQ($r['line_qty_planned']))
                 : '<span class="muted">—</span>';
    } else {
        $qtyCell = ($r['qty'] !== null)
                 ? h($fmtQ($r['qty']))
                 : '<span class="muted">—</span>';
    }

    // Planned column — shows line_qty_planned for receipt/ship-out rows only
    // (lets operator spot amendments where event qty ≠ current plan).
    // For planned-direction rows it's already in Qty, so suppress it here.
    $planned = $r['line_qty_planned'];
    if ($r['direction'] === 'planned' || $planned === null || $planned === '') {
        $plannedCell = '<span class="muted">—</span>';
    } else {
        $plannedFmt = $fmtQ($planned);
        if ($r['qty'] !== null && (float)$planned !== (float)$r['qty']) {
            $plannedCell = '<strong style="color:#b45309" title="Differs from event qty — likely amended">'
                         . h($plannedFmt) . ' ⚑</strong>';
        } else {
            $plannedCell = h($plannedFmt);
        }
    }

    // Invoice linked/unlinked. Only meaningful for receipts. The
    // ship-out and planned branches both render '—' here so the
    // operator distinguishes "not invoice-eligible" from "zero
    // linked yet" (the latter only shows on receipts).
    if ($r['direction'] === 'receive') {
        $linked   = (float)$r['inv_linked_qty'];
        $unlinked = (float)$r['qty'] - $linked;
        if ($unlinked < 0) $unlinked = 0.0;
        $invLinkedCell   = $linked > 0
            ? '<strong style="color:#059669">' . h($fmtQ($linked)) . '</strong>'
            : '<span class="muted">0</span>';
        $invUnlinkedCell = $unlinked > 0
            ? '<strong style="color:#b45309">' . h($fmtQ($unlinked)) . '</strong>'
            : '<span class="muted">0</span>';
    } else {
        $invLinkedCell   = '<span class="muted">—</span>';
        $invUnlinkedCell = '<span class="muted">—</span>';
    }

    $actions = '<a class="btn btn-icon" title="View shipment" aria-label="View shipment" href="'
             . h(url('/inventory_shiprcpt.php?action=view&id=' . (int)$r['shipment_id']))
             . '">👁 <span class="dt-action-label">View</span></a>';
    // Edit button — only on draft shipments and only for users who
    // can manage. Inherited from the (now-removed) shipments_list
    // page so power users keep that affordance.
    if ($canManage && $sStatus === 'draft') {
        $actions .= ' <a class="btn btn-icon" title="Edit shipment" aria-label="Edit shipment" href="'
                  . h(url('/inventory_shiprcpt.php?action=edit&id=' . (int)$r['shipment_id']))
                  . '">✎ <span class="dt-action-label">Edit</span></a>';
    }

    $lineCount    = (int)($r['line_count'] ?? 0);
    $receiptCount = (int)($r['receipt_count'] ?? 0);

    // Notes/Attachments — clip icon when this shipment's transactions carry
    // running notes. Click opens a popup listing them (handled by
    // shr_txn_notes_popup_assets()). The count map is batch-prefilled for
    // the initial page render; on the datatable's AJAX rows path (sort /
    // filter / paginate) data_table_run() renders and exits BEFORE the
    // prefill runs, so fall back to a memoized per-shipment lookup here.
    $shipId = (int)$r['shipment_id'];
    if (!array_key_exists($shipId, $shipmentNoteCounts)) {
        $oneCount = shr_shipment_txn_note_counts([$shipId]);
        $shipmentNoteCounts[$shipId] = $oneCount[$shipId] ?? 0;
    }
    $shNoteCount = $shipmentNoteCounts[$shipId];
    if ($shNoteCount > 0) {
        $txnNotesCell = '<button type="button" class="shr-notes-indicator"'
            . ' data-shipment-id="' . (int)$r['shipment_id'] . '"'
            . ' data-ship-no="' . h($r['ship_no']) . '"'
            . ' title="' . $shNoteCount . ' note' . ($shNoteCount === 1 ? '' : 's')
            . ' on this shipment&#39;s transactions">'
            . '📎&nbsp;<span class="shr-notes-badge">' . $shNoteCount . '</span></button>';
    } else {
        $txnNotesCell = '<span class="muted small">—</span>';
    }

    return [
        'event_at'      => h(dt_display($r['event_at'])),
        'event_date'    => h($r['event_date'] ?: '—'),
        'direction'     => $dirPill,
        'sh_status'     => $statusPill,
        'sh_mode'       => h($modeLabel),
        'ship_no'       => $shipLink,
        'po_no'         => $poCell,
        'old_txn'       => !empty($r['old_transaction_id'])
            ? '<code title="Legacy transaction.transaction_id">' . (int)$r['old_transaction_id'] . '</code>'
            : '<span class="muted">—</span>',
        'vendor'        => $vendor,
        'item_label'    => $itemCell,
        'qty'           => $qtyCell,
        'line_qty_planned' => $plannedCell,
        'line_count'    => $lineCount   ?: '<span class="muted">—</span>',
        'receipt_count' => $receiptCount ?: '<span class="muted">—</span>',
        'inv_linked'    => $invLinkedCell,
        'inv_unlinked'  => $invUnlinkedCell,
        'location_name' => h($r['location_name'] ?: '—'),
        'actor_name'    => h($r['actor_name'] ?: '—'),
        'notes'         => h($r['notes'] ?: ''),
        'txn_notes'     => $txnNotesCell,
        '_actions'      => dt_actions_wrap($actions),
    ];
};
$dt = data_table_run($dtCfg, $rowRenderer);

// Batched note rollup for just the rows on this page (one query). The
// renderer captured $shipmentNoteCounts by reference, so filling it here —
// after the page slice is known but before data_table_render() iterates —
// is what lights up the clip icons. Seed 0 for every page shipment so the
// renderer's array_key_exists() check treats them as "known" and skips the
// per-row fallback query on the initial render.
$pageShipmentIds    = array_map('intval', array_column($dt['rows'], 'shipment_id'));
$shipmentNoteCounts = shr_shipment_txn_note_counts($pageShipmentIds);
foreach ($pageShipmentIds as $sid) {
    if (!array_key_exists($sid, $shipmentNoteCounts)) $shipmentNoteCounts[$sid] = 0;
}

$newBtnHtml = $canManage
    ? '<a class="btn btn-primary" href="' . h(url('/inventory_shiprcpt.php?action=new')) . '"'
      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New shipment', 'N') . '</a>'
    : '';
$dtCfg['title']        = 'Ship & Receipt';
$dtCfg['description']  = 'Unified feed: one row per event (receipts, ship-outs) plus one row per shipment line that has no event posted yet (direction = Planned). Each line on a draft / approved shipment appears as its own Planned row. Use the column controls (⚙) to hide what you don\'t need.';
$dtCfg['actions_html'] = $newBtnHtml;

$page_title  = 'Ship & Receipt';
$page_module = 'inventory_shiprcpt';
require __DIR__ . '/includes/header.php';
data_table_render($dtCfg, $dt, $rowRenderer);
shr_txn_notes_popup_assets();   // clip-icon popup CSS + JS (emitted once)
require __DIR__ . '/includes/footer.php';
