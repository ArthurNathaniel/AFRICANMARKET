<?php
require __DIR__ . '/partials/bootstrap.php';
require __DIR__ . '/partials/product-card.php';

$q        = mb_substr(input('q'), 0, 100);
$catSlug  = input('category');
$origin   = input('origin');
$min      = input_decimal('min');
$max      = input_decimal('max');
$sale     = input('sale') === '1';
$inStock  = input('in_stock') === '1';
$sort     = input('sort', 'featured');

$where = [LIVE_PRODUCT_SQL];
$params = [];
$category = null;

if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.short_description LIKE ? OR p.sku LIKE ? OR p.origin_country LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($catSlug !== '' && ($category = fetch_one('SELECT * FROM categories WHERE slug = ? AND is_active = 1', [$catSlug]))) {
    $where[] = '(p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
    array_push($params, $category['id'], $category['id']);
}
if ($origin !== '') { $where[] = 'p.origin_country = ?'; $params[] = $origin; }
if ($min !== null)  { $where[] = 'p.price >= ?'; $params[] = $min; }
if ($max !== null)  { $where[] = 'p.price <= ?'; $params[] = $max; }
if ($sale)          { $where[] = 'p.compare_price > p.price'; }
if ($inStock)       { $where[] = 'p.stock_qty > 0'; }
$whereSql = implode(' AND ', $where);

$sorts = [
    'featured'   => ['Featured', 'p.is_featured DESC, p.created_at DESC'],
    'newest'     => ['Newest', 'p.created_at DESC'],
    'popular'    => ['Best selling', '(SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id) DESC'],
    'rating'     => ['Top rated', 'rating IS NULL, rating DESC, review_count DESC'],
    'price_asc'  => ['Price: low to high', 'p.price ASC'],
    'price_desc' => ['Price: high to low', 'p.price DESC'],
];
if (!isset($sorts[$sort])) $sort = 'featured';

$total = (int) fetch_value("SELECT COUNT(*) FROM products p WHERE $whereSql", $params);
$pg = paginate($total, 24);
$products = fetch_all(product_card_sql() . " WHERE $whereSql ORDER BY {$sorts[$sort][1]} LIMIT {$pg['perPage']} OFFSET {$pg['offset']}", $params);

// Sidebar data
$allCats = store_categories();
$origins = fetch_all("SELECT p.origin_country c, COUNT(*) n FROM products p
                      WHERE " . LIVE_PRODUCT_SQL . " AND p.origin_country IS NOT NULL GROUP BY p.origin_country ORDER BY n DESC");

$heading = $category ? $category['name'] : ($q !== '' ? "Results for “{$q}”" : 'All products');
$pageTitle = $category ? $category['name'] : 'Shop';
// ---- SEO: category pages are indexable; searches and filtered views are not ----
$storeName = setting('store_name', APP_NAME);
$metaTitle = ($category ? $category['name'] . ' – African Groceries Online' : 'Shop African Groceries Online') . ' | ' . $storeName;
$metaDescription = $category
    ? (plain_text($category['description'] ?? '') ?: 'Shop ' . $category['name'] . ' from Africa at ' . $storeName . '. Authentic brands you grew up with, delivered anywhere in the USA.')
    : 'Shop authentic African groceries online — drinks, snacks, spices, sauces and more from Ghana, Nigeria and across Africa, delivered anywhere in the USA.';
$canonical = absolute_url($category ? 'shop.php?category=' . urlencode($category['slug']) : 'shop.php');
$filtered = $q !== '' || $origin || $min !== null || $max !== null || $sale || $inStock || $pg['page'] > 1 || $sort !== 'featured';
if ($filtered) $metaRobots = 'noindex, follow';
$schema = [[
    '@type' => 'CollectionPage', 'name' => $category['name'] ?? 'Shop', 'url' => $canonical,
    'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn($pr, $i) => [
        '@type' => 'ListItem', 'position' => $i + 1, 'url' => absolute_url('product.php?slug=' . urlencode($pr['slug'])), 'name' => $pr['name'],
    ], $products, array_keys($products))],
]];
$activeCat = $category ? ($category['parent_id'] ? (string) fetch_value('SELECT slug FROM categories WHERE id = ?', [$category['parent_id']]) : $category['slug']) : 'all';
$activeChips = array_filter([
    'q' => $q !== '' ? "“{$q}”" : null, 'origin' => $origin ?: null,
    'min' => $min !== null ? 'From ' . money($min) : null, 'max' => $max !== null ? 'Up to ' . money($max) : null,
    'sale' => $sale ? 'On sale' : null, 'in_stock' => $inStock ? 'In stock' : null,
]);

require __DIR__ . '/partials/header.php';
?>
<div class="wrap">
  <div class="page-title">
    <div class="crumbs"><a href="<?= url('/') ?>">Home</a> / <a href="<?= url('shop.php') ?>">Shop</a><?= $category ? ' / ' . e($category['name']) : '' ?></div>
    <h1><?= e($heading) ?></h1>
    <?php if ($category && $category['description']): ?>
      <p><?= e($category['description']) ?></p>
    <?php endif; ?>
  </div>

  <div class="shop-layout">
    <aside class="filters-panel" id="filters" aria-label="Filters">
      <h3>Category</h3>
      <ul class="filter-list">
        <li><a href="<?= e(query_with(['category' => null, 'page' => null])) ?>" class="<?= !$category ? 'active' : '' ?>">All categories</a></li>
        <?php foreach ($allCats as $c): if ($c['parent_id']) continue; ?>
          <li><a href="<?= e(query_with(['category' => $c['slug'], 'page' => null])) ?>" class="<?= $category && $category['id'] == $c['id'] ? 'active' : '' ?>"><?= e($c['name']) ?> <span class="muted"><?= (int) $c['products'] ?></span></a></li>
          <?php if ($category && ($category['id'] == $c['id'] || $category['parent_id'] == $c['id'])): ?>
            <?php foreach ($allCats as $sub): if ((int) $sub['parent_id'] !== (int) $c['id']) continue; ?>
              <li class="sub"><a href="<?= e(query_with(['category' => $sub['slug'], 'page' => null])) ?>" class="<?= $category['id'] == $sub['id'] ? 'active' : '' ?>"><?= e($sub['name']) ?> <span class="muted"><?= (int) $sub['products'] ?></span></a></li>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>

      <form method="get">
        <?php foreach (['q' => $q, 'category' => $catSlug, 'sort' => $sort] as $k => $val): if ($val === '') continue; ?>
          <input type="hidden" name="<?= $k ?>" value="<?= e($val) ?>">
        <?php endforeach; ?>
        <h3>Price</h3>
        <div class="price-inputs">
          <input class="input" name="min" inputmode="decimal" placeholder="$ Min" value="<?= e($min ?? '') ?>" aria-label="Minimum price">
          <span class="muted">–</span>
          <input class="input" name="max" inputmode="decimal" placeholder="$ Max" value="<?= e($max ?? '') ?>" aria-label="Maximum price">
        </div>
        <h3>Made in</h3>
        <select name="origin" aria-label="Country of origin">
          <option value="">Any country</option>
          <?php foreach ($origins as $o): ?><option value="<?= e($o['c']) ?>" <?= $origin === $o['c'] ? 'selected' : '' ?>><?= e($o['c']) ?> (<?= (int) $o['n'] ?>)</option><?php endforeach; ?>
        </select>
        <h3>Show only</h3>
        <label class="check small" style="margin-bottom:8px"><input type="checkbox" name="sale" value="1" <?= $sale ? 'checked' : '' ?>> On sale</label>
        <label class="check small"><input type="checkbox" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>> In stock</label>
        <button class="btn btn-primary btn-block" type="submit" style="margin-top:18px">Apply filters</button>
      </form>
    </aside>

    <section aria-label="Products">
      <div class="toolbar">
        <div class="chips">
          <button class="btn btn-sm filter-toggle" data-toggle="#filters"><i class="bi bi-sliders"></i> Filters</button>
          <span class="muted"><?= number_format($total) ?> product<?= $total === 1 ? '' : 's' ?></span>
          <?php foreach ($activeChips as $key => $label): ?>
            <a class="chip" href="<?= e(query_with([$key => null, 'page' => null])) ?>"><?= e($label) ?> <i class="bi bi-x"></i></a>
          <?php endforeach; ?>
        </div>
        <form method="get">
          <?php foreach ($_GET as $k => $val): if (in_array($k, ['sort', 'page'], true) || !is_string($val)) continue; ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e($val) ?>">
          <?php endforeach; ?>
          <label class="sr-only" for="sort">Sort by</label>
          <select id="sort" name="sort" onchange="this.form.submit()">
            <?php foreach ($sorts as $k => [$label]): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($products): ?>
        <div class="grid-products three">
          <?php foreach ($products as $p) render_product_card($p); ?>
        </div>
        <?= pagination_links($pg) ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="bi bi-search"></i>
          <h3>No products found</h3>
          <p>Try a different search or clear some filters.</p>
          <a class="btn btn-primary" href="<?= url('shop.php') ?>">Browse all products</a>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
