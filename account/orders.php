<?php
require dirname(__DIR__) . '/partials/bootstrap.php';
require dirname(__DIR__) . '/partials/order-status.php';
$customer = require_customer();

$accountTab = 'orders';
$id = input_int('id');

if ($id) {
    $o = fetch_one('SELECT * FROM orders WHERE id = ? AND customer_id = ?', [$id, $customer['id']]);
    if (!$o) { flash('error', 'Order not found.'); redirect('account/orders.php'); }
    $payment = fetch_one("SELECT gateway, created_at FROM payments WHERE order_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$id]);
    $pageTitle = 'Order #' . $o['order_number'];
    require dirname(__DIR__) . '/partials/header.php'; ?>
    <div class="wrap account-layout">
      <?php require dirname(__DIR__) . '/partials/account-nav.php'; ?>
      <div style="display:grid;gap:20px">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-end">
          <div><a class="small" href="<?= url('account/orders.php') ?>"><i class="bi bi-arrow-left"></i> All orders</a>
            <h1 style="font-size:28px;margin-top:6px">Order #<?= e($o['order_number']) ?></h1>
            <div class="small muted">Placed <?= format_date($o['created_at'], 'F j, Y \a\t g:i A') ?></div></div>
          <button class="btn btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print receipt</button>
        </div>
        <?php if ($o['payment_status'] === 'unpaid' && $o['status'] === 'pending'): ?>
          <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i><div>This order hasn't been paid, so it won't ship. If you meant to buy these items, add them to your cart again and check out.</div></div>
        <?php endif; ?>
        <div class="panel panel-body"><?php render_order_progress($o); ?></div>
        <div class="cart-layout" style="padding:0;grid-template-columns:minmax(0,1fr) 300px">
          <div class="panel panel-body"><h2 style="font-size:18px">Items</h2><?php render_shipments($id); ?></div>
          <div style="display:grid;gap:16px;align-content:start">
            <div class="panel panel-body"><?php render_order_totals($o); ?>
              <?php if ($payment): ?><p class="small muted" style="margin:10px 0 0">Paid <?= format_date($payment['created_at']) ?> via <?= e($payment['gateway'] === 'manual' ? 'test payment' : ucfirst($payment['gateway'])) ?></p><?php endif; ?></div>
            <div class="panel panel-body small"><strong>Shipping to</strong><div class="text-2" style="margin-top:4px"><?= e($o['ship_name']) ?><br><?= e($o['ship_line1']) ?><br><?php if ($o['ship_line2']): ?><?= e($o['ship_line2']) ?><br><?php endif; ?><?= e($o['ship_city']) ?>, <?= e($o['ship_state']) ?> <?= e($o['ship_zip']) ?></div></div>
            <div class="panel panel-body small"><strong>Need help?</strong><p class="text-2" style="margin:4px 0 0">Email <a href="mailto:<?= e(setting('support_email', MAIL_FROM)) ?>"><?= e(setting('support_email', MAIL_FROM)) ?></a> with your order number.</p></div>
          </div>
        </div>
      </div>
    </div>
    <?php require dirname(__DIR__) . '/partials/footer.php';
    exit;
}

$pg = paginate((int) fetch_value('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$customer['id']]), 10);
$orders = fetch_all(
    "SELECT o.*, (SELECT GROUP_CONCAT(oi.product_name ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) items,
            (SELECT p.image FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id ORDER BY oi.id LIMIT 1) image
     FROM orders o WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}", [$customer['id']]);

$pageTitle = 'My orders';
require dirname(__DIR__) . '/partials/header.php';
?>
<div class="wrap account-layout">
  <?php require dirname(__DIR__) . '/partials/account-nav.php'; ?>
  <div>
    <h1 style="font-size:30px;margin-bottom:18px">My orders</h1>
    <?php if (!$orders): ?>
      <div class="empty-state"><i class="bi bi-bag"></i><h3>No orders yet</h3><p>When you place an order, you'll be able to track it here.</p><a class="btn btn-primary" href="<?= url('shop.php') ?>">Start shopping</a></div>
    <?php else: ?>
      <div class="panel">
        <?php foreach ($orders as $o): ?>
          <a class="cart-item" href="<?= url('account/orders.php?id=' . $o['id']) ?>" style="color:inherit;text-decoration:none">
            <img src="<?= e(image_url($o['image'])) ?>" alt="">
            <div>
              <div class="title">#<?= e($o['order_number']) ?> <span class="muted small" style="font-weight:500">· <?= format_date($o['created_at']) ?></span></div>
              <div class="small text-2" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:460px"><?= e($o['items']) ?></div>
              <div style="margin-top:6px"><?= status_badge($o['payment_status'] === 'paid' || in_array($o['status'], ['cancelled', 'refunded'], true) ? $o['status'] : 'unpaid') ?></div>
            </div>
            <div class="line"><?= money($o['grand_total']) ?> <i class="bi bi-chevron-right small muted"></i></div>
          </a>
        <?php endforeach; ?>
      </div>
      <?= pagination_links($pg) ?>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
