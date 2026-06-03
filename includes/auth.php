<?php
/**
 * Authentication & "view-as" impersonation.
 *
 * Session shape:
 *   $_SESSION['uid']               = real signed-in user id
 *   $_SESSION['impersonate_uid']   = id of user we are "viewing as"
 *
 * current_user() returns the impersonated user when impersonation is active,
 * so every permission check below it sees the target user's view.
 * real_user() always returns the actual signed-in user; useful to render
 * the "Viewing as …" banner and the "Return to admin" control.
 *
 * Created: 20260515_060024_IST
 */

/** Try to authenticate username+password locally. Returns user row or null. */
function auth_local($username, $password)
{
    $u = db_one('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1',
                [$username, $username]);
    if (!$u || empty($u['password_hash'])) return null;
    $pepper = $GLOBALS['APP']['password_pepper'];
    if (!password_verify($password . $pepper, $u['password_hash'])) return null;
    return $u;
}

/** Sign a user in (after either local or SSO success) */
function auth_sign_in($userId)
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$userId;
    unset($_SESSION['impersonate_uid']);
    db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int)$userId]);
}

function auth_sign_out()
{
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    $_SESSION = [];
    session_destroy();
}

/** Real authenticated user id (ignoring impersonation) */
function real_user_id()
{
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
}

function real_user()
{
    static $cache = null;
    if ($cache !== null) return $cache ?: null;
    $id = real_user_id();
    if (!$id) { $cache = false; return null; }
    $cache = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    return $cache ?: null;
}

/** Current effective user (real, OR impersonated when active) */
function current_user_id()
{
    if (!empty($_SESSION['impersonate_uid'])) return (int)$_SESSION['impersonate_uid'];
    return real_user_id();
}

function current_user()
{
    static $cache = null;
    if ($cache !== null) return $cache ?: null;
    $id = current_user_id();
    if (!$id) { $cache = false; return null; }
    $cache = db_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    return $cache ?: null;
}

function is_impersonating()
{
    return !empty($_SESSION['impersonate_uid']) && real_user_id() !== (int)$_SESSION['impersonate_uid'];
}

/** Require a signed-in user; bounce to login otherwise. */
function require_login()
{
    if (!real_user_id()) {
        $_SESSION['_return_to'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(url('/login.php'));
    }
}

/** Start "view-as" — admin only. */
function impersonate_start($targetUserId)
{
    $admin = real_user();
    if (!$admin || !permission_check('users', 'impersonate')) {
        flash_set('error', 'You do not have permission to view as another user.');
        return false;
    }
    $target = db_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$targetUserId]);
    if (!$target) {
        flash_set('error', 'Target user not found.');
        return false;
    }
    if ((int)$target['id'] === (int)$admin['id']) {
        flash_set('error', 'You are already that user.');
        return false;
    }
    $_SESSION['impersonate_uid'] = (int)$target['id'];
    db_exec(
        'INSERT INTO audit_log (actor_id, target_id, action, details, ip)
         VALUES (?, ?, ?, ?, ?)',
        [
            (int)$admin['id'],
            (int)$target['id'],
            'impersonate.start',
            'viewing as ' . $target['username'],
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
    return true;
}

function impersonate_stop()
{
    if (!is_impersonating()) return;
    db_exec(
        'INSERT INTO audit_log (actor_id, target_id, action, details, ip)
         VALUES (?, ?, ?, ?, ?)',
        [
            real_user_id(),
            (int)$_SESSION['impersonate_uid'],
            'impersonate.stop',
            'returned to admin view',
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
    unset($_SESSION['impersonate_uid']);
}
