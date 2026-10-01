<?php
/**
 * Loaded at the top of every admin page.
 * Pages that must be reachable while signed out define ADMIN_PUBLIC first.
 */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!is_installed()) {
    redirect('install.php');
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (!defined('ADMIN_PUBLIC')) {
    $admin = require_admin();
}
