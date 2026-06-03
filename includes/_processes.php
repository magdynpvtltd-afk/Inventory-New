<?php
/**
 * MagDyn — Process flows helpers
 *
 * Functions:
 *   process_load($id)
 *   process_load_nodes($processId)
 *   process_load_edges($processId)
 *   process_visible_to_user($userId, $forAdmin = false) → array
 *   process_user_can_view($userId, $processId) → bool
 *   process_render_mermaid($processId)        → string Mermaid source
 *   process_save_revision($processId, $kind, $summary, $actorId) → rev_no
 *   process_load_revisions($processId)
 *   process_restore_revision($processId, $revNo, $actorId)
 *   process_status_pill($status)              → ['class'=>..., 'label'=>...]
 *   process_next_node_key($processId, $prefix='n')
 *   process_slugify($title)
 *
 * Mermaid output uses `flowchart TD` (top-down). Node shape map:
 *   start, end → ([text])      stadium
 *   step       → [text]        rectangle
 *   action     → [[text]]      subroutine (thick-border rectangle)
 *   decision   → {text}        diamond
 *   reference  → [/text/]      asymmetric (links to URL)
 *
 * Edge syntax:
 *   solid  : A -->|label| B   (or A --> B when label is blank)
 *   dashed : A -.->|label| B  (or A -.-> B)
 *   thick  : A ==>|label| B   (or A ==> B)
 */
require_once __DIR__ . '/db.php';

// =============================================================
// LOADERS
// =============================================================

function process_load($processId)
{
    return db_one("SELECT * FROM processes WHERE id = ?", [(int)$processId]);
}

function process_load_nodes($processId)
{
    return db_all(
        "SELECT id, process_id, node_key, node_type, label, body, ref_url, sort_order
           FROM process_nodes
          WHERE process_id = ?
          ORDER BY sort_order ASC, id ASC",
        [(int)$processId]
    );
}

function process_load_edges($processId)
{
    return db_all(
        "SELECT e.id, e.process_id, e.from_node_id, e.to_node_id,
                e.label, e.line_style, e.sort_order,
                nf.node_key AS from_key, nf.label AS from_label,
                nt.node_key AS to_key,   nt.label AS to_label
           FROM process_edges e
      LEFT JOIN process_nodes nf ON nf.id = e.from_node_id
      LEFT JOIN process_nodes nt ON nt.id = e.to_node_id
          WHERE e.process_id = ?
          ORDER BY e.sort_order ASC, e.id ASC",
        [(int)$processId]
    );
}

/**
 * Processes visible to a user.
 *   $forAdmin = true → return ALL processes regardless of status / role,
 *                       but still subject to the slug-derived module gate
 *                       (admins of the Processes module can't bypass the
 *                       module-permission check for unrelated modules).
 *   $forAdmin = false → only published processes whose role_access matches
 *                       any of the user's roles, AND that pass the module
 *                       gate.
 */
function process_visible_to_user($userId, $forAdmin = false)
{
    if ($forAdmin) {
        $rows = db_all(
            "SELECT p.*, u.full_name AS owner_name
               FROM processes p
          LEFT JOIN users u ON u.id = p.owner_id
              ORDER BY p.status, p.title"
        );
    } else {
        $rows = db_all(
            "SELECT DISTINCT p.*, u.full_name AS owner_name
               FROM processes p
          LEFT JOIN users u ON u.id = p.owner_id
               JOIN process_role_access pra ON pra.process_id = p.id
               JOIN user_roles ur ON ur.role_id = pra.role_id
              WHERE ur.user_id = ?
                AND p.status = 'published'
              ORDER BY p.title",
            [(int)$userId]
        );
    }
    // Module gate applies in BOTH paths — even admins lose access to
    // system process flows for modules they don't have permission for.
    $out = [];
    foreach ($rows as $r) {
        if (process_passes_module_gate($r['slug'])) $out[] = $r;
    }
    return $out;
}

function process_user_can_view($userId, $processId)
{
    // Module gate runs FIRST — applies even to processes.manage.
    // Rationale: a user with admin-of-processes can still be lacking
    // permission for the module a system flow documents (e.g. they
    // administer the processes feature but don't have cmm.view). The
    // gate prevents them from reading prose about that module.
    $slugRow = db_one('SELECT slug FROM processes WHERE id = ?', [(int)$processId]);
    if (!$slugRow) return false;
    if (!process_passes_module_gate($slugRow['slug'])) return false;

    // Admins / processes-managers still bypass per-process role grants
    // (i.e. they don't need explicit process_role_access rows). They
    // also bypass the published-status check so drafts are visible.
    if (permission_check('processes', 'manage')) return true;

    $row = db_one(
        "SELECT p.status
           FROM processes p
           JOIN process_role_access pra ON pra.process_id = p.id
           JOIN user_roles ur ON ur.role_id = pra.role_id
          WHERE ur.user_id = ? AND p.id = ?
          LIMIT 1",
        [(int)$userId, (int)$processId]
    );
    return $row && $row['status'] === 'published';
}

/**
 * Slug-based module gate for system process flows.
 *
 * The auto-doc process flows (slug starts with 'sys-') describe how a
 * specific module works. Users who can't see that module shouldn't see
 * the process flow for it either — there's nothing actionable in the
 * flow without access to the underlying module.
 *
 * User-created processes (slugs without the 'sys-' prefix, or slugs not
 * in the mapping table below) are unaffected — they pass the gate.
 *
 * Returns true if the user can view processes for this slug, false if
 * they can't. The mapping is intentionally hard-coded — it changes only
 * when a new sys-* process is added by a future migration.
 *
 * Critically, this gate is NOT bypassed by processes.manage. An admin
 * of the Processes module without cmm.view must not see CMM prose.
 */
function process_passes_module_gate($slug)
{
    $slug = (string)$slug;
    static $map = [
        'sys-asset-lifecycle'   => ['asset',                'view'],
        'sys-inventory-flow'    => ['inventory_view_items', 'view'],
        'sys-inspection-flow'   => ['inspection',           'view'],
        'sys-invoice-cycle'     => ['invoice',              'view'],
        'sys-ecn-workflow'      => ['ecn',                  'view'],
        'sys-dms-lifecycle'     => ['__any_dms__',          null],
        'sys-cmm-analysis'      => ['cmm',                  'view'],
        'sys-training-cert'     => ['training',             'view'],
        'sys-running-notes'     => ['running_notes',        'view'],
        // sys-magdyn-system-map: no module gate — system overview is
        // always visible to anyone who has processes.view.
    ];
    if (!isset($map[$slug])) return true;   // user-created or system-overview → no gate
    list($mod, $act) = $map[$slug];
    if ($mod === '__any_dms__') {
        // DMS uses per-kind submodules (dms_pos, dms_invoices, etc.).
        // Any single dms_* permission grants visibility to the DMS flow.
        foreach (user_permissions() as $p) {
            if (strpos($p, 'dms_') === 0) return true;
        }
        return false;
    }
    return permission_check($mod, $act);
}

/**
 * Convenience wrapper around process_passes_module_gate() that loads
 * the slug from the DB by process id. Used by POST handlers in
 * processes.php to enforce the module gate before performing writes.
 * Returns true if the gate passes (or the process doesn't exist —
 * caller decides what to do about that).
 */
function process_passes_module_gate_for_id($processId)
{
    $row = db_one('SELECT slug FROM processes WHERE id = ?', [(int)$processId]);
    if (!$row) return true;   // unknown — let caller handle "not found"
    return process_passes_module_gate($row['slug']);
}

function process_status_pill($status)
{
    switch ($status) {
        case 'published': return ['class' => 'pill-success', 'label' => 'Published'];
        case 'archived':  return ['class' => 'pill-neutral', 'label' => 'Archived'];
        case 'draft':
        default:          return ['class' => 'pill-warning', 'label' => 'Draft'];
    }
}

// =============================================================
// MERMAID RENDERING
// =============================================================

/**
 * Build a Mermaid `flowchart TD` source string from the process's
 * node + edge graph. Pure string composition — never echoes.
 */
function process_render_mermaid($processId)
{
    $nodes = process_load_nodes($processId);
    $edges = process_load_edges($processId);
    if (empty($nodes)) {
        return "flowchart TD\n  empty[\"(no nodes yet — add some in the editor)\"]\n";
    }

    $lines = ["flowchart TD"];
    // Nodes
    foreach ($nodes as $n) {
        $key   = $n['node_key'];
        $text  = _mermaid_escape_label($n['label']);
        $shape = _mermaid_shape_for($n['node_type'], $text);
        $lines[] = "  " . $key . $shape;
    }
    // Edges
    foreach ($edges as $e) {
        if (!$e['from_key'] || !$e['to_key']) continue;
        $label = trim((string)$e['label']);
        if ($label === '') {
            $arrow = _mermaid_arrow_for($e['line_style']);
            $lines[] = "  " . $e['from_key'] . ' ' . $arrow . ' ' . $e['to_key'];
        } else {
            // Mermaid syntax differs per style — handled by _mermaid_arrow_with_label
            $lines[] = "  " . $e['from_key'] . ' ' . _mermaid_arrow_with_label($e['line_style'], $label) . ' ' . $e['to_key'];
        }
    }
    // Node styling by type (CSS classes applied via Mermaid classDef)
    $lines[] = "";
    $lines[] = "  classDef startNode fill:#dcfce7,stroke:#16a34a,stroke-width:2px;";
    $lines[] = "  classDef endNode   fill:#fee2e2,stroke:#b91c1c,stroke-width:2px;";
    $lines[] = "  classDef decision  fill:#fef3c7,stroke:#d97706,stroke-width:2px;";
    $lines[] = "  classDef action    fill:#e0e7ff,stroke:#4338ca,stroke-width:2px;";
    $lines[] = "  classDef reference fill:#cffafe,stroke:#0e7490,stroke-width:1px,stroke-dasharray: 3 3;";

    $byType = ['startNode' => [], 'endNode' => [], 'decision' => [], 'action' => [], 'reference' => []];
    foreach ($nodes as $n) {
        switch ($n['node_type']) {
            case 'start':     $byType['startNode'][] = $n['node_key']; break;
            case 'end':       $byType['endNode'][]   = $n['node_key']; break;
            case 'decision':  $byType['decision'][]  = $n['node_key']; break;
            case 'action':    $byType['action'][]    = $n['node_key']; break;
            case 'reference': $byType['reference'][] = $n['node_key']; break;
        }
    }
    foreach ($byType as $cls => $keys) {
        if (!empty($keys)) $lines[] = "  class " . implode(',', $keys) . " " . $cls . ";";
    }
    return implode("\n", $lines) . "\n";
}

function _mermaid_shape_for($type, $label)
{
    switch ($type) {
        case 'start':
        case 'end':       return '([' . _mermaid_quote($label) . '])';
        case 'action':    return '[[' . _mermaid_quote($label) . ']]';
        case 'decision':  return '{' . _mermaid_quote($label) . '}';
        case 'reference': return '[/' . _mermaid_quote($label) . '/]';
        case 'step':
        default:          return '[' . _mermaid_quote($label) . ']';
    }
}

function _mermaid_arrow_for($style)
{
    switch ($style) {
        case 'dashed': return '-.->';
        case 'thick':  return '==>';
        case 'solid':
        default:       return '-->';
    }
}

function _mermaid_arrow_with_label($style, $label)
{
    $esc = _mermaid_escape_label($label);
    // Mermaid edge labels must be quoted when they contain anything
    // beyond plain alphanumerics + space. Parens, commas, colons,
    // semicolons, and any non-ASCII letter will break the parser
    // unless the label sits inside double quotes.
    $quoted = _mermaid_quote($esc);
    switch ($style) {
        case 'dashed': return '-. ' . $quoted . ' .->';
        case 'thick':  return '== ' . $quoted . ' ==>';
        case 'solid':
        default:       return '-->|' . $quoted . '|';
    }
}

/**
 * Sanitise a string so it can appear inside a Mermaid label safely.
 * Quotes, newlines, pipes, brackets all break Mermaid parsing.
 */
function _mermaid_escape_label($s)
{
    $s = (string)$s;
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = str_replace('"', "'", $s);          // double quotes → single
    $s = str_replace(['|', '[', ']', '{', '}'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function _mermaid_quote($s)
{
    // Wrap in quotes whenever the label has ANYTHING beyond plain ASCII
    // alphanumerics + space + a tiny set of safe punctuation. Parens,
    // commas, colons, em-dashes, ≥, non-ASCII letters, and similar all
    // need quotes to survive Mermaid's tokenizer.
    if (preg_match('/^[A-Za-z0-9 _\-\.]+$/', $s)) {
        return $s;
    }
    return '"' . str_replace('"', "'", $s) . '"';
}

// =============================================================
// REVISIONS
// =============================================================

/**
 * Snapshot the current state of a process and persist it as a revision.
 * $kind: 'metadata' | 'body' | 'node_add' | 'node_edit' | 'node_delete'
 *      | 'edge_add' | 'edge_edit' | 'edge_delete' | 'publish' | 'archive'
 *      | 'restore' | 'role_change'
 * Returns the new rev_no.
 */
function process_save_revision($processId, $kind, $summary, $actorId)
{
    $process = process_load($processId);
    if (!$process) throw new RuntimeException("Process not found");
    $nodes = process_load_nodes($processId);
    $edges = process_load_edges($processId);
    $snapshot = [
        'process' => $process,
        'nodes'   => $nodes,
        'edges'   => $edges,
    ];
    $next = (int)db_val(
        "SELECT COALESCE(MAX(rev_no), 0) + 1 FROM process_revisions WHERE process_id = ?",
        [(int)$processId]
    );
    db_exec(
        "INSERT INTO process_revisions
            (process_id, rev_no, change_kind, change_summary, snapshot_json, actor_id)
         VALUES (?, ?, ?, ?, ?, ?)",
        [(int)$processId, $next, $kind, $summary,
         json_encode($snapshot, JSON_UNESCAPED_SLASHES),
         $actorId ? (int)$actorId : null]
    );
    return $next;
}

function process_load_revisions($processId)
{
    return db_all(
        "SELECT r.*, u.full_name AS actor_name
           FROM process_revisions r
      LEFT JOIN users u ON u.id = r.actor_id
          WHERE r.process_id = ?
          ORDER BY r.rev_no DESC",
        [(int)$processId]
    );
}

function process_load_revision($processId, $revNo)
{
    return db_one(
        "SELECT r.*, u.full_name AS actor_name
           FROM process_revisions r
      LEFT JOIN users u ON u.id = r.actor_id
          WHERE r.process_id = ? AND r.rev_no = ?",
        [(int)$processId, (int)$revNo]
    );
}

/**
 * Restore the process state from a given revision number. Replays the
 * snapshot back into processes / process_nodes / process_edges. Also
 * writes a new revision row recording the restore.
 */
function process_restore_revision($processId, $revNo, $actorId)
{
    $rev = process_load_revision($processId, $revNo);
    if (!$rev) throw new RuntimeException("Revision $revNo not found");
    $snap = json_decode($rev['snapshot_json'], true);
    if (!is_array($snap) || !isset($snap['process'])) {
        throw new RuntimeException("Revision snapshot is corrupt");
    }
    db_exec('START TRANSACTION');
    try {
        $p = $snap['process'];
        db_exec(
            "UPDATE processes SET
                 title = ?, slug = ?, description = ?, mode = ?, body_html = ?,
                 status = ?, tags = ?, owner_id = ?, updated_by = ?
             WHERE id = ?",
            [
                $p['title'], $p['slug'], $p['description'], $p['mode'], $p['body_html'],
                $p['status'], $p['tags'],
                $p['owner_id'] ? (int)$p['owner_id'] : null,
                $actorId ? (int)$actorId : null,
                (int)$processId,
            ]
        );
        // Wipe + re-insert nodes / edges (FKs cascade)
        db_exec("DELETE FROM process_edges WHERE process_id = ?", [(int)$processId]);
        db_exec("DELETE FROM process_nodes WHERE process_id = ?", [(int)$processId]);
        // Re-insert nodes — preserve original IDs so edges link up
        foreach ($snap['nodes'] as $n) {
            db_exec(
                "INSERT INTO process_nodes
                    (id, process_id, node_key, node_type, label, body, ref_url, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int)$n['id'], (int)$processId, $n['node_key'], $n['node_type'],
                    $n['label'], $n['body'], $n['ref_url'], (int)$n['sort_order'],
                ]
            );
        }
        foreach ($snap['edges'] as $e) {
            db_exec(
                "INSERT INTO process_edges
                    (id, process_id, from_node_id, to_node_id, label, line_style, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    (int)$e['id'], (int)$processId,
                    (int)$e['from_node_id'], (int)$e['to_node_id'],
                    $e['label'], $e['line_style'], (int)$e['sort_order'],
                ]
            );
        }
        process_save_revision($processId, 'restore', "Restored from revision $revNo", $actorId);
        db_exec('COMMIT');
    } catch (Exception $e) {
        db_exec('ROLLBACK');
        throw $e;
    }
}

// =============================================================
// MISC
// =============================================================

/**
 * Generate the next available auto node_key like "n1", "n2", ...
 * Skips any already in use.
 */
function process_next_node_key($processId, $prefix = 'n')
{
    $existing = db_all(
        "SELECT node_key FROM process_nodes WHERE process_id = ? AND node_key LIKE ?",
        [(int)$processId, $prefix . '%']
    );
    $taken = [];
    foreach ($existing as $r) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $r['node_key'], $m)) {
            $taken[(int)$m[1]] = true;
        }
    }
    for ($i = 1; $i < 10000; $i++) {
        if (!isset($taken[$i])) return $prefix . $i;
    }
    throw new RuntimeException("Could not generate a fresh node_key");
}

function process_slugify($title)
{
    $s = strtolower((string)$title);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 64);
}

/**
 * Validate a node_key — must start with a letter and contain only
 * alphanumerics + underscore. Mermaid is picky about node IDs.
 */
function process_validate_node_key($key)
{
    $key = (string)$key;
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $key)) {
        throw new RuntimeException("Node key must start with a letter and contain only letters, digits, or underscores (max 32 chars).");
    }
    return $key;
}
