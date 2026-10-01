<?php
/**
 * Customer authentication. Uses the same throttle table as admin login,
 * with "customer:"-prefixed keys so the two account types never collide.
 */

require_once __DIR__ . '/auth.php';

function current_customer(): ?array
{
    static $customer = false;
    if ($customer !== false) return $customer;
    $customer = null;
    if (empty($_SESSION['customer_id'])) return null;

    $row = fetch_one('SELECT id, first_name, last_name, email, phone, status FROM customers WHERE id = ?', [$_SESSION['customer_id']]);
    if (!$row || $row['status'] !== 'active') {
        unset($_SESSION['customer_id']);
        return null;
    }
    return $customer = $row;
}

function require_customer(): array
{
    $c = current_customer();
    if (!$c) {
        flash('info', 'Please sign in to continue.');
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    return $c;
}

/** Returns null on success or an error message. */
function customer_login(string $email, string $password): ?string
{
    $email = strtolower($email);
    $key = 'customer:' . $email;
    if (too_many_attempts($key)) {
        return 'Too many failed attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again.';
    }
    $c = fetch_one('SELECT id, password_hash, status FROM customers WHERE email = ?', [$email]);
    if (!$c || !$c['password_hash'] || !password_verify($password, $c['password_hash'])) {
        q('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())', [$key, client_ip()]);
        return 'Incorrect email or password.';
    }
    if ($c['status'] !== 'active') return 'This account has been suspended. Please contact support.';

    q('DELETE FROM login_attempts WHERE email = ?', [$key]);
    q('UPDATE customers SET last_login_at = NOW() WHERE id = ?', [$c['id']]);
    sign_in_customer((int) $c['id']);
    return null;
}

function sign_in_customer(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['customer_id'] = $id;
    // Guest orders placed earlier in this same browser session now belong to the account.
    if (!empty($_SESSION['my_orders'])) {
        $ids = array_map('intval', $_SESSION['my_orders']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $email = fetch_value('SELECT email FROM customers WHERE id = ?', [$id]);
        q("UPDATE orders SET customer_id = ? WHERE customer_id IS NULL AND email = ? AND id IN ($in)", array_merge([$id, $email], $ids));
    }
}

/** Only allow same-site relative redirect targets. */
function safe_next(string $next): string
{
    return preg_match('#^/(?!/)[^\s]*$#', $next) ? $next : '';
}
