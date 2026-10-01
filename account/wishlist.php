<?php
require dirname(__DIR__) . '/partials/bootstrap.php';
require dirname(__DIR__) . '/partials/product-card.php';

// Toggle from any product card / product page
if (is_post()) {
    verify_csrf();
    $back = safe_next(input('back')) ?: url('account/wishlist.php');
    $customer = current_customer();
    if (!$customer) {
        flash('info', 'Sign in to save items to your wishlist.');
        redirect('login.php?next=' . urlencode($back));
    }
    $pid = input_int('product_id');
    if (fetch_value('SELECT 1 FROM wishlists WHERE customer_id = ? AND product_id = ?', [$customer['id'], $pid])) {
        q('DELETE FROM wishlists WHERE customer_id = ? AND product_id = ?', [$customer['id'], $pid]);
        flash('success', 'Removed from your wishlist.');
    } elseif (fetch_value('SELECT id FROM products WHERE id = ?', [$pid])) {
        q('INSERT INTO wishlists (customer_id, product_id) VALUES (?, ?)', [$customer['id'], $pid]);
        flash('success', 'Saved to your wishlist.');
    }
    header('Location: ' . $back);
    exit;
}

$customer = require_customer();
$items = fetch_all(product_card_sql() . ' JOIN wishlists w ON w.product_id = p.id WHERE w.customer_id = ? ORDER BY w.created_at DESC', [$customer['id']]);

$pageTitle = 'Wishlist';
$accountTab = 'wishlist';
require dirname(__DIR__) . '/partials/header.php';
?>
<div class="wrap account-layout">
  <?php require dirname(__DIR__) . '/partials/account-nav.php'; ?>
  <div>
    <h1 style="font-size:30px;margin-bottom:18px">Wishlist <span class="muted" style="font-size:18px">(<?= count($items) ?>)</span></h1>
    <?php if ($items): ?>
      <div class="grid-products three"><?php foreach ($items as $p) render_product_card($p); ?></div>
    <?php else: ?>
      <div class="empty-state"><i class="bi bi-heart"></i><h3>Nothing saved yet</h3><p>Tap the heart on any product to keep it here for later.</p><a class="btn btn-primary" href="<?= url('shop.php') ?>">Browse products</a></div>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
