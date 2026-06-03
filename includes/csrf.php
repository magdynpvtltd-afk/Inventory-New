<?php
/**
 * CSRF token helpers.
 *
 * Created: 20260515_060024_IST
 */

function csrf_token()
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    $name = $GLOBALS['APP']['csrf_field'];
    return '<input type="hidden" name="' . h($name) . '" value="' . h(csrf_token()) . '">';
}

function csrf_check()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $name = $GLOBALS['APP']['csrf_field'];
    $given = isset($_POST[$name]) ? $_POST[$name] : '';
    $ok = !empty($given) && hash_equals(csrf_token(), $given);
    if (!$ok) {
        http_response_code(419);
        die('CSRF check failed. Reload the page and try again.');
    }
    return true;
}
