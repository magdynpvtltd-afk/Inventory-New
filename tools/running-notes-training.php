<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Running Notes · Operator Manual';
$current_page  = 'running-notes-training.php';
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
            <div class="brand-title">Running Notes Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#categories">Note Categories</a></li>
            <li><a href="#attach">Attaching Notes to Entities</a></li>
            <li><a href="#new">Adding a Note</a></li>
            <li><a href="#log">The Notes Log</a></li>
            <li><a href="#attachments">Attachments</a></li>
            <li><a href="#permissions">Permissions Model</a></li>
            <li><a href="#workflows">Common Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">Shop-floor log</div>
    <h1>The audit trail for everything that <strong>doesn't fit a form field</strong>.</h1>
    <p class="lede">
        Running Notes is the place for free-form text attached to
        anything in the system &mdash; an asset, an inventory item,
        an inspection, an invoice, a shipment. Categorised, optionally
        with file attachments, searchable in one place, permissioned
        by category so sensitive notes stay where they belong.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    A note is a chunk of free text attached to one specific entity.
    Every entity type in MagDyn (asset, inventory item, inspection,
    invoice, shipment, etc.) has a notes section that lists notes
    attached to that entity. The same notes are searchable in the
    Notes Log &mdash; a global feed across every entity.
  </p>

  <h3>What gets noted</h3>
  <p>
    Anything that's worth recording but doesn't belong in a structured
    field. Common examples: a maintenance event log on a piece of
    equipment ("Spindle bearings replaced 2026-04-10, took 4 hrs"),
    quality observations on an inspection ("Lot was visibly cleaner
    than usual"), shift-handover notes ("CNC #3 needs coolant top-up,
    Day shift please action"), vendor commentary on a PO receipt
    ("Vendor delivered early, all packing slips correct").
  </p>

  <h3>Note structure</h3>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>PURPOSE</th></tr></thead>
    <tbody>
      <tr><td>Entity type</td><td class="dim">What kind of thing the note is about (asset / inventory / inspection / invoice / shipment / etc.). Drives which entity-picker shows up.</td></tr>
      <tr><td>Entity</td><td class="dim">Which specific entity. The actual asset row, item row, inspection record, etc.</td></tr>
      <tr><td>Category</td><td class="dim">A label that groups notes by topic / sensitivity. Admin-configurable. Drives permissions &mdash; see &sect;07.</td></tr>
      <tr><td>Body</td><td class="dim">Free text. Plain-text by default; line breaks preserved.</td></tr>
      <tr><td>Attachments</td><td class="dim">Optional file uploads (photos, PDFs, certificates).</td></tr>
      <tr><td>Author / timestamp</td><td class="dim">Auto-captured. Immutable.</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ CATEGORIES ============ -->
<section class="module" id="categories">
  <h2><span class="num">02</span> Note Categories</h2>

  <p>
    Categories give notes a topic label and drive the permission model.
    Common categories: General, Maintenance, Quality, Safety,
    Customer-facing, Confidential. Each shop sets up its own list in
    Admin &rarr; Running Notes Categories.
  </p>

  <h3>Why categories matter</h3>
  <p>
    Two reasons. First, they make the Notes Log easier to scan &mdash;
    you can filter to just Maintenance notes when troubleshooting a
    machine, or just Quality notes when reviewing a customer issue.
    Second, every category is a permission gate: each running-notes
    category has a corresponding hidden module
    <code>note_cat_&lt;code&gt;</code> with view and manage permissions.
    A user only sees notes in categories where they have view
    permission.
  </p>

  <h3>Adding a category</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Admin &rarr; Running Notes Categories</strong>.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "+ New category"</strong>. Enter a code (short, lowercase, alphanumeric &mdash; <code>maintenance</code>, <code>safety</code>) and a display name.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> Behind the scenes a <code>note_cat_&lt;code&gt;</code> module is created with view + manage permissions. Grant those permissions to the right roles in Admin &rarr; Roles &amp; Permissions.</p>
      </div>
    </div>
  </div>

  <div class="callout warn">
    <div class="label">CATEGORY CODES ARE PERMANENT</div>
    <p>The code becomes part of the permission module name (<code>note_cat_maintenance</code>). Renaming a category's code orphans all its existing notes and breaks role grants. Pick the code carefully; the display name can be changed any time without consequence.</p>
  </div>
</section>

<!-- ============ ATTACH ============ -->
<section class="module" id="attach">
  <h2><span class="num">03</span> Attaching Notes to Entities</h2>

  <p>
    Notes live "on" their parent entity. Open any asset, inventory
    item, inspection, invoice, or shipment view page &mdash; the notes
    section appears at the bottom (or in a dedicated tab, depending on
    the page).
  </p>

  <h3>What you see on an entity page</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>The list of notes already on the entity, newest first, with category pills</li>
    <li>An "Add note" inline composer (if you have manage permission on at least one category)</li>
    <li>For long lists, a "Show all notes" link that opens the Notes Log filtered to this entity</li>
  </ul>

  <h3>Supported entity types</h3>
  <p>
    The module supports notes on: <em>asset</em>, <em>inventory_item</em>,
    <em>inspection</em>, <em>inspection_template</em>, <em>invoice</em>,
    <em>shipment</em>, and a handful of admin entities. New entity
    types can be added via a small migration that registers them in
    the entity_type ENUM.
  </p>
</section>

<!-- ============ NEW ============ -->
<section class="module" id="new">
  <h2><span class="num">04</span> Adding a Note</h2>

  <p>
    Two ways to add a note: inline from the entity's page (most
    common), or from the top-level "+ New note" page where you pick
    the entity in a two-stage picker.
  </p>

  <h3>Inline (from an entity page)</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Open the entity</strong> (asset view, inventory item view, etc.). Scroll to the Notes section.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Pick a category</strong> from the dropdown. Only categories you have manage permission on appear.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Type the note body.</strong> Line breaks are preserved. Reasonable length &mdash; if it's longer than a paragraph or two, consider whether it should be a document attachment instead.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Optionally attach files</strong> via the file-picker. See &sect;06.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The note appears at the top of the entity's notes list.</p>
      </div>
    </div>
  </div>

  <h3>Top-level (from "+ New note")</h3>
  <p>
    Sidebar &rarr; Running Notes &rarr; "+ New note". The form asks
    for: entity type, then an entity (with type-ahead search inside
    the chosen type), then category, body, and attachments. Useful
    when you know the note topic but haven't yet navigated to the
    entity.
  </p>

  <div class="callout note">
    <div class="label">EDITING NOTES</div>
    <p>By default notes are append-only &mdash; you can't edit an existing note's body. If you need to correct a note, add a follow-up note in the same category referencing the original. Some installs allow admins with the manage permission on the category to delete (soft-delete) and re-add &mdash; check your local procedure.</p>
  </div>
</section>

<!-- ============ LOG ============ -->
<section class="module" id="log">
  <h2><span class="num">05</span> The Notes Log</h2>

  <p>
    Sidebar &rarr; Running Notes &rarr; Notes Log is the global feed
    &mdash; every note from every entity in one searchable list. The
    log respects category permissions (you only see notes in
    categories you can view).
  </p>

  <h3>Filters</h3>
  <table>
    <thead><tr><th style="width:24%">FILTER</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Date range</td><td class="dim">From / to. Defaults to last 30 days.</td></tr>
      <tr><td>Entity type</td><td class="dim">All / asset / inventory_item / inspection / etc.</td></tr>
      <tr><td>Category</td><td class="dim">Multi-select. Only categories you can view appear.</td></tr>
      <tr><td>Author</td><td class="dim">Filter to notes from one specific user.</td></tr>
      <tr><td>Text search</td><td class="dim">Full-text search across body. Case-insensitive substring match.</td></tr>
    </tbody>
  </table>

  <h3>Reading the feed</h3>
  <p>
    Each row shows: timestamp, author, entity (linked), category
    pill, body excerpt. Click the entity link to jump to that entity's
    page. Click the row body to expand and see the full text plus
    any attachments.
  </p>
</section>

<!-- ============ ATTACHMENTS ============ -->
<section class="module" id="attachments">
  <h2><span class="num">06</span> Attachments</h2>

  <p>
    Notes can carry one or more file attachments &mdash; photos,
    PDFs, certificates, screenshots. Useful when text alone won't
    convey what happened.
  </p>

  <h3>Adding attachments</h3>
  <p>
    Click the paperclip icon (or "Attach file" button) in the note
    composer. Pick one or more files. They upload as the note is
    saved. Multiple attachments per note are supported.
  </p>

  <h3>Supported file types</h3>
  <p>
    Most common types: images (JPG, PNG, GIF, WebP), documents
    (PDF, plain text, CSV, XLSX, DOCX). Executable types are blocked
    for safety. The upper size limit is typically 10MB per file but
    is configurable in Admin.
  </p>

  <h3>Viewing attachments</h3>
  <p>
    Each attachment shows as a thumbnail (for images) or a file-type
    icon (everything else) below the note body. Click to open in a
    new tab. Images get a lightbox preview; documents download or
    open in the browser depending on the file type.
  </p>

  <div class="callout warn">
    <div class="label">PRIVACY OF ATTACHMENTS</div>
    <p>Attachments inherit the note's category permission &mdash; a user can only see (and download) attachments on notes in categories they have view permission on. The category permission is the security boundary; pick categories carefully when notes contain sensitive content like wage info, customer-confidential drawings, or personal data.</p>
  </div>
</section>

<!-- ============ PERMISSIONS ============ -->
<section class="module" id="permissions">
  <h2><span class="num">07</span> Permissions Model</h2>

  <p>
    Running notes have a two-layer permission system: a top-level
    <code>running_notes.view</code> permission that gates access to
    the module at all, and per-category permissions
    <code>note_cat_&lt;code&gt;.view</code> /
    <code>.manage</code> that gate access to specific categories.
  </p>

  <h3>How visibility computes</h3>
  <p>
    For any given note, a user can see it if and only if they have
    the <code>view</code> permission on its category's module
    (<code>note_cat_&lt;code&gt;</code>). The Notes Log filters
    automatically; per-entity note lists filter automatically;
    search results filter automatically. There's no way to see a
    note in a category you lack view on.
  </p>

  <h3>How manage works</h3>
  <p>
    The <code>manage</code> permission on a category lets a user
    create new notes IN that category. View without manage means
    read-only. Manage implies view. Hard-delete is typically
    reserved to admins via the global <code>running_notes.manage</code>
    permission.
  </p>

  <h3>Recommended setup</h3>
  <table>
    <thead><tr><th style="width:30%">CATEGORY</th><th>WHO GETS VIEW</th><th>WHO GETS MANAGE</th></tr></thead>
    <tbody>
      <tr><td>General</td><td class="dim">Everyone</td><td class="dim">Everyone</td></tr>
      <tr><td>Maintenance</td><td class="dim">Operators + Maintenance + Engineering</td><td class="dim">Maintenance + Engineering</td></tr>
      <tr><td>Quality</td><td class="dim">QC + Engineering + Operations</td><td class="dim">QC + Engineering</td></tr>
      <tr><td>Safety</td><td class="dim">Everyone</td><td class="dim">EHS + Supervisors</td></tr>
      <tr><td>Customer-confidential</td><td class="dim">Sales + Engineering + Operations leads</td><td class="dim">Sales + Engineering leads</td></tr>
    </tbody>
  </table>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">08</span> Common Workflows</h2>

  <h3>Shift handover</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>At end of shift, open the Notes Log filtered to today and category = General or Maintenance.</li>
    <li>Add notes against any equipment or job that needs the next-shift attention.</li>
    <li>Next shift opens the same filter at start &mdash; today's notes show what's outstanding.</li>
  </ol>

  <h3>Quality observation on a delivered lot</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open the incoming inspection view for the lot.</li>
    <li>Add a Quality category note describing the observation. Attach photos if relevant.</li>
    <li>The note shows on the inspection page forever and surfaces in vendor-performance reviews that filter the Notes Log by category=Quality + author or vendor.</li>
  </ol>

  <h3>Maintenance log for a piece of equipment</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open the asset view for the equipment.</li>
    <li>Each service event: add a Maintenance category note describing what was done, parts used, time taken, technician.</li>
    <li>Attach the service report PDF if available.</li>
    <li>Over time, the asset's notes section becomes the maintenance history; the global Notes Log filtered to category=Maintenance lets you scan all equipment.</li>
  </ol>

  <h3>Customer issue tracking</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open the relevant invoice or shipment view.</li>
    <li>Add a Customer-confidential category note describing the issue, the customer contact, the resolution path.</li>
    <li>As the issue progresses, add follow-up notes in the same category. The thread on the entity becomes the case history.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">09</span> Troubleshooting</h2>

  <h3>I can't see a note someone told me they added</h3>
  <p>
    Most likely the note is in a category you don't have view
    permission on. Check Admin &rarr; Roles &amp; Permissions for
    your role &mdash; the note_cat_* permissions should show which
    categories you can see. If it's a permission you should have,
    ask an admin to grant it.
  </p>

  <h3>The category dropdown in the new-note composer is empty</h3>
  <p>
    You need <code>manage</code> permission on at least one category
    to create notes. View-only roles see existing notes but can't
    add new ones. Ask your admin to grant manage on the appropriate
    category for your role.
  </p>

  <h3>An attachment failed to upload</h3>
  <p>
    Three likely causes: (1) file too large &mdash; check the limit
    in Admin (default 10MB per file); (2) file type blocked &mdash;
    executables and scripts are rejected; (3) the storage location
    on the server filled up &mdash; admin should check disk space.
    The error message will indicate which.
  </p>

  <h3>I deleted a note by accident</h3>
  <p>
    Notes are soft-deleted by default &mdash; the row stays but is
    hidden from listings. An admin with <code>running_notes.manage</code>
    can restore it. If a hard purge has already run, the data is
    gone &mdash; backups are the only recovery path.
  </p>

  <h3>The search isn't finding text I know is in a note</h3>
  <p>
    Two possibilities: (1) the note is in a category you can't view
    &mdash; the search respects permissions, so even matching notes
    you can't access don't appear; (2) the search is substring-based
    and case-insensitive &mdash; make sure the text you're searching
    for actually appears verbatim in the note body, not via
    paraphrase.
  </p>
</section>

<div class="foot">
    <div>Running Notes &middot; Operator Manual &middot; v1.0</div>
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
