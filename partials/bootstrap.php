<?php
/**
 * Loaded at the top of every storefront and customer-account page.
 */
require_once dirname(__DIR__) . '/includes/shop.php';

if (!is_installed()) {
    redirect('install.php');
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
