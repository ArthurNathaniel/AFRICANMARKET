<?php
require __DIR__ . '/partials/bootstrap.php';

$type = 'customer';
$table = 'customers';
$token = input('token');
$reset = find_password_reset($type, $token);
$error = null;

if ($reset && is_post()) {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    if (!($error = password_problem($password, (string) ($_POST['password_confirm'] ?? '')))) {
        db()->beginTransaction();
        q("UPDATE $table SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        q('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$reset['id']]);
        db()->commit();
        $email = fetch_value("SELECT email FROM $table WHERE id = ?", [$reset['user_id']]);
        q('DELETE FROM login_attempts WHERE email = ?', ["$type:$email"]);
        flash('success', 'Your password has been updated. Please sign in.');
        redirect('login.php');
    }
}

$pageTitle = 'Choose a new password';
require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <?php if (!$reset): ?>
      <h1>Link expired</h1>
      <p class="lead">This reset link is invalid, already used, or older than 1 hour.</p>
      <a class="btn btn-primary btn-block" href="<?= url('forgot-password.php?type=' . $type) ?>">Request a new link</a>
    <?php else: ?>
      <h1>Choose a new password</h1>
      <p class="lead">At least 8 characters, with letters and numbers.</p>
      <?php if ($error): ?><div class="alert alert-error"><div><?= e($error) ?></div></div><?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="password">New password</label>
          <div class="pw-wrap"><input class="input" type="password" id="password" name="password" autocomplete="new-password" required autofocus><button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
        <div class="field"><label for="password_confirm">Confirm new password</label><input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Update password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
