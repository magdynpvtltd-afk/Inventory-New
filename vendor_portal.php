<?php
/**
 * MagDyn — Vendor Self-Service Portal (Phase E.2)
 *
 * Public, no login required. Entry point is a magic-link URL with a
 * cryptographically-random token (?t=...). The token resolves to a
 * single vendor_applications row; the vendor can update their fields,
 * upload supporting documents, and submit for review.
 *
 * Security model:
 *   - Token must be present, non-revoked, non-expired
 *   - Every write action re-verifies the token from POST
 *   - Only fields in ve_portal_editable_fields() can be set
 *   - Document uploads use the same blocklist + size cap as the
 *     authenticated upload path
 *   - Once status is past 'clarifications', the portal goes read-only
 *
 * Actions:
 *   (default GET)        landing + form (or read-only status panel)
 *   save (POST)          save field updates
 *   upload (POST)        attach a document
 *   delete_doc (POST)    remove a document (only while draft/clarifications)
 *   download_doc (GET)   stream an attached doc
 *   submit (POST)        move draft/clarifications → submitted
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/_vendor_empanelment.php';

// -----------------------------------------------------------------
// Resolve token from URL or POST
// -----------------------------------------------------------------
$tokenStr = (string)input('t', '');
if ($tokenStr === '') {
    $tokenStr = (string)input('token', '');   // POSTs use 'token' in body
}
$lookup = ve_token_lookup($tokenStr);

// Bail out early with a friendly message if the token isn't usable.
if (!$lookup) {
    portal_render_error('This link is invalid or has expired. Please contact us for a new invitation.');
    exit;
}
$token = $lookup['token'];
$app   = $lookup['app'];

// Stamp activity (helps audit + the operator's view of when the
// vendor last opened the link).
ve_token_mark_used($token['id']);


// -----------------------------------------------------------------
// Action dispatch
// -----------------------------------------------------------------
$action = (string)input('action', '');
$writable = in_array($app['status'], ['draft', 'clarifications'], true);

if ($action === 'download_doc') {
    $docId = (int)input('doc', 0);
    $doc = db_one(
        "SELECT * FROM vendor_application_documents
          WHERE id = ? AND application_id = ?",
        [$docId, (int)$app['id']]
    );
    if (!$doc) { http_response_code(404); echo 'Not found.'; exit; }
    $abs = __DIR__ . '/' . ltrim($doc['file_path'], '/');
    if (!is_file($abs)) { http_response_code(404); echo 'File missing.'; exit; }
    header('Content-Type: ' . ($doc['file_mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . addslashes($doc['file_name']) . '"');
    readfile($abs);
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$writable) {
        portal_redirect_back($tokenStr, 'error', 'This application can no longer be edited.');
    }
    // Collect only whitelisted fields. Anything else in POST is ignored.
    $fields = ve_portal_editable_fields();
    $sets   = [];
    $vals   = [];
    foreach ($fields as $f) {
        if (!isset($_POST[$f])) continue;
        $v = is_array($_POST[$f]) ? '' : (string)$_POST[$f];
        // Light cleaning: PAN / GST / IFSC / CIN upper-case + trim
        if (in_array($f, ['pan_no','gst_no','bank_ifsc','cin'], true)) {
            $v = strtoupper(trim($v));
        } else {
            $v = trim($v);
        }
        if ($f === 'iso_certified') $v = $v ? 1 : 0;
        if ($v === '') $v = ($f === 'iso_certified') ? 0 : null;
        $sets[] = "$f = ?";
        $vals[] = $v;
    }
    if (!$sets) {
        portal_redirect_back($tokenStr, 'error', 'Nothing to save.');
    }
    if (trim((string)input('legal_name', '')) === '') {
        portal_redirect_back($tokenStr, 'error', 'Legal name is required.');
    }
    $vals[] = (int)$app['id'];
    db_exec("UPDATE vendor_applications SET " . implode(', ', $sets) . " WHERE id = ?", $vals);

    // Categories — multi-checkbox from form posts as category_ids[]
    if (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
        ve_set_application_categories((int)$app['id'], $_POST['category_ids']);
    }

    portal_redirect_back($tokenStr, 'success', 'Saved. You can continue editing or submit when ready.');
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$writable) {
        portal_redirect_back($tokenStr, 'error', 'This application can no longer accept new documents.');
    }
    $docType = (string)input('doc_type', 'other');
    $allowed = array_keys(ve_doc_types_labelled());
    if (!in_array($docType, $allowed, true)) $docType = 'other';
    $description = trim((string)input('description', '')) ?: null;

    if (empty($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        portal_redirect_back($tokenStr, 'error', 'Pick a file to upload.');
    }
    if ((int)$_FILES['file']['size'] > 15 * 1024 * 1024) {
        portal_redirect_back($tokenStr, 'error', 'File too large (15 MB max).');
    }
    $blocked = ['exe','bat','cmd','sh','js','vbs','msi','scr','php','phtml'];
    $orig = basename($_FILES['file']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (in_array($ext, $blocked, true)) {
        portal_redirect_back($tokenStr, 'error', "File type .$ext is not allowed.");
    }
    $dir = ve_doc_dir((int)$app['id']);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    $stored = $docType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
    $abs = $dir . '/' . $stored;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $abs)) {
        portal_redirect_back($tokenStr, 'error', 'Could not save the uploaded file.');
    }
    $rel = 'uploads/vendor_applications/' . (int)$app['id'] . '/' . $stored;
    db_exec(
        "INSERT INTO vendor_application_documents
            (application_id, doc_type, file_name, file_path, file_mime, file_size, description, uploaded_by)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [(int)$app['id'], $docType, $orig, $rel,
         @mime_content_type($abs), (int)filesize($abs), $description, (int)$token['created_by']]
    );
    ve_refresh_nda_flag((int)$app['id']);
    portal_redirect_back($tokenStr, 'success', 'Document uploaded.');
}

if ($action === 'delete_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$writable) portal_redirect_back($tokenStr, 'error', 'Documents can no longer be removed.');
    $docId = (int)input('doc', 0);
    $doc = db_one(
        "SELECT * FROM vendor_application_documents
          WHERE id = ? AND application_id = ?",
        [$docId, (int)$app['id']]
    );
    if ($doc) {
        $abs = __DIR__ . '/' . ltrim($doc['file_path'], '/');
        if (is_file($abs)) @unlink($abs);
        db_exec("DELETE FROM vendor_application_documents WHERE id = ?", [$docId]);
        ve_refresh_nda_flag((int)$app['id']);
    }
    portal_redirect_back($tokenStr, 'success', 'Document removed.');
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$writable) portal_redirect_back($tokenStr, 'error', 'Already submitted.');
    // Reload to evaluate required docs
    $full = ve_load((int)$app['id']);
    $missing = ve_required_docs($full['app'], $full['docs']);
    if ($missing) {
        $labels = ve_doc_types_labelled();
        $names = array_map(function ($t) use ($labels) { return $labels[$t] ?? $t; }, $missing);
        portal_redirect_back($tokenStr, 'error',
            'Please upload the following before submitting: ' . implode(', ', $names));
    }
    db_exec(
        "UPDATE vendor_applications
            SET status = 'submitted', submitted_at = NOW()
          WHERE id = ?",
        [(int)$app['id']]
    );
    db_exec(
        "INSERT INTO vendor_application_history
            (application_id, from_status, to_status, note, actor_id)
          VALUES (?, ?, 'submitted', 'Submitted via vendor portal', NULL)",
        [(int)$app['id'], $app['status']]
    );
    portal_redirect_back($tokenStr, 'success', 'Your application has been submitted. We will get back to you shortly.');
}


// -----------------------------------------------------------------
// Render
// -----------------------------------------------------------------
// Refresh from DB in case of just-saved state
$full = ve_load((int)$app['id']);
$app  = $full['app'];
$docs = $full['docs'];
$writable = in_array($app['status'], ['draft', 'clarifications'], true);

$categoriesAll = ve_categories_get_all(true);
$selectedCats = array_flip(ve_application_category_ids((int)$app['id']));
$missing = ve_required_docs($app, $docs);
$missingLabels = ve_doc_types_labelled();
$ndaTemplate = $app['nda_template_id'] ? ve_nda_template_get((int)$app['nda_template_id']) : null;

$flash = portal_flash_pop();

portal_render_page($app, $docs, $writable, $categoriesAll, $selectedCats,
                   $missing, $missingLabels, $ndaTemplate, $tokenStr, $flash);


// =================================================================
// Portal-only render helpers (no admin header / sidebar)
// =================================================================
function portal_redirect_back($token, $level, $msg)
{
    // Flash via a short-lived session key keyed on the token so
    // concurrent vendor sessions don't collide.
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['portal_flash_' . $token] = ['level' => $level, 'msg' => $msg];
    header('Location: ' . url('/vendor_portal.php?t=' . urlencode($token)));
    exit;
}

function portal_flash_pop()
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $token = (string)input('t', '');
    $key = 'portal_flash_' . $token;
    if (!empty($_SESSION[$key])) {
        $f = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $f;
    }
    return null;
}

function portal_render_error($msg)
{
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>Vendor Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 560px;
               margin: 60px auto; padding: 24px; color: #222; line-height: 1.5; }
        .box { background: #fff; border: 1px solid #ddd; border-radius: 8px;
               padding: 28px; text-align: center; }
        .icon { font-size: 36px; margin-bottom: 12px; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        p { color: #555; margin: 6px 0; }
    </style></head><body>
    <div class="box">
        <div class="icon">🔒</div>
        <h1>Link no longer valid</h1>
        <p><?= htmlspecialchars($msg) ?></p>
    </div>
    </body></html>
    <?php
}

function portal_render_page($app, $docs, $writable, $categoriesAll, $selectedCats,
                            $missing, $missingLabels, $ndaTemplate, $tokenStr, $flash)
{
    $statusMeta = ve_status_meta()[$app['status']] ?? ['label' => $app['status'], 'pill' => 'muted'];
    $hp = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!doctype html>
    <html lang="en"><head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vendor Empanelment — <?= $hp($app['legal_name']) ?></title>
    <style>
        :root { --primary:#2d3a8c; --border:#dcdfe6; --muted:#6b7280; --bg:#f6f7fa; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; color:#222;
               background:var(--bg); margin:0; line-height:1.5; }
        .wrap { max-width: 920px; margin: 0 auto; padding: 24px 16px 60px; }
        header.brand { background:#fff; padding:16px; border-bottom:1px solid var(--border); }
        header.brand .inner { max-width: 920px; margin: 0 auto; display:flex; align-items:center; gap:12px; }
        .brand h1 { font-size: 16px; margin:0; font-weight: 600; }
        .brand small { color: var(--muted); }
        .card { background:#fff; border:1px solid var(--border); border-radius:8px;
                padding:18px 20px; margin-bottom:16px; }
        h2 { font-size: 14px; text-transform: uppercase; letter-spacing: 0.04em;
             color: #444; border-bottom: 1px solid var(--border); padding-bottom: 5px;
             margin: 18px 0 10px; }
        h2:first-child { margin-top: 0; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px; }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .field label { display:block; font-size: 12px; color: var(--muted); margin-bottom: 3px; }
        .field input[type=text], .field input[type=email], .field input[type=number],
        .field select, .field textarea {
            width: 100%; padding: 7px 9px; border: 1px solid var(--border);
            border-radius: 4px; font: inherit; background: #fff;
        }
        .field input:focus, .field select:focus, .field textarea:focus {
            outline: 2px solid var(--primary); outline-offset: -1px; border-color: var(--primary);
        }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 10px;
                font-size: 11px; font-weight: 600; }
        .pill-active { background: #d1fae5; color: #065f46; }
        .pill-info   { background: #dbeafe; color: #1e40af; }
        .pill-muted  { background: #e5e7eb; color: #4b5563; }
        .pill-warn   { background: #fef3c7; color: #92400e; }
        .pill-danger { background: #fee2e2; color: #991b1b; }
        .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-warn    { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 4px;
               border: 1px solid var(--border); background: #fff; cursor: pointer;
               font: inherit; text-decoration: none; color: inherit; }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-sm { padding: 5px 10px; font-size: 13px; }
        .btn-icon { padding: 4px 8px; background: none; }
        .muted { color: var(--muted); }
        .small { font-size: 12px; }
        .docs table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .docs th, .docs td { padding: 6px 8px; border-bottom: 1px solid var(--border); text-align: left; }
        .cats { display: flex; flex-wrap: wrap; gap: 6px; }
        .cats label { display: inline-flex; align-items: center; gap: 4px;
                      padding: 4px 9px; border: 1px solid var(--border);
                      border-radius: 14px; background: #fff; cursor: pointer; font-size: 13px; }
        .cats input[type=checkbox]:checked + span { font-weight: 600; }
        .actions-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        @media (max-width: 640px) {
            .grid, .grid-3 { grid-template-columns: 1fr; }
        }
    </style>
    </head><body>

    <header class="brand">
        <div class="inner">
            <strong>Magneto Dynamics</strong>
            <small>· Vendor empanelment portal</small>
        </div>
    </header>

    <div class="wrap">
        <h1 style="font-size: 22px; margin: 0 0 4px;"><?= $hp($app['legal_name'] ?: 'New vendor application') ?>
            <span class="pill pill-<?= $hp($statusMeta['pill']) ?>" style="margin-left:8px; vertical-align:middle; font-size: 12px;"><?= $hp($statusMeta['label']) ?></span>
        </h1>
        <p class="muted small">Application <strong><?= $hp($app['application_no']) ?></strong></p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $hp($flash['level'] === 'error' ? 'error' : 'success') ?>">
                <?= $hp($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <?php if ($app['status'] === 'clarifications' && $app['decision_notes']): ?>
            <div class="alert alert-warn">
                <strong>Updates requested by our team:</strong><br>
                <?= nl2br($hp($app['decision_notes'])) ?>
            </div>
        <?php endif; ?>

        <?php if (!$writable): ?>
            <div class="card">
                <?php if ($app['status'] === 'submitted' || $app['status'] === 'under_review'): ?>
                    <h2>Application under review</h2>
                    <p>Thank you for submitting your empanelment application. Our team is reviewing it and will get back to you within the next few working days.</p>
                <?php elseif ($app['status'] === 'approved'): ?>
                    <h2>Approved 🎉</h2>
                    <p>Your empanelment has been approved. You're now an empaneled vendor with Magneto Dynamics. Our purchase team will reach out separately with onboarding details.</p>
                <?php elseif ($app['status'] === 'rejected'): ?>
                    <h2>Application closed</h2>
                    <p>Unfortunately we are unable to empanel your company at this time. If you'd like more information, please reach out to our purchase team.</p>
                    <?php if ($app['decision_notes']): ?>
                        <p class="muted small">Note from the reviewer: <?= nl2br($hp($app['decision_notes'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($missing): ?>
                <div class="alert alert-warn">
                    <strong>Required documents not yet uploaded:</strong>
                    <?= $hp(implode(', ', array_map(function($t) use ($missingLabels){ return $missingLabels[$t] ?? $t; }, $missing))) ?>
                </div>
            <?php endif; ?>

            <?php if ($ndaTemplate): ?>
                <div class="card" style="background:#f0f9ff; border-color:#7dd3fc;">
                    <h2 style="margin-top: 0; border:none; padding: 0;">📄 NDA Template</h2>
                    <p>Please download and sign the NDA, then upload the signed copy below (type: "Signed NDA").</p>
                    <a class="btn btn-primary btn-sm" href="<?= $hp(url('/nda_templates.php?action=download&id=' . (int)$ndaTemplate['id'])) ?>">
                        Download <?= $hp($ndaTemplate['name']) ?> (v<?= $hp($ndaTemplate['version']) ?>)
                    </a>
                </div>
            <?php endif; ?>

            <!-- ============= Editable form ============= -->
            <form method="post" action="<?= $hp(url('/vendor_portal.php?action=save')) ?>" class="card">
                <input type="hidden" name="token" value="<?= $hp($tokenStr) ?>">

                <h2>Company identity</h2>
                <div class="grid">
                    <div class="field">
                        <label for="f_legal_name">Legal name *</label>
                        <input type="text" id="f_legal_name" name="legal_name" required value="<?= $hp($app['legal_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="f_trade_name">Trade / brand name</label>
                        <input type="text" id="f_trade_name" name="trade_name" value="<?= $hp($app['trade_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="f_business_type">Business type</label>
                        <select id="f_business_type" name="business_type">
                            <?php foreach (ve_business_types_labelled() as $code => $label): ?>
                                <option value="<?= $hp($code) ?>" <?= $app['business_type'] === $code ? 'selected' : '' ?>><?= $hp($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="f_year_established">Year established</label>
                        <input type="number" id="f_year_established" name="year_established" min="1900" max="<?= (int)date('Y') ?>" value="<?= $hp($app['year_established']) ?>">
                    </div>
                    <div class="field">
                        <label for="f_employee_count">Employee count</label>
                        <input type="number" id="f_employee_count" name="employee_count" min="0" value="<?= $hp($app['employee_count']) ?>">
                    </div>
                    <div class="field">
                        <label for="f_annual_turnover_range">Annual turnover (range)</label>
                        <input type="text" id="f_annual_turnover_range" name="annual_turnover_range" placeholder="e.g. ₹1Cr - ₹5Cr" value="<?= $hp($app['annual_turnover_range']) ?>">
                    </div>
                </div>

                <h2>Registered address</h2>
                <div class="grid">
                    <div class="field" style="grid-column: span 2;">
                        <label for="f_address_line1">Line 1</label>
                        <input type="text" id="f_address_line1" name="address_line1" value="<?= $hp($app['address_line1']) ?>">
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label for="f_address_line2">Line 2</label>
                        <input type="text" id="f_address_line2" name="address_line2" value="<?= $hp($app['address_line2']) ?>">
                    </div>
                </div>
                <div class="grid grid-3">
                    <div class="field"><label for="f_city">City</label>
                        <input type="text" id="f_city" name="city" value="<?= $hp($app['city']) ?>"></div>
                    <div class="field"><label for="f_state">State</label>
                        <input type="text" id="f_state" name="state" value="<?= $hp($app['state']) ?>"></div>
                    <div class="field"><label for="f_pincode">PIN code</label>
                        <input type="text" id="f_pincode" name="pincode" value="<?= $hp($app['pincode']) ?>"></div>
                </div>

                <h2>Statutory (Indian)</h2>
                <div class="grid">
                    <div class="field"><label for="f_pan">PAN *</label>
                        <input type="text" id="f_pan" name="pan_no" style="text-transform:uppercase" pattern="[A-Z]{5}[0-9]{4}[A-Z]" value="<?= $hp($app['pan_no']) ?>"></div>
                    <div class="field"><label for="f_gst">GST / GSTIN</label>
                        <input type="text" id="f_gst" name="gst_no" style="text-transform:uppercase" value="<?= $hp($app['gst_no']) ?>"></div>
                    <div class="field"><label for="f_msme">MSME no.</label>
                        <input type="text" id="f_msme" name="msme_no" value="<?= $hp($app['msme_no']) ?>"></div>
                    <div class="field"><label for="f_udyam">Udyam no.</label>
                        <input type="text" id="f_udyam" name="udyam_no" placeholder="UDYAM-XX-00-0000000" value="<?= $hp($app['udyam_no']) ?>"></div>
                    <div class="field"><label for="f_cin">CIN (companies)</label>
                        <input type="text" id="f_cin" name="cin" style="text-transform:uppercase" value="<?= $hp($app['cin']) ?>"></div>
                </div>

                <h2>Bank details</h2>
                <div class="grid">
                    <div class="field"><label for="f_bank_name">Bank</label>
                        <input type="text" id="f_bank_name" name="bank_name" value="<?= $hp($app['bank_name']) ?>"></div>
                    <div class="field"><label for="f_bank_branch">Branch</label>
                        <input type="text" id="f_bank_branch" name="bank_branch" value="<?= $hp($app['bank_branch']) ?>"></div>
                    <div class="field"><label for="f_bank_account_no">Account no.</label>
                        <input type="text" id="f_bank_account_no" name="bank_account_no" value="<?= $hp($app['bank_account_no']) ?>"></div>
                    <div class="field"><label for="f_bank_account_type">Account type</label>
                        <select id="f_bank_account_type" name="bank_account_type">
                            <option value="">—</option>
                            <?php foreach (ve_account_types_labelled() as $code => $label): ?>
                                <option value="<?= $hp($code) ?>" <?= $app['bank_account_type'] === $code ? 'selected' : '' ?>><?= $hp($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label for="f_bank_ifsc">IFSC</label>
                        <input type="text" id="f_bank_ifsc" name="bank_ifsc" style="text-transform:uppercase" pattern="[A-Z]{4}0[A-Z0-9]{6}" value="<?= $hp($app['bank_ifsc']) ?>"></div>
                </div>

                <h2>Primary contact</h2>
                <div class="grid">
                    <div class="field"><label for="f_contact_name">Name</label>
                        <input type="text" id="f_contact_name" name="contact_name" value="<?= $hp($app['contact_name']) ?>"></div>
                    <div class="field"><label for="f_contact_designation">Designation</label>
                        <input type="text" id="f_contact_designation" name="contact_designation" value="<?= $hp($app['contact_designation']) ?>"></div>
                    <div class="field"><label for="f_contact_email">Email</label>
                        <input type="email" id="f_contact_email" name="contact_email" value="<?= $hp($app['contact_email']) ?>"></div>
                    <div class="field"><label for="f_contact_phone">Phone</label>
                        <input type="text" id="f_contact_phone" name="contact_phone" value="<?= $hp($app['contact_phone']) ?>"></div>
                </div>

                <h2>Categories of supply</h2>
                <p class="muted small">Tick all that apply.</p>
                <div class="cats">
                    <?php foreach ($categoriesAll as $c): ?>
                        <label>
                            <input type="checkbox" name="category_ids[]" value="<?= (int)$c['id'] ?>"
                                   <?= isset($selectedCats[(int)$c['id']]) ? 'checked' : '' ?>>
                            <span><?= $hp($c['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <h2 style="margin-top: 18px;">Capability summary</h2>
                <div class="field">
                    <textarea name="capabilities" rows="4" placeholder="A few sentences about your equipment, expertise, key customers"><?= $hp($app['capabilities']) ?></textarea>
                </div>
                <div class="grid">
                    <div class="field">
                        <label class="inline" style="gap:6px;">
                            <input type="checkbox" name="iso_certified" value="1" <?= $app['iso_certified'] ? 'checked' : '' ?>>
                            ISO certified
                        </label>
                    </div>
                    <div class="field"><label for="f_iso">ISO certificate no.</label>
                        <input type="text" id="f_iso" name="iso_certificate_no" value="<?= $hp($app['iso_certificate_no']) ?>"></div>
                </div>

                <div class="actions-bar" style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">💾 Save progress</button>
                    <span class="muted small">You can save and come back later using this same link.</span>
                </div>
            </form>

            <!-- ============= Documents ============= -->
            <div class="card docs">
                <h2 style="margin-top: 0;">Supporting documents</h2>
                <p class="muted small">Upload each required document. We accept PDF, images, Word, Excel; 15 MB max per file.</p>

                <form method="post" action="<?= $hp(url('/vendor_portal.php?action=upload')) ?>" enctype="multipart/form-data" style="margin-bottom: 12px;">
                    <input type="hidden" name="token" value="<?= $hp($tokenStr) ?>">
                    <div class="grid grid-3" style="align-items: end;">
                        <div class="field">
                            <label for="f_doc_type">Type</label>
                            <select id="f_doc_type" name="doc_type">
                                <?php foreach (ve_doc_types_labelled() as $code => $label): ?>
                                    <option value="<?= $hp($code) ?>"><?= $hp($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="f_doc_desc">Description (optional)</label>
                            <input type="text" id="f_doc_desc" name="description">
                        </div>
                        <div class="field">
                            <label for="f_doc_file">File</label>
                            <input type="file" id="f_doc_file" name="file" required>
                        </div>
                    </div>
                    <div style="margin-top: 8px;">
                        <button type="submit" class="btn btn-primary btn-sm">📎 Upload</button>
                    </div>
                </form>

                <?php if (!$docs): ?>
                    <p class="muted small">No documents uploaded yet.</p>
                <?php else: ?>
                    <table>
                        <thead><tr>
                            <th>Type</th>
                            <th>File</th>
                            <th>Uploaded</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($docs as $d): $typeLabel = ve_doc_types_labelled()[$d['doc_type']] ?? $d['doc_type']; ?>
                                <tr>
                                    <td><?= $hp($typeLabel) ?></td>
                                    <td>
                                        <a href="<?= $hp(url('/vendor_portal.php?action=download_doc&doc=' . (int)$d['id'] . '&t=' . urlencode($tokenStr))) ?>"><?= $hp($d['file_name']) ?></a>
                                        <?php if ($d['description']): ?><div class="muted small"><?= $hp($d['description']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="small muted"><?= $hp(substr((string)$d['uploaded_at'], 0, 16)) ?></td>
                                    <td>
                                        <form method="post" action="<?= $hp(url('/vendor_portal.php?action=delete_doc')) ?>" style="display:inline;"
                                              onsubmit="return confirm('Remove this document?');">
                                            <input type="hidden" name="token" value="<?= $hp($tokenStr) ?>">
                                            <input type="hidden" name="doc" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="btn btn-icon" title="Remove">🗑</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ============= Submit ============= -->
            <div class="card">
                <h2 style="margin-top: 0;">Ready to submit?</h2>
                <p class="muted small">Once submitted, you won't be able to make further changes until we review and either approve, or ask for clarifications via this same link.</p>
                <form method="post" action="<?= $hp(url('/vendor_portal.php?action=submit')) ?>"
                      onsubmit="return confirm('Submit the application for review?');">
                    <input type="hidden" name="token" value="<?= $hp($tokenStr) ?>">
                    <button type="submit" class="btn btn-primary" <?= $missing ? 'disabled title="Upload all required documents first"' : '' ?>>
                        📤 Submit for review
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <p class="muted small" style="text-align:center; margin-top: 20px;">
            Need help? Contact our purchase team. This link is unique to your application — please don't share.
        </p>
    </div>

    </body></html>
    <?php
}
