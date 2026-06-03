<?php
require_once __DIR__ . "/../includes/bootstrap.php";
require_login();
$page_title    = 'Admin · Operator Manual';
$current_page  = 'admin-training.php';
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
            <div class="brand-title">Admin Manual</div>
            <div class="brand-sub">Operator manual · v1.0</div>
        </div>
    </div>
    <nav class="nav toc" aria-label="On this page">
        <div class="toc-heading">Contents</div>
        <ol>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#users">Users</a></li>
            <li><a href="#roles">Roles &amp; Permissions</a></li>
            <li><a href="#modules">Modules</a></li>
            <li><a href="#locations">Locations</a></li>
            <li><a href="#vendors">Vendors</a></li>
            <li><a href="#assetlookups">Asset Lookups</a></li>
            <li><a href="#categories">Note Categories</a></li>
            <li><a href="#uoms">Inspection UOMs</a></li>
            <li><a href="#codes">Code Sequences</a></li>
            <li><a href="#audit">Audit Log</a></li>
            <li><a href="#notifications">Notifications</a></li>
            <li><a href="#workflows">Common Setup Workflows</a></li>
            <li><a href="#faq">Troubleshooting</a></li>
        </ol>
    </nav>
</aside>

<main class="main">

<div class="hero">
    <div class="eyebrow">System configuration</div>
    <h1>Users, roles, lookups &mdash; the <strong>foundation</strong> everything else sits on.</h1>
    <p class="lede">
        The Admin module is the configuration layer for MagDyn. Users
        live here. Roles and permissions live here. So do the lookup
        tables every other module references &mdash; locations,
        vendors, asset lookups, categories, UOMs, code sequences.
        Get these right and the rest of the system works smoothly.
    </p>
</div>

<!-- ============ OVERVIEW ============ -->
<section class="module" id="overview">
  <h2><span class="num">01</span> Overview</h2>

  <p>
    Admin isn't one page &mdash; it's a group of admin pages each
    handling one configuration concern. They share a permission
    pattern: each admin sub-page has its own
    <code>&lt;module&gt;.view</code> / <code>.manage</code> permission
    so you can grant fine-grained access. The "admin" role typically
    has everything.
  </p>

  <h3>The admin pages, at a glance</h3>
  <table>
    <thead><tr><th style="width:26%">PAGE</th><th>WHAT IT CONFIGURES</th></tr></thead>
    <tbody>
      <tr><td>Users</td><td class="dim">Who can log in. Names, emails, role assignments, active/disabled.</td></tr>
      <tr><td>Roles &amp; Permissions</td><td class="dim">What each role is allowed to do. Permission grants per module.</td></tr>
      <tr><td>Modules</td><td class="dim">The sidebar nav structure. Module activation, ordering, icons.</td></tr>
      <tr><td>Locations</td><td class="dim">Physical places stock and assets live. Hierarchical.</td></tr>
      <tr><td>Vendors</td><td class="dim">External companies you buy from or send work to.</td></tr>
      <tr><td>Asset Lookups</td><td class="dim">Alias, frequency, engraved-ID, calibration-ID, checked-OK-ID dropdowns used on assets.</td></tr>
      <tr><td>Note Categories</td><td class="dim">Categories for Running Notes plus their per-category permissions.</td></tr>
      <tr><td>Inspection UOMs</td><td class="dim">Units of measure for inspection templates (mm, in, deg, kg, etc.).</td></tr>
      <tr><td>Code Sequences</td><td class="dim">Auto-generated codes (ASSET-NNNNN, INV-NNNNN, etc.) and their prefixes / counters.</td></tr>
      <tr><td>Audit Log</td><td class="dim">Read-only feed of every administrative action.</td></tr>
      <tr><td>Notifications</td><td class="dim">Per-user notification preferences and admin-configurable kinds.</td></tr>
    </tbody>
  </table>

  <h3>Who can do what</h3>
  <p>
    Each admin sub-page gates on its own module permission. A user
    with <code>users.manage</code> can edit users; without it they
    might still have <code>users.view</code> (read-only). The default
    "admin" role has manage on every admin page; lesser roles get
    cherry-picked grants based on need.
  </p>
</section>

<!-- ============ USERS ============ -->
<section class="module" id="users">
  <h2><span class="num">02</span> Users</h2>

  <p>
    Sidebar &rarr; Admin &rarr; Users. Each user has an email
    (login), name, password (set / reset by admin), and one or more
    role assignments. Disabled users can't log in but remain in the
    audit history.
  </p>

  <h3>Adding a user</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "+ New user".</strong></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Fill in name, email, initial password.</strong> Email must be unique app-wide.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Assign roles.</strong> Multi-select &mdash; one user can hold many roles. Effective permissions are the union.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> Share the initial password with the user via a secure channel; they should change it after first login.</p>
      </div>
    </div>
  </div>

  <h3>Disabling vs deleting</h3>
  <p>
    Disabled users keep their history. They can't log in but every
    record they created or modified retains its provenance. Use
    disable for departures. Delete is reserved for never-active
    accounts that were created in error &mdash; deleting an active
    user breaks foreign-key references and is blocked at DB level.
  </p>

  <div class="callout warn">
    <div class="label">PASSWORD POLICY</div>
    <p>The app doesn't enforce a password complexity policy beyond minimum length. Communicate your own standard (e.g., 12+ chars, mixed case, numerals) verbally to users at onboarding. For sensitive deployments, consider integrating SSO &mdash; ask your admin about options.</p>
  </div>
</section>

<!-- ============ ROLES ============ -->
<section class="module" id="roles">
  <h2><span class="num">03</span> Roles &amp; Permissions</h2>

  <p>
    A role is a named bundle of permissions. Users hold roles; roles
    hold permissions; permissions gate access to specific actions on
    specific modules.
  </p>

  <h3>The permission matrix</h3>
  <p>
    Sidebar &rarr; Admin &rarr; Roles &amp; Permissions opens the
    matrix. Rows are modules; columns are permissions (view, manage,
    create, delete, approve, etc.). Each cell is a per-role checkbox.
    Click a cell to grant/revoke.
  </p>

  <h3>Recommended baseline roles</h3>
  <table>
    <thead><tr><th style="width:24%">ROLE</th><th>TYPICAL PERMISSIONS</th></tr></thead>
    <tbody>
      <tr><td><strong>admin</strong></td><td class="dim">Everything. The system maintenance role.</td></tr>
      <tr><td><strong>supervisor</strong></td><td class="dim">View on most modules; manage on operational modules (asset, inventory, inspection); approve on invoice.</td></tr>
      <tr><td><strong>operator</strong></td><td class="dim">View on most modules; manage on the modules they actively work in (e.g., asset.manage for technicians).</td></tr>
      <tr><td><strong>inspector</strong></td><td class="dim">View on inspection + asset + inventory; manage on inspection.</td></tr>
      <tr><td><strong>ap_clerk</strong></td><td class="dim">View on most modules; manage on invoice; no approve.</td></tr>
      <tr><td><strong>ap_approver</strong></td><td class="dim">View + manage + approve on invoice.</td></tr>
      <tr><td><strong>read_only</strong></td><td class="dim">View on every module. No manage anywhere. Useful for auditors.</td></tr>
    </tbody>
  </table>

  <h3>Permission grants take effect immediately</h3>
  <p>
    No cache to clear &mdash; toggle a permission and the affected
    user's next page load sees the change. (Users who are mid-session
    will get the new perms on the next request, except for the
    sidebar which renders once per page navigation.)
  </p>

  <div class="callout note">
    <div class="label">PRINCIPLE OF LEAST PRIVILEGE</div>
    <p>Resist the urge to put everyone on the "admin" role just to get past permission errors. Take five minutes to grant the specific permission they actually need. The audit trail and security model only work if roles are meaningfully scoped.</p>
  </div>
</section>

<!-- ============ MODULES ============ -->
<section class="module" id="modules">
  <h2><span class="num">04</span> Modules</h2>

  <p>
    The Modules page lists every sidebar entry in the system &mdash;
    asset, inventory, inspection, all the admin sub-pages, etc. It
    lets you activate / deactivate, reorder, and rename modules in
    the sidebar.
  </p>

  <h3>What you can change</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>Activate / deactivate (toggles visibility in the sidebar)</li>
    <li>Display name (the label shown in the sidebar)</li>
    <li>Description (tooltip / docs)</li>
    <li>Sort order (position within its group)</li>
  </ul>

  <h3>What you can't (or shouldn't) change</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>The module code (e.g., <code>asset</code>, <code>invoice</code>) &mdash; permissions reference it</li>
    <li>The route (virtual_url) of built-in modules &mdash; the underlying PHP file expects it</li>
    <li>Note-category shadow modules (<code>note_cat_*</code>) &mdash; managed via the Note Categories page</li>
  </ul>
</section>

<!-- ============ LOCATIONS ============ -->
<section class="module" id="locations">
  <h2><span class="num">05</span> Locations</h2>

  <p>
    Physical places where stock or assets can live. Hierarchical:
    each location has an optional parent so you can model building
    &rarr; room &rarr; shelf. Locations are referenced by the
    Inventory and Assets modules.
  </p>

  <h3>Adding a location</h3>
  <div class="steps">
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Click "+ New location".</strong></p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Enter name and (optional) parent.</strong></p>
        <p class="sub">Top-level locations have no parent. Sub-locations pick a parent from the dropdown. The location list displays as an indented tree.</p>
      </div>
    </div>
    <div class="step">
      <div class="step-num"></div>
      <div class="step-body">
        <p><strong>Save.</strong> The location appears in inventory and asset pickers.</p>
      </div>
    </div>
  </div>

  <h3>Renaming and reorganising</h3>
  <p>
    Renaming is safe &mdash; existing records reference the location
    by id, not name. Moving a location to a new parent is allowed
    but be careful: descendant locations follow their parent
    automatically.
  </p>

  <h3>Deactivating</h3>
  <p>
    Locations can be deactivated rather than deleted. Deactivated
    locations don't appear in pickers for new transactions but
    remain in the history of existing records. Use deactivation
    when a stockroom is decommissioned.
  </p>
</section>

<!-- ============ VENDORS ============ -->
<section class="module" id="vendors">
  <h2><span class="num">06</span> Vendors</h2>

  <p>
    External companies you buy from, send work to, or receive
    services from. Vendors are referenced by Asset transactions
    (send_vendor / receive_vendor), Inventory shipments, and
    invoices.
  </p>

  <h3>Vendor fields</h3>
  <table>
    <thead><tr><th style="width:26%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Name <strong>*</strong></td><td class="dim">Display name. Required and should be unique.</td></tr>
      <tr><td>Code</td><td class="dim">Optional short code for compact display (e.g., "ACME" for ACME Manufacturing).</td></tr>
      <tr><td>Contact info</td><td class="dim">Phone, email, address. Free text, structured.</td></tr>
      <tr><td>Tags / categories</td><td class="dim">Optional labels (e.g., "Calibration vendor", "Raw material supplier") for filtering vendor lists.</td></tr>
      <tr><td>Notes</td><td class="dim">Free text. Quality observations, contract terms, preferred contact.</td></tr>
      <tr><td>Active</td><td class="dim">Toggle. Inactive vendors don't appear in pickers but remain in history.</td></tr>
    </tbody>
  </table>

  <div class="callout">
    <div class="label">VENDOR PERFORMANCE TRACKING</div>
    <p>The Ship &amp; Receipt module's lateness metrics and the Invoice module's coverage reports both group by vendor, giving you per-vendor reliability and billing summaries. Keep vendor records clean and consolidated &mdash; one row per actual company &mdash; for the metrics to be meaningful.</p>
  </div>
</section>

<!-- ============ ASSET LOOKUPS ============ -->
<section class="module" id="assetlookups">
  <h2><span class="num">07</span> Asset Lookups</h2>

  <p>
    Several fields on the asset form (Alias, Frequency, Engraved-ID,
    Calibration-ID, Checked-OK-ID) are dropdowns sourced from
    admin-configured lookup tables. The Asset Lookups page manages
    those tables.
  </p>

  <h3>The five lookup tables</h3>
  <table>
    <thead><tr><th style="width:26%">LOOKUP</th><th>WHAT IT IS</th></tr></thead>
    <tbody>
      <tr><td>Alias</td><td class="dim">Alternative or legacy names for assets. Common values: shop nickname, supplier-side part number, old asset tag from a predecessor system.</td></tr>
      <tr><td>Frequency</td><td class="dim">Standard calibration cycles. Typical values: Monthly, Quarterly, Semi-annual, Annual, Biennial, On-demand.</td></tr>
      <tr><td>Engraved-ID</td><td class="dim">Physical ID engraved on the asset (separate from the asset_tag). Useful when the engraved ID predates MagDyn.</td></tr>
      <tr><td>Calibration-ID</td><td class="dim">Internal cal-lab reference if your shop's cal lab uses its own numbering system.</td></tr>
      <tr><td>Checked-OK-ID</td><td class="dim">Inspector / supervisor sign-off identifier. Captures who last verified the asset was good.</td></tr>
    </tbody>
  </table>

  <h3>Adding values</h3>
  <p>
    Each table has the same shape: code, display name, optional
    description, active flag. Add values once; they appear in the
    asset form pickers immediately.
  </p>
</section>

<!-- ============ CATEGORIES ============ -->
<section class="module" id="categories">
  <h2><span class="num">08</span> Note Categories</h2>

  <p>
    Categories for Running Notes (see the Running Notes manual). The
    Note Categories admin page lets you add, rename, deactivate
    categories. Adding a category auto-creates a shadow module
    <code>note_cat_&lt;code&gt;</code> with view and manage
    permissions that you grant via the Roles &amp; Permissions matrix.
  </p>

  <h3>Adding a category</h3>
  <p>
    Pick a stable lowercase code (<code>maintenance</code>,
    <code>quality</code>, <code>safety</code>) and a display name.
    Save. Then go to Roles &amp; Permissions and grant view / manage
    on the new <code>note_cat_&lt;code&gt;</code> module to the
    appropriate roles.
  </p>

  <div class="callout warn">
    <div class="label">CODE STABILITY</div>
    <p>The category code is part of a permission module name. Once you've granted role access against <code>note_cat_safety.view</code>, renaming the code orphans all those grants. Pick the code carefully at creation time.</p>
  </div>
</section>

<!-- ============ UOMS ============ -->
<section class="module" id="uoms">
  <h2><span class="num">09</span> Inspection UOMs</h2>

  <p>
    Units of measure used on inspection templates (and elsewhere &mdash;
    inventory items reference the same UOM list). Admin &rarr; Inspection
    UOMs manages the list.
  </p>

  <h3>UOM fields</h3>
  <table>
    <thead><tr><th style="width:26%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Code <strong>*</strong></td><td class="dim">Short identifier (mm, in, deg, kg, EA). Used in dropdowns.</td></tr>
      <tr><td>Name</td><td class="dim">Full name (millimeter, inch, degree, kilogram, each).</td></tr>
      <tr><td>Symbol</td><td class="dim">Display symbol (mm, in, °, kg). Often same as code.</td></tr>
      <tr><td>Active</td><td class="dim">Toggle. Inactive UOMs don't appear in new pickers but remain in history.</td></tr>
    </tbody>
  </table>

  <h3>When to add a UOM</h3>
  <p>
    The shipped set covers most engineering measurements (length,
    mass, force, pressure, angle, area, volume). Add a new UOM only
    when an inspection genuinely needs a unit not in the list &mdash;
    e.g., specialty hardness scales (HRC, HB, Shore A), viscosity
    (cP, MU), surface finish (Ra in µm or µin).
  </p>
</section>

<!-- ============ CODES ============ -->
<section class="module" id="codes">
  <h2><span class="num">10</span> Code Sequences</h2>

  <p>
    Most entities in MagDyn (assets, inventory items, inspections,
    invoices, templates) get an auto-generated code on creation
    &mdash; ASSET-00042, I-00042, INSP-000042, etc. The Code Sequences
    admin page defines the format and tracks the next-available
    counter for each.
  </p>

  <h3>Sequence fields</h3>
  <table>
    <thead><tr><th style="width:24%">FIELD</th><th>NOTES</th></tr></thead>
    <tbody>
      <tr><td>Name</td><td class="dim">Internal name of the sequence (assets, inventory_items, inspections, etc.). Don't rename &mdash; code in PHP references the name.</td></tr>
      <tr><td>Prefix</td><td class="dim">String prepended (<code>ASSET-</code>, <code>I-</code>, <code>INSP-</code>). Editable.</td></tr>
      <tr><td>Next value</td><td class="dim">The next integer that will be appended. Editable to skip / rewind, but rarely needed.</td></tr>
      <tr><td>Pad width</td><td class="dim">Minimum digits in the numeric part. Pad width 5 makes "42" render as "00042". Editable.</td></tr>
    </tbody>
  </table>

  <h3>Editing safely</h3>
  <p>
    Changing the prefix or pad width affects only FUTURE codes &mdash;
    existing entities keep their original codes. Rewinding the next
    value risks collisions with existing codes; the system blocks
    saves that would collide. Skip forward (e.g., next=1000 to start
    a fresh thousand) is always safe.
  </p>
</section>

<!-- ============ AUDIT ============ -->
<section class="module" id="audit">
  <h2><span class="num">11</span> Audit Log</h2>

  <p>
    Read-only feed of every administrative action recorded by the
    system: user logins, permission grants, vendor adds, location
    deactivations, etc. Used for compliance and forensics.
  </p>

  <h3>What gets logged</h3>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>Logins and logouts (including failed attempts)</li>
    <li>User creates / edits / disables</li>
    <li>Role grants and permission changes</li>
    <li>Lookup-table additions and edits (locations, vendors, categories, UOMs, codes)</li>
    <li>Module activation / deactivation</li>
    <li>Notable destructive actions (deletes, mass updates)</li>
  </ul>

  <p>
    Operational transactions (asset moves, inventory transactions,
    inspections, invoices) are NOT in the audit log &mdash; they're
    logged in their own per-module histories. Audit covers
    configuration changes.
  </p>

  <h3>Reading the log</h3>
  <p>
    Filter by date range, actor (user), action type, or target.
    Each row shows: timestamp, actor, action, target id, and an
    optional details JSON / text blob describing what changed.
    Read-only &mdash; no row can be edited or deleted from the UI.
  </p>
</section>

<!-- ============ NOTIFICATIONS ============ -->
<section class="module" id="notifications">
  <h2><span class="num">12</span> Notifications</h2>

  <p>
    Per-user notification preferences for events that affect them &mdash;
    calibration overdue, pending invoice approvals, inspection
    failures, etc. Each user can opt in or out per kind. The admin
    page lets you see which kinds exist and (sometimes) configure
    delivery channels.
  </p>

  <h3>Configurable kinds</h3>
  <p>
    The kinds available depend on which features your install has
    enabled. Common kinds:
  </p>
  <ul style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;">
    <li>Calibration overdue (per-asset, per-user issue tracking)</li>
    <li>Invoice pending approval (for users with invoice.approve)</li>
    <li>Inspection failed (for QC roles)</li>
    <li>Shipment receipt late (vendor performance)</li>
  </ul>

  <p>
    Users manage their personal preferences via their profile page;
    admins manage the available kinds via this page.
  </p>
</section>

<!-- ============ WORKFLOWS ============ -->
<section class="module" id="workflows">
  <h2><span class="num">13</span> Common Setup Workflows</h2>

  <h3>Onboarding a new MagDyn install</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Configure locations &mdash; reflect your physical shop layout.</li>
    <li>Configure vendors &mdash; load the vendor list you currently use.</li>
    <li>Configure asset lookups &mdash; alias scheme, calibration frequencies, etc.</li>
    <li>Configure note categories with appropriate codes; grant view / manage to roles.</li>
    <li>Configure inspection UOMs if defaults don't cover your measurements.</li>
    <li>Tune code sequences (prefix and pad width per your tagging convention).</li>
    <li>Create user accounts and assign appropriate roles.</li>
    <li>Bulk-import existing data (assets, inventory items, BOMs) via per-module importers.</li>
  </ol>

  <h3>Onboarding a new employee</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Add user via Users page. Assign roles that fit their function.</li>
    <li>Communicate initial credentials securely. Ask them to change password on first login.</li>
    <li>Verify their sidebar shows the modules you expect &mdash; if a module is missing, check the role's permission on that module.</li>
    <li>Have them mark the relevant training courses complete (see the per-module manuals).</li>
  </ol>

  <h3>Annual permission review</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Open Roles &amp; Permissions. Review each role's grants.</li>
    <li>For each user, verify their assigned roles match their current job function. Adjust as needed.</li>
    <li>Disable any users who've left the company &mdash; don't delete (audit history).</li>
    <li>Document the review in Running Notes against the category "Audit" or similar.</li>
  </ol>

  <h3>Adding a new location</h3>
  <ol style="color:var(--text-muted);margin-bottom:14px;padding-left:22px;line-height:2;">
    <li>Admin &rarr; Locations &rarr; "+ New location". Pick parent if hierarchical.</li>
    <li>Save. The location appears in inventory and asset pickers immediately.</li>
    <li>If items need to be moved INTO the new location, use Inventory &rarr; Process Inventory or per-asset move transactions.</li>
  </ol>
</section>

<!-- ============ FAQ ============ -->
<section class="module" id="faq">
  <h2><span class="num">14</span> Troubleshooting</h2>

  <h3>A user can't see a module they should have access to</h3>
  <p>
    Three layers to check: (1) the user's role assignment in Admin
    &rarr; Users; (2) that role's permissions in Admin &rarr; Roles
    &amp; Permissions on the relevant module; (3) the module's own
    active flag in Admin &rarr; Modules (a deactivated module is
    hidden regardless of permissions). Walk all three before
    assuming a deeper bug.
  </p>

  <h3>Permission change didn't take effect</h3>
  <p>
    Permissions update on next page load. If the user is mid-session,
    their next navigation will reflect the change. The sidebar
    renders once per page load &mdash; they may need to navigate to
    a different page (not just refresh) to see a newly-granted
    module appear.
  </p>

  <h3>Code sequence next-value got out of sync after a manual import</h3>
  <p>
    If you imported records with codes that include higher numbers
    than the sequence's next-value, the next auto-generated code
    will collide. Open Admin &rarr; Code Sequences, set the next
    value to one higher than the highest imported code's numeric
    part. The system blocks collisions on save so you'll get an
    error instead of silent corruption.
  </p>

  <h3>Audit log seems to be missing entries</h3>
  <p>
    Audit logs configuration changes, not operational transactions.
    If you're looking for "who moved this asset" or "who issued this
    inventory", check the per-module history page (Asset Transactions,
    Inventory Transactions). Audit covers config; module histories
    cover operations.
  </p>

  <h3>I deactivated a vendor and now old transactions show "Unknown vendor"</h3>
  <p>
    They shouldn't &mdash; deactivation doesn't break referential
    integrity. Inactive vendors hide from pickers for NEW
    transactions but remain referenced in old ones. If old
    transactions show "Unknown", the vendor row was likely deleted
    (not deactivated). Restore from backup or recreate the vendor
    row with a note explaining.
  </p>

  <h3>I want to reset a user's password</h3>
  <p>
    Admin &rarr; Users &rarr; pick user &rarr; Edit &rarr; "Set new
    password". Save. Communicate the new password securely. The
    user should change it after next login. There's no self-service
    "forgot password" by default; admins are the reset path.
  </p>
</section>

<div class="foot">
    <div>Admin &middot; Operator Manual &middot; v1.0</div>
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
