<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/auth.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';

require_admin(true);

// El estado de un solo uso protege el retorno OAuth y evita repetir una respuesta previa.
$state = (string) ($_GET['state'] ?? '');
$expectedState = (string) ($_SESSION['oauth_state'] ?? '');
unset($_SESSION['oauth_state']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    AppLogger::warning('OAuth callback state mismatch or expired.', ['provided_state' => $state, 'expected_state' => $expectedState]);
    http_response_code(400);
    exit('La sesión de autorización expiró o se inició desde otro dominio. Reinicia la conexión desde el dashboard.');
}

if (isset($_GET['error'])) {
    AppLogger::warning('Brightspace OAuth authorization denied.', ['error' => $_GET['error'] ?? null, 'error_description' => $_GET['error_description'] ?? null]);
    http_response_code(400);
    exit('Brightspace no autorizó la aplicación.');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    AppLogger::warning('OAuth callback missing authorization code.');
    http_response_code(400);
    exit('No se recibió el código de autorización.');
}

try {
    // Intercambia el codigo por tokens y los guarda cifrados en la base local.
    $oauth = new OAuthClient();
    $tokens = $oauth->tokenFromCode($code);
    $store = new OAuthTokenStore(Database::connection(), (string) getenv('APP_ENCRYPTION_KEY'));
    $store->save($tokens);
    AppLogger::info('OAuth token exchange successful.', ['has_refresh' => isset($tokens['refresh_token'])]);
} catch (Throwable $exception) {
    AppLogger::exception($exception, ['context' => 'oauth_callback_token_exchange']);
    throw $exception;
}

header('Location: /?oauth=success');
exit;
