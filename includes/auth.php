<?php
/**
 * Admin authentication: login throttling, session handling, roles, password resets.
 */

require_once __DIR__ . '/functions.php';

const ADMIN_ROLES = ['super_admin' => 'Super admin', 'admin' => 'Admin', 'manager' => 'Manager'];

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) return $admin;

    $admin = null;
    if (empty($_SESSION['admin_id'])) return null;

    // Idle timeout
    $idle = time() - (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($idle > SESSION_IDLE_MINUTES * 60) {
        admin_logout();
        flash('info', 'You were signed out after a period of inactivity.');
        return null;
    }
    $_SESSION['admin_last_seen'] = time();

    $row = fetch_one('SELECT id, name, email, role, status FROM admins WHERE id = ?', [$_SESSION['admin_id']]);
    if (!$row || $row['status'] !== 'active') {
        admin_logout();
        return null;
    }
    return $admin = $row;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('admin/login.php' . ($next ? '?next=' . urlencode($next) : ''));
    }
    return $admin;
}

/** Restrict a page or action to the given roles. */
function require_role(string ...$roles): void
{
    $admin = require_admin();
    if (!in_array($admin['role'], $roles, true)) {
        flash('error', 'You do not have permission to do that.');
        redirect('admin/dashboard.php');
    }
}

function admin_can(string ...$roles): bool
{
    $admin = current_admin();
    return $admin && in_array($admin['role'], $roles, true);
}

/**
 * Throttle: LOGIN_MAX_ATTEMPTS failures per email, or 4x that per IP
 * (looser, so one office sharing an IP isn't locked out by a single typo-prone user).
 */
function too_many_attempts(string $email): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $byEmail = (int) fetch_value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > ?', [$email, $since]);
    $byIp = (int) fetch_value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?', [client_ip(), $since]);
    return $byEmail >= LOGIN_MAX_ATTEMPTS || $byIp >= LOGIN_MAX_ATTEMPTS * 4;
}

/**
 * Attempts a login. Returns null on success, or an error message.
 */
function admin_login(string $email, string $password): ?string
{
    $email = strtolower($email);
    if (too_many_attempts($email)) {
        return 'Too many failed attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again.';
    }

    $admin = fetch_one('SELECT * FROM admins WHERE email = ?', [$email]);
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        q('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())', [$email, client_ip()]);
        return 'Incorrect email or password.';
    }
    if ($admin['status'] === 'pending') {
        return 'Your account is awaiting approval by a super admin.';
    }
    if ($admin['status'] !== 'active') {
        return 'This account has been suspended. Contact a super admin.';
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }

    q('DELETE FROM login_attempts WHERE email = ?', [$email]);
    q('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$admin['id']]);

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_last_seen'] = time();
    log_activity('login', 'Signed in');
    return null;
}

function admin_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_last_seen']);
    session_regenerate_id(true);
}

/** Returns an error message, or null when the password is strong enough. */
function password_problem(string $password, string $confirm): ?string
{
    if (strlen($password) < 8) return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain both letters and numbers.';
    }
    if ($password !== $confirm) return 'Passwords do not match.';
    return null;
}

/* ---------------------------------------------------------------------------
 * Password resets (token is emailed; only its SHA-256 hash is stored)
 * ------------------------------------------------------------------------- */
function create_password_reset(string $userType, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM password_resets WHERE user_type = ? AND user_id = ?', [$userType, $userId]);
    q('INSERT INTO password_resets (user_type, user_id, token_hash, expires_at) VALUES (?, ?, ?, ?)',
      [$userType, $userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
    return $token;
}

function find_password_reset(string $userType, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return fetch_one(
        'SELECT * FROM password_resets WHERE user_type = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
        [$userType, hash('sha256', $token)]
    );
}
