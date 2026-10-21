<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailRecipientList.php';
require_once dirname(__DIR__, 2) . '/integrations/brightspace/BrightspaceBatchClient.php';

interface BrightspaceAgentClient
{
    public function get(string $product, string $path, array $query = []): array;

    public function post(string $product, string $path, array $body = []): array;

    public function put(string $product, string $path, array $body): array;
}

/**
 * Aplica cambios seguros a agentes de Brightspace y confirma su resultado
 * mediante una nueva lectura antes de informar que la operacion tuvo exito.
 */
final class BrightspaceAgentService
{
    public function __construct(private BrightspaceAgentClient|BrightspaceClient $client, private int $orgUnitId)
    {
        if ($this->orgUnitId <= 0) {
            throw new InvalidArgumentException('BRIGHTSPACE_AGENT_ORG_UNIT_ID no está configurado.');
        }
    }

    public function list(): array
    {
        try {
            $response = $this->client->get('le', '/' . $this->orgUnitId . '/agents', ['sortField' => 'Name']);
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'HTTP 404')) {
                throw $exception;
            }

            // Algunos cursos no exponen la lista; en ese caso se consultan los IDs configurados.
            return $this->listConfiguredAgents();
        }

        $agents = $response['Items'] ?? $response['Objects'] ?? [];
        if (!is_array($agents)) {
            return [];
        }

        foreach ($agents as &$agent) {
            if (is_array($agent) && !isset($agent['AgentId']) && isset($agent['Id'])) {
                $agent['AgentId'] = $agent['Id'];
            }
        }
        unset($agent);

        $knownIds = array_map(
            static fn (mixed $agent): int => is_array($agent) ? (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0) : 0,
            $agents
        );
        foreach ($this->listConfiguredAgents() as $configuredAgent) {
            if (!is_array($configuredAgent)) {
                continue;
            }

            $configuredId = (int) ($configuredAgent['AgentId'] ?? $configuredAgent['Id'] ?? 0);
            if ($configuredId > 0 && !in_array($configuredId, $knownIds, true)) {
                $agents[] = $configuredAgent;
                $knownIds[] = $configuredId;
            }
        }

        return $agents;
    }

    private function listConfiguredAgents(): array
    {
        $configuredIds = preg_split('/\s*,\s*/', (string) getenv('BRIGHTSPACE_AGENT_IDS'), -1, PREG_SPLIT_NO_EMPTY);
        $agents = [];

        foreach ($configuredIds ?: [] as $configuredId) {
            $agentId = (int) $configuredId;
            if ($agentId <= 0) {
                continue;
            }

            try {
                $agents[] = $this->fetchAgent($agentId);
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'HTTP 404')) {
                    throw $exception;
                }

                $agents[] = [
                    'AgentId' => $agentId,
                    'Name' => match ($agentId) {
                        2364 => 'Recordatorio (8 días)',
                        2363 => 'Recordatorio (30 días)',
                        2362 => 'Recordatorio (15 días)',
                        3460 => 'PRUEBA CORREO MELANIE',
                        default => 'Agente ' . $agentId,
                    },
                    'IsEnabled' => false,
                ];
            }
        }

        return $agents;
    }

    public function fetchAgent(int $agentId, ?int $orgUnitId = null): array
    {
        if ($agentId <= 0) {
            throw new InvalidArgumentException('El identificador del agente no es válido.');
        }

        $orgUnitId ??= $this->orgUnitId;
        if ($orgUnitId <= 0) {
            throw new InvalidArgumentException('La unidad organizativa del agente no es válida.');
        }

        try {
            $agent = $this->client->get('le', '/' . $orgUnitId . '/agents/' . $agentId . '/');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'HTTP 404')) {
                throw $exception;
            }

            // Brightspace puede devolver 404 en el detalle aunque incluya el agente en la lista.
            $response = $this->client->get('le', '/' . $orgUnitId . '/agents', [
                'sortField' => 'Name',
                'pageSize' => 200,
            ]);
            $agents = $response['Items'] ?? $response['Objects'] ?? [];

            foreach (is_array($agents) ? $agents : [] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $candidateId = (int) ($candidate['AgentId'] ?? $candidate['Id'] ?? 0);
                if ($candidateId !== $agentId) {
                    continue;
                }

                $candidate['AgentId'] = $candidateId;
                $candidate['OrgUnitId'] = $orgUnitId;

                return $candidate;
            }

            throw $exception;
        }

        if (!isset($agent['AgentId']) && isset($agent['Id'])) {
            $agent['AgentId'] = $agent['Id'];
        }
        $agent['OrgUnitId'] = $orgUnitId;

        return $agent;
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array{AgentId: int, OrgUnitId: int, Name: string}>
     */
    public static function agentsWithEmailAction(array $agents): array
    {
        $targets = [];

        foreach ($agents as $agent) {
            if (!is_array($agent) || !is_array($agent['Action']['EmailAction'] ?? null)) {
                continue;
            }

            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
            if ($agentId <= 0 || $orgUnitId <= 0) {
                continue;
            }

            $targets[$orgUnitId . ':' . $agentId] = [
                'AgentId' => $agentId,
                'OrgUnitId' => $orgUnitId,
                'Name' => (string) ($agent['Name'] ?? ('Agente ' . $agentId)),
            ];
        }

        return array_values($targets);
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array{AgentId: int, OrgUnitId: int, Name: string}>
     */
    public static function agentsWithEmailActionForCareerPeriod(
        array $agents,
        string $career,
        string $period
    ): array {
        return self::agentsWithEmailAction(
            self::emailActionAgentsForCareerPeriod($agents, $career, $period)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array<string, mixed>>
     */
    public static function emailActionAgentsForCareerPeriod(
        array $agents,
        string $career,
        string $period
    ): array {
        $career = trim($career);
        if ($career === '' || $period === '') {
            return [];
        }

        return array_values(array_filter(
            $agents,
            static fn (array $agent): bool => CourseCatalogService::careerMatches(
                isset($agent['career']) ? (string) $agent['career'] : null,
                $career
            )
                && (string) ($agent['period'] ?? '') === $period
                && is_array($agent['Action']['EmailAction'] ?? null)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array{AgentId: int, OrgUnitId: int, Name: string}>
     */
    public static function agentsWithEmailActionForOrgUnit(array $agents, int $orgUnitId): array
    {
        if ($orgUnitId <= 0) {
            return [];
        }

        return self::agentsWithEmailAction(array_values(array_filter(
            $agents,
            static fn (array $agent): bool => (int) ($agent['OrgUnitId'] ?? 0) === $orgUnitId
        )));
    }

    public static function formatEmailMessageAsHtml(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", trim($message));
        if ($message === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $message, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($paragraphs)) {
            throw new RuntimeException('No se pudo preparar el formato HTML del mensaje.');
        }

        $formattedParagraphs = [];
        foreach ($paragraphs as $paragraph) {
            $escapedParagraph = htmlspecialchars(
                trim($paragraph),
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            );
            $formattedParagraphs[] = '<p>' . str_replace("\n", '<br />', $escapedParagraph) . '</p>';
        }

        return implode("\n", $formattedParagraphs);
    }

    /** Re-reads the agent, changes the requested email fields, and verifies every other setting. */
    public function updateEmailAction(int $agentId, int $orgUnitId, string $recipient, string $message): array
    {
        return $this->updateEmailActionFields($agentId, $orgUnitId, $recipient, $message);
    }

    public function updateEmailActionCcRecipient(int $agentId, int $orgUnitId, string $recipient): array
    {
        return $this->updateEmailActionFields(
            $agentId,
            $orgUnitId,
            trim($recipient) === '' ? '' : EmailRecipientList::normalize($recipient),
            null,
            true
        );
    }

    public function updateEmailActionMessage(int $agentId, int $orgUnitId, string $message): array
    {
        return $this->updateEmailActionFields($agentId, $orgUnitId, null, $message);
    }

    /**
     * Reads, updates, and verifies a small group of email agents using concurrent API waves.
     *
     * @param array<int, array{AgentId: int, OrgUnitId: int, Name?: string}> $targets
     * @return array<int, array{target: array, confirmed_agent: array|null, error: string|null}>
     */
    public function updateEmailActionMessages(array $targets, string $message): array
    {
        if (count($targets) > 8) {
            throw new InvalidArgumentException('El grupo de agentes excede el máximo permitido.');
        }

        if (!($this->client instanceof BrightspaceBatchClient)) {
            $results = [];
            foreach ($targets as $index => $target) {
                try {
                    $results[$index] = [
                        'target' => $target,
                        'confirmed_agent' => $this->updateEmailActionMessage(
                            (int) ($target['AgentId'] ?? 0),
                            (int) ($target['OrgUnitId'] ?? 0),
                            $message
                        ),
                        'error' => null,
                    ];
                } catch (Throwable $exception) {
                    $results[$index] = [
                        'target' => $target,
                        'confirmed_agent' => null,
                        'error' => $exception->getMessage(),
                    ];
                }
            }

            return $results;
        }

        $results = [];
        $readRequests = [];
        foreach ($targets as $index => $target) {
            $results[$index] = [
                'target' => $target,
                'confirmed_agent' => null,
                'error' => null,
            ];
            $agentId = (int) ($target['AgentId'] ?? 0);
            $orgUnitId = (int) ($target['OrgUnitId'] ?? 0);
            if ($agentId <= 0 || $orgUnitId <= 0) {
                $results[$index]['error'] = 'El agente no tiene identificadores válidos.';
                continue;
            }

            $readRequests[$index] = [
                'method' => 'GET',
                'product' => 'le',
                'path' => '/' . $orgUnitId . '/agents/' . $agentId . '/',
            ];
        }

        $readResponses = $this->client->requestBatchJson($readRequests);
        $preparedAgents = [];
        $updateRequests = [];
        $formattedMessage = self::formatEmailMessageAsHtml($message);

        foreach ($readRequests as $index => $_request) {
            $response = $readResponses[$index] ?? null;
            if (is_array($response) && (int) ($response['status'] ?? 0) === 404) {
                try {
                    $freshAgent = $this->fetchAgent(
                        (int) $targets[$index]['AgentId'],
                        (int) $targets[$index]['OrgUnitId']
                    );
                } catch (Throwable $exception) {
                    $results[$index]['error'] = $exception->getMessage();
                    continue;
                }
            } elseif (
                !is_array($response)
                || $response['error'] !== null
                || !is_array($response['data'])
            ) {
                $results[$index]['error'] = is_array($response)
                    ? (string) ($response['error'] ?? 'No se pudo leer la configuración actual del agente.')
                    : 'Brightspace no devolvió el resultado de lectura del agente.';
                continue;
            } else {
                $freshAgent = $response['data'];
            }

            $target = $targets[$index];
            $agentId = (int) $target['AgentId'];
            $orgUnitId = (int) $target['OrgUnitId'];
            if (!isset($freshAgent['AgentId']) && isset($freshAgent['Id'])) {
                $freshAgent['AgentId'] = $freshAgent['Id'];
            }
            $freshAgent['OrgUnitId'] = $orgUnitId;

            if (
                (int) ($freshAgent['AgentId'] ?? 0) !== $agentId
                || !is_array($freshAgent['Action']['EmailAction'] ?? null)
            ) {
                $results[$index]['error'] = 'El agente ya no tiene la acción de correo esperada para actualizar.';
                continue;
            }

            $updatedAgent = $freshAgent;
            $updatedAgent['Action']['EmailAction']['Message'] = $formattedMessage;
            unset($updatedAgent['LastRunDate'], $updatedAgent['NextRunDate']);
            $preparedAgents[$index] = [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'expected' => $updatedAgent,
            ];
            $updateRequests[$index] = [
                'method' => 'PUT',
                'product' => 'le',
                'path' => '/' . $orgUnitId . '/agents/' . $agentId,
                'body' => $updatedAgent,
            ];
        }

        $updateResponses = $this->client->requestBatchJson($updateRequests);
        $verifyRequests = [];
        foreach ($updateRequests as $index => $_request) {
            $response = $updateResponses[$index] ?? null;
            if (!is_array($response) || $response['error'] !== null) {
                $results[$index]['error'] = is_array($response)
                    ? (string) ($response['error'] ?? 'No se pudo actualizar el agente.')
                    : 'Brightspace no devolvió el resultado de actualización del agente.';
                continue;
            }

            $agentId = $preparedAgents[$index]['agent_id'];
            $orgUnitId = $preparedAgents[$index]['org_unit_id'];
            $verifyRequests[$index] = [
                'method' => 'GET',
                'product' => 'le',
                'path' => '/' . $orgUnitId . '/agents/' . $agentId . '/',
            ];
        }

        $verifyResponses = $this->client->requestBatchJson($verifyRequests);
        foreach ($verifyRequests as $index => $_request) {
            $response = $verifyResponses[$index] ?? null;
            if (is_array($response) && (int) ($response['status'] ?? 0) === 404) {
                try {
                    $confirmedAgent = $this->fetchAgent(
                        $preparedAgents[$index]['agent_id'],
                        $preparedAgents[$index]['org_unit_id']
                    );
                    $response = ['data' => $confirmedAgent, 'error' => null];
                } catch (Throwable $exception) {
                    $results[$index]['error'] = $exception->getMessage();
                    continue;
                }
            }
            if (!is_array($response) || $response['error'] !== null || !is_array($response['data'])) {
                $results[$index]['error'] = is_array($response)
                    ? (string) ($response['error'] ?? 'No se pudo verificar el agente actualizado.')
                    : 'Brightspace no devolvió el resultado de verificación del agente.';
                continue;
            }

            $expectedAgent = $preparedAgents[$index]['expected'];
            $confirmedAgent = $response['data'];
            if (!isset($confirmedAgent['AgentId']) && isset($confirmedAgent['Id'])) {
                $confirmedAgent['AgentId'] = $confirmedAgent['Id'];
            }
            $confirmedAgent['OrgUnitId'] = $preparedAgents[$index]['org_unit_id'];

            $expectedMessage = $expectedAgent['Action']['EmailAction']['Message'] ?? null;
            if (
                (int) ($confirmedAgent['AgentId'] ?? 0) !== $preparedAgents[$index]['agent_id']
                || !is_array($confirmedAgent['Action']['EmailAction'] ?? null)
                || ($confirmedAgent['Action']['EmailAction']['Message'] ?? null) !== $expectedMessage
            ) {
                $results[$index]['error'] =
                    'Brightspace recibió la solicitud, pero no confirmó el mensaje del agente '
                    . $preparedAgents[$index]['agent_id'] . '.';
                continue;
            }

            unset($expectedAgent['LastRunDate'], $expectedAgent['NextRunDate']);
            $observedAgent = $confirmedAgent;
            unset($observedAgent['LastRunDate'], $observedAgent['NextRunDate']);
            if (is_array($expectedAgent['Action']['EmailAction'] ?? null)) {
                $expectedAgent['Action']['EmailAction']['To'] = EmailRecipientList::normalizeForComparison(
                    $expectedAgent['Action']['EmailAction']['To'] ?? null
                );
            }
            if (is_array($observedAgent['Action']['EmailAction'] ?? null)) {
                $observedAgent['Action']['EmailAction']['To'] = EmailRecipientList::normalizeForComparison(
                    $observedAgent['Action']['EmailAction']['To'] ?? null
                );
            }
            if (!self::agentSettingsMatch($expectedAgent, $observedAgent)) {
                $results[$index]['error'] =
                    'Brightspace cambió otra configuración del agente '
                    . $preparedAgents[$index]['agent_id']
                    . ' al actualizar el mensaje. No se actualizará el inventario guardado.';
                continue;
            }

            $results[$index]['confirmed_agent'] = $confirmedAgent;
        }

        return $results;
    }

    private function updateEmailActionFields(
        int $agentId,
        int $orgUnitId,
        ?string $recipient,
        ?string $message,
        bool $updateCc = false
    ): array
    {
        if ($agentId <= 0 || $orgUnitId <= 0) {
            throw new InvalidArgumentException('El agente no tiene identificadores válidos.');
        }

        $freshAgent = $this->fetchAgent($agentId, $orgUnitId);
        if (
            (int) ($freshAgent['AgentId'] ?? $freshAgent['Id'] ?? 0) !== $agentId
            || (int) ($freshAgent['OrgUnitId'] ?? 0) !== $orgUnitId
        ) {
            throw new RuntimeException('La configuración actual del agente no coincide con el inventario.');
        }

        if (!is_array($freshAgent['Action']['EmailAction'] ?? null)) {
            throw new RuntimeException('El agente ya no tiene una acción de correo para actualizar.');
        }

        $updatedAgent = $freshAgent;
        if ($recipient !== null) {
            if ($updateCc) {
                $updatedAgent['Action']['EmailAction']['Cc'] = $recipient;
            } else {
                $updatedAgent['Action']['EmailAction']['To'] = $recipient;
            }
        }
        $formattedMessage = $message !== null ? self::formatEmailMessageAsHtml($message) : null;
        if ($formattedMessage !== null) {
            $updatedAgent['Action']['EmailAction']['Message'] = $formattedMessage;
        }
        unset($updatedAgent['LastRunDate'], $updatedAgent['NextRunDate']);

        $this->client->put('le', '/' . $orgUnitId . '/agents/' . $agentId, $updatedAgent);

        $confirmedAgent = $this->fetchAgent($agentId, $orgUnitId);
        $expectedTo = $recipient !== null && !$updateCc
            ? $recipient
            : ($freshAgent['Action']['EmailAction']['To'] ?? null);
        $expectedCc = $recipient !== null && $updateCc
            ? $recipient
            : ($freshAgent['Action']['EmailAction']['Cc'] ?? null);
        $expectedMessage = $formattedMessage ?? ($freshAgent['Action']['EmailAction']['Message'] ?? null);
        $confirmedTo = $confirmedAgent['Action']['EmailAction']['To'] ?? null;
        $confirmedCc = $confirmedAgent['Action']['EmailAction']['Cc'] ?? null;
        $recipientMatches = $recipient === null
            ? $confirmedTo === $expectedTo
            : EmailRecipientList::normalizeForComparison($confirmedTo)
                    === EmailRecipientList::normalizeForComparison($expectedTo)
                && EmailRecipientList::normalizeForComparison($confirmedCc)
                    === EmailRecipientList::normalizeForComparison($expectedCc);
        if (
            (int) ($confirmedAgent['AgentId'] ?? $confirmedAgent['Id'] ?? 0) !== $agentId
            || (int) ($confirmedAgent['OrgUnitId'] ?? 0) !== $orgUnitId
            || !$recipientMatches
            || ($confirmedAgent['Action']['EmailAction']['Message'] ?? null) !== $expectedMessage
        ) {
            throw new RuntimeException(
                'Brightspace recibió la solicitud, pero no confirmó los campos de correo del agente '
                . $agentId . '.'
            );
        }

        $expectedAgent = $freshAgent;
        if ($recipient !== null) {
            if ($updateCc) {
                $expectedAgent['Action']['EmailAction']['Cc'] = $recipient;
            } else {
                $expectedAgent['Action']['EmailAction']['To'] = $recipient;
            }
        }
        if ($formattedMessage !== null) {
            $expectedAgent['Action']['EmailAction']['Message'] = $formattedMessage;
        }
        unset($expectedAgent['LastRunDate'], $expectedAgent['NextRunDate']);
        $observedAgent = $confirmedAgent;
        unset($observedAgent['LastRunDate'], $observedAgent['NextRunDate']);
        if (is_array($expectedAgent['Action']['EmailAction'] ?? null)) {
            $expectedAgent['Action']['EmailAction']['To'] = EmailRecipientList::normalizeForComparison(
                $expectedAgent['Action']['EmailAction']['To'] ?? null
            );
            if (array_key_exists('Cc', $expectedAgent['Action']['EmailAction'])) {
                $expectedAgent['Action']['EmailAction']['Cc'] = EmailRecipientList::normalizeForComparison(
                    $expectedAgent['Action']['EmailAction']['Cc']
                );
            }
        }
        if (is_array($observedAgent['Action']['EmailAction'] ?? null)) {
            $observedAgent['Action']['EmailAction']['To'] = EmailRecipientList::normalizeForComparison(
                $observedAgent['Action']['EmailAction']['To'] ?? null
            );
            if (array_key_exists('Cc', $observedAgent['Action']['EmailAction'])) {
                $observedAgent['Action']['EmailAction']['Cc'] = EmailRecipientList::normalizeForComparison(
                    $observedAgent['Action']['EmailAction']['Cc']
                );
            }
        }
        if (!self::agentSettingsMatch($expectedAgent, $observedAgent)) {
            throw new RuntimeException(
                'Brightspace cambió otra configuración del agente '
                . $agentId . ' al actualizar el correo. No se actualizará el inventario guardado.'
            );
        }

        return $confirmedAgent;
    }

    private static function agentSettingsMatch(array $expected, array $observed): bool
    {
        foreach ([
            ['IsEnabled'],
            ['Action', 'EmailAction', 'IsEnabled'],
        ] as $path) {
            $expectedValue = self::nestedValue($expected, $path);
            $observedValue = self::nestedValue($observed, $path);
            $expectedBoolean = filter_var($expectedValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $observedBoolean = filter_var($observedValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($expectedBoolean !== null && $observedBoolean !== null) {
                self::setNestedValue($expected, $path, $expectedBoolean);
                self::setNestedValue($observed, $path, $observedBoolean);
            }
        }

        return self::canonicalSettings($expected) === self::canonicalSettings($observed);
    }

    private static function nestedValue(array $values, array $path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($values) || !array_key_exists($key, $values)) {
                return null;
            }
            $values = $values[$key];
        }

        return $values;
    }

    private static function setNestedValue(array &$values, array $path, mixed $value): void
    {
        $key = array_shift($path);
        if ($path === []) {
            $values[$key] = $value;
            return;
        }

        if (is_array($values[$key] ?? null)) {
            self::setNestedValue($values[$key], $path, $value);
        }
    }

    private static function canonicalSettings(array $settings): array
    {
        foreach ($settings as &$value) {
            if (is_array($value)) {
                $value = self::canonicalSettings($value);
            }
        }
        unset($value);

        if (!array_is_list($settings)) {
            ksort($settings);
        }

        return $settings;
    }

    public function setEnabled(array $agent, bool $enabled): array
    {
        $orgUnitId = (int) ($agent['OrgUnitId'] ?? $this->orgUnitId);
        if ($orgUnitId <= 0) {
            throw new InvalidArgumentException('La unidad organizativa del agente no es válida.');
        }

        $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
        if ($agentId <= 0) {
            throw new InvalidArgumentException('El identificador del agente no es válido.');
        }

        $agent['IsEnabled'] = $enabled;
        unset($agent['LastRunDate'], $agent['NextRunDate']);
        $this->client->put('le', '/' . $orgUnitId . '/agents/' . $agentId, $agent);

        $confirmedAgent = $this->fetchAgent($agentId, $orgUnitId);
        $confirmedId = (int) ($confirmedAgent['AgentId'] ?? $confirmedAgent['Id'] ?? 0);
        $confirmedState = array_key_exists('IsEnabled', $confirmedAgent)
            ? filter_var($confirmedAgent['IsEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        if ($confirmedId !== $agentId || $confirmedState === null || $confirmedState !== $enabled) {
            throw new RuntimeException(
                'Brightspace recibió la solicitud, pero no confirmó el cambio de estado del agente '
                . $agentId . '. El panel no actualizará su estado guardado.'
            );
        }

        return $confirmedAgent;
    }

    /**
     * Applies an inclusive local date range without changing other schedule
     * settings or the agent's enabled state.
     *
     * @return array{StartDate: string, EndDate: string}
     */
    public static function normalizeScheduleDateRange(string $startDate, string $endDate): array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);

        if (
            $start === false
            || $end === false
            || $start->format('Y-m-d') !== $startDate
            || $end->format('Y-m-d') !== $endDate
        ) {
            throw new InvalidArgumentException('Selecciona fechas válidas para el cronograma.');
        }

        if ($start > $end) {
            throw new InvalidArgumentException('La fecha final no puede ser anterior a la fecha inicial.');
        }

        $utc = new DateTimeZone('UTC');

        return [
            'StartDate' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s.000\Z'),
            'EndDate' => $end->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d\TH:i:s.000\Z'),
        ];
    }

    public function setScheduleDates(array $agent, string $startDate, string $endDate): array
    {
        $dates = self::normalizeScheduleDateRange($startDate, $endDate);
        $orgUnitId = (int) ($agent['OrgUnitId'] ?? $this->orgUnitId);
        $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
        $schedule = $agent['Schedule'] ?? null;

        if ($orgUnitId <= 0 || $agentId <= 0) {
            throw new InvalidArgumentException('El agente no tiene identificadores válidos.');
        }
        if (!is_array($schedule)) {
            throw new RuntimeException('Brightspace no devolvió un cronograma editable para el agente.');
        }

        $agent['Schedule']['StartDate'] = $dates['StartDate'];
        $agent['Schedule']['EndDate'] = $dates['EndDate'];
        unset($agent['LastRunDate'], $agent['NextRunDate']);

        $this->client->put('le', '/' . $orgUnitId . '/agents/' . $agentId, $agent);

        $confirmedAgent = $this->fetchAgent($agentId, $orgUnitId);
        $confirmedSchedule = $confirmedAgent['Schedule'] ?? null;
        if (
            (int) ($confirmedAgent['AgentId'] ?? $confirmedAgent['Id'] ?? 0) !== $agentId
            || !is_array($confirmedSchedule)
            || !$this->scheduleDatesMatch($confirmedSchedule, $dates)
        ) {
            throw new RuntimeException(
                'Brightspace recibió la solicitud, pero no confirmó las fechas del cronograma del agente '
                . $agentId . '.'
            );
        }

        $originalScheduleEnabled = filter_var(
            $schedule['IsEnabled'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $confirmedScheduleEnabled = filter_var(
            $confirmedSchedule['IsEnabled'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if (
            $originalScheduleEnabled !== null
            && $confirmedScheduleEnabled !== $originalScheduleEnabled
        ) {
            throw new RuntimeException('Brightspace cambió el estado del cronograma al guardar las fechas.');
        }

        $originalAgentEnabled = filter_var(
            $agent['IsEnabled'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $confirmedAgentEnabled = filter_var(
            $confirmedAgent['IsEnabled'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if (
            $originalAgentEnabled !== null
            && $confirmedAgentEnabled !== $originalAgentEnabled
        ) {
            throw new RuntimeException('Brightspace cambió el estado del agente al guardar las fechas.');
        }

        return $confirmedAgent;
    }

    private function scheduleDatesMatch(array $schedule, array $expectedDates): bool
    {
        foreach (['StartDate', 'EndDate'] as $dateKey) {
            $actual = $schedule[$dateKey] ?? null;
            if (!is_string($actual) || strtotime($actual) !== strtotime($expectedDates[$dateKey])) {
                return false;
            }
        }

        return true;
    }

    public static function normalizeCourseActivityDays(mixed $days): int
    {
        if (!is_int($days) && !is_string($days)) {
            throw new InvalidArgumentException('Los días de inactividad deben ser un entero positivo.');
        }

        $normalized = filter_var(
            is_string($days) ? trim($days) : $days,
            FILTER_VALIDATE_INT
        );
        if ($normalized === false || $normalized <= 0) {
            throw new InvalidArgumentException('Los días de inactividad deben ser un entero positivo.');
        }

        return $normalized;
    }

    public function setCourseActivityDays(array $agent, mixed $days): array
    {
        // Se conserva el resto de la condicion y se valida el valor releido tras el PUT.
        $days = self::normalizeCourseActivityDays($days);
        $orgUnitId = (int) ($agent['OrgUnitId'] ?? $this->orgUnitId);
        $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
        if ($orgUnitId <= 0 || $agentId <= 0) {
            throw new InvalidArgumentException('El agente no tiene identificadores válidos.');
        }
        if (!is_array($agent['Condition']['CourseActivity'] ?? null)) {
            throw new RuntimeException('El agente no tiene una condición de actividad del curso editable.');
        }

        $agent['Condition']['CourseActivity']['Days'] = $days;
        unset($agent['LastRunDate'], $agent['NextRunDate']);
        $this->client->put('le', '/' . $orgUnitId . '/agents/' . $agentId, $agent);

        $confirmedAgent = $this->fetchAgent($agentId, $orgUnitId);
        if (
            (int) ($confirmedAgent['AgentId'] ?? $confirmedAgent['Id'] ?? 0) !== $agentId
            || !is_array($confirmedAgent['Condition']['CourseActivity'] ?? null)
            || self::normalizeCourseActivityDays(
                $confirmedAgent['Condition']['CourseActivity']['Days'] ?? null
            ) !== $days
        ) {
            throw new RuntimeException(
                'Brightspace recibió la solicitud, pero no confirmó los días de actividad del curso del agente '
                . $agentId . '.'
            );
        }

        return $confirmedAgent;
    }

    public function runNow(int $agentId, ?int $orgUnitId = null): array
    {
        $orgUnitId ??= $this->orgUnitId;
        if ($orgUnitId <= 0) {
            throw new InvalidArgumentException('La unidad organizativa del agente no es válida.');
        }

        return $this->client->post('le', '/' . $orgUnitId . '/agents/' . $agentId . '/runs', ['RunNowType' => 1]);
    }
}