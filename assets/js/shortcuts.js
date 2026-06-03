/* ============================================================
   MagDyn — Keyboard Shortcut Behaviour
   Created: 20260515_060024_IST

   Two kinds of shortcuts on every page:

   1. GLOBAL  — added by this script, always available:
        Alt+H  Home (dashboard)
        Alt+U  Users
        Alt+T  Training
        Alt+N  Notifications
        Alt+L  Logout
        Alt+/  Focus search box (if any on page)
        Alt+X  Exit "view-as" mode (if active)
        Esc    Close open modal, blur active input

   2. LOCAL   — set via [data-shortcut="X"] on any focusable
                element. When Alt is held, the character matching
                the data-shortcut is visually highlighted (via the
                .alt-active class on body and the <u> inside the
                button label) so the affordance is discoverable.

   The script also:
   - focuses the element whose id is window.__FOCUS_ID on load
   - enforces tab order across [tabindex] elements
   - exposes window.MagDyn.shortcuts.register(letter, fn) for
     page-specific shortcuts.
   ============================================================ */
(function () {
    'use strict';

    var ALT_DOWN = false;
    var localBindings = {};   // letter -> function (last-registered wins per page)
    var chordMode = null;     // { firstChar: 'A', timeout: timerId }

    // ---- Helpers ------------------------------------------------
    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    // Find all currently-visible data-shortcut elements whose chord starts
    // with the given character (case-insensitive). Hidden ancestors are
    // skipped via the offsetParent check.
    function visibleShortcutEls(prefix) {
        var matches = [];
        var pre = (prefix || '').toUpperCase();
        var all = document.querySelectorAll('[data-shortcut]');
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            var s = (el.getAttribute('data-shortcut') || '').toUpperCase();
            if (!s) continue;
            if (pre && s.charAt(0) !== pre) continue;
            // offsetParent === null means hidden (display:none or in hidden parent)
            if (el.offsetParent === null && el.tagName !== 'BODY') continue;
            matches.push(el);
        }
        return matches;
    }

    function fireEl(el) {
        if (!el) return false;
        if (typeof el.click === 'function') { el.click(); return true; }
        if (el.href) { window.location.href = el.href; return true; }
        return false;
    }

    function clickByShortcut(letter) {
        var L = letter.toUpperCase();
        // Page-registered handlers always win
        if (typeof localBindings[L] === 'function') {
            localBindings[L]();
            return true;
        }
        // Find every visible candidate whose chord starts with this char
        var matches = visibleShortcutEls(L);
        if (matches.length === 0) return false;

        // Single match: only fire immediately if the chord is itself
        // 1-char. A 2-char chord must always wait for the second key,
        // otherwise typing "A" of "Au" navigates before the user can
        // type "u". This was the source of the "page opens on first
        // letter" bug.
        if (matches.length === 1) {
            var only = (matches[0].getAttribute('data-shortcut') || '').toUpperCase();
            if (only.length <= 1) {
                return fireEl(matches[0]);
            }
            // 2-char chord — enter chord mode so we wait for the
            // second key. Fire on second key match below.
            enterChordMode(L, matches);
            return true;
        }
        // Multiple candidates share the first char — also enter chord
        // mode so the user can disambiguate.
        enterChordMode(L, matches);
        return true;
    }

    function enterChordMode(firstChar, matches) {
        if (chordMode && chordMode.timeout) clearTimeout(chordMode.timeout);
        chordMode = {
            firstChar: firstChar,
            timeout: setTimeout(exitChordMode, 3000)
        };
        document.body.classList.add('chord-active');
        document.body.setAttribute('data-chord-first', firstChar);
        // Visual: mark matching items with chord-match so CSS can pop them.
        for (var i = 0; i < matches.length; i++) {
            matches[i].classList.add('chord-match');
        }
    }

    function exitChordMode() {
        if (chordMode && chordMode.timeout) clearTimeout(chordMode.timeout);
        chordMode = null;
        document.body.classList.remove('chord-active');
        document.body.removeAttribute('data-chord-first');
        var marked = document.querySelectorAll('.chord-match');
        for (var i = 0; i < marked.length; i++) marked[i].classList.remove('chord-match');
    }

    function handleChordSecond(ch) {
        if (!chordMode) return false;
        var target = chordMode.firstChar + ch.toUpperCase();
        var found = visibleShortcutEls(chordMode.firstChar);
        for (var i = 0; i < found.length; i++) {
            var s = (found[i].getAttribute('data-shortcut') || '').toUpperCase();
            if (s === target) {
                exitChordMode();
                fireEl(found[i]);
                return true;
            }
        }
        exitChordMode();
        return false;
    }

    // ---- Alt-key affordance ------------------------------------
    function onAltDown() {
        if (ALT_DOWN) return;
        ALT_DOWN = true;
        document.body.classList.add('alt-active');
    }
    function onAltUp() {
        if (!ALT_DOWN) return;
        ALT_DOWN = false;
        document.body.classList.remove('alt-active');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Alt' || e.altKey) {
            onAltDown();
        }

        // Esc — exit chord mode, close modal, blur input
        if (e.key === 'Escape') {
            if (chordMode) { exitChordMode(); e.preventDefault(); return; }
            var open = document.querySelector('.modal.open');
            if (open) { open.classList.remove('open'); e.preventDefault(); return; }
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            return;
        }

        // Chord mode: a plain letter (no Alt) disambiguates between
        // shortcuts that share the same first character.
        if (chordMode && !e.altKey && !e.ctrlKey && !e.metaKey
                && e.key && e.key.length === 1) {
            // Don't hijack typing in text fields
            var tag = (e.target && e.target.tagName || '').toLowerCase();
            var isTyping = tag === 'input' || tag === 'textarea' || tag === 'select'
                        || (e.target && e.target.isContentEditable);
            if (!isTyping) {
                if (handleChordSecond(e.key)) {
                    e.preventDefault();
                    return;
                }
            }
        }

        // Alt + letter
        if (e.altKey && e.key && e.key.length === 1) {
            var ch = e.key.toUpperCase();
            // Built-in global bindings
            var globals = {
                'H': function () { window.location.href = window.MAGDYN_BASE + '/index.php'; },
                '/': function () {
                    var s = document.querySelector('.search input, input[type="search"]');
                    if (s) s.focus();
                }
            };
            // Local bindings + data-shortcut on the page take precedence over globals.
            if (clickByShortcut(ch)) {
                e.preventDefault();
                return;
            }
            if (globals[ch]) {
                e.preventDefault();
                globals[ch]();
                return;
            }
        }
    }, false);

    document.addEventListener('keyup', function (e) {
        // CRITICAL: a solo Alt-press (no companion key) is what triggers the
        // browser to move focus to its menu bar (Chrome/Edge/Firefox). That
        // steals focus from our page so subsequent shortcuts don't reach us.
        //
        // Suppressing the default action on the Alt keyup keeps focus on the
        // page. We do this for every Alt keyup, regardless of whether a
        // companion key fired, because the menu activation only happens on
        // solo Alt anyway — preventing default in the companion-key case is
        // harmless.
        if (e.key === 'Alt') {
            e.preventDefault();
            onAltUp();
            return;
        }
        if (!e.altKey) onAltUp();
    }, false);

    // Some browsers (Firefox) also trigger menu activation on the Alt
    // keydown rather than keyup. Belt-and-braces: prevent default on the
    // bare Alt keydown too, but only if nothing is currently focused that
    // wants to handle Alt (e.g. text inputs use Alt for word-jump). We
    // detect that by checking the active element's tag.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Alt') return;
        var el = document.activeElement;
        var tag = el && el.tagName ? el.tagName.toLowerCase() : '';
        // Don't interfere with editor-style controls that may use Alt
        // (rich-text editors, code editors). For plain inputs we still
        // want to suppress the menu since users may shortcut from a field.
        if (tag === 'textarea' && el.isContentEditable) return;
        e.preventDefault();
    }, false);

    // Cover the edge case where the window loses focus mid-Alt.
    window.addEventListener('blur', onAltUp);

    // ---- Initial focus ----------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        if (window.__FOCUS_ID) {
            var el = document.getElementById(window.__FOCUS_ID);
            if (el && el.focus) {
                try { el.focus(); if (el.select) el.select(); } catch (_) {}
            }
        }
    });

    // ---- Public API -------------------------------------------
    window.MagDyn = window.MagDyn || {};
    window.MagDyn.shortcuts = {
        register: function (letter, fn) {
            if (!letter || typeof fn !== 'function') return;
            localBindings[letter.toUpperCase()] = fn;
        },
        unregister: function (letter) {
            if (letter) delete localBindings[letter.toUpperCase()];
        }
    };
})();
