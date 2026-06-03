/* ============================================================
   MagDyn — App-level JS (service worker registration, etc.)
   Created: 20260515_060024_IST
   ============================================================ */
(function () {
    'use strict';

    // ---- Sidebar group toggling --------------------------------
    // Click (or Alt+letter via shortcuts.js) on a .nav-group-toggle expands/
    // collapses its children panel. State is persisted in localStorage so the
    // group stays open across page loads.
    // ACCORDION behaviour: at most one group panel open at a time.
    // - Pre-paint script (in <head>) reads localStorage 'magdyn.nav.openGroup'
    //   and applies an inline stylesheet so the server-rendered HTML is
    //   visible in its final state, no flicker.
    // - We don't toggle the HTML5 `hidden` attribute here — too easy to
    //   fight with !important rules. Instead we set/clear `display` on the
    //   panel and let the pre-paint stylesheet supply the default.
    function initNavGroups() {
        var toggles = document.querySelectorAll('.nav-group-toggle');
        if (!toggles.length) return;
        var storeKey = 'magdyn.nav.openGroup';

        // Clean up older multi-state key from earlier versions.
        try { localStorage.removeItem('magdyn.nav.groups.v1'); } catch (_) {}
        try { localStorage.removeItem('magdyn.nav.groups.v2'); } catch (_) {}

        // Determine the currently-open group id. Priority:
        //   1. Persisted user choice (localStorage)
        //   2. The server-rendered .open group (auto-expanded for current page)
        //   3. None
        var openId = '';
        try { openId = localStorage.getItem(storeKey) || ''; } catch (_) {}
        if (!openId) {
            var serverOpen = document.querySelector('.nav-group-toggle.open');
            if (serverOpen) openId = serverOpen.getAttribute('data-group');
        }

        function applyState(activeId) {
            toggles.forEach(function (btn) {
                var id    = btn.getAttribute('data-group');
                var panel = document.getElementById(id);
                if (!panel) return;
                var isOpen = id === activeId;
                btn.classList.toggle('open', isOpen);
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                // Use display, not the `hidden` attribute, so we never collide
                // with the pre-paint stylesheet. Clearing inline style lets
                // the stylesheet fall through; setting block/none takes over.
                panel.style.display = isOpen ? 'block' : 'none';
                panel.removeAttribute('hidden');
            });
        }
        applyState(openId);

        toggles.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var id = btn.getAttribute('data-group');
                // If this group is already open, close it (and persist empty).
                var nowOpen = btn.classList.contains('open') ? '' : id;
                applyState(nowOpen);
                try { localStorage.setItem(storeKey, nowOpen); } catch (_) {}
            });
        });
    }
    if (document.readyState !== 'loading') initNavGroups();
    else document.addEventListener('DOMContentLoaded', initNavGroups);

    // ---- Sub-group (third-level) toggling ----------------------
    // These behave differently from top-level groups: they don't
    // participate in the accordion. Multiple sub-groups can be open
    // simultaneously. State persists per-sub-group in localStorage
    // so the user's choice survives navigation.
    function initNavSubGroups() {
        var toggles = document.querySelectorAll('.nav-subgroup-toggle');
        if (!toggles.length) return;
        var storeKey = 'magdyn.nav.openSubGroups';
        var openSet = {};
        try {
            var raw = localStorage.getItem(storeKey);
            if (raw) {
                var arr = JSON.parse(raw);
                if (Array.isArray(arr)) arr.forEach(function (id) { openSet[id] = true; });
            }
        } catch (_) {}
        // If localStorage has nothing yet, seed from server-rendered .open state
        // so the sub-group containing the current page stays expanded.
        if (Object.keys(openSet).length === 0) {
            toggles.forEach(function (btn) {
                if (btn.classList.contains('open')) {
                    openSet[btn.getAttribute('data-group')] = true;
                }
            });
        }
        function applyOne(btn) {
            var id    = btn.getAttribute('data-group');
            var panel = document.getElementById(id);
            if (!panel) return;
            var isOpen = !!openSet[id];
            btn.classList.toggle('open', isOpen);
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (isOpen) panel.removeAttribute('hidden');
            else panel.setAttribute('hidden', '');
        }
        toggles.forEach(applyOne);
        toggles.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var id = btn.getAttribute('data-group');
                if (openSet[id]) delete openSet[id];
                else openSet[id] = true;
                applyOne(btn);
                try {
                    localStorage.setItem(storeKey, JSON.stringify(Object.keys(openSet)));
                } catch (_) {}
            });
        });
    }
    if (document.readyState !== 'loading') initNavSubGroups();
    else document.addEventListener('DOMContentLoaded', initNavSubGroups);

    // Register service worker — only on mobile shell to keep desktop simple.
    if ('serviceWorker' in navigator && window.MAGDYN_SW !== false) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(window.MAGDYN_BASE + '/service-worker.js', {
                scope: window.MAGDYN_BASE + '/'
            }).catch(function (e) { console.warn('SW registration failed', e); });
        });
    }

    // Convert base64-url VAPID key to Uint8Array (Push API requirement)
    function urlBase64ToUint8Array(b64) {
        var padding = '='.repeat((4 - b64.length % 4) % 4);
        var s = (b64 + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(s);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    // Public helper used by mobile_settings.php's "Enable push" button.
    window.MagDyn = window.MagDyn || {};
    window.MagDyn.subscribePush = function (vapidPublicKey) {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            alert('Push notifications are not supported on this browser.');
            return;
        }
        if (!vapidPublicKey) {
            alert('VAPID public key is missing in config/app.config.php.');
            return;
        }
        Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') return;
            navigator.serviceWorker.ready.then(function (reg) {
                reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                }).then(function (sub) {
                    fetch(window.MAGDYN_BASE + '/api/push_subscribe.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(sub)
                    }).then(function (r) {
                        if (r.ok) alert('Push notifications enabled on this device.');
                        else alert('Failed to register subscription with server.');
                    });
                });
            });
        });
    };
})();

/* ============================================================
   Auto-inject a Cancel button beside every form's Save button.
   Avoids editing every individual create/edit page manually.

   Heuristic: find each <button type="submit"> inside <form> that
   doesn't already have a sibling Cancel anchor, and inject one.
   The Cancel link goes to history.back() — works for any
   create/edit page reached from a list.
   ============================================================ */
(function () {
    function addCancelButtons() {
        document.querySelectorAll('form .form-actions').forEach(function (fa) {
            // Skip if a Cancel button/link already exists in this actions block.
            if (fa.querySelector('[data-form-cancel]')) return;
            var saveBtn = fa.querySelector('button[type="submit"]');
            if (!saveBtn) return;
            var cancel = document.createElement('a');
            cancel.className = 'btn btn-ghost';
            cancel.href = '#';
            cancel.textContent = 'Cancel';
            cancel.setAttribute('data-form-cancel', '1');
            cancel.style.marginLeft = '6px';
            cancel.title = 'Discard changes and go back';
            cancel.addEventListener('click', function (e) {
                e.preventDefault();
                if (history.length > 1) history.back();
                else window.location.href = window.MAGDYN_BASE || '/';
            });
            // Insert immediately after the Save button so tab order stays
            // Save → Cancel → other actions.
            saveBtn.insertAdjacentElement('afterend', cancel);
        });
    }
    if (document.readyState !== 'loading') addCancelButtons();
    else document.addEventListener('DOMContentLoaded', addCancelButtons);
    // Expose for SPA re-init after content swap.
    window.MagDynForms = { addCancelButtons: addCancelButtons };
})();

/* ============================================================
   Sidebar collapse toggle.
   - Adds .collapsed to the <aside.sidebar> AND html.sidebar-collapsed
     so CSS rules can target whichever is convenient.
   - Persisted in localStorage.
   - Listens on document for the toggle button click (so SPA
     swaps don't lose the binding).
   ============================================================ */
(function () {
    var KEY = 'magdyn.sidebar.collapsed';
    function setCollapsed(on, persist) {
        document.documentElement.classList.toggle('sidebar-collapsed', !!on);
        document.querySelectorAll('.sidebar').forEach(function (s) {
            s.classList.toggle('collapsed', !!on);
        });
        // Skip persistence on pages that auto-collapse the sidebar
        // (like the manuals viewer). Otherwise toggling the button
        // while reading a manual would overwrite the user's global
        // sidebar preference. The page-level CSS class signals
        // we're in such a mode.
        var inAutoCollapseMode = document.body && document.body.classList.contains('is-manual-view');
        if (persist !== false && !inAutoCollapseMode) {
            try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (_) {}
        }
        document.querySelectorAll('#sidebarCollapseBtn').forEach(function (b) {
            b.textContent = on ? '»' : '«';
            b.setAttribute('aria-label', on ? 'Expand sidebar' : 'Collapse sidebar');
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target || e.target.id !== 'sidebarCollapseBtn') return;
        var nowCollapsed = !document.documentElement.classList.contains('sidebar-collapsed');
        setCollapsed(nowCollapsed);
    });
    function syncOnLoad() {
        // The pre-paint script set html.sidebar-collapsed before render.
        // Now mirror that onto the aside and the button glyph.
        var on = document.documentElement.classList.contains('sidebar-collapsed');
        document.querySelectorAll('.sidebar').forEach(function (s) { s.classList.toggle('collapsed', on); });
        document.querySelectorAll('#sidebarCollapseBtn').forEach(function (b) { b.textContent = on ? '»' : '«'; });
    }
    if (document.readyState !== 'loading') syncOnLoad();
    else document.addEventListener('DOMContentLoaded', syncOnLoad);
})();
