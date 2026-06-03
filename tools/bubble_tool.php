<?php
// MagDyn integration: require login to access this tool. The bootstrap
// resolves to the parent app dir so this works regardless of how the
// tool is reached (direct or via iframe wrapper).
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Bubble · MagDyn';
$current_page  = 'bubble_tool.php';
$trigger_style = 'dark';
$cdn_scripts   = [
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js',
];
include 'includes/head.php';
?>
<style>
/* ============================================================
   Bubble — app-specific styles (loaded AFTER magdyn-base.css)
   Every colour here is a MagDyn CSS variable. No new tokens.
   ============================================================ */

/* ---- App shell ---- */
html, body { height: 100%; overflow: hidden; }
body { overflow: hidden; }

.layout { height: 100vh; }
.main { padding: 18px 22px; display: flex; flex-direction: column; min-height: 0; }

/* The bubble tool is dense — page-head margin compresses */
.main > .page-head { margin-bottom: 14px; flex-shrink: 0; }

/* Two-column body: canvas grows, parts panel is fixed-ish */
.bubble-body {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 14px;
    min-height: 0;
}
@media (max-width: 1100px) {
    .bubble-body { grid-template-columns: 1fr; grid-template-rows: 1fr auto; }
    .parts-card { max-height: 280px; }
}

/* ---- Tool sidebar (uses MagDyn's dark sidebar) ---- */
.tool-nav-section {
    margin-top: 14px;
    padding: 4px 12px 6px;
    font-size: 10px;
    color: var(--sidebar-text-very-dim);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 600;
}

/* Tool buttons — re-purpose .tool-btn as MagDyn nav-item-shaped buttons inside the sidebar */
.tool-grid {
    padding: 0 8px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
}
.tool-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 10px 6px;
    color: var(--sidebar-text);
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    font-size: 11px;
    letter-spacing: 0.04em;
    transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.tool-btn:hover { background: var(--sidebar-bg-hover); color: white; }
.tool-btn.active {
    background: var(--sidebar-bg-active);
    color: white;
    border-color: var(--sidebar-bg-active);
}
.tool-btn svg { width: 18px; height: 18px; opacity: 0.9; }

/* Shape / redaction-style picker (small swatches) */
.shape-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
    padding: 0 8px;
}
.shape-row.row-2 { grid-template-columns: 1fr 1fr; }
.shape-btn {
    aspect-ratio: 1;
    background: transparent;
    border: 1px solid var(--sidebar-border);
    border-radius: 6px;
    color: var(--sidebar-text);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
}
.shape-btn:hover { background: var(--sidebar-bg-hover); color: white; }
.shape-btn.active {
    background: var(--sidebar-bg-active);
    border-color: var(--sidebar-bg-active);
    color: white;
}
.shape-btn svg { width: 22px; height: 22px; }

/* In-sidebar form controls — sit on the dark background */
.sidebar-field { padding: 0 12px; margin-bottom: 10px; }
.sidebar-field label {
    display: block;
    font-size: 10px;
    color: var(--sidebar-text-very-dim);
    letter-spacing: 0.08em;
    margin-bottom: 5px;
    text-transform: uppercase;
    font-weight: 600;
}
.sidebar-field input[type="number"],
.sidebar-field input[type="text"],
.sidebar-field select {
    width: 100%;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--sidebar-border);
    color: white;
    padding: 6px 8px;
    font-family: inherit;
    font-size: 12px;
    border-radius: 4px;
    outline: none;
}
.sidebar-field input:focus, .sidebar-field select:focus { border-color: var(--primary); }
.sidebar-field input[type="color"] {
    width: 100%;
    height: 28px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--sidebar-border);
    border-radius: 4px;
    padding: 2px;
    cursor: pointer;
}

.slider-row { display: flex; align-items: center; gap: 8px; }
.slider-row input[type="range"] { flex: 1; accent-color: var(--primary); }
.slider-row .val {
    font-size: 11px; color: white;
    min-width: 36px; text-align: right;
    font-variant-numeric: tabular-nums;
}

/* Mini-buttons in the sidebar (zoom/rotate rows) */
.mini-row { display: flex; gap: 3px; margin-top: 6px; }
.mini-btn {
    flex: 1;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--sidebar-border);
    color: var(--sidebar-text);
    padding: 5px 4px;
    font-family: inherit;
    font-size: 10px;
    letter-spacing: 0.05em;
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.12s;
}
.mini-btn:hover { background: var(--sidebar-bg-hover); color: white; }

/* Quick calculator widgets in the sidebar */
.qc-unit-row {
    display: grid;
    grid-template-columns: 1fr 14px 1fr;
    gap: 4px; align-items: center;
}
.qc-arrow {
    text-align: center; color: var(--sidebar-text-dim);
    font-size: 11px;
}
.qc-expr-row {
    display: flex; gap: 4px; align-items: stretch;
}
.qc-expr-row input { flex: 1; min-width: 0; }
.qc-expr-row .mini-btn {
    flex: 0 0 auto;
    padding: 5px 10px;
    font-size: 13px; font-weight: 700;
}
.qc-result {
    margin-top: 6px;
    padding: 6px 8px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--sidebar-border);
    border-radius: 4px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    color: var(--sidebar-text);
    overflow-wrap: anywhere;
    min-height: 14px;
}
.qc-result.error { color: #fda4af; }
.qc-result.success { color: #86efac; }
.btn-small.qc-open-full {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--sidebar-border);
    color: var(--sidebar-text);
    padding: 8px 10px;
    font-family: inherit;
    font-size: 11px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border-radius: 5px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-small.qc-open-full:hover {
    background: var(--sidebar-bg-hover); color: white;
}

/* Upload zone */
.upload-zone {
    margin: 0 12px;
    border: 1px dashed var(--sidebar-border);
    background: rgba(255,255,255,0.02);
    border-radius: 6px;
    padding: 16px 10px;
    text-align: center;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.upload-zone:hover, .upload-zone.drag {
    border-color: var(--primary);
    background: rgba(255,255,255,0.05);
}
.upload-zone .ico { font-size: 22px; color: var(--sidebar-text-dim); margin-bottom: 4px; }
.upload-zone p { font-size: 10px; color: var(--sidebar-text-dim); letter-spacing: 0.06em; }

/* ---- Canvas stage (light surface) ---- */
.stage-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 0;
}
.stage {
    flex: 1;
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 50% 50%, var(--surface) 0%, var(--surface-alt) 100%);
}
.stage-corner {
    position: absolute;
    color: var(--text-light);
    font-size: 10px;
    letter-spacing: 0.12em;
    pointer-events: none;
    user-select: none;
    text-transform: uppercase;
    font-weight: 600;
}
.stage-corner.tl { top: 10px; left: 14px; }
.stage-corner.tr { top: 10px; right: 14px; }
.stage-corner.bl { bottom: 10px; left: 14px; }
.stage-corner.br { bottom: 10px; right: 14px; }

#drawing-container {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: default;
    overflow: hidden;        /* clip canvas-wrap when panned or zoomed past edges */
}
#drawing-container.tool-bubble { cursor: crosshair; }
#drawing-container.tool-redact { cursor: crosshair; }
#drawing-container.tool-text { cursor: text; }
#drawing-container.tool-pan { cursor: grab; }
#drawing-container.tool-pan.panning { cursor: grabbing; }

.canvas-wrap {
    position: relative;
    transform-origin: center center;
    will-change: transform;
}
#drawing-img {
    display: block;
    max-width: none;
    user-select: none;
    -webkit-user-drag: none;
}
#annotations {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    overflow: visible;
    pointer-events: none;
}
#annotations .bubble-group { pointer-events: auto; cursor: move; }
#annotations .leader-anchor { pointer-events: auto; cursor: crosshair; }
#annotations .leader-line { pointer-events: none; }
#annotations text { user-select: none; }
#annotations .bubble-group.selected .bubble-shape {
    filter: drop-shadow(0 0 0 2px var(--primary)) drop-shadow(0 0 6px var(--primary));
}
#annotations .redact-rect { pointer-events: auto; cursor: move; }
#annotations .redact-rect.selected {
    filter: drop-shadow(0 0 0 2px var(--primary)) drop-shadow(0 0 4px var(--primary));
}
#annotations .redact-handle {
    pointer-events: auto;
    cursor: nwse-resize;
    fill: var(--primary);
    stroke: var(--text);
    stroke-width: 1;
}
#annotations .redact-pending {
    fill: rgba(185, 28, 28, 0.18);
    stroke: var(--danger);
    stroke-width: 1.5;
    stroke-dasharray: 4 3;
    pointer-events: none;
}
#annotations .text-annot { pointer-events: auto; cursor: move; user-select: none; }
#annotations .text-annot-bg {
    fill: rgba(30, 58, 138, 0.06);
    stroke: var(--primary);
    stroke-width: 1;
    stroke-dasharray: 2 2;
    pointer-events: none;
}

/* Floating text editor */
.text-edit-overlay {
    position: absolute;
    background: var(--surface);
    border: 1px solid var(--primary);
    border-radius: var(--radius);
    padding: 6px;
    z-index: 200;
    box-shadow: var(--shadow-lg);
}
.text-edit-overlay textarea {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    padding: 6px 8px;
    min-width: 220px;
    min-height: 44px;
    resize: both;
    outline: none;
    border-radius: var(--radius);
}
.text-edit-overlay textarea:focus { border-color: var(--primary); }
.text-edit-overlay .row { display: flex; gap: 6px; margin-top: 6px; align-items: center; }
.text-edit-overlay button {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    padding: 5px 10px;
    font-family: inherit;
    font-size: 11px;
    cursor: pointer;
    border-radius: var(--radius);
    font-weight: 500;
}
.text-edit-overlay button:hover { background: var(--surface-alt); }
.text-edit-overlay button.primary { background: var(--primary); color: white; border-color: var(--primary); }
.text-edit-overlay button.primary:hover { background: var(--primary-dark); }
.text-edit-overlay .size-input {
    width: 56px;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    font-family: inherit;
    font-size: 11px;
    padding: 5px 7px;
    border-radius: var(--radius);
    outline: none;
}

/* Empty state — uses card pattern */
.empty-state {
    text-align: center;
    color: var(--text-muted);
}
.empty-state .big {
    font-size: 16px;
    color: var(--text);
    margin-bottom: 8px;
    font-weight: 600;
}
.empty-state .sub { font-size: 13px; }

/* Page navigator (PDF mode) */
.page-nav {
    position: absolute;
    bottom: 14px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--surface);
    border: 1px solid var(--border-strong);
    border-radius: var(--radius);
    padding: 6px 10px;
    display: none;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: var(--text);
    z-index: 10;
    box-shadow: var(--shadow);
}
.page-nav.visible { display: flex; }
.page-nav button {
    background: transparent;
    border: 1px solid var(--border-strong);
    color: var(--text);
    width: 26px; height: 26px;
    cursor: pointer;
    border-radius: 4px;
    font-family: inherit;
    font-size: 14px;
    line-height: 1;
    padding: 0;
}
.page-nav button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.page-nav button:disabled { opacity: 0.3; cursor: not-allowed; }
.page-nav .label { font-variant-numeric: tabular-nums; min-width: 78px; text-align: center; font-weight: 500; }
.page-nav .label .accent { color: var(--primary); font-weight: 700; }

/* ---- Parts list panel ---- */
.parts-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    min-height: 0;
}
.parts-head {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.parts-head h2 {
    font-size: 12px;
    letter-spacing: 0.07em;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
}
.parts-head .count {
    font-size: 11px;
    color: var(--text-muted);
}
.parts-table {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}
.parts-search-row {
    padding: 8px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 6px;
    align-items: center;
    flex-shrink: 0;
}
.parts-search-input {
    flex: 1;
    padding: 6px 10px;
    font-size: 12px;
    border: 1px solid var(--border);
    border-radius: 4px;
    outline: none;
    background: var(--surface, #fff);
    color: var(--text, #111);
    font-family: inherit;
}
.parts-search-input:focus {
    border-color: var(--accent, #1e3a8a);
}
.parts-search-clear {
    width: 22px;
    height: 22px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: transparent;
    color: var(--text-light);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.parts-search-clear:hover {
    background: #f3f4f6;
    color: var(--text);
}
.parts-empty {
    padding: 36px 16px;
    text-align: center;
    color: var(--text-light);
    font-size: 12px;
}
.parts-foot {
    padding: 10px 14px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}
.parts-foot .btn { flex: 1; }

/* Parts row — uses MagDyn surface tones */
.parts-row {
    border-bottom: 1px solid var(--border);
    transition: background 0.1s;
}
.parts-row.selected { background: var(--primary-light); }
.parts-row .summary {
    display: grid;
    grid-template-columns: 38px 1fr auto auto;
    align-items: center;
    padding: 9px 12px 9px 8px;
    gap: 6px;
    cursor: pointer;
}
.parts-row .summary:hover { background: var(--surface-alt); }
.parts-row .num {
    font-weight: 700;
    color: var(--primary);
    text-align: center;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1px;
}
.parts-row .num .num-prefix {
    font-weight: 700;
    color: var(--primary);
}
.parts-row .num .num-input {
    width: 38px;
    min-width: 28px;
    max-width: 60px;
    padding: 2px 3px;
    border: 1px solid transparent;
    background: transparent;
    color: var(--primary);
    font-weight: 700;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
    font-family: inherit;
    text-align: center;
    border-radius: 3px;
    outline: none;
}
.parts-row .num .num-input:hover {
    border-color: var(--border-strong);
    background: var(--surface);
}
.parts-row .num .num-input:focus {
    border-color: var(--primary);
    background: var(--surface);
    box-shadow: 0 0 0 2px var(--primary-light);
}
.parts-row .num.critical::after {
    content: '';
    position: absolute;
    top: -2px; right: -2px;
    width: 6px; height: 6px;
    background: var(--danger);
    border-radius: 50%;
    box-shadow: 0 0 0 2px var(--surface);
}
.parts-row.selected .num.critical::after { box-shadow: 0 0 0 2px var(--primary-light); }
.parts-row .desc-line { overflow: hidden; min-width: 0; }
.parts-row .desc-line .desc-text {
    color: var(--text);
    font-size: 12.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.parts-row .desc-line .dim-text {
    color: var(--primary);
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 500;
}
.parts-row .desc-line .placeholder {
    color: var(--text-light);
    font-style: italic;
    font-size: 12px;
}
.parts-row .expand-toggle {
    background: transparent;
    border: 1px solid var(--border-strong);
    color: var(--text-muted);
    width: 22px; height: 22px;
    cursor: pointer;
    border-radius: 4px;
    font-size: 11px;
    line-height: 1;
    padding: 0;
    transition: all 0.1s;
}
.parts-row .expand-toggle:hover { border-color: var(--primary); color: var(--primary); }
.parts-row.expanded .expand-toggle { color: var(--primary); border-color: var(--primary); transform: rotate(90deg); }
.parts-row .del {
    background: transparent;
    border: none;
    color: var(--text-light);
    cursor: pointer;
    font-size: 16px;
    padding: 2px 6px;
    line-height: 1;
    border-radius: 4px;
}
.parts-row .del:hover { color: var(--danger); }

.parts-row .detail {
    display: none;
    padding: 6px 14px 14px 8px;
    background: var(--surface-alt);
    border-top: 1px solid var(--border);
}
.parts-row.expanded .detail { display: block; }
.detail .field-mini { margin-bottom: 8px; }
.detail .field-mini > label {
    display: block;
    font-size: 10px;
    color: var(--text-muted);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 4px;
    font-weight: 600;
}
.detail input[type="text"], .detail select, .detail textarea {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    padding: 6px 8px;
    font-family: inherit;
    font-size: 12px;
    border-radius: var(--radius);
    outline: none;
}
.detail textarea { resize: vertical; min-height: 40px; line-height: 1.4; }
.detail input:focus, .detail select:focus, .detail textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(30, 58, 138, 0.1);
}
.detail .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.detail .row-tol { display: grid; grid-template-columns: 1fr 1fr 64px; gap: 6px; align-items: end; }
.detail .checkbox-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    cursor: pointer;
    user-select: none;
}
.detail .checkbox-row input[type="checkbox"] {
    accent-color: var(--danger);
    cursor: pointer;
    width: 14px; height: 14px;
}
.detail .checkbox-row span {
    font-size: 11px;
    color: var(--text);
    letter-spacing: 0.04em;
    font-weight: 500;
}
.detail .gdt-symbols {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 3px;
    margin-bottom: 6px;
}
.detail .gdt-symbols button {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    padding: 5px 0;
    cursor: pointer;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1;
    transition: all 0.1s;
}
.detail .gdt-symbols button:hover { border-color: var(--primary); color: var(--primary); }
.detail .gdt-symbols button.active { background: var(--primary-light); border-color: var(--primary); color: var(--primary); }
.detail .gdt-block { display: none; }
.detail .gdt-block.visible { display: block; }
.detail .standard-block { display: block; }
.detail .standard-block.hidden { display: none; }
.detail .mini-btn {
    flex: none;
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text);
    padding: 6px 8px;
    font-family: inherit;
    font-size: 10px;
    letter-spacing: 0.05em;
    cursor: pointer;
    border-radius: 4px;
    font-weight: 500;
}
.detail .mini-btn:hover { border-color: var(--primary); color: var(--primary); }

/* ---- Modals get a wider variant for the page manager ---- */
.modal-wide {
    max-width: 920px !important;
    max-height: 80vh;
    width: 92% !important;
    display: none;
    flex-direction: column;
}
.modal.open .modal-wide { display: flex; }
.page-grid {
    overflow-y: auto;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    min-height: 200px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px;
    margin: 8px 0;
}
.page-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px;
    position: relative;
    cursor: grab;
    user-select: none;
    transition: border-color 0.1s, transform 0.1s;
}
.page-card:hover { border-color: var(--primary); }
.page-card.dragging { opacity: 0.4; cursor: grabbing; }
.page-card.drop-before { border-left: 3px solid var(--primary); }
.page-card.drop-after { border-right: 3px solid var(--primary); }
.page-card.deleted {
    opacity: 0.55;
    background: var(--danger-bg);
    border-color: var(--danger);
    border-style: dashed;
}
.page-card.deleted .thumb-wrap { filter: grayscale(1); }
.page-card .thumb-wrap {
    width: 100%;
    aspect-ratio: 0.77;
    background: #ffffff;
    border: 1px solid var(--border);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    position: relative;
    border-radius: 4px;
}
.page-card .thumb-wrap img { max-width: 100%; max-height: 100%; display: block; }
.page-card .thumb-wrap .loading {
    color: var(--text-light);
    font-size: 10px;
    letter-spacing: 0.08em;
}
.page-card .thumb-wrap .deleted-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(185, 28, 28, 0.12);
    color: var(--danger);
    font-size: 10px;
    letter-spacing: 0.12em;
    font-weight: 700;
}
.page-card .meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 10px;
    color: var(--text-muted);
    letter-spacing: 0.06em;
    margin-bottom: 6px;
}
.page-card .meta .pgnum { color: var(--primary); font-weight: 700; font-size: 12px; }
.page-card .actions-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 3px;
}
.page-card .actions-row button {
    background: var(--surface);
    border: 1px solid var(--border-strong);
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 4px;
    padding: 4px 0;
    font-family: inherit;
    font-size: 10px;
    line-height: 1;
    transition: all 0.1s;
}
.page-card .actions-row button:hover { border-color: var(--primary); color: var(--primary); }
.page-card .actions-row button.danger:hover { border-color: var(--danger); color: var(--danger); }

/* Modal markup uses MagDyn — but the bubble tool created `.visible` style;
   bridge that by making `.visible` an alias for `.open` on the backdrop. */
.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal-backdrop.visible { display: flex; }
.modal-backdrop .modal {
    position: static;
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: 24px;
    width: 90%;
    max-width: 480px;
    box-shadow: var(--shadow-lg);
    display: block;
}
.modal-backdrop .modal-wide {
    max-width: 920px;
    max-height: 80vh;
    width: 92%;
    display: flex;
    flex-direction: column;
}
.modal-backdrop h2 {
    font-size: 16px;
    color: var(--text);
    margin-bottom: 10px;
    font-weight: 600;
}
.modal-backdrop p { color: var(--text-muted); margin-bottom: 12px; font-size: 13px; }
.modal-backdrop p.dim { color: var(--text-light); font-size: 12px; }
.modal-backdrop .convert-list {
    background: var(--surface-alt);
    border: 1px solid var(--border);
    padding: 12px 14px;
    margin: 12px 0;
    border-radius: var(--radius);
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.7;
}
.modal-backdrop .convert-list strong { color: var(--primary); font-weight: 600; }
.modal-backdrop .modal-actions {
    margin-top: 16px;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

/* Auto-bubble modal */
.ab-scope, .ab-options {
    display: flex; flex-direction: column; gap: 6px;
    margin-bottom: 12px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    padding: 12px 14px;
    border-radius: var(--radius);
}
.ab-radio, .ab-check {
    display: flex; align-items: center; gap: 8px;
    cursor: pointer;
    font-size: 13px;
    color: var(--text);
}
.ab-radio input, .ab-check input { cursor: pointer; }
.ab-progress-label {
    font-size: 14px; font-weight: 500;
    color: var(--text);
    margin-bottom: 10px;
}
.ab-progress-bar {
    height: 6px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: 999px;
    overflow: hidden;
}
.ab-progress-fill {
    height: 100%;
    width: 0%;
    background: var(--primary);
    border-radius: inherit;
    transition: width 0.18s;
}
.ab-preview {
    max-height: 320px;
    overflow-y: auto;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px;
    font-size: 12px;
}
.ab-preview-row {
    display: grid;
    grid-template-columns: 28px 1fr 70px;
    gap: 8px;
    align-items: center;
    padding: 5px 8px;
    border-radius: 4px;
}
.ab-preview-row:hover { background: var(--surface); }
.ab-preview-row .ab-num {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-weight: 600;
    color: var(--primary);
    text-align: center;
}
.ab-preview-row .ab-text {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ab-preview-row .ab-conf {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 999px;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 600;
}
.ab-conf-high { background: var(--success-bg, #d1fae5); color: var(--success, #047857); }
.ab-conf-med  { background: var(--warn-bg, #fef3c7);    color: var(--warn, #b45309); }
.ab-conf-low  { background: var(--danger-bg, #fee2e2);  color: var(--danger, #b91c1c); }
/* Parts-row indicator for auto-bubble */
.parts-row .ab-badge {
    display: inline-block;
    font-size: 9px;
    padding: 1px 5px;
    margin-left: 4px;
    border-radius: 3px;
    background: var(--info-bg, #e0e7ff);
    color: var(--info, #3730a3);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    vertical-align: middle;
}
.parts-row .cell-badge {
    display: inline-block;
    font-size: 9px;
    padding: 1px 5px;
    margin-left: 4px;
    border-radius: 3px;
    background: #f3f4f6;
    color: #374151;
    font-weight: 600;
    font-family: 'JetBrains Mono', monospace;
    border: 1px solid #d1d5db;
    vertical-align: middle;
}


/* Progress overlay */
.progress-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    backdrop-filter: blur(2px);
}
.progress-overlay.visible { display: flex; }
.progress-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 28px 36px;
    min-width: 300px;
    text-align: center;
    box-shadow: var(--shadow-lg);
}
.progress-box .pulse {
    width: 24px; height: 24px;
    border: 2px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    margin: 0 auto 14px;
    animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.progress-box .progress-label {
    color: var(--primary);
    font-size: 12px;
    letter-spacing: 0.1em;
    margin-bottom: 6px;
    font-weight: 700;
    text-transform: uppercase;
}
.progress-box .progress-detail {
    color: var(--text-muted);
    font-size: 12px;
}
.progress-bar {
    margin-top: 14px;
    height: 4px;
    background: var(--surface-alt);
    border-radius: 2px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: var(--primary);
    width: 0%;
    transition: width 0.2s ease;
}

/* Footer status bar — flat row below canvas card */
.status-strip {
    display: flex;
    gap: 18px;
    padding: 8px 12px;
    background: var(--surface);
    border-top: 1px solid var(--border);
    border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    font-size: 11px;
    color: var(--text-muted);
    letter-spacing: 0.05em;
}
.status-strip .stat {
    display: flex;
    gap: 5px;
    align-items: baseline;
}
.status-strip .stat label {
    text-transform: uppercase;
    font-weight: 600;
    font-size: 10px;
    letter-spacing: 0.08em;
}
.status-strip .stat span { color: var(--primary); font-variant-numeric: tabular-nums; font-weight: 600; }
.status-strip .right { margin-left: auto; color: var(--success); font-weight: 600; }






  /* MagDyn embed mode (iframed inside /tools.php?tool=bubble). The bubble
     tool's own inner sidebar stays visible (it has the layers/pages/tools
     panel which is essential), but the cross-tool "Switch tool" dropdown
     is hidden since the MagDyn sidebar already handles app switching. */
  html.embed-mode .apps-menu-wrap { display: none; }
</style>
<script>
  (function () {
    try {
      var p = new URLSearchParams(window.location.search);
      if (p.get('embed') === '1') {
        document.documentElement.classList.add('embed-mode');
      }
    } catch (e) {}
  })();
</script>
<?php
// Template-integration mode: when the inspection template editor
// launches the bubble tool with ?template_session=<token>, we expose
// the session token + CSRF + return URL to the JS so it can preload
// the staged drawing and POST bubbles back. The PHP side validates
// the token belongs to this user via session.
//
// URLs are absolute (via url() which prepends the configured base_url)
// rather than relative — eliminates the chance of misresolution when
// the tool is reached through an unusual referrer chain.
$tplSession = trim((string)($_GET['template_session'] ?? ''));
$tplCancelUrl = '';
$tplMaxBubbleNo = 0;
$tplStartingNumber = 1;
if ($tplSession !== '') {
    // Read the launch stash to learn which template the user was
    // editing, so the Cancel button can navigate straight back.
    // (Session is already started by bootstrap.) If the stash has
    // expired or doesn't match, we fall back to the templates list.
    $_launchStash = $_SESSION['magdyn_tpl_bubble'][$tplSession] ?? null;
    if ($_launchStash && ($_launchStash['kind'] ?? '') === 'launch') {
        $_tid = (int)($_launchStash['template_id'] ?? 0);
        $tplCancelUrl = $_tid > 0
            ? url('/inspection.php?action=template_edit&id=' . $_tid)
            : url('/inspection.php?action=template_new');
        // Carry forward the highest bubble number the editor already
        // knows about so the bubble tool starts numbering after it.
        // This prevents duplicate numbers across multiple round-trips
        // ("save bubbles → re-open bubble tool with another drawing").
        $tplMaxBubbleNo = (int)($_launchStash['max_bubble_no'] ?? 0);
    } else {
        $tplCancelUrl = url('/inspection.php?action=templates');
    }
    $tplStartingNumber = max(1, $tplMaxBubbleNo + 1);
}
if ($tplSession !== ''): ?>
<script>
  window.MAGDYN_TPL_BUBBLE = {
    token:            <?= json_encode($tplSession) ?>,
    drawing_url:      <?= json_encode(url('/inspection.php?action=template_bubble_drawing&token=' . urlencode($tplSession))) ?>,
    return_url:       <?= json_encode(url('/inspection.php?action=template_bubble_return')) ?>,
    cancel_url:       <?= json_encode($tplCancelUrl) ?>,
    csrf_token:       <?= json_encode(csrf_token()) ?>,
    // Numbering hand-off from the template editor. The bubble tool will
    // set its "next number" input to (max + 1) on init so the user can't
    // accidentally create duplicate bubble numbers across re-launches.
    max_bubble_no:    <?= (int)$tplMaxBubbleNo ?>,
    starting_number:  <?= (int)$tplStartingNumber ?>,
    // Hint for the JS: we're in template mode. Hide CSV/PNG/PDF exports
    // and show a "Save to template" button instead.
    mode: 'template'
  };
  console.log('[bubble-template-mode] PHP emitted MAGDYN_TPL_BUBBLE; '
    + 'starting_number=' + window.MAGDYN_TPL_BUBBLE.starting_number);
</script>
<?php endif; ?>
</head>

<body>

<div class="layout">

<!-- ============ SIDEBAR (tools + style settings) ============ -->
<aside class="sidebar">
        
    <?php include 'includes/apps-menu.php'; ?>
    <div class="brand">
        <div class="brand-mark"><div style="width:24px;height:24px;border-radius:50%;background:var(--primary);box-shadow:inset 0 0 0 3px var(--sidebar-bg);"></div></div>
        <div class="brand-text">
            <div class="brand-title">Bubble</div>
            <div class="brand-sub">Drawing annotator</div>
        </div>
    </div>

    <div class="nav">
        <div class="tool-nav-section">Drawing</div>
        <div class="upload-zone" id="upload-zone">
            <div class="ico">⊕</div>
            <p>Click or drop file<br>PNG · JPG · SVG · PDF · CGM</p>
            <input type="file" id="file-input" accept="image/*,application/pdf,.pdf,.cgm" style="display:none">
        </div>

        <div class="tool-nav-section">Tool</div>
        <div class="tool-grid">
            <button class="tool-btn active" data-tool="bubble" title="B">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="6"/><text x="12" y="15" text-anchor="middle" font-size="7" fill="currentColor" stroke="none">1</text></svg>
                Bubble
            </button>
            <button class="tool-btn" data-tool="redact" title="X">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="8" width="16" height="8" fill="currentColor" stroke="none"/><path d="M2 6l4 12M22 6l-4 12" stroke-width="1"/></svg>
                Redact
            </button>
            <button class="tool-btn" data-tool="text" title="T">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 5h14M12 5v14M9 19h6"/></svg>
                Text
            </button>
            <button class="tool-btn" data-tool="pan" title="H">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11V5a2 2 0 014 0v6M9 11V8a2 2 0 00-4 0v6c0 4 3 7 7 7s7-3 7-7v-3a2 2 0 00-4 0v1m-2-3v-1a2 2 0 014 0v3"/></svg>
                Pan
            </button>
            <button class="tool-btn" data-tool="delete" title="D" style="grid-column: span 2;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                Delete
            </button>
        </div>

        <div class="tool-nav-section">Bubble Shape</div>
        <div class="shape-row">
            <button class="shape-btn active" data-shape="circle" title="Circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="8"/></svg></button>
            <button class="shape-btn" data-shape="hexagon" title="Hexagon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12,4 20,8 20,16 12,20 4,16 4,8"/></svg></button>
            <button class="shape-btn" data-shape="square" title="Square"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16"/></svg></button>
            <button class="shape-btn" data-shape="diamond" title="Diamond"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12,3 21,12 12,21 3,12"/></svg></button>
        </div>

        <div class="tool-nav-section">Redaction</div>
        <div class="shape-row row-2">
            <button class="shape-btn active" data-redact-style="white" title="White-out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="6" width="16" height="12" fill="#ffffff" stroke="currentColor"/></svg></button>
            <button class="shape-btn" data-redact-style="black" title="Black bar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="6" width="16" height="12" fill="#0f172a" stroke="currentColor"/></svg></button>
        </div>
        <p style="padding:0 12px;font-size:10px;color:var(--sidebar-text-very-dim);margin-top:6px;line-height:1.5;">Visual only. Does not delete source data — unsuitable for legal redaction.</p>

        <div class="tool-nav-section">Style</div>
        <div class="sidebar-field">
            <label>Bubble Size</label>
            <div class="slider-row">
                <input type="range" id="bubble-size" min="8" max="48" value="12">
                <span class="val" id="bubble-size-val">12</span>
            </div>
        </div>
        <div class="sidebar-field">
            <label>Stroke Weight</label>
            <div class="slider-row">
                <input type="range" id="stroke-width" min="1" max="6" value="2" step="0.5">
                <span class="val" id="stroke-width-val">2</span>
            </div>
        </div>
        <div class="sidebar-field">
            <label>Fill Color</label>
            <input type="color" id="fill-color" value="#ffff00">
        </div>
        <div class="sidebar-field">
            <label>Bubble Opacity</label>
            <div class="slider-row">
                <input type="range" id="fill-opacity" min="0" max="100" value="60">
                <span class="val" id="fill-opacity-val">60%</span>
            </div>
        </div>
        <div class="sidebar-field">
            <label>Text Color</label>
            <input type="color" id="text-color" value="#000000">
        </div>
        <div class="sidebar-field">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:500;">
                <input type="checkbox" id="show-ref-rects" checked style="margin:0;">
                <span>Show reference rectangles</span>
            </label>
        </div>

        <div class="tool-nav-section">Numbering</div>
        <div class="sidebar-field">
            <label>Next Number</label>
            <input type="number" id="next-number" min="1" value="1">
        </div>
        <div class="sidebar-field">
            <label>Prefix (optional)</label>
            <input type="text" id="prefix" placeholder="e.g. A-">
        </div>

        <div class="tool-nav-section">View</div>
        <div class="sidebar-field">
            <label>Zoom</label>
            <div class="slider-row">
                <input type="range" id="zoom" min="10" max="500" value="100">
                <span class="val" id="zoom-val">100%</span>
            </div>
            <div class="mini-row">
                <button class="mini-btn" id="zoom-out" title="Zoom out (−)">−</button>
                <button class="mini-btn" id="zoom-fit" title="Fit (F)">FIT</button>
                <button class="mini-btn" id="zoom-100" title="100% (0)">1:1</button>
                <button class="mini-btn" id="zoom-in" title="Zoom in (+)">+</button>
            </div>
        </div>
        <div class="sidebar-field">
            <label>Rotation</label>
            <div class="slider-row">
                <input type="range" id="rotate" min="-180" max="180" value="0" step="1">
                <span class="val" id="rotate-val">0°</span>
            </div>
            <div class="mini-row">
                <button class="mini-btn" id="rot-ccw" title="−90° ([)">↺ 90°</button>
                <button class="mini-btn" id="rot-reset" title="Reset (R)">RESET</button>
                <button class="mini-btn" id="rot-cw" title="+90° (])">90° ↻</button>
            </div>
        </div>

        <div class="tool-nav-section">Calculator</div>
        <div class="sidebar-field">
            <label>Quick unit (mm ↔ in)</label>
            <div class="qc-unit-row">
                <input type="number" id="qc-mm" step="any" placeholder="mm" title="Type mm">
                <span class="qc-arrow">↔</span>
                <input type="number" id="qc-in" step="any" placeholder="in" title="Type inches">
            </div>
        </div>
        <div class="sidebar-field">
            <label>Quick expression</label>
            <div class="qc-expr-row">
                <input type="text" id="qc-expr" placeholder="e.g. (25.4*2)+0.5" autocomplete="off">
                <button class="mini-btn" id="qc-expr-go" title="Evaluate (Enter)">=</button>
            </div>
            <div class="qc-result" id="qc-expr-result">—</div>
        </div>
        <div class="sidebar-field">
            <button class="btn-small qc-open-full" id="qc-open-full" title="Open the full calculator in a popup window">
                <span style="font-size:14px;">∑</span> Open full calculator
            </button>
        </div>
    </div>
</aside>

<!-- ============ MAIN ============ -->
<div class="main">

    <div class="page-head">
        <div>
            <h1>Engineering Drawing Annotator</h1>
            <p class="muted small">Bubble drawings · build inspection lists · export annotated PDFs</p>
        </div>
        <div class="head-actions">
            <button class="btn" id="btn-pages" title="Page manager (PDF only)">Pages</button>
            <button class="btn" id="btn-auto-bubble" title="Detect dimensions and create bubbles automatically (PDF only)" disabled>Auto-bubble</button>
            <button class="btn btn-ghost" id="btn-clear">Clear</button>
            <button class="btn" id="btn-export-csv">Export CSV</button>
            <button class="btn" id="btn-export-png">Export PNG</button>
            <button class="btn btn-primary" id="btn-export-pdf">Export PDF</button>
        </div>
    </div>

    <div class="bubble-body">

        <!-- ============ Canvas ============ -->
        <div class="stage-card">
            <div class="stage">
                <div class="stage-corner tl">Sheet 01</div>
                <div class="stage-corner tr">Scale variable</div>
                <div class="stage-corner bl" id="coord-display">X: — &nbsp; Y: —</div>
                <div class="stage-corner br">Rev A</div>

                <div id="drawing-container" class="tool-bubble">
                    <div class="canvas-wrap" id="canvas-wrap">
                        <div class="empty-state" id="empty-state">
                            <div class="big">No drawing loaded</div>
                            <div class="sub">Upload an image or PDF from the sidebar to begin annotation.</div>
                        </div>
                        <img id="drawing-img" style="display:none;" alt="Drawing">
                        <svg id="annotations" xmlns="http://www.w3.org/2000/svg"></svg>
                    </div>
                </div>

                <div class="page-nav" id="page-nav">
                    <button id="page-prev" title="Previous page">‹</button>
                    <div class="label">Page <span class="accent" id="page-current">1</span> / <span id="page-total">1</span></div>
                    <button id="page-next" title="Next page">›</button>
                </div>
            </div>

            <div class="status-strip">
                <div class="stat"><label>Bubbles</label><span id="footer-count">0</span></div>
                <div class="stat"><label>Redact</label><span id="footer-redact-count">0</span></div>
                <div class="stat"><label>Text</label><span id="footer-text-count">0</span></div>
                <div class="stat"><label>Tool</label><span id="footer-tool">BUBBLE</span></div>
                <div class="stat"><label>Shape</label><span id="footer-shape">CIRCLE</span></div>
                <div class="stat"><label>Zoom</label><span id="footer-zoom">100%</span></div>
                <div class="stat"><label>Rot</label><span id="footer-rot">0°</span></div>
                <div class="right">Ready</div>
            </div>
        </div>

        <!-- ============ Parts list ============ -->
        <aside class="parts-card">
            <div class="parts-head">
                <h2>Parts List / BOM</h2>
                <div class="count" id="parts-count">0 items</div>
            </div>
            <div class="parts-search-row">
                <input type="text" id="parts-search" placeholder="Search number, description, dimension, cell…" class="parts-search-input">
                <button class="parts-search-clear" id="parts-search-clear" title="Clear search">×</button>
            </div>
            <div class="parts-table" id="parts-table">
                <div class="parts-empty">No bubbles yet.<br>Click on the drawing to add.</div>
            </div>
            <div class="parts-foot">
                <button class="btn btn-sm" id="btn-renumber">Renumber</button>
                <button class="btn btn-sm" id="btn-clear-bubbles">Clear All</button>
            </div>
        </aside>

    </div>
</div>

</div><!-- /.layout -->

<!-- ============ Modals ============ -->
<div class="modal-backdrop" id="cgm-modal">
    <div class="modal">
        <h2>CGM file detected</h2>
        <p>Computer Graphics Metafile (.cgm) is a vector format used by AutoCAD, CATIA, IsoDraw, and S1000D-compliant manuals. Browser-based rendering of CGM requires a commercial viewer license, so this tool cannot display CGM files directly.</p>
        <p class="dim">However, every CAD package that exports CGM also exports formats this tool fully supports:</p>
        <div class="convert-list">
            <strong>→ PDF</strong> &nbsp; AutoCAD: PLOT to PDF · CATIA: File › Print › PDF<br>
            <strong>→ SVG</strong> &nbsp; IsoDraw: Export › SVG · Inkscape can open CGM too<br>
            <strong>→ PNG</strong> &nbsp; Any CAD viewer: print or screenshot at high DPI
        </div>
        <p class="dim">Once converted, drop the resulting file here and you can annotate as normal.</p>
        <div class="modal-actions">
            <button class="btn btn-primary" id="cgm-modal-close">Got it</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="pages-modal">
    <div class="modal modal-wide">
        <h2>Page Manager</h2>
        <p class="dim" style="margin-bottom: 14px;">Drag pages to reorder. Use the icons to delete, duplicate, or rotate individual pages. Changes apply to PDF exports — the source file on disk is unchanged.</p>
        <div class="page-grid" id="page-grid"></div>
        <div class="modal-actions">
            <button class="btn" id="pages-modal-reset">Reset to original</button>
            <button class="btn btn-primary" id="pages-modal-close">Done</button>
        </div>
    </div>
</div>

<!-- ============ AUTO-BUBBLE MODAL ============ -->
<div class="modal-backdrop" id="ab-modal">
    <div class="modal">
        <h2 id="ab-modal-title">Auto-bubble dimensions</h2>
        <div id="ab-stage-intro">
            <p class="dim" style="margin-bottom:8px;">Detect dimensions in this drawing and place bubbles automatically. Existing manual bubbles are preserved.</p>
            <p class="dim" style="margin-bottom:14px;">For born-digital PDFs (exported from CAD), PDF.js extracts the text layer instantly. When OCR is enabled, Tesseract runs in parallel on the rasterized page to also catch baked-in annotations and stamps — first OCR run downloads a ~3MB engine. When both engines see the same text, the OCR reading is kept as an alternate the operator can swap to per-bubble.</p>
            <div class="ab-scope">
                <label class="ab-radio"><input type="radio" name="ab-scope" value="current" checked> <span>Current page only</span></label>
                <label class="ab-radio"><input type="radio" name="ab-scope" value="all"> <span>All pages</span></label>
            </div>
            <div class="ab-options">
                <label class="ab-check"><input type="checkbox" id="ab-allow-ocr" checked> <span>Run OCR in parallel with PDF.js (catches raster annotations; both readings preserved per bubble for review)</span></label>
                <label class="ab-check"><input type="checkbox" id="ab-skip-title-block" checked> <span>Skip title-block region (bottom-right ~25%)</span></label>
                <label class="ab-check"><input type="checkbox" id="ab-bracketed" checked> <span>Also bubble dual-unit [bracketed] values (numbered with <code>.a</code> suffix)</span></label>
                <label class="ab-check"><input type="checkbox" id="ab-notes"> <span>Treat numbered NOTES list as separate bubbles</span></label>
                <label class="ab-check"><input type="checkbox" id="ab-clockwise" checked> <span>Number bubbles in clockwise sweep order (from 12 o'clock)</span></label>
                <label class="ab-check"><input type="checkbox" id="ab-rulers" checked> <span>Show A–D / 1–N rulers and capture bubble grid cell</span></label>
                <div style="margin-top:8px; display:flex; gap:10px; align-items:center; font-size:12px;">
                    <span style="color:var(--text-light);">Grid:</span>
                    <select id="ab-grid-rows" style="padding:3px 6px; font-size:12px;">
                        <option value="4">4 rows (A–D)</option>
                        <option value="6">6 rows (A–F)</option>
                        <option value="8">8 rows (A–H)</option>
                    </select>
                    <span style="color:var(--text-light);">×</span>
                    <select id="ab-grid-cols" style="padding:3px 6px; font-size:12px;">
                        <option value="4">4 cols (1–4)</option>
                        <option value="6">6 cols (1–6)</option>
                        <option value="8">8 cols (1–8)</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn" id="ab-cancel-1">Cancel</button>
                <button class="btn btn-primary" id="ab-start">Detect dimensions</button>
            </div>
        </div>

        <div id="ab-stage-progress" style="display:none;">
            <div class="ab-progress-label" id="ab-progress-label">Extracting text from PDF…</div>
            <div class="ab-progress-bar"><div class="ab-progress-fill" id="ab-progress-fill"></div></div>
            <p class="dim" id="ab-progress-detail" style="font-size:12px;margin-top:8px;">Reading PDF text layer…</p>
        </div>

        <div id="ab-stage-result" style="display:none;">
            <p id="ab-result-summary" style="margin-bottom:10px;font-weight:500;"></p>
            <p class="dim" id="ab-result-detail" style="margin-bottom:14px;font-size:12.5px;"></p>
            <div class="ab-preview" id="ab-preview"></div>
            <div class="modal-actions" style="margin-top:14px;">
                <button class="btn" id="ab-discard">Discard, undo</button>
                <button class="btn btn-primary" id="ab-accept">Keep bubbles</button>
            </div>
        </div>
    </div>
</div>


<div class="progress-overlay" id="progress-overlay">
    <div class="progress-box">
        <div class="pulse"></div>
        <div class="progress-label" id="progress-label">Exporting PDF</div>
        <div class="progress-detail" id="progress-detail">Preparing…</div>
        <div class="progress-bar"><div class="progress-bar-fill" id="progress-bar-fill"></div></div>
    </div>
</div>

<!-- ============ Main script ============ -->
<!-- (script content gets injected by the build step below) -->
<script>
(function() {
  'use strict';

  // ============ PDF.JS WORKER ============
  // pdf.js 3.x requires GlobalWorkerOptions.workerSrc to be set before any
  // getDocument() call. Without it, loading silently fails ("Deprecated API
  // usage: No 'GlobalWorkerOptions.workerSrc' specified") and the document
  // promise never resolves — which breaks PDF loading, which is why pan /
  // zoom / everything appeared broken (there was nothing to manipulate).
  // The CDN_VER is pinned to match the script tag in the head.
  if (window.pdfjsLib && !pdfjsLib.GlobalWorkerOptions.workerSrc) {
    pdfjsLib.GlobalWorkerOptions.workerSrc =
      'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
  }

  // ============ STATE ============
  const state = {
    bubbles: [],          // bubbles for the CURRENT page only
    pageBubbles: {},      // map pageNum -> bubble array (for PDF multi-page)
    nextId: 1,
    tool: 'bubble',
    shape: 'circle',
    bubbleSize: 12,
    strokeWidth: 2,
    fillColor: '#ffff00',
    fillOpacity: 0.6,
    textColor: '#000000',
    gridRows: 4,
    gridCols: 4,
    showRulers: false,
    showRefRects: true,
    partsSearch: '',
    prefix: '',
    zoom: 1,
    rotation: 0,          // degrees, applied to canvas-wrap
    panX: 0,              // translation in screen pixels (post-scale)
    panY: 0,
    drawing: { loaded: false, w: 0, h: 0, type: null, baseW: 0, baseH: 0 }, // baseW/H = fitted display size at zoom=1
    pdf: { doc: null, page: 1, total: 1 },
    selected: null,
    expanded: new Set(),
    drag: null,
    // Redactions (visual only)
    redactions: [],         // current page's redactions: {id, x, y, w, h, style}
    pageRedactions: {},     // map pageNum -> redactions array (PDF mode)
    redactStyle: 'white',   // 'white' | 'black'
    redactDraw: null,       // {startX, startY, x, y, w, h} while dragging out a new one
    redactSelected: null,
    redactDrag: null,       // {type: 'move'|'resize', id, dx, dy} for moving/resizing
    // Text annotations
    texts: [],              // current page's text annotations: {id, x, y, content, fontSize, color}
    pageTexts: {},          // map pageNum -> texts array (PDF mode)
    textSelected: null,
    textDrag: null,
    // Page manager (PDF only): user-applied page operations
    pageOrder: null,        // null = use original order; otherwise array of source-page indices (0-based) in desired order
    pageDeleted: {},        // map source-page-num (1-based) -> true if deleted
  };

  // ============ DOM ============
  const $ = id => document.getElementById(id);
  const drawingContainer = $('drawing-container');
  const canvasWrap = $('canvas-wrap');
  const drawingImg = $('drawing-img');
  const annotationsSvg = $('annotations');
  const emptyState = $('empty-state');
  const partsTable = $('parts-table');

  // ============ TOOL SELECTION ============
  document.querySelectorAll('.tool-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      state.tool = btn.dataset.tool;
      drawingContainer.className = 'tool-' + state.tool;
      $('footer-tool').textContent = state.tool.toUpperCase();
    });
  });

  // ============ SHAPE SELECTION ============
  document.querySelectorAll('.shape-btn[data-shape]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.shape-btn[data-shape]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      state.shape = btn.dataset.shape;
      $('footer-shape').textContent = state.shape.toUpperCase();
    });
  });

  // ============ REDACTION STYLE SELECTION ============
  document.querySelectorAll('.shape-btn[data-redact-style]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.shape-btn[data-redact-style]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      state.redactStyle = btn.dataset.redactStyle;
    });
  });

  // ============ STYLE INPUTS ============
  // Helper: apply a per-bubble property update to all existing bubbles
  // on the current page. Used by the live style sliders so changes are
  // visible immediately across the whole drawing.
  function applyStyleToAllBubbles(updater) {
    state.bubbles.forEach(b => updater(b));
    syncPage();
    render();
  }

  $('bubble-size').addEventListener('input', e => {
    state.bubbleSize = parseInt(e.target.value);
    $('bubble-size-val').textContent = e.target.value;
    applyStyleToAllBubbles(b => { b.size = state.bubbleSize; });
  });
  $('stroke-width').addEventListener('input', e => {
    state.strokeWidth = parseFloat(e.target.value);
    $('stroke-width-val').textContent = e.target.value;
    applyStyleToAllBubbles(b => { b.stroke = state.strokeWidth; });
  });
  $('fill-color').addEventListener('input', e => {
    state.fillColor = e.target.value;
    applyStyleToAllBubbles(b => { b.fill = state.fillColor; });
  });
  $('fill-opacity').addEventListener('input', e => {
    const v = parseInt(e.target.value);
    state.fillOpacity = v / 100;
    $('fill-opacity-val').textContent = v + '%';
    applyStyleToAllBubbles(b => { b.fillOpacity = state.fillOpacity; });
  });
  $('text-color').addEventListener('input', e => {
    state.textColor = e.target.value;
    applyStyleToAllBubbles(b => { b.textColor = state.textColor; });
  });
  $('show-ref-rects').addEventListener('change', e => {
    state.showRefRects = e.target.checked;
    render();
  });

  // Parts list search — filters the rendered rows live as the user types.
  $('parts-search').addEventListener('input', e => {
    state.partsSearch = e.target.value;
    renderParts();
  });
  $('parts-search-clear').addEventListener('click', () => {
    $('parts-search').value = '';
    state.partsSearch = '';
    renderParts();
    $('parts-search').focus();
  });

  // ============ QUICK CALCULATOR (sidebar widgets) ============
  // Three small tools that live alongside the bubble workflow:
  //   1. mm ↔ in instant converter (most-asked inspection conversion)
  //   2. expression bar that evaluates basic arithmetic with the standard
  //      scientific functions, useful when filling tolerance fields
  //   3. button that opens the full standalone calculator in a popup window
  //
  // Kept lightweight on purpose — the full multi-tab calculator is its own
  // page; embedding 2000 lines here would slow the bubble tool unnecessarily.

  // 1. Bi-directional mm ↔ in. Typing into either side fills the other.
  $('qc-mm').addEventListener('input', () => {
    const v = parseFloat($('qc-mm').value);
    $('qc-in').value = isFinite(v) ? Number((v / 25.4).toPrecision(8)) : '';
  });
  $('qc-in').addEventListener('input', () => {
    const v = parseFloat($('qc-in').value);
    $('qc-mm').value = isFinite(v) ? Number((v * 25.4).toPrecision(8)) : '';
  });

  // 2. Expression evaluator. We restrict the input to safe math characters
  //    and provide the standard scientific functions in scope. The implementation
  //    intentionally mirrors the full calculator's sciEval — same operator set
  //    and behaviour — so the muscle memory transfers.
  function qcEvalExpression(raw) {
    if (!raw || !raw.trim()) return { ok: false, msg: '' };
    // Normalise the same display chars the full calculator accepts
    let s = raw
      .replace(/π/g, '(' + Math.PI + ')')
      .replace(/(?<![A-Za-z])e(?![A-Za-z\d])/g, '(' + Math.E + ')')
      .replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-')
      .replace(/\^/g, '**')
      .replace(/√/g, 'Math.sqrt')
      .replace(/\bsin\b/g,  '_sin').replace(/\bcos\b/g,  '_cos').replace(/\btan\b/g,  '_tan')
      .replace(/\basin\b/g, '_asin').replace(/\bacos\b/g, '_acos').replace(/\batan\b/g, '_atan')
      .replace(/\blog\b/g,  'Math.log10').replace(/\bln\b/g,   'Math.log')
      .replace(/\babs\b/g,  'Math.abs').replace(/\bsqrt\b/g, 'Math.sqrt');

    // Safety: only allow math characters
    if (!/^[\d+\-*/().,\s%a-zA-Z_]*$/.test(s)) {
      return { ok: false, msg: 'Invalid characters' };
    }
    try {
      // Default to degrees for trig (matches the full calculator's default)
      const k = Math.PI / 180;
      const _sin  = x => Math.sin(x * k);
      const _cos  = x => Math.cos(x * k);
      const _tan  = x => Math.tan(x * k);
      const _asin = x => Math.asin(x) / k;
      const _acos = x => Math.acos(x) / k;
      const _atan = x => Math.atan(x) / k;
      // eslint-disable-next-line no-new-func
      const fn = new Function('_sin','_cos','_tan','_asin','_acos','_atan',
        'return (' + s + ');');
      const v = fn(_sin, _cos, _tan, _asin, _acos, _atan);
      if (!isFinite(v)) return { ok: false, msg: 'Not a finite number' };
      // Concise formatting consistent with other readouts in this app
      const out = Math.abs(v) >= 1e7 || (v !== 0 && Math.abs(v) < 1e-4)
        ? v.toExponential(6)
        : Number(v.toPrecision(10)).toString();
      return { ok: true, value: out };
    } catch (err) {
      return { ok: false, msg: 'Syntax error' };
    }
  }

  function qcRunExpression() {
    const raw = $('qc-expr').value;
    const res = qcEvalExpression(raw);
    const out = $('qc-expr-result');
    out.classList.remove('error', 'success');
    if (!raw.trim()) {
      out.textContent = '—';
      return;
    }
    if (res.ok) {
      out.textContent = '= ' + res.value;
      out.classList.add('success');
    } else {
      out.textContent = res.msg || '—';
      if (res.msg) out.classList.add('error');
    }
  }

  // Live preview while typing (no commit — just shows the result)
  $('qc-expr').addEventListener('input', qcRunExpression);
  // Enter = explicit evaluate (no different from typing, but feels natural)
  $('qc-expr').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      qcRunExpression();
    }
  });
  $('qc-expr-go').addEventListener('click', qcRunExpression);

  // 3. Open the full standalone calculator in a popup window. We use
  //    window.open with sized features so it lands as a real secondary
  //    window rather than a new tab — better for side-by-side workflow.
  //    Falls back to a normal new tab if the popup is blocked.
  $('qc-open-full').addEventListener('click', () => {
    const w = Math.min(1400, Math.max(900, Math.floor(window.screen.availWidth * 0.7)));
    const h = Math.min(900, Math.max(700, Math.floor(window.screen.availHeight * 0.8)));
    const left = Math.floor((window.screen.availWidth - w) / 2);
    const top  = Math.floor((window.screen.availHeight - h) / 2);
    const popup = window.open(
      'engineering-calculator.php',
      'engCalcPopup',
      'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
    );
    if (popup) popup.focus();
    // If popup blocked, the click target was 'engCalcPopup' — browsers may
    // fall back to a new tab; either way the user sees the calculator.
  });
  $('next-number').addEventListener('input', e => { state.nextId = parseInt(e.target.value) || 1; });
  $('prefix').addEventListener('input', e => { state.prefix = e.target.value; render(); renderParts(); });
  $('zoom').addEventListener('input', e => {
    state.zoom = parseInt(e.target.value) / 100;
    applyTransform();
  });
  $('rotate').addEventListener('input', e => {
    state.rotation = parseInt(e.target.value);
    applyTransform();
  });

  let lastRenderedRotation = 0;
  function applyTransform() {
    // Order matters: translate FIRST (in screen pixels), then scale + rotate
    // around the center. This way panning works at any zoom level without
    // the pan distance being scaled.
    canvasWrap.style.transform =
        `translate(${state.panX}px, ${state.panY}px) ` +
        `scale(${state.zoom}) rotate(${state.rotation}deg)`;
    const zoomPct = Math.round(state.zoom * 100);
    $('zoom').value = zoomPct;
    $('zoom-val').textContent = zoomPct + '%';
    $('rotate').value = state.rotation;
    $('rotate-val').textContent = state.rotation + '°';
    const fz = $('footer-zoom'); if (fz) fz.textContent = zoomPct + '%';
    const fr = $('footer-rot'); if (fr) fr.textContent = state.rotation + '°';
    // Re-render bubbles so the counter-rotation transform on number text
    // matches the current rotation
    if (state.rotation !== lastRenderedRotation) {
      lastRenderedRotation = state.rotation;
      if (state.drawing.loaded) render();
    }
  }

  function resetPan() {
    state.panX = 0;
    state.panY = 0;
    applyTransform();
  }

  // Zoom buttons
  function setZoom(z, opts = {}) {
    state.zoom = Math.max(0.1, Math.min(5, z));
    applyTransform();
  }
  $('zoom-in').addEventListener('click', () => setZoom(state.zoom * 1.25));
  $('zoom-out').addEventListener('click', () => setZoom(state.zoom / 1.25));
  $('zoom-100').addEventListener('click', () => { resetPan(); setZoom(1); });
  $('zoom-fit').addEventListener('click', () => { resetPan(); fitToScreen(); });

  function fitToScreen() {
    if (!state.drawing.loaded) return;
    // Use base display size; account for rotation (swap w/h for 90/270)
    const rot = ((state.rotation % 360) + 360) % 360;
    const isQuarter = (Math.abs(rot - 90) < 1 || Math.abs(rot - 270) < 1);
    const w = isQuarter ? state.drawing.baseH : state.drawing.baseW;
    const h = isQuarter ? state.drawing.baseW : state.drawing.baseH;
    const padding = 60;
    const availW = drawingContainer.clientWidth - padding;
    const availH = drawingContainer.clientHeight - padding;
    const z = Math.min(availW / w, availH / h, 5);
    setZoom(z);
  }

  // Rotation buttons
  function setRotation(deg) {
    // Normalize to (-180, 180]
    let r = ((deg + 180) % 360 + 360) % 360 - 180;
    state.rotation = Math.round(r);
    applyTransform();
  }
  $('rot-cw').addEventListener('click', () => setRotation(state.rotation + 90));
  $('rot-ccw').addEventListener('click', () => setRotation(state.rotation - 90));
  $('rot-reset').addEventListener('click', () => setRotation(0));

  // Wheel zoom (centered on cursor)
  drawingContainer.addEventListener('wheel', e => {
    if (!state.drawing.loaded) return;
    e.preventDefault();
    const delta = -e.deltaY;
    const factor = delta > 0 ? 1.1 : 1 / 1.1;
    setZoom(state.zoom * factor);
  }, { passive: false });

  // ============ PAN ============
  // Active when state.tool === 'pan'. Click-drag inside the container
  // translates the canvas-wrap. Pan offset is independent of zoom (we apply
  // the translate in screen pixels before the scale).
  // Middle-mouse-drag pans regardless of which tool is active — same convention
  // as most CAD viewers. Spacebar+drag does the same.
  let panActive = false;
  let panStart = null;       // {clientX, clientY, panX0, panY0}
  let spaceHeld = false;

  function panShouldStart(e) {
    if (!state.drawing.loaded) return false;
    // Don't start a pan if the click was on an annotation handle —
    // the annotation drag handler runs first anyway, but be safe.
    if (e.target.closest('[data-role]')) return false;
    // Middle button always pans
    if (e.button === 1) return true;
    // Left button pans when the Pan tool is active, or while space is held
    if (e.button === 0 && (state.tool === 'pan' || spaceHeld)) return true;
    return false;
  }

  drawingContainer.addEventListener('mousedown', e => {
    if (!panShouldStart(e)) return;
    e.preventDefault();
    panActive = true;
    panStart = {
      clientX: e.clientX,
      clientY: e.clientY,
      panX0: state.panX,
      panY0: state.panY,
    };
    drawingContainer.classList.add('panning');
  });

  window.addEventListener('mousemove', e => {
    if (!panActive || !panStart) return;
    state.panX = panStart.panX0 + (e.clientX - panStart.clientX);
    state.panY = panStart.panY0 + (e.clientY - panStart.clientY);
    applyTransform();
  });

  window.addEventListener('mouseup', () => {
    if (!panActive) return;
    panActive = false;
    panStart = null;
    drawingContainer.classList.remove('panning');
  });

  // Spacebar hold = temporary pan tool (works regardless of currently
  // selected tool, like most drawing apps). Ignore when typing in inputs.
  window.addEventListener('keydown', e => {
    if (e.code === 'Space' && !spaceHeld) {
      const tag = (e.target && e.target.tagName) || '';
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
      e.preventDefault();
      spaceHeld = true;
      drawingContainer.classList.add('tool-pan');
    }
  });
  window.addEventListener('keyup', e => {
    if (e.code === 'Space' && spaceHeld) {
      spaceHeld = false;
      // Restore actual tool cursor (unless pan was the actual selection)
      if (state.tool !== 'pan') {
        drawingContainer.classList.remove('tool-pan');
      }
    }
  });

  // Touch pan: a one-finger drag while the pan tool is active.
  let touchPanStart = null;
  drawingContainer.addEventListener('touchstart', e => {
    if (state.tool !== 'pan' || !state.drawing.loaded) return;
    if (e.touches.length !== 1) return;
    const t = e.touches[0];
    touchPanStart = { clientX: t.clientX, clientY: t.clientY, panX0: state.panX, panY0: state.panY };
    drawingContainer.classList.add('panning');
  }, { passive: true });
  drawingContainer.addEventListener('touchmove', e => {
    if (!touchPanStart || e.touches.length !== 1) return;
    e.preventDefault();
    const t = e.touches[0];
    state.panX = touchPanStart.panX0 + (t.clientX - touchPanStart.clientX);
    state.panY = touchPanStart.panY0 + (t.clientY - touchPanStart.clientY);
    applyTransform();
  }, { passive: false });
  drawingContainer.addEventListener('touchend', () => {
    if (!touchPanStart) return;
    touchPanStart = null;
    drawingContainer.classList.remove('panning');
  });

  // ============ FILE UPLOAD ============
  const fileInput = $('file-input');
  const uploadZone = $('upload-zone');
  uploadZone.addEventListener('click', () => fileInput.click());
  uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('drag'); });
  uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag'));
  uploadZone.addEventListener('drop', e => {
    e.preventDefault();
    uploadZone.classList.remove('drag');
    if (e.dataTransfer.files[0]) loadFile(e.dataTransfer.files[0]);
  });
  fileInput.addEventListener('change', e => {
    if (e.target.files[0]) loadFile(e.target.files[0]);
  });

  function loadFile(file) {
    const name = file.name.toLowerCase();
    const isPdf = file.type === 'application/pdf' || name.endsWith('.pdf');
    const isCgm = name.endsWith('.cgm') || file.type === 'image/cgm';
    const isImage = file.type.startsWith('image/') && !isCgm;

    if (isCgm) {
      $('cgm-modal').classList.add('visible');
      fileInput.value = '';
      return;
    }

    // Append mode: if a PDF is already loaded and the user selects another
    // PDF, append the new pages instead of replacing. Bubbles on existing
    // pages are preserved; new pages get added to the end and are blank
    // and ready for bubbling. Image + image or any cross-type combination
    // is refused with a friendly explanation.
    if (state.drawing.loaded) {
      if (state.drawing.type === 'pdf' && isPdf) {
        appendPdf(file);
        return;
      }
      // Existing PDF + dropping an image, or existing image + anything:
      // refuse. Clearing and starting over is one click on "Clear" so the
      // user isn't stuck.
      var confirmReset = confirm(
        'A drawing is already loaded. Loading this file will replace it ' +
        'and discard all current bubbles.\n\n' +
        'Append (adding pages) only works when both files are PDFs.\n\n' +
        'Continue and replace?'
      );
      if (!confirmReset) return;
    }

    if (isPdf) { loadPdf(file); return; }
    if (isImage) { loadImage(file); return; }
    alert('Unsupported file type. Please upload PNG, JPG, SVG, or PDF.');
  }

  // Append a second (or third…) PDF to the currently loaded one. Pages
  // from the new PDF are added to the END of the page list, keeping the
  // global page numbering contiguous. All existing bubbles, redactions,
  // texts, and page-deletion flags are preserved because they're keyed
  // by global page number.
  async function appendPdf(file) {
    if (!window.pdfjsLib) {
      alert('PDF library failed to load. Refresh and try again.');
      return;
    }
    try {
      // First-time bootstrap: convert the single-source layout into the
      // multi-source layout. We only do this once, on first append.
      if (!state.pdf.sources) {
        state.pdf.sources = [{
          originalBytes: state.pdf.originalBytes,
          doc: state.pdf.doc,
          pageCount: state.pdf.total
        }];
      }

      const buf = await file.arrayBuffer();
      const bytesCopy = buf.slice(0);  // keep one copy for pdf-lib export
      const newDoc = await pdfjsLib.getDocument({ data: buf }).promise;
      state.pdf.sources.push({
        originalBytes: bytesCopy,
        doc: newDoc,
        pageCount: newDoc.numPages
      });

      // Persist the currently-shown page's annotations before re-rendering.
      // (renderPdfPage normally does this on switch, but appending doesn't
      // navigate — so do it explicitly.)
      if (state.drawing.type === 'pdf') {
        state.pageBubbles[state.pdf.page] = state.bubbles.slice();
        state.pageRedactions[state.pdf.page] = state.redactions.slice();
        state.pageTexts[state.pdf.page] = state.texts.slice();
      }

      state.pdf.total = state.pdf.sources.reduce(function (n, s) { return n + s.pageCount; }, 0);

      // If the page manager was previously opened, state.pageOrder exists
      // and is stale (missing the newly-appended pages). Extend it so the
      // exporter sees the full set. New pages go at the END, undeleted.
      if (state.pageOrder !== null) {
        var existingCount = state.pageOrder.length;
        for (var p = existingCount; p < state.pdf.total; p++) {
          state.pageOrder.push({ srcIdx: p, rotation: 0, deleted: false });
        }
      }

      $('page-total').textContent = state.pdf.total;
      $('page-nav').classList.toggle('visible', state.pdf.total >= 1);
      updatePageNavButtons();

      // Auto-navigate to the FIRST page of the newly-appended PDF so the
      // user sees feedback that the append worked. Without this nothing
      // visibly changes — the existing page stays on screen and the user
      // is left wondering "did it work?". Jumping to the new page makes
      // the action's result obvious.
      var firstNewPage = state.pdf.total - newDoc.numPages + 1;
      await renderPdfPage(firstNewPage);

      console.log('[bubble-tool] appended PDF: ' + file.name +
        ' (' + newDoc.numPages + ' pages); total now ' + state.pdf.total +
        '; jumped to page ' + firstNewPage);
    } catch (err) {
      console.error(err);
      alert('Failed to append PDF: ' + (err.message || 'unknown error'));
    }
    fileInput.value = '';
  }

  // Resolve a global 1-based page number to {doc, localPage} for the
  // multi-source PDF layout. In single-source mode (no append yet),
  // returns the legacy state.pdf.doc.
  function resolvePdfPage(globalPageNum) {
    if (!state.pdf.sources) {
      return { doc: state.pdf.doc, localPage: globalPageNum };
    }
    var remaining = globalPageNum;
    for (var i = 0; i < state.pdf.sources.length; i++) {
      var src = state.pdf.sources[i];
      if (remaining <= src.pageCount) {
        return { doc: src.doc, localPage: remaining, sourceIndex: i };
      }
      remaining -= src.pageCount;
    }
    return null;  // out of range
  }

  function loadImage(file) {
    const reader = new FileReader();
    reader.onload = ev => {
      drawingImg.onload = () => {
        // Clear any prior PDF state
        state.pdf.doc = null;
        state.pdf.sources = null;
        state.pdf.originalBytes = null;
        state.pdf.page = 1;
        state.pdf.total = 1;
        state.pageBubbles = {};
        state.pageRedactions = {};
        state.pageTexts = {};
        state.bubbles = [];
        state.redactions = [];
        state.texts = [];
        state.nextId = 1;
        $('next-number').value = 1;
        $('page-nav').classList.remove('visible');

        state.drawing.loaded = true;
        state.drawing.type = 'image';
        state.drawing.w = drawingImg.naturalWidth;
        state.drawing.h = drawingImg.naturalHeight;
        fitDrawingToStage(drawingImg.naturalWidth, drawingImg.naturalHeight);
        emptyState.style.display = 'none';
        drawingImg.style.display = 'block';
        render();
        renderParts();
        updateFooter();
      };
      drawingImg.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  }

  function fitDrawingToStage(natW, natH) {
    const maxW = drawingContainer.clientWidth - 60;
    const maxH = drawingContainer.clientHeight - 60;
    const scale = Math.min(maxW / natW, maxH / natH, 1);
    const dispW = natW * scale;
    const dispH = natH * scale;
    drawingImg.style.width = dispW + 'px';
    drawingImg.style.height = dispH + 'px';
    annotationsSvg.setAttribute('width', dispW);
    annotationsSvg.setAttribute('height', dispH);
    annotationsSvg.setAttribute('viewBox', `0 0 ${dispW} ${dispH}`);
    state.drawing.baseW = dispW;
    state.drawing.baseH = dispH;
    // Reset view transforms so a fresh drawing starts oriented correctly
    state.zoom = 1;
    state.rotation = 0;
    state.panX = 0;
    state.panY = 0;
    applyTransform();
  }

  // ============ PDF LOADING ============
  async function loadPdf(file) {
    if (!window.pdfjsLib) {
      alert('PDF library failed to load. Check your internet connection and refresh.');
      return;
    }
    try {
      const buf = await file.arrayBuffer();
      // Keep an extra copy for pdf-lib export (PDF.js consumes the buffer)
      state.pdf.originalBytes = buf.slice(0);
      const doc = await pdfjsLib.getDocument({ data: buf }).promise;
      // Reset annotation state for new document
      state.pageBubbles = {};
      state.pageRedactions = {};
      state.pageTexts = {};
      state.bubbles = [];
      state.redactions = [];
      state.texts = [];
      state.selected = null;
      state.redactSelected = null;
      state.textSelected = null;
      state.pageOrder = null;
      state.pageDeleted = {};
      state.nextId = 1;
      $('next-number').value = 1;

      state.pdf.doc = doc;
      state.pdf.sources = null;  // single-source until first append
      state.pdf.total = doc.numPages;
      state.pdf.page = 1;
      state.drawing.type = 'pdf';

      $('page-total').textContent = doc.numPages;
      $('page-nav').classList.toggle('visible', doc.numPages >= 1);
      updatePageNavButtons();
      await renderPdfPage(1);
      emptyState.style.display = 'none';
      drawingImg.style.display = 'block';
    } catch (err) {
      console.error(err);
      alert('Failed to load PDF: ' + (err.message || 'unknown error'));
    }
  }

  async function renderPdfPage(pageNum) {
    if (!state.pdf.doc && !state.pdf.sources) return;
    // Persist current page's bubbles + redactions + texts before switching
    if (state.drawing.loaded && state.drawing.type === 'pdf') {
      state.pageBubbles[state.pdf.page] = state.bubbles.slice();
      state.pageRedactions[state.pdf.page] = state.redactions.slice();
      state.pageTexts[state.pdf.page] = state.texts.slice();
    }

    // Resolve global page number to the right source doc + local index.
    // In single-source mode (no append) this just returns state.pdf.doc.
    var resolved = resolvePdfPage(pageNum);
    if (!resolved || !resolved.doc) {
      console.error('[bubble-tool] cannot resolve page', pageNum);
      return;
    }
    const page = await resolved.doc.getPage(resolved.localPage);
    // Render at 2x for crispness, then fit to stage
    const renderScale = 2;
    const viewport = page.getViewport({ scale: renderScale });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    const ctx = canvas.getContext('2d');
    // White background (PDFs are transparent by default)
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvasContext: ctx, viewport }).promise;
    drawingImg.src = canvas.toDataURL('image/png');

    await new Promise(resolve => {
      drawingImg.onload = () => {
        state.drawing.loaded = true;
        state.drawing.w = canvas.width;
        state.drawing.h = canvas.height;
        fitDrawingToStage(canvas.width, canvas.height);
        resolve();
      };
    });

    state.pdf.page = pageNum;
    $('page-current').textContent = pageNum;
    updatePageNavButtons();

    // Load any saved bubbles + redactions + texts for this page
    state.bubbles = state.pageBubbles[pageNum] ? state.pageBubbles[pageNum].slice() : [];
    state.redactions = state.pageRedactions[pageNum] ? state.pageRedactions[pageNum].slice() : [];
    state.texts = state.pageTexts[pageNum] ? state.pageTexts[pageNum].slice() : [];
    state.selected = null;
    state.redactSelected = null;
    state.textSelected = null;
    state.redactSelected = null;
    // Recompute next number from max across all pages
    let maxNum = 0;
    Object.values(state.pageBubbles).forEach(arr => {
      arr.forEach(b => { const n = parseInt(b.num); if (!isNaN(n) && n > maxNum) maxNum = n; });
    });
    state.bubbles.forEach(b => { const n = parseInt(b.num); if (!isNaN(n) && n > maxNum) maxNum = n; });
    state.nextId = maxNum + 1;
    $('next-number').value = state.nextId;

    render();
    renderParts();
    updateFooter();
  }

  function updatePageNavButtons() {
    $('page-prev').disabled = state.pdf.page <= 1;
    $('page-next').disabled = state.pdf.page >= state.pdf.total;
  }

  $('page-prev').addEventListener('click', () => {
    if (state.pdf.page > 1) renderPdfPage(state.pdf.page - 1);
  });
  $('page-next').addEventListener('click', () => {
    if (state.pdf.page < state.pdf.total) renderPdfPage(state.pdf.page + 1);
  });

  // CGM modal close
  $('cgm-modal-close').addEventListener('click', () => {
    $('cgm-modal').classList.remove('visible');
  });
  $('cgm-modal').addEventListener('click', e => {
    if (e.target === $('cgm-modal')) $('cgm-modal').classList.remove('visible');
  });

  // ============ PAGE MANAGER ============
  // The pageOrder array (in state) holds source-page indices (0-based) in the
  // user's desired order. Duplicates are allowed (for the duplicate action).
  // pageDeleted is a map keyed by source page number (1-based) that toggles a
  // page off — but since duplicates can exist, we track deletion at the
  // *order-array index* level instead, using a sparse marker. Approach used:
  // pageOrder entries that should be skipped get a special object {idx, deleted:true}.
  // For simplicity in this implementation, deletion REMOVES the entry from
  // pageOrder; the page-manager UI shows a visual "deleted" state during the
  // session by tracking a separate set of order-positions, then on close, the
  // entries get pruned. To allow undeleting in the same session, we keep the
  // entries in pageOrder but add a `_del` flag — those get filtered out at export.

  function ensurePageOrder() {
    if (state.pageOrder === null) {
      // Initialize with original order: each entry is { srcIdx: 0-based, rotation: 0 }
      state.pageOrder = [];
      for (let i = 0; i < state.pdf.total; i++) {
        state.pageOrder.push({ srcIdx: i, rotation: 0, deleted: false });
      }
    }
    return state.pageOrder;
  }

  // Cache of page thumbnail data URLs, keyed by srcIdx
  const thumbCache = {};

  async function getPageThumbnail(srcIdx) {
    if (thumbCache[srcIdx]) return thumbCache[srcIdx];
    // srcIdx is a 0-based global index across all appended sources. Route
    // it through resolvePdfPage so appended-PDF pages render correctly in
    // the page manager too.
    var resolved = resolvePdfPage(srcIdx + 1);
    if (!resolved || !resolved.doc) return null;
    try {
      const page = await resolved.doc.getPage(resolved.localPage);
      const viewport = page.getViewport({ scale: 0.4 });
      const canvas = document.createElement('canvas');
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      await page.render({ canvasContext: ctx, viewport }).promise;
      const url = canvas.toDataURL('image/jpeg', 0.7);
      thumbCache[srcIdx] = url;
      return url;
    } catch (err) {
      console.error('Thumbnail render failed for page', srcIdx + 1, err);
      return null;
    }
  }

  $('btn-pages').addEventListener('click', async () => {
    if (state.drawing.type !== 'pdf') {
      alert('Page manager is only available for PDF files. Upload a PDF to use this feature.');
      return;
    }
    ensurePageOrder();
    $('pages-modal').classList.add('visible');
    await renderPageGrid();
  });

  $('pages-modal-close').addEventListener('click', () => {
    $('pages-modal').classList.remove('visible');
  });
  $('pages-modal').addEventListener('click', e => {
    if (e.target === $('pages-modal')) $('pages-modal').classList.remove('visible');
  });
  $('pages-modal-reset').addEventListener('click', () => {
    if (!confirm('Reset to original page order? Any deletions, duplicates, and rotations from this session will be discarded.')) return;
    state.pageOrder = null;
    ensurePageOrder();
    renderPageGrid();
  });

  async function renderPageGrid() {
    const grid = $('page-grid');
    grid.innerHTML = '';
    const order = state.pageOrder;
    if (!order || order.length === 0) {
      grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text-light);padding:40px;font-size:11px;letter-spacing:0.15em;">— ALL PAGES DELETED —</div>';
      return;
    }
    order.forEach((entry, posIdx) => {
      const card = document.createElement('div');
      card.className = 'page-card' + (entry.deleted ? ' deleted' : '');
      card.draggable = !entry.deleted;
      card.dataset.pos = posIdx;
      card.innerHTML = `
        <div class="thumb-wrap">
          <span class="loading">PAGE ${entry.srcIdx + 1}</span>
          ${entry.deleted ? '<div class="deleted-overlay">DELETED</div>' : ''}
        </div>
        <div class="meta">
          <span class="pgnum">${posIdx + 1}</span>
          <span>SRC P${entry.srcIdx + 1}${entry.rotation ? ' · ' + entry.rotation + '°' : ''}</span>
        </div>
        <div class="actions-row">
          <button data-act="rot-cw" title="Rotate clockwise 90°">↻</button>
          <button data-act="rot-ccw" title="Rotate counter-clockwise 90°">↺</button>
          <button data-act="dup" title="Duplicate this page">⊕</button>
          <button data-act="${entry.deleted ? 'restore' : 'delete'}" class="${entry.deleted ? '' : 'danger'}" title="${entry.deleted ? 'Restore' : 'Delete'}">${entry.deleted ? '↶' : '✕'}</button>
        </div>
      `;
      grid.appendChild(card);

      // Render thumbnail
      const wrap = card.querySelector('.thumb-wrap');
      getPageThumbnail(entry.srcIdx).then(url => {
        if (!url) return;
        const img = document.createElement('img');
        img.src = url;
        // Apply rotation visual to thumbnail
        if (entry.rotation) {
          img.style.transform = `rotate(${entry.rotation}deg)`;
          // Adjust wrapper to swap aspect for 90/270
          const r = ((entry.rotation % 360) + 360) % 360;
          if (r === 90 || r === 270) {
            // The image will rotate but stay within the wrapper; size to fit
            img.style.maxWidth = '70%';
            img.style.maxHeight = '70%';
          }
        }
        wrap.querySelector('.loading')?.remove();
        wrap.insertBefore(img, wrap.firstChild);
      });

      // Wire actions
      card.querySelectorAll('[data-act]').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          const act = btn.dataset.act;
          handlePageAction(posIdx, act);
        });
      });

      // Drag handlers
      card.addEventListener('dragstart', e => {
        if (entry.deleted) { e.preventDefault(); return; }
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(posIdx));
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        document.querySelectorAll('.page-card').forEach(c => {
          c.classList.remove('drop-before', 'drop-after');
        });
      });
      card.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const rect = card.getBoundingClientRect();
        const before = (e.clientX - rect.left) < rect.width / 2;
        // Clear from siblings, set on this
        document.querySelectorAll('.page-card').forEach(c => {
          if (c !== card) c.classList.remove('drop-before', 'drop-after');
        });
        card.classList.toggle('drop-before', before);
        card.classList.toggle('drop-after', !before);
      });
      card.addEventListener('dragleave', () => {
        card.classList.remove('drop-before', 'drop-after');
      });
      card.addEventListener('drop', e => {
        e.preventDefault();
        const fromPos = parseInt(e.dataTransfer.getData('text/plain'));
        const rect = card.getBoundingClientRect();
        const before = (e.clientX - rect.left) < rect.width / 2;
        let toPos = posIdx + (before ? 0 : 1);
        if (fromPos === toPos || fromPos === toPos - 1) return;
        // Move entry from fromPos to toPos
        const moved = state.pageOrder.splice(fromPos, 1)[0];
        // Adjust toPos if removing earlier item
        if (fromPos < toPos) toPos--;
        state.pageOrder.splice(toPos, 0, moved);
        renderPageGrid();
      });
    });
  }

  function handlePageAction(posIdx, act) {
    const entry = state.pageOrder[posIdx];
    if (!entry) return;
    if (act === 'delete') {
      entry.deleted = true;
    } else if (act === 'restore') {
      entry.deleted = false;
    } else if (act === 'rot-cw') {
      entry.rotation = ((entry.rotation || 0) + 90) % 360;
    } else if (act === 'rot-ccw') {
      entry.rotation = ((entry.rotation || 0) - 90 + 360) % 360;
    } else if (act === 'dup') {
      // Insert a copy right after the current position
      const copy = { srcIdx: entry.srcIdx, rotation: entry.rotation, deleted: false };
      state.pageOrder.splice(posIdx + 1, 0, copy);
    }
    renderPageGrid();
  }

  // ============ COORDINATE HELPERS ============
  // Use the SVG's own coordinate transform matrix — handles scale, rotation,
  // and any future transforms automatically. This is the only sane way to map
  // screen → canvas coords once rotation is in play.
  function getCanvasPoint(clientX, clientY) {
    const ctm = annotationsSvg.getScreenCTM();
    if (!ctm) {
      const rect = annotationsSvg.getBoundingClientRect();
      return { x: (clientX - rect.left) / state.zoom, y: (clientY - rect.top) / state.zoom };
    }
    const pt = annotationsSvg.createSVGPoint();
    pt.x = clientX;
    pt.y = clientY;
    const local = pt.matrixTransform(ctm.inverse());
    return { x: local.x, y: local.y };
  }

  // ============ ADD BUBBLE ============
  drawingContainer.addEventListener('click', e => {
    if (state.tool !== 'bubble') return;
    if (!state.drawing.loaded) return;
    if (e.target.closest('.bubble-group') || e.target.closest('.leader-anchor')) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    if (pt.x < 0 || pt.y < 0 || pt.x > drawingImg.offsetWidth || pt.y > drawingImg.offsetHeight) return;
    const num = state.nextId;
    const bubble = {
      id: 'b_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
      num: num,
      label: '',
      x: pt.x,
      y: pt.y,
      ax: pt.x + 50,
      ay: pt.y - 50,
      shape: state.shape,
      size: state.bubbleSize,
      stroke: state.strokeWidth,
      fill: state.fillColor,
      fillOpacity: state.fillOpacity,
      textColor: state.textColor,
      // Dimension details — engineering inspection fields
      dim: {
        type: 'linear',     // linear | diameter | radius | angle | gdt | reference
        nominal: '',        // e.g. "25.40"
        unit: 'mm',         // mm | in | deg | none
        tolPlus: '',        // upper deviation, e.g. "0.05" or "0.10"
        tolMinus: '',       // lower deviation
        gdtSym: '',         // GD&T symbol char if type=gdt
        gdtTol: '',         // GD&T tolerance value
        gdtDatum: '',       // datum references like "A|B|C"
        critical: false,    // key characteristic / critical-to-function flag
        notes: ''           // free text
      }
    };
    state.bubbles.push(bubble);
    state.nextId = num + 1;
    $('next-number').value = state.nextId;
    syncPage();
    render();
    renderParts();
    updateFooter();
  });

  // ============ TEXT TOOL ============
  // Click to place a text annotation; an inline editor opens immediately.
  drawingContainer.addEventListener('click', e => {
    if (state.tool !== 'text') return;
    if (!state.drawing.loaded) return;
    if (e.target.closest('.text-annot') || e.target.closest('.bubble-group') ||
        e.target.closest('.redact-rect') || e.target.closest('.redact-handle') ||
        e.target.closest('.leader-anchor')) return;

    const pt = getCanvasPoint(e.clientX, e.clientY);
    const dispW = drawingImg.offsetWidth;
    const dispH = drawingImg.offsetHeight;
    if (pt.x < 0 || pt.y < 0 || pt.x > dispW || pt.y > dispH) return;

    const text = {
      id: 't_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
      x: pt.x, y: pt.y,
      content: '',
      fontSize: 14,
      color: '#1a1f2e'
    };
    state.texts.push(text);
    syncTextPage();
    state.textSelected = text.id;
    render();
    updateFooter();
    openTextEditor(text);
  });

  function syncTextPage() {
    if (state.drawing.type === 'pdf') {
      state.pageTexts[state.pdf.page] = state.texts.slice();
    }
  }

  // Floating text editor — appears above the annotation while typing.
  let activeTextEditor = null;
  function openTextEditor(textAnnot) {
    closeTextEditor();
    const overlay = document.createElement('div');
    overlay.className = 'text-edit-overlay';
    overlay.innerHTML = `
      <textarea spellcheck="true" placeholder="Type your note…"></textarea>
      <div class="row">
        <input type="number" class="size-input" value="${textAnnot.fontSize}" min="8" max="72" title="Font size">
        <input type="color" value="${textAnnot.color}" title="Text color" style="width:32px;height:24px;border:1px solid var(--border);background:var(--bg);cursor:pointer;border-radius:2px;padding:0;">
        <button data-act="done" class="primary">DONE</button>
        <button data-act="cancel">CANCEL</button>
      </div>
    `;
    document.body.appendChild(overlay);
    activeTextEditor = { el: overlay, id: textAnnot.id };
    positionTextEditor(textAnnot);

    const ta = overlay.querySelector('textarea');
    const sizeInput = overlay.querySelector('.size-input');
    const colorInput = overlay.querySelector('input[type="color"]');
    ta.value = textAnnot.content;
    setTimeout(() => ta.focus(), 0);

    ta.addEventListener('input', () => {
      const t = state.texts.find(x => x.id === textAnnot.id);
      if (t) { t.content = ta.value; syncTextPage(); render(); }
    });
    sizeInput.addEventListener('input', () => {
      const t = state.texts.find(x => x.id === textAnnot.id);
      if (t) {
        t.fontSize = Math.max(8, Math.min(72, parseInt(sizeInput.value) || 14));
        syncTextPage(); render();
      }
    });
    colorInput.addEventListener('input', () => {
      const t = state.texts.find(x => x.id === textAnnot.id);
      if (t) { t.color = colorInput.value; syncTextPage(); render(); }
    });
    overlay.querySelector('[data-act="done"]').addEventListener('click', () => {
      finalizeTextEdit(textAnnot.id);
    });
    overlay.querySelector('[data-act="cancel"]').addEventListener('click', () => {
      const t = state.texts.find(x => x.id === textAnnot.id);
      if (t && !t.content.trim()) {
        // Remove empty annotation that was just created
        state.texts = state.texts.filter(x => x.id !== textAnnot.id);
        syncTextPage();
      }
      closeTextEditor();
      render();
      updateFooter();
    });
    ta.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        e.preventDefault();
        overlay.querySelector('[data-act="cancel"]').click();
      } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        finalizeTextEdit(textAnnot.id);
      }
    });
  }

  function finalizeTextEdit(id) {
    const t = state.texts.find(x => x.id === id);
    if (t && !t.content.trim()) {
      // Remove empty annotation
      state.texts = state.texts.filter(x => x.id !== id);
      syncTextPage();
    }
    closeTextEditor();
    render();
    updateFooter();
  }

  function closeTextEditor() {
    if (activeTextEditor) {
      activeTextEditor.el.remove();
      activeTextEditor = null;
    }
  }

  function positionTextEditor(textAnnot) {
    if (!activeTextEditor) return;
    // Position the editor near the annotation, in screen coords
    const ctm = annotationsSvg.getScreenCTM();
    if (!ctm) return;
    const pt = annotationsSvg.createSVGPoint();
    pt.x = textAnnot.x;
    pt.y = textAnnot.y;
    const screen = pt.matrixTransform(ctm);
    activeTextEditor.el.style.left = (screen.x + 12) + 'px';
    activeTextEditor.el.style.top = (screen.y + 12) + 'px';
  }


  // Mousedown on the canvas with redact tool starts a drag rectangle.
  // Shift+click on a PDF source snaps the redaction to the image at that point.
  drawingContainer.addEventListener('mousedown', async e => {
    if (state.tool !== 'redact') return;
    if (!state.drawing.loaded) return;
    if (e.target.closest('.redact-rect') || e.target.closest('.redact-handle')) return;
    if (e.target.closest('.bubble-group') || e.target.closest('.leader-anchor')) return;

    const pt = getCanvasPoint(e.clientX, e.clientY);
    const dispW = drawingImg.offsetWidth;
    const dispH = drawingImg.offsetHeight;
    if (pt.x < 0 || pt.y < 0 || pt.x > dispW || pt.y > dispH) return;

    // Shift+click on a PDF: snap-to-image
    if (e.shiftKey && state.drawing.type === 'pdf') {
      e.preventDefault();
      const imgRect = await findImageAtPoint(pt.x, pt.y);
      if (imgRect) {
        state.redactions.push({
          id: 'r_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
          x: imgRect.x, y: imgRect.y, w: imgRect.w, h: imgRect.h,
          style: state.redactStyle
        });
        syncRedactPage();
        updateFooter();
        render();
      }
      return;
    }

    state.redactDraw = { startX: pt.x, startY: pt.y, x: pt.x, y: pt.y, w: 0, h: 0 };
    e.preventDefault();
  });

  // Image bounding-box detection on the current PDF page.
  // Walks PDF.js's operator list, tracks the current transformation matrix
  // through save/restore/transform ops, and captures the bbox of the unit
  // square (the natural extent of an image XObject) under the CTM whenever
  // an image-painting op fires. Returns the smallest containing bbox.
  async function findImageAtPoint(cx, cy) {
    if (!state.pdf.doc) return null;
    try {
      const page = await state.pdf.doc.getPage(state.pdf.page);
      const opList = await page.getOperatorList();
      const viewport = page.getViewport({ scale: 1 });
      const dispW = drawingImg.offsetWidth;
      const dispH = drawingImg.offsetHeight;
      const sx = viewport.width / dispW;
      const sy = viewport.height / dispH;
      const pdfX = cx * sx;
      const pdfY = cy * sy;
      const pdfPageH = viewport.height;

      const OPS = pdfjsLib.OPS;
      const stack = [];
      let ctm = [1, 0, 0, 1, 0, 0];
      const candidates = [];

      function multiply(a, b) {
        return [
          a[0] * b[0] + a[2] * b[1],
          a[1] * b[0] + a[3] * b[1],
          a[0] * b[2] + a[2] * b[3],
          a[1] * b[2] + a[3] * b[3],
          a[0] * b[4] + a[2] * b[5] + a[4],
          a[1] * b[4] + a[3] * b[5] + a[5],
        ];
      }
      function applyCTM(x, y, m) {
        return [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];
      }

      for (let i = 0; i < opList.fnArray.length; i++) {
        const fn = opList.fnArray[i];
        const args = opList.argsArray[i];
        if (fn === OPS.save) {
          stack.push(ctm.slice());
        } else if (fn === OPS.restore) {
          if (stack.length) ctm = stack.pop();
        } else if (fn === OPS.transform) {
          ctm = multiply(ctm, args);
        } else if (fn === OPS.paintImageXObject || fn === OPS.paintInlineImageXObject ||
                   fn === OPS.paintImageMaskXObject || fn === OPS.paintJpegXObject) {
          const corners = [
            applyCTM(0, 0, ctm),
            applyCTM(1, 0, ctm),
            applyCTM(0, 1, ctm),
            applyCTM(1, 1, ctm),
          ];
          let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
          corners.forEach(([x, y]) => {
            if (x < minX) minX = x;
            if (x > maxX) maxX = x;
            if (y < minY) minY = y;
            if (y > maxY) maxY = y;
          });
          // Convert PDF user space (Y-up) to viewport (Y-down)
          const yTop = pdfPageH - maxY;
          const yBottom = pdfPageH - minY;
          if (pdfX >= minX && pdfX <= maxX && pdfY >= yTop && pdfY <= yBottom) {
            const wPdf = maxX - minX;
            const hPdf = yBottom - yTop;
            candidates.push({ minX, yTop, wPdf, hPdf, area: wPdf * hPdf });
          }
        }
      }
      if (candidates.length === 0) return null;
      // Smallest containing image wins (most specific)
      candidates.sort((a, b) => a.area - b.area);
      const best = candidates[0];
      return {
        x: best.minX / sx,
        y: best.yTop / sy,
        w: best.wPdf / sx,
        h: best.hPdf / sy,
      };
    } catch (err) {
      console.error('Image detection failed:', err);
      return null;
    }
  }

  window.addEventListener('mousemove', e => {
    if (!state.redactDraw) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    const r = state.redactDraw;
    r.x = Math.min(r.startX, pt.x);
    r.y = Math.min(r.startY, pt.y);
    r.w = Math.abs(pt.x - r.startX);
    r.h = Math.abs(pt.y - r.startY);
    render();
  });

  window.addEventListener('mouseup', () => {
    if (state.redactDraw) {
      const r = state.redactDraw;
      // Ignore tiny accidental clicks
      if (r.w >= 4 && r.h >= 4) {
        // Clamp to drawing bounds
        const dispW = drawingImg.offsetWidth;
        const dispH = drawingImg.offsetHeight;
        const x = Math.max(0, Math.min(r.x, dispW));
        const y = Math.max(0, Math.min(r.y, dispH));
        const w = Math.max(1, Math.min(r.w, dispW - x));
        const h = Math.max(1, Math.min(r.h, dispH - y));
        state.redactions.push({
          id: 'r_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
          x, y, w, h,
          style: state.redactStyle
        });
        syncRedactPage();
        updateFooter();
      }
      state.redactDraw = null;
      render();
    }
  });

  // Sync redactions for current PDF page
  function syncRedactPage() {
    if (state.drawing.type === 'pdf') {
      state.pageRedactions[state.pdf.page] = state.redactions.slice();
    }
  }

  // Move/resize existing redactions via SVG mousedown
  function onRedactMouseDown(e, role, id) {
    const r = state.redactions.find(x => x.id === id);
    if (!r) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    state.redactSelected = id;
    if (state.tool === 'delete') {
      state.redactions = state.redactions.filter(x => x.id !== id);
      syncRedactPage();
      updateFooter();
      render();
      return;
    }
    if (role === 'move') {
      state.redactDrag = { type: 'move', id, dx: pt.x - r.x, dy: pt.y - r.y };
    } else if (role === 'resize') {
      state.redactDrag = { type: 'resize', id, sx: r.x, sy: r.y };
    }
    render();
    e.preventDefault();
    e.stopPropagation();
  }

  window.addEventListener('mousemove', e => {
    if (!state.redactDrag) return;
    const r = state.redactions.find(x => x.id === state.redactDrag.id);
    if (!r) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    const dispW = drawingImg.offsetWidth;
    const dispH = drawingImg.offsetHeight;
    if (state.redactDrag.type === 'move') {
      r.x = Math.max(0, Math.min(pt.x - state.redactDrag.dx, dispW - r.w));
      r.y = Math.max(0, Math.min(pt.y - state.redactDrag.dy, dispH - r.h));
    } else {
      // Resize from bottom-right handle
      r.w = Math.max(4, Math.min(pt.x - state.redactDrag.sx, dispW - state.redactDrag.sx));
      r.h = Math.max(4, Math.min(pt.y - state.redactDrag.sy, dispH - state.redactDrag.sy));
    }
    render();
  });

  window.addEventListener('mouseup', () => {
    if (state.redactDrag) {
      syncRedactPage();
      state.redactDrag = null;
    }
  });

  // ============ MOUSE MOVE FOR COORD DISPLAY ============
  drawingContainer.addEventListener('mousemove', e => {
    if (!state.drawing.loaded) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    if (pt.x >= 0 && pt.y >= 0 && pt.x <= drawingImg.offsetWidth && pt.y <= drawingImg.offsetHeight) {
      $('coord-display').textContent = `X: ${Math.round(pt.x)}  Y: ${Math.round(pt.y)}`;
    }
  });

  // ============ RENDER BUBBLES ============
  const SVG_NS = 'http://www.w3.org/2000/svg';

  function shapePath(shape, cx, cy, size) {
    const r = size;
    if (shape === 'circle') return null; // use <circle>
    if (shape === 'hexagon') {
      const pts = [];
      for (let i = 0; i < 6; i++) {
        const a = Math.PI / 6 + (Math.PI / 3) * i;
        pts.push((cx + r * Math.cos(a)).toFixed(2) + ',' + (cy + r * Math.sin(a)).toFixed(2));
      }
      return pts.join(' ');
    }
    if (shape === 'square') {
      const s = r * Math.SQRT2 * 0.9;
      return `${cx-s/2},${cy-s/2} ${cx+s/2},${cy-s/2} ${cx+s/2},${cy+s/2} ${cx-s/2},${cy+s/2}`;
    }
    if (shape === 'diamond') {
      return `${cx},${cy-r} ${cx+r},${cy} ${cx},${cy+r} ${cx-r},${cy}`;
    }
    return null;
  }

  // Draw ruler bars + a faint cell grid on top of the drawing. Called from
  // render() before bubbles, so bubbles paint over the grid lines.
  //
  // Geometry:
  //   - Top ruler: a band across the top, ~16px tall, with column numbers 1..N
  //   - Left ruler: a band down the left, ~16px wide, with row letters A..Z
  //   - Faint dashed lines between cells (inside the drawing area)
  //
  // All coords are in the SVG viewBox (display-pixel) space, matching the
  // bubble coordinate system.
  function renderRulers() {
    const rows = state.gridRows || 4;
    const cols = state.gridCols || 4;
    const W = state.drawing.baseW || 0;
    const H = state.drawing.baseH || 0;
    if (W <= 0 || H <= 0) return;

    // Ruler band thickness scales with the drawing size, capped so very large
    // pages don't get giant bars.
    const band = Math.max(14, Math.min(24, Math.min(W, H) * 0.025));
    const fontSize = Math.max(9, band * 0.6);

    const g = document.createElementNS(SVG_NS, 'g');
    g.setAttribute('class', 'rulers-overlay');
    g.setAttribute('pointer-events', 'none');

    // Top ruler background bar
    const topBar = document.createElementNS(SVG_NS, 'rect');
    topBar.setAttribute('x', 0);
    topBar.setAttribute('y', 0);
    topBar.setAttribute('width', W);
    topBar.setAttribute('height', band);
    topBar.setAttribute('fill', '#f3f4f6');
    topBar.setAttribute('fill-opacity', '0.85');
    topBar.setAttribute('stroke', '#9ca3af');
    topBar.setAttribute('stroke-width', '0.5');
    g.appendChild(topBar);

    // Left ruler background bar
    const leftBar = document.createElementNS(SVG_NS, 'rect');
    leftBar.setAttribute('x', 0);
    leftBar.setAttribute('y', 0);
    leftBar.setAttribute('width', band);
    leftBar.setAttribute('height', H);
    leftBar.setAttribute('fill', '#f3f4f6');
    leftBar.setAttribute('fill-opacity', '0.85');
    leftBar.setAttribute('stroke', '#9ca3af');
    leftBar.setAttribute('stroke-width', '0.5');
    g.appendChild(leftBar);

    // Column numbers along the top ruler
    const colWidth = W / cols;
    for (let c = 0; c < cols; c++) {
      const cx = c * colWidth + colWidth / 2;
      // Tick mark down from the band edge
      const tick = document.createElementNS(SVG_NS, 'line');
      tick.setAttribute('x1', c * colWidth);
      tick.setAttribute('y1', 0);
      tick.setAttribute('x2', c * colWidth);
      tick.setAttribute('y2', band);
      tick.setAttribute('stroke', '#6b7280');
      tick.setAttribute('stroke-width', '0.5');
      g.appendChild(tick);
      // Label
      const txt = document.createElementNS(SVG_NS, 'text');
      txt.setAttribute('x', cx);
      txt.setAttribute('y', band / 2);
      txt.setAttribute('text-anchor', 'middle');
      txt.setAttribute('dominant-baseline', 'central');
      txt.setAttribute('fill', '#374151');
      txt.setAttribute('font-family', 'JetBrains Mono, monospace');
      txt.setAttribute('font-size', fontSize);
      txt.setAttribute('font-weight', '600');
      txt.textContent = String(c + 1);
      g.appendChild(txt);
    }
    // Final tick at the right edge
    const lastTick = document.createElementNS(SVG_NS, 'line');
    lastTick.setAttribute('x1', W);
    lastTick.setAttribute('y1', 0);
    lastTick.setAttribute('x2', W);
    lastTick.setAttribute('y2', band);
    lastTick.setAttribute('stroke', '#6b7280');
    lastTick.setAttribute('stroke-width', '0.5');
    g.appendChild(lastTick);

    // Row letters along the left ruler
    const rowHeight = H / rows;
    for (let r = 0; r < rows; r++) {
      const cy = r * rowHeight + rowHeight / 2;
      const tick = document.createElementNS(SVG_NS, 'line');
      tick.setAttribute('x1', 0);
      tick.setAttribute('y1', r * rowHeight);
      tick.setAttribute('x2', band);
      tick.setAttribute('y2', r * rowHeight);
      tick.setAttribute('stroke', '#6b7280');
      tick.setAttribute('stroke-width', '0.5');
      g.appendChild(tick);
      const txt = document.createElementNS(SVG_NS, 'text');
      txt.setAttribute('x', band / 2);
      txt.setAttribute('y', cy);
      txt.setAttribute('text-anchor', 'middle');
      txt.setAttribute('dominant-baseline', 'central');
      txt.setAttribute('fill', '#374151');
      txt.setAttribute('font-family', 'JetBrains Mono, monospace');
      txt.setAttribute('font-size', fontSize);
      txt.setAttribute('font-weight', '600');
      txt.textContent = String.fromCharCode(65 + r);
      g.appendChild(txt);
    }
    const lastRowTick = document.createElementNS(SVG_NS, 'line');
    lastRowTick.setAttribute('x1', 0);
    lastRowTick.setAttribute('y1', H);
    lastRowTick.setAttribute('x2', band);
    lastRowTick.setAttribute('y2', H);
    lastRowTick.setAttribute('stroke', '#6b7280');
    lastRowTick.setAttribute('stroke-width', '0.5');
    g.appendChild(lastRowTick);

    // Faint dashed grid lines inside the drawing area (between cells)
    for (let c = 1; c < cols; c++) {
      const x = c * colWidth;
      const line = document.createElementNS(SVG_NS, 'line');
      line.setAttribute('x1', x);
      line.setAttribute('y1', band);
      line.setAttribute('x2', x);
      line.setAttribute('y2', H);
      line.setAttribute('stroke', '#9ca3af');
      line.setAttribute('stroke-width', '0.4');
      line.setAttribute('stroke-dasharray', '3 4');
      line.setAttribute('opacity', '0.35');
      g.appendChild(line);
    }
    for (let r = 1; r < rows; r++) {
      const y = r * rowHeight;
      const line = document.createElementNS(SVG_NS, 'line');
      line.setAttribute('x1', band);
      line.setAttribute('y1', y);
      line.setAttribute('x2', W);
      line.setAttribute('y2', y);
      line.setAttribute('stroke', '#9ca3af');
      line.setAttribute('stroke-width', '0.4');
      line.setAttribute('stroke-dasharray', '3 4');
      line.setAttribute('opacity', '0.35');
      g.appendChild(line);
    }

    annotationsSvg.appendChild(g);
  }

  function render() {
    // Clear
    while (annotationsSvg.firstChild) annotationsSvg.removeChild(annotationsSvg.firstChild);

    // Render rulers / grid overlay (when enabled) BEFORE bubbles so they
    // sit behind the bubble graphics.
    if (state.showRulers && state.drawing.loaded) {
      renderRulers();
    }

    state.bubbles.forEach(b => {
      ensureDim(b);
      // Opacity applies to fill, border, leader line, and anchor dot so the
      // whole bubble fades together. Per-bubble override > state default > 1.
      const fo = (typeof b.fillOpacity === 'number') ? b.fillOpacity
                : (typeof state.fillOpacity === 'number') ? state.fillOpacity : 1;

      // Reference rectangle — frames the dimension text on the drawing.
      // Drawn FIRST (behind everything else) and only when:
      //   - the bubble has a refRect attached (auto-bubbled bubbles only)
      //   - state.showRefRects is true (global toggle)
      //   - this specific rect's `visible` flag isn't false (per-bubble hide)
      if (state.showRefRects && b.refRect && b.refRect.visible !== false) {
        const r = b.refRect;
        const rect = document.createElementNS(SVG_NS, 'rect');
        rect.setAttribute('x', r.x);
        rect.setAttribute('y', r.y);
        rect.setAttribute('width', r.w);
        rect.setAttribute('height', r.h);
        rect.setAttribute('fill', 'none');
        rect.setAttribute('stroke', state.selected === b.id ? '#1e3a8a' : '#6b7280');
        rect.setAttribute('stroke-width', state.selected === b.id ? 1.5 : 1);
        rect.setAttribute('stroke-dasharray', state.selected === b.id ? '0' : '4 3');
        rect.setAttribute('class', 'ref-rect');
        rect.dataset.id = b.id;
        rect.dataset.role = 'ref-rect';
        annotationsSvg.appendChild(rect);

        // Resize handles, only when this bubble is selected. Four corner
        // handles (small squares) that the user can drag to resize the rect.
        if (state.selected === b.id) {
          const handlePositions = [
            { hx: r.x,        hy: r.y,        corner: 'nw' },
            { hx: r.x + r.w,  hy: r.y,        corner: 'ne' },
            { hx: r.x,        hy: r.y + r.h,  corner: 'sw' },
            { hx: r.x + r.w,  hy: r.y + r.h,  corner: 'se' }
          ];
          for (const hp of handlePositions) {
            const h = document.createElementNS(SVG_NS, 'rect');
            const hsz = 6;
            h.setAttribute('x', hp.hx - hsz / 2);
            h.setAttribute('y', hp.hy - hsz / 2);
            h.setAttribute('width', hsz);
            h.setAttribute('height', hsz);
            h.setAttribute('fill', '#ffffff');
            h.setAttribute('stroke', '#1e3a8a');
            h.setAttribute('stroke-width', 1.2);
            h.setAttribute('class', 'ref-rect-handle ' + hp.corner);
            h.style.cursor = (hp.corner === 'nw' || hp.corner === 'se') ? 'nwse-resize' : 'nesw-resize';
            h.dataset.id = b.id;
            h.dataset.role = 'ref-rect-handle';
            h.dataset.corner = hp.corner;
            annotationsSvg.appendChild(h);
          }
        }
      }

      // Leader line — always black so it's visible regardless of bubble fill.
      // The line stops at the bubble's edge (not its centre), so it doesn't
      // visually pass through the transparent bubble fill.
      const dx = b.x - b.ax, dy = b.y - b.ay;
      const dlen = Math.hypot(dx, dy) || 1;
      const endX = b.x - (dx / dlen) * b.size;
      const endY = b.y - (dy / dlen) * b.size;
      const line = document.createElementNS(SVG_NS, 'line');
      line.setAttribute('x1', b.ax);
      line.setAttribute('y1', b.ay);
      line.setAttribute('x2', endX);
      line.setAttribute('y2', endY);
      line.setAttribute('stroke', '#000000');
      line.setAttribute('stroke-width', b.stroke);
      line.setAttribute('stroke-opacity', fo);
      line.setAttribute('class', 'leader-line');
      annotationsSvg.appendChild(line);

      // Anchor (arrow tip) — also black, also opacity-linked
      const anchor = document.createElementNS(SVG_NS, 'circle');
      anchor.setAttribute('cx', b.ax);
      anchor.setAttribute('cy', b.ay);
      anchor.setAttribute('r', 3);
      anchor.setAttribute('fill', '#000000');
      anchor.setAttribute('fill-opacity', fo);
      anchor.setAttribute('stroke', '#1a1f2e');
      anchor.setAttribute('stroke-width', 1);
      anchor.setAttribute('stroke-opacity', fo);
      anchor.setAttribute('class', 'leader-anchor');
      anchor.dataset.id = b.id;
      anchor.dataset.role = 'anchor';
      annotationsSvg.appendChild(anchor);

      // Bubble group
      const g = document.createElementNS(SVG_NS, 'g');
      g.setAttribute('class', 'bubble-group' + (state.selected === b.id ? ' selected' : ''));
      g.dataset.id = b.id;
      g.dataset.role = 'bubble';

      // Critical ring (red outer halo) — drawn first, behind the bubble
      if (b.dim.critical) {
        let critEl;
        const ringSize = b.size + Math.max(3, b.size * 0.18);
        if (b.shape === 'circle') {
          critEl = document.createElementNS(SVG_NS, 'circle');
          critEl.setAttribute('cx', b.x);
          critEl.setAttribute('cy', b.y);
          critEl.setAttribute('r', ringSize);
        } else {
          critEl = document.createElementNS(SVG_NS, 'polygon');
          critEl.setAttribute('points', shapePath(b.shape, b.x, b.y, ringSize));
        }
        critEl.setAttribute('fill', 'none');
        critEl.setAttribute('stroke', '#b91c1c');
        critEl.setAttribute('stroke-width', Math.max(1.5, b.stroke));
        critEl.setAttribute('stroke-dasharray', '3 2');
        g.appendChild(critEl);
      }

      let shapeEl;
      if (b.shape === 'circle') {
        shapeEl = document.createElementNS(SVG_NS, 'circle');
        shapeEl.setAttribute('cx', b.x);
        shapeEl.setAttribute('cy', b.y);
        shapeEl.setAttribute('r', b.size);
      } else {
        shapeEl = document.createElementNS(SVG_NS, 'polygon');
        shapeEl.setAttribute('points', shapePath(b.shape, b.x, b.y, b.size));
      }
      shapeEl.setAttribute('fill', b.fill);
      shapeEl.setAttribute('fill-opacity', fo);
      shapeEl.setAttribute('stroke', '#1a1f2e');
      shapeEl.setAttribute('stroke-width', b.stroke);
      shapeEl.setAttribute('stroke-opacity', fo);
      shapeEl.setAttribute('class', 'bubble-shape');
      g.appendChild(shapeEl);

      // Number text — counter-rotate so it stays upright regardless of
      // drawing rotation (the canvas-wrap is rotated as a whole, so we
      // need to apply the inverse here, around the bubble center).
      const text = document.createElementNS(SVG_NS, 'text');
      text.setAttribute('x', b.x);
      text.setAttribute('y', b.y);
      text.setAttribute('text-anchor', 'middle');
      text.setAttribute('dominant-baseline', 'central');
      text.setAttribute('fill', b.textColor);
      text.setAttribute('font-family', 'JetBrains Mono, monospace');
      const fontSize = Math.max(9, b.size * 0.75);
      text.setAttribute('font-size', fontSize);
      text.setAttribute('font-weight', '700');
      if (state.rotation) {
        text.setAttribute('transform', `rotate(${-state.rotation} ${b.x} ${b.y})`);
      }
      text.textContent = state.prefix + b.num;
      g.appendChild(text);

      annotationsSvg.appendChild(g);
    });

    // Render redactions on top of bubbles (so they actually cover everything)
    state.redactions.forEach(r => {
      const rect = document.createElementNS(SVG_NS, 'rect');
      rect.setAttribute('x', r.x);
      rect.setAttribute('y', r.y);
      rect.setAttribute('width', r.w);
      rect.setAttribute('height', r.h);
      rect.setAttribute('fill', r.style === 'black' ? '#000000' : '#ffffff');
      rect.setAttribute('stroke', state.redactSelected === r.id ? 'var(--primary)' : (r.style === 'black' ? '#000000' : '#cccccc'));
      rect.setAttribute('stroke-width', state.redactSelected === r.id ? 2 : 0.5);
      rect.setAttribute('class', 'redact-rect' + (state.redactSelected === r.id ? ' selected' : ''));
      rect.dataset.id = r.id;
      rect.dataset.role = 'redact';
      annotationsSvg.appendChild(rect);

      // Resize handle (only when selected)
      if (state.redactSelected === r.id) {
        const handle = document.createElementNS(SVG_NS, 'rect');
        const hSize = 8;
        handle.setAttribute('x', r.x + r.w - hSize / 2);
        handle.setAttribute('y', r.y + r.h - hSize / 2);
        handle.setAttribute('width', hSize);
        handle.setAttribute('height', hSize);
        handle.setAttribute('class', 'redact-handle');
        handle.dataset.id = r.id;
        handle.dataset.role = 'redact-handle';
        annotationsSvg.appendChild(handle);
      }
    });

    // Pending (in-progress) redaction rectangle
    if (state.redactDraw && (state.redactDraw.w > 0 || state.redactDraw.h > 0)) {
      const rd = state.redactDraw;
      const pending = document.createElementNS(SVG_NS, 'rect');
      pending.setAttribute('x', rd.x);
      pending.setAttribute('y', rd.y);
      pending.setAttribute('width', rd.w);
      pending.setAttribute('height', rd.h);
      pending.setAttribute('class', 'redact-pending');
      annotationsSvg.appendChild(pending);
    }

    // Render text annotations on top of everything else
    state.texts.forEach(t => {
      const g = document.createElementNS(SVG_NS, 'g');
      g.setAttribute('class', 'text-annot' + (state.textSelected === t.id ? ' selected' : ''));
      g.dataset.id = t.id;
      g.dataset.role = 'text';

      // Selection background
      if (state.textSelected === t.id) {
        // Approximate text bounds for outline
        const lines = (t.content || '').split('\n');
        const lineH = t.fontSize * 1.3;
        const maxLineLen = Math.max(...lines.map(l => l.length), 1);
        const approxW = maxLineLen * t.fontSize * 0.6;
        const approxH = lines.length * lineH;
        const bg = document.createElementNS(SVG_NS, 'rect');
        bg.setAttribute('x', t.x - 4);
        bg.setAttribute('y', t.y - t.fontSize);
        bg.setAttribute('width', approxW + 8);
        bg.setAttribute('height', approxH + 8);
        bg.setAttribute('class', 'text-annot-bg');
        g.appendChild(bg);
      }

      // Render lines as separate <text> elements (SVG doesn't auto-wrap)
      const lines = (t.content || '').split('\n');
      const lineH = t.fontSize * 1.3;
      // Counter-rotate to keep upright when drawing is rotated
      const rotXform = state.rotation
        ? `rotate(${-state.rotation} ${t.x} ${t.y})`
        : '';
      lines.forEach((line, i) => {
        const txt = document.createElementNS(SVG_NS, 'text');
        txt.setAttribute('x', t.x);
        txt.setAttribute('y', t.y + i * lineH);
        txt.setAttribute('fill', t.color);
        txt.setAttribute('font-family', 'Inter, system-ui, sans-serif');
        txt.setAttribute('font-size', t.fontSize);
        if (rotXform) txt.setAttribute('transform', rotXform);
        txt.textContent = line || ' '; // empty line keeps spacing
        g.appendChild(txt);
      });

      annotationsSvg.appendChild(g);
    });
    // Keep Auto-bubble button enabled state in sync with drawing type
    if (window.refreshAbButtonState) window.refreshAbButtonState();
  }

  // ============ DRAGGING ============
  annotationsSvg.addEventListener('mousedown', e => {
    if (state.tool === 'pan') return;
    const target = e.target.closest('[data-role]');
    if (!target) {
      state.selected = null;
      state.redactSelected = null;
      render();
      renderParts();
      return;
    }
    const id = target.dataset.id;
    const role = target.dataset.role;

    // Redaction roles
    if (role === 'redact') {
      // Clear bubble selection
      state.selected = null;
      onRedactMouseDown(e, 'move', id);
      return;
    }
    if (role === 'redact-handle') {
      state.selected = null;
      onRedactMouseDown(e, 'resize', id);
      return;
    }

    // Ref-rect resize handle: drag corner to resize the rectangle
    if (role === 'ref-rect-handle') {
      const b = state.bubbles.find(x => x.id === id);
      if (!b || !b.refRect) return;
      const corner = target.dataset.corner;
      const r = b.refRect;
      state.drag = {
        type: 'ref-rect-resize',
        id, corner,
        orig: { x: r.x, y: r.y, w: r.w, h: r.h },
        startX: 0, startY: 0   // filled below
      };
      const pt = getCanvasPoint(e.clientX, e.clientY);
      state.drag.startX = pt.x;
      state.drag.startY = pt.y;
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    // Ref-rect body: select the bubble + start a move-drag of the rectangle
    if (role === 'ref-rect') {
      const b = state.bubbles.find(x => x.id === id);
      if (!b || !b.refRect) return;
      state.selected = id;
      const pt = getCanvasPoint(e.clientX, e.clientY);
      state.drag = {
        type: 'ref-rect-move',
        id,
        dx: pt.x - b.refRect.x,
        dy: pt.y - b.refRect.y
      };
      render();
      renderParts();
      e.preventDefault();
      return;
    }

    // Text annotation role
    if (role === 'text') {
      state.selected = null;
      state.redactSelected = null;
      const t = state.texts.find(x => x.id === id);
      if (!t) return;
      if (state.tool === 'delete') {
        state.texts = state.texts.filter(x => x.id !== id);
        if (state.textSelected === id) state.textSelected = null;
        syncTextPage();
        render();
        updateFooter();
        return;
      }
      state.textSelected = id;
      const pt = getCanvasPoint(e.clientX, e.clientY);
      state.textDrag = { id, dx: pt.x - t.x, dy: pt.y - t.y };
      render();
      e.preventDefault();
      return;
    }

    // Bubble roles
    const b = state.bubbles.find(x => x.id === id);
    if (!b) return;

    if (state.tool === 'delete') {
      deleteBubble(id);
      return;
    }

    state.redactSelected = null;
    state.selected = id;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    if (role === 'bubble') {
      state.drag = { type: 'bubble', id, dx: pt.x - b.x, dy: pt.y - b.y };
    } else if (role === 'anchor') {
      state.drag = { type: 'anchor', id, dx: pt.x - b.ax, dy: pt.y - b.ay };
    }
    render();
    renderParts();
    e.preventDefault();
  });

  window.addEventListener('mousemove', e => {
    if (!state.drag) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    const b = state.bubbles.find(x => x.id === state.drag.id);
    if (!b) return;
    if (state.drag.type === 'bubble') {
      b.x = pt.x - state.drag.dx;
      b.y = pt.y - state.drag.dy;
    } else if (state.drag.type === 'anchor') {
      b.ax = pt.x - state.drag.dx;
      b.ay = pt.y - state.drag.dy;
    } else if (state.drag.type === 'ref-rect-move') {
      // Drag the whole rectangle, preserving size
      if (!b.refRect) return;
      b.refRect.x = pt.x - state.drag.dx;
      b.refRect.y = pt.y - state.drag.dy;
    } else if (state.drag.type === 'ref-rect-resize') {
      // Resize from one of the corners. Compute new rect from the OPPOSITE
      // corner (fixed) + the current cursor position. Allow negative dimensions
      // mid-drag by normalising at the end (here we just clamp to a minimum).
      if (!b.refRect) return;
      const orig = state.drag.orig;
      const corner = state.drag.corner;
      // Identify the fixed corner (opposite the dragged one)
      const fixedX = (corner === 'nw' || corner === 'sw') ? (orig.x + orig.w) : orig.x;
      const fixedY = (corner === 'nw' || corner === 'ne') ? (orig.y + orig.h) : orig.y;
      const newX = Math.min(fixedX, pt.x);
      const newY = Math.min(fixedY, pt.y);
      const newW = Math.max(8, Math.abs(pt.x - fixedX));
      const newH = Math.max(8, Math.abs(pt.y - fixedY));
      b.refRect.x = newX;
      b.refRect.y = newY;
      b.refRect.w = newW;
      b.refRect.h = newH;
    }
    render();
  });

  // Text annotation drag
  window.addEventListener('mousemove', e => {
    if (!state.textDrag) return;
    const t = state.texts.find(x => x.id === state.textDrag.id);
    if (!t) return;
    const pt = getCanvasPoint(e.clientX, e.clientY);
    t.x = pt.x - state.textDrag.dx;
    t.y = pt.y - state.textDrag.dy;
    render();
  });
  window.addEventListener('mouseup', () => {
    if (state.textDrag) {
      syncTextPage();
      state.textDrag = null;
    }
  });

  window.addEventListener('mouseup', () => {
    if (state.drag) syncPage();
    state.drag = null;
  });

  // ============ DOUBLE-CLICK TO RENAME ============
  annotationsSvg.addEventListener('dblclick', e => {
    // Text annotation: open editor
    const textTarget = e.target.closest('.text-annot');
    if (textTarget) {
      const id = textTarget.dataset.id;
      const t = state.texts.find(x => x.id === id);
      if (t) {
        state.textSelected = id;
        render();
        openTextEditor(t);
      }
      return;
    }
    // Bubble: rename number — accepts any text (5, 5a, 5.1, …),
    // capped at 8 chars to match the inspection_template_items.bubble_no
    // VARCHAR(8) column downstream.
    const target = e.target.closest('.bubble-group');
    if (!target) return;
    const id = target.dataset.id;
    const b = state.bubbles.find(x => x.id === id);
    if (!b) return;
    const newNum = prompt('Bubble number (max 8 chars, any text — e.g. 5, 5a, 5.1):', b.num);
    if (newNum !== null && newNum.trim() !== '') {
      b.num = newNum.trim().slice(0, 8);
      syncPage();
      render();
      renderParts();
    }
  });

  // ============ DELETE BUBBLE ============
  function deleteBubble(id) {
    state.bubbles = state.bubbles.filter(b => b.id !== id);
    if (state.selected === id) state.selected = null;
    syncPage();
    render();
    renderParts();
    updateFooter();
  }

  // ============ KEYBOARD ============
  window.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if ((e.key === 'Delete' || e.key === 'Backspace') && state.textSelected) {
      state.texts = state.texts.filter(t => t.id !== state.textSelected);
      state.textSelected = null;
      syncTextPage();
      updateFooter();
      render();
    } else if ((e.key === 'Delete' || e.key === 'Backspace') && state.redactSelected) {
      state.redactions = state.redactions.filter(r => r.id !== state.redactSelected);
      state.redactSelected = null;
      syncRedactPage();
      updateFooter();
      render();
    } else if ((e.key === 'Delete' || e.key === 'Backspace') && state.selected) {
      deleteBubble(state.selected);
    } else if (e.key === 'b' || e.key === 'B') {
      document.querySelector('[data-tool="bubble"]').click();
    } else if (e.key === 'x' || e.key === 'X') {
      document.querySelector('[data-tool="redact"]').click();
    } else if (e.key === 't' || e.key === 'T') {
      document.querySelector('[data-tool="text"]').click();
    } else if (e.key === 'v' || e.key === 'V') {
      document.querySelector('[data-tool="select"]').click();
    } else if (e.key === 'h' || e.key === 'H') {
      document.querySelector('[data-tool="pan"]').click();
    } else if (e.key === 'Escape') {
      state.selected = null;
      state.redactSelected = null;
      state.textSelected = null;
      closeTextEditor();
      render();
      renderParts();
    } else if (e.key === '+' || e.key === '=') {
      e.preventDefault(); setZoom(state.zoom * 1.25);
    } else if (e.key === '-' || e.key === '_') {
      e.preventDefault(); setZoom(state.zoom / 1.25);
    } else if (e.key === '0') {
      e.preventDefault(); setZoom(1);
    } else if (e.key === 'f' || e.key === 'F') {
      e.preventDefault(); fitToScreen();
    } else if (e.key === ']') {
      e.preventDefault(); setRotation(state.rotation + 90);
    } else if (e.key === '[') {
      e.preventDefault(); setRotation(state.rotation - 90);
    } else if (e.key === 'r' || e.key === 'R') {
      e.preventDefault(); setRotation(0);
    } else if (state.drawing.type === 'pdf' && (e.key === 'PageDown' || e.key === 'ArrowRight')) {
      if (state.pdf.page < state.pdf.total) { e.preventDefault(); renderPdfPage(state.pdf.page + 1); }
    } else if (state.drawing.type === 'pdf' && (e.key === 'PageUp' || e.key === 'ArrowLeft')) {
      if (state.pdf.page > 1) { e.preventDefault(); renderPdfPage(state.pdf.page - 1); }
    }
  });

  // ============ PARTS LIST ============
  // GD&T symbol palette — common geometric tolerancing characters
  const GDT_SYMBOLS = [
    { sym: '⌖', name: 'Position' },
    { sym: '○', name: 'Circularity' },
    { sym: '⌭', name: 'Cylindricity' },
    { sym: '⏥', name: 'Flatness' },
    { sym: '⏤', name: 'Straightness' },
    { sym: '⌒', name: 'Profile (line)' },
    { sym: '⌓', name: 'Profile (surf)' },
    { sym: '∥', name: 'Parallelism' },
    { sym: '⊥', name: 'Perpendicular' },
    { sym: '∠', name: 'Angularity' },
    { sym: '⌯', name: 'Symmetry' },
    { sym: '◎', name: 'Concentricity' },
    { sym: '↗', name: 'Runout' },
    { sym: '⌰', name: 'Total runout' }
  ];

  function renderParts() {
    if (state.bubbles.length === 0) {
      partsTable.innerHTML = '<div class="parts-empty">— NO BUBBLES YET —<br>CLICK ON THE DRAWING TO ADD</div>';
      $('parts-count').textContent = '0 ITEMS' + (state.drawing.type === 'pdf' ? ' · PAGE ' + state.pdf.page : '');
      return;
    }
    partsTable.innerHTML = '';

    // Apply search filter (state.partsSearch is updated by the search input).
    // Empty query → show everything. Otherwise case-insensitively match
    // against number, description, dimension text, gridCell, raw text, notes.
    const q = (state.partsSearch || '').trim().toLowerCase();
    const filtered = q ? state.bubbles.filter(b => {
      ensureDim(b);
      const haystack = [
        String(b.num),
        state.prefix + b.num,
        b.label || '',
        b.gridCell || '',
        formatDim(b),
        b.dim.rawText || '',
        b.dim.notes || '',
        b.dim.gdtSym || '',
        b.dim.gdtTol || '',
        b.dim.gdtDatum || ''
      ].join(' ').toLowerCase();
      return haystack.includes(q);
    }) : state.bubbles;

    if (filtered.length === 0) {
      partsTable.innerHTML = '<div class="parts-empty">No matches for "' + escapeHtml(q) + '"</div>';
      $('parts-count').textContent = '0 of ' + state.bubbles.length + ' MATCH' +
        (state.drawing.type === 'pdf' ? ' · PAGE ' + state.pdf.page : '');
      return;
    }

    filtered.forEach(b => {
      ensureDim(b);
      const isExpanded = state.expanded.has(b.id);
      const isSelected = state.selected === b.id;
      const dimText = formatDim(b);

      const row = document.createElement('div');
      row.className = 'parts-row' + (isSelected ? ' selected' : '') + (isExpanded ? ' expanded' : '');

      // Summary row
      const summary = document.createElement('div');
      summary.className = 'summary';
      const autoBadge = (b.dim.autoSource && b.dim.autoSource !== 'manual')
        ? `<span class="ab-badge" title="Auto-detected from ${b.dim.autoSource}${b.dim.parseConfidence ? ' · ' + b.dim.parseConfidence + ' confidence' : ''}">AUTO</span>`
        : '';
      const cellBadge = b.gridCell
        ? `<span class="cell-badge" title="Grid cell">${escapeHtml(b.gridCell)}</span>`
        : '';
      summary.innerHTML = `
        <div class="num${b.dim.critical ? ' critical' : ''}" title="${b.dim.critical ? 'Critical / Key Characteristic' : ''}">
          ${state.prefix ? `<span class="num-prefix">${escapeHtml(state.prefix)}</span>` : ''}<input type="text" class="num-input" data-action="edit-num" data-id="${b.id}" value="${escapeHtml(String(b.num))}" title="Edit bubble number (any text — 5, 5a, 5.1)">${autoBadge}${cellBadge}
        </div>
        <div class="desc-line">
          ${b.label
            ? `<div class="desc-text">${escapeHtml(b.label)}</div>`
            : `<div class="desc-text placeholder">Description / part no.</div>`}
          ${dimText ? `<div class="dim-text">${escapeHtml(dimText)}</div>` : ''}
        </div>
        <button class="expand-toggle" data-action="expand" data-id="${b.id}" title="Edit dimension details">▸</button>
        <button class="del" data-action="del" data-id="${b.id}" title="Delete">×</button>
      `;
      row.appendChild(summary);

      // Detail editor (only build markup if needed; cheap enough either way)
      const detail = document.createElement('div');
      detail.className = 'detail';
      detail.innerHTML = buildDetailEditor(b);
      row.appendChild(detail);

      partsTable.appendChild(row);
    });

    wireUpPartsRows();
    const total = state.bubbles.length;
    const pageSfx = (state.drawing.type === 'pdf' ? ' · PAGE ' + state.pdf.page : '');
    if (q) {
      $('parts-count').textContent = filtered.length + ' of ' + total + (total === 1 ? ' ITEM' : ' ITEMS') + pageSfx;
    } else {
      $('parts-count').textContent = total + (total === 1 ? ' ITEM' : ' ITEMS') + pageSfx;
    }
  }

  function buildDetailEditor(b) {
    const d = b.dim;
    const isGdt = d.type === 'gdt';
    const showAngleUnit = d.type === 'angle';
    const rawTextField = d.rawText ? `
      <div class="field-mini">
        <label>Raw text from drawing <span style="color: var(--text-light); font-weight: normal; font-size: 10px; text-transform: none; letter-spacing: 0;">(as detected — source of truth)</span></label>
        <div style="display: flex; gap: 6px; align-items: stretch;">
          <input type="text" data-field="rawText" data-id="${b.id}" value="${escapeHtml(d.rawText)}" style="flex: 1; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--surface-alt);">
          ${d.altRawText ? `
            <button type="button" class="mini-btn" data-action="swap-alt" data-id="${b.id}"
                    title="Swap to the alternate reading from ${escapeHtml(d.altSource || 'other engine')}: ${escapeHtml(d.altRawText)}"
                    style="padding: 5px 8px; font-size: 10px; white-space: nowrap;">
              ↔ ${escapeHtml(d.altSource === 'ocr' ? 'OCR' : (d.altSource || 'alt'))}
            </button>
          ` : ''}
        </div>
        ${d.altRawText ? `
          <div style="font-size: 10px; color: var(--text-light); margin-top: 3px;">
            Alt reading: <code style="font-family: ui-monospace, monospace;">${escapeHtml(d.altRawText)}</code>
          </div>
        ` : ''}
      </div>
    ` : '';
    return `
      ${rawTextField}
      <div class="field-mini">
        <label>Description / Part Number</label>
        <input type="text" data-field="label" data-id="${b.id}" value="${escapeHtml(b.label)}" placeholder="e.g. Hex bolt M8x25, Bracket P/N 4521-A">
      </div>

      <div class="field-mini">
        <label>Dimension Type</label>
        <select data-field="type" data-id="${b.id}">
          <option value="linear" ${d.type==='linear'?'selected':''}>Linear</option>
          <option value="diameter" ${d.type==='diameter'?'selected':''}>Diameter (Ø)</option>
          <option value="radius" ${d.type==='radius'?'selected':''}>Radius (R)</option>
          <option value="angle" ${d.type==='angle'?'selected':''}>Angle (°)</option>
          <option value="reference" ${d.type==='reference'?'selected':''}>Reference (basic)</option>
          <option value="gdt" ${d.type==='gdt'?'selected':''}>GD&amp;T (Geometric)</option>
        </select>
      </div>

      <div class="standard-block ${isGdt ? 'hidden' : ''}">
        <div class="field-mini">
          <label>Nominal Value &amp; Unit</label>
          <div class="grid-2">
            <input type="text" data-field="nominal" data-id="${b.id}" value="${escapeHtml(d.nominal)}" placeholder="${showAngleUnit ? 'e.g. 30' : 'e.g. 25.40'}">
            <select data-field="unit" data-id="${b.id}" ${showAngleUnit ? 'disabled' : ''}>
              ${showAngleUnit
                ? '<option value="deg" selected>deg (°)</option>'
                : `
                <option value="mm" ${d.unit==='mm'?'selected':''}>mm</option>
                <option value="in" ${d.unit==='in'?'selected':''}>in</option>
                <option value="none" ${d.unit==='none'?'selected':''}>—</option>
                `}
            </select>
          </div>
        </div>

        <div class="field-mini">
          <label>Tolerance</label>
          <div class="row-tol">
            <input type="text" data-field="tolPlus" data-id="${b.id}" value="${escapeHtml(d.tolPlus)}" placeholder="+ upper">
            <input type="text" data-field="tolMinus" data-id="${b.id}" value="${escapeHtml(d.tolMinus)}" placeholder="− lower">
            <button type="button" class="mini-btn" data-action="symmetric" data-id="${b.id}" title="Make symmetric (±)" style="padding:5px 6px;font-size:9px;">SYM ±</button>
          </div>
        </div>
      </div>

      <div class="gdt-block ${isGdt ? 'visible' : ''}">
        <div class="field-mini">
          <label>Geometric Characteristic</label>
          <div class="gdt-symbols">
            ${GDT_SYMBOLS.map(g => `<button type="button" class="${d.gdtSym === g.sym ? 'active' : ''}" data-action="gdt-sym" data-sym="${g.sym}" data-id="${b.id}" title="${g.name}">${g.sym}</button>`).join('')}
          </div>
        </div>
        <div class="field-mini">
          <label>Tolerance Value &amp; Datum References</label>
          <div class="grid-2">
            <input type="text" data-field="gdtTol" data-id="${b.id}" value="${escapeHtml(d.gdtTol)}" placeholder="e.g. 0.05 Ⓜ">
            <input type="text" data-field="gdtDatum" data-id="${b.id}" value="${escapeHtml(d.gdtDatum)}" placeholder="e.g. A|B|C">
          </div>
        </div>
      </div>

      <div class="field-mini">
        <label>Inspection Notes</label>
        <textarea data-field="notes" data-id="${b.id}" rows="2" placeholder="Method, gauge, AQL, etc.">${escapeHtml(d.notes)}</textarea>
      </div>

      <label class="checkbox-row">
        <input type="checkbox" data-field="critical" data-id="${b.id}" ${d.critical ? 'checked' : ''}>
        <span>CRITICAL / KEY CHARACTERISTIC</span>
      </label>
    `;
  }

  function wireUpPartsRows() {
    // Row click to select
    partsTable.querySelectorAll('.summary').forEach(s => {
      s.addEventListener('click', e => {
        if (e.target.closest('button')) return;
        const row = s.closest('.parts-row');
        // Find the bubble id from the delete/expand button as a stable anchor
        const btn = row.querySelector('[data-action="del"]');
        if (!btn) return;
        state.selected = btn.dataset.id;
        render();
        renderParts();
      });
    });

    // Expand/collapse
    partsTable.querySelectorAll('[data-action="expand"]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const id = btn.dataset.id;
        if (state.expanded.has(id)) state.expanded.delete(id);
        else state.expanded.add(id);
        renderParts();
      });
    });

    // Delete
    partsTable.querySelectorAll('[data-action="del"]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        deleteBubble(btn.dataset.id);
      });
    });

    // Inline edit of the bubble number — accepts any text (digits, "5a",
    // "5.1", "FOO" — VARCHAR(8) in the database). Commits on Enter or blur;
    // empty values revert to the previous number.
    partsTable.querySelectorAll('[data-action="edit-num"]').forEach(inp => {
      // Stop click propagation so typing inside the input doesn't also
      // trigger the row-select handler (which would re-render and blow
      // away the input focus).
      inp.addEventListener('click', e => { e.stopPropagation(); });
      inp.addEventListener('mousedown', e => { e.stopPropagation(); });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          inp.blur();        // triggers the change handler below
        } else if (e.key === 'Escape') {
          // Revert to current state
          const b = state.bubbles.find(x => x.id === inp.dataset.id);
          if (b) inp.value = String(b.num);
          inp.blur();
        }
      });
      inp.addEventListener('change', () => {
        const b = state.bubbles.find(x => x.id === inp.dataset.id);
        if (!b) return;
        const v = inp.value.trim();
        if (v === '') {
          inp.value = String(b.num);   // revert
          return;
        }
        // Cap at 8 chars to match the inspection_template_items.bubble_no
        // VARCHAR(8) column — same constraint used downstream.
        const trimmed = v.slice(0, 8);
        b.num = trimmed;
        inp.value = trimmed;
        syncPage();
        render();
        // Don't full re-render parts (would lose focus); just resync the
        // SVG which actually shows the new number on the drawing.
      });
    });

    // Symmetric tolerance helper: copy + value to − value
    partsTable.querySelectorAll('[data-action="symmetric"]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const b = state.bubbles.find(x => x.id === btn.dataset.id);
        if (!b) return;
        const d = ensureDim(b);
        if (d.tolPlus && !d.tolMinus) d.tolMinus = d.tolPlus;
        else if (d.tolMinus && !d.tolPlus) d.tolPlus = d.tolMinus;
        else if (d.tolPlus && d.tolMinus) d.tolMinus = d.tolPlus; // make match
        syncPage();
        renderParts();
      });
    });

    // Swap alt: dual-engine extraction gave us two readings for this
    // bubble (PDF.js + OCR disagreed). The button swaps the current
    // primary with the alternate, and re-parses so the structured
    // fields (type, nominal, tol, unit) update to reflect the new
    // primary text. We also need to swap altSource so the button
    // label still names the OTHER engine after the swap.
    partsTable.querySelectorAll('[data-action="swap-alt"]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const b = state.bubbles.find(x => x.id === btn.dataset.id);
        if (!b) return;
        const d = ensureDim(b);
        if (!d.altRawText) return;
        // Swap rawText <-> altRawText, and primary source <-> alt source.
        // After the swap, the "alt" is the previously-primary reading.
        const newPrimary = d.altRawText;
        const newAlt     = d.rawText;
        // primary's "source" lives in autoSource ('text-layer' | 'ocr');
        // altSource holds 'ocr' or 'pdfjs'. Map between the two
        // vocabularies on swap.
        const primaryWasFromOcr = (d.autoSource === 'ocr');
        const altWasFromOcr     = (d.altSource  === 'ocr');
        d.rawText    = newPrimary;
        d.altRawText = newAlt;
        d.autoSource = altWasFromOcr     ? 'ocr' : 'text-layer';
        d.altSource  = primaryWasFromOcr ? 'ocr' : 'pdfjs';
        // Re-parse so structured fields (type, nominal, tol, unit)
        // reflect the new primary text. parseDimension may return
        // null if the new primary is unparseable; in that case we
        // keep the existing parsed values rather than wiping them
        // (the user can still hand-edit if needed).
        const reparsed = parseDimension(newPrimary, d.unit || null);
        if (reparsed) {
          if (reparsed.type)     d.type     = reparsed.type;
          if (reparsed.nominal !== undefined) d.nominal = reparsed.nominal;
          if (reparsed.unit)     d.unit     = reparsed.unit;
          if (reparsed.tolPlus !== undefined)  d.tolPlus  = reparsed.tolPlus;
          if (reparsed.tolMinus !== undefined) d.tolMinus = reparsed.tolMinus;
        }
        syncPage();
        renderParts();
      });
    });

    // GD&T symbol picker
    partsTable.querySelectorAll('[data-action="gdt-sym"]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const b = state.bubbles.find(x => x.id === btn.dataset.id);
        if (!b) return;
        const d = ensureDim(b);
        // Toggle: clicking the active symbol clears it
        d.gdtSym = (d.gdtSym === btn.dataset.sym) ? '' : btn.dataset.sym;
        syncPage();
        renderParts();
      });
    });

    // Field input wiring (label + dim.* fields)
    partsTable.querySelectorAll('[data-field]').forEach(input => {
      const handler = e => {
        const b = state.bubbles.find(x => x.id === input.dataset.id);
        if (!b) return;
        const field = input.dataset.field;
        if (field === 'label') {
          b.label = input.value;
        } else if (field === 'critical') {
          ensureDim(b).critical = input.checked;
          // Re-render so the danger-dot indicator updates on the summary
          syncPage();
          renderParts();
          return;
        } else if (field === 'type') {
          const d = ensureDim(b);
          d.type = input.value;
          // Auto-set unit for angle
          if (d.type === 'angle') d.unit = 'deg';
          else if (d.unit === 'deg') d.unit = 'mm';
          syncPage();
          renderParts(); // re-render to swap GD&T vs standard block
          return;
        } else {
          ensureDim(b)[field] = input.value;
        }
        syncPage();
        // Live-update only the summary line for performance
        const row = input.closest('.parts-row');
        if (row) {
          const descText = row.querySelector('.desc-text');
          const dimLine = row.querySelector('.dim-text');
          if (descText) {
            if (b.label) {
              descText.textContent = b.label;
              descText.classList.remove('placeholder');
            } else {
              descText.textContent = 'Description / part no.';
              descText.classList.add('placeholder');
            }
          }
          const dimStr = formatDim(b);
          if (dimLine) {
            if (dimStr) dimLine.textContent = dimStr;
            else dimLine.remove();
          } else if (dimStr) {
            const dl = document.createElement('div');
            dl.className = 'dim-text';
            dl.textContent = dimStr;
            row.querySelector('.desc-line').appendChild(dl);
          }
        }
      };
      input.addEventListener('input', handler);
      input.addEventListener('change', handler);
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
  }

  // Keep per-page bubble storage in sync (PDF mode only)
  function syncPage() {
    if (state.drawing.type === 'pdf') {
      state.pageBubbles[state.pdf.page] = state.bubbles.slice();
    }
  }

  // Defensive: ensure every bubble has a dim object (for forward-compat)
  function ensureDim(b) {
    if (!b.dim) {
      b.dim = {
        type: 'linear', nominal: '', unit: 'mm',
        tolPlus: '', tolMinus: '',
        gdtSym: '', gdtTol: '', gdtDatum: '',
        critical: false, notes: '',
        // Auto-bubble fields
        rawText: '',         // exact text the parser saw on the drawing
        altRawText: '',      // alternate reading from the other engine (dual-engine)
        altSource: '',       // '' | 'ocr' | 'pdfjs' — which engine produced altRawText
        autoSource: '',      // '' | 'text-layer' | 'ocr' | 'manual'
        parseConfidence: ''  // '' | 'high' | 'medium' | 'low'
      };
    }
    // Migrate older bubbles that lack the new fields
    if (b.dim.rawText === undefined)         b.dim.rawText = '';
    if (b.dim.altRawText === undefined)      b.dim.altRawText = '';
    if (b.dim.altSource === undefined)       b.dim.altSource = '';
    if (b.dim.autoSource === undefined)      b.dim.autoSource = '';
    if (b.dim.parseConfidence === undefined) b.dim.parseConfidence = '';
    return b.dim;
  }

  // Format dimension as a single human-readable string
  // Examples: "Ø25.40 +0.05/-0.02 mm", "30° ±1°", "⊥ 0.05 A|B"
  function formatDim(b) {
    const d = ensureDim(b);
    if (!d.nominal && !d.gdtTol) return '';
    if (d.type === 'gdt') {
      const parts = [];
      if (d.gdtSym) parts.push(d.gdtSym);
      if (d.gdtTol) parts.push(d.gdtTol);
      if (d.gdtDatum) parts.push(d.gdtDatum);
      return parts.join(' ');
    }
    let prefix = '';
    if (d.type === 'diameter') prefix = 'Ø';
    else if (d.type === 'radius') prefix = 'R';
    else if (d.type === 'reference') prefix = '(';
    let nominal = prefix + d.nominal;
    if (d.type === 'reference' && d.nominal) nominal += ')';
    let tol = '';
    if (d.tolPlus || d.tolMinus) {
      // If symmetric, write ±value
      if (d.tolPlus && d.tolMinus && d.tolPlus === d.tolMinus) {
        tol = ' ±' + d.tolPlus;
      } else {
        const plus = d.tolPlus ? '+' + d.tolPlus : '';
        const minus = d.tolMinus ? '-' + d.tolMinus : '';
        tol = ' ' + [plus, minus].filter(Boolean).join('/');
      }
    }
    let unit = '';
    if (d.unit && d.unit !== 'none') {
      if (d.type === 'angle') unit = '°';
      else unit = ' ' + d.unit;
      // For angle, the ° goes right after the number (no space)
      if (d.type === 'angle') {
        nominal = nominal + '°';
        unit = '';
        // Tolerance for angles also gets °
        if (tol) tol = tol.replace(/(\d+(?:\.\d+)?)/g, '$1°');
      }
    }
    return (nominal + tol + unit).trim();
  }

  function updateFooter() {
    $('footer-count').textContent = state.bubbles.length;
    const fr = $('footer-redact-count'); if (fr) fr.textContent = state.redactions.length;
    const ft = $('footer-text-count'); if (ft) ft.textContent = state.texts.length;
  }

  // ============ RENUMBER & CLEAR ============
  $('btn-renumber').addEventListener('click', () => {
    state.bubbles.forEach((b, i) => { b.num = i + 1; });
    state.nextId = state.bubbles.length + 1;
    $('next-number').value = state.nextId;
    syncPage();
    render();
    renderParts();
  });

  $('btn-clear-bubbles').addEventListener('click', () => {
    if (state.bubbles.length === 0) return;
    const scope = state.drawing.type === 'pdf' ? 'on this page' : '';
    if (!confirm('Delete all ' + state.bubbles.length + ' bubbles' + (scope ? ' ' + scope : '') + '?')) return;
    state.bubbles = [];
    state.selected = null;
    syncPage();
    // Recompute next number across all pages
    let maxNum = 0;
    Object.values(state.pageBubbles).forEach(arr => {
      arr.forEach(b => { const n = parseInt(b.num); if (!isNaN(n) && n > maxNum) maxNum = n; });
    });
    state.nextId = maxNum + 1;
    $('next-number').value = state.nextId;
    render();
    renderParts();
    updateFooter();
  });

  $('btn-clear').addEventListener('click', () => {
    if (!confirm('Clear drawing and all bubbles?')) return;
    state.bubbles = [];
    state.pageBubbles = {};
    state.redactions = [];
    state.pageRedactions = {};
    state.texts = [];
    state.pageTexts = {};
    state.selected = null;
    state.redactSelected = null;
    state.textSelected = null;
    state.pageOrder = null;
    state.pageDeleted = {};
    state.nextId = 1;
    state.drawing.loaded = false;
    state.drawing.type = null;
    state.pdf.doc = null;
    state.pdf.originalBytes = null;
    state.pdf.sources = null;
    state.pdf.page = 1;
    state.pdf.total = 1;
    drawingImg.src = '';
    drawingImg.style.display = 'none';
    emptyState.style.display = '';
    $('page-nav').classList.remove('visible');
    $('next-number').value = 1;
    render();
    renderParts();
    updateFooter();
  });

  // ============================================================
  // AUTO-BUBBLE — detect dimensions in a PDF and place bubbles
  // ============================================================
  //
  // Two-tier strategy:
  //   1. PDF text-layer extraction via PDF.js (instant, perfect accuracy for
  //      born-digital PDFs). Try this first.
  //   2. OCR fallback via Tesseract.js (slow, lossy, for scanned PDFs).
  //      Only loaded on demand to avoid the 3MB hit for users who don't need it.
  //
  // Detected text is run through a dimension parser that recognises common
  // engineering notations: linear, ±tolerance, +/- tolerance, Ø/R prefixes,
  // GD&T frames, multiplicity (2X, 4X), thru/max modifiers, basic dimensions.
  //
  // Parsed bubbles get all available structured fields populated, plus the
  // raw detected text in dim.rawText (source of truth for QA).
  // ============================================================

  const ab = {
    // Backup of bubbles before auto-bubble runs — restored on Cancel
    snapshot: null,
    // Newly added bubbles in the current run (for the preview panel)
    added: [],
    // Lazy-loaded Tesseract worker
    tesseractPromise: null
  };

  // ---- Dimension parser ----------------------------------------------------
  // Returns null if the text isn't a dimension; otherwise an object with
  // structured fields and a confidence rating.
  //
  // pageUnit: optional 'mm' or 'in' hint from the drawing's title block. When
  // provided, this overrides the per-number unit guess from guessUnit().
  function parseDimension(rawText, pageUnit) {
    // Clean up the input
    let s = (rawText || '').trim();
    if (!s) return null;
    // Replace unicode minus & dashes with hyphens for easier matching
    s = s.replace(/[−–—]/g, '-');
    // Ø symbol normalization
    const hasDiamSymbol = /[Ø⌀]/.test(s);

    // Square brackets denote dual-unit equivalent values. The caller decides
    // whether to skip these entirely (default) or bubble them with a ".a"
    // suffix. To keep this parser stateless, the caller pre-strips the
    // brackets before invoking us. So here we just reject anything that
    // STILL has surrounding brackets — that means the caller chose to skip.
    if (/^\[.*\]$/.test(s)) return null;

    // Round parens denote reference dimensions per ASME Y14.5. We still bubble
    // them (they're inspection-relevant) but strip the parens for parsing.
    const isReference = /^\(.*\)$/.test(s);
    if (isReference) s = s.slice(1, -1).trim();

    // Helper: pick the unit. Title-block hint wins if provided; else fall back
    // to guessUnit based on the number's format.
    const pickUnit = (numStr) => pageUnit || guessUnit(numStr);

    // Multiplicity prefix: 2X, 4x, etc.
    let multiplicity = 1;
    const multMatch = s.match(/^(\d+)\s*[xX×]\s+/);
    if (multMatch) {
      multiplicity = parseInt(multMatch[1], 10);
      s = s.slice(multMatch[0].length);
    }

    // Strip leading Ø/R prefix
    let type = 'linear';
    if (/^[Ø⌀]/.test(s)) { type = 'diameter'; s = s.replace(/^[Ø⌀]\s*/, ''); }
    else if (hasDiamSymbol) { type = 'diameter'; s = s.replace(/[Ø⌀]/, '').trim(); }
    else if (/^R(?=[\d.])/.test(s)) { type = 'radius'; s = s.slice(1).trim(); }

    // Reject things that are clearly not dimensions
    // - pure scale notation: "1:1", "1:2"
    if (/^\d+\s*:\s*\d+$/.test(s)) return null;
    // - sheet/revision callouts: "SH 1 OF 4", "REV A"
    if (/^(SH|SHEET|REV|REVISION|DWG|DRAWING|SCALE|PROJ)\b/i.test(s)) return null;
    // - dates: "12/04/2024"
    if (/^\d{1,2}\/\d{1,2}\/\d{2,4}$/.test(s)) return null;
    // - text labels with letters that look like words, not engineering notations
    // (We allow letter suffixes for thread classes like H7, but reject if mostly
    // letters and no leading digit)
    if (!/\d/.test(s)) return null;

    // Thread callouts: M10x1.5, M10, 1/2-13 UNC
    let threadMatch = s.match(/^M(\d+(?:\.\d+)?)\s*[xX×]\s*(\d+(?:\.\d+)?)/);
    if (threadMatch) {
      return {
        type: 'linear',
        nominal: threadMatch[1],
        tolPlus: '',
        tolMinus: '',
        unit: 'mm',
        rawText: rawText,
        notes: 'Metric thread M' + threadMatch[1] + 'x' + threadMatch[2],
        confidence: 'high'
      };
    }
    // Imperial thread: 1/2-13 UNC, 1/4-20 UNF, #6-32 (skip)
    if (/^\d+\/\d+\s*-\s*\d+\s+UN[CFR]/.test(s) || /^#\d+-\d+/.test(s)) {
      return {
        type: 'linear', nominal: '',
        tolPlus: '', tolMinus: '',
        unit: 'in',
        rawText: rawText,
        notes: 'Thread callout',
        confidence: 'medium'
      };
    }

    // ===== GD&T detection (broad) =====
    //
    // Engineering drawings encode GD&T in many incompatible ways:
    //   (a) Proper Unicode symbols:   ⊥ 0.05 A|B
    //   (b) AutoCAD %% escape codes:  %%c 0.05 A B   (%%c = Ø, %%p = ±, %%d = °)
    //   (c) Custom CAD fonts: symbol comes through as an arbitrary glyph that
    //       PDF.js extracts as garbage like "j" or "5"
    //   (d) Vector geometry only: no text at all (we can't see this)
    //
    // We try the structural shape first — most feature control frames look
    // like "<symbol?> <tolerance-number> <one-or-more-datum-letters>". If we
    // see that shape, we treat it as GD&T even if the symbol is unrecognised.
    // The user gets a low/medium-confidence bubble with the raw text intact
    // and the structural fields populated where we could parse them.

    const knownSyms = new Set([
      '⌖','⊥','∥','∠','⌓','⌒','⌭','⌯','⌰','⌱','⌲','⌳','⏥','◎','○','⌀','▱','⊕',
      '⊙','⊚','⊝','⌭','⌯','⌰','═','═','◇','⌗','⌜','⌝','⌞','⌟','⌐','⌑','⌒'
    ]);
    // AutoCAD escape codes mapped to their visual equivalents
    const acadMap = {
      '%%c': '⌀',   // diameter
      '%%C': '⌀',
      '%%p': '±',   // plus/minus
      '%%P': '±',
      '%%d': '°',   // degrees
      '%%D': '°'
    };
    // Decode AutoCAD escapes for parsing while keeping rawText untouched
    let decoded = s;
    for (const code in acadMap) {
      decoded = decoded.split(code).join(acadMap[code]);
    }

    // Material condition modifiers — replaced with circled Unicode for storage
    const modifierMap = {
      ' M ': ' Ⓜ ', ' L ': ' Ⓛ ', ' S ': ' Ⓢ ', ' P ': ' Ⓟ ', ' F ': ' Ⓕ '
    };
    let modified = decoded;
    for (const k in modifierMap) modified = modified.split(k).join(modifierMap[k]);

    const firstChar = modified.charAt(0);
    const hasKnownSym = knownSyms.has(firstChar);

    // Structural patterns. Letters used for datums are typically capital A–N
    // (skipping I, O, Q which are reserved). We allow A–Z to be safe.
    // Tolerance can be Ø/⌀-prefixed for cylindrical zones.
    //
    // Pattern A — full feature control frame WITH known symbol prefix:
    //   ⊥ 0.05 A
    //   ⊥ Ø0.05 A|B|C
    //   ⊥ 0.05 Ⓜ A Ⓜ B
    // Pattern B — frame WITHOUT a recognised symbol but with the right shape:
    //   0.05 A
    //   0.05 A|B|C
    //   Ø0.05 A B C
    // Pattern C — symbol present but we can't tell what it is (single non-
    //   alphanumeric character followed by tolerance+datum shape):
    //   <weird-glyph> 0.05 A

    // Try Pattern A first (highest confidence)
    if (hasKnownSym) {
      const rest = modified.slice(1).trim();
      const frameMatch = rest.match(
        /^([⌀Ø]?\d+(?:\.\d+)?(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*[|,\s]\s*([A-Z](?:[|,\s]+[A-Z]){0,4}(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*$/
      );
      if (frameMatch) {
        return {
          type: 'gdt',
          gdtSym: firstChar,
          gdtTol: frameMatch[1].trim(),
          gdtDatum: frameMatch[2].replace(/[\s,]+/g, '|'),
          nominal: '', tolPlus: '', tolMinus: '',
          unit: 'mm',
          rawText: rawText,
          confidence: 'high'
        };
      }
      // Symbol with just a tolerance, no datums (form tolerances: flatness,
      // straightness, roundness, cylindricity)
      const tolOnly = rest.match(/^([⌀Ø]?\d+(?:\.\d+)?(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*$/);
      if (tolOnly) {
        return {
          type: 'gdt',
          gdtSym: firstChar,
          gdtTol: tolOnly[1].trim(),
          gdtDatum: '',
          nominal: '', tolPlus: '', tolMinus: '',
          unit: 'mm',
          rawText: rawText,
          confidence: 'high'
        };
      }
    }

    // Try Pattern B — bare structural match, no recognised symbol.
    // Requires: tolerance value at start (small number, ≤ 10) AND at least
    // one datum-letter group at end.
    {
      const bareMatch = modified.match(
        /^([⌀Ø]?\d+(?:\.\d+)?(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*[|,\s]\s*([A-Z](?:[|,\s]+[A-Z]){0,4}(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*$/
      );
      if (bareMatch) {
        const tolVal = parseFloat(bareMatch[1].replace(/[⌀Ø]/, ''));
        // Plausibility: GD&T tolerances are almost always small (< 10mm or so).
        // This screens out things like "100 A" which would more plausibly be
        // a part-number label.
        if (!isNaN(tolVal) && tolVal <= 10) {
          return {
            type: 'gdt',
            gdtSym: '',          // user fills in
            gdtTol: bareMatch[1].trim(),
            gdtDatum: bareMatch[2].replace(/[\s,]+/g, '|'),
            nominal: '', tolPlus: '', tolMinus: '',
            unit: 'mm',
            rawText: rawText,
            notes: 'GD&T symbol not detected — please verify',
            confidence: 'low'
          };
        }
      }
    }

    // Try Pattern C — leading character is non-alphanumeric and the rest
    // matches the frame shape. The first character is likely a custom-font
    // GD&T symbol that didn't survive PDF extraction.
    if (firstChar && !/[A-Za-z0-9\s]/.test(firstChar) && !knownSyms.has(firstChar)) {
      const rest = modified.slice(1).trim();
      const cMatch = rest.match(
        /^([⌀Ø]?\d+(?:\.\d+)?(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*[|,\s]\s*([A-Z](?:[|,\s]+[A-Z]){0,4}(?:\s*[ⓂⓁⓈⓅⒻ])?)\s*$/
      );
      if (cMatch) {
        const tolVal = parseFloat(cMatch[1].replace(/[⌀Ø]/, ''));
        if (!isNaN(tolVal) && tolVal <= 10) {
          return {
            type: 'gdt',
            gdtSym: firstChar,  // keep whatever the glyph was
            gdtTol: cMatch[1].trim(),
            gdtDatum: cMatch[2].replace(/[\s,]+/g, '|'),
            nominal: '', tolPlus: '', tolMinus: '',
            unit: 'mm',
            rawText: rawText,
            notes: 'GD&T symbol may be a custom font glyph — please verify',
            confidence: 'medium'
          };
        }
      }
    }
    // ===== end GD&T detection =====

    // Number-with-tolerance patterns:

    // Bilateral symmetric: 100 ±0.1   or  100±0.1
    let m;
    m = s.match(/^(\d+(?:\.\d+)?)\s*[±]\s*(\d+(?:\.\d+)?)/);
    if (m) {
      return {
        type: type,
        nominal: m[1],
        tolPlus: m[2],
        tolMinus: m[2],
        unit: pickUnit(m[1]),
        rawText: rawText,
        confidence: 'high'
      };
    }

    // Bilateral asymmetric: 100 +0.05/-0.02  or  100 +0.05 -0.02
    m = s.match(/^(\d+(?:\.\d+)?)\s*\+\s*(\d+(?:\.\d+)?)\s*[\/\s]\s*-\s*(\d+(?:\.\d+)?)/);
    if (m) {
      return {
        type: type,
        nominal: m[1],
        tolPlus: m[2],
        tolMinus: m[3],
        unit: pickUnit(m[1]),
        rawText: rawText,
        confidence: 'high'
      };
    }

    // Unilateral plus-only: 100 +0.05/0  or  100 +0.05 -0
    m = s.match(/^(\d+(?:\.\d+)?)\s*\+\s*(\d+(?:\.\d+)?)\s*[\/\s]\s*-?\s*0\b/);
    if (m) {
      return {
        type: type,
        nominal: m[1],
        tolPlus: m[2],
        tolMinus: '0',
        unit: pickUnit(m[1]),
        rawText: rawText,
        confidence: 'high'
      };
    }

    // Unilateral minus-only: 100 -0.05/0   (rarer)
    m = s.match(/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)\s*[\/\s]\s*\+?\s*0\b/);
    if (m) {
      return {
        type: type,
        nominal: m[1],
        tolPlus: '0',
        tolMinus: m[2],
        unit: pickUnit(m[1]),
        rawText: rawText,
        confidence: 'high'
      };
    }

    // Fit-class notation: 25 H7, Ø25 H7, 50 g6
    m = s.match(/^(\d+(?:\.\d+)?)\s+([A-Za-z]\d+)\b/);
    if (m) {
      return {
        type: type,
        nominal: m[1],
        tolPlus: '',
        tolMinus: '',
        unit: pickUnit(m[1]),
        rawText: rawText,
        notes: 'Fit class ' + m[2],
        confidence: 'medium'
      };
    }

    // Angle with degrees: 30°  30° ±1°  or  45 DEG
    m = s.match(/^(\d+(?:\.\d+)?)\s*[°˚]\s*(?:[±]\s*(\d+(?:\.\d+)?))?/);
    if (m) {
      return {
        type: 'angle',
        nominal: m[1],
        tolPlus: m[2] || '',
        tolMinus: m[2] || '',
        unit: 'deg',
        rawText: rawText,
        confidence: 'high'
      };
    }
    m = s.match(/^(\d+(?:\.\d+)?)\s+DEG\b/i);
    if (m) {
      return {
        type: 'angle',
        nominal: m[1],
        tolPlus: '', tolMinus: '',
        unit: 'deg',
        rawText: rawText,
        confidence: 'high'
      };
    }

    // MAX or MIN modifier: 25 MAX, R5 MAX
    m = s.match(/^(\d+(?:\.\d+)?)\s+(MAX|MIN)\b/i);
    if (m) {
      return {
        type: type,
        nominal: m[1],
        tolPlus: '', tolMinus: '',
        unit: pickUnit(m[1]),
        rawText: rawText,
        notes: m[2].toUpperCase() + ' modifier',
        confidence: 'high'
      };
    }

    // Bare number, possibly with THRU modifier
    m = s.match(/^(\d+(?:\.\d+)?)\s*(?:THRU)?\s*$/i);
    if (m) {
      // Only accept if it's a plausible dimension value:
      // - more than just "1" or "2" (probably not a real dim)
      // - or has a decimal point
      // - or has the diameter/radius prefix (already type set)
      const val = parseFloat(m[1]);
      const hasDecimal = /\./.test(m[1]);
      if (type !== 'linear' || hasDecimal || val >= 3) {
        return {
          type: type,
          nominal: m[1],
          tolPlus: '', tolMinus: '',
          unit: pickUnit(m[1]),
          rawText: rawText,
          confidence: type !== 'linear' ? 'high' : (hasDecimal ? 'medium' : 'low')
        };
      }
    }

    return null;
  }

  // Guess unit from a numeric string. Numbers with 3+ decimals and no integer
  // part > 100 are probably inches (e.g. .500). Otherwise default to mm.
  function guessUnit(numStr) {
    const v = parseFloat(numStr);
    if (isNaN(v)) return 'mm';
    // Imperial notation: leading-dot decimal (.500), or value < 20 with 3+ decimals
    const decimals = (numStr.split('.')[1] || '').length;
    if (numStr.startsWith('.')) return 'in';
    if (v < 20 && decimals >= 3) return 'in';
    return 'mm';
  }

  // Scan title-block text items for a primary-units declaration.
  // Returns 'mm', 'in', or null if undetermined.
  //
  // Common phrasings seen on engineering drawings:
  //   "DIMENSIONS IN MM"
  //   "DIMS IN MILLIMETERS"
  //   "UNITS: MM"
  //   "ALL DIMENSIONS IN INCHES"
  //   "DIMENSIONS ARE IN INCHES UNLESS OTHERWISE SPECIFIED"
  //   "MM" or "INCHES" appearing alone near a "UNITS" label
  function detectTitleBlockUnit(tbItems) {
    if (!tbItems || tbItems.length === 0) return null;
    // Concatenate all title-block text into one normalised string for matching
    const blob = tbItems.map(it => it.text).join(' ').toUpperCase();

    // Strong patterns first
    if (/\b(DIMENSIONS?|DIMS?|UNITS?)\b[^A-Z0-9]{0,12}(MM|MILLIMETERS?|MILLIMETRES?)\b/.test(blob)) {
      return 'mm';
    }
    if (/\b(DIMENSIONS?|DIMS?|UNITS?)\b[^A-Z0-9]{0,12}(IN|INCH|INCHES)\b/.test(blob)) {
      return 'in';
    }

    // Weaker pattern: just "UNITS: MM" or "UNITS: IN" with a colon
    const m1 = blob.match(/\bUNITS?\s*[:=]\s*([A-Z\.]+)/);
    if (m1) {
      const u = m1[1];
      if (u === 'MM' || u === 'MILLIMETERS' || u === 'MILLIMETRES') return 'mm';
      if (u === 'IN' || u === 'IN.' || u === 'INCH' || u === 'INCHES') return 'in';
    }

    // ISO note: drawings labelled "ISO" or with metric thread callouts strongly
    // imply mm. ANSI/imperial labels imply inches. This is a fallback hint.
    if (/\bISO\b/.test(blob) && !/\bANSI\b/.test(blob)) return 'mm';
    if (/\bANSI\b/.test(blob) && !/\bISO\b/.test(blob)) return 'in';

    return null;
  }

  // ---- Text-layer extraction via PDF.js ------------------------------------
  // Extract text items at a given viewport scale. The scale should match the
  // SVG viewBox scale used for rendering bubbles — i.e. the displayed size,
  // not the raw 2x render scale. Otherwise extracted coords will be in a
  // different coordinate space than the bubble positions and they'll appear
  // way off-page.
  async function extractTextFromPdfPage(pdfDoc, pageNum, displayScale) {
    const page = await pdfDoc.getPage(pageNum);
    const viewport = page.getViewport({ scale: displayScale });
    const textContent = await page.getTextContent();
    // Each item has: str, transform [a b c d e f], width, height
    // The transform maps from text space to viewport space. The 4th and 5th
    // values (e, f) give the position. PDF coords are bottom-up; we need to
    // flip Y to match the canvas coords (where 0 is top).
    return textContent.items.map(item => {
      const tx = pdfjsLib.Util.transform(viewport.transform, item.transform);
      // tx[4] and tx[5] are the position of the bottom-left of the text in
      // viewport pixels. We want the centre.
      const fontSize = Math.hypot(tx[2], tx[3]);
      const x = tx[4] + (item.width * viewport.scale) / 2;
      const y = tx[5] - fontSize / 2;
      return {
        text: item.str,
        x: x,
        y: y,
        width: item.width * viewport.scale,
        height: fontSize,
        source: 'pdfjs'   // dual-engine: distinguish from OCR-extracted items
      };
    });
  }

  // ---- OCR fallback ---------------------------------------------------
  //
  // Two-tier OCR strategy:
  //
  //   1. Server-side PaddleOCR via ocr_proxy.php (if reachable). PaddleOCR is
  //      substantially better than Tesseract on engineering drawings —
  //      smaller text, mixed orientations, dense lines. Requires the Python
  //      service to be running on the same host (see /ocr/README.md).
  //
  //   2. Tesseract.js (in-browser) as a fallback when the server is
  //      unreachable or returns an error. Lower quality, but works without
  //      any backend.
  //
  // Whichever path runs, the output is normalised to the same shape:
  //     [{ text, x, y, width, height }]  with coordinates in image pixels.

  // URL of the server-side OCR proxy. Same origin as bubble_tool.php so no
  // CORS concerns. Set to null to skip the server attempt entirely (force
  // browser Tesseract).
  const SERVER_OCR_URL = 'ocr_proxy.php';

  // After one failed attempt to reach the server in this session, stop
  // trying — fall back to Tesseract for the rest of the run instead of
  // waiting on a dead server for every page.
  let serverOcrUnavailable = false;

  function loadTesseract() {
    if (ab.tesseractPromise) return ab.tesseractPromise;
    ab.tesseractPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.0.4/tesseract.min.js';
      s.onload = () => {
        if (window.Tesseract) resolve(window.Tesseract);
        else reject(new Error('Tesseract loaded but window.Tesseract is missing'));
      };
      s.onerror = () => reject(new Error('Failed to load Tesseract.js from CDN'));
      document.head.appendChild(s);
    });
    return ab.tesseractPromise;
  }

  // Attempt server-side OCR. Returns the normalised item array on success,
  // or throws so the caller can fall back.
  async function ocrViaServer(pageImageDataUrl, onProgress) {
    if (!SERVER_OCR_URL || serverOcrUnavailable) {
      throw new Error('server OCR disabled');
    }
    // The data URL may include the "data:image/png;base64," prefix; the
    // proxy strips it but it's still good to send a clean base64 string.
    const b64 = pageImageDataUrl.indexOf(',') >= 0
      ? pageImageDataUrl.split(',', 2)[1]
      : pageImageDataUrl;

    // We can't easily report fine-grained progress from a server call —
    // the request just sits open. Show a coarse indeterminate state.
    if (onProgress) onProgress(0.1);

    // Set up an abort controller with a generous timeout. First call after
    // a cold start can be ~15s while paddle loads the models.
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 90000);

    let response;
    try {
      response = await fetch(SERVER_OCR_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ image: b64, lang: 'en' }),
        signal: controller.signal
      });
    } catch (err) {
      clearTimeout(timeout);
      // Network error, abort, or proxy down — mark unavailable so we don't
      // re-try on every subsequent page in this run.
      serverOcrUnavailable = true;
      throw err;
    }
    clearTimeout(timeout);

    if (!response.ok) {
      // 502 / 503 from the proxy means the upstream service is down.
      // 4xx means we sent a bad request — also a "don't retry" signal.
      serverOcrUnavailable = true;
      let body = '';
      try { body = await response.text(); } catch (e) {}
      throw new Error('server OCR HTTP ' + response.status + ': ' + body.slice(0, 200));
    }

    const data = await response.json();
    if (!data || !data.ok) {
      serverOcrUnavailable = true;
      throw new Error('server OCR: ' + (data && data.error ? data.error : 'unknown error'));
    }

    if (onProgress) onProgress(1.0);

    // Convert server output to our internal shape.
    // Server returns:  { text, bbox: [x1,y1,x2,y2], polygon, confidence }
    // We need:         { text, x, y, width, height }
    return (data.items || []).map(it => {
      const [x1, y1, x2, y2] = it.bbox;
      return {
        text: it.text,
        x: (x1 + x2) / 2,
        y: (y1 + y2) / 2,
        width:  Math.max(1, x2 - x1),
        height: Math.max(1, y2 - y1),
        _confidence: it.confidence
      };
    });
  }

  // Tesseract.js fallback — runs entirely in-browser.
  async function ocrViaTesseract(pageImageDataUrl, onProgress) {
    const Tesseract = await loadTesseract();
    const result = await Tesseract.recognize(pageImageDataUrl, 'eng', {
      logger: m => {
        if (m.status === 'recognizing text' && onProgress) {
          onProgress(m.progress);
        }
      }
    });
    // Tesseract returns words with bounding boxes:
    // result.data.words = [{ text, bbox: { x0, y0, x1, y1 } }, ...]
    return (result.data.words || []).map(w => ({
      text: w.text,
      x: (w.bbox.x0 + w.bbox.x1) / 2,
      y: (w.bbox.y0 + w.bbox.y1) / 2,
      width: w.bbox.x1 - w.bbox.x0,
      height: w.bbox.y1 - w.bbox.y0,
      source: 'ocr'   // dual-engine: distinguish from PDF.js text-layer items
    }));
  }

  async function ocrPageToTextItems(pageImageDataUrl, onProgress) {
    // Try the server first; on failure, fall back to Tesseract. Server
    // OCR also tags items with source='ocr' on the way out.
    try {
      const items = await ocrViaServer(pageImageDataUrl, onProgress);
      // ocrViaServer may not stamp source itself — ensure it's present
      // before returning so downstream code can rely on the tag.
      items.forEach(it => { if (!it.source) it.source = 'ocr'; });
      console.log('[auto-bubble] OCR via server: ' + items.length + ' items');
      return items;
    } catch (err) {
      console.warn('[auto-bubble] Server OCR unavailable, falling back to Tesseract:', err.message);
      return await ocrViaTesseract(pageImageDataUrl, onProgress);
    }
  }

  // ---- Combine adjacent text items into multi-word dimension strings -------
  // PDF text layers often split a dimension like "100 ±0.1" into 3 items.
  // We merge items that are on roughly the same baseline and within a few
  // character widths horizontally.
  function mergeAdjacentTextItems(items) {
    if (items.length === 0) return [];
    // Sort by y then x
    const sorted = items.slice().sort((a, b) => {
      if (Math.abs(a.y - b.y) > 5) return a.y - b.y;
      return a.x - b.x;
    });
    const merged = [];
    let cur = null;
    for (const item of sorted) {
      if (!cur) { cur = { ...item, text: item.text }; continue; }
      const sameLine = Math.abs(item.y - cur.y) <= Math.max(4, cur.height * 0.4);
      const horizGap = item.x - (cur.x + cur.width / 2);
      const tightGap = horizGap < cur.height * 3.5; // 3.5 chars max
      if (sameLine && tightGap) {
        // merge
        const newRight = item.x + item.width / 2;
        const newLeft = cur.x - cur.width / 2;
        const newWidth = newRight - newLeft;
        cur.text = cur.text + ' ' + item.text;
        cur.x = (newLeft + newRight) / 2;
        cur.width = newWidth;
        cur.height = Math.max(cur.height, item.height);
      } else {
        merged.push(cur);
        cur = { ...item };
      }
    }
    if (cur) merged.push(cur);

    // ----- GD&T second-pass merge -----
    // Feature control frames have cell dividers between symbol / tolerance /
    // datums, so the first-pass (tight) merge usually treats them as 2 or 3
    // separate items. Do a more permissive merge specifically for fragments
    // that LOOK like GD&T pieces.
    //
    // A fragment is GD&T-shaped if it's:
    //   - a single non-alphanumeric char (likely a symbol)
    //   - a single capital letter A-Z (likely a datum)
    //   - a small number, possibly with Ø prefix (likely a tolerance)
    //
    // We allow a wider horizontal gap (up to 8x text height) for these.
    const isGdtFragment = (s) => {
      const t = s.trim();
      if (!t) return false;
      // Bracket characters belong to dual-unit notation, not GD&T frames.
      // Refuse to treat them as fragments here so the dual-unit pairing can
      // pick them up cleanly later.
      if (/[\[\]\(\)]/.test(t)) return false;
      if (/^[A-Z]$/.test(t)) return true;                            // datum letter
      if (/^[⌀Ø]?\d+(\.\d+)?$/.test(t)) {
        const n = parseFloat(t.replace(/[⌀Ø]/, ''));
        return n <= 10; // small numbers only
      }
      if (/^[^A-Za-z0-9\s]$/.test(t)) return true;                    // single non-alnum char (symbol)
      if (/^[ⓂⓁⓈⓅⒻ]$/.test(t)) return true;                          // material modifier
      return false;
    };

    const result = [];
    let pending = null;
    for (const item of merged) {
      if (!pending) { pending = { ...item }; continue; }
      const sameLine = Math.abs(item.y - pending.y) <= Math.max(6, pending.height * 0.6);
      const horizGap = item.x - (pending.x + pending.width / 2);
      const wideGap  = horizGap < pending.height * 8.5;
      const bothGdt  = isGdtFragment(pending.text) && isGdtFragment(item.text);
      // Allow merge if first piece is GDT-shaped and next is GDT-shaped
      // (even loose pieces), OR if combining would produce something that
      // parses as a frame.
      const combined = pending.text.trim() + ' ' + item.text.trim();
      const looksLikeFrame = /^.{0,2}\s*[⌀Ø]?\d+(?:\.\d+)?(?:\s*[ⓂⓁⓈⓅⒻ])?\s+[A-Z]/.test(combined);
      if (sameLine && wideGap && (bothGdt || looksLikeFrame)) {
        const newRight = item.x + item.width / 2;
        const newLeft  = pending.x - pending.width / 2;
        const newWidth = newRight - newLeft;
        pending.text   = combined;
        pending.x      = (newLeft + newRight) / 2;
        pending.width  = newWidth;
        pending.height = Math.max(pending.height, item.height);
      } else {
        result.push(pending);
        pending = { ...item };
      }
    }
    if (pending) result.push(pending);

    return result;
  }

  // ---- Dual-source merge: PDF.js text-layer + Tesseract OCR --------------
  //
  // Dual-engine extraction runs both PDF.js (when there's a text layer) AND
  // Tesseract (on the rasterized page) on every page. This merges the two
  // outputs into a unified list of text items that downstream candidate
  // detection can consume.
  //
  // The rules:
  //   - Items whose bounding boxes overlap (with a small tolerance) are
  //     treated as the same physical text on the drawing — only one item
  //     comes through, carrying the PRIMARY reading plus an ALT reading
  //     for the user to swap to if the primary is wrong.
  //   - PDF.js wins on overlap by default (its character extraction is
  //     exact; OCR can confuse Ø with 0, 5 with S, etc.). The alt is the
  //     OCR reading.
  //   - When PDF.js missed a detection entirely (raster-only annotation,
  //     stamp, hand-written callout), the OCR-only item passes through
  //     with no alt — it's just an OCR detection.
  //   - When OCR missed (Tesseract sometimes can't see small text the
  //     text layer captures), the PDF.js-only item passes through with
  //     no alt.
  //
  // Output items have the SAME shape as input items (text, x, y, width,
  // height, source) plus optionally altText and altSource. Downstream
  // code that doesn't know about alts can ignore them; the bubble
  // detail editor surfaces them via a swap button.
  function mergeDualSourceItems(pdfjsItems, ocrItems) {
    // Tolerance for treating two items as the same detection. The two
    // engines won't agree to the pixel — text-layer reports glyph
    // bounding boxes, OCR reports word bounding boxes with some
    // anti-aliasing slop. We expand each box by a fraction of its
    // height when checking overlap to forgive small disagreements.
    function overlapsLoose(a, b) {
      const pad = Math.max(a.height, b.height) * 0.4;
      const adx = (a.width  / 2) + pad;
      const ady = (a.height / 2) + pad;
      const bdx = (b.width  / 2);
      const bdy = (b.height / 2);
      return (Math.abs(a.x - b.x) < (adx + bdx)) &&
             (Math.abs(a.y - b.y) < (ady + bdy));
    }

    const out = [];
    const ocrTaken = new Array(ocrItems.length).fill(false);

    // First sweep: each PDF.js item finds (at most) one OCR partner.
    // Picks the closest OCR item by centre distance, if any overlaps.
    for (const p of pdfjsItems) {
      let bestJ = -1;
      let bestDist = Infinity;
      for (let j = 0; j < ocrItems.length; j++) {
        if (ocrTaken[j]) continue;
        const o = ocrItems[j];
        if (!overlapsLoose(p, o)) continue;
        const dist = Math.hypot(p.x - o.x, p.y - o.y);
        if (dist < bestDist) { bestDist = dist; bestJ = j; }
      }
      if (bestJ >= 0) {
        const o = ocrItems[bestJ];
        ocrTaken[bestJ] = true;
        // Same detection per both engines. PDF.js text is primary
        // (exact-character source of truth); OCR text rides along as
        // an alt the user can swap to if the primary parses wrong.
        // We only carry the alt when the two strings actually differ
        // (after trimming whitespace) — if both engines agree there's
        // nothing to swap to.
        const pTxt = (p.text || '').trim();
        const oTxt = (o.text || '').trim();
        if (pTxt && oTxt && pTxt !== oTxt) {
          out.push(Object.assign({}, p, {
            altText: o.text,
            altSource: 'ocr'
          }));
        } else {
          out.push(p);
        }
      } else {
        // PDF.js-only detection (OCR missed it, common for small text).
        out.push(p);
      }
    }

    // Second sweep: OCR items that no PDF.js item claimed.
    // These are OCR-only detections — likely raster annotations the
    // text layer never had. Pass them through as primary 'ocr' items
    // with no alt.
    for (let j = 0; j < ocrItems.length; j++) {
      if (!ocrTaken[j]) out.push(ocrItems[j]);
    }
    return out;
  }


  //
  // Decide where to place the bubble and its leader-arrow tip relative to the
  // detected text item, considering:
  //   - page edges (don't place the bubble off the rendered page)
  //   - already-placed bubbles on this page (avoid overlap clusters)
  //   - text bounding box (the arrow points to the box edge; the bubble sits
  //     in clear space at least one bubble-radius + clearance away)
  //
  // Returns { tx, ty, bx, by } where (tx,ty) is the arrow tip (anchored to
  // the text box edge) and (bx,by) is the bubble centre.
  // Helper: do two AABBs overlap? Each arg is {x,y,w,h} with x/y at centre.
  function aabbsOverlap(a, b, pad) {
    pad = pad || 0;
    const adx = (a.w / 2) + pad;
    const ady = (a.h / 2) + pad;
    const bdx = (b.w / 2);
    const bdy = (b.h / 2);
    return (Math.abs(a.x - b.x) < (adx + bdx)) && (Math.abs(a.y - b.y) < (ady + bdy));
  }

  function placeBubbleNearText(item, existingBubbles, allTextItems, pageW, pageH, bubbleSize) {
    // Text bounding box of the dim we're bubbling
    const tLeft   = item.x - item.width  / 2;
    const tRight  = item.x + item.width  / 2;
    const tTop    = item.y - item.height / 2;
    const tBot    = item.y + item.height / 2;

    const radius = (bubbleSize || 12);
    // Clearance scales with bubble size — small bubbles don't need 38px gap.
    // Floor of 14px so even tiny bubbles aren't right on top of the text.
    const clearance = Math.max(14, radius * 1.4);
    const margin = Math.max(12, radius + 6); // page-edge margin
    // Standoff: gap between the arrow-tip anchor and the text-box edge so the
    // tip doesn't sit on top of the glyphs. Small but visible.
    const standoff = 4;

    // Eight candidate placements:
    // 4 diagonal (from text-box corners) + 4 cardinal (from text-box edge midpoints).
    // tx/ty = arrow-tip anchor, sits "standoff" pixels outside the text-box edge
    // bx/by = bubble centre out further beyond
    const offset = radius + clearance;
    const diag = offset * 0.707; // 45° decomposition for diagonal placement
    const sDiag = standoff * 0.707;
    const candidates = [
      // diagonal corners — arrow tip nudged outward diagonally
      { tx: tRight + sDiag, ty: tTop - sDiag,  bx: tRight + diag, by: tTop - diag, dir: 'UR' },
      { tx: tRight + sDiag, ty: tBot + sDiag,  bx: tRight + diag, by: tBot + diag, dir: 'DR' },
      { tx: tLeft  - sDiag, ty: tBot + sDiag,  bx: tLeft  - diag, by: tBot + diag, dir: 'DL' },
      { tx: tLeft  - sDiag, ty: tTop - sDiag,  bx: tLeft  - diag, by: tTop - diag, dir: 'UL' },
      // cardinal edge midpoints — arrow tip nudged outward perpendicularly
      { tx: (tLeft + tRight) / 2, ty: tTop - standoff,           bx: (tLeft + tRight) / 2, by: tTop - offset, dir: 'U'  },
      { tx: (tLeft + tRight) / 2, ty: tBot + standoff,           bx: (tLeft + tRight) / 2, by: tBot + offset, dir: 'D'  },
      { tx: tLeft - standoff,     ty: (tTop + tBot) / 2,         bx: tLeft - offset,       by: (tTop + tBot) / 2, dir: 'L'  },
      { tx: tRight + standoff,    ty: (tTop + tBot) / 2,         bx: tRight + offset,      by: (tTop + tBot) / 2, dir: 'R'  }
    ];

    function score(c) {
      let s = 0;
      // Page-edge penalty: bubble extent must fit inside [margin, page-margin]
      const left  = c.bx - radius;
      const right = c.bx + radius;
      const top   = c.by - radius;
      const bot   = c.by + radius;
      if (left  < margin)         s += 1000 + (margin - left);
      if (right > pageW - margin) s += 1000 + (right - (pageW - margin));
      if (top   < margin)         s += 1000 + (margin - top);
      if (bot   > pageH - margin) s += 1000 + (bot - (pageH - margin));

      // Bubble-vs-bubble overlap (existing bubbles)
      for (const e of existingBubbles) {
        const dx = c.bx - e.x_centre;
        const dy = c.by - e.y_centre;
        const dist = Math.hypot(dx, dy);
        const minDist = radius + e.radius + 6;
        if (dist < minDist) {
          s += (minDist - dist) * 6;
        }
      }

      // Bubble-vs-text overlap. We don't want our bubble covering OTHER
      // detected dimension text. The current item being bubbled is excluded
      // (we WANT the leader to point at it).
      const bubbleBox = { x: c.bx, y: c.by, w: radius * 2, h: radius * 2 };
      for (const t of allTextItems) {
        if (t === item) continue;
        const tBox = { x: t.x, y: t.y, w: t.width, h: t.height };
        if (aabbsOverlap(bubbleBox, tBox, 2)) {
          // Big penalty — text-overlap is the worst placement outcome
          s += 500;
        }
      }

      // Leader-line vs text overlap. The leader line goes from (tx,ty) to
      // (bx,by). Penalty if any midpoint passes through a text box.
      // Sample 4 points along the line.
      for (let i = 1; i <= 4; i++) {
        const t = i / 5;
        const px = c.tx + (c.bx - c.tx) * t;
        const py = c.ty + (c.by - c.ty) * t;
        for (const ti of allTextItems) {
          if (ti === item) continue;
          if (Math.abs(px - ti.x) < ti.width / 2 + 1 &&
              Math.abs(py - ti.y) < ti.height / 2 + 1) {
            s += 80;
            break;
          }
        }
      }

      // Mild directional bias
      const dirBias = { UR: -2, R: -1.5, DR: -1, U: -0.5, D: 0, UL: 0.5, DL: 1, L: 1.5 };
      s += dirBias[c.dir] || 0;

      return s;
    }

    // Pick best candidate
    let best = candidates[0];
    let bestScore = score(best);
    for (let i = 1; i < candidates.length; i++) {
      const sc = score(candidates[i]);
      if (sc < bestScore) {
        bestScore = sc;
        best = candidates[i];
      }
    }

    // If the best candidate still overlaps text, try "spiral-out": push the
    // bubble centre further away from the text along the same direction
    // until no overlap, up to 3 steps.
    if (bestScore >= 500) {
      const dx = best.bx - best.tx;
      const dy = best.by - best.ty;
      const len = Math.hypot(dx, dy) || 1;
      const ux = dx / len, uy = dy / len;
      for (let step = 1; step <= 3; step++) {
        const trial = {
          tx: best.tx,
          ty: best.ty,
          bx: best.bx + ux * radius * 1.8 * step,
          by: best.by + uy * radius * 1.8 * step,
          dir: best.dir
        };
        const sc = score(trial);
        if (sc < bestScore) {
          best = trial;
          bestScore = sc;
          if (sc < 500) break;
        }
      }
    }

    // Clamp bubble centre to stay on-page even if all candidates were bad
    best = { ...best };
    best.bx = Math.max(margin + radius, Math.min(pageW - margin - radius, best.bx));
    best.by = Math.max(margin + radius, Math.min(pageH - margin - radius, best.by));

    return best;
  }

  // ---- Clockwise placement around the drawing perimeter --------------------
  //
  // For each detected item, place its bubble on a ring just outside the
  // drawing area at the angle from page-centre to the dimension text. This
  // mirrors how inspection drawings are typically organised — numbered
  // clockwise starting from 12 o'clock — making it easy for an inspector to
  // sweep around the page in order.
  //
  // The leader line runs from the bubble (on the perimeter) to the text-box
  // edge, which means leaders can be long for centrally-located dimensions.
  //
  // Algorithm:
  //   1. Compute each item's angle from page-centre (0° = up, clockwise)
  //   2. Sort items by angle
  //   3. Allocate perimeter slots, advancing past occupied ones
  //
  // Returns array of placements [{ item, tx, ty, bx, by, angleDeg }], same
  // order as input items.
  function placeBubblesClockwise(items, pageW, pageH, bubbleSize, existingBubbles) {
    const radius = (bubbleSize || 12);
    // Perimeter ring just outside the drawing (in displayed coords). Use a
    // proportional outset so the ring scales with the drawing size.
    const ringInset = Math.max(radius * 3, Math.min(pageW, pageH) * 0.05);
    const cx = pageW / 2;
    const cy = pageH / 2;

    // Compute angle for each item (0 = up = 12 o'clock; clockwise)
    function angleFromCenter(it) {
      const dx = it.x - cx;
      const dy = it.y - cy;
      // Math.atan2 returns radians from +X axis CCW. We want degrees from +Y
      // axis CW (so 12 o'clock is 0°, 3 o'clock is 90°, etc.)
      let deg = Math.atan2(dx, -dy) * 180 / Math.PI;
      if (deg < 0) deg += 360;
      return deg;
    }

    // Annotate items with angles, then sort
    const indexed = items.map((it, idx) => ({ it, idx, angle: angleFromCenter(it) }));
    indexed.sort((a, b) => a.angle - b.angle);

    // Place each in order. If two items are at nearly the same angle, push
    // the later one a few degrees onward.
    const minAngularGap = Math.max(4, (360 / Math.max(items.length, 8)) * 0.8);
    const placed = new Array(items.length);
    let lastAngle = -360;
    for (const entry of indexed) {
      let a = entry.angle;
      if (a - lastAngle < minAngularGap) {
        a = lastAngle + minAngularGap;
      }
      lastAngle = a;
      // Project the bubble centre onto the perimeter at this angle.
      // We use an elliptical perimeter (rectangle's bounding ellipse) so it
      // looks visually correct for wide or tall pages.
      const rad = a * Math.PI / 180;
      // direction vector: (sin(deg), -cos(deg)) gives 12 o'clock = (0,-1)
      const dirX = Math.sin(rad);
      const dirY = -Math.cos(rad);
      // Scale outward to land on the rectangle perimeter (not ellipse)
      const halfW = pageW / 2 - ringInset;
      const halfH = pageH / 2 - ringInset;
      // Find t such that t*|dirX| = halfW OR t*|dirY| = halfH, whichever first
      const tx = halfW / Math.max(0.0001, Math.abs(dirX));
      const ty = halfH / Math.max(0.0001, Math.abs(dirY));
      const t = Math.min(tx, ty);
      const bx = cx + dirX * t;
      const by = cy + dirY * t;

      // Arrow tip — anchor to nearest corner/edge of text box
      const it = entry.it;
      const tLeft  = it.x - it.width / 2;
      const tRight = it.x + it.width / 2;
      const tTop   = it.y - it.height / 2;
      const tBot   = it.y + it.height / 2;
      // Pick the edge of the text box closest to the bubble. Standoff 4px.
      const standoff = 4;
      let arrowX, arrowY;
      if (bx >= tRight) arrowX = tRight + standoff;
      else if (bx <= tLeft) arrowX = tLeft - standoff;
      else arrowX = it.x;
      if (by >= tBot) arrowY = tBot + standoff;
      else if (by <= tTop) arrowY = tTop - standoff;
      else arrowY = it.y;

      placed[entry.idx] = {
        item: it,
        tx: arrowX,
        ty: arrowY,
        bx: bx,
        by: by,
        angleDeg: a
      };
    }
    return placed;
  }

  // ---- Grid cell calculation ----------------------------------------------
  // Given a position (px, py) inside the page, return the cell label like
  // "B3". Rows top-to-bottom = A,B,C,..., cols left-to-right = 1,2,3,...
  function gridCellFor(px, py, pageW, pageH, rows, cols) {
    const col = Math.max(0, Math.min(cols - 1, Math.floor(px / pageW * cols)));
    const row = Math.max(0, Math.min(rows - 1, Math.floor(py / pageH * rows)));
    return String.fromCharCode(65 + row) + (col + 1);
  }

  // ---- Reference rectangle ------------------------------------------------
  // Build a rectangle that frames the dimension's text on the drawing.
  // Stored on the bubble so it can be rendered, toggled, and resized.
  // We add a small padding (3-4px) so the rect doesn't sit flush against the
  // glyphs.
  function buildRefRect(item) {
    const pad = Math.max(3, item.height * 0.25);
    return {
      x: item.x - item.width / 2 - pad,      // top-left x
      y: item.y - item.height / 2 - pad,     // top-left y
      w: item.width + pad * 2,
      h: item.height + pad * 2,
      visible: true
    };
  }

  // ---- Default description from parsed dimension --------------------------
  // Auto-bubbled bubbles should have a non-empty description so the parts
  // list and CSV aren't empty. We use the parsed nominal+tol if available,
  // else fall back to the raw text. The user can edit anywhere downstream.
  function defaultDescription(parsed, fallback) {
    if (!parsed) return fallback || '';
    // GD&T: e.g. "⊥ 0.05 |A|B"
    if (parsed.type === 'gdt') {
      const sym = parsed.gdtSym || '?';
      const tol = parsed.gdtTol || '';
      const dat = parsed.gdtDatum ? '|' + parsed.gdtDatum.replace(/[|,\s]+/g, '|') : '';
      return (sym + ' ' + tol + dat).trim();
    }
    // Reference / note items: use rawText (already trimmed)
    if (parsed.type === 'reference' && !parsed.nominal) {
      return parsed.rawText || fallback || '';
    }
    // Linear / dia / radius / angle: nominal +tol -tol unit
    const parts = [];
    if (parsed.nominal) {
      let n = String(parsed.nominal);
      if (parsed.type === 'diameter') n = 'Ø' + n;
      else if (parsed.type === 'radius') n = 'R' + n;
      parts.push(n);
    }
    if (parsed.tolPlus || parsed.tolMinus) {
      if (parsed.tolPlus === parsed.tolMinus && parsed.tolPlus) {
        parts.push('±' + parsed.tolPlus);
      } else {
        if (parsed.tolPlus)  parts.push('+' + parsed.tolPlus);
        if (parsed.tolMinus) parts.push('-' + parsed.tolMinus);
      }
    }
    if (parsed.unit && parsed.unit !== 'none' && parts.length > 0) {
      parts.push(parsed.unit);
    }
    return parts.join(' ').trim() || fallback || '';
  }

  // ---- Numbered NOTES extraction -----------------------------------------
  //
  // Engineering drawings list general notes in a numbered block, often
  // labelled "NOTES:" or "GENERAL NOTES". Each numbered item should become
  // its own bubble. We detect them by looking for items whose text starts
  // with a number-and-dot pattern (1., 2., 3., …).
  //
  // We also attempt to extract any embedded dimension from each note's
  // text (e.g. "BREAK ALL EDGES 0.5 MAX" → captures "0.5 MAX" as the
  // dimension, with the rest going into notes).
  //
  // Returns array of candidates: { kind: 'note', item, parsed }
  function extractNotesAsCandidates(mergedItems, pageW, pageH) {
    const candidates = [];
    const noteRe = /^\s*(\d{1,2})[.)]\s+(.+)$/;
    for (const item of mergedItems) {
      const text = (item.text || '').trim();
      if (!text) continue;
      // Only consider items that match the "N. text" or "N) text" prefix
      const m = text.match(noteRe);
      if (!m) continue;
      const noteIndex = m[1];
      const noteBody  = m[2];

      // Try to extract a dimension from inside the note body
      let parsed = parseDimension(noteBody);
      if (parsed) {
        parsed.notes = (parsed.notes ? parsed.notes + ' · ' : '') + 'Note ' + noteIndex + ': ' + noteBody;
        parsed.noteIndex = noteIndex;
        parsed.rawText = text;
      } else {
        // Note didn't contain a parseable dimension — still bubble it as a
        // reference item carrying the note text.
        parsed = {
          type: 'reference',
          nominal: '',
          tolPlus: '', tolMinus: '',
          unit: 'none',
          notes: 'Note ' + noteIndex + ': ' + noteBody,
          rawText: text,
          noteIndex: noteIndex,
          confidence: 'medium'
        };
      }
      candidates.push({ kind: 'note', item: item, parsed: parsed });
    }
    return candidates;
  }

  // ---- The main runner -----------------------------------------------------
  async function runAutoBubble({
    scope, allowOcr, skipTitleBlock, bubbleBracketed,
    bubbleNotes, clockwise, showRulers, gridRows, gridCols
  }) {
    // Persist grid settings for the renderer
    state.gridRows = gridRows || 4;
    state.gridCols = gridCols || 4;
    state.showRulers = !!showRulers;
    // Snapshot current state for undo
    if (state.drawing.type === 'pdf') {
      syncPage();
      ab.snapshot = {
        pageBubbles: JSON.parse(JSON.stringify(state.pageBubbles)),
        nextId: state.nextId
      };
    } else {
      ab.snapshot = {
        bubbles: JSON.parse(JSON.stringify(state.bubbles)),
        nextId: state.nextId
      };
    }
    ab.added = [];

    const pagesToProcess = (scope === 'all' && state.drawing.type === 'pdf')
      ? Array.from({ length: state.pdf.total }, (_, i) => i + 1)
      : [state.pdf.page || 1];

    setProgress('Reading PDF text layer…', 'Page 1 of ' + pagesToProcess.length, 0);

    let totalFound = 0;
    let viaOcr = 0;
    let viaTextLayer = 0;
    // Diagnostic counters for bracketed dual-unit detection
    let bracketedDetected = 0;   // count of [x] items we identified
    let bracketedBubbled = 0;    // count we actually created bubbles for

    for (let i = 0; i < pagesToProcess.length; i++) {
      const pageNum = pagesToProcess[i];
      setProgress(
        'Page ' + pageNum + ' of ' + state.pdf.total,
        'Extracting text layer…',
        (i / pagesToProcess.length) * 0.5
      );

      // Compute the DISPLAY scale for this page — the same scale that
      // fitDrawingToStage uses when rendering. Bubble positions live in this
      // displayed-pixel coordinate space (SVG viewBox is 0,0 → dispW,dispH).
      //
      // For the currently-rendered page, state.drawing.baseW/H is already the
      // display size. For other pages we replicate fitDrawingToStage's math.
      const pageRaw = await state.pdf.doc.getPage(pageNum);
      const RENDER_SCALE = 2;                          // PDF.js render scale used everywhere
      const rawViewport = pageRaw.getViewport({ scale: RENDER_SCALE });
      const rawW = rawViewport.width;
      const rawH = rawViewport.height;

      let displayScale;
      if (pageNum === state.pdf.page && state.drawing.baseW) {
        // Already rendered — use the actual fitted size
        displayScale = state.drawing.baseW / rawW * RENDER_SCALE;
      } else {
        // Compute what fit would produce for this page
        const maxW = drawingContainer.clientWidth - 60;
        const maxH = drawingContainer.clientHeight - 60;
        const fitScale = Math.min(maxW / rawW, maxH / rawH, 1);
        displayScale = fitScale * RENDER_SCALE;
      }
      const dispViewport = pageRaw.getViewport({ scale: displayScale });
      const pageW = dispViewport.width;
      const pageH = dispViewport.height;

      // ---- DUAL-ENGINE EXTRACTION ----
      //
      // Run BOTH PDF.js text-layer extraction AND Tesseract OCR on
      // every page (when allowOcr is on), then merge results so that:
      //   - Overlapping detections become one item carrying both
      //     readings (PDF.js primary + OCR alt). The user can swap
      //     to the alt per-bubble via a toggle in the parts editor.
      //   - Detections only one engine saw pass through as-is
      //     (PDF.js-only = small text OCR missed; OCR-only =
      //     raster-baked annotations the text layer never had).
      //
      // When the text layer is absent or near-empty (under 5 items)
      // and OCR is disabled, we skip OCR — same as the old behavior.
      // When OCR is enabled but the text layer is healthy, we still
      // run OCR to catch raster-only annotations.
      let pdfjsItems = [];
      try {
        pdfjsItems = await extractTextFromPdfPage(state.pdf.doc, pageNum, displayScale);
      } catch (err) {
        console.warn('Text-layer extraction failed for page ' + pageNum + ':', err);
      }

      let ocrItems = [];
      let usedOcr = false;
      let usedTextLayer = pdfjsItems.length >= 5;
      if (allowOcr) {
        usedOcr = true;
        setProgress(
          'Page ' + pageNum + ' of ' + state.pdf.total,
          usedTextLayer
            ? 'Running OCR in parallel to catch raster annotations…'
            : 'No text layer — running OCR (server preferred; Tesseract fallback)…',
          (i / pagesToProcess.length) * 0.5 + 0.05
        );
        // Render the page to a canvas AT DISPLAY SCALE so OCR bboxes
        // are in the right coordinate space (same space as PDF.js
        // items, so the dual-source merge can compare bounding boxes).
        const canvas = document.createElement('canvas');
        canvas.width = pageW;
        canvas.height = pageH;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await pageRaw.render({ canvasContext: ctx, viewport: dispViewport }).promise;
        const dataUrl = canvas.toDataURL('image/png');
        try {
          ocrItems = await ocrPageToTextItems(dataUrl, p => {
            setProgress(
              'Page ' + pageNum + ' of ' + state.pdf.total,
              'OCR progress: ' + Math.round(p * 100) + '%',
              (i / pagesToProcess.length) * 0.5 + 0.05 + p * 0.45 / pagesToProcess.length
            );
          });
        } catch (err) {
          console.error('OCR failed for page ' + pageNum + ':', err);
          ocrItems = [];
        }
      }

      // Merge the two result sets. If only one engine ran (allowOcr
      // off, or text layer empty), the merge degenerates to a
      // pass-through.
      let items;
      if (pdfjsItems.length && ocrItems.length) {
        items = mergeDualSourceItems(pdfjsItems, ocrItems);
        console.log('[auto-bubble] page ' + pageNum + ': '
          + pdfjsItems.length + ' PDF.js + ' + ocrItems.length + ' OCR '
          + '→ ' + items.length + ' merged');
      } else if (pdfjsItems.length) {
        items = pdfjsItems;
      } else {
        items = ocrItems;
      }

      if (usedOcr) viaOcr++;
      if (usedTextLayer) viaTextLayer++;

      // ---- Title-block unit detection ----
      // Pull the items that lie in the bottom-right ~30% × 25% (typical
      // title-block region) and look for a primary-units declaration.
      // We do this BEFORE optionally filtering them out for dim detection.
      const tbItems = items.filter(it => it.x > pageW * 0.65 && it.y > pageH * 0.7);
      const pageUnit = detectTitleBlockUnit(tbItems);

      // Now filter out title-block region from dim-detection items if requested
      if (skipTitleBlock) {
        items = items.filter(it => !(it.x > pageW * 0.7 && it.y > pageH * 0.75));
      }

      // Merge adjacent items into dimension strings
      const merged = mergeAdjacentTextItems(items);

      // Build a list of bubbles already on this page so the placement
      // algorithm can avoid overlapping them.
      const existingForPlacement = [];
      const pageBubblesList = (state.drawing.type === 'pdf')
        ? (state.pageBubbles[pageNum] || [])
        : state.bubbles;
      pageBubblesList.forEach(b => {
        // Bubble centre is (b.x, b.y); leader-tip anchor is (b.ax, b.ay).
        // b.size IS the radius (passed directly to SVG circle's r attribute).
        existingForPlacement.push({
          x_centre: b.x,
          y_centre: b.y,
          radius: (b.size || 12)
        });
      });

      // ---- Two-pass placement: primaries first, then bracketed ----
      //
      // Bracketed values like [25.4] are dual-unit equivalents of the primary
      // dimension placed near them. When the user opts in to bubble them too,
      // they get a ".a" suffix on the matching primary's number (e.g. if the
      // primary is bubble 5, the bracketed gets 5.a).

      // Partition merged items into primaries and bracketed-candidates.
      //
      // Bracketed dual-unit values come through PDF text extraction in many
      // shapes. We handle:
      //   (a) Standalone:        "[30.0]"
      //   (b) Merged with primary, after:  "1.18 [30.0]"
      //   (c) Merged before:     "[30.0] 1.18"
      //   (d) Multiple per item: "[30.0] [58.97]"
      //   (e) Fullwidth/CJK:     "［30.0］"
      //   (f) Mathematical/exotic angle brackets: "⟨30.0⟩"  "〈30.0〉"  "〔30.0〕"
      //   (g) Open-bracket only when the close ended up in a separate item:
      //       handled by treating any item containing "[" followed by digits
      //       as bracketed if no closing bracket appears in the same item.
      //
      // Bracket-character normalisation table: all map to ASCII [ or ].
      const openBrackets  = ['[', '［', '〔', '【', '⟨', '〈', '⟦'];
      const closeBrackets = [']', '］', '〕', '】', '⟩', '〉', '⟧'];

      function normaliseBrackets(s) {
        let out = s;
        for (const ch of openBrackets)  out = out.split(ch).join('[');
        for (const ch of closeBrackets) out = out.split(ch).join(']');
        return out;
      }

      const bracketRe = /\[\s*([^\[\]]+?)\s*\]/g;
      const primaries = [];
      const bracketedRaw = [];
      // Diagnostic counters — log to console at the end of the page pass
      let _diagBrackHits = 0;
      let _diagBrackOpenOnly = 0;
      for (const item of merged) {
        const text = normaliseBrackets((item.text || '').trim());
        if (!text) continue;

        // Find all balanced bracketed substrings
        const matches = [];
        let m;
        bracketRe.lastIndex = 0;
        while ((m = bracketRe.exec(text)) !== null) {
          matches.push({ inner: m[1], full: m[0], index: m.index });
        }

        if (matches.length === 0) {
          // No balanced brackets. Edge case: a stray `[<digits>` with no close,
          // which means PDF.js split the closing bracket into a different item
          // (or vice versa). In that case, treat the text as bracketed and
          // pull out the digit-y portion after the `[`.
          const openOnly = text.match(/\[\s*([0-9.\-Ø⌀]+)/);
          if (openOnly) {
            _diagBrackOpenOnly++;
            bracketedRaw.push({ ...item, text: text, _inner: openOnly[1] });
          } else {
            primaries.push({ ...item, text: text });
          }
        } else if (matches.length === 1 && matches[0].full === text) {
          // Whole item is one bracket — bracketed candidate
          _diagBrackHits++;
          bracketedRaw.push({ ...item, text: text, _inner: matches[0].inner });
        } else {
          // Mixed: extract bracketed substrings as separate virtual items,
          // then leave the remaining (non-bracketed) text as a primary.
          _diagBrackHits += matches.length;
          const itemLeft = item.x - item.width / 2;
          const charWidth = text.length > 0 ? (item.width / text.length) : 0;
          for (const mm of matches) {
            const subCx = itemLeft + (mm.index + mm.full.length / 2) * charWidth;
            bracketedRaw.push({
              ...item,
              text: mm.full,
              _inner: mm.inner,
              x: subCx,
              width: mm.full.length * charWidth
            });
          }
          // Primary remainder: strip all bracketed parts, keep what's left
          let leftover = text;
          for (const mm of matches) leftover = leftover.replace(mm.full, ' ');
          leftover = leftover.replace(/\s+/g, ' ').trim();
          if (leftover) {
            primaries.push({ ...item, text: leftover });
          }
        }
      }
      console.log('[auto-bubble] page', pageNum,
                  '· primaries:', primaries.length,
                  '· bracketed:', bracketedRaw.length,
                  '· bracketed (open-only):', _diagBrackOpenOnly,
                  '· bubbleBracketed flag:', bubbleBracketed);
      if (bracketedRaw.length > 0) {
        console.log('[auto-bubble] bracketed items detected:',
                    bracketedRaw.map(b => ({ text: b.text, inner: b._inner, x: Math.round(b.x), y: Math.round(b.y) })));
      }
      bracketedDetected += bracketedRaw.length;

      // -- Pass A: gather all parsable items as "candidates" --
      //
      // We collect everything we WANT to bubble first, then decide placements
      // in a single batch. Each candidate has:
      //   { kind, item, parsed, isBracketed, primaryRef? }
      //
      // kind: 'primary' | 'bracketed' | 'note'
      const candidates = [];
      // Regex used both to suppress notes from the primary pass (when notes
      // are being bubbled separately) AND inside the notes extractor itself.
      const noteLineRe = /^\s*\d{1,2}[.)]\s+\S/;
      for (const p of primaries) {
        // If notes are being bubbled separately, skip items that look like
        // numbered list entries — those belong to the notes pass and would
        // otherwise be double-counted.
        if (bubbleNotes && noteLineRe.test(p.text || '')) continue;
        const parsed = parseDimension(p.text, pageUnit);
        if (!parsed) continue;
        candidates.push({ kind: 'primary', item: p, parsed: parsed });
      }
      if (bubbleBracketed) {
        for (const b of bracketedRaw) {
          const inner = (b._inner !== undefined)
            ? b._inner
            : b.text.trim().replace(/^\[\s*|\s*\]$/g, '').trim();
          if (!inner) continue;
          const parsed = parseDimension(inner, pageUnit);
          if (!parsed) continue;
          candidates.push({ kind: 'bracketed', item: b, parsed: parsed });
        }
      }
      // Notes pass (if enabled)
      if (bubbleNotes) {
        const noteCandidates = extractNotesAsCandidates(merged, pageW, pageH);
        for (const nc of noteCandidates) candidates.push(nc);
      }

      if (candidates.length === 0) continue;

      // -- Pass B: smart placement near each dimension --
      //
      // Always use placeBubbleNearText so bubbles sit near their dimensions
      // (not on a perimeter ring — that was the old "clockwise placement"
      // behaviour, retired in favour of smart placement + clockwise NUMBERING).
      const placements = [];
      const localExisting = existingForPlacement.slice();
      for (const c of candidates) {
        const p = placeBubbleNearText(
          c.item, localExisting, merged,
          pageW, pageH, state.bubbleSize
        );
        placements.push(p);
        localExisting.push({
          x_centre: p.bx, y_centre: p.by, radius: (state.bubbleSize || 12)
        });
      }

      // -- Pass B.5: determine numbering order --
      //
      // If clockwise numbering is enabled, sort candidate INDICES by the
      // angle of each dimension's TEXT position from page-centre. The result
      // is a list of indices in the order we want to assign numbers.
      // (We sort indices, not the candidates themselves, so we don't disturb
      // the per-candidate placement[] mapping.)
      const orderIdx = candidates.map((_, i) => i);
      if (clockwise) {
        const cx = pageW / 2, cy = pageH / 2;
        const angleOf = (idx) => {
          const it = candidates[idx].item;
          // 0° = 12 o'clock, clockwise to 360°
          let a = Math.atan2(it.x - cx, -(it.y - cy)) * 180 / Math.PI;
          if (a < 0) a += 360;
          return a;
        };
        // Sort by angle; ties broken by distance from centre (closer first).
        orderIdx.sort((a, b) => {
          const da = angleOf(a), db = angleOf(b);
          if (Math.abs(da - db) > 0.1) return da - db;
          const ia = candidates[a].item, ib = candidates[b].item;
          const distA = Math.hypot(ia.x - cx, ia.y - cy);
          const distB = Math.hypot(ib.x - cx, ib.y - cy);
          return distA - distB;
        });
      }

      // -- Pass C: assign numbers + create bubble objects --
      //
      // Numbering rules:
      //   - 'primary' and 'note' get sequential numbers from state.nextId
      //     in the order defined by orderIdx (clockwise or detection order)
      //   - 'bracketed' gets <nearest primary>.a/.b/.c (by position)
      //   - if a bracketed has no primary within reach, it gets its own number
      //
      // Grid cell is computed from the DIMENSION's text position so it
      // indicates where to LOOK on the drawing, not where the bubble sits.

      const primaryBubblesForPairing = []; // { candIdx, bubble }
      const suffixByPrimary = new Map();   // primaryId -> last char code used
      const nextSuffix = (pid) => {
        const cur = suffixByPrimary.get(pid) || 96;
        const next = cur + 1;
        suffixByPrimary.set(pid, next);
        return '.' + String.fromCharCode(next);
      };

      // First sweep: assign numbers + create non-bracketed bubbles, in
      // orderIdx order so clockwise numbering takes effect.
      const createdBubbles = new Array(candidates.length);
      for (const idx of orderIdx) {
        const c = candidates[idx];
        if (c.kind === 'bracketed') continue; // do these in second sweep
        const id = 'b_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6) + '_' + idx;
        const num = state.nextId++;
        const placed = placements[idx];
        // Grid cell from the DIMENSION's position (where to LOOK on the
        // drawing), not the bubble's position.
        const cell = gridCellFor(c.item.x, c.item.y, pageW, pageH, state.gridRows, state.gridCols);
        const bubble = {
          id: id,
          num: num,
          label: defaultDescription(c.parsed, c.item.text),
          x:  placed.bx,
          y:  placed.by,
          ax: placed.tx,
          ay: placed.ty,
          shape: state.shape,
          size: state.bubbleSize,
          stroke: state.strokeWidth,
          fill: state.fillColor,
          fillOpacity: state.fillOpacity,
          textColor: state.textColor,
          gridCell: cell,
          refRect: buildRefRect(c.item),
          dim: {
            type: c.parsed.type || (c.kind === 'note' ? 'reference' : 'linear'),
            nominal: c.parsed.nominal || '',
            unit: c.parsed.unit || 'mm',
            tolPlus: c.parsed.tolPlus || '',
            tolMinus: c.parsed.tolMinus || '',
            gdtSym: c.parsed.gdtSym || '',
            gdtTol: c.parsed.gdtTol || '',
            gdtDatum: c.parsed.gdtDatum || '',
            critical: false,
            notes: (c.kind === 'note')
              ? (c.parsed.notes || 'Note item ' + (c.parsed.noteIndex || ''))
              : (c.parsed.notes || ''),
            rawText: c.parsed.rawText || c.item.text,
            // Dual-engine: when both PDF.js and OCR saw this text and
            // disagreed, carry the OCR reading as the alt so the user
            // can swap to it from the parts editor. mergeDualSourceItems
            // attaches altText/altSource to the item before candidates
            // are built; we just propagate them onto the bubble here.
            altRawText: c.item.altText || '',
            altSource:  c.item.altSource || '',
            autoSource: usedOcr ? 'ocr' : 'text-layer',
            parseConfidence: c.parsed.confidence || 'medium'
          }
        };
        createdBubbles[idx] = bubble;
        if (c.kind === 'primary') {
          primaryBubblesForPairing.push({ item: c.item, bubble: bubble });
        }
      }

      // Second sweep: bracketed dimensions, paired to nearest primary.
      // Also in orderIdx order so any bracketed that gets its OWN number
      // (because no primary was close enough) lands in the clockwise sweep.
      for (const idx of orderIdx) {
        const c = candidates[idx];
        if (c.kind !== 'bracketed') continue;
        const placed = placements[idx];

        // Find nearest primary on this page
        let bestPrimary = null;
        let bestDist = Infinity;
        for (const p of primaryBubblesForPairing) {
          const dx = p.item.x - c.item.x;
          const dy = p.item.y - c.item.y;
          const d = Math.hypot(dx, dy);
          if (d < bestDist) { bestDist = d; bestPrimary = p; }
        }
        const acceptanceRadius = c.item.height * 6 + 60;
        let numLabel;
        if (bestPrimary && bestDist <= acceptanceRadius) {
          numLabel = String(bestPrimary.bubble.num) + nextSuffix(bestPrimary.bubble.id);
        } else {
          numLabel = state.nextId++;
        }

        const id = 'b_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6) + '_b' + idx;
        // Grid cell from the bracketed value's own position (same convention)
        const cell = gridCellFor(c.item.x, c.item.y, pageW, pageH, state.gridRows, state.gridCols);
        const bubble = {
          id: id,
          num: numLabel,
          label: defaultDescription(c.parsed, c.item.text),
          x:  placed.bx,
          y:  placed.by,
          ax: placed.tx,
          ay: placed.ty,
          shape: state.shape,
          size: state.bubbleSize,
          stroke: state.strokeWidth,
          fill: state.fillColor,
          fillOpacity: state.fillOpacity,
          textColor: state.textColor,
          gridCell: cell,
          refRect: buildRefRect(c.item),
          dim: {
            type: c.parsed.type,
            nominal: c.parsed.nominal || '',
            unit: c.parsed.unit || 'mm',
            tolPlus: c.parsed.tolPlus || '',
            tolMinus: c.parsed.tolMinus || '',
            gdtSym: c.parsed.gdtSym || '',
            gdtTol: c.parsed.gdtTol || '',
            gdtDatum: c.parsed.gdtDatum || '',
            critical: false,
            notes: (c.parsed.notes ? c.parsed.notes + ' · ' : '') + 'Dual-unit equivalent',
            rawText: c.item.text,
            autoSource: usedOcr ? 'ocr' : 'text-layer',
            parseConfidence: c.parsed.confidence
          }
        };
        createdBubbles[idx] = bubble;
        bracketedBubbled++;
      }

      // Final: push created bubbles to the page state.
      // Iterate in orderIdx order so the parts list reads in numeric/clockwise
      // order rather than detection order.
      for (const idx of orderIdx) {
        const bubble = createdBubbles[idx];
        if (!bubble) continue;
        if (state.drawing.type === 'pdf') {
          if (!state.pageBubbles[pageNum]) state.pageBubbles[pageNum] = [];
          state.pageBubbles[pageNum].push(bubble);
          if (pageNum === state.pdf.page) state.bubbles.push(bubble);
        } else {
          state.bubbles.push(bubble);
        }
        ab.added.push({ pageNum, bubble });
        totalFound++;
      }
    }

    setProgress('Done', totalFound + ' bubble' + (totalFound === 1 ? '' : 's') + ' added', 1);

    $('next-number').value = state.nextId;
    render();
    renderParts();
    updateFooter();

    return {
      totalFound, viaOcr, viaTextLayer,
      pagesProcessed: pagesToProcess.length,
      bracketedDetected, bracketedBubbled,
      bubbleBracketedOpt: !!bubbleBracketed
    };
  }

  // ---- Undo: restore the snapshot ------------------------------------------
  function undoAutoBubble() {
    if (!ab.snapshot) return;
    if (ab.snapshot.pageBubbles) {
      state.pageBubbles = ab.snapshot.pageBubbles;
      // Refresh current page from snapshot
      state.bubbles = state.pageBubbles[state.pdf.page] ? state.pageBubbles[state.pdf.page].slice() : [];
    } else {
      state.bubbles = ab.snapshot.bubbles;
    }
    state.nextId = ab.snapshot.nextId;
    $('next-number').value = state.nextId;
    ab.snapshot = null;
    ab.added = [];
    render();
    renderParts();
    updateFooter();
  }

  // ---- Modal wiring --------------------------------------------------------
  function setProgress(label, detail, fraction) {
    const lbl = document.getElementById('ab-progress-label');
    const det = document.getElementById('ab-progress-detail');
    const fill = document.getElementById('ab-progress-fill');
    if (lbl) lbl.textContent = label;
    if (det) det.textContent = detail;
    if (fill) fill.style.width = Math.round(Math.max(0, Math.min(1, fraction)) * 100) + '%';
  }
  function showStage(stage) {
    document.getElementById('ab-stage-intro').style.display    = stage === 'intro'    ? 'block' : 'none';
    document.getElementById('ab-stage-progress').style.display = stage === 'progress' ? 'block' : 'none';
    document.getElementById('ab-stage-result').style.display   = stage === 'result'   ? 'block' : 'none';
  }
  function openAbModal() {
    showStage('intro');
    document.getElementById('ab-modal').classList.add('visible');
  }
  function closeAbModal() {
    document.getElementById('ab-modal').classList.remove('visible');
  }

  // Open the modal — disable button if no PDF loaded
  function refreshAbButtonState() {
    const btn = document.getElementById('btn-auto-bubble');
    if (!btn) return;
    btn.disabled = !(state.drawing.loaded && state.drawing.type === 'pdf');
  }

  $('btn-auto-bubble').addEventListener('click', () => {
    if (!(state.drawing.loaded && state.drawing.type === 'pdf')) {
      alert('Auto-bubble requires a PDF to be loaded.');
      return;
    }
    openAbModal();
  });
  $('ab-cancel-1').addEventListener('click', closeAbModal);

  $('ab-start').addEventListener('click', async () => {
    const scope = document.querySelector('input[name="ab-scope"]:checked').value;
    const allowOcr = document.getElementById('ab-allow-ocr').checked;
    const skipTitleBlock = document.getElementById('ab-skip-title-block').checked;
    const bubbleBracketed = document.getElementById('ab-bracketed').checked;
    const bubbleNotes = document.getElementById('ab-notes').checked;
    const clockwise = document.getElementById('ab-clockwise').checked;
    const showRulers = document.getElementById('ab-rulers').checked;
    const gridRows = parseInt(document.getElementById('ab-grid-rows').value);
    const gridCols = parseInt(document.getElementById('ab-grid-cols').value);
    showStage('progress');
    setProgress('Starting…', 'Preparing pages…', 0);
    try {
      const result = await runAutoBubble({
        scope, allowOcr, skipTitleBlock, bubbleBracketed,
        bubbleNotes, clockwise, showRulers, gridRows, gridCols
      });
      // Build result preview
      const preview = document.getElementById('ab-preview');
      preview.innerHTML = '';
      ab.added.forEach(({ pageNum, bubble }) => {
        const d = bubble.dim;
        const conf = d.parseConfidence || 'low';
        const confClass = conf === 'high' ? 'ab-conf-high' : (conf === 'medium' ? 'ab-conf-med' : 'ab-conf-low');
        const row = document.createElement('div');
        row.className = 'ab-preview-row';
        row.innerHTML =
          '<div class="ab-num">' + escapeHtml(state.prefix + bubble.num) + '</div>' +
          '<div class="ab-text">' + escapeHtml(d.rawText || '') + '</div>' +
          '<div class="ab-conf ' + confClass + '">' + conf + '</div>';
        preview.appendChild(row);
      });
      const summary = result.totalFound === 0
        ? 'No dimensions detected.'
        : 'Added ' + result.totalFound + ' bubble' + (result.totalFound === 1 ? '' : 's') + '.';
      const detail = [];
      if (result.viaTextLayer > 0) detail.push(result.viaTextLayer + ' page' + (result.viaTextLayer === 1 ? '' : 's') + ' via text layer');
      if (result.viaOcr > 0) detail.push(result.viaOcr + ' page' + (result.viaOcr === 1 ? '' : 's') + ' via OCR');
      // Bracketed dual-unit diagnostic
      if (result.bubbleBracketedOpt) {
        if (result.bracketedBubbled > 0) {
          detail.push(result.bracketedBubbled + ' dual-unit (.a) bubble' + (result.bracketedBubbled === 1 ? '' : 's'));
        } else if (result.bracketedDetected > 0) {
          detail.push(result.bracketedDetected + ' bracketed value' + (result.bracketedDetected === 1 ? '' : 's') + ' detected but no .a bubbles (parsing rejected them — check console)');
        } else {
          detail.push('No bracketed values detected in PDF text layer');
        }
      } else if (result.bracketedDetected > 0) {
        detail.push(result.bracketedDetected + ' bracketed value' + (result.bracketedDetected === 1 ? '' : 's') + ' skipped (option off)');
      }
      document.getElementById('ab-result-summary').textContent = summary;
      document.getElementById('ab-result-detail').textContent = detail.join(' · ');
      showStage('result');
    } catch (err) {
      console.error('Auto-bubble failed:', err);
      undoAutoBubble();
      alert('Auto-bubble failed: ' + (err && err.message ? err.message : err));
      closeAbModal();
    }
  });

  $('ab-discard').addEventListener('click', () => {
    undoAutoBubble();
    closeAbModal();
  });
  $('ab-accept').addEventListener('click', () => {
    ab.snapshot = null;
    ab.added = [];
    closeAbModal();
  });

  // Refresh Auto-bubble button state whenever a drawing loads or unloads.
  // The simplest hook is to wrap fitDrawingToStage to refresh after each
  // load — but fitDrawingToStage is called in many places. Easier: poll
  // briefly via render(). We add it to the end of render().
  // (Wired below — see the patch around `function render`.)
  window.refreshAbButtonState = refreshAbButtonState;

  // ============ EXPORT CSV ============
  $('btn-export-csv').addEventListener('click', () => {
    // Collect bubbles across all pages (PDF) or just current (image)
    let allRows = [];
    if (state.drawing.type === 'pdf') {
      // Make sure current page is synced
      syncPage();
      const pages = Object.keys(state.pageBubbles).map(Number).sort((a,b) => a-b);
      pages.forEach(p => {
        state.pageBubbles[p].forEach(b => allRows.push({ page: p, b }));
      });
    } else {
      state.bubbles.forEach(b => allRows.push({ page: 1, b }));
    }
    if (allRows.length === 0) { alert('No bubbles to export.'); return; }

    const baseCols = ['Item'];
    if (state.drawing.type === 'pdf') baseCols.push('Page');
    baseCols.push('Number', 'Grid Cell', 'Description', 'Type', 'Nominal', 'Tol +', 'Tol −', 'Unit', 'GD&T Symbol', 'GD&T Tolerance', 'Datum Refs', 'Critical', 'Notes', 'Dimension (formatted)', 'Raw Text (as detected)', 'Source', 'Parse Confidence', 'X', 'Y');
    const rows = [baseCols];

    allRows.forEach((r, i) => {
      const b = r.b;
      const d = ensureDim(b);
      const row = [i + 1];
      if (state.drawing.type === 'pdf') row.push(r.page);
      // Grid cell: stored on the bubble at placement time; for manual bubbles
      // or older auto-bubbles without one, compute on the fly so the column
      // is never empty.
      let cell = b.gridCell;
      if (!cell && state.drawing.baseW && state.drawing.baseH) {
        cell = gridCellFor(b.x, b.y, state.drawing.baseW, state.drawing.baseH,
                           state.gridRows || 4, state.gridCols || 4);
      }
      row.push(
        state.prefix + b.num,
        cell || '',
        b.label,
        d.type,
        d.nominal,
        d.tolPlus,
        d.tolMinus,
        d.unit,
        d.gdtSym,
        d.gdtTol,
        d.gdtDatum,
        d.critical ? 'YES' : '',
        d.notes,
        formatDim(b),
        d.rawText || '',
        d.autoSource || 'manual',
        d.parseConfidence || '',
        Math.round(b.x),
        Math.round(b.y)
      );
      rows.push(row);
    });
    const csv = rows.map(r => r.map(c => {
      const s = String(c == null ? '' : c);
      return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'parts-list.csv';
    a.click();
    URL.revokeObjectURL(url);
  });

  // ============ EXPORT PDF ============
  // Renders a given page's image + bubbles to a canvas at the source resolution,
  // baking in current rotation. For multi-page PDFs, this is called per page.
  // Returns a Promise<{canvas, widthPt, heightPt}> where pt = points (1pt = 1/72in).
  async function renderAnnotatedCanvas(opts) {
    // opts: {imgEl, srcW, srcH, dispW, dispH, bubbles, redactions, rotation, prefix}
    const { imgEl, srcW, srcH, dispW, dispH, bubbles, rotation, prefix } = opts;
    const redactions = opts.redactions || [];
    const texts = opts.texts || [];
    const rot = ((rotation % 360) + 360) % 360;
    const isQuarter = (Math.abs(rot - 90) < 1 || Math.abs(rot - 270) < 1);
    const canvasW = isQuarter ? srcH : srcW;
    const canvasH = isQuarter ? srcW : srcH;

    const canvas = document.createElement('canvas');
    canvas.width = canvasW;
    canvas.height = canvasH;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvasW, canvasH);
    ctx.save();
    ctx.translate(canvasW / 2, canvasH / 2);
    ctx.rotate(rot * Math.PI / 180);
    ctx.drawImage(imgEl, -srcW / 2, -srcH / 2, srcW, srcH);
    ctx.restore();

    // Build SVG of just these bubbles (display-coords), then transform & rasterize on top
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('xmlns', ns);
    svg.setAttribute('width', canvasW);
    svg.setAttribute('height', canvasH);
    svg.setAttribute('viewBox', `0 0 ${canvasW} ${canvasH}`);

    const g = document.createElementNS(ns, 'g');
    const scaleX = srcW / dispW;
    g.setAttribute('transform',
      `translate(${canvasW / 2}, ${canvasH / 2}) ` +
      `rotate(${rot}) ` +
      `translate(${-srcW / 2}, ${-srcH / 2}) ` +
      `scale(${scaleX})`
    );

    // Render redactions FIRST so bubbles paint on top
    redactions.forEach(r => {
      const rect = document.createElementNS(ns, 'rect');
      rect.setAttribute('x', r.x);
      rect.setAttribute('y', r.y);
      rect.setAttribute('width', r.w);
      rect.setAttribute('height', r.h);
      rect.setAttribute('fill', r.style === 'black' ? '#000000' : '#ffffff');
      // No stroke in export — clean edge
      g.appendChild(rect);
    });

    bubbles.forEach(b => {
      ensureDim(b);
      // Opacity for all bubble parts (fill, border, leader, anchor)
      const foExp = (typeof b.fillOpacity === 'number') ? b.fillOpacity : 1;

      // Leader line — always black for visibility in print/export.
      // Stop at bubble edge so it doesn't pass through the transparent fill.
      const ldx = b.x - b.ax, ldy = b.y - b.ay;
      const llen = Math.hypot(ldx, ldy) || 1;
      const lEndX = b.x - (ldx / llen) * b.size;
      const lEndY = b.y - (ldy / llen) * b.size;
      const line = document.createElementNS(ns, 'line');
      line.setAttribute('x1', b.ax); line.setAttribute('y1', b.ay);
      line.setAttribute('x2', lEndX); line.setAttribute('y2', lEndY);
      line.setAttribute('stroke', '#000000');
      line.setAttribute('stroke-width', b.stroke);
      line.setAttribute('stroke-opacity', foExp);
      g.appendChild(line);
      // Anchor dot — also black, also opacity-linked
      const anchor = document.createElementNS(ns, 'circle');
      anchor.setAttribute('cx', b.ax); anchor.setAttribute('cy', b.ay);
      anchor.setAttribute('r', 3);
      anchor.setAttribute('fill', '#000000');
      anchor.setAttribute('fill-opacity', foExp);
      anchor.setAttribute('stroke', '#1a1f2e');
      anchor.setAttribute('stroke-width', 1);
      anchor.setAttribute('stroke-opacity', foExp);
      g.appendChild(anchor);
      // Critical ring (drawn before bubble so it sits behind)
      if (b.dim.critical) {
        let critEl;
        const ringSize = b.size + Math.max(3, b.size * 0.18);
        if (b.shape === 'circle') {
          critEl = document.createElementNS(ns, 'circle');
          critEl.setAttribute('cx', b.x); critEl.setAttribute('cy', b.y);
          critEl.setAttribute('r', ringSize);
        } else {
          critEl = document.createElementNS(ns, 'polygon');
          critEl.setAttribute('points', shapePath(b.shape, b.x, b.y, ringSize));
        }
        critEl.setAttribute('fill', 'none');
        critEl.setAttribute('stroke', '#b91c1c');
        critEl.setAttribute('stroke-width', Math.max(1.5, b.stroke));
        critEl.setAttribute('stroke-dasharray', '3 2');
        g.appendChild(critEl);
      }
      // Bubble shape
      let shapeEl;
      if (b.shape === 'circle') {
        shapeEl = document.createElementNS(ns, 'circle');
        shapeEl.setAttribute('cx', b.x); shapeEl.setAttribute('cy', b.y);
        shapeEl.setAttribute('r', b.size);
      } else {
        shapeEl = document.createElementNS(ns, 'polygon');
        shapeEl.setAttribute('points', shapePath(b.shape, b.x, b.y, b.size));
      }
      shapeEl.setAttribute('fill', b.fill);
      shapeEl.setAttribute('fill-opacity', foExp);
      shapeEl.setAttribute('stroke', '#1a1f2e');
      shapeEl.setAttribute('stroke-width', b.stroke);
      shapeEl.setAttribute('stroke-opacity', foExp);
      g.appendChild(shapeEl);
      // Number text — counter-rotate to keep upright in the rotated drawing
      const text = document.createElementNS(ns, 'text');
      text.setAttribute('x', b.x); text.setAttribute('y', b.y);
      text.setAttribute('text-anchor', 'middle');
      text.setAttribute('dominant-baseline', 'central');
      text.setAttribute('fill', b.textColor);
      text.setAttribute('font-family', 'JetBrains Mono, monospace');
      const fontSize = Math.max(9, b.size * 0.75);
      text.setAttribute('font-size', fontSize);
      text.setAttribute('font-weight', '700');
      if (rot) {
        text.setAttribute('transform', `rotate(${-rot} ${b.x} ${b.y})`);
      }
      text.textContent = prefix + b.num;
      g.appendChild(text);
    });

    // Render text annotations on top
    texts.forEach(t => {
      const lines = (t.content || '').split('\n');
      const lineH = t.fontSize * 1.3;
      lines.forEach((line, i) => {
        const txt = document.createElementNS(ns, 'text');
        txt.setAttribute('x', t.x);
        txt.setAttribute('y', t.y + i * lineH);
        txt.setAttribute('fill', t.color);
        txt.setAttribute('font-family', 'Inter, system-ui, sans-serif');
        txt.setAttribute('font-size', t.fontSize);
        if (rot) {
          txt.setAttribute('transform', `rotate(${-rot} ${t.x} ${t.y})`);
        }
        txt.textContent = line || ' ';
        g.appendChild(txt);
      });
    });

    svg.appendChild(g);

    const svgStr = new XMLSerializer().serializeToString(svg);
    const svgBlob = new Blob([svgStr], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(svgBlob);
    await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        ctx.drawImage(img, 0, 0, canvasW, canvasH);
        URL.revokeObjectURL(url);
        resolve();
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('SVG render failed')); };
      img.src = url;
    });

    return { canvas, widthPx: canvasW, heightPx: canvasH };
  }

  function showProgress(label, detail, pct) {
    $('progress-overlay').classList.add('visible');
    $('progress-label').textContent = label;
    $('progress-detail').textContent = detail;
    $('progress-bar-fill').style.width = (pct || 0) + '%';
  }
  function hideProgress() {
    $('progress-overlay').classList.remove('visible');
  }

  $('btn-export-pdf').addEventListener('click', async () => {
    if (!state.drawing.loaded) { alert('Load a drawing first.'); return; }
    if (!window.jspdf || !window.jspdf.jsPDF) {
      alert('PDF export library failed to load. Check your internet connection and refresh.');
      return;
    }
    const { jsPDF } = window.jspdf;

    try {
      // Sync current page bubbles into the per-page map (PDF mode)
      syncPage();

      if (state.drawing.type === 'pdf') {
        // ---- Multi-page vector-preserving PDF export ----
        // Strategy: use pdf-lib to load the ORIGINAL PDF (preserving all
        // vector text, lines, fonts, images at full fidelity), then for each
        // page draw bubbles + redactions as native PDF objects on top.
        // Falls back to rasterized export if pdf-lib is unavailable or the
        // original bytes are missing.
        if (!window.PDFLib || !state.pdf.originalBytes) {
          // Fallback: rasterized export (legacy path)
          await exportRasterizedPdf();
          return;
        }

        showProgress('EXPORTING PDF', 'Loading source PDF…', 5);
        await new Promise(r => setTimeout(r, 10));

        const { PDFDocument, rgb, degrees, pushGraphicsState, popGraphicsState, concatTransformationMatrix } = window.PDFLib;

        // Load each appended source PDF as a separate pdf-lib document.
        // In single-source mode (the common case — no append yet),
        // state.pdf.sources is null and we just load state.pdf.originalBytes
        // into a single srcDoc, mirroring the legacy behavior.
        //
        // The mapping below converts a global page number (1-based across
        // ALL sources) to {srcDocIdx, localIdx} where srcDocIdx indexes
        // libSources and localIdx is 0-based within that source. Drawing
        // tools always work in global page numbers, so we only need this
        // mapping at copy/annotate time.
        var libSources;
        var globalToLocal;  // function: globalPage1Based → {libIdx, localIdx0Based}
        if (state.pdf.sources && state.pdf.sources.length > 1) {
          libSources = [];
          for (var si = 0; si < state.pdf.sources.length; si++) {
            libSources.push(await PDFDocument.load(state.pdf.sources[si].originalBytes.slice(0)));
          }
          globalToLocal = function (g) {
            var rem = g - 1;
            for (var i = 0; i < state.pdf.sources.length; i++) {
              var pc = state.pdf.sources[i].pageCount;
              if (rem < pc) return { libIdx: i, localIdx: rem };
              rem -= pc;
            }
            return null;
          };
        } else {
          libSources = [await PDFDocument.load(state.pdf.originalBytes.slice(0))];
          globalToLocal = function (g) {
            return { libIdx: 0, localIdx: g - 1 };
          };
        }
        // Keep srcDoc as an alias for the FIRST source so the legacy code
        // below that references it directly (e.g. getPageCount when no
        // pageOrder is set) keeps working in the single-source case.
        const srcDoc = libSources[0];
        const originalRotation = state.rotation;
        const rotNorm = ((originalRotation % 360) + 360) % 360;

        // Build the export-page list. If the user has used the page manager,
        // honor pageOrder (with rotations and deletions). Otherwise, fall back
        // to original 1:1 mapping. Each entry: { srcGlobal, extraRotation }
        // where srcGlobal is 1-based global page across ALL appended sources.
        let exportEntries;
        if (state.pageOrder && state.pageOrder.length > 0) {
          // state.pageOrder stores srcIdx as the GLOBAL 0-based index across
          // all appended sources (the page manager treats them uniformly).
          exportEntries = state.pageOrder
            .filter(e => !e.deleted)
            .map(e => ({ srcGlobal: e.srcIdx + 1, extraRotation: e.rotation || 0 }));
        } else {
          var totalGlobal = state.pdf.sources
            ? state.pdf.sources.reduce(function (n, s) { return n + s.pageCount; }, 0)
            : srcDoc.getPageCount();
          exportEntries = [];
          for (let i = 0; i < totalGlobal; i++) {
            exportEntries.push({ srcGlobal: i + 1, extraRotation: 0 });
          }
        }
        if (exportEntries.length === 0) {
          hideProgress();
          alert('No pages to export — all pages are deleted in the page manager.');
          return;
        }

        // Copy source pages into the output doc. To minimize the number
        // of async round-trips (pdf-lib's copyPages is awaitable and
        // each call has overhead), we group entries by their source
        // document and call copyPages ONCE per source with the full
        // list of local indices that source contributes. Output order
        // is preserved by interleaving the copied pages back into the
        // outDoc.addPage stream in original order.
        const outDoc = await PDFDocument.create();
        const outSrcGlobal = [];

        // Bucket entries by libIdx, remembering each entry's output position.
        var bucketByLib = {};
        for (var bi = 0; bi < exportEntries.length; bi++) {
          var ent2 = exportEntries[bi];
          var loc2 = globalToLocal(ent2.srcGlobal);
          if (!loc2) continue;
          if (!bucketByLib[loc2.libIdx]) bucketByLib[loc2.libIdx] = { indices: [], outPositions: [], srcGlobals: [] };
          bucketByLib[loc2.libIdx].indices.push(loc2.localIdx);
          bucketByLib[loc2.libIdx].outPositions.push(bi);
          bucketByLib[loc2.libIdx].srcGlobals.push(ent2.srcGlobal);
        }
        // Per-source copyPages call. Stash the copied page references
        // in a slot indexed by output position so we can addPage them
        // in the right order afterwards.
        var pagesByOutPos = new Array(exportEntries.length);
        var srcGlobalByOutPos = new Array(exportEntries.length);
        for (var libIdx in bucketByLib) {
          if (!Object.prototype.hasOwnProperty.call(bucketByLib, libIdx)) continue;
          var bucket = bucketByLib[libIdx];
          var copiedBatch = await outDoc.copyPages(libSources[libIdx], bucket.indices);
          for (var ci = 0; ci < copiedBatch.length; ci++) {
            pagesByOutPos[bucket.outPositions[ci]] = copiedBatch[ci];
            srcGlobalByOutPos[bucket.outPositions[ci]] = bucket.srcGlobals[ci];
          }
        }
        // Now add pages to outDoc in output order.
        for (var pi = 0; pi < pagesByOutPos.length; pi++) {
          if (pagesByOutPos[pi]) {
            outDoc.addPage(pagesByOutPos[pi]);
            outSrcGlobal.push(srcGlobalByOutPos[pi]);
          }
        }

        // Now annotate each output page based on the source page's annotations.
        // We use the same per-source-page data — if a source page was duplicated,
        // both copies receive the same annotations, which is the sensible default.
        for (let outIdx = 0; outIdx < exportEntries.length; outIdx++) {
          const entry = exportEntries[outIdx];
          // srcPageNum is the global page number (1-based across all sources),
          // which is exactly how state.pageBubbles is keyed.
          const srcPageNum = outSrcGlobal[outIdx];
          showProgress('EXPORTING PDF', `Annotating page ${outIdx + 1} of ${exportEntries.length}…`, 5 + outIdx / exportEntries.length * 80);
          await new Promise(r => setTimeout(r, 5));

          const page = outDoc.getPage(outIdx);
          const { width: pageW, height: pageH } = page.getSize();

          // Combined rotation: per-page extra (from page manager) + global display
          const combinedRot = ((entry.extraRotation + rotNorm) % 360 + 360) % 360;
          if (combinedRot !== 0) {
            const existing = page.getRotation().angle || 0;
            page.setRotation(degrees((existing + combinedRot) % 360));
          }

          const bubbles = state.pageBubbles[srcPageNum] || [];
          const redactions = state.pageRedactions[srcPageNum] || [];
          const texts = state.pageTexts[srcPageNum] || [];
          if (bubbles.length === 0 && redactions.length === 0 && texts.length === 0) continue;

          // Bubble + redaction coordinates were captured in display pixels
          // when the page was annotated. Convert to PDF points using the
          // ratio of page point dimensions to the display dimensions.
          // PDF.js scale=1 viewport produces dims = page points, so we use
          // those as the reference. The display dims (baseW/baseH) capture
          // the on-screen size, which scales linearly to PDF points.
          const baseW = state.drawing.baseW || pageW;
          const baseH = state.drawing.baseH || pageH;
          // dispW/dispH could differ slightly from baseW/baseH in edge cases,
          // but for any annotated page they were equal at annotation time.
          const sx = pageW / baseW;
          const sy = pageH / baseH;

          // PDF Y-axis is bottom-up; screen Y is top-down. Flip Y.
          const toPdfX = x => x * sx;
          const toPdfY = y => pageH - y * sy;

          // ---- Draw redactions first (so bubbles can sit on top) ----
          redactions.forEach(r => {
            const x1 = toPdfX(r.x);
            const y1 = toPdfY(r.y + r.h); // bottom edge in PDF coords
            const w = r.w * sx;
            const h = r.h * sy;
            const fill = r.style === 'black' ? rgb(0, 0, 0) : rgb(1, 1, 1);
            page.drawRectangle({
              x: x1, y: y1, width: w, height: h,
              color: fill,
              borderWidth: 0,
              opacity: 1
            });
          });

          // ---- Draw bubbles ----
          bubbles.forEach(b => {
            ensureDim(b);
            const cx = toPdfX(b.x);
            const cy = toPdfY(b.y);
            const ax = toPdfX(b.ax);
            const ay = toPdfY(b.ay);
            const size = b.size * sx;
            const stroke = Math.max(0.5, b.stroke * sx);

            // Parse fill color (hex → rgb 0–1)
            const fillRgb = hexToPdfRgb(b.fill);
            const textRgb = hexToPdfRgb(b.textColor);
            const black = rgb(0.1, 0.12, 0.15);
            const danger = rgb(0.95, 0.36, 0.36);

            // Leader line
            page.drawLine({
              start: { x: ax, y: ay },
              end: { x: cx, y: cy },
              thickness: stroke,
              color: fillRgb
            });
            // Anchor dot
            page.drawCircle({
              x: ax, y: ay, size: 3 * sx,
              color: fillRgb,
              borderColor: black,
              borderWidth: 0.5
            });
            // Critical ring (drawn before bubble shape, slightly larger)
            if (b.dim.critical) {
              const ringSize = size + Math.max(3 * sx, size * 0.18);
              drawBubbleShape(page, b.shape, cx, cy, ringSize, {
                fill: null,
                border: danger,
                borderWidth: Math.max(1.5 * sx, stroke),
                dashArray: [3 * sx, 2 * sx]
              });
            }
            // Bubble shape
            drawBubbleShape(page, b.shape, cx, cy, size, {
              fill: fillRgb,
              border: black,
              borderWidth: stroke
            });
            // Number text — counter-rotate so it appears upright even when
            // the page has /Rotate set. We push a graphics-state, apply a
            // transformation matrix that rotates by -rotNorm around the
            // bubble center (cx, cy), draw the text, then pop the state.
            // This composes correctly with page rotation: the page rotates
            // everything by +rotNorm visually, our content-stream matrix
            // rotates the text by -rotNorm before that, so text ends up at
            // 0° in the viewer's perspective.
            const fontSize = Math.max(7, size * 0.85);
            const label = state.prefix + b.num;
            // pdf-lib doesn't measure text width without an embedded font, so
            // approximate centering: average glyph width ≈ 0.55 * fontSize
            // for Helvetica-Bold digits/letters. Good enough for centered nums.
            const approxW = label.length * fontSize * 0.55;
            const baselineOffset = fontSize / 2.8;

            if (combinedRot !== 0) {
              // Build matrix for "rotate by -combinedRot around (cx, cy)":
              // [a b c d e f] where (e, f) is the post-translation that
              // ensures the rotation pivots at (cx, cy).
              const theta = -combinedRot * Math.PI / 180;
              const cos = Math.cos(theta);
              const sin = Math.sin(theta);
              const a = cos, bMat = sin, c = -sin, d = cos;
              const e = cx - cx * cos + cy * sin;
              const f = cy - cx * sin - cy * cos;
              page.pushOperators(
                pushGraphicsState(),
                concatTransformationMatrix(a, bMat, c, d, e, f)
              );
            }
            page.drawText(label, {
              x: cx - approxW / 2,
              y: cy - baselineOffset,
              size: fontSize,
              color: textRgb
            });
            if (combinedRot !== 0) {
              page.pushOperators(popGraphicsState());
            }
          });

          // ---- Draw text annotations on top of bubbles ----
          // Each line is drawn as a separate drawText call (PDF doesn't auto-wrap).
          // For rotated pages, we wrap each annotation in a graphics-state matrix
          // that counter-rotates around the annotation's anchor (t.x, t.y) so
          // the text appears upright in the rotated viewport — same approach
          // used for bubble numbers above.
          texts.forEach(t => {
            if (!t.content || !t.content.trim()) return;
            const tx = toPdfX(t.x);
            const ty = toPdfY(t.y);
            const fontSize = Math.max(6, t.fontSize * sx);
            const lineH = fontSize * 1.3;
            const lines = t.content.split('\n');
            const colorRgb = hexToPdfRgb(t.color);

            if (combinedRot !== 0) {
              const theta = -combinedRot * Math.PI / 180;
              const cos = Math.cos(theta);
              const sin = Math.sin(theta);
              const e = tx - tx * cos + ty * sin;
              const f = ty - tx * sin - ty * cos;
              page.pushOperators(
                pushGraphicsState(),
                concatTransformationMatrix(cos, sin, -sin, cos, e, f)
              );
            }
            lines.forEach((line, i) => {
              if (!line) return;
              page.drawText(line, {
                x: tx,
                y: ty - i * lineH,
                size: fontSize,
                color: colorRgb
              });
            });
            if (combinedRot !== 0) {
              page.pushOperators(popGraphicsState());
            }
          });
        }

        // ---- Append parts list pages from jsPDF, then merge ----
        showProgress('EXPORTING PDF', 'Building parts list…', 88);
        await new Promise(r => setTimeout(r, 10));

        const allBubbles = [];
        const srcPageCount = srcDoc.getPageCount();
        for (let p = 1; p <= srcPageCount; p++) {
          (state.pageBubbles[p] || []).forEach(b => allBubbles.push({ page: p, b }));
        }
        if (allBubbles.length > 0) {
          const { jsPDF } = window.jspdf;
          const partsDoc = new jsPDF({ orientation: 'l', unit: 'pt', format: 'a4', compress: true });
          // jsPDF starts with one blank page; we add real pages then remove the blank
          appendPartsListPage(partsDoc, allBubbles, true);
          partsDoc.deletePage(1);
          const partsBytes = partsDoc.output('arraybuffer');
          const partsLib = await PDFDocument.load(partsBytes);
          const copied = await outDoc.copyPages(partsLib, partsLib.getPageIndices());
          copied.forEach(pg => outDoc.addPage(pg));
        }

        showProgress('EXPORTING PDF', 'Finalizing…', 98);
        await new Promise(r => setTimeout(r, 30));
        const outBytes = await outDoc.save();
        const blob = new Blob([outBytes], { type: 'application/pdf' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'drawing-annotated.pdf';
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        hideProgress();
      } else {
        // ---- Single-page (image) export ----
        showProgress('EXPORTING PDF', 'Rendering drawing…', 30);
        await new Promise(r => setTimeout(r, 10));
        const dispW = drawingImg.offsetWidth;
        const dispH = drawingImg.offsetHeight;
        const { canvas } = await renderAnnotatedCanvas({
          imgEl: drawingImg,
          srcW: state.drawing.w,
          srcH: state.drawing.h,
          dispW, dispH,
          bubbles: state.bubbles,
          redactions: state.redactions,
          texts: state.texts,
          rotation: state.rotation,
          prefix: state.prefix
        });

        // Pick page size: use canvas px → pt at 96 DPI (1px = 0.75pt)
        const pxToPt = 0.75;
        let pageWPt = canvas.width * pxToPt;
        let pageHPt = canvas.height * pxToPt;
        // Cap to avoid absurd page sizes
        const MAX_PT = 14400; // 200 inches
        if (pageWPt > MAX_PT || pageHPt > MAX_PT) {
          const k = MAX_PT / Math.max(pageWPt, pageHPt);
          pageWPt *= k; pageHPt *= k;
        }
        const orientation = pageWPt >= pageHPt ? 'l' : 'p';
        const pdfDoc = new jsPDF({
          orientation,
          unit: 'pt',
          format: [pageWPt, pageHPt],
          compress: true
        });
        const imgData = canvas.toDataURL('image/jpeg', 0.92);
        showProgress('EXPORTING PDF', 'Embedding image…', 70);
        await new Promise(r => setTimeout(r, 10));
        pdfDoc.addImage(imgData, 'JPEG', 0, 0, pageWPt, pageHPt, undefined, 'FAST');

        if (state.bubbles.length > 0) {
          showProgress('EXPORTING PDF', 'Appending parts list…', 90);
          await new Promise(r => setTimeout(r, 10));
          const all = state.bubbles.map(b => ({ page: 1, b }));
          appendPartsListPage(pdfDoc, all, false);
        }

        showProgress('EXPORTING PDF', 'Finalizing…', 100);
        await new Promise(r => setTimeout(r, 50));
        pdfDoc.save('drawing-annotated.pdf');
        hideProgress();
      }
    } catch (err) {
      console.error(err);
      hideProgress();
      alert('PDF export failed: ' + (err.message || 'unknown error'));
    }
  });

  function loadImageFromDataUrl(dataUrl) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('Failed to load image'));
      img.src = dataUrl;
    });
  }

  // Convert "#rrggbb" to pdf-lib's rgb(r, g, b) with each channel 0–1.
  // Tolerant of leading # absence and shorthand 3-char hex.
  function hexToPdfRgb(hex) {
    const { rgb } = window.PDFLib;
    if (!hex) return rgb(0, 0, 0);
    let h = hex.replace('#', '').trim();
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    if (h.length !== 6) return rgb(0, 0, 0);
    const r = parseInt(h.slice(0, 2), 16) / 255;
    const g = parseInt(h.slice(2, 4), 16) / 255;
    const b = parseInt(h.slice(4, 6), 16) / 255;
    return rgb(r, g, b);
  }

  // Draw a bubble's shape (circle/hexagon/square/diamond) on a pdf-lib page.
  // opts: { fill, border, borderWidth, dashArray? }
  function drawBubbleShape(page, shape, cx, cy, size, opts) {
    const { fill, border, borderWidth, dashArray } = opts;
    const drawOpts = {
      borderColor: border,
      borderWidth: borderWidth,
    };
    if (fill) drawOpts.color = fill;
    if (dashArray) drawOpts.borderDashArray = dashArray;

    if (shape === 'circle') {
      page.drawCircle({ x: cx, y: cy, size, ...drawOpts });
      return;
    }
    // For polygons, build SVG path and use drawSvgPath
    let path = '';
    if (shape === 'hexagon') {
      const pts = [];
      for (let i = 0; i < 6; i++) {
        const a = Math.PI / 6 + (Math.PI / 3) * i;
        // PDF Y is inverted from the screen calc: x = cx + r*cos(a), but we
        // need to flip the y-component since drawSvgPath treats path coords
        // as PDF-native (already in our toPdfX/toPdfY system). Hexagon is
        // built using +sin which drops in PDF space — flip sign.
        pts.push([cx + size * Math.cos(a), cy - size * Math.sin(a)]);
      }
      path = 'M ' + pts.map(p => p.join(' ')).join(' L ') + ' Z';
    } else if (shape === 'square') {
      const s = size * Math.SQRT2 * 0.9;
      path = `M ${cx - s/2} ${cy - s/2} L ${cx + s/2} ${cy - s/2} L ${cx + s/2} ${cy + s/2} L ${cx - s/2} ${cy + s/2} Z`;
    } else if (shape === 'diamond') {
      path = `M ${cx} ${cy + size} L ${cx + size} ${cy} L ${cx} ${cy - size} L ${cx - size} ${cy} Z`;
    }
    if (path) {
      page.drawSvgPath(path, drawOpts);
    }
  }

  // Legacy rasterized-PDF export (used as fallback when pdf-lib is unavailable
  // or original bytes are missing — e.g. after a session memory eviction)
  async function exportRasterizedPdf() {
    const { jsPDF } = window.jspdf;
    showProgress('EXPORTING PDF', 'Initializing (rasterized fallback)…', 0);
    const originalRotation = state.rotation;
    const totalPages = state.pdf.total;
    let pdfDoc = null;
    for (let p = 1; p <= totalPages; p++) {
      showProgress('EXPORTING PDF', `Rendering page ${p} of ${totalPages}…`, ((p - 1) / totalPages) * 100);
      await new Promise(r => setTimeout(r, 10));
      const page = await state.pdf.doc.getPage(p);
      const renderScale = 2;
      const viewport = page.getViewport({ scale: renderScale });
      const pageCanvas = document.createElement('canvas');
      pageCanvas.width = viewport.width;
      pageCanvas.height = viewport.height;
      const pctx = pageCanvas.getContext('2d');
      pctx.fillStyle = '#ffffff';
      pctx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
      await page.render({ canvasContext: pctx, viewport }).promise;
      const pageImg = await loadImageFromDataUrl(pageCanvas.toDataURL('image/png'));
      const bubbles = state.pageBubbles[p] || [];
      const redactions = state.pageRedactions[p] || [];
      const texts = state.pageTexts[p] || [];
      const dispW = state.drawing.baseW || pageCanvas.width;
      const dispH = state.drawing.baseH || pageCanvas.height;
      const { canvas } = await renderAnnotatedCanvas({
        imgEl: pageImg, srcW: pageCanvas.width, srcH: pageCanvas.height,
        dispW, dispH, bubbles, redactions, texts,
        rotation: originalRotation, prefix: state.prefix
      });
      const rot = ((originalRotation % 360) + 360) % 360;
      const isQuarter = (Math.abs(rot - 90) < 1 || Math.abs(rot - 270) < 1);
      const baseViewport = page.getViewport({ scale: 1 });
      const pageWPt = isQuarter ? baseViewport.height : baseViewport.width;
      const pageHPt = isQuarter ? baseViewport.width : baseViewport.height;
      const orientation = pageWPt >= pageHPt ? 'l' : 'p';
      if (!pdfDoc) {
        pdfDoc = new jsPDF({ orientation, unit: 'pt', format: [pageWPt, pageHPt], compress: true });
      } else {
        pdfDoc.addPage([pageWPt, pageHPt], orientation);
      }
      pdfDoc.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, pageWPt, pageHPt, undefined, 'FAST');
    }
    const allBubbles = [];
    for (let p = 1; p <= totalPages; p++) {
      (state.pageBubbles[p] || []).forEach(b => allBubbles.push({ page: p, b }));
    }
    if (allBubbles.length > 0) appendPartsListPage(pdfDoc, allBubbles, true);
    pdfDoc.save('drawing-annotated.pdf');
    hideProgress();
  }

  // Append a tabular parts list page using jsPDF's native text/line drawing.
  // Crisp vector text — no rasterization, fully searchable in the output PDF.
  function appendPartsListPage(pdfDoc, allBubbles, includePageCol) {
    // Landscape A4 to fit the wider table
    pdfDoc.addPage('a4', 'l');
    const pageW = pdfDoc.internal.pageSize.getWidth();
    const pageH = pdfDoc.internal.pageSize.getHeight();
    const margin = 32;

    // Header
    function drawHeader() {
      pdfDoc.setFont('helvetica', 'bold');
      pdfDoc.setFontSize(13);
      pdfDoc.setTextColor(40, 40, 40);
      pdfDoc.text('PARTS LIST / DIMENSION REPORT', margin, margin + 8);
      pdfDoc.setFont('helvetica', 'normal');
      pdfDoc.setFontSize(8);
      pdfDoc.setTextColor(120, 120, 120);
      const critCount = allBubbles.filter(r => r.b.dim && r.b.dim.critical).length;
      const meta = `${allBubbles.length} item${allBubbles.length === 1 ? '' : 's'}` +
                   (critCount ? ` · ${critCount} critical` : '') +
                   ` · Generated ${new Date().toLocaleString()}`;
      pdfDoc.text(meta, margin, margin + 20);
    }
    drawHeader();

    // Column layout — proportional widths summing to 1
    let proportions, labels;
    if (includePageCol) {
      labels =       ['ITEM','PG','NO.','DESCRIPTION','TYPE','DIMENSION','CRIT','NOTES'];
      proportions = [ 0.04, 0.04, 0.06, 0.22,         0.07,  0.20,        0.05,  0.32 ];
    } else {
      labels =       ['ITEM','NO.','DESCRIPTION','TYPE','DIMENSION','CRIT','NOTES'];
      proportions = [ 0.05, 0.07, 0.24,          0.08,  0.22,        0.06,  0.28 ];
    }
    const tableW = pageW - margin * 2;
    let xCursor = margin;
    const cols = labels.map((label, i) => {
      const w = tableW * proportions[i];
      const col = { label, x: xCursor, w };
      xCursor += w;
      return col;
    });

    // Header row
    let y = margin + 38;
    pdfDoc.setDrawColor(180, 180, 180);
    pdfDoc.setLineWidth(0.5);
    pdfDoc.line(margin, y - 12, margin + tableW, y - 12);
    pdfDoc.setFont('helvetica', 'bold');
    pdfDoc.setFontSize(8);
    pdfDoc.setTextColor(80, 80, 80);
    cols.forEach(c => pdfDoc.text(c.label, c.x + 2, y - 2));
    pdfDoc.line(margin, y + 2, margin + tableW, y + 2);
    y += 12;

    // Body rows
    pdfDoc.setFont('helvetica', 'normal');
    pdfDoc.setFontSize(8);

    allBubbles.forEach((row, i) => {
      const b = row.b;
      const d = ensureDim(b);
      const item = String(i + 1);
      const num = state.prefix + b.num;
      const desc = b.label || '—';
      const typeLabel = (d.type || 'linear').toUpperCase();
      const dimStr = formatDim(b) || '—';
      const notes = d.notes || '';

      // Wrap text fields and find row height
      const descIdx = includePageCol ? 3 : 2;
      const dimIdx = includePageCol ? 5 : 4;
      const notesIdx = includePageCol ? 7 : 6;
      const wrappedDesc = pdfDoc.splitTextToSize(desc, cols[descIdx].w - 4);
      const wrappedDim = pdfDoc.splitTextToSize(dimStr, cols[dimIdx].w - 4);
      const wrappedNotes = pdfDoc.splitTextToSize(notes || '—', cols[notesIdx].w - 4);
      const lineCount = Math.max(wrappedDesc.length, wrappedDim.length, wrappedNotes.length, 1);
      const rowH = Math.max(11, lineCount * 9 + 2);

      // Page-break check
      if (y + rowH > pageH - margin) {
        pdfDoc.addPage('a4', 'l');
        drawHeader();
        y = margin + 38;
        pdfDoc.setDrawColor(180, 180, 180);
        pdfDoc.line(margin, y - 12, margin + tableW, y - 12);
        pdfDoc.setFont('helvetica', 'bold');
        pdfDoc.setFontSize(8);
        pdfDoc.setTextColor(80, 80, 80);
        cols.forEach(c => pdfDoc.text(c.label, c.x + 2, y - 2));
        pdfDoc.line(margin, y + 2, margin + tableW, y + 2);
        y += 12;
        pdfDoc.setFont('helvetica', 'normal');
        pdfDoc.setFontSize(8);
      }

      // Critical-row tinted background
      if (d.critical) {
        pdfDoc.setFillColor(255, 240, 232);
        pdfDoc.rect(margin, y - 8, tableW, rowH, 'F');
      }

      pdfDoc.setTextColor(40, 40, 40);
      let ci = 0;
      pdfDoc.text(item, cols[ci++].x + 2, y);
      if (includePageCol) pdfDoc.text(String(row.page), cols[ci++].x + 2, y);
      pdfDoc.setFont('helvetica', 'bold');
      pdfDoc.setTextColor(180, 110, 0);
      pdfDoc.text(num, cols[ci++].x + 2, y);
      pdfDoc.setFont('helvetica', 'normal');
      pdfDoc.setTextColor(40, 40, 40);
      pdfDoc.text(wrappedDesc, cols[ci++].x + 2, y);
      pdfDoc.setTextColor(120, 120, 120);
      pdfDoc.text(typeLabel, cols[ci++].x + 2, y);
      pdfDoc.setFont('helvetica', 'bold');
      pdfDoc.setTextColor(40, 40, 40);
      pdfDoc.text(wrappedDim, cols[ci++].x + 2, y);
      pdfDoc.setFont('helvetica', 'normal');
      if (d.critical) {
        pdfDoc.setTextColor(200, 50, 50);
        pdfDoc.text('●', cols[ci++].x + 2, y);
      } else {
        ci++;
      }
      pdfDoc.setTextColor(80, 80, 80);
      pdfDoc.text(wrappedNotes, cols[ci++].x + 2, y);

      y += rowH;
      pdfDoc.setDrawColor(230, 230, 230);
      pdfDoc.line(margin, y - 4, margin + tableW, y - 4);
    });
  }

  // ============ EXPORT PNG ============
  $('btn-export-png').addEventListener('click', async () => {
    if (!state.drawing.loaded) { alert('Load a drawing first.'); return; }
    try {
      const dispW = drawingImg.offsetWidth;
      const dispH = drawingImg.offsetHeight;
      const { canvas } = await renderAnnotatedCanvas({
        imgEl: drawingImg,
        srcW: state.drawing.w,
        srcH: state.drawing.h,
        dispW, dispH,
        bubbles: state.bubbles,
        redactions: state.redactions,
        texts: state.texts,
        rotation: state.rotation,
        prefix: state.prefix
      });
      canvas.toBlob(blob => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        const suffix = state.drawing.type === 'pdf' ? '-page' + state.pdf.page : '';
        a.download = 'drawing-annotated' + suffix + '.png';
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
      }, 'image/png');
    } catch (err) {
      console.error(err);
      alert('PNG export failed: ' + (err.message || 'unknown error'));
    }
  });

  // ============ INITIAL ============
  render();
  renderParts();
  updateFooter();

  // ============================================================
  // TEMPLATE-MODE INTEGRATION
  //
  // When the inspection template editor launches the bubble tool via
  // /tools/bubble_tool.php?template_session=<token>, the PHP side emits
  // window.MAGDYN_TPL_BUBBLE = { token, drawing_url, return_url,
  //                              csrf_token, mode:'template' }
  //
  // In that case we:
  //   1. Hide the standard export buttons (CSV/PNG/PDF) and show a
  //      single "Save to template" button.
  //   2. Auto-preload the staged drawing (PDF or image).
  //   3. On Save, build the annotated PDF bytes and POST them to
  //      return_url as JSON. Follow the returned redirect URL.
  // ============================================================
  (function () {
    var TPL = window.MAGDYN_TPL_BUBBLE;
    if (!TPL || TPL.mode !== 'template') {
      // Not in template mode. Log just in case the user expected to be
      // (helps diagnose "I clicked the launch button but no save button").
      if (window.location.search.indexOf('template_session=') >= 0) {
        console.warn('[bubble-template-mode] URL has template_session but'
          + ' window.MAGDYN_TPL_BUBBLE is not set. This usually means the'
          + ' PHP-side emitter at the top of bubble_tool.php didn\'t run —'
          + ' check for a PHP error or that csrf_token() is available.');
      }
      return;
    }
    console.log('[bubble-template-mode] active. token=' + TPL.token.slice(0, 8) + '…');

    // ---- Swap toolbar buttons ----
    // Hide CSV/PNG/PDF/Clear since the template flow has a single output.
    // Keep Auto-bubble + Pages, those are useful in template mode too.
    ['btn-export-csv', 'btn-export-png', 'btn-export-pdf', 'btn-clear']
      .forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
      });

    // Inject a "Cancel" + "Save to template" button pair. We want
    // these buttons ALWAYS visible — even when a large PDF is rendered
    // and would otherwise scroll the head-actions strip out of view —
    // so the user can never get stuck without a way out.
    //
    // Strategy: try to attach to .head-actions (preferred — fits the
    // visual rhythm of the rest of the toolbar). If that's not visible
    // in the viewport, or if a PDF render later pushes it out, we ALSO
    // mount a floating fallback pair anchored to the top-right corner.
    // (The two pairs are linked — clicking either Cancel cancels.)

    function makeCancelBtn() {
      var b = document.createElement('button');
      b.className = 'btn btn-ghost';
      b.id = 'btn-cancel-template';
      b.type = 'button';
      b.textContent = '← Cancel';
      b.title = 'Return to the inspection template editor without saving bubbles';
      return b;
    }
    function makeSaveBtn() {
      var b = document.createElement('button');
      b.className = 'btn btn-primary';
      b.id = 'btn-save-template';
      b.type = 'button';
      b.textContent = 'Save to template →';
      b.title = 'Save bubbles back to the inspection template editor';
      return b;
    }

    var cancelBtn = makeCancelBtn();
    var saveBtn   = makeSaveBtn();

    // Mount inline in .head-actions, where Pages / Auto-bubble live. This
    // keeps them in the natural toolbar rhythm (no floating overlay that
    // would otherwise hide other buttons or sit awkwardly on top of the
    // toolbar). The page-head is sticky in the CSS, so these stay in
    // view even when the user scrolls a tall PDF.
    var headActions = document.querySelector('.head-actions');
    if (headActions) {
      // Defensive: remove any prior copies that a hot-reload or stale
      // SPA swap left behind, so we never stack duplicate pairs.
      Array.prototype.slice.call(headActions.querySelectorAll('#btn-cancel-template, #btn-save-template'))
        .forEach(function (el) { el.parentNode.removeChild(el); });
      headActions.appendChild(cancelBtn);
      headActions.appendChild(saveBtn);
      console.log('[bubble-template-mode] cancel+save buttons attached to .head-actions');
    } else {
      // Fallback if for some reason .head-actions is missing — just dock
      // them top-right so the user is never stranded.
      var fallbackWrap = document.createElement('div');
      fallbackWrap.id = 'tpl-mode-float-actions';
      fallbackWrap.style.cssText =
        'position: fixed; top: 12px; right: 16px; z-index: 99999;'
        + ' display: flex; gap: 8px; align-items: center;'
        + ' padding: 6px; border-radius: 8px;'
        + ' background: rgba(255,255,255,0.92); backdrop-filter: blur(4px);'
        + ' box-shadow: 0 4px 16px rgba(0,0,0,0.18);';
      fallbackWrap.appendChild(cancelBtn);
      fallbackWrap.appendChild(saveBtn);
      document.body.appendChild(fallbackWrap);
      console.warn('[bubble-template-mode] .head-actions not found, using floating fallback');
    }
    // Aliases so the rest of the file (which uses floatCancel/floatSave
    // names from an earlier iteration) keeps working unchanged.
    var floatCancel = cancelBtn;
    var floatSave   = saveBtn;

    // Also: in the sidebar, swap the "Upload drawing" affordance text
    // so the user understands the drawing is pre-loaded.
    var emptyState = document.getElementById('empty-state');
    if (emptyState) {
      var big = emptyState.querySelector('.big');
      var sub = emptyState.querySelector('.sub');
      if (big) big.textContent = 'Loading drawing from template editor…';
      if (sub) sub.textContent = 'The staged drawing will appear in a moment.';
    }

    // ---- Preload the staged drawing ----
    (async function preload() {
      try {
        var resp = await fetch(TPL.drawing_url, { credentials: 'same-origin' });
        if (!resp.ok) {
          throw new Error('Drawing fetch HTTP ' + resp.status);
        }
        var blob = await resp.blob();
        // Construct a File so loadFile's instanceof checks (if any) work.
        // The Content-Type from the server tells us the right name suffix.
        var ct = blob.type || resp.headers.get('content-type') || '';
        var ext = '.bin';
        if (ct.indexOf('pdf') >= 0)        ext = '.pdf';
        else if (ct.indexOf('png') >= 0)   ext = '.png';
        else if (ct.indexOf('jpeg') >= 0)  ext = '.jpg';
        else if (ct.indexOf('gif') >= 0)   ext = '.gif';
        else if (ct.indexOf('webp') >= 0)  ext = '.webp';
        var file;
        try {
          file = new File([blob], 'staged-drawing' + ext, { type: ct });
        } catch (e) {
          // Older Safari doesn't support File constructor — fall back to
          // a Blob with a .name property tacked on (loadFile reads .name
          // and .type only).
          file = blob;
          try { Object.defineProperty(file, 'name', { value: 'staged-drawing' + ext }); } catch (_) {}
        }
        loadFile(file);

        // After the drawing finishes loading, apply the starting bubble
        // number handed off by the template editor. This prevents
        // duplicate numbers across multiple round-trips ("save bubbles
        // → upload another drawing → place more"). We poll because
        // loadFile is async — it returns before loadPdf/loadImage has
        // finished setting state.drawing.loaded.
        var seedAttempts = 0;
        var seedTimer = setInterval(function () {
          seedAttempts++;
          if (state.drawing.loaded || seedAttempts > 40) {
            clearInterval(seedTimer);
            if (!state.drawing.loaded) {
              console.warn('[template-mode] drawing never loaded; skipped numbering hand-off');
              return;
            }
            applyStartingNumber();
          }
        }, 50);

      } catch (err) {
        console.error('[template-mode] preload failed:', err);
        if (emptyState) {
          var big = emptyState.querySelector('.big');
          var sub = emptyState.querySelector('.sub');
          if (big) big.textContent = 'Failed to load staged drawing';
          if (sub) sub.textContent = err.message + ' — try re-uploading from the template editor.';
        }
      }
    })();

    // Apply the starting bubble number sent from the template editor.
    // Idempotent — safe to call multiple times.
    var _appliedStartingNum = false;
    function applyStartingNumber() {
      if (_appliedStartingNum) return;
      _appliedStartingNum = true;
      var startNum = parseInt(TPL.starting_number, 10) || 1;
      if (startNum > 1) {
        state.nextId = startNum;
        $('next-number').value = startNum;
        console.log('[template-mode] starting bubble number = ' + startNum);
      }
    }

    // ---- Build annotated PDF bytes (returns Uint8Array) ----
    // Mirrors btn-export-pdf logic but captures bytes instead of downloading.
    // PDF case: uses pdf-lib like the standard export. Image case: uses jsPDF
    // arraybuffer output. Either way, output is a single PDF.
    async function buildAnnotatedPdfBytes() {
      if (!state.drawing.loaded) {
        throw new Error('No drawing loaded');
      }
      syncPage();

      if (state.drawing.type === 'pdf' && window.PDFLib && state.pdf.originalBytes) {
        // Reuse the same pdf-lib annotation pipeline by calling the
        // existing handler-internal path. To avoid duplicating ~300
        // lines of pdf-lib drawing code, we trigger the existing
        // export-pdf flow but intercept its download.
        //
        // We do this by temporarily monkey-patching Blob URL creation
        // so a.click() doesn't download; instead we capture the bytes.
        return await captureExportedPdfBytes();
      }

      // Image case: render the annotated canvas, then wrap into a
      // single-page PDF via jsPDF.
      if (!window.jspdf || !window.jspdf.jsPDF) {
        throw new Error('jsPDF unavailable');
      }
      var dispW = drawingImg.offsetWidth;
      var dispH = drawingImg.offsetHeight;
      var rc = await renderAnnotatedCanvas({
        imgEl: drawingImg,
        srcW: state.drawing.w,
        srcH: state.drawing.h,
        dispW: dispW, dispH: dispH,
        bubbles: state.bubbles,
        redactions: state.redactions,
        texts: state.texts,
        rotation: state.rotation,
        prefix: state.prefix
      });
      var png = rc.canvas.toDataURL('image/png');
      var ratio = rc.canvas.width / rc.canvas.height;
      var orientation = ratio >= 1 ? 'landscape' : 'portrait';
      var pdf = new window.jspdf.jsPDF({
        orientation: orientation,
        unit: 'pt',
        format: [rc.canvas.width, rc.canvas.height]
      });
      pdf.addImage(png, 'PNG', 0, 0, rc.canvas.width, rc.canvas.height);
      var ab = pdf.output('arraybuffer');
      return new Uint8Array(ab);
    }

    // Hijack the standard btn-export-pdf download to capture bytes.
    // We do this by overriding URL.createObjectURL just long enough to
    // grab the Blob, then restoring the original. The legacy handler
    // also creates `<a>` and calls click; we intercept that too so no
    // real download is triggered.
    async function captureExportedPdfBytes() {
      var origCreateUrl = URL.createObjectURL;
      var captured = null;
      var origAnchorClick = HTMLAnchorElement.prototype.click;
      try {
        URL.createObjectURL = function (blob) {
          // Only intercept the PDF blob — there may be other createObjectURL
          // calls (e.g. the page manager's PNG thumbnails) in flight.
          if (blob && blob.type === 'application/pdf' && !captured) {
            captured = blob;
          }
          return origCreateUrl.call(URL, blob);
        };
        HTMLAnchorElement.prototype.click = function () {
          // If this anchor is the export's download trigger, swallow it.
          if (this.download && /\.pdf$/i.test(this.download)) {
            return;
          }
          return origAnchorClick.apply(this, arguments);
        };
        // Trigger the existing export-pdf handler. We dispatch a click
        // even though the button is display:none — the handler runs
        // because we never disabled it, only hid it. Poll every 50ms;
        // capped at 30s. The previous 60s/100ms made waiting for the
        // saved-template flow feel sluggish.
        var btn = document.getElementById('btn-export-pdf');
        if (!btn) throw new Error('export handler missing');
        btn.click();
        var deadline = Date.now() + 30000;
        while (!captured && Date.now() < deadline) {
          await new Promise(function (r) { setTimeout(r, 50); });
        }
        if (!captured) throw new Error('Annotated PDF generation timed out');
        var ab = await captured.arrayBuffer();
        return new Uint8Array(ab);
      } finally {
        URL.createObjectURL = origCreateUrl;
        HTMLAnchorElement.prototype.click = origAnchorClick;
      }
    }

    // ---- Collect bubbles in the wire format ----
    // The PHP return endpoint expects an array of:
    //   { id, page, ax, ay, dim: { nominal, unit, tolPlus, tolMinus,
    //                              gdtSym, gdtTol, type, critical } }
    // The bubble tool's state.pageBubbles already stores per-page lists
    // in roughly this shape — we just need to flatten and ensure the
    // dim object is present even when the user didn't fill anything in.
    function collectBubblesForReturn() {
      // Sync current page into pageBubbles map first.
      syncPage();
      var out = [];
      // The bubble tool internally stores each bubble with:
      //   b.id    — internal UUID-ish string ('b_<ts>_<rand>'), NOT
      //             the displayed number. Used for DOM/event tracking
      //             only; never user-visible.
      //   b.num   — the bubble number drawn on the page (e.g. 1, 2,
      //             3...). This is what users mean by "bubble number"
      //             and what should land in template_items.bubble_no.
      //   b.label — optional free-text secondary label (rarely set).
      // We send `num` as the canonical bubble number, `label` as a
      // secondary text label if present, and keep `id` only for
      // debugging.
      if (state.drawing.type === 'pdf') {
        Object.keys(state.pageBubbles).forEach(function (p) {
          var pageNum = parseInt(p, 10);
          (state.pageBubbles[p] || []).forEach(function (b) {
            // Compute grid cell on the fly if not stamped at placement
            // time (manual bubbles created before grid mode existed).
            var cell = b.gridCell || '';
            if (!cell && state.drawing.baseW && state.drawing.baseH
                && typeof gridCellFor === 'function') {
              cell = gridCellFor(b.x, b.y,
                                 state.drawing.baseW, state.drawing.baseH,
                                 state.gridRows || 4, state.gridCols || 4) || '';
            }
            out.push({
              num:        b.num,
              label:      b.label || '',
              page:       pageNum,
              ax:         b.ax,
              ay:         b.ay,
              grid_cell:  cell,
              dim:        b.dim || {},
              _id:        b.id  // diagnostic only
            });
          });
        });
        // Order by page then by num (numeric where possible) so the
        // template items come out in a sensible reading order.
        out.sort(function (a, b) {
          if (a.page !== b.page) return a.page - b.page;
          var ai = parseInt(a.num, 10), bi = parseInt(b.num, 10);
          if (!isNaN(ai) && !isNaN(bi)) return ai - bi;
          return String(a.num).localeCompare(String(b.num));
        });
      } else {
        // Image (single page) — page is always 1
        state.bubbles.forEach(function (b) {
          var cell = b.gridCell || '';
          if (!cell && state.drawing.w && state.drawing.h
              && typeof gridCellFor === 'function') {
            cell = gridCellFor(b.x, b.y,
                               state.drawing.w, state.drawing.h,
                               state.gridRows || 4, state.gridCols || 4) || '';
          }
          out.push({
            num:        b.num,
            label:      b.label || '',
            page:       1,
            ax:         b.ax,
            ay:         b.ay,
            grid_cell:  cell,
            dim:        b.dim || {},
            _id:        b.id
          });
        });
      }
      return out;
    }

    // ---- Cancel click ----
    // Returns the user to the template editor without applying any
    // bubbles. If they've placed bubbles, confirm before discarding —
    // it's easy to hit Cancel by accident on a tablet/trackpad.
    function onCancelClick() {
      // Snapshot any work-in-progress so the confirm message is honest
      // about what gets thrown away.
      syncPage();
      var totalBubbles = 0;
      if (state.drawing.type === 'pdf') {
        Object.keys(state.pageBubbles).forEach(function (p) {
          totalBubbles += (state.pageBubbles[p] || []).length;
        });
      } else {
        totalBubbles = state.bubbles.length;
      }
      if (totalBubbles > 0) {
        if (!confirm('Discard ' + totalBubbles + ' bubble'
            + (totalBubbles === 1 ? '' : 's')
            + ' and return to the template editor?')) {
          return;
        }
      }
      var target = TPL.cancel_url || '';
      if (!target) {
        // Defensive fallback: hard-coded path back to templates list.
        target = (window.MAGDYN_BASE || '') + '/inspection.php?action=templates';
      }
      if (window.top && window.top !== window) {
        window.top.location.href = target;
      } else {
        window.location.href = target;
      }
    }

    cancelBtn.addEventListener('click', onCancelClick);
    floatCancel.addEventListener('click', onCancelClick);

    // ---- Save-to-template click ----
    async function onSaveClick() {
      if (!state.drawing.loaded) {
        alert('Load a drawing first (or wait for the staged drawing to finish loading).');
        return;
      }
      var bubbles = collectBubblesForReturn();
      if (bubbles.length === 0) {
        if (!confirm('No bubbles placed yet. Return to the template with zero items?')) return;
      }

      // Disable both copies of the save button to prevent double-submit.
      [saveBtn, floatSave].forEach(function (b) {
        b.disabled = true;
        b.textContent = 'Saving…';
      });

      var pdfB64 = '';
      try {
        var bytes = await buildAnnotatedPdfBytes();
        // Convert to base64. For larger PDFs we chunk to avoid a
        // RangeError from String.fromCharCode.apply on big arrays.
        var CHUNK = 0x8000;
        var s = '';
        for (var i = 0; i < bytes.length; i += CHUNK) {
          s += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
        }
        pdfB64 = btoa(s);
      } catch (err) {
        console.warn('[template-mode] PDF build failed; continuing without annotated attachment:', err);
        // We still post the bubbles — the attachment is a nice-to-have.
      }

      try {
        var resp = await fetch(TPL.return_url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': TPL.csrf_token
          },
          body: JSON.stringify({
            token: TPL.token,
            bubbles: bubbles,
            annotated_pdf_b64: pdfB64
          })
        });
        var data = await resp.json();
        if (!resp.ok || !data.ok) {
          throw new Error(data.error || ('HTTP ' + resp.status));
        }
        // Follow the redirect. We use top-window navigation because the
        // tool runs in an iframe (embed mode).
        if (window.top && window.top !== window) {
          window.top.location.href = data.redirect;
        } else {
          window.location.href = data.redirect;
        }
      } catch (err) {
        console.error('[template-mode] save failed:', err);
        alert('Save to template failed: ' + (err.message || 'unknown'));
        [saveBtn, floatSave].forEach(function (b) {
          b.disabled = false;
          b.textContent = 'Save to template →';
        });
      }
    }

    saveBtn.addEventListener('click', onSaveClick);
    floatSave.addEventListener('click', onSaveClick);
  })();
})();
</script>

<?php include 'includes/footer.php'; ?>
