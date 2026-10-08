<?php
/** @var string $pageTitle  Optional: $metaDescription, $activeCat */
$storeName = setting('store_name', APP_NAME);
$customer = current_customer();
$topCats = array_filter(store_categories(), fn($c) => !$c['parent_id']);
$activeCat = $activeCat ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(isset($pageTitle) ? "$pageTitle · $storeName" : "$storeName — " . setting('store_tagline', APP_TAGLINE)) ?></title>
  <meta name="description" content="<?= e($metaDescription ?? 'Shop authentic African fashion, beauty, art, food and more — shipped across the USA.') ?>">
  <?= favicon_tags() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,200..800&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= asset('css/store.css') ?>">
</head>
<body>
<div class="announce">
  <?php $allFree = !fetch_value("SELECT COUNT(*) FROM shipping_methods WHERE is_active = 1 AND rate_type <> 'free'");
        $freeOver = $allFree ? null : fetch_value('SELECT MIN(free_over) FROM shipping_methods WHERE is_active = 1 AND free_over IS NOT NULL'); ?>
  <?php if ($allFree): ?><strong>Free shipping</strong> on every order · <?php elseif ($freeOver): ?><strong>Free standard shipping</strong> on orders over <?= money_compact($freeOver) ?> · <?php endif; ?>Ships to all 50 states
  <?php foreach (support_phones() as $i => $ph): ?><?= $i ? ' / ' : ' · <i class="bi bi-telephone"></i> ' ?><a href="tel:<?= e(phone_href($ph)) ?>"><?= e($ph) ?></a><?php endforeach; ?>
</div>
<header class="site-header">
  <div class="wrap header-main">
    <button class="menu-btn" data-toggle="#catNav" aria-label="Browse categories"><i class="bi bi-list"></i></button>
    <a class="brand" href="<?= url('/') ?>" aria-label="<?= e($storeName) ?> home"><?= brand_lockup() ?></a>
    <form class="search-bar" action="<?= url('shop.php') ?>" method="get" role="search">
      <label class="sr-only" for="q">Search products</label>
      <input id="q" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search Milo, malt, chinchin, spices…">
      <button type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
    </form>
    <nav class="header-actions" aria-label="Account">
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
<?php $flashes = get_flashes(); if ($flashes): ?>
  <div class="wrap flash-wrap">
    <?php foreach ($flashes as $_flash): ?>
      <div class="alert alert-<?= e($_flash['type']) ?>" role="alert"><div><?= e($_flash['message']) ?></div><button class="close" aria-label="Dismiss"><i class="bi bi-x-lg"></i></button></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<main>
