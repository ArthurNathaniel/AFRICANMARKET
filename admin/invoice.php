<?php
/**
 * Create an invoice for an order taken on WhatsApp. The result is an unpaid order
 * with a payment link the customer opens to pay by card on Stripe.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/shop.php';

$products = fetch_all("SELECT id, name, sku, price, stock_qty FROM products WHERE status = 'active' ORDER BY name");
$states = us_states();
$errors = [];

if (is_post()) {
    verify_csrf();
    $cust = [
        'name'  => input('ship_name'),
        'phone' => input('ship_phone'),
        'email' => strtolower(input('email')),
        'line1' => input('ship_line1'),
        'line2' => input('ship_line2'),
        'city'  => input('ship_city'),
        'state' => strtoupper(input('ship_state')),
        'zip'   => input('ship_zip'),
    ];
    $lines = [];
    foreach ((array) ($_POST['product_id'] ?? []) as $i => $pid) {
        $lines[] = [(int) $pid, max(0, min(999, (int) ($_POST['qty'][$i] ?? 0)))];
    }

    if (mb_strlen($cust['name']) < 2) $errors[] = 'Enter the customer\'s name.';
    if (strlen(preg_replace('/\D/', '', $cust['phone'])) < 10) $errors[] = 'Enter the customer\'s WhatsApp number, including the country code (e.g. +1 555 123 4567).';
    if ($cust['email'] !== '' && !filter_var($cust['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'That email address doesn\'t look right.';
    if ($cust['state'] !== '' && !isset($states[$cust['state']])) $errors[] = 'Choose a US state.';
    if ($cust['zip'] !== '' && !preg_match('/^\d{5}(-\d{4})?$/', $cust['zip'])) $errors[] = 'Enter a 5-digit ZIP code.';

    if (!$errors) {
        [$orderId, $errors] = create_invoice_order($cust, $lines, (float) input_decimal('shipping'), (float) input_decimal('discount'), input('admin_notes'));
    }
    if (!$errors) {
        $o = fetch_one('SELECT order_number FROM orders WHERE id = ?', [$orderId]);
        log_activity('invoice_create', "#{$o['order_number']} for {$cust['name']}");
        flash('success', 'Invoice created. Send it to the customer on WhatsApp below.');
        redirect("admin/orders.php?id=$orderId");
    }
}

$val = fn(string $k, string $fallback = '') => e($_POST[$k] ?? $fallback);
$rows = [];
foreach ((array) ($_POST['product_id'] ?? []) as $i => $pid) $rows[] = [(int) $pid, (int) ($_POST['qty'][$i] ?? 1)];
if (!$rows) $rows[] = [0, 1];

$pageTitle = 'New invoice';
$activeNav = 'orders';
$crumb = 'Sales';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p class="mt-0">Build an invoice for a WhatsApp order, then send the customer a link to pay by card.</p></div>
  <a class="btn" href="<?= url('admin/orders.php') ?>"><i class="bi bi-arrow-left"></i>All orders</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><i class="bi bi-exclamation-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>

<form method="post" class="grid grid-main" id="invoiceForm">
  <?= csrf_field() ?>
  <div class="grid" style="align-content:start">
    <div class="card">
      <div class="card-head"><h2>Items</h2></div>
      <div class="table-wrap"><table class="table" id="invItems">
        <thead><tr><th>Product</th><th class="num" style="width:110px">Qty</th><th class="num" style="width:120px">Total</th><th style="width:48px"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as [$pid, $q]): ?>
          <tr>
            <td><select name="product_id[]" required>
              <option value="">Choose a product…</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>" data-price="<?= e($p['price']) ?>" <?= $pid === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> — <?= money($p['price']) ?><?= $p['stock_qty'] <= 0 ? ' (sold out)' : '' ?></option>
              <?php endforeach; ?>
            </select></td>
            <td class="num"><input class="input" type="number" name="qty[]" min="1" max="999" value="<?= max(1, $q) ?>" style="text-align:right"></td>
            <td class="num strong" data-line>—</td>
            <td><button class="btn btn-ghost btn-icon" type="button" data-remove aria-label="Remove item"><i class="bi bi-trash3"></i></button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="card-body">
        <button class="btn btn-sm" type="button" id="addRow"><i class="bi bi-plus-lg"></i>Add item</button>
        <div class="form-row" style="margin-top:16px">
          <div class="field"><label for="shipping">Shipping fee</label><div class="input-group"><span class="addon">$</span><input class="input" id="shipping" name="shipping" inputmode="decimal" value="<?= $val('shipping', '0.00') ?>"></div></div>
          <div class="field"><label for="discount">Discount</label><div class="input-group"><span class="addon">$</span><input class="input" id="discount" name="discount" inputmode="decimal" value="<?= $val('discount', '0.00') ?>"></div></div>
        </div>
        <div class="totals">
          <div><span class="muted">Subtotal</span><span id="tSub">$0.00</span></div>
          <?php if ((float) setting('tax_rate', 0) > 0): ?><div><span class="muted">Sales tax (<?= e((float) setting('tax_rate')) ?>%)</span><span id="tTax">$0.00</span></div><?php endif; ?>
          <div class="grand"><span>Total</span><span id="tTotal">$0.00</span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid" style="align-content:start">
    <div class="card">
      <div class="card-head"><h2>Customer</h2></div>
      <div class="card-body">
        <div class="field"><label for="ship_name">Name <span class="req">*</span></label><input class="input" id="ship_name" name="ship_name" value="<?= $val('ship_name') ?>" required></div>
        <div class="field"><label for="ship_phone">WhatsApp number <span class="req">*</span></label><input class="input" type="tel" id="ship_phone" name="ship_phone" value="<?= $val('ship_phone') ?>" placeholder="+1 555 123 4567" required>
          <div class="small muted">Include the country code. US numbers can be 10 digits.</div></div>
        <div class="field"><label for="email">Email <span class="muted">(optional)</span></label><input class="input" type="email" id="email" name="email" value="<?= $val('email') ?>">
          <div class="small muted">If blank, the customer enters it when paying.</div></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h2>Shipping address</h2></div>
      <div class="card-body">
        <p class="small muted mt-0">Optional — if you leave it blank, the customer fills it in on the payment page.</p>
        <div class="field"><label for="ship_line1">Street address</label><input class="input" id="ship_line1" name="ship_line1" value="<?= $val('ship_line1') ?>"></div>
        <div class="field"><label for="ship_line2">Apartment, suite, etc.</label><input class="input" id="ship_line2" name="ship_line2" value="<?= $val('ship_line2') ?>"></div>
        <div class="field"><label for="ship_city">City</label><input class="input" id="ship_city" name="ship_city" value="<?= $val('ship_city') ?>"></div>
        <div class="form-row">
          <div class="field"><label for="ship_state">State</label>
            <select id="ship_state" name="ship_state"><option value="">—</option>
              <?php foreach ($states as $code => $name): ?><option value="<?= $code ?>" <?= ($_POST['ship_state'] ?? '') === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="ship_zip">ZIP</label><input class="input" id="ship_zip" name="ship_zip" value="<?= $val('ship_zip') ?>" inputmode="numeric"></div>
        </div>
        <div class="field"><label for="admin_notes">Internal notes</label><textarea class="input" id="admin_notes" name="admin_notes" rows="2" placeholder="Only visible to admins"><?= $val('admin_notes') ?></textarea></div>
      </div>
      <div class="card-foot form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-receipt"></i>Create invoice</button></div>
    </div>
  </div>
</form>
<?php
$pageScripts = '<script>
(function () {
  var body = document.querySelector("#invItems tbody"), taxRate = ' . json_encode((float) setting('tax_rate', 0)) . ';
  var money = function (v) { return "$" + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ","); };
  var num = function (id) { var v = parseFloat((document.getElementById(id).value || "").replace(/[$,]/g, "")); return isNaN(v) ? 0 : v; };
  function recalc() {
    var sub = 0;
    body.querySelectorAll("tr").forEach(function (tr) {
      var opt = tr.querySelector("select").selectedOptions[0], q = parseInt(tr.querySelector("input").value, 10) || 0;
      var line = opt && opt.value ? parseFloat(opt.getAttribute("data-price")) * q : 0;
      tr.querySelector("[data-line]").textContent = line ? money(line) : "—";
      sub += line;
    });
    var disc = Math.min(Math.max(0, num("discount")), sub), tax = Math.round((sub - disc) * taxRate) / 100;
    document.getElementById("tSub").textContent = money(sub);
    var t = document.getElementById("tTax"); if (t) t.textContent = money(tax);
    document.getElementById("tTotal").textContent = money(sub - disc + Math.max(0, num("shipping")) + tax);
  }
  document.getElementById("addRow").addEventListener("click", function () {
    var tr = body.querySelector("tr").cloneNode(true);
    tr.querySelector("select").value = ""; tr.querySelector("input").value = 1;
    body.appendChild(tr); tr.querySelector("select").focus(); recalc();
  });
  body.addEventListener("click", function (e) {
    var b = e.target.closest("[data-remove]");
    if (!b) return;
    if (body.querySelectorAll("tr").length > 1) b.closest("tr").remove();
    else { var tr = b.closest("tr"); tr.querySelector("select").value = ""; tr.querySelector("input").value = 1; }
    recalc();
  });
  document.getElementById("invoiceForm").addEventListener("input", recalc);
  document.getElementById("invoiceForm").addEventListener("change", recalc);
  recalc();
})();
</script>';
require __DIR__ . '/includes/footer.php';
