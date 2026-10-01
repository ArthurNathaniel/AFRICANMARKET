<?php
require __DIR__ . '/includes/bootstrap.php';

$editId = input_int('edit');
$editing = $editId ? fetch_one('SELECT * FROM coupons WHERE id = ?', [$editId]) : null;
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');
    if ($action === 'delete') {
        $code = fetch_value('SELECT code FROM coupons WHERE id = ?', [input_int('id')]);
        q('DELETE FROM coupons WHERE id = ?', [input_int('id')]);
        log_activity('coupon_delete', (string) $code);
        flash('success', "Coupon $code deleted.");
        redirect('admin/coupons.php');
    }
    if ($action === 'toggle') {
        q('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?', [input_int('id')]);
        flash('success', 'Coupon updated.');
        redirect('admin/coupons.php');
    }

    $id = input_int('id') ?: null;
    $code = strtoupper(preg_replace('/\s+/', '', input('code')));
    $type = input('type') === 'fixed' ? 'fixed' : 'percent';
    $value = input_decimal('value');
    $starts = input('starts_at');
    $expires = input('expires_at');

    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) $errors[] = 'Code must be 3–40 letters, numbers, dashes or underscores.';
    if ($value === null || $value <= 0) $errors[] = 'Enter a discount value.';
    if ($type === 'percent' && $value > 100) $errors[] = 'A percentage discount cannot exceed 100%.';
    if ($starts && $expires && strtotime($expires) <= strtotime($starts)) $errors[] = 'Expiry must be after the start date.';
    if (fetch_value('SELECT id FROM coupons WHERE code = ? AND id <> ?', [$code, (int) $id])) $errors[] = 'That code already exists.';

    if (!$errors) {
        $data = [
            $code, input('description') ?: null, $type, $value, input_decimal('min_order') ?? 0,
            input('max_uses') !== '' ? max(1, input_int('max_uses')) : null,
            $starts ? date('Y-m-d 00:00:00', strtotime($starts)) : null,
            $expires ? date('Y-m-d 23:59:59', strtotime($expires)) : null,
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id) {
            q('UPDATE coupons SET code=?, description=?, type=?, value=?, min_order=?, max_uses=?, starts_at=?, expires_at=?, is_active=? WHERE id=?', array_merge($data, [$id]));
        } else {
            q('INSERT INTO coupons (code, description, type, value, min_order, max_uses, starts_at, expires_at, is_active) VALUES (?,?,?,?,?,?,?,?,?)', $data);
        }
        log_activity('coupon_save', $code);
        flash('success', "Coupon $code saved.");
        redirect('admin/coupons.php');
    }
}

$coupons = fetch_all('SELECT * FROM coupons ORDER BY is_active DESC, created_at DESC');
$f = $editing ?? ['type' => 'percent', 'is_active' => 1, 'min_order' => '0.00'];
$val = fn($k) => e($_POST[$k] ?? $f[$k] ?? '');
$dateVal = function ($k) use ($f) {
    $v = $_POST[$k] ?? (!empty($f[$k]) ? date('Y-m-d', strtotime($f[$k])) : '');
    return e($v);
};

function coupon_state(array $c): string
{
    $now = time();
    if (!$c['is_active']) return status_badge('inactive');
    if ($c['expires_at'] && strtotime($c['expires_at']) < $now) return '<span class="badge badge-neutral">Expired</span>';
    if ($c['starts_at'] && strtotime($c['starts_at']) > $now) return '<span class="badge badge-info">Scheduled</span>';
    if ($c['max_uses'] && $c['used_count'] >= $c['max_uses']) return '<span class="badge badge-neutral">Used up</span>';
    return status_badge('active');
}

$pageTitle = 'Coupons';
$activeNav = 'coupons';
$crumb = 'Sales';
require __DIR__ . '/includes/header.php';
?>
<div class="grid grid-form">
  <div class="card">
    <div class="card-head"><div><h2>Discount codes</h2><div class="sub">Customers enter these codes in the cart. Discounts apply to the item subtotal, not shipping.</div></div></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Code</th><th>Discount</th><th>Valid</th><th class="num">Used</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($coupons as $c): ?>
          <tr>
            <td><code class="strong" style="font-size:13px"><?= e($c['code']) ?></code><div class="small muted"><?= e($c['description']) ?></div></td>
            <td class="nowrap"><?= $c['type'] === 'percent' ? e(rtrim(rtrim($c['value'], '0'), '.')) . '% off' : money($c['value']) . ' off' ?>
              <?php if ($c['min_order'] > 0): ?><div class="small muted">Min. <?= money($c['min_order']) ?></div><?php endif; ?></td>
            <td class="small nowrap"><?= $c['starts_at'] ? format_date($c['starts_at']) : 'Now' ?> → <?= $c['expires_at'] ? format_date($c['expires_at']) : 'No expiry' ?></td>
            <td class="num"><?= (int) $c['used_count'] ?><?= $c['max_uses'] ? ' / ' . (int) $c['max_uses'] : '' ?></td>
            <td><?= coupon_state($c) ?></td>
            <td class="actions">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm"><?= $c['is_active'] ? 'Disable' : 'Enable' ?></button></form>
              <a class="btn btn-sm btn-icon" href="?edit=<?= $c['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
              <form method="post" data-confirm="Delete coupon <?= e($c['code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-icon btn-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$coupons): ?><tr><td colspan="6" class="empty"><i class="bi bi-ticket-perforated"></i>No coupons yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form class="card" method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
    <div class="card-head"><h2><?= $editing ? 'Edit coupon' : 'New coupon' ?></h2><?php if ($editing): ?><a class="btn btn-sm btn-ghost" href="<?= url('admin/coupons.php') ?>">Cancel</a><?php endif; ?></div>
    <div class="card-body">
      <?php if ($errors): ?><div class="alert alert-error"><i class="bi bi-exclamation-octagon"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
      <div class="field"><label for="code">Code <span class="req">*</span></label><input class="input" id="code" name="code" value="<?= $val('code') ?>" style="text-transform:uppercase" placeholder="AKWAABA10" required></div>
      <div class="field"><label for="description">Description</label><input class="input" id="description" name="description" value="<?= $val('description') ?>" placeholder="Welcome offer for new customers"></div>
      <div class="form-row">
        <div class="field"><label for="type">Type</label><select id="type" name="type"><option value="percent" <?= ($_POST['type'] ?? $f['type']) === 'percent' ? 'selected' : '' ?>>Percentage</option><option value="fixed" <?= ($_POST['type'] ?? $f['type']) === 'fixed' ? 'selected' : '' ?>>Fixed amount ($)</option></select></div>
        <div class="field"><label for="value">Value <span class="req">*</span></label><input class="input" id="value" name="value" inputmode="decimal" value="<?= $val('value') ?>" required></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="min_order">Minimum order</label><div class="input-group"><span class="addon">$</span><input class="input" id="min_order" name="min_order" inputmode="decimal" value="<?= $val('min_order') ?>"></div></div>
        <div class="field"><label for="max_uses">Usage limit</label><input class="input" type="number" min="1" id="max_uses" name="max_uses" value="<?= $val('max_uses') ?>" placeholder="Unlimited"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="starts_at">Starts</label><input class="input" type="date" id="starts_at" name="starts_at" value="<?= $dateVal('starts_at') ?>"></div>
        <div class="field"><label for="expires_at">Expires</label><input class="input" type="date" id="expires_at" name="expires_at" value="<?= $dateVal('expires_at') ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="is_active" value="1" <?= ($_POST ? isset($_POST['is_active']) : !empty($f['is_active'])) ? 'checked' : '' ?>> Active</label>
    </div>
    <div class="card-foot form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i><?= $editing ? 'Save coupon' : 'Create coupon' ?></button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
