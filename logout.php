<?php
/**
 * MagDyn — Logout
 * Created: 20260515_060024_IST
 */
require_once __DIR__ . '/includes/bootstrap.php';
auth_sign_out();
redirect(url('/login.php'));
