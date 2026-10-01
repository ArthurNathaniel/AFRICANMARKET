<?php
require dirname(__DIR__) . '/partials/bootstrap.php';
$customer = require_customer();
$errors = [];

if (is_post()) {
    verify_csrf();
    $first = input('first_name');
    $last = input('last_name');
    $email = strtolower(input('email'));
    $phone = input('phone');
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $hash = (string) fetch_value('SELECT password_hash FROM customers WHERE id = ?', [$customer['id']]);

    if ($first === '' || $last === '') $errors[] = 'Enter your first and last name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($email !== $customer['email'] && fetch_value('SELECT id FROM customers WHERE email = ? AND id <> ?', [$email, $customer['id']])) $errors[] = 'That email is already used by another account.';
    if (($new !== '' || $email !== $customer['email']) && !password_verify($current, $hash)) $errors[] = 'Enter your current password to change your email or password.';
    if ($new !== '' && ($p = password_problem($new, (string) ($_POST['new_password_confirm'] ?? '')))) $errors[] = $p;

    if (!$errors) {
        q('UPDATE customers SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?', [$first, $last, $email, $phone ?: null, $customer['id']]);
        if ($new !== '') {
            q('UPDATE customers SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $customer['id']]);
            session_regenerate_id(true);
        }
        flash('success', 'Your profile has been updated.');
        redirect('account/profile.php');
    }
}

$v = fn($k) => e($_POST[$k] ?? $customer[$k] ?? '');
$pageTitle = 'Profile';
$accountTab = 'profile';
require dirname(__DIR__) . '/partials/header.php';
?>
<div class="wrap account-layout">
  <?php require dirname(__DIR__) . '/partials/account-nav.php'; ?>
  <div>
    <h1 style="font-size:30px;margin-bottom:18px">Profile &amp; password</h1>
    <form method="post" class="panel panel-body" style="max-width:640px">
      <?= csrf_field() ?>
      <?php if ($errors): ?><div class="alert alert-error"><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
      <div class="form-row">
        <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" value="<?= $v('first_name') ?>" autocomplete="given-name"></div>
        <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" value="<?= $v('last_name') ?>" autocomplete="family-name"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="email">Email</label><input class="input" type="email" id="email" name="email" value="<?= $v('email') ?>" autocomplete="email"></div>
        <div class="field"><label for="phone">Phone</label><input class="input" type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" autocomplete="tel"></div>
      </div>
      <h2 style="font-size:18px;margin:12px 0 12px">Change password</h2>
      <div class="field"><label for="current_password">Current password</label>
        <div class="pw-wrap"><input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password"><button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
        <div class="hint">Required to change your email or password.</div></div>
      <div class="form-row">
        <div class="field"><label for="new_password">New password</label><input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password"></div>
        <div class="field"><label for="new_password_confirm">Confirm new password</label><input class="input" type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password"></div>
      </div>
      <button class="btn btn-primary" type="submit">Save changes</button>
    </form>
  </div>
</div>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
