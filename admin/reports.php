<?php
/**
 * Financial reports: profit & loss (with prior-period comparison), cash received,
 * sales tax owed, unpaid orders, inventory value, and a period-by-period breakdown.
 *
 * Basis: sales are recognised on the ORDER date for orders that were paid (and later
 * refunded orders are shown as a deduction). Cash figures use the PAYMENT date.
 * Sales tax collected is a liability (owed to the state), so it is kept out of revenue.
 */
require __DIR__ . '/includes/bootstrap.php';

// ---- Date range ----
$presets = [
    '30d' => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'mtd' => ['This month', date('Y-m-01'), date('Y-m-d')],
    'lm'  => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '90d' => ['Last 90 days', date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    'ytd' => ['Year to date', date('Y-01-01'), date('Y-m-d')],
    '12m' => ['Last 12 months', date('Y-m-01', strtotime('-11 months')), date('Y-m-d')],
];
$range = input('range', 'mtd');
if (isset($presets[$range])) {
    [, $from, $to] = $presets[$range];
} else {
    $range = 'custom';
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : $presets['mtd'][1];
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : date('Y-m-d');
}
if ($from > $to) [$from, $to] = [$to, $from];
$days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
$prevTo = date('Y-m-d', strtotime("$from -1 day"));
$prevFrom = date('Y-m-d', strtotime("$prevTo -" . ($days - 1) . ' days'));
$byMonth = $days > 62;

$feePct = (float) setting('card_fee_percent', '2.9');
$feeFixed = (float) setting('card_fee_fixed', '0.30');

/** Profit & loss figures for a date range (order-date basis), plus cash and fees. */
function pnl(string $from, string $to, float $feePct, float $feeFixed): array
{
    $p = ["$from 00:00:00", "$to 23:59:59"];
    $o = fetch_one(
        "SELECT
            SUM(payment_status = 'paid') paid_orders,
            SUM(payment_status = 'refunded') refunded_orders,
            COALESCE(SUM(subtotal), 0) gross_sales,
            COALESCE(SUM(discount_total), 0) discounts,
            COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN subtotal - discount_total END), 0) refunds,
            COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN grand_total END), 0) refunds_total,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN shipping_total END), 0) shipping,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN tax_total END), 0) tax,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN grand_total END), 0) collected_on_orders
         FROM orders WHERE payment_status IN ('paid', 'refunded') AND created_at BETWEEN ? AND ?", $p);
    $c = fetch_one(
        "SELECT COALESCE(SUM(oi.unit_cost * oi.quantity), 0) cogs, SUM(oi.unit_cost IS NULL) missing_cost, COALESCE(SUM(oi.quantity), 0) units
         FROM order_items oi JOIN orders o ON o.id = oi.order_id
         WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ?", $p);
    // Stripe keeps its fee even when a payment is later refunded.
    $f = fetch_one("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) amt FROM payments
                    WHERE gateway = 'stripe' AND status IN ('completed', 'refunded') AND created_at BETWEEN ? AND ?", $p);

    $r = array_map('floatval', $o + $c);
    $r['net_sales'] = $r['gross_sales'] - $r['discounts'] - $r['refunds'];
    $r['revenue'] = $r['net_sales'] + $r['shipping'];
    $r['gross_profit'] = $r['revenue'] - $r['cogs'];
    $r['card_fees'] = round($f['amt'] * $feePct / 100 + $f['n'] * $feeFixed, 2);
    $r['card_payments'] = (int) $f['n'];
    $r['profit'] = $r['gross_profit'] - $r['card_fees'];
    $r['margin'] = $r['revenue'] > 0 ? $r['gross_profit'] / $r['revenue'] * 100 : null;
    $r['aov'] = $r['paid_orders'] > 0 ? $r['collected_on_orders'] / $r['paid_orders'] : 0;
    return $r;
}

$cur = pnl($from, $to, $feePct, $feeFixed);
$prev = pnl($prevFrom, $prevTo, $feePct, $feeFixed);
$P = ["$from 00:00:00", "$to 23:59:59"];

// ---- Cash received (payment-date basis) ----
$cash = fetch_all(
    "SELECT gateway, status, COUNT(*) n, COALESCE(SUM(amount), 0) amt FROM payments
     WHERE created_at BETWEEN ? AND ? GROUP BY gateway, status", $P);
$cashIn = ['stripe' => 0.0, 'manual' => 0.0];
$cashCount = ['stripe' => 0, 'manual' => 0];
$cashRefunded = 0.0;
foreach ($cash as $c) {
    $g = $c['gateway'] === 'stripe' ? 'stripe' : 'manual';
    $cashIn[$g] += (float) $c['amt']; $cashCount[$g] += (int) $c['n'];
    if ($c['status'] === 'refunded') $cashRefunded += (float) $c['amt'];
}
$cashGross = $cashIn['stripe'] + $cashIn['manual'];
$cashNet = $cashGross - $cashRefunded - $cur['card_fees'];

// ---- Balances ----
$unpaid = fetch_one("SELECT COUNT(*) n, COALESCE(SUM(grand_total), 0) amt FROM orders
                     WHERE payment_status = 'unpaid' AND status NOT IN ('cancelled', 'refunded') AND created_at BETWEEN ? AND ?", $P);
$unpaidAll = fetch_one("SELECT COUNT(*) n, COALESCE(SUM(grand_total), 0) amt FROM orders WHERE payment_status = 'unpaid' AND status NOT IN ('cancelled', 'refunded')");
$inv = fetch_one("SELECT COALESCE(SUM(stock_qty), 0) units, COALESCE(SUM(stock_qty * cost_price), 0) at_cost, COALESCE(SUM(stock_qty * price), 0) at_retail,
                         SUM(cost_price IS NULL AND stock_qty > 0) no_cost, COUNT(*) skus
                  FROM products WHERE status <> 'inactive' AND stock_qty > 0");

// ---- Period-by-period breakdown ----
$bucket = $byMonth ? "DATE_FORMAT(o.created_at, '%Y-%m')" : 'DATE(o.created_at)';
$bo = array_column(fetch_all(
    "SELECT $bucket b, SUM(o.payment_status = 'paid') orders, SUM(o.subtotal) gross, SUM(o.discount_total) disc,
            SUM(CASE WHEN o.payment_status = 'refunded' THEN o.subtotal - o.discount_total ELSE 0 END) refunds,
            SUM(CASE WHEN o.payment_status = 'paid' THEN o.shipping_total ELSE 0 END) ship,
            SUM(CASE WHEN o.payment_status = 'paid' THEN o.tax_total ELSE 0 END) tax
     FROM orders o WHERE o.payment_status IN ('paid', 'refunded') AND o.created_at BETWEEN ? AND ? GROUP BY b", $P), null, 'b');
$bc = array_column(fetch_all(
    "SELECT $bucket b, SUM(oi.unit_cost * oi.quantity) cogs FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ? GROUP BY b", $P), 'cogs', 'b');
$series = [];
$cursor = strtotime($byMonth ? date('Y-m-01', strtotime($from)) : $from);
while ($cursor <= strtotime($to)) {
    $key = $byMonth ? date('Y-m', $cursor) : date('Y-m-d', $cursor);
    $r = $bo[$key] ?? [];
    $net = (float) ($r['gross'] ?? 0) - (float) ($r['disc'] ?? 0) - (float) ($r['refunds'] ?? 0);
    $rev = $net + (float) ($r['ship'] ?? 0);
    $cogs = (float) ($bc[$key] ?? 0);
    $series[] = [
        'label' => $byMonth ? date('M Y', $cursor) : date('M j', $cursor),
        'orders' => (int) ($r['orders'] ?? 0), 'gross' => (float) ($r['gross'] ?? 0), 'disc' => (float) ($r['disc'] ?? 0),
        'refunds' => (float) ($r['refunds'] ?? 0), 'net' => round($net, 2), 'ship' => (float) ($r['ship'] ?? 0),
        'revenue' => round($rev, 2), 'cogs' => round($cogs, 2), 'gp' => round($rev - $cogs, 2), 'tax' => (float) ($r['tax'] ?? 0),
    ];
    $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
}

// ---- Statement lines (shared by the page and the CSV export) ----
$lines = [
    ['Gross sales (products)', 'gross_sales', 'line'],
    ['Less: discounts & coupons', 'discounts', 'less'],
    ['Less: refunds & returns', 'refunds', 'less'],
    ['Net sales', 'net_sales', 'sub'],
    ['Shipping charged to customers', 'shipping', 'line'],
    ['Total revenue', 'revenue', 'sub'],
    ['Less: cost of goods sold', 'cogs', 'less'],
    ['Gross profit', 'gross_profit', 'sub'],
    ['Less: card processing fees (estimated)', 'card_fees', 'less'],
    ['Profit after card fees', 'profit', 'total'],
];

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"financial-report-$from-to-$to.csv\"");
    $out = fopen('php://output', 'w');
    $csv = fn(array $row) => fputcsv($out, $row, ',', '"', ''); // explicit escape: no PHP 8.4 deprecation notice
    $csv([setting('store_name', APP_NAME) . ' — Profit & loss', "$from to $to", "Previous: $prevFrom to $prevTo"]);
    $csv(['Line', 'This period (USD)', 'Previous period (USD)']);
    foreach ($lines as [$label, $key, $kind]) {
        $sign = $kind === 'less' ? -1 : 1;
        $csv([$label, number_format($sign * $cur[$key], 2, '.', ''), number_format($sign * $prev[$key], 2, '.', '')]);
    }
    $csv(['Sales tax collected (owed to the state, not revenue)', number_format($cur['tax'], 2, '.', ''), number_format($prev['tax'], 2, '.', '')]);
    $csv([]);
    $csv([$byMonth ? 'Month' : 'Day', 'Paid orders', 'Gross sales', 'Discounts', 'Refunds', 'Net sales', 'Shipping', 'Total revenue', 'COGS', 'Gross profit', 'Sales tax']);
    $n = fn($v) => number_format(abs($v) < 0.005 ? 0 : $v, 2, '.', '');
    foreach ($series as $s) $csv([$s['label'], $s['orders'], $n($s['gross']), $n(-$s['disc']), $n(-$s['refunds']), $n($s['net']), $n($s['ship']), $n($s['revenue']), $n(-$s['cogs']), $n($s['gp']), $n($s['tax'])]);
    exit;
}

// ---- Existing breakdowns ----
$itemSales = "FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products pr ON pr.id = oi.product_id
              WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ?";
$byCategory = fetch_all(
    "SELECT COALESCE(pc.name, c.name, 'Uncategorized') name, SUM(oi.line_total) total, SUM(oi.quantity) units
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products pr ON pr.id = oi.product_id LEFT JOIN categories c ON c.id = pr.category_id LEFT JOIN categories pc ON pc.id = c.parent_id
     WHERE o.payment_status = 'paid' AND o.created_at BETWEEN ? AND ?
     GROUP BY name ORDER BY total DESC LIMIT 8", $P);
$byCountry = fetch_all("SELECT COALESCE(pr.origin_country, 'Unknown') country, SUM(oi.line_total) total $itemSales GROUP BY country ORDER BY total DESC LIMIT 8", $P);
$topProducts = fetch_all(
    "SELECT oi.product_id, oi.product_name, SUM(oi.quantity) units, SUM(oi.line_total) total,
            SUM(CASE WHEN oi.unit_cost IS NOT NULL THEN oi.line_total - oi.unit_cost * oi.quantity END) profit
     $itemSales GROUP BY oi.product_id, oi.product_name ORDER BY total DESC LIMIT 10", $P);
$byState = fetch_all(
    "SELECT ship_state, shipping_method, COUNT(*) orders, SUM(grand_total) total FROM orders
     WHERE payment_status = 'paid' AND created_at BETWEEN ? AND ?
     GROUP BY ship_state, shipping_method ORDER BY total DESC LIMIT 10", $P);
$states = us_states();

// ---- Formatting helpers (accounting style: negatives in brackets) ----
$acct = fn(float $v) => $v < -0.004 ? '(' . money(abs($v)) . ')' : money($v);
$chg = function (float $now, float $was, bool $goodWhenUp = true): string {
    if (abs($was) < 0.005) return abs($now) < 0.005 ? '<span class="muted">—</span>' : '<span class="muted">new</span>';
    $pct = ($now - $was) / abs($was) * 100;
    $good = $goodWhenUp ? $pct >= 0 : $pct <= 0;
    return '<span class="' . ($good ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲ ' : '▼ ') . number_format(abs($pct), abs($pct) < 10 ? 1 : 0) . '%</span>';
};

$pageTitle = 'Reports';
$activeNav = 'reports';
$crumb = 'Operations';
$useCharts = true;
require __DIR__ . '/includes/header.php';

// ---- Letterhead details for the printed report ----
$lhName = setting('store_name', APP_NAME);
$lhAddress = trim((string) setting('business_address', ''));
$lhWeb = preg_replace('#^https?://#', '', rtrim(preg_match('#^https?://#', url('/')) ? url('/') : ($_SERVER['HTTP_HOST'] ?? ''), '/'));
$preparedBy = $admin['name'] ?? '';
?>
<!-- ============ Printed report (letterhead) — hidden on screen, replaces the dashboard when printing ============ -->
<div class="print-doc">
  <header class="lh">
    <div class="lh-brand">
      <img src="<?= asset('img/logo-badge-200.png') ?>" alt="">
      <div>
        <div class="lh-name"><?= e($lhName) ?></div>
        <div class="lh-tag">African groceries · Delivered across the USA</div>
      </div>
    </div>
    <div class="lh-contact">
      <?php if ($lhAddress !== ''): ?><div><?= nl2br(e($lhAddress)) ?></div><?php endif; ?>
      <?php if ($ph = support_phones()): ?><div>Tel: <?= e(implode(' · ', $ph)) ?></div><?php endif; ?>
      <?php if ($em = support_emails()): ?><div><?= e(implode(' · ', $em)) ?></div><?php endif; ?>
      <div><?= e($lhWeb) ?></div>
    </div>
  </header>
  <div class="lh-rule"></div>

  <section class="doc-title">
    <div class="doc-kicker">Financial report</div>
    <h1>Statement of Profit and Loss</h1>
    <div class="doc-period">For the period <?= format_date($from, 'F j, Y') ?> to <?= format_date($to, 'F j, Y') ?></div>
    <table class="doc-meta">
      <tr><td>Comparative period</td><td><?= format_date($prevFrom, 'F j, Y') ?> to <?= format_date($prevTo, 'F j, Y') ?></td>
          <td>Currency</td><td>US Dollars (USD)</td></tr>
      <tr><td>Basis</td><td>Order date (sales); payment date (cash)</td>
          <td>Prepared</td><td><?= date('F j, Y \a\t g:i A') ?></td></tr>
    </table>
  </section>

  <h2 class="doc-h">1. Statement of profit and loss</h2>
  <table class="doc-table">
    <thead><tr><th></th><th class="num">Current period<br><span><?= format_date($from, 'M j') ?> – <?= format_date($to, 'M j, Y') ?></span></th><th class="num">Comparative<br><span><?= format_date($prevFrom, 'M j') ?> – <?= format_date($prevTo, 'M j, Y') ?></span></th></tr></thead>
    <tbody>
    <?php foreach ($lines as [$label, $key, $kind]): $sign = $kind === 'less' ? -1 : 1; ?>
      <tr class="d-<?= $kind ?>"><td><?= e($label) ?></td><td class="num"><?= $acct($sign * $cur[$key]) ?></td><td class="num"><?= $acct($sign * $prev[$key]) ?></td></tr>
      <?php if ($key === 'gross_profit'): ?>
        <tr class="d-note"><td>Gross margin</td><td class="num"><?= $cur['margin'] !== null ? number_format($cur['margin'], 1) . '%' : '—' ?></td><td class="num"><?= $prev['margin'] !== null ? number_format($prev['margin'], 1) . '%' : '—' ?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <table class="doc-kv">
    <tr><td>Paid orders</td><td class="num"><?= number_format((int) $cur['paid_orders']) ?></td><td class="num"><?= number_format((int) $prev['paid_orders']) ?></td></tr>
    <tr><td>Items sold</td><td class="num"><?= number_format((int) $cur['units']) ?></td><td class="num"><?= number_format((int) $prev['units']) ?></td></tr>
    <tr><td>Average order value (incl. shipping &amp; tax)</td><td class="num"><?= money($cur['aov']) ?></td><td class="num"><?= money($prev['aov']) ?></td></tr>
  </table>

  <div class="doc-two">
    <div>
      <h2 class="doc-h">2. Cash receipts</h2>
      <table class="doc-table compact">
        <tr><td>Card payments (Stripe) — <?= $cashCount['stripe'] ?></td><td class="num"><?= money($cashIn['stripe']) ?></td></tr>
        <tr><td>Manual payments (cash, Zelle) — <?= $cashCount['manual'] ?></td><td class="num"><?= money($cashIn['manual']) ?></td></tr>
        <tr class="d-less"><td>Less: refunds paid out</td><td class="num"><?= $acct(-$cashRefunded) ?></td></tr>
        <tr class="d-less"><td>Less: card processing fees (est.)</td><td class="num"><?= $acct(-$cur['card_fees']) ?></td></tr>
        <tr class="d-total"><td>Net cash received</td><td class="num"><?= $acct($cashNet) ?></td></tr>
      </table>
    </div>
    <div>
      <h2 class="doc-h">3. Balances</h2>
      <table class="doc-table compact">
        <tr><td>Sales tax payable (collected, owed to the state)</td><td class="num"><?= money($cur['tax']) ?></td></tr>
        <tr><td>Unpaid orders in period — <?= (int) $unpaid['n'] ?></td><td class="num"><?= money($unpaid['amt']) ?></td></tr>
        <tr><td>All unpaid orders at report date — <?= (int) $unpaidAll['n'] ?></td><td class="num"><?= money($unpaidAll['amt']) ?></td></tr>
      </table>
      <h2 class="doc-h">4. Inventory valuation <span>(at <?= date('M j, Y') ?>)</span></h2>
      <table class="doc-table compact">
        <tr><td>Units on hand — <?= number_format((int) $inv['units']) ?> (<?= (int) $inv['skus'] ?> products)</td><td class="num"></td></tr>
        <tr><td>Value at cost</td><td class="num"><?= money($inv['at_cost']) ?></td></tr>
        <tr><td>Value at retail</td><td class="num"><?= money($inv['at_retail']) ?></td></tr>
        <tr class="d-total"><td>Unrealised margin in stock</td><td class="num"><?= $acct((float) $inv['at_retail'] - (float) $inv['at_cost']) ?></td></tr>
      </table>
    </div>
  </div>

  <h2 class="doc-h">5. Breakdown by <?= $byMonth ? 'month' : 'day' ?></h2>
  <table class="doc-table grid-tbl">
    <thead><tr><th><?= $byMonth ? 'Month' : 'Day' ?></th><th class="num">Orders</th><th class="num">Gross sales</th><th class="num">Discounts</th><th class="num">Refunds</th><th class="num">Net sales</th><th class="num">Shipping</th><th class="num">Revenue</th><th class="num">COGS</th><th class="num">Gross profit</th><th class="num">Sales tax</th></tr></thead>
    <tbody>
    <?php $PT = array_fill_keys(['orders', 'gross', 'disc', 'refunds', 'net', 'ship', 'revenue', 'cogs', 'gp', 'tax'], 0); $rowsShown = 0; ?>
    <?php foreach ($series as $s): if (!$s['orders'] && !$s['refunds']) continue; $rowsShown++; foreach ($PT as $k => $_) $PT[$k] += $s[$k]; ?>
      <tr><td><?= e($s['label']) ?></td><td class="num"><?= $s['orders'] ?></td><td class="num"><?= money($s['gross']) ?></td><td class="num"><?= $acct(-$s['disc']) ?></td><td class="num"><?= $acct(-$s['refunds']) ?></td><td class="num"><?= money($s['net']) ?></td><td class="num"><?= money($s['ship']) ?></td><td class="num"><?= money($s['revenue']) ?></td><td class="num"><?= $acct(-$s['cogs']) ?></td><td class="num"><?= $acct($s['gp']) ?></td><td class="num"><?= money($s['tax']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rowsShown): ?><tr><td colspan="11">No sales in this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= $PT['orders'] ?></td><td class="num"><?= money($PT['gross']) ?></td><td class="num"><?= $acct(-$PT['disc']) ?></td><td class="num"><?= $acct(-$PT['refunds']) ?></td><td class="num"><?= money($PT['net']) ?></td><td class="num"><?= money($PT['ship']) ?></td><td class="num"><?= money($PT['revenue']) ?></td><td class="num"><?= $acct(-$PT['cogs']) ?></td><td class="num"><?= $acct($PT['gp']) ?></td><td class="num"><?= money($PT['tax']) ?></td></tr></tfoot>
  </table>

  <h2 class="doc-h">Notes to the report</h2>
  <ol class="doc-notes">
    <li>Sales are recognised on the date the order was placed, for orders that were paid. Orders later refunded are shown as a deduction from sales.</li>
    <li>Sales tax collected from customers is a liability owed to the state and is excluded from revenue.</li>
    <li>Cost of goods sold uses the cost price recorded on each item at the time of sale.<?= $cur['missing_cost'] ? ' ' . (int) $cur['missing_cost'] . ' sold item line(s) have no recorded cost, so cost of goods sold may be understated.' : '' ?></li>
    <li>Card processing fees are estimated at <?= e(rtrim(rtrim(number_format($feePct, 2), '0'), '.')) ?>% + <?= money($feeFixed) ?> per card payment; exact fees are shown in the Stripe dashboard.</li>
    <li>Not included: carrier shipping costs, packaging, rent, wages and other operating expenses. Profit shown is before these overheads and before income tax.</li>
    <?php $pgap = round($cur['collected_on_orders'] - ($cashGross - $cashRefunded), 2); if (abs($pgap) >= 0.01): ?>
      <li>Paid orders in the period (<?= money($cur['collected_on_orders']) ?>) differ from payments recorded (<?= money($cashGross - $cashRefunded) ?>) by <?= money(abs($pgap)) ?>, usually orders marked paid without a payment record or paid in another period.</li>
    <?php endif; ?>
  </ol>

  <div class="doc-sign">
    <div><span class="line"></span>Prepared by<?= $preparedBy !== '' ? ': ' . e($preparedBy) : '' ?></div>
    <div><span class="line"></span>Reviewed / approved by</div>
    <div><span class="line"></span>Date</div>
  </div>

  <div class="doc-foot"><?= e($lhName) ?> · Generated from store records on <?= date('M j, Y') ?> · Confidential — for management and accounting use</div>
</div>
<!-- ============ end printed report ============ -->

<div class="card mb no-print">
  <form class="filters" method="get" style="border-bottom:0">
    <?php foreach ($presets as $key => [$label]): ?>
      <a class="btn btn-sm <?= $range === $key ? 'btn-primary' : '' ?>" href="?range=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <span class="muted small" style="margin-left:8px">Custom:</span>
    <input type="hidden" name="range" value="custom">
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
    <button class="btn btn-sm" type="submit">Apply</button>
    <span style="margin-left:auto;display:flex;gap:8px">
      <button class="btn btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer"></i>Print</button>
      <a class="btn btn-sm" href="<?= e(query_with(['export' => 'csv', 'range' => $range, 'from' => $from, 'to' => $to])) ?>"><i class="bi bi-download"></i>Export CSV</a>
    </span>
  </form>
</div>

<div class="report-period">
  <strong><?= format_date($from, 'M j, Y') ?> – <?= format_date($to, 'M j, Y') ?></strong>
  <span class="muted">compared with <?= format_date($prevFrom, 'M j') ?> – <?= format_date($prevTo, 'M j, Y') ?> (<?= $days ?> day<?= $days === 1 ? '' : 's' ?> each)</span>
</div>

<div class="grid grid-4">
  <div class="card stat"><div class="stat-label"><i class="bi bi-cash-stack"></i>Net sales</div><div class="stat-value"><?= money($cur['net_sales']) ?></div>
    <div class="stat-delta"><?= $chg($cur['net_sales'], $prev['net_sales']) ?> vs previous</div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-graph-up-arrow gold"></i>Gross profit</div><div class="stat-value"><?= $acct($cur['gross_profit']) ?></div>
    <div class="stat-delta"><?= $cur['margin'] !== null ? number_format($cur['margin'], 1) . '% margin · ' : '' ?><?= $chg($cur['gross_profit'], $prev['gross_profit']) ?></div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-piggy-bank terra"></i>Profit after card fees</div><div class="stat-value"><?= $acct($cur['profit']) ?></div>
    <div class="stat-delta"><?= $chg($cur['profit'], $prev['profit']) ?> vs previous</div></div>
  <div class="card stat"><div class="stat-label"><i class="bi bi-bag-check info"></i>Paid orders</div><div class="stat-value"><?= number_format((int) $cur['paid_orders']) ?></div>
    <div class="stat-delta">Avg order <?= money($cur['aov']) ?> · <?= number_format((int) $cur['units']) ?> items · <?= $chg($cur['paid_orders'], $prev['paid_orders']) ?></div></div>
</div>

<div class="grid grid-main mt">
  <div class="card">
    <div class="card-head"><div><h2>Profit &amp; loss statement</h2><div class="sub">Orders placed in the period · amounts in USD</div></div></div>
    <div class="table-wrap"><table class="table pl-table">
      <thead><tr><th></th><th class="num">This period</th><th class="num">Previous</th><th class="num">Change</th></tr></thead>
      <tbody>
      <?php foreach ($lines as [$label, $key, $kind]): $sign = $kind === 'less' ? -1 : 1; ?>
        <tr class="pl-<?= $kind ?>">
          <td><?= e($label) ?><?php if ($key === 'card_fees'): ?> <span class="muted small">(<?= e(rtrim(rtrim(number_format($feePct, 2), '0'), '.')) ?>% + <?= money($feeFixed) ?> × <?= (int) $cur['card_payments'] ?>)</span><?php endif; ?></td>
          <td class="num"><?= $acct($sign * $cur[$key]) ?></td>
          <td class="num muted"><?= $acct($sign * $prev[$key]) ?></td>
          <td class="num small"><?= $chg($cur[$key], $prev[$key], $kind !== 'less') ?></td>
        </tr>
        <?php if ($key === 'gross_profit' && $cur['margin'] !== null): ?>
          <tr class="pl-note"><td>Gross margin</td><td class="num"><?= number_format($cur['margin'], 1) ?>%</td><td class="num muted"><?= $prev['margin'] !== null ? number_format($prev['margin'], 1) . '%' : '—' ?></td><td></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="card-body small text-2 pl-notes">
      <?php if ($cur['missing_cost']): ?><div class="alert alert-warning small" style="margin:0 0 10px"><i class="bi bi-exclamation-triangle"></i><div><?= (int) $cur['missing_cost'] ?> sold item line(s) have no cost price, so cost of goods is understated. Add <strong>Cost per item</strong> to those products.</div></div><?php endif; ?>
      <div><strong>Not included:</strong> what you pay carriers for shipping, packaging, rent, wages and other overheads — record those in your bookkeeping to get final net profit.</div>
      <div>Card fees are estimated from your rate in Settings → Commerce; your Stripe dashboard has the exact amounts.</div>
    </div>
  </div>

  <div class="grid" style="align-content:start">
    <div class="card">
      <div class="card-head"><div><h2>Cash &amp; payments</h2><div class="sub">Money received in the period</div></div></div>
      <div class="card-body"><div class="totals report-totals">
        <div><span class="muted">Card payments (Stripe) · <?= $cashCount['stripe'] ?></span><span><?= money($cashIn['stripe']) ?></span></div>
        <div><span class="muted">Manual (cash, Zelle…) · <?= $cashCount['manual'] ?></span><span><?= money($cashIn['manual']) ?></span></div>
        <div><span class="muted">Less: refunds paid out</span><span><?= $acct(-$cashRefunded) ?></span></div>
        <div><span class="muted">Less: card fees (est.)</span><span><?= $acct(-$cur['card_fees']) ?></span></div>
        <div class="grand"><span>Net cash received</span><span><?= $acct($cashNet) ?></span></div>
      </div>
      <?php $gap = round($cur['collected_on_orders'] - ($cashGross - $cashRefunded), 2); if (abs($gap) >= 0.01): ?>
        <div class="alert alert-warning small" style="margin:12px 0 0"><i class="bi bi-exclamation-triangle"></i><div>
          <strong>Doesn't reconcile by <?= money(abs($gap)) ?>.</strong> Paid orders in this period total <?= money($cur['collected_on_orders']) ?>, but payments recorded total <?= money($cashGross - $cashRefunded) ?>.
          Usually an order was marked <em>Paid</em> by hand without a payment record (fix: Payments → Record manual payment), or it was paid in a different period.
        </div></div>
      <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><div><h2>Balances to watch</h2><div class="sub">Not part of profit</div></div></div>
      <div class="card-body"><div class="totals report-totals">
        <div><span class="muted"><strong>Sales tax collected</strong> — owed to the state</span><span><?= money($cur['tax']) ?></span></div>
        <div><span class="muted">Unpaid orders in period · <?= (int) $unpaid['n'] ?></span><span><?= money($unpaid['amt']) ?></span></div>
        <div><span class="muted">All unpaid orders right now · <?= (int) $unpaidAll['n'] ?></span><span><a href="<?= url('admin/orders.php?payment=unpaid') ?>"><?= money($unpaidAll['amt']) ?></a></span></div>
      </div></div>
    </div>
    <div class="card">
      <div class="card-head"><div><h2>Inventory value</h2><div class="sub">Stock on hand today · <?= number_format((int) $inv['units']) ?> units, <?= (int) $inv['skus'] ?> products</div></div></div>
      <div class="card-body"><div class="totals report-totals">
        <div><span class="muted">At cost (what you paid)</span><span><?= money($inv['at_cost']) ?></span></div>
        <div><span class="muted">At retail (selling price)</span><span><?= money($inv['at_retail']) ?></span></div>
        <div class="grand"><span>Potential profit in stock</span><span><?= $acct((float) $inv['at_retail'] - (float) $inv['at_cost']) ?></span></div>
        <?php if ($inv['no_cost']): ?><div class="small"><span class="muted"><?= (int) $inv['no_cost'] ?> product(s) in stock have no cost price</span><span></span></div><?php endif; ?>
      </div></div>
    </div>
  </div>
</div>

<div class="card mt">
  <div class="card-head"><div><h2>Revenue &amp; gross profit</h2><div class="sub"><?= $byMonth ? 'By month' : 'By day' ?> · revenue = net sales + shipping (excludes sales tax)</div></div></div>
  <div class="card-body"><div class="chart-box" style="height:300px"><canvas id="revChart" role="img" aria-label="Chart of revenue and gross profit over time"></canvas></div></div>
</div>

<div class="card mt">
  <div class="card-head"><div><h2>Breakdown by <?= $byMonth ? 'month' : 'day' ?></h2><div class="sub">Every line of the statement, period by period</div></div></div>
  <div class="table-wrap"><table class="table small breakdown">
    <thead><tr><th><?= $byMonth ? 'Month' : 'Day' ?></th><th class="num">Orders</th><th class="num">Gross sales</th><th class="num">Discounts</th><th class="num">Refunds</th><th class="num">Net sales</th><th class="num">Shipping</th><th class="num">Revenue</th><th class="num">COGS</th><th class="num">Gross profit</th><th class="num">Margin</th><th class="num">Sales tax</th></tr></thead>
    <tbody>
    <?php $T = array_fill_keys(['orders', 'gross', 'disc', 'refunds', 'net', 'ship', 'revenue', 'cogs', 'gp', 'tax'], 0); ?>
    <?php foreach ($series as $s): if (!$byMonth && !$s['orders'] && !$s['refunds']) continue; foreach ($T as $k => $_) $T[$k] += $s[$k]; ?>
      <tr><td><?= e($s['label']) ?></td><td class="num"><?= $s['orders'] ?></td><td class="num"><?= money($s['gross']) ?></td><td class="num"><?= $acct(-$s['disc']) ?></td><td class="num"><?= $acct(-$s['refunds']) ?></td>
        <td class="num strong"><?= money($s['net']) ?></td><td class="num"><?= money($s['ship']) ?></td><td class="num"><?= money($s['revenue']) ?></td><td class="num"><?= $acct(-$s['cogs']) ?></td>
        <td class="num strong"><?= $acct($s['gp']) ?></td><td class="num muted"><?= $s['revenue'] > 0 ? number_format($s['gp'] / $s['revenue'] * 100, 1) . '%' : '—' ?></td><td class="num muted"><?= money($s['tax']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$T['orders'] && !$T['refunds']): ?><tr><td colspan="12" class="empty">No sales in this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th>Total</th><th class="num"><?= $T['orders'] ?></th><th class="num"><?= money($T['gross']) ?></th><th class="num"><?= $acct(-$T['disc']) ?></th><th class="num"><?= $acct(-$T['refunds']) ?></th>
      <th class="num"><?= money($T['net']) ?></th><th class="num"><?= money($T['ship']) ?></th><th class="num"><?= money($T['revenue']) ?></th><th class="num"><?= $acct(-$T['cogs']) ?></th>
      <th class="num"><?= $acct($T['gp']) ?></th><th class="num"><?= $T['revenue'] > 0 ? number_format($T['gp'] / $T['revenue'] * 100, 1) . '%' : '—' ?></th><th class="num"><?= money($T['tax']) ?></th></tr></tfoot>
  </table></div>
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
          <td class="num"><?= (int) $t['units'] ?></td><td class="num strong"><?= money($t['total']) ?></td><td class="num"><?= $t['profit'] !== null ? $acct((float) $t['profit']) : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$topProducts): ?><tr><td colspan="4" class="empty">No sales in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-head"><div><h2>Where orders go</h2><div class="sub">Paid orders by state (pickup shown separately)</div></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>State</th><th class="num">Orders</th><th class="num">Value</th><th style="width:34%"></th></tr></thead><tbody>
      <?php $maxState = $byState ? max(array_column($byState, 'total')) : 1; foreach ($byState as $s): ?>
        <tr><td class="strong"><?= $s['shipping_method'] === 'Pickup' ? '<i class="bi bi-shop"></i> Store pickup' : ($s['ship_state'] === null ? '<span class="muted">No address</span>' : e($states[$s['ship_state']] ?? $s['ship_state'])) ?></td><td class="num"><?= (int) $s['orders'] ?></td><td class="num"><?= money($s['total']) ?></td>
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
  var series = ' . json_encode(array_map(fn($s) => ['label' => $s['label'], 'revenue' => $s['revenue'], 'gp' => $s['gp'], 'orders' => $s['orders']], $series), $j) . ';
  var cats = ' . json_encode($byCategory, $j) . ';
  var countries = ' . json_encode($byCountry, $j) . ';
  var green = "#16794f", gold = "#e2a022";

  new Chart(document.getElementById("revChart"), {
    data: {
      labels: series.map(function (s) { return s.label; }),
      datasets: [
        { type: "bar", label: "Revenue", data: series.map(function (s) { return s.revenue; }), backgroundColor: green, borderRadius: 4, borderSkipped: "start", maxBarThickness: 24, order: 2 },
        { type: "line", label: "Gross profit", data: series.map(function (s) { return s.gp; }), borderColor: gold, backgroundColor: gold, pointRadius: 2.5, tension: .25, order: 1 }
      ]
    },
    options: {
      interaction: { mode: "index", intersect: false },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } },
        y: { beginAtZero: true, border: { display: false }, ticks: { callback: AFM.money, maxTicksLimit: 6 } }
      },
      plugins: { legend: { display: true, position: "top", align: "end" }, tooltip: { callbacks: {
        label: function (c) { return " " + c.dataset.label + " " + AFM.moneyExact(c.parsed.y) + (c.datasetIndex === 0 ? " · " + series[c.dataIndex].orders + " orders" : ""); }
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
