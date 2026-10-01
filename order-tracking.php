<?php
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/order-status.php';

$order = null;
$error = null;
$number = strtoupper(ltrim(input('order'), '#'));
$email = strtolower(input('email'));

if ($number !== '' && $email !== '') {
    // Throttle lookups so order numbers can't be guessed against an email.
    $key = 'track:' . client_ip();
    if (too_many_attempts($key)) {
        $error = 'Too many lookups. Please wait a few minutes and try again.';
    } else {
        $order = fetch_one('SELECT * FROM orders WHERE order_number = ? AND email = ?', [$number, $email]);
        if (!$order) {
            q('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())', [$key, client_ip()]);
            $error = 'We couldn\'t find an order with that number and email. Check your confirmation email and try again.';
        }
    }
}

$pageTitle = 'Track your order';
require __DIR__ . '/partials/header.php';
?>
<div class="wrap" style="max-width:860px">
  <div class="page-title"><h1>Track your order</h1><p>Enter the order number from your confirmation email.</p></div>

  <form method="get" class="panel panel-body mt">
    <?php if ($error): ?><div class="alert alert-error"><i class="bi bi-exclamation-circle"></i><div><?= e($error) ?></div></div><?php endif; ?>
    <div class="form-row">
      <div class="field"><label for="order">Order number</label><input class="input" id="order" name="order" value="<?= e($number) ?>" placeholder="JAM-100123" required></div>
      <div class="field"><label for="email">Email used at checkout</label><input class="input" type="email" id="email" name="email" value="<?= e($email ?: (current_customer()['email'] ?? '')) ?>" required></div>
    </div>
    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Find my order</button>
    <?php if (current_customer()): ?><a class="btn btn-ghost" href="<?= url('account/orders.php') ?>">See all my orders</a><?php endif; ?>
  </form>

  <?php if ($order): ?>
    <div class="panel panel-body mt">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div><h2 style="font-size:20px">Order #<?= e($order['order_number']) ?></h2><div class="small muted">Placed <?= format_date($order['created_at'], 'F j, Y') ?> · shipping to <?= e($order['ship_city']) ?>, <?= e($order['ship_state']) ?></div></div>
        <div><?= status_badge($order['payment_status']) ?></div>
      </div>
      <?php render_order_progress($order); ?>
    </div>
    <div class="panel panel-body mt mb">
      <h3 style="font-size:17px">Items</h3>
      <?php render_shipments((int) $order['id']); ?>
      <div style="max-width:340px;margin:16px 0 0 auto"><?php render_order_totals($order); ?></div>
    </div>
  <?php endif; ?>
  <div style="height:40px"></div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
