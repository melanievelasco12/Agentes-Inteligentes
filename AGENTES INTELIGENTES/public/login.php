<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/auth.php';

// Si la sesión ya está autenticada, envía al usuario directamente al panel.
if (is_authenticated()) {
    header('Location: /');
    exit;
}

$csrfToken = $_SESSION['login_csrf_token'] ??= bin2hex(random_bytes(32));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Comprueba CSRF antes de validar el usuario y la contrasena.
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
    } elseif (admin_login($username, $password)) {
        unset($_SESSION['login_csrf_token']);
        header('Location: /');
        exit;
    } else {
        $error = 'Las credenciales no son válidas.';
    }
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso | Agentes Inteligentes TESA</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #241b2e; background-color: #35144f; }
        * { box-sizing: border-box; }
        body {
            display: grid;
            min-height: 100vh;
            place-items: center;
            margin: 0;
            padding: 24px;
            background-color: #35144f;
            background-image:
                url("assets/login-ai-background.png"),
                radial-gradient(ellipse at 50% 48%, rgba(164, 123, 226, 0.42), transparent 59%),
                radial-gradient(circle at 12% 12%, rgba(211, 182, 255, 0.25), transparent 32%),
                radial-gradient(circle at 88% 88%, rgba(109, 75, 180, 0.35), transparent 36%),
                linear-gradient(135deg, #32134c 0%, #4b2a69 48%, #362052 100%);
            background-position: center;
            background-size: cover;
            background-repeat: no-repeat;
        }
        @media (max-width: 600px) {
            body {
                background-position: left center, center, center, center, center;
                background-size: auto 100vh, cover, cover, cover, cover;
            }
        }
        main { width: min(100%, 420px); padding: 32px; background: #fff; border: 1px solid rgba(75, 42, 105, .12); border-top: 5px solid #4b2a69; border-radius: 12px; box-shadow: 0 12px 30px rgba(39, 20, 58, .18); }
        .login-logo { display: block; width: min(96px, 28vw); height: auto; margin: 0 auto 16px; }
        h1 { margin: 0 0 8px; color: #4b2a69; font-size: 1.7rem; }
        p { color: #6d6480; line-height: 1.5; }
        label { display: block; margin: 18px 0 6px; color: #4b2a69; font-size: .8rem; font-weight: 700; }
        input { width: 100%; padding: 12px; border: 1px solid #d9d0e3; font: inherit; }
        input:focus-visible { border-color: #6d4bb4; outline: 2px solid #6d4bb4; outline-offset: 1px; }
        button { width: 100%; margin-top: 24px; padding: 13px; border: 0; background: #6d4bb4; color: #fff; cursor: pointer; font: inherit; font-weight: 700; transition: background-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        button:hover { background: #5f3fa3; box-shadow: 0 4px 10px rgba(75, 42, 105, .2); }
        button:active { background: #52358e; box-shadow: 0 1px 3px rgba(75, 42, 105, .2); transform: translateY(1px); }
        button:focus-visible { outline: 3px solid #4b2a69; outline-offset: 3px; }
        .error { padding: 12px; border-left: 4px solid #9c2f45; background: #f8e7e7; color: #7d2337; }
        .login-footer { margin: 16px 0 0; color: #6d6480; font-size: .75rem; text-align: center; }
    </style>
</head>
<body>
    <main>
        <img class="login-logo" src="/assets/logo-tesa.png" alt="Logo TESA">
        <h1>Agentes Inteligentes TESA</h1>
        <p>Ingresa para controlar agentes inteligentes de Brightspace.</p>
        <?php if ($error !== null): ?>
            <p class="error"><?= $escape($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <label for="username">Usuario</label>
            <input id="username" name="username" type="text" autocomplete="username" required>
            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">Iniciar sesión</button>
        </form>
        <p class="login-footer">© 2026 TESA - Instituto Superior Tecnológico San Antonio</p>
    </main>
</body>
</html>
