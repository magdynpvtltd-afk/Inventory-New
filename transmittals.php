<?php
/**
 * MagDyn — Document Transmittals
 * Created: 20260519_120000_IST
 *
 * Tracks outgoing send-out events for documents. Each transmittal
 * is one (doc, recipient, date) tuple with method, optional cover
 * sheet PDF, and delivery confirmation.
 *
 * Actions:
 *   list (default)        Datatable of all transmittals
 *   new&doc_id=N          New transmittal form (for one specific doc)
 *   save (POST)           Create a transmittal
 *   view&id=N             Read-only view + delivery confirmation form
 *   upload_cover&id=N (POST)   Upload an operator-provided cover sheet PDF
 *   download_cover&id=N        Stream the cover sheet
 *   mark_delivered&id=N (POST) Update delivery status
 *   cancel&id=N (POST)         Cancel a transmittal (soft)
 *
 * Permission: documents_transmittals.view / create / manage / delete.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('documents_transmittals', 'view');

require_once __DIR__ . '/includes/_dms.php';
require_once __DIR__ . '/includes/datatable.php';

$action = (string)input('action', 'list');
$id     = (int)input('id', 0);
$uid    = current_user_id();
$page_module = 'documents_transmittals';

// =============================================================
// Datatable config + row renderer — module-level so both AJAX and
// page-render paths can share them.
// =============================================================
$dtCfg = [
    'id' => 'transmittal_list',
    'url' => url('/transmittals.php?action=list'),
    'base_sql' => "
        SELECT t.id, t.document_id, t.transmittal_no, t.sent_date, t.method, t.delivery_status,
               t.recipient_kind, t.recipient_attn, t.external_party,
               d.code AS doc_code, d.title AS doc_title,
               rv.rev_label,
               v.name AS vendor_name,
               u.full_name AS user_name,
               t.created_at
          FROM doc_transmittals t
          JOIN documents d ON d.id = t.document_id
     LEFT JOIN doc_revisions rv ON rv.id = t.revision_id
     LEFT JOIN vendors v ON v.id = t.vendor_id
     LEFT JOIN users u ON u.id = t.user_id
         WHERE t.cancelled_at IS NULL",
    'columns' => [
        ['key' => 'transmittal_no',  'label' => 'No',        'sortable' => true, 'searchable' => true, 'sql_col' => 't.transmittal_no'],
        ['key' => 'doc_code',        'label' => 'Document',  'sortable' => true, 'searchable' => true, 'sql_col' => 'd.code'],
        ['key' => 'rev_label',       'label' => 'Rev',       'sortable' => false,'searchable' => true, 'sql_col' => 'rv.rev_label'],
        ['key' => 'recipient_attn',  'label' => 'Recipient', 'sortable' => true, 'searchable' => true, 'sql_col' => "COALESCE(t.recipient_attn, t.external_party, v.name, u.full_name)"],
        ['key' => 'sent_date',       'label' => 'Sent',      'sortable' => true, 'searchable' => true, 'sql_col' => 't.sent_date'],
        ['key' => 'method',          'label' => 'Method',    'sortable' => true, 'searchable' => true, 'sql_col' => 't.method'],
        ['key' => 'delivery_status', 'label' => 'Status',    'sortable' => true, 'searchable' => true, 'sql_col' => 't.delivery_status'],
    ],
    'default_sort' => ['sent_date', 'desc'],
    'page_size'    => 25,
];
$rowRenderer = function ($row) {
    $no  = '<a href="' . url('/transmittals.php?action=view&id=' . (int)$row['id']) . '">' . h($row['transmittal_no']) . '</a>';
    $doc = '<a href="' . url('/documents.php?action=view&id=' . (int)($row['document_id'] ?? 0)) . '">' . h($row['doc_code']) . '</a>';
    $rcp = $row['recipient_attn'] ?: $row['external_party'] ?: $row['vendor_name'] ?: $row['user_name'] ?: '—';
    $statusPills = [
        'sent'         => 'pill pill-draft',
        'delivered'    => 'pill pill-active',
        'acknowledged' => 'pill pill-success',
        'signed'       => 'pill pill-success',
        'returned'     => 'pill pill-warn',
        'failed'       => 'pill pill-danger',
    ];
    $cls = isset($statusPills[$row['delivery_status']]) ? $statusPills[$row['delivery_status']] : 'pill pill-neutral';
    return [
        'transmittal_no'  => $no,
        'doc_code'        => $doc,
        'rev_label'       => h($row['rev_label'] ?? '—'),
        'recipient_attn'  => h($rcp),
        'sent_date'       => date('d M Y', strtotime($row['sent_date'])),
        'method'          => h($row['method']),
        'delivery_status' => '<span class="' . h($cls) . '">' . h($row['delivery_status']) . '</span>',
    ];
};

// =============================================================
// AJAX: list
// =============================================================
if ($action === 'list' && input('dt') === '1') {
    data_table_run($dtCfg, $rowRenderer);
    exit;
}

// =============================================================
// POST: save
// =============================================================
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('documents_transmittals', 'create');

    $docId = (int)input('document_id', 0);
    $doc   = db_one("SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL", [$docId]);
    if (!$doc) { flash_set('error', 'Document not found.'); header('Location: ' . url('/transmittals.php')); exit; }

    $revId = (int)input('revision_id', 0) ?: (int)$doc['current_rev_id'];
    $recipientKind = (string)input('recipient_kind', 'external_party');
    if (!in_array($recipientKind, ['customer','vendor','user','external_party'], true)) {
        $recipientKind = 'external_party';
    }
    $vendorId      = (int)input('vendor_id', 0) ?: null;
    $userId        = (int)input('user_id', 0) ?: null;
    $externalParty = trim((string)input('external_party', '')) ?: null;
    $attn          = trim((string)input('recipient_attn', '')) ?: null;
    $email         = trim((string)input('recipient_email', '')) ?: null;
    $phone         = trim((string)input('recipient_phone', '')) ?: null;
    $sentDate      = (string)input('sent_date', date('Y-m-d'));
    $method        = (string)input('method', 'email');
    if (!in_array($method, ['email','post','courier','portal','handover','other'], true)) $method = 'email';
    $reference     = trim((string)input('reference', '')) ?: null;
    $subject       = trim((string)input('subject', '')) ?: null;
    $notes         = trim((string)input('notes', '')) ?: null;

    $no = dms_next_transmittal_no();
    db_exec(
        "INSERT INTO doc_transmittals
            (transmittal_no, document_id, revision_id, recipient_kind,
             vendor_id, user_id, external_party,
             recipient_attn, recipient_email, recipient_phone,
             sent_date, method, reference, subject, notes,
             delivery_status, cover_kind, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', 'none', ?)",
        [$no, $docId, $revId, $recipientKind,
         $vendorId, $userId, $externalParty,
         $attn, $email, $phone,
         $sentDate, $method, $reference, $subject, $notes,
         $uid]
    );
    $tid = (int)db_val('SELECT LAST_INSERT_ID()');
    doc_history_append($docId, 'transmitted', null, null, $tid,
        "Transmittal $no to " . ($attn ?: $externalParty ?: 'recipient'), $uid);

    flash_set('success', "Transmittal $no created.");
    header('Location: ' . url('/transmittals.php?action=view&id=' . $tid));
    exit;
}

// =============================================================
// POST: upload_cover
// =============================================================
if ($action === 'upload_cover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('documents_transmittals', 'manage');
    $t = db_one("SELECT * FROM doc_transmittals WHERE id = ?", [$id]);
    if (!$t) { flash_set('error', 'Transmittal not found.'); header('Location: ' . url('/transmittals.php')); exit; }

    if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please choose a cover sheet PDF.');
        header('Location: ' . url('/transmittals.php?action=view&id=' . $id)); exit;
    }
    $f = $_FILES['cover'];
    $baseDir = __DIR__ . '/uploads/transmittals/' . (int)$id;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        flash_set('error', 'Could not create upload dir.');
        header('Location: ' . url('/transmittals.php?action=view&id=' . $id)); exit;
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']);
    $abs = $baseDir . '/cover_' . time() . '_' . $safe;
    if (!move_uploaded_file($f['tmp_name'], $abs)) {
        flash_set('error', 'Move failed.');
        header('Location: ' . url('/transmittals.php?action=view&id=' . $id)); exit;
    }
    $rel = 'uploads/transmittals/' . (int)$id . '/' . basename($abs);
    db_exec(
        "UPDATE doc_transmittals
            SET cover_kind = 'uploaded',
                cover_file_name = ?, cover_file_path = ?,
                cover_file_size = ?, cover_file_mime = ?
          WHERE id = ?",
        [$f['name'], $rel, filesize($abs),
         function_exists('mime_content_type') ? mime_content_type($abs) : 'application/pdf',
         $id]
    );
    doc_history_append((int)$t['document_id'], 'cover_uploaded', null, null, $id,
        "Cover sheet uploaded for transmittal {$t['transmittal_no']}", $uid);
    flash_set('success', 'Cover sheet uploaded.');
    header('Location: ' . url('/transmittals.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// download_cover
// =============================================================
if ($action === 'download_cover') {
    $t = db_one("SELECT * FROM doc_transmittals WHERE id = ?", [$id]);
    if (!$t || !$t['cover_file_path']) { http_response_code(404); echo 'Not found'; exit; }
    $abs = __DIR__ . '/' . $t['cover_file_path'];
    if (!is_file($abs)) { http_response_code(404); echo 'File missing'; exit; }
    header('Content-Type: ' . ($t['cover_file_mime'] ?: 'application/pdf'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . addslashes($t['cover_file_name']) . '"');
    readfile($abs);
    exit;
}

// =============================================================
// POST: mark_delivered
// =============================================================
if ($action === 'mark_delivered' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('documents_transmittals', 'manage');
    $newStatus = (string)input('status', 'delivered');
    if (!in_array($newStatus, ['sent','delivered','acknowledged','signed','returned','failed'], true)) {
        $newStatus = 'delivered';
    }
    $note = trim((string)input('delivered_note', '')) ?: null;
    db_exec(
        "UPDATE doc_transmittals
            SET delivery_status = ?, delivered_at = NOW(), delivered_note = ?
          WHERE id = ?",
        [$newStatus, $note, $id]
    );
    $t = db_one("SELECT document_id, transmittal_no FROM doc_transmittals WHERE id = ?", [$id]);
    doc_history_append((int)$t['document_id'], 'transmitted', null, $newStatus, $id,
        "Transmittal {$t['transmittal_no']} marked $newStatus" . ($note ? ': ' . $note : ''), $uid);
    flash_set('success', 'Delivery status updated.');
    header('Location: ' . url('/transmittals.php?action=view&id=' . $id));
    exit;
}

// =============================================================
// POST: cancel
// =============================================================
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_permission('documents_transmittals', 'delete');
    db_exec("UPDATE doc_transmittals SET cancelled_at = NOW(), cancelled_by = ? WHERE id = ?", [$uid, $id]);
    flash_set('success', 'Transmittal cancelled.');
    header('Location: ' . url('/transmittals.php'));
    exit;
}

// =============================================================
// GET: new — show new-transmittal form
// =============================================================
if ($action === 'new') {
    require_permission('documents_transmittals', 'create');
    $docId = (int)input('doc_id', 0);
    $doc = $docId ? db_one("SELECT d.*, c.name AS category_name FROM documents d
                              JOIN doc_categories c ON c.id = d.category_id
                             WHERE d.id = ? AND d.deleted_at IS NULL", [$docId]) : null;
    $allDocs = db_all("SELECT id, code, title FROM documents WHERE deleted_at IS NULL ORDER BY code");
    $revisions = $doc ? db_all("SELECT id, rev_label FROM doc_revisions WHERE document_id = ? ORDER BY rev_major DESC, rev_minor DESC", [$docId]) : [];
    $vendors = db_all("SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name");
    $users   = db_all("SELECT id, full_name AS name FROM users WHERE is_active = 1 ORDER BY full_name");
    $page_title = 'New Transmittal';
    require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>New transmittal</h1>
        <p class="muted">Log an outgoing document send-out: recipient, date, method, cover sheet, and delivery status.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/transmittals.php')) ?>">← All transmittals</a>
    </div>
</div>

<form method="post" action="<?= h(url('/transmittals.php?action=save')) ?>">
    <?= csrf_field() ?>

    <div class="card form-card" style="margin-bottom: 18px;">
        <h3 style="margin: 0 0 14px; font-size: 14px;">Document</h3>
        <div class="form-grid-2">
            <div class="field span-2">
                <label>Document <span style="color: var(--danger);">*</span></label>
                <select name="document_id" required>
                    <option value="">— pick —</option>
                    <?php foreach ($allDocs as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $doc && (int)$doc['id'] === (int)$d['id'] ? 'selected' : '' ?>>
                            <?= h($d['code']) ?> — <?= h($d['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($revisions)): ?>
                <div class="field">
                    <label>Revision</label>
                    <select name="revision_id">
                        <option value="">— current —</option>
                        <?php foreach ($revisions as $r): ?>
                            <option value="<?= (int)$r['id'] ?>">Rev <?= h($r['rev_label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card form-card" style="margin-bottom: 18px;">
        <h3 style="margin: 0 0 14px; font-size: 14px;">Recipient</h3>
        <div class="form-grid-2">
            <div class="field">
                <label>Recipient kind</label>
                <select name="recipient_kind">
                    <option value="external_party">External party (free text)</option>
                    <option value="vendor">Vendor</option>
                    <option value="user">Internal user</option>
                    <option value="customer">Customer</option>
                </select>
            </div>
            <div class="field">
                <label>Vendor</label>
                <select name="vendor_id">
                    <option value="">—</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">If recipient = vendor.</span>
            </div>
            <div class="field">
                <label>Internal user</label>
                <select name="user_id">
                    <option value="">—</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">If recipient = internal user.</span>
            </div>
            <div class="field">
                <label>External party</label>
                <input type="text" name="external_party" maxlength="160" placeholder="Free text name">
            </div>
            <div class="field">
                <label>Attn line</label>
                <input type="text" name="recipient_attn" maxlength="160">
                <span class="field-hint">Appears on cover sheet.</span>
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="recipient_email" maxlength="160">
            </div>
            <div class="field">
                <label>Phone</label>
                <input type="text" name="recipient_phone" maxlength="60">
            </div>
        </div>
    </div>

    <div class="card form-card" style="margin-bottom: 18px;">
        <h3 style="margin: 0 0 14px; font-size: 14px;">Send details</h3>
        <div class="form-grid-2">
            <div class="field">
                <label>Sent date <span style="color: var(--danger);">*</span></label>
                <input type="date" name="sent_date" value="<?= h(date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label>Method</label>
                <select name="method">
                    <option value="email">email</option>
                    <option value="post">post</option>
                    <option value="courier">courier</option>
                    <option value="portal">portal</option>
                    <option value="handover">handover</option>
                    <option value="other">other</option>
                </select>
            </div>
            <div class="field">
                <label>Reference</label>
                <input type="text" name="reference" maxlength="120" placeholder="PO no, RFQ, etc.">
            </div>
            <div class="field">
                <label>Subject</label>
                <input type="text" name="subject" maxlength="240">
            </div>
            <div class="field span-2">
                <label>Notes</label>
                <textarea name="notes" rows="3"></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Create transmittal</button>
    </div>
</form>
<?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =============================================================
// GET: view
// =============================================================
// =============================================================
// Phase D2.5 — Email send + composer for transmittals.
// Reuses the shared composer in includes/_email_compose.php so PO
// and transmittal emails behave identically. Auto-attachments come
// from the document's current revision file plus the transmittal's
// uploaded cover sheet (when present).
// =============================================================
require_once __DIR__ . '/includes/_email_compose.php';

function transmittal_email_compose_context($trnId)
{
    $t = db_one(
        "SELECT t.*, d.code AS doc_code, d.title AS doc_title,
                rv.rev_label, rv.file_name AS rev_file_name,
                rv.file_path AS rev_file_path, rv.file_mime AS rev_file_mime,
                v.name AS vendor_name
           FROM doc_transmittals t
           JOIN documents d ON d.id = t.document_id
      LEFT JOIN doc_revisions rv ON rv.id = t.revision_id
      LEFT JOIN vendors v        ON v.id = t.vendor_id
          WHERE t.id = ?",
        [(int)$trnId]
    );
    if (!$t) return null;

    // Recipients list. If the transmittal already names a vendor, surface
    // every vendor contact with an email — operator can pick. Otherwise
    // there's just the transmittal's own recipient_email shown as the
    // extra-To default.
    $contacts = [];
    if (!empty($t['vendor_id'])) {
        $contacts = db_all(
            "SELECT id, salutation, name, designation, email, is_primary
               FROM vendor_contacts
              WHERE vendor_id = ? AND email <> '' AND email IS NOT NULL
              ORDER BY is_primary DESC, name",
            [(int)$t['vendor_id']]
        );
    }
    $extraTo = (string)($t['recipient_email'] ?? '');
    // Drop extra-To if it matches a contact email already in the list,
    // to avoid duplicate sends to the same address.
    foreach ($contacts as $c) {
        if (strcasecmp((string)$c['email'], $extraTo) === 0) { $extraTo = ''; break; }
    }

    $subject = $t['subject'] !== ''
             ? (string)$t['subject']
             : ('Document ' . $t['doc_code']
                . ' Rev ' . ($t['rev_label'] ?? '')
                . ' — ' . $t['transmittal_no']);

    $absBase = (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST']) : '');
    $viewUrl = $absBase . url('/transmittals.php?action=view&id=' . (int)$t['id']);
    $attn    = $t['recipient_attn'] ?: ($t['vendor_name'] ?? 'Sir/Madam');

    $body = '<p>Dear ' . h($attn) . ',</p>'
          . '<p>Please find attached <strong>' . h($t['doc_code']) . ' — ' . h($t['doc_title'])
          . '</strong>, Revision <strong>' . h($t['rev_label'] ?? '') . '</strong>, '
          . 'sent under transmittal <strong>' . h($t['transmittal_no']) . '</strong>.</p>'
          . ($t['reference'] ? '<p><strong>Reference:</strong> ' . h($t['reference']) . '</p>' : '')
          . ($t['notes']     ? '<p>' . nl2br(h($t['notes'])) . '</p>' : '')
          . '<p>Kindly acknowledge receipt of this transmittal at your end.</p>'
          . '<p style="margin-top: 14px;">Transmittal record: '
          . '<a href="' . h($viewUrl) . '">' . h($t['transmittal_no']) . '</a></p>'
          . '<p>Best regards,<br>Magneto Dynamics</p>';

    // Auto-attachments: the doc's current revision file + the cover
    // sheet PDF (if uploaded). Both are referenced from disk by their
    // absolute paths derived from the relative paths stored in DB.
    $attachAuto = [];
    if (!empty($t['rev_file_path'])) {
        $abs = __DIR__ . '/' . ltrim((string)$t['rev_file_path'], '/');
        if (is_file($abs)) {
            $attachAuto[] = [
                'label'         => 'Document file',
                'description'   => 'Current revision ' . ($t['rev_label'] ?? ''),
                'filename'      => $t['rev_file_name'] ?: basename($abs),
                'hidden_path'   => $abs,
                'mime'          => $t['rev_file_mime'] ?: null,
                'default_on'    => true,
                'toggle_name'   => 'attach_doc',
            ];
        }
    }
    if (!empty($t['cover_file_path']) && empty($t['cancelled_at'])) {
        $abs = __DIR__ . '/' . ltrim((string)$t['cover_file_path'], '/');
        if (is_file($abs)) {
            $attachAuto[] = [
                'label'         => 'Cover sheet',
                'description'   => 'Uploaded transmittal cover',
                'filename'      => $t['cover_file_name'] ?: basename($abs),
                'hidden_path'   => $abs,
                'mime'          => $t['cover_file_mime'] ?: null,
                'default_on'    => true,
                'toggle_name'   => 'attach_cover',
            ];
        }
    }

    return [
        'related_type'     => 'transmittal',
        'related_id'       => (int)$t['id'],
        'page_title'       => 'Email transmittal ' . $t['transmittal_no'],
        'back_url'         => url('/transmittals.php?action=view&id=' . (int)$t['id']),
        'permission'       => ['module' => 'documents_transmittals', 'action' => 'manage'],
        'subject_default'  => $subject,
        'body_default'     => $body,
        'contacts'         => $contacts,
        'extra_to_default' => $extraTo,
        'attach_auto'      => $attachAuto,
        'reply_to_default' => '',
        'send_url'         => url('/transmittals.php?action=email_send'),
        'redirect_url'     => url('/transmittals.php?action=view&id=' . (int)$t['id']),
    ];
}

if ($action === 'email_send') {
    require_permission('documents_transmittals', 'manage');
    csrf_check();
    $id  = (int)input('id', 0);
    $ctx = transmittal_email_compose_context($id);
    if (!$ctx) { flash_set('error', 'Transmittal not found.'); header('Location: ' . url('/transmittals.php')); exit; }
    $res = handle_email_send_post($ctx, (int)$uid);
    if ($res['ok']) {
        // Bump method to 'email' and status to 'sent' if it was at default.
        db_exec(
            "UPDATE doc_transmittals
                SET method = 'email',
                    delivery_status = CASE WHEN delivery_status = 'sent' THEN 'sent' ELSE delivery_status END
              WHERE id = ?",
            [$id]
        );
        flash_set('success', 'Email sent to ' . (int)$res['recipients'] . ' recipient(s).');
    } else {
        flash_set('error', 'Send failed: ' . $res['error']);
    }
    header('Location: ' . $ctx['redirect_url']); exit;
}

if ($action === 'email_compose') {
    require_permission('documents_transmittals', 'manage');
    $id  = (int)input('id', 0);
    $ctx = transmittal_email_compose_context($id);
    if (!$ctx) { flash_set('error', 'Transmittal not found.'); header('Location: ' . url('/transmittals.php')); exit; }
    render_email_compose_page($ctx);
    exit;
}


if ($action === 'view') {
    $t = db_one(
        "SELECT t.*, d.code AS doc_code, d.title AS doc_title, d.kind AS doc_kind,
                rv.rev_label, v.name AS vendor_name, u.full_name AS user_name,
                cu.full_name AS created_by_name
           FROM doc_transmittals t
           JOIN documents d ON d.id = t.document_id
      LEFT JOIN doc_revisions rv ON rv.id = t.revision_id
      LEFT JOIN vendors v ON v.id = t.vendor_id
      LEFT JOIN users u   ON u.id = t.user_id
      LEFT JOIN users cu  ON cu.id = t.created_by
          WHERE t.id = ?", [$id]
    );
    if (!$t) { flash_set('error', 'Transmittal not found.'); header('Location: ' . url('/transmittals.php')); exit; }
    $page_title = $t['transmittal_no'];
    require __DIR__ . '/includes/header.php';
?>
<?php
    // Status pill mapping for view
    $vStatusMap = [
        'sent'         => 'pill-draft',
        'delivered'    => 'pill-active',
        'acknowledged' => 'pill-success',
        'signed'       => 'pill-success',
        'returned'     => 'pill-warn',
        'failed'       => 'pill-danger',
    ];
    $vCls = isset($vStatusMap[$t['delivery_status']]) ? $vStatusMap[$t['delivery_status']] : 'pill-neutral';
?>
<div class="page-head">
    <div>
        <h1>
            <?= h($t['transmittal_no']) ?>
            <span class="pill <?= h($vCls) ?>" style="margin-left: 10px; font-size: 11px;"><?= h($t['delivery_status']) ?></span>
            <?php if ($t['cancelled_at']): ?><span class="pill pill-cancelled" style="margin-left: 4px; font-size: 11px;">Cancelled</span><?php endif; ?>
        </h1>
        <p class="muted">
            <a href="<?= h(url('/documents.php?action=view&id=' . (int)$t['document_id'])) ?>"><?= h($t['doc_code']) ?> — <?= h($t['doc_title']) ?></a>
            · Rev <?= h($t['rev_label'] ?? '—') ?>
        </p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= h(url('/transmittals.php')) ?>">← All transmittals</a>
        <?php if (!$t['cancelled_at'] && permission_check('documents_transmittals', 'manage')): ?>
            <a class="btn btn-primary btn-sm" href="<?= h(url('/transmittals.php?action=email_compose&id=' . (int)$t['id'])) ?>">✉ Send by email</a>
        <?php endif; ?>
        <?php if (!$t['cancelled_at'] && permission_check('documents_transmittals', 'delete')): ?>
            <form method="post" action="<?= h(url('/transmittals.php?action=cancel&id=' . (int)$t['id'])) ?>" style="display:inline"
                  onsubmit="return confirm('Cancel this transmittal? The record remains but is marked cancelled.')">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger btn-sm">Cancel transmittal</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px;">
    <div class="card" style="margin-bottom:0;">
        <div class="card-head"><h3 style="margin:0; font-size:15px;">Recipient</h3></div>
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr><th style="width:35%; text-align:left;">Kind</th>
                        <td><?= h($t['recipient_kind']) ?></td></tr>
                    <tr><th style="text-align:left;">Vendor</th>
                        <td><?= h($t['vendor_name'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">Internal user</th>
                        <td><?= h($t['user_name'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">External party</th>
                        <td><?= h($t['external_party'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">Attn</th>
                        <td><?= h($t['recipient_attn'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">Email</th>
                        <td><?= h($t['recipient_email'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">Phone</th>
                        <td><?= h($t['recipient_phone'] ?: '—') ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:0;">
        <div class="card-head"><h3 style="margin:0; font-size:15px;">Transmittal details</h3></div>
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr><th style="width:35%; text-align:left;">Sent date</th>
                        <td><?= h(date('d M Y', strtotime($t['sent_date']))) ?></td></tr>
                    <tr><th style="text-align:left;">Method</th>
                        <td><?= h($t['method']) ?></td></tr>
                    <tr><th style="text-align:left;">Reference</th>
                        <td><?= h($t['reference'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">Subject</th>
                        <td><?= h($t['subject'] ?: '—') ?></td></tr>
                    <tr><th style="text-align:left;">Created by</th>
                        <td><?= h($t['created_by_name'] ?: '—') ?>
                            <span class="muted small">· <?= h(dt_display($t['created_at'])) ?></span></td></tr>
                </tbody>
            </table>
            <?php if ($t['notes']): ?>
                <h4 style="margin: 18px 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color: var(--text-muted);">Notes</h4>
                <p style="margin: 0; line-height: 1.6;"><?= nl2br(h($t['notes'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Cover sheet</h3></div>
    <div class="card-body">
        <?php if ($t['cover_file_path']): ?>
            <p style="margin: 0 0 10px;">
                📎 <a href="<?= h(url('/transmittals.php?action=download_cover&id=' . (int)$t['id'])) ?>"><?= h($t['cover_file_name']) ?></a>
                <span class="muted small">· <?= h(number_format((int)$t['cover_file_size'])) ?> B · <?= h($t['cover_kind']) ?></span>
            </p>
        <?php else: ?>
            <p class="muted">No cover sheet attached.</p>
        <?php endif; ?>
        <?php if (permission_check('documents_transmittals', 'manage')): ?>
            <form method="post" action="<?= h(url('/transmittals.php?action=upload_cover&id=' . (int)$t['id'])) ?>"
                  enctype="multipart/form-data" style="display: flex; gap: 8px; align-items: center;">
                <?= csrf_field() ?>
                <input type="file" name="cover" accept="application/pdf" required>
                <button type="submit" class="btn btn-primary btn-sm">Upload cover sheet</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head"><h3 style="margin:0; font-size:15px;">Delivery confirmation</h3></div>
    <div class="card-body">
        <?php if (permission_check('documents_transmittals', 'manage')): ?>
            <form method="post" action="<?= h(url('/transmittals.php?action=mark_delivered&id=' . (int)$t['id'])) ?>"
                  style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;">
                <?= csrf_field() ?>
                <div>
                    <label class="muted small">Status</label>
                    <select name="status">
                        <option value="sent">sent</option>
                        <option value="delivered"    <?= $t['delivery_status'] === 'delivered'    ? 'selected' : '' ?>>delivered</option>
                        <option value="acknowledged" <?= $t['delivery_status'] === 'acknowledged' ? 'selected' : '' ?>>acknowledged</option>
                        <option value="signed"       <?= $t['delivery_status'] === 'signed'       ? 'selected' : '' ?>>signed</option>
                        <option value="returned"     <?= $t['delivery_status'] === 'returned'     ? 'selected' : '' ?>>returned</option>
                        <option value="failed"       <?= $t['delivery_status'] === 'failed'       ? 'selected' : '' ?>>failed</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 240px;">
                    <label class="muted small">Note (optional)</label>
                    <input type="text" name="delivered_note" maxlength="500">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Update status</button>
            </form>
            <?php if ($t['delivered_at']): ?>
                <p class="muted small" style="margin: 10px 0 0;">
                    Last updated <?= h(dt_display($t['delivered_at'])) ?>
                    <?= $t['delivered_note'] ? ' — ' . h($t['delivered_note']) : '' ?>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">No permission to update delivery status.</p>
        <?php endif; ?>
    </div>
</div>

<?php
    // Recent emails sent against this transmittal (Phase D2.5).
    $recentEmails = sent_emails_for('transmittal', (int)$t['id'], 10);
    if ($recentEmails):
?>
    <div class="card" style="padding: 14px 18px; margin-top: 14px;">
        <div style="display:flex; align-items:baseline; gap:8px; margin-bottom:8px;">
            <strong>Recent emails</strong>
            <span class="muted small"><?= count($recentEmails) ?> sent</span>
        </div>
        <table class="data-table" style="margin: 0;">
            <thead><tr>
                <th style="width: 130px;">Queued</th>
                <th>To</th>
                <th>Subject</th>
                <th style="width: 90px;">Status</th>
                <th>By</th>
            </tr></thead>
            <tbody>
                <?php foreach ($recentEmails as $em):
                    $statusCls = $em['status'] === 'sent'   ? 'active'
                               : ($em['status'] === 'failed' ? 'danger' : 'muted');
                ?>
                    <tr>
                        <td><?= h(substr((string)$em['queued_at'], 0, 16)) ?></td>
                        <td><?= h($em['to_addrs']) ?>
                            <?php if (!empty($em['cc_addrs'])): ?><div class="muted small">cc: <?= h($em['cc_addrs']) ?></div><?php endif; ?>
                        </td>
                        <td><?= h($em['subject']) ?></td>
                        <td>
                            <span class="pill pill-<?= h($statusCls) ?>"><?= h($em['status']) ?></span>
                            <?php if ($em['status'] === 'failed' && !empty($em['error_message'])): ?>
                                <div class="muted small" style="margin-top:4px; color:#b91c1c;" title="<?= h($em['error_message']) ?>">
                                    <?= h(substr($em['error_message'], 0, 80)) ?><?= strlen($em['error_message']) > 80 ? '…' : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= h($em['sender_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// =============================================================
// GET: list (default)
// =============================================================
$page_title = 'Document Transmittals';
$dt = data_table_run($dtCfg, $rowRenderer);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Document transmittals</h1>
        <p class="muted">Outgoing document send-out events: per-recipient, per-revision, with cover sheets and delivery confirmation.</p>
    </div>
    <div class="head-actions">
        <?php if (permission_check('documents_transmittals', 'create')): ?>
            <a class="btn btn-primary" href="<?= h(url('/transmittals.php?action=new')) ?>">+ New transmittal</a>
        <?php endif; ?>
    </div>
</div>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php
require __DIR__ . '/includes/footer.php';
