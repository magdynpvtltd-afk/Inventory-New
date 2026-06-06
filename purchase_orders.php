<?php
/**
 * MagDyn — Purchase Orders page
 *
 * Actions:
 *   (default)             List of POs (standard datatable)
 *   action=view           PO detail with print + email actions
 *   action=print          Print-friendly rendering (own minimal chrome)
 *   action=email_compose  Send-mail composer (Phase D2)
 *   action=email_send     Composer POST handler (Phase D2)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_purchase_orders.php';
require_once __DIR__ . '/includes/_email.php';     // Phase D2 — SMTP send
require_once __DIR__ . '/includes/datatable.php';

require_permission('purchase_orders', 'view');
$action = (string)input('action', 'list');
$uid    = current_user_id();

// ============================================================
// PRINT — browser-print HTML, shared by includes/_po_print.php with
// PDF generation. Single source of truth for layout, so the printed
// page and the email-attached PDF stay identical.
// ============================================================
require_once __DIR__ . '/includes/_po_print.php';

if ($action === 'print') {
    require_permission('purchase_orders', 'print');
    $id = (int)input('id', 0);
    $html = po_render_print_html($id);
    if ($html === null) { http_response_code(404); echo 'PO not found.'; exit; }
    echo $html;
    exit;
}

// ============================================================
// DOWNLOAD PDF — direct PDF download for the operator
// ============================================================
if ($action === 'download_pdf') {
    require_permission('purchase_orders', 'print');
    require_once __DIR__ . '/includes/_po_pdf.php';
    $id = (int)input('id', 0);
    $pdf = po_render_pdf($id);
    if (!$pdf) { http_response_code(404); echo 'PO not found.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($pdf['path']));
    header('Content-Disposition: attachment; filename="' . addslashes($pdf['name']) . '"');
    readfile($pdf['path']);
    @unlink($pdf['path']);
    @rmdir(dirname($pdf['path']));
    exit;
}

// ============================================================
// VIEW PDF — serve PDF inline so browser opens its built-in viewer
// ============================================================
if ($action === 'view_pdf') {
    require_permission('purchase_orders', 'print');
    require_once __DIR__ . '/includes/_po_pdf.php';
    $id = (int)input('id', 0);
    $pdf = po_render_pdf($id);
    if (!$pdf) { http_response_code(404); echo 'PO not found.'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($pdf['path']));
    header('Content-Disposition: inline; filename="' . addslashes($pdf['name']) . '"');
    header('Cache-Control: private, max-age=60');
    readfile($pdf['path']);
    @unlink($pdf['path']);
    @rmdir(dirname($pdf['path']));
    exit;
}


// ============================================================
// EMAIL — composer + send (shared with transmittals via _email_compose.php)
// ============================================================
require_once __DIR__ . '/includes/_email_compose.php';

function po_email_compose_context($poId)
{
    $full = po_load_full((int)$poId);
    if (!$full) return null;
    $po = $full['po']; $vendor = $full['vendor']; $shipment = $full['shipment'];

    $contacts = db_all(
        "SELECT id, salutation, name, designation, email, is_primary
           FROM vendor_contacts
          WHERE vendor_id = ? AND email <> '' AND email IS NOT NULL
          ORDER BY is_primary DESC, name",
        [(int)$vendor['id']]
    );

    $subject = 'Purchase Order ' . $po['po_no']
             . ' (v' . (int)$po['version'] . ') - ' . ($vendor['name'] ?? '');

    $absBase = (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST']) : '');
    $absUrl  = $absBase . url('/purchase_orders.php?action=print&id=' . (int)$po['id']);

    // Quick summary for the cover letter — line count + unique receive-due
    // window. Full line table lives in the attached PDF; keeping the email
    // body plain prose so Quill 1.x doesn't mangle it on load (1.x strips
    // tables) and so recipients reading on phones get readable text.
    $lineCount = count($full['lines']);
    $deliveryDates = [];
    foreach ($full['lines'] as $L) {
        if (!empty($L['delivery_date'])) $deliveryDates[(string)$L['delivery_date']] = true;
    }
    ksort($deliveryDates);
    $deliveryDates = array_keys($deliveryDates);
    if (count($deliveryDates) === 1)      $delivery = 'delivery on <strong>' . h($deliveryDates[0]) . '</strong>';
    elseif (count($deliveryDates) > 1)    $delivery = 'staggered deliveries between <strong>' . h($deliveryDates[0]) . '</strong> and <strong>' . h(end($deliveryDates)) . '</strong>';
    else                                   $delivery = 'delivery dates per the attached PO';

    $primary = null;
    foreach ($contacts as $c) { if (!empty($c['is_primary'])) { $primary = $c; break; } }
    $greeting = $primary
        ? ('Dear ' . trim(($primary['salutation'] ?? '') . ' ' . $primary['name']))
        : 'Dear ' . ($vendor['name'] ?? 'Supplier');

    $linesPhrase = $lineCount === 1 ? '1 line item' : ($lineCount . ' line items');

    $body = '<p>' . h($greeting) . ',</p>'
          . '<p>Please find attached <strong>Purchase Order ' . h($po['po_no'])
          . ' (v' . (int)$po['version'] . ')</strong> covering ' . $linesPhrase
          . ', with ' . $delivery . '.</p>'
          . '<p>Item codes, quantities, prices, and terms &amp; conditions are detailed in the attached PDF. '
          . 'You can also <a href="' . h($absUrl) . '">view the PO online</a>.</p>'
          . '<p>Kindly acknowledge receipt of this PO and confirm delivery schedule.</p>'
          . '<p>Best regards,<br>Magneto Dynamics</p>';

    // Phase D2.6 — auto-attach the PO as a PDF. Kind='generated' means
    // the form skips path-signing; the send-handler calls the generator
    // callable below at send time, which produces a fresh PDF in a temp
    // dir. _email_compose.php cleans up after send regardless of result.
    $attachAuto = [
        [
            'kind'        => 'generated',
            'label'       => 'PO PDF',
            'description' => 'Auto-rendered from the PO print template',
            'filename'    => $po['po_no'] . '-v' . (int)$po['version'] . '.pdf',
            'mime'        => 'application/pdf',
            'default_on'  => true,
            'toggle_name' => 'attach_po_pdf',
            'generator'   => function () use ($po) {
                require_once __DIR__ . '/includes/_po_pdf.php';
                return po_render_pdf((int)$po['id']);
            },
        ],
    ];

    return [
        'related_type'    => 'po',
        'related_id'      => (int)$po['id'],
        'page_title'      => 'Email PO ' . $po['po_no'],
        'back_url'        => url('/purchase_orders.php?action=view&id=' . (int)$po['id']),
        'permission'      => ['module' => 'purchase_orders', 'action' => 'email'],
        'subject_default' => $subject,
        'body_default'    => $body,
        'contacts'        => $contacts,
        'attach_auto'     => $attachAuto,
        'reply_to_default' => '',
        'send_url'        => url('/purchase_orders.php?action=email_send'),
        'redirect_url'    => url('/purchase_orders.php?action=view&id=' . (int)$po['id']),
    ];
}

if ($action === 'email_send') {
    require_permission('purchase_orders', 'email');
    csrf_check();
    $id = (int)input('id', 0);
    $ctx = po_email_compose_context($id);
    if (!$ctx) { flash_set('error', 'PO not found.'); redirect(url('/purchase_orders.php')); }
    $res = handle_email_send_post($ctx, $uid);
    if ($res['ok']) {
        flash_set('success', 'Email sent to ' . (int)$res['recipients'] . ' recipient(s).');
    } else {
        flash_set('error', 'Send failed: ' . $res['error']);
    }
    redirect($ctx['redirect_url']);
}

if ($action === 'email_compose') {
    require_permission('purchase_orders', 'email');
    $id = (int)input('id', 0);
    $ctx = po_email_compose_context($id);
    if (!$ctx) { flash_set('error', 'PO not found.'); redirect(url('/purchase_orders.php')); }
    render_email_compose_page($ctx);
    exit;
}


// ============================================================
// VIEW — in-app PO detail page
// ============================================================
if ($action === 'view') {
    $id = (int)input('id', 0);
    $full = po_load_full($id);
    if (!$full) { flash_set('error', 'PO not found.'); redirect(url('/purchase_orders.php')); }

    $po       = $full['po'];
    $shipment = $full['shipment'];
    $vendor   = $full['vendor'];
    $lines    = $full['lines'];

    // Detect if this is a superseded (historical) version — i.e. another
    // PO row exists for the same shipment with a higher version.
    $latestPo      = po_latest_for_shipment((int)$po['shipment_id']);
    $isSuperseded  = $latestPo && (int)$latestPo['id'] !== (int)$po['id'];
    $isHistorical  = !empty($po['lines_snapshot']); // snapshot = lines frozen at amendment
    $versionChain  = po_version_chain((int)$po['shipment_id']);

    $page_title  = 'PO ' . $po['po_no'];
    $page_module = 'purchase_orders';
    $focus_id    = '';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>
                PO <?= h($po['po_no']) ?>
                <?php if ($isSuperseded): ?>
                    <span class="pill pill-muted" style="font-size:.75rem;vertical-align:middle;">v<?= (int)$po['version'] ?> — historical</span>
                <?php else: ?>
                    <span class="pill pill-info" style="font-size:.75rem;vertical-align:middle;">v<?= (int)$po['version'] ?> — latest</span>
                <?php endif; ?>
            </h1>
            <p class="muted small">
                Issued <?= h($po['po_date']) ?> ·
                Vendor: <a href="<?= h(url('/vendors.php?action=edit&id=' . (int)$vendor['id'])) ?>"><?= h($vendor['name']) ?></a> ·
                Linked shipment:
                <a href="<?= h(url('/inventory_shiprcpt.php?action=view&id=' . (int)$shipment['id'])) ?>">
                    <?= h($shipment['ship_no']) ?>
                </a>
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <?php if (!$isSuperseded && permission_check('purchase_orders', 'email')): ?>
                <a class="btn btn-ghost"
                   href="<?= h(url('/purchase_orders.php?action=email_compose&id=' . (int)$po['id'])) ?>">
                    ✉ Send mail
                </a>
            <?php endif; ?>
            <?php if (permission_check('purchase_orders', 'print')): ?>
                <a class="btn btn-ghost"
                   href="<?= h(url('/purchase_orders.php?action=download_pdf&id=' . (int)$po['id'])) ?>">
                    📄 Download PDF
                </a>
                <a class="btn btn-primary" target="_blank"
                   href="<?= h(url('/purchase_orders.php?action=print&id=' . (int)$po['id'])) ?>">
                    🖨 Print PO
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isSuperseded): ?>
        <div class="alert alert-warn" style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:1.3rem;">🕐</span>
            <div>
                <strong>You are viewing a historical version (v<?= (int)$po['version'] ?>).</strong>
                The lines and prices below are frozen as they were <em>before</em> the next amendment was applied.
                <a href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$latestPo['id'])) ?>" style="margin-left:8px;">
                    → View latest version (v<?= (int)$latestPo['version'] ?>)
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$isSuperseded && po_has_blank_priced_lines((int)$shipment['id'])): ?>
        <div class="alert alert-warn">
            <strong>System note:</strong>
            <?= h(magdyn_setting('shiprcpt.system_note_blank_price', '')) ?>
        </div>
    <?php endif; ?>

    <?php if (count($versionChain) > 1): ?>
        <div class="card" style="padding:12px 18px;margin-bottom:14px;">
            <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:8px;">
                <strong>Amendment history</strong>
                <span class="muted small"><?= count($versionChain) ?> version<?= count($versionChain) === 1 ? '' : 's' ?></span>
            </div>
            <table class="data-table" style="margin:0;">
                <thead><tr>
                    <th style="width:60px;">Ver</th>
                    <th>Issued</th>
                    <th>By</th>
                    <th class="r">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach (array_reverse($versionChain) as $vi => $vp):
                    $vIsLatest  = ($vi === 0);
                    $vIsCurrent = ((int)$vp['id'] === (int)$po['id']);
                ?>
                    <tr<?= $vIsCurrent ? ' style="background:var(--row-hover,#f5f5f5);font-weight:600;"' : '' ?>>
                        <td>
                            v<?= (int)$vp['version'] ?>
                            <?php if ($vIsLatest): ?>
                                <span class="pill pill-info" style="margin-left:4px;">latest</span>
                            <?php else: ?>
                                <span class="pill pill-muted" style="margin-left:4px;">history</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted small"><?= h(substr((string)$vp['created_at'], 0, 16)) ?></td>
                        <td><?= h($vp['created_by_name'] ?: '—') ?></td>
                        <td class="r nowrap">
                            <?php if (!$vIsCurrent): ?>
                                <a class="btn btn-icon" href="<?= h(url('/purchase_orders.php?action=view&id=' . (int)$vp['id'])) ?>" title="View this version">👁</a>
                            <?php else: ?>
                                <span class="muted small">← current view</span>
                            <?php endif; ?>
                            <?php if (permission_check('purchase_orders', 'print')): ?>
                                <a class="btn btn-icon" target="_blank"
                                   href="<?= h(url('/purchase_orders.php?action=print&id=' . (int)$vp['id'])) ?>"
                                   title="Print v<?= (int)$vp['version'] ?>">🖨</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Code / Tag</th>
                        <th>Description</th>
                        <th class="r">Qty</th>
                        <th>Before</th>
                        <th>Delivery</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$lines): ?>
                        <tr><td colspan="7" class="empty muted">No lines.</td></tr>
                    <?php else: foreach ($lines as $idx => $l):
                        $isAsset   = $l['entity_type'] === 'asset';
                        $isPending = !$isAsset && empty($l['item_id']) && !empty($l['pending_name']);
                    ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <?php if ($isAsset): ?>
                                    <span class="pill pill-info">A-Asset</span>
                                <?php elseif ($isPending): ?>
                                    <span class="pill pill-warn">I-Pending</span>
                                <?php else: ?>
                                    <span class="pill pill-muted">I-Item</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= h($isAsset ? ($l['asset_tag'] ?: '—') : ($l['item_code'] ?: '(new)')) ?></code></td>
                            <td><?= h($isAsset ? ($l['asset_model'] ?? '') : ($l['item_name'] ?: ($l['pending_name'] ?? ''))) ?></td>
                            <td class="r"><?= h(rtrim(rtrim((string)$l['qty_planned'], '0'), '.')) ?></td>
                            <td><?= h($l['before_date'] ?? '—') ?></td>
                            <td><?= h($l['delivery_date'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // Phase D2 — Recent emails sent against this PO. Surfaces a
    // quick-glance log of what's gone out (and any failures) so the
    // operator can spot delivery problems without digging into
    // sent_emails directly.
    $recentEmails = sent_emails_for('po', (int)$po['id'], 10);
    if ($recentEmails):
    ?>
        <div class="card" style="padding: 14px 18px; margin-top: 14px;">
            <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:8px;">
                <strong>Recent emails</strong>
                <span class="muted small"><?= count($recentEmails) ?> sent</span>
            </div>
            <table class="data-table" style="margin: 0;">
                <thead><tr>
                    <th style="width: 130px;">Queued</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th style="width: 90px;">Status</th>
                    <th>By</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($recentEmails as $em):
                        $statusCls = $em['status'] === 'sent'   ? 'active'
                                   : ($em['status'] === 'failed' ? 'danger' : 'muted');
                    ?>
                        <tr>
                            <td><?= h(substr((string)$em['queued_at'], 0, 16)) ?></td>
                            <td><?= h($em['to_addrs']) ?>
                                <?php if (!empty($em['cc_addrs'])): ?>
                                    <div class="muted small">cc: <?= h($em['cc_addrs']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($em['subject']) ?></td>
                            <td>
                                <span class="pill pill-<?= h($statusCls) ?>"><?= h($em['status']) ?></span>
                                <?php if ($em['status'] === 'failed' && !empty($em['error_message'])): ?>
                                    <div class="muted small" style="margin-top:4px; color:#b91c1c;" title="<?= h($em['error_message']) ?>">
                                        <?= h(substr($em['error_message'], 0, 80)) ?><?= strlen($em['error_message']) > 80 ? '…' : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($em['sender_name'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/footer.php'; exit;
}


// ============================================================
// LIST (default)
// ============================================================
$dtCfg = [
    'id'       => 'purchase_orders',
    'base_sql' => "SELECT po.id, po.po_no, po.po_date, po.version,
                          v.name AS vendor_name, v.code AS vendor_code,
                          s.ship_no
                     FROM purchase_orders po
                     JOIN vendors v        ON v.id = po.vendor_id
                LEFT JOIN inv_shipments s  ON s.id = po.shipment_id",
    'columns'  => [
        ['key'=>'po_no',       'label'=>'PO No',         'sortable'=>true, 'searchable'=>true, 'sql_col'=>'po.po_no'],
        ['key'=>'po_date',     'label'=>'Date',          'sortable'=>true, 'searchable'=>false, 'sql_col'=>'po.po_date'],
        ['key'=>'vendor_name', 'label'=>'Vendor',        'sortable'=>true, 'searchable'=>true, 'sql_col'=>'v.name'],
        ['key'=>'ship_no',     'label'=>'Ship/Receipt',  'sortable'=>true, 'searchable'=>true, 'sql_col'=>'s.ship_no'],
        ['key'=>'version',     'label'=>'Ver',           'sortable'=>true, 'searchable'=>false, 'sql_col'=>'po.version', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'_actions',    'label'=>'Actions',       'sortable'=>false, 'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['po_no', 'desc'],
];
$canPrint = permission_check('purchase_orders', 'print');
$rowRenderer = function ($r) use ($canPrint) {
    $actions = '<a class="btn btn-icon" title="Open PO" aria-label="Open PO" href="'
             . h(url('/purchase_orders.php?action=view&id=' . (int)$r['id'])) . '">↗ <span class="dt-action-label">Open PO</span></a> ';
    if ($canPrint) {
        $actions .= '<a class="btn btn-icon" title="Print PO" aria-label="Print PO" target="_blank" href="'
                  . h(url('/purchase_orders.php?action=print&id=' . (int)$r['id'])) . '">🖨 <span class="dt-action-label">Print PO</span></a>';
    }
    return [
        'po_no'       => '<a href="' . h(url('/purchase_orders.php?action=view&id=' . (int)$r['id'])) . '"><strong>' . h($r['po_no']) . '</strong></a>',
        'po_date'     => h($r['po_date']),
        'vendor_name' => h($r['vendor_name']),
        'ship_no'     => $r['ship_no']
                            ? '<a href="' . h(url('/inventory_shiprcpt.php?action=view&id=' . (int)$r['id'])) . '">' . h($r['ship_no']) . '</a>'
                            : '<span class="muted">—</span>',
        'version'     => (int)$r['version'],
        '_actions'    => dt_actions_wrap($actions),
    ];
};
$dt = data_table_run($dtCfg, $rowRenderer);

$page_title  = 'Purchase Orders';
$page_module = 'purchase_orders';
$focus_id    = '';
$dtCfg['title']       = 'Purchase Orders';
$dtCfg['description'] = 'Auto-generated when a Ship/Receipt is saved. Phase D will add amendments and email.';
require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
