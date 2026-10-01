<?php
require __DIR__ . '/partials/bootstrap.php';

[$lines, $notices] = cart_lines();
if (!$lines) {
    flash('info', 'Your cart is empty.');
    redirect('cart.php');
}
foreach ($notices as $n) flash('warning', $n);

$customer = current_customer();
$states = us_states();
$subtotal = cart_subtotal($lines);
$weight = cart_weight($lines);
[$coupon] = validate_coupon($_SESSION['coupon'] ?? '', $subtotal);
$discount = coupon_discount($coupon, $subtotal);
$taxRate = (float) setting('tax_rate', 0);
$addresses = $customer ? fetch_all('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id', [$customer['id']]) : [];
$default = $addresses[0] ?? null;
$errors = [];

if (is_post()) {
    verify_csrf();
    $ship = [
        'name'  => input('ship_name'),
        'line1' => input('ship_line1'),
        'line2' => input('ship_line2'),
        'city'  => input('ship_city'),
        'state' => strtoupper(input('ship_state')),
        'zip'   => input('ship_zip'),
        'phone' => input('ship_phone'),
    ];
    $email = strtolower($customer['email'] ?? input('email'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address for your receipt.';
    if (mb_strlen($ship['name']) < 2) $errors[] = 'Enter the recipient\'s full name.';
    if (mb_strlen($ship['line1']) < 3) $errors[] = 'Enter a street address.';
    if (mb_strlen($ship['city']) < 2) $errors[] = 'Enter a city.';
    if (!isset($states[$ship['state']])) $errors[] = 'Choose a US state.';
    if (!preg_match('/^\d{5}(-\d{4})?$/', $ship['zip'])) $errors[] = 'Enter a 5-digit ZIP code.';
    if (!stripe_enabled() && !test_payments_enabled()) $errors[] = 'Online payments are not set up yet. Please try again later.';

    if (!$errors) {
        // Discard this visitor's previous abandoned (never paid) checkout attempt.
        if (!empty($_SESSION['pending_order_id'])) {
            q("DELETE FROM orders WHERE id = ? AND payment_status = 'unpaid' AND stripe_session_id IS NULL", [$_SESSION['pending_order_id']]);
        }
        [$orderId, $errors] = create_order_from_cart($ship, $email, input_int('shipping_method'), $customer['id'] ?? null);
    }

    if (!$errors) {
        $_SESSION['pending_order_id'] = $orderId;
        if ($customer && isset($_POST['save_address'])) {
            $isFirst = !$addresses;
            q('INSERT INTO customer_addresses (customer_id, label, full_name, line1, line2, city, state, zip, phone, is_default) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$customer['id'], 'Shipping', $ship['name'], $ship['line1'], $ship['line2'] ?: null, $ship['city'], $ship['state'], $ship['zip'], $ship['phone'] ?: null, $isFirst ? 1 : 0]);
        }
        $order = fetch_one('SELECT * FROM orders WHERE id = ?', [$orderId]);

        if (stripe_enabled()) {
            try {
                redirect(stripe_checkout_url($order));
            } catch (RuntimeException $ex) {
                error_log('Stripe checkout failed: ' . $ex->getMessage());
                $errors[] = 'We couldn\'t start the secure payment page. Please try again in a moment.';
            }
        } else {
            // Development only: simulate a successful card payment.
            mark_order_paid($orderId, 'manual', 'TEST-' . strtoupper(bin2hex(random_bytes(5))), (float) $order['grand_total']);
            cart_clear();
            unset($_SESSION['pending_order_id']);
            redirect('order-success.php?order=' . urlencode($order['order_number']));
        }
    }
}

if (input('cancelled') === '1' && !is_post()) {
    $errors[] = 'Payment was cancelled — your cart is still here whenever you\'re ready.';
}

$val = function (string $k, string $fallback = '') { return e($_POST[$k] ?? $fallback); };
$shipTable = shipping_table($subtotal, $weight);
$selState = $_POST['ship_state'] ?? ($default['state'] ?? '');

$pageTitle = 'Checkout';
require __DIR__ . '/partials/header.php';
?>
<div class="wrap">
  <div class="page-title"><div class="crumbs"><a href="<?= url('cart.php') ?>">Cart</a> / Checkout</div><h1>Checkout</h1></div>

  <?php if ($errors): ?>
    <div class="alert alert-<?= input('cancelled') === '1' && !is_post() ? 'info' : 'error' ?>" role="alert"><i class="bi bi-exclamation-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <form method="post" class="cart-layout" id="checkoutForm" novalidate>
    <?= csrf_field() ?>
    <div style="display:grid;gap:20px">
      <section class="panel panel-body">
        <h2 class="step-title"><b>1</b> Contact</h2>
        <?php if ($customer): ?>
          <p class="text-2" style="margin:0">Signed in as <strong><?= e($customer['email']) ?></strong>. Your receipt and tracking updates go here.</p>
        <?php else: ?>
          <div class="field"><label for="email">Email for receipt &amp; tracking <span class="req">*</span></label><input class="input" type="email" id="email" name="email" value="<?= $val('email') ?>" autocomplete="email" required></div>
          <p class="small text-2" style="margin:0">Have an account? <a href="<?= url('login.php?next=' . urlencode(url('checkout.php'))) ?>">Sign in</a> for faster checkout. You can also check out as a guest.</p>
        <?php endif; ?>
      </section>

      <section class="panel panel-body">
        <h2 class="step-title"><b>2</b> Shipping address</h2>
        <?php if (count($addresses) > 1): ?>
          <div class="field"><label for="savedAddress">Use a saved address</label>
            <select id="savedAddress">
              <?php foreach ($addresses as $a): ?>
                <option data-name="<?= e($a['full_name']) ?>" data-line1="<?= e($a['line1']) ?>" data-line2="<?= e($a['line2']) ?>" data-city="<?= e($a['city']) ?>" data-state="<?= e($a['state']) ?>" data-zip="<?= e($a['zip']) ?>" data-phone="<?= e($a['phone']) ?>">
                  <?= e(($a['label'] ? $a['label'] . ' — ' : '') . $a['line1'] . ', ' . $a['city'] . ' ' . $a['state']) ?></option>
              <?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <div class="field"><label for="ship_name">Full name <span class="req">*</span></label><input class="input" id="ship_name" name="ship_name" value="<?= $val('ship_name', $default['full_name'] ?? ($customer ? $customer['first_name'] . ' ' . $customer['last_name'] : '')) ?>" autocomplete="name" required></div>
        <div class="field"><label for="ship_line1">Street address <span class="req">*</span></label><input class="input" id="ship_line1" name="ship_line1" value="<?= $val('ship_line1', $default['line1'] ?? '') ?>" autocomplete="address-line1" required></div>
        <div class="field"><label for="ship_line2">Apartment, suite, etc.</label><input class="input" id="ship_line2" name="ship_line2" value="<?= $val('ship_line2', $default['line2'] ?? '') ?>" autocomplete="address-line2"></div>
        <div class="form-row three">
          <div class="field"><label for="ship_city">City <span class="req">*</span></label><input class="input" id="ship_city" name="ship_city" value="<?= $val('ship_city', $default['city'] ?? '') ?>" autocomplete="address-level2" required></div>
          <div class="field"><label for="ship_state">State <span class="req">*</span></label>
            <select id="ship_state" name="ship_state" autocomplete="address-level1" required>
              <option value="">Select…</option>
              <?php foreach ($states as $code => $name): ?><option value="<?= $code ?>" <?= $selState === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="ship_zip">ZIP <span class="req">*</span></label><input class="input" id="ship_zip" name="ship_zip" value="<?= $val('ship_zip', $default['zip'] ?? '') ?>" inputmode="numeric" autocomplete="postal-code" required></div>
        </div>
        <div class="field"><label for="ship_phone">Phone (for delivery questions)</label><input class="input" type="tel" id="ship_phone" name="ship_phone" value="<?= $val('ship_phone', $default['phone'] ?? ($customer['phone'] ?? '')) ?>" autocomplete="tel"></div>
        <?php if ($customer && !$addresses): ?><label class="check small"><input type="checkbox" name="save_address" value="1" checked> Save this address to my account</label><?php endif; ?>
      </section>

      <section class="panel panel-body">
        <h2 class="step-title"><b>3</b> Shipping method</h2>
        <div class="ship-options" id="shipOptions" data-selected="<?= (int) ($_POST['shipping_method'] ?? 0) ?>">
          <p class="text-2" style="margin:0">Choose your state to see delivery options.</p>
        </div>
        <?php if (setting('ship_origin_note')): ?><p class="small text-2" style="margin:14px 0 0"><i class="bi bi-info-circle"></i> <?= e(setting('ship_origin_note')) ?></p><?php endif; ?>
      </section>

      <section class="panel panel-body">
        <h2 class="step-title"><b>4</b> Payment</h2>
        <?php if (stripe_enabled()): ?>
          <p class="text-2" style="margin:0"><i class="bi bi-shield-lock"></i> You'll enter your card on Stripe's secure page, then come straight back here. Apple Pay and Google Pay are supported where available.</p>
        <?php elseif (test_payments_enabled()): ?>
          <div class="alert alert-warning" style="margin:0"><i class="bi bi-tools"></i><div><strong>Test mode:</strong> Stripe isn't configured yet, so placing the order simulates a successful payment. Add your Stripe keys in Admin → Settings → Payment gateways to take real payments.</div></div>
        <?php else: ?>
          <div class="alert alert-error" style="margin:0">Online payments are not available right now.</div>
        <?php endif; ?>
      </section>
    </div>

    <aside class="panel" style="position:sticky;top:140px">
      <div class="panel-head"><h2>Order summary</h2><a class="small" href="<?= url('cart.php') ?>">Edit cart</a></div>
      <div class="panel-body">
        <?php foreach ($lines as $l): ?>
          <div class="mini-item">
            <div class="q"><img src="<?= e(image_url($l['image'])) ?>" alt=""><span><?= (int) $l['qty'] ?></span></div>
            <div class="small"><strong><?= e($l['name']) ?></strong><?php if ($l['origin_country']): ?><div class="muted">Made in <?= e($l['origin_country']) ?></div><?php endif; ?></div>
            <div class="small strong"><?= money($l['line_total']) ?></div>
          </div>
        <?php endforeach; ?>
        <div style="border-top:1px solid var(--border);margin-top:10px;padding-top:10px">
          <div class="summary-row"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
          <?php if ($discount > 0): ?><div class="summary-row discount"><span>Coupon <?= e($coupon['code']) ?></span><span>−<?= money($discount) ?></span></div><?php endif; ?>
          <div class="summary-row"><span>Shipping</span><span id="sumShipping">—</span></div>
          <?php if ($taxRate > 0): ?><div class="summary-row"><span>Tax (<?= e(rtrim(rtrim(number_format($taxRate, 2), '0'), '.')) ?>%)</span><span><?= money(round(($subtotal - $discount) * $taxRate / 100, 2)) ?></span></div><?php endif; ?>
          <div class="summary-row total"><span>Total</span><span id="sumTotal"><?= money($subtotal - $discount + round(($subtotal - $discount) * $taxRate / 100, 2)) ?></span></div>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit" id="placeOrder" style="margin-top:14px" <?= stripe_enabled() || test_payments_enabled() ? '' : 'disabled' ?>>
          <i class="bi bi-lock-fill"></i> <?= stripe_enabled() ? 'Continue to payment' : 'Place order' ?></button>
        <p class="small muted" style="text-align:center;margin:10px 0 0">By placing your order you agree to our terms and returns policy.</p>
      </div>
    </aside>
  </form>
</div>
<?php
$j = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$pageScripts = '<script>
(function () {
  var table = ' . json_encode($shipTable, $j) . ';
  var base = ' . json_encode(round($subtotal - $discount + round(($subtotal - $discount) * $taxRate / 100, 2), 2)) . ';
  var box = document.getElementById("shipOptions"), state = document.getElementById("ship_state");
  var sumShip = document.getElementById("sumShipping"), sumTotal = document.getElementById("sumTotal"), btn = document.getElementById("placeOrder");
  var canPay = !btn.disabled;

  function updateTotals() {
    var sel = box.querySelector("input:checked");
    var cost = sel ? parseFloat(sel.getAttribute("data-cost")) : null;
    sumShip.textContent = cost === null ? "—" : (cost === 0 ? "Free" : AFM.money(cost));
    sumTotal.textContent = AFM.money(base + (cost || 0));
    btn.disabled = !canPay || !sel;
  }
  function render() {
    var opts = table[state.value] || [];
    var want = parseInt(box.getAttribute("data-selected"), 10);
    box.innerHTML = "";
    if (!state.value) { box.innerHTML = "<p class=\"text-2\" style=\"margin:0\">Choose your state to see delivery options.</p>"; updateTotals(); return; }
    if (!opts.length) { box.innerHTML = "<div class=\"alert alert-error\" style=\"margin:0\">Sorry, we don’t ship to this state yet.</div>"; updateTotals(); return; }
    opts.forEach(function (o, i) {
      var label = document.createElement("label");
      label.className = "ship-option";
      var checked = want ? o.id === want : i === 0;
      label.innerHTML = "<input type=\"radio\" name=\"shipping_method\" value=\"" + o.id + "\" data-cost=\"" + o.cost + "\"" + (checked ? " checked" : "") + ">"
        + "<span class=\"grow\"><strong></strong><br><span class=\"small muted\"></span></span><strong>" + (o.cost === 0 ? "Free" : AFM.money(o.cost)) + "</strong>";
      label.querySelector(".grow strong").textContent = o.name;
      label.querySelector(".grow .muted").textContent = o.days + " after dispatch";
      box.appendChild(label);
    });
    if (!box.querySelector("input:checked")) box.querySelector("input").checked = true;
    updateTotals();
  }
  state.addEventListener("change", function () { box.setAttribute("data-selected", "0"); render(); });
  box.addEventListener("change", updateTotals);
  render();

  var saved = document.getElementById("savedAddress");
  if (saved) saved.addEventListener("change", function () {
    var o = saved.options[saved.selectedIndex];
    ["name", "line1", "line2", "city", "zip", "phone"].forEach(function (k) { document.getElementById("ship_" + k).value = o.getAttribute("data-" + k) || ""; });
    state.value = o.getAttribute("data-state"); state.dispatchEvent(new Event("change"));
  });
  document.getElementById("checkoutForm").addEventListener("submit", function () { btn.disabled = true; btn.textContent = "Processing…"; });
})();
</script>';
require __DIR__ . '/partials/footer.php';
