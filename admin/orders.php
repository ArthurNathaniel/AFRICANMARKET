<?php
require __DIR__ . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/shop.php';

$orderStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
$paymentStatuses = ['unpaid', 'paid', 'refunded', 'failed'];
$carriers = ['USPS', 'UPS', 'FedEx', 'DHL Express'];

if (is_post()) {
    verify_csrf();
    $id = input_int('id');
    $order = fetch_one('SELECT * FROM orders WHERE id = ?', [$id]);
    if (!$order) { flash('error', 'Order not found.'); redirect('admin/orders.php'); }

    // WhatsApp order → invoice: set customer contact and pricing before sending the payment link.
    if (input('action') === 'invoice') {
        if ($order['payment_status'] !== 'unpaid' || in_array($order['status'], ['cancelled', 'refunded'], true)) {
            flash('error', 'Only unpaid, open orders can be invoiced.');
            redirect("admin/orders.php?id=$id");
        }
        $name = input('ship_name');
        $phone = input('ship_phone');
        $email = strtolower(input('email'));
        if ($phone !== '' && strlen(preg_replace('/\D/', '', $phone)) < 10) {
            flash('error', 'Enter the customer\'s WhatsApp number with the country code (e.g. +1 555 123 4567).');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'That email address doesn\'t look right.');
        } elseif ($err = update_invoice_totals($order, (float) input_decimal('shipping'), (float) input_decimal('discount'))) {
            flash('error', $err);
        } else {
            q('UPDATE orders SET ship_name = ?, ship_phone = ?, email = ? WHERE id = ?', [$name ?: null, $phone ?: null, $email, $id]);
            log_activity('invoice_update', "#{$order['order_number']}");
            flash('success', 'Invoice ready — send it to the customer on WhatsApp.');
        }
        redirect("admin/orders.php?id=$id");
    }

    $status = input('status');
    $payment = input('payment_status');
    if (!in_array($status, $orderStatuses, true) || !in_array($payment, $paymentStatuses, true)) {
        flash('error', 'Invalid status.');
        redirect("admin/orders.php?id=$id");
    }
    $carrier = input('carrier') ?: null;
    $tracking = input('tracking_number') ?: null;
    if ($status === 'shipped' && !$tracking && !is_pickup_order($order)) {
        flash('warning', 'Tip: add a tracking number so the customer can follow their package.');
    }

    db()->beginTransaction();
    // Shipped/delivered flags are worked out here, not compared in SQL (avoids collation mismatches on some hosts).
    $isShipped = (int) in_array($status, ['shipped', 'delivered'], true);
    $isDelivered = (int) ($status === 'delivered');
    q('UPDATE orders SET status = ?, payment_status = ?, carrier = ?, tracking_number = ?, admin_notes = ?,
         shipped_at = CASE WHEN ? = 1 AND shipped_at IS NULL THEN NOW() ELSE shipped_at END,
         delivered_at = CASE WHEN ? = 1 AND delivered_at IS NULL THEN NOW() ELSE delivered_at END
       WHERE id = ?', [$status, $payment, $carrier, $tracking, input('admin_notes') ?: null, $isShipped, $isDelivered, $id]);
    // Put stock back when an order is cancelled/refunded for the first time (only if stock was taken, i.e. it was paid).
    $wasOpen = !in_array($order['status'], ['cancelled', 'refunded'], true);
    if ($wasOpen && $order['payment_status'] === 'paid' && in_array($status, ['cancelled', 'refunded'], true) && isset($_POST['restock'])) {
        q('UPDATE products p JOIN order_items oi ON oi.product_id = p.id SET p.stock_qty = p.stock_qty + oi.quantity WHERE oi.order_id = ?', [$id]);
    }
    db()->commit();

    $changes = [];
    if ($status !== $order['status']) $changes[] = "status {$order['status']} → $status";
    if ($payment !== $order['payment_status']) $changes[] = "payment {$order['payment_status']} → $payment";
    if ($tracking !== $order['tracking_number']) $changes[] = 'tracking updated';
    log_activity('order_update', "#{$order['order_number']}" . ($changes ? ': ' . implode(', ', $changes) : ''));

    if (isset($_POST['notify']) && ($status !== $order['status'] || $tracking !== $order['tracking_number'])) {
        $store = setting('store_name', APP_NAME);
        $messages = [
            'processing' => 'We\'re preparing your order.',
            'shipped'    => 'Good news — your order is on its way!',
            'delivered'  => 'Your order has been delivered. We hope you love it!',
            'cancelled'  => 'Your order has been cancelled.',
            'refunded'   => 'Your order has been refunded. The money should appear in 5–10 business days.',
        ];
        if (is_pickup_order($order)) {
            $messages['shipped'] = "Good news — your order is READY FOR PICKUP!\n\n" . pickup_instructions($order['order_number']);
            $messages['delivered'] = 'Your order has been picked up. Thank you, we hope you love it!';
        }
        $subjects = is_pickup_order($order) ? ['shipped' => 'Ready for pickup', 'delivered' => 'Picked up'] : [];
        $body = "Hi {$order['ship_name']},\n\n" . ($messages[$status] ?? "Your order status is now: $status.") . "\n\nOrder #{$order['order_number']}\n";
        if ($tracking) {
            $body .= "Carrier: " . ($carrier ?: '—') . "\nTracking number: $tracking\n";
            if ($link = tracking_link($carrier, $tracking)) $body .= "Track it: $link\n";
        }
        $body .= "\nOrder details: " . absolute_url('order-tracking.php?order=' . urlencode($order['order_number']) . '&email=' . urlencode($order['email'])) . "\n\nThank you for shopping with us!\n$store";
        send_mail($order['email'], "Order #{$order['order_number']}: " . ($subjects[$status] ?? ucfirst($status)), $body);
    }
    flash('success', 'Order updated' . (isset($_POST['notify']) && $changes ? ' and customer notified.' : '.'));
    redirect("admin/orders.php?id=$id");
}

$pageTitle = 'Orders';
$activeNav = 'orders';
$crumb = 'Sales';

/* =========================================================================
 * Order detail
 * ======================================================================= */
$id = input_int('id');
if ($id) {
    $o = fetch_one('SELECT o.*, c.first_name, c.last_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?', [$id]);
    if (!$o) { flash('error', 'Order not found.'); redirect('admin/orders.php'); }
    $items = fetch_all('SELECT oi.*, p.image, p.stock_qty FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id', [$id]);
    $payments = fetch_all('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at', [$id]);
    $costKnown = !in_array(null, array_column($items, 'unit_cost'), true);
    $cost = array_sum(array_map(fn($i) => (float) $i['unit_cost'] * $i['quantity'], $items));
    $previousOrders = $o['customer_id'] ? (int) fetch_value('SELECT COUNT(*) FROM orders WHERE customer_id = ? AND id <> ? AND payment_status = \'paid\'', [$o['customer_id'], $id]) : 0;

    $pageTitle = 'Order #' . $o['order_number'];
    require __DIR__ . '/includes/header.php'; ?>
    <div class="page-head">
      <div>
        <div><?= status_badge($o['status']) ?> <?= status_badge($o['payment_status']) ?></div>
        <p><?= is_whatsapp_order($o) ? '<i class="bi bi-whatsapp"></i> WhatsApp order · ' : '' ?>Placed <?= format_date($o['created_at'], 'M j, Y \a\t g:i A') ?> · <?= array_sum(array_column($items, 'quantity')) ?> item(s)
          <?php if ($o['shipped_at']): ?> · shipped <?= format_date($o['shipped_at']) ?><?php endif; ?>
          <?php if ($o['delivered_at']): ?> · delivered <?= format_date($o['delivered_at']) ?><?php endif; ?></p>
      </div>
      <div class="form-actions">
        <a class="btn" href="<?= url('admin/orders.php') ?>"><i class="bi bi-arrow-left"></i>All orders</a>
        <button class="btn" onclick="window.print()"><i class="bi bi-printer"></i>Packing slip</button>
      </div>
    </div>

    <div class="grid grid-main">
      <div class="grid" style="align-content:start">
        <div class="card">
          <div class="card-head"><h2>Items</h2></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Product</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><div class="cell-main"><img class="thumb" src="<?= e(image_url($it['image'])) ?>" alt="">
                  <div><?php if ($it['product_id']): ?><a class="title" href="<?= url('admin/product-edit.php?id=' . $it['product_id']) ?>"><?= e($it['product_name']) ?></a><?php else: ?><span class="title"><?= e($it['product_name']) ?></span><?php endif; ?>
                  <div class="meta"><?= $it['stock_qty'] !== null ? (int) $it['stock_qty'] . ' left in stock' : '' ?></div></div></div></td>
                <td class="num"><?= money($it['unit_price']) ?></td>
                <td class="num"><?= (int) $it['quantity'] ?></td>
                <td class="num strong"><?= money($it['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="card-body">
            <div class="totals">
              <div><span class="muted">Subtotal</span><span><?= money($o['subtotal']) ?></span></div>
              <?php if ($o['discount_total'] > 0): ?><div><span class="muted">Discount<?= $o['coupon_code'] ? ' (' . e($o['coupon_code']) . ')' : '' ?></span><span>−<?= money($o['discount_total']) ?></span></div><?php endif; ?>
              <div><span class="muted">Shipping<?= $o['shipping_method'] ? ' · ' . e($o['shipping_method']) : '' ?></span><span><?= money($o['shipping_total']) ?></span></div>
              <div><span class="muted">Sales tax</span><span><?= money($o['tax_total']) ?></span></div>
              <div class="grand"><span>Total</span><span><?= money($o['grand_total']) ?></span></div>
              <?php if ($costKnown && $items): ?><div class="small"><span class="muted">Gross profit on items</span><span class="strong"><?= money($o['subtotal'] - $o['discount_total'] - $cost) ?></span></div><?php endif; ?>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><h2>Payments</h2><a class="btn btn-sm" href="<?= url('admin/payments.php?q=' . urlencode($o['order_number'])) ?>">View in payments</a></div>
          <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Gateway</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
            <?php foreach ($payments as $p): ?>
              <tr><td><?= format_date($p['created_at'], 'M j, Y g:i A') ?></td><td><?= e(ucfirst($p['gateway'])) ?></td><td class="small"><code><?= e($p['transaction_ref'] ?: '—') ?></code></td><td><?= status_badge($p['status']) ?></td><td class="num"><?= money($p['amount']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="5" class="empty">No payment records — this order has not been paid.</td></tr><?php endif; ?>
          </tbody></table></div>
        </div>
      </div>

      <div class="grid" style="align-content:start">
        <?php if ($o['payment_status'] === 'unpaid' && !in_array($o['status'], ['cancelled', 'refunded'], true)): $payLink = invoice_url($o); ?>
          <div class="card">
            <div class="card-head"><h2><i class="bi bi-whatsapp"></i> <?= is_whatsapp_order($o) ? 'WhatsApp invoice' : 'Payment link' ?></h2></div>
            <?php if (is_whatsapp_order($o)): ?>
              <form method="post" class="card-body" style="border-bottom:1px solid var(--border)">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="invoice">
                <p class="small muted mt-0"><strong>Step 1.</strong> Add the customer's details from the chat and any shipping fee or discount.</p>
                <div class="field"><label for="inv_name">Customer name</label><input class="input" id="inv_name" name="ship_name" value="<?= e($o['ship_name']) ?>"></div>
                <div class="field"><label for="inv_phone">WhatsApp number</label><input class="input" type="tel" id="inv_phone" name="ship_phone" value="<?= e($o['ship_phone']) ?>" placeholder="+1 555 123 4567"></div>
                <div class="field"><label for="inv_email">Email <span class="muted">(optional)</span></label><input class="input" type="email" id="inv_email" name="email" value="<?= e($o['email']) ?>"></div>
                <div class="form-row">
                  <div class="field"><label for="inv_ship">Shipping fee</label><div class="input-group"><span class="addon">$</span><input class="input" id="inv_ship" name="shipping" inputmode="decimal" value="<?= e(number_format((float) $o['shipping_total'], 2, '.', '')) ?>"></div></div>
                  <div class="field"><label for="inv_disc">Discount</label><div class="input-group"><span class="addon">$</span><input class="input" id="inv_disc" name="discount" inputmode="decimal" value="<?= e(number_format((float) $o['discount_total'], 2, '.', '')) ?>"></div></div>
                </div>
                <button class="btn btn-block" type="submit"><i class="bi bi-receipt"></i>Save &amp; update invoice</button>
              </form>
            <?php endif; ?>
            <div class="card-body">
              <p class="small muted mt-0"><?= is_whatsapp_order($o) ? '<strong>Step 2.</strong> ' : '' ?>Send the invoice on WhatsApp. The customer opens the link, <?= $o['email'] === '' || !$o['ship_line1'] ? 'adds their missing details, ' : '' ?>and pays <strong><?= money($o['grand_total']) ?></strong> by card on Stripe. The order is marked paid automatically.</p>
              <a class="btn btn-primary btn-block" href="<?= e(invoice_whatsapp_url($o)) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i>Send invoice on WhatsApp</a>
              <?php if (!$o['ship_phone']): ?><p class="small muted" style="margin:8px 0 0">No phone on file — WhatsApp will ask you to pick the contact.</p><?php endif; ?>
              <div class="input-group" style="margin-top:12px">
                <input class="input" id="payLink" value="<?= e($payLink) ?>" readonly aria-label="Payment link" onclick="this.select()">
                <button class="btn" type="button" id="copyPayLink"><i class="bi bi-clipboard"></i>Copy</button>
              </div>
            </div>
          </div>
        <?php endif; ?>
        <form class="card" method="post">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <div class="card-head"><h2>Fulfilment</h2></div>
          <div class="card-body">
            <?php if ($o['payment_status'] !== 'paid' && !in_array($o['status'], ['cancelled', 'refunded'], true)): ?>
              <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i><div>Not paid yet — don't ship until payment is received.</div></div>
            <?php endif; ?>
            <div class="form-row">
              <div class="field"><label for="status">Order status</label>
                <?php $statusLabel = is_pickup_order($o) ? ['shipped' => 'Ready for pickup', 'delivered' => 'Picked up'] : []; ?>
                <select id="status" name="status"><?php foreach ($orderStatuses as $s): ?><option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= $statusLabel[$s] ?? ucfirst($s) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="payment_status">Payment</label>
                <select id="payment_status" name="payment_status"><?php foreach ($paymentStatuses as $s): ?><option value="<?= $s ?>" <?= $o['payment_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
            </div>
            <?php if (is_pickup_order($o)): ?>
              <div class="alert alert-info small"><i class="bi bi-shop"></i><div><strong>Pickup order.</strong> When it's packed, set <strong>Ready for pickup</strong> (the customer is emailed) and text <?= e($o['ship_phone'] ?: 'them') ?>. Hand it over to the customer or their Uber/driver against order <strong>#<?= e($o['order_number']) ?></strong>, then set <strong>Picked up</strong>.</div></div>
              <input type="hidden" name="carrier" value="<?= e($o['carrier']) ?>"><input type="hidden" name="tracking_number" value="<?= e($o['tracking_number']) ?>">
            <?php else: ?>
            <div class="form-row">
              <div class="field"><label for="carrier">Carrier</label>
                <input class="input" id="carrier" name="carrier" list="carriers" value="<?= e($o['carrier']) ?>" placeholder="e.g. USPS">
                <datalist id="carriers"><?php foreach ($carriers as $cr): ?><option value="<?= e($cr) ?>"><?php endforeach; ?></datalist></div>
              <div class="field"><label for="tracking_number">Tracking number</label><input class="input" id="tracking_number" name="tracking_number" value="<?= e($o['tracking_number']) ?>"></div>
            </div>
            <?php if ($link = tracking_link($o['carrier'], $o['tracking_number'])): ?><p class="small" style="margin:-6px 0 12px"><a href="<?= e($link) ?>" target="_blank" rel="noopener">Open carrier tracking <i class="bi bi-box-arrow-up-right"></i></a></p><?php endif; ?>
            <?php endif; ?>
            <div class="field"><label for="admin_notes">Internal notes</label><textarea class="input" id="admin_notes" name="admin_notes" rows="3" placeholder="Only visible to admins"><?= e($o['admin_notes']) ?></textarea></div>
            <label class="check mb"><input type="checkbox" name="notify" value="1" checked> Email the customer about status or tracking changes</label>
            <?php if (!in_array($o['status'], ['cancelled', 'refunded'], true) && $o['payment_status'] === 'paid'): ?>
              <label class="check"><input type="checkbox" name="restock" value="1" checked> Return items to stock if cancelled/refunded</label>
            <?php endif; ?>
          </div>
          <div class="card-foot form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i>Update order</button></div>
        </form>

        <div class="card">
          <div class="card-head"><h2>Customer</h2></div>
          <div class="card-body"><dl class="dl">
            <dt>Name</dt><dd><?php if ($o['customer_id']): ?><a href="<?= url('admin/customers.php?view=' . $o['customer_id']) ?>"><?= e($o['first_name'] . ' ' . $o['last_name']) ?></a> <span class="small muted"><?= $previousOrders ? "· $previousOrders previous order(s)" : '· first order' ?></span><?php else: ?><?= e($o['ship_name']) ?> <span class="muted">(guest)</span><?php endif; ?></dd>
            <dt>Email</dt><dd><?php if ($o['email'] !== ''): ?><a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a><?php else: ?>—<?php endif; ?></dd>
            <dt>Phone</dt><dd><?= e($o['ship_phone'] ?: '—') ?></dd>
            <dt><?= is_pickup_order($o) ? 'Delivery' : 'Ship to' ?></dt><dd><?php if (is_pickup_order($o)): ?><span class="badge badge-warning"><i class="bi bi-shop"></i> Pickup</span><br><span class="small">Customer comes in or sends an Uber/driver with order #<?= e($o['order_number']) ?></span><?php elseif ($o['ship_line1']): ?><?= e($o['ship_name']) ?><br><?= e($o['ship_line1']) ?><br><?php if ($o['ship_line2']): ?><?= e($o['ship_line2']) ?><br><?php endif; ?><?= e($o['ship_city']) ?>, <?= e($o['ship_state']) ?> <?= e($o['ship_zip']) ?><br>United States<?php else: ?><span class="muted">Not provided yet — the customer adds it when paying.</span><?php endif; ?></dd>
          </dl></div>
        </div>
      </div>
    </div>
    <?php
    $pageScripts = '<script>
    (function () {
      var b = document.getElementById("copyPayLink");
      if (!b) return;
      b.addEventListener("click", function () {
        var f = document.getElementById("payLink");
        f.select();
        (navigator.clipboard ? navigator.clipboard.writeText(f.value) : Promise.reject()).catch(function () { document.execCommand("copy"); });
        b.innerHTML = "<i class=\"bi bi-check2\"></i>Copied";
        setTimeout(function () { b.innerHTML = "<i class=\"bi bi-clipboard\"></i>Copy"; }, 2000);
      });
    })();
    </script>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* =========================================================================
 * Order list
 * ======================================================================= */
$status  = input('status');
$payment = input('payment');
$source  = input('source');
$search  = input('q');
$from    = input('from');
$to      = input('to');

$where = ['1=1']; $params = [];
if ($status !== '')  { $where[] = 'o.status = ?'; $params[] = $status; }
if ($payment !== '') { $where[] = 'o.payment_status = ?'; $params[] = $payment; }
if ($source === 'whatsapp') { $where[] = 'o.shipping_method = ?'; $params[] = WHATSAPP_ORDER_METHOD; }
if ($source === 'website') { $where[] = '(o.shipping_method IS NULL OR o.shipping_method <> ?)'; $params[] = WHATSAPP_ORDER_METHOD; }
if ($search !== '')  { $where[] = '(o.order_number LIKE ? OR o.email LIKE ? OR o.ship_name LIKE ? OR o.tracking_number LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'o.created_at >= ?'; $params[] = "$from 00:00:00"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'o.created_at <= ?'; $params[] = "$to 23:59:59"; }
$whereSql = implode(' AND ', $where);

$pg = paginate((int) fetch_value("SELECT COUNT(*) FROM orders o WHERE $whereSql", $params), 25);
$orders = fetch_all(
    "SELECT o.*,
        (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id) items,
        (SELECT GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) products
     FROM orders o WHERE $whereSql ORDER BY o.created_at DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}", $params);
$statusCounts = array_column(fetch_all('SELECT status, COUNT(*) n FROM orders GROUP BY status'), 'n', 'status');

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p class="mt-0">Website orders and WhatsApp invoices.</p></div>
  <a class="btn btn-primary" href="<?= url('admin/invoice.php') ?>"><i class="bi bi-receipt"></i>New invoice</a>
</div>
<div class="card">
  <div class="tabs">
    <a href="<?= e(query_with(['status' => null, 'page' => null])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All<span class="count"><?= array_sum($statusCounts) ?></span></a>
    <?php foreach ($orderStatuses as $s): ?>
      <a href="<?= e(query_with(['status' => $s, 'page' => null])) ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= $s === 'processing' ? 'To ship' : ucfirst($s) ?><span class="count"><?= (int) ($statusCounts[$s] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="filters" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($search) ?>" placeholder="Order #, email, name or tracking…"></div>
    <select name="payment"><option value="">Any payment</option><?php foreach ($paymentStatuses as $s): ?><option value="<?= $s ?>" <?= $payment === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
    <select name="source"><option value="">Any source</option><option value="website" <?= $source === 'website' ? 'selected' : '' ?>>Website</option><option value="whatsapp" <?= $source === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option></select>
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
    <button class="btn" type="submit">Filter</button>
    <?php if ($search || $payment || $source || $from || $to): ?><a class="btn btn-ghost" href="<?= url('admin/orders.php' . ($status ? '?status=' . urlencode($status) : '')) ?>">Clear</a><?php endif; ?>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Status</th><th>Payment</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a class="strong" href="?id=<?= $o['id'] ?>">#<?= e($o['order_number']) ?></a><?php if (is_whatsapp_order($o)): ?> <span class="badge badge-success" title="WhatsApp order"><i class="bi bi-whatsapp"></i> WhatsApp</span><?php endif; ?><?php if (is_pickup_order($o)): ?> <span class="badge badge-warning" title="Pickup order"><i class="bi bi-shop"></i> Pickup</span><?php endif; ?><div class="small muted"><?= format_date($o['created_at'], 'M j, g:i A') ?></div></td>
          <td><?= $o['ship_name'] ? e($o['ship_name']) : '<span class="muted">Name not added yet</span>' ?><div class="small muted"><?= $o['ship_city'] ? e($o['ship_city']) . ', ' . e($o['ship_state']) : e($o['ship_phone'] ?? '') ?></div></td>
          <td class="small text-2" style="max-width:260px"><?= (int) $o['items'] ?> × <?= e(mb_strimwidth((string) $o['products'], 0, 70, '…')) ?></td>
          <td><?= status_badge($o['status']) ?><?php if ($o['tracking_number']): ?><div class="small muted"><i class="bi bi-truck"></i> <?= e($o['carrier']) ?></div><?php endif; ?></td>
          <td><?= status_badge($o['payment_status']) ?></td>
          <td class="num strong"><?= money($o['grand_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="6" class="empty"><i class="bi bi-bag"></i>No orders match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
