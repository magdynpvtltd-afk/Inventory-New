<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Import · Operator Manual';
$current_page  = 'import-training.php';
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
.pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 3px; letter-spacing: 0.03em; }
.pill-link { background: #dcfce7; color: #166534; }
.pill-rev  { background: #fef3c7; color: #92400e; }
.pill-up   { background: #e0e7ff; color: #3730a3; }
</style>

<div class="layout">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark"><div style="width:32px;height:32px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;letter-spacing:-0.02em;">MD</div></div>
        <div class="brand-text">
            <div class="brand-title">Import Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#access">Access &amp; Permissions</a></li>
            <li><a href="#upload">Step 1 &middot; Upload</a></li>
            <li><a href="#mapping">Field Mapping</a></li>
            <li><a href="#codes">Codes &amp; Match Key</a></li>
            <li><a href="#preview">Step 2 &middot; Preview</a></li>
            <li><a href="#notes">Running Note &amp; XML File</a></li>
            <li><a href="#documents">Attached Documents</a></li>
            <li><a href="#ecn">Rev Change &rarr; ECN</a></li>
            <li><a href="#commit">Commit</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Bulk data loading</div>
    <h1>Load parts from <strong>part-report XML</strong> &mdash; with documents and revision control.</h1>
    <p class="lede">
        The Import module turns an external <code>part_report</code> XML
        file into inventory items, attaches the source XML as a running
        note, and reconciles each referenced document against your
        external-documents register &mdash; linking what exists, creating
        what doesn't, and routing genuine revision changes through an ECN.
        Every import is a two-step <strong>preview &rarr; commit</strong>
        so nothing reaches the database before you approve it.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    The XML inventory-items importer reads a <code>&lt;part_report&gt;</code>
    file containing one or more <code>&lt;part_record&gt;</code> entries.
    For each part it maps a fixed set of fields to inventory columns,
    files the item under a Finished Good category and a division you
    choose, records the rest of the XML data as a running note (with the
    XML file attached), and processes the part's attached documents.
  </p>

  <h3>What one import touches</h3>
  <table>
    <thead><tr><th style="width:26%">AREA</th><th>WHAT HAPPENS</th></tr></thead>
    <tbody>
      <tr><td>Inventory item</td><td class="dim">Insert a new item or update an existing one (matched on part number + part revision). The item code is system-generated, never taken from the XML.</td></tr>
      <tr><td>Running note</td><td class="dim">A note is added to the item carrying every other XML field, with the original XML file attached.</td></tr>
      <tr><td>External documents</td><td class="dim">Each <code>documents_bom</code> entry is matched by Doc No + revision: link the existing document, create a new one from an uploaded file, or draft an ECN for a revision change.</td></tr>
      <tr><td>ECN</td><td class="dim">When a referenced document's revision differs from what's on file, the importer can draft a drawing-revision ECN that carries the new file and lists the item as affected.</td></tr>
    </tbody>
  </table>

  <h3>The two-step flow</h3>
  <p>
    Every import is <strong>upload &rarr; preview &rarr; commit</strong>.
    The upload parses the file and stages it; the preview shows exactly
    what will be inserted, updated, linked, created, or drafted, with
    per-item and per-document controls; the commit applies everything in
    a single transaction. If any row fails, the whole batch rolls back.
  </p>
</section>

<!-- ============ ACCESS ============ -->
<section class="module" id="access">
  <h2><span class="num">02</span> Access &amp; Permissions</h2>

  <p>
    The Import module lives in the left sidebar (icon &#128229;). Each
    import flow is gated by its own permission, so an admin can grant
    narrow access.
  </p>

  <table>
    <thead><tr><th style="width:34%">PERMISSION</th><th>GRANTS</th></tr></thead>
    <tbody>
      <tr><td class="mono">import.view</td><td class="dim">See the Import landing page and the list of available importers.</td></tr>
      <tr><td class="mono">import.xml_inv_items</td><td class="dim">Run the XML inventory-items importer (upload, preview, commit).</td></tr>
      <tr><td class="mono">ecn.create</td><td class="dim">Not part of Import, but required to use the &ldquo;Create ECN&rdquo; option when a document revision changes. Without it, that option is hidden and the operator can only link-as-is or skip.</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">Documents need their own permission</div>
    <p>
      Creating or linking external documents touches the
      <code>documents_external</code> module. To see the documents the
      importer links or creates, the operator also needs
      <code>documents_external.view</code>; to have the importer create
      them, the effect is performed under the importer's own action, but
      reviewing/accepting them later requires the external-documents
      permissions.
    </p>
  </div>
</section>

<!-- ============ UPLOAD ============ -->
<section class="module" id="upload">
  <h2><span class="num">03</span> Step 1 &middot; Upload</h2>

  <p>
    From Import, open <em>XML &mdash; Inventory Items</em>. The upload form
    has three inputs:
  </p>

  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>XML file</strong> &mdash; the <code>part_report</code> file (max 8&nbsp;MB, UTF-8 or ASCII). Multiple <code>part_record</code> entries are supported in one file.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Division</strong> (required) &mdash; applied to every item in this import. Inventory items require a division, so it must be chosen here. Mech / Elec / Electronics / Software / Other.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p><strong>Document category</strong> &mdash; the external-documents category used when the importer <em>creates</em> a new document (defaults to Customer Specification). Existing documents keep their own category.</p>
    </div></div>
  </div>

  <p>
    Clicking <em>Parse &amp; preview</em> reads the file, stages a copy of
    the XML (so it survives to the commit step), and takes you to the
    preview. Nothing has been written to the database yet.
  </p>
</section>

<!-- ============ MAPPING ============ -->
<section class="module" id="mapping">
  <h2><span class="num">04</span> Field Mapping</h2>

  <p>
    A fixed set of XML fields map to inventory columns. Everything else
    in the part record goes into the running note rather than into
    structured columns.
  </p>

  <table>
    <thead><tr><th style="width:34%">XML FIELD</th><th>INVENTORY COLUMN</th></tr></thead>
    <tbody>
      <tr><td class="mono">obj_name</td><td class="dim"><code>part_no</code></td></tr>
      <tr><td class="mono">obj_desc</td><td class="dim"><code>long_description</code> (full text)</td></tr>
      <tr><td class="mono">obj_name + rev</td><td class="dim"><code>short_description</code> and <code>name</code>, formatted <em>&ldquo;P4000132451 Rev-A&rdquo;</em></td></tr>
      <tr><td class="mono">rev</td><td class="dim"><code>part_rev_no</code></td></tr>
      <tr><td class="mono">uom</td><td class="dim"><code>uom_id</code> (looked up in the UoM table, with aliases like EA&rarr;PCS)</td></tr>
      <tr><td class="mono">model</td><td class="dim"><code>dwg_no</code></td></tr>
      <tr><td class="mono">drawing_rev</td><td class="dim"><code>dwg_rev_no</code></td></tr>
      <tr><td class="mono">ecn</td><td class="dim"><code>ecn</code></td></tr>
      <tr><td>everything else</td><td class="dim">folded into the item's <strong>running note</strong> (see &sect;07), not into columns</td></tr>
    </tbody>
  </table>

  <h3>Fixed values on every import</h3>
  <p>
    Every imported item is filed under category <strong>Finished Good</strong>
    (<code>finshd</code>), manufacturer type <strong>Internal</strong>, and
    the division you picked on the upload form. These aren't taken from the
    XML &mdash; they're applied uniformly.
  </p>

  <div class="callout warn">
    <div class="label">UoM that can't be resolved</div>
    <p>
      If the XML's <code>uom</code> has no matching active row in the UoM
      table (even after alias matching), the item imports with
      <code>uom_id</code> left blank and a warning shows in the preview.
      Add the UoM under Admin and re-run, or set it on the item later.
    </p>
  </div>
</section>

<!-- ============ CODES ============ -->
<section class="module" id="codes">
  <h2><span class="num">05</span> Codes &amp; Match Key</h2>

  <h3>The item code is system-generated</h3>
  <p>
    The inventory code (e.g. <code>P-00042</code>) is <strong>never</strong>
    taken from the XML. It's drawn from the Code Sequences admin page
    (the <code>inv_item</code> sequence), the same source manual item
    creation uses. Change the prefix there and it applies everywhere,
    including the importer. The XML's part number goes into
    <code>part_no</code>, not the code.
  </p>

  <h3>Insert vs update is matched on part_no + part_rev_no</h3>
  <p>
    The importer decides insert-vs-update by looking up
    <code>(part_no, part_rev_no)</code> together:
  </p>
  <table>
    <thead><tr><th style="width:40%">SITUATION</th><th>RESULT</th></tr></thead>
    <tbody>
      <tr><td>part_no + rev not found</td><td class="dim"><strong>INSERT</strong> &mdash; new item with a fresh generated code.</td></tr>
      <tr><td>part_no + rev already exists</td><td class="dim"><strong>UPDATE</strong> in place &mdash; the existing code is kept; mapped columns refreshed.</td></tr>
      <tr><td>same part_no, different rev</td><td class="dim"><strong>INSERT</strong> &mdash; a new revision of a part is a distinct item with its own code.</td></tr>
      <tr><td>empty part_no</td><td class="dim"><strong>SKIP</strong> &mdash; nothing to match on.</td></tr>
      <tr><td>same part_no + rev twice in one file</td><td class="dim">First wins; later duplicates SKIP with a warning (a DB-level unique key on part_no + rev backs this).</td></tr>
    </tbody>
  </table>

  <div class="callout note">
    <div class="label">Why a new rev is a new item</div>
    <p>
      Treating each revision as its own item means stock, BOM linkage,
      and history for Rev A stay separate from Rev B. A unique constraint
      on <code>(part_no, part_rev_no)</code> enforces this at the
      database level, so duplicates can't slip in.
    </p>
  </div>
</section>

<!-- ============ PREVIEW ============ -->
<section class="module" id="preview">
  <h2><span class="num">06</span> Step 2 &middot; Preview</h2>

  <p>
    The preview is where you verify and decide before committing. At the
    top are KPI tiles (new / updates / skipped / with-warnings) and a
    confirmation line showing the category, division, and document
    category that will apply.
  </p>

  <p>
    Each part renders as a card tagged <strong>INSERT</strong>,
    <strong>UPDATE</strong>, or <strong>SKIP</strong>, showing the mapped
    field values. For updates, fields that will change are highlighted
    old&nbsp;&rarr;&nbsp;new. Each card also has a notes preview and an
    attached-documents panel (see the next sections).
  </p>

  <p>
    Nothing is committed until you click <em>Commit import</em> at the
    bottom. Cancelling or re-uploading discards the staged data.
  </p>
</section>

<!-- ============ NOTES ============ -->
<section class="module" id="notes">
  <h2><span class="num">07</span> Running Note &amp; XML File</h2>

  <p>
    Rather than cramming the long tail of XML fields into the item's notes
    column, the importer creates a <strong>running note</strong> on the
    item carrying that data, and attaches the original XML file to that
    note. The note's contents are previewable on each card.
  </p>

  <h3>What goes in the note</h3>
  <p>
    Any non-empty XML field that isn't one of the mapped columns, plus the
    <code>misc_notes</code> entries and any part warnings, formatted as a
    readable list under an &ldquo;Imported from XML for &lt;code&gt;&rdquo;
    heading. The original XML file is attached so the source is always
    retrievable from the item.
  </p>

  <h3>On re-import (update)</h3>
  <p>
    When you re-import a part that already exists, the preview asks, per
    item, what to do with the import note:
  </p>
  <table>
    <thead><tr><th style="width:30%">CHOICE</th><th>EFFECT</th></tr></thead>
    <tbody>
      <tr><td>Add a new note</td><td class="dim">Keeps the previous import note(s) &mdash; full history. A fresh note + XML attachment is added.</td></tr>
      <tr><td>Replace existing</td><td class="dim">Soft-deletes prior import note(s) and their attachments, then creates one fresh note. Manually-authored notes are never touched.</td></tr>
    </tbody>
  </table>
  <p class="dim">
    On a fresh insert there's no prompt &mdash; a note is always created
    with the XML attached.
  </p>
</section>

<!-- ============ DOCUMENTS ============ -->
<section class="module" id="documents">
  <h2><span class="num">08</span> Attached Documents</h2>

  <p>
    The XML's <code>documents_bom</code> block lists documents the part
    references (specs, procedures, drawings). The importer reconciles each
    against your <strong>external-documents</strong> register, matching on
    <strong>Doc No</strong> (the document number, e.g. <code>E55201</code>)
    and revision. Each document gets one of three classifications, shown
    on the preview card:
  </p>

  <table>
    <thead><tr><th style="width:24%">TAG</th><th>MEANING &amp; OPTIONS</th></tr></thead>
    <tbody>
      <tr>
        <td><span class="pill pill-link">LINK EXISTING</span></td>
        <td class="dim">Doc No <strong>and</strong> revision both match a document on file. The existing document is linked to the imported item. No other action.</td>
      </tr>
      <tr>
        <td><span class="pill pill-rev">REV CHANGE</span></td>
        <td class="dim">Doc No matches but the revision differs. You choose: draft an ECN (see &sect;09), link the existing document as-is, or skip.</td>
      </tr>
      <tr>
        <td><span class="pill pill-up">UPLOAD NEW</span></td>
        <td class="dim">No document with this Doc No exists. Upload the file to create a new external document (filed under the chosen document category, status <em>received</em>, rev = the XML rev) and link it &mdash; or skip.</td>
      </tr>
    </tbody>
  </table>

  <h3>Doc No is the match key</h3>
  <p>
    Documents are matched on the <code>doc_no</code> field, populated from
    the XML document's name. The revision compares against the document's
    current revision label. If you want a document to match on import,
    make sure its Doc No is set (editable on the document form).
  </p>

  <div class="callout note">
    <div class="label">Linking is non-destructive</div>
    <p>
      Linking attaches the document to the item via the document-entity
      link table. It doesn't change the document, its revision, or its
      status &mdash; it just records that this item references it.
    </p>
  </div>
</section>

<!-- ============ ECN ============ -->
<section class="module" id="ecn">
  <h2><span class="num">09</span> Rev Change &rarr; ECN</h2>

  <p>
    A document on file at one revision, referenced by the XML at a
    different revision, is a controlled change &mdash; not something the
    importer should apply silently. So when you choose <strong>Create
    ECN</strong> on a REV CHANGE document (requires <code>ecn.create</code>),
    the importer drafts a drawing-revision ECN instead of touching the
    document directly.
  </p>

  <h3>What the importer does at commit</h3>
  <div class="steps">
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p>Drafts a <strong>drawing-revision ECN</strong> against the existing document, carrying the XML's new revision label as the pending revision.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p>Stages the file you uploaded as the ECN's pending revision file.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p>Marks the imported item as an <strong>affected item</strong> on the ECN.</p>
    </div></div>
    <div class="step"><div class="step-num"></div><div class="step-body">
      <p>Links the existing document to the item now, noting that the revision change is pending the ECN.</p>
    </div></div>
  </div>

  <h3>What happens next (you drive this)</h3>
  <p>
    The new document revision is <strong>not</strong> created at import
    time. The drafted ECN sits in the ECN module. You take it through the
    normal ECN process &mdash; submit, collect sign-offs, make effective.
    When the ECN is made <em>effective</em>, the staged file becomes the
    document's new revision and the document's current revision advances.
  </p>

  <div class="callout warn">
    <div class="label">The ECN is a draft, not a fait accompli</div>
    <p>
      Importing with the ECN option doesn't change any document revision
      by itself. Until someone processes the ECN to effective, the
      document stays at its current revision. If the ECN is cancelled, no
      revision change happens. This is deliberate &mdash; revision changes
      to controlled documents go through approval.
    </p>
  </div>

  <p class="dim">
    If you pick <em>Create ECN</em> but lack <code>ecn.create</code>, or
    the ECN subsystem is unavailable, the importer falls back to linking
    the existing document as-is so the import still completes.
  </p>
</section>

<!-- ============ COMMIT ============ -->
<section class="module" id="commit">
  <h2><span class="num">10</span> Commit</h2>

  <p>
    Clicking <em>Commit import</em> applies everything in one transaction:
    items inserted/updated, running notes created (with the XML attached),
    documents linked or created, and ECNs drafted. The success message
    summarises the counts &mdash; inserted, updated, skipped, documents
    linked/created, and ECNs drafted.
  </p>

  <h3>All-or-nothing</h3>
  <p>
    If any row fails mid-commit, the entire batch rolls back &mdash; no
    partial imports. The most common failure is a uniqueness clash on
    <code>(part_no, part_rev_no)</code>, which can happen if someone
    imported the same part + revision between your preview and commit. The
    error explains this; re-upload and preview again to pick up the current
    state.
  </p>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">11</span> Common Workflows</h2>

  <h3>First import of a brand-new part</h3>
  <p class="dim">
    Upload &rarr; pick division + doc category &rarr; preview shows INSERT,
    all documents likely UPLOAD NEW &rarr; attach the document files you
    have, skip the ones you don't &rarr; commit. The item is created, the
    XML attached as a note, documents created and linked.
  </p>

  <h3>Re-importing a corrected XML for an existing part</h3>
  <p class="dim">
    Upload the same part+rev &rarr; preview shows UPDATE with field diffs
    &rarr; choose replace or add for the note &rarr; documents now mostly
    LINK EXISTING &rarr; commit. Mapped columns refresh, code stays.
  </p>

  <h3>A spec the part references has been revised</h3>
  <p class="dim">
    The document shows REV CHANGE &rarr; choose Create ECN &rarr; upload
    the new revision file &rarr; commit. A draft ECN appears in the ECN
    module with the item listed as affected. Process the ECN to effective
    to publish the new revision.
  </p>

  <h3>Loading a new revision of an existing part</h3>
  <p class="dim">
    Change the XML's <code>rev</code> and import &mdash; because the match
    is on part_no + rev, a different rev creates a <em>new</em> item with
    its own code rather than overwriting the old revision's item.
  </p>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">12</span> Troubleshooting</h2>

  <h3>&ldquo;The item code came out as P-… but I expected the part number.&rdquo;</h3>
  <p>
    That's intended. The code is system-generated; the part number lives
    in <code>part_no</code>. The code prefix is controlled by the
    <code>inv_item</code> row on the Code Sequences admin page.
  </p>

  <h3>&ldquo;A document I expected to link came up as UPLOAD NEW.&rdquo;</h3>
  <p>
    The match is on the document's <strong>Doc No</strong>. If the
    existing document has no Doc No set (or a different one), it won't
    match. Open the document and set its Doc No to the value the XML uses,
    then re-import.
  </p>

  <h3>&ldquo;REV CHANGE but I don't see the Create ECN option.&rdquo;</h3>
  <p>
    The Create-ECN option only appears for users with
    <code>ecn.create</code>. Without it you can link-as-is or skip; ask an
    admin to grant the permission if you need to raise ECNs from import.
  </p>

  <h3>&ldquo;I made the ECN but the document still shows the old revision.&rdquo;</h3>
  <p>
    Correct &mdash; the import only <em>drafts</em> the ECN. The new
    revision is published when the ECN is made effective in the ECN
    module. Until then the document stays at its current revision.
  </p>

  <h3>&ldquo;Accepting an external document changed its revision &mdash; is that expected?&rdquo;</h3>
  <p>
    No. Accepting an external document keeps whatever revision was
    entered. Revision changes are always a deliberate, manually-entered
    action (add a revision) or the result of an ECN going effective &mdash;
    never a side effect of acceptance.
  </p>

  <h3>&ldquo;Import failed and nothing was created.&rdquo;</h3>
  <p>
    The commit is all-or-nothing. A uniqueness clash on part_no + rev (a
    part added since you previewed) is the usual cause. Re-upload and
    preview again.
  </p>

  <h3>&ldquo;The same document appears under multiple items.&rdquo;</h3>
  <p>
    That's expected and fine &mdash; one external document (e.g. a shared
    spec) can be linked to many inventory items. Linking is a
    relationship, not a copy.
  </p>
</section>

<div class="foot">
    <div>Import &middot; Operator Manual &middot; v1.0</div>
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
