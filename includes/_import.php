<?php
/**
 * MagDyn — Shared CSV-import helpers
 * Created: 20260517_120000_IST
 *
 * Provides the two-step upload → preview → commit pipeline used by the
 * asset, asset_models, and inv_items list pages. Each list page wires
 * three actions:
 *   ?action=<prefix>_import          GET — show upload modal (just a button)
 *   ?action=<prefix>_import_preview  POST multipart — parse + show preview
 *   ?action=<prefix>_import_commit   POST — replay the stashed CSV + insert/update
 *
 * The CSV is stashed in $_SESSION between preview and commit so the user
 * doesn't have to re-upload. Capped at 1 MB to keep session small.
 *
 * Each entity supplies an "adapter": a callable that takes one CSV row
 * (associative array, lowercase keys) plus the upsert flag and returns
 * one of:
 *   ['status' => 'insert', 'data' => $cleanRow]
 *   ['status' => 'update', 'data' => $cleanRow, 'existing_id' => N]
 *   ['status' => 'skip',   'reason' => "human-readable why"]
 *   ['status' => 'error',  'reason' => "human-readable why"]
 *
 * Plus a "committer": a callable that takes one validated row from the
 * adapter and persists it (returns id of inserted/updated row).
 */

if (!defined('MAGDYN_IMPORT_HELPERS_LOADED')) {
define('MAGDYN_IMPORT_HELPERS_LOADED', 1);

/**
 * Maximum stashed CSV size in bytes. 1 MB is plenty for thousands of
 * master-data rows; larger files should be split.
 */
define('IMPORT_MAX_BYTES', 1024 * 1024);

/**
 * Parse an uploaded CSV from $_FILES['csv'] into an array of associative
 * rows + the original CSV text (for stashing). Returns:
 *   ['ok' => true, 'rows' => [...], 'csv_text' => "..."]
 * or
 *   ['ok' => false, 'error' => "human-readable"]
 *
 * Header keys are lowercased and trimmed. Unknown columns are preserved
 * (the adapter decides what to use).
 */
function import_parse_uploaded_csv($fieldName = 'csv')
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No CSV file uploaded or upload failed.'];
    }
    if ($_FILES[$fieldName]['size'] > IMPORT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'CSV file too large (max '
            . round(IMPORT_MAX_BYTES / 1024) . ' KB). Split into smaller files.'];
    }
    $raw = file_get_contents($_FILES[$fieldName]['tmp_name']);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'error' => 'Could not read uploaded CSV.'];
    }
    return import_parse_csv_text($raw);
}

/**
 * Parse CSV text (already in memory) into associative rows. Used by both
 * the upload step (fresh from $_FILES) and the commit step (rehydrated
 * from $_SESSION). Strips UTF-8 BOM if present.
 */
function import_parse_csv_text($raw)
{
    // Strip UTF-8 BOM
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    // Normalize line endings
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    // Use fopen with php://memory so fgetcsv handles quoting/escaping
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return ['ok' => false, 'error' => 'CSV is empty (no header row).'];
    }
    // Normalize headers: lowercase, trim, strip surrounding whitespace
    $header = array_map(function ($h) { return strtolower(trim((string)$h)); }, $header);

    $rows = [];
    $lineNo = 1;  // header was line 1
    while (($row = fgetcsv($fh)) !== false) {
        $lineNo++;
        // Skip blank lines (every cell empty)
        $hasContent = false;
        foreach ($row as $v) { if (trim((string)$v) !== '') { $hasContent = true; break; } }
        if (!$hasContent) continue;

        // Pad/trim to header length; map by column name
        $assoc = [];
        foreach ($header as $i => $h) {
            $assoc[$h] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
        $assoc['_line'] = $lineNo;
        $rows[] = $assoc;
    }
    fclose($fh);

    return ['ok' => true, 'rows' => $rows, 'csv_text' => $raw, 'header' => $header];
}

/**
 * Apply the entity's row adapter to every parsed row to produce the
 * preview. Returns:
 *   [
 *     'rows'    => [['line'=>N, 'status'=>..., 'data'=>..., 'reason'=>...], ...],
 *     'counts'  => ['insert'=>N, 'update'=>N, 'skip'=>N, 'error'=>N],
 *   ]
 */
function import_run_adapter(array $parsedRows, callable $adapter, $upsert)
{
    $counts = ['insert' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
    $out = [];
    foreach ($parsedRows as $row) {
        $line = (int)($row['_line'] ?? 0);
        $r = $adapter($row, $upsert);
        $status = $r['status'] ?? 'error';
        if (!isset($counts[$status])) $counts[$status] = 0;
        $counts[$status]++;
        $out[] = [
            'line'        => $line,
            'status'      => $status,
            'data'        => $r['data']        ?? null,
            'existing_id' => $r['existing_id'] ?? null,
            'reason'      => $r['reason']      ?? '',
            'original'    => $row,
        ];
    }
    return ['rows' => $out, 'counts' => $counts];
}

/**
 * Stash CSV text in the session under a short-lived token for the
 * commit step. Returns the token (a hex string).
 */
function import_stash(string $csvText, string $namespace = 'generic')
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $token = bin2hex(random_bytes(8));
    $key = '__import_stash_' . $namespace . '_' . $token;
    $_SESSION[$key] = [
        'csv'       => $csvText,
        'stamped'   => time(),
    ];
    // Garbage-collect any stash older than 1 hour to avoid session bloat.
    foreach ($_SESSION as $k => $v) {
        if (strpos($k, '__import_stash_') !== 0) continue;
        if (is_array($v) && isset($v['stamped']) && (time() - (int)$v['stamped']) > 3600) {
            unset($_SESSION[$k]);
        }
    }
    return $token;
}

/**
 * Retrieve a stashed CSV by token + namespace. Returns null if missing
 * or expired (>1 hour). Does NOT remove the entry — caller does that
 * via import_unstash() once the commit succeeds.
 */
function import_unstash_peek(string $token, string $namespace = 'generic')
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $key = '__import_stash_' . $namespace . '_' . $token;
    if (empty($_SESSION[$key]) || !is_array($_SESSION[$key])) return null;
    $age = time() - (int)$_SESSION[$key]['stamped'];
    if ($age > 3600) {
        unset($_SESSION[$key]);
        return null;
    }
    return (string)$_SESSION[$key]['csv'];
}

function import_unstash(string $token, string $namespace = 'generic')
{
    $csv = import_unstash_peek($token, $namespace);
    if ($csv !== null) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        unset($_SESSION['__import_stash_' . $namespace . '_' . $token]);
    }
    return $csv;
}

/**
 * Render the preview page. Caller has already required header.php and
 * is responsible for the surrounding chrome. This emits the preview
 * table + the commit form.
 *
 * $cfg keys:
 *   title         — page heading (e.g. "Import assets · preview")
 *   commit_url    — POST URL for the Commit button
 *   cancel_url    — back-to-list URL for Cancel
 *   token         — stash token to pass through on commit
 *   upsert        — bool, current upsert toggle state
 *   counts        — counts array from import_run_adapter()
 *   rows          — rows array from import_run_adapter()
 *   columns       — list of [key, label] showing which CSV columns to
 *                   surface in the preview table
 */
function import_render_preview(array $cfg)
{
    $counts = $cfg['counts'];
    $rows   = $cfg['rows'];
    $totalActionable = $counts['insert'] + $counts['update'];
    $hasErrors = $counts['error'] > 0;
    ?>
    <div class="import-preview-page">
        <div class="page-head">
            <div>
                <h1><?= h($cfg['title']) ?></h1>
                <p class="muted">
                    Review the rows below. Green rows will be inserted, blue rows will update
                    existing records, red rows have problems and will be skipped. Click Commit
                    to apply the changes.
                </p>
            </div>
        </div>

        <div class="import-summary">
            <span class="pill pill-active">✓ Insert: <?= (int)$counts['insert'] ?></span>
            <span class="pill pill-info">⟳ Update: <?= (int)$counts['update'] ?></span>
            <span class="pill pill-neutral">⊘ Skip: <?= (int)$counts['skip'] ?></span>
            <span class="pill pill-danger">✗ Error: <?= (int)$counts['error'] ?></span>
        </div>

        <div class="import-actions">
            <form method="post" action="<?= h($cfg['commit_url']) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= h($cfg['token']) ?>">
                <input type="hidden" name="upsert" value="<?= $cfg['upsert'] ? '1' : '0' ?>">
                <button type="submit" class="btn btn-primary"
                        <?= $totalActionable === 0 ? 'disabled title="Nothing to commit"' : '' ?>>
                    Commit <?= (int)$totalActionable ?> change<?= $totalActionable === 1 ? '' : 's' ?>
                </button>
            </form>
            <a class="btn btn-ghost" href="<?= h($cfg['cancel_url']) ?>">Cancel</a>
            <?php if ($hasErrors): ?>
                <span class="muted small" style="margin-left: 12px;">
                    Errors are reported per row below. Errored rows are skipped during commit.
                </span>
            <?php endif; ?>
        </div>

        <table class="data-table import-preview-table">
            <thead>
                <tr>
                    <th style="width: 38px;">Line</th>
                    <th style="width: 70px;">Status</th>
                    <?php foreach ($cfg['columns'] as $col): ?>
                        <th><?= h($col[1]) ?></th>
                    <?php endforeach; ?>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $statusClass = [
                        'insert' => 'imp-insert',
                        'update' => 'imp-update',
                        'skip'   => 'imp-skip',
                        'error'  => 'imp-error',
                    ][$r['status']] ?? '';
                    $statusLabel = [
                        'insert' => '✓ Insert',
                        'update' => '⟳ Update',
                        'skip'   => '⊘ Skip',
                        'error'  => '✗ Error',
                    ][$r['status']] ?? $r['status'];
                ?>
                    <tr class="<?= $statusClass ?>">
                        <td class="r muted small"><?= (int)$r['line'] ?></td>
                        <td><strong><?= h($statusLabel) ?></strong></td>
                        <?php foreach ($cfg['columns'] as $col):
                            $key = $col[0];
                            // Prefer the resolved data; fall back to the raw CSV cell.
                            $val = '';
                            if (is_array($r['data']) && array_key_exists($key, $r['data'])) {
                                $val = (string)$r['data'][$key];
                            } elseif (array_key_exists($key, $r['original'])) {
                                $val = (string)$r['original'][$key];
                            }
                        ?>
                            <td><?= h($val) ?></td>
                        <?php endforeach; ?>
                        <td class="muted small"><?= h($r['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Run the commit step. Iterates the rehydrated rows, applies the
 * adapter again (so the same validation that ran at preview applies
 * at commit, defending against schema changes between steps), then
 * calls $committer for each insert/update row.
 *
 * Returns flash-ready counts.
 */
function import_run_commit(string $csvText, callable $adapter, bool $upsert, callable $committer)
{
    $parsed = import_parse_csv_text($csvText);
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => $parsed['error'] ?? 'parse failed', 'inserted' => 0, 'updated' => 0, 'errors' => 0];
    }
    $result = import_run_adapter($parsed['rows'], $adapter, $upsert);
    $inserted = 0; $updated = 0; $errors = 0;
    foreach ($result['rows'] as $r) {
        if ($r['status'] === 'insert' || $r['status'] === 'update') {
            try {
                $committer($r);
                if ($r['status'] === 'insert') $inserted++;
                else                            $updated++;
            } catch (Exception $e) {
                $errors++;
                error_log('[import_commit] line ' . $r['line'] . ': ' . $e->getMessage());
            }
        }
    }
    return ['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
}

/**
 * Compact HTML for the "Import CSV" modal that sits on top of a list
 * page. Caller sets the action URL + a hidden "kind" if needed.
 *
 * Markup matches notes/template_import modal pattern so styling reuses.
 */
function import_modal_html($modalId, $title, $postUrl, $hint = '', $showUpsert = true)
{
    ?>
    <div id="<?= h($modalId) ?>" class="att-preview-modal" hidden>
        <div class="att-preview-backdrop" data-import-close></div>
        <div class="att-preview-dialog" role="dialog" aria-label="<?= h($title) ?>"
             style="max-width: 540px; margin: auto; height: auto;">
            <div class="att-preview-head">
                <span class="att-preview-name"><?= h($title) ?></span>
                <button type="button" class="btn btn-icon att-preview-close-btn" data-import-close title="Close">✕</button>
            </div>
            <form method="post" action="<?= h($postUrl) ?>"
                  enctype="multipart/form-data" style="padding: 18px;">
                <?= csrf_field() ?>
                <div class="field" data-drop-zone="csv-import">
                    <label>CSV file * <span class="muted small">(or drag onto this area)</span></label>
                    <input name="csv" type="file" accept=".csv,text/csv" required>
                </div>
                <?php if ($hint): ?>
                    <div class="muted small" style="margin-top: 10px;"><?= $hint ?></div>
                <?php endif; ?>
                <?php if ($showUpsert): ?>
                <div class="field" style="margin-top: 12px;">
                    <label class="inline">
                        <input type="checkbox" name="upsert" value="1">
                        Update existing rows on code clash
                    </label>
                    <span class="muted small">
                        Off (default): rows whose code already exists are skipped with an error.
                        On: existing rows are overwritten with the CSV values.
                    </span>
                </div>
                <?php endif; ?>
                <div style="margin-top: 16px; display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-import-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Preview</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var modal = document.getElementById(<?= json_encode($modalId) ?>);
        if (!modal) return;
        // Open via any element with data-open-import="<modalId>"
        document.querySelectorAll('[data-open-import="' + <?= json_encode($modalId) ?> + '"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                modal.hidden = false;
                document.body.classList.add('att-preview-modal-open');
            });
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-import-close]')) {
                if (modal.contains(e.target)) {
                    modal.hidden = true;
                    document.body.classList.remove('att-preview-modal-open');
                }
            }
        });
    })();
    </script>
    <?php
}

}  // MAGDYN_IMPORT_HELPERS_LOADED
