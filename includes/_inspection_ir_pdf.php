<?php
/**
 * MagDyn — Inspection Report PDF generator
 *
 * Wraps DOMPDF around ir_render_print_html() to produce a PDF file
 * suitable for download. A4 landscape to match the printed IR format.
 *
 * Usage:
 *   $att = ir_render_pdf($inspectionId);
 *   if (!$att) { handle missing inspection; }
 *   header('Content-Type: application/pdf');
 *   header('Content-Disposition: attachment; filename="' . $att['name'] . '"');
 *   readfile($att['path']);
 *   @unlink($att['path']);
 */

require_once __DIR__ . '/_inspection_ir_print.php';
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Render an inspection IR to a PDF file.
 *
 * Returns ['path' => string, 'name' => string, 'mime' => 'application/pdf']
 * or null when the inspection doesn't exist.
 *
 * Caller is responsible for unlinking the file after streaming it.
 */
function ir_render_pdf($inspectionId, $tempDir = null)
{
    $inspectionId = (int)$inspectionId;
    $html = ir_render_print_html($inspectionId, ['include_actions_bar' => false]);
    if ($html === null) return null;

    // Resolve IR reference for the filename
    $ir = db_one(
        'SELECT ir_no, code, part_no, part_rev FROM inspections WHERE id = ?',
        [$inspectionId]
    );
    if (!$ir) return null;

    $nameParts = [];
    if (!empty($ir['part_no'])) {
        $nameParts[] = $ir['part_no'];
        if (!empty($ir['part_rev'])) $nameParts[] = 'Rev' . $ir['part_rev'];
    }
    $nameParts[] = $ir['ir_no'] ?: $ir['code'];
    $nameParts[] = 'IR';
    $filename = implode('-', $nameParts) . '.pdf';
    $filename = preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename);

    if (!$tempDir) {
        $tempDir = sys_get_temp_dir() . '/magdyn_ir_pdf_' . bin2hex(random_bytes(6));
    }
    if (!is_dir($tempDir)) @mkdir($tempDir, 0700, true);

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled'      => false,
        'isHtml5ParserEnabled' => true,
        'defaultFont'          => 'DejaVu Sans',
        'fontCache'            => $tempDir,
        'tempDir'              => $tempDir,
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $path = $tempDir . '/' . $filename;
    file_put_contents($path, $dompdf->output());

    return [
        'path' => $path,
        'name' => $filename,
        'mime' => 'application/pdf',
    ];
}
