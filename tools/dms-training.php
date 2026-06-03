<?php
// MagDyn integration: require login to access this manual. The
// bootstrap resolves to the parent app dir so this works whether
// the file is loaded directly or via the training-course iframe.
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Documents · Operator Manual';
$current_page  = 'dms-training.php';
$trigger_style = 'dark';
$cdn_scripts   = [];
include 'includes/head.php';
?>
<style>
/* ============ TRAINING DOC — MagDyn ============
   Same scaffolding as the other module manuals for visual consistency.
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

.steps { margin: 14px 0; counter-reset: stepnum; }
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
.lifecycle {
    margin: 16px 0;
    padding: 16px;
    background: var(--surface-alt);
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    line-height: 1.8;
}
.lifecycle .state {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 3px;
    margin: 0 2px;
    font-weight: 600;
    color: white;
}
.lifecycle .state.draft     { background: #6b7280; }
.lifecycle .state.review    { background: #d97706; }
.lifecycle .state.approved  { background: #2563eb; }
.lifecycle .state.released  { background: #059669; }
.lifecycle .state.obsolete  { background: #374151; }
.lifecycle .state.received  { background: #6b7280; }
.lifecycle .state.accepted  { background: #059669; }
.lifecycle .state.rejected  { background: #dc2626; }
.lifecycle .state.filed     { background: #2563eb; }
.lifecycle .arrow { color: var(--muted); margin: 0 6px; }
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
            <div class="brand-title">Documents Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#model">The Document Model</a></li>
            <li><a href="#categories">Categories &amp; Numbering</a></li>
            <li><a href="#internal">Internal Documents</a></li>
            <li><a href="#external">External Documents</a></li>
            <li><a href="#revisions">Revisions &amp; Files</a></li>
            <li><a href="#recipients">Recipients &amp; Acks</a></li>
            <li><a href="#linking">Entity Linking</a></li>
            <li><a href="#transmittals">Transmittals</a></li>
            <li><a href="#notes">Review Comments</a></li>
            <li><a href="#permissions">Permissions</a></li>
            <li><a href="#dashboard">Dashboard</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Documents (DMS)</div>
    <h1>Every controlled document, every revision, <strong>every recipient</strong>.</h1>
    <p class="lede">
        The Documents module is your controlled-document library. It tracks two
        very different flows in one place &mdash; the documents you author and
        release (SOPs, work instructions, drawings, procedures), and the
        documents that arrive from outside (customer specs, vendor certificates,
        calibration certs, datasheets). Both get unique codes, a revision history,
        a lifecycle, and a complete audit trail of who touched what and when.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
<h2><span class="num">01</span>Overview</h2>

<p>
    Open <a href="<?= h(url('/documents.php?kind=internal')) ?>"><strong>Documents</strong> → <strong>Internal</strong></a>
    to see your authored documents, or <a href="<?= h(url('/documents.php?kind=external')) ?>"><strong>Documents → External</strong></a>
    for the incoming ones. They share the same page (<code>documents.php</code>) but the
    <code>kind</code> parameter swaps the form, the lifecycle, and the permission set.
</p>

<h3>What the module does</h3>
<table>
<thead><tr><th>Capability</th><th>Internal docs</th><th>External docs</th></tr></thead>
<tbody>
<tr><td>Auto-numbering by category</td><td>✓ (SOP-00001, DWG-00001, …)</td><td>✓ (CSP-00001, MTR-00001, …)</td></tr>
<tr><td>Revision history with file storage</td><td>✓ Major.Minor</td><td>✓ Major.Minor</td></tr>
<tr><td>Lifecycle (status workflow)</td><td>Draft → In&nbsp;Review → Approved → Released → Obsolete</td><td>Received → In&nbsp;Review → Accepted/Rejected → Filed</td></tr>
<tr><td>Effective date / expiry / next review</td><td>Effective date set at release</td><td>Expiry + next-review tracking</td></tr>
<tr><td>Recipient assignment + acknowledgment</td><td>✓ at release</td><td>n/a (single counterparty)</td></tr>
<tr><td>Vendor / counterparty linkage</td><td>n/a</td><td>✓ vendor selector</td></tr>
<tr><td>Entity linking (assets, items, ECNs, etc.)</td><td>✓</td><td>✓</td></tr>
<tr><td>Transmittal events for outgoing sends</td><td>✓ (typical)</td><td>✓ (rare, e.g. forwarding to a third party)</td></tr>
<tr><td>Review comments (running notes)</td><td>✓ "Document Review Comments" category</td><td>✓ same category</td></tr>
<tr><td>Training course linkage</td><td>✓ optional</td><td>n/a</td></tr>
</tbody>
</table>

<div class="callout">
    <div class="label">Why two kinds?</div>
    <p>Internal and external documents go through fundamentally different processes.
       An internal SOP has authors, reviewers, approvers, recipients, and
       acknowledgments &mdash; you control all of it. An external calibration cert
       arrives, you verify it, you file it, and you track when it expires.
       Splitting them keeps each form simple and lets you grant the two flows
       to different roles (e.g. Engineering can manage internal docs, Quality
       can manage external docs).</p>
</div>
</section>

<!-- ============ MODEL ============ -->
<section class="module" id="model">
<h2><span class="num">02</span>The Document Model</h2>

<p>Each document is one row in the <code>documents</code> table with these key fields:</p>

<table>
<thead><tr><th>Field</th><th>What it holds</th></tr></thead>
<tbody>
<tr><td class="mono">code</td><td>Auto-generated, unique. Pulled from per-category Code Sequence (e.g. <code>SOP-00042</code>).</td></tr>
<tr><td class="mono">title</td><td>Free text. Editable until release/accept.</td></tr>
<tr><td class="mono">category_id</td><td>Pins the doc to one category (SOP, Vendor Cert, etc.).</td></tr>
<tr><td class="mono">kind</td><td>Either <code>internal</code> or <code>external</code>. Cannot be changed after creation.</td></tr>
<tr><td class="mono">status</td><td>Current lifecycle state. See §04 / §05.</td></tr>
<tr><td class="mono">current_rev_id</td><td>Pointer to the "live" revision (latest released for internal, latest accepted for external).</td></tr>
<tr><td class="mono">effective_date</td><td>Internal only. Set at release. The doc isn't binding until this date.</td></tr>
<tr><td class="mono">expiry_date</td><td>External. When the doc loses validity (e.g. cal cert valid 12 months).</td></tr>
<tr><td class="mono">next_review_date</td><td>External. Periodic re-review trigger. Drives dashboard alerts.</td></tr>
<tr><td class="mono">vendor_id</td><td>External. The counterparty that supplied the doc.</td></tr>
<tr><td class="mono">training_course_id</td><td>Internal. Optional link to a course recipients must complete on ack.</td></tr>
</tbody>
</table>

<p>Around the document row, eight related tables hold the rest of the picture:</p>

<table>
<thead><tr><th>Table</th><th>Holds</th></tr></thead>
<tbody>
<tr><td class="mono">doc_categories</td><td>The list of categories. Each has a prefix and a sequence.</td></tr>
<tr><td class="mono">doc_revisions</td><td>One row per revision (rev_major, rev_minor) per doc, with file metadata.</td></tr>
<tr><td class="mono">doc_recipients</td><td>Who an internal doc was released to (for one specific rev).</td></tr>
<tr><td class="mono">doc_acknowledgments</td><td>Confirmations from recipients. One ack per recipient assignment.</td></tr>
<tr><td class="mono">doc_transmittals</td><td>Outgoing send events (cover sheet PDF, delivery status).</td></tr>
<tr><td class="mono">doc_entity_links</td><td>Polymorphic links to assets, items, inspections, invoices, ECNs, …</td></tr>
<tr><td class="mono">doc_history</td><td>Append-only audit trail of every action on the doc.</td></tr>
</tbody>
</table>
</section>

<!-- ============ CATEGORIES ============ -->
<section class="module" id="categories">
<h2><span class="num">03</span>Categories &amp; Numbering</h2>

<p>
    Every document belongs to a category. The category determines the
    auto-generated code prefix and which Code Sequence increments it. MagDyn
    ships with 14 standard QMS categories &mdash; 7 internal, 7 external &mdash;
    and you can add more from <a href="<?= h(url('/module.php?m=doc_categories')) ?>">Admin → Document Categories</a>
    (placeholder for now; the table is editable directly via the Code Sequences
    page or with a small admin patch).
</p>

<h3>Shipped categories</h3>
<table>
<thead><tr><th>Prefix</th><th>Name</th><th>Kind</th></tr></thead>
<tbody>
<tr><td class="mono">SOP</td><td>Standard Operating Procedure</td><td>internal</td></tr>
<tr><td class="mono">WI</td><td>Work Instruction</td><td>internal</td></tr>
<tr><td class="mono">DWG</td><td>Drawing</td><td>internal</td></tr>
<tr><td class="mono">PROC</td><td>Procedure</td><td>internal</td></tr>
<tr><td class="mono">SPEC</td><td>Specification</td><td>internal</td></tr>
<tr><td class="mono">FRM</td><td>Form / Template</td><td>internal</td></tr>
<tr><td class="mono">MAN</td><td>Manual</td><td>internal</td></tr>
<tr><td class="mono">CSP</td><td>Customer Specification</td><td>external</td></tr>
<tr><td class="mono">CDW</td><td>Customer Drawing</td><td>external</td></tr>
<tr><td class="mono">VC</td><td>Vendor Quality Certificate</td><td>external</td></tr>
<tr><td class="mono">CC</td><td>Calibration Certificate</td><td>external</td></tr>
<tr><td class="mono">DS</td><td>Datasheet</td><td>external</td></tr>
<tr><td class="mono">MTR</td><td>Material Test Report</td><td>external</td></tr>
<tr><td class="mono">CTR</td><td>Contract / Agreement</td><td>external</td></tr>
</tbody>
</table>

<h3>How numbers get assigned</h3>
<p>
    Each category has its own row in <code>code_sequences</code> named
    <code>doc_&lt;cat_code&gt;</code> (e.g. <code>doc_sop</code>, <code>doc_cc</code>).
    When you save a new document, the dispatcher calls
    <code>doc_next_code($categoryId)</code>, which pulls the next number,
    pads to width 5, and prefixes with the category's prefix. The result
    looks like <code>SOP-00042</code>.
</p>

<div class="callout note">
    <div class="label">Reset numbering?</div>
    <p>Go to <a href="<?= h(url('/code_sequences.php')) ?>">Admin → Code Sequences</a>
       and edit the <code>doc_sop</code> (or other) row's <code>next_value</code>.
       Don't reset unless you've cleaned up the old docs; uniqueness on
       <code>documents.code</code> will reject collisions.</p>
</div>
</section>

<!-- ============ INTERNAL ============ -->
<section class="module" id="internal">
<h2><span class="num">04</span>Internal Documents</h2>

<p>
    Internal documents are the ones you author. The lifecycle takes them
    from a working draft, through review and approval, to a released
    revision that is binding from a specific effective date.
</p>

<h3>Lifecycle</h3>
<div class="lifecycle">
    <span class="state draft">draft</span>
    <span class="arrow">→</span>
    <span class="state review">in_review</span>
    <span class="arrow">→</span>
    <span class="state approved">approved</span>
    <span class="arrow">→</span>
    <span class="state released">released</span>
    <span class="arrow">→</span>
    <span class="state obsolete">obsolete</span>
</div>

<table>
<thead><tr><th>State</th><th>What it means</th><th>Who can transition</th></tr></thead>
<tbody>
<tr><td><strong>Draft</strong></td><td>Author is still working. Editable. File uploads create Minor revs.</td><td>Manager (documents_internal.manage)</td></tr>
<tr><td><strong>In Review</strong></td><td>Reviewers have access; review comments are captured under the "Document Review Comments" running-notes category.</td><td>Manager</td></tr>
<tr><td><strong>Approved</strong></td><td>Sign-off recorded. Still not in force until released.</td><td>Approver (documents_internal.approve)</td></tr>
<tr><td><strong>Released</strong></td><td>Major rev bumped. Effective date set. Recipients can be added and asked to acknowledge.</td><td>Approver</td></tr>
<tr><td><strong>Obsolete</strong></td><td>Superseded or withdrawn. Read-only; previous revs remain in history.</td><td>Manager</td></tr>
</tbody>
</table>

<h3>Creating an internal document</h3>
<div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Pick a category.</strong></p>
        <p class="sub">The category determines the auto-generated code (SOP-00042, WI-00007, …).</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Title, description, owner, optional training course.</strong></p>
        <p class="sub">If the doc is something employees must read and confirm (e.g. a new
           safety SOP), link the training course; ack-time wiring will create the
           training enrollment automatically.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Save.</strong> The doc lands as Draft with revision 0.1 (no file yet).</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Upload your draft file.</strong></p>
        <p class="sub">On the document view, use the <strong>Upload Revision</strong> card. Pick
           "Minor" for normal edits, "Major" for a meaningful structural change
           pre-release. Add a change note; future-you will be glad.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Move to In Review.</strong></p>
        <p class="sub">Click the <kbd>→ In Review</kbd> button at the top right. Anyone
           with view + manage on the In-Review-comments running-notes category
           can leave reviewer comments inline.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Approve.</strong></p>
        <p class="sub">An approver clicks <kbd>→ Approved</kbd>. The approver and
           approval timestamp are stamped on the document.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Release with an effective date.</strong></p>
        <p class="sub">Click <kbd>→ Released</kbd>. Set the effective date (defaults to today;
           pick a future date if the doc takes effect later). The system bumps the
           Major revision (e.g. 0.4 → 1.0), stamps released_by and released_at, and
           opens the Recipients card.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Add recipients.</strong></p>
        <p class="sub">Pick users from the dropdown, or type an external name for
           non-user recipients. Set a due date if there's an acknowledgment
           deadline. Each recipient appears in their own
           <a href="<?= h(url('/index.php')) ?>">dashboard</a> until they
           acknowledge.</p>
    </div></div>
</div>

<div class="callout warn">
    <div class="label">Release is a hard line</div>
    <p>After release, the doc's metadata is read-only. To change anything,
       transition <span class="state obsolete">obsolete</span> and create a new
       document, OR upload a new revision and re-release (Major bump on every release).
       MagDyn keeps the old released rev visible in the rev history so audit trails
       are intact.</p>
</div>
</section>

<!-- ============ EXTERNAL ============ -->
<section class="module" id="external">
<h2><span class="num">05</span>External Documents</h2>

<p>
    External documents are what comes in from the outside &mdash; a customer
    spec, a vendor cal cert, an MTR with a material shipment. The lifecycle
    is shorter and the focus shifts to <em>verifying</em> and <em>filing</em>
    the doc rather than authoring it.
</p>

<h3>Lifecycle</h3>
<div class="lifecycle">
    <span class="state received">received</span>
    <span class="arrow">→</span>
    <span class="state review">in_review</span>
    <span class="arrow">→</span>
    <span class="state accepted">accepted</span>
    <span class="arrow">/</span>
    <span class="state rejected">rejected</span>
    <span class="arrow">→</span>
    <span class="state filed">filed</span>
</div>

<table>
<thead><tr><th>State</th><th>What it means</th></tr></thead>
<tbody>
<tr><td><strong>Received</strong></td><td>Logged in but not yet verified. Default for newly-created external docs.</td></tr>
<tr><td><strong>In Review</strong></td><td>QC or whoever is checking; review notes captured inline.</td></tr>
<tr><td><strong>Accepted</strong></td><td>Verified and valid. Major rev bumped (so the "accepted" version is unambiguous in history).</td></tr>
<tr><td><strong>Rejected</strong></td><td>Failed verification (wrong cert, expired, mismatched). Can be moved back to Received once corrected.</td></tr>
<tr><td><strong>Filed</strong></td><td>Permanent retention. Read-only.</td></tr>
</tbody>
</table>

<h3>Logging an incoming document</h3>
<div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Pick the right category.</strong></p>
        <p class="sub">Cal cert → CC. Material test report → MTR. Customer spec → CSP. Etc.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Vendor and external reference.</strong></p>
        <p class="sub">"External ref" is the doc number as printed on the document itself
           (e.g. the vendor's own cert number). Useful when an auditor asks you
           to produce "cert XYZ-2024-003".</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Dates: issued, received, expiry, next-review.</strong></p>
        <p class="sub">Set what's known. Expiry drives the "expiring soon" dashboard alert; next-review drives the "due for periodic review" alert.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Save, then upload the scanned PDF / electronic copy.</strong></p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Verify and accept</strong> (or reject and request re-issue).</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>File when no further action is expected.</strong></p>
    </div></div>
</div>
</section>

<!-- ============ REVISIONS ============ -->
<section class="module" id="revisions">
<h2><span class="num">06</span>Revisions &amp; Files</h2>

<p>
    Every document has one or more <strong>revisions</strong>, each tagged
    with a <strong>Major.Minor</strong> label and its own uploaded file.
    The "current" revision is whichever one is pointed to by
    <code>documents.current_rev_id</code>; older revs stay in history.
</p>

<h3>Major.Minor rules</h3>
<table>
<thead><tr><th>Bump kind</th><th>From 1.3 you get</th><th>Trigger</th></tr></thead>
<tbody>
<tr><td>Minor</td><td>1.4</td><td>You uploaded a routine revision in Draft / In Review / Correction.</td></tr>
<tr><td>Major</td><td>2.0</td><td>You released the doc, OR you explicitly picked "Major" on upload.</td></tr>
<tr><td>Initial</td><td>0.1</td><td>New document. Created automatically with no file when you save the first time.</td></tr>
</tbody>
</table>

<p>The exact rule the system uses lives in <code>doc_next_rev()</code> in <code>includes/_dms.php</code>:</p>

<table>
<thead><tr><th>Input</th><th>Output</th></tr></thead>
<tbody>
<tr><td>No revs yet</td><td><strong>0.1</strong></td></tr>
<tr><td>Latest 1.3 + Minor</td><td><strong>1.4</strong></td></tr>
<tr><td>Latest 1.3 + Major</td><td><strong>2.0</strong></td></tr>
<tr><td>Latest 2.7 + release transition</td><td><strong>3.0</strong> (auto-Major on release)</td></tr>
</tbody>
</table>

<h3>File storage</h3>
<p>
    Uploaded files land at <code>uploads/documents/&lt;doc_id&gt;/&lt;rev_id&gt;_&lt;safe_name&gt;</code>.
    The <code>rev_id</code> prefix means two revs with the same original filename
    never collide. Files are stored once per upload; if you accidentally
    upload the same file twice as a new rev, you'll have two rev rows but
    two physical files (the SHA-256 hash is recorded for dedup tooling
    later if needed).
</p>

<div class="callout note">
    <div class="label">File limits</div>
    <p>Hostinger shared hosting caps single uploads at the PHP
       <code>upload_max_filesize</code> / <code>post_max_size</code> values &mdash;
       typically 64 MB. Big drawings (TIFF, large multi-page PDFs) may need
       to be split or compressed. The error you'll see is the generic browser
       "file too large" if the limit is exceeded server-side.</p>
</div>

<h3>Downloading a revision</h3>
<p>
    Click the filename on any revision row in the Revisions table. The
    download link goes through <code>documents.php?action=download_rev&amp;rid=N</code>,
    which checks your view permission before streaming. Anonymous downloads
    are not possible &mdash; every download is gated by login + view permission
    on the right kind (internal or external).
</p>
</section>

<!-- ============ RECIPIENTS ============ -->
<section class="module" id="recipients">
<h2><span class="num">07</span>Recipients &amp; Acknowledgments</h2>

<p>
    When you release an internal doc, you can list <strong>recipients</strong>
    who must acknowledge they've received and read it. This is the QMS
    audit trail: "everyone in production was made aware of SOP-00042 rev 2.0
    by 30 May 2026".
</p>

<h3>How it works</h3>
<div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Release the doc.</strong> Recipients card only appears in <span class="state released">released</span>.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>Add each recipient.</strong></p>
        <p class="sub">A user from the dropdown, OR a role (for "everyone in QC"), OR
           an external free-text name (for contractors or external auditors).
           Optionally set a due date.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>The recipient sees the doc in their dashboard.</strong></p>
        <p class="sub">Pending acks show up in the Documents dashboard widget under "Awaiting your acknowledgment".</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
        <p><strong>They click Acknowledge.</strong></p>
        <p class="sub">The button appears on their own recipient row. Optionally
           they can leave a comment. The ack is timestamped and immutable.</p>
    </div></div>
</div>

<h3>Acknowledgment + training course</h3>
<p>
    If the document had a <strong>training_course_id</strong> set, the
    ack triggers an enrollment in that course. Two-stage compliance:
    "I confirm I received the new SOP" (the ack) and "I confirm I
    completed the training on it" (the course completion). The
    document's <code>doc_acknowledgments.training_completion_id</code>
    column ties them together.
</p>

<div class="callout warn">
    <div class="label">One ack per recipient</div>
    <p>Each recipient row can only be acked once. If you need a new ack for the
       same person (e.g. you re-released the doc with a new revision), the
       release event added a new <code>doc_recipients</code> row for that
       revision &mdash; the old row's ack stays valid for the old rev.</p>
</div>
</section>

<!-- ============ LINKING ============ -->
<section class="module" id="linking">
<h2><span class="num">08</span>Entity Linking</h2>

<p>
    A document by itself is just a file. The value comes from knowing
    <em>what it's about</em>. The Linked Entities card on the document
    view lets you attach the doc to any other entity in MagDyn.
</p>

<table>
<thead><tr><th>Entity type</th><th>Typical use</th></tr></thead>
<tbody>
<tr><td>Asset</td><td>Cal cert for a specific gauge; SOP for operating a specific machine.</td></tr>
<tr><td>Inventory Item</td><td>MTR for a material; datasheet for a part number.</td></tr>
<tr><td>Inspection</td><td>The inspection report PDF; the customer drawing that drove the inspection.</td></tr>
<tr><td>Inspection Template</td><td>The reference drawing baked into a reusable inspection template.</td></tr>
<tr><td>Invoice</td><td>Supporting contract, PO, or quote.</td></tr>
<tr><td>Shipment</td><td>Packing list, shipping cert, CoA tied to a specific outbound shipment.</td></tr>
<tr><td>ECN</td><td>The drawing or spec changed by the ECN.</td></tr>
</tbody>
</table>

<p>
    Links are bidirectional in lookup-terms: <code>doc_for_entity('asset', 42)</code>
    returns all docs linked to asset 42, suitable for "Related Documents"
    panels on entity pages. (Wiring those panels into each entity's view
    page is a follow-on patch &mdash; the helper is ready.)
</p>
</section>

<!-- ============ TRANSMITTALS ============ -->
<section class="module" id="transmittals">
<h2><span class="num">09</span>Transmittals</h2>

<p>
    A <strong>transmittal</strong> is the record of one specific send-out
    event: "we sent rev 2.0 of SOP-00042 to ACME Corp on 5 June 2026 via
    email". Multiple transmittals per document are normal; the same doc
    might go to different customers on different days.
</p>

<p>
    The page is <a href="<?= h(url('/transmittals.php')) ?>">Transmittals</a>.
    Each transmittal carries:
</p>

<table>
<thead><tr><th>Field</th><th>What it captures</th></tr></thead>
<tbody>
<tr><td class="mono">transmittal_no</td><td>Auto-generated (TRN-00001, TRN-00002, …).</td></tr>
<tr><td class="mono">document_id, revision_id</td><td>Exactly which rev was sent.</td></tr>
<tr><td class="mono">recipient_kind</td><td>Customer / vendor / internal user / external party (free text).</td></tr>
<tr><td class="mono">recipient_attn / email / phone</td><td>Who the package is addressed to.</td></tr>
<tr><td class="mono">sent_date / method</td><td>When and how (email, post, courier, portal, handover).</td></tr>
<tr><td class="mono">reference</td><td>Their PO / RFQ / project number for cross-reference.</td></tr>
<tr><td class="mono">delivery_status</td><td>sent → delivered → acknowledged / signed / returned / failed.</td></tr>
<tr><td class="mono">cover_file_path</td><td>Optional cover sheet PDF.</td></tr>
</tbody>
</table>

<h3>Cover sheets</h3>
<p>
    Phase 1 supports <strong>operator-uploaded</strong> cover sheet PDFs.
    Click <kbd>Upload cover sheet</kbd> on a transmittal's view page and
    attach the file you sent. The auto-generated cover sheet feature (where
    MagDyn renders a templated PDF for you) is Phase 2.
</p>

<h3>Delivery confirmation</h3>
<p>
    The Delivery Confirmation card lets the operator update the status
    after the fact &mdash; "delivered, courier proof attached", "signed and
    returned", "failed, address wrong". The history of these changes
    is captured in <code>doc_history</code> on the parent document.
</p>
</section>

<!-- ============ NOTES ============ -->
<section class="module" id="notes">
<h2><span class="num">10</span>Review Comments &amp; Running Notes</h2>

<p>
    Every document carries running notes like every other entity in MagDyn,
    plus a dedicated category: <strong>Document Review Comments</strong>.
    This is the channel reviewers use during the In Review stage to leave
    inline feedback.
</p>

<h3>Two ways to comment</h3>
<table>
<thead><tr><th>Channel</th><th>Use it for</th></tr></thead>
<tbody>
<tr><td><strong>General running notes</strong></td><td>Any free-form note about the document &mdash; an operator's "I tried this and it worked" or "see related doc X for context".</td></tr>
<tr><td><strong>Document Review Comments</strong> (dedicated category)</td><td>Formal reviewer comments during In Review. Use the running-notes category dropdown to pick "Document Review Comments" when you post.</td></tr>
</tbody>
</table>

<p>
    The category is permission-gated through a shadow module called
    <code>note_cat_doc_review</code>. Anyone with <code>documents_internal.manage</code>
    automatically gets view + manage on the review comments category.
</p>
</section>

<!-- ============ PERMISSIONS ============ -->
<section class="module" id="permissions">
<h2><span class="num">11</span>Permissions</h2>

<p>
    The DMS has <strong>two separate permission sets</strong> &mdash; one for
    internal docs, one for external docs &mdash; plus the transmittals and
    dashboard modules. The split lets you grant the internal flow to
    Engineering and the external flow to Quality (or any other partition)
    without leakage.
</p>

<table>
<thead><tr><th>Module</th><th>view</th><th>create</th><th>manage</th><th>approve</th><th>delete</th></tr></thead>
<tbody>
<tr>
    <td><code>documents_internal</code></td>
    <td>See internal docs</td><td>Create new</td><td>Edit / upload rev / add recipients</td><td>Approve / release</td><td>Soft-delete</td>
</tr>
<tr>
    <td><code>documents_external</code></td>
    <td>See external docs</td><td>Log new incoming</td><td>Edit metadata / upload rev</td><td>Accept / reject</td><td>Soft-delete</td>
</tr>
<tr>
    <td><code>documents_transmittals</code></td>
    <td>See transmittals</td><td>Create</td><td>Upload covers, mark delivered</td><td>—</td><td>Cancel</td>
</tr>
<tr>
    <td><code>documents_dashboard</code></td>
    <td>See the widget</td><td>—</td><td>—</td><td>—</td><td>—</td>
</tr>
<tr>
    <td><code>note_cat_doc_review</code></td>
    <td>Read review comments</td><td>—</td><td>Post review comments</td><td>—</td><td>—</td>
</tr>
</tbody>
</table>

<h3>Default grants from the migration</h3>
<p>The shipped migration grants:</p>
<ul style="margin: 12px 0 12px 24px; line-height: 1.8;">
    <li>All five perms on every documents-* module to the <code>admin</code> role.</li>
    <li><strong>view</strong> on <code>documents_internal</code>, <code>documents_external</code>, and <code>documents_dashboard</code> to every role that already has <code>training.view</code> &mdash; the rationale being that employees who can already see training courses should be able to read released SOPs.</li>
    <li><strong>view</strong> + <strong>manage</strong> on <code>note_cat_doc_review</code> to every role with <code>documents_internal.manage</code>.</li>
</ul>

<p>Adjust grants in <a href="<?= h(url('/roles.php')) ?>">Admin → Roles</a>.</p>
</section>

<!-- ============ DASHBOARD ============ -->
<section class="module" id="dashboard">
<h2><span class="num">12</span>Dashboard &amp; Notifications</h2>

<p>
    Documents that need attention surface on the home dashboard in the
    Documents widget (when you have <code>documents_dashboard.view</code>).
    Four panels:
</p>

<table>
<thead><tr><th>Panel</th><th>Drives off</th><th>Default window</th></tr></thead>
<tbody>
<tr><td>Becoming effective soon</td><td>Internal docs released with an upcoming <code>effective_date</code>.</td><td>Next 7 days</td></tr>
<tr><td>Due for review</td><td>External docs whose <code>next_review_date</code> ≤ today + window.</td><td>Next 14 days</td></tr>
<tr><td>Expiring soon</td><td>External docs whose <code>expiry_date</code> ≤ today + window.</td><td>Next 30 days</td></tr>
<tr><td>Awaiting your acknowledgment</td><td>Released internal docs where the current user is a recipient and hasn't acked.</td><td>—</td></tr>
</tbody>
</table>

<h3>Notification framework</h3>
<p>
    Four <code>notification_types</code> are registered: <code>doc_effective_due</code>,
    <code>doc_review_due</code>, <code>doc_expiring</code>, and <code>doc_ack_pending</code>.
    Users will be able to opt in/out per-channel from
    <a href="<?= h(url('/notifications.php')) ?>">Notifications</a>.
</p>

<div class="callout note">
    <div class="label">Phase 2</div>
    <p>The actual cron job that emits these notifications is Phase 2 &mdash; the
       types are registered now so the preference UI shows them, but no
       outbound mail is sent yet. The dashboard widget (read-time) is the
       live alerting mechanism for Phase 1.</p>
</div>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
<h2><span class="num">13</span>Troubleshooting</h2>

<h3>"Document cannot be edited in status released"</h3>
<p>By design. Released metadata is immutable. Either obsolete the doc and
re-create, or upload a new revision (which creates a new rev row with a
new file but doesn't change title / category / etc.).</p>

<h3>"Upload failed (error=2)"</h3>
<p>PHP's <code>UPLOAD_ERR_FORM_SIZE</code> &mdash; the file is bigger than the form's
<code>MAX_FILE_SIZE</code> limit. Or error=1 means it exceeds
<code>upload_max_filesize</code>. Shrink the file or talk to your sysadmin
about raising the PHP limits.</p>

<h3>The code starts at SOP-00001 but I want it to start higher</h3>
<p>Edit <code>code_sequences.next_value</code> for the <code>doc_&lt;cat_code&gt;</code>
row in the Code Sequences admin page. Or run<br>
<code>UPDATE code_sequences SET next_value = 100 WHERE name = 'doc_sop';</code></p>

<h3>I deleted a document by mistake</h3>
<p>Soft delete only. The row's <code>deleted_at</code> column is set; the doc
is hidden from the list. Restore with<br>
<code>UPDATE documents SET deleted_at = NULL WHERE code = 'SOP-00042';</code></p>

<h3>An acknowledgment was made by the wrong person</h3>
<p>Acks are immutable from the UI to preserve the audit trail. Delete the
ack row directly with a quick SQL patch and have the right person re-ack:<br>
<code>DELETE FROM doc_acknowledgments WHERE id = N;</code></p>

<h3>How do I see all docs linked to a particular asset?</h3>
<p>Phase 1 stops at the helper level. <code>doc_for_entity('asset', $assetId)</code>
returns the list; wiring it into the asset view page's sidebar is a small
follow-on patch.</p>

<h3>The cover sheet PDF auto-generation isn't working</h3>
<p>Not built in Phase 1. Upload your cover sheet manually. The
auto-generator is on the Phase 2 list.</p>

<h3>I added a recipient with a role, but only one person showed up to acknowledge</h3>
<p>Role-targeted recipient rows are stored as a single row referencing the
role. Anyone in that role can ack on its behalf &mdash; whoever clicks first
is recorded. This is by design (one ack per recipient row). For per-person
tracking, add individual user recipients instead.</p>

</section>

<div class="foot">
    <div>MagDyn · Documents Manual · v1.0</div>
    <div>Section count: 13</div>
</div>

</main>
</div>

<script>
// Scroll-spy: highlight the TOC entry whose section is in view
(function () {
    var toc = document.querySelectorAll('.toc ol li a');
    var sections = [];
    toc.forEach(function (a) {
        var id = a.getAttribute('href').slice(1);
        var sec = document.getElementById(id);
        if (sec) sections.push({ a: a, el: sec });
    });
    function spy() {
        var y = window.scrollY + 80;
        var active = sections[0];
        sections.forEach(function (s) {
            if (s.el.offsetTop <= y) active = s;
        });
        toc.forEach(function (a) { a.classList.remove('active'); });
        if (active) active.a.classList.add('active');
    }
    window.addEventListener('scroll', spy, { passive: true });
    spy();
})();
</script>

<?php include 'includes/foot.php'; ?>
