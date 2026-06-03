<?php
/**
 * MagDyn — User datatable preferences (Phase A of PRD).
 *
 * Per-user, per-datatable column layout: order, visibility, width.
 *
 * Loaded once at render time by data_table_render() and applied to
 * $cfg['columns'] BEFORE rendering, so the page comes back already
 * customised (no flash-of-default).
 *
 * Saved by the AJAX endpoint api/dt_prefs.php on user interactions
 * (column toggle, drag-reorder, drag-resize).
 *
 * Public surface:
 *   user_dt_prefs_load($userId, $dtId)        → map column_key → {order,width,hidden}
 *   user_dt_prefs_apply($cfg, $userId)        → reordered/filtered $cfg
 *   user_dt_prefs_save_width($u, $dt, $col, $px)
 *   user_dt_prefs_save_layout($u, $dt, [{col, order, hidden}, ...])
 *   user_dt_prefs_reset($u, $dt)
 */

require_once __DIR__ . '/db.php';

/**
 * Fetch all prefs for a (user, datatable) pair. Returns a map keyed
 * by column_key. Absent columns simply aren't in the map → caller
 * uses defaults for them.
 */
function user_dt_prefs_load($userId, $dtId)
{
    $userId = (int)$userId;
    $dtId   = trim((string)$dtId);
    if ($userId <= 0 || $dtId === '') return [];
    try {
        $rows = db_all(
            "SELECT column_key, display_order, width_px, is_hidden
               FROM user_dt_prefs
              WHERE user_id = ? AND dt_id = ?",
            [$userId, $dtId]
        );
    } catch (\Throwable $e) {
        // Table missing (pre-migration) or DB error — degrade gracefully
        // to default layout rather than breaking every datatable render.
        return [];
    }
    $map = [];
    foreach ($rows as $r) {
        $map[(string)$r['column_key']] = [
            'order'  => $r['display_order'] === null ? null : (int)$r['display_order'],
            'width'  => $r['width_px']      === null ? null : (int)$r['width_px'],
            'hidden' => (int)$r['is_hidden'] === 1,
        ];
    }
    return $map;
}


/**
 * Apply user prefs to a datatable config. Returns a NEW $cfg with
 * reordered + visibility-filtered columns and per-column width
 * annotations. Original $cfg is not mutated.
 *
 * If the caller passed $cfg['prefs'] === false, prefs are disabled
 * for this datatable (useful for one-off lists that don't need it).
 *
 * Visibility: hidden columns are stripped from $cfg['columns'] so
 * the server never even renders/queries them. This is more efficient
 * than rendering hidden + display:none, and it means sortable=true
 * on a hidden column can't accidentally be the current sort.
 */
function user_dt_prefs_apply(array $cfg, $userId)
{
    if (isset($cfg['prefs']) && $cfg['prefs'] === false) return $cfg;
    if (empty($cfg['id']) || empty($cfg['columns'])) return $cfg;

    $prefs = user_dt_prefs_load($userId, $cfg['id']);
    if (!$prefs) return $cfg;

    $cols = $cfg['columns'];

    // Annotate each column with its pref (default to none).
    foreach ($cols as &$c) {
        $k = $c['key'] ?? '';
        $p = $prefs[$k] ?? null;
        $c['_pref_order']  = ($p && $p['order'] !== null) ? $p['order'] : null;
        $c['_pref_width']  = ($p && $p['width'] !== null) ? $p['width'] : null;
        $c['_pref_hidden'] = ($p && $p['hidden']);
        // Carry the saved width forward as a real width hint so the
        // existing column-width rendering paths pick it up without
        // needing JS to re-apply it after load.
        if ($c['_pref_width'] !== null) {
            $c['width'] = $c['_pref_width'] . 'px';
        }
    }
    unset($c);

    // Drop hidden columns from the render. We keep the sort/search
    // pipelines focused on visible columns only.
    $cols = array_values(array_filter($cols, function ($c) {
        return empty($c['_pref_hidden']);
    }));

    // Apply ordering: columns WITH a saved order go first (sorted by
    // _pref_order), then any columns WITHOUT a saved order in their
    // original config order. New columns added to the config after a
    // user saved their layout therefore appear at the END until the
    // user reorders them — that's the least surprising default.
    $ordered = [];
    $unordered = [];
    foreach ($cols as $c) {
        if ($c['_pref_order'] !== null) {
            $ordered[] = $c;
        } else {
            $unordered[] = $c;
        }
    }
    usort($ordered, function ($a, $b) {
        return $a['_pref_order'] - $b['_pref_order'];
    });
    $cfg['columns'] = array_merge($ordered, $unordered);
    return $cfg;
}


/**
 * Persist a single column's width.
 */
function user_dt_prefs_save_width($userId, $dtId, $columnKey, $widthPx)
{
    $userId    = (int)$userId;
    $dtId      = trim((string)$dtId);
    $columnKey = trim((string)$columnKey);
    $widthPx   = (int)$widthPx;
    if ($userId <= 0 || $dtId === '' || $columnKey === '') return false;
    // Reasonable bounds — anything outside is almost certainly a bug
    // (negative widths or 10000+ widths from a runaway drag).
    if ($widthPx > 0 && ($widthPx < 30 || $widthPx > 2000)) return false;

    if ($widthPx <= 0) {
        // 0 / negative = clear the saved width (back to default)
        db_exec(
            "UPDATE user_dt_prefs
                SET width_px = NULL
              WHERE user_id = ? AND dt_id = ? AND column_key = ?",
            [$userId, $dtId, $columnKey]
        );
        return true;
    }

    db_exec(
        "INSERT INTO user_dt_prefs (user_id, dt_id, column_key, width_px)
              VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE width_px = VALUES(width_px)",
        [$userId, $dtId, $columnKey, $widthPx]
    );
    return true;
}


/**
 * Persist the full layout (order + visibility) for a datatable in
 * one shot. $items is an array of {column_key, display_order,
 * is_hidden} entries — typically every column the user can see + has
 * configured. Widths are NOT touched here.
 *
 * Rows for columns not in $items are left alone (so the user's
 * widths survive a layout reorder).
 */
function user_dt_prefs_save_layout($userId, $dtId, array $items)
{
    $userId = (int)$userId;
    $dtId   = trim((string)$dtId);
    if ($userId <= 0 || $dtId === '') return false;

    db()->beginTransaction();
    try {
        foreach ($items as $it) {
            $col    = trim((string)($it['column_key'] ?? ''));
            $order  = isset($it['display_order']) ? (int)$it['display_order'] : null;
            $hidden = !empty($it['is_hidden']) ? 1 : 0;
            if ($col === '') continue;
            db_exec(
                "INSERT INTO user_dt_prefs
                    (user_id, dt_id, column_key, display_order, is_hidden)
                  VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    display_order = VALUES(display_order),
                    is_hidden     = VALUES(is_hidden)",
                [$userId, $dtId, $col, $order, $hidden]
            );
        }
        db()->commit();
        return true;
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[user_dt_prefs] save_layout failed: ' . $e->getMessage());
        return false;
    }
}


/**
 * Delete every preference row for (user, datatable). The user gets
 * the application's default layout on next render.
 */
function user_dt_prefs_reset($userId, $dtId)
{
    $userId = (int)$userId;
    $dtId   = trim((string)$dtId);
    if ($userId <= 0 || $dtId === '') return false;
    db_exec(
        "DELETE FROM user_dt_prefs WHERE user_id = ? AND dt_id = ?",
        [$userId, $dtId]
    );
    return true;
}
