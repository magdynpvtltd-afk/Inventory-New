/**
 * MagDyn — Modules tree (drag-drop reorder)
 *
 * Behaviour:
 *   - Click chevron to expand/collapse a node (or group).
 *   - Expand all / Collapse all buttons.
 *   - Drag a row by its body (not by the action buttons):
 *       - Drop ABOVE a target  → become a sibling above it
 *       - Drop BELOW a target  → become a sibling below it
 *       - Drop ONTO a group's row → become a child of that group
 *   - After every successful drop, the new positions are POSTed to the
 *     server, which validates the cycle constraint and writes them in
 *     a transaction.
 *   - Action buttons (Edit / Toggle / Delete) initiate forms — they
 *     must NOT trigger a drag. We achieve that by setting draggable=false
 *     on the children of .mod-meta and bailing out of dragstart when
 *     the source is a button or form.
 *
 * SPA-friendly: re-binds on every navigation because the script tag is
 * re-executed by spa.js after innerHTML swap.
 */
(function () {
    'use strict';

    var tree = document.querySelector('.mod-tree');
    if (!tree) return;

    var canManage = tree.dataset.canManage === '1';
    var reorderUrl = tree.dataset.reorderUrl;
    var csrfToken  = tree.dataset.csrf;
    var statusEl   = document.getElementById('mod-save-status');

    /* -------- Expand / collapse (same idea as BOM view) -------- */
    function setOpen(node, open) {
        if (!node || !node.classList.contains('mod-node')) return;
        var kids = node.querySelector(':scope > .mod-children');
        if (!kids) return;
        node.classList.toggle('bom-closed', !open);
        node.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function expandAll() {
        document.querySelectorAll('.mod-tree .mod-node').forEach(function (n) {
            setOpen(n, true);
        });
    }
    function collapseAll() {
        document.querySelectorAll('.mod-tree .mod-node').forEach(function (n) {
            if (!n.classList.contains('bom-root')) setOpen(n, false);
        });
    }
    function applyDefault() {
        // Open top-level groups; collapse deeper levels
        document.querySelectorAll('.mod-tree .mod-node').forEach(function (n) {
            if (n.classList.contains('bom-root')) {
                setOpen(n, true);
            } else {
                setOpen(n, false);
            }
        });
    }

    // Bind chevron clicks directly on the tree (re-bind every SPA load is fine —
    // the tree element is fresh each navigation).
    tree.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.bom-toggle');
        if (!btn || btn.classList.contains('bom-leaf')) return;
        if (!tree.contains(btn)) return;
        var node = btn.closest('.mod-node');
        if (node) setOpen(node, node.classList.contains('bom-closed'));
    });

    // Bind toolbar buttons directly so a single click triggers exactly one
    // handler. Earlier draft used document-level delegation, which was
    // fragile across SPA navigations.
    var expandBtn = document.getElementById('mod-expand-all');
    var collapseBtn = document.getElementById('mod-collapse-all');
    if (expandBtn) expandBtn.addEventListener('click', function (e) {
        e.preventDefault();
        expandAll();
    });
    if (collapseBtn) collapseBtn.addEventListener('click', function (e) {
        e.preventDefault();
        collapseAll();
    });

    applyDefault();

    if (!canManage) return;

    /* -------- Drag / drop -------- */
    var dragNode = null;
    var dropZone = null;    // 'above' | 'below' | 'inside'
    var dropTarget = null;

    // Make action-button area non-draggable so clicking Edit etc. doesn't
    // start a drag. The <li> still listens for dragstart, but if the
    // user starts the drag from a button, we cancel below.
    function isInActionArea(el) {
        return el.closest && el.closest('.mod-meta');
    }

    function clearDropMarks() {
        document.querySelectorAll('.mod-drop-above, .mod-drop-below, .mod-drop-inside').forEach(function (n) {
            n.classList.remove('mod-drop-above', 'mod-drop-below', 'mod-drop-inside');
        });
        dropZone = null;
        dropTarget = null;
    }

    tree.addEventListener('dragstart', function (e) {
        // Reject drags that originate on action buttons
        if (isInActionArea(e.target)) {
            e.preventDefault();
            return;
        }
        var node = e.target.closest && e.target.closest('.mod-node');
        if (!node || node.dataset.modId === undefined) return;
        dragNode = node;
        node.classList.add('mod-dragging');
        // Carry the id so cross-document drops are theoretically possible;
        // not used here but defensive.
        try { e.dataTransfer.setData('text/plain', node.dataset.modId); } catch (_) {}
        e.dataTransfer.effectAllowed = 'move';
    });

    tree.addEventListener('dragend', function () {
        if (dragNode) dragNode.classList.remove('mod-dragging');
        dragNode = null;
        clearDropMarks();
    });

    tree.addEventListener('dragover', function (e) {
        if (!dragNode) return;
        var node = e.target.closest && e.target.closest('.mod-node');
        if (!node || node === dragNode) return;

        // Never allow dropping a node into its own descendant — visual guard;
        // server validates too.
        if (node.closest && dragNode.contains(node)) return;

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        // Decide zone based on cursor position within the row.
        // Drop-inside activates in the middle 50% of the row regardless
        // of whether the target is currently marked as a group — if it
        // isn't, the server auto-promotes it to is_group=1 on save. The
        // operator's mental model is "drop X on Y, X nests under Y" and
        // it shouldn't matter whether Y had been pre-marked as a group.
        var row = node.querySelector(':scope > .mod-row');
        if (!row) return;
        var rect = row.getBoundingClientRect();
        var y = e.clientY - rect.top;
        var h = rect.height;

        clearDropMarks();
        dropTarget = node;

        if (y > h * 0.25 && y < h * 0.75) {
            dropZone = 'inside';
            node.classList.add('mod-drop-inside');
        } else if (y < h / 2) {
            dropZone = 'above';
            node.classList.add('mod-drop-above');
        } else {
            dropZone = 'below';
            node.classList.add('mod-drop-below');
        }
    });

    tree.addEventListener('dragleave', function (e) {
        // Clear only if leaving the tree completely
        if (!tree.contains(e.relatedTarget)) clearDropMarks();
    });

    tree.addEventListener('drop', function (e) {
        if (!dragNode || !dropTarget) { clearDropMarks(); return; }
        e.preventDefault();

        var src = dragNode;
        var dst = dropTarget;
        var zone = dropZone;
        clearDropMarks();

        if (zone === 'inside') {
            // Append to dst's children, creating the children UL if absent
            var kids = dst.querySelector(':scope > .mod-children');
            if (!kids) {
                kids = document.createElement('ul');
                kids.className = 'bom-children mod-children';
                kids.setAttribute('role', 'group');
                dst.appendChild(kids);
                // Also swap the leaf indicator for a real chevron
                var lead = dst.querySelector(':scope > .mod-row > .bom-toggle');
                if (lead && lead.classList.contains('bom-leaf')) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'bom-toggle';
                    btn.setAttribute('aria-label', 'Toggle children');
                    btn.textContent = '▾';
                    lead.parentNode.replaceChild(btn, lead);
                }
            }
            kids.appendChild(src);
            setOpen(dst, true);
        } else if (zone === 'above') {
            dst.parentNode.insertBefore(src, dst);
        } else if (zone === 'below') {
            dst.parentNode.insertBefore(src, dst.nextSibling);
        }

        // If a non-group container is now empty, downgrade its chevron back to a leaf
        document.querySelectorAll('.mod-node').forEach(function (n) {
            if (n.dataset.isGroup === '1') return;  // groups always show chevron
            var kids = n.querySelector(':scope > .mod-children');
            if (kids && kids.children.length === 0) {
                kids.parentNode.removeChild(kids);
                var t = n.querySelector(':scope > .mod-row > .bom-toggle');
                if (t && !t.classList.contains('bom-leaf')) {
                    var span = document.createElement('span');
                    span.className = 'bom-toggle bom-leaf';
                    span.setAttribute('aria-hidden', 'true');
                    span.textContent = '·';
                    t.parentNode.replaceChild(span, t);
                }
            }
        });

        saveOrder();
    });

    /* -------- Walk DOM, collect new positions, POST -------- */
    function collectOrder() {
        var items = [];
        function walk(ul, parentId) {
            var sort = 10;
            ul.querySelectorAll(':scope > .mod-node').forEach(function (node) {
                var id = parseInt(node.dataset.modId, 10);
                items.push({ id: id, parent_id: parentId, sort_order: sort });
                sort += 10;
                var kids = node.querySelector(':scope > .mod-children');
                if (kids) walk(kids, id);
            });
        }
        walk(tree, null);
        return items;
    }

    function setStatus(kind, text) {
        if (!statusEl) return;
        statusEl.style.color = kind === 'err' ? '#b3261e' : (kind === 'ok' ? '#137333' : '');
        statusEl.textContent = text || '';
    }

    function applyNewSorts(items) {
        // Update the sort_order numbers shown in the row, and the dataset
        var map = {};
        items.forEach(function (it) { map[it.id] = it; });
        document.querySelectorAll('.mod-node').forEach(function (n) {
            var id = parseInt(n.dataset.modId, 10);
            if (!map[id]) return;
            n.dataset.sort = map[id].sort_order;
            var label = n.querySelector(':scope > .mod-row .mod-sort-num');
            if (label) label.textContent = map[id].sort_order;
        });
    }

    function saveOrder() {
        var items = collectOrder();
        setStatus('', 'Saving…');
        fetch(reorderUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken  // best-effort; bootstrap may check this header
            },
            body: JSON.stringify({ items: items, csrf_token: csrfToken })
        })
        .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
        .then(function (res) {
            var data;
            try { data = JSON.parse(res.text); }
            catch (_) { throw new Error('Server returned: ' + res.text.slice(0, 200)); }
            if (res.status >= 400 || !data.ok) {
                throw new Error(data && data.error ? data.error : 'Save failed');
            }

            // If the server auto-promoted a leaf into a group (drag-in)
            // or auto-demoted an empty group back to a leaf (drag-out
            // of the last child), the DOM no longer matches reality —
            // toggle widget, group pill, mod-group class, data-is-group
            // attribute all need to flip on the affected nodes.
            // Doing those flips correctly client-side requires recreating
            // toggle <button> ↔ leaf <span>, which is fiddly. Reloading
            // is cheap, idempotent, and guarantees consistency. Skipped
            // when only sort orders changed (the common case) so simple
            // intra-parent reorders stay snappy.
            var grouped  = (data.auto_grouped  || []).length;
            var demoted  = (data.auto_demoted  || []).length;
            if (grouped || demoted) {
                var bits = [];
                if (grouped) bits.push(grouped + ' promoted to group');
                if (demoted) bits.push(demoted + ' demoted to leaf');
                setStatus('ok', 'Saved (' + data.count + ' updated; ' + bits.join(', ') + '). Refreshing…');
                setTimeout(function () { window.location.reload(); }, 600);
                return;
            }

            applyNewSorts(items);
            setStatus('ok', 'Saved (' + data.count + ' updated). ');
            setTimeout(function () { setStatus('', ''); }, 2500);
        })
        .catch(function (err) {
            console.error(err);
            setStatus('err', 'Save failed: ' + (err.message || err) + ' — reload to revert.');
        });
    }

    // Expose for debugging
    window.__ModulesTree = { saveOrder: saveOrder, collectOrder: collectOrder };
})();
