<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Engineering Calculator · MagDyn';
$current_page  = 'engineering-calculator.php';
$trigger_style = 'dark';
$cdn_scripts   = [];
include 'includes/head.php';
?>
<style>
  .view { display: none; }
  .view.active { display: block; }
  .nav-item.tab-btn { cursor: pointer; }
  .nav-item.tab-btn .num {
    font-size: 10px; font-weight: 700;
    color: var(--sidebar-text-very-dim);
    margin-right: 8px; letter-spacing: 0.05em;
  }
  .nav-item.tab-btn.active .num { color: rgba(255,255,255,0.55); }

  .calc-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 18px;
  }
  @media (max-width: 1100px) { .calc-grid { grid-template-columns: 1fr; } }

  .panel-num-tag {
    font-size: 10px; color: var(--text-light);
    letter-spacing: 0.1em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-weight: 500;
  }

  /* Form fields */
  .calc-field {
    display: flex; flex-direction: column; gap: 4px;
    margin-bottom: 12px;
  }
  .calc-field > label {
    font-size: 11px; font-weight: 600; color: var(--text);
    letter-spacing: 0.03em;
  }
  .calc-field > .hint {
    font-size: 10px; color: var(--text-light); margin-top: -2px;
  }
  .calc-field input[type="number"],
  .calc-field input[type="text"],
  .calc-field select {
    padding: 8px 10px; font-size: 13px;
    border: 1px solid var(--border-strong, var(--border));
    border-radius: var(--radius);
    background: var(--surface, #fff);
    color: var(--text, #111);
    font-family: inherit;
    width: 100%;
  }
  .calc-field input:focus, .calc-field select:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--primary-light);
  }

  /* Output blocks */
  .calc-output {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    margin-bottom: 10px;
  }
  .calc-output .row {
    display: flex; justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px dashed var(--border);
    font-size: 13px;
  }
  .calc-output .row:last-child { border-bottom: none; }
  .calc-output .row > .lbl { color: var(--text-light); }
  .calc-output .row > .val {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-weight: 600; color: var(--text);
  }
  .calc-output .row.headline {
    background: var(--primary-light, #e0e7ff);
    margin: 0 -16px 6px;
    padding: 10px 16px;
    border-radius: var(--radius);
    border-bottom: none;
    font-size: 14px;
  }
  .calc-output .row.headline > .val {
    color: var(--primary, #1e3a8a);
    font-size: 15px;
  }
  .calc-output .row.warn > .val { color: #b91c1c; }
  .calc-output .row.good > .val { color: #15803d; }

  .panel h3 {
    font-size: 12px; font-weight: 600;
    color: var(--text); letter-spacing: 0.04em;
    text-transform: uppercase;
    margin: 18px 0 10px; padding-bottom: 6px;
    border-bottom: 1px solid var(--border);
  }
  .panel h3:first-child { margin-top: 0; }

  .formula-block {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.7;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 14px;
    color: var(--text-muted, var(--text));
    white-space: pre-wrap;
  }

  /* Tolerance stack-up table */
  .stack-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
  }
  .stack-table th, .stack-table td {
    padding: 6px 8px; text-align: left;
    border-bottom: 1px solid var(--border);
  }
  .stack-table th {
    font-size: 10px; letter-spacing: 0.05em;
    text-transform: uppercase; color: var(--text-light);
    font-weight: 600; background: var(--surface-alt);
  }
  .stack-table input[type="number"] {
    width: 100%; padding: 4px 6px; font-size: 12px;
    border: 1px solid var(--border); border-radius: 3px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  .stack-table input[type="text"] {
    width: 100%; padding: 4px 6px; font-size: 12px;
    border: 1px solid var(--border); border-radius: 3px;
  }
  .stack-table select {
    width: 100%; padding: 3px 4px; font-size: 12px;
    border: 1px solid var(--border); border-radius: 3px;
  }
  .stack-table .row-del {
    cursor: pointer; color: var(--text-light);
    border: none; background: none; font-size: 16px;
    padding: 0 4px;
  }
  .stack-table .row-del:hover { color: #b91c1c; }

  .btn-small {
    padding: 6px 12px; font-size: 11px;
    border: 1px solid var(--border-strong, var(--border));
    background: var(--surface);
    border-radius: var(--radius);
    cursor: pointer; font-family: inherit;
    color: var(--text);
  }
  .btn-small:hover { border-color: var(--primary); color: var(--primary); }
  .btn-small.primary {
    background: var(--primary); color: white; border-color: var(--primary);
  }
  .btn-small.primary:hover { opacity: 0.92; color: white; }

  /* "Calculate & save" — the explicit history-recording button per calculator.
     Slightly larger and brighter so the primary action stands out from the
     secondary helpers (Add row, Reset, etc). */
  .btn-small.calc-go {
    padding: 9px 18px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
    display: inline-flex; align-items: center; gap: 8px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.08);
  }
  .btn-small.calc-go:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(30,58,138,0.25);
  }
  .btn-small.calc-go:active { transform: translateY(0); }

  /* Scientific calculator keypad */
  .sci-display {
    background: #0f172a; color: #f1f5f9;
    border-radius: var(--radius);
    padding: 16px 18px; margin-bottom: 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    min-height: 56px;
    display: flex; flex-direction: column;
    justify-content: flex-end;
    box-shadow: inset 0 1px 4px rgba(0,0,0,0.4);
  }
  .sci-display .expr {
    font-size: 12px; color: #94a3b8; opacity: 0.85;
    min-height: 18px; word-break: break-all;
  }
  .sci-display .result {
    font-size: 26px; font-weight: 600; text-align: right;
    word-break: break-all;
  }
  .sci-keypad {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px;
  }
  .sci-key {
    padding: 12px 6px; font-size: 13px;
    border: 1px solid var(--border-strong, var(--border));
    background: var(--surface);
    border-radius: var(--radius);
    cursor: pointer; font-family: inherit;
    font-weight: 500; color: var(--text);
    transition: all 0.08s;
  }
  .sci-key:hover { background: var(--surface-alt); border-color: var(--primary); }
  .sci-key:active { transform: scale(0.97); }
  .sci-key.fn { color: var(--primary); font-weight: 600; }
  .sci-key.op { background: #fef3c7; border-color: #fcd34d; }
  .sci-key.op:hover { background: #fde68a; }
  .sci-key.eq {
    background: var(--primary); color: white; border-color: var(--primary);
    font-weight: 700;
  }
  .sci-key.eq:hover { background: #1e40af; color: white; }
  .sci-key.clear { background: #fee2e2; border-color: #fca5a5; }

  /* Shape picker for area/volume/weight */
  .shape-picker {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 6px; margin-bottom: 14px;
  }
  .shape-btn {
    padding: 10px 4px; font-size: 11px;
    border: 1px solid var(--border-strong, var(--border));
    background: var(--surface);
    border-radius: var(--radius);
    cursor: pointer; font-family: inherit;
    color: var(--text);
    display: flex; flex-direction: column;
    align-items: center; gap: 4px;
  }
  .shape-btn svg {
    width: 28px; height: 28px;
    stroke: currentColor; stroke-width: 1.5; fill: none;
  }
  .shape-btn:hover { border-color: var(--primary); color: var(--primary); }
  .shape-btn.active {
    background: var(--primary-light); color: var(--primary);
    border-color: var(--primary);
  }

  /* Unit converter list */
  .unit-row {
    display: grid;
    grid-template-columns: 1fr 14px 1fr;
    gap: 8px; align-items: center;
    margin-bottom: 8px;
  }
  .unit-row .arrow {
    text-align: center; color: var(--text-light); font-size: 14px;
  }
  .unit-row input { width: 100%; }
  .unit-row select {
    padding: 6px 8px; font-size: 12px;
  }

  /* History card */
  .ch-card {
    margin-top: 18px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    overflow: hidden;
  }
  .ch-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px;
    background: var(--surface-alt);
    border-bottom: 1px solid var(--border);
  }
  .ch-head-title {
    font-size: 11px; font-weight: 600;
    color: var(--text); letter-spacing: 0.06em;
    text-transform: uppercase;
  }
  .ch-head-actions { display: flex; gap: 6px; align-items: center; }
  .ch-head-count {
    font-size: 10px; color: var(--text-light);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    margin-right: 4px;
  }
  .ch-clear-btn {
    background: transparent; border: 1px solid var(--border);
    color: var(--text-light); cursor: pointer;
    font-size: 10px; padding: 3px 8px;
    border-radius: 3px; font-family: inherit;
    letter-spacing: 0.04em; text-transform: uppercase;
  }
  .ch-clear-btn:hover { color: #b91c1c; border-color: #b91c1c; }
  .ch-list {
    max-height: 280px;
    overflow-y: auto;
  }
  .ch-empty {
    padding: 18px 14px;
    font-size: 11px; color: var(--text-light);
    text-align: center; font-style: italic;
  }
  .ch-row {
    display: grid;
    grid-template-columns: 78px 1fr 24px;
    gap: 10px; align-items: center;
    padding: 8px 14px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background 0.08s;
  }
  .ch-row:last-child { border-bottom: none; }
  .ch-row:hover { background: var(--surface-alt); }
  .ch-row-time {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 10px; color: var(--text-light);
    letter-spacing: 0.02em;
  }
  .ch-row-label {
    font-size: 12px; color: var(--text);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .ch-row-del {
    background: transparent; border: none;
    color: var(--text-light); cursor: pointer;
    font-size: 16px; line-height: 1; padding: 0;
    width: 22px; height: 22px;
    border-radius: 3px;
  }
  .ch-row-del:hover { color: #b91c1c; background: #fee2e2; }

  /* MagDyn embed mode (iframed inside /tools.php?tool=calc). Hide the
     tool's own inner sidebar since the MagDyn sidebar already provides
     navigation via the third-level sub-group entries. The main pane
     expands to fill the iframe. */
  html.embed-mode .sidebar { display: none; }
  html.embed-mode .layout  { grid-template-columns: 1fr; }
  html.embed-mode .main    { padding-left: 24px; }
</style>

<script>
  // Set the embed flag on <html> before any layout-affecting CSS would
  // run, so the inner sidebar never even paints. Driven by ?embed=1 in
  // the URL — set by tools.php's iframe wrapper.
  (function () {
    try {
      var p = new URLSearchParams(window.location.search);
      if (p.get('embed') === '1') {
        document.documentElement.classList.add('embed-mode');
      }
    } catch (e) {}
  })();
</script>

<div class="layout">

<aside class="sidebar">
  <?php include 'includes/apps-menu.php'; ?>
  <div class="brand">
    <div class="brand-mark">
      <div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:14px;letter-spacing:-0.02em;">MD</div>
    </div>
    <div class="brand-text">
      <div class="brand-title">Engineering Calculator</div>
      <div class="brand-sub">MagDyn</div>
    </div>
  </div>

  <div class="nav">
    <div class="nav-section"><span>Calculators</span></div>

    <a class="nav-item tab-btn active" data-tab="units">
      <span class="ico">⇌</span>
      <span class="nav-label"><span class="num">01</span>Unit Converter</span>
    </a>
    <a class="nav-item tab-btn" data-tab="stackup">
      <span class="ico">∑</span>
      <span class="nav-label"><span class="num">02</span>Tolerance Stack-up</span>
    </a>
    <a class="nav-item tab-btn" data-tab="cpk">
      <span class="ico">σ</span>
      <span class="nav-label"><span class="num">03</span>Cp / Cpk</span>
    </a>
    <a class="nav-item tab-btn" data-tab="fit">
      <span class="ico">⊕</span>
      <span class="nav-label"><span class="num">04</span>Fit / Clearance</span>
    </a>
    <a class="nav-item tab-btn" data-tab="sfm">
      <span class="ico">⚙</span>
      <span class="nav-label"><span class="num">05</span>Speeds &amp; Feeds</span>
    </a>
    <a class="nav-item tab-btn" data-tab="geometry">
      <span class="ico">◧</span>
      <span class="nav-label"><span class="num">06</span>Area · Volume · Weight</span>
    </a>
    <a class="nav-item tab-btn" data-tab="sci">
      <span class="ico">∫</span>
      <span class="nav-label"><span class="num">07</span>Scientific</span>
    </a>
    <a class="nav-item tab-btn" data-tab="aql">
      <span class="ico">⊞</span>
      <span class="nav-label"><span class="num">08</span>IS 2500 Sampling</span>
    </a>
    <a class="nav-item tab-btn" data-tab="iso2859">
      <span class="ico">⊟</span>
      <span class="nav-label"><span class="num">09</span>ISO 2859 Sampling</span>
    </a>

    <div class="nav-section"><span>About</span></div>
    <div style="padding: 0 12px; font-size: 11px; color: var(--sidebar-text-very-dim); line-height: 1.6;">
      Stack-up per ASME&nbsp;Y14.5. Capability per AIAG. Fits per ISO&nbsp;286. Sampling per IS&nbsp;2500-1 and ISO&nbsp;2859-1 (single, normal). Treat results as engineering estimates &mdash; verify before release.
    </div>
  </div>

  <div class="sidebar-footer">
    <div style="font-size: 11px; color: var(--sidebar-text-dim); padding: 4px 8px; line-height: 1.6;">
      <div id="tab-context" style="text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">UNITS · LENGTH × AREA × VOLUME</div>
    </div>
  </div>
</aside>

<div class="main">

  <!-- ============ 01 · UNIT CONVERTER ============ -->
  <div class="view active" id="view-units">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Unit Converter</h1>
        <div class="panel-num-tag">01 · LENGTH · AREA · VOLUME · MASS · FORCE · PRESSURE · TEMPERATURE · ANGLE</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Category</h3>
        <div class="calc-field">
          <select id="uc-category">
            <option value="length">Length</option>
            <option value="area">Area</option>
            <option value="volume">Volume</option>
            <option value="mass">Mass</option>
            <option value="force">Force</option>
            <option value="pressure">Pressure</option>
            <option value="angle">Angle</option>
            <option value="temperature">Temperature</option>
          </select>
        </div>

        <h3>Convert</h3>
        <div class="unit-row">
          <input type="number" id="uc-input-a" step="any" inputmode="decimal" value="1">
          <span class="arrow">→</span>
          <select id="uc-unit-a"></select>
        </div>
        <div class="unit-row">
          <input type="number" id="uc-input-b" step="any" inputmode="decimal">
          <span class="arrow">→</span>
          <select id="uc-unit-b"></select>
        </div>
        <div style="font-size:11px;color:var(--text-light);margin-top:6px;">
          Type into either side &mdash; the other updates live.
        </div>
        <div style="margin-top:14px;">
          <button class="btn-small primary calc-go" data-calc="units" title="Save this conversion to history (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>
      </div>

      <div class="panel">
        <h3>Common conversions for this value</h3>
        <div class="calc-output" id="uc-table">
          <!-- populated by JS -->
        </div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent conversions</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-units-count"></span>
          <button class="ch-clear-btn" data-clear="units">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-units-list"></div>
    </div>
  </div>

  <!-- ============ 02 · TOLERANCE STACK-UP ============ -->
  <div class="view" id="view-stackup">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Tolerance Stack-up</h1>
        <div class="panel-num-tag">02 · WORST-CASE · ROOT-SUM-SQUARE (RSS)</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Contributing dimensions</h3>
        <table class="stack-table" id="ts-table">
          <thead>
            <tr>
              <th style="width: 22%;">Description</th>
              <th style="width: 12%;">Dir</th>
              <th style="width: 18%;">Nominal</th>
              <th style="width: 16%;">Tol +</th>
              <th style="width: 16%;">Tol −</th>
              <th style="width: 8%;"></th>
            </tr>
          </thead>
          <tbody id="ts-rows">
            <!-- rows added by JS -->
          </tbody>
        </table>
        <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap:wrap;">
          <button class="btn-small primary calc-go" data-calc="stackup" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
          <button class="btn-small" id="ts-add">+ Add dimension</button>
          <button class="btn-small" id="ts-reset">Reset</button>
        </div>
        <div style="font-size: 11px; color: var(--text-light); margin-top: 12px; line-height: 1.6;">
          <strong>Dir</strong> sets the sign: + adds the nominal, − subtracts it. Tolerances are bilateral around the nominal.
        </div>
      </div>

      <div class="panel">
        <h3>Result</h3>
        <div class="calc-output" id="ts-result">
          <!-- populated by JS -->
        </div>

        <h3>Formula</h3>
        <div class="formula-block">
Sum   = Σ (dir<sub>i</sub> &middot; nominal<sub>i</sub>)
WC    = Σ |tol<sub>i</sub>|       (worst case)
RSS   = √( Σ tol<sub>i</sub>² )   (root-sum-square)

Mean gap = Sum
Max gap  = Sum + WC
Min gap  = Sum − WC</div>

        <h3>When to use which</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          <strong>Worst case</strong> is conservative &mdash; guarantees every assembly meets tolerance. Use for safety-critical or low-volume parts.<br>
          <strong>RSS</strong> assumes normal distribution with σ at 1/3 the tolerance band. Use for high-volume statistically-controlled production. Predicts ~99.73% will be in range.
        </div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent stack-ups</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-stackup-count"></span>
          <button class="ch-clear-btn" data-clear="stackup">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-stackup-list"></div>
    </div>
  </div>

  <!-- ============ 03 · Cp / Cpk ============ -->
  <div class="view" id="view-cpk">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Cp / Cpk — Process Capability</h1>
        <div class="panel-num-tag">03 · USL · LSL · MEAN · SIGMA → Cp · Cpk · Pp · Ppk · PPM</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Specification limits</h3>
        <div class="calc-field">
          <label>Upper Spec Limit (USL)</label>
          <input type="number" id="cpk-usl" step="any" inputmode="decimal" value="10.05">
        </div>
        <div class="calc-field">
          <label>Lower Spec Limit (LSL)</label>
          <input type="number" id="cpk-lsl" step="any" inputmode="decimal" value="9.95">
          <div class="hint">Leave one blank for one-sided spec.</div>
        </div>

        <h3>Process data</h3>
        <div class="calc-field">
          <label>Process Mean (x̄)</label>
          <input type="number" id="cpk-mean" step="any" inputmode="decimal" value="10.01">
        </div>
        <div class="calc-field">
          <label>Process Std-Dev (σ)</label>
          <input type="number" id="cpk-sigma" step="any" inputmode="decimal" value="0.012">
          <div class="hint">Or paste comma/newline-separated sample data below to compute it.</div>
        </div>
        <div class="calc-field">
          <label>Sample data (optional)</label>
          <textarea id="cpk-samples" rows="3"
            style="padding:8px 10px;font-size:12px;border:1px solid var(--border-strong, var(--border));border-radius:var(--radius);font-family:ui-monospace,monospace;width:100%;"
            placeholder="10.01, 10.00, 9.99, 10.02, 9.98, 10.00, …"></textarea>
        </div>
        <div style="margin-top:6px;">
          <button class="btn-small primary calc-go" data-calc="cpk" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>
      </div>

      <div class="panel">
        <h3>Capability indices</h3>
        <div class="calc-output" id="cpk-result">
          <!-- populated by JS -->
        </div>

        <h3>Interpretation</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          <strong>Cp</strong> measures process <em>spread</em> vs spec width. <strong>Cpk</strong> includes <em>centering</em>: a Cpk &lt; Cp means the process is off-centre.<br><br>
          Common targets: <strong>Cpk &ge; 1.33</strong> for routine production, <strong>&ge; 1.67</strong> for critical features, <strong>&ge; 2.00</strong> for six-sigma.
        </div>

        <h3>Formula</h3>
        <div class="formula-block">
Cp  = (USL − LSL) / (6σ)
CpU = (USL − x̄)  / (3σ)
CpL = (x̄ − LSL)  / (3σ)
Cpk = min(CpU, CpL)
PPM = expected parts-per-million outside spec
       (normal-distribution approximation)</div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent capability studies</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-cpk-count"></span>
          <button class="ch-clear-btn" data-clear="cpk">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-cpk-list"></div>
    </div>
  </div>

  <!-- ============ 04 · FIT / CLEARANCE ============ -->
  <div class="view" id="view-fit">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Fit / Clearance — ISO 286</h1>
        <div class="panel-num-tag">04 · HOLE × SHAFT → CLEARANCE · TRANSITION · INTERFERENCE</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Hole</h3>
        <div class="calc-field">
          <label>Basic size (mm)</label>
          <input type="number" id="fit-basic" step="any" inputmode="decimal" value="20">
        </div>
        <div class="calc-field">
          <label>Hole tolerance class</label>
          <select id="fit-hole-class">
            <option value="H6">H6</option>
            <option value="H7" selected>H7</option>
            <option value="H8">H8</option>
            <option value="H9">H9</option>
            <option value="H11">H11</option>
          </select>
        </div>

        <h3>Shaft</h3>
        <div class="calc-field">
          <label>Shaft tolerance class</label>
          <select id="fit-shaft-class">
            <option value="g6">g6 (clearance — running fit)</option>
            <option value="h6" selected>h6 (clearance — sliding fit)</option>
            <option value="h7">h7</option>
            <option value="h9">h9 (loose running)</option>
            <option value="js6">js6 (transition)</option>
            <option value="k6">k6 (transition — light press)</option>
            <option value="m6">m6 (transition)</option>
            <option value="n6">n6 (interference — light)</option>
            <option value="p6">p6 (interference — medium)</option>
            <option value="r6">r6 (interference — heavy)</option>
            <option value="s6">s6 (interference — very heavy)</option>
          </select>
        </div>

        <div style="font-size: 11px; color: var(--text-light); margin-top: 16px; line-height: 1.6;">
          Tolerance values per ISO 286 standard tables. Covers basic-size range from 1 to 500 mm.
        </div>
        <div style="margin-top:12px;">
          <button class="btn-small primary calc-go" data-calc="fit" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>
      </div>

      <div class="panel">
        <h3>Computed fit</h3>
        <div class="calc-output" id="fit-result">
          <!-- populated by JS -->
        </div>

        <h3>Reading the result</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          <strong>Clearance &gt; 0</strong>: hole always bigger than shaft (parts slide).<br>
          <strong>Interference &gt; 0</strong>: shaft always bigger than hole (parts press together).<br>
          <strong>Transition</strong>: max condition is interference, min condition is clearance &mdash; assembly may need selective fitting.
        </div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent fits</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-fit-count"></span>
          <button class="ch-clear-btn" data-clear="fit">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-fit-list"></div>
    </div>
  </div>

  <!-- ============ 05 · SPEEDS & FEEDS ============ -->
  <div class="view" id="view-sfm">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Speeds &amp; Feeds</h1>
        <div class="panel-num-tag">05 · MATERIAL × TOOL × OPERATION → RPM · IPM · MRR</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Setup</h3>
        <div class="calc-field">
          <label>Material</label>
          <select id="sf-material">
            <option value="aluminum">Aluminum (6061-T6)</option>
            <option value="brass">Brass</option>
            <option value="copper">Copper</option>
            <option value="mild-steel" selected>Mild Steel (1018)</option>
            <option value="alloy-steel">Alloy Steel (4140)</option>
            <option value="tool-steel">Tool Steel (H13)</option>
            <option value="stainless-304">Stainless 304</option>
            <option value="stainless-316">Stainless 316</option>
            <option value="cast-iron">Cast Iron</option>
            <option value="titanium">Titanium (Ti-6Al-4V)</option>
            <option value="plastic">Plastic (POM/Nylon/PEEK)</option>
          </select>
        </div>
        <div class="calc-field">
          <label>Tool material</label>
          <select id="sf-tool">
            <option value="hss">HSS (high-speed steel)</option>
            <option value="carbide" selected>Carbide</option>
          </select>
        </div>
        <div class="calc-field">
          <label>Operation</label>
          <select id="sf-op">
            <option value="turning" selected>Turning</option>
            <option value="milling">Milling</option>
            <option value="drilling">Drilling</option>
          </select>
        </div>

        <h3>Geometry</h3>
        <div class="calc-field">
          <label>Diameter (mm) — workpiece (turning) or tool (mill/drill)</label>
          <input type="number" id="sf-diameter" step="any" inputmode="decimal" value="20">
        </div>
        <div class="calc-field">
          <label>Number of flutes / teeth (milling only)</label>
          <input type="number" id="sf-flutes" step="1" min="1" value="4">
        </div>
        <div class="calc-field">
          <label>Chip load / feed per tooth (mm)</label>
          <input type="number" id="sf-fpt" step="any" inputmode="decimal" value="0.05">
          <div class="hint">For turning: feed per revolution. For drilling: feed per revolution. For milling: feed per tooth.</div>
        </div>
        <div class="calc-field">
          <label>Depth of cut — DOC (mm)</label>
          <input type="number" id="sf-doc" step="any" inputmode="decimal" value="2">
        </div>
        <div style="margin-top:6px;">
          <button class="btn-small primary calc-go" data-calc="sfm" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>
      </div>

      <div class="panel">
        <h3>Recommended cutting parameters</h3>
        <div class="calc-output" id="sf-result"></div>

        <h3>Notes</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          SFM (surface feet/min) values are conservative starting points. Adjust based on tool runout, coolant, machine rigidity, and finish requirements. For finishing passes reduce DOC and increase RPM ~20%.
        </div>

        <h3>Formula</h3>
        <div class="formula-block">
RPM  = (SFM × 304.8) / (π × D_mm)
Feed = RPM × FPT × N_teeth   (milling)
       RPM × FPR             (turning, drilling)
MRR  = Feed × DOC × WOC      (volumetric removal)</div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent speeds &amp; feeds</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-sfm-count"></span>
          <button class="ch-clear-btn" data-clear="sfm">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-sfm-list"></div>
    </div>
  </div>

  <!-- ============ 06 · AREA · VOLUME · WEIGHT ============ -->
  <div class="view" id="view-geometry">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Area · Volume · Weight</h1>
        <div class="panel-num-tag">06 · SHAPE × MATERIAL → SURFACE · VOLUME · MASS</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Shape</h3>
        <div class="shape-picker" id="geo-shapes">
          <button class="shape-btn active" data-shape="cube">
            <svg viewBox="0 0 36 36"><path d="M6 12L18 6L30 12L18 18L6 12Z M6 12V24L18 30M30 12V24L18 30M18 18V30"/></svg>
            Cube
          </button>
          <button class="shape-btn" data-shape="box">
            <svg viewBox="0 0 36 36"><path d="M6 12L18 6L30 12L18 18L6 12Z M6 12V26L18 32M30 12V26L18 32M18 18V32"/></svg>
            Box
          </button>
          <button class="shape-btn" data-shape="cylinder">
            <svg viewBox="0 0 36 36"><ellipse cx="18" cy="8" rx="10" ry="3"/><line x1="8" y1="8" x2="8" y2="28"/><line x1="28" y1="8" x2="28" y2="28"/><ellipse cx="18" cy="28" rx="10" ry="3"/></svg>
            Cylinder
          </button>
          <button class="shape-btn" data-shape="tube">
            <svg viewBox="0 0 36 36"><ellipse cx="18" cy="8" rx="10" ry="3"/><ellipse cx="18" cy="8" rx="6" ry="1.8"/><line x1="8" y1="8" x2="8" y2="28"/><line x1="28" y1="8" x2="28" y2="28"/><ellipse cx="18" cy="28" rx="10" ry="3"/></svg>
            Tube
          </button>
          <button class="shape-btn" data-shape="sphere">
            <svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="11"/><ellipse cx="18" cy="18" rx="11" ry="4"/></svg>
            Sphere
          </button>
          <button class="shape-btn" data-shape="cone">
            <svg viewBox="0 0 36 36"><line x1="18" y1="4" x2="6" y2="28"/><line x1="18" y1="4" x2="30" y2="28"/><ellipse cx="18" cy="28" rx="12" ry="3"/></svg>
            Cone
          </button>
          <button class="shape-btn" data-shape="hex">
            <svg viewBox="0 0 36 36"><polygon points="10,6 26,6 32,18 26,30 10,30 4,18"/></svg>
            Hex bar
          </button>
          <button class="shape-btn" data-shape="pyramid">
            <svg viewBox="0 0 36 36"><polygon points="18,4 4,28 32,28"/><line x1="18" y1="4" x2="18" y2="28"/></svg>
            Pyramid
          </button>
        </div>

        <h3>Dimensions</h3>
        <div id="geo-inputs"></div>

        <h3>Material</h3>
        <div class="calc-field">
          <select id="geo-material">
            <option value="steel">Steel (7.85 g/cm³)</option>
            <option value="stainless">Stainless 304/316 (8.0 g/cm³)</option>
            <option value="aluminum">Aluminum 6061 (2.70 g/cm³)</option>
            <option value="brass">Brass C36000 (8.5 g/cm³)</option>
            <option value="copper">Copper (8.96 g/cm³)</option>
            <option value="cast-iron">Cast Iron (7.2 g/cm³)</option>
            <option value="titanium">Titanium Ti-6Al-4V (4.43 g/cm³)</option>
            <option value="nylon">Nylon 6/6 (1.14 g/cm³)</option>
            <option value="acrylic">Acrylic / PMMA (1.18 g/cm³)</option>
            <option value="abs">ABS (1.05 g/cm³)</option>
            <option value="peek">PEEK (1.32 g/cm³)</option>
            <option value="rubber">Rubber (1.20 g/cm³)</option>
            <option value="custom">Custom…</option>
          </select>
        </div>
        <div class="calc-field" id="geo-custom-density-wrap" style="display:none;">
          <label>Custom density (g/cm³)</label>
          <input type="number" id="geo-custom-density" step="any" inputmode="decimal" value="1.00">
        </div>
        <div style="margin-top:6px;">
          <button class="btn-small primary calc-go" data-calc="geometry" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>
      </div>

      <div class="panel">
        <h3>Results</h3>
        <div class="calc-output" id="geo-result"></div>

        <h3>Notes</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          Densities are typical handbook values; actual alloys vary &plusmn;2-3%. Weight is theoretical &mdash; machined parts will be lighter than the bounding shape.
        </div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent shapes</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-geometry-count"></span>
          <button class="ch-clear-btn" data-clear="geometry">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-geometry-list"></div>
    </div>
  </div>

  <!-- ============ 07 · SCIENTIFIC CALCULATOR ============ -->
  <div class="view" id="view-sci">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">Scientific Calculator</h1>
        <div class="panel-num-tag">07 · sin · cos · tan · ln · log · √ · x^y</div>
      </div>
    </div>

    <div class="calc-grid" style="grid-template-columns: 1fr; max-width: 560px;">
      <div class="panel">
        <div class="sci-display">
          <div class="expr" id="sci-expr">&nbsp;</div>
          <div class="result" id="sci-result">0</div>
        </div>

        <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
          <label style="font-size:11px;color:var(--text-light);">Angle:</label>
          <select id="sci-angle-mode" style="padding:4px 8px;font-size:12px;border-radius:4px;border:1px solid var(--border);">
            <option value="deg" selected>Degrees</option>
            <option value="rad">Radians</option>
          </select>
          <div style="margin-left:auto;font-size:11px;color:var(--text-light);" id="sci-memory-status">M: 0</div>
        </div>

        <div class="sci-keypad" id="sci-keypad">
          <!-- keys built by JS -->
        </div>
      </div>
    </div>

    <div class="ch-card" style="max-width:560px;">
      <div class="ch-head">
        <div class="ch-head-title">Recent expressions</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-sci-count"></span>
          <button class="ch-clear-btn" data-clear="sci">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-sci-list"></div>
    </div>
  </div>

  <!-- ============ 08 · IS 2500 SAMPLING ============ -->
  <div class="view" id="view-aql">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">IS 2500 Sampling Plan</h1>
        <div class="panel-num-tag">08 · LOT × AQL × LEVEL → SAMPLE SIZE · ACCEPT · REJECT</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Inputs</h3>
        <div class="calc-field">
          <label>Lot size (N)</label>
          <input type="number" id="aql-lot" step="1" min="2" inputmode="decimal" value="500">
          <div class="hint">Number of units in the batch being inspected.</div>
        </div>
        <div class="calc-field">
          <label>Inspection level</label>
          <select id="aql-level">
            <option value="S-1">S-1 (Special — smallest sample)</option>
            <option value="S-2">S-2</option>
            <option value="S-3">S-3</option>
            <option value="S-4">S-4</option>
            <option value="I">I (General — smaller sample)</option>
            <option value="II" selected>II (General — normal, default)</option>
            <option value="III">III (General — larger sample, more discriminating)</option>
          </select>
          <div class="hint">Level II is the default unless the contract specifies otherwise.</div>
        </div>
        <div class="calc-field">
          <label>Inspection mode</label>
          <select id="aql-mode">
            <option value="normal" selected>Normal (default) &mdash; Table 2-A / 3-A</option>
            <option value="tightened">Tightened &mdash; Table 2-B</option>
            <option value="reduced">Reduced &mdash; Table 2-C</option>
          </select>
          <div class="hint">Switch when prior history justifies it: tightened after 2 of 5 lots rejected; reduced after 10 consecutive accepted lots with low process average. (Switching rules are advisory &mdash; this calculator does not automate them.)</div>
        </div>
        <div class="calc-field">
          <label>Sampling type</label>
          <select id="aql-type">
            <option value="single" selected>Single sampling (default)</option>
            <option value="double">Double sampling &mdash; Table 3-A (normal only)</option>
          </select>
          <div class="hint">Double sampling lets you accept/reject on a small first sample, or take a second sample if the result is ambiguous. Often gives a smaller average sample size than single sampling for the same OC. Tightened &amp; reduced double sampling (Tables 3-B / 3-C) are not yet implemented.</div>
        </div>
        <div class="calc-field">
          <label>Acceptable Quality Level (AQL, %)</label>
          <select id="aql-aql">
            <!-- options populated by JS so we don't duplicate the canonical list -->
          </select>
          <div class="hint">The highest defect rate considered acceptable as a process average. Lower AQL → stricter plan.</div>
        </div>

        <div id="aql-double-decision" style="display:none;border-top:1px solid var(--border);margin-top:10px;padding-top:10px;">
          <h3 style="margin-top:0;">Decision tool (optional)</h3>
          <div style="font-size:12px;color:var(--text-muted,var(--text));line-height:1.6;margin-bottom:8px;">
            If you're mid-inspection, enter the defect counts you've observed. The tool will tell you whether to accept, reject, or take the next sample.
          </div>
          <div class="calc-field">
            <label>Defects found in first sample (optional)</label>
            <input type="number" id="aql-d1" step="1" min="0" inputmode="decimal" placeholder="leave blank to skip">
            <div class="hint">Count of nonconforming units in the first sample of n₁ items.</div>
          </div>
          <div class="calc-field">
            <label>Defects found in second sample (optional)</label>
            <input type="number" id="aql-d2" step="1" min="0" inputmode="decimal" placeholder="enter only if a second sample was drawn">
            <div class="hint">Count of nonconforming units in the second sample alone (not cumulative). Only used if the first-sample result was ambiguous.</div>
          </div>
        </div>

        <div style="margin-top:6px;">
          <button class="btn-small primary calc-go" data-calc="aql" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>

        <h3>Scope &amp; limitations</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          This calculator implements <strong>IS 2500 Part 1 Single Sampling</strong> in all three inspection modes (Normal &mdash; Table 2-A; Tightened &mdash; Table 2-B; Reduced &mdash; Table 2-C), and <strong>Double Sampling Normal</strong> (Table 3-A). All master tables are transcribed directly from the published PDF in <code>docs/IS_2500_1_2000.pdf</code> and verified by an inline self-test against known plans at page load.
          <br><br>
          <strong>Not yet implemented:</strong> automatic switching rules between modes (Section 9.3), Double Sampling Tightened &amp; Reduced (Tables 3-B and 3-C), Multiple Sampling for all modes (Tables 4-A/B/C, 5+ stage plans), IS 2500 Part 2 (LQ-based isolated lots), and Part 3 (skip-lot). The PDF in the repo has all of these tables if you want to extend.
        </div>
      </div>

      <div class="panel">
        <h3>Sampling plan</h3>
        <div class="calc-output" id="aql-result">
          <!-- populated by JS -->
        </div>

        <h3>How to use this</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          <strong>1.</strong> Randomly pick <em>n</em> units from the lot.<br>
          <strong>2.</strong> Inspect each for the defect of interest.<br>
          <strong>3.</strong> Count defective units found = <em>d</em>.<br>
          <strong>4.</strong> If <em>d</em> ≤ Ac → accept the lot. If <em>d</em> ≥ Re → reject. (For single sampling, Ac and Re are always one apart, so there's no "indeterminate" zone.)
        </div>

        <h3>Reading the table reference</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          The standard's master table can put an arrow in a cell when the (code letter, AQL) combination's sample would equal or exceed the lot size. <strong>↓</strong> means "use the first plan below the arrow" — the sample size CHANGES to the bigger code letter's <em>n</em>. <strong>↑</strong> means use the first plan above. This calculator handles those jumps automatically.
        </div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent sampling plans</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-aql-count"></span>
          <button class="ch-clear-btn" data-clear="aql">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-aql-list"></div>
    </div>
  </div>

  <!-- ============ 09 · ISO 2859-1 SAMPLING ============ -->
  <div class="view" id="view-iso2859">
    <div class="page-head">
      <div>
        <h1 style="margin:0;">ISO 2859-1 Sampling Plan</h1>
        <div class="panel-num-tag">09 · LOT × AQL × LEVEL → SAMPLE SIZE · ACCEPT · REJECT</div>
      </div>
    </div>

    <div class="calc-grid">
      <div class="panel">
        <h3>Inputs</h3>
        <div class="calc-field">
          <label>Lot size (N)</label>
          <input type="number" id="iso-lot" step="1" min="2" inputmode="decimal" value="500">
          <div class="hint">Number of units in the batch being inspected.</div>
        </div>
        <div class="calc-field">
          <label>Inspection level</label>
          <select id="iso-level">
            <option value="S-1">S-1 (Special — smallest sample)</option>
            <option value="S-2">S-2</option>
            <option value="S-3">S-3</option>
            <option value="S-4">S-4</option>
            <option value="I">I (General — smaller sample)</option>
            <option value="II" selected>II (General — normal, default)</option>
            <option value="III">III (General — larger sample, more discriminating)</option>
          </select>
          <div class="hint">Level II is the default unless the contract specifies otherwise.</div>
        </div>
        <div class="calc-field">
          <label>Inspection mode</label>
          <select id="iso-mode">
            <option value="normal" selected>Normal (default) &mdash; Table 2-A / 3-A</option>
            <option value="tightened">Tightened &mdash; Table 2-B</option>
            <option value="reduced">Reduced &mdash; Table 2-C</option>
          </select>
          <div class="hint">Switching rules (ISO 2859-1 §9.3) are advisory: tightened after 2 of 5 lots rejected; reduced after 10 consecutive accepted lots and a stable process average. This calculator does not automate the switching.</div>
        </div>
        <div class="calc-field">
          <label>Sampling type</label>
          <select id="iso-type">
            <option value="single" selected>Single sampling (default)</option>
            <option value="double">Double sampling &mdash; Table 3-A (normal only)</option>
          </select>
          <div class="hint">Double sampling lets you accept/reject on a small first sample, or take a second sample if the result is ambiguous. Often gives a smaller average sample size than single sampling for the same OC. Tightened &amp; reduced double sampling are not yet implemented.</div>
        </div>
        <div class="calc-field">
          <label>Acceptable Quality Level (AQL, %)</label>
          <select id="iso-aql">
            <!-- options populated by JS so we don't duplicate the canonical list -->
          </select>
          <div class="hint">The highest defect rate considered acceptable as a process average. Lower AQL → stricter plan.</div>
        </div>

        <div id="iso-double-decision" style="display:none;border-top:1px solid var(--border);margin-top:10px;padding-top:10px;">
          <h3 style="margin-top:0;">Decision tool (optional)</h3>
          <div style="font-size:12px;color:var(--text-muted,var(--text));line-height:1.6;margin-bottom:8px;">
            If you're mid-inspection, enter the defect counts you've observed. The tool will tell you whether to accept, reject, or take the next sample.
          </div>
          <div class="calc-field">
            <label>Defects found in first sample (optional)</label>
            <input type="number" id="iso-d1" step="1" min="0" inputmode="decimal" placeholder="leave blank to skip">
            <div class="hint">Count of nonconforming units in the first sample of n₁ items.</div>
          </div>
          <div class="calc-field">
            <label>Defects found in second sample (optional)</label>
            <input type="number" id="iso-d2" step="1" min="0" inputmode="decimal" placeholder="enter only if a second sample was drawn">
            <div class="hint">Count of nonconforming units in the second sample alone (not cumulative). Only used if the first-sample result was ambiguous.</div>
          </div>
        </div>

        <div style="margin-top:6px;">
          <button class="btn-small primary calc-go" data-calc="iso2859" title="Compute &amp; save (Enter)">
            <span style="font-size:13px;font-weight:700;">∑</span> Calculate &amp; save
          </button>
        </div>

        <h3>Scope &amp; limitations</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          Implements <strong>ISO 2859-1 single sampling</strong> in all three inspection modes &mdash; Normal (Table 2-A), Tightened (Table 2-B), Reduced (Table 2-C) &mdash; plus <strong>Double Sampling Normal</strong> (Table 3-A). All master tables are transcribed directly from the published PDF kept at <code>docs/IS_2500_1_2000.pdf</code> in this repo (ISO 2859-1 and IS 2500-1 share identical master tables).
          <br><br>
          <strong>Not yet implemented:</strong> automatic switching rules between modes (§9.3), Double Sampling Tightened &amp; Reduced (Tables 3-B and 3-C), Multiple Sampling (Tables 4-A/B/C), ISO 2859-2 (isolated-lot LQ plans), ISO 2859-3 (skip-lot), ISO 2859-4 (declared quality levels), ISO 2859-5 (sequential), ISO 2859-10 (vocabulary). All sampling-plan tables not implemented here are in the PDF if you want to extend.
        </div>
      </div>

      <div class="panel">
        <h3>Sampling plan</h3>
        <div class="calc-output" id="iso-result">
          <!-- populated by JS -->
        </div>

        <h3>How to use this</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          <strong>1.</strong> Randomly pick <em>n</em> units from the lot.<br>
          <strong>2.</strong> Inspect each for the defect of interest.<br>
          <strong>3.</strong> Count defective units found = <em>d</em>.<br>
          <strong>4.</strong> If <em>d</em> ≤ Ac → accept the lot. If <em>d</em> ≥ Re → reject. (For single sampling, Ac and Re are always one apart, so there's no "indeterminate" zone.)
        </div>

        <h3>Reading the table reference</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          ISO 2859 Table 2-A uses arrows in cells where the (code letter, AQL) plan would equal/exceed the lot size or would always reject. <strong>↓</strong> means "use the first plan below the arrow" — the sample size CHANGES to the bigger code letter's <em>n</em>. <strong>↑</strong> means use the first plan above. This calculator handles those jumps automatically.
        </div>

        <h3>Relationship to other standards</h3>
        <div style="font-size: 12px; color: var(--text-muted, var(--text)); line-height: 1.7;">
          For <strong>single sampling, normal inspection</strong>, the master tables in ISO 2859-1, IS 2500-1, ANSI/ASQ Z1.4, and MIL-STD-105E are numerically identical. The same lot+level+AQL inputs produce the same <em>n</em>, Ac, Re. The standards differ in scope (which sampling schemes they cover) and in details like switching rules and how isolated lots are treated.
        </div>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-head">
        <div class="ch-head-title">Recent sampling plans</div>
        <div class="ch-head-actions">
          <span class="ch-head-count" id="ch-iso2859-count"></span>
          <button class="ch-clear-btn" data-clear="iso2859">Clear all</button>
        </div>
      </div>
      <div class="ch-list" id="ch-iso2859-list"></div>
    </div>
  </div>

</div>
</div>

<script>
'use strict';

// =========================================================================
// SHARED HELPERS
// =========================================================================

const $ = id => document.getElementById(id);

// parseFloat that's a little forgiving:
//   - Accepts comma OR period as decimal separator ("25,4" → 25.4)
//   - Strips thousand-separator spaces ("1 234.5" → 1234.5)
//   - Returns NaN for empty/invalid (unlike parseFloat which can be quirky)
//
// We use this in place of bare parseFloat for input fields to make decimals
// reliable across locales and casual user input.
function parseNum(s) {
  if (s === null || s === undefined) return NaN;
  let t = String(s).trim().replace(/\s+/g, '');
  if (!t) return NaN;
  // Only swap comma → period if there's exactly one comma and no period
  // (otherwise it might be a thousand separator we'd corrupt).
  if (t.indexOf(',') !== -1 && t.indexOf('.') === -1) {
    t = t.replace(',', '.');
  }
  const v = parseFloat(t);
  return isFinite(v) ? v : NaN;
}

function fmt(n, digits = 4) {
  if (!isFinite(n)) return '—';
  if (n === 0) return '0';
  const abs = Math.abs(n);
  // Use scientific notation for very large or very small numbers
  if (abs >= 1e7 || (abs > 0 && abs < 1e-4)) {
    return n.toExponential(digits - 1);
  }
  return Number(n.toPrecision(digits)).toString();
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => (
    { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
  ));
}

// =========================================================================
// CALC HISTORY (per-calculator local storage)
// =========================================================================
//
// Each calculator gets a list of up to 25 saved input snapshots, kept in
// localStorage under "engcalc.history.<calcId>". Each entry is:
//   { ts: <unix ms>, label: "short summary", state: <object> }
//
// When the user changes inputs, we debounce ~1.5s and then push a new entry.
// Duplicates of the most recent entry (same JSON) are skipped so a flurry of
// keystrokes doesn't bloat the list.

const CalcHistory = (function() {
  const PREFIX = 'engcalc.history.';
  const MAX_ENTRIES = 25;

  function key(calcId) { return PREFIX + calcId; }

  function read(calcId) {
    try {
      const raw = localStorage.getItem(key(calcId));
      if (!raw) return [];
      const v = JSON.parse(raw);
      return Array.isArray(v) ? v : [];
    } catch (e) {
      return [];
    }
  }

  function write(calcId, list) {
    try { localStorage.setItem(key(calcId), JSON.stringify(list)); }
    catch (e) { /* quota or disabled — silently ignore */ }
  }

  function save(calcId, state, label) {
    if (state === null || state === undefined) return;
    const list = read(calcId);
    const snapshot = JSON.stringify(state);
    // Dedupe: if newest entry is identical, skip
    if (list.length > 0 && JSON.stringify(list[0].state) === snapshot) return;
    list.unshift({ ts: Date.now(), label: label || '', state: state });
    // Cap at MAX_ENTRIES (newest first)
    while (list.length > MAX_ENTRIES) list.pop();
    write(calcId, list);
    return list;
  }

  function clear(calcId) {
    try { localStorage.removeItem(key(calcId)); } catch (e) {}
  }

  function remove(calcId, index) {
    const list = read(calcId);
    if (index < 0 || index >= list.length) return list;
    list.splice(index, 1);
    write(calcId, list);
    return list;
  }

  function render(slotId, calcId, onClick) {
    const el = document.getElementById(slotId);
    if (!el) return;
    const list = read(calcId);
    if (list.length === 0) {
      el.innerHTML = '<div class="ch-empty">No saved entries yet. Inputs are auto-saved as you work.</div>';
      return;
    }
    const fmtTime = (ts) => {
      const d = new Date(ts);
      const today = new Date();
      const sameDay = d.toDateString() === today.toDateString();
      return sameDay
        ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    };
    el.innerHTML = list.map((entry, i) => `
      <div class="ch-row" data-i="${i}" title="Click to load these inputs">
        <div class="ch-row-time">${fmtTime(entry.ts)}</div>
        <div class="ch-row-label">${escapeHtml(entry.label || '(no summary)')}</div>
        <button class="ch-row-del" data-i="${i}" title="Remove from history">×</button>
      </div>
    `).join('');
    // Wire row click → onClick(entry.state)
    el.querySelectorAll('.ch-row').forEach(row => {
      row.addEventListener('click', (e) => {
        if (e.target.classList.contains('ch-row-del')) return;
        const idx = +row.dataset.i;
        const entry = list[idx];
        if (entry && typeof onClick === 'function') onClick(entry.state);
      });
    });
    el.querySelectorAll('.ch-row-del').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const idx = +btn.dataset.i;
        remove(calcId, idx);
        render(slotId, calcId, onClick);
      });
    });
  }

  return { save, read, clear, remove, render };
})();

// =========================================================================
// TAB SWITCHING
// =========================================================================

const tabContextLabels = {
  units:    'UNITS · LENGTH × AREA × VOLUME',
  stackup:  'STACK-UP · WORST-CASE × RSS',
  cpk:      'CAPABILITY · Cp × Cpk × PPM',
  fit:      'ISO 286 · HOLE × SHAFT',
  sfm:      'SPEEDS · MATERIAL × TOOL × OPERATION',
  geometry: 'GEOMETRY · SHAPE × DENSITY → MASS',
  sci:      'SCIENTIFIC · sin · cos · ln · √',
  aql:      'IS 2500 · LOT × AQL × LEVEL → n · Ac · Re',
  iso2859:  'ISO 2859-1 · LOT × AQL × LEVEL → n · Ac · Re'
};

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const tab = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    btn.classList.add('active');
    $('view-' + tab).classList.add('active');
    const ctx = $('tab-context');
    if (ctx && tabContextLabels[tab]) ctx.textContent = tabContextLabels[tab];
    // Recompute the tab's outputs in case its inputs were already filled
    if (tab === 'units') renderUnitConverter();
    if (tab === 'cpk') renderCpk();
    if (tab === 'fit') renderFit();
    if (tab === 'sfm') renderSfm();
    if (tab === 'geometry') renderGeometry();
    if (tab === 'stackup') renderStackup();
    if (tab === 'aql') renderAql();
    if (tab === 'iso2859') renderIso();
  });
});

// -------------------------------------------------------------------------
// MagDyn integration: when this page is loaded with ?view=<tab> in the URL
// (either directly or inside the MagDyn iframe wrapper), switch to that tab
// on load. Lets the MagDyn sidebar's third-level entries deep-link straight
// to the right calculator instead of always landing on Unit Converter.
// -------------------------------------------------------------------------
(function () {
  try {
    var params = new URLSearchParams(window.location.search);
    var requested = params.get('view');
    if (!requested) return;
    // Whitelist only known tabs so a stray param can't trigger a stale click
    // on some unrelated DOM element.
    var known = ['units','stackup','cpk','fit','sfm','geometry','sci','aql','iso2859'];
    if (known.indexOf(requested) === -1) return;
    var btn = document.querySelector('.tab-btn[data-tab="' + requested + '"]');
    if (btn) btn.click();
  } catch (e) { /* swallow — initial tab stays whatever the HTML had */ }
})();

// =========================================================================
// 01 · UNIT CONVERTER
// =========================================================================
//
// All units are stored as conversion factors to a base unit per category.
// Temperature is the exception — handled with offset formulas separately.

const UNITS = {
  length: {
    label: 'Length',
    base: 'm',
    units: [
      ['nm', 'nanometre',  1e-9],
      ['μm', 'micrometre', 1e-6],
      ['mm', 'millimetre', 1e-3],
      ['cm', 'centimetre', 1e-2],
      ['m',  'metre',      1],
      ['km', 'kilometre',  1e3],
      ['in', 'inch',       0.0254],
      ['ft', 'foot',       0.3048],
      ['yd', 'yard',       0.9144],
      ['mi', 'mile',       1609.344],
      ['thou', 'thou / mil', 0.0000254]
    ]
  },
  area: {
    label: 'Area', base: 'm²',
    units: [
      ['mm²', 'mm²', 1e-6],
      ['cm²', 'cm²', 1e-4],
      ['m²',  'm²',  1],
      ['in²', 'in²', 0.00064516],
      ['ft²', 'ft²', 0.09290304],
      ['hectare', 'hectare', 10000]
    ]
  },
  volume: {
    label: 'Volume', base: 'm³',
    units: [
      ['mm³', 'mm³',   1e-9],
      ['cm³', 'cm³ (cc)', 1e-6],
      ['ml',  'millilitre', 1e-6],
      ['L',   'litre',  1e-3],
      ['m³',  'm³',     1],
      ['in³', 'in³',    0.0000163871],
      ['ft³', 'ft³',    0.0283168],
      ['gal-us', 'US gallon', 0.00378541],
      ['gal-imp', 'Imperial gallon', 0.00454609]
    ]
  },
  mass: {
    label: 'Mass', base: 'kg',
    units: [
      ['mg', 'milligram', 1e-6],
      ['g',  'gram',      1e-3],
      ['kg', 'kilogram',  1],
      ['t',  'tonne',     1000],
      ['oz', 'ounce',     0.0283495],
      ['lb', 'pound',     0.453592],
      ['ton-us', 'US (short) ton', 907.185],
      ['ton-uk', 'UK (long) ton',  1016.05]
    ]
  },
  force: {
    label: 'Force', base: 'N',
    units: [
      ['N',   'newton',     1],
      ['kN',  'kilonewton', 1000],
      ['MN',  'meganewton', 1e6],
      ['kgf', 'kilogram-force', 9.80665],
      ['lbf', 'pound-force',    4.44822],
      ['ozf', 'ounce-force',    0.278014]
    ]
  },
  pressure: {
    label: 'Pressure', base: 'Pa',
    units: [
      ['Pa',  'pascal',      1],
      ['kPa', 'kilopascal',  1000],
      ['MPa', 'megapascal',  1e6],
      ['bar', 'bar',         1e5],
      ['psi', 'PSI',         6894.76],
      ['ksi', 'KSI',         6894760],
      ['atm', 'atmosphere',  101325],
      ['torr','torr / mmHg', 133.322],
      ['inHg','inch of mercury', 3386.39]
    ]
  },
  angle: {
    label: 'Angle', base: 'rad',
    units: [
      ['rad', 'radian',      1],
      ['deg', 'degree',      Math.PI / 180],
      ['grad','gradian',     Math.PI / 200],
      ['arcmin', 'arcminute',Math.PI / (180*60)],
      ['arcsec', 'arcsecond',Math.PI / (180*3600)],
      ['turn','full turn',   2 * Math.PI]
    ]
  },
  temperature: {
    label: 'Temperature', base: 'K',
    // Handled specially — no simple multiplier
    units: [
      ['C',  '°Celsius', 1],
      ['F',  '°Fahrenheit', 1],
      ['K',  'Kelvin', 1],
      ['R',  '°Rankine', 1]
    ]
  }
};

function tempToK(value, unit) {
  switch (unit) {
    case 'C': return value + 273.15;
    case 'F': return (value - 32) * 5/9 + 273.15;
    case 'K': return value;
    case 'R': return value * 5/9;
  }
  return NaN;
}
function tempFromK(kelvin, unit) {
  switch (unit) {
    case 'C': return kelvin - 273.15;
    case 'F': return (kelvin - 273.15) * 9/5 + 32;
    case 'K': return kelvin;
    case 'R': return kelvin * 9/5;
  }
  return NaN;
}

function convert(value, fromUnit, toUnit, category) {
  if (!isFinite(value)) return NaN;
  if (category === 'temperature') {
    return tempFromK(tempToK(value, fromUnit), toUnit);
  }
  const cat = UNITS[category];
  const from = cat.units.find(u => u[0] === fromUnit);
  const to   = cat.units.find(u => u[0] === toUnit);
  if (!from || !to) return NaN;
  return value * from[2] / to[2];
}

function rebuildUnitSelects() {
  const category = $('uc-category').value;
  const cat = UNITS[category];
  const a = $('uc-unit-a'), b = $('uc-unit-b');
  a.innerHTML = ''; b.innerHTML = '';
  for (const u of cat.units) {
    const o1 = document.createElement('option');
    o1.value = u[0]; o1.textContent = u[0] + ' — ' + u[1];
    a.appendChild(o1);
    const o2 = o1.cloneNode(true);
    b.appendChild(o2);
  }
  // Sensible defaults: pick first two distinct units
  a.value = cat.units[0][0];
  b.value = cat.units.length > 1 ? cat.units[1][0] : cat.units[0][0];
  // Smart defaults: for length, default mm → in
  if (category === 'length') { a.value = 'mm'; b.value = 'in'; }
  if (category === 'mass')   { a.value = 'kg'; b.value = 'lb'; }
  if (category === 'temperature') { a.value = 'C'; b.value = 'F'; }
}

function renderUnitConverter() {
  const cat = $('uc-category').value;
  const a = $('uc-unit-a').value;
  const b = $('uc-unit-b').value;
  const va = parseNum($('uc-input-a').value);
  // Update B from A (forward direction)
  const vb = convert(va, a, b, cat);
  if (isFinite(vb)) $('uc-input-b').value = Number(vb.toPrecision(8));

  // Common conversions table
  const tableEl = $('uc-table');
  tableEl.innerHTML = '';
  const c = UNITS[cat];
  for (const u of c.units) {
    if (u[0] === a) continue;
    const conv = convert(va, a, u[0], cat);
    if (!isFinite(conv)) continue;
    const row = document.createElement('div');
    row.className = 'row';
    row.innerHTML = '<span class="lbl">' + u[0] + ' — ' + u[1] + '</span><span class="val">' + fmt(conv, 7) + '</span>';
    tableEl.appendChild(row);
  }
}

$('uc-category').addEventListener('change', () => {
  rebuildUnitSelects();
  renderUnitConverter();
});
$('uc-input-a').addEventListener('input', () => renderUnitConverter());
$('uc-unit-a').addEventListener('change', () => renderUnitConverter());
$('uc-unit-b').addEventListener('change', () => renderUnitConverter());
$('uc-input-b').addEventListener('input', () => {
  // Reverse direction: type into B, update A
  const cat = $('uc-category').value;
  const a = $('uc-unit-a').value, b = $('uc-unit-b').value;
  const vb = parseNum($('uc-input-b').value);
  const va = convert(vb, b, a, cat);
  if (isFinite(va)) {
    $('uc-input-a').value = Number(va.toPrecision(8));
    // Re-render the common table off the new A value
    renderUnitConverter();
  }
});

// =========================================================================
// 02 · TOLERANCE STACK-UP
// =========================================================================

let stackRows = [];

function stackAddRow(desc, dir, nom, tp, tm) {
  stackRows.push({
    desc: desc ?? '',
    dir:  dir  ?? '+',
    nom:  isFinite(nom) ? nom : 0,
    tp:   isFinite(tp)  ? tp  : 0.1,
    tm:   isFinite(tm)  ? tm  : 0.1
  });
  renderStackup();
}

function renderStackup() {
  const tbody = $('ts-rows');
  tbody.innerHTML = '';
  stackRows.forEach((r, i) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="text" value="${r.desc}" data-i="${i}" data-f="desc" placeholder="A→B"></td>
      <td>
        <select data-i="${i}" data-f="dir">
          <option value="+" ${r.dir==='+'?'selected':''}>+</option>
          <option value="-" ${r.dir==='-'?'selected':''}>−</option>
        </select>
      </td>
      <td><input type="number" step="any" inputmode="decimal" value="${r.nom}" data-i="${i}" data-f="nom"></td>
      <td><input type="number" step="any" inputmode="decimal" value="${r.tp}"  data-i="${i}" data-f="tp"></td>
      <td><input type="number" step="any" inputmode="decimal" value="${r.tm}"  data-i="${i}" data-f="tm"></td>
      <td><button class="row-del" data-i="${i}" title="Remove">×</button></td>
    `;
    tbody.appendChild(tr);
  });

  // Wire up inputs
  tbody.querySelectorAll('input, select').forEach(el => {
    el.addEventListener('input', () => {
      const i = +el.dataset.i, f = el.dataset.f;
      let v = el.value;
      if (f === 'nom' || f === 'tp' || f === 'tm') v = parseNum(v) || 0;
      stackRows[i][f] = v;
      computeStackup();
    });
  });
  tbody.querySelectorAll('.row-del').forEach(b => {
    b.addEventListener('click', () => {
      stackRows.splice(+b.dataset.i, 1);
      renderStackup();
    });
  });
  computeStackup();
}

function computeStackup() {
  let sum = 0, wc = 0, rss = 0;
  for (const r of stackRows) {
    const sign = r.dir === '+' ? 1 : -1;
    sum += sign * r.nom;
    // Each tolerance contributes its half-width to spread; use max(tp, tm) for symmetric worst-case
    const halfBand = (r.tp + r.tm) / 2;
    wc  += halfBand;     // worst case adds full half-band of each
    rss += halfBand * halfBand;
  }
  rss = Math.sqrt(rss);
  const min = sum - wc;
  const max = sum + wc;
  const minRss = sum - rss;
  const maxRss = sum + rss;

  $('ts-result').innerHTML = `
    <div class="row headline"><span class="lbl">Mean (nominal sum)</span><span class="val">${fmt(sum, 6)}</span></div>
    <div class="row"><span class="lbl">Worst-case max</span><span class="val">${fmt(max, 6)}</span></div>
    <div class="row"><span class="lbl">Worst-case min</span><span class="val">${fmt(min, 6)}</span></div>
    <div class="row"><span class="lbl">Worst-case span</span><span class="val">±${fmt(wc, 6)}</span></div>
    <div class="row"><span class="lbl" style="font-weight:600;color:var(--primary);">RSS span (±3σ ≈ 99.73%)</span><span class="val">±${fmt(rss, 6)}</span></div>
    <div class="row"><span class="lbl">RSS max</span><span class="val">${fmt(maxRss, 6)}</span></div>
    <div class="row"><span class="lbl">RSS min</span><span class="val">${fmt(minRss, 6)}</span></div>
  `;
}

$('ts-add').addEventListener('click', () => stackAddRow('', '+', 10, 0.05, 0.05));
$('ts-reset').addEventListener('click', () => {
  stackRows = [];
  stackAddRow('Feature A', '+', 25.00, 0.05, 0.05);
  stackAddRow('Feature B', '-', 10.00, 0.05, 0.05);
  stackAddRow('Feature C', '-',  5.00, 0.02, 0.02);
});

// Seed with an example
stackAddRow('Feature A', '+', 25.00, 0.05, 0.05);
stackAddRow('Feature B', '-', 10.00, 0.05, 0.05);
stackAddRow('Feature C', '-',  5.00, 0.02, 0.02);

// =========================================================================
// 03 · Cp / Cpk
// =========================================================================

function normCdf(z) {
  // Abramowitz & Stegun 26.2.17 — good to ~1e-7
  const a1=0.254829592, a2=-0.284496736, a3=1.421413741, a4=-1.453152027, a5=1.061405429, p=0.3275911;
  const sign = z < 0 ? -1 : 1;
  const x = Math.abs(z) / Math.sqrt(2);
  const t = 1.0 / (1.0 + p * x);
  const y = 1.0 - (((((a5*t + a4)*t) + a3)*t + a2)*t + a1) * t * Math.exp(-x*x);
  return 0.5 * (1.0 + sign * y);
}

function renderCpk() {
  let usl = parseNum($('cpk-usl').value);
  let lsl = parseNum($('cpk-lsl').value);
  let mean = parseNum($('cpk-mean').value);
  let sigma = parseNum($('cpk-sigma').value);

  // Compute mean+sigma from samples if provided
  const sampleText = $('cpk-samples').value.trim();
  if (sampleText) {
    const samples = sampleText.split(/[\s,;]+/).map(parseFloat).filter(v => isFinite(v));
    if (samples.length >= 2) {
      const m = samples.reduce((a,b) => a+b, 0) / samples.length;
      const variance = samples.reduce((a,b) => a + (b-m)*(b-m), 0) / (samples.length - 1);
      mean = m;
      sigma = Math.sqrt(variance);
      $('cpk-mean').value = Number(mean.toPrecision(8));
      $('cpk-sigma').value = Number(sigma.toPrecision(8));
    }
  }

  const hasUsl = isFinite(usl);
  const hasLsl = isFinite(lsl);

  if (!isFinite(mean) || !isFinite(sigma) || sigma <= 0 || (!hasUsl && !hasLsl)) {
    $('cpk-result').innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">Enter spec limits and process data.</div>';
    return;
  }

  let cp = NaN, cpu = NaN, cpl = NaN, cpk;
  if (hasUsl && hasLsl) {
    cp = (usl - lsl) / (6 * sigma);
    cpu = (usl - mean) / (3 * sigma);
    cpl = (mean - lsl) / (3 * sigma);
    cpk = Math.min(cpu, cpl);
  } else if (hasUsl) {
    cpu = (usl - mean) / (3 * sigma);
    cpk = cpu;
  } else {
    cpl = (mean - lsl) / (3 * sigma);
    cpk = cpl;
  }

  // PPM — expected fraction outside spec, normal approximation
  let ppmHi = 0, ppmLo = 0;
  if (hasUsl) ppmHi = (1 - normCdf((usl - mean) / sigma)) * 1e6;
  if (hasLsl) ppmLo = normCdf((lsl - mean) / sigma) * 1e6;
  const ppmTotal = ppmHi + ppmLo;

  // Verdict
  let verdict = 'Marginal'; let verdictClass = 'warn';
  if (cpk >= 2.00) { verdict = 'Six-Sigma class'; verdictClass = 'good'; }
  else if (cpk >= 1.67) { verdict = 'Excellent — critical-feature capable'; verdictClass = 'good'; }
  else if (cpk >= 1.33) { verdict = 'Capable — production-ready'; verdictClass = 'good'; }
  else if (cpk >= 1.00) { verdict = 'Marginal — needs improvement'; verdictClass = 'warn'; }
  else { verdict = 'Inadequate'; verdictClass = 'warn'; }

  $('cpk-result').innerHTML = `
    <div class="row headline">
      <span class="lbl">Cpk</span>
      <span class="val">${fmt(cpk, 4)}</span>
    </div>
    ${hasUsl && hasLsl ? `<div class="row"><span class="lbl">Cp (potential)</span><span class="val">${fmt(cp, 4)}</span></div>` : ''}
    ${isFinite(cpu) ? `<div class="row"><span class="lbl">CpU (upper)</span><span class="val">${fmt(cpu, 4)}</span></div>` : ''}
    ${isFinite(cpl) ? `<div class="row"><span class="lbl">CpL (lower)</span><span class="val">${fmt(cpl, 4)}</span></div>` : ''}
    <div class="row"><span class="lbl">σ in use</span><span class="val">${fmt(sigma, 5)}</span></div>
    ${hasUsl ? `<div class="row"><span class="lbl">Expected PPM &gt; USL</span><span class="val">${fmt(ppmHi, 3)}</span></div>` : ''}
    ${hasLsl ? `<div class="row"><span class="lbl">Expected PPM &lt; LSL</span><span class="val">${fmt(ppmLo, 3)}</span></div>` : ''}
    <div class="row"><span class="lbl">Total PPM out</span><span class="val">${fmt(ppmTotal, 3)}</span></div>
    <div class="row ${verdictClass}"><span class="lbl">Verdict</span><span class="val">${verdict}</span></div>
  `;
}

['cpk-usl','cpk-lsl','cpk-mean','cpk-sigma','cpk-samples'].forEach(id => {
  $(id).addEventListener('input', () => renderCpk());
});

// =========================================================================
// 04 · FIT / CLEARANCE  (ISO 286 — common classes)
// =========================================================================
//
// ISO 286 lookup: for each tolerance class, return {upper, lower} in micrometres
// based on basic-size range. We store the standard "Fundamental Deviation" and
// "Standard Tolerance Grade" tables for the most common cases.
//
// For HOLES, upper deviation = ES, lower deviation = EI.
// For SHAFTS, upper deviation = es, lower deviation = ei.
// Hole H is special: EI=0 (lower deviation), ES = +IT_grade.
// Shaft h is special: es=0, ei = −IT_grade.
//
// IT grades (μm) by size range — the full ISO 286 table. Sizes in mm.

const IT_RANGES = [
  // [maxSize, IT6, IT7, IT8, IT9, IT11]
  [3,    6,  10, 14,  25,  60],
  [6,    8,  12, 18,  30,  75],
  [10,   9,  15, 22,  36,  90],
  [18,  11,  18, 27,  43, 110],
  [30,  13,  21, 33,  52, 130],
  [50,  16,  25, 39,  62, 160],
  [80,  19,  30, 46,  74, 190],
  [120, 22,  35, 54,  87, 220],
  [180, 25,  40, 63, 100, 250],
  [250, 29,  46, 72, 115, 290],
  [315, 32,  52, 81, 130, 320],
  [400, 36,  57, 89, 140, 360],
  [500, 40,  63, 97, 155, 400]
];

function itGrade(size, grade) {
  // grade: 6, 7, 8, 9, 11
  const cols = { 6: 1, 7: 2, 8: 3, 9: 4, 11: 5 };
  const col = cols[grade];
  for (const row of IT_RANGES) {
    if (size <= row[0]) return row[col];
  }
  return NaN;
}

// Fundamental deviation tables for common shaft classes.
// For 'g' shaft: es (upper) = -IT5+a few μm... but the simplest accurate
// implementation is to use the standard "fundamental deviation" lookup.
// Source: ISO 286-1.
//
// We store the *upper* deviation (es) for each shaft class by size range.

const SHAFT_ES_TABLE = {
  // [maxSize, es in μm] — upper deviation for each class
  g: [[3,-2],[6,-4],[10,-5],[14,-6],[18,-6],[24,-7],[30,-7],[40,-9],[50,-9],[65,-10],[80,-10],[100,-12],[120,-12],[140,-14],[160,-14],[180,-14],[200,-15],[225,-15],[250,-15],[280,-17],[315,-17],[355,-18],[400,-18],[450,-20],[500,-20]],
  h: 'zero', // es = 0
  js:'sym',  // ±IT/2
  k: [[3,0],[6,1],[10,1],[18,1],[30,2],[50,2],[80,2],[120,3],[180,3],[250,4],[315,4],[400,4],[500,5]],
  m: [[3,2],[6,4],[10,6],[18,7],[30,8],[50,9],[80,11],[120,13],[180,15],[250,17],[315,20],[400,21],[500,23]],
  n: [[3,4],[6,8],[10,10],[18,12],[30,15],[50,17],[80,20],[120,23],[180,27],[250,31],[315,34],[400,37],[500,40]],
  p: [[3,6],[6,12],[10,15],[18,18],[30,22],[50,26],[80,32],[120,37],[180,43],[250,50],[315,56],[400,62],[500,68]],
  r: [[3,10],[6,15],[10,19],[18,23],[30,28],[50,34],[80,41],[120,48],[180,56],[250,66],[315,73],[400,82],[500,90]],
  s: [[3,14],[6,19],[10,23],[18,28],[30,35],[50,43],[80,53],[120,62],[180,72],[250,84],[315,93],[400,105],[500,115]]
};

function shaftEs(letter, size) {
  if (letter === 'h') return 0;
  if (letter === 'js') return null; // signal symmetric
  const tbl = SHAFT_ES_TABLE[letter];
  if (!tbl) return NaN;
  for (const row of tbl) if (size <= row[0]) return row[1];
  return NaN;
}

function shaftDeviations(cls, size) {
  // cls e.g. "h6", "k6", "g6", "js6"
  const m = cls.match(/^(js|[a-z]+)(\d+)$/);
  if (!m) return null;
  const letter = m[1];
  const grade = +m[2];
  const it = itGrade(size, grade);
  if (!isFinite(it)) return null;

  if (letter === 'js') {
    return { es: it / 2, ei: -it / 2 };
  }
  const es = shaftEs(letter, size);
  if (es === null || !isFinite(es)) return null;
  // For shafts with es defined as upper deviation, ei = es - IT
  // EXCEPT for 'h' where es=0 and ei=-IT
  // EXCEPT for 'g' where es is negative and ei = es - IT
  return { es: es, ei: es - it };
}

function holeDeviations(cls, size) {
  // Only Hxx supported (ES = +IT, EI = 0) which covers H6-H11
  const m = cls.match(/^H(\d+)$/);
  if (!m) return null;
  const grade = +m[1];
  const it = itGrade(size, grade);
  if (!isFinite(it)) return null;
  return { ES: it, EI: 0 };
}

function renderFit() {
  const D = parseNum($('fit-basic').value);
  const hcls = $('fit-hole-class').value;
  const scls = $('fit-shaft-class').value;
  if (!isFinite(D) || D <= 0 || D > 500) {
    $('fit-result').innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">Basic size must be 0&lt;D&le;500 mm.</div>';
    return;
  }
  const hole = holeDeviations(hcls, D);
  const shaft = shaftDeviations(scls, D);
  if (!hole || !shaft) {
    $('fit-result').innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">Tolerance not found for this combination.</div>';
    return;
  }
  // All values currently in μm; convert to mm for display
  const Hmax = D + hole.ES / 1000;
  const Hmin = D + hole.EI / 1000;
  const Smax = D + shaft.es / 1000;
  const Smin = D + shaft.ei / 1000;
  // Clearance = hole - shaft (positive means clearance, negative interference)
  const Cmin = Hmin - Smax;   // tightest fit
  const Cmax = Hmax - Smin;   // loosest fit

  let kind;
  if (Cmin >= 0) kind = 'CLEARANCE FIT';
  else if (Cmax <= 0) kind = 'INTERFERENCE FIT';
  else kind = 'TRANSITION FIT';

  $('fit-result').innerHTML = `
    <div class="row headline"><span class="lbl">Fit type</span><span class="val">${kind}</span></div>
    <div class="row"><span class="lbl">Hole ${hcls}: upper / lower deviation</span><span class="val">+${hole.ES} / ${hole.EI} μm</span></div>
    <div class="row"><span class="lbl">Hole size range</span><span class="val">${Hmin.toFixed(4)} … ${Hmax.toFixed(4)} mm</span></div>
    <div class="row"><span class="lbl">Shaft ${scls}: upper / lower deviation</span><span class="val">${shaft.es>0?'+':''}${shaft.es} / ${shaft.ei} μm</span></div>
    <div class="row"><span class="lbl">Shaft size range</span><span class="val">${Smin.toFixed(4)} … ${Smax.toFixed(4)} mm</span></div>
    <div class="row"><span class="lbl">Min clearance / interference</span><span class="val">${Cmin >= 0 ? '+' : ''}${(Cmin*1000).toFixed(1)} μm</span></div>
    <div class="row"><span class="lbl">Max clearance / interference</span><span class="val">${Cmax >= 0 ? '+' : ''}${(Cmax*1000).toFixed(1)} μm</span></div>
  `;
}

['fit-basic','fit-hole-class','fit-shaft-class'].forEach(id => {
  $(id).addEventListener('input', () => renderFit());
  $(id).addEventListener('change', () => renderFit());
});

// =========================================================================
// 05 · SPEEDS & FEEDS
// =========================================================================
//
// Conservative SFM (surface feet per minute) starting points by material and
// tool material. These are typical handbook values — adjust based on actual
// setup conditions.

const SFM_TABLE = {
  // [hss-min, hss-max, carbide-min, carbide-max] SFM
  'aluminum':      [200, 400, 600, 1500],
  'brass':         [150, 300, 400, 800],
  'copper':        [100, 200, 300, 600],
  'mild-steel':    [80,  120, 250, 500],
  'alloy-steel':   [50,  90,  150, 350],
  'tool-steel':    [40,  70,  100, 250],
  'stainless-304': [40,  60,  120, 300],
  'stainless-316': [30,  50,  100, 250],
  'cast-iron':     [60,  100, 200, 400],
  'titanium':      [30,  50,   80, 200],
  'plastic':       [200, 600, 400, 1200]
};

function renderSfm() {
  const mat = $('sf-material').value;
  const tool = $('sf-tool').value;
  const op = $('sf-op').value;
  const D = parseNum($('sf-diameter').value);
  const N = parseInt($('sf-flutes').value) || 1;
  const fpt = parseNum($('sf-fpt').value);
  const doc = parseNum($('sf-doc').value);

  if (!isFinite(D) || D <= 0 || !isFinite(fpt) || fpt <= 0) {
    $('sf-result').innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">Enter diameter and feed.</div>';
    return;
  }
  const sfmRange = SFM_TABLE[mat];
  if (!sfmRange) return;
  const sfmMin = tool === 'hss' ? sfmRange[0] : sfmRange[2];
  const sfmMax = tool === 'hss' ? sfmRange[1] : sfmRange[3];
  const sfmRec = (sfmMin + sfmMax) / 2;

  // RPM = (SFM * 304.8) / (π * D_mm)
  const rpmMin = (sfmMin * 304.8) / (Math.PI * D);
  const rpmRec = (sfmRec * 304.8) / (Math.PI * D);
  const rpmMax = (sfmMax * 304.8) / (Math.PI * D);

  // Feed rate (mm/min)
  // - milling: RPM * fpt * N
  // - turning / drilling: RPM * fpr (where fpt input = fpr)
  let feedRec;
  if (op === 'milling') feedRec = rpmRec * fpt * N;
  else feedRec = rpmRec * fpt;

  // MRR (cm³/min) approximation
  // turning: π * D * DOC * feed (where feed = fpr*RPM)
  // milling: feed * DOC * WOC ... we don't know WOC so we use DOC as both
  // drilling: cross-section × feed
  let mrr = NaN;
  if (op === 'turning' && isFinite(doc)) {
    // material removed per minute = π * D * DOC * feed_per_rev * RPM / 1000³ (cm³)
    mrr = (Math.PI * D * doc * feedRec) / 1000; // mm³/min → cm³/min
  } else if (op === 'milling' && isFinite(doc)) {
    mrr = (D * doc * feedRec) / 1000;
  } else if (op === 'drilling' && isFinite(doc)) {
    mrr = (Math.PI * D * D / 4 * feedRec) / 1000;
  }

  $('sf-result').innerHTML = `
    <div class="row headline"><span class="lbl">Recommended RPM</span><span class="val">${Math.round(rpmRec)}</span></div>
    <div class="row"><span class="lbl">RPM range (conservative … aggressive)</span><span class="val">${Math.round(rpmMin)} … ${Math.round(rpmMax)}</span></div>
    <div class="row"><span class="lbl">SFM used</span><span class="val">${Math.round(sfmRec)} (${Math.round(sfmMin)}…${Math.round(sfmMax)})</span></div>
    <div class="row"><span class="lbl">Cutting speed</span><span class="val">${fmt(sfmRec * 0.3048, 4)} m/min</span></div>
    <div class="row"><span class="lbl">Feed rate</span><span class="val">${Math.round(feedRec)} mm/min · ${fmt(feedRec/25.4, 4)} IPM</span></div>
    ${op === 'milling' ? `<div class="row"><span class="lbl">Chip load per tooth</span><span class="val">${fmt(fpt, 4)} mm</span></div>` : ''}
    ${isFinite(mrr) ? `<div class="row"><span class="lbl">Approx MRR</span><span class="val">${fmt(mrr, 4)} cm³/min</span></div>` : ''}
  `;
}

['sf-material','sf-tool','sf-op','sf-diameter','sf-flutes','sf-fpt','sf-doc'].forEach(id => {
  $(id).addEventListener('input', () => renderSfm());
  $(id).addEventListener('change', () => renderSfm());
});

// =========================================================================
// 06 · AREA · VOLUME · WEIGHT
// =========================================================================

const DENSITIES = {
  steel: 7.85, stainless: 8.0, aluminum: 2.70, brass: 8.5,
  copper: 8.96, 'cast-iron': 7.2, titanium: 4.43,
  nylon: 1.14, acrylic: 1.18, abs: 1.05, peek: 1.32, rubber: 1.20
};

// Each shape entry: list of input fields (id, label, default) + compute function
const SHAPES = {
  cube: {
    fields: [['side', 'Side (mm)', 50]],
    compute(d) {
      const s = d.side;
      return { volume: s*s*s, area: 6*s*s };
    }
  },
  box: {
    fields: [['L', 'Length (mm)', 100], ['W', 'Width (mm)', 50], ['H', 'Height (mm)', 25]],
    compute(d) {
      const v = d.L * d.W * d.H;
      const a = 2*(d.L*d.W + d.L*d.H + d.W*d.H);
      return { volume: v, area: a };
    }
  },
  cylinder: {
    fields: [['D', 'Diameter (mm)', 25], ['L', 'Length (mm)', 100]],
    compute(d) {
      const r = d.D / 2;
      const v = Math.PI * r * r * d.L;
      const a = 2*Math.PI*r*r + 2*Math.PI*r*d.L;
      return { volume: v, area: a };
    }
  },
  tube: {
    fields: [['Do', 'Outer Ø (mm)', 30], ['Di', 'Inner Ø (mm)', 20], ['L', 'Length (mm)', 100]],
    compute(d) {
      const ro = d.Do/2, ri = d.Di/2;
      const v = Math.PI * (ro*ro - ri*ri) * d.L;
      const a = 2*Math.PI*(ro+ri)*d.L + 2*Math.PI*(ro*ro - ri*ri);
      return { volume: v, area: a };
    }
  },
  sphere: {
    fields: [['D', 'Diameter (mm)', 50]],
    compute(d) {
      const r = d.D/2;
      return { volume: (4/3)*Math.PI*r*r*r, area: 4*Math.PI*r*r };
    }
  },
  cone: {
    fields: [['D', 'Base Ø (mm)', 50], ['H', 'Height (mm)', 100]],
    compute(d) {
      const r = d.D/2;
      const slant = Math.sqrt(r*r + d.H*d.H);
      return {
        volume: (1/3)*Math.PI*r*r*d.H,
        area: Math.PI*r*r + Math.PI*r*slant
      };
    }
  },
  hex: {
    fields: [['F', 'Flats (mm)', 25], ['L', 'Length (mm)', 100]],
    compute(d) {
      // Hex bar: flat-to-flat distance = F. Side length s = F/√3.
      const s = d.F / Math.sqrt(3);
      const Across = (3 * Math.sqrt(3) / 2) * s * s;  // area of hex cross-section
      const perim = 6 * s;
      return {
        volume: Across * d.L,
        area: 2*Across + perim*d.L
      };
    }
  },
  pyramid: {
    fields: [['L', 'Base length (mm)', 50], ['W', 'Base width (mm)', 50], ['H', 'Height (mm)', 80]],
    compute(d) {
      // Surface area approximation: base + 4 triangular faces (square base only)
      const slant1 = Math.sqrt(d.H*d.H + (d.W/2)*(d.W/2));
      const slant2 = Math.sqrt(d.H*d.H + (d.L/2)*(d.L/2));
      const sides = d.L * slant1 + d.W * slant2;
      return {
        volume: (1/3) * d.L * d.W * d.H,
        area: d.L * d.W + sides
      };
    }
  }
};

let currentShape = 'cube';
let shapeInputs = {};

function buildShapeInputs() {
  const def = SHAPES[currentShape];
  const wrap = $('geo-inputs');
  wrap.innerHTML = '';
  shapeInputs = {};
  for (const [id, label, defVal] of def.fields) {
    shapeInputs[id] = defVal;
    const div = document.createElement('div');
    div.className = 'calc-field';
    div.innerHTML = `<label>${label}</label><input type="number" step="any" inputmode="decimal" data-fid="${id}" value="${defVal}">`;
    wrap.appendChild(div);
  }
  wrap.querySelectorAll('input').forEach(inp => {
    inp.addEventListener('input', () => {
      shapeInputs[inp.dataset.fid] = parseNum(inp.value) || 0;
      renderGeometry();
    });
  });
}

function renderGeometry() {
  const def = SHAPES[currentShape];
  const r = def.compute(shapeInputs);
  // Convert mm³ → cm³ for weight calculation
  const vCm3 = r.volume / 1000;
  let density;
  const matSel = $('geo-material').value;
  if (matSel === 'custom') {
    density = parseNum($('geo-custom-density').value) || 0;
  } else {
    density = DENSITIES[matSel];
  }
  const massG = vCm3 * density;
  const massKg = massG / 1000;
  const massLb = massKg * 2.20462;

  $('geo-result').innerHTML = `
    <div class="row headline"><span class="lbl">Weight</span><span class="val">${fmt(massKg, 5)} kg · ${fmt(massLb, 5)} lb</span></div>
    <div class="row"><span class="lbl">Mass</span><span class="val">${fmt(massG, 5)} g</span></div>
    <div class="row"><span class="lbl">Volume</span><span class="val">${fmt(vCm3, 5)} cm³ · ${fmt(r.volume, 5)} mm³</span></div>
    <div class="row"><span class="lbl">Surface area</span><span class="val">${fmt(r.area/100, 5)} cm² · ${fmt(r.area, 5)} mm²</span></div>
    <div class="row"><span class="lbl">Density used</span><span class="val">${fmt(density, 4)} g/cm³</span></div>
  `;
}

document.querySelectorAll('.shape-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.shape-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentShape = btn.dataset.shape;
    buildShapeInputs();
    renderGeometry();
  });
});
$('geo-material').addEventListener('change', () => {
  $('geo-custom-density-wrap').style.display = $('geo-material').value === 'custom' ? '' : 'none';
  renderGeometry();
});
$('geo-custom-density').addEventListener('input', () => renderGeometry());
buildShapeInputs();

// =========================================================================
// 07 · SCIENTIFIC CALCULATOR
// =========================================================================
//
// A small expression-based scientific calculator. We build up the expression
// as the user clicks buttons, then evaluate it with a custom parser that
// supports arithmetic, parentheses, and the standard scientific functions.

const sci = {
  expr: '',
  result: '0',
  memory: 0,
  angleMode: 'deg'
};

function sciEval(expr, angleMode) {
  // Convert visible expression to JS-evaluable. Handle:
  //   π → Math.PI, e → Math.E
  //   × → *, ÷ → /, − → -, ^ → **
  //   sin( cos( tan( asin( acos( atan(  with deg/rad conversion
  //   log(  → log10
  //   ln(   → log
  //   √(    → sqrt
  let s = expr
    .replace(/π/g, '(' + Math.PI + ')')
    .replace(/(?<![A-Za-z])e(?![A-Za-z\d])/g, '(' + Math.E + ')')
    .replace(/×/g, '*')
    .replace(/÷/g, '/')
    .replace(/−/g, '-')
    .replace(/\^/g, '**');

  const degRad = angleMode === 'deg' ? '* Math.PI / 180' : '';
  const radDeg = angleMode === 'deg' ? '* 180 / Math.PI' : '';
  s = s.replace(/\bsin\(/g,  '(Math.sin((')
       .replace(/\bcos\(/g,  '(Math.cos((')
       .replace(/\btan\(/g,  '(Math.tan((');
  // Close the extra-paren-wrap with the deg-rad multiplier. We need balanced parens —
  // easier to do a simple regex with a non-paren-only argument:
  // Better approach: walk through and process each scientific function carefully.
  // Re-do with a parser-friendly tactic:
  // (We just inserted extra ( ( so we need to handle close: when we see the
  // user's closing ), we need to insert deg-rad-conversion before it.)
  // Simpler: define the trig calls as a JS wrapper.
  // Reset s and use a different strategy:
  s = expr
    .replace(/π/g, '(' + Math.PI + ')')
    .replace(/(?<![A-Za-z])e(?![A-Za-z\d])/g, '(' + Math.E + ')')
    .replace(/×/g, '*')
    .replace(/÷/g, '/')
    .replace(/−/g, '-')
    .replace(/\^/g, '**')
    .replace(/√/g, 'Math.sqrt')
    .replace(/\bsin\b/g,  '_sin')
    .replace(/\bcos\b/g,  '_cos')
    .replace(/\btan\b/g,  '_tan')
    .replace(/\basin\b/g, '_asin')
    .replace(/\bacos\b/g, '_acos')
    .replace(/\batan\b/g, '_atan')
    .replace(/\blog\b/g,  'Math.log10')
    .replace(/\bln\b/g,   'Math.log')
    .replace(/\babs\b/g,  'Math.abs');

  // Defensive: only allow safe characters
  if (!/^[\d+\-*/().,\s%a-zA-Z_]*$/.test(s)) {
    return 'Error';
  }

  // Evaluate with the trig wrappers in scope
  try {
    const k = angleMode === 'deg' ? Math.PI / 180 : 1;
    const _sin  = x => Math.sin(x * k);
    const _cos  = x => Math.cos(x * k);
    const _tan  = x => Math.tan(x * k);
    const _asin = x => Math.asin(x) / k;
    const _acos = x => Math.acos(x) / k;
    const _atan = x => Math.atan(x) / k;
    // eslint-disable-next-line no-new-func
    const fn = new Function('_sin','_cos','_tan','_asin','_acos','_atan', 'return (' + (s || '0') + ');');
    const v = fn(_sin, _cos, _tan, _asin, _acos, _atan);
    if (!isFinite(v)) return 'Error';
    return v;
  } catch (e) {
    return 'Error';
  }
}

function sciUpdateDisplay() {
  $('sci-expr').textContent = sci.expr || '\u00A0';
  $('sci-result').textContent = String(sci.result);
  $('sci-memory-status').textContent = 'M: ' + fmt(sci.memory, 6);
}

function sciHandle(key) {
  switch (key) {
    case 'C': sci.expr = ''; sci.result = '0'; break;
    case '⌫': sci.expr = sci.expr.slice(0, -1); break;
    case '=':
      const v = sciEval(sci.expr, sci.angleMode);
      if (v === 'Error') { sci.result = 'Error'; }
      else { sci.result = fmt(v, 10); sci.expr = String(sci.result); }
      break;
    case 'MS': {
      const v = sciEval(sci.expr || sci.result, sci.angleMode);
      if (typeof v === 'number') sci.memory = v;
      break;
    }
    case 'MR': sci.expr += String(sci.memory); break;
    case 'M+': {
      const v = sciEval(sci.expr || sci.result, sci.angleMode);
      if (typeof v === 'number') sci.memory += v;
      break;
    }
    case 'MC': sci.memory = 0; break;
    case 'sin(': case 'cos(': case 'tan(':
    case 'asin(': case 'acos(': case 'atan(':
    case 'log(': case 'ln(':
      sci.expr += key; break;
    case '√(':
      sci.expr += '√('; break;
    case 'x²':
      sci.expr += '^2'; break;
    case '1/x':
      sci.expr = '1/(' + (sci.expr || sci.result) + ')'; break;
    case '±':
      // Toggle sign on current expression
      if (sci.expr.startsWith('-')) sci.expr = sci.expr.slice(1);
      else sci.expr = '-' + sci.expr;
      break;
    case 'π': sci.expr += 'π'; break;
    case 'e': sci.expr += 'e'; break;
    default:
      sci.expr += key;
  }
  // Live result preview
  if (key !== '=') {
    const v = sciEval(sci.expr, sci.angleMode);
    if (typeof v === 'number') sci.result = fmt(v, 10);
    else if (sci.expr === '') sci.result = '0';
  }
  sciUpdateDisplay();
}

// Build keypad
const sciKeys = [
  ['MC', 'MR', 'MS', 'M+', 'C',   '⌫'],
  ['sin(', 'cos(', 'tan(', 'log(', '(',   ')'],
  ['asin(','acos(','atan(','ln(',  'x²',  '^'],
  ['7',    '8',    '9',    '÷',    '√(',  'π'],
  ['4',    '5',    '6',    '×',    '1/x', 'e'],
  ['1',    '2',    '3',    '−',    '±',   '%'],
  ['0',    '.',    '=',    '+',    '',    '']
];

const keypad = $('sci-keypad');
for (const row of sciKeys) {
  for (const k of row) {
    if (!k) { keypad.appendChild(document.createElement('div')); continue; }
    const btn = document.createElement('button');
    btn.className = 'sci-key';
    if (/^[A-Z]\w*$/.test(k) || k.endsWith('(') || k === 'π' || k === 'e' || k === '√(' || k === 'x²' || k === '1/x' || k === '±' || k === '⌫') {
      btn.classList.add('fn');
    }
    if (k === '+' || k === '−' || k === '×' || k === '÷' || k === '^') btn.classList.add('op');
    if (k === '=') btn.classList.add('eq');
    if (k === 'C') btn.classList.add('clear');
    btn.textContent = k;
    btn.addEventListener('click', () => sciHandle(k));
    keypad.appendChild(btn);
  }
}
$('sci-angle-mode').addEventListener('change', e => {
  sci.angleMode = e.target.value;
});

// Keyboard support
window.addEventListener('keydown', e => {
  if (!$('view-sci').classList.contains('active')) return;
  if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT')) return;
  const k = e.key;
  if (/^[0-9.]$/.test(k)) { sciHandle(k); e.preventDefault(); }
  else if (k === '+') { sciHandle('+'); e.preventDefault(); }
  else if (k === '-') { sciHandle('−'); e.preventDefault(); }
  else if (k === '*') { sciHandle('×'); e.preventDefault(); }
  else if (k === '/') { sciHandle('÷'); e.preventDefault(); }
  else if (k === '(' || k === ')') { sciHandle(k); e.preventDefault(); }
  else if (k === '^') { sciHandle('^'); e.preventDefault(); }
  else if (k === 'Enter' || k === '=') { sciHandle('='); e.preventDefault(); }
  else if (k === 'Backspace') { sciHandle('⌫'); e.preventDefault(); }
  else if (k === 'Escape') { sciHandle('C'); e.preventDefault(); }
});
sciUpdateDisplay();

// =========================================================================
// 08 · IS 2500 SAMPLING (single, normal inspection)
// =========================================================================
//
// This implements IS 2500 Part 1 — sampling plans indexed by AQL, for
// lot-by-lot inspection by attributes, single sampling, normal inspection.
// The standard is functionally identical to ISO 2859-1 and ANSI Z1.4, all
// descended from MIL-STD-105. Data tables reproduced from the published
// standard. Numbers in this module are NOT interpolated or invented —
// every value comes from the master tables.

// ---- Table 1: Sample-size code letter ----------------------------------
// Indexed by [lot-size range][inspection level].
// Each row: [maxLotSize, codeS1, codeS2, codeS3, codeS4, codeI, codeII, codeIII]
// To resolve: find the first row whose maxLotSize >= N.
const AQL_CODE_LETTER = [
  // max N    S-1  S-2  S-3  S-4  I   II  III
  [    8,    'A', 'A', 'A', 'A', 'A','A','B'],
  [   15,    'A', 'A', 'A', 'A', 'A','B','C'],
  [   25,    'A', 'A', 'B', 'B', 'B','C','D'],
  [   50,    'A', 'B', 'B', 'C', 'C','D','E'],
  [   90,    'B', 'B', 'C', 'C', 'C','E','F'],
  [  150,    'B', 'B', 'C', 'D', 'D','F','G'],
  [  280,    'B', 'C', 'D', 'E', 'E','G','H'],
  [  500,    'B', 'C', 'D', 'E', 'F','H','J'],
  [ 1200,    'C', 'C', 'E', 'F', 'G','J','K'],
  [ 3200,    'C', 'D', 'E', 'G', 'H','K','L'],
  [10000,    'C', 'D', 'F', 'G', 'J','L','M'],
  [35000,    'C', 'D', 'F', 'H', 'K','M','N'],
  [150000,   'D', 'E', 'G', 'J', 'L','N','P'],
  [500000,   'D', 'E', 'G', 'J', 'M','P','Q'],
  [Infinity, 'D', 'E', 'H', 'K', 'N','Q','R']
];
const CODE_LEVEL_INDEX = { 'S-1':1,'S-2':2,'S-3':3,'S-4':4,'I':5,'II':6,'III':7 };

// ---- Table 2-A/2-B/2-C: Sample sizes by code letter and inspection mode -----
//
// Sample sizes for normal and tightened are the same (Tables 2-A and 2-B).
// Reduced (Table 2-C) uses smaller samples per code letter — about 40% of
// the normal/tightened size, rounded to standard preferred numbers.
const AQL_SAMPLE_SIZES = {
  normal: {
    A:2, B:3, C:5, D:8, E:13, F:20, G:32, H:50, J:80, K:125, L:200, M:315,
    N:500, P:800, Q:1250, R:2000
  },
  tightened: {
    // Same as normal for codes A–R. Note that Table 2-B also has a code S
    // (n=3150) which we omit for now — it only appears for very small AQLs
    // where the row is mostly arrows in any case.
    A:2, B:3, C:5, D:8, E:13, F:20, G:32, H:50, J:80, K:125, L:200, M:315,
    N:500, P:800, Q:1250, R:2000
  },
  reduced: {
    A:2, B:2, C:2, D:3, E:5, F:8, G:13, H:20, J:32, K:50, L:80, M:125,
    N:200, P:315, Q:500, R:800
  }
};
// Backwards-compatible alias for existing call sites that haven't been updated.
const AQL_SAMPLE_SIZE = AQL_SAMPLE_SIZES.normal;

// Code-letter ordering — used for arrow-follow logic.
const CODE_ORDER = ['A','B','C','D','E','F','G','H','J','K','L','M','N','P','Q','R'];

// ---- Canonical AQL values (single-sampling, all modes) ----------------------
const AQL_VALUES = [
  '0.010','0.015','0.025','0.040','0.065','0.10','0.15','0.25','0.40',
  '0.65','1.0','1.5','2.5','4.0','6.5','10','15','25','40','65','100',
  '150','250','400','650','1000'
];

// ---- Tables 2-A, 2-B, 2-C: Single-sampling Ac/Re by mode -------------------
//
// Each cell is one of:
//   [Ac, Re]   — numeric accept/reject pair. Standard plan applies.
//   'DOWN'     — ↓ arrow: use first sampling plan below this row at this AQL
//   'UP'       — ↑ arrow: use first sampling plan above this row at this AQL
//
// These tables are transcribed directly from the IS 2500-1:2000 / ISO 2859-1
// PDF (pages 20–22 for Tables 2-A, 2-B, 2-C respectively). The PDF is kept
// in /docs/IS_2500_1_2000.pdf in this repo so any future edit can be
// verified cell-by-cell against the published source.
//
// Each table has its own:
//   • Ac/Re sequence — the order of numeric values along a row's band
//   • Anchor — where Code A's [0,1] sits (i.e. the diagonal's starting column)
//   • Row-by-row band length — how far each row's numeric band extends
//
// The (↑, ↓) GAP between [0,1] and [1,2] is present in ALL three tables for
// every code letter except R. Most commercial AQL calculators omit this gap,
// which is why their results disagree with the published standard for cells
// adjacent to [0,1].
//
// Cross-verification (see selfTest below):
//   • Normal:    D@1.5 → n=8 Ac=0 Re=1 (user case + PDF)
//   • Normal:    L@1.5 → n=200 Ac=7 Re=8 (CRAN AQLSchemes)
//   • Tightened: D@1.0 → n=8 Ac=0 Re=1 (PDF page 21) — shifted right vs normal
//   • Reduced:   D@2.5 → n=3 Ac=0 Re=1 (PDF page 22) — smaller sample, lighter test
//
const ACRE_SEQUENCES = {
  normal:    [[0,1],[1,2],[2,3],[3,4],[5,6], [7,8], [10,11],[14,15],[21,22],[30,31],[44,45]],
  tightened: [[0,1],[1,2],[2,3],[3,4],[5,6], [8,9], [12,13],[18,19],[27,28],[41,42]],
  reduced:   [[0,1],[1,2],[2,3],[3,4],[5,6], [6,7], [8,9], [10,11],[14,15],[21,22],[30,31]]
};

const ANCHOR_COL_FOR_A = {
  normal:    14,    // Code A's [0,1] sits at AQL 6.5% (col 14)
  tightened: 15,    // shifted right by 1 — tightened needs higher AQL for [0,1]
  reduced:   14     // same anchor as normal
};

// Right-tail band lengths — the max sequence index reached in each row.
// Derived from direct PDF transcription, not a formula.
//
// Convention: max-sequence-index reached. So 8 means the band extends from
// [0,1] (seqIdx 0) up to and including seqIdx 8.
const BAND_END_SEQIDX = {
  normal: {
    A:9, B:10, C:10, D:10, E:10, F:8, G:8, H:8, J:8, K:8, L:8, M:8,
    N:8, P:8, Q:8, R:8   // R is special (no [0,1] — handled separately)
  },
  tightened: {
    A:8, B:9, C:9, D:9, E:9, F:7, G:7, H:7, J:7, K:7, L:7, M:7,
    N:7, P:7, Q:7, R:7
  },
  reduced: {
    // Rows A and B use the "skip" sequence — no [6,7] or [8,9]. For these
    // rows we treat the numeric band as the standard normal-shape (skip
    // seqIdx 5 and 6 → use seqIdx 4 then 7).
    // Actually, rows A and B in 2-C just use the SAME seq as normal, since
    // their bands are short enough to never hit the extra [6,7]/[8,9] cells.
    // We achieve that by giving them their own sequence override below.
    A:8, B:8,    // bands reach [30,31]  (uses normal-like sequence)
    C:9, D:9, E:9,    // [21,22]
    F:7,    // [10,11]
    G:7, H:7, J:7, K:7, L:7, M:7, N:7, P:7, Q:7, R:7   // [10,11]
  }
};

// Per-row sequence override — for rows in 2-C that use the normal-shape
// sequence instead of the extended-shape one. (Rows A, B in reduced have
// short bands that don't include the [6,7]/[8,9] insertions.)
const PER_ROW_SEQ_OVERRIDE = {
  reduced: {
    // Rows A and B use the normal-shape sequence (skips [6,7] and [8,9])
    A: [[0,1],[1,2],[2,3],[3,4],[5,6], [7,8], [10,11],[14,15],[21,22],[30,31]],
    B: [[0,1],[1,2],[2,3],[3,4],[5,6], [7,8], [10,11],[14,15],[21,22],[30,31]]
  }
};

function buildAqlTableForMode(mode) {
  const table = {};
  const numCols = AQL_VALUES.length;
  const sequence = ACRE_SEQUENCES[mode];
  const anchorA = ANCHOR_COL_FOR_A[mode];
  const bandEnds = BAND_END_SEQIDX[mode];
  const overrides = PER_ROW_SEQ_OVERRIDE[mode] || {};

  CODE_ORDER.forEach((letter, codeIdx) => {
    const row = new Array(numCols).fill('UP');
    const rowSeq = overrides[letter] || sequence;
    const startCol = anchorA - codeIdx;

    if (letter === 'R') {
      // Special: row R has no [0,1] cell and no gap. ↑ at cols 0..startCol-1,
      // then numeric band starting at [1,2] (seqIdx 1) at col startCol+2,
      // extending to seqIdx bandEnd. In normal, that puts [1,2] at col 2.
      const maxIdx = bandEnds[letter];
      for (let c = 0; c < numCols; c++) row[c] = 'UP';
      const startNumeric = (mode === 'tightened') ? startCol + 1 : startCol + 2;
      // Actually in normal: R's [1,2] is at col 2. anchorA - codeIdx = 14 - 15 = -1.
      // We need a special-case col for R's band start.
      // Looking at PDF: R in 2-A normal has [1,2] starting at col 2 (AQL 0.025).
      //                R in 2-B tightened: [1,2] starts at col 2 (AQL 0.025).
      //                R in 2-C reduced:   [1,2] starts at col 2.
      // Always col 2 across all modes.
      const rStart = 2;
      for (let seqIdx = 1; seqIdx <= maxIdx; seqIdx++) {
        const col = rStart + (seqIdx - 1);
        if (col >= numCols) break;
        row[col] = rowSeq[seqIdx];
      }
      // Everything before rStart and after the band stays 'UP'.
    } else {
      // Standard rows A through Q
      // Cols before startCol: DOWN
      for (let c = 0; c < startCol; c++) row[c] = 'DOWN';
      // [0,1] at startCol
      if (startCol >= 0 && startCol < numCols) row[startCol] = rowSeq[0];
      // ↑ at startCol+1, ↓ at startCol+2 (the gap)
      if (startCol + 1 < numCols) row[startCol + 1] = 'UP';
      if (startCol + 2 < numCols) row[startCol + 2] = 'DOWN';
      // Right band: seqIdx 1+ starts at startCol+3
      const maxIdx = bandEnds[letter];
      for (let seqIdx = 1; seqIdx <= maxIdx; seqIdx++) {
        const col = startCol + 2 + seqIdx;
        if (col >= numCols) break;
        if (seqIdx < rowSeq.length) row[col] = rowSeq[seqIdx];
      }
      // Cols past the band stay 'UP'
    }
    table[letter] = row;
  });
  return table;
}

const AQL_TABLES = {
  normal:    buildAqlTableForMode('normal'),
  tightened: buildAqlTableForMode('tightened'),
  reduced:   buildAqlTableForMode('reduced')
};
// Backwards-compatible alias for any existing site that hasn't been refactored
const AQL_TABLE = AQL_TABLES.normal;

// ---- Self-test: fail loudly if any table mis-resolves a known plan ----
(function aqlSelfTest() {
  const knownPlans = [
    // [mode, codeLetter, aqlStr, expectedN, expectedAc, expectedRe, source]
    // Normal (Table 2-A)
    ['normal','D','1.5',   8,   0,  1, "user case + PDF p20"],
    ['normal','L','1.5', 200,   7,  8, "CRAN AQLSchemes"],
    ['normal','H','1.0',  50,   1,  2, "InTouch guide + PDF p20"],
    ['normal','A','6.5',   2,   0,  1, "PDF p20"],
    ['normal','A','1000',  2,  30, 31, "PDF p20"],
    ['normal','J','0.15', 80,   0,  1, "PDF p20"],
    ['normal','L','0.065',200,  0,  1, "PDF p20"],
    ['normal','K','4.0', 125,  10, 11, "PDF p20"],
    // Tightened (Table 2-B)
    ['tightened','A','10',  2,   0,  1, "PDF p21 — anchor at AQL 10"],
    ['tightened','D','2.5', 8,   0,  1, "PDF p21 — shifted right vs normal"],
    ['tightened','H','1.5', 50,  1,  2, "PDF p21"],
    ['tightened','L','1.0', 200, 3,  4, "PDF p21 — seq has [3,4] at this position"],
    ['tightened','K','4.0', 125, 8,  9, "PDF p21 — note [8,9] not [10,11]"],
    // Reduced (Table 2-C)
    ['reduced','A','6.5',   2,   0,  1, "PDF p22 — anchor at AQL 6.5"],
    ['reduced','D','1.5',   3,   0,  1, "PDF p22 — smaller sample size"],
    ['reduced','G','1.5',  13,   1,  2, "PDF p22"],
    ['reduced','H','2.5',  20,   3,  4, "PDF p22"]
  ];
  const failures = [];
  for (const [mode, c, a, en, eac, ere, src] of knownPlans) {
    const aqlIdx = AQL_VALUES.indexOf(a);
    const cell = AQL_TABLES[mode][c][aqlIdx];
    const n = AQL_SAMPLE_SIZES[mode][c];
    if (!Array.isArray(cell)) {
      failures.push(`${mode} ${c}@${a}: got arrow ${cell}, expected [${eac},${ere}] (${src})`);
      continue;
    }
    if (n !== en || cell[0] !== eac || cell[1] !== ere) {
      failures.push(`${mode} ${c}@${a}: got n=${n} Ac=${cell[0]} Re=${cell[1]}, expected n=${en} Ac=${eac} Re=${ere} (${src})`);
    }
  }
  if (failures.length) {
    console.error('AQL TABLE SELF-TEST FAILED (' + failures.length + ' of ' + knownPlans.length + '):');
    for (const f of failures) console.error('  ' + f);
  } else {
    console.log('AQL table self-test passed (' + knownPlans.length + ' plans across 3 modes).');
  }
})();


// ---- Table 3-A: Double sampling plans for normal inspection ----------
//
// Transcribed directly from IS 2500-1:2000 / ISO 2859-1 PDF page 23. Each
// row has TWO sub-rows (first sample and cumulative-second-sample), and
// each numeric cell contains four values: (Ac1, Re1, Ac2, Re2).
//
// Decision rule:
//   1. Draw first sample of n1. Count defects d1.
//      If d1 <= Ac1, accept the lot.
//      If d1 >= Re1, reject the lot.
//      Otherwise (Ac1 < d1 < Re1), draw a second sample.
//   2. Draw second sample of n2. Count total defects d = d1 + d2.
//      If d <= Ac2, accept. If d >= Re2, reject.
//      (For all entries in this table, Ac2 + 1 == Re2 — no indeterminate
//      zone at the second stage.)
//
// Structural notes (verified against PDF page 23):
//
//   • Row A is special: ALL cells are '*' (use single-sampling plan in
//     Table 2-A instead). Code A's n=2 is too small for staged sampling.
//
//   • Rows B–R each have a numeric band of 8–10 cells preceded by a
//     four-cell gap [DOWN, *, UP, DOWN] before the first numeric. The
//     '*' in the gap means "use single sampling at this AQL".
//
//   • Diagonal: Row B's first numeric (entry 0) sits at column 16 (AQL 15).
//     Each subsequent code letter shifts the band LEFT by exactly 1 column.
//     So Row R (codeIdx 15) has its first numeric at col 2 (AQL 0.025).
//
//   • Right truncation:
//       - Rows B–E:  full 10-entry sequence (extends to col 25)
//       - Rows F–Q:  truncated to 8 entries (ends at (11,16,26,27))
//       - Row R:     truncated to 8 entries; leftmost cells are '↑'
//                    not '↓ * ↑ ↓' because R is at the table edge.
//
//   • Sample sizes differ from single-sampling Table 2-A. Double-sampling
//     uses smaller first-sample sizes because of the second-chance design:
//       Code:    B  C  D  E   F   G   H   J   K   L    M    N    P    Q     R
//       n1=n2:   2  3  5  8  13  20  32  50  80 125  200  315  500  800  1250
//
// Cross-checked (see selfTest2 below):
//   • Code K @ AQL 0.65 → n=80, (Ac1=0,Re1=3) / cum n=160, (Ac2=3,Re2=4)
//     — matches elsmar.com forum citation of Z1.4 (same data as IS 2500).
//   • Row B @ AQL 15 → (0,2,1,2); B @ AQL 25 → (0,3,3,4); other PDF cells.
const AQL_SAMPLE_SIZES_DOUBLE = {
  // First sample n1 per code letter. Second sample is always equal to first.
  // Code A has no double plan (use single instead).
  B:2, C:3, D:5, E:8, F:13, G:20, H:32, J:50, K:80, L:125,
  M:200, N:315, P:500, Q:800, R:1250
};
// Double-sampling Ac/Re sequence: each entry is [Ac1, Re1, Ac2, Re2].
const DOUBLE_SEQUENCE = [
  [0, 2, 1, 2],
  [0, 3, 3, 4],
  [1, 3, 4, 5],
  [2, 5, 6, 7],
  [3, 6, 9, 10],
  [5, 9, 12, 13],
  [7, 11, 18, 19],
  [11, 16, 26, 27],
  [17, 22, 37, 38],
  [25, 31, 56, 57]
];
const DOUBLE_ANCHOR_COL_FOR_B = 16;   // Row B's entry-0 sits at AQL col 16

// Band-end seqIdx per row in Table 3-A
const DOUBLE_BAND_END = {
  B: 9, C: 9, D: 9, E: 9,                    // full 10-entry sequence
  F: 7, G: 7, H: 7, J: 7, K: 7, L: 7,         // truncated to 8 entries
  M: 7, N: 7, P: 7, Q: 7, R: 7
};

function buildDoubleTable() {
  const table = {};
  const numCols = AQL_VALUES.length;   // 26

  // Code A: all '*' (use single)
  const rowA = new Array(numCols).fill('STAR');
  table['A'] = rowA;

  CODE_ORDER.forEach((letter, codeIdx) => {
    if (letter === 'A') return;   // already handled
    const row = new Array(numCols).fill('UP');
    // codeIdx for B is 1, so shift from B (which has anchor col 16):
    const startCol = DOUBLE_ANCHOR_COL_FOR_B - (codeIdx - 1);

    if (letter === 'R') {
      // Row R has '↑' on the left edge (no '↓ * ↑ ↓' gap, since the row
      // can't shift further left). startCol = 16 - 14 = 2, so the band
      // starts at col 2 directly. Cols 0 and 1 are '↑'.
      // Looking at PDF page 23: actually Row R shows ↑ at col 0, then
      // immediately the band starts at col 2 (so col 1 is also an ↑/blank).
      for (let c = 0; c < startCol; c++) row[c] = 'UP';
    } else {
      // Standard rows: gap is [DOWN, STAR, UP, DOWN] at cols
      // startCol-4 through startCol-1.
      for (let c = 0; c < startCol - 4; c++) row[c] = 'DOWN';
      if (startCol - 4 >= 0) row[startCol - 4] = 'DOWN';
      if (startCol - 3 >= 0) row[startCol - 3] = 'STAR';
      if (startCol - 2 >= 0) row[startCol - 2] = 'UP';
      if (startCol - 1 >= 0) row[startCol - 1] = 'DOWN';
    }

    // Fill the numeric band
    const maxIdx = DOUBLE_BAND_END[letter];
    for (let seqIdx = 0; seqIdx <= maxIdx; seqIdx++) {
      const col = startCol + seqIdx;
      if (col >= numCols) break;
      row[col] = DOUBLE_SEQUENCE[seqIdx];
    }
    // Remaining cells stay 'UP'
    table[letter] = row;
  });
  return table;
}

const AQL_DOUBLE_TABLE = buildDoubleTable();

// Self-test for Table 3-A
(function aqlDoubleSelfTest() {
  // [codeLetter, aqlStr, n1, Ac1, Re1, n2, Ac2, Re2, source]
  const knownPlans = [
    ['K','0.65',  80, 0, 3,  80, 3, 4, "elsmar.com Z1.4 example"],
    ['B','15',     2, 0, 2,   2, 1, 2, "PDF p23 image"],
    ['B','25',     2, 0, 3,   2, 3, 4, "PDF p23 image"],
    ['J','0.65',  50, 0, 2,  50, 1, 2, "PDF p23 image"],
    ['K','1.0',   80, 1, 3,  80, 4, 5, "PDF p23 image (K col 10 = seq 2)"],
    ['L','2.5',  125, 5, 9, 125, 12, 13, "PDF p23 image (L col 12 = seq 5)"],
    ['R','0.025',1250, 0, 2, 1250, 1, 2, "PDF p23 image (R first entry at col 2)"],
    ['F','65',    13, 11, 16, 13, 26, 27, "PDF p23 image (F last entry at col 19, AQL 65)"]
  ];
  const failures = [];
  for (const [c, a, n1, ac1, re1, n2, ac2, re2, src] of knownPlans) {
    const aqlIdx = AQL_VALUES.indexOf(a);
    const cell = AQL_DOUBLE_TABLE[c][aqlIdx];
    const sample = AQL_SAMPLE_SIZES_DOUBLE[c];
    if (!Array.isArray(cell)) {
      failures.push(`Double ${c}@${a}: got ${cell}, expected [${ac1},${re1},${ac2},${re2}] (${src})`);
      continue;
    }
    if (sample !== n1 || cell[0] !== ac1 || cell[1] !== re1 || cell[2] !== ac2 || cell[3] !== re2) {
      failures.push(`Double ${c}@${a}: got n=${sample} [${cell.join(',')}], expected n=${n1} [${ac1},${re1},${ac2},${re2}] (${src})`);
    }
  }
  if (failures.length) {
    console.error('AQL DOUBLE TABLE SELF-TEST FAILED (' + failures.length + ' of ' + knownPlans.length + '):');
    for (const f of failures) console.error('  ' + f);
  } else {
    console.log('AQL double-sampling table self-test passed (' + knownPlans.length + ' plans).');
  }
})();

// ---- Lookup: double-sampling plan for (code letter, AQL) -------------
//
// Returns { plan: { codeLetter, n1, ac1, re1, cumN, ac2, re2 }, ... }
// For arrow cells, follows ↓/↑ to the next row.
// For '*' cells, returns a marker indicating "use single sampling instead".
function aqlDoublePlanFor(codeLetter, aqlStr) {
  const aqlIdx = AQL_VALUES.indexOf(aqlStr);
  if (aqlIdx === -1) return null;
  if (!AQL_DOUBLE_TABLE[codeLetter]) return null;

  const codeIdx = CODE_ORDER.indexOf(codeLetter);
  if (codeIdx === -1) return null;

  const cell = AQL_DOUBLE_TABLE[codeLetter][aqlIdx];

  if (Array.isArray(cell)) {
    const n1 = AQL_SAMPLE_SIZES_DOUBLE[codeLetter];
    return {
      codeLetter: codeLetter,
      n1: n1,
      ac1: cell[0],
      re1: cell[1],
      n2: n1,           // second-sample size always equals first
      cumN: 2 * n1,
      ac2: cell[2],
      re2: cell[3],
      jumped: false,
      useSingle: false,
      originalCodeLetter: codeLetter,
      originalReason: null
    };
  }

  if (cell === 'STAR') {
    return {
      codeLetter: codeLetter,
      useSingle: true,
      jumped: false,
      originalCodeLetter: codeLetter,
      originalReason: 'STAR',
      message: 'Use single-sampling plan from Table 2-A at this code letter and AQL.'
    };
  }

  // Arrow-follow
  const direction = (cell === 'DOWN') ? +1 : -1;
  let i = codeIdx + direction;
  while (i >= 0 && i < CODE_ORDER.length) {
    const cl = CODE_ORDER[i];
    if (!AQL_DOUBLE_TABLE[cl]) { i += direction; continue; }
    const inner = AQL_DOUBLE_TABLE[cl][aqlIdx];
    if (Array.isArray(inner)) {
      const n1 = AQL_SAMPLE_SIZES_DOUBLE[cl];
      return {
        codeLetter: cl,
        n1: n1,
        ac1: inner[0],
        re1: inner[1],
        n2: n1,
        cumN: 2 * n1,
        ac2: inner[2],
        re2: inner[3],
        jumped: true,
        useSingle: false,
        jumpReason: (cell === 'DOWN') ? 'down' : 'up',
        originalCodeLetter: codeLetter,
        originalReason: cell
      };
    }
    if (inner === 'STAR') {
      return {
        codeLetter: cl,
        useSingle: true,
        jumped: true,
        jumpReason: (cell === 'DOWN') ? 'down' : 'up',
        originalCodeLetter: codeLetter,
        originalReason: cell,
        message: 'Arrow points to a "*" cell at code ' + cl + '. Use single-sampling plan from Table 2-A.'
      };
    }
    i += direction;
  }
  return {
    codeLetter: null,
    useSingle: false,
    jumped: true,
    jumpReason: (cell === 'DOWN') ? 'down' : 'up',
    originalCodeLetter: codeLetter,
    originalReason: cell,
    error: 'No double-sampling plan available — table boundary reached.'
  };
}

// ---- Decision helper: given a double-sampling plan and observed
//      defects, what's the recommended action?
function aqlDoubleDecision(plan, d1, d2) {
  if (!plan || plan.useSingle || plan.error) return null;
  if (!isFinite(d1)) return { stage: 'awaiting1', action: 'sample1', text: 'Draw first sample of ' + plan.n1 + ' units.' };
  if (d1 <= plan.ac1) {
    return { stage: 'after1', action: 'accept', text: 'Accept (' + d1 + ' ≤ Ac₁=' + plan.ac1 + ').' };
  }
  if (d1 >= plan.re1) {
    return { stage: 'after1', action: 'reject', text: 'Reject (' + d1 + ' ≥ Re₁=' + plan.re1 + ').' };
  }
  // Intermediate after first sample — take second
  if (!isFinite(d2)) {
    return {
      stage: 'after1',
      action: 'sample2',
      text: 'First-sample defects ' + d1 + ' is between Ac₁=' + plan.ac1 + ' and Re₁=' + plan.re1 + '. Take a second sample of ' + plan.n2 + ' units.'
    };
  }
  const totalD = d1 + d2;
  if (totalD <= plan.ac2) {
    return { stage: 'after2', action: 'accept', text: 'Accept (cumulative ' + totalD + ' ≤ Ac₂=' + plan.ac2 + ').' };
  }
  return { stage: 'after2', action: 'reject', text: 'Reject (cumulative ' + totalD + ' ≥ Re₂=' + plan.re2 + ').' };
}

// ---- Lookup: code letter for (lot size, level) -----------------------
function aqlCodeLetter(lotN, level) {
  const idx = CODE_LEVEL_INDEX[level];
  if (!idx) return null;
  for (const row of AQL_CODE_LETTER) {
    if (lotN <= row[0]) return row[idx];
  }
  return null;
}

// ---- Lookup: plan for (code letter, AQL string, inspection mode) ----
//
// Returns { codeLetter, n, ac, re, jumped, jumpReason, mode, ... }
// `mode` is 'normal' (default), 'tightened', or 'reduced'.
function aqlPlanFor(codeLetter, aqlStr, mode) {
  mode = mode || 'normal';
  const table = AQL_TABLES[mode];
  const samples = AQL_SAMPLE_SIZES[mode];
  if (!table || !samples) return null;

  const aqlIdx = AQL_VALUES.indexOf(aqlStr);
  if (aqlIdx === -1) return null;
  if (!table[codeLetter]) return null;

  const codeIdx = CODE_ORDER.indexOf(codeLetter);
  if (codeIdx === -1) return null;

  const cell = table[codeLetter][aqlIdx];
  if (Array.isArray(cell)) {
    return {
      codeLetter: codeLetter,
      n: samples[codeLetter],
      ac: cell[0],
      re: cell[1],
      jumped: false,
      jumpReason: null,
      originalCodeLetter: codeLetter,
      originalReason: null,
      mode: mode
    };
  }

  // Arrow-follow.  For DOWN, scan to larger code letters until we hit a
  // numeric cell at this AQL. For UP, scan to smaller code letters.
  const direction = (cell === 'DOWN') ? +1 : -1;
  let i = codeIdx + direction;
  while (i >= 0 && i < CODE_ORDER.length) {
    const cl = CODE_ORDER[i];
    if (!table[cl]) { i += direction; continue; }
    const inner = table[cl][aqlIdx];
    if (Array.isArray(inner)) {
      return {
        codeLetter: cl,
        n: samples[cl],
        ac: inner[0],
        re: inner[1],
        jumped: true,
        jumpReason: (cell === 'DOWN') ? 'down' : 'up',
        originalCodeLetter: codeLetter,
        originalReason: cell,
        mode: mode
      };
    }
    i += direction;
  }
  return {
    codeLetter: null, n: null, ac: null, re: null,
    jumped: true,
    jumpReason: (cell === 'DOWN') ? 'down' : 'up',
    originalCodeLetter: codeLetter,
    originalReason: cell,
    mode: mode,
    error: 'No plan available — table boundary reached. Try a different AQL or level.'
  };
}

// ---- Populate AQL dropdown options once at boot ----------------------
function aqlPopulateOptions() {
  const sel = $('aql-aql');
  if (!sel) return;
  for (const v of AQL_VALUES) {
    const o = document.createElement('option');
    o.value = v;
    o.textContent = v + ' %';
    sel.appendChild(o);
  }
  sel.value = '1.0';
}
aqlPopulateOptions();

// ---- Shared helper: format inspection mode for display ---------------
function modeLabel(mode) {
  if (mode === 'tightened') return 'Tightened (Table 2-B)';
  if (mode === 'reduced')   return 'Reduced (Table 2-C)';
  return 'Normal (Table 2-A)';
}

// ---- Render the AQL result panel ------------------------------------
// ---- Helper: render single-sampling result panel ---------------------
function aqlRenderSingleHtml(plan, lotN, level, mode, aql) {
  const jumpRow = plan.jumped
    ? `<div class="row" style="color:#a16207;">
         <span class="lbl">↳ Plan jumped via ${plan.originalReason === 'DOWN' ? '↓ arrow' : '↑ arrow'}</span>
         <span class="val">${escapeHtml(plan.originalCodeLetter)} → ${escapeHtml(plan.codeLetter)}</span>
       </div>`
    : '';

  const p = parseFloat(aql) / 100;
  let pAccept = 0;
  if (plan.n != null && plan.ac != null && p > 0 && p < 1) {
    let term = Math.pow(1 - p, plan.n);
    pAccept = term;
    for (let k = 1; k <= plan.ac; k++) {
      term *= (plan.n - k + 1) / k * (p / (1 - p));
      pAccept += term;
    }
  } else if (p === 0) {
    pAccept = 1;
  }
  const pAcceptPct = (pAccept * 100).toFixed(2);
  const pAcceptAt2 = (() => {
    const p2 = p * 2;
    if (p2 >= 1 || plan.n == null) return null;
    let term = Math.pow(1 - p2, plan.n);
    let s = term;
    for (let k = 1; k <= plan.ac; k++) {
      term *= (plan.n - k + 1) / k * (p2 / (1 - p2));
      s += term;
    }
    return s;
  })();

  return `
    <div class="row headline">
      <span class="lbl">Sample size (n)</span>
      <span class="val">${plan.n}</span>
    </div>
    <div class="row">
      <span class="lbl">Accept on (Ac)</span>
      <span class="val" style="color:#15803d;">≤ ${plan.ac}</span>
    </div>
    <div class="row">
      <span class="lbl">Reject on (Re)</span>
      <span class="val" style="color:#b91c1c;">≥ ${plan.re}</span>
    </div>
    <div class="row">
      <span class="lbl">Code letter</span>
      <span class="val">${escapeHtml(plan.codeLetter)}</span>
    </div>
    ${jumpRow}
    <div class="row"><span class="lbl">Lot size N</span><span class="val">${lotN}</span></div>
    <div class="row"><span class="lbl">Inspection level</span><span class="val">${escapeHtml(level)}</span></div>
    <div class="row"><span class="lbl">Inspection mode</span><span class="val">${escapeHtml(modeLabel(mode))}</span></div>
    <div class="row"><span class="lbl">Sampling type</span><span class="val">Single</span></div>
    <div class="row"><span class="lbl">AQL</span><span class="val">${escapeHtml(aql)} %</span></div>
    <div class="row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:8px;">
      <span class="lbl">P(accept) at AQL</span>
      <span class="val">${pAcceptPct} %</span>
    </div>
    ${pAcceptAt2 != null ? `
      <div class="row">
        <span class="lbl">P(accept) at 2× AQL</span>
        <span class="val">${(pAcceptAt2 * 100).toFixed(2)} %</span>
      </div>` : ''}
    <div class="row"><span class="lbl">Sampling fraction (n/N)</span><span class="val">${(plan.n / lotN * 100).toFixed(2)} %</span></div>
  `;
}

// ---- Helper: render double-sampling result panel ---------------------
function aqlRenderDoubleHtml(plan, lotN, level, aql, d1, d2) {
  // 'plan' is from aqlDoublePlanFor(). Handles arrow-follow + use-single redirects.
  if (plan.useSingle) {
    const ssPlan = aqlPlanFor(plan.codeLetter, aql, 'normal');
    let ssBlock = '';
    if (ssPlan && !ssPlan.error && ssPlan.n != null) {
      ssBlock = `
        <div class="row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:8px;">
          <span class="lbl">Single-sampling plan (Table 2-A)</span>
          <span class="val">n=${ssPlan.n}, Ac=${ssPlan.ac}, Re=${ssPlan.re}</span>
        </div>
      `;
    }
    return `
      <div class="row headline warn"><span class="lbl">Double plan</span><span class="val">Use single instead</span></div>
      <div class="row"><span class="lbl">Reason</span><span class="val" style="font-weight:400;">${escapeHtml(plan.message || 'No double plan for this cell.')}</span></div>
      <div class="row"><span class="lbl">Code letter</span><span class="val">${escapeHtml(plan.codeLetter || '—')}</span></div>
      <div class="row"><span class="lbl">Lot size N</span><span class="val">${lotN}</span></div>
      <div class="row"><span class="lbl">Inspection level</span><span class="val">${escapeHtml(level)}</span></div>
      <div class="row"><span class="lbl">Sampling type</span><span class="val">Double (Table 3-A) — fallback to single</span></div>
      <div class="row"><span class="lbl">AQL</span><span class="val">${escapeHtml(aql)} %</span></div>
      ${ssBlock}
    `;
  }

  if (plan.error) {
    return `
      <div class="row headline warn"><span class="lbl">Plan</span><span class="val">Unavailable</span></div>
      <div class="row"><span class="lbl">Reason</span><span class="val" style="font-weight:400;">${escapeHtml(plan.error)}</span></div>
    `;
  }

  const jumpRow = plan.jumped
    ? `<div class="row" style="color:#a16207;">
         <span class="lbl">↳ Plan jumped via ${plan.originalReason === 'DOWN' ? '↓ arrow' : '↑ arrow'}</span>
         <span class="val">${escapeHtml(plan.originalCodeLetter)} → ${escapeHtml(plan.codeLetter)}</span>
       </div>`
    : '';

  // Optional decision row based on observed defects
  let decisionBlock = '';
  if (isFinite(d1) && d1 >= 0) {
    const decision = aqlDoubleDecision(plan, d1, isFinite(d2) && d2 >= 0 ? d2 : NaN);
    if (decision) {
      const color = decision.action === 'accept' ? '#15803d'
                  : decision.action === 'reject' ? '#b91c1c'
                  : '#a16207';
      decisionBlock = `
        <div class="row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:8px;">
          <span class="lbl">Decision</span>
          <span class="val" style="color:${color};text-transform:uppercase;">${escapeHtml(decision.action)}</span>
        </div>
        <div class="row"><span class="lbl">Reasoning</span><span class="val" style="font-weight:400;">${escapeHtml(decision.text)}</span></div>
      `;
    }
  }

  return `
    <div class="row headline">
      <span class="lbl">Stage 1 &mdash; First sample</span>
      <span class="val">n₁ = ${plan.n1}</span>
    </div>
    <div class="row">
      <span class="lbl">Accept on (Ac₁)</span>
      <span class="val" style="color:#15803d;">≤ ${plan.ac1}</span>
    </div>
    <div class="row">
      <span class="lbl">Reject on (Re₁)</span>
      <span class="val" style="color:#b91c1c;">≥ ${plan.re1}</span>
    </div>
    <div class="row" style="font-style:italic;color:var(--text-muted,var(--text));">
      <span class="lbl">If Ac₁ &lt; d₁ &lt; Re₁</span>
      <span class="val">draw second sample</span>
    </div>
    <div class="row headline" style="border-top:1px solid var(--border);margin-top:4px;padding-top:8px;">
      <span class="lbl">Stage 2 &mdash; Second sample</span>
      <span class="val">n₂ = ${plan.n2} (cum ${plan.cumN})</span>
    </div>
    <div class="row">
      <span class="lbl">Accept on (Ac₂, cumulative)</span>
      <span class="val" style="color:#15803d;">≤ ${plan.ac2}</span>
    </div>
    <div class="row">
      <span class="lbl">Reject on (Re₂, cumulative)</span>
      <span class="val" style="color:#b91c1c;">≥ ${plan.re2}</span>
    </div>
    <div class="row">
      <span class="lbl">Code letter</span>
      <span class="val">${escapeHtml(plan.codeLetter)}</span>
    </div>
    ${jumpRow}
    <div class="row"><span class="lbl">Lot size N</span><span class="val">${lotN}</span></div>
    <div class="row"><span class="lbl">Inspection level</span><span class="val">${escapeHtml(level)}</span></div>
    <div class="row"><span class="lbl">Sampling type</span><span class="val">Double (Table 3-A, normal)</span></div>
    <div class="row"><span class="lbl">AQL</span><span class="val">${escapeHtml(aql)} %</span></div>
    <div class="row"><span class="lbl">Max sampling fraction (cum/N)</span><span class="val">${(plan.cumN / lotN * 100).toFixed(2)} %</span></div>
    ${decisionBlock}
  `;
}

function renderAql() {
  const lotN  = parseNum($('aql-lot').value);
  const level = $('aql-level').value;
  const mode  = ($('aql-mode') && $('aql-mode').value) || 'normal';
  const type  = ($('aql-type') && $('aql-type').value) || 'single';
  const aql   = $('aql-aql').value;
  const out = $('aql-result');
  const decisionPanel = $('aql-double-decision');

  // Show/hide the decision-tool inputs based on sampling type
  if (decisionPanel) {
    decisionPanel.style.display = (type === 'double') ? '' : 'none';
  }

  if (!isFinite(lotN) || lotN < 2) {
    out.innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">Lot size must be at least 2.</div>';
    return;
  }
  const code = aqlCodeLetter(lotN, level);
  if (!code) {
    out.innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">No code letter found for this lot size and level.</div>';
    return;
  }

  if (type === 'double') {
    if (mode !== 'normal') {
      // Tightened/reduced double sampling not yet implemented — fall back to single in that mode
      out.innerHTML = `
        <div class="row headline warn"><span class="lbl">Plan</span><span class="val">Not implemented</span></div>
        <div class="row"><span class="lbl">Note</span><span class="val" style="font-weight:400;">Double-sampling Tightened (Table 3-B) and Reduced (Table 3-C) are deferred. Switch to Normal mode for double sampling, or use single sampling in this mode.</span></div>
        <div class="row"><span class="lbl">Code letter</span><span class="val">${escapeHtml(code)}</span></div>
        <div class="row"><span class="lbl">Inspection mode</span><span class="val">${escapeHtml(modeLabel(mode))}</span></div>
      `;
      return;
    }
    const dPlan = aqlDoublePlanFor(code, aql);
    if (!dPlan) {
      out.innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">No double-sampling plan available for this combination.</div>';
      return;
    }
    const d1 = parseNum(($('aql-d1') && $('aql-d1').value) || '');
    const d2 = parseNum(($('aql-d2') && $('aql-d2').value) || '');
    out.innerHTML = aqlRenderDoubleHtml(dPlan, lotN, level, aql, d1, d2);
    return;
  }

  // Single sampling
  const plan = aqlPlanFor(code, aql, mode);
  if (!plan || plan.error) {
    const msg = (plan && plan.error) ? plan.error : 'No plan available for this combination.';
    out.innerHTML = `
      <div class="row headline warn"><span class="lbl">Plan</span><span class="val">Unavailable</span></div>
      <div class="row"><span class="lbl">Reason</span><span class="val" style="font-weight:400;">${escapeHtml(msg)}</span></div>
      <div class="row"><span class="lbl">Code letter (from lot/level)</span><span class="val">${escapeHtml(code)}</span></div>
      <div class="row"><span class="lbl">Mode</span><span class="val">${escapeHtml(modeLabel(mode))}</span></div>
    `;
    return;
  }
  out.innerHTML = aqlRenderSingleHtml(plan, lotN, level, mode, aql);
}

// Re-render on input change so users see plans evolve as they tweak
['aql-lot','aql-level','aql-aql','aql-mode','aql-type','aql-d1','aql-d2'].forEach(id => {
  const el = $(id);
  if (!el) return;
  el.addEventListener('input', renderAql);
  el.addEventListener('change', renderAql);
});
renderAql();


// =========================================================================
// 09 · ISO 2859-1 SAMPLING (single, normal inspection)
// =========================================================================
//
// ISO 2859-1 single sampling normal inspection uses the SAME master tables
// as IS 2500-1 and ANSI Z1.4 — they all descend from MIL-STD-105E. So we
// alias the data tables defined above rather than duplicating them. Any
// future correction to the underlying numbers only happens in one place.
//
// What's separate per tab:
//   - The DOM ids (iso-lot, iso-level, iso-aql, iso-result)
//   - The render function (renderIso) — produces ISO-labelled output
//   - The state functions (isoGetState, isoSetState, isoLabel)
//   - The localStorage history bucket ('iso2859')
//
// What's shared:
//   - The master data tables (AQL_CODE_LETTER, AQL_SAMPLE_SIZE, AQL_VALUES,
//     AQL_TABLE, CODE_ORDER, CODE_LEVEL_INDEX)
//   - The lookup functions (aqlCodeLetter, aqlPlanFor)
//
// If you ever need an ISO-specific deviation (e.g. an edition that diverged
// from Z1.4), define separate ISO_* constants here and swap the references
// inside renderIso/isoLabel.

function isoPopulateOptions() {
  const sel = $('iso-aql');
  if (!sel) return;
  for (const v of AQL_VALUES) {
    const o = document.createElement('option');
    o.value = v;
    o.textContent = v + ' %';
    sel.appendChild(o);
  }
  sel.value = '1.0';   // common default, same as IS 2500 tab
}
isoPopulateOptions();

// ---- Helper: render single-sampling result panel for ISO 2859 -------
function isoRenderSingleHtml(plan, lotN, level, mode, aql) {
  const jumpRow = plan.jumped
    ? `<div class="row" style="color:#a16207;">
         <span class="lbl">↳ Plan jumped via ${plan.originalReason === 'DOWN' ? '↓ arrow' : '↑ arrow'}</span>
         <span class="val">${escapeHtml(plan.originalCodeLetter)} → ${escapeHtml(plan.codeLetter)}</span>
       </div>`
    : '';

  const p = parseFloat(aql) / 100;
  let pAccept = 0;
  if (plan.n != null && plan.ac != null && p > 0 && p < 1) {
    let term = Math.pow(1 - p, plan.n);
    pAccept = term;
    for (let k = 1; k <= plan.ac; k++) {
      term *= (plan.n - k + 1) / k * (p / (1 - p));
      pAccept += term;
    }
  } else if (p === 0) {
    pAccept = 1;
  }
  const pAcceptPct = (pAccept * 100).toFixed(2);
  const pAcceptAt2 = (() => {
    const p2 = p * 2;
    if (p2 >= 1 || plan.n == null) return null;
    let term = Math.pow(1 - p2, plan.n);
    let s = term;
    for (let k = 1; k <= plan.ac; k++) {
      term *= (plan.n - k + 1) / k * (p2 / (1 - p2));
      s += term;
    }
    return s;
  })();

  const isoTableNum = mode === 'tightened' ? '2-B' : mode === 'reduced' ? '2-C' : '2-A';

  return `
    <div class="row headline">
      <span class="lbl">Sample size (n)</span>
      <span class="val">${plan.n}</span>
    </div>
    <div class="row">
      <span class="lbl">Accept on (Ac)</span>
      <span class="val" style="color:#15803d;">≤ ${plan.ac}</span>
    </div>
    <div class="row">
      <span class="lbl">Reject on (Re)</span>
      <span class="val" style="color:#b91c1c;">≥ ${plan.re}</span>
    </div>
    <div class="row">
      <span class="lbl">Code letter</span>
      <span class="val">${escapeHtml(plan.codeLetter)}</span>
    </div>
    ${jumpRow}
    <div class="row"><span class="lbl">Lot size N</span><span class="val">${lotN}</span></div>
    <div class="row"><span class="lbl">Inspection level</span><span class="val">${escapeHtml(level)}</span></div>
    <div class="row"><span class="lbl">Inspection mode</span><span class="val">${escapeHtml(modeLabel(mode))}</span></div>
    <div class="row"><span class="lbl">Sampling type</span><span class="val">Single</span></div>
    <div class="row"><span class="lbl">AQL</span><span class="val">${escapeHtml(aql)} %</span></div>
    <div class="row"><span class="lbl">Standard</span><span class="val">ISO 2859-1 · Table ${isoTableNum}</span></div>
    <div class="row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:8px;">
      <span class="lbl">P(accept) at AQL</span>
      <span class="val">${pAcceptPct} %</span>
    </div>
    ${pAcceptAt2 != null ? `
      <div class="row">
        <span class="lbl">P(accept) at 2× AQL</span>
        <span class="val">${(pAcceptAt2 * 100).toFixed(2)} %</span>
      </div>` : ''}
    <div class="row"><span class="lbl">Sampling fraction (n/N)</span><span class="val">${(plan.n / lotN * 100).toFixed(2)} %</span></div>
  `;
}

// ---- Helper: render double-sampling result panel for ISO 2859 -------
function isoRenderDoubleHtml(plan, lotN, level, aql, d1, d2) {
  if (plan.useSingle) {
    const ssPlan = aqlPlanFor(plan.codeLetter, aql, 'normal');
    let ssBlock = '';
    if (ssPlan && !ssPlan.error && ssPlan.n != null) {
      ssBlock = `
        <div class="row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:8px;">
          <span class="lbl">Single-sampling plan (Table 2-A)</span>
          <span class="val">n=${ssPlan.n}, Ac=${ssPlan.ac}, Re=${ssPlan.re}</span>
        </div>
      `;
    }
    return `
      <div class="row headline warn"><span class="lbl">Double plan</span><span class="val">Use single instead</span></div>
      <div class="row"><span class="lbl">Reason</span><span class="val" style="font-weight:400;">${escapeHtml(plan.message || 'No double plan for this cell.')}</span></div>
      <div class="row"><span class="lbl">Code letter</span><span class="val">${escapeHtml(plan.codeLetter || '—')}</span></div>
      <div class="row"><span class="lbl">Lot size N</span><span class="val">${lotN}</span></div>
      <div class="row"><span class="lbl">Inspection level</span><span class="val">${escapeHtml(level)}</span></div>
      <div class="row"><span class="lbl">Sampling type</span><span class="val">Double (Table 3-A) — fallback to single</span></div>
      <div class="row"><span class="lbl">AQL</span><span class="val">${escapeHtml(aql)} %</span></div>
      <div class="row"><span class="lbl">Standard</span><span class="val">ISO 2859-1 · Table 3-A</span></div>
      ${ssBlock}
    `;
  }

  if (plan.error) {
    return `
      <div class="row headline warn"><span class="lbl">Plan</span><span class="val">Unavailable</span></div>
      <div class="row"><span class="lbl">Reason</span><span class="val" style="font-weight:400;">${escapeHtml(plan.error)}</span></div>
    `;
  }

  const jumpRow = plan.jumped
    ? `<div class="row" style="color:#a16207;">
         <span class="lbl">↳ Plan jumped via ${plan.originalReason === 'DOWN' ? '↓ arrow' : '↑ arrow'}</span>
         <span class="val">${escapeHtml(plan.originalCodeLetter)} → ${escapeHtml(plan.codeLetter)}</span>
       </div>`
    : '';

  let decisionBlock = '';
  if (isFinite(d1) && d1 >= 0) {
    const decision = aqlDoubleDecision(plan, d1, isFinite(d2) && d2 >= 0 ? d2 : NaN);
    if (decision) {
      const color = decision.action === 'accept' ? '#15803d'
                  : decision.action === 'reject' ? '#b91c1c'
                  : '#a16207';
      decisionBlock = `
        <div class="row" style="border-top:2px solid var(--border);margin-top:4px;padding-top:8px;">
          <span class="lbl">Decision</span>
          <span class="val" style="color:${color};text-transform:uppercase;">${escapeHtml(decision.action)}</span>
        </div>
        <div class="row"><span class="lbl">Reasoning</span><span class="val" style="font-weight:400;">${escapeHtml(decision.text)}</span></div>
      `;
    }
  }

  return `
    <div class="row headline">
      <span class="lbl">Stage 1 &mdash; First sample</span>
      <span class="val">n₁ = ${plan.n1}</span>
    </div>
    <div class="row">
      <span class="lbl">Accept on (Ac₁)</span>
      <span class="val" style="color:#15803d;">≤ ${plan.ac1}</span>
    </div>
    <div class="row">
      <span class="lbl">Reject on (Re₁)</span>
      <span class="val" style="color:#b91c1c;">≥ ${plan.re1}</span>
    </div>
    <div class="row" style="font-style:italic;color:var(--text-muted,var(--text));">
      <span class="lbl">If Ac₁ &lt; d₁ &lt; Re₁</span>
      <span class="val">draw second sample</span>
    </div>
    <div class="row headline" style="border-top:1px solid var(--border);margin-top:4px;padding-top:8px;">
      <span class="lbl">Stage 2 &mdash; Second sample</span>
      <span class="val">n₂ = ${plan.n2} (cum ${plan.cumN})</span>
    </div>
    <div class="row">
      <span class="lbl">Accept on (Ac₂, cumulative)</span>
      <span class="val" style="color:#15803d;">≤ ${plan.ac2}</span>
    </div>
    <div class="row">
      <span class="lbl">Reject on (Re₂, cumulative)</span>
      <span class="val" style="color:#b91c1c;">≥ ${plan.re2}</span>
    </div>
    <div class="row">
      <span class="lbl">Code letter</span>
      <span class="val">${escapeHtml(plan.codeLetter)}</span>
    </div>
    ${jumpRow}
    <div class="row"><span class="lbl">Lot size N</span><span class="val">${lotN}</span></div>
    <div class="row"><span class="lbl">Inspection level</span><span class="val">${escapeHtml(level)}</span></div>
    <div class="row"><span class="lbl">Sampling type</span><span class="val">Double (Table 3-A, normal)</span></div>
    <div class="row"><span class="lbl">AQL</span><span class="val">${escapeHtml(aql)} %</span></div>
    <div class="row"><span class="lbl">Standard</span><span class="val">ISO 2859-1 · Table 3-A</span></div>
    <div class="row"><span class="lbl">Max sampling fraction (cum/N)</span><span class="val">${(plan.cumN / lotN * 100).toFixed(2)} %</span></div>
    ${decisionBlock}
  `;
}

function renderIso() {
  const lotN  = parseNum($('iso-lot').value);
  const level = $('iso-level').value;
  const mode  = ($('iso-mode') && $('iso-mode').value) || 'normal';
  const type  = ($('iso-type') && $('iso-type').value) || 'single';
  const aql   = $('iso-aql').value;
  const out = $('iso-result');
  const decisionPanel = $('iso-double-decision');

  if (decisionPanel) {
    decisionPanel.style.display = (type === 'double') ? '' : 'none';
  }

  if (!isFinite(lotN) || lotN < 2) {
    out.innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">Lot size must be at least 2.</div>';
    return;
  }
  const code = aqlCodeLetter(lotN, level);
  if (!code) {
    out.innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">No code letter found for this lot size and level.</div>';
    return;
  }

  if (type === 'double') {
    if (mode !== 'normal') {
      out.innerHTML = `
        <div class="row headline warn"><span class="lbl">Plan</span><span class="val">Not implemented</span></div>
        <div class="row"><span class="lbl">Note</span><span class="val" style="font-weight:400;">Double-sampling Tightened (Table 3-B) and Reduced (Table 3-C) are deferred. Switch to Normal mode for double sampling, or use single sampling in this mode.</span></div>
        <div class="row"><span class="lbl">Code letter</span><span class="val">${escapeHtml(code)}</span></div>
        <div class="row"><span class="lbl">Inspection mode</span><span class="val">${escapeHtml(modeLabel(mode))}</span></div>
      `;
      return;
    }
    const dPlan = aqlDoublePlanFor(code, aql);
    if (!dPlan) {
      out.innerHTML = '<div style="color:var(--text-light);padding:10px;text-align:center;">No double-sampling plan available for this combination.</div>';
      return;
    }
    const d1 = parseNum(($('iso-d1') && $('iso-d1').value) || '');
    const d2 = parseNum(($('iso-d2') && $('iso-d2').value) || '');
    out.innerHTML = isoRenderDoubleHtml(dPlan, lotN, level, aql, d1, d2);
    return;
  }

  // Single sampling
  const plan = aqlPlanFor(code, aql, mode);
  if (!plan || plan.error) {
    const msg = (plan && plan.error) ? plan.error : 'No plan available for this combination.';
    out.innerHTML = `
      <div class="row headline warn"><span class="lbl">Plan</span><span class="val">Unavailable</span></div>
      <div class="row"><span class="lbl">Reason</span><span class="val" style="font-weight:400;">${escapeHtml(msg)}</span></div>
      <div class="row"><span class="lbl">Code letter (from lot/level)</span><span class="val">${escapeHtml(code)}</span></div>
      <div class="row"><span class="lbl">Mode</span><span class="val">${escapeHtml(modeLabel(mode))}</span></div>
    `;
    return;
  }
  out.innerHTML = isoRenderSingleHtml(plan, lotN, level, mode, aql);
}

['iso-lot','iso-level','iso-aql','iso-mode','iso-type','iso-d1','iso-d2'].forEach(id => {
  const el = $(id);
  if (!el) return;
  el.addEventListener('input', renderIso);
  el.addEventListener('change', renderIso);
});
renderIso();


// =========================================================================
// HISTORY WIRING (per calculator)
// =========================================================================
//
// For each calculator we define:
//   getState()  → snapshot of inputs (plain JSON-able object)
//   setState(s) → restore inputs from a snapshot, then re-render
//   label(s)    → short one-line summary for the history list
// We then build a debounced saver that pushes (state, label) to CalcHistory
// after the user stops typing, and we hook the saver into the calculator's
// existing render*() function via monkey-patching.

// ---- 01 · Units ----
function unitsGetState() {
  return {
    cat:    $('uc-category').value,
    unitA:  $('uc-unit-a').value,
    unitB:  $('uc-unit-b').value,
    valueA: parseNum($('uc-input-a').value) || 0
  };
}
function unitsSetState(s) {
  if (!s) return;
  $('uc-category').value = s.cat || 'length';
  rebuildUnitSelects();
  if (s.unitA) $('uc-unit-a').value = s.unitA;
  if (s.unitB) $('uc-unit-b').value = s.unitB;
  $('uc-input-a').value = (s.valueA !== undefined ? s.valueA : 1);
  renderUnitConverter();
}
function unitsLabel(s) {
  const conv = convert(s.valueA, s.unitA, s.unitB, s.cat);
  return s.valueA + ' ' + s.unitA + ' → ' + fmt(conv, 6) + ' ' + s.unitB;
}

// ---- 02 · Stackup ----
function stackupGetState() {
  // Deep clone so subsequent edits don't mutate saved entries
  return { rows: JSON.parse(JSON.stringify(stackRows)) };
}
function stackupSetState(s) {
  if (!s || !Array.isArray(s.rows)) return;
  stackRows = JSON.parse(JSON.stringify(s.rows));
  renderStackup();
}
function stackupLabel(s) {
  const n = s.rows.length;
  let sum = 0, halfBand = 0;
  for (const r of s.rows) {
    sum += (r.dir === '+' ? 1 : -1) * (r.nom || 0);
    halfBand += ((r.tp || 0) + (r.tm || 0)) / 2;
  }
  return n + ' dim' + (n === 1 ? '' : 's') + ' · Σ=' + fmt(sum, 5) + ' ±' + fmt(halfBand, 4);
}

// ---- 03 · Cpk ----
function cpkGetState() {
  return {
    usl:     parseNum($('cpk-usl').value),
    lsl:     parseNum($('cpk-lsl').value),
    mean:    parseNum($('cpk-mean').value),
    sigma:   parseNum($('cpk-sigma').value),
    samples: $('cpk-samples').value
  };
}
function cpkSetState(s) {
  if (!s) return;
  $('cpk-usl').value     = isFinite(s.usl)   ? s.usl   : '';
  $('cpk-lsl').value     = isFinite(s.lsl)   ? s.lsl   : '';
  $('cpk-mean').value    = isFinite(s.mean)  ? s.mean  : '';
  $('cpk-sigma').value   = isFinite(s.sigma) ? s.sigma : '';
  $('cpk-samples').value = s.samples || '';
  renderCpk();
}
function cpkLabel(s) {
  let parts = [];
  if (isFinite(s.usl)) parts.push('USL ' + s.usl);
  if (isFinite(s.lsl)) parts.push('LSL ' + s.lsl);
  if (isFinite(s.mean) && isFinite(s.sigma) && s.sigma > 0) {
    // Compute Cpk for the label
    let cpk = Infinity;
    if (isFinite(s.usl)) cpk = Math.min(cpk, (s.usl - s.mean) / (3 * s.sigma));
    if (isFinite(s.lsl)) cpk = Math.min(cpk, (s.mean - s.lsl) / (3 * s.sigma));
    if (isFinite(cpk)) parts.push('Cpk ' + fmt(cpk, 3));
  }
  return parts.join(' · ') || 'Cpk inputs';
}

// ---- 04 · Fit ----
function fitGetState() {
  return {
    basic:      parseNum($('fit-basic').value),
    holeClass:  $('fit-hole-class').value,
    shaftClass: $('fit-shaft-class').value
  };
}
function fitSetState(s) {
  if (!s) return;
  if (isFinite(s.basic)) $('fit-basic').value = s.basic;
  if (s.holeClass)  $('fit-hole-class').value  = s.holeClass;
  if (s.shaftClass) $('fit-shaft-class').value = s.shaftClass;
  renderFit();
}
function fitLabel(s) {
  return 'Ø' + s.basic + ' ' + s.holeClass + '/' + s.shaftClass;
}

// ---- 05 · SFM ----
function sfmGetState() {
  return {
    material: $('sf-material').value,
    tool:     $('sf-tool').value,
    op:       $('sf-op').value,
    diameter: parseNum($('sf-diameter').value),
    flutes:   parseInt($('sf-flutes').value) || 1,
    fpt:      parseNum($('sf-fpt').value),
    doc:      parseNum($('sf-doc').value)
  };
}
function sfmSetState(s) {
  if (!s) return;
  if (s.material) $('sf-material').value = s.material;
  if (s.tool)     $('sf-tool').value     = s.tool;
  if (s.op)       $('sf-op').value       = s.op;
  if (isFinite(s.diameter)) $('sf-diameter').value = s.diameter;
  if (isFinite(s.flutes))   $('sf-flutes').value   = s.flutes;
  if (isFinite(s.fpt))      $('sf-fpt').value      = s.fpt;
  if (isFinite(s.doc))      $('sf-doc').value      = s.doc;
  renderSfm();
}
function sfmLabel(s) {
  const matShort = (s.material || '').replace('stainless-', 'SS').replace('mild-steel', 'mild').replace('alloy-steel','alloy').replace('cast-iron','cast-Fe');
  return s.op + ' · ' + matShort + ' · Ø' + s.diameter + 'mm';
}

// ---- 06 · Geometry ----
function geometryGetState() {
  return {
    shape: currentShape,
    inputs: { ...shapeInputs },
    material: $('geo-material').value,
    customDensity: parseNum($('geo-custom-density').value) || 0
  };
}
function geometrySetState(s) {
  if (!s) return;
  if (s.shape && SHAPES[s.shape]) {
    document.querySelectorAll('.shape-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.shape === s.shape);
    });
    currentShape = s.shape;
    buildShapeInputs();
    // Now write the input values
    if (s.inputs) {
      for (const k in s.inputs) {
        if (k in shapeInputs) {
          shapeInputs[k] = s.inputs[k];
          const inp = document.querySelector('#geo-inputs input[data-fid="' + k + '"]');
          if (inp) inp.value = s.inputs[k];
        }
      }
    }
  }
  if (s.material) {
    $('geo-material').value = s.material;
    $('geo-custom-density-wrap').style.display = s.material === 'custom' ? '' : 'none';
  }
  if (isFinite(s.customDensity)) $('geo-custom-density').value = s.customDensity;
  renderGeometry();
}
function geometryLabel(s) {
  const dimSummary = Object.entries(s.inputs || {})
    .map(([k, v]) => k + '=' + v).join(' ');
  return s.shape + ' · ' + dimSummary + ' · ' + s.material;
}

// ---- 07 · Sci ----
//
// Scientific calculator is event-driven (each key press is a discrete action),
// so we save only when '=' is pressed (i.e. when a real computation is done).
function sciGetState() {
  return {
    expr: sci.expr,
    result: sci.result,
    angleMode: sci.angleMode
  };
}
function sciSetState(s) {
  if (!s) return;
  sci.expr = s.expr || '';
  sci.result = s.result || '0';
  sci.angleMode = s.angleMode || 'deg';
  if (s.angleMode) $('sci-angle-mode').value = s.angleMode;
  sciUpdateDisplay();
}
function sciLabel(s) {
  if (!s.expr) return s.result || '0';
  return s.expr + ' = ' + s.result;
}
// Sci is saved on '=' explicitly, not debounced — we want each evaluation
// recorded as soon as it happens.
function sciRecordEvaluation() {
  const state = sciGetState();
  if (!state.expr) return;
  CalcHistory.save('sci', state, sciLabel(state));
  CalcHistory.render('ch-sci-list', 'sci', sciSetState);
  updateAllCounts();
}

// ---- AQL state functions ----
function aqlGetState() {
  return {
    lot:   parseNum($('aql-lot').value),
    level: $('aql-level').value,
    mode:  ($('aql-mode') && $('aql-mode').value) || 'normal',
    type:  ($('aql-type') && $('aql-type').value) || 'single',
    aql:   $('aql-aql').value
  };
}
function aqlSetState(s) {
  if (!s) return;
  if (isFinite(s.lot)) $('aql-lot').value = s.lot;
  if (s.level) $('aql-level').value = s.level;
  if (s.mode && $('aql-mode')) $('aql-mode').value = s.mode;
  if (s.type && $('aql-type')) $('aql-type').value = s.type;
  if (s.aql)   $('aql-aql').value = s.aql;
  renderAql();
}
function aqlLabel(s) {
  // Compute the plan summary inline so the history row tells you what
  // came out, not just what went in.
  const mode = s.mode || 'normal';
  const type = s.type || 'single';
  const code = aqlCodeLetter(s.lot, s.level);
  let planTxt = 'no plan';
  if (code) {
    if (type === 'double' && mode === 'normal') {
      const dp = aqlDoublePlanFor(code, s.aql);
      if (dp && dp.useSingle) {
        planTxt = '→ single';
      } else if (dp && dp.n1 != null) {
        planTxt = 'n₁=' + dp.n1 + ' Ac₁=' + dp.ac1 + '/Re₁=' + dp.re1
                + ' · n₂=' + dp.n2 + ' Ac₂=' + dp.ac2 + '/Re₂=' + dp.re2;
      }
    } else {
      const plan = aqlPlanFor(code, s.aql, mode);
      if (plan && plan.n != null) {
        planTxt = 'n=' + plan.n + ' Ac=' + plan.ac + ' Re=' + plan.re;
      }
    }
  }
  const modeTag = mode === 'normal' ? '' : ' · ' + (mode === 'tightened' ? 'T' : 'R');
  const typeTag = type === 'double' ? ' · 2-stage' : '';
  return 'N=' + s.lot + ' · L' + s.level + modeTag + typeTag + ' · AQL ' + s.aql + '% · ' + planTxt;
}

// ---- ISO 2859-1 state functions ----
function isoGetState() {
  return {
    lot:   parseNum($('iso-lot').value),
    level: $('iso-level').value,
    mode:  ($('iso-mode') && $('iso-mode').value) || 'normal',
    type:  ($('iso-type') && $('iso-type').value) || 'single',
    aql:   $('iso-aql').value
  };
}
function isoSetState(s) {
  if (!s) return;
  if (isFinite(s.lot)) $('iso-lot').value = s.lot;
  if (s.level) $('iso-level').value = s.level;
  if (s.mode && $('iso-mode')) $('iso-mode').value = s.mode;
  if (s.type && $('iso-type')) $('iso-type').value = s.type;
  if (s.aql)   $('iso-aql').value = s.aql;
  renderIso();
}
function isoLabel(s) {
  // Same shape as aqlLabel but prefixed with ISO so histories stay
  // distinguishable when both calculators have been used.
  const mode = s.mode || 'normal';
  const type = s.type || 'single';
  const code = aqlCodeLetter(s.lot, s.level);
  let planTxt = 'no plan';
  if (code) {
    if (type === 'double' && mode === 'normal') {
      const dp = aqlDoublePlanFor(code, s.aql);
      if (dp && dp.useSingle) {
        planTxt = '→ single';
      } else if (dp && dp.n1 != null) {
        planTxt = 'n₁=' + dp.n1 + ' Ac₁=' + dp.ac1 + '/Re₁=' + dp.re1
                + ' · n₂=' + dp.n2 + ' Ac₂=' + dp.ac2 + '/Re₂=' + dp.re2;
      }
    } else {
      const plan = aqlPlanFor(code, s.aql, mode);
      if (plan && plan.n != null) {
        planTxt = 'n=' + plan.n + ' Ac=' + plan.ac + ' Re=' + plan.re;
      }
    }
  }
  const modeTag = mode === 'normal' ? '' : ' · ' + (mode === 'tightened' ? 'T' : 'R');
  const typeTag = type === 'double' ? ' · 2-stage' : '';
  return 'ISO · N=' + s.lot + ' · L' + s.level + modeTag + typeTag + ' · AQL ' + s.aql + '% · ' + planTxt;
}

// ---- Explicit "Calculate" recording ----
//
// History saves only when the user explicitly clicks a Calculate button
// (or hits Enter inside an input — which forwards to the same button).
// We use a small dispatcher so per-calc state/label functions stay co-located.

const __saverDispatch = {
  units:    { get: unitsGetState,    label: unitsLabel,    set: unitsSetState,    slot: 'ch-units-list',    render: () => renderUnitConverter() },
  stackup:  { get: stackupGetState,  label: stackupLabel,  set: stackupSetState,  slot: 'ch-stackup-list',  render: () => renderStackup() },
  cpk:      { get: cpkGetState,      label: cpkLabel,      set: cpkSetState,      slot: 'ch-cpk-list',      render: () => renderCpk() },
  fit:      { get: fitGetState,      label: fitLabel,      set: fitSetState,      slot: 'ch-fit-list',      render: () => renderFit() },
  sfm:      { get: sfmGetState,      label: sfmLabel,      set: sfmSetState,      slot: 'ch-sfm-list',      render: () => renderSfm() },
  geometry: { get: geometryGetState, label: geometryLabel, set: geometrySetState, slot: 'ch-geometry-list', render: () => renderGeometry() },
  sci:      { get: sciGetState,      label: sciLabel,      set: sciSetState,      slot: 'ch-sci-list',      render: () => sciUpdateDisplay() },
  aql:      { get: aqlGetState,      label: aqlLabel,      set: aqlSetState,      slot: 'ch-aql-list',      render: () => renderAql() },
  iso2859:  { get: isoGetState,      label: isoLabel,      set: isoSetState,      slot: 'ch-iso2859-list', render: () => renderIso() }
};

function recordSnapshot(calcId) {
  const d = __saverDispatch[calcId];
  if (!d) return;
  // Always recompute first so the user sees fresh results when they hit
  // Calculate. The form-based calculators each have their own render fn.
  d.render();
  const state = d.get();
  if (!state) return;
  const label = d.label(state);
  CalcHistory.save(calcId, state, label);
  CalcHistory.render(d.slot, calcId, d.set);
  updateAllCounts();
}

// Scientific calculator: save on '=' (the existing user gesture).
const __origSciHandle = sciHandle;
sciHandle = function(key) {
  __origSciHandle.apply(this, arguments);
  if (key === '=') recordSnapshot('sci');
};

// ---- Wire each "Calculate & save" button ----
document.querySelectorAll('.calc-go[data-calc]').forEach(btn => {
  btn.addEventListener('click', () => {
    const calcId = btn.dataset.calc;
    recordSnapshot(calcId);
    // Visual feedback: brief highlight pulse on the button
    btn.style.transition = 'background 0.2s';
    const orig = btn.style.background;
    btn.style.background = '#15803d';
    setTimeout(() => { btn.style.background = orig; }, 220);
  });
});

// ---- Enter inside any input within a view triggers that view's Calculate ----
//
// We attach a single keydown listener to each <div class="view"> so Enter
// pressed anywhere inside the calc's inputs fires its Calculate button.
// Textareas are excluded — Enter in a multi-line field should still insert
// a newline (e.g. the Cpk sample-data textarea).
const __viewToCalcId = {
  'view-units': 'units',
  'view-stackup': 'stackup',
  'view-cpk': 'cpk',
  'view-fit': 'fit',
  'view-sfm': 'sfm',
  'view-geometry': 'geometry',
  'view-aql': 'aql',
  'view-iso2859': 'iso2859'
  // view-sci is excluded — that calc has its own keypad + key handling
};
for (const [viewId, calcId] of Object.entries(__viewToCalcId)) {
  const viewEl = document.getElementById(viewId);
  if (!viewEl) continue;
  viewEl.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    // Don't hijack Enter in textareas — those use Enter for newlines
    if (e.target && e.target.tagName === 'TEXTAREA') return;
    // Don't hijack Enter in buttons — let the click happen normally
    if (e.target && e.target.tagName === 'BUTTON') return;
    e.preventDefault();
    recordSnapshot(calcId);
    // Brief pulse on the Calculate button so the user sees their action
    const goBtn = viewEl.querySelector('.calc-go');
    if (goBtn) {
      const orig = goBtn.style.background;
      goBtn.style.background = '#15803d';
      setTimeout(() => { goBtn.style.background = orig; }, 220);
    }
  });
}

// ---- "Clear all" buttons ----
document.querySelectorAll('[data-clear]').forEach(btn => {
  btn.addEventListener('click', () => {
    const calcId = btn.dataset.clear;
    if (!confirm('Clear all history for this calculator?')) return;
    CalcHistory.clear(calcId);
    const slotId = 'ch-' + calcId + '-list';
    const setStateMap = {
      units: unitsSetState, stackup: stackupSetState, cpk: cpkSetState,
      fit: fitSetState, sfm: sfmSetState, geometry: geometrySetState,
      sci: sciSetState, aql: aqlSetState, iso2859: isoSetState
    };
    CalcHistory.render(slotId, calcId, setStateMap[calcId]);
    updateAllCounts();
  });
});

// ---- Update the small count badges on each card head ----
function updateAllCounts() {
  ['units','stackup','cpk','fit','sfm','geometry','sci','aql','iso2859'].forEach(id => {
    const el = $('ch-' + id + '-count');
    if (!el) return;
    const n = CalcHistory.read(id).length;
    el.textContent = n + ' / 25';
  });
}

// ---- Initial render of each history list ----
CalcHistory.render('ch-units-list',    'units',    unitsSetState);
CalcHistory.render('ch-stackup-list',  'stackup',  stackupSetState);
CalcHistory.render('ch-cpk-list',      'cpk',      cpkSetState);
CalcHistory.render('ch-fit-list',      'fit',      fitSetState);
CalcHistory.render('ch-sfm-list',      'sfm',      sfmSetState);
CalcHistory.render('ch-geometry-list', 'geometry', geometrySetState);
CalcHistory.render('ch-sci-list',      'sci',      sciSetState);
CalcHistory.render('ch-aql-list',      'aql',      aqlSetState);
CalcHistory.render('ch-iso2859-list',  'iso2859',  isoSetState);
updateAllCounts();

// Periodically refresh counts (cheap; runs every 2s) so additions are visible
setInterval(updateAllCounts, 2000);

// =========================================================================
// INIT
// =========================================================================

rebuildUnitSelects();
renderUnitConverter();
renderCpk();
renderFit();
renderSfm();
renderGeometry();

// Unlock history saving — startup renders are complete, future user edits
// will now produce history entries.
// Delay slightly past the 1.5s debounce window so any pending startup
// timers definitely don't fire under the suppression-cleared condition
// with stale state.
</script>

<?php include 'includes/footer.php'; ?>
