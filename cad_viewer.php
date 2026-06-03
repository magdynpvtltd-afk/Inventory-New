<?php
/**
 * MagDyn — CAD Viewer
 * Created: 20260516_211500_IST
 *
 * Standalone CAD/3D file viewer designed to be embedded in an iframe
 * (or opened as a popup window). Supports DXF, CGM (clear text), STL,
 * OBJ, STEP, IGES, and 3DS files.
 *
 * Auto-load mode:
 *   /cad_viewer.php?att_id=N
 *   The viewer fetches /note_attach.php?id=N and feeds the resulting
 *   blob into the same handler used by drag-and-drop. The fetch is
 *   browser-credentialed so the existing note_attach.php permission
 *   gate applies — if the user can't view the attachment, the fetch
 *   returns 403 and the viewer shows an error.
 *
 * Without ?att_id, the page presents the normal drop UI for ad-hoc use.
 *
 * Self-contained styling — no MagDyn header.php / footer.php / sidebar.
 * Iframes shouldn't carry the parent app's chrome. require_login() still
 * gates access so unauthenticated users can't load the page.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$attId = (int)input('att_id', 0);
// Which attachment endpoint to fetch from. 'note' → /note_attach.php
// (running notes), 'inspection' → /inspection_attach.php. Defaults to
// note for backward compatibility.
$src = (string)input('src', 'note');
if (!in_array($src, ['note', 'inspection'], true)) $src = 'note';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CAD Viewer</title>
  <style>
    :root {
      --primary: #1e3a8a;
      --primary-light: #eef2fb;
      --text: #1e293b;
      --text-muted: #64748b;
      --text-light: #94a3b8;
      --surface: #ffffff;
      --surface-alt: #f8fafc;
      --border: #e2e8f0;
      --border-strong: #cbd5e1;
      --radius: 6px;
      --radius-lg: 10px;
      --shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.04);
      --info: #1e40af;
      --info-bg: #eef2fb;
      --warn: #92400e;
      --warn-bg: #fef3c7;
      --danger: #991b1b;
      --danger-bg: #fef2f2;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      font-size: 14px;
      color: var(--text);
      background: var(--surface-alt);
      padding: 16px;
      overflow-y: auto;
    }
    .wrap { max-width: 1180px; margin: 0 auto; }
    .muted { color: var(--text-muted); }
    .btn {
      display: inline-flex; align-items: center;
      padding: 6px 12px;
      font-size: 12.5px;
      font-weight: 500;
      border: 1px solid var(--border-strong);
      background: var(--surface);
      color: var(--text);
      border-radius: var(--radius);
      cursor: pointer;
      text-decoration: none;
      transition: background 0.1s, border-color 0.1s;
    }
    .btn:hover { background: var(--surface-alt); border-color: var(--primary); }
    .btn-sm { font-size: 12px; padding: 5px 10px; }
    .btn-ghost { background: transparent; border-color: transparent; }
    .btn-ghost:hover { background: var(--surface-alt); }
    .hidden { display: none !important; }

    .drop {
      background: var(--surface);
      border: 2px dashed var(--border-strong);
      border-radius: var(--radius-lg);
      padding: 64px 32px;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
      box-shadow: var(--shadow);
    }
    .drop:hover { border-color: var(--primary); }
    .drop.drag { border-color: var(--primary); background: var(--primary-light); }
    .drop .icon { font-size: 44px; margin-bottom: 12px; opacity: 0.55; line-height: 1; }
    .drop h2 { margin: 0 0 6px; }
    .drop p { color: var(--text-muted); margin: 4px 0; font-size: 13.5px; }
    .drop .hint { font-size: 12px; color: var(--text-light); margin-top: 14px; }

    .stage {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .toolbar {
      display: flex; align-items: center; gap: 8px;
      padding: 10px 14px;
      border-bottom: 1px solid var(--border);
      background: var(--surface-alt);
      flex-wrap: wrap;
    }
    .toolbar .badge {
      display: inline-block;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      background: var(--info-bg);
      color: var(--info);
    }
    .toolbar .name {
      flex: 1;
      color: var(--text);
      font-size: 13.5px;
      font-weight: 500;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      min-width: 0;
    }
    .toolbar .btn.active {
      background: var(--primary); color: white; border-color: var(--primary);
    }
    .canvas-wrap { position: relative; background: var(--surface); }
    canvas { display: block; width: 100%; height: 600px; cursor: grab; }
    canvas.measuring { cursor: crosshair; }
    canvas.dragging { cursor: grabbing; }
    .status {
      position: absolute;
      bottom: 0; left: 0; right: 0;
      padding: 6px 14px;
      background: rgba(255, 255, 255, 0.92);
      border-top: 1px solid var(--border);
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 11.5px;
      color: var(--text-muted);
      display: flex;
      justify-content: space-between;
      gap: 16px;
      pointer-events: none;
    }
    .status span { display: inline-block; }
    .status #hint { color: var(--text-light); }
    .msg {
      margin-top: 18px;
      padding: 14px 18px;
      border-radius: var(--radius);
      border-left: 3px solid;
      font-size: 13.5px;
    }
    .msg .title { font-weight: 600; margin-bottom: 4px; font-size: 14px; }
    .msg ul { margin: 8px 0 4px 22px; padding: 0; line-height: 1.7; }
    .msg a { text-decoration: underline; }
    .msg.info    { background: var(--info-bg);    color: var(--info);    border-color: var(--info); }
    .msg.warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn); }
    .msg.danger  { background: var(--danger-bg);  color: var(--danger);  border-color: var(--danger); }
    header.page-head { margin-bottom: 20px; display: block; }
    header.page-head h1 { margin: 0 0 4px; font-size: 20px; }
    header.page-head .tags { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
    header.page-head .tag {
      font-size: 10px;
      padding: 3px 9px;
      background: var(--surface-alt);
      color: var(--text-muted);
      border: 1px solid var(--border);
      border-radius: 4px;
      font-weight: 500;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    footer { margin-top: 24px; font-size: 12px; color: var(--text-light); text-align: center; }
  </style>
</head>
<body>

<div class="wrap">

  <header class="page-head">
    <h1>CAD Drawing Viewer</h1>
    <p class="muted">Open DXF, STL, OBJ, STEP, IGES, and other formats in your browser.</p>
    <div class="tags">
      <span class="tag">2D · DXF</span>
      <span class="tag">3D · STL / OBJ / STEP / IGES</span>
    </div>
  </header>

  <div id="drop" class="drop">
    <div class="icon">📐</div>
    <h2>Drop a drawing here</h2>
    <p>DXF, CGM, STL, OBJ, STEP, IGES — or drop a DWG, binary CGM, or JT and I'll explain how to convert it</p>
    <div class="hint">Click to browse if you'd rather pick from a dialog.</div>
    <input type="file" id="file" accept=".dxf,.dwg,.cgm,.cgmtx,.txt,.stl,.obj,.step,.stp,.iges,.igs,.jt,.3ds" hidden>
  </div>

  <div id="stage" class="stage hidden">
    <div class="toolbar">
      <span id="format" class="badge">DXF</span>
      <span id="name" class="name"></span>
      <button id="wireframe" class="btn btn-sm hidden" title="Toggle wireframe rendering">Wireframe</button>
      <button id="measure" class="btn btn-sm" title="Click two points to measure distance">Measure</button>
      <button id="clearMeasure" class="btn btn-sm hidden" title="Clear all measurements">Clear</button>
      <button id="fit" class="btn btn-sm">Fit</button>
      <button id="exportSvg" class="btn btn-sm" title="Download as SVG">Export SVG</button>
      <button id="newFile" class="btn btn-sm btn-ghost">New file</button>
    </div>
    <div class="canvas-wrap">
      <canvas id="canvas"></canvas>
      <canvas id="canvas3d" class="hidden"></canvas>
      <div class="status">
        <span id="meta"></span>
        <span id="coords"></span>
        <span id="hint">Drag to pan · Scroll to zoom</span>
      </div>
    </div>
  </div>

  <div id="msg" class="msg hidden"></div>

  <footer>CAD viewer · MagDyn</footer>
</div>

<script>
// Auto-load configuration, injected by PHP. The viewer JS module reads
// this on startup. att_id > 0 means fetch /note_attach.php or
// /inspection_attach.php depending on src, and feed the response to
// handleFile() instead of waiting for a drop.
window.CAD_VIEWER_AUTOLOAD = {
  att_id: <?= (int)$attId ?>,
  src:    <?= json_encode($src) ?>,
  base: <?= json_encode(rtrim(parse_url((string)url('/'), PHP_URL_PATH) ?: '', '/')) ?>,
};
</script>

<script type="module">
import DxfParser from 'https://esm.sh/dxf-parser@1.1.2';
import * as THREE from 'https://esm.sh/three@0.160.0';
import { OrbitControls } from 'https://esm.sh/three@0.160.0/examples/jsm/controls/OrbitControls.js';
import { TDSLoader } from 'https://esm.sh/three@0.160.0/examples/jsm/loaders/TDSLoader.js';

/* ============================================================
   The viewer body below is taken verbatim from the standalone
   reference viewer (see project notes). DOM ids are unchanged.
   At the very bottom, an auto-load shim reads
   window.CAD_VIEWER_AUTOLOAD and, if att_id is set, fetches the
   attachment and feeds the blob into handleFile().
   ============================================================ */


/* -------------------------------------------------------------
   DOM + state
   ------------------------------------------------------------- */

const $ = id => document.getElementById(id);
const drop = $('drop'), file = $('file'), stage = $('stage');
const canvas = $('canvas'), canvas3d = $('canvas3d');
const nameOut = $('name'), formatOut = $('format'), meta = $('meta'), msg = $('msg');
const hintEl = $('hint'), coordsEl = $('coords');
const wireframeBtn = $('wireframe'), exportSvgBtn = $('exportSvg');
const measureBtn = $('measure'), clearMeasureBtn = $('clearMeasure');
const ctx = canvas.getContext('2d');

let view = { scale: 1, tx: 0, ty: 0 };
let entities = [];     // normalized 2D entity list
let bbox = { minX: -10, minY: -10, maxX: 10, maxY: 10 };
let currentName = '';  // current filename, used for SVG export
let mode = null;       // null | '2d' | '3d' — which renderer is active

// Measurement + cursor state (2D only). Measurements live in world coords
// so they survive pan/zoom; rulers re-tick based on current scale.
let measureMode = false;
let measurePending = null;       // { x, y } first picked point during a measurement
let measurements = [];           // [{ p1, p2 }] completed linear dimensions
let cursorScreen = null;         // pixel coords inside canvas, or null when off-canvas
let cursorWorld = null;          // world coords of cursor
const RULER_PX = 22;             // ruler thickness in screen pixels

// Three.js scene state, initialised lazily on first 3D file
let renderer3d = null, scene3d = null, camera3d = null, controls3d = null;
let mesh3d = null, wireOverlay = null;
let wireframeOn = false;
let anim3dRunning = false;

/* -------------------------------------------------------------
   File handling + format detection
   ------------------------------------------------------------- */

drop.addEventListener('click', () => file.click());
file.addEventListener('change', e => { if (e.target.files[0]) handleFile(e.target.files[0]); });
['dragenter','dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('drag'); }));
['dragleave','drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('drag'); }));
drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]); });

$('newFile').addEventListener('click', resetUI);
$('fit').addEventListener('click', fitView);
$('exportSvg').addEventListener('click', exportSvg);

function resetUI() {
  stage.classList.add('hidden');
  msg.classList.add('hidden');
  drop.classList.remove('hidden');
  file.value = '';
  entities = [];
  measurements = [];
  measurePending = null;
  if (measureMode) toggleMeasureMode();
  dispose3d();
  mode = null;
}

function handleFile(f) {
  currentName = f.name;
  nameOut.textContent = f.name;
  // Need a larger head than 32 bytes for 3D detection (STL binary needs the
  // 80-byte header + 4-byte triangle count to verify size; STEP/IGES headers
  // can be a couple hundred bytes in)
  const headReader = new FileReader();
  headReader.onload = e => {
    const headBytes = new Uint8Array(e.target.result);
    const headText = String.fromCharCode.apply(null, headBytes);
    const format = detectFormat(headBytes, headText, f.name, f.size);

    // Formats we can only detect — show conversion guidance and stop
    if (format === 'dwg') {
      showDwgMessage(headText.match(/AC\d{4}/)?.[0] || 'unknown', f.name); return;
    }
    if (format === 'cgm-binary') { showCgmBinaryMessage(f.name); return; }
    if (format === 'step' || format === 'iges') {
      // STEP/IGES need the OpenCASCADE WASM kernel; reads as binary
      const kind = format === 'step' ? 'STEP' : 'IGES';
      const reader = new FileReader();
      reader.onload = ev => loadOcctFile(ev.target.result, f.name, kind);
      reader.onerror = () => showError('Could not read this file.');
      reader.readAsArrayBuffer(f);
      return;
    }
    if (format === 'jt')   { showProprietary3dMessage(f.name, 'JT');   return; }

    // Binary 3D formats — read as ArrayBuffer
    if (format === 'stl-binary') {
      const reader = new FileReader();
      reader.onload = ev => {
        try { loadStl(ev.target.result, /*binary=*/true, f.name); }
        catch (err) { showError('Could not parse STL: ' + (err.message || err)); }
      };
      reader.onerror = () => showError('Could not read this file.');
      reader.readAsArrayBuffer(f);
      return;
    }
    if (format === '3ds') {
      const reader = new FileReader();
      reader.onload = ev => {
        try { load3ds(ev.target.result, f.name); }
        catch (err) { showError('Could not parse 3DS: ' + (err.message || err)); }
      };
      reader.onerror = () => showError('Could not read this file.');
      reader.readAsArrayBuffer(f);
      return;
    }

    // Text-based formats (2D + 3D) — read whole file as text
    const reader = new FileReader();
    reader.onload = ev => {
      try {
        if (format === 'cgm-clear')      loadCgm(ev.target.result, f.name);
        else if (format === 'stl-ascii') loadStl(ev.target.result, /*binary=*/false, f.name);
        else if (format === 'obj')       loadObj(ev.target.result, f.name);
        else                              loadDxf(ev.target.result, f.name);  // default + fallback
      } catch (err) {
        showError('Could not parse this file: ' + (err.message || err));
      }
    };
    reader.onerror = () => showError('Could not read this file.');
    reader.readAsText(f);
  };
  // Read enough head to detect binary STL (84 bytes minimum) and STEP/IGES headers
  headReader.readAsArrayBuffer(f.slice(0, 256));
}

function detectFormat(bytes, text, filename, fileSize) {
  const ext = (filename.match(/\.([^.]+)$/) || [, ''])[1].toLowerCase();

  // DWG: starts with "AC" + 4 digits (version)
  if (/^AC\d{4}/.test(text)) return 'dwg';

  // STEP: ISO-10303 file header
  if (/^ISO-10303/i.test(text) || ext === 'step' || ext === 'stp') return 'step';
  // IGES: signature line ending in "S      1" at column 73, or by extension
  if (ext === 'iges' || ext === 'igs') return 'iges';
  // JT: 80-byte header beginning with "Version X.Y" — Siemens' tessellation format
  if (/^Version \d+\.\d+/.test(text) || ext === 'jt') return 'jt';
  // 3DS: starts with chunk ID 0x4D4D ("MM" — the MAIN3DS chunk header)
  if ((bytes[0] === 0x4D && bytes[1] === 0x4D) || ext === '3ds') return '3ds';

  // STL binary: best signal is "filesize == 80 + 4 + N*50" where N is the
  // little-endian uint32 at offset 80. Plain "starts with solid" can lie —
  // some binary STLs use 'solid' as the header text.
  if (bytes.length >= 84) {
    const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const triCount = dv.getUint32(80, true);
    if (fileSize != null && fileSize === 84 + triCount * 50) return 'stl-binary';
  }
  // STL ASCII: starts with "solid " and contains "facet normal" within the head
  if (/^\s*solid\s/i.test(text) && /facet\s+normal/i.test(text)) return 'stl-ascii';
  // If the extension is .stl but neither check fired, take the extension at face value
  if (ext === 'stl') return fileSize > 84 ? 'stl-binary' : 'stl-ascii';

  // OBJ: usually starts with "# comment" or "v " or "vn " or "o name" / "g name"
  if (ext === 'obj') return 'obj';
  if (/^(\s*#[^\n]*\n\s*)*v\s+[-\d.]/m.test(text)) return 'obj';

  // Clear-text CGM: starts with BEGMF (case-insensitive, possibly preceded by whitespace/comment)
  if (/^\s*(?:%[^%]*%\s*)?BEGMF\b/i.test(text)) return 'cgm-clear';
  // Binary CGM: first byte 0x00, second byte 0x20–0x3F
  // (BEGMF element: class=0, id=1, paramLen 0–31)
  if (bytes[0] === 0x00 && bytes[1] >= 0x20 && bytes[1] <= 0x3F) return 'cgm-binary';
  // Default: try DXF (it's permissive and dxf-parser will throw if it's not)
  return 'dxf';
}

/* -------------------------------------------------------------
   Format-specific loaders
   ------------------------------------------------------------- */

function loadDxf(text, filename) {
  const parser = new DxfParser();
  const dxf = parser.parseSync(text);
  if (!dxf || !dxf.entities || !dxf.entities.length) {
    showError('No drawable entities found in this DXF file.');
    return;
  }
  entities = dxfToNormalized(dxf);
  if (!entities.length) {
    showError('Parsed the DXF, but none of the entities are renderable.');
    return;
  }
  formatOut.textContent = 'DXF';
  showStage(`${entities.length} entities`);
}

function loadCgm(text, filename) {
  const result = parseCgmClear(text);
  if (!result.entities.length) {
    showError('No drawable elements found in this CGM file.');
    return;
  }
  entities = result.entities;
  formatOut.textContent = 'CGM';
  const extra = result.vdcExt ? ` · VDC ${fmt(result.vdcExt.minX)},${fmt(result.vdcExt.minY)} → ${fmt(result.vdcExt.maxX)},${fmt(result.vdcExt.maxY)}` : '';
  showStage(`${entities.length} elements${extra}`);
}

function fmt(n) { return Math.abs(n) >= 1000 ? n.toFixed(0) : n.toFixed(2); }

/* -------------------------------------------------------------
   Messages for unsupported formats
   ------------------------------------------------------------- */

function showDwgMessage(version, filename) {
  drop.classList.add('hidden'); stage.classList.add('hidden'); msg.classList.remove('hidden');
  msg.className = 'msg warn';
  msg.innerHTML =
    `<div class="title">${escapeHtml(filename)} is a DWG file (${version})</div>` +
    `<div>DWG is Autodesk's proprietary binary format — there's no good pure-JavaScript parser, so it can't be opened in the browser without a server-side converter. The fix is quick:</div>` +
    `<ul>` +
      `<li>Use the free <a href="https://www.opendesign.com/guestfiles/oda_file_converter" target="_blank" rel="noopener">ODA File Converter</a> to save your DWG as DXF</li>` +
      `<li>Or open it in any CAD app (AutoCAD, LibreCAD, QCAD, FreeCAD) and export as DXF</li>` +
    `</ul>` +
    `<button type="button" id="back">Try another file</button>`;
  $('back').addEventListener('click', resetUI);
}

function showCgmBinaryMessage(filename) {
  drop.classList.add('hidden'); stage.classList.add('hidden'); msg.classList.remove('hidden');
  msg.className = 'msg warn';
  msg.innerHTML =
    `<div class="title">${escapeHtml(filename)} is a binary CGM file</div>` +
    `<div>CGM has three encodings — clear text, character, and binary. This viewer reads clear text, which is the parseable cousin (like DXF). Binary CGM uses a typed opcode stream that's painful to parse in the browser without a battle-tested library, and there isn't a good one for JavaScript. To open it here, convert it first:</div>` +
    `<ul>` +
      `<li><a href="https://imagemagick.org/" target="_blank" rel="noopener">ImageMagick</a> can convert CGM to SVG/PNG with the right delegate (<code>magick file.cgm out.svg</code>)</li>` +
      `<li><a href="http://ralcgm.sourceforge.net/" target="_blank" rel="noopener">RalCGM</a> is the classic free tool for CGM ↔ clear-text conversion (older but works)</li>` +
      `<li>Most CGM authoring tools (IsoDraw, CorelDraw) can re-export as clear text CGM or SVG</li>` +
    `</ul>` +
    `<button type="button" id="back">Try another file</button>`;
  $('back').addEventListener('click', resetUI);
}

function showProprietary3dMessage(filename, kind) {
  drop.classList.add('hidden'); stage.classList.add('hidden'); msg.classList.remove('hidden');
  msg.className = 'msg warn';

  let blurb, options;
  if (kind === 'STEP') {
    blurb = `STEP (ISO 10303) is the standard interchange format for solid CAD models. The file is a parametric B-Rep description — surfaces, edges, and topology — that needs a geometry kernel like OpenCASCADE to evaluate into renderable triangles. There's no realistic pure-JS implementation of that kernel below ~10MB of WASM, so a server-side or desktop conversion step is needed:`;
    options = [
      `<a href="https://www.freecad.org/" target="_blank" rel="noopener">FreeCAD</a> opens STEP and exports to STL or OBJ for triangle-mesh viewing`,
      `<a href="https://www.opencascade.com/" target="_blank" rel="noopener">OpenCASCADE</a>'s command-line tools convert STEP to STL if you're scripting`,
      `Most CAD packages (Fusion 360, SolidWorks, Onshape, Rhino) export STEP as STL or OBJ`,
    ];
  } else if (kind === 'IGES') {
    blurb = `IGES is an older CAD interchange format with a similar problem to STEP — it stores surface and curve definitions that need a geometry kernel to evaluate. Convert it before bringing it here:`;
    options = [
      `<a href="https://www.freecad.org/" target="_blank" rel="noopener">FreeCAD</a> opens IGES and exports to STL or OBJ`,
      `<a href="https://www.opencascade.com/" target="_blank" rel="noopener">OpenCASCADE</a>'s tools handle IGES → STL conversion`,
      `Most CAD packages can re-export IGES as STL or OBJ`,
    ];
  } else {  // JT
    blurb = `JT (Jupiter Tessellation) is Siemens' 3D viewing format, widely used in automotive and aerospace pipelines. It's an open ISO standard (14306) but the binary uses several proprietary compression schemes (custom Huffman variants, deferred-load LOD streams) and there's no actively maintained pure-JS parser. Convert it first:`;
    options = [
      `<a href="https://www.sw.siemens.com/en-US/technology/jt-open/" target="_blank" rel="noopener">JT2Go</a> is the free Siemens reference viewer; it can take screenshots and export images`,
      `<a href="https://www.freecad.org/" target="_blank" rel="noopener">FreeCAD</a> has experimental JT import via plugin`,
      `Enterprise CAD apps with full JT support (NX, Solid Edge, Catia, SolidWorks via plugin) can re-export to STEP, STL, or OBJ`,
    ];
  }

  msg.innerHTML =
    `<div class="title">${escapeHtml(filename)} is a ${kind} file</div>` +
    `<div>${blurb}</div>` +
    `<ul>${options.map(o => `<li>${o}</li>`).join('')}</ul>` +
    `<button type="button" id="back">Try another file</button>`;
  $('back').addEventListener('click', resetUI);
}

function showError(text) {
  drop.classList.add('hidden'); stage.classList.add('hidden'); msg.classList.remove('hidden');
  msg.className = 'msg danger';
  msg.innerHTML =
    `<div class="title">Couldn't open that file</div>` +
    `<div>${escapeHtml(text)}</div>` +
    `<div style="margin-top:.75rem"><button type="button" id="back">Try another file</button></div>`;
  $('back').addEventListener('click', resetUI);
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

/* -------------------------------------------------------------
   Stage / canvas setup
   ------------------------------------------------------------- */

function showStage(metaText) {
  drop.classList.add('hidden');
  msg.classList.add('hidden');
  stage.classList.remove('hidden');
  // 2D-mode UI: show our canvas, hide the 3D one, restore SVG export, hide wireframe
  canvas.classList.remove('hidden');
  canvas3d.classList.add('hidden');
  exportSvgBtn.classList.remove('hidden');
  wireframeBtn.classList.add('hidden');
  measureBtn.classList.remove('hidden');
  // Stale measurements from a previous file shouldn't persist
  measurements = [];
  measurePending = null;
  if (measureMode) toggleMeasureMode();
  clearMeasureBtn.classList.add('hidden');
  hintEl.textContent = 'Drag to pan · Scroll to zoom';
  mode = '2d';
  bbox = computeBbox(entities);
  meta.textContent = metaText;
  resizeCanvas();
  fitView();
}

function resizeCanvas() {
  const wrap = canvas.parentElement;
  const dpr = window.devicePixelRatio || 1;
  const w = wrap.clientWidth, h = wrap.clientHeight;
  canvas.width = w * dpr; canvas.height = h * dpr;
  canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function computeBbox(ents) {
  let mnX = Infinity, mnY = Infinity, mxX = -Infinity, mxY = -Infinity;
  const ex = (x, y) => {
    if (isFinite(x) && isFinite(y)) {
      if (x < mnX) mnX = x; if (y < mnY) mnY = y;
      if (x > mxX) mxX = x; if (y > mxY) mxY = y;
    }
  };
  for (const e of ents) {
    switch (e.kind) {
      case 'line': ex(e.x1, e.y1); ex(e.x2, e.y2); break;
      case 'polyline': case 'polygon':
        for (const p of e.points) ex(p.x, p.y); break;
      case 'circle': case 'arc':
        ex(e.cx - e.r, e.cy - e.r); ex(e.cx + e.r, e.cy + e.r); break;
      case 'ellipse':
        const r = Math.max(e.rx, e.ry);
        ex(e.cx - r, e.cy - r); ex(e.cx + r, e.cy + r); break;
      case 'rect':
        ex(e.x, e.y); ex(e.x + e.w, e.y + e.h); break;
      case 'image':
        // Parallelogram corners P, R, Q and the implied 4th corner P+Q-R
        ex(e.P.x, e.P.y); ex(e.R.x, e.R.y); ex(e.Q.x, e.Q.y);
        ex(e.P.x + e.Q.x - e.R.x, e.P.y + e.Q.y - e.R.y);
        break;
      case 'text': case 'point':
        ex(e.x, e.y); break;
    }
  }
  if (!isFinite(mnX)) return { minX: -10, minY: -10, maxX: 10, maxY: 10 };
  // Pad slightly if degenerate
  if (mnX === mxX) { mnX -= 1; mxX += 1; }
  if (mnY === mxY) { mnY -= 1; mxY += 1; }
  return { minX: mnX, minY: mnY, maxX: mxX, maxY: mxY };
}

function fitView() {
  if (mode === '3d') { fitView3d(); return; }
  if (!entities.length) return;
  const w = canvas.clientWidth, h = canvas.clientHeight;
  const dx = Math.max(bbox.maxX - bbox.minX, 1e-6);
  const dy = Math.max(bbox.maxY - bbox.minY, 1e-6);
  view.scale = Math.min(w / dx, h / dy) * 0.92;
  view.tx = w / 2 - ((bbox.minX + bbox.maxX) / 2) * view.scale;
  view.ty = h / 2 + ((bbox.minY + bbox.maxY) / 2) * view.scale;
  render();
}

/* -------------------------------------------------------------
   Renderer (works on normalized entities)
   ------------------------------------------------------------- */

function render() {
  if (!entities.length) return;
  const w = canvas.clientWidth, h = canvas.clientHeight;
  ctx.clearRect(0, 0, w, h);
  ctx.save();
  ctx.translate(view.tx, view.ty);
  ctx.scale(view.scale, -view.scale);
  const pxLine = 1 / view.scale;
  ctx.lineWidth = pxLine;
  const fg = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#222';

  for (const e of entities) {
    const stroke = e.stroke || fg;
    const fill = e.fill;
    const lw = (e.width && isFinite(e.width) && e.width > 0)
      ? Math.max(e.width, pxLine)
      : pxLine;
    ctx.lineWidth = lw;
    ctx.strokeStyle = stroke;
    if (fill) ctx.fillStyle = fill;

    switch (e.kind) {
      case 'line':
        ctx.beginPath();
        ctx.moveTo(e.x1, e.y1); ctx.lineTo(e.x2, e.y2);
        ctx.stroke();
        break;
      case 'polyline':
        if (e.points.length < 2) break;
        ctx.beginPath();
        ctx.moveTo(e.points[0].x, e.points[0].y);
        for (let i = 1; i < e.points.length; i++) ctx.lineTo(e.points[i].x, e.points[i].y);
        if (e.closed) ctx.closePath();
        ctx.stroke();
        break;
      case 'polygon':
        if (e.points.length < 3) break;
        ctx.beginPath();
        ctx.moveTo(e.points[0].x, e.points[0].y);
        for (let i = 1; i < e.points.length; i++) ctx.lineTo(e.points[i].x, e.points[i].y);
        ctx.closePath();
        if (fill) ctx.fill();
        if (stroke) ctx.stroke();
        break;
      case 'circle':
        ctx.beginPath();
        ctx.arc(e.cx, e.cy, e.r, 0, Math.PI * 2);
        if (fill) ctx.fill();
        ctx.stroke();
        break;
      case 'arc':
        ctx.beginPath();
        ctx.arc(e.cx, e.cy, e.r, e.startAngle, e.endAngle, !!e.anticlockwise);
        ctx.stroke();
        break;
      case 'ellipse':
        ctx.beginPath();
        ctx.ellipse(e.cx, e.cy, e.rx, e.ry, e.rotation || 0,
                    e.startAngle ?? 0, e.endAngle ?? Math.PI * 2);
        if (fill) ctx.fill();
        ctx.stroke();
        break;
      case 'rect':
        if (fill) ctx.fillRect(e.x, e.y, e.w, e.h);
        ctx.strokeRect(e.x, e.y, e.w, e.h);
        break;
      case 'point':
        ctx.beginPath();
        ctx.arc(e.x, e.y, 1.5 / view.scale, 0, Math.PI * 2);
        ctx.fillStyle = stroke;
        ctx.fill();
        break;
      case 'image': {
        // Map the image's pixel-space rectangle (0,0)-(nx,ny) onto the
        // CGM parallelogram P-R-Q-(P+Q-R). The 6-tuple is the affine matrix
        //   [a c e]
        //   [b d f]
        // such that pixel (i,j) lands at (a*i + c*j + e, b*i + d*j + f).
        const a = (e.R.x - e.P.x) / e.nx;
        const b = (e.R.y - e.P.y) / e.nx;
        const c = (e.Q.x - e.R.x) / e.ny;
        const d = (e.Q.y - e.R.y) / e.ny;
        ctx.save();
        ctx.transform(a, b, c, d, e.P.x, e.P.y);
        ctx.imageSmoothingEnabled = false;  // keep cells crisp
        ctx.drawImage(e.canvas, 0, 0);
        ctx.restore();
        break;
      }
      case 'text': {
        ctx.save();
        ctx.translate(e.x, e.y);
        if (e.rotation) ctx.rotate(e.rotation);
        ctx.scale(1, -1);
        const size = Math.max(e.size || 1, 1);
        ctx.font = size + 'px sans-serif';
        ctx.textAlign = e.anchor === 'middle' ? 'center'
                      : e.anchor === 'end'    ? 'right'
                      : 'left';
        ctx.textBaseline = e.baseline === 'hanging'     ? 'top'
                         : e.baseline === 'middle'      ? 'middle'
                         : e.baseline === 'ideographic' ? 'bottom'
                         : 'alphabetic';
        ctx.fillStyle = e.color || fg;
        ctx.fillText(e.text || '', 0, 0);
        ctx.restore();
        break;
      }
    }
  }
  ctx.restore();

  // Overlays in screen-space — order matters: measurements first, rulers
  // last so the ruler bars overdraw any measurement strokes that cross them.
  drawMeasurements();
  drawRulers();
}

/* -------------------------------------------------------------
   Coordinate transforms + formatting helpers
   ------------------------------------------------------------- */

function screenToWorld(sx, sy) {
  return { x: (sx - view.tx) / view.scale, y: -(sy - view.ty) / view.scale };
}

function worldToScreen(wx, wy) {
  return { x: wx * view.scale + view.tx, y: -wy * view.scale + view.ty };
}

// Pick a "nice" round tick interval (1, 2, or 5 × 10ⁿ) so major ticks land
// roughly every targetPx pixels regardless of zoom level.
function niceInterval(scale, targetPx = 80) {
  const target = targetPx / scale;
  const power = Math.pow(10, Math.floor(Math.log10(target)));
  const frac = target / power;
  if (frac <= 1) return 1 * power;
  if (frac <= 2) return 2 * power;
  if (frac <= 5) return 5 * power;
  return 10 * power;
}

// Format a coordinate with decimal precision that matches the current zoom —
// more precision when zoomed in close.
function fmtCoord(v) {
  if (!isFinite(v)) return '';
  const decimals = view.scale >= 1000 ? 4
                 : view.scale >= 100  ? 3
                 : view.scale >= 10   ? 2
                 : view.scale >= 1    ? 1 : 0;
  const s = v.toFixed(decimals);
  // Trim trailing zeros after decimal but keep at least one decimal if there is a point
  return decimals > 0 ? s.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') : s;
}

/* -------------------------------------------------------------
   Rulers (top + left bars, screen-space)
   ------------------------------------------------------------- */

function drawRulers() {
  const w = canvas.clientWidth, h = canvas.clientHeight;
  const cs = getComputedStyle(document.documentElement);
  const surface = cs.getPropertyValue('--surface').trim() || '#fff';
  const text    = cs.getPropertyValue('--text').trim()    || '#222';
  const muted   = cs.getPropertyValue('--muted').trim()   || '#888';
  const border  = cs.getPropertyValue('--border').trim()  || 'rgba(0,0,0,0.12)';

  // Bar backgrounds (slightly translucent so the underlying drawing peeks through)
  ctx.fillStyle = surface;
  ctx.globalAlpha = 0.92;
  ctx.fillRect(0, 0, w, RULER_PX);          // top
  ctx.fillRect(0, 0, RULER_PX, h);          // left
  ctx.globalAlpha = 1;

  // Inner edge lines that visually separate rulers from drawing area
  ctx.strokeStyle = border;
  ctx.lineWidth = 0.5;
  ctx.beginPath();
  ctx.moveTo(0, RULER_PX + 0.5); ctx.lineTo(w, RULER_PX + 0.5);
  ctx.moveTo(RULER_PX + 0.5, 0); ctx.lineTo(RULER_PX + 0.5, h);
  ctx.stroke();

  const interval = niceInterval(view.scale);
  const minorStep = interval / 5;

  ctx.font = '10px sans-serif';
  ctx.fillStyle = text;
  ctx.strokeStyle = muted;
  ctx.lineWidth = 1;

  // Top ruler — x-axis, ticks pointing down toward the drawing area
  ctx.textAlign = 'center';
  ctx.textBaseline = 'top';
  const xLeft  = (RULER_PX - view.tx) / view.scale;   // world-x at left edge of drawing area
  const xRight = (w - view.tx) / view.scale;          // world-x at right edge
  const xMinorStart = Math.floor(xLeft / minorStep) * minorStep;
  ctx.beginPath();
  for (let wx = xMinorStart; wx <= xRight; wx += minorStep) {
    const sx = wx * view.scale + view.tx;
    if (sx < RULER_PX) continue;
    // Major tick: longer line + label
    const isMajor = Math.abs(Math.round(wx / interval) * interval - wx) < interval * 1e-6;
    if (isMajor) {
      ctx.moveTo(sx + 0.5, RULER_PX - 7);
      ctx.lineTo(sx + 0.5, RULER_PX);
      ctx.fillText(fmtCoord(wx), sx, 2);
    } else {
      ctx.moveTo(sx + 0.5, RULER_PX - 3);
      ctx.lineTo(sx + 0.5, RULER_PX);
    }
  }
  ctx.stroke();

  // Left ruler — y-axis (CAD y-up), ticks pointing right
  ctx.textAlign = 'right';
  ctx.textBaseline = 'middle';
  const yBot = -(h - view.ty) / view.scale;          // world-y at bottom edge (smaller y in y-up)
  const yTop = -(RULER_PX - view.ty) / view.scale;   // world-y at top edge of drawing area
  const yMinorStart = Math.floor(yBot / minorStep) * minorStep;
  ctx.beginPath();
  for (let wy = yMinorStart; wy <= yTop; wy += minorStep) {
    const sy = -wy * view.scale + view.ty;
    if (sy < RULER_PX) continue;
    const isMajor = Math.abs(Math.round(wy / interval) * interval - wy) < interval * 1e-6;
    if (isMajor) {
      ctx.moveTo(RULER_PX - 7, sy + 0.5);
      ctx.lineTo(RULER_PX,     sy + 0.5);
      // Labels on the left ruler are right-aligned at the inner edge with a small gap
      ctx.fillText(fmtCoord(wy), RULER_PX - 9, sy);
    } else {
      ctx.moveTo(RULER_PX - 3, sy + 0.5);
      ctx.lineTo(RULER_PX,     sy + 0.5);
    }
  }
  ctx.stroke();

  // Top-left corner — overdraw cleanly so neither ruler's text leaks into the other
  ctx.fillStyle = surface;
  ctx.fillRect(0, 0, RULER_PX, RULER_PX);
  ctx.strokeStyle = border;
  ctx.beginPath();
  ctx.moveTo(0, RULER_PX + 0.5); ctx.lineTo(RULER_PX, RULER_PX + 0.5);
  ctx.moveTo(RULER_PX + 0.5, 0); ctx.lineTo(RULER_PX + 0.5, RULER_PX);
  ctx.stroke();

  // Cursor indicator — translucent stripe across each ruler at cursor position
  if (cursorScreen
      && cursorScreen.x >= RULER_PX && cursorScreen.y >= RULER_PX
      && cursorScreen.x < w && cursorScreen.y < h) {
    ctx.fillStyle = 'rgba(200, 99, 30, 0.5)';  // amber, matches measurement color
    ctx.fillRect(cursorScreen.x - 0.5, 0, 1, RULER_PX);
    ctx.fillRect(0, cursorScreen.y - 0.5, RULER_PX, 1);
  }
}

/* -------------------------------------------------------------
   Linear dimensions (measurements)
   ------------------------------------------------------------- */

function drawMeasurements() {
  for (const m of measurements) drawDimension(m.p1, m.p2, false);
  // While picking the second point, draw a live preview to the cursor
  if (measureMode && measurePending && cursorWorld) {
    drawDimension(measurePending, cursorWorld, true);
  }
}

function drawDimension(p1, p2, pending) {
  const a = worldToScreen(p1.x, p1.y);
  const b = worldToScreen(p2.x, p2.y);
  const dx = b.x - a.x, dy = b.y - a.y;
  const len = Math.hypot(dx, dy);
  if (len < 1) return;
  const nx = -dy / len, ny = dx / len;  // unit perpendicular

  const color = pending ? 'rgba(30, 58, 138, 0.7)' : '#1e3a8a';
  const cs = getComputedStyle(document.documentElement);
  const surface = cs.getPropertyValue('--surface').trim() || '#fff';

  ctx.save();
  ctx.strokeStyle = color;
  ctx.lineWidth = 1;
  ctx.setLineDash(pending ? [4, 3] : []);

  // Main dimension line
  ctx.beginPath();
  ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y);
  ctx.stroke();

  // Perpendicular tick marks at endpoints (act as arrowheads)
  const t = 5;
  ctx.setLineDash([]);
  ctx.beginPath();
  ctx.moveTo(a.x + nx * t, a.y + ny * t); ctx.lineTo(a.x - nx * t, a.y - ny * t);
  ctx.moveTo(b.x + nx * t, b.y + ny * t); ctx.lineTo(b.x - nx * t, b.y - ny * t);
  ctx.stroke();

  // Distance label, rotated to match the line, with a surface-color back-plate
  const distance = Math.hypot(p2.x - p1.x, p2.y - p1.y);
  const label = fmtCoord(distance);
  let angle = Math.atan2(dy, dx);
  if (angle > Math.PI / 2 || angle < -Math.PI / 2) angle += Math.PI;  // keep text upright
  const midX = (a.x + b.x) / 2, midY = (a.y + b.y) / 2;
  const labelOffset = 12;
  ctx.translate(midX + nx * labelOffset, midY + ny * labelOffset);
  ctx.rotate(angle);
  ctx.font = '11px sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const tw = ctx.measureText(label).width;
  ctx.fillStyle = surface;
  ctx.globalAlpha = 0.92;
  ctx.fillRect(-tw / 2 - 4, -8, tw + 8, 16);
  ctx.globalAlpha = 1;
  ctx.fillStyle = color;
  ctx.fillText(label, 0, 0);
  ctx.restore();
}

/* -------------------------------------------------------------
   AutoCAD Color Index → CSS color
   Indices 1–9 are the canonical primary colors; 10–249 follow a
   24-hue × variation pattern that we approximate via HSL; 250–255
   are grayscale steps. 0=BYBLOCK, 7=foreground, 256=BYLAYER are
   special and resolved against layer/fallback colors.
   ------------------------------------------------------------- */

const ACI_BASE = {
  1: '#ff0000', 2: '#ffff00', 3: '#00ff00', 4: '#00ffff',
  5: '#0000ff', 6: '#ff00ff', 8: '#414141', 9: '#808080',
};

function aciToColor(aci, layerColor, fallback) {
  if (aci == null) return fallback;
  if (aci === 0 || aci === 256) return layerColor || fallback;  // BYBLOCK / BYLAYER
  if (aci === 7) return fallback;                                // foreground
  if (ACI_BASE[aci]) return ACI_BASE[aci];
  if (aci >= 10 && aci <= 249) {
    // Approximate the 24-hue × 10-variation grid algorithmically
    const hueIdx = Math.floor((aci - 10) / 10);
    const variation = (aci - 10) % 10;
    const h = (hueIdx * 15) % 360;
    const sat = variation < 6 ? 100 - variation * 8 : 60 - (variation - 6) * 10;
    const lit = 30 + variation * 4;
    return `hsl(${h},${Math.max(sat, 30)}%,${Math.min(Math.max(lit, 25), 75)}%)`;
  }
  if (aci >= 250 && aci <= 255) {
    const v = Math.round(((aci - 250) / 5) * 255).toString(16).padStart(2, '0');
    return '#' + v + v + v;
  }
  return fallback;
}

/* -------------------------------------------------------------
   DXF → normalized entity list
   ------------------------------------------------------------- */

function dxfToNormalized(dxf) {
  const out = [];
  const blocks = dxf.blocks || {};
  const layers = (dxf.tables && dxf.tables.layer && dxf.tables.layer.layers) || {};

  // Resolve an entity's stroke color from its ACI and its layer's ACI
  function entityColor(e, blockColor) {
    const aci = e.color != null ? e.color : e.colorIndex;
    const layerName = e.layer;
    const layerAci = layers[layerName] ? layers[layerName].color : null;
    const layerColor = layerAci != null ? aciToColor(layerAci, null, null) : null;
    return aciToColor(aci, layerColor || blockColor, null);
  }

  function visit(list, dx, dy, blockColor) {
    for (const e of list) {
      const stroke = entityColor(e, blockColor);
      switch (e.type) {
        case 'LINE':
          if (e.vertices && e.vertices.length >= 2)
            out.push({ kind: 'line',
              x1: e.vertices[0].x + dx, y1: e.vertices[0].y + dy,
              x2: e.vertices[1].x + dx, y2: e.vertices[1].y + dy, stroke });
          break;
        case 'CIRCLE':
          if (e.center && isFinite(e.radius))
            out.push({ kind: 'circle',
              cx: e.center.x + dx, cy: e.center.y + dy, r: e.radius, stroke });
          break;
        case 'ARC':
          if (e.center && isFinite(e.radius))
            out.push({ kind: 'arc', cx: e.center.x + dx, cy: e.center.y + dy, r: e.radius,
              startAngle: e.startAngle || 0,
              endAngle: e.endAngle != null ? e.endAngle : Math.PI * 2, stroke });
          break;
        case 'LWPOLYLINE': case 'POLYLINE':
          if (e.vertices && e.vertices.length)
            out.push({ kind: 'polyline',
              points: e.vertices.map(v => ({ x: v.x + dx, y: v.y + dy })),
              closed: !!e.shape, stroke });
          break;
        case 'ELLIPSE':
          if (e.center && e.majorAxisEndPoint) {
            const rx = Math.hypot(e.majorAxisEndPoint.x, e.majorAxisEndPoint.y);
            out.push({ kind: 'ellipse',
              cx: e.center.x + dx, cy: e.center.y + dy,
              rx, ry: rx * (e.axisRatio || 1),
              rotation: Math.atan2(e.majorAxisEndPoint.y, e.majorAxisEndPoint.x),
              startAngle: e.startAngle || 0,
              endAngle: e.endAngle != null ? e.endAngle : Math.PI * 2, stroke });
          }
          break;
        case 'POINT':
          if (e.position) out.push({ kind: 'point',
            x: e.position.x + dx, y: e.position.y + dy, stroke });
          break;
        case 'SPLINE': {
          const pts = e.controlPoints || [];
          if (pts.length >= 2)
            out.push({ kind: 'polyline',
              points: pts.map(v => ({ x: v.x + dx, y: v.y + dy })),
              closed: false, stroke });
          break;
        }
        case 'TEXT': case 'MTEXT': {
          const p = e.startPoint || e.position; if (!p) break;
          const text = (e.text || '').replace(/\\P/g, ' ').replace(/\{[^}]*\}/g, '').replace(/\\[A-Za-z]+;?/g, '');
          out.push({ kind: 'text', x: p.x + dx, y: p.y + dy,
            text, size: e.textHeight || e.height || 1,
            rotation: e.rotation ? (e.rotation * Math.PI / 180) : 0,
            color: stroke });
          break;
        }
        case '3DFACE': case 'SOLID':
          if (e.vertices && e.vertices.length >= 3)
            out.push({ kind: 'polyline',
              points: e.vertices.map(v => ({ x: v.x + dx, y: v.y + dy })),
              closed: true, stroke });
          break;
        case 'INSERT': {
          const blk = blocks[e.name];
          if (!blk) break;
          const p = e.position || { x: 0, y: 0 };
          const bp = blk.position || { x: 0, y: 0 };
          // INSERT entity may set a color that propagates to BYBLOCK children
          visit(blk.entities || [], dx + (p.x - bp.x), dy + (p.y - bp.y), stroke || blockColor);
          break;
        }
      }
    }
  }
  visit(dxf.entities, 0, 0, null);
  return out;
}

/* -------------------------------------------------------------
   CGM clear-text parser
   -------------------------------------------------------------
   Spec is large, this handles the common subset:
     - delimiters: BEGMF/ENDMF/BEGPIC/BEGPICBODY/ENDPIC/BEGMFDEFAULTS/ENDMFDEFAULTS
     - VDCEXT, COLRMODE, COLRTABLE, BACKCOLR
     - attributes: LINECOLR LINEWIDTH FILLCOLR INTSTYLE EDGECOLR EDGEWIDTH
                   EDGEVIS TEXTCOLR CHARHEIGHT
     - primitives: LINE POLYLINE DISJTLINE POLYGON RECT/RECTANGLE
                   CIRCLE CIRCLE3 ARCCTR ARC3 ELLIPSE TEXT RESTRTEXT
   Unknown elements are skipped (advance to next ;) so unfamiliar files
   still render the parts we understand.
   ------------------------------------------------------------- */

function tokenizeCgm(text) {
  const tokens = [];
  let i = 0, n = text.length;
  while (i < n) {
    const c = text[i];
    // whitespace + comma
    if (c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === ',') { i++; continue; }
    // comments: % ... %
    if (c === '%') {
      i++;
      while (i < n && text[i] !== '%') i++;
      if (i < n) i++;
      continue;
    }
    // string: ' ... ' or " ... " (doubled quote = literal)
    if (c === "'" || c === '"') {
      const q = c; i++;
      let s = '';
      while (i < n) {
        if (text[i] === q) {
          if (text[i+1] === q) { s += q; i += 2; }
          else { i++; break; }
        } else { s += text[i++]; }
      }
      tokens.push({ type: 'string', value: s });
      continue;
    }
    if (c === '(') { tokens.push({ type: 'lparen' }); i++; continue; }
    if (c === ')') { tokens.push({ type: 'rparen' }); i++; continue; }
    if (c === ';') { tokens.push({ type: 'semi' }); i++; continue; }
    if (c === '/') { tokens.push({ type: 'slash' }); i++; continue; }
    // number
    if ((c >= '0' && c <= '9') || c === '-' || c === '+' || c === '.') {
      let s = ''; let sawDigit = false;
      while (i < n) {
        const cc = text[i];
        if ((cc >= '0' && cc <= '9')) { s += cc; sawDigit = true; i++; }
        else if (cc === '.' || cc === '-' || cc === '+' || cc === 'e' || cc === 'E') { s += cc; i++; }
        else break;
      }
      if (sawDigit && !isNaN(parseFloat(s))) {
        tokens.push({ type: 'number', value: parseFloat(s) });
        continue;
      }
      // fallthrough — treat as word
      tokens.push({ type: 'word', value: s.toUpperCase() });
      continue;
    }
    // word/keyword
    let s = '';
    while (i < n) {
      const cc = text[i];
      if (cc === ' ' || cc === '\t' || cc === '\n' || cc === '\r' ||
          cc === ',' || cc === ';' || cc === '(' || cc === ')' ||
          cc === "'" || cc === '"' || cc === '%' || cc === '/') break;
      s += cc; i++;
    }
    if (s) tokens.push({ type: 'word', value: s.toUpperCase() });
  }
  return tokens;
}

function parseCgmClear(text) {
  const tokens = tokenizeCgm(text);
  let i = 0;
  const ents = [];
  let vdcExt = null;
  const state = {
    colorMode: 'INDEXED',
    colorPrec: 255,         // max value for direct RGB components
    colorTable: { 0: '#ffffff', 1: '#000000' }, // default: bg=white, fg=black
    bgColor: '#ffffff',
    lineColor: '#000000',
    lineWidth: 0,           // 0 = use default thin line
    fillColor: '#cccccc',
    intStyle: 'HOLLOW',
    edgeColor: '#000000',
    edgeWidth: 0,
    edgeVisible: false,
    textColor: '#000000',
    charHeight: 0,
    // Text orientation: charBase = reading direction, charUp = "up" of glyph.
    // Defaults are standard left-to-right reading.
    charBase: { x: 1, y: 0 },
    charUp: { x: 0, y: 1 },
    textAlignH: 'NORMHORIZ',
    textAlignV: 'NORMVERT',
  };

  function readElementParams() {
    // Collect tokens up to ; (skipping parens, which we treat as visual-only)
    const params = [];
    while (i < tokens.length && tokens[i].type !== 'semi') {
      const t = tokens[i++];
      if (t.type === 'lparen' || t.type === 'rparen' || t.type === 'slash') continue;
      params.push(t);
    }
    if (i < tokens.length) i++; // consume ;
    return params;
  }

  function nums(params) { return params.filter(t => t.type === 'number').map(t => t.value); }
  function words(params) { return params.filter(t => t.type === 'word').map(t => t.value); }
  function strs(params) { return params.filter(t => t.type === 'string').map(t => t.value); }
  function pairs(arr) {
    const r = [];
    for (let j = 0; j + 1 < arr.length; j += 2) r.push({ x: arr[j], y: arr[j+1] });
    return r;
  }

  function parseColor(params) {
    const ns = nums(params);
    if (state.colorMode === 'DIRECT' && ns.length >= 3) {
      return rgbToHex(ns[0], ns[1], ns[2], state.colorPrec);
    }
    if (ns.length >= 1) {
      const idx = Math.round(ns[0]);
      if (state.colorTable[idx]) return state.colorTable[idx];
      // Fallback for unknown index: derive from index
      return idx === 0 ? state.bgColor : '#000000';
    }
    return '#000000';
  }

  while (i < tokens.length) {
    const t = tokens[i++];
    if (t.type !== 'word') continue;
    const kw = t.value;
    const params = readElementParams();

    try {
      switch (kw) {
        // delimiters — nothing to do
        case 'BEGMF': case 'ENDMF':
        case 'BEGPIC': case 'BEGPICBODY': case 'ENDPIC':
        case 'BEGMFDEFAULTS': case 'ENDMFDEFAULTS':
        case 'BEGFIGURE': case 'ENDFIGURE':
        case 'BEGPROTREGION': case 'ENDPROTREGION':
        case 'BEGCOMPOLINE': case 'ENDCOMPOLINE':
        case 'BEGTILEARRAY': case 'ENDTILEARRAY':
          break;

        // metafile/picture descriptors we care about
        case 'VDCEXT': {
          const ns = nums(params);
          if (ns.length >= 4) vdcExt = { minX: ns[0], minY: ns[1], maxX: ns[2], maxY: ns[3] };
          break;
        }
        case 'COLRMODE':
        case 'COLOURMODE': {
          const w = words(params)[0];
          if (w === 'INDEXED' || w === 'DIRECT') state.colorMode = w;
          break;
        }
        case 'COLRPRECISION':
        case 'COLOURPRECISION': {
          const ns = nums(params);
          if (ns.length) state.colorPrec = ns[0];
          break;
        }
        case 'COLRVALUEEXT':
        case 'COLOURVALUEEXT': {
          // RGB min/max — use max R as precision proxy
          const ns = nums(params);
          if (ns.length >= 6) state.colorPrec = Math.max(ns[3], ns[4], ns[5]);
          break;
        }
        case 'COLRTABLE':
        case 'COLOURTABLE': {
          const ns = nums(params);
          if (ns.length >= 4) {
            const start = Math.round(ns[0]);
            for (let k = 1; k + 2 < ns.length; k += 3) {
              state.colorTable[start + Math.floor((k - 1) / 3)] =
                rgbToHex(ns[k], ns[k+1], ns[k+2], state.colorPrec);
            }
          }
          break;
        }
        case 'BACKCOLR': case 'BACKCOLOUR':
          state.bgColor = parseColor(params); state.colorTable[0] = state.bgColor; break;

        // attributes
        case 'LINECOLR': case 'LINECOLOUR': state.lineColor = parseColor(params); break;
        case 'LINEWIDTH': { const ns = nums(params); if (ns.length) state.lineWidth = ns[0]; break; }
        case 'FILLCOLR': case 'FILLCOLOUR': state.fillColor = parseColor(params); break;
        case 'INTSTYLE': { const w = words(params)[0]; if (w) state.intStyle = w; break; }
        case 'EDGECOLR': case 'EDGECOLOUR': state.edgeColor = parseColor(params); break;
        case 'EDGEWIDTH': { const ns = nums(params); if (ns.length) state.edgeWidth = ns[0]; break; }
        case 'EDGEVIS': case 'EDGEVISIBILITY': {
          const w = words(params)[0];
          state.edgeVisible = (w === 'ON' || w === 'YES');
          break;
        }
        case 'TEXTCOLR': case 'TEXTCOLOUR': state.textColor = parseColor(params); break;
        case 'CHARHEIGHT': { const ns = nums(params); if (ns.length) state.charHeight = ns[0]; break; }
        case 'CHARORI': case 'CHARACTERORIENTATION': {
          // CGM spec order: charUp (x,y), charBase (x,y)
          const ns = nums(params);
          if (ns.length >= 4) {
            state.charUp   = { x: ns[0], y: ns[1] };
            state.charBase = { x: ns[2], y: ns[3] };
          }
          break;
        }
        case 'TEXTALIGN': case 'TEXTALIGNMENT': {
          const ws = words(params);
          if (ws[0]) state.textAlignH = ws[0];
          if (ws[1]) state.textAlignV = ws[1];
          break;
        }

        // primitives
        case 'LINE': case 'POLYLINE': {
          const pts = pairs(nums(params));
          if (pts.length >= 2)
            ents.push({ kind: 'polyline', points: pts, closed: false,
              stroke: state.lineColor, width: state.lineWidth });
          break;
        }
        case 'DISJTLINE': {
          const pts = pairs(nums(params));
          for (let k = 0; k + 1 < pts.length; k += 2) {
            ents.push({ kind: 'line',
              x1: pts[k].x, y1: pts[k].y, x2: pts[k+1].x, y2: pts[k+1].y,
              stroke: state.lineColor, width: state.lineWidth });
          }
          break;
        }
        case 'POLYGON': {
          const pts = pairs(nums(params));
          if (pts.length >= 3) {
            const fill = isSolidStyle(state.intStyle) ? state.fillColor : null;
            ents.push({ kind: 'polygon', points: pts, fill,
              stroke: state.edgeVisible ? state.edgeColor : null,
              width: state.edgeWidth });
          }
          break;
        }
        case 'RECT': case 'RECTANGLE': {
          const ns = nums(params);
          if (ns.length >= 4) {
            const x = Math.min(ns[0], ns[2]), y = Math.min(ns[1], ns[3]);
            const w = Math.abs(ns[2] - ns[0]), h = Math.abs(ns[3] - ns[1]);
            const fill = isSolidStyle(state.intStyle) ? state.fillColor : null;
            ents.push({ kind: 'rect', x, y, w, h, fill,
              stroke: state.edgeVisible ? state.edgeColor : state.lineColor,
              width: state.edgeWidth || state.lineWidth });
          }
          break;
        }
        case 'CIRCLE': {
          const ns = nums(params);
          if (ns.length >= 3) {
            const fill = isSolidStyle(state.intStyle) ? state.fillColor : null;
            ents.push({ kind: 'circle', cx: ns[0], cy: ns[1], r: ns[2], fill,
              stroke: state.edgeVisible ? state.edgeColor : state.lineColor,
              width: state.edgeWidth || state.lineWidth });
          }
          break;
        }
        case 'CIRCLE3': case 'CIRCLE3PT': {
          // Three points on the circle
          const pts = pairs(nums(params));
          if (pts.length >= 3) {
            const c = circleFrom3Points(pts[0], pts[1], pts[2]);
            if (c) ents.push({ kind: 'circle', cx: c.cx, cy: c.cy, r: c.r,
              stroke: state.lineColor, width: state.lineWidth });
          }
          break;
        }
        case 'ARCCTR': {
          // Center, vec1 (x,y), vec2 (x,y), radius
          const ns = nums(params);
          if (ns.length >= 7) {
            const cx = ns[0], cy = ns[1];
            const a1 = Math.atan2(ns[3], ns[2]);
            const a2 = Math.atan2(ns[5], ns[4]);
            ents.push({ kind: 'arc', cx, cy, r: ns[6],
              startAngle: a1, endAngle: a2,
              stroke: state.lineColor, width: state.lineWidth });
          }
          break;
        }
        case 'ARC3': case 'ARC3PT': {
          // Start, intermediate, end
          const pts = pairs(nums(params));
          if (pts.length >= 3) {
            const c = circleFrom3Points(pts[0], pts[1], pts[2]);
            if (c) {
              const a0 = Math.atan2(pts[0].y - c.cy, pts[0].x - c.cx);
              const a2 = Math.atan2(pts[2].y - c.cy, pts[2].x - c.cx);
              ents.push({ kind: 'arc', cx: c.cx, cy: c.cy, r: c.r,
                startAngle: a0, endAngle: a2,
                stroke: state.lineColor, width: state.lineWidth });
            }
          }
          break;
        }
        case 'ELLIPSE': {
          // center, conjugate diameter end 1, conjugate diameter end 2
          const pts = pairs(nums(params));
          if (pts.length >= 3) {
            const cx = pts[0].x, cy = pts[0].y;
            const v1 = { x: pts[1].x - cx, y: pts[1].y - cy };
            const v2 = { x: pts[2].x - cx, y: pts[2].y - cy };
            const fill = isSolidStyle(state.intStyle) ? state.fillColor : null;
            ents.push({ kind: 'ellipse',
              cx, cy,
              rx: Math.hypot(v1.x, v1.y),
              ry: Math.hypot(v2.x, v2.y),
              rotation: Math.atan2(v1.y, v1.x),
              startAngle: 0, endAngle: Math.PI * 2,
              fill, stroke: state.edgeVisible ? state.edgeColor : state.lineColor,
              width: state.edgeWidth || state.lineWidth });
          }
          break;
        }
        case 'TEXT': case 'RESTRTEXT': {
          // (x,y) [width height for RESTRTEXT] FINAL/NOTFINAL 'string'
          const ns = nums(params);
          const ss = strs(params);
          if (ns.length >= 2 && ss.length) {
            ents.push({ kind: 'text', x: ns[0], y: ns[1],
              text: ss[ss.length - 1],
              size: state.charHeight || 10,
              color: state.textColor,
              rotation: Math.atan2(state.charBase.y, state.charBase.x),
              anchor: cgmHorizToAnchor(state.textAlignH),
              baseline: cgmVertToBaseline(state.textAlignV) });
          }
          break;
        }
        case 'CELLARRAY': {
          // P Q R nx ny [local_color_precision] [rep_type] color1 color2 ...
          // P = corner of cell (0,0), R = corner of cell (nx,0),
          // Q = corner of cell (nx,ny). 4th implicit corner = P+Q-R.
          // Cells are listed row-major with i (along P→R) varying fastest.
          // Only packed (rep_type=0) is supported here; run-length is skipped.
          const ns = nums(params);
          if (ns.length < 8) break;
          const P = { x: ns[0], y: ns[1] };
          const Q = { x: ns[2], y: ns[3] };
          const R = { x: ns[4], y: ns[5] };
          const nx = Math.round(ns[6]);
          const ny = Math.round(ns[7]);
          if (nx < 1 || ny < 1 || nx > 4096 || ny > 4096 || nx * ny > (1 << 20)) break;

          const colorBytes = state.colorMode === 'DIRECT' ? 3 : 1;
          const expectedColors = nx * ny * colorBytes;
          // After 8 fixed nums, 0–2 optional ints (local color precision,
          // representation type) precede the color values. Decide which are
          // present by remaining-count arithmetic.
          let cursor = 8;
          let repType = 0;
          const remaining = ns.length - cursor;
          if (remaining >= expectedColors + 2) {
            repType = ns[cursor + 1];
            cursor += 2;
          } else if (remaining >= expectedColors + 1) {
            // One extra — assume rep_type if it's 0 or 1, else local precision
            if (ns[cursor] === 0 || ns[cursor] === 1) repType = ns[cursor];
            cursor += 1;
          }
          if (repType !== 0) break;  // run-length unsupported

          const colorVals = ns.slice(cursor, cursor + expectedColors);
          if (colorVals.length < expectedColors) break;

          // Build offscreen canvas with one source pixel per cell.
          // Canvas pixel (i, j) holds cell (i+1, j+1); when drawn through
          // the affine transform the cell lands at the right spot in CAD space.
          const cellCanvas = document.createElement('canvas');
          cellCanvas.width = nx;
          cellCanvas.height = ny;
          const cctx = cellCanvas.getContext('2d');
          const img = cctx.createImageData(nx, ny);
          const directScale = state.colorPrec > 1 ? 255 / state.colorPrec : 255;
          for (let k = 0; k < nx * ny; k++) {
            let r, g, b;
            if (state.colorMode === 'DIRECT') {
              r = clamp255(colorVals[3*k]     * directScale);
              g = clamp255(colorVals[3*k + 1] * directScale);
              b = clamp255(colorVals[3*k + 2] * directScale);
            } else {
              const idx = Math.round(colorVals[k]);
              const hex = state.colorTable[idx]
                || (idx === 0 ? state.bgColor : '#000000');
              const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
              if (m) {
                r = parseInt(m[1], 16);
                g = parseInt(m[2], 16);
                b = parseInt(m[3], 16);
              } else { r = g = b = 128; }
            }
            img.data[4*k]     = r;
            img.data[4*k + 1] = g;
            img.data[4*k + 2] = b;
            img.data[4*k + 3] = 255;
          }
          cctx.putImageData(img, 0, 0);

          ents.push({ kind: 'image', P, Q, R, nx, ny, canvas: cellCanvas });
          break;
        }
        // Everything else: skip silently. We've already advanced past the ;.
      }
    } catch (err) {
      // Ignore one bad element so the rest of the file still renders
      console.warn('CGM element', kw, 'skipped:', err);
    }
  }

  return { entities: ents, vdcExt };
}

function isSolidStyle(s) { return s === 'SOLID' || s === 'PATTERN' || s === 'HATCH'; }

function clamp255(v) {
  v = Math.round(v);
  return v < 0 ? 0 : v > 255 ? 255 : v;
}

function cgmHorizToAnchor(h) {
  switch (h) {
    case 'CTR': case 'CENTER': case 'CENTRE': return 'middle';
    case 'RIGHT': return 'end';
    default: return 'start';
  }
}

function cgmVertToBaseline(v) {
  switch (v) {
    case 'TOP': case 'CAP': return 'hanging';
    case 'HALF': return 'middle';
    case 'BOTTOM': return 'ideographic';
    default: return 'alphabetic';
  }
}

function rgbToHex(r, g, b, prec) {
  const scale = prec > 1 ? 255 / prec : 255;
  const c = v => {
    let x = Math.round(v * scale);
    if (x < 0) x = 0; if (x > 255) x = 255;
    return x.toString(16).padStart(2, '0');
  };
  return '#' + c(r) + c(g) + c(b);
}

function circleFrom3Points(p1, p2, p3) {
  // Returns {cx, cy, r} for the unique circle through 3 points, or null if collinear
  const ax = p1.x, ay = p1.y, bx = p2.x, by = p2.y, cx_ = p3.x, cy_ = p3.y;
  const d = 2 * (ax * (by - cy_) + bx * (cy_ - ay) + cx_ * (ay - by));
  if (Math.abs(d) < 1e-9) return null;
  const ux = ((ax*ax + ay*ay) * (by - cy_) + (bx*bx + by*by) * (cy_ - ay) + (cx_*cx_ + cy_*cy_) * (ay - by)) / d;
  const uy = ((ax*ax + ay*ay) * (cx_ - bx) + (bx*bx + by*by) * (ax - cx_) + (cx_*cx_ + cy_*cy_) * (bx - ax)) / d;
  return { cx: ux, cy: uy, r: Math.hypot(ax - ux, ay - uy) };
}

/* -------------------------------------------------------------
   SVG export
   -------------------------------------------------------------
   Strategy: outer <g transform="scale(1,-1)"> flips the world to
   match CAD's Y-up convention, so shapes can be written using
   their original CAD coordinates. Text gets a per-element
   counter-flip (translate → rotate → scale(1,-1)) so glyphs read
   upright while still sitting at the right CAD position and angle.
   ------------------------------------------------------------- */

function exportSvg() {
  if (!entities.length) return;
  const w = bbox.maxX - bbox.minX;
  const h = bbox.maxY - bbox.minY;
  const pad = (Math.max(w, h) || 1) * 0.05;
  const vbX = bbox.minX - pad;
  const vbY = -(bbox.maxY) - pad;   // top-left in flipped coords
  const vbW = w + pad * 2;
  const vbH = h + pad * 2;

  const parts = [];
  parts.push('<' + '?xml version="1.0" encoding="UTF-8"?' + '>');
  parts.push(`<svg xmlns="http://www.w3.org/2000/svg" `
    + `viewBox="${num(vbX)} ${num(vbY)} ${num(vbW)} ${num(vbH)}" `
    + `width="${num(Math.min(vbW, 2000))}" height="${num(Math.min(vbH, 2000))}">`);
  // Comment with provenance
  parts.push(`<title>${escapeXml(currentName || 'drawing')}</title>`);
  // Outer flip group: flips Y so we can use original CAD coordinates
  parts.push(`<g transform="scale(1 -1)" fill="none" stroke="currentColor" `
    + `stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke">`);

  for (const e of entities) parts.push(entityToSvg(e));

  parts.push(`</g>`);
  parts.push(`</svg>`);

  const svg = parts.join('\n');
  const blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const base = (currentName || 'drawing').replace(/\.(dxf|dwg|cgm|cgmtx|txt)$/i, '');
  a.href = url;
  a.download = base + '.svg';
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 200);
}

function num(n) {
  // Avoid scientific notation and unnecessary precision in SVG
  if (!isFinite(n)) return '0';
  const r = Math.round(n * 1000) / 1000;
  return String(r);
}

function entityToSvg(e) {
  const stroke = e.stroke || e.color || 'currentColor';
  const fill = e.fill || 'none';
  const sw = e.width && e.width > 0 ? e.width : null;
  // strokeAttr is just stroke + optional stroke-width override
  const strokeAttr = sw
    ? `stroke="${stroke}" stroke-width="${num(sw)}"`
    : `stroke="${stroke}"`;
  const fillAttr = `fill="${fill}"`;

  switch (e.kind) {
    case 'line':
      return `<line x1="${num(e.x1)}" y1="${num(e.y1)}" `
        + `x2="${num(e.x2)}" y2="${num(e.y2)}" ${strokeAttr}/>`;
    case 'polyline':
      if (e.closed) {
        // A closed polyline is structurally a polygon with no fill
        return `<polygon points="${
          e.points.map(p => `${num(p.x)},${num(p.y)}`).join(' ')
        }" ${strokeAttr} fill="none"/>`;
      }
      return `<polyline points="${
        e.points.map(p => `${num(p.x)},${num(p.y)}`).join(' ')
      }" ${strokeAttr} fill="none"/>`;
    case 'polygon':
      return `<polygon points="${
        e.points.map(p => `${num(p.x)},${num(p.y)}`).join(' ')
      }" ${strokeAttr} ${fillAttr}/>`;
    case 'rect':
      return `<rect x="${num(e.x)}" y="${num(e.y)}" `
        + `width="${num(e.w)}" height="${num(e.h)}" ${strokeAttr} ${fillAttr}/>`;
    case 'circle':
      return `<circle cx="${num(e.cx)}" cy="${num(e.cy)}" r="${num(e.r)}" `
        + `${strokeAttr} ${fillAttr}/>`;
    case 'ellipse': {
      const deg = num(((e.rotation || 0) * 180 / Math.PI));
      return `<ellipse cx="${num(e.cx)}" cy="${num(e.cy)}" `
        + `rx="${num(e.rx)}" ry="${num(e.ry)}" `
        + `transform="rotate(${deg} ${num(e.cx)} ${num(e.cy)})" `
        + `${strokeAttr} ${fillAttr}/>`;
    }
    case 'arc': {
      const a1 = e.startAngle, a2 = e.endAngle;
      // SVG arc path. We're inside a scale(1,-1) outer group, which
      // corresponds to CAD's CCW convention, so sweep-flag=1 here means
      // "go counter-clockwise in CAD math" — matching CGM/DXF arcs.
      const x1 = e.cx + e.r * Math.cos(a1);
      const y1 = e.cy + e.r * Math.sin(a1);
      const x2 = e.cx + e.r * Math.cos(a2);
      const y2 = e.cy + e.r * Math.sin(a2);
      let sweep = a2 - a1;
      sweep = ((sweep % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
      const large = sweep > Math.PI ? 1 : 0;
      return `<path d="M ${num(x1)} ${num(y1)} A ${num(e.r)} ${num(e.r)} 0 `
        + `${large} 1 ${num(x2)} ${num(y2)}" ${strokeAttr} fill="none"/>`;
    }
    case 'point':
      return `<circle cx="${num(e.x)}" cy="${num(e.y)}" r="0.5" `
        + `fill="${stroke}" stroke="none"/>`;
    case 'image': {
      // Same affine transform as canvas rendering: (0,0)→P, (nx,0)→R, (nx,ny)→Q.
      // The outer <g transform="scale(1 -1)"> handles the CAD y-up convention,
      // so this matrix uses CAD coordinates directly.
      const a = (e.R.x - e.P.x) / e.nx;
      const b = (e.R.y - e.P.y) / e.nx;
      const c = (e.Q.x - e.R.x) / e.ny;
      const d = (e.Q.y - e.R.y) / e.ny;
      const dataUrl = e.canvas.toDataURL();
      return `<image href="${dataUrl}" width="${e.nx}" height="${e.ny}" `
        + `transform="matrix(${num(a)} ${num(b)} ${num(c)} ${num(d)} `
        + `${num(e.P.x)} ${num(e.P.y)})" `
        + `preserveAspectRatio="none" image-rendering="pixelated"/>`;
    }
    case 'text': {
      // Counter-flip glyphs so text reads upright while still positioned in
      // CAD coords. Transforms compose right-to-left in effect:
      //   scale(1 -1) — un-flip glyphs
      //   rotate(θ)   — apply CAD rotation
      //   translate   — move to CAD position
      const deg = num(((e.rotation || 0) * 180 / Math.PI));
      const t = `translate(${num(e.x)} ${num(e.y)}) rotate(${deg}) scale(1 -1)`;
      const anchor = e.anchor || 'start';
      const baseline = e.baseline || 'alphabetic';
      const color = e.color || stroke;
      return `<text x="0" y="0" font-size="${num(Math.max(e.size || 1, 0.5))}" `
        + `font-family="sans-serif" fill="${color}" stroke="none" `
        + `text-anchor="${anchor}" dominant-baseline="${baseline}" `
        + `transform="${t}">${escapeXml(e.text || '')}</text>`;
    }
  }
  return '';
}

function escapeXml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&apos;'
  }[c]));
}

/* -------------------------------------------------------------
   3D parsers — STL (binary + ASCII) and OBJ
   -------------------------------------------------------------
   Both produce a Three.js BufferGeometry. STL is naturally
   un-indexed (a flat list of triangle vertices); OBJ has explicit
   vertex re-use via face indices. Vertex normals are computed
   afterwards if the source didn't supply them.
   ------------------------------------------------------------- */

function parseStlBinary(buffer) {
  const dv = new DataView(buffer);
  if (buffer.byteLength < 84) throw new Error('STL file too short');
  const triCount = dv.getUint32(80, true);
  const expected = 84 + triCount * 50;
  if (buffer.byteLength < expected) {
    throw new Error(`STL claims ${triCount} triangles but file is only ${buffer.byteLength} bytes`);
  }
  const positions = new Float32Array(triCount * 9);
  const normals = new Float32Array(triCount * 9);
  let off = 84, p = 0;
  for (let i = 0; i < triCount; i++) {
    const nx = dv.getFloat32(off, true), ny = dv.getFloat32(off + 4, true), nz = dv.getFloat32(off + 8, true);
    off += 12;
    for (let v = 0; v < 3; v++) {
      positions[p]     = dv.getFloat32(off, true);
      positions[p + 1] = dv.getFloat32(off + 4, true);
      positions[p + 2] = dv.getFloat32(off + 8, true);
      normals[p]       = nx;
      normals[p + 1]   = ny;
      normals[p + 2]   = nz;
      p += 3; off += 12;
    }
    off += 2;  // skip "attribute byte count"
  }
  return { positions, normals, triangles: triCount };
}

function parseStlAscii(text) {
  // Pull every "vertex x y z" triplet; ignore facet normals (we'll recompute).
  const positions = [];
  const re = /vertex\s+(-?[\d.eE+-]+)\s+(-?[\d.eE+-]+)\s+(-?[\d.eE+-]+)/g;
  let m;
  while ((m = re.exec(text)) !== null) {
    positions.push(parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]));
  }
  if (positions.length < 9 || positions.length % 9 !== 0) {
    throw new Error(`ASCII STL has ${positions.length / 3} vertices — not a multiple of 3`);
  }
  return { positions: new Float32Array(positions), normals: null, triangles: positions.length / 9 };
}

function parseObj(text) {
  // Just positions and faces; ignore materials, groups, smoothing, normals, UVs.
  // Faces can be polygons — fan-triangulate. Indices are 1-based; negatives mean
  // relative-from-end. Face vertex tokens may be "v", "v/vt", "v/vt/vn", or "v//vn".
  const verts = [];     // [x, y, z, x, y, z, …]
  const indices = [];   // 0-based triangle indices into verts
  const lines = text.split(/\r?\n/);
  let triCount = 0;
  for (const raw of lines) {
    const line = raw.trim();
    if (!line || line.charCodeAt(0) === 0x23 /* # */) continue;
    const sp = line.indexOf(' ');
    if (sp < 0) continue;
    const cmd = line.slice(0, sp);
    if (cmd === 'v') {
      const parts = line.slice(sp + 1).split(/\s+/);
      verts.push(parseFloat(parts[0]), parseFloat(parts[1]), parseFloat(parts[2]));
    } else if (cmd === 'f') {
      const parts = line.slice(sp + 1).split(/\s+/);
      const vCount = verts.length / 3;
      const idx = [];
      for (const tok of parts) {
        const slash = tok.indexOf('/');
        const vstr = slash < 0 ? tok : tok.slice(0, slash);
        let n = parseInt(vstr, 10);
        if (isNaN(n)) continue;
        if (n < 0) n = vCount + n; else n = n - 1;
        if (n >= 0 && n < vCount) idx.push(n);
      }
      // Fan-triangulate: (0, i, i+1)
      for (let i = 1; i + 1 < idx.length; i++) {
        indices.push(idx[0], idx[i], idx[i + 1]);
        triCount++;
      }
    }
  }
  if (!verts.length || !indices.length) {
    throw new Error('OBJ has no usable vertices or faces');
  }
  return {
    positions: new Float32Array(verts),
    indices: verts.length / 3 < 65536 ? new Uint16Array(indices) : new Uint32Array(indices),
    normals: null,
    triangles: triCount,
  };
}

function buildGeometry(parsed) {
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(parsed.positions, 3));
  if (parsed.indices) g.setIndex(new THREE.BufferAttribute(parsed.indices, 1));
  if (parsed.normals) g.setAttribute('normal', new THREE.BufferAttribute(parsed.normals, 3));
  else g.computeVertexNormals();
  g.computeBoundingSphere();
  return g;
}

/* -------------------------------------------------------------
   3D scene — Three.js renderer, camera, OrbitControls, lighting
   ------------------------------------------------------------- */

function init3d() {
  if (renderer3d) return;

  renderer3d = new THREE.WebGLRenderer({ canvas: canvas3d, antialias: true, alpha: true });
  renderer3d.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

  scene3d = new THREE.Scene();
  // Background mirrors --surface so dark mode stays consistent
  const surface = getComputedStyle(document.documentElement).getPropertyValue('--surface').trim();
  if (surface) scene3d.background = new THREE.Color(surface);

  camera3d = new THREE.PerspectiveCamera(45, 1, 0.1, 10000);
  camera3d.position.set(50, 50, 50);

  // Lighting: ambient fill plus two opposing directionals so back-faces
  // aren't pitch-black when models are viewed from behind
  scene3d.add(new THREE.AmbientLight(0xffffff, 0.55));
  const key = new THREE.DirectionalLight(0xffffff, 0.65);
  key.position.set(5, 10, 7);
  scene3d.add(key);
  const fill = new THREE.DirectionalLight(0xffffff, 0.25);
  fill.position.set(-5, -3, -7);
  scene3d.add(fill);

  controls3d = new OrbitControls(camera3d, canvas3d);
  controls3d.enableDamping = true;
  controls3d.dampingFactor = 0.1;

  anim3dRunning = true;
  const tick = () => {
    if (!anim3dRunning) return;
    requestAnimationFrame(tick);
    controls3d.update();
    renderer3d.render(scene3d, camera3d);
  };
  tick();
}

function loadMesh3d(object3d) {
  init3d();
  // Dispose the previous root (and any wireframe overlay) before swapping
  if (mesh3d) {
    scene3d.remove(mesh3d);
    disposeObject3d(mesh3d);
    mesh3d = null;
  }
  if (wireOverlay) {
    scene3d.remove(wireOverlay);
    disposeObject3d(wireOverlay);
    wireOverlay = null;
  }

  mesh3d = object3d;
  scene3d.add(mesh3d);

  // Apply current wireframe toggle state to the new root
  applyWireframe();

  fitView3d();
  resize3d();
}

function disposeObject3d(obj) {
  if (!obj || !obj.traverse) return;
  obj.traverse(child => {
    if (child.geometry) child.geometry.dispose();
    if (child.material) {
      const mats = Array.isArray(child.material) ? child.material : [child.material];
      for (const m of mats) m.dispose();
    }
  });
}

function makeDefault3dMaterial() {
  return new THREE.MeshStandardMaterial({
    color: 0xb0b4ba, metalness: 0.1, roughness: 0.6,
    side: THREE.DoubleSide, flatShading: false,
  });
}

function fitView3d() {
  if (!mesh3d || !camera3d) return;
  // Box3 works for both single Mesh and a Group of Meshes (STEP/IGES case)
  const box = new THREE.Box3().setFromObject(mesh3d);
  if (box.isEmpty()) return;
  const sphere = new THREE.Sphere();
  box.getBoundingSphere(sphere);
  if (!sphere.radius || !isFinite(sphere.radius)) return;
  const fovRad = camera3d.fov * Math.PI / 180;
  const dist = (sphere.radius / Math.sin(fovRad / 2)) * 1.3;
  // Position camera off-axis so the model isn't a flat silhouette on first view
  const dir = new THREE.Vector3(1, 0.7, 1).normalize();
  camera3d.position.copy(sphere.center).addScaledVector(dir, dist);
  camera3d.near = Math.max(sphere.radius * 0.001, 0.01);
  camera3d.far  = sphere.radius * 100;
  camera3d.updateProjectionMatrix();
  controls3d.target.copy(sphere.center);
  controls3d.update();
}

function resize3d() {
  if (!renderer3d) return;
  const wrap = canvas3d.parentElement;
  const w = wrap.clientWidth, h = wrap.clientHeight;
  renderer3d.setSize(w, h, false);
  camera3d.aspect = w / h;
  camera3d.updateProjectionMatrix();
}

function dispose3d() {
  // Stop the render loop and free GPU resources. Re-creation happens
  // lazily on the next 3D file load via init3d().
  anim3dRunning = false;
  if (mesh3d) { disposeObject3d(mesh3d); mesh3d = null; }
  if (wireOverlay) { disposeObject3d(wireOverlay); wireOverlay = null; }
  if (renderer3d) { renderer3d.dispose(); renderer3d = null; }
  scene3d = null; camera3d = null; controls3d = null;
}

function applyWireframe() {
  // Always tear down the previous overlay and reset visibility flags
  if (wireOverlay) {
    scene3d.remove(wireOverlay);
    disposeObject3d(wireOverlay);
    wireOverlay = null;
  }
  if (!mesh3d) return;
  mesh3d.traverse(child => { if (child.isMesh) child.visible = true; });
  if (!wireframeOn) return;

  // Build an edges-overlay group containing one LineSegments per Mesh in
  // the root. EdgesGeometry suppresses interior triangulation lines so we
  // get clean silhouette + feature edges. Hide the solid meshes underneath.
  const fg = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#222';
  const lineMat = new THREE.LineBasicMaterial({ color: new THREE.Color(fg) });
  wireOverlay = new THREE.Group();
  mesh3d.updateMatrixWorld(true);
  mesh3d.traverse(child => {
    if (child.isMesh && child.geometry) {
      const eg = new THREE.EdgesGeometry(child.geometry, 20);
      const lines = new THREE.LineSegments(eg, lineMat);
      // Bake the mesh's world transform into the overlay so nested transforms
      // (multi-part STEP assemblies) line up exactly with their solid parents
      lines.matrixAutoUpdate = false;
      lines.matrix.copy(child.matrixWorld);
      wireOverlay.add(lines);
      child.visible = false;
    }
  });
  scene3d.add(wireOverlay);
}

/* -------------------------------------------------------------
   3D file loaders + stage
   ------------------------------------------------------------- */

function loadStl(data, isBinary, filename) {
  const parsed = isBinary ? parseStlBinary(data) : parseStlAscii(data);
  const geom = buildGeometry(parsed);
  const mesh = new THREE.Mesh(geom, makeDefault3dMaterial());
  formatOut.textContent = isBinary ? 'STL bin' : 'STL';
  show3dStage(mesh, `${parsed.triangles.toLocaleString()} triangles`);
}

function loadObj(text, filename) {
  const parsed = parseObj(text);
  const geom = buildGeometry(parsed);
  const mesh = new THREE.Mesh(geom, makeDefault3dMaterial());
  formatOut.textContent = 'OBJ';
  show3dStage(mesh, `${(parsed.positions.length / 3).toLocaleString()} verts · ${parsed.triangles.toLocaleString()} triangles`);
}

function load3ds(buffer, filename) {
  // 3DS is a chunked binary format from 3D Studio (DOS-era). Three.js ships
  // a parser that returns a Group with one Mesh per object in the file.
  // We replace the source materials with our standard PBR setup so lighting
  // matches the rest of the viewer, while preserving any diffuse color from
  // the source. Texture references inside the .3ds aren't resolved (their
  // image files aren't bundled in the .3ds file itself), which is fine —
  // we don't render textures here anyway.
  const root = new TDSLoader().parse(buffer, '');
  if (!root || !root.children || !root.children.length) {
    showError('3DS file contains no meshes.');
    return;
  }

  let parts = 0, totalTris = 0;
  root.traverse(c => {
    if (!c.isMesh || !c.geometry) return;
    parts++;
    const idx = c.geometry.index;
    const pos = c.geometry.attributes.position;
    if (idx) totalTris += idx.count / 3;
    else if (pos) totalTris += pos.count / 3;

    // Save diffuse color before disposing the original material(s)
    const oldColor = !Array.isArray(c.material) && c.material && c.material.color
      ? c.material.color.clone() : null;
    if (Array.isArray(c.material)) c.material.forEach(mm => mm.dispose && mm.dispose());
    else if (c.material && c.material.dispose) c.material.dispose();

    const newMat = makeDefault3dMaterial();
    if (oldColor) newMat.color.copy(oldColor);
    c.material = newMat;

    if (!c.geometry.attributes.normal) c.geometry.computeVertexNormals();
  });

  if (!parts) {
    showError('3DS file parsed but contained no renderable meshes.');
    return;
  }

  formatOut.textContent = '3DS';
  const partWord = parts === 1 ? 'part' : 'parts';
  show3dStage(root, `${parts} ${partWord} · ${Math.floor(totalTris).toLocaleString()} triangles`);
}

function show3dStage(object3d, metaText) {
  drop.classList.add('hidden');
  msg.classList.add('hidden');
  stage.classList.remove('hidden');
  // Swap canvases: hide the 2D one, show 3D
  canvas.classList.add('hidden');
  canvas3d.classList.remove('hidden');
  // Toolbar mode: SVG export off, measure off, wireframe toggle on
  exportSvgBtn.classList.add('hidden');
  measureBtn.classList.add('hidden');
  clearMeasureBtn.classList.add('hidden');
  if (measureMode) toggleMeasureMode();
  wireframeBtn.classList.remove('hidden');
  hintEl.textContent = 'Drag to rotate · Scroll to zoom · Right-drag to pan';
  coordsEl.textContent = '';
  meta.textContent = metaText;
  mode = '3d';
  loadMesh3d(object3d);
}

/* -------------------------------------------------------------
   STEP / IGES via occt-import-js (lazy-loaded WASM)
   -------------------------------------------------------------
   OpenCASCADE compiled to WebAssembly (~10MB) — fetched only when the
   user actually drops a STEP or IGES file, then cached for subsequent
   files in the session. The script-tag loader avoids ESM/UMD friction
   with how Emscripten resolves its sibling .wasm. If the network load
   fails (offline, blocked CDN, etc.), we fall back to the conversion-
   guidance message so users still know what to do.
   ------------------------------------------------------------- */

const OCCT_VERSION = '0.0.22';
const OCCT_BASE = `https://cdn.jsdelivr.net/npm/occt-import-js@${OCCT_VERSION}/dist`;
let occtPromise = null;

function loadOcct() {
  if (occtPromise) return occtPromise;
  occtPromise = new Promise((resolve, reject) => {
    // Inject as a classic script — this is UMD-ish Emscripten output and
    // exposes a `occtimportjs` global once loaded.
    const script = document.createElement('script');
    script.src = `${OCCT_BASE}/occt-import-js.js`;
    script.onload = async () => {
      try {
        const factory = window.occtimportjs;
        if (typeof factory !== 'function') {
          throw new Error('occt-import-js global not found after script load');
        }
        // locateFile points the WASM fetch at the same CDN as the JS
        const occt = await factory({
          locateFile: (filename) => `${OCCT_BASE}/${filename}`,
        });
        resolve(occt);
      } catch (err) {
        reject(err);
      }
    };
    script.onerror = () => reject(new Error('Failed to fetch occt-import-js script'));
    document.head.appendChild(script);
  });
  // If loading throws, clear the cached promise so the user can retry by
  // dropping another STEP/IGES file (e.g. after fixing connectivity)
  occtPromise.catch(() => { occtPromise = null; });
  return occtPromise;
}

async function loadOcctFile(buffer, filename, kind) {
  showLoadingMessage(filename, `Loading ${kind} parser (~10MB on first use)…`);
  let occt;
  try {
    occt = await loadOcct();
  } catch (err) {
    // Graceful fallback: same conversion-guidance UI users would have
    // seen before WASM support landed
    console.warn('occt-import-js load failed:', err);
    showProprietary3dMessage(filename, kind);
    return;
  }
  showLoadingMessage(filename, `Parsing ${kind} file…`);
  // Yield a frame so the "Parsing…" message actually paints before the
  // synchronous parse blocks the main thread
  await new Promise(r => setTimeout(r, 16));

  let result;
  try {
    result = kind === 'STEP'
      ? occt.ReadStepFile(new Uint8Array(buffer), null)
      : occt.ReadIgesFile(new Uint8Array(buffer), null);
  } catch (err) {
    showError(`${kind} parser threw: ${err.message || err}`);
    return;
  }
  if (!result || !result.success || !result.meshes || !result.meshes.length) {
    showError(`The ${kind} parser produced no renderable meshes — file may be empty, malformed, or use unsupported features.`);
    return;
  }

  // Build a Group of Three.js meshes — one per occt mesh — preserving
  // per-part colors so assembly structure stays visible.
  const group = new THREE.Group();
  let totalTris = 0;
  for (const md of result.meshes) {
    if (!md.attributes || !md.attributes.position || !md.attributes.position.array) continue;
    const geom = new THREE.BufferGeometry();

    const posSrc = md.attributes.position.array;
    const positions = posSrc instanceof Float32Array ? posSrc : new Float32Array(posSrc);
    geom.setAttribute('position', new THREE.BufferAttribute(positions, 3));

    if (md.attributes.normal && md.attributes.normal.array) {
      const normSrc = md.attributes.normal.array;
      const normals = normSrc instanceof Float32Array ? normSrc : new Float32Array(normSrc);
      geom.setAttribute('normal', new THREE.BufferAttribute(normals, 3));
    }

    if (md.index && md.index.array) {
      const idxSrc = md.index.array;
      const ArrType = positions.length / 3 < 65536 ? Uint16Array : Uint32Array;
      const indices = idxSrc instanceof ArrType ? idxSrc : new ArrType(idxSrc);
      geom.setIndex(new THREE.BufferAttribute(indices, 1));
      totalTris += idxSrc.length / 3;
    } else {
      totalTris += positions.length / 9;  // unindexed triangles
    }

    if (!geom.attributes.normal) geom.computeVertexNormals();

    const material = makeDefault3dMaterial();
    if (md.color && md.color.length >= 3) {
      material.color.setRGB(md.color[0], md.color[1], md.color[2]);
    }
    group.add(new THREE.Mesh(geom, material));
  }

  if (!group.children.length) {
    showError(`The ${kind} parser returned meshes, but none had position data.`);
    return;
  }

  formatOut.textContent = kind;
  const partWord = group.children.length === 1 ? 'part' : 'parts';
  show3dStage(group, `${group.children.length} ${partWord} · ${totalTris.toLocaleString()} triangles`);
}

function showLoadingMessage(filename, status) {
  drop.classList.add('hidden'); stage.classList.add('hidden');
  msg.classList.remove('hidden');
  msg.className = 'msg info';
  msg.innerHTML =
    `<div class="title">${escapeHtml(filename)}</div>` +
    `<div>${escapeHtml(status)}</div>`;
}

/* -------------------------------------------------------------
   Wireframe toggle button
   ------------------------------------------------------------- */

wireframeBtn.addEventListener('click', () => {
  wireframeOn = !wireframeOn;
  wireframeBtn.textContent = wireframeOn ? 'Solid' : 'Wireframe';
  applyWireframe();
});

/* -------------------------------------------------------------
   Pan / zoom / touch + measurement picks
   ------------------------------------------------------------- */

let dragging = false, lastX = 0, lastY = 0;
canvas.addEventListener('mousedown', e => {
  // In measure mode, left-click is for picking points — don't start a pan
  if (mode === '2d' && measureMode && e.button === 0) return;
  dragging = true; lastX = e.clientX; lastY = e.clientY;
  canvas.classList.add('dragging');
});
window.addEventListener('mouseup', () => { dragging = false; canvas.classList.remove('dragging'); });
window.addEventListener('mousemove', e => {
  if (!dragging) return;
  view.tx += e.clientX - lastX;
  view.ty += e.clientY - lastY;
  lastX = e.clientX; lastY = e.clientY;
  render();
});

// Track cursor over the 2D canvas for ruler highlights, status-bar coords,
// and the live preview while a measurement is being picked
canvas.addEventListener('mousemove', e => {
  if (mode !== '2d') return;
  const rect = canvas.getBoundingClientRect();
  cursorScreen = { x: e.clientX - rect.left, y: e.clientY - rect.top };
  cursorWorld = screenToWorld(cursorScreen.x, cursorScreen.y);
  if (cursorScreen.x >= RULER_PX && cursorScreen.y >= RULER_PX) {
    coordsEl.textContent = `${fmtCoord(cursorWorld.x)}, ${fmtCoord(cursorWorld.y)}`;
  } else {
    coordsEl.textContent = '';
  }
  // Re-render to update ruler cursor stripes / measurement preview
  render();
});
canvas.addEventListener('mouseleave', () => {
  cursorScreen = null;
  cursorWorld = null;
  coordsEl.textContent = '';
  if (mode === '2d') render();
});

// Click in measure mode: pick first point, then second to commit
canvas.addEventListener('click', e => {
  if (mode !== '2d' || !measureMode) return;
  if (!cursorWorld) return;
  // Ignore clicks inside the ruler strips
  const rect = canvas.getBoundingClientRect();
  const cx = e.clientX - rect.left, cy = e.clientY - rect.top;
  if (cx < RULER_PX || cy < RULER_PX) return;

  if (!measurePending) {
    measurePending = { x: cursorWorld.x, y: cursorWorld.y };
  } else {
    measurements.push({
      p1: measurePending,
      p2: { x: cursorWorld.x, y: cursorWorld.y },
    });
    measurePending = null;
    clearMeasureBtn.classList.remove('hidden');
  }
  render();
});

window.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!measureMode) return;
  if (measurePending) {
    // First Esc cancels the in-progress pick
    measurePending = null;
    render();
  } else {
    // Second Esc exits measure mode entirely
    toggleMeasureMode();
  }
});

canvas.addEventListener('wheel', e => {
  e.preventDefault();
  const rect = canvas.getBoundingClientRect();
  const mx = e.clientX - rect.left, my = e.clientY - rect.top;
  const factor = Math.exp(-e.deltaY * 0.0015);
  view.tx = mx + (view.tx - mx) * factor;
  view.ty = my + (view.ty - my) * factor;
  view.scale *= factor;
  // Zoom changes which world coords sit under the cursor — refresh readout
  if (cursorScreen) {
    cursorWorld = screenToWorld(cursorScreen.x, cursorScreen.y);
    if (cursorScreen.x >= RULER_PX && cursorScreen.y >= RULER_PX) {
      coordsEl.textContent = `${fmtCoord(cursorWorld.x)}, ${fmtCoord(cursorWorld.y)}`;
    }
  }
  render();
}, { passive: false });

/* -------------------------------------------------------------
   Measure-mode toolbar buttons
   ------------------------------------------------------------- */

measureBtn.addEventListener('click', toggleMeasureMode);
clearMeasureBtn.addEventListener('click', () => {
  measurements = [];
  measurePending = null;
  clearMeasureBtn.classList.add('hidden');
  render();
});

function toggleMeasureMode() {
  measureMode = !measureMode;
  measurePending = null;
  measureBtn.classList.toggle('active', measureMode);
  measureBtn.textContent = measureMode ? 'Stop' : 'Measure';
  canvas.classList.toggle('measuring', measureMode);
  if (mode === '2d') {
    hintEl.textContent = measureMode
      ? 'Click two points to measure · Esc to cancel'
      : 'Drag to pan · Scroll to zoom';
  }
  // Reveal Clear if there are any measurements to clear
  if (measurements.length) clearMeasureBtn.classList.remove('hidden');
  if (mode === '2d') render();
}

let touchState = null;
canvas.addEventListener('touchstart', e => {
  if (e.touches.length === 1) {
    touchState = { mode: 'pan', x: e.touches[0].clientX, y: e.touches[0].clientY };
  } else if (e.touches.length === 2) {
    const t1 = e.touches[0], t2 = e.touches[1];
    touchState = { mode: 'pinch',
      d: Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY),
      cx: (t1.clientX + t2.clientX) / 2, cy: (t1.clientY + t2.clientY) / 2 };
  }
}, { passive: true });
canvas.addEventListener('touchmove', e => {
  if (!touchState) return;
  e.preventDefault();
  if (touchState.mode === 'pan' && e.touches.length === 1) {
    view.tx += e.touches[0].clientX - touchState.x;
    view.ty += e.touches[0].clientY - touchState.y;
    touchState.x = e.touches[0].clientX;
    touchState.y = e.touches[0].clientY;
    render();
  } else if (touchState.mode === 'pinch' && e.touches.length === 2) {
    const t1 = e.touches[0], t2 = e.touches[1];
    const d = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
    const factor = d / touchState.d;
    const rect = canvas.getBoundingClientRect();
    const mx = touchState.cx - rect.left, my = touchState.cy - rect.top;
    view.tx = mx + (view.tx - mx) * factor;
    view.ty = my + (view.ty - my) * factor;
    view.scale *= factor;
    touchState.d = d;
    render();
  }
}, { passive: false });
canvas.addEventListener('touchend', () => { touchState = null; }, { passive: true });

if (window.ResizeObserver) {
  new ResizeObserver(() => {
    if (stage.classList.contains('hidden')) return;
    if (mode === '3d') resize3d();
    else { resizeCanvas(); render(); }
  }).observe(canvas.parentElement);
}

/* -------------------------------------------------------------
   Auto-load shim — fetch ?att_id=N and call handleFile()
   ------------------------------------------------------------- */

(async function autoLoadFromAttachment() {
  const cfg = window.CAD_VIEWER_AUTOLOAD;
  if (!cfg || !cfg.att_id) return;

  // Pick the right attachment endpoint based on src. Browser sends
  // session cookies automatically; if the user can't view the
  // attachment the endpoint returns 403 and we surface a load error.
  const base = (cfg.base || '').replace(/\/+$/, '');
  const endpoint = cfg.src === 'inspection' ? '/inspection_attach.php' : '/note_attach.php';
  const url  = base + endpoint + '?id=' + encodeURIComponent(cfg.att_id);

  // Hide the drop UI immediately and show a loading state.
  drop.classList.add('hidden');
  msg.classList.remove('hidden');
  msg.className = 'msg info';
  msg.innerHTML = '<div class="title">Loading…</div><div>Fetching attachment from the server.</div>';

  try {
    const resp = await fetch(url, { credentials: 'same-origin' });
    if (!resp.ok) {
      msg.className = 'msg danger';
      msg.innerHTML = '<div class="title">Couldn\'t load attachment</div>'
        + '<div>Server returned ' + resp.status + ' ' + resp.statusText + '.</div>';
      return;
    }
    // Try to recover the original filename from the Content-Disposition
    // header so the viewer can label the file correctly. note_attach.php
    // emits Content-Disposition: inline|attachment; filename="..."
    let filename = 'attachment';
    const cd = resp.headers.get('Content-Disposition');
    if (cd) {
      const m = cd.match(/filename="?([^";]+)"?/i);
      if (m) filename = m[1];
    }
    const blob = await resp.blob();
    // Wrap blob as a File so handleFile (which reads f.name/f.size) works.
    const f = new File([blob], filename, { type: blob.type || 'application/octet-stream' });
    msg.classList.add('hidden');
    handleFile(f);
  } catch (err) {
    msg.className = 'msg danger';
    msg.innerHTML = '<div class="title">Couldn\'t load attachment</div>'
      + '<div>' + escapeHtml(err.message || String(err)) + '</div>';
  }
})();
</script>
</body>
</html>
