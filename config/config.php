<?php
/**
 * Jenmond Mobile African Market - core configuration.
 * Edit the values in this file for each environment (local XAMPP, staging, production).
 */

// ---- Application -----------------------------------------------------------
define('APP_NAME', 'Jenmond Mobile African Market');
define('APP_TAGLINE', 'Shop Africa in the USA');
define('APP_ENV', 'development');          // 'development' | 'production'

// ---- Database (XAMPP defaults) ---------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'african_market');
define('DB_USER', 'root');
define('DB_PASS', '');

// define('DB_HOST', '127.0.0.1');
// define('DB_PORT', 3306);
// define('DB_NAME', 'u257031014_jen');
// define('DB_USER', 'u257031014_jen');
// define('DB_PASS', 'OnGod@@123');

// ---- URLs ------------------------------------------------------------------
// Leave empty to auto-detect from the web server's document root,
// or set explicitly in production, e.g. 'https://www.jenmondafricanmarket.com'
define('BASE_URL', '');

// ---- Security --------------------------------------------------------------
// Code a new admin must enter on the sign-up page. Accounts created with it
// stay "pending" until a super admin approves them in Settings > Admin team.
define('ADMIN_INVITE_CODE', 'JAM-INVITE-CHANGE-ME');
define('SESSION_IDLE_MINUTES', 120);
define('LOGIN_MAX_ATTEMPTS', 5);           // per email/IP ...
define('LOGIN_LOCK_MINUTES', 15);          // ... within this window

// ---- Mail ------------------------------------------------------------------
define('MAIL_FROM', 'no-reply@jenmondafricanmarket.com');

// ---- Paths -----------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('MAX_UPLOAD_MB', 5);

date_default_timezone_set('America/New_York');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
