<?php
require __DIR__ . '/includes/bootstrap.php';

$states = us_states();
$rateTypes = ['flat' => 'Flat rate', 'per_lb' => 'Base + per lb', 'free' => 'Free'];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'save_zone') {
        $id = input_int('id');
        $name = input('name');
        $regions = array_values(array_intersect((array) ($_POST['regions'] ?? []), array_keys($states)));
        if (mb_strlen($name) < 2 || !$regions) {
            flash('error', 'Give the zone a name and select at least one state.');
            redirect('admin/shipping.php' . ($id ? "?zone=$id" : ''));
        }
        // A state can only belong to one zone.
        $clash = [];
        foreach (fetch_all('SELECT id, name, regions FROM shipping_zones WHERE id <> ?', [$id]) as $z) {
            $overlap = array_intersect($regions, array_filter(explode(',', (string) $z['regions'])));
            if ($overlap) $clash[] = implode(', ', $overlap) . " (already in {$z['name']})";
        }
        if ($clash) {
            flash('error', 'These states are already in another zone: ' . implode('; ', $clash));
            redirect('admin/shipping.php' . ($id ? "?zone=$id" : ''));
        }
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id) {
            q('UPDATE shipping_zones SET name = ?, regions = ?, is_active = ? WHERE id = ?', [$name, implode(',', $regions), $active, $id]);
        } else {
            $sort = (int) fetch_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shipping_zones');
            q('INSERT INTO shipping_zones (name, regions, is_active, sort_order) VALUES (?, ?, ?, ?)', [$name, implode(',', $regions), $active, $sort]);
        }
        log_activity('shipping_zone_save', $name);
        flash('success', "Zone “{$name}” saved.");
    }

    if ($action === 'delete_zone') {
        require_role('super_admin', 'admin');
        q('DELETE FROM shipping_zones WHERE id = ?', [input_int('id')]);
        flash('success', 'Zone and its methods deleted.');
    }

    if ($action === 'save_method') {
        $id = input_int('id');
        $type = array_key_exists(input('rate_type'), $rateTypes) ? input('rate_type') : 'flat';
        $min = max(0, input_int('min_days'));
        $max = max($min, input_int('max_days'));
        $data = [
            input_int('zone_id'), input('name'), $type,
            $type === 'free' ? 0 : (input_decimal('base_cost') ?? 0),
            $type === 'per_lb' ? (input_decimal('per_lb_cost') ?? 0) : 0,
            input_decimal('free_over'), $min, $max, input('is_active') === '0' ? 0 : 1,
        ];
        if (mb_strlen($data[1]) < 2 || !fetch_value('SELECT id FROM shipping_zones WHERE id = ?', [$data[0]])) {
            flash('error', 'Enter a method name.');
        } else {
            if ($id) {
                q('UPDATE shipping_methods SET zone_id=?, name=?, rate_type=?, base_cost=?, per_lb_cost=?, free_over=?, min_days=?, max_days=?, is_active=? WHERE id=?', array_merge($data, [$id]));
            } else {
                q('INSERT INTO shipping_methods (zone_id, name, rate_type, base_cost, per_lb_cost, free_over, min_days, max_days, is_active) VALUES (?,?,?,?,?,?,?,?,?)', $data);
            }
            log_activity('shipping_method_save', $data[1]);
            flash('success', "Shipping method “{$data[1]}” saved.");
        }
    }

    if ($action === 'delete_method') {
        q('DELETE FROM shipping_methods WHERE id = ?', [input_int('id')]);
        flash('success', 'Shipping method deleted.');
    }

    if ($action === 'save_general') {
        save_setting('ship_processing_days', (string) max(0, input_int('ship_processing_days')));
        save_setting('ship_origin_note', input('ship_origin_note'));
        save_setting('ship_customs_note', input('ship_customs_note'));
        flash('success', 'Shipping policy saved.');
    }
    redirect('admin/shipping.php');
}

$zones = fetch_all('SELECT * FROM shipping_zones ORDER BY sort_order, name');
$methods = [];
foreach (fetch_all('SELECT * FROM shipping_methods ORDER BY base_cost') as $m) $methods[$m['zone_id']][] = $m;
$editZone = input_int('zone') ? fetch_one('SELECT * FROM shipping_zones WHERE id = ?', [input_int('zone')]) : null;
$zoneRegions = $editZone ? explode(',', (string) $editZone['regions']) : [];
$usedElsewhere = [];
foreach ($zones as $z) if (!$editZone || $z['id'] != $editZone['id']) foreach (explode(',', (string) $z['regions']) as $s) $usedElsewhere[$s] = $z['name'];

function method_price(array $m): string
{
    $s = match ($m['rate_type']) {
        'free'   => 'Free',
        'per_lb' => money($m['base_cost']) . ' + ' . money($m['per_lb_cost']) . '/lb',
        default  => money($m['base_cost']),
    };
    if ($m['free_over'] !== null && $m['rate_type'] !== 'free') $s .= ' · free over ' . money($m['free_over']);
    return $s;
}

$pageTitle = 'Shipping';
$activeNav = 'shipping';
$crumb = 'Operations';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div><p class="mt-0">Rates are calculated at checkout from the customer's state, the order subtotal and the total package weight.</p></div>
</div>

<div class="grid grid-form">
  <div class="grid" style="align-content:start">
    <?php foreach ($zones as $z): $regionList = array_filter(explode(',', (string) $z['regions'])); ?>
      <div class="card">
        <div class="card-head">
          <div><h2><?= e($z['name']) ?> <?= $z['is_active'] ? '' : status_badge('inactive') ?></h2>
            <div class="sub"><?= count($regionList) ?> state(s): <?= e(implode(', ', array_slice($regionList, 0, 14))) ?><?= count($regionList) > 14 ? '…' : '' ?></div></div>
          <div class="form-actions">
            <button class="btn btn-sm btn-primary" data-modal="methodModal" data-fill-id="" data-fill-zone-id="<?= $z['id'] ?>" data-fill-name="" data-fill-rate-type="flat" data-fill-base-cost="" data-fill-per-lb-cost="" data-fill-free-over="" data-fill-min-days="7" data-fill-max-days="14" data-fill-is-active="1" data-fill-zone="<?= e($z['name']) ?>"><i class="bi bi-plus-lg"></i>Method</button>
            <a class="btn btn-sm btn-icon" href="?zone=<?= $z['id'] ?>" title="Edit zone"><i class="bi bi-pencil"></i></a>
            <?php if (admin_can('super_admin', 'admin')): ?>
            <form method="post" data-confirm="Delete zone “<?= e($z['name']) ?>” and all its shipping methods?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_zone"><input type="hidden" name="id" value="<?= $z['id'] ?>"><button class="btn btn-sm btn-icon btn-danger" title="Delete zone"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
          </div>
        </div>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Method</th><th>Price</th><th>Delivery</th><th>Status</th><th class="actions"></th></tr></thead>
          <tbody>
          <?php foreach ($methods[$z['id']] ?? [] as $m): ?>
            <tr>
              <td class="strong"><?= e($m['name']) ?></td>
              <td><?= method_price($m) ?></td>
              <td><?= (int) $m['min_days'] ?>–<?= (int) $m['max_days'] ?> business days</td>
              <td><?= status_badge($m['is_active'] ? 'active' : 'inactive') ?></td>
              <td class="actions">
                <button class="btn btn-sm btn-icon" title="Edit" data-modal="methodModal" data-fill-id="<?= $m['id'] ?>" data-fill-zone-id="<?= $z['id'] ?>" data-fill-name="<?= e($m['name']) ?>" data-fill-rate-type="<?= e($m['rate_type']) ?>" data-fill-base-cost="<?= e($m['base_cost']) ?>" data-fill-per-lb-cost="<?= e($m['per_lb_cost']) ?>" data-fill-free-over="<?= e($m['free_over']) ?>" data-fill-min-days="<?= (int) $m['min_days'] ?>" data-fill-max-days="<?= (int) $m['max_days'] ?>" data-fill-is-active="<?= (int) $m['is_active'] ?>" data-fill-zone="<?= e($z['name']) ?>"><i class="bi bi-pencil"></i></button>
                <form method="post" data-confirm="Delete “<?= e($m['name']) ?>”?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_method"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-sm btn-icon btn-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($methods[$z['id']])): ?><tr><td colspan="5" class="empty">No methods — customers in this zone can't check out yet.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$zones): ?><div class="card empty"><i class="bi bi-truck"></i>Create your first shipping zone.</div><?php endif; ?>
  </div>

  <div class="grid" style="align-content:start">
    <form class="card" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_zone"><input type="hidden" name="id" value="<?= $editZone ? (int) $editZone['id'] : '' ?>">
      <div class="card-head"><h2><?= $editZone ? 'Edit zone' : 'New zone' ?></h2><?php if ($editZone): ?><a class="btn btn-sm btn-ghost" href="<?= url('admin/shipping.php') ?>">Cancel</a><?php endif; ?></div>
      <div class="card-body">
        <div class="field"><label for="zname">Zone name</label><input class="input" id="zname" name="name" value="<?= e($editZone['name'] ?? '') ?>" placeholder="e.g. East Coast" required></div>
        <div class="field">
          <div class="label">States</div>
          <div style="max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px 10px;display:grid;grid-template-columns:1fr 1fr;gap:4px 10px">
            <?php foreach ($states as $code => $name): $taken = $usedElsewhere[$code] ?? null; ?>
              <label class="check small" title="<?= $taken ? 'In zone: ' . e($taken) : '' ?>" style="<?= $taken ? 'opacity:.45' : '' ?>">
                <input type="checkbox" name="regions[]" value="<?= $code ?>" <?= in_array($code, $zoneRegions, true) ? 'checked' : '' ?> <?= $taken ? 'disabled' : '' ?>> <?= e($name) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="hint">Greyed-out states already belong to another zone.</div>
        </div>
        <label class="check"><input type="checkbox" name="is_active" value="1" <?= !$editZone || $editZone['is_active'] ? 'checked' : '' ?>> Active</label>
      </div>
      <div class="card-foot form-actions"><button class="btn btn-primary" type="submit"><?= $editZone ? 'Save zone' : 'Create zone' ?></button></div>
    </form>

    <form class="card" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_general">
      <div class="card-head"><h2>Shipping policy</h2></div>
      <div class="card-body">
        <div class="field"><label for="ship_processing_days">Handling time before dispatch (business days)</label><input class="input" type="number" min="0" id="ship_processing_days" name="ship_processing_days" value="<?= e(setting('ship_processing_days', 3)) ?>"></div>
        <div class="field"><label for="ship_origin_note">Shipping note shown at checkout</label><textarea class="input" id="ship_origin_note" name="ship_origin_note" rows="3"><?= e(setting('ship_origin_note', '')) ?></textarea></div>
        <div class="field"><label for="ship_customs_note">Customs &amp; duties note</label><textarea class="input" id="ship_customs_note" name="ship_customs_note" rows="3"><?= e(setting('ship_customs_note', '')) ?></textarea></div>
      </div>
      <div class="card-foot form-actions"><button class="btn btn-primary btn-sm" type="submit">Save policy</button></div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="methodModal" role="dialog" aria-modal="true" aria-labelledby="methodTitle">
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_method"><input type="hidden" name="id"><input type="hidden" name="zone_id">
    <div class="card-head"><h3 id="methodTitle">Shipping method · <span data-text="zone"></span></h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="card-body">
      <div class="field"><label for="m_name">Name</label><input class="input" id="m_name" name="name" required placeholder="e.g. Standard Air (DHL)"></div>
      <div class="form-row">
        <div class="field"><label for="m_type">Rate type</label><select id="m_type" name="rate_type"><?php foreach ($rateTypes as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="m_active">Status</label><select id="m_active" name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
      </div>
      <div class="form-row three">
        <div class="field"><label for="m_base">Base cost</label><div class="input-group"><span class="addon">$</span><input class="input" id="m_base" name="base_cost" inputmode="decimal"></div></div>
        <div class="field"><label for="m_lb">Per lb</label><div class="input-group"><span class="addon">$</span><input class="input" id="m_lb" name="per_lb_cost" inputmode="decimal"></div></div>
        <div class="field"><label for="m_free">Free over</label><div class="input-group"><span class="addon">$</span><input class="input" id="m_free" name="free_over" inputmode="decimal" placeholder="—"></div></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="m_min">Min. days</label><input class="input" type="number" min="0" id="m_min" name="min_days"></div>
        <div class="field"><label for="m_max">Max. days</label><input class="input" type="number" min="0" id="m_max" name="max_days"></div>
      </div>
    </div>
    <div class="card-foot form-actions"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save method</button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
