<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AdminUsernameCipher.php';
require_once __DIR__ . '/database.php';

// Limites de sesion y defensa contra intentos reiterados de autenticacion.
const ADMIN_SESSION_TTL = 1800;
const ADMIN_MAX_LOGIN_ATTEMPTS = 5;
const ADMIN_LOGIN_COOLDOWN_SECONDS = 300;

function is_admin_authenticated(): bool
{
    return is_authenticated() && ($_SESSION['admin_role'] ?? '') === 'admin';
}

function is_authenticated(): bool
{
    return ($_SESSION['authenticated'] ?? false) === true;
}

function touch_admin_session(): void
{
    if (is_authenticated()) {
        $_SESSION['last_activity'] = time();
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            $cookieOptions = [
                'expires' => time() + max(1, (int) $cookie['lifetime']),
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => (bool) $cookie['secure'],
                'httponly' => (bool) $cookie['httponly'],
                'samesite' => $cookie['samesite'] !== '' ? $cookie['samesite'] : 'Lax',
            ];
            setcookie(session_name(), session_id(), $cookieOptions);
        }
    }
}

function admin_login_attempts_state(): array
{
    $attempts = (int) ($_SESSION['admin_login_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['admin_login_locked_until'] ?? 0);

    return ['attempts' => $attempts, 'locked_until' => $lockedUntil];
}

function require_authenticated(bool $jsonResponse = false): void
{
    if (!is_authenticated()) {
        if ($jsonResponse) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => false,
                'login_required' => true,
                'error' => 'La sesión expiró. Inicia sesión para reanudar la actualización pendiente.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: /login.php');
        exit;
    }

    $lastActivity = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : 0;
    if ($lastActivity > 0 && (time() - $lastActivity) > ADMIN_SESSION_TTL) {
        if ($jsonResponse) {
            unset(
                $_SESSION['authenticated'],
                $_SESSION['admin_username'],
                $_SESSION['admin_user_id'],
                $_SESSION['admin_role'],
                $_SESSION['last_activity'],
                $_SESSION['csrf_token']
            );
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => false,
                'login_required' => true,
                'error' => 'La sesión expiró. Inicia sesión para reanudar la actualización pendiente.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        admin_logout();
        header('Location: /login.php?expired=1');
        exit;
    }

    $statement = Database::connection()->prepare(
        'SELECT username, role, is_active FROM app_users WHERE id = :id'
    );
    $statement->execute(['id' => (int) ($_SESSION['admin_user_id'] ?? 0)]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user) || (int) $user['is_active'] !== 1) {
        admin_logout();
        if ($jsonResponse) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'success' => false,
                'login_required' => true,
                'error' => 'La cuenta está desactivada o ya no existe. Inicia sesión con una cuenta activa.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: /login.php');
        exit;
    }

    $_SESSION['admin_username'] = (string) $user['username'];
    $_SESSION['admin_role'] = (string) $user['role'];
    touch_admin_session();
}

function require_admin(bool $jsonResponse = false): void
{
    require_authenticated($jsonResponse);
    if (is_admin_authenticated()) {
        return;
    }

    http_response_code(403);
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Se requieren permisos de administrador para realizar esta acción.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo 'Acceso denegado. Se requieren permisos de administrador.';
    exit;
}

function admin_login(string $username, string $password, ?PDO $database = null): bool
{
    $normalizedUsername = trim($username);
    if ($normalizedUsername === '' || $password === '') {
        AppLogger::warning('Intento de login con credenciales vacías.');
        return false;
    }

    $state = admin_login_attempts_state();
    $now = time();
    if ($state['locked_until'] > $now) {
        AppLogger::warning('Bloqueo activo de login.', ['locked_until' => $state['locked_until']]);
        return false;
    }

    $database ??= Database::connection();
    $statement = $database->prepare(
        'SELECT id, username, password_hash, role, is_active FROM app_users WHERE username = :username LIMIT 1'
    );
    $statement->execute(['username' => $normalizedUsername]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    $valid = is_array($user)
        && (int) $user['is_active'] === 1
        && password_verify($password, (string) $user['password_hash']);

    if (!$valid) {
        $state['attempts'] = $state['attempts'] + 1;
        $_SESSION['admin_login_attempts'] = $state['attempts'];

        if ($state['attempts'] >= ADMIN_MAX_LOGIN_ATTEMPTS) {
            $_SESSION['admin_login_locked_until'] = $now + ADMIN_LOGIN_COOLDOWN_SECONDS;
            $_SESSION['admin_login_attempts'] = 0;
            AppLogger::warning('Login bloqueado por demasiados intentos fallidos.', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        } else {
            AppLogger::warning('Intento fallido de login.', ['attempts' => $state['attempts']]);
        }

        return false;
    }

    $_SESSION['admin_login_attempts'] = 0;
    unset($_SESSION['admin_login_locked_until']);

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['admin_user_id'] = (int) $user['id'];
    $_SESSION['admin_username'] = (string) $user['username'];
    $_SESSION['admin_role'] = (string) $user['role'];
    $_SESSION['last_activity'] = time();

    AppLogger::info('Inicio de sesión exitoso.', [
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    return true;
}

function admin_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], (bool) $parameters['secure'], (bool) $parameters['httponly']);
    }

    session_destroy();
}
