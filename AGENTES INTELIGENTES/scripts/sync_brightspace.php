<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';
require_once PROJECT_ROOT . '/integrations/brightspace/BrightspaceClient.php';
require_once PROJECT_ROOT . '/app/Services/BrightspaceSyncService.php';

// Sincroniza matrículas y devuelve un codigo de salida para la consola o tareas programadas.
try {
    $database = Database::connection();
    $service = new BrightspaceSyncService(
        new BrightspaceClient(
            new OAuthClient(),
            new OAuthTokenStore($database, (string) getenv('APP_ENCRYPTION_KEY')),
            (string) getenv('BRIGHTSPACE_BASE_URL'),
            (string) getenv('BRIGHTSPACE_API_VERSION')
        ),
        $database
    );

    $records = $service->synchronize();
    echo 'SYNC_OK records=' . $records . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    error_log('sync_brightspace.php failed: ' . $exception->getMessage());
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}