<?php
/**
 * MagDyn — Job Card module
 * Created: 2026-05-27 IST
 *
 * Actions:
 *   list   default — paginated table of all job cards
 *   view &id=N — read-only detail view (5-step accordion)
 *
 * Editable steps (QC fields / production / ATS number) and the
 * partial-split modal come in Delta 2. Until then, the human path is
 * read-only: job cards land via the SO API, advance via API or
 * supervisor editing through MySQL, and close via the billing API.
 *
 * Permissions
 *   job_card.view         read the list + detail
 *   job_card.create       create cards (Step 1 — SO API has this)
 *   job_card.qc_update    QC Step 2 (used in Delta 2)
 *   job_card.prod_update  Production Step 3 (used in Delta 2)
 *   job_card.ats_update   ATS Step 4 (used in Delta 2)
 *   job_card.close        Billing close (billing API has this)
 *   job_card.edit         Supervisor override (used in Delta 2)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/datatable.php';
require_once __DIR__ . '/includes/_ats.php';

$action = (string)input('action', 'list');
$canView         = permission_check('job_card', 'view');
$canQc           = permission_check('job_card', 'qc_update');
$canProd         = permission_check('job_card', 'prod_update');
$canAts          = permission_check('job_card', 'ats_update');
$canEdit         = permission_check('job_card', 'edit');
require_permission('job_card', 'view');

/**
 * Render the status as a coloured pill. Single source of truth for
 * how statuses look across list, detail, and any future report.
 */
function jc_status_pill($status) {
    static $map = [
        'qc_pending'      => ['QC Pending',      'pill-warn'],
        'prod_pending'    => ['Prod Pending',    'pill-info'],
        'ats_pending'     => ['ATS Pending',     'pill-info'],
        'billing_pending' => ['Billing Pending', 'pill-warn'],
        'closed'          => ['Closed',          'pill-success'],
        'cancelled'       => ['Cancelled',       'pill-danger'],
    ];
    $info = $map[$status] ?? [ucfirst($status), 'pill-neutral'];
    return '<span class="pill ' . $info[1] . '">' . h($info[0]) . '</span>';
}

/**
 * Render a numeric quantity for display: strip trailing fractional
 * zeros and a dangling decimal point, but ONLY when the value has a
 * decimal portion. This avoids the trap where rtrim($v, '0') on a
 * whole number like "30" strips the trailing '0' and produces "3".
 *
 * Examples:
 *   jc_num(30)      -> "30"
 *   jc_num(30.0)    -> "30"
 *   jc_num(3019.98) -> "3019.98"
 *   jc_num(1.500)   -> "1.5"
 *   jc_num(0.001)   -> "0.001"
 *   jc_num(null)    -> ""
 *   jc_num("3000")  -> "3000"
 */
function jc_num($v) {
    if ($v === null || $v === '') return '';
    $s = (string)(0 + $v);             // canonical numeric string ("30", "30.5")
    if (strpos($s, '.') === false) return $s;
    $s = rtrim($s, '0');
    $s = rtrim($s, '.');
    return $s === '' ? '0' : $s;
}

/**
 * Resolve current step label (which department owns the next action).
 * Used in the list to make it obvious who's blocking.
 */
function jc_current_step_label($status) {
    static $map = [
        'qc_pending'      => 'QC',
        'prod_pending'    => 'Production',
        'ats_pending'     => 'ATS',
        'billing_pending' => 'Accounts',
        'closed'          => '—',
        'cancelled'       => '—',
    ];
    return $map[$status] ?? '—';
}

/**
 * Notify every user who holds a given job_card permission. Used at
 * step transitions so the next team sees the card as soon as it's
 * ready. Idempotent in the sense that re-running it just adds more
 * rows — callers should only invoke once per transition.
 */
function jc_notify_step($jobCardId, $permissionCode, $headline, $body = null) {
    $users = db_all(
        "SELECT DISTINCT ur.user_id
           FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p       ON p.id = rp.permission_id
           JOIN modules m           ON m.id = p.module_id
          WHERE m.code = 'job_card' AND p.code = ?",
        [$permissionCode]
    );
    if (!$users) return;
    $href = url('/job_card.php?action=view&id=' . (int)$jobCardId);
    $me   = current_user_id();
    foreach ($users as $u) {
        // Don't notify the actor themselves — they just acted; they
        // already know the card moved.
        if ((int)$u['user_id'] === (int)$me) continue;
        db_exec(
            "INSERT INTO notifications (user_id, entity_type, entity_id, headline, body, href)
             VALUES (?, 'job_card', ?, ?, ?, ?)",
            [(int)$u['user_id'], $jobCardId, $headline, $body, $href]
        );
    }
}

/**
 * Write an event into the job_card_events audit table.
 */
function jc_event($jobCardId, $type, $data = null, $actorLabel = null) {
    db_exec(
        "INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_user_id, actor_label)
         VALUES (?, ?, ?, ?, ?)",
        [$jobCardId, $type, $data === null ? null : json_encode($data),
         current_user_id(), $actorLabel]
    );
}

/**
 * Return true if the actor can edit a given step on a job card right
 * now. Combines the role-permission with the workflow rule (can't edit
 * a future step that hasn't been reached yet). Supervisors with
 * job_card.edit can edit anything.
 *
 * $step: 'qc' | 'prod' | 'ats'
 */
function jc_can_edit_step($jc, $step) {
    // Cancelled and closed cards are terminal — no processing allowed
    // by anyone, including users with the job_card.edit (supervisor)
    // permission. Once a card is cancelled the workflow is over; any
    // further changes belong on a NEW card. This check is FIRST, before
    // the supervisor override, so cancellation is truly absolute.
    $status = $jc['status'];
    if ($status === 'closed' || $status === 'cancelled') return false;

    if (permission_check('job_card', 'edit')) return true;
    if ($step === 'qc') {
        if (!permission_check('job_card', 'qc_update')) return false;
        // QC can save when card is at QC; after that, MIR fields are
        // QC-only per Q7 — they can re-edit even after approval.
        return in_array($status, ['qc_pending','prod_pending','ats_pending','billing_pending'], true);
    }
    if ($step === 'prod') {
        if (!permission_check('job_card', 'prod_update')) return false;
        // Production gates: only after QC has been completed.
        if ($status === 'qc_pending') return false;
        return in_array($status, ['prod_pending','ats_pending','billing_pending'], true);
    }
    if ($step === 'ats') {
        if (!permission_check('job_card', 'ats_update')) return false;
        if (in_array($status, ['qc_pending','prod_pending'], true)) return false;
        return in_array($status, ['ats_pending','billing_pending'], true);
    }
    return false;
}

// ============================================================
// SAVE — Step 2 (QC / MIR)
// ============================================================
if ($action === 'save_qc') {
    csrf_check();
    $id = (int)input('id', 0);
    $jc = db_one("SELECT * FROM job_cards WHERE id = ?", [$id]);
    if (!$jc) {
        flash_set('error', 'Job card not found.');
        redirect(url('/job_card.php'));
    }
    if (!jc_can_edit_step($jc, 'qc')) {
        flash_set('error', 'You do not have permission to edit the QC step on this card.');
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    $ats = (string)input('ats_needed', '');
    $ppm = (string)input('ppm', '');
    $qn  = (string)input('qn', '');
    if (!in_array($ats, ['Yes','No'], true)) $ats = '';
    if (!in_array($ppm, ['Yes','No'], true)) $ppm = '';
    if (!in_array($qn,  ['Yes','No'], true)) $qn  = '';
    $batchQc = trim((string)input('batch_qc', ''));
    $mirText = trim((string)input('mir_text', ''));

    // First-time save advances to prod_pending. Re-saves preserve the
    // existing status (so QC can edit after approval without flipping
    // a closed card back to pending). qc_completed_at/by also stick.
    $isFirstSave = empty($jc['qc_completed_at']);
    $newStatus   = $jc['status'];
    if ($isFirstSave && $jc['status'] === 'qc_pending') {
        $newStatus = 'prod_pending';
    }

    db_exec(
        "UPDATE job_cards
            SET ats_needed = ?, ppm = ?, qn = ?, batch_qc = ?, mir_text = ?,
                qc_completed_at = COALESCE(qc_completed_at, NOW()),
                qc_completed_by = COALESCE(qc_completed_by, ?),
                status = ?
          WHERE id = ?",
        [$ats, $ppm, $qn, ($batchQc ?: null), ($mirText ?: null),
         current_user_id(), $newStatus, $id]
    );

    jc_event($id, $isFirstSave ? 'qc_saved' : 'edited',
             ['step' => 'qc', 'ats_needed' => $ats, 'first_save' => $isFirstSave]);

    if ($isFirstSave) {
        jc_notify_step($id, 'prod_update',
            sprintf('%s ready for production', $jc['jc_no']),
            'QC has signed off — MIR recorded.');
    }
    flash_set('success', 'QC details saved.');
    redirect(url('/job_card.php?action=view&id=' . $id));
}

// ============================================================
// SAVE — Step 3 (Production)
// ============================================================
if ($action === 'save_prod') {
    csrf_check();
    $id = (int)input('id', 0);
    $jc = db_one("SELECT * FROM job_cards WHERE id = ?", [$id]);
    if (!$jc) {
        flash_set('error', 'Job card not found.');
        redirect(url('/job_card.php'));
    }
    if (!jc_can_edit_step($jc, 'prod')) {
        flash_set('error', 'You do not have permission to edit production on this card.');
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    $subQty   = (float)input('sub_qty', 0);
    $batchProd = trim((string)input('batch_prod', ''));
    $poQty   = (float)$jc['po_qty'];
    $splitOk = input('split_confirm', '0') === '1';
    $splitReason = trim((string)input('split_reason', ''));

    if ($subQty <= 0) {
        flash_set('error', 'Submitted quantity must be greater than 0.');
        redirect(url('/job_card.php?action=view&id=' . $id));
    }
    if ($subQty > $poQty + 0.0001) {
        flash_set('error', sprintf('Submitted qty (%g) exceeds PO qty (%g). Use a separate job card.',
                                   $subQty, $poQty));
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    // Detect first-save vs re-edit, and the direction of the qty change
    // on re-edit. We need this BEFORE the partial-prompt redirect so we
    // can skip prompting on a pure qty increase (which doesn't need the
    // split modal — children get absorbed instead).
    $isFirstSave = empty($jc['prod_completed_at']);
    $oldSubQty = $jc['sub_qty'] !== null ? (float)$jc['sub_qty'] : 0.0;
    $isReduction = !$isFirstSave && $subQty + 0.0001 < $oldSubQty;
    $isIncrease  = !$isFirstSave && $subQty > $oldSubQty + 0.0001;

    // Partial-production guard. If the qty is short of the PO and the
    // user hasn't confirmed a split, bounce them back with a flag so
    // the UI renders the modal. We use a redirect query param rather
    // than re-rendering because the UI is server-rendered + we want
    // any other pending flash messages to land too.
    //
    // The modal is needed only when the operator is COMMITTING to a
    // partial qty for the first time (first-save partial) or
    // REDUCING from a previously-saved sub_qty (re-edit reduction).
    // A pure increase on re-edit doesn't need the modal — children
    // get absorbed without a confirmation prompt.
    $isPartial = ($subQty + 0.0001) < $poQty;
    $needsSplitPrompt = $isPartial && !$splitOk && !$isIncrease;
    if ($needsSplitPrompt) {
        redirect(url('/job_card.php?action=view&id=' . $id . '&split_prompt=1&split_qty=' . urlencode(jc_num($subQty))));
    }

    if ($isReduction && !empty($jc['ats_completed_at'])) {
        flash_set('error', sprintf(
            'Cannot reduce submitted qty from %s to %s — ATS has already moved stock to SHP. '
          . 'Reconcile SHP inventory manually before reducing.',
            jc_num($oldSubQty), jc_num($subQty)
        ));
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    // For INCREASES on re-edit, the additional units are absorbed from
    // the parent's active children. Resolve which children are absorbable
    // here so the validation below can check the available pool, and
    // so the transaction can shrink them. A child is absorbable if it
    // belongs to this card and is still in qc_pending or prod_pending —
    // a child whose production has finished can't be shrunk silently.
    $absorbableChildren = [];
    $availableFromChildren = 0.0;
    if ($isIncrease) {
        // ORDER BY id DESC = absorb most recently created children first.
        // This matches a FIFO of "the last balance card carved off" being
        // the first one rolled back. If you'd prefer LIFO order edit
        // the ORDER BY clause.
        $absorbableChildren = db_all(
            "SELECT id, jc_no, po_qty, status
               FROM job_cards
              WHERE parent_id = ?
                AND status IN ('qc_pending','prod_pending')
              ORDER BY id DESC",
            [$id]
        );
        foreach ($absorbableChildren as $c) $availableFromChildren += (float)$c['po_qty'];
    }

    if ($isIncrease) {
        $increaseDelta = $subQty - $oldSubQty;
        // The pool the operator can claim from: child cards' combined
        // po_qty plus the parent's existing sub_qty (which is what they
        // already had). Anything beyond that means they're claiming
        // more than the customer ordered — refuse.
        if ($increaseDelta > $availableFromChildren + 0.0001) {
            flash_set('error', sprintf(
                'Cannot increase submitted qty from %s to %s — only %s units available across the active child job card(s) to absorb. '
              . 'The parent + children must add up to the original PO qty.',
                jc_num($oldSubQty), jc_num($subQty), jc_num($availableFromChildren)
            ));
            redirect(url('/job_card.php?action=view&id=' . $id));
        }
        // ATS-submitted increases are still allowed (the increase
        // doesn't move stock — that happens at ATS save), but they're
        // semantically odd because the ATS team already signed off on
        // the old qty. Don't block, but log a notable event.
    }

    // Read the box-table inputs (parallel arrays). Empty rows are
    // skipped when iterating below.
    $bNo    = (array)input('box_no',    []);
    $bType  = (array)input('box_type',  []);
    $bSize  = (array)input('box_size',  []);
    $bW     = (array)input('box_weight', []);
    $bQ     = (array)input('box_qty',   []);
    $boxCount = max(count($bNo), count($bType), count($bSize), count($bW), count($bQ));

    // Status only advances on first save. Subsequent edits preserve
    // status (so production can correct a batch number after handoff
    // without flipping the card back).
    $newStatus = $jc['status'];
    if ($isFirstSave && $jc['status'] === 'prod_pending') {
        $newStatus = 'ats_pending';
    }

    try {
        db()->beginTransaction();

        db_exec(
            "UPDATE job_cards
                SET sub_qty = ?, batch_prod = ?,
                    prod_completed_at = COALESCE(prod_completed_at, NOW()),
                    prod_completed_by = COALESCE(prod_completed_by, ?),
                    status = ?
              WHERE id = ?",
            [$subQty, ($batchProd ?: null), current_user_id(), $newStatus, $id]
        );

        // Increase absorption: shrink active children to give the units
        // back to the parent. Walk children newest-first, draining each
        // by up to its po_qty until the increase delta is covered. If a
        // child's full po_qty is consumed, cancel it (status='cancelled')
        // rather than leaving a zero-qty card. The validation above
        // guaranteed the pool is sufficient.
        $absorbedFrom = [];
        if ($isIncrease && !empty($absorbableChildren)) {
            $remaining = $subQty - $oldSubQty;
            foreach ($absorbableChildren as $c) {
                if ($remaining <= 0.0001) break;
                $childQty = (float)$c['po_qty'];
                if ($childQty <= $remaining + 0.0001) {
                    // Drain this child completely → cancel it.
                    db_exec(
                        "UPDATE job_cards SET status = 'cancelled' WHERE id = ?",
                        [(int)$c['id']]
                    );
                    jc_event((int)$c['id'], 'cancelled', [
                        'source' => 'parent_qty_increase_absorbed',
                        'parent_id' => $id, 'parent_jc_no' => $jc['jc_no'],
                        'absorbed_qty' => $childQty,
                        'previous_po_qty' => $childQty,
                    ]);
                    $absorbedFrom[] = ['jc_no' => $c['jc_no'], 'absorbed' => $childQty, 'cancelled' => true];
                    $remaining -= $childQty;
                } else {
                    // Partial drain → reduce po_qty on the child.
                    $newChildQty = $childQty - $remaining;
                    db_exec(
                        "UPDATE job_cards SET po_qty = ? WHERE id = ?",
                        [$newChildQty, (int)$c['id']]
                    );
                    jc_event((int)$c['id'], 'edited', [
                        'source' => 'parent_qty_increase_absorbed',
                        'parent_id' => $id, 'parent_jc_no' => $jc['jc_no'],
                        'absorbed_qty' => $remaining,
                        'previous_po_qty' => $childQty,
                        'new_po_qty' => $newChildQty,
                    ]);
                    $absorbedFrom[] = ['jc_no' => $c['jc_no'], 'absorbed' => $remaining, 'cancelled' => false];
                    $remaining = 0;
                }
            }
        }

        // Wipe + reinsert boxes (simpler than diff). The grid can have
        // arbitrary edits across rows; full replace is safe because
        // box rows have no FKs to anything else.
        db_exec("DELETE FROM job_card_boxes WHERE job_card_id = ?", [$id]);
        for ($i = 0; $i < $boxCount; $i++) {
            $no   = isset($bNo[$i])   ? trim((string)$bNo[$i])   : '';
            $type = isset($bType[$i]) ? trim((string)$bType[$i]) : '';
            $size = isset($bSize[$i]) ? trim((string)$bSize[$i]) : '';
            $w    = isset($bW[$i])    ? (float)$bW[$i] : 0;
            $q    = isset($bQ[$i])    ? (float)$bQ[$i] : 0;
            if ($no === '' && $type === '' && $size === '' && $w == 0 && $q == 0) continue;
            db_exec(
                "INSERT INTO job_card_boxes (job_card_id, sort_order, box_no, box_type, box_size, weight_kg, qty_in_box)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$id, $i, ($no ?: (string)($i + 1)), ($type ?: null), ($size ?: null),
                 ($w > 0 ? $w : null), ($q > 0 ? $q : null)]
            );
        }

        // Reduction absorption + partial-split — the freed units have
        // to go somewhere when the operator reduces sub_qty. Symmetric
        // to the increase-absorption above: pick the most recent active
        // child and bump its po_qty by the freed units, so the parent +
        // children continue to add up to the original PO qty. Only fall
        // back to CREATING a new child when there are no absorbable
        // children to expand.
        //
        // A child is "absorbable" for reduction-expansion if it belongs
        // to this card and is still in qc_pending or prod_pending. A
        // child past those states (ats_pending+) has already moved
        // along the workflow; silently growing its po_qty would disturb
        // commitments already made by ATS/billing.
        //
        // Cases that REACH this block:
        //   - First save with sub_qty < po_qty (normal partial split).
        //     No existing children; create a new one for (po_qty-subQty).
        //   - Re-edit reducing sub_qty from a previously-saved value.
        //     If there's an active child, expand it by the delta.
        //     Otherwise create a new child for the delta.
        $childId      = null;        // new child created in this save
        $childBalance = 0.0;
        $expandedChild = null;       // existing child whose po_qty was bumped
        $expandedBy    = 0.0;
        if ($splitOk) {
            if ($isFirstSave && $isPartial) {
                $childBalance = $poQty - $subQty;
            } elseif ($isReduction) {
                $deltaFreed = $oldSubQty - $subQty;
                // Look for the most recent active child to expand.
                $candidate = db_one(
                    "SELECT id, jc_no, po_qty, status
                       FROM job_cards
                      WHERE parent_id = ?
                        AND status IN ('qc_pending','prod_pending')
                      ORDER BY id DESC
                      LIMIT 1",
                    [$id]
                );
                if ($candidate) {
                    // Expand the existing child instead of creating a new one.
                    $oldChildQty = (float)$candidate['po_qty'];
                    $newChildQty = $oldChildQty + $deltaFreed;
                    db_exec("UPDATE job_cards SET po_qty = ? WHERE id = ?",
                            [$newChildQty, (int)$candidate['id']]);
                    jc_event((int)$candidate['id'], 'edited', [
                        'source'         => 'parent_qty_reduction_absorbed',
                        'parent_id'      => $id,
                        'parent_jc_no'   => $jc['jc_no'],
                        'absorbed_qty'   => $deltaFreed,
                        'previous_po_qty'=> $oldChildQty,
                        'new_po_qty'     => $newChildQty,
                    ]);
                    jc_event($id, 'partial_split', [
                        'expanded_child_id'    => (int)$candidate['id'],
                        'expanded_child_jc_no' => $candidate['jc_no'],
                        'submitted'            => $subQty,
                        'previous_sub_qty'     => $oldSubQty,
                        'delta_freed'          => $deltaFreed,
                        'reason'               => $splitReason,
                    ]);
                    $expandedChild = $candidate;
                    $expandedBy    = $deltaFreed;
                } else {
                    // No existing children to expand — create a new one.
                    $childBalance = $deltaFreed;
                }
            }
        }
        if ($childBalance > 0.0001) {
            db_exec(
                "INSERT INTO job_cards
                   (jc_no, status, item_id, po_no, line_no, po_qty, delivery_date,
                    supplier_name, location, ack_no, ds,
                    ats_needed, ppm, qn, batch_qc, mir_text,
                    qc_completed_at, qc_completed_by,
                    parent_id, partial_reason, created_by)
                 VALUES (?, 'prod_pending', ?, ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?, ?, ?,
                         NOW(), ?,
                         ?, ?, ?)",
                ['__pending__', (int)$jc['item_id'], $jc['po_no'], $jc['line_no'], $childBalance, $jc['delivery_date'],
                 $jc['supplier_name'], $jc['location'], $jc['ack_no'], (int)$jc['ds'],
                 $jc['ats_needed'], $jc['ppm'], $jc['qn'], $jc['batch_qc'], $jc['mir_text'],
                 current_user_id(),
                 $id,
                 $splitReason ?: ($isReduction ? 'Partial production reduction on re-edit' : 'Partial production split'),
                 current_user_id()]
            );
            $childId = (int)db()->lastInsertId();
            $childJcNo = sprintf('JC-%06d', $childId);
            db_exec("UPDATE job_cards SET jc_no = ? WHERE id = ?", [$childJcNo, $childId]);
            jc_event($childId, 'created', [
                'source' => $isReduction ? 'partial_split_on_reduction' : 'partial_split',
                'parent_id' => $id, 'balance' => $childBalance,
            ]);
            jc_event($id, 'partial_split', [
                'child_id' => $childId, 'child_jc_no' => $childJcNo,
                'submitted' => $subQty,
                'previous_sub_qty' => $isReduction ? $oldSubQty : null,
                'balance' => $childBalance,
                'reason' => $splitReason,
            ]);
        }

        jc_event($id, $isFirstSave ? 'prod_saved' : 'edited',
                 ['step' => 'prod', 'sub_qty' => $subQty, 'boxes' => $boxCount,
                  'first_save' => $isFirstSave, 'partial' => $isPartial]);

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[job_card/save_prod] db error: ' . $e->getMessage());
        flash_set('error', 'Could not save production: ' . $e->getMessage());
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    if ($isFirstSave) {
        jc_notify_step($id, 'ats_update',
            sprintf('%s ready for ATS', $jc['jc_no']),
            sprintf('Production completed — %s units submitted%s.',
                    jc_num($subQty), $isPartial ? ' (partial)' : ''));
    }
    // Child notification — a new child was created. Fires when no
    // existing absorbable child was available to expand, OR on the
    // normal first-save partial-split path.
    if ($childId) {
        jc_notify_step($childId, 'prod_update',
            sprintf('%s — new card from partial split', $childJcNo),
            sprintf('%s units split off from %s%s.',
                    jc_num($childBalance), $jc['jc_no'],
                    $isReduction ? ' (qty reduction on re-edit)' : ''));
    }
    // Expanded-child notification — an existing active child got bigger.
    // The team holding that child's current step needs to know its
    // workload just grew.
    if ($expandedChild) {
        $expandedPerm = $expandedChild['status'] === 'qc_pending' ? 'qc_update' : 'prod_update';
        jc_notify_step((int)$expandedChild['id'], $expandedPerm,
            sprintf('%s — qty expanded by %s units', $expandedChild['jc_no'], jc_num($expandedBy)),
            sprintf('%s reduced its submitted qty; the freed units were absorbed into this card.', $jc['jc_no']));
    }

    // Build the success flash. Covers four transitional cases:
    //   - normal save (no child, no absorption, no expansion)
    //   - new child created (first-save partial OR no absorbable child existed)
    //   - existing child expanded (re-edit reduction with an active child)
    //   - children absorbed (qty increase on re-edit)
    $flashMsg = 'Production details saved.';
    if ($childId) {
        $flashMsg .= ' Child job card created: ' . $childJcNo
                   . ' carrying ' . jc_num($childBalance) . ' units.';
    }
    if ($expandedChild) {
        $flashMsg .= ' ' . jc_num($expandedBy) . ' units released to existing child '
                   . $expandedChild['jc_no'] . '.';
    }
    if (!empty($absorbedFrom)) {
        $absorbBits = [];
        foreach ($absorbedFrom as $a) {
            $absorbBits[] = jc_num($a['absorbed']) . ' from ' . $a['jc_no']
                          . ($a['cancelled'] ? ' (cancelled — fully absorbed)' : '');
        }
        $flashMsg .= ' Absorbed ' . jc_num($subQty - $oldSubQty)
                   . ' units from active children: ' . implode('; ', $absorbBits) . '.';
    }
    flash_set('success', $flashMsg);
    redirect(url('/job_card.php?action=view&id=' . $id));
}

// ============================================================
// SAVE — Step 4 (ATS)
// ============================================================
// On save, this step moves the produced stock to the SHP location so
// the billing-system close (via API) can subsequently ship it out.
// Wrapped in a transaction so a stock-move failure doesn't half-update
// the card.
if ($action === 'save_ats') {
    csrf_check();
    $id = (int)input('id', 0);
    $jc = db_one("SELECT * FROM job_cards WHERE id = ?", [$id]);
    if (!$jc) {
        flash_set('error', 'Job card not found.');
        redirect(url('/job_card.php'));
    }
    if (!jc_can_edit_step($jc, 'ats')) {
        flash_set('error', 'You do not have permission to update the ATS step on this card.');
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    // ATS number is no longer operator-entered — it's auto-assigned by
    // ats_create_for_job_card via code_next('ats'). This handler now
    // just commits the step (moves stock to SHP on first save) and
    // triggers the ATS create/refresh. The form has no ats_no input.

    $isFirstSave = empty($jc['ats_completed_at']);
    $newStatus = $jc['status'];
    if ($isFirstSave && $jc['status'] === 'ats_pending') {
        $newStatus = 'billing_pending';
    }

    try {
        db()->beginTransaction();

        // Create / refresh the ATS first so we know the number to mirror
        // into job_cards.ats_no for display. Inside the same transaction
        // so a failure here rolls back the whole save.
        $atsId = ats_create_for_job_card($jc, current_user_id());
        $atsRow = ats_find($atsId);
        $atsNo = $atsRow ? (string)$atsRow['ats_no'] : '';

        db_exec(
            "UPDATE job_cards
                SET ats_no = ?,
                    ats_completed_at = COALESCE(ats_completed_at, NOW()),
                    ats_completed_by = COALESCE(ats_completed_by, ?),
                    status = ?
              WHERE id = ?",
            [$atsNo, current_user_id(), $newStatus, $id]
        );

        // Move produced stock into SHP on FIRST save only.
        if ($isFirstSave) {
            $shp = db_one("SELECT id, code, name FROM locations WHERE code = 'SHP' AND is_active = 1 LIMIT 1");
            if (!$shp) {
                throw new \RuntimeException("SHP location not found or inactive. Create/activate it in admin first.");
            }
            $itemId = (int)$jc['item_id'];
            $subQty = (float)$jc['sub_qty'];
            if ($subQty <= 0) {
                throw new \RuntimeException("Job card has no submitted production qty — can't move stock to SHP.");
            }
            $existing = db_one(
                "SELECT qty FROM inv_item_location_stock WHERE item_id = ? AND location_id = ?",
                [$itemId, (int)$shp['id']]
            );
            if ($existing) {
                db_exec(
                    "UPDATE inv_item_location_stock SET qty = qty + ? WHERE item_id = ? AND location_id = ?",
                    [$subQty, $itemId, (int)$shp['id']]
                );
            } else {
                db_exec(
                    "INSERT INTO inv_item_location_stock (item_id, location_id, qty) VALUES (?, ?, ?)",
                    [$itemId, (int)$shp['id'], $subQty]
                );
            }
        }

        jc_event($id, $isFirstSave ? 'ats_saved' : 'edited',
                 ['step' => 'ats', 'ats_no' => $atsNo, 'ats_id' => $atsId, 'first_save' => $isFirstSave]);

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[job_card/save_ats] db error: ' . $e->getMessage());
        flash_set('error', 'Could not save ATS: ' . $e->getMessage());
        redirect(url('/job_card.php?action=view&id=' . $id));
    }

    if ($isFirstSave) {
        jc_notify_step($id, 'close',
            sprintf('%s awaiting billing', $jc['jc_no']),
            'ATS step complete — stock moved to SHP. Awaiting invoice push from billing system.');
    }
    flash_set('success', sprintf('ATS %s saved.%s', $atsNo, $isFirstSave ? ' Stock moved to SHP.' : ''));
    redirect(url('/job_card.php?action=view&id=' . $id));
}

// ============================================================
// NOTIFICATIONS — list + mark-read
// ============================================================
if ($action === 'notifications') {
    $uid = (int)current_user_id();
    $notes = db_all(
        "SELECT * FROM notifications
          WHERE user_id = ?
          ORDER BY is_read ASC, created_at DESC
          LIMIT 100",
        [$uid]
    );

    $page_title  = 'Notifications';
    $page_module = 'job_card';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/'),
        'back_label' => 'Back',
        'title'      => 'Notifications',
    ]) ?>
    <div style="padding: 18px 22px;">
        <?php if (!$notes): ?>
            <p class="muted">No notifications yet. New job cards and step transitions assigned to your role will appear here.</p>
        <?php else: ?>
            <form method="post" action="<?= h(url('/job_card.php?action=mark_all_read')) ?>" style="margin: 0 0 12px;">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm">Mark all as read</button>
            </form>
            <table class="data-table" style="border-collapse: separate; border-spacing: 0;">
                <thead><tr>
                    <th style="width:130px;">When</th>
                    <th>Notification</th>
                    <th style="width:80px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($notes as $n): ?>
                    <tr style="<?= $n['is_read'] ? 'opacity:0.65;' : 'background:#fafbff;' ?>">
                        <td style="white-space:nowrap;" class="muted small"><?= h($n['created_at']) ?></td>
                        <td>
                            <a href="<?= h(url('/job_card.php?action=mark_read&id=' . (int)$n['id'])) ?>"
                               style="text-decoration:none;color:inherit;">
                                <div style="font-weight:<?= $n['is_read'] ? '400' : '600' ?>;"><?= h($n['headline']) ?></div>
                                <?php if ($n['body']): ?>
                                    <div class="muted small" style="margin-top:2px;"><?= h($n['body']) ?></div>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <?php if (!$n['is_read']): ?>
                                <span class="pill pill-info" style="font-size:10px;">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Mark a single notification read + redirect to its href.
if ($action === 'mark_read') {
    $nid = (int)input('id', 0);
    $uid = (int)current_user_id();
    $n = db_one("SELECT * FROM notifications WHERE id = ? AND user_id = ?", [$nid, $uid]);
    if ($n) {
        db_exec("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?", [$nid]);
        redirect(url($n['href']));
    }
    redirect(url('/job_card.php?action=notifications'));
}

// Mark all read for current user.
if ($action === 'mark_all_read') {
    csrf_check();
    db_exec("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0",
            [(int)current_user_id()]);
    redirect(url('/job_card.php?action=notifications'));
}

// ============================================================
// LIST
// ============================================================
if ($action === 'list') {
    $dtCfg = [
        'id'       => 'job_cards',
        'base_sql' => "SELECT jc.*,
                              i.code AS item_code,
                              COALESCE(NULLIF(i.short_description, ''), i.name) AS item_name
                         FROM job_cards jc
                    LEFT JOIN inv_items i ON i.id = jc.item_id",
        'columns'  => [
            ['key'=>'jc_no',         'label'=>'JC #',        'sortable'=>true, 'searchable'=>true,  'sql_col'=>'jc.jc_no'],
            ['key'=>'status',        'label'=>'Status',      'sortable'=>true, 'sql_col'=>'jc.status',
             'filter' => ['type'=>'select', 'placeholder'=>'all', 'options'=>[
                 ['value'=>'qc_pending',      'label'=>'QC Pending'],
                 ['value'=>'prod_pending',    'label'=>'Prod Pending'],
                 ['value'=>'ats_pending',     'label'=>'ATS Pending'],
                 ['value'=>'billing_pending', 'label'=>'Billing Pending'],
                 ['value'=>'closed',          'label'=>'Closed'],
                 ['value'=>'cancelled',       'label'=>'Cancelled'],
             ]]],
            ['key'=>'current_step',  'label'=>'Next step',   'sortable'=>false, 'searchable'=>false],
            ['key'=>'po_no',         'label'=>'PO #',        'sortable'=>true, 'searchable'=>true,  'sql_col'=>'jc.po_no'],
            ['key'=>'line_no',       'label'=>'Line',        'sortable'=>true, 'searchable'=>true,  'sql_col'=>'jc.line_no'],
            ['key'=>'item',          'label'=>'Item',        'sortable'=>true, 'searchable'=>true,  'sql_col'=>"CONCAT(i.code, ' ', COALESCE(NULLIF(i.short_description, ''), i.name))"],
            ['key'=>'po_qty',        'label'=>'Ordered',     'sortable'=>true, 'sql_col'=>'jc.po_qty',         'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'sub_qty',       'label'=>'Produced',    'sortable'=>true, 'sql_col'=>'jc.sub_qty',        'th_class'=>'r', 'td_class'=>'r'],
            ['key'=>'supplier_name', 'label'=>'Customer',    'sortable'=>true, 'searchable'=>true,  'sql_col'=>'jc.supplier_name'],
            ['key'=>'delivery_date', 'label'=>'Delivery',    'sortable'=>true, 'sql_col'=>'jc.delivery_date'],
            ['key'=>'created_at',    'label'=>'Created',     'sortable'=>true, 'sql_col'=>'jc.created_at'],
            ['key'=>'_actions',      'label'=>'',            'sortable'=>false],
        ],
        'default_sort' => ['created_at', 'desc'],
    ];

    $rowRenderer = function ($r) {
        $itemLabel = $r['item_code']
            ? '(' . h($r['item_code']) . ')-' . h($r['item_name'])
            : '<span class="muted">—</span>';
        $produced = $r['sub_qty'] !== null
            ? jc_num($r['sub_qty'])
            : '<span class="muted">—</span>';
        $action = '<a class="btn btn-icon" href="' . h(url('/job_card.php?action=view&id=' . (int)$r['id']))
                . '" title="View" aria-label="View job card">👁 <span class="dt-action-label">View</span></a>';
        return [
            'jc_no'         => '<strong><a href="' . h(url('/job_card.php?action=view&id=' . (int)$r['id'])) . '">' . h($r['jc_no']) . '</a></strong>',
            'status'        => jc_status_pill($r['status']),
            'current_step'  => h(jc_current_step_label($r['status'])),
            'po_no'         => h($r['po_no']),
            'line_no'       => h($r['line_no'] ?: '—'),
            'item'          => $itemLabel,
            'po_qty'        => jc_num($r['po_qty']),
            'sub_qty'       => $produced,
            'supplier_name' => h($r['supplier_name'] ?: '—'),
            'delivery_date' => h($r['delivery_date'] ?: '—'),
            'created_at'    => h($r['created_at']),
            '_actions'      => dt_actions_wrap($action),
        ];
    };
    $dt = data_table_run($dtCfg, $rowRenderer);
    $dtCfg['title']        = 'Job cards';
    $dtCfg['description']  = 'Approval-to-ship workflow. Cards arrive from the billing system, advance through QC → Production → ATS → Billing close.';
    $dtCfg['actions_html'] = '';

    $page_title  = 'Job cards';
    $page_module = 'job_card';
    require __DIR__ . '/includes/header.php';
    data_table_render($dtCfg, $dt, $rowRenderer);
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// VIEW (read-only)
// ============================================================
if ($action === 'view') {
    $id = (int)input('id', 0);
    $jc = db_one(
        "SELECT jc.*,
                i.code AS item_code,
                COALESCE(NULLIF(i.short_description, ''), i.name) AS item_name,
                u_qc.full_name   AS qc_user_name,
                u_prod.full_name AS prod_user_name,
                u_ats.full_name  AS ats_user_name,
                parent.jc_no     AS parent_jc_no
           FROM job_cards jc
      LEFT JOIN inv_items i      ON i.id = jc.item_id
      LEFT JOIN users u_qc       ON u_qc.id = jc.qc_completed_by
      LEFT JOIN users u_prod     ON u_prod.id = jc.prod_completed_by
      LEFT JOIN users u_ats      ON u_ats.id = jc.ats_completed_by
      LEFT JOIN job_cards parent ON parent.id = jc.parent_id
          WHERE jc.id = ?",
        [$id]
    );
    if (!$jc) {
        flash_set('error', 'Job card not found.');
        redirect(url('/job_card.php'));
    }

    // Child job cards spawned from a partial split.
    $children = db_all(
        "SELECT id, jc_no, status, po_qty FROM job_cards WHERE parent_id = ? ORDER BY id",
        [$id]
    );

    // Per-box packing data (Step 3).
    $boxes = db_all(
        "SELECT * FROM job_card_boxes WHERE job_card_id = ? ORDER BY sort_order, id",
        [$id]
    );

    // Event timeline (audit).
    $events = db_all(
        "SELECT e.*, u.full_name AS actor_name
           FROM job_card_events e
      LEFT JOIN users u ON u.id = e.actor_user_id
          WHERE e.job_card_id = ?
          ORDER BY e.occurred_at DESC, e.id DESC
          LIMIT 50",
        [$id]
    );

    $linkedShipment = null;
    if (!empty($jc['shipment_id'])) {
        $linkedShipment = db_one(
            "SELECT id, ship_no, status, notes FROM inv_shipments WHERE id = ?",
            [(int)$jc['shipment_id']]
        );
    }

    $page_title  = 'Job card ' . $jc['jc_no'];
    $page_module = 'job_card';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/job_card.php'),
        'back_label' => 'Back to job cards',
        'title'      => $jc['jc_no'],
        'subtitle'   => 'Job card · ' . jc_current_step_label($jc['status']),
    ]) ?>

    <div style="padding: 0 22px; margin-top: 8px;">
        <span class="muted small" style="margin-right:8px;">Status:</span>
        <?= jc_status_pill($jc['status']) ?>
    </div>

    <?php if ($jc['status'] === 'cancelled'): ?>
    <?php
    // Cancellation reason from the most recent 'cancelled' event in the
    // audit log. Falls back to partial_reason (which can carry a billing-
    // amendment cancellation reason) then to a plain "cancelled" message.
    $cancelEvt = db_one(
        "SELECT event_data, occurred_at, actor_label
           FROM job_card_events
          WHERE job_card_id = ? AND event_type = 'cancelled'
          ORDER BY occurred_at DESC LIMIT 1",
        [(int)$jc['id']]
    );
    $cancelReason = '';
    if ($cancelEvt && !empty($cancelEvt['event_data'])) {
        $ed = @json_decode($cancelEvt['event_data'], true);
        if (is_array($ed)) {
            if (!empty($ed['reason']))           $cancelReason = $ed['reason'];
            elseif (!empty($ed['source']))       $cancelReason = 'Cancelled (' . $ed['source'] . ')';
        }
    }
    if ($cancelReason === '' && !empty($jc['partial_reason'])) $cancelReason = $jc['partial_reason'];
    ?>
    <div style="margin: 12px 22px 0; padding: 14px 18px; background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 6px;">
        <div style="font-weight: 600; color: #991b1b; margin-bottom: 4px;">⛔ This job card has been cancelled</div>
        <div style="color: #7f1d1d; font-size: 13px; line-height: 1.5;">
            No further processing is allowed on this card by anyone, including supervisors. The workflow is terminal.
            <?php if ($cancelEvt): ?>
                Cancelled on <strong><?= h($cancelEvt['occurred_at']) ?></strong><?php if (!empty($cancelEvt['actor_label'])): ?> by <strong><?= h($cancelEvt['actor_label']) ?></strong><?php endif; ?>.
            <?php endif; ?>
            <?php if ($cancelReason !== ''): ?>
                <br><span class="muted">Reason:</span> <?= h($cancelReason) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($jc['status'] === 'closed'): ?>
    <div style="margin: 12px 22px 0; padding: 14px 18px; background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 6px;">
        <div style="font-weight: 600; color: #166534; margin-bottom: 4px;">✓ This job card is closed</div>
        <div style="color: #14532d; font-size: 13px; line-height: 1.5;">
            All steps are complete; the invoice has been raised and stock has shipped out of SHP.
            No further processing is allowed.
        </div>
    </div>
    <?php endif; ?>

    <div class="jc-page">
        <style>
            .jc-page { display: flex; flex-direction: column; gap: 14px; padding: 18px 22px; }
            .jc-step {
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 8px;
                overflow: hidden;
            }
            .jc-step-head {
                display: flex; gap: 14px; align-items: center;
                padding: 12px 18px;
                background: linear-gradient(180deg, var(--surface-alt, #f9fafb), var(--surface));
                border-bottom: 1px solid var(--border);
            }
            .jc-step-num {
                flex-shrink: 0;
                width: 30px; height: 30px;
                border-radius: 50%;
                background: var(--primary); color: white;
                font-weight: 600; font-size: 14px;
                display: flex; align-items: center; justify-content: center;
            }
            .jc-step-title { margin: 0; font-size: 15px; font-weight: 600; color: var(--text); }
            .jc-step-dept  { margin-left: auto; font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
            .jc-step-body { padding: 14px 18px; }
            .jc-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 12px 24px;
            }
            .jc-grid > .jc-cell { display: flex; flex-direction: column; }
            .jc-cell-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
            .jc-cell-value { font-size: 13.5px; color: var(--text); margin-top: 2px; }
            .jc-cell-value.muted { color: var(--text-muted); font-style: italic; }
            .jc-step-pending { opacity: 0.5; }
            .jc-step-pending .jc-step-head { background: #f5f5f7; }
            /* Active = this user can edit the step right now. Bright,
               unmistakable, with a primary-tinted left edge so it's
               obvious which step the user owns. Form controls inside
               are always interactive. */
            .jc-step-active {
                box-shadow: 0 0 0 2px var(--primary, #1d4ed8) inset, 0 4px 14px rgba(29, 78, 216, 0.08);
            }
            .jc-step-active .jc-step-head {
                background: linear-gradient(180deg, #eef2ff, var(--surface));
            }
            .jc-step-active .jc-step-num { background: var(--primary, #1d4ed8); }
            /* Form-control safety: regardless of any inherited dim/disabled
               styling on the section, controls inside the QC/Prod/ATS
               forms are fully interactive. */
            .jc-step input,
            .jc-step textarea,
            .jc-step button,
            .jc-step select,
            .jc-step label {
                pointer-events: auto !important;
                opacity: 1 !important;
            }
            /* Inputs and textareas inside the step forms need real borders
               and padding — without these they look like read-only spans. */
            .jc-step input[type="text"],
            .jc-step input[type="number"],
            .jc-step textarea {
                width: 100%;
                box-sizing: border-box;
                padding: 8px 10px;
                font-size: 13px;
                border: 1px solid var(--border-strong, #d0d4dc);
                border-radius: 4px;
                background: white;
                font-family: inherit;
                line-height: 1.4;
            }
            .jc-step input[type="text"]:focus,
            .jc-step input[type="number"]:focus,
            .jc-step textarea:focus {
                outline: none;
                border-color: var(--primary, #1d4ed8);
                box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.15);
            }
            .jc-step textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
            .jc-step input[type="radio"] {
                margin-right: 4px;
                vertical-align: middle;
                cursor: pointer;
            }
            .jc-step label {
                cursor: pointer;
                user-select: none;
            }
            .jc-step-done .jc-step-num { background: var(--success, #047857); }
            .jc-mir-textarea {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 12px;
                white-space: pre-wrap;
                background: var(--surface-alt);
                padding: 10px 12px;
                border-radius: 4px;
                border: 1px solid var(--border);
                margin-top: 6px;
            }
            .jc-meta-line { font-size: 11.5px; color: var(--text-muted); margin-top: 6px; }
            .jc-meta-line strong { color: var(--text); font-weight: 500; }
            .jc-children {
                padding: 8px 14px;
                background: var(--info-bg, #dbeafe);
                border-left: 3px solid var(--info, #1d4ed8);
                margin-top: 12px;
                font-size: 12.5px;
            }
            .jc-children a { font-weight: 600; }
            .jc-events {
                margin-top: 6px;
                font-size: 12px;
            }
            .jc-events table { width: 100%; border-collapse: separate; border-spacing: 0; }
            .jc-events th, .jc-events td {
                padding: 6px 10px;
                border-bottom: 1px solid var(--border);
                text-align: left;
                vertical-align: top;
            }
            .jc-events th { background: var(--surface-alt); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
            .jc-events code { font-size: 11px; }
        </style>

        <?php if ($jc['parent_jc_no']): ?>
            <div class="jc-children">
                Child of <a href="<?= h(url('/job_card.php?action=view&id=' . (int)$jc['parent_id'])) ?>"><?= h($jc['parent_jc_no']) ?></a> · split for the balance qty after partial production. <?= h($jc['partial_reason'] ?: '') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($children)): ?>
            <div class="jc-children">
                This card was split. Children:
                <?php foreach ($children as $i => $ch): ?>
                    <?= $i > 0 ? ', ' : '' ?>
                    <a href="<?= h(url('/job_card.php?action=view&id=' . (int)$ch['id'])) ?>"><?= h($ch['jc_no']) ?></a>
                    (<?= jc_status_pill($ch['status']) ?>, <?= jc_num($ch['po_qty']) ?> units)
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- STEP 1 — Accounts / SO -->
        <section class="jc-step jc-step-done">
            <div class="jc-step-head">
                <span class="jc-step-num">1</span>
                <h3 class="jc-step-title">Sales Order</h3>
                <span class="jc-step-dept">Accounts · via API</span>
            </div>
            <div class="jc-step-body">
                <div class="jc-grid">
                    <div class="jc-cell"><span class="jc-cell-label">Part No</span>
                        <span class="jc-cell-value"><code><?= h($jc['item_code'] ?: '—') ?></code></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">Part Name</span>
                        <span class="jc-cell-value"><?= h($jc['item_name'] ?: '—') ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">PO Number</span>
                        <span class="jc-cell-value"><?= h($jc['po_no']) ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">Line No</span>
                        <span class="jc-cell-value"><?= h($jc['line_no'] ?: '—') ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">PO Quantity</span>
                        <span class="jc-cell-value"><?= jc_num($jc['po_qty']) ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">Delivery Date</span>
                        <span class="jc-cell-value"><?= h($jc['delivery_date'] ?: '—') ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">Customer</span>
                        <span class="jc-cell-value"><?= h($jc['supplier_name'] ?: '—') ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">Location</span>
                        <span class="jc-cell-value"><?= h($jc['location'] ?: '—') ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">ACK No</span>
                        <span class="jc-cell-value"><?= h($jc['ack_no'] ?: '—') ?></span></div>
                    <div class="jc-cell"><span class="jc-cell-label">Drop Shipment</span>
                        <span class="jc-cell-value"><?= $jc['ds'] ? 'Yes' : 'No' ?></span></div>
                </div>
                <div class="jc-meta-line">Created <strong><?= h($jc['created_at']) ?></strong> from SO API push</div>
            </div>
        </section>

        <!-- STEP 2 — QC / MIR -->
        <?php
        $canEditQc   = jc_can_edit_step($jc, 'qc');
        // Visual state:
        //   jc-step-done    — step is completed (qc_completed_at set)
        //   jc-step-active  — user can edit RIGHT NOW (form renders fully bright)
        //   jc-step-pending — awaiting, but this user can't act on it (greyed)
        if ($jc['qc_completed_at']) {
            $step2Status = 'jc-step-done';
        } elseif ($canEditQc) {
            $step2Status = 'jc-step-active';
        } else {
            $step2Status = 'jc-step-pending';
        }
        ?>
        <section class="jc-step <?= $step2Status ?>">
            <div class="jc-step-head">
                <span class="jc-step-num">2</span>
                <h3 class="jc-step-title">QC · Material Inspection Report</h3>
                <span class="jc-step-dept">QC Team</span>
            </div>
            <div class="jc-step-body">
                <?php if ($canEditQc): ?>
                    <form method="post" action="<?= h(url('/job_card.php?action=save_qc')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$jc['id'] ?>">
                        <div class="jc-grid">
                            <div class="jc-cell">
                                <span class="jc-cell-label">ATS Needed</span>
                                <span class="jc-cell-value">
                                    <label style="margin-right:14px;"><input type="radio" name="ats_needed" value="Yes" <?= $jc['ats_needed'] === 'Yes' ? 'checked' : '' ?>> Yes</label>
                                    <label><input type="radio" name="ats_needed" value="No" <?= $jc['ats_needed'] !== 'Yes' ? 'checked' : '' ?>> No</label>
                                </span>
                            </div>
                            <div class="jc-cell">
                                <span class="jc-cell-label">PPM</span>
                                <span class="jc-cell-value">
                                    <label style="margin-right:14px;"><input type="radio" name="ppm" value="Yes" <?= $jc['ppm'] === 'Yes' ? 'checked' : '' ?>> Yes</label>
                                    <label><input type="radio" name="ppm" value="No" <?= $jc['ppm'] !== 'Yes' ? 'checked' : '' ?>> No</label>
                                </span>
                            </div>
                            <div class="jc-cell">
                                <span class="jc-cell-label">QN</span>
                                <span class="jc-cell-value">
                                    <label style="margin-right:14px;"><input type="radio" name="qn" value="Yes" <?= $jc['qn'] === 'Yes' ? 'checked' : '' ?>> Yes</label>
                                    <label><input type="radio" name="qn" value="No" <?= $jc['qn'] !== 'Yes' ? 'checked' : '' ?>> No</label>
                                </span>
                            </div>
                            <div class="jc-cell" style="grid-column: span 3;">
                                <span class="jc-cell-label">Batch / Serial No</span>
                                <input type="text" name="batch_qc" value="<?= h($jc['batch_qc'] ?: '') ?>" placeholder="Enter batch / serial no" style="margin-top:4px;">
                            </div>
                            <div class="jc-cell" style="grid-column: span 3;">
                                <span class="jc-cell-label">MIR — Material Inspection Report</span>
                                <textarea name="mir_text" rows="4" placeholder="MIR notes — describe the inspection result, heat / batch refs, any deviations." style="margin-top:4px;"><?= h($jc['mir_text'] ?: '') ?></textarea>
                            </div>
                        </div>
                        <div style="margin-top:14px; display:flex; gap:8px; align-items:center;">
                            <button type="submit" class="btn btn-primary"><?= empty($jc['qc_completed_at']) ? '✓ Complete QC' : '💾 Update QC' ?></button>
                            <?php if (!empty($jc['qc_completed_at'])): ?>
                                <span class="jc-meta-line" style="margin:0;">First completed <strong><?= h($jc['qc_completed_at']) ?></strong> by <strong><?= h($jc['qc_user_name'] ?: 'system') ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php elseif (!$jc['qc_completed_at']): ?>
                    <p class="jc-cell-value muted">Awaiting QC.</p>
                <?php else: ?>
                    <div class="jc-grid">
                        <div class="jc-cell"><span class="jc-cell-label">ATS Needed</span>
                            <span class="jc-cell-value"><?= h($jc['ats_needed'] ?: '—') ?></span></div>
                        <div class="jc-cell"><span class="jc-cell-label">PPM</span>
                            <span class="jc-cell-value"><?= h($jc['ppm'] ?: '—') ?></span></div>
                        <div class="jc-cell"><span class="jc-cell-label">QN</span>
                            <span class="jc-cell-value"><?= h($jc['qn'] ?: '—') ?></span></div>
                        <div class="jc-cell" style="grid-column: span 3;">
                            <span class="jc-cell-label">Batch / Serial No</span>
                            <span class="jc-cell-value"><?= h($jc['batch_qc'] ?: '—') ?></span></div>
                    </div>
                    <?php if (trim((string)$jc['mir_text']) !== ''): ?>
                        <div class="jc-cell-label" style="margin-top: 12px;">MIR Notes</div>
                        <div class="jc-mir-textarea"><?= h($jc['mir_text']) ?></div>
                    <?php endif; ?>
                    <div class="jc-meta-line">Completed <strong><?= h($jc['qc_completed_at']) ?></strong> by <strong><?= h($jc['qc_user_name'] ?: 'system') ?></strong></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- STEP 3 — Production -->
        <?php
        $canEditProd = jc_can_edit_step($jc, 'prod');
        if ($jc['prod_completed_at']) {
            $step3Status = 'jc-step-done';
        } elseif ($canEditProd) {
            $step3Status = 'jc-step-active';
        } else {
            $step3Status = 'jc-step-pending';
        }
        $splitPrompt = !empty($_GET['split_prompt']);
        $splitQty    = isset($_GET['split_qty']) ? (float)$_GET['split_qty'] : (float)$jc['sub_qty'];
        ?>
        <section class="jc-step <?= $step3Status ?>">
            <div class="jc-step-head">
                <span class="jc-step-num">3</span>
                <h3 class="jc-step-title">Production & Packing</h3>
                <span class="jc-step-dept">Production Team</span>
            </div>
            <div class="jc-step-body">
                <?php if ($canEditProd): ?>
                    <form method="post" action="<?= h(url('/job_card.php?action=save_prod')) ?>" id="jc-prod-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$jc['id'] ?>">
                        <input type="hidden" name="split_confirm" id="jc-split-confirm" value="0">
                        <input type="hidden" name="split_reason"  id="jc-split-reason"  value="">
                        <div class="jc-grid">
                            <div class="jc-cell">
                                <span class="jc-cell-label">Submitted Qty (of <?= jc_num($jc['po_qty']) ?>)</span>
                                <?php
                                // Prefer the user's typed value when the modal is showing.
                                // After a partial-qty submission, the server redirects back
                                // with ?split_prompt=1&split_qty=<typed-value> so the page
                                // can render the modal AND preserve what the user typed.
                                // Without this fallback, the input would reset to po_qty
                                // and the eventual modal-confirm submit would carry the
                                // full po_qty instead of the partial value.
                                $qtyInputValue = $splitPrompt
                                    ? $splitQty
                                    : ($jc['sub_qty'] !== null && $jc['sub_qty'] !== '' ? $jc['sub_qty'] : $jc['po_qty']);
                                ?>
                                <input type="number" step="any" min="0"
                                       max="<?= h(jc_num($jc['po_qty'])) ?>"
                                       name="sub_qty"
                                       value="<?= h(jc_num($qtyInputValue)) ?>"
                                       required
                                       <?= $splitPrompt ? 'readonly' : '' ?>
                                       style="margin-top:4px;<?= $splitPrompt ? 'background:#f3f4f7;cursor:not-allowed;' : '' ?>">
                            </div>
                            <div class="jc-cell" style="grid-column: span 2;">
                                <span class="jc-cell-label">Batch / Serial No</span>
                                <input type="text" name="batch_prod" value="<?= h($jc['batch_prod'] ?: '') ?>" placeholder="Enter batch / serial no" style="margin-top:4px;">
                            </div>
                        </div>

                        <div class="jc-cell-label" style="margin-top: 18px;">Packing — one row per box</div>
                        <table class="data-table jc-box-table" style="margin-top: 6px;">
                            <thead><tr>
                                <th style="width:80px;">Box #</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th class="r" style="width:110px;">Weight (kg)</th>
                                <th class="r" style="width:110px;">Qty</th>
                                <th style="width:36px;"></th>
                            </tr></thead>
                            <tbody id="jc-box-body">
                                <?php
                                $rows = $boxes ?: [['box_no'=>'','box_type'=>'','box_size'=>'','weight_kg'=>'','qty_in_box'=>'']];
                                foreach ($rows as $b):
                                ?>
                                <tr class="jc-box-row">
                                    <td><input type="text" name="box_no[]"     value="<?= h($b['box_no'] ?? '') ?>"></td>
                                    <td><input type="text" name="box_type[]"   value="<?= h($b['box_type'] ?? '') ?>"></td>
                                    <td><input type="text" name="box_size[]"   value="<?= h($b['box_size'] ?? '') ?>"></td>
                                    <td><input type="number" step="any" min="0" name="box_weight[]" value="<?= h($b['weight_kg'] !== null ? jc_num($b['weight_kg']) : '') ?>"></td>
                                    <td><input type="number" step="any" min="0" name="box_qty[]"    value="<?= h($b['qty_in_box'] !== null ? jc_num($b['qty_in_box']) : '') ?>"></td>
                                    <td><button type="button" class="btn btn-icon btn-ghost" onclick="this.closest('tr').remove();" title="Remove row">🗑</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="jcAddBox()" style="margin-top:6px;">+ Add box</button>

                        <div style="margin-top:14px; display:flex; gap:8px; align-items:center;">
                            <button type="submit" class="btn btn-primary"><?= empty($jc['prod_completed_at']) ? '✓ Complete Production' : '💾 Update Production' ?></button>
                            <?php if (!empty($jc['prod_completed_at'])): ?>
                                <span class="jc-meta-line" style="margin:0;">First completed <strong><?= h($jc['prod_completed_at']) ?></strong> by <strong><?= h($jc['prod_user_name'] ?: 'system') ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </form>

                    <script>
                    function jcAddBox() {
                        var tbody = document.getElementById('jc-box-body');
                        var tr = document.createElement('tr');
                        tr.className = 'jc-box-row';
                        tr.innerHTML =
                            '<td><input type="text" name="box_no[]"></td>' +
                            '<td><input type="text" name="box_type[]"></td>' +
                            '<td><input type="text" name="box_size[]"></td>' +
                            '<td><input type="number" step="any" min="0" name="box_weight[]"></td>' +
                            '<td><input type="number" step="any" min="0" name="box_qty[]"></td>' +
                            '<td><button type="button" class="btn btn-icon btn-ghost" onclick="this.closest(\'tr\').remove();" title="Remove row">🗑</button></td>';
                        tbody.appendChild(tr);
                    }
                    </script>
                    <style>
                    .jc-box-table input { width: 100%; box-sizing: border-box; padding: 6px 8px; font-size: 13px; }
                    .jc-box-table td { padding: 4px 8px; }
                    </style>

                    <?php if ($splitPrompt): ?>
                    <?php
                    // Detect what flavor of partial-save this is:
                    //   - First-save partial:   child = po_qty - splitQty
                    //   - Re-edit reduction:    child = oldSubQty - splitQty
                    //   - Re-edit reduction WITH absorbable existing child:
                    //         release the delta INTO that child (no new card created)
                    $modalIsReduction = !empty($jc['prod_completed_at'])
                                        && $jc['sub_qty'] !== null
                                        && (float)$splitQty + 0.0001 < (float)$jc['sub_qty'];
                    $modalBalance = $modalIsReduction
                                    ? ((float)$jc['sub_qty'] - (float)$splitQty)
                                    : ((float)$jc['po_qty']  - (float)$splitQty);

                    // Look up the absorbable child (most recent active) so the
                    // modal can describe "release N units into JC-XXX" instead
                    // of "spin off a new card" when one exists.
                    $modalAbsorbTarget = null;
                    if ($modalIsReduction) {
                        $modalAbsorbTarget = db_one(
                            "SELECT id, jc_no, po_qty, status
                               FROM job_cards
                              WHERE parent_id = ?
                                AND status IN ('qc_pending','prod_pending')
                              ORDER BY id DESC
                              LIMIT 1",
                            [(int)$jc['id']]
                        );
                    }
                    ?>
                    <!-- Partial-split confirmation modal. Rendered when the server bounced us
                         here because the user tried to save with subQty < poQty (or, on re-edit,
                         smaller than the previously-saved sub_qty). -->
                    <div id="jc-split-modal" style="position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:9999;">
                        <div style="background:white;border-radius:8px;max-width:520px;width:92%;padding:22px;box-shadow:0 20px 60px rgba(15,23,42,.25);">
                            <h3 style="margin:0 0 6px;font-size:16px;">
                                <?php
                                if ($modalIsReduction && $modalAbsorbTarget) {
                                    echo 'Reduce submitted qty — release units into existing child?';
                                } elseif ($modalIsReduction) {
                                    echo 'Reduce submitted qty — spin off child for the difference?';
                                } else {
                                    echo 'Partial production — split into child job card?';
                                }
                                ?>
                            </h3>
                            <p class="muted small" style="margin:0 0 14px;">
                                <?php if ($modalIsReduction && $modalAbsorbTarget): ?>
                                    Reducing submitted qty from <strong><?= h(jc_num($jc['sub_qty'])) ?></strong> to
                                    <strong><?= h(jc_num($splitQty)) ?></strong>.
                                    The freed <strong><?= h(jc_num($modalBalance)) ?></strong> units will be added to existing child
                                    <strong><?= h($modalAbsorbTarget['jc_no']) ?></strong>
                                    (currently <?= h(jc_num($modalAbsorbTarget['po_qty'])) ?> →
                                    <?= h(jc_num((float)$modalAbsorbTarget['po_qty'] + $modalBalance)) ?> units).
                                    The PO qty of <strong><?= h(jc_num($jc['po_qty'])) ?></strong> stays unchanged on the parent.
                                <?php elseif ($modalIsReduction): ?>
                                    Reducing submitted qty from <strong><?= h(jc_num($jc['sub_qty'])) ?></strong> to
                                    <strong><?= h(jc_num($splitQty)) ?></strong>.
                                    The difference (<strong><?= h(jc_num($modalBalance)) ?></strong> units) will spin off into a new child job card.
                                    The PO qty of <strong><?= h(jc_num($jc['po_qty'])) ?></strong> stays unchanged on the parent.
                                <?php else: ?>
                                    You're submitting <strong><?= h(jc_num($splitQty)) ?></strong> of
                                    <strong><?= h(jc_num($jc['po_qty'])) ?></strong> ordered units.
                                    The balance (<strong><?= h(jc_num($modalBalance)) ?></strong>) will spin off a child job card.
                                <?php endif; ?>
                                <?php if (!$modalAbsorbTarget): ?>
                                    The child inherits this card's QC data and lands in production (no re-QC needed).
                                <?php endif; ?>
                            </p>
                            <label class="jc-cell-label" style="display:block;">Reason <?= $modalIsReduction ? 'for reduction' : 'for split' ?> (optional)</label>
                            <textarea id="jc-split-reason-input" rows="2"
                                      placeholder="<?= $modalIsReduction ? 'e.g. recount showed fewer good units; rejected boxes pulled out' : 'e.g. material shortage; second batch arriving tomorrow' ?>"
                                      style="width:100%;margin-top:4px;"></textarea>
                            <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">
                                <a href="<?= h(url('/job_card.php?action=view&id=' . (int)$jc['id'])) ?>" class="btn btn-ghost btn-sm">Cancel</a>
                                <button type="button" class="btn btn-primary btn-sm" onclick="jcConfirmSplit()">
                                    <?php
                                    if ($modalIsReduction && $modalAbsorbTarget) echo 'Confirm release & save';
                                    elseif ($modalIsReduction)                   echo 'Confirm reduction & save';
                                    else                                          echo 'Confirm split & save';
                                    ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <script>
                    function jcConfirmSplit() {
                        var f = document.getElementById('jc-prod-form');
                        document.getElementById('jc-split-confirm').value = '1';
                        document.getElementById('jc-split-reason').value  = document.getElementById('jc-split-reason-input').value;
                        // Force the qty input to the value the user originally typed
                        // (carried back in ?split_qty=). This is critical: if the user
                        // typed 30, the server rejected the partial submission and
                        // redirected here. The input might have been re-filled by the
                        // browser cache or rerendered with the po_qty default; either
                        // way we want the partial value to be what submits.
                        var subInput = document.querySelector('input[name="sub_qty"]');
                        if (subInput) subInput.value = '<?= jc_num($splitQty) ?>';
                        f.submit();
                    }
                    </script>
                    <?php endif; ?>

                <?php elseif (!$jc['prod_completed_at']): ?>
                    <p class="jc-cell-value muted">Awaiting production.</p>
                <?php else: ?>
                    <div class="jc-grid">
                        <div class="jc-cell"><span class="jc-cell-label">Submitted Qty</span>
                            <span class="jc-cell-value"><?= jc_num($jc['sub_qty']) ?>
                                <?php if ((float)$jc['sub_qty'] < (float)$jc['po_qty']): ?>
                                    <span class="muted">(of <?= jc_num($jc['po_qty']) ?> — partial)</span>
                                <?php endif; ?>
                            </span></div>
                        <div class="jc-cell" style="grid-column: span 2;">
                            <span class="jc-cell-label">Batch / Serial No</span>
                            <span class="jc-cell-value"><?= h($jc['batch_prod'] ?: '—') ?></span></div>
                    </div>
                    <?php if (!empty($boxes)): ?>
                        <div class="jc-cell-label" style="margin-top: 14px;">Packing</div>
                        <table class="data-table" style="margin-top: 6px;">
                            <thead><tr>
                                <th>Box #</th><th>Type</th><th>Size</th>
                                <th class="r">Weight (kg)</th><th class="r">Qty</th>
                            </tr></thead>
                            <tbody>
                            <?php $totW = 0; $totQ = 0; foreach ($boxes as $b): ?>
                                <tr>
                                    <td><?= h($b['box_no']) ?></td>
                                    <td><?= h($b['box_type'] ?: '—') ?></td>
                                    <td><?= h($b['box_size'] ?: '—') ?></td>
                                    <td class="r"><?= jc_num($b['weight_kg']) ?></td>
                                    <td class="r"><?= jc_num($b['qty_in_box']) ?></td>
                                </tr>
                                <?php $totW += (float)$b['weight_kg']; $totQ += (float)$b['qty_in_box']; ?>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr>
                                <td colspan="3" style="text-align:right; font-weight:600;">Total</td>
                                <td class="r" style="font-weight:600;"><?= jc_num($totW) ?></td>
                                <td class="r" style="font-weight:600;"><?= jc_num($totQ) ?></td>
                            </tr></tfoot>
                        </table>
                    <?php endif; ?>
                    <div class="jc-meta-line">Completed <strong><?= h($jc['prod_completed_at']) ?></strong> by <strong><?= h($jc['prod_user_name'] ?: 'system') ?></strong></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- STEP 4 — ATS -->
        <?php
        $canEditAts  = jc_can_edit_step($jc, 'ats');
        if ($jc['ats_completed_at']) {
            $step4Status = 'jc-step-done';
        } elseif ($canEditAts) {
            $step4Status = 'jc-step-active';
        } else {
            $step4Status = 'jc-step-pending';
        }
        ?>
        <section class="jc-step <?= $step4Status ?>">
            <div class="jc-step-head">
                <span class="jc-step-num">4</span>
                <h3 class="jc-step-title">ATS — Authority to Ship</h3>
                <span class="jc-step-dept">ATS Team</span>
            </div>
            <div class="jc-step-body">
                <?php if ($canEditAts): ?>
                    <?php
                    // Look up the ATS this job card already has (if any),
                    // so we can show the auto-assigned number after the
                    // first save and link through to its detail page.
                    $jcAts = ats_find_by_job_card((int)$jc['id']);
                    ?>
                    <form method="post" action="<?= h(url('/job_card.php?action=save_ats')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$jc['id'] ?>">
                        <div class="jc-grid">
                            <div class="jc-cell" style="grid-column: span 2;">
                                <span class="jc-cell-label">ATS Number</span>
                                <?php if ($jcAts): ?>
                                    <span class="jc-cell-value">
                                        <a href="<?= h(url('/ats.php?action=view&id=' . (int)$jcAts['id'])) ?>"><strong><?= h($jcAts['ats_no']) ?></strong></a>
                                        <span class="muted small">(auto-assigned)</span>
                                    </span>
                                <?php else: ?>
                                    <span class="jc-cell-value muted">
                                        Auto-assigned on Complete ATS.
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="jc-cell">
                                <span class="jc-cell-label">QC's ATS Flag</span>
                                <span class="jc-cell-value"><?= h($jc['ats_needed'] ?: '—') ?>
                                    <?php if ($jc['ats_needed'] !== 'Yes'): ?>
                                        <span class="muted small">(ATS still raised — billing tracks shipment regardless)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <p class="muted small" style="margin: 8px 0 0;">
                            <?php if (empty($jc['ats_completed_at'])): ?>
                                On first save, <strong><?= jc_num($jc['sub_qty']) ?></strong> units will move to the SHP location and an ATS number will be auto-assigned.
                            <?php else: ?>
                                Stock has already moved to SHP. The ATS number was auto-assigned on first save.
                            <?php endif; ?>
                        </p>
                        <div style="margin-top:14px; display:flex; gap:8px; align-items:center;">
                            <button type="submit" class="btn btn-primary"><?= empty($jc['ats_completed_at']) ? '✓ Complete ATS' : '💾 Update ATS' ?></button>
                            <?php if (!empty($jc['ats_completed_at'])): ?>
                                <span class="jc-meta-line" style="margin:0;">First completed <strong><?= h($jc['ats_completed_at']) ?></strong> by <strong><?= h($jc['ats_user_name'] ?: 'system') ?></strong></span>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php elseif (!$jc['ats_completed_at']): ?>
                    <p class="jc-cell-value muted">Awaiting ATS step completion. On save, produced stock moves to SHP location and an ATS number is auto-assigned.</p>
                <?php else: ?>
                    <?php $jcAts = ats_find_by_job_card((int)$jc['id']); ?>
                    <div class="jc-grid">
                        <div class="jc-cell"><span class="jc-cell-label">ATS Number</span>
                            <span class="jc-cell-value">
                                <?php if ($jcAts): ?>
                                    <a href="<?= h(url('/ats.php?action=view&id=' . (int)$jcAts['id'])) ?>"><?= h($jcAts['ats_no']) ?></a>
                                <?php else: ?>
                                    <?= h($jc['ats_no'] ?: '—') ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="jc-meta-line">Completed <strong><?= h($jc['ats_completed_at']) ?></strong> by <strong><?= h($jc['ats_user_name'] ?: 'system') ?></strong> · stock moved to SHP location</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- STEP 5 — Billing close -->
        <?php $step5Status = $jc['closed_at'] ? 'jc-step-done' : 'jc-step-pending'; ?>
        <section class="jc-step <?= $step5Status ?>">
            <div class="jc-step-head">
                <span class="jc-step-num">5</span>
                <h3 class="jc-step-title">Billing & Dispatch</h3>
                <span class="jc-step-dept">Accounts · via API</span>
            </div>
            <div class="jc-step-body">
                <?php if (!$jc['closed_at']): ?>
                    <p class="jc-cell-value muted">Awaiting invoice from billing system. On invoice receipt the goods auto-ship from SHP and this card closes.</p>
                <?php else: ?>
                    <div class="jc-grid">
                        <div class="jc-cell"><span class="jc-cell-label">Invoice No</span>
                            <span class="jc-cell-value"><?= h($jc['invoice_no'] ?: '—') ?></span></div>
                        <div class="jc-cell"><span class="jc-cell-label">Invoice Date</span>
                            <span class="jc-cell-value"><?= h($jc['invoice_date'] ?: '—') ?></span></div>
                        <div class="jc-cell"><span class="jc-cell-label">Linked Shipment</span>
                            <span class="jc-cell-value">
                                <?php if ($linkedShipment): ?>
                                    <a href="<?= h(url('/inventory_shiprcpt.php?action=view&id=' . (int)$linkedShipment['id'])) ?>">
                                        <?= h($linkedShipment['ship_no']) ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </span></div>
                    </div>
                    <div class="jc-meta-line">Closed <strong><?= h($jc['closed_at']) ?></strong> via billing API push</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Event timeline -->
        <?php if (!empty($events)): ?>
            <section class="jc-step">
                <div class="jc-step-head">
                    <span class="jc-step-num" style="background: var(--text-muted);">⏱</span>
                    <h3 class="jc-step-title">Event log</h3>
                    <span class="jc-step-dept">Most recent first</span>
                </div>
                <div class="jc-step-body jc-events">
                    <table>
                        <thead><tr>
                            <th style="width: 160px;">When</th>
                            <th style="width: 110px;">Event</th>
                            <th style="width: 140px;">Actor</th>
                            <th>Details</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($events as $e): ?>
                            <tr>
                                <td><?= h($e['occurred_at']) ?></td>
                                <td><?= h($e['event_type']) ?></td>
                                <td><?= h($e['actor_name'] ?: ($e['actor_label'] ?: 'system')) ?></td>
                                <td><?php
                                    if ($e['event_data']) {
                                        $d = json_decode($e['event_data'], true);
                                        if (is_array($d)) {
                                            $parts = [];
                                            foreach ($d as $k => $v) {
                                                if (is_scalar($v)) $parts[] = h($k) . '=' . h((string)$v);
                                            }
                                            echo '<code>' . implode(' · ', $parts) . '</code>';
                                        } else {
                                            echo '<code>' . h($e['event_data']) . '</code>';
                                        }
                                    } else echo '—';
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Unknown action — redirect to list.
redirect(url('/job_card.php'));
