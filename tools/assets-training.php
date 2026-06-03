<?php
// MagDyn integration: require login to access this manual. The
// bootstrap resolves to the parent app dir so this works whether
// the file is loaded directly or via the training-course iframe.
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Assets · Operator Manual';
$current_page  = 'assets-training.php';
$trigger_style = 'dark';
$cdn_scripts   = [];
include 'includes/head.php';
?>
<style>
/* ============ TRAINING DOC — MagDyn ============
   Same scaffolding as the other module manuals (bubble, calc) for
   visual consistency. Self-contained CSS so the standalone PHP file
   renders correctly when iframed.
 ============================================== */
:root { --muted: var(--text-muted); }

body { overflow-x: hidden; }
.layout { min-height: 100vh; }

.sidebar { height: 100vh; position: sticky; top: 0; overflow-y: auto; }
.toc-heading {
    padding: 14px 16px 6px;
    font-size: 10px; color: var(--sidebar-text-very-dim);
    text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;
}
.toc ol { list-style: none; padding: 4px 8px 16px; margin: 0; counter-reset: tocsec; }
.toc ol li { counter-increment: tocsec; }
.toc ol li a {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 12px; margin: 2px 0;
    color: var(--sidebar-text);
    font-size: 13px; border-radius: 6px;
    text-decoration: none;
    transition: background 0.12s, color 0.12s;
}
.toc ol li a::before {
    content: counter(tocsec, decimal-leading-zero);
    font-size: 10px; font-weight: 700;
    color: var(--sidebar-text-very-dim);
    letter-spacing: 0.05em;
    flex-shrink: 0; width: 18px;
}
.toc ol li a:hover { background: var(--sidebar-bg-hover); color: white; text-decoration: none; }
.toc ol li a:hover::before { color: rgba(255,255,255,0.6); }
.toc ol li a.active { background: var(--sidebar-bg-active); color: white; }
.toc ol li a.active::before { color: rgba(255,255,255,0.6); }

.main { padding: 32px 40px 60px; max-width: 880px; }

.hero { margin-bottom: 36px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
.hero .eyebrow {
    font-size: 11px; color: var(--primary);
    letter-spacing: 0.12em; text-transform: uppercase;
    font-weight: 700; margin-bottom: 12px;
}
.hero h1 {
    font-size: 32px; font-weight: 600;
    letter-spacing: -0.02em;
    margin: 0 0 14px;
    color: var(--text);
}
.hero h1 strong { color: var(--primary); font-weight: 700; }
.hero .lede { font-size: 15px; line-height: 1.7; color: var(--muted); max-width: 720px; }

section.module { margin: 0 0 48px; padding-top: 8px; }
section.module h2 {
    font-size: 22px; font-weight: 600;
    margin: 0 0 14px;
    display: flex; align-items: baseline; gap: 14px;
}
section.module h2 .num {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 13px; font-weight: 700;
    color: var(--text-light);
    letter-spacing: 0.05em;
    background: var(--surface-alt);
    padding: 4px 8px; border-radius: 4px;
}
section.module h3 {
    font-size: 15px; font-weight: 600;
    margin: 22px 0 10px;
    color: var(--text);
}
section.module h4 {
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.07em;
    color: var(--text-light);
    margin: 18px 0 8px;
}
section.module p { line-height: 1.7; color: var(--text); margin-bottom: 12px; }
section.module p.dim { color: var(--muted); font-size: 13.5px; }

table {
    width: 100%; border-collapse: collapse;
    margin: 12px 0; font-size: 13px;
}
table th, table td {
    text-align: left;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
table th {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--text-light);
    background: var(--surface-alt);
}
table td.dim { color: var(--muted); }
table td.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

.callout {
    margin: 16px 0;
    padding: 12px 14px;
    border-left: 3px solid var(--primary);
    background: var(--surface-alt);
    border-radius: 4px;
}
.callout .label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--primary);
    margin-bottom: 6px;
}
.callout p { margin: 0; font-size: 13.5px; line-height: 1.6; }
.callout.note { border-left-color: #6b7280; }
.callout.note .label { color: #6b7280; }
.callout.warn { border-left-color: #d97706; background: #fef3c7; }
.callout.warn .label { color: #b45309; }

.terminal {
    background: #1a1f2e;
    color: #cbd5e1;
    padding: 14px 18px;
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12.5px;
    line-height: 1.6;
    margin: 12px 0;
    white-space: pre-wrap;
}
.terminal .prompt { color: #94a3b8; display: block; margin-top: 4px; font-size: 11px; }
.terminal .out { color: #e2e8f0; display: block; padding-left: 4px; }

.steps { margin: 14px 0; }
.steps .step {
    display: flex; gap: 14px;
    padding: 12px 0;
    border-bottom: 1px dashed var(--border);
}
.steps .step:last-child { border-bottom: none; }
.steps .step-num {
    flex-shrink: 0;
    width: 24px; height: 24px;
    background: var(--primary); color: white;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    counter-increment: stepnum;
}
.steps .step-num::before { content: counter(stepnum); }
.steps { counter-reset: stepnum; }
.steps .step-body p { margin: 0 0 4px; }
.steps .step-body p.sub { font-size: 12.5px; color: var(--muted); line-height: 1.6; }

kbd {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    padding: 2px 6px;
    background: var(--surface-alt);
    border: 1px solid var(--border);
    border-radius: 3px;
    color: var(--text);
}
code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12.5px;
    padding: 1px 4px;
    background: var(--surface-alt);
    border-radius: 3px;
    color: var(--text);
}
.foot {
    margin: 60px 0 20px;
    padding: 20px 0;
    border-top: 1px solid var(--border);
    display: flex; justify-content: space-between;
    font-size: 11.5px; color: var(--text-light);
}
</style>

<div class="layout">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark">
            <div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;letter-spacing:-0.02em;">MD</div>
        </div>
        <div class="brand-text">
            <div class="brand-title">Assets Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#models">Asset Models</a></li>
            <li><a href="#register">Registering an Asset</a></li>
            <li><a href="#view">The Asset View</a></li>
            <li><a href="#transactions">Transactions</a></li>
            <li><a href="#calibration">Calibration Tracking</a></li>
            <li><a href="#subassets">Sub-assets &amp; Locking</a></li>
            <li><a href="#history">Transaction History</a></li>
            <li><a href="#import">Bulk Import</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Asset register</div>
    <h1>Track every measurement tool, every move, <strong>every calibration</strong>.</h1>
    <p class="lede">
        The Assets module is the canonical register for every piece of
        physical equipment in your shop &mdash; gauges, fixtures, jigs,
        calibration masters, instruments. Each asset carries a tag,
        a model classification, a location, and a complete history of
        every move, vendor send-out, user issue, and calibration event.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    An <em>asset</em> is one physical thing &mdash; one micrometer, one
    pin gauge, one fixture. Each asset has a unique tag (e.g.
    <code>ASSET-00042</code>) and inherits common attributes from its
    <em>model</em> (e.g. "Mitutoyo 293-340 0&ndash;1 inch micrometer").
    Multiple assets can share a model; one asset belongs to exactly one
    model.
  </p>

  <h3>What the module tracks</h3>
  <table>
    <thead>
      <tr><th style="width:30%">FIELD</th><th>PURPOSE</th></tr>
    </thead>
    <tbody>
      <tr><td>Asset tag</td><td class="dim">Unique stable identifier, auto-generated by default (<code>ASSET-NNNNN</code>) or hand-entered. Prefix and pad-width are configurable in Code Sequences (Admin).</td></tr>
      <tr><td>Model</td><td class="dim">The asset's classification &mdash; manufacturer, model number, type, typical specs. Models are pre-registered before assets reference them.</td></tr>
      <tr><td>Location</td><td class="dim">Where the asset currently lives. Locations are hierarchical (building &rarr; room &rarr; shelf).</td></tr>
      <tr><td>Status</td><td class="dim">One of <code>active</code>, <code>archived</code>, <code>with_vendor</code> (out for calibration / repair), or <code>with_user</code> (issued to a person).</td></tr>
      <tr><td>Calibration</td><td class="dim">Last-calibrated date and next-due date. Drives the calibration dashboard and overdue alerts.</td></tr>
      <tr><td>Parent asset</td><td class="dim">For sub-assets &mdash; e.g. a pin gauge that lives inside a master pin set. The parent's location can lock the child's location.</td></tr>
      <tr><td>Lookup fields</td><td class="dim">Alias ID, Frequency, Engraved ID, Calibration ID, Checked-OK ID. Free-form admin-configurable lookup tables for company-specific bookkeeping.</td></tr>
      <tr><td>Notes &amp; PID</td><td class="dim">Free text notes plus a "Used in PID" field for assets tied to a specific process or instrument loop.</td></tr>
    </tbody>
  </table>

  <h3>Who can do what</h3>
  <table>
    <thead><tr><th style="width:24%">PERMISSION</th><th>WHAT IT GATES</th></tr></thead>
    <tbody>
      <tr><td><code>asset.view</code></td><td class="dim">See the asset list, view individual asset details, see transaction history.</td></tr>
      <tr><td><code>asset.manage</code></td><td class="dim">Create / edit assets, perform transactions (move, send, receive, calibrate, archive).</td></tr>
      <tr><td><code>asset.delete</code></td><td class="dim">Hard-delete an asset row. Distinct from archive &mdash; delete is irreversible and loses history.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ MODELS ============ -->
<section class="module" id="models">
  <h2><span class="num">02</span> Asset Models</h2>

  <p>
    A model is the template every asset is created from. Models hold
    the manufacturer, part number, type, and a couple of model-level
    defaults that propagate to new assets (default calibration
    frequency, default lookup IDs). Register the models before you
    register the assets.
  </p>

  <h3>Creating a model</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the Models page</strong> &mdash; sidebar &rarr; Assets &rarr; <em>Models</em>, or browse to <code>/asset.php?action=models</code>.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "+ New model"</strong> at the top right of the list.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the form:</strong> manufacturer, part / model number, type (gauge, fixture, instrument, etc.), and the optional default values.</p>
        <p class="sub">The "type" field categorises models; the asset list lets you filter by it. Pick a category your company actually uses &mdash; "Calibration master", "Pin gauge", "Micrometer", "Fixture" are typical.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The model becomes available in the model-picker on the asset registration form.</p>
      </div>
    </div>
  </div>

  <div class="callout note">
    <div class="label">MODEL VS ASSET</div>
    <p>If you have ten identical micrometers, you create ONE model and TEN assets. Each asset gets its own tag (ASSET-00042, ASSET-00043, &hellip;) and tracks its own location, calibration, and history independently. The model is shared.</p>
  </div>
</section>

<!-- ============ REGISTER ============ -->
<section class="module" id="register">
  <h2><span class="num">03</span> Registering an Asset</h2>

  <p>
    Once your models exist, each individual asset is a quick form-fill.
    The system auto-generates the asset tag using the configured Code
    Sequence (default <code>ASSET-NNNNN</code>); you can override
    manually if your shop already has a tag convention.
  </p>

  <h3>The registration form</h3>
  <table>
    <thead><tr><th style="width:30%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Asset tag</td><td class="dim">Auto-filled with the next sequence value. Override only if you want a non-standard tag (e.g., legacy import).</td></tr>
      <tr><td>Model <strong>*</strong></td><td class="dim">Required. Pick from registered models. Use the type-ahead to filter.</td></tr>
      <tr><td>Location</td><td class="dim">Where the asset starts its life. Can be changed later via a Move transaction.</td></tr>
      <tr><td>Parent asset</td><td class="dim">Optional &mdash; only for sub-assets inside a set / kit. See &sect;07.</td></tr>
      <tr><td>Lookup IDs</td><td class="dim">Alias, Frequency, Engraved ID, Calibration ID, Checked-OK ID &mdash; each is a dropdown of admin-configured values. Leave empty if your shop doesn't use them.</td></tr>
      <tr><td>Calibration dates</td><td class="dim">Last-done and next-due dates. Even for new assets, capture last-done = receipt date so the cycle starts.</td></tr>
      <tr><td>Notes</td><td class="dim">Free text. Vendor warranty, serial number details, anything that wouldn't fit elsewhere.</td></tr>
      <tr><td>Used in PID</td><td class="dim">For process-loop instruments: which P&amp;ID loop tag this is part of. Leave empty for shop tools.</td></tr>
      <tr><td>Price</td><td class="dim">Acquisition cost. Optional, but useful for insurance and depreciation reports.</td></tr>
    </tbody>
  </table>

  <h3>What happens on save</h3>
  <p>
    Saving a new asset writes the assets row AND creates an
    <code>asset_transactions</code> row with type <code>create</code>.
    From that point on, every change to the asset goes through a
    transaction so the history stays complete.
  </p>

  <div class="callout">
    <div class="label">TIP</div>
    <p>For high-volume registration (e.g., onboarding 50 new gauges at once), use Bulk Import instead. See &sect;09.</p>
  </div>
</section>

<!-- ============ VIEW ============ -->
<section class="module" id="view">
  <h2><span class="num">04</span> The Asset View</h2>

  <p>
    The asset view page is the single-source-of-truth for one piece
    of equipment. Header shows the tag, status pill, and quick actions.
    The body has three vertical sections: current state, transaction
    history, and notes / files.
  </p>

  <h3>Header actions</h3>
  <table>
    <thead><tr><th style="width:22%">BUTTON</th><th>EFFECT</th></tr></thead>
    <tbody>
      <tr><td>Transact</td><td class="dim">Opens the transaction form &mdash; move, send to vendor, receive from vendor, send to user, return from user, calibrate, archive, restore. See &sect;05.</td></tr>
      <tr><td>Edit</td><td class="dim">Edit the asset's metadata (model, location, lookup IDs, notes). Edits also write an <code>edit</code> transaction so the change is traceable.</td></tr>
      <tr><td>Clone</td><td class="dim">Create a new asset with the same model and lookup defaults but a fresh tag. Useful when registering multiple identical units.</td></tr>
      <tr><td>Archive</td><td class="dim">Mark the asset as no longer in service. Status changes to <code>archived</code>. Reversible via <em>Restore</em>.</td></tr>
      <tr><td>Delete</td><td class="dim">Hard-delete the row and its transaction history. <strong>Irreversible.</strong> Use Archive instead unless you really mean to lose history.</td></tr>
    </tbody>
  </table>

  <h3>Status pills</h3>
  <table>
    <thead><tr><th style="width:22%">STATUS</th><th>MEANING</th></tr></thead>
    <tbody>
      <tr><td><span style="color:#16a34a;font-weight:600;">active</span></td><td class="dim">In service, in stock, available for use.</td></tr>
      <tr><td><span style="color:#d97706;font-weight:600;">with_vendor</span></td><td class="dim">Out for calibration, repair, or recertification. The current_vendor_id field names the vendor.</td></tr>
      <tr><td><span style="color:#3b82f6;font-weight:600;">with_user</span></td><td class="dim">Issued to a named person (technician, inspector). The current_user_id field names who has it.</td></tr>
      <tr><td><span style="color:#6b7280;font-weight:600;">archived</span></td><td class="dim">Retired from service. Not deleted, but excluded from the default asset list view.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ TRANSACTIONS ============ -->
<section class="module" id="transactions">
  <h2><span class="num">05</span> Transactions</h2>

  <p>
    Every meaningful change to an asset's state goes through a
    transaction. Transactions are the audit log &mdash; you can always
    answer "where was this gauge on January 15?" by walking back the
    history. Ten transaction types are supported.
  </p>

  <h3>Transaction types</h3>
  <table>
    <thead><tr><th style="width:22%">TYPE</th><th>WHAT IT MEANS</th></tr></thead>
    <tbody>
      <tr><td><strong>create</strong></td><td class="dim">Asset was registered. Fires automatically when you save a new asset.</td></tr>
      <tr><td><strong>edit</strong></td><td class="dim">An attribute (notes, model, lookup ID, etc.) was changed. Fires automatically on save of the edit form.</td></tr>
      <tr><td><strong>move</strong></td><td class="dim">Asset moved to a new location. Use for shop-floor reorganisations.</td></tr>
      <tr><td><strong>send_vendor</strong></td><td class="dim">Asset was shipped to a vendor &mdash; calibration, repair, return-for-credit. Status becomes <code>with_vendor</code>.</td></tr>
      <tr><td><strong>receive_vendor</strong></td><td class="dim">Asset came back from the vendor. Often paired with a <code>calibrate</code> transaction if calibration was the reason. Status returns to <code>active</code>.</td></tr>
      <tr><td><strong>send_user</strong></td><td class="dim">Asset issued to a named person. Status becomes <code>with_user</code>.</td></tr>
      <tr><td><strong>receive_user</strong></td><td class="dim">Asset returned by the user. Status returns to <code>active</code>.</td></tr>
      <tr><td><strong>calibrate</strong></td><td class="dim">Calibration event recorded &mdash; sets the last-done date and computes the next-due. See &sect;06.</td></tr>
      <tr><td><strong>archive</strong></td><td class="dim">Asset retired from service.</td></tr>
      <tr><td><strong>restore</strong></td><td class="dim">Archived asset brought back into service.</td></tr>
    </tbody>
  </table>

  <h3>Recording a transaction</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the asset's view page</strong> and click "Transact" in the header.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick the transaction type</strong> from the dropdown.</p>
        <p class="sub">The form fields below adapt to your choice &mdash; moving asks for a destination location, sending to a vendor asks for the vendor and an optional reference doc, etc.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the contextual fields and notes.</strong></p>
        <p class="sub">Always include notes when the transaction's "why" is non-obvious &mdash; "Sent to ACME Cal Lab, expected back by Friday. RMA #1234." The notes show up in transaction history forever.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The transaction is written, the asset's status and location update accordingly, and the history pane on the asset page shows the new row at the top.</p>
      </div>
    </div>
  </div>

  <div class="callout warn">
    <div class="label">NO BACKDATING</div>
    <p>Transactions are timestamped at the moment they're saved. If you need to record a move that happened last week, note the actual date in the transaction's notes field &mdash; don't try to manipulate the timestamp. Audit integrity depends on the timestamp being the wallclock of when the record was created.</p>
  </div>
</section>

<!-- ============ CALIBRATION ============ -->
<section class="module" id="calibration">
  <h2><span class="num">06</span> Calibration Tracking</h2>

  <p>
    Each asset carries two calibration dates: <em>last-done</em> and
    <em>next-due</em>. The Calibration / Warranty admin view (sidebar
    &rarr; Assets &rarr; <em>Calibration / Warranty</em>) lists every
    asset with a next-due date, sorted by urgency.
  </p>

  <h3>Recording a calibration</h3>
  <p>
    Use the <code>calibrate</code> transaction type. The form asks for:
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li><strong>Calibration done on</strong> &mdash; the date the calibration was performed (typically today, but you can backdate if recording a recent event after the fact).</li>
    <li><strong>Next calibration due</strong> &mdash; the date the next calibration is required. Often derived from the asset's frequency (e.g. annual) plus the done date.</li>
    <li><strong>Notes</strong> &mdash; certificate number, vendor who did it, any out-of-spec findings.</li>
  </ul>

  <h3>The Calibration / Warranty view</h3>
  <p>
    Sortable list with these key columns: Asset tag, Model, Last
    calibrated, Next due, Days remaining (negative = overdue), Status.
    Color cues highlight overdue (red), due-this-month (yellow), and
    current (green).
  </p>

  <table>
    <thead><tr><th style="width:24%">DAYS REMAINING</th><th>VISUAL CUE</th><th>WHAT IT MEANS</th></tr></thead>
    <tbody>
      <tr><td class="mono">&lt; 0</td><td class="dim">Red pill</td><td class="dim">Overdue &mdash; should not be in service.</td></tr>
      <tr><td class="mono">0&ndash;30</td><td class="dim">Yellow pill</td><td class="dim">Due this month. Schedule the cal before it lapses.</td></tr>
      <tr><td class="mono">31&ndash;90</td><td class="dim">Neutral</td><td class="dim">Due soon. Plan the cal trip.</td></tr>
      <tr><td class="mono">&gt; 90</td><td class="dim">Green pill</td><td class="dim">Current.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">FREQUENCY LOOKUP</div>
    <p>The Frequency lookup field on each asset (configurable in Admin &rarr; Asset Lookups) defines the standard cycle &mdash; monthly, quarterly, annual, biennial, calibration-on-demand. Use it consistently so the Calibration view groups your fleet by cycle.</p>
  </div>
</section>

<!-- ============ SUB-ASSETS ============ -->
<section class="module" id="subassets">
  <h2><span class="num">07</span> Sub-assets &amp; Location Locking</h2>

  <p>
    Some assets are conceptually <em>contained</em> by other assets
    &mdash; pin gauges inside a master pin set, individual collets
    inside a collet rack, components of a fixture kit. The module
    supports this with the <code>parent_asset_id</code> field on each
    asset.
  </p>

  <h3>Creating a sub-asset</h3>
  <p>
    On the registration form, pick the parent in the <em>Parent asset</em>
    field. The child appears nested under its parent in the asset list
    when grouping by parent is enabled.
  </p>

  <h3>Lock to parent</h3>
  <p>
    The <em>Lock to parent</em> checkbox forces the child to always
    inhabit the same location as the parent. When the parent is moved
    via a <code>move</code> transaction, every locked child gets a
    corresponding move transaction automatically so their location
    stays in sync.
  </p>
  <p>
    Useful for kits that physically travel together (a master pin
    set's individual pins always live in the same drawer as the set
    box). Use carefully &mdash; locked children can't be moved
    independently without first unlocking.
  </p>

  <div class="callout note">
    <div class="label">UNLOCKING</div>
    <p>Uncheck "Lock to parent" on the child's edit form to break the link. Useful when one pin from a set is temporarily out for calibration &mdash; unlock it, move it (or send_vendor it), then re-lock when it's back in the set.</p>
  </div>
</section>

<!-- ============ HISTORY ============ -->
<section class="module" id="history">
  <h2><span class="num">08</span> Asset Transaction History</h2>

  <p>
    Each asset's view page shows its own history at the bottom. For a
    company-wide view of every asset transaction across every asset,
    open sidebar &rarr; Assets &rarr; <em>Asset Transactions</em>
    (or <code>/asset.php?action=txn_history</code>).
  </p>

  <h3>Columns</h3>
  <table>
    <thead><tr><th style="width:22%">COLUMN</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>When</td><td class="dim">Wallclock timestamp when the transaction was recorded.</td></tr>
      <tr><td>Type</td><td class="dim">Colored pill (create &mdash; green, edit &mdash; neutral, move &mdash; blue, send_vendor &mdash; amber, etc.). Filterable via the column dropdown.</td></tr>
      <tr><td>Asset</td><td class="dim">Tag, linked to the asset's view page.</td></tr>
      <tr><td>Model</td><td class="dim">Searchable.</td></tr>
      <tr><td>From &rarr; To</td><td class="dim">Smart composite cell. For <code>move</code>: 📍 old location &rarr; 📍 new location. For <code>send_vendor</code>: 📍 current location &rarr; 🏢 vendor. For <code>send_user</code>: 📍 location &rarr; 👤 user. Each emoji disambiguates what kind of entity is on each side.</td></tr>
      <tr><td>Calibration</td><td class="dim">For calibrate transactions: shows "done YYYY-MM-DD &middot; next YYYY-MM-DD". Empty for other types.</td></tr>
      <tr><td>Notes</td><td class="dim">The notes the operator entered.</td></tr>
      <tr><td>By</td><td class="dim">User who recorded the transaction.</td></tr>
    </tbody>
  </table>

  <h3>Filters</h3>
  <p>
    The Type column has a single-select dropdown filter with all 10
    transaction types. The Asset and Model columns are text-search. The
    From &rarr; To column is text-search across all six possible name
    columns (locations, users, vendors on both sides), so typing a
    vendor name finds every transaction where that vendor was involved.
  </p>
</section>

<!-- ============ IMPORT ============ -->
<section class="module" id="import">
  <h2><span class="num">09</span> Bulk Import</h2>

  <p>
    Three importers ship with the module: <em>Assets</em>,
    <em>Models</em>, and <em>Asset Transactions</em>. Each takes a CSV
    file and runs a preview-then-commit flow so you can catch problems
    before changes hit the database.
  </p>

  <h3>The import flow (all three)</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "Import"</strong> in the toolbar of the appropriate list page (Assets, Models, or Asset Transactions).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Download the template</strong> if you haven't built your CSV yet. Open in Excel / LibreOffice / Google Sheets, fill in the rows, save as CSV.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Upload the CSV.</strong> The preview view shows every row with parsed values, marked OK, WARN, or ERROR.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Review the preview carefully.</strong> Fix any ERROR rows in your CSV and re-upload. WARN rows will still import but flag soft issues (e.g. an unrecognised lookup value &mdash; the row imports without that value populated).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "Commit import"</strong> to apply. The commit writes all OK rows in a single transaction &mdash; either all land or none do.</p>
      </div>
    </div>
  </div>

  <h3>CSV format tips</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>UTF-8 encoding only. Excel's default Save-As may use Windows-1252; pick "CSV UTF-8" explicitly on Windows.</li>
    <li>Headers must match the template exactly (case-sensitive). Extra columns are ignored. Missing required columns cause the upload to reject.</li>
    <li>Dates in <code>YYYY-MM-DD</code> format.</li>
    <li>Lookup values (location name, model number, vendor name) must match existing records exactly. Pre-create any missing lookups before importing.</li>
  </ul>

  <div class="callout warn">
    <div class="label">PREVIEW IS NOT A DRY RUN</div>
    <p>The preview parses your CSV and reports what WILL happen, but it doesn't reserve the data. If two people import overlapping CSVs and both commit at the same time, the second commit can fail mid-way. For large imports (&gt; 500 rows) consider coordinating with your team so only one person is importing at a time.</p>
  </div>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">10</span> Common Workflows</h2>

  <h3>Annual calibration cycle</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open the Calibration / Warranty list. Filter to "due this month" (yellow pills).</li>
    <li>For each asset in the list, perform a <code>send_vendor</code> transaction. Pick the calibration vendor, attach a vendor reference (PO or RMA), add notes.</li>
    <li>When the asset returns, perform <code>receive_vendor</code> AND <code>calibrate</code> transactions back-to-back. Receive sets status back to <code>active</code>; calibrate records the new last-done and next-due dates.</li>
    <li>Attach the calibration certificate to the asset (notes field with cert number, or as a file if your install has the attachments feature).</li>
  </ol>

  <h3>Issuing a tool to a technician</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Find the asset in the asset list. Confirm status is <code>active</code>.</li>
    <li>Click Transact &rarr; <code>send_user</code>. Pick the user from the dropdown. Notes: project, expected return date, anything special.</li>
    <li>When the asset returns, Transact &rarr; <code>receive_user</code>. Status returns to <code>active</code>.</li>
    <li>If the asset was damaged or needs cal verification on return, add the appropriate notes and schedule a calibration if needed.</li>
  </ol>

  <h3>Onboarding a new asset purchase</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Check whether the asset's model is already registered. If not, create the model first.</li>
    <li>Use the asset registration form (or bulk import if &gt; 10 units). Fill in serial number in notes, vendor on the invoice link, etc.</li>
    <li>For each new asset, perform an initial <code>calibrate</code> transaction to record the vendor-supplied calibration cert date as last-done, and compute next-due from the model's standard frequency.</li>
    <li>Tag the physical asset with the auto-generated <code>ASSET-NNNNN</code> tag &mdash; a label printer is the typical workflow.</li>
  </ol>

  <h3>Retiring an asset</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Confirm the asset is no longer issued to a user and not at a vendor. Status should be <code>active</code> before archive.</li>
    <li>Click Transact &rarr; <code>archive</code>. Add notes explaining the reason (worn out, lost, sold for scrap, returned to owner).</li>
    <li>The asset disappears from the default list view (which filters to active only). It remains in the database with full history.</li>
    <li>If the asset is rediscovered or reactivated, Transact &rarr; <code>restore</code>.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">11</span> Troubleshooting</h2>

  <h3>I can't find an asset in the list</h3>
  <p>
    Two common reasons: (1) the asset is archived &mdash; the default
    list filters to active assets only. Toggle the "show archived"
    filter at the top. (2) The asset is currently <code>with_user</code>
    or <code>with_vendor</code>, and your filter is showing only
    <code>active</code>. Adjust the status filter or remove it.
  </p>

  <h3>The calibration date didn't update after a calibrate transaction</h3>
  <p>
    The dates on the asset only update when you save the calibrate
    transaction with both <em>Calibration done on</em> AND <em>Next
    calibration due</em> fields filled in. If you left one blank, the
    transaction was recorded but the asset's dates weren't touched.
    Edit the asset directly to set the dates.
  </p>

  <h3>I tried to move a child asset and it got blocked</h3>
  <p>
    The child has "Lock to parent" enabled, which forbids independent
    moves. Edit the child, uncheck Lock to parent, then move it. Re-lock
    once it's back with the parent if you want to maintain the
    automatic-sync behaviour.
  </p>

  <h3>An import preview shows ERROR on every row</h3>
  <p>
    Usually a header-row mismatch. Compare your CSV's header line
    character-by-character against the template. Common culprits:
    spaces in column names, case differences ("Model" vs "model"),
    BOM characters from Excel (open the CSV in a plain text editor
    to see). Re-save from the template if in doubt.
  </p>

  <h3>The asset tag I want is "taken" but I can't find it in the list</h3>
  <p>
    Asset tags must be unique across all assets including archived
    ones. The list might show only active assets by default. Search
    in the unfiltered list (toggle "show archived") &mdash; or check
    if the tag belongs to a soft-deleted record. Pick a different
    tag or restore + re-edit the old one if appropriate.
  </p>

  <h3>"Used in PID" is empty for everything &mdash; should I be filling it in?</h3>
  <p>
    Only for assets that are part of a P&amp;ID instrumentation loop
    (control instruments, transmitters, valves). For shop tools
    (gauges, fixtures, jigs), leave it empty. It's a sparse field by
    design.
  </p>
</section>

<div class="foot">
    <div>Assets &middot; Operator Manual &middot; v1.0</div>
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
