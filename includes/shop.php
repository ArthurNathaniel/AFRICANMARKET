<?php
/**
 * Storefront commerce logic: catalog, cart, shipping, coupons, orders, payments.
 */

require_once __DIR__ . '/accounts.php';

/* ---------------------------------------------------------------------------
 * Catalog
 * ------------------------------------------------------------------------- */

/** SQL fragment: a product is visible and buyable when it's active. */
const LIVE_PRODUCT_SQL = "p.status = 'active'";

function product_card_sql(): string
{
    return "SELECT p.id, p.name, p.slug, p.price, p.compare_price, p.image, p.stock_qty, p.origin_country, p.created_at,
                   (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating,
                   (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS review_count
            FROM products p";
}

function product_url(array $p): string
{
    return url('product.php?slug=' . urlencode($p['slug']));
}

function store_categories(): array
{
    static $cats = null;
    if ($cats === null) {
        $cats = fetch_all(
            "SELECT c.id, c.parent_id, c.name, c.slug,
                    (SELECT COUNT(*) FROM products p LEFT JOIN categories c2 ON c2.id = p.category_id
                     WHERE " . LIVE_PRODUCT_SQL . " AND (p.category_id = c.id OR c2.parent_id = c.id)) AS products
             FROM categories c WHERE c.is_active = 1 ORDER BY c.sort_order, c.name"
        );
    }
    return $cats;
}

function wishlist_ids(): array
{
    $c = current_customer();
    if (!$c) return [];
    static $ids = null;
    if ($ids === null) $ids = array_map('intval', array_column(fetch_all('SELECT product_id FROM wishlists WHERE customer_id = ?', [$c['id']]), 'product_id'));
    return $ids;
}

function stars_html(?float $rating, int $count = 0): string
{
    if (!$rating) return '';
    $full = (int) round($rating);
    return '<span class="stars" aria-label="Rated ' . e($rating) . ' out of 5">' . str_repeat('★', $full)
         . '<span class="off">' . str_repeat('★', 5 - $full) . '</span></span>'
         . ($count ? ' <span class="muted small">(' . $count . ')</span>' : '');
}

/* ---------------------------------------------------------------------------
 * Cart (stored in the session as product_id => quantity)
 * ------------------------------------------------------------------------- */
function cart_raw(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return array_sum(cart_raw());
}

function cart_set(int $productId, int $qty): void
{
    if ($qty <= 0) {
        unset($_SESSION['cart'][$productId]);
    } else {
        $_SESSION['cart'][$productId] = min($qty, 99);
    }
}

function cart_clear(): void
{
    unset($_SESSION['cart'], $_SESSION['coupon']);
}

/**
 * Cart lines joined with live product data. Lines whose product is no longer
 * available are dropped; quantities are capped at stock. Returns [lines, notices].
 */
function cart_lines(): array
{
    $raw = cart_raw();
    if (!$raw) return [[], []];
    $ids = array_map('intval', array_keys($raw));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = fetch_all(
        "SELECT p.id, p.name, p.slug, p.sku, p.price, p.cost_price, p.image, p.stock_qty, p.weight_lb, p.origin_country
         FROM products p WHERE p.id IN ($in) AND " . LIVE_PRODUCT_SQL, $ids);
    $byId = array_column($rows, null, 'id');

    $lines = [];
    $notices = [];
    foreach ($raw as $pid => $qty) {
        $p = $byId[$pid] ?? null;
        if (!$p) {
            cart_set((int) $pid, 0);
            $notices[] = 'An item in your cart is no longer available and was removed.';
            continue;
        }
        if ($p['stock_qty'] <= 0) {
            cart_set((int) $pid, 0);
            $notices[] = "“{$p['name']}” just sold out and was removed from your cart.";
            continue;
        }
        if ($qty > $p['stock_qty']) {
            $qty = (int) $p['stock_qty'];
            cart_set((int) $pid, $qty);
            $notices[] = "Only {$qty} of “{$p['name']}” left — we've updated your cart.";
        }
        $p['qty'] = (int) $qty;
        $p['line_total'] = round($p['price'] * $qty, 2);
        $lines[] = $p;
    }
    return [$lines, $notices];
}

function cart_subtotal(array $lines): float
{
    return round(array_sum(array_column($lines, 'line_total')), 2);
}

function cart_weight(array $lines): float
{
    return array_sum(array_map(fn($l) => $l['weight_lb'] * $l['qty'], $lines));
}

/* ---------------------------------------------------------------------------
 * Coupons
 * ------------------------------------------------------------------------- */
/** Returns [coupon|null, error|null]. */
function validate_coupon(string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    if ($code === '') return [null, null];
    $c = fetch_one('SELECT * FROM coupons WHERE code = ? AND is_active = 1', [$code]);
    $now = time();
    if (!$c) return [null, 'That coupon code is not valid.'];
    if ($c['starts_at'] && strtotime($c['starts_at']) > $now) return [null, 'That coupon is not active yet.'];
    if ($c['expires_at'] && strtotime($c['expires_at']) < $now) return [null, 'That coupon has expired.'];
    if ($c['max_uses'] && $c['used_count'] >= $c['max_uses']) return [null, 'That coupon has reached its usage limit.'];
    if ($subtotal < (float) $c['min_order']) return [null, 'Spend ' . money($c['min_order']) . ' or more to use this coupon.'];
    return [$c, null];
}

function coupon_discount(?array $coupon, float $subtotal): float
{
    if (!$coupon) return 0.0;
    $d = $coupon['type'] === 'percent' ? $subtotal * $coupon['value'] / 100 : (float) $coupon['value'];
    return round(min($d, $subtotal), 2);
}

/* ---------------------------------------------------------------------------
 * Shipping
 * ------------------------------------------------------------------------- */
function shipping_cost(array $m, float $subtotal, float $weight): float
{
    if ($m['rate_type'] === 'free') return 0.0;
    if ($m['free_over'] !== null && $subtotal >= (float) $m['free_over']) return 0.0;
    $cost = (float) $m['base_cost'];
    if ($m['rate_type'] === 'per_lb') $cost += (float) $m['per_lb_cost'] * max(1, ceil($weight));
    return round($cost, 2);
}

/** Active shipping methods (with computed cost) for a US state. */
function shipping_options(string $state, float $subtotal, float $weight): array
{
    foreach (fetch_all('SELECT id, regions FROM shipping_zones WHERE is_active = 1') as $z) {
        if (in_array(strtoupper($state), explode(',', (string) $z['regions']), true)) {
            $methods = fetch_all('SELECT * FROM shipping_methods WHERE zone_id = ? AND is_active = 1 ORDER BY base_cost', [$z['id']]);
            foreach ($methods as &$m) $m['cost'] = shipping_cost($m, $subtotal, $weight);
            return $methods;
        }
    }
    return [];
}

/** All zones' methods keyed by state, for the checkout page's live shipping picker. */
function shipping_table(float $subtotal, float $weight): array
{
    $methods = [];
    foreach (fetch_all('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY base_cost') as $m) {
        $methods[$m['zone_id']][] = [
            'id' => (int) $m['id'], 'name' => $m['name'], 'cost' => shipping_cost($m, $subtotal, $weight),
            'days' => $m['min_days'] . '–' . $m['max_days'] . ' business days',
        ];
    }
    $table = [];
    foreach (fetch_all('SELECT id, regions FROM shipping_zones WHERE is_active = 1') as $z) {
        foreach (array_filter(explode(',', (string) $z['regions'])) as $st) {
            $table[$st] ??= $methods[$z['id']] ?? [];
        }
    }
    return $table;
}

/* ---------------------------------------------------------------------------
 * Orders
 * ------------------------------------------------------------------------- */
/**
 * Creates an unpaid order from the cart. Stock is checked here and
 * decremented when payment is confirmed (mark_order_paid).
 * Returns [orderId|null, errors[]].
 */
function create_order_from_cart(array $ship, string $email, int $methodId, ?int $customerId): array
{
    [$lines] = cart_lines();
    if (!$lines) return [null, ['Your cart is empty.']];

    $subtotal = cart_subtotal($lines);
    $weight = cart_weight($lines);
    $method = null;
    foreach (shipping_options($ship['state'], $subtotal, $weight) as $m) {
        if ((int) $m['id'] === $methodId) $method = $m;
    }
    if (!$method) return [null, ['Choose a shipping method available for your state.']];

    [$coupon, $couponError] = validate_coupon($_SESSION['coupon'] ?? '', $subtotal);
    if ($couponError) unset($_SESSION['coupon']);
    $discount = coupon_discount($coupon, $subtotal);
    $tax = round(($subtotal - $discount) * (float) setting('tax_rate', 0) / 100, 2);
    $total = round($subtotal - $discount + $method['cost'] + $tax, 2);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO orders (order_number, customer_id, email, subtotal, discount_total, shipping_total, tax_total, grand_total, coupon_code,
             shipping_method, status, payment_status, ship_name, ship_line1, ship_line2, ship_city, ship_state, ship_zip, ship_phone)
           VALUES (?,?,?,?,?,?,?,?,?,?, \'pending\', \'unpaid\', ?,?,?,?,?,?,?)', [
            'TMP-' . bin2hex(random_bytes(6)), $customerId, $email, $subtotal, $discount, $method['cost'], $tax, $total,
            $coupon['code'] ?? null, $method['name'],
            $ship['name'], $ship['line1'], $ship['line2'] ?: null, $ship['city'], $ship['state'], $ship['zip'], $ship['phone'] ?: null,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        q('UPDATE orders SET order_number = ? WHERE id = ?', [setting('order_prefix', 'JAM') . '-' . (100000 + $orderId), $orderId]);
        foreach ($lines as $l) {
            q('INSERT INTO order_items (order_id, product_id, product_name, sku, unit_price, unit_cost, quantity, line_total) VALUES (?,?,?,?,?,?,?,?)',
              [$orderId, $l['id'], $l['name'], $l['sku'], $l['price'], $l['cost_price'], $l['qty'], $l['line_total']]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    $_SESSION['my_orders'][] = $orderId;
    return [$orderId, []];
}

/** Whether the current visitor may view this order (owner, or placed in this session). */
function can_view_order(array $order): bool
{
    $c = current_customer();
    if ($c && (int) $order['customer_id'] === (int) $c['id']) return true;
    return in_array((int) $order['id'], array_map('intval', $_SESSION['my_orders'] ?? []), true);
}

/**
 * Records a successful payment exactly once: decrements stock, marks the order
 * paid/processing, counts the coupon, and sends confirmation emails.
 */
function mark_order_paid(int $orderId, string $gateway, string $reference, float $amount): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $order = fetch_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
    if (!$order || $order['payment_status'] === 'paid') {
        $pdo->rollBack();
        return false;
    }
    q("INSERT INTO payments (order_id, gateway, transaction_ref, amount, status) VALUES (?, ?, ?, ?, 'completed')", [$orderId, $gateway, $reference, $amount]);
    q("UPDATE orders SET payment_status = 'paid', status = 'processing' WHERE id = ?", [$orderId]);
    q('UPDATE products p JOIN order_items oi ON oi.product_id = p.id SET p.stock_qty = GREATEST(0, p.stock_qty - oi.quantity) WHERE oi.order_id = ?', [$orderId]);
    if ($order['coupon_code']) q('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?', [$order['coupon_code']]);
    $pdo->commit();

    send_order_emails($orderId);
    return true;
}

function send_order_emails(int $orderId): void
{
    $o = fetch_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $items = fetch_all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]);
    $store = setting('store_name', APP_NAME);
    $list = '';
    foreach ($items as $i) $list .= "- {$i['quantity']} × {$i['product_name']}  " . money($i['line_total']) . "\n";

    send_mail($o['email'], "Order #{$o['order_number']} confirmed",
        "Hi {$o['ship_name']},\n\nThank you for your order! Here's what you bought:\n\n$list\nTotal paid: " . money($o['grand_total'])
        . "\n\nWe'll email you tracking details as soon as it ships.\nTrack your order: " . absolute_url('order-tracking.php') . "\n\n$store");

    // Let the team know there's an order to pack.
    if ($staff = setting('support_email')) {
        send_mail($staff, "New order #{$o['order_number']} — " . money($o['grand_total']),
            "New paid order to ship to {$o['ship_city']}, {$o['ship_state']}:\n\n$list\n" . absolute_url('admin/orders.php?id=' . $orderId));
    }
}

function absolute_url(string $path): string
{
    $u = url($path);
    if (preg_match('#^https?://#', $u)) return $u;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $u;
}

/** Public tracking link for common carriers, or null. */
function tracking_link(?string $carrier, ?string $number): ?string
{
    if (!$number) return null;
    $links = ['DHL' => 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=', 'FedEx' => 'https://www.fedex.com/fedextrack/?trknbr=',
              'UPS' => 'https://www.ups.com/track?tracknum=', 'USPS' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels='];
    foreach ($links as $k => $href) {
        if (stripos((string) $carrier, $k) !== false) return $href . rawurlencode($number);
    }
    return null;
}

/* ---------------------------------------------------------------------------
 * Stripe (REST API over cURL — no SDK required)
 * ------------------------------------------------------------------------- */
function stripe_enabled(): bool
{
    return (string) setting('stripe_secret_key', '') !== '' && function_exists('curl_init');
}

function test_payments_enabled(): bool
{
    return APP_ENV === 'development' && !stripe_enabled();
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => setting('stripe_secret_key') . ':',
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($body === false) throw new RuntimeException('Could not reach Stripe: ' . $err);
    $data = json_decode($body, true) ?: [];
    if ($code >= 400) throw new RuntimeException($data['error']['message'] ?? 'Stripe error');
    return $data;
}

function stripe_checkout_url(array $order): string
{
    $items = fetch_all('SELECT product_name, quantity FROM order_items WHERE order_id = ?', [$order['id']]);
    $desc = implode(', ', array_map(fn($i) => $i['quantity'] . ' × ' . $i['product_name'], $items));
    $session = stripe_request('POST', 'checkout/sessions', [
        'mode' => 'payment',
        'customer_email' => $order['email'],
        'client_reference_id' => $order['id'],
        'metadata' => ['order_id' => $order['id'], 'order_number' => $order['order_number']],
        'payment_intent_data' => ['metadata' => ['order_id' => $order['id'], 'order_number' => $order['order_number']]],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => (int) round($order['grand_total'] * 100),
                'product_data' => [
                    'name' => setting('store_name', APP_NAME) . ' order #' . $order['order_number'],
                    'description' => mb_substr($desc, 0, 450),
                ],
            ],
        ]],
        'success_url' => absolute_url('order-success.php?order=' . urlencode($order['order_number'])) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => absolute_url('checkout.php?cancelled=1'),
    ]);
    q('UPDATE orders SET stripe_session_id = ? WHERE id = ?', [$session['id'], $order['id']]);
    return $session['url'];
}

/** Confirms a Checkout Session against the order and marks it paid. */
function stripe_confirm_session(array $order, string $sessionId): bool
{
    if (!stripe_enabled() || $order['payment_status'] === 'paid' || $sessionId !== $order['stripe_session_id']) return false;
    $s = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
    $expected = (int) round($order['grand_total'] * 100);
    if (($s['payment_status'] ?? '') === 'paid' && (int) ($s['amount_total'] ?? 0) === $expected
        && (string) ($s['metadata']['order_id'] ?? '') === (string) $order['id']) {
        return mark_order_paid((int) $order['id'], 'stripe', (string) ($s['payment_intent'] ?? $sessionId), $expected / 100);
    }
    return false;
}
