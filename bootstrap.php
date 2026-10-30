<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/config.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('beanson_pos_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Baseline security response headers. Set here (in addition to .htaccess) so
// they apply even on hosts where mod_headers is unavailable.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function logged_in(): bool
{
    return isset($_SESSION['user']['id'], $_SESSION['user']['role']);
}

function current_user(): array
{
    return $_SESSION['user'] ?? [];
}

function require_login(): void
{
    if (!logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();

    if ((current_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied. Administrator access is required.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function audit(?int $userId, string $action, ?string $details = null): void
{
    $statement = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)'
    );

    $statement->execute([$userId, $action, $details]);
}

function peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function user_permissions(): array
{
    $permissions = current_user()['permissions'] ?? '[]';

    if (is_array($permissions)) {
        return $permissions;
    }

    $decoded = json_decode((string) $permissions, true);

    return is_array($decoded) ? $decoded : [];
}

function has_permission(string $permission): bool
{
    // Administrators always have complete access.
    if ((current_user()['role'] ?? '') === 'admin') {
        return true;
    }

    return in_array($permission, user_permissions(), true);
}

function require_permission(string $permission): void
{
    require_login();

    if (!has_permission($permission)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to use this feature.');
    }
}