<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';
require_once PROJECT_ROOT . '/integrations/brightspace/BrightspaceClient.php';

// Herramienta CLI de diagnostico para inspeccionar respuestas de matriculas de Brightspace.
$client = new BrightspaceClient(
    new OAuthClient(),
    new OAuthTokenStore(Database::connection(), (string) getenv('APP_ENCRYPTION_KEY')),
    (string) getenv('BRIGHTSPACE_BASE_URL'),
    (string) getenv('BRIGHTSPACE_API_VERSION')
);

$enrollments = $client->get('lp', '/enrollments/myenrollments/');
echo 'enrollments.top.keys=' . implode(',', array_keys($enrollments)) . PHP_EOL;
$pagingInfo = is_array($enrollments['PagingInfo'] ?? null) ? $enrollments['PagingInfo'] : [];
echo 'enrollments.paging.keys=' . implode(',', array_keys($pagingInfo)) . PHP_EOL;
$items = isset($enrollments['Items']) && is_array($enrollments['Items']) ? $enrollments['Items'] : [];

echo 'enrollments.items=' . count($items) . PHP_EOL;
if ($items !== []) {
    echo 'enrollments.first.keys=' . implode(',', array_keys($items[0])) . PHP_EOL;
    $orgUnit = is_array($items[0]['OrgUnit'] ?? null) ? $items[0]['OrgUnit'] : [];
    $access = is_array($items[0]['Access'] ?? null) ? $items[0]['Access'] : [];
    echo 'enrollments.orgunit.keys=' . implode(',', array_keys($orgUnit)) . PHP_EOL;
    echo 'enrollments.access.keys=' . implode(',', array_keys($access)) . PHP_EOL;
    $orgUnitId = $orgUnit['Identifier'] ?? $orgUnit['Id'] ?? null;

    if (is_int($orgUnitId) || is_string($orgUnitId)) {
        $classlist = $client->get('le', '/' . $orgUnitId . '/classlist/');
        echo 'classlist.top.keys=' . implode(',', array_slice(array_keys($classlist), 0, 10)) . PHP_EOL;
        if (isset($classlist[0]) && is_array($classlist[0])) {
            echo 'classlist.first.keys=' . implode(',', array_keys($classlist[0])) . PHP_EOL;
        }
        $roles = [];
        foreach ($classlist as $classlistUser) {
            if (is_array($classlistUser) && isset($classlistUser['ClasslistRoleDisplayName'])) {
                $roles[(string) $classlistUser['ClasslistRoleDisplayName']] = true;
            }
        }
        echo 'classlist.roles=' . implode('|', array_keys($roles)) . PHP_EOL;
        $classlistItems = isset($classlist['Items']) && is_array($classlist['Items']) ? $classlist['Items'] : [];
        echo 'classlist.items=' . count($classlistItems) . PHP_EOL;
        if ($classlistItems !== []) {
            echo 'classlist.first.keys=' . implode(',', array_keys($classlistItems[0])) . PHP_EOL;
        }
    }
}
