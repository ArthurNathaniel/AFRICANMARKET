<?php
require __DIR__ . '/partials/bootstrap.php';

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
if (current_customer()) redirect('account/dashboard.php');

$errors = [];
if (is_post()) {
    verify_csrf();
    $first = input('first_name');
    $last = input('last_name');
    $email = strtolower(input('email'));
    $password = (string) ($_POST['password'] ?? '');

    if (mb_strlen($first) < 1 || mb_strlen($last) < 1) $errors[] = 'Enter your first and last name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($p = password_problem($password, $password)) $errors[] = $p;
    if (!isset($_POST['terms'])) $errors[] = 'Please accept the terms to continue.';

    // An existing record (even one without a password) can only be taken over by
    // proving ownership of the email through the reset-password link.
    if (!$errors && fetch_value('SELECT id FROM customers WHERE email = ?', [$email])) {
        $errors[] = 'An account with that email already exists. Sign in, or use "Forgot password" to set a new password.';
    }

    if (!$errors) {
        q('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)',
          [$first, $last, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) db()->lastInsertId();
        // Only guest orders placed in this browser session are attached (see sign_in_customer).
        sign_in_customer($id);
        send_mail($email, 'Welcome to ' . setting('store_name', APP_NAME), "Hi $first,\n\nYour account is ready. Happy shopping!\n\n" . absolute_url('shop.php'));
        flash('success', "Welcome, $first! Your account is ready.");
        if ($next) { header('Location: ' . $next); exit; }
        redirect('account/dashboard.php');
    }
}

$pageTitle = 'Create account';
require __DIR__ . '/partials/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>Create your account</h1>
    <p class="lead">Track orders, save addresses and keep a wishlist of things you love.</p>
    <?php if ($errors): ?><div class="alert alert-error" role="alert"><i class="bi bi-exclamation-circle"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="form-row">
        <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" value="<?= old('first_name') ?>" autocomplete="given-name" required></div>
        <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" value="<?= old('last_name') ?>" autocomplete="family-name" required></div>
      </div>
      <div class="field"><label for="email">Email</label><input class="input" type="email" id="email" name="email" value="<?= old('email', $_GET['email'] ?? '') ?>" autocomplete="email" required></div>
      <div class="field"><label for="password">Password</label>
        <div class="pw-wrap"><input class="input" type="password" id="password" name="password" autocomplete="new-password" required minlength="8"><button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
        <div class="hint">At least 8 characters, with letters and numbers.</div></div>
      <label class="check small" style="margin-bottom:18px"><input type="checkbox" name="terms" value="1" <?= isset($_POST['terms']) ? 'checked' : '' ?>> I agree to the terms of service and privacy policy.</label>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
    </form>
    <p class="foot">Already have an account? <a href="<?= url('login.php' . ($next ? '?next=' . urlencode($next) : '')) ?>">Sign in</a></p>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
