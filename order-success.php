<?php
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/order-status.php';

$order = fetch_one('SELECT * FROM orders WHERE order_number = ?', [input('order')]);
if (!$order || !can_view_order($order)) {
    flash('error', 'We couldn\'t find that order. You can look it up with your email below.');
    redirect('order-tracking.php');
}

// Returning from Stripe: confirm the payment directly with Stripe (the webhook does the same).
$sessionId = input('session_id');
if ($sessionId !== '' && $order['payment_status'] !== 'paid') {
    try {
        if (stripe_confirm_session($order, $sessionId)) {
            $order = fetch_one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
        }
    } catch (RuntimeException $ex) {
        error_log('Stripe confirm failed: ' . $ex->getMessage());
    }
}
// Only clear the cart for the order checked out from it (not for a paid WhatsApp invoice).
if ($order['payment_status'] === 'paid' && (int) ($_SESSION['pending_order_id'] ?? 0) === (int) $order['id']) {
    cart_clear();
    unset($_SESSION['pending_order_id']);
}
$paid = $order['payment_status'] === 'paid';
$customer = current_customer();
$hasAccount = !$customer && fetch_value('SELECT id FROM customers WHERE email = ?', [$order['email']]);

$pageTitle = 'Order #' . $order['order_number'];
require __DIR__ . '/partials/header.php';
?>
<div class="wrap" style="max-width:860px">
  <div class="success-hero">
    <?php if ($paid): ?>
      <div class="tick"><i class="bi bi-check-lg"></i></div>
      <h1>Thank you, <?= e(explode(' ', $order['ship_name'])[0]) ?>!</h1>
      <p class="text-2">Order <strong>#<?= e($order['order_number']) ?></strong> is confirmed. A receipt is on its way to <strong><?= e($order['email']) ?></strong>.</p>
    <?php else: ?>
      <div class="tick" style="background:var(--gold-100);color:#9a6200"><i class="bi bi-hourglass-split"></i></div>
      <h1>We're confirming your payment</h1>
      <p class="text-2">This usually takes a few seconds. Refresh this page, or check your email for confirmation of order <strong>#<?= e($order['order_number']) ?></strong>.</p>
      <a class="btn" href="<?= e($_SERVER['REQUEST_URI']) ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
    <?php endif; ?>
  </div>

  <div class="panel panel-body mb"><?php render_order_progress($order); ?></div>

  <div class="cart-layout success-layout">
    <div class="panel panel-body">
      <h2 style="font-size:18px">Your items</h2>
      <p class="small text-2" style="margin:4px 0 0"><?= is_pickup_order($order) ? 'We’ll text you as soon as your order is ready for pickup.' : 'We’ll email you tracking details as soon as your order ships.' ?></p>
      <?php render_shipments((int) $order['id']); ?>
    </div>
    <div style="display:grid;gap:16px;align-content:start">
      <div class="panel panel-body"><?php render_order_totals($order); ?></div>
      <div class="panel panel-body small">
        <?php render_delivery_box($order); ?>
      </div>
      <?php if (!$customer && !$hasAccount): ?>
        <div class="panel panel-body">
          <strong>Save your details for next time</strong>
          <p class="small text-2">Create an account to track this order, save addresses and build a wishlist.</p>
          <a class="btn btn-primary btn-block" href="<?= url('register.php?email=' . urlencode($order['email'])) ?>">Create account</a>
        </div>
      <?php endif; ?>
      <a class="btn btn-block" href="<?= url('shop.php') ?>">Continue shopping</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
