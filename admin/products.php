<?php
require __DIR__ . '/includes/bootstrap.php';

/** Deletes products and their image files. */
function delete_products(array $ids): int
{
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $files = array_merge(
        fetch_all("SELECT image AS path FROM products WHERE id IN ($in) AND image IS NOT NULL", $ids),
        fetch_all("SELECT path FROM product_images WHERE product_id IN ($in)", $ids)
    );
    $n = q("DELETE FROM products WHERE id IN ($in)", $ids)->rowCount();
    foreach ($files as $f) delete_upload($f['path']);
    return $n;
}

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [input_int('id')]))));

    if (!$ids) {
        flash('warning', 'Select at least one product.');
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        switch ($action) {
            case 'activate':
                q("UPDATE products SET status = 'active' WHERE id IN ($in)", $ids);
                flash('success', count($ids) . ' product(s) are now visible in the store.');
                break;
            case 'hide':
                q("UPDATE products SET status = 'inactive' WHERE id IN ($in)", $ids);
                flash('success', count($ids) . ' product(s) hidden from the store.');
                break;
            case 'feature':
                q("UPDATE products SET is_featured = 1 - is_featured WHERE id IN ($in)", $ids);
                flash('success', 'Featured flag updated.');
                break;
            case 'restock':
                $qty = max(0, input_int('qty'));
                q("UPDATE products SET stock_qty = ? WHERE id IN ($in)", array_merge([$qty], $ids));
                flash('success', "Stock set to $qty.");
                break;
            case 'delete':
                require_role('super_admin', 'admin');
                $n = delete_products($ids);
                flash('success', "$n product(s) deleted.");
                break;
            default:
                flash('error', 'Unknown action.');
        }
        log_activity('product_' . $action, 'Product IDs: ' . implode(', ', $ids));
    }
    redirect('admin/products.php' . query_with([]));
}

// ---- Filters ---------------------------------------------------------------
$status   = input('status');
$search   = input('q');
$category = input_int('category');
$origin   = input('origin');
$stock    = input('stock');

$where = ['1=1'];
$params = [];
if ($status !== '')   { $where[] = 'p.status = ?'; $params[] = $status; }
if ($search !== '')   { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($category)        { $where[] = '(p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))'; array_push($params, $category, $category); }
if ($origin !== '')   { $where[] = 'p.origin_country = ?'; $params[] = $origin; }
if ($stock === 'low') { $where[] = 'p.stock_qty BETWEEN 1 AND ?'; $params[] = low_stock(); }
if ($stock === 'out') { $where[] = 'p.stock_qty = 0'; }
$whereSql = implode(' AND ', $where);

$total = (int) fetch_value("SELECT COUNT(*) FROM products p WHERE $whereSql", $params);
$pg = paginate($total, 20);
$products = fetch_all(
    "SELECT p.*, c.name AS category_name,
        (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.product_id = p.id AND o.payment_status = 'paid') AS sold
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE $whereSql ORDER BY p.created_at DESC
     LIMIT {$pg['perPage']} OFFSET {$pg['offset']}",
    $params
);

$statusCounts = array_column(fetch_all('SELECT status, COUNT(*) n FROM products GROUP BY status'), 'n', 'status');
$categories = fetch_all('SELECT c.id, c.name, pc.name parent FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id ORDER BY COALESCE(pc.name, c.name), c.parent_id IS NOT NULL, c.name');
$origins = array_column(fetch_all('SELECT DISTINCT origin_country c FROM products WHERE origin_country IS NOT NULL ORDER BY c'), 'c');
$stockValue = fetch_one("SELECT COALESCE(SUM(stock_qty * price), 0) retail, COALESCE(SUM(stock_qty * cost_price), 0) cost, SUM(stock_qty = 0 AND status = 'active') sold_out FROM products");

$pageTitle = 'Products';
$activeNav = 'products';
$crumb = 'Catalog';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p class="mt-0">Inventory value <strong><?= money($stockValue['retail']) ?></strong> at retail<?= $stockValue['cost'] > 0 ? ' · ' . money($stockValue['cost']) . ' at cost' : '' ?><?= $stockValue['sold_out'] ? ' · <a href="?stock=out&status=active">' . (int) $stockValue['sold_out'] . ' sold out</a>' : '' ?></p></div>
  <a class="btn btn-primary" href="<?= url('admin/product-add.php') ?>"><i class="bi bi-plus-lg"></i>Add product</a>
</div>

<div class="card">
  <div class="tabs">
    <a href="<?= e(query_with(['status' => null, 'page' => null])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All<span class="count"><?= array_sum($statusCounts) ?></span></a>
    <?php foreach (['active' => 'Active', 'draft' => 'Draft', 'inactive' => 'Hidden'] as $k => $label): ?>
      <a href="<?= e(query_with(['status' => $k, 'page' => null])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $label ?><span class="count"><?= (int) ($statusCounts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </div>

  <form class="filters" method="get">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($search) ?>" placeholder="Search products…"></div>
    <select name="category" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $category == $c['id'] ? 'selected' : '' ?>><?= $c['parent'] ? e($c['parent']) . ' › ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="origin" onchange="this.form.submit()">
      <option value="">Any origin</option>
      <?php foreach ($origins as $o): ?><option <?= $origin === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?>
    </select>
    <select name="stock" onchange="this.form.submit()">
      <option value="">Any stock</option>
      <option value="low" <?= $stock === 'low' ? 'selected' : '' ?>>Low stock (1–<?= low_stock() ?>)</option>
      <option value="out" <?= $stock === 'out' ? 'selected' : '' ?>>Out of stock</option>
    </select>
    <button class="btn" type="submit">Filter</button>
    <?php if ($search || $category || $origin || $stock): ?><a class="btn btn-ghost" href="<?= url('admin/products.php' . ($status ? '?status=' . urlencode($status) : '')) ?>">Clear</a><?php endif; ?>
  </form>

  <form method="post" data-confirm="Apply this action to the selected products?">
    <?= csrf_field() ?>
    <div class="filters" style="padding:10px 16px">
      <select name="action" style="min-width:180px" onchange="document.getElementById('bulkQty').classList.toggle('hidden', this.value !== 'restock')">
        <option value="activate">Make active</option>
        <option value="hide">Hide from store</option>
        <option value="feature">Toggle featured</option>
        <option value="restock">Set stock to…</option>
        <?php if (admin_can('super_admin', 'admin')): ?><option value="delete">Delete</option><?php endif; ?>
      </select>
      <input class="input hidden" id="bulkQty" type="number" min="0" name="qty" placeholder="Qty" style="min-width:90px;width:90px">
      <button class="btn btn-sm" type="submit">Apply to selected</button>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th style="width:32px"><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)" aria-label="Select all"></th>
          <th>Product</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th class="num">Sold</th><th>Status</th><th class="actions"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td><input type="checkbox" class="row-check" name="ids[]" value="<?= $p['id'] ?>" aria-label="Select <?= e($p['name']) ?>"></td>
            <td>
              <div class="cell-main">
                <img class="thumb" src="<?= e(image_url($p['image'])) ?>" alt="">
                <div style="min-width:0">
                  <a class="title" href="<?= url('admin/product-edit.php?id=' . $p['id']) ?>"><?= e($p['name']) ?></a>
                  <?php if ($p['is_featured']): ?> <span class="badge badge-warning badge-plain"><i class="bi bi-star-fill"></i> Featured</span><?php endif; ?>
                  <div class="meta"><?= e($p['origin_country'] ?: '—') ?></div>
                </div>
              </div>
            </td>
            <td><?= e($p['category_name'] ?? '—') ?></td>
            <td class="num"><?= money($p['price']) ?><?php if ($p['compare_price'] > $p['price']): ?><div class="small muted"><s><?= money($p['compare_price']) ?></s></div><?php endif; ?></td>
            <td class="num <?= $p['stock_qty'] <= low_stock() ? 'strong' : '' ?>" style="<?= $p['stock_qty'] == 0 ? 'color:var(--danger)' : '' ?>"><?= (int) $p['stock_qty'] ?></td>
            <td class="num"><?= (int) $p['sold'] ?></td>
            <td><?= $p['status'] === 'inactive' ? '<span class="badge badge-neutral">Hidden</span>' : status_badge($p['status']) ?></td>
            <td class="actions">
              <a class="btn btn-sm btn-icon" href="<?= url('product.php?slug=' . urlencode($p['slug'])) ?>" target="_blank" rel="noopener" title="View in store"><i class="bi bi-eye"></i></a>
              <a class="btn btn-sm btn-icon" href="<?= url('admin/product-edit.php?id=' . $p['id']) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="8" class="empty"><i class="bi bi-box-seam"></i>No products match these filters.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?= pagination_links($pg) ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
