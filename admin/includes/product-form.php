<?php
/**
 * Shared product form logic for product-add.php and product-edit.php.
 */

const PRODUCT_STATUSES = ['active' => 'Active (visible in store)', 'draft' => 'Draft', 'inactive' => 'Hidden'];

/** Validates POST data and saves the product. Returns [errors[], savedId|null]. */
function save_product_from_post(?int $productId, array $existing): array
{
    $errors = [];
    $data = [
        'category_id'       => input_int('category_id') ?: null,
        'name'              => input('name'),
        'slug'              => slugify(input('slug') ?: input('name')),
        'short_description' => mb_substr(input('short_description'), 0, 500) ?: null,
        'description'       => input('description') ?: null,
        'price'             => input_decimal('price'),
        'compare_price'     => input_decimal('compare_price'),
        'cost_price'        => input_decimal('cost_price'),
        'stock_qty'         => max(0, input_int('stock_qty')),
        'weight_lb'         => input_decimal('weight_lb') ?? 1.0,
        'origin_country'    => input('origin_country') ?: null,
        'status'            => input('status'),
        'is_featured'       => isset($_POST['is_featured']) ? 1 : 0,
    ];

    if (mb_strlen($data['name']) < 3) $errors[] = 'Product name must be at least 3 characters.';
    if ($data['price'] === null || $data['price'] <= 0) $errors[] = 'Enter a price greater than $0.';
    if ($data['compare_price'] !== null && $data['compare_price'] <= (float) $data['price']) {
        $errors[] = 'Compare-at price must be higher than the selling price (or leave it blank).';
    }
    if ($data['cost_price'] !== null && $data['cost_price'] < 0) $errors[] = 'Cost price cannot be negative.';
    if (!isset(PRODUCT_STATUSES[$data['status']])) $errors[] = 'Choose a valid status.';
    if ($errors) return [$errors, null];

    $data['slug'] = unique_slug('products', $data['slug'], $productId);

    // Images: validate/upload before writing to the database.
    $mainImage = null;
    $newGallery = [];
    try {
        $mainImage = upload_image($_FILES['image'] ?? null, 'products');
        foreach (files_array('gallery') as $f) {
            if ($p = upload_image($f, 'products')) $newGallery[] = $p;
        }
    } catch (RuntimeException $ex) {
        if ($mainImage) delete_upload($mainImage);
        foreach ($newGallery as $p) delete_upload($p);
        return [[$ex->getMessage()], null];
    }

    $pdo = db();
    $pdo->beginTransaction();
    $removedPaths = [];
    try {
        if ($productId) {
            if ($mainImage) $data['image'] = $mainImage;
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
            q("UPDATE products SET $set WHERE id = ?", array_merge(array_values($data), [$productId]));
        } else {
            $data['image'] = $mainImage;
            $cols = array_keys($data);
            q('INSERT INTO products (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
              array_values($data));
            $productId = (int) $pdo->lastInsertId();
        }

        // Remove gallery images ticked for deletion
        $remove = array_map('intval', (array) ($_POST['remove_images'] ?? []));
        if ($remove) {
            $in = implode(',', array_fill(0, count($remove), '?'));
            $removedPaths = fetch_all("SELECT path FROM product_images WHERE product_id = ? AND id IN ($in)", array_merge([$productId], $remove));
            q("DELETE FROM product_images WHERE product_id = ? AND id IN ($in)", array_merge([$productId], $remove));
        }
        $sort = (int) fetch_value('SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = ?', [$productId]);
        foreach ($newGallery as $path) {
            q('INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)', [$productId, $path, ++$sort]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        if ($mainImage) delete_upload($mainImage);
        foreach ($newGallery as $p) delete_upload($p);
        throw $ex;
    }

    // Clean up replaced/removed files after the commit succeeded.
    if ($mainImage && !empty($existing['image'])) delete_upload($existing['image']);
    foreach ($removedPaths as $r) delete_upload($r['path']);

    return [[], $productId];
}

/** Renders the product form. */
function render_product_form(array $p, array $gallery, array $errors, string $submitLabel): void
{
    $cats = fetch_all('SELECT c.id, c.name, pc.name AS parent FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id ORDER BY COALESCE(pc.name, c.name), c.parent_id IS NOT NULL, c.name');
    $v = fn($k, $d = '') => e($_POST[$k] ?? $p[$k] ?? $d);
    $sel = fn($k, $val) => (string) ($_POST[$k] ?? $p[$k] ?? '') === (string) $val ? 'selected' : '';
    ?>
    <?php if ($errors): ?>
      <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-octagon"></i>
        <div><strong>Please fix the following:</strong><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
      </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="grid grid-form">
      <?= csrf_field() ?>
      <div class="grid">
        <div class="card">
          <div class="card-head"><h2>Product details</h2></div>
          <div class="card-body">
            <div class="field">
              <label for="name">Product name <span class="req">*</span></label>
              <input class="input" id="name" name="name" value="<?= $v('name') ?>" required maxlength="200" data-slug-target="#slug" placeholder="e.g. Handwoven Kente Stole – Asante Pattern">
            </div>
            <div class="field">
              <label for="slug">URL slug</label>
              <div class="input-group"><span class="addon">/product/</span><input class="input" id="slug" name="slug" value="<?= $v('slug') ?>" maxlength="200"></div>
            </div>
            <div class="field">
              <label for="short_description">Short description</label>
              <input class="input" id="short_description" name="short_description" value="<?= $v('short_description') ?>" maxlength="500" placeholder="One line shown in search results and product cards">
            </div>
            <div class="field">
              <label for="description">Full description</label>
              <textarea class="input" id="description" name="description" rows="7" placeholder="Materials, how it's made, sizing, care instructions, the maker's story…"><?= $v('description') ?></textarea>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><h2>Pricing &amp; inventory</h2></div>
          <div class="card-body">
            <div class="form-row three">
              <div class="field">
                <label for="price">Price (USD) <span class="req">*</span></label>
                <div class="input-group"><span class="addon">$</span><input class="input" id="price" name="price" inputmode="decimal" value="<?= $v('price') ?>" required></div>
              </div>
              <div class="field">
                <label for="compare_price">Compare-at price</label>
                <div class="input-group"><span class="addon">$</span><input class="input" id="compare_price" name="compare_price" inputmode="decimal" value="<?= $v('compare_price') ?>"></div>
                <div class="hint">Shown crossed out when higher than price.</div>
              </div>
              <div class="field">
                <label for="cost_price">Cost per item</label>
                <div class="input-group"><span class="addon">$</span><input class="input" id="cost_price" name="cost_price" inputmode="decimal" value="<?= $v('cost_price') ?>"></div>
                <div class="hint">Private — used for profit reports.</div>
              </div>
            </div>
            <div class="form-row">
              <div class="field">
                <label for="stock_qty">Stock quantity</label>
                <input class="input" type="number" min="0" id="stock_qty" name="stock_qty" value="<?= $v('stock_qty', '0') ?>">
              </div>
              <div class="field">
                <label for="weight_lb">Shipping weight</label>
                <div class="input-group"><input class="input" id="weight_lb" name="weight_lb" inputmode="decimal" value="<?= $v('weight_lb', '1.00') ?>"><span class="addon">lb</span></div>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><div><h2>Gallery</h2><div class="sub">Additional photos. Tick an image to remove it on save.</div></div></div>
          <div class="card-body">
            <?php if ($gallery): ?>
              <div class="gallery mb">
                <?php foreach ($gallery as $g): ?>
                  <label title="Remove on save"><input type="checkbox" name="remove_images[]" value="<?= $g['id'] ?>"><img src="<?= e(image_url($g['path'])) ?>" alt=""></label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="dropzone">
              <i class="bi bi-images" style="font-size:22px"></i>
              <div>Add more photos (JPG, PNG, WEBP · max <?= MAX_UPLOAD_MB ?>MB each)</div>
              <input type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-preview-grid="galleryPreview">
            </div>
            <div class="gallery mt" id="galleryPreview" hidden></div>
          </div>
        </div>
      </div>

      <div class="grid">
        <div class="card">
          <div class="card-head"><h2>Publishing</h2></div>
          <div class="card-body">
            <div class="field">
              <label for="status">Status</label>
              <select id="status" name="status">
                <?php foreach (PRODUCT_STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $sel('status', $k) ?>><?= $label ?></option><?php endforeach; ?>
              </select>
            </div>
            <label class="check"><input type="checkbox" name="is_featured" value="1" <?= ($_POST ? isset($_POST['is_featured']) : !empty($p['is_featured'])) ? 'checked' : '' ?>> Feature on homepage</label>
          </div>
          <div class="card-foot form-actions">
            <a class="btn" href="<?= url('admin/products.php') ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i><?= e($submitLabel) ?></button>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><h2>Organization</h2></div>
          <div class="card-body">
            <div class="field">
              <label for="category_id">Category</label>
              <select id="category_id" name="category_id">
                <option value="">Uncategorized</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= $sel('category_id', $c['id']) ?>><?= $c['parent'] ? e($c['parent']) . ' › ' : '' ?><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field mt-0">
              <label for="origin_country">Country of origin</label>
              <select id="origin_country" name="origin_country">
                <option value="">Select…</option>
                <?= country_options($_POST['origin_country'] ?? $p['origin_country'] ?? null) ?>
              </select>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><h2>Main image</h2></div>
          <div class="card-body">
            <img id="mainPreview" class="img-preview mb" src="<?= e(image_url($p['image'] ?? null)) ?>" alt="Main product image preview">
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="mainPreview">
            <div class="hint">Square images of at least 1000×1000px look best.</div>
          </div>
        </div>
      </div>
    </form>
    <?php
}
