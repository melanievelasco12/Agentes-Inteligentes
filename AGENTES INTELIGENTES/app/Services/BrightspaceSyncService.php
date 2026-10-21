<?php

declare(strict_types=1);

/**
 * Sincroniza el catalogo academico, estudiantes y matriculas desde Brightspace
 * y registra el resultado de cada ejecucion.
 */
final class BrightspaceSyncService
{
    private const SYNC_LOCK = 'tesa_brightspace_sync';

    public function __construct(private BrightspaceClient $client, private PDO $database)
    {
    }

    public function synchronizeCourseCatalog(): int
    {
        // El bloqueo evita que dos procesos cambien el catalogo al mismo tiempo.
        $lock = (int) $this->database->query("SELECT GET_LOCK('" . self::SYNC_LOCK . "', 0)")->fetchColumn();
        if ($lock !== 1) {
            throw new RuntimeException('Ya existe una sincronización de Brightspace en ejecución.');
        }

        $runId = null;
        $processed = 0;
        $catalogCourseIds = [];

        try {
            set_time_limit(0);
            ignore_user_abort(true);
            $runId = $this->startRun('courses');

            $organization = $this->client->get('lp', '/organization/info');
            $rootOrgUnitId = (int) ($organization['Identifier'] ?? 0);
            if ($rootOrgUnitId <= 0) {
                throw new RuntimeException('Brightspace no devolvió el identificador de la organización.');
            }

            $bookmark = null;
            do {
                $query = $bookmark === null ? [] : ['bookmark' => $bookmark];
                $page = $this->client->get(
                    'lp',
                    '/orgstructure/' . $rootOrgUnitId . '/descendants/paged/',
                    $query
                );
                $units = $page['Items'] ?? [];
                if (!is_array($units)) {
                    throw new RuntimeException('Brightspace devolvió una lista inválida de unidades organizativas.');
                }

                foreach ($units as $unit) {
                    if (!is_array($unit)) {
                        continue;
                    }

                    $typeCode = (string) ($unit['Type']['Code'] ?? '');
                    if ($typeCode === 'Semester') {
                        $semesterCode = trim((string) ($unit['Code'] ?? ''));
                        if ($semesterCode !== '') {
                            $periodCode = $this->periodFromCode($semesterCode) ?? $semesterCode;
                            $this->upsertAcademicPeriod(
                                $periodCode,
                                (string) ($unit['Name'] ?? $periodCode)
                            );
                        }

                        continue;
                    }

                    if ($typeCode !== 'Course Offering') {
                        continue;
                    }

                    $orgUnitId = (int) ($unit['Identifier'] ?? 0);
                    $code = isset($unit['Code']) ? (string) $unit['Code'] : null;
                    if ($orgUnitId <= 0 || $this->periodFromCode($code) === null) {
                        continue;
                    }

                    $this->upsertCourse([
                        'Id' => $orgUnitId,
                        'Code' => $code,
                        'Name' => (string) ($unit['Name'] ?? 'Curso sin nombre'),
                    ]);
                    $catalogCourseIds[$orgUnitId] = true;
                    $processed++;
                }

                $paging = is_array($page['PagingInfo'] ?? null) ? $page['PagingInfo'] : [];
                $hasMore = (bool) ($paging['HasMoreItems'] ?? false);
                $nextBookmark = (string) ($paging['Bookmark'] ?? '');
                if ($hasMore && ($nextBookmark === '' || $nextBookmark === $bookmark)) {
                    throw new RuntimeException('Brightspace no proporcionó un bookmark válido para continuar la sincronización.');
                }
                $bookmark = $hasMore ? $nextBookmark : null;
            } while ($bookmark !== null);

            $this->deactivateCoursesOutsideCatalog(array_keys($catalogCourseIds));

            $this->finishRun($runId, 'completed', $processed, null);
            return $processed;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->finishRun($runId, 'failed', $processed, $exception->getMessage());
            }
            throw $exception;
        } finally {
            $this->database->query("SELECT RELEASE_LOCK('" . self::SYNC_LOCK . "')");
        }
    }

    /** @param array<int, int> $currentOrgUnitIds */
    private function deactivateCoursesOutsideCatalog(array $currentOrgUnitIds): void
    {
        if ($currentOrgUnitIds === []) {
            throw new RuntimeException('El catálogo de Brightspace no devolvió ofertas de curso; no se modificó el catálogo local.');
        }

        $currentIds = array_fill_keys($currentOrgUnitIds, true);
        $localIds = $this->database->query(
            "SELECT brightspace_org_unit_id FROM courses
             WHERE active = TRUE AND (academic_period IS NULL OR academic_period = '')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $staleIds = [];

        foreach ($localIds as $localId) {
            $localId = (int) $localId;
            if (!isset($currentIds[$localId])) {
                $staleIds[] = $localId;
            }
        }

        foreach (array_chunk($staleIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->database->prepare(
                "UPDATE courses SET active = FALSE WHERE brightspace_org_unit_id IN ({$placeholders})"
            );
            $statement->execute($chunk);
        }
    }

    public function synchronize(): int
    {
        // La sincronizacion de matriculas comparte el mismo bloqueo que la del catalogo.
        $lock = (int) $this->database->query("SELECT GET_LOCK('" . self::SYNC_LOCK . "', 0)")->fetchColumn();
        if ($lock !== 1) {
            throw new RuntimeException('Ya existe una sincronización de Brightspace en ejecución.');
        }

        $runId = null;
        $processed = 0;

        try {
            $runId = $this->startRun();
            foreach ($this->fetchEnrollments() as $enrollment) {
                $orgUnit = is_array($enrollment['OrgUnit'] ?? null) ? $enrollment['OrgUnit'] : [];
                $orgUnitId = (int) ($orgUnit['Id'] ?? $orgUnit['Identifier'] ?? 0);

                if ($orgUnitId <= 0) {
                    continue;
                }

                $courseId = $this->upsertCourse($orgUnit);
                try {
                    $classlist = $this->client->get('le', '/' . $orgUnitId . '/classlist/');
                } catch (RuntimeException $exception) {
                    if (str_contains($exception->getMessage(), 'HTTP 403')) {
                        continue;
                    }

                    throw $exception;
                }

                $classlistItems = $classlist['Items'] ?? $classlist['Objects'] ?? $classlist;
                if (!is_array($classlistItems)) {
                    continue;
                }

                foreach ($classlistItems as $classlistUser) {
                    if (!is_array($classlistUser) || !in_array((string) ($classlistUser['ClasslistRoleDisplayName'] ?? ''), ['Estudiante', 'Student'], true)) {
                        continue;
                    }

                    $email = trim((string) ($classlistUser['Email'] ?? ''));
                    $fullName = trim((string) ($classlistUser['FirstName'] ?? '') . ' ' . (string) ($classlistUser['LastName'] ?? ''));
                    $brightspaceUserId = (int) ($classlistUser['Identifier'] ?? 0);

                    if ($brightspaceUserId <= 0 || $email === '' || $fullName === '' || !str_ends_with(strtolower($email), '@estud.tesa.edu.ec')) {
                        continue;
                    }

                    $studentId = $this->upsertStudent($brightspaceUserId, $email, $fullName);
                    $this->upsertEnrollment($studentId, $courseId, $classlistUser['LastAccessed'] ?? null, (bool) ($enrollment['Access']['IsActive'] ?? true));
                    $processed++;
                }
            }

            $this->finishRun($runId, 'completed', $processed, null);
            return $processed;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->finishRun($runId, 'failed', $processed, $exception->getMessage());
            }
            throw $exception;
        } finally {
            $this->database->query("SELECT RELEASE_LOCK('" . self::SYNC_LOCK . "')");
        }
    }

    private function fetchEnrollments(): array
    {
        $agentOrgUnitId = (int) getenv('BRIGHTSPACE_AGENT_ORG_UNIT_ID');
        if ($agentOrgUnitId > 0) {
            return [[
                'OrgUnit' => [
                    'Id' => $agentOrgUnitId,
                    'Name' => 'Unidad organizativa Brightspace ' . $agentOrgUnitId,
                    'Code' => null,
                ],
                'Access' => ['IsActive' => true],
            ]];
        }

        $all = [];
        $bookmark = null;

        do {
            $page = $this->client->get('lp', '/enrollments/myenrollments/', $bookmark === null ? [] : ['bookmark' => $bookmark]);
            $all = array_merge($all, is_array($page['Items'] ?? null) ? $page['Items'] : []);
            $paging = is_array($page['PagingInfo'] ?? null) ? $page['PagingInfo'] : [];
            $bookmark = ($paging['HasMoreItems'] ?? false) ? (string) ($paging['Bookmark'] ?? '') : null;
        } while ($bookmark !== null && $bookmark !== '');

        return $all;
    }

    private function upsertStudent(int $brightspaceUserId, string $email, string $fullName): int
    {
        $statement = $this->database->prepare('INSERT INTO students (brightspace_user_id, institutional_email, full_name, last_synced_at) VALUES (:user_id, :email, :name, NOW()) ON DUPLICATE KEY UPDATE institutional_email = VALUES(institutional_email), full_name = VALUES(full_name), active = TRUE, last_synced_at = NOW()');
        $statement->execute(['user_id' => $brightspaceUserId, 'email' => $email, 'name' => $fullName]);
        $id = $this->database->lastInsertId();
        if ($id !== '0') {
            return (int) $id;
        }

        $lookup = $this->database->prepare('SELECT id FROM students WHERE brightspace_user_id = :user_id');
        $lookup->execute(['user_id' => $brightspaceUserId]);
        return (int) $lookup->fetchColumn();
    }

    private function upsertCourse(array $orgUnit): int
    {
        $code = ($orgUnit['Code'] ?? null) !== null ? (string) $orgUnit['Code'] : null;
        $statement = $this->database->prepare('INSERT INTO courses (brightspace_org_unit_id, code, name, academic_period, last_synced_at) VALUES (:unit_id, :code, :name, :academic_period, NOW()) ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), academic_period = VALUES(academic_period), active = TRUE, last_synced_at = NOW()');
        $statement->execute([
            'unit_id' => (int) $orgUnit['Id'],
            'code' => $code,
            'name' => (string) ($orgUnit['Name'] ?? 'Curso sin nombre'),
            'academic_period' => $this->periodFromCode($code),
        ]);
        $id = $this->database->lastInsertId();
        if ($id !== '0') {
            return (int) $id;
        }

        $lookup = $this->database->prepare('SELECT id FROM courses WHERE brightspace_org_unit_id = :unit_id');
        $lookup->execute(['unit_id' => (int) $orgUnit['Id']]);
        return (int) $lookup->fetchColumn();
    }

    private function upsertAcademicPeriod(string $code, string $name): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO academic_periods (code, name, active, last_synced_at)
             VALUES (:code, :name, TRUE, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), active = TRUE, last_synced_at = NOW()'
        );
        $statement->execute(['code' => $code, 'name' => $name]);
    }

    private function periodFromCode(?string $code): ?string
    {
        if ($code === null || !preg_match('/^(\d{2})\.?([A-Z]\d*)(?:[._]|$)/i', $code, $matches)) {
            return null;
        }

        return strtoupper($matches[1] . '.' . $matches[2]);
    }

    private function upsertEnrollment(int $studentId, int $courseId, mixed $lastAccessed, bool $active): void
    {
        $timestamp = is_string($lastAccessed) ? strtotime($lastAccessed) : false;
        $lastActivityAt = $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
        $statement = $this->database->prepare('INSERT INTO enrollments (student_id, course_id, active, last_activity_at, last_synced_at) VALUES (:student_id, :course_id, :active, :last_activity_at, NOW()) ON DUPLICATE KEY UPDATE active = VALUES(active), last_activity_at = VALUES(last_activity_at), last_synced_at = NOW()');
        $statement->execute(['student_id' => $studentId, 'course_id' => $courseId, 'active' => $active ? 1 : 0, 'last_activity_at' => $lastActivityAt]);
    }

    private function startRun(string $type = 'enrollments'): int
    {
        $statement = $this->database->prepare('INSERT INTO synchronization_runs (type, status) VALUES (:type, :status)');
        $statement->execute(['type' => $type, 'status' => 'running']);
        return (int) $this->database->lastInsertId();
    }

    private function finishRun(int $runId, string $status, int $processed, ?string $error): void
    {
        $statement = $this->database->prepare('UPDATE synchronization_runs SET status = :status, records_processed = :records_processed, error_message = :error_message, completed_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $runId, 'status' => $status, 'records_processed' => $processed, 'error_message' => $error]);
    }
}