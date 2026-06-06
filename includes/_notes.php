<?php
/**
 * MagDyn — Running notes
 * Created: 20260516_180000_IST
 * Updated: 20260516_190000_IST — per-category permissions, popup mode
 *
 * Public functions:
 *
 *   notes_render($entityType, $entityId, $mode = 'inline')
 *       Emits the notes section. $mode is 'inline' (full section) or
 *       'modal' (the content placed inside a modal body — without the
 *       outer .form-section wrapper). Pass one of:
 *           'asset', 'asset_txn', 'inv_item', 'inv_txn'
 *
 *   notes_popup_button($entityType, $entityId, $label = 'Notes')
 *       Returns an HTML button that, when clicked, fetches the notes
 *       modal content for this entity and shows it. Pair with
 *       notes_popup_assets() emitted once near the bottom of the page.
 *
 *   notes_popup_assets()
 *       Emits the modal scaffold + JS + CSS hooks needed by the popup
 *       buttons. Call exactly once per page (typically just before
 *       footer.php).
 *
 *   notes_handle_action()
 *       Called from any host page (or the dedicated modal endpoint)
 *       that wants to handle note POSTs. Returns true if a note action
 *       was handled (host should redirect or exit), false otherwise.
 *
 *   notes_sync_category_permissions()
 *       Ensures every running_notes category has corresponding
 *       note_cat_<code> modules with view/manage permissions. Idempotent.
 *       Call this from the categories admin's save action.
 *
 * Per-category permissions: each note category gets its own permission
 * module (`note_cat_<code>`). Users see only notes in categories they
 * have `view` on; they can post/edit only in categories they have
 * `manage` on. The host module permission (asset.manage / inventory_…
 * .manage) is still required as a baseline.
 */

/**
 * Map entity_type → (module, manage_action) for permission checks.
 * "Manage" = can add / edit / delete notes on this entity type.
 */
function _notes_permission_for($entityType)
{
    static $map = [
        'asset'      => ['asset',                'manage'],
        'asset_txn'  => ['asset',                'transact'],
        'inv_item'   => ['inventory_view_items', 'manage'],
        'inv_txn'    => ['inventory_view_items', 'manage'],
        // Inspection notes: anyone who can execute an inspection (the
        // inspector role) can add/edit notes on it. View-only users
        // can still read notes thanks to the per-category view check.
        'inspection' => ['inspection',           'execute'],
        // Inspection-template notes: anyone with inspection.create can
        // manage notes on a template (the same gate that lets them
        // create or edit the template itself). The bubble-tool flow
        // auto-drops the annotated PDF here so it surfaces in Running
        // Notes.
        'inspection_template' => ['inspection',  'create'],
        // Document notes: handled specially by notes_can_manage() —
        // either documents_internal.manage OR documents_external.manage
        // is enough. The single-tuple form here picks documents_internal
        // as the lookup default for any code path that bypasses the
        // OR logic below.
        'document'   => ['documents_internal',   'manage'],
    ];
    return $map[$entityType] ?? null;
}

/**
 * Check whether the current user can manage notes on this entity.
 * Returns true/false, never throws.
 *
 * Special case: 'document' entities accept either documents_internal.manage
 * or documents_external.manage, because the two kinds split the permission
 * set but a user managing either kind should be able to add notes.
 */
function notes_can_manage($entityType)
{
    if ($entityType === 'document') {
        return permission_check('documents_internal', 'manage')
            || permission_check('documents_external', 'manage');
    }
    $p = _notes_permission_for($entityType);
    if (!$p) return false;
    return permission_check($p[0], $p[1]);
}

/**
 * Per-category permission helpers.
 * Each running_notes category has a module `note_cat_<code>` with
 * view + manage permissions. A user can view notes in a category if
 * they have note_cat_<code>.view (or no such module exists — fail
 * open for legacy data without the migration applied). Same logic
 * for manage.
 */
function notes_can_view_category($code)
{
    if (!$code) return true; // notes with no category are visible to anyone with host-module access
    $modCode = 'note_cat_' . $code;
    // permission_check returns false if module doesn't exist or user
    // lacks the permission. We want "fail open" when the per-category
    // module doesn't exist yet (e.g. fresh category not synced) — so
    // we check existence ourselves and only enforce when present.
    $modExists = (bool)db_one('SELECT 1 FROM modules WHERE code = ?', [$modCode]);
    if (!$modExists) return true;
    return permission_check($modCode, 'view');
}
function notes_can_manage_category($code)
{
    if (!$code) return true;
    $modCode = 'note_cat_' . $code;
    $modExists = (bool)db_one('SELECT 1 FROM modules WHERE code = ?', [$modCode]);
    if (!$modExists) return true;
    return permission_check($modCode, 'manage');
}

/**
 * Returns the list of categories the current user can VIEW. Used to
 * filter the notes list query. Returns [['id'=>..,'name'=>..,'code'=>..]]
 * sorted by sort_order.
 */
function notes_viewable_categories()
{
    $all = db_all(
        "SELECT id, name, code, sort_order FROM categories
          WHERE type = 'running_notes' AND is_active = 1
          ORDER BY sort_order, name"
    );
    $out = [];
    foreach ($all as $c) {
        if (notes_can_view_category($c['code'])) $out[] = $c;
    }
    return $out;
}
function notes_manageable_categories()
{
    $all = db_all(
        "SELECT id, name, code, sort_order FROM categories
          WHERE type = 'running_notes' AND is_active = 1
          ORDER BY sort_order, name"
    );
    $out = [];
    foreach ($all as $c) {
        if (notes_can_manage_category($c['code'])) $out[] = $c;
    }
    return $out;
}

/**
 * Returns array of category IDs the current user can view, suitable for
 * an IN(...) clause. Includes NULL-handling: notes with note_type_id
 * NULL are always visible (no category, no per-category restriction).
 */
function notes_viewable_category_ids()
{
    return array_map(function ($c) { return (int)$c['id']; }, notes_viewable_categories());
}

/**
 * Ensure every running_notes category has matching note_cat_<code>
 * permission modules + view/manage permissions. Idempotent. Call this
 * after adding/editing a running_notes category in the admin UI.
 */
function notes_sync_category_permissions()
{
    $cats = db_all("SELECT id, name, code FROM categories WHERE type = 'running_notes' AND is_active = 1");
    foreach ($cats as $c) {
        $modCode = 'note_cat_' . $c['code'];
        $exists = db_one('SELECT id FROM modules WHERE code = ?', [$modCode]);
        if (!$exists) {
            db_exec(
                "INSERT INTO modules (code, name, description, sort_order, is_active)
                 VALUES (?, ?, ?, 9000, 1)",
                [$modCode, 'Note category: ' . $c['name'], 'View/manage notes in the "' . $c['name'] . '" category']
            );
            $modId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        } else {
            $modId = (int)$exists['id'];
        }
        foreach (['view' => 'View ', 'manage' => 'Manage '] as $pc => $prefix) {
            $pExists = db_one('SELECT id FROM permissions WHERE module_id = ? AND code = ?', [$modId, $pc]);
            if (!$pExists) {
                db_exec(
                    'INSERT INTO permissions (module_id, code, name) VALUES (?, ?, ?)',
                    [$modId, $pc, $prefix . 'Note category: ' . $c['name']]
                );
            }
        }
    }
}

/**
 * Allow-list HTML sanitizer for Quill output. We accept only the tags
 * Quill produces by default with our toolbar config, and only safe
 * attributes. Anything else is stripped. NEVER store unsanitized HTML
 * — it's a textbook XSS vector when rendered back to other users.
 *
 * Tags allowed:
 *   p br strong em u s a ul ol li blockquote code pre h1 h2 h3
 * Attributes allowed:
 *   href (a only — must be http/https/mailto)
 * <a> tags get rel="noopener noreferrer" forced on output.
 */
function notes_sanitize_html($html)
{
    $allowedTags = '<p><br><strong><em><u><s><a><ul><ol><li><blockquote><code><pre><h1><h2><h3>';
    // Strip everything except allowed tags first
    $clean = strip_tags((string)$html, $allowedTags);

    // Remove inline event handlers and style attributes from any
    // remaining tags. Iterates because nested matches can hide.
    // Pattern: in any tag, remove on*="..." and style="..." attributes.
    $clean = preg_replace('/\s+(on\w+|style|class|id)\s*=\s*"[^"]*"/i', '', $clean);
    $clean = preg_replace("/\s+(on\w+|style|class|id)\s*=\s*'[^']*'/i", '', $clean);

    // For <a> tags: keep only safe href schemes, force rel.
    $clean = preg_replace_callback('/<a\b([^>]*)>/i', function ($m) {
        $attrs = $m[1];
        $href = '';
        if (preg_match('/\bhref\s*=\s*"([^"]*)"/i', $attrs, $hm)) $href = $hm[1];
        elseif (preg_match("/\bhref\s*=\s*'([^']*)'/i", $attrs, $hm)) $href = $hm[1];
        // Only http/https/mailto. Anything else (including javascript:)
        // drops the href entirely.
        if (!preg_match('#^(https?:|mailto:)#i', $href)) $href = '';
        if ($href === '') return '<a>';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" rel="noopener noreferrer" target="_blank">';
    }, $clean);

    // Empty-ish content guard. Quill's empty document is "<p><br></p>".
    if (trim(strip_tags($clean)) === '' && strpos($clean, '<img') === false) {
        return '';
    }
    return $clean;
}

/**
 * Resolve the uploads base directory (filesystem path), creating it on
 * first use. Returns null if it can't be created.
 */
function _notes_uploads_base()
{
    $base = dirname(__DIR__) . '/uploads/notes';
    if (!is_dir($base)) {
        if (!@mkdir($base, 0775, true)) return null;
    }
    return $base;
}

/**
 * Persist an uploaded file to disk under uploads/notes/YYYY/MM/.
 * Returns array [stored_path, filename, mime, size] or null on error.
 * stored_path is relative to the uploads base (no leading slash).
 */
function _notes_store_upload($file)
{
    if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) return null;
    $maxBytes = 10 * 1024 * 1024; // 10 MB
    if ((int)$file['size'] > $maxBytes) return null;

    $base = _notes_uploads_base();
    if (!$base) return null;
    $sub = date('Y/m');
    $dir = $base . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;

    // Generate a safe stored filename: hex prefix + sanitized original
    // name. Hex avoids collisions; original-as-suffix keeps the
    // filesystem listing human-scannable.
    $origName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$file['name']);
    if ($origName === '' || $origName === '.') $origName = 'file';
    $hex = bin2hex(random_bytes(8));
    $stored = $sub . '/' . $hex . '_' . $origName;
    $dest   = $dir . '/' . $hex . '_' . $origName;

    if (!@move_uploaded_file($file['tmp_name'], $dest)) return null;

    // Detect mime defensively. The browser-provided type is unreliable.
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)finfo_file($fi, $dest); finfo_close($fi); }
    }
    if ($mime === '') $mime = (string)$file['type']; // fallback

    return [
        'stored_path' => $stored,
        'filename'    => $origName,
        'mime'        => $mime,
        'size'        => (int)$file['size'],
    ];
}

/**
 * Render the running-notes section for a host page. Emits the composer
 * and the existing notes list.
 *
 * $mode: 'inline' (default — full .form-section) or 'modal' (content
 *        only, without the section heading or outer wrapper, suitable
 *        for placing inside a modal body).
 */
function notes_render($entityType, $entityId, $mode = 'inline', $returnTo = null)
{
    $canManage = notes_can_manage($entityType);
    $entityId = (int)$entityId;

    // Where to redirect after a save/redact/delete/unredact. The host
    // page passes its own URL so the user lands back where they were.
    // Falls back to the request URI if not specified, then to the
    // running notes list as a final guard.
    if ($returnTo === null) {
        $returnTo = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    }
    $returnTo = (string)$returnTo;

    // Build per-category visibility WHERE clause: include notes whose
    // category is viewable OR notes with no category set.
    $viewableIds = notes_viewable_category_ids();
    $inClause = '';
    if (!empty($viewableIds)) {
        $inClause = ' AND (n.note_type_id IS NULL OR n.note_type_id IN (' . implode(',', $viewableIds) . '))';
    } else {
        // No viewable categories: still show uncategorised notes
        $inClause = ' AND n.note_type_id IS NULL';
    }

    // Load existing notes (excluding soft-deleted) newest-first.
    $notes = db_all(
        "SELECT n.*, u.full_name AS author_name, u.email AS author_email,
                c.name AS note_type_name, c.code AS note_type_code,
                ru.full_name AS redactor_name, ru.email AS redactor_email
           FROM notes n
           LEFT JOIN users u      ON u.id  = n.author_id
           LEFT JOIN categories c ON c.id  = n.note_type_id
           LEFT JOIN users ru     ON ru.id = n.redacted_by
          WHERE n.entity_type = ? AND n.entity_id = ? AND n.is_deleted = 0"
        . $inClause . "
          ORDER BY n.created_at DESC, n.id DESC",
        [$entityType, $entityId]
    );

    // Group attachments by note_id for the rows we just loaded.
    $attByNote = [];
    if ($notes) {
        $ids = array_map(function ($n) { return (int)$n['id']; }, $notes);
        $in = implode(',', $ids);
        foreach (db_all("SELECT * FROM note_attachments WHERE note_id IN ($in) ORDER BY id") as $a) {
            $attByNote[(int)$a['note_id']][] = $a;
        }
    }

    // Note types — only those the user can MANAGE (post/edit).
    $types = notes_manageable_categories();

    $currentUid = current_user_id();
    $postUrl = h(notes_endpoint_for($entityType, $entityId));

    $isModal = $mode === 'modal';
    if (!$isModal) { ?>
    <div class="form-section notes-section" data-entity-type="<?= h($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
        <h2>Running notes <span class="muted small" style="font-weight: normal;">(<?= count($notes) ?>)</span></h2>
    <?php } else { ?>
    <div class="notes-section notes-section-modal" data-entity-type="<?= h($entityType) ?>" data-entity-id="<?= (int)$entityId ?>">
    <?php } ?>

        <?php if ($canManage): ?>
            <div class="notes-composer-toggle-row">
                <button type="button" class="btn btn-primary btn-sm notes-composer-toggle"
                        title="Add a new note">+ Add note</button>
            </div>
            <form class="notes-composer" method="post" action="<?= $postUrl ?>" enctype="multipart/form-data"
                  data-drop-zone="notes-composer" hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="note_action" value="save">
                <input type="hidden" name="entity_type" value="<?= h($entityType) ?>">
                <input type="hidden" name="entity_id"   value="<?= (int)$entityId ?>">
                <input type="hidden" name="edit_id"     value="" class="notes-edit-id">
                <input type="hidden" name="return_to"   value="<?= h($returnTo) ?>">

                <div class="notes-composer-row">
                    <select name="note_type_id" class="no-combobox notes-type-select">
                        <option value="">— Type —</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="btn btn-ghost btn-sm notes-attach-btn" title="Attach files">
                        📎 Attach
                        <input type="file" name="attachments[]" multiple style="display: none;" class="notes-attach-input">
                    </label>
                    <span class="muted small notes-attach-preview"></span>
                </div>

                <!-- Quill mounts here on first reveal of the composer.
                     body_html mirrors back into the hidden input on submit. -->
                <div class="notes-editor"></div>
                <input type="hidden" name="body_html" class="notes-body-input">

                <div class="notes-composer-actions">
                    <button type="submit" class="btn btn-primary btn-sm notes-submit-btn">Add note</button>
                    <button type="button" class="btn btn-ghost btn-sm notes-cancel-btn">Cancel</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="notes-list">
            <?php if ($canManage && !empty($notes) && !$isModal): ?>
                <!--
                  Secondary "Add note" toggle — only in inline mode.
                  In a popup modal the primary button at the top is always
                  visible, so a second one here would appear duplicated.
                -->
                <div class="notes-list-add-row" style="margin-bottom: 10px;">
                    <button type="button" class="btn btn-ghost btn-sm notes-composer-toggle"
                            title="Add another note">+ Add note</button>
                </div>
            <?php endif; ?>
            <?php if (!$notes): ?>
                <p class="muted empty" style="text-align: left; padding: 14px 0;">No notes yet<?= $canManage ? ' — add the first one above.' : '.' ?></p>
            <?php endif; ?>
            <?php foreach ($notes as $n):
                $atts = $attByNote[(int)$n['id']] ?? [];
                $isAuthor = (int)$n['author_id'] === (int)$currentUid;
                $canManageThis = $canManage && notes_can_manage_category($n['note_type_code']);
                $isRedacted = !empty($n['redacted_at']);
                // Mgmt rights on this row: author or someone with the host
                // module's manage perm (same check used for the action gates
                // below). Computed once and reused.
                $canActOnThis = $canManageThis && ($isAuthor || notes_can_manage($entityType));
                $canRestore = $isRedacted && permission_check('running_notes', 'manage');
            ?>
                <article class="note-item<?= $isRedacted ? ' note-item-redacted' : '' ?>" data-note-id="<?= (int)$n['id'] ?>">
                    <header class="note-head">
                        <strong class="note-author"><?= h($n['author_name'] ?: $n['author_email']) ?></strong>
                        <span class="muted small note-when"
                              title="<?= h($n['created_at']) ?>"><?= h($n['created_at']) ?></span>
                        <?php if ($n['note_type_name']): ?>
                            <span class="pill pill-info note-type-pill"><?= h($n['note_type_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($n['edited_at'] && !$isRedacted): ?>
                            <span class="muted small">· edited <?= h($n['edited_at']) ?></span>
                        <?php endif; ?>
                        <?php if ($isRedacted): ?>
                            <span class="pill pill-warn note-redacted-pill" title="Redacted">REDACTED</span>
                        <?php endif; ?>
                        <span class="note-actions">
                            <?php if ($canActOnThis && !$isRedacted): ?>
                                <button type="button" class="btn btn-icon notes-edit-btn"
                                        data-note-id="<?= (int)$n['id'] ?>"
                                        data-body="<?= h($n['body_html']) ?>"
                                        data-type-id="<?= (int)$n['note_type_id'] ?>"
                                        title="Edit">✎</button>
                                <form method="post" action="<?= $postUrl ?>" style="display:inline"
                                      onsubmit="return confirm('Redact this note? The body will be replaced with a redaction notice; the original is preserved in the audit log.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="note_action" value="redact">
                                    <input type="hidden" name="entity_type" value="<?= h($entityType) ?>">
                                    <input type="hidden" name="entity_id"   value="<?= (int)$entityId ?>">
                                    <input type="hidden" name="note_id"     value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="return_to"   value="<?= h($returnTo) ?>">
                                    <button class="btn btn-icon" type="submit" title="Redact">🚫</button>
                                </form>
                                <form method="post" action="<?= $postUrl ?>" style="display:inline"
                                      onsubmit="return confirm('Delete this note? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="note_action" value="delete">
                                    <input type="hidden" name="entity_type" value="<?= h($entityType) ?>">
                                    <input type="hidden" name="entity_id"   value="<?= (int)$entityId ?>">
                                    <input type="hidden" name="note_id"     value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="return_to"   value="<?= h($returnTo) ?>">
                                    <button class="btn btn-icon" type="submit" title="Delete">🗑</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canRestore): ?>
                                <form method="post" action="<?= $postUrl ?>" style="display:inline"
                                      onsubmit="return confirm('Restore this redacted note? Its original body will be visible again.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="note_action" value="unredact">
                                    <input type="hidden" name="entity_type" value="<?= h($entityType) ?>">
                                    <input type="hidden" name="entity_id"   value="<?= (int)$entityId ?>">
                                    <input type="hidden" name="note_id"     value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="return_to"   value="<?= h($returnTo) ?>">
                                    <button class="btn btn-icon" type="submit" title="Restore">↩</button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </header>
                    <?php if ($isRedacted): ?>
                        <div class="note-body note-body-redacted">
                            <em>[Redacted by <?= h($n['redactor_name'] ?: $n['redactor_email'] ?: 'unknown') ?>
                                on <?= h($n['redacted_at']) ?>]</em>
                        </div>
                    <?php else: ?>
                        <div class="note-body"><?= $n['body_html'] /* sanitized at save */ ?></div>
                    <?php endif; ?>
                    <?php if ($atts && !$isRedacted): ?>
                        <?php
                            // CMM Analyze button — only for PDFs, only when user
                            // can upload CMM runs. Auto-link to inv_txn when the
                            // note is on an inv_txn AND the user can link.
                            $canCmmUpload = permission_check('cmm', 'upload');
                            $cmmLinkTxnId = ($canCmmUpload
                                             && $entityType === 'inv_txn'
                                             && permission_check('cmm', 'link'))
                                          ? (int)$entityId : 0;
                        ?>
                        <div class="note-attachments">
                            <?php foreach ($atts as $a): ?>
                                <a class="note-attachment"
                                   href="<?= h(url('/note_attach.php?id=' . (int)$a['id'])) ?>"
                                   title="<?= h($a['filename']) ?> · <?= number_format((int)$a['size_bytes'] / 1024, 1) ?> KB">
                                    📎 <?= h($a['filename']) ?>
                                    <span class="muted small">(<?= number_format((int)$a['size_bytes'] / 1024, 1) ?> KB)</span>
                                </a>
                                <?php
                                    $isPdf = preg_match('/\.pdf$/i', (string)$a['filename']);
                                    if ($isPdf && $canCmmUpload):
                                        $cmmUrl = url('/cmm.php?attachment_id=' . (int)$a['id']
                                            . ($cmmLinkTxnId ? '&link_txn_id=' . $cmmLinkTxnId : ''));
                                ?>
                                    <a class="btn btn-ghost btn-xs"
                                       href="<?= h($cmmUrl) ?>"
                                       style="margin-left: 4px;"
                                       title="Analyze this PDF in the CMM Analyzer<?= $cmmLinkTxnId ? ' (auto-link to this txn)' : '' ?>">
                                        📐 CMM Analyze
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($atts && $isRedacted): ?>
                        <div class="note-attachments-redacted muted small">
                            <em><?= count($atts) ?> attachment<?= count($atts) === 1 ? '' : 's' ?> hidden (note redacted)</em>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

    <?php // Load Quill (vendored). The CSS is appended once via <link>;
          // the JS once via <script>. Both files must exist at the paths
          // below — see app docs for the upload step.
          static $quillLoaded = false;
          if (!$quillLoaded && $canManage): $quillLoaded = true; ?>
        <link rel="stylesheet" href="<?= h(asset_url('/assets/css/vendor/quill.snow.css')) ?>">
        <script src="<?= h(asset_url('/assets/js/vendor/quill.min.js')) ?>"></script>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <script>
    (function () {
        // We can't rely on document.currentScript because this script is
        // re-created by the modal opener (innerHTML doesn't run <script>,
        // so the modal-opener JS recreates each <script> via createElement
        // — and currentScript is null for those). Instead, locate our
        // section by entity_type + entity_id, which PHP inlined below.
        // Match either the inline section OR the modal section.
        var entType = <?= json_encode((string)$entityType) ?>;
        var entId   = <?= json_encode((string)$entityId) ?>;
        var sections = document.querySelectorAll(
            '.notes-section[data-entity-type="' + entType + '"][data-entity-id="' + entId + '"]'
        );
        // Prefer the modal section if it exists (it's the freshest render).
        var section = null;
        for (var i = sections.length - 1; i >= 0; i--) {
            if (sections[i].classList.contains('notes-section-modal')) { section = sections[i]; break; }
        }
        if (!section && sections.length) section = sections[sections.length - 1];
        if (!section) return;
        var form        = section.querySelector('.notes-composer');
        // There may be multiple `.notes-composer-toggle` buttons in the
        // section (e.g. one above the list and one inside the list, so
        // users on long pages don't have to scroll to find it). Wire
        // them all to the same show-composer handler.
        var toggleBtns  = section.querySelectorAll('.notes-composer-toggle');
        var toggleBtn   = toggleBtns[0];  // for old single-button code paths
        if (!form || !toggleBtns.length) return;
        var editorEl    = section.querySelector('.notes-editor');
        var bodyInp     = section.querySelector('.notes-body-input');
        var editIdEl    = section.querySelector('.notes-edit-id');
        var submitBtn   = section.querySelector('.notes-submit-btn');
        var cancelBtn   = section.querySelector('.notes-cancel-btn');
        var attInput    = section.querySelector('.notes-attach-input');
        var attLabel    = section.querySelector('.notes-attach-preview');
        var typeSel     = section.querySelector('.notes-type-select');

        // Quill is lazy-mounted on first reveal of the composer. This
        // keeps the initial modal open fast: most "view notes" clicks
        // don't need the ~200KB editor library to parse and bind.
        var quill = null;

        function ensureQuill() {
            if (quill) return quill;
            if (typeof Quill === 'undefined') {
                editorEl.innerHTML =
                    '<p style="color:#b91c1c;font-size:12px;">Quill editor not loaded. ' +
                    'Ensure /assets/js/vendor/quill.min.js and /assets/css/vendor/quill.snow.css exist.</p>';
                return null;
            }
            quill = new Quill(editorEl, {
                theme: 'snow',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['link', 'blockquote', 'code-block'],
                        [{ header: [1, 2, 3, false] }],
                        ['clean']
                    ]
                },
                placeholder: 'Write a note…'
            });
            return quill;
        }

        function setTogglesHidden(hidden) {
            Array.prototype.forEach.call(toggleBtns, function (b) { b.hidden = hidden; });
        }

        function showComposer() {
            form.hidden = false;
            setTogglesHidden(true);
            var q = ensureQuill();
            if (q) {
                // Give Quill's contenteditable a tick to layout before
                // focusing, otherwise the cursor placement can jump.
                setTimeout(function () { q.focus(); }, 0);
            }
        }
        function hideComposer() {
            form.hidden = true;
            setTogglesHidden(false);
            // Reset composer state
            editIdEl.value = '';
            typeSel.value = '';
            attInput.value = '';
            attLabel.textContent = '';
            if (quill) quill.setText('');
            submitBtn.textContent = 'Add note';
        }

        Array.prototype.forEach.call(toggleBtns, function (btn) {
            btn.addEventListener('click', showComposer);
        });
        cancelBtn.addEventListener('click', hideComposer);

        form.addEventListener('submit', function (e) {
            // Quill must be mounted to have a body. If it's not (user
            // somehow submits before reveal), guard.
            if (!quill) {
                e.preventDefault();
                return;
            }
            bodyInp.value = quill.root.innerHTML;

            // Inside the popup modal, submit via AJAX so the modal STAYS
            // OPEN and refreshes in place. A normal POST would navigate the
            // host page and close the modal.
            var modalBody = document.querySelector('.notes-modal-body');
            if (section.classList.contains('notes-section-modal') && modalBody && window.fetch && window.FormData) {
                e.preventDefault();
                var hasBody = quill.getText().trim() !== '';
                var hasFile = attInput && attInput.files && attInput.files.length > 0;
                if (!hasBody && !hasFile) { return; }   // nothing to save
                var fd = new FormData(form);
                fd.append('ajax', '1');
                if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }
                fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        modalBody.innerHTML = html;
                        // innerHTML doesn't run <script>; re-create them so the
                        // fresh composer + Quill re-initialise inside the modal.
                        modalBody.querySelectorAll('script').forEach(function (oldS) {
                            var s = document.createElement('script');
                            if (oldS.src) s.src = oldS.src; else s.text = oldS.textContent;
                            oldS.parentNode.replaceChild(s, oldS);
                        });
                    })
                    .catch(function () {
                        // Network/parse failure → fall back to a normal submit.
                        if (submitBtn) submitBtn.disabled = false;
                        form.submit();
                    });
            }
        });

        attInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(attInput.files || []);
            if (!files.length) { attLabel.textContent = ''; return; }
            attLabel.textContent = files.map(function (f) {
                return f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
            }).join(', ');
        });

        // Edit-note button: reveal composer, ensure Quill, pre-fill.
        section.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.notes-edit-btn');
            if (!btn) return;
            e.preventDefault();
            showComposer();
            var q = ensureQuill();
            if (!q) return;
            editIdEl.value = btn.getAttribute('data-note-id');
            q.root.innerHTML = btn.getAttribute('data-body') || '';
            typeSel.value = btn.getAttribute('data-type-id') || '';
            submitBtn.textContent = 'Save changes';
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            q.focus();
        });
    })();
    </script>
    <?php endif;
}

/**
 * Resolve the URL the composer should POST to for this entity. We just
 * post back to the same page with a note_action param; the host page
 * calls notes_handle_action() near the top.
 */
function notes_endpoint_for($entityType, $entityId)
{
    // All note write actions (save/redact/delete/unredact) are handled
    // by running_notes.php so we have ONE place enforcing the state
    // machine + redirect behaviour. The host page passes the user's
    // current URL as `return_to` so we land back where the user was
    // (asset view, item edit, txn list, etc.) after the action.
    return url('/running_notes.php?action=save');
}

/**
 * Process a note action posted to the host page. Returns true if a note
 * action was handled (host should redirect or exit), false otherwise.
 *
 * Recognises:
 *   note_action=save   — create or update (with edit_id present)
 *   note_action=delete — soft-delete a note
 */
function notes_handle_action()
{
    $action = (string)input('note_action', '');
    if ($action === '') return false;
    csrf_check();

    $entityType = (string)input('entity_type', '');
    $entityId   = (int)input('entity_id', 0);
    if (!_notes_permission_for($entityType) || $entityId <= 0) {
        flash_set('error', 'Invalid note target.');
        return true;
    }
    $perm = _notes_permission_for($entityType);
    require_permission($perm[0], $perm[1]);

    if ($action === 'save') {
        $editId   = (int)input('edit_id', 0);
        $typeId   = (int)input('note_type_id', 0) ?: null;

        // Per-category manage check: the user must have manage on the
        // chosen category (or the category is null/missing — public).
        if ($typeId) {
            $cat = db_one('SELECT code FROM categories WHERE id = ?', [$typeId]);
            if ($cat && !notes_can_manage_category($cat['code'])) {
                flash_set('error', 'You don\'t have permission to post in that note category.');
                return true;
            }
        }

        $body     = notes_sanitize_html((string)input('body_html', ''));
        if ($body === '' && empty($_FILES['attachments']['name'][0])) {
            flash_set('error', 'Note body or at least one attachment required.');
            return true;
        }
        $uid = (int)current_user_id();

        if ($editId > 0) {
            // Update existing note (must belong to this entity).
            $existing = db_one(
                'SELECT * FROM notes WHERE id = ? AND entity_type = ? AND entity_id = ? AND is_deleted = 0',
                [$editId, $entityType, $entityId]
            );
            if (!$existing) { flash_set('error', 'Note not found.'); return true; }
            db_exec(
                'UPDATE notes SET body_html = ?, note_type_id = ?, edited_at = NOW(), edited_by = ? WHERE id = ?',
                [$body, $typeId, $uid, $editId]
            );
            $noteId = $editId;
        } else {
            db_exec(
                'INSERT INTO notes (entity_type, entity_id, note_type_id, body_html, author_id) VALUES (?, ?, ?, ?, ?)',
                [$entityType, $entityId, $typeId, $body, $uid]
            );
            $noteId = (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
        }

        // Handle attachments — uploaded as $_FILES['attachments'].
        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $files = $_FILES['attachments'];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i])) continue;
                $one = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $stored = _notes_store_upload($one);
                if (!$stored) {
                    flash_set('error', 'Failed to store attachment "' . htmlspecialchars($one['name']) . '" (max 10 MB).');
                    continue;
                }
                db_exec(
                    'INSERT INTO note_attachments (note_id, filename, stored_path, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)',
                    [$noteId, $stored['filename'], $stored['stored_path'], $stored['mime'], $stored['size'], $uid]
                );
            }
        }

        flash_set('success', $editId ? 'Note updated.' : 'Note added.');
        return true;
    }

    if ($action === 'delete') {
        $noteId = (int)input('note_id', 0);
        $note = db_one(
            'SELECT * FROM notes WHERE id = ? AND entity_type = ? AND entity_id = ?',
            [$noteId, $entityType, $entityId]
        );
        if (!$note) { flash_set('error', 'Note not found.'); return true; }
        // Author can always delete their own; otherwise need manage perm.
        if ((int)$note['author_id'] !== (int)current_user_id()) {
            // Already have manage perm via require_permission above.
        }
        db_exec('UPDATE notes SET is_deleted = 1 WHERE id = ?', [$noteId]);
        flash_set('success', 'Note deleted.');
        return true;
    }

    if ($action === 'redact') {
        // Mark the note as redacted. The original body_html is preserved
        // in the DB for audit; the display layer substitutes the redacted
        // notice. Either the author OR anyone with the host module's
        // manage perm (which the require_permission above already enforced)
        // can redact — same gate as delete.
        $noteId = (int)input('note_id', 0);
        $note = db_one(
            'SELECT * FROM notes WHERE id = ? AND entity_type = ? AND entity_id = ?',
            [$noteId, $entityType, $entityId]
        );
        if (!$note) { flash_set('error', 'Note not found.'); return true; }
        if ($note['redacted_at']) { flash_set('error', 'Note already redacted.'); return true; }
        db_exec(
            'UPDATE notes SET redacted_at = NOW(), redacted_by = ? WHERE id = ?',
            [(int)current_user_id(), $noteId]
        );
        flash_set('success', 'Note redacted.');
        return true;
    }

    if ($action === 'unredact') {
        // Restore a redacted note. Admin-only — requires the
        // running_notes.manage permission. Authors cannot un-redact their
        // own notes; this prevents redaction from being treated as a
        // toggle and keeps the audit semantics clean.
        if (!permission_check('running_notes', 'manage')) {
            flash_set('error', 'Only administrators can restore redacted notes.');
            return true;
        }
        $noteId = (int)input('note_id', 0);
        $note = db_one(
            'SELECT * FROM notes WHERE id = ? AND entity_type = ? AND entity_id = ?',
            [$noteId, $entityType, $entityId]
        );
        if (!$note) { flash_set('error', 'Note not found.'); return true; }
        if (!$note['redacted_at']) { flash_set('error', 'Note is not redacted.'); return true; }
        db_exec(
            'UPDATE notes SET redacted_at = NULL, redacted_by = NULL WHERE id = ?',
            [$noteId]
        );
        flash_set('success', 'Note restored.');
        return true;
    }

    return false;
}

/**
 * Render a "Notes (N)" button that opens the notes modal for this
 * entity. The page must also call notes_popup_assets() once to emit
 * the modal scaffold and JS.
 *
 * The note count is queried inline so the badge stays accurate; for
 * pages displaying many such buttons (a list with one per row), do
 * not call this in a tight loop or the queries add up. For list pages
 * use a batch count helper instead (TODO if/when needed).
 */
function notes_popup_button($entityType, $entityId, $label = 'Notes', $shortcut = '')
{
    $entityId = (int)$entityId;
    $viewableIds = notes_viewable_category_ids();
    $inClause = '';
    if (!empty($viewableIds)) {
        $inClause = ' AND (note_type_id IS NULL OR note_type_id IN (' . implode(',', $viewableIds) . '))';
    } else {
        $inClause = ' AND note_type_id IS NULL';
    }
    $count = (int)db_val(
        "SELECT COUNT(*) FROM notes
          WHERE entity_type = ? AND entity_id = ? AND is_deleted = 0" . $inClause,
        [$entityType, $entityId],
        0
    );
    $countHtml = $count > 0 ? ' <span class="notes-popup-count">(' . $count . ')</span>' : '';

    // Optional keyboard shortcut. When provided, the button registers
    // an accesskey + data-shortcut, matching the convention used by
    // form_toolbar's S/C/E/T buttons. Default empty = no shortcut so
    // unique-letter constraints on list pages aren't disturbed.
    $shortcutAttrs = '';
    $renderedLabel = h($label);
    if ($shortcut !== '') {
        $sc = strtoupper(substr($shortcut, 0, 1));
        $shortcutAttrs = ' data-shortcut="' . h($sc) . '" accesskey="' . h(strtolower($sc)) . '"';
        $renderedLabel = shortcut_label($label, $sc);
    }

    return '<button type="button" class="btn btn-ghost btn-sm notes-popup-btn"'
        . ' data-entity-type="' . h($entityType) . '"'
        . ' data-entity-id="' . $entityId . '"'
        . $shortcutAttrs
        . '>✎ ' . $renderedLabel . $countHtml . '</button>';
}

/**
 * Compact menu-item variant of notes_popup_button() for use INSIDE a
 * data-table row's gear-dropdown actions. Differs from the button:
 *   - Renders as an <a> so the existing .dt-actions-dropdown styles
 *     pick it up as a menu row (left-aligned, full-width)
 *   - Carries the same .notes-popup-btn class so the existing modal
 *     click handler binds to it (the class name's the contract)
 *   - Skips the inline note-count query (would be N+1 across the list;
 *     the count is visible inside the modal once opened)
 *
 * Returns an HTML fragment. Append to your row's $actions string before
 * passing to dt_actions_wrap():
 *   $actions .= notes_popup_menu_item('asset', (int)$row['id']);
 *
 * Don't forget to call notes_popup_assets() once per page to emit the
 * modal scaffold + JS bindings.
 */
function notes_popup_menu_item($entityType, $entityId, $label = 'Notes', $count = null)
{
    $countHtml = '';
    if ($count !== null && (int)$count > 0) {
        $countHtml = ' <span class="notes-popup-count">(' . (int)$count . ')</span>';
    }
    return '<a href="#" class="notes-popup-btn"'
        . ' data-entity-type="' . h($entityType) . '"'
        . ' data-entity-id="' . (int)$entityId . '"'
        . '>📝 ' . h($label) . $countHtml . '</a>';
}

/**
 * Batched note-count lookup. Returns [entity_id => count] for the given
 * entity_type. Use this in list pages to fetch all per-row counts in a
 * single query instead of an N+1 loop. Respects per-category viewability:
 * counts only include notes whose category the current user can view.
 *
 *   $counts = notes_counts_for('inv_txn', array_column($rows, 'id'));
 *   foreach ($rows as $r) {
 *       echo notes_popup_menu_item('inv_txn', $r['id'], 'Notes', $counts[$r['id']] ?? 0);
 *   }
 */
function notes_counts_for($entityType, array $entityIds)
{
    if (!$entityIds) return [];
    $entityIds = array_map('intval', $entityIds);
    $in = implode(',', $entityIds);

    $viewableIds = notes_viewable_category_ids();
    $catClause = '';
    if (!empty($viewableIds)) {
        $catClause = ' AND (note_type_id IS NULL OR note_type_id IN (' . implode(',', $viewableIds) . '))';
    } else {
        $catClause = ' AND note_type_id IS NULL';
    }

    $rows = db_all(
        "SELECT entity_id, COUNT(*) AS c
           FROM notes
          WHERE entity_type = ? AND entity_id IN ($in) AND is_deleted = 0" . $catClause . "
          GROUP BY entity_id",
        [$entityType]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['entity_id']] = (int)$r['c'];
    }
    return $out;
}

/**
 * Emit the modal scaffold + JS hooks. Call once per page (typically
 * just before footer.php). Safe to omit if no notes_popup_button() is
 * on the page — but harmless to include either way.
 */
/**
 * Render a clickable attachment indicator (📎 + count badge) for an entity.
 * Click behaviour is wired by note_att_indicator_assets():
 *   - 0 attachments → a muted dash.
 *   - 1 attachment  → opens it directly.
 *   - >1            → a small popup listing each filename (click to open).
 */
function note_att_indicator($entityType, $entityId, $count)
{
    $count = (int)$count;
    if ($count <= 0) return '<span class="muted small">—</span>';
    return '<button type="button" class="att-indicator"'
         . ' data-entity-type="' . h($entityType) . '" data-entity-id="' . (int)$entityId . '"'
         . ' data-count="' . $count . '"'
         . ' title="' . $count . ' attachment' . ($count === 1 ? '' : 's') . '">'
         . '📎&nbsp;<span class="att-badge">' . $count . '</span></button>';
}

/**
 * Emit the shared popup + click handler for note_att_indicator() once per
 * page. Fetches the attachment list from running_notes.php?action=attachments
 * on click; opens a single attachment directly, or shows a filename popup.
 */
function note_att_indicator_assets()
{
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
        .att-indicator { background:none; border:none; padding:0; cursor:pointer; font:inherit; line-height:1; white-space:nowrap; }
        #att-backdrop {
            position: fixed; inset: 0; z-index: 99998; display: none;
            background: rgba(0,0,0,.35);
        }
        #att-pop {
            position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
            z-index: 99999; display: none;
            background: #ffffff; color: #111827;
            border: 1px solid #d1d5db; border-radius: 10px;
            box-shadow: 0 18px 50px rgba(0,0,0,.30);
            width: min(420px, 92vw); max-height: 70vh; overflow: auto;
        }
        #att-pop .att-pop-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 14px; border-bottom: 1px solid #eef0f3;
            font-size: 13px; font-weight: 700; color: #111827;
            position: sticky; top: 0; background: #fff;
        }
        #att-pop .att-pop-close {
            background: none; border: none; font-size: 18px; line-height: 1;
            cursor: pointer; color: #6b7280; padding: 0 2px;
        }
        #att-pop .att-pop-body { padding: 8px; }
        #att-pop a {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 11px; border-radius: 6px; text-decoration: none;
            color: #1d4ed8; font-size: 13.5px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        #att-pop a:hover { background: #eff6ff; }
        #att-pop .att-pop-empty { padding: 14px; color: #6b7280; font-size: 13px; }
    </style>
    <script>
    (function () {
        if (window.__attIndicatorBound) return;
        window.__attIndicatorBound = true;
        var base = (window.MAGDYN_BASE || '');

        // Build a centered modal popup + backdrop once, anchored on <body>.
        var backdrop = document.getElementById('att-backdrop');
        if (!backdrop) { backdrop = document.createElement('div'); backdrop.id = 'att-backdrop'; document.body.appendChild(backdrop); }
        var pop = document.getElementById('att-pop');
        if (!pop) { pop = document.createElement('div'); pop.id = 'att-pop'; document.body.appendChild(pop); }

        function hide() { pop.style.display = 'none'; backdrop.style.display = 'none'; pop.innerHTML = ''; }
        function render(list) {
            pop.innerHTML = '';
            var head = document.createElement('div');
            head.className = 'att-pop-head';
            var label = document.createElement('span');
            label.textContent = '📎 ' + list.length + ' attachment' + (list.length === 1 ? '' : 's');
            var x = document.createElement('button');
            x.type = 'button'; x.className = 'att-pop-close'; x.textContent = '✕';
            x.addEventListener('click', hide);
            head.appendChild(label); head.appendChild(x);
            pop.appendChild(head);

            var body = document.createElement('div');
            body.className = 'att-pop-body';
            if (!list.length) {
                var em = document.createElement('div');
                em.className = 'att-pop-empty';
                em.textContent = 'No attachments found.';
                body.appendChild(em);
            } else {
                list.forEach(function (a) {
                    var link = document.createElement('a');
                    link.href = base + '/note_attach.php?id=' + a.id;
                    link.title = a.name;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = '📄 ' + a.name;
                    body.appendChild(link);
                });
            }
            pop.appendChild(body);
            backdrop.style.display = 'block';
            pop.style.display = 'block';
        }

        // Capture phase so nothing can swallow the click before us.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.att-indicator');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var et  = btn.getAttribute('data-entity-type');
            var eid = btn.getAttribute('data-entity-id');
            render([]);                       // open immediately with a placeholder
            pop.querySelector('.att-pop-body').innerHTML = '<div class="att-pop-empty">Loading…</div>';
            var url = base + '/running_notes.php?action=attachments'
                    + '&entity_type=' + encodeURIComponent(et)
                    + '&entity_id='   + encodeURIComponent(eid);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (list) { render(Array.isArray(list) ? list : []); })
                .catch(function () {
                    pop.querySelector('.att-pop-body').innerHTML =
                        '<div class="att-pop-empty">Could not load attachments.</div>';
                });
        }, true);

        backdrop.addEventListener('click', hide);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    })();
    </script>
    <?php
}

function notes_popup_assets()
{
    // Always make sure the attachment preview machinery is on the page
    // too — every page that has notes also has attachment links to preview.
    notes_attachment_preview_assets();
    // And the 📎 attachment-indicator popup handler (used by list/txn tables).
    note_att_indicator_assets();

    static $emitted = false;
    if ($emitted) return;
    $emitted = true;
    ?>
    <div id="notes-modal" class="notes-modal" hidden>
        <div class="notes-modal-backdrop" data-notes-modal-close></div>
        <div class="notes-modal-dialog" role="dialog" aria-label="Notes">
            <div class="notes-modal-head">
                <h3 class="notes-modal-title">Notes</h3>
                <button type="button" class="btn btn-icon notes-modal-close-btn" data-notes-modal-close title="Close">✕</button>
            </div>
            <div class="notes-modal-body">
                <p class="muted">Loading…</p>
            </div>
        </div>
    </div>
    <script>
    (function () {
        // Guard: only attach the document-level click/keydown handlers once
        // per browser session. The handlers re-query the modal DOM on every
        // open/close so SPA navigation (which swaps <main> wholesale and
        // emits a fresh #notes-modal each time) doesn't strand stale
        // references in this closure.
        if (window.__notesPopupBound) return;
        window.__notesPopupBound = true;

        function getModal() { return document.getElementById('notes-modal'); }
        function getBody()  { var m = getModal(); return m ? m.querySelector('.notes-modal-body')  : null; }
        function getTitle() { var m = getModal(); return m ? m.querySelector('.notes-modal-title') : null; }

        function open(et, eid) {
            var modal = getModal();
            if (!modal) return;
            var body  = getBody();
            var title = getTitle();
            if (title) title.textContent = 'Notes';
            if (body)  body.innerHTML = '<p class="muted">Loading…</p>';
            modal.hidden = false;
            document.body.classList.add('notes-modal-open');
            var url = (window.MAGDYN_BASE || '') + '/running_notes.php?action=modal'
                    + '&entity_type=' + encodeURIComponent(et)
                    + '&entity_id=' + encodeURIComponent(eid)
                    + '&return_to=' + encodeURIComponent(window.location.pathname + window.location.search);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var b = getBody();
                    if (!b) return;
                    b.innerHTML = html;
                    // The modal body may include Quill init blocks (with
                    // <script> tags). Re-evaluate them so the editor mounts
                    // inside the modal — innerHTML doesn't execute scripts.
                    b.querySelectorAll('script').forEach(function (oldS) {
                        var s = document.createElement('script');
                        if (oldS.src) s.src = oldS.src;
                        else s.text = oldS.textContent;
                        oldS.parentNode.replaceChild(s, oldS);
                    });
                })
                .catch(function () {
                    var b = getBody();
                    if (b) b.innerHTML = '<p style="color:#b91c1c;">Failed to load notes.</p>';
                });
        }
        function close() {
            var modal = getModal();
            if (!modal) return;
            modal.hidden = true;
            document.body.classList.remove('notes-modal-open');
            var b = getBody();
            if (b) b.innerHTML = '';
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.notes-popup-btn');
            if (btn) {
                e.preventDefault();
                open(btn.getAttribute('data-entity-type'),
                     btn.getAttribute('data-entity-id'));
                return;
            }
            if (e.target.closest && e.target.closest('[data-notes-modal-close]')) {
                close();
                return;
            }
        });
        document.addEventListener('keydown', function (e) {
            var modal = getModal();
            if (e.key === 'Escape' && modal && !modal.hidden) close();
        });

        // Intercept redact / unredact / delete form submissions inside the modal
        // so the popup stays open. Runs in bubble phase (after onsubmit inline
        // handler) so e.defaultPrevented is true when user cancels a confirm.
        document.addEventListener('submit', function (e) {
            // Skip if the inline onsubmit already cancelled (user hit "Cancel" on confirm)
            if (e.defaultPrevented) return;

            var modal = getModal();
            if (!modal || modal.hidden) return;
            var body = getBody();
            if (!body || !body.contains(e.target)) return;

            var form = e.target;
            var actionInp = form.querySelector('input[name="note_action"]');
            if (!actionInp) return;
            var noteAction = actionInp.value;
            if (noteAction !== 'redact' && noteAction !== 'unredact' && noteAction !== 'delete') return;

            e.preventDefault();

            var fd = new FormData(form);
            fd.set('ajax', '1');
            fd.set('return_to', window.location.pathname + window.location.search);

            fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var b = getBody();
                    if (!b) return;
                    b.innerHTML = html;
                    b.querySelectorAll('script').forEach(function (oldS) {
                        var s = document.createElement('script');
                        if (oldS.src) s.src = oldS.src;
                        else s.text = oldS.textContent;
                        oldS.parentNode.replaceChild(s, oldS);
                    });
                })
                .catch(function () {
                    // Network failure — fall back to normal submit (closes modal)
                    form.submit();
                });
        }, false);
    })();
    </script>
    <?php
}

/**
 * Emit the attachment preview modal scaffold + click interceptor JS.
 * Idempotent (static guard) so multiple callers don't duplicate it.
 *
 * The modal:
 *   - Intercepts clicks on .note-attachment / .note-att-link / .note-att-preview
 *   - Routes CAD/3D file extensions to /cad_viewer.php?att_id=N
 *   - Routes browser-previewable types (image, PDF, text) directly to
 *     /note_attach.php?id=N (which sets Content-Disposition: inline)
 *   - Falls back to default browser action (download) for unknown types
 *
 * The att_id is extracted from the link's href: note_attach.php?id=N
 */
function notes_attachment_preview_assets()
{
    static $emitted = false;
    if ($emitted) return;
    $emitted = true;
    ?>
    <div id="att-preview-modal" class="att-preview-modal" hidden>
        <div class="att-preview-backdrop" data-att-preview-close></div>
        <div class="att-preview-dialog" role="dialog" aria-label="Attachment preview">
            <div class="att-preview-head">
                <span class="att-preview-name" id="att-preview-name">Preview</span>
                <span class="att-preview-actions">
                    <a id="att-preview-open" class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="#">Open in new tab</a>
                    <a id="att-preview-download" class="btn btn-ghost btn-sm" href="#" download>Download</a>
                    <button type="button" class="btn btn-icon att-preview-close-btn" data-att-preview-close title="Close (Esc)">✕</button>
                </span>
            </div>
            <div class="att-preview-body">
                <iframe id="att-preview-frame" frameborder="0"></iframe>
            </div>
        </div>
    </div>
    <script>
    (function () {
        if (window.__attPreviewBound) return;
        window.__attPreviewBound = true;

        // Extensions the embedded CAD viewer handles. Anything in this set
        // routes through /cad_viewer.php?att_id=N; the viewer fetches the
        // file via note_attach.php credentialed by the user's session.
        var CAD_EXTS = ['dxf','dwg','cgm','cgmtx','stl','obj','step','stp','iges','igs','jt','3ds'];

        // Extensions the browser can render inline without a special viewer.
        // For these we just iframe note_attach.php?id=N directly — the
        // endpoint already sets Content-Disposition: inline for them.
        var INLINE_EXTS = ['pdf','png','jpg','jpeg','gif','webp','svg','txt','md','log','csv','json','xml','html','htm'];

        function getModal() { return document.getElementById('att-preview-modal'); }
        function getFrame() { var m = getModal(); return m ? m.querySelector('#att-preview-frame') : null; }
        function getName()  { var m = getModal(); return m ? m.querySelector('#att-preview-name') : null; }
        function getOpenLink()     { var m = getModal(); return m ? m.querySelector('#att-preview-open') : null; }
        function getDownloadLink() { var m = getModal(); return m ? m.querySelector('#att-preview-download') : null; }

        function extOf(filename) {
            var i = filename.lastIndexOf('.');
            if (i < 0) return '';
            return filename.slice(i + 1).toLowerCase();
        }

        function parseAtt(href) {
            // Returns { id, src } where src is 'note' or 'inspection'
            // (matches the endpoint the link is pointing at).
            var m = href.match(/(note|inspection)_attach\.php\?[^#]*?\bid=(\d+)/);
            if (!m) return null;
            return { src: m[1], id: parseInt(m[2], 10) };
        }

        function openPreview(filename, frameUrl, downloadUrl) {
            var modal = getModal();
            if (!modal) return;
            var frame = getFrame();
            var nm    = getName();
            var open  = getOpenLink();
            var dl    = getDownloadLink();
            if (nm) nm.textContent = filename;
            if (open) open.setAttribute('href', frameUrl);
            if (dl) dl.setAttribute('href', downloadUrl);
            if (frame) frame.setAttribute('src', frameUrl);
            modal.hidden = false;
            document.body.classList.add('att-preview-modal-open');
        }
        function closePreview() {
            var modal = getModal();
            if (!modal) return;
            modal.hidden = true;
            document.body.classList.remove('att-preview-modal-open');
            var frame = getFrame();
            if (frame) frame.setAttribute('src', 'about:blank');
        }

        document.addEventListener('click', function (e) {
            // Close buttons / backdrop
            if (e.target.closest && e.target.closest('[data-att-preview-close]')) {
                closePreview();
                return;
            }
            // Attachment link interception. We catch BOTH:
            //   .note-att-link      — the Running Notes list column
            //   .note-attachment    — inline note cards (asset view, item edit)
            //                         and inside the popup modal
            var link = e.target.closest && e.target.closest('a.note-att-link, a.note-attachment');
            if (!link) return;
            var href = link.getAttribute('href') || '';
            var att = parseAtt(href);
            if (!att) return;  // Not a recognisable attachment link; let default happen.

            // Filename guess: prefer the link's title attr (which we emit
            // server-side with the filename), then fall back to text.
            var filename = link.getAttribute('title') || link.textContent.trim() || 'attachment';
            // Strip the leading "📎 " emoji if present in the visible text.
            filename = filename.replace(/^\s*📎\s*/, '').split(/\s+·\s+/)[0];
            // Also strip a trailing "(123 KB)" size annotation if it
            // leaked through textContent (running_notes view markup
            // includes one inside a <span>).
            filename = filename.replace(/\s*\([^)]*\)\s*$/, '').trim();
            var ext = extOf(filename);

            // Route to the right viewer.
            if (CAD_EXTS.indexOf(ext) !== -1) {
                e.preventDefault();
                var base = (window.MAGDYN_BASE || '').replace(/\/+$/, '');
                openPreview(filename, base + '/cad_viewer.php?att_id=' + att.id + '&src=' + att.src, href);
            } else if (INLINE_EXTS.indexOf(ext) !== -1) {
                e.preventDefault();
                openPreview(filename, href, href);
            } else {
                // Unknown extension. We still want to preview if the
                // server is going to stream it inline (PDF and images
                // by default). Best-effort: preview anyway. Worst case
                // the iframe shows a download prompt and the user can
                // close via the floating X.
                console.log('[att-preview] unknown extension "' + ext + '"; opening in modal anyway');
                e.preventDefault();
                openPreview(filename, href, href);
            }
        });

        document.addEventListener('keydown', function (e) {
            var modal = getModal();
            if (e.key === 'Escape' && modal && !modal.hidden) closePreview();
        });
    })();
    </script>
    <?php
}
