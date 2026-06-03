<?php
/**
 * MagDyn — Invoice (v3)
 * Created: 20260517_194500_IST
 * Reworked: 20260517_213000_IST — approval flow + M:N linkage
 * Reworked: 20260517_223000_IST — line items + search-select pickers
 *
 * Header + line-item shape:
 *   invoices       header (vendor, no, date, status, currency, notes)
 *   invoice_items  one row per line: kind+code (asset/inv_item),
 *                  description, qty, UOM, unit_price, GST%, HSN
 *   invoice_lines  approval-time M:N junction between invoice and
 *                  underlying transactions (asset_txns / inv_receipts)
 *
 * Flow stays the same as v2:
 *   ENTRY — vendor + line items + (optional) total. Status = pending.
 *   APPROVAL — pick from candidate txns. Strict picker filters
 *              candidates to codes that appear in any line item.
 *   REJECT / REOPEN — same as v2.
 *
 * Search-and-select item codes: the combobox.js script (loaded
 * globally from includes/footer.php) auto-enhances every <select> on
 * the page into a search-as-you-type combobox. We render each line
 * item's code field as a <select> populated with assets + inv_items
 * options; the user types to filter. For tenants with thousands of
 * items, we'd swap this for a server-side AJAX picker — for v1 the
 * full-options approach is good for up to a few thousand rows.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('invoice', 'view');
require_once __DIR__ . '/includes/datatable.php';
require_once __DIR__ . '/includes/_invoice_links.php';

$action    = (string)input('action', 'index');
$canManage = permission_check('invoice', 'manage');

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/** Qty-increasing asset txn types — only ones invoiceable. */
function invoice_asset_txn_types_increasing()
{
    return ['create', 'receive_vendor', 'receive_user'];
}

/**
 * Pre-fetch the pickable code sets for the new-invoice form. Returns:
 *   ['assets' => [{code, label}, ...], 'items' => [{code, label, uom, unit_cost, hsn?, gst?}, ...]]
 * Used to seed the line-item code selects + auto-fill UOM/price/etc.
 *
 * We cap each list at 2000 — combobox.js stays responsive up to a few
 * thousand. Larger tenants should swap to server-side AJAX picker.
 */
function invoice_picker_options()
{
    // Build ONE unified option list mixing inv_items + assets. Each
    // option's `value` is "<kind>:<code>" so on POST we can recover
    // which table the picked row came from. Labels stay clean ("<code>
    // — <description>") so the user's code-prefix convention (A- for
    // assets, I- for inventory) makes filtering intuitive without us
    // adding any synthetic prefix marker. Inv items come first because
    // they're the more common pick on a typical bill.
    $opts = [];
    $rows = db_all(
        'SELECT code,
                CONCAT(code, " — ", COALESCE(NULLIF(short_description, ""), name)) AS label,
                uom, unit_cost
           FROM inv_items
          WHERE is_active = 1
          ORDER BY code
          LIMIT 2000'
    );
    foreach ($rows as $r) {
        $opts[] = [
            'value'      => 'inv:' . $r['code'],
            'label'      => (string)$r['label'],
            'uom'        => (string)$r['uom'],
            'unit_cost'  => $r['unit_cost'],
        ];
    }
    $rows = db_all(
        'SELECT a.asset_tag AS code,
                CONCAT(a.asset_tag, " — ", COALESCE(am.name, "asset")) AS label
           FROM assets a
      LEFT JOIN asset_models am ON am.id = a.model_id
          ORDER BY a.asset_tag
          LIMIT 2000'
    );
    foreach ($rows as $r) {
        $opts[] = [
            'value'      => 'asset:' . $r['code'],
            'label'      => (string)$r['label'],
            'uom'        => '',           // assets have no inherent UOM
            'unit_cost'  => null,
        ];
    }
    return $opts;
}

/**
 * Compute the running total for an invoice's line items. Sums
 * (qty * unit_price) across rows. Returns 0 for invoices with no
 * lines. Caller decides what to do with GST (separate sum if
 * GST-exclusive pricing, embedded in unit_price otherwise — we
 * stay agnostic).
 */
function invoice_line_total_subtotal($invoiceId)
{
    return (float)db_val(
        'SELECT COALESCE(SUM(qty * unit_price), 0) FROM invoice_items WHERE invoice_id = ?',
        [(int)$invoiceId], 0.0
    );
}

/**
 * Pull the distinct codes used across an invoice's line items. The
 * approval picker uses this as the "strict mode" filter set: only
 * candidate txns matching any of these codes show up unless the
 * user toggles to "all transactions".
 *
 * Returns ['asset' => ['A-001', ...], 'inv_item' => ['BRG-6204', ...]]
 */
function invoice_code_set($invoiceId)
{
    $rows = db_all(
        'SELECT DISTINCT item_kind, item_code FROM invoice_items WHERE invoice_id = ?',
        [(int)$invoiceId]
    );
    $out = ['asset' => [], 'inv_item' => []];
    foreach ($rows as $r) {
        if (isset($out[$r['item_kind']])) {
            $out[$r['item_kind']][] = (string)$r['item_code'];
        }
    }
    return $out;
}

/**
 * Replace an invoice's line items from the form's parallel arrays.
 * Validates each row + inserts; returns the count actually written.
 * Empty rows (no item_code) are skipped silently so the user can
 * leave trailing blanks in the form without errors.
 */
function invoice_save_items($invoiceId)
{
    // Unified picker: each row posts a single item_pick[] value of
    // the form "<kind>:<code>" (e.g. "asset:A-00042" or
    // "inv:BRG-6204"). We split that into kind + code and continue
    // with the same per-row parallel arrays for the other fields.
    $picks  = (array)input('item_pick',  []);
    $qtys   = (array)input('item_qty',   []);
    $uoms   = (array)input('item_uom',   []);
    $prices = (array)input('item_price', []);
    $gsts   = (array)input('item_gst',   []);
    $hsns   = (array)input('item_hsn',   []);
    $notes  = (array)input('item_notes', []);

    $n = max(count($picks), count($qtys), count($prices));

    db_exec('DELETE FROM invoice_items WHERE invoice_id = ?', [(int)$invoiceId]);
    $written = 0;
    for ($i = 0; $i < $n; $i++) {
        $pick  = isset($picks[$i])  ? trim((string)$picks[$i])  : '';
        $qty   = isset($qtys[$i])   ? (float)$qtys[$i]   : 0;
        $uom   = isset($uoms[$i])   ? trim((string)$uoms[$i])   : 'pcs';
        $price = isset($prices[$i]) ? (float)$prices[$i] : 0;
        $gstR  = isset($gsts[$i])   ? trim((string)$gsts[$i])   : '';
        $gst   = $gstR === '' ? null : (float)$gstR;
        $hsn   = isset($hsns[$i])   ? trim((string)$hsns[$i])   : '';
        $note  = isset($notes[$i])  ? trim((string)$notes[$i])  : '';

        if ($pick === '') continue;             // skip empty rows
        $parts = explode(':', $pick, 2);
        if (count($parts) !== 2) continue;
        $kindRaw = $parts[0];
        $code    = trim($parts[1]);
        // Map the picker's short kind tags to the DB ENUM values.
        // The picker uses 'asset' and 'inv' (terser in option values);
        // invoice_items.item_kind is 'asset' / 'inv_item'.
        if      ($kindRaw === 'asset') $kind = 'asset';
        elseif  ($kindRaw === 'inv')   $kind = 'inv_item';
        else    continue;
        if ($code === '') continue;
        if ($qty <= 0)   $qty = 1;
        if ($uom === '') $uom = 'pcs';

        // Snapshot the description from the live source at save time.
        $desc = '';
        if ($kind === 'inv_item') {
            $r = db_one(
                'SELECT COALESCE(NULLIF(short_description, ""), name) AS d FROM inv_items WHERE code = ?',
                [$code]
            );
            if ($r) $desc = (string)$r['d'];
        } else {
            $r = db_one(
                'SELECT COALESCE(am.name, "") AS d
                   FROM assets a LEFT JOIN asset_models am ON am.id = a.model_id
                  WHERE a.asset_tag = ?',
                [$code]
            );
            if ($r) $desc = (string)$r['d'];
        }

        db_exec(
            'INSERT INTO invoice_items
               (invoice_id, sort_order, item_kind, item_code, description,
                qty, uom, unit_price, gst_rate, hsn_code, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int)$invoiceId, $i, $kind, $code, $desc,
             $qty, $uom, $price, $gst, $hsn ?: null, $note ?: null]
        );
        $written++;
    }
    return $written;
}

// ----------------------------------------------------------------
// SAVE
// ----------------------------------------------------------------
if ($action === 'save') {
    require_permission('invoice', 'manage');
    csrf_check();

    $id          = (int)input('id', 0);
    $invoiceNo   = trim((string)input('invoice_no', ''));
    $invoiceDate = trim((string)input('invoice_date', ''));
    $vendorId    = (int)input('vendor_id', 0);
    $currency    = trim((string)input('currency', 'INR')) ?: 'INR';
    $notes       = trim((string)input('notes', ''));

    // Validate header
    $errors = [];
    if ($invoiceNo === '') $errors[] = 'Invoice number is required.';
    if ($invoiceDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $errors[] = 'Invoice date is required (YYYY-MM-DD).';
    }
    if ($vendorId <= 0) $errors[] = 'Vendor is required.';

    // Quick scan of submitted items — we don't fully validate here
    // (invoice_save_items skips empties + does its own checks) but we
    // do require at least one non-empty row so the user can't create
    // a totally empty invoice.
    $submittedPicks = (array)input('item_pick', []);
    $hasAnyLine = false;
    foreach ($submittedPicks as $p) {
        if (trim((string)$p) !== '') { $hasAnyLine = true; break; }
    }
    if (!$hasAnyLine) $errors[] = 'Add at least one line item.';

    if ($errors) {
        flash_set('error', implode(' ', $errors));
        redirect(url('/invoice.php?action=' . ($id ? 'edit&id=' . $id : 'new')));
    }

    $uid = (int)current_user_id();
    if ($id > 0) {
        $existing = db_one('SELECT id FROM invoices WHERE id = ?', [$id]);
        if (!$existing) {
            flash_set('error', 'Invoice not found.');
            redirect(url('/invoice.php'));
        }
        try {
            db_exec(
                'UPDATE invoices
                    SET invoice_no   = ?,
                        invoice_date = ?,
                        vendor_id    = ?,
                        currency     = ?,
                        notes        = ?
                  WHERE id = ?',
                [$invoiceNo, $invoiceDate, $vendorId, $currency, $notes ?: null, $id]
            );
        } catch (\Throwable $e) {
            flash_set('error', 'Could not save invoice: ' . $e->getMessage());
            redirect(url('/invoice.php?action=edit&id=' . $id));
        }
        invoice_save_items($id);
        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'invoice.update', ?, ?)",
            [$uid, $id, $invoiceNo]
        );
        flash_set('success', 'Invoice updated.');
        redirect(url('/invoice.php?action=view&id=' . $id));
    } else {
        try {
            db_exec(
                'INSERT INTO invoices
                   (invoice_no, invoice_date, vendor_id, currency, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$invoiceNo, $invoiceDate, $vendorId, $currency, 'pending', $notes ?: null, $uid]
            );
        } catch (\Throwable $e) {
            flash_set('error', 'Could not save invoice: ' . $e->getMessage());
            redirect(url('/invoice.php?action=new'));
        }
        $newId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        $written = invoice_save_items($newId);
        db_exec(
            "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'invoice.create', ?, ?)",
            [$uid, $newId, $invoiceNo . ' (' . $written . ' line' . ($written === 1 ? '' : 's') . ')']
        );
        flash_set('success', 'Invoice created with ' . $written . ' line item' . ($written === 1 ? '' : 's')
                            . '. Approve it from the browse list to link transactions.');
        redirect(url('/invoice.php?action=view&id=' . $newId));
    }
}

// ----------------------------------------------------------------
// APPROVAL — save linkages + flip status
// ----------------------------------------------------------------
if ($action === 'approve_save') {
    require_permission('invoice', 'manage');
    csrf_check();

    $id = (int)input('id', 0);
    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }

    // Per-line linking is now a separate flow (action=links). Approval
    // simply flips the status — the link rows are managed independently
    // through invoice_item_link_create/_delete from the linker page.
    $uid = (int)current_user_id();
    db_exec(
        'UPDATE invoices
            SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = NULL
          WHERE id = ?',
        ['approved', $uid, $id]
    );
    // Count current links for the audit detail / flash message.
    $linkCount = (int)db_val(
        'SELECT COUNT(*) FROM invoice_lines il
           JOIN invoice_items ii ON ii.id = il.invoice_item_id
          WHERE ii.invoice_id = ?',
        [$id], 0
    );
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'invoice.approve', ?, ?)",
        [$uid, $id, $inv['invoice_no'] . ' (' . $linkCount . ' link' . ($linkCount === 1 ? '' : 's') . ')']
    );
    flash_set('success', sprintf('Invoice approved. %d transaction link%s on file.',
        $linkCount, $linkCount === 1 ? '' : 's'));
    redirect(url('/invoice.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// LINKING — per-invoice-item txn links (independent of approval)
// ----------------------------------------------------------------
// Three actions:
//   links             — render the editor for a single invoice
//   link_create_save  — POST: add one link row
//   link_delete_save  — POST: remove one link row by id
//
// Code-match validation lives in invoice_item_validate_link (helper).
// All mutations go through invoice_item_link_create/_delete so the
// constraints (kind match, code match, qty bounds) are enforced
// consistently from both the UI and any future scripted callers.

if ($action === 'link_create_save') {
    require_permission('invoice', 'manage');
    csrf_check();

    $invoiceId = (int)input('id', 0);
    $itemId    = (int)input('invoice_item_id', 0);
    $linkKind  = (string)input('link_kind', '');
    $targetId  = (int)input('target_id', 0);
    $qty       = (float)input('qty', 0);

    try {
        invoice_item_link_create($itemId, $linkKind, $targetId, $qty);
        flash_set('success', sprintf('Linked %s.',
            rtrim(rtrim(number_format($qty, 3), '0'), '.')));
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (\Throwable $e) {
        flash_set('error', 'Link failed: ' . $e->getMessage());
    }
    redirect(url('/invoice.php?action=links&id=' . $invoiceId));
}

if ($action === 'link_delete_save') {
    require_permission('invoice', 'manage');
    csrf_check();

    $invoiceId = (int)input('id', 0);
    $linkId    = (int)input('link_id', 0);

    if ($linkId > 0) {
        invoice_item_link_delete($linkId);
        flash_set('success', 'Link removed.');
    } else {
        flash_set('error', 'Invalid link id.');
    }
    redirect(url('/invoice.php?action=links&id=' . $invoiceId));
}

// ----------------------------------------------------------------
// REJECT
// ----------------------------------------------------------------
if ($action === 'reject_save') {
    require_permission('invoice', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $reason = trim((string)input('rejection_reason', ''));
    $inv = db_one('SELECT invoice_no FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    // Clear all txn links on this invoice's items. invoice_lines.invoice_id
    // was dropped in migration_20260524_231813 — joins go via invoice_items.
    db_exec(
        'DELETE il FROM invoice_lines il
           JOIN invoice_items ii ON ii.id = il.invoice_item_id
          WHERE ii.invoice_id = ?',
        [$id]
    );
    db_exec(
        'UPDATE invoices SET status = ?, rejection_reason = ?, approved_by = NULL, approved_at = NULL WHERE id = ?',
        ['rejected', $reason ?: null, $id]
    );
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'invoice.reject', ?, ?)",
        [(int)current_user_id(), $id, $inv['invoice_no']]
    );
    flash_set('success', 'Invoice rejected.');
    redirect(url('/invoice.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// REOPEN
// ----------------------------------------------------------------
if ($action === 'reopen') {
    require_permission('invoice', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $inv = db_one('SELECT invoice_no FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    db_exec(
        'DELETE il FROM invoice_lines il
           JOIN invoice_items ii ON ii.id = il.invoice_item_id
          WHERE ii.invoice_id = ?',
        [$id]
    );
    db_exec(
        'UPDATE invoices SET status = ?, approved_by = NULL, approved_at = NULL, rejection_reason = NULL WHERE id = ?',
        ['pending', $id]
    );
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'invoice.reopen', ?, ?)",
        [(int)current_user_id(), $id, $inv['invoice_no']]
    );
    flash_set('success', 'Invoice reopened. It\'s back to pending.');
    redirect(url('/invoice.php?action=view&id=' . $id));
}

// ----------------------------------------------------------------
// DELETE
// ----------------------------------------------------------------
if ($action === 'delete') {
    require_permission('invoice', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $existing = db_one('SELECT invoice_no FROM invoices WHERE id = ?', [$id]);
    if (!$existing) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    $atts = db_all('SELECT stored_path FROM invoice_attachments WHERE invoice_id = ?', [$id]);
    foreach ($atts as $a) {
        $p = __DIR__ . '/' . ltrim((string)$a['stored_path'], '/');
        if (is_file($p)) @unlink($p);
    }
    db_exec('DELETE FROM invoices WHERE id = ?', [$id]);
    db_exec(
        "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, 'invoice.delete', ?, ?)",
        [(int)current_user_id(), $id, $existing['invoice_no']]
    );
    flash_set('success', 'Invoice deleted.');
    redirect(url('/invoice.php'));
}

// ----------------------------------------------------------------
// ATTACHMENTS
// ----------------------------------------------------------------
if ($action === 'attach_upload') {
    require_permission('invoice', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $existing = db_one('SELECT id FROM invoices WHERE id = ?', [$id]);
    if (!$existing) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Upload failed.');
        redirect(url('/invoice.php?action=view&id=' . $id));
    }
    $maxBytes = (int)$GLOBALS['APP']['upload_max_mb'] * 1024 * 1024;
    if ($_FILES['attachment']['size'] > $maxBytes) {
        flash_set('error', 'File too large.');
        redirect(url('/invoice.php?action=view&id=' . $id));
    }
    $origName = (string)$_FILES['attachment']['name'];
    $mime     = (string)$_FILES['attachment']['type'];
    $size     = (int)$_FILES['attachment']['size'];
    $allowed = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'bin';
    if (!in_array(strtolower($mime), $allowed, true)
        && !in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        flash_set('error', 'Only PDF or image files are accepted.');
        redirect(url('/invoice.php?action=view&id=' . $id));
    }
    $dir = __DIR__ . '/uploads/invoices';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = 'inv' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . '/' . $fname)) {
        flash_set('error', 'Could not save uploaded file.');
        redirect(url('/invoice.php?action=view&id=' . $id));
    }
    db_exec(
        'INSERT INTO invoice_attachments
           (invoice_id, filename, stored_path, mime_type, size_bytes, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$id, $origName, 'uploads/invoices/' . $fname, $mime ?: 'application/octet-stream',
         $size, (int)current_user_id()]
    );
    flash_set('success', 'Attachment added.');
    redirect(url('/invoice.php?action=view&id=' . $id));
}

if ($action === 'attach_delete') {
    require_permission('invoice', 'manage');
    csrf_check();
    $attId     = (int)input('att_id', 0);
    $invoiceId = (int)input('id', 0);
    $att = db_one('SELECT stored_path, invoice_id FROM invoice_attachments WHERE id = ?', [$attId]);
    if (!$att || (int)$att['invoice_id'] !== $invoiceId) {
        flash_set('error', 'Attachment not found.');
        redirect(url('/invoice.php?action=view&id=' . $invoiceId));
    }
    $p = __DIR__ . '/' . ltrim((string)$att['stored_path'], '/');
    if (is_file($p)) @unlink($p);
    db_exec('DELETE FROM invoice_attachments WHERE id = ?', [$attId]);
    flash_set('success', 'Attachment removed.');
    redirect(url('/invoice.php?action=view&id=' . $invoiceId));
}

// ----------------------------------------------------------------
// NEW / EDIT — form
// ----------------------------------------------------------------
if ($action === 'new' || $action === 'edit') {
    if ($action === 'new') require_permission('invoice', 'manage');
    $id = $action === 'edit' ? (int)input('id', 0) : 0;
    $inv = $id > 0 ? db_one('SELECT * FROM invoices WHERE id = ?', [$id]) : null;
    if ($action === 'edit' && !$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    $items = $id > 0
        ? db_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', [$id])
        : [];

    $vendors = db_all('SELECT id, code, name, is_active FROM vendors ORDER BY name');
    $uoms    = db_all('SELECT code, label FROM inv_uom WHERE is_active = 1 ORDER BY sort_order, code');
    $picker  = invoice_picker_options();

    // Prefill from a deep link (existing pattern). We only fill the
    // vendor — the line items themselves stay empty so the user
    // explicitly enters quantities and prices.
    $prefillVendorId = 0;
    if ($action === 'new') {
        $prefillType = (string)input('link_type', '');
        $prefillId   = (int)input('link_id', 0);
        if ($prefillType === 'asset_txn' && $prefillId > 0) {
            $row = db_one(
                'SELECT COALESCE(from_vendor_id, to_vendor_id) AS vendor_id
                   FROM asset_transactions WHERE id = ?', [$prefillId]);
            if ($row) $prefillVendorId = (int)$row['vendor_id'];
        } elseif ($prefillType === 'inv_receipt' && $prefillId > 0) {
            $row = db_one(
                'SELECT s.vendor_id
                   FROM inv_receipts r LEFT JOIN inv_shipments s ON s.id = r.shipment_id
                  WHERE r.id = ?', [$prefillId]);
            if ($row) $prefillVendorId = (int)$row['vendor_id'];
        }
    }

    $vInvoiceNo   = $inv['invoice_no']   ?? '';
    $vInvoiceDate = $inv['invoice_date'] ?? date('Y-m-d');
    $vVendorId    = (int)($inv['vendor_id'] ?? $prefillVendorId);
    $vCurrency    = $inv['currency']     ?? 'INR';
    $vNotes       = $inv['notes']        ?? '';

    // Ensure we always render at least one empty row so the user has
    // somewhere to start.
    if (empty($items)) $items = [null];
    else $items[] = null;  // one trailing empty row for adding

    $page_title  = $id ? 'Edit invoice' : 'New invoice';
    $page_module = 'invoice';
    require __DIR__ . '/includes/header.php';
    ?>

    <?= form_toolbar([
        'back_href'  => url('/invoice.php' . ($id ? '?action=view&id=' . $id : '')),
        'back_label' => $id ? '← Back to invoice' : '← Back to invoices',
        'title'      => $id ? ('Edit invoice ' . h($inv['invoice_no'])) : 'New invoice',
    ]) ?>

    <form method="post" action="<?= h(url('/invoice.php?action=save')) ?>"
          class="card" style="padding: 18px;">
        <?= csrf_field() ?>
        <?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>

        <div class="grid-2col">
            <div class="field">
                <label for="f_invoice_no">Invoice # <span class="required">*</span></label>
                <input id="f_invoice_no" name="invoice_no" type="text" maxlength="64" required
                       value="<?= h($vInvoiceNo) ?>"
                       placeholder="e.g. INV-2026-0042">
                <span class="muted small">As issued by the vendor.</span>
            </div>
            <div class="field">
                <label for="f_invoice_date">Invoice date <span class="required">*</span></label>
                <input id="f_invoice_date" name="invoice_date" type="date" required
                       value="<?= h($vInvoiceDate) ?>">
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
            <div class="field">
                <label for="f_currency">Currency</label>
                <input id="f_currency" name="currency" type="text" maxlength="8"
                       value="<?= h($vCurrency) ?>" placeholder="INR">
            </div>
        </div>

        <h4 style="margin-top: 18px; margin-bottom: 8px;">Line items</h4>
        <p class="muted small" style="margin-bottom: 10px;">
            One row per billable item. The code dropdowns are searchable — start typing to filter.
            Picking an inventory item auto-fills UOM and the default unit price; you can override
            anything per line. Empty rows are ignored on save.
        </p>

        <style>
            /* Normalize every form control inside the invoice line-items
               table to the same height + padding + border-radius. The
               default app styles target `.form-page-body .field input`
               which doesn't reach inputs inside a data-table cell, so
               the combobox.cb-input + native <select>/<input> in the
               table cells were rendering at three slightly different
               sizes. This scoped rule pulls everything onto a single
               visual baseline.

               Important: we override .cb-input here too because the
               combobox.js default styling is tuned for stand-alone use
               (slightly tighter padding) and looks visually inconsistent
               against the bigger native inputs in adjacent cells.
            */
            .inv-items-table td {
                padding: 6px 8px;
                vertical-align: middle;
            }
            .inv-items-table th { padding: 8px; font-size: 12px; }
            .inv-items-table input[type="text"],
            .inv-items-table input[type="number"],
            .inv-items-table select,
            .inv-items-table .cb-input {
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
            /* The combobox input needs extra right-padding for its
               chevron — preserve that against the generic rule above. */
            .inv-items-table .cb-input {
                padding-right: 28px;
            }
            /* Native <select> arrows get cramped without a matching
               right-pad — keep them readable. */
            .inv-items-table select {
                padding-right: 24px;
            }
            .inv-items-table .cb-chev { right: 8px; }
            /* The cb-wrap is a flex container by default but absolute
               positioning of the chevron requires position:relative. The
               main combobox CSS handles this already; just make sure
               the wrap takes full cell width here. */
            .inv-items-table .cb-wrap { width: 100%; }

            /* Subtotal row: heavier border on top, no row-hover. */
            .inv-items-table tfoot td { border-top: 2px solid var(--border); padding-top: 10px; }
        </style>

        <table class="data-table inv-items-table" id="invoice-items-tbl">
            <thead>
                <tr>
                    <th>Code & description</th>
                    <th class="r" style="width: 90px;">Qty</th>
                    <th style="width: 90px;">UOM</th>
                    <th class="r" style="width: 120px;">Unit price</th>
                    <th class="r" style="width: 80px;">GST %</th>
                    <th style="width: 110px;">HSN</th>
                    <th class="r" style="width: 120px;">Line total</th>
                    <th style="width: 44px;"></th>
                </tr>
            </thead>
            <tbody id="invoice-items-body">
            <?php $ri = 0; foreach ($items as $it):
                $isExisting = is_array($it);
                $iKind  = $isExisting ? $it['item_kind']   : '';
                $iCode  = $isExisting ? $it['item_code']   : '';
                $iPick  = $isExisting
                    ? (($iKind === 'asset' ? 'asset:' : 'inv:') . $iCode)
                    : '';
                $iQty   = $isExisting ? rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.') : '';
                $iUom   = $isExisting ? $it['uom']         : 'pcs';
                $iPrice = $isExisting ? rtrim(rtrim(number_format((float)$it['unit_price'], 4, '.', ''), '0'), '.') : '';
                $iGst   = $isExisting && $it['gst_rate'] !== null
                            ? rtrim(rtrim(number_format((float)$it['gst_rate'], 2, '.', ''), '0'), '.') : '';
                $iHsn   = $isExisting ? ($it['hsn_code'] ?? '') : '';
                // Has the saved pick survived in the picker's current
                // option list? If the inv item / asset was removed or
                // deactivated we still want the row to render with the
                // historic value selected, so we tack on a one-off
                // option below when needed.
                $inPicker = false;
                foreach ($picker as $po) { if ($po['value'] === $iPick) { $inPicker = true; break; } }
            ?>
                <tr class="inv-item-row">
                    <td>
                        <select name="item_pick[]" class="inv-item-pick">
                            <option value="">— pick an item (search by code or name) —</option>
                            <?php foreach ($picker as $opt): ?>
                                <option value="<?= h($opt['value']) ?>"
                                        data-uom="<?= h($opt['uom']) ?>"
                                        data-price="<?= h($opt['unit_cost'] ?? '') ?>"
                                        <?= $opt['value'] === $iPick ? 'selected' : '' ?>>
                                    <?= h($opt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($iPick !== '' && !$inPicker): ?>
                                <option value="<?= h($iPick) ?>" selected>
                                    <?= h($iCode) ?> — (inactive / removed)
                                </option>
                            <?php endif; ?>
                        </select>
                    </td>
                    <td class="r">
                        <input type="number" step="0.001" min="0" class="inv-item-qty r"
                               name="item_qty[]" value="<?= h($iQty) ?>" placeholder="1">
                    </td>
                    <td>
                        <select name="item_uom[]" class="no-combobox">
                            <?php foreach ($uoms as $u): ?>
                                <option value="<?= h($u['code']) ?>"
                                        <?= $iUom === $u['code'] ? 'selected' : '' ?>>
                                    <?= h($u['code']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($iUom && !in_array($iUom, array_column($uoms, 'code'), true)): ?>
                                <option value="<?= h($iUom) ?>" selected><?= h($iUom) ?></option>
                            <?php endif; ?>
                        </select>
                    </td>
                    <td class="r">
                        <input type="number" step="0.0001" min="0" class="inv-item-price r"
                               name="item_price[]" value="<?= h($iPrice) ?>" placeholder="0.00">
                    </td>
                    <td class="r">
                        <input type="number" step="0.01" min="0" max="100" class="r"
                               name="item_gst[]" value="<?= h($iGst) ?>" placeholder="18">
                    </td>
                    <td>
                        <input type="text" maxlength="16" name="item_hsn[]"
                               value="<?= h($iHsn) ?>" placeholder="HSN">
                    </td>
                    <td class="r inv-item-total muted">—</td>
                    <td class="r">
                        <button type="button" class="btn btn-icon btn-danger inv-item-remove"
                                title="Remove this line">🗑</button>
                    </td>
                </tr>
                <?php $ri++; endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" class="r"><strong>Subtotal (qty × price, GST extra):</strong></td>
                    <td class="r"><strong id="invoice-items-subtotal">—</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <div style="margin-top: 8px;">
            <button type="button" class="btn btn-ghost btn-sm" id="invoice-items-add">+ Add line</button>
        </div>

        <div class="field" style="margin-top: 18px;">
            <label for="f_notes">Notes</label>
            <textarea id="f_notes" name="notes" rows="3"
                      placeholder="Anything an approver should know (PO ref, partial-billing context)."><?= h($vNotes) ?></textarea>
        </div>

        <div class="form-actions" style="margin-top: 16px;">
            <button type="submit" class="btn btn-primary">
                <?= $id ? 'Save changes' : 'Create invoice' ?>
            </button>
        </div>
    </form>

    <script>
    (function () {
        // The invoice line-items table is dynamic: rows can be added,
        // removed, and each row's totals + auto-fill behavior runs as
        // the user types. Plain DOM manipulation.

        var tbl  = document.getElementById('invoice-items-tbl');
        var body = document.getElementById('invoice-items-body');
        if (!tbl || !body) return;

        function fmt2(n) {
            if (isNaN(n) || !isFinite(n)) return '—';
            return n.toFixed(2);
        }
        function recalcRow(row) {
            var q = parseFloat(row.querySelector('.inv-item-qty').value)   || 0;
            var p = parseFloat(row.querySelector('.inv-item-price').value) || 0;
            var t = q * p;
            row.querySelector('.inv-item-total').textContent = t > 0 ? fmt2(t) : '—';
            row.querySelector('.inv-item-total').classList.toggle('muted', t <= 0);
            recalcSubtotal();
        }
        function recalcSubtotal() {
            var sub = 0;
            body.querySelectorAll('tr.inv-item-row').forEach(function (row) {
                var q = parseFloat(row.querySelector('.inv-item-qty').value)   || 0;
                var p = parseFloat(row.querySelector('.inv-item-price').value) || 0;
                sub += q * p;
            });
            document.getElementById('invoice-items-subtotal').textContent = sub > 0 ? fmt2(sub) : '—';
        }

        // Auto-fill UOM + unit price when an inv-item code is picked.
        // The picked <option>'s data-uom / data-price attrs are set
        // server-side; asset rows have empty data-uom and no data-price
        // so this is a no-op for them. We only overwrite empty fields
        // so user edits aren't trampled on re-pick.
        function autoFillFromPick(row) {
            var sel = row.querySelector('.inv-item-pick');
            if (!sel) return;
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;
            if (opt.dataset.uom) {
                var uomSel = row.querySelector('select[name="item_uom[]"]');
                if (uomSel) {
                    for (var i = 0; i < uomSel.options.length; i++) {
                        if (uomSel.options[i].value === opt.dataset.uom) {
                            uomSel.selectedIndex = i;
                            break;
                        }
                    }
                }
            }
            if (opt.dataset.price) {
                var priceInp = row.querySelector('.inv-item-price');
                if (priceInp && !priceInp.value) {
                    priceInp.value = opt.dataset.price;
                    recalcRow(row);
                }
            }
        }

        function wireRow(row) {
            if (row._wired) return;
            row._wired = true;
            row.querySelector('.inv-item-pick').addEventListener('change', function () {
                autoFillFromPick(row);
            });
            row.querySelectorAll('.inv-item-qty, .inv-item-price').forEach(function (inp) {
                inp.addEventListener('input', function () { recalcRow(row); });
            });
            row.querySelector('.inv-item-remove').addEventListener('click', function () {
                if (body.querySelectorAll('tr.inv-item-row').length <= 1) return;
                row.parentNode.removeChild(row);
                recalcSubtotal();
            });
            recalcRow(row);
        }

        document.getElementById('invoice-items-add').addEventListener('click', function () {
            var rows = body.querySelectorAll('tr.inv-item-row');
            var tmpl = rows[rows.length - 1].cloneNode(true);
            tmpl.querySelectorAll('input').forEach(function (inp) {
                if (inp.type === 'number' || inp.type === 'text') inp.value = '';
            });
            tmpl.querySelectorAll('select').forEach(function (sel) {
                sel.classList.remove('cb-bound', 'cb-native');
                sel.style.display = '';
                sel.disabled = false;
                sel.selectedIndex = 0;
            });
            // Strip cloned cb-wrap nodes so combobox can re-wrap fresh.
            tmpl.querySelectorAll('.cb-wrap').forEach(function (wrap) {
                var sel = wrap.querySelector('select');
                if (sel) {
                    wrap.parentNode.insertBefore(sel, wrap);
                    wrap.parentNode.removeChild(wrap);
                }
            });
            tmpl._wired = false;
            tmpl.querySelector('.inv-item-total').textContent = '—';
            tmpl.querySelector('.inv-item-total').classList.add('muted');
            body.appendChild(tmpl);
            wireRow(tmpl);
            if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
                window.MagDynCombobox.initAll();
            }
        });

        body.querySelectorAll('tr.inv-item-row').forEach(wireRow);
    })();
    </script>

    <?php require __DIR__ . '/includes/footer.php'; exit; }

// ----------------------------------------------------------------
// APPROVE — confirm page (linking lives on the Links page now)
// ----------------------------------------------------------------
// The old picker UI was retired when invoice_lines moved from per-
// header to per-invoice-item linkage. Linking is handled on
// action=links (a dedicated editor with strict code matching and
// per-line qty allocation). Approving an invoice now just flips the
// status flag; the link rows are managed independently.
if ($action === 'approve_form') {
    require_permission('invoice', 'manage');
    $id = (int)input('id', 0);
    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    $vendor = db_one('SELECT code, name FROM vendors WHERE id = ?', [(int)$inv['vendor_id']]);
    $items  = db_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', [$id]);

    // Roll up linked / unlinked totals across all line items so the
    // approver sees what coverage looks like before signing off.
    $totalQty = 0.0; $totalLinked = 0.0;
    foreach ($items as $it) {
        $totalQty    += (float)$it['qty'];
        $totalLinked += invoice_item_qty_linked((int)$it['id']);
    }
    $totalUnlinked = max(0.0, $totalQty - $totalLinked);

    $page_title  = 'Approve invoice ' . $inv['invoice_no'];
    $page_module = 'invoice';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'title'      => 'Approve · ' . h($inv['invoice_no']),
        'subtitle'   => 'Confirm the status change. Linking is managed separately on the Links page.',
        'back_href'  => url('/invoice.php?action=view&id=' . $id),
        'back_label' => '← Back to invoice',
    ]) ?>
    <div class="form-page-body">
        <div class="card" style="margin-bottom:14px;">
            <div class="card-body">
                <strong><?= h($inv['invoice_no']) ?></strong>
                · <?= h($inv['invoice_date']) ?>
                · <?= h($vendor ? $vendor['name'] : '—') ?>
                <span class="pill pill-neutral"><?= h($inv['status']) ?></span>
            </div>
        </div>

        <div class="card" style="margin-bottom:14px;">
            <div class="card-head"><h3 style="margin:0;">Coverage</h3></div>
            <div class="card-body">
                <p class="muted small">
                    Across <?= count($items) ?> line item<?= count($items) === 1 ? '' : 's' ?>:
                    total qty <strong><?= h(rtrim(rtrim(number_format($totalQty, 3), '0'), '.')) ?></strong>
                    · linked <strong style="color:#059669"><?= h(rtrim(rtrim(number_format($totalLinked, 3), '0'), '.')) ?></strong>
                    · unlinked <strong style="<?= $totalUnlinked > 0 ? 'color:#b45309' : '' ?>"><?= h(rtrim(rtrim(number_format($totalUnlinked, 3), '0'), '.')) ?></strong>.
                </p>
                <?php if ($totalUnlinked > 0): ?>
                    <div class="callout warn">
                        <div class="label">Partial coverage</div>
                        <p>Some invoice qty is not yet linked to a receipt or asset transaction.
                           You can still approve, but consider opening the
                           <a href="<?= h(url('/invoice.php?action=links&id=' . $id)) ?>">Links page</a>
                           first to attach the missing pieces.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" action="<?= h(url('/invoice.php?action=approve_save')) ?>"
                      style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"
                      onsubmit="return confirm('Approve this invoice? Status flips to approved.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-primary">✓ Approve invoice</button>
                    <a class="btn btn-ghost" href="<?= h(url('/invoice.php?action=links&id=' . $id)) ?>">🔗 Manage links first</a>
                    <a class="btn btn-ghost" href="<?= h(url('/invoice.php?action=view&id=' . $id)) ?>">Cancel</a>
                </form>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}


// ----------------------------------------------------------------
// VIEW — detail page
// ----------------------------------------------------------------
if ($action === 'view') {
    $id = (int)input('id', 0);
    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    $vendor = db_one('SELECT code, name FROM vendors WHERE id = ?', [(int)$inv['vendor_id']]);
    $approver = $inv['approved_by']
        ? db_one('SELECT full_name FROM users WHERE id = ?', [(int)$inv['approved_by']])
        : null;
    $items = db_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', [$id]);
    $attachments = db_all(
        'SELECT * FROM invoice_attachments WHERE invoice_id = ? ORDER BY uploaded_at DESC',
        [$id]
    );
    $links = db_all(
        "SELECT l.id, l.invoice_item_id, l.link_kind, l.asset_txn_id, l.inv_receipt_id, l.qty,
                CASE l.link_kind
                    WHEN 'asset' THEN a.asset_tag
                    WHEN 'inv'   THEN ii.code
                END AS link_code,
                CASE l.link_kind
                    WHEN 'asset' THEN CONCAT(t.txn_type, ' on ', SUBSTRING(t.at, 1, 16))
                    WHEN 'inv'   THEN CONCAT(r.receipt_no, ' on ', r.receipt_date)
                END AS link_label,
                CASE l.link_kind
                    WHEN 'asset' THEN t.asset_id
                END AS deep_asset_id
           FROM invoice_lines l
           JOIN invoice_items it_parent       ON it_parent.id = l.invoice_item_id
      LEFT JOIN asset_transactions t          ON t.id = l.asset_txn_id
      LEFT JOIN assets a                      ON a.id = t.asset_id
      LEFT JOIN inv_receipts r                ON r.id  = l.inv_receipt_id
      LEFT JOIN inv_shipment_lines rsl        ON rsl.id = r.shipment_line_id
      LEFT JOIN inv_items ii                  ON ii.id = rsl.item_id
          WHERE it_parent.invoice_id = ?
          ORDER BY l.id",
        [$id]
    );
    $subtotal = invoice_line_total_subtotal($id);
    $gstTotal = 0;
    foreach ($items as $it) {
        if ($it['gst_rate'] !== null) {
            $gstTotal += (float)$it['qty'] * (float)$it['unit_price'] * ((float)$it['gst_rate'] / 100);
        }
    }

    $page_title  = 'Invoice ' . $inv['invoice_no'];
    $page_module = 'invoice';
    require __DIR__ . '/includes/header.php';

    $actions = '';
    if ($canManage) {
        if ($inv['status'] === 'pending') {
            $actions .= '<a class="btn btn-primary btn-sm" href="' . h(url('/invoice.php?action=approve_form&id=' . $id)) . '">✓ Approve</a> ';
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/invoice.php?action=reject_save')) . '"'
                      . ' onsubmit="var r = prompt(\'Reason for rejection?\'); if (r === null) return false; this.elements.rejection_reason.value = r; return true;">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<input type="hidden" name="rejection_reason" value="">'
                      . '<button type="submit" class="btn btn-ghost btn-sm">✗ Reject</button></form> ';
        } else {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/invoice.php?action=reopen')) . '"'
                      . ' onsubmit="return confirm(\'Reopen this invoice? Its transaction links will be cleared.\');">'
                      . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                      . '<button type="submit" class="btn btn-ghost btn-sm">↻ Reopen</button></form> ';
        }
        $actions .= '<a class="btn btn-ghost btn-sm" href="' . h(url('/invoice.php?action=edit&id=' . $id)) . '">✎ Edit</a> ';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/invoice.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Delete invoice &quot;' . h(addslashes($inv['invoice_no'])) . '&quot;? This removes all lines, links, and attachments.\');">'
                  . csrf_field() . '<input type="hidden" name="id" value="' . (int)$id . '">'
                  . '<button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button></form>';
    }
    $statusPill = '<span class="pill pill-'
        . ($inv['status'] === 'approved' ? 'active'
            : ($inv['status'] === 'rejected' ? 'danger' : 'neutral'))
        . '">' . h($inv['status']) . '</span>';
    ?>

    <?= form_toolbar([
        'back_href'    => url('/invoice.php'),
        'back_label'   => '← Back to invoices',
        'title'        => 'Invoice ' . h($inv['invoice_no']),
        'actions_html' => $actions,
    ]) ?>

    <div class="card" style="padding: 18px; margin-bottom: 14px;">
        <div class="grid-2col">
            <div><div class="muted small">Invoice #</div><div><strong><?= h($inv['invoice_no']) ?></strong></div></div>
            <div><div class="muted small">Status</div><div><?= $statusPill ?></div></div>
            <div><div class="muted small">Date</div><div><?= h($inv['invoice_date']) ?></div></div>
            <div><div class="muted small">Vendor</div><div><?= $vendor ? '<code>' . h($vendor['code']) . '</code> ' . h($vendor['name']) : '—' ?></div></div>
            <?php if ($inv['status'] === 'approved' && $approver): ?>
                <div><div class="muted small">Approved by</div><div><?= h($approver['full_name']) ?> · <?= h(substr((string)$inv['approved_at'], 0, 16)) ?></div></div>
            <?php endif; ?>
            <?php if ($inv['status'] === 'rejected' && $inv['rejection_reason']): ?>
                <div><div class="muted small">Rejection reason</div><div><?= h($inv['rejection_reason']) ?></div></div>
            <?php endif; ?>
        </div>
        <?php if ($inv['notes']): ?>
            <div style="margin-top: 14px;">
                <div class="muted small">Notes</div>
                <div style="white-space: pre-wrap;"><?= h($inv['notes']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Line items -->
    <div class="card" style="padding: 18px; margin-bottom: 14px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px;gap:14px;">
            <h3 style="margin: 0;">Line items</h3>
            <?php if ($canManage && !empty($items)): ?>
                <a class="btn btn-ghost btn-sm" href="<?= h(url('/invoice.php?action=links&id=' . (int)$id)) ?>">
                    🔗 Manage links
                </a>
            <?php endif; ?>
        </div>
        <?php if (empty($items)): ?>
            <p class="muted empty">No line items.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Kind</th>
                        <th>Code</th>
                        <th>Description</th>
                        <th class="r">Qty</th>
                        <th>UOM</th>
                        <th class="r">Unit price</th>
                        <th class="r">Linked</th>
                        <th class="r">Unlinked</th>
                        <th class="r">GST %</th>
                        <th>HSN</th>
                        <th class="r">Line total</th>
                    </tr>
                </thead>
                <tbody>
                <?php $rn = 1; foreach ($items as $it):
                    $lt = (float)$it['qty'] * (float)$it['unit_price'];
                    $qLinked   = invoice_item_qty_linked((int)$it['id']);
                    $qUnlinked = max(0.0, (float)$it['qty'] - $qLinked);
                    $linkedDisp   = rtrim(rtrim(number_format($qLinked, 3, '.', ''), '0'), '.');
                    $unlinkedDisp = rtrim(rtrim(number_format($qUnlinked, 3, '.', ''), '0'), '.');
                    ?>
                    <tr>
                        <td class="muted"><?= $rn++ ?></td>
                        <td><span class="muted small"><?= h($it['item_kind'] === 'asset' ? 'asset' : 'inv') ?></span></td>
                        <td><code><?= h($it['item_code']) ?></code></td>
                        <td><?= h($it['description'] ?: '—') ?></td>
                        <td class="r"><?= h(rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.')) ?></td>
                        <td><?= h($it['uom']) ?></td>
                        <td class="r"><?= h(number_format((float)$it['unit_price'], 2)) ?></td>
                        <td class="r"><?php if ($qLinked > 0): ?><strong style="color:#059669"><?= h($linkedDisp) ?></strong><?php else: ?><span class="muted">0</span><?php endif; ?></td>
                        <td class="r"><?php if ($qUnlinked > 0): ?><strong style="color:#b45309"><?= h($unlinkedDisp) ?></strong><?php else: ?><span class="muted">0</span><?php endif; ?></td>
                        <td class="r"><?= $it['gst_rate'] !== null ? h(rtrim(rtrim(number_format((float)$it['gst_rate'], 2, '.', ''), '0'), '.')) . '%' : '—' ?></td>
                        <td><?= h($it['hsn_code'] ?: '—') ?></td>
                        <td class="r"><?= h(number_format($lt, 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="11" class="r"><strong>Subtotal</strong></td>
                        <td class="r"><strong><?= h($inv['currency']) ?> <?= h(number_format($subtotal, 2)) ?></strong></td>
                    </tr>
                    <?php if ($gstTotal > 0): ?>
                    <tr>
                        <td colspan="11" class="r muted">GST</td>
                        <td class="r muted"><?= h($inv['currency']) ?> <?= h(number_format($gstTotal, 2)) ?></td>
                    </tr>
                    <tr>
                        <td colspan="11" class="r"><strong>Total (incl. GST)</strong></td>
                        <td class="r"><strong><?= h($inv['currency']) ?> <?= h(number_format($subtotal + $gstTotal, 2)) ?></strong></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <!-- Linked transactions -->
    <div class="card" style="padding: 18px; margin-bottom: 14px;">
        <h3 style="margin: 0 0 10px;">Linked transactions</h3>
        <?php if (empty($links)): ?>
            <p class="muted empty" style="text-align: left; padding: 8px 0;">
                <?php if ($inv['status'] === 'pending'): ?>
                    No transactions linked yet — approve the invoice to pick them.
                <?php else: ?>
                    No transactions linked.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Kind</th><th>Code</th><th>Reference</th><th class="r">Linked qty</th></tr></thead>
                <tbody>
                <?php foreach ($links as $l):
                    $linkUrl = '';
                    if ($l['link_kind'] === 'asset' && $l['deep_asset_id']) {
                        $linkUrl = url('/asset.php?action=edit&id=' . (int)$l['deep_asset_id']);
                    } ?>
                    <tr>
                        <td><?= $l['link_kind'] === 'asset'
                              ? '<span class="pill pill-neutral">Asset txn</span>'
                              : '<span class="pill pill-neutral">Inv receipt</span>' ?></td>
                        <td><code><?= h($l['link_code'] ?: '—') ?></code></td>
                        <td>
                            <?php if ($linkUrl): ?>
                                <a href="<?= h($linkUrl) ?>"><?= h($l['link_label'] ?: '—') ?></a>
                            <?php else: ?>
                                <?= h($l['link_label'] ?: '—') ?>
                            <?php endif; ?>
                        </td>
                        <td class="r"><?= h(rtrim(rtrim(number_format((float)$l['qty'], 3, '.', ''), '0'), '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Attachments -->
    <div class="card" style="padding: 18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 12px;">
            <h3 style="margin: 0;">Attachments</h3>
            <?php if ($canManage): ?>
                <form method="post" enctype="multipart/form-data" style="margin:0;"
                      action="<?= h(url('/invoice.php?action=attach_upload')) ?>"
                      data-drop-zone="invoice-attach">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <input type="file" name="attachment" accept=".pdf,image/png,image/jpeg,image/gif,image/webp"
                           onchange="this.form.submit();"
                           style="display: inline-block;">
                    <span class="muted small">Drop a file (PDF or image, max <?= (int)$GLOBALS['APP']['upload_max_mb'] ?> MB).</span>
                </form>
            <?php endif; ?>
        </div>
        <?php if (empty($attachments)): ?>
            <p class="muted empty" style="text-align: left; padding: 14px 0;">No attachments yet.</p>
        <?php else: ?>
            <div class="invoice-att-list">
            <?php foreach ($attachments as $a):
                $relPath = (string)$a['stored_path'];
                $downloadUrl = url('/' . ltrim($relPath, '/')); ?>
                <div class="invoice-att-row"
                     style="display:flex; align-items:center; gap:12px; padding:10px 0; border-top: 1px solid var(--border);">
                    <div style="flex: 1 1 auto; min-width: 0;">
                        <a href="<?= h($downloadUrl) ?>" target="_blank" rel="noopener">
                            <strong><?= h($a['filename']) ?></strong>
                        </a>
                        <div class="muted small">
                            <?= h($a['mime_type']) ?> · <?= number_format(((int)$a['size_bytes']) / 1024, 1) ?> KB
                            · <?= h(substr((string)$a['uploaded_at'], 0, 16)) ?>
                        </div>
                    </div>
                    <?php if ($canManage): ?>
                        <form method="post" style="margin:0;"
                              action="<?= h(url('/invoice.php?action=attach_delete')) ?>"
                              onsubmit="return confirm('Remove this attachment?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$id ?>">
                            <input type="hidden" name="att_id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn btn-icon btn-danger" title="Remove">🗑</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    // Running notes section — uses the generic notes module with
    // entity_type='invoice'. The same entity_type is recognized by
    // notes_render() (it isn't allowlisted internally — any string
    // works). notes_attachment_preview_assets() emits the modal +
    // JS that lets attachments inside notes open in a previewer
    // (image / PDF / CAD); without that call, clicking a note
    // attachment falls back to a plain download link. Calling it
    // here (and only here) is idempotent: the helper itself uses a
    // static guard so duplicate calls are no-ops.
    if (function_exists('notes_render')) {
        notes_render('invoice', $id, 'inline');
    }
    if (function_exists('notes_attachment_preview_assets')) {
        notes_attachment_preview_assets();
    }
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ----------------------------------------------------------------
// LINKS — per-line linker page
// ----------------------------------------------------------------
// One page per invoice. Top: invoice header summary. Body: one
// "card" per invoice_item, each showing:
//   - the item identity (kind, code, description, qty, uom)
//   - the running linked/unlinked qty totals
//   - the existing link rows with a per-row Remove button
//   - an "Add link" picker scoped to candidate txns whose item code
//     matches THIS line's item_code (strict match, per user policy)
//
// The page is reachable from the invoice list (new "Links" action),
// the invoice view summary, and the (renamed) Approve flow CTA.
// ----------------------------------------------------------------
if ($action === 'links') {
    $id = (int)input('id', 0);
    $inv = db_one(
        'SELECT inv.*, v.name AS vendor_name, v.code AS vendor_code
           FROM invoices inv
      LEFT JOIN vendors v ON v.id = inv.vendor_id
          WHERE inv.id = ?', [$id]
    );
    if (!$inv) {
        flash_set('error', 'Invoice not found.');
        redirect(url('/invoice.php'));
    }
    $items = db_all(
        'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id',
        [$id]
    );

    // Build per-item candidate-txn lists. For each invoice_item, find
    // every asset_txn or inv_receipt sharing its item_code AND still
    // carrying any unlinked qty. Strict code match (= comparison).
    //
    // We resolve "unlinked on txn" via the helper rather than a single
    // big SQL — readability over peak performance; n_items × small
    // is fine since invoices rarely exceed a few dozen lines.
    $candidates = [];
    foreach ($items as $it) {
        $iid = (int)$it['id'];
        if ($it['item_kind'] === 'asset') {
            $rows = db_all(
                "SELECT at.id AS txn_id, DATE(at.at) AS txn_date, at.txn_type,
                        a.asset_tag AS code
                   FROM asset_transactions at
                   JOIN assets a ON a.id = at.asset_id
                  WHERE a.asset_tag = ?
                    AND at.txn_type IN ('create','receive_vendor','receive_user')
                  ORDER BY at.at DESC, at.id DESC
                  LIMIT 200",
                [$it['item_code']]
            );
            foreach ($rows as &$r) {
                $r['unlinked'] = invoice_link_txn_qty_unlinked('asset', (int)$r['txn_id']);
            }
            unset($r);
            $candidates[$iid] = ['kind' => 'asset', 'rows' => $rows];
        } else {
            $rows = db_all(
                "SELECT r.id AS txn_id, r.receipt_no, r.receipt_date,
                        r.qty_received, i.code AS code
                   FROM inv_receipts r
                   JOIN inv_shipment_lines sl ON sl.id = r.shipment_line_id
                   JOIN inv_items i           ON i.id = sl.item_id
              LEFT JOIN inv_shipments s       ON s.id = r.shipment_id
                  WHERE i.code = ?
                    AND r.qty_received > 0
                    AND COALESCE(s.is_rework, 0) = 0
                  ORDER BY r.receipt_date DESC, r.id DESC
                  LIMIT 200",
                [$it['item_code']]
            );
            foreach ($rows as &$r) {
                $r['unlinked'] = invoice_link_txn_qty_unlinked('inv', (int)$r['txn_id']);
            }
            unset($r);
            $candidates[$iid] = ['kind' => 'inv', 'rows' => $rows];
        }
    }

    $page_title  = 'Invoice ' . $inv['invoice_no'] . ' — links';
    $page_module = 'invoice';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'title'    => 'Links · Invoice ' . h($inv['invoice_no']),
        'subtitle' => 'Per-line link to receipts / asset transactions. Strict code match.',
        'back_href'  => url('/invoice.php?action=view&id=' . $id),
        'back_label' => '← Back to invoice',
    ]) ?>
    <div class="form-page-body">
        <div class="card" style="margin-bottom:14px;">
            <div class="card-body">
                <strong><?= h($inv['invoice_no']) ?></strong>
                · <?= h($inv['invoice_date']) ?>
                · <?= h($inv['vendor_name'] ?: '—') ?>
                <span class="pill pill-<?= $inv['status'] === 'approved' ? 'active' : ($inv['status'] === 'rejected' ? 'danger' : 'neutral') ?>"><?= h($inv['status']) ?></span>
            </div>
        </div>

        <?php if (!$items): ?>
            <div class="callout warn">
                <p>This invoice has no line items. Add at least one item from the
                   <a href="<?= h(url('/invoice.php?action=edit&id=' . $id)) ?>">Edit</a>
                   page before linking transactions.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($items as $it):
            $iid       = (int)$it['id'];
            $links     = invoice_item_links($iid);
            $qtyTotal  = (float)$it['qty'];
            $qtyLinked = invoice_item_qty_linked($iid);
            $qtyOpen   = max(0.0, $qtyTotal - $qtyLinked);
            $kindLabel = $it['item_kind'] === 'asset' ? 'Asset' : 'Inv item';
            $cand      = $candidates[$iid];
            ?>
            <div class="card" style="margin-bottom:14px;">
                <div class="card-head">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap;">
                        <div>
                            <span class="muted small"><?= h($kindLabel) ?></span>
                            <strong>(<?= h($it['item_code']) ?>)-<?= h($it['description'] ?: '—') ?></strong>
                        </div>
                        <div class="muted small">
                            Qty <strong><?= h(rtrim(rtrim(number_format($qtyTotal, 3), '0'), '.')) ?></strong> <?= h($it['uom']) ?>
                            · Linked <strong><?= h(rtrim(rtrim(number_format($qtyLinked, 3), '0'), '.')) ?></strong>
                            · Unlinked <strong style="<?= $qtyOpen > 0 ? 'color:#b45309' : '' ?>"><?= h(rtrim(rtrim(number_format($qtyOpen, 3), '0'), '.')) ?></strong>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Existing links -->
                    <?php if ($links): ?>
                        <table class="data-table" style="margin-bottom:10px;">
                            <thead>
                                <tr>
                                    <th>Linked txn</th>
                                    <th>Date</th>
                                    <th class="r">Qty linked</th>
                                    <th>By</th>
                                    <th class="r">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($links as $L): ?>
                                <tr>
                                    <td><code><?= h($L['target_label'] ?: '—') ?></code></td>
                                    <td><?= h($L['target_date'] ?: '—') ?></td>
                                    <td class="r"><?= h(rtrim(rtrim(number_format((float)$L['qty'], 3), '0'), '.')) ?></td>
                                    <td class="muted small"><?= h($L['created_by_name'] ?: '—') ?></td>
                                    <td class="r">
                                        <?php if ($canManage): ?>
                                        <form method="post" action="<?= h(url('/invoice.php?action=link_delete_save')) ?>"
                                              style="display:inline"
                                              onsubmit="return confirm('Remove this link?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="link_id" value="<?= (int)$L['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted small" style="margin:0 0 10px;">No links yet on this line.</p>
                    <?php endif; ?>

                    <!-- Add-link form (only when there's room AND candidates exist) -->
                    <?php if ($canManage && $qtyOpen > 0): ?>
                        <?php if (!$cand['rows']): ?>
                            <div class="muted small">No candidate transactions found with matching code <code><?= h($it['item_code']) ?></code>.</div>
                        <?php else: ?>
                            <form method="post" action="<?= h(url('/invoice.php?action=link_create_save')) ?>"
                                  style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;border-top:1px dashed var(--border);padding-top:10px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="invoice_item_id" value="<?= $iid ?>">
                                <input type="hidden" name="link_kind" value="<?= h($cand['kind']) ?>">
                                <div class="field" style="margin:0;min-width:280px;flex:1;">
                                    <label>Candidate txn (code <code><?= h($it['item_code']) ?></code>)</label>
                                    <select name="target_id" required>
                                        <option value="">— Pick —</option>
                                        <?php foreach ($cand['rows'] as $r):
                                            if ($r['unlinked'] <= 0) continue;
                                            if ($cand['kind'] === 'asset') {
                                                $lbl = $r['code'] . ' · ' . $r['txn_type'] . ' · ' . $r['txn_date']
                                                     . ' · unlinked ' . rtrim(rtrim(number_format((float)$r['unlinked'], 3), '0'), '.');
                                            } else {
                                                $lbl = $r['receipt_no'] . ' · ' . $r['receipt_date']
                                                     . ' · received ' . rtrim(rtrim(number_format((float)$r['qty_received'], 3), '0'), '.')
                                                     . ' · unlinked ' . rtrim(rtrim(number_format((float)$r['unlinked'], 3), '0'), '.');
                                            }
                                        ?>
                                            <option value="<?= (int)$r['txn_id'] ?>"
                                                    data-max="<?= h(min((float)$r['unlinked'], $qtyOpen)) ?>">
                                                <?= h($lbl) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field" style="margin:0;width:130px;">
                                    <label>Qty to link</label>
                                    <input type="number" name="qty" step="0.001" min="0.001"
                                           max="<?= h($qtyOpen) ?>" required
                                           value="<?= h(rtrim(rtrim(number_format($qtyOpen, 3), '0'), '.')) ?>">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-sm">Add link</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($qtyOpen <= 0): ?>
                        <div class="muted small" style="border-top:1px dashed var(--border);padding-top:10px;">
                            Fully linked. Remove an existing link to free qty for re-allocation.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ----------------------------------------------------------------
// COVERAGE REPORTS — read-only views answering "what's been
// invoiced vs not". Two pages, both gated by the invoice.view
// permission already required at the top of this file.
//
// coverage_items
//   /invoice.php?action=coverage_items
//   /invoice.php?action=coverage_items&tab=receipts
//
//   tab=items (default): one row per inv_items row, with received
//   qty (across all receipts) and qty linked to invoice_lines.
//   The open column = received - linked, floored at 0 so a future
//   over-link (qty_invoiced > qty_received, e.g. from a manual
//   correction) doesn't render as negative.
//
//   tab=receipts: one row per inv_receipts event, with that
//   receipt's qty_received and the SUM of invoice_lines.qty
//   pointing at it. Useful for finding specific partial deliveries
//   that haven't been invoiced.
//
// coverage_txns
//   /invoice.php?action=coverage_txns
//   /invoice.php?action=coverage_txns&filter={all|linked|open|n_a}
//
//   One row per inv_txns ledger entry. Only ship_in txns are
//   invoice-eligible (those carry an inv_receipts row); everything
//   else (issue, ship_out, adjust, process) shows as N/A. Filter
//   pill switches which subset is visible.
//
// Rejected invoices are EXCLUDED from "linked" totals — a rejected
// invoice is no longer a valid claim against the received stock.
// Pending + approved both count.
// ----------------------------------------------------------------

if ($action === 'coverage_items') {
    $tab = (string)input('tab', 'items');
    if (!in_array($tab, ['items', 'receipts'], true)) $tab = 'items';

    $page_title  = 'Item coverage — invoices';
    $page_module = 'invoice_coverage_items';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/invoice.php'),
        'back_label' => 'Back to invoices',
        'title'      => 'Item coverage',
    ]) ?>

    <div class="card" style="padding: 12px; margin-bottom: 12px;">
        <div style="display:flex; gap:6px; align-items:center;">
            <span class="muted small" style="margin-right: 6px;">View:</span>
            <a class="btn btn-sm <?= $tab === 'items' ? 'btn-primary' : 'btn-ghost' ?>"
               href="<?= h(url('/invoice.php?action=coverage_items&tab=items')) ?>">By item</a>
            <a class="btn btn-sm <?= $tab === 'receipts' ? 'btn-primary' : 'btn-ghost' ?>"
               href="<?= h(url('/invoice.php?action=coverage_items&tab=receipts')) ?>">By receipt event</a>
        </div>
    </div>

    <?php if ($tab === 'items'):
        // ----- BY ITEM ----------------------------------------------------
        // For every item that has received SOMETHING, compute received
        // and linked totals. We DON'T list items with zero receipts —
        // they have no invoice exposure either way and would just be
        // noise on the report. If the user wants to see those too,
        // a future filter pill can flip this.
        $rows = db_all(
            "SELECT
                i.id, i.code, i.name, i.short_description, i.uom_id,
                u.symbol AS uom_symbol,
                COALESCE(r_tot.received, 0) AS received_qty,
                COALESCE(l_tot.linked,   0) AS linked_qty
              FROM inv_items i
              LEFT JOIN inv_uom u ON u.id = i.uom_id
              LEFT JOIN (
                  SELECT sl.item_id, SUM(r.qty_received) AS received
                    FROM inv_receipts r
                    JOIN inv_shipment_lines sl ON sl.id = r.shipment_line_id
                   GROUP BY sl.item_id
              ) r_tot ON r_tot.item_id = i.id
              LEFT JOIN (
                  SELECT sl.item_id, SUM(il.qty) AS linked
                    FROM invoice_lines il
                    JOIN inv_receipts r        ON r.id = il.inv_receipt_id
                    JOIN inv_shipment_lines sl ON sl.id = r.shipment_line_id
                    JOIN invoice_items ii      ON ii.id = il.invoice_item_id
                    JOIN invoices inv          ON inv.id = ii.invoice_id
                   WHERE il.link_kind = 'inv'
                     AND inv.status IN ('pending', 'approved')
                   GROUP BY sl.item_id
              ) l_tot ON l_tot.item_id = i.id
             WHERE COALESCE(r_tot.received, 0) > 0
             ORDER BY i.code"
        );
        ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th class="r">Received</th>
                    <th class="r">Linked to invoice</th>
                    <th class="r">Open (not linked)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $totReceived = 0; $totLinked = 0; $totOpen = 0;
            foreach ($rows as $r):
                $received = (float)$r['received_qty'];
                $linked   = (float)$r['linked_qty'];
                $open     = max(0, $received - $linked);
                $totReceived += $received;
                $totLinked   += $linked;
                $totOpen     += $open;
                if ($open <= 0.001) {
                    $statusPill = '<span class="pill pill-success">fully linked</span>';
                } elseif ($linked <= 0.001) {
                    $statusPill = '<span class="pill pill-warning">none linked</span>';
                } else {
                    $statusPill = '<span class="pill pill-info">partial</span>';
                }
                $uom = $r['uom_symbol'] ? ' ' . h($r['uom_symbol']) : '';
                ?>
                <tr>
                    <td><code><?= h($r['code']) ?></code></td>
                    <td><?= h($r['short_description'] ?: $r['name']) ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($received, 3), '0'), '.')) ?><?= $uom ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($linked, 3), '0'), '.')) ?><?= $uom ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($open, 3), '0'), '.')) ?><?= $uom ?></td>
                    <td><?= $statusPill ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="empty muted">No items with received quantities yet.</td></tr>
            <?php else: ?>
                <tr style="border-top:2px solid var(--border); font-weight:600;">
                    <td colspan="2">Totals</td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($totReceived, 3), '0'), '.')) ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($totLinked, 3), '0'), '.')) ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($totOpen, 3), '0'), '.')) ?></td>
                    <td class="muted small">across <?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

    <?php else:
        // ----- BY RECEIPT EVENT -------------------------------------------
        // One row per inv_receipts row. Each receipt's qty_received vs
        // SUM(invoice_lines.qty WHERE inv_receipt_id = receipt.id).
        $rows = db_all(
            "SELECT
                r.id AS receipt_id, r.receipt_no, r.receipt_date,
                r.qty_received,
                i.code AS item_code, i.name AS item_name, i.short_description,
                u.symbol AS uom_symbol,
                sh.ship_no, v.name AS vendor_name,
                COALESCE(l_tot.linked, 0) AS linked_qty
              FROM inv_receipts r
              JOIN inv_shipment_lines sl ON sl.id = r.shipment_line_id
              JOIN inv_items i           ON i.id  = sl.item_id
              LEFT JOIN inv_uom u        ON u.id  = i.uom_id
              JOIN inv_shipments sh      ON sh.id = r.shipment_id
              LEFT JOIN vendors v        ON v.id  = sh.vendor_id
              LEFT JOIN (
                  SELECT il.inv_receipt_id, SUM(il.qty) AS linked
                    FROM invoice_lines il
                    JOIN invoice_items ii ON ii.id = il.invoice_item_id
                    JOIN invoices inv     ON inv.id = ii.invoice_id
                   WHERE il.link_kind = 'inv'
                     AND inv.status IN ('pending', 'approved')
                   GROUP BY il.inv_receipt_id
              ) l_tot ON l_tot.inv_receipt_id = r.id
             ORDER BY r.receipt_date DESC, r.id DESC"
        );
        ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Vendor</th>
                    <th>Shipment</th>
                    <th class="r">Received</th>
                    <th class="r">Linked</th>
                    <th class="r">Open</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($rows as $r):
                $received = (float)$r['qty_received'];
                $linked   = (float)$r['linked_qty'];
                $open     = max(0, $received - $linked);
                if ($open <= 0.001) {
                    $statusPill = '<span class="pill pill-success">fully linked</span>';
                } elseif ($linked <= 0.001) {
                    $statusPill = '<span class="pill pill-warning">none linked</span>';
                } else {
                    $statusPill = '<span class="pill pill-info">partial</span>';
                }
                $uom = $r['uom_symbol'] ? ' ' . h($r['uom_symbol']) : '';
                ?>
                <tr>
                    <td><code><?= h($r['receipt_no']) ?></code></td>
                    <td><?= h($r['receipt_date']) ?></td>
                    <td>
                        <code><?= h($r['item_code']) ?></code><br>
                        <span class="muted small"><?= h($r['short_description'] ?: $r['item_name']) ?></span>
                    </td>
                    <td><?= h($r['vendor_name'] ?? '—') ?></td>
                    <td><code class="muted small"><?= h($r['ship_no']) ?></code></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($received, 3), '0'), '.')) ?><?= $uom ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($linked, 3), '0'), '.')) ?><?= $uom ?></td>
                    <td class="r"><?= h(rtrim(rtrim(number_format($open, 3), '0'), '.')) ?><?= $uom ?></td>
                    <td><?= $statusPill ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="empty muted">No receipt events recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'coverage_txns') {
    $filter = (string)input('filter', 'all');
    if (!in_array($filter, ['all', 'linked', 'open', 'n_a'], true)) $filter = 'all';

    // Each inv_txns row may or may not have a receipt — only ship_in
    // txns get linked through inv_receipts. We compute the linked
    // qty per receipt and join it back. For non-ship_in txns, the
    // join yields NULL and we classify them as N/A.
    $rows = db_all(
        "SELECT
            t.id AS txn_id, t.txn_type, t.txn_date,
            t.qty_delta, t.qty_after,
            i.code AS item_code, i.name AS item_name, i.short_description,
            u.symbol AS uom_symbol,
            l.code AS loc_code, l.name AS loc_name,
            r.id AS receipt_id, r.receipt_no, r.qty_received,
            COALESCE(l_tot.linked, 0) AS linked_qty
          FROM inv_txns t
          JOIN inv_items i  ON i.id = t.item_id
          LEFT JOIN inv_uom u   ON u.id = i.uom_id
          LEFT JOIN locations l ON l.id = t.location_id
          LEFT JOIN inv_receipts r ON r.txn_id = t.id
          LEFT JOIN (
              SELECT il.inv_receipt_id, SUM(il.qty) AS linked
                FROM invoice_lines il
                JOIN invoice_items ii ON ii.id = il.invoice_item_id
                JOIN invoices inv     ON inv.id = ii.invoice_id
               WHERE il.link_kind = 'inv'
                 AND inv.status IN ('pending', 'approved')
               GROUP BY il.inv_receipt_id
          ) l_tot ON l_tot.inv_receipt_id = r.id
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT 1000"
    );

    // Classify each row server-side so the filter just hides rows
    // by status. Three buckets:
    //   'linked'  — receipt exists AND linked_qty >= qty_received
    //   'open'    — receipt exists AND linked_qty < qty_received
    //   'n_a'     — no receipt (txn isn't invoice-eligible)
    foreach ($rows as &$r) {
        if (!$r['receipt_id']) {
            $r['_bucket'] = 'n_a';
        } else {
            $received = (float)$r['qty_received'];
            $linked   = (float)$r['linked_qty'];
            $r['_bucket'] = ($linked + 0.001 >= $received) ? 'linked' : 'open';
        }
    }
    unset($r);

    // Tally for the filter-pill counts
    $counts = ['all' => count($rows), 'linked' => 0, 'open' => 0, 'n_a' => 0];
    foreach ($rows as $r) $counts[$r['_bucket']]++;

    $page_title  = 'Transaction coverage — invoices';
    $page_module = 'invoice_coverage_txns';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/invoice.php'),
        'back_label' => 'Back to invoices',
        'title'      => 'Transaction coverage',
    ]) ?>

    <div class="card" style="padding: 12px; margin-bottom: 12px;">
        <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
            <span class="muted small" style="margin-right: 6px;">Filter:</span>
            <?php foreach ([
                'all'    => ['All',            $counts['all']],
                'linked' => ['Linked',         $counts['linked']],
                'open'   => ['Open',           $counts['open']],
                'n_a'    => ['Not applicable', $counts['n_a']],
            ] as $k => $cfg):
                list($lbl, $n) = $cfg;
            ?>
                <a class="btn btn-sm <?= $filter === $k ? 'btn-primary' : 'btn-ghost' ?>"
                   href="<?= h(url('/invoice.php?action=coverage_txns&filter=' . $k)) ?>"><?= h($lbl) ?>
                   <span class="muted small">(<?= (int)$n ?>)</span></a>
            <?php endforeach; ?>
            <span class="muted small" style="margin-left:auto;">
                Showing last 1000 ledger entries
            </span>
        </div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Txn #</th>
                <th>Date</th>
                <th>Type</th>
                <th>Item</th>
                <th>Location</th>
                <th class="r">Delta</th>
                <th class="r">Receipt qty</th>
                <th class="r">Linked qty</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php
        foreach ($rows as $r):
            if ($filter !== 'all' && $r['_bucket'] !== $filter) continue;
            $uom = $r['uom_symbol'] ? ' ' . h($r['uom_symbol']) : '';
            $delta = (float)$r['qty_delta'];
            switch ($r['_bucket']) {
                case 'linked':
                    $statusPill = '<span class="pill pill-success">linked</span>';
                    break;
                case 'open':
                    $statusPill = '<span class="pill pill-warning">open</span>';
                    break;
                default:
                    $statusPill = '<span class="pill pill-neutral">N/A</span>';
            }
            ?>
            <tr>
                <td><code>#<?= (int)$r['txn_id'] ?></code></td>
                <td><?= h($r['txn_date']) ?></td>
                <td><code class="muted small"><?= h($r['txn_type']) ?></code></td>
                <td>
                    <code><?= h($r['item_code']) ?></code><br>
                    <span class="muted small"><?= h($r['short_description'] ?: $r['item_name']) ?></span>
                </td>
                <td>
                    <?php if ($r['loc_code']): ?>
                        <code><?= h($r['loc_code']) ?></code>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="r"><?= h(rtrim(rtrim(number_format($delta, 3), '0'), '.')) ?><?= $uom ?></td>
                <td class="r">
                    <?php if ($r['receipt_id']): ?>
                        <?= h(rtrim(rtrim(number_format((float)$r['qty_received'], 3), '0'), '.')) ?><?= $uom ?>
                        <br><code class="muted small"><?= h($r['receipt_no']) ?></code>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="r">
                    <?php if ($r['receipt_id']): ?>
                        <?= h(rtrim(rtrim(number_format((float)$r['linked_qty'], 3), '0'), '.')) ?><?= $uom ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $statusPill ?></td>
            </tr>
        <?php endforeach; ?>
        <?php $visibleCount = ($filter === 'all') ? count($rows) : $counts[$filter] ?? 0; ?>
        <?php if ($visibleCount === 0): ?>
            <tr><td colspan="9" class="empty muted">No transactions match this filter.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ----------------------------------------------------------------
// LIST (default) — one row per invoice_item
// ----------------------------------------------------------------
// Per the per-line linking model, each invoice_item is its own row
// in the browse table. Header info (invoice no, date, vendor) is
// repeated across each item-row of the same invoice; the dedicated
// 'invoice_no' column links to the invoice view, while the
// item-specific 'item_code' / 'description' / qty / linked / unlinked
// columns describe THIS line.
//
// linked_qty and unlinked_qty come from a SUM over invoice_lines via
// correlated subquery on invoice_item_id.
//
// Invoices with zero line items still show one row (LEFT JOIN with
// COALESCE), labelled "(no line items)" so the operator notices.
$dtCfg = [
    'id'       => 'invoices',
    'base_sql' => "SELECT inv.id            AS invoice_id,
                          inv.invoice_no,
                          inv.invoice_date,
                          inv.vendor_id,
                          inv.currency,
                          inv.status,
                          v.code            AS vendor_code,
                          v.name            AS vendor_name,
                          COALESCE(ii.id, 0)            AS item_id,
                          ii.sort_order,
                          ii.item_kind,
                          ii.item_code,
                          ii.description    AS item_desc,
                          ii.qty            AS item_qty,
                          ii.uom            AS item_uom,
                          ii.unit_price,
                          (ii.qty * ii.unit_price) AS line_total,
                          COALESCE(
                              (SELECT SUM(il.qty) FROM invoice_lines il WHERE il.invoice_item_id = ii.id),
                              0
                          ) AS qty_linked,
                          GREATEST(
                              COALESCE(ii.qty, 0) -
                              COALESCE(
                                  (SELECT SUM(il.qty) FROM invoice_lines il WHERE il.invoice_item_id = ii.id),
                                  0
                              ),
                              0
                          ) AS qty_unlinked
                     FROM invoices inv
                LEFT JOIN vendors v          ON v.id = inv.vendor_id
                LEFT JOIN invoice_items ii   ON ii.invoice_id = inv.id",
    'columns'  => [
        ['key'=>'invoice_no',   'label'=>'Invoice #',  'sortable'=>true, 'searchable'=>true, 'sql_col'=>'inv.invoice_no'],
        ['key'=>'invoice_date', 'label'=>'Date',       'sortable'=>true, 'sql_col'=>'inv.invoice_date'],
        ['key'=>'vendor',       'label'=>'Vendor',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'v.name'],
        ['key'=>'item_kind',    'label'=>'Kind',       'sortable'=>true, 'searchable'=>true, 'sql_col'=>'ii.item_kind',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'asset',    'label'=>'Asset'],
             ['value'=>'inv_item', 'label'=>'Inv item'],
         ]]],
        ['key'=>'item_label',   'label'=>'Item',       'sortable'=>true, 'searchable'=>true,
         // Searchable on both code and description, displayed as
         // (CODE)-Description per the app-wide convention.
         'sql_col'=>"CONCAT('(', COALESCE(ii.item_code, ''), ')-', COALESCE(ii.description, ''))"],
        ['key'=>'item_qty',     'label'=>'Qty',        'sortable'=>true, 'searchable'=>false, 'sql_col'=>'ii.qty',         'th_class'=>'r','td_class'=>'r'],
        ['key'=>'qty_linked',   'label'=>'Linked',     'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'qty_unlinked', 'label'=>'Unlinked',   'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'line_total',   'label'=>'Line total', 'sortable'=>true, 'searchable'=>false, 'sql_col'=>'(ii.qty * ii.unit_price)', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'status',       'label'=>'Status',     'sortable'=>true, 'sql_col'=>'inv.status',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'pending', 'label'=>'Pending'],
             ['value'=>'approved','label'=>'Approved'],
             ['value'=>'rejected','label'=>'Rejected'],
         ]]],
        ['key'=>'_actions',     'label'=>'Actions',    'sortable'=>false, 'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['invoice_date', 'desc'],
];

$rowRenderer = function ($r) use ($canManage) {
    $vendor = $r['vendor_name']
        ? '<code>' . h($r['vendor_code']) . '</code> ' . h($r['vendor_name'])
        : '—';
    $statusCls = $r['status'] === 'approved' ? 'active' : ($r['status'] === 'rejected' ? 'danger' : 'neutral');
    $status = '<span class="pill pill-' . $statusCls . '">' . h($r['status']) . '</span>';

    // The fmt helper trims trailing zeros for qty displays.
    $fmt = function ($v) {
        return rtrim(rtrim(number_format((float)$v, 3), '0'), '.');
    };

    // If the invoice has no line items, item_id will be 0 (from the
    // COALESCE). Show a single muted row for the header and skip
    // the link-related cells.
    if ((int)$r['item_id'] === 0) {
        $actions = '<a class="btn btn-icon" title="View invoice" aria-label="View invoice" href="'
                 . h(url('/invoice.php?action=view&id=' . (int)$r['invoice_id']))
                 . '">👁 <span class="dt-action-label">View</span></a>';
        if ($canManage) {
            $actions .= ' <a class="btn btn-icon" title="Edit invoice" aria-label="Edit invoice" href="'
                      . h(url('/invoice.php?action=edit&id=' . (int)$r['invoice_id']))
                      . '">✎ <span class="dt-action-label">Edit</span></a>';
        }
        return [
            'invoice_no'   => '<strong><a href="' . h(url('/invoice.php?action=view&id=' . (int)$r['invoice_id'])) . '">'
                              . h($r['invoice_no']) . '</a></strong>',
            'invoice_date' => h($r['invoice_date']),
            'vendor'       => $vendor,
            'item_kind'    => '<span class="muted small">no items</span>',
            'item_label'   => '<span class="muted small">(no line items)</span>',
            'item_qty'     => '—',
            'qty_linked'   => '—',
            'qty_unlinked' => '—',
            'line_total'   => '—',
            'status'       => $status,
            '_actions'     => dt_actions_wrap($actions),
        ];
    }

    $kindPill = $r['item_kind'] === 'asset'
        ? '<span class="pill pill-info">asset</span>'
        : '<span class="pill pill-neutral">inv item</span>';

    $itemLabel = '(' . h($r['item_code']) . ')-' . h($r['item_desc'] ?: '—');

    $qtyLinked   = (float)$r['qty_linked'];
    $qtyUnlinked = (float)$r['qty_unlinked'];
    // Highlight unlinked qty when > 0 — that's the "balance unlinked"
    // the user wanted to see surfaced separately.
    $unlinkedCell = $qtyUnlinked > 0
        ? '<strong style="color:#b45309">' . h($fmt($qtyUnlinked)) . '</strong>'
        : h($fmt(0));
    $linkedCell = $qtyLinked > 0
        ? '<strong style="color:#059669">' . h($fmt($qtyLinked)) . '</strong>'
        : h($fmt(0));

    $lineTotal = (float)$r['line_total'] > 0
        ? h($r['currency']) . ' ' . h(number_format((float)$r['line_total'], 2))
        : '—';

    $actions = '<a class="btn btn-icon" title="View invoice" aria-label="View invoice" href="'
             . h(url('/invoice.php?action=view&id=' . (int)$r['invoice_id']))
             . '">👁 <span class="dt-action-label">View</span></a>';
    if ($canManage) {
        $actions .= ' <a class="btn btn-icon" title="Link txns to this line" aria-label="Link txns" href="'
                  . h(url('/invoice.php?action=links&id=' . (int)$r['invoice_id']))
                  . '#item-' . (int)$r['item_id']
                  . '">🔗 <span class="dt-action-label">Link</span></a>';
        if ($r['status'] === 'pending') {
            $actions .= ' <a class="btn btn-icon btn-primary" title="Approve invoice" aria-label="Approve invoice"'
                      . ' href="' . h(url('/invoice.php?action=approve_form&id=' . (int)$r['invoice_id']))
                      . '">✓ <span class="dt-action-label">Approve</span></a>';
        }
        $actions .= ' <a class="btn btn-icon" title="Edit invoice" aria-label="Edit invoice" href="'
                  . h(url('/invoice.php?action=edit&id=' . (int)$r['invoice_id']))
                  . '">✎ <span class="dt-action-label">Edit</span></a>';
    }

    return [
        'invoice_no'   => '<strong><a href="' . h(url('/invoice.php?action=view&id=' . (int)$r['invoice_id'])) . '">'
                          . h($r['invoice_no']) . '</a></strong>',
        'invoice_date' => h($r['invoice_date']),
        'vendor'       => $vendor,
        'item_kind'    => $kindPill,
        'item_label'   => $itemLabel,
        'item_qty'     => h($fmt($r['item_qty'])) . ' <span class="muted small">' . h($r['item_uom']) . '</span>',
        'qty_linked'   => $linkedCell,
        'qty_unlinked' => $unlinkedCell,
        'line_total'   => $lineTotal,
        'status'       => $status,
        '_actions'     => dt_actions_wrap($actions),
    ];
};
$dt = data_table_run($dtCfg, $rowRenderer);

$newBtnHtml = $canManage
    ? '<a class="btn btn-primary" href="' . h(url('/invoice.php?action=new')) . '"'
      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New invoice', 'N') . '</a>'
    : '';
$dtCfg['title']        = 'Invoices';
$dtCfg['description']  = 'One row per invoice line item. Each line tracks how much of its qty is linked to receipts / asset transactions. The Link column opens the per-line linker.';
$dtCfg['actions_html'] = $newBtnHtml;

$page_title  = 'Invoices';
$page_module = 'invoice';
require __DIR__ . '/includes/header.php';
data_table_render($dtCfg, $dt, $rowRenderer);
require __DIR__ . '/includes/footer.php';
