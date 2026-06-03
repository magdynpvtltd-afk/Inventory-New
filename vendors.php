<?php
/**
 * MagDyn — Vendors
 * Created: 20260515_071000_IST
 * Rewritten: 20260515_190000_IST — multi contacts/addresses + bank details
 *
 * Top-level sidebar entry (no longer under Admin). Asset send/receive
 * and Ship & Receipt transactions point at rows in this table.
 *
 * Sub-actions on the edit page:
 *   contact_save, contact_delete   — manage vendor_contacts rows
 *   address_save, address_delete   — manage vendor_addresses rows
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('vendors', 'view');
require_once __DIR__ . '/includes/datatable.php';

$action    = (string)input('action', 'index');
$canManage = permission_check('vendors', 'manage');
$canDelete = permission_check('vendors', 'delete');

/**
 * Auto-generate the next VND-NNNNN code. Falls back to a timestamp
 * suffix on the unlikely concurrent-collision case.
 */
function vendor_code_generate()
{
    // After migration 220000, all vendor codes use the V- prefix.
    // The query LIMITs to one row by id desc, which is a fast heuristic
    // — works because codes are assigned monotonically per insert.
    $last = db_val('SELECT code FROM vendors ORDER BY id DESC LIMIT 1', [], '');
    $next = 1;
    if ($last && preg_match('/^V-(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    $candidate = 'V-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
    if (db_val('SELECT id FROM vendors WHERE code = ?', [$candidate], 0)) {
        $candidate = 'V-' . date('YmdHis');
    }
    return $candidate;
}

// ============================================================
// SAVE / TOGGLE / DELETE — vendor header
// ============================================================
if ($action === 'save') {
    require_permission('vendors', 'manage');
    csrf_check();
    $id   = (int)input('id', 0);
    $data = [
        'name'                => trim((string)input('name')),
        'gst_no'              => trim((string)input('gst_no')) ?: null,
        'pan_no'              => trim((string)input('pan_no')) ?: null,
        'bank_account_name'   => trim((string)input('bank_account_name')) ?: null,
        'bank_account_number' => trim((string)input('bank_account_number')) ?: null,
        'bank_ifsc'           => trim((string)input('bank_ifsc')) ?: null,
        'bank_swift'          => trim((string)input('bank_swift')) ?: null,
        'bank_name'           => trim((string)input('bank_name')) ?: null,
        'bank_branch'         => trim((string)input('bank_branch')) ?: null,
        'payment_terms'       => trim((string)input('payment_terms')) ?: null,
        'notes'               => trim((string)input('notes')) ?: null,
        'is_active'           => input('is_active') ? 1 : 0,
    ];
    if ($data['name'] === '') {
        flash_set('error', 'Name is required.');
        redirect($id ? url('/vendors.php?action=edit&id=' . $id) : url('/vendors.php?action=new'));
    }

    if ($id) {
        db_exec(
            'UPDATE vendors
                SET name=?, gst_no=?, pan_no=?,
                    bank_account_name=?, bank_account_number=?, bank_ifsc=?, bank_swift=?,
                    bank_name=?, bank_branch=?, payment_terms=?, notes=?, is_active=?
              WHERE id=?',
            [$data['name'], $data['gst_no'], $data['pan_no'],
             $data['bank_account_name'], $data['bank_account_number'], $data['bank_ifsc'], $data['bank_swift'],
             $data['bank_name'], $data['bank_branch'], $data['payment_terms'], $data['notes'], $data['is_active'], $id]
        );
        flash_set('success', 'Vendor saved.');
        redirect(url('/vendors.php?action=edit&id=' . $id));
    } else {
        // Auto-generate code on create; immutable thereafter.
        $code = vendor_code_generate();
        db_exec(
            'INSERT INTO vendors
                (code, name, gst_no, pan_no,
                 bank_account_name, bank_account_number, bank_ifsc, bank_swift,
                 bank_name, bank_branch, payment_terms, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$code, $data['name'], $data['gst_no'], $data['pan_no'],
             $data['bank_account_name'], $data['bank_account_number'], $data['bank_ifsc'], $data['bank_swift'],
             $data['bank_name'], $data['bank_branch'], $data['payment_terms'], $data['notes'], $data['is_active']]
        );
        $newId = (int)db()->lastInsertId();
        flash_set('success', sprintf('Vendor %s created. Now add contacts and addresses.', $code));
        redirect(url('/vendors.php?action=edit&id=' . $newId));
    }
}

if ($action === 'toggle') {
    require_permission('vendors', 'manage');
    csrf_check();
    $id = (int)input('id', 0);
    db_exec('UPDATE vendors SET is_active = 1 - is_active WHERE id = ?', [$id]);
    flash_set('success', 'Vendor toggled.');
    redirect(url('/vendors.php'));
}

if ($action === 'delete') {
    require_permission('vendors', 'delete');
    csrf_check();
    $id = (int)input('id', 0);
    $v = db_one('SELECT * FROM vendors WHERE id = ?', [$id]);
    if (!$v) { flash_set('error', 'Vendor not found.'); redirect(url('/vendors.php')); }

    $linked = 0;
    try {
        $linked = db_val('SELECT COUNT(*) FROM assets WHERE current_vendor_id = ?', [$id], 0);
        $linked += db_val('SELECT COUNT(*) FROM asset_transactions WHERE from_vendor_id = ? OR to_vendor_id = ?', [$id, $id], 0);
        $linked += db_val('SELECT COUNT(*) FROM inv_shipments WHERE vendor_id = ?', [$id], 0);
    } catch (Exception $e) { /* tables may not exist */ }
    if ($linked > 0) {
        flash_set('error', sprintf('Cannot delete "%s" — %d record(s) reference this vendor.', $v['name'], $linked));
        redirect(url('/vendors.php'));
    }

    db_exec('DELETE FROM vendors WHERE id = ?', [$id]);
    db_exec("INSERT INTO audit_log (actor_id, action, details) VALUES (?, 'vendor.delete', ?)",
            [real_user_id(), 'deleted vendor ' . $v['code']]);
    flash_set('success', 'Vendor deleted.');
    redirect(url('/vendors.php'));
}

// ============================================================
// CONTACT sub-grid actions
// ============================================================
if ($action === 'contact_save') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $cid = (int)input('contact_id', 0);
    $data = [
        'salutation'  => trim((string)input('c_salutation')) ?: null,
        'name'        => trim((string)input('c_name')),
        'designation' => trim((string)input('c_designation')) ?: null,
        'email'       => trim((string)input('c_email')) ?: null,
        'phone'       => trim((string)input('c_phone')) ?: null,
        'is_primary'  => input('c_is_primary') ? 1 : 0,
    ];
    if ($data['name'] === '') {
        flash_set('error', 'Contact name is required.');
        redirect(url('/vendors.php?action=edit&id=' . $vid));
    }
    // Enforce "only one primary contact per vendor"
    if ($data['is_primary']) {
        db_exec('UPDATE vendor_contacts SET is_primary = 0 WHERE vendor_id = ?', [$vid]);
    }
    if ($cid) {
        db_exec(
            'UPDATE vendor_contacts SET salutation=?, name=?, designation=?, email=?, phone=?, is_primary=? WHERE id=? AND vendor_id=?',
            [$data['salutation'], $data['name'], $data['designation'], $data['email'], $data['phone'], $data['is_primary'], $cid, $vid]
        );
        flash_set('success', 'Contact updated.');
    } else {
        $nextSort = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM vendor_contacts WHERE vendor_id = ?', [$vid], 10);
        db_exec(
            'INSERT INTO vendor_contacts (vendor_id, salutation, name, designation, email, phone, is_primary, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$vid, $data['salutation'], $data['name'], $data['designation'], $data['email'], $data['phone'], $data['is_primary'], $nextSort]
        );
        flash_set('success', 'Contact added.');
    }
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

if ($action === 'contact_delete') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $cid = (int)input('contact_id', 0);
    db_exec('DELETE FROM vendor_contacts WHERE id = ? AND vendor_id = ?', [$cid, $vid]);
    flash_set('success', 'Contact removed.');
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

// ============================================================
// ADDRESS sub-grid actions
// ============================================================
if ($action === 'address_save') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $aid = (int)input('address_id', 0);
    $data = [
        'label'      => trim((string)input('a_label')) ?: null,
        'line1'      => trim((string)input('a_line1')),
        'line2'      => trim((string)input('a_line2')) ?: null,
        'city'       => trim((string)input('a_city')) ?: null,
        'state'      => trim((string)input('a_state')) ?: null,
        'pincode'    => trim((string)input('a_pincode')) ?: null,
        'country'    => trim((string)input('a_country')) ?: 'India',
        'is_primary' => input('a_is_primary') ? 1 : 0,
    ];
    if ($data['line1'] === '') {
        flash_set('error', 'Address line 1 is required.');
        redirect(url('/vendors.php?action=edit&id=' . $vid));
    }
    if ($data['is_primary']) {
        db_exec('UPDATE vendor_addresses SET is_primary = 0 WHERE vendor_id = ?', [$vid]);
    }
    if ($aid) {
        db_exec(
            'UPDATE vendor_addresses
                SET label=?, line1=?, line2=?, city=?, state=?, pincode=?, country=?, is_primary=?
              WHERE id=? AND vendor_id=?',
            [$data['label'], $data['line1'], $data['line2'], $data['city'], $data['state'],
             $data['pincode'], $data['country'], $data['is_primary'], $aid, $vid]
        );
        flash_set('success', 'Address updated.');
    } else {
        $nextSort = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM vendor_addresses WHERE vendor_id = ?', [$vid], 10);
        db_exec(
            'INSERT INTO vendor_addresses
                (vendor_id, label, line1, line2, city, state, pincode, country, is_primary, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$vid, $data['label'], $data['line1'], $data['line2'], $data['city'], $data['state'],
             $data['pincode'], $data['country'], $data['is_primary'], $nextSort]
        );
        flash_set('success', 'Address added.');
    }
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

if ($action === 'address_delete') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $aid = (int)input('address_id', 0);
    db_exec('DELETE FROM vendor_addresses WHERE id = ? AND vendor_id = ?', [$aid, $vid]);
    flash_set('success', 'Address removed.');
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

// ============================================================
// INVENTORY LINK sub-grid actions
//
// Backing table is inv_item_vendors — a many-to-many that already
// exists and is also editable from the inventory item edit page.
// We add link by resolving an item CODE the operator types (rather
// than offering a dropdown of every item, which would be unusable on
// a long catalogue). Duplicate links are silently ignored thanks to
// the composite PK on (item_id, vendor_id).
// ============================================================
if ($action === 'item_link_add') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    // Either a numeric item_id (from a future autocomplete) or a code
    // typed by the operator. We accept both transparently.
    $itemId = (int)input('item_id', 0);
    $code   = trim((string)input('item_code', ''));
    if (!$itemId && $code !== '') {
        $itemId = (int)db_val("SELECT id FROM inv_items WHERE code = ?", [$code], 0);
        if (!$itemId) {
            flash_set('error', 'No inventory item with code "' . h($code) . '".');
            redirect(url('/vendors.php?action=edit&id=' . $vid));
        }
    }
    if (!$itemId || !$vid) {
        flash_set('error', 'Item is required.');
        redirect(url('/vendors.php?action=edit&id=' . $vid));
    }
    $nextSort = (int)db_val(
        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM inv_item_vendors WHERE vendor_id = ?',
        [$vid], 10
    );
    db_exec(
        'INSERT IGNORE INTO inv_item_vendors (item_id, vendor_id, sort_order) VALUES (?, ?, ?)',
        [$itemId, $vid, $nextSort]
    );
    flash_set('success', 'Inventory item linked.');
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

if ($action === 'item_link_delete') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $iid = (int)input('item_id', 0);
    db_exec('DELETE FROM inv_item_vendors WHERE vendor_id = ? AND item_id = ?', [$vid, $iid]);
    flash_set('success', 'Inventory link removed.');
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

// ============================================================
// ASSET LINK sub-grid actions
//
// Backing table is vendor_assets — net-new in migration
// 20260531_174500_IST. Same shape and resolve-by-tag pattern as the
// inventory link above.
// ============================================================
if ($action === 'asset_link_add') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $assetId = (int)input('asset_id', 0);
    $tag     = trim((string)input('asset_tag', ''));
    if (!$assetId && $tag !== '') {
        $assetId = (int)db_val("SELECT id FROM assets WHERE asset_tag = ?", [$tag], 0);
        if (!$assetId) {
            flash_set('error', 'No asset with tag "' . h($tag) . '".');
            redirect(url('/vendors.php?action=edit&id=' . $vid));
        }
    }
    if (!$assetId || !$vid) {
        flash_set('error', 'Asset is required.');
        redirect(url('/vendors.php?action=edit&id=' . $vid));
    }
    $notes    = trim((string)input('va_notes', '')) ?: null;
    $nextSort = (int)db_val(
        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM vendor_assets WHERE vendor_id = ?',
        [$vid], 10
    );
    db_exec(
        'INSERT INTO vendor_assets (vendor_id, asset_id, sort_order, notes)
              VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE notes = VALUES(notes)',
        [$vid, $assetId, $nextSort, $notes]
    );
    flash_set('success', 'Asset linked.');
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

if ($action === 'asset_link_delete') {
    require_permission('vendors', 'manage');
    csrf_check();
    $vid = (int)input('vendor_id', 0);
    $aid = (int)input('asset_id', 0);
    db_exec('DELETE FROM vendor_assets WHERE vendor_id = ? AND asset_id = ?', [$vid, $aid]);
    flash_set('success', 'Asset link removed.');
    redirect(url('/vendors.php?action=edit&id=' . $vid));
}

// ============================================================
// NEW / EDIT form
// ============================================================
if ($action === 'new' || $action === 'edit') {
    require_permission('vendors', 'manage');
    $editing = null;
    if ($action === 'edit') {
        $editing = db_one('SELECT * FROM vendors WHERE id = ?', [(int)input('id', 0)]);
        if (!$editing) { flash_set('error', 'Not found.'); redirect(url('/vendors.php')); }
    }
    $contacts  = $editing ? db_all('SELECT * FROM vendor_contacts  WHERE vendor_id = ? ORDER BY is_primary DESC, sort_order, id', [(int)$editing['id']]) : [];
    $addresses = $editing ? db_all('SELECT * FROM vendor_addresses WHERE vendor_id = ? ORDER BY is_primary DESC, sort_order, id', [(int)$editing['id']]) : [];
    // Linked inventory items (m:n via inv_item_vendors). Read code +
    // name so the table is browseable without click-throughs.
    $linkedItems = $editing
        ? db_all(
            'SELECT i.id, i.code, i.name, iv.sort_order
               FROM inv_item_vendors iv
               JOIN inv_items i ON i.id = iv.item_id
              WHERE iv.vendor_id = ?
              ORDER BY iv.sort_order, i.code',
            [(int)$editing['id']]
        )
        : [];
    // Inventory items still available to link (active + not already
    // linked). Used to populate the picker <select>; combobox.js will
    // enhance it into a search-and-select.
    $unlinkedItems = $editing
        ? db_all(
            'SELECT i.id, i.code, i.name
               FROM inv_items i
              WHERE i.is_active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM inv_item_vendors iv
                     WHERE iv.item_id = i.id AND iv.vendor_id = ?
                )
              ORDER BY i.code',
            [(int)$editing['id']]
        )
        : [];
    // Linked assets (m:n via vendor_assets — new table for Phase B).
    // Resolve the asset tag and the model's display name.
    $linkedAssets = $editing
        ? db_all(
            "SELECT a.id, a.asset_tag, COALESCE(am.name, '—') AS model_name, va.notes, va.sort_order
               FROM vendor_assets va
               JOIN assets a ON a.id = va.asset_id
          LEFT JOIN asset_models am ON am.id = a.model_id
              WHERE va.vendor_id = ?
              ORDER BY va.sort_order, a.asset_tag",
            [(int)$editing['id']]
        )
        : [];
    // Assets still available to link (not archived + not already
    // linked). Same picker pattern as inventory above.
    $unlinkedAssets = $editing
        ? db_all(
            "SELECT a.id, a.asset_tag, COALESCE(am.name, '') AS model_name
               FROM assets a
          LEFT JOIN asset_models am ON am.id = a.model_id
              WHERE a.status <> 'archived'
                AND NOT EXISTS (
                    SELECT 1 FROM vendor_assets va
                     WHERE va.asset_id = a.id AND va.vendor_id = ?
                )
              ORDER BY a.asset_tag",
            [(int)$editing['id']]
        )
        : [];

    $page_title  = $editing ? ('Edit ' . $editing['name']) : 'New vendor';
    $page_module = 'vendors';
    $focus_id    = 'f_name';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'       => $editing ? $editing['name'] : 'New vendor',
            'subtitle'    => $editing ? $editing['code'] : 'Supplier or service provider',
            'back_href'   => url('/vendors.php'),
            'back_label'  => 'Vendors',
            'actions_html' =>
                '<button type="submit" form="main-form" class="btn btn-primary btn-sm"'
              . ' data-shortcut="S" accesskey="s">' . shortcut_label('Save', 'S') . '</button>'
              . ' <a class="btn btn-ghost btn-sm" href="' . h(url('/vendors.php')) . '"'
              . ' data-shortcut="C" accesskey="c">' . shortcut_label('Cancel', 'C') . '</a>',
        ]) ?>
        <div class="form-page-body">
            <!-- Main vendor header form -->
            <form id="main-form" method="post" action="<?= h(url('/vendors.php?action=save')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

                <?php if ($editing): ?>
                    <div class="field">
                        <label>Vendor code</label>
                        <input type="text" value="<?= h($editing['code']) ?>" readonly tabindex="-1" class="mono">
                        <span class="muted small">Auto-generated · immutable</span>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>Vendor code</label>
                        <input type="text" value="(auto-generated on save)" readonly tabindex="-1" class="muted">
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="f_name">Name *</label>
                    <input id="f_name" name="name" type="text" required tabindex="1"
                           value="<?= h($editing['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_gst">GSTIN</label>
                    <input id="f_gst" name="gst_no" type="text" tabindex="2"
                           value="<?= h($editing['gst_no'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="f_pan">PAN</label>
                    <input id="f_pan" name="pan_no" type="text" tabindex="3"
                           value="<?= h($editing['pan_no'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_payment_terms">Payment terms</label>
                    <input id="f_payment_terms" name="payment_terms" type="text" tabindex="4"
                           placeholder="e.g. Net 30, Net 60, 50% advance + 50% on delivery"
                           value="<?= h($editing['payment_terms'] ?? '') ?>">
                </div>
                <div class="field span-2">
                    <label for="f_notes">Notes</label>
                    <textarea id="f_notes" name="notes" rows="2" tabindex="4"><?= h($editing['notes'] ?? '') ?></textarea>
                </div>
                <div class="field span-2">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="is_active" value="1" tabindex="5"
                               <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                        Active
                    </label>
                </div>

                <h3 class="span-2" style="margin: 10px 0 0 0; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;">Bank details</h3>

            <div class="field">
                <label for="f_bank_name">Bank name</label>
                <input id="f_bank_name" name="bank_name" type="text" tabindex="10"
                       value="<?= h($editing['bank_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_bank_branch">Branch</label>
                <input id="f_bank_branch" name="bank_branch" type="text" tabindex="11"
                       value="<?= h($editing['bank_branch'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_acc_name">Account name</label>
                <input id="f_acc_name" name="bank_account_name" type="text" tabindex="12"
                       value="<?= h($editing['bank_account_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_acc_no">Account number</label>
                <input id="f_acc_no" name="bank_account_number" type="text" tabindex="13"
                       value="<?= h($editing['bank_account_number'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_ifsc">IFSC</label>
                <input id="f_ifsc" name="bank_ifsc" type="text" tabindex="14"
                       value="<?= h($editing['bank_ifsc'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="f_swift">SWIFT</label>
                <input id="f_swift" name="bank_swift" type="text" tabindex="15"
                       value="<?= h($editing['bank_swift'] ?? '') ?>">
            </div>
        </form>

    <?php if ($editing): ?>
        <!-- CONTACTS -->
        <div class="form-section">
            <h2>Contacts</h2>
            <table class="data-table">
                <thead><tr><th>Salutation</th><th>Name</th><th>Designation</th><th>Email</th><th>Phone</th><th>Primary</th><th class="r">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$contacts): ?>
                        <tr><td colspan="7" class="empty muted">No contacts yet. Add one below.</td></tr>
                    <?php else: foreach ($contacts as $c): ?>
                        <tr>
                            <td><?= h($c['salutation'] ?: '—') ?></td>
                            <td><strong><?= h($c['name']) ?></strong></td>
                            <td><?= h($c['designation'] ?: '—') ?></td>
                            <td><?= h($c['email'] ?: '—') ?></td>
                            <td><?= h($c['phone'] ?: '—') ?></td>
                            <td><?= $c['is_primary'] ? '<span class="pill pill-active">primary</span>' : '' ?></td>
                            <td class="r">
                                <form method="post" style="display:inline"
                                      action="<?= h(url('/vendors.php?action=contact_delete')) ?>"
                                      onsubmit="return confirm('Remove this contact?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="vendor_id"  value="<?= (int)$editing['id'] ?>">
                                    <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-icon" type="submit" title="Remove contact" aria-label="Remove contact">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <h3 style="margin-top: 16px; font-size: 13px; color: var(--text-muted);">Add contact</h3>
            <form method="post" action="<?= h(url('/vendors.php?action=contact_save')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="vendor_id" value="<?= (int)$editing['id'] ?>">
                <div class="field">
                    <label>Salutation</label>
                    <select name="c_salutation">
                        <option value="">—</option>
                        <?php foreach (['Mr', 'Ms', 'Mrs', 'Mx', 'Dr', 'Prof'] as $sal): ?>
                            <option value="<?= h($sal) ?>"><?= h($sal) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>Name *</label><input name="c_name" type="text" required></div>
                <div class="field"><label>Designation</label><input name="c_designation" type="text"></div>
                <div class="field"><label>Email</label><input name="c_email" type="email"></div>
                <div class="field"><label>Phone</label><input name="c_phone" type="text"></div>
                <div class="field span-2">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="c_is_primary" value="1"> Make this the primary contact
                    </label>
                </div>
                <div class="form-actions span-2">
                    <button type="submit" class="btn btn-primary">+ Add contact</button>
                </div>
            </form>
        </div>

        <!-- ADDRESSES -->
        <div class="form-section">
            <h2>Addresses</h2>
            <table class="data-table">
                <thead><tr><th>Label</th><th>Address</th><th>City</th><th>State</th><th>Pincode</th><th>Country</th><th>Primary</th><th class="r">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$addresses): ?>
                        <tr><td colspan="8" class="empty muted">No addresses yet. Add one below.</td></tr>
                    <?php else: foreach ($addresses as $a):
                        $line = trim($a['line1'] . (!empty($a['line2']) ? ', ' . $a['line2'] : ''));
                    ?>
                        <tr>
                            <td><?= h($a['label'] ?: '—') ?></td>
                            <td><?= h($line) ?></td>
                            <td><?= h($a['city'] ?: '—') ?></td>
                            <td><?= h($a['state'] ?: '—') ?></td>
                            <td><?= h($a['pincode'] ?: '—') ?></td>
                            <td><?= h($a['country']) ?></td>
                            <td><?= $a['is_primary'] ? '<span class="pill pill-active">primary</span>' : '' ?></td>
                            <td class="r">
                                <form method="post" style="display:inline"
                                      action="<?= h(url('/vendors.php?action=address_delete')) ?>"
                                      onsubmit="return confirm('Remove this address?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="vendor_id"  value="<?= (int)$editing['id'] ?>">
                                    <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                                    <button class="btn btn-icon" type="submit" title="Remove address" aria-label="Remove address">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <h3 style="margin-top: 16px; font-size: 13px; color: var(--text-muted);">Add address</h3>
            <form method="post" action="<?= h(url('/vendors.php?action=address_save')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="vendor_id" value="<?= (int)$editing['id'] ?>">
                <div class="field"><label>Label</label><input name="a_label" type="text" placeholder="Head office, Factory, …"></div>
                <div class="field"><label>Country</label><input name="a_country" type="text" value="India"></div>
                <div class="field span-2"><label>Address line 1 *</label><input name="a_line1" type="text" required></div>
                <div class="field span-2"><label>Address line 2</label><input name="a_line2" type="text"></div>
                <div class="field"><label>City</label><input name="a_city" type="text"></div>
                <div class="field"><label>State</label><input name="a_state" type="text"></div>
                <div class="field"><label>Pincode</label><input name="a_pincode" type="text"></div>
                <div class="field span-2">
                    <label class="nowrap" style="font-weight: normal;">
                        <input type="checkbox" name="a_is_primary" value="1"> Make this the primary address
                    </label>
                </div>
                <div class="form-actions span-2">
                    <button type="submit" class="btn btn-primary">+ Add address</button>
                </div>
            </form>
        </div>

        <!-- LINKED INVENTORY ITEMS -->
        <!-- Tells the operator: "this vendor can supply / produce
             these items." Backed by the existing inv_item_vendors
             table (also surfaced from the inventory item edit page).
             Items are added by typing a code (autocomplete is a future
             nicety); duplicate adds are silently no-ops thanks to the
             composite PK on (item_id, vendor_id). -->
        <div class="form-section">
            <h2>Linked inventory items</h2>
            <p class="muted small">Items this vendor can produce or supply. Shown on the inventory item's vendor list too.</p>
            <table class="data-table">
                <thead><tr><th>Code</th><th>Name</th><th class="r">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$linkedItems): ?>
                        <tr><td colspan="3" class="empty muted">No items linked yet. Add one below.</td></tr>
                    <?php else: foreach ($linkedItems as $li): ?>
                        <tr>
                            <td><code><?= h($li['code']) ?></code></td>
                            <td><?= h($li['name']) ?></td>
                            <td class="r">
                                <form method="post" style="display:inline"
                                      action="<?= h(url('/vendors.php?action=item_link_delete')) ?>"
                                      onsubmit="return confirm('Remove link to this item?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="vendor_id" value="<?= (int)$editing['id'] ?>">
                                    <input type="hidden" name="item_id"   value="<?= (int)$li['id'] ?>">
                                    <button class="btn btn-icon" type="submit" title="Unlink item" aria-label="Unlink item">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <h3 style="margin-top: 16px; font-size: 13px; color: var(--text-muted);">Link an item</h3>
            <form method="post" action="<?= h(url('/vendors.php?action=item_link_add')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="vendor_id" value="<?= (int)$editing['id'] ?>">
                <div class="field">
                    <label>Item</label>
                    <?php if ($unlinkedItems): ?>
                        <select name="item_id" required>
                            <option value="">— search and select —</option>
                            <?php foreach ($unlinkedItems as $ui): ?>
                                <option value="<?= (int)$ui['id'] ?>">
                                    <?= h($ui['code']) ?> — <?= h($ui['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <p class="muted small" style="margin: 6px 0;">All active items are already linked to this vendor.</p>
                    <?php endif; ?>
                </div>
                <?php if ($unlinkedItems): ?>
                    <div class="form-actions span-2">
                        <button type="submit" class="btn btn-primary">+ Link item</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- LINKED ASSETS -->
        <!-- Tells the operator: "this vendor can calibrate or service
             these assets." Backed by vendor_assets (new in
             20260531_174500_IST). -->
        <div class="form-section">
            <h2>Linked assets</h2>
            <p class="muted small">Assets this vendor can calibrate or service.</p>
            <table class="data-table">
                <thead><tr><th>Tag</th><th>Model</th><th>Notes</th><th class="r">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$linkedAssets): ?>
                        <tr><td colspan="4" class="empty muted">No assets linked yet. Add one below.</td></tr>
                    <?php else: foreach ($linkedAssets as $la): ?>
                        <tr>
                            <td><code><?= h($la['asset_tag']) ?></code></td>
                            <td><?= h($la['model_name']) ?></td>
                            <td><?= h($la['notes'] ?: '—') ?></td>
                            <td class="r">
                                <form method="post" style="display:inline"
                                      action="<?= h(url('/vendors.php?action=asset_link_delete')) ?>"
                                      onsubmit="return confirm('Remove link to this asset?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="vendor_id" value="<?= (int)$editing['id'] ?>">
                                    <input type="hidden" name="asset_id"  value="<?= (int)$la['id'] ?>">
                                    <button class="btn btn-icon" type="submit" title="Unlink asset" aria-label="Unlink asset">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <h3 style="margin-top: 16px; font-size: 13px; color: var(--text-muted);">Link an asset</h3>
            <form method="post" action="<?= h(url('/vendors.php?action=asset_link_add')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="vendor_id" value="<?= (int)$editing['id'] ?>">
                <div class="field">
                    <label>Asset</label>
                    <?php if ($unlinkedAssets): ?>
                        <select name="asset_id" required>
                            <option value="">— search and select —</option>
                            <?php foreach ($unlinkedAssets as $ua): ?>
                                <option value="<?= (int)$ua['id'] ?>">
                                    <?= h($ua['asset_tag']) ?><?php if ($ua['model_name']): ?> — <?= h($ua['model_name']) ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <p class="muted small" style="margin: 6px 0;">All active assets are already linked to this vendor.</p>
                    <?php endif; ?>
                </div>
                <?php if ($unlinkedAssets): ?>
                    <div class="field">
                        <label>Notes (optional)</label>
                        <input name="va_notes" type="text" placeholder="e.g. Authorised Zeiss service partner">
                    </div>
                    <div class="form-actions span-2">
                        <button type="submit" class="btn btn-primary">+ Link asset</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>
        </div><!-- /.form-page-body -->
    </div><!-- /.form-page -->
    <?php require __DIR__ . '/includes/footer.php'; exit;
}

// ============================================================
// LIST (default)
// ============================================================
$dtCfg = [
    'id'       => 'vendors',
    'base_sql' => 'SELECT v.*,
                          (SELECT COUNT(*) FROM vendor_contacts  vc WHERE vc.vendor_id  = v.id) AS contact_count,
                          (SELECT COUNT(*) FROM vendor_addresses va WHERE va.vendor_id  = v.id) AS address_count,
                          (SELECT name FROM vendor_contacts WHERE vendor_id = v.id AND is_primary = 1 LIMIT 1) AS primary_contact
                     FROM vendors v',
    'columns'  => [
        ['key'=>'code',            'label'=>'Code',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'v.code'],
        ['key'=>'name',            'label'=>'Name',     'sortable'=>true, 'searchable'=>true, 'sql_col'=>'v.name'],
        ['key'=>'primary_contact', 'label'=>'Primary contact', 'sortable'=>false, 'searchable'=>false],
        ['key'=>'gst_no',          'label'=>'GSTIN',    'sortable'=>true, 'searchable'=>true, 'sql_col'=>'v.gst_no'],
        ['key'=>'contact_count',   'label'=>'#Contacts','sortable'=>false, 'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'address_count',   'label'=>'#Addr.',   'sortable'=>false, 'searchable'=>false, 'th_class'=>'r','td_class'=>'r'],
        ['key'=>'is_active',       'label'=>'Status',   'sortable'=>true, 'sql_col'=>'v.is_active',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[
             ['value'=>'1','label'=>'Active'],
             ['value'=>'0','label'=>'Disabled'],
         ]]],
        ['key'=>'_actions',        'label'=>'Actions',  'sortable'=>false, 'searchable'=>false, 'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['code', 'asc'],
];
$rowRenderer = function ($v) use ($canManage, $canDelete) {
    $status = $v['is_active']
        ? '<span class="pill pill-active">active</span>'
        : '<span class="pill pill-neutral">disabled</span>';
    $actions = '<a class="btn btn-icon" title="Edit vendor" aria-label="Edit vendor" href="'
             . h(url('/vendors.php?action=edit&id=' . (int)$v['id'])) . '">✎ <span class="dt-action-label">Edit vendor</span></a> ';
    if ($canManage) {
        $toggleTitle = $v['is_active'] ? 'Disable vendor' : 'Enable vendor';
        $glyph       = $v['is_active'] ? '🚫' : '✅';
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/vendors.php?action=toggle')) . '">'
                  . csrf_field() . '<input type="hidden" name="id" value="' . (int)$v['id'] . '">'
                  . '<button class="btn btn-icon" type="submit" title="' . h($toggleTitle) . '" aria-label="' . h($toggleTitle) . '">'
                  . $glyph . ' <span class="dt-action-label">' . h($toggleTitle) . '</span></button></form> ';
    }
    if ($canDelete) {
        $actions .= '<form method="post" style="display:inline" action="' . h(url('/vendors.php?action=delete')) . '"'
                  . ' onsubmit="return confirm(\'Delete vendor &quot;' . h(addslashes($v['name'])) . '&quot;?\');">'
                  . csrf_field() . '<input type="hidden" name="id" value="' . (int)$v['id'] . '">'
                  . '<button class="btn btn-icon btn-danger" type="submit" title="Delete vendor" aria-label="Delete vendor">🗑 <span class="dt-action-label">Delete vendor</span></button></form>';
    }
    return [
        'code'            => '<code>' . h($v['code']) . '</code>',
        'name'            => '<strong><a href="' . h(url('/vendors.php?action=edit&id=' . (int)$v['id'])) . '">' . h($v['name']) . '</a></strong>',
        'primary_contact' => h($v['primary_contact'] ?: '—'),
        'gst_no'          => h($v['gst_no'] ?: '—'),
        'contact_count'   => (int)$v['contact_count'],
        'address_count'   => (int)$v['address_count'],
        'is_active'       => $status,
        '_actions'        => dt_actions_wrap($actions),
    ];
};
$dt = data_table_run($dtCfg, $rowRenderer);

$page_title  = 'Vendors';
$page_module = 'vendors';
$focus_id    = '';
$newBtnHtml = $canManage
    ? '<a class="btn btn-primary" href="' . h(url('/vendors.php?action=new')) . '"'
      . ' data-shortcut="N" accesskey="n">' . shortcut_label('+ New vendor', 'N') . '</a>'
    : '';
$dtCfg['title']        = 'Vendors';
$dtCfg['description']  = 'Suppliers and service providers. Each vendor can have multiple contacts and addresses.';
$dtCfg['actions_html'] = $newBtnHtml;
require __DIR__ . '/includes/header.php';
?>
<?php data_table_render($dtCfg, $dt, $rowRenderer); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
