<?php
/**
 * MagDyn — CMM Analyzer / Reporter helpers
 *
 * Port of the standalone CAM CMM Analyzer (src/analyzer.php + src/reporter.php).
 * Translated to:
 *   - PHP 7.0+ (no declare(strict_types), no return-type hints)
 *   - MagDyn db helpers (db_val/db_one/db_all/db_exec) where DB access is needed
 *   - Plain functions (no static class methods) for the reporter — easier to use
 *     in MagDyn's template-style PHP files.
 *
 * Analyzer functions:
 *   cmm_analyze($points, $upperTol, $lowerTol) → array  (the full analysis blob)
 *
 * Reporter functions (all return HTML strings):
 *   cmm_report_executive_summary($analysis, $meta)
 *   cmm_report_machining_assessment($analysis)
 *   cmm_report_root_causes($analysis)
 *   cmm_report_recommendations($analysis)
 *
 * Persistence helpers:
 *   cmm_insert_run($filename, $sizeBytes, $extractedVia, $meta, $upperTol,
 *                  $lowerTol, $analysis, $points, $uploadedBy)
 *   cmm_load_run($runId)            → row or null
 *   cmm_load_points($runId)         → array of float-coerced point rows
 *   cmm_set_comment($runId, $comment)
 *   cmm_list_runs($limit = 200)     → run rows for the list page
 *
 * Linkage helpers:
 *   cmm_link_to_txn($runId, $txnId, $linkedBy, $note = null)
 *   cmm_unlink_from_txn($runId, $txnId)
 *   cmm_runs_for_txn($txnId)        → CMM runs linked to a given inv_txn
 *   cmm_txns_for_run($runId)        → inv_txns this run is linked to
 *
 * Thresholds (PASS / MARGINAL / REJECT) are baked in:
 *   PASS:     OOT% ≤ 1.0 AND Cpk(U) ≥ 1.33
 *   MARGINAL: OOT% ≤ 10  AND Cpk(U) ≥ 1.0
 *   REJECT:   otherwise
 */

require_once __DIR__ . '/db.php';

// =============================================================
// ANALYZER
// =============================================================

/**
 * Run the analysis. $points is the array of probed points (each with
 * idx, tag, x_actual, x_nominal, x_dev, y_actual, y_nominal, y_dev,
 * z_actual, z_nominal, z_dev, dist_actual, dist_dev).
 * Returns the same structure the JS expects for the Plotly charts.
 */
function cmm_analyze($points, $upperTol = 0.0005, $lowerTol = -0.0005)
{
    $upperTol = (float)$upperTol;
    $lowerTol = (float)$lowerTol;
    $n = count($points);
    if ($n === 0) {
        throw new RuntimeException("No points to analyze");
    }

    $dist  = array_column($points, 'dist_actual');
    $xDev  = array_column($points, 'x_dev');
    $yDev  = array_column($points, 'y_dev');
    $zAct  = array_column($points, 'z_actual');
    $idxArr= array_column($points, 'idx');

    // Classify
    $inTol = $edge = $oot = [];
    foreach ($points as $p) {
        $d = $p['dist_actual'];
        if ($d > $upperTol)                       $oot[]  = $p;
        elseif (abs($d - $upperTol) < 1e-9)       $edge[] = $p;
        else                                      $inTol[]= $p;
    }

    $distStats = _cmm_stats($dist);
    $xStats    = _cmm_stats($xDev);
    $yStats    = _cmm_stats($yDev);

    // One-sided Cpk against the upper limit
    $sigma  = $distStats['stdev'];
    $cpkU   = $sigma > 0 ? ($upperTol - $distStats['mean']) / (3 * $sigma) : INF;

    // Quadrant breakdown
    $quads = ['Q1 (+X+Y)' => [], 'Q2 (-X+Y)' => [], 'Q3 (-X-Y)' => [], 'Q4 (+X-Y)' => []];
    foreach ($points as $p) {
        $x = $p['x_nominal']; $y = $p['y_nominal']; $d = $p['dist_actual'];
        if      ($x >= 0 && $y >= 0) $quads['Q1 (+X+Y)'][] = $d;
        elseif  ($x < 0 && $y >= 0)  $quads['Q2 (-X+Y)'][] = $d;
        elseif  ($x < 0 && $y < 0)   $quads['Q3 (-X-Y)'][] = $d;
        else                         $quads['Q4 (+X-Y)'][] = $d;
    }
    $quadStats = [];
    foreach ($quads as $k => $arr) {
        if (empty($arr)) continue;
        $cnt = count($arr);
        $ootK = 0; foreach ($arr as $d) if ($d > $upperTol) $ootK++;
        $quadStats[$k] = [
            'count'    => $cnt,
            'mean'     => array_sum($arr) / $cnt,
            'max'      => max($arr),
            'oot_count'=> $ootK,
            'oot_pct'  => $ootK / $cnt * 100,
        ];
    }

    // Contiguous OOT ranges
    $rangesOot = [];
    $start = null;
    $end = null;
    foreach ($points as $i => $p) {
        if ($p['dist_actual'] > $upperTol) {
            if ($start === null) $start = $i;
            $end = $i;
        } else {
            if ($start !== null) {
                $rangesOot[] = [$points[$start]['idx'], $points[$end]['idx'], $end - $start + 1];
                $start = null;
            }
        }
    }
    if ($start !== null) {
        $rangesOot[] = [$points[$start]['idx'], $points[$end]['idx'], $end - $start + 1];
    }
    usort($rangesOot, function ($a, $b) { return $b[2] - $a[2]; });

    // Drift linear regression
    list($slope, $intercept, $corr) = _cmm_linreg($idxArr, $dist);

    // Z constancy
    $uniqZ = array_unique(array_map(function ($z) { return round($z, 6); }, $zAct));
    $zConst = count($uniqZ) === 1;

    // Min / Max tagged points
    $minPt = $maxPt = null;
    foreach ($points as $p) {
        if (!empty($p['tag'])) {
            if      ($p['tag'] === 'Min') $minPt = $p;
            elseif  ($p['tag'] === 'Max') $maxPt = $p;
        }
    }
    if ($minPt === null) {
        $minIdx = array_search(min($dist), $dist);
        $minPt  = $points[$minIdx];
    }
    if ($maxPt === null) {
        $maxIdx = array_search(max($dist), $dist);
        $maxPt  = $points[$maxIdx];
    }

    // Verdict
    $ootPct = count($oot) / $n * 100;
    if ($ootPct <= 1.0 && $cpkU >= 1.33) {
        $verdict = 'PASS';
    } elseif ($ootPct <= 10.0 && $cpkU >= 1.0) {
        $verdict = 'MARGINAL';
    } else {
        $verdict = 'REJECT';
    }

    return [
        'N'                 => $n,
        'in_tol_count'      => count($inTol),
        'edge_count'        => count($edge),
        'oot_count'         => count($oot),
        'in_tol_pct'        => count($inTol) / $n * 100,
        'edge_pct'          => count($edge) / $n * 100,
        'oot_pct'           => $ootPct,
        'upper_tol'         => $upperTol,
        'lower_tol'         => $lowerTol,
        'dist_stats'        => $distStats,
        'x_stats'           => $xStats,
        'y_stats'           => $yStats,
        'cpk_upper'         => $cpkU,
        'quad_stats'        => $quadStats,
        'oot_ranges'        => $rangesOot,
        'drift_slope'       => $slope,
        'drift_corr'        => $corr,
        'z_constant'        => $zConst,
        'z_value'           => $zConst ? $points[0]['z_actual'] : null,
        'min_point'         => $minPt,
        'max_point'         => $maxPt,
        'verdict'           => $verdict,
        'verdict_reasons'   => _cmm_reasons($verdict, $ootPct, $cpkU, $quadStats, $xStats, $yStats),
        'recommendations'   => _cmm_recommendations($verdict, $quadStats, $xStats, $yStats),
    ];
}

function _cmm_stats($a)
{
    sort($a);
    $n = count($a);
    $sum = array_sum($a);
    $mean = $sum / $n;
    $var = 0.0; foreach ($a as $v) $var += pow($v - $mean, 2);
    $stdev = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
    $median = $n % 2 ? $a[intdiv($n, 2)] : ($a[$n/2 - 1] + $a[$n/2]) / 2;
    return [
        'min'    => $a[0],
        'max'    => $a[$n - 1],
        'mean'   => $mean,
        'stdev'  => $stdev,
        'median' => $median,
        'p95'    => $a[(int) floor(0.95 * $n)],
        'p99'    => $a[(int) floor(0.99 * $n)],
    ];
}

function _cmm_linreg($x, $y)
{
    $n = count($x);
    $sx = array_sum($x); $sy = array_sum($y);
    $sxy = 0.0; $sxx = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sxy += $x[$i] * $y[$i];
        $sxx += $x[$i] * $x[$i];
    }
    $den = ($n * $sxx - $sx * $sx);
    $slope = $den > 0 ? ($n * $sxy - $sx * $sy) / $den : 0.0;
    $intercept = ($sy - $slope * $sx) / $n;
    $mx = $sx / $n; $my = $sy / $n;
    $num = 0.0; $d1 = 0.0; $d2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $num += ($x[$i] - $mx) * ($y[$i] - $my);
        $d1  += pow($x[$i] - $mx, 2);
        $d2  += pow($y[$i] - $my, 2);
    }
    $corr = ($d1 > 0 && $d2 > 0) ? $num / sqrt($d1 * $d2) : 0.0;
    return [$slope, $intercept, $corr];
}

function _cmm_reasons($verdict, $oot, $cpk, $q, $x, $y)
{
    $r = [];
    if ($verdict !== 'PASS') {
        $r[] = sprintf("%.1f%% of points exceed the upper tolerance.", $oot);
        $r[] = sprintf("Cpk(upper) = %.2f — below the 1.33 process-capability target.", $cpk);
    }
    $worst = null;
    foreach ($q as $k => $s) {
        if ($worst === null || $s['oot_pct'] > $q[$worst]['oot_pct']) $worst = $k;
    }
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        $r[] = sprintf("Defects cluster in %s (%.1f%% OOT there) — likely a localised setup or process defect rather than a global process drift.",
            $worst, $q[$worst]['oot_pct']);
    }
    if (abs($x['mean']) > 0.0001 || abs($y['mean']) > 0.0001) {
        $r[] = sprintf("Systematic axis bias: mean ΔX = %+.4f, mean ΔY = %+.4f — fingerprint of a work-coordinate / origin offset.",
            $x['mean'], $y['mean']);
    }
    return $r;
}

function _cmm_recommendations($verdict, $q, $x, $y)
{
    if ($verdict === 'PASS') {
        return [
            "Process is within spec. Continue current setup.",
            "Maintain SPC monitoring at the previously identified worst-case point.",
        ];
    }
    $recs = [
        "Hold the part; flag for engineering review before shipping.",
        "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
        "Re-measure the finish-pass cutter on the tool presetter and update the offset table.",
        "Re-touch the part datum with a calibrated probe; verify the active G54 / work coordinate matches the print datum scheme.",
    ];
    $worst = null;
    foreach ($q as $k => $s) {
        if ($worst === null || $s['oot_pct'] > $q[$worst]['oot_pct']) $worst = $k;
    }
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        $recs[] = "Inspect the NC program in the angular region corresponding to {$worst} for differing feedrate, cutter-comp toggles, or a separate finish pass.";
    }
    $recs[] = "Run a prove-out part with corrections applied; re-CMM and confirm Cpk(U) ≥ 1.33 before releasing.";
    return $recs;
}

// =============================================================
// REPORTER (HTML-emitting prose blocks)
//
// All three machine-specific reporters dispatch through a registry. The
// math in cmm_analyze() is identical across machine types — only the
// human-facing language changes. To add a new machine type:
//   1. Add its key + label to cmm_machine_types()
//   2. Add an ENUM value in a new migration
//   3. Add three reporter functions: _cmm_machining_<key>, _cmm_causes_<key>,
//      _cmm_recommendations_<key> (signature matches the existing 'vmc'
//      versions). 'other' is the generic fallback.
// =============================================================

/**
 * Registry of supported machine types. Keys match the ENUM in cmm_runs.
 * Used by the upload form, view page, and reporter dispatch.
 */
function cmm_machine_types()
{
    return [
        'vmc'      => 'Vertical Machining Center (VMC)',
        'wedm'     => 'Wire EDM',
        'manual'   => 'Manual machining',
        'grinding' => 'Grinding',
        'other'    => 'Other / generic',
    ];
}

function cmm_machine_label($key)
{
    $types = cmm_machine_types();
    return isset($types[$key]) ? $types[$key] : $types['other'];
}

function cmm_report_executive_summary($a, $meta)
{
    $maxDevUm = number_format($a['dist_stats']['max'] * 25.4 * 1000, 1);
    $maxIdx   = $a['max_point']['idx'];
    $verdict  = $a['verdict'];
    if      ($verdict === 'PASS')     $vt = '<span class="pill pill-success">PASS — meets print.</span>';
    elseif  ($verdict === 'MARGINAL') $vt = '<span class="pill pill-warning">MARGINAL — defects present but bounded.</span>';
    else                              $vt = '<span class="pill pill-danger">REJECT — does not meet print.</span>';

    $body = sprintf(
        '<p>%s Of the <b>%d</b> probed points on the cam profile, '.
        '<b>%d (%.1f%%)</b> exceed the upper tolerance of %.4f, and a further '.
        '<b>%d (%.1f%%)</b> sit exactly on the limit. Only <b>%d points (%.1f%%)</b> '.
        'lie strictly inside the band. Maximum deviation is <b>+%.4f (≈ %s µm)</b> '.
        'at point %d.</p>',
        $vt,
        $a['N'],
        $a['oot_count'], $a['oot_pct'], $a['upper_tol'],
        $a['edge_count'], $a['edge_pct'],
        $a['in_tol_count'], $a['in_tol_pct'],
        $a['dist_stats']['max'], $maxDevUm, $maxIdx
    );

    if (!empty($a['verdict_reasons'])) {
        $body .= '<ul>';
        foreach ($a['verdict_reasons'] as $r) {
            $body .= '<li>' . h($r) . '</li>';
        }
        $body .= '</ul>';
    }
    return $body;
}

function _cmm_normalize_machine_type($machineType)
{
    // Defensive: trim + lowercase. Stored as ENUM in DB so this is mostly
    // belt-and-braces, but `null`, `'VMC'`, `' wedm '`, etc. have been
    // observed slipping through some paths.
    $mt = strtolower(trim((string)$machineType));
    $types = cmm_machine_types();
    if (isset($types[$mt])) return $mt;

    // Some clients send the human-readable label ('Wire EDM') instead of
    // the key ('wedm') — happens when an <option> tag was rendered without
    // a value="" attribute and the browser used the text content. Match by
    // label, case-insensitively.
    $rawTrim = trim((string)$machineType);
    foreach ($types as $k => $label) {
        if (strcasecmp($label, $rawTrim) === 0) return $k;
    }
    // Also match by leading word ('Wire' → 'wedm', 'Manual' → 'manual')
    foreach ($types as $k => $label) {
        $first = strtolower(strtok($label, ' '));
        if ($first === $mt) return $k;
    }
    return 'vmc';
}

function cmm_report_machining_assessment($a, $machineType = 'vmc', $isMultipass = false)
{
    $machineType = _cmm_normalize_machine_type($machineType);
    $isMultipass = (bool)$isMultipass;
    switch ($machineType) {
        case 'wedm':     return _cmm_machining_wedm($a, $isMultipass);
        case 'manual':   return _cmm_machining_manual($a);
        case 'grinding': return _cmm_machining_grinding($a);
        case 'other':    return _cmm_machining_other($a);
        case 'vmc':
        default:         return _cmm_machining_vmc($a);
    }
}

function cmm_report_root_causes($a, $machineType = 'vmc', $isMultipass = false)
{
    $machineType = _cmm_normalize_machine_type($machineType);
    $isMultipass = (bool)$isMultipass;
    switch ($machineType) {
        case 'wedm':     $causes = _cmm_causes_wedm($a, $isMultipass); break;
        case 'manual':   $causes = _cmm_causes_manual($a); break;
        case 'grinding': $causes = _cmm_causes_grinding($a); break;
        case 'other':    $causes = _cmm_causes_other($a); break;
        case 'vmc':
        default:         $causes = _cmm_causes_vmc($a); break;
    }
    // Always tack on the universal CMM-false-negative check at the end.
    $causes[] = [
        'title' => 'CMM probe / fixture error (false negative)',
        'body'  => 'Before committing to scrap and rework, confirm: the CMM probe was qualified before this scan; the part was clamped on the CMM with the same datums the print calls out; the part hadn\'t deflected against a hard clamp.',
    ];
    return _cmm_render_causes($causes);
}

function cmm_report_recommendations($a, $machineType = 'vmc', $isMultipass = false)
{
    $machineType = _cmm_normalize_machine_type($machineType);
    $isMultipass = (bool)$isMultipass;
    // Recommendations are computed at render time so the same analyze
    // blob (which doesn't carry machine_type) can be re-rendered against
    // any machine. The analyze pass still pre-builds $a['recommendations']
    // for the default VMC view; if the caller passes a different type,
    // we recompute.
    if ($machineType === 'vmc' && !empty($a['recommendations'])) {
        $recs = $a['recommendations'];
    } else {
        $recs = _cmm_recommendations_for(
            $machineType,
            $a['verdict'],
            $a['quad_stats'],
            $a['x_stats'],
            $a['y_stats'],
            $isMultipass
        );
    }
    $html = '<ol>';
    foreach ($recs as $r) {
        $html .= '<li>' . h($r) . '</li>';
    }
    $html .= '</ol>';
    return $html;
}

function _cmm_render_causes($causes)
{
    $html = '';
    foreach ($causes as $i => $c) {
        $html .= '<div style="margin: 12px 0; padding: 10px 14px; background: #f9fafb; border-left: 3px solid var(--border);">';
        $html .= '<h4 style="margin: 0 0 6px;">' . ($i + 1) . '. ' . $c['title'] . '</h4>';
        $html .= '<p style="margin: 0;">' . $c['body'] . '</p>';
        $html .= '</div>';
    }
    return $html;
}

// =============================================================
// VMC — original prose, untouched (the default machine type)
// =============================================================

function _cmm_machining_vmc($a)
{
    $html = '<h3 style="margin-top:0;">What the VMC did well</h3><ul>';
    $positives = [];
    if ($a['z_constant']) {
        $positives[] = 'Z-axis depth control is excellent — every probed point reports a Z deviation of exactly 0.0000 (single-plane scan confirms tight depth control).';
    }
    if ($a['dist_stats']['stdev'] < 0.0003) {
        $positives[] = sprintf('Standard deviation (scatter) is tight: σ = %.4f. The machine is repeating itself consistently — argues against chatter, hard-spot variation, tool-edge chipping, or spindle runout.', $a['dist_stats']['stdev']);
    }
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    $bestQ  = _cmm_best_quad($a['quad_stats']);
    if ($bestQ && $a['quad_stats'][$bestQ]['oot_pct'] < 5) {
        $positives[] = sprintf('Three quadrants out of four behave well or near-passing. %s in particular is %.1f%% OOT — close to passing — suggesting the profile geometry itself is correct and the CAM toolpath is fundamentally sound.',
            $bestQ, $a['quad_stats'][$bestQ]['oot_pct']);
    }
    if (abs($a['drift_corr']) < 0.7) {
        $positives[] = sprintf('No strong tool-wear signature: index-vs-deviation correlation is only %.2f. A worn cutter would produce a steadily-climbing error over the run — that is not the dominant effect here.',
            $a['drift_corr']);
    }
    if (empty($positives)) $positives[] = '(Nothing flagged on the positive side.)';
    foreach ($positives as $p) $html .= '<li>' . $p . '</li>';
    $html .= '</ul>';

    $html .= '<h3>Where the VMC fell short</h3><ul>';
    $negatives = [];
    if ($a['oot_pct'] > 1) {
        $negatives[] = sprintf('%.1f%% out-of-tolerance — well above the 1%% threshold expected of a controlled process.', $a['oot_pct']);
    }
    if ($a['cpk_upper'] < 1.33) {
        $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark. The distribution mean sits too close to the upper specification limit.', $a['cpk_upper']);
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 50) {
        $negatives[] = sprintf('Severe %s defect: %.1f%% of points OOT in that quadrant, with peak deviation +%.4f. Continuous OOT zones suggest a single setup / programming defect rather than intermittent vibration.',
            $worstQ, $a['quad_stats'][$worstQ]['oot_pct'], $a['quad_stats'][$worstQ]['max']);
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $negatives[] = sprintf('Systematic XY origin shift: mean ΔX = %+.4f, mean ΔY = %+.4f — textbook fingerprint of WCS/G54 misalignment or cutter-radius compensation error.',
            $a['x_stats']['mean'], $a['y_stats']['mean']);
    }
    if (!empty($a['oot_ranges'])) {
        $r = $a['oot_ranges'][0];
        if ($r[2] > 50) {
            $negatives[] = sprintf('Longest contiguous OOT band: indices %d – %d, %d consecutive points. Localised toolpath / cutter-comp artefact, not random scatter.',
                $r[0], $r[1], $r[2]);
        }
    }
    if (empty($negatives)) $negatives[] = '(No machining issues flagged.)';
    foreach ($negatives as $n) $html .= '<li>' . $n . '</li>';
    $html .= '</ul>';
    return $html;
}

function _cmm_causes_vmc($a)
{
    $causes = [];
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 30) {
        $causes[] = [
            'title' => 'Cutter-radius compensation error — most likely',
            'body'  => 'An assumed cutter radius slightly smaller than the true (worn) cutter would leave extra stock outside the nominal in regions of positive curvature (lobe tips), matching the observed clustering of error at curvature extremes. A discrepancy of just 0.0002–0.0003 in radius accounts for the observed magnitudes. <b>Check:</b> remeasure the cutter on the tool presetter (or with a tool-setter probe inside the machine) before re-running.',
        ];
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $dir = '';
        if      ($a['x_stats']['mean'] > 0) $dir .= '+X';
        elseif  ($a['x_stats']['mean'] < 0) $dir .= '-X';
        if      ($a['y_stats']['mean'] > 0) $dir .= '/+Y';
        elseif  ($a['y_stats']['mean'] < 0) $dir .= '/-Y';
        $causes[] = [
            'title' => 'Work-coordinate-system / fixture offset shift',
            'body'  => sprintf('The constant %s bias (ΔX = %+.4f, ΔY = %+.4f) is consistent with a touch-off stylus calibration that drifted, a probe with a slight ball-radius mis-entry, or simply the part clamped a fraction off its theoretical zero. <b>Check:</b> re-touch the part with a calibrated probe; verify the macro that sets G54 reads the current probe-ball compensation table, not a stale one.',
                $dir, $a['x_stats']['mean'], $a['y_stats']['mean']),
        ];
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 80) {
        $causes[] = [
            'title' => 'Localised programming or feedrate issue at one sweep',
            'body'  => sprintf('Almost all of %s fails (where other quadrants pass), hinting at a single toolpath that passes through that region once. <b>Check:</b> open the NC file and inspect the toolpath ordering across the lobe.', $worstQ),
        ];
    }
    if ($a['drift_corr'] > 0.4 && $a['drift_corr'] < 0.9) {
        $causes[] = [
            'title' => 'Thermal growth of spindle / table during the cut',
            'body'  => sprintf('A slow positive drift exists (correlation r = %.2f). The machine could have grown approximately 0.2 thou between start and end of the cut. Thermal growth is at best a secondary contributor here.', $a['drift_corr']),
        ];
    }
    return $causes;
}

// =============================================================
// WEDM — Wire EDM
// =============================================================

function _cmm_machining_wedm($a, $isMultipass = false)
{
    $passLabel = $isMultipass ? 'multi-pass wire EDM' : 'wire EDM';
    $html = '<h3 style="margin-top:0;">What the ' . $passLabel . ' did well</h3><ul>';
    $positives = [];
    if ($a['z_constant']) {
        $positives[] = 'Z-axis flatness is excellent — every probed point reports a Z deviation of exactly 0.0000. The upper and lower guides are tracking together; no detectable taper introduced by the wire path.';
    }
    if ($a['dist_stats']['stdev'] < 0.0003) {
        if ($isMultipass) {
            $positives[] = sprintf('Standard deviation (scatter) is tight: σ = %.4f. The skim passes are producing a consistent finish — argues against erratic dielectric flow on the finishing passes, intermittent wire breakage, or inconsistent skim energy settings.', $a['dist_stats']['stdev']);
        } else {
            $positives[] = sprintf('Standard deviation (scatter) is tight: σ = %.4f. The discharge process is repeating consistently from point to point — argues against erratic dielectric flow, intermittent wire breakage, or inconsistent flushing pressure.', $a['dist_stats']['stdev']);
        }
    }
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    $bestQ  = _cmm_best_quad($a['quad_stats']);
    if ($bestQ && $a['quad_stats'][$bestQ]['oot_pct'] < 5) {
        $positives[] = sprintf('Three quadrants out of four behave well or near-passing. %s in particular is %.1f%% OOT — close to passing — suggesting the programmed wire path is geometrically correct and the cut sequence is sound.',
            $bestQ, $a['quad_stats'][$bestQ]['oot_pct']);
    }
    if (abs($a['drift_corr']) < 0.7) {
        $positives[] = sprintf('No strong wire-wear or guide-wear signature: index-vs-deviation correlation is only %.2f. Progressive guide wear or wire diameter drift would produce a steadily-climbing error over the run — that is not the dominant effect here.',
            $a['drift_corr']);
    }
    if (empty($positives)) $positives[] = '(Nothing flagged on the positive side.)';
    foreach ($positives as $p) $html .= '<li>' . $p . '</li>';
    $html .= '</ul>';

    $html .= '<h3>Where the ' . $passLabel . ' fell short</h3><ul>';
    $negatives = [];
    if ($a['oot_pct'] > 1) {
        $negatives[] = sprintf('%.1f%% out-of-tolerance — well above the 1%% threshold expected of a controlled EDM process.', $a['oot_pct']);
    }
    if ($a['cpk_upper'] < 1.33) {
        if ($isMultipass) {
            // Tight scatter + high mean on a multi-pass cut is a classic
            // "skim passes did not fully reach finish size" pattern.
            if ($a['dist_stats']['stdev'] < 0.0003) {
                $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark, despite tight scatter (σ = %.4f). On a multi-pass cut, this signature — narrow distribution sitting too high — typically means the skim passes were not aggressive enough to fully reach the programmed finish dimension. The cut is consistent but oversized; adding another skim pass or widening overlap should bring the mean back inside the band.',
                    $a['cpk_upper'], $a['dist_stats']['stdev']);
            } else {
                $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark. The distribution mean sits too close to the upper specification limit — on a multi-pass cut this points at a final-pass offset error or insufficient skim aggressiveness.', $a['cpk_upper']);
            }
        } else {
            $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark. The distribution mean sits too close to the upper specification limit — likely an offset / wire-comp issue rather than scatter.', $a['cpk_upper']);
        }
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 50) {
        if ($isMultipass) {
            $negatives[] = sprintf('Severe %s defect: %.1f%% of points OOT in that quadrant, with peak deviation +%.4f. On a multi-pass cut, a single-quadrant failure usually means the skim pass(es) did not catch up across that arc — corner-strategy parameters on the finishing pass, insufficient overlap, or the pass being skipped through that region — rather than random discharge variation.',
                $worstQ, $a['quad_stats'][$worstQ]['oot_pct'], $a['quad_stats'][$worstQ]['max']);
        } else {
            $negatives[] = sprintf('Severe %s defect: %.1f%% of points OOT in that quadrant, with peak deviation +%.4f. Continuous OOT zones in a single region point at corner washout, lag, or a missed skim pass over that arc — not random discharge variation.',
                $worstQ, $a['quad_stats'][$worstQ]['oot_pct'], $a['quad_stats'][$worstQ]['max']);
        }
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        if ($isMultipass) {
            $negatives[] = sprintf('Systematic XY origin shift: mean ΔX = %+.4f, mean ΔY = %+.4f — on a multi-pass cut, check the offset value applied to the FINAL skim pass specifically (the rough offset and skim offsets are usually separate parameters in the control), not just the part-zero touch-off.',
                $a['x_stats']['mean'], $a['y_stats']['mean']);
        } else {
            $negatives[] = sprintf('Systematic XY origin shift: mean ΔX = %+.4f, mean ΔY = %+.4f — fingerprint of an incorrect wire-radius offset, a stale wire-diameter entry, or part-zero touch-off drift.',
                $a['x_stats']['mean'], $a['y_stats']['mean']);
        }
    }
    if (!empty($a['oot_ranges'])) {
        $r = $a['oot_ranges'][0];
        if ($r[2] > 50) {
            $negatives[] = sprintf('Longest contiguous OOT band: indices %d – %d, %d consecutive points. Localised wire-path / corner-washout artefact, not random scatter.',
                $r[0], $r[1], $r[2]);
        }
    }
    if (empty($negatives)) $negatives[] = '(No EDM issues flagged.)';
    foreach ($negatives as $n) $html .= '<li>' . $n . '</li>';
    $html .= '</ul>';
    return $html;
}

function _cmm_causes_wedm($a, $isMultipass = false)
{
    $causes = [];
    $worstQ = _cmm_worst_quad($a['quad_stats']);

    // For multi-pass cuts with tight scatter but high mean, the single most
    // likely cause is "skim passes didn't fully reach finish dim", which
    // beats the wire-radius-offset story in likelihood. Lead with it.
    if ($isMultipass
        && $a['dist_stats']['stdev'] < 0.0003
        && $a['cpk_upper'] < 1.33) {
        $causes[] = [
            'title' => 'Insufficient skim passes / under-aggressive finishing — most likely',
            'body'  => 'Tight scatter (σ low) combined with a high mean is the signature of a multi-pass cut where the skim passes did not bring the part all the way to programmed finish size. The cut is consistent but the distribution is offset toward the upper limit. <b>Check:</b> count the skim passes actually used vs. what the program calls for; verify each pass\'s energy / spark-gap parameters; confirm the overlap between passes is sufficient; consider adding one more skim pass with reduced energy. A typical fix is bumping a 1-rough-2-skim sequence to 1-rough-3-skim, or widening the final skim offset by 0.0002–0.0003.',
        ];
    }

    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 30) {
        if ($isMultipass) {
            $causes[] = [
                'title' => 'Final-pass wire-radius offset error',
                'body'  => 'An assumed wire diameter slightly smaller than the true wire (after wear or with a different spool than programmed) would leave extra stock outside the nominal in regions of positive curvature (lobe tips), matching the observed clustering of error at curvature extremes. A discrepancy of just 0.0002–0.0003 in radius accounts for the observed magnitudes. On a multi-pass cut, the rough offset and each skim offset are usually separate parameters — verify the FINAL skim pass\'s offset specifically. <b>Check:</b> measure the wire on a calibrated micrometer; verify the wire-radius compensation for every pass in the control matches the spool actually mounted; confirm the spark gap added to the wire radius is current for the energy settings of the finishing pass specifically.',
            ];
        } else {
            $causes[] = [
                'title' => 'Wire-radius offset / wire-diameter entry error — most likely',
                'body'  => 'An assumed wire diameter slightly smaller than the true wire (after wear or with a different spool than programmed) would leave extra stock outside the nominal in regions of positive curvature (lobe tips), matching the observed clustering of error at curvature extremes. A discrepancy of just 0.0002–0.0003 in radius accounts for the observed magnitudes. <b>Check:</b> measure the wire on a calibrated micrometer; verify the wire-radius compensation value in the control matches the spool actually mounted; confirm the spark gap added to the wire radius is current for the energy settings used.',
            ];
        }
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $dir = '';
        if      ($a['x_stats']['mean'] > 0) $dir .= '+X';
        elseif  ($a['x_stats']['mean'] < 0) $dir .= '-X';
        if      ($a['y_stats']['mean'] > 0) $dir .= '/+Y';
        elseif  ($a['y_stats']['mean'] < 0) $dir .= '/-Y';
        $causes[] = [
            'title' => 'Work-coordinate / part-zero shift',
            'body'  => sprintf('The constant %s bias (ΔX = %+.4f, ΔY = %+.4f) is consistent with a part-zero touch-off that drifted from the print datum, an edge-find routine that ran with the wrong wire offset loaded, or the part clamped a fraction off its theoretical zero. <b>Check:</b> re-edge-find the part with the current wire installed; verify the part-zero macro reads the current wire-comp table; confirm the datum scheme on the CMM matches the EDM setup.',
                $dir, $a['x_stats']['mean'], $a['y_stats']['mean']),
        ];
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 80) {
        if ($isMultipass) {
            $causes[] = [
                'title' => sprintf('Skim pass did not catch up across %s', $worstQ),
                'body'  => sprintf('Almost all of %s fails (where other quadrants pass), in a pattern that strongly suggests one or more skim passes did not reach the programmed finish on that arc. Common causes on multi-pass cuts: a corner-strategy parameter that applied only on certain quadrants, a wire-path that started/ended in that region so the skim overlap is thin, or insufficient corner-control settings. <b>Check:</b> review the program path for the skim passes specifically through that region; verify corner-control settings; consider routing the skim passes to start and end well away from that arc; add an extra finishing pass over the failing region.', $worstQ),
            ];
        } else {
            $causes[] = [
                'title' => 'Corner washout / wire lag on one sweep',
                'body'  => sprintf('Almost all of %s fails (where other quadrants pass), hinting at a region where the wire path turns sharply or the wire trails (lags) the head significantly. The skim passes may not have caught up across that arc, or a corner-strategy parameter was missing. <b>Check:</b> review the program for the wire path through that region; verify the number of skim passes and corner-control settings; consider adding an extra finishing pass over the failing arc.', $worstQ),
            ];
        }
    }
    if ($a['drift_corr'] > 0.4 && $a['drift_corr'] < 0.9) {
        if ($isMultipass) {
            $causes[] = [
                'title' => 'Wire-diameter drift between rough and finishing passes',
                'body'  => sprintf('A slow positive drift exists (correlation r = %.2f). On a multi-pass cut, the wire used for finishing passes is later in the spool than the wire used for the rough — if the spool diameter is non-uniform, the wire-radius compensation that was correct for the rough pass is now slightly wrong for the finish. Guide wear during the long combined cut time is also a contributor. <b>Check:</b> measure the wire diameter at multiple points along the spool (especially at the section used for finishing); inspect the upper and lower diamond guides under magnification; consider a fresh spool for high-precision finishing.', $a['drift_corr']),
            ];
        } else {
            $causes[] = [
                'title' => 'Progressive guide wear or wire-diameter drift during the cut',
                'body'  => sprintf('A slow positive drift exists (correlation r = %.2f). Guide wear (especially in the upper/lower diamond guides) or a spool with non-uniform diameter could produce a steadily-shifting kerf over the cut. <b>Check:</b> inspect the upper and lower guides under magnification; rotate to fresh guide positions if they have wear scars; measure the wire diameter at multiple points along the spool.', $a['drift_corr']),
            ];
        }
    }
    return $causes;
}

// =============================================================
// MANUAL MACHINING (lathe, mill, drill operated by hand)
// =============================================================

function _cmm_machining_manual($a)
{
    $html = '<h3 style="margin-top:0;">What the operator did well</h3><ul>';
    $positives = [];
    if ($a['z_constant']) {
        $positives[] = 'Z-axis depth control is consistent — every probed point reports a Z deviation of exactly 0.0000. The operator held depth precisely across the part.';
    }
    if ($a['dist_stats']['stdev'] < 0.0003) {
        $positives[] = sprintf('Standard deviation (scatter) is tight: σ = %.4f. The operator is hand-feeding consistently — argues against chatter, indicator wander, or inconsistent feel on the handwheels.', $a['dist_stats']['stdev']);
    }
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    $bestQ  = _cmm_best_quad($a['quad_stats']);
    if ($bestQ && $a['quad_stats'][$bestQ]['oot_pct'] < 5) {
        $positives[] = sprintf('Three quadrants out of four behave well or near-passing. %s in particular is %.1f%% OOT — close to passing — suggesting the setup is fundamentally sound; the problem is localised to one region.',
            $bestQ, $a['quad_stats'][$bestQ]['oot_pct']);
    }
    if (abs($a['drift_corr']) < 0.7) {
        $positives[] = sprintf('No strong drift signature: index-vs-deviation correlation is only %.2f. The operator is not getting progressively tired or losing reference during the run.',
            $a['drift_corr']);
    }
    if (empty($positives)) $positives[] = '(Nothing flagged on the positive side.)';
    foreach ($positives as $p) $html .= '<li>' . $p . '</li>';
    $html .= '</ul>';

    $html .= '<h3>Where the operator fell short</h3><ul>';
    $negatives = [];
    if ($a['oot_pct'] > 1) {
        $negatives[] = sprintf('%.1f%% out-of-tolerance — well above the 1%% threshold expected of a controlled process.', $a['oot_pct']);
    }
    if ($a['cpk_upper'] < 1.33) {
        $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark. The achieved size sits too close to the upper limit — likely a touch-off / indicator-zero issue rather than scatter.', $a['cpk_upper']);
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 50) {
        $negatives[] = sprintf('Severe %s defect: %.1f%% of points OOT in that quadrant, with peak deviation +%.4f. Continuous OOT zones suggest the indicator was bumped, the part was repositioned, or one setup step was skipped on that side.',
            $worstQ, $a['quad_stats'][$worstQ]['oot_pct'], $a['quad_stats'][$worstQ]['max']);
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $negatives[] = sprintf('Systematic XY origin shift: mean ΔX = %+.4f, mean ΔY = %+.4f — fingerprint of an indicator zeroed against the wrong reference, a micrometer that needs calibration, or a parallax error on a dial reading.',
            $a['x_stats']['mean'], $a['y_stats']['mean']);
    }
    if (empty($negatives)) $negatives[] = '(No machining issues flagged.)';
    foreach ($negatives as $n) $html .= '<li>' . $n . '</li>';
    $html .= '</ul>';
    return $html;
}

function _cmm_causes_manual($a)
{
    $causes = [];
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 30) {
        $causes[] = [
            'title' => 'Indicator zero / measurement reference error — most likely',
            'body'  => 'A small error in the zero reference (indicator pre-loaded against the wrong gauge block, micrometer not calibrated, or a parallax error reading the dial) would shift every measurement on one side of the part. <b>Check:</b> re-zero the indicator against a calibrated gauge block; verify the micrometer or DTI used to set size is in calibration; have a second operator verify a few key measurements independently.',
        ];
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $dir = '';
        if      ($a['x_stats']['mean'] > 0) $dir .= '+X';
        elseif  ($a['x_stats']['mean'] < 0) $dir .= '-X';
        if      ($a['y_stats']['mean'] > 0) $dir .= '/+Y';
        elseif  ($a['y_stats']['mean'] < 0) $dir .= '/-Y';
        $causes[] = [
            'title' => 'Touch-off / part-zero shift',
            'body'  => sprintf('The constant %s bias (ΔX = %+.4f, ΔY = %+.4f) is consistent with the operator touching off against the wrong edge, using a different edge-finder than usual, or the part not being properly seated against the parallels. <b>Check:</b> repeat the touch-off procedure with a second operator observing; verify the parallels are clean and the part is fully against them.',
                $dir, $a['x_stats']['mean'], $a['y_stats']['mean']),
        ];
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 80) {
        $causes[] = [
            'title' => 'Setup discontinuity at one face',
            'body'  => sprintf('Almost all of %s fails (where other quadrants pass), hinting at a setup change part-way through — the part was unclamped and reclamped, the indicator was bumped, or a separate roughing-then-finishing strategy was skipped on that face. <b>Check:</b> walk through the setup sheet with the operator; verify no step was missed for that face.', $worstQ),
        ];
    }
    if ($a['drift_corr'] > 0.4 && $a['drift_corr'] < 0.9) {
        $causes[] = [
            'title' => 'Operator drift / tool wear over the run',
            'body'  => sprintf('A slow positive drift exists (correlation r = %.2f). A worn hand-tool (e.g. a dull turning insert) or operator fatigue late in the cut could produce a steadily-shifting size. <b>Check:</b> inspect the cutting edge for wear; consider breaking the job into shorter cuts with a fresh edge.', $a['drift_corr']),
        ];
    }
    return $causes;
}

// =============================================================
// GRINDING (surface or cylindrical)
// =============================================================

function _cmm_machining_grinding($a)
{
    $html = '<h3 style="margin-top:0;">What the grinder did well</h3><ul>';
    $positives = [];
    if ($a['z_constant']) {
        $positives[] = 'Z-axis depth control is excellent — every probed point reports a Z deviation of exactly 0.0000. Infeed steps and sparkout passes held depth tightly.';
    }
    if ($a['dist_stats']['stdev'] < 0.0003) {
        $positives[] = sprintf('Standard deviation (scatter) is tight: σ = %.4f. The wheel is dressed and balanced — argues against wheel-load variation, glazing, or coolant interruption.', $a['dist_stats']['stdev']);
    }
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    $bestQ  = _cmm_best_quad($a['quad_stats']);
    if ($bestQ && $a['quad_stats'][$bestQ]['oot_pct'] < 5) {
        $positives[] = sprintf('Three quadrants out of four behave well or near-passing. %s in particular is %.1f%% OOT — close to passing — suggesting the wheel form and dressing diamond setup are fundamentally correct.',
            $bestQ, $a['quad_stats'][$bestQ]['oot_pct']);
    }
    if (abs($a['drift_corr']) < 0.7) {
        $positives[] = sprintf('No strong wheel-wear signature: index-vs-deviation correlation is only %.2f. The wheel is not progressively losing form across the run.',
            $a['drift_corr']);
    }
    if (empty($positives)) $positives[] = '(Nothing flagged on the positive side.)';
    foreach ($positives as $p) $html .= '<li>' . $p . '</li>';
    $html .= '</ul>';

    $html .= '<h3>Where the grinder fell short</h3><ul>';
    $negatives = [];
    if ($a['oot_pct'] > 1) {
        $negatives[] = sprintf('%.1f%% out-of-tolerance — well above the 1%% threshold expected of a controlled grinding process.', $a['oot_pct']);
    }
    if ($a['cpk_upper'] < 1.33) {
        $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark. The achieved size sits too close to the upper limit — likely an infeed / dress-comp issue rather than scatter.', $a['cpk_upper']);
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 50) {
        $negatives[] = sprintf('Severe %s defect: %.1f%% of points OOT in that quadrant, with peak deviation +%.4f. Continuous OOT zones in one region suggest a low spot on the wheel, the dressing diamond skipped that arc, or magnetic chuck distortion under load.',
            $worstQ, $a['quad_stats'][$worstQ]['oot_pct'], $a['quad_stats'][$worstQ]['max']);
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $negatives[] = sprintf('Systematic XY origin shift: mean ΔX = %+.4f, mean ΔY = %+.4f — fingerprint of a dress-compensation error (the wheel was dressed but the new wheel size was not registered), or part-zero touch-off against the wrong reference.',
            $a['x_stats']['mean'], $a['y_stats']['mean']);
    }
    if (empty($negatives)) $negatives[] = '(No grinding issues flagged.)';
    foreach ($negatives as $n) $html .= '<li>' . $n . '</li>';
    $html .= '</ul>';
    return $html;
}

function _cmm_causes_grinding($a)
{
    $causes = [];
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 30) {
        $causes[] = [
            'title' => 'Dress compensation / wheel-size offset error — most likely',
            'body'  => 'The wheel was dressed but the control was not updated with the new wheel diameter (or the dress increment was applied incorrectly). This leaves excess stock everywhere proportionally. <b>Check:</b> verify the dress-comp value in the control matches the actual wheel diameter; re-measure the wheel with a probe or against a master if available; confirm the dressing diamond is not itself worn.',
        ];
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $dir = '';
        if      ($a['x_stats']['mean'] > 0) $dir .= '+X';
        elseif  ($a['x_stats']['mean'] < 0) $dir .= '-X';
        if      ($a['y_stats']['mean'] > 0) $dir .= '/+Y';
        elseif  ($a['y_stats']['mean'] < 0) $dir .= '/-Y';
        $causes[] = [
            'title' => 'Part-zero / magnetic-chuck seating shift',
            'body'  => sprintf('The constant %s bias (ΔX = %+.4f, ΔY = %+.4f) is consistent with the part not seating fully against the chuck rail or a touch-off against a non-print reference. <b>Check:</b> clean the chuck face and demagnetise before reseating; verify the part contacts the locating rail along its full length; redo the touch-off with the current dressed wheel.',
                $dir, $a['x_stats']['mean'], $a['y_stats']['mean']),
        ];
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 80) {
        $causes[] = [
            'title' => 'Localised wheel defect / sparkout skipped over one arc',
            'body'  => sprintf('Almost all of %s fails (where other quadrants pass), hinting at a region the sparkout pass did not catch — possibly because the wheel had a chip or low spot at that azimuth, or the dressing pass missed that section. <b>Check:</b> dress the wheel fresh and re-run; inspect the wheel circumference for chips or low spots; ensure enough sparkout passes are programmed.', $worstQ),
        ];
    }
    if ($a['drift_corr'] > 0.4 && $a['drift_corr'] < 0.9) {
        $causes[] = [
            'title' => 'Wheel wear / spindle thermal growth over the run',
            'body'  => sprintf('A slow positive drift exists (correlation r = %.2f). The wheel is losing diameter as it wears (especially if dressing intervals are too far apart), or spindle thermal growth is shifting the cut line. <b>Check:</b> shorten the dress interval; allow longer warm-up before the precision passes; verify coolant flow temperature is stable.', $a['drift_corr']),
        ];
    }
    return $causes;
}

// =============================================================
// OTHER — generic / process-agnostic fallback
// =============================================================

function _cmm_machining_other($a)
{
    $html = '<h3 style="margin-top:0;">What the process did well</h3><ul>';
    $positives = [];
    if ($a['z_constant']) {
        $positives[] = 'Z-axis dimension is consistent — every probed point reports a Z deviation of exactly 0.0000.';
    }
    if ($a['dist_stats']['stdev'] < 0.0003) {
        $positives[] = sprintf('Standard deviation (scatter) is tight: σ = %.4f. The process is repeating consistently from point to point.', $a['dist_stats']['stdev']);
    }
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    $bestQ  = _cmm_best_quad($a['quad_stats']);
    if ($bestQ && $a['quad_stats'][$bestQ]['oot_pct'] < 5) {
        $positives[] = sprintf('Three quadrants out of four behave well or near-passing. %s in particular is %.1f%% OOT — close to passing — suggesting the process setup is fundamentally sound.',
            $bestQ, $a['quad_stats'][$bestQ]['oot_pct']);
    }
    if (abs($a['drift_corr']) < 0.7) {
        $positives[] = sprintf('No strong drift signature: index-vs-deviation correlation is only %.2f. The process is not progressively worsening across the run.',
            $a['drift_corr']);
    }
    if (empty($positives)) $positives[] = '(Nothing flagged on the positive side.)';
    foreach ($positives as $p) $html .= '<li>' . $p . '</li>';
    $html .= '</ul>';

    $html .= '<h3>Where the process fell short</h3><ul>';
    $negatives = [];
    if ($a['oot_pct'] > 1) {
        $negatives[] = sprintf('%.1f%% out-of-tolerance — well above the 1%% threshold expected of a controlled process.', $a['oot_pct']);
    }
    if ($a['cpk_upper'] < 1.33) {
        $negatives[] = sprintf('Cpk(upper) ≈ %.2f, below the 1.33 industrial benchmark. The distribution mean sits too close to the upper specification limit.', $a['cpk_upper']);
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 50) {
        $negatives[] = sprintf('Severe %s defect: %.1f%% of points OOT in that quadrant, with peak deviation +%.4f. Continuous OOT zones suggest a localised setup or process defect rather than random scatter.',
            $worstQ, $a['quad_stats'][$worstQ]['oot_pct'], $a['quad_stats'][$worstQ]['max']);
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $negatives[] = sprintf('Systematic XY origin shift: mean ΔX = %+.4f, mean ΔY = %+.4f — fingerprint of a coordinate-system / origin offset error.',
            $a['x_stats']['mean'], $a['y_stats']['mean']);
    }
    if (empty($negatives)) $negatives[] = '(No process issues flagged.)';
    foreach ($negatives as $n) $html .= '<li>' . $n . '</li>';
    $html .= '</ul>';
    return $html;
}

function _cmm_causes_other($a)
{
    $causes = [];
    $worstQ = _cmm_worst_quad($a['quad_stats']);
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 30) {
        $causes[] = [
            'title' => 'Tool / wheel / wire offset compensation error — most likely',
            'body'  => 'A small error in the assumed tool / wheel / wire size leaves excess stock outside the nominal in regions of positive curvature. A discrepancy of just 0.0002–0.0003 in radius accounts for the observed magnitudes. <b>Check:</b> remeasure the cutting element with calibrated instruments; verify the compensation value in the control matches the actual measurement.',
        ];
    }
    if (abs($a['x_stats']['mean']) > 0.0001 || abs($a['y_stats']['mean']) > 0.0001) {
        $dir = '';
        if      ($a['x_stats']['mean'] > 0) $dir .= '+X';
        elseif  ($a['x_stats']['mean'] < 0) $dir .= '-X';
        if      ($a['y_stats']['mean'] > 0) $dir .= '/+Y';
        elseif  ($a['y_stats']['mean'] < 0) $dir .= '/-Y';
        $causes[] = [
            'title' => 'Coordinate-system / part-zero shift',
            'body'  => sprintf('The constant %s bias (ΔX = %+.4f, ΔY = %+.4f) is consistent with a touch-off / part-zero that drifted from the print datum. <b>Check:</b> redo the touch-off with calibrated instruments; verify the active work coordinate matches the print datum scheme; confirm the CMM datums match the setup datums.',
                $dir, $a['x_stats']['mean'], $a['y_stats']['mean']),
        ];
    }
    if ($worstQ && $a['quad_stats'][$worstQ]['oot_pct'] > 80) {
        $causes[] = [
            'title' => 'Localised setup discontinuity at one region',
            'body'  => sprintf('Almost all of %s fails (where other quadrants pass), hinting at a single setup step that did not execute correctly over that region. <b>Check:</b> walk through the process sheet and identify steps that apply only to that region; verify each was performed correctly.', $worstQ),
        ];
    }
    if ($a['drift_corr'] > 0.4 && $a['drift_corr'] < 0.9) {
        $causes[] = [
            'title' => 'Progressive drift during the run',
            'body'  => sprintf('A slow positive drift exists (correlation r = %.2f). Some element of the process (tool wear, thermal growth, operator fatigue, consumable wear) is shifting the result steadily over the run.', $a['drift_corr']),
        ];
    }
    return $causes;
}

// =============================================================
// RECOMMENDATION DISPATCH (per machine type, computed at render time)
// =============================================================

function _cmm_recommendations_for($machineType, $verdict, $q, $x, $y, $isMultipass = false)
{
    if ($verdict === 'PASS') {
        return [
            "Process is within spec. Continue current setup.",
            "Maintain SPC monitoring at the previously identified worst-case point.",
        ];
    }
    switch ($machineType) {
        case 'wedm':     return _cmm_recommendations_wedm($q, $x, $y, $isMultipass);
        case 'manual':   return _cmm_recommendations_manual($q, $x, $y);
        case 'grinding': return _cmm_recommendations_grinding($q, $x, $y);
        case 'other':    return _cmm_recommendations_other($q, $x, $y);
        case 'vmc':
        default:         return _cmm_recommendations_vmc($q, $x, $y);
    }
}

function _cmm_recommendations_vmc($q, $x, $y)
{
    $recs = [
        "Hold the part; flag for engineering review before shipping.",
        "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
        "Re-measure the finish-pass cutter on the tool presetter and update the offset table.",
        "Re-touch the part datum with a calibrated probe; verify the active G54 / work coordinate matches the print datum scheme.",
    ];
    $worst = _cmm_worst_quad($q);
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        $recs[] = "Inspect the NC program in the angular region corresponding to {$worst} for differing feedrate, cutter-comp toggles, or a separate finish pass.";
    }
    $recs[] = "Run a prove-out part with corrections applied; re-CMM and confirm Cpk(U) ≥ 1.33 before releasing.";
    return $recs;
}

function _cmm_recommendations_wedm($q, $x, $y, $isMultipass = false)
{
    if ($isMultipass) {
        $recs = [
            "Hold the part; flag for engineering review before shipping.",
            "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
            "Verify the wire-radius compensation value for the FINAL skim pass specifically — the rough offset and skim offsets are usually separate parameters.",
            "Measure the wire on a calibrated micrometer at the section actually used for finishing; the spool may be non-uniform.",
            "Consider adding one more skim pass with reduced energy, or widening the final-pass offset by 0.0002–0.0003.",
            "Re-edge-find the part with the current wire installed; verify the part-zero macro reads the current wire-comp table.",
            "Inspect the upper and lower diamond guides under magnification; rotate to fresh guide positions if they show wear scars.",
        ];
    } else {
        $recs = [
            "Hold the part; flag for engineering review before shipping.",
            "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
            "Measure the wire on a calibrated micrometer; verify the wire-radius compensation in the control matches the spool actually mounted.",
            "Re-edge-find the part with the current wire installed; verify the part-zero macro reads the current wire-comp table.",
            "Inspect the upper and lower diamond guides under magnification; rotate to fresh guide positions if they show wear scars.",
        ];
    }
    $worst = _cmm_worst_quad($q);
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        if ($isMultipass) {
            $recs[] = "For the angular region corresponding to {$worst}: verify the skim-pass path covers it with adequate overlap; check corner-control settings on the final pass; consider re-routing skim start/end points away from that arc.";
        } else {
            $recs[] = "Review the wire path for the angular region corresponding to {$worst}; check corner-control settings and consider an additional skim pass over that arc.";
        }
    }
    $recs[] = "Run a prove-out part with corrections applied; re-CMM and confirm Cpk(U) ≥ 1.33 before releasing.";
    return $recs;
}

function _cmm_recommendations_manual($q, $x, $y)
{
    $recs = [
        "Hold the part; flag for engineering review before shipping.",
        "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
        "Re-zero the indicator / DTI against a calibrated gauge block; verify the micrometer is in current calibration.",
        "Have a second operator independently verify a few key measurements.",
        "Repeat the touch-off / edge-find with a second operator observing; verify the parallels are clean and the part is fully seated.",
    ];
    $worst = _cmm_worst_quad($q);
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        $recs[] = "Walk through the setup sheet with the operator for the {$worst} face; identify any steps that may have been missed or done out of sequence.";
    }
    $recs[] = "Run a prove-out part with corrections applied; re-CMM and confirm Cpk(U) ≥ 1.33 before releasing.";
    return $recs;
}

function _cmm_recommendations_grinding($q, $x, $y)
{
    $recs = [
        "Hold the part; flag for engineering review before shipping.",
        "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
        "Dress the wheel fresh and verify the dress-comp value in the control matches the new wheel diameter.",
        "Inspect the dressing diamond for wear; replace if rounded.",
        "Demagnetise and clean the chuck face; verify the part seats fully against the locating rail.",
    ];
    $worst = _cmm_worst_quad($q);
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        $recs[] = "Inspect the wheel circumference for chips or low spots that align with the {$worst} azimuth; consider adding sparkout passes.";
    }
    $recs[] = "Run a prove-out part with corrections applied; re-CMM and confirm Cpk(U) ≥ 1.33 before releasing.";
    return $recs;
}

function _cmm_recommendations_other($q, $x, $y)
{
    $recs = [
        "Hold the part; flag for engineering review before shipping.",
        "Re-qualify the CMM probe and re-scan the worst point to rule out a CMM false reading.",
        "Remeasure the cutting element / tool / wire with calibrated instruments; verify the compensation value in the control matches.",
        "Repeat the touch-off / part-zero procedure; verify the active work coordinate matches the print datum scheme.",
    ];
    $worst = _cmm_worst_quad($q);
    if ($worst && $q[$worst]['oot_pct'] > 50) {
        $recs[] = "Inspect the process steps that apply to the {$worst} region for any setup or programming discrepancies.";
    }
    $recs[] = "Run a prove-out part with corrections applied; re-CMM and confirm Cpk(U) ≥ 1.33 before releasing.";
    return $recs;
}

function _cmm_worst_quad($qs)
{
    $worst = null; $worstPct = -1;
    foreach ($qs as $k => $s) {
        if ($s['oot_pct'] > $worstPct) { $worst = $k; $worstPct = $s['oot_pct']; }
    }
    return $worst;
}
function _cmm_best_quad($qs)
{
    $best = null; $bestPct = 101;
    foreach ($qs as $k => $s) {
        if ($s['oot_pct'] < $bestPct) { $best = $k; $bestPct = $s['oot_pct']; }
    }
    return $best;
}

// =============================================================
// PERSISTENCE
// =============================================================

/**
 * Insert a complete run + its points in a transaction.
 * Returns the new run id.
 */
function cmm_insert_run($filename, $sizeBytes, $extractedVia, $meta,
                        $upperTol, $lowerTol, $analysis, $points, $uploadedBy,
                        $machineType = 'vmc', $isMultipass = false)
{
    // Validate / normalize machine_type. The normalizer accepts the key
    // ('wedm'), the label ('Wire EDM'), or a stray null and resolves to a
    // valid enum value. Defaults to 'vmc' for anything unrecognized.
    $machineType = _cmm_normalize_machine_type($machineType);

    // is_multipass only meaningful for wedm. Force to 0 for any other
    // machine type so we don't accidentally render multi-pass prose for
    // a VMC run that somehow got the flag set.
    $isMultipass = ($machineType === 'wedm' && $isMultipass) ? 1 : 0;

    db_exec('START TRANSACTION');
    try {
        db_exec(
            "INSERT INTO cmm_runs
                (filename, size_bytes, uploaded_at, uploaded_by,
                 report_date, part_number, cmm_type, machine_type, is_multipass, operator, feature_name,
                 upper_tol, lower_tol, z_value,
                 point_count, in_tol_count, edge_count, oot_count,
                 cpk_upper, verdict, analysis_json, extracted_via)
             VALUES (?, ?, NOW(), ?,
                     ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?)",
            [
                $filename, (int)$sizeBytes, $uploadedBy ? (int)$uploadedBy : null,
                $meta['report_date']  ?? null,
                $meta['part_number']  ?? null,
                $meta['cmm_type']     ?? null,
                $machineType,
                $isMultipass,
                $meta['operator']     ?? null,
                $meta['feature_name'] ?? null,
                (float)$upperTol, (float)$lowerTol,
                $analysis['z_value'],
                (int)$analysis['N'],
                (int)$analysis['in_tol_count'],
                (int)$analysis['edge_count'],
                (int)$analysis['oot_count'],
                is_finite($analysis['cpk_upper']) ? (float)$analysis['cpk_upper'] : 9.99,
                $analysis['verdict'],
                json_encode($analysis, JSON_UNESCAPED_SLASHES),
                $extractedVia,
            ]
        );
        $runId = (int)db_val('SELECT LAST_INSERT_ID()');

        foreach ($points as $p) {
            db_exec(
                "INSERT INTO cmm_points
                    (run_id, idx, tag,
                     x_actual, x_nominal, x_dev,
                     y_actual, y_nominal, y_dev,
                     z_actual, z_nominal, z_dev,
                     dist_actual, dist_dev, out_of_tol)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $runId,
                    (int)$p['idx'], $p['tag'],
                    (float)$p['x_actual'],    (float)$p['x_nominal'],    (float)$p['x_dev'],
                    (float)$p['y_actual'],    (float)$p['y_nominal'],    (float)$p['y_dev'],
                    (float)$p['z_actual'],    (float)$p['z_nominal'],    (float)$p['z_dev'],
                    (float)$p['dist_actual'], (float)$p['dist_dev'],
                    !empty($p['out_of_tol']) ? 1 : 0,
                ]
            );
        }
        db_exec('COMMIT');
        return $runId;
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        throw $e;
    }
}

function cmm_load_run($runId)
{
    return db_one("SELECT * FROM cmm_runs WHERE id = ?", [(int)$runId]);
}

function cmm_load_points($runId)
{
    $rows = db_all(
        "SELECT idx, tag,
                x_actual, x_nominal, x_dev,
                y_actual, y_nominal, y_dev,
                z_actual, z_nominal, z_dev,
                dist_actual, dist_dev, out_of_tol
           FROM cmm_points
          WHERE run_id = ?
          ORDER BY idx ASC",
        [(int)$runId]
    );
    foreach ($rows as &$r) {
        foreach (['x_actual','x_nominal','x_dev','y_actual','y_nominal','y_dev',
                  'z_actual','z_nominal','z_dev','dist_actual','dist_dev'] as $k) {
            $r[$k] = (float)$r[$k];
        }
        $r['idx']        = (int)$r['idx'];
        $r['out_of_tol'] = (int)$r['out_of_tol'] === 1;
    }
    unset($r);
    return $rows;
}

function cmm_set_comment($runId, $comment)
{
    $comment = str_replace("\r\n", "\n", (string)$comment);
    $comment = rtrim($comment);
    if (strlen($comment) > 8000) {
        throw new RuntimeException("Comment exceeds maximum length of 8000 characters.");
    }
    $stored = $comment === '' ? null : $comment;
    db_exec("UPDATE cmm_runs SET comment = ? WHERE id = ?", [$stored, (int)$runId]);
    return $comment;
}

function cmm_list_runs($limit = 200)
{
    $limit = (int)$limit;
    if ($limit < 1 || $limit > 2000) $limit = 200;
    return db_all(
        "SELECT r.id, r.filename, r.uploaded_at, r.report_date, r.part_number,
                r.machine_type,
                r.point_count, r.oot_count, r.verdict, r.cpk_upper, r.comment,
                u.full_name AS uploaded_by_name,
                (SELECT COUNT(*) FROM inv_txn_cmm_runs xt WHERE xt.cmm_run_id = r.id) AS txn_link_count
           FROM cmm_runs r
           LEFT JOIN users u ON u.id = r.uploaded_by
       ORDER BY r.uploaded_at DESC
          LIMIT $limit"
    );
}

function cmm_delete_run($runId)
{
    db_exec("DELETE FROM cmm_runs WHERE id = ?", [(int)$runId]);
}

// =============================================================
// LINKAGE: inv_txns ↔ cmm_runs
// =============================================================

function cmm_link_to_txn($runId, $txnId, $linkedBy, $note = null)
{
    db_exec(
        "INSERT IGNORE INTO inv_txn_cmm_runs (txn_id, cmm_run_id, linked_by, note)
         VALUES (?, ?, ?, ?)",
        [(int)$txnId, (int)$runId, $linkedBy ? (int)$linkedBy : null, $note]
    );
}

function cmm_unlink_from_txn($runId, $txnId)
{
    db_exec(
        "DELETE FROM inv_txn_cmm_runs WHERE cmm_run_id = ? AND txn_id = ?",
        [(int)$runId, (int)$txnId]
    );
}

function cmm_runs_for_txn($txnId)
{
    return db_all(
        "SELECT r.id, r.filename, r.uploaded_at, r.verdict,
                r.point_count, r.oot_count, r.cpk_upper,
                x.linked_at, x.note, u.full_name AS linked_by_name
           FROM inv_txn_cmm_runs x
           JOIN cmm_runs r ON r.id = x.cmm_run_id
      LEFT JOIN users u ON u.id = x.linked_by
          WHERE x.txn_id = ?
          ORDER BY x.linked_at DESC",
        [(int)$txnId]
    );
}

function cmm_txns_for_run($runId)
{
    return db_all(
        "SELECT t.id, t.txn_type, t.txn_date, t.qty_delta, t.qty_after,
                t.ref_doc, t.notes,
                i.code AS item_code, i.name AS item_name,
                l.name AS location_name,
                x.linked_at, x.note AS link_note,
                u.full_name AS linked_by_name
           FROM inv_txn_cmm_runs x
           JOIN inv_txns t ON t.id = x.txn_id
      LEFT JOIN inv_items i ON i.id = t.item_id
      LEFT JOIN locations l ON l.id = t.location_id
      LEFT JOIN users u ON u.id = x.linked_by
          WHERE x.cmm_run_id = ?
          ORDER BY x.linked_at DESC",
        [(int)$runId]
    );
}

function cmm_verdict_pill($verdict)
{
    $map = [
        'PASS'     => 'pill-success',
        'MARGINAL' => 'pill-warning',
        'REJECT'   => 'pill-danger',
    ];
    return isset($map[$verdict]) ? $map[$verdict] : 'pill-neutral';
}
