<?php
define('ADMIN_PUBLIC', true);
require __DIR__ . '/includes/bootstrap.php';

if (current_admin()) redirect('admin/dashboard.php');

$sent = false;
$devLink = null;
$error = null;

if (is_post()) {
    verify_csrf();
    $email = strtolower(input('email'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (too_many_attempts('reset:' . $email)) {
        $error = 'Too many requests. Please wait a few minutes and try again.';
    } else {
        // Throttle reset emails separately from login failures (prefixed key).
        q('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())', ['reset:' . $email, client_ip()]);

        $admin = fetch_one("SELECT id, name, email FROM admins WHERE email = ? AND status = 'active'", [$email]);
        if ($admin) {
            $token = create_password_reset('admin', (int) $admin['id']);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $link = preg_match('#^https?://#', base_url())
                ? url('admin/reset_password.php?token=' . $token)
                : $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('admin/reset_password.php?token=' . $token);

            $body = "Hi {$admin['name']},\n\nWe received a request to reset your " . setting('store_name', APP_NAME)
                  . " admin password. Use the link below within 1 hour:\n\n$link\n\n"
                  . "If you didn't ask for this, you can ignore this email — your password won't change.\n";
            send_mail($admin['email'], 'Reset your admin password', $body);
            log_activity('password_reset_requested', $admin['email']);

            // Local XAMPP usually can't deliver mail, so show the link while developing.
            if (APP_ENV === 'development') $devLink = $link;
        }
        // Same response whether or not the account exists (prevents email enumeration).
        $sent = true;
    }
}

$pageTitle = 'Forgot password';
require __DIR__ . '/includes/auth_header.php';
?>
<h1>Reset your password</h1>
<?php if ($sent): ?>
  <p class="lead">If an active admin account exists for that email, we've sent a reset link. It expires in 1 hour.</p>
  <?php if ($devLink): ?>
    <div class="alert alert-warning"><i class="bi bi-tools"></i>
      <div><strong>Development mode:</strong> local servers usually can't send email, so here is the link (hidden when APP_ENV is 'production'):<br>
        <a href="<?= e($devLink) ?>" style="word-break:break-all"><?= e($devLink) ?></a></div>
    </div>
  <?php endif; ?>
  <a class="btn btn-primary btn-block" href="<?= url('admin/login.php') ?>">Back to sign in</a>
<?php else: ?>
  <p class="lead">Enter your admin email and we'll send you a link to choose a new password.</p>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-octagon"></i><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="email">Email address</label>
      <input class="input" type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="email" required autofocus>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
  </form>
  <p class="auth-foot"><a href="<?= url('admin/login.php') ?>"><i class="bi bi-arrow-left"></i> Back to sign in</a></p>
<?php endif; ?>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
