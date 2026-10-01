<?php
/** @var string $pageTitle  @var string $activeNav */
$admin = current_admin();
$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';

$navCounts = [
    'products' => (int) fetch_value("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_qty = 0"),
    'orders'   => (int) fetch_value("SELECT COUNT(*) FROM orders WHERE status = 'processing' AND payment_status = 'paid'"),
    'reviews'  => (int) fetch_value("SELECT COUNT(*) FROM reviews WHERE status = 'pending'"),
];

$nav = [
    'Overview'    => [['dashboard', 'Dashboard', 'bi-grid-1x2', 'dashboard.php']],
    'Catalog'     => [
        ['products', 'Products', 'bi-box-seam', 'products.php'],
        ['categories', 'Categories', 'bi-tags', 'categories.php'],
        ['reviews', 'Reviews', 'bi-star', 'reviews.php'],
    ],
    'Sales'       => [
        ['orders', 'Orders', 'bi-bag-check', 'orders.php'],
        ['payments', 'Payments', 'bi-credit-card', 'payments.php'],
        ['customers', 'Customers', 'bi-people', 'customers.php'],
        ['coupons', 'Coupons', 'bi-ticket-perforated', 'coupons.php'],
    ],
    'Operations'  => [
        ['shipping', 'Shipping', 'bi-truck', 'shipping.php'],
        ['reports', 'Reports', 'bi-bar-chart-line', 'reports.php'],
        ['settings', 'Settings', 'bi-gear', 'settings.php'],
    ],
];
$storeName = setting('store_name', APP_NAME);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($pageTitle) ?> · <?= e($storeName) ?> Admin</title>
  <link rel="icon" type="image/svg+xml" href="<?= asset('img/logo-mark.svg') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,200..800&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
  <?php if (!empty($useCharts)): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <?php endif; ?>
</head>
<body>
<div class="admin-shell">
  <aside class="sidebar" aria-label="Admin navigation">
    <div class="sidebar-brand">
      <a href="<?= url('admin/dashboard.php') ?>"><img src="<?= asset('img/logo-light.svg') ?>" alt="<?= e($storeName) ?>"></a>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($nav as $_navGroup => $_navItems): ?>
        <div class="nav-label"><?= e($_navGroup) ?></div>
        <?php foreach ($_navItems as [$_navKey, $_navLabel, $_navIcon, $_navHref]): ?>
          <a class="nav-link<?= $activeNav === $_navKey ? ' active' : '' ?>" href="<?= url('admin/' . $_navHref) ?>">
            <i class="bi <?= $_navIcon ?>"></i><?= e($_navLabel) ?>
            <?php if (!empty($navCounts[$_navKey])): ?><span class="nav-count"><?= $navCounts[$_navKey] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">&copy; <?= date('Y') ?> <?= e($storeName) ?></div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-toggle" data-toggle-nav aria-label="Open menu"><i class="bi bi-list"></i></button>
      <div>
        <div class="crumb">Admin<?= !empty($crumb) ? ' / ' . e($crumb) : '' ?></div>
        <h1><?= e($pageTitle) ?></h1>
      </div>
      <div class="topbar-actions">
        <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i><span class="name">View store</span></a>
        <div class="user-menu">
          <button type="button" data-dropdown="userMenu" aria-haspopup="true">
            <span class="avatar gold"><?= e(initials($admin['name'])) ?></span>
            <span class="name"><?= e($admin['name']) ?></span>
            <i class="bi bi-chevron-down small"></i>
          </button>
          <div class="dropdown" id="userMenu">
            <div class="dd-head">
              <div class="strong"><?= e($admin['name']) ?></div>
              <div class="small muted"><?= e($admin['email']) ?> · <?= e(ADMIN_ROLES[$admin['role']] ?? $admin['role']) ?></div>
            </div>
            <a href="<?= url('admin/settings.php?tab=profile') ?>"><i class="bi bi-person"></i>My profile</a>
            <a href="<?= url('admin/settings.php') ?>"><i class="bi bi-gear"></i>Settings</a>
            <form method="post" action="<?= url('admin/logout.php') ?>">
              <?= csrf_field() ?>
              <button type="submit"><i class="bi bi-box-arrow-right"></i>Sign out</button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <?php foreach (get_flashes() as $_flash):
        $_flashIcon = ['success' => 'check-circle', 'error' => 'exclamation-octagon', 'warning' => 'exclamation-triangle'][$_flash['type']] ?? 'info-circle'; ?>
        <div class="alert alert-<?= e($_flash['type']) ?>" role="alert">
          <i class="bi bi-<?= $_flashIcon ?>"></i><div><?= e($_flash['message']) ?></div>
          <button class="close" aria-label="Dismiss"><i class="bi bi-x-lg"></i></button>
        </div>
      <?php endforeach; ?>
