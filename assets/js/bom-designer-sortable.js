/*
 * MagDyn — BOM Designer (Sortable.js engine)
 * Created: 20260516_143000_IST
 *
 * Drop-in replacement for the hand-rolled bom-designer.js using
 * SortableJS for drag-drop. The original is kept in place and still
 * loaded when config bom_designer.engine != 'sortable', so you can
 * revert by flipping that flag in app.config.php.
 *
 * DOM contract (set by inventory.php render_bom_designer_tree + page):
 *   #bom-designer.bd-wrap
 *     data-root-item-id      — id of the BOM root item
 *     data-endpoint          — POST URL for ops
 *     data-csrf              — token
 *     data-csrf-field        — token field name (e.g. _csrf)
 *     .bd-palette            — left side: source items (clone-only)
 *       .bd-palette-item     — each draggable catalogue entry, data-item-id
 *     .bd-tree-holder        — right side: the tree
 *       ul.bd-tree           — root list
 *         li.bd-node.bd-root — single root <li>
 *           div.bd-row
 *           ul.bd-children   — nested children (may not exist if empty)
 *             li.bd-node     — each line, data-line-id, data-item-id,
 *                              data-parent-item-id
 *
 * Operations (server-side endpoint):
 *   add_line(parent_item_id, child_item_id, relative_to_line_id?, position)
 *   move_line(line_id, new_parent_item_id, relative_to_line_id?, position)
 *   update_line(line_id, qty)
 *   delete_line(line_id)
 *
 * Sortable semantics:
 *   - Every <ul.bd-children> is a sortable list.
 *   - The root <ul.bd-tree> contains ONLY the root <li>, which itself
 *     must not be moved. We achieve this by:
 *       a) not init'ing Sortable on .bd-tree itself
 *       b) putting the actual drop zone on every <ul.bd-children>
 *   - The palette is its own Sortable with clone-on-pull. Items leave
 *     it visually but the original stays put.
 *   - On a drop, we read evt.from / evt.to / evt.item / evt.oldIndex /
 *     evt.newIndex to figure out which op to call.
 *   - After every successful op the server returns fresh tree HTML; we
 *     re-render the .bd-tree-holder and re-bind Sortable.
 */
(function () {
    'use strict';

    var wrap, endpoint, csrfToken, csrfField, rootItemId;
    var sortableInstances = [];
    var paletteInstance = null;

    function init() {
        wrap = document.getElementById('bom-designer');
        if (!wrap) return;
        if (typeof Sortable === 'undefined') {
            // The vendor file isn't loaded. Bail out — the legacy engine
            // (loaded conditionally instead) will handle the page. Nothing
            // for us to do here.
            console.warn('[bom-designer-sortable] Sortable.js not present; skipping.');
            return;
        }
        endpoint   = wrap.getAttribute('data-endpoint');
        csrfToken  = wrap.getAttribute('data-csrf');
        csrfField  = wrap.getAttribute('data-csrf-field') || '_csrf';
        rootItemId = parseInt(wrap.getAttribute('data-root-item-id'), 10);

        bindAll();
        bindFieldEvents();
        bindPaletteSearch();
    }

    function bindAll() {
        // Tear down any existing instances (called after server returns
        // fresh HTML and we re-render the tree).
        sortableInstances.forEach(function (s) { try { s.destroy(); } catch (_) {} });
        sortableInstances = [];

        // Step 1: ensure every .bd-node has a .bd-children <ul>.
        // The server emits a .bd-children only when an item has at
        // least one child. Leaf nodes (and the root, if empty) start
        // out without one. SortableJS's drop detection works on
        // <ul>s, not on individual rows, so a leaf without a children
        // <ul> has no drop target attached to it — that's why users
        // could only drop items above/below siblings, never INTO a
        // leaf to nest it. Adding an empty <ul> here gives every
        // node a "drop here to nest" target. CSS class
        // bd-children-empty styles them as a thin visible strip so
        // SortableJS's emptyInsertThreshold can detect hover.
        wrap.querySelectorAll('.bd-tree-holder li.bd-node').forEach(function (li) {
            if (!li.querySelector(':scope > ul.bd-children')) {
                var emptyUl = document.createElement('ul');
                emptyUl.className = 'bd-children bd-children-empty';
                li.appendChild(emptyUl);
            }
        });

        // Step 2: attach Sortable to every .bd-children <ul>. Items
        // can move freely between them (same group name). Dropping
        // into a previously-empty .bd-children-empty creates a new
        // parent-child relationship; dropping into a populated one
        // either reorders among siblings (onUpdate) or reparents
        // across lists (onAdd).
        wrap.querySelectorAll('.bd-tree-holder ul.bd-children').forEach(function (ul) {
            sortableInstances.push(new Sortable(ul, {
                group: { name: 'bom-tree', pull: true, put: true },
                animation: 140,
                // Drag from anywhere on the row. We intentionally do NOT
                // set `handle` so the entire .bd-node is the grip. Inputs
                // and buttons are excluded via `filter` below so clicking
                // the qty field or delete/toggle doesn't start a drag.
                filter: '.bd-qty, .bd-delete, .bd-toggle',
                preventOnFilter: false,
                draggable: '.bd-node:not(.bd-root)',
                ghostClass: 'bd-ghost',
                chosenClass: 'bd-chosen',
                dragClass: 'bd-drag',
                // Generous emptyInsertThreshold so users don't have
                // to drop precisely inside the thin empty-children
                // strip — anywhere within ~24px of the empty <ul>
                // will trigger the drop hover state.
                emptyInsertThreshold: 24,
                onAdd: handleSortableAdd,
                onUpdate: handleSortableUpdate
                // onEnd not needed — onAdd fires for cross-list moves and
                // onUpdate fires for same-list reorders. Both are mutually
                // exclusive in Sortable, so we get exactly one notification
                // per drop.
            }));
        });

        // Note: the previous "Special case: root with no children"
        // block has been folded into Step 1 above — that loop already
        // ensures every .bd-node (root included, since the root IS a
        // .bd-node) has an empty .bd-children <ul>, so we don't need a
        // separate root-specific path here.

        // Palette: clone-on-pull, no put.
        //
        // This must be (re)bound on every bindAll(), not just once. After
        // an SPA navigation the shell swaps the page DOM and calls init()
        // again — the old .bd-palette element is detached and a fresh one
        // rendered. A persistent "init once" guard (paletteInstance held
        // in the IIFE closure) would point at the stale, detached element
        // and skip binding the new palette, leaving it non-draggable.
        // So we tear down any prior instance and attach to the current
        // .bd-palette, mirroring how the tree instances are rebuilt above.
        if (paletteInstance) {
            try { paletteInstance.destroy(); } catch (_) {}
            paletteInstance = null;
        }
        var pal = wrap.querySelector('.bd-palette');
        if (pal) {
            paletteInstance = new Sortable(pal, {
                group: { name: 'bom-tree', pull: 'clone', put: false },
                sort: false,
                animation: 0,
                draggable: '.bd-palette-item',
                ghostClass: 'bd-palette-ghost',
                chosenClass: 'bd-palette-chosen',
                onClone: function (evt) {
                    // Tag the clone so we know it's a palette drop, not
                    // a tree-internal move.
                    evt.clone.dataset.fromPalette = '1';
                }
            });
        }
    }

    // ---- Drop handlers --------------------------------------------------

    function handleSortableAdd(evt) {
        // evt.item came from a different list. Two cases:
        //   1. Palette drop  -> add_line
        //   2. Cross-parent move within tree -> move_line
        //
        // We detect "palette drop" by checking whether the dropped
        // element carries the .bd-palette-item class. This is more
        // robust than the previous `dataset.fromPalette === '1'`
        // approach, which relied on Sortable's onClone callback
        // having tagged the element — but in some Sortable.js setups
        // with pull:'clone', the element that lands in the
        // destination's onAdd is the original palette node (not the
        // clone), so the fromPalette flag ended up on the wrong DOM
        // element. classList.contains('bd-palette-item') instead is
        // a structural property of the rendered markup and doesn't
        // depend on clone-vs-original semantics.
        var item = evt.item;
        var newParentLi = parentLiOf(evt.to);
        var newParentItemId = parseInt(newParentLi.getAttribute('data-item-id'), 10);
        var pos = computePositionWithin(evt.to, evt.newIndex);

        if (item.classList && item.classList.contains('bd-palette-item')) {
            // Palette drop. The element has the palette structure
            // (data-item-id), not tree structure. Remove it
            // immediately and let the server send back a fresh tree
            // where the new line is properly rendered.
            var childItemId = parseInt(item.getAttribute('data-item-id'), 10);
            if (item.parentNode) item.parentNode.removeChild(item);
            if (!childItemId) {
                console.error('[bom-designer] palette drop has no item-id', item);
                refreshTree();
                return;
            }
            api('add_line', {
                parent_item_id:      newParentItemId,
                child_item_id:       childItemId,
                relative_to_line_id: pos.relativeToLineId,
                position:            pos.position
            });
            return;
        }
        // Cross-parent move: the item already exists as a bom_line — we
        // need its line_id. Tree <li>s carry it.
        var lineId = parseInt(item.getAttribute('data-line-id'), 10);
        if (!lineId) {
            console.error('[bom-designer-sortable] Moved node has no line-id', item);
            refreshTree();
            return;
        }
        api('move_line', {
            line_id:             lineId,
            new_parent_item_id:  newParentItemId,
            relative_to_line_id: pos.relativeToLineId,
            position:            pos.position
        });
    }

    function handleSortableUpdate(evt) {
        // Same-list reorder. Same parent, just a new position.
        var item = evt.item;
        var newParentLi = parentLiOf(evt.to);
        var newParentItemId = parseInt(newParentLi.getAttribute('data-item-id'), 10);
        var pos = computePositionWithin(evt.to, evt.newIndex);
        var lineId = parseInt(item.getAttribute('data-line-id'), 10);
        if (!lineId) { refreshTree(); return; }
        api('move_line', {
            line_id:             lineId,
            new_parent_item_id:  newParentItemId,
            relative_to_line_id: pos.relativeToLineId,
            position:            pos.position
        });
    }

    /**
     * Given a parent <ul> and the new index of the dropped item within
     * it, figure out which existing sibling we should be inserted
     * "before" so the server can compute a sort_order. The simplest
     * encoding: if there's a sibling AFTER the new position, use it as
     * relative_to_line_id with position='before'. Otherwise position='last'.
     */
    function computePositionWithin(parentUl, newIndex) {
        // Children at this point INCLUDE the just-inserted item, so
        // "the sibling after the dropped one" is at newIndex + 1.
        var siblings = Array.prototype.slice.call(parentUl.children).filter(function (n) {
            return n.classList && n.classList.contains('bd-node');
        });
        var after = siblings[newIndex + 1];
        if (after) {
            var afterLineId = parseInt(after.getAttribute('data-line-id'), 10);
            if (afterLineId) return { relativeToLineId: afterLineId, position: 'before' };
        }
        return { relativeToLineId: 0, position: 'last' };
    }

    function parentLiOf(ul) {
        // Walk up to the enclosing <li.bd-node> (the parent item the <ul>
        // belongs to).
        var li = ul.parentElement;
        while (li && !li.classList.contains('bd-node')) li = li.parentElement;
        return li;
    }

    // ---- API ----------------------------------------------------------

    function api(op, extra) {
        var body = new FormData();
        body.append('op', op);
        body.append('id', String(rootItemId));
        body.append(csrfField, csrfToken);
        for (var k in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, k)) {
                body.append(k, String(extra[k]));
            }
        }
        fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Bad JSON (HTTP ' + r.status + ')' }; }); })
            .then(function (data) {
                if (!data || data.ok !== true) {
                    flash('error', (data && data.error) || 'Unknown error');
                    refreshTree();
                    return;
                }
                renderTree(data.tree_html);
            })
            .catch(function (err) {
                flash('error', 'Network error: ' + err);
                refreshTree();
            });
    }

    function refreshTree() {
        // Force a fresh GET via no-op API call — easier than re-fetching
        // the page. We use update_line on a line that doesn't exist to
        // trigger an error, but that returns the tree anyway... actually
        // no, an error returns no tree. Simpler: reload the page.
        window.location.reload();
    }

    function renderTree(html) {
        var holder = wrap.querySelector('.bd-tree-holder');
        if (!holder) return;
        holder.innerHTML = html;
        bindAll();
    }

    // ---- Inline field events (qty change, delete) ---------------------

    function bindFieldEvents() {
        wrap.addEventListener('change', function (e) {
            var qty = e.target.closest && e.target.closest('.bd-qty');
            if (!qty) return;
            var lineId = parseInt(qty.getAttribute('data-line-id'), 10);
            if (!lineId) return;
            var v = parseFloat(qty.value);
            if (!(v > 0)) {
                flash('error', 'Quantity must be greater than zero.');
                refreshTree();
                return;
            }
            api('update_line', { line_id: lineId, qty: v });
        });
        wrap.addEventListener('click', function (e) {
            var del = e.target.closest && e.target.closest('.bd-delete');
            if (!del) return;
            e.preventDefault();
            var lineId = parseInt(del.getAttribute('data-line-id'), 10);
            if (!lineId) return;
            if (!confirm('Remove this line from the BOM?')) return;
            api('delete_line', { line_id: lineId });
        });
    }

    // ---- Palette search -----------------------------------------------

    function bindPaletteSearch() {
        var input = wrap.querySelector('.bd-palette-search');
        if (!input) return;
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            wrap.querySelectorAll('.bd-palette .bd-palette-item').forEach(function (el) {
                var t = el.textContent.toLowerCase();
                el.style.display = (!q || t.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // ---- Minimal flash --------------------------------------------------
    function flash(kind, msg) {
        // Reuse any global flash if one exists, else inline-banner inside wrap.
        if (typeof window.MagDynFlash === 'function') {
            window.MagDynFlash(kind, msg);
            return;
        }
        var bar = wrap.querySelector('.bd-flash');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'bd-flash';
            wrap.insertBefore(bar, wrap.firstChild);
        }
        bar.textContent = msg;
        bar.dataset.kind = kind;
        setTimeout(function () { if (bar.parentNode) bar.parentNode.removeChild(bar); }, 5000);
    }

    window.MagDynBomDesigner = window.MagDynBomDesigner || {};
    // Match the original module's init() so the SPA shell's re-init call
    // works whichever engine is loaded.
    window.MagDynBomDesigner.init = init;

    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
})();
