<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'CAD viewer · Training guide';
$current_page  = 'cad-viewer-training.php';
$trigger_style = 'dark';
$cdn_scripts   = [];
include 'includes/head.php';
?>
<style>
/* ============ TRAINING DOC — MagDyn ============ */
:root { --muted: var(--text-muted); }

body { overflow-x: hidden; }
.layout { min-height: 100vh; }

/* TOC sidebar — uses MagDyn dark sidebar; nav-items become anchors */
.sidebar {
    height: 100vh;
    position: sticky;
    top: 0;
    overflow-y: auto;
}
.toc-heading {
    padding: 14px 16px 6px;
    font-size: 10px;
    color: var(--sidebar-text-very-dim);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 600;
}
.toc ol {
    list-style: none;
    padding: 4px 8px 16px;
    margin: 0;
    counter-reset: tocsec;
}
.toc ol li {
    counter-increment: tocsec;
}
.toc ol li a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 12px;
    margin: 2px 0;
    color: var(--sidebar-text);
    font-size: 13px;
    border-radius: 6px;
    text-decoration: none;
    transition: background 0.12s, color 0.12s;
}
.toc ol li a::before {
    content: counter(tocsec, decimal-leading-zero);
    font-size: 10px;
    font-weight: 700;
    color: var(--sidebar-text-very-dim);
    letter-spacing: 0.05em;
    flex-shrink: 0;
    width: 18px;
}
.toc ol li a:hover {
    background: var(--sidebar-bg-hover);
    color: white;
    text-decoration: none;
}
.toc ol li a:hover::before { color: rgba(255,255,255,0.6); }
.toc ol li a.active {
    background: var(--sidebar-bg-active);
    color: white;
}
.toc ol li a.active::before { color: rgba(255,255,255,0.6); }

/* Main column */
.main {
    padding: 32px 40px 60px;
    max-width: 880px;
}

/* Hero */
.hero {
    margin-bottom: 36px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
}
.hero .eyebrow {
    font-size: 11px;
    color: var(--primary);
    letter-spacing: 0.12em;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 12px;
}
.hero h1 {
    font-size: 32px;
    font-weight: 600;
    letter-spacing: -0.02em;
    line-height: 1.15;
    margin-bottom: 16px;
}
.hero h1 strong {
    color: var(--primary);
    font-weight: 700;
}
.hero p.lede {
    font-size: 15.5px;
    color: var(--text-muted);
    line-height: 1.65;
    max-width: 720px;
}

/* Sections */
main section {
    margin-bottom: 44px;
    scroll-margin-top: 12px;
}
main section h2 {
    font-size: 22px;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: baseline;
    gap: 12px;
}
main section h2 .num {
    font-size: 12px;
    color: var(--primary);
    background: var(--primary-light);
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 700;
    letter-spacing: 0.04em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
main section h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 22px 0 10px;
    color: var(--text);
}
main section h4 {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin: 22px 0 10px;
}
main section p {
    margin-bottom: 12px;
    line-height: 1.7;
    color: var(--text);
    font-size: 14.5px;
}
main section p.dim,
main section .sub {
    color: var(--text-muted);
    font-size: 13.5px;
}
main section ol, main section ul {
    margin: 0 0 14px 22px;
    line-height: 1.8;
}
main section ol li, main section ul li {
    margin-bottom: 4px;
    color: var(--text);
}

/* Tables — reuse MagDyn .data-table feel */
main section table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
    font-size: 13.5px;
}
main section table thead th {
    text-align: left;
    padding: 10px 14px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
}
main section table td {
    padding: 10px 14px;
    border: 1px solid var(--border);
    vertical-align: top;
}
main section table td.mono,
main section table td.dim {
    color: var(--text-muted);
    font-size: 13px;
}
main section table td.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--surface-alt);
}

/* Callouts */
.callout {
    background: var(--info-bg);
    color: var(--info);
    border-left: 3px solid var(--info);
    padding: 14px 18px;
    border-radius: var(--radius);
    margin: 18px 0;
    font-size: 13.5px;
    line-height: 1.65;
}
.callout .label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 700;
    margin-bottom: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.callout p { color: inherit; font-size: inherit; margin-bottom: 6px; }
.callout p:last-child { margin-bottom: 0; }
.callout.note {
    background: var(--surface-alt);
    color: var(--text);
    border-left-color: var(--primary);
}
.callout.note .label { color: var(--primary); }
.callout.warn {
    background: var(--warn-bg);
    color: var(--warn);
    border-left-color: var(--warn);
}

/* Steps */
.steps { margin: 14px 0 18px; counter-reset: step; }
.step {
    display: grid;
    grid-template-columns: 32px 1fr;
    gap: 14px;
    margin-bottom: 14px;
    padding-bottom: 14px;
    border-bottom: 1px dashed var(--border);
}
.step:last-child { border-bottom: none; padding-bottom: 0; }
.step-num {
    counter-increment: step;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}
.step-num::before { content: counter(step); }
.step-body p { margin-bottom: 6px; line-height: 1.6; }
.step-body p.sub {
    font-size: 12.5px;
    color: var(--text-muted);
    margin-top: 2px;
}

/* Key-grid (keyboard shortcuts table) */
.key-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 24px;
    margin-bottom: 18px;
}
@media (max-width: 800px) { .key-grid { grid-template-columns: 1fr; } }
.key-grid .row {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 10px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    align-items: baseline;
}
.key-grid .keys {
    display: flex;
    gap: 4px;
    align-items: center;
    flex-wrap: wrap;
}
.key-grid .desc { color: var(--text-muted); }

/* Terminal block */
.terminal {
    background: #0f172a;
    color: #e2e8f0;
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    margin: 14px 0 18px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    line-height: 1.6;
    overflow-x: auto;
}
.terminal .prompt { color: #4ade80; }
.terminal .prompt::before { content: '$ '; color: #94a3b8; }
.terminal .out { color: #cbd5e1; }
.terminal .out::before { content: '  '; }

/* Workflow cards */
.workflows {
    display: grid;
    gap: 14px;
    margin: 14px 0 18px;
}
.workflow-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 18px;
    box-shadow: var(--shadow);
}
.workflow-card h3 {
    margin-top: 0;
    margin-bottom: 6px;
    color: var(--primary);
}
.workflow-card .sub {
    font-size: 12.5px;
    color: var(--text-muted);
    margin-bottom: 14px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
}
.workflow-card ol {
    margin: 0 0 0 20px;
    counter-reset: wstep;
    list-style: decimal;
}
.workflow-card ol li {
    margin-bottom: 6px;
    line-height: 1.6;
}

/* GD&T symbol grid (bubble training doc only) */
.gdt-table {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 8px;
    margin: 14px 0 18px;
}
.gdt-table .gdt-cell {
    display: grid;
    grid-template-columns: 36px 1fr;
    gap: 10px;
    align-items: center;
    padding: 10px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
}
main section table .gdt-cell {
    display: grid;
    grid-template-columns: 36px 1fr;
    gap: 8px;
    align-items: center;
}
.gdt-cell .sym {
    font-size: 22px;
    font-weight: 600;
    color: var(--primary);
    text-align: center;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.gdt-cell .name { color: var(--text); }

/* Inline code / kbd */
kbd {
    display: inline-block;
    padding: 2px 7px;
    background: var(--surface-alt);
    border: 1px solid var(--border-strong);
    border-bottom-width: 2px;
    border-radius: 4px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text);
}
code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    background: var(--surface-alt);
    padding: 1px 6px;
    border-radius: 3px;
    color: var(--text);
}

/* Tag chip */
.tag {
    display: inline-block;
    font-size: 10px;
    padding: 2px 8px;
    background: var(--surface-alt);
    color: var(--text-muted);
    border: 1px solid var(--border);
    border-radius: 4px;
    font-weight: 500;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-right: 4px;
}

/* Footer */
.foot {
    margin-top: 60px;
    padding: 20px 0;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    color: var(--text-light);
    font-size: 11.5px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-weight: 600;
}

/* Inline emphasis classes */
.dim { color: var(--text-muted); }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

/* Glossary / definition list */
main section dl {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 8px 18px;
    margin: 14px 0 18px;
}
main section dl dt {
    font-weight: 600;
    color: var(--primary);
    padding: 8px 0;
    font-size: 13.5px;
}
main section dl dd {
    padding: 8px 0;
    line-height: 1.65;
    color: var(--text);
    font-size: 13.5px;
    border-bottom: 1px solid var(--border);
    margin: 0;
}
main section dl dt + dd ~ dt {
    border-top: 1px solid var(--border);
    margin-top: 0;
    padding-top: 8px;
}

/* Print */
@media print {
    .sidebar, .foot { display: none !important; }
    .layout { display: block; }
    .main { padding: 0; max-width: none; }
}

/* ============ CAD training-doc extras ============ */

/* Tier chips (pill-style) */
.tier {
    display: inline-block;
    font-size: 10.5px;
    padding: 3px 9px;
    border-radius: 999px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.tier.native  { background: var(--success-bg); color: var(--success); }
.tier.wasm    { background: var(--info-bg);    color: var(--info); }
.tier.detect  { background: var(--warn-bg);    color: var(--warn); }

/* Note (alias for callout) */
.note {
    background: var(--info-bg);
    color: var(--info);
    border-left: 3px solid var(--info);
    padding: 14px 18px;
    border-radius: var(--radius);
    margin: 18px 0;
    font-size: 13.5px;
    line-height: 1.65;
}
.note p { color: inherit; margin-bottom: 6px; }
.note p:last-child { margin-bottom: 0; }
.note.info {
    background: var(--info-bg);
    color: var(--info);
    border-left-color: var(--info);
}
.note.warn {
    background: var(--warn-bg);
    color: var(--warn);
    border-left-color: var(--warn);
}

/* CTAs in header */
.cta-row {
    display: flex;
    gap: 8px;
    margin-top: 18px;
    flex-wrap: wrap;
}
.cta {
    display: inline-block;
    padding: 9px 16px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.12s;
    border: 1px solid var(--border-strong);
    background: var(--surface);
    color: var(--text);
}
.cta:hover { background: var(--surface-alt); text-decoration: none; }
.cta.primary {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
.cta.primary:hover { background: var(--primary-dark); color: white; }
.cta.secondary { background: var(--surface); }

/* Snap-cell grid */
.snap-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin: 14px 0 18px;
}
.snap-cell {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px;
    text-align: center;
    box-shadow: var(--shadow);
}
.snap-cell svg {
    width: 60px;
    height: 60px;
    display: block;
    margin: 0 auto 8px;
}
.snap-cell .label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--primary);
    margin-bottom: 6px;
}
.snap-cell .desc {
    font-size: 12.5px;
    color: var(--text-muted);
    line-height: 1.5;
    text-align: left;
}

/* Toolbar mockup (figure showing tool buttons) */
.toolbar-mockup {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.toolbar-mockup .badge {
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    background: var(--info-bg);
    color: var(--info);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.toolbar-mockup .name {
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    margin-right: auto;
    padding-left: 4px;
}
.toolbar-mockup .btn {
    padding: 5px 10px;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    border-radius: var(--radius);
    font-size: 12px;
    cursor: default;
}

/* Figure / figcaption */
figure {
    margin: 14px 0 18px;
}
figcaption {
    margin-top: 8px;
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.55;
    font-style: italic;
}







</style>
</head>
<body>


<div class="layout">

<aside class="sidebar">
        
    <div class="brand">
        <div class="brand-mark">
            <div style="width:24px;height:24px;border-radius:6px;background:var(--primary);"></div>
        </div>
        <div class="brand-text">
            <div class="brand-title">CAD viewer</div>
            <div class="brand-sub">Training guide</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">On this page</div>
        <ol>
    <li><a href="#quickstart">Quick start</a></li>
    <li><a href="#formats">Format coverage</a></li>
    <li><a href="#nav-2d">Navigating 2D drawings</a></li>
    <li><a href="#nav-3d">Navigating 3D models</a></li>
    <li><a href="#measure">Measurement tools</a></li>
    <li><a href="#snap">Snap reference</a></li>
    <li><a href="#rulers">Rulers and coordinates</a></li>
    <li><a href="#export">Exporting to SVG</a></li>
    <li><a href="#step">STEP and IGES</a></li>
    <li><a href="#convert">Detect-only formats</a></li>
    <li><a href="#privacy">Privacy</a></li>
    <li><a href="#troubleshooting">Troubleshooting</a></li>
    <li><a href="#keyboard">Keyboard reference</a></li>
    <li><a href="#glossary">Glossary</a></li>
  </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Training guide</div>
    <h1>CAD viewer</h1>
    <p class="lede">
        A browser-based viewer for 2D and 3D CAD drawings. Files stay in your browser — nothing is uploaded.
        This guide covers what's supported, how to use it, and what to do when a file isn't directly openable.
    </p>
    <div class="cta-row">
        <a class="cta primary" href="cad-viewer.html">Open the viewer →</a>
        <a class="cta secondary" href="#quickstart">Skip to quick start</a>
    </div>
</div>

<section id="quickstart">
  <h2><span class="num">01</span>Quick start</h2>
  <p>Sixty seconds, four steps:</p>
  <ol>
    <li><strong>Open the viewer.</strong> A drop zone fills the page.</li>
    <li><strong>Drop a file</strong> onto it, or click to browse. The viewer detects the format from the file's content (not just its extension) and routes it to the right loader.</li>
    <li><strong>Look at it.</strong> 2D files (DXF, CGM, STL when ASCII...) get a flat canvas with rulers; 3D files get an orbiting camera. The toolbar at the top shows what's available for the file type you loaded.</li>
    <li><strong>Measure or export.</strong> Click <em>Linear</em> or <em>Diameter</em> to take measurements. Click <em>Export SVG</em> on a 2D drawing to save what you're looking at.</li>
  </ol>

  <div class="note info">
    <div class="title">Note</div>
    Files never leave your browser. Parsing happens client-side; the only network traffic is for the viewer's own dependencies (Three.js for 3D, optionally OpenCASCADE WASM for STEP files). See <a href="#privacy">Privacy</a>.
  </div>
</section>

<section id="formats">
  <h2><span class="num">02</span>Format coverage</h2>
  <p>Three tiers of support, depending on what's realistic to do in pure JavaScript:</p>

  <table>
    <thead>
      <tr>
        <th>Format</th>
        <th>Extensions</th>
        <th>Status</th>
        <th>Notes</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>DXF</strong></td>
        <td><code>.dxf</code></td>
        <td><span class="tier native">Native</span></td>
        <td>AutoCAD's open text format. Layer colors honored, INSERT blocks flattened, splines approximated as polylines.</td>
      </tr>
      <tr>
        <td><strong>CGM</strong> (clear text)</td>
        <td><code>.cgm</code></td>
        <td><span class="tier native">Native</span></td>
        <td>ISO/IEC 8632. Lines, polygons, arcs, ellipses, text alignment + rotation, indexed/direct color, cell arrays for raster patches.</td>
      </tr>
      <tr>
        <td><strong>STL</strong></td>
        <td><code>.stl</code></td>
        <td><span class="tier native">Native</span></td>
        <td>ASCII and binary. Auto-detected by file size matching <code>84 + N×50</code>.</td>
      </tr>
      <tr>
        <td><strong>OBJ</strong></td>
        <td><code>.obj</code></td>
        <td><span class="tier native">Native</span></td>
        <td>Vertices + faces. Polygons fan-triangulated. Materials and textures ignored.</td>
      </tr>
      <tr>
        <td><strong>3DS</strong></td>
        <td><code>.3ds</code></td>
        <td><span class="tier native">Native</span></td>
        <td>Three.js TDSLoader. Diffuse colors preserved; texture references stripped.</td>
      </tr>
      <tr>
        <td><strong>STEP</strong> / <strong>IGES</strong></td>
        <td><code>.step</code> <code>.stp</code> <code>.iges</code> <code>.igs</code></td>
        <td><span class="tier wasm">WASM kernel</span></td>
        <td>Parametric B-Rep. Lazy-loads ~10MB of OpenCASCADE WASM on first use; cached after. Per-part colors preserved.</td>
      </tr>
      <tr>
        <td><strong>DWG</strong></td>
        <td><code>.dwg</code></td>
        <td><span class="tier detect">Detect only</span></td>
        <td>Closed Autodesk format. Convert to DXF first.</td>
      </tr>
      <tr>
        <td><strong>Binary CGM</strong></td>
        <td><code>.cgm</code></td>
        <td><span class="tier detect">Detect only</span></td>
        <td>Binary opcode stream. Convert to clear-text CGM, SVG, or another format.</td>
      </tr>
      <tr>
        <td><strong>JT</strong></td>
        <td><code>.jt</code></td>
        <td><span class="tier detect">Detect only</span></td>
        <td>Siemens tessellation format. Convert to STEP or STL via JT2Go or NX.</td>
      </tr>
    </tbody>
  </table>

  <h3>What the tiers mean</h3>
  <ul>
    <li><span class="tier native">Native</span> — parsed in pure JavaScript, no extra downloads. Fast and offline-capable once the viewer page is cached.</li>
    <li><span class="tier wasm">WASM kernel</span> — uses a WebAssembly geometry kernel. The first file of this type triggers a ~10MB one-time download; subsequent files are instant.</li>
    <li><span class="tier detect">Detect only</span> — the viewer recognizes the file but can't open it. You'll see a message explaining the format and pointing at conversion paths. See <a href="#convert">Detect-only formats</a>.</li>
  </ul>
</section>

<section id="nav-2d">
  <h2><span class="num">03</span>Navigating 2D drawings</h2>

  <p>When you load a 2D file (DXF or CGM), the toolbar looks like this:</p>
  <figure>
    <div class="toolbar-mockup">
      <span class="badge">DXF</span>
      <span class="name">drawing.dxf</span>
      <span class="btn">Linear</span>
      <span class="btn">Diameter</span>
      <span class="btn">Fit</span>
      <span class="btn">Export SVG</span>
      <span class="btn">New file</span>
    </div>
    <figcaption>The 2D toolbar. <em>Clear</em> appears once you have at least one measurement. The format badge tells you what was detected.</figcaption>
  </figure>

  <h3>Navigation</h3>
  <table>
    <tbody>
      <tr><td><strong>Pan</strong></td><td>Click-drag with the left mouse button. Disabled in measure mode (left-click is reserved for picking).</td></tr>
      <tr><td><strong>Zoom</strong></td><td>Scroll wheel. The cursor stays the pivot point — point at a feature and zoom in to keep it centered.</td></tr>
      <tr><td><strong>Fit</strong></td><td>Toolbar button. Frames the bounding box of the entire drawing with ~8% padding.</td></tr>
      <tr><td><strong>Pinch zoom</strong></td><td>Two-finger pinch on touch devices, with the midpoint as pivot.</td></tr>
      <tr><td><strong>Two-finger pan</strong></td><td>Two-finger drag on touch.</td></tr>
    </tbody>
  </table>

  <p>Lines drawn at zoom level 1× are 1 device pixel wide. As you zoom in, line widths increase proportionally; as you zoom out, they're capped at 1px so detail doesn't disappear into a haze.</p>
</section>

<section id="nav-3d">
  <h2><span class="num">04</span>Navigating 3D models</h2>

  <p>3D files (STL, OBJ, 3DS, STEP, IGES) get a different toolbar:</p>
  <figure>
    <div class="toolbar-mockup">
      <span class="badge">STL</span>
      <span class="name">part.stl</span>
      <span class="btn">Wireframe</span>
      <span class="btn">Linear</span>
      <span class="btn">Fit</span>
      <span class="btn">New file</span>
    </div>
    <figcaption>The 3D toolbar. <em>Diameter</em> and <em>Export SVG</em> are hidden — they don't apply to mesh data.</figcaption>
  </figure>

  <h3>Navigation</h3>
  <table>
    <tbody>
      <tr><td><strong>Orbit</strong></td><td>Left-drag. Outside measure mode. In measure mode, orbit moves to <strong>right-drag</strong> so left-click can pick.</td></tr>
      <tr><td><strong>Pan</strong></td><td>Right-drag (default) or middle-drag.</td></tr>
      <tr><td><strong>Zoom (dolly)</strong></td><td>Scroll wheel.</td></tr>
      <tr><td><strong>Fit</strong></td><td>Frames the model's bounding sphere off-axis so it isn't a flat silhouette on first view.</td></tr>
      <tr><td><strong>Touch</strong></td><td>One finger: orbit. Two fingers: pan + dolly. In measure mode, single-finger orbit is disabled.</td></tr>
    </tbody>
  </table>

  <h3>Wireframe mode</h3>
  <p>The Wireframe button replaces the solid shaded surfaces with edge-only lines. The edges are extracted from each mesh using Three.js's <code>EdgesGeometry</code> with a 20° crease threshold, so you see silhouette and feature edges rather than every triangulation line. Useful for understanding internal structure of an assembly.</p>

  <h3>Lighting</h3>
  <p>Two directional lights (key + fill) plus ambient. Materials use double-sided rendering, so back-faces don't go pitch-black when you look at a model from inside or behind. STEP/IGES models preserve per-part colors from the source file; STL/OBJ/3DS use a default neutral gray.</p>
</section>

<section id="measure">
  <h2><span class="num">05</span>Measurement tools</h2>

  <h3>Linear distance</h3>
  <p>Works in both 2D and 3D. Click <em>Linear</em>, then click two points. The distance is reported on a labeled dimension line.</p>

  <figure>
    <svg viewBox="0 0 400 130" xmlns="http://www.w3.org/2000/svg">
      <!-- The "drawing" -->
      <line x1="60" y1="100" x2="340" y2="100" stroke="currentColor" stroke-width="1" stroke-opacity="0.4"/>
      <circle cx="60" cy="100" r="3" fill="currentColor" fill-opacity="0.4"/>
      <circle cx="340" cy="100" r="3" fill="currentColor" fill-opacity="0.4"/>
      <!-- The dimension line -->
      <line x1="60" y1="60" x2="340" y2="60" stroke="#1e3a8a" stroke-width="1.2"/>
      <!-- Tick marks -->
      <line x1="60" y1="55" x2="60" y2="65" stroke="#1e3a8a" stroke-width="1.2"/>
      <line x1="340" y1="55" x2="340" y2="65" stroke="#1e3a8a" stroke-width="1.2"/>
      <!-- Label -->
      <rect x="170" y="48" width="60" height="20" fill="var(--surface)" stroke="none" rx="2"/>
      <text x="200" y="62" text-anchor="middle" fill="#1e3a8a" font-size="13" font-family="sans-serif">280.0</text>
    </svg>
    <figcaption>Linear dimension. The line is amber, with perpendicular tick marks at the picked points and a label rotated to match the line's angle.</figcaption>
  </figure>

  <p><strong>In 2D</strong>, the cursor finds nearby snap candidates while you're picking. See <a href="#snap">Snap reference</a> for the full set.</p>
  <p><strong>In 3D</strong>, picking is a raycast — the cursor casts a ray into the scene and the first triangle hit becomes the candidate. If a vertex of that triangle is within ~15 screen pixels of the hit, the pick snaps to the vertex.</p>

  <h3>Diameter (2D only)</h3>
  <p>Click <em>Diameter</em>, then click on the edge of a circle or arc. Diameter on tessellated 3D meshes would mean detecting circular features in triangle data, which is a different problem entirely; not supported in 3D.</p>

  <figure>
    <svg viewBox="0 0 400 200" xmlns="http://www.w3.org/2000/svg">
      <!-- The circle -->
      <circle cx="200" cy="100" r="55" fill="none" stroke="currentColor" stroke-width="1" stroke-opacity="0.4"/>
      <!-- Diameter line -->
      <line x1="145" y1="100" x2="255" y2="100" stroke="#1e3a8a" stroke-width="1.2"/>
      <!-- Arrowheads -->
      <polygon points="145,100 153,96 153,104" fill="#1e3a8a"/>
      <polygon points="255,100 247,96 247,104" fill="#1e3a8a"/>
      <!-- Label -->
      <rect x="180" y="90" width="40" height="20" fill="var(--surface)" stroke="none" rx="2"/>
      <text x="200" y="104" text-anchor="middle" fill="#1e3a8a" font-size="13" font-family="sans-serif">Ø110</text>
    </svg>
    <figcaption>Diameter dimension. Arrowheads at both ends, <code>Ø</code> prefix on the label. The line passes through the center along the direction from center to your click — click the right side for a horizontal diameter, click the top for vertical.</figcaption>
  </figure>

  <h3>Workflow notes</h3>
  <ul>
    <li>Modes are <strong>mutually exclusive</strong> — clicking one toggles off the other.</li>
    <li>The active button shows a highlighted state.</li>
    <li><strong>Esc</strong> cancels an in-progress pick. A second <strong>Esc</strong> exits measure mode.</li>
    <li>Measurements are stored in <strong>world coordinates</strong>, so they survive panning, zooming, and Fit.</li>
    <li>Loading a new file clears all measurements.</li>
    <li>The <em>Clear</em> button (which appears once you have at least one measurement) wipes them all in one click.</li>
  </ul>
</section>

<section id="snap">
  <h2><span class="num">06</span>Snap reference</h2>

  <p>While a measurement mode is active, the cursor is continuously checked against snap candidates extracted from every entity in the drawing. The closest one within ~12 screen pixels wins. The <strong>marker shape tells you what kind of feature</strong> got snapped:</p>

  <figure>
    <div class="snap-grid">
      <div class="snap-cell">
        <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
          <rect x="22" y="22" width="16" height="16" fill="#1e3a8a"/>
          <rect x="23" y="23" width="14" height="14" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="1"/>
        </svg>
        <div class="label">Endpoint</div>
        <div class="desc">Filled square. Line endpoints, polyline/polygon vertices, rectangle corners, arc start/end points.</div>
      </div>
      <div class="snap-cell">
        <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
          <polygon points="30,18 41,38 19,38" fill="#1e3a8a" stroke="rgba(255,255,255,0.9)" stroke-width="1"/>
        </svg>
        <div class="label">Midpoint</div>
        <div class="desc">Triangle. Line midpoints, polyline/polygon segment midpoints, rectangle edge midpoints, arc midpoint along curve.</div>
      </div>
      <div class="snap-cell">
        <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
          <circle cx="30" cy="30" r="8" fill="none" stroke="#1e3a8a" stroke-width="2"/>
          <line x1="18" y1="30" x2="42" y2="30" stroke="#1e3a8a" stroke-width="2"/>
          <line x1="30" y1="18" x2="30" y2="42" stroke="#1e3a8a" stroke-width="2"/>
        </svg>
        <div class="label">Center</div>
        <div class="desc">Circle with cross. Circles, ellipses, arc centers.</div>
      </div>
      <div class="snap-cell">
        <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
          <circle cx="30" cy="30" r="6" fill="#1e3a8a" fill-opacity="0.9"/>
        </svg>
        <div class="label">Vertex (3D)</div>
        <div class="desc">Sphere. Triangle vertices in mesh data — the only snap kind in 3D.</div>
      </div>
    </div>
    <figcaption>Snap markers, drawn at the same size and color the viewer uses. The icons are CAD conventions, so they should look familiar from any AutoCAD-family tool.</figcaption>
  </figure>

  <h3>Snap behavior</h3>
  <ul>
    <li>The snap tolerance is <strong>screen-relative</strong> — a constant ~12 pixels regardless of zoom. Easy to land on close-together features when zoomed in, no need for pixel-perfect aim when zoomed out.</li>
    <li>The status bar shows the <strong>snapped coordinates</strong>, not the raw cursor — confirm visually before clicking.</li>
    <li>The committed measurement uses the snap point, so dimensions land exactly on geometric features rather than approximately.</li>
  </ul>

  <h3>What gets snap candidates</h3>
  <table>
    <thead>
      <tr><th>Entity</th><th>Snap candidates</th></tr>
    </thead>
    <tbody>
      <tr><td>Line</td><td>2 endpoints + 1 midpoint</td></tr>
      <tr><td>Polyline (open)</td><td>n endpoints + (n−1) segment midpoints</td></tr>
      <tr><td>Polyline (closed) / polygon</td><td>n endpoints + n segment midpoints</td></tr>
      <tr><td>Circle / ellipse</td><td>1 center</td></tr>
      <tr><td>Arc</td><td>2 endpoints + 1 mid-curve point + 1 center</td></tr>
      <tr><td>Rectangle</td><td>4 corners + 4 edge midpoints</td></tr>
      <tr><td>3D mesh</td><td>Triangle vertices (per-hit, not pre-extracted)</td></tr>
    </tbody>
  </table>
</section>

<section id="rulers">
  <h2><span class="num">07</span>Rulers and coordinates</h2>

  <p>2D drawings get rulers along the top and left edges, with a live coordinate readout in the status bar.</p>

  <figure>
    <svg viewBox="0 0 400 200" xmlns="http://www.w3.org/2000/svg">
      <!-- Background -->
      <rect x="0" y="0" width="400" height="200" fill="var(--surface)"/>

      <!-- Top ruler -->
      <rect x="0" y="0" width="400" height="22" fill="var(--surface-2)"/>
      <line x1="0" y1="22" x2="400" y2="22" stroke="var(--border)" stroke-width="0.5"/>

      <!-- Top ticks -->
      <g stroke="var(--muted)" stroke-width="1">
        <line x1="80" y1="15" x2="80" y2="22"/>
        <line x1="160" y1="15" x2="160" y2="22"/>
        <line x1="240" y1="15" x2="240" y2="22"/>
        <line x1="320" y1="15" x2="320" y2="22"/>
        <!-- Minor -->
        <line x1="100" y1="19" x2="100" y2="22"/>
        <line x1="120" y1="19" x2="120" y2="22"/>
        <line x1="140" y1="19" x2="140" y2="22"/>
        <line x1="180" y1="19" x2="180" y2="22"/>
        <line x1="200" y1="19" x2="200" y2="22"/>
        <line x1="220" y1="19" x2="220" y2="22"/>
        <line x1="260" y1="19" x2="260" y2="22"/>
        <line x1="280" y1="19" x2="280" y2="22"/>
        <line x1="300" y1="19" x2="300" y2="22"/>
      </g>
      <!-- Top labels -->
      <g font-size="9" fill="var(--text)" text-anchor="middle" font-family="sans-serif">
        <text x="80" y="11">0</text>
        <text x="160" y="11">100</text>
        <text x="240" y="11">200</text>
        <text x="320" y="11">300</text>
      </g>

      <!-- Left ruler -->
      <rect x="0" y="0" width="22" height="200" fill="var(--surface-2)"/>
      <line x1="22" y1="0" x2="22" y2="200" stroke="var(--border)" stroke-width="0.5"/>

      <!-- Left ticks -->
      <g stroke="var(--muted)" stroke-width="1">
        <line x1="15" y1="60" x2="22" y2="60"/>
        <line x1="15" y1="100" x2="22" y2="100"/>
        <line x1="15" y1="140" x2="22" y2="140"/>
        <line x1="19" y1="80" x2="22" y2="80"/>
        <line x1="19" y1="120" x2="22" y2="120"/>
        <line x1="19" y1="160" x2="22" y2="160"/>
      </g>
      <!-- Left labels -->
      <g font-size="9" fill="var(--text)" text-anchor="end" font-family="sans-serif">
        <text x="13" y="63">100</text>
        <text x="13" y="103">50</text>
        <text x="13" y="143">0</text>
      </g>

      <!-- Cursor stripes -->
      <rect x="200" y="0" width="1" height="22" fill="#1e3a8a" fill-opacity="0.5"/>
      <rect x="0" y="100" width="22" height="1" fill="#1e3a8a" fill-opacity="0.5"/>

      <!-- Cursor crosshair -->
      <circle cx="200" cy="100" r="3" fill="none" stroke="#1e3a8a" stroke-width="1"/>

      <!-- Some example geometry to show context -->
      <rect x="80" y="60" width="240" height="100" fill="none" stroke="currentColor" stroke-width="1" stroke-opacity="0.4"/>

      <!-- Top-left corner -->
      <rect x="0" y="0" width="22" height="22" fill="var(--surface-2)"/>
      <line x1="22" y1="0" x2="22" y2="22" stroke="var(--border)" stroke-width="0.5"/>
      <line x1="0" y1="22" x2="22" y2="22" stroke="var(--border)" stroke-width="0.5"/>
    </svg>
    <figcaption>Rulers with major ticks at "nice" round numbers and minor ticks at 1/5 spacing. The amber stripes track the cursor's X and Y on each ruler.</figcaption>
  </figure>

  <h3>Tick interval auto-scaling</h3>
  <p>The major tick interval is always <code>{1, 2, or 5} × 10ⁿ</code>, picked so on-screen spacing lands around 100 pixels. Zoom in 10× and the interval shrinks by 10×; zoom out and it grows. So you never see crowded labels or sparse ones — the rulers are always readable.</p>

  <h3>Coordinate readout</h3>
  <p>The status bar at the bottom shows live cursor coordinates in CAD-space (y-up convention preserved). Decimal precision adapts to zoom — more decimals when zoomed in close, fewer when zoomed out. When a snap is active, the readout shows the <em>snapped</em> position rather than the raw cursor, so you can confirm the pick visually.</p>
</section>

<section id="export">
  <h2><span class="num">08</span>Exporting to SVG</h2>

  <p>The <em>Export SVG</em> button serializes the current 2D drawing to a standalone SVG file and triggers a download. The exported file:</p>

  <ul>
    <li>Uses the original CAD coordinates (y-up convention) inside an outer <code>&lt;g transform="scale(1 -1)"&gt;</code> wrapper</li>
    <li>Preserves entity colors (DXF layer colors via the ACI palette, CGM direct/indexed colors)</li>
    <li>Preserves text alignment + rotation with per-element counter-flips so glyphs stay upright</li>
    <li>Embeds CGM cell arrays as inline <code>&lt;image&gt;</code> data URLs with <code>image-rendering: pixelated</code> for crisp scaling</li>
    <li>Approximates arcs and rotated ellipses as polylines (32+ points per arc) for direction-independent rendering</li>
  </ul>

  <p>The filename mirrors the input (e.g. <code>drawing.dxf</code> → <code>drawing.svg</code>).</p>

  <div class="note warn">
    <div class="title">What's not exported</div>
    Measurements aren't included in the SVG output — only the source drawing's geometry. If you need annotated output, screenshot the canvas instead, or take measurements after opening the SVG in a downstream tool.
  </div>
</section>

<section id="step">
  <h2><span class="num">09</span>STEP and IGES</h2>

  <p>STEP (ISO 10303) and IGES are <strong>parametric B-Rep</strong> formats — they store surfaces, edges, and topology rather than triangles. To render them, you need a geometry kernel that can evaluate parametric NURBS surfaces into mesh data. The viewer uses <a href="https://github.com/kovacsv/occt-import-js" target="_blank" rel="noopener">occt-import-js</a>, an Emscripten port of OpenCASCADE.</p>

  <h3>What you'll see on first use</h3>
  <ol>
    <li>Drop a STEP or IGES file. The drop zone hides; an info-styled message appears: <em>"Loading STEP parser (~10MB on first use)…"</em></li>
    <li>The WASM downloads from a CDN. First time only — subsequent files in the same session use the cached parser.</li>
    <li>Once loaded, the message updates to <em>"Parsing STEP file…"</em> while the kernel evaluates the surfaces.</li>
    <li>The 3D scene appears, with one Three.js <code>Mesh</code> per part in the source assembly. Per-part colors are preserved.</li>
  </ol>

  <h3>What it costs</h3>
  <ul>
    <li><strong>10MB one-time download</strong>, cached after first use.</li>
    <li><strong>Few hundred milliseconds</strong> of WASM initialization on first load.</li>
    <li><strong>Variable parse time</strong> depending on assembly complexity. A simple part loads instantly; a multi-thousand-part assembly might take 30+ seconds with no progress indicator (the parse is synchronous inside the kernel).</li>
  </ul>

  <h3>If the load fails</h3>
  <p>If the WASM CDN is unreachable (corporate network, ad blocker, offline), the viewer falls back to the same conversion-guidance message it shows for detect-only formats. So you'll still know what to do — convert externally and try again.</p>
</section>

<section id="convert">
  <h2><span class="num">10</span>Detect-only formats</h2>

  <p>Three formats are recognized but can't be opened in-browser. The viewer shows a clear conversion path for each.</p>

  <h3>DWG (AutoCAD Drawing)</h3>
  <p>Closed Autodesk format. The reverse-engineered library is LibreDWG (GPL), and there's no permissively-licensed WASM port the way OpenCASCADE works for STEP. Practical workflow:</p>
  <ol>
    <li>Download <a href="https://www.opendesign.com/guestfiles/oda_file_converter" target="_blank" rel="noopener">ODA File Converter</a> — free, from the Open Design Alliance.</li>
    <li>Set input to your DWG, output format to DXF.</li>
    <li>Drop the resulting DXF here.</li>
  </ol>
  <p>Or open the DWG in any CAD app (AutoCAD, LibreCAD, QCAD, FreeCAD) and export as DXF.</p>

  <h3>Binary CGM</h3>
  <p>CGM has three encodings (clear text, character, binary). The viewer reads clear text natively. Binary uses a typed opcode stream that's painful to parse without a battle-tested library — and there isn't a maintained pure-JS one.</p>
  <ul>
    <li><a href="https://imagemagick.org/" target="_blank" rel="noopener">ImageMagick</a> with the right delegate: <code>magick file.cgm out.svg</code></li>
    <li>RalCGM — the classic free tool for CGM ↔ clear-text conversion (older but works)</li>
    <li>Most CGM authoring tools (IsoDraw, CorelDraw) can re-export as clear text or SVG</li>
  </ul>

  <h3>JT (Jupiter Tessellation)</h3>
  <p>Siemens' 3D viewing format. It's an open ISO standard (14306) but the binary uses several proprietary compression schemes, and no actively maintained pure-JS parser exists.</p>
  <ul>
    <li><a href="https://www.sw.siemens.com/en-US/technology/jt-open/" target="_blank" rel="noopener">JT2Go</a> — Siemens' free reference viewer; can export images</li>
    <li>FreeCAD — experimental JT import via plugin</li>
    <li>NX, Solid Edge, Catia, SolidWorks (via plugin) — full JT support, can re-export to STEP, STL, or OBJ</li>
  </ul>
</section>

<section id="privacy">
  <h2><span class="num">11</span>Privacy</h2>

  <p>The viewer is designed so files never leave your machine. Specifically:</p>
  <ul>
    <li>Files are read via the browser's <code>File API</code> directly from the local filesystem. There's no upload step.</li>
    <li>Parsing happens client-side in the JavaScript that's already loaded.</li>
    <li>The only network traffic is for <strong>static dependencies</strong>:
      <ul>
        <li><code>dxf-parser</code> from esm.sh</li>
        <li><code>three.js</code> + <code>OrbitControls</code> + <code>TDSLoader</code> from esm.sh</li>
        <li><code>occt-import-js</code> WASM from jsdelivr (only fetched on first STEP/IGES file)</li>
      </ul>
      These are static library files served from public CDNs — they don't see your file content.
    </li>
    <li>Nothing persists across sessions. Refreshing the page wipes everything.</li>
    <li>Closing the page releases all in-memory data.</li>
  </ul>

  <p>For an air-gapped environment, you'd want a self-hosted version with the dependencies bundled — but for normal use, the CDN model is fine.</p>
</section>

<section id="troubleshooting">
  <h2><span class="num">12</span>Troubleshooting</h2>

  <h3>"Could not parse this file"</h3>
  <p>The file might be corrupt, truncated, or use a format variant the parser doesn't handle. Try opening it in another tool to verify it's valid. For DXF specifically, very old (pre-AC1009) or very new format versions may use entities the parser doesn't recognize.</p>

  <h3>STEP/IGES file load is hanging</h3>
  <p>First load downloads 10MB of WASM — with a slow connection this can take a minute or more, with no progress indicator. Subsequent files use the cached parser and are fast. If it's stuck for several minutes, refresh and retry — the failed download might be cached.</p>
  <p>For very large assemblies (thousands of parts), the parse itself can also take 30+ seconds. The "Parsing STEP file…" message stays visible until done.</p>

  <h3>3D vertex snap picks the wrong point</h3>
  <p>The snap fires when a triangle vertex is within 15 screen pixels of the click. On a heavily subdivided mesh, the closest vertex might not be the geometric feature you wanted (e.g. it could land on a midface vertex of a curve approximation rather than the curve endpoint). Fix: zoom in further before clicking — the screen-pixel tolerance gets smaller in world units as you zoom, so the snap becomes more selective.</p>

  <h3>Wireframe shows too many or too few lines</h3>
  <p>The crease threshold is fixed at 20°. Sharp creases (cube edges, etc.) appear as edges; smoother surfaces don't. There's no UI to adjust this currently — it's a tradeoff that works for most CAD-style models.</p>

  <h3>Measurement labels are clipped or unreadable</h3>
  <p>2D dimension labels rotate with their line and have a small surface-color back-plate. If a label crosses the ruler bars, the ruler overdraws it (intentional — ruler readability wins). Pan slightly to bring the label out from under the ruler.</p>
  <p>3D labels are sprites that always face the camera. They're sized to ~28 screen pixels regardless of camera distance. If they overlap each other, that's a known limitation — there's no automatic anti-collision logic.</p>

  <h3>Drawing renders but everything's the same color</h3>
  <p>For DXF, this happens when the file uses BYLAYER colors but doesn't define layer colors (a malformed file). The viewer falls back to the foreground (text) color for everything. To fix: open in AutoCAD or LibreCAD, set explicit colors, re-save.</p>
</section>

<section id="keyboard">
  <h2><span class="num">13</span>Keyboard reference</h2>

  <table>
    <thead>
      <tr><th>Key</th><th>Action</th><th>Context</th></tr>
    </thead>
    <tbody>
      <tr><td><kbd>Esc</kbd></td><td>Cancel in-progress measurement pick</td><td>While picking</td></tr>
      <tr><td><kbd>Esc</kbd> (again)</td><td>Exit measure mode</td><td>After cancelling, or when no pick is pending</td></tr>
    </tbody>
  </table>

  <p>The viewer is intentionally mouse/touch-driven. Most actions are single-button-click affairs that don't benefit from keyboard shortcuts.</p>
</section>

<section id="glossary">
  <h2><span class="num">14</span>Glossary</h2>

  <dl style="margin: 1rem 0;">
    <dt style="font-weight: 500; margin-top: 1rem;">ACI (AutoCAD Color Index)</dt>
    <dd style="margin-left: 0; color: var(--muted);">A 256-entry color palette used in DXF/DWG files. Indices 1–9 are canonical primaries (red, yellow, green, cyan, blue, magenta, white/black, gray, light gray); 10–249 follow a hue/saturation pattern; 250–255 are grayscale.</dd>

    <dt style="font-weight: 500; margin-top: 1rem;">B-Rep (Boundary Representation)</dt>
    <dd style="margin-left: 0; color: var(--muted);">A solid-modeling representation that stores surfaces, edges, and their topological connections — as opposed to tessellated triangle data. STEP, IGES, and the source data inside CAD applications use B-Rep. Rendering requires a kernel that can evaluate the parametric surfaces into triangles.</dd>

    <dt style="font-weight: 500; margin-top: 1rem;">CGM (Computer Graphics Metafile)</dt>
    <dd style="margin-left: 0; color: var(--muted);">An ISO/IEC 8632 vector graphics format with three encodings: clear text (human-readable), character (rare), and binary. Widely used in technical illustration pipelines, especially in aerospace/defense documentation.</dd>

    <dt style="font-weight: 500; margin-top: 1rem;">EdgesGeometry</dt>
    <dd style="margin-left: 0; color: var(--muted);">A Three.js helper that extracts feature edges from a mesh by comparing the angle between adjacent triangle face normals. Edges where the angle exceeds a threshold (20° in this viewer) are kept; smoother transitions are dropped. Used for clean wireframe rendering.</dd>

    <dt style="font-weight: 500; margin-top: 1rem;">NDC (Normalized Device Coordinates)</dt>
    <dd style="margin-left: 0; color: var(--muted);">A coordinate system where the visible viewport spans <code>−1</code> to <code>+1</code> in both X and Y. Used internally by the 3D pick logic — the cursor's screen position is converted to NDC before raycasting.</dd>

    <dt style="font-weight: 500; margin-top: 1rem;">VDC (Virtual Device Coordinates)</dt>
    <dd style="margin-left: 0; color: var(--muted);">The coordinate space used inside CGM files. The <code>VDCEXT</code> element declares the coordinate range for a picture; everything else is drawn within that space.</dd>

    <dt style="font-weight: 500; margin-top: 1rem;">WASM (WebAssembly)</dt>
    <dd style="margin-left: 0; color: var(--muted);">A binary instruction format that runs in browsers at near-native speed. The viewer uses WASM (via <code>occt-import-js</code>) for STEP/IGES parsing because pure-JavaScript implementations of OpenCASCADE-grade geometry kernels aren't realistic.</dd>
  </dl>
</section>

</main>

</div>

<footer class="foot">
    <div>CAD viewer · Training guide</div>
    <div>Runs locally · No telemetry</div>
</footer>

<script>
    const links = document.querySelectorAll('nav.toc a');
    const sections = Array.from(links).map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.id;
                links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });
    sections.forEach(s => observer.observe(s));
</script>

<?php include 'includes/footer.php'; ?>
