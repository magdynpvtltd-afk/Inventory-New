/* ============================================================
   MagDyn — DataTable client
   Created: 20260515_113000_IST

   Looks for .dt-wrap blocks rendered by includes/datatable.php and
   hydrates them with:
     - sortable headers (click to toggle)
     - debounced global search box
     - debounced per-column filter inputs
     - page-size selector
     - paginator buttons
     - URL pushState so links stay shareable + browser back works

   Falls back to plain form submission (full page reload) when JS
   is unavailable, since every control is also exposed as a real
   form element with a paired hidden input strategy below.
   ============================================================ */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    function readState(wrap) {
        var qInput   = wrap.querySelector('.dt-q');
        var sizeSel  = wrap.querySelector('.dt-size');
        var sorted   = wrap.querySelector('.dt-headers th.dt-sorted');
        var colInps  = wrap.querySelectorAll('.dt-col-filter');
        var state = {
            q:    qInput ? qInput.value : '',
            size: sizeSel ? parseInt(sizeSel.value, 10) : 25,
            sort: sorted ? sorted.getAttribute('data-dt-sort') : '',
            dir:  sorted ? (sorted.classList.contains('dt-sorted-desc') ? 'desc' : 'asc') : 'asc',
            page: 1,
            col:  {}
        };
        colInps.forEach(function (inp) {
            // Client-side filter inputs don't go in URL params — they
            // filter the rendered DOM rows locally (see applyClientFilters).
            if (inp.getAttribute('data-dt-col-client') === '1') return;
            var k = inp.getAttribute('data-dt-col');
            if (inp.value) state.col[k] = inp.value;
        });
        return state;
    }

    // Immediately refresh the "Showing X to Y of Z" summary from known values.
    // Called optimistically (before AJAX) whenever page or size changes so
    // the display never waits for a network round-trip or full page reload.
    function updateRangeSummary(wrap, page, size) {
        var totalEl      = wrap.querySelector('.dt-total');
        var rangeStartEl = wrap.querySelector('.dt-range-start');
        var rangeEndEl   = wrap.querySelector('.dt-range-end');
        if (!rangeStartEl || !rangeEndEl || !totalEl) return;
        var total = parseInt(totalEl.textContent, 10) || 0;
        var start = total === 0 ? 0 : ((page - 1) * size + 1);
        var end   = Math.min(total, page * size);
        rangeStartEl.textContent = String(start);
        rangeEndEl.textContent   = String(end);
    }

    // Apply client-side row filters by hiding rows that don't match.
    // Called whenever a client-side filter input changes, AND after any
    // AJAX reload (because new rows might come in that need filtering).
    // Each filter input has data-dt-col-idx pointing at its column's
    // zero-based index in the row's <td> list.
    function applyClientFilters(wrap) {
        var inputs = wrap.querySelectorAll('.dt-col-filter-client');
        var filters = [];
        inputs.forEach(function (inp) {
            var v = (inp.value || '').trim().toLowerCase();
            if (!v) return;
            filters.push({ idx: parseInt(inp.getAttribute('data-dt-col-idx') || '0', 10), needle: v });
        });
        var rows = wrap.querySelectorAll('.dt-body > tr');
        if (filters.length === 0) {
            // No active client filters — show every row
            rows.forEach(function (r) { r.style.display = ''; });
            return;
        }
        rows.forEach(function (r) {
            var cells = r.children;
            var matchesAll = true;
            for (var i = 0; i < filters.length; i++) {
                var f = filters[i];
                var cell = cells[f.idx];
                var text = cell ? (cell.textContent || '').toLowerCase() : '';
                if (text.indexOf(f.needle) === -1) { matchesAll = false; break; }
            }
            r.style.display = matchesAll ? '' : 'none';
        });
    }

    function buildUrl(wrap, state) {
        // Start from the page's current URL so we don't strip other
        // params (e.g. ?action=list&view=...).
        var url = new URL(window.location.href);
        // Strip previous dt_* params
        var stripKeys = [];
        url.searchParams.forEach(function (_, k) { if (k.indexOf('dt_') === 0) stripKeys.push(k); });
        stripKeys.forEach(function (k) { url.searchParams.delete(k); });

        if (state.sort) url.searchParams.set('dt_sort', state.sort);
        if (state.dir)  url.searchParams.set('dt_dir',  state.dir);
        if (state.page) url.searchParams.set('dt_page', String(state.page));
        if (state.size) url.searchParams.set('dt_size', String(state.size));
        if (state.q)    url.searchParams.set('dt_q',    state.q);
        Object.keys(state.col).forEach(function (k) {
            url.searchParams.set('dt_col[' + k + ']', state.col[k]);
        });
        return url;
    }

    function reload(wrap, state, pushHistory) {
        var url = buildUrl(wrap, state);

        // AJAX fetch — append dt_format=json + dt_id so multi-table pages
        // can route the AJAX request to the right table handler.
        var ajaxUrl = new URL(url.toString());
        ajaxUrl.searchParams.set('dt_format', 'json');
        var tableId = wrap.getAttribute('data-dt-id');
        if (tableId) ajaxUrl.searchParams.set('dt_id', tableId);

        wrap.classList.add('dt-loading');

        fetch(ajaxUrl.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data || data.ok !== true) throw new Error('Bad response');
                var body  = wrap.querySelector('.dt-body');
                var pager = wrap.querySelector('.dt-pager');
                var total = wrap.querySelector('.dt-total');
                if (body)  body.innerHTML  = data.rows_html;
                if (pager) pager.innerHTML = data.pager_html;
                if (total) total.textContent = String(data.total);
                updateRangeSummary(wrap, data.page, data.page_size);

                // Update sort indicators on the header row
                wrap.querySelectorAll('.dt-headers th[data-dt-sort]').forEach(function (th) {
                    var key = th.getAttribute('data-dt-sort');
                    th.classList.remove('dt-sorted', 'dt-sorted-asc', 'dt-sorted-desc');
                    th.setAttribute('aria-sort', 'none');
                    var arrow = th.querySelector('.dt-arrow');
                    if (key === data.sort) {
                        th.classList.add('dt-sorted', 'dt-sorted-' + data.dir);
                        th.setAttribute('aria-sort', data.dir === 'asc' ? 'ascending' : 'descending');
                        th.setAttribute('data-dt-dir', data.dir === 'asc' ? 'desc' : 'asc');
                        if (arrow) arrow.textContent = data.dir === 'asc' ? '▲' : '▼';
                    } else {
                        th.setAttribute('data-dt-dir', 'asc');
                        if (arrow) arrow.textContent = '↕';
                    }
                });

                if (pushHistory) {
                    history.pushState({ dt: true }, '', url.toString());
                }
                // Re-evaluate which cells overflow after the row swap
                if (typeof wrap._magdynApplyTooltips === 'function') {
                    wrap._magdynApplyTooltips();
                }
                // New rows arrived — re-apply any active client-side
                // column filters so the hide-state is consistent.
                applyClientFilters(wrap);
            })
            .catch(function () {
                // On error, full-page reload as a fallback
                window.location.href = url.toString();
            })
            .then(function () {
                wrap.classList.remove('dt-loading');
            });
    }

    function bindWrap(wrap) {
        if (wrap.dataset.dtBound === '1') return;
        wrap.dataset.dtBound = '1';

        var debouncedReload = debounce(function (push) {
            var s = readState(wrap);
            reload(wrap, s, push !== false);
        }, DEBOUNCE_MS);

        // ---- Global search box ----
        var qInput = wrap.querySelector('.dt-q');
        if (qInput) {
            qInput.addEventListener('input', function () {
                debouncedReload(true);
            });
            qInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { qInput.value = ''; debouncedReload(true); }
            });
        }

        // ---- Per-column filters ----
        // Server-side filter inputs trigger a debounced AJAX reload.
        // Client-side filter inputs filter the rendered DOM in place,
        // hiding rows whose cell at the marked column index doesn't
        // contain the typed substring (case-insensitive).
        wrap.querySelectorAll('.dt-col-filter').forEach(function (inp) {
            var isClient = inp.getAttribute('data-dt-col-client') === '1';
            if (isClient) {
                inp.addEventListener('input', function () {
                    applyClientFilters(wrap);
                });
                return;
            }
            if (inp.tagName === 'SELECT') {
                inp.addEventListener('change', function () {
                    var s = readState(wrap);
                    s.page = 1;
                    reload(wrap, s, true);
                });
            } else {
                inp.addEventListener('input', function () { debouncedReload(true); });
            }
        });

        // ---- Page size ----
        var sizeSel = wrap.querySelector('.dt-size');
        if (sizeSel) {
            sizeSel.addEventListener('change', function () {
                var s = readState(wrap);
                s.page = 1;
                updateRangeSummary(wrap, 1, s.size);
                reload(wrap, s, true);
            });
        }

        // ---- Sortable headers (click + Enter/Space) ----
        wrap.querySelectorAll('.dt-headers th.dt-sortable').forEach(function (th) {
            function fire() {
                var s = readState(wrap);
                var key = th.getAttribute('data-dt-sort');
                var nextDir = th.getAttribute('data-dt-dir') || 'asc';
                s.sort = key;
                s.dir  = nextDir;
                s.page = 1;
                reload(wrap, s, true);
            }
            th.addEventListener('click', fire);
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fire(); }
            });
        });

        // ---- Paginator (event delegation since contents are replaced) ----
        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.dt-page-btn');
            if (!btn) return;
            if (btn.disabled) return;
            var s = readState(wrap);
            s.page = parseInt(btn.getAttribute('data-dt-page') || '1', 10);
            updateRangeSummary(wrap, s.page, s.size);
            reload(wrap, s, true);
        });
        // ---- Column resizing ----
        // Each <th> (except the last) has a `.dt-resize-handle` span we
        // attach a mousedown listener to. On drag, we update the column's
        // CSS width and the corresponding tfoot td (if present). Final
        // width is persisted in localStorage keyed by the dt-wrap's id
        // plus the column key, so it survives reloads and SPA swaps.
        var resizable = wrap.querySelector('.dt-table[data-dt-resizable="1"]');
        if (resizable) {
            var wrapId = wrap.getAttribute('data-dt-id') || 'default';
            var hasSaved = false;
            // Apply previously saved widths from localStorage
            resizable.querySelectorAll('th[data-dt-colkey]').forEach(function (th) {
                var key = th.getAttribute('data-dt-colkey');
                var storageKey = 'magdyn.dt.colw.' + wrapId + '.' + key;
                var saved = null;
                try { saved = localStorage.getItem(storageKey); } catch (e) {}
                if (saved && /^\d+$/.test(saved)) {
                    th.style.width = saved + 'px';
                    hasSaved = true;
                }
            });
            if (hasSaved) {
                resizable.classList.add('dt-fixed-layout');
                // Pin the table width to the sum of column widths so
                // saved widths are honored without squishing (the base
                // .dt-table { width: 100% } would otherwise force
                // fixed-layout to redistribute widths). Use the next
                // animation frame so the saved style widths have applied
                // first and getBoundingClientRect returns the requested
                // sizes rather than the pre-saved auto-layout sizes.
                requestAnimationFrame(function () {
                    var totalW = 0;
                    resizable.querySelectorAll('thead th').forEach(function (th) {
                        totalW += th.getBoundingClientRect().width;
                    });
                    if (totalW > 0) {
                        resizable.style.width = Math.round(totalW) + 'px';
                    }
                });
            }

            // Attach drag handlers
            resizable.querySelectorAll('.dt-resize-handle').forEach(function (handle) {
                handle.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var th = handle.closest('th');
                    if (!th) return;
                    var startX = e.clientX;
                    var startW = th.getBoundingClientRect().width;
                    var key = th.getAttribute('data-dt-colkey');
                    handle.classList.add('dt-resizing');
                    document.body.classList.add('dt-resizing-active');
                    // Switching to fixed layout the moment the user starts
                    // resizing — auto-layout would ignore any width set.
                    // We also pin the table's overall width to the SUM of
                    // column widths so growing one column actually grows
                    // the table (and triggers horizontal scroll on .dt-scroll)
                    // rather than redistributing space across siblings.
                    // Without the explicit table-width pin, fixed-layout
                    // honors `width: 100%` from base CSS and squishes
                    // other columns to keep the total constant.
                    if (!resizable.classList.contains('dt-fixed-layout')) {
                        // Snapshot current column widths before flipping
                        // to fixed, so layout doesn't reflow surprisingly.
                        resizable.querySelectorAll('th[data-dt-colkey]').forEach(function (otherTh) {
                            if (!otherTh.style.width) {
                                otherTh.style.width = otherTh.getBoundingClientRect().width + 'px';
                            }
                        });
                        resizable.classList.add('dt-fixed-layout');
                        startW = th.getBoundingClientRect().width;
                    }
                    // Compute the table's natural width as the sum of
                    // every <th>'s current width. This number is the
                    // fixed table width we'll keep adjusting as the user
                    // drags. NOT a saved value — recomputed at every
                    // mousedown so it stays correct if other columns
                    // were independently resized.
                    var initialTableW = 0;
                    resizable.querySelectorAll('thead th').forEach(function (anyTh) {
                        initialTableW += anyTh.getBoundingClientRect().width;
                    });
                    resizable.style.width = Math.round(initialTableW) + 'px';

                    function onMove(ev) {
                        var delta = ev.clientX - startX;
                        var newW  = Math.max(40, Math.round(startW + delta));
                        var actualDelta = newW - parseInt(th.style.width || startW, 10);
                        th.style.width = newW + 'px';
                        // Grow the table by the same delta so the cell
                        // can actually expand without squishing siblings.
                        var newTableW = Math.round(initialTableW + (newW - startW));
                        if (newTableW > 0) {
                            resizable.style.width = newTableW + 'px';
                        }
                    }
                    function onUp() {
                        handle.classList.remove('dt-resizing');
                        document.body.classList.remove('dt-resizing-active');
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        // Persist the new width to the server if prefs are
                        // enabled on this datatable. Falls back silently to
                        // localStorage on networks/auth failure so users
                        // still get session-local width preservation.
                        if (!key) return;
                        var widthPx = Math.round(th.getBoundingClientRect().width);
                        var prefsEnabled = wrap.getAttribute('data-dt-prefs') === '1';
                        var csrf = wrap.getAttribute('data-dt-csrf') || '';
                        if (prefsEnabled && csrf) {
                            fetch('/erp/api/dt_prefs.php?op=save_width', {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': csrf
                                },
                                body: JSON.stringify({
                                    dt_id: wrapId,
                                    column_key: key,
                                    width_px: widthPx
                                })
                            }).catch(function () {
                                // Network/server failure: degrade to
                                // localStorage so the width still survives
                                // a reload within this browser.
                                try {
                                    localStorage.setItem(
                                        'magdyn.dt.colw.' + wrapId + '.' + key,
                                        String(widthPx)
                                    );
                                } catch (e) {}
                            });
                        } else {
                            // Prefs disabled on this datatable; keep the
                            // previous localStorage-only behaviour.
                            try {
                                localStorage.setItem(
                                    'magdyn.dt.colw.' + wrapId + '.' + key,
                                    String(widthPx)
                                );
                            } catch (e) {}
                        }
                    }
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
                // Prevent the click from propagating to the sortable th
                handle.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });
        }

        // ---- Overflow tooltips ----
        // For every <td> whose rendered content is wider than its cell,
        // set a `title` attribute with the cell's text content so the
        // user sees the full value on hover. Re-run after AJAX swaps and
        // after column resize.
        var tooltipDebounce = null;
        function applyOverflowTooltips() {
            var table = wrap.querySelector('.dt-table');
            if (!table) return;
            table.querySelectorAll('tbody td').forEach(function (td) {
                // scrollWidth > clientWidth means the content was clipped
                // by overflow:hidden + ellipsis. textContent is fine — we
                // tooltip the plain text, not the HTML pills/links inside.
                if (td.scrollWidth > td.clientWidth + 1) {
                    var txt = td.textContent.trim();
                    if (txt) td.setAttribute('title', txt);
                } else if (td.hasAttribute('title')) {
                    td.removeAttribute('title');
                }
            });
        }
        function scheduleTooltips() {
            if (tooltipDebounce) clearTimeout(tooltipDebounce);
            tooltipDebounce = setTimeout(applyOverflowTooltips, 60);
        }
        // Initial pass once layout has settled
        scheduleTooltips();
        // Re-run after AJAX-driven tbody replacement. The reload()
        // function (above) calls this via a custom event we dispatch
        // there; for now also re-run on window resize.
        window.addEventListener('resize', scheduleTooltips);
        // Re-run after column resize finishes — listen for the body
        // class being removed (signals resize end).
        var bodyObserver = new MutationObserver(function () {
            if (!document.body.classList.contains('dt-resizing-active')) {
                scheduleTooltips();
            }
        });
        bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        // Stash on the wrap so reload() can call it
        wrap._magdynApplyTooltips = scheduleTooltips;
    }

    function initAll() {
        document.querySelectorAll('.dt-wrap').forEach(bindWrap);
    }

    // ----------------------------------------------------------------
    // Gear-icon action dropdown (.dt-actions) — shared by every list
    // page. Each row's Actions cell wraps its original action HTML in
    // <div class="dt-actions"><button class="dt-actions-trigger">⚙
    // </button><div class="dt-actions-dropdown" hidden>…</div></div>.
    // Clicking the gear opens the dropdown, positioned via fixed
    // coordinates so the table scroll container can't clip it.
    //
    // Listeners are attached once at module load (delegated on document)
    // so they survive AJAX swaps and SPA navigation.
    // ----------------------------------------------------------------
    (function () {
        function closeAllDt() {
            document.querySelectorAll('.dt-actions-dropdown[data-open="1"]').forEach(function (dd) {
                dd.hidden = true;
                dd.style.top = '';
                dd.style.left = '';
                dd.style.position = '';
                dd.removeAttribute('data-open');
                var trigger = dd.parentElement && dd.parentElement.querySelector('.dt-actions-trigger');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        }
        function openDt(trigger, dd) {
            dd.hidden = false;
            dd.style.position = 'fixed';
            // Reveal so we can measure; use offsetWidth/Height which work
            // even when not yet placed.
            var rect = trigger.getBoundingClientRect();
            var w = dd.offsetWidth, hgt = dd.offsetHeight;
            var left = rect.right - w;
            var top  = rect.bottom + 2;
            if (left < 4) left = 4;
            if (left + w > window.innerWidth - 4) left = window.innerWidth - w - 4;
            if (top + hgt > window.innerHeight - 4) {
                top = rect.top - hgt - 2;
                if (top < 4) top = 4;
            }
            dd.style.left = left + 'px';
            dd.style.top  = top + 'px';
            dd.setAttribute('data-open', '1');
            trigger.setAttribute('aria-expanded', 'true');
        }
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest && e.target.closest('.dt-actions-trigger');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                var dd = trigger.parentElement && trigger.parentElement.querySelector('.dt-actions-dropdown');
                if (!dd) return;
                var wasOpen = dd.getAttribute('data-open') === '1';
                closeAllDt();
                if (!wasOpen) openDt(trigger, dd);
                return;
            }
            // A click inside an open dropdown on an actual <a> / <button>
            // lets navigation/submit happen and closes the menu. We don't
            // need to do anything here for <a> — the browser navigates.
            // For <form><button> submits we let the form submit naturally.
            // In both cases, schedule a close so the menu doesn't linger.
            if (e.target.closest && e.target.closest('.dt-actions-dropdown')) {
                closeAllDt();
                return;
            }
            closeAllDt();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAllDt();
        });
        // Scroll inside a dt-scroll or window resize invalidates fixed
        // coordinates — close so the user doesn't see a floating ghost.
        document.addEventListener('scroll', function (e) {
            if (e.target.closest && (e.target.closest('.dt-scroll') || e.target.closest('.bom-grid-scroll'))) {
                closeAllDt();
            }
        }, true);
        window.addEventListener('resize', closeAllDt);
    })();

    // Expose for SPA-shell re-initialisation after content swap
    window.MagDynDataTable = window.MagDynDataTable || {};
    window.MagDynDataTable.bindAll = initAll;

    if (document.readyState !== 'loading') initAll();
    else document.addEventListener('DOMContentLoaded', initAll);

    // popstate handling is delegated to the SPA shell (spa.js). When the
    // user hits Back/Forward the shell fetches the URL and swaps <main>,
    // which includes a freshly server-rendered datatable with the right
    // params already applied. No need to also AJAX the table here.
})();


/* ============================================================
   MagDyn — DataTable Columns panel (Phase A of PRD)

   Hydrates the "⚙ Columns" button next to each datatable. Opens a
   popover where the user can:
     - toggle column visibility (checkboxes)
     - reorder columns (drag-to-reorder)
     - reset to defaults
   Saves to /erp/api/dt_prefs.php and reloads the page on success.
   ============================================================ */
(function () {
    'use strict';

    var ENDPOINT = (window.MAGDYN_BASE || '').replace(/\/+$/, '') + '/api/dt_prefs.php';

    function buildPanel(wrap, btn) {
        var raw = wrap.getAttribute('data-dt-allcols');
        if (!raw) return null;
        var cols;
        try { cols = JSON.parse(raw); } catch (e) { return null; }
        if (!cols || !cols.length) return null;
        var dtId = wrap.getAttribute('data-dt-id');
        var csrf = wrap.getAttribute('data-dt-csrf') || '';

        var panel = document.createElement('div');
        panel.className = 'dt-cols-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Column preferences');

        // Header
        var hdr = document.createElement('div');
        hdr.className = 'dt-cols-panel-head';
        hdr.innerHTML =
            '<strong>Columns</strong>' +
            '<span class="dim small" style="margin-left: 8px;">Drag to reorder · Toggle to show/hide</span>';
        panel.appendChild(hdr);

        // List
        var list = document.createElement('ul');
        list.className = 'dt-cols-list';
        cols.forEach(function (c) {
            var li = document.createElement('li');
            li.className = 'dt-cols-item';
            li.draggable = true;
            li.setAttribute('data-key', c.key);
            li.innerHTML =
                '<span class="dt-cols-drag" aria-hidden="true">⋮⋮</span>' +
                '<label class="dt-cols-label">' +
                  '<input type="checkbox" ' + (c.hidden ? '' : 'checked') + '>' +
                  '<span>' + escapeHtml(c.label) + '</span>' +
                '</label>';
            list.appendChild(li);
        });
        panel.appendChild(list);

        // Footer buttons
        var foot = document.createElement('div');
        foot.className = 'dt-cols-panel-foot';
        foot.innerHTML =
            '<button type="button" class="btn btn-sm btn-ghost" data-act="reset">Reset to default</button>' +
            '<span class="grow"></span>' +
            '<button type="button" class="btn btn-sm btn-ghost" data-act="cancel">Cancel</button>' +
            '<button type="button" class="btn btn-sm btn-primary" data-act="save">Save</button>';
        panel.appendChild(foot);

        // Drag-to-reorder
        var draggedEl = null;
        list.addEventListener('dragstart', function (e) {
            var li = e.target.closest('li.dt-cols-item');
            if (!li) return;
            draggedEl = li;
            li.classList.add('dt-cols-dragging');
            if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragend', function () {
            if (draggedEl) draggedEl.classList.remove('dt-cols-dragging');
            draggedEl = null;
        });
        list.addEventListener('dragover', function (e) {
            if (!draggedEl) return;
            e.preventDefault();
            var over = e.target.closest('li.dt-cols-item');
            if (!over || over === draggedEl) return;
            var rect = over.getBoundingClientRect();
            var midY = rect.top + rect.height / 2;
            if (e.clientY < midY) {
                over.parentNode.insertBefore(draggedEl, over);
            } else {
                over.parentNode.insertBefore(draggedEl, over.nextSibling);
            }
        });

        // Button handlers
        foot.querySelector('[data-act="cancel"]').addEventListener('click', closePanel);
        foot.querySelector('[data-act="save"]').addEventListener('click', function () {
            var items = [];
            list.querySelectorAll('li.dt-cols-item').forEach(function (li, idx) {
                items.push({
                    column_key:    li.getAttribute('data-key'),
                    display_order: idx,
                    is_hidden:     !li.querySelector('input[type=checkbox]').checked
                });
            });
            postJson(ENDPOINT + '?op=save_layout', { dt_id: dtId, items: items }, csrf)
                .then(function () { window.location.reload(); })
                .catch(function (err) {
                    alert('Could not save column preferences: ' + (err && err.message || err));
                });
        });
        foot.querySelector('[data-act="reset"]').addEventListener('click', function () {
            if (!confirm('Reset column layout to defaults for this table? (Width, order, and visibility prefs will be cleared.)')) return;
            postJson(ENDPOINT + '?op=reset', { dt_id: dtId }, csrf)
                .then(function () { window.location.reload(); })
                .catch(function (err) {
                    alert('Could not reset column preferences: ' + (err && err.message || err));
                });
        });

        // Click-outside to close
        function onDocClick(e) {
            if (panel.contains(e.target) || btn.contains(e.target)) return;
            closePanel();
        }
        function onKey(e) { if (e.key === 'Escape') closePanel(); }
        document.addEventListener('click', onDocClick, true);
        document.addEventListener('keydown', onKey);

        function closePanel() {
            document.removeEventListener('click', onDocClick, true);
            document.removeEventListener('keydown', onKey);
            if (panel.parentNode) panel.parentNode.removeChild(panel);
        }
        panel._close = closePanel;
        return panel;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
        });
    }

    function postJson(url, body, csrf) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf || ''
            },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok || !j.ok) {
                    throw new Error(j.error || ('HTTP ' + r.status));
                }
                return j;
            });
        });
    }

    function positionPanel(panel, btn) {
        var rect = btn.getBoundingClientRect();
        panel.style.position = 'fixed';
        panel.style.top = (rect.bottom + 4) + 'px';
        // Right-align to the button so the panel doesn't escape the
        // toolbar; clamp left to viewport.
        var panelW = 320;
        var left = Math.min(rect.left, window.innerWidth - panelW - 12);
        panel.style.left = Math.max(12, left) + 'px';
        panel.style.width = panelW + 'px';
        panel.style.zIndex = '9999';
    }

    function bindOne(btn) {
        if (btn._dtColsBound) return;
        btn._dtColsBound = true;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            // Close any existing panel before opening a new one
            document.querySelectorAll('.dt-cols-panel').forEach(function (p) {
                if (p._close) p._close();
                else if (p.parentNode) p.parentNode.removeChild(p);
            });
            var wrap = btn.closest('.dt-wrap');
            if (!wrap) return;
            var panel = buildPanel(wrap, btn);
            if (!panel) return;
            document.body.appendChild(panel);
            positionPanel(panel, btn);
            // Reposition on scroll/resize
            var reposition = function () { positionPanel(panel, btn); };
            window.addEventListener('scroll', reposition, true);
            window.addEventListener('resize', reposition);
            var origClose = panel._close;
            panel._close = function () {
                window.removeEventListener('scroll', reposition, true);
                window.removeEventListener('resize', reposition);
                if (origClose) origClose();
            };
        });
    }

    function initAll() {
        document.querySelectorAll('[data-dt-cols-btn]').forEach(bindOne);
    }

    // Expose so the SPA shell can re-init after content swap.
    window.MagDynDataTable = window.MagDynDataTable || {};
    var prevBindAll = window.MagDynDataTable.bindAll;
    window.MagDynDataTable.bindAll = function () {
        if (prevBindAll) prevBindAll.apply(this, arguments);
        initAll();
    };

    if (document.readyState !== 'loading') initAll();
    else document.addEventListener('DOMContentLoaded', initAll);
})();
