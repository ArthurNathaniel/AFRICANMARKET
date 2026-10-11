<?php
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/product-card.php';

$base = product_card_sql() . ' WHERE ' . LIVE_PRODUCT_SQL;

// Each rail skips products already shown higher up the page, so a small
// catalog doesn't repeat the same items; rails with nothing left are hidden.
$shown = [];
$take = function (array $rows, int $limit) use (&$shown): array {
    $out = [];
    foreach ($rows as $r) {
        if (count($out) >= $limit) break;
        if (isset($shown[$r['id']])) continue;
        $shown[$r['id']] = true;
        $out[] = $r;
    }
    return $out;
};

$heroPicks = fetch_all("$base ORDER BY p.is_featured DESC, p.stock_qty > 0 DESC, p.created_at DESC LIMIT 3");
$deals = $take(fetch_all("$base AND p.compare_price > p.price ORDER BY (p.compare_price - p.price) / p.compare_price DESC LIMIT 24"), 4);
$featured = $take(fetch_all("$base ORDER BY p.is_featured DESC, p.created_at DESC LIMIT 40"), 10);
$bestsellers = $take(fetch_all(
    "$base AND p.id IN (SELECT oi.product_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
                        WHERE o.payment_status = 'paid' AND o.created_at >= ?)
     ORDER BY (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.product_id = p.id) DESC LIMIT 24",
    [date('Y-m-d', strtotime('-90 days'))]
), 10);
$newest = $take(fetch_all("$base ORDER BY p.created_at DESC LIMIT 24"), 10);

$maxOff = (int) fetch_value('SELECT MAX(ROUND(100 - p.price / p.compare_price * 100)) FROM products p WHERE p.compare_price > p.price AND ' . LIVE_PRODUCT_SQL);
$dealPic = $deals[0]['image'] ?? null;

// Categories, with a product photo for each where we have one
$cats = array_filter(store_categories(), fn($c) => !$c['parent_id']);
usort($cats, fn($a, $b) => ((int) $b['products'] > 0) <=> ((int) $a['products'] > 0));
$catPics = [];
foreach (fetch_all('SELECT p.category_id, p.image FROM products p WHERE p.image IS NOT NULL AND ' . LIVE_PRODUCT_SQL . ' ORDER BY p.is_featured DESC, p.created_at DESC') as $r) {
    $catPics[$r['category_id']] ??= $r['image'];
}
$icons = [
    'drink' => 'bi-cup-straw', 'snack' => 'bi-basket2', 'spice' => 'bi-fire', 'sauce' => 'bi-fire', 'meat' => 'bi-egg-fried',
    'fish' => 'bi-egg-fried', 'fashion' => 'bi-bag-heart', 'beauty' => 'bi-flower1', 'grain' => 'bi-flower3', 'rice' => 'bi-flower3',
    'flour' => 'bi-flower3', 'frozen' => 'bi-snow', 'home' => 'bi-house-heart', 'art' => 'bi-palette', 'jewel' => 'bi-gem',
];
$iconFor = function (string $slug) use ($icons) {
    foreach ($icons as $k => $i) if (strpos($slug, $k) !== false) return $i;
    return 'bi-basket';
};

$wa = whatsapp_order_link();
$phones = support_phones();

/** One horizontally scrolling product rail with its heading. */
$rail = function (string $id, string $title, string $sub, array $items, string $link, string $linkText, string $icon = '') {
    if (!$items) return; ?>
    <section class="h-section">
      <div class="wrap">
        <div class="h-head">
          <div><h2><?php if ($icon): ?><i class="bi <?= $icon ?>"></i> <?php endif; ?><?= e($title) ?></h2><p><?= e($sub) ?></p></div>
          <div class="h-head-actions">
            <div class="rail-nav"><button type="button" data-rail="#<?= $id ?>" data-dir="-1" aria-label="Scroll left"><i class="bi bi-chevron-left"></i></button><button type="button" data-rail="#<?= $id ?>" data-dir="1" aria-label="Scroll right"><i class="bi bi-chevron-right"></i></button></div>
            <a class="h-link" href="<?= url($link) ?>"><?= e($linkText) ?> <i class="bi bi-arrow-right"></i></a>
          </div>
        </div>
        <div class="rail" id="<?= $id ?>">
          <?php foreach ($items as $p) render_product_card($p); ?>
        </div>
      </div>
    </section>
<?php };

// ---- SEO: homepage title/description + store and site-search structured data ----
$storeName = setting('store_name', APP_NAME);
$metaTitle = $storeName . ' – African Groceries Delivered Across the USA';
$metaDescription = 'Shop Milo, Peak milk, Malta, chinchin, spices and more — the African brands you grew up with, delivered to your door anywhere in the USA. Order online or on WhatsApp.';
$canonical = absolute_url('/');
$schema = [
    organization_schema(),
    ['@type' => 'WebSite', '@id' => $canonical . '#website', 'name' => $storeName, 'url' => $canonical,
     'publisher' => ['@id' => $canonical . '#organization'],
     'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => absolute_url('shop.php') . '?q={search_term_string}'], 'query-input' => 'required name=search_term_string']],
];

require __DIR__ . '/partials/header.php';
?>
<section class="h-hero">
  <div class="wrap">
    <div class="h-hero-copy">
      <span class="h-pill"><span class="dot"></span> Shipping free to all 50 states</span>
      <h1>Your <em><span id="heroTyped">African market</span></em>, at your door.</h1>
      <p>Milo, Peak milk, Malta, chinchin, spices and more — the African brands you grew up with, delivered anywhere in the USA.</p>
      <div class="h-ctas">
        <a class="btn btn-primary btn-lg" href="<?= url('shop.php') ?>"><i class="bi bi-basket2"></i> Shop groceries</a>
        <?php if ($wa): ?><a class="btn btn-lg h-btn-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Order on WhatsApp</a><?php endif; ?>
      </div>
      <div class="h-proof">
        <div><i class="bi bi-truck"></i><span><strong>Free</strong> shipping</span></div>
        <div><i class="bi bi-shield-check"></i><span><strong>Secure</strong> checkout</span></div>
        <div><i class="bi bi-headset"></i><span><strong>Real</strong> people</span></div>
      </div>
    </div>

    <div class="h-hero-art">
      <div class="h-blob"></div>
      <svg class="h-sticker" viewBox="0 0 120 120" aria-hidden="true">
        <defs><path id="stickerCircle" d="M60,60 m-44,0 a44,44 0 1,1 88,0 a44,44 0 1,1 -88,0"/></defs>
        <circle cx="60" cy="60" r="58"/>
        <text><textPath href="#stickerCircle">FREE SHIPPING • EVERY ORDER • ALL 50 STATES •</textPath></text>
        <g transform="translate(60 60)"><text class="mid" text-anchor="middle" dy="5">FREE</text></g>
      </svg>
      <?php foreach ($heroPicks as $i => $p): $onSale = $p['compare_price'] !== null && $p['compare_price'] > $p['price']; ?>
        <a class="h-float h-float-<?= $i ?>" href="<?= e(product_url($p)) ?>">
          <img src="<?= e(image_url($p['image'])) ?>" alt="<?= e($p['name']) ?>" loading="<?= $i ? 'lazy' : 'eager' ?>">
          <span class="tag"><strong><?= money($p['price']) ?></strong><?php if ($onSale): ?><em>−<?= (int) round(100 - $p['price'] / $p['compare_price'] * 100) ?>%</em><?php endif; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($cats): ?>
<section class="h-cats">
  <div class="wrap">
    <div class="h-cat-row">
      <a class="h-cat all" href="<?= url('shop.php') ?>"><span class="bubble"><i class="bi bi-grid"></i></span><strong>Shop all</strong></a>
      <?php foreach ($cats as $c): $n = (int) $c['products']; $pic = $catPics[$c['id']] ?? null; ?>
        <a class="h-cat<?= $n ? '' : ' soon' ?>" href="<?= url('shop.php?category=' . urlencode($c['slug'])) ?>">
          <span class="bubble"><?php if ($pic): ?><img src="<?= e(image_url($pic)) ?>" alt="" loading="lazy"><?php else: ?><i class="bi <?= $iconFor($c['slug']) ?>"></i><?php endif; ?></span>
          <strong><?= e($c['name']) ?></strong><small><?= $n ? $n . ' item' . ($n === 1 ? '' : 's') : 'Coming soon' ?></small>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="h-section h-promos-wrap">
  <div class="wrap h-promos">
    <a class="h-promo deal" href="<?= url('shop.php?sale=1') ?>">
      <div>
        <span class="kicker">Today's deals</span>
        <h3><?= $maxOff > 0 ? "Save up to {$maxOff}%" : 'Fresh deals every week' ?></h3>
        <p>Big markdowns on pantry favourites — while stocks last.</p>
        <span class="go">Shop deals <i class="bi bi-arrow-right"></i></span>
      </div>
      <?php if ($dealPic): ?><img src="<?= e(image_url($dealPic)) ?>" alt="" loading="lazy"><?php endif; ?>
    </a>
    <?php if ($wa): ?>
    <a class="h-promo wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">
      <div>
        <span class="kicker">No account needed</span>
        <h3>Send us your list on WhatsApp</h3>
        <p>Tell us what you need — we'll confirm your total and ship it free.</p>
        <span class="go">Start a chat <i class="bi bi-arrow-right"></i></span>
      </div>
      <i class="bi bi-whatsapp big-ic"></i>
    </a>
    <?php endif; ?>
  </div>
</section>

<?php $rail('railDeals', "Today's deals", 'Our biggest markdowns right now.', $deals, 'shop.php?sale=1', 'All deals', 'bi-lightning-charge-fill'); ?>
<?php $rail('railPopular', 'Popular picks', 'Pantry staples our customers keep coming back for.', $featured, 'shop.php', 'Shop all'); ?>

<section class="h-section">
  <div class="wrap">
    <div class="h-steps">
      <div class="h-steps-head">
        <h2>How it works</h2>
        <p>From our shelves to your kitchen in three easy steps.</p>
      </div>
      <ol>
        <li><span class="num">1</span><i class="bi bi-basket2"></i><strong>Pick your favourites</strong><span>Browse drinks, snacks, spices and more — or just send us your list.</span></li>
        <li><span class="num">2</span><i class="bi bi-credit-card-2-front"></i><strong>Check out your way</strong><span>Pay securely online with card, Apple Pay or Google Pay, or order by WhatsApp or phone.</span></li>
        <li><span class="num">3</span><i class="bi bi-box-seam"></i><strong>Delivered free</strong><span>We pack and ship it free, with tracking all the way to your door.</span></li>
      </ol>
    </div>
  </div>
</section>

<?php $rail('railBest', 'Bestsellers', 'What shoppers loved over the last 90 days.', $bestsellers, 'shop.php?sort=popular', 'Shop bestsellers', 'bi-fire'); ?>
<?php $rail('railNew', 'Just arrived', 'The newest additions to the shop.', $newest, 'shop.php?sort=newest', "See what's new", 'bi-stars'); ?>

<section class="h-section">
  <div class="wrap">
    <div class="h-contact">
      <div class="h-contact-copy">
        <h2>Can't find what you're looking for?</h2>
        <p>Tell us — we stock new products every week and we're happy to help you order.</p>
      </div>
      <div class="h-contact-actions">
        <?php if ($wa): ?><a class="btn btn-lg h-btn-wa solid" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp us</a><?php endif; ?>
        <?php foreach ($phones as $ph): ?><a class="btn btn-lg h-btn-call" href="tel:<?= e(phone_href($ph)) ?>"><i class="bi bi-telephone"></i> <?= e($ph) ?></a><?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php
$pageScripts = '<script src="https://cdn.jsdelivr.net/npm/typed.js@2.1.0/dist/typed.umd.js"></script>
<script>
(function () {
  var el = document.getElementById("heroTyped");
  if (!el || !window.Typed || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  new Typed(el, {
    strings: ["African market", "Milo &amp; Malta", "jollof spices", "Peak milk", "chinchin fix", "taste of home"],
    typeSpeed: 70,
    backSpeed: 40,
    backDelay: 1800,
    startDelay: 1200,
    loop: true,
    cursorChar: "|"
  });
})();
</script>';
require __DIR__ . '/partials/footer.php'; ?>
