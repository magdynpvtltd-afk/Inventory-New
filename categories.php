<?php
/**
 * MagDyn — Categories
 * Created: 20260515_093000_IST
 *
 * Hierarchical categories partitioned by `type`. One DB table, one page,
 * one tab per type. Each tab shows a parent/child tree (same pattern as
 * locations.php) with inline edit + hard delete.
 *
 * URL actions:
 *   ?type=<type>           switch the active tab (default 'asset')
 *   ?action=new            new category form
 *   ?action=edit&id=N      edit category form
 *   ?action=save (POST)    save (insert or update)
 *   ?action=toggle&id=N    flip is_active
 *   ?action=delete&id=N    hard delete (blocked if has children or refs)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('categories', 'view');

// ---- Type whitelist + display names ----
// Add to this map when you want a new tab. The migration seeds asset /
// inventory / inspection / invoice; running_notes added for completeness.
$TYPES = [
    'asset'         => 'Asset',
    'inventory'     => 'Inventory',
    'inspection'    => 'Inspection',
    'running_notes' => 'Running Notes',
    'invoice'       => 'Invoice',
    // "All" is a shared bucket: categories created here surface in EVERY
    // module's category picker (currently Asset models + Inventory items)
    // alongside that module's own categories. Managed on its own tab like
    // any other type; the consumer queries include type='all'.
    'all'           => 'All modules',
];

$typeKey = (string)input('type', 'asset');
if (!isset($TYPES[$typeKey])) $typeKey = 'asset';

$action    = (string)input('action', 'index');
$canManage = permission_check('categories', 'manage');
$canDelete = permission_check('categories', 'delete');

// ============================================================
// SAVE
// ============================================================
if ($action === 'save') {
    require_permission('categories', 'manage');
    csrf_check();
    $id        = (int)input('id', 0);
    $type      = (string)input('type', $typeKey);
    if (!isset($TYPES[$type])) $type = 'asset';

    $code      = trim((string)input('code'));
    $name      = trim((string)input('name'));
    $parentRaw = (int)input('parent_id', 0);
    $parent    = $parentRaw ?: null;
    $notes     = trim((string)input('notes'));
    $sort      = (int)input('sort_order', 100);
    $active    = input('is_active') ? 1 : 0;

    $errors = [];
    if ($code === '') $errors[] = 'Code is required.';
    if ($name === '') $errors[] = 'Name is required.';

    // Parent must be the same type
    if ($parent) {
        $parentRow = db_one('SELECT id, type FROM categories WHERE id = ?', [$parent]);
        if (!$parentRow)            $errors[] = 'Parent category not found.';
        elseif ($parentRow['type'] !== $type) $errors[] = 'Parent must be the same category type.';
    }

    // Cycle prevention: walk up from the chosen parent; if we hit our own id, abort
    if ($id && $parent) {
        $walker = (int)$parent;
        $seen   = [];
        while ($walker) {
            if ($walker === $id) { $errors[] = 'Cannot make a descendant the parent.'; break; }
            if (isset($seen[$walker])) break;
            $seen[$walker] = 1;
            $walker = (int)db_val('SELECT parent_id FROM categories WHERE id = ?', [$walker], 0);
        }
    }

    $clash = db_one('SELECT id FROM categories WHERE type = ? AND code = ? AND id <> ?', [$type, $code, $id]);
    if ($clash) $errors[] = 'Another category in this type uses that code.';

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        $back = $id
            ? url('/categories.php?action=edit&id=' . $id . '&type=' . $type)
            : url('/categories.php?action=new&type=' . $type);
        redirect($back);
    }

    if ($id) {
        db_exec(
            'UPDATE categories SET type=?, parent_id=?, code=?, name=?, notes=?, sort_order=?, is_active=? WHERE id=?',
            [$type, $parent, $code, $name, $notes, $sort, $active, $id]
        );
        flash_set('success', 'Category updated.');
    } else {
        db_exec(
            'INSERT INTO categories (type, parent_id, code, name, notes, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$type, $parent, $code, $name, $notes, $sort, $active]
        );
        flash_set('success', 'Category created.');
    }

    // For running-notes categories, register matching note_cat_* permission
    // modules so the new category appears in the role editor immediately.
    if ($type === 'running_notes') {
        require_once __DIR__ . '/includes/_notes.php';
        notes_sync_category_permissions();
    }

    redirect(url('/categories.php?type=' . $type));
}

// ============================================================
// TOGGLE
// ============================================================
if ($action === 'toggle') {
    require_permission('categories', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    $cat = db_one('SELECT type, code FROM categories WHERE id = ?', [$id]);
    db_exec('UPDATE categories SET is_active = 1 - is_active WHERE id = ?', [$id]);
    // For running_notes categories, mirror the active state onto the
    // corresponding permission module so the roles editor doesn't show
    // disabled categories.
    if ($cat && $cat['type'] === 'running_notes') {
        db_exec(
            "UPDATE modules SET is_active = (SELECT is_active FROM categories WHERE id = ?)
              WHERE code = ?",
            [$id, 'note_cat_' . $cat['code']]
        );
    }
    flash_set('success', 'Category toggled.');
    redirect(url('/categories.php?type=' . $typeKey));
}

// ============================================================
// DELETE
// ============================================================
if ($action === 'delete') {
    require_permission('categories', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $cat = db_one('SELECT * FROM categories WHERE id = ?', [$id]);
    if (!$cat) { flash_set('error', 'Category not found.'); redirect(url('/categories.php?type=' . $typeKey)); }

    // Block if any child categories
    $kids = db_val('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$id], 0);
    if ($kids > 0) {
        flash_set('error', sprintf('Cannot delete "%s" — it has %d child categor%s.',
            $cat['name'], $kids, $kids === 1 ? 'y' : 'ies'));
        redirect(url('/categories.php?type=' . $cat['type']));
    }

    // Block if any asset model references it (only asset_models has the FK so far)
    if ($cat['type'] === 'asset') {
        $linked = 0;
        try {
            $linked = (int)db_val('SELECT COUNT(*) FROM asset_models WHERE category_id = ?', [$id], 0);
        } catch (Exception $e) { /* asset_models may not exist */ }
        if ($linked > 0) {
            flash_set('error', sprintf('Cannot delete "%s" — %d asset model(s) reference it.', $cat['name'], $linked));
            redirect(url('/categories.php?type=' . $cat['type']));
        }
    }

    db_exec('DELETE FROM categories WHERE id = ?', [$id]);
    // For running_notes categories, also drop the corresponding
    // permission module (cascade-delete its permissions and role grants).
    if ($cat['type'] === 'running_notes') {
        db_exec('DELETE FROM modules WHERE code = ?', ['note_cat_' . $cat['code']]);
    }
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'category.delete', ?)",
            [real_user_id(), 'deleted category ' . $cat['type'] . '/' . $cat['code']]);
    flash_set('success', 'Category deleted.');
    redirect(url('/categories.php?type=' . $cat['type']));
}

// ============================================================
// CATEGORY EXPORT CSV
// ============================================================
if ($action === 'export') {
    require_permission('categories', 'manage');
    $exportType = (string)input('type', '');
    $where  = $exportType && isset($TYPES[$exportType]) ? 'WHERE type = ' . db()->quote($exportType) : '';
    $expRows = db_all("SELECT type, code, name, notes, sort_order, is_active FROM categories $where ORDER BY type, sort_order, name");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="categories_' . ($exportType ?: 'all') . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['type', 'code', 'name', 'notes', 'sort_order', 'is_active']);
    foreach ($expRows as $r) {
        fputcsv($out, [$r['type'], $r['code'], $r['name'], $r['notes'] ?? '', $r['sort_order'], $r['is_active']]);
    }
    fclose($out);
    exit;
}

// ============================================================
// CATEGORY CSV IMPORT — preview + commit
// Supports two CSV formats:
//   FULL (with headers): type, code, name, notes, sort_order, is_active
//   SIMPLE (headerless): one category name per line (type chosen in modal)
// Codes are auto-generated for the simple format.
// ============================================================
require_once __DIR__ . '/includes/_import.php';

function category_code_from_name($name)
{
    // Uppercase slug: non-alphanumeric → underscore, max 12 chars.
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', trim($name)));
    $base = trim($base, '_');
    return $base !== '' ? substr($base, 0, 12) : 'CAT';
}

function category_code_generate($name, $type)
{
    $base = category_code_from_name($name);
    $candidate = $base;
    $i = 2;
    while (db_val('SELECT id FROM categories WHERE type = ? AND code = ?', [$type, $candidate], 0)) {
        $candidate = $base . '_' . $i++;
    }
    return $candidate;
}

if ($action === 'import_preview') {
    require_permission('categories', 'manage');
    csrf_check();

    $importType = (string)input('import_type', 'asset');
    if (!isset($TYPES[$importType])) $importType = 'asset';

    if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'No CSV file uploaded or upload failed.');
        redirect(url('/categories.php?type=' . $typeKey));
    }
    $raw = file_get_contents($_FILES['csv']['tmp_name']);
    if ($raw === false || $raw === '') {
        flash_set('error', 'Could not read the uploaded file.');
        redirect(url('/categories.php?type=' . $typeKey));
    }

    // Strip BOM, normalise line endings
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    // Detect format: FULL (first row has "type","code","name" headers) vs SIMPLE (name-only)
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $firstRow = fgetcsv($fh);
    fclose($fh);
    $firstRowLower = array_map(function($v){ return strtolower(trim((string)$v)); }, $firstRow ?: []);
    $isFullFormat  = in_array('name', $firstRowLower, true) && in_array('type', $firstRowLower, true);

    $parsed = [];
    if ($isFullFormat) {
        // Full format: parse with _import.php helper (uses first row as header)
        $parsedResult = import_parse_csv_text($raw);
        if (!$parsedResult['ok']) {
            flash_set('error', $parsedResult['error']);
            redirect(url('/categories.php?type=' . $typeKey));
        }
        foreach ($parsedResult['rows'] as $r) {
            $name = trim((string)($r['name'] ?? ''));
            $type = trim((string)($r['type'] ?? $importType));
            if (!isset($TYPES[$type])) $type = $importType;
            if ($name === '' || preg_match('/^\.*\s*$/', $name)) continue;
            $parsed[] = [
                'name'       => $name,
                'type'       => $type,
                'code'       => trim((string)($r['code']       ?? '')),
                'notes'      => trim((string)($r['notes']      ?? '')),
                'sort_order' => isset($r['sort_order']) && $r['sort_order'] !== '' ? (int)$r['sort_order'] : 100,
                'is_active'  => isset($r['is_active'])  && $r['is_active']  !== '' ? ((int)$r['is_active'] ? 1 : 0) : 1,
                '_line'      => (int)($r['_line'] ?? 0),
            ];
        }
    } else {
        // Simple format: one name per line, no header, type from selector
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $lineNo = 0;
        while (($cols = fgetcsv($fh)) !== false) {
            $lineNo++;
            $name = isset($cols[0]) ? trim($cols[0]) : '';
            if ($name === '' || preg_match('/^\.*\s*$/', $name)) continue;
            $parsed[] = ['name' => $name, 'type' => $importType, 'code' => '', 'notes' => '', 'sort_order' => 100, 'is_active' => 1, '_line' => $lineNo];
        }
        fclose($fh);
    }

    if (empty($parsed)) {
        flash_set('error', 'No valid category names found in the file.');
        redirect(url('/categories.php?type=' . $typeKey));
    }

    // Build existing name maps per type
    $existingByType = [];
    $existingCodesByType = [];
    foreach (db_all('SELECT type, LOWER(name) AS n, code FROM categories') as $r) {
        $existingByType[$r['type']][$r['n']] = true;
        $existingCodesByType[$r['type']][$r['code']] = true;
    }

    $previewRows = [];
    $insertCount = 0;
    $skipCount   = 0;
    $usedCodes   = [];
    $sortSeq     = 100;
    foreach ($parsed as $row) {
        $type = $row['type'];
        $key  = strtolower($row['name']);
        if (isset($existingByType[$type][$key])) {
            $skipCount++;
            $previewRows[] = ['line' => $row['_line'], 'status' => 'skip', 'name' => $row['name'], 'type' => $type, 'code' => '', 'reason' => 'Name already exists in ' . ($TYPES[$type] ?? $type)];
        } else {
            // Resolve code: use provided code if non-empty and unique, otherwise auto-generate
            if ($row['code'] !== '') {
                $code = $row['code'];
                if (isset($existingCodesByType[$type][$code]) || isset($usedCodes[$type][$code])) {
                    $code = category_code_generate($row['name'], $type);
                }
            } else {
                $base = category_code_from_name($row['name']);
                $code = $base; $i = 2;
                while (
                    isset($existingCodesByType[$type][$code]) || isset($usedCodes[$type][$code])
                ) { $code = $base . '_' . $i++; }
            }
            $usedCodes[$type][$code] = true;

            $insertCount++;
            $previewRows[] = ['line' => $row['_line'], 'status' => 'insert', 'name' => $row['name'], 'type' => $type, 'code' => $code,
                              'notes' => $row['notes'], 'sort_order' => $row['sort_order'], 'reason' => ''];
            $existingByType[$type][$key] = true;
            $sortSeq += 10;
        }
    }

    $token = import_stash($raw . "\x00" . $importType, 'category');

    $page_title  = 'Import categories · preview';
    $page_module = 'categories';
    $focus_id    = '';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="import-preview-page">
        <div class="page-head">
            <div>
                <h1>Import categories · preview</h1>
                <p class="muted">
                    <?= $isFullFormat ? 'Full format detected (type/code/name headers).' : 'Simple format — importing into type: <strong>' . h($TYPES[$importType]) . '</strong>.' ?>
                    Green rows will be inserted; grey rows are skipped.
                    Click Commit to apply.
                </p>
            </div>
        </div>
        <div class="import-summary">
            <span class="pill pill-active">✓ Insert: <?= $insertCount ?></span>
            <span class="pill pill-neutral">⊘ Skip: <?= $skipCount ?></span>
        </div>
        <div class="import-actions" style="margin: 16px 0;">
            <form method="post" action="<?= h(url('/categories.php?action=import_commit')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button type="submit" class="btn btn-primary"
                        <?= $insertCount === 0 ? 'disabled title="Nothing to insert"' : '' ?>>
                    Commit <?= $insertCount ?> categor<?= $insertCount === 1 ? 'y' : 'ies' ?>
                </button>
            </form>
            <a class="btn btn-ghost" href="<?= h(url('/categories.php?type=' . $importType)) ?>">Cancel</a>
        </div>
        <table class="data-table import-preview-table">
            <thead>
                <tr>
                    <th style="width:52px;">Line</th>
                    <th style="width:82px;">Status</th>
                    <th>Type</th><th>Code</th><th>Name</th><th>Notes</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($previewRows as $r):
                    $cls = $r['status'] === 'insert' ? 'imp-insert' : 'imp-skip';
                    $lbl = $r['status'] === 'insert' ? '✓ Insert'   : '⊘ Skip';
                ?>
                <tr class="<?= $cls ?>">
                    <td class="r muted small"><?= (int)$r['line'] ?></td>
                    <td><strong><?= h($lbl) ?></strong></td>
                    <td><?= h($r['type'] ?? $importType) ?></td>
                    <td><code><?= h($r['code']) ?></code></td>
                    <td><?= h($r['name']) ?></td>
                    <td class="muted small"><?= h($r['notes'] ?? '') ?></td>
                    <td class="muted small"><?= h($r['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'import_commit') {
    require_permission('categories', 'manage');
    csrf_check();

    $token   = trim((string)input('token', ''));
    $rawFull = import_unstash($token, 'category');
    if ($rawFull === null) {
        flash_set('error', 'Import session expired. Please re-upload the CSV.');
        redirect(url('/categories.php'));
    }

    $parts      = explode("\x00", $rawFull, 2);
    $raw        = $parts[0];
    $importType = isset($parts[1]) ? trim($parts[1]) : 'asset';
    if (!isset($TYPES[$importType])) $importType = 'asset';

    // Detect format same way as preview
    $fh2 = fopen('php://memory', 'r+');
    fwrite($fh2, $raw);
    rewind($fh2);
    $firstRow2 = fgetcsv($fh2);
    fclose($fh2);
    $firstLower2 = array_map(function($v){ return strtolower(trim((string)$v)); }, $firstRow2 ?: []);
    $isFullFmt2  = in_array('name', $firstLower2, true) && in_array('type', $firstLower2, true);

    $rows = [];
    if ($isFullFmt2) {
        $pr = import_parse_csv_text($raw);
        if (!empty($pr['ok'])) {
            foreach ($pr['rows'] as $r) {
                $name = trim((string)($r['name'] ?? ''));
                $type = trim((string)($r['type'] ?? $importType));
                if (!isset($TYPES[$type])) $type = $importType;
                if ($name === '' || preg_match('/^\.*\s*$/', $name)) continue;
                $rows[] = [
                    'name'       => $name,
                    'type'       => $type,
                    'code'       => trim((string)($r['code'] ?? '')),
                    'notes'      => trim((string)($r['notes'] ?? '')) ?: null,
                    'sort_order' => isset($r['sort_order']) && $r['sort_order'] !== '' ? (int)$r['sort_order'] : 100,
                    'is_active'  => isset($r['is_active'])  && $r['is_active']  !== '' ? ((int)$r['is_active'] ? 1 : 0) : 1,
                ];
            }
        }
    } else {
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $sortSeqBase = (int)db_val('SELECT COALESCE(MAX(sort_order), 90) FROM categories WHERE type = ?', [$importType], 90);
        while (($cols = fgetcsv($fh)) !== false) {
            $name = isset($cols[0]) ? trim($cols[0]) : '';
            if ($name === '' || preg_match('/^\.*\s*$/', $name)) continue;
            $sortSeqBase += 10;
            $rows[] = ['name' => $name, 'type' => $importType, 'code' => '', 'notes' => null, 'sort_order' => $sortSeqBase, 'is_active' => 1];
        }
        fclose($fh);
    }

    $existingByType = [];
    $existingCodesByType = [];
    foreach (db_all('SELECT type, LOWER(name) AS n, code FROM categories') as $r) {
        $existingByType[$r['type']][$r['n']] = true;
        $existingCodesByType[$r['type']][$r['code']] = true;
    }

    $inserted = 0; $skipped = 0; $usedCodes = [];
    $firstType = $rows ? $rows[0]['type'] : $importType;

    foreach ($rows as $row) {
        $type = $row['type'];
        $key  = strtolower($row['name']);
        if (isset($existingByType[$type][$key])) { $skipped++; continue; }

        if ($row['code'] !== '') {
            $code = $row['code'];
            if (isset($existingCodesByType[$type][$code]) || isset($usedCodes[$type][$code])) {
                $code = category_code_generate($row['name'], $type);
            }
        } else {
            $base = category_code_from_name($row['name']);
            $code = $base; $i = 2;
            while (isset($existingCodesByType[$type][$code]) || isset($usedCodes[$type][$code])) {
                $code = $base . '_' . $i++;
            }
        }
        $usedCodes[$type][$code] = true;

        db_exec(
            'INSERT INTO categories (type, parent_id, code, name, notes, sort_order, is_active)
             VALUES (?, NULL, ?, ?, ?, ?, ?)',
            [$type, $code, $row['name'], $row['notes'], $row['sort_order'], $row['is_active']]
        );
        $existingByType[$type][$key] = true;
        $existingCodesByType[$type][$code] = true;
        $inserted++;
    }

    flash_set('success', sprintf(
        'Imported %d categor%s.%s',
        $inserted,
        $inserted === 1 ? 'y' : 'ies',
        $skipped > 0 ? " $skipped skipped (already existed)." : ''
    ));
    redirect(url('/categories.php?type=' . $firstType));
}

// ============================================================
// NEW / EDIT FORM
// ============================================================
if ($action === 'new' || $action === 'edit') {
    require_permission('categories', 'manage');
    $editing = null;
    if ($action === 'edit') {
        $id = (int)input('id', 0);
        $editing = db_one('SELECT * FROM categories WHERE id = ?', [$id]);
        if (!$editing) { flash_set('error', 'Category not found.'); redirect(url('/categories.php')); }
        $typeKey = $editing['type'];
    }

    // Possible parents = same-type categories EXCEPT self and descendants
    $excludeIds = [];
    if ($editing) {
        $excludeIds[] = (int)$editing['id'];
        $stack = [(int)$editing['id']];
        while ($stack) {
            $cur = array_pop($stack);
            $kids = db_all('SELECT id FROM categories WHERE parent_id = ?', [$cur]);
            foreach ($kids as $k) { $excludeIds[] = (int)$k['id']; $stack[] = (int)$k['id']; }
        }
    }
    $parentOptions = db_all(
        'SELECT * FROM categories WHERE type = ? ORDER BY sort_order, name',
        [$typeKey]
    );

    $page_title  = $editing ? 'Edit category' : 'New category';
    $page_module = 'categories';
    $focus_id    = 'f_code';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? 'Edit category' : 'New category',
            'subtitle'    => $TYPES[$typeKey] . ($editing ? ' · ' . $editing['name'] : ''),
            'back_href'   => url('/categories.php?type=' . $typeKey),
            'back_label'  => $TYPES[$typeKey],
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/categories.php?type=' . $typeKey)) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/categories.php?action=save')) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id"   value="<?= $editing ? (int)$editing['id'] : '' ?>">
            <input type="hidden" name="type" value="<?= h($typeKey) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="f_code"><?= shortcut_label('Code', 'C') ?> *</label>
                    <input id="f_code" name="code" type="text" required tabindex="1"
                           value="<?= h($editing['code'] ?? '') ?>">
                    <span class="muted small">Short identifier unique within this type.</span>
                </div>
                <div class="field">
                    <label for="f_name"><?= shortcut_label('Name', 'N') ?> *</label>
                    <input id="f_name" name="name" type="text" required tabindex="2"
                           value="<?= h($editing['name'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_parent"><?= shortcut_label('Parent', 'P') ?> category</label>
                    <select id="f_parent" name="parent_id" tabindex="3">
                        <option value="">— Top level —</option>
                        <?php foreach ($parentOptions as $p):
                            if (in_array((int)$p['id'], $excludeIds, true)) continue; ?>
                            <option value="<?= (int)$p['id'] ?>"
                                    <?= (isset($editing['parent_id']) && (int)$editing['parent_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                <?= h($p['code']) ?> — <?= h($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="f_notes"><?= shortcut_label('Notes', 'O') ?></label>
                    <input id="f_notes" name="notes" type="text" tabindex="4"
                           value="<?= h($editing['notes'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_sort">Sort order</label>
                    <input id="f_sort" name="sort_order" type="number" tabindex="5"
                           value="<?= isset($editing['sort_order']) ? (int)$editing['sort_order'] : 100 ?>">
                </div>
                <div class="field">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="is_active" value="1" tabindex="6"
                               <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                        <?= shortcut_label('Active', 'A') ?>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// LIST (default) — tabbed by type. Tree by default; flat
// sortable/searchable datatable when any dt_* URL param is present.
// ============================================================
require_once __DIR__ . '/includes/datatable.php';

$useDataTable = false;
foreach (array_keys(array_merge($_GET, $_POST)) as $k) {
    if (strpos($k, 'dt_') === 0) { $useDataTable = true; break; }
}

if ($useDataTable) {
    $dtCfg = [
        'id'       => 'categories_' . $typeKey,
        'base_sql' => 'SELECT c.*, p.name AS parent_name
                         FROM categories c
                         LEFT JOIN categories p ON p.id = c.parent_id',
        'extra_where' => [['c.type = ?', $typeKey]],
        'columns'  => [
            ['key'=>'name',        'label'=>'Name',   'sortable'=>true, 'searchable'=>true, 'sql_col'=>'c.name'],
            ['key'=>'code',        'label'=>'Code',   'sortable'=>true, 'searchable'=>true, 'sql_col'=>'c.code'],
            ['key'=>'parent_name', 'label'=>'Parent', 'sortable'=>true, 'searchable'=>true, 'sql_col'=>'p.name'],
            ['key'=>'notes',       'label'=>'Notes',  'sortable'=>false,'searchable'=>true, 'sql_col'=>'c.notes', 'td_class'=>'muted small'],
            ['key'=>'sort_order',  'label'=>'Order',  'sortable'=>true, 'searchable'=>false,'sql_col'=>'c.sort_order','th_class'=>'r','td_class'=>'r'],
            ['key'=>'is_active',   'label'=>'Status', 'sortable'=>true, 'searchable'=>false,'sql_col'=>'c.is_active'],
            ['key'=>'_actions',    'label'=>'Actions','sortable'=>false,'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
        ],
        'default_sort' => ['name', 'asc'],
    ];

    $rowRenderer = function ($r) use ($canManage, $canDelete, $typeKey) {
        $name = $canManage
            ? '<strong><a href="' . h(url('/categories.php?action=edit&id=' . (int)$r['id'])) . '">' . h($r['name']) . '</a></strong>'
            : '<strong>' . h($r['name']) . '</strong>';
        $status = $r['is_active']
            ? '<span class="pill pill-active">active</span>'
            : '<span class="pill pill-neutral">inactive</span>';
        $actions = '';
        if ($canManage) {
            $toggleTitle = $r['is_active'] ? 'Disable' : 'Enable';
            $toggleGlyph = $r['is_active'] ? '🚫' : '✅';
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/categories.php?action=toggle&type=' . $typeKey)) . '">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon" type="submit" title="' . $toggleTitle . '" aria-label="' . $toggleTitle . '">'
                      . $toggleGlyph . ' <span class="dt-action-label">' . $toggleTitle . '</span></button></form> ';
        }
        if ($canDelete) {
            $actions .= '<form method="post" style="display:inline" action="' . h(url('/categories.php?action=delete&type=' . $typeKey)) . '"'
                      . ' onsubmit="return confirm(\'Delete category &quot;' . h(addslashes($r['name'])) . '&quot;?\');">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                      . '<button class="btn btn-icon btn-danger" type="submit" title="Delete" aria-label="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
        }
        return [
            'name'        => $name,
            'code'        => '<code>' . h($r['code']) . '</code>',
            'parent_name' => h($r['parent_name'] ?: '—'),
            'notes'       => h($r['notes'] ?: ''),
            'sort_order'  => (int)$r['sort_order'],
            'is_active'   => $status,
            '_actions'    => dt_actions_wrap($actions),
        ];
    };

    $dt = data_table_run($dtCfg, $rowRenderer);

    $page_title  = 'Categories';
    $page_module = 'categories';
    $focus_id    = '';

    $actionsHtml = '';
    if ($canManage) {
        $actionsHtml = '<button type="button" class="btn btn-ghost btn-sm"'
                     . ' data-open-import="category-import-modal"'
                     . ' title="Import categories from CSV">⤒ Import CSV</button> ';
        $actionsHtml .= '<a class="btn btn-primary btn-sm" href="' . h(url('/categories.php?action=new&type=' . $typeKey)) . '"'
                      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New category', 'N') . '</a>';
    }
    $actionsHtml .= ' <a class="btn btn-ghost btn-sm" href="' . h(url('/categories.php?type=' . $typeKey)) . '">← Tree view</a>';
    $dtCfg['title']        = $TYPES[$typeKey] . ' categories (flat)';
    $dtCfg['actions_html'] = $actionsHtml;

    require __DIR__ . '/includes/header.php';
    ?>
    <?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// -------- Tree view (default) --------
$rows = db_all('SELECT * FROM categories WHERE type = ? ORDER BY parent_id IS NULL DESC, parent_id, sort_order, name', [$typeKey]);

// Group by parent for tree rendering. parent_id NULL becomes key 0.
$byParent = [];
$byId     = [];
foreach ($rows as $r) {
    $byId[(int)$r['id']] = $r;
    $byParent[(int)$r['parent_id']][] = $r;
}
$GLOBALS['__cat_byid'] = $byId;

function render_cat_tree($parentId, $byParent, $canManage, $canDelete, $typeKey) {
    if (empty($byParent[$parentId])) return;
    foreach ($byParent[$parentId] as $r):
        $id = (int)$r['id'];
        // Compute depth by walking up
        $depth = 0; $walk = (int)$r['parent_id'];
        while ($walk) {
            $depth++;
            $walk = (int)($GLOBALS['__cat_byid'][$walk]['parent_id'] ?? 0);
        }
        ?>
        <tr>
            <td>
                <?= str_repeat("\xC2\xA0\xC2\xA0\xC2\xA0", $depth) ?>
                <?php if ($depth > 0): ?>└ <?php endif; ?>
                <?php if ($canManage): ?>
                    <strong><a href="<?= h(url('/categories.php?action=edit&id=' . $id)) ?>"><?= h($r['name']) ?></a></strong>
                <?php else: ?>
                    <strong><?= h($r['name']) ?></strong>
                <?php endif; ?>
            </td>
            <td><code><?= h($r['code']) ?></code></td>
            <td class="muted small"><?= h($r['notes'] ?: '') ?></td>
            <td class="r"><?= (int)$r['sort_order'] ?></td>
            <td><?php if ($r['is_active']): ?>
                <span class="pill pill-active">active</span>
            <?php else: ?>
                <span class="pill pill-neutral">inactive</span>
            <?php endif; ?></td>
            <td class="r nowrap">
                <?php if ($canManage): ?>
                    <form method="post" style="display:inline" action="<?= h(url('/categories.php?action=toggle&type=' . $typeKey)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-sm btn-ghost" type="submit"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="post" style="display:inline"
                          action="<?= h(url('/categories.php?action=delete&type=' . $typeKey)) ?>"
                          onsubmit="return confirm('Delete category &quot;<?= h(addslashes($r['name'])) ?>&quot;?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php render_cat_tree($id, $byParent, $canManage, $canDelete, $typeKey);
    endforeach;
}

$page_title  = 'Categories';
$page_module = 'categories';
$focus_id    = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Categories</h1>
        <p class="muted">Per-module hierarchical category lists ·
            <a href="<?= h(url('/categories.php?type=' . $typeKey . '&dt_sort=name')) ?>">switch to flat list (sort/search)</a></p>
    </div>
    <div class="head-actions">
        <?php if ($canManage): ?>
            <a class="btn btn-ghost" href="<?= h(url('/categories.php?action=export&type=' . $typeKey)) ?>" title="Download categories as CSV">⤓ Export CSV</a>
            <button type="button" class="btn btn-ghost"
                    data-open-import="category-import-modal"
                    title="Import categories from CSV">⤒ Import CSV</button>
            <a class="btn btn-primary" href="<?= h(url('/categories.php?action=new&type=' . $typeKey)) ?>"
               data-shortcut="N" accesskey="n"><?= shortcut_label('+ New category', 'N') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="tabs" style="margin-bottom: 16px;">
    <?php $i = 0; foreach ($TYPES as $k => $label):
        $letter = strtoupper(substr($label, 0, 1));
        $i++; ?>
        <a class="tab <?= $k === $typeKey ? 'active' : '' ?>"
           href="<?= h(url('/categories.php?type=' . $k)) ?>"
           data-shortcut="<?= h($letter) ?>"
           tabindex="<?= $i ?>">
            <?= shortcut_label($label, $letter) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= h($TYPES[$typeKey]) ?> categories</h2>
        <span class="muted small"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span>
    </div>
    <table class="data-table">
        <thead>
        <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Notes</th>
            <th class="r">Order</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="empty">No categories in this type yet.</td></tr>
        <?php else: render_cat_tree(0, $byParent, $canManage, $canDelete, $typeKey); endif; ?>
        </tbody>
    </table>
</div>

<?php if ($canManage):
    require_once __DIR__ . '/includes/_import.php';
    // Custom modal — includes the type selector not present in the generic helper.
    ?>
    <div id="category-import-modal" class="att-preview-modal" hidden>
        <div class="att-preview-backdrop" data-import-close></div>
        <div class="att-preview-dialog" role="dialog" aria-label="Import categories from CSV"
             style="max-width:540px;margin:auto;height:auto;">
            <div class="att-preview-head">
                <span class="att-preview-name">Import categories from CSV</span>
                <button type="button" class="btn btn-icon att-preview-close-btn" data-import-close title="Close">✕</button>
            </div>
            <form method="post" action="<?= h(url('/categories.php?action=import_preview')) ?>"
                  enctype="multipart/form-data" style="padding:18px;">
                <?= csrf_field() ?>
                <div class="field">
                    <label>Category type *</label>
                    <select name="import_type" class="no-combobox">
                        <?php foreach ($TYPES as $k => $label): ?>
                            <option value="<?= h($k) ?>" <?= $k === $typeKey ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-drop-zone="csv-import" style="margin-top:12px;">
                    <label>CSV file * <span class="muted small">(or drag onto this area)</span></label>
                    <input name="csv" type="file" accept=".csv,text/csv" required>
                </div>
                <div class="muted small" style="margin-top:10px;">
                    One category name per line. No header row needed. Blank lines and "." entries are
                    skipped. Names already present in the chosen type are skipped automatically.
                    Codes are auto-generated from the name.
                </div>
                <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-import-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Preview</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var modal = document.getElementById('category-import-modal');
        if (!modal) return;
        document.querySelectorAll('[data-open-import="category-import-modal"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                modal.hidden = false;
                document.body.classList.add('att-preview-modal-open');
            });
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-import-close]')) {
                if (modal.contains(e.target)) {
                    modal.hidden = true;
                    document.body.classList.remove('att-preview-modal-open');
                }
            }
        });
    })();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
