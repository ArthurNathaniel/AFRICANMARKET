<?php
define('ADMIN_PUBLIC', true);
require __DIR__ . '/includes/bootstrap.php';

$token = input('token');
$reset = find_password_reset('admin', $token);
$error = null;

if ($reset && is_post()) {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($error = password_problem($password, $confirm)) {
        // fall through to re-render the form
    } else {
        db()->beginTransaction();
        q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        q('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$reset['id']]);
        q("DELETE FROM password_resets WHERE user_type = 'admin' AND user_id = ? AND id <> ?", [$reset['user_id'], $reset['id']]);
        db()->commit();

        $email = fetch_value('SELECT email FROM admins WHERE id = ?', [$reset['user_id']]);
        q('DELETE FROM login_attempts WHERE email = ?', [$email]);
        admin_logout();
        flash('success', 'Your password has been updated. Sign in with your new password.');
        redirect('admin/login.php');
    }
}

$pageTitle = 'Choose a new password';
require __DIR__ . '/includes/auth_header.php';
?>
<?php if (!$reset): ?>
  <h1>Link expired</h1>
  <p class="lead">This password reset link is invalid, has already been used, or has expired. Request a new one to continue.</p>
  <a class="btn btn-primary btn-block" href="<?= url('admin/forgot_password.php') ?>">Request a new link</a>
<?php else: ?>
  <h1>Choose a new password</h1>
  <p class="lead">Pick something you haven't used before.</p>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-octagon"></i><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="field">
      <label for="password">New password</label>
      <div class="pw-wrap">
        <input class="input" type="password" id="password" name="password" autocomplete="new-password" required minlength="8" autofocus>
        <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
      </div>
      <div class="hint">At least 8 characters, with letters and numbers.</div>
    </div>
    <div class="field">
      <label for="password_confirm">Confirm new password</label>
      <input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Update password</button>
  </form>
<?php endif; ?>
<p class="auth-foot"><a href="<?= url('admin/login.php') ?>"><i class="bi bi-arrow-left"></i> Back to sign in</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
