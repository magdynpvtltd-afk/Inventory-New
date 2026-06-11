<?php
/**
 * MagDyn — Running Notes module
 * Created: 20260516_190000_IST
 *
 * Top-level page for the Running Notes module. Actions:
 *
 *   list    (default)   Datatable of all notes, filtered by the
 *                        current user's per-category view permissions.
 *   new                 "Add note" page: pick entity type → entity →
 *                        category, write body, attach files, save.
 *   view                Read-only view of a single note. Useful for
 *                        permalinks/email.
 *   save    (POST)      Create / update from the new page or any
 *                        entity-side composer (handled via
 *                        notes_handle_action).
 *   delete  (POST)      Soft-delete a note (handled via
 *                        notes_handle_action).
 *   entity_picker (AJAX) Returns a JSON list of entities matching the
 *                        chosen type + search query. Used by the new
 *                        page's two-stage picker.
 *   modal   (AJAX)       Returns the notes section HTML for a given
 *                        (entity_type, entity_id). Used by the popup
 *                        button on entity pages.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/_notes.php';
require_once __DIR__ . '/includes/datatable.php';

/**
 * Render the "import results" page (stat cards + import log). Shown after a
 * POST to ?action=import_old. Mirrors the layout used by the asset/vendor
 * importers on old_inventory_import.php.
 */
function running_notes_render_import_result(array $result, ?string $fatalError): void
{
    $page_title  = 'Import Running Notes — Results';
    $page_module = 'running_notes';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'      => 'Import Running Notes — Results',
            'back_href'  => url('/running_notes.php?action=import'),
            'back_label' => 'Back to Import',
        ]) ?>
        <div class="form-page-body" style="max-width:820px;">
        <?php if ($fatalError): ?>
            <div class="alert alert-error">
                <strong>Import failed with a fatal error:</strong><br>
                <code><?= h($fatalError) ?></code>
            </div>
        <?php else: ?>
            <h3 style="margin:0 0 8px;">Notes</h3>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
                <?php foreach ([
                    ['Found in source', $result['note_total']    ?? 0, '#f3f4f6', '#374151'],
                    ['Imported',        $result['note_imported'] ?? 0, '#d1fae5', '#065f46'],
                    ['Skipped',         $result['note_skipped']  ?? 0, '#fef9c3', '#854d0e'],
                    ['Failed',          $result['note_failed']   ?? 0, '#fee2e2', '#991b1b'],
                ] as [$label, $val, $bg, $color]): ?>
                <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                            padding:14px 24px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                    <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                    <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h3 style="margin:0 0 8px;">Linked to</h3>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
                <?php foreach ([
                    ['Assets',              $result['as_asset']    ?? 0, '#dbeafe', '#1e40af'],
                    ['Inventory items',     $result['as_inv_item'] ?? 0, '#dbeafe', '#1e40af'],
                    ['Inventory txns',      $result['as_inv_txn']  ?? 0, '#dbeafe', '#1e40af'],
                    ['Attachments',         $result['att_imported']?? 0, '#ede9fe', '#5b21b6'],
                ] as [$label, $val, $bg, $color]): ?>
                <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                            padding:14px 24px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                    <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                    <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($result['errors'])): ?>
            <h3 style="margin-bottom:8px;">Import Log</h3>
            <div style="background:#f9fafb;border:1px solid var(--border);border-radius:6px;
                        max-height:360px;overflow-y:auto;padding:12px;
                        font-size:12px;font-family:monospace;line-height:1.6;">
                <?php foreach ($result['errors'] as $entry): ?>
                <?php $c = $entry['level'] === 'error' ? '#991b1b' : ($entry['level'] === 'warn' ? '#854d0e' : '#374151'); ?>
                <div style="color:<?= $c ?>;margin-bottom:2px;">
                    [<?= h($entry['time']) ?>]
                    [<?= strtoupper(h($entry['level'])) ?>]
                    <?= h(is_array($entry['message']) ? json_encode($entry['message']) : $entry['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-top:20px;">
                Attachment <em>metadata</em> was imported. Copy the physical files into
                <code>uploads/notes/old_import/</code> (named by their old <code>tmp_name</code> hash)
                so the 📎 download links resolve.
            </div>
        <?php endif; ?>

            <div style="margin-top:24px;display:flex;gap:10px;">
                <a class="btn btn-primary" href="<?= h(url('/running_notes.php?action=list')) ?>">View Running Notes</a>
                <a class="btn btn-ghost"   href="<?= h(url('/running_notes.php?action=import')) ?>">Back to Import</a>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
}

$action = (string)input('action', 'list');

// Note actions (save, delete, redact, unredact) come from any host that
// imported _notes.php. We catch them here because /running_notes.php is
// the universal endpoint for these writes (see notes_endpoint_for()).
if (notes_handle_action()) {
    // AJAX composer (notes popup modal): instead of redirecting (which would
    // navigate the host page and close the modal), respond with the refreshed
    // notes section HTML so the client can swap it in place and keep the
    // modal open after adding a note.
    if (input('ajax')) {
        require_permission('running_notes', 'view');
        $et  = (string)input('entity_type', '');
        $eid = (int)input('entity_id', 0);
        $rt  = (string)input('return_to', '');
        if (in_array($et, ['asset','asset_txn','inv_item','inv_txn','inspection','inspection_template'], true) && $eid > 0) {
            notes_render($et, $eid, 'modal', $rt);
        }
        exit;
    }
    // Where do we send the user back? The host page passes its URL as
    // `return_to`. We accept ONLY same-origin paths (must start with '/')
    // to prevent open-redirect attacks. If anything is fishy, fall back
    // to the running notes list.
    $returnTo = (string)input('return_to', '');
    $isSafe = $returnTo !== ''
        && strpos($returnTo, '/') === 0   // path-relative
        && strpos($returnTo, '//') !== 0  // not protocol-relative (//evil.com/...)
        && strpos($returnTo, "\n") === false
        && strpos($returnTo, "\r") === false;
    if ($isSafe) {
        // url() prepends the app base if needed. But return_to is already
        // a full path like /erp/inventory.php?action=... so we use it raw.
        redirect($returnTo);
    }
    redirect(url('/running_notes.php?action=list'));
}

// =================================================================
// OLD-INVENTORY IMPORT — run / reset / confirmation page.
// These are gated on running_notes.manage (admin), independent of the
// list's view gate below.
// =================================================================

// ── POST: delete ALL running notes (every module) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'delete_all_notes') {
    csrf_check();
    require_permission('running_notes', 'manage');

    try {
        $count = (int) db_val('SELECT COUNT(*) FROM notes', [], 0);
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('TRUNCATE TABLE note_attachments');
        $pdo->exec('TRUNCATE TABLE notes');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        flash_set('success', "All running notes deleted ({$count}), including every attachment.");
    } catch (Throwable $e) {
        try { db()->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_) {}
        flash_set('error', 'Delete failed: ' . $e->getMessage());
    }
    redirect(url('/running_notes.php?action=import'));
}

// ── POST: run the old-inventory notes import ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_old') {
    csrf_check();
    require_permission('running_notes', 'manage');
    @set_time_limit(0);

    require_once __DIR__ . '/services/OldInventoryNotesImportService.php';

    $fatalError = null;
    $result     = [];
    try {
        $svc    = new OldInventoryNotesImportService((int) current_user_id());
        $result = $svc->run();
    } catch (Throwable $e) {
        $fatalError = $e->getMessage();
    }
    running_notes_render_import_result($result, $fatalError);
    exit;
}

// ── GET: import confirmation / status page ───────────────────────────────────
if ($action === 'import') {
    require_permission('running_notes', 'manage');

    // Source count (old API) — guarded so an unreachable old server still
    // renders the page (delete-all works without it).
    $apiError    = null;
    $sourceCount = 0;
    try {
        require_once __DIR__ . '/includes/old_inventory_api.php';
        old_inventory_notes_api('ping');
        $sourceCount = (int) (old_inventory_notes_api('notes_count')['count'] ?? 0);
    } catch (Throwable $e) {
        $apiError = $e->getMessage();
    }

    $localNotes = (int) db_val('SELECT COUNT(*) FROM notes', [], 0);
    $localAtts  = (int) db_val('SELECT COUNT(*) FROM note_attachments', [], 0);

    $page_title  = 'Import Running Notes';
    $page_module = 'running_notes';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'      => 'Import Running Notes from Old Inventory',
            'subtitle'   => 'Migrate <code>inv_notes</code> + <code>notes_attachments</code> from <code>inventory_live</code> (192.168.1.249).',
            'back_href'  => url('/running_notes.php?action=list'),
            'back_label' => 'Back to Running Notes',
        ]) ?>
        <div class="form-page-body" style="max-width:720px;">

            <?php if ($apiError): ?>
            <div class="alert alert-error" style="margin-bottom:20px;">
                <strong>Cannot reach the running-notes API.</strong><br>
                <code style="font-size:12px;"><?= h($apiError) ?></code><br><br>
                Deploy <code>api_export_notes.php</code> to the old server at
                <strong>192.168.1.249/inventory/</strong> and confirm <code>notes_url</code> in
                <code>config/old_inventory_api.php</code> points at it.
            </div>
            <?php else: ?>
            <div class="alert alert-info" style="margin-bottom:20px;">
                ✅ Running-notes API reachable — ready to import.
            </div>
            <?php endif; ?>

            <h3 style="margin:0 0 10px;">Counts</h3>
            <table class="info-table" style="margin-bottom:24px;width:100%;">
                <thead>
                    <tr>
                        <th style="width:50%;">Type</th>
                        <th style="text-align:right;">Old Inventory</th>
                        <th style="text-align:right;">Already in MagDyn</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Notes (non-redacted)</td>
                        <td style="text-align:right;font-weight:600;"><?= number_format($sourceCount) ?></td>
                        <td style="text-align:right;"><?= number_format($localNotes) ?></td>
                    </tr>
                    <tr>
                        <td>Attachments</td>
                        <td style="text-align:right;font-weight:600;">—</td>
                        <td style="text-align:right;"><?= number_format($localAtts) ?></td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin:0 0 10px;">What this import does</h3>
            <table class="info-table" style="margin-bottom:24px;width:100%;">
                <tr><th style="width:40%;">Class <code>A</code></th><td>Linked to the <strong>asset</strong> whose <em>Asset ID</em> (<code>asset_tag</code>) equals the old <code>inv_notes.id</code>.</td></tr>
                <tr><th>Class <code>P</code></th><td>Linked to the <strong>inventory item</strong> whose <em>Inventory Code</em> (<code>code</code>) equals the old <code>inv_notes.id</code>.</td></tr>
                <tr><th>With a <code>tid</code></th><td>Linked to the specific <strong>inventory transaction</strong> (matched via <code>OLD-ITX-&lt;tid&gt;</code>). Falls back to the class entity if the txn isn't found.</td></tr>
                <tr><th>Attachments</th><td>Metadata only. <code>stored_path = old_import/&lt;tmp_name&gt;</code> — copy the physical files into <code>uploads/notes/old_import/</code> separately.</td></tr>
                <tr><th>Author / date</th><td>Authored by you; original <code>created_date</code> preserved.</td></tr>
            </table>

            <!-- Reset -->
            <h3 style="margin:0 0 10px;">Reset</h3>
            <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
                <p style="margin:0 0 12px;font-size:14px;color:#7f1d1d;">
                    <strong>Delete All Running Notes</strong> — permanently removes <em>every</em> note and
                    attachment in the system (all modules, not just imported ones). Run this before a clean
                    re-import to avoid duplicates.
                </p>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                    <div style="text-align:center;min-width:80px;">
                        <div style="font-size:22px;font-weight:700;color:#991b1b;"><?= number_format($localNotes) ?></div>
                        <div style="font-size:11px;color:#7f1d1d;">Notes</div>
                    </div>
                    <div style="text-align:center;min-width:80px;">
                        <div style="font-size:22px;font-weight:700;color:#991b1b;"><?= number_format($localAtts) ?></div>
                        <div style="font-size:11px;color:#7f1d1d;">Attachments</div>
                    </div>
                </div>
                <form method="post" action="<?= h(url('/running_notes.php')) ?>"
                      onsubmit="return confirm('This will permanently delete ALL running notes and attachments across EVERY module.\n\nAre you sure?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_all_notes">
                    <button type="submit" class="btn btn-danger">🗑 Delete All Running Notes</button>
                </form>
            </div>

            <?php if (!$apiError): ?>
            <!-- Run import -->
            <h3 style="margin:0 0 10px;">Run Import</h3>
            <form method="post" action="<?= h(url('/running_notes.php')) ?>"
                  onsubmit="return confirm('Import all running notes from the old system?\n\nRun \'Delete All Running Notes\' first if you want a clean re-import.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_old">
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="submit" class="btn btn-primary">▶ Import Running Notes</button>
                    <a class="btn btn-ghost" href="<?= h(url('/running_notes.php?action=list')) ?>">Cancel</a>
                    <span class="muted small">This may take up to a minute.</span>
                </div>
            </form>
            <?php endif; ?>

        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

require_permission('running_notes', 'view');

// =================================================================
// MODAL — AJAX fragment for the popup button on entity pages
// =================================================================
if ($action === 'modal') {
    $et = (string)input('entity_type', '');
    $id = (int)input('entity_id', 0);
    $rt = (string)input('return_to', '');
    if (!in_array($et, ['asset', 'asset_txn', 'inv_item', 'inv_txn', 'inspection', 'inspection_template'], true) || $id <= 0) {
        http_response_code(400);
        echo '<p style="color:#b91c1c;">Bad request.</p>';
        exit;
    }
    // Permission is enforced inside notes_render via the host-module
    // permission check. The user must also have running_notes.view to
    // even reach this endpoint (require_permission above).
    notes_render($et, $id, 'modal', $rt);
    exit;
}

// =================================================================
// ATTACHMENTS — JSON list of attachments on an entity's notes.
// Used by the 📎 indicator: 1 → open directly, >1 → popup of names.
// =================================================================
if ($action === 'attachments') {
    header('Content-Type: application/json; charset=utf-8');
    $et = (string)input('entity_type', '');
    $id = (int)input('entity_id', 0);
    if (!in_array($et, ['asset', 'asset_txn', 'inv_item', 'inv_txn', 'inspection', 'inspection_template'], true) || $id <= 0) {
        echo '[]';
        exit;
    }
    $rows = db_all(
        "SELECT na.id, na.filename
           FROM note_attachments na
           JOIN notes n ON n.id = na.note_id
          WHERE n.entity_type = ? AND n.entity_id = ? AND n.is_deleted = 0
          ORDER BY na.id",
        [$et, $id]
    );
    $out = [];
    foreach ($rows as $r) { $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['filename']]; }
    echo json_encode($out);
    exit;
}

// =================================================================
// ENTITY PICKER — AJAX list for the new-note page
// =================================================================
if ($action === 'entity_picker') {
    header('Content-Type: application/json; charset=utf-8');
    $et = (string)input('entity_type', '');
    $q  = trim((string)input('q', ''));
    $like = '%' . $q . '%';
    $rows = [];
    if ($et === 'asset') {
        $rows = db_all(
            "SELECT a.id, a.asset_tag AS code,
                    COALESCE(am.name, '') AS label
               FROM assets a
               LEFT JOIN asset_models am ON am.id = a.model_id
              WHERE a.asset_tag LIKE ? OR am.name LIKE ?
              ORDER BY a.asset_tag
              LIMIT 30",
            [$like, $like]
        );
    } elseif ($et === 'inv_item') {
        $rows = db_all(
            "SELECT id, code, COALESCE(NULLIF(short_description, ''), name) AS label
               FROM inv_items
              WHERE code LIKE ? OR short_description LIKE ? OR name LIKE ?
              ORDER BY code
              LIMIT 30",
            [$like, $like, $like]
        );
    } elseif ($et === 'asset_txn') {
        $rows = db_all(
            "SELECT at.id,
                    CONCAT('Txn #', at.id) AS code,
                    CONCAT(at.txn_type, ' · ', a.asset_tag, ' · ', DATE(at.at)) AS label
               FROM asset_transactions at
               JOIN assets a ON a.id = at.asset_id
              WHERE a.asset_tag LIKE ?
              ORDER BY at.id DESC
              LIMIT 30",
            [$like]
        );
    } elseif ($et === 'inv_txn') {
        $rows = db_all(
            "SELECT t.id,
                    CONCAT('Txn #', t.id) AS code,
                    CONCAT(t.txn_type, ' · ', i.code, ' · ', DATE(t.created_at)) AS label
               FROM inv_txns t
               JOIN inv_items i ON i.id = t.item_id
              WHERE i.code LIKE ?
              ORDER BY t.id DESC
              LIMIT 30",
            [$like]
        );
    }
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

// =================================================================
// VIEW — single-note detail page
// =================================================================
if ($action === 'view') {
    $id = (int)input('id', 0);
    $note = db_one(
        "SELECT n.*, u.full_name AS author_name, u.email AS author_email,
                c.name AS note_type_name, c.code AS note_type_code,
                ru.full_name AS redactor_name, ru.email AS redactor_email
           FROM notes n
           LEFT JOIN users u      ON u.id  = n.author_id
           LEFT JOIN categories c ON c.id  = n.note_type_id
           LEFT JOIN users ru     ON ru.id = n.redacted_by
          WHERE n.id = ? AND n.is_deleted = 0",
        [$id]
    );
    if (!$note) {
        flash_set('error', 'Note not found.');
        redirect(url('/running_notes.php?action=list'));
    }
    if (!notes_can_view_category($note['note_type_code'])) {
        flash_set('error', 'You don\'t have permission to view notes in this category.');
        redirect(url('/running_notes.php?action=list'));
    }
    $isRedacted = !empty($note['redacted_at']);

    $atts = db_all('SELECT * FROM note_attachments WHERE note_id = ? ORDER BY id', [$id]);

    // Resolve the linked entity for "Go to entity" link.
    $entityLink = '#';
    $entityLabel = $note['entity_type'] . ' #' . $note['entity_id'];
    if ($note['entity_type'] === 'asset') {
        $a = db_one('SELECT asset_tag FROM assets WHERE id = ?', [$note['entity_id']]);
        if ($a) { $entityLabel = 'Asset ' . $a['asset_tag']; $entityLink = url('/asset.php?action=view&id=' . (int)$note['entity_id']); }
    } elseif ($note['entity_type'] === 'inv_item') {
        $i = db_one('SELECT code, short_description, name FROM inv_items WHERE id = ?', [$note['entity_id']]);
        if ($i) { $entityLabel = 'Item ' . $i['code'] . ' — ' . ($i['short_description'] ?: $i['name']); $entityLink = url('/inventory.php?action=item_edit&id=' . (int)$note['entity_id']); }
    } elseif ($note['entity_type'] === 'asset_txn') {
        $at = db_one(
            'SELECT at.id, at.txn_type, a.asset_tag, a.id AS asset_id
               FROM asset_transactions at JOIN assets a ON a.id = at.asset_id
              WHERE at.id = ?',
            [$note['entity_id']]
        );
        if ($at) {
            $entityLabel = 'Asset txn #' . (int)$at['id'] . ' — ' . $at['asset_tag'] . ' · ' . $at['txn_type'];
            $entityLink  = url('/asset.php?action=view&id=' . (int)$at['asset_id']);
        }
    } elseif ($note['entity_type'] === 'inv_txn') {
        $it = db_one(
            'SELECT t.id, t.txn_type, i.code, t.item_id
               FROM inv_txns t JOIN inv_items i ON i.id = t.item_id
              WHERE t.id = ?',
            [$note['entity_id']]
        );
        if ($it) {
            $entityLabel = 'Inv txn #' . (int)$it['id'] . ' — ' . $it['code'] . ' · ' . $it['txn_type'];
            $entityLink  = url('/inventory.php?action=item_edit&id=' . (int)$it['item_id']);
        }
    } elseif ($note['entity_type'] === 'inspection') {
        $ins = db_one('SELECT id, code FROM inspections WHERE id = ?', [$note['entity_id']]);
        if ($ins) {
            $entityLabel = 'Inspection ' . $ins['code'];
            $entityLink  = url('/inspection.php?action=view&id=' . (int)$ins['id']);
        }
    } elseif ($note['entity_type'] === 'inspection_template') {
        $tpl = db_one('SELECT id, code, name FROM inspection_templates WHERE id = ?', [$note['entity_id']]);
        if ($tpl) {
            $entityLabel = 'Inspection template ' . $tpl['code'] . ' — ' . $tpl['name'];
            $entityLink  = url('/inspection.php?action=template_edit&id=' . (int)$tpl['id']);
        }
    }

    $page_title  = 'Note #' . (int)$note['id'];
    $page_module = 'running_notes';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => 'Note #' . (int)$note['id'],
            'subtitle'    => 'by ' . ($note['author_name'] ?: $note['author_email']) . ' · ' . $note['created_at'],
            'back_href'   => url('/running_notes.php?action=list'),
            'back_label'  => 'Running Notes',
        ]) ?>
        <div class="form-page-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label>Entity</label>
                    <div><a href="<?= h($entityLink) ?>"><?= h($entityLabel) ?></a></div>
                </div>
                <?php if ($note['note_type_name']): ?>
                    <div class="field">
                        <label>Category</label>
                        <div><span class="pill pill-info"><?= h($note['note_type_name']) ?></span></div>
                    </div>
                <?php endif; ?>
                <div class="field span-4">
                    <label>Body</label>
                    <?php if ($isRedacted): ?>
                        <div class="note-body note-body-redacted" style="padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-alt, #fff7ed);">
                            <em>[Redacted by <?= h($note['redactor_name'] ?: $note['redactor_email'] ?: 'unknown') ?>
                                on <?= h($note['redacted_at']) ?>]</em>
                        </div>
                    <?php else: ?>
                        <div class="note-body" style="padding: 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface);">
                            <?= $note['body_html'] ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($atts && !$isRedacted): ?>
                    <div class="field span-4">
                        <label>Attachments</label>
                        <div class="note-attachments">
                            <?php foreach ($atts as $a): ?>
                                <a class="note-attachment" href="<?= h(url('/note_attach.php?id=' . (int)$a['id'])) ?>"
                                   title="<?= h($a['filename']) ?>">
                                    📎 <?= h($a['filename']) ?>
                                    <span class="muted small">(<?= number_format((int)$a['size_bytes'] / 1024, 1) ?> KB)</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php notes_attachment_preview_assets(); ?>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// =================================================================
// NEW — Add note page
// =================================================================
if ($action === 'new') {
    // The form is used in TWO modes:
    //   - Create: ?id is absent. Requires running_notes.create.
    //   - Edit:   ?id=<existing note id>. Requires the same modify gate
    //             used elsewhere (author OR host-module manager) plus
    //             manage permission on the note's category.
    $editingId = (int)input('id', 0);
    $editNote  = null;
    if ($editingId > 0) {
        $editNote = db_one(
            "SELECT n.*, c.code AS note_type_code
               FROM notes n
               LEFT JOIN categories c ON c.id = n.note_type_id
              WHERE n.id = ? AND n.is_deleted = 0",
            [$editingId]
        );
        if (!$editNote) {
            flash_set('error', 'Note not found.');
            redirect(url('/running_notes.php?action=list'));
        }
        if (!empty($editNote['redacted_at'])) {
            flash_set('error', 'Redacted notes cannot be edited. Restore the note first if you have admin rights.');
            redirect(url('/running_notes.php?action=list'));
        }
        // Permission gate: use the centralized entity_type → (module,
        // action) map so adding a new entity type doesn't require
        // touching this code.
        if (!notes_can_manage($editNote['entity_type'])) {
            flash_set('error', 'You do not have permission to edit this note.');
            redirect(url('/running_notes.php?action=list'));
        }
        if (!notes_can_manage_category($editNote['note_type_code'])) {
            flash_set('error', 'You do not have manage rights on this note\'s category.');
            redirect(url('/running_notes.php?action=list'));
        }
    } else {
        require_permission('running_notes', 'create');
    }

    // Pre-fill values: from query string (deep link) for create, or
    // from the existing note for edit.
    $preType  = $editNote ? (string)$editNote['entity_type'] : (string)input('entity_type', '');
    $preId    = $editNote ? (int)$editNote['entity_id']      : (int)input('entity_id', 0);
    $preTypeId = $editNote ? (int)$editNote['note_type_id'] : 0;
    $preBody   = $editNote ? (string)$editNote['body_html'] : '';

    // For edit mode, look up the entity's friendly label so the picker
    // can show "✓ <label>" without an extra AJAX round-trip.
    $preEntityLabel = '';
    if ($editNote && $preId) {
        if ($preType === 'asset') {
            $row = db_one('SELECT asset_tag FROM assets WHERE id = ?', [$preId]);
            if ($row) $preEntityLabel = $row['asset_tag'];
        } elseif ($preType === 'inv_item') {
            $row = db_one('SELECT code, short_description, name FROM inv_items WHERE id = ?', [$preId]);
            if ($row) $preEntityLabel = $row['code'] . ' · ' . ($row['short_description'] ?: $row['name']);
        } elseif ($preType === 'asset_txn') {
            $row = db_one('SELECT at.txn_type, a.asset_tag FROM asset_transactions at JOIN assets a ON a.id = at.asset_id WHERE at.id = ?', [$preId]);
            if ($row) $preEntityLabel = 'Txn #' . $preId . ' · ' . $row['asset_tag'] . ' · ' . $row['txn_type'];
        } elseif ($preType === 'inv_txn') {
            $row = db_one('SELECT t.txn_type, i.code FROM inv_txns t JOIN inv_items i ON i.id = t.item_id WHERE t.id = ?', [$preId]);
            if ($row) $preEntityLabel = 'Txn #' . $preId . ' · ' . $row['code'] . ' · ' . $row['txn_type'];
        }
    }

    $types = notes_manageable_categories();

    $page_title = $editNote ? ('Edit note #' . $editingId) : 'Add note';
    $page_module = 'running_notes';
    $focus_id = $editNote ? '' : 'f_entity_type';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'        => $editNote ? ('Edit note #' . $editingId) : 'Add note',
            'subtitle'     => $editNote
                ? 'Editing existing note — entity reference is locked'
                : 'Attach a running note to an asset, inventory item, or transaction',
            'back_href'    => url('/running_notes.php?action=list'),
            'back_label'   => 'Running Notes',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm">'
              . ($editNote ? 'Update note' : 'Save note') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/running_notes.php?action=list')) . '">Cancel</a>',
        ]) ?>
        <form id="main-form" class="form-page-body" method="post"
              action="<?= h(url('/running_notes.php?action=save')) ?>"
              enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="note_action" value="save">
            <input type="hidden" name="edit_id" value="<?= (int)$editingId ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="f_entity_type">Entity type *</label>
                    <select id="f_entity_type" name="entity_type" required tabindex="1" class="no-combobox"
                            <?= $editNote ? 'disabled' : '' ?>>
                        <option value="">— Select —</option>
                        <option value="asset"     <?= $preType === 'asset'    ? 'selected' : '' ?>>Asset</option>
                        <option value="asset_txn" <?= $preType === 'asset_txn' ? 'selected' : '' ?>>Asset transaction</option>
                        <option value="inv_item"  <?= $preType === 'inv_item'  ? 'selected' : '' ?>>Inventory item</option>
                        <option value="inv_txn"   <?= $preType === 'inv_txn'   ? 'selected' : '' ?>>Inventory transaction</option>
                    </select>
                    <?php if ($editNote): ?>
                        <!-- A disabled select doesn't submit its value, so mirror
                             the chosen type in a hidden input for the POST. -->
                        <input type="hidden" name="entity_type" value="<?= h($preType) ?>">
                    <?php endif; ?>
                </div>
                <div class="field span-2">
                    <label for="f_entity_id">Entity *</label>
                    <div class="entity-picker">
                        <input id="f_entity_search" type="text" placeholder="Type to search…" tabindex="2" class="entity-picker-search" autocomplete="off"
                               <?= $editNote ? 'disabled' : '' ?>>
                        <input id="f_entity_id" name="entity_id" type="hidden" value="<?= (int)$preId ?>" required>
                        <div class="entity-picker-dropdown" hidden></div>
                        <div class="entity-picker-chosen muted small">
                            <?= $preEntityLabel ? '✓ ' . h($preEntityLabel) . ($editNote ? ' <span class="muted small">(locked)</span>' : '') : '' ?>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="f_type">Category</label>
                    <select id="f_type" name="note_type_id" tabindex="3" class="no-combobox">
                        <option value="">— Type —</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $preTypeId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field span-4">
                    <label>Note body *</label>
                    <div id="quill-host" class="notes-editor"></div>
                    <input type="hidden" name="body_html" id="f_body_html">
                </div>

                <div class="field span-4" data-drop-zone="rn-attach">
                    <label><?= $editNote ? 'Add more attachments' : 'Attachments' ?></label>
                    <input type="file" name="attachments[]" multiple>
                    <span class="muted small">Max 10 MB per file.<?= $editNote ? ' Existing attachments are preserved.' : '' ?> Drag files onto this area to attach.</span>
                </div>
            </div>
        </form>
    </div>

    <link rel="stylesheet" href="<?= h(asset_url('/assets/css/vendor/quill.snow.css')) ?>">
    <script src="<?= h(asset_url('/assets/js/vendor/quill.min.js')) ?>"></script>
    <script>
    (function () {
        var INITIAL_BODY = <?= json_encode($preBody) ?>;
        var IS_EDIT      = <?= $editNote ? 'true' : 'false' ?>;

        // ---- Quill ----
        var quill = null;
        function initQuill() {
            if (typeof Quill === 'undefined') return;
            quill = new Quill('#quill-host', {
                theme: 'snow',
                modules: { toolbar: [
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['link', 'blockquote', 'code-block'],
                    [{ header: [1, 2, 3, false] }],
                    ['clean']
                ]},
                placeholder: 'Write a note…'
            });
            if (INITIAL_BODY) {
                quill.root.innerHTML = INITIAL_BODY;
            }
        }
        if (document.readyState !== 'loading') initQuill();
        else document.addEventListener('DOMContentLoaded', initQuill);

        document.getElementById('main-form').addEventListener('submit', function (e) {
            if (!quill) return;
            document.getElementById('f_body_html').value = quill.root.innerHTML;
            var entId = document.getElementById('f_entity_id').value;
            if (!entId) {
                e.preventDefault();
                alert('Please pick an entity.');
                return;
            }
            // Plain-text length check — Quill's empty doc is "<p><br></p>"
            var plain = (quill.getText() || '').replace(/\s+/g, '');
            if (!plain.length) {
                e.preventDefault();
                alert('Please write something in the note body.');
            }
        });

        // ---- Entity picker (search + select). Skipped entirely in edit
        // mode since the entity is locked. ----
        if (!IS_EDIT) {
            var typeSel   = document.getElementById('f_entity_type');
            var searchInp = document.getElementById('f_entity_search');
            var hiddenInp = document.getElementById('f_entity_id');
            var dropdown  = document.querySelector('.entity-picker-dropdown');
            var chosenEl  = document.querySelector('.entity-picker-chosen');
            var searchTimer = null;

            function clearChoice() {
                hiddenInp.value = '';
                chosenEl.textContent = '';
            }
            typeSel.addEventListener('change', function () {
                clearChoice();
                searchInp.value = '';
                dropdown.hidden = true;
            });

            function searchEntities() {
                var et = typeSel.value;
                var q  = searchInp.value.trim();
                if (!et) { dropdown.hidden = true; return; }
                var url = (window.MAGDYN_BASE || '') + '/running_notes.php?action=entity_picker'
                        + '&entity_type=' + encodeURIComponent(et)
                        + '&q=' + encodeURIComponent(q);
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        dropdown.innerHTML = '';
                        if (!data || !data.ok || !data.rows.length) {
                            dropdown.innerHTML = '<div class="entity-picker-empty muted small">No matches</div>';
                        } else {
                            data.rows.forEach(function (row) {
                                var div = document.createElement('div');
                                div.className = 'entity-picker-item';
                                div.dataset.id    = row.id;
                                div.dataset.label = row.code + ' · ' + row.label;
                                div.innerHTML = '<strong>' + row.code + '</strong> <span class="muted">' + row.label + '</span>';
                                dropdown.appendChild(div);
                            });
                        }
                        dropdown.hidden = false;
                    });
            }
            searchInp.addEventListener('input', function () {
                if (searchTimer) clearTimeout(searchTimer);
                searchTimer = setTimeout(searchEntities, 200);
            });
            searchInp.addEventListener('focus', function () { if (typeSel.value) searchEntities(); });
            document.addEventListener('mousedown', function (e) {
                var item = e.target.closest && e.target.closest('.entity-picker-item');
                if (item) {
                    hiddenInp.value = item.dataset.id;
                    chosenEl.textContent = '✓ ' + item.dataset.label;
                    searchInp.value = '';
                    dropdown.hidden = true;
                    return;
                }
                if (!e.target.closest('.entity-picker')) dropdown.hidden = true;
            });

            // Prefill chosen entity if we arrived here with ?entity_type=&entity_id=
            if (typeSel.value && hiddenInp.value && !chosenEl.textContent) {
                chosenEl.textContent = '✓ Entity #' + hiddenInp.value + ' (from previous page)';
            }
        }
    })();
    </script>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// =================================================================
// LIST (default)
// =================================================================
// Datatable of all notes the user can view. Filterable by entity type,
// category, author, body text.

// Build category-visible IN clause for the WHERE.
$viewableIds = notes_viewable_category_ids();
if (empty($viewableIds)) {
    // Only see uncategorised notes
    $catWhere = 'n.note_type_id IS NULL';
} else {
    $catWhere = '(n.note_type_id IS NULL OR n.note_type_id IN (' . implode(',', $viewableIds) . '))';
}

$dtCfg = [
    'id'       => 'running_notes',
    'base_sql' =>
        "SELECT n.id, n.entity_type, n.entity_id, n.created_at, n.edited_at,
                n.note_type_id, n.redacted_at,
                u.full_name AS author_name, u.email AS author_email,
                c.name AS note_type_name, c.code AS note_type_code,
                SUBSTRING(n.body_html, 1, 200) AS body_snippet,
                (SELECT COUNT(*) FROM note_attachments na WHERE na.note_id = n.id) AS att_count,
                CASE
                    WHEN n.entity_type = 'asset'      THEN ea.asset_tag
                    WHEN n.entity_type = 'inv_item'   THEN ei.code
                    WHEN n.entity_type = 'asset_txn'  THEN eat_a.asset_tag
                    WHEN n.entity_type = 'inv_txn'    THEN eit_i.code
                    WHEN n.entity_type = 'inspection' THEN ein.code
                END AS entity_code
           FROM notes n
           LEFT JOIN users u            ON u.id = n.author_id
           LEFT JOIN categories c       ON c.id = n.note_type_id
           LEFT JOIN assets     ea      ON n.entity_type = 'asset'     AND ea.id      = n.entity_id
           LEFT JOIN inv_items  ei      ON n.entity_type = 'inv_item'  AND ei.id      = n.entity_id
           LEFT JOIN asset_transactions eat ON n.entity_type = 'asset_txn' AND eat.id = n.entity_id
           LEFT JOIN assets     eat_a   ON n.entity_type = 'asset_txn' AND eat_a.id   = eat.asset_id
           LEFT JOIN inv_txns   eit     ON n.entity_type = 'inv_txn'   AND eit.id     = n.entity_id
           LEFT JOIN inv_items  eit_i   ON n.entity_type = 'inv_txn'   AND eit_i.id   = eit.item_id
           LEFT JOIN inspections ein    ON n.entity_type = 'inspection' AND ein.id    = n.entity_id",
    'extra_where' => [
        ['n.is_deleted = 0', []],
        [$catWhere, []],
    ],
    'columns' => [
        ['key'=>'id',             'label'=>'#',          'sortable'=>true, 'sql_col'=>'n.id',          'th_class'=>'r','td_class'=>'r'],
        ['key'=>'when',           'label'=>'When',       'sortable'=>true, 'sql_col'=>'n.created_at'],
        ['key'=>'author',         'label'=>'Author',     'sortable'=>true, 'sql_col'=>'u.full_name'],
        ['key'=>'entity_id_raw',  'label'=>'Entity ID',  'sortable'=>true, 'searchable'=>true,  'sql_col'=>'n.entity_id', 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'entity_code',    'label'=>'Code',       'sortable'=>true, 'searchable'=>true,
            'sql_col'=>"CASE
                WHEN n.entity_type = 'asset'      THEN ea.asset_tag
                WHEN n.entity_type = 'inv_item'   THEN ei.code
                WHEN n.entity_type = 'asset_txn'  THEN eat_a.asset_tag
                WHEN n.entity_type = 'inv_txn'    THEN eit_i.code
                WHEN n.entity_type = 'inspection' THEN ein.code
            END"],
        ['key'=>'entity',         'label'=>'Entity',     'sortable'=>false],
        ['key'=>'note_type_name', 'label'=>'Category',   'sortable'=>true, 'sql_col'=>'c.name'],
        ['key'=>'body',           'label'=>'Note',       'sortable'=>false, 'sql_col'=>'n.body_html'],
        ['key'=>'att',            'label'=>'Attachments','sortable'=>true, 'searchable'=>false,
            'sql_col'=>'(SELECT COUNT(*) FROM note_attachments na WHERE na.note_id = n.id)'],
        ['key'=>'_actions',       'label'=>'',           'sortable'=>false,'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['id', 'desc'],
];

// Run the query so we can pre-fetch attachments for the visible rows in
// ONE batched query (avoiding an N+1 per-row filename lookup).
$dt = data_table_query($dtCfg);

$noteIdsOnPage = array_map(function ($r) { return (int)$r['id']; }, $dt['rows']);
$attsByNote = [];
if ($noteIdsOnPage) {
    $in = implode(',', $noteIdsOnPage);
    foreach (db_all("SELECT id, note_id, filename FROM note_attachments WHERE note_id IN ($in) ORDER BY note_id, id") as $a) {
        $attsByNote[(int)$a['note_id']][] = $a;
    }
}

$rowRenderer = function ($r) use ($attsByNote) {
    // Entity link resolution (best-effort; falls back to raw)
    $entLabel = '#' . (int)$r['entity_id'];
    $entLink  = '#';
    if ($r['entity_type'] === 'asset') {
        $a = db_one('SELECT asset_tag FROM assets WHERE id = ?', [$r['entity_id']]);
        if ($a) { $entLabel = $a['asset_tag']; $entLink = url('/asset.php?action=view&id=' . (int)$r['entity_id']); }
    } elseif ($r['entity_type'] === 'inv_item') {
        $i = db_one('SELECT code FROM inv_items WHERE id = ?', [$r['entity_id']]);
        if ($i) { $entLabel = $i['code']; $entLink = url('/inventory.php?action=item_edit&id=' . (int)$r['entity_id']); }
    } elseif ($r['entity_type'] === 'asset_txn') {
        // Asset transactions don't have a dedicated view page; link to
        // the parent asset's view page which lists this txn in its
        // history.
        $at = db_one(
            'SELECT at.id, at.txn_type, a.asset_tag, a.id AS asset_id
               FROM asset_transactions at
               JOIN assets a ON a.id = at.asset_id
              WHERE at.id = ?',
            [$r['entity_id']]
        );
        if ($at) {
            $entLabel = $at['asset_tag'] . ' · ' . $at['txn_type'];
            $entLink  = url('/asset.php?action=view&id=' . (int)$at['asset_id']);
        }
    } elseif ($r['entity_type'] === 'inv_txn') {
        $it = db_one(
            'SELECT t.id, t.txn_type, i.code, t.item_id
               FROM inv_txns t
               JOIN inv_items i ON i.id = t.item_id
              WHERE t.id = ?',
            [$r['entity_id']]
        );
        if ($it) {
            $entLabel = $it['code'] . ' · ' . $it['txn_type'];
            $entLink  = url('/inventory.php?action=item_edit&id=' . (int)$it['item_id']);
        }
    } elseif ($r['entity_type'] === 'inspection') {
        $ins = db_one('SELECT id, code FROM inspections WHERE id = ?', [$r['entity_id']]);
        if ($ins) {
            $entLabel = $ins['code'];
            $entLink  = url('/inspection.php?action=view&id=' . (int)$ins['id']);
        }
    }
    $entHtml = '<a href="' . h($entLink) . '">' . h($entLabel) . '</a>';

    // Snippet: if redacted, surface the notice; otherwise strip HTML +
    // truncate. Attachment count is rendered as its own column now.
    $isRedacted = !empty($r['redacted_at']);
    if ($isRedacted) {
        $snippet = '<em class="muted">[Redacted]</em>';
        $bodyCell = '<span class="pill pill-warn" title="Redacted">REDACTED</span> ' . $snippet;
    } else {
        $snippet = strip_tags($r['body_snippet'] ?? '');
        $snippet = preg_replace('/\s+/', ' ', $snippet);
        if (mb_strlen($snippet) > 100) $snippet = mb_substr($snippet, 0, 100) . '…';
        $bodyCell = '<span title="' . h($snippet) . '">' . $snippet . '</span>';
    }

    // Per-row actions: view always; edit/redact/delete gated by the
    // host module's manage permission AND the category's manage perm.
    // Restore only for running_notes admins on redacted rows.
    $canHostManage = notes_can_manage($r['entity_type']);
    $canCatManage  = notes_can_manage_category($r['note_type_code']);
    $canModify     = $canHostManage && $canCatManage;
    $canRestore    = $isRedacted && permission_check('running_notes', 'manage');

    $noteId = (int)$r['id'];
    $entType = h($r['entity_type']);
    $entId   = (int)$r['entity_id'];

    $actions = '<a class="btn btn-icon" href="' . h(url('/running_notes.php?action=view&id=' . $noteId))
             . '" title="View">👁 <span class="dt-action-label">View</span></a> ';

    if ($canModify && !$isRedacted) {
        $actions .= '<a class="btn btn-icon" href="' . h(url('/running_notes.php?action=new&id=' . $noteId))
                  . '" title="Edit">✎ <span class="dt-action-label">Edit</span></a> ';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/running_notes.php?action=save')) . '"'
                  . ' onsubmit="return confirm(\'Redact note #' . $noteId . '? The body will be replaced with a redaction notice; the original is preserved in the audit log.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="note_action" value="redact">'
                  . '<input type="hidden" name="entity_type" value="' . $entType . '">'
                  . '<input type="hidden" name="entity_id"   value="' . $entId . '">'
                  . '<input type="hidden" name="note_id"     value="' . $noteId . '">'
                  . '<button class="btn btn-icon" type="submit" title="Redact">🚫 <span class="dt-action-label">Redact</span></button></form> ';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/running_notes.php?action=save')) . '"'
                  . ' onsubmit="return confirm(\'Delete note #' . $noteId . '? This cannot be undone.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="note_action" value="delete">'
                  . '<input type="hidden" name="entity_type" value="' . $entType . '">'
                  . '<input type="hidden" name="entity_id"   value="' . $entId . '">'
                  . '<input type="hidden" name="note_id"     value="' . $noteId . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
    } elseif ($canModify && $isRedacted) {
        // Still allow delete on a redacted note (e.g. spam author wants
        // to walk away cleanly). Edit/redact are not meaningful.
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/running_notes.php?action=save')) . '"'
                  . ' onsubmit="return confirm(\'Delete note #' . $noteId . '? This cannot be undone.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="note_action" value="delete">'
                  . '<input type="hidden" name="entity_type" value="' . $entType . '">'
                  . '<input type="hidden" name="entity_id"   value="' . $entId . '">'
                  . '<input type="hidden" name="note_id"     value="' . $noteId . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete">🗑 <span class="dt-action-label">Delete</span></button></form>';
    }
    if ($canRestore) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/running_notes.php?action=save')) . '"'
                  . ' onsubmit="return confirm(\'Restore redacted note #' . $noteId . '? Its original body becomes visible again.\');">'
                  . csrf_field()
                  . '<input type="hidden" name="note_action" value="unredact">'
                  . '<input type="hidden" name="entity_type" value="' . $entType . '">'
                  . '<input type="hidden" name="entity_id"   value="' . $entId . '">'
                  . '<input type="hidden" name="note_id"     value="' . $noteId . '">'
                  . '<button class="btn btn-icon" type="submit" title="Restore">↩ <span class="dt-action-label">Restore</span></button></form>';
    }

    $noteAtts = $attsByNote[$noteId] ?? [];
    if ($isRedacted) {
        // Attachments on redacted notes are hidden from non-admins;
        // surface a dash here to avoid signalling.
        $attCell = '<span class="muted small">—</span>';
    } elseif ($noteAtts) {
        // Each attachment is a download link to note_attach.php. Stack
        // them one per line so multiple attachments are scannable.
        $lines = [];
        foreach ($noteAtts as $a) {
            $lines[] = '<a class="note-att-link" href="' . h(url('/note_attach.php?id=' . (int)$a['id'])) . '"'
                     . ' title="' . h($a['filename']) . '">📎 ' . h($a['filename']) . '</a>';
        }
        $attCell = '<div class="note-att-list">' . implode('', $lines) . '</div>';
    } else {
        $attCell = '<span class="muted small">—</span>';
    }

    return [
        'id'             => '<a href="' . h(url('/running_notes.php?action=view&id=' . $noteId)) . '">' . $noteId . '</a>',
        'when'           => h($r['created_at']) . ($r['edited_at'] && !$isRedacted ? ' <span class="muted small">(edited)</span>' : ''),
        'author'         => h($r['author_name'] ?: $r['author_email']),
        'entity_id_raw'  => $entId,
        'entity_code'    => $r['entity_code']
            ? '<code>' . h($r['entity_code']) . '</code>'
            : '<span class="muted small">—</span>',
        'entity'         => $entHtml,
        'note_type_name' => $r['note_type_name']
            ? '<span class="pill pill-info">' . h($r['note_type_name']) . '</span>'
            : '<span class="muted small">—</span>',
        'body'           => $bodyCell,
        'att'            => $attCell,
        '_actions'       => dt_actions_wrap($actions),
    ];
};

// JSON branch — used by the SPA shell when only the table body needs
// re-rendering (sort, filter, pagination). Mirrors what data_table_run
// would do but using the already-queried $dt + the closure we just built.
if ((string)input('dt_format', '') === 'json') {
    ob_start();
    data_table_render_rows($dtCfg, $dt, $rowRenderer);
    $rowsHtml = ob_get_clean();
    ob_start();
    data_table_render_pager($dt);
    $pagerHtml = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true, 'rows_html' => $rowsHtml, 'pager_html' => $pagerHtml,
        'total' => (int)$dt['total'], 'page' => (int)$dt['page'],
        'pages' => (int)$dt['pages'], 'page_size' => (int)$dt['page_size'],
        'sort' => $dt['sort'], 'dir' => $dt['dir'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dtCfg['title']        = 'Running Notes';
$listActions = '';
if (permission_check('running_notes', 'create')) {
    $listActions .= '<a class="btn btn-primary btn-sm" href="' . h(url('/running_notes.php?action=new')) . '"'
        . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ Add note', 'N') . '</a> ';
}
if (permission_check('running_notes', 'manage')) {
    $listActions .= '<a class="btn btn-ghost btn-sm" href="' . h(url('/running_notes.php?action=import')) . '">'
        . '⬇ Import from Old Inventory</a>';
}
$dtCfg['actions_html'] = $listActions;

$page_title  = 'Running Notes';
$page_module = 'running_notes';
require __DIR__ . '/includes/header.php';
data_table_render($dtCfg, $dt, $rowRenderer);
notes_attachment_preview_assets();
require __DIR__ . '/includes/footer.php';
