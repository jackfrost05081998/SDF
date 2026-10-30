<?php
declare(strict_types=1);

/*
 * Database credentials are NOT stored in this file.
 *
 * They are loaded from config.local.php (preferred, blocked from web access
 * in .htaccess) or, failing that, from environment variables. This keeps the
 * live password out of the main source file / any backups or version control.
 *
 * Local dev (e.g. XAMPP) can create its own config.local.php or set the
 * DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables.
 */
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : '');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}
