<?php
/**
 * Shared helpers used by the storefront, customer accounts and admin panel.
 */

require_once dirname(__DIR__) . '/config/config.php';

/* ---------------------------------------------------------------------------
 * Session
 * ------------------------------------------------------------------------- */
if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('afm_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------------------------------------------------------------------------
 * Database
 * ------------------------------------------------------------------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep MySQL NOW()/CURRENT_TIMESTAMP in the same timezone as PHP's date().
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function fetch_value(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function is_installed(): bool
{
    return is_file(ROOT_PATH . '/config/installed.lock');
}

/* ---------------------------------------------------------------------------
 * URLs, output, requests
 * ------------------------------------------------------------------------- */
function base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;
    if (BASE_URL !== '') return $base = rtrim(BASE_URL, '/');

    $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root    = str_replace('\\', '/', (string) realpath(ROOT_PATH));
    $path    = ($docRoot !== '' && stripos($root, $docRoot) === 0) ? substr($root, strlen($docRoot)) : '';
    return $base = rtrim($path, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** Logo badge + wordmark. $tone: 'dark' text for light backgrounds, 'light' for dark ones. */
function brand_lockup(string $tone = 'dark', string $class = ''): string
{
    return '<span class="brand-lockup brand-' . $tone . ($class ? ' ' . $class : '') . '">'
         . '<img src="' . asset('img/logo-badge-160.png') . '" alt="" width="160" height="160">'
         . '<span class="brand-words"><strong>Jenmond</strong><small>Mobile African Market</small></span></span>';
}

/** Favicon links for <head>. */
function favicon_tags(): string
{
    return '<link rel="icon" type="image/png" sizes="64x64" href="' . asset('img/favicon.png') . '">'
         . "\n  " . '<link rel="apple-touch-icon" href="' . asset('img/apple-touch-icon.png') . '">';
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $target);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string input from POST (falls back to GET). */
function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function input_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

/** Decimal input or null when blank. */
function input_decimal(string $key): ?float
{
    $v = input($key);
    if ($v === '') return null;
    $v = str_replace([',', '$'], '', $v);
    return is_numeric($v) ? round((float) $v, 2) : null;
}

/** Previously submitted value, for re-filling forms after a validation error. */
function old(string $key, $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

/** Current query string with some keys replaced/removed (null removes). */
function query_with(array $changes): string
{
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $params ? '?' . http_build_query($params) : '?';
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/* ---------------------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        flash('error', 'Your session expired or the form was tampered with. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? url('admin/dashboard.php');
        header('Location: ' . $back);
        exit;
    }
}

/* ---------------------------------------------------------------------------
 * Flash messages
 * ------------------------------------------------------------------------- */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------------------------------------------------------------------
 * Settings (key/value table)
 * ------------------------------------------------------------------------- */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (fetch_all('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) {
            // settings table not available yet (e.g. during install)
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function save_setting(string $key, ?string $value): void
{
    q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

/** Support phone numbers from settings (comma-separated). */
function support_phones(): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) setting('support_phone', '')))));
}

/** tel: link target for a US phone number, e.g. "(987) 908-6393" → "+19879086393". */
function phone_href(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) === 10 ? '+1' . $digits : '+' . $digits;
}

/** WhatsApp chat link with a prefilled message, or '' when no WhatsApp number is set. */
function whatsapp_url(string $text = ''): string
{
    $number = (string) setting('whatsapp_number', '');
    if ($number === '') return '';
    return 'https://wa.me/' . ltrim(phone_href($number), '+') . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/* ---------------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------------- */
function money($amount): string
{
    $amount = (float) $amount;
    return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2);
}

/** Compact money for stat tiles: $1,284 / $12.9K / $4.2M */
function money_compact($amount): string
{
    $a = (float) $amount;
    if (abs($a) >= 1000000) return '$' . rtrim(rtrim(number_format($a / 1000000, 1), '0'), '.') . 'M';
    if (abs($a) >= 10000)   return '$' . rtrim(rtrim(number_format($a / 1000, 1), '0'), '.') . 'K';
    return '$' . number_format($a, 0);
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

function time_ago(?string $date): string
{
    if (!$date) return 'never';
    $diff = time() - strtotime($date);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return format_date($date);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'item';
}

/** Slug that is unique in $table.slug (optionally ignoring row $ignoreId). */
function unique_slug(string $table, string $source, ?int $ignoreId = null): string
{
    $base = slugify($source);
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = "SELECT COUNT(*) FROM `$table` WHERE slug = ?" . ($ignoreId ? ' AND id <> ?' : '');
        $params = $ignoreId ? [$slug, $ignoreId] : [$slug];
        if ((int) fetch_value($sql, $params) === 0) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function status_badge(?string $status): string
{
    $status = (string) $status;
    $map = [
        'success' => ['active', 'approved', 'paid', 'completed', 'delivered'],
        'warning' => ['pending', 'processing', 'unpaid'],
        'danger'  => ['rejected', 'suspended', 'failed', 'cancelled'],
        'info'    => ['shipped', 'refunded'],
    ];
    $tone = 'neutral';
    foreach ($map as $t => $list) {
        if (in_array($status, $list, true)) { $tone = $t; break; }
    }
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) $out .= strtoupper(substr($p, 0, 1));
    return $out ?: '?';
}

/* ---------------------------------------------------------------------------
 * Pagination
 * ------------------------------------------------------------------------- */
function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    return [
        'total'   => $total,
        'perPage' => $perPage,
        'page'    => $page,
        'pages'   => $pages,
        'offset'  => ($page - 1) * $perPage,
    ];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<div class="pagination"><span class="muted">' . number_format($p['total']) . ' result' . ($p['total'] == 1 ? '' : 's') . '</span></div>';
    }
    $from = $p['offset'] + 1;
    $to   = min($p['total'], $p['offset'] + $p['perPage']);
    $html = '<div class="pagination"><span class="muted">' . $from . '–' . $to . ' of ' . number_format($p['total']) . '</span><nav>';
    $html .= $p['page'] > 1
        ? '<a href="' . e(query_with(['page' => $p['page'] - 1])) . '">&lsaquo; Prev</a>'
        : '<span class="disabled">&lsaquo; Prev</span>';
    $start = max(1, $p['page'] - 2);
    $end   = min($p['pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $p['page']
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . e(query_with(['page' => $i])) . '">' . $i . '</a>';
    }
    $html .= $p['page'] < $p['pages']
        ? '<a href="' . e(query_with(['page' => $p['page'] + 1])) . '">Next &rsaquo;</a>'
        : '<span class="disabled">Next &rsaquo;</span>';
    return $html . '</nav></div>';
}

/* ---------------------------------------------------------------------------
 * Uploads
 * ------------------------------------------------------------------------- */
/**
 * Validates and stores an uploaded image. Returns the relative path
 * (e.g. "uploads/products/ab12cd.jpg"), or null if no file was sent.
 * Throws RuntimeException with a user-friendly message on invalid input.
 */
function upload_image(?array $file, string $subdir): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed (code ' . $file['error'] . ').');
    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) throw new RuntimeException('Images must be ' . MAX_UPLOAD_MB . 'MB or smaller.');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    }

    $subdir = trim(preg_replace('/[^a-z0-9_-]/i', '', $subdir), '/');
    $dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Upload folder is not writable.');

    $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    return 'uploads/' . $subdir . '/' . $name;
}

/** Normalises a multi-file input (name="x[]") into a list of single-file arrays. */
function files_array(string $field): array
{
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return [];
    $out = [];
    foreach ($_FILES[$field]['name'] as $i => $n) {
        $out[] = [
            'name'     => $n,
            'type'     => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => $_FILES[$field]['error'][$i],
            'size'     => $_FILES[$field]['size'][$i],
        ];
    }
    return $out;
}

function delete_upload(?string $relPath): void
{
    if (!$relPath || strpos($relPath, 'uploads/') !== 0 || strpos($relPath, '..') !== false) return;
    $full = ROOT_PATH . '/' . $relPath;
    if (is_file($full)) @unlink($full);
}

function image_url(?string $relPath): string
{
    return $relPath ? url($relPath) : asset('img/placeholder.svg');
}

/* ---------------------------------------------------------------------------
 * Mail
 * ------------------------------------------------------------------------- */
function send_mail(string $to, string $subject, string $body): bool
{
    $headers = 'From: ' . setting('store_name', APP_NAME) . ' <' . MAIL_FROM . ">\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

/* ---------------------------------------------------------------------------
 * Activity log
 * ------------------------------------------------------------------------- */
function log_activity(string $action, string $details = ''): void
{
    try {
        q('INSERT INTO activity_log (admin_id, action, details, ip) VALUES (?, ?, ?, ?)',
          [$_SESSION['admin_id'] ?? null, $action, mb_substr($details, 0, 500), client_ip()]);
    } catch (Throwable $e) {
        // never block a request because logging failed
    }
}

/* ---------------------------------------------------------------------------
 * Reference data
 * ------------------------------------------------------------------------- */
function african_countries(): array
{
    return ['Algeria','Angola','Benin','Botswana','Burkina Faso','Burundi','Cabo Verde','Cameroon',
        'Central African Republic','Chad','Comoros','Congo','DR Congo',"Côte d'Ivoire",'Djibouti','Egypt',
        'Equatorial Guinea','Eritrea','Eswatini','Ethiopia','Gabon','Gambia','Ghana','Guinea','Guinea-Bissau',
        'Kenya','Lesotho','Liberia','Libya','Madagascar','Malawi','Mali','Mauritania','Mauritius','Morocco',
        'Mozambique','Namibia','Niger','Nigeria','Rwanda','São Tomé and Príncipe','Senegal','Seychelles',
        'Sierra Leone','Somalia','South Africa','South Sudan','Sudan','Tanzania','Togo','Tunisia','Uganda',
        'Zambia','Zimbabwe'];
}

/** Other countries a product may originate from (e.g. made in the diaspora). */
function other_countries(): array
{
    return ['United States', 'Canada', 'United Kingdom', 'Ireland', 'France', 'Germany', 'Netherlands', 'Belgium',
        'Italy', 'Spain', 'Portugal', 'Switzerland', 'Sweden', 'Norway', 'Denmark', 'Brazil', 'Mexico', 'Colombia',
        'Jamaica', 'Haiti', 'Trinidad and Tobago', 'Barbados', 'Dominican Republic', 'India', 'Pakistan',
        'Bangladesh', 'China', 'Japan', 'South Korea', 'Vietnam', 'Thailand', 'Philippines', 'Indonesia',
        'Turkey', 'United Arab Emirates', 'Saudi Arabia', 'Australia', 'New Zealand'];
}

function all_countries(): array
{
    return array_merge(african_countries(), other_countries());
}

/** <option> list grouped as Africa / Rest of the world. */
function country_options(?string $selected): string
{
    $html = '';
    foreach (['Africa' => african_countries(), 'Rest of the world' => other_countries()] as $group => $list) {
        $html .= '<optgroup label="' . e($group) . '">';
        foreach ($list as $c) {
            $html .= '<option' . ($selected === $c ? ' selected' : '') . '>' . e($c) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function us_states(): array
{
    return ['AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado',
        'CT'=>'Connecticut','DE'=>'Delaware','DC'=>'District of Columbia','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii',
        'ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa','KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana',
        'ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan','MN'=>'Minnesota','MS'=>'Mississippi',
        'MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire','NJ'=>'New Jersey',
        'NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma',
        'OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota',
        'TN'=>'Tennessee','TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington',
        'WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming','PR'=>'Puerto Rico','GU'=>'Guam','VI'=>'U.S. Virgin Islands'];
}


/** Stock level at or below which a product counts as "low stock" (Settings → Commerce). */
function low_stock(): int
{
    return max(0, (int) setting('low_stock_threshold', 5));
}
