<?php

declare(strict_types=1);

/**
 * Clasifica los agentes inteligentes de Brightspace según la estructura
 * académica (período, carrera, módulo y paralelo A/B).
 *
 * Brightspace devuelve los agentes de una unidad organizativa contenedora sin
 * indicar a qué sección pertenecen. Este servicio deduce su alcance a partir
 * del nombre del agente y de la estructura real de cursos sincronizada, y
 * expone el resultado listo para pintar en el panel.
 */
final class AgentClassificationService
{
    /** Umbrales de inactividad soportados por el sistema. */
    private const THRESHOLDS = [8, 15, 30];

    /** Palabras clave que permiten deducir el módulo de un agente. */
    private const PARALLEL_KEYWORDS = [
        'A' => ['MODULO A', 'MÓDULO A', 'PARALELO A', 'SECCION A', 'SECCIÓN A', 'GRUPO A'],
        'B' => ['MODULO B', 'MÓDULO B', 'PARALELO B', 'SECCION B', 'SECCIÓN B', 'GRUPO B'],
    ];

    public function __construct(private CourseCatalogService $catalog)
    {
    }

    /**
     * Enriquecer una lista de agentes con su clasificación académica.
     *
     * @param array<int, array<string, mixed>> $agents
     * @return array<int, array<string, mixed>>
     */
    public function classify(array $agents, ?string $period = null, ?string $career = null, ?string $module = null): array
    {
        $classified = [];

        foreach ($agents as $agent) {
            if (!is_array($agent)) {
                continue;
            }

            $classified[] = $this->classifyAgent($agent, $period, $career, $module);
        }

        return $classified;
    }

    /**
     * Añade a un agente las claves de clasificación:
     *   - `Threshold`: 8, 15, 30 o null.
     *   - `Parallels`: módulos detectados (A, B…) o vacío.
     *   - `ScopeLabel`: descripción legible del alcance.
     *
     * @param array<string, mixed> $agent
     * @return array<string, mixed>
     */
    public function classifyAgent(array $agent, ?string $period = null, ?string $career = null, ?string $module = null): array
    {
        $name = (string) ($agent['Name'] ?? '');
        $description = (string) ($agent['Description'] ?? '');
        $haystack = mb_strtoupper($name . ' ' . $description);

        $agent['Threshold'] = $this->detectThreshold($agent, $haystack);
        $agent['Parallels'] = $this->detectParallels($haystack);
        $agent['ScopeLabel'] = $this->scopeLabel(
            $agent,
            $period,
            $career,
            $module
        );

        return $agent;
    }

    /**
     * Umbral de inactividad del agente. Se lee la condición real de Brightspace
     * (`Condition.CourseActivity.Days`), con respaldo en los campos planos y,
     * por último, en el nombre del agente.
     */
    private function detectThreshold(array $agent, string $haystack): ?int
    {
        $conditionDays = (int) ($agent['Condition']['CourseActivity']['Days'] ?? 0);

        if (in_array($conditionDays, self::THRESHOLDS, true)) {
            return $conditionDays;
        }

        $threshold = (int) ($agent['AccessConditionThreshold'] ?? 0);

        if (in_array($threshold, self::THRESHOLDS, true)) {
            return $threshold;
        }

        $loginThreshold = (int) ($agent['LoginConditionThreshold'] ?? 0);

        if (in_array($loginThreshold, self::THRESHOLDS, true)) {
            return $loginThreshold;
        }

        foreach (self::THRESHOLDS as $candidate) {
            if (preg_match('/(?<!\d)' . $candidate . '(?!\d)/', $haystack) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Módulos (A/B) a los que apunta el agente, deducidos del nombre.
     *
     * @return array<int, string>
     */
    private function detectParallels(string $haystack): array
    {
        $parallels = [];

        foreach (self::PARALLEL_KEYWORDS as $section => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $parallels[] = $section;
                    break;
                }
            }
        }

        return $parallels;
    }

    /**
     * Descripción legible del alcance del agente dentro de la selección.
     */
    private function scopeLabel(array $agent, ?string $period, ?string $career, ?string $module): string
    {
        $parts = [];

        if ($period !== null && $period !== '') {
            $parts[] = $period;
        }

        if ($career !== null && $career !== '') {
            $parts[] = CourseCatalogService::careerLabel($career);
        }

        if ($module !== null && $module !== '') {
            $parts[] = $module;
        }

        $parallels = $agent['Parallels'] ?? [];

        if ($parallels !== []) {
            $labels = array_map(
                static fn (string $section): string => CourseCatalogService::parallelLabel($section),
                $parallels
            );
            $parts[] = implode(' + ', $labels);
        } elseif ($parts !== []) {
            $parts[] = 'Todos los módulos';
        }

        if ($parts === []) {
            return 'Alcance institucional';
        }

        return implode(' · ', $parts);
    }

    /**
     * Agrupa agentes ya clasificados por umbral de inactividad.
     *
     * @param array<int, array<string, mixed>> $agents
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function groupByThreshold(array $agents): array
    {
        $groups = [];

        foreach ($agents as $agent) {
            $threshold = $agent['Threshold'] ?? null;
            $key = $threshold === null ? 'otros' : (string) $threshold;
            $groups[$key][] = $agent;
        }

        return $groups;
    }

    /**
     * Resumen numérico de la clasificación para las tarjetas del panel.
     *
     * @param array<int, array<string, mixed>> $agents
     * @return array{total: int, enabled: int, disabled: int, by_parallel: array<string, int>, by_threshold: array<string, int>}
     */
    public function summarize(array $agents): array
    {
        $summary = [
            'total' => 0,
            'enabled' => 0,
            'disabled' => 0,
            'by_parallel' => [],
            'by_threshold' => [],
        ];

        foreach ($agents as $agent) {
            $summary['total']++;

            if ((bool) ($agent['IsEnabled'] ?? false)) {
                $summary['enabled']++;
            } else {
                $summary['disabled']++;
            }

            foreach ($agent['Parallels'] ?? [] as $section) {
                $summary['by_parallel'][$section] = ($summary['by_parallel'][$section] ?? 0) + 1;
            }

            $threshold = $agent['Threshold'] ?? null;
            $key = $threshold === null ? 'otros' : (string) $threshold;
            $summary['by_threshold'][$key] = ($summary['by_threshold'][$key] ?? 0) + 1;
        }

        return $summary;
    }
}