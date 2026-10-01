<?php
require __DIR__ . '/includes/bootstrap.php';

function pct_change(float $cur, float $prev): ?float
{
    if ($prev <= 0) return null;
    return ($cur - $prev) / $prev * 100;
}

function delta_html(?float $pct, string $period = 'vs previous 30 days'): string
{
    if ($pct === null) return '<div class="stat-delta">No data for ' . e(str_replace('vs ', '', $period)) . '</div>';
    $cls = $pct >= 0 ? 'up' : 'down';
    $arrow = $pct >= 0 ? '▲' : '▼';
    return '<div class="stat-delta"><span class="' . $cls . '">' . $arrow . ' ' . number_format(abs($pct), 1) . '%</span> ' . e($period) . '</div>';
}

$d30 = date('Y-m-d H:i:s', strtotime('-30 days'));
$d60 = date('Y-m-d H:i:s', strtotime('-60 days'));

$sales = fetch_one(
    "SELECT
        COALESCE(SUM(CASE WHEN created_at >= :d30 THEN grand_total END), 0) AS rev,
        COALESCE(SUM(CASE WHEN created_at <  :d30b THEN grand_total END), 0) AS rev_prev,
        COUNT(CASE WHEN created_at >= :d30c THEN 1 END) AS orders,
        COUNT(CASE WHEN created_at <  :d30d THEN 1 END) AS orders_prev
     FROM orders WHERE payment_status = 'paid' AND created_at >= :d60",
    ['d30' => $d30, 'd30b' => $d30, 'd30c' => $d30, 'd30d' => $d30, 'd60' => $d60]
);
$units = fetch_one(
    "SELECT
        COALESCE(SUM(CASE WHEN o.created_at >= :d30 THEN oi.quantity END), 0) AS cur,
        COALESCE(SUM(CASE WHEN o.created_at <  :d30b THEN oi.quantity END), 0) AS prev
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.payment_status = 'paid' AND o.created_at >= :d60",
    ['d30' => $d30, 'd30b' => $d30, 'd60' => $d60]
);
$liveProducts = (int) fetch_value("SELECT COUNT(*) FROM products WHERE status = 'active'");
$customersTotal = (int) fetch_value('SELECT COUNT(*) FROM customers');
$customersNew = (int) fetch_value('SELECT COUNT(*) FROM customers WHERE created_at >= ?', [$d30]);
$aov = $sales['orders'] > 0 ? $sales['rev'] / $sales['orders'] : 0;

// Daily revenue, last 30 days (missing days filled with 0)
$rows = fetch_all(
    "SELECT DATE(created_at) d, SUM(grand_total) total, COUNT(*) n
     FROM orders WHERE payment_status = 'paid' AND created_at >= ?
     GROUP BY DATE(created_at)",
    [date('Y-m-d 00:00:00', strtotime('-29 days'))]
);
$byDay = array_column($rows, null, 'd');
$daily = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily[] = ['label' => date('M j', strtotime($d)), 'total' => round((float) ($byDay[$d]['total'] ?? 0), 2), 'orders' => (int) ($byDay[$d]['n'] ?? 0)];
}

$statusOrder = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
$statusCounts = array_column(fetch_all('SELECT status, COUNT(*) n FROM orders GROUP BY status'), 'n', 'status');
$byStatus = [];
foreach ($statusOrder as $s) $byStatus[] = ['label' => ucfirst($s), 'n' => (int) ($statusCounts[$s] ?? 0)];

$recentOrders = fetch_all(
    "SELECT o.id, o.order_number, o.grand_total, o.status, o.payment_status, o.created_at, o.ship_name, o.ship_state,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) items
     FROM orders o ORDER BY o.created_at DESC LIMIT 8"
);

$topProducts = fetch_all(
    "SELECT oi.product_id, oi.product_name, p.image, p.stock_qty, SUM(oi.line_total) sales, SUM(oi.quantity) units
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE o.payment_status = 'paid' AND o.created_at >= ?
     GROUP BY oi.product_id, oi.product_name, p.image, p.stock_qty
     ORDER BY sales DESC LIMIT 5",
    [$d30]
);

$attention = [
    ['Orders to pack & ship', 'bi-box2', 'orders.php?status=processing', (int) fetch_value("SELECT COUNT(*) FROM orders WHERE status = 'processing' AND payment_status = 'paid'")],
    ['Shipped, awaiting delivery', 'bi-truck', 'orders.php?status=shipped', (int) fetch_value("SELECT COUNT(*) FROM orders WHERE status = 'shipped'")],
    ['Reviews to moderate', 'bi-star', 'reviews.php?status=pending', (int) fetch_value("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")],
    ['Sold-out products', 'bi-x-octagon', 'products.php?status=active&stock=out', (int) fetch_value("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_qty = 0")],
    ['Low-stock products (1–' . low_stock() . ')', 'bi-exclamation-triangle', 'products.php?status=active&stock=low', (int) fetch_value("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock_qty BETWEEN 1 AND ?", [low_stock()])],
    ['Unpaid checkouts (7 days)', 'bi-hourglass-split', 'orders.php?payment=unpaid', (int) fetch_value("SELECT COUNT(*) FROM orders WHERE payment_status = 'unpaid' AND created_at >= ?", [date('Y-m-d', strtotime('-7 days'))])],
];

$activity = fetch_all(
    'SELECT l.action, l.details, l.created_at, a.name FROM activity_log l LEFT JOIN admins a ON a.id = l.admin_id
     ORDER BY l.id DESC LIMIT 6'
);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$useCharts = true;
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <h2 style="font-size:22px;font-weight:800">Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $admin['name'])[0]) ?></h2>
    <p>Here's how the store performed over the last 30 days.</p>
  </div>
  <div class="form-actions">
    <a class="btn" href="<?= url('admin/reports.php') ?>"><i class="bi bi-bar-chart-line"></i>Full reports</a>
    <a class="btn btn-primary" href="<?= url('admin/product-add.php') ?>"><i class="bi bi-plus-lg"></i>Add product</a>
  </div>
</div>

<div class="grid grid-4">
  <div class="card stat">
    <div class="stat-label"><i class="bi bi-currency-dollar"></i>Gross sales · 30 days</div>
    <div class="stat-value"><?= money_compact($sales['rev']) ?></div>
    <?= delta_html(pct_change((float) $sales['rev'], (float) $sales['rev_prev'])) ?>
  </div>
  <div class="card stat">
    <div class="stat-label"><i class="bi bi-bag gold"></i>Paid orders · 30 days</div>
    <div class="stat-value"><?= number_format((int) $sales['orders']) ?></div>
    <?= delta_html(pct_change((float) $sales['orders'], (float) $sales['orders_prev'])) ?>
  </div>
  <div class="card stat">
    <div class="stat-label"><i class="bi bi-box-seam terra"></i>Items sold · 30 days</div>
    <div class="stat-value"><?= number_format((int) $units['cur']) ?></div>
    <?= delta_html(pct_change((float) $units['cur'], (float) $units['prev'])) ?>
  </div>
  <div class="card stat">
    <div class="stat-label"><i class="bi bi-receipt info"></i>Average order value</div>
    <div class="stat-value"><?= money($aov) ?></div>
    <div class="stat-delta"><?= number_format($liveProducts) ?> live products · <?= number_format($customersTotal) ?> customers (+<?= $customersNew ?>)</div>
  </div>
</div>

<div class="grid grid-main mt">
  <div class="card">
    <div class="card-head">
      <div><h2>Daily sales</h2><div class="sub">Paid orders, last 30 days</div></div>
    </div>
    <div class="card-body"><div class="chart-box"><canvas id="salesChart" aria-label="Daily sales line chart" role="img"></canvas></div></div>
    <details class="table-view">
      <summary>View as table</summary>
      <table class="table small"><thead><tr><th>Day</th><th class="num">Orders</th><th class="num">Sales</th></tr></thead><tbody>
        <?php foreach (array_reverse($daily) as $r): ?>
          <tr><td><?= e($r['label']) ?></td><td class="num"><?= $r['orders'] ?></td><td class="num"><?= money($r['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </details>
  </div>

  <div class="card">
    <div class="card-head"><h2>Needs attention</h2></div>
    <div class="attention">
      <?php foreach ($attention as [$label, $icon, $href, $n]): ?>
        <a href="<?= url('admin/' . $href) ?>">
          <i class="bi <?= $icon ?> muted"></i><?= e($label) ?>
          <span class="n<?= $n === 0 ? ' zero' : '' ?>"><?= $n ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-main mt">
  <div class="card">
    <div class="card-head">
      <div><h2>Recent orders</h2></div>
      <a class="btn btn-sm" href="<?= url('admin/orders.php') ?>">View all</a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Payment</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td><a class="strong" href="<?= url('admin/orders.php?id=' . $o['id']) ?>">#<?= e($o['order_number']) ?></a>
              <div class="small muted"><?= time_ago($o['created_at']) ?> · <?= (int) $o['items'] ?> item<?= $o['items'] == 1 ? '' : 's' ?></div></td>
            <td><?= e($o['ship_name']) ?><div class="small muted"><?= e($o['ship_state']) ?></div></td>
            <td><?= status_badge($o['status']) ?></td>
            <td><?= status_badge($o['payment_status']) ?></td>
            <td class="num strong"><?= money($o['grand_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recentOrders): ?><tr><td colspan="5" class="empty"><i class="bi bi-bag"></i>No orders yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h2>Orders by status</h2><div class="sub">All time</div></div></div>
    <div class="card-body"><div class="chart-box sm"><canvas id="statusChart" aria-label="Orders by status bar chart" role="img"></canvas></div></div>
  </div>
</div>

<div class="grid grid-2 mt">
  <div class="card">
    <div class="card-head"><div><h2>Top products</h2><div class="sub">By sales, last 30 days</div></div>
      <a class="btn btn-sm" href="<?= url('admin/reports.php') ?>">Reports</a></div>
    <ul class="list">
      <?php foreach ($topProducts as $tp): ?>
        <li>
          <img class="thumb" src="<?= e(image_url($tp['image'])) ?>" alt="">
          <div class="grow">
            <?php if ($tp['product_id']): ?><a class="title" href="<?= url('admin/product-edit.php?id=' . $tp['product_id']) ?>"><?= e($tp['product_name']) ?></a><?php else: ?><span class="title"><?= e($tp['product_name']) ?></span><?php endif; ?>
            <div class="meta"><?= number_format((int) $tp['units']) ?> sold<?= $tp['stock_qty'] !== null ? ' · ' . (int) $tp['stock_qty'] . ' in stock' : '' ?></div>
          </div>
          <div class="num strong"><?= money($tp['sales']) ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$topProducts): ?><li class="empty">No sales in this period.</li><?php endif; ?>
    </ul>
  </div>

  <div class="card">
    <div class="card-head"><h2>Recent admin activity</h2></div>
    <ul class="list">
      <?php foreach ($activity as $a): ?>
        <li>
          <span class="avatar"><?= e(initials($a['name'] ?? 'System')) ?></span>
          <div class="grow">
            <div class="title"><?= e($a['name'] ?? 'System') ?> <span class="muted" style="font-weight:500">· <?= e(str_replace('_', ' ', $a['action'])) ?></span></div>
            <div class="meta"><?= e($a['details']) ?></div>
          </div>
          <div class="small muted nowrap"><?= time_ago($a['created_at']) ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$activity): ?><li class="empty">No activity yet.</li><?php endif; ?>
    </ul>
  </div>
</div>

<?php
$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$pageScripts = '<script>
AFM.ready(function () {
  var daily = ' . json_encode($daily, $json) . ';
  var byStatus = ' . json_encode($byStatus, $json) . ';
  var green = "#16794f";

  new Chart(document.getElementById("salesChart"), {
    type: "line",
    data: {
      labels: daily.map(function (d) { return d.label; }),
      datasets: [{
        label: "Sales",
        data: daily.map(function (d) { return d.total; }),
        borderColor: green, backgroundColor: "rgba(22,121,79,.10)", fill: true,
        borderWidth: 2, cubicInterpolationMode: "monotone", pointRadius: 0, pointHoverRadius: 5,
        pointHoverBackgroundColor: green, pointHoverBorderColor: "#fff", pointHoverBorderWidth: 2,
        borderCapStyle: "round", borderJoinStyle: "round"
      }]
    },
    options: {
      interaction: { mode: "index", intersect: false },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
        y: { beginAtZero: true, border: { display: false }, ticks: { callback: AFM.money, maxTicksLimit: 5 } }
      },
      plugins: { tooltip: { callbacks: {
        label: function (c) { return " Sales " + AFM.moneyExact(c.parsed.y) + "  ·  " + daily[c.dataIndex].orders + " orders"; }
      } } }
    }
  });

  new Chart(document.getElementById("statusChart"), {
    type: "bar",
    data: {
      labels: byStatus.map(function (d) { return d.label; }),
      datasets: [{ label: "Orders", data: byStatus.map(function (d) { return d.n; }),
        backgroundColor: green, borderRadius: 4, borderSkipped: "start", maxBarThickness: 24 }]
    },
    options: {
      indexAxis: "y",
      scales: {
        x: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } },
        y: { grid: { display: false } }
      }
    }
  });
});
</script>';
require __DIR__ . '/includes/footer.php';
