<?php
/**
 * Generic helpers.
 *
 * Created: 20260515_060024_IST
 */

/** HTML-safe escape */
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a URL within this app */
function url($path = '')
{
    $base = rtrim($GLOBALS['APP']['base_url'], '/');
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    return $base . $path;
}

/**
 * Build a URL to a static asset with a cache-busting query string.
 *
 * Appends ?v=<file-mtime> so browsers and the service worker always
 * fetch a fresh copy after a deploy. mtime changes whenever the file
 * is overwritten, so users never see a stale JS or CSS again.
 *
 * Falls back gracefully if the file isn't on disk yet (e.g. during a
 * partial deploy) by using the wallclock day as the version.
 */
function asset_url($path)
{
    $rel = ltrim((string)$path, '/');
    $fs  = $GLOBALS['ROOT'] . '/' . $rel;
    $v   = @filemtime($fs);
    if (!$v) $v = (int)date('Ymd');
    return url('/' . $rel) . '?v=' . $v;
}

/**
 * Build a URL to a module page.
 *
 * MagDyn uses flat per-page PHP files at the app root for built-out modules,
 * and a generic dispatcher (/module.php?m=<code>) for modules that don't yet
 * have their own page. route('users', 'edit', ['id'=>42]) becomes
 * /magdyn/users.php?action=edit&id=42; route('inv_stock_levels') becomes
 * /magdyn/module.php?m=inv_stock_levels.
 */
function route($module, $action = 'index', array $extra = [])
{
    $qs = [];
    if ($action && $action !== 'index') $qs['action'] = $action;
    $qs = array_merge($qs, $extra);

    // 'dashboard' is the home page and lives at /index.php
    if ($module === 'dashboard') {
        $file = '/index.php';
    } elseif ($module === 'documents_internal') {
        $file = '/documents.php';
        $qs = array_merge(['kind' => 'internal'], $qs);
    } elseif ($module === 'documents_external') {
        $file = '/documents.php';
        $qs = array_merge(['kind' => 'external'], $qs);
    } elseif ($module === 'documents_transmittals') {
        $file = '/transmittals.php';
    } elseif ($module === 'documents_dashboard') {
        $file = '/index.php';
        $qs = array_merge(['tab' => 'documents'], $qs);
    } elseif (in_array($module, _routed_modules(), true)) {
        $file = '/' . $module . '.php';
    } else {
        // Unknown module -> generic dispatcher
        $file = '/module.php';
        $qs   = array_merge(['m' => $module], $qs);
    }

    $url = url($file);
    if ($qs) $url .= '?' . http_build_query($qs);
    return $url;
}

/**
 * Modules that have their own .php file at the app root. Anything not in
 * this list routes through the generic /module.php dispatcher (which
 * renders a "Coming soon" placeholder by default).
 *
 * Add to this list when you ship a real page for a module.
 */
function _routed_modules()
{
    return [
        'users','roles','modules','mobile','notifications','audit',
        'locations','vendors','asset_lookups','categories',
        // 'inventory_locations' removed in migration_20260517_123000_IST:
        // inv_locations table merged into locations; the file is now a
        // redirect stub. It's reached only via direct URL, never via
        // route() / the sidebar.
        'inspection_uoms',      // inspection_uoms.php — admin CRUD
        'asset',         // asset.php (Asset submodules use virtual_url)
        'training',
        'running_notes', // running_notes.php — list/new/view/modal actions
        'inspection',    // inspection.php — list/new/view/execute/templates
        'job_card',      // job_card.php — approval-to-ship workflow (SO → QC → Prod → ATS → Billing)
        'import',        // import.php — data import tools (XML inventory items, future: BOMs, etc.)
        'invoice',       // invoice.php — list/new/edit/view + attachments
        'ecn',           // ecn.php — engineering change notices, list/new/edit/view + workflow actions
        'ats',           // ats.php — Authorisation To Ship (job_card → ATS → billing app push); list/view/edit/finalize/cancel/reopen
        'vendor_empanelment', // vendor_empanelment.php — onboard new vendors; collect docs, NDAs; approve to create empaneled vendor
        'nda_templates', // nda_templates.php — master NDA template library used by vendor_empanelment
        'purchase_orders', // purchase_orders.php — auto-generated POs per Ship/Receipt; list/view/print (Phase D adds amend + email)
        'settings',      // settings.php — app-wide config (SMTP today, more tabs later)
        'cmm',           // cmm.php — ZEISS Calypso CMM analyzer (PASS/MARGINAL/REJECT + Plotly charts)
        'processes',     // processes.php — SOP / work instructions / decision trees with Mermaid flowcharts
        'tools',         // tools.php — iframe wrapper for engineering tools
        'manuals',       // manuals.php — iframe wrapper for tool manuals
        'code_sequences', // code_sequences.php — admin page for auto-code prefixes
    ];
}

/** Redirect helper */
function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

/** Pull a GET/POST value safely */
function input($key, $default = '')
{
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    return $default;
}

/** IST timestamp helper for filenames */
function ist_ts()
{
    $dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return $dt->format('Ymd_His') . '_IST';
}

/** Format a SQL datetime for display */
function dt_display($sqlDt)
{
    if (!$sqlDt) return '—';
    $t = strtotime($sqlDt);
    return $t ? date('d M Y, H:i', $t) : h($sqlDt);
}

/**
 * Render a label string with the keyboard-shortcut character underlined.
 *
 * Accepts a 1- or 2-character chord:
 *   shortcut_label('Save', 'S')         → <u>S</u>ave           (single)
 *   shortcut_label('Audit', 'Au')       → <u>Au</u>dit           (two-letter)
 *
 * Two-letter chords underline both characters together when they appear
 * adjacent in the label (the common case). When they don't appear
 * adjacent, the first letter is underlined and the second char is shown
 * as a small superscript hint after the label.
 *
 * The DOM also receives a data-shortcut attribute used by
 * assets/js/shortcuts.js: pressing Alt + the chord's first char enters
 * "chord mode" if multiple items share that first char; the next
 * keypress then disambiguates.
 */
function shortcut_label($label, $chord)
{
    $chord = (string)$chord;
    if ($chord === '') return h($label);
    $len = min(strlen($chord), 2);

    if ($len === 1) {
        $letter = $chord[0];
        $pos = stripos($label, $letter);
        if ($pos === false) {
            return h($label) . ' <kbd>' . h(strtoupper($letter)) . '</kbd>';
        }
        return h(substr($label, 0, $pos))
             . '<u>' . h(substr($label, $pos, 1)) . '</u>'
             . h(substr($label, $pos + 1));
    }

    // Two-letter chord: try to find the two letters adjacent in the label
    // (case-insensitive). E.g. chord "Au" in "Audit" -> highlight both.
    $needle = strtolower(substr($chord, 0, 2));
    $hay    = strtolower($label);
    $pos    = strpos($hay, $needle);
    if ($pos !== false) {
        return h(substr($label, 0, $pos))
             . '<u>' . h(substr($label, $pos, 2)) . '</u>'
             . h(substr($label, $pos + 2));
    }
    // Adjacent pair not found — fall back to underlining the first char and
    // appending the second as a hint.
    $a = $chord[0];
    $pos1 = stripos($label, $a);
    if ($pos1 === false) {
        return h($label) . ' <kbd>' . h(strtoupper($chord)) . '</kbd>';
    }
    return h(substr($label, 0, $pos1))
         . '<u>' . h(substr($label, $pos1, 1)) . '</u>'
         . h(substr($label, $pos1 + 1))
         . '<span class="shortcut-hint">'
         . h(strtoupper(substr($chord, 1, 1))) . '</span>';
}

/**
 * Given an ordered list of human names, produce a unique short chord for
 * each (1 character preferred, 2 characters when needed for collisions).
 *
 * Strategy:
 *   1. Try the first letter (capitalised) for each name.
 *   2. If multiple names share that letter, walk each colliding name to
 *      find a second distinguishing character — preferring the next
 *      consonant inside the same first word, falling back to subsequent
 *      letters.
 *   3. Items assigned in input order; earlier items keep their short form
 *      if possible.
 *
 * Returns ['Name 1' => 'A', 'Name 2' => 'Au', ...] keyed by the input names.
 */
function assign_shortcuts(array $names)
{
    $out  = [];
    $used = [];   // chord (uppercase) => name
    foreach ($names as $name) {
        $clean = trim((string)$name);
        if ($clean === '') { $out[$name] = ''; continue; }

        // Candidate 1: first alpha character
        $first = '';
        for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
            $c = strtoupper($clean[$i]);
            if ($c >= 'A' && $c <= 'Z') { $first = $c; break; }
        }
        if ($first === '') { $out[$name] = ''; continue; }

        // Try the single letter first
        if (!isset($used[$first])) {
            $used[$first] = $name;
            $out[$name]   = $first;
            continue;
        }

        // Collision: find a second char that, paired with $first, is unique.
        // Walk the name from position-after-first looking for an alpha char.
        $second = '';
        $startedAt = stripos($clean, $first);
        for ($i = $startedAt + 1, $n = strlen($clean); $i < $n; $i++) {
            $c = strtolower($clean[$i]);
            if ($c < 'a' || $c > 'z') continue;
            $candidate = $first . strtoupper($c);
            if (!isset($used[$candidate])) {
                $second = strtoupper($c);
                $used[$candidate] = $name;
                $out[$name]       = $first . $second;
                break;
            }
        }
        if ($second === '') {
            // Last-resort: append a digit
            for ($d = 2; $d < 10; $d++) {
                $candidate = $first . $d;
                if (!isset($used[$candidate])) {
                    $used[$candidate] = $name;
                    $out[$name]       = $candidate;
                    break;
                }
            }
        }
    }
    return $out;
}

/**
 * Resolve a module code to its display glyph.
 *
 * We intentionally store glyphs in PHP rather than the database because
 * 4-byte emoji require an explicit utf8mb4 *column* (not just connection
 * charset). MySQL servers with Latin-1 column defaults silently corrupt
 * these bytes on insert, producing the classic mojibake (â\x8a\x9e etc.).
 * Keeping the canonical map here removes that failure mode entirely.
 *
 * Pass the module code; falls back to the second argument if no mapping
 * exists (useful when a custom module is added via the Modules admin UI).
 */
function module_icon($code, $fallback = '◆')
{
    static $map = [
        'dashboard'     => "\xE2\x8A\x9E",                  // ⊞ U+229E
        'users'         => "\xF0\x9F\x91\xA5",              // 👥 U+1F465
        'roles'         => "\xF0\x9F\x9B\xA1\xEF\xB8\x8F",  // 🛡️ U+1F6E1 VS16
        'modules'       => "\xE2\x9A\x99\xEF\xB8\x8F",      // ⚙️ U+2699 VS16
        'mobile'        => "\xF0\x9F\x93\xB1",              // 📱 U+1F4F1
        'training'      => "\xF0\x9F\x93\x96",              // 📖 U+1F4D6
        'notifications' => "\xF0\x9F\x94\x94",              // 🔔 U+1F514
        'audit'         => "\xF0\x9F\x93\x8B",              // 📋 U+1F4CB
        'asset'         => "\xF0\x9F\x8F\xAD",              // 🏭 U+1F3ED
        'inventory'     => "\xF0\x9F\x93\xA6",              // 📦 U+1F4E6
        'qc'            => "\xE2\x9C\x93",                  // ✓  U+2713 (legacy)
        'inspection'    => "\xE2\x9C\x93",                  // ✓  U+2713
        'running_notes' => "\xF0\x9F\x93\x9D",              // 📝 U+1F4DD (was 🗒️ — too similar to 🧾 invoice at sidebar size)
        'job_card'      => "\xF0\x9F\x8E\xAB",              // 🎫 U+1F3AB (ticket — approval-to-ship workflow)
        'invoice'       => "\xF0\x9F\xA7\xBE",              // 🧾 U+1F9FE
        'ecn'           => "\xF0\x9F\x94\x84",              // 🔄 U+1F504 (clockwise arrows — change cycle)
        'cmm'           => "\xF0\x9F\x93\x90",              // 📐 U+1F4D0 (triangular ruler — measurement)
        'processes'     => "\xF0\x9F\x94\x80",              // 🔀 U+1F500 (twisted arrows — flowchart)
        'reports'       => "\xF0\x9F\x93\x8A",              // 📊 U+1F4CA
        'locations'     => "\xF0\x9F\x93\x8D",              // 📍 U+1F4CD
        'vendors'       => "\xF0\x9F\x8F\xAA",              // 🏪 U+1F3EA
        'admin'         => "\xF0\x9F\x94\xA7",              // 🔧 U+1F527
        'import'        => "\xF0\x9F\x93\xA5",              // 📥 U+1F4E5 (inbox tray — import)

        // Document Management module + children
        'dms'                    => "\xF0\x9F\x97\x83\xEF\xB8\x8F",  // 🗃️ U+1F5C3 VS16 (card file box — document filing)
        'documents_internal'     => "\xF0\x9F\x93\x84",              // 📄 U+1F4C4 (page facing up — internal authored)
        'documents_external'     => "\xF0\x9F\x93\xA8",              // 📨 U+1F4E8 (incoming envelope — received from outside)
        'documents_transmittals' => "\xF0\x9F\x93\xA4",              // 📤 U+1F4E4 (outbox tray — outgoing send-outs)
        'documents_dashboard'    => "\xF0\x9F\x93\x8A",              // 📊 U+1F4CA (bar chart) — usually hidden via is_active=0

        // Invoice submodules
        'invoice_view'  => "\xF0\x9F\x93\x8B",                       // 📋 U+1F4CB (clipboard — browse list)
        'invoice_new'   => "\xE2\x9E\x95",                           // ➕ U+2795 (heavy plus — create new)
        'asset_models'  => "\xF0\x9F\x93\x90",              // 📐 U+1F4D0
        'asset_lookups' => "\xF0\x9F\x94\xA1",              // 🔡 U+1F521
        'categories'    => "\xF0\x9F\x97\x82\xEF\xB8\x8F",  // 🗂️ U+1F5C2 VS16
        'inventory_locations' => "\xF0\x9F\x93\x8D",        // 📍 U+1F4CD
        'inspection_uoms'     => "\xF0\x9F\x93\x8F",        // 📏 U+1F4CF

        // Asset module — sidebar entries (current + legacy)
        'asset_view_assets'  => "\xF0\x9F\x93\x8B",         // 📋 U+1F4CB
        'asset_view_models'  => "\xF0\x9F\x93\x90",         // 📐 U+1F4D0
        // Legacy codes retained so existing localStorage / bookmarks render
        'asset_create_model' => "\xF0\x9F\x93\x90",         // 📐 U+1F4D0
        'asset_create'       => "\xE2\x9E\x95",             // ➕ U+2795
        'asset_transactions' => "\xF0\x9F\x94\x84",         // 🔄 U+1F504
        'asset_calibration'  => "\xF0\x9F\x93\x85",         // 📅 U+1F4C5
        'asset_view'         => "\xF0\x9F\x93\x8B",         // 📋 U+1F4CB

        // group-level glyph (used by header.php when a module_group renders)
        'group_admin'         => "\xF0\x9F\x94\xA7",              // 🔧 U+1F527
        'group_asset'         => "\xF0\x9F\x8F\xAD",              // 🏭 U+1F3ED
        'group_inventory'     => "\xF0\x9F\x93\xA6",              // 📦 U+1F4E6
        'group_inspection'    => "\xE2\x9C\x93",                  // ✓  U+2713
        'group_running_notes' => "\xF0\x9F\x93\x9D",              // 📝 U+1F4DD (matches 'running_notes')
        'group_invoice'       => "\xF0\x9F\xA7\xBE",              // 🧾 U+1F9FE
        'group_reports'       => "\xF0\x9F\x93\x8A",              // 📊 U+1F4CA
        'group_tools'         => "\xF0\x9F\xA7\xB0",              // 🧰 U+1F9F0
        'group_dms'           => "\xF0\x9F\x97\x83\xEF\xB8\x8F",  // 🗃️ U+1F5C3 VS16 (card file box — Document Management section)

        // Tools module + submodules
        'tools'         => "\xF0\x9F\xA7\xB0",                    // 🧰 U+1F9F0
        'tools_bubble'  => "\xE2\x97\xAF",                        // ◯  U+25EF (large circle)
        'tools_cad'     => "\xE2\x8C\xA7",                        // ⌧  U+2327
        'tools_weight'  => "\xE2\x9A\x96\xEF\xB8\x8F",            // ⚖️ U+2696 VS16
        'tools_calc'    => "\xF0\x9F\xA7\xAE",                    // 🧮 U+1F9EE

        // Engineering Calculator third-level sub-tools (sidebar nesting)
        'calc_units'     => "\xE2\x87\x84",                       // ⇄  U+21C4
        'calc_stackup'   => "\xCE\xA3",                           // Σ  U+03A3
        'calc_cpk'       => "\xCF\x83",                           // σ  U+03C3
        'calc_fit'       => "\xE2\x8A\x95",                       // ⊕  U+2295
        'calc_sfm'       => "\xE2\x9A\x99\xEF\xB8\x8F",           // ⚙️
        'calc_geometry'  => "\xE2\x96\xA3",                       // ▣  U+25A3
        'calc_sci'       => "\xE2\x88\xAB",                       // ∫  U+222B
        'calc_aql'       => "\xE2\x97\xB1",                       // ◱  U+25F1
        'calc_iso2859'   => "\xE2\x97\xB1",                       // ◱  U+25F1

        // Weight Calculator third-level sub-tools
        'weight_calc'      => "\xE2\x9A\x96\xEF\xB8\x8F",         // ⚖️
        'weight_hardness'  => "\xF0\x9F\x94\xA9",                 // 🔩 U+1F529
        'weight_shore'     => "\xE2\x8A\x95",                     // ⊕  U+2295

        // Inventory submodules
        'inv_stock_levels' => "\xF0\x9F\x93\x8A",                 // 📊
        'inv_movements'    => "\xF0\x9F\x94\x84",                 // 🔄
        'inv_reorder'      => "\xE2\x9A\xA0\xEF\xB8\x8F",        // ⚠️
        'inv_stocktake'    => "\xF0\x9F\x93\x8B",                 // 📋
        'inv_reports'      => "\xF0\x9F\x93\x88",                 // 📈

        // Inspection submodules
        'insp_new'         => "\xE2\x9E\x95",                     // ➕
        'insp_pending'     => "\xE2\x8F\xB3",                     // ⏳ (deactivated; kept for legacy data)
        'insp_completed'   => "\xF0\x9F\x93\x8B",                 // 📋 (unified Inspection list)
        'insp_reports'     => "\xF0\x9F\x93\x88",                 // 📈

        // Running Notes
        'notes_log'        => "\xF0\x9F\x93\x9D",                 // 📝 U+1F4DD

        // Inventory submodules
        'inventory_view_items' => "\xF0\x9F\x93\x8B",         // 📋 U+1F4CB
        'inventory_view_boms'  => "\xF0\x9F\x8C\xB3",         // 🌳 U+1F333
        'inventory_lookups'    => "\xF0\x9F\x94\xA7",         // 🔧 U+1F527
        'inventory_process'    => "\xE2\x9A\x99\xEF\xB8\x8F", // ⚙️ U+2699 VS16 (production / process)
        'inventory_shiprcpt'   => "\xF0\x9F\x9A\x9A",         // 🚚 U+1F69A (delivery truck)
        'purchase_orders'      => "\xF0\x9F\xA7\xBE",         // 🧾 U+1F9FE (receipt — PO)
        'vendor_empanelment'   => "\xF0\x9F\xA4\x9D",         // 🤝 U+1F91D (handshake — empanelment)
        'nda_templates'        => "\xF0\x9F\x93\x9C",         // 📜 U+1F4DC (scroll — NDA templates)
        'settings'             => "\xE2\x9A\x99",             // ⚙ U+2699 (gear)
        'inventory_shipments_list' => "\xF0\x9F\x93\x8B",     // 📋 U+1F4CB (clipboard)
        'inventory_txn_history'=> "\xF0\x9F\x93\x9C",         // 📜 U+1F4DC (scroll / log)
        'insp_templates'       => "\xF0\x9F\x93\x91",         // 📑 U+1F4D1 (bookmark tabs — template)
        'code_sequences'       => "\xF0\x9F\x94\xA2",         // 🔢 U+1F522 (numeric input symbol)

        // Invoice submodules
        'inv_create'       => "\xE2\x9E\x95",                     // ➕
        'inv_send'         => "\xF0\x9F\x93\xA4",                 // 📤
        'inv_received'     => "\xF0\x9F\x93\xA5",                 // 📥
        'inv_aging'        => "\xE2\x8F\xB0",                     // ⏰
        'inv_gst_report'   => "\xF0\x9F\xA7\xBE",                 // 🧾
        'invoice_coverage_items' => "\xF0\x9F\x93\x8A",           // 📊 U+1F4CA (bar chart — item coverage)
        'invoice_coverage_txns'  => "\xF0\x9F\x93\x88",           // 📈 U+1F4C8 (trending up — txn coverage)

        // Manuals (umbrella + children)
        'manuals'         => "\xF0\x9F\x93\x96",                  // 📖 U+1F4D6 (open book — manuals umbrella)
        'manuals_bubble'  => "\xE2\x97\xAF",                      // ◯ U+25EF (matches Bubble tool's icon)
        'manuals_cad'     => "\xE2\x8C\xA7",                      // ⌧ U+2327 (matches CAD viewer's icon)
        'manuals_weight'  => "\xE2\x9A\x96",                      // ⚖ U+2696 (matches Weight calc's icon)
        'manuals_calc'    => "\xE2\x88\x91",                      // ∑ U+2211 (matches Engineering Calculator's icon)

        // Reports submodules
        'rpt_asset'        => "\xF0\x9F\x8F\xAD",                 // 🏭
        'rpt_inventory'    => "\xF0\x9F\x93\xA6",                 // 📦
        'rpt_inspection'   => "\xE2\x9C\x93",                     // ✓
        'rpt_invoice'      => "\xF0\x9F\xA7\xBE",                 // 🧾
        'rpt_custom'       => "\xE2\x9C\xA8",                     // ✨
    ];
    if (isset($map[$code])) return $map[$code];
    return $fallback !== '' ? $fallback : "\xE2\x97\x86";   // ◆
}

/** Flash messaging */
function flash_set($type, $msg)
{
    if (!isset($_SESSION['_flash'])) $_SESSION['_flash'] = [];
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_pull()
{
    $f = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : [];
    unset($_SESSION['_flash']);
    return $f;
}

/**
 * Render a standard Save + Cancel button pair for create/edit forms.
 *
 * - $cancelUrl: where the Cancel link goes. The browser's "data-no-spa"
 *   bit isn't needed; we want SPA navigation there too.
 * - $saveLabel: button text. Defaults to "Save".
 * - $saveTabindex: tabindex for the Save button so it stays in flow.
 * - $extraHtml: anything to render between Save and Cancel (e.g. a Delete
 *   sub-form on edit pages).
 *
 * Emits its own .form-actions wrapper, so callers should drop the
 * existing div and use only the output of this helper. Keeps the markup
 * consistent across every form in the app.
 */
function form_actions($cancelUrl, $saveLabel = 'Save', $saveTabindex = 99, $extraHtml = '')
{
    ob_start(); ?>
    <div class="form-actions span-2">
        <button type="submit" class="btn btn-primary" tabindex="<?= (int)$saveTabindex ?>"
                data-shortcut="S" accesskey="s"><?= shortcut_label(h($saveLabel), 'S') ?></button>
        <a class="btn btn-ghost" href="<?= h($cancelUrl) ?>"
           data-shortcut="X" accesskey="x" tabindex="<?= (int)$saveTabindex + 1 ?>">
            <?= shortcut_label('Cancel', 'X') ?>
        </a>
        <?= $extraHtml ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render the standard toolbar for an add / edit page.
 *
 * Mirrors the data-table toolbar pattern from data_table_render() so
 * add pages and list pages share a visual schema:
 *   left   — breadcrumb-style back link
 *   center — title (and an optional subtitle in muted small)
 *   right  — primary/cancel/delete actions (raw HTML)
 *
 * Use the matching .form-page wrapper around the page content so the
 * CSS (.main:has > .form-page) zeroes the gutters for edge-to-edge
 * forms.
 *
 * Usage in a page:
 *   require __DIR__ . '/includes/header.php';
 *   ?>
 *   <div class="form-page">
 *     <?= form_toolbar([
 *         'title' => 'Edit user',
 *         'back_href' => url('/users.php'),
 *         'back_label' => 'Users',
 *         'actions_html' => '<button type="submit" form="main-form" class="btn btn-primary btn-sm">Save</button>',
 *     ]) ?>
 *     <form id="main-form" class="form-page-body" method="post" action="...">
 *       ... fields ...
 *     </form>
 *   </div>
 *   <?php require __DIR__ . '/includes/footer.php';
 *
 * Keys:
 *   title         — string, page heading shown centered
 *   subtitle      — optional, rendered in muted small after the title
 *   back_href     — optional URL for the back-arrow link
 *   back_label    — optional label for the back link (defaults to "Back")
 *   actions_html  — raw HTML for the right-side buttons
 */
function form_toolbar(array $cfg = [])
{
    $title       = $cfg['title']        ?? '';
    $subtitle    = $cfg['subtitle']     ?? '';
    $backHref    = $cfg['back_href']    ?? '';
    $backLabel   = $cfg['back_label']   ?? 'Back';
    $actionsHtml = $cfg['actions_html'] ?? '';
    ob_start();
    ?>
    <div class="form-toolbar">
        <div class="form-toolbar-left">
            <?php if ($backHref !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= h($backHref) ?>"
                   data-shortcut="B" accesskey="b">← <?= h($backLabel) ?></a>
            <?php endif; ?>
        </div>
        <h2 class="form-toolbar-title">
            <?= h($title) ?>
            <?php if ($subtitle !== ''): ?>
                <span class="muted small form-toolbar-subtitle"><?= h($subtitle) ?></span>
            <?php endif; ?>
        </h2>
        <div class="form-toolbar-right">
            <?= $actionsHtml ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Wrap a data-table row's action HTML inside a gear-icon dropdown menu.
 * Replaces the inline-icons pattern that filled the Actions cell with a
 * compact ⚙ button that, on click, reveals the original action HTML in
 * a dropdown popup. CSS restyles the inner buttons/forms to appear as
 * left-aligned menu items.
 *
 * Pass-through behaviour: if $actions is empty or only contains the
 * '—' placeholder (no actions to show), this returns the input unchanged
 * so the cell still renders the dash.
 *
 * Usage in a list page row renderer:
 *   '_actions' => dt_actions_wrap($actions),
 */
function dt_actions_wrap($actions)
{
    $s = trim((string)$actions);
    if ($s === '' || strpos($s, 'muted small">—') !== false && strlen($s) < 60) {
        // Empty cell — keep the dash placeholder
        return $actions;
    }
    return '<div class="dt-actions">'
         . '<button type="button" class="btn btn-icon dt-actions-trigger" aria-haspopup="true" aria-expanded="false" title="Actions">⚙</button>'
         . '<div class="dt-actions-dropdown" hidden>' . $actions . '</div>'
         . '</div>';
}

/**
 * Generate a unique code for a row about to be cloned.
 *
 * Tries "<source>-COPY" first; if that's taken, tries "<source>-COPY-2",
 * "-3", ... up to 99. Returns the first available code. The check is
 * against $table.$codeColumn, optionally narrowed by $extraWhere (a
 * pre-bound WHERE fragment, no leading AND).
 *
 * Used by the per-module clone handlers for inv_items, assets, BOMs,
 * roles, locations.
 */
function clone_unique_code($table, $codeColumn, $sourceCode, $extraWhere = '')
{
    // Whitelist the table + column names since they're interpolated
    // directly into SQL. The caller is trusted (always a literal in
    // app code) but the assertion catches future misuse early.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) ||
        !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $codeColumn)) {
        throw new InvalidArgumentException('clone_unique_code: bad table/column name');
    }
    $whereClause = $extraWhere !== '' ? ' AND ' . $extraWhere : '';

    // Truncate the source code so suffix fits within typical 40-64 char
    // code columns. Keeping the suffix portion under 8 chars handles
    // "-COPY-NN" comfortably.
    $base = mb_substr($sourceCode, 0, 50);
    $candidates = [$base . '-COPY'];
    for ($i = 2; $i <= 99; $i++) {
        $candidates[] = $base . '-COPY-' . $i;
    }
    foreach ($candidates as $candidate) {
        $exists = (int)db_val(
            "SELECT COUNT(*) FROM `$table` WHERE `$codeColumn` = ?" . $whereClause,
            [$candidate], 0
        );
        if ($exists === 0) return $candidate;
    }
    // Beyond 99 attempts, fall back to a random suffix. Vanishingly
    // rare to hit but worth handling.
    return $base . '-COPY-' . substr(bin2hex(random_bytes(4)), 0, 6);
}

/**
 * Clone a single row to the same table, overriding specific columns
 * and excluding others. Returns the new row's id.
 *
 * - Reads $table's column list from information_schema (so adding
 *   columns via later migrations doesn't require updating clone code)
 * - Excludes 'id' (PK is auto-generated) and any columns in $exclude
 * - Substitutes any column value in $overrides
 *
 * NOT a generic-purpose helper — assumes:
 *   - PK column is 'id' (auto_increment)
 *   - Source row is keyed by 'id'
 *   - $overrides values are bound as parameters (caller controls them)
 *
 * @param string $table     table name (whitelisted by regex)
 * @param int    $sourceId  id of the row to clone
 * @param array  $overrides assoc array: column => value
 * @param array  $exclude   list of column names to skip (besides id)
 * @return int new row id, 0 on failure
 */
function clone_row($table, $sourceId, array $overrides = [], array $exclude = [])
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
        throw new InvalidArgumentException('clone_row: bad table name');
    }
    $cols = db_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          ORDER BY ORDINAL_POSITION",
        [$table]
    );
    if (empty($cols)) return 0;

    // Build a fast lookup of real column names so we can silently drop
    // override keys for columns that don't exist (lets callers list
    // overrides for columns added in optional migrations).
    $realCols = [];
    foreach ($cols as $c) $realCols[$c['COLUMN_NAME']] = true;

    $skipAlways  = array_merge(['id'], $exclude);
    $selectExprs = [];
    $insertCols  = [];
    $params      = [];
    foreach ($cols as $c) {
        $name = $c['COLUMN_NAME'];
        if (in_array($name, $skipAlways, true)) continue;
        $insertCols[] = '`' . $name . '`';
        if (array_key_exists($name, $overrides)) {
            $selectExprs[] = '?';
            $params[]      = $overrides[$name];
        } else {
            $selectExprs[] = '`' . $name . '`';
        }
    }
    $params[] = $sourceId;  // for WHERE id = ?

    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $insertCols) . ') '
         . 'SELECT '       . implode(', ', $selectExprs)
         . ' FROM `' . $table . '` WHERE id = ?';
    db_exec($sql, $params);
    return (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
}

