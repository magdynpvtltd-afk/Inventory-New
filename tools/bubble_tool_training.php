<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Bubble · Operator Manual';
$current_page  = 'bubble_tool_training.php';
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






</style>
</head>
<body>


<div class="layout">

<aside class="sidebar">
        
    <div class="brand">
        <div class="brand-mark"><div style="width:24px;height:24px;border-radius:50%;background:var(--primary);box-shadow:inset 0 0 0 3px var(--sidebar-bg);"></div></div>
        <div class="brand-text">
            <div class="brand-title">Bubble · Manual</div>
            <div class="brand-sub">Operator manual · v1.3</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
    <li><a href="#overview">Overview</a></li>
    <li><a href="#interface">Interface Tour</a></li>
    <li><a href="#first-bubble">Your First Bubble</a></li>
    <li><a href="#auto-bubble">Auto Bubble</a></li>
    <li><a href="#dimensions">Dimension Details</a></li>
    <li><a href="#gdt">GD&amp;T Callouts</a></li>
    <li><a href="#text">Text Annotations</a></li>
    <li><a href="#redaction">Redaction Tool</a></li>
    <li><a href="#pages">Page Manager</a></li>
    <li><a href="#multipage">Multi-Page PDFs</a></li>
    <li><a href="#exports">Exports</a></li>
    <li><a href="#workflows">Common Workflows</a></li>
    <li><a href="#shortcuts">Keyboard Shortcuts</a></li>
    <li><a href="#faq">Troubleshooting</a></li>
  </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Engineering drawing annotator</div>
    <h1>Bubble drawings, build inspection lists, do <strong>real work</strong>.</h1>
    <p class="lede">
        Bubble is a browser-based balloon-callout tool for engineering drawings.
        Upload a print, click to drop numbered bubbles, fill in dimensions and
        tolerances, then export an annotated PDF and a parts/dimension list ready
        for first article inspection. No server uploads — every drawing stays on
        your machine.
    </p>
    <div class="cta-row">
        <a class="cta primary" href="bubble_tool.html">Open the tool →</a>
        <a class="cta secondary" href="#overview">Skip to overview</a>
    </div>
</div>

<!-- ============ OVERVIEW ============ -->
<section id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    BUBBLE.IO is built for the kind of work that happens between a finished CAD drawing
    and a shop floor: bubbling a print for FAI, marking up a vendor proof, redacting a
    superseded note before re-issue, or compiling a BOM-by-callout. It runs entirely in
    your browser — no installer, no account, nothing leaves your machine.
  </p>

  <h3>What it can do</h3>
  <table>
    <thead>
      <tr><th style="width:32%">CAPABILITY</th><th>NOTES</th></tr>
    </thead>
    <tbody>
      <tr><td>Source files</td><td class="dim">PNG · JPG · SVG · PDF (multi-page). CGM is detected and routed to a conversion guide.</td></tr>
      <tr><td>Bubble shapes</td><td class="dim">Circle, hexagon, square, diamond — sized 14–48 px, any fill / text / stroke color.</td></tr>
      <tr><td>Dimension data</td><td class="dim">Linear, diameter, radius, angle, reference, GD&amp;T. Tolerance band, units, critical flag, inspection notes.</td></tr>
      <tr><td>Text annotations</td><td class="dim">Click to drop a typed note, multi-line, sized 8–72pt, any color. Movable, editable, vector-preserved in PDF exports.</td></tr>
      <tr><td>Redaction</td><td class="dim">Visual white-out or black-bar coverage. Drag to draw, or Shift+click an image in a PDF to snap to its bounding box.</td></tr>
      <tr><td>Page manager</td><td class="dim">Reorder, delete, duplicate, and rotate individual pages of multi-page PDFs. Drag-and-drop with thumbnails.</td></tr>
      <tr><td>View controls</td><td class="dim">Zoom 10–500%, rotate ±180° (any angle), wheel-zoom, fit-to-screen.</td></tr>
      <tr><td>Exports</td><td class="dim">PNG of current view · CSV of full BOM · PDF (vector-preserving when source was a PDF; honors page-manager edits).</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">PRIVACY</div>
    <p>BUBBLE.IO never uploads your files. Source PDFs, images, and annotations live only in your browser session. Closing the tab clears everything. Save your exports if you want them.</p>
  </div>
</section>

<!-- ============ INTERFACE ============ -->
<section id="interface">
  <h2><span class="num">02</span> Interface Tour</h2>

  <p>
    The screen is split into four zones. Spend a minute identifying each before
    you start clicking — every later instruction in this manual refers to one
    of these areas.
  </p>

  <div class="callout note">
    <div class="label">FIGURE 2.1 — Interface zones</div>
    <p>Open the tool in a separate tab to follow along. The interface has five zones: <strong>A</strong> the toolbar (left side), <strong>B</strong> the drawing stage (centre), <strong>C</strong> the parts list (right), <strong>D</strong> the page navigator (bottom of the stage when a multi-page PDF is loaded), and <strong>E</strong> the status strip (above the stage, showing tool / zoom / rotation / bubble count).</p>
  </div>


  <table>
    <thead>
      <tr><th style="width:14%">ZONE</th><th style="width:24%">NAME</th><th>WHAT YOU DO HERE</th></tr>
    </thead>
    <tbody>
      <tr><td class="mono"><strong>A</strong></td><td>Toolbar</td><td class="dim">Upload your drawing. Pick the active tool (Bubble, Redact, Text, Pan, Delete). Choose bubble shape, size, color, view controls, and trigger <strong>Auto Bubble</strong> (the one-click scan that bubbles every dimension on the drawing — see §04).</td></tr>
      <tr><td class="mono"><strong>B</strong></td><td>Stage</td><td class="dim">The drawing canvas. Click to drop bubbles when the Bubble tool is active. Drag to redact. Wheel to zoom. Drag to pan when the Pan tool is active.</td></tr>
      <tr><td class="mono"><strong>C</strong></td><td>Parts list</td><td class="dim">Live list of bubbles on the current page. Each row carries description, dimension fields, and a <kbd>▸</kbd> chevron that expands the full dimension editor.</td></tr>
      <tr><td class="mono"><strong>D</strong></td><td>Page navigator</td><td class="dim">Appears only when a multi-page PDF is loaded. Next/previous page buttons plus a page-number indicator; the Page Manager modal launches from here too.</td></tr>
      <tr><td class="mono"><strong>E</strong></td><td>Status strip</td><td class="dim">Live counters: bubbles, redactions, current tool, zoom, rotation. The READY indicator pulses during long exports and during Auto Bubble runs.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ FIRST BUBBLE ============ -->
<section id="first-bubble">
  <h2><span class="num">03</span> Your First Bubble</h2>

  <p>
    Five steps from a blank page to a usable annotation. Everything else
    in this manual builds on this loop.
  </p>

  <div class="steps">

    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Upload a drawing.</strong></p>
        <p class="sub">
          Click the upload zone in the top-left, or drag a file onto it.
          PNG, JPG, SVG, and PDF all work. PDFs open with a page navigator.
        </p>
      </div>
    </div>

    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Make sure the Bubble tool is active.</strong></p>
        <p class="sub">
          The Bubble button in the toolbar should be highlighted in amber.
          If not, click it — or press <kbd>B</kbd>.
        </p>
      </div>
    </div>

    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click the feature you want to call out.</strong></p>
        <p class="sub">
          A numbered bubble appears with a leader line angled up-and-right
          from the click point. The number auto-increments — you can override
          it via the "Next Number" field in the toolbar.
        </p>
      </div>
    </div>

    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Position it.</strong></p>
        <p class="sub">
          Drag the bubble body to move the callout. Drag the small dot at
          the leader-tip to point at the exact feature. The leader line
          rubber-bands as you drag.
        </p>
      </div>
    </div>

    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the description in the right panel.</strong></p>
        <p class="sub">
          The bubble's row in the Parts List sidebar accepts free text — typically
          the part number or a short feature description. For dimensional data,
          expand the row with the <kbd>▸</kbd> chevron (next section).
        </p>
      </div>
    </div>
  </div>

  <div class="callout">
    <div class="label">TIP</div>
    <p>Double-click any bubble on the drawing to rename its number. Useful when you've inserted a callout out of order and want to re-letter (e.g., changing "5" to "5A").</p>
  </div>
</section>

<!-- ============ AUTO BUBBLE ============ -->
<section id="auto-bubble">
  <h2><span class="num">04</span> Auto Bubble</h2>

  <p>
    Manual bubbling works fine for a 12-dimension print, but a typical FAI
    drawing has 60–150 toleranced features. Clicking each one and typing
    each value is the slowest part of inspection prep. Auto Bubble scans
    the drawing, finds every dimension and tolerance, places a bubble on
    each, and fills in the parsed values automatically. You review, fix
    the handful it got wrong, and you're done.
  </p>

  <h3>When to use it</h3>
  <p>
    Auto Bubble works best on born-digital PDFs — drawings exported
    directly from CAD with a clean text layer. For those, extraction is
    near-instant and parser accuracy is high. It also works on scanned
    drawings, but slower (OCR is the bottleneck) and with more parsing
    errors to fix afterward. As a rule of thumb:
  </p>
  <table>
    <thead><tr><th style="width:32%">SOURCE</th><th>EXPECTED RESULT</th></tr></thead>
    <tbody>
      <tr><td>CAD-exported PDF</td><td class="dim">Most dimensions captured cleanly. Title-block area skipped. Manual cleanup typically &lt; 10% of bubbles.</td></tr>
      <tr><td>Scanned engineering drawing</td><td class="dim">OCR runs per page; expect 30–80 seconds per page. Parsing catches most callouts but symbol-only dimensions (⌖, Ø, etc.) sometimes mis-read.</td></tr>
      <tr><td>Hybrid (CAD text + raster stamps)</td><td class="dim">Both engines run in parallel. Each bubble carries the PDF.js reading and the OCR reading; you can swap per-bubble if one engine got it wrong.</td></tr>
      <tr><td>Pure raster (PNG, JPG)</td><td class="dim">Treated as scanned. Same expectations as scanned PDFs.</td></tr>
    </tbody>
  </table>

  <h3>Running Auto Bubble</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Upload your drawing</strong> and open the Auto Bubble button in the toolbar.</p>
        <p class="sub">The dialog opens with the detection options below. Defaults are tuned for FAI prep — leave them as-is unless you have a reason to change.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick the scope.</strong> Current page only, or all pages in the document.</p>
        <p class="sub">All pages is the right choice for multi-page packages where every sheet has callouts. Current page is useful when you've already bubbled the others and just need to fill in one more.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Review the options.</strong> Each toggle changes what gets detected — they matter.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "Detect dimensions"</strong> and watch the progress bar.</p>
        <p class="sub">For CAD PDFs the bar usually completes in 2–4 seconds. With OCR enabled, each page adds 30–90 seconds depending on density and your machine.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Review the proposed bubbles in the preview pane.</strong></p>
        <p class="sub">A summary card shows how many candidates were found, how many couldn't be parsed, and which engine produced them. The preview lists every proposed bubble with its parsed text and confidence level.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Accept with "Keep bubbles"</strong>, or back out with "Discard, undo".</p>
        <p class="sub">Discard removes everything Auto Bubble placed — your manual bubbles are untouched either way (Auto Bubble never replaces existing manual annotations).</p>
      </div>
    </div>
  </div>

  <h3>The detection options, in detail</h3>
  <table>
    <thead><tr><th style="width:36%">OPTION</th><th>WHAT IT DOES</th></tr></thead>
    <tbody>
      <tr><td>Run OCR in parallel with PDF.js</td><td class="dim">When checked, both engines run on every page and results are merged. Catches raster-baked annotations the text layer misses. When two engines see the same text, the OCR reading is kept as an alternate the operator can swap to per-bubble (see <em>Dual-engine extraction</em> below). Slower than text-layer-only — expect 30-90s per page added.</td></tr>
      <tr><td>Skip title-block region</td><td class="dim">Excludes the bottom-right ~25% of each page from detection. Title blocks are noisy (revision tables, drawing numbers, sheet refs) and rarely contain dimensions you want as bubbles. Turn off only if your drawings have actual callouts in the title-block area.</td></tr>
      <tr><td>Also bubble dual-unit [bracketed] values</td><td class="dim">For drawings that show <code>25.40 [1.000]</code> with the equivalent in inches/mm in brackets, this creates a secondary bubble for the bracketed value, numbered with a <code>.a</code> suffix (e.g. 5.a). Useful for FAI reports that need to track both units.</td></tr>
      <tr><td>Treat numbered NOTES list as separate bubbles</td><td class="dim">Some drawings carry a numbered notes block ("1. ALL DIMS IN MM", "2. BREAK ALL EDGES…"). Enable to bubble each numbered note as its own row in the parts list. Off by default — most FAI workflows don't need notes as bubbles.</td></tr>
      <tr><td>Clockwise sweep numbering</td><td class="dim">Bubbles are numbered clockwise from the 12 o'clock position around the page centre, rather than in detection order. The result is much friendlier for inspectors — bubble #1 starts at top, #2 is to its right, and the numbers walk around the part predictably. Off-by-default for raw detection order, but on-by-default for new runs because the clockwise order reads better in 99% of cases.</td></tr>
      <tr><td>Show A–D / 1–N rulers</td><td class="dim">Overlays a grid on the drawing during detection and captures which grid cell each bubble lives in (e.g., "B3"). The CSV export then includes a Grid column so inspectors can find each callout faster. Pick grid density via the rows × cols dropdown — 4×4 is fine for most drawings; bump to 8×8 only for very dense layouts.</td></tr>
    </tbody>
  </table>

  <h3>Dual-engine extraction</h3>
  <p>
    When the OCR option is enabled, every page is processed by BOTH
    PDF.js (text-layer extraction) and Tesseract (OCR on the rasterized
    page). The results are merged: detections that both engines saw
    become one bubble carrying both readings, detections only one engine
    saw pass through as that engine's reading.
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li><strong>PDF.js wins on overlap by default.</strong> Its character extraction is exact — for clean text-layer pages it knows the original characters with no recognition guesswork. OCR can confuse <code>Ø</code> with <code>0</code>, <code>5</code> with <code>S</code>, ligatures with one-or-another character.</li>
    <li><strong>OCR rides along as an alternate reading.</strong> When the two engines see the same text but disagree on what it says, the OCR reading is recorded as the bubble's alternate, accessible via a swap button in the parts editor.</li>
    <li><strong>OCR-only detections pass through directly.</strong> Stamps, hand-drawn callouts, embedded raster regions that the text layer never had — these become bubbles with OCR as their primary source.</li>
    <li><strong>PDF.js-only detections also pass through.</strong> Small text the OCR misses (sometimes anything &lt; 8pt) gets bubbled with the text-layer reading as primary, no alternate.</li>
  </ul>

  <h3>The swap-to-alternate button</h3>
  <p>
    For any bubble where Auto Bubble has an alternate reading, the
    parts editor shows a small <kbd>↔ OCR</kbd> button next to the
    raw-text field. Hovering shows the alternate value as a tooltip.
    Clicking swaps primary and alternate, and re-parses the new primary
    so the structured fields (type, nominal, tolerance, unit) update
    to match. Click again to swap back.
  </p>
  <div class="callout note">
    <div class="label">WHEN THE SWAP HELPS</div>
    <p>The two engines disagree most often on dimension callouts with diameter symbols (Ø6.35 vs Ø6.35 vs 06.35 vs Ø6,35 — OCR sometimes reads the Ø as a 0, sometimes drops it entirely, sometimes confuses a comma decimal separator). If a bubble's structured value looks wrong, hit the swap button — there's a 50/50 chance the other engine got it right.</p>
  </div>

  <h3>Undoing a run</h3>
  <p>
    The "Discard, undo" button in the result panel removes every
    bubble Auto Bubble just placed. Your pre-existing manual bubbles
    are not affected — Auto Bubble runs only ever ADD bubbles, never
    edit or delete what was already there. After you've clicked "Keep
    bubbles", individual bubbles can still be deleted manually
    (select + <kbd>Del</kbd>) but there's no single "undo the run"
    button anymore; that fork happens at accept time.
  </p>

  <h3>Common failure modes</h3>
  <table>
    <thead><tr><th style="width:36%">SYMPTOM</th><th>WHY / WHAT TO DO</th></tr></thead>
    <tbody>
      <tr><td>Auto Bubble finds dimensions, but their nominal values are wrong</td><td class="dim">Usually a parsing issue — the parser saw the right text but couldn't unscramble it. Open the bubble's detail editor; the raw text field shows what was actually detected. Edit the structured fields by hand. If a clear pattern shows (e.g. all diameters reading as "0" instead of "Ø"), enable the OCR option and re-run; OCR sometimes catches characters the text layer encoded oddly.</td></tr>
      <tr><td>Dimensions on rotated views (90° in the title block) aren't detected</td><td class="dim">PDF.js's text layer reads orientations correctly, but OCR struggles with rotated text. If a section is rotated and OCR is your only signal there, manually rotate the page (use the Page Manager's per-page rotation) so the text reads upright, then re-run Auto Bubble.</td></tr>
      <tr><td>Title-block dimensions get bubbled when I didn't want them</td><td class="dim">Either the "Skip title-block region" option is off, or your title block is in a non-standard position (top, left, or mid-page rather than bottom-right). The skip region is the bottom-right ~25% of each page only. Either turn off auto-skip and manually delete the title-block bubbles after, or just delete them post-detection — usually faster than re-running.</td></tr>
      <tr><td>Some bubbles are placed far away from the feature they bubble</td><td class="dim">The placement algorithm picks the nearest open space; on dense drawings it sometimes overshoots. Drag the bubble's leader-tip (small dot at the end of the leader line) to retarget. The leader line rubber-bands as you drag.</td></tr>
      <tr><td>Auto Bubble found zero candidates on a CAD PDF</td><td class="dim">Some CAD packages flatten text into vector paths during PDF export, killing the text layer. PDF.js then has nothing to read. Enable the OCR option and re-run — Tesseract works on the rasterized page and doesn't care that the original was vector.</td></tr>
      <tr><td>Multi-column tables get bubbled wrong</td><td class="dim">Both engines read text in a single linear order; dense multi-column tables (revision histories, BOM tables, dimension tables in border) often break this assumption. The "Skip title-block region" option excludes one common offender. For others, accept the run, delete the wrong rows manually, or turn off Auto Bubble entirely for that section.</td></tr>
    </tbody>
  </table>

  <div class="callout warn">
    <div class="label">PRIVACY</div>
    <p>Auto Bubble runs entirely in your browser. PDF.js reads from the in-memory PDF document. Tesseract OCR runs in a Web Worker on rasterized page images that never leave the tab. No drawing data, dimension text, or OCR output is uploaded anywhere.</p>
  </div>
</section>

<!-- ============ DIMENSIONS ============ -->
<section id="dimensions">
  <h2><span class="num">05</span> Dimension Details</h2>

  <p>
    Each bubble carries a structured set of inspection fields, modeled on the
    columns you'll find in a typical AS9102 first article inspection report.
    Click the <kbd>▸</kbd> chevron on any parts row to expand the editor.
  </p>

  <h3>The fields</h3>
  <table>
    <thead>
      <tr><th style="width:24%">FIELD</th><th>PURPOSE</th></tr>
    </thead>
    <tbody>
      <tr><td>Description</td><td class="dim">Free text. Part number, feature name, drawing zone, or finishing note.</td></tr>
      <tr><td>Type</td><td class="dim">Linear, Diameter (Ø), Radius (R), Angle (°), Reference (basic, parens), or GD&amp;T.</td></tr>
      <tr><td>Nominal value</td><td class="dim">The drawing value, e.g. <code>25.40</code>, <code>0.250</code>, <code>30</code>.</td></tr>
      <tr><td>Unit</td><td class="dim">mm / in / none. Auto-set to degrees when type = Angle.</td></tr>
      <tr><td>Tolerance ±</td><td class="dim">Upper and lower deviation. Use <kbd>SYM ±</kbd> to copy one to the other for symmetric tolerances.</td></tr>
      <tr><td>Inspection notes</td><td class="dim">Method, gauge, AQL, acceptance criteria — anything the inspector needs.</td></tr>
      <tr><td>Critical / KC</td><td class="dim">Flag for key-characteristic / critical-to-function dimensions. See callout below.</td></tr>
    </tbody>
  </table>

  <h3>Reading the formatted output</h3>

  <p>
    The summary line under each row shows the dimension as it will appear in
    your CSV and PDF exports. Examples:
  </p>

  <div class="terminal">
    <span class="prompt">Linear, symmetric tolerance:</span>
    <span class="out">25.40 ±0.05 mm</span>
    <span class="prompt">Diameter, asymmetric tolerance:</span>
    <span class="out">Ø10.000 +0.018/-0.000 mm</span>
    <span class="prompt">Angle:</span>
    <span class="out">30° ±1°</span>
    <span class="prompt">Reference (basic):</span>
    <span class="out">(50.00) mm</span>
    <span class="prompt">GD&amp;T position:</span>
    <span class="out">⌖ 0.05 A|B|C</span>
  </div>

  <h3>Critical / Key Characteristic</h3>

  <p>
    Tick the <strong>CRITICAL / KEY CHARACTERISTIC</strong> checkbox in the
    detail editor for any dimension that's safety-critical, regulatory, or
    flagged by the customer drawing as key. Three things change:
  </p>

  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>A red dot appears on the bubble's number tile in the parts panel</li>
    <li>A dashed red ring is drawn around the bubble on the drawing itself</li>
    <li>The PDF parts list highlights the row with a soft pink background</li>
  </ul>

  <div class="callout warn">
    <div class="label">CONVENTION</div>
    <p>The dashed-red-ring convention follows ASME Y14.5 and AS9100 practice for "key characteristic" callouts. If your customer uses a different convention (e.g. a triangle flag for safety-critical), set up a fill color or shape in the toolbar that everyone on your team agrees on.</p>
  </div>
</section>

<!-- ============ GD&T ============ -->
<section id="gdt">
  <h2><span class="num">06</span> GD&amp;T Callouts</h2>

  <p>
    For geometric dimensioning &amp; tolerancing, switch the Type field to
    <strong>GD&amp;T (Geometric)</strong>. The standard tolerance fields
    (Nominal, Unit, ±) hide and a 14-symbol palette appears.
  </p>

  <h3>Symbol palette</h3>

  <div class="gdt-table">
    <div class="gdt-cell"><span class="sym">⌖</span><span class="name">Position</span></div>
    <div class="gdt-cell"><span class="sym">○</span><span class="name">Circularity</span></div>
    <div class="gdt-cell"><span class="sym">⌭</span><span class="name">Cylindricity</span></div>
    <div class="gdt-cell"><span class="sym">⏥</span><span class="name">Flatness</span></div>
    <div class="gdt-cell"><span class="sym">⏤</span><span class="name">Straightness</span></div>
    <div class="gdt-cell"><span class="sym">⌒</span><span class="name">Profile (line)</span></div>
    <div class="gdt-cell"><span class="sym">⌓</span><span class="name">Profile (surface)</span></div>
    <div class="gdt-cell"><span class="sym">∥</span><span class="name">Parallelism</span></div>
    <div class="gdt-cell"><span class="sym">⊥</span><span class="name">Perpendicularity</span></div>
    <div class="gdt-cell"><span class="sym">∠</span><span class="name">Angularity</span></div>
    <div class="gdt-cell"><span class="sym">⌯</span><span class="name">Symmetry</span></div>
    <div class="gdt-cell"><span class="sym">◎</span><span class="name">Concentricity</span></div>
    <div class="gdt-cell"><span class="sym">↗</span><span class="name">Runout</span></div>
    <div class="gdt-cell"><span class="sym">⌰</span><span class="name">Total runout</span></div>
  </div>

  <h3>Filling in the feature control frame</h3>

  <p>
    A typical GD&amp;T feature control frame in BUBBLE.IO needs three pieces:
  </p>

  <table>
    <thead>
      <tr><th style="width:30%">FIELD</th><th>EXAMPLE</th><th>NOTES</th></tr>
    </thead>
    <tbody>
      <tr><td>Symbol</td><td class="mono">⌖</td><td class="dim">Click the symbol in the palette. Click again to deselect.</td></tr>
      <tr><td>Tolerance value</td><td class="mono">0.05 Ⓜ</td><td class="dim">Numeric tolerance, with optional material modifier characters.</td></tr>
      <tr><td>Datum references</td><td class="mono">A|B|C</td><td class="dim">Pipe-separated for clarity. The pipes export verbatim to the PDF/CSV.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">NOTE</div>
    <p>Material modifiers (Ⓜ Maximum Material Condition, Ⓛ Least Material Condition, Ⓟ Projected) are entered as Unicode characters in the tolerance value field. Copy from the symbol palette in your CAD package or use the codes Ⓜ (U+24C2), Ⓛ (U+24C1), Ⓟ (U+24C5).</p>
  </div>
</section>

<!-- ============ TEXT ANNOTATIONS ============ -->
<section id="text">
  <h2><span class="num">07</span> Text Annotations</h2>

  <p>
    Text annotations are free-form typed notes you can drop anywhere on the
    drawing — process notes, review comments, "verify with vendor" callouts.
    Unlike bubbles, they don't carry inspection data and don't appear in the
    parts list. They sit on top of the drawing as a layer of comments.
  </p>

  <h3>Adding text</h3>

  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick the Text tool</strong> from the toolbar, or press <kbd>T</kbd>.</p>
        <p class="sub">The cursor changes to a text-insertion caret to confirm you're in text mode.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click anywhere on the drawing.</strong> A floating editor opens at the click point.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Type your note.</strong> Multi-line is supported — press <kbd>Enter</kbd> for a new line.</p>
        <p class="sub">As you type, the text renders live on the drawing. Adjust font size (8–72 pt) and color from the editor toolbar.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Commit with <kbd>DONE</kbd></strong> (or <kbd>Ctrl</kbd>+<kbd>Enter</kbd>), or cancel with <kbd>Esc</kbd>.</p>
        <p class="sub">An empty text annotation is removed automatically — no clutter from accidental clicks.</p>
      </div>
    </div>
  </div>

  <h3>Editing existing text</h3>

  <table>
    <thead>
      <tr><th style="width:32%">ACTION</th><th>HOW</th></tr>
    </thead>
    <tbody>
      <tr><td>Edit the content</td><td class="dim">Double-click any text annotation. The editor reopens with the current content.</td></tr>
      <tr><td>Move it</td><td class="dim">Click and drag from anywhere on the text. The annotation follows your cursor.</td></tr>
      <tr><td>Delete it</td><td class="dim">Click to select, then press <kbd>Del</kbd> or <kbd>Bksp</kbd>. Or use the Delete tool and click the text.</td></tr>
      <tr><td>Change font / color</td><td class="dim">Double-click to reopen the editor — font size and color controls are in the toolbar.</td></tr>
    </tbody>
  </table>

  <div class="callout">
    <div class="label">VECTOR PRESERVATION</div>
    <p>In PDF exports from a PDF source, text annotations are written as <em>native PDF text</em> — selectable, searchable, and copyable in any reader. They're not rasterized images. This makes the tool useful for adding genuine review comments, not just visual decoration.</p>
  </div>

  <h3>Editing PDFs that already contain text</h3>

  <p>
    A common ask: "I want to change the words already in this PDF." That's a
    different problem from adding text on top — true text editing requires
    rewriting the PDF's content stream, which BUBBLE.IO does not support
    (very few browser-based tools do; the underlying problem is genuinely hard).
    The practical workaround is the same one most PDF editors use under the hood:
  </p>

  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:1.9;">
    <li>Use the Redact tool to white-out the existing text</li>
    <li>Use the Text tool to type the replacement on top, sized and styled to match</li>
  </ol>

  <p>
    The result reads cleanly in the export and works for almost every practical
    "edit existing text" scenario.
  </p>
</section>

<!-- ============ REDACTION ============ -->
<section id="redaction">
  <h2><span class="num">08</span> Redaction Tool</h2>

  <p>
    The Redact tool covers areas of the drawing with an opaque rectangle —
    useful for hiding stale dimensions, vendor names, or pre-release IP before
    sending a print to a third party.
  </p>

  <div class="callout warn">
    <div class="label">IMPORTANT — VISUAL ONLY</div>
    <p>Redaction in BUBBLE.IO is <strong>visual only</strong>. The covered text and image data still exists in the source file underneath the rectangle. For legal, regulatory, or IP-sensitive redaction where the underlying data must be physically removed, use Adobe Acrobat Pro's redaction tool — it rewrites the PDF content stream. BUBBLE.IO is appropriate for engineering review, not legal redaction.</p>
  </div>

  <h3>Using the tool</h3>

  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Select the Redact tool</strong> from the toolbar, or press <kbd>X</kbd>.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a style</strong> in the Redaction section: white-out (matches paper) or black bar (visible redaction).</p>
        <p class="sub">White-out is best for revising stale callouts quietly. Black bar signals intent and is the right choice when the reviewer should know something was redacted.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Drag a rectangle</strong> over what you want to hide.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Adjust if needed.</strong> Click a redaction to select it. Drag from inside to move; drag the corner handle to resize. Press <kbd>Del</kbd> to remove.</p>
      </div>
    </div>
  </div>

  <h3>Snap-to-image (PDF only)</h3>

  <p>
    For PDF sources, BUBBLE.IO can find the bounding box of any image embedded
    in the page and snap a redaction rectangle to it exactly. Useful for hiding
    a vendor logo, a sample photograph, or any other raster image that you want
    cleanly covered without manually dragging a precise rectangle.
  </p>

  <table>
    <thead>
      <tr><th style="width:32%">ACTION</th><th>HOW</th></tr>
    </thead>
    <tbody>
      <tr><td>Snap to image</td><td class="dim">In Redact mode, hold <kbd>Shift</kbd> and click anywhere inside an image. A redaction sized to the image's bounding box appears instantly.</td></tr>
      <tr><td>If no image is found</td><td class="dim">The Shift+click is silently ignored — drop a normal drag-rectangle instead.</td></tr>
      <tr><td>Vector content</td><td class="dim">Snap-to-image only works on raster images (JPEG/PNG XObjects). Pure vector drawings, lines, and gradients have no XObject to detect.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">HOW IT WORKS</div>
    <p>BUBBLE.IO walks the PDF's content stream, tracking the current transformation matrix through save/restore/transform operators, and captures the on-page bounding box of every image-painting operator. When you Shift+click, it picks the smallest image whose bbox contains your click point — so if you click an image inside an image-in-an-image, you get the most specific match.</p>
  </div>
</section>

<!-- ============ PAGE MANAGER ============ -->
<section id="pages">
  <h2><span class="num">09</span> Page Manager</h2>

  <p>
    The Page Manager lets you reorder, delete, duplicate, and rotate individual
    pages of a multi-page PDF before exporting. Open it with the <strong>Pages</strong>
    button in the top-right header. (PDF sources only — for image uploads the
    button shows an explanation.)
  </p>

  <h3>The interface</h3>

  <p>
    The Page Manager opens as a modal showing every page of the PDF as a
    thumbnail card. Each card shows the page number, the source page reference
    (e.g. <code>SRC P3</code>), and any per-page rotation. Below the thumbnail
    are four action buttons.
  </p>

  <table>
    <thead>
      <tr><th style="width:14%">BUTTON</th><th style="width:24%">ACTION</th><th>NOTES</th></tr>
    </thead>
    <tbody>
      <tr><td class="mono"><strong>↻</strong></td><td>Rotate clockwise 90°</td><td class="dim">Per-page rotation. Stacks with the global view rotation at export time.</td></tr>
      <tr><td class="mono"><strong>↺</strong></td><td>Rotate counter-clockwise 90°</td><td class="dim">Same, opposite direction. Click multiple times for 180°/270°.</td></tr>
      <tr><td class="mono"><strong>⊕</strong></td><td>Duplicate page</td><td class="dim">Inserts a copy of the page right after the original. Annotations on the source page appear on both copies.</td></tr>
      <tr><td class="mono"><strong>✕</strong> / <strong>↶</strong></td><td>Delete / Restore</td><td class="dim">Marks the page for exclusion from export. Click again to restore. Visual-only during the session — undeletes any time before you close.</td></tr>
    </tbody>
  </table>

  <h3>Reordering pages</h3>

  <p>
    Drag any thumbnail card and drop it on another page to move it. A vertical
    amber line appears on the drop target showing whether the page will land
    before or after — left-side line means "drop before," right-side means
    "drop after."
  </p>

  <h3>Resetting changes</h3>

  <p>
    The <strong>Reset to original</strong> button discards every page-manager
    edit you've made in the current session and returns the page list to its
    original order. Annotations (bubbles, redactions, text) are <em>not</em>
    affected — only the page-manager state.
  </p>

  <div class="callout">
    <div class="label">EXPORT BEHAVIOR</div>
    <p>The PDF export honors all page-manager edits: deleted pages drop out, duplicates appear in their new positions, rotations stack with the global view rotation. The exported PDF is a fresh document built by copying source pages into a new container — so deleted page content is genuinely absent from the output, even though it remains in the source file on disk.</p>
  </div>

  <div class="callout warn">
    <div class="label">ANNOTATIONS ON DUPLICATES</div>
    <p>Annotations are keyed by source page number, not by export position. If you duplicate page 3 and bubble it, then duplicate it again, all three copies of page 3 receive the same annotations on export. This is usually what you want (e.g., for a mirrored part with the same callouts) but worth knowing.</p>
  </div>
</section>

<!-- ============ MULTI-PAGE PDF ============ -->
<section id="multipage">
  <h2><span class="num">10</span> Multi-Page PDFs</h2>

  <p>
    When you upload a multi-page PDF, a page navigator appears at the bottom
    of the stage. Each page maintains its own independent set of bubbles,
    redactions, and text annotations.
  </p>

  <h3>Navigation</h3>
  <table>
    <thead><tr><th style="width:32%">ACTION</th><th>HOW</th></tr></thead>
    <tbody>
      <tr><td>Next page</td><td class="dim">Click <kbd>›</kbd>, or press <kbd>→</kbd> / <kbd>PgDn</kbd></td></tr>
      <tr><td>Previous page</td><td class="dim">Click <kbd>‹</kbd>, or press <kbd>←</kbd> / <kbd>PgUp</kbd></td></tr>
      <tr><td>Current page indicator</td><td class="dim">Center of page navigator. Sidebar header also shows <code>· PAGE N</code>.</td></tr>
    </tbody>
  </table>

  <h3>How numbering works across pages</h3>
  <p>
    Numbering is sequential across the whole document — page 1 might end at
    bubble #7, in which case page 2 starts at #8. This keeps your BOM as a
    single ordered list. The "Next Number" field always reflects the highest
    used number across all pages.
  </p>

  <div class="callout note">
    <div class="label">WHAT GETS EXPORTED</div>
    <p>The PDF export always includes <strong>every page</strong> from the source PDF, even pages with no bubbles or redactions. The CSV export includes a <code>Page</code> column showing where each bubble lives. The PNG export only captures the currently visible page.</p>
  </div>
</section>

<!-- ============ EXPORTS ============ -->
<section id="exports">
  <h2><span class="num">11</span> Exports</h2>

  <p>
    Three export buttons sit in the top-right header. Pick the right one
    based on what you're doing next.
  </p>

  <div class="workflows">
    <div class="workflow-card">
      <span class="tag">CSV</span>
      <h4>Export CSV</h4>
      <p>
        Spreadsheet of the parts list — every dimension field as a separate
        column plus a single formatted dimension column. Pivot in Excel or
        feed directly into your inspection software. Includes a Page column
        for multi-page PDFs.
      </p>
    </div>
    <div class="workflow-card">
      <span class="tag">PNG</span>
      <h4>Export PNG</h4>
      <p>
        Just the current view as a PNG image, rendered at the source's
        natural resolution with annotations and redactions baked in. Good
        for quick sharing in chat or pasting into a slide.
      </p>
    </div>
    <div class="workflow-card">
      <span class="tag">PDF</span>
      <h4>Export PDF</h4>
      <p>
        The full annotated document with a parts-list summary appended.
        Vector-preserving when the source was a PDF — text stays selectable
        and printable at any zoom.
      </p>
    </div>
  </div>

  <h3>About the vector-preserving PDF export</h3>
  <p>
    When your source was a PDF, BUBBLE.IO loads the original document with
    pdf-lib and overlays bubbles, redactions, and text annotations as
    <strong>native PDF objects</strong> rather than rasterizing the page. This
    preserves all the original vector text, lines, and CAD geometry — the
    result prints sharp at any size and remains searchable. Your typed text
    annotations also remain searchable in the output.
  </p>
  <p>
    When your source was an image, the PDF export rasterizes the canvas (since
    there's no vector source to preserve) and embeds it as a JPEG-compressed
    image at the source resolution.
  </p>

  <h3>Page-manager edits in the export</h3>
  <p>
    If you've used the Page Manager (§09) to reorder, delete, duplicate, or
    rotate pages, the vector PDF export honors all of it. The output is a
    fresh document built by copying source pages in your chosen order with
    your per-page rotations baked in. Deleted pages are absent from the
    output entirely. Annotations made on a source page carry through to all
    duplicates of that page.
  </p>

  <h3>About the parts list page</h3>
  <p>
    Whenever you export a PDF with at least one bubble, an A4 landscape parts-list
    page is appended. It uses native vector text (selectable and searchable), with
    columns for Item, Page (PDFs only), Number, Description, Type, Dimension,
    Critical flag, and Notes. Critical rows get a soft pink background. Long
    descriptions wrap and pages flow when the table overflows.
  </p>

  <div class="callout">
    <div class="label">ROTATION IN THE EXPORT</div>
    <p>
      Whatever rotation you've applied — both the global view rotation and any
      per-page rotations from the Page Manager — is baked into each exported
      PDF page via the <code>/Rotate</code> attribute. PDF readers honor this
      and display the page rotated. Bubble numbers and text annotations are
      counter-rotated within the page so they read upright in the rotated
      view, regardless of the rotation amount.
    </p>
  </div>
</section>

<!-- ============ WORKFLOWS ============ -->
<section id="workflows">
  <h2><span class="num">12</span> Common Workflows</h2>

  <h3>First Article Inspection (FAI / AS9102)</h3>
  <p>
    The canonical workflow this tool was built for. Goal: produce a numbered
    drawing and a Form 3 dimension list that an inspector can fill in with
    actuals.
  </p>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body"><p>Upload the customer drawing PDF.</p></div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Run Auto Bubble first.</strong> Click the Auto Bubble button, leave the defaults on (skip title-block, clockwise numbering, OCR-in-parallel), pick scope "All pages", and let it detect every toleranced feature in one pass. For a 60-dimension CAD print, this turns 30 minutes of clicking into ~10 seconds.</p>
        <p class="sub">If the drawing isn't from CAD (it's scanned or rasterized), expect OCR to take 30-90s per page. Keep the tab open and let it finish; the result is still faster than dropping every bubble by hand. Review the proposed bubbles in the preview pane and click "Keep bubbles" when satisfied.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p>Walk every Auto-Bubble-placed bubble and check the parsed values against the drawing. Use the <kbd>↔ OCR</kbd> swap button on any bubble where Auto Bubble's primary reading looks wrong — the alternate (from the other engine) is often correct. For any feature Auto Bubble missed entirely, drop a manual bubble — its number slots into the sequence automatically.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p>Expand each row, set the Type, fill in nominal &amp; tolerance from the drawing, and tick Critical for anything called out as KC or safety.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p>Export PDF (give it to the inspector along with the parts) <em>and</em> CSV (open in Excel, your inspection software, or paste into your AS9102 Form 3 template).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p>Inspector records actual measurements against each row. Pass/fail computed against the tolerance band; flagged criticals get extra scrutiny.</p>
      </div>
    </div>
  </div>

  <h3>BOM-by-Callout for Assembly Drawings</h3>
  <p>
    Assembly print with a parts list. Goal: a numbered exploded view paired
    with a clean BOM table.
  </p>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Upload the assembly drawing.</li>
    <li>Set bubble Shape to <strong>Hexagon</strong> (common for BOM callouts) and a fill color that contrasts with the print.</li>
    <li>Click each component in the exploded view to drop a bubble. Drag to position the callout in clear space.</li>
    <li>In the sidebar, fill in the description with the part number and quantity (e.g. <code>P/N 4521-A · QTY 4</code>).</li>
    <li>Skip the dimension fields entirely — for BOMs, the description column carries everything you need.</li>
    <li>Export PDF for the print package. The parts list page becomes your BOM table.</li>
  </ol>

  <h3>Vendor Drawing Review (Markup &amp; Return)</h3>
  <p>
    Vendor sent a proof. You need to redline it and return.
  </p>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Upload the vendor's PDF.</li>
    <li>Bubble each issue that needs a numbered reference. Use the description field as a short comment — "Wrong material — should be 6061-T6 not 6063".</li>
    <li>For longer prose comments that don't need to live in a parts list, use the <strong>Text tool</strong> (<kbd>T</kbd>) to drop typed notes directly on the drawing. Multi-line is fine.</li>
    <li>Optionally redact any internal vendor info that shouldn't be in your reply file. <kbd>Shift</kbd>+click images to snap-redact logos and photos; drag rectangles for ad-hoc coverage.</li>
    <li>Export PDF. The result preserves the vendor's original drawing in vectors, with your numbered comments, prose text annotations, and a summary list of all bubbled issues. Text annotations remain selectable and searchable in the output.</li>
    <li>Email it back as the redlined response.</li>
  </ol>

  <h3>Document Cleanup &amp; Reissue</h3>
  <p>
    You have a multi-page PDF that needs to be reorganized for re-issue —
    drop appendix pages, reorder sections, duplicate a sheet for a mirrored
    variant, hide stale data.
  </p>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Upload the source PDF.</li>
    <li>Open the <strong>Page Manager</strong> from the header. Drag pages to reorder; click <kbd>✕</kbd> on any page that should be dropped from the reissue.</li>
    <li>Click <kbd>⊕</kbd> on a sheet that needs to appear twice (e.g., for a left-hand and right-hand variant of the same part).</li>
    <li>Use per-page rotation buttons (<kbd>↻</kbd> / <kbd>↺</kbd>) to fix any sideways-scanned pages without touching others.</li>
    <li>Close the Page Manager. Annotate as needed — bubbles, text notes, redactions on superseded callouts.</li>
    <li>Export PDF. The output is a fresh document in your new order, with deleted pages absent, duplicated pages present in their new positions, and per-page rotations baked in.</li>
  </ol>
</section>

<!-- ============ SHORTCUTS ============ -->
<section id="shortcuts">
  <h2><span class="num">13</span> Keyboard Shortcuts</h2>

  <h4>TOOLS</h4>
  <div class="key-grid">
    <div class="row"><div class="keys"><kbd>B</kbd></div><div class="desc">Bubble tool</div></div>
    <div class="row"><div class="keys"><kbd>X</kbd></div><div class="desc">Redact tool</div></div>
    <div class="row"><div class="keys"><kbd>T</kbd></div><div class="desc">Text tool</div></div>
    <div class="row"><div class="keys"><kbd>H</kbd></div><div class="desc">Pan tool</div></div>
    <div class="row"><div class="keys"><kbd>Del</kbd> / <kbd>Bksp</kbd></div><div class="desc">Delete selected annotation</div></div>
    <div class="row"><div class="keys"><kbd>Esc</kbd></div><div class="desc">Deselect / close text editor</div></div>
  </div>

  <h4>TEXT EDITOR</h4>
  <div class="key-grid">
    <div class="row"><div class="keys"><kbd>Enter</kbd></div><div class="desc">New line within annotation</div></div>
    <div class="row"><div class="keys"><kbd>Ctrl</kbd>+<kbd>Enter</kbd></div><div class="desc">Commit and close editor</div></div>
    <div class="row"><div class="keys"><kbd>Esc</kbd></div><div class="desc">Cancel editing</div></div>
    <div class="row"><div class="keys"><kbd>Dbl-click</kbd></div><div class="desc">Edit existing text</div></div>
  </div>

  <h4>REDACTION</h4>
  <div class="key-grid">
    <div class="row"><div class="keys"><kbd>Drag</kbd></div><div class="desc">Draw a rectangle to redact</div></div>
    <div class="row"><div class="keys"><kbd>Shift</kbd>+<kbd>Click</kbd></div><div class="desc">Snap to image (PDF only)</div></div>
  </div>

  <h4>VIEW</h4>
  <div class="key-grid">
    <div class="row"><div class="keys"><kbd>+</kbd></div><div class="desc">Zoom in</div></div>
    <div class="row"><div class="keys"><kbd>-</kbd></div><div class="desc">Zoom out</div></div>
    <div class="row"><div class="keys"><kbd>0</kbd></div><div class="desc">100% zoom</div></div>
    <div class="row"><div class="keys"><kbd>F</kbd></div><div class="desc">Fit to screen</div></div>
    <div class="row"><div class="keys"><kbd>Wheel</kbd></div><div class="desc">Zoom in/out at cursor</div></div>
  </div>

  <h4>ROTATION</h4>
  <div class="key-grid">
    <div class="row"><div class="keys"><kbd>]</kbd></div><div class="desc">Rotate clockwise 90°</div></div>
    <div class="row"><div class="keys"><kbd>[</kbd></div><div class="desc">Rotate counter-clockwise 90°</div></div>
    <div class="row"><div class="keys"><kbd>R</kbd></div><div class="desc">Reset rotation to 0°</div></div>
  </div>

  <h4>PDF NAVIGATION</h4>
  <div class="key-grid">
    <div class="row"><div class="keys"><kbd>→</kbd> / <kbd>PgDn</kbd></div><div class="desc">Next page</div></div>
    <div class="row"><div class="keys"><kbd>←</kbd> / <kbd>PgUp</kbd></div><div class="desc">Previous page</div></div>
  </div>
</section>

<!-- ============ FAQ ============ -->
<section id="faq">
  <h2><span class="num">14</span> Troubleshooting</h2>

  <h3>I uploaded a CGM file and got a popup instead of a drawing</h3>
  <p>
    CGM (Computer Graphics Metafile) requires a commercial viewer license to
    render in browsers — there's no free renderer that handles the format
    reliably. The popup suggests conversion paths: AutoCAD's PLOT to PDF,
    CATIA's File &gt; Print &gt; PDF, IsoDraw's Export &gt; SVG, or Inkscape
    (which can open CGM and re-export as SVG/PDF). Convert and re-upload.
  </p>

  <h3>Can I edit text that was already in the PDF?</h3>
  <p>
    Not directly — that requires rewriting the PDF's content stream, which is
    a hard problem that BUBBLE.IO doesn't attempt. The practical workaround
    is the same one most PDF editors use under the hood: redact the existing
    text, then type your replacement on top with the Text tool. See §07 for
    the workflow.
  </p>

  <h3>Shift+click on an image isn't snapping to anything</h3>
  <p>
    Three possibilities: (1) the source isn't a PDF — image-snap only works
    for PDF sources since it relies on PDF.js's content-stream inspection;
    (2) the visual you clicked is actually a vector drawing, not a raster
    image XObject (this is common with technical illustrations that look
    photographic but are drawn as paths); (3) the image is masked, clipped,
    or otherwise transformed in a way the detector can't reconstruct.
    In all three cases, drag a manual rectangle instead.
  </p>

  <h3>My text annotation appears in the wrong place after I rotate the drawing</h3>
  <p>
    Text annotations rotate with the drawing (they're attached to drawing
    coordinates) but the glyphs themselves counter-rotate so they stay
    upright. If a text annotation looks misplaced after rotating, it's almost
    always because the click that created it landed at a slightly different
    spot than intended. Drag the annotation to the correct position — it
    follows your cursor through any rotation.
  </p>

  <h3>I duplicated a page in the Page Manager but only got one copy in the export</h3>
  <p>
    Make sure you closed the Page Manager modal with <kbd>Done</kbd> rather
    than reloading the page or opening a new file. Page-manager edits are
    session state — they persist while the tab is open but reset when you
    upload a new file or hit Clear. The duplicate appears in the order list
    immediately and the export honors it.
  </p>

  <h3>The PDF export is huge</h3>
  <p>
    Two reasons this can happen: (1) the source PDF was huge to start with —
    pdf-lib preserves the original, so big in equals big out, and duplicating
    pages in the Page Manager makes the output proportionally larger;
    (2) the source was an image at very high resolution, and the rasterized
    PDF embeds that full-resolution image. For (2), downsample your input
    image to ~3000 px long-edge before uploading.
  </p>

  <h3>Bubbles on a page I haven't visited look slightly off in the PDF</h3>
  <p>
    BUBBLE.IO stores bubble positions relative to the display dimensions used
    when you annotated each page. If you resize the browser window
    <em>between</em> annotating different pages, the relative positions of
    bubbles on previously-annotated pages may shift slightly when exporting.
    Avoid by keeping the window size stable while annotating a multi-page document.
  </p>

  <h3>I want to save my session and resume later</h3>
  <p>
    BUBBLE.IO doesn't currently persist its session to disk — bubbles, text
    annotations, redactions, and page-manager edits live only in your browser
    tab. To resume work later, re-upload the original source and rebuild.
    The CSV export is a useful intermediate save: it records every bubble's
    description, dimension data, and X/Y position, so a partially-completed
    review can be reconstructed by re-bubbling at the recorded coordinates.
  </p>
</section>

</main>

</div>

<footer class="foot">
    <div>Bubble · Operator Manual · v1.3</div>
    <div>Runs locally · No telemetry</div>
</footer>

<script>
    // Highlight active section in TOC as user scrolls
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
