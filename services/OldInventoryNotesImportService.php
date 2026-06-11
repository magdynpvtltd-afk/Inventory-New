<?php
/**
 * MagDyn — Old Inventory Running-Notes Import Service (API version)
 *
 * Fetches the legacy `inv_notes` rows (and their `notes_attachments`) from the
 * old inventory system via api_export_notes.php and imports them into the
 * MagDyn `notes` / `note_attachments` tables.
 *
 * Entity resolution (old → new):
 *   class = 'A'                 → notes.entity_type = 'asset'
 *                                 old inv_notes.id  matched against assets.asset_tag
 *   class = 'P'                 → notes.entity_type = 'inv_item'
 *                                 old inv_notes.id  matched against inv_items.code
 *   tid IS NOT NULL (any class) → notes.entity_type = 'inv_txn'
 *                                 old inv_notes.tid matched against the inv_txns
 *                                 row whose ref_doc = 'OLD-ITX-<tid>' (the
 *                                 provenance tag written by the txn importer).
 *                                 Falls back to the class entity if the txn
 *                                 isn't found.  (Asset notes rarely carry tid.)
 *
 * Attachments:
 *   Only metadata is imported — the physical files are NOT transferred. Each
 *   attachment's stored_path is set to "old_import/<tmp_name>" (tmp_name is the
 *   SHA-256 hash the old server saved the file under). Copy those files into
 *   uploads/notes/old_import/ afterwards and the download links resolve.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryNotesImportService.php';
 *   $svc    = new OldInventoryNotesImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryNotesImportService
{
    /** Records per API page / DB transaction batch */
    private const BATCH_SIZE = 500;

    /** Sub-directory (under uploads/notes/) imported attachments live in */
    private const IMPORT_SUBDIR = 'old_import';

    /** @var int  User credited as author / uploader of every imported row */
    private int $actorId;

    /** @var array<string,int>  assets.asset_tag → assets.id */
    private array $assetMap = [];

    /** @var array<string,int>  inv_items.code → inv_items.id */
    private array $itemMap = [];

    /** @var array<int,int>  old inventory_transaction_id → inv_txns.id */
    private array $txnMap = [];

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var callable|null  fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array */
    private array $counts = [
        'note_total'      => 0,
        'note_imported'   => 0,
        'note_failed'     => 0,
        'note_skipped'    => 0,
        'att_imported'    => 0,
        'as_asset'        => 0,
        'as_inv_item'     => 0,
        'as_inv_txn'      => 0,
        'tid_unmatched'   => 0,
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    public function setProgressCallback(callable $cb): void
    {
        $this->onProgress = $cb;
    }

    private function emitProgress(string $phase, int $done, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($phase, $done, $total);
        }
    }

    public function run(): array
    {
        // Make sure the destination folder for imported files exists so the
        // user has somewhere to drop the physical attachments later.
        $this->ensureImportDir();

        // Build the resolution maps once up-front (cheap vs per-row queries).
        $this->buildLookupMaps();

        $countData = old_inventory_notes_api('notes_count');
        $this->counts['note_total'] = (int) ($countData['count'] ?? 0);
        $total = $this->counts['note_total'];
        $this->log("Notes: {$total} non-redacted rows found in source.");
        $this->emitProgress('Notes', 0, $total);

        $processed = 0;
        $offset    = 0;
        while (true) {
            $data  = old_inventory_notes_api('all_notes_json', ['offset' => $offset, 'limit' => self::BATCH_SIZE]);
            $batch = $data['notes'] ?? [];
            if (empty($batch)) {
                break;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach ($batch as $row) {
                    try {
                        $this->processOneNote($row);
                    } catch (Throwable $e) {
                        $this->counts['note_failed']++;
                        $nid = isset($row['noteid']) ? (int) $row['noteid'] : 0;
                        $this->log("Failed note #{$nid}: " . $e->getMessage(), 'error');
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->log("Batch at offset {$offset} rolled back: " . $e->getMessage(), 'error');
            }

            $processed += count($batch);
            $this->emitProgress('Notes', min($processed, $total), $total);

            $offset += self::BATCH_SIZE;
            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
        }

        $this->emitProgress('Notes', $total, $total);

        $this->log(
            "Done — Imported: {$this->counts['note_imported']} " .
            "(asset: {$this->counts['as_asset']}, item: {$this->counts['as_inv_item']}, " .
            "txn: {$this->counts['as_inv_txn']}), " .
            "Attachments: {$this->counts['att_imported']}, " .
            "Skipped: {$this->counts['note_skipped']}, Failed: {$this->counts['note_failed']}."
        );

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    /** Resolve + insert a single old note (and its attachments). */
    private function processOneNote(array $row): void
    {
        $oldNoteId = (int) ($row['noteid'] ?? 0);
        $text      = trim((string) ($row['notes'] ?? ''));

        if ($text === '') {
            $this->counts['note_skipped']++;
            $this->log("Skipped note #{$oldNoteId}: empty body.", 'warn');
            return;
        }

        $class = strtoupper(trim((string) ($row['class'] ?? '')));
        $oldId = (int) ($row['id'] ?? 0);
        $tid   = (isset($row['tid']) && $row['tid'] !== null) ? (int) $row['tid'] : 0;

        // ── Entity resolution ────────────────────────────────────────────────
        $entityType = null;
        $entityId   = 0;

        // A transaction id, when present, is the most specific target.
        if ($tid > 0) {
            if (isset($this->txnMap[$tid])) {
                $entityType = 'inv_txn';
                $entityId   = $this->txnMap[$tid];
            } else {
                // Txn not imported — fall back to the class entity below and warn.
                $this->counts['tid_unmatched']++;
                $this->log("Note #{$oldNoteId}: tid {$tid} has no matching inv_txn (OLD-ITX-{$tid}); falling back to class '{$class}'.", 'warn');
            }
        }

        if ($entityType === null) {
            if ($class === 'A') {
                if (isset($this->assetMap[(string) $oldId])) {
                    $entityType = 'asset';
                    $entityId   = $this->assetMap[(string) $oldId];
                } else {
                    $this->counts['note_failed']++;
                    $this->log("Note #{$oldNoteId}: no asset with Asset ID '{$oldId}' (class A).", 'error');
                    return;
                }
            } elseif ($class === 'P') {
                if (isset($this->itemMap[(string) $oldId])) {
                    $entityType = 'inv_item';
                    $entityId   = $this->itemMap[(string) $oldId];
                } else {
                    $this->counts['note_failed']++;
                    $this->log("Note #{$oldNoteId}: no inventory item with Inventory Code '{$oldId}' (class P).", 'error');
                    return;
                }
            } else {
                $this->counts['note_failed']++;
                $this->log("Note #{$oldNoteId}: unknown class '{$class}'.", 'error');
                return;
            }
        }

        // ── Insert the note ──────────────────────────────────────────────────
        $bodyHtml  = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
        $createdAt = $this->normalizeDate((string) ($row['created_date'] ?? ''));

        db_exec(
            'INSERT INTO notes (entity_type, entity_id, note_type_id, body_html, author_id, created_at, is_deleted)
             VALUES (?, ?, NULL, ?, ?, ?, 0)',
            [$entityType, $entityId, $bodyHtml, $this->actorId, $createdAt]
        );
        $newNoteId = (int) db_val('SELECT LAST_INSERT_ID()', [], 0);

        $this->counts['note_imported']++;
        if ($entityType === 'asset')    { $this->counts['as_asset']++; }
        if ($entityType === 'inv_item') { $this->counts['as_inv_item']++; }
        if ($entityType === 'inv_txn')  { $this->counts['as_inv_txn']++; }

        // ── Insert attachments (metadata only) ───────────────────────────────
        $atts = isset($row['attachments']) && is_array($row['attachments']) ? $row['attachments'] : [];
        foreach ($atts as $a) {
            $tmp = trim((string) ($a['tmp_name'] ?? ''));
            $fn  = trim((string) ($a['filename'] ?? ''));
            if ($fn === '') { $fn = $tmp; }

            // The physical file lives (once copied) at uploads/notes/old_import/<tmp_name>.
            // tmp_name is a SHA-256 hash and already path-safe; fall back to a
            // sanitised filename if a row somehow lacks one.
            $stored = $tmp !== '' ? $tmp : preg_replace('/[^A-Za-z0-9._-]/', '_', $fn);
            if ($stored === '') { continue; }
            $storedPath = self::IMPORT_SUBDIR . '/' . $stored;

            $mime = trim((string) ($a['type'] ?? '')) ?: 'application/octet-stream';

            db_exec(
                'INSERT INTO note_attachments (note_id, filename, stored_path, mime_type, size_bytes, uploaded_by, uploaded_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)',
                [$newNoteId, substr($fn, 0, 255), substr($storedPath, 0, 500), substr($mime, 0, 120), $this->actorId, $createdAt]
            );
            $this->counts['att_imported']++;
        }
    }

    /** Build asset_tag / inv_items.code / old-txn-id lookup maps. */
    private function buildLookupMaps(): void
    {
        foreach (db_all('SELECT id, asset_tag FROM assets') as $r) {
            $this->assetMap[(string) $r['asset_tag']] = (int) $r['id'];
        }
        foreach (db_all('SELECT id, code FROM inv_items') as $r) {
            $this->itemMap[(string) $r['code']] = (int) $r['id'];
        }
        // ref_doc 'OLD-ITX-<oldInventoryTransactionId>' → inv_txns.id
        foreach (db_all("SELECT id, ref_doc FROM inv_txns WHERE ref_doc LIKE 'OLD-ITX-%'") as $r) {
            $n = (int) substr((string) $r['ref_doc'], 8); // after 'OLD-ITX-'
            if ($n > 0) {
                $this->txnMap[$n] = (int) $r['id'];
            }
        }
        $this->log(
            'Lookup maps built — assets: ' . count($this->assetMap) .
            ', items: ' . count($this->itemMap) .
            ', txns: ' . count($this->txnMap) . '.'
        );
    }

    /** Create uploads/notes/old_import/ if missing (best effort). */
    private function ensureImportDir(): void
    {
        $dir = dirname(__DIR__) . '/uploads/notes/' . self::IMPORT_SUBDIR;
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0775, true)) {
                $this->log('Created attachment folder: uploads/notes/' . self::IMPORT_SUBDIR . '/');
            } else {
                $this->log('Could not create uploads/notes/' . self::IMPORT_SUBDIR . '/ — create it manually before copying files.', 'warn');
            }
        }
    }

    /** Coerce a legacy datetime to a valid 'Y-m-d H:i:s', defaulting to now. */
    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0000-00-00 00:00:00' || strcasecmp($raw, 'null') === 0) {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }

    private function log(string $message, string $level = 'info'): void
    {
        $this->errors[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => date('H:i:s'),
        ];
    }
}
