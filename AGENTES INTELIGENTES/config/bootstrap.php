<?php

declare(strict_types=1);

// Inicializa rutas, entorno, zona horaria, logging y sesion para las entradas PHP.
const PROJECT_ROOT = __DIR__ . '/..';

require_once PROJECT_ROOT . '/app/Support/AppLogger.php';

$env = strtolower((string) getenv('APP_ENV'));
if ($env === '') {
    $env = 'production';
    putenv('APP_ENV=production');
}

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    header('Referrer-Policy: strict-origin-when-cross-origin', true);
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()', true);
    header('X-Permitted-Cross-Domain-Policies: none', true);
}

if ($env === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    putenv('APP_LOG_LEVEL=DEBUG');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED & ~E_USER_NOTICE);
    putenv('APP_LOG_LEVEL=' . (strtoupper((string) getenv('APP_LOG_LEVEL')) !== '' ? strtoupper((string) getenv('APP_LOG_LEVEL')) : 'INFO'));
}

if (is_file(PROJECT_ROOT . '/.env')) {
    $lines = file(PROJECT_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Guayaquil');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_lifetime', '14400');
    ini_set('session.gc_maxlifetime', '14400');
    ini_set('session.name', 'TESA_SESSION');
    session_start();
}
