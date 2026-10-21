<?php

declare(strict_types=1);

/**
 * Interpreta la estructura académica de los cursos sincronizados desde Brightspace.
 *
 * Los códigos de unidad organizativa siguen el patrón institucional:
 *
 *   26.S3.DIGMOD4003.PR.B.3956 - ATELIER DE ALTA COSTURA
 *   └─┬──┘└──┬───┘ └┬┘ │  └─┬──┘
 *   período carrera  módulo modalidad paralelo
 *
 * El servicio tolera variantes reales observadas en la base local
 * (por ejemplo `DWEB-1001`, `EALOPTATIVA`, paralelos `A1`/`B1` o el
 * separador duplicado `VR..1221`).
 */
final class CourseCatalogService
{
    public const UNKNOWN_PERIOD = 'SIN_PERIODO';
    public const UNKNOWN_CAREER = 'SIN_CARRERA';
    public const UNKNOWN_MODULE = 'SIN_MODULO';

    /** Etiquetas legibles para las modalidades presentes en los códigos. */
    private const MODALITY_LABELS = [
        'VR' => 'Virtual',
        'PR' => 'Presencial',
        'HB' => 'Híbrida',
        'V' => 'Virtual',
        'P' => 'Presencial',
    ];

    /**
     * Modalidades que en realidad son contenedores académicos, no modalidades
     * de impartición: `PT` agrupa pruebas/proyectos y `CUR` cursos libres.
     * Sus secciones no son módulos de estudiantes, por eso se aíslan.
     */
    private const CONTAINER_MODALITIES = ['PT', 'CUR'];

    /** Módulos en los que se divide una asignatura (A y B). */
    private const MODULE_SECTIONS = ['A', 'B'];

    /** Etiqueta legible de cada módulo. */
    private const SECTION_LABELS = [
        'A' => 'Módulo A',
        'B' => 'Módulo B',
    ];

    /** Canonical career options and the Brightspace career codes they include. */
    private const CAREER_FILTER_GROUPS = [
        'EAL' => ['EAL', 'EALOPTATIVA'],
        'DERMA' => ['DERMA'],
        'CIBER' => ['CIBER'],
        'EMED' => ['EMED'],
        'ENF' => ['ENF', 'INST'],
        'FISIO' => ['FISIO'],
        'GTH' => ['GTH'],
        'CRIM' => ['CRIM'],
        'PUB' => ['PUB'],
        'ADM' => ['ADM'],
        'ADENT' => ['ADENT'],
        'DSOFT' => ['DSOFT', 'DOSFT'],
        'DIGMOD' => ['DIGMOD'],
        'FPS' => ['FPS'],
        'MKT' => ['MKT'],
        'POD' => ['POD'],
    ];

    public function __construct(private PDO $database)
    {
    }

    /**
     * Lista de períodos académicos con su volumen de cursos, matrículas y estudiantes.
     *
     * @return array<int, array{period: string, courses: int, enrollments: int, students: int}>
     */
    public function periods(): array
    {
        $rows = $this->database->query(
            "SELECT COALESCE(NULLIF(c.academic_period, ''), '" . self::UNKNOWN_PERIOD . "') AS period,
                    COUNT(DISTINCT c.id) AS courses,
                    COUNT(DISTINCT e.id) AS enrollments,
                    COUNT(DISTINCT e.student_id) AS students
             FROM courses c
             LEFT JOIN enrollments e ON e.course_id = c.id AND e.active = TRUE
             WHERE c.active = TRUE
             GROUP BY period
             ORDER BY (period = '" . self::UNKNOWN_PERIOD . "'), period DESC"
        )->fetchAll();

        $periods = [];

        foreach ($rows as $row) {
            $period = (string) $row['period'];
            $periods[$period] = [
                'period' => (string) $row['period'],
                'label' => (string) $row['period'],
                'courses' => (int) $row['courses'],
                'enrollments' => (int) $row['enrollments'],
                'students' => (int) $row['students'],
            ];
        }

        $syncedPeriods = $this->database->query(
            'SELECT code, name FROM academic_periods WHERE active = TRUE'
        )->fetchAll();

        foreach ($syncedPeriods as $syncedPeriod) {
            $periodCode = (string) $syncedPeriod['code'];
            $periods[$periodCode] ??= [
                'period' => $periodCode,
                'courses' => 0,
                'enrollments' => 0,
                'students' => 0,
            ];
            $periods[$periodCode]['label'] = (string) ($syncedPeriod['name'] ?: $periodCode);
        }

        uksort($periods, static function (string $left, string $right): int {
            if ($left === self::UNKNOWN_PERIOD) {
                return 1;
            }
            if ($right === self::UNKNOWN_PERIOD) {
                return -1;
            }

            $leftIsAcademic = preg_match('/^\d{2}\.[A-Z]\d*$/i', $left) === 1;
            $rightIsAcademic = preg_match('/^\d{2}\.[A-Z]\d*$/i', $right) === 1;
            if ($leftIsAcademic !== $rightIsAcademic) {
                return $leftIsAcademic ? -1 : 1;
            }

            $specialOrder = [
                'ukITSA' => 0,
                'sem_sample_2018' => 1,
                'Turnitin' => 2,
                'Sandbox' => 3,
                'Especial' => 4,
                '201' => 5,
            ];
            if (isset($specialOrder[$left], $specialOrder[$right])) {
                return $specialOrder[$left] <=> $specialOrder[$right];
            }

            return strcmp($right, $left);
        });

        return array_values($periods);
    }

    /**
     * Carreras detectadas en los códigos de curso, opcionalmente limitadas a un período.
     *
     * @return array<int, array{career: string, courses: int, enrollments: int, students: int}>
     */
    public function careers(?string $period = null): array
    {
        $careers = [];

        foreach ($this->courseRows($period) as $row) {
            $parsed = self::parseCode((string) $row['code']);
            $career = self::careerFilterKey($parsed['career'] ?? null);

            if ($career === null) {
                continue;
            }

            if (!isset($careers[$career])) {
                $careers[$career] = [
                    'career' => $career,
                    'label' => self::careerLabel($career),
                    'courses' => 0,
                    'enrollments' => 0,
                    'students' => 0,
                ];
            }

            $careers[$career]['courses']++;
            $careers[$career]['enrollments'] += (int) $row['enrollments'];
            $careers[$career]['students'] += (int) $row['students'];
        }

        uasort($careers, static fn (array $a, array $b): int => $b['courses'] <=> $a['courses']);

        return array_values($careers);
    }

    /**
     * Módulos (asignaturas) detectados, filtrables por período y carrera.
     *
     * @return array<int, array{module: string, label: string, courses: int, enrollments: int, students: int}>
     */
    public function modules(?string $period = null, ?string $career = null): array
    {
        $modules = [];

        foreach ($this->courseRows($period) as $row) {
            $parsed = self::parseCode((string) $row['code']);

            if ($career !== null && $career !== '' && !self::careerMatches($parsed['career'] ?? null, $career)) {
                continue;
            }

            $key = ($parsed['career'] ?? self::UNKNOWN_CAREER) . '::' . ($parsed['module'] ?? self::UNKNOWN_MODULE);

            if (!isset($modules[$key])) {
                $modules[$key] = [
                    'module' => $parsed['module'] ?? self::UNKNOWN_MODULE,
                    'career' => $parsed['career'] ?? self::UNKNOWN_CAREER,
                    'label' => $parsed['module_label'] ?? (string) $row['name'],
                    'courses' => 0,
                    'enrollments' => 0,
                    'students' => 0,
                ];
            }

            $modules[$key]['courses']++;
            $modules[$key]['enrollments'] += (int) $row['enrollments'];
            $modules[$key]['students'] += (int) $row['students'];
        }

        uasort($modules, static fn (array $a, array $b): int => $b['enrollments'] <=> $a['enrollments']);

        return array_values($modules);
    }

    /**
     * Cursos que pertenecen a la combinación período + carrera + módulo seleccionada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function courses(?string $period = null, ?string $career = null, ?string $module = null): array
    {
        $matches = [];

        foreach ($this->courseRows($period) as $row) {
            $parsed = self::parseCode((string) $row['code']);

            if ($career !== null && $career !== '' && !self::careerMatches($parsed['career'] ?? null, $career)) {
                continue;
            }

            if ($module !== null && $module !== '' && ($parsed['module'] ?? self::UNKNOWN_MODULE) !== $module) {
                continue;
            }

            $row['parsed'] = $parsed;
            $matches[] = $row;
        }

        usort($matches, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $matches;
    }

    /**
     * Resumen consolidado de la selección actual para las tarjetas del panel.
     *
     * @return array{courses: int, enrollments: int, students: int, inactive_8: int, inactive_15: int, inactive_30: int}
     */
    public function summary(?string $period = null, ?string $career = null, ?string $module = null): array
    {
        $summary = [
            'courses' => 0,
            'enrollments' => 0,
            'students' => 0,
            'inactive_8' => 0,
            'inactive_15' => 0,
            'inactive_30' => 0,
        ];

        $courseIds = [];

        foreach ($this->courses($period, $career, $module) as $course) {
            $courseIds[] = (int) $course['id'];
            $summary['courses']++;
            $summary['enrollments'] += (int) $course['enrollments'];
            $summary['students'] += (int) $course['students'];
        }

        if ($courseIds === []) {
            return $summary;
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

        foreach ([8, 15, 30] as $days) {
            $statement = $this->database->prepare(
                "SELECT COUNT(DISTINCT e.student_id)
                 FROM enrollments e
                 INNER JOIN students s ON s.id = e.student_id AND s.active = TRUE
                 WHERE e.active = TRUE
                   AND e.course_id IN ({$placeholders})
                   AND (e.last_activity_at IS NULL
                        OR DATEDIFF(CURDATE(), DATE(e.last_activity_at)) >= ?)"
            );
            $statement->execute([...$courseIds, $days]);
            $summary['inactive_' . $days] = (int) $statement->fetchColumn();
        }

        return $summary;
    }

    /**
     * Convierte un código de unidad organizativa en sus componentes académicos.
     *
     * @return array{period: ?string, career: ?string, career_label: ?string, module: ?string, module_label: ?string, modality: ?string, modality_label: ?string, parallel: ?string}
     */
    public static function parseCode(?string $code): array
    {
        $result = [
            'period' => null,
            'career' => null,
            'career_label' => null,
            'module' => null,
            'module_label' => null,
            'modality' => null,
            'modality_label' => null,
            'parallel' => null,
        ];

        $code = trim((string) $code);

        if ($code === '') {
            return $result;
        }

        // Semestres incluyen períodos S1/S2/S3 y ciclos especiales V/E.
        if (preg_match('/^(\d{2})\.?([A-Z]\d*)(?:[._]|$)/i', $code, $periodMatch)) {
            $result['period'] = strtoupper($periodMatch[1] . '.' . $periodMatch[2]);
        }

        // Inglés puede venir con el campo de carrera vacío: 26.S3..ENG-001.PR.CUR.4376.
        if (preg_match('/^(\d{2}\.S\d+)\.\.([A-Z]+)-?(\d{1,4})\.(VR|PR|HB)\.CUR(?:\.(\d{2,6}))?/i', $code, $match)) {
            $career = strtoupper($match[2]);
            $module = $career . $match[3];
            $modality = strtoupper($match[4]);

            $result['period'] = strtoupper($match[1]);
            $result['career'] = $career;
            $result['career_label'] = self::careerLabel($career);
            $result['module'] = $module;
            $result['module_label'] = $module;
            $result['modality'] = $modality;
            $result['modality_label'] = self::MODALITY_LABELS[$modality] ?? $modality;

            return $result;
        }

        // Carrera + módulo + modalidad + paralelo + NRC.
        //  26.S3.DIGMOD4003.PR.B.3956  -> DIGMOD / DIGMOD4003 / PR / B / 3956
        //  26.S3.DWEB-1001.VR.B1.1801  -> DWEB / DWEB1001 / VR / B / 1801
        //  26.S3.ADENT701.HB.1021      -> ADENT / ADENT701 / HB / sin paralelo / 1021
        //  26.S3.DIGMOD.PT.DIGMOD      -> contenedor PT sin paralelo
        if (preg_match('/^(\d{2}\.S\d)\.([A-Z]+)-?(\d{0,4})((?:\.\.?|\.)[A-Z]{1,3})?(?:\.([A-Z]{1,2}\d{0,3}))?(?:\.(\d{2,6}))?/i', $code, $match)) {
            $career = strtoupper($match[2]);
            $number = $match[3];
            $modality = isset($match[4]) ? strtoupper(ltrim($match[4], '.')) : '';
            $parallelCandidate = isset($match[5]) ? strtoupper($match[5]) : '';

            $result['career'] = $career;
            $result['career_label'] = self::careerLabel($career);
            $result['module'] = $number !== '' ? $career . $number : $career;
            $result['module_label'] = $result['module'];
            $result['modality'] = $modality !== '' ? $modality : null;
            $result['modality_label'] = $modality !== ''
                ? (self::MODALITY_LABELS[$modality] ?? $modality)
                : null;
            $result['parallel'] = self::normalizeParallel($parallelCandidate);

            return $result;
        }

        return $result;
    }

    /**
     * Reduce un candidato de sección a su módulo canónico (A, B…).
     * Devuelve null cuando el valor es un NRC, un curso libre o una palabra
     * completa (PT, CUR, DIGMOD…) en lugar de un módulo real.
     */
    public static function normalizeParallel(?string $candidate): ?string
    {
        $candidate = strtoupper(trim((string) $candidate));

        if ($candidate === '' || ctype_digit($candidate)) {
            return null;
        }

        // Un módulo real tiene una letra y, opcionalmente, hasta dos dígitos
        // (A, B, A1, B2…). Palabras como CUR o DIGMOD no son módulos.
        if (preg_match('/^([A-Z])\d{0,2}$/', $candidate, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Indica si el curso pertenece a un contenedor (PT / CUR) y no a una sección
     * con estudiantes matriculados.
     */
    public static function isContainer(array $parsed): bool
    {
        $modality = strtoupper((string) ($parsed['modality'] ?? ''));

        return $modality !== '' && in_array($modality, self::CONTAINER_MODALITIES, true);
    }

    /** Etiqueta legible de un módulo (A -> "Módulo A"). */
    public static function parallelLabel(string $parallel): string
    {
        return self::SECTION_LABELS[strtoupper($parallel)] ?? ('Módulo ' . strtoupper($parallel));
    }

    /**
     * Módulos (A/B) presentes en la selección, con su volumen.
     *
     * @return array<int, array{parallel: string, label: string, courses: int, enrollments: int, students: int}>
     */
    public function parallels(?string $period = null, ?string $career = null, ?string $module = null): array
    {
        $parallels = [];

        foreach ($this->courses($period, $career, $module) as $course) {
            $parsed = $course['parsed'];

            if (self::isContainer($parsed)) {
                continue;
            }

            $parallel = $parsed['parallel'] ?? null;

            if ($parallel === null || !in_array($parallel, self::MODULE_SECTIONS, true)) {
                continue;
            }

            if (!isset($parallels[$parallel])) {
                $parallels[$parallel] = [
                    'parallel' => $parallel,
                    'label' => self::parallelLabel($parallel),
                    'courses' => 0,
                    'enrollments' => 0,
                    'students' => 0,
                ];
            }

            $parallels[$parallel]['courses']++;
            $parallels[$parallel]['enrollments'] += (int) $course['enrollments'];
            $parallels[$parallel]['students'] += (int) $course['students'];
        }

        ksort($parallels);

        return array_values($parallels);
    }

    /**
     * Agrupa los cursos de la selección por módulo A/B, dejando aparte los
     * cursos sin módulo (contenedores PT/CUR o códigos sin sección).
     *
     * @return array{sections: array<string, array{parallel: string, label: string, courses: array<int, array<string, mixed>>}>, unassigned: array<int, array<string, mixed>>}
     */
    public function coursesByParallel(?string $period = null, ?string $career = null, ?string $module = null): array
    {
        $sections = [];
        $unassigned = [];

        foreach (self::MODULE_SECTIONS as $section) {
            $sections[$section] = [
                'parallel' => $section,
                'label' => self::parallelLabel($section),
                'courses' => [],
            ];
        }

        foreach ($this->courses($period, $career, $module) as $course) {
            $parsed = $course['parsed'];
            $parallel = self::isContainer($parsed) ? null : ($parsed['parallel'] ?? null);

            if ($parallel !== null && isset($sections[$parallel])) {
                $sections[$parallel]['courses'][] = $course;
                continue;
            }

            $unassigned[] = $course;
        }

        return ['sections' => $sections, 'unassigned' => $unassigned];
    }

    /**
     * Nombre legible de una carrera a partir de su abreviatura institucional.
     */
    public static function careerFilterKey(?string $career): ?string
    {
        $career = strtoupper(trim((string) $career));

        foreach (self::CAREER_FILTER_GROUPS as $key => $codes) {
            if (in_array($career, $codes, true)) {
                return $key;
            }
        }

        return null;
    }

    /** Indica si un código de carrera pertenece a la opción seleccionada. */
    public static function careerMatches(?string $courseCareer, string $selectedCareer): bool
    {
        $courseCareer = strtoupper(trim((string) $courseCareer));
        $selectedCareer = strtoupper(trim($selectedCareer));
        $codes = self::CAREER_FILTER_GROUPS[$selectedCareer] ?? [$selectedCareer];

        return in_array($courseCareer, $codes, true);
    }

    public static function careerLabel(string $career): string
    {
        return self::CAREER_LABELS[strtoupper($career)] ?? strtoupper($career);
    }

    /** Abreviaturas institucionales observadas en los códigos de Brightspace. */
    private const CAREER_LABELS = [
        'ADENT' => 'Tecnología Superior en Aparatología Dental',
        'ADM' => 'Tecnología Superior en Administración',
        'BIGD' => 'Big Data e Inteligencia de Negocios',
        'CIBER' => 'Tecnología Superior en Ciberseguridad',
        'COM' => 'Comunicación Empresarial y RR.PP.',
        'COMEX' => 'Comercio Exterior',
        'CRIM' => 'Criminalística',
        'DEI' => 'Desarrollo Infantil Integral',
        'DERMA' => 'Tecnología Superior en Dermatocosmiatría',
        'DIGMOD' => 'Tecnología Superior en Diseño y Gestión de Modas',
        'DOSFT' => 'Tecnología Superior en Desarrollo de Software y Programación',
        'DSOFT' => 'Tecnología Superior en Desarrollo de Software y Programación',
        'DWEB' => 'Desarrollo Web y Apps',
        'EMED' => 'Emergencias Médicas',
        'EAL' => 'Electivas Académicas y Libres',
        'EALOPTATIVA' => 'Electivas Académicas y Libres',
        'ECISA' => 'ECISA',
        'ECODI' => 'Escuela de Comunicación y Diseño',
        'ECOME' => 'Escuela de Comunicación y Estrategia',
        'EDUCON' => 'Escuela de Educación Continua',
        'EG' => 'Escuela General',
        'EGEDU' => 'Escuela de Gestión Educativa',
        'ENF' => 'Enfermería',
        'ENG' => 'Inglés',
        'ESNE' => 'Escuela de Negocios',
        'ETEI' => 'Escuela de Tecnologías e Innovación',
        'FISIO' => 'Fisioterapia',
        'FPS' => 'Tecnología Superior en Finanzas Populares y Solidarias',
        'GTH' => 'Gestión del Talento Humano',
        'INV' => 'Investigación',
        'MKT' => 'Tecnología Superior en Marketing Digital',
        'POD' => 'Tecnología Superior en Podología',
        'PRASEM' => 'Práctica Semestral',
        'PT' => 'Prótesis Dental',
        'PUB' => 'Publicidad',
        'SECOM' => 'Secretariado Comercial',
    ];

    /**
     * Cursos activos con sus conteos de matrículas y estudiantes, opcionalmente por período.
     *
     * @return array<int, array<string, mixed>>
     */
    private function courseRows(?string $period = null): array
    {
        $sql = "SELECT c.id, c.brightspace_org_unit_id, c.code, c.name, c.academic_period,
                       COUNT(DISTINCT e.id) AS enrollments,
                       COUNT(DISTINCT e.student_id) AS students
                FROM courses c
                LEFT JOIN enrollments e ON e.course_id = c.id AND e.active = TRUE
                WHERE c.active = TRUE";

        $parameters = [];

        if ($period !== null && $period !== '') {
            if ($period === self::UNKNOWN_PERIOD) {
                $sql .= " AND (c.academic_period IS NULL OR c.academic_period = '')";
            } else {
                $sql .= ' AND c.academic_period = :period';
                $parameters['period'] = $period;
            }
        }

        $sql .= ' GROUP BY c.id, c.brightspace_org_unit_id, c.code, c.name, c.academic_period';

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}