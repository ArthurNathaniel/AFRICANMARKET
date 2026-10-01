<?php
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/product-card.php';

$base = product_card_sql() . ' WHERE ' . LIVE_PRODUCT_SQL;
$featured = fetch_all("$base ORDER BY p.is_featured DESC, p.created_at DESC LIMIT 8");
$bestsellers = fetch_all(
    "$base AND p.id IN (SELECT oi.product_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
                        WHERE o.payment_status = 'paid' AND o.created_at >= ?)
     ORDER BY (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.product_id = p.id) DESC LIMIT 4",
    [date('Y-m-d', strtotime('-90 days'))]
);
$newest = fetch_all("$base ORDER BY p.created_at DESC LIMIT 4");
$stats = fetch_one("SELECT COUNT(*) products, COUNT(DISTINCT p.origin_country) countries FROM products p WHERE " . LIVE_PRODUCT_SQL);
$customersServed = (int) fetch_value("SELECT COUNT(DISTINCT email) FROM orders WHERE payment_status = 'paid'");
$cats = array_filter(store_categories(), fn($c) => !$c['parent_id']);
$icons = ['fashion' => 'bi-bag-heart', 'art' => 'bi-palette', 'beauty' => 'bi-flower1', 'jewel' => 'bi-gem', 'food' => 'bi-cup-hot', 'music' => 'bi-music-note-beamed', 'home' => 'bi-house-heart'];
$iconFor = function (string $slug) use ($icons) {
    foreach ($icons as $k => $i) if (strpos($slug, $k) !== false) return $i;
    return 'bi-stars';
};

require __DIR__ . '/partials/header.php';
?>
<section class="hero">
  <div class="wrap">
    <div>
      <span class="eyebrow"><i class="bi bi-truck"></i> Shipping to all 50 states</span>
      <h1>The best of <span>Africa</span>, delivered across America.</h1>
      <p class="lead">Hand-woven kente, raw shea butter, Ethiopian coffee, Maasai beadwork and more — sourced directly from makers across the continent.</p>
      <div class="ctas">
        <a class="btn btn-gold btn-lg" href="<?= url('shop.php') ?>">Shop now <i class="bi bi-arrow-right"></i></a>
        <a class="btn btn-lg btn-outline" href="<?= url('shop.php?sort=newest') ?>">New arrivals</a>
      </div>
      <div class="hero-stats">
        <div><strong><?= number_format((int) $stats['products']) ?>+</strong><span>Products</span></div>
        <div><strong><?= (int) $stats['countries'] ?></strong><span>Countries of origin</span></div>
        <?php if ($customersServed > 0): ?><div><strong><?= number_format($customersServed) ?>+</strong><span>Happy customers</span></div><?php endif; ?>
      </div>
    </div>
    <div class="hero-collage" aria-hidden="true">
      <?php foreach (array_slice($featured, 0, 4) as $p): ?>
        <div class="tile"><img src="<?= e(image_url($p['image'])) ?>" alt=""><?php if ($p['origin_country']): ?><span><?= e($p['origin_country']) ?></span><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="value-props">
      <div><i class="bi bi-patch-check"></i><strong>Authentic &amp; handmade</strong><span>Every product is sourced and checked by our team.</span></div>
      <div><i class="bi bi-people"></i><strong>Supports makers</strong><span>We buy directly from artisans and cooperatives.</span></div>
      <div><i class="bi bi-geo-alt"></i><strong>Tracked delivery</strong><span>Follow your package all the way to your door.</span></div>
      <div><i class="bi bi-shield-lock"></i><strong>Secure checkout</strong><span>Payments processed by Stripe. Prices in USD.</span></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="section-head"><div><h2>Shop by category</h2><p>From the loom, the kitchen and the studio.</p></div></div>
    <div class="cat-tiles">
      <?php foreach ($cats as $c): ?>
        <a class="cat-tile" href="<?= url('shop.php?category=' . urlencode($c['slug'])) ?>">
          <div class="ic"><i class="bi <?= $iconFor($c['slug']) ?>"></i></div>
          <strong><?= e($c['name']) ?></strong><span><?= (int) $c['products'] ?> product<?= (int) $c['products'] === 1 ? '' : 's' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="section-head"><div><h2>Featured finds</h2><p>Hand-picked by our team this week.</p></div><a class="btn" href="<?= url('shop.php') ?>">View all <i class="bi bi-arrow-right"></i></a></div>
    <div class="grid-products">
      <?php foreach ($featured as $p) render_product_card($p); ?>
    </div>
  </div>
</section>

<?php if ($bestsellers): ?>
<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="section-head"><div><h2>Bestsellers</h2><p>What shoppers loved over the last 90 days.</p></div><a class="btn" href="<?= url('shop.php?sort=popular') ?>">Shop bestsellers</a></div>
    <div class="grid-products">
      <?php foreach ($bestsellers as $p) render_product_card($p); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="sell-band">
      <div>
        <h2>From the makers' hands to your home.</h2>
        <p class="text-2">We travel to markets and workshops across Africa to find pieces made the traditional way, then bring them to the US so you don't have to wait on international shipping.</p>
        <ul>
          <li><i class="bi bi-check-circle-fill"></i> Fair prices paid directly to artisans and cooperatives</li>
          <li><i class="bi bi-check-circle-fill"></i> Every item checked by our team before it ships</li>
          <li><i class="bi bi-check-circle-fill"></i> Easy returns within 14 days of delivery</li>
        </ul>
      </div>
      <div style="text-align:center">
        <a class="btn btn-primary btn-lg" href="<?= url('shop.php') ?>"><i class="bi bi-bag"></i> Start shopping</a>
        <p class="small muted" style="margin-top:10px">Questions? <a href="mailto:<?= e(setting('support_email', MAIL_FROM)) ?>">Email our team</a></p>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="section-head"><div><h2>Just arrived</h2><p>The newest additions to the shop.</p></div><a class="btn" href="<?= url('shop.php?sort=newest') ?>">See what's new</a></div>
    <div class="grid-products">
      <?php foreach ($newest as $p) render_product_card($p); ?>
    </div>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
