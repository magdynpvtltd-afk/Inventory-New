<?php
/**
 * Shared apps-menu block (trigger + dropdown + CSS + JS).
 * Fully self-contained: emits CSS and JS once via a static flag.
 *
 * Caller sets:
 *   $current_page    — string, e.g. 'bubble_tool.php'
 *   $trigger_style   — 'dark' (sidebar) or 'light' (page-head)
 */
$current_page  = $current_page  ?? '';
$trigger_style = $trigger_style ?? 'dark';

function ams_current($current, $tool, $manual) {
    return ($current === $tool || $current === $manual) ? ' aria-current="page"' : '';
}

$trigger_class = ($trigger_style === 'light') ? 'apps-trigger-light' : 'apps-trigger';
$wrap_style    = ($trigger_style === 'light') ? ' style="display:inline-block;"' : '';
$label_class   = ($trigger_style === 'dark')  ? ' class="label"' : '';

static $emitted = false;
$first = !$emitted;
$emitted = true;
?>
<?php if ($first): ?>
<style>
.apps-menu-wrap { position: relative; }

.apps-trigger {
    display: flex !important; align-items: center; gap: 10px;
    padding: 12px 14px;
    margin: 10px 8px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.85);
    font-family: inherit; font-size: 13px; font-weight: 500;
    border-radius: 8px; cursor: pointer;
    width: calc(100% - 16px);
    text-align: left;
}
.apps-trigger:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.18); color: white; }
.apps-trigger[aria-expanded="true"] { background: rgba(30,58,138,0.4); border-color: rgba(255,255,255,0.25); color: white; }
.apps-trigger .ico { font-size: 16px; line-height: 1; color: rgba(255,255,255,0.9); flex-shrink: 0; }
.apps-trigger .label { flex: 1; }
.apps-trigger .chev { font-size: 10px; opacity: 0.6; transition: transform 0.15s; }
.apps-trigger[aria-expanded="true"] .chev { transform: rotate(180deg); }

.apps-trigger-light {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 12px;
    background: var(--surface, #fff);
    border: 1px solid var(--border-strong, #d0d4dc);
    color: var(--text, #1a1f2e);
    font-family: inherit; font-size: 13px; font-weight: 500;
    border-radius: 8px; cursor: pointer;
}
.apps-trigger-light:hover { border-color: var(--primary, #1e3a8a); }
.apps-trigger-light[aria-expanded="true"] { background: var(--surface-alt, #f8f9fb); border-color: var(--primary, #1e3a8a); }
.apps-trigger-light .ico { font-size: 14px; color: var(--primary, #1e3a8a); line-height: 1; }
.apps-trigger-light .chev { font-size: 9px; opacity: 0.5; }

.apps-dropdown {
    position: fixed !important;
    z-index: 2147483647 !important;
    min-width: 300px;
    background: #ffffff !important;
    border: 1px solid #e3e5ea;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);
    padding: 6px;
    display: none;
}
.apps-dropdown.open { display: block !important; }
.apps-foot {
    padding: 6px 10px 4px;
    font-size: 10px; color: #9aa1ad;
    letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;
}
.apps-row {
    display: grid; grid-template-columns: 1fr auto;
    align-items: center;
    border-radius: 6px;
}
.apps-row:hover { background: #f8f9fb; }
.apps-row[aria-current="page"] { background: #eef2ff; }
.apps-row[aria-current="page"] .apps-link .name { color: #1e3a8a; font-weight: 600; }
.apps-row[aria-current="page"] .apps-link .ico-row { background: #1e3a8a; color: white; }
.apps-link {
    display: grid; grid-template-columns: 32px 1fr;
    align-items: center; gap: 10px;
    padding: 9px 10px;
    border-radius: 6px;
    text-decoration: none;
    color: #1a1f2e;
    min-width: 0;
}
.apps-link:hover { text-decoration: none; }
.apps-link .ico-row {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    background: #f8f9fb;
    border-radius: 6px;
    color: #1e3a8a;
    font-size: 16px; line-height: 1;
}
.apps-link .body { min-width: 0; }
.apps-link .name { font-size: 13px; font-weight: 500; line-height: 1.3; color: #1a1f2e; }
.apps-link .sub { font-size: 11px; color: #6b7280; line-height: 1.3; margin-top: 1px; }
.manual-link {
    font-size: 11px; color: #6b7280; text-decoration: none;
    padding: 4px 8px; margin-right: 6px;
    border-radius: 4px; border: 1px solid transparent;
}
.manual-link:hover { border-color: #d0d4dc; color: #1e3a8a; background: #fff; text-decoration: none; }
</style>
<?php endif; ?>
<div class="apps-menu-wrap"<?= $wrap_style ?>>
    <button class="<?= $trigger_class ?>" id="apps-trigger" aria-expanded="false" aria-haspopup="true">
        <span class="ico">&#8862;</span>
        <span<?= $label_class ?>>Switch tool</span>
        <span class="chev">&#9660;</span>
    </button>
    <div class="apps-dropdown" id="apps-dropdown" role="menu">
        <div class="apps-foot">Switch tool</div>

        <div class="apps-row"<?= ams_current($current_page, 'bubble_tool.php', 'bubble_tool_training.php') ?>>
            <a href="bubble_tool.php" class="apps-link">
                <span class="ico-row">&#9737;</span>
                <div class="body">
                    <div class="name">Bubble</div>
                    <div class="sub">Drawing annotator</div>
                </div>
            </a>
            <a class="manual-link" href="bubble_tool_training.php">Manual</a>
        </div>

        <div class="apps-row"<?= ams_current($current_page, 'cad-viewer.php', 'cad-viewer-training.php') ?>>
            <a href="cad-viewer.php" class="apps-link">
                <span class="ico-row">&#128208;</span>
                <div class="body">
                    <div class="name">CAD Viewer</div>
                    <div class="sub">DXF &middot; STEP &middot; STL &middot; IGES</div>
                </div>
            </a>
            <a class="manual-link" href="cad-viewer-training.php">Manual</a>
        </div>

        <div class="apps-row"<?= ams_current($current_page, 'weight-calculator.php', 'weight-calculator-training.php') ?>>
            <a href="weight-calculator.php" class="apps-link">
                <span class="ico-row">&#9878;</span>
                <div class="body">
                    <div class="name">Engineering Toolbox</div>
                    <div class="sub">Weight &middot; Hardness &middot; Shore</div>
                </div>
            </a>
            <a class="manual-link" href="weight-calculator-training.php">Manual</a>
        </div>

        <div class="apps-row"<?= ams_current($current_page, 'engineering-calculator.php', 'engineering-calculator.php') ?>>
            <a href="engineering-calculator.php" class="apps-link">
                <span class="ico-row">&#8721;</span>
                <div class="body">
                    <div class="name">Engineering Calculator</div>
                    <div class="sub">Stack-up &middot; Cpk &middot; Fits &middot; Speeds</div>
                </div>
            </a>
        </div>

    </div>
</div>
<?php if ($first): ?>
<script>
(function() {
    if (window.__appsMenuInit) return;
    window.__appsMenuInit = true;

    function init() {
        var btn = document.getElementById('apps-trigger');
        var dd  = document.getElementById('apps-dropdown');
        if (!btn || !dd) return;

        // Eagerly reparent the dropdown to <body> so no ancestor's
        // overflow/transform/z-index can affect it.
        if (dd.parentNode !== document.body) {
            document.body.appendChild(dd);
        }

        function positionDropdown() {
            var rect = btn.getBoundingClientRect();
            if (btn.classList.contains('apps-trigger-light')) {
                dd.style.setProperty('top',   (rect.bottom + 6) + 'px',                  'important');
                dd.style.setProperty('right', (window.innerWidth - rect.right) + 'px',   'important');
                dd.style.setProperty('left',  'auto',                                    'important');
            } else {
                dd.style.setProperty('top',   rect.top + 'px',         'important');
                dd.style.setProperty('left',  (rect.right + 8) + 'px', 'important');
                dd.style.setProperty('right', 'auto',                  'important');
            }
        }
        function openDD()  { positionDropdown(); dd.classList.add('open');    btn.setAttribute('aria-expanded', 'true');  }
        function closeDD() {                     dd.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            if (dd.classList.contains('open')) closeDD(); else openDD();
        });
        document.addEventListener('click', function(e) {
            if (!btn.contains(e.target) && !dd.contains(e.target)) closeDD();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDD();
        });
        window.addEventListener('resize', function() {
            if (dd.classList.contains('open')) positionDropdown();
        });
        window.addEventListener('scroll', function() {
            if (dd.classList.contains('open')) positionDropdown();
        }, true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
<?php endif; ?>
