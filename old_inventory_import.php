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

// ── POST — run the import ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    require_once __DIR__ . '/services/OldInventoryAssetImportService.php';

    $result     = null;
    $fatalError = null;

    try {
        $svc    = new OldInventoryAssetImportService(current_user_id());
        $result = $svc->run();
    } catch (Throwable $e) {
        $fatalError = $e->getMessage();
    }

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
                ['Models Found',    $result['model_total'],   '#f3f4f6', '#374151'],
                ['Created',         $result['model_created'], '#d1fae5', '#065f46'],
                ['Already Existed', max(0, (int)$result['model_total'] - (int)$result['model_created']), '#dbeafe', '#1e40af'],
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
                ['Total Found',        $result['total'],    '#f3f4f6', '#374151'],
                ['Imported (new)',      $result['imported'], '#d1fae5', '#065f46'],
                ['Updated (existing)', $result['updated'],  '#dbeafe', '#1e40af'],
                ['Failed',             $result['failed'],   '#fee2e2', '#991b1b'],
                ['Skipped',            $result['skipped'],  '#fef9c3', '#854d0e'],
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
                ['Txn Found',    $result['txn_total'],    '#f3f4f6', '#374151'],
                ['Txn Imported', $result['txn_imported'], '#d1fae5', '#065f46'],
                ['Txn Failed',   $result['txn_failed'],   '#fee2e2', '#991b1b'],
                ['Txn Skipped',  $result['txn_skipped'],  '#fef9c3', '#854d0e'],
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

// Current MagDyn counts (what's already imported)
$localCounts = [
    'assets' => (int) db_val('SELECT COUNT(*) FROM assets',           [], 0),
    'models' => (int) db_val('SELECT COUNT(*) FROM asset_models',     [], 0),
    'txns'   => (int) db_val('SELECT COUNT(*) FROM asset_transactions WHERE notes LIKE \'[old-txn:%\'', [], 0),
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
              onsubmit="
                this.querySelector('button[type=submit]').disabled = true;
                this.querySelector('button[type=submit]').textContent = '⏳ Importing… please wait';
              ">
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

    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
