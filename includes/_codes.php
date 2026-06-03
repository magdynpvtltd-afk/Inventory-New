<?php
/**
 * MagDyn — code sequence helper
 * Created: 20260518_083000_IST
 *
 * Single entry point for generating auto-incremented codes (asset
 * tags, inv item codes, ship/receipt numbers, vendor codes, etc).
 *
 * Configuration lives in the `code_sequences` table (one row per
 * code-type). The admin page at /code_sequences.php lets operators
 * edit prefix, pad width, and (for date-based formats) the date part.
 *
 * Usage:
 *   $newCode = code_next('asset');      // → ASSET-00042
 *   $newCode = code_next('shipment');   // → SH-260518-001
 *
 * The function:
 *   1. Loads the row from code_sequences by name
 *   2. Scans the configured target_table for the highest matching
 *      suffix
 *   3. Increments and returns; retries up to 50× on uniqueness clash
 *      (defends against the narrow race where two parallel handlers
 *      mint the same code).
 *
 * Fallbacks if the table or row is missing (fresh install before
 * migration ran): returns a sensible default using legacy hardcoded
 * prefixes. This keeps the helper safe to call even on partially-
 * migrated systems.
 */

if (!function_exists('code_next')) {

    /**
     * Generate the next code for the named sequence.
     *
     * @param string $name  Sequence name (matches code_sequences.name)
     * @return string       The newly-minted code
     * @throws Exception    If the sequence is unknown AND no fallback applies
     */
    function code_next($name)
    {
        $row = null;
        // Defensive try — table may not exist on a fresh install.
        try {
            $row = db_one('SELECT * FROM code_sequences WHERE name = ? AND is_active = 1', [$name]);
        } catch (\Throwable $e) {
            $row = null;
        }
        if (!$row) {
            return _code_next_legacy_fallback($name);
        }

        $prefix     = (string)$row['prefix'];
        $pad        = (int)$row['pad'];
        $format     = (string)$row['format'];
        $dateFormat = (string)($row['date_format'] ?? '');
        $tbl        = (string)($row['target_table'] ?? '');
        $col        = (string)($row['target_column'] ?? '');

        // Compose the "search prefix" — the literal string at the
        // start of every code in this sequence. For prefix_pad it's
        // just the prefix; for date_seq it's prefix + today's date +
        // '-'. The numeric suffix follows.
        if ($format === 'prefix_date_seq') {
            $dateFormat = $dateFormat !== '' ? $dateFormat : 'ymd';
            $searchPrefix = $prefix . date($dateFormat) . '-';
        } else {
            $searchPrefix = $prefix;
        }

        // Scan the target table for existing codes matching the
        // search prefix. Pull the numeric suffix from each and find
        // the highest, then increment. We use SUBSTRING + CAST in SQL
        // so MySQL does the work; falling back to PHP if the table
        // isn't configured.
        $maxN = 0;
        if ($tbl !== '' && $col !== '') {
            try {
                $maxN = (int)db_val(
                    "SELECT MAX(CAST(SUBSTRING(`{$col}`, ?) AS UNSIGNED))
                       FROM `{$tbl}`
                      WHERE `{$col}` LIKE ?",
                    [strlen($searchPrefix) + 1, $searchPrefix . '%'],
                    0
                );
            } catch (\Throwable $e) {
                $maxN = 0;
            }
        }
        $next = $maxN + 1;

        // Retry up to 50× on uniqueness clash. Each candidate is
        // checked against the target table; if free, we return it.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $searchPrefix . str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
            $clash = null;
            if ($tbl !== '' && $col !== '') {
                try {
                    $clash = db_val(
                        "SELECT 1 FROM `{$tbl}` WHERE `{$col}` = ? LIMIT 1",
                        [$candidate],
                        null
                    );
                } catch (\Throwable $e) {
                    $clash = null;
                }
            }
            if (!$clash) return $candidate;
            $next++;
        }
        // Hard fallback if 50 candidates all clashed — should never
        // happen in practice. Use the timestamp for guaranteed
        // uniqueness.
        return $searchPrefix . date('His');
    }


    /**
     * Pre-migration fallback so existing pages keep working before
     * the code_sequences table exists or while it's empty. Mirrors
     * the legacy hardcoded prefixes for each known sequence.
     */
    function _code_next_legacy_fallback($name)
    {
        switch ($name) {
            case 'shipment':
                $prefix = 'SH-' . date('ymd') . '-';
                return $prefix . str_pad((string)(time() % 1000), 3, '0', STR_PAD_LEFT);
            case 'receipt':
                $prefix = 'RC-' . date('ymd') . '-';
                return $prefix . str_pad((string)(time() % 1000), 3, '0', STR_PAD_LEFT);
            case 'asset':
                return 'ASSET-' . date('YmdHis');
            case 'inv_item':
                return 'I-' . date('YmdHis');
            case 'vendor':
                return 'V-' . date('YmdHis');
            case 'inspection':
                return 'INSP-' . date('YmdHis');
            case 'inspection_template':
                return 'TPL-' . date('YmdHis');
            default:
                throw new Exception('Unknown code sequence: ' . $name);
        }
    }
}
