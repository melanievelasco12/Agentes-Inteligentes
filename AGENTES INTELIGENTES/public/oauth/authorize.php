<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/auth.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';

require_admin();

// Si Postman es el callback configurado, la autorizacion se importa por consola.
$callbackUri = parse_url((string) getenv('BRIGHTSPACE_REDIRECT_URI'));
$callbackHost = strtolower((string) ($callbackUri['host'] ?? ''));

if ($callbackHost === 'oauth.pstmn.io') {
    http_response_code(409);
    exit('Este proyecto usa un token de Postman importado por consola. Ejecuta scripts/import_postman_token.php desde la carpeta del proyecto.');
}

$callbackScheme = strtolower((string) ($callbackUri['scheme'] ?? ''));
$callbackPort = isset($callbackUri['port']) ? ':' . $callbackUri['port'] : '';
$expectedAuthority = $callbackHost . $callbackPort;
$requestAuthority = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$forwardedScheme = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$requestScheme = $forwardedScheme !== ''
	? $forwardedScheme
	: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

if ($callbackHost === '' || !in_array($callbackScheme, ['http', 'https'], true)) {
    AppLogger::error('OAuth callback URL is misconfigured.', ['callback_url' => getenv('BRIGHTSPACE_REDIRECT_URI')]);
    http_response_code(500);
    exit('La URL de retorno OAuth de Brightspace no está configurada correctamente.');
}

if ($requestAuthority !== $expectedAuthority || $requestScheme !== $callbackScheme) {
    $callbackPath = (string) ($callbackUri['path'] ?? '/oauth/callback.php');
    $authorizationPath = rtrim(dirname($callbackPath), '/') . '/authorize.php';
    AppLogger::warning('OAuth redirect authority mismatch detected.', ['expected_authority' => $expectedAuthority, 'request_authority' => $requestAuthority, 'expected_scheme' => $callbackScheme, 'request_scheme' => $requestScheme]);
    header('Location: ' . $callbackScheme . '://' . $expectedAuthority . $authorizationPath, true, 302);
    exit;
}

$state = bin2hex(random_bytes(32));
// Vincula la respuesta OAuth con esta sesion y mitiga falsificacion de solicitudes.
$_SESSION['oauth_state'] = $state;

$oauth = new OAuthClient();
AppLogger::info('OAuth authorization initiated.', ['state' => $state, 'redirect_uri' => $oauth->authorizationUrl($state)]);
header('Location: ' . $oauth->authorizationUrl($state));
exit;
