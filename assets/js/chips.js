/* ============================================================
   MagDyn — Chip-style searchable multi-select
   Created: 20260515_160000_IST

   Progressively enhances any <select multiple class="chips">.
   - Native <select> is hidden but remains in the DOM so the
     form posts the right name[] values on submit.
   - Visible UI is a chip strip + search input + filtered
     dropdown. Click a chip's × to deselect. Click a dropdown
     row to select. Search filters in real time.

   Keyboard:
   - Down/Up arrows in the search input move highlight in the list
   - Enter selects the highlighted row
   - Backspace in an empty search input removes the last chip
   - Esc closes the dropdown
   ============================================================ */
(function () {
    'use strict';

    function init(sel) {
        if (sel.dataset.chipsBound === '1') return;
        sel.dataset.chipsBound = '1';

        // Build wrapper
        var wrap = document.createElement('div');
        wrap.className = 'chips-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        // Move the native <select> inside the wrap and hide it
        wrap.appendChild(sel);
        sel.style.display = 'none';

        var chipsRow = document.createElement('div');
        chipsRow.className = 'chips-row';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'chips-input';
        input.placeholder = sel.getAttribute('data-placeholder') || 'Search…';
        chipsRow.appendChild(input);

        var dropdown = document.createElement('div');
        dropdown.className = 'chips-dropdown';
        dropdown.style.display = 'none';

        wrap.appendChild(chipsRow);
        wrap.appendChild(dropdown);

        // ----- Data model -----
        function allOptions() {
            return Array.prototype.map.call(sel.options, function (o) {
                return { value: o.value, label: o.textContent, selected: o.selected };
            });
        }

        function setSelected(value, isSelected) {
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === value) {
                    sel.options[i].selected = isSelected;
                    break;
                }
            }
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // ----- Render the chip strip -----
        function renderChips() {
            // Clear existing chips (keep the input)
            Array.prototype.slice.call(chipsRow.querySelectorAll('.chip')).forEach(function (c) { c.remove(); });
            var opts = allOptions();
            opts.forEach(function (o) {
                if (!o.selected) return;
                var chip = document.createElement('span');
                chip.className = 'chip';
                chip.dataset.value = o.value;
                chip.innerHTML = '<span class="chip-label"></span><button type="button" class="chip-x" aria-label="Remove">×</button>';
                chip.querySelector('.chip-label').textContent = o.label;
                chip.querySelector('.chip-x').addEventListener('click', function () {
                    setSelected(o.value, false);
                    renderChips();
                });
                chipsRow.insertBefore(chip, input);
            });
        }

        // ----- Render the dropdown rows (filtered) -----
        var highlightIdx = -1;
        function renderDropdown() {
            var q = input.value.trim().toLowerCase();
            var opts = allOptions().filter(function (o) {
                if (o.selected) return false;
                if (!q) return true;
                return o.label.toLowerCase().indexOf(q) !== -1;
            });
            dropdown.innerHTML = '';
            if (!opts.length) {
                var none = document.createElement('div');
                none.className = 'chips-empty';
                none.textContent = q ? 'No matches' : 'No more options';
                dropdown.appendChild(none);
                highlightIdx = -1;
                return;
            }
            opts.forEach(function (o, idx) {
                var row = document.createElement('div');
                row.className = 'chips-option';
                row.dataset.value = o.value;
                row.textContent = o.label;
                if (idx === highlightIdx) row.classList.add('hl');
                row.addEventListener('mousedown', function (e) {
                    // mousedown not click — so focus stays in input
                    e.preventDefault();
                    setSelected(o.value, true);
                    renderChips();
                    input.value = '';
                    renderDropdown();
                });
                row.addEventListener('mouseenter', function () {
                    highlightIdx = idx;
                    refreshHighlight();
                });
                dropdown.appendChild(row);
            });
            if (highlightIdx >= opts.length) highlightIdx = opts.length - 1;
            if (highlightIdx < 0) highlightIdx = 0;
            refreshHighlight();
        }
        function refreshHighlight() {
            var rows = dropdown.querySelectorAll('.chips-option');
            for (var i = 0; i < rows.length; i++) {
                rows[i].classList.toggle('hl', i === highlightIdx);
            }
            if (rows[highlightIdx]) {
                var el = rows[highlightIdx];
                // Scroll into view if needed
                var elTop = el.offsetTop, elBot = elTop + el.offsetHeight;
                if (elTop < dropdown.scrollTop) dropdown.scrollTop = elTop;
                else if (elBot > dropdown.scrollTop + dropdown.clientHeight) {
                    dropdown.scrollTop = elBot - dropdown.clientHeight;
                }
            }
        }

        function openDropdown() {
            dropdown.style.display = '';
            renderDropdown();
        }
        function closeDropdown() {
            dropdown.style.display = 'none';
        }

        // ----- Events -----
        input.addEventListener('focus', openDropdown);
        input.addEventListener('input', function () {
            highlightIdx = 0;
            openDropdown();
        });
        input.addEventListener('keydown', function (e) {
            var rows = dropdown.querySelectorAll('.chips-option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (dropdown.style.display === 'none') openDropdown();
                highlightIdx = Math.min(highlightIdx + 1, rows.length - 1);
                refreshHighlight();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightIdx = Math.max(highlightIdx - 1, 0);
                refreshHighlight();
            } else if (e.key === 'Enter') {
                if (rows[highlightIdx]) {
                    e.preventDefault();
                    setSelected(rows[highlightIdx].dataset.value, true);
                    renderChips();
                    input.value = '';
                    renderDropdown();
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
                input.blur();
            } else if (e.key === 'Backspace' && input.value === '') {
                // Remove last chip
                var chips = chipsRow.querySelectorAll('.chip');
                if (chips.length) {
                    var last = chips[chips.length - 1];
                    setSelected(last.dataset.value, false);
                    renderChips();
                    renderDropdown();
                }
            }
        });

        // Click on the wrap (but not on a chip's ×) focuses the input
        wrap.addEventListener('click', function (e) {
            if (e.target.closest('.chip-x')) return;
            if (e.target.closest('.chips-option')) return;
            input.focus();
        });

        // Close on outside click
        document.addEventListener('mousedown', function (e) {
            if (!wrap.contains(e.target)) closeDropdown();
        });

        renderChips();
    }

    function initAll(root) {
        (root || document).querySelectorAll('select[multiple].chips').forEach(init);
    }

    window.MagDynChips = { initAll: initAll };

    if (document.readyState !== 'loading') initAll();
    else document.addEventListener('DOMContentLoaded', function () { initAll(); });
})();
