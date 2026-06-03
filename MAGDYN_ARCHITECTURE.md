# MagDyn Architecture

PHP 7.0+/MariaDB ERP/admin console for a precision manufacturing &
metrology shop. Deployed at `magdyn.com/erp/` on Hostinger shared
hosting (account `u184502428`). Local working tree in Claude's
sandbox: `/home/claude/work/magdyn-app/`.

This doc is the canonical state-of-the-world after the **Inspection
IR module integration** (early Jun 2026). Read it before touching
the code.

---

## Tech stack & hard constraints

| Layer | Choice / constraint |
|---|---|
| Language | PHP **7.0.33** — 7.0/7.1 compatibility is a hard requirement. No `match`, no nullsafe `?->`, no named args, no constructor property promotion. |
| DB | MariaDB 10.5+ via PDO with prepared statements. Helper layer: `db()`, `db_one()`, `db_all()`, `db_val()`, `db_exec()` in `includes/db.php`. |
| Composer | **Not used.** All deps vendored under `includes/vendor/`. |
| Frontend | Server-rendered PHP. Light vanilla JS (no React/Vue). MagDyn design system CSS (`assets/css/magdyn-base.css` + `app.css`). |
| Hosting | Hostinger shared hosting. SQL run via phpMyAdmin tab OR CLI. Some patterns that work in CLI **don't** work in phpMyAdmin (see Migration Patterns). |
| Sessions | PHP native sessions. Login at `/login.php`. CSRF tokens on every form (`csrf_field()` / `csrf_check()`). |

---

## Recurring conventions — read this first

These rules came up repeatedly in past sessions. Violating them produces broken deploys.

| Rule | Why |
|---|---|
| Never use `SET @var = …;` in migrations | Doesn't persist across statements in phpMyAdmin's SQL tab on Hostinger. Use inline subqueries instead. |
| Always add `class="no-combobox"` to `<select>` that needs `getElementById` access | The combobox widget moves the `id` from the `<select>` to a wrapping `<input>`, breaking lookups. |
| Append `?v=<filemtime>` to JS/CSS includes | Bypasses service-worker cache after edits. The service worker caches statics aggressively. |
| `render_attachments_section()` echoes directly | Don't wrap it in `echo()` — produces double output. |
| Lookbehind regex unsupported pre-Safari 16.4 | Avoid `(?<=…)` in client-side regex; rewrite as a capture group. |
| `config/app.config.php` is PERSISTENT | Merge edits, never overwrite the whole file. |
| `*/` inside a PHP docblock closes the comment early | Avoid sequences like `tolerance_*/unit` in docblocks; the `*/` terminates the comment and the rest becomes literal code. Rephrase. |
| `COLLATE` cannot follow an `IN (…)` list | Attach to the column instead: `col COLLATE utf8mb4_unicode_ci IN ('x','y')`. |
| `db_one()` returns NULL on no rows | Use `db_val()` for scalar `fetchColumn`. |
| Avoid touching `_inspection_reports.php`, `inspection_reports.php` | These were rolled back when the IR module folded into `inspections`. They no longer exist. |

---

## Standard migration shape

Idempotent, phpMyAdmin-safe migration that adds a column:

```sql
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_some_change;
DELIMITER //
CREATE PROCEDURE magdyn_p_some_change()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'some_table'
                      AND COLUMN_NAME = 'new_col') THEN
        ALTER TABLE some_table ADD COLUMN new_col VARCHAR(60) NULL;
    END IF;
END //
DELIMITER ;
CALL magdyn_p_some_change();
DROP PROCEDURE magdyn_p_some_change;
```

Idempotent insert into a config table with FK lookup:

```sql
INSERT INTO process_nodes (process_id, node_key, …)
SELECT p.id, 'q_remarks', …
  FROM processes p
 WHERE p.slug = 'sys-inspection-flow' COLLATE utf8mb4_unicode_ci
   AND NOT EXISTS (
       SELECT 1 FROM process_nodes pn
        WHERE pn.process_id = p.id
          AND pn.node_key COLLATE utf8mb4_unicode_ci = 'q_remarks'
   );
```

Migrations are dated `migration_YYYYMMDD_HHMMSS_IST.sql` and placed in `sql/`.

---

## Datatable API (recurring pattern)

Every list page uses the same helper layer at `includes/datatable.php`:

```php
$dtCfg = [
    'id'       => 'inspections',
    'base_sql' => "SELECT … FROM … LEFT JOIN …",
    'extra_where' => [
        ['i.is_deleted = 0', []],           // tuple: [sql_fragment, params]
        ["i.status IN ('draft','in_progress')", []],
    ],
    'columns' => [
        ['key'=>'ir_no', 'label'=>'IR #', 'sortable'=>true, 'searchable'=>true,
         'sql_col'=>'i.ir_no', 'td_class'=>'nowrap'],
        ['key'=>'status', 'label'=>'Status', 'sortable'=>true,
         'sql_col'=>'i.status',
         'filter' => ['type'=>'select','placeholder'=>'all','options'=>[…]]],
        ['key'=>'_actions', 'label'=>'', 'sortable'=>false,
         'th_class'=>'r','td_class'=>'r nowrap'],
    ],
    'default_sort' => ['code', 'desc'],
];
$rowRenderer = function ($r) { return [/* key => html per column */]; };
$dt = data_table_run($dtCfg, $rowRenderer);
$dtCfg['title']        = 'Inspection list';
$dtCfg['actions_html'] = '…';
data_table_render($dtCfg, $dt, $rowRenderer);
```

`extra_where` is a list of `[sql_fragment_with_?, [params]]` tuples. Sending a flat array of strings is the bug that's bitten twice.

---

## Code sequences (auto-numbered identifiers)

`code_sequences` table seeds next-N counters for things like `INSP-NNNNNN`, `IR.NNNNN`, `JC-NNNN`. Columns: `name`, `label`, `description`, `prefix`, `pad`, `format` (e.g. `'{prefix}{n}'`), `target_table`, `target_column`. Generate next via `code_next($name)`.

To anchor a sequence at a specific number, `UPDATE` the target table's column on any existing row first; the next `code_next()` call reads `MAX(col)` and continues from there.

---

## File uploads

Standard pattern: `/uploads/<module>/<id>/<doctype>_<ts>_<hex>_<safename>.<ext>` with a `Deny from all` `.htaccess`. Files served back via a per-module attach controller that checks permissions and streams the file. Attachment metadata lives in per-module tables (`inspection_attachments`, `vendor_attachments`, etc.).

The polymorphic `attachments` table is used elsewhere (it has an ENUM `entity_type`); the per-module attachment tables are direct FK style.

---

## Authentication, RBAC, audit

- `users`, `roles`, `user_roles`, `modules`, `permissions`, `role_permissions`. SSO-ready (`includes/sso.php` switches between local / oidc / saml / header).
- `permission_check('module', 'action')` / `require_permission('module', 'action')` in `includes/permissions.php`.
- `current_user()` / `current_user_id()` in `includes/auth.php`.
- Admin impersonation via `$_SESSION['impersonate_uid']`; banner + Alt+X to exit.
- Audit log: `audit_log` table; module events insert via the same helper.

---

## Module map (top-level)

| Module | Pages | Notes |
|---|---|---|
| Inspection (IR-aware) | `inspection.php` | Templates, multi-sample IR grid, snapshot part fields, live job_card / inv_items derivation. **Major recent rewrite — see IR section below.** |
| Inventory | `inventory.php`, `inventory_shiprcpt.php`, `includes/_inventory_txn.php` | Items, txns, lots, BOMs. Ship&Receipt with AJAX receive flow. |
| Vendor empanelment | `vendor_empanelment.php`, `nda_templates.php`, `vendor_portal.php`, `includes/_vendor_empanelment.php` | Onboarding + NDA library + public token-authenticated portal. Phase E.2 complete. |
| Assets / calibration | `asset.php`, `asset_lookups.php`, `cron/calibration_notify.php` | Assets, models, calibration cycles. |
| ATS | `ats.php`, `includes/_ats.php` | Asset transfer slips. |
| Job cards | `job_card.php`, `tools/job-card-training.php` | Four-department sequential workflow. Re-skinned to MagDyn design system. |
| ECN | `ecn.php`, `ecn_admin.php`, `includes/_ecn.php` | Engineering change notices. |
| DMS | `documents.php`, `includes/_dms.php` | Document management. |
| CMM | `cmm.php`, `includes/_cmm.php` | CMM measurement integration. |
| Bubble tool | `tools/bubble_tool.php` | Drawing bubble annotation. Feeds inspection templates. |
| Engineering calculator | `tools/engineering-calculator.php` | Process calcs (weights, volumes, etc.). |
| Weight calculator | `tools/weight-calculator.php` | Standalone weight-of-material tool. |
| Training | `training.php`, `tools/*-training.php` | 12 manual courses + per-course quiz. Strict sequential nav. Recently expanded with inspection-IR content. |
| Process flows | `processes.php`, `includes/_processes.php` | Mermaid-rendered workflow diagrams seeded from migrations. `sys-inspection-flow` recently IR-aware. |
| Roles & permissions | `roles.php`, `modules.php` | RBAC matrix. |
| Admin | `users.php`, `audit.php`, `mobile_settings.php`, `notifications.php` | User management, audit, push notifications. |
| API | `api/dt_prefs.php`, `api/job_card.php`, `api/push_subscribe.php`, `api/so_pending.php` | External / mobile endpoints. |
| Auth | `login.php`, `logout.php`, `sso_begin.php`, `sso_callback.php` | Local + SSO. |

---

## Inspection IR module (the major recent work)

Folded the printed-IR (Inspection Report) format into the existing `inspections` schema. **The inspection list IS the IR list** — no parallel module.

### Schema additions (migration `20260601_230000_IST`)

**`inspections`:**
- `ir_no VARCHAR(40)` — parallel to `code` (INSP-NNNNNN). Format `IR.NNNNN`. Generated via `code_next('inspection_ir')`.
- `part_no`, `part_rev`, `part_description`, `pid` — **snapshot** from `inv_items` at creation (so the printed IR survives later inv_items edits/deletes).
- `chkd_qty INT` — manual entry, no sampling-plan column.
- `sample_count INT UNSIGNED NOT NULL DEFAULT 1` — the grid geometry switch.
- `sample_remarks_json TEXT` — sparse `{"1":"Accepted","5":"Rejected"}` map.
- `job_card_id INT UNSIGNED NULL` FK to `job_cards(id)` — PO no, PO line, PDN qty are read **live** through this link.
- `parent_inspection_id INT UNSIGNED NULL` self-FK — reserved for future SO-split chain.

**`inspection_results`:**
- `sample_no INT UNSIGNED NULL` — multi-sample distinguisher. NULL = legacy single-sample.
- `instrument_asset_id INT UNSIGNED NULL` FK to `assets(id)` — snapshotted from template at seed time.

**`inspection_template_items`:**
- `instrument_asset_id INT UNSIGNED NULL` FK to `assets(id)` — picker on template_edit filters by `is_active = 1`.

**Code sequence:** `inspection_ir` (prefix `IR.`, no padding, format `{prefix}{n}`, targets `inspections.ir_no`).

### Confirmed column maps for derivation

- `inv_items.part_no` → `inspections.part_no` (snapshot)
- `inv_items.part_rev_no` → `inspections.part_rev` (snapshot)
- `inv_items.long_description` → `inspections.part_description` (snapshot)
- `inv_items.code` → `inspections.pid` (snapshot)
- `inv_items.dwg_no` / `inv_items.dwg_rev_no` → **live derived** at view time
- `job_cards.po_no` / `job_cards.line_no` / `job_cards.pdn_qty` → **live derived** via `inspections.job_card_id`
- Job-card picker label: `code + po_no + line_no + part_no`
- Asset picker: `is_active = 1` only, label `asset_tag + asset_models.name`

### IR helpers (`includes/_inspection_ir.php`)

| Function | Purpose |
|---|---|
| `ir_snapshot_part_from_inv_item($id)` | Returns part snapshot map for INSERT into `inspections`. |
| `ir_job_card_header($jcId)` | Live read of PO no / line / PDN qty. |
| `ir_job_card_picker($q, $limit)` | Fuzzy search for the picker (matches code, PO, part). |
| `ir_instrument_picker($q, $limit)` | Active assets only. |
| `ir_remarks_decode($json)` / `ir_remarks_encode($map)` | Sparse-map codec for `sample_remarks_json`. |
| `ir_seed_results_with_samples($id, $tplId, $sampleCount)` | Wipes results, seeds N rows per template item with instrument snapshot. |
| `ir_results_grid($id)` | Returns `['grid' => [$tid][$sno] => row, 'params' => […]]` for grid rendering. |
| `ir_min_max($target, $lower, $upper)` | Lower/upper are ± offsets from target, NOT absolute bounds. |
| `ir_evaluate($value, $min, $max)` | Numeric or text indicators ("OK", "NOT OK[NG]"). |
| `ir_fmt_num($v)` | Trim trailing zeros for display. |
| `ir_next_no()` | `code_next('inspection_ir')` with MAX-scan fallback if the sequence row is missing. |

### UI flow

1. **List page (`?action=`)** — first column is **IR #** (linked to view, internal `code` as subtitle). Second-from-end is **Part / PO** combining part identity (with cascading fallback: txn → direct inv_items → snapshot) plus live customer PO from `job_cards`. Search matches part code, name, AND PO number.

2. **New (`?action=new`)** — adds an "IR document & sampling" fieldset: job-card picker (AJAX search via `?action=job_card_picker`), sample columns (1–60), checked qty. Picking a job card auto-suggests `sample_count = pdn_qty` as a placeholder hint.

3. **Save `op=create`** — generates ir_no, snapshots part fields when entity is an inv_item, writes new fields, wraps insert + seed in a transaction, calls `ir_seed_results_with_samples()`.

4. **Execute (`?action=execute`)** — replaced legacy per-row UI with a **multi-sample grid**. Rows = parameters, columns = S1..SN. One text input per cell (numeric or text indicators). Note rows (`check_type='text'`) span as full-width text inputs with mirrored hidden inputs so the same text lands in every sample's row. Per-sample remarks footer (default "Accepted"). Sticky header, horizontal scroll.

5. **Save `op=execute`** — **auto-computes pass/fail** server-side via `ir_evaluate()` when no explicit verdict is posted (the multi-sample grid case). Empty cells stay `pending`. Reads `sample_remarks[sno]` and writes JSON to `sample_remarks_json`.

6. **View (`?action=view`)** — renders an IR document header card (only when `hasIrData`) with snapshot vs live derivation per cell. Multi-sample grid read-only with pass/fail-coloured cells: green `pass`, red `fail` (bold), amber `pending` with a value, no colour for empty/`na`.

7. **Templates / template_edit** — new **Instrument** column between Unit and Required. Picker fetches active assets once (no N+1), labels `TAG — Model name`. Save reads `item_instrument_id[]` into `inspection_template_items.instrument_asset_id`. `template_clone` carries the FK forward. CSV import doesn't include instrument (would need versioning).

### Backfill caveat

Inspections seeded **before** the template had instruments populated will have `instrument_asset_id = NULL` on their results. One-liner backfill per template after populating:

```sql
UPDATE inspection_results r
  JOIN inspection_template_items i ON i.id = r.template_item_id
   SET r.instrument_asset_id = i.instrument_asset_id
 WHERE r.inspection_id IN (SELECT id FROM inspections WHERE template_id = <TID>)
   AND r.instrument_asset_id IS NULL
   AND i.instrument_asset_id IS NOT NULL;
```

### Process flow

`sys-inspection-flow` updated by migration `20260602_120000_IST`: labels on `q_tmpl`, `q_create`, `q_exec` revised to mention instrument / job_card / multi-sample grid. New node `q_remarks` ("Capture per-sample remarks") inserted between `q_exec` and `q_pass`. Edges rewired. `body_html` and tags refreshed.

### Training manual

`tools/inspection-training.php` updated in sections §02 (Templates — Instrument FK), §04 (Starting — IR fields block), §05 (Executing — full rewrite for multi-sample grid), §07 (Reading Results — IR header card + coloured grid), §08 (Types — `finished_goods` flagged as IR driver).

### Files that no longer exist (were rolled back)

- `inspection_reports.php`
- `includes/_inspection_reports.php`

Their backing tables (`inspection_reports`, `inspection_report_lines`, `inspection_report_samples`, `inspection_report_sample_values`, `inspection_report_attachments`) are dropped by the rollback in migration `20260601_230000_IST`.

---

## Other completed major work (recent months)

### Vendor empanelment Phase E.2 (migration `20260601_175000_IST`)
- `vendor_categories` master (19 seeded codes), `vendor_application_categories` junction
- `nda_templates` (file uploads), `vendor_portal_tokens` (public-form access)
- Vendor application gains `expires_at`, `nda_template_id`, `renewal_of_application_id`
- Vendors gain `empanelment_expires_at`
- New module `nda_templates` with `manage` / `view` perms
- New vendor_empanelment perms: `invite`, `renew`
- Pages: `vendor_empanelment.php` (extended), `nda_templates.php` (new), `vendor_portal.php` (new, public no-login)

### Training module (Spring 2026)
- 12 manual courses (`manual-admin`, `-assets`, `-bubble`, `-cad`, `-calc`, `-dms`, `-ecn`, `-inspection`, `-inventory`, `-invoice`, `-running-notes`, `-weight`)
- Each course: "Read the manual" iframe step + 7-question quiz step
- Strict sequential nav, attempt history, per-question review, certification tracking

### Shipment module (Spring 2026)
- Receive-driven default flow
- AJAX BOM checklist with client-side line buffering for unsaved shipments
- Auto-derived mode (ship / receive / both) from line content

### MagDyn ↔ Billing integration (Spring 2026)
- Bidirectional product mirror; MagDyn authoritative for product metadata, billing authoritative for pricing/tax/HSN
- PID collision → MagDyn wins, claims existing local product row
- Smoke test suite with `T-` prefix fixture cleanup

### Job card workflow (earlier)
- Four-department sequential workflow; structured multi-file rewrite from monolithic single-file app
- Re-skinned to MagDyn design system, dark mode via CSS variable override

### SSO system (earlier — `magdyn.com/SSO/`)
- OAuth 2.0 Authorization Code flow, centralised RBAC, per-app permission scoping
- Self-publishing permissions manifest
- PHP 7.0/7.1 hard compatibility

---

## Open items (not yet built)

1. **Split flow** — `parent_inspection_id` is in the DB but unwired in UI. Needs decision on whether to split by job card or by ATS.
2. **PDF export of the printed IR** — would use vendored dompdf 1.2.2 (already in `includes/vendor/dompdf`).
3. **`measured_value` column width check** — text indicators like "Values Found but Job Nos Mismatched" are 30+ chars. Need `DESCRIBE inspection_results;` to confirm width.
4. **IR-specific quiz questions** in the training module for `manual-inspection`. Existing quiz covers procedural concepts only.
5. **Sampling-plan column on `inv_items`** — currently `chkd_qty` is manual. If a sampling-plan code is added later, derive it.

---

## Working style notes

- Crisp short answers preferred. Multi-part questions answered with `1=A, 2=B, …` shorthand.
- Asks before adding new schema fields (`ask me about new field addition, will confirm if new field is needed/existing can be used`).
- Wants single deltas with focused scope over giant batches.
- Cautious about destructive migrations — rollback the prior approach without complaint when corrected.
- Builds toward complete features but accepts staged delivery.

## Delta packaging

Every delivery is a zip in `/mnt/user-data/outputs/` named `magdyn-app_delta_YYYYMMDD_HHMMSS_IST.zip` containing **only the changed files**, with folder structure preserved (so it unzips on top of the deployed tree). The user downloads the full repo separately when they want a baseline.

Always mention in the deploy step: OPcache reload + migration order + hard refresh.

---

## Key file locations (quick reference)

| File | Purpose |
|---|---|
| `inspection.php` | All inspection action handlers (list / new / save / view / execute / templates / template_edit / attach / approve). Large — ~4100 lines. |
| `includes/_inspection_ir.php` | IR-specific helpers (snapshot, picker queries, grid indexing, evaluator). |
| `includes/_qc.php` | QC release / location movement on inspection approval. |
| `tools/inspection-training.php` | The user-facing inspection training manual (iframe embed target). |
| `sql/migration_20260601_230000_IST.sql` | Inspection IR data model. |
| `sql/migration_20260602_120000_IST.sql` | Inspection process-flow IR-aware update. |
| `sql/migration_20260601_175000_IST.sql` | Vendor empanelment Phase E.2. |
| `vendor_empanelment.php`, `nda_templates.php`, `vendor_portal.php` | Vendor empanelment pages. |
| `includes/_vendor_empanelment.php` | Empanelment helpers (tokens, expiry, category mgmt). |
| `includes/datatable.php` | Universal datatable layer. |
| `includes/permissions.php`, `includes/auth.php`, `includes/csrf.php` | Security primitives. |
| `includes/header.php`, `includes/footer.php` | Page chrome (sidebar, top bar, toolbar). |
| `includes/_notes.php` | Running-notes popup, used across modules. |
| `includes/_processes.php` | Mermaid renderer + process model. |
| `config/app.config.php`, `config/db.config.php` | **DO NOT OVERWRITE.** |
| `assets/css/magdyn-base.css` | Design system. Edit `app.css` for overrides. |
| `assets/js/datatable.js` | Front-end datatable JS. Custom event `datatable:updated` fires after re-render. |
