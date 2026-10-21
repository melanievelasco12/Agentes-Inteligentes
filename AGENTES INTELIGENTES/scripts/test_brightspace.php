<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';
require_once PROJECT_ROOT . '/integrations/brightspace/BrightspaceClient.php';

// Comprueba autenticacion y conectividad con una lectura inocua de matriculas propias.
$client = new BrightspaceClient(
    new OAuthClient(),
    new OAuthTokenStore(Database::connection(), (string) getenv('APP_ENCRYPTION_KEY')),
    (string) getenv('BRIGHTSPACE_BASE_URL'),
    (string) getenv('BRIGHTSPACE_API_VERSION')
);

$data = $client->get('lp', '/enrollments/myenrollments/');
$items = isset($data['Items']) && is_array($data['Items']) ? $data['Items'] : [];
echo 'BRIGHTSPACE_API_OK items=' . count($items) . PHP_EOL;
