<?php
/** @var array $customer  @var string $accountTab */
$_links = [
    'dashboard' => ['Overview', 'bi-grid', 'account/dashboard.php'],
    'orders'    => ['Orders', 'bi-bag-check', 'account/orders.php'],
    'wishlist'  => ['Wishlist', 'bi-heart', 'account/wishlist.php'],
    'addresses' => ['Addresses', 'bi-geo-alt', 'account/addresses.php'],
    'profile'   => ['Profile & password', 'bi-person-gear', 'account/profile.php'],
];
?>
<aside class="account-nav" aria-label="Account">
  <div class="who">
    <span class="avatar"><?= e(initials($customer['first_name'] . ' ' . $customer['last_name'])) ?></span>
    <div><strong><?= e($customer['first_name'] . ' ' . $customer['last_name']) ?></strong><div class="small muted" style="word-break:break-all"><?= e($customer['email']) ?></div></div>
  </div>
  <?php foreach ($_links as $_k => [$_label, $_icon, $_href]): ?>
    <a href="<?= url($_href) ?>" class="<?= $accountTab === $_k ? 'active' : '' ?>"><i class="bi <?= $_icon ?>"></i><?= $_label ?></a>
  <?php endforeach; ?>
  <form method="post" action="<?= url('logout.php') ?>"><?= csrf_field() ?><button type="submit"><i class="bi bi-box-arrow-right"></i>Sign out</button></form>
</aside>
