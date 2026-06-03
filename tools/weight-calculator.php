<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Engineering Toolbox · MagDyn';
$current_page  = 'weight-calculator.php';
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
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px;
  }
  @media (max-width: 1300px) { .calc-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 900px) { .calc-grid { grid-template-columns: 1fr; } }
  .panel-num-tag {
    font-size: 10px; color: var(--text-light);
    letter-spacing: 0.1em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-weight: 500;
  }

  .shapes {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;
    margin-bottom: 14px;
  }
  .shape-btn {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    padding: 12px 6px;
    cursor: pointer;
    border-radius: var(--radius);
    font-family: inherit;
    font-size: 11px; font-weight: 500;
    display: flex; flex-direction: column;
    align-items: center; gap: 6px;
    transition: all 0.12s;
  }
  .shape-btn svg {
    width: 28px; height: 28px; fill: none;
    stroke: currentColor; stroke-width: 1.5;
  }
  .shape-btn:hover { border-color: var(--primary); color: var(--primary); }
  .shape-btn.active {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
  }

  .diagram {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px; margin-bottom: 14px;
    text-align: center; min-height: 130px;
    display: flex; align-items: center; justify-content: center;
  }
  .diagram svg { max-width: 100%; height: auto; max-height: 110px; }
  .diagram .dim {
    fill: var(--text-muted);
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  .diagram .dash { stroke-dasharray: 4 3; }
  .diagram line, .diagram path, .diagram rect, .diagram polygon, .diagram circle {
    stroke: var(--text); fill: none; stroke-width: 1.5;
  }

  .unit-toggle {
    display: grid; grid-template-columns: 1fr 1fr; gap: 3px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius); padding: 3px;
  }
  .unit-toggle button {
    background: transparent; border: none;
    color: var(--text-muted);
    padding: 7px 10px;
    font-family: inherit; font-size: 11px; font-weight: 600;
    cursor: pointer; border-radius: 4px;
    letter-spacing: 0.04em; transition: all 0.12s;
  }
  .unit-toggle button:hover { color: var(--text); }
  .unit-toggle button.active { background: var(--primary); color: white; }

  #dimensions { margin-bottom: 12px; }
  #dimensions .field { margin-bottom: 10px; }
  #dimensions .field:last-child { margin-bottom: 0; }

  .readout {
    background: var(--primary); color: white;
    border-radius: var(--radius);
    padding: 22px 20px; text-align: center;
    margin-bottom: 14px;
  }
  .readout-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.12em; opacity: 0.7;
    margin-bottom: 6px; font-weight: 600;
  }
  .readout-value {
    font-size: 38px; font-weight: 700;
    letter-spacing: -0.01em; line-height: 1.1;
    font-variant-numeric: tabular-nums;
  }
  .readout-value.warn { color: var(--warn-bg); }
  .readout-unit {
    font-size: 11px; letter-spacing: 0.12em;
    opacity: 0.7; margin-top: 4px; font-weight: 600;
  }
  .readout-secondary {
    margin-top: 12px; padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.18);
    font-size: 12px; opacity: 0.85;
  }

  .breakdown { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .breakdown .stat {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius); padding: 10px 12px;
  }
  .breakdown .stat-label {
    font-size: 10px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    font-weight: 600; margin-bottom: 4px;
  }
  .breakdown .stat-value {
    font-size: 16px; font-weight: 600;
    color: var(--text); font-variant-numeric: tabular-nums;
  }
  .breakdown .stat-value .u {
    font-size: 11px; color: var(--text-muted);
    margin-left: 4px; font-weight: 500;
  }

  .hardness-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 18px; }
  @media (max-width: 1200px) { .hardness-grid { grid-template-columns: 1fr; } }

  table.hardness {
    width: 100%; border-collapse: collapse; font-size: 12.5px;
  }
  table.hardness thead th {
    text-align: center; padding: 8px 6px;
    background: var(--surface-alt);
    border-bottom: 1px solid var(--border);
    font-size: 11px; color: var(--text); vertical-align: top;
  }
  table.hardness thead th .scale {
    display: block; font-weight: 700; color: var(--primary);
    font-size: 12px; letter-spacing: 0.04em;
  }
  table.hardness thead th small {
    display: block; font-size: 9.5px;
    color: var(--text-muted); font-weight: 500;
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-top: 2px;
  }
  table.hardness td {
    padding: 6px 8px;
    border-bottom: 1px solid var(--border);
    text-align: center; font-variant-numeric: tabular-nums;
    color: var(--text);
  }
  table.hardness tbody tr.dim td { color: var(--text-light); }
  table.hardness tbody tr.match td {
    background: var(--primary-light);
    font-weight: 700; color: var(--primary);
  }
  table.hardness tbody tr.band-very-hard td:first-child {
    border-left: 3px solid var(--danger);
  }
  table.hardness tbody tr.band-hard td:first-child {
    border-left: 3px solid var(--warn);
  }
  table.hardness tbody tr.band-medium td:first-child {
    border-left: 3px solid var(--info);
  }
  .hardness-scroll { max-height: calc(100vh - 240px); overflow-y: auto; }

  .converter .conv-head h2 { font-size: 14px; margin-bottom: 4px; }
  .converter .conv-head p {
    color: var(--text-muted); font-size: 12.5px; margin-bottom: 14px;
  }
  .conv-input {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    margin-bottom: 14px;
  }
  .conv-input > div { display: flex; flex-direction: column; }
  .conv-input label {
    font-size: 10px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    font-weight: 600; margin-bottom: 4px;
  }
  .conv-input select, .conv-input input {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text); padding: 7px 9px;
    font-family: inherit; font-size: 13px;
    border-radius: var(--radius); outline: none;
  }
  .conv-input select:focus, .conv-input input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
  }
  .conv-results {
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
    margin-bottom: 12px;
  }
  .conv-cell {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius); padding: 8px 10px;
  }
  .conv-cell .lbl {
    font-size: 9px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.08em;
    font-weight: 600; margin-bottom: 2px;
  }
  .conv-cell .val {
    font-size: 15px; color: var(--text); font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
  .conv-cell .val .u {
    font-size: 10px; color: var(--text-muted);
    margin-left: 4px; font-weight: 500;
  }
  .conv-uts {
    background: var(--primary); color: white;
    border-radius: var(--radius); padding: 14px;
    text-align: center; margin-bottom: 12px;
  }
  .conv-uts .lbl {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.1em; opacity: 0.7;
    font-weight: 600; margin-bottom: 4px;
  }
  .conv-uts .val {
    font-size: 22px; font-weight: 700;
    font-variant-numeric: tabular-nums; line-height: 1.1;
  }
  .conv-uts .u { font-size: 11px; opacity: 0.85; margin-top: 4px; }
  .conv-note {
    font-size: 11.5px; color: var(--text-muted);
    background: var(--warn-bg);
    border-left: 3px solid var(--warn);
    padding: 10px 12px; border-radius: var(--radius); line-height: 1.55;
  }
  .conv-note .key {
    font-weight: 700; color: var(--warn);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }

  .status-strip {
    display: flex; justify-content: space-between; gap: 18px;
    margin-top: 18px; padding: 10px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap;
  }
  .status-strip .grp { display: flex; gap: 16px; flex-wrap: wrap; }
  .status-strip .key {
    color: var(--primary); font-weight: 700;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
  .status-strip .val { color: var(--text); font-weight: 600; font-size: 11px; }





  /* MagDyn embed mode (iframed inside /tools.php?tool=weight). Hide the
     tool's own inner sidebar since the MagDyn sidebar provides nav. */
  html.embed-mode .sidebar { display: none; }
  html.embed-mode .layout  { grid-template-columns: 1fr; }
  html.embed-mode .main    { padding-left: 24px; }
</style>

<script>
  // Set the embed flag on <html> early so the inner sidebar never paints.
  (function () {
    try {
      var p = new URLSearchParams(window.location.search);
      if (p.get('embed') === '1') {
        document.documentElement.classList.add('embed-mode');
      }
    } catch (e) {}
  })();
</script>
</head>
<body>


<div class="layout">

<aside class="sidebar">
      
    <?php include 'includes/apps-menu.php'; ?>
    <div class="brand">
    <div class="brand-mark">
      <div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:14px;letter-spacing:-0.02em;">MD</div>
    </div>
    <div class="brand-text">
      <div class="brand-title">Engineering Toolbox</div>
      <div class="brand-sub">MagDyn</div>
    </div>
  </div>

  <div class="nav">
    <div class="nav-section"><span>Tools</span></div>

    <a class="nav-item tab-btn active" data-tab="calculator">
      <span class="ico">⚖</span>
      <span class="nav-label"><span class="num">01</span>Weight Calculator</span>
    </a>
    <a class="nav-item tab-btn" data-tab="hardness">
      <span class="ico">🔩</span>
      <span class="nav-label"><span class="num">02</span>Hardness · Steels</span>
    </a>
    <a class="nav-item tab-btn" data-tab="shore">
      <span class="ico">⊕</span>
      <span class="nav-label"><span class="num">03</span>Shore · Rubber/Plastic</span>
    </a>

    <div class="nav-section"><span>Reference</span></div>
    <div style="padding: 0 12px; font-size: 11px; color: var(--sidebar-text-very-dim); line-height: 1.6;">
      Densities follow handbook conventions. Hardness conversions per ASTM&nbsp;E140. Shore per ASTM&nbsp;D2240. Tolerance ±2%.
    </div>
  </div>

  <div class="sidebar-footer">
    <div style="font-size: 11px; color: var(--sidebar-text-dim); padding: 4px 8px; line-height: 1.6;">
      <div id="tab-context" style="text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">PROFILE × MATERIAL → MASS</div>
    </div>
  </div>
</aside>

<div class="main">

  <!-- ============ CALCULATOR VIEW ============ -->
  <div class="view active" id="view-calculator">
    <div class="page-head">
      <div>
        <h1>Weight Calculator</h1>
        <p class="muted">Profile × material → mass. Volume, density, surface area, and per-piece weight in metric or imperial.</p>
      </div>
    </div>

    <div class="calc-grid">
      <div class="card">
        <div class="card-head">
          <h2>Profile &amp; material</h2>
          <span class="panel-num-tag">// 01</span>
        </div>
        <div class="card-body">
          <div class="field" style="margin-bottom: 14px;">
            <label>Profile</label>
            <div class="shapes" id="shapes">
              <button class="shape-btn active" data-shape="rect">
                <svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12"/></svg>
                Rect Bar
              </button>
              <button class="shape-btn" data-shape="round">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>
                Round Bar
              </button>
              <button class="shape-btn" data-shape="square">
                <svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14"/></svg>
                Sq. Bar
              </button>
              <button class="shape-btn" data-shape="hex">
                <svg viewBox="0 0 24 24"><polygon points="12,3 21,8 21,16 12,21 3,16 3,8"/></svg>
                Hex Bar
              </button>
              <button class="shape-btn" data-shape="tube">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/></svg>
                Round Tube
              </button>
              <button class="shape-btn" data-shape="sheet">
                <svg viewBox="0 0 24 24"><rect x="2" y="9" width="20" height="6"/></svg>
                Sheet
              </button>
            </div>
          </div>

          <div class="diagram" id="diagram"></div>

          <div class="field">
            <label>Material</label>
            <select id="material"></select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Dimensions</h2>
          <span class="panel-num-tag">// 02</span>
        </div>
        <div class="card-body">
          <div class="field" style="margin-bottom: 14px;">
            <label>Units</label>
            <div class="unit-toggle" id="unit-toggle">
              <button class="active" data-unit="metric">METRIC · mm</button>
              <button data-unit="imperial">IMPERIAL · in</button>
            </div>
          </div>

          <div id="dimensions"></div>

          <div class="field">
            <label>Quantity (pieces)</label>
            <input type="number" id="qty" value="1" min="1" step="1" />
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Calculated mass</h2>
          <span class="panel-num-tag">// 03</span>
        </div>
        <div class="card-body">
          <div class="readout">
            <div class="readout-label">Total weight</div>
            <div class="readout-value" id="weight-total">0.00</div>
            <div class="readout-unit" id="weight-unit">KILOGRAMS</div>
            <div class="readout-secondary">
              per piece: <span id="weight-each">0.00</span>
              <span id="weight-each-u">kg</span>
            </div>
          </div>

          <div class="breakdown">
            <div class="stat">
              <div class="stat-label">Volume</div>
              <div class="stat-value"><span id="stat-volume">0</span><span class="u" id="stat-volume-u">cm³</span></div>
            </div>
            <div class="stat">
              <div class="stat-label">Density</div>
              <div class="stat-value"><span id="stat-density">0</span><span class="u" id="stat-density-u">g/cm³</span></div>
            </div>
            <div class="stat">
              <div class="stat-label">Surface area</div>
              <div class="stat-value"><span id="stat-surface">0</span><span class="u" id="stat-surface-u">cm²</span></div>
            </div>
            <div class="stat">
              <div class="stat-label">Linear mass</div>
              <div class="stat-value"><span id="stat-linear">0</span><span class="u" id="stat-linear-u">kg/m</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="status-strip">
      <div class="grp">
        <span><span class="key">ρ</span> <span class="val">density × volume → mass</span></span>
        <span>tubes <span class="val">subtract bore</span></span>
        <span>tolerance <span class="val">±2%</span></span>
      </div>
      <div class="grp">
        <span id="status-shape" class="val">RECT BAR</span>
        <span id="status-material" class="val">STEEL A36</span>
      </div>
    </div>
  </div>

  <!-- ============ HARDNESS VIEW ============ -->
  <div class="view" id="view-hardness">
    <div class="page-head">
      <div>
        <h1>Hardness conversion · non-austenitic steels</h1>
        <p class="muted">Per ASTM E140 · approximate. Apply to non-austenitic steels only. ±5% scatter is normal.</p>
      </div>
    </div>

    <div class="hardness-grid">
      <div class="card">
        <div class="card-body" style="padding: 0;">
          <div class="hardness-scroll">
            <table class="hardness" id="hardness-table">
              <thead>
                <tr>
                  <th><span class="scale">HRC</span><small>Rockwell C</small></th>
                  <th><span class="scale">HRB</span><small>Rockwell B</small></th>
                  <th><span class="scale">HV</span><small>Vickers</small></th>
                  <th><span class="scale">HBW</span><small>Brinell</small></th>
                  <th><span class="scale">HRA</span><small>Rockwell A</small></th>
                  <th><span class="scale">HS</span><small>Scleroscope</small></th>
                  <th><span class="scale">UTS</span><small>MPa · est.</small></th>
                  <th><span class="scale">UTS</span><small>ksi · est.</small></th>
                </tr>
              </thead>
              <tbody id="hardness-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card converter">
        <div class="card-head">
          <h2>Quick convert</h2>
        </div>
        <div class="card-body">
          <div class="conv-head">
            <p>Enter a value on any scale — the equivalents update from the table by linear interpolation.</p>
          </div>

          <div class="conv-input">
            <div>
              <label>Scale</label>
              <select id="conv-scale">
                <option value="HRC">HRC · Rockwell C</option>
                <option value="HRB">HRB · Rockwell B</option>
                <option value="HV">HV · Vickers</option>
                <option value="HBW">HBW · Brinell</option>
              </select>
            </div>
            <div>
              <label>Value</label>
              <input type="number" id="conv-value" value="40" step="any" />
            </div>
          </div>

          <div class="conv-results">
            <div class="conv-cell">
              <div class="lbl">Rockwell C</div>
              <div class="val" id="r-hrc">—<span class="u">HRC</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Rockwell B</div>
              <div class="val" id="r-hrb">—<span class="u">HRB</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Vickers</div>
              <div class="val" id="r-hv">—<span class="u">HV</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Brinell</div>
              <div class="val" id="r-hbw">—<span class="u">HBW</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Rockwell A</div>
              <div class="val" id="r-hra">—<span class="u">HRA</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Scleroscope</div>
              <div class="val" id="r-hsh">—<span class="u">HS</span></div>
            </div>
          </div>

          <div class="conv-uts">
            <div class="lbl">Estimated tensile strength</div>
            <div class="val" id="r-uts">—</div>
            <div class="u"><span id="r-uts-mpa">— MPa</span> · <span id="r-uts-ksi">— ksi</span></div>
          </div>

          <div class="conv-note">
            <span class="key">⚠</span> Conversions are empirical (<span class="key">ASTM E140</span>) and apply to non-austenitic steels. Expect ±5% scatter — never substitute for direct measurement on the specified scale.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============ SHORE VIEW ============ -->
  <div class="view" id="view-shore">
    <div class="page-head">
      <div>
        <h1>Shore durometer · rubbers &amp; plastics</h1>
        <p class="muted">Per ASTM D2240. Shore A and D overlap in the 90A–50D region. Cross-scale conversion is unreliable outside that band.</p>
      </div>
    </div>

    <div class="hardness-grid">
      <div class="card">
        <div class="card-body" style="padding: 0;">
          <div class="hardness-scroll">
            <table class="hardness" id="shore-table">
              <thead>
                <tr>
                  <th><span class="scale">Shore A</span><small>Soft elastomers</small></th>
                  <th><span class="scale">Shore D</span><small>Hard plastics</small></th>
                  <th><span class="scale">Shore OO</span><small>Foams &amp; gels</small></th>
                  <th><span class="scale">IRHD</span><small>ISO equiv.</small></th>
                  <th><span class="scale">FEEL</span><small>analog</small></th>
                  <th><span class="scale">EXAMPLE</span><small>typical material</small></th>
                </tr>
              </thead>
              <tbody id="shore-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card converter">
        <div class="card-head">
          <h2>Quick convert</h2>
        </div>
        <div class="card-body">
          <div class="conv-head">
            <p>Shore A and D scales overlap in the 90A–50D region. Conversion outside that band is unreliable.</p>
          </div>

          <div class="conv-input">
            <div>
              <label>Scale</label>
              <select id="shore-scale">
                <option value="A">Shore A · soft</option>
                <option value="D">Shore D · hard</option>
                <option value="OO">Shore OO · foams</option>
              </select>
            </div>
            <div>
              <label>Value</label>
              <input type="number" id="shore-value" value="70" min="0" max="100" step="any" />
            </div>
          </div>

          <div class="conv-results">
            <div class="conv-cell">
              <div class="lbl">Shore A</div>
              <div class="val" id="s-a">—<span class="u">A</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Shore D</div>
              <div class="val" id="s-d">—<span class="u">D</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">Shore OO</div>
              <div class="val" id="s-oo">—<span class="u">OO</span></div>
            </div>
            <div class="conv-cell">
              <div class="lbl">IRHD</div>
              <div class="val" id="s-irhd">—<span class="u">IRHD</span></div>
            </div>
          </div>

          <div class="conv-uts">
            <div class="lbl">Material category</div>
            <div class="val" id="s-feel" style="font-size: 22px;">—</div>
            <div class="u" id="s-example">analog material —</div>
          </div>

          <div class="conv-note">
            <span class="key">⚠</span> Shore scales (<span class="key">ASTM D2240</span>) measure flexible materials. Shore A and D use different indenters &amp; springs — overlap is approximate. Shore-to-Rockwell conversions across material classes are <em>not meaningful</em>.
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

</div>

<script>
  const MATERIALS = {
    'Metals — Ferrous': {
      'Steel, Mild (A36)': 7.85,
      'Steel, Stainless 304': 8.00,
      'Steel, Stainless 316': 8.00,
      'Steel, Tool (A2)': 7.86,
      'Cast Iron, Gray': 7.20,
      'Wrought Iron': 7.75,
    },
    'Metals — Aluminum': {
      'Aluminum 1100': 2.71,
      'Aluminum 6061': 2.70,
      'Aluminum 7075': 2.81,
      'Aluminum 5052': 2.68,
    },
    'Metals — Copper Group': {
      'Copper, Pure': 8.96,
      'Brass (C260)': 8.53,
      'Bronze (C932)': 8.83,
    },
    'Metals — Light/Specialty': {
      'Titanium (Grade 2)': 4.51,
      'Titanium (Ti-6Al-4V)': 4.43,
      'Magnesium (AZ31)': 1.77,
      'Nickel': 8.90,
      'Zinc': 7.13,
      'Lead': 11.34,
      'Tin': 7.31,
      'Silver': 10.49,
      'Gold': 19.32,
      'Platinum': 21.45,
      'Tungsten': 19.25,
    },
    'Plastics — Commodity': {
      'ABS': 1.05,
      'PVC (Rigid)': 1.40,
      'Polyethylene (HDPE)': 0.95,
      'Polyethylene (LDPE)': 0.92,
      'Polypropylene (PP)': 0.91,
      'Polystyrene (PS)': 1.05,
    },
    'Plastics — Engineering': {
      'Acrylic (PMMA)': 1.18,
      'Polycarbonate (PC)': 1.20,
      'Nylon 6': 1.13,
      'Nylon 66': 1.14,
      'PTFE (Teflon)': 2.20,
      'POM / Delrin (Acetal)': 1.41,
      'PEEK': 1.32,
    },
  };

  const state = {
    shape: 'rect',
    material: 'Steel, Mild (A36)',
    density: 7.85,
    unit: 'metric',
    dims: {},
  };

  const SHAPES = {
    rect: {
      label: 'RECT BAR',
      fields: [
        { id: 'w', label: 'Width', def_mm: 50, def_in: 2 },
        { id: 'h', label: 'Height', def_mm: 25, def_in: 1 },
        { id: 'l', label: 'Length', def_mm: 1000, def_in: 36 },
      ],
      diagram: `<svg viewBox="0 0 80 60">
        <rect x="14" y="22" width="52" height="22"/>
        <line class="dim" x1="14" y1="50" x2="66" y2="50"/>
        <text class="dim-text" x="36" y="57">L</text>
        <line class="dim" x1="70" y1="22" x2="70" y2="44"/>
        <text class="dim-text" x="72" y="35">H</text>
        <line class="dim" x1="14" y1="18" x2="66" y2="18"/>
        <text class="dim-text" x="36" y="15">W</text>
      </svg>`,
      volume: d => d.w * d.h * d.l,
      surface: d => 2 * (d.w*d.h + d.w*d.l + d.h*d.l),
    },
    round: {
      label: 'ROUND BAR',
      fields: [
        { id: 'd', label: 'Diameter', def_mm: 25, def_in: 1 },
        { id: 'l', label: 'Length', def_mm: 1000, def_in: 36 },
      ],
      diagram: `<svg viewBox="0 0 80 60">
        <ellipse cx="20" cy="30" rx="6" ry="14"/>
        <line x1="20" y1="16" x2="68" y2="16"/>
        <line x1="20" y1="44" x2="68" y2="44"/>
        <ellipse cx="68" cy="30" rx="6" ry="14"/>
        <line class="dim" x1="20" y1="52" x2="68" y2="52"/>
        <text class="dim-text" x="40" y="59">L</text>
        <line class="dim" x1="14" y1="16" x2="14" y2="44"/>
        <text class="dim-text" x="2" y="33">Ø</text>
      </svg>`,
      volume: d => Math.PI * (d.d/2)**2 * d.l,
      surface: d => Math.PI * d.d * d.l + 2 * Math.PI * (d.d/2)**2,
    },
    square: {
      label: 'SQ. BAR',
      fields: [
        { id: 's', label: 'Side', def_mm: 25, def_in: 1 },
        { id: 'l', label: 'Length', def_mm: 1000, def_in: 36 },
      ],
      diagram: `<svg viewBox="0 0 80 60">
        <rect x="14" y="20" width="52" height="26"/>
        <line class="dim" x1="14" y1="52" x2="66" y2="52"/>
        <text class="dim-text" x="36" y="59">L</text>
        <line class="dim" x1="70" y1="20" x2="70" y2="46"/>
        <text class="dim-text" x="72" y="35">S</text>
      </svg>`,
      volume: d => d.s * d.s * d.l,
      surface: d => 4 * d.s * d.l + 2 * d.s * d.s,
    },
    hex: {
      label: 'HEX BAR',
      fields: [
        { id: 'a', label: 'Across Flats', def_mm: 25, def_in: 1 },
        { id: 'l', label: 'Length', def_mm: 1000, def_in: 36 },
      ],
      diagram: `<svg viewBox="0 0 80 60">
        <polygon points="20,18 28,14 28,46 20,42"/>
        <polygon points="28,14 60,14 68,18 60,22 28,22"/>
        <polygon points="28,14 60,14 68,18 68,42 60,46 28,46 20,42 20,18"
                 style="fill:rgba(200,255,62,0.04)"/>
        <line class="dim" x1="20" y1="52" x2="68" y2="52"/>
        <text class="dim-text" x="40" y="59">L</text>
        <text class="dim-text" x="40" y="9">A/F</text>
      </svg>`,
      volume: d => (Math.sqrt(3)/2) * d.a * d.a * d.l,
      surface: d => 6 * (d.a / Math.sqrt(3)) * d.l + 2 * (Math.sqrt(3)/2) * d.a * d.a,
    },
    tube: {
      label: 'ROUND TUBE',
      fields: [
        { id: 'od', label: 'Outer Ø', def_mm: 25, def_in: 1 },
        { id: 'wall', label: 'Wall Thk.', def_mm: 2, def_in: 0.083 },
        { id: 'l', label: 'Length', def_mm: 1000, def_in: 36 },
      ],
      diagram: `<svg viewBox="0 0 80 60">
        <ellipse cx="20" cy="30" rx="6" ry="14"/>
        <ellipse cx="20" cy="30" rx="3" ry="8"/>
        <line x1="20" y1="16" x2="68" y2="16"/>
        <line x1="20" y1="44" x2="68" y2="44"/>
        <ellipse cx="68" cy="30" rx="6" ry="14"/>
        <ellipse cx="68" cy="30" rx="3" ry="8" style="fill:var(--bg-elev)"/>
        <line class="dim" x1="20" y1="52" x2="68" y2="52"/>
        <text class="dim-text" x="40" y="59">L</text>
      </svg>`,
      volume: d => {
        const id_ = Math.max(0, d.od - 2 * d.wall);
        return Math.PI * ((d.od/2)**2 - (id_/2)**2) * d.l;
      },
      surface: d => {
        const id_ = Math.max(0, d.od - 2 * d.wall);
        return Math.PI * d.od * d.l + Math.PI * id_ * d.l +
               2 * Math.PI * ((d.od/2)**2 - (id_/2)**2);
      },
    },
    sheet: {
      label: 'SHEET / PLATE',
      fields: [
        { id: 'l', label: 'Length', def_mm: 1000, def_in: 36 },
        { id: 'w', label: 'Width', def_mm: 500, def_in: 18 },
        { id: 't', label: 'Thickness', def_mm: 3, def_in: 0.125 },
      ],
      diagram: `<svg viewBox="0 0 80 60">
        <polygon points="14,20 56,12 70,22 28,30"/>
        <polygon points="14,20 14,32 28,42 28,30"/>
        <polygon points="28,30 70,22 70,34 28,42"/>
        <text class="dim-text" x="40" y="9">L × W × T</text>
      </svg>`,
      volume: d => d.l * d.w * d.t,
      surface: d => 2 * (d.l*d.w + d.l*d.t + d.w*d.t),
    },
  };

  const $ = sel => document.querySelector(sel);
  const $$ = sel => document.querySelectorAll(sel);

  function buildMaterials() {
    const sel = $('#material');
    sel.innerHTML = '';
    Object.entries(MATERIALS).forEach(([group, items]) => {
      const og = document.createElement('optgroup');
      og.label = group;
      Object.entries(items).forEach(([name, density]) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = `${name}  —  ρ ${density.toFixed(2)}`;
        opt.dataset.density = density;
        og.appendChild(opt);
      });
      sel.appendChild(og);
    });
    sel.value = state.material;
  }

  function buildDimensions() {
    const shape = SHAPES[state.shape];
    const container = $('#dimensions');
    container.innerHTML = '';

    const unitLabel = state.unit === 'metric' ? 'mm' : 'in';
    const cols = shape.fields.length === 3 ? 'row-3' : (shape.fields.length === 2 ? 'row-2' : '');

    if (cols) {
      const wrap = document.createElement('div');
      wrap.className = 'field';
      const grid = document.createElement('div');
      grid.className = cols;

      shape.fields.forEach(f => {
        const def = state.unit === 'metric' ? f.def_mm : f.def_in;
        if (state.dims[f.id] === undefined) state.dims[f.id] = def;
        const cell = document.createElement('div');
        cell.innerHTML = `
          <label>${f.label} <span class="u">(${unitLabel})</span></label>
          <input type="number" data-dim="${f.id}" value="${state.dims[f.id]}" min="0" step="any" />
        `;
        grid.appendChild(cell);
      });
      wrap.appendChild(grid);
      container.appendChild(wrap);
    } else {
      shape.fields.forEach(f => {
        const def = state.unit === 'metric' ? f.def_mm : f.def_in;
        if (state.dims[f.id] === undefined) state.dims[f.id] = def;
        const div = document.createElement('div');
        div.className = 'field';
        div.innerHTML = `
          <label>${f.label} <span class="u">(${unitLabel})</span></label>
          <input type="number" data-dim="${f.id}" value="${state.dims[f.id]}" min="0" step="any" />
        `;
        container.appendChild(div);
      });
    }

    $$('#dimensions input').forEach(inp => {
      inp.addEventListener('input', e => {
        state.dims[e.target.dataset.dim] = parseFloat(e.target.value) || 0;
        compute();
      });
    });

    $('#diagram').innerHTML = shape.diagram;
    $('#status-shape').textContent = shape.label;
  }

  function compute() {
    const shape = SHAPES[state.shape];

    let dimsMM = {};
    Object.keys(state.dims).forEach(k => {
      dimsMM[k] = state.unit === 'metric' ? state.dims[k] : state.dims[k] * 25.4;
    });

    const volMM3 = shape.volume(dimsMM);
    const surfMM2 = shape.surface(dimsMM);
    const volCM3 = volMM3 / 1000;
    const surfCM2 = surfMM2 / 100;

    const massG = volCM3 * state.density;
    const massKG = massG / 1000;
    const massLB = massKG * 2.20462;

    const qty = Math.max(1, parseInt($('#qty').value) || 1);
    const totalKG = massKG * qty;
    const totalLB = massLB * qty;

    const lengthMM = dimsMM.l || 0;
    const lengthM = lengthMM / 1000;
    const linearKGperM = lengthM > 0 ? massKG / lengthM : 0;
    const linearLBperFT = linearKGperM * 0.671969;

    const isMetric = state.unit === 'metric';
    const w = $('#weight-total');

    if (isMetric) {
      w.textContent = formatNum(totalKG);
      $('#weight-unit').textContent = qty > 1 ? `KILOGRAMS · ${qty} pcs` : 'KILOGRAMS';
      $('#weight-each').textContent = formatNum(massKG);
      $('#weight-each-u').textContent = 'kg';
      $('#stat-volume').textContent = formatNum(volCM3);
      $('#stat-volume-u').textContent = 'cm³';
      $('#stat-density').textContent = state.density.toFixed(2);
      $('#stat-density-u').textContent = 'g/cm³';
      $('#stat-surface').textContent = formatNum(surfCM2);
      $('#stat-surface-u').textContent = 'cm²';
      $('#stat-linear').textContent = formatNum(linearKGperM);
      $('#stat-linear-u').textContent = 'kg/m';
    } else {
      w.textContent = formatNum(totalLB);
      $('#weight-unit').textContent = qty > 1 ? `POUNDS · ${qty} pcs` : 'POUNDS';
      $('#weight-each').textContent = formatNum(massLB);
      $('#weight-each-u').textContent = 'lb';
      $('#stat-volume').textContent = formatNum(volCM3 / 16.387);
      $('#stat-volume-u').textContent = 'in³';
      $('#stat-density').textContent = (state.density * 0.03613).toFixed(4);
      $('#stat-density-u').textContent = 'lb/in³';
      $('#stat-surface').textContent = formatNum(surfCM2 / 6.4516);
      $('#stat-surface-u').textContent = 'in²';
      $('#stat-linear').textContent = formatNum(linearLBperFT);
      $('#stat-linear-u').textContent = 'lb/ft';
    }

    w.classList.toggle('warn', !isFinite(totalKG) || totalKG <= 0);
    $('#status-material').textContent = state.material.toUpperCase();
  }

  function formatNum(n) {
    if (!isFinite(n) || isNaN(n)) return '—';
    if (n === 0) return '0.00';
    if (Math.abs(n) >= 10000) return n.toFixed(0);
    if (Math.abs(n) >= 100) return n.toFixed(1);
    if (Math.abs(n) >= 1) return n.toFixed(2);
    if (Math.abs(n) >= 0.01) return n.toFixed(3);
    return n.toExponential(2);
  }

  function bindControls() {
    $$('.shape-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        $$('.shape-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.shape = btn.dataset.shape;
        state.dims = {};
        buildDimensions();
        compute();
      });
    });

    $('#material').addEventListener('change', e => {
      state.material = e.target.value;
      state.density = parseFloat(e.target.selectedOptions[0].dataset.density);
      compute();
    });

    $('#qty').addEventListener('input', compute);

    $$('#unit-toggle button').forEach(btn => {
      btn.addEventListener('click', () => {
        if (btn.classList.contains('active')) return;
        $$('#unit-toggle button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const fromUnit = state.unit;
        const toUnit = btn.dataset.unit;
        Object.keys(state.dims).forEach(k => {
          if (fromUnit === 'metric' && toUnit === 'imperial') {
            state.dims[k] = state.dims[k] / 25.4;
          } else if (fromUnit === 'imperial' && toUnit === 'metric') {
            state.dims[k] = state.dims[k] * 25.4;
          }
        });
        state.unit = toUnit;
        buildDimensions();
        compute();
      });
    });
  }

  buildMaterials();
  state.density = MATERIALS['Metals — Ferrous']['Steel, Mild (A36)'];
  buildDimensions();
  bindControls();
  compute();

  // ============================================================
  // HARDNESS CONVERSION DATA — ASTM E140 non-austenitic steels
  // Columns: HRC, HRB, HV, HBW, HRA, HSh (Shore), UTS_MPa
  // null = outside valid scale range
  // ============================================================
  const HARDNESS = [
    // HRC,  HRB,   HV,   HBW,  HRA,  HSh,  UTS(MPa)
    { HRC: 68, HRB: null, HV: 940, HBW: null, HRA: 85.6, HSh: 97, UTS: null },
    { HRC: 66, HRB: null, HV: 865, HBW: null, HRA: 84.5, HSh: 92, UTS: null },
    { HRC: 64, HRB: null, HV: 800, HBW: null, HRA: 83.4, HSh: 88, UTS: null },
    { HRC: 62, HRB: null, HV: 746, HBW: null, HRA: 82.3, HSh: 84, UTS: null },
    { HRC: 60, HRB: null, HV: 697, HBW: 654, HRA: 81.2, HSh: 81, UTS: 2393 },
    { HRC: 58, HRB: null, HV: 653, HBW: 615, HRA: 80.1, HSh: 78, UTS: 2241 },
    { HRC: 56, HRB: null, HV: 613, HBW: 577, HRA: 79.0, HSh: 75, UTS: 2096 },
    { HRC: 54, HRB: null, HV: 577, HBW: 543, HRA: 78.0, HSh: 72, UTS: 1986 },
    { HRC: 52, HRB: null, HV: 544, HBW: 512, HRA: 76.8, HSh: 69, UTS: 1855 },
    { HRC: 50, HRB: null, HV: 513, HBW: 481, HRA: 75.9, HSh: 67, UTS: 1731 },
    { HRC: 48, HRB: null, HV: 484, HBW: 455, HRA: 74.7, HSh: 64, UTS: 1606 },
    { HRC: 46, HRB: null, HV: 458, HBW: 432, HRA: 73.6, HSh: 62, UTS: 1503 },
    { HRC: 44, HRB: null, HV: 434, HBW: 409, HRA: 72.5, HSh: 59, UTS: 1407 },
    { HRC: 42, HRB: null, HV: 412, HBW: 390, HRA: 71.4, HSh: 57, UTS: 1324 },
    { HRC: 40, HRB: null, HV: 392, HBW: 371, HRA: 70.3, HSh: 55, UTS: 1248 },
    { HRC: 38, HRB: 110, HV: 372, HBW: 353, HRA: 69.2, HSh: 52, UTS: 1193 },
    { HRC: 36, HRB: 109, HV: 354, HBW: 336, HRA: 68.1, HSh: 50, UTS: 1131 },
    { HRC: 34, HRB: 108, HV: 336, HBW: 319, HRA: 67.0, HSh: 47, UTS: 1069 },
    { HRC: 32, HRB: 107, HV: 318, HBW: 301, HRA: 65.9, HSh: 45, UTS: 1007 },
    { HRC: 30, HRB: 105, HV: 302, HBW: 286, HRA: 64.8, HSh: 43, UTS: 951 },
    { HRC: 28, HRB: 104, HV: 286, HBW: 271, HRA: 63.7, HSh: 41, UTS: 896 },
    { HRC: 26, HRB: 102, HV: 272, HBW: 258, HRA: 62.5, HSh: 39, UTS: 855 },
    { HRC: 24, HRB: 101, HV: 260, HBW: 247, HRA: 61.5, HSh: 37, UTS: 814 },
    { HRC: 22, HRB: 99,  HV: 248, HBW: 237, HRA: 60.4, HSh: 35, UTS: 779 },
    { HRC: 20, HRB: 97,  HV: 236, HBW: 226, HRA: 59.2, HSh: 34, UTS: 745 },
    { HRC: 18, HRB: 96,  HV: 226, HBW: 219, HRA: null, HSh: 33, UTS: 717 },
    { HRC: 16, HRB: 94,  HV: 216, HBW: 212, HRA: null, HSh: 31, UTS: 689 },
    { HRC: 14, HRB: 92,  HV: 207, HBW: 203, HRA: null, HSh: 30, UTS: 662 },
    { HRC: 12, HRB: 91,  HV: 199, HBW: 194, HRA: null, HSh: 29, UTS: 634 },
    { HRC: 10, HRB: 89,  HV: 191, HBW: 187, HRA: null, HSh: 28, UTS: 614 },
    { HRC: null, HRB: 87, HV: 183, HBW: 179, HRA: null, HSh: 26, UTS: 593 },
    { HRC: null, HRB: 85, HV: 176, HBW: 171, HRA: null, HSh: 25, UTS: 565 },
    { HRC: null, HRB: 83, HV: 170, HBW: 165, HRA: null, HSh: 24, UTS: 545 },
    { HRC: null, HRB: 81, HV: 165, HBW: 159, HRA: null, HSh: 23, UTS: 524 },
    { HRC: null, HRB: 79, HV: 160, HBW: 154, HRA: null, HSh: 22, UTS: 510 },
    { HRC: null, HRB: 76, HV: 152, HBW: 146, HRA: null, HSh: 20, UTS: 490 },
    { HRC: null, HRB: 73, HV: 144, HBW: 139, HRA: null, HSh: 19, UTS: 462 },
    { HRC: null, HRB: 70, HV: 137, HBW: 132, HRA: null, HSh: 18, UTS: 441 },
    { HRC: null, HRB: 67, HV: 130, HBW: 125, HRA: null, HSh: 17, UTS: 414 },
    { HRC: null, HRB: 64, HV: 124, HBW: 119, HRA: null, HSh: 16, UTS: 393 },
    { HRC: null, HRB: 60, HV: 117, HBW: 113, HRA: null, HSh: 15, UTS: 372 },
    { HRC: null, HRB: 56, HV: 111, HBW: 107, HRA: null, HSh: 14, UTS: 352 },
    { HRC: null, HRB: 52, HV: 105, HBW: 101, HRA: null, HSh: 13, UTS: 331 },
    { HRC: null, HRB: 48, HV: 100, HBW: 96,  HRA: null, HSh: 12, UTS: 317 },
    { HRC: null, HRB: 41, HV: 92,  HBW: 89,  HRA: null, HSh: 11, UTS: 290 },
  ];

  // ============================================================
  // Render the hardness table
  // ============================================================
  function buildHardnessTable() {
    const tbody = document.getElementById('hardness-tbody');
    tbody.innerHTML = '';

    HARDNESS.forEach(row => {
      const tr = document.createElement('tr');

      // assign band class for the leading dot color
      const hrc = row.HRC;
      let band = 'band-soft';
      if (hrc !== null) {
        if (hrc >= 55) band = 'band-very-hard';
        else if (hrc >= 40) band = 'band-hard';
        else if (hrc >= 20) band = 'band-medium';
      }
      tr.classList.add(band);

      const cells = [
        row.HRC,
        row.HRB,
        row.HV,
        row.HBW,
        row.HRA !== null ? row.HRA.toFixed(1) : null,
        row.HSh,
        row.UTS,
        row.UTS !== null ? Math.round(row.UTS * 0.145038) : null,
      ];

      cells.forEach(v => {
        const td = document.createElement('td');
        if (v === null || v === undefined) {
          td.textContent = '—';
          td.classList.add('dash');
        } else {
          td.textContent = v;
        }
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  }

  // ============================================================
  // Hardness converter — interpolate based on input scale
  // ============================================================
  function interp(value, fromKey, toKey) {
    // collect rows where both keys are non-null
    const rows = HARDNESS.filter(r => r[fromKey] !== null && r[toKey] !== null);
    if (rows.length < 2) return null;

    // sort ascending by fromKey
    const sorted = [...rows].sort((a, b) => a[fromKey] - b[fromKey]);

    const minF = sorted[0][fromKey];
    const maxF = sorted[sorted.length - 1][fromKey];

    if (value < minF || value > maxF) return null; // out of range

    // find bracketing rows
    for (let i = 0; i < sorted.length - 1; i++) {
      const a = sorted[i], b = sorted[i + 1];
      if (value >= a[fromKey] && value <= b[fromKey]) {
        const span = b[fromKey] - a[fromKey];
        if (span === 0) return a[toKey];
        const t = (value - a[fromKey]) / span;
        return a[toKey] + t * (b[toKey] - a[toKey]);
      }
    }
    return null;
  }

  function fmtConv(v, decimals = 0) {
    if (v === null || v === undefined || !isFinite(v)) return null;
    return decimals === 0 ? Math.round(v) : v.toFixed(decimals);
  }

  function setConv(id, val, unit) {
    const el = document.getElementById(id);
    if (val === null) {
      el.innerHTML = `—<span class="u">${unit}</span>`;
      el.classList.add('dim');
    } else {
      el.innerHTML = `${val}<span class="u">${unit}</span>`;
      el.classList.remove('dim');
    }
  }

  function updateConverter() {
    const scale = document.getElementById('conv-scale').value;
    const value = parseFloat(document.getElementById('conv-value').value);

    if (!isFinite(value)) {
      ['r-hrc','r-hrb','r-hv','r-hbw','r-hra','r-hsh'].forEach(id => {
        setConv(id, null, id.replace('r-','').toUpperCase());
      });
      document.getElementById('r-uts').textContent = '—';
      document.getElementById('r-uts-mpa').textContent = '— MPa';
      document.getElementById('r-uts-ksi').textContent = '— ksi';
      return;
    }

    const hrc = scale === 'HRC' ? value : interp(value, scale, 'HRC');
    const hrb = scale === 'HRB' ? value : interp(value, scale, 'HRB');
    const hv  = scale === 'HV'  ? value : interp(value, scale, 'HV');
    const hbw = scale === 'HBW' ? value : interp(value, scale, 'HBW');
    const hra = interp(value, scale, 'HRA');
    const hsh = interp(value, scale, 'HSh');
    const uts = interp(value, scale, 'UTS');

    setConv('r-hrc', fmtConv(hrc, 1), 'HRC');
    setConv('r-hrb', fmtConv(hrb, 1), 'HRB');
    setConv('r-hv',  fmtConv(hv),     'HV');
    setConv('r-hbw', fmtConv(hbw),    'HBW');
    setConv('r-hra', fmtConv(hra, 1), 'HRA');
    setConv('r-hsh', fmtConv(hsh),    'HS');

    // UTS readout
    if (uts !== null) {
      document.getElementById('r-uts').textContent = Math.round(uts);
      document.getElementById('r-uts-mpa').textContent = `${Math.round(uts)} MPa`;
      document.getElementById('r-uts-ksi').textContent = `${Math.round(uts * 0.145038)} ksi`;
    } else {
      document.getElementById('r-uts').textContent = '—';
      document.getElementById('r-uts-mpa').textContent = '— MPa';
      document.getElementById('r-uts-ksi').textContent = '— ksi';
    }

    // highlight nearest row in table
    highlightTableRow(scale, value);
  }

  function highlightTableRow(scale, value) {
    const tbody = document.getElementById('hardness-tbody');
    const rows = tbody.querySelectorAll('tr');
    rows.forEach(r => r.classList.remove('match'));

    // find closest by scale
    let closestIdx = -1;
    let bestDelta = Infinity;
    HARDNESS.forEach((row, i) => {
      if (row[scale] === null) return;
      const d = Math.abs(row[scale] - value);
      if (d < bestDelta) { bestDelta = d; closestIdx = i; }
    });
    if (closestIdx >= 0 && rows[closestIdx]) {
      rows[closestIdx].classList.add('match');
      // scroll into view if hardness tab is active
      if (document.getElementById('view-hardness').classList.contains('active')) {
        rows[closestIdx].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }
  }

  document.getElementById('conv-scale').addEventListener('change', e => {
    // adjust default value sensibly when switching scale
    const defaults = { HRC: 40, HRB: 90, HV: 400, HBW: 380 };
    document.getElementById('conv-value').value = defaults[e.target.value];
    updateConverter();
  });
  document.getElementById('conv-value').addEventListener('input', updateConverter);

  // ============================================================
  // SHORE DUROMETER DATA — ASTM D2240
  // Approximate cross-scale equivalents and analog materials.
  // Values are interpolation-friendly; A/D overlap exists in
  // the 90A↔50D region only. OO is a separate softness scale.
  // ============================================================
  const SHORE = [
    {A: 5,   D: null, OO: 35,  IRHD: 5,   feel: 'gel',         ex: 'orthopedic gel pad'},
    {A: 10,  D: null, OO: 55,  IRHD: 10,  feel: 'very soft',   ex: 'gel insole, marshmallow'},
    {A: 20,  D: null, OO: 70,  IRHD: 20,  feel: 'soft',        ex: 'rubber band, gummy bear'},
    {A: 30,  D: null, OO: 80,  IRHD: 30,  feel: 'soft',        ex: 'pencil eraser'},
    {A: 40,  D: null, OO: 85,  IRHD: 40,  feel: 'medium-soft', ex: 'inner tube, soft seal'},
    {A: 50,  D: null, OO: 90,  IRHD: 50,  feel: 'medium',      ex: 'rubber stamp, door seal'},
    {A: 60,  D: 12,   OO: null,IRHD: 60,  feel: 'medium-firm', ex: 'tire tread, shoe sole'},
    {A: 70,  D: 18,   OO: null,IRHD: 70,  feel: 'firm',        ex: 'O-ring, skateboard wheel'},
    {A: 75,  D: 22,   OO: null,IRHD: 75,  feel: 'firm',        ex: 'roller-blade wheel'},
    {A: 80,  D: 27,   OO: null,IRHD: 80,  feel: 'hard',        ex: 'shoe heel, hydraulic seal'},
    {A: 85,  D: 32,   OO: null,IRHD: 85,  feel: 'hard',        ex: 'industrial wheel, ear plug'},
    {A: 90,  D: 38,   OO: null,IRHD: 90,  feel: 'very hard',   ex: 'shopping-cart wheel'},
    {A: 95,  D: 45,   OO: null,IRHD: 95,  feel: 'very hard',   ex: 'hard hat (semi-rigid)'},
    {A: 100, D: 55,   OO: null,IRHD: 98,  feel: 'rigid',       ex: 'ebonite, golf-ball cover'},
    {A: null,D: 65,   OO: null,IRHD: null,feel: 'rigid',       ex: 'hard plastic, bowling ball'},
    {A: null,D: 75,   OO: null,IRHD: null,feel: 'rigid',       ex: 'hard hat shell, helmet'},
    {A: null,D: 85,   OO: null,IRHD: null,feel: 'very rigid',  ex: 'PMMA, polycarbonate sheet'},
    {A: null,D: 90,   OO: null,IRHD: null,feel: 'very rigid',  ex: 'phenolic, hardened thermoset'},
  ];

  function buildShoreTable() {
    const tbody = document.getElementById('shore-tbody');
    tbody.innerHTML = '';

    SHORE.forEach((row) => {
      const tr = document.createElement('tr');
      let band = 'band-soft';
      const ref = row.A !== null ? row.A : (row.D !== null ? row.D + 50 : 0);
      if (ref >= 90) band = 'band-very-hard';
      else if (ref >= 70) band = 'band-hard';
      else if (ref >= 40) band = 'band-medium';
      tr.classList.add(band);

      const cells = [row.A, row.D, row.OO, row.IRHD, row.feel, row.ex];
      cells.forEach((v, i) => {
        const td = document.createElement('td');
        if (v === null || v === undefined) {
          td.textContent = '—';
          td.classList.add('dash');
        } else {
          td.textContent = v;
        }
        if (i >= 4) {
          td.style.textAlign = 'left';
          td.style.fontSize = '11px';
          td.style.color = i === 5 ? 'var(--text-muted)' : 'var(--text)';
        }
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  }

  function interpShore(value, fromKey, toKey) {
    const rows = SHORE.filter(r => r[fromKey] !== null && r[toKey] !== null);
    if (rows.length < 2) return null;
    const sorted = [...rows].sort((a, b) => a[fromKey] - b[fromKey]);
    if (value < sorted[0][fromKey] || value > sorted[sorted.length - 1][fromKey]) return null;
    for (let i = 0; i < sorted.length - 1; i++) {
      const a = sorted[i], b = sorted[i + 1];
      if (value >= a[fromKey] && value <= b[fromKey]) {
        const span = b[fromKey] - a[fromKey];
        if (span === 0) return a[toKey];
        const t = (value - a[fromKey]) / span;
        return a[toKey] + t * (b[toKey] - a[toKey]);
      }
    }
    return null;
  }

  function nearestShoreRow(scale, value) {
    let best = null, bestDelta = Infinity, bestIdx = -1;
    SHORE.forEach((row, i) => {
      if (row[scale] === null) return;
      const d = Math.abs(row[scale] - value);
      if (d < bestDelta) { bestDelta = d; best = row; bestIdx = i; }
    });
    return { row: best, idx: bestIdx };
  }

  function setShoreCell(id, val, unit) {
    const el = document.getElementById(id);
    if (val === null || val === undefined) {
      el.innerHTML = `—<span class="u">${unit}</span>`;
      el.classList.add('dim');
    } else {
      el.innerHTML = `${Math.round(val)}<span class="u">${unit}</span>`;
      el.classList.remove('dim');
    }
  }

  function updateShoreConverter() {
    const scale = document.getElementById('shore-scale').value;
    const value = parseFloat(document.getElementById('shore-value').value);

    if (!isFinite(value)) {
      ['s-a','s-d','s-oo','s-irhd'].forEach(id => setShoreCell(id, null, ''));
      document.getElementById('s-feel').textContent = '—';
      document.getElementById('s-example').textContent = 'analog material —';
      return;
    }

    const sa = scale === 'A'  ? value : interpShore(value, scale, 'A');
    const sd = scale === 'D'  ? value : interpShore(value, scale, 'D');
    const so = scale === 'OO' ? value : interpShore(value, scale, 'OO');
    const ir = interpShore(value, scale, 'IRHD');

    setShoreCell('s-a',    sa, 'A');
    setShoreCell('s-d',    sd, 'D');
    setShoreCell('s-oo',   so, 'OO');
    setShoreCell('s-irhd', ir, 'IRHD');

    const { row, idx } = nearestShoreRow(scale, value);
    if (row) {
      document.getElementById('s-feel').textContent = row.feel;
      document.getElementById('s-example').textContent = `analog: ${row.ex}`;

      const tbody = document.getElementById('shore-tbody');
      tbody.querySelectorAll('tr').forEach(r => r.classList.remove('match'));
      if (idx >= 0 && tbody.children[idx]) {
        tbody.children[idx].classList.add('match');
        if (document.getElementById('view-shore').classList.contains('active')) {
          tbody.children[idx].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      }
    }
  }

  document.getElementById('shore-scale').addEventListener('change', e => {
    const defaults = { A: 70, D: 50, OO: 70 };
    document.getElementById('shore-value').value = defaults[e.target.value];
    updateShoreConverter();
  });
  document.getElementById('shore-value').addEventListener('input', updateShoreConverter);

  // ============================================================
  // Tab switching
  // ============================================================
  const TAB_CONTEXTS = {
    calculator: 'PROFILE × MATERIAL → MASS',
    hardness: 'ASTM E140 · NON-AUSTENITIC STEELS',
    shore: 'ASTM D2240 · RUBBERS & PLASTICS',
  };

  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
      document.getElementById('view-' + tab).classList.add('active');
      document.getElementById('tab-context').textContent = TAB_CONTEXTS[tab] || '';
    });
  });

  // -------------------------------------------------------------------
  // MagDyn integration: ?view=<tab> in the URL switches to that tab on
  // load. Used by the MagDyn sidebar's third-level entries to deep-link
  // straight to Weight Calculator / Hardness / Shore.
  // -------------------------------------------------------------------
  try {
    var requested = new URLSearchParams(window.location.search).get('view');
    var known = ['calculator','hardness','shore'];
    if (requested && known.indexOf(requested) !== -1) {
      var btn = document.querySelector('.tab-btn[data-tab="' + requested + '"]');
      if (btn) btn.click();
    }
  } catch (e) { /* leave default tab in place */ }


  // ============================================================
  // Init hardness + shore modules
  // ============================================================
  buildHardnessTable();
  updateConverter();
  buildShoreTable();
  updateShoreConverter();

</script>

<?php include 'includes/footer.php'; ?>
