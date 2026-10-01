<?php
require dirname(__DIR__) . '/partials/bootstrap.php';
$customer = require_customer();
$states = us_states();
$errors = [];
$editId = input_int('edit');
$editing = $editId ? fetch_one('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?', [$editId, $customer['id']]) : null;

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = input_int('id');
    $own = $id ? fetch_one('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?', [$id, $customer['id']]) : null;

    if ($action === 'delete' && $own) {
        q('DELETE FROM customer_addresses WHERE id = ?', [$id]);
        if ($own['is_default']) {
            q('UPDATE customer_addresses SET is_default = 1 WHERE customer_id = ? ORDER BY id LIMIT 1', [$customer['id']]);
        }
        flash('success', 'Address deleted.');
        redirect('account/addresses.php');
    }
    if ($action === 'default' && $own) {
        q('UPDATE customer_addresses SET is_default = (id = ?) WHERE customer_id = ?', [$id, $customer['id']]);
        flash('success', 'Default address updated.');
        redirect('account/addresses.php');
    }
    if ($action === 'save') {
        $d = [
            'label' => mb_substr(input('label'), 0, 40) ?: null, 'full_name' => input('full_name'), 'line1' => input('line1'),
            'line2' => input('line2') ?: null, 'city' => input('city'), 'state' => strtoupper(input('state')),
            'zip' => input('zip'), 'phone' => input('phone') ?: null,
        ];
        if (mb_strlen($d['full_name']) < 2) $errors[] = 'Enter the recipient\'s name.';
        if (mb_strlen($d['line1']) < 3) $errors[] = 'Enter a street address.';
        if (mb_strlen($d['city']) < 2) $errors[] = 'Enter a city.';
        if (!isset($states[$d['state']])) $errors[] = 'Choose a state.';
        if (!preg_match('/^\d{5}(-\d{4})?$/', $d['zip'])) $errors[] = 'Enter a 5-digit ZIP code.';
        if ($id && !$own) $errors[] = 'Address not found.';
        if (!$errors) {
            $makeDefault = isset($_POST['is_default']) || !fetch_value('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$customer['id']]);
            if ($makeDefault) q('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [$customer['id']]);
            if ($own) {
                q('UPDATE customer_addresses SET label=?, full_name=?, line1=?, line2=?, city=?, state=?, zip=?, phone=?, is_default = IF(?, 1, is_default) WHERE id = ?',
                  array_merge(array_values($d), [$makeDefault ? 1 : 0, $id]));
            } else {
                q('INSERT INTO customer_addresses (label, full_name, line1, line2, city, state, zip, phone, is_default, customer_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                  array_merge(array_values($d), [$makeDefault ? 1 : 0, $customer['id']]));
            }
            flash('success', 'Address saved.');
            redirect('account/addresses.php');
        }
    }
}

$addresses = fetch_all('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id', [$customer['id']]);
$f = $editing ?? [];
$v = fn($k) => e($_POST[$k] ?? $f[$k] ?? '');
$showForm = $editing || isset($_GET['new']) || $errors || !$addresses;

$pageTitle = 'Addresses';
$accountTab = 'addresses';
require dirname(__DIR__) . '/partials/header.php';
?>
<div class="wrap account-layout">
  <?php require dirname(__DIR__) . '/partials/account-nav.php'; ?>
  <div style="display:grid;gap:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px"><h1 style="font-size:30px">Addresses</h1>
      <?php if (!$showForm): ?><a class="btn btn-primary" href="?new=1"><i class="bi bi-plus-lg"></i> Add address</a><?php endif; ?></div>

    <?php if ($showForm): ?>
      <form method="post" class="panel panel-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <h2 style="font-size:18px;margin-bottom:14px"><?= $editing ? 'Edit address' : 'New address' ?></h2>
        <?php if ($errors): ?><div class="alert alert-error"><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
        <div class="form-row">
          <div class="field"><label for="label">Label</label><input class="input" id="label" name="label" value="<?= $v('label') ?>" placeholder="Home, Work…"></div>
          <div class="field"><label for="full_name">Full name <span class="req">*</span></label><input class="input" id="full_name" name="full_name" value="<?= $v('full_name') ?: e($customer['first_name'] . ' ' . $customer['last_name']) ?>" autocomplete="name"></div>
        </div>
        <div class="field"><label for="line1">Street address <span class="req">*</span></label><input class="input" id="line1" name="line1" value="<?= $v('line1') ?>" autocomplete="address-line1"></div>
        <div class="field"><label for="line2">Apartment, suite, etc.</label><input class="input" id="line2" name="line2" value="<?= $v('line2') ?>" autocomplete="address-line2"></div>
        <div class="form-row three">
          <div class="field"><label for="city">City <span class="req">*</span></label><input class="input" id="city" name="city" value="<?= $v('city') ?>" autocomplete="address-level2"></div>
          <div class="field"><label for="state">State <span class="req">*</span></label><select id="state" name="state"><option value="">Select…</option>
            <?php foreach ($states as $code => $name): ?><option value="<?= $code ?>" <?= ($_POST['state'] ?? $f['state'] ?? '') === $code ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="zip">ZIP <span class="req">*</span></label><input class="input" id="zip" name="zip" value="<?= $v('zip') ?>" inputmode="numeric" autocomplete="postal-code"></div>
        </div>
        <div class="field"><label for="phone">Phone</label><input class="input" type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" autocomplete="tel"></div>
        <label class="check small" style="margin-bottom:16px"><input type="checkbox" name="is_default" value="1" <?= !empty($f['is_default']) || !$addresses ? 'checked' : '' ?>> Use as my default address</label>
        <div style="display:flex;gap:10px"><button class="btn btn-primary" type="submit">Save address</button><?php if ($addresses): ?><a class="btn" href="<?= url('account/addresses.php') ?>">Cancel</a><?php endif; ?></div>
      </form>
    <?php endif; ?>

    <?php if ($addresses): ?>
      <div class="address-grid">
        <?php foreach ($addresses as $a): ?>
          <div class="address-card<?= $a['is_default'] ? ' default' : '' ?>">
            <div style="display:flex;justify-content:space-between;gap:8px"><strong><?= e($a['label'] ?: 'Address') ?></strong><?php if ($a['is_default']): ?><span class="badge badge-success">Default</span><?php endif; ?></div>
            <div class="text-2 small" style="margin-top:6px"><?= e($a['full_name']) ?><br><?= e($a['line1']) ?><br><?php if ($a['line2']): ?><?= e($a['line2']) ?><br><?php endif; ?><?= e($a['city']) ?>, <?= e($a['state']) ?> <?= e($a['zip']) ?><?php if ($a['phone']): ?><br><?= e($a['phone']) ?><?php endif; ?></div>
            <div class="actions">
              <a class="btn btn-sm" href="?edit=<?= $a['id'] ?>">Edit</a>
              <?php if (!$a['is_default']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm">Make default</button></form><?php endif; ?>
              <form method="post" data-confirm="Delete this address?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-danger">Delete</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
