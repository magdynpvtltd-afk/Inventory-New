<?php
/**
 * MagDyn — Engineering Tools wrapper
 * Created: 20260517_080000_IST
 *
 * Renders MagDyn's standard header + sidebar, then embeds one of the
 * engineering tools (which live under /tools/*.php) inside an iframe.
 * Each tool keeps its own internal layout untouched; this wrapper
 * supplies the MagDyn chrome around it so users don't lose their
 * navigation context when switching to a tool.
 *
 * URL: /tools.php?tool=bubble|cad|weight|calc
 *      or /tools.php (landing page with cards)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('tools', 'view');

$tool = (string)input('tool', '');

// Whitelist of tools we know about. Each entry has the relative iframe
// src, a friendly title, an icon, and a brief description. Adding a new
// tool means dropping its PHP in /tools/ and adding a row here.
$TOOLS = [
    'bubble' => [
        'src'   => 'tools/bubble_tool.php',
        'title' => 'Bubble · Drawing annotator',
        'icon'  => '◯',
        'desc'  => 'Open a PDF or image of a drawing, place numbered balloon callouts, and export a marked-up PDF for inspection records.',
        'manual' => 'tools/bubble_tool_training.php',
    ],
    'cad' => [
        'src'   => 'tools/cad-viewer.php',
        'title' => 'CAD Viewer',
        'icon'  => '⌧',
        'desc'  => 'Open DXF, STL, OBJ, STEP, IGES, and 3DS files in the browser. Pan, rotate, measure. No upload — runs locally.',
        'manual' => 'tools/cad-viewer-training.php',
    ],
    'weight' => [
        'src'   => 'tools/weight-calculator.php',
        'title' => 'Weight & Material Calculator',
        'icon'  => '⚖',
        'desc'  => 'Profile × material → mass. Volume, density, surface area. Plus steels-hardness conversions (ASTM E140) and Shore durometer for rubber / plastic.',
        'manual' => 'tools/weight-calculator-training.php',
    ],
    'calc' => [
        'src'   => 'tools/engineering-calculator.php',
        'title' => 'Engineering Calculator',
        'icon'  => '∑',
        'desc'  => 'Beam-, stress-, fastener-, and unit-conversion calculators for day-to-day engineering work.',
        'manual' => 'tools/engineering-calculator-training.php',
    ],
];

if ($tool !== '' && !isset($TOOLS[$tool])) {
    flash_set('error', 'Unknown tool: ' . htmlspecialchars($tool));
    redirect(url('/tools.php'));
}

$page_module = 'tools';

if ($tool === '') {
    // ---- Landing page: card grid of available tools ----
    $page_title = 'Engineering Tools';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>Engineering Tools</h1>
            <p class="muted">Drawing annotation, CAD preview, and calculators. All run client-side — nothing uploaded.</p>
        </div>
    </div>

    <div class="tool-card-grid">
        <?php foreach ($TOOLS as $key => $t): ?>
            <a class="tool-card" href="<?= h(url('/tools.php?tool=' . $key)) ?>">
                <div class="tool-card-icon" aria-hidden="true"><?= h($t['icon']) ?></div>
                <div class="tool-card-body">
                    <div class="tool-card-title"><?= h($t['title']) ?></div>
                    <div class="tool-card-desc"><?= h($t['desc']) ?></div>
                    <?php if (!empty($t['manual'])): ?>
                        <span class="tool-card-manual">📖 Manual available</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ---- Single tool: render header + fullscreen iframe ----
$t = $TOOLS[$tool];
$page_title = $t['title'];

// Optional view selector — only meaningful for the calculator at the
// moment, but generic so other tools can adopt it later. Whitelist to
// alphanumerics+dash so a malicious value can't break the iframe src.
$view = (string)input('view', '');
if (!preg_match('/^[a-z0-9_-]{1,40}$/i', $view)) $view = '';

// Resolve the sidebar module code to highlight. For tools with nested
// sub-tools (calc + weight) we map (tool, view) → grandchild code so
// the sidebar's active highlight lands on the right third-level entry.
// For tools without nesting (bubble, cad) we highlight the leaf child.
$SIDEBAR_MAP = [
    'bubble'   => ['_default' => 'tools_bubble'],
    'cad'      => ['_default' => 'tools_cad'],
    'weight'   => [
        '_default'  => 'tools_weight',
        'calculator'=> 'weight_calc',
        'hardness'  => 'weight_hardness',
        'shore'     => 'weight_shore',
    ],
    'calc'     => [
        '_default' => 'tools_calc',
        'units'    => 'calc_units',
        'stackup'  => 'calc_stackup',
        'cpk'      => 'calc_cpk',
        'fit'      => 'calc_fit',
        'sfm'      => 'calc_sfm',
        'geometry' => 'calc_geometry',
        'sci'      => 'calc_sci',
        'aql'      => 'calc_aql',
        'iso2859'  => 'calc_iso2859',
    ],
];
$page_module = $SIDEBAR_MAP[$tool][$view] ?? ($SIDEBAR_MAP[$tool]['_default'] ?? 'tools');

$iframeSrc = '/' . $t['src'];
$standaloneSrc = $iframeSrc;
if ($view !== '') {
    $iframeSrc     .= '?view=' . urlencode($view);
    $standaloneSrc .= '?view=' . urlencode($view);
}
// embed=1 tells the tool to hide its own chrome (inner sidebar) since
// the MagDyn sidebar already provides navigation. Standalone mode (the
// "Open standalone" link, opened in a new tab) gets the tool's full
// native UI with its own sidebar intact.
$iframeSrc .= ($view !== '' ? '&' : '?') . 'embed=1';

require __DIR__ . '/includes/header.php';
?>
<div class="page-head tool-frame-head">
    <div>
        <h1><?= h($t['icon']) ?> <?= h($t['title']) ?></h1>
        <p class="muted small"><?= h($t['desc']) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost btn-sm" href="<?= h(url('/tools.php')) ?>">← All tools</a>
        <a class="btn btn-ghost btn-sm" href="<?= h(url($standaloneSrc)) ?>" target="_blank" rel="noopener"
           title="Open the tool in its own browser tab (full screen, no MagDyn header)">
            ⤢ Open standalone
        </a>
        <?php if (!empty($t['manual'])):
            // Manuals are now training courses. Each tool maps to a
            // course slug; we look up the course id at render time
            // so admins can rename the courses without breaking the
            // link. If the migration hasn't run on this install,
            // the lookup returns null and we hide the button.
            $MANUAL_SLUG_MAP = [
                'bubble' => 'manual-bubble',
                'cad'    => 'manual-cad',
                'weight' => 'manual-weight',
                'calc'   => 'manual-calc',
            ];
            $manualSlug = $MANUAL_SLUG_MAP[$tool] ?? null;
            $manualCourseId = null;
            if ($manualSlug) {
                // Defensive: training_courses.slug may not exist on
                // pre-migration installs. Wrap in try/catch.
                try {
                    $manualCourseId = (int)db_val(
                        'SELECT id FROM training_courses WHERE slug = ? AND is_active = 1 LIMIT 1',
                        [$manualSlug], 0
                    );
                } catch (\Throwable $e) {
                    $manualCourseId = 0;
                }
            }
            if ($manualCourseId):
        ?>
            <a class="btn btn-ghost btn-sm"
               href="<?= h(url('/training.php?action=view&id=' . $manualCourseId)) ?>"
               title="Open the manual (training course) in the same tab">
                📖 Manual
            </a>
        <?php endif; endif; ?>
    </div>
</div>

<iframe class="tool-frame"
        src="<?= h(url($iframeSrc)) ?>"
        title="<?= h($t['title']) ?>"
        allow="clipboard-read; clipboard-write; fullscreen"
        loading="eager"></iframe>

<?php
require __DIR__ . '/includes/footer.php';
