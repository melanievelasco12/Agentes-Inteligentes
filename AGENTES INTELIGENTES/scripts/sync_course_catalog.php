<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';
require_once PROJECT_ROOT . '/integrations/brightspace/BrightspaceClient.php';
require_once PROJECT_ROOT . '/app/Services/BrightspaceSyncService.php';

// Actualiza solo cursos y periodos; la sincronizacion de matriculas tiene otro comando.
$database = Database::connection();
$client = new BrightspaceClient(
    new OAuthClient(),
    new OAuthTokenStore($database, (string) getenv('APP_ENCRYPTION_KEY')),
    (string) getenv('BRIGHTSPACE_BASE_URL'),
    (string) getenv('BRIGHTSPACE_API_VERSION'),
    (string) getenv('BRIGHTSPACE_LP_API_VERSION')
);

$service = new BrightspaceSyncService($client, $database);

echo 'COURSE_CATALOG_SYNC_OK courses=' . $service->synchronizeCourseCatalog() . PHP_EOL;
