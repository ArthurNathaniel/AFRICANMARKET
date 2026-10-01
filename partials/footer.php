</main>
<?php $storeName = setting('store_name', APP_NAME); ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid">
      <div>
        <img src="<?= asset('img/logo-light.svg') ?>" alt="<?= e($storeName) ?>" style="height:44px;margin-bottom:14px">
        <p style="max-width:320px;margin:0">Authentic African products, sourced from makers across the continent and shipped to your door anywhere in the USA.</p>
      </div>
      <div>
        <h4>Shop</h4>
        <ul>
          <li><a href="<?= url('shop.php') ?>">All products</a></li>
          <li><a href="<?= url('shop.php?sort=newest') ?>">New arrivals</a></li>
          <li><a href="<?= url('shop.php?sale=1') ?>">On sale</a></li>
        </ul>
      </div>
      <div>
        <h4>Account</h4>
        <ul>
          <li><a href="<?= url('account/dashboard.php') ?>">My account</a></li>
          <li><a href="<?= url('account/orders.php') ?>">Orders</a></li>
          <li><a href="<?= url('account/wishlist.php') ?>">Wishlist</a></li>
          <li><a href="<?= url('cart.php') ?>">Cart</a></li>
        </ul>
      </div>
      <div>
        <h4>Help</h4>
        <ul>
          <li><a href="<?= url('order-tracking.php') ?>">Order tracking</a></li>
          <?php if (setting('support_email')): ?><li><a href="mailto:<?= e(setting('support_email')) ?>"><?= e(setting('support_email')) ?></a></li><?php endif; ?>
          <?php if (setting('support_phone')): ?><li><?= e(setting('support_phone')) ?></li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($storeName) ?>. All prices in USD.</span>
      <span class="pay-icons" aria-label="Payment methods accepted"><i class="bi bi-credit-card-2-front"></i><i class="bi bi-stripe"></i><i class="bi bi-apple"></i><i class="bi bi-google"></i></span>
    </div>
  </div>
</footer>
<script src="<?= asset('js/store.js') ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
