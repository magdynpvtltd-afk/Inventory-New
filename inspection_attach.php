<?php
/**
 * MagDyn — Inspection attachment download
 * Created: 2026-05-17 IST
 *
 * Mirrors note_attach.php but for inspection_attachments. Permission gate:
 * the user must have inspection.view (host module).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)input('id', 0);
$att = db_one(
    'SELECT a.*, i.is_deleted
       FROM inspection_attachments a
       JOIN inspections i ON i.id = a.inspection_id
      WHERE a.id = ?',
    [$id]
);
if (!$att || (int)$att['is_deleted'] === 1) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}
if (!permission_check('inspection', 'view')) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$file = __DIR__ . '/uploads/inspections/' . $att['stored_path'];
$realBase = realpath(__DIR__ . '/uploads/inspections');
$realFile = realpath($file);
if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    echo 'File missing on disk.';
    exit;
}

$filename = (string)$att['filename'];
$mime     = (string)$att['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (int)$att['size_bytes']);
$inlineMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];
$disp = in_array($mime, $inlineMimes, true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disp . '; filename="' . addslashes($filename) . '"');
header('Cache-Control: private, max-age=300');
readfile($realFile);
exit;
