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
 * WhatsApp orders
 * Clicking "Order via WhatsApp" records an unpaid order (so it shows in
 * Admin → Orders with an order number) and then opens the chat with that number.
 * ------------------------------------------------------------------------- */
const WHATSAPP_ORDER_METHOD = 'WhatsApp order';

/* ---------------------------------------------------------------------------
 * Store pickup (customer comes in, or sends an Uber/driver with the order number)
 * ------------------------------------------------------------------------- */
const PICKUP_METHOD = 'Pickup';

function pickup_enabled(): bool
{
    return setting('pickup_enabled', '1') === '1';
}

function is_pickup_order(array $order): bool
{
    return ($order['shipping_method'] ?? '') === PICKUP_METHOD;
}

/** Pickup address lines from Settings → Store (pickup address, falling back to the business address). */
function pickup_address(): string
{
    return trim((string) (setting('pickup_address') ?: setting('business_address', '')));
}

/** Plain-text pickup instructions for emails and messages. */
function pickup_instructions(string $orderNumber = ''): string
{
    $addr = pickup_address();
    $hours = trim((string) setting('pickup_hours', ''));
    return 'Pick up at: ' . ($addr !== '' ? preg_replace('/\s*\n\s*/', ', ', $addr) : 'we will message you the address') . "\n"
         . ($hours !== '' ? "Hours: $hours\n" : '')
         . 'Come in yourself, or send an Uber / driver with your order number' . ($orderNumber !== '' ? " #$orderNumber" : '') . '.';
}

function is_whatsapp_order(array $order): bool
{
    return $order['shipping_method'] === WHATSAPP_ORDER_METHOD;
}

/** Link that records a WhatsApp order for the cart (or one product) and opens the chat; '' if no number is set. */
function whatsapp_order_link(int $productId = 0): string
{
    if (setting('whatsapp_number', '') === '') return '';
    return url('whatsapp-order.php?t=' . csrf_token() . ($productId ? '&product=' . $productId : ''));
}

/**
 * Creates the visitor's WhatsApp order for $lines, or updates the one they already
 * started from the same source (cart / product) if it hasn't been paid or invoiced.
 * $lines use the cart_lines() shape. Returns the order row.
 */
function save_whatsapp_order(array $lines, string $source): array
{
    $subtotal = cart_subtotal($lines);
    $tax = round($subtotal * (float) setting('tax_rate', 0) / 100, 2);
    $total = round($subtotal + $tax, 2);

    // Reuse only while the admin hasn't priced it (shipping/discount) and the customer hasn't started paying.
    $existing = fetch_one("SELECT * FROM orders WHERE id = ? AND payment_status = 'unpaid' AND status = 'pending' AND shipping_method = ?
                             AND stripe_session_id IS NULL AND shipping_total = 0 AND discount_total = 0",
                          [(int) ($_SESSION['wa_orders'][$source] ?? 0), WHATSAPP_ORDER_METHOD]);
    if ($existing) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q('UPDATE orders SET subtotal = ?, tax_total = ?, grand_total = ? WHERE id = ?', [$subtotal, $tax, $total, $existing['id']]);
            q('DELETE FROM order_items WHERE order_id = ?', [$existing['id']]);
            insert_order_items((int) $existing['id'], $lines);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        return fetch_one('SELECT * FROM orders WHERE id = ?', [$existing['id']]);
    }

    $c = current_customer();
    $orderId = insert_order([
        'customer_id' => $c['id'] ?? null, 'email' => $c['email'] ?? '',
        'subtotal' => $subtotal, 'tax_total' => $tax, 'grand_total' => $total,
        'shipping_method' => WHATSAPP_ORDER_METHOD,
        'ship_name' => $c ? trim($c['first_name'] . ' ' . $c['last_name']) : null, 'ship_phone' => $c['phone'] ?? null,
    ], $lines);
    $_SESSION['wa_orders'][$source] = $orderId;
    notify_staff_new_order($orderId, 'whatsapp');
    $_SESSION['my_orders'][] = $orderId;
    return fetch_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
}

function whatsapp_order_text(array $order, array $lines): string
{
    $text = "Hi! I'd like to place an order.\n\n*Order #{$order['order_number']}*\n";
    foreach ($lines as $l) $text .= "• {$l['qty']} × {$l['name']} — " . money($l['line_total']) . "\n";
    $text .= "\nItems: " . array_sum(array_column($lines, 'qty')) . "\nSubtotal: " . money($order['subtotal'])
           . "\n\nPlease send me the invoice so I can pay online.";
    return $text;
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
function create_order_from_cart(array $ship, string $email, int $methodId, ?int $customerId, bool $pickup = false): array
{
    [$lines] = cart_lines();
    if (!$lines) return [null, ['Your cart is empty.']];

    $subtotal = cart_subtotal($lines);
    $weight = cart_weight($lines);
    $method = null;
    if ($pickup) {
        $method = ['name' => PICKUP_METHOD, 'cost' => 0.0];
    } else {
        foreach (shipping_options($ship['state'], $subtotal, $weight) as $m) {
            if ((int) $m['id'] === $methodId) $method = $m;
        }
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
            $ship['name'], $ship['line1'] ?: null, $ship['line2'] ?: null, $ship['city'] ?: null, $ship['state'] ?: null, $ship['zip'] ?: null, $ship['phone'] ?: null,
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

    if ($o['email'] !== '') send_mail($o['email'], "Order #{$o['order_number']} confirmed",
        "Hi {$o['ship_name']},\n\nThank you for your order! Here's what you bought:\n\n$list\nTotal paid: " . money($o['grand_total'])
        . (is_pickup_order($o)
            ? "\n\nYOUR ORDER IS FOR PICKUP\n" . pickup_instructions($o['order_number']) . "\nWe'll text you when it's ready."
            : "\n\nWe'll email you tracking details as soon as it ships.")
        . "\nTrack your order: " . absolute_url('order-tracking.php') . "\n\n$store");

    // Let the team know there's an order to pack.
    notify_staff_new_order($orderId, 'paid');
}

/**
 * Emails every support address (Admin → Settings → Store) a full breakdown of an order.
 * $event: 'paid' (payment received — ready to pack) or 'whatsapp' (new WhatsApp order awaiting an invoice).
 */
function notify_staff_new_order(int $orderId, string $event): void
{
    $to = support_emails();
    if (!$to) return;
    [$subject, $html, $text] = staff_order_email($orderId, $event);
    $o = fetch_one('SELECT email FROM orders WHERE id = ?', [$orderId]);
    $send = function () use ($to, $subject, $html, $text, $o) {
        foreach ($to as $addr) send_html_mail($addr, $subject, $html, $text, $o['email'] ?: null);
    };
    if (PHP_SAPI === 'cli') { $send(); return; }
    // Send after the response so the customer isn't kept waiting on the mail server.
    register_shutdown_function(function () use ($send) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        $send();
    });
}

/** Builds [subject, html, text] for the staff new-order email. */
function staff_order_email(int $orderId, string $event): array
{
    $o = fetch_one('SELECT o.*, c.first_name, c.last_name, c.created_at AS customer_since FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?', [$orderId]);
    $items = fetch_all('SELECT oi.*, p.image, p.slug, p.stock_qty, p.origin_country, p.weight_lb FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id', [$orderId]);
    $pay = fetch_one("SELECT * FROM payments WHERE order_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$orderId]);
    $store = setting('store_name', APP_NAME);
    $paid = $o['payment_status'] === 'paid';
    $wa = is_whatsapp_order($o);
    $units = array_sum(array_column($items, 'quantity'));
    $weight = array_sum(array_map(fn($i) => (float) $i['weight_lb'] * $i['quantity'], $items));
    $costKnown = $items && !in_array(null, array_column($items, 'unit_cost'), true);
    $profit = $o['subtotal'] - $o['discount_total'] - array_sum(array_map(fn($i) => (float) $i['unit_cost'] * $i['quantity'], $items));
    $prevOrders = $o['customer_id'] ? (int) fetch_value("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND id <> ? AND payment_status = 'paid'", [$o['customer_id'], $orderId]) : 0;
    $adminUrl = absolute_url('admin/orders.php?id=' . $orderId);
    $name = $o['ship_name'] ?: trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')) ?: 'Customer (name not added yet)';
    $pickup = is_pickup_order($o);
    $hasAddress = (bool) $o['ship_line1'];
    $addressLines = array_filter([$o['ship_line1'], $o['ship_line2'], $hasAddress ? "{$o['ship_city']}, {$o['ship_state']} {$o['ship_zip']}" : null, $hasAddress ? 'United States' : null]);
    $mapUrl = $hasAddress ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $addressLines)) : null;
    $waUrl = $o['ship_phone'] ? 'https://wa.me/' . ltrim(phone_href($o['ship_phone']), '+') : null;
    $source = $wa ? 'WhatsApp' : 'Website checkout';

    if ($event === 'paid') {
        $headline = 'New paid order — ready to pack';
        $subject = "New paid order #{$o['order_number']} — " . money($o['grand_total']) . " — {$name}" . ($hasAddress ? " ({$o['ship_city']}, {$o['ship_state']})" : '');
        $next = $pickup
            ? 'PICKUP order — payment is confirmed. Get the items below ready, text the customer when it is ready, and hand it over to them or their Uber/driver against order #' . $o['order_number'] . '. Then set the order to Picked up.'
            : 'Payment is confirmed. Pick and pack the items below, then add the carrier and tracking number on the order page.';
        if ($pickup) $subject = "New PICKUP order #{$o['order_number']} — " . money($o['grand_total']) . " — {$name}";
    } else {
        $headline = 'New WhatsApp order — send the invoice';
        $subject = "New WhatsApp order #{$o['order_number']} — " . money($o['grand_total']) . ' — awaiting invoice';
        $next = 'The customer was sent to WhatsApp with this order number. When their message arrives, open the order, add their details and shipping (Step 1), then send the invoice (Step 2). Not paid yet — do not ship.';
    }

    // ---- Plain-text version ----
    $t = "$headline\n" . str_repeat('=', mb_strlen($headline)) . "\n\n$next\n\n";
    $t .= "ORDER\n  Order #: {$o['order_number']}\n  Placed: " . format_date($o['created_at'], 'D, M j, Y g:i A') . "\n  Source: $source\n"
        . "  Status: " . ucfirst($o['status']) . "\n  Payment: " . ucfirst($o['payment_status']) . "\n";
    if ($pay) $t .= "  Paid: " . money($pay['amount']) . ' via ' . ucfirst($pay['gateway']) . ' on ' . format_date($pay['created_at'], 'M j, Y g:i A') . "\n  Payment ref: {$pay['transaction_ref']}\n";
    $t .= "\nCUSTOMER\n  Name: $name\n  Email: " . ($o['email'] ?: '—') . "\n  Phone: " . ($o['ship_phone'] ?: '—') . "\n"
        . '  Account: ' . ($o['customer_id'] ? "Registered (customer since " . format_date($o['customer_since']) . "), $prevOrders previous paid order(s)" : 'Guest') . "\n";
    $t .= ($pickup ? "\nDELIVERY\n  PICKUP — customer comes in or sends an Uber/driver with order #{$o['order_number']}\n"
                   : "\nSHIP TO\n" . ($hasAddress ? '  ' . implode("\n  ", $addressLines) . "\n  Map: $mapUrl\n" : "  Not provided yet — the customer adds it on the payment page.\n"))
        . '  Method: ' . ($o['shipping_method'] ?: '—') . "\n  Parcel: $units item(s), about " . rtrim(rtrim(number_format($weight, 2), '0'), '.') . " lb\n";
    $t .= "\nITEMS\n";
    foreach ($items as $i) {
        $t .= "  {$i['quantity']} × {$i['product_name']}" . ($i['sku'] ? " (SKU {$i['sku']})" : '') . ' @ ' . money($i['unit_price']) . ' = ' . money($i['line_total'])
            . ($i['stock_qty'] !== null ? "  [{$i['stock_qty']} left in stock]" : '') . "\n";
    }
    $t .= "\nTOTALS\n  Subtotal: " . money($o['subtotal']) . "\n"
        . ($o['discount_total'] > 0 ? '  Discount' . ($o['coupon_code'] ? " ({$o['coupon_code']})" : '') . ': −' . money($o['discount_total']) . "\n" : '')
        . '  Shipping: ' . money($o['shipping_total']) . "\n  Tax: " . money($o['tax_total']) . "\n  TOTAL: " . money($o['grand_total']) . "\n"
        . ($costKnown ? '  Gross profit on items: ' . money($profit) . "\n" : '');
    if ($o['admin_notes']) $t .= "\nNOTES\n  {$o['admin_notes']}\n";
    $t .= "\nOpen the order: $adminUrl\n" . ($waUrl ? "Message the customer on WhatsApp: $waUrl\n" : '') . "\n— $store\n";

    // ---- HTML version (inline styles for email clients) ----
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $row = fn($k, $v) => '<tr><td style="padding:6px 0;color:#6b6457;width:150px;vertical-align:top">' . $k . '</td><td style="padding:6px 0;color:#1d1b16;font-weight:600">' . $v . '</td></tr>';
    $section = fn($title, $inner) => '<tr><td style="padding:18px 28px 4px"><div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#c96a1b;font-weight:700;margin-bottom:6px">' . $title . '</div>' . $inner . '</td></tr>';
    $badge = fn($label, $bg, $fg) => '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:700">' . $label . '</span>';
    $payBadge = $paid ? $badge('PAID', '#dcf3e6', '#0f6a3c') : $badge(strtoupper($o['payment_status']), '#fff1d6', '#8a5a00');
    $btn = fn($href, $label, $bg) => '<a href="' . $h($href) . '" style="display:inline-block;padding:12px 20px;margin:4px 8px 4px 0;border-radius:8px;background:' . $bg . ';color:#ffffff;font-weight:700;text-decoration:none">' . $label . '</a>';

    $orderRows = $row('Order #', $h($o['order_number']) . ' ' . $payBadge)
        . $row('Placed', $h(format_date($o['created_at'], 'D, M j, Y \a\t g:i A')))
        . $row('Source', $h($source))
        . $row('Status', $h(ucfirst($o['status'])));
    if ($pay) $orderRows .= $row('Payment', $h(money($pay['amount']) . ' via ' . ucfirst($pay['gateway']) . ' · ' . format_date($pay['created_at'], 'M j, g:i A')))
        . $row('Payment ref', '<code style="font-size:12px">' . $h($pay['transaction_ref']) . '</code>');

    $custRows = $row('Name', $h($name))
        . $row('Email', $o['email'] ? '<a href="mailto:' . $h($o['email']) . '" style="color:#0f6a3c">' . $h($o['email']) . '</a>' : '—')
        . $row('Phone', $o['ship_phone'] ? '<a href="tel:' . $h(phone_href($o['ship_phone'])) . '" style="color:#0f6a3c">' . $h($o['ship_phone']) . '</a>' . ($waUrl ? ' · <a href="' . $h($waUrl) . '" style="color:#0f6a3c">WhatsApp</a>' : '') : '—')
        . $row('Account', $o['customer_id'] ? $h('Registered since ' . format_date($o['customer_since'])) . ' · ' . ($prevOrders ? "$prevOrders previous paid order(s)" : 'first paid order') : 'Guest checkout');

    $shipRows = $pickup ? $row('Delivery', '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#fff1d6;color:#8a5a00;font-size:12px;font-weight:700">PICKUP</span> Customer comes in or sends an Uber/driver with order #' . $h($o['order_number'])) . $row('Phone', $o['ship_phone'] ? $h($o['ship_phone']) : '—') : $row('Address', $hasAddress ? implode('<br>', array_map($h, $addressLines)) . '<br><a href="' . $h($mapUrl) . '" style="color:#0f6a3c;font-weight:500">Open in Google Maps</a>' : '<span style="color:#8a5a00">Not provided yet — the customer adds it on the payment page.</span>')
        . $row('Method', $h($o['shipping_method'] ?: '—'))
        . $row('Parcel', $h("$units item(s) · about " . rtrim(rtrim(number_format($weight, 2), '0'), '.') . ' lb'));

    $itemRows = '';
    foreach ($items as $i) {
        $img = $i['image'] ? '<img src="' . $h(full_url(image_url($i['image']))) . '" width="56" height="56" alt="" style="display:block;width:56px;height:56px;object-fit:contain;border-radius:8px;background:#f6f1e6">' : '';
        $meta = array_filter([$i['sku'] ? 'SKU ' . $i['sku'] : null, $i['origin_country'] ? 'From ' . $i['origin_country'] : null, $i['stock_qty'] !== null ? $i['stock_qty'] . ' left in stock' : null]);
        $low = $i['stock_qty'] !== null && $i['stock_qty'] <= (int) setting('low_stock_threshold', 5);
        $itemRows .= '<tr style="border-bottom:1px solid #eee7da">'
            . '<td style="padding:10px 10px 10px 0;width:56px">' . $img . '</td>'
            . '<td style="padding:10px 0"><div style="font-weight:700;color:#1d1b16">' . $h($i['product_name']) . '</div><div style="font-size:12px;color:' . ($low ? '#b42318' : '#6b6457') . '">' . $h(implode(' · ', $meta)) . '</div></td>'
            . '<td style="padding:10px 8px;text-align:center;font-weight:700;white-space:nowrap">× ' . (int) $i['quantity'] . '</td>'
            . '<td style="padding:10px 8px;text-align:right;color:#6b6457;white-space:nowrap">' . money($i['unit_price']) . '</td>'
            . '<td style="padding:10px 0;text-align:right;font-weight:700;white-space:nowrap">' . money($i['line_total']) . '</td></tr>';
    }
    $tot = fn($k, $v, $strong = false) => '<tr><td style="padding:4px 0;color:' . ($strong ? '#1d1b16;font-size:17px;font-weight:800' : '#6b6457') . '">' . $k . '</td><td style="padding:4px 0;text-align:right;' . ($strong ? 'font-size:17px;font-weight:800' : 'font-weight:600') . '">' . $v . '</td></tr>';
    $totals = $tot('Subtotal', money($o['subtotal']))
        . ($o['discount_total'] > 0 ? $tot('Discount' . ($o['coupon_code'] ? ' (' . $h($o['coupon_code']) . ')' : ''), '−' . money($o['discount_total'])) : '')
        . $tot('Shipping', $o['shipping_total'] > 0 ? money($o['shipping_total']) : 'Free')
        . ($o['tax_total'] > 0 ? $tot('Sales tax', money($o['tax_total'])) : '')
        . '<tr><td colspan="2" style="border-top:2px solid #1d1b16;padding-top:4px"></td></tr>'
        . $tot($paid ? 'Total paid' : 'Total due', money($o['grand_total']), true)
        . ($costKnown ? $tot('Gross profit on items', money($profit)) : '');

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f3efe6;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1d1b16">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3efe6;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e6dfd0">'
        . '<tr><td style="background:#0d4a32;padding:22px 28px;color:#ffffff">'
        . '<table role="presentation" width="100%"><tr><td style="width:64px"><img src="' . $h(full_url(asset('img/logo-badge-200.png'))) . '" width="56" height="56" alt="" style="display:block;border-radius:50%;background:#fffdf6"></td>'
        . '<td><div style="font-size:12px;letter-spacing:.14em;color:#e2a022;font-weight:700">' . $h(strtoupper($store)) . '</div>'
        . '<div style="font-size:22px;font-weight:800;margin-top:4px">' . $h($headline) . '</div></td>'
        . '<td style="text-align:right;white-space:nowrap"><div style="font-size:12px;color:#cfe3d7">' . ($paid ? 'Total paid' : 'Total') . '</div><div style="font-size:24px;font-weight:800">' . money($o['grand_total']) . '</div></td></tr></table></td></tr>'
        . '<tr><td style="height:6px;background:repeating-linear-gradient(90deg,#e2a022 0 28px,#c4532d 28px 40px,#1a8a5a 40px 62px,#1d1b16 62px 68px);background-color:#e2a022"></td></tr>'
        . '<tr><td style="padding:18px 28px 0"><div style="padding:12px 14px;border-radius:10px;background:' . ($paid ? '#e8f6ee;color:#0f5132' : '#fff4dc;color:#7a4b00') . ';line-height:1.5">' . $h($next) . '</div></td></tr>'
        . '<tr><td style="padding:16px 28px 0">' . $btn($adminUrl, $paid ? 'Open order to pack' : 'Open order &amp; send invoice', '#16794f') . ($waUrl ? $btn($waUrl, 'Message on WhatsApp', '#25d366') : '') . '</td></tr>'
        . $section('Order', '<table role="presentation" width="100%">' . $orderRows . '</table>')
        . $section('Customer', '<table role="presentation" width="100%">' . $custRows . '</table>')
        . $section($pickup ? 'Delivery' : 'Ship to', '<table role="presentation" width="100%">' . $shipRows . '</table>')
        . $section('Items (' . $units . ')', '<table role="presentation" width="100%" style="border-collapse:collapse">' . $itemRows . '</table>')
        . $section('Totals', '<table role="presentation" width="100%" style="max-width:320px;margin-left:auto">' . $totals . '</table>')
        . ($o['admin_notes'] ? $section('Notes', '<div style="padding:10px 12px;background:#f6f1e6;border-radius:8px">' . nl2br($h($o['admin_notes'])) . '</div>') : '')
        . '<tr><td style="padding:22px 28px;color:#8a8478;font-size:12px;border-top:1px solid #eee7da;margin-top:12px">Sent to the support emails in Admin → Settings → Store. Reply to this email to contact the customer' . ($o['email'] ? '' : ' (no customer email yet)') . '.</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [$subject, $html, $t];
}

/* ---------------------------------------------------------------------------
 * Invoices / payment links (sent to customers on WhatsApp)
 * ------------------------------------------------------------------------- */
/** Secret used to sign payment links; generated on first use. */
function invoice_secret(): string
{
    static $secret = null;
    $secret ??= (string) setting('invoice_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        save_setting('invoice_secret', $secret);
    }
    return $secret;
}

function invoice_token(array $order): string
{
    return substr(hash_hmac('sha256', $order['id'] . '|' . $order['order_number'], invoice_secret()), 0, 32);
}

/** Public link where the customer can view the invoice and pay by card. */
function invoice_url(array $order): string
{
    return absolute_url('pay.php?o=' . urlencode($order['order_number']) . '&k=' . invoice_token($order));
}

function invoice_whatsapp_text(array $order): string
{
    $items = fetch_all('SELECT product_name, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id', [$order['id']]);
    $first = explode(' ', trim((string) $order['ship_name']))[0];
    $text = 'Hi' . ($first !== '' ? " {$first}" : '') . "! Here's your invoice from " . setting('store_name', APP_NAME) . ".\n\n"
          . "*Invoice #{$order['order_number']}*\n";
    foreach ($items as $i) $text .= "• {$i['quantity']} × {$i['product_name']} — " . money($i['line_total']) . "\n";
    $text .= "\nSubtotal: " . money($order['subtotal']);
    if ($order['discount_total'] > 0) $text .= "\nDiscount: −" . money($order['discount_total']);
    $text .= "\nShipping: " . ($order['shipping_total'] > 0 ? money($order['shipping_total']) : 'Free');
    if ($order['tax_total'] > 0) $text .= "\nTax: " . money($order['tax_total']);
    $text .= "\n*Total: " . money($order['grand_total']) . "*\n\n"
           . "Pay securely online by card here:\n" . invoice_url($order) . "\n\nThank you for shopping with us!";
    return $text;
}

/** wa.me link to the customer's number (or the contact picker if no phone is on file). */
function invoice_whatsapp_url(array $order): string
{
    $digits = $order['ship_phone'] ? ltrim(phone_href($order['ship_phone']), '+') : '';
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode(invoice_whatsapp_text($order));
}

/**
 * Creates an unpaid order from an admin-built invoice.
 * $lines: [[product_id, qty], ...]. Returns [orderId|null, errors[]].
 */
function create_invoice_order(array $cust, array $lines, float $shipping, float $discount, string $notes): array
{
    $qty = [];
    foreach ($lines as [$pid, $q]) if ($pid > 0 && $q > 0) $qty[$pid] = ($qty[$pid] ?? 0) + $q;
    if (!$qty) return [null, ['Add at least one product to the invoice.']];

    $in = implode(',', array_fill(0, count($qty), '?'));
    $products = array_column(fetch_all("SELECT id, name, sku, price, cost_price FROM products WHERE id IN ($in)", array_keys($qty)), null, 'id');
    $items = [];
    foreach ($qty as $pid => $q) {
        if (!isset($products[$pid])) return [null, ['One of the selected products no longer exists.']];
        $p = $products[$pid];
        $items[] = $p + ['qty' => $q, 'line_total' => round($p['price'] * $q, 2)];
    }

    $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
    $discount = round(min(max(0, $discount), $subtotal), 2);
    $shipping = round(max(0, $shipping), 2);
    $tax = round(($subtotal - $discount) * (float) setting('tax_rate', 0) / 100, 2);
    $total = round($subtotal - $discount + $shipping + $tax, 2);
    if ($total < 0.5) return [null, ['The invoice total must be at least $0.50 to pay by card.']];

    $customerId = $cust['email'] !== '' ? fetch_value('SELECT id FROM customers WHERE email = ?', [$cust['email']]) : null;

    $orderId = insert_order([
        'customer_id' => $customerId ?: null, 'email' => $cust['email'],
        'subtotal' => $subtotal, 'discount_total' => $discount, 'shipping_total' => $shipping, 'tax_total' => $tax, 'grand_total' => $total,
        'shipping_method' => WHATSAPP_ORDER_METHOD,
        'ship_name' => $cust['name'], 'ship_line1' => $cust['line1'] ?: null, 'ship_line2' => $cust['line2'] ?: null,
        'ship_city' => $cust['city'] ?: null, 'ship_state' => $cust['state'] ?: null, 'ship_zip' => $cust['zip'] ?: null,
        'ship_phone' => $cust['phone'] ?: null, 'admin_notes' => $notes ?: null,
    ], $items);
    return [$orderId, []];
}

/**
 * Re-prices an unpaid invoice after the admin sets shipping/discount.
 * Returns an error message, or null on success.
 */
function update_invoice_totals(array $order, float $shipping, float $discount): ?string
{
    $subtotal = (float) $order['subtotal'];
    $discount = round(min(max(0, $discount), $subtotal), 2);
    $shipping = round(max(0, $shipping), 2);
    $tax = round(($subtotal - $discount) * (float) setting('tax_rate', 0) / 100, 2);
    $total = round($subtotal - $discount + $shipping + $tax, 2);
    if ($total < 0.5) return 'The invoice total must be at least $0.50 to pay by card.';
    q('UPDATE orders SET discount_total = ?, shipping_total = ?, tax_total = ?, grand_total = ? WHERE id = ?',
      [$discount, $shipping, $tax, $total, $order['id']]);
    return null;
}

/** Inserts an unpaid, pending order plus its items; assigns the order number. Returns the new id. */
function insert_order(array $fields, array $items): int
{
    $fields = ['order_number' => 'TMP-' . bin2hex(random_bytes(6)), 'status' => 'pending', 'payment_status' => 'unpaid'] + $fields;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO orders (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
        $orderId = (int) $pdo->lastInsertId();
        q('UPDATE orders SET order_number = ? WHERE id = ?', [setting('order_prefix', 'JAM') . '-' . (100000 + $orderId), $orderId]);
        insert_order_items($orderId, $items);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $orderId;
}

/** $items use the cart_lines() shape: id, name, sku, price, cost_price, qty, line_total. */
function insert_order_items(int $orderId, array $items): void
{
    foreach ($items as $l) {
        q('INSERT INTO order_items (order_id, product_id, product_name, sku, unit_price, unit_cost, quantity, line_total) VALUES (?,?,?,?,?,?,?,?)',
          [$orderId, $l['id'], $l['name'], $l['sku'], $l['price'], $l['cost_price'], $l['qty'], $l['line_total']]);
    }
}

function absolute_url(string $path): string
{
    return full_url(url($path));
}

/** Makes a URL returned by url()/asset()/image_url() absolute. */
function full_url(string $u): string
{
    if (preg_match('#^https?://#', $u)) return $u;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $u;
}

/* ---------------------------------------------------------------------------
 * SEO: <head> meta, Open Graph, Twitter cards and JSON-LD structured data
 * ------------------------------------------------------------------------- */
/** Plain one-line text from HTML/markdown-ish input. */
function plain_text(?string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8')));
}

/** Cuts text to $max characters on a word boundary, adding an ellipsis. */
function clip_text(string $s, int $max): string
{
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:–-") . '…';
}

/** Search-result description for a product (≤ 160 chars): price, origin, summary, shipping. */
function product_meta_description(array $p): string
{
    $lead = 'Buy ' . $p['name'] . ' online for ' . money($p['price'])
          . ($p['origin_country'] ? ', authentic from ' . $p['origin_country'] : '') . '.';
    $tail = $p['stock_qty'] > 0 ? ' Ships fast across the USA.' : ' Currently sold out — check back soon.';
    $summary = plain_text($p['short_description'] ?: $p['description']);
    $room = 160 - mb_strlen($lead) - mb_strlen($tail) - 1;
    return $lead . ($summary !== '' && $room > 30 ? ' ' . clip_text($summary, $room) : '') . $tail;
}

function json_ld(array $data): string
{
    return '<script type="application/ld+json">'
         . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
         . '</script>';
}

/** The store as a schema.org Organization (logo, contacts, social profiles). */
function organization_schema(): array
{
    $phones = support_phones();
    return array_filter([
        '@type' => 'Organization',
        '@id' => absolute_url('/') . '#organization',
        'name' => setting('store_name', APP_NAME),
        'url' => absolute_url('/'),
        'logo' => full_url(asset('img/logo-badge.png')),
        'email' => support_emails()[0] ?? null,
        'telephone' => $phones ? phone_href($phones[0]) : null,
        'sameAs' => array_column(social_links(), 2) ?: null,
        'contactPoint' => $phones ? array_map(fn($ph) => [
            '@type' => 'ContactPoint', 'telephone' => phone_href($ph), 'contactType' => 'customer service',
            'areaServed' => 'US', 'availableLanguage' => 'English',
        ], $phones) : null,
    ]);
}

/**
 * Builds the SEO block for <head>. $m keys (all optional): title, description, url,
 * image, image_alt, type, robots, og (extra property => content), schema (JSON-LD list).
 */
function seo_head(array $m): string
{
    $store = setting('store_name', APP_NAME);
    $title = $m['title'];
    $desc = clip_text(plain_text($m['description'] ?? ''), 200);
    $image = $m['image'] ?? full_url(asset('img/logo-badge.png'));
    $tags = [
        ['name', 'description', $desc],
        ['name', 'robots', $m['robots'] ?? 'index, follow, max-image-preview:large'],
        ['property', 'og:site_name', $store],
        ['property', 'og:locale', 'en_US'],
        ['property', 'og:type', $m['type'] ?? 'website'],
        ['property', 'og:title', $title],
        ['property', 'og:description', $desc],
        ['property', 'og:image', $image],
        ['property', 'og:image:alt', $m['image_alt'] ?? $title],
        ['name', 'twitter:card', 'summary_large_image'],
        ['name', 'twitter:title', $title],
        ['name', 'twitter:description', $desc],
        ['name', 'twitter:image', $image],
    ];
    if (!empty($m['url'])) $tags[] = ['property', 'og:url', $m['url']];
    foreach ($m['og'] ?? [] as $prop => $content) $tags[] = ['property', $prop, $content];
    foreach (social_links() as [$label, , $link]) {
        if ($label === 'X' && preg_match('#(?:x|twitter)\.com/@?([A-Za-z0-9_]{1,15})#i', $link, $mm)) $tags[] = ['name', 'twitter:site', '@' . $mm[1]];
    }

    $out = '';
    foreach ($tags as [$attr, $key, $content]) {
        if ($content !== null && $content !== '') $out .= "  <meta $attr=\"" . e($key) . '" content="' . e($content) . "\">\n";
    }
    if (!empty($m['url'])) $out .= '  <link rel="canonical" href="' . e($m['url']) . "\">\n";
    if (!empty($m['schema'])) $out .= '  ' . json_ld(['@context' => 'https://schema.org', '@graph' => $m['schema']]) . "\n";
    return $out;
}

/** JSON-LD for a product page: Product (offer, ratings, reviews) + BreadcrumbList. */
function product_schema(array $p, array $images, array $reviews, int $reviewCount, float $avg): array
{
    $url = absolute_url('product.php?slug=' . urlencode($p['slug']));
    $allFree = !fetch_value("SELECT COUNT(*) FROM shipping_methods WHERE is_active = 1 AND rate_type <> 'free'");
    $offer = [
        '@type' => 'Offer',
        'url' => $url,
        'priceCurrency' => 'USD',
        'price' => number_format((float) $p['price'], 2, '.', ''),
        'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
        'availability' => 'https://schema.org/' . ($p['stock_qty'] > 0 ? 'InStock' : 'OutOfStock'),
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller' => ['@id' => absolute_url('/') . '#organization'],
    ];
    if ($allFree) {
        $offer['shippingDetails'] = [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => '0', 'currency' => 'USD'],
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'US'],
        ];
    }
    $product = array_filter([
        '@type' => 'Product',
        '@id' => $url . '#product',
        'name' => $p['name'],
        'url' => $url,
        'image' => $images,
        'description' => clip_text(plain_text($p['description'] ?: $p['short_description']), 5000) ?: null,
        'sku' => $p['sku'] ?: null,
        'category' => $p['category_name'] ?: null,
        'countryOfOrigin' => $p['origin_country'] ? ['@type' => 'Country', 'name' => $p['origin_country']] : null,
        'weight' => (float) $p['weight_lb'] > 0 ? ['@type' => 'QuantitativeValue', 'value' => (float) $p['weight_lb'], 'unitCode' => 'LBR'] : null,
        'offers' => $offer,
        'aggregateRating' => $reviewCount ? ['@type' => 'AggregateRating', 'ratingValue' => round($avg, 1), 'reviewCount' => $reviewCount, 'bestRating' => 5, 'worstRating' => 1] : null,
        'review' => $reviews ? array_map(fn($r) => array_filter([
            '@type' => 'Review',
            'author' => ['@type' => 'Person', 'name' => $r['author'] ?: 'Verified buyer'],
            'datePublished' => date('Y-m-d', strtotime($r['created_at'])),
            'name' => $r['title'] ?: null,
            'reviewBody' => plain_text($r['body']),
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r['rating'], 'bestRating' => 5, 'worstRating' => 1],
        ]), array_slice($reviews, 0, 5)) : null,
    ]);

    $crumbs = [['Home', absolute_url('/')], ['Shop', absolute_url('shop.php')]];
    if ($p['category_name']) $crumbs[] = [$p['category_name'], absolute_url('shop.php?category=' . urlencode($p['category_slug']))];
    $crumbs[] = [$p['name'], $url];
    $breadcrumb = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(
        fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1]], $crumbs, array_keys($crumbs))];

    return [$product, $breadcrumb, organization_schema()];
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
 * Stripe Checkout (REST API over cURL — no SDK required)
 * Test and live modes each have their own keys; "stripe_mode" picks which set
 * is used. In test mode no real money moves — pay with Stripe's test cards.
 * ------------------------------------------------------------------------- */
const STRIPE_TEST_CARDS = [
    '4242 4242 4242 4242' => 'Payment succeeds',
    '4000 0025 0000 3155' => 'Requires 3D Secure authentication',
    '4000 0000 0000 9995' => 'Declined (insufficient funds)',
];

function stripe_mode(): string
{
    return setting('stripe_mode', 'test') === 'live' ? 'live' : 'test';
}

function stripe_test_mode(): bool
{
    return stripe_mode() === 'test';
}

/**
 * Reads a key for the given mode (default: current): publishable_key,
 * secret_key or webhook_secret. Falls back to the older single-set setting
 * (stripe_secret_key etc.) when its prefix belongs to that mode.
 */
function stripe_key(string $name, ?string $mode = null): string
{
    $mode = $mode ?? stripe_mode();
    $value = (string) setting("stripe_{$mode}_{$name}", '');
    if ($value === '' && $name !== 'webhook_secret') {
        $legacy = (string) setting("stripe_{$name}", '');
        if (preg_match('/^(pk|sk|rk)_' . $mode . '_/', $legacy)) $value = $legacy;
    }
    return $value;
}

function stripe_enabled(): bool
{
    return stripe_key('secret_key') !== '' && function_exists('curl_init');
}

function test_payments_enabled(): bool
{
    return APP_ENV === 'development' && !stripe_enabled();
}

function stripe_request(string $method, string $path, array $params = [], ?string $secretKey = null): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => ($secretKey ?? stripe_key('secret_key')) . ':',
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

/** Checks a mode's secret key against the Stripe API. Returns a short status message; throws on failure. */
function stripe_check_connection(string $mode): string
{
    $key = stripe_key('secret_key', $mode);
    if ($key === '') throw new RuntimeException('No ' . $mode . ' secret key saved yet.');
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is not enabled on this server.');
    $acct = stripe_request('GET', 'account', [], $key);
    $name = $acct['settings']['dashboard']['display_name'] ?? $acct['business_profile']['name'] ?? $acct['id'] ?? 'your account';
    return 'Connected to ' . $name . ' in ' . $mode . ' mode.';
}

function stripe_checkout_url(array $order, ?string $cancelUrl = null): string
{
    $items = fetch_all('SELECT product_name, quantity FROM order_items WHERE order_id = ?', [$order['id']]);
    $desc = implode(', ', array_map(fn($i) => $i['quantity'] . ' × ' . $i['product_name'], $items));
    $session = stripe_request('POST', 'checkout/sessions', array_filter([
        'mode' => 'payment',
        'customer_email' => $order['email'] ?: null,
        'client_reference_id' => $order['id'],
        'metadata' => ['order_id' => $order['id'], 'order_number' => $order['order_number']],
        'payment_intent_data' => ['metadata' => ['order_id' => $order['id'], 'order_number' => $order['order_number']]],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => (int) round($order['grand_total'] * 100),
                'product_data' => array_filter([
                    'name' => setting('store_name', APP_NAME) . ' order #' . $order['order_number'],
                    'description' => mb_substr($desc, 0, 450), // Stripe rejects an empty description
                ]),
            ],
        ]],
        'success_url' => absolute_url('order-success.php?order=' . urlencode($order['order_number'])) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $cancelUrl ?? absolute_url('checkout.php?cancelled=1'),
    ], fn($v) => $v !== null));
    q('UPDATE orders SET stripe_session_id = ? WHERE id = ?', [$session['id'], $order['id']]);
    return $session['url'];
}

/** Confirms a Checkout Session against the order and marks it paid. */
function stripe_confirm_session(array $order, string $sessionId): bool
{
    if (!stripe_enabled() || $order['payment_status'] === 'paid' || $sessionId !== (string) $order['stripe_session_id']) return false;
    $s = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
    $expected = (int) round($order['grand_total'] * 100);
    if (($s['payment_status'] ?? '') === 'paid' && (int) ($s['amount_total'] ?? 0) === $expected
        && (string) ($s['metadata']['order_id'] ?? '') === (string) $order['id']) {
        return mark_order_paid((int) $order['id'], 'stripe', (string) ($s['payment_intent'] ?? $sessionId), $expected / 100);
    }
    return false;
}
