<?php
/**
 * MagDyn — PO PDF generator (Phase D2.6)
 *
 * Wraps dompdf around po_render_print_html() to produce a PDF file
 * for email attachment or direct download.
 *
 * Usage:
 *   $att = po_render_pdf($poId);
 *   if (!$att) { handle missing PO; }
 *   // $att = ['path' => '/tmp/.../PO-00042.pdf', 'name' => 'PO-00042.pdf',
 *   //         'mime' => 'application/pdf']
 *   ...attach to email or stream to browser...
 *   @unlink($att['path']);  // caller cleans up after use
 *
 * The dompdf instance disables remote-enabled — we never want it
 * fetching arbitrary URLs from PO content. Default font is DejaVu Sans
 * so non-ASCII glyphs (₹, em-dash, ✓, accents) render correctly.
 */

require_once __DIR__ . '/_po_print.php';
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Render a PO to a PDF file. Returns
 *   ['path' => string, 'name' => string, 'mime' => 'application/pdf']
 * or null if the PO can't be loaded.
 *
 * $tempDir defaults to a random subdir of sys_get_temp_dir(). The
 * caller is responsible for unlinking the file (and rmdir'ing the
 * temp dir if they like) once they're done with it.
 */
function po_render_pdf($poId, $tempDir = null)
{
    $html = po_render_print_html((int)$poId, ['include_actions_bar' => false]);
    if ($html === null) return null;

    // Resolve PO ref for the filename.
    $po = db_one('SELECT po_no, version FROM purchase_orders WHERE id = ?', [(int)$poId]);
    if (!$po) return null;
    $filename = $po['po_no'] . '-v' . (int)$po['version'] . '.pdf';

    if (!$tempDir) {
        $tempDir = sys_get_temp_dir() . '/magdyn_po_pdf_' . bin2hex(random_bytes(6));
    }
    if (!is_dir($tempDir)) @mkdir($tempDir, 0700, true);

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled'    => false,
        'isHtml5ParserEnabled' => true,
        'defaultFont'        => 'DejaVu Sans',
        // Use a per-render font cache subdir inside our temp dir so we
        // never pollute the global font cache and never need write
        // access outside /tmp.
        'fontCache'          => $tempDir,
        'tempDir'            => $tempDir,
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $path = $tempDir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    file_put_contents($path, $dompdf->output());

    return [
        'path' => $path,
        'name' => $filename,
        'mime' => 'application/pdf',
    ];
}
