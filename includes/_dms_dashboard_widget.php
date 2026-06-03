<?php
/**
 * MagDyn — Documents Dashboard Widget
 *
 * Renders four small panels of docs-needing-attention. Include
 * from index.php after a permission check:
 *
 *   if (permission_check('documents_dashboard', 'view')) {
 *       include __DIR__ . '/includes/_dms_dashboard_widget.php';
 *   }
 *
 * No <html> / <body> — meant to drop into the existing dashboard layout.
 * Created: 20260519_120000_IST
 */
require_once __DIR__ . '/_dms.php';

// Fetch the four dashboard datasets. Each is bounded so the widget
// stays fast even with thousands of docs.
$_dmsEffective = doc_dashboard_effective_due(7);
$_dmsReview    = doc_dashboard_review_due(14);
$_dmsExpiring  = doc_dashboard_expiring(30);
$_dmsPending   = doc_dashboard_pending_acks_for_user(current_user_id());

// Build a small CSS once (scoped via class names)
?>
<style>
.dms-widget { margin: 18px 0 24px; }
.dms-widget h3 { font-size: 16px; font-weight: 600; margin: 0 0 12px; }
.dms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 14px;
}
.dms-panel {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 14px 16px;
    background: var(--surface, #ffffff);
}
.dms-panel .dms-panel-head {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 8px;
}
.dms-panel h4 {
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--text-light, #6b7280);
    margin: 0;
}
.dms-panel .dms-count {
    font-size: 18px; font-weight: 700;
    color: var(--text, #111827);
}
.dms-panel ul { list-style: none; padding: 0; margin: 0; font-size: 13px; }
.dms-panel li {
    padding: 6px 0;
    border-bottom: 1px dashed var(--border, #e5e7eb);
    display: flex; justify-content: space-between; gap: 10px;
}
.dms-panel li:last-child { border-bottom: none; }
.dms-panel li a { color: var(--primary, #2563eb); text-decoration: none; }
.dms-panel li a:hover { text-decoration: underline; }
.dms-panel li .meta { color: var(--text-light, #6b7280); font-size: 12px; white-space: nowrap; }
.dms-panel .empty { color: var(--text-light, #6b7280); font-size: 12.5px; padding: 8px 0; }
.dms-panel .urgent { color: #dc2626; font-weight: 600; }
.dms-panel .ok { color: #059669; }
</style>

<div class="dms-widget">
    <h3>Documents</h3>
    <div class="dms-grid">

        <!-- Becoming effective soon -->
        <div class="dms-panel">
            <div class="dms-panel-head">
                <h4>Becoming effective (7d)</h4>
                <span class="dms-count"><?= count($_dmsEffective) ?></span>
            </div>
            <?php if (empty($_dmsEffective)): ?>
                <div class="empty">None upcoming.</div>
            <?php else: ?>
                <ul>
                    <?php foreach (array_slice($_dmsEffective, 0, 5) as $d): ?>
                        <li>
                            <a href="<?= h(url('/documents.php?action=view&id=' . (int)$d['id'])) ?>">
                                <?= h($d['code']) ?> — <?= h(mb_strimwidth($d['title'], 0, 36, '…')) ?>
                            </a>
                            <span class="meta"><?= date('d M', strtotime($d['effective_date'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($_dmsEffective) > 5): ?>
                    <div class="meta" style="margin-top:6px">+ <?= count($_dmsEffective) - 5 ?> more</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Due for review -->
        <div class="dms-panel">
            <div class="dms-panel-head">
                <h4>Due for review (14d)</h4>
                <span class="dms-count"><?= count($_dmsReview) ?></span>
            </div>
            <?php if (empty($_dmsReview)): ?>
                <div class="empty">Nothing pending review.</div>
            <?php else: ?>
                <ul>
                    <?php foreach (array_slice($_dmsReview, 0, 5) as $d):
                        $dr = (int)$d['days_remaining'];
                        $cls = $dr < 0 ? 'urgent' : ($dr <= 3 ? 'urgent' : '');
                    ?>
                        <li>
                            <a href="<?= h(url('/documents.php?action=view&id=' . (int)$d['id'])) ?>">
                                <?= h($d['code']) ?> — <?= h(mb_strimwidth($d['title'], 0, 36, '…')) ?>
                            </a>
                            <span class="meta <?= h($cls) ?>">
                                <?= $dr < 0 ? abs($dr) . 'd late' : ($dr === 0 ? 'today' : $dr . 'd') ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($_dmsReview) > 5): ?>
                    <div class="meta" style="margin-top:6px">+ <?= count($_dmsReview) - 5 ?> more</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Expiring soon -->
        <div class="dms-panel">
            <div class="dms-panel-head">
                <h4>Expiring (30d)</h4>
                <span class="dms-count"><?= count($_dmsExpiring) ?></span>
            </div>
            <?php if (empty($_dmsExpiring)): ?>
                <div class="empty">Nothing expiring.</div>
            <?php else: ?>
                <ul>
                    <?php foreach (array_slice($_dmsExpiring, 0, 5) as $d):
                        $dr = (int)$d['days_remaining'];
                        $cls = $dr < 0 ? 'urgent' : ($dr <= 7 ? 'urgent' : '');
                    ?>
                        <li>
                            <a href="<?= h(url('/documents.php?action=view&id=' . (int)$d['id'])) ?>">
                                <?= h($d['code']) ?> — <?= h(mb_strimwidth($d['title'], 0, 36, '…')) ?>
                            </a>
                            <span class="meta <?= h($cls) ?>">
                                <?= $dr < 0 ? 'expired ' . abs($dr) . 'd ago' : ($dr === 0 ? 'today' : $dr . 'd') ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($_dmsExpiring) > 5): ?>
                    <div class="meta" style="margin-top:6px">+ <?= count($_dmsExpiring) - 5 ?> more</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Pending acks for me -->
        <div class="dms-panel">
            <div class="dms-panel-head">
                <h4>Awaiting your acknowledgment</h4>
                <span class="dms-count"><?= count($_dmsPending) ?></span>
            </div>
            <?php if (empty($_dmsPending)): ?>
                <div class="empty"><span class="ok">All caught up.</span></div>
            <?php else: ?>
                <ul>
                    <?php foreach (array_slice($_dmsPending, 0, 5) as $d): ?>
                        <li>
                            <a href="<?= h(url('/documents.php?action=view&id=' . (int)$d['doc_id'])) ?>">
                                <?= h($d['code']) ?> — <?= h(mb_strimwidth($d['title'], 0, 36, '…')) ?>
                            </a>
                            <span class="meta">Rev <?= h($d['rev_label']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($_dmsPending) > 5): ?>
                    <div class="meta" style="margin-top:6px">+ <?= count($_dmsPending) - 5 ?> more</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>
