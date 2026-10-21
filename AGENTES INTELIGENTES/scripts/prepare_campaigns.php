<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/app/Services/InactivityService.php';
require_once PROJECT_ROOT . '/app/Services/ReminderCampaignService.php';

// Prepara borradores para cada umbral; este script no envia correos.
try {
    $database = Database::connection();
    $campaigns = new ReminderCampaignService($database, new InactivityService($database));

    foreach ([8, 15, 30] as $days) {
        $recipients = $campaigns->prepare($days);
        echo 'CAMPAIGN_REVIEW days=' . $days . ' recipients=' . $recipients . PHP_EOL;
    }

    exit(0);
} catch (Throwable $exception) {
    error_log('prepare_campaigns.php failed: ' . $exception->getMessage());
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}