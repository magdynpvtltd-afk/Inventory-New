<?php
/**
 * MagDyn — Inventory Locations (DEPRECATED — merged into Locations)
 * Created: 20260515_170000_IST
 * Updated: 20260517_123000_IST — table inv_locations merged into
 *                                locations; this page now redirects.
 *
 * The inv_locations table was merged into the unified `locations`
 * table by migration_20260517_123000_IST. The inventory_locations
 * sidebar module + its permissions were removed in the same migration.
 *
 * This file is kept as a redirect stub so any bookmarks or legacy
 * URL references (audit log, etc.) still land somewhere useful.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

// Forward any action= parameter through to locations.php so e.g.
// /inventory_locations.php?action=new still opens the new-location form.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '/locations.php' . ($qs !== '' ? '?' . $qs : '');
redirect(url($target));
