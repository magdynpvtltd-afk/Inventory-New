<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'ECN · Operator Manual';
$current_page  = 'ecn-training.php';
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
            <div class="brand-title">ECN Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#lifecycle">Status Lifecycle</a></li>
            <li><a href="#types">The Six ECN Types</a></li>
            <li><a href="#originate">Originating an ECN</a></li>
            <li><a href="#affected">Affected Entities</a></li>
            <li><a href="#review">Review Stage</a></li>
            <li><a href="#approve">Approval Stage</a></li>
            <li><a href="#effective">Effective &amp; Closure</a></li>
            <li><a href="#permissions">Permissions Model</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Change control</div>
    <h1>Controlled product changes &mdash; from <strong>idea to effective</strong>.</h1>
    <p class="lede">
        The Engineering Change Notice (ECN) module is the system of
        record for every controlled change to a product, document, or
        process: a drawing revision, a BOM modification, a material
        substitution, a process change, a document rev, or a
        specification change. Each ECN flows through originator
        &rarr; reviewer &rarr; approver, gets an effective date, and
        leaves a complete audit trail.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    An ECN is a controlled record of a change. It exists for one
    reason: to make sure that when we change something about a
    product or process, the right people see it, agree it, and the
    change is documented so it can be traced months or years later.
    Without ECN discipline, drawing revs and BOM edits get made
    silently, customer audits find missing records, and nobody knows
    when "Rev B" became the running version.
  </p>

  <h3>The four people in an ECN</h3>
  <table>
    <thead><tr><th style="width:22%">ROLE</th><th>WHAT THEY DO</th></tr></thead>
    <tbody>
      <tr><td><strong>Originator</strong></td><td class="dim">Identifies the need, creates the ECN, fills in fields, attaches affected entities, submits.</td></tr>
      <tr><td><strong>Reviewer</strong></td><td class="dim">First-pass technical review. Picks up the submitted ECN, checks it's clear and complete, either passes it on to the approver or sends it back to the originator for fixes.</td></tr>
      <tr><td><strong>Approver</strong></td><td class="dim">Final go/no-go. Decides if the change is accepted, sets the effective date, and either approves or rejects.</td></tr>
      <tr><td><strong>Operator</strong></td><td class="dim">Anyone affected by the change. Reads the effective ECN to know what changed and when.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">SINGLE-APPROVER FLOW</div>
    <p>This install uses a single-approver flow: any one user with <code>ecn.approve</code> can approve. For Change Control Board (CCB) workflows requiring multiple specific roles to all sign off, the module would need extending &mdash; ask your admin if that's something you need.</p>
  </div>
</section>

<!-- ============ LIFECYCLE ============ -->
<section class="module" id="lifecycle">
  <h2><span class="num">02</span> Status Lifecycle</h2>

  <p>
    Every ECN walks through a defined set of statuses. The
    transitions are guarded &mdash; you can only move forward to an
    adjacent status, never skip steps, never go backward except
    in the specifically-allowed back-to-draft path from review.
  </p>

  <h3>The states</h3>
  <table>
    <thead><tr><th style="width:18%">STATUS</th><th>MEANING</th></tr></thead>
    <tbody>
      <tr><td><strong>Draft</strong></td><td class="dim">Originator is still preparing. Editable. Visible only to the originator (and admins). Add affected entities, fill in type details. When ready, submit.</td></tr>
      <tr><td><strong>Submitted</strong></td><td class="dim">Originator pressed Submit. Visible to everyone with view. Awaits a reviewer to pick it up.</td></tr>
      <tr><td><strong>Under review</strong></td><td class="dim">A reviewer has assigned themselves. They evaluate the ECN, then either pass-review (forwards to approver) or fail-review (sends back to draft with comments).</td></tr>
      <tr><td><strong>Approved</strong></td><td class="dim">Approver signed off. Effective date typically set at this point. The change is committed but not yet active in production.</td></tr>
      <tr><td><strong>Effective</strong></td><td class="dim">The change is now active. From this date forward, the new state of the affected entities is what production should use. ECN can no longer be undone via this workflow &mdash; a counter-ECN would be needed.</td></tr>
      <tr><td><strong>Closed</strong></td><td class="dim">Effective ECN where all downstream actions are complete. No further workflow steps. Terminal.</td></tr>
      <tr><td><strong>Rejected</strong></td><td class="dim">Approver declined. Carries rejection reason. Terminal. Originator can clone the ECN to revise.</td></tr>
      <tr><td><strong>Cancelled</strong></td><td class="dim">Originator (or admin) withdrew the ECN before approval. Terminal.</td></tr>
    </tbody>
  </table>

  <h3>The transitions</h3>
  <table>
    <thead><tr><th style="width:24%">FROM</th><th style="width:24%">TO</th><th>WHO</th></tr></thead>
    <tbody>
      <tr><td>Draft</td><td>Submitted</td><td class="dim">Originator (Submit button)</td></tr>
      <tr><td>Draft</td><td>Cancelled</td><td class="dim">Originator</td></tr>
      <tr><td>Submitted</td><td>Under review</td><td class="dim">Reviewer (Pick up)</td></tr>
      <tr><td>Submitted</td><td>Cancelled</td><td class="dim">Originator</td></tr>
      <tr><td>Under review</td><td>Awaiting approval *</td><td class="dim">Reviewer (Pass review)</td></tr>
      <tr><td>Under review</td><td>Draft</td><td class="dim">Reviewer (Send back)</td></tr>
      <tr><td>Awaiting approval *</td><td>Approved</td><td class="dim">Approver</td></tr>
      <tr><td>Awaiting approval *</td><td>Rejected</td><td class="dim">Approver</td></tr>
      <tr><td>Approved</td><td>Effective</td><td class="dim">Approver / admin (Mark effective)</td></tr>
      <tr><td>Effective</td><td>Closed</td><td class="dim">Approver / admin (Close)</td></tr>
    </tbody>
  </table>
  <p class="dim small">* "Awaiting approval" is a sub-state of <code>under_review</code> &mdash; same status, but with a <code>reviewed_at</code> timestamp filled in. The view page surfaces it as a distinct phase visually.</p>
</section>

<!-- ============ TYPES ============ -->
<section class="module" id="types">
  <h2><span class="num">03</span> The Six ECN Types</h2>

  <p>
    The module supports six standard ECN types. Each type has its own
    set of required fields appropriate for that kind of change. Pick
    the type that best fits the change you're documenting; if a
    change spans types, file separate ECNs.
  </p>

  <h3>📐 Drawing revision</h3>
  <p>
    Used when a drawing's revision letter / number is bumping. Fields:
    drawing number, from-rev, to-rev, change log. Affected entities
    are typically the inventory items that reference the drawing.
  </p>

  <h3>🌲 BOM change</h3>
  <p>
    Used when a parent item's Bill of Materials is changing &mdash;
    adding a line, removing a line, or modifying the qty / child of
    an existing line. Fields: parent item code, change kind
    (add/remove/modify), child item code, from-qty, to-qty.
  </p>

  <div class="callout warn">
    <div class="label">BOM CHANGES DON'T AUTO-APPLY</div>
    <p>This ECN module records the BOM change but doesn't automatically update the inv_bom_lines table when the ECN becomes effective. After the ECN goes effective, an authorised user must manually update the BOM via the BOM Designer (or import). The ECN is the authorisation; the BOM Designer is the execution.</p>
  </div>

  <h3>⚛️ Material substitution</h3>
  <p>
    Used when one approved material is being replaced by another
    (often for cost, availability, or performance reasons). Fields:
    from-material spec, to-material spec, reason code (cost /
    availability / performance / obsolescence / other), basis for
    equivalency (the engineering justification for why the
    substitute is acceptable).
  </p>

  <h3>⚙️ Process change</h3>
  <p>
    Used when how a part is made is changing &mdash; a new operation,
    different machine, different sequence, different cycle time.
    Fields: process step affected, current method, new method,
    whether first-piece approval is required after the change.
  </p>

  <h3>📄 Document revision</h3>
  <p>
    Used for controlled documents that aren't drawings &mdash; SOPs,
    work instructions, calibration procedures, inspection plans.
    Fields: document number, document title, from-rev, to-rev. Use
    Drawing revision (not Document revision) for actual drawings.
  </p>

  <h3>📏 Specification change</h3>
  <p>
    Used when a tolerance, performance specification, or acceptance
    criterion is changing &mdash; without the drawing itself changing
    rev. Fields: spec reference (drawing zone or clause), current
    spec, new spec, impact assessment. Often used alongside a
    drawing-rev ECN where the spec change is the SUBSTANCE of the
    drawing change.
  </p>
</section>

<!-- ============ ORIGINATE ============ -->
<section class="module" id="originate">
  <h2><span class="num">04</span> Originating an ECN</h2>

  <p>
    Anyone with <code>ecn.create</code> can originate an ECN.
    Typical originators: design engineers, manufacturing engineers,
    quality engineers responding to issues found in audit or
    customer feedback.
  </p>

  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Sidebar &rarr; ECN &rarr; "+ New ECN".</strong></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick the type.</strong> The form re-renders the type-specific fields when you change the dropdown.</p>
        <p class="sub">If the change spans multiple types, file separate ECNs &mdash; one per type. Cross-reference them in the description.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the title and the three free-text fields:</strong> business reason (why), description (what), disposition (how to handle existing stock).</p>
        <p class="sub">The title is what shows in the ECN list &mdash; make it scannable. "Bracket-A drawing Rev B for thicker mounting flange" is better than "Drawing update".</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the type-specific fields.</strong> The required ones are marked with an asterisk.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save as draft.</strong> You get an ECN number (<code>ECN-NNNNN</code>) and land on the view page.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Add at least one affected entity</strong> in the Affected Entities section. See &sect;05.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Submit for review</strong> when complete. The Submit button appears in the workflow actions panel at the top.</p>
      </div>
    </div>
  </div>

  <div class="callout note">
    <div class="label">DISPOSITION IS NOT OPTIONAL IN PRACTICE</div>
    <p>The disposition field is technically optional but you should fill it in for any change that affects parts already in inventory or WIP. "Use as-is", "Rework per drawing rev B", "Scrap", or "Sort-and-segregate" are common dispositions. Leaving it blank pushes the question to the approver to figure out, and they may send the ECN back.</p>
  </div>
</section>

<!-- ============ AFFECTED ============ -->
<section class="module" id="affected">
  <h2><span class="num">05</span> Affected Entities</h2>

  <p>
    An ECN must declare what it affects. The Affected Entities section
    on the view page lets you attach one or more references &mdash;
    inventory items, assets, or document references that this ECN
    changes. The list also tells future readers (and audits) "where
    to look for the consequences of this ECN".
  </p>

  <h3>Entity types</h3>
  <table>
    <thead><tr><th style="width:20%">TYPE</th><th>USE FOR</th></tr></thead>
    <tbody>
      <tr><td>Inventory item</td><td class="dim">The part numbers affected by the change. Code is the <code>inv_items.code</code> (e.g., <code>I-00042</code>).</td></tr>
      <tr><td>Asset</td><td class="dim">A specific tracked asset whose configuration is changing. Code is the asset_tag (e.g., <code>ASSET-00042</code>).</td></tr>
      <tr><td>Document</td><td class="dim">A controlled document being revised (when the ECN type isn't already document_rev). Code is the document number.</td></tr>
      <tr><td>Other</td><td class="dim">Anything else &mdash; a customer reference, a project ID, an external standard. Free text.</td></tr>
    </tbody>
  </table>

  <h3>Adding entities</h3>
  <p>
    Use the small "+ Add" form at the bottom of the Affected Entities
    section on the view page. Pick type, type the code, optionally
    add per-entity notes (e.g., "PN-1234 stock to be reworked, ~200
    units in WIP at effective date").
  </p>

  <p>
    The label is auto-resolved from the entity tables &mdash; if you
    type <code>I-00042</code> and that item exists, the row will
    display with the item's name. The label is captured at add time
    and frozen on the row, so even if the item is later renamed, the
    historical name stays.
  </p>

  <h3>Modifying entities mid-flow</h3>
  <p>
    Affected entities can be added or removed while the ECN is
    <em>draft</em> or <em>under_review</em>. Once approved, the list
    is locked. If you realise an ECN is missing an affected entity
    after approval, the right path is to file a new ECN that
    references the same change.
  </p>
</section>

<!-- ============ REVIEW ============ -->
<section class="module" id="review">
  <h2><span class="num">06</span> Review Stage</h2>

  <p>
    The reviewer's job is technical clarity: is this ECN complete
    and unambiguous enough that an approver could make a sound
    decision, and so that an operator could later execute it? The
    reviewer is not approving the change; they're approving the
    proposal's quality.
  </p>

  <h3>Picking up a submitted ECN</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Filter the ECN list to status = Submitted.</strong></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the ECN you want to review.</strong> The "Pick up for review" button appears in the workflow actions panel.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "Pick up for review".</strong> You become the assigned reviewer. Status flips to Under review.</p>
      </div>
    </div>
  </div>

  <h3>Making a decision</h3>
  <p>
    Once you've picked it up, the review-decision form appears with
    two buttons: <em>Pass review</em> and <em>Send back to draft</em>.
    Both take an optional comment.
  </p>

  <table>
    <thead><tr><th style="width:30%">DECISION</th><th>WHEN TO USE</th></tr></thead>
    <tbody>
      <tr><td><strong>Pass review</strong></td><td class="dim">The ECN is clear, complete, and ready for approval. Add a comment if you want to highlight anything for the approver. The ECN now waits for the approver.</td></tr>
      <tr><td><strong>Send back to draft</strong></td><td class="dim">Something needs work &mdash; missing information, unclear language, wrong type, missing entities. Add a comment explaining what to fix. The originator can edit the draft and resubmit.</td></tr>
    </tbody>
  </table>

  <div class="callout">
    <div class="label">SEND BACK GENEROUSLY</div>
    <p>Sending back to draft costs the originator a few minutes of revision. Approving a half-baked ECN costs the org weeks of confusion downstream when the change goes effective and nobody knows what to do. When in doubt, send back with clear comments rather than passing weakly.</p>
  </div>
</section>

<!-- ============ APPROVE ============ -->
<section class="module" id="approve">
  <h2><span class="num">07</span> Approval Stage</h2>

  <p>
    The approver's job is business judgment: should this change
    happen? Consider impact on production, customer, cost,
    schedule, quality. The reviewer has already verified the ECN is
    clear; the approver decides whether it's right.
  </p>

  <h3>Finding ECNs awaiting approval</h3>
  <p>
    The ECN list shows status &mdash; "Under review" with a passed
    reviewer indicates an ECN waiting for approval. The view page
    shows the workflow actions panel with the approval form.
  </p>

  <h3>The approval form</h3>
  <p>
    Three inputs: optional effective date, optional comment (required
    for rejection), and two buttons. The effective date can be set
    at approval time (most common) or left blank and set later via
    Mark effective.
  </p>

  <table>
    <thead><tr><th style="width:24%">DECISION</th><th>WHEN TO USE</th></tr></thead>
    <tbody>
      <tr><td><strong>Approve</strong></td><td class="dim">Change is accepted. Sets approver, approved_at timestamp, and (optionally) effective date. Status flips to Approved. If effective date is in the past or today, the next "Mark effective" step completes the cycle.</td></tr>
      <tr><td><strong>Reject</strong></td><td class="dim">Change is not accepted. Reason is required &mdash; explain enough so the originator knows whether the answer is "redo this" or "no, the underlying need can't be met this way". Status flips to Rejected (terminal).</td></tr>
    </tbody>
  </table>

  <h3>After approval</h3>
  <p>
    An approved ECN with no effective date set waits in the Approved
    state until someone marks it effective. An approved ECN with an
    effective date in the future is committed but not yet acting on
    production &mdash; useful for coordinating with a planned
    cutover.
  </p>

  <div class="callout warn">
    <div class="label">REJECTION IS TERMINAL</div>
    <p>A rejected ECN cannot be reopened. If new information emerges that would change the rejection, the originator should clone the ECN (copy its content into a new draft) and start the workflow over. The original rejection record remains for audit.</p>
  </div>
</section>

<!-- ============ EFFECTIVE ============ -->
<section class="module" id="effective">
  <h2><span class="num">08</span> Effective &amp; Closure</h2>

  <p>
    The approved &rarr; effective &rarr; closed cycle separates
    "approved on paper" from "active in production" from "all
    downstream actions complete".
  </p>

  <h3>Marking effective</h3>
  <p>
    When the effective date arrives (or when production is ready to
    cut over), the approver or an admin clicks "Mark effective" on
    the ECN. Status flips to Effective. From this moment the change
    is the active state of the affected entities.
  </p>

  <p>
    The ECN module does NOT automatically apply the change to the
    affected entities. After marking effective, the user (or another
    user with appropriate permissions) must:
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>For a drawing rev: update the drawing files (outside MagDyn) and bump the inv_items revision field.</li>
    <li>For a BOM change: update the BOM via the BOM Designer.</li>
    <li>For a material substitution: update the item's material spec field.</li>
    <li>For a process change: update the work instruction documents.</li>
    <li>For a document rev: update the document and any references.</li>
    <li>For a spec change: update the relevant inspection templates.</li>
  </ul>

  <p>
    Each of these execution steps is a normal operation in the
    relevant module; the ECN is the authorisation for them to happen.
  </p>

  <h3>Closing the ECN</h3>
  <p>
    Once all downstream actions are complete, close the ECN. Status
    becomes Closed (terminal). Closing is bookkeeping &mdash; it
    indicates the ECN is fully resolved and no further action is
    needed.
  </p>

  <div class="callout note">
    <div class="label">EFFECTIVE VS CLOSED</div>
    <p>Effective is "the change is now active". Closed is "everything that needed to happen because of this change has happened". For a simple drawing rev, effective and closed might be hours apart. For a complex multi-entity ECN with WIP rework and supplier notifications, effective and closed might be weeks apart.</p>
  </div>
</section>

<!-- ============ PERMISSIONS ============ -->
<section class="module" id="permissions">
  <h2><span class="num">09</span> Permissions Model</h2>

  <table>
    <thead><tr><th style="width:22%">PERMISSION</th><th>WHAT IT GATES</th></tr></thead>
    <tbody>
      <tr><td><code>ecn.view</code></td><td class="dim">See the ECN list, view individual ECNs (except drafts that aren't yours). Granted to most roles by default.</td></tr>
      <tr><td><code>ecn.create</code></td><td class="dim">Originate new ECNs and edit your own drafts. Typically engineers and senior technicians.</td></tr>
      <tr><td><code>ecn.review</code></td><td class="dim">Pick up submitted ECNs, perform review decisions. Typically senior engineers or QA leads.</td></tr>
      <tr><td><code>ecn.approve</code></td><td class="dim">Approve / reject ECNs at the final stage, mark effective, close. Typically engineering managers or change-control owners.</td></tr>
      <tr><td><code>ecn.manage</code></td><td class="dim">Admin override &mdash; edit any ECN in any state, force status changes if needed. Reserved for admin role.</td></tr>
      <tr><td><code>ecn.delete</code></td><td class="dim">Hard-delete ECNs (only drafts unless combined with manage). Use sparingly &mdash; cancel rather than delete unless the ECN was created in error.</td></tr>
    </tbody>
  </table>

  <h3>Separating roles</h3>
  <p>
    For stronger change-control, separate <code>create</code> from
    <code>review</code> from <code>approve</code> &mdash; no one user
    should be able to originate, review, and approve their own ECN.
    The system doesn't enforce this rule by code; it's a policy your
    role grants implement.
  </p>

  <h3>Recommended setup</h3>
  <table>
    <thead><tr><th style="width:24%">ROLE</th><th>TYPICAL ECN PERMISSIONS</th></tr></thead>
    <tbody>
      <tr><td>admin</td><td class="dim">all (view, create, review, approve, manage, delete)</td></tr>
      <tr><td>engineer</td><td class="dim">view, create</td></tr>
      <tr><td>senior_engineer</td><td class="dim">view, create, review</td></tr>
      <tr><td>engineering_manager</td><td class="dim">view, create, review, approve</td></tr>
      <tr><td>operator / inspector</td><td class="dim">view</td></tr>
      <tr><td>read_only</td><td class="dim">view</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">10</span> Common Workflows</h2>

  <h3>Drawing revision driven by customer feedback</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Customer reports an issue with a bracket dimension. Design engineer evaluates and decides a drawing rev is warranted.</li>
    <li>Engineer creates ECN, type = Drawing revision. Fills in drawing number, from-rev, to-rev, change log.</li>
    <li>Adds affected inventory items (the parts that reference the drawing) and any in-process work orders as affected entities.</li>
    <li>Sets disposition: "Rework existing WIP per Rev B; sorted-and-segregated finished stock to be re-inspected against Rev B before ship."</li>
    <li>Submits. Senior engineer reviews and passes. Engineering manager approves with effective date = next Monday.</li>
    <li>On Monday, engineering manager (or whoever runs the production cutover) marks the ECN effective. Drawing files in DMS get updated to Rev B. inv_items revision field updated.</li>
    <li>Production reworks WIP, QC re-inspects finished stock per the new spec. Once all of that is done, close the ECN.</li>
  </ol>

  <h3>Material substitution for cost reduction</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Procurement identifies a cheaper alternative material that meets specs. Asks engineering for substitution approval.</li>
    <li>Engineer creates ECN, type = Material substitution. From = current material spec, To = new material spec, Reason = Cost reduction, Equivalency basis = "Test report TR-2026-014: alternative material exceeds spec for tensile and yield".</li>
    <li>Adds affected inventory items.</li>
    <li>Submits. Quality reviews. Engineering manager approves with effective date when current stock of original material is depleted.</li>
    <li>On effective date, item material spec field is updated and procurement starts ordering the alternative.</li>
  </ol>

  <h3>Process change requiring first-piece approval</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Manufacturing engineer wants to introduce a new fixture that speeds up an operation.</li>
    <li>ME creates ECN, type = Process change. Process step = Op 30 milling, From = "manual setup with parallels", To = "dedicated fixture FXT-105", Requires FPA = Yes.</li>
    <li>Submits. ME lead reviews. Operations manager approves.</li>
    <li>On effective date, first piece is produced with the new fixture, QC performs FPA, results recorded as an Inspection record. If FPA passes, run rate resumes; if not, file a counter-ECN to revert.</li>
  </ol>

  <h3>Withdrawing an unsubmitted draft</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>You started a draft ECN and decided not to pursue it.</li>
    <li>Open the ECN view. Click "Cancel ECN" in the workflow actions. Optional reason in the input.</li>
    <li>Status becomes Cancelled (terminal). The ECN remains visible in the list for audit but is dead.</li>
    <li>Alternative: if no one else has seen the draft, an admin can hard-delete it via the "Delete draft" button.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">11</span> Troubleshooting</h2>

  <h3>I can't submit my ECN &mdash; the button says "Add at least one affected entity"</h3>
  <p>
    The system requires at least one entity in the Affected Entities
    section before submission. Add it via the "+ Add" form on the
    view page, then submit.
  </p>

  <h3>I picked the wrong ECN type and need to change it</h3>
  <p>
    While the ECN is in Draft state, click Edit and change the type
    in the dropdown. The type-specific fields will re-render; you'll
    need to re-fill them. After submission the type is locked &mdash;
    cancel and start over if it's still wrong.
  </p>

  <h3>The reviewer sent my ECN back &mdash; what now?</h3>
  <p>
    The ECN is back in Draft. Read the reviewer's comment in the
    history panel, edit the ECN to address the feedback, then
    resubmit. The history records both the original review-fail and
    the resubmission, so the trail is complete.
  </p>

  <h3>I approved an ECN with the wrong effective date</h3>
  <p>
    The effective date is editable only by admins (via manage)
    after approval. Ask an admin to update it &mdash; or, if you
    haven't yet marked effective, you can re-approve setting the
    correct date. If already effective, file a small companion ECN
    documenting the date correction.
  </p>

  <h3>I see an ECN in the list but can't open it</h3>
  <p>
    Other people's drafts are hidden &mdash; the ECN list filters
    out drafts that aren't yours unless you have <code>ecn.manage</code>.
    If you see an ECN you can't open, it's likely a draft owned by
    someone else. Ask them to submit it (or cancel it) so it
    appears in everyone's list.
  </p>

  <h3>Can I link an ECN to another ECN?</h3>
  <p>
    Not directly &mdash; there's no native ECN-to-ECN linkage in
    this version. Use the Description field to reference related
    ECN numbers ("Supersedes ECN-00042; companion to ECN-00045").
    Future feature, if needed.
  </p>

  <h3>The BOM didn't update after the ECN went effective</h3>
  <p>
    Correct &mdash; this module records the change and authorises
    it, but doesn't auto-apply BOM updates. After marking the BOM
    Change ECN effective, an authorised user must update the BOM
    via the BOM Designer (or import). This separation is
    intentional: the ECN is the decision, the BOM Designer is the
    execution, and they're operated by different roles in many
    shops.
  </p>
</section>

<div class="foot">
    <div>ECN &middot; Operator Manual &middot; v1.0</div>
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
