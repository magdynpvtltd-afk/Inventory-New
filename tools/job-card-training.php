<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Job Card · Operator Manual';
$current_page  = 'job-card-training.php';
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

/* Process flow diagram (SVG container) — bordered card, scrolls
   horizontally on narrow screens. */
.flow-card { margin: 18px 0; padding: 18px; border: 1px solid var(--border); border-radius: 8px; background: white; overflow-x: auto; }
.flow-card svg { display: block; min-width: 760px; }

/* Linkage table — what writes/reads what. Higher density than the
   default table style so the eye can quickly scan it. */
.linkages td, .linkages th { padding: 6px 10px; font-size: 12.5px; }
.linkages td.mono { font-size: 12px; }
.role-pill { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 2px 7px; border-radius: 999px; }
.role-pill.acc  { background: #fef3c7; color: #92400e; }
.role-pill.qc   { background: #ede9fe; color: #5b21b6; }
.role-pill.prod { background: #dbeafe; color: #1e40af; }
.role-pill.ats  { background: #d1fae5; color: #047857; }
.role-pill.bill { background: #fce7f3; color: #9d174d; }
.role-pill.sys  { background: #e5e7eb; color: #374151; }
</style>

<div class="layout">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark"><div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;letter-spacing:-0.02em;">MD</div></div>
        <div class="brand-text">
            <div class="brand-title">Job Card Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>

    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#flow">Process Flow</a></li>
            <li><a href="#step1">Step 1 &middot; Sales Order</a></li>
            <li><a href="#step2">Step 2 &middot; QC / MIR</a></li>
            <li><a href="#step3">Step 3 &middot; Production</a></li>
            <li><a href="#step4">Step 4 &middot; ATS</a></li>
            <li><a href="#step5">Step 5 &middot; Billing &amp; Close</a></li>
            <li><a href="#partial">Partial Production Split</a></li>
            <li><a href="#qty-changes">Qty Changes &amp; Re-edits</a></li>
            <li><a href="#amendments">Amendments from Billing</a></li>
            <li><a href="#terminal">Cancelled &amp; Closed Cards</a></li>
            <li><a href="#linkages">Module Linkages</a></li>
            <li><a href="#api">API Endpoints</a></li>
            <li><a href="#roles">Roles &amp; Permissions</a></li>
            <li><a href="#notifications">Notifications</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Approval to ship</div>
    <h1>From sales order to dispatch &mdash; <strong>five gates, one card</strong>.</h1>
    <p class="lede">
        The Job Card module is MagDyn's approval-to-ship workflow. Every
        sales-order line that needs production becomes a job card that
        moves through five role-gated steps: Sales Order, QC, Production,
        ATS, and Billing close. Each step is owned by a different team,
        notifications fire between steps, and inventory + shipment
        records are written automatically at the right moments.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    A <em>job card</em> represents one production task: take an item from
    its sub-assemblies, get it through QC, produce it, get authority to
    ship it, then close it out when billing raises an invoice. One
    sales-order line is one job card.
  </p>

  <h3>Why this exists</h3>
  <p>
    Without this module, the steps between &ldquo;sales received an
    order&rdquo; and &ldquo;goods left the building&rdquo; live in
    spreadsheets and email threads. The job card consolidates that into
    a single auditable record &mdash; status visible at a glance, each
    step's owner is unambiguous, no order falls through the cracks, and
    the inventory and shipment side-effects happen automatically as the
    card progresses.
  </p>

  <h3>What's auto-populated vs human-entered</h3>
  <table>
    <thead><tr><th style="width:30%">FIELDS</th><th>SOURCE</th></tr></thead>
    <tbody>
      <tr>
        <td>Part No, Part Name</td>
        <td class="dim">Derived at render time from <code>inv_items</code> via the item code the billing system pushes &mdash; never stored on the job card itself, so a part-name change in inventory flows through automatically.</td>
      </tr>
      <tr>
        <td>PO #, Line #, PO Qty, Delivery Date, Customer, Location, ACK #, Drop Ship flag</td>
        <td class="dim">All pushed in by the external billing system via API on Step 1. No human types these into MagDyn.</td>
      </tr>
      <tr>
        <td>ATS Needed, PPM, QN, Batch (QC), MIR Notes</td>
        <td class="dim">QC operator fills these in Step 2.</td>
      </tr>
      <tr>
        <td>Submitted Qty, Batch (Production), Boxes (No/Type/Size/Weight/Qty)</td>
        <td class="dim">Production operator fills these in Step 3. Submitting less than the PO qty triggers a partial-split modal &mdash; see &sect;08.</td>
      </tr>
      <tr>
        <td>ATS Number</td>
        <td class="dim">ATS team enters this in Step 4. On save, the produced qty moves to the <code>SHP</code> location as ready-to-ship stock.</td>
      </tr>
      <tr>
        <td>Invoice No, Invoice Date, Linked Shipment</td>
        <td class="dim">Billing system pushes these via API on Step 5. MagDyn auto-creates the inv_shipments + inv_shipment_lines records and decrements SHP stock atomically.</td>
      </tr>
    </tbody>
  </table>

  <h3>Status states</h3>
  <table>
    <thead><tr><th style="width:22%">STATUS</th><th>WHO OWNS IT</th><th>NEXT TRIGGER</th></tr></thead>
    <tbody>
      <tr><td><code>qc_pending</code></td>      <td class="dim">QC team</td>                <td class="dim">QC saves Step 2</td></tr>
      <tr><td><code>prod_pending</code></td>    <td class="dim">Production team</td>        <td class="dim">Production saves Step 3</td></tr>
      <tr><td><code>ats_pending</code></td>     <td class="dim">ATS team</td>               <td class="dim">ATS saves Step 4 (stock moves to SHP)</td></tr>
      <tr><td><code>billing_pending</code></td> <td class="dim">External billing system</td><td class="dim">Billing pushes invoice via API</td></tr>
      <tr><td><code>closed</code></td>          <td class="dim">&mdash;</td>                <td class="dim">Terminal</td></tr>
      <tr><td><code>cancelled</code></td>       <td class="dim">&mdash;</td>                <td class="dim">Terminal (admin override only)</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ PROCESS FLOW ============ -->
<section class="module" id="flow">
  <h2><span class="num">02</span> Process Flow</h2>

  <p>
    The diagram below shows the full lifecycle &mdash; left to right
    through the five steps, with auto-triggered side effects on inventory
    and shipment tables drawn as branches off the main flow. Solid lines
    are status transitions; dashed lines are writes to other modules.
  </p>

  <div class="flow-card">
    <svg viewBox="0 0 1000 540" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Job Card process flow">
      <defs>
        <marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#475569"/>
        </marker>
        <marker id="arrD" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#94a3b8"/>
        </marker>
      </defs>

      <!-- Background bands for swimlanes -->
      <rect x="0"   y="0"   width="1000" height="60"  fill="#f8fafc"/>
      <rect x="0"   y="380" width="1000" height="160" fill="#fef9f3"/>

      <!-- Swimlane labels -->
      <text x="14" y="36"  font-family="ui-monospace,Menlo,monospace" font-size="11" fill="#475569" font-weight="700">EXTERNAL SYSTEMS</text>
      <text x="14" y="412" font-family="ui-monospace,Menlo,monospace" font-size="11" fill="#92400e" font-weight="700">INVENTORY / SHIPMENT SIDE-EFFECTS</text>

      <!-- External: Billing system box -->
      <rect x="40" y="10" width="170" height="40" rx="6" fill="#fff" stroke="#cbd5e1" stroke-width="1.5"/>
      <text x="125" y="35" text-anchor="middle" font-family="system-ui,sans-serif" font-size="13" font-weight="600" fill="#0f172a">Billing system</text>

      <!-- Inv_items reference -->
      <rect x="290" y="10" width="170" height="40" rx="6" fill="#fff" stroke="#cbd5e1" stroke-width="1.5"/>
      <text x="375" y="29" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" fill="#0f172a">inv_items</text>
      <text x="375" y="44" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#64748b">(part no, name)</text>

      <!-- Step boxes (main flow) -->
      <!-- Step 1 -->
      <rect x="40" y="120" width="155" height="100" rx="8" fill="#fef3c7" stroke="#d97706" stroke-width="2"/>
      <text x="117" y="142" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="700" fill="#92400e">STEP 1 &middot; SALES ORDER</text>
      <text x="117" y="162" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#92400e">Accounts &middot; via API</text>
      <line x1="56" y1="172" x2="178" y2="172" stroke="#d97706" stroke-width="0.5" opacity="0.4"/>
      <text x="117" y="190" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#78350f">PO, qty, delivery,</text>
      <text x="117" y="204" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#78350f">customer, ACK no</text>

      <!-- Step 2 -->
      <rect x="225" y="120" width="155" height="100" rx="8" fill="#ede9fe" stroke="#7c3aed" stroke-width="2"/>
      <text x="302" y="142" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="700" fill="#5b21b6">STEP 2 &middot; QC / MIR</text>
      <text x="302" y="162" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#5b21b6">QC Team</text>
      <line x1="241" y1="172" x2="363" y2="172" stroke="#7c3aed" stroke-width="0.5" opacity="0.4"/>
      <text x="302" y="190" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#4c1d95">ATS, PPM, QN,</text>
      <text x="302" y="204" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#4c1d95">batch, MIR notes</text>

      <!-- Step 3 -->
      <rect x="410" y="120" width="155" height="100" rx="8" fill="#dbeafe" stroke="#2563eb" stroke-width="2"/>
      <text x="487" y="142" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="700" fill="#1e40af">STEP 3 &middot; PRODUCTION</text>
      <text x="487" y="162" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#1e40af">Production Team</text>
      <line x1="426" y1="172" x2="548" y2="172" stroke="#2563eb" stroke-width="0.5" opacity="0.4"/>
      <text x="487" y="190" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#1e3a8a">Submitted qty,</text>
      <text x="487" y="204" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#1e3a8a">batch, boxes</text>

      <!-- Step 4 -->
      <rect x="595" y="120" width="155" height="100" rx="8" fill="#d1fae5" stroke="#059669" stroke-width="2"/>
      <text x="672" y="142" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="700" fill="#047857">STEP 4 &middot; ATS</text>
      <text x="672" y="162" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#047857">ATS Team</text>
      <line x1="611" y1="172" x2="733" y2="172" stroke="#059669" stroke-width="0.5" opacity="0.4"/>
      <text x="672" y="190" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#064e3b">ATS number</text>
      <text x="672" y="204" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#064e3b">(stock moves to SHP)</text>

      <!-- Step 5 -->
      <rect x="780" y="120" width="180" height="100" rx="8" fill="#fce7f3" stroke="#be185d" stroke-width="2"/>
      <text x="870" y="142" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="700" fill="#9d174d">STEP 5 &middot; BILLING / CLOSE</text>
      <text x="870" y="162" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" fill="#9d174d">Accounts &middot; via API</text>
      <line x1="796" y1="172" x2="944" y2="172" stroke="#be185d" stroke-width="0.5" opacity="0.4"/>
      <text x="870" y="190" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#831843">Invoice no &amp; date</text>
      <text x="870" y="204" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#831843">(auto-ship)</text>

      <!-- Status transitions (solid arrows between steps) -->
      <line x1="195" y1="170" x2="225" y2="170" stroke="#475569" stroke-width="2" marker-end="url(#arr)"/>
      <line x1="380" y1="170" x2="410" y2="170" stroke="#475569" stroke-width="2" marker-end="url(#arr)"/>
      <line x1="565" y1="170" x2="595" y2="170" stroke="#475569" stroke-width="2" marker-end="url(#arr)"/>
      <line x1="750" y1="170" x2="780" y2="170" stroke="#475569" stroke-width="2" marker-end="url(#arr)"/>

      <!-- Status label on each arrow -->
      <text x="210" y="160" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="9" fill="#475569">qc_pending</text>
      <text x="395" y="160" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="9" fill="#475569">prod_pending</text>
      <text x="580" y="160" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="9" fill="#475569">ats_pending</text>
      <text x="765" y="160" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="9" fill="#475569">billing_pending</text>

      <!-- Closed terminus -->
      <rect x="800" y="240" width="140" height="40" rx="6" fill="#f3f4f6" stroke="#9ca3af" stroke-width="1.5"/>
      <text x="870" y="265" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="11" fill="#374151">closed</text>
      <line x1="870" y1="220" x2="870" y2="240" stroke="#475569" stroke-width="2" marker-end="url(#arr)"/>

      <!-- External billing system feeds Step 1 -->
      <line x1="125" y1="50" x2="117" y2="120" stroke="#475569" stroke-width="2" stroke-dasharray="4 3" marker-end="url(#arr)"/>
      <text x="65" y="85" font-family="system-ui,sans-serif" font-size="10" fill="#475569" font-style="italic">create_from_so</text>

      <!-- inv_items feeds part name lookup (across all steps) -->
      <line x1="375" y1="50" x2="280" y2="120" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="3 3" marker-end="url(#arrD)"/>
      <text x="305" y="85" font-family="system-ui,sans-serif" font-size="10" fill="#64748b" font-style="italic">item_id FK</text>

      <!-- Billing system feeds Step 5 -->
      <line x1="125" y1="50" x2="870" y2="120" stroke="#475569" stroke-width="2" stroke-dasharray="4 3" marker-end="url(#arr)"/>
      <text x="490" y="44" font-family="system-ui,sans-serif" font-size="10" fill="#475569" font-style="italic">set_invoice</text>

      <!-- INVENTORY SWIMLANE: Step 4 -> inv_item_location_stock (+) -->
      <rect x="595" y="410" width="170" height="80" rx="6" fill="#fff" stroke="#d97706" stroke-width="1.5"/>
      <text x="680" y="432" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="600" fill="#92400e">inv_item_location_stock</text>
      <text x="680" y="450" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="10" fill="#92400e">+sub_qty at SHP</text>
      <text x="680" y="466" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#78350f">(produced goods now</text>
      <text x="680" y="480" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#78350f">ready to dispatch)</text>
      <line x1="672" y1="220" x2="672" y2="410" stroke="#d97706" stroke-width="1.5" stroke-dasharray="4 3" marker-end="url(#arr)"/>

      <!-- INVENTORY SWIMLANE: Step 5 -> inv_shipments + lines + stock -->
      <rect x="785" y="410" width="180" height="80" rx="6" fill="#fff" stroke="#be185d" stroke-width="1.5"/>
      <text x="875" y="432" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" font-weight="600" fill="#9d174d">inv_shipments</text>
      <text x="875" y="450" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#9d174d">+ inv_shipment_lines</text>
      <text x="875" y="466" text-anchor="middle" font-family="ui-monospace,Menlo,monospace" font-size="10" fill="#9d174d">&minus;sub_qty from SHP</text>
      <text x="875" y="480" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#831843">(invoice in shipment notes)</text>
      <line x1="870" y1="280" x2="870" y2="410" stroke="#be185d" stroke-width="1.5" stroke-dasharray="4 3" marker-end="url(#arr)"/>

      <!-- Partial split path -->
      <path d="M 487 220 Q 487 320 410 360 L 410 380" stroke="#7c3aed" stroke-width="1.5" fill="none" stroke-dasharray="4 3" marker-end="url(#arrD)"/>
      <rect x="290" y="385" width="180" height="40" rx="6" fill="#fff" stroke="#7c3aed" stroke-width="1.5"/>
      <text x="380" y="403" text-anchor="middle" font-family="system-ui,sans-serif" font-size="11" font-weight="600" fill="#5b21b6">Child job card (balance)</text>
      <text x="380" y="418" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#6d28d9">parent_id set &middot; inherits QC data</text>

      <text x="500" y="270" font-family="system-ui,sans-serif" font-size="10" fill="#7c3aed" font-style="italic">partial production?</text>

      <!-- Notification arrows (small icons next to each transition arrow) -->
      <text x="210" y="100" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14">🔔</text>
      <text x="395" y="100" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14">🔔</text>
      <text x="580" y="100" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14">🔔</text>
      <text x="765" y="100" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14">🔔</text>
      <text x="495" y="510" text-anchor="middle" font-family="system-ui,sans-serif" font-size="10" fill="#475569" font-style="italic">🔔 = notification sent to next step's role-holders on transition</text>
    </svg>
  </div>

  <p class="dim">
    The bell icons mark where notifications fire. When a card moves from
    one step to the next, MagDyn looks up every user who holds the
    next step's permission and writes a notification row. The sidebar's
    bell badge updates on the next page load &mdash; see &sect;12.
  </p>
</section>

<!-- ============ STEP 1 ============ -->
<section class="module" id="step1">
  <h2><span class="num">03</span> Step 1 &middot; Sales Order <span class="role-pill acc">Accounts</span></h2>

  <p>
    Step 1 is fully automated &mdash; the external billing system POSTs a
    sales-order line to MagDyn's job card API, and a new card materializes
    in status <code>qc_pending</code>. No human types Step 1 fields into MagDyn.
  </p>

  <h3>What the billing system pushes</h3>
  <table>
    <thead><tr><th style="width:30%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td><code>inv_code</code></td>     <td class="dim">FK to <code>inv_items.code</code>. Resolves to the canonical item record &mdash; part number and name are derived from there at every render.</td></tr>
      <tr><td><code>po_no</code></td>        <td class="dim">PO number from the billing system.</td></tr>
      <tr><td><code>line_no</code></td>      <td class="dim">PO line, free text. Empty for single-line POs.</td></tr>
      <tr><td><code>qty</code></td>          <td class="dim">Ordered quantity, must be &gt; 0.</td></tr>
      <tr><td><code>delivery_date</code></td><td class="dim">Customer-requested delivery date (YYYY-MM-DD).</td></tr>
      <tr><td><code>supplier_name</code></td><td class="dim">The customer name. (The field is called <code>supplier_name</code> because in MagDyn's model &ldquo;vendors&rdquo; are who supplies us; this is the company we're shipping TO.)</td></tr>
      <tr><td><code>location</code></td>     <td class="dim">Customer delivery location.</td></tr>
      <tr><td><code>ack_no</code></td>       <td class="dim">Billing-system acknowledgement number &mdash; cross-reference back.</td></tr>
      <tr><td><code>ds</code></td>           <td class="dim">Drop-shipment flag.</td></tr>
    </tbody>
  </table>

  <h3>What MagDyn does on receipt</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Resolves the item.</strong></p>
      <p class="sub">Looks up <code>inv_items.id</code> by <code>code</code>. If no active item matches, returns <code>404 item_not_found</code>; the card is NOT created. The billing system must sync the item first (manual process).</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Duplicate-guards on (PO #, line #).</strong></p>
      <p class="sub">If a non-closed job card already exists for the same PO+line, returns <code>409 duplicate</code>. The billing system should NOT retry blindly &mdash; this means the push already succeeded once.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Inserts the card.</strong></p>
      <p class="sub">Auto-increment ID becomes the human-readable <code>JC-NNNNNN</code> number. Status is set to <code>qc_pending</code>, <code>created_at</code> stamped now.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Logs the event + notifies QC.</strong></p>
      <p class="sub">Writes a <code>created</code> row in <code>job_card_events</code>. Looks up every user with <code>job_card.qc_update</code> permission, inserts a notification row for each &mdash; they'll see the bell badge on their next page load.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Returns <code>{ok:true, id, jc_no}</code>.</strong></p>
      <p class="sub">The billing system stores <code>jc_no</code> in its own record for the later <code>set_invoice</code> call.</p>
    </div></div>
  </div>

  <div class="callout note">
    <div class="label">Why no human Step 1 form?</div>
    <p>
      Step 1 fields are facts from the billing system's perspective &mdash;
      the customer's PO, the qty they ordered, the address they want it
      shipped to. Letting MagDyn operators retype those would just
      create transcription errors. The API is the system of record.
    </p>
  </div>
</section>

<!-- ============ STEP 2 ============ -->
<section class="module" id="step2">
  <h2><span class="num">04</span> Step 2 &middot; QC / MIR <span class="role-pill qc">QC</span></h2>

  <p>
    QC inspects the inbound material or sub-assemblies, fills in the
    Material Inspection Report (MIR), and signs off. On save, the card
    advances to <code>prod_pending</code> and production gets a notification.
  </p>

  <h3>Fields on the QC form</h3>
  <table>
    <thead><tr><th style="width:30%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>ATS Needed (Yes / No)</td>     <td class="dim">If Yes, the ATS Number field is required at Step 4. Either way the card still flows through Step 4 &mdash; never skipped.</td></tr>
      <tr><td>PPM (Yes / No)</td>            <td class="dim">Parts-per-million inspection flag.</td></tr>
      <tr><td>QN (Yes / No)</td>             <td class="dim">Quality notification flag.</td></tr>
      <tr><td>Batch / Serial No</td>         <td class="dim">Heat / batch / serial reference for the material being inspected.</td></tr>
      <tr><td>MIR Notes</td>                 <td class="dim">Free text. Inspection result, dimensional checks, heat / batch refs, any deviations.</td></tr>
    </tbody>
  </table>

  <h3>What happens on Complete QC</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>First save:</strong> status advances <code>qc_pending</code> &rarr; <code>prod_pending</code>. <code>qc_completed_at</code> &amp; <code>qc_completed_by</code> stamped.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Subsequent saves:</strong> status preserved (so QC can correct MIR text after approval without flipping the card back). Only the field values update.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Notification</strong> sent to everyone with <code>job_card.prod_update</code>.</p>
    </div></div>
  </div>

  <div class="callout">
    <div class="label">Edit after approval</div>
    <p>
      Once Step 2 is complete, QC can still edit the MIR fields any time
      before the card closes. Other roles (Production, ATS) cannot edit
      the MIR &mdash; those fields are QC-only. Supervisors with
      <code>job_card.edit</code> can edit anything.
    </p>
  </div>
</section>

<!-- ============ STEP 3 ============ -->
<section class="module" id="step3">
  <h2><span class="num">05</span> Step 3 &middot; Production &amp; Packing <span class="role-pill prod">Production</span></h2>

  <p>
    Production runs the work order and reports back: how much they
    produced, the production batch number, and the packing breakdown
    (one row per box). If they produced less than the PO qty, the
    partial-split modal appears &mdash; see &sect;08.
  </p>

  <h3>Fields on the Production form</h3>
  <table>
    <thead><tr><th style="width:30%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Submitted Qty</td>     <td class="dim">How much was actually produced. Must be &gt; 0 and &le; PO qty. If less than PO qty, triggers the split prompt.</td></tr>
      <tr><td>Batch / Serial No</td> <td class="dim">Production batch reference.</td></tr>
      <tr><td>Box Table</td>         <td class="dim">One row per box: Box # (auto-numbered if blank), Type, Size, Weight (kg), Qty. Add / remove rows via the <kbd>+ Add box</kbd> and <kbd>🗑</kbd> buttons.</td></tr>
    </tbody>
  </table>

  <h3>What happens on Complete Production</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Full qty</strong> (<code>sub_qty == po_qty</code>): status advances <code>prod_pending</code> &rarr; <code>ats_pending</code>. Boxes are wiped + reinserted from the form rows. <code>prod_completed_at</code> &amp; <code>prod_completed_by</code> stamped.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Partial qty</strong> (<code>sub_qty &lt; po_qty</code>): partial-split modal appears with the balance qty and a reason textarea. On confirm, parent advances to <code>ats_pending</code> AND a child card is created carrying the balance &mdash; see &sect;08.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Notification</strong> sent to everyone with <code>job_card.ats_update</code>. If a child was created, production also gets a notification for the new card.</p>
    </div></div>
  </div>
</section>

<!-- ============ STEP 4 ============ -->
<section class="module" id="step4">
  <h2><span class="num">06</span> Step 4 &middot; ATS <span class="role-pill ats">ATS</span></h2>

  <p>
    The ATS team enters the Authority-to-Ship number, signaling the goods
    have cleared the final compliance check. <strong>This is the step
    that moves stock into the SHP location</strong> &mdash; making the
    produced goods visible as ready-to-ship inventory.
  </p>

  <h3>Fields on the ATS form</h3>
  <table>
    <thead><tr><th style="width:30%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>ATS Number</td><td class="dim">Required if QC marked ATS Needed = Yes. Optional otherwise &mdash; but the step still runs through (Q5: never skipped).</td></tr>
    </tbody>
  </table>

  <h3>What happens on Complete ATS</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>First save</strong>, in a transaction:</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>1.</strong> ATS number + completion stamps written.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>2.</strong> Status advances <code>ats_pending</code> &rarr; <code>billing_pending</code>.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>3.</strong> <code>inv_item_location_stock</code> for (this card's item &times; SHP) is increased by <code>sub_qty</code>. Upsert &mdash; adds to existing row, or inserts new if none exists.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>4.</strong> Notification sent to everyone with <code>job_card.close</code> (typically Accounts).</p>
    </div></div>
  </div>

  <div class="callout warn">
    <div class="label">Stock move is per-job-card-total</div>
    <p>
      Even though Production records multiple boxes, the SHP move is a
      single increment by <code>sub_qty</code>. The box breakdown is
      retained for packing-list / shipping purposes but doesn't fragment
      the inventory ledger.
    </p>
  </div>

  <div class="callout warn">
    <div class="label">If SHP doesn't exist</div>
    <p>
      The Step 4 save throws if the <code>SHP</code> location is missing
      or inactive. Make sure SHP exists in <em>Inventory &middot;
      Locations</em> with <code>is_active=1</code> before the first ATS
      save. (It was seeded by the install; check before going live.)
    </p>
  </div>
</section>

<!-- ============ STEP 5 ============ -->
<section class="module" id="step5">
  <h2><span class="num">07</span> Step 5 &middot; Billing &amp; Close <span class="role-pill bill">Billing</span></h2>

  <p>
    Step 5 is automated &mdash; the billing system POSTs the invoice number
    via API once it has been raised. MagDyn closes the card and
    auto-ships the goods out of SHP in a single transactional step.
    No human form for this in MagDyn.
  </p>

  <h3>Pre-conditions</h3>
  <p>
    The card must be in status <code>billing_pending</code>. If the
    billing system calls <code>set_invoice</code> on a card in any other
    state, the API returns <code>409 wrong_status</code> with the current
    status in the body.
  </p>

  <h3>What happens on receipt</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>1.</strong> Inserts an <code>inv_shipments</code> row: status <code>closed</code>, mode <code>ship</code>, ref_doc set to the JC number.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>2.</strong> Inserts an <code>inv_shipment_lines</code> row: <code>line_kind=ship</code>, src_location=SHP, qty_planned &amp; qty_shipped both set to the produced qty.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>3.</strong> Decrements <code>inv_item_location_stock</code> for (item &times; SHP) by <code>sub_qty</code>.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>4.</strong> Writes invoice + customer details into the shipment's <code>notes</code> column &mdash; visible in the shipment view.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>5.</strong> Updates the job card: status <code>closed</code>, invoice_no, invoice_date, shipment_id back-link, closed_at stamp.</p>
    </div></div>
  </div>

  <div class="callout warn">
    <div class="label">All-or-nothing</div>
    <p>
      Steps 1&ndash;5 above run in a single DB transaction. If any leg
      fails (most common: insufficient SHP stock &mdash; ATS step didn't
      move stock correctly or another shipment drained it), the whole
      thing rolls back. The card stays at <code>billing_pending</code>
      and the API returns <code>422 inventory_fail</code> with details.
      The billing team escalates to ops &mdash; don't retry blindly.
    </p>
  </div>
</section>

<!-- ============ PARTIAL ============ -->
<section class="module" id="partial">
  <h2><span class="num">08</span> Partial Production Split</h2>

  <p>
    When production submits less than the ordered qty <strong>on first
    save</strong>, MagDyn doesn't fail the card &mdash; it splits.
    The parent advances at the submitted qty and a child job card is
    auto-created carrying the balance. (For re-edits where production
    later wants to adjust the submitted qty up or down, see &sect;09 &mdash;
    those follow different rules.)
  </p>

  <h3>The flow</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Production enters <code>sub_qty &lt; po_qty</code></strong> and clicks Complete Production.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Server bounces back</strong> with <code>?split_prompt=1</code> in the URL. A modal overlays asking &ldquo;Split into child job card?&rdquo; with the balance qty shown and a reason textarea.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Operator confirms.</strong> Form re-posts with <code>split_confirm=1</code> and the reason.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Server transaction:</strong></p>
      <p class="sub">
        a) Updates parent: <code>sub_qty</code> = submitted, status =
        <code>ats_pending</code>, prod completion stamps written.<br>
        b) Inserts boxes for the parent.<br>
        c) Creates child card with <code>po_qty</code> = balance,
        <code>parent_id</code> = parent's id, <code>partial_reason</code>
        = operator's note, status = <code>prod_pending</code>. <strong>
        Child inherits the parent's QC fields</strong> (ATS needed, PPM,
        QN, batch_qc, mir_text, qc_completed_at, qc_completed_by) so it
        skips Step 2 entirely &mdash; same heat, same MIR.<br>
        d) Logs <code>partial_split</code> event on parent and
        <code>created</code> event on child.
      </p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Notifications:</strong> ATS users get notified about the parent (ready for ATS); production users get notified about the child (new card to produce).</p>
    </div></div>
  </div>

  <p class="dim">
    The card view shows both directions of the linkage. Parent's view
    has a &ldquo;This card was split. Children: JC-XXX&rdquo; banner;
    child's view has a &ldquo;Child of JC-YYY&rdquo; banner with the
    reason text.
  </p>
</section>

<!-- ============ QTY CHANGES & RE-EDITS ============ -->
<section class="module" id="qty-changes">
  <h2><span class="num">09</span> Qty Changes &amp; Re-edits</h2>

  <p>
    After a job card has been saved at Step 3, production can come back
    and adjust the submitted qty. The system enforces a strict invariant:
    <strong>parent's <code>po_qty</code> + sum of active children's
    <code>po_qty</code> = original PO qty</strong>. Edits flow units
    between the parent and its children to keep the books balanced.
  </p>

  <h3>The four re-edit scenarios</h3>
  <table>
    <thead><tr><th style="width:24%">SCENARIO</th><th>WHAT HAPPENS</th></tr></thead>
    <tbody>
      <tr>
        <td><strong>Increase</strong>, active child exists</td>
        <td class="dim">Parent's <code>sub_qty</code> goes up; the delta is <em>absorbed</em> from the most recent active child. The child shrinks by the delta. If the child can't cover the delta, the system walks newest-first across multiple children. A child fully consumed gets <code>cancelled</code>. Modal does NOT appear &mdash; this is silent.</td>
      </tr>
      <tr>
        <td><strong>Increase</strong>, no active child</td>
        <td class="dim">Refused with an error: &ldquo;Cannot increase &mdash; only 0 units available across active children.&rdquo; The parent + children invariant doesn't permit increasing the parent's claim without taking units from somewhere.</td>
      </tr>
      <tr>
        <td><strong>Decrease</strong>, active child exists</td>
        <td class="dim">Modal asks &ldquo;Release units into existing child?&rdquo; On confirm, parent's <code>sub_qty</code> goes down; the freed units are added to the most recent active child's <code>po_qty</code>. No new card created.</td>
      </tr>
      <tr>
        <td><strong>Decrease</strong>, no active child</td>
        <td class="dim">Modal asks &ldquo;Spin off into a new child?&rdquo; On confirm, parent's <code>sub_qty</code> goes down; a fresh child is created carrying the freed units. Same as a normal partial split.</td>
      </tr>
    </tbody>
  </table>

  <h3>What "active child" means</h3>
  <p>
    A child is absorbable / expandable only while it's in
    <code>qc_pending</code> or <code>prod_pending</code>. Once a child
    moves past those states (ATS submitted, billing pending, closed, or
    cancelled), it's frozen &mdash; the system won't silently grow or
    shrink it because the next teams have already started working with
    the qty they were handed.
  </p>

  <h3>The ATS lockout</h3>
  <div class="callout warn">
    <div class="label">No reductions once ATS is submitted</div>
    <p>
      Once the parent card's status is <code>billing_pending</code>
      (ATS has signed off and stock has moved to SHP), production
      <strong>cannot reduce</strong> <code>sub_qty</code>. The
      submitted units have already been counted into SHP inventory;
      reducing would leave phantom stock. The system refuses with:
      &ldquo;Cannot reduce &mdash; ATS has already moved stock to SHP.
      Reconcile SHP inventory manually before reducing.&rdquo;
      An admin with DB access has to either reverse the ATS step
      manually or write off the discrepancy outside the system.
    </p>
  </div>

  <h3>Walkthrough: increase example</h3>
  <p>Starting state &mdash; one PO line broken across two cards:</p>
  <table>
    <thead><tr><th>CARD</th><th>PARENT_ID</th><th>PO_QTY</th><th>SUB_QTY</th><th>STATUS</th></tr></thead>
    <tbody>
      <tr><td class="mono">JC-000004</td> <td class="dim">&mdash;</td>      <td>2989.98</td><td>35</td><td class="dim"><code>ats_pending</code></td></tr>
      <tr><td class="mono">JC-000005</td> <td class="mono">4</td>           <td>2954.98</td><td class="dim">(null)</td><td class="dim"><code>prod_pending</code></td></tr>
    </tbody>
  </table>
  <p>Operator opens JC-000004 and bumps sub_qty from 35 to 55 (delta = +20).</p>
  <table>
    <thead><tr><th>CARD</th><th>PO_QTY</th><th>SUB_QTY</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td class="mono">JC-000004</td><td>2989.98</td><td>55</td><td class="dim">+20 absorbed from JC-000005</td></tr>
      <tr><td class="mono">JC-000005</td><td>2934.98</td><td class="dim">(null)</td><td class="dim">po_qty reduced from 2954.98 by 20</td></tr>
    </tbody>
  </table>
  <p>JC-000004's <code>po_qty</code> is untouched (parent's commitment to the customer is still 2989.98). The 20 extra units came out of JC-000005's pending work.</p>

  <h3>Walkthrough: decrease example</h3>
  <p>Starting state &mdash; same as above after the increase.</p>
  <p>Now operator opens JC-000004 again and reduces sub_qty from 55 to 40 (delta = &minus;15). Modal appears:</p>
  <p class="dim" style="border-left: 3px solid var(--border); padding-left: 12px;">
    &ldquo;Reducing submitted qty from 55 to 40. The freed 15 units will be added to existing child JC-000005 (currently 2934.98 &rarr; 2949.98 units). PO qty stays at 2989.98 on the parent.&rdquo;
  </p>
  <p>Operator clicks &ldquo;Confirm release &amp; save&rdquo;.</p>
  <table>
    <thead><tr><th>CARD</th><th>PO_QTY</th><th>SUB_QTY</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td class="mono">JC-000004</td><td>2989.98</td><td>40</td><td class="dim">15 units released to JC-000005</td></tr>
      <tr><td class="mono">JC-000005</td><td>2949.98</td><td class="dim">(null)</td><td class="dim">po_qty grew from 2934.98 by 15</td></tr>
    </tbody>
  </table>

  <h3>Audit trail</h3>
  <p>
    Every absorption and release writes a row in <code>job_card_events</code>:
  </p>
  <ul>
    <li><code>parent_qty_increase_absorbed</code> on each affected child, with <code>absorbed_qty</code>, <code>previous_po_qty</code>, <code>new_po_qty</code>.</li>
    <li><code>parent_qty_reduction_absorbed</code> on the expanded child for decreases.</li>
    <li><code>cancelled</code> with <code>source: parent_qty_increase_absorbed</code> if a child gets fully consumed.</li>
    <li><code>partial_split</code> on the parent referencing the child(ren) touched.</li>
  </ul>

  <h3>Notifications</h3>
  <p>
    The current-step owners of any child whose <code>po_qty</code>
    changed get a notification: &ldquo;JC-XXX &mdash; qty expanded by N
    units&rdquo; (or absorbed message). Their workload just moved without
    any direct action from them; the bell badge lets them notice.
  </p>
</section>

<!-- ============ AMENDMENTS FROM BILLING ============ -->
<section class="module" id="amendments">
  <h2><span class="num">10</span> Amendments from Billing</h2>

  <p>
    When the billing system amends an SO &mdash; customer changed the
    qty, moved the delivery date, dropped a line, added a line &mdash;
    those changes flow into MagDyn via the same API the billing dev
    already uses for creating cards. The billing app decides per-line
    which op to call.
  </p>

  <h3>The decision rule (billing-side)</h3>
  <table>
    <thead><tr><th style="width:38%">WHAT HAPPENED ON BILLING SIDE</th><th>OP TO CALL</th></tr></thead>
    <tbody>
      <tr><td>First-ever push of a new PO line</td>                              <td class="mono">create_from_so</td></tr>
      <tr><td>Existing PO line was modified (qty / date / customer / etc.)</td> <td class="mono">update_from_so</td></tr>
      <tr><td>New line added during an amendment to an existing PO</td>         <td class="mono">create_from_so</td></tr>
      <tr><td>Line removed during an amendment</td>                              <td class="mono">cancel_from_so</td></tr>
      <tr><td>Invoice raised against the line, ready to ship</td>                <td class="mono">set_invoice</td></tr>
    </tbody>
  </table>

  <p>
    Per-line decision &mdash; one amendment with three modified lines
    and one dropped line means four API calls. Billing tracks
    &ldquo;have I pushed this line before&rdquo; state on its side and
    picks the op accordingly.
  </p>

  <h3>What changes on an amendment update</h3>
  <p>
    <code>update_from_so</code> takes the same payload shape as
    <code>create_from_so</code>. MagDyn matches by
    <code>(po_no, line_no)</code>, diffs against the current values,
    and applies what changed. Mutability is gated by status:
  </p>
  <table>
    <thead><tr><th style="width:32%">FIELD</th><th>WHEN MUTABLE</th></tr></thead>
    <tbody>
      <tr>
        <td><code>qty</code> &mdash; decrease</td>
        <td class="dim">Mutable through <code>ats_pending</code>. Refused once status is <code>billing_pending</code>+ (ATS submitted, stock at SHP).</td>
      </tr>
      <tr>
        <td><code>qty</code> &mdash; increase</td>
        <td class="dim">Mutable through <code>ats_pending</code>. In <code>qc_pending</code> the parent's po_qty is bumped in place. In <code>prod_pending</code>/<code>ats_pending</code> the parent's po_qty stays unchanged and a <strong>child card is spun off carrying only the delta</strong> at <code>qc_pending</code>. The child goes through its own QC cycle since the additional units may be a different lot.</td>
      </tr>
      <tr>
        <td><code>delivery_date</code>, <code>supplier_name</code>, <code>location</code>, <code>ack_no</code>, <code>ds</code></td>
        <td class="dim">Mutable through <code>billing_pending</code>. Display-only metadata; no side effects.</td>
      </tr>
      <tr><td><code>inv_code</code></td><td class="dim"><strong>Never</strong> changeable. Different item = different SO line &mdash; cancel and reissue.</td></tr>
      <tr><td>Anything</td>                <td class="dim">Refused on <code>closed</code> / <code>cancelled</code> cards.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">Two different "qty increase" paths</div>
    <p>
      Don't confuse this with the operator-driven qty increase in &sect;09. There are TWO ways a card's qty can increase:
    </p>
    <p style="margin-top:8px;">
      <strong>Operator increases sub_qty on the parent</strong> (&sect;09) &rarr; absorbs units from existing active children. The PO total is unchanged.
    </p>
    <p style="margin-top:8px;">
      <strong>Billing pushes a qty increase amendment</strong> (this section) &rarr; the customer wants more units total. Parent's po_qty either bumps (qc_pending) or spawns a child for the delta (production has started). The PO total grows.
    </p>
  </div>

  <h3>Cancellation from amendment</h3>
  <p>
    When the billing app calls <code>cancel_from_so</code> for a dropped
    line, MagDyn flips the matching card to <code>cancelled</code>
    if it's still cancellable. Rules:
  </p>
  <table>
    <thead><tr><th style="width:24%">CARD STATUS</th><th>OUTCOME</th></tr></thead>
    <tbody>
      <tr><td><code>qc_pending</code></td>      <td class="dim">Cancelled. No work done yet.</td></tr>
      <tr><td><code>prod_pending</code></td>    <td class="dim">Cancelled. QC done (sunk cost) but production hasn't physically produced anything yet.</td></tr>
      <tr><td><code>ats_pending</code></td>     <td class="dim"><strong>Refused</strong> with <code>409 wrong_status</code>. Production has submitted units; stock has moved to SHP. Human supervisor must reconcile.</td></tr>
      <tr><td><code>billing_pending</code></td> <td class="dim"><strong>Refused</strong>. Same reasoning.</td></tr>
      <tr><td><code>closed</code></td>           <td class="dim">Not found in the match (already shipped). Returns <code>action: 'no_card'</code> &mdash; treated as success since there's nothing to cancel.</td></tr>
      <tr><td><code>cancelled</code></td>        <td class="dim">Idempotent &mdash; <code>action: 'already_cancelled'</code>. Safe to retry.</td></tr>
    </tbody>
  </table>

  <h3>End-to-end amendment scenarios</h3>

  <h4>Scenario A &mdash; Customer increases qty before production starts</h4>
  <p>
    PO line for 100 units, card at <code>qc_pending</code>. Customer
    amends to 150. Billing pushes <code>update_from_so</code> with
    <code>qty: 150</code>. MagDyn bumps the card's <code>po_qty</code> from
    100 to 150 in place. No child spawned. QC team sees the new qty next
    page load.
  </p>

  <h4>Scenario B &mdash; Customer increases qty after production started</h4>
  <p>
    Same line, but now at <code>prod_pending</code>. Production has
    already started against the 100 they were told about. Billing pushes
    amendment to 150. MagDyn keeps parent's <code>po_qty</code> at 100,
    creates a child with <code>po_qty=50</code> at <code>qc_pending</code>.
    Production keeps working on the 100; QC team gets a new card for the
    additional 50.
  </p>

  <h4>Scenario C &mdash; Customer reduces qty before ATS</h4>
  <p>
    PO line for 100 units, card at <code>ats_pending</code> (production
    submitted 100). Customer amends to 70. Billing pushes
    <code>update_from_so</code> with <code>qty: 70</code>. MagDyn updates
    <code>po_qty</code> to 70 in place. Production is already done.
    ATS team sees the lower qty and signs off on 70 instead of 100.
  </p>

  <h4>Scenario D &mdash; Customer reduces qty after ATS</h4>
  <p>
    Same line, but ATS already signed off and status is
    <code>billing_pending</code>. 100 units sitting at SHP. Customer
    amends to 70. Billing pushes <code>update_from_so</code> with
    <code>qty: 70</code>. MagDyn returns <code>422 qty_locked</code>:
    &ldquo;Cannot change po_qty &mdash; ATS submitted, stock at SHP.&rdquo;
    Operations has to decide: ship the 100 anyway, partial-ship 70 and
    create a return, or pull 30 back out of SHP.
  </p>

  <h4>Scenario E &mdash; Customer drops a line</h4>
  <p>
    PO had three lines; amendment removes line 2. Billing pushes
    <code>cancel_from_so</code> for line 2. If the matching card is in
    <code>qc_pending</code> or <code>prod_pending</code>, it's cancelled.
    If it's further along, billing gets a <code>409 wrong_status</code>
    and ops handles it.
  </p>

  <h4>Scenario F &mdash; Customer adds a line</h4>
  <p>
    PO had three lines; amendment adds line 4. Billing pushes
    <code>create_from_so</code> for line 4. MagDyn creates a fresh card
    just like any first-time line push. QC team gets a notification.
  </p>

  <h3>What can go wrong</h3>
  <table>
    <thead><tr><th style="width:30%">ERROR</th><th>CAUSE / FIX</th></tr></thead>
    <tbody>
      <tr><td><code>409 duplicate</code> on create</td>                <td class="dim">Billing called <code>create_from_so</code> for a line that already exists. Billing's state tracking has drifted &mdash; investigate; don't retry blindly.</td></tr>
      <tr><td><code>404 jc_not_found</code> on update</td>             <td class="dim">Billing called <code>update_from_so</code> for a line MagDyn has no record of. Initial create push failed &mdash; call <code>create_from_so</code> first, then retry update.</td></tr>
      <tr><td><code>422 qty_locked</code></td>                          <td class="dim">Tried to change qty past <code>ats_pending</code>. Manual reconciliation only.</td></tr>
      <tr><td><code>422 item_change</code></td>                         <td class="dim"><code>inv_code</code> in payload differs from the existing card. Cancel old card via <code>cancel_from_so</code>; create new one via <code>create_from_so</code>.</td></tr>
      <tr><td><code>409 wrong_status</code> on cancel</td>              <td class="dim">Card is past <code>prod_pending</code>; can't be cancelled via API. Escalate to ops.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ TERMINAL ============ -->
<section class="module" id="terminal">
  <h2><span class="num">11</span> Cancelled &amp; Closed Cards</h2>

  <p>
    Two terminal states end a job card's life: <code>closed</code>
    (happy path &mdash; the customer got their goods) and
    <code>cancelled</code> (sad path &mdash; the order or line was
    voided). Both are absolute. No further processing is allowed by
    anyone, including users with the <code>job_card.edit</code>
    supervisor permission.
  </p>

  <h3>How a card becomes cancelled</h3>
  <ul>
    <li><strong>Billing amendment drops the line.</strong> Billing app calls <code>cancel_from_so</code>; MagDyn flips status to <code>cancelled</code>. Allowed only at <code>qc_pending</code> or <code>prod_pending</code>; refused beyond.</li>
    <li><strong>Operator qty increase fully consumes a child.</strong> When an operator increases the parent's sub_qty and the absorption pulls every unit out of a child card, that child gets auto-cancelled with <code>source: parent_qty_increase_absorbed</code>.</li>
    <li><strong>Admin override.</strong> A supervisor with direct DB access can flip <code>status='cancelled'</code> manually. There's no in-app UI for this yet &mdash; intentional, since cancellation has real downstream consequences and ought to require a moment of thought.</li>
  </ul>

  <h3>How a card becomes closed</h3>
  <p>
    The billing system pushes <code>set_invoice</code> when the invoice
    has been raised. MagDyn atomically creates the shipment record,
    decrements SHP stock, writes the invoice into the shipment notes,
    and flips the card to <code>closed</code>. The card is done.
  </p>

  <h3>Visual treatment</h3>
  <p>
    A cancelled card shows a prominent red banner at the top of the
    view page:
  </p>
  <div class="callout warn">
    <div class="label">⛔ This job card has been cancelled</div>
    <p>No further processing is allowed on this card by anyone, including supervisors. Cancelled on <em>&lt;timestamp&gt;</em> by <em>&lt;actor&gt;</em>. Reason: <em>&lt;text&gt;</em></p>
  </div>
  <p>A closed card shows a green banner:</p>
  <div class="callout">
    <div class="label">✓ This job card is closed</div>
    <p>All steps complete; invoice raised, stock shipped from SHP. No further processing is allowed.</p>
  </div>
  <p>
    Behind the banners, all three step sections render their read-only
    summaries (whatever was filled in before the card hit the terminal
    state). The editable forms are gone.
  </p>

  <h3>What's still allowed</h3>
  <ul>
    <li>Viewing the card &mdash; the read-only summary stays accessible.</li>
    <li>Reading the audit trail (<code>job_card_events</code>) for forensics.</li>
    <li>Following links from the card to the linked shipment (if closed) or to parent/child cards.</li>
    <li>Closed cards' SHP movement is reversible <em>only</em> via direct DB intervention; cancellation has no automatic stock implications because nothing moved to SHP for cancellable statuses (<code>qc_pending</code> / <code>prod_pending</code> can't reach the SHP move).</li>
  </ul>

  <h3>What's blocked</h3>
  <ul>
    <li>QC, Production, ATS save handlers all refuse with a flash error if called on a cancelled or closed card.</li>
    <li>The view page hides the editable forms; only the read-only summary is shown.</li>
    <li>API <code>update_from_so</code> returns <code>409 wrong_status</code>; <code>cancel_from_so</code> on a cancelled card returns <code>action: 'already_cancelled'</code> as a no-op success.</li>
  </ul>

  <div class="callout note">
    <div class="label">Why so absolute?</div>
    <p>
      Terminal-status hard-locking prevents a class of bugs where a
      well-meaning supervisor reopens a cancelled card, advances it
      through Step 4, and inadvertently moves stock into SHP for an
      order that no longer exists. Once cancelled, always cancelled.
      Need to reprocess? Create a fresh card.
    </p>
  </div>
</section>

<!-- ============ LINKAGES ============ -->
<section class="module" id="linkages">
  <h2><span class="num">12</span> Module Linkages</h2>

  <p>
    The job card module isn't an island &mdash; it reads from and writes to
    several other tables. Here's the complete dependency map.
  </p>

  <h3>What job_card reads</h3>
  <table class="linkages">
    <thead><tr><th style="width:25%">TABLE</th><th style="width:25%">WHEN</th><th>WHY</th></tr></thead>
    <tbody>
      <tr><td class="mono">inv_items</td>             <td class="dim">Every render</td>           <td class="dim">Resolve item_id to code, short_description, name. Derives part no / part name.</td></tr>
      <tr><td class="mono">locations</td>             <td class="dim">Step 4 save</td>            <td class="dim">Look up SHP location for the stock move target.</td></tr>
      <tr><td class="mono">inv_item_location_stock</td><td class="dim">Step 5 (close)</td>        <td class="dim">Verify enough stock at SHP before shipping.</td></tr>
      <tr><td class="mono">users</td>                 <td class="dim">Every render</td>           <td class="dim">Resolve qc/prod/ats completed_by IDs to names.</td></tr>
      <tr><td class="mono">user_roles, role_permissions, permissions, modules</td><td class="dim">Step transitions</td><td class="dim">Find users with the next step's permission to notify.</td></tr>
    </tbody>
  </table>

  <h3>What job_card writes</h3>
  <table class="linkages">
    <thead><tr><th style="width:25%">TABLE</th><th style="width:25%">WHEN</th><th>WHAT</th></tr></thead>
    <tbody>
      <tr><td class="mono">job_cards</td>             <td class="dim">All steps</td>              <td class="dim">The main record. INSERT on Step 1, UPDATE on Steps 2-5, INSERT on partial-split (child).</td></tr>
      <tr><td class="mono">job_card_boxes</td>        <td class="dim">Step 3 save</td>            <td class="dim">DELETE + INSERT per row in the box table.</td></tr>
      <tr><td class="mono">job_card_events</td>       <td class="dim">All steps + edits</td>     <td class="dim">Audit trail. One row per state change, save, or admin override.</td></tr>
      <tr><td class="mono">notifications</td>         <td class="dim">Step transitions</td>      <td class="dim">One row per recipient (everyone with the next step's permission, minus the actor).</td></tr>
      <tr><td class="mono">inv_item_location_stock</td><td class="dim">Step 4 (ATS)</td>          <td class="dim">UPSERT: +sub_qty at SHP. Goods now visible as ready-to-ship.</td></tr>
      <tr><td class="mono">inv_item_location_stock</td><td class="dim">Step 5 (close)</td>        <td class="dim">UPDATE: &minus;sub_qty from SHP. Goods leave the building.</td></tr>
      <tr><td class="mono">inv_shipments</td>         <td class="dim">Step 5 (close)</td>        <td class="dim">INSERT one row. Status closed, mode ship, ref_doc = JC#. Invoice details in notes.</td></tr>
      <tr><td class="mono">inv_shipment_lines</td>    <td class="dim">Step 5 (close)</td>        <td class="dim">INSERT one row. line_kind ship, src_location SHP, qty = produced.</td></tr>
      <tr><td class="mono">job_cards.shipment_id</td> <td class="dim">Step 5 (close)</td>        <td class="dim">Back-link from the closed card to the auto-created shipment.</td></tr>
    </tbody>
  </table>

  <h3>What writes back to job_card</h3>
  <table class="linkages">
    <thead><tr><th style="width:30%">WRITER</th><th>WHEN</th></tr></thead>
    <tbody>
      <tr><td>External billing system (via API)</td>              <td class="dim"><code>create_from_so</code> creates the card; <code>set_invoice</code> closes it.</td></tr>
      <tr><td>QC / Production / ATS UI</td>                       <td class="dim">Each role's role-gated form writes their step's fields.</td></tr>
      <tr><td>Admin (direct SQL or admin UI)</td>                  <td class="dim">Status overrides (e.g. cancellation), supervisor edits with the <code>job_card.edit</code> permission.</td></tr>
    </tbody>
  </table>

  <h3>Cross-reference: shipment ↔ job card</h3>
  <p>
    When a job card closes, MagDyn creates an <code>inv_shipments</code>
    row and links them in BOTH directions:
  </p>
  <ul>
    <li><code>job_cards.shipment_id</code> &rarr; the auto-created shipment.</li>
    <li><code>inv_shipments.ref_doc</code> &rarr; the JC number (string ref, not FK).</li>
    <li><code>inv_shipments.notes</code> contains the invoice number, invoice date, customer, and delivery location for at-a-glance visibility.</li>
  </ul>
  <p>
    Open the closed card's view in MagDyn and Step 5 shows a clickable
    link to the linked shipment. Open the shipment and the notes field
    shows the originating JC.
  </p>
</section>

<!-- ============ API ============ -->
<section class="module" id="api">
  <h2><span class="num">13</span> API Endpoints</h2>

  <p>
    Two ops, both POST to <code>/api/job_card.php</code>, both
    Bearer-token-authenticated. Full spec is in <code>docs/JOB_CARD_API.md</code>
    &mdash; share that with the billing-system developer.
  </p>

  <table>
    <thead><tr><th style="width:30%">OP</th><th>WHAT IT DOES</th></tr></thead>
    <tbody>
      <tr>
        <td class="mono">?op=create_from_so</td>
        <td class="dim">Creates a new job card from a sales-order line. Inputs: inv_code, po_no, line_no, qty, delivery_date, supplier_name, location, ack_no, ds. Returns id + jc_no.</td>
      </tr>
      <tr>
        <td class="mono">?op=set_invoice</td>
        <td class="dim">Closes a card in <code>billing_pending</code>. Inputs: jc_no, invoice_no, invoice_date. Atomically: creates shipment, decrements SHP stock, writes invoice into notes, marks card closed.</td>
      </tr>
      <tr>
        <td class="mono">?probe=1</td>
        <td class="dim">Static JSON heartbeat. No auth. Use to verify connectivity.</td>
      </tr>
    </tbody>
  </table>

  <h3>Auth</h3>
  <p>
    Bearer token in the Authorization header. The token is the same
    value as <code>so_integration.bearer_token</code> in
    <code>config/app.config.php</code> (shared with the existing SO Pending
    API). Missing or wrong token returns 401.
  </p>

  <h3>Error catalog</h3>
  <table>
    <thead><tr><th style="width:10%">HTTP</th><th style="width:25%">ERROR CODE</th><th>MEANING</th></tr></thead>
    <tbody>
      <tr><td>200</td><td class="dim">&mdash;</td>                            <td class="dim">Success.</td></tr>
      <tr><td>400</td><td class="mono">bad_json, unknown_op</td>              <td class="dim">Fix request, retry.</td></tr>
      <tr><td>401</td><td class="mono">unauthorized</td>                      <td class="dim">Bad bearer token.</td></tr>
      <tr><td>404</td><td class="mono">item_not_found, jc_not_found</td>      <td class="dim">Sync the missing record, then retry.</td></tr>
      <tr><td>409</td><td class="mono">duplicate, wrong_status</td>           <td class="dim">Don't retry blindly &mdash; investigate.</td></tr>
      <tr><td>422</td><td class="mono">validation, inventory_fail</td>        <td class="dim">Fix and retry.</td></tr>
      <tr><td>500</td><td class="mono">db_error, server_fatal</td>            <td class="dim">Server side &mdash; escalate.</td></tr>
      <tr><td>503</td><td class="mono">not_configured, bootstrap_fail</td>    <td class="dim">Server config issue.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ ROLES ============ -->
<section class="module" id="roles">
  <h2><span class="num">14</span> Roles &amp; Permissions</h2>

  <p>
    Seven distinct permissions on the <code>job_card</code> module, each
    gating a specific capability. Wire them to your existing roles in
    <em>Admin &middot; Roles &amp; Permissions</em>.
  </p>

  <table>
    <thead><tr><th style="width:25%">PERMISSION</th><th style="width:25%">TYPICAL ROLE</th><th>WHAT IT ALLOWS</th></tr></thead>
    <tbody>
      <tr><td class="mono">job_card.view</td>        <td>Everyone in the workflow</td>  <td class="dim">See the list page and detail view. Required to see anything.</td></tr>
      <tr><td class="mono">job_card.create</td>      <td>System (API)</td>              <td class="dim">Create cards via <code>create_from_so</code>. The API enforces this implicitly via the bearer token.</td></tr>
      <tr><td class="mono">job_card.qc_update</td>   <td>QC role</td>                   <td class="dim">Edit Step 2 fields. MIR fields are restricted to this permission &mdash; no other role can edit them.</td></tr>
      <tr><td class="mono">job_card.prod_update</td> <td>Production role</td>           <td class="dim">Edit Step 3 fields and trigger partial splits.</td></tr>
      <tr><td class="mono">job_card.ats_update</td>  <td>ATS role</td>                  <td class="dim">Edit Step 4. On first save, moves stock to SHP.</td></tr>
      <tr><td class="mono">job_card.close</td>       <td>System (API) / Accounts</td>   <td class="dim">Close cards via <code>set_invoice</code>. The API enforces this implicitly.</td></tr>
      <tr><td class="mono">job_card.edit</td>        <td>Supervisor</td>                <td class="dim">Edit any step regardless of current status or owner role. Hard override.</td></tr>
    </tbody>
  </table>

  <p>
    The admin role gets all seven by default (auto-granted in the
    migration). Other roles get them via the admin UI &mdash; per the
    design conversation (S4), you wire which role owns which step rather
    than MagDyn creating new roles.
  </p>
</section>

<!-- ============ NOTIFICATIONS ============ -->
<section class="module" id="notifications">
  <h2><span class="num">15</span> Notifications</h2>

  <p>
    Every step transition fires notifications to the next step's
    role-holders. Notifications surface as:
  </p>
  <ul>
    <li>A red badge on the bell icon in the sidebar footer (unread count).</li>
    <li>The notifications page at <em>/job_card.php?action=notifications</em> &mdash; reachable by clicking the bell.</li>
  </ul>

  <h3>Who gets what</h3>
  <table>
    <thead><tr><th style="width:30%">TRANSITION</th><th>RECIPIENTS</th></tr></thead>
    <tbody>
      <tr><td>SO API push (card created)</td>           <td class="dim">All users with <code>job_card.qc_update</code></td></tr>
      <tr><td>QC complete</td>                          <td class="dim">All users with <code>job_card.prod_update</code></td></tr>
      <tr><td>Production complete (full)</td>           <td class="dim">All users with <code>job_card.ats_update</code></td></tr>
      <tr><td>Production complete (partial split)</td>  <td class="dim">All users with <code>job_card.ats_update</code> (for the parent) PLUS all users with <code>job_card.prod_update</code> (for the new child)</td></tr>
      <tr><td>ATS complete</td>                         <td class="dim">All users with <code>job_card.close</code></td></tr>
      <tr><td>Billing close</td>                        <td class="dim">No recipients &mdash; terminal state. Visible in the closed-cards list.</td></tr>
    </tbody>
  </table>

  <p class="dim">
    The actor themselves is never notified &mdash; if QC just clicked Save
    on Step 2, they don't need a bell badge telling them Step 3 is open;
    they already know.
  </p>

  <h3>Notifications table</h3>
  <p>
    The <code>notifications</code> table is generic (entity_type +
    entity_id polymorphism) so other modules can adopt it later without
    schema changes. For now only the job_card module writes to it.
  </p>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">16</span> Troubleshooting</h2>

  <h3>&ldquo;The QC form looks greyed out / disabled.&rdquo;</h3>
  <p>
    If the user has <code>job_card.qc_update</code> and the card is in
    a state they can edit (<code>qc_pending</code> through
    <code>billing_pending</code>), the form should show a bright blue
    outline. If it's faded, the user lacks the permission &mdash; check
    Admin &middot; Roles. Hard-refresh after permission changes
    (<kbd>Ctrl+Shift+R</kbd>).
  </p>

  <h3>&ldquo;Billing API returns 409 wrong_status.&rdquo;</h3>
  <p>
    The card hasn't reached <code>billing_pending</code> yet &mdash; ATS
    hasn't completed Step 4. Don't retry blindly. Open the card in
    MagDyn to see who's blocking, then chase that team.
  </p>

  <h3>&ldquo;Billing API returns 422 inventory_fail: insufficient SHP stock.&rdquo;</h3>
  <p>
    Either Step 4 didn't move stock (rare &mdash; would have failed
    earlier) or another shipment drained SHP between Step 4 and Step 5.
    Open <em>Inventory &middot; Stock</em>, filter by location SHP and
    the item code on the JC. Reconcile the discrepancy before retrying
    <code>set_invoice</code>.
  </p>

  <h3>&ldquo;Notifications aren't appearing for the right users.&rdquo;</h3>
  <p>
    Check that the role wiring is correct in
    <em>Admin &middot; Roles &amp; Permissions</em>. The notification
    query walks <code>user_roles &rarr; role_permissions &rarr;
    permissions &rarr; modules</code> &mdash; if a user isn't getting
    notifications for the QC step, they don't have a role with
    <code>job_card.qc_update</code>. The bell badge is cached per page
    load; navigate to a fresh URL to see the new count.
  </p>

  <h3>&ldquo;Partial-split modal didn't appear.&rdquo;</h3>
  <p>
    The modal renders when the URL has <code>split_prompt=1</code>. If
    Production submits a partial qty and the modal doesn't show, check
    the browser's Network tab &mdash; the form should POST and get
    redirected back to the same view URL with the query param appended.
    If that's not happening, the form is failing validation server-side
    BEFORE reaching the split check. Check error_log for the cause.
  </p>

  <h3>&ldquo;A card is stuck. Need to cancel it.&rdquo;</h3>
  <p>
    Cancellation is admin-override only (no UI yet). A supervisor with
    DB access runs:
    <code>UPDATE job_cards SET status='cancelled' WHERE id = &lt;N&gt;;</code>
    then logs an event:
    <code>INSERT INTO job_card_events (job_card_id, event_type, event_data, actor_user_id) VALUES (&lt;N&gt;, 'cancelled', '{&quot;reason&quot;:&quot;...&quot;}', &lt;user&gt;);</code>
  </p>

  <h3>&ldquo;Where do I see the JC for a given PO?&rdquo;</h3>
  <p>
    Open <em>/job_card.php</em>, filter the PO column for the PO number.
    If the billing system pushed the SO, a card exists. If you don't see
    one, the push hasn't happened &mdash; talk to the billing team.
  </p>

  <h3>&ldquo;I increased the parent's sub_qty but got 'only 0 units available'.&rdquo;</h3>
  <p>
    The system requires units to come from somewhere &mdash; either an
    active child to absorb from, or the customer's amended qty has to
    grow. There's no absorbable child (all are closed / cancelled /
    past prod_pending), so the increase can't be honored. Either:
    don't increase the parent, OR ask billing to push a qty amendment
    raising the customer's order, OR cancel the parent and reissue.
  </p>

  <h3>&ldquo;I reduced sub_qty but the modal said 'release into existing child' &mdash; I expected a new child.&rdquo;</h3>
  <p>
    When there's an active child carrying the balance, MagDyn prefers
    to expand it rather than fragment the work into more cards. If you
    want a new child anyway (different lot, different timing), first
    transition the existing child past <code>prod_pending</code> (or
    cancel it), then redo the reduction &mdash; with no absorbable
    child, the modal switches back to &ldquo;spin off into a new
    card.&rdquo;
  </p>

  <h3>&ldquo;Billing sent an amendment but the card didn't update.&rdquo;</h3>
  <p>
    Most likely cause: billing called <code>create_from_so</code> for
    an existing line and got <code>409 duplicate</code>, then logged it
    silently. Amendments must use <code>update_from_so</code> not
    <code>create_from_so</code>. Coordinate with the billing dev to
    check their per-line decision logic. See &sect;10 for the decision
    rule table.
  </p>

  <h3>&ldquo;Card is cancelled but I need to fix one field.&rdquo;</h3>
  <p>
    You can't. Cancellation is terminal &mdash; not even supervisors can
    edit a cancelled card. Create a new card via the billing system if
    the work needs to resume. The cancelled card's audit trail stays
    accessible for reference.
  </p>

  <h3>&ldquo;Qty change refused with 'qty_locked' from billing API.&rdquo;</h3>
  <p>
    The card has reached <code>billing_pending</code> &mdash; ATS
    submitted and stock moved to SHP. Qty changes here would desync
    inventory. Options: ship what's at SHP and create a new SO line for
    any delta, OR cancel the card via DB intervention and have ops pull
    units back out of SHP manually.
  </p>

  <h3>&ldquo;Amendment increase split into a child &mdash; can I avoid this?&rdquo;</h3>
  <p>
    Not via the API. The child-spawn behavior protects production from
    silently having its committed qty grow mid-job. If you genuinely
    want the parent's <code>po_qty</code> to grow (e.g. the qc_pending
    card hasn't been touched yet), confirm that with the timing &mdash;
    qc_pending status DOES update po_qty in place. Past that point,
    children get spawned. Talk to ops if you need an exception.
  </p>
</section>

<div class="foot">
    <div>Job Card &middot; Operator Manual &middot; v1.0</div>
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
