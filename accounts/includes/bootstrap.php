<?php
/**
 * Loaded first by every accounts page. Sets up the DB connection, a
 * hardened session, CSRF protection, auth helpers and shared utilities.
 */
declare(strict_types=1);

define('ACC_LOADED', 1);
define('ACC_ROOT', dirname(__DIR__));
define('ACC_SESSION_IDLE_SECONDS', 60 * 60);   // sign out after 1h of inactivity
define('ACC_MAX_LOGIN_ATTEMPTS', 5);           // per email or IP …
define('ACC_LOGIN_WINDOW_MINUTES', 15);        // … within this window

require_once ACC_ROOT . '/../lib/Db.php';

date_default_timezone_set('Asia/Kathmandu');

// ── Session ──────────────────────────────────────────────────────────
// Own cookie name so it never shares state with the CMS or site portal.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('JEIWS_ACC');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => acc_base_path() . '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// ── Error capture ────────────────────────────────────────────────────
// Any uncaught error or fatal (out of memory, a missing PHP extension …) is
// written to data/log/accounts-errors.log with a reference code, and the user
// sees a short page quoting that code instead of a bare "Internal Server
// Error". Admins can read the log under Settings → System check.
define('ACC_ERROR_LOG', ACC_ROOT . '/../data/log/accounts-errors.log');

function acc_log_error(string $type, string $message, string $file, int $line): string {
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $entry = json_encode([
        'ref' => $ref, 'time' => date('Y-m-d H:i:s'), 'type' => $type, 'message' => substr($message, 0, 1000),
        'where' => strtr($file, [DIRECTORY_SEPARATOR => '/']) . ':' . $line, 'url' => $_SERVER['REQUEST_URI'] ?? '', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'user' => $_SESSION['acc_user_id'] ?? null, 'php' => PHP_VERSION,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $dir = dirname(ACC_ERROR_LOG);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(ACC_ERROR_LOG, $entry . "\n", FILE_APPEND | LOCK_EX);
    error_log("[accounts {$ref}] {$type}: {$message} at {$file}:{$line}");
    return $ref;
}

function acc_error_page(string $ref): void {
    if (headers_sent()) { echo "<p>Something went wrong (reference {$ref}).</p>"; return; }
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 20px;line-height:1.5">'
        . '<h1 style="font-size:1.3rem">Something went wrong</h1>'
        . '<p>The server hit an error and your last action may not have been saved. Please check before trying again.</p>'
        . '<p>Reference: <strong style="font-family:monospace;font-size:1.1rem">' . $ref . '</strong></p>'
        . '<p>An admin can see the details under <em>Settings → System check</em>.</p>'
        . '<p><a href="javascript:history.back()">← Go back</a></p></div>';
}

set_exception_handler(function (Throwable $ex): void {
    acc_error_page(acc_log_error(get_class($ex), $ex->getMessage(), $ex->getFile(), $ex->getLine()));
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        @ini_set('memory_limit', '-1');   // the fatal may have been "out of memory" — give the logger room
        acc_error_page(acc_log_error('Fatal error', $e['message'], $e['file'], $e['line']));
    }
});

// Security headers for every accounts response.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// A POST larger than post_max_size arrives with $_POST and $_FILES emptied by
// PHP — without this check it would surface as a misleading "session expired"
// CSRF failure. Say what actually happened.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $limit = ini_get('post_max_size') ?: '8M';
    http_response_code(413);
    exit('<!doctype html><meta charset="utf-8"><title>Upload too large</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 20px;line-height:1.5">'
        . '<h1 style="font-size:1.3rem">The files were too large to upload</h1>'
        . '<p>Everything sent at once came to ' . round((int)$_SERVER['CONTENT_LENGTH'] / 1048576, 1) . ' MB, but the server accepts at most ' . htmlspecialchars($limit) . ' per save, so nothing was saved.</p>'
        . '<p>Go back and attach fewer files at a time, or use a smaller PDF (photos are shrunk automatically). You can add more files to the invoice after saving it.</p>'
        . '<p><a href="javascript:history.back()">← Go back</a></p></div>');
}

// Reject input that isn't valid UTF-8. MySQL would otherwise silently cut the
// text at the first bad byte (e.g. "Nabil – Current" saved as "Nabil ").
(function () {
    // preg's /u flag fails on invalid UTF-8 — works even where mbstring isn't installed.
    $isUtf8 = fn(string $s): bool => function_exists('mb_check_encoding') ? mb_check_encoding($s, 'UTF-8') : preg_match('//u', $s) === 1;
    $valid = function (array $data) use (&$valid, $isUtf8): bool {
        foreach ($data as $k => $v) {
            if (!$isUtf8((string)$k)) return false;
            if (is_array($v) ? !$valid($v) : !$isUtf8((string)$v)) return false;
        }
        return true;
    };
    if (!$valid($_GET) || !$valid($_POST)) {
        http_response_code(400);
        exit('The form contained characters that could not be read (invalid text encoding). Please retype the text and try again.');
    }
})();

// ── Paths ────────────────────────────────────────────────────────────
/** URL path of the accounts folder, e.g. "/accounts" live or "/jeiws2025/accounts" locally. */
function acc_base_path(): string {
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $pos = strpos($script, '/accounts/');
        $base = $pos === false ? '/accounts' : substr($script, 0, $pos) . '/accounts';
    }
    return $base;
}

function acc_url(string $path = ''): string {
    return acc_base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path): never {
    header('Location: ' . (str_starts_with($path, '/') || str_starts_with($path, 'http') ? $path : acc_url($path)));
    exit;
}

// ── Output helpers ───────────────────────────────────────────────────
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formats an amount in Nepali/Indian digit grouping: 12,34,567.00 */
function money(float|int|string|null $amount, bool $withSymbol = true): string {
    $amount = (float)$amount;
    $negative = $amount < 0;
    [$int, $dec] = explode('.', number_format(abs($amount), 2, '.', ''));
    $last3 = substr($int, -3);
    $rest  = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    $formatted = ($negative ? '-' : '') . $int . '.' . $dec;
    return $withSymbol ? setting('currency_symbol', 'Rs.') . ' ' . $formatted : $formatted;
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    return match (true) {
        $diff < 60     => 'just now',
        $diff < 3600   => floor($diff / 60) . ' min ago',
        $diff < 86400  => floor($diff / 3600) . ' h ago',
        $diff < 604800 => floor($diff / 86400) . ' d ago',
        default        => date('d M Y', strtotime($datetime)),
    };
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── Flash messages ───────────────────────────────────────────────────
function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array {
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ── CSRF ─────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $given = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($given) || !hash_equals(csrf_token(), $given)) {
        http_response_code(403);
        exit('Your session expired or the form was tampered with. Go back, reload the page and try again.');
    }
}

// ── Settings ─────────────────────────────────────────────────────────
function settings(bool $refresh = false): array {
    static $cache = null;
    if ($cache === null || $refresh) {
        try {
            $cache = db()->query('SELECT setting_key, setting_value FROM acc_settings')
                         ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            $cache = []; // schema not installed yet — pages fall back to defaults
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string {
    $value = settings()[$key] ?? null;
    return ($value === null || $value === '') ? $default : (string)$value;
}

// ── Auth ─────────────────────────────────────────────────────────────
function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;

    $user = null;
    $id = $_SESSION['acc_user_id'] ?? null;
    if (!$id) return null;

    if (time() - ($_SESSION['acc_last_seen'] ?? 0) > ACC_SESSION_IDLE_SECONDS) {
        logout_user();
        flash('warning', 'You were signed out after a period of inactivity.');
        return null;
    }

    $stmt = db()->prepare('SELECT id, full_name, email, role FROM acc_users WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        $_SESSION['acc_last_seen'] = time();
    } else {
        logout_user(); // deactivated or deleted since signing in
    }
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        $_SESSION['acc_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
    return $user;
}

/** @param string|string[] $roles */
function require_role(string|array $roles): array {
    $user = require_login();
    if (!in_array($user['role'], (array)$roles, true)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
    return $user;
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['acc_user_id']   = (int)$user['id'];
    $_SESSION['acc_last_seen'] = time();
    unset($_SESSION['csrf_token']);
    db()->prepare('UPDATE acc_users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logout_user(): void {
    unset($_SESSION['acc_user_id'], $_SESSION['acc_last_seen'], $_SESSION['csrf_token']);
    session_regenerate_id(true);
}

function audit(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void {
    $userId = $_SESSION['acc_user_id'] ?? null;
    db()->prepare('INSERT INTO acc_audit_log (user_id, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $action, $entity, $entityId, $details, client_ip()]);
}

// ── Login throttling ─────────────────────────────────────────────────
function too_many_login_attempts(string $email): bool {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM acc_login_attempts
         WHERE succeeded = 0 AND attempted_at > (NOW() - INTERVAL ? MINUTE)
           AND (email = ? OR ip_address = ?)'
    );
    $stmt->execute([ACC_LOGIN_WINDOW_MINUTES, $email, client_ip()]);
    return (int)$stmt->fetchColumn() >= ACC_MAX_LOGIN_ATTEMPTS;
}

function record_login_attempt(string $email, bool $succeeded): void {
    db()->prepare('INSERT INTO acc_login_attempts (email, ip_address, succeeded) VALUES (?,?,?)')
        ->execute([$email, client_ip(), $succeeded ? 1 : 0]);
}

// ── Schema ───────────────────────────────────────────────────────────
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/master-data.php';
require_once __DIR__ . '/payroll.php';
require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/cashbank.php';
require_once __DIR__ . '/taxes.php';

try {
    acc_migrate();
} catch (Throwable $ex) {
    error_log('[accounts] migration failed: ' . $ex->getMessage());
    http_response_code(503);
    exit('<!doctype html><meta charset="utf-8"><title>Database update needed</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 20px;line-height:1.5">'
        . '<h1 style="font-size:1.4rem">The accounts database needs updating</h1>'
        . '<p>The automatic update could not run (usually because the database user is not allowed to create tables).</p>'
        . '<p>Open phpMyAdmin, select the site database, choose <b>Import</b> and upload <code>accounts/db/schema.sql</code>, then reload this page.</p>'
        . '</div>');
}
