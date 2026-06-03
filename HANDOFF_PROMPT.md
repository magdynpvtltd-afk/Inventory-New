# MagDyn handoff — paste this into a new chat to continue

I'm working on **MagDyn**, my PHP 7.0+/MariaDB ERP at `magdyn.com/erp/`
on Hostinger shared hosting (account `u184502428`). I just bundled up
the latest repo as multiple zips so I can hand off to you mid-flight.

## What you have

I've uploaded:

1. **`MAGDYN_ARCHITECTURE.md`** — current state of the world. Read this
   first. Covers tech stack, recurring conventions (the rules I keep
   hitting), module map, the new Inspection IR module in detail, and
   open items.
2. **Repo zips** (5 of them, split for size):
   - `magdyn-app_code_*.zip` — root `*.php` files, `includes/*.php`
     (no vendor), `tools/*.php`, `api/`, `cron/`, `config/`, `mobile/`,
     `README.md`. This is the "code that changes" bundle.
   - `magdyn-app_sql_*.zip` — `sql/` migrations.
   - `magdyn-app_assets_*.zip` — CSS / JS / images.
   - `magdyn-app_vendor_*.zip` — `includes/vendor/` (dompdf 1.2.2 +
     phpmailer). Doesn't change often; reuse across sessions.
   - `magdyn-app_docs_*.zip` — `docs/`, `tools/docs/`, `uploads/`.
     Static reference; rarely touched.

Unzip the **code**, **sql**, and **assets** bundles onto a clean
`magdyn-app/` directory in your working tree. Add **vendor** if any
of my requests touch dompdf or phpmailer. Skip the **docs** bundle
unless I ask about a reference PDF.

## Hard rules (violations break my deploys)

These are in the architecture doc but worth surfacing:

- **PHP 7.0/7.1 compatibility.** No `match`, no `?->`, no named args.
- **No `SET @var` in migrations** — phpMyAdmin on Hostinger doesn't
  persist them across statements. Use inline subqueries.
- **No Composer.** Vendor dependencies live under `includes/vendor/`.
- **`config/app.config.php` is persistent** — merge edits, never
  overwrite.
- **`class="no-combobox"`** on every `<select>` accessed via
  `getElementById`.
- **`?v=<filemtime>`** on JS/CSS includes for service-worker
  cache-busting.
- **Avoid `*/` inside docblocks.** It closes the comment early.
- **Datatable `extra_where`** is `[[sql_with_?, [params]], …]` — list
  of tuples, NOT a list of strings.

## Delivery style

- Every deliverable is a zip in `/mnt/user-data/outputs/` named
  `magdyn-app_delta_YYYYMMDD_HHMMSS_IST.zip` containing **only the
  changed files**, folder structure preserved.
- One focused scope per delivery. I'll ask for more in follow-up turns.
- Always include: OPcache reload note, migration order, hard-refresh
  reminder.
- Lint every PHP file with `php -l` before zipping.
- Validate idempotency of every migration (re-run safe).

## My working style

- I give crisp short answers, often shorthand like `1=A, 2=C with cols
  X/Y/Z`.
- I want you to **ask before adding new schema fields** — I'll confirm
  whether a new column is needed or an existing one will do.
- Prefer staged delivery over giant batches. Small reviewable diffs.
- If something's destructive (drop column, rewrite table), surface it
  clearly before doing it.

## Where I am

Most recent work (early Jun 2026): folded the printed-IR (Inspection
Report) format into the existing `inspections` schema. The inspection
module IS the IR module — no parallel tables, no parallel list page.
Multi-sample grid execute UI, snapshot vs live derivation pattern,
new instrument FK to assets, training manual + process flow updated.

See the **Inspection IR module** section of the architecture doc for
the full data model and UI flow.

## Open items I haven't tackled yet

1. **Split flow** — `inspections.parent_inspection_id` is in the DB
   but unwired in UI. Needs decision on whether to split by job_card
   or by ATS.
2. **PDF export of the printed IR** — would use the vendored dompdf
   1.2.2 already in `includes/vendor/dompdf`.
3. **`measured_value` column width check** — text indicators like
   "Values Found but Job Nos Mismatched" are 30+ chars. Need
   `DESCRIBE inspection_results;` to confirm width.
4. **IR-specific quiz questions** in the training module for
   `manual-inspection`. Existing quiz covers procedural concepts only.
5. **Sampling-plan column on `inv_items`** — currently `chkd_qty` is
   manual. If a sampling-plan code is added later, derive it.

I'll tell you which to pick up first.

## First thing to do

Acknowledge you've read the architecture doc, then wait for my next
ask. Don't proactively re-explain the architecture back to me — I
wrote it.
