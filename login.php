<?php
require __DIR__ . '/partials/bootstrap.php';

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
if (current_customer()) {
    if ($next) { header('Location: ' . $next); exit; }
    redirect('account/dashboard.php');
}

$error = null;
if (is_post()) {
    verify_csrf();
    $email = input('email');
    $password = (string) ($_POST['password'] ?? '');
    $error = ($email === '' || $password === '') ? 'Enter your email and password.' : customer_login($email, $password);
    if ($error === null) {
        flash('success', 'Welcome back!');
        if ($next) { header('Location: ' . $next); exit; }
        redirect('account/dashboard.php');
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>Welcome back</h1>
    <p class="lead">Sign in to track orders, save addresses and keep a wishlist.</p>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><i class="bi bi-exclamation-circle"></i><div><?= e($error) ?></div></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="email">Email</label><input class="input" type="email" id="email" name="email" value="<?= old('email') ?>" autocomplete="username" required autofocus></div>
      <div class="field"><label for="password">Password</label>
        <div class="pw-wrap"><input class="input" type="password" id="password" name="password" autocomplete="current-password" required><button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
      <p style="text-align:right;margin:-6px 0 16px" class="small"><a href="<?= url('forgot-password.php') ?>">Forgot password?</a></p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
    </form>
    <p class="foot">New here? <a href="<?= url('register.php' . ($next ? '?next=' . urlencode($next) : '')) ?>">Create an account</a></p>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
