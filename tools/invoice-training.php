<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Invoice · Operator Manual';
$current_page  = 'invoice-training.php';
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
            <div class="brand-title">Invoice Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#fields">Invoice Fields</a></li>
            <li><a href="#new">Recording an Invoice</a></li>
            <li><a href="#approve">Approval Flow</a></li>
            <li><a href="#linkage">Linking to Receipts &amp; Assets</a></li>
            <li><a href="#coverage">Coverage Reports</a></li>
            <li><a href="#attachments">Attachments</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">AP workflow</div>
    <h1>Record, approve, and trace every <strong>vendor bill</strong>.</h1>
    <p class="lede">
        The Invoice module is the accounts-payable register for every
        vendor bill that hits your shop. Each invoice ties to a vendor,
        identifies what was billed (an asset purchase or an inventory
        item code), captures amount and date, goes through a pending
        &rarr; approved / rejected flow, and links to specific receipt
        events or asset transactions so you can see exactly what each
        invoice covers.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    An invoice in this module is a record of a bill received from a
    vendor &mdash; not an invoice you send to a customer. Each invoice
    has a header (vendor, invoice number, date) and one or more
    <strong>line items</strong>. Each line item is either an asset
    (referenced by asset tag) or an inventory item (referenced by code),
    with its own qty, unit price, and optional GST / HSN. An invoice
    can mix kinds: line 1 can be an asset, line 2 an inv_item.
  </p>

  <p>
    After the invoice is recorded, each line item can be <strong>linked
    to one or more receipts / asset transactions</strong> at any time
    (independent of approval). The linkage answers, per-line, &ldquo;what
    physical event does this charge correspond to?&rdquo; with
    audit-grade traceability. Linking uses strict code-matching: a line
    for SKU-A can only be linked to receipts of SKU-A.
  </p>

  <h3>The status lifecycle</h3>
  <table>
    <thead><tr><th style="width:20%">STATUS</th><th>MEANING</th></tr></thead>
    <tbody>
      <tr><td><strong>pending</strong></td><td class="dim">Default for a newly recorded invoice. Awaits approver sign-off.</td></tr>
      <tr><td><strong>approved</strong></td><td class="dim">Reviewed and accepted by an approver. Captures the approver's identity and the timestamp. Approval simply flips status; linking is managed independently on the Links page.</td></tr>
      <tr><td><strong>rejected</strong></td><td class="dim">Approver declined the invoice. Carries a rejection reason. Excluded from default list views but retained for audit. <strong>Rejecting clears all txn links</strong> &mdash; a rejected bill is no longer a claim against the received stock.</td></tr>
    </tbody>
  </table>

  <h3>What &ldquo;linked&rdquo; vs &ldquo;unlinked&rdquo; mean</h3>
  <p>
    Both qtys are derived from the link rows on each line:
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:1.8;">
    <li><strong>Linked qty</strong> &mdash; sum of qtys allocated from this line to receipts / asset transactions.</li>
    <li><strong>Unlinked qty</strong> &mdash; <code>line.qty &minus; linked_qty</code>, clamped to zero. The portion of this invoice line not yet matched to a physical event.</li>
  </ul>
  <p>
    The same view holds from the receipt's side: <strong>linked qty</strong>
    on a receipt is what's been claimed across all invoices; <strong>unlinked
    qty</strong> is <code>qty_received &minus; sum(linked)</code> &mdash; the
    portion of the receipt not yet on any invoice.
  </p>

  <h3>Who can do what</h3>
  <table>
    <thead><tr><th style="width:24%">PERMISSION</th><th>WHAT IT GATES</th></tr></thead>
    <tbody>
      <tr><td><code>invoice.view</code></td><td class="dim">See the invoice list, individual invoices, coverage reports.</td></tr>
      <tr><td><code>invoice.manage</code></td><td class="dim">Create / edit invoices, manage line items, upload attachments, create / remove txn links, approve / reject.</td></tr>
      <tr><td><code>invoice.delete</code></td><td class="dim">Hard-delete an invoice row. Distinct from rejection.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ FIELDS ============ -->
<section class="module" id="fields">
  <h2><span class="num">02</span> Invoice Fields</h2>

  <h3>Header fields (required)</h3>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Invoice number <strong>*</strong></td><td class="dim">Vendor-issued invoice number. Required and unique app-wide &mdash; you can't record the same vendor invoice number twice.</td></tr>
      <tr><td>Invoice date <strong>*</strong></td><td class="dim">The date printed on the vendor invoice. Not the date you recorded it.</td></tr>
      <tr><td>Vendor <strong>*</strong></td><td class="dim">Pick from the vendors lookup. Every invoice represents a bill from one specific vendor; for internal debit notes, create a &ldquo;self&rdquo; vendor row.</td></tr>
      <tr><td>Currency</td><td class="dim">Defaults to your shop's home currency (INR). Override per-invoice if the vendor billed in a different currency.</td></tr>
      <tr><td>Notes</td><td class="dim">Free-text header note. PO reference, GL code, any comments for the approver.</td></tr>
    </tbody>
  </table>

  <h3>Line-item fields (one row per item, at least one required)</h3>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Item picker <strong>*</strong></td><td class="dim">Search-and-select combobox. Pick an asset (by tag) or an inv_item (by code). One picker per line; lines can mix kinds.</td></tr>
      <tr><td>Description</td><td class="dim">Snapshot of the item's name at invoice-time. Auto-filled from the source item; editable if the vendor describes it differently.</td></tr>
      <tr><td>Qty <strong>*</strong></td><td class="dim">Quantity billed on this line. Decimal with three digits of precision. For asset lines this is usually 1.</td></tr>
      <tr><td>UoM</td><td class="dim">Defaults to the source item's UoM (or <code>pcs</code> for assets). Free string &mdash; override if the vendor uses an idiosyncratic unit.</td></tr>
      <tr><td>Unit price</td><td class="dim">Price per unit in invoice currency. Line total = <code>qty &times; unit_price</code> (computed at display time, never stored).</td></tr>
      <tr><td>GST %</td><td class="dim">Optional. India: 5 / 12 / 18 / 28; free decimal for other rates.</td></tr>
      <tr><td>HSN</td><td class="dim">Optional. HSN code (India) or HS code (international). Treated as opaque metadata.</td></tr>
      <tr><td>Notes</td><td class="dim">Free text per-line; PO line ref, batch number, etc.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">WHY ITEM_CODE IS A STRING, NOT AN FK</div>
    <p>If the asset or inventory item is later soft-deleted, renamed, or merged, the invoice's record of what was billed shouldn't change. The free-string code preserves history at the cost of slightly looser referential integrity &mdash; a deliberate choice for an audit-grade financial record.</p>
  </div>

  <div class="callout note">
    <div class="label">NO INVOICE-LEVEL TOTAL FIELD</div>
    <p>There's no separate &ldquo;invoice total&rdquo; field. The total is computed as <code>SUM(line.qty &times; line.unit_price)</code>; GST adds to it at display time. If your vendor's PDF shows a total that doesn't match the line-items sum, you've recorded something wrong &mdash; fix the line items rather than overriding a stored total.</p>
  </div>
</section>

<!-- ============ NEW ============ -->
<section class="module" id="new">
  <h2><span class="num">03</span> Recording an Invoice</h2>

  <p>
    Sidebar &rarr; Invoice &rarr; &ldquo;+ New invoice&rdquo; opens the
    form. Requires <code>invoice.manage</code>.
  </p>

  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in the header</strong>: invoice number, invoice date, vendor, currency (defaults to INR), notes (optional).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Add line items.</strong> Each line: use the item picker (asset tag or inv code), set the qty, UoM (auto-filled), unit price, optional GST % + HSN.</p>
        <p class="sub">Use the &ldquo;+ Add line&rdquo; button for additional rows. Lines can mix kinds (asset + inv_item on the same invoice).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Attach the vendor PDF</strong> (optional but strongly recommended &mdash; audit trail).</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> Status is <code>pending</code>. The invoice now appears in the browse list with one row per line item.</p>
      </div>
    </div>
  </div>

  <div class="callout warn">
    <div class="label">DUPLICATE INVOICE NUMBERS</div>
    <p>Each invoice_no must be unique system-wide. If two different vendors happen to issue invoices with the same number (sequential numbering collisions), prefix one with a vendor-disambiguator (e.g., <code>ACME-INV-1234</code>) at the moment of recording. The unique constraint prevents accidental double-payment from a duplicate record.</p>
  </div>

  <div class="callout note">
    <div class="label">EMPTY LINES ARE SKIPPED</div>
    <p>The form lets you leave trailing empty rows. Anything with no item picked is silently ignored at save time, so you can pre-add rows without worrying about clearing them.</p>
  </div>
</section>

<!-- ============ APPROVE ============ -->
<section class="module" id="approve">
  <h2><span class="num">04</span> Approval Flow</h2>

  <p>
    Approval is now a status-only operation. The Approve button on
    the invoice list (or the pending invoice's view page) opens a
    confirmation page showing the line-items coverage (linked vs
    unlinked totals) and a single <strong>Approve invoice</strong>
    button. Linking is managed independently on the Links page
    (see &sect;05) and can be done before or after approval.
  </p>

  <h3>Approving</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the pending invoice.</strong></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Verify the line items</strong> against the attached PDF. Check qty, unit price, GST, line total.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>(Recommended) Open the Links page</strong> first via &ldquo;Manage links&rdquo; to attach receipts / asset transactions to each line. Approving with unlinked qty is allowed but coverage reports will surface the gap.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click &ldquo;Approve invoice&rdquo;</strong> on the confirm page.</p>
        <p class="sub">Status flips to <code>approved</code>; <code>approved_by</code> + <code>approved_at</code> are captured. Existing link rows stay in place.</p>
      </div>
    </div>
  </div>

  <h3>Rejecting</h3>
  <p>
    Click &ldquo;Reject&rdquo; instead of Approve. A reason text-area
    opens. Type the reason (vendor billing error, wrong PO, item never
    received, etc.) and confirm. Status becomes <code>rejected</code>;
    the reason is captured permanently. <strong>Rejecting deletes all
    txn link rows on the invoice</strong> &mdash; a rejected bill is no
    longer a valid claim against received stock.
  </p>

  <h3>Reopening</h3>
  <p>
    An approved or rejected invoice can be reopened to <code>pending</code>
    via the &ldquo;Reopen&rdquo; toolbar action. Useful when an approval
    was premature or a rejection should be reversed after vendor
    correction. <strong>Reopening also clears all txn link rows</strong>,
    forcing you to re-link on the Links page before re-approval &mdash;
    that prevents a forgotten stale linkage from sneaking through.
  </p>

  <div class="callout note">
    <div class="label">ROLE SEPARATION</div>
    <p>By default <code>invoice.manage</code> covers both recording and approval. For stronger SOX-style control, create a separate role that owns approval-time decisions and grant <code>invoice.manage</code> selectively, or implement a separate permission via Admin &rarr; Roles &amp; Permissions.</p>
  </div>
</section>

<!-- ============ LINKAGE ============ -->
<section class="module" id="linkage">
  <h2><span class="num">05</span> Linking to Receipts &amp; Assets</h2>

  <p>
    Each invoice line item can be linked to one or more
    <strong>receipts</strong> (for inv_item lines) or
    <strong>asset transactions</strong> (for asset lines). The linkage
    is per-line and qty-aware: a line of qty 10 can be split across
    two receipts of qty 6 + qty 4, or partially linked (qty 6 linked,
    qty 4 still unlinked).
  </p>

  <h3>The invoice ↔ receipt / asset_txn process flow</h3>
  <p>
    End-to-end view of how invoice line items, receipts, and asset
    transactions thread together:
  </p>

  <figure style="margin:18px 0;text-align:center;">
    <svg viewBox="0 0 940 660" xmlns="http://www.w3.org/2000/svg"
         style="max-width:100%;height:auto;font-family:inherit;" role="img"
         aria-label="Invoice linking process flow from vendor invoice receipt through line items, link page, and linked/unlinked totals">
      <defs>
        <marker id="invArrow" viewBox="0 0 10 10" refX="9" refY="5"
                markerWidth="6" markerHeight="6" orient="auto-start-reverse">
          <path d="M 0 0 L 10 5 L 0 10 z" fill="#475569"/>
        </marker>
        <style>
          .ivbox  { fill: #ffffff; stroke: #475569; stroke-width: 1.5; }
          .ivlab  { font-size: 12px; font-weight: 600; fill: #0f172a; }
          .ivsub  { font-size: 10px; fill: #64748b; }
          .ivline { stroke: #475569; stroke-width: 1.5; fill: none; }
          .ivdash { stroke: #94a3b8; stroke-width: 1.2; fill: none; stroke-dasharray: 4 3; }
          .ivsrc  { fill: #eff6ff; stroke: #3b82f6; }
          .ivsrct { fill: #1e3a8a; }
          .ivinv  { fill: #fef3c7; stroke: #d97706; }
          .ivinvt { fill: #78350f; }
          .ivlink { fill: #ede9fe; stroke: #7c3aed; }
          .ivlinkt{ fill: #4c1d95; }
          .ivok   { fill: #d1fae5; stroke: #059669; }
          .ivokt  { fill: #064e3b; }
          .ivopen { fill: #fee2e2; stroke: #dc2626; }
          .ivopent{ fill: #7f1d1d; }
        </style>
      </defs>

      <!-- ====================== ROW 1 — Physical events ====================== -->
      <text x="20" y="22" class="ivlab" fill="#475569">1. Physical events happen first</text>

      <rect class="ivbox ivsrc" x="80"  y="35" width="240" height="56" rx="6"/>
      <text x="200" y="58" text-anchor="middle" class="ivlab ivsrct">Ship &amp; Receipt — receipt event</text>
      <text x="200" y="76" text-anchor="middle" class="ivsub">posts <tspan font-family="monospace">ship_in</tspan> txn + <tspan font-family="monospace">inv_receipts</tspan> row</text>

      <rect class="ivbox ivsrc" x="620" y="35" width="240" height="56" rx="6"/>
      <text x="740" y="58" text-anchor="middle" class="ivlab ivsrct">Asset transaction</text>
      <text x="740" y="76" text-anchor="middle" class="ivsub"><tspan font-family="monospace">create / receive_vendor / receive_user</tspan></text>

      <!-- ====================== ROW 2 — Invoice received ====================== -->
      <text x="20" y="135" class="ivlab" fill="#475569">2. Vendor invoice arrives</text>

      <rect class="ivbox ivinv" x="280" y="148" width="380" height="80" rx="6"/>
      <text x="470" y="172" text-anchor="middle" class="ivlab ivinvt">Invoice header + N line items</text>
      <text x="470" y="190" text-anchor="middle" class="ivsub ivinvt">each line: kind (asset/inv_item), code, qty, unit price</text>
      <text x="470" y="206" text-anchor="middle" class="ivsub ivinvt">recorded by AP via Invoice → New</text>
      <text x="470" y="220" text-anchor="middle" class="ivsub ivinvt">status: <tspan font-family="monospace">pending</tspan></text>

      <!-- ====================== ROW 3 — Linker page ====================== -->
      <text x="20" y="270" class="ivlab" fill="#475569">3. Approver links each line</text>

      <rect class="ivbox ivlink" x="220" y="285" width="500" height="92" rx="6"/>
      <text x="470" y="308" text-anchor="middle" class="ivlab ivlinkt">Links page (one card per invoice line)</text>
      <text x="470" y="324" text-anchor="middle" class="ivsub ivlinkt">strict code match: line for SKU-A only accepts SKU-A receipts</text>
      <text x="470" y="338" text-anchor="middle" class="ivsub ivlinkt">qty allocation per link row; one line → many txns</text>
      <text x="470" y="354" text-anchor="middle" class="ivsub ivlinkt">remove links per row to free qty for re-allocation</text>
      <text x="470" y="368" text-anchor="middle" class="ivsub ivlinkt">independent of approval — link before or after</text>

      <!-- Connectors from row 1 + row 2 into linker -->
      <path class="ivline" d="M 200 91 L 200 320 L 220 320" marker-end="url(#invArrow)"/>
      <text x="115" y="220" class="ivsub" fill="#475569">candidate</text>
      <text x="115" y="232" class="ivsub" fill="#475569">receipts</text>

      <path class="ivline" d="M 740 91 L 740 320 L 720 320" marker-end="url(#invArrow)"/>
      <text x="755" y="220" class="ivsub" fill="#475569">candidate</text>
      <text x="755" y="232" class="ivsub" fill="#475569">asset txns</text>

      <path class="ivline" d="M 470 228 L 470 285" marker-end="url(#invArrow)"/>
      <text x="485" y="262" class="ivsub" fill="#475569">line items</text>

      <!-- ====================== ROW 4 — Derived qty buckets ====================== -->
      <text x="20" y="425" class="ivlab" fill="#475569">4. Linked / unlinked qty derived on every page</text>

      <path class="ivline" d="M 470 377 L 470 415" marker-end="url(#invArrow)"/>

      <!-- Linked column -->
      <rect class="ivbox ivok" x="80" y="430" width="350" height="100" rx="6"/>
      <text x="255" y="454" text-anchor="middle" class="ivlab ivokt">Linked qty (covered)</text>
      <text x="255" y="473" text-anchor="middle" class="ivsub ivokt">sum of link rows. Shown in green on:</text>
      <text x="255" y="488" text-anchor="middle" class="ivsub ivokt">• invoice list (per line)</text>
      <text x="255" y="502" text-anchor="middle" class="ivsub ivokt">• receipts table on shipment view</text>
      <text x="255" y="516" text-anchor="middle" class="ivsub ivokt">• asset txn list · inv ledger · txn history</text>

      <!-- Unlinked column -->
      <rect class="ivbox ivopen" x="510" y="430" width="350" height="100" rx="6"/>
      <text x="685" y="454" text-anchor="middle" class="ivlab ivopent">Unlinked qty (open)</text>
      <text x="685" y="473" text-anchor="middle" class="ivsub ivopent">qty − linked, clamped ≥ 0. Shown in amber on:</text>
      <text x="685" y="488" text-anchor="middle" class="ivsub ivopent">• invoice line (balance not yet matched)</text>
      <text x="685" y="502" text-anchor="middle" class="ivsub ivopent">• receipt row (balance not yet billed)</text>
      <text x="685" y="516" text-anchor="middle" class="ivsub ivopent">• asset txn row · ledger · history</text>

      <!-- Hard-block reminder -->
      <rect class="ivbox" x="180" y="565" width="580" height="50" rx="6" stroke="#dc2626"/>
      <text x="470" y="588" text-anchor="middle" class="ivlab" fill="#7f1d1d">⚠ Hard-block: receipts / asset txns with active links can't be cancelled or deleted</text>
      <text x="470" y="604" text-anchor="middle" class="ivsub" fill="#7f1d1d">Remove the link rows on the invoice's Links page first.</text>

      <!-- Connectors -->
      <path class="ivline" d="M 470 377 L 470 400 L 255 400 L 255 430" marker-end="url(#invArrow)"/>
      <path class="ivline" d="M 470 377 L 470 400 L 685 400 L 685 430" marker-end="url(#invArrow)"/>
    </svg>
    <figcaption class="muted" style="font-size:11.5px;margin-top:6px;">
      Invoice linking flow. Solid arrows show how data builds up;
      the bottom callout is a guard the system enforces automatically.
    </figcaption>
  </figure>

  <h3>How to link (the Links page)</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the invoice</strong> from the browse list. Click <strong>🔗 Manage links</strong> (next to the Line items section) or use the <strong>🔗 Link</strong> action button on any line in the browse list.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>For each line</strong>, the Links page shows a card with: the line's code/description/qty, current linked total, current unlinked total, the existing link rows (with a Remove button per row), and an &ldquo;Add link&rdquo; form scoped to candidate txns whose item code matches this line.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a candidate txn</strong> from the dropdown. Only txns with non-zero unlinked qty appear; each option shows the date, qty received, and qty still unlinked on that txn.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Set the qty</strong> to allocate. Defaults to the line's full unlinked qty, but you can split across multiple txns by linking less and adding another row.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click Add link.</strong> The link row appears in the table; the line's Linked / Unlinked totals update; the candidate txn's own Unlinked column drops by the linked amount.</p>
      </div>
    </div>
  </div>

  <h3>Strict code matching</h3>
  <p>
    A line for code <code>SKU-A</code> can only be linked to a receipt
    of an item whose code is exactly <code>SKU-A</code>. Asset lines
    match on <code>asset_tag</code>, inv_item lines on
    <code>inv_items.code</code>. The candidate dropdown is pre-filtered
    by the line's code &mdash; you literally cannot pick a
    non-matching txn. The server-side helper enforces the same rule on
    POST as a defense-in-depth check.
  </p>

  <h3>Removing a link</h3>
  <p>
    Click the <strong>Remove</strong> button on any link row in the
    line's card. The qty becomes free for re-allocation (the line's
    Unlinked goes up; the txn's Unlinked goes up by the same amount).
    No status change, no audit-trail mutation beyond the row deletion.
  </p>

  <h3>Hard-block on edits when linked</h3>
  <p>
    Once a receipt or asset transaction is on any invoice's link table,
    the system refuses certain destructive operations until you remove
    the link first:
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:1.8;">
    <li><strong>Cancelling a shipment</strong> whose receipts are linked &mdash; refused, with the blocking invoice numbers in the flash error.</li>
    <li><strong>Deleting a shipment</strong> whose receipts are linked &mdash; refused likewise.</li>
    <li><strong>Deleting an asset</strong> whose transactions are linked &mdash; refused with the blocking invoice numbers.</li>
  </ul>
  <p>
    The user is told exactly which invoice(s) hold the blocking links,
    and the workflow is: open each named invoice, go to its Links
    page, remove the relevant rows, then come back and retry the
    destructive op.
  </p>
</section>

<!-- ============ COVERAGE ============ -->
<section class="module" id="coverage">
  <h2><span class="num">06</span> Coverage Reports</h2>

  <p>
    Two report views answer "which invoices cover which operational
    events" from opposite directions. Both filter to non-rejected
    invoices.
  </p>

  <h3>Coverage by item / receipt</h3>
  <p>
    Sidebar &rarr; Invoice &rarr; <em>Coverage &mdash; Items</em>. A
    tabbed view: the first tab lists every inventory item that has
    one or more receipts in the period, with totals for received qty
    and invoiced amount, plus a coverage badge. The second tab is
    the same broken down per receipt event &mdash; one row per
    receipt with the linked invoice (if any).
  </p>

  <h3>Coverage by transaction</h3>
  <p>
    Sidebar &rarr; Invoice &rarr; <em>Coverage &mdash; Transactions</em>.
    One row per <code>inv_txns</code> row in the period, filtered by
    pills at the top: <em>all</em>, <em>linked</em> (has a linked
    invoice), <em>open</em> (eligible for linkage but unlinked), or
    <em>n_a</em> (not eligible for linkage, e.g., issue transactions).
  </p>

  <h3>Using coverage reports</h3>
  <p>
    Month-end check: open Coverage by Transaction filtered to "open" and
    the last 30 days. Any row in that list is a receipt that should
    have an invoice but doesn't &mdash; either the vendor hasn't
    billed yet, or the invoice was recorded but not linked to the
    receipt at approval time. Investigate each.
  </p>

  <div class="callout">
    <div class="label">UNLINKED IS NOT NECESSARILY WRONG</div>
    <p>An "open" row in coverage just means it's not linked to an invoice. Some receipts legitimately never have an invoice &mdash; e.g., returns from a vendor under warranty, or stock transfers that count as receipts but aren't billed. The reports surface candidates for review; humans make the final call.</p>
  </div>
</section>

<!-- ============ ATTACHMENTS ============ -->
<section class="module" id="attachments">
  <h2><span class="num">07</span> Attachments</h2>

  <p>
    Invoices support file attachments via the same machinery as notes.
    Standard use is to attach the vendor's PDF invoice for audit
    reference. The Edit form has an upload widget; the View page lists
    every attachment with thumbnails / icons.
  </p>

  <h3>Best practice</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li><strong>Always attach the vendor PDF.</strong> The structured fields capture the key data but the PDF is the authoritative document and what an external auditor wants to see.</li>
    <li><strong>One PDF per invoice.</strong> Don't bundle multiple vendor invoices into one MagDyn invoice record. Each gets its own row.</li>
    <li><strong>Use notes for supporting docs.</strong> Quotes, POs, email approvals can attach to the invoice's notes section (entity_type = invoice in Running Notes) rather than directly on the invoice attachment list. Keeps the structured attachment slot for the canonical bill.</li>
  </ul>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">08</span> Common Workflows</h2>

  <h3>Vendor PO received and billed (multi-item invoice)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Vendor delivers stock against PO. Goods are received in the Ship &amp; Receipt module &mdash; each item gets one or more <code>inv_receipts</code> rows.</li>
    <li>Vendor mails / emails their invoice PDF, typically with multiple line items (one per item supplied).</li>
    <li>AP opens Invoice &rarr; New, records header (number, date, vendor), then adds one line per item on the vendor's PDF: pick the item code, enter qty, unit price. Attach the PDF. Save.</li>
    <li>Open the new invoice &rarr; click <strong>🔗 Manage links</strong>.</li>
    <li>For each line, pick the matching receipt(s) from the candidate dropdown (already filtered to that line's exact code). Set the link qty; add multiple links per line if the qty was split across receipts.</li>
    <li>Once the Linked totals match the line qtys (Unlinked = 0 on every line), go back to the invoice and click <strong>Approve</strong>.</li>
  </ol>

  <h3>Asset calibration billed</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Asset went out for calibration via <code>send_vendor</code>, came back via <code>receive_vendor</code> + <code>calibrate</code> transactions.</li>
    <li>Vendor sends calibration invoice.</li>
    <li>AP records the invoice with one asset line: pick the asset tag, qty 1, unit price = invoice amount.</li>
    <li>Open the Links page, link the line to the <code>receive_vendor</code> txn (or whichever asset txn the bill corresponds to).</li>
    <li>Approve the invoice.</li>
  </ol>

  <h3>Mixed-kind invoice (asset + inv_item)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>One vendor bills a single PDF that covers both a service on Asset-001 AND a batch of consumables (SKU-A).</li>
    <li>Record one invoice with two lines: line 1 = asset / Asset-001 / qty 1, line 2 = inv_item / SKU-A / qty &lt;n&gt;.</li>
    <li>On the Links page, line 1 links to the asset txn, line 2 to the SKU-A receipt(s). Same invoice, different kinds of links.</li>
  </ol>

  <h3>Partial linkage (intentional)</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Vendor invoiced for 100 units but only 80 have arrived so far (back-order on 20).</li>
    <li>Record the invoice line with qty 100 (matches the vendor bill).</li>
    <li>On the Links page, link 80 to the receipt that landed; the line shows <strong>Unlinked: 20</strong>.</li>
    <li>You can approve now (the 20 unlinked surfaces in coverage reports) OR wait until the remaining 20 arrive, then return and add a second link row.</li>
  </ol>

  <h3>Disputed invoice</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Vendor sends an invoice with a billing error.</li>
    <li>AP records it as usual (so it's tracked).</li>
    <li>Approver opens, clicks Reject, enters the reason (&ldquo;amount overstated &mdash; vendor billed for 50 units, only 45 received per PO&rdquo;).</li>
    <li>Notify vendor for correction. When the corrected invoice arrives, record it as a new invoice. The original rejected one stays in the system for audit. Any earlier link rows on the rejected invoice are auto-removed at reject time.</li>
  </ol>

  <h3>Cancelling a shipment with linked receipts</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>You try to cancel an approved shipment whose receipts have been linked to invoices.</li>
    <li>The system refuses with a flash error naming the blocking invoice numbers (e.g. &ldquo;<code>INV-2026-001, INV-2026-003</code>&rdquo;).</li>
    <li>Open each named invoice &rarr; Links page &rarr; Remove the rows that point at this shipment's receipts.</li>
    <li>Return to the shipment and retry the cancel.</li>
  </ol>

  <h3>Month-end coverage check</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open Coverage by Transaction. Filter pill: <em>open</em>. Date range: this month.</li>
    <li>For each row, decide: should this have an invoice yet? If yes &amp; the invoice has been recorded, edit the invoice to add the link. If yes &amp; no invoice exists, chase the vendor. If no (legitimately unbilled), add a note explaining why.</li>
    <li>At month-end, &ldquo;open&rdquo; rows should be either chased-vendor or annotated. Nothing silent.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">09</span> Troubleshooting</h2>

  <h3>"Duplicate invoice number" error when recording</h3>
  <p>
    An invoice with that exact number already exists in MagDyn. Search
    by number to find it. If it's the same bill (already recorded),
    don't add again. If it's actually a different vendor's invoice
    that happens to share a number, prefix yours with a
    vendor-disambiguator (<code>ACME-1234</code>).
  </p>

  <h3>I approved the wrong invoice</h3>
  <p>
    Click the "Reopen" button on the approved invoice's view page.
    Status returns to pending and the approval metadata clears. You
    can then re-approve correctly or reject.
  </p>

  <h3>The approval form shows no eligible receipts to link</h3>
  <p>
    Three possibilities: (1) the bound item code has no receipt
    events in the period &mdash; the receipts may be against a
    different item code; (2) all the receipts are already linked to
    other invoices &mdash; check the existing linkages; (3) you
    typed the bound code with a typo that doesn't match any actual
    item. Verify the bound_code matches an item or asset that has
    operational records.
  </p>

  <h3>Coverage report says my invoice is unlinked but I linked it</h3>
  <p>
    Coverage reports filter to non-rejected invoices, but they also
    only show linkages with <code>link_kind</code> matching the
    invoice's <code>bound_kind</code>. If you have an asset invoice
    that's somehow linked with <code>link_kind='inv'</code> (shouldn't
    happen via UI, but possible via direct DB edits), the report
    won't see it. Re-open and re-approve to fix.
  </p>

  <h3>Currency / amount looks wrong</h3>
  <p>
    Amounts are stored in your shop's home currency (configured in
    Admin). The module doesn't auto-convert vendor invoices in foreign
    currencies. Record at the converted home-currency amount with the
    FX details captured in the notes field.
  </p>
</section>

<div class="foot">
    <div>Invoice &middot; Operator Manual &middot; v1.0</div>
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
