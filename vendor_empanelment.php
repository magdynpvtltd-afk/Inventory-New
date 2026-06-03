<?php
/**
 * MagDyn — Vendor Empanelment (Phase E)
 *
 * Actions:
 *   (default)      list of applications (datatable + status filter)
 *   action=new           new application form
 *   action=view&id=N     read-only detail + docs + history + workflow buttons
 *   action=edit&id=N     edit a draft / clarifications application
 *   action=save&id=N     POST handler (new + edit share this)
 *   action=submit&id=N   draft → submitted
 *   action=review&id=N   submitted → under_review (claim for review)
 *   action=clarifications&id=N  under_review → clarifications
 *   action=approve&id=N  under_review → approved (creates / updates vendor)
 *   action=reject&id=N   under_review → rejected
 *   action=reopen&id=N   approved/rejected → under_review (reviewer-only)
 *   action=reset_draft&id=N  send back to draft
 *   action=upload_doc&id=N    attach a supporting document
 *   action=delete_doc&id=N&doc=M  remove a doc
 *   action=download_doc&doc=M    stream the file
 *   action=delete&id=N   delete a draft application (cascade)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_vendor_empanelment.php';
require_once __DIR__ . '/includes/datatable.php';

require_permission('vendor_empanelment', 'view');
$action = (string)input('action', 'list');
$uid    = current_user_id();

$canCreate = permission_check('vendor_empanelment', 'create');
$canEdit   = permission_check('vendor_empanelment', 'edit');
$canSubmit = permission_check('vendor_empanelment', 'submit');
$canReview = permission_check('vendor_empanelment', 'review');
$canDelete = permission_check('vendor_empanelment', 'delete');
$canUpload = permission_check('vendor_empanelment', 'upload_doc');
$canInvite = permission_check('vendor_empanelment', 'invite');
$canRenew  = permission_check('vendor_empanelment', 'renew');


// ============================================================
// SAVE — POST handler (new + edit share this)
// ============================================================
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)input('id', 0);

    if ($id) {
        $existing = db_one("SELECT * FROM vendor_applications WHERE id = ?", [$id]);
        if (!$existing) { flash_set('error', 'Application not found.'); redirect(url('/vendor_empanelment.php')); }
        if (!in_array($existing['status'], ['draft', 'clarifications'], true) && !$canReview) {
            flash_set('error', 'Only draft or clarification applications can be edited.');
            redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
        }
        if (!$canEdit && !$canReview) {
            flash_set('error', 'No permission to edit.');
            redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
        }
    } else {
        if (!$canCreate) { flash_set('error', 'No permission to create.'); redirect(url('/vendor_empanelment.php')); }
    }

    // Collect form fields
    $data = [
        'legal_name'          => trim((string)input('legal_name', '')),
        'trade_name'          => trim((string)input('trade_name', '')) ?: null,
        'business_type'       => (string)input('business_type', 'pvt_ltd'),
        'year_established'    => input('year_established', '') !== '' ? (int)input('year_established', 0) : null,
        'employee_count'      => input('employee_count', '') !== '' ? (int)input('employee_count', 0) : null,
        'annual_turnover_range' => trim((string)input('annual_turnover_range', '')) ?: null,
        'existing_vendor_id'  => input('existing_vendor_id', '') !== '' ? (int)input('existing_vendor_id', 0) : null,

        'address_line1'  => trim((string)input('address_line1', '')) ?: null,
        'address_line2'  => trim((string)input('address_line2', '')) ?: null,
        'city'           => trim((string)input('city', '')) ?: null,
        'state'          => trim((string)input('state', '')) ?: null,
        'pincode'        => trim((string)input('pincode', '')) ?: null,
        'country'        => trim((string)input('country', 'India')) ?: 'India',

        'pan_no'         => strtoupper(trim((string)input('pan_no', ''))) ?: null,
        'gst_no'         => strtoupper(trim((string)input('gst_no', ''))) ?: null,
        'msme_no'        => trim((string)input('msme_no', '')) ?: null,
        'udyam_no'       => trim((string)input('udyam_no', '')) ?: null,
        'cin'            => strtoupper(trim((string)input('cin', ''))) ?: null,

        'bank_name'         => trim((string)input('bank_name', '')) ?: null,
        'bank_branch'       => trim((string)input('bank_branch', '')) ?: null,
        'bank_account_no'   => trim((string)input('bank_account_no', '')) ?: null,
        'bank_account_type' => trim((string)input('bank_account_type', '')) ?: null,
        'bank_ifsc'         => strtoupper(trim((string)input('bank_ifsc', ''))) ?: null,

        'contact_salutation'  => trim((string)input('contact_salutation', '')) ?: null,
        'contact_name'        => trim((string)input('contact_name', '')) ?: null,
        'contact_designation' => trim((string)input('contact_designation', '')) ?: null,
        'contact_email'       => trim((string)input('contact_email', '')) ?: null,
        'contact_phone'       => trim((string)input('contact_phone', '')) ?: null,

        'categories'        => trim((string)input('categories', '')) ?: null,
        'capabilities'      => trim((string)input('capabilities', '')) ?: null,
        'iso_certified'     => (int)!!input('iso_certified', 0),
        'iso_certificate_no' => trim((string)input('iso_certificate_no', '')) ?: null,

        'nda_template_id'   => input('nda_template_id', '') !== '' ? (int)input('nda_template_id', 0) : null,

        'notes'             => trim((string)input('notes', '')) ?: null,
    ];

    // Validation: legal_name required, contact_email if given must be valid,
    // PAN/GST/IFSC if given must be plausibly shaped. We surface warnings,
    // not hard errors, since vendor codes occasionally have weird shapes.
    if ($data['legal_name'] === '') {
        flash_set('error', 'Legal name is required.');
        redirect(url($id ? '/vendor_empanelment.php?action=edit&id=' . $id : '/vendor_empanelment.php?action=new'));
    }
    if ($data['contact_email'] && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Contact email looks invalid.');
        redirect(url($id ? '/vendor_empanelment.php?action=edit&id=' . $id : '/vendor_empanelment.php?action=new'));
    }

    // category_ids[] from multi-checkbox widget — write to junction
    $catIds = (array)input('category_ids', []);

    if ($id) {
        // UPDATE
        $sql = "UPDATE vendor_applications SET ";
        $sets = [];
        $vals = [];
        foreach ($data as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
        $sql .= implode(', ', $sets) . " WHERE id = ?";
        $vals[] = $id;
        db_exec($sql, $vals);
        ve_set_application_categories($id, $catIds);
        flash_set('success', 'Application saved.');
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    } else {
        // INSERT
        try { $appNo = code_next('vendor_application'); }
        catch (\Throwable $e) {
            // Fallback if sequence row missing
            $maxId = (int)db_one("SELECT COALESCE(MAX(id),0) AS m FROM vendor_applications")['m'];
            $appNo = sprintf('VAPP-%05d', $maxId + 1);
        }
        $cols = array_keys($data);
        $cols[] = 'application_no';   $vals = array_values($data); $vals[] = $appNo;
        $cols[] = 'created_by';       $vals[] = (int)$uid;
        $placeholders = implode(',', array_fill(0, count($vals), '?'));
        db_exec(
            "INSERT INTO vendor_applications (" . implode(',', $cols) . ") VALUES ($placeholders)",
            $vals
        );
        $newId = (int)db()->lastInsertId();
        ve_set_application_categories($newId, $catIds);
        db_exec(
            "INSERT INTO vendor_application_history (application_id, from_status, to_status, note, actor_id)
             VALUES (?, NULL, 'draft', 'Application created.', ?)",
            [$newId, (int)$uid]
        );
        flash_set('success', "Application $appNo created. Add supporting documents, then submit for review.");
        redirect(url('/vendor_empanelment.php?action=view&id=' . $newId));
    }
}


// ============================================================
// Workflow actions
// ============================================================
if (in_array($action, ['submit','review','clarifications','approve','reject','reopen','reset_draft'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)input('id', 0);
    $note = trim((string)input('note', ''));

    if ($action === 'submit')         { if (!$canSubmit) require_permission('vendor_empanelment','submit'); $to = 'submitted'; }
    elseif ($action === 'review')     { if (!$canReview) require_permission('vendor_empanelment','review'); $to = 'under_review'; }
    elseif ($action === 'clarifications') { if (!$canReview) require_permission('vendor_empanelment','review'); $to = 'clarifications'; }
    elseif ($action === 'approve')    { if (!$canReview) require_permission('vendor_empanelment','review'); $to = 'approved'; }
    elseif ($action === 'reject')     { if (!$canReview) require_permission('vendor_empanelment','review'); $to = 'rejected'; }
    elseif ($action === 'reopen')     { if (!$canReview) require_permission('vendor_empanelment','review'); $to = 'under_review'; }
    elseif ($action === 'reset_draft'){ if (!$canEdit)   require_permission('vendor_empanelment','edit');   $to = 'draft'; }

    // Submit-side: warn (but allow) about missing required docs
    if ($to === 'submitted') {
        $full = ve_load($id);
        if ($full) {
            $missing = ve_required_docs($full['app'], $full['docs']);
            if ($missing) {
                $labels = ve_doc_types_labelled();
                $names = array_map(function ($t) use ($labels) { return $labels[$t] ?? $t; }, $missing);
                flash_set('warn', 'Submitted with missing documents: ' . implode(', ', $names) . '. The reviewer will likely send this back for clarifications.');
            }
        }
    }

    if (ve_transition($id, $to, $note ?: null, $uid)) {
        // On approval, materialise / link the vendor row, set expiry,
        // revoke any outstanding portal tokens (they're no longer
        // editable so the vendor doesn't need access).
        if ($to === 'approved') {
            try {
                $vendorId = ve_create_vendor_from_app($id, $uid);
                ve_set_expiry_on_approve($id, $vendorId);
                ve_token_revoke_all_for_app($id);
                $v = db_one("SELECT code, name FROM vendors WHERE id = ?", [$vendorId]);
                $expiry = db_one("SELECT expires_at FROM vendor_applications WHERE id = ?", [$id]);
                $expDate = $expiry && $expiry['expires_at'] ? substr($expiry['expires_at'], 0, 10) : '—';
                flash_set('success', "Application approved. Vendor {$v['code']} — {$v['name']} is now empaneled (valid through $expDate).");
            } catch (\Throwable $e) {
                // Approval went through but vendor creation failed; flag clearly
                flash_set('error', 'Approved, but failed to create/link vendor: ' . $e->getMessage()
                                . ' Edit the vendor manually and link with empanelment_application_id = ' . (int)$id . '.');
            }
        } elseif ($to === 'rejected') {
            // Revoke tokens on reject too — vendor shouldn't be able to
            // keep editing a rejected app.
            ve_token_revoke_all_for_app($id);
            flash_set('success', 'Status updated.');
        } else {
            flash_set('success', 'Status updated.');
        }
    }
    redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
}


// ============================================================
// INVITE — generate a magic-link portal token for the vendor
// Two paths:
//   action=create_invite   — generate token, redirect back to view
//                            with the URL flashed (operator copies it)
//   action=invite_email    — generate token AND send invite email
//                            via SMTP (uses smtp_send + smtp.* settings)
// ============================================================
if ($action === 'create_invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canInvite) require_permission('vendor_empanelment', 'invite');
    csrf_check();
    $id = (int)input('id', 0);
    $app = db_one("SELECT id, application_no, legal_name FROM vendor_applications WHERE id = ?", [$id]);
    if (!$app) { flash_set('error', 'Application not found.'); redirect(url('/vendor_empanelment.php')); }
    $token = ve_token_create($id, $uid, 'fill');
    $portalUrl = ve_portal_url($token);
    flash_set('success', 'Portal link generated. Share with the vendor: ' . $portalUrl);
    redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
}

if ($action === 'invite_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canInvite) require_permission('vendor_empanelment', 'invite');
    csrf_check();
    $id = (int)input('id', 0);
    $app = db_one("SELECT * FROM vendor_applications WHERE id = ?", [$id]);
    if (!$app) { flash_set('error', 'Application not found.'); redirect(url('/vendor_empanelment.php')); }

    $to = trim((string)input('to', $app['contact_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Recipient email is missing or invalid.');
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }
    require_once __DIR__ . '/includes/_email.php';

    $token = ve_token_create($id, $uid, 'fill');
    $portalUrl = ve_portal_url($token);

    $ttlDays = (int)magdyn_setting('empanelment.portal_token_ttl_days', 14);

    $body  = '<p>Dear ' . h($app['contact_name'] ?: $app['legal_name']) . ',</p>';
    $body .= '<p>Magneto Dynamics would like to empanel <strong>' . h($app['legal_name']) . '</strong> as a vendor.</p>';
    $body .= '<p>Please use the secure link below to complete your application — fill in your company details, upload supporting documents (PAN, GST, signed NDA, bank proof), and submit for review.</p>';
    $body .= '<p style="text-align:center; margin: 20px 0;">';
    $body .= '<a href="' . h($portalUrl) . '" style="display:inline-block; background:#2d3a8c; color:#fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: 600;">Open empanelment form</a>';
    $body .= '</p>';
    $body .= '<p class="muted small">This link is unique to your application and remains valid for ' . (int)$ttlDays . ' days. Please don\'t forward it.</p>';
    $body .= '<p>If you have any questions, simply reply to this email.</p>';
    $body .= '<p>Best regards,<br>Magneto Dynamics — Purchase team</p>';

    $res = smtp_send([
        'related_type' => 'vendor_application',
        'related_id'   => $id,
        'to'           => $to,
        'subject'      => 'Vendor empanelment — ' . $app['legal_name'] . ' (' . $app['application_no'] . ')',
        'body_html'    => $body,
        'actor_id'     => $uid,
    ]);
    if ($res['ok']) {
        flash_set('success', 'Invite sent to ' . h($to) . '.');
    } else {
        // Token was created; surface URL anyway so operator can copy/share
        flash_set('error', 'Email send failed: ' . $res['error'] . '. The portal link is: ' . $portalUrl);
    }
    redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
}


// ============================================================
// REVOKE all outstanding portal tokens for this application
// ============================================================
if ($action === 'revoke_tokens' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canInvite) require_permission('vendor_empanelment', 'invite');
    csrf_check();
    $id = (int)input('id', 0);
    ve_token_revoke_all_for_app($id);
    flash_set('success', 'All outstanding portal links revoked.');
    redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
}


// ============================================================
// RENEW — clone an approved (or expired) application into a new draft
// ============================================================
if ($action === 'renew' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canRenew) require_permission('vendor_empanelment', 'renew');
    csrf_check();
    $id = (int)input('id', 0);
    $src = db_one("SELECT * FROM vendor_applications WHERE id = ?", [$id]);
    if (!$src) { flash_set('error', 'Source application not found.'); redirect(url('/vendor_empanelment.php')); }
    if ($src['status'] !== 'approved') {
        flash_set('error', 'Renewal only allowed from approved applications.');
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }

    // Fresh application_no for the renewal
    try { $appNo = code_next('vendor_application'); }
    catch (\Throwable $e) {
        $maxId = (int)db_one("SELECT COALESCE(MAX(id),0) AS m FROM vendor_applications")['m'];
        $appNo = sprintf('VAPP-%05d', $maxId + 1);
    }

    // Carry over all the company / statutory / bank / contact /
    // capability fields. Reset workflow state.
    $copyCols = [
        'legal_name','trade_name','business_type','year_established','employee_count','annual_turnover_range',
        'address_line1','address_line2','city','state','pincode','country',
        'pan_no','gst_no','msme_no','udyam_no','cin',
        'bank_name','bank_branch','bank_account_no','bank_account_type','bank_ifsc',
        'contact_salutation','contact_name','contact_designation','contact_email','contact_phone',
        'categories','capabilities','iso_certified','iso_certificate_no',
        'nda_template_id',
    ];
    $cols = $copyCols;
    $vals = [];
    foreach ($copyCols as $c) $vals[] = $src[$c];

    // Insert metadata
    $cols[] = 'application_no';            $vals[] = $appNo;
    $cols[] = 'status';                    $vals[] = 'draft';
    $cols[] = 'existing_vendor_id';        $vals[] = $src['approved_vendor_id'];   // we know which vendor we're renewing
    $cols[] = 'renewal_of_application_id'; $vals[] = (int)$src['id'];
    $cols[] = 'created_by';                $vals[] = (int)$uid;
    $cols[] = 'notes';                     $vals[] = 'Renewal of ' . $src['application_no'];

    $placeholders = implode(',', array_fill(0, count($vals), '?'));
    db_exec("INSERT INTO vendor_applications (" . implode(',', $cols) . ") VALUES ($placeholders)", $vals);
    $newId = (int)db()->lastInsertId();

    // Copy category junction
    $catIds = ve_application_category_ids((int)$id);
    if ($catIds) ve_set_application_categories($newId, $catIds);

    // History entry on the new app
    db_exec(
        "INSERT INTO vendor_application_history (application_id, from_status, to_status, note, actor_id)
         VALUES (?, NULL, 'draft', ?, ?)",
        [$newId, 'Renewal created from ' . $src['application_no'], (int)$uid]
    );

    flash_set('success', "Renewal draft $appNo created. Update any changed details and resubmit.");
    redirect(url('/vendor_empanelment.php?action=edit&id=' . $newId));
}


// ============================================================
// UPLOAD DOC
// ============================================================
if ($action === 'upload_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canUpload) require_permission('vendor_empanelment', 'upload_doc');
    csrf_check();
    $id = (int)input('id', 0);
    $app = db_one("SELECT id, status FROM vendor_applications WHERE id = ?", [$id]);
    if (!$app) { flash_set('error', 'Application not found.'); redirect(url('/vendor_empanelment.php')); }

    $docType = (string)input('doc_type', 'other');
    $allowedTypes = array_keys(ve_doc_types_labelled());
    if (!in_array($docType, $allowedTypes, true)) $docType = 'other';
    $description = trim((string)input('description', '')) ?: null;

    if (empty($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = (int)($_FILES['file']['error'] ?? -1);
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL  => 'Upload was interrupted.',
            UPLOAD_ERR_NO_FILE  => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no tmp directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file.',
        ];
        flash_set('error', 'Upload failed: ' . ($errMap[$errCode] ?? "code $errCode"));
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }
    if ((int)$_FILES['file']['size'] > 15 * 1024 * 1024) {
        flash_set('error', 'File too large (15 MB max).');
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }
    $blockedExt = ['exe','bat','cmd','sh','js','vbs','msi','scr','php','phtml'];
    $origName = basename($_FILES['file']['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (in_array($ext, $blockedExt, true)) {
        flash_set('error', "File type .$ext is not allowed.");
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }

    $dir = ve_doc_dir($id);
    // Filename: <doctype>_<ts>_<safe-orig>.<ext>
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
    $stored = $docType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
    $abs = $dir . '/' . $stored;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $abs)) {
        flash_set('error', 'Failed to save uploaded file.');
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }

    // Path stored relative to the app root
    $rel = 'uploads/vendor_applications/' . $id . '/' . $stored;
    db_exec(
        "INSERT INTO vendor_application_documents
            (application_id, doc_type, file_name, file_path, file_mime, file_size, description, uploaded_by)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [$id, $docType, $origName, $rel, @mime_content_type($abs), (int)filesize($abs), $description, (int)$uid]
    );
    ve_refresh_nda_flag($id);
    flash_set('success', 'Document uploaded.');
    redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
}


// ============================================================
// DELETE DOC
// ============================================================
if ($action === 'delete_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canUpload) require_permission('vendor_empanelment', 'upload_doc');
    csrf_check();
    $id    = (int)input('id', 0);
    $docId = (int)input('doc', 0);
    $doc = db_one("SELECT * FROM vendor_application_documents WHERE id = ? AND application_id = ?", [$docId, $id]);
    if (!$doc) { flash_set('error', 'Document not found.'); redirect(url('/vendor_empanelment.php?action=view&id=' . $id)); }
    $abs = __DIR__ . '/' . ltrim($doc['file_path'], '/');
    if (is_file($abs)) @unlink($abs);
    db_exec("DELETE FROM vendor_application_documents WHERE id = ?", [$docId]);
    ve_refresh_nda_flag($id);
    flash_set('success', 'Document removed.');
    redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
}


// ============================================================
// DOWNLOAD DOC
// ============================================================
if ($action === 'download_doc') {
    $docId = (int)input('doc', 0);
    $doc = db_one("SELECT * FROM vendor_application_documents WHERE id = ?", [$docId]);
    if (!$doc) { http_response_code(404); echo 'Not found.'; exit; }
    $abs = __DIR__ . '/' . ltrim($doc['file_path'], '/');
    if (!is_file($abs)) { http_response_code(404); echo 'File missing on disk.'; exit; }
    header('Content-Type: ' . ($doc['file_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . addslashes($doc['file_name']) . '"');
    readfile($abs);
    exit;
}


// ============================================================
// DELETE application
// ============================================================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canDelete) require_permission('vendor_empanelment', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $app = db_one("SELECT id, status FROM vendor_applications WHERE id = ?", [$id]);
    if (!$app) { flash_set('error', 'Not found.'); redirect(url('/vendor_empanelment.php')); }
    if ($app['status'] !== 'draft' && !$canReview) {
        flash_set('error', 'Only draft applications can be deleted (reviewers can delete any).');
        redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
    }
    // Wipe uploaded files
    $dir = ve_doc_dir($id);
    foreach ((array)@glob($dir . '/*') as $f) @unlink($f);
    @rmdir($dir);
    db_exec("DELETE FROM vendor_applications WHERE id = ?", [$id]);
    flash_set('success', 'Application deleted.');
    redirect(url('/vendor_empanelment.php'));
}


// ============================================================
// LIST — datatable
// ============================================================
if ($action === 'list' || $action === '') {
    $statusFilter   = (string)input('status', '');
    $categoryFilter = (int)input('category', 0);
    $expiryFilter   = (string)input('expiry', '');

    // The datatable helper takes extra_where as a list of
    // [sql_fragment, params_array] tuples. Each fragment is appended
    // to WHERE with AND. Using ? bound params keeps quoting safe even
    // though status is already a controlled enum value.
    $extraWhere = [];
    if ($statusFilter && array_key_exists($statusFilter, ve_status_meta())) {
        $extraWhere[] = ['a.status = ?', [$statusFilter]];
    }
    if ($categoryFilter) {
        // EXISTS subquery is cheaper than a JOIN when filtering on a
        // single category (junction has compound PK).
        $extraWhere[] = ['EXISTS (SELECT 1 FROM vendor_application_categories vac WHERE vac.application_id = a.id AND vac.category_id = ?)', [$categoryFilter]];
    }
    $reminderDays = (int)magdyn_setting('empanelment.reminder_days_before', 30);
    if ($expiryFilter === 'expiring') {
        $extraWhere[] = ['a.expires_at IS NOT NULL AND a.expires_at > NOW() AND a.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)', [$reminderDays]];
    } elseif ($expiryFilter === 'expired') {
        $extraWhere[] = ['a.expires_at IS NOT NULL AND a.expires_at <= NOW()', []];
    }

    $dtCfg = [
        'id'        => 'vendor_applications',
        'title'     => 'Vendor Empanelment',
        'base_sql'  => "
            SELECT a.id, a.application_no, a.legal_name, a.trade_name, a.status,
                   a.pan_no, a.gst_no, a.nda_on_file, a.expires_at,
                   a.created_at, a.submitted_at, a.reviewed_at,
                   u.full_name AS created_by_name,
                   v.code      AS approved_vendor_code,
                   v.name      AS approved_vendor_name,
                   (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order SEPARATOR ', ')
                      FROM vendor_application_categories vac
                      JOIN vendor_categories c ON c.id = vac.category_id
                     WHERE vac.application_id = a.id) AS categories_csv
              FROM vendor_applications a
         LEFT JOIN users   u ON u.id = a.created_by
         LEFT JOIN vendors v ON v.id = a.approved_vendor_id",
        'extra_where' => $extraWhere,
        'columns' => [
            ['key'=>'application_no',       'label'=>'App #',     'sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.application_no'],
            ['key'=>'legal_name',           'label'=>'Vendor',    'sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.legal_name'],
            ['key'=>'categories_csv',       'label'=>'Categories','sortable'=>false, 'searchable'=>true,  'sql_col'=>'a.legal_name'],   // search piggy-backs name
            ['key'=>'status',               'label'=>'Status',    'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.status'],
            ['key'=>'pan_no',               'label'=>'PAN / GST', 'sortable'=>true,  'searchable'=>true,  'sql_col'=>'a.pan_no'],
            ['key'=>'nda_on_file',          'label'=>'NDA',       'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.nda_on_file'],
            ['key'=>'approved_vendor_code', 'label'=>'Vendor',    'sortable'=>true,  'searchable'=>true,  'sql_col'=>'v.code'],
            ['key'=>'expires_at',           'label'=>'Expires',   'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.expires_at',  'td_class'=>'nowrap'],
            ['key'=>'created_at',           'label'=>'Created',   'sortable'=>true,  'searchable'=>false, 'sql_col'=>'a.created_at',  'td_class'=>'nowrap'],
        ],
        'default_sort' => ['created_at', 'desc'],
        'actions_html' => $canCreate
            ? '<a class="btn btn-primary btn-sm" href="' . h(url('/vendor_empanelment.php?action=new')) . '">＋ New application</a>'
            : '',
    ];

    $rowRenderer = function ($r) {
        $statusMeta = ve_status_meta();
        $m = $statusMeta[$r['status']] ?? ['label' => $r['status'], 'pill' => 'muted'];

        $legal = '<strong>' . h($r['legal_name']) . '</strong>';
        if (!empty($r['trade_name']) && $r['trade_name'] !== $r['legal_name']) {
            $legal .= ' <span class="muted small">(' . h($r['trade_name']) . ')</span>';
        }

        $panGst = $r['pan_no'] ? h($r['pan_no']) : '<span class="muted">—</span>';
        if ($r['gst_no']) $panGst .= '<br><span class="muted small">' . h($r['gst_no']) . '</span>';

        // Categories cell: chip-style pills, capped at 3 with overflow count
        $cats = '';
        if (!empty($r['categories_csv'])) {
            $list = array_filter(array_map('trim', explode(', ', $r['categories_csv'])));
            $shown = array_slice($list, 0, 3);
            foreach ($shown as $c) {
                $cats .= '<span class="pill pill-info" style="margin: 0 3px 2px 0; font-size:10.5px;">' . h($c) . '</span> ';
            }
            $extra = count($list) - count($shown);
            if ($extra > 0) $cats .= '<span class="muted small">+' . $extra . '</span>';
        } else {
            $cats = '<span class="muted">—</span>';
        }

        // Expires cell: human date + state badge (ok/expiring/expired/none)
        $expState = ve_expiry_state($r);
        if ($expState === 'none') {
            $expHtml = '<span class="muted">—</span>';
        } else {
            $expHtml = h(substr((string)$r['expires_at'], 0, 10));
            if ($expState === 'expired')       $expHtml .= '<br><span class="pill pill-danger" style="font-size:10.5px;">expired</span>';
            elseif ($expState === 'expiring')  $expHtml .= '<br><span class="pill pill-warn" style="font-size:10.5px;">expiring</span>';
        }

        return [
            'application_no'       => '<a href="' . h(url('/vendor_empanelment.php?action=view&id=' . (int)$r['id'])) . '"><strong>' . h($r['application_no']) . '</strong></a>',
            'legal_name'           => $legal,
            'categories_csv'       => $cats,
            'status'               => '<span class="pill pill-' . h($m['pill']) . '">' . h($m['label']) . '</span>',
            'pan_no'               => $panGst,
            'nda_on_file'          => $r['nda_on_file'] ? '<span class="pill pill-active">yes</span>' : '<span class="muted small">—</span>',
            'approved_vendor_code' => $r['approved_vendor_code']
                ? '<span class="pill pill-active">' . h($r['approved_vendor_code']) . '</span>'
                : '<span class="muted">—</span>',
            'expires_at'           => $expHtml,
            'created_at'           => h(substr((string)$r['created_at'], 0, 16))
                                    . '<br><span class="muted small">' . h($r['created_by_name'] ?: '—') . '</span>',
        ];
    };

    // Run the query BEFORE header.php so any redirect (e.g. on bad
    // page-size cookie) can still send headers.
    $dt = data_table_run($dtCfg, $rowRenderer);

    // Build a query-string helper that preserves all active filters
    // except the one being changed. Lets us toggle status without
    // losing category, etc.
    $linkPreserve = function (array $override = []) use ($statusFilter, $categoryFilter, $expiryFilter) {
        $q = array_filter([
            'status'   => $statusFilter,
            'category' => $categoryFilter ?: '',
            'expiry'   => $expiryFilter,
        ], function ($v) { return $v !== '' && $v !== 0; });
        foreach ($override as $k => $v) {
            if ($v === null || $v === '' || $v === 0) unset($q[$k]);
            else $q[$k] = $v;
        }
        return url('/vendor_empanelment.php' . ($q ? ('?' . http_build_query($q)) : ''));
    };

    // Load category list once for the filter strip.
    $allCats = ve_categories_get_all(true);

    $page_title  = 'Vendor Empanelment';
    $page_module = 'vendor_empanelment';
    require __DIR__ . '/includes/header.php';
    ?>
    <!-- Status filter strip -->
    <div style="margin-bottom: 6px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
        <span class="muted small" style="margin-right: 4px;">Status:</span>
        <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['status' => null])) ?>"
           style="<?= $statusFilter === '' ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">All</a>
        <?php foreach (ve_status_meta() as $code => $meta): ?>
            <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['status' => $code])) ?>"
               style="<?= $statusFilter === $code ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">
                <?= h($meta['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Expiry filter strip -->
    <div style="margin-bottom: 6px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
        <span class="muted small" style="margin-right: 4px;">Expiry:</span>
        <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['expiry' => null])) ?>"
           style="<?= $expiryFilter === '' ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">Any</a>
        <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['expiry' => 'expiring'])) ?>"
           style="<?= $expiryFilter === 'expiring' ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">⚠ Expiring soon</a>
        <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['expiry' => 'expired'])) ?>"
           style="<?= $expiryFilter === 'expired' ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">Expired</a>
    </div>

    <!-- Category filter strip -->
    <div style="margin-bottom: 12px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
        <span class="muted small" style="margin-right: 4px;">Category:</span>
        <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['category' => null])) ?>"
           style="<?= $categoryFilter === 0 ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">All</a>
        <?php foreach ($allCats as $c): ?>
            <a class="btn btn-ghost btn-sm" href="<?= h($linkPreserve(['category' => (int)$c['id']])) ?>"
               style="<?= $categoryFilter === (int)$c['id'] ? 'border-color: var(--primary); font-weight: 600;' : '' ?>">
                <?= h($c['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    data_table_render($dtCfg, $dt, $rowRenderer);
    require __DIR__ . '/includes/footer.php';
    exit;
}


// ============================================================
// NEW / EDIT — both render the form
// ============================================================
if ($action === 'new' || $action === 'edit') {
    if ($action === 'new')  {
        if (!$canCreate) require_permission('vendor_empanelment', 'create');
        $app = [];   // all fields blank/defaulted
        $isNew = true;
        $id = 0;
    } else {
        $id = (int)input('id', 0);
        $full = ve_load($id);
        if (!$full) { flash_set('error', 'Application not found.'); redirect(url('/vendor_empanelment.php')); }
        $app = $full['app'];
        $isNew = false;
        if (!in_array($app['status'], ['draft','clarifications'], true) && !$canReview) {
            flash_set('error', 'Only draft or clarifications applications are editable.');
            redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
        }
        if (!$canEdit && !$canReview) {
            flash_set('error', 'No permission to edit.');
            redirect(url('/vendor_empanelment.php?action=view&id=' . $id));
        }
    }

    // Vendor list for the optional "empanel existing vendor" picker
    $vendorsList = db_all("SELECT id, code, name FROM vendors WHERE is_active = 1 ORDER BY name");

    $page_title  = $isNew ? 'New Vendor Application' : ('Edit Application ' . ($app['application_no'] ?? ''));
    $page_module = 'vendor_empanelment';
    require __DIR__ . '/includes/header.php';

    $v = function ($key, $default = '') use ($app) {
        if (!isset($app[$key]) || $app[$key] === null) return $default;
        return $app[$key];
    };
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($page_title) ?></h1>
            <p class="muted small">
                <a href="<?= h(url('/vendor_empanelment.php')) ?>">← All applications</a>
                <?php if (!$isNew): ?> · <a href="<?= h(url('/vendor_empanelment.php?action=view&id=' . $id)) ?>">View</a><?php endif; ?>
            </p>
        </div>
    </div>

    <form method="post" action="<?= h(url('/vendor_empanelment.php?action=save')) ?>" class="card" style="padding: 16px; max-width: 1100px;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <!-- ============= Company identity ============= -->
        <h2 style="margin-top: 0;">Company identity</h2>
        <div class="form-grid">
            <div class="field">
                <label for="f_legal_name">Legal name <span class="muted small">*</span></label>
                <input type="text" id="f_legal_name" name="legal_name" required maxlength="190"
                       value="<?= h($v('legal_name')) ?>">
            </div>
            <div class="field">
                <label for="f_trade_name">Trade name <span class="muted small">(brand / DBA, if different)</span></label>
                <input type="text" id="f_trade_name" name="trade_name" maxlength="190"
                       value="<?= h($v('trade_name')) ?>">
            </div>
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="f_business_type">Business type</label>
                <select id="f_business_type" name="business_type" class="no-combobox">
                    <?php foreach (ve_business_types_labelled() as $code => $label): ?>
                        <option value="<?= h($code) ?>" <?= $v('business_type', 'pvt_ltd') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_year_established">Year established</label>
                <input type="number" id="f_year_established" name="year_established" min="1900" max="<?= (int)date('Y') ?>"
                       value="<?= h($v('year_established')) ?>">
            </div>
            <div class="field">
                <label for="f_employee_count">Employee count</label>
                <input type="number" id="f_employee_count" name="employee_count" min="0"
                       value="<?= h($v('employee_count')) ?>">
            </div>
            <div class="field">
                <label for="f_annual_turnover_range">Annual turnover (range)</label>
                <input type="text" id="f_annual_turnover_range" name="annual_turnover_range" maxlength="40"
                       placeholder="e.g. ₹1Cr - ₹5Cr"
                       value="<?= h($v('annual_turnover_range')) ?>">
            </div>
        </div>
        <div class="field">
            <label for="f_existing_vendor_id">Link to existing vendor <span class="muted small">(if empaneling a current vendor)</span></label>
            <select id="f_existing_vendor_id" name="existing_vendor_id">
                <option value="">— New vendor record will be created on approval —</option>
                <?php foreach ($vendorsList as $vv): ?>
                    <option value="<?= (int)$vv['id'] ?>" <?= (int)$v('existing_vendor_id', 0) === (int)$vv['id'] ? 'selected' : '' ?>>
                        <?= h($vv['code']) ?> — <?= h($vv['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- ============= Registered address ============= -->
        <h2>Registered address</h2>
        <div class="form-grid">
            <div class="field" style="grid-column: span 2;">
                <label for="f_address_line1">Line 1</label>
                <input type="text" id="f_address_line1" name="address_line1" maxlength="200" value="<?= h($v('address_line1')) ?>">
            </div>
            <div class="field" style="grid-column: span 2;">
                <label for="f_address_line2">Line 2</label>
                <input type="text" id="f_address_line2" name="address_line2" maxlength="200" value="<?= h($v('address_line2')) ?>">
            </div>
        </div>
        <div class="form-grid">
            <div class="field"><label for="f_city">City</label>
                <input type="text" id="f_city" name="city" maxlength="120" value="<?= h($v('city')) ?>"></div>
            <div class="field"><label for="f_state">State</label>
                <input type="text" id="f_state" name="state" maxlength="120" value="<?= h($v('state')) ?>"></div>
            <div class="field"><label for="f_pincode">PIN code</label>
                <input type="text" id="f_pincode" name="pincode" maxlength="20" value="<?= h($v('pincode')) ?>"></div>
            <div class="field"><label for="f_country">Country</label>
                <input type="text" id="f_country" name="country" maxlength="120" value="<?= h($v('country', 'India')) ?>"></div>
        </div>

        <!-- ============= Statutory ============= -->
        <h2>Statutory</h2>
        <div class="form-grid">
            <div class="field"><label for="f_pan_no">PAN <span class="muted small">(10 chars: 5 letters + 4 digits + 1 letter)</span></label>
                <input type="text" id="f_pan_no" name="pan_no" maxlength="20" style="text-transform: uppercase;"
                       pattern="[A-Z]{5}[0-9]{4}[A-Z]" title="PAN format: AAAAA9999A"
                       value="<?= h($v('pan_no')) ?>"></div>
            <div class="field"><label for="f_gst_no">GST / GSTIN <span class="muted small">(15 chars)</span></label>
                <input type="text" id="f_gst_no" name="gst_no" maxlength="20" style="text-transform: uppercase;"
                       value="<?= h($v('gst_no')) ?>"></div>
            <div class="field"><label for="f_msme_no">MSME registration</label>
                <input type="text" id="f_msme_no" name="msme_no" maxlength="40" value="<?= h($v('msme_no')) ?>"></div>
            <div class="field"><label for="f_udyam_no">Udyam registration</label>
                <input type="text" id="f_udyam_no" name="udyam_no" maxlength="40" placeholder="UDYAM-XX-00-0000000"
                       value="<?= h($v('udyam_no')) ?>"></div>
            <div class="field"><label for="f_cin">CIN <span class="muted small">(for incorporated companies)</span></label>
                <input type="text" id="f_cin" name="cin" maxlength="30" style="text-transform: uppercase;"
                       value="<?= h($v('cin')) ?>"></div>
        </div>

        <!-- ============= Bank ============= -->
        <h2>Bank details</h2>
        <div class="form-grid">
            <div class="field"><label for="f_bank_name">Bank</label>
                <input type="text" id="f_bank_name" name="bank_name" maxlength="190" value="<?= h($v('bank_name')) ?>"></div>
            <div class="field"><label for="f_bank_branch">Branch</label>
                <input type="text" id="f_bank_branch" name="bank_branch" maxlength="190" value="<?= h($v('bank_branch')) ?>"></div>
            <div class="field"><label for="f_bank_account_no">Account no</label>
                <input type="text" id="f_bank_account_no" name="bank_account_no" maxlength="40" value="<?= h($v('bank_account_no')) ?>"></div>
            <div class="field"><label for="f_bank_account_type">Account type</label>
                <select id="f_bank_account_type" name="bank_account_type" class="no-combobox">
                    <option value="">—</option>
                    <?php foreach (ve_account_types_labelled() as $code => $label): ?>
                        <option value="<?= h($code) ?>" <?= $v('bank_account_type') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="f_bank_ifsc">IFSC <span class="muted small">(4 letters + 0 + 6 alphanumeric)</span></label>
                <input type="text" id="f_bank_ifsc" name="bank_ifsc" maxlength="20" style="text-transform: uppercase;"
                       pattern="[A-Z]{4}0[A-Z0-9]{6}" title="IFSC format: AAAA0AAAAAA"
                       value="<?= h($v('bank_ifsc')) ?>"></div>
        </div>

        <!-- ============= Primary contact ============= -->
        <h2>Primary contact</h2>
        <div class="form-grid">
            <div class="field" style="max-width:130px;"><label for="f_contact_salutation">Salutation</label>
                <input type="text" id="f_contact_salutation" name="contact_salutation" maxlength="10"
                       value="<?= h($v('contact_salutation')) ?>"></div>
            <div class="field"><label for="f_contact_name">Name</label>
                <input type="text" id="f_contact_name" name="contact_name" maxlength="150"
                       value="<?= h($v('contact_name')) ?>"></div>
            <div class="field"><label for="f_contact_designation">Designation</label>
                <input type="text" id="f_contact_designation" name="contact_designation" maxlength="120"
                       value="<?= h($v('contact_designation')) ?>"></div>
            <div class="field"><label for="f_contact_email">Email</label>
                <input type="email" id="f_contact_email" name="contact_email" maxlength="190"
                       value="<?= h($v('contact_email')) ?>"></div>
            <div class="field"><label for="f_contact_phone">Phone</label>
                <input type="text" id="f_contact_phone" name="contact_phone" maxlength="40"
                       value="<?= h($v('contact_phone')) ?>"></div>
        </div>

        <!-- ============= Capabilities ============= -->
        <h2>Capabilities</h2>
        <?php
        // Load the active categories list + already-checked ids on edit
        $allCatsForm = ve_categories_get_all(true);
        $checkedCats = $isNew ? [] : array_flip(ve_application_category_ids((int)$id));
        ?>
        <div class="field">
            <label>Categories of supply <span class="muted small">(tick all that apply)</span></label>
            <div style="display:flex; flex-wrap:wrap; gap:6px; padding:8px;
                        border:1px solid var(--border); border-radius:4px;">
                <?php foreach ($allCatsForm as $c): ?>
                    <label class="inline" style="gap:5px; padding:4px 9px; border:1px solid var(--border);
                                                  border-radius:14px; background:#fff; cursor:pointer; font-size:13px;">
                        <input type="checkbox" name="category_ids[]" value="<?= (int)$c['id'] ?>"
                               <?= isset($checkedCats[(int)$c['id']]) ? 'checked' : '' ?>>
                        <?= h($c['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="muted small" style="margin: 4px 0 0;">
                Need a category we don't list? Add a row to <code>vendor_categories</code>; it'll show up here.
            </p>
        </div>
        <div class="field" style="display: none;">
            <!-- Legacy comma-separated categories. Kept for backward compat; the
                 junction is canonical. Hidden so operators don't double-enter. -->
            <input type="hidden" name="categories" value="<?= h($v('categories')) ?>">
        </div>
        <div class="field">
            <label for="f_capabilities">Capability summary</label>
            <textarea id="f_capabilities" name="capabilities" rows="4"><?= h($v('capabilities')) ?></textarea>
        </div>
        <div class="form-grid">
            <div class="field">
                <label class="inline" style="gap: 6px;">
                    <input type="checkbox" name="iso_certified" value="1" <?= $v('iso_certified', 0) ? 'checked' : '' ?>>
                    ISO certified
                </label>
            </div>
            <div class="field">
                <label for="f_iso_certificate_no">ISO certificate number</label>
                <input type="text" id="f_iso_certificate_no" name="iso_certificate_no" maxlength="120"
                       value="<?= h($v('iso_certificate_no')) ?>">
            </div>
        </div>

        <!-- ============= NDA template ============= -->
        <?php $ndaTpls = ve_nda_templates_active(); ?>
        <h2>NDA template</h2>
        <div class="field">
            <label for="f_nda_template_id">Template to send to vendor</label>
            <?php if (!$ndaTpls): ?>
                <p class="muted small">
                    No active NDA templates available.
                    <?php if (permission_check('nda_templates', 'manage')): ?>
                        <a href="<?= h(url('/nda_templates.php')) ?>">Upload one</a>.
                    <?php else: ?>
                        Ask an admin to upload one in NDA Templates.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <select id="f_nda_template_id" name="nda_template_id" class="no-combobox">
                    <option value="">— No NDA template (vendor uploads their own) —</option>
                    <?php foreach ($ndaTpls as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (int)$v('nda_template_id', 0) === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?> (v<?= h($t['version']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted small" style="margin: 4px 0 0;">
                    If selected, the vendor will see a download link for the template on the portal form.
                </p>
            <?php endif; ?>
        </div>

        <!-- ============= Notes ============= -->
        <h2>Internal notes</h2>
        <div class="field">
            <textarea name="notes" rows="3" placeholder="Internal notes about this application, not shown to the vendor"><?= h($v('notes')) ?></textarea>
        </div>

        <div class="form-actions" style="margin-top: 18px;">
            <button type="submit" class="btn btn-primary">💾 Save</button>
            <a class="btn btn-ghost" href="<?= h($isNew ? url('/vendor_empanelment.php') : url('/vendor_empanelment.php?action=view&id=' . $id)) ?>">Cancel</a>
        </div>
    </form>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}


// ============================================================
// VIEW
// ============================================================
if ($action === 'view') {
    $id = (int)input('id', 0);
    $full = ve_load($id);
    if (!$full) { flash_set('error', 'Application not found.'); redirect(url('/vendor_empanelment.php')); }
    $app = $full['app'];
    $statusMeta = ve_status_meta()[$app['status']];
    $missing = ve_required_docs($app, $full['docs']);
    $missingLabels = ve_doc_types_labelled();

    $page_title  = 'Application ' . $app['application_no'];
    $page_module = 'vendor_empanelment';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1><?= h($app['application_no']) ?> — <?= h($app['legal_name']) ?>
                <span class="pill pill-<?= h($statusMeta['pill']) ?>" style="margin-left:8px; vertical-align:middle;"><?= h($statusMeta['label']) ?></span>
            </h1>
            <p class="muted small">
                <a href="<?= h(url('/vendor_empanelment.php')) ?>">← All applications</a>
                <?php if ($full['approved_vendor']): ?>
                  · Empaneled as <a href="<?= h(url('/vendors.php?action=view&id=' . (int)$full['approved_vendor']['id'])) ?>"><strong><?= h($full['approved_vendor']['code']) ?></strong> — <?= h($full['approved_vendor']['name']) ?></a>
                <?php endif; ?>
            </p>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if (in_array($app['status'], ['draft','clarifications'], true) && ($canEdit || $canReview)): ?>
                <a class="btn btn-primary" href="<?= h(url('/vendor_empanelment.php?action=edit&id=' . $id)) ?>">✏ Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($missing && in_array($app['status'], ['draft','clarifications'], true)): ?>
        <div class="alert alert-warn" style="margin-bottom: 12px;">
            <strong>Missing required documents:</strong>
            <?= h(implode(', ', array_map(function ($t) use ($missingLabels) { return $missingLabels[$t] ?? $t; }, $missing))) ?>
            — upload below before submitting.
        </div>
    <?php endif; ?>

    <!-- ============= Expiry + renewal banner (approved apps) ============= -->
    <?php
    $expState = ve_expiry_state($app);
    if (in_array($expState, ['expiring', 'expired'], true)):
    ?>
        <div class="alert alert-<?= $expState === 'expired' ? 'warn' : 'warn' ?>" style="margin-bottom: 12px;">
            <strong><?= $expState === 'expired' ? '⏰ Empanelment expired' : '⚠ Empanelment expiring soon' ?>.</strong>
            Valid through <?= h(substr($app['expires_at'], 0, 10)) ?>.
            <?php if ($canRenew && $app['status'] === 'approved'): ?>
                <form method="post" action="<?= h(url('/vendor_empanelment.php?action=renew')) ?>" style="display:inline; margin-left:8px;"
                      onsubmit="return confirm('Create a renewal draft from this approved application?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-primary btn-sm">↻ Start renewal</button>
                </form>
            <?php endif; ?>
        </div>
    <?php elseif ($expState === 'ok'): ?>
        <p class="muted small" style="margin: 0 0 12px;">
            Empanelment valid through <strong><?= h(substr($app['expires_at'], 0, 10)) ?></strong>.
        </p>
    <?php endif; ?>

    <!-- ============= Workflow buttons row ============= -->
    <div class="card" style="padding: 12px; margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <span class="muted small">Workflow:</span>

        <?php if ($app['status'] === 'draft' && $canSubmit): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=submit')) ?>" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-primary btn-sm">📤 Submit for review</button>
            </form>
        <?php endif; ?>

        <?php if ($app['status'] === 'submitted' && $canReview): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=review')) ?>" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-primary btn-sm">🔍 Start review</button>
            </form>
        <?php endif; ?>

        <?php if ($app['status'] === 'under_review' && $canReview): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=approve')) ?>" style="display:inline;"
                  onsubmit="return confirm('Approve this application? A vendor record will be created (or linked to the existing one) and they\'ll be empaneled.');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="text" name="note" placeholder="Approval note (optional)" style="width: 220px;">
                <button type="submit" class="btn btn-primary btn-sm">✅ Approve</button>
            </form>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=clarifications')) ?>" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="text" name="note" placeholder="What's needed?" required style="width: 220px;">
                <button type="submit" class="btn btn-ghost btn-sm">❓ Request clarifications</button>
            </form>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=reject')) ?>" style="display:inline;"
                  onsubmit="return confirm('Reject this application? They will not be empaneled.');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="text" name="note" placeholder="Reason" required style="width: 220px;">
                <button type="submit" class="btn btn-danger btn-sm">❌ Reject</button>
            </form>
        <?php endif; ?>

        <?php if ($app['status'] === 'clarifications' && $canSubmit): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=submit')) ?>" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-primary btn-sm">📤 Resubmit for review</button>
            </form>
        <?php endif; ?>

        <?php if (in_array($app['status'], ['approved','rejected'], true) && $canReview): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=reopen')) ?>" style="display:inline;"
                  onsubmit="return confirm('Reopen this application for re-review?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-ghost btn-sm">↻ Reopen</button>
            </form>
        <?php endif; ?>

        <?php if ($app['status'] === 'approved' && $canRenew): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=renew')) ?>" style="display:inline;"
                  onsubmit="return confirm('Create a renewal draft from this approved application?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-ghost btn-sm">↻ Renew</button>
            </form>
        <?php endif; ?>

        <?php if ($app['status'] === 'draft' && $canDelete): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=delete')) ?>" style="display:inline; margin-left:auto;"
                  onsubmit="return confirm('Delete this draft application and all its documents?');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑 Delete draft</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ============= Details ============= -->
    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
        <div class="card" style="padding: 14px 18px;">
            <h2 style="margin-top: 0;">Company</h2>
            <table class="data-table" style="margin: 0;">
                <tbody>
                    <tr><th>Legal name</th><td><?= h($app['legal_name']) ?></td></tr>
                    <?php if ($app['trade_name']): ?><tr><th>Trade name</th><td><?= h($app['trade_name']) ?></td></tr><?php endif; ?>
                    <tr><th>Type</th><td><?= h(ve_business_types_labelled()[$app['business_type']] ?? $app['business_type']) ?></td></tr>
                    <?php if ($app['year_established']): ?><tr><th>Established</th><td><?= (int)$app['year_established'] ?></td></tr><?php endif; ?>
                    <?php if ($app['employee_count']): ?><tr><th>Employees</th><td><?= (int)$app['employee_count'] ?></td></tr><?php endif; ?>
                    <?php if ($app['annual_turnover_range']): ?><tr><th>Annual turnover</th><td><?= h($app['annual_turnover_range']) ?></td></tr><?php endif; ?>
                    <tr><th>Address</th>
                        <td>
                            <?= h($app['address_line1']) ?><?= $app['address_line2'] ? '<br>' . h($app['address_line2']) : '' ?>
                            <br><?= h(trim(($app['city'] ?? '') . ' ' . ($app['state'] ?? '') . ' ' . ($app['pincode'] ?? ''))) ?>
                            <br><?= h($app['country']) ?>
                        </td></tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="padding: 14px 18px;">
            <h2 style="margin-top: 0;">Statutory + bank</h2>
            <table class="data-table" style="margin: 0;">
                <tbody>
                    <tr><th>PAN</th><td><?= $app['pan_no'] ? '<code>' . h($app['pan_no']) . '</code>' : '<span class="muted">—</span>' ?></td></tr>
                    <tr><th>GST</th><td><?= $app['gst_no'] ? '<code>' . h($app['gst_no']) . '</code>' : '<span class="muted">—</span>' ?></td></tr>
                    <?php if ($app['msme_no']): ?><tr><th>MSME</th><td><?= h($app['msme_no']) ?></td></tr><?php endif; ?>
                    <?php if ($app['udyam_no']): ?><tr><th>Udyam</th><td><?= h($app['udyam_no']) ?></td></tr><?php endif; ?>
                    <?php if ($app['cin']): ?><tr><th>CIN</th><td><code><?= h($app['cin']) ?></code></td></tr><?php endif; ?>
                    <tr><th>Bank</th><td>
                        <?= h($app['bank_name'] ?? '') ?><?= $app['bank_branch'] ? ' (' . h($app['bank_branch']) . ')' : '' ?>
                        <?php if ($app['bank_account_no']): ?>
                            <br>A/c <code><?= h($app['bank_account_no']) ?></code>
                            <?php if ($app['bank_account_type']): ?> · <?= h(ve_account_types_labelled()[$app['bank_account_type']] ?? $app['bank_account_type']) ?><?php endif; ?>
                        <?php endif; ?>
                        <?= $app['bank_ifsc'] ? '<br>IFSC <code>' . h($app['bank_ifsc']) . '</code>' : '' ?>
                    </td></tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="padding: 14px 18px;">
            <h2 style="margin-top: 0;">Primary contact</h2>
            <?php if ($app['contact_name']): ?>
                <p>
                    <strong><?= h(trim(($app['contact_salutation'] ?? '') . ' ' . $app['contact_name'])) ?></strong>
                    <?php if ($app['contact_designation']): ?><br><span class="muted small"><?= h($app['contact_designation']) ?></span><?php endif; ?>
                    <?php if ($app['contact_email']): ?><br><?= h($app['contact_email']) ?><?php endif; ?>
                    <?php if ($app['contact_phone']): ?><br><?= h($app['contact_phone']) ?><?php endif; ?>
                </p>
            <?php else: ?>
                <p class="muted">No contact specified.</p>
            <?php endif; ?>
        </div>

        <div class="card" style="padding: 14px 18px;">
            <h2 style="margin-top: 0;">Capabilities</h2>
            <?php
            // Prefer junction over legacy comma-separated text
            $appCats = ve_application_categories((int)$id);
            ?>
            <?php if ($appCats): ?>
                <p><strong>Categories:</strong>
                    <?php foreach ($appCats as $c): ?>
                        <span class="pill pill-info" style="margin-right:4px;"><?= h($c['name']) ?></span>
                    <?php endforeach; ?>
                </p>
            <?php elseif ($app['categories']): ?>
                <p><strong>Categories:</strong>
                    <?php foreach (array_filter(array_map('trim', explode(',', $app['categories']))) as $c): ?>
                        <span class="pill pill-info" style="margin-right:4px;"><?= h($c) ?></span>
                    <?php endforeach; ?>
                    <span class="muted small">(legacy text — edit to migrate to junction)</span>
                </p>
            <?php endif; ?>
            <?php if ($app['capabilities']): ?>
                <p style="white-space: pre-wrap;"><?= h($app['capabilities']) ?></p>
            <?php endif; ?>
            <?php if ($app['iso_certified']): ?>
                <p><span class="pill pill-active">ISO certified</span>
                   <?= $app['iso_certificate_no'] ? '<code>' . h($app['iso_certificate_no']) . '</code>' : '' ?></p>
            <?php endif; ?>
            <?php if (!$appCats && !$app['categories'] && !$app['capabilities'] && !$app['iso_certified']): ?>
                <p class="muted">No capability info on file.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============= NDA template + Portal tokens (Phase E.2) ============= -->
    <div class="grid-2" style="margin-top: 12px;">
        <!-- NDA template card -->
        <?php
        $ndaTpl = $app['nda_template_id'] ? ve_nda_template_get((int)$app['nda_template_id']) : null;
        ?>
        <div class="card" style="padding: 14px 18px;">
            <h2 style="margin-top: 0;">NDA template</h2>
            <?php if ($ndaTpl): ?>
                <p><strong><?= h($ndaTpl['name']) ?></strong>
                   <span class="muted small">v<?= h($ndaTpl['version']) ?></span></p>
                <?php if ($ndaTpl['description']): ?>
                    <p class="muted small"><?= h($ndaTpl['description']) ?></p>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= h(url('/nda_templates.php?action=download&id=' . (int)$ndaTpl['id'])) ?>">
                    📥 Download template
                </a>
                <?php if (!$ndaTpl['is_active']): ?>
                    <p class="muted small" style="margin-top:6px;">⚠ This template is no longer active.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">No NDA template assigned.
                    <?php if ($canEdit && in_array($app['status'], ['draft','clarifications'], true)): ?>
                        Edit the application to pick one.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Portal access card -->
        <?php
        $tokens = db_all(
            "SELECT * FROM vendor_portal_tokens WHERE application_id = ?
              ORDER BY revoked_at IS NULL DESC, created_at DESC LIMIT 5",
            [(int)$id]
        );
        $activeTokens = array_filter($tokens, function ($t) {
            return $t['revoked_at'] === null && strtotime($t['expires_at']) > time();
        });
        $writableState = in_array($app['status'], ['draft','clarifications'], true);
        ?>
        <div class="card" style="padding: 14px 18px;">
            <h2 style="margin-top: 0;">Vendor portal</h2>
            <?php if (!$writableState): ?>
                <p class="muted small">
                    The portal is for vendors to self-fill draft / clarification-stage applications.
                    Once the application is past those stages, portal links are revoked automatically.
                </p>
            <?php endif; ?>

            <?php if ($activeTokens): ?>
                <p><strong><?= count($activeTokens) ?></strong> active portal link<?= count($activeTokens) > 1 ? 's' : '' ?>.</p>
                <table style="width:100%; font-size: 12.5px; border-collapse: collapse;">
                    <thead><tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left; padding: 4px 0;">Created</th>
                        <th style="text-align:left; padding: 4px 0;">Expires</th>
                        <th style="text-align:left; padding: 4px 0;">Last opened</th>
                        <th style="text-align:left; padding: 4px 0;">Uses</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($activeTokens as $t): ?>
                            <tr>
                                <td style="padding: 4px 0;"><?= h(substr((string)$t['created_at'], 0, 16)) ?></td>
                                <td style="padding: 4px 0;"><?= h(substr((string)$t['expires_at'], 0, 16)) ?></td>
                                <td style="padding: 4px 0;"><?= h($t['last_used_at'] ? substr((string)$t['last_used_at'], 0, 16) : '—') ?></td>
                                <td style="padding: 4px 0;"><?= (int)$t['use_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($writableState): ?>
                <p class="muted small">No active portal links. Create one below to let the vendor self-fill.</p>
            <?php endif; ?>

            <?php if ($writableState && $canInvite): ?>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px;">
                    <form method="post" action="<?= h(url('/vendor_empanelment.php?action=create_invite')) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">🔗 Create link</button>
                    </form>

                    <form method="post" action="<?= h(url('/vendor_empanelment.php?action=invite_email')) ?>" style="display:flex; gap:6px; align-items:center;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="email" name="to" placeholder="vendor@email.com" required
                               value="<?= h($app['contact_email']) ?>" style="min-width: 200px;">
                        <button type="submit" class="btn btn-primary btn-sm">📧 Send invite email</button>
                    </form>

                    <?php if ($activeTokens): ?>
                        <form method="post" action="<?= h(url('/vendor_empanelment.php?action=revoke_tokens')) ?>" style="display:inline;"
                              onsubmit="return confirm('Revoke ALL active portal links for this application?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">🚫 Revoke all</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($app['renewal_of_application_id']): ?>
                <?php $src = db_one("SELECT application_no FROM vendor_applications WHERE id = ?", [(int)$app['renewal_of_application_id']]); ?>
                <p class="muted small" style="margin-top: 10px;">
                    Renewal of <a href="<?= h(url('/vendor_empanelment.php?action=view&id=' . (int)$app['renewal_of_application_id'])) ?>"><?= h($src['application_no'] ?? '?') ?></a>.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============= Documents ============= -->
    <div class="card" style="padding: 14px 18px; margin-top: 12px;">
        <h2 style="margin-top: 0;">
            Supporting documents <span class="muted small">(<?= count($full['docs']) ?>)</span>
        </h2>

        <?php if ($canUpload && in_array($app['status'], ['draft','clarifications','submitted','under_review'], true)): ?>
            <form method="post" action="<?= h(url('/vendor_empanelment.php?action=upload_doc')) ?>"
                  enctype="multipart/form-data"
                  style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="field" style="margin: 0;">
                    <label for="f_doc_type" class="small">Type</label>
                    <select id="f_doc_type" name="doc_type" class="no-combobox">
                        <?php foreach (ve_doc_types_labelled() as $code => $label): ?>
                            <option value="<?= h($code) ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="margin: 0; flex: 1; min-width: 200px;">
                    <label for="f_doc_desc" class="small">Description (optional)</label>
                    <input type="text" id="f_doc_desc" name="description" maxlength="255">
                </div>
                <div class="field" style="margin: 0;">
                    <label for="f_doc_file" class="small">File <span class="muted">(15 MB max)</span></label>
                    <input type="file" id="f_doc_file" name="file" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">📎 Upload</button>
            </form>
        <?php endif; ?>

        <?php if (!$full['docs']): ?>
            <p class="muted">No documents uploaded yet.</p>
        <?php else: ?>
            <table class="data-table" style="margin: 0;">
                <thead><tr>
                    <th style="width: 180px;">Type</th>
                    <th>File</th>
                    <th style="width: 130px;">Uploaded</th>
                    <th style="width: 130px;">By</th>
                    <?php if ($canUpload && in_array($app['status'], ['draft','clarifications'], true)): ?><th style="width: 70px;"></th><?php endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($full['docs'] as $d):
                        $typeLabel = ve_doc_types_labelled()[$d['doc_type']] ?? $d['doc_type'];
                    ?>
                        <tr>
                            <td><?= h($typeLabel) ?></td>
                            <td>
                                <a href="<?= h(url('/vendor_empanelment.php?action=download_doc&doc=' . (int)$d['id'])) ?>">
                                    <?= h($d['file_name']) ?>
                                </a>
                                <?php if ($d['description']): ?>
                                    <div class="muted small"><?= h($d['description']) ?></div>
                                <?php endif; ?>
                                <?php if ($d['file_size']): ?>
                                    <div class="muted small"><?= h(number_format((float)$d['file_size'] / 1024, 1)) ?> KB</div>
                                <?php endif; ?>
                            </td>
                            <td><?= h(substr((string)$d['uploaded_at'], 0, 16)) ?></td>
                            <td><?= h($d['uploader_name'] ?: '—') ?></td>
                            <?php if ($canUpload && in_array($app['status'], ['draft','clarifications'], true)): ?>
                                <td>
                                    <form method="post" action="<?= h(url('/vendor_empanelment.php?action=delete_doc')) ?>" style="display:inline;"
                                          onsubmit="return confirm('Remove this document?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="doc" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn btn-icon" title="Delete">🗑</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ============= History ============= -->
    <?php if ($full['history']): ?>
    <div class="card" style="padding: 14px 18px; margin-top: 12px;">
        <h2 style="margin-top: 0;">History</h2>
        <table class="data-table" style="margin: 0;">
            <thead><tr>
                <th style="width: 140px;">When</th>
                <th style="width: 220px;">Transition</th>
                <th>Note</th>
                <th style="width: 140px;">By</th>
            </tr></thead>
            <tbody>
                <?php foreach ($full['history'] as $h):
                    $fromMeta = $h['from_status'] ? (ve_status_meta()[$h['from_status']] ?? null) : null;
                    $toMeta   = ve_status_meta()[$h['to_status']] ?? null;
                ?>
                    <tr>
                        <td><?= h(substr((string)$h['created_at'], 0, 16)) ?></td>
                        <td>
                            <?php if ($fromMeta): ?>
                                <span class="pill pill-<?= h($fromMeta['pill']) ?>"><?= h($fromMeta['label']) ?></span>
                                <span class="muted">→</span>
                            <?php endif; ?>
                            <?php if ($toMeta): ?>
                                <span class="pill pill-<?= h($toMeta['pill']) ?>"><?= h($toMeta['label']) ?></span>
                            <?php else: ?>
                                <?= h($h['to_status']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= h($h['note'] ?: '—') ?></td>
                        <td><?= h($h['actor_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($app['notes']): ?>
    <div class="card" style="padding: 14px 18px; margin-top: 12px;">
        <h2 style="margin-top: 0;">Internal notes</h2>
        <p style="white-space: pre-wrap;"><?= h($app['notes']) ?></p>
    </div>
    <?php endif; ?>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}


// Fallback
http_response_code(404);
echo 'Unknown action.';
exit;
