<?php
/**
 * MagDyn — Old Inventory Imported Data Viewer
 *
 * Shows data imported from the old inventory system.
 * Reads from the old_inv_* staging tables populated by bom_old_import.php.
 *
 * Tabs: Transactions · Shipments · Receipts · Purchase Orders
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('inventory_view_items', 'view');

$tab = (string)input('tab', 'shipments');
$allowed_tabs = ['transactions', 'shipments', 'receipts', 'po'];
if (!in_array($tab, $allowed_tabs, true)) $tab = 'shipments';

// ── per-tab search / pagination ──────────────────────────────────────────────
$search  = trim((string)input('q', ''));
$page    = max(1, (int)input('page', 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// ── counts for tab badges ─────────────────────────────────────────────────────
$counts = [
    'transactions' => (int)db_val('SELECT COUNT(*) FROM old_inv_txns',      [], 0),
    'shipments'    => (int)db_val('SELECT COUNT(*) FROM old_inv_shipments',  [], 0),
    'receipts'     => (int)db_val('SELECT COUNT(*) FROM old_inv_receipts',   [], 0),
    'po'           => (int)db_val('SELECT COUNT(*) FROM old_inv_po',         [], 0),
];

// ── data query for active tab ─────────────────────────────────────────────────
$rows      = [];
$totalRows = 0;

if ($tab === 'transactions') {
    $where  = $search
        ? "WHERE (item_code LIKE ? OR item_name LIKE ? OR txn_type LIKE ?
                  OR source_location LIKE ? OR dest_location LIKE ?
                  OR created_by_name LIKE ?)"
        : '';
    $params = $search ? array_fill(0, 6, '%' . $search . '%') : [];

    $totalRows = (int)db_val("SELECT COUNT(*) FROM old_inv_txns $where", $params, 0);
    $rows      = db_all(
        "SELECT * FROM old_inv_txns $where
         ORDER BY old_id DESC LIMIT $perPage OFFSET $offset",
        $params
    );

} elseif ($tab === 'shipments') {
    $where  = $search
        ? "WHERE (shipment_number LIKE ? OR from_company LIKE ? OR to_company LIKE ?
                  OR courier_name LIKE ? OR tracking_number LIKE ?)"
        : '';
    $params = $search ? array_fill(0, 5, '%' . $search . '%') : [];

    $totalRows = (int)db_val("SELECT COUNT(*) FROM old_inv_shipments $where", $params, 0);
    $rows      = db_all(
        "SELECT * FROM old_inv_shipments $where
         ORDER BY old_shipment_id DESC LIMIT $perPage OFFSET $offset",
        $params
    );

} elseif ($tab === 'receipts') {
    $where  = $search
        ? "WHERE (receipt_number LIKE ? OR from_company LIKE ?)"
        : '';
    $params = $search ? array_fill(0, 2, '%' . $search . '%') : [];

    $totalRows = (int)db_val("SELECT COUNT(*) FROM old_inv_receipts $where", $params, 0);
    $rows      = db_all(
        "SELECT * FROM old_inv_receipts $where
         ORDER BY old_receipt_id DESC LIMIT $perPage OFFSET $offset",
        $params
    );

} elseif ($tab === 'po') {
    $where  = $search
        ? "WHERE (customer LIKE ? OR customer_contact LIKE ? OR product LIKE ?
                  OR po_ref_no LIKE ? OR shipping_courier LIKE ?)"
        : '';
    $params = $search ? array_fill(0, 5, '%' . $search . '%') : [];

    $totalRows = (int)db_val("SELECT COUNT(*) FROM old_inv_po $where", $params, 0);
    $rows      = db_all(
        "SELECT * FROM old_inv_po $where
         ORDER BY old_po_id DESC LIMIT $perPage OFFSET $offset",
        $params
    );
}

$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $perPage) : 1;

// ── page url helper ───────────────────────────────────────────────────────────
function tab_url(string $t, int $pg = 1, string $q = ''): string
{
    $args = ['tab' => $t, 'page' => $pg];
    if ($q !== '') $args['q'] = $q;
    return url('/old_inv_data.php?' . http_build_query($args));
}

$page_title  = 'Old Inventory — Imported Data';
$page_module = 'inventory_view_items';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Old Inventory — Imported Data</h1>
        <p class="muted">
            Data imported from the old inventory system via
            <a href="<?= h(url('/bom_old_import.php')) ?>">BOM Old Import</a>.
            Read-only. Re-import any time to refresh.
        </p>
    </div>
    <div>
        <a class="btn btn-ghost" href="<?= h(url('/bom_old_import.php')) ?>">
            ← Back to Import
        </a>
    </div>
</div>

<!-- ── Tab bar ──────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:20px;flex-wrap:wrap;">
    <?php
    $tabDefs = [
        'shipments'    => 'Shipments',
        'receipts'     => 'Receipts',
        'po'           => 'Purchase Orders',
        'transactions' => 'Transactions',
    ];
    foreach ($tabDefs as $tk => $tl):
        $active = $tab === $tk;
    ?>
    <a href="<?= h(tab_url($tk)) ?>"
       style="padding:8px 16px;font-size:14px;font-weight:600;text-decoration:none;
              border-bottom:<?= $active ? '2px solid var(--primary,#3b82f6)' : '2px solid transparent' ?>;
              margin-bottom:-2px;
              color:<?= $active ? 'var(--primary,#3b82f6)' : 'var(--text-muted,#6b7280)' ?>;">
        <?= h($tl) ?>
        <span style="background:#e5e7eb;border-radius:999px;padding:1px 7px;font-size:11px;margin-left:4px;font-weight:500;">
            <?= number_format($counts[$tk]) ?>
        </span>
    </a>
    <?php endforeach; ?>
</div>

<?php if (array_sum($counts) === 0): ?>
<div class="alert alert-warn">
    No imported data found. Go to
    <a href="<?= h(url('/bom_old_import.php')) ?>">BOM Old Import</a>
    and run "Import Transactions &amp; Shipments" first.
</div>
<?php else: ?>

<!-- ── Search + count ───────────────────────────────────────────────────────── -->
<form method="get" action="<?= h(url('/old_inv_data.php')) ?>"
      style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="search" name="q" value="<?= h($search) ?>"
           placeholder="Search…"
           style="padding:7px 12px;border:1px solid var(--border);border-radius:6px;
                  font-size:14px;min-width:220px;">
    <button type="submit" class="btn btn-ghost">Search</button>
    <?php if ($search): ?>
        <a href="<?= h(tab_url($tab)) ?>" class="btn btn-ghost">✕ Clear</a>
    <?php endif; ?>
    <span style="font-size:13px;color:#6b7280;margin-left:auto;">
        <?= number_format($totalRows) ?> record<?= $totalRows !== 1 ? 's' : '' ?>
        <?= $search ? ' for "' . h($search) . '"' : '' ?>
    </span>
</form>

<!-- ── Data table ───────────────────────────────────────────────────────────── -->
<div style="overflow-x:auto;">
<table class="data-table" style="min-width:100%;">

<?php if ($tab === 'shipments'): ?>
<thead>
    <tr>
        <th>#</th>
        <th>Shipment No</th>
        <th>From</th>
        <th>To</th>
        <th>Courier</th>
        <th>Tracking No</th>
        <th>Ship Date</th>
        <th>Txn Date</th>
        <th>Shipped</th>
        <th>Note</th>
    </tr>
</thead>
<tbody>
<?php if (empty($rows)): ?>
    <tr><td colspan="10" class="empty muted">No shipments found.</td></tr>
<?php else: foreach ($rows as $r): ?>
    <tr>
        <td class="muted small"><?= (int)$r['old_shipment_id'] ?></td>
        <td><strong><?= h($r['shipment_number']) ?></strong></td>
        <td><?= h($r['from_company']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['to_company'])   ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['courier_name']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['tracking_number']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['ship_date']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= $r['txn_date'] ? h(substr($r['txn_date'], 0, 10)) : '<span class="muted">—</span>' ?></td>
        <td>
            <?php if ($r['shipped']): ?>
                <span class="pill pill-success">Yes</span>
            <?php else: ?>
                <span class="pill pill-muted">No</span>
            <?php endif; ?>
        </td>
        <td class="muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
            title="<?= h($r['txn_note']) ?>">
            <?= h($r['txn_note']) ?: '—' ?>
        </td>
    </tr>
<?php endforeach; endif; ?>
</tbody>

<?php elseif ($tab === 'receipts'): ?>
<thead>
    <tr>
        <th>#</th>
        <th>Receipt No</th>
        <th>From Company</th>
        <th>Receipt Date</th>
        <th>Due Date</th>
        <th>Txn Date</th>
        <th>Received</th>
        <th>Note</th>
    </tr>
</thead>
<tbody>
<?php if (empty($rows)): ?>
    <tr><td colspan="8" class="empty muted">No receipts found.</td></tr>
<?php else: foreach ($rows as $r): ?>
    <tr>
        <td class="muted small"><?= (int)$r['old_receipt_id'] ?></td>
        <td><strong><?= h($r['receipt_number']) ?></strong></td>
        <td><?= h($r['from_company']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['receipt_date']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['due_date'])     ?: '<span class="muted">—</span>' ?></td>
        <td><?= $r['txn_date'] ? h(substr($r['txn_date'], 0, 10)) : '<span class="muted">—</span>' ?></td>
        <td>
            <?php if ($r['received_flag']): ?>
                <span class="pill pill-success">Yes</span>
            <?php else: ?>
                <span class="pill pill-muted">No</span>
            <?php endif; ?>
        </td>
        <td class="muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
            title="<?= h($r['txn_note']) ?>">
            <?= h($r['txn_note']) ?: '—' ?>
        </td>
    </tr>
<?php endforeach; endif; ?>
</tbody>

<?php elseif ($tab === 'po'): ?>
<thead>
    <tr>
        <th>#</th>
        <th>PO Ref No</th>
        <th>Customer</th>
        <th>Product</th>
        <th>Qty</th>
        <th>Price</th>
        <th>Courier</th>
        <th>Due Date</th>
        <th>Created</th>
    </tr>
</thead>
<tbody>
<?php if (empty($rows)): ?>
    <tr><td colspan="9" class="empty muted">No purchase orders found.</td></tr>
<?php else: foreach ($rows as $r): ?>
    <tr>
        <td class="muted small"><?= (int)$r['old_po_id'] ?></td>
        <td><strong><?= h($r['po_ref_no']) ?></strong></td>
        <td>
            <?= h($r['customer']) ?>
            <?php if ($r['customer_contact']): ?>
                <div class="muted small"><?= h($r['customer_contact']) ?></div>
            <?php endif; ?>
        </td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
            title="<?= h($r['product']) ?>">
            <?= h($r['product']) ?>
        </td>
        <td class="r"><?= h((string)$r['quantity']) ?> <?= h($r['uom']) ?></td>
        <td class="r"><?= $r['price'] > 0 ? '$' . number_format((float)$r['price'], 2) : '<span class="muted">—</span>' ?></td>
        <td><?= h($r['shipping_courier']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['due_date']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= h($r['po_create_date']) ?: '<span class="muted">—</span>' ?></td>
    </tr>
<?php endforeach; endif; ?>
</tbody>

<?php elseif ($tab === 'transactions'): ?>
<thead>
    <tr>
        <th>#</th>
        <th>Item Code</th>
        <th>Item Name</th>
        <th>Type</th>
        <th class="r">Qty</th>
        <th>Source</th>
        <th>Destination</th>
        <th>By</th>
        <th>Event date</th>
        <th>Recorded</th>
    </tr>
</thead>
<tbody>
<?php if (empty($rows)): ?>
    <tr><td colspan="10" class="empty muted">No transactions found.</td></tr>
<?php else: foreach ($rows as $r): ?>
    <tr>
        <td class="muted small"><?= (int)$r['old_id'] ?></td>
        <td><code><?= h($r['item_code']) ?></code></td>
        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
            title="<?= h($r['item_name']) ?>">
            <?= h($r['item_name']) ?>
        </td>
        <td>
            <?php
            $typeColors = [
                'Move' => '#6366f1', 'Check In' => '#10b981', 'Check Out' => '#f59e0b',
                'Ship' => '#3b82f6', 'Receive' => '#059669', 'Restock' => '#8b5cf6',
                'Reserve' => '#f97316', 'Unreserve' => '#6b7280',
                'Archive' => '#4b5563', 'Unarchive' => '#374151', 'Take Out' => '#ef4444',
            ];
            $color = $typeColors[$r['txn_type']] ?? '#6b7280';
            ?>
            <span style="background:<?= h($color) ?>18;color:<?= h($color) ?>;
                         border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;">
                <?= h($r['txn_type']) ?>
            </span>
        </td>
        <td class="r"><strong><?= h((string)$r['quantity']) ?></strong></td>
        <td class="muted small"><?= h($r['source_location']) ?: '—' ?></td>
        <td class="muted small"><?= h($r['dest_location'])   ?: '—' ?></td>
        <td class="muted small"><?= h($r['created_by_name']) ?: '—' ?></td>
        <td class="muted small" title="When the event occurred (modified_date)"><?= $r['txn_date'] ? h(substr($r['txn_date'], 0, 10)) : '—' ?></td>
        <td class="muted small" title="When the transaction was recorded (creation_date)"><?= !empty($r['recorded_date']) ? h(substr($r['recorded_date'], 0, 10)) : '—' ?></td>
    </tr>
<?php endforeach; endif; ?>
</tbody>
<?php endif; ?>

</table>
</div>

<!-- ── Pagination ────────────────────────────────────────────────────────────── -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
        <a class="btn btn-ghost" href="<?= h(tab_url($tab, $page - 1, $search)) ?>">← Prev</a>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 3);
    $end   = min($totalPages, $page + 3);
    if ($start > 1) echo '<span style="padding:6px 4px;color:#6b7280;">…</span>';
    for ($p = $start; $p <= $end; $p++):
    ?>
        <a class="btn <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?>"
           href="<?= h(tab_url($tab, $p, $search)) ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($end < $totalPages) echo '<span style="padding:6px 4px;color:#6b7280;">…</span>'; ?>

    <?php if ($page < $totalPages): ?>
        <a class="btn btn-ghost" href="<?= h(tab_url($tab, $page + 1, $search)) ?>">Next →</a>
    <?php endif; ?>
</div>
<div style="text-align:center;font-size:12px;color:#9ca3af;margin-top:8px;">
    Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($totalRows) ?> total
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
