<?php
/**
 * MagDyn — PO print HTML renderer (Phase D2.6)
 *
 * Single source of HTML for both:
 *   - Browser print at purchase_orders.php?action=print
 *   - PDF generation via includes/_po_pdf.php (dompdf)
 *
 * Designed to render cleanly under dompdf 1.x which lacks support for
 * CSS flexbox / grid. Layout uses HTML tables with vertical-align
 * tricks instead. Other dompdf gotchas observed and avoided:
 *   - No `border-radius` (dompdf supports limited subset, prints
 *     unevenly across renderers)
 *   - No `box-shadow`
 *   - No CSS variables (works server-side but flaky)
 *   - Fonts referenced by family name only — dompdf maps to bundled
 *     DejaVu Sans via lib/fonts/.
 *   - All styles inline or in <style> in <head>; no external CSS
 *     files (we'd have to mark them remote-enabled).
 */

require_once __DIR__ . '/_purchase_orders.php';

/**
 * Build the full PO print HTML as a string.
 *
 * $opts:
 *   'include_actions_bar' => bool  (default true; only meaningful for
 *                                   browser print, where it shows
 *                                   Print / Back buttons. PDF callers
 *                                   set false.)
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
    $address  = $full['primary_address'];
    $lines    = $full['lines'];
    $recv     = $full['receive_lines'];

    // Price and GST come directly from inv_shipment_lines (unit_price, gst_rate).
    // The old inv_shipment_receive_lines approach is removed — that table does not exist.
    $blankPrice = po_has_blank_priced_lines((int)$po['shipment_id']);
    $blankNote  = magdyn_setting('shiprcpt.system_note_blank_price', '');
    $terms      = (string)($shipment['terms_conditions'] ?? '') !== ''
                  ? $shipment['terms_conditions']
                  : magdyn_setting('shiprcpt.terms_conditions', '');

    // Build everything into an output buffer so callers can echo OR
    // pass to dompdf->loadHtml().
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PO <?= h($po['po_no']) ?></title>
<style>
    body  { font-family: 'DejaVu Sans', sans-serif; font-size: 11.5px; color: #222; margin: 0; padding: 0; }
    .wrap { max-width: 760px; margin: 0 auto; padding: 22px 18px; }
    h1 { font-size: 22px; margin: 0 0 2px 0; padding: 0; }
    h2 { font-size: 13px; margin: 14px 0 5px 0; padding: 0 0 3px 0; border-bottom: 1px solid #cccccc; text-transform: uppercase; letter-spacing: 0.04em; color: #444; }
    table.layout { width: 100%; border-collapse: collapse; }
    table.layout td { vertical-align: top; padding: 0; }
    table.data { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
    table.data th, table.data td { border: 1px solid #d0d0d0; padding: 5px 7px; vertical-align: top; text-align: left; }
    table.data th { background: #f4f4f7; font-weight: bold; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #444; }
    .num    { text-align: right; }
    .small  { font-size: 10.5px; color: #666; }
    .meta   { text-align: right; font-size: 10.5px; line-height: 1.55; }
    .info-cell { background: #fafafa; padding: 9px 11px; }
    .info-cell .lbl { display: block; font-size: 9.5px; color: #666; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3px; }
    .system-note { margin: 10px 0; padding: 9px 11px; background: #fff8e1; border-left: 4px solid #f5b400; font-size: 11px; }
    .terms { white-space: pre-wrap; font-size: 10.5px; line-height: 1.55; background: #fafafa; padding: 9px 11px; }
    .actions { margin-bottom: 18px; }
    .actions a, .actions button { display: inline-block; padding: 5px 12px; border: 1px solid #999; background: #fff; font-size: 11.5px; text-decoration: none; color: inherit; margin-right: 4px; cursor: pointer; }
    .actions .primary { background: #2d3a8c; color: #fff; border-color: #2d3a8c; }
    @media print { .actions { display: none; } .wrap { padding: 0 8mm; max-width: none; } }
</style>
</head>
<body>
<div class="wrap">

<?php if ($opts['include_actions_bar']): ?>
<div class="actions">
    <button onclick="window.print()" class="primary">Print</button>
    <a href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$po['id'])) ?>">Back to PO</a>
</div>
<?php endif; ?>

<!-- Head block: title left, meta right -->
<table class="layout"><tr>
    <td>
        <h1>Purchase Order</h1>
        <div class="small">PO No: <strong><?= h($po['po_no']) ?></strong> · Version <?= (int)$po['version'] ?></div>
    </td>
    <td class="meta">
        <div><strong>PO Date:</strong> <?= h($po['po_date']) ?></div>
        <div><strong>Ship/Receipt:</strong> <?= h($shipment['ship_no'] ?? '—') ?></div>
        <?php if (!empty($shipment['reference'])): ?>
            <div><strong>Reference:</strong> <?= h($shipment['reference']) ?></div>
        <?php endif; ?>
    </td>
</tr></table>

<!-- Vendor + Ship-to block -->
<table class="layout" style="margin-top: 14px;"><tr>
    <td style="width: 50%; padding-right: 9px;">
        <div class="info-cell">
            <span class="lbl">Vendor</span>
            <strong><?= h($vendor['name'] ?? '—') ?></strong><br>
            <span class="small">
                <?= h($vendor['code'] ?? '') ?>
                <?php if (!empty($vendor['gst_no'])): ?> · GSTIN: <?= h($vendor['gst_no']) ?><?php endif; ?>
            </span>
            <?php if ($contact): ?>
                <div style="margin-top:5px;">
                    <?= h(trim(($contact['salutation'] ?? '') . ' ' . $contact['name'])) ?>
                    <?php if (!empty($contact['email'])): ?> · <?= h($contact['email']) ?><?php endif; ?>
                    <?php if (!empty($contact['phone'])): ?> · <?= h($contact['phone']) ?><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </td>
    <td style="width: 50%; padding-left: 9px;">
        <div class="info-cell">
            <span class="lbl">Ship to / Address</span>
            <?php if ($address): ?>
                <?= h($address['line1']) ?><br>
                <?php if (!empty($address['line2'])): ?><?= h($address['line2']) ?><br><?php endif; ?>
                <?= h(trim(($address['city'] ?? '') . ' ' . ($address['state'] ?? '') . ' ' . ($address['pincode'] ?? ''))) ?><br>
                <?= h($address['country'] ?? '') ?>
            <?php else: ?>
                <span class="small">No primary address set.</span>
            <?php endif; ?>
            <?php if (!empty($shipment['courier_name'])): ?>
                <div style="margin-top:6px;"><span class="lbl">Courier</span><?= h($shipment['courier_name']) ?></div>
            <?php endif; ?>
        </div>
    </td>
</tr></table>

<?php if (
       !empty($shipment['payment_terms']) ||
       !empty($shipment['packing_forwarding']) ||
       !empty($shipment['freight_insurance']) ||
       !empty($shipment['special_instructions'])
   ): ?>
    <h2>Commercial</h2>
    <table class="data">
        <?php if (!empty($shipment['payment_terms'])): ?>
            <tr><th style="width: 28%;">Payment terms</th><td><?= h($shipment['payment_terms']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($shipment['packing_forwarding'])): ?>
            <tr><th>Packing &amp; forwarding</th><td><?= h($shipment['packing_forwarding']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($shipment['freight_insurance'])): ?>
            <tr><th>Freight &amp; insurance</th><td><?= h($shipment['freight_insurance']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($shipment['special_instructions'])): ?>
            <tr><th>Special instructions</th><td><?= h($shipment['special_instructions']) ?></td></tr>
        <?php endif; ?>
    </table>
<?php endif; ?>

<h2>Lines</h2>
<table class="data">
    <thead>
        <tr>
            <th style="width: 3.5%;">#</th>
            <th style="width: 12%;">Code / Tag</th>
            <th>Description</th>
            <th style="width: 6%;">UOM</th>
            <th class="num" style="width: 7%;">Qty</th>
            <th style="width: 8%;">Before</th>
            <th style="width: 8%;">Delivery</th>
            <th class="num" style="width: 9%;">Unit Price</th>
            <th class="num" style="width: 6%;">GST %</th>
            <th class="num" style="width: 9%;">Line Total</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $subtotal = 0.0;
        $totalGst = 0.0;
        if (!$lines): ?>
            <tr><td colspan="10" class="small" style="text-align:center;">No lines.</td></tr>
        <?php else: foreach ($lines as $idx => $l):
            $isAsset   = $l['entity_type'] === 'asset';
            $isPending = !$isAsset && empty($l['item_id']) && !empty($l['pending_name']);
            $code = $isAsset
                  ? ($l['asset_tag'] ?: '—')
                  : ($l['item_code'] ?: ($isPending ? '(new)' : '—'));
            $desc = $isAsset
                  ? ($l['asset_model'] ?: '')
                  : ($l['item_name'] ?: ($l['pending_name'] ?? ''));
            // Price and GST come directly from the line row (inv_shipment_lines columns).
            $price = ($l['unit_price'] !== null && $l['unit_price'] !== '') ? (float)$l['unit_price'] : null;
            $gst   = ($l['gst_rate']   !== null && $l['gst_rate']   !== '') ? (float)$l['gst_rate']   : null;
            $qty   = (float)($l['qty_planned'] ?? 0);
            $qtyDisp = rtrim(rtrim(number_format($qty, 3), '0'), '.');
            if ($qtyDisp === '' || $qtyDisp === '.') $qtyDisp = '0';
            // Line totals
            $lineTotal    = $price !== null ? $price * $qty : 0.0;
            $lineGstAmt   = ($price !== null && $gst !== null) ? ($lineTotal * $gst / 100) : 0.0;
            $subtotal    += $lineTotal;
            $totalGst    += $lineGstAmt;
        ?>
            <tr>
                <td><?= $idx + 1 ?></td>
                <td><?= h($code) ?><?php if ($isAsset): ?> <span class="small">(A)</span><?php endif; ?></td>
                <td><?= h($desc) ?></td>
                <td><?= h($l['uom_label'] ?? '—') ?></td>
                <td class="num"><strong><?= h($qtyDisp) ?></strong></td>
                <td><?= h($l['before_date'] ?? '—') ?></td>
                <td><?= h($l['delivery_date'] ?? '—') ?></td>
                <td class="num"><?= $price !== null ? '₹ ' . number_format($price, 2) : '<span class="small">—</span>' ?></td>
                <td class="num"><?= $gst !== null ? rtrim(rtrim(number_format($gst, 2), '0'), '.') . ' %' : '<span class="small">—</span>' ?></td>
                <td class="num"><?= $price !== null ? '₹ ' . number_format($lineTotal + $lineGstAmt, 2) : '<span class="small">—</span>' ?></td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody>
    <?php
    $grandTotal = $subtotal + $totalGst;
    if ($lines): ?>
    <tfoot>
        <tr>
            <td colspan="9" class="num" style="background:#f4f4f7; font-weight:bold;">Subtotal</td>
            <td class="num" style="background:#f4f4f7; font-weight:bold;">₹ <?= number_format($subtotal, 2) ?></td>
        </tr>
        <tr>
            <td colspan="9" class="num" style="background:#f4f4f7;">GST</td>
            <td class="num" style="background:#f4f4f7;">₹ <?= number_format($totalGst, 2) ?></td>
        </tr>
        <tr>
            <td colspan="9" class="num" style="background:#e8ecf4; font-weight:bold; font-size:12px;">Grand Total</td>
            <td class="num" style="background:#e8ecf4; font-weight:bold; font-size:12px;">₹ <?= number_format($grandTotal, 2) ?></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<?php if ($blankPrice && $blankNote): ?>
    <div class="system-note">
        <strong>Note:</strong> <?= h($blankNote) ?>
    </div>
<?php endif; ?>

<?php if (!empty($shipment['notes'])): ?>
    <h2>Notes</h2>
    <div style="background:#fafafa; padding:9px 11px; white-space:pre-wrap;"><?= h($shipment['notes']) ?></div>
<?php endif; ?>

<?php if ($terms !== ''): ?>
    <h2>Terms &amp; Conditions</h2>
    <div class="terms"><?= h($terms) ?></div>
<?php endif; ?>

</div>
</body>
</html>
    <?php
    return ob_get_clean();
}
