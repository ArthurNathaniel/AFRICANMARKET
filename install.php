<?php
/**
 * One-time installer: creates the database + tables, the first super admin,
 * default settings and (optionally) demo data. Locks itself afterwards by
 * writing config/installed.lock — delete that file to re-run.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$errors = [];
$done = false;

if (is_installed()) {
    $pageTitle = 'Already installed';
    require __DIR__ . '/admin/includes/auth_header.php'; ?>
    <h1>Already installed</h1>
    <p class="lead">The store is set up. To reinstall, delete <code>config/installed.lock</code> (this does not delete data).</p>
    <a class="btn btn-primary btn-block" href="<?= url('admin/login.php') ?>">Go to admin sign in</a>
    <?php require __DIR__ . '/admin/includes/auth_footer.php';
    exit;
}

if (is_post()) {
    verify_csrf();
    $name = input('name');
    $email = strtolower(input('email'));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($name) < 2) $errors[] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($p = password_problem($password, $confirm)) $errors[] = $p;

    if (!$errors) {
        try {
            // 1. Database
            $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            // 2. Tables
            $sql = file_get_contents(__DIR__ . '/database/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
                if (trim($stmt) !== '') db()->exec($stmt);
            }

            // 3. Settings + admin
            $defaults = [
                'store_name' => APP_NAME, 'store_tagline' => APP_TAGLINE, 'support_email' => $email,
                'tax_rate' => '0', 'low_stock_threshold' => '5',
                'order_prefix' => 'JAM', 'stripe_mode' => 'test',
                'ship_processing_days' => '1',
                'ship_origin_note' => 'Orders usually ship within 1–2 business days.',
                'ship_customs_note' => '',
            ];
            foreach ($defaults as $k => $v) {
                q('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)', [$k, $v]);
            }
            q("INSERT INTO admins (name, email, password_hash, role, status) VALUES (?, ?, ?, 'super_admin', 'active')
               ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'super_admin', status = 'active'",
              [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $adminId = (int) fetch_value('SELECT id FROM admins WHERE email = ?', [$email]);

            // 4. Demo data
            if (isset($_POST['demo']) && (int) fetch_value('SELECT COUNT(*) FROM products') === 0) {
                require __DIR__ . '/database/demo_seed.php';
                seed_demo_data($adminId);
            }

            file_put_contents(__DIR__ . '/config/installed.lock', 'Installed ' . date('c'));
            $done = true;
        } catch (Throwable $ex) {
            $errors[] = 'Installation failed: ' . $ex->getMessage();
            if (stripos($ex->getMessage(), 'Access denied') !== false || stripos($ex->getMessage(), 'Connection refused') !== false
                || stripos($ex->getMessage(), 'No connection') !== false) {
                $errors[] = 'Check that MySQL is running in the XAMPP control panel and that the DB_* values in config/config.php are correct.';
            }
        }
    }
}

$pageTitle = 'Install';
require __DIR__ . '/admin/includes/auth_header.php';
?>
<?php if ($done): ?>
  <h1>You're all set</h1>
  <p class="lead">The database is ready and your super admin account has been created.</p>
  <div class="alert alert-warning"><i class="bi bi-shield-exclamation"></i><div>Before going live: change <code>ADMIN_INVITE_CODE</code> and set <code>APP_ENV</code> to <code>'production'</code> in <code>config/config.php</code>.</div></div>
  <a class="btn btn-primary btn-block" href="<?= url('admin/login.php') ?>">Sign in to the admin <i class="bi bi-arrow-right"></i></a>
<?php else: ?>
  <h1>Install <?= e(APP_NAME) ?></h1>
  <p class="lead">Creates the <code><?= e(DB_NAME) ?></code> database on <code><?= e(DB_HOST) ?></code> and your super admin account.</p>
  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-octagon"></i><div><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="name">Your name</label><input class="input" id="name" name="name" value="<?= old('name') ?>" required autofocus></div>
    <div class="field"><label for="email">Admin email</label><input class="input" type="email" id="email" name="email" value="<?= old('email') ?>" required></div>
    <div class="field"><label for="password">Password</label>
      <div class="pw-wrap"><input class="input" type="password" id="password" name="password" autocomplete="new-password" required><button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
      <div class="hint">At least 8 characters, with letters and numbers.</div></div>
    <div class="field"><label for="password_confirm">Confirm password</label><input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required></div>
    <div class="field"><label class="check"><input type="checkbox" name="demo" value="1" <?= !is_post() || isset($_POST['demo']) ? 'checked' : '' ?>> Load demo data (products, customers, orders)</label></div>
    <button class="btn btn-primary btn-block" type="submit">Install</button>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/admin/includes/auth_footer.php'; ?>
