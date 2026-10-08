</main>
<?php $storeName = setting('store_name', APP_NAME); ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid">
      <div>
        <div style="margin-bottom:14px"><?= brand_lockup('light', 'brand-lg') ?></div>
        <p style="max-width:320px;margin:0">Authentic African groceries and everyday favourites, shipped free to your door anywhere in the USA.</p>
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
          <?php foreach (support_phones() as $ph): ?><li><a href="tel:<?= e(phone_href($ph)) ?>"><i class="bi bi-telephone"></i> <?= e($ph) ?></a></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($storeName) ?>. All prices in USD.</span>
      <span class="pay-icons" aria-label="Payment methods accepted"><i class="bi bi-credit-card-2-front"></i><i class="bi bi-stripe"></i><i class="bi bi-apple"></i><i class="bi bi-google"></i></span>
    </div>
  </div>
</footer>
<?php if ($wa = whatsapp_order_link()): ?>
  <a class="wa-float" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Order via WhatsApp"><i class="bi bi-whatsapp"></i></a>
<?php endif; ?>
<script src="<?= asset('js/store.js') ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
