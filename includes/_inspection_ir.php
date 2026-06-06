<?php
/**
 * MagDyn — Inspection IR helpers
 *
 * Utility functions used by inspection.php for the printed IR
 * (multi-sample, snapshot-style) workflow. Pulled out into its own
 * file so inspection.php stays focused on action dispatching.
 *
 * Conventions:
 *   - measured_value stays VARCHAR — accepts numeric readings AND text
 *     indicators ("OK", "NOT OK[NG]", "Values Found but Job Nos
 *     Mismatched") that the printed IR carries.
 *   - sample_no is 1-indexed; NULL means "legacy single-sample row"
 *     (won't appear on multi-sample IRs but tolerated for old data).
 *   - "Snapshot" fields (part_no/rev/desc/pid on inspections,
 *     instrument_asset_id on inspection_results) are copied AT
 *     creation time and never re-read. Live links (job_card_id) ARE
 *     re-read on every view so PO corrections flow.
 */


/**
 * Snapshot part identity from inv_items into [part_no, part_rev,
 * part_description, pid] suitable for INSERT into `inspections`.
 *
 * Falls back to nulls when the item doesn't exist (deleted between
 * picker click and save, etc.) — caller can still allow free-form
 * overrides on top of the snapshot.
 *
 * Column map (confirmed):
 *   inv_items.part_no          → inspections.part_no
 *   inv_items.part_rev_no      → inspections.part_rev
 *   inv_items.long_description → inspections.part_description
 *   inv_items.code             → inspections.pid
 */
function ir_snapshot_part_from_inv_item($invItemId)
{
    $row = db_one(
        'SELECT part_no, part_rev_no, long_description, code
           FROM inv_items WHERE id = ?',
        [(int)$invItemId]
    );
    if (!$row) {
        return ['part_no' => null, 'part_rev' => null, 'part_description' => null, 'pid' => null];
    }
    return [
        'part_no'          => $row['part_no']          ?: null,
        'part_rev'         => $row['part_rev_no']      ?: null,
        'part_description' => $row['long_description'] ?: null,
        'pid'              => $row['code']             ?: null,
    ];
}


/**
 * Live read of job_card fields used in the IR header (PO no, PO line,
 * PDN qty). Never snapshotted — if Production fixes a wrong PO, the
 * correction flows through to every linked IR.
 *
 * Returns ['po_no' => …, 'line_no' => …, 'pdn_qty' => …] or NULL if
 * the job card doesn't exist or the column is missing.
 *
 * Column map (confirmed):
 *   job_cards.po_no, job_cards.line_no, job_cards.pdn_qty
 */
function ir_job_card_header($jobCardId)
{
    if (!$jobCardId) return null;
    return db_one(
        'SELECT id, po_no, line_no, pdn_qty
           FROM job_cards WHERE id = ?',
        [(int)$jobCardId]
    );
}


/**
 * Job-card picker query. Used by the entity-picker AJAX endpoint and
 * by the IR new/edit form.
 *
 * Display label format (confirmed): code + po_no + line_no + part_no
 * — assembled in the picker UI rather than concatenated in SQL so the
 * front-end can style each segment if it wants.
 *
 * `$q` is a fuzzy term matched against code / po_no / part_no.
 */
function ir_job_card_picker($q = '', $limit = 25)
{
    $q = trim((string)$q);
    $where  = '1=1';
    $params = [];
    if ($q !== '') {
        $where  .= ' AND (jc.code LIKE ? OR jc.po_no LIKE ? OR jc.part_no LIKE ?)';
        $like    = '%' . $q . '%';
        $params  = [$like, $like, $like];
    }
    $sql = "
        SELECT jc.id, jc.code, jc.po_no, jc.line_no, jc.part_no,
               jc.pdn_qty
          FROM job_cards jc
         WHERE $where
         ORDER BY jc.id DESC
         LIMIT " . (int)$limit;
    return db_all($sql, $params);
}


/**
 * Active assets, suitable for the instrument picker. No category
 * filter — answered as "just is_active=1 against all assets". The
 * picker label is "code — name".
 */
function ir_instrument_picker($q = '', $limit = 50)
{
    $q = trim((string)$q);
    $where  = 'is_active = 1';
    $params = [];
    if ($q !== '') {
        $where  .= ' AND (code LIKE ? OR name LIKE ?)';
        $like    = '%' . $q . '%';
        $params  = [$like, $like];
    }
    return db_all(
        "SELECT id, code, name FROM assets WHERE $where ORDER BY code LIMIT " . (int)$limit,
        $params
    );
}


/**
 * Per-sample remarks codec. The remarks live in
 * inspections.sample_remarks_json as a sparse map:
 *   { "1": "Accepted", "5": "Rejected", "12": "Hold" }
 * NULL/empty means "all default" — the view layer fills in "Accepted"
 * (or whatever default) for any sample_no not in the map.
 *
 * Decode tolerates malformed JSON by returning an empty array (so a
 * corrupted column doesn't break the page).
 */
function ir_remarks_decode($json)
{
    if ($json === null || $json === '') return [];
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $k => $v) {
        $kInt = (int)$k;
        if ($kInt < 1) continue;
        $out[$kInt] = (string)$v;
    }
    return $out;
}

/**
 * Encode a remarks map for storage. Filters out empty values (so the
 * JSON stays compact and "all default = empty map = NULL in db").
 */
function ir_remarks_encode(array $map)
{
    $out = [];
    foreach ($map as $k => $v) {
        $kInt = (int)$k;
        $vStr = trim((string)$v);
        if ($kInt < 1) continue;
        if ($vStr === '') continue;
        $out[(string)$kInt] = $vStr;
    }
    if (!$out) return null;
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}


/**
 * Seed `inspection_results` from a template, expanding by sample_count.
 *
 * Replaces the legacy seed loop (one row per template_item) with
 * N rows per template_item — one per sample. Each row carries:
 *   - The same snapshot of label/bubble_no/gdt_symbol/check_type/
 *     target_value/tolerance_lower/tolerance_upper/unit as before
 *   - The new sample_no (1..N)
 *   - The new instrument_asset_id snapshotted from the template item
 *
 * Wipes existing inspection_results for the given inspection_id
 * before seeding (so re-seeding after a sample_count change is safe).
 * Called in a transaction by the caller — this fn does not start one.
 */
function ir_seed_results_with_samples($inspectionId, $templateId, $sampleCount)
{
    $inspectionId = (int)$inspectionId;
    $templateId   = (int)$templateId;
    $sampleCount  = max(1, (int)$sampleCount);

    db_exec('DELETE FROM inspection_results WHERE inspection_id = ?', [$inspectionId]);
    if (!$templateId) return;

    $items = db_all(
        'SELECT * FROM inspection_template_items
          WHERE template_id = ? ORDER BY sort_order, id',
        [$templateId]
    );
    foreach ($items as $it) {
        for ($s = 1; $s <= $sampleCount; $s++) {
            db_exec(
                'INSERT INTO inspection_results
                   (inspection_id, sample_no, template_item_id, sort_order,
                    label, bubble_no, gdt_symbol, check_type,
                    target_value, tolerance_lower, tolerance_upper, unit,
                    instrument_asset_id, pass_fail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$inspectionId, $s, (int)$it['id'], (int)$it['sort_order'],
                 $it['label'], $it['bubble_no'] ?? null, $it['gdt_symbol'] ?? null,
                 $it['check_type'], $it['target_value'],
                 $it['tolerance_lower'], $it['tolerance_upper'], $it['unit'],
                 $it['instrument_asset_id'] ?? null, 'pending']
            );
        }
    }
}


/**
 * Load results for one inspection, indexed as
 *   $grid[$templateItemId][$sampleNo] = $resultRow
 *
 * Used by the multi-sample execute UI and the IR view. Rows with NULL
 * sample_no (legacy data) bucket under sample_no=1 so they still
 * render in the first column.
 */
function ir_results_grid($inspectionId)
{
    $rows = db_all(
        'SELECT * FROM inspection_results
          WHERE inspection_id = ?
          ORDER BY sort_order, template_item_id, sample_no',
        [(int)$inspectionId]
    );
    $grid = [];
    $params = []; // parameter rows in order, dedup by template_item_id
    $seen = [];
    foreach ($rows as $r) {
        $tid = (int)$r['template_item_id'];
        $sno = (int)($r['sample_no'] ?? 1) ?: 1;
        $grid[$tid][$sno] = $r;
        if (!isset($seen[$tid])) {
            $seen[$tid] = true;
            $params[] = $r;          // sentinel row — first seen carries the param metadata
        }
    }
    return ['grid' => $grid, 'params' => $params];
}


/**
 * Compute [min, max] from a target value plus ± tolerances.
 *
 * IMPORTANT — semantics: tolerance_lower/upper are interpreted as
 * NEGATIVE / POSITIVE offsets from the target (matching the printed
 * IR's "Tol −0.8 / +0.8" convention). Min = target − lower,
 * Max = target + upper. If either tolerance is missing/non-numeric,
 * that bound is NULL (one-sided spec).
 */
function ir_min_max($target, $lower, $upper)
{
    if ($target === null || $target === '' || !is_numeric($target)) return [null, null];
    $t = (float)$target;
    $min = ($lower !== null && $lower !== '' && is_numeric($lower)) ? $t - (float)$lower : null;
    $max = ($upper !== null && $upper !== '' && is_numeric($upper)) ? $t + (float)$upper : null;
    return [$min, $max];
}


/**
 * Evaluate a measurement string vs a [min, max] spec.
 * Returns 'pass' / 'fail' / 'na'. Text indicators ("OK", "NOT OK[NG]")
 * are recognised so the IR grid colours them correctly.
 */
function ir_evaluate($value, $min, $max)
{
    if ($value === null || $value === '') return 'na';
    if (!is_numeric($value)) {
        $t = strtolower(trim((string)$value));
        if ($t === 'ok' || $t === 'pass')                             return 'pass';
        if ($t === 'fail' || strpos($t, 'not ok') === 0 || strpos($t, 'ng') !== false) return 'fail';
        return 'na';
    }
    if (($min === null || $min === '') && ($max === null || $max === '')) return 'na';
    $v = (float)$value;
    if ($min !== null && is_numeric($min) && $v < (float)$min) return 'fail';
    if ($max !== null && is_numeric($max) && $v > (float)$max) return 'fail';
    return 'pass';
}


/**
 * Return [min, max] bounds for a check type, interpreting stored fields
 * according to type semantics:
 *   NOM / LOGICAL-NOM / numeric : target ± offsets (same as ir_min_max)
 *   MIN-MAX / LOGICAL-MIN-MAX   : tolerance_lower IS min, tolerance_upper IS max
 *   everything else             : [null, null]
 */
function ir_min_max_for_type($checkType, $target, $lower, $upper)
{
    if ($checkType === 'nom' || $checkType === 'logical-nom' || $checkType === 'numeric') {
        return ir_min_max($target, $lower, $upper);
    }
    if ($checkType === 'min-max' || $checkType === 'logical-min-max') {
        $min = ($lower !== null && $lower !== '' && is_numeric($lower)) ? (float)$lower : null;
        $max = ($upper !== null && $upper !== '' && is_numeric($upper)) ? (float)$upper : null;
        return [$min, $max];
    }
    return [null, null];
}


/**
 * Whether a check type auto-computes pass/fail from the measured value.
 * Types that return false require a manual verdict (or have no verdict).
 */
function ir_auto_passfail($checkType)
{
    return in_array($checkType, ['numeric', 'logical-nom', 'logical-min-max'], true);
}


/**
 * Pretty-print a number for display (trim trailing zeros).
 */
function ir_fmt_num($v)
{
    if ($v === null || $v === '') return '';
    if (!is_numeric($v)) return (string)$v;
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
}


/**
 * Generate the IR document number (IR.NNNNN) for a freshly-created
 * inspection. Wrapped so it falls back gracefully if the code sequence
 * is missing (some installs may not have run the migration yet).
 */
function ir_next_no()
{
    try {
        return code_next('inspection_ir');
    } catch (\Throwable $e) {
        $maxId = (int)db_val(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(ir_no, 4) AS UNSIGNED)), 0)
               FROM inspections WHERE ir_no LIKE 'IR.%'",
            [], 0
        );
        return 'IR.' . ($maxId + 1);
    }
}
