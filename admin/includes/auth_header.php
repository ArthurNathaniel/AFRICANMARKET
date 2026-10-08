<?php /** @var string $pageTitle */ $storeName = setting('store_name', APP_NAME); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($pageTitle) ?> · <?= e($storeName) ?> Admin</title>
  <?= favicon_tags() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,200..800&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= asset('css/admin.css') ?>">
</head>
<body>
<div class="auth-page">
  <section class="auth-art">
    <?= brand_lockup('light', 'logo brand-lg') ?>
    <div>
      <h2>Bringing the best of <span>Africa</span> to every American doorstep.</h2>
      <p>Manage products, fulfil orders, track payments and look after customers — all from one place.</p>
      <ul class="auth-points">
        <li><i class="bi bi-box-seam"></i> Catalog, stock &amp; categories</li>
        <li><i class="bi bi-graph-up-arrow"></i> Sales &amp; profit reports</li>
        <li><i class="bi bi-truck"></i> US shipping zones &amp; order tracking</li>
      </ul>
    </div>
    <div class="small" style="opacity:.6">&copy; <?= date('Y') ?> <?= e($storeName) ?> · Admin console</div>
  </section>
  <section class="auth-form-wrap">
    <div class="auth-form">
      <?= brand_lockup('dark', 'mobile-logo') ?>
      <?php foreach (get_flashes() as $_flash): ?>
        <div class="alert alert-<?= e($_flash['type']) ?>" role="alert"><div><?= e($_flash['message']) ?></div></div>
      <?php endforeach; ?>
