-- MagDyn — migration_20260604_150000_IST
-- Add a dedicated "Divisions" admin menu entry that links straight to the
-- inventory division manager (inventory_lookups.php?type=division), with its
-- own permission so it surfaces in the sidebar under Admin → Categories.
--
-- Inventory divisions live in the `categories` table (type='division') and
-- were already editable via Inventory Lookups → Division tab; this just makes
-- adding them a first-class, directly-reachable option.
--
-- Idempotent + phpMyAdmin-safe (procedure with local DECLAREs, no SET @var).

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS magdyn_p_add_divisions_admin;
DELIMITER //
CREATE PROCEDURE magdyn_p_add_divisions_admin()
BEGIN
    DECLARE v_parent  INT DEFAULT NULL;
    DECLARE v_mod     INT DEFAULT NULL;
    DECLARE v_pview   INT DEFAULT NULL;
    DECLARE v_pmanage INT DEFAULT NULL;

    -- 1) Sidebar module under the existing Categories group (itself under Admin).
    IF NOT EXISTS (SELECT 1 FROM modules WHERE code = 'inventory_divisions') THEN
        SELECT id INTO v_parent FROM modules WHERE code = 'categories' LIMIT 1;
        INSERT INTO modules (code, name, description, parent_id, is_group, virtual_url, sort_order, is_active)
        VALUES ('inventory_divisions', 'Divisions', 'Add / manage inventory divisions',
                v_parent, 0, '/inventory_lookups.php?type=division', 15, 1);
    END IF;

    SELECT id INTO v_mod FROM modules WHERE code = 'inventory_divisions' LIMIT 1;

    -- 2) Permissions for the new module.
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'view') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'view', 'View inventory divisions');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM permissions WHERE module_id = v_mod AND code = 'manage') THEN
        INSERT INTO permissions (module_id, code, name) VALUES (v_mod, 'manage', 'Add / edit / delete inventory divisions');
    END IF;

    -- 3) Grant both to the admin role so it works immediately.
    SELECT id INTO v_pview   FROM permissions WHERE module_id = v_mod AND code = 'view'   LIMIT 1;
    SELECT id INTO v_pmanage FROM permissions WHERE module_id = v_mod AND code = 'manage' LIMIT 1;

    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, v_pview FROM roles r
     WHERE r.code = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = v_pview);

    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, v_pmanage FROM roles r
     WHERE r.code = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = v_pmanage);
END //
DELIMITER ;
CALL magdyn_p_add_divisions_admin();
DROP PROCEDURE magdyn_p_add_divisions_admin;
