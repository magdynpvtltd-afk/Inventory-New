/* ============================================================
   MagDyn — Lightweight SPA shell
   Created: 20260515_122000_IST

   Intercepts clicks on internal links and form submissions for
   simple GET forms, fetches the response, and swaps only the
   <main> region. The sidebar never re-paints.

   Heuristics for "shouldn't intercept":
   - External links (different origin)
   - Links with target="_blank" or download
   - Links with rel="external" or data-no-spa
   - Modifier-key clicks (ctrl/cmd/shift/alt) and middle-click
   - POST forms, multipart forms, or forms with data-no-spa
   - Forms whose action goes to a different origin

   The shell calls a re-init hook after swap so other modules
   (datatable hydration, sortable headers, etc.) re-bind.
   ============================================================ */
(function () {
    'use strict';

    // Master switch — bail out if no <main> in DOM
    var mainEl = document.querySelector('main.main, main');
    if (!mainEl) return;

    function sameOrigin(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.origin === window.location.origin;
        } catch (_) { return false; }
    }

    function isInternalAnchor(a) {
        if (!a || a.tagName !== 'A') return false;
        if (a.target && a.target !== '' && a.target !== '_self') return false;
        if (a.hasAttribute('download')) return false;
        if (a.getAttribute('rel') === 'external') return false;
        if (a.hasAttribute('data-no-spa')) return false;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#') return false;
        if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;
        if (!sameOrigin(a.href)) return false;
        return true;
    }

    function setActiveSidebarFor(url) {
        // Update the .active class on sidebar items so the visual cue
        // moves to the new page. We match on pathname + a few discriminator
        // query params:
        //   action   — used by asset/inventory etc. (asset.php?action=models)
        //   type     — used by categories.php tabs
        //   tool     — used by tools.php (?tool=bubble|cad|weight|calc)
        //   view     — used by tools.php's nested sub-tools (?view=stackup)
        // All other query keys (dt_*, status, q, page, etc.) are stripped
        // so transient state doesn't affect which nav item is highlighted.
        function fingerprint(u) {
            try {
                var parsed = new URL(u, window.location.href);
                var sp     = parsed.searchParams;
                var action = sp.get('action') || '';
                var type   = sp.get('type')   || '';
                var tool   = sp.get('tool')   || '';
                var view   = sp.get('view')   || '';
                return parsed.pathname + '|' + action + '|' + type + '|' + tool + '|' + view;
            } catch (_) { return ''; }
        }
        var target = fingerprint(url);
        var newlyActive = null;
        document.querySelectorAll('.sidebar .nav-item').forEach(function (el) {
            var href = el.getAttribute('href');
            if (!href) { el.classList.remove('active'); return; }
            var match = fingerprint(href) === target;
            el.classList.toggle('active', match);
            if (match) newlyActive = el;
        });
        // Drop any lingering focus on a sidebar link so the browser's
        // :focus ring doesn't sit on the link that was clicked to start
        // this navigation. The new page's content is what should hold
        // focus, not the trigger.
        var focused = document.activeElement;
        if (focused && focused.closest && focused.closest('.sidebar')) {
            try { focused.blur(); } catch (_) {}
        }
        // If the newly-active item is inside a collapsed group, auto-open
        // that group (and accordion-close all others).
        if (newlyActive) {
            var panel = newlyActive.closest('.nav-group-children');
            if (panel && panel.id) {
                document.querySelectorAll('.nav-group-toggle').forEach(function (btn) {
                    var id = btn.getAttribute('data-group');
                    var p  = document.getElementById(id);
                    var isOpen = id === panel.id;
                    btn.classList.toggle('open', isOpen);
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    if (p) p.style.display = isOpen ? 'block' : 'none';
                });
                try { localStorage.setItem('magdyn.nav.openGroup', panel.id); } catch (_) {}
            }
            // Also auto-expand the sub-group if the newly-active item is a
            // third-level grandchild (e.g. calc_stackup under tools_calc
            // under tools). Sub-groups are independent — opening one does
            // not close others.
            var sgPanel = newlyActive.closest('.nav-subgroup-children');
            if (sgPanel && sgPanel.id) {
                var sgBtn = document.querySelector(
                    '.nav-subgroup-toggle[data-group="' + sgPanel.id + '"]'
                );
                if (sgBtn) {
                    sgBtn.classList.add('open');
                    sgBtn.setAttribute('aria-expanded', 'true');
                    sgPanel.removeAttribute('hidden');
                    try {
                        var key = 'magdyn.nav.openSubGroups';
                        var arr = [];
                        try {
                            var raw = localStorage.getItem(key);
                            if (raw) { var parsed = JSON.parse(raw);
                                if (Array.isArray(parsed)) arr = parsed; }
                        } catch (_) {}
                        if (arr.indexOf(sgPanel.id) === -1) arr.push(sgPanel.id);
                        localStorage.setItem(key, JSON.stringify(arr));
                    } catch (_) {}
                }
            }
        }
    }

    function reinitAfterSwap() {
        // Rebind datatable wraps
        if (window.MagDynDataTable && typeof window.MagDynDataTable.bindAll === 'function') {
            window.MagDynDataTable.bindAll();
        }
        if (window.MagDynChips && typeof window.MagDynChips.initAll === 'function') {
            window.MagDynChips.initAll();
        }
        if (window.MagDynBomDesigner && typeof window.MagDynBomDesigner.init === 'function') {
            window.MagDynBomDesigner.init();
        }
        if (window.MagDynCombobox && typeof window.MagDynCombobox.initAll === 'function') {
            window.MagDynCombobox.initAll();
        }
        if (window.MagDynForms && typeof window.MagDynForms.addCancelButtons === 'function') {
            window.MagDynForms.addCancelButtons();
        }
        // Re-focus the element flagged by the new page
        if (window.__FOCUS_ID) {
            var el = document.getElementById(window.__FOCUS_ID);
            if (el && el.focus) {
                try { el.focus(); if (el.select) el.select(); } catch (_) {}
            }
        }
    }

    function showLoading(on) {
        document.body.classList.toggle('spa-loading', !!on);
    }

    function swap(html, url, pushHistory) {
        var doc;
        try {
            doc = new DOMParser().parseFromString(html, 'text/html');
        } catch (e) { window.location.href = url; return; }

        var newMain = doc.querySelector('main.main, main');
        if (!newMain) { window.location.href = url; return; }

        // Update <title> and any per-page <script> hints in head
        if (doc.title) document.title = doc.title;
        // Pull __FOCUS_ID from the new doc's head (it's set as window.__FOCUS_ID)
        // Re-evaluate by extracting any inline scripts that set it.
        var headScripts = doc.querySelectorAll('head script');
        headScripts.forEach(function (s) {
            if (s.textContent && /__FOCUS_ID/.test(s.textContent)) {
                try { new Function(s.textContent)(); } catch (e) {}
            }
        });

        // Swap main content
        // Clear the has-dt-wrap class on both <html> and <body> BEFORE
        // injecting the new page. data_table_render() emits an inline
        // script that re-adds the class when the new page actually
        // contains a datatable. If the new page is a form or any other
        // non-list view, the class stays off — those pages need normal
        // page-level scrolling, which the class otherwise locks to
        // viewport (overflow:hidden on html/body/.main).
        document.documentElement.classList.remove('has-dt-wrap');
        document.body.classList.remove('has-dt-wrap');

        mainEl.innerHTML = newMain.innerHTML;

        // Run any <script> tags that arrived inside the new main (they
        // don't execute when set via innerHTML, so we recreate them).
        mainEl.querySelectorAll('script').forEach(function (oldScript) {
            var ns = document.createElement('script');
            for (var i = 0; i < oldScript.attributes.length; i++) {
                var a = oldScript.attributes[i];
                ns.setAttribute(a.name, a.value);
            }
            ns.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(ns, oldScript);
        });

        if (pushHistory) {
            history.pushState({ spa: true }, '', url);
        }

        setActiveSidebarFor(url);
        window.scrollTo(0, 0);
        reinitAfterSwap();
    }

    function go(url, pushHistory) {
        showLoading(true);
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-MagDyn-SPA': '1', 'Accept': 'text/html' }
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                // Don't swap if the server redirected to a different origin
                if (!sameOrigin(r.url)) { window.location.href = r.url; return null; }
                return r.text().then(function (text) { return { text: text, url: r.url }; });
            })
            .then(function (out) {
                if (!out) return;
                swap(out.text, out.url, pushHistory);
            })
            .catch(function () {
                window.location.href = url;
            })
            .then(function () { showLoading(false); });
    }

    // ---- Intercept link clicks ----
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented) return;
        if (e.button !== 0) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest && e.target.closest('a');
        if (!isInternalAnchor(a)) return;
        e.preventDefault();
        go(a.href, true);
    });

    // ---- Intercept simple GET form submissions ----
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.hasAttribute('data-no-spa')) return;
        var method = (form.method || 'get').toLowerCase();
        if (method !== 'get') return;
        if ((form.enctype || '').indexOf('multipart') !== -1) return;
        var action = form.getAttribute('action') || window.location.href;
        if (!sameOrigin(action)) return;

        e.preventDefault();
        var fd = new FormData(form);
        var params = new URLSearchParams();
        fd.forEach(function (v, k) { params.append(k, v); });
        var sep = action.indexOf('?') === -1 ? '?' : '&';
        var target = action + (params.toString() ? sep + params.toString() : '');
        go(target, true);
    });

    // ---- Back / forward ----
    window.addEventListener('popstate', function () {
        go(window.location.href, false);
    });
})();
