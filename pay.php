<?php
/**
 * Invoice payment page. Admins send this link (pay.php?o=ORDER&k=TOKEN) to customers
 * on WhatsApp; the customer reviews the invoice, fills in any missing contact or
 * shipping details, and pays by card on Stripe Checkout.
 */
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/order-status.php';

$order = fetch_one('SELECT * FROM orders WHERE order_number = ?', [input('o')]);
if (!$order || !hash_equals(invoice_token($order), input('k'))) {
    flash('error', 'That payment link is not valid. Please ask us for a new one on WhatsApp.');
    redirect('order-tracking.php');
}

// Lets the customer see order-success.php after paying, without an account.
if (!in_array((int) $order['id'], array_map('intval', $_SESSION['my_orders'] ?? []), true)) {
    $_SESSION['my_orders'][] = (int) $order['id'];
}
// If they already paid on Stripe but the webhook hasn't arrived, confirm it now.
if ($order['payment_status'] !== 'paid' && $order['stripe_session_id']) {
    try {
        if (stripe_confirm_session($order, (string) $order['stripe_session_id'])) {
            $order = fetch_one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
        }
    } catch (RuntimeException $ex) {
        error_log('Stripe invoice confirm failed: ' . $ex->getMessage());
    }
}
if ($order['payment_status'] === 'paid') {
    redirect('order-success.php?order=' . urlencode($order['order_number']));
}

$closed = in_array($order['status'], ['cancelled', 'refunded'], true) || $order['payment_status'] === 'refunded';
$needsEmail = $order['email'] === '';
$needsAddress = !$order['ship_line1'] || !$order['ship_city'] || !$order['ship_state'] || !$order['ship_zip'];
$canPay = !$closed && (stripe_enabled() || test_payments_enabled());
$states = us_states();
$errors = [];

if (is_post() && $canPay) {
    verify_csrf();
    if ($needsEmail) {
        $email = strtolower(input('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address for your receipt.';
    }
    if ($needsAddress) {
        $ship = [
            'name' => input('ship_name'), 'line1' => input('ship_line1'), 'line2' => input('ship_line2'),
            'city' => input('ship_city'), 'state' => strtoupper(input('ship_state')), 'zip' => input('ship_zip'),
        ];
        if (mb_strlen($ship['name']) < 2) $errors[] = 'Enter the recipient\'s full name.';
        if (mb_strlen($ship['line1']) < 3) $errors[] = 'Enter a street address.';
        if (mb_strlen($ship['city']) < 2) $errors[] = 'Enter a city.';
        if (!isset($states[$ship['state']])) $errors[] = 'Choose a US state.';
        if (!preg_match('/^\d{5}(-\d{4})?$/', $ship['zip'])) $errors[] = 'Enter a 5-digit ZIP code.';
    }

    if (!$errors) {
        if ($needsEmail) q('UPDATE orders SET email = ? WHERE id = ?', [$email, $order['id']]);
        if ($needsAddress) {
            q('UPDATE orders SET ship_name = ?, ship_line1 = ?, ship_line2 = ?, ship_city = ?, ship_state = ?, ship_zip = ? WHERE id = ?',
              [$ship['name'], $ship['line1'], $ship['line2'] ?: null, $ship['city'], $ship['state'], $ship['zip'], $order['id']]);
        }
        $order = fetch_one('SELECT * FROM orders WHERE id = ?', [$order['id']]);

        if (stripe_enabled()) {
            try {
                redirect(stripe_checkout_url($order, invoice_url($order) . '&cancelled=1'));
            } catch (RuntimeException $ex) {
                error_log('Stripe invoice checkout failed: ' . $ex->getMessage());
                $errors[] = 'We couldn\'t start the secure payment page. Please try again in a moment.'
                    . (stripe_test_mode() ? ' (Stripe test mode: ' . $ex->getMessage() . ')' : '');
            }
        } else {
            // Development only: simulate a successful card payment.
            mark_order_paid((int) $order['id'], 'manual', 'TEST-' . strtoupper(bin2hex(random_bytes(5))), (float) $order['grand_total']);
            redirect('order-success.php?order=' . urlencode($order['order_number']));
        }
    }
}

$val = fn(string $k, ?string $fallback = '') => e($_POST[$k] ?? $fallback ?? '');
$pageTitle = 'Invoice #' . $order['order_number'];
require __DIR__ . '/partials/header.php';
?>
<div class="wrap" style="max-width:960px">
  <div class="page-title"><h1>Invoice #<?= e($order['order_number']) ?></h1>
    <p>For <?= e($order['ship_name'] ?: 'you') ?> · issued <?= format_date($order['created_at'], 'F j, Y') ?></p></div>

  <?php if (input('cancelled') === '1' && !is_post()): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i><div>Payment was cancelled — you can try again whenever you're ready.</div></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>

  <form method="post" class="cart-layout" id="payForm" novalidate>
    <?= csrf_field() ?>
    <div style="display:grid;gap:20px;align-content:start">
      <section class="panel panel-body">
        <h2 style="font-size:18px">Your items</h2>
        <?php render_shipments((int) $order['id']); ?>
      </section>

      <?php if ($closed): ?>
        <div class="alert alert-error"><i class="bi bi-x-circle"></i><div>This invoice is no longer payable. Message us on WhatsApp if you think this is a mistake.</div></div>
      <?php else: ?>
        <?php if ($needsEmail): ?>
          <section class="panel panel-body">
            <h2 class="step-title">Contact</h2>
            <div class="field"><label for="email">Email for receipt &amp; tracking <span class="req">*</span></label>
              <input class="input" type="email" id="email" name="email" value="<?= $val('email') ?>" autocomplete="email" required></div>
          </section>
        <?php endif; ?>

        <?php if ($needsAddress): $selState = $_POST['ship_state'] ?? (string) $order['ship_state']; ?>
          <section class="panel panel-body">
            <h2 class="step-title">Shipping address</h2>
            <div class="field"><label for="ship_name">Full name <span class="req">*</span></label><input class="input" id="ship_name" name="ship_name" value="<?= $val('ship_name', $order['ship_name']) ?>" autocomplete="name" required></div>
            <div class="field"><label for="ship_line1">Street address <span class="req">*</span></label><input class="input" id="ship_line1" name="ship_line1" value="<?= $val('ship_line1', $order['ship_line1']) ?>" autocomplete="address-line1" required></div>
            <div class="field"><label for="ship_line2">Apartment, suite, etc.</label><input class="input" id="ship_line2" name="ship_line2" value="<?= $val('ship_line2', $order['ship_line2']) ?>" autocomplete="address-line2"></div>
            <div class="form-row three">
              <div class="field"><label for="ship_city">City <span class="req">*</span></label><input class="input" id="ship_city" name="ship_city" value="<?= $val('ship_city', $order['ship_city']) ?>" autocomplete="address-level2" required></div>
              <div class="field"><label for="ship_state">State <span class="req">*</span></label>
                <select id="ship_state" name="ship_state" autocomplete="address-level1" required>
                  <option value="">Select…</option>
                  <?php foreach ($states as $code => $name): ?><option value="<?= $code ?>" <?= $selState === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
                </select></div>
              <div class="field"><label for="ship_zip">ZIP <span class="req">*</span></label><input class="input" id="ship_zip" name="ship_zip" value="<?= $val('ship_zip', $order['ship_zip']) ?>" inputmode="numeric" autocomplete="postal-code" required></div>
            </div>
          </section>
        <?php else: ?>
          <section class="panel panel-body small">
            <strong>Shipping to</strong>
            <div class="text-2" style="margin-top:4px"><?= e($order['ship_name']) ?><br><?= e($order['ship_line1']) ?><br><?php if ($order['ship_line2']): ?><?= e($order['ship_line2']) ?><br><?php endif; ?><?= e($order['ship_city']) ?>, <?= e($order['ship_state']) ?> <?= e($order['ship_zip']) ?></div>
          </section>
        <?php endif; ?>

        <?php if (stripe_enabled() && stripe_test_mode()): ?>
          <div class="alert alert-warning" style="margin:0"><i class="bi bi-tools"></i><div>
            <strong>Stripe test mode:</strong> no real money is charged. Use one of these cards with any future expiry, any CVC and any ZIP:
            <?php foreach (STRIPE_TEST_CARDS as $card => $result): ?><div><code><?= e($card) ?></code> — <?= e($result) ?></div><?php endforeach; ?>
          </div></div>
        <?php elseif (test_payments_enabled()): ?>
          <div class="alert alert-warning" style="margin:0"><i class="bi bi-tools"></i><div><strong>Test mode:</strong> Stripe isn't configured yet, so paying simulates a successful payment.</div></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <aside class="panel" style="position:sticky;top:140px;align-self:start">
      <div class="panel-head"><h2>Amount due</h2></div>
      <div class="panel-body">
        <?php render_order_totals($order); ?>
        <?php if (!$closed): ?>
          <button class="btn btn-primary btn-lg btn-block" type="submit" id="payBtn" style="margin-top:14px" <?= $canPay ? '' : 'disabled' ?>>
            <i class="bi bi-lock-fill"></i> Pay <?= money($order['grand_total']) ?> securely</button>
          <?php if ($canPay): ?>
            <p class="small muted" style="text-align:center;margin:10px 0 0"><i class="bi bi-shield-lock"></i> Card details are entered on Stripe's secure page. Apple Pay and Google Pay supported where available.</p>
          <?php else: ?>
            <p class="small muted" style="text-align:center;margin:10px 0 0">Online payments are not available right now.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </aside>
  </form>
</div>
<?php
$pageScripts = '<script>
document.getElementById("payForm").addEventListener("submit", function () {
  var b = document.getElementById("payBtn"); if (b) { b.disabled = true; b.textContent = "Processing…"; }
});
</script>';
require __DIR__ . '/partials/footer.php';
