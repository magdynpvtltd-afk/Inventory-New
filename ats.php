<?php
/**
 * MagDyn — ATS (Authorisation To Ship) list + view
 *
 * Phase 1 scope: read-only views, status filter tabs, edit of
 * the header's free-text fields (notes, ref_no). No HTTP calls
 * to the billing app yet — the "Finalize / Send to billing" and
 * "Cancel on billing" buttons render disabled with a Phase 2/3
 * tooltip so the placement is visible but the action is wired up
 * later.
 *
 * Routes:
 *   ?action=list                  list of ATSes with status tabs (default)
 *   ?action=view&id=N             view a single ATS (header + lines + push history placeholder)
 *   ?action=edit&id=N             edit notes / ref_no (POST writes back)
 *   ?action=save_meta&id=N (POST) the edit save handler
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/_ats.php';
require_once __DIR__ . '/includes/datatable.php';

$action = (string)input('action', 'list');
$id     = (int)input('id', 0);
$uid    = current_user_id();

require_permission('ats', 'view');


// =============================================================
// POST: save_meta — edit free-text header fields
// =============================================================
if ($action === 'save_meta' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ats', 'edit');

    $row = ats_find($id);
    if (!$row) {
        flash_set('error', 'ATS not found.');
        redirect(url('/ats.php'));
    }
    if ($row['status'] === 'locked') {
        flash_set('error', 'This ATS is locked (billing advanced past Applied). Header edits are blocked.');
        redirect(url('/ats.php?action=view&id=' . $id));
    }

    $refNo = trim((string)input('ats_ref_no', ''));
    $notes = trim((string)input('notes', ''));
    $atsDateRaw = trim((string)input('ats_date', ''));
    $atsDate = $atsDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $atsDateRaw) ? $atsDateRaw : null;

    db_exec(
        "UPDATE ats
            SET ats_ref_no = ?,
                notes      = ?,
                ats_date   = COALESCE(?, ats_date)
          WHERE id = ?",
        [($refNo !== '' ? $refNo : null), ($notes !== '' ? $notes : null), $atsDate, $id]
    );
    // Mutating a pushed ATS flips it back to draft so the operator
    // is prompted to resend in Phase 2.
    if ($row['status'] === 'pushed') {
        db_exec("UPDATE ats SET status = 'draft' WHERE id = ?", [$id]);
    }
    flash_set('success', 'ATS updated.');
    redirect(url('/ats.php?action=view&id=' . $id));
}


// =============================================================
// POST: finalize — push to billing (op=upsert).
// Used for both the first "Finalize / Send to billing" click and
// the "Resend to billing" click on an already-pushed ATS. The
// billing app is idempotent on magdyn_ats_id, so the same call
// covers both cases — the difference is only the button label.
// =============================================================
if ($action === 'finalize' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ats', 'finalize');
    try {
        $result = ats_finalize($id, $uid);
        if ($result['ok']) {
            $j = $result['json'] ?? [];
            $bAtsNo = isset($j['ats_no']) ? (string)$j['ats_no'] : '';
            $bAction = isset($j['action']) ? (string)$j['action'] : 'updated';
            flash_set('success', sprintf(
                'ATS sent to billing successfully (%s on billing side%s).',
                $bAction,
                $bAtsNo !== '' ? ' — billing ATS ' . $bAtsNo : ''
            ));
        } else {
            // Surface the billing app's specific error so the operator
            // sees exactly what to fix (e.g. so_not_found, item_not_found,
            // so_line_not_found, wrong_status).
            flash_set('error', sprintf(
                'Billing push failed (HTTP %d / %s): %s',
                (int)$result['http'],
                (string)($result['error_code'] ?? 'unknown'),
                (string)($result['error'] ?? 'no detail')
            ));
        }
    } catch (\Throwable $e) {
        flash_set('error', 'Could not push to billing: ' . $e->getMessage());
    }
    redirect(url('/ats.php?action=view&id=' . $id));
}


// =============================================================
// POST: cancel — call billing op=cancel and mark local cancelled.
// =============================================================
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ats', 'cancel');
    try {
        $result = ats_cancel($id, $uid);
        if ($result['ok']) {
            if (!empty($result['local_only'])) {
                flash_set('success', 'ATS cancelled locally (it had not been pushed to billing).');
            } else {
                flash_set('success', 'ATS cancelled on billing.');
            }
        } else {
            flash_set('error', sprintf(
                'Cancel failed (HTTP %d / %s): %s',
                (int)$result['http'],
                (string)($result['error_code'] ?? 'unknown'),
                (string)($result['error'] ?? 'no detail')
            ));
        }
    } catch (\Throwable $e) {
        flash_set('error', 'Could not cancel ATS: ' . $e->getMessage());
    }
    redirect(url('/ats.php?action=view&id=' . $id));
}


// =============================================================
// POST: reopen — flip a cancelled ATS back to Draft.
//
// Used when an operator cancelled by mistake, or when circumstances
// changed and the ATS is needed again. Refuses if the ATS is locked
// (billing terminal). A reopened ATS that WAS pushed will need a
// fresh Resend to bring billing back in sync — that's the operator's
// job after reopening, not automatic, so the operator stays in
// control of when the network call fires.
// =============================================================
if ($action === 'reopen' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('ats', 'cancel');
    try {
        ats_reopen($id, $uid);
        flash_set('success', 'ATS reopened. Status flipped to Draft — edit/resend as needed.');
    } catch (\Throwable $e) {
        flash_set('error', 'Could not reopen ATS: ' . $e->getMessage());
    }
    redirect(url('/ats.php?action=view&id=' . $id));
}


// =============================================================
// Render
// =============================================================
$page_title  = 'ATS';
$page_module = 'ats';
require __DIR__ . '/includes/header.php';

if ($action === 'view' && $id > 0) {
    ats_render_view($id, $uid);
} elseif ($action === 'edit' && $id > 0) {
    ats_render_edit($id, $uid);
} else {
    ats_render_list($uid);
}

require __DIR__ . '/includes/footer.php';


// =============================================================
// RENDERERS
// =============================================================

/**
 * The list page. Status-filter tabs across the top, datatable of
 * ATSes underneath. Counts per status are computed once for the
 * tab labels.
 */
function ats_render_list($uid)
{
    $statusFilter = (string)input('status', 'all');
    $valid = ['all','draft','pushed','cancelled','locked'];
    if (!in_array($statusFilter, $valid, true)) $statusFilter = 'all';

    // Per-status counts for the tabs. One query, group by status.
    $counts = ['all' => 0];
    foreach (db_all("SELECT status, COUNT(*) n FROM ats GROUP BY status") as $r) {
        $counts[(string)$r['status']] = (int)$r['n'];
        $counts['all'] += (int)$r['n'];
    }
    foreach (['draft','pushed','cancelled','locked'] as $s) {
        if (!isset($counts[$s])) $counts[$s] = 0;
    }

    $labels = ats_status_labels();

    // --------------------------------------------------------------
    // Standard datatable config. Status-filter tabs feed extra_where
    // so the same datatable handles all status views with one config
    // (sort/search/paginate work identically on every tab).
    //
    // We expose ats_no, po_no, ats_date, line_count, status, billing_ats_no,
    // and last_push_at as sortable columns. Line count is a SQL-side
    // subquery so the datatable can sort on it. The 'last_push_error'
    // text is folded into the same cell as the timestamp so we keep
    // seven columns and avoid a half-empty "error" column on rows that
    // never failed.
    // --------------------------------------------------------------
    $dtCfg = [
        'id'       => 'ats_list_' . $statusFilter,
        'base_sql' => "SELECT a.id, a.ats_no, a.po_no, a.ats_date, a.status,
                              a.billing_ats_no, a.billing_ats_id,
                              a.last_push_at, a.last_push_error,
                              (SELECT COUNT(*) FROM ats_lines al WHERE al.ats_id = a.id) AS line_count
                         FROM ats a",
        'columns' => [
            ['key'=>'ats_no',         'label'=>'ATS No',     'sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.ats_no'],
            ['key'=>'po_no',          'label'=>'PO No',      'sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.po_no'],
            ['key'=>'ats_date',       'label'=>'Date',       'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.ats_date'],
            ['key'=>'line_count',     'label'=>'Lines',      'sortable'=>true,  'searchable'=>false, 'sql_col'=>'line_count', 'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'status',         'label'=>'Status',     'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.status'],
            ['key'=>'billing_ats_no', 'label'=>'Billing ATS','sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.billing_ats_no'],
            ['key'=>'last_push_at',   'label'=>'Last push',  'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.last_push_at'],
        ],
        'default_sort' => ['ats_no', 'desc'],
    ];
    if ($statusFilter !== 'all') {
        $dtCfg['extra_where'] = [['a.status = ?', $statusFilter]];
    }

    $rowRenderer = function ($r) use ($labels) {
        $lbl = $labels[$r['status']] ?? [$r['status'], 'pill-muted'];
        // Distinguish local-only cancel (never pushed) from a billing
        // cancel so the audit trail and remediation path are visible
        // at-a-glance on the list.
        $isLocalOnlyCancel = (
            $r['status'] === 'cancelled'
            && empty($r['billing_ats_no'])
            && stripos((string)($r['last_push_error'] ?? ''), 'local-only') !== false
        );

        $statusCell = '<span class="pill ' . h($lbl[1]) . '">' . h($lbl[0]) . '</span>';
        if ($isLocalOnlyCancel) {
            $statusCell .= ' <span class="small muted" title="Was never pushed to billing — local-only cancel">(local-only)</span>';
        }

        $pushCell = '<span class="muted small">never</span>';
        if ($r['last_push_at']) {
            $pushCell = '<span class="small">' . h($r['last_push_at']) . '</span>';
            if ($r['last_push_error'] && !$isLocalOnlyCancel) {
                $pushCell .= '<br><span class="small" style="color: var(--danger);">' . h($r['last_push_error']) . '</span>';
            }
        }

        return [
            'ats_no'         => '<a href="' . h(url('/ats.php?action=view&id=' . (int)$r['id'])) . '"><strong>' . h($r['ats_no']) . '</strong></a>',
            'po_no'          => h($r['po_no']),
            'ats_date'       => h($r['ats_date']),
            'line_count'     => (int)$r['line_count'],
            'status'         => $statusCell,
            'billing_ats_no' => $r['billing_ats_no']
                                  ? h($r['billing_ats_no'])
                                  : '<span class="muted small">—</span>',
            'last_push_at'   => $pushCell,
        ];
    };

    $dtCfg['title'] = 'ATS';
    if ($statusFilter !== 'all') {
        $dtCfg['title'] .= ' · ' . h(ats_status_label($statusFilter));
    }

    $dt = data_table_run($dtCfg, $rowRenderer);
    ?>
    <div class="page-head">
        <h1>ATS</h1>
    </div>

    <!-- Status-filter tabs. Above the dt-wrap so they read as a
         page-level nav (like the BOM grid's division tabs), not as
         part of the toolbar. -->
    <div class="bom-tabs-row" style="margin-bottom: 8px;">
        <?php foreach ([
            'all'       => 'All',
            'draft'     => 'Draft',
            'pushed'    => 'Pushed',
            'cancelled' => 'Cancelled',
            'locked'    => 'Locked',
        ] as $s => $lbl): ?>
            <a class="bom-tab <?= $statusFilter === $s ? 'active' : '' ?>"
               href="<?= h(url('/ats.php?status=' . $s)) ?>">
                <?= h($lbl) ?>
                <span class="count">(<?= (int)$counts[$s] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    <?php
}


/**
 * Single-ATS view: header card + lines table + (Phase 2 placeholder)
 * action buttons. We keep the placeholder buttons visible-but-disabled
 * so the operator can see where finalize/cancel WILL live, but they
 * can't trigger anything yet — they'll be wired up in Phase 2/3.
 */
function ats_render_view($id, $uid)
{
    $row = ats_find($id);
    if (!$row) {
        echo '<div class="alert alert-error">ATS not found.</div>';
        return;
    }
    $lines = ats_lines($id);
    $labels = ats_status_labels();
    $lbl = $labels[$row['status']] ?? [$row['status'], 'pill-muted'];

    $canEdit     = function_exists('permission_check') && permission_check('ats', 'edit')
                   && $row['status'] !== 'locked';
    $canFinalize = function_exists('permission_check') && permission_check('ats', 'finalize');
    $canCancel   = function_exists('permission_check') && permission_check('ats', 'cancel');
    $billingReady = ats_billing_config() !== null;

    // What's the right primary action right now?
    //   draft     → Finalize (first push)
    //   pushed    → Resend (re-upsert; safe / idempotent)
    //   cancelled → nothing (terminal locally)
    //   locked    → nothing (terminal — billing past Applied)
    $primaryOp = null;
    if ($billingReady) {
        if ($row['status'] === 'draft')  $primaryOp = 'finalize';
        if ($row['status'] === 'pushed') $primaryOp = 'resend';
    }
    $isLocalOnlyCancel = (
        $row['status'] === 'cancelled'
        && empty($row['billing_ats_no'])
        && stripos((string)($row['last_push_error'] ?? ''), 'local-only') !== false
    );
    ?>
    <div class="page-head">
        <h1>
            ATS <?= h($row['ats_no']) ?>
            <span class="pill <?= h($lbl[1]) ?>"><?= h($lbl[0]) ?></span>
            <?php if ($isLocalOnlyCancel): ?>
                <span class="small muted" title="Was never pushed to billing — local-only cancel">(local-only)</span>
            <?php endif; ?>
        </h1>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/ats.php')) ?>">&larr; Back to list</a>
            <?php if ($canEdit): ?>
                <a class="btn btn-ghost" href="<?= h(url('/ats.php?action=edit&id=' . $id)) ?>">Edit</a>
            <?php endif; ?>
            <?php if ($canFinalize && $primaryOp === 'finalize'): ?>
                <form method="post" action="<?= h(url('/ats.php?action=finalize&id=' . $id)) ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary"
                            onclick="return confirm('Push this ATS to the billing app?');">
                        ↑ Finalize / Send to billing
                    </button>
                </form>
            <?php elseif ($canFinalize && $primaryOp === 'resend'): ?>
                <form method="post" action="<?= h(url('/ats.php?action=finalize&id=' . $id)) ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            title="Re-send the current state to billing. Safe — the billing app is idempotent on this ATS id.">
                        ↻ Resend to billing
                    </button>
                </form>
            <?php endif; ?>
            <?php
            // Cancel button: label and confirm text depend on whether
            // this ATS has actually been pushed to billing. A draft that
            // was never pushed is a local-only cancel — no network call.
            // A pushed ATS calls billing op=cancel.
            $cancelMode = null;
            if ($canCancel) {
                if ($row['status'] === 'draft' && empty($row['billing_ats_id'])) {
                    $cancelMode = 'local';     // never pushed
                } elseif (in_array($row['status'], ['draft','pushed'], true)) {
                    $cancelMode = 'billing';   // pushed (or pushed-then-flipped-back-to-draft)
                }
            }
            ?>
            <?php if ($cancelMode === 'local'): ?>
                <form method="post" action="<?= h(url('/ats.php?action=cancel&id=' . $id)) ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            onclick="return confirm('Cancel this ATS locally? It was never pushed to billing — no network call will be made. You can reopen it later if needed.');"
                            title="ATS was never pushed to billing — local-only cancel, no HTTP call.">
                        ✕ Cancel locally
                    </button>
                </form>
            <?php elseif ($cancelMode === 'billing'): ?>
                <form method="post" action="<?= h(url('/ats.php?action=cancel&id=' . $id)) ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            onclick="return confirm('Cancel this ATS on billing? This calls billing op=cancel and marks their copy superseded. Refused if billing has already invoiced or shipped (in which case the ATS becomes Locked here too).');"
                            title="Calls billing op=cancel. Refused once billing has invoiced/shipped.">
                        ✕ Cancel on billing
                    </button>
                </form>
            <?php endif; ?>
            <?php
            // Reopen: a cancelled ATS can be brought back to Draft so
            // the operator can edit and resend. Refused for Locked
            // (terminal — billing past Applied) — handled by the helper.
            if ($canCancel && $row['status'] === 'cancelled'): ?>
                <form method="post" action="<?= h(url('/ats.php?action=reopen&id=' . $id)) ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost"
                            onclick="return confirm('Reopen this ATS? Status flips back to Draft so you can edit and resend.');"
                            title="Flips a cancelled ATS back to Draft. Blocked once billing has invoiced/shipped.">
                        ↶ Reopen
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!$billingReady): ?>
                <span class="pill pill-warn" title="config/app.config.php → billing_integration is not set">
                    billing not configured
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h3 style="margin:0; font-size:14px;">Header</h3></div>
        <div class="card-body">
            <dl class="kv">
                <dt>ATS No</dt><dd><?= h($row['ats_no']) ?></dd>
                <dt>PO No</dt><dd><?= h($row['po_no']) ?></dd>
                <dt>ATS Date</dt><dd><?= h($row['ats_date']) ?></dd>
                <dt>Ref No</dt><dd><?= $row['ats_ref_no'] ? h($row['ats_ref_no']) : '<span class="muted">—</span>' ?></dd>
                <dt>Notes</dt><dd><?= $row['notes'] ? nl2br(h($row['notes'])) : '<span class="muted">—</span>' ?></dd>
                <dt>Status</dt><dd><span class="pill <?= h($lbl[1]) ?>"><?= h($lbl[0]) ?></span></dd>
                <dt>Billing ATS</dt>
                <dd>
                    <?php if ($row['billing_ats_no']): ?>
                        <?= h($row['billing_ats_no']) ?>
                        <span class="muted small">(billing id <?= (int)$row['billing_ats_id'] ?>)</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </dd>
                <dt>Billing status</dt><dd><?= $row['billing_status'] ? h($row['billing_status']) : '<span class="muted">—</span>' ?></dd>
                <dt>Last push</dt>
                <dd>
                    <?php if ($row['last_push_at']): ?>
                        <?= h($row['last_push_at']) ?> ·
                        <?= h($row['last_push_op']) ?> ·
                        HTTP <?= (int)$row['last_push_http'] ?>
                        <?php if ($row['last_push_error']): ?>
                            <br><span style="color: var(--danger);">⚠ <?= h($row['last_push_error']) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted">never (not yet sent to billing)</span>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="card-head">
            <h3 style="margin:0; font-size:14px;">Lines (<?= count($lines) ?>)</h3>
        </div>
        <div class="card-body">
            <?php if (!$lines): ?>
                <p class="muted">No lines yet. Save the ATS step on a job card with this ATS number to populate.</p>
            <?php else: ?>
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>Job Card</th>
                            <th>Item code</th>
                            <th>Item name</th>
                            <th>SO line</th>
                            <th style="text-align:right;">Qty</th>
                            <th>JC status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $ln): ?>
                            <tr>
                                <td>
                                    <?php if ($ln['job_card_id']): ?>
                                        <a href="<?= h(url('/job_card.php?action=view&id=' . (int)$ln['job_card_id'])) ?>">
                                            <?= h($ln['jc_no'] ?: '#' . (int)$ln['job_card_id']) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="mono"><?= h($ln['inv_code']) ?></td>
                                <td><?= h($ln['item_name']) ?></td>
                                <td><?= h($ln['line_no'] ?? '') ?></td>
                                <td style="text-align:right;"><?= h(rtrim(rtrim(number_format((float)$ln['qty'], 3, '.', ''), '0'), '.')) ?></td>
                                <td><span class="muted small"><?= h($ln['jc_status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ----- Push history panel -----
    // Shows every push attempt (success or failure) for this ATS, most
    // recent first. The table is lazy-created on first push, so this
    // section is empty (and the panel hidden) until at least one push
    // has been attempted.
    $history = ats_push_history($id, 20);
    if ($history): ?>
        <div class="card" style="margin-top: 16px;">
            <div class="card-head">
                <h3 style="margin:0; font-size:14px;">Push history (<?= count($history) ?>)</h3>
            </div>
            <div class="card-body">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Op</th>
                            <th>HTTP</th>
                            <th>Result</th>
                            <th>Actor</th>
                            <th>Error / detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="small"><?= h($h['created_at']) ?></td>
                                <td><?= h($h['op']) ?></td>
                                <td><?= (int)$h['http'] ?: '<span class="muted">—</span>' ?></td>
                                <td>
                                    <?php if ((int)$h['ok'] === 1): ?>
                                        <span class="pill pill-success">OK</span>
                                    <?php else: ?>
                                        <span class="pill pill-danger"><?= h($h['error_code'] ?: 'fail') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= h($h['actor_name'] ?: '—') ?></td>
                                <td class="small">
                                    <?php if ($h['error']): ?>
                                        <?= h($h['error']) ?>
                                    <?php elseif ($h['response']): ?>
                                        <details>
                                            <summary class="muted">view response</summary>
                                            <pre style="white-space: pre-wrap; max-height: 200px; overflow: auto; font-size: 11px;"><?= h(substr((string)$h['response'], 0, 2000)) ?></pre>
                                        </details>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php
}


/**
 * Header-edit form. Only the operator-editable header fields
 * (notes, ref_no, ats_date) are mutable — ats_no and po_no are
 * derived from job cards and CAN'T be edited here without
 * orphaning lines, so we render them as read-only.
 */
function ats_render_edit($id, $uid)
{
    require_permission('ats', 'edit');
    $row = ats_find($id);
    if (!$row) {
        echo '<div class="alert alert-error">ATS not found.</div>';
        return;
    }
    if ($row['status'] === 'locked') {
        echo '<div class="alert alert-error">This ATS is locked (billing advanced past Applied). Editing is blocked.</div>';
        return;
    }
    ?>
    <div class="page-head">
        <h1>Edit ATS <?= h($row['ats_no']) ?></h1>
        <div class="head-actions">
            <a class="btn btn-ghost" href="<?= h(url('/ats.php?action=view&id=' . $id)) ?>">Cancel</a>
        </div>
    </div>

    <form method="post" action="<?= h(url('/ats.php?action=save_meta&id=' . $id)) ?>">
        <?= csrf_field() ?>
        <div class="card form-card">
            <div class="form-grid-2">
                <div class="field">
                    <label>ATS No</label>
                    <input type="text" value="<?= h($row['ats_no']) ?>" disabled>
                    <span class="field-hint">Derived from the job card's ATS number; not editable here.</span>
                </div>
                <div class="field">
                    <label>PO No</label>
                    <input type="text" value="<?= h($row['po_no']) ?>" disabled>
                    <span class="field-hint">Derived from the job cards. One PO per ATS.</span>
                </div>
                <div class="field">
                    <label>ATS Date</label>
                    <input type="date" name="ats_date" value="<?= h($row['ats_date']) ?>">
                </div>
                <div class="field">
                    <label>Ref No</label>
                    <input type="text" name="ats_ref_no" maxlength="64" value="<?= h($row['ats_ref_no'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label>Notes</label>
                    <textarea name="notes" rows="4"><?= h($row['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <a class="btn btn-ghost" href="<?= h(url('/ats.php?action=view&id=' . $id)) ?>">Cancel</a>
            </div>
        </div>
    </form>
    <?php
}
