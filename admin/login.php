<?php
define('ADMIN_PUBLIC', true);
require __DIR__ . '/includes/bootstrap.php';

if (current_admin()) redirect('admin/dashboard.php');

// Only allow local, same-site redirect targets after login.
$next = $_GET['next'] ?? $_POST['next'] ?? '';
if (!is_string($next) || !preg_match('#^/(?!/)[^\s]*$#', $next) || strpos($next, '/admin/') === false) {
    $next = '';
}

$error = null;
if (is_post()) {
    verify_csrf();
    $email = input('email');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } else {
        $error = admin_login($email, $password);
        if ($error === null) {
            if ($next) { header('Location: ' . $next); exit; }
            redirect('admin/dashboard.php');
        }
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/includes/auth_header.php';
?>
<h1>Welcome back</h1>
<p class="lead">Sign in to the admin console.</p>

<?php if ($error): ?>
  <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-octagon"></i><div><?= e($error) ?></div></div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="field">
    <label for="email">Email address</label>
    <input class="input" type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="username" required autofocus>
  </div>
  <div class="field">
    <label for="password">Password</label>
    <div class="pw-wrap">
      <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
      <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
    </div>
  </div>
  <div class="auth-links">
    <span></span>
    <a href="<?= url('admin/forgot_password.php') ?>">Forgot password?</a>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Sign in <i class="bi bi-arrow-right"></i></button>
</form>

<p class="auth-foot">New team member? <a href="<?= url('admin/signup.php') ?>">Request an admin account</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
