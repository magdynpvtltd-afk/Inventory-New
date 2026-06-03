<?php
/**
 * MagDyn — CMM Analyzer dispatcher
 *
 * Actions:
 *   ?action=list                       (default) — list of runs + upload widget
 *   ?action=view&id=N                  — analysis report for one run
 *   ?action=save           POST        — receives the JSON payload from app.js,
 *                                        runs cmm_analyze(), persists, returns
 *                                        { ok: true, run_id: N } JSON
 *   ?action=api_run&id=N   GET         — returns points + analysis JSON for the
 *                                        Plotly charts on the view page
 *   ?action=api_comment&id=N           — GET returns current comment; POST sets it
 *   ?action=export&id=N    GET         — downloads a self-contained HTML report
 *   ?action=link_txn&id=N  POST        — link this run to an inv_txn
 *   ?action=unlink_txn&id=N POST       — remove a link
 *   ?action=delete&id=N    POST        — hard-delete a run (cmm.delete)
 *
 * Permissions:
 *   cmm.view    — list and view runs
 *   cmm.upload  — analyze new PDFs
 *   cmm.comment — edit notes on a run
 *   cmm.link    — link/unlink runs to inv_txns
 *   cmm.delete  — delete runs
 *
 * The PDF itself is parsed in the browser via pdf.js (with Tesseract.js OCR
 * fallback for scanned PDFs). Only extracted measurement data is POSTed here.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_cmm.php';
require_once __DIR__ . '/includes/datatable.php';

require_permission('cmm', 'view');

$action = (string)input('action', 'list');
$id     = (int)input('id', 0);
$uid    = current_user_id();

// =============================================================
// POST: save — receives parsed JSON, runs analyzer, persists.
// Returns JSON.
// =============================================================
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_permission('cmm', 'upload');
    try {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            throw new RuntimeException("Empty request body.");
        }
        // Reasonable cap to prevent runaway payloads (20 MB)
        if (strlen($raw) > 20 * 1024 * 1024) {
            throw new RuntimeException("Payload exceeds 20 MB.");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON payload.");
        }
        $filename = (string)($data['filename'] ?? '');
        if ($filename === '') {
            throw new RuntimeException("Missing 'filename'.");
        }
        if (!isset($data['points']) || !is_array($data['points']) || empty($data['points'])) {
            throw new RuntimeException("No points in payload.");
        }
        $upperTol = isset($data['upper_tol']) ? (float)$data['upper_tol'] : 0.0005;
        $lowerTol = isset($data['lower_tol']) ? (float)$data['lower_tol'] : -0.0005;
        $meta     = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $extractedVia = (string)($data['extracted_via'] ?? 'pdfjs');
        $sizeBytes    = (int)($data['size_bytes'] ?? 0);
        $linkTxnId    = (int)($data['link_txn_id'] ?? 0);
        // Machine type — validated against the registry in cmm_insert_run.
        // Defaults to 'vmc' (backward-compatible behaviour).
        $machineType  = (string)($data['machine_type'] ?? 'vmc');
        // Multi-pass flag — only meaningful for WEDM. cmm_insert_run forces
        // this to 0 for any other machine type.
        $isMultipass  = !empty($data['is_multipass']);

        // Sanitise points
        $cleanPoints = [];
        foreach ($data['points'] as $p) {
            if (!is_array($p)) continue;
            $cleanPoints[] = [
                'idx'         => (int)($p['idx'] ?? 0),
                'tag'         => isset($p['tag']) && $p['tag'] !== null ? (string)$p['tag'] : null,
                'x_actual'    => (float)($p['x_actual'] ?? 0),
                'x_nominal'   => (float)($p['x_nominal'] ?? 0),
                'x_dev'       => (float)($p['x_dev'] ?? 0),
                'y_actual'    => (float)($p['y_actual'] ?? 0),
                'y_nominal'   => (float)($p['y_nominal'] ?? 0),
                'y_dev'       => (float)($p['y_dev'] ?? 0),
                'z_actual'    => (float)($p['z_actual'] ?? 0),
                'z_nominal'   => (float)($p['z_nominal'] ?? 0),
                'z_dev'       => (float)($p['z_dev'] ?? 0),
                'dist_actual' => (float)($p['dist_actual'] ?? 0),
                'dist_dev'    => (float)($p['dist_dev'] ?? 0),
                'out_of_tol'  => !empty($p['out_of_tol']) ? 1 : 0,
            ];
        }
        if (empty($cleanPoints)) {
            throw new RuntimeException("No valid points after sanitisation.");
        }
        $analysis = cmm_analyze($cleanPoints, $upperTol, $lowerTol);
        $runId = cmm_insert_run($filename, $sizeBytes, $extractedVia, $meta,
                                $upperTol, $lowerTol, $analysis, $cleanPoints, $uid,
                                $machineType, $isMultipass);

        // If launched from a specific txn, auto-link
        if ($linkTxnId > 0 && permission_check('cmm', 'link')) {
            $exists = db_val("SELECT id FROM inv_txns WHERE id = ?", [$linkTxnId]);
            if ($exists) {
                cmm_link_to_txn($runId, $linkTxnId, $uid, 'Auto-linked at upload');
            }
        }
        echo json_encode(['ok' => true, 'run_id' => $runId]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =============================================================
// POST: api_machine — update machine_type on an existing run
// =============================================================
if ($action === 'api_machine' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_permission('cmm', 'upload');
    try {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON payload.");
        }
        $runId = (int)($data['run_id'] ?? 0);
        $mt    = (string)($data['machine_type'] ?? '');
        if ($runId <= 0) throw new RuntimeException("Missing run_id.");
        $types = cmm_machine_types();
        if (!isset($types[$mt])) {
            throw new RuntimeException("Unknown machine_type '$mt'.");
        }
        $exists = db_val("SELECT id FROM cmm_runs WHERE id = ?", [$runId]);
        if (!$exists) throw new RuntimeException("Run not found.");
        db_exec("UPDATE cmm_runs SET machine_type = ? WHERE id = ?", [$mt, $runId]);
        echo json_encode([
            'ok'    => true,
            'label' => $types[$mt],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =============================================================
// GET: api_run — returns points + analysis for the Plotly charts
// =============================================================
if ($action === 'api_run') {
    header('Content-Type: application/json; charset=utf-8');
    $run = cmm_load_run($id);
    if (!$run) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Run not found']);
        exit;
    }
    $points = cmm_load_points($id);
    echo json_encode([
        'ok'         => true,
        'id'         => (int)$run['id'],
        'filename'   => $run['filename'],
        'upper_tol'  => (float)$run['upper_tol'],
        'lower_tol'  => (float)$run['lower_tol'],
        'verdict'    => $run['verdict'],
        'point_count'=> (int)$run['point_count'],
        'analysis'   => json_decode($run['analysis_json'], true),
        'points'     => $points,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// =============================================================
// api_comment — GET returns current; POST updates
// =============================================================
if ($action === 'api_comment') {
    header('Content-Type: application/json; charset=utf-8');
    $run = cmm_load_run($id);
    if (!$run) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Run not found']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'ok'      => true,
            'id'      => (int)$run['id'],
            'comment' => $run['comment'] !== null ? (string)$run['comment'] : '',
        ]);
        exit;
    }
    // POST
    require_permission('cmm', 'comment');
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('comment', $data)) {
            throw new RuntimeException("Expected JSON body { comment: '...' }");
        }
        $saved = cmm_set_comment($id, $data['comment']);
        echo json_encode(['ok' => true, 'id' => (int)$id, 'comment' => $saved, 'updated' => true]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =============================================================
// link_txn / unlink_txn
// =============================================================
if ($action === 'link_txn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('cmm', 'link');
    $txnId = (int)input('txn_id', 0);
    $note  = trim((string)input('note', '')) ?: null;
    if ($txnId <= 0) {
        flash_set('error', 'Pick a txn id.');
    } else {
        $exists = db_val("SELECT id FROM inv_txns WHERE id = ?", [$txnId]);
        if (!$exists) flash_set('error', "Inv txn #$txnId not found.");
        else {
            cmm_link_to_txn($id, $txnId, $uid, $note);
            flash_set('success', "Linked to inv_txn #$txnId.");
        }
    }
    header('Location: ' . url('/cmm.php?action=view&id=' . $id));
    exit;
}
if ($action === 'unlink_txn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('cmm', 'link');
    $txnId = (int)input('txn_id', 0);
    cmm_unlink_from_txn($id, $txnId);
    flash_set('success', "Unlinked inv_txn #$txnId.");
    header('Location: ' . url('/cmm.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// delete
// =============================================================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('cmm', 'delete');
    $run = cmm_load_run($id);
    if (!$run) {
        flash_set('error', 'Run not found.');
    } else {
        cmm_delete_run($id);
        flash_set('success', "Deleted run #$id.");
    }
    header('Location: ' . url('/cmm.php'));
    exit;
}

// =============================================================
// export — self-contained HTML download
//
// Renders the same content the view page shows, but as a single
// HTML file that includes the Plotly library from CDN + the points
// JSON inline. Opens in any browser, no PHP required. Recipient can
// print it to PDF from their browser if they want.
// =============================================================
if ($action === 'export') {
    $run = cmm_load_run($id);
    if (!$run) { http_response_code(404); die('CMM run not found.'); }
    $points  = cmm_load_points($id);
    $analysis = json_decode($run['analysis_json'], true);
    $meta = [
        'report_date'  => $run['report_date'],
        'part_number'  => $run['part_number'],
        'cmm_type'     => $run['cmm_type'],
        'operator'     => $run['operator'],
        'feature_name' => $run['feature_name'],
    ];
    $verdictColor = $run['verdict'] === 'PASS'    ? '#137333'
                  : ($run['verdict'] === 'MARGINAL' ? '#b06000' : '#b3261e');
    $filename = sprintf('CMM_Run_%d_%s.html', (int)$run['id'],
                        preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$run['part_number']) ?: 'report');

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CMM Run #<?= (int)$run['id'] ?> — <?= h($run['part_number'] ?: $run['filename']) ?></title>
<script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
<style>
    * { box-sizing: border-box; }
    body { font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1a2330; max-width: 1100px; margin: 0 auto; padding: 24px; background: #fff; }
    h1 { font-size: 22px; margin: 0 0 6px; }
    h2 { font-size: 17px; margin: 24px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #e5e7eb; }
    h3 { font-size: 14px; margin: 18px 0 8px; }
    h4 { margin: 0 0 6px; font-size: 13px; }
    p { margin: 8px 0; }
    .muted { color: #6b7280; }
    .small { font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 13px; }
    th, td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
    th { background: #f9fafb; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #475569; }
    .verdict-banner { padding: 16px 20px; border-radius: 8px; border-left: 6px solid <?= $verdictColor ?>; background: <?= $verdictColor ?>15; margin: 12px 0 24px; }
    .verdict-banner .v { display: inline-block; padding: 4px 14px; background: <?= $verdictColor ?>; color: #fff; font-weight: 700; border-radius: 4px; font-size: 16px; }
    .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 12px 0; }
    .metric { padding: 12px; background: #f9fafb; border-radius: 6px; }
    .metric .label { font-size: 12px; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; }
    .metric .value { font-size: 20px; font-weight: 700; margin-top: 4px; }
    .metric .sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
    .good { color: #137333; } .warn { color: #b06000; } .bad { color: #b3261e; }
    .plot { width: 100%; min-height: 360px; margin: 12px 0; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
    .pill.pass     { background: #dcfce7; color: #137333; }
    .pill.marginal { background: #fef3c7; color: #b06000; }
    .pill.reject   { background: #fee2e2; color: #b3261e; }
    .cause { margin: 10px 0; padding: 10px 14px; background: #f9fafb; border-left: 3px solid #cbd5e1; }
    .notes-box { padding: 10px 14px; background: #fffbeb; border-left: 3px solid #d97706; white-space: pre-wrap; }
    footer { margin-top: 30px; padding-top: 14px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #6b7280; }
    @media print { body { max-width: none; padding: 0; } .no-print { display: none; } }
</style>
</head>
<body>

<h1>CMM Run #<?= (int)$run['id'] ?></h1>
<p class="muted">
    <?= h($run['filename']) ?> ·
    Uploaded <?= h($run['uploaded_at']) ?> ·
    <?= h(date('d M Y H:i', strtotime($run['uploaded_at']))) ?>
</p>

<div class="verdict-banner">
    <span class="v"><?= h($run['verdict']) ?></span>
    <strong style="margin-left: 12px;">
        <?php if ($run['verdict'] === 'PASS'): ?>Part meets print
        <?php elseif ($run['verdict'] === 'MARGINAL'): ?>Borderline — review recommended
        <?php else: ?>Does not meet print
        <?php endif; ?>
    </strong>
    <div class="muted" style="margin-top: 6px;">
        <?= number_format((int)$run['oot_count']) ?> of <?= number_format((int)$run['point_count']) ?>
        points out of tolerance (<?= number_format((float)$analysis['oot_pct'], 1) ?>%)
        · Cpk(U) = <?= number_format((float)$analysis['cpk_upper'], 2) ?>
    </div>
</div>

<?php if (!empty($run['comment'])): ?>
<h2>Notes</h2>
<div class="notes-box"><?= h($run['comment']) ?></div>
<?php endif; ?>

<h2>Report header</h2>
<table>
    <tr><th>Source file</th><td><?= h($run['filename']) ?></td>
        <th>Uploaded</th><td><?= h($run['uploaded_at']) ?></td></tr>
    <tr><th>CMM software</th><td>ZEISS Calypso</td>
        <th>CMM hardware</th><td><?= h($run['cmm_type'] ?: '—') ?></td></tr>
    <tr><th>Report date</th><td><?= h($run['report_date'] ?: '—') ?></td>
        <th>Operator</th><td><?= h($run['operator'] ?: '—') ?></td></tr>
    <tr><th>Part number</th><td><?= h($run['part_number'] ?: '—') ?></td>
        <th>Feature</th><td><?= h($run['feature_name'] ?: '—') ?></td></tr>
    <tr><th>Machine type</th><td><?= h(cmm_machine_label($run['machine_type'] ?? 'vmc')) ?></td>
        <th>Point count</th><td><?= (int)$run['point_count'] ?></td></tr>
    <tr><th>Z (probe plane)</th><td><?= $run['z_value'] !== null ? number_format((float)$run['z_value'], 4) : '—' ?></td>
        <th>Upper / lower tol</th><td>+<?= number_format((float)$run['upper_tol'], 4) ?> / <?= number_format((float)$run['lower_tol'], 4) ?></td></tr>
</table>

<h2>1. Key metrics</h2>
<div class="metrics">
    <div class="metric"><div class="label">In tolerance</div>
        <div class="value good"><?= number_format((float)$analysis['in_tol_pct'], 1) ?>%</div>
        <div class="sub"><?= number_format((int)$analysis['in_tol_count']) ?> / <?= number_format((int)$analysis['N']) ?></div></div>
    <div class="metric"><div class="label">At edge</div>
        <div class="value warn"><?= number_format((float)$analysis['edge_pct'], 1) ?>%</div>
        <div class="sub"><?= number_format((int)$analysis['edge_count']) ?> pts</div></div>
    <div class="metric"><div class="label">Out of tol</div>
        <div class="value bad"><?= number_format((float)$analysis['oot_pct'], 1) ?>%</div>
        <div class="sub"><?= number_format((int)$analysis['oot_count']) ?> pts</div></div>
    <div class="metric"><div class="label">Max deviation</div>
        <div class="value <?= $analysis['dist_stats']['max'] > $analysis['upper_tol'] ? 'bad' : 'good' ?>">
            +<?= number_format((float)$analysis['dist_stats']['max'], 4) ?></div>
        <div class="sub">at idx <?= (int)$analysis['max_point']['idx'] ?></div></div>
    <div class="metric"><div class="label">Mean deviation</div>
        <div class="value"><?= number_format((float)$analysis['dist_stats']['mean'], 4) ?></div>
        <div class="sub">σ = <?= number_format((float)$analysis['dist_stats']['stdev'], 4) ?></div></div>
    <div class="metric"><div class="label">Cpk (upper)</div>
        <div class="value <?= $analysis['cpk_upper'] >= 1.33 ? 'good' : ($analysis['cpk_upper'] >= 1.0 ? 'warn' : 'bad') ?>">
            <?= number_format((float)$analysis['cpk_upper'], 2) ?></div>
        <div class="sub">target ≥ 1.33</div></div>
</div>

<h2>2. Charts</h2>
<div id="plotProfile" class="plot"></div>
<div id="plotDeviation" class="plot"></div>
<div id="plotXY" class="plot"></div>
<div id="plotHistogram" class="plot"></div>
<div id="plotQuadrants" class="plot"></div>

<?php if (!empty($analysis['quad_stats'])): ?>
<h2>3. Quadrant breakdown</h2>
<table>
    <thead><tr><th>Quadrant</th><th>Points</th><th>Mean</th><th>Max</th><th>OOT</th><th>OOT %</th></tr></thead>
    <tbody>
        <?php foreach ($analysis['quad_stats'] as $k => $s): ?>
            <tr>
                <td><?= h($k) ?></td>
                <td><?= (int)$s['count'] ?></td>
                <td><?= number_format((float)$s['mean'], 4) ?></td>
                <td><?= number_format((float)$s['max'], 4) ?></td>
                <td><?= (int)$s['oot_count'] ?></td>
                <td class="<?= $s['oot_pct'] > 50 ? 'bad' : ($s['oot_pct'] > 10 ? 'warn' : 'good') ?>">
                    <?= number_format((float)$s['oot_pct'], 1) ?>%
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (!empty($analysis['oot_ranges'])): ?>
<h2>4. Contiguous out-of-tolerance bands</h2>
<table>
    <thead><tr><th>Start idx</th><th>End idx</th><th>Length</th></tr></thead>
    <tbody>
        <?php foreach (array_slice($analysis['oot_ranges'], 0, 20) as $r): ?>
            <tr><td><?= (int)$r[0] ?></td><td><?= (int)$r[1] ?></td><td><?= (int)$r[2] ?></td></tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php if (count($analysis['oot_ranges']) > 20): ?>
    <p class="muted small">… and <?= count($analysis['oot_ranges']) - 20 ?> more shorter bands.</p>
<?php endif; ?>
<?php endif; ?>

<h2>5. Executive summary</h2>
<?= cmm_report_executive_summary($analysis, $meta) ?>

<h2>6. Machining-performance assessment</h2>
<?= cmm_report_machining_assessment($analysis, $run['machine_type'] ?? 'vmc', !empty($run['is_multipass'])) ?>

<h2>7. Probable root causes</h2>
<?= cmm_report_root_causes($analysis, $run['machine_type'] ?? 'vmc', !empty($run['is_multipass'])) ?>

<h2>8. Recommended actions</h2>
<?= cmm_report_recommendations($analysis, $run['machine_type'] ?? 'vmc', !empty($run['is_multipass'])) ?>

<footer>
    Generated by MagDyn CMM Analyzer · Run #<?= (int)$run['id'] ?> ·
    Exported <?= h(date('d M Y H:i')) ?> IST
</footer>

<script>
// Embed points inline so the charts re-render without needing PHP.
const POINTS = <?= json_encode($points, JSON_UNESCAPED_SLASHES) ?>;
const UPPER_TOL = <?= json_encode((float)$run['upper_tol']) ?>;

const PLOT_CFG = { displaylogo: false, responsive: true, modeBarButtonsToRemove: ['lasso2d', 'select2d', 'autoScale2d'] };
const LAYOUT_BASE = {
    margin: { l: 60, r: 24, t: 36, b: 50 },
    paper_bgcolor: '#fff', plot_bgcolor: '#fff',
    font: { family: '-apple-system, "Segoe UI", Roboto, sans-serif', size: 12, color: '#1a2330' },
    hovermode: 'closest',
};

(function () {
    if (!POINTS.length) return;
    const pts = POINTS.slice().sort((a, b) => (a.idx | 0) - (b.idx | 0));
    const idx  = pts.map(p => p.idx | 0);
    const xs   = pts.map(p => +p.x_actual);
    const ys   = pts.map(p => +p.y_actual);
    const dist = pts.map(p => +p.dist_actual);
    const xdev = pts.map(p => +p.x_dev);
    const ydev = pts.map(p => +p.y_dev);
    const ut = UPPER_TOL;

    Plotly.newPlot('plotProfile', [{
        x: xs, y: ys, mode: 'markers', type: 'scattergl',
        marker: { size: 4, color: dist,
            colorscale: [[0, '#1d4ed8'], [0.5, '#16a34a'], [0.8, '#f59e0b'], [1, '#b3261e']],
            cmin: 0, cmax: ut * 2,
            colorbar: { title: 'Dist dev (in)', thickness: 12, len: 0.7 }, showscale: true },
        text: idx.map((i, k) => 'idx ' + i + '<br>dist ' + dist[k].toFixed(4)),
        hovertemplate: '%{text}<br>X=%{x:.4f}, Y=%{y:.4f}<extra></extra>',
    }], Object.assign({}, LAYOUT_BASE, {
        title: { text: 'Cam profile (X–Y), coloured by distance deviation', font: { size: 14 } },
        xaxis: { title: 'X (in)', zeroline: true, scaleanchor: 'y', scaleratio: 1 },
        yaxis: { title: 'Y (in)', zeroline: true }, height: 540,
    }), PLOT_CFG);

    const color = dist.map(d => d > ut ? '#b3261e' : (d >= ut - 0.00005 ? '#f59e0b' : '#137333'));
    Plotly.newPlot('plotDeviation', [
        { x: idx, y: dist, mode: 'markers', type: 'scattergl', marker: { size: 4, color },
          name: 'Dist deviation', hovertemplate: 'idx %{x}<br>dev %{y:.4f}<extra></extra>' },
        { x: [idx[0], idx[idx.length - 1]], y: [ut, ut], mode: 'lines',
          line: { color: '#b3261e', dash: 'dash', width: 1.5 }, name: 'USL' },
    ], Object.assign({}, LAYOUT_BASE, {
        title: { text: 'Distance deviation per measurement point', font: { size: 14 } },
        xaxis: { title: 'Measurement index' }, yaxis: { title: 'Distance deviation (in)' },
        height: 400, showlegend: true, legend: { orientation: 'h', y: -0.2 },
    }), PLOT_CFG);

    Plotly.newPlot('plotXY', [
        { x: idx, y: xdev, type: 'scattergl', mode: 'markers',
          marker: { size: 3, color: '#1d4ed8' }, name: 'X dev', xaxis: 'x', yaxis: 'y' },
        { x: idx, y: ydev, type: 'scattergl', mode: 'markers',
          marker: { size: 3, color: '#b06000' }, name: 'Y dev', xaxis: 'x2', yaxis: 'y2' },
    ], Object.assign({}, LAYOUT_BASE, {
        title: { text: 'Signed X and Y deviations per point', font: { size: 14 } },
        grid: { rows: 2, columns: 1, pattern: 'independent' },
        xaxis: { domain: [0, 1] }, yaxis: { title: 'X dev (in)' },
        xaxis2: { title: 'Measurement index' }, yaxis2: { title: 'Y dev (in)' },
        height: 480, showlegend: false,
    }), PLOT_CFG);

    Plotly.newPlot('plotHistogram', [{
        x: dist, type: 'histogram',
        marker: { color: '#1d4ed8', line: { color: '#fff', width: 1 } },
        xbins: { size: 0.00005 }, name: 'Count',
    }], Object.assign({}, LAYOUT_BASE, {
        title: { text: 'Distribution of distance deviation', font: { size: 14 } },
        xaxis: { title: 'Distance deviation (in)' }, yaxis: { title: 'Count of points' },
        height: 380,
        shapes: [{ type: 'line', xref: 'x', yref: 'paper', x0: ut, x1: ut, y0: 0, y1: 1,
                    line: { color: '#b3261e', dash: 'dash', width: 2 } }],
        annotations: [{ x: ut, y: 1, xref: 'x', yref: 'paper', text: 'USL', showarrow: false,
                         font: { color: '#b3261e' }, xanchor: 'left', yanchor: 'top' }],
    }), PLOT_CFG);

    const buckets = { Q1: [], Q2: [], Q3: [], Q4: [] };
    for (let i = 0; i < xs.length; i++) {
        const q = xs[i] >= 0 ? (ys[i] >= 0 ? 'Q1' : 'Q4') : (ys[i] >= 0 ? 'Q2' : 'Q3');
        buckets[q].push(dist[i]);
    }
    const labels = ['Q1 (+X,+Y)', 'Q2 (–X,+Y)', 'Q3 (–X,–Y)', 'Q4 (+X,–Y)'];
    const keys = ['Q1', 'Q2', 'Q3', 'Q4'];
    const ootPct = keys.map(k => {
        const a = buckets[k]; if (!a.length) return 0;
        return 100 * a.filter(d => d > ut).length / a.length;
    });
    const meanDev = keys.map(k => {
        const a = buckets[k]; if (!a.length) return 0;
        return a.reduce((s, v) => s + v, 0) / a.length;
    });
    Plotly.newPlot('plotQuadrants', [
        { x: labels, y: ootPct, type: 'bar',
          marker: { color: ootPct.map(p => p > 10 ? '#b3261e' : (p > 1 ? '#f59e0b' : '#137333')) },
          yaxis: 'y', xaxis: 'x',
          text: ootPct.map(p => p.toFixed(1) + '%'), textposition: 'outside' },
        { x: labels, y: meanDev, type: 'bar',
          marker: { color: '#1d4ed8' }, yaxis: 'y2', xaxis: 'x2',
          text: meanDev.map(v => v.toFixed(4)), textposition: 'outside' },
    ], Object.assign({}, LAYOUT_BASE, {
        title: { text: 'Per-quadrant breakdown', font: { size: 14 } },
        grid: { rows: 1, columns: 2, pattern: 'independent' },
        xaxis: { domain: [0, 0.46] }, yaxis: { title: '% OOT', rangemode: 'tozero' },
        xaxis2: { domain: [0.54, 1] }, yaxis2: { title: 'Mean dev (in)' },
        height: 380, showlegend: false,
        margin: { l: 60, r: 24, t: 50, b: 70 },
    }), PLOT_CFG);
})();
</script>

</body>
</html><?php
    exit;
}

// =============================================================
// HTML pages — require header
// =============================================================
require __DIR__ . '/includes/header.php';

if ($action === 'view' && $id > 0) {
    $run = cmm_load_run($id);
    if (!$run) {
        echo '<div class="alert alert-error">CMM run not found.</div>';
        require __DIR__ . '/includes/footer.php';
        exit;
    }
    render_cmm_view($run, $uid);
} else {
    render_cmm_list($uid);
}

require __DIR__ . '/includes/footer.php';

// =============================================================
// LIST
// =============================================================
function render_cmm_list($uid)
{
    // Two ways the page gets pre-populated:
    //   ?txn_id=N           — coming from the inventory ledger; the next
    //                         upload auto-links to that inv_txn
    //   ?attachment_id=N    — coming from a running-note PDF attachment;
    //                         JS will fetch the file and auto-submit
    //   ?link_txn_id=N      — companion to attachment_id when the note is
    //                         on an inv_txn (so the resulting run links to it)
    $linkTxnId    = (int)input('txn_id', 0);
    if (!$linkTxnId) $linkTxnId = (int)input('link_txn_id', 0);
    $attachmentId = (int)input('attachment_id', 0);

    $linkTxn = $linkTxnId > 0
        ? db_one("SELECT t.id, t.txn_type, t.txn_date, t.qty_delta,
                          i.code AS item_code, i.name AS item_name
                     FROM inv_txns t
                LEFT JOIN inv_items i ON i.id = t.item_id
                    WHERE t.id = ?", [$linkTxnId])
        : null;
    if ($linkTxnId > 0 && !$linkTxn) {
        // Invalid txn id — silently ignore the link param
        $linkTxnId = 0;
    }
    // For cmm.link gating later (only relevant when txn comes from URL)
    if ($linkTxnId > 0 && !permission_check('cmm', 'link')) {
        $linkTxnId = 0; $linkTxn = null;
    }

    // Resolve attachment context, gated by ENTITY-level view permission
    // (the same check note_attach.php does). If the attachment is bad or
    // the user can't access it, treat as no-op.
    $attachment = null;
    if ($attachmentId > 0) {
        $row = db_one(
            'SELECT na.*, n.entity_type, n.entity_id, n.is_deleted, n.redacted_at
               FROM note_attachments na
               JOIN notes n ON n.id = na.note_id
              WHERE na.id = ?',
            [$attachmentId]
        );
        if ($row && (int)$row['is_deleted'] === 0 && empty($row['redacted_at'])
            && preg_match('/\.pdf$/i', (string)$row['filename'])) {
            // Mirror the perm map from note_attach.php
            $viewPerm = [
                'asset'     => ['asset',                'view'],
                'asset_txn' => ['asset',                'view'],
                'inv_item'  => ['inventory_view_items', 'view'],
                'inv_txn'   => ['inventory_view_items', 'view'],
                'inspection'=> ['inspection',           'view'],
                'inspection_template' => ['inspection', 'view'],
                'document'  => ['documents_internal',   'view'],
            ];
            $p = $viewPerm[$row['entity_type']] ?? null;
            if ($p && permission_check($p[0], $p[1])) {
                $attachment = $row;
                // If note is on an inv_txn and the user can link, set the
                // link_txn_id automatically (running-notes integration spec)
                if ($attachment['entity_type'] === 'inv_txn'
                    && !$linkTxnId
                    && permission_check('cmm', 'link')) {
                    $linkTxnId = (int)$attachment['entity_id'];
                    $linkTxn = db_one(
                        "SELECT t.id, t.txn_type, t.txn_date, t.qty_delta,
                                i.code AS item_code, i.name AS item_name
                           FROM inv_txns t
                      LEFT JOIN inv_items i ON i.id = t.item_id
                          WHERE t.id = ?",
                        [$linkTxnId]
                    );
                }
            }
        }
    }

    $canUpload = permission_check('cmm', 'upload');
    $canDelete = permission_check('cmm', 'delete');
?>
<div class="page-head">
    <div>
        <h1>⌀ CMM Analyzer</h1>
        <p class="muted">Drop a ZEISS Calypso CMM PDF — the file is parsed in your browser, only the extracted measurement data is sent to the server.</p>
    </div>
</div>

<?php if ($attachment && $canUpload): ?>
    <div class="card" style="margin-bottom: 18px; background: #ecfdf5; border-left: 3px solid #059669;">
        <div class="card-body">
            <strong>📐 Auto-analyzing attachment</strong> —
            <span style="font-family: monospace;"><?= h($attachment['filename']) ?></span>
            (<?= number_format((int)$attachment['size_bytes'] / 1024, 1) ?> KB).
            <?php if ($linkTxn): ?>
                Will auto-link to <strong>inv_txn #<?= (int)$linkTxn['id'] ?></strong>
                (<?= h($linkTxn['item_code']) ?>).
            <?php endif; ?>
            <div class="muted small" style="margin-top: 4px;">
                Fetching the file and starting analysis — you'll be redirected to the run when complete.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($linkTxn && !$attachment): ?>
    <div class="card" style="margin-bottom: 18px; background: #f0f9ff; border-left: 3px solid var(--info, #0284c7);">
        <div class="card-body">
            <strong>Linking mode</strong> — the next upload will auto-link to
            <strong>inv_txn #<?= (int)$linkTxn['id'] ?></strong>
            (<?= h($linkTxn['txn_type']) ?>, <?= h($linkTxn['txn_date']) ?>,
            item <?= h($linkTxn['item_code'] . ' — ' . $linkTxn['item_name']) ?>,
            qty <?= h($linkTxn['qty_delta']) ?>).
            <a class="btn btn-ghost btn-xs" href="<?= h(url('/cmm.php')) ?>" style="margin-left:8px;">Cancel link</a>
        </div>
    </div>
<?php endif; ?>

<?php if ($canUpload): ?>
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Upload a CMM report</h3></div>
    <div class="card-body">
        <p class="muted">Format: ZEISS Calypso Curve Form scan (X/Y/Z/Dist columns + tolerance band). If the PDF is a scan with no embedded text, the app falls back to OCR (slower).</p>

        <form id="cmmUploadForm" autocomplete="off">
            <input type="hidden" id="cmmLinkTxnId" value="<?= (int)$linkTxnId ?>">

            <div class="field" style="margin-bottom: 14px;">
                <label for="cmmMachineType">Machine type</label>
                <select id="cmmMachineType" name="machine_type" autocomplete="off" style="max-width: 320px;">
                    <?php foreach (cmm_machine_types() as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $key === 'vmc' ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">Selects which root-cause and recommendation prose to render. Math is identical across all types.</span>
            </div>

            <div class="field" id="cmmMultipassRow" hidden style="margin-bottom: 14px; padding-left: 4px;">
                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500;">
                    <input type="checkbox" id="cmmIsMultipass" name="is_multipass" autocomplete="off">
                    Multi-pass cut (rough + one or more skim passes)
                </label>
                <span class="field-hint" style="display:block; margin-top:4px;">When checked, the analysis prose calls out skim-pass-specific causes (e.g. final-pass offset, insufficient skim aggressiveness) instead of single-pass-only causes.</span>
            </div>

            <label id="cmmDropZone" style="display:block; padding:30px; border:2px dashed var(--border-strong, #d1d5db); border-radius:8px; text-align:center; cursor:pointer; background:#fafafa;">
                <input type="file" id="cmmPdfInput" accept="application/pdf,.pdf" style="position:absolute; width:1px; height:1px; opacity:0;">
                <div style="font-size:32px;">📄</div>
                <div style="font-weight:600;">Click to choose a PDF</div>
                <div class="muted small">or drag-and-drop here</div>
                <div id="cmmFileName" style="margin-top:8px; font-family:monospace; font-size:13px;"></div>
            </label>
            <div class="form-actions" style="margin-top: 14px;">
                <button type="submit" class="btn btn-primary" id="cmmUploadBtn" disabled>Analyze</button>
            </div>
            <div id="cmmStatus" class="muted small" style="margin-top: 10px;"></div>
            <div id="cmmOcrProgress" hidden style="margin-top: 10px;">
                <div style="height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;">
                    <div id="cmmOcrFill" style="height: 100%; background: var(--primary); width: 0; transition: width 0.2s;"></div>
                </div>
                <div id="cmmOcrLabel" class="muted small" style="margin-top: 4px;"></div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
// Build the run-history datatable
require_once __DIR__ . '/includes/datatable.php';

$dtCfg = [
    'id'       => 'cmm-runs',
    'title'    => 'Run history',
    'base_sql' => "SELECT r.id, r.filename, r.uploaded_at, r.report_date, r.part_number,
                          r.machine_type, r.is_multipass, r.point_count, r.oot_count, r.cpk_upper, r.verdict, r.comment,
                          u.full_name AS uploaded_by_name,
                          (SELECT COUNT(*) FROM inv_txn_cmm_runs xt WHERE xt.cmm_run_id = r.id) AS txn_link_count
                     FROM cmm_runs r
                LEFT JOIN users u ON u.id = r.uploaded_by",
    'columns'  => [
        ['key'=>'id',           'label'=>'#',         'sortable'=>true,  'searchable'=>false, 'sql_col'=>'r.id',           'th_class'=>'r','td_class'=>'r'],
        ['key'=>'filename',     'label'=>'Filename',  'sortable'=>true,  'searchable'=>true,  'sql_col'=>'r.filename'],
        ['key'=>'uploaded_at',  'label'=>'Uploaded',  'sortable'=>true,  'searchable'=>true,  'sql_col'=>'r.uploaded_at'],
        ['key'=>'part_number',  'label'=>'Part #',    'sortable'=>true,  'searchable'=>true,  'sql_col'=>'r.part_number'],
        ['key'=>'point_count',  'label'=>'Points',    'sortable'=>true,  'searchable'=>false, 'sql_col'=>'r.point_count',  'th_class'=>'r','td_class'=>'r'],
        ['key'=>'oot_count',    'label'=>'OOT',       'sortable'=>true,  'searchable'=>false, 'sql_col'=>'r.oot_count',    'th_class'=>'r','td_class'=>'r'],
        ['key'=>'cpk_upper',    'label'=>'Cpk(U)',    'sortable'=>true,  'searchable'=>false, 'sql_col'=>'r.cpk_upper',    'th_class'=>'r','td_class'=>'r'],
        ['key'=>'verdict',      'label'=>'Verdict',   'sortable'=>true,  'searchable'=>false, 'sql_col'=>'r.verdict'],
        ['key'=>'comment',      'label'=>'Notes',     'sortable'=>false, 'searchable'=>true,  'sql_col'=>'r.comment'],
        ['key'=>'txn_link_count','label'=>'Txns',     'sortable'=>false, 'searchable'=>false],
        ['key'=>'_actions',     'label'=>'',          'sortable'=>false, 'searchable'=>false, 'th_class'=>'r', 'td_class'=>'r nowrap'],
    ],
    'default_sort' => ['uploaded_at', 'desc'],
];

$rowRenderer = function ($r) use ($canDelete) {
    $cmt = isset($r['comment']) ? trim((string)$r['comment']) : '';
    $cmtFlat = $cmt === '' ? '' : preg_replace('/\s+/', ' ', $cmt);
    $cmtCell = '<span class="muted small">—</span>';
    if ($cmtFlat !== '') {
        $cmtExcerpt = mb_substr($cmtFlat, 0, 120);
        if (mb_strlen($cmtFlat) > 120) $cmtExcerpt .= '…';
        $cmtCell = '<span class="muted small" title="' . h($cmtFlat) . '" style="cursor: help;">'
                 . h($cmtExcerpt) . '</span>';
    }

    $mt = $r['machine_type'] ?? 'vmc';
    $mtLabels = ['vmc'=>'VMC','wedm'=>'WEDM','manual'=>'Manual','grinding'=>'Grinding','other'=>'Other'];
    $mtLabel = isset($mtLabels[$mt]) ? $mtLabels[$mt] : strtoupper($mt);
    // Annotate WEDM rows that were marked multi-pass at upload time.
    if ($mt === 'wedm' && !empty($r['is_multipass'])) {
        $mtLabel .= ' · multi-pass';
    }

    $uploadedCell = '<span class="muted small">' . h(dt_display($r['uploaded_at'])) . '</span>';
    if (!empty($r['uploaded_by_name'])) {
        $uploadedCell .= '<br><span class="muted small">' . h($r['uploaded_by_name']) . '</span>';
    }

    $txnCell = (int)$r['txn_link_count'] > 0
        ? '<span class="pill pill-info">' . (int)$r['txn_link_count'] . ' linked</span>'
        : '<span class="muted small">—</span>';

    $partCell = h($r['part_number'] ?: '—')
              . '<br><span class="pill pill-neutral" style="font-size:10px;">' . h($mtLabel) . '</span>';

    $actions  = '<a class="btn btn-icon" href="' . h(url('/cmm.php?action=view&id=' . (int)$r['id'])) . '"'
              . ' title="View" aria-label="View">👁 <span class="dt-action-label">View</span></a> ';
    if ($canDelete) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/cmm.php?action=delete&id=' . (int)$r['id'])) . '"'
                  . ' onsubmit="return confirm(\'Delete CMM run #' . (int)$r['id'] . ' (' . h(addslashes($r['filename'])) . ')? This removes all points and any links to inv_txns. Cannot be undone.\');">'
                  . csrf_field()
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
    }

    return [
        'id'             => '<strong>' . (int)$r['id'] . '</strong>',
        'filename'       => h($r['filename']),
        'uploaded_at'    => $uploadedCell,
        'part_number'    => $partCell,
        'point_count'    => (int)$r['point_count'],
        'oot_count'      => (int)$r['oot_count'],
        'cpk_upper'      => $r['cpk_upper'] !== null ? number_format((float)$r['cpk_upper'], 2) : '—',
        'verdict'        => '<span class="pill ' . h(cmm_verdict_pill($r['verdict'])) . '">' . h($r['verdict']) . '</span>',
        'comment'        => $cmtCell,
        'txn_link_count' => $txnCell,
        '_actions'       => dt_actions_wrap($actions),
    ];
};

$dt = data_table_run($dtCfg, $rowRenderer);
data_table_render($dtCfg, $dt, $rowRenderer);
?>

<script>
    window.CMM_SAVE_URL = <?= json_encode(url('/cmm.php?action=save')) ?>;
    window.CMM_VIEW_URL = <?= json_encode(url('/cmm.php?action=view&id=')) ?>;
    <?php if ($attachment && $canUpload): ?>
    window.CMM_AUTO_ATTACHMENT = {
        url:      <?= json_encode(url('/note_attach.php?id=' . (int)$attachment['id'])) ?>,
        filename: <?= json_encode($attachment['filename']) ?>,
        size:     <?= (int)$attachment['size_bytes'] ?>
    };
    <?php endif; ?>
</script>
<script src="<?= h(url('/assets/js/cmm.js')) ?>"></script>
<?php
}

// =============================================================
// VIEW (single run)
// =============================================================
function render_cmm_view($run, $uid)
{
    $analysis = json_decode($run['analysis_json'], true);
    $meta = [
        'report_date'  => $run['report_date'],
        'part_number'  => $run['part_number'],
        'cmm_type'     => $run['cmm_type'],
        'operator'     => $run['operator'],
        'feature_name' => $run['feature_name'],
    ];
    $linkedTxns = cmm_txns_for_run((int)$run['id']);
    $canComment = permission_check('cmm', 'comment');
    $canLink    = permission_check('cmm', 'link');
    $canDelete  = permission_check('cmm', 'delete');
    $canUpload  = permission_check('cmm', 'upload');
?>
<div class="page-head">
    <div>
        <h1>
            CMM Run #<?= (int)$run['id'] ?>
            <span class="pill <?= h(cmm_verdict_pill($run['verdict'])) ?>" style="margin-left:10px; font-size:11px;">
                <?= h($run['verdict']) ?>
            </span>
        </h1>
        <p class="muted"><?= h($run['filename']) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/cmm.php')) ?>">← All runs</a>
        <a class="btn btn-ghost" href="<?= h(url('/cmm.php?action=export&id=' . (int)$run['id'])) ?>"
           title="Download a self-contained HTML report (includes interactive charts)">
            ⬇ Save HTML
        </a>
        <button type="button" class="btn btn-ghost" onclick="window.print()"
                title="Open the browser print dialog — use 'Save as PDF' to keep a PDF copy">
            🖨 Print / Save PDF
        </button>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= h(url('/cmm.php?action=delete&id=' . (int)$run['id'])) ?>" style="display:inline;"
                  onsubmit="return confirm('Delete this run? This cannot be undone.')">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- VERDICT BANNER -->
<div class="card" style="margin-bottom: 18px;">
    <div class="card-body" style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
        <span class="pill <?= h(cmm_verdict_pill($run['verdict'])) ?>" style="font-size:18px; padding:8px 18px;">
            <?= h($run['verdict']) ?>
        </span>
        <div>
            <h3 style="margin:0;">
                <?php if ($run['verdict'] === 'PASS'): ?>Part meets print
                <?php elseif ($run['verdict'] === 'MARGINAL'): ?>Borderline — review recommended
                <?php else: ?>Does not meet print
                <?php endif; ?>
            </h3>
            <p class="muted small" style="margin: 4px 0 0;">
                <?= number_format((int)$run['oot_count']) ?> of <?= number_format((int)$run['point_count']) ?>
                points out of tolerance (<?= number_format((float)$analysis['oot_pct'], 1) ?>%)
                · Cpk(U) = <?= number_format((float)$analysis['cpk_upper'], 2) ?>
            </p>
        </div>
    </div>
</div>

<!-- METADATA -->
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Report header</h3></div>
    <div class="card-body">
        <table class="data-table">
            <tbody>
                <tr><th>Source file</th><td><?= h($run['filename']) ?></td>
                    <th>Uploaded</th><td><?= h(dt_display($run['uploaded_at'])) ?></td></tr>
                <tr><th>CMM software</th><td>ZEISS Calypso</td>
                    <th>CMM hardware</th><td><?= h($run['cmm_type'] ?: '—') ?></td></tr>
                <tr><th>Report date</th><td><?= h($run['report_date'] ?: '—') ?></td>
                    <th>Operator</th><td><?= h($run['operator'] ?: '—') ?></td></tr>
                <tr><th>Part number</th><td><?= h($run['part_number'] ?: '—') ?></td>
                    <th>Feature</th><td><?= h($run['feature_name'] ?: '—') ?></td></tr>
                <tr><th>Point count</th><td><?= (int)$run['point_count'] ?></td>
                    <th>Z (probe plane)</th><td><?= $run['z_value'] !== null ? number_format((float)$run['z_value'], 4) : '—' ?></td></tr>
                <tr><th>Upper tolerance</th><td>+<?= number_format((float)$run['upper_tol'], 4) ?></td>
                    <th>Lower tolerance</th><td><?= number_format((float)$run['lower_tol'], 4) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- NOTES -->
<div class="card" id="cmmCommentCard" data-run-id="<?= (int)$run['id'] ?>" style="margin-bottom: 18px;">
    <div class="card-head" style="display:flex; align-items:center; justify-content:space-between;">
        <h3 style="margin:0; font-size:15px;">Notes</h3>
        <?php if ($canComment): ?>
        <div>
            <button type="button" class="btn btn-ghost btn-sm" id="cmmCommentEditBtn">Edit</button>
            <button type="button" class="btn btn-primary btn-sm" id="cmmCommentSaveBtn" hidden>Save</button>
            <button type="button" class="btn btn-ghost btn-sm" id="cmmCommentCancelBtn" hidden>Cancel</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php $cmt = (string)($run['comment'] ?? ''); ?>
        <div id="cmmCommentView" class="<?= $cmt === '' ? 'muted' : '' ?>">
            <?php if ($cmt === ''): ?>
                <em>No notes yet.<?= $canComment ? ' Click <strong>Edit</strong> to add some.' : '' ?></em>
            <?php else: ?>
                <?= nl2br(h($cmt)) ?>
            <?php endif; ?>
        </div>
        <?php if ($canComment): ?>
        <div id="cmmCommentEdit" hidden>
            <textarea id="cmmCommentTextarea" maxlength="8000"
                placeholder="Add notes, follow-up actions, ticket numbers, links — anything you want to remember about this run."
                style="width:100%; min-height:120px; font-family:inherit;"><?= h($cmt) ?></textarea>
            <div class="muted small" style="margin-top: 4px;">
                <span id="cmmCommentCharCount"></span>
                <span id="cmmCommentStatus" style="margin-left: 10px;"></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- LINKED INV_TXNS -->
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Linked inventory transactions <span class="muted small">(<?= count($linkedTxns) ?>)</span></h3></div>
    <div class="card-body">
        <?php if ($canLink): ?>
            <form method="post" action="<?= h(url('/cmm.php?action=link_txn&id=' . (int)$run['id'])) ?>"
                  style="margin-bottom: 14px;">
                <?= csrf_field() ?>
                <div class="form-grid-2">
                    <div class="field">
                        <label>Inv txn id</label>
                        <input type="number" name="txn_id" min="1" placeholder="e.g. 1234" required>
                        <span class="field-hint">Find the id on the inventory ledger.</span>
                    </div>
                    <div class="field">
                        <label>Note <span class="muted small">(optional)</span></label>
                        <input type="text" name="note" maxlength="500">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">+ Link txn</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (empty($linkedTxns)): ?>
            <p class="muted">No inv_txns linked.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Txn #</th><th>Type</th><th>Date</th><th>Item</th><th>Location</th><th>Delta</th><th>Linked</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($linkedTxns as $t): ?>
                        <tr>
                            <td><strong>#<?= (int)$t['id'] ?></strong></td>
                            <td><?= h($t['txn_type']) ?></td>
                            <td><?= h($t['txn_date']) ?></td>
                            <td><?= h(($t['item_code'] ?: '?') . ' — ' . ($t['item_name'] ?: '')) ?></td>
                            <td><?= h($t['location_name'] ?: '—') ?></td>
                            <td class="r"><?= h($t['qty_delta']) ?></td>
                            <td><span class="muted small"><?= h(dt_display($t['linked_at'])) ?></span>
                                <?php if ($t['linked_by_name']): ?>
                                    <br><span class="muted small"><?= h($t['linked_by_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($t['link_note']): ?>
                                    <br><span class="muted small"><?= h($t['link_note']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canLink): ?>
                                    <form method="post" action="<?= h(url('/cmm.php?action=unlink_txn&id=' . (int)$run['id'])) ?>"
                                          style="display:inline;" onsubmit="return confirm('Unlink this txn?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-xs">×</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- KEY METRICS -->
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">1. Key metrics</h3></div>
    <div class="card-body">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px;">
            <?php
            $metrics = [
                ['In tolerance',    number_format((float)$analysis['in_tol_pct'], 1) . '%',
                 number_format((int)$analysis['in_tol_count']) . ' / ' . number_format((int)$analysis['N']),
                 'pill-success'],
                ['At edge',         number_format((float)$analysis['edge_pct'], 1) . '%',
                 number_format((int)$analysis['edge_count']) . ' pts', 'pill-warning'],
                ['Out of tol',      number_format((float)$analysis['oot_pct'], 1) . '%',
                 number_format((int)$analysis['oot_count']) . ' pts', 'pill-danger'],
                ['Max deviation',   '+' . number_format((float)$analysis['dist_stats']['max'], 4),
                 'at idx ' . (int)$analysis['max_point']['idx'],
                 $analysis['dist_stats']['max'] > $analysis['upper_tol'] ? 'pill-danger' : 'pill-success'],
                ['Mean deviation',  number_format((float)$analysis['dist_stats']['mean'], 4),
                 'σ = ' . number_format((float)$analysis['dist_stats']['stdev'], 4), 'pill-neutral'],
                ['Cpk (upper)',     number_format((float)$analysis['cpk_upper'], 2),
                 'target ≥ 1.33',
                 $analysis['cpk_upper'] >= 1.33 ? 'pill-success' :
                 ($analysis['cpk_upper'] >= 1.0 ? 'pill-warning' : 'pill-danger')],
            ];
            foreach ($metrics as $m): ?>
                <div style="padding: 12px; background: #f9fafb; border-radius: 6px;">
                    <div class="muted small"><?= h($m[0]) ?></div>
                    <div style="font-size: 22px; font-weight: 700; margin-top: 4px;">
                        <span class="pill <?= h($m[3]) ?>" style="font-size: 14px; padding: 2px 8px;"><?= h($m[1]) ?></span>
                    </div>
                    <div class="muted small" style="margin-top: 4px;"><?= h($m[2]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- CHARTS -->
<?php
$chartSections = [
    ['plotProfile',    '2. Cam profile (deviation heat-map)',         'Coloured by per-point deviation from the nominal curve. Hover any segment for details.'],
    ['plotDeviation',  '3. Deviation along the profile',              'Each probed point in sequence with the tolerance band overlaid.'],
    ['plotXY',         '4. Signed axis deviation (Actual − Nominal)', 'Reveals systematic origin offsets and cutter-comp drift.'],
    ['plotHistogram',  '5. Deviation distribution',                   ''],
    ['plotQuadrants',  '6. Quadrant breakdown',                       'If defects cluster in one quadrant, the problem is local (setup/toolpath) not global.'],
];
foreach ($chartSections as $cs): ?>
    <div class="card" style="margin-bottom: 18px;">
        <div class="card-head"><h3 style="margin:0; font-size:15px;"><?= h($cs[1]) ?></h3></div>
        <div class="card-body">
            <?php if ($cs[2]): ?><p class="muted small" style="margin-top:0;"><?= h($cs[2]) ?></p><?php endif; ?>
            <div id="<?= h($cs[0]) ?>" style="width:100%; min-height: 360px;"></div>
        </div>
    </div>
<?php endforeach; ?>

<!-- QUADRANT TABLE -->
<?php if (!empty($analysis['quad_stats'])): ?>
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Quadrant stats</h3></div>
    <div class="card-body">
        <table class="data-table">
            <thead><tr><th>Quadrant</th><th>Points</th><th>Mean</th><th>Max</th><th>OOT</th><th>OOT %</th></tr></thead>
            <tbody>
                <?php foreach ($analysis['quad_stats'] as $k => $s): ?>
                    <tr>
                        <td><?= h($k) ?></td>
                        <td><?= (int)$s['count'] ?></td>
                        <td><?= number_format((float)$s['mean'], 4) ?></td>
                        <td><?= number_format((float)$s['max'], 4) ?></td>
                        <td><?= (int)$s['oot_count'] ?></td>
                        <td>
                            <span class="pill <?= $s['oot_pct'] > 50 ? 'pill-danger' : ($s['oot_pct'] > 10 ? 'pill-warning' : 'pill-success') ?>">
                                <?= number_format((float)$s['oot_pct'], 1) ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- OOT BANDS -->
<?php if (!empty($analysis['oot_ranges'])): ?>
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">7. Contiguous out-of-tolerance bands</h3></div>
    <div class="card-body">
        <table class="data-table">
            <thead><tr><th>Start idx</th><th>End idx</th><th>Length</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($analysis['oot_ranges'], 0, 10) as $r): ?>
                    <tr><td><?= (int)$r[0] ?></td><td><?= (int)$r[1] ?></td><td><?= (int)$r[2] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($analysis['oot_ranges']) > 10): ?>
            <p class="muted small">… and <?= count($analysis['oot_ranges']) - 10 ?> more shorter bands.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- PROSE BLOCKS -->
<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">8. Executive summary</h3></div>
    <div class="card-body"><?= cmm_report_executive_summary($analysis, $meta) ?></div>
</div>

<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">9. Machining-performance assessment</h3></div>
    <div class="card-body"><?= cmm_report_machining_assessment($analysis, $run['machine_type'] ?? 'vmc', !empty($run['is_multipass'])) ?></div>
</div>

<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">10. Probable root causes (ranked)</h3></div>
    <div class="card-body"><?= cmm_report_root_causes($analysis, $run['machine_type'] ?? 'vmc', !empty($run['is_multipass'])) ?></div>
</div>

<div class="card" style="margin-bottom: 18px;">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">11. Recommended actions</h3></div>
    <div class="card-body"><?= cmm_report_recommendations($analysis, $run['machine_type'] ?? 'vmc', !empty($run['is_multipass'])) ?></div>
</div>

<!-- Plotly + cmm.js for charts + comment editing -->
<script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
<script>
    window.CMM_RUN_ID      = <?= (int)$run['id'] ?>;
    window.CMM_API_RUN     = <?= json_encode(url('/cmm.php?action=api_run&id=')) ?>;
    window.CMM_API_CMT     = <?= json_encode(url('/cmm.php?action=api_comment&id=')) ?>;
    window.CMM_UPPER_TOL   = <?= json_encode((float)$run['upper_tol']) ?>;
</script>
<script src="<?= h(url('/assets/js/cmm.js')) ?>"></script>
<?php
}
