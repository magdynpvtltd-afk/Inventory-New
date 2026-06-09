<?php
/**
 * MagDyn — DataTable helper
 * Created: 20260515_113000_IST
 *
 * Standard sort + search + paginate flow shared by every list page.
 *
 * USAGE
 * -----
 *   require_once __DIR__ . '/includes/datatable.php';
 *
 *   $cfg = [
 *       'id'         => 'users',                  // unique table id (used by JS)
 *       'base_sql'   => 'SELECT u.id, u.username, u.full_name, u.is_active,
 *                              GROUP_CONCAT(r.name SEPARATOR ", ") AS role_names
 *                          FROM users u
 *                          LEFT JOIN user_roles ur ON ur.user_id = u.id
 *                          LEFT JOIN roles r       ON r.id       = ur.role_id',
 *       'count_sql'  => 'SELECT COUNT(*) FROM users u',   // for total
 *       'group_by'   => 'u.id',                   // optional GROUP BY
 *       'columns'    => [
 *           ['key'=>'username',  'label'=>'Username',  'sortable'=>true, 'searchable'=>true,  'sql_col'=>'u.username'],
 *           ['key'=>'full_name', 'label'=>'Full name', 'sortable'=>true, 'searchable'=>true,  'sql_col'=>'u.full_name'],
 *           ['key'=>'role_names','label'=>'Roles',     'sortable'=>false,'searchable'=>false],
 *           ['key'=>'is_active', 'label'=>'Status',    'sortable'=>true, 'searchable'=>false, 'sql_col'=>'u.is_active'],
 *       ],
 *       'default_sort' => ['username', 'asc'],
 *   ];
 *   $dt = data_table_query($cfg);
 *   // $dt has: rows, total, page, page_size, pages, sort, dir, q, col_filters
 *
 * RENDER
 *   data_table_render($cfg, $dt, function($row) use ($cfg) {
 *       // return the cell array, one per visible column key
 *       return [
 *           'username'   => '<strong>' . h($row['username']) . '</strong>',
 *           'full_name'  => h($row['full_name']),
 *           'role_names' => h($row['role_names']),
 *           'is_active'  => $row['is_active'] ? '<span class="pill pill-active">active</span>' : '<span class="pill pill-neutral">disabled</span>',
 *       ];
 *   });
 *
 * AJAX
 *   Pages don't need to handle AJAX themselves. The shared /datatable.php
 *   dispatcher routes by table id back to a registered config. Pages just
 *   register their config via data_table_register('users', $cfg).
 */

// Phase A — global user-persistent column prefs. Require the helper
// here so user_dt_prefs_apply() is always defined when data_table_*
// functions run. The earlier function_exists() guards in those
// functions stayed silent when this file wasn't loaded, which made
// saves succeed (api/dt_prefs.php loads it directly) but the next
// page render would silently skip applying the saved layout —
// looking to the operator as though the panel did nothing.
require_once __DIR__ . '/user_dt_prefs.php';

/** Allowed page sizes; protects against arbitrary user input. */
function data_table_page_sizes() { return [10, 25, 50, 100]; }
function data_table_default_size() { return 25; }

/**
 * Parse + validate URL params into a normalised dt-state array.
 *
 * Honours: ?dt_sort=<colKey> ?dt_dir=asc|desc ?dt_page=<n>
 *          ?dt_size=<10|25|50|100> ?dt_q=<global search>
 *          ?dt_col[colKey]=<per-column search>
 *
 * Unknown column keys are silently dropped so users can't sort on hidden columns.
 */
function data_table_state(array $cfg)
{
    $allowedSizes = data_table_page_sizes();
    $defaultSize  = data_table_default_size();
    $colKeys      = array_column($cfg['columns'], 'key');

    $sort = (string)input('dt_sort', '');
    $dir  = strtolower((string)input('dt_dir', ''));
    if (!in_array($sort, $colKeys, true)) {
        $sort = isset($cfg['default_sort'][0]) ? $cfg['default_sort'][0] : '';
        $dir  = isset($cfg['default_sort'][1]) ? $cfg['default_sort'][1] : 'asc';
    }
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'asc';

    $page = max(1, (int)input('dt_page', 1));
    $size = (int)input('dt_size', $defaultSize);
    if (!in_array($size, $allowedSizes, true)) $size = $defaultSize;

    $q = trim((string)input('dt_q', ''));

    $cols = (array)input('dt_col', []);
    $colFilters = [];
    foreach ($cols as $k => $v) {
        if (in_array($k, $colKeys, true)) {
            $val = trim((string)$v);
            if ($val !== '') $colFilters[$k] = $val;
        }
    }

    return [
        'sort'        => $sort,
        'dir'         => $dir,
        'page'        => $page,
        'page_size'   => $size,
        'q'           => $q,
        'col_filters' => $colFilters,
    ];
}

/**
 * Execute the data-table query. Returns the state array + rows + total.
 *
 * Builds WHERE clauses from per-column filters and the global search.
 * Sortable columns must declare their SQL expression in `sql_col`.
 */
function data_table_query(array $cfg)
{
    // Apply user prefs so hidden columns are excluded from search,
    // filters, and sort eligibility. If a user hid the sort column,
    // the query falls back to the default sort.
    if (function_exists('user_dt_prefs_apply') && function_exists('current_user_id')) {
        $cfg = user_dt_prefs_apply($cfg, current_user_id());
    }
    $state = data_table_state($cfg);
    $params = [];
    $where  = [];

    // ---- Global search across searchable cols ----
    if ($state['q'] !== '') {
        $orParts = [];
        foreach ($cfg['columns'] as $c) {
            if (!empty($c['searchable']) && !empty($c['sql_col'])) {
                $orParts[] = $c['sql_col'] . ' LIKE ?';
                $params[]  = '%' . $state['q'] . '%';
            }
        }
        if ($orParts) $where[] = '(' . implode(' OR ', $orParts) . ')';
    }

    // ---- Per-column filters ----
    foreach ($state['col_filters'] as $key => $val) {
        $col = null;
        foreach ($cfg['columns'] as $c) {
            if ($c['key'] === $key) { $col = $c; break; }
        }
        if (!$col || empty($col['sql_col'])) continue;
        // Filter type: 'select' uses exact match; everything else uses LIKE.
        $type = isset($col['filter']['type']) ? $col['filter']['type'] : 'text';
        if ($type === 'select') {
            $where[]  = $col['sql_col'] . ' = ?';
            $params[] = $val;
        } else {
            $where[]  = $col['sql_col'] . ' LIKE ?';
            $params[] = '%' . $val . '%';
        }
    }

    // ---- Additional always-on filters from config ----
    if (!empty($cfg['extra_where'])) {
        foreach ($cfg['extra_where'] as $clause) {
            $where[]  = $clause[0];
            $params   = array_merge($params, isset($clause[1]) ? (array)$clause[1] : []);
        }
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $groupSql = !empty($cfg['group_by']) ? ' GROUP BY ' . $cfg['group_by'] : '';

    // ---- ORDER BY (sortable column whitelisted to its sql_col) ----
    $orderSql = '';
    foreach ($cfg['columns'] as $c) {
        if ($c['key'] === $state['sort'] && !empty($c['sortable']) && !empty($c['sql_col'])) {
            $sortExpr = !empty($c['sort_sql']) ? $c['sort_sql'] : $c['sql_col'];
            $orderSql = ' ORDER BY ' . $sortExpr . ' ' . strtoupper($state['dir']);
            break;
        }
    }
    // Fallback: if no sortable column matched (e.g. the default_sort was a
    // composite raw expression that doesn't map to any single column), allow
    // the caller to specify a literal ORDER BY via `default_order_by`. Only
    // used when the user hasn't clicked a sortable header explicitly.
    if ($orderSql === '' && !empty($cfg['default_order_by']) && !input('dt_sort', '')) {
        $orderSql = ' ORDER BY ' . $cfg['default_order_by'];
    }

    // ---- Total (uses count_sql) ----
    // For grouped queries we wrap in a subquery; for un-grouped we just COUNT.
    if ($groupSql) {
        $countSql = 'SELECT COUNT(*) FROM (' . $cfg['base_sql'] . $whereSql . $groupSql . ') AS _t';
    } else {
        // Replace the SELECT clause with COUNT(*)
        if (!empty($cfg['count_sql'])) {
            $countSql = $cfg['count_sql'] . $whereSql;
        } else {
            $countSql = 'SELECT COUNT(*) FROM (' . $cfg['base_sql'] . $whereSql . ') AS _t';
        }
    }
    $total = (int)db_val($countSql, $params, 0);

    // ---- Page slice ----
    $pages   = max(1, (int)ceil($total / $state['page_size']));
    $page    = min($state['page'], $pages);
    $offset  = ($page - 1) * $state['page_size'];

    $limitSql = ' LIMIT ' . (int)$state['page_size'] . ' OFFSET ' . (int)$offset;
    $sql = $cfg['base_sql'] . $whereSql . $groupSql . $orderSql . $limitSql;
    $rows = db_all($sql, $params);

    return array_merge($state, [
        'rows'  => $rows,
        'total' => $total,
        'pages' => $pages,
        'page'  => $page,
    ]);
}

/**
 * Render a list-page data table — header with sort arrows, tbody, a
 * single horizontal toolbar at the top (summary + actions + title +
 * pagination), and a sticky pill-shaped filter bar at the bottom.
 *
 * STANDARD LIST-PAGE PATTERN (applies to every new list page):
 *   $page_title  = '...';
 *   $page_module = '...';
 *   $focus_id    = '';
 *
 *   $actionsHtml = '<a class="btn btn-ghost btn-sm" href="...">...</a>
 *                   <a class="btn btn-primary btn-sm" href="...">+ New</a>';
 *
 *   $dtCfg['title']        = 'Page heading';
 *   $dtCfg['actions_html'] = $actionsHtml;        // optional, raw HTML
 *
 *   require __DIR__ . '/includes/header.php';
 *   data_table_render($dtCfg, $dt, $rowRenderer);
 *   require __DIR__ . '/includes/footer.php';
 *
 * DO NOT wrap in <div class="page-head"> or <div class="card"> — the
 * data table owns its chrome end-to-end. The .main padding is auto-
 * zeroed when .dt-wrap is the first child (via :has() in app.css).
 *
 * Page-specific buttons go in $actionsHtml (raw). The data-table
 * supports: 'title' (string), 'description' (unused in toolbar but
 * kept for backwards compat), and 'actions_html' (raw HTML).
 *
 * $rowRenderer is a callable that takes a raw DB row and returns
 * ['colKey' => html, ...] for the cells.
 */
function data_table_render(array $cfg, array $dt, callable $rowRenderer)
{
    // Preserve the FULL original column list before applying prefs, so
    // we can render the "Columns" panel listing every column (including
    // those the user has hidden, so they can un-hide them).
    $originalCols = $cfg['columns'] ?? [];

    // Apply user's persisted column prefs (order/visibility/width)
    // BEFORE we render so the page comes back already customised.
    // Pages can opt out by setting $cfg['prefs'] = false.
    $prefsEnabled = !(isset($cfg['prefs']) && $cfg['prefs'] === false);
    if ($prefsEnabled && function_exists('user_dt_prefs_apply') && function_exists('current_user_id')) {
        $cfg = user_dt_prefs_apply($cfg, current_user_id());
    }
    $id = h($cfg['id']);
    $cols = $cfg['columns'];
    $colCount = count($cols);

    // Build the column registry for the "Columns" panel: every column
    // with its current visibility & label. Done BEFORE any markup that
    // references it (the data-dt-allcols attribute on .dt-wrap renders
    // first). Empty array when prefs are disabled — the markup branches
    // on $prefsEnabled and skips emitting the attribute in that case.
    $allColsJson = [];
    if ($prefsEnabled) {
        $visibleKeys = [];
        foreach ($cols as $c) $visibleKeys[$c['key']] = true;
        // Visible columns first, in current display order; then any
        // hidden columns from the original config so the user can
        // un-hide them.
        foreach ($cols as $c) {
            $allColsJson[] = [
                'key'    => (string)$c['key'],
                'label'  => (string)($c['label'] ?? $c['key']),
                'hidden' => false,
            ];
        }
        foreach ($originalCols as $c) {
            $k = (string)($c['key'] ?? '');
            if ($k === '' || isset($visibleKeys[$k])) continue;
            $allColsJson[] = [
                'key'    => $k,
                'label'  => (string)($c['label'] ?? $k),
                'hidden' => true,
            ];
        }
    }
    // The dt-toolbar is a single horizontal strip at the top of the
    // table area. Layout, left to right:
    //   1. Pagination summary ("Showing X to Y of Z entries")
    //      + page-size selector
    //   2. Page-specific actions (CSV/Copy/tab toggles) passed in
    //      via 'actions_html' — rendered raw
    //   3. Centered title — purely textual, no description
    //   4. Pagination buttons (Prev / 1 2 3 ... / Next)
    // Pages that need a description should put it in the page's own
    // <p class="muted"> below the title or omit it entirely.
    $title       = $cfg['title']        ?? null;
    $actions     = $cfg['actions_html'] ?? null;

    // Compute the "Showing X to Y of Z" summary
    $total    = (int)$dt['total'];
    $page     = (int)$dt['page'];
    $pageSize = (int)$dt['page_size'];
    $rangeStart = $total === 0 ? 0 : (($page - 1) * $pageSize + 1);
    $rangeEnd   = min($total, $page * $pageSize);
    ?>
    <script>
    /* Mark <html> and <body> so CSS can target page-with-datatable layout
       without relying on :has(). Some browser configurations don't
       evaluate :has() consistently, which leaves .main without viewport-
       height treatment — causing the OUTER page to scroll instead of
       the inner .dt-scroll, defeating sticky thead. Placed BEFORE the
       dt-wrap renders so the layout takes effect on first paint. */
    (function () {
        if (document.documentElement && !document.documentElement.classList.contains('has-dt-wrap')) {
            document.documentElement.classList.add('has-dt-wrap');
        }
        if (document.body && !document.body.classList.contains('has-dt-wrap')) {
            document.body.classList.add('has-dt-wrap');
        }
    })();
    </script>
    <div class="dt-wrap" data-dt-id="<?= $id ?>"<?php
        if ($prefsEnabled) {
            // Pass the registry of all columns (visible + hidden) and
            // CSRF token to the JS. We use JSON inside a data- attr; the
            // JS reads it with JSON.parse(getAttribute()).
            echo ' data-dt-allcols="' . h(json_encode($allColsJson, JSON_UNESCAPED_SLASHES)) . '"';
            echo ' data-dt-csrf="'    . h(function_exists('csrf_token') ? csrf_token() : '') . '"';
            echo ' data-dt-prefs="1"';
        }
    ?>>
        <div class="dt-toolbar">
            <div class="dt-toolbar-left">
                <span class="dt-summary muted small">
                    Showing <strong class="dt-range-start"><?= $rangeStart ?></strong> to <strong class="dt-range-end"><?= $rangeEnd ?></strong>
                    of <strong class="dt-total"><?= $total ?></strong> entries
                </span>
                <label class="dt-page-size muted small">
                    <select class="dt-size no-combobox">
                        <?php foreach (data_table_page_sizes() as $s): ?>
                            <option value="<?= $s ?>" <?= $dt['page_size'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    / page
                </label>
                <?php if ($actions): ?>
                    <span class="dt-toolbar-actions"><?= $actions ?></span>
                <?php endif; ?>
                <?php if ($prefsEnabled): ?>
                    <button type="button" class="btn btn-sm btn-ghost dt-cols-btn"
                            data-dt-cols-btn
                            title="Show/hide and reorder columns. Your layout is saved per-user.">
                        ⚙ Columns
                    </button>
                <?php endif; ?>
            </div>
            <?php if ($title): ?>
                <h2 class="dt-toolbar-title"><?= h($title) ?></h2>
            <?php endif; ?>
            <div class="dt-toolbar-right">
                <span class="dt-pager">
                    <?php data_table_render_pager($dt); ?>
                </span>
            </div>
        </div>

        <div class="dt-scroll">
        <table class="data-table dt-table" data-dt-resizable="1">
            <thead>
            <tr class="dt-headers">
                <?php foreach ($cols as $idx => $c):
                    $sortable = !empty($c['sortable']);
                    $isSort   = $sortable && $dt['sort'] === $c['key'];
                    $nextDir  = ($isSort && $dt['dir'] === 'asc') ? 'desc' : 'asc';
                    $cls      = isset($c['th_class']) ? $c['th_class'] : '';
                    // The data-dt-colkey attribute identifies the column for
                    // the resize JS, which persists widths in localStorage.
                ?>
                    <th class="<?= h($cls) ?><?= $sortable ? ' dt-sortable' : '' ?><?= $isSort ? ' dt-sorted dt-sorted-' . h($dt['dir']) : '' ?>"
                        data-dt-colkey="<?= h($c['key']) ?>"
                        <?php if ($sortable): ?>
                            data-dt-sort="<?= h($c['key']) ?>"
                            data-dt-dir="<?= h($nextDir) ?>"
                            tabindex="0"
                            role="button"
                            aria-sort="<?= $isSort ? ($dt['dir'] === 'asc' ? 'ascending' : 'descending') : 'none' ?>"
                        <?php endif; ?>>
                        <span class="dt-th-label"><?= h($c['label']) ?></span>
                        <?php if ($sortable): ?>
                            <span class="dt-arrow" aria-hidden="true"><?= $isSort ? ($dt['dir'] === 'asc' ? '▲' : '▼') : '↕' ?></span>
                        <?php endif; ?>
                        <?php /* Resize handle on every column INCLUDING the last.
                                Previously the last column was skipped so the
                                handle wouldn't sit at the table's right edge,
                                but now the table grows past container width
                                during resize (and the .dt-scroll wrapper
                                provides horizontal scroll), so a right-edge
                                handle on the last column is fully usable. */ ?>
                        <span class="dt-resize-handle" data-dt-resize aria-hidden="true"></span>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody class="dt-body">
            <?php data_table_render_rows($cfg, $dt, $rowRenderer); ?>
            </tbody>
            <?php
            // Filter row lives inside <tfoot> so it shares column widths
            // with the data. The cells are sticky-positioned to the bottom
            // of the scroll container, so they stay visible regardless of
            // row count. A column is filterable by default if:
            //   1. It has a `sql_col` mapping (so the server can filter)
            //   2. Its key doesn't start with `_` (which marks meta columns
            //      like _actions)
            //   3. It hasn't explicitly opted out with `'filterable' => false`
            // Pages can still pass `'filter' => ['type'=>'select', ...]`
            // to override the default text input with a dropdown.
            // Filter row in <tfoot>: a column gets a filter input if any
            // of these are true:
            //   1. It has 'searchable' => true (legacy explicit opt-in)
            //   2. It has 'filter' => [...] (custom filter config)
            //   3. It has a sql_col (so the server can filter by it)
            //   4. It has a 'key' that doesn't start with '_' (action/utility)
            //
            // Columns that pass (4) but not (1)-(3) get a CLIENT-SIDE text
            // filter that matches against the rendered cell text. This
            // lets users search even derived/joined columns that weren't
            // given a sql_col mapping. The data-dt-col-client attribute
            // marks the input for the JS to handle locally instead of
            // sending to the server.
            //
            // Pages can explicitly opt out a column via 'filterable' => false.
            $filterable = function ($c) {
                if (isset($c['filterable']) && $c['filterable'] === false) return false;
                if (strncmp($c['key'], '_', 1) === 0) return false;
                return true;
            };
            $serverFilterable = function ($c) {
                return !empty($c['sql_col']);
            };
            $anyFilterable = false;
            foreach ($cols as $c) { if ($filterable($c)) { $anyFilterable = true; break; } }
            if ($anyFilterable):
            ?>
            <tfoot class="dt-filters">
                <tr>
                    <?php foreach ($cols as $idx => $c):
                        $isFilt = $filterable($c);
                        $isSrvFilt = $isFilt && $serverFilterable($c);
                        $cur    = $isSrvFilt ? ($dt['col_filters'][$c['key']] ?? '') : '';
                        $type   = isset($c['filter']['type']) ? $c['filter']['type'] : 'text';
                    ?>
                        <td class="dt-filter-td">
                            <?php if ($isFilt && $isSrvFilt && $type === 'select'): ?>
                                <div class="dt-filter-pill">
                                    <span class="dt-filter-icon" aria-hidden="true">🔍</span>
                                    <select class="dt-col-filter dt-col-filter-select"
                                            data-dt-col="<?= h($c['key']) ?>">
                                        <option value=""><?= h($c['filter']['placeholder'] ?? $c['label']) ?></option>
                                        <?php foreach (($c['filter']['options'] ?? []) as $opt):
                                            $v = (string)$opt['value']; $l = (string)$opt['label'];
                                        ?>
                                            <option value="<?= h($v) ?>" <?= (string)$cur === $v ? 'selected' : '' ?>>
                                                <?= h($l) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php elseif ($isFilt && $isSrvFilt): ?>
                                <div class="dt-filter-pill">
                                    <span class="dt-filter-icon" aria-hidden="true">🔍</span>
                                    <input type="text" class="dt-col-filter"
                                           data-dt-col="<?= h($c['key']) ?>"
                                           value="<?= h($cur) ?>"
                                           placeholder="<?= h($c['label']) ?>">
                                </div>
                            <?php elseif ($isFilt): ?>
                                <?php /* Column has no sql_col — provide a client-side
                                        row filter instead. data-dt-col-client tells
                                        datatable.js to filter rows in the DOM by
                                        matching the typed text against this column's
                                        rendered cell content. data-dt-col-idx is the
                                        zero-based column index. */ ?>
                                <div class="dt-filter-pill">
                                    <span class="dt-filter-icon" aria-hidden="true">🔍</span>
                                    <input type="text" class="dt-col-filter dt-col-filter-client"
                                           data-dt-col-client="1"
                                           data-dt-col-idx="<?= (int)$idx ?>"
                                           placeholder="<?= h($c['label']) ?>">
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div><!-- /.dt-scroll -->
    </div>
    <?php
}

/**
 * Render just the <tr> rows of the body. Called both by the full render
 * function above and the AJAX endpoint.
 */
function data_table_render_rows(array $cfg, array $dt, callable $rowRenderer)
{
    // Same prefs-apply step here so the AJAX-only re-render of just
    // the rows respects hidden columns and reordering.
    if (function_exists('user_dt_prefs_apply') && function_exists('current_user_id')) {
        $cfg = user_dt_prefs_apply($cfg, current_user_id());
    }
    $cols = $cfg['columns'];
    if (!$dt['rows']) {
        echo '<tr><td colspan="' . (int)count($cols) . '" class="empty">No results.</td></tr>';
        return;
    }
    foreach ($dt['rows'] as $row) {
        $cells = $rowRenderer($row);
        echo '<tr>';
        foreach ($cols as $c) {
            $cls = isset($c['td_class']) ? ' class="' . h($c['td_class']) . '"' : '';
            $val = isset($cells[$c['key']]) ? $cells[$c['key']] : '';
            echo '<td' . $cls . '>' . $val . '</td>';
        }
        echo '</tr>';
    }
}

/**
 * Render the page navigation control. Shows up to a window of pages
 * around the current one.
 */
function data_table_render_pager(array $dt)
{
    $page  = (int)$dt['page'];
    $pages = (int)$dt['pages'];
    if ($pages <= 1) {
        echo '<span class="muted small">Page 1 of 1</span>';
        return;
    }

    $window = 2;
    $from = max(1, $page - $window);
    $to   = min($pages, $page + $window);

    echo '<button type="button" class="btn btn-sm btn-ghost dt-page-btn" data-dt-page="1"'
       . ($page === 1 ? ' disabled' : '') . '>« First</button>';
    echo '<button type="button" class="btn btn-sm btn-ghost dt-page-btn" data-dt-page="' . max(1, $page - 1) . '"'
       . ($page === 1 ? ' disabled' : '') . '>‹ Prev</button>';

    if ($from > 1) echo '<span class="muted small">…</span>';
    for ($p = $from; $p <= $to; $p++) {
        echo '<button type="button" class="btn btn-sm '
           . ($p === $page ? 'btn-primary' : 'btn-ghost')
           . ' dt-page-btn" data-dt-page="' . $p . '">' . $p . '</button>';
    }
    if ($to < $pages) echo '<span class="muted small">…</span>';

    echo '<button type="button" class="btn btn-sm btn-ghost dt-page-btn" data-dt-page="' . min($pages, $page + 1) . '"'
       . ($page === $pages ? ' disabled' : '') . '>Next ›</button>';
    echo '<button type="button" class="btn btn-sm btn-ghost dt-page-btn" data-dt-page="' . $pages . '"'
       . ($page === $pages ? ' disabled' : '') . '>Last »</button>';
}

/**
 * Run the data-table flow end-to-end on the current page.
 *
 * If ?dt_format=json is set, emits a JSON response with the rendered
 * body + footer and exits. Otherwise returns the $dt result so the
 * caller can render the full page chrome around it.
 *
 * Pages just do:
 *
 *   $dt = data_table_run($cfg, function ($row) { return [...]; });
 *   // ...page chrome...
 *   data_table_render($cfg, $dt, $rowRenderer);
 *
 * On AJAX hits this function exits before the page chrome ever runs.
 */
function data_table_run(array $cfg, callable $rowRenderer)
{
    $dt = data_table_query($cfg);

    if ((string)input('dt_format', '') === 'json') {
        // Capture the rows-only HTML
        ob_start();
        data_table_render_rows($cfg, $dt, $rowRenderer);
        $rowsHtml = ob_get_clean();

        ob_start();
        data_table_render_pager($dt);
        $pagerHtml = ob_get_clean();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'         => true,
            'rows_html'  => $rowsHtml,
            'pager_html' => $pagerHtml,
            'total'      => (int)$dt['total'],
            'page'       => (int)$dt['page'],
            'pages'      => (int)$dt['pages'],
            'page_size'  => (int)$dt['page_size'],
            'sort'       => $dt['sort'],
            'dir'        => $dt['dir'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $dt;
}
