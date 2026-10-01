<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/product-form.php';

$id = input_int('id');
$product = fetch_one('SELECT * FROM products WHERE id = ?', [$id]);
if (!$product) {
    flash('error', 'Product not found.');
    redirect('admin/products.php');
}

$errors = [];
if (is_post()) {
    verify_csrf();
    [$errors] = save_product_from_post($id, $product);
    if (!$errors) {
        log_activity('product_update', input('name') . " (#$id)");
        flash('success', 'Product saved.');
        redirect('admin/product-edit.php?id=' . $id);
    }
}

$gallery = fetch_all('SELECT id, path FROM product_images WHERE product_id = ? ORDER BY sort_order', [$id]);
$stats = fetch_one(
    "SELECT COALESCE(SUM(oi.quantity), 0) units, COALESCE(SUM(oi.line_total), 0) revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE oi.product_id = ? AND o.payment_status = 'paid'", [$id]
);
$rating = fetch_one("SELECT AVG(rating) avg, COUNT(*) n FROM reviews WHERE product_id = ? AND status = 'approved'", [$id]);

$pageTitle = 'Edit product';
$activeNav = 'products';
$crumb = 'Products';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h2 style="font-size:20px;font-weight:800"><?= e($product['name']) ?></h2>
    <p><?= status_badge($product['status']) ?>
      <span class="muted"> · <?= number_format((int) $stats['units']) ?> sold · <?= money($stats['revenue']) ?> revenue
      <?php if ($rating['n']): ?> · <?= number_format((float) $rating['avg'], 1) ?>★ (<?= (int) $rating['n'] ?> reviews)<?php endif; ?>
      · updated <?= time_ago($product['updated_at'] ?? $product['created_at']) ?></span></p>
  </div>
  <div class="form-actions">
  <a class="btn" href="<?= url('product.php?slug=' . urlencode($product['slug'])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i>View in store</a>
  <?php if (admin_can('super_admin', 'admin')): ?>
  <form method="post" action="<?= url('admin/products.php') ?>" data-confirm="Delete this product permanently? Order history is kept.">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-danger" type="submit"><i class="bi bi-trash"></i>Delete</button>
  </form>
  <?php endif; ?>
  </div>
</div>
<?php
render_product_form($product, $gallery, $errors, 'Save changes');
require __DIR__ . '/includes/footer.php';
