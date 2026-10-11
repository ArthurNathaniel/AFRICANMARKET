<?php
/**
 * Shared order progress, items and totals, used by order-success,
 * order-tracking and the account order page.
 */
/** "Shipping to" address, or pickup location + instructions for pickup orders. */
function render_delivery_box(array $order): void
{
    if (is_pickup_order($order)) { ?>
        <strong><i class="bi bi-shop"></i> Pickup</strong>
        <div class="text-2" style="margin-top:4px"><?= pickup_address() !== '' ? nl2br(e(pickup_address())) : 'We\'ll message you the pickup address when your order is ready.' ?></div>
        <?php if (setting('pickup_hours')): ?><div class="text-2" style="margin-top:4px"><i class="bi bi-clock"></i> <?= e(setting('pickup_hours')) ?></div><?php endif; ?>
        <div style="margin-top:8px">Come in, or send an Uber / driver with order number <strong>#<?= e($order['order_number']) ?></strong>. We'll text <?= e($order['ship_phone'] ?: 'you') ?> when it's ready.</div>
    <?php } else { ?>
        <strong>Shipping to</strong>
        <div class="text-2" style="margin-top:4px"><?= e($order['ship_name']) ?><br><?= e($order['ship_line1']) ?><br><?php if ($order['ship_line2']): ?><?= e($order['ship_line2']) ?><br><?php endif; ?><?= e($order['ship_city']) ?>, <?= e($order['ship_state']) ?> <?= e($order['ship_zip']) ?></div>
    <?php }
}

function render_order_progress(array $order): void
{
    $steps = is_pickup_order($order) ? ['Placed', 'Preparing', 'Ready for pickup', 'Picked up'] : ['Placed', 'Processing', 'Shipped', 'Delivered'];
    $idx = ['pending' => 0, 'processing' => 1, 'shipped' => 2, 'delivered' => 3][$order['status']] ?? null;
    if ($idx === null) {
        echo '<div class="alert alert-' . ($order['status'] === 'refunded' ? 'info' : 'error') . '"><i class="bi bi-info-circle"></i><div>This order was <strong>' . e($order['status']) . '</strong>.</div></div>';
        return;
    }
    if ($order['payment_status'] !== 'paid') $idx = 0;
    ?>
    <div class="timeline" role="list" aria-label="Order progress">
      <div class="fill" style="width:<?= $idx * 25 ?>%"></div>
      <?php foreach ($steps as $i => $s): ?>
        <div class="step<?= $i <= $idx ? ' done' : '' ?>" role="listitem"><div class="dot"><?= $i <= $idx ? '<i class="bi bi-check-lg"></i>' : $i + 1 ?></div><?= $s ?></div>
      <?php endforeach; ?>
    </div>
    <?php if ($order['tracking_number']): $link = tracking_link($order['carrier'], $order['tracking_number']); ?>
      <div class="shipment" style="margin-top:18px"><i class="bi bi-truck"></i> <?= e($order['carrier'] ?: 'Carrier') ?> tracking number <strong><?= e($order['tracking_number']) ?></strong>
        <?php if ($link): ?> · <a href="<?= e($link) ?>" target="_blank" rel="noopener">Track package <i class="bi bi-box-arrow-up-right small"></i></a><?php endif; ?>
        <?php if ($order['shipped_at']): ?><span class="small muted"> · shipped <?= format_date($order['shipped_at']) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
}

/** The order's items. */
function render_shipments(int $orderId): void
{
    $items = fetch_all('SELECT oi.*, p.image, p.slug FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id', [$orderId]);
    foreach ($items as $i): ?>
        <div class="mini-item">
          <div class="q"><img src="<?= e(image_url($i['image'])) ?>" alt=""><span><?= (int) $i['quantity'] ?></span></div>
          <div class="small"><?php if ($i['slug']): ?><a href="<?= url('product.php?slug=' . urlencode($i['slug'])) ?>" class="strong" style="color:var(--text)"><?= e($i['product_name']) ?></a><?php else: ?><strong><?= e($i['product_name']) ?></strong><?php endif; ?>
            <div class="muted"><?= money($i['unit_price']) ?> each</div></div>
          <div class="small strong"><?= money($i['line_total']) ?></div>
        </div>
    <?php endforeach;
}

function render_order_totals(array $o): void
{ ?>
    <div class="summary-row"><span>Subtotal</span><span><?= money($o['subtotal']) ?></span></div>
    <?php if ($o['discount_total'] > 0): ?><div class="summary-row discount"><span>Discount<?= $o['coupon_code'] ? ' (' . e($o['coupon_code']) . ')' : '' ?></span><span>−<?= money($o['discount_total']) ?></span></div><?php endif; ?>
    <div class="summary-row"><span>Shipping<?= $o['shipping_method'] ? ' · ' . e($o['shipping_method']) : '' ?></span><span><?= $o['shipping_total'] > 0 ? money($o['shipping_total']) : 'Free' ?></span></div>
    <?php if ($o['tax_total'] > 0): ?><div class="summary-row"><span>Tax</span><span><?= money($o['tax_total']) ?></span></div><?php endif; ?>
    <div class="summary-row total"><span>Total</span><span><?= money($o['grand_total']) ?></span></div>
<?php }
