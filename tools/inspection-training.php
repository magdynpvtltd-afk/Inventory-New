<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Inspection · Operator Manual';
$current_page  = 'inspection-training.php';
$trigger_style = 'dark';
$cdn_scripts   = [];
include 'includes/head.php';
?>
<style>
:root { --muted: var(--text-muted); }
body { overflow-x: hidden; }
.layout { min-height: 100vh; }
.sidebar { height: 100vh; position: sticky; top: 0; overflow-y: auto; }
.toc-heading { padding: 14px 16px 6px; font-size: 10px; color: var(--sidebar-text-very-dim); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600; }
.toc ol { list-style: none; padding: 4px 8px 16px; margin: 0; counter-reset: tocsec; }
.toc ol li { counter-increment: tocsec; }
.toc ol li a { display: flex; align-items: center; gap: 10px; padding: 7px 12px; margin: 2px 0; color: var(--sidebar-text); font-size: 13px; border-radius: 6px; text-decoration: none; transition: background 0.12s, color 0.12s; }
.toc ol li a::before { content: counter(tocsec, decimal-leading-zero); font-size: 10px; font-weight: 700; color: var(--sidebar-text-very-dim); letter-spacing: 0.05em; flex-shrink: 0; width: 18px; }
.toc ol li a:hover { background: var(--sidebar-bg-hover); color: white; text-decoration: none; }
.toc ol li a:hover::before { color: rgba(255,255,255,0.6); }
.toc ol li a.active { background: var(--sidebar-bg-active); color: white; }
.toc ol li a.active::before { color: rgba(255,255,255,0.6); }
.main { padding: 32px 40px 60px; max-width: 880px; }
.hero { margin-bottom: 36px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
.hero .eyebrow { font-size: 11px; color: var(--primary); letter-spacing: 0.12em; text-transform: uppercase; font-weight: 700; margin-bottom: 12px; }
.hero h1 { font-size: 32px; font-weight: 600; letter-spacing: -0.02em; margin: 0 0 14px; color: var(--text); }
.hero h1 strong { color: var(--primary); font-weight: 700; }
.hero .lede { font-size: 15px; line-height: 1.7; color: var(--muted); max-width: 720px; }
section.module { margin: 0 0 48px; padding-top: 8px; }
section.module h2 { font-size: 22px; font-weight: 600; margin: 0 0 14px; display: flex; align-items: baseline; gap: 14px; }
section.module h2 .num { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; font-weight: 700; color: var(--text-light); letter-spacing: 0.05em; background: var(--surface-alt); padding: 4px 8px; border-radius: 4px; }
section.module h3 { font-size: 15px; font-weight: 600; margin: 22px 0 10px; color: var(--text); }
section.module h4 { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-light); margin: 18px 0 8px; }
section.module p { line-height: 1.7; color: var(--text); margin-bottom: 12px; }
section.module p.dim { color: var(--muted); font-size: 13.5px; }
table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 13px; }
table th, table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
table th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-light); background: var(--surface-alt); }
table td.dim { color: var(--muted); }
table td.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.callout { margin: 16px 0; padding: 12px 14px; border-left: 3px solid var(--primary); background: var(--surface-alt); border-radius: 4px; }
.callout .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--primary); margin-bottom: 6px; }
.callout p { margin: 0; font-size: 13.5px; line-height: 1.6; }
.callout.note { border-left-color: #6b7280; }
.callout.note .label { color: #6b7280; }
.callout.warn { border-left-color: #d97706; background: #fef3c7; }
.callout.warn .label { color: #b45309; }
.steps { margin: 14px 0; counter-reset: stepnum; }
.steps .step { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px dashed var(--border); }
.steps .step:last-child { border-bottom: none; }
.steps .step-num { flex-shrink: 0; width: 24px; height: 24px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; counter-increment: stepnum; }
.steps .step-num::before { content: counter(stepnum); }
.steps .step-body p { margin: 0 0 4px; }
.steps .step-body p.sub { font-size: 12.5px; color: var(--muted); line-height: 1.6; }
kbd { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; padding: 2px 6px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: 3px; color: var(--text); }
code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; padding: 1px 4px; background: var(--surface-alt); border-radius: 3px; color: var(--text); }
.foot { margin: 60px 0 20px; padding: 20px 0; border-top: 1px solid var(--border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-light); }
</style>

<div class="layout">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark"><div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;letter-spacing:-0.02em;">MD</div></div>
        <div class="brand-text">
            <div class="brand-title">Inspection Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#templates">Inspection Templates</a></li>
            <li><a href="#bubble">Bubble Drawings (Auto-Build)</a></li>
            <li><a href="#new">Starting an Inspection</a></li>
            <li><a href="#execute">Executing an Inspection</a></li>
            <li><a href="#qcrelease">QC Release on Approval</a></li>
            <li><a href="#results">Reading Results</a></li>
            <li><a href="#types">Inspection Types</a></li>
            <li><a href="#uoms">Units of Measure</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Quality control</div>
    <h1>Templates, executions, results &mdash; <strong>FAI without spreadsheets</strong>.</h1>
    <p class="lede">
        The Inspection module turns inspection plans (templates) into
        running inspections (executions) with measured values, pass /
        fail computation, and stored records. Templates can be hand-built
        or generated automatically from a Bubble drawing &mdash; the same
        bubbles you put on a drawing become the rows on the inspection
        sheet.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    Two core concepts: a <em>template</em> is the recipe (what to
    measure, what the spec is, what tool to use), and an
    <em>inspection</em> is the act (someone measures, records actuals,
    pass/fail computed). One template can spawn many inspections.
  </p>

  <h3>The lifecycle</h3>
  <table>
    <thead><tr><th style="width:22%">STAGE</th><th>WHAT HAPPENS</th></tr></thead>
    <tbody>
      <tr><td>Inspection created</td><td class="dim">Either manually (operator picks template + entity) or <strong>auto</strong> from Ship &amp; Receipt receipts and Process Inventory headers. Status: <em>draft</em>.</td></tr>
      <tr><td>Measurements recorded</td><td class="dim">Inspector measures, fills in actuals, optionally a verdict summary. On save, status becomes <em>in_progress</em>.</td></tr>
      <tr><td>Approved (two-person rule)</td><td class="dim">A different user approves with verdict: <em>passed</em>, <em>failed</em>, <em>rework</em>, <em>hold</em>, or <em>cancelled</em>. Inspector and approver cannot be the same person.</td></tr>
      <tr><td>QC release</td><td class="dim">For inspections linked to a +qty inv_txn, approval also <strong>moves the stock out of <code>LOC-QCH</code></strong> to a verdict-specific destination &mdash; see &sect;06.</td></tr>
    </tbody>
  </table>

  <h3>The sidebar entries</h3>
  <p>
    Under the Inspection group:
    <em>New Inspection</em> (start an inspection),
    <em>Pending</em> (inspections in progress &mdash; includes the
    auto-created QC queue),
    <em>Templates</em> (browse / create templates),
    <em>All inspections</em> (full list, including completed).
  </p>
</section>

<!-- ============ TEMPLATES ============ -->
<section class="module" id="templates">
  <h2><span class="num">02</span> Inspection Templates</h2>

  <p>
    A template is a reusable plan. Each row in a template defines one
    measurement: what feature, what nominal, what tolerance, what
    units, what gauge / method.
  </p>

  <h3>Template fields</h3>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>PURPOSE</th></tr></thead>
    <tbody>
      <tr><td>Code <strong>*</strong></td><td class="dim">Unique identifier (auto-generated <code>TPL-NNNNNN</code> or hand-entered). Stable, shouldn't change once issued.</td></tr>
      <tr><td>Name <strong>*</strong></td><td class="dim">Human-readable title &mdash; "Bracket assembly FAI", "PN-1234 incoming inspection".</td></tr>
      <tr><td>Inspection type</td><td class="dim">One of incoming / asset_cal / finished_goods / first_article / adhoc. Drives default behavior (see &sect;07).</td></tr>
      <tr><td>Linked entity</td><td class="dim">Optional link to an Inventory item or Asset. When set, inspections from this template auto-link to that entity.</td></tr>
      <tr><td>Description</td><td class="dim">Free text. Drawing rev, customer reference, any context the inspector needs.</td></tr>
      <tr><td>Rows</td><td class="dim">The measurements. One row per dimension. See below.</td></tr>
    </tbody>
  </table>

  <h3>Template row fields</h3>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>PURPOSE</th></tr></thead>
    <tbody>
      <tr><td>Bubble # / Item</td><td class="dim">Identifier for this row &mdash; bubble number from a drawing, or "1", "2", "3" sequence.</td></tr>
      <tr><td>Description</td><td class="dim">What's being measured. "Bore diameter", "Hole position from datum A", "Thread depth".</td></tr>
      <tr><td>Nominal</td><td class="dim">The target value from the drawing.</td></tr>
      <tr><td>Tolerance + / &minus;</td><td class="dim">Upper and lower deviation. Use 0 if unilateral.</td></tr>
      <tr><td>Unit</td><td class="dim">mm, in, deg, etc. Pulled from the UOM admin list.</td></tr>
      <tr><td>Instrument</td><td class="dim">FK to an Asset (typed picker, active assets only). What gauge / instrument the inspector should use &mdash; "Digital vernier A35", "Plunger Dial A274", "Slip gauge A17". Snapshotted onto each <code>inspection_results</code> row at seed time, so later instrument deactivations don't break historical IRs.</td></tr>
      <tr><td>Critical / KC flag</td><td class="dim">Marks safety-critical or customer-flagged characteristics. Highlighted on the inspection form.</td></tr>
    </tbody>
  </table>

  <h3>Creating a template manually</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Sidebar &rarr; Inspection &rarr; Templates</strong> &rarr; "+ New template".</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the header</strong> (code, name, type, optional linked entity, description).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Add rows</strong> one at a time, or paste a tab-separated block into the bulk-add box.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The template becomes available for inspections.</p>
      </div>
    </div>
  </div>

  <h3>Cloning / importing / exporting</h3>
  <p>
    Templates can be cloned (creates a copy with a fresh code),
    exported to CSV, and re-imported. Useful for templating across
    a part-number family &mdash; export the master, edit the rows,
    re-import as a new template.
  </p>
</section>

<!-- ============ BUBBLE ============ -->
<section class="module" id="bubble">
  <h2><span class="num">03</span> Bubble Drawings (Auto-Build Templates)</h2>

  <p>
    Building a template row-by-row for a 60-dimension drawing is
    tedious. The Inspection module integrates with the Bubble tool
    so a template can be built directly from a bubbled drawing.
  </p>

  <h3>The flow</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the template's edit view</strong> and click "Bubble drawing".</p>
        <p class="sub">The Bubble tool opens in a new tab with the template's drawing (if attached) pre-loaded.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Bubble the drawing</strong> in the Bubble tool &mdash; manually, or via Auto Bubble.</p>
        <p class="sub">Every bubble carries dimension data (type, nominal, tolerance, unit, notes, critical flag). These become template rows back in Inspection.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "Send to Inspection"</strong> in the Bubble tool.</p>
        <p class="sub">The bubbles export back to the template. Each bubble becomes one row with the bubble number as identifier, the parsed dimension as nominal+tol, the notes as method.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Review the imported rows</strong> in the template view. Adjust any that need refinement (gauge specifics, method elaboration).</p>
      </div>
    </div>
  </div>

  <div class="callout">
    <div class="label">SEE THE BUBBLE MANUAL</div>
    <p>For details on how Bubble works &mdash; Auto Bubble, GD&amp;T callouts, dual-engine extraction &mdash; see the Bubble manual. That's where the drawing markup happens; Inspection just consumes the output.</p>
  </div>
</section>

<!-- ============ NEW ============ -->
<section class="module" id="new">
  <h2><span class="num">04</span> Starting an Inspection</h2>

  <p>
    Once a template exists, starting an inspection takes two clicks
    &mdash; but most inspections aren't started manually. <strong>Ship
    &amp; Receipt receipts and Process Inventory headers auto-create a
    draft inspection</strong> against the resulting +qty txn. Those
    appear in <em>Pending</em> immediately and skip steps 1&ndash;4
    below. You only run the manual flow for ad-hoc, first-article, or
    asset-calibration inspections that don't trigger from an inventory
    event.
  </p>

  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Sidebar &rarr; Inspection &rarr; New Inspection</strong>.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a template</strong> from the dropdown. Use the type-ahead to filter.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Set inspection-instance fields:</strong> linked entity if not pre-filled (specific Inventory item lot or Asset), inspection date, inspector (defaults to you).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Start inspection.</strong> A new inspection record is created with all rows pre-populated from the template (N copies per parameter when sample count &gt; 1, one copy each otherwise). Internal id auto-generated as <code>INSP-NNNNNN</code>; printed IR document number generated in parallel as <code>IR.NNNNN</code>. Status: <em>draft</em>.</p>
      </div>
    </div>
  </div>

  <h3>IR document &amp; sampling block</h3>
  <p>
    For inspections that drive a <strong>printed IR document</strong>
    (typically <em>finished_goods</em> and <em>first_article</em> types),
    the new-inspection form has an extra fieldset titled
    "IR document &amp; sampling":
  </p>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>PURPOSE</th></tr></thead>
    <tbody>
      <tr><td>Job card</td><td class="dim">Search-as-you-type picker over <code>job_cards</code> (matches code, customer PO, or part). Once linked, <strong>PO no., PO line, and PDN qty are read live from the job card on every view</strong> &mdash; later corrections in Production flow through to the IR without re-saving the inspection.</td></tr>
      <tr><td>Sample columns *</td><td class="dim">How many sample parts the inspector will measure (1&ndash;60, default 1). This is the <code>S1..SN</code> column count on the printed IR. The inspection seeder generates <strong>N rows per template item</strong> at this count, so picking 27 creates 27&times; the parameter count of result rows.</td></tr>
      <tr><td>Checked qty</td><td class="dim">The "Chkd Qty" cell on the printed IR header. No sampling-plan lookup &mdash; ask the inspector. Often equals Sample columns; defaults blank.</td></tr>
    </tbody>
  </table>
  <p class="dim">
    Picking a job card auto-suggests Sample columns = PDN qty (only as
    a placeholder hint &mdash; it doesn't override an explicit entry).
    When the target entity is an inventory item, <strong>part no.,
    part rev, long description, and PID are snapshotted</strong> from
    <code>inv_items</code> onto the inspection so the printed IR
    survives later item edits or deletes.
  </p>
</section>

<!-- ============ EXECUTE ============ -->
<section class="module" id="execute">
  <h2><span class="num">05</span> Executing an Inspection</h2>

  <p>
    The execute view is a <strong>multi-sample grid</strong>: rows are
    parameters (one per template item), columns are the samples
    (<code>S1</code> through <code>SN</code> where N is the inspection's
    sample count). Each cell is a single text input where the inspector
    types the measured value for that parameter on that sample. The
    header row sticks to the top while scrolling vertically; the whole
    grid scrolls horizontally for IRs with many samples.
  </p>

  <h3>What you see in the grid</h3>
  <table>
    <thead><tr><th style="width:22%">COLUMN</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Bbl</td><td class="dim">Balloon number from the template. Matches the drawing.</td></tr>
      <tr><td>Parameter</td><td class="dim">From the template &mdash; the feature being measured. GD&amp;T symbol prepended when set.</td></tr>
      <tr><td>Nominal</td><td class="dim">Target value from the template.</td></tr>
      <tr><td>Tol &minus;/+</td><td class="dim">Negative and positive offsets from nominal. Used to compute Min / Max.</td></tr>
      <tr><td>Min / Max</td><td class="dim">Spec band derived as nominal &minus;tol / nominal +tol. Display only &mdash; not editable.</td></tr>
      <tr><td>UOM</td><td class="dim">From the template.</td></tr>
      <tr><td>Notes (instrument)</td><td class="dim">The instrument snapshotted from the template at seed time. Display only on the execute page.</td></tr>
      <tr><td>S1 &hellip; SN</td><td class="dim">YOUR INPUT &mdash; one cell per sample. Type the measured value (numeric, or text indicator like "OK" / "NOT OK[NG]").</td></tr>
    </tbody>
  </table>

  <h3>Note rows</h3>
  <p>
    Parameters with <code>check_type='text'</code> render as a
    <strong>full-width text input spanning all sample columns</strong>
    &mdash; the same text gets written to every sample's result row
    via mirrored hidden inputs, so the IR view shows the note
    identically regardless of which sample column it pulls from.
    Used for free-text notes like "Note 1: Masking prior to chrome
    plating" that the printed IR carries between measurement rows.
  </p>

  <h3>Per-sample remarks footer</h3>
  <p>
    Below the grid is a one-row footer with N text inputs &mdash; one
    per sample column &mdash; prefilled with "Accepted". This is the
    "Remarks" row on the printed IR. On submit, sparse entries are
    <code>json_encode</code>d into <code>inspections.sample_remarks_json</code>
    (empty cells are stripped, an empty map becomes <code>NULL</code>).
  </p>

  <h3>Auto pass/fail (no per-cell verdict input)</h3>
  <p>
    The grid omits the per-cell verdict dropdown and per-cell notes
    input from the legacy single-sample UI &mdash; both would be
    untenable for 1,000-cell IRs. Pass/fail is <strong>computed
    server-side on save</strong>:
  </p>
  <ul>
    <li>Numeric value within [min, max] &rarr; <strong>pass</strong></li>
    <li>Numeric value outside the band &rarr; <strong>fail</strong></li>
    <li>Text "OK" / "pass" &rarr; <strong>pass</strong></li>
    <li>Text starting "NOT OK" or containing "NG" &rarr; <strong>fail</strong></li>
    <li>Anything else recognised text-wise &rarr; <em>na</em></li>
    <li>Empty cell &rarr; stays <em>pending</em> (so unmeasured cells stay flagged in yellow rather than silently mass-marked N/A)</li>
  </ul>

  <h3>Saving progress vs submitting</h3>
  <table>
    <thead><tr><th style="width:24%">ACTION</th><th>EFFECT</th></tr></thead>
    <tbody>
      <tr><td>Save (no submit)</td><td class="dim">Persists any actuals entered so far. Status moves to <em>in_progress</em>. You can come back and edit until approval.</td></tr>
      <tr><td>Submit / Save results</td><td class="dim">Saves and flags the inspection as ready for approval. Status stays <em>in_progress</em>; you've handed it to the approver.</td></tr>
      <tr><td>Approve (different user)</td><td class="dim">A second user applies a verdict &mdash; passed, failed, rework, hold, or cancelled. The two-person rule blocks the inspector from approving their own work.</td></tr>
    </tbody>
  </table>

  <div class="callout warn">
    <div class="label">APPROVAL LOCKS THE RECORD</div>
    <p>Once approved with a final verdict (passed / failed / rework / cancelled), the actuals can no longer be edited. If a measurement was wrong, the right correction is a new inspection (with notes referencing the original) &mdash; not silently retroactively editing the record. This preserves audit integrity. <em>hold</em> verdicts can be re-opened later for further review.</p>
  </div>
</section>

<!-- ============ QC RELEASE ============ -->
<section class="module" id="qcrelease">
  <h2><span class="num">06</span> QC Release on Approval</h2>

  <p>
    When an inspection is linked to a +qty inventory transaction
    (i.e. created automatically from Ship &amp; Receipt or Process
    Inventory), approval doesn't just stamp a verdict on the record
    &mdash; it <strong>also moves the stock out of <code>LOC-QCH</code></strong>
    to a destination chosen by the verdict and the source txn type.
    The move is atomic: it commits together with the status change so
    you can't get a half-state where the inspection is approved but
    the stock is still in QC.
  </p>

  <h3>The QC process flow</h3>
  <p>
    End-to-end view of where stock goes from the moment it enters
    MagDyn through final disposition:
  </p>

  <figure style="margin:18px 0;text-align:center;">
    <svg viewBox="0 0 940 660" xmlns="http://www.w3.org/2000/svg"
         style="max-width:100%;height:auto;font-family:inherit;" role="img"
         aria-label="QC process flow from material entry through inspection and verdict routing">
      <defs>
        <marker id="qcArrow" viewBox="0 0 10 10" refX="9" refY="5"
                markerWidth="6" markerHeight="6" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#475569"/>
        </marker>
        <style>
          .qcbox  { fill: #ffffff; stroke: #475569; stroke-width: 1.5; }
          .qclab  { font-size: 12px; font-weight: 600; fill: #0f172a; }
          .qcsub  { font-size: 10px; fill: #64748b; }
          .qcline { stroke: #475569; stroke-width: 1.5; fill: none; }
          .qcdash { stroke: #94a3b8; stroke-width: 1.2; fill: none; stroke-dasharray: 4 3; }
          .qcsrc  { fill: #eff6ff; stroke: #3b82f6; }
          .qcsrct { fill: #1e3a8a; }
          .qchold { fill: #fef3c7; stroke: #d97706; }
          .qcholdt{ fill: #78350f; }
          .qcvdct { fill: #f1f5f9; stroke: #475569; }
          .qcok   { fill: #d1fae5; stroke: #059669; }
          .qcokt  { fill: #064e3b; }
          .qcbad  { fill: #fee2e2; stroke: #dc2626; }
          .qcbadt { fill: #7f1d1d; }
          .qcrw   { fill: #ede9fe; stroke: #7c3aed; }
          .qcrwt  { fill: #4c1d95; }
        </style>
      </defs>

      <!-- ====================== ROW 1 — Source events ====================== -->
      <text x="20" y="22" class="qclab" fill="#475569">1. Material enters</text>

      <rect class="qcbox qcsrc" x="80"  y="35" width="220" height="56" rx="6"/>
      <text x="190" y="58" text-anchor="middle" class="qclab qcsrct">Ship &amp; Receipt — receipt event</text>
      <text x="190" y="76" text-anchor="middle" class="qcsub">posts a <tspan font-family="monospace">ship_in</tspan> txn (+qty)</text>

      <rect class="qcbox qcsrc" x="360" y="35" width="220" height="56" rx="6"/>
      <text x="470" y="58" text-anchor="middle" class="qclab qcsrct">Process Inventory</text>
      <text x="470" y="76" text-anchor="middle" class="qcsub">posts a <tspan font-family="monospace">process</tspan> txn (+qty parent)</text>

      <rect class="qcbox qcsrc" x="640" y="35" width="220" height="56" rx="6"/>
      <text x="750" y="58" text-anchor="middle" class="qclab qcsrct">Manual <tspan font-family="monospace">receive</tspan> on Ledger</text>
      <text x="750" y="76" text-anchor="middle" class="qcsub">+qty incoming, ad-hoc</text>

      <!-- Converging lines into row 2 -->
      <path class="qcline" d="M 190 91  L 190 140 L 470 140 L 470 158" marker-end="url(#qcArrow)"/>
      <path class="qcline" d="M 470 91  L 470 158" marker-end="url(#qcArrow)"/>
      <path class="qcline" d="M 750 91  L 750 140 L 470 140 L 470 158" marker-end="url(#qcArrow)"/>

      <!-- ====================== ROW 2 — LOC-QCH ====================== -->
      <text x="20" y="180" class="qclab" fill="#475569">2. Stock lands at LOC-QCH</text>

      <rect class="qcbox qchold" x="320" y="160" width="300" height="64" rx="6"/>
      <text x="470" y="184" text-anchor="middle" class="qclab qcholdt">LOC-QCH (Quality Check Hold)</text>
      <text x="470" y="202" text-anchor="middle" class="qcsub qcholdt">+ a draft inspection is auto-created</text>
      <text x="470" y="216" text-anchor="middle" class="qcsub qcholdt">against the +qty txn</text>

      <path class="qcline" d="M 470 224 L 470 268" marker-end="url(#qcArrow)"/>

      <!-- ====================== ROW 3 — Inspection lifecycle ====================== -->
      <text x="20" y="290" class="qclab" fill="#475569">3. Inspection lifecycle</text>

      <rect class="qcbox" x="320" y="270" width="300" height="72" rx="6"/>
      <text x="470" y="292" text-anchor="middle" class="qclab">draft → in_progress → approved</text>
      <text x="470" y="308" text-anchor="middle" class="qcsub">inspector records measurements (Execute)</text>
      <text x="470" y="322" text-anchor="middle" class="qcsub">approver applies verdict</text>
      <text x="470" y="336" text-anchor="middle" class="qcsub" fill="#7c3aed">two-person rule: inspector ≠ approver</text>

      <path class="qcline" d="M 470 342 L 470 380" marker-end="url(#qcArrow)"/>

      <!-- Diamond decision node -->
      <polygon class="qcbox qcvdct" points="470,380 590,420 470,460 350,420"/>
      <text x="470" y="416" text-anchor="middle" class="qclab">Verdict?</text>
      <text x="470" y="432" text-anchor="middle" class="qcsub">approver picks one</text>

      <!-- ====================== ROW 4 — Outcomes ====================== -->
      <text x="20" y="500" class="qclab" fill="#475569">4. Stock routes</text>

      <!-- PASSED -->
      <path class="qcline" d="M 365 415 L 90 415 L 90 500" marker-end="url(#qcArrow)"/>
      <text x="225" y="408" class="qcsub" fill="#059669">passed</text>
      <rect class="qcbox qcok" x="20" y="500" width="140" height="56" rx="6"/>
      <text x="90" y="522" text-anchor="middle" class="qclab qcokt">ST-HLD</text>
      <text x="90" y="540" text-anchor="middle" class="qcsub qcokt">store team Moves to shelf</text>

      <!-- FAILED -->
      <path class="qcline" d="M 405 445 L 250 500" marker-end="url(#qcArrow)"/>
      <text x="305" y="475" class="qcsub" fill="#dc2626">failed</text>
      <rect class="qcbox qcbad" x="180" y="500" width="140" height="56" rx="6"/>
      <text x="250" y="522" text-anchor="middle" class="qclab qcbadt">LOC-REJ</text>
      <text x="250" y="540" text-anchor="middle" class="qcsub qcbadt">RTV or scrap</text>

      <!-- REWORK — splits into two -->
      <path class="qcline" d="M 470 462 L 470 488" marker-end="url(#qcArrow)"/>
      <text x="485" y="478" class="qcsub" fill="#7c3aed">rework</text>
      <text x="485" y="490" class="qcsub" fill="#7c3aed">(approver picks)</text>
      <rect class="qcbox qcrw" x="365" y="488" width="100" height="56" rx="6"/>
      <text x="415" y="510" text-anchor="middle" class="qclab qcrwt">O-Rework</text>
      <text x="415" y="528" text-anchor="middle" class="qcsub qcrwt">vendor returns</text>
      <rect class="qcbox qcrw" x="475" y="488" width="100" height="56" rx="6"/>
      <text x="525" y="510" text-anchor="middle" class="qclab qcrwt">I-Rework</text>
      <text x="525" y="528" text-anchor="middle" class="qcsub qcrwt">fix in-house</text>

      <!-- HOLD -->
      <path class="qcline" d="M 535 445 L 700 500" marker-end="url(#qcArrow)"/>
      <text x="600" y="475" class="qcsub" fill="#475569">hold</text>
      <rect class="qcbox" x="610" y="500" width="160" height="56" rx="6"/>
      <text x="690" y="522" text-anchor="middle" class="qclab">stays at LOC-QCH</text>
      <text x="690" y="540" text-anchor="middle" class="qcsub">await re-approval</text>

      <!-- CANCELLED -->
      <path class="qcline" d="M 575 415 L 850 415 L 850 500" marker-end="url(#qcArrow)"/>
      <text x="710" y="408" class="qcsub" fill="#475569">cancelled</text>
      <rect class="qcbox" x="790" y="500" width="130" height="56" rx="6"/>
      <text x="855" y="522" text-anchor="middle" class="qclab">no movement</text>
      <text x="855" y="540" text-anchor="middle" class="qcsub">manual handling</text>

      <!-- Rework feedback loop — I-Rework cycles back through Process -->
      <path class="qcdash" d="M 525 544 L 525 600 L 470 600 L 470 91" marker-end="url(#qcArrow)"/>
      <text x="540" y="620" class="qcsub" fill="#7c3aed">
        I-Rework re-enters via Process Inventory
      </text>
      <text x="540" y="632" class="qcsub" fill="#7c3aed">
        (Rework checkbox auto-ticks when product has I-Rework stock)
      </text>
    </svg>
    <figcaption class="muted" style="font-size:11.5px;margin-top:6px;">
      QC process flow. Solid arrows are automatic on submit/approve;
      dashed arrow is the rework feedback loop a human triggers via
      Process Inventory.
    </figcaption>
  </figure>

  <h3>Where the stock goes</h3>
  <table>
    <thead><tr><th style="width:18%">VERDICT</th><th style="width:30%">DESTINATION</th><th>WHY</th></tr></thead>
    <tbody>
      <tr><td><strong>passed</strong></td><td class="dim"><code>ST-HLD</code> (Store Hold)</td><td class="dim">The lot is good. The store team picks it up from ST-HLD and routes it to its final shelf using the Move action (see Inventory manual &sect;05).</td></tr>
      <tr><td><strong>failed</strong></td><td class="dim"><code>LOC-REJ</code></td><td class="dim">Hard reject. Stays in quarantine pending RTV (return-to-vendor) or scrap.</td></tr>
      <tr><td><strong>rework</strong></td><td class="dim"><code>O-Rework</code> or <code>I-Rework</code> (approver picks)</td><td class="dim">When the approver clicks the <em>Rework</em> button, a chooser pops up: O-Rework (send back to the vendor &mdash; typical for ship_in / receive failures) or I-Rework (fix in-house &mdash; typical for process failures). The approver decides per inspection.</td></tr>
      <tr><td><strong>hold</strong></td><td class="dim">Stays at <code>LOC-QCH</code></td><td class="dim">Inspection on pause; the lot waits in QC until you re-approve with a final verdict.</td></tr>
      <tr><td><strong>cancelled</strong></td><td class="dim">Stays at <code>LOC-QCH</code></td><td class="dim">No stock movement. The inspection is voided; the lot is still in QC and needs a follow-up inspection or manual handling.</td></tr>
    </tbody>
  </table>

  <h3>What you see after approval</h3>
  <p>
    The inspection view page gains a <em>QC release</em> row showing
    where the stock was moved to, by whom, and when. The
    <em>Transaction history</em> in the Inventory module shows two
    paired <code>move</code> rows (out of LOC-QCH, into the destination)
    referencing the inspection code in the notes.
  </p>

  <div class="callout warn">
    <div class="label">SOURCE TXN MUST STILL BE AT LOC-QCH</div>
    <p>If somebody manually moved the qty out of <code>LOC-QCH</code>
       before the inspection was approved (e.g. a hasty Move), the
       release step refuses to fire on approval &mdash; it can't know
       which location to subtract from. The inspection still gets
       approved, but stock has to be reconciled by hand. Don't move
       stock out of LOC-QCH before approval.</p>
  </div>

  <h3>What about non-inv_txn inspections?</h3>
  <p>
    Inspections linked to an asset (asset_cal) or to an inventory
    <em>item</em> (rather than a specific txn), or standalone
    inspections, don't trigger a release move &mdash; there's no
    specific qty at LOC-QCH to route. The verdict still records
    normally; just no stock changes hands.
  </p>
</section>

<!-- ============ RESULTS ============ -->
<section class="module" id="results">
  <h2><span class="num">07</span> Reading Results</h2>

  <p>
    A completed inspection's view page shows three blocks: the
    <strong>IR document header card</strong> (only rendered when the
    inspection has any IR data &mdash; older pre-migration records
    skip it cleanly), the workflow grid (status, target, planned /
    inspected / approved by, QC release), and the <strong>multi-sample
    results grid</strong> matching the printed IR layout.
  </p>

  <h3>IR document header card</h3>
  <table>
    <thead><tr><th style="width:24%">CELL</th><th>SOURCE</th></tr></thead>
    <tbody>
      <tr><td>IR no.</td><td class="dim"><code>inspections.ir_no</code> (printed doc number) prominent, internal <code>code</code> (INSP-NNNNNN) as a small subtitle.</td></tr>
      <tr><td>Inspection date / by</td><td class="dim">Date portion of <code>inspected_at</code> plus the inspector's joined name from <code>users</code>.</td></tr>
      <tr><td>Part no. / rev / PID / description</td><td class="dim">SNAPSHOT from <code>inv_items</code> at creation time. Stays correct even if the inv_items row is later edited or deleted.</td></tr>
      <tr><td>Drawing no. / rev</td><td class="dim"><strong>Live</strong> from <code>inv_items.dwg_no</code> / <code>dwg_rev_no</code>. Re-revisioning the drawing in inventory updates every linked IR's display.</td></tr>
      <tr><td>Customer PO / line</td><td class="dim"><strong>Live</strong> from <code>job_cards.po_no</code> / <code>line_no</code> via the inspection's <code>job_card_id</code> link. PO corrections in Production flow through.</td></tr>
      <tr><td>PDN qty</td><td class="dim"><strong>Live</strong> from <code>job_cards.pdn_qty</code>.</td></tr>
      <tr><td>Chkd qty</td><td class="dim">Stored on the inspection (manual entry from the new-inspection form).</td></tr>
      <tr><td>Accepted qty</td><td class="dim">Equals PDN qty by current business rule (overall reject / partial reject reflected via the status pill, not by mutating the printed Accepted figure).</td></tr>
    </tbody>
  </table>

  <h3>Multi-sample grid</h3>
  <p>
    Same layout as the execute UI but read-only and pass/fail-coloured:
  </p>
  <ul>
    <li><strong>Green</strong> cell &mdash; passed</li>
    <li><strong>Red</strong> cell (bold dark-red text) &mdash; failed</li>
    <li><strong>Amber</strong> cell &mdash; has a value but pass/fail is still <em>pending</em> (shouldn't normally happen post-execute)</li>
    <li>No colour &mdash; empty cell or <em>na</em></li>
  </ul>
  <p>
    Colours reflect <strong>stored</strong> <code>inspection_results.pass_fail</code>
    (set at execute time by the auto-evaluator), not a re-computation at render time.
    So if an approver manually overrode a verdict, the cell shows the override.
  </p>
  <p>
    Note rows render as a full-width italic span with the note text
    (pulled from sample 1's <code>measured_value</code>, which the
    execute UI mirrors to every sample column). Below the body, a
    <strong>per-sample remarks footer</strong> shows the decoded
    <code>sample_remarks_json</code> map &mdash; one cell per sample
    with the inspector's "Accepted" / other remark.
  </p>

  <h3>Overall pass/fail</h3>
  <p>
    The inspection's overall result is FAIL if any row failed, PASS
    otherwise. Critical-row failures still count as one failure (no
    automatic escalation in the module &mdash; that's up to your
    process), but the row is flagged with a red flag plus red pill.
  </p>

  <h3>Filtering all inspections</h3>
  <p>
    Sidebar &rarr; <em>All inspections</em> shows every inspection
    with filters for: status (pending / complete), result (pass /
    fail), inspection type, date range, inspector, linked entity.
    The list page also surfaces <strong>IR&nbsp;#</strong> as the
    first column (with internal code as subtitle) and a
    <strong>Part&nbsp;/&nbsp;PO</strong> column that combines the part
    identity with the customer PO from job_cards &mdash; search
    matches part code, part name, or PO number, so the inspector
    can find a record by any of them.
  </p>
</section>

<!-- ============ TYPES ============ -->
<section class="module" id="types">
  <h2><span class="num">08</span> Inspection Types</h2>

  <table>
    <thead><tr><th style="width:22%">TYPE</th><th>USE FOR</th></tr></thead>
    <tbody>
      <tr><td>Incoming</td><td class="dim">Inspecting material received from a vendor. Often paired with a Ship &amp; Receipt receipt event &mdash; one inspection per delivered lot.</td></tr>
      <tr><td>Asset calibration</td><td class="dim">Calibration check of an asset. The linked entity is the asset. Common workflow: receive_vendor &rarr; asset_cal inspection &rarr; calibrate transaction.</td></tr>
      <tr><td>Finished goods</td><td class="dim">Final inspection before ship-out to customer. Often a customer-specific template. <strong>The primary IR multi-sample workflow driver</strong> &mdash; pair with a job card link, a sample count matching the lot, and the inspector fills the S1..SN grid.</td></tr>
      <tr><td>First article</td><td class="dim">FAI per AS9102 or similar. Done once per part-number + drawing rev. Comprehensive &mdash; every toleranced feature.</td></tr>
      <tr><td>Adhoc</td><td class="dim">Anything that doesn't fit the above &mdash; in-process spot check, audit, vendor evaluation. Default for new templates.</td></tr>
    </tbody>
  </table>

  <p>
    The type is informational &mdash; it doesn't change the inspection
    flow, but it lets you filter / report by type. Pick the right
    type when creating templates; you'll thank yourself when running
    QC summaries six months later.
  </p>
</section>

<!-- ============ UOMS ============ -->
<section class="module" id="uoms">
  <h2><span class="num">09</span> Units of Measure</h2>

  <p>
    UOMs (units of measure) are a shared lookup managed in Admin &rarr;
    Inspection UOMs. Each UOM has a code (mm, in, deg, kg, etc.), a
    symbol, and an optional description.
  </p>

  <h3>When to add a new UOM</h3>
  <p>
    Most shops never need to. The default set covers length, mass,
    angle, force, pressure, and a handful of others. Add a new UOM
    only when the existing list genuinely doesn't include what your
    measurement is in &mdash; e.g., specialty units like Brinell
    hardness (HB), Rockwell C (HRC), or Mooney viscosity (MU).
  </p>

  <h3>UOM conversion</h3>
  <p>
    The module does NOT auto-convert between UOMs. A template row in
    mm stays in mm; if your gauge reads inches, convert before
    entering. The Engineering Calculator's Unit Converter is one
    click away if you need it.
  </p>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">10</span> Common Workflows</h2>

  <h3>First Article Inspection (FAI / AS9102)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Build the template from the customer drawing. Use Bubble (&sect;03) for speed &mdash; Auto Bubble can capture 60+ dimensions in seconds.</li>
    <li>Type the template: <em>First article</em>.</li>
    <li>Receive the first article parts. Start a new inspection from the template, link it to the part-number lot if the entity exists.</li>
    <li>Walk every dimension, record actuals, mark critical features carefully.</li>
    <li>Have a second user approve. Verdict drives the QC release (&sect;06).</li>
  </ol>

  <h3>Incoming inspection on a vendor delivery (auto-created)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Record the Ship &amp; Receipt receipt event (Inventory manual &sect;06). It lands at <code>LOC-QCH</code> and the system auto-creates a draft inspection &mdash; you'll see it in <em>Inspection &rarr; Pending</em>.</li>
    <li>Open the pending inspection. If you've defined an incoming template for that item, link it from the inspection's edit view; otherwise inspect ad-hoc and add rows.</li>
    <li>Inspect the sample size your AQL requires (Engineering Calculator &rarr; IS 2500 or ISO 2859-1 if you need a lookup). Record actuals, save.</li>
    <li>A second user approves with verdict:
      <ul style="margin-top:4px;">
        <li><em>passed</em> &rarr; qty auto-moves to <code>ST-HLD</code>; store team takes it onward via Move.</li>
        <li><em>failed</em> &rarr; qty auto-moves to <code>LOC-REJ</code> for RTV / scrap.</li>
        <li><em>rework</em> &rarr; chooser opens; approver typically picks <code>O-Rework</code> (back to vendor) for incoming material, but may pick <code>I-Rework</code> if your team plans to fix the lot in-house instead.</li>
      </ul>
    </li>
  </ol>

  <h3>Inspecting Process Inventory output (auto-created)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Operator runs Process Inventory (Inventory manual &sect;05). The parent qty lands at <code>LOC-QCH</code> and a draft inspection appears in <em>Pending</em>.</li>
    <li>Open the inspection. Link a finished-goods template if you have one for this assembly.</li>
    <li>Inspect, record actuals, save.</li>
    <li>A second user approves:
      <ul style="margin-top:4px;">
        <li><em>passed</em> &rarr; <code>ST-HLD</code> (store team moves to shelf).</li>
        <li><em>failed</em> &rarr; <code>LOC-REJ</code>.</li>
        <li><em>rework</em> &rarr; chooser opens; approver typically picks <code>I-Rework</code> for in-house production fixes. When the operator next re-runs Process Inventory for that product, the Rework checkbox <strong>auto-ticks</strong> because there's stock at I-Rework, pulling the unit from I-Rework back through QC.</li>
      </ul>
    </li>
  </ol>

  <h3>Routine asset calibration</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>The asset comes back from the calibration vendor.</li>
    <li>Start an asset_cal inspection from the template you use for that asset model. Linked entity is the asset (not an inv_txn), so no QC release move happens &mdash; just a verdict on the record.</li>
    <li>Verify the calibration certificate values against your template's tolerances. Record actuals.</li>
    <li>If PASS, perform the asset's <code>calibrate</code> transaction (see Assets manual &sect;06) to update the asset's next-due date.</li>
    <li>If FAIL, send the asset back to the vendor with a complaint or quarantine and investigate.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">11</span> Troubleshooting</h2>

  <h3>I can't submit an inspection &mdash; says "rows are missing actuals"</h3>
  <p>
    Every template row needs an actual value before the inspection
    can be marked complete. Find the empty ones, fill them in. If a
    row is genuinely not applicable, enter a marker value and explain
    in the notes (or split the template into multiple smaller ones
    so rows are always applicable).
  </p>

  <h3>The pass/fail computation says PASS but the value is out of the band</h3>
  <p>
    Check the units &mdash; if the template says mm and you typed an
    inch value, the computation is technically right for the value
    you typed. The module doesn't auto-convert UOMs. Edit the actual
    after converting (or before submitting, if still pending).
  </p>

  <h3>Bubble drawing exported but the rows look wrong</h3>
  <p>
    The Bubble tool's dimension parser handles most callouts but can
    misread unusual notations. Review every imported row before
    relying on them. The raw text from each bubble is preserved in
    the template row &mdash; you can see what Bubble actually saw
    and fix the parsed values manually.
  </p>

  <h3>Template list is huge &mdash; how do I find the right one?</h3>
  <p>
    Use the search box at the top of the template list. It searches
    code, name, description, AND linked-entity name. For frequent
    inspections, consider cloning into named variants ("FAI Template
    &ndash; Bracket V3") so the active templates are obvious.
  </p>

  <h3>I marked the wrong verdict at approval &mdash; how do I unlock it?</h3>
  <p>
    You can't. The verdict and the QC release move are a one-way door
    by design. The right correction is to start a new inspection on
    the same entity with notes referencing the wrong-approved one,
    and use the Move action to put the stock back where it belongs
    (with a note explaining the correction). The audit trail captures
    what happened.
  </p>

  <h3>An auto-created inspection shows up against a txn I don't recognise</h3>
  <p>
    Open the inspection and check the Target column &mdash; it shows
    the item code and the qty of the linked +qty txn. Click through
    to the ledger to see exactly which receipt or process event
    triggered it. If the txn was wrong (e.g. somebody hit Process by
    mistake), approve the inspection with verdict <em>cancelled</em>;
    the qty stays at <code>LOC-QCH</code> and you can sort out the
    ledger separately.
  </p>
</section>

<div class="foot">
    <div>Inspection &middot; Operator Manual &middot; v1.0</div>
    <div>Part of the MagDyn module suite</div>
</div>

</main>

</div>

<script>
    const links = document.querySelectorAll('nav.toc a');
    const sections = Array.from(links).map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.id;
                links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });
    sections.forEach(s => observer.observe(s));
</script>

<?php include 'includes/footer.php'; ?>
