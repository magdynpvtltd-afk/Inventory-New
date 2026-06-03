/* ============================================================
   MagDyn — BOM Designer (drag and drop)
   Created: 20260515_160000_IST

   Two drop sources:
   1) Items from the side panel  -> dropping on a tree row creates
      a new BOM line as a child of that row's item.
   2) Existing tree row          -> dropping on another tree row
      reparents it under that row, OR onto a row's drop-zone slots
      ("before" / "into" / "after") to control position.

   All state changes are sent to the server as JSON POSTs and the
   tree re-renders from the server response, so the client never
   has to keep an authoritative model.

   Cycle prevention is enforced server-side; the client only does
   a quick local check and shows a red "no-drop" cue when the move
   would clearly fail (target is a descendant of the source).
   ============================================================ */
(function () {
    'use strict';

    var rootEl = null;
    var rootItemId = null;
    var endpoint = null;
    var csrf = null;
    var csrfField = '_csrf';

    // ---- Init ----
    function init() {
        rootEl = document.getElementById('bom-designer');
        if (!rootEl) return;
        rootItemId = parseInt(rootEl.dataset.rootItemId, 10);
        endpoint   = rootEl.dataset.endpoint;
        csrf       = rootEl.dataset.csrf;
        csrfField  = rootEl.dataset.csrfField || '_csrf';

        bindPalette();
        bindTree();
        bindSearch();
    }

    function bindSearch() {
        var inp = rootEl.querySelector('.bd-palette-search');
        if (!inp || inp.dataset.bdSearchBound) return;
        inp.dataset.bdSearchBound = '1';
        inp.addEventListener('input', function () {
            var q = inp.value.trim().toLowerCase();
            rootEl.querySelectorAll('.bd-palette-item').forEach(function (it) {
                var hay = (it.textContent || '').toLowerCase();
                it.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    function bindPalette() {
        rootEl.querySelectorAll('.bd-palette-item').forEach(function (it) {
            if (it.dataset.bdPaletteBound) return;
            it.dataset.bdPaletteBound = '1';
            it.draggable = true;
            it.addEventListener('dragstart', function (e) {
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    kind: 'new',
                    childItemId: parseInt(it.dataset.itemId, 10)
                }));
                rootEl.classList.add('bd-dragging-new');
            });
            it.addEventListener('dragend', function () {
                rootEl.classList.remove('bd-dragging-new');
            });
        });
    }

    function bindTree() {
        rootEl.querySelectorAll('.bd-node').forEach(function (n) {
            // Drag origin is the .bd-handle inside the row, not the whole
            // <li>. This stops the qty input and delete button from
            // triggering drags when the user wants to click them.
            var handle = n.querySelector(':scope > .bd-row > .bd-handle');
            if (handle && !n.classList.contains('bd-root') && !handle.dataset.bdBound) {
                handle.dataset.bdBound = '1';
                handle.addEventListener('dragstart', function (e) {
                    e.stopPropagation();
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', JSON.stringify({
                        kind: 'move',
                        lineId: parseInt(n.dataset.lineId, 10),
                        itemId: parseInt(n.dataset.itemId, 10)
                    }));
                    rootEl.classList.add('bd-dragging-move');
                    n.classList.add('bd-source');
                });
                handle.addEventListener('dragend', function () {
                    rootEl.classList.remove('bd-dragging-move');
                    rootEl.querySelectorAll('.bd-source, .bd-over')
                          .forEach(function (el) {
                              el.classList.remove('bd-source', 'bd-over',
                                  'bd-over-before', 'bd-over-into', 'bd-over-after');
                          });
                });
            }
            // Every node row is a drop target. Three zones per row:
            // top edge = "before", middle = "into" (becomes a child),
            // bottom edge = "after".
            var row = n.querySelector(':scope > .bd-row');
            if (!row || row.dataset.bdRowBound) return;
            row.dataset.bdRowBound = '1';
            row.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = e.dataTransfer.effectAllowed === 'copy' ? 'copy' : 'move';
                rootEl.querySelectorAll('.bd-over').forEach(function (el) {
                    el.classList.remove('bd-over', 'bd-over-before', 'bd-over-into', 'bd-over-after');
                });
                var rect = row.getBoundingClientRect();
                var y = e.clientY - rect.top;
                var zone;
                if (y < rect.height * 0.25)      zone = 'before';
                else if (y > rect.height * 0.75) zone = 'after';
                else                              zone = 'into';
                // 'before' / 'after' are meaningless on the root
                if (n.classList.contains('bd-root')) zone = 'into';
                n.classList.add('bd-over', 'bd-over-' + zone);
                row.dataset.dropZone = zone;
            });
            row.addEventListener('dragleave', function () {
                n.classList.remove('bd-over', 'bd-over-before', 'bd-over-into', 'bd-over-after');
            });
            row.addEventListener('drop', function (e) {
                e.preventDefault();
                var payload;
                try { payload = JSON.parse(e.dataTransfer.getData('text/plain') || '{}'); }
                catch (_) { return; }
                var zone = row.dataset.dropZone || 'into';
                if (payload.kind === 'new') {
                    addLine(n, payload.childItemId, zone);
                } else if (payload.kind === 'move') {
                    moveLine(n, payload.lineId, zone);
                }
                n.classList.remove('bd-over', 'bd-over-before', 'bd-over-into', 'bd-over-after');
            });
        });

        // Delete-line buttons
        rootEl.querySelectorAll('.bd-delete').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                if (!confirm('Remove this line?')) return;
                api('delete_line', { line_id: parseInt(b.dataset.lineId, 10) });
            });
        });

        // Qty inline edit
        rootEl.querySelectorAll('.bd-qty').forEach(function (inp) {
            var lineId = parseInt(inp.dataset.lineId, 10);
            inp.addEventListener('change', function () {
                var v = parseFloat(inp.value);
                if (!(v > 0)) { alert('Quantity must be greater than zero'); return; }
                api('update_line', { line_id: lineId, qty: v });
            });
        });
    }

    function addLine(targetNode, childItemId, zone) {
        var parentLineId; // for before/after: target's parent is the parent; sort_order computed
        var parentItemId;
        var sortHint = null;
        if (zone === 'into') {
            parentItemId = parseInt(targetNode.dataset.itemId, 10);
        } else {
            // 'before' / 'after' adds as a sibling of the target node
            parentItemId = parseInt(targetNode.dataset.parentItemId, 10);
            sortHint = (zone === 'before' ? 'before' : 'after');
            parentLineId = parseInt(targetNode.dataset.lineId, 10);
        }
        if (!parentItemId) return;
        api('add_line', {
            parent_item_id: parentItemId,
            child_item_id: childItemId,
            relative_to_line_id: parentLineId || 0,
            position: sortHint || 'last'
        });
    }

    function moveLine(targetNode, lineId, zone) {
        // Quick client check: target must not be a descendant of the line.
        var src = rootEl.querySelector('.bd-node[data-line-id="' + lineId + '"]');
        if (src && src.contains(targetNode) && src !== targetNode) {
            alert('Cannot move into a descendant.');
            return;
        }
        var parentItemId;
        var relativeLineId = 0;
        var position = 'last';
        if (zone === 'into') {
            parentItemId = parseInt(targetNode.dataset.itemId, 10);
        } else {
            parentItemId = parseInt(targetNode.dataset.parentItemId, 10);
            relativeLineId = parseInt(targetNode.dataset.lineId, 10);
            position = (zone === 'before' ? 'before' : 'after');
        }
        if (!parentItemId) return;
        api('move_line', {
            line_id: lineId,
            new_parent_item_id: parentItemId,
            relative_to_line_id: relativeLineId,
            position: position
        });
    }

    function api(op, payload) {
        var body = new URLSearchParams();
        body.set('op', op);
        body.set(csrfField, csrf);
        Object.keys(payload).forEach(function (k) { body.set(k, payload[k]); });
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
        .then(function (r) {
            // Always try to parse JSON. The server returns JSON on success
            // AND on error (permission denied, CSRF failure, app errors).
            // If parsing fails, surface the status code so the user sees
            // what went wrong instead of a generic "Network error".
            return r.text().then(function (text) {
                try { return JSON.parse(text); }
                catch (e) {
                    return { ok: false, error: 'Server returned non-JSON (' + r.status + '). Response: ' + text.substring(0, 200) };
                }
            });
        })
        .then(function (data) {
            if (!data || data.ok !== true) {
                alert((data && data.error) || 'Operation failed');
                return;
            }
            var holder = rootEl.querySelector('.bd-tree-holder');
            if (holder && data.tree_html) {
                holder.innerHTML = data.tree_html;
                bindTree();
            }
        })
        .catch(function (err) {
            alert('Network error: ' + (err && err.message ? err.message : 'connection failed'));
        });
    }

    // Expose init so SPA navigation can re-bind after content swap.
    window.MagDynBomDesigner = { init: init };

    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
})();
