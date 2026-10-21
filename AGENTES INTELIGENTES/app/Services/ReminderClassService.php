<?php

declare(strict_types=1);

/** Groups discovered agents by Brightspace class and tracks verified reminders. */
final class ReminderClassService
{
    public const THRESHOLDS = [8, 15, 30];

    public static function verifiedThreshold(array $agent): ?int
    {
        $values = [
            $agent['Condition']['CourseActivity']['Days'] ?? null,
            $agent['AccessConditionThreshold'] ?? null,
            $agent['LoginConditionThreshold'] ?? null,
        ];

        foreach ($values as $value) {
            if (!is_int($value) && (!is_string($value) || preg_match('/^(8|15|30)$/', $value) !== 1)) {
                continue;
            }

            $threshold = (int) $value;
            if (in_array($threshold, self::THRESHOLDS, true)) {
                return $threshold;
            }
        }

        return null;
    }

    public static function isReminderCandidate(array $agent): bool
    {
        if (self::verifiedThreshold($agent) !== null) {
            return true;
        }

        return preg_match('/(?<!\d)(8|15|30)(?!\d)/', (string) ($agent['Name'] ?? '')) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array{org_unit_id: int, course_name: string, course_code: string, agents: array<int, array<string, mixed>>, thresholds: array<string, array<int, array<string, mixed>>>, active_count: int}>
     */
    public static function groupByOrgUnit(array $agents): array
    {
        $groups = [];
        $seen = [];

        foreach ($agents as $agent) {
            if (!is_array($agent)) {
                continue;
            }

            $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            $threshold = self::verifiedThreshold($agent);

            if ($orgUnitId <= 0 || $agentId <= 0) {
                continue;
            }

            $key = $orgUnitId . ':' . $agentId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (!isset($groups[$orgUnitId])) {
                $groups[$orgUnitId] = [
                    'org_unit_id' => $orgUnitId,
                    'course_name' => (string) ($agent['CourseName'] ?? ''),
                    'course_code' => (string) ($agent['CourseCode'] ?? ''),
                    'agents' => [],
                    'thresholds' => ['8' => [], '15' => [], '30' => []],
                    'active_count' => 0,
                ];
            }

            $groups[$orgUnitId]['agents'][] = $agent;
            if ($threshold !== null) {
                $groups[$orgUnitId]['thresholds'][(string) $threshold][] = $agent;
            }
            if ((bool) ($agent['IsEnabled'] ?? false)) {
                $groups[$orgUnitId]['active_count']++;
            }
        }

        ksort($groups, SORT_NUMERIC);

        return $groups;
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array<string, mixed>>
     */
    public static function agentsForOrgUnit(array $agents, int $orgUnitId): array
    {
        if ($orgUnitId <= 0) {
            return [];
        }

        $targets = [];
        $seen = [];

        foreach ($agents as $agent) {
            if (!is_array($agent) || (int) ($agent['OrgUnitId'] ?? 0) !== $orgUnitId) {
                continue;
            }

            $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
            if ($agentId <= 0) {
                continue;
            }

            $key = $orgUnitId . ':' . $agentId;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $targets[] = $agent;
        }

        return $targets;
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array<string, mixed>>
     */
    public static function agentsWithCourseActivityForOrgUnit(array $agents, int $orgUnitId): array
    {
        return array_values(array_filter(
            self::agentsForOrgUnit($agents, $orgUnitId),
            static fn (array $agent): bool => is_array($agent['Condition']['CourseActivity'] ?? null)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array<string, mixed>>
     */
    public static function agentsWithCourseActivityForScope(
        array $agents,
        int $orgUnitId,
        ?string $period,
        ?string $career,
        ?string $parallel
    ): array {
        return array_values(array_filter(
            self::agentsWithCourseActivityForOrgUnit($agents, $orgUnitId),
            static function (array $agent) use ($period, $career, $parallel): bool {
                if ($period !== null && $period !== '' && ($agent['period'] ?? null) !== $period) {
                    return false;
                }
                if (
                    $career !== null
                    && $career !== ''
                    && !CourseCatalogService::careerMatches($agent['career'] ?? null, $career)
                ) {
                    return false;
                }
                if ($parallel !== null && $parallel !== '') {
                    $agentParallel = CourseCatalogService::normalizeParallel(
                        isset($agent['section']) ? (string) $agent['section'] : null
                    );
                    if ($agentParallel === null) {
                        $agentParallel = CourseCatalogService::parseCode(
                            (string) ($agent['CourseCode'] ?? '')
                        )['parallel'];
                    }
                    if ($agentParallel !== $parallel) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }
}
