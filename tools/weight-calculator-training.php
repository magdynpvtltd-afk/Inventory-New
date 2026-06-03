<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Engineering Toolbox · Training manual';
$current_page  = 'weight-calculator-training.php';
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



/* ============ Weight Calc training extras ============ */

/* Module sections — wrap each with a card-like surface for clear demarcation */
.module {
    margin-bottom: 56px;
    scroll-margin-top: 12px;
}
.module-end {
    margin: 40px 0;
    text-align: center;
    color: var(--text-light);
    font-size: 11px;
    letter-spacing: 0.4em;
}
.module-end::before { content: '— · — · —'; }

.section-mark {
    display: inline-block;
    background: var(--primary-light);
    color: var(--primary);
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    margin-right: 8px;
}
.of {
    color: var(--text-light);
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-right: 8px;
}
.module h2 .num {
    font-size: 12px;
    color: var(--primary);
    background: var(--primary-light);
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 700;
    letter-spacing: 0.04em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* UI mockup (small panels showing the live tool) */
.mockup {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px;
    margin: 14px 0 8px;
    box-shadow: var(--shadow);
}
.m-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 10px;
    margin: -14px -14px 12px;
    background: var(--surface-alt);
    border-bottom: 1px solid var(--border);
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    font-size: 10px;
    color: var(--text-muted);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-weight: 600;
}
.m-brand {
    color: var(--primary);
    font-weight: 700;
    letter-spacing: 0.06em;
}
.m-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 12px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 6px;
}
.m-tab {
    padding: 4px 9px;
    font-size: 10px;
    color: var(--text-muted);
    border-radius: 4px 4px 0 0;
    letter-spacing: 0.04em;
    font-weight: 600;
}
.m-tab.active {
    background: var(--primary);
    color: white;
}
.m-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
}
@media (max-width: 700px) { .m-grid { grid-template-columns: 1fr; } }
.m-panel {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px;
}
.m-label {
    font-size: 9px;
    color: var(--text-light);
    letter-spacing: 0.1em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    margin-bottom: 4px;
}
.m-title {
    font-size: 11px;
    color: var(--text);
    font-weight: 600;
    margin-bottom: 6px;
}
.m-readout {
    font-size: 24px;
    color: var(--primary);
    font-weight: 700;
    text-align: center;
    padding: 6px 0 2px;
    font-variant-numeric: tabular-nums;
}
.m-callout {
    background: var(--primary-light);
    color: var(--primary);
    border-left: 3px solid var(--primary);
    padding: 8px 12px;
    border-radius: var(--radius);
    font-size: 11.5px;
    margin-top: 8px;
}

.mockup-caption {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.55;
    font-style: italic;
    margin-bottom: 18px;
}
.mockup-caption .key {
    background: var(--primary);
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-style: normal;
    font-weight: 700;
    font-size: 10px;
    letter-spacing: 0.05em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    margin-right: 4px;
}

/* Formula / equation blocks */
.formula {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-left: 3px solid var(--primary);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin: 14px 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.8;
}
.equation {
    text-align: center;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 15px;
    padding: 14px 0;
    color: var(--text);
}

/* Exercises */
.exercise {
    background: var(--info-bg);
    border-left: 3px solid var(--info);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin: 14px 0;
    font-size: 13.5px;
    line-height: 1.65;
}
.exercise .label {
    font-size: 10px;
    color: var(--info);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 700;
    margin-bottom: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.answer {
    background: var(--success-bg);
    color: var(--success);
    border-left: 3px solid var(--success);
    border-radius: var(--radius);
    padding: 14px 18px;
    margin: 12px 0 18px;
    font-size: 13px;
}
.answer .label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 700;
    margin-bottom: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* Spec table */
.spec {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 8px 18px;
    margin: 14px 0;
    font-size: 13.5px;
}
.spec dt, .spec .label {
    font-weight: 600;
    color: var(--primary);
    padding: 6px 0;
}
.spec dd, .spec .value {
    padding: 6px 0;
    color: var(--text);
}

/* Glossary */
.gloss {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 8px 18px;
    margin: 18px 0;
    font-size: 13.5px;
}
.gloss dt {
    font-weight: 600;
    color: var(--primary);
    padding: 8px 0;
    border-top: 1px solid var(--border);
}
.gloss dt:first-of-type { border-top: none; }
.gloss dd {
    padding: 8px 0;
    line-height: 1.65;
    color: var(--text);
    border-top: 1px solid var(--border);
}
.gloss dd:first-of-type { border-top: none; }
@media (max-width: 700px) {
    .gloss { grid-template-columns: 1fr; }
    .gloss dd { border-top: none; padding-top: 0; }
}

/* Inline kbd / key highlight */
.key {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--primary);
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
}

/* highlight phrase */
.highlight {
    background: var(--warn-bg);
    padding: 1px 4px;
    border-radius: 2px;
    color: var(--text);
}






</style>
</head>
<body>


<div class="layout">

<aside class="sidebar">
        
    <div class="brand">
        <div class="brand-mark">
            <div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;letter-spacing:-0.02em;">MD</div>
        </div>
        <div class="brand-text">
            <div class="brand-title">Training manual</div>
            <div class="brand-sub">Engineering Toolbox</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
      <li><a href="#intro">Introduction &amp; layout overview</a></li>
      <li><a href="#weight">Weight Calculator</a></li>
      <li><a href="#hardness">Hardness · Steels</a></li>
      <li><a href="#shore">Shore · Rubber &amp; Plastic</a></li>
      <li><a href="#reference">Reference data</a></li>
      <li><a href="#limits">Limits &amp; best practice</a></li>
      <li><a href="#appendix">Appendix &amp; troubleshooting</a></li>
    </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Training manual</div>
    <h1>Engineering Toolbox</h1>
    <p class="lede">
        Operator manual for the MagDyn engineering toolbox: weight calculation
        across six profile types and 32 materials, hardness conversions per
        ASTM E140, and Shore durometer reference for elastomers and plastics.
        Every panel includes a short exercise to confirm you can use it.
    </p>
    <div class="cta-row">
        <a class="cta primary" href="weight-calculator.html">Open the tool →</a>
        <a class="cta secondary" href="#intro">Skip to introduction</a>
    </div>
</div>

<section class="module" id="intro">
    <div class="section-mark">
      <span class="num">01</span>
      <span class="of">/</span>
      <span>Introduction</span>
    </div>
    <h2>What this <em>tool</em> is, and what it isn't.</h2>

    <p class="lede">
      The MagDyn Engineering Toolbox is a <em>single-page utility</em> that handles three of the most common back-of-envelope calculations in a metals-and-polymers shop: stock weight, steel hardness conversion, and rubber/plastic durometer conversion. It runs entirely in your browser — no install, no network, no spreadsheet juggling.
    </p>

    <h3>What problems it solves</h3>
    <p>
      Every metalworking and polymer shop has the same three reference questions repeating themselves daily: <strong>"how much will this piece weigh?"</strong>, <strong>"this material spec is in HRC but my tester reads HV — what's that?"</strong>, and <strong>"the customer wants Shore 70A, what's that in Shore D?"</strong>. The toolbox answers all three from one window.
    </p>

    <h3>What it doesn't do</h3>
    <p>
      It is not a replacement for direct measurement, formal certification, or material datasheets. It does not handle stress analysis, fatigue calculations, FEA, or anything dynamic. The hardness conversions are <em>approximate</em> — accurate enough for procurement and shop-floor decisions, never for compliance documents. <strong>Always quote the original measurement scale on QC records.</strong>
    </p>

    <h3>The three modules at a glance</h3>

    <div class="mockup">
      <div class="m-bar">
        <span class="m-brand">MagDyn</span>
        <span>● LIVE · 32 MATERIALS · 6 PROFILES</span>
      </div>
      <div class="m-tabs">
        <span class="m-tab active">01 WEIGHT CALCULATOR</span>
        <span class="m-tab">02 HARDNESS · STEELS</span>
        <span class="m-tab">03 SHORE · RUBBER/PLASTIC</span>
      </div>
      <div class="m-grid">
        <div class="m-panel">
          <div class="m-label">// 01</div>
          <div class="m-title">Profile &amp; material</div>
          <div style="font-size:9px;color:var(--text-muted);line-height:1.6">
            ▢ Rect Bar  ◯ Round  ▣ Square<br />
            ⬡ Hex  ◎ Tube  ▭ Sheet<br /><br />
            Material → Steel A36
          </div>
        </div>
        <div class="m-panel">
          <div class="m-label">// 02</div>
          <div class="m-title">Dimensions</div>
          <div style="font-size:9px;color:var(--text-muted);line-height:1.6">
            METRIC · mm<br />
            W: 50  H: 25  L: 1000<br /><br />
            Quantity: 1
          </div>
        </div>
        <div class="m-panel">
          <div class="m-label">// 03</div>
          <div class="m-title">Calculated mass</div>
          <div class="m-readout">9.81</div>
          <div style="font-size:9px;color:var(--text-muted);text-align:center;letter-spacing:0.16em">KILOGRAMS</div>
        </div>
      </div>
    </div>
    <div class="mockup-caption">
      <span class="key">FIG 01-A</span> Three-panel layout shared by all modules — input on the left, parameters in the middle, output on the right.
    </div>

    <h3>Launching the tool</h3>
    <p>
      Open <code>weight-calculator.html</code> in any modern browser (Chrome, Edge, Safari, Firefox). The file is fully self-contained — fonts pull from Google Fonts on first load, then the tool works offline. No login, no data leaves your machine.
    </p>

    <div class="callout note">
      <strong>Note · file portability</strong>
      The HTML file embeds its own logo and all styling inline. You can email it, drop it on a shared drive, or pin it to a USB stick — it will work identically anywhere a browser exists.
    </div>

    <h3>The top bar — your global controls</h3>
    <p>
      Three things sit in the top strip and stay accessible from every module:
    </p>
    <ol class="steps">
      <li>
        <strong>The MagDyn brand mark</strong>
        <p>Top-left corner. Click takes you nowhere — it's just a wayfinding anchor.</p>
      </li>
      <li>
        <strong>The module tabs</strong>
        <p>Three tabs labelled <code>01 Weight Calculator</code>, <code>02 Hardness · Steels</code>, <code>03 Shore · Rubber/Plastic</code>. Click to switch. State within each module is preserved when you switch away — your dimensions don't get wiped.</p>
      </li>
      <li>
        <strong>The theme toggle</strong>
        <p>Sun/moon icon, far right. Flips between the dark cockpit theme (default, easier on the eyes for shop-floor lighting) and the light paper theme (better for projection or daylight). The change persists for the session only — refresh and you're back to dark.</p>
      </li>
    </ol>

    <div class="module-end">
      <span>End of <span class="key">01</span></span>
      <span>Continue → Weight Calculator</span>
    </div>
  </section>

  <!-- ============================================================
       SECTION 02 — WEIGHT CALCULATOR
       ============================================================ -->
  <section class="module" id="weight">
    <div class="section-mark">
      <span class="num">02</span>
      <span class="of">/</span>
      <span>Module · Weight Calculator</span>
    </div>
    <h2>From <em>profile</em> &amp; dimensions to mass — in three columns.</h2>

    <p class="lede">
      The Weight Calculator answers the question <em>"how much will this piece of stock weigh?"</em> for any of six standard profiles in any of 32 materials. Pick a profile, choose a material, key in dimensions, read the mass on the right.
    </p>

    <h3>The workflow</h3>

    <ol class="steps">
      <li>
        <strong>Pick a profile (Panel 01)</strong>
        <p>Six options, top of Panel 01: Rect Bar, Round Bar, Sq. Bar, Hex Bar, Round Tube, Sheet/Plate. The active button highlights in lime. The geometry diagram below the picker updates to show which dimensions you'll need.</p>
      </li>
      <li>
        <strong>Pick a material (Panel 01)</strong>
        <p>The dropdown is grouped: <em>Ferrous</em>, <em>Aluminum</em>, <em>Copper Group</em>, <em>Light/Specialty</em>, <em>Commodity Plastics</em>, <em>Engineering Plastics</em>. Each entry shows its density inline (e.g. <code>Steel, Mild (A36) — ρ 7.85</code>). The default is mild steel A36.</p>
      </li>
      <li>
        <strong>Choose your unit system (Panel 02)</strong>
        <p>Toggle <code>METRIC · mm</code> or <code>IMPERIAL · in</code>. <strong>Important:</strong> when you flip the toggle, your existing dimension values are <em>converted in place</em> — they don't reset. So 50 mm becomes 1.97 in automatically.</p>
      </li>
      <li>
        <strong>Enter dimensions (Panel 02)</strong>
        <p>Each profile shows the fields it needs — Rect Bar wants Width × Height × Length; Round Tube wants Outer Ø + Wall Thickness + Length. Type values in any field; the output recalculates instantly on every keystroke.</p>
      </li>
      <li>
        <strong>Set quantity (Panel 02)</strong>
        <p>If you're costing 200 identical pieces, set Quantity to 200. The total weight on the right multiplies; the per-piece weight stays single.</p>
      </li>
      <li>
        <strong>Read the output (Panel 03)</strong>
        <p>Big number is total weight. Below it: per-piece mass. Below that, four breakdown stats: <em>Volume</em>, <em>Density</em>, <em>Surface Area</em>, and <em>Linear Mass</em> (kg/m or lb/ft of the longitudinal axis).</p>
      </li>
    </ol>

    <h3>What the numbers mean</h3>

    <div class="formula">
      <div class="equation">mass = <em>ρ</em> × V × n</div>
      <div class="gloss">
        <var>ρ</var> material density (g/cm³ or lb/in³, internal)<br />
        <var>V</var> volume computed from the profile geometry<br />
        <var>n</var> quantity (number of pieces)
      </div>
    </div>

    <p>
      For tubes the volume subtracts the inner bore: <code>V = π(R² − r²) × L</code>. For hex bar it uses the across-flats convention: <code>V = (√3 / 2) × A² × L</code>. Sheet is the simple <code>L × W × T</code>.
    </p>

    <div class="callout tip">
      <strong>Tip · linear mass for ordering</strong>
      The <em>Linear mass</em> stat (kg/m or lb/ft) is the most useful number for ordering bar stock by length. Most mills quote prices per metre or per foot — multiply linear mass by run length to get a fast cost-of-material estimate.
    </div>

    <h3>Profile reference</h3>

    <table class="spec">
      <caption>Required dimensions per profile</caption>
      <thead>
        <tr><th>Profile</th><th>Dimensions</th><th>Volume formula</th></tr>
      </thead>
      <tbody>
        <tr><td>Rect Bar</td><td>W · H · L</td><td>W × H × L</td></tr>
        <tr><td>Round Bar</td><td>Ø · L</td><td>π × (Ø/2)² × L</td></tr>
        <tr><td>Sq. Bar</td><td>S · L</td><td>S² × L</td></tr>
        <tr><td>Hex Bar</td><td>A/F · L</td><td>(√3/2) × A² × L</td></tr>
        <tr><td>Round Tube</td><td>OD · wall · L</td><td>π × ((OD/2)² − (ID/2)²) × L</td></tr>
        <tr><td>Sheet/Plate</td><td>L · W · T</td><td>L × W × T</td></tr>
      </tbody>
    </table>

    <div class="callout warn">
      <strong>Caution · density tolerance</strong>
      Densities in the dropdown are typical room-temperature values from standard references. Real stock varies with alloy grade, heat treatment, voids, and porosity. Treat the calculated mass as a <strong>±2% estimate</strong>. For shipping or load-bearing calculations, weigh a sample piece.
    </div>

    <div class="exercise">
      <h4>Try it: a tonne of stock</h4>
      <p>Configure the calculator to find the length of <strong>50 mm × 50 mm</strong> square mild-steel bar that weighs exactly <strong>1,000 kg</strong>.</p>
      <ol>
        <li>Profile → Sq. Bar</li>
        <li>Material → Steel, Mild (A36)</li>
        <li>Side → 50 mm; vary Length until total reads 1000.00</li>
      </ol>
      <div class="answer"><strong>Expected</strong> Length ≈ 50,955 mm (about 51 m). Linear mass should read about 19.6 kg/m.</div>
    </div>

    <div class="module-end">
      <span>End of <span class="key">02</span></span>
      <span>Continue → Hardness · Steels</span>
    </div>
  </section>

  <!-- ============================================================
       SECTION 03 — HARDNESS
       ============================================================ -->
  <section class="module" id="hardness">
    <div class="section-mark">
      <span class="num">03</span>
      <span class="of">/</span>
      <span>Module · Hardness · Steels</span>
    </div>
    <h2>Convert between <em>HRC, HRB, HV, HBW</em> and tensile strength.</h2>

    <p class="lede">
      The Hardness module is a two-panel view: a <em>reference table</em> on the left covering the full practical hardness range for non-austenitic steels, and a <em>quick-converter</em> on the right that interpolates between the rows.
    </p>

    <h3>What the scales mean</h3>

    <table class="spec">
      <caption>Steel hardness scales — when each one applies</caption>
      <thead>
        <tr><th>Scale</th><th>Test method</th><th>Typical use</th></tr>
      </thead>
      <tbody>
        <tr><td>HRC</td><td>Rockwell C — 120° diamond cone, 150 kgf</td><td>Hardened steel, tool steel, bearings (20–70)</td></tr>
        <tr><td>HRB</td><td>Rockwell B — 1/16″ steel ball, 100 kgf</td><td>Annealed steel, brass, soft alloys (25–100)</td></tr>
        <tr><td>HRA</td><td>Rockwell A — diamond cone, 60 kgf</td><td>Thin steels, cemented carbide, case-hardened</td></tr>
        <tr><td>HV</td><td>Vickers — diamond pyramid, variable load</td><td>Microhardness, weld zones, case depth</td></tr>
        <tr><td>HBW</td><td>Brinell — 10 mm tungsten ball, 3000 kgf</td><td>Cast iron, large/coarse-grained steels</td></tr>
        <tr><td>HS</td><td>Scleroscope — diamond hammer rebound</td><td>Field portable test on rolls, large parts</td></tr>
      </tbody>
    </table>

    <h3>Using the reference table</h3>

    <div class="mockup">
      <div class="m-bar">
        <span class="m-brand">MagDyn</span>
        <span>HARDNESS · STEELS</span>
      </div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
        <div class="m-panel" style="position:relative;">
          <div class="m-title">Hardness conversion · non-austenitic steels</div>
          <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:9px;color:var(--surface)">
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;color:var(--primary);font-weight:700;border-bottom:1px solid var(--text);padding-bottom:4px">
              <span>HRC</span><span>HRB</span><span>HV</span><span>HBW</span><span>HRA</span><span>UTS</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;padding:3px 0;border-bottom:1px dashed var(--text)"><span style="color:var(--primary)">60</span><span style="color:var(--text-muted)">—</span><span>697</span><span>654</span><span>81.2</span><span>2393</span></div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;padding:3px 0;border-bottom:1px dashed var(--text)"><span style="color:var(--primary)">50</span><span style="color:var(--text-muted)">—</span><span>513</span><span>481</span><span>75.9</span><span>1731</span></div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;padding:3px 0;border-bottom:1px dashed var(--text);background:rgba(200,255,62,0.08)"><span style="color:var(--primary)">40</span><span style="color:var(--text-muted)">—</span><span>392</span><span>371</span><span>70.3</span><span>1248</span></div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;padding:3px 0;border-bottom:1px dashed var(--text)"><span style="color:var(--primary)">30</span><span>105</span><span>302</span><span>286</span><span>64.8</span><span>951</span></div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:6px;padding:3px 0;color:var(--text-muted)"><span>—</span><span>76</span><span>152</span><span>146</span><span>—</span><span>490</span></div>
          </div>
          <div class="m-callout" style="bottom:8px;right:8px">MATCH</div>
        </div>
        <div class="m-panel">
          <div class="m-title">Quick convert</div>
          <div style="font-size:9px;color:var(--text-muted);margin-bottom:6px">Scale: HRC · Value: 40</div>
          <div style="font-size:9px;color:var(--surface);line-height:1.7;border-left:2px solid var(--primary-dark);padding-left:6px">
            HV: <span style="color:var(--primary)">392</span><br />
            HBW: <span style="color:var(--primary)">371</span><br />
            HRA: <span style="color:var(--primary)">70.3</span>
          </div>
          <div class="m-readout" style="font-size:20px;margin-top:6px">1248</div>
          <div style="font-size:8px;color:var(--text-muted);text-align:center;letter-spacing:0.16em">MPa · UTS</div>
        </div>
      </div>
    </div>
    <div class="mockup-caption">
      <span class="key">FIG 03-A</span> The active row in the table highlights to match the converter — pick HRC=40, the row for 40 lights up.
    </div>

    <h3>The converter side panel</h3>

    <ol class="steps">
      <li>
        <strong>Pick the input scale</strong>
        <p>Drop down to choose <code>HRC</code>, <code>HRB</code>, <code>HV</code>, or <code>HBW</code>. The default value adjusts to a typical mid-range reading for that scale, so you're not starting from zero.</p>
      </li>
      <li>
        <strong>Type your measured value</strong>
        <p>The converter interpolates linearly between the two nearest table rows. Out-of-range inputs return em-dashes rather than extrapolating — this is intentional, because hardness conversions break down outside their valid bands.</p>
      </li>
      <li>
        <strong>Read the equivalents</strong>
        <p>Six cells show the value translated to HRC, HRB, HV, HBW, HRA, and HS. Greyed-out cells (em-dashes) mean that scale is not valid for the input region — for example, HRB has no defined equivalent above HRC ~ 38.</p>
      </li>
      <li>
        <strong>Read the tensile estimate</strong>
        <p>The big number at the bottom is estimated Ultimate Tensile Strength, in both MPa and ksi. This is computed from the HBW relationship (UTS ≈ 3.45 × HV for steels) and is useful for fast material screening.</p>
      </li>
    </ol>

    <div class="callout warn">
      <strong>Critical · NACE / spec-boundary work</strong>
      Hardness conversions are empirical and assume a "well-behaved" non-austenitic steel. Expect <strong>±5% scatter</strong> in real measurements. For NACE MR0175 sour-service compliance, ASME PWHT verification, or any pass/fail decision near a spec limit, <strong>measure directly on the governing scale</strong>. Do not report a converted value as a primary measurement on a quality record.
    </div>

    <h3>When you can't use this table</h3>
    <p>
      The conversion table is built from data on <em>non-austenitic steels</em> — carbon steel, alloy steel, tool steel. It does not apply to:
    </p>
    <table class="spec">
      <thead>
        <tr><th>Material class</th><th>Why the table fails</th></tr>
      </thead>
      <tbody>
        <tr><td>Austenitic stainless (304, 316)</td><td>Work-hardens during indentation; readings deviate 5–8%</td></tr>
        <tr><td>Aluminum alloys</td><td>Different elastic-plastic response — use ASTM E140 X9 tables</td></tr>
        <tr><td>Copper / brass</td><td>Soft and ductile — use ASTM E140 X4 tables</td></tr>
        <tr><td>Cast iron (gray)</td><td>Graphite inclusions cause 15–20% scatter in conversions</td></tr>
        <tr><td>Case-hardened parts</td><td>Brinell averages case + core; conversions apply to surface only</td></tr>
        <tr><td>Cemented carbide</td><td>Use HRA scale directly — no reliable HRC equivalent</td></tr>
      </tbody>
    </table>

    <div class="exercise">
      <h4>Try it: tool steel verification</h4>
      <p>A customer sends a D2 tool-steel part with spec <strong>58–62 HRC</strong>. Your shop tester only does Vickers. What HV range should you accept?</p>
      <ol>
        <li>Switch to the Hardness · Steels tab</li>
        <li>Set Scale → HRC; type 58</li>
        <li>Read HV; repeat for 62</li>
      </ol>
      <div class="answer"><strong>Expected</strong> 58 HRC ≈ 653 HV; 62 HRC ≈ 746 HV. So accept readings in the <strong>650–750 HV</strong> range with the standard ±5% caveat.</div>
    </div>

    <div class="module-end">
      <span>End of <span class="key">03</span></span>
      <span>Continue → Shore · Rubber/Plastic</span>
    </div>
  </section>

  <!-- ============================================================
       SECTION 04 — SHORE
       ============================================================ -->
  <section class="module" id="shore">
    <div class="section-mark">
      <span class="num">04</span>
      <span class="of">/</span>
      <span>Module · Shore · Rubber/Plastic</span>
    </div>
    <h2>Durometer scales for <em>flexible</em> materials.</h2>

    <p class="lede">
      Shore (durometer) hardness measures soft and semi-rigid materials — rubbers, elastomers, plastics, foams — using a spring-loaded indenter per <em>ASTM D2240</em>. It's a different family of tests from the steel hardness in module 03, with completely different indenter geometries.
    </p>

    <h3>The three Shore scales</h3>

    <div class="cols-2">
      <div>
        <h4>Shore A — soft elastomers</h4>
        <p>35° truncated cone, 0.79 mm flat tip, 822 g spring force. Used for rubbers, elastomers, soft plastics. <strong>Range:</strong> 0 (gel) to 100 (rigid). Common references: rubber band ≈ 25A, tire tread ≈ 65A, shoe sole ≈ 80A.</p>
      </div>
      <div>
        <h4>Shore D — hard plastics</h4>
        <p>30° conical point, 0.1 mm radius tip, 4536 g spring force. Used for hard rubbers and rigid plastics. <strong>Range:</strong> 0 (medium-firm) to 100 (very rigid). Common references: shopping-cart wheel ≈ 38D, hard hat ≈ 75D, polycarbonate ≈ 85D.</p>
      </div>
      <div>
        <h4>Shore OO — gels &amp; foams</h4>
        <p>Smaller indenter, lighter spring. Used for very soft materials below the Shore A range. <strong>Range:</strong> 0 to 100. References: gel insole ≈ 55 OO, soft foam ≈ 35 OO.</p>
      </div>
      <div>
        <h4>IRHD — ISO equivalent</h4>
        <p>International Rubber Hardness Degrees, per ISO 48. Numerically close to Shore A in the elastomer range — about a 1:1 correspondence for typical rubbers between 30 and 90.</p>
      </div>
    </div>

    <div class="callout warn">
      <strong>Don't · cross-family conversion</strong>
      Shore values <em>cannot</em> be meaningfully converted to Rockwell or Brinell. The materials and indenter geometries are too different. If you see a chart claiming "60 Shore A = 30 HRC", treat it as wrong — the test methods don't share calibration.
    </div>

    <h3>Using the Shore module</h3>

    <ol class="steps">
      <li>
        <strong>Open the table to find your material</strong>
        <p>The reference table on the left shows Shore A / Shore D / Shore OO / IRHD across the full practical range, with a "feel" descriptor (soft / firm / hard / rigid) and an analog material example for each row. Use it to sanity-check what your spec really means in physical terms.</p>
      </li>
      <li>
        <strong>Use the converter for cross-scale spec translation</strong>
        <p>Pick the input scale (A, D, or OO), enter your measured or specified value, read the equivalents. The converter interpolates between rows, but only within the overlap zone where two scales physically agree.</p>
      </li>
      <li>
        <strong>Note the "Material category" output</strong>
        <p>The bottom of the converter panel calls out the descriptive category (e.g. <em>"firm"</em>) and an analog reference material. This is gold for procurement conversations: telling the buyer "we need 70 Shore A — like an O-ring" is more communicative than the number alone.</p>
      </li>
    </ol>

    <h3>The A / D overlap zone</h3>
    <p>
      Shore A and D meaningfully overlap only between roughly <strong>90A ↔ 38D</strong>. Below that, Shore D readings on soft material are unreliable (the indenter punches through). Above 100A, Shore A is saturated and switching to Shore D is mandatory. The converter shows em-dashes outside the overlap.
    </p>

    <table class="spec">
      <caption>Approximate overlap table</caption>
      <thead><tr><th>Shore A</th><th>Shore D</th><th>Feel / example</th></tr></thead>
      <tbody>
        <tr><td>60</td><td>12</td><td>medium-firm — tire tread</td></tr>
        <tr><td>70</td><td>18</td><td>firm — O-ring, skateboard wheel</td></tr>
        <tr><td>80</td><td>27</td><td>hard — shoe heel, hydraulic seal</td></tr>
        <tr><td>90</td><td>38</td><td>very hard — shopping-cart wheel</td></tr>
        <tr><td>95</td><td>45</td><td>very hard — hard hat (semi-rigid)</td></tr>
        <tr><td>100</td><td>55</td><td>rigid — ebonite, golf-ball cover</td></tr>
      </tbody>
    </table>

    <div class="exercise">
      <h4>Try it: O-ring spec translation</h4>
      <p>A vendor catalogue lists an O-ring as <strong>Shore D 18</strong> but your seal-design handbook is in Shore A. What's the equivalent?</p>
      <ol>
        <li>Switch to the Shore tab</li>
        <li>Scale → Shore D · hard; Value → 18</li>
        <li>Read the Shore A equivalent and the analog material</li>
      </ol>
      <div class="answer"><strong>Expected</strong> Shore A ≈ 70, analog: O-ring or skateboard wheel — exactly what we'd expect.</div>
    </div>

    <div class="module-end">
      <span>End of <span class="key">04</span></span>
      <span>Continue → Reference data</span>
    </div>
  </section>

  <!-- ============================================================
       SECTION 05 — REFERENCE
       ============================================================ -->
  <section class="module" id="reference">
    <div class="section-mark">
      <span class="num">05</span>
      <span class="of">/</span>
      <span>Reference data</span>
    </div>
    <h2>Densities, formulas &amp; <em>coefficients</em>.</h2>

    <p class="lede">
      The numbers underneath the buttons. Useful when someone asks "where did that value come from?" — or when you're working without the tool and need to do the math by hand.
    </p>

    <h3>Density library — metals</h3>

    <table class="spec">
      <caption>Material densities at 20 °C (g/cm³)</caption>
      <thead><tr><th>Material</th><th>ρ</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td>Steel, Mild (A36)</td><td class="num">7.85</td><td>Default — most carbon &amp; low-alloy steels</td></tr>
        <tr><td>Stainless 304 / 316</td><td class="num">8.00</td><td>Austenitic — slightly denser than carbon</td></tr>
        <tr><td>Cast Iron, Gray</td><td class="num">7.20</td><td>Lower due to graphite inclusions</td></tr>
        <tr><td>Aluminum 6061 / 7075</td><td class="num">2.70 / 2.81</td><td>7075 denser due to Zn content</td></tr>
        <tr><td>Copper</td><td class="num">8.96</td><td>Reference for copper-group alloys</td></tr>
        <tr><td>Brass C260</td><td class="num">8.53</td><td>70/30 cartridge brass</td></tr>
        <tr><td>Titanium Grade 2 / 6Al-4V</td><td class="num">4.51 / 4.43</td><td>Alpha-beta alloy is slightly lighter</td></tr>
        <tr><td>Magnesium AZ31</td><td class="num">1.77</td><td>Lightest practical structural metal</td></tr>
        <tr><td>Lead</td><td class="num">11.34</td><td>Highest density in common shop use</td></tr>
        <tr><td>Tungsten</td><td class="num">19.25</td><td>Densest metal commonly used</td></tr>
      </tbody>
    </table>

    <h3>Density library — plastics</h3>

    <table class="spec">
      <caption>Polymer densities at 20 °C (g/cm³)</caption>
      <thead><tr><th>Material</th><th>ρ</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td>Polypropylene (PP)</td><td class="num">0.91</td><td>Lightest commodity plastic — floats on water</td></tr>
        <tr><td>HDPE / LDPE</td><td class="num">0.95 / 0.92</td><td>Both float; HDPE slightly denser</td></tr>
        <tr><td>ABS</td><td class="num">1.05</td><td>Standard prototyping plastic</td></tr>
        <tr><td>Acrylic (PMMA)</td><td class="num">1.18</td><td>Plexiglas / Perspex</td></tr>
        <tr><td>Polycarbonate (PC)</td><td class="num">1.20</td><td>Lexan</td></tr>
        <tr><td>POM / Delrin</td><td class="num">1.41</td><td>Acetal — common machined plastic</td></tr>
        <tr><td>PVC, Rigid</td><td class="num">1.40</td><td>Pipe-grade rigid PVC</td></tr>
        <tr><td>PEEK</td><td class="num">1.32</td><td>High-temperature engineering polymer</td></tr>
        <tr><td>PTFE (Teflon)</td><td class="num">2.20</td><td>Densest common plastic — fluorinated</td></tr>
      </tbody>
    </table>

    <h3>Useful conversion coefficients</h3>

    <div class="formula">
      <div class="equation">1 in = <em>25.4</em> mm  ·  1 lb = <em>0.4536</em> kg</div>
      <div class="gloss">Used internally; you don't need to think about these.</div>
    </div>

    <div class="formula">
      <div class="equation">1 g/cm³ = <em>0.03613</em> lb/in³</div>
      <div class="gloss">Converts the metric density library to imperial. Same number, different units.</div>
    </div>

    <div class="formula">
      <div class="equation">UTS<sub>MPa</sub> ≈ <em>3.45</em> × HV  ·  UTS<sub>MPa</sub> ≈ <em>3.3</em> × HBW</div>
      <div class="gloss">
        <var>UTS</var> Ultimate Tensile Strength estimate from hardness. Valid for HBW 100–400 on carbon steels only.
      </div>
    </div>

    <div class="formula">
      <div class="equation">1 MPa = <em>0.145</em> ksi</div>
      <div class="gloss">For tensile-strength conversions in the hardness module.</div>
    </div>

  </section>

  <!-- ============================================================
       SECTION 06 — LIMITS
       ============================================================ -->
  <section class="module" id="limits">
    <div class="section-mark">
      <span class="num">06</span>
      <span class="of">/</span>
      <span>Limits &amp; best practice</span>
    </div>
    <h2>What this tool is <em>not</em> — and how to know when to put it down.</h2>

    <p class="lede">
      The toolbox is a fast first-pass utility. It is not a substitute for direct measurement, formal certification, or a competent metallurgist's judgement on edge cases. Read this section before quoting a value to a customer.
    </p>

    <h3>Three rules</h3>

    <ol class="steps">
      <li>
        <strong>Quote the original measurement, not the conversion</strong>
        <p>On any quality record, ship-out cert, or compliance document, write the value <em>as measured</em>: "62 HRC" if you used a Rockwell tester. Never write "697 HV (converted from 62 HRC)" as a primary spec. The tool is a translation aid for your decision-making, not a replacement for what your instrument measured.</p>
      </li>
      <li>
        <strong>Direct-measure near a spec boundary</strong>
        <p>If your reading is within ±5% of a pass/fail limit, conversion uncertainty alone could flip the result. Re-test on the governing scale before declaring pass or fail. This is non-negotiable for NACE, aerospace, and pressure-vessel work.</p>
      </li>
      <li>
        <strong>Mind the material class</strong>
        <p>The hardness table is calibrated for non-austenitic steels. The Shore module is calibrated for typical rubbers/plastics. Aluminum, copper, austenitic stainless, cast iron, ceramics, and composites need their own tables (or direct measurement on the spec scale). The tool will <em>still give you a number</em> for these — but the number will be wrong.</p>
      </li>
    </ol>

    <h3>Common pitfalls</h3>

    <table class="spec">
      <caption>Symptoms and remedies</caption>
      <thead><tr><th>Symptom</th><th>Cause</th><th>Fix</th></tr></thead>
      <tbody>
        <tr><td>Mass off by ~10%</td><td>Wrong density — common when ordering bronze and getting brass</td><td>Confirm material grade; check density library</td></tr>
        <tr><td>Hardness conversion off by &gt; 10</td><td>Material is austenitic or non-ferrous</td><td>Use direct measurement on spec scale</td></tr>
        <tr><td>Tube weight too low</td><td>Wall thickness left at default 2 mm</td><td>Always set wall thickness explicitly</td></tr>
        <tr><td>Imperial dims, metric output</td><td>Forgot to flip unit toggle</td><td>Toggle stays per-session — check before reading</td></tr>
        <tr><td>HRB shows em-dash for high-HRC input</td><td>HRB is not defined above ~ HRC 38</td><td>Not a bug — that scale doesn't apply</td></tr>
        <tr><td>Shore D conversion fails for soft rubber</td><td>Below the A/D overlap zone</td><td>Stay on Shore A scale for rubber</td></tr>
      </tbody>
    </table>

    <h3>Tolerance summary</h3>

    <table class="spec">
      <caption>Expected accuracy by output</caption>
      <thead><tr><th>Output</th><th>Tolerance</th><th>Conditions</th></tr></thead>
      <tbody>
        <tr><td>Stock weight</td><td>±2%</td><td>Density library matches actual alloy grade</td></tr>
        <tr><td>Hardness conversion (steel)</td><td>±5%</td><td>Non-austenitic, within table range</td></tr>
        <tr><td>Tensile estimate from hardness</td><td>±10%</td><td>Carbon steel only, HBW 100–400</td></tr>
        <tr><td>Shore A↔D conversion</td><td>±5 points</td><td>Within 90A ↔ 38D overlap zone</td></tr>
      </tbody>
    </table>

  </section>

  <!-- ============================================================
       SECTION 07 — APPENDIX
       ============================================================ -->
  <section class="module" id="appendix">
    <div class="section-mark">
      <span class="num">07</span>
      <span class="of">/</span>
      <span>Appendix</span>
    </div>
    <h2>Keyboard, troubleshooting &amp; <em>support</em>.</h2>

    <h3>Keyboard navigation</h3>
    <p>
      The toolbox is fully keyboard-accessible. Use:
    </p>

    <table class="spec">
      <caption>Keyboard shortcuts</caption>
      <thead><tr><th>Action</th><th>Keys</th></tr></thead>
      <tbody>
        <tr><td>Move focus forward</td><td><kbd>Tab</kbd></td></tr>
        <tr><td>Move focus back</td><td><kbd>Shift</kbd> + <kbd>Tab</kbd></td></tr>
        <tr><td>Activate button / shape picker</td><td><kbd>Space</kbd> or <kbd>Enter</kbd></td></tr>
        <tr><td>Open dropdown</td><td><kbd>↓</kbd> or <kbd>Alt</kbd> + <kbd>↓</kbd></td></tr>
        <tr><td>Increment number field</td><td><kbd>↑</kbd> / <kbd>↓</kbd></td></tr>
        <tr><td>Switch tabs</td><td>Click only — no keyboard binding yet</td></tr>
      </tbody>
    </table>

    <h3>Troubleshooting</h3>

    <h4>The fonts look wrong</h4>
    <p>The page uses the system font stack (no web font download) plus a system monospace fallback for any code or number-heavy blocks, so it renders identically online and offline from first load.</p>

    <h4>Theme toggle doesn't persist</h4>
    <p>By design — the tool stores no state between sessions because it runs as a self-contained HTML file with no localStorage write permission in some sandboxed environments. If you want persistent theme, host the file on a real server.</p>

    <h4>Numbers appear as scientific notation</h4>
    <p>This happens for very small results (sub-milligram). The formatter switches to <code>1.23e-04</code> when values drop below 0.01. Check your dimensions — usually a misplaced decimal.</p>

    <h4>Two tabs show "match" highlighting at once</h4>
    <p>Expected behaviour — each module's converter remembers the last row you matched even when the tab is hidden. No action needed.</p>

    <h3>Standards referenced</h3>
    <table class="spec">
      <thead><tr><th>Standard</th><th>Scope</th></tr></thead>
      <tbody>
        <tr><td>ASTM E140-12b</td><td>Hardness conversion tables for metals (non-austenitic steels)</td></tr>
        <tr><td>ASTM D2240</td><td>Standard test method for rubber property — durometer hardness</td></tr>
        <tr><td>ISO 18265</td><td>International equivalent of ASTM E140</td></tr>
        <tr><td>ISO 48-4</td><td>Rubber, vulcanized — determination of hardness (Shore)</td></tr>
        <tr><td>SAE J417</td><td>Hardness tests &amp; hardness number conversions for steel</td></tr>
      </tbody>
    </table>

    <h3>Feedback</h3>
    <p>
      If a value in the density library or hardness table looks wrong for a specific alloy you work with regularly, raise it with the engineering desk — the JSON tables in the source HTML are easy to extend. Density and hardness data live near the top of the <code>&lt;script&gt;</code> block, well-commented.
    </p>

  </section>

  <!-- ============================================================
       COLOPHON
       ============================================================ -->
  <footer class="colophon">
    <p>
      <em>End of manual.</em>
    </p>
    <p style="margin-top:12px">
      MagDyn Engineering Toolbox · Training Manual<span class="magdyn-mark"></span>Document <strong>TM-001</strong> · Revision 04 · May 2026
    </p>
    <p style="margin-top:6px">
      Set in the system font stack with a monospace fallback for numerals. Printed for the workshop floor.
    </p>
  </footer>

</main>

</div>

<footer class="foot">
    <div>Engineering Toolbox · Training manual</div>
    <div>Runs locally · MagDyn</div>
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
