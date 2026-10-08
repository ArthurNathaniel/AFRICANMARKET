<?php
/**
 * Stripe webhook endpoint. In the Stripe dashboard (test mode and/or live mode),
 * add an endpoint pointing at
 *   https://YOUR-DOMAIN/stripe-webhook.php
 * listening for "checkout.session.completed", then paste its signing secret
 * into Admin → Settings → Payment gateways under the matching mode.
 */
require_once __DIR__ . '/includes/shop.php';

$payload = file_get_contents('php://input');
$header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify the Stripe-Signature header: t=timestamp,v1=hex(hmac_sha256("t.payload"))
$parts = [];
foreach (explode(',', $header) as $kv) {
    [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
    $parts[$k][] = $v;
}
$timestamp = (int) ($parts['t'][0] ?? 0);
$valid = false;
foreach (['test', 'live'] as $mode) {
    $secret = stripe_key('webhook_secret', $mode);
    if ($secret === '') continue;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    if (array_filter($parts['v1'] ?? [], fn($sig) => hash_equals($expected, $sig))) {
        $valid = true;
        break;
    }
}
if (!$valid || abs(time() - $timestamp) >= 300) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (($event['type'] ?? '') === 'checkout.session.completed') {
    $s = $event['data']['object'];
    $order = fetch_one('SELECT * FROM orders WHERE id = ?', [(int) ($s['metadata']['order_id'] ?? 0)]);
    $expectedAmount = $order ? (int) round($order['grand_total'] * 100) : -1;
    if ($order && ($s['payment_status'] ?? '') === 'paid' && (int) $s['amount_total'] === $expectedAmount) {
        mark_order_paid((int) $order['id'], 'stripe', (string) ($s['payment_intent'] ?? $s['id']), $expectedAmount / 100);
    }
}
http_response_code(200);
echo 'ok';
