<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';

// Resume cursos con matriculas por periodo y muestra cursos todavia sin clasificar.
$database = Database::connection();

echo "PERIOD_COVERAGE\n";
$periods = $database->query(
    'SELECT COALESCE(academic_period, \'SIN_PERIODO\') AS academic_period,
            COUNT(*) AS courses,
            SUM(CASE WHEN enrollment_count = 0 THEN 1 ELSE 0 END) AS courses_without_classes
     FROM (
         SELECT c.id, c.academic_period, COUNT(e.id) AS enrollment_count
         FROM courses c
         LEFT JOIN enrollments e ON e.course_id = c.id AND e.active = TRUE
         GROUP BY c.id, c.academic_period
     ) coverage
     GROUP BY academic_period
     ORDER BY academic_period'
)->fetchAll();

foreach ($periods as $period) {
    echo $period['academic_period'] . ' courses=' . $period['courses'] . ' without_classes=' . $period['courses_without_classes'] . PHP_EOL;
}

$unclassified = $database->query(
    "SELECT id, brightspace_org_unit_id, code, name
     FROM courses
     WHERE academic_period IS NULL
     ORDER BY id
     LIMIT 100"
)->fetchAll();

echo 'UNCLASSIFIED_COURSES total=' . count($unclassified) . ' shown_limit=100' . PHP_EOL;
foreach ($unclassified as $course) {
    echo 'COURSE id=' . $course['id'] . ' org_unit=' . $course['brightspace_org_unit_id'] . ' code=' . ($course['code'] ?? '') . ' name=' . $course['name'] . PHP_EOL;
}

$latestRun = $database->query(
    'SELECT id, status, records_processed, error_message, started_at, completed_at
     FROM synchronization_runs ORDER BY id DESC LIMIT 1'
)->fetch();

if ($latestRun !== false) {
    echo 'LATEST_SYNC id=' . $latestRun['id'] . ' status=' . $latestRun['status']
        . ' records=' . $latestRun['records_processed']
        . ' error=' . ($latestRun['error_message'] ?? '') . PHP_EOL;
}