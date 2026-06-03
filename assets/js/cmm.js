/* MagDyn — CMM Analyzer frontend (port of cam_cmm_analyzer/public/app.js)
 *
 * - Upload page (cmm.php?action=list): drag/drop PDF, parse with pdf.js (or
 *   OCR fallback), POST extracted data to ?action=save, then redirect.
 * - View page (cmm.php?action=view&id=N): fetch ?action=api_run&id=N and
 *   render 5 Plotly charts; comment editor talks to ?action=api_comment&id=N.
 *
 * Element IDs are all prefixed `cmm*` so this won't collide with other
 * MagDyn pages on a shared SPA shell.
 *
 * Required globals (set by cmm.php):
 *   window.CMM_SAVE_URL   - POST endpoint for analyze
 *   window.CMM_VIEW_URL   - prefix for the view page (id is appended)
 *   window.CMM_API_RUN    - prefix for the run-json endpoint
 *   window.CMM_API_CMT    - prefix for the comment endpoint
 *   window.CMM_RUN_ID     - id of the current run (view page only)
 *   window.CMM_UPPER_TOL  - upper tolerance of the current run (view page)
 */

(function () {
  'use strict';

  const PDFJS_VERSION = '4.5.136';
  const PDFJS_BASE    = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${PDFJS_VERSION}`;
  const TESSERACT_URL = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
  const MIN_POINTS_FOR_TEXT_PARSE = 20;
  const OCR_RENDER_SCALE = 2.0;

  /* ------------ pdf.js loader ------------ */
  let pdfjsLibPromise = null;
  function loadPdfJs() {
    if (pdfjsLibPromise) return pdfjsLibPromise;
    pdfjsLibPromise = import(/* @vite-ignore */ `${PDFJS_BASE}/pdf.min.mjs`).then(mod => {
      const lib = mod.default || mod;
      lib.GlobalWorkerOptions.workerSrc = `${PDFJS_BASE}/pdf.worker.min.mjs`;
      return lib;
    });
    return pdfjsLibPromise;
  }

  /* ------------ Tesseract loader ------------ */
  let tesseractPromise = null;
  function loadTesseract() {
    if (tesseractPromise) return tesseractPromise;
    tesseractPromise = new Promise((resolve, reject) => {
      if (window.Tesseract) return resolve(window.Tesseract);
      const s = document.createElement('script');
      s.src = TESSERACT_URL;
      s.onload  = () => resolve(window.Tesseract);
      s.onerror = () => reject(new Error('Failed to load Tesseract.js from CDN'));
      document.head.appendChild(s);
    });
    return tesseractPromise;
  }

  /* ------------ PDF.JS text extraction ------------ */
  async function extractTextWithPdfJs(file) {
    const lib = await loadPdfJs();
    const buf = await file.arrayBuffer();
    const pdf = await lib.getDocument({ data: buf }).promise;
    const allLines = [];
    for (let p = 1; p <= pdf.numPages; p++) {
      const page = await pdf.getPage(p);
      const tc = await page.getTextContent();
      let bucket = [];
      for (const it of tc.items) {
        if (it.hasEOL) { if (bucket.length) allLines.push(bucket); bucket = []; continue; }
        bucket.push(it);
      }
      if (bucket.length) allLines.push(bucket);
    }
    return allLines.map(items => {
      items.sort((a, b) => a.transform[4] - b.transform[4]);
      let out = '';
      let prevEnd = -Infinity;
      for (const it of items) {
        const x = it.transform[4];
        const w = it.width || 0;
        if (out.length) {
          const gap = x - prevEnd;
          if (gap > 1.5 && !out.endsWith(' ') && !/^\s/.test(it.str)) out += ' ';
        }
        out += it.str;
        prevEnd = x + w;
      }
      return out.replace(/\s+/g, ' ').trim();
    }).join('\n');
  }

  /* ------------ OCR fallback ------------ */
  async function extractTextWithOcr(file, onProgress) {
    const lib = await loadPdfJs();
    const Tess = await loadTesseract();
    const buf = await file.arrayBuffer();
    const pdf = await lib.getDocument({ data: buf }).promise;
    const worker = await Tess.createWorker('eng', 1, {
      logger: m => {
        if (onProgress && m.progress != null) {
          onProgress({ stage: m.status || 'recognizing', progress: m.progress });
        }
      }
    });
    let allText = '';
    for (let p = 1; p <= pdf.numPages; p++) {
      if (onProgress) onProgress({ stage: `Rasterising page ${p}/${pdf.numPages}`, progress: (p - 1) / pdf.numPages });
      const page = await pdf.getPage(p);
      const viewport = page.getViewport({ scale: OCR_RENDER_SCALE });
      const canvas = document.createElement('canvas');
      canvas.width  = viewport.width;
      canvas.height = viewport.height;
      const ctx = canvas.getContext('2d');
      await page.render({ canvasContext: ctx, viewport }).promise;
      if (onProgress) onProgress({ stage: `OCR page ${p}/${pdf.numPages}`, progress: (p - 0.5) / pdf.numPages });
      const { data } = await worker.recognize(canvas);
      allText += '\n' + (data.text || '');
    }
    await worker.terminate();
    if (onProgress) onProgress({ stage: 'OCR complete', progress: 1 });
    return allText;
  }

  /* ------------ Meta + point extraction ------------ */
  function extractMeta(text) {
    const meta = { report_date: null, part_number: null, cmm_type: null, operator: null, feature_name: null };
    let m;
    if ((m = text.match(/Date\s+([A-Z][a-z]+\s+\d{1,2},\s+\d{4})/))) meta.report_date = m[1].trim();
    if ((m = text.match(/Part Number\s+(?:CMM Type[^\n]*)?\s*\n\s*(\d+)/))) meta.part_number = m[1].trim();
    else if ((m = text.match(/Part Number\s+(\d+)/))) meta.part_number = m[1].trim();
    if ((m = text.match(/(SPECTRUM\w+)/))) meta.cmm_type = m[1].trim();
    if ((m = text.match(/Operator\s+(\w+)/))) meta.operator = m[1].trim();
    if ((m = text.match(/Measurement Plan\s*\n\s*([^\r\n]+)/))) meta.feature_name = m[1].trim();
    else if ((m = text.match(/(Curve Form\d+)/))) meta.feature_name = m[1].trim();
    return meta;
  }

  function extractPoints(text) {
    const lines = text.split(/\r?\n/);
    const n = lines.length;
    const points = [];
    const rxX = /^\s*(\d+)(?:\/(Min|Max))?\s+X\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)/;
    const rxY = /^\s*Y\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)/;
    const rxZ = /^\s*Z\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)/;
    const rxD = /^\s*Dist\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)/;
    let upperTol = 0.0005, lowerTol = -0.0005;
    let i = 0;
    while (i < n) {
      const mx = lines[i].match(rxX);
      if (mx) {
        const idx = parseInt(mx[1], 10);
        const tag = mx[2] || null;
        const xA = +mx[3], xN = +mx[4], xD = +mx[5];
        let iy=-1, iz=-1, id=-1, yA, yN, yD, zA, zN, zD, dA, dU, dL, dD;
        for (let j = i + 1; j < Math.min(i + 8, n); j++) {
          let my;
          if (iy < 0 && (my = lines[j].match(rxY))) { iy = j; yA = +my[1]; yN = +my[2]; yD = +my[3]; }
          else if (iy >= 0 && iz < 0 && (my = lines[j].match(rxZ))) { iz = j; zA = +my[1]; zN = +my[2]; zD = +my[3]; }
          else if (iz >= 0 && id < 0 && (my = lines[j].match(rxD))) { id = j; dA = +my[1]; dU = +my[2]; dL = +my[3]; dD = +my[4]; break; }
        }
        if (iy >= 0 && iz >= 0 && id >= 0) {
          if (points.length === 0) { upperTol = dU; lowerTol = dL; }
          const oot = (dA > dU) || (dA < dL);
          points.push({
            idx, tag,
            x_actual: xA, x_nominal: xN, x_dev: xD,
            y_actual: yA, y_nominal: yN, y_dev: yD,
            z_actual: zA, z_nominal: zN, z_dev: zD,
            dist_actual: dA, dist_upper: dU, dist_lower: dL, dist_dev: dD,
            out_of_tol: oot,
          });
          i = id + 1;
          continue;
        }
      }
      i++;
    }
    return { points, upperTol, lowerTol };
  }

  /* ------------ Upload page ------------ */
  function initUpload() {
    const form = document.getElementById('cmmUploadForm');
    if (!form) return;
    const dz       = document.getElementById('cmmDropZone');
    const input    = document.getElementById('cmmPdfInput');
    const fileName = document.getElementById('cmmFileName');
    const btn      = document.getElementById('cmmUploadBtn');
    const status   = document.getElementById('cmmStatus');
    const ocrWrap  = document.getElementById('cmmOcrProgress');
    const ocrFill  = document.getElementById('cmmOcrFill');
    const ocrLabel = document.getElementById('cmmOcrLabel');
    const linkTxnId = (document.getElementById('cmmLinkTxnId') || {}).value || '';

    let chosenFile = null;
    function setFile(file) {
      chosenFile = file || null;
      if (!file) { fileName.textContent = ''; btn.disabled = true; return; }
      const mb = (file.size / (1024 * 1024)).toFixed(2);
      fileName.textContent = `${file.name} (${mb} MB)`;
      btn.disabled = false;
    }
    function setStatus(kind, msg) {
      status.style.color = kind === 'err' ? '#b3261e' : '';
      status.textContent = msg || '';
    }
    function setOcrProgress(stage, pct) {
      if (pct == null) { ocrWrap.hidden = true; return; }
      ocrWrap.hidden = false;
      ocrFill.style.width = (Math.max(0, Math.min(1, pct)) * 100).toFixed(1) + '%';
      ocrLabel.textContent = stage;
    }

    input.addEventListener('change', () => setFile(input.files && input.files[0]));

    // Multi-pass checkbox is only relevant when machine type is WEDM.
    // Show/hide the row based on the dropdown selection.
    const machineSelectInit = document.getElementById('cmmMachineType');
    const multipassRow      = document.getElementById('cmmMultipassRow');
    const multipassCheckbox = document.getElementById('cmmIsMultipass');
    function syncMultipassVisibility() {
      if (!machineSelectInit || !multipassRow) return;
      const isWedm = machineSelectInit.value === 'wedm';
      multipassRow.hidden = !isWedm;
      // When switching away from WEDM, clear the checkbox so a re-selection
      // of WEDM doesn't quietly carry the old value forward.
      if (!isWedm && multipassCheckbox) multipassCheckbox.checked = false;
    }
    if (machineSelectInit) {
      machineSelectInit.addEventListener('change', syncMultipassVisibility);
      syncMultipassVisibility();
    }
    ['dragenter', 'dragover'].forEach(ev => {
      dz.addEventListener(ev, e => {
        e.preventDefault(); e.stopPropagation();
        dz.style.background = '#e0f2fe';
      });
    });
    ['dragleave', 'dragend'].forEach(ev => {
      dz.addEventListener(ev, e => {
        e.preventDefault(); e.stopPropagation();
        dz.style.background = '#fafafa';
      });
    });
    dz.addEventListener('drop', e => {
      e.preventDefault(); e.stopPropagation();
      dz.style.background = '#fafafa';
      const dt = e.dataTransfer;
      if (!dt) return;
      const f = (dt.files && dt.files[0]) || null;
      if (f) setFile(f);
    });
    ['dragover', 'drop'].forEach(ev => {
      window.addEventListener(ev, e => { if (!dz.contains(e.target)) e.preventDefault(); });
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const file = chosenFile;
      if (!file) { setStatus('err', 'Please select a PDF file first.'); return; }
      if (!/pdf$/i.test(file.name) && file.type !== 'application/pdf') {
        setStatus('err', "That doesn't look like a PDF file.");
        return;
      }
      // CAPTURE machine type IMMEDIATELY at submit time. Earlier we read
      // this just before the fetch, which is AFTER `await extractTextWithPdfJs()`.
      // Some browsers visibly revert <select> elements on form submit (autofill
      // restoration or BFCache state) — reading early avoids picking up that
      // reset value. Logged for sanity-check in the network payload too.
      const machineSelect = document.getElementById('cmmMachineType');
      const machineTypeAtSubmit = (machineSelect && machineSelect.value) ? machineSelect.value : 'vmc';
      const multipassEl = document.getElementById('cmmIsMultipass');
      const isMultipassAtSubmit = !!(multipassEl && multipassEl.checked && machineTypeAtSubmit === 'wedm');
      console.log('[cmm] machine_type captured at submit:', machineTypeAtSubmit, 'multipass:', isMultipassAtSubmit);

      btn.disabled = true;
      btn.textContent = 'Parsing PDF…';
      setStatus('', '');
      setOcrProgress(null);

      try {
        const text = await extractTextWithPdfJs(file);
        let meta = extractMeta(text);
        let { points, upperTol, lowerTol } = extractPoints(text);
        let extractedVia = 'pdfjs';

        if (points.length < MIN_POINTS_FOR_TEXT_PARSE) {
          btn.textContent = 'No embedded text — running OCR…';
          setOcrProgress('Loading OCR engine…', 0);
          const ocrText = await extractTextWithOcr(file, ({ stage, progress }) => {
            setOcrProgress(stage, progress);
          });
          const m2 = extractMeta(ocrText);
          for (const k of Object.keys(m2)) if (!meta[k] && m2[k]) meta[k] = m2[k];
          const r2 = extractPoints(ocrText);
          if (r2.points.length > points.length) {
            points = r2.points; upperTol = r2.upperTol; lowerTol = r2.lowerTol;
            extractedVia = 'tesseract';
          }
          setOcrProgress(null);
        }

        if (points.length === 0) {
          throw new Error("Couldn't extract any measurement points from this PDF. The format may not match the expected ZEISS Calypso layout, or the image quality is too low for OCR.");
        }

        btn.textContent = `Uploading ${points.length} points…`;
        const payload = {
          filename: file.name,
          size_bytes: file.size,
          extracted_via: extractedVia,
          meta,
          upper_tol: upperTol,
          lower_tol: lowerTol,
          points,
        };
        if (linkTxnId && parseInt(linkTxnId, 10) > 0) {
          payload.link_txn_id = parseInt(linkTxnId, 10);
        }
        // Use the value captured at submit time (see top of handler).
        // Reading from the DOM here would risk picking up a browser-reset
        // value if anything has touched the form during the async work.
        payload.machine_type = machineTypeAtSubmit;
        payload.is_multipass = isMultipassAtSubmit;

        const res = await fetch(window.CMM_SAVE_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const txt = await res.text();
        let data;
        try { data = JSON.parse(txt); }
        catch (_) { throw new Error('Server returned: ' + txt.slice(0, 300)); }
        if (!res.ok || !data.ok) throw new Error(data.error || 'Server upload failed');
        window.location.href = window.CMM_VIEW_URL + encodeURIComponent(data.run_id);
      } catch (err) {
        console.error(err);
        setStatus('err', 'Failed: ' + (err.message || err));
        setOcrProgress(null);
        btn.disabled = false;
        btn.textContent = 'Analyze';
      }
    });
  }

  /* ------------ View page: charts ------------ */
  async function initView() {
    if (!window.CMM_RUN_ID) return;
    if (!document.getElementById('plotProfile')) return;
    try {
      const res = await fetch(window.CMM_API_RUN + encodeURIComponent(window.CMM_RUN_ID));
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load run');
      const upperTol = (typeof window.CMM_UPPER_TOL === 'number' && window.CMM_UPPER_TOL)
        ? window.CMM_UPPER_TOL
        : (parseFloat(data.upper_tol) || 0.0005);
      renderAllPlots(data.points, upperTol);
    } catch (err) {
      console.error(err);
      ['plotProfile','plotDeviation','plotXY','plotHistogram','plotQuadrants'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = '<div style="color:#b3261e; padding:18px;">Could not load chart data: ' + escapeHtml(err.message) + '</div>';
      });
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  const PLOT_CFG = {
    displaylogo: false, responsive: true,
    modeBarButtonsToRemove: ['lasso2d', 'select2d', 'autoScale2d'],
  };
  const LAYOUT_BASE = {
    margin: { l: 60, r: 24, t: 36, b: 50 },
    paper_bgcolor: '#fff', plot_bgcolor: '#fff',
    font: { family: '-apple-system, "Segoe UI", Roboto, sans-serif', size: 12, color: '#1a2330' },
    hovermode: 'closest',
  };

  function renderAllPlots(points, ut) {
    if (!Array.isArray(points) || !points.length) return;
    points = points.slice().sort((a, b) => (a.idx | 0) - (b.idx | 0));
    const idx  = points.map(p => p.idx | 0);
    const xs   = points.map(p => parseFloat(p.x_actual));
    const ys   = points.map(p => parseFloat(p.y_actual));
    const dist = points.map(p => parseFloat(p.dist_actual));
    const xdev = points.map(p => parseFloat(p.x_dev));
    const ydev = points.map(p => parseFloat(p.y_dev));
    plotProfile(xs, ys, dist, ut, idx);
    plotDeviation(idx, dist, ut);
    plotXY(idx, xdev, ydev);
    plotHistogram(dist, ut);
    plotQuadrants(xs, ys, dist, ut);
  }

  function plotProfile(xs, ys, dist, ut, idx) {
    Plotly.newPlot('plotProfile', [{
      x: xs, y: ys, mode: 'markers', type: 'scattergl',
      marker: {
        size: 4, color: dist,
        colorscale: [[0, '#1d4ed8'], [0.5, '#16a34a'], [0.8, '#f59e0b'], [1, '#b3261e']],
        cmin: 0, cmax: ut * 2,
        colorbar: { title: 'Dist dev (in)', thickness: 12, len: 0.7 },
        showscale: true,
      },
      text: idx.map((i, k) => `idx ${i}<br>dist ${dist[k].toFixed(4)}″`),
      hovertemplate: '%{text}<br>X=%{x:.4f}, Y=%{y:.4f}<extra></extra>',
    }], Object.assign({}, LAYOUT_BASE, {
      title: { text: 'Cam profile (X–Y), coloured by distance deviation', font: { size: 14 } },
      xaxis: { title: 'X (in)', zeroline: true, scaleanchor: 'y', scaleratio: 1 },
      yaxis: { title: 'Y (in)', zeroline: true },
      height: 540,
    }), PLOT_CFG);
  }

  function plotDeviation(idx, dist, ut) {
    const color = dist.map(d => d > ut ? '#b3261e' : (d >= ut - 0.00005 ? '#f59e0b' : '#137333'));
    Plotly.newPlot('plotDeviation', [
      { x: idx, y: dist, mode: 'markers', type: 'scattergl',
        marker: { size: 4, color }, name: 'Dist deviation',
        hovertemplate: 'idx %{x}<br>dev %{y:.4f}″<extra></extra>' },
      { x: [idx[0], idx[idx.length - 1]], y: [ut, ut], mode: 'lines',
        line: { color: '#b3261e', dash: 'dash', width: 1.5 }, name: `USL (${ut}″)` },
    ], Object.assign({}, LAYOUT_BASE, {
      title: { text: 'Distance deviation per measurement point', font: { size: 14 } },
      xaxis: { title: 'Measurement index' },
      yaxis: { title: 'Distance deviation (in)' },
      height: 400, showlegend: true, legend: { orientation: 'h', y: -0.2 },
    }), PLOT_CFG);
  }

  function plotXY(idx, xdev, ydev) {
    Plotly.newPlot('plotXY', [
      { x: idx, y: xdev, type: 'scattergl', mode: 'markers',
        marker: { size: 3, color: '#1d4ed8' }, name: 'X dev', xaxis: 'x', yaxis: 'y' },
      { x: idx, y: ydev, type: 'scattergl', mode: 'markers',
        marker: { size: 3, color: '#b06000' }, name: 'Y dev', xaxis: 'x2', yaxis: 'y2' },
    ], Object.assign({}, LAYOUT_BASE, {
      title: { text: 'Signed X and Y deviations per point', font: { size: 14 } },
      grid: { rows: 2, columns: 1, pattern: 'independent' },
      xaxis: { domain: [0, 1] },
      yaxis: { title: 'X dev (in)' },
      xaxis2: { title: 'Measurement index' },
      yaxis2: { title: 'Y dev (in)' },
      height: 480, showlegend: false,
    }), PLOT_CFG);
  }

  function plotHistogram(dist, ut) {
    Plotly.newPlot('plotHistogram', [{
      x: dist, type: 'histogram',
      marker: { color: '#1d4ed8', line: { color: '#fff', width: 1 } },
      xbins: { size: 0.00005 }, name: 'Count',
    }], Object.assign({}, LAYOUT_BASE, {
      title: { text: 'Distribution of distance deviation', font: { size: 14 } },
      xaxis: { title: 'Distance deviation (in)' },
      yaxis: { title: 'Count of points' },
      height: 380,
      shapes: [{ type: 'line', xref: 'x', yref: 'paper',
                  x0: ut, x1: ut, y0: 0, y1: 1,
                  line: { color: '#b3261e', dash: 'dash', width: 2 } }],
      annotations: [{ x: ut, y: 1, xref: 'x', yref: 'paper',
                       text: `USL (${ut}″)`, showarrow: false,
                       font: { color: '#b3261e' }, xanchor: 'left', yanchor: 'top' }],
    }), PLOT_CFG);
  }

  function plotQuadrants(xs, ys, dist, ut) {
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
      xaxis: { domain: [0, 0.46] },
      yaxis: { title: '% OOT', rangemode: 'tozero' },
      xaxis2: { domain: [0.54, 1] },
      yaxis2: { title: 'Mean dev (in)' },
      height: 380, showlegend: false,
      margin: { l: 60, r: 24, t: 50, b: 70 },
    }), PLOT_CFG);
  }

  /* ------------ Comment editor ------------ */
  function initComment() {
    const card = document.getElementById('cmmCommentCard');
    if (!card) return;
    const runId      = card.dataset.runId;
    const viewEl     = document.getElementById('cmmCommentView');
    const editEl     = document.getElementById('cmmCommentEdit');
    const textarea   = document.getElementById('cmmCommentTextarea');
    const editBtn    = document.getElementById('cmmCommentEditBtn');
    const saveBtn    = document.getElementById('cmmCommentSaveBtn');
    const cancelBtn  = document.getElementById('cmmCommentCancelBtn');
    const charCount  = document.getElementById('cmmCommentCharCount');
    const statusEl   = document.getElementById('cmmCommentStatus');
    if (!runId || !viewEl || !editEl || !textarea || !editBtn) return;

    const MAX_LEN = 8000;
    let originalText = textarea.value;

    function setCharCount() {
      if (!charCount) return;
      const n = textarea.value.length;
      charCount.textContent = `${n} / ${MAX_LEN} characters`;
    }
    function setStatus(kind, msg) {
      if (!statusEl) return;
      statusEl.style.color = kind === 'err' ? '#b3261e' : '';
      statusEl.textContent = msg || '';
    }
    function enterEditMode() {
      originalText = textarea.value;
      viewEl.hidden = true; editEl.hidden = false;
      editBtn.hidden = true; saveBtn.hidden = false; cancelBtn.hidden = false;
      setStatus('', ''); setCharCount();
      textarea.focus();
      const v = textarea.value;
      textarea.setSelectionRange(v.length, v.length);
    }
    function exitEditMode() {
      viewEl.hidden = false; editEl.hidden = true;
      editBtn.hidden = false; saveBtn.hidden = true; cancelBtn.hidden = true;
    }
    function renderView(text) {
      const trimmed = (text || '').replace(/\s+$/, '');
      if (trimmed === '') {
        viewEl.classList.add('muted');
        viewEl.innerHTML = '<em>No notes yet. Click <strong>Edit</strong> to add some.</em>';
      } else {
        viewEl.classList.remove('muted');
        viewEl.innerHTML = String(trimmed)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/\n/g, '<br>');
      }
    }

    editBtn.addEventListener('click', enterEditMode);
    cancelBtn.addEventListener('click', () => {
      textarea.value = originalText; exitEditMode();
    });
    textarea.addEventListener('input', setCharCount);

    saveBtn.addEventListener('click', async () => {
      const newText = textarea.value;
      if (newText.length > MAX_LEN) {
        setStatus('err', `Too long (${newText.length} / ${MAX_LEN}).`);
        return;
      }
      saveBtn.disabled = true; cancelBtn.disabled = true;
      setStatus('', 'Saving…');
      try {
        const res = await fetch(window.CMM_API_CMT + encodeURIComponent(runId), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ comment: newText }),
        });
        const txt = await res.text();
        let data;
        try { data = JSON.parse(txt); }
        catch (_) { throw new Error('Server returned: ' + txt.slice(0, 200)); }
        if (!res.ok || !data.ok) throw new Error(data.error || 'Save failed');
        originalText = data.comment;
        renderView(data.comment);
        exitEditMode();
        setStatus('', 'Saved.');
      } catch (err) {
        console.error(err);
        setStatus('err', 'Save failed: ' + (err.message || err));
      } finally {
        saveBtn.disabled = false; cancelBtn.disabled = false;
      }
    });
  }

  /* ------------ Auto-load attachment ------------
   * When window.CMM_AUTO_ATTACHMENT is set (running-notes integration:
   * user clicked "CMM Analyze" on a PDF attachment), fetch the file
   * from its auth-protected URL, wrap it in a File object, drop it
   * into the upload form, and submit. The user lands on the run view
   * once parsing completes.
   * --------------------------------------------- */
  async function initAutoAttachment() {
    if (!window.CMM_AUTO_ATTACHMENT) return;
    const form = document.getElementById('cmmUploadForm');
    const input = document.getElementById('cmmPdfInput');
    const status = document.getElementById('cmmStatus');
    if (!form || !input) return;

    const att = window.CMM_AUTO_ATTACHMENT;
    if (status) { status.style.color = ''; status.textContent = 'Fetching ' + att.filename + '…'; }

    try {
      const res = await fetch(att.url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status + ' fetching attachment');
      const blob = await res.blob();
      // Wrap as a File so the rest of the pipeline (extractTextWithPdfJs,
      // form submit) treats it identically to a user-dropped file.
      let file;
      try {
        file = new File([blob], att.filename, { type: 'application/pdf' });
      } catch (e) {
        // Older browsers don't support `new File`. Fall back to assigning
        // a name on the Blob; the rest of the code only reads .name / .size.
        file = blob;
        file.name = att.filename;
      }
      // Plant the File on the input via DataTransfer so listeners that
      // read `input.files` work. Then trigger a 'change' so setFile() runs.
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (e) {
        // If DataTransfer isn't supported, fall back to submitting the
        // form directly with a synthesized event — the submit handler
        // reads `chosenFile` from closure, so we need an alternative
        // path. We'll piggyback by setting a global and dispatching submit.
        window.CMM_FALLBACK_FILE = file;
      }
      // Kick the submit handler. It will read the file from `chosenFile`
      // (now populated via the change event), parse, POST, redirect.
      if (status) status.textContent = 'Starting analysis…';
      form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    } catch (err) {
      console.error(err);
      if (status) {
        status.style.color = '#b3261e';
        status.textContent = 'Could not auto-load attachment: ' + (err.message || err);
      }
    }
  }

  /* ------------ Boot ------------ */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { initUpload(); initView(); initComment(); initAutoAttachment(); });
  } else {
    initUpload(); initView(); initComment(); initAutoAttachment();
  }
})();
