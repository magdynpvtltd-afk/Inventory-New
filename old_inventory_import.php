<?php
/**
 * MagDyn — Old Inventory Import
 *
 * Dedicated page for migrating data from the legacy inventory_live system.
 * Handles both the confirmation screen (GET) and the actual import run (POST).
 *
 * Permissions: asset.create
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/old_inventory_api.php';
require_login();
require_permission('asset', 'create');

$page_title  = 'Import from Old Inventory';
$page_module = 'asset';

/**
 * Render a generic "import results" page (stat cards + log) and the page chrome.
 *
 * @param string      $title      Toolbar title
 * @param array       $sections   [ 'Heading' => [ [label,val,bg,color], ... ], ... ]
 * @param array       $errors     Log entries from the service ([level,message,time])
 * @param string|null $fatalError Fatal error message, or null
 * @param string      $viewHref   "View …" primary button target
 * @param string      $infoNote   Optional HTML note shown above the cards
 */
function render_old_inventory_result(string $title, array $sections, array $errors, ?string $fatalError, string $viewHref, string $infoNote = ''): void
{
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="form-page">
        <?= form_toolbar([
            'title'      => $title,
            'back_href'  => url('/old_inventory_import.php'),
            'back_label' => 'Back to Import',
        ]) ?>
        <div class="form-page-body" style="max-width:820px;">
        <?php if ($fatalError): ?>
            <div class="alert alert-error">
                <strong>Import failed with a fatal error:</strong><br>
                <code><?= h($fatalError) ?></code>
            </div>
        <?php else: ?>
            <?php if ($infoNote !== ''): ?>
            <div class="alert alert-info" style="margin-bottom:20px;"><?= $infoNote ?></div>
            <?php endif; ?>
            <?php foreach ($sections as $heading => $cards): ?>
            <h3 style="margin:0 0 8px;"><?= h((string) $heading) ?></h3>
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
                <?php foreach ($cards as [$label, $val, $bg, $color]): ?>
                <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                            padding:14px 24px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                    <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                    <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($errors)): ?>
            <h3 style="margin-bottom:8px;">Import Log</h3>
            <div style="background:#f9fafb;border:1px solid var(--border);border-radius:6px;
                        max-height:360px;overflow-y:auto;padding:12px;
                        font-size:12px;font-family:monospace;line-height:1.6;">
                <?php foreach ($errors as $entry): ?>
                <?php $c = $entry['level'] === 'error' ? '#991b1b' : ($entry['level'] === 'warn' ? '#854d0e' : '#374151'); ?>
                <div style="color:<?= $c ?>;margin-bottom:2px;">
                    [<?= h($entry['time']) ?>]
                    [<?= strtoupper(h($entry['level'])) ?>]
                    <?= h(is_array($entry['message']) ? json_encode($entry['message']) : $entry['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

            <div style="margin-top:24px;display:flex;gap:10px;">
                <a class="btn btn-primary" href="<?= h($viewHref) ?>">View Records</a>
                <a class="btn btn-ghost"   href="<?= h(url('/old_inventory_import.php')) ?>">Back to Import</a>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
}

/**
 * Render the asset-import result page (Models / Assets / Transactions cards
 * + log). Shared by the classic synchronous POST and the streaming path's
 * redirect GET (?result=assets).
 */
function render_asset_import_result(array $result, ?string $fatalError): void
{
    require __DIR__ . '/includes/header.php';
    ?>
<div class="form-page">
    <?= form_toolbar([
        'title'      => 'Import Old Inventory — Results',
        'back_href'  => url('/old_inventory_import.php'),
        'back_label' => 'Back to Import',
    ]) ?>

    <div class="form-page-body" style="max-width:820px;">

    <?php if ($fatalError): ?>
        <div class="alert alert-error">
            <strong>Import failed with a fatal error:</strong><br>
            <code><?= h($fatalError) ?></code>
        </div>
    <?php else: ?>

        <!-- Models summary -->
        <h3 style="margin:0 0 8px;">Models</h3>
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
            <?php foreach ([
                ['Models Found',    $result['model_total'] ?? 0,   '#f3f4f6', '#374151'],
                ['Created',         $result['model_created'] ?? 0, '#d1fae5', '#065f46'],
                ['Already Existed', max(0, (int)($result['model_total'] ?? 0) - (int)($result['model_created'] ?? 0)), '#dbeafe', '#1e40af'],
            ] as [$label, $val, $bg, $color]): ?>
            <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                        padding:14px 24px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Assets summary -->
        <h3 style="margin:0 0 8px;">Assets</h3>
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px;">
            <?php foreach ([
                ['Total Found',        $result['total'] ?? 0,    '#f3f4f6', '#374151'],
                ['Imported (new)',      $result['imported'] ?? 0, '#d1fae5', '#065f46'],
                ['Updated (existing)', $result['updated'] ?? 0,  '#dbeafe', '#1e40af'],
                ['Parent Linked',      $result['parent_linked'] ?? 0, '#ede9fe', '#5b21b6'],
                ['Failed',             $result['failed'] ?? 0,   '#fee2e2', '#991b1b'],
                ['Skipped',            $result['skipped'] ?? 0,  '#fef9c3', '#854d0e'],
            ] as [$label, $val, $bg, $color]): ?>
            <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                        padding:14px 24px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Transactions summary -->
        <h3 style="margin:0 0 8px;">Transaction History</h3>
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:28px;">
            <?php foreach ([
                ['Txn Found',    $result['txn_total'] ?? 0,    '#f3f4f6', '#374151'],
                ['Txn Imported', $result['txn_imported'] ?? 0, '#d1fae5', '#065f46'],
                ['Txn Failed',   $result['txn_failed'] ?? 0,   '#fee2e2', '#991b1b'],
                ['Txn Skipped',  $result['txn_skipped'] ?? 0,  '#fef9c3', '#854d0e'],
            ] as [$label, $val, $bg, $color]): ?>
            <div style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:8px;
                        padding:14px 24px;min-width:130px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <div style="font-size:30px;font-weight:700;line-height:1.1;"><?= number_format((int)$val) ?></div>
                <div style="font-size:12px;margin-top:4px;"><?= h($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($result['errors'])): ?>
        <h3 style="margin-bottom:8px;">Import Log</h3>
        <div style="background:#f9fafb;border:1px solid var(--border);border-radius:6px;
                    max-height:360px;overflow-y:auto;padding:12px;
                    font-size:12px;font-family:monospace;line-height:1.6;">
            <?php foreach ($result['errors'] as $entry): ?>
            <?php $c = $entry['level'] === 'error' ? '#991b1b' : ($entry['level'] === 'warn' ? '#854d0e' : '#374151'); ?>
            <div style="color:<?= $c ?>;margin-bottom:2px;">
                [<?= h($entry['time']) ?>]
                [<?= strtoupper(h($entry['level'])) ?>]
                <?= h(is_array($entry['message']) ? json_encode($entry['message']) : $entry['message']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>

        <div style="margin-top:24px;display:flex;gap:10px;">
            <a class="btn btn-primary" href="<?= h(url('/asset.php?action=list')) ?>">View Assets</a>
            <a class="btn btn-ghost"   href="<?= h(url('/old_inventory_import.php')) ?>">Run Again</a>
        </div>
    </div>
</div>
<?php
    require __DIR__ . '/includes/footer.php';
}

/** Render the vendor-import result page. */
function render_vendor_import_result(array $result, ?string $fatalError): void
{
    render_old_inventory_result(
        'Import Vendors — Results',
        [
            'Vendors' => [
                ['Companies Found', $result['vendor_total']    ?? 0, '#f3f4f6', '#374151'],
                ['Imported (new)',  $result['vendor_imported'] ?? 0, '#d1fae5', '#065f46'],
                ['Updated',         $result['vendor_updated']  ?? 0, '#dbeafe', '#1e40af'],
                ['Failed',          $result['vendor_failed']   ?? 0, '#fee2e2', '#991b1b'],
                ['Skipped',         $result['vendor_skipped']  ?? 0, '#fef9c3', '#854d0e'],
            ],
            'Contacts & Addresses' => [
                ['Contacts Imported',  $result['contact_imported'] ?? 0, '#d1fae5', '#065f46'],
                ['Addresses Imported', $result['address_imported'] ?? 0, '#d1fae5', '#065f46'],
            ],
        ],
        $result['errors'] ?? [],
        $fatalError,
        url('/vendors.php')
    );
}

/** Render the user-import result page. */
function render_user_import_result(array $result, ?string $fatalError): void
{
    render_old_inventory_result(
        'Import Users — Results',
        [
            'Users' => [
                ['Accounts Found',     $result['user_total']    ?? 0, '#f3f4f6', '#374151'],
                ['Imported (new)',     $result['user_imported'] ?? 0, '#d1fae5', '#065f46'],
                ['Skipped (existing)', $result['user_skipped']  ?? 0, '#fef9c3', '#854d0e'],
                ['Failed',             $result['user_failed']   ?? 0, '#fee2e2', '#991b1b'],
            ],
        ],
        $result['errors'] ?? [],
        $fatalError,
        url('/users.php'),
        'New users were created with the default password <code>admin123</code> and the '
        . '<strong>Viewer</strong> role. Existing MagDyn accounts were left untouched '
        . '(no profile or password change).'
    );
}

/**
 * Run an import while streaming newline-delimited JSON progress events to the
 * browser, then stash the final result in the session and tell the browser
 * which result page to GET. Used by the stream=1 (progress-bar) form path.
 *
 * @param callable $runner fn(callable $emitProgress): array — returns [$result, $fatalError]
 */
function stream_import(string $resultKey, string $redirectUrl, callable $runner): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    // Disable Apache mod_deflate gzip for this response so events flush live
    // (gzip would otherwise buffer the whole NDJSON stream).
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Encoding: none');  // belt-and-braces: no compression layer
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');   // disable proxy (nginx) buffering
    @set_time_limit(0);

    $send = function (array $msg) {
        echo json_encode($msg) . "\n";
        @ob_flush();
        @flush();
    };

    $emitProgress = function (string $phase, int $done, int $total) use ($send) {
        $pct = $total > 0 ? (int) floor($done * 100 / $total) : 100;
        $send(['type' => 'progress', 'phase' => $phase, 'done' => $done, 'total' => $total, 'percent' => $pct]);
    };

    $send(['type' => 'start']);

    [$result, $fatalError] = $runner($emitProgress);

    // Persist the result for the redirect GET, then release the session lock
    // BEFORE telling the browser to navigate so the result page (which needs
    // the same session) isn't blocked or racing the write.
    $_SESSION[$resultKey] = ['result' => $result, 'fatal' => $fatalError];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $send(['type' => 'done', 'redirect' => $redirectUrl, 'fatal' => $fatalError]);
}

// ── GET: render a stashed import result (streaming path redirect target) ──────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array((string) input('result'), ['assets', 'vendors', 'users'], true)) {
    $type   = (string) input('result');
    $keyMap = ['assets' => 'imp_result_assets', 'vendors' => 'imp_result_vendors', 'users' => 'imp_result_users'];
    $key    = $keyMap[$type];
    $stash  = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);

    if (!$stash) {
        // Nothing stashed (e.g. page refreshed) — go back to the import page.
        redirect(url('/old_inventory_import.php'));
    }

    $result = $stash['result'] ?? [];
    $fatal  = $stash['fatal']  ?? null;

    if ($type === 'assets')      { render_asset_import_result($result, $fatal); }
    elseif ($type === 'vendors') { render_vendor_import_result($result, $fatal); }
    else                         { render_user_import_result($result, $fatal); }
    exit;
}

// ── POST: delete all asset records ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'delete_all') {
    csrf_check();
    require_permission('asset', 'delete');

    try {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // Order: dependent tables first, then core tables
        $pdo->exec('DELETE FROM invoice_lines     WHERE asset_txn_id IS NOT NULL');
        $pdo->exec('TRUNCATE TABLE asset_transactions');
        $pdo->exec('DELETE FROM notes             WHERE entity_type = \'asset\'');
        $pdo->exec('DELETE FROM vendor_assets     WHERE asset_id    IS NOT NULL');
        $pdo->exec('DELETE FROM inspection_results WHERE instrument_asset_id IS NOT NULL');
        $pdo->exec('TRUNCATE TABLE assets');
        $pdo->exec('TRUNCATE TABLE asset_models');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        flash_set('success', 'All asset records (models, assets, transactions, notes) have been deleted.');
    } catch (Throwable $e) {
        try { db()->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_) {}
        flash_set('error', 'Delete failed: ' . $e->getMessage());
    }

    redirect(url('/old_inventory_import.php'));
}

// ── POST: import vendors / contacts / addresses ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_vendors') {
    csrf_check();

    require_once __DIR__ . '/services/OldInventoryVendorImportService.php';

    $runner = function (callable $emit) {
        $fatalError = null;
        $result     = [];
        try {
            $svc = new OldInventoryVendorImportService(current_user_id());
            $svc->setProgressCallback($emit);
            $result = $svc->run();
        } catch (Throwable $e) {
            $fatalError = $e->getMessage();
        }
        return [$result, $fatalError];
    };

    if ((string) input('stream') === '1') {
        stream_import('imp_result_vendors', url('/old_inventory_import.php?result=vendors'), $runner);
        exit;
    }

    [$result, $fatalError] = $runner(function () {});
    render_vendor_import_result($result, $fatalError);
    exit;
}

// ── POST: import users ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'import_users') {
    csrf_check();
    require_permission('users', 'create');

    require_once __DIR__ . '/services/OldInventoryUserImportService.php';

    $runner = function (callable $emit) {
        $fatalError = null;
        $result     = [];
        try {
            $svc = new OldInventoryUserImportService(current_user_id());
            $svc->setProgressCallback($emit);
            $result = $svc->run();
        } catch (Throwable $e) {
            $fatalError = $e->getMessage();
        }
        return [$result, $fatalError];
    };

    if ((string) input('stream') === '1') {
        stream_import('imp_result_users', url('/old_inventory_import.php?result=users'), $runner);
        exit;
    }

    [$result, $fatalError] = $runner(function () {});
    render_user_import_result($result, $fatalError);
    exit;
}

// ── POST: delete ALL vendors ─────────────────────────────────────────────────
// Removes every vendor together with its contacts and addresses. Nullable
// references (assets, transactions, …) are cleared so nothing dangles; the
// vendor-only link tables are emptied. Real documents (invoices, POs) keep
// their rows — only their now-orphaned vendor pointer is left behind, exactly
// like the asset reset above.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'delete_vendors') {
    csrf_check();
    require_permission('vendors', 'delete');

    // Run a statement, ignoring "table/column doesn't exist" so a slimmer
    // install doesn't abort the whole reset.
    $tryExec = function (string $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* ignore */ }
    };

    $count = (int) db_val('SELECT COUNT(*) FROM vendors', [], 0);

    try {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // 1) Clear nullable references so they don't point at deleted vendors
        $tryExec('UPDATE assets             SET current_vendor_id = NULL WHERE current_vendor_id IS NOT NULL');
        $tryExec('UPDATE asset_transactions SET to_vendor_id      = NULL WHERE to_vendor_id   IS NOT NULL');
        $tryExec('UPDATE asset_transactions SET from_vendor_id    = NULL WHERE from_vendor_id IS NOT NULL');
        $tryExec('UPDATE doc_transmittals   SET vendor_id         = NULL WHERE vendor_id      IS NOT NULL');
        $tryExec('UPDATE inv_items          SET primary_vendor_id = NULL WHERE primary_vendor_id IS NOT NULL');
        $tryExec('UPDATE inv_shipments      SET vendor_id         = NULL WHERE vendor_id      IS NOT NULL');
        $tryExec('UPDATE vendor_applications SET approved_vendor_id = NULL WHERE approved_vendor_id IS NOT NULL');
        $tryExec('UPDATE vendor_applications SET existing_vendor_id = NULL WHERE existing_vendor_id IS NOT NULL');

        // 2) Empty the vendor-only link / child tables
        $tryExec('DELETE FROM vendor_assets');
        $tryExec('DELETE FROM inv_item_vendors');
        $tryExec('DELETE FROM vendor_contacts');
        $tryExec('DELETE FROM vendor_addresses');

        // 3) Delete every vendor
        $pdo->exec('DELETE FROM vendors');

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        flash_set('success', "All vendors deleted ({$count}), including their contacts and addresses.");
    } catch (Throwable $e) {
        try { db()->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $_) {}
        flash_set('error', 'Vendor delete failed: ' . $e->getMessage());
    }

    redirect(url('/old_inventory_import.php'));
}

// ── POST: delete imported users ──────────────────────────────────────────────
// Deletes users one at a time, but NEVER an Administrator, never the current
// user, and skips anyone still referenced elsewhere (created_by, actor, …).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)input('action') === 'delete_users') {
    csrf_check();
    require_permission('users', 'delete');

    $candidates = db_all(
        "SELECT u.id
           FROM users u
          WHERE u.id <> ?
            AND NOT EXISTS (
                SELECT 1 FROM user_roles ur
                  JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = u.id AND r.code = 'admin'
            )
          ORDER BY u.id",
        [current_user_id()]
    );

    $deleted = 0;
    $kept    = 0;
    foreach ($candidates as $r) {
        try {
            db_exec('DELETE FROM users WHERE id = ?', [(int) $r['id']]);
            $deleted++;
        } catch (Throwable $e) {
            // Referenced elsewhere (created_by / actor / etc.) — keep it.
            $kept++;
        }
    }

    flash_set('success', "Users deleted: {$deleted}. Kept (Administrators or still referenced): {$kept}.");
    redirect(url('/old_inventory_import.php'));
}

// ── POST — run the import ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    require_once __DIR__ . '/services/OldInventoryAssetImportService.php';

    $runner = function (callable $emit) {
        $fatalError = null;
        $result     = [];
        try {
            $svc = new OldInventoryAssetImportService(current_user_id());
            $svc->setProgressCallback($emit);
            $result = $svc->run();
        } catch (Throwable $e) {
            $fatalError = $e->getMessage();
        }
        return [$result, $fatalError];
    };

    if ((string) input('stream') === '1') {
        stream_import('imp_result_assets', url('/old_inventory_import.php?result=assets'), $runner);
        exit;
    }

    [$result, $fatalError] = $runner(function () {});
    render_asset_import_result($result, $fatalError);
    exit;
}

// ── GET — confirmation / status page ─────────────────────────────────────────
$oldDbError  = null;
$apiCounts   = [];

try {
    $apiCounts['assets'] = (int) (old_inventory_api('count')['count']       ?? 0);
    $apiCounts['models'] = (int) (old_inventory_api('model_count')['count'] ?? 0);
    $apiCounts['txns']   = (int) (old_inventory_api('txn_count')['count']   ?? 0);
} catch (Throwable $e) {
    $oldDbError = $e->getMessage();
}

// Vendor / user source counts — fetched from the dedicated vendor API.
// Independent of the asset API, so a separate try/catch keeps one failure
// from hiding the other section.
$vendorApiError = null;
$vendorCounts   = [];
try {
    $vendorCounts['vendors'] = (int) (old_inventory_vendor_api('vendor_count')['count'] ?? 0);
    $vendorCounts['users']   = (int) (old_inventory_vendor_api('user_count')['count']   ?? 0);
} catch (Throwable $e) {
    $vendorApiError = $e->getMessage();
}

// Current MagDyn counts (what's already imported)
$localCounts = [
    'assets'  => (int) db_val('SELECT COUNT(*) FROM assets',           [], 0),
    'models'  => (int) db_val('SELECT COUNT(*) FROM asset_models',     [], 0),
    'txns'    => (int) db_val('SELECT COUNT(*) FROM asset_transactions WHERE notes LIKE \'[old-txn:%\'', [], 0),
    'vendors' => (int) db_val('SELECT COUNT(*) FROM vendors',          [], 0),
    'users'   => (int) db_val('SELECT COUNT(*) FROM users',            [], 0),
];

require __DIR__ . '/includes/header.php';
?>
<div class="form-page">
    <?= form_toolbar([
        'title'     => 'Import from Old Inventory',
        'subtitle'  => 'Migrate asset records from <code>inventory_live</code> (192.168.1.249) into this system.',
        'back_href'  => url('/asset.php?action=list'),
        'back_label' => 'Back to Assets',
    ]) ?>

    <div class="form-page-body" style="max-width:720px;">

        <!-- Live progress panel (revealed by JS while an import streams) -->
        <div id="import-progress" style="display:none;background:#f8fafc;border:1px solid var(--border);
             border-radius:10px;padding:18px 20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;">
                <h3 id="ip-title" style="margin:0;">Importing…</h3>
                <span id="ip-pct" style="font-weight:700;font-variant-numeric:tabular-nums;">0%</span>
            </div>
            <div id="ip-phase" class="muted small" style="margin:6px 0 10px;">Starting…</div>
            <div style="background:#e5e7eb;border-radius:999px;height:16px;overflow:hidden;">
                <div id="ip-bar" style="background:#2563eb;height:100%;width:0%;border-radius:999px;
                     transition:width .25s ease;"></div>
            </div>
            <div class="muted small" style="margin-top:8px;">Please keep this page open until the import finishes.</div>
        </div>

        <!-- Connection status -->
        <?php if ($oldDbError): ?>
        <div class="alert alert-error" style="margin-bottom:20px;">
            <strong>Cannot reach the old inventory API.</strong><br>
            <code style="font-size:12px;"><?= h($oldDbError) ?></code><br><br>
            Make sure <code>api_export_assets.php</code> is deployed on the old server at
            <strong>192.168.1.249/inventory/</strong>, and the token in
            <code>config/old_inventory_api.php</code> matches <code>API_TOKEN</code> in that file.
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="margin-bottom:20px;">
            ✅ Old inventory API reachable — ready to import.
        </div>
        <?php endif; ?>

        <!-- Source vs current counts -->
        <?php if (!$oldDbError): ?>
        <h3 style="margin:0 0 10px;">Source Data (Old Inventory)</h3>
        <table class="info-table" style="margin-bottom:24px;width:100%;">
            <thead>
                <tr>
                    <th style="width:40%;">Type</th>
                    <th style="text-align:right;">Old Inventory</th>
                    <th style="text-align:right;">Already in MagDyn</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Models</td>
                    <td style="text-align:right;font-weight:600;"><?= number_format($apiCounts['models']) ?></td>
                    <td style="text-align:right;"><?= number_format($localCounts['models']) ?></td>
                </tr>
                <tr>
                    <td>Assets</td>
                    <td style="text-align:right;font-weight:600;"><?= number_format($apiCounts['assets']) ?></td>
                    <td style="text-align:right;"><?= number_format($localCounts['assets']) ?></td>
                </tr>
                <tr>
                    <td>Transactions</td>
                    <td style="text-align:right;font-weight:600;"><?= number_format($apiCounts['txns']) ?></td>
                    <td style="text-align:right;"><?= number_format($localCounts['txns']) ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Behaviour notes -->
        <h3 style="margin:0 0 10px;">What this import does</h3>
        <table class="info-table" style="margin-bottom:24px;width:100%;">
            <tr><th style="width:40%;">Models</th><td>All models imported first (Phase 0). Duplicate codes are merged.</td></tr>
            <tr><th>Assets</th><td>Matched by <code>asset_id</code> → update if exists, insert if new.</td></tr>
            <tr><th>Locations</th><td>Matched by name. Unmatched assets default to the Magdyn location.</td></tr>
            <tr><th>Transaction history</th><td>Old transactions tagged <code>[old-txn:N]</code> are deleted and re-imported fresh each run.</td></tr>
            <tr><th>Manufacturer &amp; Model No.</th><td>Pulled from <code>manufacturer.short_description</code> and <code>asset_model.asset_model_code</code>.</td></tr>
            <tr><th>Files</th><td>File names recorded in notes — physical files are <em>not</em> transferred.</td></tr>
            <tr><th>Batch size</th><td>100 records per DB transaction.</td></tr>
        </table>

        <!-- Delete all records -->
        <h3 style="margin:0 0 10px;">Reset</h3>
        <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
            <p style="margin:0 0 12px;font-size:14px;color:#7f1d1d;">
                <strong>Delete all asset records</strong> — removes every model, asset, transaction and
                asset note from this system. Use this to start a clean re-import.
            </p>
            <?php
            $delCounts = [
                'Models'       => (int) db_val('SELECT COUNT(*) FROM asset_models',        [], 0),
                'Assets'       => (int) db_val('SELECT COUNT(*) FROM assets',              [], 0),
                'Transactions' => (int) db_val('SELECT COUNT(*) FROM asset_transactions',  [], 0),
                'Notes'        => (int) db_val("SELECT COUNT(*) FROM notes WHERE entity_type='asset'", [], 0),
            ];
            ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                <?php foreach ($delCounts as $label => $cnt): ?>
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:22px;font-weight:700;color:#991b1b;"><?= number_format($cnt) ?></div>
                    <div style="font-size:11px;color:#7f1d1d;"><?= h($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?= h(url('/old_inventory_import.php')) ?>"
                  onsubmit="return confirm('This will permanently delete ALL models, assets, transactions and asset notes.\n\nAre you sure?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_all">
                <button type="submit" class="btn btn-danger">🗑 Delete All Asset Records</button>
            </form>
        </div>

        <?php if (!$oldDbError): ?>
        <!-- Start import -->
        <h3 style="margin:0 0 10px;">Run Import</h3>
        <form method="post" action="<?= h(url('/old_inventory_import.php')) ?>"
              class="js-stream-import" data-title="Importing assets">
            <?= csrf_field() ?>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit" class="btn btn-primary">
                    ▶ Start Import
                </button>
                <a class="btn btn-ghost" href="<?= h(url('/asset.php?action=list')) ?>">Cancel</a>
                <span class="muted small">This may take up to a minute.</span>
            </div>
        </form>
        <?php endif; ?>

        <!-- Vendors & Users — separate imports via api_export_vendors.php -->
        <h3 style="margin:28px 0 10px;">Vendors &amp; Users</h3>

        <?php if ($vendorApiError): ?>
        <div class="alert alert-error" style="margin-bottom:20px;">
            <strong>Cannot reach the vendor/user API.</strong><br>
            <code style="font-size:12px;"><?= h($vendorApiError) ?></code><br><br>
            Make sure <code>api_export_vendors.php</code> is deployed on the old server at
            <strong>192.168.1.249/inventory/</strong>, and that <code>vendors_url</code> in
            <code>config/old_inventory_api.php</code> is correct.
        </div>
        <?php else: ?>

        <table class="info-table" style="margin-bottom:16px;width:100%;">
            <thead>
                <tr>
                    <th style="width:40%;">Type</th>
                    <th style="text-align:right;">Old Inventory</th>
                    <th style="text-align:right;">Already in MagDyn</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Vendors (companies)</td>
                    <td style="text-align:right;font-weight:600;"><?= number_format($vendorCounts['vendors']) ?></td>
                    <td style="text-align:right;"><?= number_format($localCounts['vendors']) ?></td>
                </tr>
                <tr>
                    <td>Users</td>
                    <td style="text-align:right;font-weight:600;"><?= number_format($vendorCounts['users']) ?></td>
                    <td style="text-align:right;"><?= number_format($localCounts['users']) ?></td>
                </tr>
            </tbody>
        </table>

        <p class="muted small" style="margin:0 0 12px;">
            <strong>Vendors</strong> import companies with their contacts and addresses
            (<code>company</code> + <code>contact</code> + <code>address</code> and their custom fields).
            Matched by vendor code / name → updated if it exists, inserted if new; child contacts and
            addresses are refreshed each run.<br>
            <strong>Users</strong> import every <code>user_account</code>. New users get the default
            password <code>admin123</code> and the <strong>Viewer</strong> role; existing MagDyn
            accounts are left untouched (no profile or password change).
        </p>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <form method="post" action="<?= h(url('/old_inventory_import.php')) ?>" style="display:inline;"
                  class="js-stream-import" data-title="Importing vendors">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_vendors">
                <button type="submit" class="btn btn-primary">🏢 Import Vendors</button>
            </form>

            <form method="post" action="<?= h(url('/old_inventory_import.php')) ?>" style="display:inline;"
                  class="js-stream-import" data-title="Importing users"
                  data-confirm="Import all users from the old system?&#10;&#10;New users will be created with the default password 'admin123'.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_users">
                <button type="submit" class="btn btn-primary">👤 Import Users</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Reset vendors / users (local DB; available even if the API is down) -->
        <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin-top:20px;">
            <p style="margin:0 0 12px;font-size:14px;color:#7f1d1d;">
                <strong>Delete imported vendors / users.</strong>
                <strong>Delete All Vendors</strong> removes <em>every</em> vendor with its contacts and
                addresses (references on assets, transactions, etc. are cleared first).
                <strong>Delete All Users</strong> keeps <strong>Administrator</strong> accounts, your own
                account, and any user still referenced elsewhere.
            </p>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <form method="post" action="<?= h(url('/old_inventory_import.php')) ?>" style="display:inline;"
                      onsubmit="return confirm('Delete ALL vendors, including their contacts and addresses?\n\nThis cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_vendors">
                    <button type="submit" class="btn btn-danger">🗑 Delete All Vendors</button>
                </form>
                <form method="post" action="<?= h(url('/old_inventory_import.php')) ?>" style="display:inline;"
                      onsubmit="return confirm('Delete all users except Administrators (and those referenced elsewhere)?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_users">
                    <button type="submit" class="btn btn-danger">🗑 Delete All Users</button>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
// Progressive enhancement: stream import progress into the panel above.
// Forms with .js-stream-import POST with stream=1 and render a live bar from
// the NDJSON the server emits. Without JS they submit normally and fall back
// to the classic "run then show results" page.
(function () {
    var panel = document.getElementById('import-progress');
    if (!panel || !window.fetch || !window.ReadableStream) return;

    var bar   = document.getElementById('ip-bar');
    var pct   = document.getElementById('ip-pct');
    var phase = document.getElementById('ip-phase');
    var title = document.getElementById('ip-title');

    function show(t) {
        title.textContent = t;
        phase.textContent = 'Starting…';
        bar.style.width = '0%';
        bar.style.background = '#2563eb';
        pct.textContent = '0%';
        panel.style.display = 'block';
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function num(n) { try { return Number(n).toLocaleString(); } catch (e) { return n; } }
    function setProgress(ph, done, total, percent) {
        phase.textContent = ph + ' — ' + num(done) + ' / ' + num(total);
        bar.style.width = percent + '%';
        pct.textContent = percent + '%';
    }
    function fail(msg) {
        bar.style.background = '#dc2626';
        phase.textContent = 'Import failed: ' + msg;
    }

    function handleLine(line) {
        var msg;
        try { msg = JSON.parse(line); } catch (e) { return; }
        if (msg.type === 'progress') {
            setProgress(msg.phase, msg.done, msg.total, msg.percent);
        } else if (msg.type === 'done') {
            if (msg.fatal) { fail(msg.fatal); return; }
            phase.textContent = 'Finishing…';
            bar.style.width = '100%';
            pct.textContent = '100%';
            window.location = msg.redirect;
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('form.js-stream-import'), function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();

            var confirmMsg = form.getAttribute('data-confirm');
            if (confirmMsg && !window.confirm(confirmMsg)) return;

            Array.prototype.forEach.call(form.querySelectorAll('button[type=submit]'), function (b) {
                b.disabled = true;
            });
            show(form.getAttribute('data-title') || 'Importing…');

            var fd = new FormData(form);
            fd.set('stream', '1');

            // Use getAttribute('action'), NOT form.action — these forms have a
            // hidden <input name="action">, which shadows the form's .action
            // property and would otherwise stringify to "[object HTMLInputElement]".
            var actionUrl = form.getAttribute('action');

            fetch(actionUrl, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            }).then(function (resp) {
                if (!resp.ok || !resp.body) { throw new Error('HTTP ' + resp.status); }
                var reader = resp.body.getReader();
                var dec = new TextDecoder();
                var buf = '';
                function pump() {
                    return reader.read().then(function (r) {
                        if (r.done) {
                            if (buf.trim() !== '') handleLine(buf.trim());
                            return;
                        }
                        buf += dec.decode(r.value, { stream: true });
                        var idx;
                        while ((idx = buf.indexOf('\n')) >= 0) {
                            var line = buf.slice(0, idx);
                            buf = buf.slice(idx + 1);
                            if (line.trim() !== '') handleLine(line);
                        }
                        return pump();
                    });
                }
                return pump();
            }).catch(function (err) {
                fail(String(err && err.message ? err.message : err));
                Array.prototype.forEach.call(form.querySelectorAll('button[type=submit]'), function (b) {
                    b.disabled = false;
                });
            });
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
