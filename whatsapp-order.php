<?php
/**
 * "Order via WhatsApp": records the cart (or ?product=ID) as an unpaid WhatsApp
 * order so it appears in Admin → Orders, then opens the store's WhatsApp chat with
 * the order number and items. The admin replies with an invoice/payment link.
 */
require __DIR__ . '/partials/bootstrap.php';

$greeting = "Hi! I'd like to place an order.";
if (setting('whatsapp_number', '') === '') redirect('/');
// The token (this visitor's CSRF token) stops other sites and crawlers from creating orders.
if (!hash_equals(csrf_token(), input('t'))) redirect(whatsapp_url($greeting));

if ($pid = input_int('product')) {
    $p = fetch_one('SELECT p.id, p.name, p.sku, p.price, p.cost_price, p.stock_qty FROM products p WHERE p.id = ? AND ' . LIVE_PRODUCT_SQL, [$pid]);
    if (!$p || $p['stock_qty'] <= 0) {
        flash('error', 'Sorry, that product is not available right now.');
        redirect('shop.php');
    }
    $qty = max(1, min(input_int('qty', 1), (int) $p['stock_qty'], 99));
    $lines = [$p + ['qty' => $qty, 'line_total' => round($p['price'] * $qty, 2)]];
    $source = 'product:' . $pid;
} else {
    [$lines] = cart_lines();
    $source = 'cart';
}

if (!$lines) redirect(whatsapp_url($greeting));

$order = save_whatsapp_order($lines, $source);
redirect(whatsapp_url(whatsapp_order_text($order, $lines)));
