<?php

declare(strict_types=1);

/**
 * Descubre los agentes inteligentes de Brightspace en toda la estructura
 * académica y los clasifica por período, carrera, módulo (A/B) y umbral de
 * inactividad.
 *
 * Brightspace no expone un listado global de agentes: cada unidad organizativa
 * (curso) mantiene los suyos. Este servicio recorre las unidades sincronizadas,
 * cachea el resultado en disco y devuelve los agentes con su ubicación resuelta.
 */
final class AgentDiscoveryService
{
    /** Tiempo en segundos que se reutiliza el inventario guardado. */
    private const CACHE_TTL = 604800;

    /** Consultas paralelas por lote contra la API de Brightspace.
     *
     * Se mantiene pequeño para no saturar la API ni exceder el tiempo de
     * ejecución del proceso en sitios con muchos cursos activos.
     */
    private const BATCH_SIZE = 8;
    private const REFRESH_BATCH_SIZE = self::BATCH_SIZE;
    private const MAX_DISCOVERY_RETRIES = 2;

    private const CACHE_FILE = '/storage/cache/agents_inventory.json';

    /** @var array<int, true> */
    private array $failedOrgUnitIds = [];

    private int $scannedOrgUnitCount = 0;

    private int $requestedOrgUnitCount = 0;

    public function __construct(
        private BrightspaceClient $client,
        private PDO $database,
        private CourseCatalogService $catalog
    ) {
    }

    /**
     * Agentes descubiertos para un período, con su clasificación resuelta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function agentsForPeriod(?string $period = null, bool $forceRefresh = false): array
    {
        $inventory = $this->inventory($forceRefresh);
        return $this->filterAgentsForPeriod($inventory, $period);
    }

    /**
     * Agentes de la última caché guardada, sin iniciar una consulta completa a Brightspace.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cachedAgentsForPeriod(?string $period = null): array
    {
        $cache = $this->readCache();
        return $this->filterAgentsForPeriod(is_array($cache['agents'] ?? null) ? $cache['agents'] : [], $period);
    }

    /**
     * Resumen del inventario completo, sin filtrar por período.
     *
     * @return array{total: int, by_period: array<string, int>, by_threshold: array<string, int>, generated_at: ?string, complete: ?bool, fresh: bool, scanned_org_unit_count: int, requested_org_unit_count: int, failed_org_unit_ids: array<int, int>}
     */
    public function inventorySummary(): array
    {
        $cache = $this->readCache();
        $inventory = is_array($cache['agents'] ?? null) ? $cache['agents'] : [];

        $byPeriod = [];
        $byThreshold = [];

        foreach ($inventory as $entry) {
            $key = (string) ($entry['period'] ?? 'SIN_PERIODO');
            $byPeriod[$key] = ($byPeriod[$key] ?? 0) + 1;

            $threshold = $entry['Threshold'] ?? null;
            $thresholdKey = $threshold === null ? 'otros' : (string) $threshold;
            $byThreshold[$thresholdKey] = ($byThreshold[$thresholdKey] ?? 0) + 1;
        }

        krsort($byPeriod);

        return [
            'total' => count($inventory),
            'by_period' => $byPeriod,
            'by_threshold' => $byThreshold,
            'generated_at' => $cache['generated_at'] ?? null,
            'complete' => isset($cache['complete']) ? (bool) $cache['complete'] : null,
            'fresh' => $cache !== null && $this->isFresh($cache),
            'scanned_org_unit_count' => (int) ($cache['scanned_org_unit_count'] ?? 0),
            'requested_org_unit_count' => (int) ($cache['requested_org_unit_count'] ?? 0),
            'failed_org_unit_ids' => array_map('intval', $cache['failed_org_unit_ids'] ?? []),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $inventory
     * @return array<int, array<string, mixed>>
     */
    private function filterAgentsForPeriod(array $inventory, ?string $period): array
    {
        if ($period === null || $period === '') {
            return $inventory;
        }

        return array_values(array_filter(
            $inventory,
            static fn (array $entry): bool => ($entry['period'] ?? null) === $period
        ));
    }

    /** @param array<string, bool> $enabledByScope Keys are "orgUnitId:agentId". */
    public function updateCachedAgentStates(array $enabledByScope): void
    {
        if ($enabledByScope === []) {
            return;
        }

        $cache = $this->readCache();
        if ($cache === null || !is_array($cache['agents'] ?? null)) {
            return;
        }

        $updated = false;

        foreach ($cache['agents'] as &$agent) {
            if (!is_array($agent)) {
                continue;
            }

            $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $key = $orgUnitId . ':' . $agentId;

            if (array_key_exists($key, $enabledByScope)) {
                $agent['IsEnabled'] = $enabledByScope[$key];
                $updated = true;
            }
        }
        unset($agent);

        if ($updated) {
            $generatedAt = $cache['generated_at'] ?? null;
            $this->writeCache($cache['agents'], is_string($generatedAt) ? $generatedAt : null, [
                'complete' => (bool) ($cache['complete'] ?? false),
                'scanned_org_unit_count' => (int) ($cache['scanned_org_unit_count'] ?? 0),
                'requested_org_unit_count' => (int) ($cache['requested_org_unit_count'] ?? 0),
                'failed_org_unit_ids' => array_map('intval', $cache['failed_org_unit_ids'] ?? []),
            ]);
        }
    }

    /** @param array<string, array<string, mixed>> $schedulesByScope Keys are "orgUnitId:agentId". */
    public function updateCachedAgentSchedules(array $schedulesByScope): void
    {
        if ($schedulesByScope === []) {
            return;
        }

        $cache = $this->readCache();
        if ($cache === null || !is_array($cache['agents'] ?? null)) {
            return;
        }

        $updated = false;

        foreach ($cache['agents'] as &$agent) {
            if (!is_array($agent)) {
                continue;
            }

            $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $key = $orgUnitId . ':' . $agentId;

            if (isset($schedulesByScope[$key])) {
                $agent['Schedule'] = $schedulesByScope[$key];
                $updated = true;
            }
        }
        unset($agent);

        if ($updated) {
            $generatedAt = $cache['generated_at'] ?? null;
            $this->writeCache($cache['agents'], is_string($generatedAt) ? $generatedAt : null, [
                'complete' => (bool) ($cache['complete'] ?? false),
                'scanned_org_unit_count' => (int) ($cache['scanned_org_unit_count'] ?? 0),
                'requested_org_unit_count' => (int) ($cache['requested_org_unit_count'] ?? 0),
                'failed_org_unit_ids' => array_map('intval', $cache['failed_org_unit_ids'] ?? []),
            ]);
        }
    }

    /** @param array<string, int> $daysByScope Keys are "orgUnitId:agentId". */
    public function updateCachedAgentCourseActivityDays(array $daysByScope): void
    {
        if ($daysByScope === []) {
            return;
        }

        $cache = $this->readCache();
        if ($cache === null || !is_array($cache['agents'] ?? null)) {
            return;
        }

        $updated = false;
        foreach ($cache['agents'] as &$agent) {
            if (!is_array($agent)) {
                continue;
            }

            $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $key = $orgUnitId . ':' . $agentId;
            if (
                !array_key_exists($key, $daysByScope)
                || !is_int($daysByScope[$key])
                || $daysByScope[$key] <= 0
                || !is_array($agent['Condition']['CourseActivity'] ?? null)
            ) {
                continue;
            }

            $days = $daysByScope[$key];
            $agent['Condition']['CourseActivity']['Days'] = $days;
            $threshold = in_array($days, [8, 15, 30], true) ? $days : null;
            if ($threshold === null) {
                foreach ([8, 15, 30] as $candidate) {
                    if (preg_match('/(?<!\\d)' . $candidate . '(?!\\d)/', (string) ($agent['Name'] ?? '')) === 1) {
                        $threshold = $candidate;
                        break;
                    }
                }
            }
            $agent['Threshold'] = $threshold;
            $updated = true;
        }
        unset($agent);

        if ($updated) {
            $generatedAt = $cache['generated_at'] ?? null;
            $this->writeCache($cache['agents'], is_string($generatedAt) ? $generatedAt : null, [
                'complete' => (bool) ($cache['complete'] ?? false),
                'scanned_org_unit_count' => (int) ($cache['scanned_org_unit_count'] ?? 0),
                'requested_org_unit_count' => (int) ($cache['requested_org_unit_count'] ?? 0),
                'failed_org_unit_ids' => array_map('intval', $cache['failed_org_unit_ids'] ?? []),
            ]);
        }
    }

    /** @param array<string, array{To?: string, Cc?: string, Message?: string}> $emailActionsByScope */
    public function updateCachedAgentEmailActions(array $emailActionsByScope): void
    {
        if ($emailActionsByScope === []) {
            return;
        }

        $cache = $this->readCache();
        if ($cache === null || !is_array($cache['agents'] ?? null)) {
            return;
        }

        $updated = false;
        foreach ($cache['agents'] as &$agent) {
            if (!is_array($agent)) {
                continue;
            }

            $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $key = $orgUnitId . ':' . $agentId;
            if (!isset($emailActionsByScope[$key]) || !is_array($agent['Action']['EmailAction'] ?? null)) {
                continue;
            }

            foreach (['To', 'Cc', 'Message'] as $field) {
                if (array_key_exists($field, $emailActionsByScope[$key])) {
                    $agent['Action']['EmailAction'][$field] = $emailActionsByScope[$key][$field];
                }
            }
            $updated = true;
        }
        unset($agent);

        if ($updated) {
            $generatedAt = $cache['generated_at'] ?? null;
            $this->writeCache($cache['agents'], is_string($generatedAt) ? $generatedAt : null, [
                'complete' => (bool) ($cache['complete'] ?? false),
                'scanned_org_unit_count' => (int) ($cache['scanned_org_unit_count'] ?? 0),
                'requested_org_unit_count' => (int) ($cache['requested_org_unit_count'] ?? 0),
                'failed_org_unit_ids' => array_map('intval', $cache['failed_org_unit_ids'] ?? []),
            ]);
        }
    }

    /** @return array{refresh_id: string, processed: int, total: int, done: bool} */
    public function startInventoryRefresh(): array
    {
        $courses = $this->coursesToScan();
        $resumableRefresh = $this->latestResumableRefresh($courses);
        if ($resumableRefresh !== null) {
            return [
                'refresh_id' => $resumableRefresh['refresh_id'],
                'processed' => $resumableRefresh['processed'],
                'total' => $resumableRefresh['total'],
                'done' => false,
                'failed' => $resumableRefresh['failed'],
                'resumed' => true,
            ];
        }

        $refreshId = bin2hex(random_bytes(16));
        if ($courses === []) {
            $cacheWritten = $this->writeCache([], null, [
                'complete' => true,
                'scanned_org_unit_count' => 0,
                'requested_org_unit_count' => 0,
                'failed_org_unit_ids' => [],
            ]);
            if (!$cacheWritten) {
                throw new RuntimeException('No se pudo guardar el inventario actualizado.');
            }

            return [
                'refresh_id' => $refreshId,
                'processed' => 0,
                'total' => 0,
                'done' => true,
            ];
        }

        $state = [
            'refresh_id' => $refreshId,
            'courses' => $courses,
            'next_offset' => 0,
            'agents' => [],
            'seen' => [],
            'failed_org_unit_ids' => [],
            'scanned_org_unit_count' => 0,
        ];
        $this->writeRefreshState($refreshId, $state);

        return [
            'refresh_id' => $refreshId,
            'processed' => 0,
            'total' => count($courses),
            'done' => false,
            'failed' => 0,
            'resumed' => false,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $courses
     * @return array{refresh_id: string, processed: int, total: int, failed: int}|null
     */
    private function latestResumableRefresh(array $courses): ?array
    {
        $stateFiles = glob(PROJECT_ROOT . '/storage/cache/agents_inventory_refresh_*.json');
        if ($stateFiles === false || $stateFiles === []) {
            return null;
        }

        usort($stateFiles, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        $currentCourseIds = array_map(
            static fn (array $course): int => (int) ($course['brightspace_org_unit_id'] ?? 0),
            $courses
        );
        sort($currentCourseIds);

        foreach ($stateFiles as $path) {
            $fileName = basename($path);
            if (preg_match('/^agents_inventory_refresh_([a-f0-9]{32})\.json$/', $fileName, $matches) !== 1) {
                continue;
            }
            $lastModified = filemtime($path);
            if ($lastModified === false || time() - $lastModified >= self::CACHE_TTL) {
                continue;
            }

            $refreshId = $matches[1];
            $state = $this->readRefreshState($refreshId);
            $savedCourses = $state['courses'] ?? null;
            if (
                !is_array($state)
                || ($state['refresh_id'] ?? '') !== $refreshId
                || !is_array($savedCourses)
            ) {
                continue;
            }

            $savedCourseIds = array_map(
                static fn (array $course): int => (int) ($course['brightspace_org_unit_id'] ?? 0),
                $savedCourses
            );
            sort($savedCourseIds);
            $processed = (int) ($state['next_offset'] ?? 0);
            if (
                $savedCourseIds !== $currentCourseIds
                || $processed < 0
                || $processed >= count($savedCourses)
            ) {
                continue;
            }

            return [
                'refresh_id' => $refreshId,
                'processed' => $processed,
                'total' => count($savedCourses),
                'failed' => count($state['failed_org_unit_ids'] ?? []),
            ];
        }

        return null;
    }

    /**
     * @return array{refresh_id: string, processed: int, total: int, done: bool, failed: int}
     */
    public function processInventoryRefreshBatch(string $refreshId): array
    {
        set_time_limit(0);
        ignore_user_abort(true);

        if (preg_match('/^[a-f0-9]{32}$/', $refreshId) !== 1) {
            throw new InvalidArgumentException('El identificador de actualización no es válido.');
        }

        $state = $this->readRefreshState($refreshId);
        if ($state === null || !hash_equals($refreshId, (string) ($state['refresh_id'] ?? ''))) {
            throw new RuntimeException('La actualización del inventario expiró. Vuelve a iniciarla.');
        }

        $courses = $state['courses'] ?? null;
        if (!is_array($courses)) {
            throw new RuntimeException('No se pudo recuperar el progreso de la actualización.');
        }

        $total = count($courses);
        $offset = (int) ($state['next_offset'] ?? 0);
        if ($offset < $total) {
            $chunk = array_slice($courses, $offset, self::REFRESH_BATCH_SIZE);
            $this->failedOrgUnitIds = [];
            $this->scannedOrgUnitCount = 0;
            $responses = $this->fetchBatch($chunk);

            foreach ($responses as $index => $items) {
                $course = $chunk[$index] ?? null;
                if (!is_array($course)) {
                    continue;
                }

                $orgUnitId = (int) $course['brightspace_org_unit_id'];
                $parsed = CourseCatalogService::parseCode((string) ($course['code'] ?? ''));
                foreach ($items as $agent) {
                    if (!is_array($agent)) {
                        continue;
                    }

                    $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
                    $key = $orgUnitId . ':' . $agentId;
                    if ($agentId <= 0 || isset($state['seen'][$key])) {
                        continue;
                    }

                    $state['seen'][$key] = true;
                    $state['agents'][] = $this->decorate($agent, $orgUnitId, $course, $parsed);
                }
            }

            foreach (array_keys($this->failedOrgUnitIds) as $orgUnitId) {
                $state['failed_org_unit_ids'][(string) $orgUnitId] = true;
            }
            $state['scanned_org_unit_count'] = (int) ($state['scanned_org_unit_count'] ?? 0)
                + $this->scannedOrgUnitCount;
            $state['next_offset'] = $offset + count($chunk);
        }

        $processed = (int) $state['next_offset'];
        $done = $processed >= $total;
        if ($done) {
            $agents = $state['agents'] ?? [];
            $failedOrgUnitIds = array_map('intval', array_keys($state['failed_org_unit_ids'] ?? []));
            $cachedInventory = $this->readCache();

            if ($failedOrgUnitIds !== [] && $cachedInventory !== null) {
                $failedUnits = array_fill_keys($failedOrgUnitIds, true);
                $seen = $state['seen'] ?? [];
                foreach ($cachedInventory['agents'] ?? [] as $cachedAgent) {
                    if (!is_array($cachedAgent)) {
                        continue;
                    }

                    $orgUnitId = (int) ($cachedAgent['OrgUnitId'] ?? 0);
                    $agentId = (int) ($cachedAgent['AgentId'] ?? $cachedAgent['Id'] ?? 0);
                    $key = $orgUnitId . ':' . $agentId;
                    if ($agentId > 0 && isset($failedUnits[$orgUnitId]) && !isset($seen[$key])) {
                        $agents[] = $cachedAgent;
                        $seen[$key] = true;
                    }
                }
            }

            usort($agents, static fn (array $a, array $b): int =>
                [$a['period'] ?? '', $a['career'] ?? '', $a['Threshold'] ?? 99, $a['Name'] ?? '']
                <=> [$b['period'] ?? '', $b['career'] ?? '', $b['Threshold'] ?? 99, $b['Name'] ?? '']
            );
            $cacheWritten = $this->writeCache($agents, null, [
                'complete' => $failedOrgUnitIds === [] && (int) $state['scanned_org_unit_count'] === $total,
                'scanned_org_unit_count' => (int) $state['scanned_org_unit_count'],
                'requested_org_unit_count' => $total,
                'failed_org_unit_ids' => $failedOrgUnitIds,
            ]);
            if (!$cacheWritten) {
                throw new RuntimeException('No se pudo guardar el inventario actualizado.');
            }
            $this->deleteRefreshState($refreshId);
        } else {
            $this->writeRefreshState($refreshId, $state);
        }

        return [
            'refresh_id' => $refreshId,
            'processed' => $processed,
            'total' => $total,
            'done' => $done,
            'failed' => count($state['failed_org_unit_ids'] ?? []),
        ];
    }

    /**
     * Inventario completo: recorre las unidades organizativas sincronizadas y
     * recoge los agentes de cada una. Usa caché en disco para no golpear la API
     * en cada carga del panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inventory(bool $forceRefresh = false): array
    {
        $cache = $this->readCache();

        if (
            !$forceRefresh
            && $cache !== null
            && $this->isFresh($cache)
            && is_array($cache['agents'] ?? null)
            && ($cache['agents'] !== [] || ($cache['complete'] ?? false) === true)
        ) {
            return $cache['agents'] ?? [];
        }

        // El descubrimiento de agentes puede requerir varias decenas de
        // llamadas HTTP sobre todo el catálogo de cursos. En este caso el
        // límite de 120 segundos del proceso puede abortar la operación antes de
        // que el caché se actualice. Permitimos que la sincronización complete.
        set_time_limit(0);
        ignore_user_abort(true);

        $this->failedOrgUnitIds = [];
        $this->scannedOrgUnitCount = 0;
        $this->requestedOrgUnitCount = 0;
        $agents = $this->scan();

        if ($this->failedOrgUnitIds !== [] && $cache !== null && ($cache['agents'] ?? []) !== []) {
            $failedOrgUnits = array_fill_keys(array_keys($this->failedOrgUnitIds), true);
            $knownAgents = [];

            foreach ($agents as $agent) {
                $key = (int) ($agent['OrgUnitId'] ?? 0) . ':' . (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
                $knownAgents[$key] = true;
            }

            $preservedCount = 0;
            foreach ($cache['agents'] as $cachedAgent) {
                if (!is_array($cachedAgent)) {
                    continue;
                }

                $orgUnitId = (int) ($cachedAgent['OrgUnitId'] ?? 0);
                $agentId = (int) ($cachedAgent['AgentId'] ?? $cachedAgent['Id'] ?? 0);
                $key = $orgUnitId . ':' . $agentId;

                if (!isset($failedOrgUnits[$orgUnitId]) || $agentId <= 0 || isset($knownAgents[$key])) {
                    continue;
                }

                $agents[] = $cachedAgent;
                $knownAgents[$key] = true;
                $preservedCount++;
            }

            usort($agents, static function (array $a, array $b): int {
                return [$a['period'] ?? '', $a['career'] ?? '', $a['Threshold'] ?? 99, $a['Name'] ?? '']
                    <=> [$b['period'] ?? '', $b['career'] ?? '', $b['Threshold'] ?? 99, $b['Name'] ?? ''];
            });

            AppLogger::warning('Brightspace agent discovery was incomplete; updated accessible courses and preserved cached agents for inaccessible courses.', [
                'failed_org_unit_count' => count($this->failedOrgUnitIds),
                'failed_org_unit_ids' => array_slice(array_keys($this->failedOrgUnitIds), 0, 30),
                'preserved_cached_agent_count' => $preservedCount,
            ]);
        }

        $this->writeCache($agents, null, [
            'complete' => $this->failedOrgUnitIds === []
                && $this->scannedOrgUnitCount === $this->requestedOrgUnitCount,
            'scanned_org_unit_count' => $this->scannedOrgUnitCount,
            'requested_org_unit_count' => $this->requestedOrgUnitCount,
            'failed_org_unit_ids' => array_map('intval', array_keys($this->failedOrgUnitIds)),
        ]);

        return $agents;
    }

    /**
     * Recorre todos los cursos activos y extrae sus agentes.
     *
     * Consulta la API en lotes paralelos: un recorrido secuencial sobre todo el
     * catálogo tardaría horas y agotaría el tiempo de ejecución de PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scan(): array
    {
        $courses = $this->coursesToScan();

        if ($courses === []) {
            return [];
        }

        $this->requestedOrgUnitCount = count($courses);
        $agents = [];
        $seen = [];

        foreach (array_chunk($courses, self::BATCH_SIZE) as $chunk) {
            $responses = $this->fetchBatch($chunk);

            foreach ($responses as $index => $items) {
                $course = $chunk[$index] ?? null;
                if (!is_array($course)) {
                    continue;
                }

                $orgUnitId = (int) $course['brightspace_org_unit_id'];
                $parsed = CourseCatalogService::parseCode((string) ($course['code'] ?? ''));

                foreach ($items as $agent) {
                    if (!is_array($agent)) {
                        continue;
                    }

                    $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
                    $seenKey = $orgUnitId . ':' . $agentId;

                    if ($agentId <= 0 || isset($seen[$seenKey])) {
                        continue;
                    }

                    $seen[$seenKey] = true;
                    $agents[] = $this->decorate($agent, $orgUnitId, $course, $parsed);
                }
            }
        }

        usort($agents, static function (array $a, array $b): int {
            return [$a['period'], $a['career'], $a['Threshold'] ?? 99, $a['Name']]
                <=> [$b['period'], $b['career'], $b['Threshold'] ?? 99, $b['Name']];
        });

        return $agents;
    }

    /** @return array<int, array<string, mixed>> */
    private function coursesToScan(): array
    {
        $courses = $this->database->query(
            'SELECT brightspace_org_unit_id, code, name, academic_period
             FROM courses
             WHERE active = TRUE AND brightspace_org_unit_id IS NOT NULL'
        )->fetchAll();

        $rootOrgUnitId = (int) getenv('BRIGHTSPACE_AGENT_ORG_UNIT_ID');
        if ($rootOrgUnitId > 0) {
            $knownOrgUnitIds = array_fill_keys(
                array_map(static fn (array $course): int => (int) $course['brightspace_org_unit_id'], $courses),
                true
            );
            if (!isset($knownOrgUnitIds[$rootOrgUnitId])) {
                $courses[] = [
                    'brightspace_org_unit_id' => $rootOrgUnitId,
                    'code' => '',
                    'name' => 'Unidad institucional de agentes',
                    'academic_period' => null,
                ];
            }
        }

        return array_values($courses);
    }

    /**
     * Consulta los agentes de varias unidades organizativas en paralelo.
     *
     * @param array<int, array<string, mixed>> $courses
     * @return array<int, array<int, mixed>> lista de agentes indexada por posición
     */
    private function fetchBatch(array $courses, int $retryAttempt = 0): array
    {
        $token = $this->client->accessTokenForDiscovery();
        $baseUrl = $this->client->apiBaseUrl();
        $multi = curl_multi_init();
        $handles = [];
        $results = [];
        $retryCourses = [];

        foreach ($courses as $index => $course) {
            $orgUnitId = (int) $course['brightspace_org_unit_id'];
            $url = $baseUrl . '/le/' . $this->apiVersion() . '/' . $orgUnitId . '/agents?sortField=Name';
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$index] = $handle;
            $results[$index] = [];
        }

        $running = 0;
        $multiStatus = CURLM_OK;
        do {
            do {
                $multiStatus = curl_multi_exec($multi, $running);
            } while (
                defined('CURLM_CALL_MULTI_PERFORM')
                && $multiStatus === constant('CURLM_CALL_MULTI_PERFORM')
            );

            if ($multiStatus !== CURLM_OK || $running === 0) {
                break;
            }

            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1 || $selected === false) {
                usleep(100000);
            }
        } while ($running > 0);

        if ($multiStatus !== CURLM_OK) {
            AppLogger::warning('Brightspace agent discovery multi-request batch failed.', [
                'curl_multi_status' => $multiStatus,
                'curl_multi_error' => curl_multi_strerror($multiStatus),
                'batch_size' => count($handles),
            ]);
        }

        foreach ($handles as $index => $handle) {
            $body = curl_multi_getcontent($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($handle);
            $curlError = curl_error($handle);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            if ($curlError !== '') {
                $orgUnitId = (int) ($courses[$index]['brightspace_org_unit_id'] ?? 0);
                if ($orgUnitId > 0) {
                    $this->failedOrgUnitIds[$orgUnitId] = true;
                }
                AppLogger::warning('Brightspace agent discovery failed for organization unit.', [
                    'org_unit_id' => $orgUnitId,
                    'http_status' => $statusCode,
                    'curl_errno' => $curlErrno,
                    'curl_error' => $curlError,
                    'retry_attempt' => $retryAttempt,
                ]);
                if (
                    $retryAttempt < self::MAX_DISCOVERY_RETRIES
                    && ($statusCode === 0 || $statusCode === 429 || $statusCode >= 500)
                ) {
                    $retryCourses[$index] = $courses[$index];
                }
                continue;
            }

            if ($statusCode < 200 || $statusCode >= 300 || !is_string($body)) {
                $orgUnitId = (int) ($courses[$index]['brightspace_org_unit_id'] ?? 0);
                if ($orgUnitId > 0) {
                    $this->failedOrgUnitIds[$orgUnitId] = true;
                }
                AppLogger::warning('Brightspace agent discovery returned non-success response.', [
                    'org_unit_id' => $orgUnitId,
                    'http_status' => $statusCode,
                    'curl_errno' => $curlErrno,
                    'curl_error' => $curlError,
                    'curl_multi_status' => $multiStatus,
                    'retry_attempt' => $retryAttempt,
                ]);
                if (
                    $retryAttempt < self::MAX_DISCOVERY_RETRIES
                    && ($statusCode === 0 || $statusCode === 429 || $statusCode >= 500)
                ) {
                    $retryCourses[$index] = $courses[$index];
                }
                continue;
            }

            $data = json_decode($body, true);

            if (!is_array($data)) {
                $orgUnitId = (int) ($courses[$index]['brightspace_org_unit_id'] ?? 0);
                if ($orgUnitId > 0) {
                    $this->failedOrgUnitIds[$orgUnitId] = true;
                }
                AppLogger::warning('Brightspace agent discovery returned a non-JSON payload.', [
                    'org_unit_id' => $orgUnitId,
                    'http_status' => $statusCode,
                    'retry_attempt' => $retryAttempt,
                ]);
                if ($retryAttempt < self::MAX_DISCOVERY_RETRIES) {
                    $retryCourses[$index] = $courses[$index];
                }
                continue;
            }

            $orgUnitId = (int) ($courses[$index]['brightspace_org_unit_id'] ?? 0);
            $items = $data['Items'] ?? $data['Objects'] ?? [];
            if (!is_array($items)) {
                $this->failedOrgUnitIds[$orgUnitId] = true;
                AppLogger::warning('Brightspace agent discovery returned an invalid agent list.', ['org_unit_id' => $orgUnitId]);
                continue;
            }

            $allItems = $items;
            $next = $data['Next'] ?? null;
            $visitedPages = [];
            $complete = true;

            while (is_string($next) && trim($next) !== '') {
                $nextUrl = $this->nextPageUrl($next, $orgUnitId);
                if ($nextUrl === null || isset($visitedPages[$nextUrl])) {
                    $complete = false;
                    break;
                }
                $visitedPages[$nextUrl] = true;

                $nextHandle = curl_init($nextUrl);
                curl_setopt_array($nextHandle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'Authorization: Bearer ' . $token,
                    ],
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $nextBody = curl_exec($nextHandle);
                $nextStatus = (int) curl_getinfo($nextHandle, CURLINFO_HTTP_CODE);
                $nextError = curl_error($nextHandle);
                curl_close($nextHandle);

                $nextData = is_string($nextBody) ? json_decode($nextBody, true) : null;
                if (
                    $nextError !== ''
                    || $nextStatus < 200
                    || $nextStatus >= 300
                    || !is_array($nextData)
                ) {
                    $complete = false;
                    AppLogger::warning('Brightspace agent discovery could not read a subsequent page.', [
                        'org_unit_id' => $orgUnitId,
                        'http_status' => $nextStatus,
                        'curl_error' => $nextError,
                    ]);
                    if (
                        $retryAttempt < self::MAX_DISCOVERY_RETRIES
                        && ($nextError !== '' || $nextStatus === 0 || $nextStatus === 429 || $nextStatus >= 500)
                    ) {
                        $retryCourses[$index] = $courses[$index];
                    }
                    break;
                }

                $nextItems = $nextData['Items'] ?? $nextData['Objects'] ?? [];
                if (!is_array($nextItems)) {
                    $complete = false;
                    break;
                }
                $allItems = array_merge($allItems, $nextItems);
                $next = $nextData['Next'] ?? null;
            }

            if ($next !== null && !is_string($next)) {
                $complete = false;
            }
            $results[$index] = $allItems;
            if (!$complete) {
                $this->failedOrgUnitIds[$orgUnitId] = true;
                continue;
            }

            unset($this->failedOrgUnitIds[$orgUnitId]);
            $this->scannedOrgUnitCount++;
        }

        curl_multi_close($multi);

        foreach ($retryCourses as $index => $course) {
            usleep(300000 * ($retryAttempt + 1));
            $retryResults = $this->fetchBatch([$index => $course], $retryAttempt + 1);
            if (array_key_exists($index, $retryResults)) {
                $results[$index] = $retryResults[$index];
            }
        }

        return $results;
    }

    private function nextPageUrl(string $next, int $orgUnitId): ?string
    {
        $baseUrl = $this->client->apiBaseUrl() . '/le/' . $this->apiVersion() . '/' . $orgUnitId . '/agents';
        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'], $baseParts['path'])) {
            return null;
        }

        if (str_starts_with($next, '?')) {
            $url = $baseUrl . $next;
        } elseif (str_starts_with($next, '/')) {
            $url = $baseParts['scheme'] . '://' . $baseParts['host']
                . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '')
                . $next;
        } else {
            $url = $next;
        }

        $nextParts = parse_url($url);
        if (
            !is_array($nextParts)
            || strtolower((string) ($nextParts['scheme'] ?? '')) !== strtolower($baseParts['scheme'])
            || strtolower((string) ($nextParts['host'] ?? '')) !== strtolower($baseParts['host'])
            || (int) ($nextParts['port'] ?? 443) !== (int) ($baseParts['port'] ?? 443)
            || ($nextParts['path'] ?? '') !== $baseParts['path']
            || isset($nextParts['user'])
            || isset($nextParts['pass'])
        ) {
            return null;
        }

        return $url;
    }

    private function apiVersion(): string
    {
        return $this->client->apiVersion();
    }

    /**
     * Añade a un agente su ubicación académica y clasificación.
     *
     * @param array<string, mixed> $agent
     * @param array<string, mixed> $course
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function decorate(array $agent, int $orgUnitId, array $course, array $parsed): array
    {
        $name = (string) ($agent['Name'] ?? '');
        $days = (int) ($agent['Condition']['CourseActivity']['Days'] ?? 0);
        $threshold = in_array($days, [8, 15, 30], true) ? $days : null;

        if ($threshold === null) {
            foreach ([8, 15, 30] as $candidate) {
                if (preg_match('/(?<!\d)' . $candidate . '(?!\d)/', $name) === 1) {
                    $threshold = $candidate;
                    break;
                }
            }
        }

        $agent['Threshold'] = $threshold;
        $agent['OrgUnitId'] = $orgUnitId;
        $agent['CourseCode'] = (string) ($course['code'] ?? '');
        $agent['CourseName'] = (string) ($course['name'] ?? '');
        $agent['period'] = $parsed['period'] ?? ((string) ($course['academic_period'] ?? '') ?: null);
        $agent['career'] = $parsed['career'] ?? null;
        $agent['career_label'] = $parsed['career_label'] ?? null;
        $agent['module'] = $parsed['module'] ?? null;
        $agent['module_label'] = $parsed['module_label'] ?? null;
        $agent['section'] = $parsed['parallel'] ?? null;
        $agent['modality'] = $parsed['modality_label'] ?? null;
        $agent['is_container'] = CourseCatalogService::isContainer($parsed);

        $agent['ScopeLabel'] = $this->scopeLabel($agent);

        return $agent;
    }

    /**
     * Descripción legible del alcance académico del agente.
     *
     * @param array<string, mixed> $agent
     */
    private function scopeLabel(array $agent): string
    {
        $parts = [];

        if (!empty($agent['period'])) {
            $parts[] = (string) $agent['period'];
        }

        if (!empty($agent['career_label'])) {
            $parts[] = (string) $agent['career_label'];
        }

        if (!empty($agent['module'])) {
            $parts[] = (string) $agent['module'];
        }

        if (!empty($agent['section'])) {
            $parts[] = CourseCatalogService::parallelLabel((string) $agent['section']);
        } elseif (($agent['is_container'] ?? false) === true) {
            $parts[] = 'Contenedor';
        }

        return $parts === [] ? 'Alcance institucional' : implode(' · ', $parts);
    }

    /** @return array<string, mixed>|null */
    private function readRefreshState(string $refreshId): ?array
    {
        $path = $this->refreshStatePath($refreshId);
        if (!is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo leer el progreso de la actualización.');
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new RuntimeException('No se pudo bloquear el progreso de la actualización.');
        }
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($raw === false) {
            throw new RuntimeException('No se pudo leer el progreso de la actualización.');
        }

        $state = json_decode($raw, true);
        if (!is_array($state)) {
            throw new RuntimeException('El progreso guardado de la actualización no es válido.');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function writeRefreshState(string $refreshId, array $state): void
    {
        $directory = PROJECT_ROOT . '/storage/cache';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de progreso del inventario.');
        }

        try {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudo guardar el progreso del inventario.', 0, $exception);
        }

        $path = $this->refreshStatePath($refreshId);
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('No se pudo guardar el progreso de la actualización.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('No se pudo bloquear el progreso de la actualización.');
        }

        $written = ftruncate($handle, 0) && rewind($handle);
        $jsonLength = strlen($json);
        $offset = 0;
        while ($written && $offset < $jsonLength) {
            $bytesWritten = fwrite($handle, substr($json, $offset));
            if ($bytesWritten === false || $bytesWritten === 0) {
                $written = false;
                break;
            }
            $offset += $bytesWritten;
        }
        if ($written) {
            $written = fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$written) {
            throw new RuntimeException('No se pudo guardar el progreso de la actualización.');
        }
    }

    private function deleteRefreshState(string $refreshId): void
    {
        $path = $this->refreshStatePath($refreshId);
        if (is_file($path) && !unlink($path)) {
            AppLogger::warning('No se pudo eliminar el progreso finalizado del inventario.');
        }
    }

    private function refreshStatePath(string $refreshId): string
    {
        return PROJECT_ROOT . '/storage/cache/agents_inventory_refresh_' . $refreshId . '.json';
    }

    /** @return array{generated_at: string, agents: array<int, array<string, mixed>>}|null */
    private function readCache(): ?array
    {
        $path = PROJECT_ROOT . self::CACHE_FILE;

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['agents'])) {
            return null;
        }

        $agents = $data['agents'];
        if (!is_array($agents)) {
            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $cache */
    private function isFresh(array $cache): bool
    {
        $generatedAt = strtotime((string) ($cache['generated_at'] ?? ''));

        return $generatedAt !== false && (time() - $generatedAt) < self::CACHE_TTL;
    }

    /** @param array<int, array<string, mixed>> $agents */
    private function writeCache(array $agents, ?string $generatedAt = null, ?array $inventoryStatus = null): bool
    {
        $directory = PROJECT_ROOT . '/storage/cache';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            AppLogger::error('No se pudo crear el directorio de caché de agentes.', ['directory' => $directory]);
            return false;
        }

        $payload = [
            'generated_at' => $generatedAt ?? date('c'),
            'agents' => $agents,
            'complete' => (bool) ($inventoryStatus['complete'] ?? false),
            'scanned_org_unit_count' => (int) ($inventoryStatus['scanned_org_unit_count'] ?? 0),
            'requested_org_unit_count' => (int) ($inventoryStatus['requested_org_unit_count'] ?? 0),
            'failed_org_unit_ids' => array_values(array_map(
                'intval',
                $inventoryStatus['failed_org_unit_ids'] ?? []
            )),
        ];

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            AppLogger::exception($exception, ['operation' => 'agent_inventory_cache_encode']);
            return false;
        }

        if (file_put_contents($directory . '/agents_inventory.json', $json) === false) {
            AppLogger::error('No se pudo guardar el inventario de agentes en caché.', ['path' => $directory . '/agents_inventory.json']);
            return false;
        }

        return true;
    }
}