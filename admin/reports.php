<?php
require __DIR__ . '/includes/bootstrap.php';

// ---- Date range ----
$presets = [
    '30d' => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    '90d' => ['Last 90 days', date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    '12m' => ['Last 12 months', date('Y-m-01', strtotime('-11 months')), date('Y-m-d')],
    'ytd' => ['Year to date', date('Y-01-01'), date('Y-m-d')],
];
$range = input('range', '90d');
if (isset($presets[$range])) {
    [, $from, $to] = $presets[$range];
} else {
    $range = 'custom';
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : $presets['90d'][1];
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : date('Y-m-d');
}
if ($from > $to) [$from, $to] = [$to, $from];
$p = ["$from 00:00:00", "$to 23:59:59"];
$days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
$byMonth = $days > 62;

// ---- Time series: paid order revenue per bucket ----
$bucketSql = $byMonth ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';
$rows = fetch_all(
    "SELECT $bucketSql b, SUM(grand_total) total, COUNT(*) orders FROM orders
     WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ? GROUP BY b ORDER BY b", $p);
$idx = array_column($rows, null, 'b');
$series = [];
$cursor = strtotime($byMonth ? date('Y-m-01', strtotime($from)) : $from);
$end = strtotime($to);
while ($cursor <= $end) {
    $key = $byMonth ? date('Y-m', $cursor) : date('Y-m-d', $cursor);
    $series[] = [
        'label'  => $byMonth ? date('M Y', $cursor) : date('M j', $cursor),
        'total'  => round((float) ($idx[$key]['total'] ?? 0), 2),
        'orders' => (int) ($idx[$key]['orders'] ?? 0),
    ];
    $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
}

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"sales-$from-to-$to.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, [$byMonth ? 'Month' : 'Day', 'Paid orders', 'Revenue (USD)']);
    foreach ($series as $s) fputcsv($out, [$s['label'], $s['orders'], $s['total']]);
    exit;
}

// ---- KPIs ----
$k = fetch_one(
    "SELECT COUNT(*) orders, COALESCE(SUM(grand_total), 0) gross, COALESCE(SUM(shipping_total), 0) shipping,
            COALESCE(SUM(discount_total), 0) discounts, COALESCE(SUM(tax_total), 0) tax
     FROM orders WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?", $p);
$profit = fetch_one(
    "SELECT COALESCE(SUM(oi.line_total), 0) revenue, COALESCE(SUM(oi.unit_cost * oi.quantity), 0) cost,
            SUM(oi.unit_cost IS NULL) missing, COALESCE(SUM(oi.quantity), 0) units
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ?", $p);
$costedRevenue = (float) fetch_value(
    "SELECT COALESCE(SUM(oi.line_total), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ? AND oi.unit_cost IS NOT NULL", $p);
$grossProfit = $costedRevenue - (float) $profit['cost'];
$refunds = (float) fetch_value("SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE payment_status = 'refunded' AND created_at BETWEEN ? AND ?", $p);
$newCustomers = (int) fetch_value('SELECT COUNT(*) FROM customers WHERE created_at BETWEEN ? AND ?', $p);

$itemSales = "FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products pr ON pr.id = oi.product_id
              WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ?";
$byCategory = fetch_all(
    "SELECT COALESCE(pc.name, c.name, 'Uncategorized') name, SUM(oi.line_total) total, SUM(oi.quantity) units
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products pr ON pr.id = oi.product_id LEFT JOIN categories c ON c.id = pr.category_id LEFT JOIN categories pc ON pc.id = c.parent_id
     WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ?
     GROUP BY name ORDER BY total DESC LIMIT 8", $p);
$byCountry = fetch_all("SELECT COALESCE(pr.origin_country, 'Unknown') country, SUM(oi.line_total) total $itemSales GROUP BY country ORDER BY total DESC LIMIT 8", $p);
$topProducts = fetch_all(
    "SELECT oi.product_id, oi.product_name, SUM(oi.quantity) units, SUM(oi.line_total) total,
            SUM(CASE WHEN oi.unit_cost IS NOT NULL THEN oi.line_total - oi.unit_cost * oi.quantity END) profit
     $itemSales GROUP BY oi.product_id, oi.product_name ORDER BY total DESC LIMIT 10", $p);
$byState = fetch_all(
    "SELECT ship_state, COUNT(*) orders, SUM(grand_total) total FROM orders
     WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?
     GROUP BY ship_state ORDER BY total DESC LIMIT 10", $p);
$states = us_states();

$pageTitle = 'Reports';
$activeNav = 'reports';
$crumb = 'Operations';
$useCharts = true;
require __DIR__ . '/includes/header.php';
?>
<div class="card mb">
  <form class="filters" method="get" style="border-bottom:0">
    <?php foreach ($presets as $key => [$label]): ?>
      <a class="btn btn-sm <?= $range === $key ? 'btn-primary' : '' ?>" href="?range=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <span class="muted small" style="margin-left:8px">Custom:</span>
    <input type="hidden" name="range" value="custom">
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
    <button class="btn btn-sm" type="submit">Apply</button>
    <a class="btn btn-sm" style="margin-left:auto" href="<?= e(query_with(['export' => 'csv', 'range' => $range, 'from' => $from, 'to' => $to])) ?>"><i class="bi bi-download"></i>Export CSV</a>
  </form>
</div>

<div class="grid grid-4">
  <div class="card stat"><div class="stat-label"><i class="bi bi-currency-dollar"></i>Revenue</div><div class="stat-value"><?= money_compact($k['gross']) ?></div><div class="stat-delta"><?= format_date($from) ?> – <?= format_date($to) ?></div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-bag gold"></i>Paid orders</div><div class="stat-value"><?= number_format((int) $k['orders']) ?></div><div class="stat-delta">AOV <?= money($k['orders'] ? $k['gross'] / $k['orders'] : 0) ?> · <?= number_format((int) $profit['units']) ?> items</div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-graph-up-arrow terra"></i>Gross profit on items</div>
    <div class="stat-value"><?= $costedRevenue > 0 ? money_compact($grossProfit) : '—' ?></div>
    <div class="stat-delta"><?php if ($costedRevenue > 0): ?><?= round($grossProfit / $costedRevenue * 100) ?>% margin<?= $profit['missing'] ? ' · ' . (int) $profit['missing'] . ' lines without cost' : '' ?><?php else: ?>Add cost prices to products to see profit<?php endif; ?></div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-people info"></i>New customers</div><div class="stat-value"><?= number_format($newCustomers) ?></div><div class="stat-delta"><?= money_compact($k['discounts']) ?> discounts · <?= money_compact($refunds) ?> refunded</div></div>
</div>

<div class="card mt">
  <div class="card-head">
    <div><h2>Revenue</h2><div class="sub">Paid orders, <?= $byMonth ? 'by month' : 'by day' ?> (includes shipping and tax)</div></div>
  </div>
  <div class="card-body"><div class="chart-box" style="height:320px"><canvas id="revChart" role="img" aria-label="Bar chart of revenue over time"></canvas></div></div>
  <details class="table-view">
    <summary>View as table</summary>
    <div class="table-wrap"><table class="table small"><thead><tr><th><?= $byMonth ? 'Month' : 'Day' ?></th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead><tbody>
      <?php foreach ($series as $s): ?><tr><td><?= e($s['label']) ?></td><td class="num"><?= $s['orders'] ?></td><td class="num strong"><?= money($s['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </details>
</div>

<div class="grid grid-2 mt">
  <div class="card">
    <div class="card-head"><div><h2>Sales by category</h2><div class="sub">Item revenue, grouped by top-level category</div></div></div>
    <div class="card-body"><div class="chart-box sm"><canvas id="catChart" role="img" aria-label="Bar chart of sales by category"></canvas></div></div>
    <details class="table-view"><summary>View as table</summary>
      <table class="table small"><tbody><?php foreach ($byCategory as $c): ?><tr><td><?= e($c['name']) ?></td><td class="num"><?= (int) $c['units'] ?> units</td><td class="num"><?= money($c['total']) ?></td></tr><?php endforeach; ?></tbody></table>
    </details>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Sales by country of origin</h2><div class="sub">Where your best-selling products come from</div></div></div>
    <div class="card-body"><div class="chart-box sm"><canvas id="countryChart" role="img" aria-label="Bar chart of sales by country of origin"></canvas></div></div>
    <details class="table-view"><summary>View as table</summary>
      <table class="table small"><tbody><?php foreach ($byCountry as $c): ?><tr><td><?= e($c['country']) ?></td><td class="num"><?= money($c['total']) ?></td></tr><?php endforeach; ?></tbody></table>
    </details>
  </div>
</div>

<div class="grid grid-2 mt">
  <div class="card">
    <div class="card-head"><h2>Top products</h2></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Product</th><th class="num">Units</th><th class="num">Revenue</th><th class="num">Profit</th></tr></thead><tbody>
      <?php foreach ($topProducts as $t): ?>
        <tr><td><?php if ($t['product_id']): ?><a class="strong" href="<?= url('admin/product-edit.php?id=' . $t['product_id']) ?>"><?= e($t['product_name']) ?></a><?php else: ?><?= e($t['product_name']) ?><?php endif; ?></td>
          <td class="num"><?= (int) $t['units'] ?></td><td class="num strong"><?= money($t['total']) ?></td><td class="num"><?= $t['profit'] !== null ? money($t['profit']) : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$topProducts): ?><tr><td colspan="4" class="empty">No sales in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Top US states</h2><div class="sub">By order value</div></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>State</th><th class="num">Orders</th><th class="num">Revenue</th><th style="width:34%"></th></tr></thead><tbody>
      <?php $maxState = $byState ? max(array_column($byState, 'total')) : 1; foreach ($byState as $s): ?>
        <tr><td class="strong"><?= e($states[$s['ship_state']] ?? $s['ship_state']) ?></td><td class="num"><?= (int) $s['orders'] ?></td><td class="num"><?= money($s['total']) ?></td>
          <td><div style="height:10px;border-radius:0 4px 4px 0;background:#16794f;width:<?= round($s['total'] / $maxState * 100, 1) ?>%"></div></td></tr>
      <?php endforeach; ?>
      <?php if (!$byState): ?><tr><td colspan="4" class="empty">No sales in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>

<?php
$j = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$pageScripts = '<script>
AFM.ready(function () {
  var series = ' . json_encode($series, $j) . ';
  var cats = ' . json_encode($byCategory, $j) . ';
  var countries = ' . json_encode($byCountry, $j) . ';
  var green = "#16794f";

  new Chart(document.getElementById("revChart"), {
    type: "bar",
    data: {
      labels: series.map(function (s) { return s.label; }),
      datasets: [{ label: "Revenue", data: series.map(function (s) { return s.total; }), backgroundColor: green,
        borderRadius: 4, borderSkipped: "start", maxBarThickness: 24 }]
    },
    options: {
      interaction: { mode: "index", intersect: false },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: { beginAtZero: true, border: { display: false }, ticks: { callback: AFM.money, maxTicksLimit: 6 } }
      },
      plugins: { tooltip: { callbacks: {
        label: function (c) { return " Revenue " + AFM.moneyExact(c.parsed.y) + " · " + series[c.dataIndex].orders + " orders"; }
      } } }
    }
  });

  function hbar(id, rows, labelKey) {
    new Chart(document.getElementById(id), {
      type: "bar",
      data: { labels: rows.map(function (r) { return r[labelKey]; }),
        datasets: [{ label: "Sales", data: rows.map(function (r) { return +r.total; }), backgroundColor: green,
          borderRadius: 4, borderSkipped: "start", maxBarThickness: 24 }] },
      options: { indexAxis: "y",
        scales: { x: { beginAtZero: true, border: { display: false }, ticks: { callback: AFM.money, maxTicksLimit: 5 } }, y: { grid: { display: false } } },
        plugins: { tooltip: { callbacks: { label: function (c) { return " " + AFM.moneyExact(c.parsed.x); } } } } }
    });
  }
  hbar("catChart", cats, "name");
  hbar("countryChart", countries, "country");
});
</script>';
require __DIR__ . '/includes/footer.php';
