<?php
require __DIR__ . '/includes/bootstrap.php';

$editId = input_int('edit');
$editing = $editId ? fetch_one('SELECT * FROM categories WHERE id = ?', [$editId]) : null;
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete') {
        require_role('super_admin', 'admin');
        $id = input_int('id');
        $name = fetch_value('SELECT name FROM categories WHERE id = ?', [$id]);
        q('DELETE FROM categories WHERE id = ?', [$id]);
        log_activity('category_delete', (string) $name);
        flash('success', 'Category deleted. Its products are now uncategorized.');
        redirect('admin/categories.php');
    }

    if ($action === 'toggle') {
        q('UPDATE categories SET is_active = 1 - is_active WHERE id = ?', [input_int('id')]);
        flash('success', 'Category visibility updated.');
        redirect('admin/categories.php');
    }

    // Save (create or update)
    $id = input_int('id') ?: null;
    $name = input('name');
    $parent = input_int('parent_id') ?: null;

    if (mb_strlen($name) < 2) $errors[] = 'Name must be at least 2 characters.';
    if ($id && $parent === $id) $errors[] = 'A category cannot be its own parent.';
    if ($parent && $id && (int) fetch_value('SELECT parent_id FROM categories WHERE id = ?', [$parent]) === $id) {
        $errors[] = 'That parent is a sub-category of this one.';
    }

    if (!$errors) {
        $data = [
            $name,
            unique_slug('categories', input('slug') ?: $name, $id),
            $parent,
            input('description') ?: null,
            input_int('sort_order'),
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id) {
            q('UPDATE categories SET name=?, slug=?, parent_id=?, description=?, sort_order=?, is_active=? WHERE id=?', array_merge($data, [$id]));
            log_activity('category_update', $name);
            flash('success', 'Category updated.');
        } else {
            q('INSERT INTO categories (name, slug, parent_id, description, sort_order, is_active) VALUES (?,?,?,?,?,?)', $data);
            log_activity('category_create', $name);
            flash('success', 'Category created.');
        }
        redirect('admin/categories.php');
    }
}

$rows = fetch_all(
    "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) products,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 'active') live
     FROM categories c ORDER BY c.sort_order, c.name"
);
// Order as a tree: parents followed by their children
$tree = [];
foreach ($rows as $r) if (!$r['parent_id']) {
    $tree[] = $r + ['depth' => 0];
    foreach ($rows as $child) if ((int) $child['parent_id'] === (int) $r['id']) $tree[] = $child + ['depth' => 1];
}
$parents = array_filter($rows, fn($r) => !$r['parent_id'] && (!$editing || $r['id'] != $editing['id']));
$f = $editing ?? ['is_active' => 1, 'sort_order' => 0];
$val = fn($k) => e($_POST[$k] ?? $f[$k] ?? '');

$pageTitle = 'Categories';
$activeNav = 'categories';
$crumb = 'Catalog';
require __DIR__ . '/includes/header.php';
?>
<div class="grid grid-form">
  <div class="card">
    <div class="card-head"><div><h2>All categories</h2><div class="sub">Shown in the store menu in sort order. Sub-categories appear under their parent.</div></div></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Name</th><th class="num">Products</th><th>Visible</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($tree as $c): ?>
          <tr>
            <td style="padding-left:<?= 16 + $c['depth'] * 26 ?>px">
              <?= $c['depth'] ? '<span class="muted">↳</span> ' : '' ?><span class="strong"><?= e($c['name']) ?></span>
              <div class="small muted">/<?= e($c['slug']) ?></div>
            </td>
            <td class="num"><a href="<?= url('admin/products.php?category=' . $c['id']) ?>"><?= (int) $c['products'] ?></a> <span class="small muted">(<?= (int) $c['live'] ?> live)</span></td>
            <td>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-ghost" type="submit" title="Toggle visibility"><?= $c['is_active'] ? status_badge('active') : status_badge('hidden') ?></button>
              </form>
            </td>
            <td class="actions">
              <a class="btn btn-sm btn-icon" href="?edit=<?= $c['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
              <?php if (admin_can('super_admin', 'admin')): ?>
              <form method="post" data-confirm="Delete “<?= e($c['name']) ?>”? Its <?= (int) $c['products'] ?> product(s) will become uncategorized.">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-icon btn-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tree): ?><tr><td colspan="4" class="empty"><i class="bi bi-tags"></i>No categories yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form class="card" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
    <div class="card-head"><h2><?= $editing ? 'Edit category' : 'New category' ?></h2>
      <?php if ($editing): ?><a class="btn btn-sm btn-ghost" href="<?= url('admin/categories.php') ?>">Cancel</a><?php endif; ?></div>
    <div class="card-body">
      <?php if ($errors): ?><div class="alert alert-error"><i class="bi bi-exclamation-octagon"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
      <div class="field"><label for="name">Name <span class="req">*</span></label><input class="input" id="name" name="name" value="<?= $val('name') ?>" required data-slug-target="#slug"></div>
      <div class="field"><label for="slug">Slug</label><input class="input" id="slug" name="slug" value="<?= $val('slug') ?>"></div>
      <div class="field"><label for="parent_id">Parent category</label>
        <select id="parent_id" name="parent_id"><option value="">— None (top level) —</option>
          <?php foreach ($parents as $p): ?><option value="<?= $p['id'] ?>" <?= (string) ($_POST['parent_id'] ?? $f['parent_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="description">Description</label><textarea class="input" id="description" name="description" rows="3"><?= $val('description') ?></textarea></div>
      <div class="field"><label for="sort_order">Sort order</label><input class="input" type="number" id="sort_order" name="sort_order" value="<?= $val('sort_order') ?>"></div>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= ($_POST ? isset($_POST['is_active']) : !empty($f['is_active'])) ? 'checked' : '' ?>> Visible in store</label>
    </div>
    <div class="card-foot form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i><?= $editing ? 'Save category' : 'Create category' ?></button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
