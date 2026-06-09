<?php
/**
 * MagDyn — Modules administration
 * Created: 20260515_060024_IST
 *
 * Note: module CODE is locked once created because it's referenced by
 * permission strings. Disabling a module hides it from the sidebar but
 * keeps its permissions intact.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('modules', 'view');

$action = (string)input('action', 'index');

if ($action === 'toggle') {
    require_permission('modules', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    db_exec('UPDATE modules SET is_active = 1 - is_active WHERE id = ?', [$id]);
    flash_set('success', 'Module visibility updated.');
    redirect(url('/modules.php'));
}

if ($action === 'delete') {
    require_permission('modules', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $m  = db_one('SELECT * FROM modules WHERE id = ?', [$id]);
    if (!$m) { flash_set('error', 'Module not found.'); redirect(url('/modules.php')); }

    // Protect system-critical modules — deleting these would break the app.
    $protected = ['dashboard','users','roles','modules','admin'];
    if (in_array($m['code'], $protected, true)) {
        flash_set('error', sprintf('"%s" is a system module and cannot be deleted. Disable it instead.', $m['name']));
        redirect(url('/modules.php'));
    }

    // FK CASCADE handles permissions + role_permissions + parent-child links.
    db_exec('DELETE FROM modules WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'module.delete', ?)",
            [real_user_id(), 'deleted module ' . $m['code']]);
    flash_set('success', sprintf('Module "%s" deleted along with its permissions.', $m['name']));
    redirect(url('/modules.php'));
}

if ($action === 'save') {
    require_permission('modules', 'manage');
    csrf_check();
    $id   = (int)input('id', 0);
    $name = trim((string)input('name'));
    $desc = trim((string)input('description'));
    $icon = trim((string)input('icon'));
    $sort = (int)input('sort_order', 100);
    if ($name === '') {
        flash_set('error', 'Name is required.');
        redirect(url('/modules.php?action=edit&id=' . $id));
    }
    db_exec(
        'UPDATE modules SET name = ?, description = ?, icon = ?, sort_order = ? WHERE id = ?',
        [$name, $desc, $icon, $sort, $id]
    );
    flash_set('success', 'Module updated.');
    redirect(url('/modules.php'));
}

// ============================================================
// REORDER — accepts a JSON payload of new positions from the
// tree drag-drop. Body: { items: [ {id, parent_id, sort_order}, ... ] }
// Returns JSON. Validates cycles (a group can't be inside its own
// descendant) and parent integrity (parent must be a group).
// ============================================================
if ($action === 'reorder') {
    require_permission('modules', 'manage');
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Expected JSON body { items: [...] }']);
        exit;
    }

    // Inline CSRF check — csrf_check() reads $_POST, which is empty for a
    // JSON body. We accept the token from the payload itself OR from an
    // X-CSRF-Token header. Both are sent by modules_tree.js.
    $csrfField = $GLOBALS['APP']['csrf_field'];
    $given = isset($data[$csrfField]) ? $data[$csrfField] : '';
    if (!$given) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-CSRF-Token') === 0) { $given = $v; break; }
        }
    }
    if (!$given || !hash_equals(csrf_token(), $given)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'CSRF check failed. Reload the page and try again.']);
        exit;
    }

    // Pre-load every module's current state so we can validate without
    // hammering the DB inside the loop.
    $all = [];
    foreach (db_all("SELECT id, code, parent_id, is_group FROM modules") as $r) {
        $all[(int)$r['id']] = $r;
    }

    // Validate every proposed change before applying any of them
    $changes = [];
    foreach ($data['items'] as $i => $entry) {
        if (!isset($entry['id'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Item $i missing id"]);
            exit;
        }
        $mid = (int)$entry['id'];
        if (!isset($all[$mid])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Unknown module id $mid"]);
            exit;
        }
        $newParent = isset($entry['parent_id']) && $entry['parent_id'] !== null && $entry['parent_id'] !== ''
            ? (int)$entry['parent_id']
            : null;
        $newSort   = (int)($entry['sort_order'] ?? 100);

        // Reject only if newParent doesn't exist. If the parent isn't
        // currently a group, we auto-promote it to is_group=1 below so
        // the operator can nest anywhere — the JS allows drop-inside on
        // any node, and this matches the resulting save behaviour.
        // Tracked in $autoGroupedParents so the final response can tell
        // the operator which nodes were converted.
        if ($newParent !== null) {
            if (!isset($all[$newParent])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Unknown parent id $newParent (for module {$all[$mid]['code']})"]);
                exit;
            }
        }

        // Reject if this would create a cycle: walk up from $newParent and
        // make sure we never hit $mid. Use the PROPOSED state (current $all
        // + all earlier $changes already validated) so multi-step moves are
        // sound.
        if ($newParent !== null) {
            $cursor = $newParent;
            $hops = 0;
            while ($cursor !== null) {
                if ($cursor === $mid) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => "Cannot place '{$all[$mid]['code']}' inside its own descendant"]);
                    exit;
                }
                if (++$hops > 32) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Hierarchy too deep — aborting']);
                    exit;
                }
                // Walk up: use any pending change first, then current state
                $upParent = null;
                foreach ($changes as $c) {
                    if ($c['id'] === $cursor) { $upParent = $c['parent_id']; break; }
                }
                if ($upParent === null && isset($all[$cursor]['parent_id']) && $all[$cursor]['parent_id'] !== null) {
                    $upParent = (int)$all[$cursor]['parent_id'];
                }
                $cursor = $upParent;
            }
        }

        $changes[] = [
            'id'         => $mid,
            'parent_id'  => $newParent,
            'sort_order' => $newSort,
        ];
    }

    // Apply in a single transaction
    db_exec('START TRANSACTION');
    try {
        // Determine which parents need auto-promotion to group. We do
        // this AFTER the loop so we have the full proposed parent set
        // and don't promote intermediate hops that get re-parented
        // again later in the same submission.
        $autoGroupedIds = [];
        foreach ($changes as $c) {
            if ($c['parent_id'] !== null && isset($all[$c['parent_id']])
                && (int)$all[$c['parent_id']]['is_group'] !== 1) {
                $autoGroupedIds[(int)$c['parent_id']] = true;
            }
        }
        foreach (array_keys($autoGroupedIds) as $pid) {
            db_exec('UPDATE modules SET is_group = 1 WHERE id = ?', [(int)$pid]);
        }

        foreach ($changes as $c) {
            db_exec(
                'UPDATE modules SET parent_id = ?, sort_order = ? WHERE id = ?',
                [$c['parent_id'], $c['sort_order'], $c['id']]
            );
        }

        // Symmetric to the auto-promote above: any module that is now
        // is_group=1 but has zero children left should drop back to
        // is_group=0. Without this, dragging the only child OUT of a
        // previously auto-promoted leaf leaves it stranded as an empty
        // "group" — the toggle/folder rendering sticks and the module's
        // original link semantics (sidebar URL) stay severed.
        //
        // Two layers of derived table because MariaDB rejects a self-
        // referencing subquery in an UPDATE target.
        $emptyGroupIds = [];
        foreach (db_all("
            SELECT m.id FROM modules m
             WHERE m.is_group = 1
               AND NOT EXISTS (
                   SELECT 1 FROM modules c WHERE c.parent_id = m.id
               )
        ") as $r) {
            $emptyGroupIds[] = (int)$r['id'];
        }
        $autoDemotedIds = [];
        if ($emptyGroupIds) {
            // Demote them. We only touch is_group — parent_id and other
            // fields stay untouched so the row reverts to its original
            // sidebar-link behaviour.
            $placeholders = implode(',', array_fill(0, count($emptyGroupIds), '?'));
            db_exec(
                "UPDATE modules SET is_group = 0 WHERE id IN ($placeholders)",
                $emptyGroupIds
            );
            $autoDemotedIds = $emptyGroupIds;
        }

        $auditNote = count($changes) . ' module position(s) updated';
        if ($autoGroupedIds) {
            $auditNote .= ' (' . count($autoGroupedIds) . ' parent(s) auto-promoted to group)';
        }
        if ($autoDemotedIds) {
            $auditNote .= ' (' . count($autoDemotedIds) . ' empty group(s) demoted to leaf)';
        }
        db_exec(
            "INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'module.reorder', ?)",
            [real_user_id(), $auditNote]
        );
        db_exec('COMMIT');
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'ok'              => true,
        'count'           => count($changes),
        'auto_grouped'    => array_keys($autoGroupedIds),
        'auto_demoted'    => $autoDemotedIds,
    ]);
    exit;
}

if ($action === 'edit') {
    require_permission('modules', 'manage');
    $id = (int)input('id', 0);
    $m  = db_one('SELECT * FROM modules WHERE id = ?', [$id]);
    if (!$m) { flash_set('error', 'Module not found.'); redirect(url('/modules.php')); }

    // Resolve parent + sibling context for clarity
    $hasParentCol = !empty(db_one("SHOW COLUMNS FROM modules LIKE 'parent_id'"));
    $hasGroupCol  = !empty(db_one("SHOW COLUMNS FROM modules LIKE 'is_group'"));
    $parent = null;
    $siblings = [];
    if ($hasParentCol) {
        if (!empty($m['parent_id'])) {
            $parent = db_one('SELECT id, name, code, sort_order FROM modules WHERE id = ?', [(int)$m['parent_id']]);
            $siblings = db_all(
                'SELECT id, name, code, sort_order, is_active FROM modules
                  WHERE parent_id = ? AND id <> ?
                  ORDER BY sort_order ASC, name ASC',
                [(int)$m['parent_id'], $id]
            );
        } else {
            // Top-level — siblings are other top-level (parent_id NULL) modules + groups
            $siblings = db_all(
                'SELECT id, name, code, sort_order, is_active FROM modules
                  WHERE parent_id IS NULL AND id <> ?
                    AND code NOT LIKE \'note_cat_%\'
                  ORDER BY sort_order ASC, name ASC',
                [$id]
            );
        }
    }

    $page_title  = 'Edit module';
    $page_module = 'modules';
    $focus_id    = 'f_name';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Edit module',
            'subtitle'    => $m['name'],
            'back_href'   => url('/modules.php'),
            'back_label'  => 'Modules',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/modules.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/modules.php?action=save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <div class="form-grid">
                <div class="field">
                    <label>Code</label>
                    <input type="text" value="<?= h($m['code']) ?>" readonly>
                    <span class="muted small">Locked — referenced by permission strings.</span>
                </div>
                <div class="field">
                    <label for="f_icon">Icon (optional, custom modules only)</label>
                    <input id="f_icon" name="icon" type="text" maxlength="8"
                           value="<?= h($m['icon']) ?>" tabindex="1">
                    <span class="muted small">Built-in modules use a fixed glyph from <code>module_icon()</code>. Stored value is only used as a fallback.</span>
                </div>
                <div class="field span-2">
                    <label for="f_name"><?= shortcut_label('Name', 'N') ?> *</label>
                    <input id="f_name" name="name" type="text" required tabindex="2"
                           value="<?= h($m['name']) ?>">
                </div>
                <div class="field span-2">
                    <label for="f_desc"><?= shortcut_label('Description', 'D') ?></label>
                    <input id="f_desc" name="description" type="text" tabindex="3"
                           value="<?= h($m['description']) ?>">
                </div>
                <div class="field">
                    <label for="f_sort">Sort order</label>
                    <input id="f_sort" name="sort_order" type="number" tabindex="4"
                           value="<?= (int)$m['sort_order'] ?>">
                    <span class="muted small">
                        <?php if ($parent): ?>
                            Determines position <strong>within <?= h($parent['name']) ?></strong>. Lower = higher in the submenu. To move the whole group, edit <?= h($parent['name']) ?> instead.
                        <?php elseif ($hasGroupCol && !empty($m['is_group'])): ?>
                            Determines position of this <strong>group</strong> in the top-level sidebar. Lower = higher. (Children have their own sort_order within this group.)
                        <?php else: ?>
                            Determines position in the top-level sidebar. Lower = higher.
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($hasParentCol): ?>
                <div class="field">
                    <label>Position context</label>
                    <div style="padding: 8px; background: #f9fafb; border-radius: 4px; font-size: 13px;">
                        <?php if ($parent): ?>
                            <strong>Inside:</strong> <?= h($parent['name']) ?> <span class="muted small">(group sort_order <?= (int)$parent['sort_order'] ?>)</span>
                        <?php elseif ($hasGroupCol && !empty($m['is_group'])): ?>
                            <span class="pill pill-info">group</span> at top level
                        <?php else: ?>
                            Top-level module
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($hasParentCol && !empty($siblings)): ?>
            <div class="form-section" style="margin-top: 24px;">
                <h2>
                    <?= $parent ? 'Other modules in ' . h($parent['name']) : 'Other top-level modules / groups' ?>
                    <span class="muted small" style="font-weight: normal;">(<?= count($siblings) ?>)</span>
                </h2>
                <p class="muted small">Use these as reference when picking your sort_order. Lower numbers appear first.</p>
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Code</th><th class="r">Sort order</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($siblings as $s):
                            // Mark where the current module's sort_order would land
                            $isHere = ((int)$s['sort_order'] === (int)$m['sort_order']);
                        ?>
                            <tr <?= $isHere ? 'style="background: #fef9c3;"' : '' ?>>
                                <td><a href="<?= h(url('/modules.php?action=edit&id=' . (int)$s['id'])) ?>"><?= h($s['name']) ?></a></td>
                                <td><code class="muted small"><?= h($s['code']) ?></code></td>
                                <td class="r"><strong><?= (int)$s['sort_order'] ?></strong>
                                    <?php if ($isHere): ?><span class="muted small">(same as this)</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['is_active']): ?>
                                        <span class="pill pill-active">visible</span>
                                    <?php else: ?>
                                        <span class="pill pill-neutral">hidden</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// LIST — two views: ?view=tree (default) and ?view=table
// ============================================================
$view = (string)input('view', 'tree');
if (!in_array($view, ['tree', 'table'], true)) $view = 'tree';

$canManage = permission_check('modules', 'manage');
$canDelete = permission_check('modules', 'delete');

// Detect optional columns once. Older installs may not have parent_id/is_group.
$hasParentCol = !empty(db_one("SHOW COLUMNS FROM modules LIKE 'parent_id'"));
$hasGroupCol  = !empty(db_one("SHOW COLUMNS FROM modules LIKE 'is_group'"));

// ============================================================
// TREE VIEW
// ============================================================
if ($view === 'tree') {
    // Load ALL modules in one query (filtered for note_cat_*)
    $all = db_all(
        "SELECT m.*,
                (SELECT COUNT(*) FROM permissions p WHERE p.module_id = m.id) AS perm_count
           FROM modules m
          WHERE m.code NOT LIKE 'note_cat_%'
          ORDER BY m.sort_order ASC, m.name ASC"
    );

    // Build a parent_id → [children] map
    $byParent = [];
    foreach ($all as $m) {
        $pid = $hasParentCol && !empty($m['parent_id']) ? (int)$m['parent_id'] : 0;
        $byParent[$pid][] = $m;
    }
    // Stable sort within each parent group by sort_order then name
    foreach ($byParent as &$kids) {
        usort($kids, function ($a, $b) {
            return ((int)$a['sort_order'] - (int)$b['sort_order']) ?: strcmp($a['name'], $b['name']);
        });
    }
    unset($kids);

    $page_title  = 'Modules';
    $page_module = 'modules';
    $focus_id    = '';
    require __DIR__ . '/includes/header.php';
    ?>

    <div class="page-head">
        <div>
            <h1>Modules</h1>
            <p class="muted">Reorder by drag. Drag a module onto another to nest it; non-group targets are auto-promoted into a navigation group. Drag back out to make it top-level. <strong>Sort order saves automatically.</strong></p>
        </div>
        <div class="head-actions">
            <span class="muted small" style="margin-right: 8px;">View:</span>
            <span class="pill pill-info">Tree</span>
            <a class="btn btn-ghost btn-sm" href="<?= h(url('/modules.php?view=table')) ?>">Table</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="bom-tree-toolbar">
                <button type="button" class="btn btn-sm btn-ghost" id="mod-expand-all">Expand all</button>
                <button type="button" class="btn btn-sm btn-ghost" id="mod-collapse-all">Collapse all</button>
                <span id="mod-save-status" class="muted small" style="margin-left: auto; align-self: center;"></span>
            </div>

            <ul class="bom-tree mod-tree" role="tree"
                data-can-manage="<?= $canManage ? '1' : '0' ?>"
                data-reorder-url="<?= h(url('/modules.php?action=reorder')) ?>"
                data-csrf="<?= h(csrf_token()) ?>">
                <?php
                // Recursive renderer
                function render_mod_node($m, $byParent, $depth, $canManage, $canDelete, $hasGroupCol) {
                    $kids = isset($byParent[(int)$m['id']]) ? $byParent[(int)$m['id']] : [];
                    $hasKids = !empty($kids);
                    $isGroup = $hasGroupCol && !empty($m['is_group']);
                    $codeStr = (string)$m['code'];
                    $protected = in_array($codeStr, ['dashboard','users','roles','modules','admin'], true);
                    ?>
                    <li class="bom-node mod-node <?= $depth === 0 ? 'bom-root' : '' ?> <?= $isGroup ? 'mod-group' : '' ?>"
                        role="treeitem"
                        aria-expanded="<?= $hasKids ? 'true' : 'false' ?>"
                        data-mod-id="<?= (int)$m['id'] ?>"
                        data-mod-code="<?= h($codeStr) ?>"
                        data-is-group="<?= $isGroup ? '1' : '0' ?>"
                        data-sort="<?= (int)$m['sort_order'] ?>"
                        draggable="<?= $canManage ? 'true' : 'false' ?>">
                        <div class="bom-row mod-row">
                            <?php if ($hasKids || $isGroup): ?>
                                <button type="button" class="bom-toggle" aria-label="Toggle children">▾</button>
                            <?php else: ?>
                                <span class="bom-toggle bom-leaf" aria-hidden="true">·</span>
                            <?php endif; ?>
                            <span class="bom-icon"><?= h(module_icon($m['code'], $m['icon'] ?: '·')) ?></span>
                            <div class="bom-main">
                                <strong><?= h($m['name']) ?></strong>
                                <?php if ($isGroup): ?>
                                    <span class="pill pill-info" style="font-size:10px; margin-left:6px;">group</span>
                                <?php endif; ?>
                                <code class="muted small" style="margin-left:6px;"><?= h($m['code']) ?></code>
                                <?php if (!empty($m['description'])): ?>
                                    <span class="muted small" style="margin-left:8px;">— <?= h($m['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="bom-meta mod-meta">
                                <span class="muted small mod-sort-label" title="Sort order within parent">
                                    sort <strong class="mod-sort-num"><?= (int)$m['sort_order'] ?></strong>
                                </span>
                                <?php if ((int)$m['is_active']): ?>
                                    <span class="pill pill-active">visible</span>
                                <?php else: ?>
                                    <span class="pill pill-neutral">hidden</span>
                                <?php endif; ?>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-icon" href="<?= h(url('/modules.php?action=edit&id=' . (int)$m['id'])) ?>"
                                       title="Edit" aria-label="Edit">✎</a>
                                    <form method="post" style="display:inline;"
                                          action="<?= h(url('/modules.php?action=toggle')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <button type="submit" class="btn btn-icon"
                                                title="<?= (int)$m['is_active'] ? 'Hide' : 'Show' ?>"
                                                aria-label="<?= (int)$m['is_active'] ? 'Hide' : 'Show' ?>">
                                            <?= (int)$m['is_active'] ? '🚫' : '✅' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canDelete && !$protected): ?>
                                    <form method="post" style="display:inline;"
                                          action="<?= h(url('/modules.php?action=delete')) ?>"
                                          onsubmit="return confirm('Delete module &quot;<?= h(addslashes($m['name'])) ?>&quot; and all its permissions?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                        <button type="submit" class="btn btn-icon btn-danger"
                                                title="Delete" aria-label="Delete">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($hasKids || $isGroup): ?>
                            <ul class="bom-children mod-children" role="group">
                                <?php foreach ($kids as $kid) render_mod_node($kid, $byParent, $depth + 1, $canManage, $canDelete, $hasGroupCol); ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                    <?php
                }

                $topLevel = isset($byParent[0]) ? $byParent[0] : [];
                foreach ($topLevel as $m) {
                    render_mod_node($m, $byParent, 0, $canManage, $canDelete, $hasGroupCol);
                }
                ?>
            </ul>
        </div>
    </div>

    <style>
        /* Modules-tree specifics on top of bom-tree base */
        .mod-row { grid-template-columns: 22px 22px 1fr auto; }
        .mod-row .bom-main { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .mod-meta { display: flex; align-items: center; gap: 6px; }
        .mod-group > .mod-row { background: #f0f9ff; border-left: 3px solid var(--info, #0284c7); }
        .mod-group > .mod-row:hover { background: #e0f2fe; }

        /* Drag affordances */
        .mod-node[draggable="true"] { cursor: move; }
        .mod-node.mod-dragging > .mod-row { opacity: 0.4; }
        .mod-node.mod-drop-above > .mod-row { border-top: 2px solid var(--primary, #1d4ed8); }
        .mod-node.mod-drop-below > .mod-row { border-bottom: 2px solid var(--primary, #1d4ed8); }
        .mod-node.mod-drop-inside > .mod-row { background: #ecfdf5 !important; outline: 2px dashed var(--success, #059669); }

        /* Action buttons should not initiate drag */
        .mod-meta .btn-icon, .mod-meta form { cursor: default; }
    </style>

    <script src="<?= h(url('/assets/js/modules_tree.js')) ?>"></script>

    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// TABLE VIEW (fallback / opt-in via ?view=table)
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

// Build the base SQL with optional joins to expose the parent name.
$baseSql = "SELECT m.*,
                   (SELECT COUNT(*) FROM permissions p WHERE p.module_id = m.id) AS perm_count";
if ($hasParentCol) {
    $baseSql .= ",
                   pm.name AS parent_name,
                   pm.code AS parent_code,
                   pm.sort_order AS parent_sort";
}
$baseSql .= "
              FROM modules m";
if ($hasParentCol) {
    $baseSql .= " LEFT JOIN modules pm ON pm.id = m.parent_id";
}

$dtCfg = [
    'id'       => 'modules',
    'base_sql' => $baseSql,
    // Note-category permission modules (note_cat_*) are managed via the
    // Categories admin (Admin → Categories → Running Notes). Showing them
    // here would let an admin edit them out of sync with their parent
    // category. Filter them out.
    'extra_where' => [
        ["m.code NOT LIKE 'note_cat_%'", []],
    ],
    'columns'  => array_values(array_filter([
        ['key'=>'_icon',      'label'=>'',           'sortable'=>false,'searchable'=>false],
        ['key'=>'name',       'label'=>'Name',       'sortable'=>true, 'searchable'=>true,  'sql_col'=>'m.name'],
        ['key'=>'code',       'label'=>'Code',       'sortable'=>true, 'searchable'=>true,  'sql_col'=>'m.code'],
        $hasParentCol ? ['key'=>'parent',  'label'=>'Parent',    'sortable'=>true, 'searchable'=>true, 'sql_col'=>'pm.name'] : null,
        ['key'=>'sort_order', 'label'=>'Order',      'sortable'=>true, 'searchable'=>false, 'sql_col'=>'m.sort_order','th_class'=>'r','td_class'=>'r'],
        ['key'=>'perm_count', 'label'=>'Permissions','sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'is_active',  'label'=>'Status',     'sortable'=>true, 'searchable'=>false, 'sql_col'=>'m.is_active'],
        ['key'=>'_actions',   'label'=>'Actions',    'sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ])),
    // Default sort = hierarchical: groups + top-level modules first by their
    // own sort_order; children indented under each parent. The composite
    // expression sorts by (parent's sort_order OR own), then top-level
    // before children at the same number, then own sort_order, then name.
    'default_sort' => ['sort_order', 'asc'],   // header arrow when user clicks
    'default_order_by' => $hasParentCol
        ? 'COALESCE(pm.sort_order, m.sort_order) ASC, (m.parent_id IS NOT NULL) ASC, m.sort_order ASC, m.name ASC'
        : 'm.sort_order ASC, m.name ASC',
];

$rowRenderer = function ($m) use ($canManage, $canDelete, $hasParentCol, $hasGroupCol) {
    $indent = ($hasParentCol && !empty($m['parent_id']))
        ? '<span style="display:inline-block; width:18px; color:#9ca3af;">└</span> '
        : '';
    $groupBadge = ($hasGroupCol && !empty($m['is_group']))
        ? ' <span class="pill pill-info" style="font-size:10px;">group</span>'
        : '';
    $nameInner = $canManage
        ? $indent . '<strong><a href="' . h(url('/modules.php?action=edit&id=' . (int)$m['id'])) . '">' . h($m['name']) . '</a></strong>' . $groupBadge
        : $indent . '<strong>' . h($m['name']) . '</strong>' . $groupBadge;
    if (!empty($m['description'])) {
        $nameInner .= '<div class="muted small">' . h($m['description']) . '</div>';
    }
    $status = $m['is_active']
        ? '<span class="pill pill-active">visible</span>'
        : '<span class="pill pill-neutral">hidden</span>';

    $actions = '';
    if ($canManage) {
        $actions .= '<a class="btn btn-icon" href="' . h(url('/modules.php?action=edit&id=' . (int)$m['id'])) . '"'
                  . ' title="Edit" aria-label="Edit">✎ <span class="dt-action-label">Edit</span></a> ';
        $toggleTitle = $m['is_active'] ? 'Hide' : 'Show';
        $toggleGlyph = $m['is_active'] ? '🚫' : '✅';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/modules.php?action=toggle')) . '">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$m['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="' . $toggleTitle . '" aria-label="' . $toggleTitle . '">'
                  . $toggleGlyph . ' <span class="dt-action-label">' . $toggleTitle . '</span></button></form> ';
    }
    if ($canDelete && !in_array($m['code'], ['dashboard','users','roles','modules','admin'])) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/modules.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Delete module &quot;' . h(addslashes($m['name'])) . '&quot; and all its permissions?\');">'
                  . csrf_field()
                  . '<input type="hidden" name="id" value="' . (int)$m['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
    }

    $row = [
        '_icon'      => '<span style="font-size:18px;">' . h(module_icon($m['code'], $m['icon'] ?: '·')) . '</span>',
        'name'       => $nameInner,
        'code'       => '<code>' . h($m['code']) . '</code>',
    ];
    if ($hasParentCol) {
        $row['parent'] = !empty($m['parent_name'])
            ? '<span class="muted small">' . h($m['parent_name']) . '</span>'
            : '<span class="muted small">—</span>';
    }
    $row['sort_order'] = (int)$m['sort_order'];
    $row['perm_count'] = isset($m['perm_count']) ? (int)$m['perm_count'] : 0;
    $row['is_active']  = $status;
    $row['_actions']   = dt_actions_wrap($actions);
    return $row;
};

$dt = data_table_run($dtCfg, $rowRenderer);

$page_title  = 'Modules';
$page_module = 'modules';
$focus_id    = '';

$dtCfg['title'] = 'Modules';

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Modules</h1>
        <p class="muted">Flat table view, sortable by any column.</p>
    </div>
    <div class="head-actions">
        <span class="muted small" style="margin-right: 8px;">View:</span>
        <a class="btn btn-ghost btn-sm" href="<?= h(url('/modules.php?view=tree')) ?>">Tree</a>
        <span class="pill pill-info">Table</span>
    </div>
</div>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
