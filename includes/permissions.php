<?php
/**
 * Role / permission helpers.
 *
 * Permission codes are always 'module_code.permission_code', e.g.
 *   'users.view', 'training.manage', 'users.impersonate'
 *
 * Operate on the *current* (possibly impersonated) user — admins viewing
 * as someone else see the same denials the target user would see.
 *
 * Created: 20260515_060024_IST
 */

/**
 * Return all permission codes (module.code) the current user holds.
 * Cached per-request.
 *
 * The 'admin' role is a hard override (gets every existing permission)
 * because role_permissions for admin is seeded full in schema.sql, so
 * this works without any special-casing in code.
 */
function user_permissions()
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $u = current_user();
    if (!$u) return $cache = [];

    $rows = db_all(
        "SELECT DISTINCT CONCAT(m.code, '.', p.code) AS perm
           FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           JOIN permissions p       ON p.id       = rp.permission_id
           JOIN modules m           ON m.id       = p.module_id
          WHERE ur.user_id = ?",
        [(int)$u['id']]
    );
    return $cache = array_column($rows, 'perm');
}

/** Reset cached permissions (call after impersonation start/stop). */
function user_permissions_reset()
{
    // No simple way to reset a 'static' inside another function in PHP 7
    // without funcs supporting it — but since each request re-evaluates,
    // and impersonation always goes through a redirect, this is fine.
    // Provided for clarity / future-proofing.
}

function permission_check($module, $action)
{
    $code = $module . '.' . $action;
    return in_array($code, user_permissions(), true);
}

/** Hard-fail when permission is missing. Renders a friendly 403 page. */
function require_permission($module, $action)
{
    require_login();
    if (!permission_check($module, $action)) {
        http_response_code(403);
        $page_title  = 'Access denied';
        $page_module = '';
        $focus_id    = '';
        require dirname(__DIR__) . '/includes/header.php';
        echo '<div class="page-head"><div><h1>403 — Access denied</h1>'
           . '<p class="muted">You do not have permission for <code>'
           . h($module . '.' . $action) . '</code>.</p></div></div>';
        echo '<div class="card"><div class="card-body">'
           . '<p>If you believe this is in error, ask an administrator to grant your role '
           . 'the appropriate permission under <strong>Roles &amp; Permissions</strong>.</p>'
           . '<a class="btn btn-ghost" href="' . h(url('/index.php')) . '">← Back to dashboard</a>'
           . '</div></div>';
        require dirname(__DIR__) . '/includes/footer.php';
        exit;
    }
}

/** Returns role rows for current user. */
function current_user_roles()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $u = current_user();
    if (!$u) return $cache = [];
    $cache = db_all(
        'SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?',
        [(int)$u['id']]
    );
    return $cache;
}

/**
 * Modules visible to the current user — those for which they hold at least
 * one permission. Includes shortcut metadata used by the sidebar.
 *
 * Returns array of rows keyed by module code:
 *   [ 'users' => ['id'=>2,'code'=>'users','name'=>'Users','icon'=>'👥',...], ... ]
 */
function visible_modules()
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $perms = user_permissions();
    if (!$perms) return $cache = [];

    $moduleCodes = [];
    foreach ($perms as $p) {
        $dot = strpos($p, '.');
        if ($dot !== false) $moduleCodes[substr($p, 0, $dot)] = true;
    }
    if (!$moduleCodes) return $cache = [];

    $in   = implode(',', array_fill(0, count($moduleCodes), '?'));
    $rows = db_all(
        "SELECT * FROM modules WHERE is_active = 1 AND code IN ($in) ORDER BY sort_order, name",
        array_keys($moduleCodes)
    );

    // Shortcut assignment is now done globally by visible_module_tree()
    // so chords are unique across groups, children, and top-level items.
    //
    // Skip note_cat_* modules from the visible list: they exist purely
    // as bookkeeping rows so the existing RBAC machinery can grant
    // view/manage on a per-category basis. They have no virtual_url and
    // no route() mapping, so showing them in the sidebar would 404 on
    // click. They still need to flow through user_permissions() and the
    // roles editor — those callers reach permissions directly without
    // going through visible_modules().
    $out = [];
    foreach ($rows as $m) {
        if (strpos($m['code'], 'note_cat_') === 0) continue;
        $m['shortcut'] = '';
        $out[$m['code']] = $m;
    }
    return $cache = $out;
}

/**
 * All modules + every permission grouped by module. Used by the
 * Roles & Permissions admin UI.
 */
function permissions_matrix()
{
    $modules = db_all('SELECT * FROM modules ORDER BY sort_order, name');
    $perms   = db_all('SELECT * FROM permissions ORDER BY module_id, id');
    $byMod   = [];
    foreach ($perms as $p) {
        $byMod[$p['module_id']][] = $p;
    }
    $out = [];
    foreach ($modules as $m) {
        $m['permissions'] = isset($byMod[$m['id']]) ? $byMod[$m['id']] : [];
        $out[] = $m;
    }
    return $out;
}

/**
 * Visible modules organised for the sidebar.
 *
 * Returns a flat ordered list of entries the header renders top-to-bottom.
 * Each entry is one of:
 *   ['type'=>'module', 'data'=>$moduleRow]
 *   ['type'=>'group',  'data'=>['id','code','name','icon','shortcut','children'=>[$row,...]]]
 *
 * Architecture:
 *   - Every module lives in the `modules` table.
 *   - is_group=1 marks a parent (Asset, Inventory, Inspection, Running Notes,
 *     Invoice, Reports, Admin). Parent rows are not clickable; their only
 *     purpose is to host children.
 *   - is_group=0 with parent_id set is a SUBMODULE. Its `virtual_url` column
 *     (if set) overrides the default route() to /<code>.php — used by Asset
 *     submodules to point at asset.php?action=...
 *   - is_group=0 with no parent_id is a top-level module (Dashboard, Training).
 *
 * Visibility:
 *   A submodule appears only if the user has at least one permission on it.
 *   A parent appears only if at least one of its visible children appears.
 *   This makes the sidebar self-pruning across roles.
 *
 * Fallback:
 *   When the `is_group`/`parent_id`/`virtual_url` columns don't yet exist
 *   (migration not applied), the function falls back to a hardcoded layout
 *   so the sidebar still groups Users/Roles/etc. under Admin and Asset's
 *   five virtual children appear correctly.
 */
function visible_module_tree()
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $visible = visible_modules();   // by code => row

    // ---- Capability detection (cached for the request) ----
    static $hasGroupCols  = null;
    static $hasVirtualUrl = null;
    if ($hasGroupCols === null) {
        try {
            $hasGroupCols  = !empty(db_one("SHOW COLUMNS FROM modules LIKE 'is_group'"));
            $hasVirtualUrl = !empty(db_one("SHOW COLUMNS FROM modules LIKE 'virtual_url'"));
        } catch (Exception $e) {
            $hasGroupCols  = false;
            $hasVirtualUrl = false;
        }
    }

    // ---- Load groups straight from the DB ----
    $groupsByCode = [];
    $groupsById   = [];
    if ($hasGroupCols) {
        $rows = db_all('SELECT * FROM modules WHERE is_group = 1 AND is_active = 1 ORDER BY sort_order, name');
        foreach ($rows as $g) {
            $g['shortcut'] = '';
            $g['children'] = [];
            $groupsByCode[$g['code']] = $g;
            $groupsById[(int)$g['id']] = &$groupsByCode[$g['code']];
        }
    }

    // ---- Synthesise the Admin group if the DB doesn't have one yet ----
    if (!isset($groupsByCode['admin'])) {
        $groupsByCode['admin'] = [
            'id'         => 0,
            'code'       => 'admin',
            'name'       => 'Admin',
            'icon'       => null,
            'sort_order' => 200,
            'shortcut'   => '',
            'children'   => [],
        ];
    }

    // Hardcoded admin children list — only consulted when the DB hasn't been
    // structured yet (legacy installs). Once migration 080000 runs and sets
    // parent_id, Priority 1 below handles placement.
    // NOTE: 'vendors' was removed here when migration 190000 promoted Vendors
    // to a top-level module. The Priority 1 path won't place it (parent_id
    // is NULL), and we no longer want the legacy fallback to drag it back
    // into Admin.
    $hardcodedAdminChildren = ['users','roles','modules','mobile','notifications',
                               'audit','locations','asset_lookups',
                               'categories'];

    // Hardcoded Asset virtual children — same story. Once migration 110000
    // runs and inserts these as real DB rows, Priority 1 supersedes.
    $hardcodedAssetVirtualChildren = [
        ['asset_view_assets', 'View Assets', '/asset.php?action=list',   110],
        ['asset_view_models', 'View Models', '/asset.php?action=models', 120],
    ];
    // Only inject Asset's virtual children when the DB rows for them are
    // absent (i.e. migration 090000 hasn't run). Once those rows exist they
    // come through Priority 1 below.
    $dbHasAssetSubmodules = false;
    if ($hasGroupCols && isset($groupsByCode['asset'])) {
        try {
            $dbHasAssetSubmodules = (int)db_val(
                "SELECT COUNT(*) FROM modules WHERE parent_id = ? AND is_active = 1",
                [(int)$groupsByCode['asset']['id']], 0
            ) > 0;
        } catch (Exception $e) {}
    }
    if (!$dbHasAssetSubmodules && isset($groupsByCode['asset'])) {
        foreach ($hardcodedAssetVirtualChildren as $vc) {
            list($code, $label, $action, $sort) = $vc;
            // Gate by parent permission so a user without asset access
            // doesn't see an empty group.
            if (!permission_check('asset', 'view')) continue;
            $groupsByCode['asset']['children'][] = [
                'id'          => 0,
                'code'        => $code,
                'name'        => $label,
                'icon'        => null,
                'sort_order'  => $sort,
                'is_active'   => 1,
                'virtual_url' => $action,
                'shortcut'    => '',
            ];
        }
    }

    // ---- Place every visible (non-group) module ----
    $topLevel = [];
    foreach ($visible as $m) {
        if (!empty($m['is_group'])) continue;

        $placedInGroup = false;
        // Priority 1: DB parent_id. The parent may be a top-level group
        // (e.g. inspection's parent = inspection group) OR a sub-group
        // (e.g. calc_units's parent = tools_calc, which itself parents
        // into the tools group). We resolve in two hops below.
        if ($hasGroupCols && !empty($m['parent_id'])) {
            $pid = (int)$m['parent_id'];
            if (isset($groupsById[$pid])) {
                $groupsById[$pid]['children'][] = $m;
                $placedInGroup = true;
            }
        }
        // Priority 2: hardcoded admin membership (legacy fallback)
        if (!$placedInGroup && in_array($m['code'], $hardcodedAdminChildren, true)) {
            $groupsByCode['admin']['children'][] = $m;
            $placedInGroup = true;
        }
        if (!$placedInGroup) {
            $topLevel[] = $m;
        }
    }

    // ---- Nest sub-groups under their parent group ----
    // A "sub-group" is a row with is_group=1 AND parent_id pointing at
    // another group. Engineering Calculator's tools_calc is the first
    // such case: it lives under the tools group, and itself parents
    // nine grandchildren. We move sub-groups out of $groupsByCode and
    // into their parent's `children` list, so the renderer (which
    // already walks `children`) can render them with a nested toggle.
    //
    // The sub-group keeps its own `children` array intact — that's how
    // the renderer knows to expand it.
    $subgroupCodes = [];   // collected so we don't render them at top level too
    if ($hasGroupCols) {
        foreach ($groupsByCode as $gcode => $g) {
            if (empty($g['parent_id'])) continue;       // top-level group, nothing to do
            $pid = (int)$g['parent_id'];
            if (!isset($groupsById[$pid])) continue;     // orphaned — leave at top level
            // Sort the sub-group's own children first
            usort($g['children'], function ($a, $b) {
                return ((int)$a['sort_order'] - (int)$b['sort_order']) ?: strcmp($a['name'], $b['name']);
            });
            // Mark it as a group for the renderer to recognise, then
            // attach as a child of the parent group.
            $g['_is_subgroup'] = true;
            $groupsById[$pid]['children'][] = $g;
            $subgroupCodes[$gcode] = true;
        }
        // Drop the sub-groups from the top-level $groupsByCode list so
        // they don't ALSO render at the root.
        foreach ($subgroupCodes as $gcode => $_) {
            unset($groupsByCode[$gcode]);
        }
    }

    usort($topLevel, function ($a, $b) {
        return ((int)$a['sort_order'] - (int)$b['sort_order']) ?: strcmp($a['name'], $b['name']);
    });

    // ---- Merge groups + top-level modules in sort_order ----
    $merged = [];
    foreach ($topLevel as $m) {
        $merged[] = ['kind' => 'module', 'sort' => (int)$m['sort_order'], 'data' => $m];
    }
    foreach ($groupsByCode as $g) {
        if (!$g['children']) continue;
        usort($g['children'], function ($a, $b) {
            return ((int)$a['sort_order'] - (int)$b['sort_order']) ?: strcmp($a['name'], $b['name']);
        });
        $merged[] = ['kind' => 'group', 'sort' => (int)$g['sort_order'], 'data' => $g];
    }
    usort($merged, function ($a, $b) {
        return $a['sort'] - $b['sort'];
    });

    // ---- Sidebar shortcut assignment ----
    // Strategy:
    //   1. Top-level modules + group toggles compete for SINGLE-letter
    //      chords first. They're the prominent items and should be
    //      mnemonically easy (D = Dashboard, R = Reports, A = Admin).
    //   2. Within each group, children get a 2-char chord = group's
    //      letter + their own distinguishing letter (e.g. R+A for
    //      Asset Reports under Reports). Children compete against
    //      siblings only, so the second letter is short and obvious.
    //   3. The visible underline on each child shows just its OWN
    //      letter (the parent prefix is implicit since the user is
    //      already in that group). No more cluttered "all-R" labels.
    $assignFirst = function ($name) {
        for ($i = 0, $n = strlen($name); $i < $n; $i++) {
            $c = strtoupper($name[$i]);
            if ($c >= 'A' && $c <= 'Z') return $c;
        }
        return '';
    };

    // Pass 1: claim 1-char chords for top-level items + group toggles
    $primary = [];   // name => '<X>'
    $usedTop = [];   // 'X' => true
    foreach ($merged as $row) {
        $name = $row['data']['name'];
        $first = $assignFirst($name);
        if ($first === '') { $primary[$name] = ''; continue; }
        if (!isset($usedTop[$first])) {
            $usedTop[$first]  = true;
            $primary[$name]   = $first;
            continue;
        }
        // Collision: walk the name for a free single letter
        for ($i = 1, $n = strlen($name); $i < $n; $i++) {
            $c = strtoupper($name[$i]);
            if ($c < 'A' || $c > 'Z') continue;
            if (!isset($usedTop[$c])) {
                $usedTop[$c]    = true;
                $primary[$name] = $c;
                break;
            }
        }
        if (!isset($primary[$name])) $primary[$name] = '';   // gave up
    }

    // Pass 2: each group's children get <group-letter> + own letter,
    // scoped against siblings (NOT against the global pool).
    $childChords = [];   // group-code => [child-name => 'GX']
    foreach ($merged as $row) {
        if ($row['kind'] !== 'group') continue;
        $gName    = $row['data']['name'];
        $gChord   = $primary[$gName] ?? '';
        if ($gChord === '') continue;   // pathological: group had no letter

        $usedChild = [];   // second letter used in this group
        foreach ($row['data']['children'] as $c) {
            $cName  = $c['name'];
            $second = $assignFirst($cName);
            if ($second === '' || isset($usedChild[$second])) {
                // Walk for a free letter
                $second = '';
                for ($i = 1, $n = strlen($cName); $i < $n; $i++) {
                    $cc = strtoupper($cName[$i]);
                    if ($cc < 'A' || $cc > 'Z') continue;
                    if (!isset($usedChild[$cc])) { $second = $cc; break; }
                }
            }
            if ($second !== '') {
                $usedChild[$second] = true;
                $childChords[$row['data']['code']][$cName] = $gChord . $second;
            } else {
                $childChords[$row['data']['code']][$cName] = '';
            }
        }
    }

    // Write the chord back into each item
    foreach ($merged as &$row) {
        $row['data']['shortcut'] = $primary[$row['data']['name']] ?? '';
        if ($row['kind'] === 'group') {
            foreach ($row['data']['children'] as &$c) {
                $c['shortcut'] = $childChords[$row['data']['code']][$c['name']] ?? '';
            }
            unset($c);
        }
    }
    unset($row);

    $entries = [];
    foreach ($merged as $row) {
        $entries[] = ['type' => $row['kind'], 'data' => $row['data']];
    }
    return $cache = $entries;
}
