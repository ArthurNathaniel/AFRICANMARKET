<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    require_role('super_admin', 'admin');
    $action = input('action');

    if ($action === 'refund') {
        $p = fetch_one("SELECT p.*, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? AND p.status = 'completed'", [input_int('id')]);
        if (!$p) {
            flash('error', 'Only completed payments can be marked as refunded.');
        } else {
            db()->beginTransaction();
            q("UPDATE payments SET status = 'refunded' WHERE id = ?", [$p['id']]);
            q("UPDATE orders SET payment_status = 'refunded', status = 'refunded' WHERE id = ?", [$p['order_id']]);
            db()->commit();
            log_activity('payment_refund', "#{$p['order_number']} " . money($p['amount']));
            flash('success', "Payment for #{$p['order_number']} marked as refunded. Remember to issue the refund in your " . ucfirst($p['gateway']) . ' dashboard.');
        }
    }

    if ($action === 'manual') {
        $order = fetch_one('SELECT id, order_number, grand_total FROM orders WHERE order_number = ?', [ltrim(input('order_number'), '#')]);
        $amount = input_decimal('amount');
        if (!$order) {
            flash('error', 'No order with that number.');
        } elseif (!$amount || $amount <= 0) {
            flash('error', 'Enter the amount received.');
        } else {
            db()->beginTransaction();
            q("INSERT INTO payments (order_id, gateway, transaction_ref, amount, status) VALUES (?, 'manual', ?, ?, 'completed')",
              [$order['id'], input('reference') ?: null, $amount]);
            $paid = (float) fetch_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'completed'", [$order['id']]);
            if ($paid + 0.009 >= (float) $order['grand_total']) {
                q("UPDATE orders SET payment_status = 'paid', status = IF(status = 'pending', 'processing', status) WHERE id = ?", [$order['id']]);
            }
            db()->commit();
            log_activity('payment_manual', "#{$order['order_number']} " . money($amount));
            flash('success', 'Manual payment recorded for #' . $order['order_number'] . '.');
        }
    }
    redirect('admin/payments.php' . query_with([]));
}

$status  = input('status');
$gateway = input('gateway');
$search  = input('q');
$where = ['1=1']; $params = [];
if ($status !== '')  { $where[] = 'p.status = ?'; $params[] = $status; }
if ($gateway !== '') { $where[] = 'p.gateway = ?'; $params[] = $gateway; }
if ($search !== '')  { $where[] = '(o.order_number LIKE ? OR p.transaction_ref LIKE ? OR o.email LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
$whereSql = implode(' AND ', $where);

$pg = paginate((int) fetch_value("SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE $whereSql", $params), 25);
$payments = fetch_all(
    "SELECT p.*, o.order_number, o.email, o.ship_name FROM payments p JOIN orders o ON o.id = p.order_id
     WHERE $whereSql ORDER BY p.created_at DESC LIMIT {$pg['perPage']} OFFSET {$pg['offset']}", $params);

$d30 = date('Y-m-d H:i:s', strtotime('-30 days'));
$summary = fetch_one(
    "SELECT COALESCE(SUM(CASE WHEN status = 'completed' THEN amount END), 0) captured,
            COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount END), 0) refunded,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) failed,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) pending
     FROM payments WHERE created_at >= ?", [$d30]);
$byGateway = fetch_all("SELECT gateway, COUNT(*) n, SUM(amount) total FROM payments WHERE status = 'completed' AND created_at >= ? GROUP BY gateway ORDER BY total DESC", [$d30]);

$pageTitle = 'Payments';
$activeNav = 'payments';
$crumb = 'Sales';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p class="mt-0">Customer payments captured through Stripe and manual transfers. Last 30 days shown in the summary.</p></div>
  <?php if (admin_can('super_admin', 'admin')): ?><button class="btn btn-primary" data-modal="manualModal"><i class="bi bi-plus-lg"></i>Record manual payment</button><?php endif; ?>
</div>

<div class="grid grid-4">
  <div class="card stat"><div class="stat-label"><i class="bi bi-check2-circle"></i>Captured · 30 days</div><div class="stat-value"><?= money_compact($summary['captured']) ?></div>
    <div class="stat-delta"><?php foreach ($byGateway as $g): ?><?= e(ucfirst($g['gateway'])) ?> <?= money_compact($g['total']) ?> · <?php endforeach; ?><?= $byGateway ? '' : 'No payments' ?></div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-arrow-counterclockwise info"></i>Refunded · 30 days</div><div class="stat-value"><?= money_compact($summary['refunded']) ?></div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-x-octagon terra"></i>Failed · 30 days</div><div class="stat-value"><?= (int) $summary['failed'] ?></div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-hourglass-split gold"></i>Pending · 30 days</div><div class="stat-value"><?= (int) $summary['pending'] ?></div></div>
</div>

<div class="card mt">
  <form class="filters" method="get">
    <div class="search"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($search) ?>" placeholder="Order #, transaction reference or email…"></div>
    <select name="gateway" onchange="this.form.submit()"><option value="">All gateways</option><?php foreach (['stripe' => 'Stripe', 'manual' => 'Manual'] as $k => $l): ?><option value="<?= $k ?>" <?= $gateway === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <select name="status" onchange="this.form.submit()"><option value="">Any status</option><?php foreach (['completed', 'pending', 'failed', 'refunded'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th>Gateway</th><th>Reference</th><th>Status</th><th class="num">Amount</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="nowrap"><?= format_date($p['created_at'], 'M j, Y') ?><div class="small muted"><?= format_date($p['created_at'], 'g:i A') ?></div></td>
          <td><a class="strong" href="<?= url('admin/orders.php?id=' . $p['order_id']) ?>">#<?= e($p['order_number']) ?></a></td>
          <td><?= e($p['ship_name']) ?><div class="small muted"><?= e($p['email']) ?></div></td>
          <td><i class="bi <?= ['stripe' => 'bi-stripe', 'paypal' => 'bi-paypal', 'manual' => 'bi-bank'][$p['gateway']] ?>"></i> <?= e(ucfirst($p['gateway'])) ?></td>
          <td class="small"><code><?= e($p['transaction_ref'] ?: '—') ?></code></td>
          <td><?= status_badge($p['status']) ?></td>
          <td class="num strong"><?= money($p['amount']) ?></td>
          <td class="actions">
            <?php if ($p['status'] === 'completed' && admin_can('super_admin', 'admin')): ?>
              <form method="post" data-confirm="Mark this <?= e(money($p['amount'])) ?> payment as refunded? This also marks the order as refunded."><?= csrf_field() ?>
                <input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-danger">Refund</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="8" class="empty"><i class="bi bi-credit-card"></i>No payments found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($pg) ?>
</div>

<div class="modal-backdrop" id="manualModal" role="dialog" aria-modal="true" aria-labelledby="manualTitle">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="manual">
    <div class="card-head"><h3 id="manualTitle">Record manual payment</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="card-body">
      <p class="text-2 mt-0">For bank transfers, Zelle or cash payments received outside the online checkout.</p>
      <div class="field"><label for="m_order">Order number</label><input class="input" id="m_order" name="order_number" required placeholder="JAM-100045"></div>
      <div class="form-row">
        <div class="field"><label for="m_amount">Amount received</label><div class="input-group"><span class="addon">$</span><input class="input" id="m_amount" name="amount" inputmode="decimal" required></div></div>
        <div class="field"><label for="m_ref">Reference</label><input class="input" id="m_ref" name="reference" placeholder="Transfer ID"></div>
      </div>
    </div>
    <div class="card-foot form-actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit">Record payment</button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
