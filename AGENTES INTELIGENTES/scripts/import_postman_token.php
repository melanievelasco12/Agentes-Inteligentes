<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';

// Importa una vez el refresh token de Postman y lo guarda cifrado para el panel.
$refreshToken = trim((string) getenv('POSTMAN_REFRESH_TOKEN'));

if ($refreshToken === '') {
    fwrite(STDERR, "POSTMAN_REFRESH_TOKEN no está configurado en .env.\n");
    exit(1);
}

if (preg_match('/\s/', $refreshToken) === 1) {
    fwrite(STDERR, "POSTMAN_REFRESH_TOKEN contiene espacios o saltos de línea; revisa el valor en .env.\n");
    exit(1);
}

try {
    $tokens = (new OAuthClient())->refresh($refreshToken);
    if (trim((string) ($tokens['refresh_token'] ?? '')) === '') {
        $tokens['refresh_token'] = $refreshToken;
    }

    (new OAuthTokenStore(
        Database::connection(),
        (string) getenv('APP_ENCRYPTION_KEY')
    ))->save($tokens);

    fwrite(STDOUT, "Brightspace quedó conectado. Los tokens se guardaron cifrados en la base de datos.\n");
    fwrite(STDOUT, "Borra ahora el valor de POSTMAN_REFRESH_TOKEN en .env; la aplicación renovará el token desde la base de datos.\n");
} catch (Throwable $exception) {
    AppLogger::exception($exception, ['operation' => 'postman_refresh_token_cli_import']);
    fwrite(STDERR, "No se pudo importar el refresh token. Comprueba que corresponda al Client ID y Client Secret configurados.\n");
    exit(1);
}
