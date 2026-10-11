</main>
<?php $storeName = setting('store_name', APP_NAME); ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid">
      <div>
        <div style="margin-bottom:14px"><?= brand_lockup('light', 'brand-lg') ?></div>
        <p class="footer-about">Authentic African groceries and everyday favourites, shipped free to your door anywhere in the USA.</p>
        <?php if ($socials = social_links()): ?>
          <div class="footer-social">
            <?php foreach ($socials as [$label, $icon, $link]): ?>
              <a href="<?= e($link) ?>" target="_blank" rel="noopener me" aria-label="<?= e($storeName) ?> on <?= e($label) ?>" title="<?= e($label) ?>"><i class="bi <?= $icon ?>"></i></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
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
          <li class="install-link" hidden><a href="#" data-install-app><i class="bi bi-phone"></i> Get the app</a></li>
          <?php foreach (support_emails() as $em): ?><li><a href="mailto:<?= e($em) ?>"><i class="bi bi-envelope"></i> <?= e($em) ?></a></li><?php endforeach; ?>
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

<?php
// App-style bottom tab bar on phones (hidden during checkout and payment).
$_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_inAccount = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/account/') !== false;
$_tab = $_inAccount || in_array($_script, ['login.php', 'register.php'], true) ? 'account'
      : (in_array($_script, ['shop.php', 'product.php'], true) ? 'shop'
      : (in_array($_script, ['cart.php'], true) ? 'cart'
      : ($_script === 'order-tracking.php' ? 'track' : ($_script === 'index.php' ? 'home' : ''))));
$_cartCount = cart_count();
$_me = current_customer();
if (!in_array($_script, ['checkout.php', 'pay.php'], true)): ?>
<nav class="tabbar" aria-label="App navigation">
  <a href="<?= url('/') ?>" class="<?= $_tab === 'home' ? 'on' : '' ?>"<?= $_tab === 'home' ? ' aria-current="page"' : '' ?>><i class="bi bi-house<?= $_tab === 'home' ? '-fill' : '' ?>"></i><span>Home</span></a>
  <a href="<?= url('shop.php') ?>" class="<?= $_tab === 'shop' ? 'on' : '' ?>"<?= $_tab === 'shop' ? ' aria-current="page"' : '' ?>><i class="bi bi-grid<?= $_tab === 'shop' ? '-fill' : '' ?>"></i><span>Shop</span></a>
  <a href="<?= url('order-tracking.php') ?>" class="<?= $_tab === 'track' ? 'on' : '' ?>"<?= $_tab === 'track' ? ' aria-current="page"' : '' ?>><i class="bi bi-truck"></i><span>Track</span></a>
  <a href="<?= url('cart.php') ?>" class="<?= $_tab === 'cart' ? 'on' : '' ?>"<?= $_tab === 'cart' ? ' aria-current="page"' : '' ?>><i class="bi bi-bag<?= $_tab === 'cart' ? '-fill' : '' ?>"></i><?php if ($_cartCount): ?><b class="tab-count" data-cart-badge><?= $_cartCount ?></b><?php else: ?><b class="tab-count" data-cart-badge hidden></b><?php endif; ?><span>Cart</span></a>
  <a href="<?= url($_me ? 'account/dashboard.php' : 'login.php') ?>" class="<?= $_tab === 'account' ? 'on' : '' ?>"<?= $_tab === 'account' ? ' aria-current="page"' : '' ?>><i class="bi bi-person<?= $_tab === 'account' ? '-fill' : '' ?>"></i><span><?= $_me ? 'Account' : 'Sign in' ?></span></a>
</nav>
<?php endif; ?>

<!-- "Install the app" banner (phones) + instructions for iPhone, where there's no install button -->
<div class="install-banner" id="installBanner" hidden>
  <img src="<?= asset('img/icon-192.png') ?>" alt="" width="44" height="44">
  <div class="grow"><strong>Get the Jenmond app</strong><span>Faster shopping, right from your home screen.</span></div>
  <button class="btn btn-primary btn-sm" type="button" data-install-app>Install</button>
  <button class="install-x" type="button" data-install-dismiss aria-label="Not now"><i class="bi bi-x-lg"></i></button>
</div>
<div class="install-sheet" id="installSheet" hidden>
  <div class="install-sheet-backdrop" data-install-close></div>
  <div class="install-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="installTitle">
    <img src="<?= asset('img/icon-192.png') ?>" alt="" width="64" height="64">
    <h3 id="installTitle">Install the Jenmond app</h3>
    <ol data-steps="ios" hidden>
      <li>Open this page in <strong>Safari</strong>, then tap the <strong>Share</strong> button <i class="bi bi-box-arrow-up"></i>.</li>
      <li>Scroll down and tap <strong>Add to Home Screen</strong> <i class="bi bi-plus-square"></i>.</li>
      <li>Tap <strong>Add</strong>. Jenmond now opens full-screen like an app from your home screen.</li>
    </ol>
    <ol data-steps="android" hidden>
      <li>Open this page in <strong>Chrome</strong>, then tap the <strong>⋮ menu</strong> (top right).</li>
      <li>Tap <strong>Install app</strong> (or <strong>Add to Home screen</strong>, then choose <strong>Install</strong>).</li>
      <li>Tap <strong>Install</strong>. Jenmond appears with your apps and opens full-screen.</li>
    </ol>
    <ol data-steps="samsung" hidden>
      <li>In <strong>Samsung Internet</strong>, tap the <strong>☰ menu</strong> (bottom right).</li>
      <li>Tap <strong>Add page to</strong> → <strong>Home screen</strong> (or the <i class="bi bi-download"></i> install icon in the address bar).</li>
      <li>Tap <strong>Add</strong>. Jenmond now opens like an app.</li>
    </ol>
    <button class="btn btn-primary btn-block" type="button" data-install-close>Got it</button>
  </div>
</div>
<script src="<?= asset('js/store.js') ?>"></script>
<?php if (!empty($pageScripts)) echo $pageScripts; ?>
</body>
</html>
