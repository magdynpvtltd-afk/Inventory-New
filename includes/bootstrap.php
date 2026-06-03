<?php
/**
 * bootstrap.php — single entry point for every request to load config,
 * connect to DB, start session, and expose helper functions.
 *
 * Created: 20260515_060024_IST
 * PHP 7.0+
 */

// PHP 7+ minimum
if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    die('MagDyn requires PHP 7.0 or above. You are running ' . PHP_VERSION);
}

// Show errors only in dev; switch off in production by changing display_errors
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Default response is HTML/UTF-8 unless the page sets its own Content-Type.
// Headers are still mutable until first byte is sent, so endpoints that
// emit JSON or a manifest can override this freely (e.g. api/push_subscribe.php).
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
ini_set('default_charset', 'UTF-8');

// ---- Load configs ----
$ROOT = dirname(__DIR__);
$APP  = require $ROOT . '/config/app.config.php';
$DB   = require $ROOT . '/config/db.config.php';

// Make these globally available
$GLOBALS['APP']  = $APP;
$GLOBALS['DB']   = $DB;
$GLOBALS['ROOT'] = $ROOT;

date_default_timezone_set($APP['timezone']);

// ---- DB ----
require_once __DIR__ . '/db.php';

// ---- Session ----
session_name($APP['session_name']);
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string)$APP['session_lifetime']);
session_start();

// ---- Core helpers ----
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/sso.php';
