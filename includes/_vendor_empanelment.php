<?php
/**
 * MagDyn — Vendor Empanelment helpers (Phase E)
 *
 * Provides:
 *   ve_load($id)                       — full application + docs + history
 *   ve_transition($id, $to, $note)     — status change with audit row
 *   ve_doc_dir($id)                    — disk path for an application's
 *                                        uploaded documents
 *   ve_required_docs($app)             — which doc types are mandatory
 *                                        before submit (depends on
 *                                        business type + GST/MSME flags)
 *   ve_refresh_nda_flag($id)           — denormalise nda_on_file from
 *                                        the documents table
 *   ve_create_vendor_from_app($appId, $uid) — on approval, create or
 *                                        link a vendors row using the
 *                                        application data; returns
 *                                        the vendor_id.
 *
 * Status machine:
 *
 *   draft  ─┬─► submitted ──► under_review ─┬─► approved   (terminal*)
 *           │                                ├─► rejected   (terminal*)
 *           │                                └─► clarifications ──┐
 *           │                                                     │
 *           └─────────────  reset_to_draft  ◄────────────────────┘
 *           ◄── (clarifications can re-submit) ──────────────────┘
 *
 *   *terminal-but-reopenable by users with the 'review' permission.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_codes.php';

/**
 * Load the application + related docs + history. Returns null if the
 * id doesn't resolve.
 */
function ve_load($id)
{
    $app = db_one("SELECT * FROM vendor_applications WHERE id = ?", [(int)$id]);
    if (!$app) return null;
    $docs = db_all(
        "SELECT d.*, u.full_name AS uploader_name
           FROM vendor_application_documents d
      LEFT JOIN users u ON u.id = d.uploaded_by
          WHERE d.application_id = ?
          ORDER BY d.uploaded_at DESC",
        [(int)$id]
    );
    $history = db_all(
        "SELECT h.*, u.full_name AS actor_name
           FROM vendor_application_history h
      LEFT JOIN users u ON u.id = h.actor_id
          WHERE h.application_id = ?
          ORDER BY h.created_at",
        [(int)$id]
    );
    $existingVendor = $app['existing_vendor_id']
        ? db_one("SELECT id, code, name FROM vendors WHERE id = ?", [(int)$app['existing_vendor_id']])
        : null;
    $approvedVendor = $app['approved_vendor_id']
        ? db_one("SELECT id, code, name FROM vendors WHERE id = ?", [(int)$app['approved_vendor_id']])
        : null;
    return [
        'app'             => $app,
        'docs'            => $docs,
        'history'         => $history,
        'existing_vendor' => $existingVendor,
        'approved_vendor' => $approvedVendor,
    ];
}

/**
 * Valid status transitions. Keys: from. Values: array of allowed to's.
 */
function ve_allowed_transitions()
{
    return [
        'draft'          => ['submitted'],
        'submitted'      => ['under_review', 'draft'],          // pick up review OR send back to draft
        'under_review'   => ['approved', 'rejected', 'clarifications', 'submitted'],
        'clarifications' => ['submitted', 'draft'],              // vendor responds → back to queue
        'approved'       => ['under_review'],                    // reopen
        'rejected'       => ['under_review'],                    // reopen
    ];
}

/**
 * Run a status transition with audit. Returns true on success, false
 * (with flash error set) if the transition isn't allowed. Caller has
 * already done permission checks.
 */
function ve_transition($id, $to, $note = null, $actorId = null)
{
    $cur = db_one("SELECT id, status FROM vendor_applications WHERE id = ?", [(int)$id]);
    if (!$cur) {
        flash_set('error', 'Application not found.');
        return false;
    }
    $from = (string)$cur['status'];
    $allowed = ve_allowed_transitions();
    if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
        flash_set('error', "Can't move from '$from' to '$to'.");
        return false;
    }

    db()->beginTransaction();
    try {
        $cols = ['status = ?'];
        $vals = [$to];

        // Timestamps + actor tagging per transition
        if ($to === 'submitted') {
            $cols[] = 'submitted_at = NOW()';
            $cols[] = 'submitted_by = ?';
            $vals[] = (int)$actorId;
        }
        if ($to === 'approved' || $to === 'rejected') {
            $cols[] = 'reviewed_at = NOW()';
            $cols[] = 'reviewed_by = ?';
            $vals[] = (int)$actorId;
            if ($note !== null && $note !== '') {
                $cols[] = 'decision_notes = ?';
                $vals[] = $note;
            }
        }
        $vals[] = (int)$id;
        db_exec("UPDATE vendor_applications SET " . implode(', ', $cols) . " WHERE id = ?", $vals);

        db_exec(
            "INSERT INTO vendor_application_history (application_id, from_status, to_status, note, actor_id)
             VALUES (?, ?, ?, ?, ?)",
            [(int)$id, $from, $to, $note ?: null, $actorId ? (int)$actorId : null]
        );

        db()->commit();
        return true;
    } catch (\Throwable $e) {
        db()->rollBack();
        flash_set('error', 'Transition failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Disk path for an application's uploaded documents. Files live under
 * uploads/vendor_applications/<id>/. Created on demand.
 */
function ve_doc_dir($id)
{
    $dir = __DIR__ . '/../uploads/vendor_applications/' . (int)$id;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/**
 * Doc-type whitelist (mirrors the ENUM in the schema). Used by the
 * upload validator and by the form's <select>.
 */
function ve_doc_types_labelled()
{
    return [
        'pan'              => 'PAN card',
        'gst'              => 'GST registration certificate',
        'msme'             => 'MSME certificate',
        'udyam'            => 'Udyam registration',
        'cin'              => 'CIN / company incorporation',
        'bank_proof'       => 'Bank account proof (letter)',
        'cancelled_cheque' => 'Cancelled cheque',
        'iso_cert'         => 'ISO certificate',
        'quality_manual'   => 'Quality manual',
        'company_profile'  => 'Company profile / capability deck',
        'director_id'      => 'Director / proprietor ID',
        'nda_signed'       => 'Signed NDA',
        'other'            => 'Other',
    ];
}

/**
 * Required documents before an application can be submitted for review.
 * Returns the list of doc_type codes that aren't yet on file.
 *
 * Rules (MVP):
 *   - PAN: always required
 *   - GST: required if gst_no is filled
 *   - Bank proof OR cancelled cheque: at least one required
 *   - NDA signed: required for all (the whole point of empanelment)
 *
 * Reviewers can override and approve regardless via the 'review' perm,
 * but submit-side validation flags missing ones to the operator.
 */
function ve_required_docs(array $app, array $docs)
{
    $haveTypes = [];
    foreach ($docs as $d) $haveTypes[$d['doc_type']] = true;

    $missing = [];
    if (empty($haveTypes['pan'])) $missing[] = 'pan';
    if (!empty($app['gst_no']) && empty($haveTypes['gst'])) $missing[] = 'gst';
    if (empty($haveTypes['bank_proof']) && empty($haveTypes['cancelled_cheque'])) {
        $missing[] = 'bank_proof';   // either is OK; we surface the canonical one
    }
    if (empty($haveTypes['nda_signed'])) $missing[] = 'nda_signed';
    return $missing;
}

/**
 * Recompute the nda_on_file + nda_signed_date denormalised columns
 * from the latest 'nda_signed' document. Call this after upload / delete.
 */
function ve_refresh_nda_flag($id)
{
    $row = db_one(
        "SELECT uploaded_at FROM vendor_application_documents
          WHERE application_id = ? AND doc_type = 'nda_signed'
          ORDER BY uploaded_at DESC LIMIT 1",
        [(int)$id]
    );
    if ($row) {
        db_exec(
            "UPDATE vendor_applications SET nda_on_file = 1,
                 nda_signed_date = COALESCE(nda_signed_date, DATE(?))
              WHERE id = ?",
            [$row['uploaded_at'], (int)$id]
        );
    } else {
        db_exec(
            "UPDATE vendor_applications SET nda_on_file = 0 WHERE id = ?",
            [(int)$id]
        );
    }
}

/**
 * Materialise a vendor row from an approved application. If
 * existing_vendor_id is set, update that vendor in place; otherwise
 * INSERT a new vendor. Returns the vendor_id.
 *
 * Called by the approve action in vendor_empanelment.php. Wraps its
 * own transaction; caller must NOT already be in one.
 */
function ve_create_vendor_from_app($appId, $actorId)
{
    $app = db_one("SELECT * FROM vendor_applications WHERE id = ?", [(int)$appId]);
    if (!$app) throw new \RuntimeException('Application not found.');

    // Build address one-liner for the legacy vendors.address column.
    $addrParts = array_filter([
        $app['address_line1'], $app['address_line2'],
        $app['city'], $app['state'], $app['pincode'], $app['country'],
    ]);
    $address = implode(', ', array_map('trim', $addrParts));

    $name = $app['trade_name'] ?: $app['legal_name'];

    db()->beginTransaction();
    try {
        if ($app['existing_vendor_id']) {
            $vendorId = (int)$app['existing_vendor_id'];
            // Patch the existing vendor with empanelment data — only
            // fill blanks, don't overwrite operator-curated values.
            db_exec(
                "UPDATE vendors SET
                   name    = COALESCE(NULLIF(name, ''), ?),
                   contact = COALESCE(NULLIF(contact, ''), ?),
                   email   = COALESCE(NULLIF(email, ''), ?),
                   phone   = COALESCE(NULLIF(phone, ''), ?),
                   gst_no  = COALESCE(NULLIF(gst_no, ''), ?),
                   pan_no  = COALESCE(NULLIF(pan_no, ''), ?),
                   address = COALESCE(NULLIF(address, ''), ?),
                   empaneled = 1,
                   empaneled_at = NOW(),
                   empanelment_application_id = ?
                 WHERE id = ?",
                [$name, $app['contact_name'], $app['contact_email'], $app['contact_phone'],
                 $app['gst_no'], $app['pan_no'], $address, (int)$appId, $vendorId]
            );
        } else {
            // Create a fresh vendor. Code via the existing 'vendor'
            // sequence if it's there; fall back to V-<applicationno>.
            try { $code = code_next('vendor'); }
            catch (\Throwable $e) { $code = 'V-' . preg_replace('/[^A-Za-z0-9]/', '', $app['application_no']); }

            db_exec(
                "INSERT INTO vendors
                    (code, name, contact, email, phone, gst_no, pan_no, address,
                     is_active, empaneled, empaneled_at, empanelment_application_id)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), ?)",
                [$code, $name, $app['contact_name'], $app['contact_email'], $app['contact_phone'],
                 $app['gst_no'], $app['pan_no'], $address, (int)$appId]
            );
            $vendorId = (int)db()->lastInsertId();
        }

        // Stamp approved_vendor_id back on the application.
        db_exec(
            "UPDATE vendor_applications SET approved_vendor_id = ? WHERE id = ?",
            [$vendorId, (int)$appId]
        );

        db()->commit();
        return $vendorId;
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Status labels and pill classes (for UI rendering).
 */
function ve_status_meta()
{
    return [
        'draft'          => ['label' => 'Draft',          'pill' => 'muted'],
        'submitted'      => ['label' => 'Submitted',      'pill' => 'info'],
        'under_review'   => ['label' => 'Under review',   'pill' => 'info'],
        'clarifications' => ['label' => 'Clarifications', 'pill' => 'warn'],
        'approved'       => ['label' => 'Approved',       'pill' => 'active'],
        'rejected'       => ['label' => 'Rejected',       'pill' => 'danger'],
    ];
}

/**
 * Business-type labels.
 */
function ve_business_types_labelled()
{
    return [
        'proprietorship' => 'Proprietorship',
        'partnership'    => 'Partnership firm',
        'llp'            => 'LLP',
        'pvt_ltd'        => 'Private Limited',
        'public_ltd'     => 'Public Limited',
        'huf'            => 'HUF',
        'government'     => 'Government / PSU',
        'trust'          => 'Trust / Society',
        'other'          => 'Other',
    ];
}

/**
 * Account types.
 */
function ve_account_types_labelled()
{
    return [
        'current'      => 'Current',
        'savings'      => 'Savings',
        'cash_credit'  => 'Cash credit',
        'overdraft'    => 'Overdraft',
        'other'        => 'Other',
    ];
}


/* =================================================================
 * Phase 2 helpers — categories, NDA templates, portal tokens, expiry
 * ================================================================= */

/**
 * Master list of supply categories (active only by default).
 */
function ve_categories_get_all($activeOnly = true)
{
    $sql = "SELECT id, code, name, description FROM vendor_categories";
    if ($activeOnly) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order, name";
    return db_all($sql, []);
}

/**
 * Return category ids currently linked to an application.
 */
function ve_application_category_ids($appId)
{
    $rows = db_all(
        "SELECT category_id FROM vendor_application_categories WHERE application_id = ?",
        [(int)$appId]
    );
    return array_map(function ($r) { return (int)$r['category_id']; }, $rows);
}

/**
 * Return full category rows for an application, in master sort order.
 */
function ve_application_categories($appId)
{
    return db_all(
        "SELECT c.id, c.code, c.name
           FROM vendor_application_categories vac
           JOIN vendor_categories c ON c.id = vac.category_id
          WHERE vac.application_id = ?
          ORDER BY c.sort_order, c.name",
        [(int)$appId]
    );
}

/**
 * Replace the set of categories linked to an application. $catIds is
 * an array of category ids (ints) coming from the form's checkboxes.
 * Invalid ids are silently dropped.
 */
function ve_set_application_categories($appId, array $catIds)
{
    $appId = (int)$appId;
    $catIds = array_values(array_unique(array_map('intval', $catIds)));
    db()->beginTransaction();
    try {
        db_exec("DELETE FROM vendor_application_categories WHERE application_id = ?", [$appId]);
        if ($catIds) {
            // Filter to ids that actually exist (cheaper than per-row FK errors)
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $valid = db_all(
                "SELECT id FROM vendor_categories WHERE id IN ($placeholders)",
                $catIds
            );
            foreach ($valid as $row) {
                db_exec(
                    "INSERT IGNORE INTO vendor_application_categories (application_id, category_id) VALUES (?, ?)",
                    [$appId, (int)$row['id']]
                );
            }
        }
        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Active NDA templates (for selection on application form / portal).
 */
function ve_nda_templates_active()
{
    return db_all(
        "SELECT id, name, version, file_name FROM nda_templates
          WHERE is_active = 1
          ORDER BY name, version DESC",
        []
    );
}

/**
 * Single NDA template (for view / download).
 */
function ve_nda_template_get($id)
{
    return db_one("SELECT * FROM nda_templates WHERE id = ?", [(int)$id]);
}

/**
 * Generate a magic-link token for an application. TTL comes from the
 * empanelment.portal_token_ttl_days setting (default 14). Returns
 * the token string. Caller composes the URL via ve_portal_url().
 *
 * Old non-revoked tokens for the same application are NOT auto-revoked;
 * an operator may legitimately issue a fresh link before the old one
 * expires. Use ve_token_revoke_all_for_app() to invalidate prior ones
 * explicitly.
 */
function ve_token_create($appId, $actorId, $purpose = 'fill')
{
    $ttlDays = (int)magdyn_setting('empanelment.portal_token_ttl_days', 14);
    if ($ttlDays < 1) $ttlDays = 14;
    $token = bin2hex(random_bytes(32));
    db_exec(
        "INSERT INTO vendor_portal_tokens
            (application_id, token, purpose, expires_at, created_by)
          VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)",
        [(int)$appId, $token, $purpose, $ttlDays, (int)$actorId]
    );
    return $token;
}

/**
 * Resolve a token string. Returns
 *   ['token' => row, 'app' => row]
 * if the token is valid, current, and not revoked; otherwise null.
 */
function ve_token_lookup($tokenStr)
{
    if (!$tokenStr || strlen($tokenStr) !== 64) return null;
    $tok = db_one(
        "SELECT * FROM vendor_portal_tokens
          WHERE token = ? AND revoked_at IS NULL
            AND expires_at > NOW()
          LIMIT 1",
        [(string)$tokenStr]
    );
    if (!$tok) return null;
    $app = db_one("SELECT * FROM vendor_applications WHERE id = ?", [(int)$tok['application_id']]);
    if (!$app) return null;
    return ['token' => $tok, 'app' => $app];
}

/**
 * Stamp a token's use_count / last_used_at — call this on every
 * portal action so we can see activity in audit.
 */
function ve_token_mark_used($tokenId)
{
    db_exec(
        "UPDATE vendor_portal_tokens
            SET use_count = use_count + 1, last_used_at = NOW()
          WHERE id = ?",
        [(int)$tokenId]
    );
}

/**
 * Revoke all outstanding tokens for an application. Called by
 * the renewal action and by the explicit revoke button.
 */
function ve_token_revoke_all_for_app($appId)
{
    db_exec(
        "UPDATE vendor_portal_tokens
            SET revoked_at = NOW()
          WHERE application_id = ? AND revoked_at IS NULL",
        [(int)$appId]
    );
}

/**
 * Build the full portal URL for a token. Prefers
 * magdyn_settings.empanelment.portal_base_url; falls back to current
 * HTTP_HOST. Used when composing invite emails.
 */
function ve_portal_url($token)
{
    $base = trim((string)magdyn_setting('empanelment.portal_base_url', ''));
    if ($base === '') {
        $base = (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST']) : '');
    }
    $base = rtrim($base, '/');
    return $base . '/vendor_portal.php?t=' . urlencode($token);
}

/**
 * Compute expiry status for an application. Returns one of:
 *   'none'        — no expires_at set (i.e. not yet approved)
 *   'ok'          — far from expiry
 *   'expiring'    — within reminder_days_before from expiry
 *   'expired'     — past expiry
 */
function ve_expiry_state(array $app)
{
    if (empty($app['expires_at'])) return 'none';
    $expiresTs = strtotime($app['expires_at']);
    if ($expiresTs <= time()) return 'expired';
    $reminderDays = (int)magdyn_setting('empanelment.reminder_days_before', 30);
    if ($expiresTs <= time() + ($reminderDays * 86400)) return 'expiring';
    return 'ok';
}

/**
 * On approval, set expires_at on the application AND
 * empanelment_expires_at on the linked vendor. validity_years comes
 * from settings (default 3 years). Called by the approve action AFTER
 * ve_create_vendor_from_app().
 */
function ve_set_expiry_on_approve($appId, $vendorId)
{
    $years = (int)magdyn_setting('empanelment.validity_years', 3);
    if ($years < 1) $years = 3;
    db_exec(
        "UPDATE vendor_applications
            SET expires_at = DATE_ADD(NOW(), INTERVAL ? YEAR)
          WHERE id = ?",
        [$years, (int)$appId]
    );
    if ($vendorId) {
        db_exec(
            "UPDATE vendors
                SET empanelment_expires_at = DATE_ADD(NOW(), INTERVAL ? YEAR)
              WHERE id = ?",
            [$years, (int)$vendorId]
        );
    }
}

/**
 * Whitelist of fields a public-portal vendor is allowed to set.
 * Internal-only fields (status, notes, existing_vendor_id, NDA
 * template selection, etc.) are NOT in this list — the portal POST
 * handler ignores anything outside the whitelist.
 */
function ve_portal_editable_fields()
{
    return [
        'legal_name', 'trade_name', 'business_type', 'year_established',
        'employee_count', 'annual_turnover_range',
        'address_line1', 'address_line2', 'city', 'state', 'pincode', 'country',
        'pan_no', 'gst_no', 'msme_no', 'udyam_no', 'cin',
        'bank_name', 'bank_branch', 'bank_account_no',
        'bank_account_type', 'bank_ifsc',
        'contact_salutation', 'contact_name', 'contact_designation',
        'contact_email', 'contact_phone',
        'capabilities', 'iso_certified', 'iso_certificate_no',
    ];
}
