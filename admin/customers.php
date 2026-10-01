<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    $id = input_int('id');
    $action = input('action');
    if (in_array($action, ['suspend', 'activate'], true)) {
        $status = $action === 'suspend' ? 'suspended' : 'active';
        q('UPDATE customers SET status = ? WHERE id = ?', [$status, $id]);
        log_activity('customer_' . $action, "Customer #$id");
        flash('success', 'Customer account ' . ($status === 'active' ? 'reactivated.' : 'suspended.'));
    }
    redirect(input('back') === 'view' ? "admin/customers.php?view=$id" : 'admin/customers.php' . query_with([]));
}

$pageTitle = 'Customers';
$activeNav = 'customers';
$crumb = 'Sales';
$states = us_states();

/* ---- Customer profile ---- */
$view = input_int('view');
if ($view) {
    $c = fetch_one('SELECT * FROM customers WHERE id = ?', [$view]);
    if (!$c) { flash('error', 'Customer not found.'); redirect('admin/customers.php'); }
    $stats = fetch_one("SELECT COUNT(*) orders, COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN grand_total END), 0) spent, MAX(created_at) last_order FROM orders WHERE customer_id = ?", [$view]);
    $orders = fetch_all('SELECT id, order_number, grand_total, status, payment_status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20', [$view]);
    $reviews = fetch_all('SELECT r.rating, r.title, r.status, r.created_at, p.name product FROM reviews r JOIN products p ON p.id = r.product_id WHERE r.customer_id = ? ORDER BY r.created_at DESC LIMIT 5', [$view]);
    $pageTitle = $c['first_name'] . ' ' . $c['last_name'];
    require __DIR__ . '/includes/header.php'; ?>
    <div class="page-head">
      <div class="cell-main">
        <span class="avatar terra" style="width:52px;height:52px;font-size:17px"><?= e(initials($pageTitle)) ?></span>
        <div><?= status_badge($c['status']) ?><p class="mt-0">Customer since <?= format_date($c['created_at']) ?> · last sign-in <?= time_ago($c['last_login_at']) ?></p></div>
      </div>
      <form method="post" <?= $c['status'] === 'active' ? 'data-confirm="Suspend this customer? They will not be able to sign in or place orders."' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><input type="hidden" name="back" value="view">
        <?php if ($c['status'] === 'active'): ?>
          <button class="btn btn-danger" name="action" value="suspend"><i class="bi bi-slash-circle"></i>Suspend</button>
        <?php else: ?>
          <button class="btn btn-primary" name="action" value="activate"><i class="bi bi-check-circle"></i>Reactivate</button>
        <?php endif; ?>
      </form>
    </div>
    <div class="grid grid-3">
      <div class="card stat"><div class="stat-label"><i class="bi bi-bag"></i>Orders</div><div class="stat-value"><?= (int) $stats['orders'] ?></div></div>
      <div class="card stat"><div class="stat-label"><i class="bi bi-currency-dollar gold"></i>Lifetime spend</div><div class="stat-value"><?= money($stats['spent']) ?></div></div>
      <div class="card stat"><div class="stat-label"><i class="bi bi-clock-history info"></i>Last order</div><div class="stat-value" style="font-size:20px"><?= $stats['last_order'] ? format_date($stats['last_order']) : '—' ?></div></div>
    </div>
    <div class="grid grid-main mt">
      <div class="card">
        <div class="card-head"><h2>Order history</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Payment</th><th class="num">Total</th></tr></thead><tbody>
          <?php foreach ($orders as $o): ?>
            <tr><td><a class="strong" href="<?= url('admin/orders.php?id=' . $o['id']) ?>">#<?= e($o['order_number']) ?></a></td><td><?= format_date($o['created_at']) ?></td>
              <td><?= status_badge($o['status']) ?></td><td><?= status_badge($o['payment_status']) ?></td><td class="num"><?= money($o['grand_total']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?><tr><td colspan="5" class="empty">No orders yet.</td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
      <div class="grid" style="align-content:start">
        <div class="card">
          <div class="card-head"><h2>Contact &amp; address</h2></div>
          <div class="card-body"><dl class="dl">
            <dt>Email</dt><dd><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></dd>
            <dt>Phone</dt><dd><?= e($c['phone'] ?: '—') ?></dd>
            <dt>Address</dt><dd><?php if ($c['address_line1']): ?><?= e($c['address_line1']) ?><br><?php if ($c['address_line2']): ?><?= e($c['address_line2']) ?><br><?php endif; ?><?= e($c['city']) ?>, <?= e($c['state']) ?> <?= e($c['zip']) ?><?php else: ?>—<?php endif; ?></dd>
          </dl></div>
        </div>
        <div class="card">
          <div class="card-head"><h2>Recent reviews</h2></div>
          <ul class="list">
            <?php foreach ($reviews as $r): ?>
              <li><div class="grow"><div class="title"><?= e($r['product']) ?></div><div class="meta"><span class="stars"><?= str_repeat('★', (int) $r['rating']) ?><span class="off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></span> <?= e($r['title']) ?></div></div><?= status_badge($r['status']) ?></li>
            <?php endforeach; ?>
            <?php if (!$reviews): ?><li class="muted">No reviews written.</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---- Customer list ---- */
$search = input('q');
$status = input('status');
$state  = input('state');
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = "(CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)"; array_push($params, "%$search%", "%$search%", "%$search%"); }
if ($status !== '') { $where[] = 'c.status = ?'; $params[] = $status; }
if ($state !== '')  { $where[] = 'c.state = ?'; $params[] = $state; }
$whereSql = implode(' AND ', $where);

$pg = paginate((int) fetch_value("SELECT COUNT(*) FROM customers c WHERE $whereSql", $params), 25);
$customers = fetch_all(
    "SELECT c.*, COUNT(o.id) orders, COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.grand_total END), 0) spent, MAX(o.created_at) last_order
     FROM customers c LEFT JOIN orders o ON o.customer_id = c.id
     WHERE $whereSql
     GROUP BY c.id
     ORDER BY c.created_at DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}", $params);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><div><p class="mt-0">Shoppers across the United States.</p></div></div>
<div class="card">
  <form class="filters" method="get">
    <div class="search"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($search) ?>" placeholder="Search name, email or phone…"></div>
    <select name="state" onchange="this.form.submit()"><option value="">All states</option>
      <?php foreach ($states as $code => $name): ?><option value="<?= $code ?>" <?= $state === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
    <select name="status" onchange="this.form.submit()"><option value="">Any status</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option></select>
    <button class="btn" type="submit">Filter</button>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Customer</th><th>Location</th><th class="num">Orders</th><th class="num">Spent</th><th>Last order</th><th>Status</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($customers as $c): $name = $c['first_name'] . ' ' . $c['last_name']; ?>
        <tr>
          <td><div class="cell-main"><span class="avatar terra"><?= e(initials($name)) ?></span><div><a class="title" href="?view=<?= $c['id'] ?>"><?= e($name) ?></a><div class="meta"><?= e($c['email']) ?></div></div></div></td>
          <td><?= $c['city'] ? e($c['city']) . ', ' . e($c['state']) : '—' ?></td>
          <td class="num"><?= (int) $c['orders'] ?></td>
          <td class="num"><?= money($c['spent']) ?></td>
          <td><?= $c['last_order'] ? time_ago($c['last_order']) : '<span class="muted">Never</span>' ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td class="actions"><a class="btn btn-sm" href="?view=<?= $c['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$customers): ?><tr><td colspan="7" class="empty"><i class="bi bi-people"></i>No customers found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
