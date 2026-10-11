<?php
/** @var string $pageTitle  Optional: $activeCat, and SEO: $metaTitle, $metaDescription, $canonical, $metaImage, $metaImageAlt, $metaType, $metaRobots, $metaOg, $schema */
$storeName = setting('store_name', APP_NAME);
$customer = current_customer();
$topCats = array_filter(store_categories(), fn($c) => !$c['parent_id']);
$activeCat = $activeCat ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?= function_exists('app_tags') ? app_tags() : '' /* guard: never 500 if functions.php is older than this file */ ?>
  <?php
  // Keep carts, checkout, accounts, invoices and orders out of search results.
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (!isset($metaRobots) && (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/account/') !== false
      || in_array($script, ['cart.php', 'checkout.php', 'pay.php', 'order-success.php', 'order-tracking.php', 'login.php', 'register.php', 'forgot-password.php', 'reset-password.php', 'install.php'], true))) {
      $metaRobots = 'noindex, nofollow';
  }
  $fullTitle =$metaTitle ?? (isset($pageTitle) ? "$pageTitle · $storeName" : "$storeName — " . setting('store_tagline', APP_TAGLINE)); ?>
  <title><?= e($fullTitle) ?></title>
<?= seo_head([
    'title' => $fullTitle,
    'description' => $metaDescription ?? 'Authentic African groceries — Milo, Peak milk, Malta, gari, spices, snacks and more — delivered to your door anywhere in the USA. Order online or on WhatsApp.',
    'url' => $canonical ?? null,
    'image' => $metaImage ?? null,
    'image_alt' => $metaImageAlt ?? null,
    'type' => $metaType ?? 'website',
    'robots' => $metaRobots ?? null,
    'og' => $metaOg ?? [],
    'schema' => $schema ?? [],
]) ?>
  <?= favicon_tags() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,200..800&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= asset('css/store.css') ?>">
</head>
<body>
<!-- App-style loader (phones only): logo with a spinning kente ring while pages load -->
<div class="page-loader" id="pageLoader" aria-hidden="true">
  <div class="pl-badge"><span class="pl-ring"></span><img src="<?= asset('img/logo-badge-200.png') ?>" alt="" width="200" height="200"></div>
</div>
<div class="announce">
  <?php $allFree = !fetch_value("SELECT COUNT(*) FROM shipping_methods WHERE is_active = 1 AND rate_type <> 'free'");
        $freeOver = $allFree ? null : fetch_value('SELECT MIN(free_over) FROM shipping_methods WHERE is_active = 1 AND free_over IS NOT NULL'); ?>
  <?php if ($allFree): ?><strong>Free shipping</strong> on every order · <?php elseif ($freeOver): ?><strong>Free standard shipping</strong> on orders over <?= money_compact($freeOver) ?> · <?php endif; ?>Ships to all 50 states
  <?php foreach (support_phones() as $i => $ph): ?><?= $i ? ' / ' : ' · <i class="bi bi-telephone"></i> ' ?><a href="tel:<?= e(phone_href($ph)) ?>"><?= e($ph) ?></a><?php endforeach; ?>
</div>
<header class="site-header">
  <div class="wrap header-main">
    <button class="menu-btn" type="button" data-drawer-open aria-controls="mDrawer" aria-expanded="false" aria-label="Open menu"><i class="bi bi-list"></i></button>
    <a class="brand" href="<?= url('/') ?>" aria-label="<?= e($storeName) ?> home"><?= brand_lockup() ?></a>
    <form class="search-bar" action="<?= url('shop.php') ?>" method="get" role="search">
      <label class="sr-only" for="q">Search products</label>
      <input id="q" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search Milo, malt, chinchin, spices…">
      <button type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
    </form>
    <nav class="header-actions" aria-label="Account">
      <button class="icon-link search-toggle" type="button" data-drawer-open="search" aria-label="Search products"><i class="bi bi-search"></i></button>
      <a class="icon-link" href="<?= url($customer ? 'account/dashboard.php' : 'login.php') ?>"><i class="bi bi-person"></i><span class="label"><?= $customer ? e($customer['first_name']) : 'Sign in' ?></span></a>
      <a class="icon-link" href="<?= url('account/wishlist.php') ?>" aria-label="Wishlist"><i class="bi bi-heart"></i></a>
      <a class="icon-link" id="cartLink" href="<?= url('cart.php') ?>" aria-label="Cart"><i class="bi bi-bag"></i><?php if (cart_count()): ?><span class="count"><?= cart_count() ?></span><?php endif; ?><span class="label">Cart</span></a>
    </nav>
  </div>
  <nav class="cat-nav" id="catNav" aria-label="Categories">
    <div class="wrap">
      <a href="<?= url('shop.php') ?>" class="<?= $activeCat === 'all' ? 'active' : '' ?>">All products</a>
      <?php foreach ($topCats as $c): ?>
        <a href="<?= url('shop.php?category=' . urlencode($c['slug'])) ?>" class="<?= $activeCat === $c['slug'] ? 'active' : '' ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
      <a class="sell" href="<?= url('order-tracking.php') ?>"><i class="bi bi-truck"></i> Track an order</a>
    </div>
  </nav>
</header>

<!-- Mobile menu (opened by the hamburger / search icon on small screens) -->
<div class="m-drawer" id="mDrawer" hidden>
  <div class="m-drawer-backdrop" data-drawer-close></div>
  <div class="m-drawer-panel" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="m-drawer-head">
      <?= brand_lockup('dark', 'brand-sm') ?>
      <button class="m-drawer-x" type="button" data-drawer-close aria-label="Close menu"><i class="bi bi-x-lg"></i></button>
    </div>
    <form class="search-bar m-search" action="<?= url('shop.php') ?>" method="get" role="search">
      <label class="sr-only" for="mq">Search products</label>
      <input id="mq" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search Milo, malt, spices…">
      <button type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
    </form>
    <nav class="m-drawer-nav" aria-label="Shop">
      <div class="m-label">Shop</div>
      <a href="<?= url('shop.php') ?>" class="<?= $activeCat === 'all' ? 'active' : '' ?>"><i class="bi bi-grid"></i>All products</a>
      <?php foreach ($topCats as $c): ?>
        <a href="<?= url('shop.php?category=' . urlencode($c['slug'])) ?>" class="<?= $activeCat === $c['slug'] ? 'active' : '' ?>"><i class="bi bi-chevron-right"></i><?= e($c['name']) ?></a>
      <?php endforeach; ?>
      <a href="<?= url('shop.php?sort=newest') ?>"><i class="bi bi-stars"></i>New arrivals</a>
      <a href="<?= url('shop.php?sale=1') ?>"><i class="bi bi-tag"></i>On sale</a>
      <div class="m-label">Your account</div>
      <?php if ($customer): ?>
        <a href="<?= url('account/dashboard.php') ?>"><i class="bi bi-person"></i>My account</a>
        <a href="<?= url('account/orders.php') ?>"><i class="bi bi-bag-check"></i>My orders</a>
      <?php else: ?>
        <a href="<?= url('login.php') ?>"><i class="bi bi-person"></i>Sign in / Create account</a>
      <?php endif; ?>
      <a href="<?= url('account/wishlist.php') ?>"><i class="bi bi-heart"></i>Wishlist</a>
      <a href="<?= url('cart.php') ?>"><i class="bi bi-bag"></i>Cart<?php if (cart_count()): ?> <span class="m-count"><?= cart_count() ?></span><?php endif; ?></a>
      <a href="<?= url('order-tracking.php') ?>"><i class="bi bi-truck"></i>Track an order</a>
    </nav>
    <div class="m-drawer-contact">
      <button class="btn btn-block install-link" type="button" data-install-app hidden><i class="bi bi-phone"></i> Install the Jenmond app</button>
      <?php if ($waLink = whatsapp_order_link()): ?><a class="btn btn-whatsapp btn-block" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Order on WhatsApp</a><?php endif; ?>
      <?php foreach (support_phones() as $ph): ?><a href="tel:<?= e(phone_href($ph)) ?>"><i class="bi bi-telephone"></i> <?= e($ph) ?></a><?php endforeach; ?>
    </div>
  </div>
</div>
<?php $flashes = get_flashes(); if ($flashes): ?>
  <div class="wrap flash-wrap">
    <?php foreach ($flashes as $_flash): ?>
      <div class="alert alert-<?= e($_flash['type']) ?>" role="alert"><div><?= e($_flash['message']) ?></div><button class="close" aria-label="Dismiss"><i class="bi bi-x-lg"></i></button></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<main>
