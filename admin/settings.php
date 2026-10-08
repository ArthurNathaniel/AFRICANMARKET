<?php
require __DIR__ . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/shop.php';

$tabs = [
    'general'  => ['Store', 'bi-shop-window', ['super_admin', 'admin']],
    'commerce' => ['Commerce', 'bi-sliders', ['super_admin', 'admin']],
    'payments' => ['Payment gateways', 'bi-credit-card-2-front', ['super_admin']],
    'team'     => ['Admin team', 'bi-people', ['super_admin']],
    'profile'  => ['My profile', 'bi-person', ['super_admin', 'admin', 'manager']],
];
$tab = input('tab', admin_can('super_admin', 'admin') ? 'general' : 'profile');
if (!isset($tabs[$tab]) || !admin_can(...$tabs[$tab][2])) $tab = 'profile';

$secretKeys = ['stripe_test_secret_key', 'stripe_test_webhook_secret', 'stripe_live_secret_key', 'stripe_live_webhook_secret'];
$fields = [
    'general'  => ['store_name', 'store_tagline', 'support_email', 'support_phone', 'whatsapp_number', 'business_address'],
    'commerce' => ['tax_rate', 'order_prefix', 'low_stock_threshold'],
    'payments' => ['stripe_mode', 'stripe_test_publishable_key', 'stripe_test_secret_key', 'stripe_test_webhook_secret',
                   'stripe_live_publishable_key', 'stripe_live_secret_key', 'stripe_live_webhook_secret'],
];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if (isset($fields[$action])) {
        require_role(...$tabs[$action][2]);
        if ($action === 'general' && !filter_var(input('support_email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid support email.';
        if ($action === 'commerce') {
            $tax = input_decimal('tax_rate');
            if ($tax === null || $tax < 0 || $tax > 30) $errors[] = 'Tax rate must be between 0 and 30%.';
            if (!preg_match('/^[A-Z0-9]{2,6}$/', strtoupper(input('order_prefix')))) $errors[] = 'Order prefix must be 2–6 letters or numbers.';
        }
        if ($action === 'payments') {
            if (!in_array(input('stripe_mode'), ['test', 'live'], true)) $errors[] = 'Choose test or live mode.';
            foreach (['test' => 'Test', 'live' => 'Live'] as $m => $label) {
                $pk = input("stripe_{$m}_publishable_key");
                $sk = input("stripe_{$m}_secret_key");
                $wh = input("stripe_{$m}_webhook_secret");
                if ($pk !== '' && strpos($pk, "pk_{$m}_") !== 0) $errors[] = "$label publishable key must start with pk_{$m}_";
                if ($sk !== '' && !preg_match("/^(sk|rk)_{$m}_/", $sk)) $errors[] = "$label secret key must start with sk_{$m}_ (or rk_{$m}_)";
                if ($wh !== '' && strpos($wh, 'whsec_') !== 0) $errors[] = "$label webhook signing secret must start with whsec_";
            }
            if (input('stripe_mode') === 'live' && input('stripe_live_secret_key') === '' && stripe_key('secret_key', 'live') === '') {
                $errors[] = 'Add your live secret key before switching to live mode.';
            }
        }
        if (!$errors) {
            foreach ($fields[$action] as $key) {
                $value = input($key);
                if (in_array($key, $secretKeys, true) && $value === '') continue; // blank = keep existing secret
                if ($key === 'order_prefix') $value = strtoupper($value);
                save_setting($key, $value);
            }
            log_activity('settings_' . $action, 'Updated ' . $tabs[$action][0] . ' settings');
            flash('success', $tabs[$action][0] . ' settings saved.');
            redirect('admin/settings.php?tab=' . $action);
        }
        $tab = $action;
    }

    if ($action === 'stripe_check') {
        require_role('super_admin');
        $mode = input('mode') === 'live' ? 'live' : 'test';
        try {
            flash('success', stripe_check_connection($mode));
        } catch (RuntimeException $ex) {
            flash('error', 'Stripe ' . $mode . ' connection failed: ' . $ex->getMessage());
        }
        redirect('admin/settings.php?tab=payments');
    }

    if ($action === 'team') {
        require_role('super_admin');
        $target = fetch_one('SELECT * FROM admins WHERE id = ?', [input_int('id')]);
        $op = input('op');
        if (!$target || (int) $target['id'] === (int) $admin['id']) {
            flash('error', 'You cannot change your own account here.');
        } else {
            if ($op === 'approve' || $op === 'activate') q("UPDATE admins SET status = 'active' WHERE id = ?", [$target['id']]);
            if ($op === 'suspend') q("UPDATE admins SET status = 'suspended' WHERE id = ?", [$target['id']]);
            if ($op === 'role' && isset(ADMIN_ROLES[input('role')])) q('UPDATE admins SET role = ? WHERE id = ?', [input('role'), $target['id']]);
            if ($op === 'delete') q('DELETE FROM admins WHERE id = ?', [$target['id']]);
            log_activity('admin_' . $op, $target['email']);
            flash('success', "Updated {$target['name']}.");
        }
        redirect('admin/settings.php?tab=team');
    }

    if ($action === 'profile') {
        $name = input('name');
        $email = strtolower(input('email'));
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $hash = fetch_value('SELECT password_hash FROM admins WHERE id = ?', [$admin['id']]);

        if (mb_strlen($name) < 2) $errors[] = 'Enter your name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if (fetch_value('SELECT id FROM admins WHERE email = ? AND id <> ?', [$email, $admin['id']])) $errors[] = 'Another admin already uses that email.';
        $changingSensitive = $new !== '' || $email !== $admin['email'];
        if ($changingSensitive && !password_verify($current, $hash)) $errors[] = 'Enter your current password to change your email or password.';
        if ($new !== '' && ($p = password_problem($new, (string) ($_POST['new_password_confirm'] ?? '')))) $errors[] = $p;

        if (!$errors) {
            q('UPDATE admins SET name = ?, email = ? WHERE id = ?', [$name, $email, $admin['id']]);
            if ($new !== '') {
                q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
                session_regenerate_id(true);
            }
            log_activity('profile_update', $new !== '' ? 'Changed password' : 'Updated profile');
            flash('success', 'Profile updated.');
            redirect('admin/settings.php?tab=profile');
        }
        $tab = 'profile';
    }
}

$v = fn($k, $d = '') => e($_POST[$k] ?? setting($k, $d));
$sel = fn($k, $x, $d = '') => (string) ($_POST[$k] ?? setting($k, $d)) === (string) $x ? 'selected' : '';
$stripeHint = function (string $mode, string $name) {
    $val = stripe_key($name, $mode);
    return $val !== '' ? 'Saved (ends in …' . e(substr($val, -4)) . '). Leave blank to keep.' : 'Not set';
};

$pageTitle = 'Settings';
$activeNav = 'settings';
$crumb = 'Operations';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="tabs">
    <?php foreach ($tabs as $key => [$label, $icon, $roles]): if (!admin_can(...$roles)) continue; ?>
      <a href="?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>"><i class="bi <?= $icon ?>"></i> <?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card-body">
    <?php if ($errors): ?><div class="alert alert-error"><i class="bi bi-exclamation-octagon"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>

    <?php if ($tab === 'general'): ?>
      <form method="post" style="max-width:720px">
        <?= csrf_field() ?><input type="hidden" name="action" value="general">
        <div class="form-row">
          <div class="field"><label for="store_name">Store name</label><input class="input" id="store_name" name="store_name" value="<?= $v('store_name', APP_NAME) ?>" required></div>
          <div class="field"><label for="store_tagline">Tagline</label><input class="input" id="store_tagline" name="store_tagline" value="<?= $v('store_tagline', APP_TAGLINE) ?>"></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="support_email">Support email</label><input class="input" type="email" id="support_email" name="support_email" value="<?= $v('support_email') ?>" required></div>
          <div class="field"><label for="support_phone">Support phone</label><input class="input" id="support_phone" name="support_phone" value="<?= $v('support_phone') ?>" placeholder="+1 (555) 000-0000"><div class="hint">Separate multiple numbers with commas.</div></div>
        </div>
        <div class="field"><label for="whatsapp_number">WhatsApp number for orders</label><input class="input" id="whatsapp_number" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>" placeholder="(555) 000-0000">
          <div class="hint">Shows “Order via WhatsApp” buttons in the store. Leave blank to hide them.</div></div>
        <div class="field"><label for="business_address">US business address</label><textarea class="input" id="business_address" name="business_address" rows="3"><?= $v('business_address') ?></textarea>
          <div class="hint">Shown on invoices and used as the returns address.</div></div>
        <div class="form-actions" style="justify-content:flex-start"><button class="btn btn-primary" type="submit">Save store settings</button></div>
      </form>

    <?php elseif ($tab === 'commerce'): ?>
      <form method="post" style="max-width:720px">
        <?= csrf_field() ?><input type="hidden" name="action" value="commerce">
        <div class="form-row">
          <div class="field"><label>Currency</label><input class="input" value="USD — US Dollar" disabled><div class="hint">All prices are listed and charged in US dollars.</div></div>
          <div class="field"><label for="order_prefix">Order number prefix</label><input class="input" id="order_prefix" name="order_prefix" value="<?= $v('order_prefix', 'JAM') ?>" style="text-transform:uppercase"></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="tax_rate">Default sales tax rate</label><div class="input-group"><input class="input" id="tax_rate" name="tax_rate" inputmode="decimal" value="<?= $v('tax_rate', '0') ?>"><span class="addon">%</span></div>
            <div class="hint">For state-accurate tax, connect a tax service at checkout.</div></div>
          <div class="field"><label for="low_stock_threshold">Low-stock alert at</label><div class="input-group"><input class="input" type="number" min="0" id="low_stock_threshold" name="low_stock_threshold" value="<?= $v('low_stock_threshold', '5') ?>"><span class="addon">units</span></div>
            <div class="hint">Shown as “Only X left” in the store.</div></div>
        </div>
        <div class="form-actions" style="justify-content:flex-start"><button class="btn btn-primary" type="submit">Save commerce settings</button></div>
      </form>

    <?php elseif ($tab === 'payments'): ?>
      <form method="post" style="max-width:720px" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="payments">
        <div class="alert alert-info"><i class="bi bi-shield-lock"></i><div>Secret keys are never shown again after saving. On a production server, prefer storing them as environment variables.</div></div>
        <h3 style="font-size:15px;margin:8px 0 12px"><i class="bi bi-stripe"></i> Stripe
          <?php if (!stripe_enabled()): ?><span class="badge badge-neutral">Not configured</span>
          <?php elseif (stripe_test_mode()): ?><span class="badge badge-warning">Test mode — no real charges</span>
          <?php else: ?><span class="badge badge-success">Live — taking real payments</span><?php endif; ?></h3>
        <p class="small text-2" style="margin:0 0 12px">Find your keys in the Stripe dashboard under Developers → API keys. Test keys come from the dashboard's test mode and start with <code>pk_test_</code> / <code>sk_test_</code>; live keys start with <code>pk_live_</code> / <code>sk_live_</code>. Keep using test mode until a full order has gone through end to end.</p>
        <div class="form-row">
          <div class="field"><label for="stripe_mode">Active mode</label><select id="stripe_mode" name="stripe_mode">
            <option value="test" <?= $sel('stripe_mode', 'test', 'test') ?>>Test (use test cards, no real money)</option>
            <option value="live" <?= $sel('stripe_mode', 'live', 'test') ?>>Live (real payments)</option></select></div>
          <div class="field"><label>Webhook endpoint</label><input class="input" value="<?= e(absolute_url('stripe-webhook.php')) ?>" readonly onclick="this.select()">
            <div class="hint">Add it in Stripe (in each mode) with the event <code>checkout.session.completed</code>.</div></div>
        </div>
        <?php foreach (['test' => 'Test keys', 'live' => 'Live keys'] as $m => $label): ?>
          <h4 style="font-size:14px;margin:18px 0 10px"><?= $label ?></h4>
          <div class="form-row three">
            <div class="field"><label for="stripe_<?= $m ?>_publishable_key">Publishable key</label><input class="input" id="stripe_<?= $m ?>_publishable_key" name="stripe_<?= $m ?>_publishable_key" value="<?= e($_POST["stripe_{$m}_publishable_key"] ?? stripe_key('publishable_key', $m)) ?>" placeholder="pk_<?= $m ?>_…"></div>
            <div class="field"><label for="stripe_<?= $m ?>_secret_key">Secret key</label><input class="input" type="password" id="stripe_<?= $m ?>_secret_key" name="stripe_<?= $m ?>_secret_key" placeholder="sk_<?= $m ?>_…" autocomplete="new-password"><div class="hint"><?= $stripeHint($m, 'secret_key') ?></div></div>
            <div class="field"><label for="stripe_<?= $m ?>_webhook_secret">Webhook signing secret</label><input class="input" type="password" id="stripe_<?= $m ?>_webhook_secret" name="stripe_<?= $m ?>_webhook_secret" placeholder="whsec_…" autocomplete="new-password"><div class="hint"><?= $stripeHint($m, 'webhook_secret') ?></div></div>
          </div>
          <button class="btn btn-sm" type="submit" form="stripeCheck<?= ucfirst($m) ?>"><i class="bi bi-plug"></i>Test <?= $m ?> connection</button>
        <?php endforeach; ?>
        <?php if (stripe_test_mode()): ?>
          <div class="alert alert-warning mt"><i class="bi bi-credit-card"></i><div><strong>Test cards</strong> (any future expiry, any CVC, any ZIP):
            <?php foreach (STRIPE_TEST_CARDS as $card => $result): ?><div><code><?= e($card) ?></code> — <?= e($result) ?></div><?php endforeach; ?></div></div>
        <?php endif; ?>
        <div class="form-actions mt" style="justify-content:flex-start"><button class="btn btn-primary" type="submit">Save gateways</button></div>
      </form>
      <?php foreach (['test', 'live'] as $m): ?>
        <form method="post" id="stripeCheck<?= ucfirst($m) ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="stripe_check"><input type="hidden" name="mode" value="<?= $m ?>"></form>
      <?php endforeach; ?>

    <?php elseif ($tab === 'team'):
      $team = fetch_all('SELECT id, name, email, role, status, last_login_at, created_at FROM admins ORDER BY FIELD(status, \'pending\') DESC, created_at'); ?>
      <p class="text-2 mt-0">New admins sign up at <code><?= e(url('admin/signup.php')) ?></code> using the invite code set in <code>config/config.php</code>, then wait here for approval.</p>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Admin</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($team as $t): $self = (int) $t['id'] === (int) $admin['id']; ?>
          <tr>
            <td><div class="cell-main"><span class="avatar gold"><?= e(initials($t['name'])) ?></span><div><div class="title"><?= e($t['name']) ?><?= $self ? ' <span class="muted">(you)</span>' : '' ?></div><div class="meta"><?= e($t['email']) ?></div></div></div></td>
            <td>
              <?php if ($self): ?><?= e(ADMIN_ROLES[$t['role']]) ?><?php else: ?>
              <form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="team"><input type="hidden" name="op" value="role"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                <select name="role" onchange="this.form.submit()" style="width:auto;padding:5px 8px"><?php foreach (ADMIN_ROLES as $rk => $rl): ?><option value="<?= $rk ?>" <?= $t['role'] === $rk ? 'selected' : '' ?>><?= $rl ?></option><?php endforeach; ?></select>
              </form><?php endif; ?>
            </td>
            <td><?= status_badge($t['status']) ?></td>
            <td><?= time_ago($t['last_login_at']) ?></td>
            <td class="actions"><?php if (!$self): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="team"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                <?php if ($t['status'] === 'pending'): ?><button class="btn btn-sm btn-primary" name="op" value="approve">Approve</button>
                <?php elseif ($t['status'] === 'suspended'): ?><button class="btn btn-sm" name="op" value="activate">Reactivate</button>
                <?php else: ?><button class="btn btn-sm" name="op" value="suspend">Suspend</button><?php endif; ?>
              </form>
              <form method="post" data-confirm="Remove <?= e($t['name']) ?> from the admin team?"><?= csrf_field() ?><input type="hidden" name="action" value="team"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-sm btn-icon btn-danger" name="op" value="delete" title="Remove"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <dl class="dl mt small">
        <dt>Super admin</dt><dd>Everything, including payment keys and the admin team.</dd>
        <dt>Admin</dt><dd>Run the shop: products, orders, refunds, customers and store settings.</dd>
        <dt>Manager</dt><dd>Day-to-day operations: update products and stock, moderate reviews, pack and ship orders. Cannot delete products or issue refunds.</dd>
      </dl>

    <?php else: ?>
      <form method="post" style="max-width:560px">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="field"><label for="name">Name</label><input class="input" id="name" name="name" value="<?= e($_POST['name'] ?? $admin['name']) ?>" required></div>
        <div class="field"><label for="email">Email</label><input class="input" type="email" id="email" name="email" value="<?= e($_POST['email'] ?? $admin['email']) ?>" required></div>
        <div class="field"><label>Role</label><input class="input" value="<?= e(ADMIN_ROLES[$admin['role']]) ?>" disabled></div>
        <h3 style="font-size:15px;margin:22px 0 12px">Change password</h3>
        <div class="field"><label for="current_password">Current password</label><div class="pw-wrap"><input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password"><button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
          <div class="hint">Required when changing your email or password.</div></div>
        <div class="form-row">
          <div class="field"><label for="new_password">New password</label><input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password"></div>
          <div class="field"><label for="new_password_confirm">Confirm new password</label><input class="input" type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password"></div>
        </div>
        <div class="form-actions" style="justify-content:flex-start"><button class="btn btn-primary" type="submit">Save profile</button></div>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
