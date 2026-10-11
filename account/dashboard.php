<?php
require dirname(__DIR__) . '/partials/bootstrap.php';
require dirname(__DIR__) . '/partials/product-card.php';
$customer = require_customer();

$stats = fetch_one("SELECT COUNT(*) orders, COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN grand_total END), 0) spent,
                           SUM(status IN ('processing','shipped') AND payment_status = 'paid') active
                    FROM orders WHERE customer_id = ?", [$customer['id']]);
$recent = fetch_all('SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) items FROM orders o
                     WHERE o.customer_id = ? ORDER BY o.created_at DESC LIMIT 5', [$customer['id']]);
$wish = fetch_all(product_card_sql() . ' JOIN wishlists w ON w.product_id = p.id WHERE w.customer_id = ? AND ' . LIVE_PRODUCT_SQL . ' ORDER BY w.created_at DESC LIMIT 3', [$customer['id']]);
$address = fetch_one('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id LIMIT 1', [$customer['id']]);

$pageTitle = 'My account';
$accountTab = 'dashboard';
require dirname(__DIR__) . '/partials/header.php';
?>
<div class="wrap account-layout">
  <?php require dirname(__DIR__) . '/partials/account-nav.php'; ?>
  <div class="stack">
    <div><h1 style="font-size:30px">Hello, <?= e($customer['first_name']) ?></h1><p class="text-2" style="margin:4px 0 0">Here's what's happening with your orders.</p></div>
    <div class="stat-cards">
      <div class="stat-card"><span>Orders placed</span><strong><?= (int) $stats['orders'] ?></strong></div>
      <div class="stat-card"><span>On the way</span><strong><?= (int) $stats['active'] ?></strong></div>
      <div class="stat-card"><span>Total spent</span><strong><?= money($stats['spent']) ?></strong></div>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>Recent orders</h2><a class="small" href="<?= url('account/orders.php') ?>">View all</a></div>
      <?php if ($recent): ?>
        <div class="order-rows">
          <?php foreach ($recent as $o): ?>
            <a class="order-row" href="<?= url('account/orders.php?id=' . $o['id']) ?>">
              <div class="grow">
                <strong>#<?= e($o['order_number']) ?></strong>
                <span class="small muted"><?= format_date($o['created_at']) ?> · <?= (int) $o['items'] ?> item<?= (int) $o['items'] === 1 ? '' : 's' ?><?= is_pickup_order($o) ? ' · Pickup' : '' ?></span>
              </div>
              <?= status_badge($o['payment_status'] === 'paid' || in_array($o['status'], ['cancelled', 'refunded'], true) ? $o['status'] : 'unpaid') ?>
              <strong class="amt"><?= money($o['grand_total']) ?></strong>
              <i class="bi bi-chevron-right muted"></i>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="panel-body text-2">No orders yet. <a href="<?= url('shop.php') ?>">Start shopping</a></div>
      <?php endif; ?>
    </div>
    <div class="account-split">
      <div class="panel">
        <div class="panel-head"><h2>From your wishlist</h2><a class="small" href="<?= url('account/wishlist.php') ?>">View all</a></div>
        <div class="panel-body">
          <?php if ($wish): ?><div class="grid-products three"><?php foreach ($wish as $p) render_product_card($p); ?></div>
          <?php else: ?><p class="text-2" style="margin:0">Tap the <i class="bi bi-heart"></i> on any product to save it here.</p><?php endif; ?>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h2>Default address</h2></div>
        <div class="panel-body small">
          <?php if ($address): ?>
            <?= e($address['full_name']) ?><br><?= e($address['line1']) ?><br><?php if ($address['line2']): ?><?= e($address['line2']) ?><br><?php endif; ?><?= e($address['city']) ?>, <?= e($address['state']) ?> <?= e($address['zip']) ?>
          <?php else: ?><span class="text-2">No saved address yet.</span><?php endif; ?>
          <div style="margin-top:12px"><a class="btn btn-sm" href="<?= url('account/addresses.php') ?>">Manage addresses</a></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
