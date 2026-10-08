<?php
require __DIR__ . '/partials/bootstrap.php';

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $pid = input_int('product_id');

    // add: increase by qty (default 1). set: exact quantity (0 removes) — used by the product-card stepper.
    if ($action === 'add' || $action === 'set') {
        $ajax = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        $p = fetch_one('SELECT p.id, p.name, p.stock_qty FROM products p WHERE p.id = ? AND ' . LIVE_PRODUCT_SQL, [$pid]);
        if (!$p || $p['stock_qty'] <= 0) {
            $ok = false;
            $qty = 0;
            $msg = 'Sorry, that product is not available right now.';
        } else {
            $want = $action === 'add' ? (cart_raw()[$pid] ?? 0) + max(1, input_int('qty', 1)) : max(0, input_int('qty'));
            $qty = min($want, (int) $p['stock_qty'], 99);
            cart_set($pid, $qty);
            $ok = true;
            $msg = $qty < $want ? "Only {$qty} of “{$p['name']}” available — added the maximum."
                 : ($action === 'add' ? "Added “{$p['name']}” to your cart." : ($qty ? "Updated “{$p['name']}” — {$qty} in your cart." : "Removed “{$p['name']}” from your cart."));
        }
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $ok, 'qty' => $qty, 'max' => $p ? min(99, (int) $p['stock_qty']) : 0, 'count' => cart_count(), 'message' => $msg]);
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        if (input('buy_now') === '1') redirect('checkout.php');
        if ($back = safe_next(input('back'))) {
            header('Location: ' . $back);
            exit;
        }
    } elseif ($action === 'update') {
        cart_set($pid, input_int('qty'));
    } elseif ($action === 'remove') {
        cart_set($pid, 0);
        flash('success', 'Item removed from your cart.');
    } elseif ($action === 'coupon') {
        [$lines] = cart_lines();
        [$coupon, $err] = validate_coupon(input('code'), cart_subtotal($lines));
        if ($coupon) {
            $_SESSION['coupon'] = $coupon['code'];
            flash('success', "Coupon {$coupon['code']} applied.");
        } else {
            flash('error', $err ?? 'Enter a coupon code.');
        }
    } elseif ($action === 'remove_coupon') {
        unset($_SESSION['coupon']);
    }
    redirect('cart.php');
}

[$lines, $notices] = cart_lines();
foreach ($notices as $n) flash('warning', $n);
$subtotal = cart_subtotal($lines);
[$coupon, $couponError] = validate_coupon($_SESSION['coupon'] ?? '', $subtotal);
if ($couponError && isset($_SESSION['coupon'])) {
    flash('warning', 'Coupon ' . $_SESSION['coupon'] . ' was removed: ' . $couponError);
    unset($_SESSION['coupon']);
}
$discount = coupon_discount($coupon, $subtotal);
$freeOver = (float) fetch_value("SELECT MIN(free_over) FROM shipping_methods WHERE is_active = 1 AND free_over IS NOT NULL AND rate_type <> 'free'");
$taxRate = (float) setting('tax_rate', 0);

$pageTitle = 'Your cart';
require __DIR__ . '/partials/header.php';
?>
<div class="wrap">
  <div class="page-title"><h1>Your cart</h1></div>
  <?php if (!$lines): ?>
    <div class="empty-state mt" style="margin-bottom:64px">
      <i class="bi bi-bag"></i>
      <h3>Your cart is empty</h3>
      <p>Discover handmade pieces from across Africa.</p>
      <a class="btn btn-primary" href="<?= url('shop.php') ?>">Start shopping</a>
    </div>
  <?php else: ?>
  <div class="cart-layout">
    <div class="panel">
        <?php foreach ($lines as $l): ?>
          <div class="cart-item">
            <a href="<?= e(product_url($l)) ?>"><img src="<?= e(image_url($l['image'])) ?>" alt=""></a>
            <div>
              <a class="title" href="<?= e(product_url($l)) ?>"><?= e($l['name']) ?></a>
              <div class="small muted"><?= money($l['price']) ?> each<?= $l['stock_qty'] <= low_stock() ? ' · only ' . (int) $l['stock_qty'] . ' left' : '' ?></div>
              <div class="controls">
                <form method="post">
                  <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="<?= (int) $l['id'] ?>">
                  <div class="qty" data-autosubmit><button type="button" data-step="-1" aria-label="Decrease quantity">−</button><input type="number" name="qty" value="<?= (int) $l['qty'] ?>" min="1" max="<?= min(99, (int) $l['stock_qty']) ?>" aria-label="Quantity for <?= e($l['name']) ?>"><button type="button" data-step="1" aria-label="Increase quantity">+</button></div>
                </form>
                <form method="post">
                  <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int) $l['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-trash"></i> Remove</button>
                </form>
              </div>
            </div>
            <div class="line"><?= money($l['line_total']) ?></div>
          </div>
        <?php endforeach; ?>
    </div>

    <aside class="panel">
      <div class="panel-head"><h2>Order summary</h2></div>
      <div class="panel-body">
        <?php if ($freeOver > 0): ?>
          <?php if ($subtotal >= $freeOver): ?>
            <div class="alert alert-success small"><i class="bi bi-truck"></i><div>You qualify for <strong>free standard shipping</strong> in the contiguous US.</div></div>
          <?php else: ?>
            <div class="small text-2" style="margin-bottom:12px">Add <strong><?= money($freeOver - $subtotal) ?></strong> more for free standard shipping.
              <div style="height:6px;background:var(--surface-2);border-radius:99px;margin-top:6px;overflow:hidden"><div style="height:100%;width:<?= min(100, round($subtotal / $freeOver * 100)) ?>%;background:var(--gold-500)"></div></div></div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="summary-row"><span>Subtotal (<?= cart_count() ?> item<?= cart_count() === 1 ? '' : 's' ?>)</span><span><?= money($subtotal) ?></span></div>
        <?php if ($coupon): ?>
          <div class="summary-row discount"><span>Coupon <?= e($coupon['code']) ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove_coupon"><button class="btn btn-ghost btn-sm" style="padding:0 4px" aria-label="Remove coupon"><i class="bi bi-x-circle"></i></button></form></span>
            <span>−<?= money($discount) ?></span></div>
        <?php endif; ?>
        <div class="summary-row"><span>Shipping</span><span class="small">Calculated at checkout</span></div>
        <?php if ($taxRate > 0): ?><div class="summary-row"><span>Estimated tax</span><span><?= money(($subtotal - $discount) * $taxRate / 100) ?></span></div><?php endif; ?>
        <div class="summary-row total"><span>Estimated total</span><span><?= money($subtotal - $discount + ($subtotal - $discount) * $taxRate / 100) ?></span></div>

        <?php if (!$coupon): ?>
          <form method="post" class="coupon-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="coupon">
            <label class="sr-only" for="code">Coupon code</label>
            <input class="input" id="code" name="code" placeholder="Coupon code" autocomplete="off">
            <button class="btn" type="submit">Apply</button>
          </form>
        <?php endif; ?>

        <a class="btn btn-primary btn-lg btn-block" href="<?= url('checkout.php') ?>" style="margin-top:8px">Checkout <i class="bi bi-lock"></i></a>
        <?php if ($wa = whatsapp_order_link()): ?>
          <a class="btn btn-whatsapp btn-block" href="<?= e($wa) ?>" target="_blank" rel="noopener" style="margin-top:8px"><i class="bi bi-whatsapp"></i> Order via WhatsApp</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-block" href="<?= url('shop.php') ?>" style="margin-top:8px">Continue shopping</a>
        <div class="trust">
          <div><i class="bi bi-shield-lock"></i> Secure checkout powered by Stripe</div>
          <div><i class="bi bi-box-seam"></i> Tracked delivery to all 50 states</div>
        </div>
      </div>
    </aside>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
