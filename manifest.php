<?php
/**
 * MagDyn — Web App Manifest (PWA)
 * Created: 20260515_060024_IST
 *
 * Served as application/manifest+json. Linked from every page via
 * <link rel="manifest"> in includes/header.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$APP = $GLOBALS['APP'];
$base = rtrim($APP['base_url'], '/');

header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => $APP['app_name'],
    'short_name'       => $APP['app_name'],
    'description'      => $APP['app_tagline'],
    'start_url'        => $base . '/mobile/',
    'scope'            => $base . '/',
    'display'          => $APP['pwa']['display'],
    'orientation'      => 'portrait',
    'theme_color'      => $APP['pwa']['theme_color'],
    'background_color' => $APP['pwa']['background_color'],
    'icons' => [
        [
            'src'   => $base . '/assets/img/icon-192.png',
            'sizes' => '192x192',
            'type'  => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'   => $base . '/assets/img/icon-512.png',
            'sizes' => '512x512',
            'type'  => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
