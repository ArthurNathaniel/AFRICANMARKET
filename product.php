<?php
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/product-card.php';

$p = fetch_one(
    'SELECT p.*, c.name AS category_name, c.slug AS category_slug
     FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.slug = ?', [input('slug')]);

// Active products are public; signed-in admins can preview drafts and hidden products.
$isPreview = $p && $p['status'] !== 'active';
if (!$p || ($isPreview && !current_admin())) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    $metaRobots = 'noindex, follow';
    require __DIR__ . '/partials/header.php'; ?>
    <div class="wrap section"><div class="empty-state"><i class="bi bi-box-seam"></i><h3>This product isn't available</h3><p>It may have sold out or been removed.</p><a class="btn btn-primary" href="<?= url('shop.php') ?>">Keep shopping</a></div></div>
    <?php require __DIR__ . '/partials/footer.php';
    exit;
}

$customer = current_customer();
$purchased = $customer ? (bool) fetch_value(
    "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE oi.product_id = ? AND o.customer_id = ? AND o.payment_status = 'paid' LIMIT 1", [$p['id'], $customer['id']]) : false;
$alreadyReviewed = $customer ? (bool) fetch_value('SELECT 1 FROM reviews WHERE product_id = ? AND customer_id = ?', [$p['id'], $customer['id']]) : false;

// ---- Review submission (verified buyers only; moderated in Admin → Reviews) ----
$reviewErrors = [];
if (is_post() && input('action') === 'review') {
    verify_csrf();
    if (!$customer) redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
    $rating = input_int('rating');
    $title = mb_substr(input('title'), 0, 150);
    $body = mb_substr(input('body'), 0, 3000);
    if (!$purchased) $reviewErrors[] = 'Only customers who bought this item can review it.';
    if ($alreadyReviewed) $reviewErrors[] = "You've already reviewed this product.";
    if ($rating < 1 || $rating > 5) $reviewErrors[] = 'Choose a star rating.';
    if (mb_strlen($body) < 10) $reviewErrors[] = 'Tell other shoppers a little more (at least 10 characters).';
    if (!$reviewErrors) {
        q("INSERT INTO reviews (product_id, customer_id, rating, title, body, status) VALUES (?, ?, ?, ?, ?, 'pending')",
          [$p['id'], $customer['id'], $rating, $title ?: null, $body]);
        flash('success', 'Thanks for your review! It will appear once our team has checked it.');
        redirect('product.php?slug=' . urlencode($p['slug']) . '#reviews');
    }
}

$gallery = array_merge($p['image'] ? [$p['image']] : [], array_column(fetch_all('SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order', [$p['id']]), 'path'));
$reviews = fetch_all(
    "SELECT r.*, CONCAT(c.first_name, ' ', LEFT(c.last_name, 1), '.') AS author FROM reviews r LEFT JOIN customers c ON c.id = r.customer_id
     WHERE r.product_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC LIMIT 30", [$p['id']]);
$dist = array_column(fetch_all("SELECT rating, COUNT(*) n FROM reviews WHERE product_id = ? AND status = 'approved' GROUP BY rating", [$p['id']]), 'n', 'rating');
$reviewCount = array_sum($dist);
$avg = $reviewCount ? array_sum(array_map(fn($r, $n) => $r * $n, array_keys($dist), $dist)) / $reviewCount : 0;
$related = fetch_all(product_card_sql() . ' WHERE ' . LIVE_PRODUCT_SQL . ' AND p.id <> ? AND (p.category_id = ? OR p.origin_country = ?) ORDER BY p.category_id = ? DESC, RAND() LIMIT 4',
    [$p['id'], $p['category_id'], $p['origin_country'], $p['category_id']]);

$options = shipping_options('NY', (float) $p['price'], (float) $p['weight_lb']);
$cheapest = $options ? min(array_column($options, 'cost')) : null;
$fastest = $options ? min(array_column($options, 'min_days')) : null;
$slowest = $options ? max(array_column($options, 'max_days')) : null;
$handling = (int) setting('ship_processing_days', 1);
$wished = in_array((int) $p['id'], wishlist_ids(), true);
$onSale = $p['compare_price'] !== null && $p['compare_price'] > $p['price'];
$inCart = cart_raw()[$p['id']] ?? 0;
$storeName = setting('store_name', APP_NAME);

$pageTitle = $p['name'];
// ---- SEO: title, description, social cards and Product structured data ----
$metaTitle = $p['name'] . ($p['origin_country'] ? ' from ' . $p['origin_country'] : '') . ' – Buy Online in the USA | ' . $storeName;
$metaDescription = product_meta_description($p);
$canonical = absolute_url('product.php?slug=' . urlencode($p['slug']));
$imageUrls = array_map(fn($g) => full_url(image_url($g)), $gallery);
$metaImage = $imageUrls[0] ?? null;
$metaImageAlt = $p['name'];
$metaType = 'product';
$metaRobots = $isPreview ? 'noindex, nofollow' : null;
$metaOg = array_filter([
    'product:price:amount' => number_format((float) $p['price'], 2, '.', ''),
    'product:price:currency' => 'USD',
    'product:availability' => $p['stock_qty'] > 0 ? 'in stock' : 'out of stock',
    'product:condition' => 'new',
    'product:retailer_item_id' => $p['sku'] ?: (string) $p['id'],
    'product:category' => $p['category_name'],
]);
$schema = $isPreview ? [] : product_schema($p, $imageUrls ?: [full_url(asset('img/logo-badge.png'))], $reviews, $reviewCount, $avg);
require __DIR__ . '/partials/header.php';
?>
<div class="wrap">
  <?php if ($isPreview): ?>
    <div class="alert alert-warning" style="margin-top:18px"><i class="bi bi-eye"></i><div><strong>Admin preview:</strong> this product is <?= e($p['status']) ?>, so shoppers can't see it. <a href="<?= url('admin/product-edit.php?id=' . $p['id']) ?>">Edit product</a></div></div>
  <?php endif; ?>
  <div class="crumbs" style="padding-top:20px"><a href="<?= url('/') ?>">Home</a> / <a href="<?= url('shop.php') ?>">Shop</a>
    <?php if ($p['category_name']): ?> / <a href="<?= url('shop.php?category=' . urlencode($p['category_slug'])) ?>"><?= e($p['category_name']) ?></a><?php endif; ?></div>

  <div class="product-layout">
    <div>
      <div class="gallery-main"><img id="mainImage" src="<?= e(image_url($gallery[0] ?? null)) ?>" alt="<?= e($p['name']) ?>"></div>
      <?php if (count($gallery) > 1): ?>
        <div class="gallery-thumbs">
          <?php foreach ($gallery as $i => $g): ?>
            <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-src="<?= e(image_url($g)) ?>" aria-label="Show image <?= $i + 1 ?>"><img src="<?= e(image_url($g)) ?>" alt=""></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="p-info">
      <?php if ($p['category_name']): ?><a class="small strong" href="<?= url('shop.php?category=' . urlencode($p['category_slug'])) ?>"><?= e($p['category_name']) ?></a><?php endif; ?>
      <h1><?= e($p['name']) ?></h1>
      <div class="by">
        <?php if ($reviewCount): ?><a href="#reviews" style="color:inherit"><?= stars_html(round($avg, 1), $reviewCount) ?></a> · <?php endif; ?>
        <?php if ($p['origin_country']): ?><i class="bi bi-geo-alt"></i> Made in <?= e($p['origin_country']) ?><?php endif; ?>
      </div>
      <div class="p-price">
        <strong><?= money($p['price']) ?></strong>
        <?php if ($onSale): ?><s><?= money($p['compare_price']) ?></s><span class="save">Save <?= money($p['compare_price'] - $p['price']) ?></span><?php endif; ?>
      </div>
      <?php if ($p['short_description']): ?><p class="text-2"><?= e($p['short_description']) ?></p><?php endif; ?>

      <?php if ($p['stock_qty'] <= 0): ?>
        <p class="stock out"><i class="bi bi-x-circle"></i> Sold out</p>
      <?php elseif ($p['stock_qty'] <= low_stock()): ?>
        <p class="stock low"><i class="bi bi-hourglass-split"></i> Only <?= (int) $p['stock_qty'] ?> left</p>
      <?php else: ?>
        <p class="stock in"><i class="bi bi-check-circle"></i> In stock — ready to ship</p>
      <?php endif; ?>

      <div class="buy-box">
        <?php if ($p['stock_qty'] > 0 && !$isPreview): ?>
          <form method="post" action="<?= url('cart.php') ?>" style="display:contents" data-quick-add data-fly-from="#mainImage">
            <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
            <div class="qty"><button type="button" data-step="-1" aria-label="Decrease quantity">−</button><input type="number" name="qty" value="1" min="1" max="<?= min(99, (int) $p['stock_qty']) ?>" aria-label="Quantity"><button type="button" data-step="1" aria-label="Increase quantity">+</button></div>
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-bag-plus"></i> Add to cart</button>
          </form>
        <?php endif; ?>
        <form method="post" action="<?= url('account/wishlist.php') ?>">
          <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI']) ?>">
          <button class="btn btn-lg" type="submit" aria-pressed="<?= $wished ? 'true' : 'false' ?>"><i class="bi bi-heart<?= $wished ? '-fill' : '' ?>" style="<?= $wished ? 'color:var(--terra-500)' : '' ?>"></i><?= $wished ? 'Saved' : 'Save' ?></button>
        </form>
      </div>
      <?php if (!$isPreview && ($wa = whatsapp_order_link((int) $p['id']))): ?>
        <a class="btn btn-whatsapp btn-lg btn-block" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Order via WhatsApp</a>
      <?php endif; ?>
      <?php if ($inCart): ?><p class="small muted"><i class="bi bi-bag-check"></i> <?= (int) $inCart ?> already in your <a href="<?= url('cart.php') ?>">cart</a>.</p><?php endif; ?>

      <div class="facts">
        <div><i class="bi bi-truck"></i><div><strong>Delivery<?= $fastest !== null ? ' in about ' . ($fastest + $handling) . '–' . ($slowest + $handling) . ' business days' : '' ?></strong><br>
          <span class="text-2">Ships to all 50 states<?= $cheapest !== null ? ($cheapest > 0 ? ' · from ' . money($cheapest) : ' · free shipping available') : '' ?>. Exact rates at checkout.</span></div></div>
        <div><i class="bi bi-arrow-repeat"></i><div><strong>Easy returns</strong><br><span class="text-2">Contact us within 14 days of delivery if something isn't right.</span></div></div>
        <div><i class="bi bi-shield-check"></i><div><strong>Secure payment</strong><br><span class="text-2">You're charged in USD. Card details never touch our servers.</span></div></div>
      </div>
    </div>
  </div>

  <div class="tabs-block">
    <h2 style="font-size:24px;margin-bottom:12px">About this item</h2>
    <div class="prose"><?= e($p['description'] ?: 'No description provided.') ?></div>
    <p class="small muted" style="margin-top:14px">Shipping weight <?= e($p['weight_lb']) ?> lb<?= $p['origin_country'] ? ' · Made in ' . e($p['origin_country']) : '' ?></p>
  </div>

  <div class="tabs-block mt" id="reviews">
    <h2 style="font-size:24px;margin-bottom:20px">Customer reviews</h2>
    <div class="reviews-layout">
      <div class="rating-summary">
        <?php if ($reviewCount): ?>
          <div class="big"><?= number_format($avg, 1) ?></div>
          <div><?= stars_html(round($avg, 1)) ?> <span class="muted small"><?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?></span></div>
          <?php for ($s = 5; $s >= 1; $s--): $n = (int) ($dist[$s] ?? 0); ?>
            <div class="bar-row"><span><?= $s ?> ★</span><div class="bar"><span style="width:<?= $reviewCount ? round($n / $reviewCount * 100) : 0 ?>%"></span></div><span><?= $n ?></span></div>
          <?php endfor; ?>
        <?php else: ?>
          <p class="text-2">No reviews yet.</p>
        <?php endif; ?>

        <div class="mt">
          <?php if (!$customer): ?>
            <p class="small text-2"><a href="<?= url('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] . '#reviews')) ?>">Sign in</a> to review a product you've bought.</p>
          <?php elseif ($alreadyReviewed): ?>
            <p class="small text-2"><i class="bi bi-check2-circle"></i> Thanks — you've reviewed this product.</p>
          <?php elseif ($purchased): ?>
            <form method="post" class="panel panel-body">
              <?= csrf_field() ?><input type="hidden" name="action" value="review">
              <h3 style="font-size:17px;margin-bottom:10px">Write a review</h3>
              <?php foreach ($reviewErrors as $er): ?><div class="alert alert-error small"><?= e($er) ?></div><?php endforeach; ?>
              <fieldset style="border:0;padding:0;margin:0 0 10px"><legend class="label">Your rating</legend>
                <div class="star-input">
                  <?php for ($s = 5; $s >= 1; $s--): ?><input type="radio" id="star<?= $s ?>" name="rating" value="<?= $s ?>" <?= (int) ($_POST['rating'] ?? 0) === $s ? 'checked' : '' ?>><label for="star<?= $s ?>" title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">★</label><?php endfor; ?>
                </div></fieldset>
              <div class="field"><label for="rtitle">Headline</label><input class="input" id="rtitle" name="title" maxlength="150" value="<?= old('title') ?>"></div>
              <div class="field"><label for="rbody">Your review</label><textarea class="input" id="rbody" name="body" rows="4" required><?= old('body') ?></textarea></div>
              <button class="btn btn-primary btn-block" type="submit">Submit review</button>
            </form>
          <?php else: ?>
            <p class="small text-2">Reviews come from verified buyers only.</p>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php foreach ($reviews as $r): ?>
          <div class="review">
            <?= stars_html((float) $r['rating']) ?>
            <?php if ($r['title']): ?><h4><?= e($r['title']) ?></h4><?php endif; ?>
            <div class="text-2"><?= nl2br(e($r['body'])) ?></div>
            <div class="small muted" style="margin-top:6px"><?= e($r['author'] ?? 'Customer') ?> · <i class="bi bi-patch-check-fill" style="color:var(--green-600)"></i> Verified buyer · <?= format_date($r['created_at']) ?></div>
            <?php if ($r['reply']): ?><div class="reply"><strong><?= e($storeName) ?> replied:</strong> <?= nl2br(e($r['reply'])) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($related): ?>
    <section class="section">
      <div class="section-head"><h2>You may also like</h2></div>
      <div class="grid-products"><?php foreach ($related as $rp) render_product_card($rp); ?></div>
    </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
