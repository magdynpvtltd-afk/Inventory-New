<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Inventory · Operator Manual';
$current_page  = 'inventory-training.php';
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
            <div class="brand-title">Inventory Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#items">Inventory Items</a></li>
            <li><a href="#locations">Locations</a></li>
            <li><a href="#transactions">Transactions (Ledger)</a></li>
            <li><a href="#process">Process Inventory (BOM consume)</a></li>
            <li><a href="#shiprcpt">Ship &amp; Receipt</a></li>
            <li><a href="#boms">Bills of Materials</a></li>
            <li><a href="#bomgrid">BOM Tree Grid</a></li>
            <li><a href="#designer">BOM Designer</a></li>
            <li><a href="#history">Transaction History</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Material control</div>
    <h1>Items, BOMs, transactions &mdash; the <strong>shop-floor ledger</strong>.</h1>
    <p class="lede">
        The Inventory module is the canonical record of every part
        number in your shop and every movement of stock between
        locations. Each item carries a code, a unit-of-measure, and
        a running quantity per location. Every change to a quantity
        is a transaction recorded forever &mdash; you can always trace
        why the on-hand qty is what it is.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    Five things make up the inventory module: items, locations, BOMs
    (bills of materials), transactions, and shipment / receipt
    workflows. The first three are static-ish data; transactions and
    shipments are the active machinery that moves stock around.
  </p>

  <h3>The core entities</h3>
  <table>
    <thead><tr><th style="width:24%">ENTITY</th><th>WHAT IT IS</th></tr></thead>
    <tbody>
      <tr><td>Item</td><td class="dim">A part number &mdash; raw material, sub-assembly, finished good. Each item has a unique code (e.g. <code>I-00042</code>), a name, a UOM, and metadata. One item maps to one physical thing your shop tracks.</td></tr>
      <tr><td>Location</td><td class="dim">A physical place stock can live &mdash; a bin, a shelf, a stockroom. Items have quantity at a location; the same item at two locations is tracked separately.</td></tr>
      <tr><td>BOM (Bill of Materials)</td><td class="dim">A list of child items required to produce one unit of a parent item. Hierarchical &mdash; child items can themselves have BOMs.</td></tr>
      <tr><td>Transaction</td><td class="dim">A logged movement of stock. Eight types (see &sect;04). Each transaction adjusts on-hand qty at a location and is immutable once posted.</td></tr>
      <tr><td>Shipment</td><td class="dim">A ship-to-vendor / receive-from-vendor event with multiple line items and optional partial-receipt tracking. See &sect;06.</td></tr>
    </tbody>
  </table>

  <h3>The sidebar entries</h3>
  <p>
    Under the Inventory group in the left sidebar:
    <em>View Inventory</em> (items list),
    <em>View BOMs</em> (BOM list + designer),
    <em>Process Inventory</em> (consume per BOM),
    <em>Ship &amp; Receipt</em> (vendor cycle workflow),
    <em>Transaction history</em> (global ledger).
  </p>
</section>

<!-- ============ ITEMS ============ -->
<section class="module" id="items">
  <h2><span class="num">02</span> Inventory Items</h2>

  <p>
    An item is one row in the parts catalog. Each item has a code
    (auto-generated as <code>I-NNNNN</code> via Code Sequences, or
    hand-entered), a name, a unit of measure, optional short
    description, and a few metadata fields.
  </p>

  <h3>Creating an item</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open View Inventory</strong> and click "+ New item" at the top right.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the form:</strong> code (auto-filled), name, UOM, short description.</p>
        <p class="sub">UOM (unit of measure) is required. Common values are EA (each), PC (piece), KG, M, L. UOMs are admin-configurable in Inspection UOMs (a shared lookup).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The item appears in the catalog with zero quantity at every location. Stock is added via transactions.</p>
      </div>
    </div>
  </div>

  <h3>The item view page</h3>
  <p>
    Open an item to see its current on-hand qty per location, its BOM
    (if any), and a tab for recent transactions. From here you can:
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>Edit the item's metadata</li>
    <li>Clone the item (creates a new one with the same metadata but a fresh code)</li>
    <li>Open the BOM designer for this item (if it has a BOM)</li>
    <li>Post a manual transaction against the item (issue, receive, adjust)</li>
  </ul>

  <h3>Bulk import</h3>
  <p>
    The Import button on the View Inventory toolbar opens a CSV-based
    preview-then-commit importer. Download the template, fill in
    rows, upload, review the preview for errors, click "Commit
    import". Same pattern as the Assets importer.
  </p>
</section>

<!-- ============ LOCATIONS ============ -->
<section class="module" id="locations">
  <h2><span class="num">03</span> Locations</h2>

  <p>
    Locations are managed in Admin &rarr; Locations. Each item carries
    its quantity per location independently &mdash; the same part can
    be in stock in Shelf A and on the shop floor at the same time, and
    moves between them are tracked.
  </p>

  <h3>Location structure</h3>
  <p>
    Locations are flat-tree: each location has an optional parent so
    you can model building &rarr; room &rarr; shelf, but every
    transaction names a specific location code (not a path). Useful
    structures: by storeroom (Raw, WIP, Finished, Quarantine), by
    shop area (CNC Cell 1, Assembly Bay, Shipping Dock), or by
    customer/project (Project Acme, Project Beta).
  </p>

  <h3>Reserved system locations</h3>
  <p>
    Five location codes have special meaning to the system. They're
    seeded by migrations and used by automated flows. <strong>Do not
    rename them.</strong>
  </p>
  <table>
    <thead><tr><th style="width:22%">CODE</th><th>PURPOSE</th></tr></thead>
    <tbody>
      <tr><td><code>LOC-QCH</code></td><td class="dim">Quality Check Hold. All incoming material (Ship &amp; Receipt receipts) and all internal production (Process Inventory) lands here first. Stock waits here until an inspection is approved.</td></tr>
      <tr><td><code>ST-HLD</code></td><td class="dim">Store Hold. Where stock lands when an inspection is approved as <em>passed</em>. The store team then moves it to its final shelf via the Move action.</td></tr>
      <tr><td><code>LOC-REJ</code></td><td class="dim">Reject. Where inspection-<em>failed</em> stock is auto-routed. Treat as quarantine pending RTV (return-to-vendor) or scrap.</td></tr>
      <tr><td><code>O-Rework</code></td><td class="dim">Outgoing rework. When the inspection's approver clicks Rework and picks O-Rework, the qty lands here. Typically used for ship_in / receive lots that the vendor will rework or replace.</td></tr>
      <tr><td><code>I-Rework</code></td><td class="dim">Internal rework. Picked at approve-time when the rework will be done in-house. Stock here is reprocessed via Process Inventory &mdash; the Rework checkbox auto-ticks when the product has I-Rework stock.</td></tr>
    </tbody>
  </table>

  <h3>Incoming material always lands at LOC-QCH</h3>
  <p>
    Receipts from Ship &amp; Receipt and headers from Process Inventory
    no longer let you pick a destination. They both land at
    <code>LOC-QCH</code> automatically and trigger a draft inspection.
    The inspection's approval routes the stock onward:
    pass &rarr; <code>ST-HLD</code>, fail &rarr; <code>LOC-REJ</code>,
    rework &rarr; the approver picks <code>O-Rework</code> or
    <code>I-Rework</code>. The store team then uses Move to take
    passed stock from <code>ST-HLD</code> to its final shelf.
    See &sect;05 (Process Inventory) for the production cycle and the
    Inspection manual &sect;06 for the QC release matrix.
  </p>

  <h3>The QC process flow</h3>
  <p>
    The diagram below maps how stock moves through the system from the
    moment it enters MagDyn (top of the diagram) to its final
    disposition (bottom). It is reproduced from the Inspection manual
    &sect;06; reading both manuals together is the fastest way to
    onboard a new store/QC team member.
  </p>

  <figure style="margin:18px 0;text-align:center;">
    <svg viewBox="0 0 940 660" xmlns="http://www.w3.org/2000/svg"
         style="max-width:100%;height:auto;font-family:inherit;" role="img"
         aria-label="QC process flow from material entry through inspection and verdict routing">
      <defs>
        <marker id="qcArrowInv" viewBox="0 0 10 10" refX="9" refY="5"
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
      <path class="qcline" d="M 190 91  L 190 140 L 470 140 L 470 158" marker-end="url(#qcArrowInv)"/>
      <path class="qcline" d="M 470 91  L 470 158" marker-end="url(#qcArrowInv)"/>
      <path class="qcline" d="M 750 91  L 750 140 L 470 140 L 470 158" marker-end="url(#qcArrowInv)"/>

      <text x="20" y="180" class="qclab" fill="#475569">2. Stock lands at LOC-QCH</text>
      <rect class="qcbox qchold" x="320" y="160" width="300" height="64" rx="6"/>
      <text x="470" y="184" text-anchor="middle" class="qclab qcholdt">LOC-QCH (Quality Check Hold)</text>
      <text x="470" y="202" text-anchor="middle" class="qcsub qcholdt">+ a draft inspection is auto-created</text>
      <text x="470" y="216" text-anchor="middle" class="qcsub qcholdt">against the +qty txn</text>
      <path class="qcline" d="M 470 224 L 470 268" marker-end="url(#qcArrowInv)"/>

      <text x="20" y="290" class="qclab" fill="#475569">3. Inspection lifecycle</text>
      <rect class="qcbox" x="320" y="270" width="300" height="72" rx="6"/>
      <text x="470" y="292" text-anchor="middle" class="qclab">draft → in_progress → approved</text>
      <text x="470" y="308" text-anchor="middle" class="qcsub">inspector records measurements (Execute)</text>
      <text x="470" y="322" text-anchor="middle" class="qcsub">approver applies verdict</text>
      <text x="470" y="336" text-anchor="middle" class="qcsub" fill="#7c3aed">two-person rule: inspector ≠ approver</text>
      <path class="qcline" d="M 470 342 L 470 380" marker-end="url(#qcArrowInv)"/>
      <polygon class="qcbox qcvdct" points="470,380 590,420 470,460 350,420"/>
      <text x="470" y="416" text-anchor="middle" class="qclab">Verdict?</text>
      <text x="470" y="432" text-anchor="middle" class="qcsub">approver picks one</text>

      <text x="20" y="500" class="qclab" fill="#475569">4. Stock routes</text>
      <path class="qcline" d="M 365 415 L 90 415 L 90 500" marker-end="url(#qcArrowInv)"/>
      <text x="225" y="408" class="qcsub" fill="#059669">passed</text>
      <rect class="qcbox qcok" x="20" y="500" width="140" height="56" rx="6"/>
      <text x="90" y="522" text-anchor="middle" class="qclab qcokt">ST-HLD</text>
      <text x="90" y="540" text-anchor="middle" class="qcsub qcokt">store team Moves to shelf</text>

      <path class="qcline" d="M 405 445 L 250 500" marker-end="url(#qcArrowInv)"/>
      <text x="305" y="475" class="qcsub" fill="#dc2626">failed</text>
      <rect class="qcbox qcbad" x="180" y="500" width="140" height="56" rx="6"/>
      <text x="250" y="522" text-anchor="middle" class="qclab qcbadt">LOC-REJ</text>
      <text x="250" y="540" text-anchor="middle" class="qcsub qcbadt">RTV or scrap</text>

      <path class="qcline" d="M 470 462 L 470 488" marker-end="url(#qcArrowInv)"/>
      <text x="485" y="478" class="qcsub" fill="#7c3aed">rework</text>
      <text x="485" y="490" class="qcsub" fill="#7c3aed">(approver picks)</text>
      <rect class="qcbox qcrw" x="365" y="488" width="100" height="56" rx="6"/>
      <text x="415" y="510" text-anchor="middle" class="qclab qcrwt">O-Rework</text>
      <text x="415" y="528" text-anchor="middle" class="qcsub qcrwt">vendor returns</text>
      <rect class="qcbox qcrw" x="475" y="488" width="100" height="56" rx="6"/>
      <text x="525" y="510" text-anchor="middle" class="qclab qcrwt">I-Rework</text>
      <text x="525" y="528" text-anchor="middle" class="qcsub qcrwt">fix in-house</text>

      <path class="qcline" d="M 535 445 L 700 500" marker-end="url(#qcArrowInv)"/>
      <text x="600" y="475" class="qcsub" fill="#475569">hold</text>
      <rect class="qcbox" x="610" y="500" width="160" height="56" rx="6"/>
      <text x="690" y="522" text-anchor="middle" class="qclab">stays at LOC-QCH</text>
      <text x="690" y="540" text-anchor="middle" class="qcsub">await re-approval</text>

      <path class="qcline" d="M 575 415 L 850 415 L 850 500" marker-end="url(#qcArrowInv)"/>
      <text x="710" y="408" class="qcsub" fill="#475569">cancelled</text>
      <rect class="qcbox" x="790" y="500" width="130" height="56" rx="6"/>
      <text x="855" y="522" text-anchor="middle" class="qclab">no movement</text>
      <text x="855" y="540" text-anchor="middle" class="qcsub">manual handling</text>

      <path class="qcdash" d="M 525 544 L 525 600 L 470 600 L 470 91" marker-end="url(#qcArrowInv)"/>
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
</section>

<!-- ============ TRANSACTIONS ============ -->
<section class="module" id="transactions">
  <h2><span class="num">04</span> Transactions (the Ledger)</h2>

  <p>
    Every change to inventory is a transaction. Transactions are
    append-only &mdash; once posted, never edited. Corrections happen
    via new "correction" transactions that reverse the bad one and
    apply the right one, leaving an audit trail.
  </p>

  <h3>Transaction types</h3>
  <p>
    The ledger has <strong>seven</strong> transaction types. The
    <code>process</code> type covers both the produced parent's
    <em>+qty</em> row and each consumed child's <em>&minus;qty</em> row
    (linked via <code>parent_txn_id</code>) &mdash; one logical event,
    multiple ledger rows.
  </p>
  <table>
    <thead><tr><th style="width:18%">TYPE</th><th style="width:18%">EFFECT</th><th>USE FOR</th></tr></thead>
    <tbody>
      <tr><td><strong>receive</strong></td><td class="dim">+ at location</td><td class="dim">Manual receipt of stock with no parent shipment. Use sparingly &mdash; prefer Ship &amp; Receipt for vendor flows.</td></tr>
      <tr><td><strong>issue</strong></td><td class="dim">&minus; at location</td><td class="dim">Manual draw-down of stock for any purpose not covered by another type.</td></tr>
      <tr><td><strong>move</strong></td><td class="dim">&minus; at one, + at another</td><td class="dim">Atomic transfer between two locations. Posts a paired set of ledger rows (the &minus; row is the parent of the + row). Used by the Move button and by inspection approval to release stock from <code>LOC-QCH</code>.</td></tr>
      <tr><td><strong>adjust</strong></td><td class="dim">&plusmn; at location</td><td class="dim">Stock-take corrections, write-offs, found stock. Delta can be either sign.</td></tr>
      <tr><td><strong>process</strong></td><td class="dim">+ parent, &minus; children</td><td class="dim">Posted by Process Inventory. Parent row at <code>LOC-QCH</code>; children debit their own source locations (or <code>I-Rework</code> if the Rework checkbox was used). See &sect;05.</td></tr>
      <tr><td><strong>ship_out</strong></td><td class="dim">&minus; at location</td><td class="dim">Auto-posted by a Ship &amp; Receipt shipment when ship-side lines are confirmed. Don't post manually.</td></tr>
      <tr><td><strong>ship_in</strong></td><td class="dim">+ at LOC-QCH</td><td class="dim">Auto-posted by a Ship &amp; Receipt receipt event. Forced to <code>LOC-QCH</code>. Don't post manually.</td></tr>
    </tbody>
  </table>

  <h3>Where to post each type</h3>
  <table>
    <thead><tr><th style="width:30%">TYPE</th><th>WHERE</th></tr></thead>
    <tbody>
      <tr><td><code>receive</code> / <code>issue</code> / <code>adjust</code></td><td class="dim">Sidebar &rarr; Inventory &rarr; <em>View Inventory</em> &rarr; pick an item &rarr; Ledger &rarr; <em>+ Receive</em> / <em>&minus; Issue</em> / <em>Adjust</em>.</td></tr>
      <tr><td><code>move</code></td><td class="dim">From <em>View Inventory</em>, the item Ledger, or the BOM grid actions menu &mdash; the <em>⇄ Move</em> button. See &sect;05 (Move stock) below.</td></tr>
      <tr><td><code>process</code></td><td class="dim">Sidebar &rarr; Inventory &rarr; <em>Process Inventory</em>. See &sect;05.</td></tr>
      <tr><td><code>ship_out</code> / <code>ship_in</code></td><td class="dim">Auto-posted by Ship &amp; Receipt. See &sect;06.</td></tr>
    </tbody>
  </table>

  <div class="callout warn">
    <div class="label">QTY CAN'T GO NEGATIVE</div>
    <p>The system blocks any transaction that would drive on-hand qty below zero at the affected location. If you legitimately need a negative on-hand (mid-cycle production where consumption posts before receipt), you'll see an error. Either receive first, or post an <code>adjust</code> to set the on-hand correctly.</p>
  </div>
</section>

<!-- ============ PROCESS ============ -->
<section class="module" id="process">
  <h2><span class="num">05</span> Process Inventory (BOM Consume)</h2>

  <p>
    Process Inventory produces N units of a parent item, consuming
    the BOM children from their chosen source locations. The parent
    qty always lands at <code>LOC-QCH</code> &mdash; the system also
    auto-creates a draft inspection so the produced batch can't be
    used downstream until QC has signed off.
  </p>

  <h3>How it works</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open Process Inventory</strong> from the sidebar.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick the parent item</strong> you're producing.
          The right pane auto-loads the BOM &mdash; one row per child
          item with required qty and a "Pull from" dropdown.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Enter the production quantity.</strong> Required
          qty per child = BOM line qty &times; production qty.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a source location for each child line.</strong>
          The "Pull from" dropdown lists only locations with stock of
          that child item &mdash; locations with zero are hidden, so
          you can't accidentally pull from an empty bin.</p>
        <p class="sub">Each row's Status column shows OK or SHORT. The
          Process button is disabled until every line has a source and
          no line is short.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click Process.</strong> The system posts a
          <code>process</code> txn for the parent at <code>LOC-QCH</code>,
          and one <code>process</code> txn per child at its chosen
          source. All rows commit together &mdash; either all succeed
          or all roll back.</p>
        <p class="sub">A draft inspection is auto-created against the
          parent's txn. It appears in <em>Inspection &rarr; Pending</em>
          immediately.</p>
      </div>
    </div>
  </div>

  <div class="callout warn">
    <div class="label">DESTINATION IS LOCKED TO LOC-QCH</div>
    <p>There is no destination picker on the form. The parent qty
       always lands at <code>LOC-QCH</code> and waits there until the
       inspection is approved. The store team takes it onward from
       <code>ST-HLD</code> after a Pass verdict.</p>
  </div>

  <h3>The two checkboxes</h3>
  <table>
    <thead><tr><th style="width:24%">CHECKBOX</th><th>WHAT IT DOES</th></tr></thead>
    <tbody>
      <tr><td><strong>Direct addition</strong></td><td class="dim">Adds the parent qty at <code>LOC-QCH</code> with <em>no</em> child consumption. Use when receiving a finished assembly from outside that bypasses the BOM (a sample, a free issue, a recovered part). Still triggers a draft inspection.</td></tr>
      <tr><td><strong>Rework</strong></td><td class="dim">Consumes the <em>same item</em> from <code>I-Rework</code> instead of cascading through the BOM children. Use after fixing a previously-rejected instance: the original sat in I-Rework, you finished the rework, and the unit is now ready to re-enter circulation. <strong>Auto-ticks</strong> when the picked product has stock at I-Rework &mdash; you'll see a blue hint in the right pane confirming the auto-selection. Untick if you actually intend to produce fresh from BOM children. Mutually exclusive with Direct addition.</td></tr>
    </tbody>
  </table>

  <div class="callout">
    <div class="label">BOM EXPLOSION</div>
    <p>Process Inventory uses the parent's <em>direct</em> BOM &mdash;
       it doesn't recursively explode child BOMs. If you're producing
       an assembly whose children are themselves sub-assemblies,
       process the sub-assemblies first (each gets its own QC cycle),
       then process the top-level assembly. This is intentional: it
       forces explicit accounting and QC of each production step.</p>
  </div>

  <h3>Move stock between locations</h3>
  <p>
    Once an inspection is approved as <em>passed</em>, stock lands in
    <code>ST-HLD</code> (Store Hold). The store team's job is to take
    it from <code>ST-HLD</code> to the final shelf using the
    <strong>Move</strong> action.
  </p>
  <p>
    Move is also the standard way to fix wrong-location problems
    (e.g. a manual receive that landed in the wrong bin) and to
    consolidate stock between bins.
  </p>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open Move</strong> from any of three places:
          the <em>View Inventory</em> row actions (⇄ Move icon), the
          item Ledger header, or the BOM grid actions menu.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick the item</strong> (pre-filled if you opened
          Move from a row). The Source dropdown then shows only
          locations that currently hold stock of that item, with the
          available qty alongside each.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a destination, qty, and date,</strong>
          optionally a reference (work order, BOL, etc.) and notes.
          Source and destination cannot be the same location.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> Two paired <code>move</code> ledger
          rows post atomically: a &minus; at the source and a + at
          the destination, linked by <code>parent_txn_id</code>.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============ SHIP & RCPT ============ -->
<section class="module" id="shiprcpt">
  <h2><span class="num">06</span> Ship &amp; Receipt</h2>

  <p>
    The Ship &amp; Receipt module handles vendor cycles &mdash; sending
    raw material out, receiving finished sub-assemblies back, or any
    receipt of stock from a vendor with a delivery note. It supports
    partial deliveries: one shipment can be received in N separate
    receipt events on different days to different locations.
  </p>

  <h3>The three modes</h3>
  <table>
    <thead><tr><th style="width:22%">MODE</th><th>USE FOR</th></tr></thead>
    <tbody>
      <tr><td>Receive only</td><td class="dim">Stock coming IN from a vendor &mdash; pure purchase receipt, no outbound. Only "receive" lines on the shipment.</td></tr>
      <tr><td>Ship only</td><td class="dim">Stock going OUT to a vendor with nothing expected back &mdash; scrap return, customer free issue, etc. Only "ship" lines.</td></tr>
      <tr><td>Ship &amp; Receive</td><td class="dim">Rework / process cycle. Send raw material to a vendor (ship lines), get finished or processed material back (receive lines). One shipment header covers both directions.</td></tr>
    </tbody>
  </table>

  <h3>The flow</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Create the shipment.</strong> Sidebar &rarr; Inventory &rarr; <em>Ship &amp; Receipt</em> &rarr; "+ New shipment". Pick mode, vendor, due date(s), reference doc, line items.</p>
        <p class="sub">For each line, set Direction (ship or receive), pick the item, enter planned qty. For ship lines, also pick the source location. Receive lines don't set destination here &mdash; destination is captured at each receipt event.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Approve the shipment.</strong> Open the shipment view, click "Approve" in the toolbar. Status flips from <code>draft</code> to <code>approved</code>.</p>
        <p class="sub">Draft shipments are still editable. Once approved, line items become locked.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Post the ship-out</strong> (if any ship lines exist). One click posts <code>ship_out</code> transactions for every ship line, debiting their source locations.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Record receipts</strong> as deliveries arrive. The
          "Record a receipt" inline form (on the shipment view, below
          the line items) takes: receive line, qty arrived, receipt
          date, notes. Click Record &mdash; a <code>ship_in</code>
          transaction posts.</p>
        <p class="sub">All receipts land at <code>LOC-QCH</code> and
          auto-create a draft inspection &mdash; you can't pick a
          destination here. The inspection's approval routes the stock
          out (pass &rarr; ST-HLD; fail &rarr; LOC-REJ;
          rework &rarr; O-Rework). Until the inspection is approved,
          the qty stays at LOC-QCH.</p>
        <p class="sub">Each receipt is a separate event. Multiple
          receipts can land on the same line on different days; the
          line's running "received" total accumulates and turns into
          a green "full" pill when it hits the planned qty.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Close the shipment</strong> when everything is in. Click "🔒 Close" in the toolbar. Status becomes <code>closed</code>. Receipts can no longer be recorded.</p>
      </div>
    </div>
  </div>

  <h3>Vendor performance tracking</h3>
  <p>
    Each receipt event captures the receipt date and compares it to
    the shipment's <em>receive due date</em>. The lateness (days
    early or days late, signed) shows up in the receipt history
    and feeds vendor performance reporting.
  </p>

  <div class="callout note">
    <div class="label">CONDITIONAL DATE FIELDS</div>
    <p>The "ship due date" and "receive due date" fields on the shipment header are only relevant for modes that include that side. The form hides the irrelevant field automatically when you toggle modes &mdash; pick "Receive only" and the ship-due field disappears.</p>
  </div>
</section>

<!-- ============ BOMS ============ -->
<section class="module" id="boms">
  <h2><span class="num">07</span> Bills of Materials (BOMs)</h2>

  <p>
    A BOM is the recipe for one item. Each row in a BOM is a child
    item plus the quantity required to produce one unit of the parent.
    BOMs power the Process Inventory consumption flow (&sect;05).
  </p>

  <h3>The two BOM views</h3>
  <table>
    <thead><tr><th style="width:24%">VIEW</th><th>USE FOR</th></tr></thead>
    <tbody>
      <tr><td>Line view</td><td class="dim">Tabular list of every BOM row in the database. Sortable by parent, child, qty. Best for bulk editing, exporting, finding specific BOM lines.</td></tr>
      <tr><td>BOM view (designer)</td><td class="dim">Per-parent visual tree showing the multi-level structure of an assembly's BOM. Best for understanding structure and editing individual assemblies. See &sect;08.</td></tr>
    </tbody>
  </table>

  <h3>Creating a BOM line manually</h3>
  <p>
    From View BOMs, pick "New line". Choose parent item, child item,
    qty per parent. Save. The line is added.
  </p>

  <h3>Importing BOMs in bulk</h3>
  <p>
    The Import action on the View BOMs page takes a CSV with columns
    parent_code, child_code, qty. Each row becomes one BOM line.
    Useful for bringing in BOMs from a CAD package or ERP export.
  </p>

  <div class="callout warn">
    <div class="label">CIRCULAR BOMS</div>
    <p>The system blocks circular BOMs &mdash; you can't make item A a child of item B if B is already (directly or transitively) a child of A. The check runs at save time. If you legitimately need a cyclic structure (rare), reorganise the BOM into separate non-cyclic pieces.</p>
  </div>
</section>

<!-- ============ DESIGNER ============ -->
<!-- ============ BOM GRID ============ -->
<section class="module" id="bomgrid">
  <h2><span class="num">08</span> BOM Tree Grid (View BOMs)</h2>

  <p>
    Sidebar &rarr; Inventory &rarr; <em>View BOMs</em> opens the tree
    grid: every finished good (anything tagged as a product) is a top
    row, its BOM children sit indented underneath, and a single +
    expands the entire subtree at once.
  </p>

  <h3>Reading a product row</h3>
  <p>
    Each row's Product Name is prefixed with the item's inv code in
    parens &mdash; <code>(MD-NEX-12-129-651)Non-Extrusion Plate</code>
    &mdash; so you don't have to mouse-over to know what you're looking
    at. The five numeric columns each have a specific meaning:
  </p>
  <table>
    <thead><tr><th style="width:14%">COLUMN</th><th>WHAT IT IS</th></tr></thead>
    <tbody>
      <tr><td><strong>Avb</strong></td><td class="dim">Total qty across all locations <em>except</em> <code>O-Rework</code>, <code>I-Rework</code>, <code>LOC-REJ</code>. The "available for use" figure. Stock parked in QC Hold (<code>LOC-QCH</code>) and Store Hold (<code>ST-HLD</code>) is counted here because it's still good stock &mdash; only the rework and reject locations are excluded.</td></tr>
      <tr><td><strong>REJ</strong></td><td class="dim">Qty at <code>LOC-REJ</code>. Rejected stock awaiting RTV or scrap.</td></tr>
      <tr><td><strong>TBR</strong></td><td class="dim">"To be received" &mdash; sum of open receive-line balances from Ship &amp; Receipt (planned minus already received) across non-cancelled, non-closed shipments. Tells you what's still in the pipe.</td></tr>
      <tr><td><strong>Rework</strong></td><td class="dim">Qty at <code>O-Rework</code> + qty at <code>I-Rework</code>. Stuff currently being reworked, either at a vendor or in-house.</td></tr>
      <tr><td><strong>Options</strong></td><td class="dim">⚙ menu with Edit item, Ledger / stock history, Move stock, BOM designer, Process, Clone BOM (managers only), Notes.</td></tr>
    </tbody>
  </table>

  <h3>Pending-SO badge</h3>
  <p>
    If MagDyn is integrated with an external sales-order app, a small
    amber pill appears next to any part name that has open sales
    orders. The pill shows <code>(N &middot; Q)</code> &mdash;
    <em>N</em> open SOs, <em>Q</em> total units pending. Hover for
    the full description.
  </p>
  <p>
    Parts with no open SOs show no pill at all, so a clean tree
    reads as &ldquo;nothing on the backlog right now&rdquo;.
  </p>
  <div class="callout note">
    <div class="label">PUSH-BASED INTEGRATION</div>
    <p>The badge data lives in MagDyn's local <code>inv_so_pending_summary</code> table.
    The external SO app pushes updates to MagDyn's <code>/api/so_pending.php</code>
    endpoint every time an SO is added / amended / closed; MagDyn never reaches out to
    the SO app. This means the BOM grid never blocks on inter-app calls and never shows
    stale-due-to-timeout numbers &mdash; the data is exactly as fresh as the most
    recent push.</p>
    <p class="sub">See <code>docs/SO_INTEGRATION_API.md</code> for the API contract.
    To enable, set <code>so_integration.bearer_token</code> in
    <code>config/app.config.php</code> and hand the same value to the SO-app
    developer.</p>
  </div>
  <p>
    If you've just changed something in the SO app and the badge
    hasn't updated, check that the SO app actually called MagDyn's
    upsert endpoint (look at the SO app's logs). MagDyn doesn't
    poll, so a missed push means a stale number until the next push
    arrives (or the nightly <code>bulk_replace</code> drift recovery).
  </p>

  <h3>Expanding and collapsing</h3>
  <p>
    Clicking the <kbd>+</kbd> beside a row opens the <em>entire</em>
    subtree under that node in one click &mdash; every descendant level
    becomes visible. Clicking <kbd>&minus;</kbd> collapses the whole
    subtree. The <em>Expand all</em> / <em>Collapse all</em> toolbar
    buttons act on every row at once.
  </p>

  <h3>Division tabs</h3>
  <p>
    The tabs at the top filter the grid by division (Inventory category
    of type 'division'). The count next to each tab is the number of
    finished goods in that division.
  </p>
</section>

<!-- ============ DESIGNER ============ -->
<section class="module" id="designer">
  <h2><span class="num">09</span> BOM Designer</h2>

  <p>
    The BOM Designer is a drag-and-drop tree editor for the BOM of
    one parent item. Open it from the BOM view (View BOMs &rarr;
    pick a BOM &rarr; "Designer"). The screen splits into two panes:
    a palette of items on the left, the BOM tree on the right.
  </p>

  <h3>Building a BOM in the designer</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Drag an item from the palette into the tree.</strong> The drop position determines where it lands &mdash; above another node, below it, or INSIDE it as a child.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Drop "into" a node</strong> by aiming for the "↳ drop here to nest" strip that appears below each node when you hover. That nests the dropped item as a child of the hovered node.</p>
        <p class="sub">Drop ABOVE or BELOW a node to make it a sibling. Drop INTO the strip to make it a child.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Set the quantity</strong> on each node by clicking it. A small panel shows the qty-per-parent and lets you edit.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The Save button at the top of the tree commits the structure to the database. Until saved, changes are local to the designer session.</p>
      </div>
    </div>
  </div>

  <h3>Cycles and depth limits</h3>
  <p>
    The designer detects cycles at render time and shows a "Cycle
    detected" placeholder where it would otherwise loop infinitely.
    The tree has a hard depth cap (50 levels) so a misbehaving BOM
    can't crash the renderer. Both are defensive &mdash; in normal use
    you won't encounter them.
  </p>

  <h3>Cloning a BOM</h3>
  <p>
    The Clone action on the View BOMs page copies an entire BOM
    structure under a new parent. Useful when creating a variant of
    an assembly that shares 90% of its BOM with the original.
  </p>
</section>

<!-- ============ HISTORY ============ -->
<section class="module" id="history">
  <h2><span class="num">10</span> Transaction History</h2>

  <p>
    Sidebar &rarr; Inventory &rarr; <em>Transaction history</em>
    shows every inventory transaction across every item. Filterable
    by type, item, location, date range, and free-text search across
    notes and reference docs.
  </p>

  <h3>Reading a transaction row</h3>
  <table>
    <thead><tr><th style="width:22%">COLUMN</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>When</td><td class="dim">Wallclock timestamp when posted.</td></tr>
      <tr><td>Type</td><td class="dim">Color-coded pill (receive green, issue red, move blue, adjust amber, ship/process variants).</td></tr>
      <tr><td>Item</td><td class="dim">Code + name. Linked to the item view.</td></tr>
      <tr><td>Location</td><td class="dim">The single location this ledger row affects. A <code>move</code> event produces <em>two</em> ledger rows (one negative, one positive) linked via <code>parent_txn_id</code>; each row shows its own side's location.</td></tr>
      <tr><td>Qty delta</td><td class="dim">Signed. Positive = stock added at the location, negative = stock removed.</td></tr>
      <tr><td>Ref doc</td><td class="dim">PO number, work order, or whatever was entered at post time.</td></tr>
      <tr><td>Notes</td><td class="dim">Free text.</td></tr>
      <tr><td>By</td><td class="dim">User who posted it.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">11</span> Common Workflows</h2>

  <h3>Receiving a PO from a vendor</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Create a Ship &amp; Receipt shipment in mode "Receive only". Set vendor, receive due date, PO number as reference.</li>
    <li>Add one receive-line per item on the PO: item, planned qty.</li>
    <li>Approve the shipment.</li>
    <li>When the delivery arrives, record a receipt event for each line. <strong>No destination to pick</strong> &mdash; the qty lands at <code>LOC-QCH</code> and a draft inspection is created. Partial deliveries are fine; come back when the rest arrives.</li>
    <li>Inspect the lot (see the Inspection manual). On approval: pass routes to <code>ST-HLD</code>, fail to <code>LOC-REJ</code>, rework to <code>O-Rework</code>.</li>
    <li>For passed lots, the store team uses <em>Move</em> to take the qty from <code>ST-HLD</code> to its final shelf.</li>
    <li>When the whole shipment has been received and routed, close the shipment.</li>
  </ol>

  <h3>Sending material for vendor processing (rework cycle)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Create a shipment in mode "Ship &amp; Receive". Set the vendor and both due dates (ship-by and receive-by).</li>
    <li>Add ship lines for the raw material going out (with source locations) and receive lines for the finished material coming back.</li>
    <li>Approve the shipment.</li>
    <li>Post the ship-out when material physically leaves.</li>
    <li>As finished material returns, record receipt events. Each receipt lands at <code>LOC-QCH</code> and gets its own QC inspection &mdash; same flow as a PO receipt.</li>
    <li>Close when everything is in and routed.</li>
  </ol>

  <h3>Producing an assembly from a BOM</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Confirm all child items have enough stock at usable locations (Avb column in the BOM grid, or stock breakdown on the Ledger page).</li>
    <li>Open Process Inventory, pick the parent item, enter production quantity.</li>
    <li>For each child line, pick the "Pull from" source location. Locations with zero stock are hidden &mdash; only viable sources show.</li>
    <li>Click Process. The parent lands at <code>LOC-QCH</code>; children are consumed at their sources; a draft inspection is created.</li>
    <li>Run the inspection (see Inspection manual). On approval: pass &rarr; <code>ST-HLD</code>; fail &rarr; <code>LOC-REJ</code>; rework &rarr; <code>I-Rework</code>.</li>
    <li>Store team moves passed stock from <code>ST-HLD</code> to its destination shelf.</li>
  </ol>

  <h3>Finishing a rework cycle (internal)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>The item is currently sitting at <code>I-Rework</code> from an earlier rework verdict.</li>
    <li>Open Process Inventory and pick the same item. The Rework checkbox <strong>auto-ticks</strong> because the system sees stock at I-Rework for that product. A blue hint in the right pane confirms it.</li>
    <li>Enter the qty, click Process. The unit moves from <code>I-Rework</code> to <code>LOC-QCH</code> and gets a fresh QC inspection.</li>
    <li>From here it's the same as a regular production cycle.</li>
  </ol>

  <h3>Moving passed stock from ST-HLD to a shelf</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open the item from View Inventory (⇄ Move) or the Ledger page (⇄ Move button).</li>
    <li>Source: <code>ST-HLD</code>. Destination: the target shelf. Qty: the amount to move.</li>
    <li>Save. Two paired ledger rows post atomically.</li>
  </ol>

  <h3>Cycle-counting a location</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open View Inventory, filter to the location you're counting.</li>
    <li>Walk the location physically, comparing each item's on-hand count to the system value.</li>
    <li>For each mismatch, post an <code>adjust</code> transaction with the delta (positive if you found more, negative if you found less). Notes: "Cycle count [date] &mdash; discrepancy due to [reason]".</li>
    <li>Run a Transaction History report filtered to type=adjust and date=today to capture the total cycle-count delta for the day.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">12</span> Troubleshooting</h2>

  <h3>Transaction is failing with "qty would go negative"</h3>
  <p>
    The system won't let any transaction drive on-hand qty below
    zero at the affected location. Either the source location
    doesn't have what you think it has (check stock levels), or
    you're posting in the wrong order (consume before receive).
    Post an <code>adjust</code> to correct the on-hand first if
    the physical stock is actually present.
  </p>

  <h3>Process Inventory says "no BOM for this item"</h3>
  <p>
    The parent item you picked has no BOM lines defined. Either
    set up the BOM first (View BOMs &rarr; pick parent &rarr; add
    lines or use designer), or use a plain Issue / Receive
    transaction instead of Process Inventory.
  </p>

  <h3>I imported BOM lines but the BOM looks empty</h3>
  <p>
    Usually the parent code or child code in the CSV didn't match
    an existing item. The preview will have flagged it as ERROR or
    WARN &mdash; re-upload and read the preview more carefully.
    Item codes are case-sensitive.
  </p>

  <h3>The designer's drag-and-drop only moves above/below, never INTO</h3>
  <p>
    Aim for the "↳ drop here to nest" strip that appears below
    each node on hover. That strip is the drop target for nesting.
    Dropping near a node's row only reorders siblings. If the strip
    doesn't appear, refresh the page &mdash; the designer's per-node
    drop strips are added on bind and a partial render can miss them.
  </p>

  <h3>I moved stock from ST-HLD to the wrong shelf</h3>
  <p>
    Open the item via View Inventory or Ledger, click <em>⇄ Move</em>,
    pick the wrong shelf as Source, the correct shelf as Destination,
    and the same qty. Note the reason ("Correction: previous Move on
    [date] went to the wrong bin"). The original move stays in the
    ledger; this Move documents the correction.
  </p>

  <h3>An inspection rejected a lot &mdash; can I move it out of LOC-REJ?</h3>
  <p>
    Yes, but think about why first. <code>LOC-REJ</code> is meant as
    a quarantine. If you really need to ship the rejected stock back
    to a vendor or scrap it, post a <code>Move</code> (or in the case
    of return-to-vendor, build a Ship &amp; Receipt ship-only shipment
    out of <code>LOC-REJ</code>). Don't just move it back to a normal
    shelf without re-inspecting &mdash; that defeats the QC trail.
  </p>

  <h3>Shipment status got stuck at "approved" with everything received</h3>
  <p>
    Approved shipments don't auto-close even when fully received.
    Open the shipment view and click "🔒 Close" in the toolbar. The
    status change is manual by design &mdash; gives the user a
    chance to verify everything is right before sealing the
    shipment.
  </p>
</section>

<div class="foot">
    <div>Inventory &middot; Operator Manual &middot; v1.0</div>
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
