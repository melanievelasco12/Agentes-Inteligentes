<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/app/Services/InactivityService.php';

// Consulta candidatos locales por los umbrales usados por los agentes institucionales.
$service = new InactivityService(Database::connection());

foreach ([8, 15, 30] as $days) {
    $candidates = $service->candidatesForDays($days);
    echo 'INACTIVITY days=' . $days . ' candidates=' . count($candidates) . PHP_EOL;
}