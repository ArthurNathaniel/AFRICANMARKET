<?php
/**
 * Admin sign-up. Requires the invite code from config/config.php.
 * New accounts are created as "pending" and must be approved by a super admin
 * (Settings > Admin team) before they can sign in.
 */
define('ADMIN_PUBLIC', true);
require __DIR__ . '/includes/bootstrap.php';

if (current_admin()) redirect('admin/dashboard.php');

$errors = [];
$done = false;

if (is_post()) {
    verify_csrf();
    $name     = input('name');
    $email    = strtolower(input('email'));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    $code     = input('invite_code');

    if (mb_strlen($name) < 2) $errors[] = 'Enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($p = password_problem($password, $confirm)) $errors[] = $p;
    if (!hash_equals(ADMIN_INVITE_CODE, $code)) $errors[] = 'The invite code is not valid.';

    if (!$errors && fetch_value('SELECT id FROM admins WHERE email = ?', [$email])) {
        $errors[] = 'An account with that email already exists.';
    }

    if (!$errors) {
        q("INSERT INTO admins (name, email, password_hash, role, status) VALUES (?, ?, ?, 'manager', 'pending')",
          [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        log_activity('admin_signup', "New admin request: $email");
        $done = true;
    }
}

$pageTitle = 'Create account';
require __DIR__ . '/includes/auth_header.php';
?>
<?php if ($done): ?>
  <h1>Request received</h1>
  <p class="lead">Your account has been created and is <strong>awaiting approval</strong> by a super admin. You'll be able to sign in once it's approved.</p>
  <a class="btn btn-primary btn-block" href="<?= url('admin/login.php') ?>">Back to sign in</a>
<?php else: ?>
  <h1>Create admin account</h1>
  <p class="lead">You'll need the invite code from the store owner.</p>

  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-octagon"></i>
      <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="name">Full name</label>
      <input class="input" id="name" name="name" value="<?= old('name') ?>" autocomplete="name" required>
    </div>
    <div class="field">
      <label for="email">Work email</label>
      <input class="input" type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="email" required>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <div class="pw-wrap">
        <input class="input" type="password" id="password" name="password" autocomplete="new-password" required minlength="8">
        <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
      </div>
      <div class="hint">At least 8 characters, with letters and numbers.</div>
    </div>
    <div class="field">
      <label for="password_confirm">Confirm password</label>
      <input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
    </div>
    <div class="field">
      <label for="invite_code">Invite code</label>
      <input class="input" id="invite_code" name="invite_code" value="<?= old('invite_code') ?>" autocomplete="off" required>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Request account</button>
  </form>
<?php endif; ?>

<p class="auth-foot">Already have an account? <a href="<?= url('admin/login.php') ?>">Sign in</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
