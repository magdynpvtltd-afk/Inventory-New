-- MagDyn — migration_20260604_120000_IST
-- Register the import.vendors_sql permission for the legacy vendor SQL-dump
-- importer (import.php?action=vendors_sql) and grant it to the admin role.
--
-- Idempotent + phpMyAdmin-safe: re-running is a no-op. No SET @var usage.

SET NAMES utf8mb4;

-- 1) Insert the permission under the existing 'import' module, but only if it
--    isn't already there. The module is matched by its code so this doesn't
--    hardcode the module id.
INSERT INTO permissions (module_id, code, name)
SELECT m.id, 'vendors_sql', 'Import vendors from legacy SQL dump'
  FROM modules m
 WHERE m.code = 'import' COLLATE utf8mb4_unicode_ci
   AND NOT EXISTS (
       SELECT 1 FROM permissions p
        WHERE p.module_id = m.id
          AND p.code COLLATE utf8mb4_unicode_ci = 'vendors_sql'
   );

-- 2) Grant the new permission to the admin role (so administrators can run it
--    immediately, and can then grant it to other roles via the Roles UI).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.code COLLATE utf8mb4_unicode_ci = 'vendors_sql'
  JOIN modules m
    ON m.id = p.module_id
   AND m.code = 'import' COLLATE utf8mb4_unicode_ci
 WHERE r.code = 'admin' COLLATE utf8mb4_unicode_ci
   AND NOT EXISTS (
       SELECT 1 FROM role_permissions rp
        WHERE rp.role_id = r.id
          AND rp.permission_id = p.id
   );
