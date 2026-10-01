<?php
require __DIR__ . '/partials/bootstrap.php';

if (is_post()) {
    verify_csrf();
    unset($_SESSION['customer_id'], $_SESSION['my_orders'], $_SESSION['pending_order_id']);
    session_regenerate_id(true);
    flash('success', 'You have been signed out.');
}
redirect('/');
