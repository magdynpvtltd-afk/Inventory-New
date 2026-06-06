<?php
/**
 * MagDyn — PO print HTML renderer
 *
 * Single source of HTML for both:
 *   - Browser print at purchase_orders.php?action=print
 *   - PDF generation via includes/_po_pdf.php (dompdf)
 *
 * Layout matches company PO format (MD-DOC-0026-DF VER-1 REV-0):
 *   Page 1 — Main PO (header, vendor block, lines table, footer)
 *   Page 2 — Input Material Issued
 *   Page 3 — Terms & Conditions
 *
 * DOMPDF-safe rules applied here:
 *   - No flexbox/grid, no border-radius, no box-shadow, no CSS variables
 *   - No `* { box-sizing }` (universal selector ignored by dompdf)
 *   - No `padding` on <table> elements (dompdf ignores it; padding goes on <td>)
 *   - Lines table uses table-layout:fixed + <colgroup> so rowspan+colspan
 *     column widths are computed from the colgroup, not the th attributes
 *   - All styles inline or in <style>/<head>; no external CSS files
 */

require_once __DIR__ . '/_purchase_orders.php';

/**
 * Build the full PO print HTML as a string.
 *
 * $opts:
 *   'include_actions_bar' => bool  (default true; PDF callers set false)
 */
function po_render_print_html($poId, array $opts = [])
{
    $opts += ['include_actions_bar' => true];
    $full = po_load_full((int)$poId);
    if (!$full) return null;

    $po       = $full['po'];
    $shipment = $full['shipment'];
    $vendor   = $full['vendor'];
    $contact  = $full['primary_contact'];
    $address  = $full['primary_address'];   // vendor address
    $lines    = $full['lines'];
    // Price and GST come directly from inv_shipment_lines (unit_price, gst_rate).
    $grandTotal = 0.0;

    // ── Company details (from settings, with sensible defaults) ────────
    $co = [
        'name'             => magdyn_setting('company.name',             'Magneto Dynamics (P) Ltd.'),
        'address_line1'    => magdyn_setting('company.address_line1',    'Plot No 7/8/9, Venkateswara Nagar,'),
        'address_line2'    => magdyn_setting('company.address_line2',    'Perungudi, Chennai 600096, I N D I A'),
        'phone'            => magdyn_setting('company.phone',            '+91-44-24960663'),
        'email'            => magdyn_setting('company.email',            'rsk@magdyn.com'),
        'gst_no'           => magdyn_setting('company.gst_no',           '33AAACM4623Q1ZB'),
        'pan_no'           => magdyn_setting('company.pan_no',           'AAACM4623Q'),
        'iec_no'           => magdyn_setting('company.iec_no',           '0403034019'),
        'tan_no'           => magdyn_setting('company.tan_no',           'CHEM01647C'),
        'msme_no'          => magdyn_setting('company.msme_no',          '33-003-11-02194'),
        'delivery_addr1'   => magdyn_setting('company.delivery_addr1',   'Plot No.7/9, Venkateswara Nagar Main Road,'),
        'delivery_addr2'   => magdyn_setting('company.delivery_addr2',   'Perungudi, Chennai – 600096.'),
        'billing_addr1'    => magdyn_setting('company.billing_addr1',    'Plot No.7/8/9, Venkateswara Nagar Main Road,'),
        'billing_addr2'    => magdyn_setting('company.billing_addr2',    'Perungudi, Chennai – 600096.'),
        'despatch_email'   => magdyn_setting('po.despatch_email',        'vidhya@magdyn.com , Accounts@magdyn.com'),
        'accounts_email'   => magdyn_setting('po.accounts_email',        'Accounts@magdyn.com'),
    ];

    // ── Creator / buyer name ────────────────────────────────────────────
    $creatorRow = db_one('SELECT full_name, username FROM users WHERE id = ?', [(int)($po['created_by'] ?? 0)]);
    $buyerDisplay = $creatorRow
        ? ($creatorRow['full_name'] ?: $creatorRow['username'])
        : magdyn_setting('po.default_buyer', '');

    // ── Date formatting ────────────────────────────────────────────────
    $poDateFmt = '';
    if (!empty($po['po_date'])) {
        $ts = strtotime((string)$po['po_date']);
        $poDateFmt = $ts ? date('d-M-Y', $ts) : h($po['po_date']);
    }

    // ── Logo (embedded as base64 so dompdf doesn't need remote URLs) ───
    $logoPath    = __DIR__ . '/../assets/img/logo.png';
    $logoHtml    = '';
    if (file_exists($logoPath)) {
        $logoDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        $logoHtml    = '<img src="' . $logoDataUri . '" style="height:44px; width:auto; display:block;">';
    }

    // ── Terms text (shipment override → system setting → default) ──────
    $terms = (string)($shipment['terms_conditions'] ?? '') !== ''
           ? $shipment['terms_conditions']
           : magdyn_setting('shiprcpt.terms_conditions', '');

    // ── Miscellaneous shipment fields ───────────────────────────────────
    $paymentTerms   = (string)($shipment['payment_terms']        ?? '');
    $packFwd        = (string)($shipment['packing_forwarding']   ?? '');
    $freightIns     = (string)($shipment['freight_insurance']    ?? '');
    $specialInst    = (string)($shipment['special_instructions'] ?? '');
    $internalNotes  = (string)($shipment['notes']                ?? '');
    $poRef          = (string)($shipment['reference']            ?? '');

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PO <?= h($po['po_no']) ?></title>
<style>
    /* No universal box-sizing — dompdf ignores the * selector and it can
       cause width miscalculations on table cells. */
    body  { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5px; color: #111; margin: 0; padding: 0; }
    .po-wrap { padding: 6mm 8mm; }
    table { border-collapse: collapse; }
    .w100 { width: 100%; }
    .b { font-weight: bold; }
    .c { text-align: center; }
    .r { text-align: right; }
    .small { font-size: 8.5px; }
    .muted { color: #555; }
    .lbl  { font-size: 8px; text-transform: uppercase; letter-spacing: 0.05em; color: #555; display: block; margin-bottom: 2px; }
    .page-break { page-break-after: always; }

    /* ── Header ── */
    .hdr-table td { vertical-align: top; padding: 0; }

    /* ── PO title block ── */
    .po-title { font-size: 17px; font-weight: bold; letter-spacing: 0.04em; }

    /* ── Lines table — table-layout:fixed + colgroup drive column widths;
          rowspan+colspan in the header are only structural. ── */
    .lines-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 8.5px; table-layout: fixed; }
    .lines-tbl th { background: #f0f0f0; border: 1px solid #999; padding: 3px 4px; text-align: center; vertical-align: middle; font-size: 8px; font-weight: bold; }
    .lines-tbl td { border: 1px solid #999; padding: 3px 4px; vertical-align: top; }
    .lines-tbl .num { text-align: right; }
    .lines-tbl .ctr { text-align: center; }
    .lines-tbl tfoot td { border: 1px solid #999; padding: 3px 6px; font-weight: bold; }

    /* ── Footer blocks ── */
    .foot-table td { border: 1px solid #bbb; padding: 4px 6px; vertical-align: top; font-size: 9px; }
    .foot-table .lbl { font-size: 8px; font-weight: bold; }

    /* ── Actions bar (browser only) ── */
    .actions { margin-bottom: 16px; }
    .actions a, .actions button { display: inline-block; padding: 5px 14px; border: 1px solid #999; background: #fff;
        font-size: 12px; text-decoration: none; color: inherit; margin-right: 6px; cursor: pointer; }
    .actions .primary { background: #2d3a8c; color: #fff; border-color: #2d3a8c; }
    @media print { .actions { display: none; } .po-wrap { padding: 0 8mm; } }

    /* ── T&C ── */
    .tc-list { padding-left: 14px; margin: 0; }
    .tc-list li { margin-bottom: 6px; line-height: 1.5; font-size: 9px; }

    /* ── Input Material table ── */
    .imt-tbl { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9px; }
    .imt-tbl th { background: #f0f0f0; border: 1px solid #999; padding: 4px 6px; text-align: center; font-weight: bold; }
    .imt-tbl td { border: 1px solid #999; padding: 4px 6px; }
</style>
</head>
<body>
<div class="po-wrap">

<?php if ($opts['include_actions_bar']): ?>
<div class="actions">
    <button onclick="window.print()" class="primary">🖨 Print</button>
    <a href="<?= h(url('/purchase_orders.php?action=download_pdf&id=' . (int)$po['id'])) ?>" target="_blank">⬇ Download PDF</a>
    <a href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$po['id'])) ?>">← Back to PO</a>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     PAGE 1 — MAIN PO
     ═══════════════════════════════════════════════════════════ -->

<!-- Doc ref top-right -->
<table class="w100" style="margin-bottom:2px;"><tr>
    <td></td>
    <td style="text-align:right; font-size:8px; color:#555;">MD-DOC-0026-DF VER-1 REV-0</td>
</tr></table>

<!-- Company header: logo+address LEFT | phone/email CENTRE | tax IDs RIGHT
     NOTE: padding on <table> is ignored by dompdf — padding lives on <td>. -->
<table class="w100 hdr-table" style="border:1px solid #bbb; margin-bottom:4px;">
    <tr>
        <td style="width:8%; padding:4px 0 4px 6px; vertical-align:middle;"><?= $logoHtml ?></td>
        <td style="width:45%; padding:4px 8px 4px 8px; vertical-align:top; line-height:1.5;">
            <strong style="font-size:11px;"><?= h($co['name']) ?></strong><br>
            <span class="muted small"><?= h($co['address_line1']) ?></span><br>
            <span class="muted small"><?= h($co['address_line2']) ?></span>
        </td>
        <td style="width:20%; padding:4px 4px; vertical-align:top; font-size:8.5px; line-height:1.6;">
            <span class="muted">Phone :</span> <?= h($co['phone']) ?><br>
            <span class="muted">Email:</span> <?= h($co['email']) ?>
        </td>
        <td style="width:27%; padding:4px 6px 4px 4px; vertical-align:top; font-size:8.5px; line-height:1.6; text-align:right;">
            <strong>GST NO:</strong> <?= h($co['gst_no']) ?> &nbsp;<strong>PAN NO:</strong> <?= h($co['pan_no']) ?><br>
            <strong>IEC NO:</strong> <?= h($co['iec_no']) ?> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<strong>TAN NO:</strong> <?= h($co['tan_no']) ?><br>
            <strong>MSME NO:</strong> <?= h($co['msme_no']) ?>
        </td>
    </tr>
</table>

<!-- PURCHASE ORDER title + PO No + Date
     Using cell borders (not table border-bottom:0) for dompdf reliability. -->
<table class="w100" style="border-left:1px solid #bbb; border-right:1px solid #bbb; border-top:1px solid #bbb; margin-bottom:0;">
    <tr>
        <td style="width:40%; padding:4px 8px;">
            <span class="po-title">PURCHASE ORDER</span>
        </td>
        <td style="width:20%; padding:4px 6px; text-align:center; border-left:1px solid #bbb; border-bottom:1px solid #bbb;">
            <span class="lbl">P.O Number</span>
            <strong><?= h($po['po_no']) ?></strong>
        </td>
        <td style="width:20%; padding:4px 6px; text-align:center; border-left:1px solid #bbb; border-bottom:1px solid #bbb;">
            <span class="lbl">Date</span>
            <strong><?= h($poDateFmt) ?></strong>
        </td>
        <td style="width:20%; padding:4px 6px; border-left:1px solid #bbb; border-bottom:1px solid #bbb;"></td>
    </tr>
</table>

<!-- Three-column: To Vendor | Delivery Address | Billing Address
     Cell borders handle internal separation; outer border from the table. -->
<table class="w100" style="border:1px solid #bbb; border-top:1px solid #999; margin-bottom:0;">
    <tr>
        <td style="width:34%; padding:5px 7px; vertical-align:top; border-right:1px solid #bbb;">
            <span class="lbl">To Vendor</span>
            <strong><?= h($vendor['name'] ?? '—') ?></strong>
            <?php if (!empty($vendor['code'])): ?>
                &nbsp;&nbsp;<span class="small muted">Vendor Code : <?= h($vendor['code']) ?></span>
            <?php endif; ?>
            <br>
            <?php if ($address): ?>
                <?= h($address['line1'] ?? '') ?><br>
                <?php if (!empty($address['line2'])): ?><?= h($address['line2']) ?><br><?php endif; ?>
                <?= h(trim(($address['city'] ?? '') . ' ' . ($address['state'] ?? '') . ' ' . ($address['pincode'] ?? ''))) ?><br>
            <?php endif; ?>
            <?php if ($contact): ?>
                Ph: <?= h($contact['phone'] ?? '') ?><br>
                Email: <?= h($contact['email'] ?? '') ?>
            <?php endif; ?>
            <?php if (!empty($vendor['gst_no'])): ?>
                <br>GST NO: <?= h($vendor['gst_no']) ?>
            <?php endif; ?>
        </td>
        <td style="width:33%; padding:5px 7px; vertical-align:top; border-right:1px solid #bbb;">
            <span class="lbl">Delivery Address</span>
            <strong><?= h($co['name']) ?></strong><br>
            <?= h($co['delivery_addr1']) ?><br>
            <?= h($co['delivery_addr2']) ?><br>
            GST NO: <?= h($co['gst_no']) ?>
        </td>
        <td style="width:33%; padding:5px 7px; vertical-align:top;">
            <span class="lbl">Billing Address</span>
            <strong><?= h($co['name']) ?></strong><br>
            <?= h($co['billing_addr1']) ?><br>
            <?= h($co['billing_addr2']) ?><br>
            GST NO: <?= h($co['gst_no']) ?>
        </td>
    </tr>
</table>

<!-- Ref row -->
<table class="w100" style="border:1px solid #bbb; border-top:0; margin-bottom:4px;">
    <tr>
        <td style="padding:3px 7px; font-size:9px;">
            <strong>Ref :</strong> <?= h($poRef) ?>
        </td>
    </tr>
</table>

<!-- Instructions -->
<div style="font-size:8.5px; margin-bottom:4px; line-height:1.5;">
    Please Supply the following material(s) as per the terms &amp; conditions mentioned here under and overleaf.
    Quote our PO No. &amp; Date in all your supply documents and correspondence.
</div>

<!-- Lines table
     DOMPDF FIX: table-layout:fixed + <colgroup> drives all column widths.
     The rowspan/colspan in <thead> are structural only — no width attrs on <th>.
     Column total: 3+26+5+5+8+7+10+7+7+6+5+5+6 = 100% -->
<table class="lines-tbl">
    <colgroup>
        <col style="width:3%;">
        <col style="width:26%;">
        <col style="width:5%;">
        <col style="width:5%;">
        <col style="width:8%;">
        <col style="width:7%;">
        <col style="width:10%;">
        <col style="width:7%;">
        <col style="width:7%;">
        <col style="width:6%;">
        <col style="width:5%;">
        <col style="width:5%;">
        <col style="width:6%;">
    </colgroup>
    <thead>
        <tr>
            <th rowspan="2">Sl.</th>
            <th rowspan="2">Part No. and Description of Material</th>
            <th rowspan="2">Qty.</th>
            <th rowspan="2">Unit</th>
            <th colspan="2">Rate per</th>
            <th rowspan="2">Total Value in Rs.P</th>
            <th rowspan="2">Incoming Inspection</th>
            <th colspan="2">Delivery Schedule</th>
            <th colspan="3">Receipt details for magdyn use</th>
        </tr>
        <tr>
            <th>Qty</th>
            <th>GST %</th>
            <th>Date</th>
            <th>Quantity</th>
            <th>Date</th>
            <th>Quantity</th>
            <th>CRIN No.</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lines): ?>
            <tr><td colspan="13" class="ctr small" style="padding:6px;">No lines.</td></tr>
        <?php else:
            foreach ($lines as $idx => $l):
                $isAsset   = $l['entity_type'] === 'asset';
                $isPending = !$isAsset && empty($l['item_id']) && !empty($l['pending_name']);
                $code = $isAsset
                      ? ($l['asset_tag'] ?: '—')
                      : ($l['item_code'] ?: ($isPending ? '(new)' : '—'));
                $desc = $isAsset
                      ? ($l['asset_model'] ?: '')
                      : ($l['item_name'] ?: ($l['pending_name'] ?? ''));
                $price = ($l['unit_price'] !== null && $l['unit_price'] !== '') ? (float)$l['unit_price'] : null;
                $gst   = ($l['gst_rate']   !== null && $l['gst_rate']   !== '') ? (float)$l['gst_rate']   : null;
                $qty   = (float)($l['qty_planned'] ?? 0);
                $qtyDisp = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
                if ($qtyDisp === '' || $qtyDisp === '.') $qtyDisp = '0';
                $totalLine = ($price !== null) ? $price * $qty : null;
                if ($totalLine !== null) $grandTotal += $totalLine;
                $delivDate = !empty($l['delivery_date']) ? h($l['delivery_date']) : '';
        ?>
            <tr>
                <td class="ctr"><?= $idx + 1 ?></td>
                <td><?= h($code) ?><?php if ($code !== '—' && $code !== ''): ?><br><span class="muted small"><?= h($desc) ?></span><?php endif; ?></td>
                <td class="ctr"><?= h($qtyDisp) ?></td>
                <td class="ctr"><?= h($l['uom_label'] ?? '—') ?></td>
                <td class="num"><?= $price !== null ? h(number_format($price, 2)) : '' ?></td>
                <td class="num"><?= $gst !== null ? h(rtrim(rtrim((string)$gst, '0'), '.')) : '' ?></td>
                <td class="num"><?= $totalLine !== null ? h(number_format($totalLine, 2)) : '' ?></td>
                <td class="ctr"></td><!-- Incoming Inspection -->
                <td class="ctr"><?= $delivDate ?></td>
                <td class="num"><?= h($qtyDisp) ?></td>
                <td class="ctr"></td><!-- Receipt Date -->
                <td class="ctr"></td><!-- Receipt Quantity -->
                <td class="ctr"></td><!-- CRIN No. -->
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" class="r" style="font-size:9px; font-weight:bold;">TOTAL Amount</td>
            <td class="num"><?= $grandTotal > 0 ? h(number_format($grandTotal, 2)) : '0' ?></td>
            <td colspan="6"></td>
        </tr>
    </tfoot>
</table>

<!-- Payment / Packing / Freight / Notes — four equal columns
     Using explicit border on each cell instead of border-left:0 shorthand
     (dompdf border-collapse + asymmetric borders can misrender). -->
<table class="w100 foot-table" style="margin-top:4px; border-collapse:collapse;">
    <tr>
        <td style="width:25%; vertical-align:top; border:1px solid #bbb; padding:4px 6px;">
            <span class="lbl">Payment terms</span>
            <?= h($paymentTerms) ?>
        </td>
        <td style="width:25%; vertical-align:top; border-top:1px solid #bbb; border-right:1px solid #bbb; border-bottom:1px solid #bbb; padding:4px 6px;">
            <span class="lbl">Packing &amp; Forwarding</span>
            <?= h($packFwd) ?>
        </td>
        <td style="width:25%; vertical-align:top; border-top:1px solid #bbb; border-right:1px solid #bbb; border-bottom:1px solid #bbb; padding:4px 6px;">
            <span class="lbl">Freight &amp; Insurance</span>
            <?= h($freightIns) ?>
        </td>
        <td style="width:25%; vertical-align:top; border-top:1px solid #bbb; border-right:1px solid #bbb; border-bottom:1px solid #bbb; padding:4px 6px;">
            <span class="lbl">NOTES</span>
        </td>
    </tr>
</table>

<!-- Special Instructions -->
<table class="w100" style="margin-top:2px; border-collapse:collapse;">
    <tr>
        <td style="border:1px solid #bbb; padding:3px 6px; font-size:9px;">
            <strong>Special Instructions</strong><br>
            <?= h($specialInst) ?>
        </td>
    </tr>
    <tr>
        <td style="border-left:1px solid #bbb; border-right:1px solid #bbb; border-bottom:1px solid #bbb; padding:3px 6px; font-size:9px;">
            <strong>Enclosures to this PO.</strong>
        </td>
    </tr>
</table>

<!-- Footer: despatch / signatory -->
<table class="w100" style="margin-top:6px; border-collapse:collapse; font-size:9px;">
    <tr>
        <td style="width:60%; vertical-align:top; padding:3px 0;">
            Confirm Despatch by email to
            <strong><?= h($co['despatch_email']) ?></strong>
        </td>
        <td style="width:40%; text-align:right; vertical-align:top; padding:3px 0;">
            <strong>For <?= h($co['name']) ?>,</strong><br><br><br>
            <span style="border-top:1px solid #555; padding-top:3px;">Authorised Signatory</span>
        </td>
    </tr>
    <tr>
        <td style="vertical-align:top; padding:3px 0; font-size:9px;">
            Project Code / Job Code<br><br>
            Budget Code
        </td>
        <td style="vertical-align:top; text-align:right; padding:3px 0; font-size:9px;">
            Buyer &nbsp;&nbsp; <?= h($buyerDisplay) ?>
        </td>
    </tr>
    <tr>
        <td style="vertical-align:top; padding:3px 0; font-size:9px;">
            Notes for Internal Use<br>
            <span class="muted"><?= nl2br(h($internalNotes)) ?></span>
        </td>
        <td style="vertical-align:top; text-align:right; padding:3px 0; font-size:9px;">
            Approved By <?= h(magdyn_setting('po.default_approver', '')) ?>
        </td>
    </tr>
</table>

<div style="text-align:right; font-size:8px; color:#888; margin-top:4px;">Page 1 of 3</div>

<!-- ═══════════════════════════════════════════════════════════
     PAGE 2 — INPUT MATERIAL ISSUED
     ═══════════════════════════════════════════════════════════ -->
<div class="page-break"></div>

<div style="font-size:12px; font-weight:bold; margin-bottom:6px;">Input Material Issued</div>
<table class="imt-tbl">
    <thead>
        <tr>
            <th style="width:5%;">Sl.</th>
            <th>Part No. and Description of Material</th>
            <th style="width:10%;">Qty.</th>
            <th style="width:18%;">Date</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$lines): ?>
            <tr><td colspan="4" class="ctr small" style="padding:8px;">No items.</td></tr>
        <?php else:
            foreach ($lines as $idx => $l):
                $isAsset = $l['entity_type'] === 'asset';
                $code = $isAsset ? ($l['asset_tag'] ?: '—') : ($l['item_code'] ?: '—');
                $desc = $isAsset ? ($l['asset_model'] ?: '') : ($l['item_name'] ?: ($l['pending_name'] ?? ''));
                $qty  = rtrim(rtrim(number_format((float)($l['qty_planned'] ?? 0), 2), '0'), '.');
                $lineDate = !empty($l['delivery_date']) ? h($l['delivery_date']) : '';
        ?>
            <tr>
                <td class="ctr"><?= $idx + 1 ?></td>
                <td><?= h($code) ?><?= $desc ? ' — ' . h($desc) : '' ?></td>
                <td class="ctr"><?= h($qty) ?></td>
                <td class="ctr"><?= $lineDate ?></td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
</table>

<div style="text-align:right; font-size:8px; color:#888; margin-top:8px;">Page 2 of 3</div>

<!-- ═══════════════════════════════════════════════════════════
     PAGE 3 — TERMS & CONDITIONS
     ═══════════════════════════════════════════════════════════ -->
<div class="page-break"></div>

<div style="text-align:center; font-size:12px; font-weight:bold; margin-bottom:10px; text-decoration:underline;">Terms &amp; Conditions</div>

<?php
$standardTerms = [
    'The materials, when despatched should be accompanied by Delivery Challan / Packing slip, Warranty / Test certificate, giving our Purchase Order Number, full description of items despatched, quantity, number of packages, mode of despatch etc.',
    'Bills / Invoices covering the materials despatched must be sent in Duplicate to us along with despatch documents within five days.',
    'Inspection will be carried out by ourselves / customer / third party inspection agency at our premises.',
    'Bills / Invoices should contain in addition to full particulars of the materials, our order number and full particulars of Airway bill / Lorry Receipt No. / courier docket No. under which the materials are despatched.',
    'Materials when received will be inspected by our Inspection Department and the decision of our Inspection Department in regard to the acceptance or rejection of the materials will be final.',
    'If materials are rejected, notice of such rejection will be intimated to suppliers giving reasons for such rejection. On receipt of such intimation, the rejected goods must be removed and replaced immediately by the supplier, if so desired by the suppliers, the rejected materials will be re-booked on "Freight To-Pay" basis. In case of failure to remove rejected materials within a reasonable time, we will reserve the right to dispose of the materials at the supplier\'s risk and no claim whatsoever thereafter will be entertained.',
    'In case of failure to supply the materials as per delivery schedule stipulated in the Purchase Order and accepted by the suppliers or in case the materials are not as per the specification, we reserve the right to cancel the Purchase Order and procure the materials from elsewhere at the supplier\'s risk and cost.',
    'The drawing sketch and or any other documents given in connection with this Purchase order should be treated in strict confidence and should not be disclosed to any third party without our permission in writing. If a third party comes into possession of any of the documents, this purchase order is liable for immediate cancellation and we reserve the right to claim damages and to procure the materials from elsewhere entirely at the supplier\'s risk and cost.',
    'The duplicate (photocopy) copy of this order is to be signed and returned to ' . ($co['name']) . ', Chennai-96 confirming the acceptance within one week.',
    'Any dispute relating to this Purchase Order shall be deemed to have arisen in Tamil Nadu State and shall be subject to the adjudication by a competent Court in Chennai, Tamil Nadu State. India.',
];

// Use custom terms if configured, otherwise use the 10 standard points.
$customTerms = (string)($shipment['terms_conditions'] ?? '') !== ''
             ? $shipment['terms_conditions']
             : magdyn_setting('shiprcpt.terms_conditions', '');
?>

<?php if ($customTerms !== ''): ?>
    <div style="font-size:9px; line-height:1.6; white-space:pre-wrap;"><?= h($customTerms) ?></div>
<?php else: ?>
    <ol class="tc-list">
        <?php foreach ($standardTerms as $tc): ?>
            <li><?= h($tc) ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<div style="text-align:right; font-size:8px; color:#888; margin-top:8px;">Page 3 of 3</div>

</div><!-- /.po-wrap -->
</body>
</html>
    <?php
    return ob_get_clean();
}
