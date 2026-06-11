<?php
/**
 * MagDyn — Note attachment download
 * Created: 20260516_180000_IST
 *
 * Serves an attachment by id with permission gating. We never link
 * directly to /uploads/notes/... — that would bypass permission checks.
 * This endpoint:
 *   1. Loads the attachment row + its host note (entity_type, entity_id)
 *   2. Verifies the user has view permission on the host entity
 *   3. Streams the file with the correct mime type + Content-Disposition
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)input('id', 0);
$att = db_one(
    'SELECT na.*, n.entity_type, n.entity_id, n.is_deleted, n.redacted_at
       FROM note_attachments na
       JOIN notes n ON n.id = na.note_id
      WHERE na.id = ?',
    [$id]
);
if (!$att || (int)$att['is_deleted'] === 1) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}
// Redacted notes hide their attachments from regular viewers; admins
// with running_notes.manage can still retrieve them (for audit/recovery).
if (!empty($att['redacted_at']) && !permission_check('running_notes', 'manage')) {
    http_response_code(403);
    echo 'This attachment is on a redacted note.';
    exit;
}

// Permission: view access on the host entity.
$viewPerm = [
    'asset'     => ['asset',                'view'],
    'asset_txn' => ['asset',                'view'],
    'inv_item'  => ['inventory_view_items', 'view'],
    'inv_txn'   => ['inventory_view_items', 'view'],
    // Inspection-side note attachments use the inspection module's
    // view perm — both the inspection-record case and the template
    // case (drawing PDFs bubbled in from the template editor).
    'inspection'          => ['inspection', 'view'],
    'inspection_template' => ['inspection', 'view'],
];
$pCheck = $viewPerm[$att['entity_type']] ?? null;
if (!$pCheck || !permission_check($pCheck[0], $pCheck[1])) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$file = __DIR__ . '/uploads/notes/' . $att['stored_path'];
// Defense: ensure resolved path is inside the uploads dir (no traversal).
$realBase = realpath(__DIR__ . '/uploads/notes');
$realFile = realpath($file);
if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    echo 'File missing on disk.';
    exit;
}

// Stream the file.
$filename = (string)$att['filename'];
$mime     = (string)$att['mime_type'] ?: 'application/octet-stream';
// Trust the on-disk size when the stored byte count is missing (0). Imported
// notes record their attachments before the physical files are copied in, so
// size_bytes is 0 — sending Content-Length: 0 would truncate the download.
$length   = (int)$att['size_bytes'] > 0 ? (int)$att['size_bytes'] : (int)@filesize($realFile);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
// Inline for images / PDFs so they render in-browser; everything else
// downloads. The user agent decides how to render anyway, but this hint
// helps the common cases.
$inlineMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];
$disp = in_array($mime, $inlineMimes, true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disp . '; filename="' . addslashes($filename) . '"');
header('Cache-Control: private, max-age=300');
readfile($realFile);
exit;
