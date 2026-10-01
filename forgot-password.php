<?php
/**
 * Customer password reset request.
 */
require __DIR__ . '/partials/bootstrap.php';

$type = 'customer';
$table = 'customers';
$sent = false;
$devLink = null;
$error = null;

if (is_post()) {
    verify_csrf();
    $email = strtolower(input('email'));
    $key = "reset-$type:$email";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (too_many_attempts($key)) {
        $error = 'Too many requests. Please wait a few minutes and try again.';
    } else {
        q('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())', [$key, client_ip()]);
        $user = fetch_one("SELECT id, email FROM $table WHERE email = ? AND status = 'active'", [$email]);
        if ($user) {
            $link = absolute_url('reset-password.php?type=' . $type . '&token=' . create_password_reset($type, (int) $user['id']));
            send_mail($user['email'], 'Reset your password',
                "We received a request to reset your password. Use this link within 1 hour:\n\n$link\n\nIf you didn't ask for this, you can ignore this email.");
            if (APP_ENV === 'development') $devLink = $link;
        }
        $sent = true; // same response either way, so emails can't be enumerated
    }
}

$pageTitle = 'Reset password';
require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>Reset your password</h1>
    <?php if ($sent): ?>
      <p class="lead">If an account exists for that email, we've sent a reset link. It expires in 1 hour.</p>
      <?php if ($devLink): ?><div class="alert alert-warning small"><div><strong>Development mode</strong> — local servers can't send email, so here's the link:<br><a href="<?= e($devLink) ?>" style="word-break:break-all"><?= e($devLink) ?></a></div></div><?php endif; ?>
      <a class="btn btn-primary btn-block" href="<?= url('login.php') ?>">Back to sign in</a>
    <?php else: ?>
      <p class="lead">Enter the email for your shopping account and we'll send you a reset link.</p>
      <?php if ($error): ?><div class="alert alert-error"><div><?= e($error) ?></div></div><?php endif; ?>
      <form method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
        <div class="field"><label for="email">Email</label><input class="input" type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="email" required autofocus></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link</button>
      </form>
      <p class="foot"><a href="<?= url('login.php') ?>"><i class="bi bi-arrow-left"></i> Back to sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
