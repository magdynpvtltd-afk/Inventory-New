<?php
/**
 * MagDyn — Auto-QC plumbing.
 *
 * Three concerns that span multiple modules:
 *   1. Resolve well-known location codes (LOC-QCH, ST-HLD, LOC-REJ,
 *      O-Rework, I-Rework) to IDs at runtime, with caching so a single
 *      page request doesn't repeat the lookups.
 *   2. Auto-create a draft inspection against a +qty inv_txn so it
 *      shows up in the "pending QC" list immediately after posting.
 *   3. On inspection approval, move the qty out of LOC-QCH to the
 *      verdict-appropriate destination (ST-HLD / LOC-REJ / *-Rework)
 *      idempotently — guard via inspections.qc_release_done so a
 *      double-submit can't post a duplicate move.
 *
 * Created: 2026-05-24
 */

if (!function_exists('qc_loc_id')) {
    /**
     * Resolve a location code (case-insensitive via utf8mb4_unicode_ci)
     * to its locations.id. Caches per-request in a static array so a
     * heavily-recursive caller (e.g. several receipts on one page)
     * doesn't repeat the lookup. Returns 0 when the code is absent —
     * the caller decides whether that's a hard error or a soft warning.
     */
    function qc_loc_id($code)
    {
        static $cache = [];
        $key = strtoupper(trim((string)$code));
        if ($key === '') return 0;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $id = (int)db_val(
            "SELECT id FROM locations
              WHERE code COLLATE utf8mb4_unicode_ci = ?
              LIMIT 1",
            [$key], 0
        );
        $cache[$key] = $id;
        return $id;
    }
}

if (!function_exists('qc_next_inspection_code')) {
    /**
     * Generate the next inspection code in the INSP-NNNNNN format used
     * by inspection.php's manual flow. Looks at existing rows, picks
     * max+1, and retries on the (vanishingly unlikely) clash. We
     * duplicate the logic here rather than depend on inspection.php
     * being loaded, since the Process and Ship&Receipt pages call this
     * helper without loading inspection.php.
     */
    function qc_next_inspection_code()
    {
        // If inspection.php is loaded in the same request (e.g. the
        // approve path calls back through this), use its helper so we
        // share the cache.
        if (function_exists('inspection_next_code')) {
            return inspection_next_code();
        }

        $prefix = 'INSP-';
        $pad    = 6;
        $rows = db_all(
            'SELECT code FROM inspections WHERE code LIKE ? ORDER BY id DESC LIMIT 50',
            [$prefix . '%']
        );
        $max = 0;
        foreach ($rows as $r) {
            $suffix = substr($r['code'], strlen($prefix));
            if (ctype_digit($suffix)) {
                $n = (int)$suffix;
                if ($n > $max) $max = $n;
            }
        }
        $next = $max + 1;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $prefix . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
            $clash = db_one('SELECT id FROM inspections WHERE code = ?', [$candidate]);
            if (!$clash) return $candidate;
            $next++;
        }
        // Last-resort fallback if 50 candidates all clashed.
        return $prefix . date('YmdHis');
    }
}

if (!function_exists('qc_auto_create_inspection_for_txn')) {
    /**
     * Auto-create a draft inspection linked to a +qty inv_txn so it
     * appears in the pending QC list immediately. Returns the new
     * inspection id, or 0 on no-op (txn missing / non-positive delta /
     * inspection already exists).
     *
     * Source of truth for "kind of inspection":
     *   - receive / ship_in  -> 'incoming'
     *   - process            -> 'finished_goods'
     *   - anything else      -> 'adhoc'
     *
     * Idempotent: if an inspection already exists for (inv_txn, txn_id)
     * we return its id without inserting again. Callers should still
     * wrap themselves in their own DB transaction; we don't open one
     * here so we slot cleanly into existing commit-or-rollback flows.
     */
    function qc_auto_create_inspection_for_txn($txnId)
    {
        $txnId = (int)$txnId;
        if (!$txnId) return 0;

        $txn = db_one(
            'SELECT id, txn_type, qty_delta, item_id, location_id, ref_doc
               FROM inv_txns WHERE id = ?',
            [$txnId]
        );
        if (!$txn) return 0;
        if ((float)$txn['qty_delta'] <= 0) return 0;

        // De-dupe — never auto-create a second inspection for the
        // same txn even if a caller forgets to check first.
        $existing = db_one(
            "SELECT id FROM inspections
              WHERE entity_type = 'inv_txn' AND entity_id = ? AND is_deleted = 0
              LIMIT 1",
            [$txnId]
        );
        if ($existing) return (int)$existing['id'];

        $type = $txn['txn_type'];
        $inspectionType = 'adhoc';
        if ($type === 'receive' || $type === 'ship_in') {
            $inspectionType = 'incoming';
        } elseif ($type === 'process') {
            $inspectionType = 'finished_goods';
        }

        $code = qc_next_inspection_code();
        $uid  = function_exists('current_user_id') ? (int)current_user_id() : null;

        // Look up the item's linked template so the checklist is ready
        // for the inspector without manual template selection.
        $linkedTplId = null;
        if ($txn['item_id']) {
            $tplRow = db_one(
                "SELECT t.id FROM inspection_template_targets tt
                   JOIN inspection_templates t ON t.id = tt.template_id AND t.is_active = 1
                  WHERE tt.entity_type = 'inv_item' AND tt.entity_id = ?
                  ORDER BY t.id LIMIT 1",
                [(int)$txn['item_id']]
            );
            if ($tplRow) {
                $linkedTplId = (int)$tplRow['id'];
            }
        }

        db_exec(
            'INSERT INTO inspections
               (code, inspection_type, entity_type, entity_id, template_id, status,
                verdict_notes, planned_by, planned_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $code,
                $inspectionType,
                'inv_txn',
                $txnId,
                $linkedTplId,
                'draft',
                'Auto-created on ' . $type . ' txn at LOC-QCH.',
                $uid,
            ]
        );
        $newInspId = (int)db()->lastInsertId();

        // Seed checklist rows from the linked template
        if ($linkedTplId) {
            if (function_exists('ir_seed_results_with_samples')) {
                ir_seed_results_with_samples($newInspId, $linkedTplId, 1);
            } else {
                $items = db_all(
                    'SELECT * FROM inspection_template_items
                      WHERE template_id = ? ORDER BY sort_order, id',
                    [$linkedTplId]
                );
                foreach ($items as $it) {
                    db_exec(
                        'INSERT INTO inspection_results
                           (inspection_id, sample_no, template_item_id, sort_order,
                            label, bubble_no, gdt_symbol, check_type,
                            target_value, tolerance_lower, tolerance_upper, unit,
                            instrument_asset_id, pass_fail)
                         VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$newInspId, (int)$it['id'], (int)$it['sort_order'],
                         $it['label'], $it['bubble_no'] ?? null, $it['gdt_symbol'] ?? null,
                         $it['check_type'], $it['target_value'],
                         $it['tolerance_lower'], $it['tolerance_upper'], $it['unit'],
                         $it['instrument_asset_id'] ?? null, 'pending']
                    );
                }
            }
        }

        return $newInspId;
    }
}

if (!function_exists('qc_release_for_verdict')) {
    /**
     * On inspection approval, move the qty out of LOC-QCH to the
     * verdict-appropriate destination. Idempotent via the
     * qc_release_done flag on the inspection row.
     *
     * Verdict routing:
     *   passed  -> ST-HLD   (store hold; store team routes to final shelf)
     *   failed  -> LOC-REJ
     *   rework  -> destination picked by the approver (O-Rework or I-Rework).
     *              The approver chooses via a prompt on the Rework button;
     *              the chosen code arrives as $reworkDstCode. If absent
     *              (legacy callers, scripted approvals), falls back to the
     *              prior heuristic: O-Rework for incoming sources
     *              (receive / ship_in), I-Rework for process sources.
     *   hold    -> no move (stock stays in LOC-QCH)
     *   cancelled -> no move
     *
     * Caller MUST wrap this in a DB transaction so the move and the
     * inspection-row update commit atomically. Throws on insufficient
     * stock or missing destination location.
     *
     * Returns an associative array describing what happened:
     *   ['moved' => bool, 'dst_loc_id' => int|null, 'qty' => float, 'reason' => string]
     */
    function qc_release_for_verdict($inspection, $verdict, $reworkDstCode = null)
    {
        $out = ['moved' => false, 'dst_loc_id' => null, 'qty' => 0.0, 'reason' => ''];

        // Only verdicts that imply a stock move proceed.
        $movingVerdicts = ['passed', 'failed', 'rework'];
        if (!in_array($verdict, $movingVerdicts, true)) {
            $out['reason'] = 'verdict ' . $verdict . ' does not move stock';
            return $out;
        }

        // Idempotency guard.
        if (!empty($inspection['qc_release_done'])) {
            $out['reason'] = 'already released';
            return $out;
        }

        // Only release for txn-linked inspections; standalone or
        // asset/item-linked inspections don't have a +qty txn to move.
        if (($inspection['entity_type'] ?? '') !== 'inv_txn' || !$inspection['entity_id']) {
            $out['reason'] = 'not linked to an inv_txn';
            return $out;
        }

        $txn = db_one(
            'SELECT id, txn_type, qty_delta, item_id, location_id, ref_doc
               FROM inv_txns WHERE id = ?',
            [(int)$inspection['entity_id']]
        );
        if (!$txn) {
            $out['reason'] = 'linked txn not found';
            return $out;
        }
        $qty = (float)$txn['qty_delta'];
        if ($qty <= 0) {
            $out['reason'] = 'linked txn has no positive qty';
            return $out;
        }

        $qchId = qc_loc_id('LOC-QCH');
        if (!$qchId) {
            throw new Exception('LOC-QCH location is missing — run the migration.');
        }
        // The source must be LOC-QCH for the release semantic to hold.
        // If somebody manually moved the qty elsewhere before approval,
        // we refuse rather than guess.
        if ((int)$txn['location_id'] !== $qchId) {
            $out['reason'] = 'linked txn did not land at LOC-QCH (was at #'
                . (int)$txn['location_id'] . '); no auto-release';
            return $out;
        }

        // Pick destination by verdict + source type.
        $dstCode = null;
        if ($verdict === 'passed') {
            $dstCode = 'ST-HLD';
        } elseif ($verdict === 'failed') {
            $dstCode = 'LOC-REJ';
        } else { // rework
            // If the approver explicitly picked a rework destination
            // (O-Rework / I-Rework), honor that. Otherwise fall back
            // to the source-type heuristic for legacy / scripted calls.
            $allowed = ['O-REWORK', 'I-REWORK'];
            $explicit = strtoupper(trim((string)$reworkDstCode));
            if (in_array($explicit, $allowed, true)) {
                // Normalize back to the canonical mixed-case for qc_loc_id
                // (which uppercases internally anyway, but keeps the
                // error/reason strings readable).
                $dstCode = ($explicit === 'O-REWORK') ? 'O-Rework' : 'I-Rework';
            } else {
                $dstCode = ($txn['txn_type'] === 'process') ? 'I-Rework' : 'O-Rework';
            }
        }
        $dstId = qc_loc_id($dstCode);
        if (!$dstId) {
            throw new Exception('Destination location ' . $dstCode
                . ' is missing. Create it under Locations first.');
        }

        // Move = paired txns under the existing inv_post_txn helper.
        // Out of LOC-QCH first (negative); then into dst (positive).
        // The 'move' txn_type makes these visible in the ledger as a
        // dedicated movement, not as adjustments.
        $today = date('Y-m-d');
        $refDoc = ($inspection['code'] ?? '') ? ('QC-' . $inspection['code']) : null;
        $note = sprintf(
            'QC release [%s] inspection %s', $verdict, $inspection['code'] ?? '?'
        );

        $headerOut = inv_post_txn(
            'move', $today,
            (int)$txn['item_id'], $qchId, -$qty,
            null, $refDoc, $note
        );
        inv_post_txn(
            'move', $today,
            (int)$txn['item_id'], $dstId, +$qty,
            (int)$headerOut['txn_id'], $refDoc, $note
        );

        $uid = function_exists('current_user_id') ? (int)current_user_id() : null;
        db_exec(
            'UPDATE inspections
                SET qc_release_done   = 1,
                    qc_release_loc_id = ?,
                    qc_release_at     = NOW(),
                    qc_release_by     = ?
              WHERE id = ?',
            [$dstId, $uid, (int)$inspection['id']]
        );

        $out['moved']      = true;
        $out['dst_loc_id'] = $dstId;
        $out['qty']        = $qty;
        $out['reason']     = 'released to ' . $dstCode;
        return $out;
    }
}
