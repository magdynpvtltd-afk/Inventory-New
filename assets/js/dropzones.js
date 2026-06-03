/* ============================================================
   Drag-and-drop file attach (site-wide, safe-by-default)
   ============================================================

   Wires drag-and-drop on `<input type="file">` elements without
   disturbing their layout. The previous version of this module
   used a `::after` overlay positioned with `inset: 0`, which —
   in combination with mutation-observer races during SPA swaps
   and modal openings — could occlude the file input controls
   (the user reported "all the attach file fields are missing").

   This version is intentionally minimal:

   - The drop zone is the file input itself, OR its nearest
     `[data-drop-zone]` ancestor (opt-in only).
   - No `::after` content overlay. Highlight is a single CSS
     outline + box-shadow on the zone — no extra elements, no
     positioning changes, no risk of covering controls.
   - Inputs inside `<label>` wrappers (the Notes pattern where a
     styled label holds a hidden file input) are NOT elevated to
     the label as the drop zone. The label keeps working as a
     clickable button.
   - The MutationObserver is debounced with rAF so SPA swaps and
     Quill edits don't cause repeated wiring storms.

   Inputs can OPT IN explicitly by adding `data-drop-zone` to a
   visible ancestor. Visible inputs become their own drop zone.
   Inputs explicitly opt out with `data-no-drop`.
   ============================================================ */
(function () {
  'use strict';

  var styleId = 'magdyn-dropzone-style';
  if (!document.getElementById(styleId)) {
    var s = document.createElement('style');
    s.id = styleId;
    s.textContent =
      '.is-drop-active {' +
      '  outline: 2px dashed #2563eb !important;' +
      '  outline-offset: 3px;' +
      '  box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.08);' +
      '  transition: box-shadow 0.12s ease;' +
      '}';
    document.head.appendChild(s);
  }

  function isVisible(el) {
    if (!el || !el.getClientRects) return false;
    return el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0;
  }

  function fileMatchesAccept(file, acceptAttr) {
    if (!acceptAttr) return true;
    var parts = acceptAttr.split(',').map(function (p) { return p.trim().toLowerCase(); });
    var name = (file.name || '').toLowerCase();
    var type = (file.type || '').toLowerCase();
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      if (!p) continue;
      if (p.charAt(0) === '.') {
        if (name.endsWith(p)) return true;
      } else if (p.endsWith('/*')) {
        if (type.indexOf(p.slice(0, -1)) === 0) return true;
      } else {
        if (type === p) return true;
      }
    }
    return false;
  }

  /**
   * Pick the drop zone for a file input. Priority:
   *   1. [data-drop-zone] ancestor (explicit opt-in).
   *   2. The input itself, if visible.
   *   3. null — skip.
   *
   * We deliberately do NOT walk up to <label> ancestors — that was
   * the v1 bug. Hidden file inputs inside styled <label> buttons
   * (Notes module) would elevate the label, and the ::after overlay
   * covered the label's content.
   */
  function dropZoneFor(input) {
    var opt = input.closest('[data-drop-zone]');
    if (opt) return opt;
    if (isVisible(input)) return input;
    return null;
  }

  function wireInput(input) {
    if (input._magdynDropWired) return;
    if (input.hasAttribute('data-no-drop')) return;
    if (input.closest('#stage') || input.id === 'file-input') return;

    input._magdynDropWired = true;
    var zone = dropZoneFor(input);
    if (!zone) return;

    if (zone._magdynDropInputs) {
      zone._magdynDropInputs.push(input);
      return;
    }
    zone._magdynDropInputs = [input];

    var depth = 0;

    function hasFiles(e) {
      return e.dataTransfer && Array.prototype.includes.call(e.dataTransfer.types || [], 'Files');
    }

    zone.addEventListener('dragenter', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault();
      depth++;
      zone.classList.add('is-drop-active');
    });
    zone.addEventListener('dragover', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
    });
    zone.addEventListener('dragleave', function () {
      depth--;
      if (depth <= 0) {
        depth = 0;
        zone.classList.remove('is-drop-active');
      }
    });
    zone.addEventListener('drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      e.preventDefault();
      e.stopPropagation();
      depth = 0;
      zone.classList.remove('is-drop-active');

      var target = zone._magdynDropInputs[0];
      var accept = target.getAttribute('accept') || '';
      var all = Array.from(e.dataTransfer.files);
      var matched = all.filter(function (f) { return fileMatchesAccept(f, accept); });
      if (!matched.length) return;
      if (!target.multiple) matched = matched.slice(0, 1);

      try {
        var dt = new DataTransfer();
        matched.forEach(function (f) { dt.items.add(f); });
        target.files = dt.files;
        target.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (err) {
        console.warn('[dropzones] DataTransfer unsupported; click to choose instead');
      }
    });
  }

  function wireAll(root) {
    var inputs = (root || document).querySelectorAll('input[type="file"]');
    for (var i = 0; i < inputs.length; i++) wireInput(inputs[i]);
  }

  if (document.readyState !== 'loading') {
    wireAll();
  } else {
    document.addEventListener('DOMContentLoaded', function () { wireAll(); });
  }

  if (typeof MutationObserver !== 'undefined') {
    var pending = false;
    var mo = new MutationObserver(function () {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () {
        pending = false;
        try { wireAll(); } catch (err) { console.warn('[dropzones] wireAll failed:', err); }
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });
  }

  window.MagDynDropzones = { wireAll: wireAll };
})();
