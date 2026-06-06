-- MagDyn — migration_20260604_170000_IST
-- BOM tree top elements: an item is a "finished good" (and thus a BOM top
-- element) when its category is a Finished Good. The recognition is now
-- name-based (not tied to a fragile category id/code that CSV re-imports can
-- renumber), anchored on the stable per-item is_product flag.
--
-- This backfill flags every item whose CURRENT category is a Finished Good
-- so existing items (e.g. P-00002, P-00003) appear in the BOM tree right away.
-- It only ever sets is_product = 1 (never clears), so legacy products stay.
--
-- Idempotent + phpMyAdmin-safe.

SET NAMES utf8mb4;

UPDATE inv_items i
  JOIN categories c ON c.id = i.category_id
   SET i.is_product = 1
 WHERE i.is_product <> 1
   AND ( LOWER(c.name) LIKE 'finished good%'
         OR c.code COLLATE utf8mb4_unicode_ci IN ('finshd', 'FINISHED_GOO') );
