<?php
/**
 * MagDyn — Import module
 *
 * Permission-gated landing page for bulk-import flows. Each importer
 * is its own action and its own permission so admins can grant access
 * narrowly (e.g. "this user can import inventory items but not BOMs").
 *
 * Actions:
 *   ?action=list                       Landing page — lists available importers
 *   ?action=xml_inv_items              Upload form for XML inventory items
 *   ?action=xml_inv_items_preview POST Parse the upload, show a preview, stash in session
 *   ?action=xml_inv_items_commit  POST Insert/update from the stashed preview
 *
 * XML inventory items field mapping (per user spec, Delta 1):
 *   obj_name      -> inv_items.part_no   (and code)
 *   obj_desc      -> inv_items.name
 *   rev           -> inv_items.part_rev_no
 *   uom           -> inv_items.uom_id    (lookup by inv_uom.code)
 *   model         -> inv_items.dwg_no
 *   drawing_rev   -> inv_items.dwg_rev_no
 *   ecn           -> inv_items.ecn       (added by migration)
 *
 * All other present fields concatenated into inv_items.notes:
 *   - The full part_record (any non-empty, non-mapped fields)
 *   - misc_notes from notes_and_specs
 *   - The two part_warning blobs (if present)
 *
 * The documents_bom block (attached documents) is intentionally NOT
 * imported in this delta — will land as Delta 2 with an
 * inv_item_documents table.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_codes.php';  // for code_next('inv_item') — admin-managed sequence
require_once __DIR__ . '/includes/_vendor_sql_import.php';  // vendor SQL-dump import helpers
require_once __DIR__ . '/includes/_notes.php';  // for _notes_uploads_base() used to store the XML as a note attachment
require_once __DIR__ . '/includes/_dms.php';    // for doc_find_by_no(), doc_link_entity(), doc creation (documents_bom)
require_once __DIR__ . '/includes/_ecn.php';    // for ecn_auto_draft_for_major_rev() — Phase 2 rev-change flow
require_once __DIR__ . '/includes/_billing_products.php';  // auto-push finished goods to billing catalogue after insert/update

require_permission('import', 'view');

$action = (string)input('action', 'list');

// ============================================================
// Landing page
// ============================================================
if ($action === 'list') {
    $page_title  = 'Import';
    $page_module = 'import';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/'),
        'back_label' => 'Back',
        'title'      => 'Import',
        'subtitle'   => 'Bulk-load data from external files',
    ]) ?>
    <div style="padding: 22px;">
        <p class="muted" style="max-width:680px;line-height:1.6;">
            Choose an import flow. Each flow is gated by its own permission, so what you see here
            depends on what the admin has granted you. Imports follow a two-step pattern:
            <strong>upload &rarr; preview &rarr; commit</strong>. You'll see exactly what will be
            created or updated before anything hits the database.
        </p>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:18px;">
            <?php if (permission_check('import', 'xml_inv_items')): ?>
                <a href="<?= h(url('/import.php?action=xml_inv_items')) ?>"
                   style="display:block;padding:18px;border:1px solid var(--border);border-radius:8px;background:var(--surface);text-decoration:none;color:inherit;transition:border-color 0.12s,box-shadow 0.12s;"
                   onmouseover="this.style.borderColor='var(--primary)';this.style.boxShadow='0 4px 12px rgba(29,78,216,0.08)';"
                   onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none';">
                    <div style="font-size:22px;margin-bottom:8px;">📄</div>
                    <div style="font-weight:600;font-size:15px;margin-bottom:4px;">XML &mdash; Inventory Items</div>
                    <div class="muted" style="font-size:12.5px;line-height:1.5;">
                        Import part records from <code>part_report</code> XML files. Maps part number,
                        description, revision, UoM, drawing number, drawing revision, and ECN.
                        Other fields go into notes. Documents BOM is skipped (planned for Delta 2).
                    </div>
                </a>
            <?php endif; ?>

            <?php if (permission_check('import', 'vendors_sql')): ?>
                <a href="<?= h(url('/import.php?action=vendors_sql')) ?>"
                   style="display:block;padding:18px;border:1px solid var(--border);border-radius:8px;background:var(--surface);text-decoration:none;color:inherit;transition:border-color 0.12s,box-shadow 0.12s;"
                   onmouseover="this.style.borderColor='var(--primary)';this.style.boxShadow='0 4px 12px rgba(29,78,216,0.08)';"
                   onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none';">
                    <div style="font-size:22px;margin-bottom:8px;">🏭</div>
                    <div style="font-weight:600;font-size:15px;margin-bottom:4px;">SQL &mdash; Vendors (legacy)</div>
                    <div class="muted" style="font-size:12.5px;line-height:1.5;">
                        Import vendor companies, contacts, and addresses from the old
                        <code>inventory_live</code> MySQL dump. Maps <code>company</code>,
                        <code>contact</code>, and <code>address</code> tables. Duplicates
                        (same vendor name) are skipped automatically.
                    </div>
                </a>
            <?php endif; ?>

            <?php
            $hasAnyImporter = permission_check('import', 'xml_inv_items')
                           || permission_check('import', 'vendors_sql');
            if (!$hasAnyImporter): ?>
                <p class="muted">
                    No import flows are available to you. Ask an admin to grant an importer permission
                    (e.g. <code>import.xml_inv_items</code> or <code>import.vendors_sql</code>)
                    under Admin &middot; Roles.
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// XML inv_items: helpers
// ============================================================

/**
 * Persist an uploaded XML file into the notes uploads area so it
 * survives the preview -> commit round-trip (is_uploaded_file() only
 * works during the original upload request). Returns metadata to stash
 * in the session, or null on failure.
 *
 * We reuse the notes uploads base so the same preview/serve machinery
 * that handles note attachments handles this file too.
 */
function imp_persist_xml_upload($tmpPath, $origName) {
    if (!is_uploaded_file($tmpPath)) return null;
    $base = dirname(__DIR__) . '/uploads/notes';
    if (!is_dir($base) && !@mkdir($base, 0775, true)) return null;
    $sub = date('Y/m');
    $dir = $base . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;

    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$origName);
    if ($clean === '' || $clean === '.') $clean = 'import.xml';
    $hex = bin2hex(random_bytes(8));
    $storedRel = $sub . '/' . $hex . '_' . $clean;
    $dest = $dir . '/' . $hex . '_' . $clean;
    if (!@move_uploaded_file($tmpPath, $dest)) {
        // move_uploaded_file fails if we already consumed the tmp file
        // for parsing via a copy; fall back to copy from the still-present
        // tmp path.
        if (!@copy($tmpPath, $dest)) return null;
    }

    $mime = 'application/xml';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $m = (string)finfo_file($fi, $dest); finfo_close($fi); if ($m !== '') $mime = $m; }
    }
    return [
        'stored_path' => $storedRel,
        'filename'    => $clean,
        'mime'        => $mime,
        'size'        => (int)@filesize($dest),
    ];
}

/**
 * Attach a previously-persisted XML file (metadata from
 * imp_persist_xml_upload) to a note as a note_attachments row.
 * If $copyPhysical is true, makes a fresh physical copy so each note
 * owns its own file (so deleting one note's attachment doesn't orphan
 * another note that pointed at the same shared file). For single-part
 * imports there's just one note so copying is cheap; for multi-part we
 * still want independence.
 */
function imp_attach_xml_to_note($noteId, $xmlMeta, $uid, $copyPhysical = true) {
    if (!$xmlMeta || empty($xmlMeta['stored_path'])) return;
    $base = dirname(__DIR__) . '/uploads/notes';
    $srcRel = $xmlMeta['stored_path'];
    $src = $base . '/' . $srcRel;
    $useRel = $srcRel;
    if ($copyPhysical && is_file($src)) {
        $sub = date('Y/m');
        $dir = $base . '/' . $sub;
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            $hex = bin2hex(random_bytes(8));
            $clean = $xmlMeta['filename'] ?: 'import.xml';
            $newRel = $sub . '/' . $hex . '_' . $clean;
            if (@copy($src, $base . '/' . $newRel)) {
                $useRel = $newRel;
            }
        }
    }
    db_exec(
        'INSERT INTO note_attachments (note_id, filename, stored_path, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)',
        [(int)$noteId, $xmlMeta['filename'], $useRel, $xmlMeta['mime'], (int)$xmlMeta['size'], (int)$uid]
    );
}

/**
 * Find prior XML-import running notes for an inventory item. Identified
 * by the heading marker the importer writes. Returns rows of {id}.
 * Used by the "replace" path to soft-delete the old import note(s)
 * before creating a fresh one.
 */
function imp_prior_import_notes($itemId) {
    return db_all(
        "SELECT id FROM notes
          WHERE entity_type = 'inv_item' AND entity_id = ?
            AND is_deleted = 0
            AND body_html LIKE '%Imported from XML for%'
          ORDER BY id",
        [(int)$itemId]
    );
}

/**
 * Soft-delete a note and hard-remove its attachment rows + files.
 */
function imp_soft_delete_note($noteId) {
    $noteId = (int)$noteId;
    $base = dirname(__DIR__) . '/uploads/notes';
    foreach (db_all("SELECT id, stored_path FROM note_attachments WHERE note_id = ?", [$noteId]) as $a) {
        $path = $base . '/' . $a['stored_path'];
        if (is_file($path)) @unlink($path);
    }
    db_exec("DELETE FROM note_attachments WHERE note_id = ?", [$noteId]);
    db_exec("UPDATE notes SET is_deleted = 1, edited_at = NOW() WHERE id = ?", [$noteId]);
}

/**
 * Classify each documents_bom entry from a parsed part against the
 * existing external-documents register. For each doc returns one of:
 *
 *   'link'       doc_no found AND rev matches current rev → just link
 *                the existing document to the imported item.
 *   'rev_change' doc_no found but rev differs from current rev →
 *                operator must decide (Phase 1: link-as-is or skip;
 *                Phase 2: route through ECN). Carries existing doc id +
 *                stored rev for the preview.
 *   'upload'     doc_no not found → operator must upload the doc to
 *                create a new external document, which then links.
 *
 * Each returned row: {seq, doc_no, rev, status, desc, klass,
 *                     existing_id?, existing_code?, existing_rev?}
 */
function imp_classify_documents($part) {
    $out = [];
    foreach (($part['documents_bom'] ?? []) as $d) {
        $docNo = trim((string)($d['name'] ?? ''));
        $rev   = trim((string)($d['rev'] ?? ''));
        $row = [
            'seq'    => $d['seq'] ?? '',
            'doc_no' => $docNo,
            'rev'    => $rev,
            'status' => $d['status'] ?? '',
            'desc'   => $d['desc'] ?? '',
        ];
        if ($docNo === '') {
            // No doc number to match on — treat as upload-optional, but
            // flag. Most documents_bom header rows (e.g. "PRODUCTION")
            // have a name though.
            $row['klass'] = 'upload';
            $out[] = $row;
            continue;
        }
        $existing = doc_find_by_no($docNo, 'external');
        if (!$existing) {
            $row['klass'] = 'upload';
        } else {
            $row['existing_id']   = (int)$existing['id'];
            $row['existing_code'] = $existing['code'];
            $row['existing_rev']  = $existing['cur_rev_label'];
            // Decide link vs rev_change:
            //   - XML rev == current rev            → link
            //   - XML rev already exists on the doc → link (the revision
            //       is already on file, just not the current one; an ECN
            //       would only collide on a duplicate label)
            //   - otherwise                         → rev_change
            $sameAsCurrent = (strcasecmp(trim((string)$existing['cur_rev_label']), $rev) === 0);
            $revExists = ($rev !== '' && doc_rev_label_exists((int)$existing['id'], $rev));
            if ($sameAsCurrent || $revExists) {
                $row['klass'] = 'link';
                $row['rev_on_file'] = $revExists && !$sameAsCurrent; // note for the preview
            } else {
                $row['klass'] = 'rev_change';
            }
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Resolve the 'finshd' (Finished Good) inventory category id. All
 * XML-imported items are filed under this category per spec. Returns
 * the category id or null if the seed row is missing (the commit will
 * then leave category_id NULL and the operator can set it manually).
 */
function imp_finshd_category_id() {
    static $cached = false;
    static $val = null;
    if ($cached) return $val;
    $cached = true;
    $row = db_one("SELECT id FROM categories WHERE type = 'inventory' AND code = 'finshd' AND is_active = 1 LIMIT 1");
    $val = $row ? (int)$row['id'] : null;
    return $val;
}

/**
 * Resolve the external-documents category id to file imported documents
 * under. Prefers a category coded 'spec' or 'external'; falls back to
 * the first active external doc category. Returns id or null.
 */
function imp_external_doc_category_id($override = null) {
    if ($override) {
        $r = db_one("SELECT id FROM doc_categories WHERE id = ? AND kind = 'external' AND is_active = 1", [(int)$override]);
        if ($r) return (int)$r['id'];
    }
    static $cached = false;
    static $val = null;
    if ($cached) return $val;
    $cached = true;
    foreach (['cust_spec', 'spec', 'external', 'datasheet', 'vendor_cert'] as $code) {
        $r = db_one("SELECT id FROM doc_categories WHERE code = ? AND kind = 'external' AND is_active = 1 LIMIT 1", [$code]);
        if ($r) { $val = (int)$r['id']; return $val; }
    }
    // Fall back to any external-kind category.
    $r = db_one("SELECT id FROM doc_categories WHERE kind = 'external' AND is_active = 1 ORDER BY sort_order, id LIMIT 1");
    if (!$r) $r = db_one("SELECT id FROM doc_categories WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1");
    $val = $r ? (int)$r['id'] : null;
    return $val;
}

/**
 * Process the documents for one imported item at commit time.
 *
 * $partIdx   index of the part in the parsed set (matches the field
 *            prefix docs[$partIdx][$docIdx] from the preview form)
 * $itemId    the inv_items.id just inserted/updated
 * $choices   $_POST['docs'][$partIdx] — per-doc {klass, doc_no, rev,
 *            desc, existing_id?, action}
 * $uid       actor id
 *
 * Returns ['linked'=>n, 'created'=>n, 'skipped'=>n, 'ecns'=>n, 'notes'=>[...]].
 *
 * Actions honoured:
 *   link        (klass=link)      → link existing doc to the item
 *   link_existing (rev_change)    → link existing doc as-is
 *   skip        (any)             → do nothing
 *   upload      (klass=upload)    → store the uploaded file, create a new
 *                                   external document + initial rev, link it
 *   ecn         (rev_change)      → auto-draft a drawing_rev ECN against the
 *                                   existing doc with the XML's new rev label,
 *                                   stage the uploaded new file on the ECN,
 *                                   add this item as an affected item, and
 *                                   link the existing doc now. The new doc
 *                                   revision is created when the operator
 *                                   makes the ECN effective (normal ECN flow).
 *
 * @param int      $docCategoryId  category for newly-created documents (null = resolver default)
 */
function imp_process_documents($partIdx, $itemId, $choices, $uid, $docCategoryId = null) {
    $res = ['linked' => 0, 'created' => 0, 'skipped' => 0, 'ecns' => 0, 'notes' => []];
    if (!is_array($choices)) return $res;

    foreach ($choices as $docIdx => $c) {
        $klass  = (string)($c['klass'] ?? '');
        $docNo  = trim((string)($c['doc_no'] ?? ''));
        $rev    = trim((string)($c['rev'] ?? ''));
        $desc   = trim((string)($c['desc'] ?? ''));
        $action = (string)($c['action'] ?? ($klass === 'link' ? 'link' : 'skip'));
        $existingId = (int)($c['existing_id'] ?? 0) ?: null;

        // ECN path (rev_change → drawing_rev ECN) ---------------------
        if ($action === 'ecn') {
            if (!$existingId) { $res['skipped']++; continue; }
            if (!function_exists('ecn_auto_draft_for_major_rev') || !permission_check('ecn', 'create')) {
                // No ECN capability/permission — fall back to link-as-is
                // so the import still completes meaningfully.
                doc_link_entity($existingId, 'inv_item', (int)$itemId,
                    'Linked via XML import (ECN requested but unavailable; ' . $docNo . ' Rev ' . $rev . ')', $uid);
                $res['linked']++;
                $res['notes'][] = "ECN unavailable for {$docNo} — linked existing instead";
                continue;
            }
            $file = imp_extract_doc_file($partIdx, $docIdx);
            // Draft the ECN (drawing_rev) for the new rev label.
            $ecnId = ecn_auto_draft_for_major_rev($existingId, ($rev !== '' ? $rev : 'NEXT'), $uid);
            // Stage the uploaded new revision file on the ECN, if provided.
            if ($file && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                $stored = imp_store_ecn_pending_file($file, $ecnId);
                if ($stored) {
                    db_exec(
                        "UPDATE ecns SET pending_file_name = ?, pending_file_path = ?,
                                         pending_file_size = ?, pending_file_mime = ?, pending_file_hash = ?
                         WHERE id = ?",
                        [$stored['name'], $stored['path'], $stored['size'], $stored['mime'], $stored['hash'], (int)$ecnId]
                    );
                }
            }
            // Mark this inv item as affected by the ECN.
            if (function_exists('ecn_add_affected_item')) {
                ecn_add_affected_item($ecnId, (int)$itemId,
                    'Added via XML import (rev change on ' . $docNo . ')');
            }
            // Link the existing document to the item now (the rev change
            // is pending the ECN going effective).
            doc_link_entity($existingId, 'inv_item', (int)$itemId,
                'Linked via XML import; rev change ' . $rev . ' pending ECN', $uid);
            $res['linked']++;
            $res['ecns']++;
            $ecnRow = db_one("SELECT ecn_no FROM ecns WHERE id = ?", [(int)$ecnId]);
            $res['notes'][] = "ECN " . ($ecnRow['ecn_no'] ?? "#$ecnId") . " drafted for {$docNo} rev → {$rev}";
            continue;
        }

        // LINK paths -------------------------------------------------
        if ($klass === 'link' || $action === 'link_existing' || $action === 'link') {
            if ($existingId) {
                doc_link_entity($existingId, 'inv_item', (int)$itemId,
                    'Linked via XML import (' . $docNo . ' Rev ' . $rev . ')', $uid);
                $res['linked']++;
                $res['notes'][] = "linked {$docNo}";
                continue;
            }
            // No existing id but asked to link — nothing to do.
            $res['skipped']++;
            continue;
        }

        // SKIP -------------------------------------------------------
        if ($action === 'skip') {
            $res['skipped']++;
            continue;
        }

        // UPLOAD -----------------------------------------------------
        if ($action === 'upload') {
            // The uploaded file arrives in a separate clean array named
            // docfile[$partIdx][$docIdx] (see imp_extract_doc_file for
            // why we don't use a docs[..][..]_file suffix form).
            $file = imp_extract_doc_file($partIdx, $docIdx);
            if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                $res['skipped']++;
                $res['notes'][] = "no file for {$docNo} — skipped";
                continue;
            }
            $catId = imp_external_doc_category_id($docCategoryId);
            if (!$catId) {
                $res['skipped']++;
                $res['notes'][] = "no external doc category — {$docNo} skipped";
                continue;
            }
            // Create the document shell.
            $code = function_exists('doc_next_code') ? doc_next_code($catId) : ('DOC-' . date('YmdHis'));
            db_exec(
                "INSERT INTO documents
                    (code, doc_no, title, category_id, kind, status, owner_id,
                     external_ref, description, created_by, updated_by)
                 VALUES (?, ?, ?, ?, 'external', 'received', ?, ?, ?, ?, ?)",
                [$code, $docNo, ($desc !== '' ? mb_substr($desc, 0, 250) : $docNo),
                 $catId, $uid, $docNo, $desc, $uid, $uid]
            );
            $docDbId = (int)db()->lastInsertId();
            doc_history_append($docDbId, 'created', null, 'received',
                null, "Created via XML import for inv item #{$itemId}", $uid);

            // Store the uploaded file as the initial revision.
            $stored = imp_store_doc_file($file, $docDbId);
            $revLabel = $rev !== '' ? $rev : 'A';
            $revId = doc_add_revision($docDbId, $revLabel, 'release',
                $stored, 'Initial revision from XML import', $uid);
            if (function_exists('doc_set_current_rev')) {
                doc_set_current_rev($docDbId, $revId);
            }
            // Link to the item.
            doc_link_entity($docDbId, 'inv_item', (int)$itemId,
                'Created & linked via XML import (' . $docNo . ' Rev ' . $revLabel . ')', $uid);
            $res['created']++;
            $res['linked']++;
            $res['notes'][] = "created+linked {$docNo} ({$code})";
            continue;
        }

        // Unknown action — skip defensively.
        $res['skipped']++;
    }
    return $res;
}

/**
 * Extract an uploaded document file for part/doc indices from the
 * $_FILES structure. File inputs are named docfile[P][D] (a clean,
 * separate top-level array — NOT docs[P][D] which carries the text
 * fields, and NOT a bracket+suffix form like docs[P][D]_file which PHP
 * silently mangles by dropping the suffix). PHP nests this as
 * $_FILES['docfile']['name'][P][D] etc. Returns a flat
 * {name,type,tmp_name,error,size} array or null.
 */
function imp_extract_doc_file($partIdx, $docIdx) {
    if (empty($_FILES['docfile']) || !is_array($_FILES['docfile'])) return null;
    $f = $_FILES['docfile'];
    if (!isset($f['name'][$partIdx][$docIdx])) return null;
    $name = $f['name'][$partIdx][$docIdx];
    if ($name === '' || $name === null) return null;
    return [
        'name'     => $name,
        'type'     => $f['type'][$partIdx][$docIdx]     ?? '',
        'tmp_name' => $f['tmp_name'][$partIdx][$docIdx] ?? '',
        'error'    => $f['error'][$partIdx][$docIdx]    ?? UPLOAD_ERR_NO_FILE,
        'size'     => $f['size'][$partIdx][$docIdx]     ?? 0,
    ];
}

/**
 * Store an uploaded document file under uploads/documents/<docId>/ and
 * return file metadata for doc_add_revision(). Mirrors
 * doc_store_uploaded_file() but works from a flat file array (since our
 * field names are bracket-mangled and don't fit that helper's slot API).
 */
function imp_store_doc_file($file, $docId) {
    $base = dirname(__DIR__) . '/uploads/documents/' . (int)$docId;
    if (!is_dir($base) && !@mkdir($base, 0775, true)) return null;
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$file['name']);
    if ($clean === '' || $clean === '.') $clean = 'document';
    $hex = bin2hex(random_bytes(6));
    $relDir = 'documents/' . (int)$docId;
    $rel = $relDir . '/' . $hex . '_' . $clean;
    $dest = dirname(__DIR__) . '/uploads/' . $rel;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        if (!@copy($file['tmp_name'], $dest)) return null;
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)finfo_file($fi, $dest); finfo_close($fi); }
    }
    if ($mime === '') $mime = (string)$file['type'];
    $hash = @hash_file('sha256', $dest) ?: null;
    return [
        'name' => $clean,
        'path' => $rel,
        'size' => (int)@filesize($dest),
        'mime' => $mime,
        'hash' => $hash,
    ];
}

/**
 * Store an uploaded file as an ECN pending-revision file under
 * uploads/ecn/<ecnId>/. Mirrors ecn_store_pending_file() but works from
 * a flat file array (our docfile[P][D] inputs are extracted manually).
 * Returns {name, path, size, mime, hash} ready for the ecns.pending_file_*
 * columns, or null. The 'path' is relative to the app root (includes the
 * leading 'uploads/') to match how ecn_store_pending_file() stores it.
 */
function imp_store_ecn_pending_file($file, $ecnId) {
    $base = dirname(__DIR__) . '/uploads/ecn/' . (int)$ecnId;
    if (!is_dir($base) && !@mkdir($base, 0775, true)) return null;
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$file['name']);
    if ($clean === '' || $clean === '.') $clean = 'revision';
    $fname = 'pending_' . time() . '_' . $clean;
    $dest = $base . '/' . $fname;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        if (!@copy($file['tmp_name'], $dest)) return null;
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)finfo_file($fi, $dest); finfo_close($fi); }
    }
    if ($mime === '') $mime = (string)($file['type'] ?: 'application/octet-stream');
    return [
        'name' => $file['name'],
        'path' => 'uploads/ecn/' . (int)$ecnId . '/' . $fname,
        'size' => (int)@filesize($dest),
        'mime' => $mime,
        'hash' => @hash_file('sha256', $dest) ?: null,
    ];
}

/**
 * Resolve the running_notes 'update' category id (used to tag the
 * import-generated note). Returns id or null (note posts uncategorized
 * if missing — still valid).
 */
function imp_note_type_id() {
    static $cached = false;
    static $val = null;
    if ($cached) return $val;
    $cached = true;
    $row = db_one("SELECT id FROM categories WHERE type = 'running_notes' AND code = 'update' AND is_active = 1 LIMIT 1");
    $val = $row ? (int)$row['id'] : null;
    return $val;
}

/**
 * Create a Running Note attached to an inventory item, carrying the
 * concatenated XML field data. Replaces the old behaviour of stuffing
 * everything into inv_items.notes.
 *
 * The note is stored against entity_type='inv_item', entity_id=<item id>
 * so it surfaces in the item's Running Notes panel and in the global
 * Running Notes list, linked back to the inv code.
 *
 * @param int    $itemId   inv_items.id
 * @param string $code     inv_items.code (for the note heading)
 * @param array  $part     parsed part array (extras / misc_notes / file_warnings)
 * @return int|null        new note id, or null if there was nothing to add
 */
function imp_create_running_note($itemId, $code, $part) {
    // Build the note body as safe HTML. We escape every value and use
    // simple markup (headings + lists) so it renders cleanly in the
    // notes panel. No user-supplied HTML passes through unescaped.
    $sections = [];

    if (!empty($part['extras'])) {
        $rows = '';
        foreach ($part['extras'] as $k => $v) {
            $rows .= '<li><strong>' . h($k) . ':</strong> ' . h($v) . '</li>';
        }
        $sections[] = '<p><strong>XML import &mdash; additional fields</strong></p><ul>' . $rows . '</ul>';
    }
    if (!empty($part['misc_notes'])) {
        $rows = '';
        foreach ($part['misc_notes'] as $m) {
            $rows .= '<li>' . h($m) . '</li>';
        }
        $sections[] = '<p><strong>Misc notes</strong></p><ul>' . $rows . '</ul>';
    }
    if (!empty($part['file_warnings'])) {
        $rows = '';
        foreach ($part['file_warnings'] as $w) {
            $rows .= '<li>' . h($w) . '</li>';
        }
        $sections[] = '<p><strong>Warnings</strong></p><ul>' . $rows . '</ul>';
    }

    // Always create the note — even when there are no extra fields,
    // misc notes, or warnings — because the note is also the host for
    // the attached XML file. A bare note still records the import event.
    $heading = '<p><em>Imported from XML for ' . h($code) . ' on ' . h(date('Y-m-d H:i')) . '</em></p>';
    $body = $heading . ($sections ? implode('', $sections) : '<p class="muted">No additional fields in the source XML.</p>');

    db_exec(
        'INSERT INTO notes (entity_type, entity_id, note_type_id, body_html, author_id) VALUES (?, ?, ?, ?, ?)',
        ['inv_item', (int)$itemId, imp_note_type_id(), $body, (int)current_user_id()]
    );
    return (int)db_val('SELECT LAST_INSERT_ID()', [], 0);
}

/**
 * Generate the next inventory code.
 *
 * Uses the admin-managed code_sequences row named 'inv_item' via
 * code_next() — the SAME source the Code Sequences admin page edits.
 * This is what makes the prefix configurable (e.g. switching I- to P-
 * in the admin page takes effect here too).
 *
 * Falls back to the legacy $APP['inv_id'] scan only if code_next() is
 * unavailable (very old installs without the code_sequences table).
 *
 * IMPORTANT: call this INSIDE the commit transaction, once per insert.
 * code_next() scans the target table for the current max and retries on
 * clash, so sequential calls within one request return distinct codes.
 */
function imp_inv_code_next() {
    if (function_exists('code_next')) {
        $code = code_next('inv_item');
        if (is_string($code) && $code !== '') return $code;
    }
    // ---- Legacy fallback (no code_sequences support) ----
    $cfg = $GLOBALS['APP']['inv_id'] ?? ['prefix' => 'I-', 'pad' => 5, 'start' => 1];
    $prefix = (string)$cfg['prefix'];
    $pad    = (int)$cfg['pad'];
    $start  = (int)$cfg['start'];

    $rows = db_all(
        "SELECT code FROM inv_items WHERE code LIKE ? ORDER BY id DESC LIMIT 50",
        [$prefix . '%']
    );
    $max = $start - 1;
    foreach ($rows as $r) {
        $suffix = substr($r['code'], strlen($prefix));
        if (ctype_digit($suffix)) {
            $n = (int)$suffix;
            if ($n > $max) $max = $n;
        }
    }
    $next = $max + 1;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = $prefix . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
        $clash = db_one('SELECT id FROM inv_items WHERE code = ?', [$candidate]);
        if (!$clash) return $candidate;
        $next++;
    }
    return $prefix . date('YmdHis');
}

/**
 * Map XML uom values to inv_uom rows. Looks up the inv_uom table by
 * code. Common XML values like 'EA' may not exist as a code in your
 * inv_uom seed (which typically has 'pcs', 'kg', 'm', etc.) — the
 * helper applies a small alias map first.
 *
 * Returns the inv_uom.id or null if no match.
 */
function imp_resolve_uom_id($xmlUom) {
    $xmlUom = strtoupper(trim((string)$xmlUom));
    if ($xmlUom === '') return null;
    // Common XML-to-MagDyn aliases. Add to this table as new XML feeds
    // appear with different conventions.
    $aliases = [
        'EA'  => ['EA', 'PCS', 'NOS', 'NO'],
        'KG'  => ['KG'],
        'G'   => ['G', 'GM'],
        'M'   => ['M', 'MTR'],
        'MM'  => ['MM'],
        'L'   => ['L', 'LTR'],
        'ML'  => ['ML'],
        'SET' => ['SET', 'SETS'],
        'PR'  => ['PR', 'PAIR'],
        'BOX' => ['BOX', 'BX'],
    ];
    $tryCodes = [$xmlUom];
    foreach ($aliases as $candidate => $variants) {
        if (in_array($xmlUom, $variants, true)) {
            $tryCodes = array_merge([$candidate], $variants);
            break;
        }
    }
    foreach ($tryCodes as $code) {
        $row = db_one("SELECT id FROM inv_uom WHERE UPPER(code) = ? AND is_active = 1 LIMIT 1",
                      [strtoupper($code)]);
        if ($row) return (int)$row['id'];
    }
    return null;
}

/**
 * Parse the XML file at the given path into a flat array of part records.
 *
 * Returns:
 *   ['parts' => [ ['mapped' => [...], 'extras' => [...], 'warnings' => [...]], ... ],
 *    'doc_warnings' => [...]]
 *
 * Each part contains:
 *   - mapped:  associative array of fields that map directly to inv_items columns
 *   - extras:  associative array of OTHER non-empty fields (will be folded into notes)
 *   - notes:   misc_notes + part_warnings concatenated into a single string
 *   - documents_bom: array of attached-doc rows (NOT imported yet — Delta 2)
 *   - warnings: parsing notes per-part (e.g. UoM couldn't be resolved)
 *
 * The mapping is deliberate and per-user-spec — DO NOT silently add
 * more fields here; new mappings require an explicit decision because
 * they have a schema impact.
 */
function imp_parse_xml_inv_items($path) {
    libxml_use_internal_errors(true);
    $doc = @simplexml_load_file($path);
    if ($doc === false) {
        $errs = libxml_get_errors();
        libxml_clear_errors();
        $msg = $errs ? trim($errs[0]->message) : 'unknown XML parse error';
        throw new \RuntimeException("Could not parse XML: $msg");
    }

    // The root element must be <part_report>. Some source systems wrap
    // a single part in part_report; others may emit a list — we handle
    // both transparently by treating every <part_record> we find as
    // one item.
    $rootName = $doc->getName();
    if ($rootName !== 'part_report') {
        throw new \RuntimeException("Unexpected root element <$rootName> — expected <part_report>.");
    }

    $parts = [];

    // The XML structure puts part_record + view_bom + documents_bom +
    // notes_and_specs all as siblings under part_report. For a single
    // part XML there's exactly one part_record. We accept multiple
    // part_records too (some sources concatenate).
    foreach ($doc->part_record as $pr) {
        $part = [
            'mapped'   => [],
            'extras'   => [],
            'misc_notes' => [],
            'documents_bom' => [],
            'warnings' => [],
        ];

        // ----- Mapped fields per user's Delta-1 spec --------------
        $obj_name    = trim((string)$pr->obj_name);
        $obj_desc    = trim((string)$pr->obj_desc);
        $rev         = trim((string)$pr->rev);
        $uom_text    = trim((string)$pr->uom);
        $model       = trim((string)$pr->model);
        $drawing_rev = trim((string)$pr->drawing_rev);
        $ecn         = trim((string)$pr->ecn);

        if ($obj_name === '') {
            $part['warnings'][] = 'obj_name (part number) is empty — this part will be skipped on commit.';
        }

        // Field mapping aligned with the app's own convention (see
        // includes/inventory/items.php item_save): the legacy `name`
        // column mirrors `short_description`; the full descriptive text
        // lives in `long_description`. So:
        //   short_description = "<part_no> Rev-<rev>"   (per spec)
        //   name              = short_description        (app convention)
        //   long_description  = obj_desc                 (the full text)
        $shortDesc = $rev !== '' ? $obj_name . ' Rev-' . $rev : $obj_name;
        $part['mapped']['part_no']           = $obj_name;
        $part['mapped']['short_description'] = $shortDesc;
        $part['mapped']['name']              = $shortDesc;   // mirror, matches item_save
        $part['mapped']['long_description']  = $obj_desc;    // full descriptive text
        $part['mapped']['part_rev_no']       = $rev;
        $part['mapped']['dwg_no']            = $model;
        $part['mapped']['dwg_rev_no']        = $drawing_rev;
        $part['mapped']['ecn']               = $ecn;

        // UoM resolution: look up inv_uom.code. If not found, record a
        // warning and leave uom_id NULL — the operator can fix the item
        // after import or seed the UoM and re-run.
        $uom_id = null;
        if ($uom_text !== '') {
            $uom_id = imp_resolve_uom_id($uom_text);
            if ($uom_id === null) {
                $part['warnings'][] = "UoM '$uom_text' has no matching active row in inv_uom — uom_id will be left NULL on import. Add the UoM under Admin &middot; UoMs and re-run, or set it manually after import.";
            }
        }
        $part['mapped']['uom_id'] = $uom_id;
        $part['mapped']['uom_text_from_xml'] = $uom_text;  // for the preview UI only

        // ----- Everything ELSE in part_record goes into "extras" --
        // (gets folded into notes column). Skip the fields we already
        // mapped above and the swarm of always-empty source-system
        // placeholders the user opted out of.
        $skip = [
            'obj_name','obj_desc','rev','uom','model','drawing_rev','ecn',
        ];
        foreach ($pr->children() as $child) {
            $tag = $child->getName();
            if (in_array($tag, $skip, true)) continue;
            $val = trim((string)$child);
            if ($val === '' || strtoupper($val) === 'NA' || strtoupper($val) === 'UNSET') continue;
            $part['extras'][$tag] = $val;
        }

        $parts[] = $part;
    }

    // misc_notes + part_warnings (file-level — apply to all parts in
    // this file; for single-part imports this is the typical case)
    $notes_block = $doc->notes_and_specs;
    $misc = [];
    if ($notes_block && $notes_block->misc_notes) {
        foreach ($notes_block->misc_notes->misc_note as $mn) {
            $t = trim((string)$mn);
            if ($t !== '') $misc[] = $t;
        }
    }
    $warnings_file = [];
    if ($notes_block && (string)$notes_block->part_warning !== '') {
        $warnings_file[] = trim((string)$notes_block->part_warning);
    }
    if ((string)$doc->part_warning !== '') {
        $warnings_file[] = trim((string)$doc->part_warning);
    }

    // documents_bom — capture refs for the preview UI; NOT imported.
    $documents = [];
    if ($doc->documents_bom) {
        foreach ($doc->documents_bom->documents_bom_item as $di) {
            $documents[] = [
                'seq'    => trim((string)$di->documents_bom_seq),
                'name'   => trim((string)$di->documents_bom_name),
                'rev'    => trim((string)$di->documents_bom_revision_id),
                'status' => trim((string)$di->documents_bom_status),
                'desc'   => trim((string)$di->documents_bom_desc),
            ];
        }
    }

    // Attach file-level misc/warnings/documents to every part. (For
    // a multi-part XML where each part has its own misc, the source
    // system would put misc inside part_record — this code path would
    // need adjustment. None of our samples do that yet.)
    foreach ($parts as &$p) {
        $p['misc_notes']    = $misc;
        $p['file_warnings'] = $warnings_file;
        $p['documents_bom'] = $documents;
    }
    unset($p);

    return [
        'parts'        => $parts,
        'doc_warnings' => $warnings_file,
    ];
}

/**
 * Assemble the consolidated notes string for an item:
 *   - extras (key: value, one per line)
 *   - misc_notes (one per line)
 *   - file_warnings (each prefixed "WARNING:")
 */
function imp_assemble_notes($part) {
    $lines = [];
    if (!empty($part['extras'])) {
        $lines[] = '--- XML import: additional fields ---';
        foreach ($part['extras'] as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
    }
    if (!empty($part['misc_notes'])) {
        $lines[] = '--- XML import: misc notes ---';
        foreach ($part['misc_notes'] as $m) {
            $lines[] = $m;
        }
    }
    if (!empty($part['file_warnings'])) {
        $lines[] = '--- XML import: warnings ---';
        foreach ($part['file_warnings'] as $w) {
            $lines[] = 'WARNING: ' . $w;
        }
    }
    return $lines ? implode("\n", $lines) : null;
}

// ============================================================
// XML inv_items: upload form
// ============================================================
if ($action === 'xml_inv_items') {
    require_permission('import', 'xml_inv_items');

    $page_title  = 'Import &middot; XML Inventory Items';
    $page_module = 'import';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/import.php'),
        'back_label' => 'Back to import',
        'title'      => 'XML — Inventory Items',
        'subtitle'   => 'Step 1 of 2 — upload',
    ]) ?>
    <div style="padding: 22px; max-width: 760px;">
        <p class="muted" style="line-height:1.6;">
            Upload a <code>&lt;part_report&gt;</code> XML file containing one or more
            <code>&lt;part_record&gt;</code> entries. The next step shows a preview of
            what would be inserted or updated before anything is committed.
        </p>

        <?php $divisions = db_all("SELECT id, name FROM categories WHERE type = 'division' AND is_active = 1 ORDER BY sort_order, name"); ?>
        <form method="post" action="<?= h(url('/import.php?action=xml_inv_items_preview')) ?>" enctype="multipart/form-data" style="margin-top: 18px;">
            <?= csrf_field() ?>
            <div style="border:1px dashed var(--border);border-radius:8px;padding:24px;background:var(--surface-alt);">
                <label style="display:block;font-weight:600;margin-bottom:8px;">XML file</label>
                <input type="file" name="xml_file" accept=".xml,application/xml,text/xml" required
                       style="display:block;width:100%;padding:8px;background:white;border:1px solid var(--border);border-radius:4px;">
                <p class="muted small" style="margin:8px 0 0;">Max 8 MB. UTF-8 or ASCII. Multiple <code>&lt;part_record&gt;</code> entries supported.</p>
            </div>
            <div style="margin-top:16px;">
                <label for="division_id" style="display:block;font-weight:600;margin-bottom:8px;">Division <span style="color:#dc2626;">*</span></label>
                <select id="division_id" name="division_id" required
                        style="display:block;width:100%;max-width:360px;padding:8px;background:white;border:1px solid var(--border);border-radius:4px;">
                    <option value="">— Select division —</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="muted small" style="margin:8px 0 0;">Applied to every item in this import. Division is required on inventory items, so it must be chosen here.</p>
                <?php if (!$divisions): ?>
                    <p class="small" style="margin:8px 0 0;color:#991b1b;">No divisions are defined. Seed at least one (Admin &middot; Categories, type=division) before importing.</p>
                <?php endif; ?>
            </div>
            <div style="margin-top:16px;">
                <?php $extDocCats = db_all("SELECT id, name, prefix FROM doc_categories WHERE kind = 'external' AND is_active = 1 ORDER BY sort_order, name"); ?>
                <label for="doc_category_id" style="display:block;font-weight:600;margin-bottom:8px;">Document category (for attached documents)</label>
                <select id="doc_category_id" name="doc_category_id"
                        style="display:block;width:100%;max-width:360px;padding:8px;background:white;border:1px solid var(--border);border-radius:4px;">
                    <?php foreach ($extDocCats as $dc): ?>
                        <option value="<?= (int)$dc['id'] ?>"<?= ($dc['name'] === 'Customer Specification' ? ' selected' : '') ?>>
                            <?= h($dc['name']) ?> (<?= h($dc['prefix']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted small" style="margin:8px 0 0;">Category used when the importer creates a NEW external document from an attached doc. Existing documents keep their own category.</p>
                <?php if (!$extDocCats): ?>
                    <p class="small" style="margin:8px 0 0;color:#991b1b;">No external document categories exist. Documents can't be created until one is seeded.</p>
                <?php endif; ?>
            </div>
            <div style="margin-top:18px;display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary"<?= $divisions ? '' : ' disabled' ?>>Parse &amp; preview &rarr;</button>
                <a href="<?= h(url('/import.php')) ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>

        <details style="margin-top:24px;">
            <summary style="cursor:pointer;font-weight:600;color:var(--text);font-size:13px;">What this importer does</summary>
            <div class="muted small" style="margin-top:10px;line-height:1.7;">
                <p><strong>Mapped to inv_items columns:</strong></p>
                <ul style="margin:6px 0 10px 18px;">
                    <li><code>code</code> &rarr; <strong>auto-generated</strong> from the system sequence (e.g. I-00001) — NOT taken from the XML</li>
                    <li><code>obj_name</code> &rarr; <code>part_no</code></li>
                    <li><code>obj_desc</code> &rarr; <code>name</code></li>
                    <li><code>obj_name</code> + <code>rev</code> &rarr; <code>short_description</code> (format: "P4000132451 Rev-A")</li>
                    <li><code>rev</code> &rarr; <code>part_rev_no</code></li>
                    <li><code>uom</code> &rarr; <code>uom_id</code> (looked up by inv_uom.code with common aliases)</li>
                    <li><code>model</code> &rarr; <code>dwg_no</code></li>
                    <li><code>drawing_rev</code> &rarr; <code>dwg_rev_no</code></li>
                    <li><code>ecn</code> &rarr; <code>ecn</code></li>
                </ul>
                <p><strong>Added as a Running Note</strong> on the item (linked to its inv code): any other non-empty, non-NA, non-UNSET field
                    from part_record; misc_notes; part_warning blobs. The item's own <code>notes</code> field is left untouched.</p>
                <p><strong>Not imported (planned for Delta 2):</strong> <code>documents_bom</code> attached document references — will go into a future <code>inv_item_documents</code> table.</p>
                <p><strong>Match key for update-vs-insert:</strong> <code>inv_items.part_no</code> + <code>part_rev_no</code> together (= XML's <code>obj_name</code> + <code>rev</code>). A new revision of an existing part imports as a separate item with its own code. Re-importing the same part &amp; revision updates that row in place (its system code is kept).</p>
            </div>
        </details>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// XML inv_items: parse + preview
// ============================================================
if ($action === 'xml_inv_items_preview') {
    require_permission('import', 'xml_inv_items');
    csrf_check();

    // Division is chosen on the upload form and applied to every item.
    $divisionId = (int)input('division_id', 0);
    if ($divisionId <= 0) {
        flash_set('error', 'Please select a division before importing.');
        redirect(url('/import.php?action=xml_inv_items'));
    }
    $divRow = db_one("SELECT id, name FROM categories WHERE id = ? AND type = 'division' AND is_active = 1", [$divisionId]);
    if (!$divRow) {
        flash_set('error', 'The selected division is invalid or inactive.');
        redirect(url('/import.php?action=xml_inv_items'));
    }

    // Document category for any NEW external documents the importer
    // creates. Optional — falls back to the resolver default if unset.
    $docCategoryId = (int)input('doc_category_id', 0) ?: null;
    $docCatRow = null;
    if ($docCategoryId) {
        $docCatRow = db_one("SELECT id, name FROM doc_categories WHERE id = ? AND kind = 'external' AND is_active = 1", [$docCategoryId]);
        if (!$docCatRow) {
            flash_set('error', 'The selected document category is invalid or inactive.');
            redirect(url('/import.php?action=xml_inv_items'));
        }
    }

    if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'No file uploaded or upload failed (error code ' . ($_FILES['xml_file']['error'] ?? '?') . ').');
        redirect(url('/import.php?action=xml_inv_items'));
    }
    $tmp = $_FILES['xml_file']['tmp_name'];
    $origName = $_FILES['xml_file']['name'] ?? 'upload.xml';
    if (!is_uploaded_file($tmp)) {
        flash_set('error', 'Uploaded file did not pass safety check.');
        redirect(url('/import.php?action=xml_inv_items'));
    }
    if (filesize($tmp) > 8 * 1024 * 1024) {
        flash_set('error', 'XML file is larger than 8 MB. Split into smaller files and re-upload.');
        redirect(url('/import.php?action=xml_inv_items'));
    }

    try {
        $parsed = imp_parse_xml_inv_items($tmp);
    } catch (\Throwable $e) {
        flash_set('error', 'Parse failed: ' . $e->getMessage());
        redirect(url('/import.php?action=xml_inv_items'));
    }

    // Persist the uploaded XML now (while it's still a valid upload) so
    // it survives to the commit step, where it gets attached to the
    // running note(s) created/updated for the imported item(s).
    $xmlMeta = imp_persist_xml_upload($tmp, $origName);
    if ($xmlMeta === null) {
        flash_set('error', 'Could not store the uploaded XML for attachment. Check uploads/notes is writable.');
        redirect(url('/import.php?action=xml_inv_items'));
    }

    // Determine insert vs update for each part by checking
    // inv_items.(part_no, part_rev_no). Code is auto-generated, so it
    // can't be the match key.
    //
    // We also track (part_no, part_rev_no) pairs seen EARLIER in this
    // same file. With the DB-level UNIQUE constraint, two records in one
    // file carrying the same part+rev would collide on commit. So the
    // first occurrence is processed normally and any later duplicate in
    // the same file is flagged skip with a clear reason — preventing a
    // mid-transaction unique-key failure that would roll back the batch.
    $seenInFile = [];
    foreach ($parsed['parts'] as $idx => &$p) {
        $partNo = $p['mapped']['part_no'];
        if ($partNo === '') {
            $p['op'] = 'skip';
            continue;
        }
        $partRev = $p['mapped']['part_rev_no'];
        $pairKey = $partNo . "\x1f" . ($partRev !== '' ? $partRev : '');
        if (isset($seenInFile[$pairKey])) {
            $p['op'] = 'skip';
            $p['warnings'][] = 'Duplicate of an earlier record in this same file (same part number + revision "'
                             . ($partRev !== '' ? $partRev : '∅') . '"). Only the first occurrence is imported.';
            continue;
        }
        $seenInFile[$pairKey] = true;

        // Duplicate check against the DB is on (part_no, part_rev_no)
        // together. A new revision of the same part is therefore a NEW
        // item (insert), not an overwrite — each revision gets its own
        // inv_items row and its own system code. Re-importing the SAME
        // part_no+rev updates that specific row in place.
        $existing = db_one(
            "SELECT id, code, name, short_description, part_no, part_rev_no, uom_id, dwg_no, dwg_rev_no, ecn, notes
               FROM inv_items
              WHERE part_no = ?
                AND COALESCE(part_rev_no, '') = COALESCE(?, '')
              ORDER BY id DESC LIMIT 1",
            [$partNo, ($partRev !== '' ? $partRev : null)]
        );
        if ($existing) {
            $p['op'] = 'update';
            $p['existing_id'] = (int)$existing['id'];
            $p['existing_code'] = $existing['code'];  // shown in preview so operator sees the kept code
            // Compute a diff so the operator sees what will actually change.
            $p['diff'] = [];
            $candidate = [
                'name'              => $p['mapped']['name'],
                'short_description' => $p['mapped']['short_description'],
                'part_no'           => $p['mapped']['part_no'],
                'part_rev_no'       => $p['mapped']['part_rev_no'],
                'uom_id'            => $p['mapped']['uom_id'],
                'dwg_no'            => $p['mapped']['dwg_no'],
                'dwg_rev_no'        => $p['mapped']['dwg_rev_no'],
                'ecn'               => $p['mapped']['ecn'],
            ];
            foreach ($candidate as $col => $newVal) {
                $oldVal = $existing[$col];
                // Treat empty strings and NULL as equivalent for diff purposes
                $a = ($oldVal === null || $oldVal === '') ? '' : (string)$oldVal;
                $b = ($newVal === null || $newVal === '') ? '' : (string)$newVal;
                if ($a !== $b) {
                    $p['diff'][$col] = ['before' => $oldVal, 'after' => $newVal];
                }
            }
        } else {
            $p['op'] = 'insert';
        }

        // Classify the part's documents_bom against the external-docs
        // register (link / rev_change / upload). Stored on the part so
        // the preview can render per-doc controls and the commit can act.
        $p['docs'] = imp_classify_documents($p);
    }
    unset($p);

    // Stash parsed payload in session for the commit step. We strip
    // SimpleXML objects (already done — parse returns plain arrays) so
    // the payload serializes cleanly.
    $token = bin2hex(random_bytes(16));
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['import_xml_inv_items'][$token] = [
        'parsed'      => $parsed,
        'origName'    => $origName,
        'division_id' => $divisionId,
        'division_nm' => $divRow['name'],
        'doc_category_id' => $docCategoryId,
        'xml_meta'    => $xmlMeta,
        'created_at'  => time(),
        'uid'         => (int)current_user_id(),
    ];
    // Keep only the 5 most-recent payloads per user to avoid session bloat.
    if (count($_SESSION['import_xml_inv_items']) > 5) {
        $all = $_SESSION['import_xml_inv_items'];
        uasort($all, function($a, $b) { return $b['created_at'] - $a['created_at']; });
        $_SESSION['import_xml_inv_items'] = array_slice($all, 0, 5, true);
    }

    // Counts for the summary header.
    $cnt_insert = 0; $cnt_update = 0; $cnt_skip = 0; $cnt_warn = 0;
    foreach ($parsed['parts'] as $p) {
        if ($p['op'] === 'insert') $cnt_insert++;
        elseif ($p['op'] === 'update') $cnt_update++;
        else $cnt_skip++;
        if (!empty($p['warnings'])) $cnt_warn++;
    }

    $page_title  = 'Import &middot; XML Inventory Items &middot; Preview';
    $page_module = 'import';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/import.php?action=xml_inv_items'),
        'back_label' => 'Upload a different file',
        'title'      => 'XML Inventory Items',
        'subtitle'   => 'Step 2 of 2 — preview &amp; commit',
    ]) ?>
    <div style="padding: 18px 22px;">
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
            <div style="flex:1;min-width:140px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#f0fdf4;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#166534;">New items</div>
                <div style="font-size:22px;font-weight:600;color:#166534;"><?= $cnt_insert ?></div>
            </div>
            <div style="flex:1;min-width:140px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#eef2ff;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#3730a3;">Updates</div>
                <div style="font-size:22px;font-weight:600;color:#3730a3;"><?= $cnt_update ?></div>
            </div>
            <?php if ($cnt_skip): ?>
            <div style="flex:1;min-width:140px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#fef2f2;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#991b1b;">Skipped</div>
                <div style="font-size:22px;font-weight:600;color:#991b1b;"><?= $cnt_skip ?></div>
            </div>
            <?php endif; ?>
            <?php if ($cnt_warn): ?>
            <div style="flex:1;min-width:140px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#fef3c7;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#92400e;">With warnings</div>
                <div style="font-size:22px;font-weight:600;color:#92400e;"><?= $cnt_warn ?></div>
            </div>
            <?php endif; ?>
        </div>

        <p class="muted small" style="margin-bottom:14px;">From <code><?= h($origName) ?></code> &middot; <?= count($parsed['parts']) ?> part record<?= count($parsed['parts']) === 1 ? '' : 's' ?> parsed.</p>
        <?php $finshdId = imp_finshd_category_id(); ?>
        <?php if ($finshdId): ?>
            <p class="muted small" style="margin-bottom:14px;">All items will be filed under category <strong>Finished Good</strong> (finshd), division <strong><?= h($divRow['name']) ?></strong>, manufacturer type <strong>Internal</strong>.<?php if (!empty($docCatRow)): ?> New documents created under <strong><?= h($docCatRow['name']) ?></strong>.<?php endif; ?></p>
        <?php else: ?>
            <div style="margin-bottom:14px;padding:10px 12px;background:#fef3c7;border-left:3px solid #d97706;border-radius:4px;font-size:12.5px;color:#78350f;">
                ⚠ The <code>finshd</code> (Finished Good) inventory category was not found. Items will import with no category set — you'll need to assign one manually, or seed the category first.
            </div>
        <?php endif; ?>

        <?php
        // The whole card list is inside the commit form so each UPDATE
        // card's replace/add radio submits along with the Commit button.
        $hasCommittable = ($cnt_insert + $cnt_update) > 0;
        ?>
        <form method="post" action="<?= h(url('/import.php?action=xml_inv_items_commit')) ?>" id="jc-commit-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <?php foreach ($parsed['parts'] as $idx => $p): ?>
            <?php
                $opColor = $p['op'] === 'insert' ? '#16a34a' : ($p['op'] === 'update' ? '#3730a3' : '#dc2626');
                $opBg    = $p['op'] === 'insert' ? '#f0fdf4' : ($p['op'] === 'update' ? '#eef2ff' : '#fef2f2');
                $opLabel = $p['op'] === 'insert' ? 'INSERT' : ($p['op'] === 'update' ? 'UPDATE' : 'SKIP');
                $partNo  = $p['mapped']['part_no'];
            ?>
            <div style="border:1px solid var(--border);border-left:4px solid <?= $opColor ?>;border-radius:6px;padding:14px 18px;margin-bottom:12px;background:<?= $opBg ?>;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                    <span style="font-family:ui-monospace,Menlo,monospace;font-size:11px;font-weight:700;color:<?= $opColor ?>;padding:2px 8px;background:white;border-radius:3px;"><?= $opLabel ?></span>
                    <strong style="font-family:ui-monospace,Menlo,monospace;"><?= h($partNo ?: '(no part number)') ?></strong>
                    <?php if ($p['op'] === 'insert'): ?>
                        <span class="muted small">code: <em>auto-generated on commit</em></span>
                    <?php elseif ($p['op'] === 'update' && !empty($p['existing_code'])): ?>
                        <span class="muted small">code: <?= h($p['existing_code']) ?> (kept)</span>
                    <?php endif; ?>
                    <span class="muted small"><?= h(mb_substr($p['mapped']['name'] ?? '', 0, 100)) ?></span>
                </div>

                <?php if (!empty($p['warnings'])): ?>
                    <div style="margin:6px 0;padding:8px 10px;background:#fef3c7;border-left:3px solid #d97706;border-radius:3px;font-size:12px;color:#78350f;">
                        <?php foreach ($p['warnings'] as $w): ?>
                            <div>⚠ <?= h($w) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <table style="width:100%;font-size:12.5px;border-collapse:collapse;">
                    <tr style="background:rgba(255,255,255,0.5);">
                        <th style="text-align:left;padding:4px 8px;font-weight:600;font-size:11px;color:var(--text-light);text-transform:uppercase;letter-spacing:0.05em;width:140px;">FIELD</th>
                        <th style="text-align:left;padding:4px 8px;font-weight:600;font-size:11px;color:var(--text-light);text-transform:uppercase;letter-spacing:0.05em;">VALUE</th>
                    </tr>
                    <?php
                    $mappedDisplay = [
                        'part_no'           => 'Part No',
                        'name'              => 'Name',
                        'short_description' => 'Short Desc',
                        'part_rev_no'       => 'Part Rev',
                        'uom_id'            => 'UoM',
                        'dwg_no'            => 'Drawing No',
                        'dwg_rev_no'        => 'Drawing Rev',
                        'ecn'               => 'ECN',
                    ];
                    foreach ($mappedDisplay as $col => $label):
                        $val = $p['mapped'][$col] ?? '';
                        $isDiff = $p['op'] === 'update' && isset($p['diff'][$col]);
                        if ($col === 'uom_id') {
                            $val = $val !== null && $val !== '' ? 'id=' . $val . ' (from XML uom="' . h($p['mapped']['uom_text_from_xml']) . '")' : '(unresolved: "' . h($p['mapped']['uom_text_from_xml']) . '")';
                        }
                    ?>
                        <tr>
                            <td style="padding:4px 8px;color:var(--text-light);"><?= $label ?></td>
                            <td style="padding:4px 8px;<?= $isDiff ? 'background:#fff8e1;' : '' ?>">
                                <?php if ($isDiff): ?>
                                    <span class="muted" style="text-decoration:line-through;"><?= h($p['diff'][$col]['before'] ?? '(empty)') ?></span>
                                    &nbsp;&rarr;&nbsp;
                                    <strong><?= h(is_array($val) ? json_encode($val) : (string)$val) ?></strong>
                                <?php else: ?>
                                    <?= h(is_array($val) ? json_encode($val) : (string)$val) ?>
                                    <?php if ($val === '' || $val === null): ?><span class="muted">(empty)</span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php if ($p['op'] === 'update'): ?>
                    <?php $priorNotes = imp_prior_import_notes((int)$p['existing_id']); ?>
                    <div style="margin-top:10px;padding:10px 12px;background:white;border:1px solid var(--border);border-radius:4px;">
                        <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Import note for this update</div>
                        <?php if ($priorNotes): ?>
                            <p class="muted small" style="margin:0 0 8px;">This item already has <?= count($priorNotes) ?> prior XML-import note<?= count($priorNotes) === 1 ? '' : 's' ?> (with attachment<?= count($priorNotes) === 1 ? '' : 's' ?>). Choose what to do:</p>
                            <label style="display:block;font-size:12.5px;margin-bottom:4px;cursor:pointer;">
                                <input type="radio" name="note_action[<?= (int)$idx ?>]" value="add" checked>
                                Add a new note (keep the existing one — full history)
                            </label>
                            <label style="display:block;font-size:12.5px;cursor:pointer;">
                                <input type="radio" name="note_action[<?= (int)$idx ?>]" value="replace">
                                Replace the existing import note<?= count($priorNotes) === 1 ? '' : 's' ?> (old note + attachment removed, fresh one created)
                            </label>
                        <?php else: ?>
                            <p class="muted small" style="margin:0;">No prior import note on this item — a new one will be added with the XML attached.</p>
                            <input type="hidden" name="note_action[<?= (int)$idx ?>]" value="add">
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php
                $notesPreview = imp_assemble_notes($p);
                if ($notesPreview): ?>
                    <details style="margin-top:10px;">
                        <summary style="cursor:pointer;font-size:12px;color:var(--text-light);font-weight:600;">Notes preview (will be added as a Running Note on the item, linked to its inv code)</summary>
                        <pre style="margin:8px 0 0;padding:10px;background:white;border:1px solid var(--border);border-radius:4px;font-size:11.5px;line-height:1.5;white-space:pre-wrap;max-height:240px;overflow:auto;"><?= h($notesPreview) ?></pre>
                    </details>
                <?php endif; ?>

                <?php if (!empty($p['docs'])): ?>
                    <?php
                        $nLink = $nRev = $nUp = 0;
                        foreach ($p['docs'] as $dd) {
                            if ($dd['klass'] === 'link') $nLink++;
                            elseif ($dd['klass'] === 'rev_change') $nRev++;
                            else $nUp++;
                        }
                    ?>
                    <details style="margin-top:8px;" open>
                        <summary style="cursor:pointer;font-size:12px;color:var(--text-light);font-weight:600;">
                            Attached documents (<?= count($p['docs']) ?>) —
                            <span style="color:#166534;"><?= $nLink ?> link</span>,
                            <span style="color:#92400e;"><?= $nRev ?> rev-change</span>,
                            <span style="color:#3730a3;"><?= $nUp ?> upload</span>
                        </summary>
                        <div style="margin-top:8px;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($p['docs'] as $di => $d): ?>
                                <?php
                                    $klass = $d['klass'];
                                    $kColor = $klass === 'link' ? '#16a34a' : ($klass === 'rev_change' ? '#d97706' : '#3730a3');
                                    $kBg    = $klass === 'link' ? '#f0fdf4' : ($klass === 'rev_change' ? '#fffbeb' : '#eef2ff');
                                    $kLabel = $klass === 'link' ? 'LINK EXISTING' : ($klass === 'rev_change' ? 'REV CHANGE' : 'UPLOAD NEW');
                                    // Field-name prefix groups all controls for this part+doc.
                                    $fp = "docs[{$idx}][{$di}]";
                                ?>
                                <div style="border:1px solid var(--border);border-left:3px solid <?= $kColor ?>;border-radius:4px;padding:8px 10px;background:<?= $kBg ?>;font-size:12px;">
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                        <span style="font-family:ui-monospace,Menlo,monospace;font-size:10px;font-weight:700;color:<?= $kColor ?>;padding:1px 6px;background:white;border-radius:3px;"><?= $kLabel ?></span>
                                        <strong style="font-family:ui-monospace,Menlo,monospace;"><?= h($d['doc_no'] ?: '(no doc no)') ?></strong>
                                        <span class="muted">Rev <?= h($d['rev'] ?: '∅') ?></span>
                                        <span class="muted"><?= h(mb_substr($d['desc'], 0, 60)) ?></span>
                                    </div>

                                    <?php
                                    // Hidden metadata so commit can recreate the classification
                                    // without re-querying (and so the file inputs are scoped).
                                    ?>
                                    <input type="hidden" name="<?= h($fp) ?>[klass]"  value="<?= h($klass) ?>">
                                    <input type="hidden" name="<?= h($fp) ?>[doc_no]" value="<?= h($d['doc_no']) ?>">
                                    <input type="hidden" name="<?= h($fp) ?>[rev]"    value="<?= h($d['rev']) ?>">
                                    <input type="hidden" name="<?= h($fp) ?>[desc]"   value="<?= h($d['desc']) ?>">
                                    <?php if (!empty($d['existing_id'])): ?>
                                        <input type="hidden" name="<?= h($fp) ?>[existing_id]" value="<?= (int)$d['existing_id'] ?>">
                                    <?php endif; ?>

                                    <?php if ($klass === 'link'): ?>
                                        <div class="muted" style="margin-top:4px;">
                                            <?php if (!empty($d['rev_on_file'])): ?>
                                                Document <strong><?= h($d['existing_code']) ?></strong> already has a Rev <strong><?= h($d['rev']) ?></strong> on file (current is Rev <?= h($d['existing_rev']) ?>). No ECN needed &mdash; will link the existing document to this item.
                                            <?php else: ?>
                                                Matches existing <strong><?= h($d['existing_code']) ?></strong> (Rev <?= h($d['existing_rev']) ?>). Will link to this item.
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($klass === 'rev_change'): ?>
                                        <?php $canEcn = function_exists('permission_check') && permission_check('ecn', 'create'); ?>
                                        <div style="margin-top:6px;">
                                            <div class="muted" style="margin-bottom:4px;">
                                                Existing <strong><?= h($d['existing_code']) ?></strong> is at Rev <strong><?= h($d['existing_rev'] ?: '∅') ?></strong>, XML says Rev <strong><?= h($d['rev']) ?></strong>. Choose:
                                            </div>
                                            <?php if ($canEcn): ?>
                                            <label style="display:block;cursor:pointer;margin-bottom:2px;">
                                                <input type="radio" name="<?= h($fp) ?>[action]" value="ecn" checked>
                                                Create ECN for the rev change &amp; upload the new revision:
                                            </label>
                                            <input type="file" name="docfile[<?= (int)$idx ?>][<?= (int)$di ?>]"
                                                   style="display:block;margin:2px 0 6px 20px;font-size:11.5px;">
                                            <div class="muted small" style="margin:0 0 6px 20px;">
                                                Drafts a <em>drawing-revision ECN</em> against <?= h($d['existing_code']) ?> for Rev <?= h($d['rev']) ?>, stages this file, and marks this item as affected. The new revision is created when you make the ECN effective (ECN module).
                                            </div>
                                            <?php endif; ?>
                                            <label style="display:block;cursor:pointer;margin-bottom:2px;">
                                                <input type="radio" name="<?= h($fp) ?>[action]" value="link_existing"<?= $canEcn ? '' : ' checked' ?>>
                                                Link existing document as-is (keep its current rev, no ECN)
                                            </label>
                                            <label style="display:block;cursor:pointer;">
                                                <input type="radio" name="<?= h($fp) ?>[action]" value="skip">
                                                Skip (don't link or change anything)
                                            </label>
                                        </div>
                                    <?php else: /* upload */ ?>
                                        <div style="margin-top:6px;">
                                            <div class="muted" style="margin-bottom:4px;">No existing document with this Doc No. Upload the file to create &amp; link it, or skip.</div>
                                            <label style="display:block;cursor:pointer;margin-bottom:2px;">
                                                <input type="radio" name="<?= h($fp) ?>[action]" value="upload" checked>
                                                Upload &amp; create new external document:
                                            </label>
                                            <input type="file" name="docfile[<?= (int)$idx ?>][<?= (int)$di ?>]"
                                                   style="display:block;margin:2px 0 6px 20px;font-size:11.5px;">
                                            <label style="display:block;cursor:pointer;">
                                                <input type="radio" name="<?= h($fp) ?>[action]" value="skip">
                                                Skip (don't create or link this document)
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($cnt_insert + $cnt_update > 0): ?>
            <div style="margin-top: 18px; padding: 16px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface);">
                <p style="margin:0 0 12px;font-size:13px;">
                    Ready to commit <strong><?= $cnt_insert ?></strong> new and <strong><?= $cnt_update ?></strong> updated item<?= ($cnt_insert + $cnt_update) === 1 ? '' : 's' ?>.
                    The uploaded XML is attached to each item's running note. This action cannot be undone.
                </p>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="btn btn-primary">✓ Commit import</button>
                    <a href="<?= h(url('/import.php?action=xml_inv_items')) ?>" class="btn btn-ghost">Cancel</a>
                </div>
            </div>
        <?php else: ?>
            <div style="margin-top:18px;padding:16px;border:1px solid var(--border);border-radius:6px;background:#fef2f2;color:#991b1b;">
                Nothing to import — all parts in the file were skipped (no part numbers, or all empty).
            </div>
        <?php endif; ?>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// XML inv_items: commit
// ============================================================
if ($action === 'xml_inv_items_commit') {
    require_permission('import', 'xml_inv_items');
    csrf_check();

    $token = (string)input('token', '');
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $stash = $_SESSION['import_xml_inv_items'][$token] ?? null;
    if (!$stash || (int)$stash['uid'] !== (int)current_user_id()) {
        flash_set('error', 'Preview session expired or token mismatch — re-upload and preview again.');
        redirect(url('/import.php?action=xml_inv_items'));
    }
    $parsed = $stash['parsed'];
    $divisionId = (int)($stash['division_id'] ?? 0) ?: null;
    $docCategoryId = (int)($stash['doc_category_id'] ?? 0) ?: null;
    $xmlMeta = $stash['xml_meta'] ?? null;
    // Per-item note action choices from the preview form (only present
    // for update rows that had a prior import note). Keyed by part index.
    $noteActions = (array)input('note_action', []);
    // Per-item, per-doc choices from the preview form (link/skip/upload/ecn).
    $docChoices = (array)input('docs', []);

    $inserted = 0; $updated = 0; $skipped = 0;
    $insertedCodes = []; $updatedCodes = []; $skippedReasons = [];
    $billingPushIds = [];   // inv_items ids to mirror to billing after commit
    $docLinked = 0; $docCreated = 0; $docSkipped = 0; $docEcns = 0;

    try {
        db()->beginTransaction();
        $uid = (int)current_user_id();

        foreach ($parsed['parts'] as $idx => $p) {
            if ($p['op'] === 'skip') {
                $skipped++;
                $skippedReasons[] = ($p['mapped']['part_no'] ?: '(no part number)') . ': ' . implode('; ', $p['warnings']);
                continue;
            }
            $m = $p['mapped'];

            if ($p['op'] === 'insert') {
                // Code is system-generated, NOT from the XML.
                $newCode = imp_inv_code_next();
                db_exec(
                    "INSERT INTO inv_items
                       (code, name, short_description, long_description, part_no, part_rev_no, uom_id, dwg_no, dwg_rev_no, ecn, category_id, division_id, manufacturer_type, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'internal', 1)",
                    [
                        $newCode,
                        $m['name'] ?: $m['part_no'],   // name is NOT NULL — fall back to part_no if obj_desc was empty
                        $m['short_description'] ?: null,
                        $m['long_description'] ?: null,
                        $m['part_no'] ?: null,
                        $m['part_rev_no'] ?: null,
                        $m['uom_id'],
                        $m['dwg_no'] ?: null,
                        $m['dwg_rev_no'] ?: null,
                        $m['ecn'] ?: null,
                        imp_finshd_category_id(),   // all XML imports filed under Finished Good
                        $divisionId,                // operator-chosen division (applied to all)
                    ]
                );
                $newItemId = (int)db()->lastInsertId();
                $billingPushIds[] = $newItemId;   // mirror to billing after commit
                // Running Note carries the concatenated XML fields. The
                // XML file itself is attached to that note. On insert we
                // always create a fresh note + attach (no prompt).
                $noteId = imp_create_running_note($newItemId, $newCode, $p);
                if ($noteId) imp_attach_xml_to_note($noteId, $xmlMeta, $uid);
                // Documents: link existing / create+link uploaded.
                $dr = imp_process_documents($idx, $newItemId, $docChoices[$idx] ?? [], $uid, $docCategoryId);
                $docLinked += $dr['linked']; $docCreated += $dr['created']; $docSkipped += $dr['skipped']; $docEcns += $dr['ecns'];
                $inserted++;
                $insertedCodes[] = $newCode . ' (' . $m['part_no'] . ')';
            } elseif ($p['op'] === 'update') {
                // Only touch the columns we control via this importer.
                // code is NEVER changed on update — the existing system
                // code is preserved. Other columns (stock, cost, the
                // item's own notes field, etc.) stay untouched.
                db_exec(
                    "UPDATE inv_items
                        SET name              = ?,
                            short_description = ?,
                            long_description  = ?,
                            part_no           = ?,
                            part_rev_no       = ?,
                            uom_id            = COALESCE(?, uom_id),  -- don't NULL out a previously-set uom on a UoM-resolution-failure import
                            dwg_no            = ?,
                            dwg_rev_no        = ?,
                            ecn               = ?,
                            category_id       = ?,
                            division_id       = COALESCE(?, division_id),  -- apply chosen division; keep existing if somehow null
                            manufacturer_type = 'internal'
                      WHERE id = ?",
                    [
                        $m['name'] ?: $m['part_no'],
                        $m['short_description'] ?: null,
                        $m['long_description'] ?: null,
                        $m['part_no'] ?: null,
                        $m['part_rev_no'] ?: null,
                        $m['uom_id'],
                        $m['dwg_no'] ?: null,
                        $m['dwg_rev_no'] ?: null,
                        $m['ecn'] ?: null,
                        imp_finshd_category_id(),
                        $divisionId,
                        (int)$p['existing_id'],
                    ]
                );
                // Note behaviour on update follows the per-item choice
                // from the preview form: 'replace' soft-deletes prior
                // import note(s) (and their attachments) before creating
                // the fresh one; 'add' (default) just appends a new note.
                // Either way the new note gets the XML attached.
                $choice = (string)($noteActions[$idx] ?? 'add');
                if ($choice === 'replace') {
                    foreach (imp_prior_import_notes((int)$p['existing_id']) as $old) {
                        imp_soft_delete_note((int)$old['id']);
                    }
                }
                $noteId = imp_create_running_note((int)$p['existing_id'], ($p['existing_code'] ?? $m['part_no']), $p);
                if ($noteId) imp_attach_xml_to_note($noteId, $xmlMeta, $uid);
                // Documents: link existing / create+link uploaded.
                $dr = imp_process_documents($idx, (int)$p['existing_id'], $docChoices[$idx] ?? [], $uid, $docCategoryId);
                $docLinked += $dr['linked']; $docCreated += $dr['created']; $docSkipped += $dr['skipped']; $docEcns += $dr['ecns'];
                $updated++;
                $updatedCodes[] = ($p['existing_code'] ?? '?') . ' (' . $m['part_no'] . ')';
                $billingPushIds[] = (int)$p['existing_id'];   // mirror to billing after commit
            }
        }

        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[import/xml_inv_items_commit] ' . $e->getMessage());
        $raw = $e->getMessage();
        // Surface the common case — a (part_no, part_rev_no) uniqueness
        // clash — in plain language. This can happen if another user
        // imported the same part+revision between your preview and commit.
        if (stripos($raw, 'uq_inv_items_partno_rev') !== false || stripos($raw, 'Duplicate entry') !== false) {
            flash_set('error', 'Import aborted: a part number + revision in this file already exists (it was likely added since you previewed). All changes rolled back — re-upload and preview again to pick up the current state.');
        } else {
            flash_set('error', 'Import failed mid-way: ' . $raw . '. All changes rolled back.');
        }
        redirect(url('/import.php?action=xml_inv_items'));
    }

    // Clear the session stash now that we've committed.
    unset($_SESSION['import_xml_inv_items'][$token]);

    // Mirror to billing — AFTER the transaction commits, so an HTTP
    // call doesn't hold row locks. All XML-imported items are filed
    // under Finished Good, so they're all eligible; the helper still
    // double-checks the category and hash internally.
    $billingPushed = 0; $billingFailed = 0;
    foreach (array_unique($billingPushIds) as $bId) {
        $r = billing_product_push_if_needed($bId, current_user_id());
        if ($r['skipped']) continue;
        if (!empty($r['result']['ok'])) $billingPushed++;
        else                            $billingFailed++;
    }

    $msg = "Import complete. Inserted: $inserted. Updated: $updated.";
    if ($skipped) $msg .= " Skipped: $skipped.";
    if ($docLinked || $docCreated || $docSkipped) {
        $msg .= " Documents — linked: $docLinked, created: $docCreated";
        if ($docSkipped) $msg .= ", skipped: $docSkipped";
        $msg .= ".";
    }
    if ($docEcns) {
        $msg .= " ECNs drafted for rev changes: $docEcns (open the ECN module to process them).";
    }
    if ($billingPushed || $billingFailed) {
        $msg .= " Billing mirror: $billingPushed pushed";
        if ($billingFailed) $msg .= ", $billingFailed failed (see item view → push history)";
        $msg .= ".";
    }
    flash_set('success', $msg);
    if ($insertedCodes || $updatedCodes) {
        $sample = array_slice(array_merge($insertedCodes, $updatedCodes), 0, 8);
        flash_set('info', 'Affected codes (first 8): ' . implode(', ', $sample) . (count(array_merge($insertedCodes, $updatedCodes)) > 8 ? ' …' : ''));
    }
    redirect(url('/import.php'));
}

// ============================================================
// Vendor SQL dump: upload form
// ============================================================
if ($action === 'vendors_sql') {
    require_permission('import', 'vendors_sql');

    $page_title  = 'Import &middot; Vendors (legacy SQL)';
    $page_module = 'import';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/import.php'),
        'back_label' => 'Back to import',
        'title'      => 'SQL &mdash; Vendors (legacy)',
        'subtitle'   => 'Step 1 of 2 &mdash; upload',
    ]) ?>
    <div style="padding: 22px; max-width: 760px;">
        <p class="muted" style="line-height:1.6;">
            Upload the <code>inventory_live</code> MySQL SQL dump file. The importer reads the
            <code>company</code>, <code>contact</code>, and <code>address</code> tables and creates
            vendor records in the new system. Vendors whose name already exists are automatically
            skipped to prevent duplicates.
        </p>

        <form method="post" action="<?= h(url('/import.php?action=vendors_sql_preview')) ?>"
              enctype="multipart/form-data" style="margin-top: 18px;">
            <?= csrf_field() ?>
            <div style="border:1px dashed var(--border);border-radius:8px;padding:24px;background:var(--surface-alt);">
                <label style="display:block;font-weight:600;margin-bottom:8px;">SQL dump file <span style="color:#dc2626;">*</span></label>
                <input type="file" name="sql_file" accept=".sql,text/plain,application/sql" required
                       style="display:block;width:100%;padding:8px;background:white;border:1px solid var(--border);border-radius:4px;">
                <p class="muted small" style="margin:8px 0 0;">
                    Max 80 MB. Must contain the <code>inventory_live</code> tables:
                    <code>company</code>, <code>address</code>, <code>contact</code>.
                    Parsing may take a few seconds for large files.
                </p>
            </div>

            <div style="margin-top:18px;display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary">Parse &amp; preview &rarr;</button>
                <a href="<?= h(url('/import.php')) ?>" class="btn btn-ghost">Cancel</a>
            </div>
        </form>

        <details style="margin-top:24px;">
            <summary style="cursor:pointer;font-weight:600;color:var(--text);font-size:13px;">Field mapping details</summary>
            <div class="muted small" style="margin-top:10px;line-height:1.7;">
                <p><strong>company → vendors:</strong></p>
                <ul style="margin:4px 0 10px 18px;">
                    <li><code>short_description</code> → <code>name</code></li>
                    <li><code>company_custom_field.cfv_18</code> → <code>gst_no</code> (GSTIN)</li>
                    <li><code>website</code>, <code>long_description</code>, <code>cfv_33</code> (bank details) → <code>notes</code></li>
                    <li>All vendors are imported as active.</li>
                </ul>
                <p><strong>contact → vendor_contacts:</strong></p>
                <ul style="margin:4px 0 10px 18px;">
                    <li><code>title</code> → <code>salutation</code> (Mr / Ms / Mrs / Dr etc.)</li>
                    <li><code>first_name</code> + <code>last_name</code> → <code>name</code></li>
                    <li><code>phone_mobile</code> (or office / home) → <code>phone</code></li>
                    <li><code>email</code> → <code>email</code></li>
                    <li><code>description</code> → <code>designation</code></li>
                    <li>First contact per vendor is marked primary.</li>
                </ul>
                <p><strong>address → vendor_addresses:</strong></p>
                <ul style="margin:4px 0 10px 18px;">
                    <li><code>short_description</code> → <code>label</code></li>
                    <li><code>address_1</code> → <code>line1</code>, <code>address_2</code> → <code>line2</code></li>
                    <li><code>city</code> → <code>city</code>, <code>postal_code</code> → <code>pincode</code></li>
                    <li><code>country_id</code> → <code>country</code> (looked up from <code>country</code> table)</li>
                    <li><code>state_province_id</code> → <code>state</code> (looked up from <code>state_province</code> table)</li>
                    <li>The company's primary address (<code>company.address_id</code>) is marked primary.</li>
                </ul>
                <p><strong>Skipped:</strong> vendors whose name already exists in the system (case-insensitive match).</p>
            </div>
        </details>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// Vendor SQL dump: parse + preview
// ============================================================
if ($action === 'vendors_sql_preview') {
    require_permission('import', 'vendors_sql');
    csrf_check();

    // Validate upload
    if (empty($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'No file uploaded or upload failed (code ' . ($_FILES['sql_file']['error'] ?? '?') . ').');
        redirect(url('/import.php?action=vendors_sql'));
    }
    $tmp      = $_FILES['sql_file']['tmp_name'];
    $origName = (string)($_FILES['sql_file']['name'] ?? 'import.sql');

    if (!is_uploaded_file($tmp)) {
        flash_set('error', 'Upload failed safety check.');
        redirect(url('/import.php?action=vendors_sql'));
    }
    $maxBytes = 80 * 1024 * 1024;  // 80 MB
    if ((int)@filesize($tmp) > $maxBytes) {
        flash_set('error', 'File is larger than 80 MB. Please split or trim the dump first.');
        redirect(url('/import.php?action=vendors_sql'));
    }

    // Persist the file so it survives to the commit step
    $sqlMeta = vsql_persist_upload($tmp, $origName);
    if (!$sqlMeta) {
        flash_set('error', 'Could not store the uploaded file. Check that uploads/notes/import_sql is writable.');
        redirect(url('/import.php?action=vendors_sql'));
    }

    // Parse — this may take a few seconds for large files
    try {
        $data = vsql_prepare_import($sqlMeta['path']);
    } catch (\Throwable $e) {
        flash_set('error', 'Parse failed: ' . $e->getMessage());
        redirect(url('/import.php?action=vendors_sql'));
    }

    // Stash parsed data + file path in session
    $token = bin2hex(random_bytes(16));
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['import_vendors_sql'][$token] = [
        'data'       => $data,
        'sql_path'   => $sqlMeta['path'],
        'sql_name'   => $sqlMeta['filename'],
        'created_at' => time(),
        'uid'        => (int)current_user_id(),
    ];
    // Keep only 3 most-recent payloads to avoid session bloat
    if (count($_SESSION['import_vendors_sql']) > 3) {
        $all = $_SESSION['import_vendors_sql'];
        uasort($all, function ($a, $b) { return $b['created_at'] - $a['created_at']; });
        $_SESSION['import_vendors_sql'] = array_slice($all, 0, 3, true);
    }

    $counts   = $data['counts'];
    $vendors  = $data['vendors'];
    $warnings = $data['warnings'];

    $page_title  = 'Import &middot; Vendors &middot; Preview';
    $page_module = 'import';
    require __DIR__ . '/includes/header.php';
    ?>
    <?= form_toolbar([
        'back_href'  => url('/import.php?action=vendors_sql'),
        'back_label' => 'Upload a different file',
        'title'      => 'SQL &mdash; Vendors (legacy)',
        'subtitle'   => 'Step 2 of 2 &mdash; preview &amp; commit',
    ]) ?>
    <div style="padding: 18px 22px;">

        <!-- Summary pills -->
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
            <div style="flex:1;min-width:130px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#f0fdf4;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#166534;">New vendors</div>
                <div style="font-size:22px;font-weight:600;color:#166534;"><?= (int)$counts['insert'] ?></div>
            </div>
            <div style="flex:1;min-width:130px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#f8fafc;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">Skipped (exist)</div>
                <div style="font-size:22px;font-weight:600;color:#64748b;"><?= (int)$counts['skip'] ?></div>
            </div>
            <div style="flex:1;min-width:130px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#eef2ff;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#3730a3;">Addresses</div>
                <div style="font-size:22px;font-weight:600;color:#3730a3;"><?= (int)$counts['addresses'] ?></div>
            </div>
            <div style="flex:1;min-width:130px;padding:14px 16px;border:1px solid var(--border);border-radius:6px;background:#fff7ed;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#c2410c;">Contacts</div>
                <div style="font-size:22px;font-weight:600;color:#c2410c;"><?= (int)$counts['contacts'] ?></div>
            </div>
        </div>

        <p class="muted small" style="margin-bottom:12px;">
            From <code><?= h($sqlMeta['filename']) ?></code> &middot;
            <?= count($vendors) ?> vendor<?= count($vendors) === 1 ? '' : 's' ?> parsed.
            Green rows will be inserted; grey rows are already in the system and will be skipped.
        </p>

        <?php foreach ($warnings as $w): ?>
            <div style="margin-bottom:12px;padding:10px 14px;background:#fef3c7;border-left:3px solid #d97706;border-radius:4px;font-size:13px;color:#78350f;">
                ⚠ <?= h($w) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($counts['insert'] > 0): ?>
        <form method="post" action="<?= h(url('/import.php?action=vendors_sql_commit')) ?>" style="display:inline;margin-bottom:14px;">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Import <?= (int)$counts['insert'] ?> vendor<?= $counts['insert'] === 1 ? '' : 's' ?> with their contacts and addresses?');">
                Commit <?= (int)$counts['insert'] ?> vendor<?= $counts['insert'] === 1 ? '' : 's' ?> &rarr;
            </button>
        </form>
        <a class="btn btn-ghost" href="<?= h(url('/import.php?action=vendors_sql')) ?>">Upload different file</a>
        <?php else: ?>
            <div style="padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#991b1b;font-size:13px;margin-bottom:14px;">
                All vendors from this file already exist in the system — nothing to import.
            </div>
        <?php endif; ?>

        <!-- Vendor preview table -->
        <div style="margin-top:16px;overflow-x:auto;">
        <table class="data-table" style="min-width:760px;">
            <thead>
                <tr>
                    <th style="width:70px;">Status</th>
                    <th>Vendor name</th>
                    <th>GSTIN</th>
                    <th style="width:60px;text-align:right;">#Contacts</th>
                    <th style="width:60px;text-align:right;">#Addresses</th>
                    <th>Primary contact</th>
                    <th>Primary address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendors as $v):
                    $isInsert    = ($v['status'] === 'insert');
                    $rowStyle    = $isInsert ? 'background:#f0fdf4;' : 'background:#f8fafc;color:#94a3b8;';
                    $statusLabel = $isInsert
                        ? '<span style="font-size:11px;font-weight:700;color:#166534;background:white;padding:2px 6px;border-radius:3px;">INSERT</span>'
                        : '<span style="font-size:11px;font-weight:700;color:#94a3b8;background:white;padding:2px 6px;border-radius:3px;">SKIP</span>';
                    $primaryContact = $v['contacts'][0] ?? null;
                    $primaryAddress = $v['addresses'][0] ?? null;
                    $addrDisplay = '';
                    if ($primaryAddress) {
                        $parts = array_filter([
                            $primaryAddress['line1'],
                            $primaryAddress['city'],
                            $primaryAddress['pincode'],
                        ]);
                        $addrDisplay = implode(', ', $parts);
                    }
                ?>
                <tr style="<?= $rowStyle ?>">
                    <td><?= $statusLabel ?></td>
                    <td>
                        <strong><?= h($v['name']) ?></strong>
                        <?php if (!$isInsert && $v['existing_id']): ?>
                            <br><span class="muted small">
                                exists as <a href="<?= h(url('/vendors.php?action=edit&id=' . (int)$v['existing_id'])) ?>" target="_blank">vendor #<?= (int)$v['existing_id'] ?></a>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($v['gst_no'] ?: '—') ?></td>
                    <td style="text-align:right;"><?= count($v['contacts']) ?></td>
                    <td style="text-align:right;"><?= count($v['addresses']) ?></td>
                    <td class="small">
                        <?php if ($primaryContact): ?>
                            <?= h(trim(($primaryContact['salutation'] ? $primaryContact['salutation'] . ' ' : '') . $primaryContact['name'])) ?>
                            <?php if ($primaryContact['phone']): ?>
                                <br><span class="muted"><?= h($primaryContact['phone']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= h($addrDisplay ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($counts['insert'] > 0): ?>
        <div style="margin-top:16px;">
            <form method="post" action="<?= h(url('/import.php?action=vendors_sql_commit')) ?>" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('Import <?= (int)$counts['insert'] ?> vendor<?= $counts['insert'] === 1 ? '' : 's' ?> with their contacts and addresses?');">
                    Commit <?= (int)$counts['insert'] ?> vendor<?= $counts['insert'] === 1 ? '' : 's' ?> &rarr;
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================
// Vendor SQL dump: commit
// ============================================================
if ($action === 'vendors_sql_commit') {
    require_permission('import', 'vendors_sql');
    csrf_check();

    // Retrieve stashed session data
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $token   = (string)input('token', '');
    $stashed = $_SESSION['import_vendors_sql'][$token] ?? null;
    if (!$stashed) {
        flash_set('error', 'Import session expired or invalid. Please re-upload the file.');
        redirect(url('/import.php?action=vendors_sql'));
    }

    // Reject stale sessions (>1 hour)
    if (time() - (int)$stashed['created_at'] > 3600) {
        unset($_SESSION['import_vendors_sql'][$token]);
        flash_set('error', 'Import session expired (>1 hour). Please re-upload the file.');
        redirect(url('/import.php?action=vendors_sql'));
    }

    $data    = $stashed['data'];
    $vendors = $data['vendors'];
    $actorId = (int)current_user_id();

    $inserted = 0;
    $skipped  = 0;
    $errors   = 0;
    $failedNames = [];

    foreach ($vendors as $v) {
        if ($v['status'] !== 'insert') {
            $skipped++;
            continue;
        }
        // Re-check for duplicates that appeared since preview
        $existing = db_one(
            'SELECT id FROM vendors WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1',
            [$v['name']]
        );
        if ($existing) {
            $skipped++;
            continue;
        }
        try {
            vsql_commit_vendor($v, $actorId);
            $inserted++;
        } catch (\Throwable $e) {
            $errors++;
            $failedNames[] = $v['name'];
            error_log('[import/vendors_sql_commit] ' . $v['name'] . ': ' . $e->getMessage());
        }
    }

    // Clear session stash
    unset($_SESSION['import_vendors_sql'][$token]);

    $msg = "Vendor import complete. Inserted: $inserted.";
    if ($skipped) $msg .= " Skipped (already exist): $skipped.";
    if ($errors)  $msg .= " Errors: $errors (see server log for details).";
    flash_set($errors ? 'info' : 'success', $msg);

    if ($failedNames) {
        flash_set('error', 'Failed to import: ' . implode(', ', array_map('h', array_slice($failedNames, 0, 10)))
            . (count($failedNames) > 10 ? ' …' : ''));
    }
    redirect(url('/vendors.php'));
}

// Default: unknown action
flash_set('error', 'Unknown action: ' . h($action));
redirect(url('/import.php'));
