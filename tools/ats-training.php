<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'ATS · Operator Manual';
$current_page  = 'ats-training.php';
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
.main { padding: 32px 40px 60px; max-width: 920px; }
.hero { margin-bottom: 36px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
.hero .eyebrow { font-size: 11px; color: var(--primary); letter-spacing: 0.12em; text-transform: uppercase; font-weight: 700; margin-bottom: 12px; }
.hero h1 { font-size: 32px; font-weight: 600; letter-spacing: -0.02em; margin: 0 0 14px; color: var(--text); }
.hero h1 strong { color: var(--primary); font-weight: 700; }
.hero .lede { font-size: 15px; line-height: 1.7; color: var(--muted); max-width: 720px; }
section.module { margin: 0 0 48px; padding-top: 8px; }
section.module h2 { font-size: 20px; font-weight: 600; letter-spacing: -0.01em; margin: 0 0 18px; color: var(--text); display: flex; align-items: baseline; gap: 12px; }
section.module h2 .num { font-size: 11px; font-weight: 700; color: var(--text-very-light); letter-spacing: 0.1em; }
section.module h3 { font-size: 14px; font-weight: 600; margin: 24px 0 10px; color: var(--text); }
section.module p { font-size: 14px; line-height: 1.65; color: var(--text); margin: 0 0 14px; max-width: 760px; }
section.module ul, section.module ol { margin: 0 0 18px; padding-left: 22px; color: var(--text); }
section.module li { font-size: 14px; line-height: 1.65; margin-bottom: 4px; }
.callout { border-left: 3px solid; padding: 12px 16px; margin: 18px 0; border-radius: 0 6px 6px 0; }
.callout.note { border-color: var(--primary); background: rgba(99,102,241,0.04); }
.callout.warn { border-color: #ea580c; background: rgba(234,88,12,0.04); }
.callout.danger { border-color: var(--danger); background: rgba(239,68,68,0.04); }
.callout.tip  { border-color: #16a34a; background: rgba(22,163,74,0.04); }
.callout .label { font-size: 10px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 6px; }
.callout.note .label { color: var(--primary); }
.callout.warn .label { color: #ea580c; }
.callout.danger .label { color: var(--danger); }
.callout.tip .label { color: #16a34a; }
.callout p { margin: 4px 0; font-size: 13.5px; }
table { width: 100%; border-collapse: collapse; margin: 14px 0 18px; font-size: 13px; }
table th, table td { border: 1px solid var(--border); padding: 8px 12px; text-align: left; vertical-align: top; }
table thead th { background: var(--surface-alt); font-size: 11px; letter-spacing: 0.05em; text-transform: uppercase; color: var(--text-light); font-weight: 600; }
table tbody td.dim { color: var(--muted); }
.small { font-size: 12px; }
.dim { color: var(--muted); }
.steps { counter-reset: step; list-style: none; padding-left: 0; }
.steps li { counter-increment: step; padding-left: 36px; position: relative; margin-bottom: 14px; }
.steps li::before { content: counter(step); position: absolute; left: 0; top: 0; width: 24px; height: 24px; line-height: 24px; border-radius: 50%; background: var(--primary); color: white; text-align: center; font-size: 12px; font-weight: 700; }
.steps .step-body { font-size: 14px; line-height: 1.6; color: var(--text); }
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
            <div class="brand-title">ATS Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#lifecycle">Status Lifecycle</a></li>
            <li><a href="#how-created">How an ATS is Created</a></li>
            <li><a href="#anatomy">Anatomy of an ATS</a></li>
            <li><a href="#finalize">Finalising / Sending to Billing</a></li>
            <li><a href="#resend">Resending</a></li>
            <li><a href="#cancel">Cancelling</a></li>
            <li><a href="#locked">When Billing Locks an ATS</a></li>
            <li><a href="#errors">Reading Push Errors</a></li>
            <li><a href="#permissions">Permissions</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Authorisation To Ship</div>
    <h1>From job card to <strong>billing-ready</strong>.</h1>
    <p class="lede">
        The ATS module records every Authorisation To Ship and mirrors
        it to the billing app. Every job card produces exactly one ATS,
        numbered automatically. When you finalise it, the system pushes
        the header and line to billing — and from then on, everything
        the billing side does (invoice, ship, cancel) is tracked back
        here.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    An <strong>ATS</strong> &mdash; Authorisation To Ship &mdash;
    represents the moment when MagDyn has finished its production
    work on a job card and is handing the goods over to billing for
    invoicing and shipping. It carries the PO number, the SO line, the
    item, and the quantity that's ready to go out the door.
  </p>

  <h3>The key invariants</h3>
  <ul>
    <li><strong>One job card produces exactly one ATS.</strong> No aggregation across cards, no splitting a card across ATSes.</li>
    <li><strong>The ATS number is auto-assigned.</strong> You never type it. The system uses the <code>ats</code> code sequence to issue numbers like <code>ATS-00042</code>.</li>
    <li><strong>One PO per ATS.</strong> A trivial consequence of the 1:1 rule &mdash; one card has one PO, so the ATS has one PO.</li>
    <li><strong>Pushes to billing are idempotent.</strong> You can resend the same ATS as many times as you like. Billing keys on the ATS id, so resends are safe.</li>
  </ul>

  <div class="callout note">
    <div class="label">WHERE ATSES COME FROM</div>
    <p>You don't create an ATS directly. They come into being when you complete the ATS step (Step 4) on a job card. Completing that step issues an ATS number, creates the ATS row, and moves the produced quantity into the SHP location all at once. The ATS module is for what you do <em>after</em> that &mdash; reviewing, finalising, resending, cancelling.</p>
  </div>
</section>

<!-- ============ LIFECYCLE ============ -->
<section class="module" id="lifecycle">
  <h2><span class="num">02</span> Status Lifecycle</h2>

  <p>An ATS walks through a small set of statuses. Most ATSes go <strong>Draft &rarr; Pushed</strong> and stop there until billing closes its end.</p>

  <h3>The states</h3>
  <table>
    <thead><tr><th style="width:18%">STATUS</th><th>MEANING</th></tr></thead>
    <tbody>
      <tr><td><strong>Draft</strong></td><td class="dim">Just created from a job card. Not yet sent to billing. Editable. The Finalize button is available.</td></tr>
      <tr><td><strong>Pushed</strong></td><td class="dim">Sent to billing successfully. Editable, but any edit flips it back to Draft (because the billing side now holds a different version). Resend brings it back to Pushed.</td></tr>
      <tr><td><strong>Cancelled</strong></td><td class="dim">Either cancelled locally (never pushed; no billing call) or cancelled on billing (via <code>op=cancel</code>, billing marks its copy <code>superseded</code>). Reopenable if billing is still in <code>applied</code> state.</td></tr>
      <tr><td><strong>Locked</strong></td><td class="dim">Billing has advanced past <code>applied</code> &mdash; it's invoiced, shipped, or otherwise downstream. No more edits, no pushes, no cancels. Reconcile manually if anything's wrong.</td></tr>
    </tbody>
  </table>

  <h3>The transitions</h3>
  <table>
    <thead><tr><th style="width:24%">FROM</th><th style="width:24%">TO</th><th>HOW</th></tr></thead>
    <tbody>
      <tr><td>Draft</td><td>Pushed</td><td class="dim">Finalize / Send to billing (op=upsert succeeds)</td></tr>
      <tr><td>Draft</td><td>Cancelled</td><td class="dim">Cancel button (local-only &mdash; never pushed)</td></tr>
      <tr><td>Pushed</td><td>Draft</td><td class="dim">Automatically, when you edit the ATS or the underlying job card</td></tr>
      <tr><td>Pushed</td><td>Cancelled</td><td class="dim">Cancel button (calls op=cancel on billing)</td></tr>
      <tr><td>Pushed</td><td>Locked</td><td class="dim">A push or cancel returns 409 wrong_status (billing has moved on)</td></tr>
      <tr><td>Cancelled</td><td>Draft</td><td class="dim">Reopen button (only available before billing has invoiced)</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ HOW CREATED ============ -->
<section class="module" id="how-created">
  <h2><span class="num">03</span> How an ATS is Created</h2>

  <p>You don't create an ATS from <code>/erp/ats.php</code>. You create one by completing the ATS step on a job card.</p>

  <ol class="steps">
    <li><div class="step-body">Open the job card and advance through QC and the production steps until you reach <strong>Step 4 (ATS)</strong>.</div></li>
    <li><div class="step-body">The ATS step shows "Auto-assigned on Complete ATS" where the number will appear. There is no input field &mdash; you can't type an ATS number anywhere.</div></li>
    <li><div class="step-body">Click <kbd>✓ Complete ATS</kbd>. Three things happen atomically:
        <p class="sub">a. A new ATS row is created with a fresh <code>ATS-NNNNN</code> number.</p>
        <p class="sub">b. The job card's submitted quantity moves into the <code>SHP</code> location.</p>
        <p class="sub">c. The job card status advances to <code>billing_pending</code>.</p>
    </div></li>
    <li><div class="step-body">The job-card page now shows the ATS number with a link to its detail page.</div></li>
  </ol>

  <div class="callout warn">
    <div class="label">EDITS RE-USE THE SAME ATS</div>
    <p>If you re-save the ATS step (for instance, to update a quantity), the SAME ATS is updated &mdash; you don't get a second one. The line's quantity changes in place. If the ATS was already <strong>Pushed</strong> at that point, it flips back to <strong>Draft</strong> and you'll need to resend.</p>
  </div>
</section>

<!-- ============ ANATOMY ============ -->
<section class="module" id="anatomy">
  <h2><span class="num">04</span> Anatomy of an ATS</h2>

  <p>Open <code>/erp/ats.php</code> and click an ATS number to see its detail page. There are three sections.</p>

  <h3>Header</h3>
  <p>Fixed-by-the-system fields: <strong>ATS No</strong>, <strong>PO No</strong>, <strong>ATS Date</strong>. The status pill. Free-text fields you can edit: <strong>Ref No</strong>, <strong>Notes</strong>, <strong>Date</strong>.</p>
  <p>The header also shows the <strong>billing side's reflection</strong>: the billing app's own ATS number (different from MagDyn's), its current billing-side status, and timestamps for the most recent push.</p>

  <h3>Lines</h3>
  <p>For each ATS, there's exactly one line: the item code, item name, SO line number, quantity, and the source job card with a deep link.</p>

  <h3>Push history</h3>
  <p>An audit table of every push attempt for this ATS. Each row shows when, which op, the HTTP status, success/failure, who clicked, and the response body for diagnosis. The panel only appears once at least one push has been attempted.</p>

  <div class="callout note">
    <div class="label">EDITING THE HEADER</div>
    <p>The <strong>Edit</strong> button on the view page lets you change Ref No, Notes, and ATS Date. You can't edit ATS No or PO No &mdash; those are derived from the job card. Editing the header on a Pushed ATS flips it back to Draft so the billing side stays in sync after the next resend.</p>
  </div>
</section>

<!-- ============ FINALIZE ============ -->
<section class="module" id="finalize">
  <h2><span class="num">05</span> Finalising / Sending to Billing</h2>

  <p>On a Draft ATS, the primary button on the view page is <kbd>↑ Finalize / Send to billing</kbd>.</p>

  <h3>What happens when you click it</h3>
  <ol class="steps">
    <li><div class="step-body">MagDyn builds the payload: header fields + the line array.</div></li>
    <li><div class="step-body">It POSTs to the billing app's ATS endpoint with the shared secret in the Authorization header.</div></li>
    <li><div class="step-body">Billing resolves the SO by your PO number, resolves each line's item against its product catalogue, and creates the matching ATS on its side.</div></li>
    <li><div class="step-body">Billing returns its own ATS id and number. MagDyn stores those and flips local status to <strong>Pushed</strong>.</div></li>
    <li><div class="step-body">The push history table gets a new row with the full response for audit.</div></li>
  </ol>

  <div class="callout tip">
    <div class="label">SAFE TO RETRY</div>
    <p>The billing app keys on MagDyn's ATS id. If you click Finalize twice (e.g. the page hangs and you retry), billing won't create a second ATS &mdash; it just updates the existing one. The response says <code>action: "updated"</code> for the second click instead of <code>"created"</code>. Either way, no harm done.</p>
  </div>

  <h3>When the button is hidden</h3>
  <ul>
    <li><strong>Cancelled or locked</strong> ATS &mdash; there's nothing to send.</li>
    <li><strong>Billing integration not configured</strong> &mdash; the URL and bearer token aren't set in <code>config/app.config.php</code>. A "billing not configured" pill replaces the button. Ask your admin.</li>
    <li><strong>You don't have <code>ats.finalize</code> permission.</strong> Same fix &mdash; ask your admin.</li>
  </ul>
</section>

<!-- ============ RESEND ============ -->
<section class="module" id="resend">
  <h2><span class="num">06</span> Resending</h2>

  <p>Once an ATS is Pushed, the primary button changes to <kbd>↻ Resend to billing</kbd>. Click it any time you want billing's copy refreshed with MagDyn's current state.</p>

  <h3>When you'd resend</h3>
  <ul>
    <li>You edited the ATS notes / ref no / date and want billing to see the change.</li>
    <li>You edited the underlying job card's submitted quantity. The ATS auto-flipped back to Draft &mdash; resend brings it to Pushed again with the new qty.</li>
    <li>Billing called you saying "I don't see ATS X" &mdash; resending is the fastest way to confirm whether it's a billing-side data issue (you'll see a specific error like <code>so_not_found</code>) or just a missed sync.</li>
    <li>A transient failure (network blip) &mdash; the last push history row will show a <code>transport</code> error. Resend.</li>
  </ul>

  <div class="callout note">
    <div class="label">WHAT DOES A RESEND ACTUALLY DO?</div>
    <p>Internally, Finalize and Resend are the same call &mdash; both call billing's <code>op=upsert</code>. They appear as different buttons only because the user mental-model differs: "send this for the first time" vs "send this again". Billing handles the difference (<code>action: "created"</code> vs <code>"updated"</code>) and tells you in the response.</p>
  </div>
</section>

<!-- ============ CANCEL ============ -->
<section class="module" id="cancel">
  <h2><span class="num">07</span> Cancelling</h2>

  <p>Sometimes an ATS shouldn't have been raised, or the order changed before it was invoiced. The Cancel button on the view page handles two cases differently.</p>

  <h3>Local-only cancel</h3>
  <p>If the ATS is still <strong>Draft</strong> (never pushed to billing), the button is labelled <kbd>✕ Cancel locally</kbd>. No network call. The local status flips to Cancelled. Billing never knew about the ATS, so there's nothing to tell it.</p>

  <h3>Billing cancel</h3>
  <p>If the ATS is <strong>Pushed</strong>, the button is <kbd>✕ Cancel on billing</kbd>. It calls billing's <code>op=cancel</code>, which marks the billing-side ATS as <code>superseded</code>. On success, local status flips to Cancelled.</p>

  <h3>Reopening a cancelled ATS</h3>
  <p>Mistakes happen. A Cancelled ATS shows a <kbd>↶ Reopen</kbd> button. Clicking it flips the status back to Draft so you can edit and resend. Reopen is blocked once billing has invoiced or shipped &mdash; at that point the ATS is locked, not cancelled.</p>

  <div class="callout danger">
    <div class="label">WHEN CANCEL FAILS</div>
    <p>If billing returns <strong>409 wrong_status</strong> on cancel, it means billing has already invoiced or shipped this ATS. The local status flips to <strong>Locked</strong>, not Cancelled. You can't undo a shipped ATS through this workflow &mdash; coordinate with the billing team for a credit note or return process.</p>
  </div>
</section>

<!-- ============ LOCKED ============ -->
<section class="module" id="locked">
  <h2><span class="num">08</span> When Billing Locks an ATS</h2>

  <p>An ATS becomes <strong>Locked</strong> when MagDyn detects that billing has advanced past <code>applied</code>. This is detected automatically &mdash; any push or cancel that returns 409 wrong_status from billing flips the local status to Locked. No further actions are possible from MagDyn.</p>

  <h3>What "past applied" means on the billing side</h3>
  <ul>
    <li><strong>approved</strong> &mdash; billing's reviewer approved the ATS for invoicing.</li>
    <li><strong>invoiced</strong> &mdash; an invoice has been issued.</li>
    <li><strong>shipped</strong> &mdash; the goods are out the door.</li>
    <li><strong>superseded</strong> &mdash; another ATS replaced this one in billing's records.</li>
  </ul>

  <h3>Practical effect</h3>
  <p>A Locked ATS is read-only in MagDyn. The Edit / Finalize / Resend / Cancel buttons all hide. The view page is still useful for audit &mdash; the push history shows everything that happened. To make changes, the operator must coordinate with billing manually (credit notes, return tickets, etc.).</p>
</section>

<!-- ============ ERRORS ============ -->
<section class="module" id="errors">
  <h2><span class="num">09</span> Reading Push Errors</h2>

  <p>When a push fails, the red flash banner shows the HTTP status, the billing app's error code, and a short message. The push history panel below shows the full response for diagnosis. Here are the common ones.</p>

  <table>
    <thead><tr><th style="width:24%">CODE</th><th>WHAT IT MEANS</th><th style="width:32%">WHAT TO DO</th></tr></thead>
    <tbody>
      <tr><td><code>so_not_found</code></td><td class="dim">The PO number on this ATS doesn't match any SO on the billing side.</td><td class="dim">Ask billing to confirm the SO was entered for this PO. Resend.</td></tr>
      <tr><td><code>item_not_found</code></td><td class="dim">A line's item code has no matching product in billing's catalogue.</td><td class="dim">Billing needs to add the product. Then resend.</td></tr>
      <tr><td><code>so_line_not_found</code></td><td class="dim">No SO line in the PO matches by line_no or by product.</td><td class="dim">Confirm the SO line number on the billing side. Edit the job card if needed (this flips ATS back to Draft).</td></tr>
      <tr><td><code>wrong_status</code></td><td class="dim">Billing's ATS is past <code>applied</code>. The local ATS becomes Locked automatically.</td><td class="dim">Manual reconciliation. No further pushes possible.</td></tr>
      <tr><td><code>validation</code></td><td class="dim">Bad payload &mdash; missing or invalid field.</td><td class="dim">Tell your admin. This shouldn't happen in normal use.</td></tr>
      <tr><td><code>bad_token</code></td><td class="dim">The shared secret is wrong or missing.</td><td class="dim">Admin needs to fix <code>billing_integration.bearer_token</code>.</td></tr>
      <tr><td><code>transport</code></td><td class="dim">Network, DNS, or TLS error before billing could respond.</td><td class="dim">Wait a moment and resend. If it persists, tell your admin.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ PERMISSIONS ============ -->
<section class="module" id="permissions">
  <h2><span class="num">10</span> Permissions</h2>

  <p>Four permissions control what you can do in the ATS module.</p>
  <table>
    <thead><tr><th style="width:24%">PERMISSION</th><th>WHAT IT GRANTS</th></tr></thead>
    <tbody>
      <tr><td><code>ats.view</code></td><td class="dim">See the ATS list and detail pages. Required for the module to appear in your menu at all.</td></tr>
      <tr><td><code>ats.edit</code></td><td class="dim">Change the editable header fields (Ref No, Notes, Date).</td></tr>
      <tr><td><code>ats.finalize</code></td><td class="dim">Click Finalize and Resend &mdash; the buttons that push to billing.</td></tr>
      <tr><td><code>ats.cancel</code></td><td class="dim">Cancel an ATS (locally or on billing). Also required to reopen a cancelled ATS.</td></tr>
    </tbody>
  </table>
  <p class="dim small">By default <code>admin</code> role has all four. Other roles need explicit grants.</p>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">11</span> Common Workflows</h2>

  <h3>A. Normal happy path</h3>
  <ol class="steps">
    <li><div class="step-body">Job card moves through production. Operator completes Step 4 (ATS). An ATS-NNNNN number is auto-assigned. Status: <strong>Draft</strong>.</div></li>
    <li><div class="step-body">Operator (or supervisor) opens the ATS in <code>/erp/ats.php</code>. Reviews the line, optionally fills in Ref No / Notes.</div></li>
    <li><div class="step-body">Operator clicks <kbd>↑ Finalize / Send to billing</kbd>. Billing accepts. Status: <strong>Pushed</strong>.</div></li>
    <li><div class="step-body">Billing invoices and ships. Their copy moves past <code>applied</code>. Next time anyone tries to push or cancel the MagDyn ATS, it flips to <strong>Locked</strong>. That's the normal end-state.</div></li>
  </ol>

  <h3>B. Quantity changed after push</h3>
  <ol class="steps">
    <li><div class="step-body">ATS is Pushed. The job card needs a qty adjustment.</div></li>
    <li><div class="step-body">Operator opens the job card, edits Step 4. The ATS automatically flips to <strong>Draft</strong>.</div></li>
    <li><div class="step-body">Operator opens the ATS, clicks <kbd>↻ Resend to billing</kbd>. Billing returns <code>action: "updated"</code>. Status: <strong>Pushed</strong> again, with the new qty.</div></li>
  </ol>

  <h3>C. Wrong ATS, never pushed</h3>
  <ol class="steps">
    <li><div class="step-body">Job card was completed by mistake. The ATS is in Draft, never sent.</div></li>
    <li><div class="step-body">Open the ATS. Click <kbd>✕ Cancel locally</kbd>. No network call. Status: <strong>Cancelled</strong>.</div></li>
    <li><div class="step-body">If you later realise it wasn't a mistake, click <kbd>↶ Reopen</kbd>. Status: Draft. Resume the workflow.</div></li>
  </ol>

  <h3>D. Wrong ATS, already pushed</h3>
  <ol class="steps">
    <li><div class="step-body">ATS is Pushed. Billing hasn't yet invoiced it.</div></li>
    <li><div class="step-body">Click <kbd>✕ Cancel on billing</kbd>. Confirm. Billing marks its copy <code>superseded</code>. Status here: <strong>Cancelled</strong>.</div></li>
    <li><div class="step-body">If billing had already invoiced (you get <code>wrong_status</code>), status flips to <strong>Locked</strong>. Coordinate manually for a credit note.</div></li>
  </ol>

  <h3>E. Billing-side error you can fix</h3>
  <ol class="steps">
    <li><div class="step-body">Finalize fails with <code>so_not_found</code> (the PO doesn't exist on the billing side).</div></li>
    <li><div class="step-body">Tell the billing team to enter the SO for that PO. The ATS stays in Draft.</div></li>
    <li><div class="step-body">Once billing confirms the SO is in their system, return to MagDyn and click Finalize again. It should succeed.</div></li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">12</span> Troubleshooting</h2>

  <h3>The Finalize button isn't there</h3>
  <p>Either you don't have <code>ats.finalize</code>, the ATS isn't in a state where finalising makes sense (already Pushed, Cancelled, or Locked), or the integration isn't configured. Look for a pink "billing not configured" pill on the right of the action bar &mdash; that's the configuration case.</p>

  <h3>The ATS keeps flipping back to Draft after I resend</h3>
  <p>Something is editing it between resends. Most often this is the job-card Step 4 getting re-saved, which always flips its ATS back to Draft. Find the last edit in the push-history table and the job card's event log; whoever's editing should coordinate with whoever's pushing.</p>

  <h3>I can't edit a Pushed ATS</h3>
  <p>You can &mdash; the Edit button is there if you have <code>ats.edit</code>. Editing flips it back to Draft on purpose, so the next resend reflects the change. If you don't see Edit, you don't have the permission, or the ATS is Locked.</p>

  <h3>The push history shows OK but billing says they don't have it</h3>
  <p>Check the billing-side ATS number in the header (the "Billing ATS" field). If it's blank, the push never reached billing in a clean state &mdash; expand the push-history response to see what billing actually returned. If it's filled in, billing definitely has it &mdash; they may be looking in the wrong place (the billing ATS number is different from MagDyn's).</p>

  <h3>I'm getting transport errors repeatedly</h3>
  <p>This is a network or TLS issue between MagDyn and the billing host. Resend once or twice &mdash; transient blips are common. If it persists, tell your admin: the billing host might be down, or the bearer token might be wrong (which often surfaces as transport-looking failures on TLS handshakes).</p>

  <h3>Can I delete an ATS?</h3>
  <p>No, not from the UI. Cancel it instead &mdash; that's the audit-safe way to mark an ATS as not-shipping. Deleting would break references back from the source job card and lose the audit trail.</p>
</section>

<div class="foot">
    <span>MagDyn ATS Manual &middot; v1.0</span>
    <span>For developers: see <code>docs/ats_integration.md</code> and <code>includes/_ats.php</code></span>
</div>

</main>
</div>

<?php include 'includes/foot.php'; ?>
