<?php
define('ADMIN_PUBLIC', true);
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    log_activity('logout', 'Signed out');
    admin_logout();
    flash('success', 'You have been signed out.');
}
redirect('admin/login.php');
