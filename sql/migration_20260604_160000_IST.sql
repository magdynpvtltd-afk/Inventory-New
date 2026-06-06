-- MagDyn — migration_20260604_160000_IST
-- Fix: the "Categories" and "Vendor Empanelment" admin entries were
-- accidentally turned into dropdown groups (is_group=1), so their pages
-- were no longer clickable. Revert them to normal links like the other
-- admin pages (Users, Roles, …).
--
-- A module can be EITHER a clickable page link (is_group=0) OR a dropdown
-- container (is_group=1) — not both. So the items that were nested under
-- these two groups are re-homed directly under Admin (as normal links),
-- ordered right after their former parent, so nothing disappears from the
-- sidebar.
--
-- Idempotent + phpMyAdmin-safe (plain UPDATEs to a fixed end-state; the
-- self-table subquery is wrapped in a derived table to avoid error 1093).

SET NAMES utf8mb4;

-- 1) Make the two entries normal clickable links again.
UPDATE modules SET is_group = 0 WHERE code IN ('categories', 'vendor_empanelment');

-- 2) Re-home the former children directly under Admin.
UPDATE modules
   SET parent_id  = (SELECT id FROM (SELECT id FROM modules WHERE code = 'admin' LIMIT 1) AS a),
       sort_order = 91
 WHERE code = 'inventory_lookups';

UPDATE modules
   SET parent_id  = (SELECT id FROM (SELECT id FROM modules WHERE code = 'admin' LIMIT 1) AS a),
       sort_order = 92
 WHERE code = 'inventory_divisions';

UPDATE modules
   SET parent_id  = (SELECT id FROM (SELECT id FROM modules WHERE code = 'admin' LIMIT 1) AS a),
       sort_order = 93
 WHERE code = 'asset_lookups';

UPDATE modules
   SET parent_id  = (SELECT id FROM (SELECT id FROM modules WHERE code = 'admin' LIMIT 1) AS a),
       sort_order = 94
 WHERE code = 'inspection_uoms';

UPDATE modules
   SET parent_id  = (SELECT id FROM (SELECT id FROM modules WHERE code = 'admin' LIMIT 1) AS a),
       sort_order = 121
 WHERE code = 'nda_templates';
