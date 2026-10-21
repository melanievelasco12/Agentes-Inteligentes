<?php

declare(strict_types=1);

// Este controlador carga los servicios, atiende formularios y finalmente renderiza el panel.
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/auth.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthClient.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';
require_once PROJECT_ROOT . '/integrations/brightspace/BrightspaceClient.php';
require_once PROJECT_ROOT . '/app/Services/BrightspaceAgentService.php';
require_once PROJECT_ROOT . '/app/Services/AuditService.php';
require_once PROJECT_ROOT . '/app/Services/CourseCatalogService.php';
require_once PROJECT_ROOT . '/app/Services/AgentDiscoveryService.php';
require_once PROJECT_ROOT . '/app/Services/ReminderClassService.php';
require_once PROJECT_ROOT . '/app/Services/SharedEmailDraftService.php';
require_once PROJECT_ROOT . '/app/Services/CareerPeriodEmailDraftService.php';
require_once PROJECT_ROOT . '/app/Services/ClassEmailRecipientService.php';

// Las solicitudes JSON necesitan que la autenticacion devuelva errores JSON en vez de redirigir.
$jsonApiRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
require_authenticated($jsonApiRequest);

$normalizeQueryValue = static function (mixed $value, int $maxLength = 64): string {
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
    if ($normalized === null) {
        return '';
    }

    return mb_substr($normalized, 0, $maxLength, 'UTF-8');
};

// Servicios y estado compartido por los distintos formularios del dashboard.
$database = Database::connection();
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$message = null;
$messageType = 'status';
$agentService = null;
$audit = new AuditService($database);
$careerPeriodEmailDraftService = new CareerPeriodEmailDraftService($database);
$sharedEmailConfigError = null;
$agentOrgUnitId = (int) getenv('BRIGHTSPACE_AGENT_ORG_UNIT_ID');
$brightspaceClient = null;

// La conexion se crea solo cuando hay una unidad organizativa configurada para Brightspace.
if ($agentOrgUnitId > 0) {
    try {
        $brightspaceClient = new BrightspaceClient(
            new OAuthClient(),
            new OAuthTokenStore($database, (string) getenv('APP_ENCRYPTION_KEY')),
            (string) getenv('BRIGHTSPACE_BASE_URL'),
            (string) getenv('BRIGHTSPACE_API_VERSION')
        );
        $agentService = new BrightspaceAgentService($brightspaceClient, $agentOrgUnitId);
    } catch (Throwable $exception) {
        AppLogger::exception($exception, ['context' => 'brightspace_connection']);
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $message = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    }
}

$catalog = new CourseCatalogService($database);
$periods = $catalog->periods();
$requestedPeriod = $normalizeQueryValue($_GET['period'] ?? '', 32);
$availablePeriods = array_column($periods, 'period');
$selectedPeriod = in_array($requestedPeriod, $availablePeriods, true) ? $requestedPeriod : '';
$careers = $catalog->careers();
$requestedCareer = $normalizeQueryValue($_GET['career'] ?? '', 64);
$availableCareers = array_column($careers, 'career');
$requestedCareerKey = CourseCatalogService::careerFilterKey($requestedCareer);
$selectedCareer = in_array($requestedCareer, $availableCareers, true)
    ? $requestedCareer
    : (in_array($requestedCareerKey, $availableCareers, true) ? $requestedCareerKey : '');
$parallelOptions = $catalog->parallels(
    $selectedPeriod !== '' ? $selectedPeriod : null,
    $selectedCareer !== '' ? $selectedCareer : null
);
$requestedParallel = strtoupper($normalizeQueryValue($_GET['parallel'] ?? '', 8));
foreach (['A', 'B'] as $module) {
    if (!in_array($module, array_column($parallelOptions, 'parallel'), true)) {
        $parallelOptions[] = [
            'parallel' => $module,
            'label' => CourseCatalogService::parallelLabel($module),
            'courses' => 0,
            'enrollments' => 0,
            'students' => 0,
        ];
    }
}
usort($parallelOptions, static fn (array $left, array $right): int => strcmp($left['parallel'], $right['parallel']));
$selectedParallel = in_array($requestedParallel, ['A', 'B'], true) ? $requestedParallel : '';
$emailScopePeriod = $selectedPeriod;
$emailScopeCareer = $selectedCareer;
$sharedEmailScopedDraft = null;
// El borrador del mensaje se carga y guarda por carrera y periodo.
if ($emailScopePeriod !== '' && $emailScopeCareer !== '') {
    try {
        $sharedEmailScopedDraft = $careerPeriodEmailDraftService->get($emailScopePeriod, $emailScopeCareer);
    } catch (Throwable $exception) {
        AppLogger::exception($exception, ['context' => 'shared_email_scope_draft_load']);
        $sharedEmailConfigError = 'No se pudo cargar el mensaje para la carrera y período seleccionados. Aplica la migración 005_scope_shared_email_drafts_by_career_period.sql.';
    }
}
// Guarda el borrador seleccionado localmente; esta accion no modifica agentes en Brightspace.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shared_email_draft_action'])) {
    $submittedPeriod = $_POST['shared_email_scope_period'] ?? null;
    $submittedCareer = $_POST['shared_email_scope_career'] ?? null;
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $message = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } elseif (
        !is_string($submittedPeriod)
        || !is_string($submittedCareer)
        || trim($submittedPeriod) === ''
        || trim($submittedCareer) === ''
        || !hash_equals($emailScopePeriod, $submittedPeriod)
        || !hash_equals($emailScopeCareer, $submittedCareer)
    ) {
        $message = 'Selecciona una carrera y un período para guardar el mensaje.';
        $messageType = 'error';
    } else {
        $asDraftString = static fn (mixed $value): string => is_string($value) ? $value : '';
        $sharedEmailScopedDraftInput = [
            'body' => $asDraftString($_POST['shared_email_body'] ?? ''),
        ];

        try {
            $changed = $careerPeriodEmailDraftService->saveBody(
                $emailScopePeriod,
                $emailScopeCareer,
                $sharedEmailScopedDraftInput['body'],
                (string) ($_SESSION['admin_username'] ?? 'system')
            );
            try {
                $audit->record('shared_email_draft.message_saved', ['changed' => $changed]);
                $message = $changed
                    ? 'El mensaje compartido de la carrera y período se guardó.'
                    : 'El mensaje compartido de la carrera y período no tenía cambios.';
                $messageType = 'status';
            } catch (Throwable $auditException) {
                AppLogger::exception($auditException, ['context' => 'shared_email_draft_audit']);
                $message = 'El mensaje compartido se guardó, pero no se pudo registrar la auditoría.';
                $messageType = 'error';
            }
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            $messageType = 'error';
        } catch (Throwable $exception) {
            AppLogger::exception($exception, ['context' => 'shared_email_draft_save']);
            $message = 'No se pudo guardar el mensaje compartido; los valores anteriores se conservan.';
            $messageType = 'error';
        }
    }
}
$agentParallel = static function (array $agent): ?string {
    $parallel = CourseCatalogService::normalizeParallel(
        isset($agent['section']) ? (string) $agent['section'] : null
    );
    if ($parallel !== null) {
        return $parallel;
    }

    $courseCode = isset($agent['CourseCode']) ? (string) $agent['CourseCode'] : '';
    return CourseCatalogService::parseCode($courseCode)['parallel'];
};
$dashboardStyles = <<<'TESA_DASHBOARD_CSS'
    <style>
        /* Tokens visuales y estilos especificos del dashboard. */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        :root {
            --bg: #f5f2f8;
            --panel: #ffffff;
            --panel-soft: #f8f4fb;
            --line: #e7dff0;
            --primary: #43275f;
            --primary-strong: #2d1a3d;
            --accent: #d4a71d;
            --text: #211b2c;
            --text-soft: #625c6a;
            --ok: #1d7a4d;
            --ok-soft: #e3f7ed;
            --danger: #9a2d41;
            --danger-soft: #fdecef;
            --shadow: 0 8px 20px rgba(57, 34, 77, 0.08);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: linear-gradient(180deg, #f3eef7 0%, #f7f5fa 100%);
            color: var(--text);
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
        }
        a { color: inherit; }
        h1, h2, h3, p { margin-top: 0; }
        h1, h2, h3 { color: var(--primary); }
        .topbar {
            max-width: 1180px;
            margin: 0 auto;
            padding: 18px 20px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-mark {
            width: 64px;
            height: 64px;
            flex: 0 0 64px;
            display: block;
            object-fit: contain;
        }
        .brand strong { display: block; font-size: 1.2rem; letter-spacing: .02em; }
        .brand span { display: block; font-size: 0.7rem; letter-spacing: .09em; text-transform: uppercase; color: #fff; }
        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: var(--control-radius);
            padding: 10px 14px;
            text-decoration: none;
            background: var(--primary);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: transform .15s ease, opacity .15s ease;
        }
        .btn:hover, .btn:focus-visible { transform: translateY(-1px); }
        .btn.secondary { background: var(--panel-soft); color: var(--primary); border: 1px solid var(--line); }
        .header-actions .btn {
            min-height: 42px;
            padding: 10px 16px;
            border: 1px solid transparent;
            border-radius: var(--control-radius);
            font-size: .86rem;
            box-shadow: 0 3px 10px rgba(25, 16, 36, .2);
        }
        .header-actions .btn.ghost {
            background: #fff;
            border-color: #fff;
            color: var(--primary-strong);
        }
        .header-actions .btn.ghost:hover { background: #f3edf8; border-color: #f3edf8; }
        .header-actions .btn.logout {
            background: transparent;
            border-color: rgba(255, 255, 255, .82);
            color: #fff;
        }
        .header-actions .btn.logout:hover { background: #fff; color: var(--danger); }
        .page-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 8px 20px 60px;
        }
        .hero {
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            border-radius: var(--card-radius);
            padding: 26px 28px 22px;
            margin-bottom: 24px;
            color: #fff;
            box-shadow: var(--shadow);
        }
        .hero h1 {
            color: #fff;
            margin: 0 0 10px;
            font-size: clamp(2rem, 2.5vw, 3rem);
            line-height: 1.12;
        }
        .hero p {
            margin: 0;
            max-width: 760px;
            color: rgba(255,255,255,0.82);
            line-height: 1.55;
        }
        .status {
            margin: 22px 0 18px;
            padding: 12px 14px;
            border-left: 4px solid var(--primary);
            border-radius: var(--control-radius);
            background: var(--panel-soft);
            color: var(--primary-strong);
            font-size: 0.92rem;
        }
        .status.error {
            border-left-color: var(--danger);
            background: var(--danger-soft);
            color: var(--danger);
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
        }
        .panel { padding: 20px; margin-bottom: 22px; }
        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .panel-head h2, .panel-head h3 { margin: 0; font-size: 1.18rem; }
        .panel-head span { color: var(--text-soft); font-size: 0.78rem; }
        .filters {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            align-items: end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .filter-group label {
            margin: 0;
            color: var(--text);
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
        }
        .filter-control {
            width: 100%;
            min-width: 0;
            min-height: 46px;
            padding: 10px 12px;
            border: 1px solid #bdc3c7;
            border-radius: var(--control-radius);
            background: #fff;
            color: var(--text);
            font: inherit;
            font-size: 15px;
        }
        .filter-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, .14);
        }
        .filter-actions { display: flex; justify-content: flex-end; grid-column: 1 / -1; }
        .filter-submit {
            margin: 0;
            padding: 12px 22px;
            border-radius: var(--control-radius);
            background: var(--forest);
        }
        .filter-submit:hover, .filter-submit:focus-visible { background: #3a2055; }
        .filter-submit:focus-visible { outline: 3px solid rgba(75, 42, 105, .22); outline-offset: 2px; }
        .filter-hint { grid-column: 1 / -1; margin: -6px 0 0; color: var(--text-soft); font-size: .78rem; }
        @media (max-width: 820px) {
            .filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 540px) {
            .filters { grid-template-columns: 1fr; }
            .filter-actions { justify-content: stretch; }
            .filter-submit { width: 100%; }
        }
        .quick-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .quick-actions form { margin: 0; }
        .quick-actions button { margin: 0; }
        .quick-actions form[data-action-label="activar"] button { background: #16803c; }
        .quick-actions form[data-action-label="activar"] button:hover,
        .quick-actions form[data-action-label="activar"] button:focus-visible { background: #126b32; }
        .quick-actions .secondary { background: var(--panel-soft); color: var(--primary); border: 1px solid var(--line); }
        .quick-actions .inventory-refresh-button { background: #2563eb; }
        .quick-actions .inventory-refresh-button:hover,
        .quick-actions .inventory-refresh-button:focus-visible { background: #1d4ed8; }
        .quick-actions .inventory-refresh-button:disabled { opacity: .6; cursor: wait; }
        #bulk-progress { margin-top: 18px; }

        .workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: var(--card-radius);
            background: #fff;
        }
        table {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--line);
            font-size: .88rem;
        }
        th {
            background: var(--panel-soft);
            color: var(--primary);
            letter-spacing: .04em;
            text-transform: uppercase;
            font-size: .72rem;
        }
        tr:last-child td { border-bottom: none; }
        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: .72rem;
            font-weight: 700;
        }
        .pill.on { background: var(--ok-soft); color: var(--ok); }
        .pill.off { background: #f2edf5; color: var(--text-soft); }
        .agent-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .agent-actions form { margin: 0; }
        .agent-actions button {
            margin: 0;
            padding: 8px 10px;
            font-size: .74rem;
        }
        .agent-actions button.secondary { background: var(--panel-soft); border: 1px solid var(--line); color: var(--primary); }

        .muted { color: var(--text-soft); }
        .stack { display: grid; gap: 12px; }

        @media (max-width: 820px) {
            .workspace { grid-template-columns: 1fr; }
            .hero { padding: 22px 20px; }
        }
        @media (max-width: 540px) {
            .page-shell { padding-left: 14px; padding-right: 14px; }
            .panel { padding-left: 14px; padding-right: 14px; }
            .quick-actions { display: grid; }
            .quick-actions button { width: 100%; }
            .panel-head { flex-direction: column; align-items: flex-start; }
        }

        :root {
            --shadow: 0 12px 28px rgba(45, 26, 61, .1);
            --card-shadow: 0 2px 8px rgba(45, 26, 61, .045), 0 1px 2px rgba(45, 26, 61, .035);
        }
        body { background: linear-gradient(180deg, #f5f1f8 0, var(--ivory) 400px); }
        header { border-bottom: 3px solid var(--coral); box-shadow: 0 5px 18px rgba(45, 26, 61, .12); }
        .topbar { max-width: 1280px; padding-top: 20px; padding-bottom: 20px; }
        .page-shell { max-width: 1280px; padding-top: 26px; padding-bottom: 64px; }
        .hero {
            border: 1px solid rgba(255, 255, 255, .12);
            border-left: 4px solid var(--coral);
            border-radius: 14px;
            padding: 30px 32px 27px;
            box-shadow: 0 14px 28px rgba(45, 26, 61, .13);
        }
        .hero h1 { font-weight: 700; letter-spacing: -.035em; }
        .status { border: 1px solid var(--line); border-left: 4px solid var(--forest); line-height: 1.5; }
        .status.error { border-left-color: #9c2f45; }
        .panel { padding-top: 23px; padding-bottom: 23px; margin-bottom: 24px; }
        .panel-head { padding-bottom: 14px; border-bottom: 1px solid var(--line); }
        .panel-head h2, .panel-head h3 { font-weight: 700; letter-spacing: -.015em; }
        .filter-control {
            border-color: #cfc5d8;
            transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease;
        }
        .filter-control:hover { border-color: #b9a9c9; background-color: #fdfbff; }
        .filter-control:focus {
            border-color: var(--forest);
            box-shadow: 0 0 0 3px rgba(75, 42, 105, .14);
        }
        .reminder-class {
            border-color: #e2d9e9;
            box-shadow: var(--card-shadow);
            transition: border-color .16s ease, box-shadow .16s ease;
        }
        .reminder-class:hover { border-color: #c9b8dd; box-shadow: var(--shadow); }
        .reminder-class-head h3 { line-height: 1.35; }
        .schedule-form { border-color: #e2d9e9; background: #fcfaff; box-shadow: none; }
        .agent-card { box-shadow: var(--card-shadow); }
        .table-wrap { box-shadow: var(--card-shadow); }
        @media (max-width: 820px) {
            .hero { padding: 22px 20px; }
        }
        @media (max-width: 540px) {
            .page-shell { padding-left: 14px; padding-right: 14px; }
            .panel { padding-left: 14px; padding-right: 14px; }
            .hero { padding: 22px 18px; }
        }
    </style>
TESA_DASHBOARD_CSS;
$discoveredAgents = [];
$allDiscoveredAgents = [];
$inventoryStatus = null;
$discovery = null;

// Las acciones dependen de una cache de inventario; su refresco se procesa en lotes.
if ($brightspaceClient !== null) {
    /** @var AgentDiscoveryService $discovery */
    $discovery = new AgentDiscoveryService($brightspaceClient, $database, $catalog);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inventory_refresh_action'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $respondInventoryRefresh = static function (int $status, array $payload): void {
            http_response_code($status);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        };

        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            $respondInventoryRefresh(403, ['success' => false, 'error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.']);
        }

        try {
            $refreshAction = (string) $_POST['inventory_refresh_action'];
            if (!in_array($refreshAction, ['start', 'continue'], true)) {
                $respondInventoryRefresh(400, ['success' => false, 'error' => 'La acción de actualización no es válida.']);
                exit;
            }

            if ($refreshAction === 'start') {
                $result = $discovery->startInventoryRefresh();
            } else {
                $result = $discovery->processInventoryRefreshBatch((string) ($_POST['refresh_id'] ?? ''));
            }

            $respondInventoryRefresh(200, ['success' => true] + $result);
        } catch (Throwable $exception) {
            AppLogger::exception($exception, ['context' => 'brightspace_inventory_refresh']);
            $respondInventoryRefresh(500, ['success' => false, 'error' => $exception->getMessage()]);
        }
    }

    try {
        $allDiscoveredAgents = $discovery->cachedAgentsForPeriod();
        $discoveredAgents = $discovery->cachedAgentsForPeriod($selectedPeriod !== '' ? $selectedPeriod : null);
        $inventoryStatus = $discovery->inventorySummary();

        // Acota por carrera y modulo cuando el usuario los ha seleccionado.
        if ($selectedCareer !== '' || $selectedParallel !== '') {
            $discoveredAgents = array_values(array_filter(
                $discoveredAgents,
            static function (array $agent) use ($selectedCareer, $selectedParallel, $agentParallel): bool {
                    if ($selectedCareer !== '' && !CourseCatalogService::careerMatches($agent['career'] ?? null, $selectedCareer)) {
                        return false;
                    }

                    if ($selectedParallel !== '' && $agentParallel($agent) !== $selectedParallel) {
                        return false;
                    }

                    return true;
                }
            ));
        }

    } catch (Throwable $exception) {
        if ($message === null) {
            $message = 'No se pudieron descubrir todos los agentes: ' . $exception->getMessage();
            $messageType = 'error';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inventory_refresh_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Brightspace no está disponible para actualizar el inventario.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$emailScopeComplete = $emailScopePeriod !== ''
    && $emailScopeCareer !== '';
$sharedEmailCareerAgents = $emailScopePeriod !== '' && $emailScopeCareer !== ''
    ? BrightspaceAgentService::emailActionAgentsForCareerPeriod(
        $allDiscoveredAgents,
        $emailScopeCareer,
        $emailScopePeriod
    )
    : [];
$sharedEmailCareerTargets = BrightspaceAgentService::agentsWithEmailAction($sharedEmailCareerAgents);
$pendingCareerEmailRun = $_SESSION['bulk_shared_email_run'] ?? null;
$unsupportedCareerEmailRun = is_array($pendingCareerEmailRun)
    && !is_array($pendingCareerEmailRun['done_result'] ?? null)
    && ($pendingCareerEmailRun['kind'] ?? null) !== 'career_recipient';
$pendingCareerEmailRunId = is_array($pendingCareerEmailRun)
    && !is_array($pendingCareerEmailRun['done_result'] ?? null)
    && ($pendingCareerEmailRun['kind'] ?? null) === 'career_recipient'
    && is_string($pendingCareerEmailRun['run_id'] ?? null)
    && (string) ($pendingCareerEmailRun['period'] ?? '') === $emailScopePeriod
    && (string) ($pendingCareerEmailRun['career'] ?? '') === $emailScopeCareer
    ? $pendingCareerEmailRun['run_id']
    : '';
$pendingCareerEmailRunOtherScope = is_array($pendingCareerEmailRun)
    && !is_array($pendingCareerEmailRun['done_result'] ?? null)
    && ($pendingCareerEmailRun['kind'] ?? null) === 'career_recipient'
    && $pendingCareerEmailRunId === '';
$activePendingCareerEmailRun = is_array($pendingCareerEmailRun)
    && !is_array($pendingCareerEmailRun['done_result'] ?? null);
$pendingSharedMessageRun = $_SESSION['bulk_shared_message_run'] ?? null;
$activePendingSharedMessageRun = is_array($pendingSharedMessageRun)
    && !is_array($pendingSharedMessageRun['done_result'] ?? null);
$pendingSharedMessageRunId = is_array($pendingSharedMessageRun)
    && ($pendingSharedMessageRun['kind'] ?? null) === 'message'
    && !is_array($pendingSharedMessageRun['done_result'] ?? null)
    && is_string($pendingSharedMessageRun['run_id'] ?? null)
    && (string) ($pendingSharedMessageRun['period'] ?? '') === $emailScopePeriod
    && (string) ($pendingSharedMessageRun['career'] ?? '') === $emailScopeCareer
    ? $pendingSharedMessageRun['run_id']
    : '';
$pendingSharedMessageFailures = $pendingSharedMessageRunId !== ''
    ? (int) ($pendingSharedMessageRun['failed'] ?? 0)
    : 0;
$pendingSharedMessageRunOtherScope = is_array($pendingSharedMessageRun)
    && !is_array($pendingSharedMessageRun['done_result'] ?? null)
    && ($pendingSharedMessageRun['kind'] ?? null) === 'message'
    && $pendingSharedMessageRunId === '';
$sharedEmailBodyValue = '';
if ($sharedEmailScopedDraft !== null) {
    $sharedEmailBodyValue = (string) ($sharedEmailScopedDraft['body'] ?? '');
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $emailScopeComplete) {
    $emailScopeAgents = array_values(array_filter(
        $allDiscoveredAgents,
        static fn (array $agent): bool => (string) ($agent['period'] ?? '') === $emailScopePeriod
            && CourseCatalogService::careerMatches(
                isset($agent['career']) ? (string) $agent['career'] : null,
                $emailScopeCareer
            )
    ));
    $defaultEmailPair = SharedEmailDraftService::mostCommonEmailActionPair($emailScopeAgents);
    $sharedEmailBodyValue = (string) ($defaultEmailPair['body'] ?? '');
}
if (isset($sharedEmailScopedDraftInput) && is_array($sharedEmailScopedDraftInput)) {
    $sharedEmailBodyValue = (string) ($sharedEmailScopedDraftInput['body'] ?? $sharedEmailBodyValue);
}

// Aplica el CC editado a todos los agentes de correo de carrera y período.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shared_email_apply_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $respondEmailApply = static function (int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    $csrfValue = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfValue) || !hash_equals($csrfToken, $csrfValue)) {
        $respondEmailApply(403, ['success' => false, 'error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.']);
    }

    $applyAction = $_POST['shared_email_apply_action'];
    if (!is_string($applyAction) || !in_array($applyAction, ['start', 'continue', 'restart'], true)) {
        $respondEmailApply(400, ['success' => false, 'error' => 'La acción de actualización del correo no es válida.']);
    }

    if (
        $agentService === null
        || $discovery === null
        || $inventoryStatus === null
        || $inventoryStatus['complete'] !== true
        || $inventoryStatus['fresh'] !== true
    ) {
        $respondEmailApply(409, [
            'success' => false,
            'error' => 'Se necesita un inventario completo y actualizado y una conexión a Brightspace para continuar.',
        ]);
    }

    if ($applyAction === 'start') {
        // Valida el alcance antes de guardar los objetivos en sesión.
        $submittedPeriod = $_POST['shared_email_scope_period'] ?? null;
        $submittedCareer = $_POST['shared_email_scope_career'] ?? null;
        if (
            !$emailScopeComplete
            || !is_string($submittedPeriod)
            || !hash_equals($emailScopePeriod, $submittedPeriod)
            || !is_string($submittedCareer)
            || !hash_equals($emailScopeCareer, $submittedCareer)
        ) {
            $respondEmailApply(422, [
                'success' => false,
                'error' => 'La carrera y el período enviados no coinciden con los filtros seleccionados.',
            ]);
        }
        $activeEmailRun = $_SESSION['bulk_shared_email_run'] ?? null;
        if (is_array($activeEmailRun) && !is_array($activeEmailRun['done_result'] ?? null)) {
            if (($activeEmailRun['kind'] ?? null) !== 'career_recipient') {
                $respondEmailApply(409, [
                    'success' => false,
                    'legacy_run_unsupported' => true,
                    'error' => 'Existe una actualización anterior por curso que esta versión no puede reanudar con seguridad. Revisa Brightspace, cierra sesión e inicia una sesión nueva antes de continuar.',
                ]);
            }
            $respondEmailApply(409, [
                'success' => false,
                'error' => 'Hay una actualización pendiente para otra carrera o período. Reanúdala antes de iniciar una nueva.',
            ]);
        }
        $activeMessageRun = $_SESSION['bulk_shared_message_run'] ?? null;
        if (is_array($activeMessageRun) && !is_array($activeMessageRun['done_result'] ?? null)) {
            $respondEmailApply(409, [
                'success' => false,
                'error' => 'Hay una actualización del mensaje compartido pendiente. Reanúdala antes de aplicar CC.',
            ]);
        }

        try {
            $recipientInput = $_POST['career_email_cc'] ?? null;
            if (!is_string($recipientInput)) {
                throw new InvalidArgumentException('El campo CC no es válido.');
            }
            $recipient = trim($recipientInput) === '' ? '' : EmailRecipientList::normalize($recipientInput);
        } catch (InvalidArgumentException $exception) {
            $respondEmailApply(422, ['success' => false, 'error' => $exception->getMessage()]);
        }

        try {
            $audit->record('brightspace_agent.career_email_cc_apply_started', [
                'career' => $emailScopeCareer,
                'period' => $emailScopePeriod,
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, ['context' => 'career_email_cc_apply_audit']);
        }

        $targets = $sharedEmailCareerTargets;
        if ($targets === []) {
            $respondEmailApply(409, [
                'success' => false,
                'error' => 'No hay agentes con acción de correo en esta carrera y período.',
            ]);
        }
        $runId = bin2hex(random_bytes(16));
        $_SESSION['bulk_shared_email_run'] = [
            'kind' => 'career_recipient',
            'run_id' => $runId,
            'period' => $emailScopePeriod,
            'career' => $emailScopeCareer,
            'recipient' => $recipient,
            'targets' => $targets,
            'next_offset' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        $respondEmailApply(200, [
            'success' => true,
            'run_id' => $runId,
            'period' => $emailScopePeriod,
            'career' => $emailScopeCareer,
            'total' => count($targets),
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'done' => false,
        ]);
    }

    $run = $_SESSION['bulk_shared_email_run'] ?? null;
    $runId = $_POST['run_id'] ?? null;
    if (
        !is_array($run)
        || ($run['kind'] ?? null) !== 'career_recipient'
        || !is_string($runId)
        || !is_string($run['run_id'] ?? null)
        || !hash_equals($run['run_id'], $runId)
        || (string) ($run['period'] ?? '') !== $emailScopePeriod
        || (string) ($run['career'] ?? '') !== $emailScopeCareer
    ) {
        $respondEmailApply(409, [
            'success' => false,
            'run_unavailable' => true,
            'error' => 'El avance corresponde a otra carrera o período, o ya no está disponible. Vuelve a sus filtros originales antes de reanudar.',
        ]);
    }
    if (is_array($run['done_result'] ?? null)) {
        $respondEmailApply(200, $run['done_result']);
    }
    $runTargets = is_array($run['targets'] ?? null) ? $run['targets'] : [];
    $currentTargetKeys = [];
    foreach ($sharedEmailCareerTargets as $currentTarget) {
        $currentTargetKeys[(int) $currentTarget['OrgUnitId'] . ':' . (int) $currentTarget['AgentId']] = true;
    }
    $runIsCareerScoped = $runTargets !== [] && $sharedEmailCareerTargets !== [];
    foreach ($runTargets as $runTarget) {
        $runTargetKey = is_array($runTarget)
            ? (int) ($runTarget['OrgUnitId'] ?? 0) . ':' . (int) ($runTarget['AgentId'] ?? 0)
            : '';
        if (
            !is_array($runTarget)
            || !isset($currentTargetKeys[$runTargetKey])
        ) {
            $runIsCareerScoped = false;
            break;
        }
    }
    if (!$runIsCareerScoped) {
        $respondEmailApply(409, [
            'success' => false,
            'run_unavailable' => true,
            'error' => 'La ejecución anterior no tiene un alcance seguro de CC por carrera y período. Revisa Brightspace, cierra sesión e inicia una nueva antes de continuar.',
        ]);
    }
    if ($applyAction === 'restart') {
        $run['next_offset'] = 0;
        $run['succeeded'] = 0;
        $run['failed'] = 0;
        $run['failures'] = [];
        unset($run['paused']);
        $_SESSION['bulk_shared_email_run'] = $run;
    }

    $offset = (int) ($run['next_offset'] ?? 0);
    $targets = $run['targets'] ?? [];
    $total = is_array($targets) ? count($targets) : 0;
    if (!is_array($targets) || !isset($targets[$offset]) || !is_array($targets[$offset])) {
        $respondEmailApply(409, ['success' => false, 'error' => 'No se pudo recuperar el siguiente agente de la actualización.']);
    }

    $batchStartedAt = microtime(true);
    $batchResults = [];
    $maxBatchSize = 8;
    $batchTimeLimit = 12.0;
    do {
        $target = $targets[$offset];
        $agentId = (int) ($target['AgentId'] ?? 0);
        $orgUnitId = (int) ($target['OrgUnitId'] ?? 0);
        $agentName = (string) ($target['Name'] ?? ('Agente ' . $agentId));
        $agentError = '';
        try {
            $confirmedAgent = $agentService->updateEmailActionCcRecipient(
                $agentId,
                $orgUnitId,
                (string) $run['recipient']
            );
            $confirmedEmailAction = $confirmedAgent['Action']['EmailAction'];

            try {
                $discovery->updateCachedAgentEmailActions([
                    $orgUnitId . ':' . $agentId => [
                        'To' => (string) $confirmedEmailAction['To'],
                        'Cc' => (string) ($confirmedEmailAction['Cc'] ?? ''),
                    ],
                ]);
            } catch (Throwable $cacheException) {
                AppLogger::exception($cacheException, [
                    'context' => 'brightspace_agent_email_cache_update_failed',
                    'agent_id' => $agentId,
                    'org_unit_id' => $orgUnitId,
                ]);
            }

            try {
                $audit->record('brightspace_agent.email_cc_updated', [
                    'agent_id' => $agentId,
                    'org_unit_id' => $orgUnitId,
                    'agent_name' => $agentName,
                ]);
            } catch (Throwable $auditException) {
                AppLogger::exception($auditException, [
                    'context' => 'brightspace_agent_email_audit_failed',
                    'agent_id' => $agentId,
                    'org_unit_id' => $orgUnitId,
                ]);
            }
            $run['succeeded']++;
        } catch (Throwable $exception) {
            AppLogger::exception($exception, [
                'context' => 'brightspace_agent_email_action_update',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
            $agentError = $exception->getMessage();
            if (str_starts_with($agentError, 'Error de conexión con Brightspace:')) {
                $run['paused'] = true;
                $_SESSION['bulk_shared_email_run'] = $run;
                $respondEmailApply(200, [
                    'success' => true,
                    'paused' => true,
                    'run_id' => $run['run_id'],
                    'total' => $total,
                    'processed' => (int) $run['next_offset'],
                    'succeeded' => (int) $run['succeeded'],
                    'failed' => (int) $run['failed'],
                    'error' => 'Brightspace no está resolviendo su servidor. La actualización quedó pausada sin marcar este agente como fallido. Verifica la conexión DNS y vuelve a intentar.',
                    'failures' => $run['failures'],
                ]);
            }
            $run['failed']++;
            if (count($run['failures']) < 20) {
                $run['failures'][] = ['agent_name' => $agentName, 'agent_id' => $agentId, 'error' => $agentError];
            }
        }

        $batchResults[] = [
            'agent_name' => $agentName,
            'agent_id' => $agentId,
            'success' => $agentError === '',
            'error' => $agentError,
        ];
        $offset++;
        $run['next_offset'] = $offset;
    } while (
        $offset < $total
        && count($batchResults) < $maxBatchSize
        && microtime(true) - $batchStartedAt < $batchTimeLimit
    );

    $processed = (int) $run['next_offset'];
    $done = $processed >= $total;
    $result = [
        'success' => true,
        'done' => $done,
        'run_id' => $run['run_id'],
        'total' => $total,
        'processed' => $processed,
        'succeeded' => (int) $run['succeeded'],
        'failed' => (int) $run['failed'],
        'batch_results' => $batchResults,
        'failures' => $run['failures'],
    ];
    if ($done) {
        $_SESSION['bulk_shared_email_run'] = [
            'kind' => 'career_recipient',
            'period' => $run['period'],
            'career' => $run['career'],
            'run_id' => $run['run_id'],
            'done_result' => $result,
        ];
    } else {
        $_SESSION['bulk_shared_email_run'] = $run;
    }
    $respondEmailApply(200, $result);
}

// Aplica el mensaje por carrera y periodo en lotes reanudables de agentes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shared_email_message_apply_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondMessageApply = static function (int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $respondMessageApply(403, ['success' => false, 'error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.']);
    }
    $submittedPeriod = $_POST['shared_email_scope_period'] ?? null;
    $submittedCareer = $_POST['shared_email_scope_career'] ?? null;
    if (
        !$emailScopeComplete
        || !is_string($submittedPeriod)
        || !hash_equals($emailScopePeriod, $submittedPeriod)
        || !is_string($submittedCareer)
        || !hash_equals($emailScopeCareer, $submittedCareer)
    ) {
        $respondMessageApply(422, [
            'success' => false,
            'error' => 'Selecciona una carrera y un período para guardar y aplicar el mensaje.',
        ]);
    }
    $messageAction = $_POST['shared_email_message_apply_action'];
    if (!is_string($messageAction) || !in_array($messageAction, ['start', 'continue', 'restart'], true)) {
        $respondMessageApply(400, ['success' => false, 'error' => 'La acción del mensaje compartido no es válida.']);
    }
    if (
        $agentService === null
        || $discovery === null
        || $inventoryStatus === null
        || $inventoryStatus['complete'] !== true
        || $inventoryStatus['fresh'] !== true
    ) {
        $respondMessageApply(409, [
            'success' => false,
            'error' => $sharedEmailConfigError ?? 'Se necesita un inventario completo y actualizado y el mensaje configurado para esta carrera y período para continuar.',
        ]);
    }

    if ($messageAction === 'start') {
        foreach (['bulk_shared_email_run', 'bulk_shared_message_run'] as $pendingKey) {
            $pending = $_SESSION[$pendingKey] ?? null;
            if (is_array($pending) && !is_array($pending['done_result'] ?? null)) {
                $respondMessageApply(409, [
                    'success' => false,
                    'legacy_run_unsupported' => !isset($pending['kind']),
                    'error' => !isset($pending['kind'])
                        ? 'Existe una actualización anterior con alcance combinado que esta versión no puede reanudar con seguridad. Revisa Brightspace, cierra sesión e inicia una sesión nueva antes de continuar.'
                        : 'Hay una actualización pendiente. Reanúdala antes de aplicar el mensaje compartido.',
                ]);
            }
        }
        $body = is_string($_POST['shared_email_body'] ?? null) ? $_POST['shared_email_body'] : '';
        $draftChanged = false;
        try {
            $draftChanged = $careerPeriodEmailDraftService->saveBody(
                $emailScopePeriod,
                $emailScopeCareer,
                $body,
                (string) ($_SESSION['admin_username'] ?? 'system')
            );
        } catch (InvalidArgumentException $exception) {
            $respondMessageApply(422, ['success' => false, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            AppLogger::exception($exception, ['context' => 'shared_email_message_save']);
            $respondMessageApply(500, ['success' => false, 'error' => 'No se pudo guardar el mensaje; no se modificó ningún agente.']);
        }
        try {
            $audit->record('shared_email_draft.message_saved', [
                'changed' => $draftChanged,
                'source' => 'career_period_apply',
                'period' => $emailScopePeriod,
                'career' => $emailScopeCareer,
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, ['context' => 'shared_email_message_audit']);
        }
        if ($sharedEmailCareerTargets === []) {
            $respondMessageApply(409, [
                'success' => false,
                'draft_saved' => true,
                'error' => 'El mensaje se guardó, pero no hay agentes con acción de correo para esta carrera y período.',
            ]);
        }
        $_SESSION['bulk_shared_message_run'] = [
            'kind' => 'message',
            'scope' => 'career_period',
            'period' => $emailScopePeriod,
            'career' => $emailScopeCareer,
            'run_id' => bin2hex(random_bytes(16)),
            'body' => $body,
            'targets' => $sharedEmailCareerTargets,
            'next_offset' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'failures' => [],
        ];
        $respondMessageApply(200, [
            'success' => true,
            'draft_saved' => true,
            'draft_changed' => $draftChanged,
            'run_id' => $_SESSION['bulk_shared_message_run']['run_id'],
            'scope' => 'career_period',
            'total' => count($sharedEmailCareerTargets),
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'done' => false,
        ]);
    }

    $run = $_SESSION['bulk_shared_message_run'] ?? null;
    $runId = $_POST['run_id'] ?? null;
    if (
        !is_array($run)
        || ($run['kind'] ?? null) !== 'message'
        || !is_string($runId)
        || !is_string($run['run_id'] ?? null)
        || !hash_equals($run['run_id'], $runId)
        || (string) ($run['period'] ?? '') !== $emailScopePeriod
        || (string) ($run['career'] ?? '') !== $emailScopeCareer
    ) {
        $respondMessageApply(409, [
            'success' => false,
            'run_unavailable' => true,
            'error' => 'El servidor ya no conserva un avance compatible para esta ejecución.',
        ]);
    }
    if (is_array($run['done_result'] ?? null)) {
        $respondMessageApply(200, $run['done_result']);
    }
    $runTargets = is_array($run['targets'] ?? null) ? $run['targets'] : [];
    $validTargetKeys = [];
    // Evita reanudar una ejecucion guardada si sus agentes ya no pertenecen al filtro vigente.
    $runScopeTargets = BrightspaceAgentService::agentsWithEmailActionForCareerPeriod(
        $allDiscoveredAgents,
        (string) ($run['career'] ?? ''),
        (string) ($run['period'] ?? '')
    );
    foreach ($runScopeTargets as $target) {
        $validTargetKeys[(int) $target['OrgUnitId'] . ':' . (int) $target['AgentId']] = true;
    }
    $targetsValid = $runTargets !== [];
    foreach ($runTargets as $target) {
        $key = is_array($target)
            ? (int) ($target['OrgUnitId'] ?? 0) . ':' . (int) ($target['AgentId'] ?? 0)
            : '';
        if (!isset($validTargetKeys[$key])) {
            $targetsValid = false;
            break;
        }
    }
    if (!$targetsValid) {
        $respondMessageApply(409, [
            'success' => false,
            'run_unavailable' => true,
            'error' => 'Los agentes guardados para esta actualización ya no coinciden con el inventario. Revisa Brightspace antes de iniciar otra.',
        ]);
    }
    if ($messageAction === 'restart') {
        $run['next_offset'] = 0;
        $run['succeeded'] = 0;
        $run['failed'] = 0;
        $run['failures'] = [];
        unset($run['paused']);
    }
    $offset = (int) ($run['next_offset'] ?? 0);
    $total = count($runTargets);
    if (!isset($runTargets[$offset]) || !is_array($runTargets[$offset])) {
        $respondMessageApply(409, ['success' => false, 'error' => 'No se pudo recuperar el siguiente agente de la actualización.']);
    }
    set_time_limit(90);
    $batchTargets = array_slice($runTargets, $offset, 4);
    $agentResults = [];
    try {
        $agentResults = $agentService->updateEmailActionMessages($batchTargets, (string) $run['body']);
    } catch (Throwable $exception) {
        AppLogger::exception($exception, [
            'context' => 'brightspace_agent_email_message_batch',
            'run_id' => $run['run_id'],
            'batch_offset' => $offset,
            'batch_size' => count($batchTargets),
        ]);
        $run['paused'] = true;
        $_SESSION['bulk_shared_message_run'] = $run;
        $respondMessageApply(200, [
            'success' => true,
            'paused' => true,
            'run_id' => $run['run_id'],
            'total' => $total,
            'processed' => (int) $run['next_offset'],
            'succeeded' => (int) $run['succeeded'],
            'failed' => (int) $run['failed'],
            'error' => 'No se pudo completar este lote. El avance quedó pausado; revisa Brightspace y reanuda antes de iniciar otra aplicación.',
            'failures' => $run['failures'],
        ]);
    }

    $batchResults = [];
    $cacheUpdates = [];
    $connectionFailure = null;
    // Cada resultado trae su agente objetivo y el error o la configuracion confirmada.
    foreach ($agentResults as $agentResult) {
        $target = is_array($agentResult['target'] ?? null) ? $agentResult['target'] : [];
        $agentId = (int) ($target['AgentId'] ?? 0);
        $orgUnitId = (int) ($target['OrgUnitId'] ?? 0);
        $agentName = (string) ($target['Name'] ?? ('Agente ' . $agentId));
        $agentError = is_string($agentResult['error'] ?? null) ? $agentResult['error'] : '';
        if (str_starts_with($agentError, 'Error de conexión con Brightspace:')) {
            $connectionFailure = $agentError;
            break;
        }

        $confirmedAgent = $agentResult['confirmed_agent'] ?? null;
        if ($agentError === '' && is_array($confirmedAgent)) {
            $confirmedMessage = (string) $confirmedAgent['Action']['EmailAction']['Message'];
            $cacheUpdates[$orgUnitId . ':' . $agentId] = ['Message' => $confirmedMessage];
            try {
                $audit->record('brightspace_agent.email_message_updated', [
                    'agent_id' => $agentId,
                    'org_unit_id' => $orgUnitId,
                    'agent_name' => $agentName,
                ]);
            } catch (Throwable $auditException) {
                AppLogger::exception($auditException, ['context' => 'brightspace_agent_message_audit_failed']);
            }
            $run['succeeded']++;
        } else {
            $agentError = $agentError !== '' ? $agentError : 'Brightspace no confirmó el mensaje actualizado.';
            AppLogger::warning('Brightspace email message update was not confirmed.', [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'error' => $agentError,
            ]);
            $run['failed']++;
            if (count($run['failures']) < 20) {
                $run['failures'][] = ['agent_name' => $agentName, 'agent_id' => $agentId, 'error' => $agentError];
            }
        }
        $batchResults[] = [
            'agent_name' => $agentName,
            'agent_id' => $agentId,
            'success' => $agentError === '',
            'error' => $agentError,
        ];
        $offset++;
        $run['next_offset'] = $offset;
    }

    if ($cacheUpdates !== []) {
        try {
            $discovery->updateCachedAgentEmailActions($cacheUpdates);
        } catch (Throwable $cacheException) {
            AppLogger::exception($cacheException, [
                'context' => 'brightspace_agent_message_cache_update_failed',
                'run_id' => $run['run_id'],
            ]);
        }
    }

    if ($connectionFailure !== null) {
        $run['paused'] = true;
        $_SESSION['bulk_shared_message_run'] = $run;
        $respondMessageApply(200, [
            'success' => true,
            'paused' => true,
            'run_id' => $run['run_id'],
            'total' => $total,
            'processed' => (int) $run['next_offset'],
            'succeeded' => (int) $run['succeeded'],
            'failed' => (int) $run['failed'],
            'error' => 'Brightspace no está disponible temporalmente o limitó las solicitudes. La actualización quedó pausada; espera un momento y reanúdala.',
            'failures' => $run['failures'],
        ]);
    }
    $processed = (int) $run['next_offset'];
    $done = $processed >= $total;
    $result = [
        'success' => true,
        'done' => $done,
        'run_id' => $run['run_id'],
        'total' => $total,
        'processed' => $processed,
        'succeeded' => (int) $run['succeeded'],
        'failed' => (int) $run['failed'],
        'batch_results' => $batchResults,
        'failures' => $run['failures'],
    ];
    $_SESSION['bulk_shared_message_run'] = $done
        ? [
            'kind' => 'message',
            'scope' => $run['scope'] ?? 'career_period',
            'period' => $run['period'] ?? null,
            'career' => $run['career'] ?? null,
            'run_id' => $run['run_id'],
            'done_result' => $result,
        ]
        : $run;
    $respondMessageApply(200, $result);
}

// Actualiza fechas de cronograma en lotes y conserva las demas opciones del agente.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $respondSchedule = static function (int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    $csrfValue = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfValue) || !hash_equals($csrfToken, $csrfValue)) {
        $respondSchedule(403, ['success' => false, 'error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.']);
    }

    if (!is_string($_POST['schedule_action']) || $_POST['schedule_action'] !== 'update_dates') {
        $respondSchedule(400, ['success' => false, 'error' => 'La acción de cronograma no es válida.']);
    }

    if (
        $agentService === null
        || $discovery === null
        || $inventoryStatus === null
        || $inventoryStatus['complete'] !== true
        || $inventoryStatus['fresh'] !== true
    ) {
        $respondSchedule(409, ['success' => false, 'error' => 'Se necesita un inventario completo y actualizado y una conexión a Brightspace para continuar.']);
    }

    $parallelValue = $_POST['schedule_parallel'] ?? null;
    $parallel = is_string($parallelValue) ? strtoupper(trim($parallelValue)) : '';
    if (!in_array($parallel, ['A', 'B'], true)) {
        $respondSchedule(400, ['success' => false, 'error' => 'Selecciona un módulo válido.']);
    }
    if ($selectedParallel !== $parallel) {
        $respondSchedule(409, ['success' => false, 'error' => 'El formulario de cronograma no coincide con el módulo seleccionado.']);
    }

    $startValue = $_POST['start_date'] ?? null;
    $endValue = $_POST['end_date'] ?? null;
    if (!is_string($startValue) || !is_string($endValue)) {
        $respondSchedule(422, ['success' => false, 'error' => 'Selecciona fechas válidas para el cronograma.']);
    }

    $startDate = $startValue;
    $endDate = $endValue;
    try {
        BrightspaceAgentService::normalizeScheduleDateRange($startDate, $endDate);
    } catch (InvalidArgumentException $exception) {
        $respondSchedule(422, ['success' => false, 'error' => $exception->getMessage()]);
    }

    $offsetValue = filter_var($_POST['schedule_offset'] ?? null, FILTER_VALIDATE_INT);
    $offset = $offsetValue === false || $offsetValue === null ? -1 : $offsetValue;
    $runIdValue = $_POST['schedule_run_id'] ?? '';
    $runId = is_string($runIdValue) ? trim($runIdValue) : '';
    $run = $_SESSION['bulk_agent_schedule_run'] ?? null;
    if ($offset < 0) {
        $respondSchedule(400, ['success' => false, 'error' => 'La posición del lote no es válida.']);
    }

    if ($runId === '' && $offset === 0) {
        // El servidor deriva los agentes elegibles desde su inventario, no desde IDs arbitrarios del cliente.
        $scheduleTargets = array_values(array_filter(
            $allDiscoveredAgents,
            static function (array $agent) use ($selectedPeriod, $selectedCareer, $parallel, $agentParallel): bool {
                if ($selectedPeriod !== '' && ($agent['period'] ?? null) !== $selectedPeriod) {
                    return false;
                }
                if ($selectedCareer !== '' && !CourseCatalogService::careerMatches($agent['career'] ?? null, $selectedCareer)) {
                    return false;
                }

                return $agentParallel($agent) === $parallel
                    && (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0) > 0
                    && (int) ($agent['OrgUnitId'] ?? 0) > 0
                    && is_array($agent['Schedule'] ?? null);
            }
        ));

        $targetKeys = [];
        $seenTargetKeys = [];
        foreach ($scheduleTargets as $target) {
            $targetKey = (int) $target['OrgUnitId'] . ':' . (int) ($target['AgentId'] ?? $target['Id']);
            if (!isset($seenTargetKeys[$targetKey])) {
                $targetKeys[] = $targetKey;
                $seenTargetKeys[$targetKey] = true;
            }
        }

        $run = [
            'id' => bin2hex(random_bytes(16)),
            'parallel' => $parallel,
            'period' => $selectedPeriod,
            'career' => $selectedCareer,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'keys' => $targetKeys,
            'next_offset' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'last_error' => '',
        ];
        $_SESSION['bulk_agent_schedule_run'] = $run;
        $runId = $run['id'];
    } elseif (
        !is_array($run)
        || !hash_equals((string) ($run['id'] ?? ''), $runId)
        || ($run['parallel'] ?? '') !== $parallel
        || ($run['period'] ?? '') !== $selectedPeriod
        || ($run['career'] ?? '') !== $selectedCareer
        || ($run['start_date'] ?? '') !== $startDate
        || ($run['end_date'] ?? '') !== $endDate
    ) {
        $respondSchedule(409, ['success' => false, 'error' => 'La operación de cronograma expiró o cambió. Vuelve a iniciarla.']);
    }

    if ($offset !== (int) $run['next_offset']) {
        $respondSchedule(409, ['success' => false, 'error' => 'El lote llegó fuera de orden. Vuelve a iniciar el cronograma.']);
    }

    $total = count($run['keys']);
    if ($offset >= $total) {
        $result = [
            'success' => (int) $run['failed'] === 0,
            'done' => true,
            'run_id' => $runId,
            'parallel' => $parallel,
            'total' => $total,
            'processed' => $offset,
            'succeeded' => (int) $run['succeeded'],
            'failed' => (int) $run['failed'],
            'last_error' => (string) $run['last_error'],
        ];
        unset($_SESSION['bulk_agent_schedule_run']);
        $respondSchedule(200, $result);
    }

    $agentByKey = [];
    foreach ($allDiscoveredAgents as $candidate) {
        $candidateId = (int) ($candidate['AgentId'] ?? $candidate['Id'] ?? 0);
        $candidateOrgUnitId = (int) ($candidate['OrgUnitId'] ?? 0);
        if ($candidateId > 0 && $candidateOrgUnitId > 0) {
            $agentByKey[$candidateOrgUnitId . ':' . $candidateId] = $candidate;
        }
    }

    $targetKey = (string) $run['keys'][$offset];
    $agent = $agentByKey[$targetKey] ?? null;
    $agentId = is_array($agent) ? (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0) : 0;
    $orgUnitId = is_array($agent) ? (int) ($agent['OrgUnitId'] ?? 0) : 0;
    $agentName = is_array($agent) ? (string) ($agent['Name'] ?? ('Agente ' . $agentId)) : $targetKey;
    $confirmedSchedule = null;
    $agentError = '';

    try {
        if (
            !is_array($agent)
            || $agentId <= 0
            || $orgUnitId <= 0
            || $agentParallel($agent) !== $parallel
            || ($selectedPeriod !== '' && ($agent['period'] ?? null) !== $selectedPeriod)
            || ($selectedCareer !== '' && !CourseCatalogService::careerMatches($agent['career'] ?? null, $selectedCareer))
        ) {
            throw new RuntimeException('El agente ya no coincide con la selección académica actual.');
        }

        $freshAgent = $agentService->fetchAgent($agentId, $orgUnitId);
        if (
            (int) ($freshAgent['AgentId'] ?? $freshAgent['Id'] ?? 0) !== $agentId
            || (int) ($freshAgent['OrgUnitId'] ?? 0) !== $orgUnitId
        ) {
            throw new RuntimeException('La configuración actual del agente no coincide con el inventario.');
        }

        $confirmedAgent = $agentService->setScheduleDates($freshAgent, $startDate, $endDate);
        $confirmedSchedule = $confirmedAgent['Schedule'] ?? null;
        if (!is_array($confirmedSchedule)) {
            throw new RuntimeException('Brightspace no devolvió el cronograma confirmado.');
        }

        try {
            $discovery->updateCachedAgentSchedules([
                $orgUnitId . ':' . $agentId => $confirmedSchedule,
            ]);
        } catch (Throwable $cacheException) {
            AppLogger::exception($cacheException, [
                'context' => 'brightspace_agent_schedule_cache_update_failed',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
        }

        try {
            $audit->record('brightspace_agent.schedule_dates_updated', [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'agent_name' => $agentName,
                'parallel' => $parallel,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'filter' => [
                    'period' => $selectedPeriod,
                    'career' => $selectedCareer,
                ],
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, [
                'context' => 'brightspace_agent_schedule_audit_failed',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
        }
        $run['succeeded']++;
    } catch (Throwable $exception) {
        AppLogger::exception($exception, [
            'context' => 'brightspace_agent_schedule_dates',
            'agent_id' => $agentId,
            'org_unit_id' => $orgUnitId,
            'parallel' => $parallel,
        ]);
        $run['failed']++;
        $run['last_error'] = $exception->getMessage();
        $agentError = $exception->getMessage();
    }

    $run['next_offset']++;
    $_SESSION['bulk_agent_schedule_run'] = $run;
    $respondSchedule(200, [
        'success' => $agentError === '',
        'done' => false,
        'run_id' => $runId,
        'parallel' => $parallel,
        'offset' => $offset,
        'total' => $total,
        'processed' => (int) $run['next_offset'],
        'succeeded' => (int) $run['succeeded'],
        'failed' => (int) $run['failed'],
        'agent_name' => $agentName,
        'agent_id' => $agentId,
        'org_unit_id' => $orgUnitId,
        'start_date' => $confirmedSchedule['StartDate'] ?? null,
        'end_date' => $confirmedSchedule['EndDate'] ?? null,
        'error' => $agentError,
    ]);
}

// Cambia solo los dias de actividad de agentes elegibles de la clase y el filtro actuales.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_activity_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $respondCourseActivity = static function (int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    $csrfValue = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfValue) || !hash_equals($csrfToken, $csrfValue)) {
        $respondCourseActivity(403, ['success' => false, 'error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.']);
    }

    if (!is_string($_POST['course_activity_action']) || $_POST['course_activity_action'] !== 'update_days') {
        $respondCourseActivity(400, ['success' => false, 'error' => 'La acción de condición de actividad no es válida.']);
    }

    if (
        $agentService === null
        || $discovery === null
        || $inventoryStatus === null
        || $inventoryStatus['complete'] !== true
        || $inventoryStatus['fresh'] !== true
    ) {
        $respondCourseActivity(409, ['success' => false, 'error' => 'Se necesita un inventario completo y actualizado y una conexión a Brightspace para continuar.']);
    }

    $orgUnitValue = filter_var($_POST['org_unit_id'] ?? null, FILTER_VALIDATE_INT);
    $agentValue = filter_var($_POST['agent_id'] ?? null, FILTER_VALIDATE_INT);
    if ($orgUnitValue === false || $orgUnitValue === null || $orgUnitValue <= 0
        || $agentValue === false || $agentValue === null || $agentValue <= 0) {
        $respondCourseActivity(422, ['success' => false, 'error' => 'La clase o el agente no son válidos.']);
    }

    $days = 0;
    try {
        $days = BrightspaceAgentService::normalizeCourseActivityDays($_POST['days'] ?? null);
    } catch (InvalidArgumentException $exception) {
        $respondCourseActivity(422, ['success' => false, 'error' => $exception->getMessage()]);
        exit;
    }

    $orgUnitId = $orgUnitValue;
    $agentId = $agentValue;
    $editableTargets = ReminderClassService::agentsWithCourseActivityForScope(
        $allDiscoveredAgents,
        $orgUnitId,
        $selectedPeriod !== '' ? $selectedPeriod : null,
        $selectedCareer !== '' ? $selectedCareer : null,
        $selectedParallel !== '' ? $selectedParallel : null
    );
    $agent = null;
    foreach ($editableTargets as $candidate) {
        if ((int) ($candidate['AgentId'] ?? $candidate['Id'] ?? 0) === $agentId) {
            $agent = $candidate;
            break;
        }
    }
    if (!is_array($agent)) {
        $respondCourseActivity(404, ['success' => false, 'error' => 'El agente no pertenece a esta clase y al alcance académico seleccionado, o no tiene una condición de actividad editable.']);
    }

    $agentName = (string) ($agent['Name'] ?? ('Agente ' . $agentId));
    try {
        $freshAgent = $agentService->fetchAgent($agentId, $orgUnitId);
        if (
            (int) ($freshAgent['AgentId'] ?? $freshAgent['Id'] ?? 0) !== $agentId
            || (int) ($freshAgent['OrgUnitId'] ?? 0) !== $orgUnitId
            || !is_array($freshAgent['Condition']['CourseActivity'] ?? null)
        ) {
            throw new RuntimeException('El agente ya no tiene una condición de actividad editable en esta clase.');
        }

        $confirmedAgent = $agentService->setCourseActivityDays($freshAgent, $days);
        $confirmedDays = BrightspaceAgentService::normalizeCourseActivityDays(
            $confirmedAgent['Condition']['CourseActivity']['Days'] ?? null
        );

        try {
            $discovery->updateCachedAgentCourseActivityDays([
                $orgUnitId . ':' . $agentId => $confirmedDays,
            ]);
        } catch (Throwable $cacheException) {
            AppLogger::exception($cacheException, [
                'context' => 'brightspace_agent_course_activity_cache_update_failed',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
        }

        try {
            $audit->record('brightspace_agent.course_activity_days_updated', [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'agent_name' => $agentName,
                'previous_days' => $freshAgent['Condition']['CourseActivity']['Days'] ?? null,
                'days' => $confirmedDays,
                'filter' => [
                    'period' => $selectedPeriod,
                    'career' => $selectedCareer,
                    'parallel' => $selectedParallel,
                ],
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, [
                'context' => 'brightspace_agent_course_activity_audit_failed',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
        }

        $respondCourseActivity(200, [
            'success' => true,
            'agent_id' => $agentId,
            'org_unit_id' => $orgUnitId,
            'days' => $confirmedDays,
        ]);
    } catch (Throwable $exception) {
        AppLogger::exception($exception, [
            'context' => 'brightspace_agent_course_activity_days',
            'agent_id' => $agentId,
            'org_unit_id' => $orgUnitId,
        ]);
        try {
            $audit->record('brightspace_agent.course_activity_days_failed', [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'agent_name' => $agentName,
                'days' => $days,
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, [
                'context' => 'brightspace_agent_course_activity_failure_audit_failed',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
        }

        $respondCourseActivity(200, [
            'success' => false,
            'agent_id' => $agentId,
            'org_unit_id' => $orgUnitId,
            'error' => $exception->getMessage(),
        ]);
    }
}

// Activa o desactiva todos los agentes de una clase despues de validar el inventario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_action'])) {
    try {
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.');
        }

        if (
            $agentService === null
            || $discovery === null
            || $inventoryStatus === null
            || $inventoryStatus['complete'] !== true
            || $inventoryStatus['fresh'] !== true
        ) {
            throw new RuntimeException('Se necesita un inventario completo y una conexión a Brightspace para continuar.');
        }

        $orgUnitId = filter_var($_POST['org_unit_id'] ?? null, FILTER_VALIDATE_INT);
        if ($orgUnitId === false || $orgUnitId === null || $orgUnitId <= 0) {
            throw new InvalidArgumentException('El identificador de la clase no es válido.');
        }

        $classAction = (string) $_POST['class_action'];
        if (!in_array($classAction, ['activate', 'deactivate'], true)) {
            throw new InvalidArgumentException('La acción para esta clase no es válida.');
        }
        $desiredState = $classAction === 'activate';

        $classTargets = ReminderClassService::agentsForOrgUnit($allDiscoveredAgents, $orgUnitId);
        if ($classTargets === []) {
            throw new RuntimeException('No hay agentes en esta clase para los filtros actuales.');
        }

        $actionLabel = $desiredState ? 'Activados' : 'Desactivados';
        $changed = 0;
        $alreadyCorrect = 0;
        $failures = [];
        $warnings = [];
        $confirmedStates = [];

        // Relee cada agente y solo registra su estado despues de confirmar el cambio remoto.
        foreach ($classTargets as $target) {
            $agentId = (int) ($target['AgentId'] ?? $target['Id'] ?? 0);
            $agentName = (string) ($target['Name'] ?? ('Agente ' . $agentId));
            $threshold = ReminderClassService::verifiedThreshold($target);

            try {
                if ($agentId <= 0 || (int) ($target['OrgUnitId'] ?? 0) !== $orgUnitId) {
                    throw new RuntimeException('El agente no coincide con esta clase.');
                }

                $freshAgent = $agentService->fetchAgent($agentId, $orgUnitId);
                if (
                    (int) ($freshAgent['AgentId'] ?? $freshAgent['Id'] ?? 0) !== $agentId
                    || (int) ($freshAgent['OrgUnitId'] ?? 0) !== $orgUnitId
                ) {
                    throw new RuntimeException('La configuración actual del agente no coincide con esta clase.');
                }

                if ((bool) ($freshAgent['IsEnabled'] ?? false) === $desiredState) {
                    $alreadyCorrect++;
                } else {
                    $agentService->setEnabled($freshAgent, $desiredState);
                    $changed++;

                    try {
                        $audit->record('brightspace_agent.class_' . $classAction, [
                            'agent_id' => $agentId,
                            'org_unit_id' => $orgUnitId,
                            'agent_name' => $agentName,
                            'threshold' => $threshold,
                        ]);
                    } catch (Throwable $auditException) {
                        AppLogger::exception($auditException, [
                            'context' => 'brightspace_class_agent_audit_failed',
                            'agent_id' => $agentId,
                            'org_unit_id' => $orgUnitId,
                            'action' => $classAction,
                        ]);
                        $warnings[$agentId][] = 'auditoría';
                    }
                }

                $confirmedStates[$agentId] = $desiredState;

                try {
                    $discovery->updateCachedAgentStates([$orgUnitId . ':' . $agentId => $desiredState]);
                } catch (Throwable $cacheException) {
                    AppLogger::exception($cacheException, [
                        'context' => 'brightspace_class_agent_cache_update_failed',
                        'agent_id' => $agentId,
                        'org_unit_id' => $orgUnitId,
                    ]);
                    $warnings[$agentId][] = 'caché';
                }
            } catch (Throwable $exception) {
                AppLogger::exception($exception, [
                    'context' => 'brightspace_class_agent_action',
                    'agent_id' => $agentId,
                    'org_unit_id' => $orgUnitId,
                ]);
                try {
                    $audit->record('brightspace_agent.class_' . $classAction . '_failed', [
                        'agent_id' => $agentId,
                        'org_unit_id' => $orgUnitId,
                        'agent_name' => $agentName,
                        'threshold' => $threshold,
                        'error' => 'class_agent_' . $classAction . '_failed',
                    ]);
                } catch (Throwable $auditException) {
                    AppLogger::exception($auditException, [
                        'context' => 'brightspace_class_agent_failure_audit_failed',
                        'agent_id' => $agentId,
                        'org_unit_id' => $orgUnitId,
                    ]);
                }
                $failures[] = $agentName;
            }
        }

        foreach ($allDiscoveredAgents as &$discoveredAgent) {
            $agentId = (int) ($discoveredAgent['AgentId'] ?? $discoveredAgent['Id'] ?? 0);
            if ((int) ($discoveredAgent['OrgUnitId'] ?? 0) === $orgUnitId && array_key_exists($agentId, $confirmedStates)) {
                $discoveredAgent['IsEnabled'] = $confirmedStates[$agentId];
            }
        }
        unset($discoveredAgent);
        foreach ($discoveredAgents as &$discoveredAgent) {
            $agentId = (int) ($discoveredAgent['AgentId'] ?? $discoveredAgent['Id'] ?? 0);
            if ((int) ($discoveredAgent['OrgUnitId'] ?? 0) === $orgUnitId && array_key_exists($agentId, $confirmedStates)) {
                $discoveredAgent['IsEnabled'] = $confirmedStates[$agentId];
            }
        }
        unset($discoveredAgent);

        $warningNote = $warnings === []
            ? ''
            : ' También hubo problemas de auditoría o caché para ' . count($warnings) . ' agente(s).';
        if ($failures !== []) {
            $message = sprintf(
                '%s %d agentes; %d no se pudieron completar: %s.%s',
                $actionLabel,
                $changed,
                count($failures),
                implode(', ', array_slice($failures, 0, 4)),
                $warningNote
            );
            $messageType = 'error';
        } elseif ($warnings !== []) {
            $message = sprintf(
                '%s %d agentes; revisa el registro: no se pudo actualizar la caché o auditoría de %d agente(s).',
                $actionLabel,
                $changed,
                count($warnings)
            );
            $messageType = 'error';
        } elseif ($changed > 0) {
            $message = sprintf(
                '%s %d agentes de esta clase; %d ya estaban en ese estado.',
                $actionLabel,
                $changed,
                $alreadyCorrect
            );
        } else {
            $message = sprintf(
                'No se realizaron cambios: %d agentes ya estaban %s.',
                $alreadyCorrect,
                $desiredState ? 'activos' : 'inactivos'
            );
        }
    } catch (Throwable $exception) {
        AppLogger::exception($exception, ['context' => 'brightspace_class_agent_request']);
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

// Procesa acciones generales por seleccion mediante solicitudes JSON por lotes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $respond = static function (int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $respond(403, ['success' => false, 'error' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.']);
    }

    if ((string) ($_POST['bulk_chunk'] ?? '') !== '1') {
        $respond(400, ['success' => false, 'error' => 'La solicitud no corresponde a un lote válido.']);
    }

    if ($agentService === null || $inventoryStatus === null) {
        $respond(503, ['success' => false, 'error' => 'Brightspace no está disponible para realizar esta acción.']);
    }

    if ($inventoryStatus['complete'] !== true || $inventoryStatus['fresh'] !== true) {
        $respond(409, ['success' => false, 'error' => 'El inventario está incompleto o vencido. Actualízalo antes de aplicar cambios masivos.']);
    }

    $bulkAction = (string) $_POST['bulk_action'];
    if (!in_array($bulkAction, ['activate_selection', 'deactivate_selection'], true)) {
        $respond(400, ['success' => false, 'error' => 'La acción solicitada no es válida.']);
    }

    $desiredState = $bulkAction === 'activate_selection';
    $offset = filter_var($_POST['bulk_offset'] ?? null, FILTER_VALIDATE_INT);
    $offset = $offset === false || $offset === null ? -1 : $offset;
    $runId = trim((string) ($_POST['bulk_run_id'] ?? ''));
    $run = $_SESSION['bulk_agent_run'] ?? null;

    if ($offset < 0) {
        $respond(400, ['success' => false, 'error' => 'La posición del lote no es válida.']);
    }

    if ($runId === '' && $offset === 0) {
        // Congela los objetivos al iniciar para mantener fijo el alcance de todos los lotes.
        $bulkTargets = array_values(array_filter(
            $discoveredAgents,
            static fn (array $agent): bool => (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0) > 0
                && (int) ($agent['OrgUnitId'] ?? 0) > 0
        ));
        $targetKeys = [];
        $seenTargetKeys = [];
        foreach ($bulkTargets as $target) {
            $targetId = (int) ($target['AgentId'] ?? $target['Id'] ?? 0);
            $targetOrgUnitId = (int) ($target['OrgUnitId'] ?? 0);
            if ($targetId > 0 && $targetOrgUnitId > 0) {
                $targetKey = $targetOrgUnitId . ':' . $targetId;
                if (!isset($seenTargetKeys[$targetKey])) {
                    $targetKeys[] = $targetKey;
                    $seenTargetKeys[$targetKey] = true;
                }
            }
        }
        $run = [
            'id' => bin2hex(random_bytes(16)),
            'action' => $bulkAction,
            'keys' => $targetKeys,
            'next_offset' => 0,
            'succeeded' => 0,
            'failed' => 0,
        ];
        $_SESSION['bulk_agent_run'] = $run;
        $runId = $run['id'];
    } elseif (
        !is_array($run)
        || !hash_equals((string) ($run['id'] ?? ''), $runId)
        || ($run['action'] ?? '') !== $bulkAction
    ) {
        $respond(409, ['success' => false, 'error' => 'La operación masiva expiró o cambió. Recarga la página e inténtalo de nuevo.']);
    }

    // Solo se acepta el siguiente lote esperado para no repetir ni saltar agentes.
    if ($offset !== (int) $run['next_offset']) {
        $respond(409, ['success' => false, 'error' => 'El lote llegó fuera de orden. Recarga la página e inténtalo de nuevo.']);
    }

    $total = count($run['keys']);
    if ($offset >= $total) {
        $result = [
            'success' => (int) $run['failed'] === 0,
            'done' => true,
            'run_id' => $runId,
            'total' => $total,
            'processed' => $offset,
            'succeeded' => (int) $run['succeeded'],
            'failed' => (int) $run['failed'],
        ];
        unset($_SESSION['bulk_agent_run']);
        $respond(200, $result);
    }

    $agentByKey = [];
    foreach ($discoveredAgents as $candidate) {
        $candidateId = (int) ($candidate['AgentId'] ?? $candidate['Id'] ?? 0);
        $candidateOrgUnitId = (int) ($candidate['OrgUnitId'] ?? 0);
        if ($candidateId > 0 && $candidateOrgUnitId > 0) {
            $agentByKey[$candidateOrgUnitId . ':' . $candidateId] = $candidate;
        }
    }
    $targetKey = (string) $run['keys'][$offset];
    $agent = $agentByKey[$targetKey] ?? null;
    if (!is_array($agent)) {
        $run['failed']++;
        $run['next_offset']++;
        $_SESSION['bulk_agent_run'] = $run;
        $respond(200, [
            'success' => false,
            'done' => false,
            'run_id' => $runId,
            'offset' => $offset,
            'total' => $total,
            'processed' => $offset + 1,
            'succeeded' => (int) $run['succeeded'],
            'failed' => (int) $run['failed'],
            'agent_name' => $targetKey,
            'error' => 'El agente ya no aparece en el inventario actual.',
        ]);
    }

    $agentId = (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0);
    $orgUnitId = (int) ($agent['OrgUnitId'] ?? 0);
    $agentName = (string) ($agent['Name'] ?? ('Agente ' . $agentId));
    if ($agentId <= 0 || $orgUnitId <= 0) {
        $respond(422, [
            'success' => false,
            'done' => false,
            'offset' => $offset,
            'total' => $total,
            'agent_name' => $agentName,
            'error' => 'El agente no tiene un identificador válido.',
        ]);
    }

    $changed = false;
    try {
        $freshAgent = $agentService->fetchAgent($agentId, $orgUnitId);
        if (
            (int) ($freshAgent['AgentId'] ?? $freshAgent['Id'] ?? 0) !== $agentId
            || (int) ($freshAgent['OrgUnitId'] ?? 0) !== $orgUnitId
        ) {
            throw new RuntimeException('La configuración actual del agente no coincide con el inventario.');
        }

        $freshEnabledState = array_key_exists('IsEnabled', $freshAgent)
            ? filter_var($freshAgent['IsEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        if ($freshEnabledState === null) {
            throw new RuntimeException('Brightspace no devolvió un estado válido para el agente.');
        }

        if ($freshEnabledState !== $desiredState) {
            $agentService->setEnabled($freshAgent, $desiredState);
            $changed = true;
        }
    } catch (Throwable $exception) {
        AppLogger::exception($exception, [
            'context' => 'brightspace_agent_bulk_action',
            'bulk_action' => $bulkAction,
            'agent_id' => $agentId,
            'org_unit_id' => $orgUnitId,
        ]);
        try {
            $audit->record('brightspace_agent.bulk_failed', [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'bulk_action' => $bulkAction,
                'error' => 'bulk_action_failed',
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, ['context' => 'brightspace_agent_bulk_audit_failed']);
        }

        $run['failed']++;
        $run['next_offset']++;
        $_SESSION['bulk_agent_run'] = $run;
        $respond(200, [
            'success' => false,
            'done' => false,
            'run_id' => $runId,
            'offset' => $offset,
            'total' => $total,
            'processed' => (int) $run['next_offset'],
            'succeeded' => (int) $run['succeeded'],
            'failed' => (int) $run['failed'],
            'agent_name' => $agentName,
            'error' => $exception->getMessage(),
        ]);
    }

    try {
        $discovery->updateCachedAgentStates([$orgUnitId . ':' . $agentId => $desiredState]);
    } catch (Throwable $cacheException) {
        AppLogger::exception($cacheException, [
            'context' => 'brightspace_agent_bulk_cache_update_failed',
            'agent_id' => $agentId,
            'org_unit_id' => $orgUnitId,
        ]);
    }

    if ($changed) {
        try {
            $audit->record('brightspace_agent.bulk.' . $bulkAction, [
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
                'agent_name' => $agentName,
                'filter' => [
                    'period' => $selectedPeriod,
                    'career' => $selectedCareer,
                    'parallel' => $selectedParallel,
                ],
            ]);
        } catch (Throwable $auditException) {
            AppLogger::exception($auditException, [
                'context' => 'brightspace_agent_bulk_audit_failed',
                'agent_id' => $agentId,
                'org_unit_id' => $orgUnitId,
            ]);
        }
    }

    $run['succeeded']++;
    $run['next_offset']++;
    $_SESSION['bulk_agent_run'] = $run;
    $respond(200, [
        'success' => true,
        'done' => false,
        'run_id' => $runId,
        'offset' => $offset,
        'total' => $total,
        'processed' => (int) $run['next_offset'],
        'succeeded' => (int) $run['succeeded'],
        'failed' => (int) $run['failed'],
        'agent_name' => $agentName,
        'agent_id' => $agentId,
        'org_unit_id' => $orgUnitId,
        'previous_enabled' => $freshEnabledState,
        'enabled' => $desiredState,
    ]);
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formatScheduleDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'No configurada';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d/m/Y');
    } catch (Throwable) {
        return 'Fecha no válida';
    }
};
$classReminderGroups = ReminderClassService::groupByOrgUnit($discoveredAgents);
$careerEmailConfigError = null;
$careerEmailCcValue = '';
if ($emailScopeComplete) {
    try {
        $careerEmailCcValue = EmailRecipientList::fromEmailActionCc($sharedEmailCareerAgents);
    } catch (Throwable $exception) {
        AppLogger::exception($exception, [
            'context' => 'career_email_cc_from_agent_actions',
            'career' => $emailScopeCareer,
            'period' => $emailScopePeriod,
        ]);
        $careerEmailConfigError = 'No se pudieron leer los valores Cc actuales de las acciones de correo de Brightspace.';
    }
}
$careerEmailCcEntries = preg_split('/[;,]/', $careerEmailCcValue);
$careerEmailCcRecipientCount = $careerEmailCcValue === ''
    ? 0
    : ($careerEmailCcValue === EmailRecipientList::DYNAMIC_RECIPIENT_TOKEN
        ? 1
        : count(array_filter(
            is_array($careerEmailCcEntries) ? $careerEmailCcEntries : [],
            static fn (string $entry): bool => trim($entry) !== ''
        )));
$careerEmailResumeRunId = $pendingCareerEmailRunId;
$careerEmailResumeFailures = $careerEmailResumeRunId !== ''
    ? (int) ($pendingCareerEmailRun['failed'] ?? 0)
    : 0;
$careerEmailResumeTargetCount = $careerEmailResumeRunId !== ''
    && is_array($pendingCareerEmailRun['targets'] ?? null)
    ? count($pendingCareerEmailRun['targets'])
    : 0;
$bulkAgentCount = count(array_filter(
    $discoveredAgents,
    static fn (array $agent): bool => (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0) > 0
        && (int) ($agent['OrgUnitId'] ?? 0) > 0
));
$scheduleAgentCounts = ['A' => 0, 'B' => 0];
foreach ($allDiscoveredAgents as $agent) {
    $parallel = $agentParallel($agent);
    if (
        !isset($scheduleAgentCounts[$parallel])
        || ($selectedPeriod !== '' && ($agent['period'] ?? null) !== $selectedPeriod)
        || ($selectedCareer !== '' && !CourseCatalogService::careerMatches($agent['career'] ?? null, $selectedCareer))
        || (int) ($agent['AgentId'] ?? $agent['Id'] ?? 0) <= 0
        || (int) ($agent['OrgUnitId'] ?? 0) <= 0
        || !is_array($agent['Schedule'] ?? null)
    ) {
        continue;
    }

    $scheduleAgentCounts[$parallel]++;
}

// A partir de aqui se prepara la vista: estilos, filtros, tarjetas y formularios.
?><!doctype html>
<html lang="es" data-timezone="<?= $escape(date_default_timezone_get()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Revisión | Agentes Inteligentes TESA</title>
    <style>
        :root { color-scheme: light; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; --forest: #4b2a69; --forest-deep: #2c183e; --ink: #241b2e; --muted: #6d6480; --coral: #d6a52a; --coral-dark: #a87808; --mint: #eee7f6; --ivory: #faf9fd; --paper: #ffffff; --line: #d9d0e3; --shadow: 0 8px 20px rgba(75, 42, 105, .08); --card-radius: 12px; --control-radius: 8px; --card-shadow: 0 2px 8px rgba(75, 42, 105, .05); --card-gap: 18px; }
        * { box-sizing: border-box; }
        /* Base de pagina, encabezado y jerarquia de titulos. */
        body { margin: 0; background: var(--ivory); color: var(--ink); -webkit-font-smoothing: antialiased; }
        body::before { content: ''; display: block; height: 4px; background: linear-gradient(90deg, #8c6cab 0%, var(--forest) 48%, var(--forest-deep) 100%); }
        header { background: radial-gradient(circle at 88% 12%, rgba(173, 145, 198, .16), transparent 42%), linear-gradient(140deg, var(--forest) 0%, var(--forest-deep) 100%); color: var(--paper); }
        .topbar, main { max-width: 1280px; margin-left: auto; margin-right: auto; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 18px 30px; }
        .brand { display: flex; align-items: center; gap: 13px; }
        .mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: var(--control-radius); background: linear-gradient(135deg, #efc949, #d7a51d); color: var(--forest-deep); font-weight: 800; font-size: 1.2rem; box-shadow: 0 0 0 2px rgba(255,255,255,.3), 0 8px 18px rgba(214, 165, 42, .28); }
        .brand strong { display: block; font-size: 1.15rem; letter-spacing: .03em; color: var(--paper); }
        .brand span { display: block; margin-top: 2px; color: #fff; font-size: .7rem; letter-spacing: .1em; text-transform: uppercase; }
        .header-actions { display: flex; align-items: center; gap: 14px; }
        .live { display: flex; align-items: center; gap: 8px; color: #f1e9f7; font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; }
        .live::before { content: ''; width: 8px; height: 8px; background: #4ade80; border-radius: 50%; box-shadow: 0 0 0 4px rgba(74, 222, 128, .2); animation: pulse 2.4s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 4px rgba(74, 222, 128, .2); } 50% { box-shadow: 0 0 0 7px rgba(74, 222, 128, .08); } }
        .connect { display: inline-flex; align-items: center; padding: 9px 15px; border-radius: var(--control-radius); border: 1px solid rgba(255, 253, 248, .3); color: var(--paper); font-size: .8rem; font-weight: 600; text-decoration: none; transition: background .18s ease, border-color .18s ease; }
        .connect:hover, .connect:focus-visible { background: var(--paper); border-color: var(--paper); color: var(--forest); }
        h1 { margin: 0; color: var(--paper); font-size: clamp(2.1rem, 5vw, 3.5rem); line-height: 1.06; }
        h2 { margin: 0; font-size: 1.5rem; line-height: 1.15; }
        p { margin-top: 0; }
        .intro { padding: 54px 30px 36px; border-bottom: 1px solid rgba(173, 145, 198, .35); }
        .eyebrow { margin: 0 0 12px; color: var(--coral); font-size: .72rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
        .intro p:last-child { max-width: 640px; margin: 14px 0 0; color: #e5d9ed; font-size: 1rem; line-height: 1.6; }
        main { padding: 30px 30px 72px; }
        .status { margin: 0 0 26px; border-radius: var(--control-radius); background: var(--mint); border-left: 4px solid var(--forest); padding: 13px 18px; color: var(--forest-deep); font-size: .86rem; }
        .error { background: #f8e7e7; border-left-color: #9c2f45; color: #7d2337; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--card-gap); margin-bottom: 42px; }
        .summary-item { padding: 18px 20px; background: var(--paper); border: 1px solid var(--line); border-radius: var(--card-radius); box-shadow: var(--card-shadow); }
        .summary-item strong { display: block; color: var(--forest); font-size: 1.9rem; line-height: 1.1; }
        .summary-item span { color: var(--muted); font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; }
        .section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 20px; margin: 0 0 18px; }
        .section-heading .muted { font-size: .8rem; }
        .campaigns { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--card-gap); margin-bottom: 44px; }
        .campaign { position: relative; display: block; min-height: 142px; padding: 20px 22px; background: var(--paper); border: 1px solid var(--line); border-radius: var(--card-radius); color: var(--ink); text-decoration: none; box-shadow: var(--card-shadow); transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .campaign::before { content: ''; position: absolute; left: 0; top: 14px; bottom: 14px; width: 4px; border-radius: 0 4px 4px 0; background: var(--line); transition: background .18s ease; }
        .campaign:hover, .campaign:focus-visible { transform: translateY(-3px); box-shadow: var(--shadow); border-color: #c9b8dd; }
        .campaign:hover::before { background: var(--coral); }
        .campaign.selected { background: linear-gradient(160deg, #f7f2fc, var(--mint)); border-color: var(--forest); }
        .campaign.selected::before { background: var(--coral); }
        .campaign span { color: var(--muted); font-size: .78rem; }
        .campaign strong { display: block; margin: 12px 0 8px; color: var(--forest); font-size: 2rem; }
        .campaign.selected strong { color: var(--coral-dark); }
        .workspace { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 30px; align-items: start; }
        .agent-panel { max-width: 360px; margin: 0 0 28px auto; background: var(--paper); border: 1px solid var(--line); border-top: 3px solid var(--forest); border-radius: var(--card-radius); padding: 24px; box-shadow: var(--card-shadow); }
        .agent-panel h2 { color: var(--forest); }
        .agent-note { margin: 8px 0 18px; color: var(--muted); font-size: .8rem; line-height: 1.5; }
        .agent-list { display: grid; gap: 12px; }
        .agent-row { padding: 14px 0; border-top: 1px solid var(--line); }
        .agent-row:first-child { border-top: 0; padding-top: 0; }
        .agent-name { display: block; margin-bottom: 5px; font-weight: 700; }
        .agent-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--muted); font-size: .75rem; }
        .agent-actions { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 11px; }
        .agent-actions form { margin: 0; }
        .agent-actions button { margin: 0; padding: 8px 11px; font-size: .72rem; }
        .agent-actions .secondary { background: var(--mint); color: var(--forest); }
        .agent-actions .secondary:hover, .agent-actions .secondary:focus-visible { background: #e0d4ee; }
        .reminder-class-list { display: grid; gap: 16px; }
        .shared-email-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(300px, .8fr);
            align-items: start;
            gap: 20px;
        }
        .shared-email-class-column, .shared-email-editor { min-width: 0; }
        .shared-email-editor {
            border: 1px solid var(--line);
            border-top: 3px solid var(--primary);
            border-radius: var(--card-radius);
            background: var(--paper);
            padding: clamp(15px, 2vw, 21px);
            box-shadow: var(--card-shadow);
        }
        .shared-email-editor h3 {
            display: grid;
            justify-items: start;
            gap: 9px;
            margin: 0;
            padding: 0 0 14px;
            border-bottom: 1px solid var(--line);
            color: var(--primary-strong);
            font-size: 1.08rem;
            letter-spacing: -.02em;
            line-height: 1.25;
        }
        .shared-email-editor h3::before {
            width: 27px;
            height: 3px;
            border-radius: 2px;
            background: var(--accent);
            content: "";
        }
        .shared-email-message-form {
            display: grid;
            gap: 14px;
            margin: 16px 0 0;
            padding: 16px;
            border: 1px solid var(--line);
            border-top: 3px solid var(--accent);
            border-radius: var(--control-radius);
            background: var(--paper);
            box-shadow: var(--card-shadow);
        }
        .shared-email-message-form h4 { margin: 0; color: var(--primary-strong); font-size: .9rem; letter-spacing: -.01em; }
        .shared-email-editor-intro { margin: 12px 0 0; color: var(--text-soft); font-size: .79rem; line-height: 1.55; }
        .shared-email-notice {
            margin: 0 0 16px;
            padding: 10px 12px;
            border: 1px solid #efdfab;
            border-left: 3px solid var(--accent);
            border-radius: 6px;
            background: #fff9e8;
            color: #654d0a;
            font-size: .78rem;
            font-weight: 600;
            line-height: 1.45;
        }
        .shared-email-fields { display: grid; gap: 13px; }
        .shared-email-fields label { display: grid; gap: 7px; color: var(--text); font-size: .78rem; font-weight: 650; }
        .shared-email-fields input, .shared-email-fields textarea {
            width: 100%;
            min-width: 0;
            border: 1px solid #cfc5d8;
            border-radius: 7px;
            background: #fff;
            color: var(--text);
            font: inherit;
            font-size: .88rem;
            transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease;
        }
        .shared-email-fields input { min-height: 44px; padding: 10px 11px; }
        .shared-email-fields textarea { min-height: 210px; padding: 12px; line-height: 1.6; resize: vertical; }
        .shared-email-fields input:hover, .shared-email-fields textarea:hover { border-color: #b9a9c9; }
        .shared-email-fields input:focus-visible, .shared-email-fields textarea:focus-visible {
            outline: 3px solid rgba(67, 39, 95, .18);
            outline-offset: 1px;
            border-color: var(--primary);
            box-shadow: 0 0 0 1px var(--primary);
        }
        .shared-email-fields input:disabled, .shared-email-fields textarea:disabled { background: #f2eff4; color: #625c6a; cursor: not-allowed; }
        .shared-email-updated {
            display: inline-flex;
            max-width: 100%;
            margin: 12px 0 0;
            padding: 6px 9px;
            border: 1px solid #e4dce9;
            border-radius: 5px;
            background: #fff;
            color: var(--text-soft);
            font-size: .7rem;
            line-height: 1.4;
        }
        .shared-email-submit { width: 100%; margin: 0; }
        .shared-email-actions { display: grid; gap: 9px; }
        .shared-email-actions .shared-email-submit { margin: 0; }
        .class-email-recipient .class-email-apply-button { background: var(--forest); }
        .class-email-recipient .class-email-apply-button:hover,
        .class-email-recipient .class-email-apply-button:focus-visible { background: #264d3e; }
        .class-email-recipient .class-email-apply-button:disabled { background: #756d7b; }
        .shared-email-actions .shared-email-apply-button { margin-top: 0; background: var(--forest); }
        .shared-email-actions .shared-email-apply-button:hover,
        .shared-email-actions .shared-email-apply-button:focus-visible { background: #264d3e; }
        .shared-email-actions .shared-email-apply-button:disabled { background: #756d7b; }
        @media (max-width: 900px) { .shared-email-layout { grid-template-columns: minmax(0, 1fr); } }
        .reminder-class { border: 1px solid var(--line); border-left: 3px solid #c5b5d6; border-radius: var(--card-radius); background: var(--paper); padding: 20px; }
        .reminder-class-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 10px 16px; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
        .reminder-class-head h3 { margin: 0; color: var(--forest); font-size: 1rem; }
        .reminder-status { margin: 14px 0; color: var(--muted); font-size: .78rem; }
        .reminder-status strong { color: var(--forest); }
        .reminder-status-active { color: #25624a; }
        .reminder-status-inactive { color: var(--muted); }
        .reminder-class-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 14px; }
        .reminder-class-actions form { margin: 0; }
        .reminder-class-actions button { margin: 0; }
        .reminder-class-actions form[data-action-label="activar"] button:not(:disabled) { background: #16803c; }
        .reminder-class-actions form[data-action-label="activar"] button:not(:disabled):hover,
        .reminder-class-actions form[data-action-label="activar"] button:not(:disabled):focus-visible { background: #126b32; }
        .class-deactivate { background: #9a2d41; color: #fff; }
        .class-deactivate:hover, .class-deactivate:focus-visible { background: #7d2337; }
        .reminder-class-actions button:disabled { background: #756d7b; color: #fff; cursor: not-allowed; opacity: .75; }
        .class-email-recipient {
            display: grid;
            gap: 12px;
            margin: 16px 0 0;
            padding: 16px;
            border: 1px solid var(--line);
            border-left: 3px solid var(--forest);
            border-radius: var(--control-radius);
            background: var(--paper);
            box-shadow: var(--card-shadow);
        }
        .class-email-recipient h4 {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            color: var(--forest-deep);
            font-size: .9rem;
            letter-spacing: -.01em;
        }
        .class-email-recipient h4::before { width: 7px; height: 7px; border-radius: 50%; background: var(--forest); content: ""; }
        .class-email-recipient label { display: grid; gap: 7px; color: var(--text); font-size: .77rem; font-weight: 650; }
        .class-email-recipient select { width: 100%; min-height: 44px; padding: 9px 34px 9px 11px; border: 1px solid #cfc5d8; border-radius: 7px; background: #fff; color: var(--ink); font: inherit; font-size: .87rem; font-weight: 400; transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease; }
        .class-email-recipient select:hover { border-color: #b9a9c9; }
        .class-email-recipient select:focus-visible { outline: 3px solid rgba(67, 39, 95, .18); outline-offset: 1px; border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
        .class-email-recipient select:disabled { background: #f2eff4; color: #625c6a; cursor: not-allowed; }
        .class-email-recipient .muted { font-size: .72rem; font-weight: 400; line-height: 1.45; }
        .class-email-recipient label > input { width: 100%; min-width: 0; min-height: 44px; padding: 10px 11px; border: 1px solid #cfc5d8; border-radius: 7px; background: #fff; color: var(--ink); font: inherit; font-size: .87rem; transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease; }
        .class-email-recipient label > input:hover { border-color: #b9a9c9; }
        .class-email-recipient label > input:focus-visible { outline: 3px solid rgba(67, 39, 95, .18); outline-offset: 1px; border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
        .class-email-recipient label > input:disabled { background: #f2eff4; color: #625c6a; cursor: not-allowed; }
        .class-email-recipient label > textarea { width: 100%; min-width: 0; min-height: 88px; padding: 10px 11px; border: 1px solid #cfc5d8; border-radius: 7px; background: #fff; color: var(--ink); font: inherit; font-size: .87rem; line-height: 1.5; resize: vertical; transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease; }
        .class-email-recipient label > textarea:hover { border-color: #b9a9c9; }
        .class-email-recipient label > textarea:focus-visible { outline: 3px solid rgba(67, 39, 95, .18); outline-offset: 1px; border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
        .class-email-recipient label > textarea:disabled { background: #f2eff4; color: #625c6a; cursor: not-allowed; }
        .class-email-recipient label > textarea[aria-invalid="true"] { border-color: #a92d45; }
        .class-email-recipient .cc-recipient-count,
        .class-email-recipient .cc-recipient-hint { margin: -6px 0 0; color: var(--text-soft); font-size: .72rem; line-height: 1.45; }
        .class-email-cc-validation { margin: -7px 0 0; padding: 8px 10px; border-left: 3px solid #a92d45; border-radius: 4px; background: #fff1f2; color: #84253a; font-size: .74rem; line-height: 1.45; }
        .class-email-cc-validation[hidden] { display: none; }
        .class-email-apply-button { justify-self: start; min-height: 42px; margin: 0; padding: 10px 14px; font-size: .77rem; transition: background-color .16s ease, box-shadow .16s ease, transform .12s ease; }
        .class-email-apply-button:focus-visible, .shared-email-apply-button:focus-visible { outline: 3px solid rgba(67, 39, 95, .22); outline-offset: 3px; }
        .class-email-apply-button:disabled, .shared-email-apply-button:disabled { color: #fff; cursor: not-allowed; opacity: .8; }
        .class-email-progress { min-height: 0; margin: 0; color: var(--text-soft); font-size: .75rem; line-height: 1.5; white-space: pre-line; }
        .class-email-progress:not(:empty) { padding: 9px 11px; border-left: 3px solid var(--forest); border-radius: 5px; background: #f3eff7; }
        .class-email-recipient .error { margin: 0; padding: 9px 11px; border-radius: 5px; font-size: .75rem; line-height: 1.45; }
        .class-email-recipient > .muted { margin: 0; padding: 9px 10px; border-left: 2px solid var(--accent); background: #faf7fc; font-size: .75rem; line-height: 1.45; }
        @media (max-width: 540px) {
            .shared-email-editor { padding: 14px; }
            .class-email-recipient, .shared-email-message-form { padding: 13px; }
            .class-email-apply-button, .shared-email-apply-button { width: 100%; }
            .class-email-apply-button { justify-self: stretch; }
            .shared-email-fields textarea { min-height: 190px; }
        }
        .schedule-actions-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px 18px; margin-top: 12px; }
        .schedule-actions-layout.has-schedule { grid-template-columns: minmax(0, 1fr) max-content; align-items: center; }
        .schedule-column, .actions-column { min-width: 0; }
        .schedule-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
        .schedule-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start; gap: 8px 12px; padding: 12px; border: 1px solid var(--line); border-radius: var(--control-radius); background: var(--paper); box-shadow: 0 3px 10px rgba(45, 26, 61, .05); }
        .schedule-form h3 { grid-column: 1 / -1; margin: 0; color: var(--forest); font-size: .9rem; line-height: 1.25; }
        .schedule-form-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; min-width: 0; }
        .schedule-date-field { position: relative; min-width: 0; }
        .schedule-date-field > label { display: block; margin: 0 0 4px; color: var(--muted); font-size: .72rem; font-weight: 700; letter-spacing: 0; text-transform: none; }
        .schedule-date-control { position: relative; display: flex; min-width: 0; }
        .schedule-date-control input[data-date-display] { width: 100%; min-width: 0; min-height: 42px; padding: 8px 42px 8px 10px; border: 1px solid #cfc2dc; border-radius: var(--control-radius); background: #fff; color: var(--ink); font: inherit; font-size: .84rem; transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease; }
        .schedule-date-control input[data-date-display]:hover { border-color: #a991be; background-color: #fdfbff; }
        .schedule-date-control input[data-date-display]:focus-visible,
        .schedule-date-control:focus-within input[data-date-display] { outline: 3px solid rgba(75, 42, 105, .2); outline-offset: 2px; border-color: var(--forest); box-shadow: 0 0 0 1px var(--forest); }
        .schedule-date-trigger { position: absolute; top: 3px; right: 3px; display: grid; width: 36px; height: 36px; place-items: center; padding: 0; border: 0; border-radius: 7px; background: var(--mint); color: var(--forest); cursor: pointer; }
        .schedule-date-trigger:hover, .schedule-date-trigger:focus-visible { background: #e0d4ee; }
        .schedule-date-trigger svg { width: 19px; height: 19px; }
        .schedule-date-field.is-open { z-index: 20; }
        .panel.has-open-calendar { position: relative; z-index: 10; }
        .date-popover { position: absolute; z-index: 30; top: calc(100% + 8px); left: 0; width: min(268px, calc(100vw - 20px)); padding: 12px; border: 1px solid #e1d6ec; border-radius: 10px; background: #fff; box-shadow: 0 14px 28px rgba(45, 26, 61, .16); color: var(--ink); }
        .schedule-date-field:last-child .date-popover { right: 0; left: auto; }
        .date-popover[hidden] { display: none; }
        .date-calendar-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
        .date-calendar-title { color: var(--forest); font-size: .82rem; font-weight: 700; text-transform: capitalize; }
        .date-month-nav { display: flex; gap: 4px; }
        .date-month-nav button, .date-calendar-footer button { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; min-height: 36px; margin: 0; padding: 3px 6px; border: 1px solid #e6deee; border-radius: 6px; background: #fff; color: var(--forest); font: inherit; font-size: .72rem; cursor: pointer; }
        .date-month-nav button:hover, .date-month-nav button:focus-visible, .date-calendar-footer button:hover, .date-calendar-footer button:focus-visible { background: #f5eff9; border-color: #c9b8dd; }
        .date-weekdays, .date-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 2px; text-align: center; }
        .date-weekdays { margin-bottom: 4px; color: var(--muted); font-size: .62rem; font-weight: 700; }
        .date-weekdays span { padding: 4px 0; }
        .date-day { display: grid; width: 100%; min-height: 36px; aspect-ratio: 1; place-items: center; margin: 0; padding: 0; border: 1px solid transparent; border-radius: 6px; background: transparent; color: var(--ink); font: inherit; font-size: .72rem; cursor: pointer; }
        .date-day:hover, .date-day:focus-visible { border-color: #d9c8e8; background: #f6f0fa; outline: none; }
        .date-day.is-outside { color: #aaa2b0; }
        .date-day.is-today:not(.is-selected) { border-color: #c8b3dc; color: var(--forest); font-weight: 700; }
        .date-day.is-selected { border-color: var(--forest); background: var(--forest); color: #fff; font-weight: 700; }
        .date-calendar-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; padding-top: 8px; border-top: 1px solid #eee8f3; }
        .date-calendar-footer button { min-height: 36px; }
        @media (max-width: 640px) {
            .date-popover { width: min(262px, calc(100vw - 16px)); padding: 10px; }
            .date-month-nav button, .date-calendar-footer button { min-width: 40px; min-height: 40px; }
            .date-day { min-height: 40px; aspect-ratio: auto; }
        }
        .schedule-form button { justify-self: start; margin-top: 0; }
        .schedule-form [data-schedule-submit] { grid-column: 2; align-self: end; min-height: 42px; padding: 9px 14px; border: 1px solid var(--forest); border-radius: var(--control-radius); background: var(--forest); color: #fff; font-size: .78rem; box-shadow: 0 2px 5px rgba(45, 26, 61, .12); transition: background .18s ease, box-shadow .18s ease, transform .12s ease; }
        .schedule-form [data-schedule-submit]:not(:disabled):hover,
        .schedule-form [data-schedule-submit]:not(:disabled):focus-visible { background: #3a2055; box-shadow: 0 3px 8px rgba(45, 26, 61, .2); }
        .schedule-form [data-schedule-submit]:focus-visible { outline: 3px solid rgba(75, 42, 105, .22); outline-offset: 2px; }
        .schedule-form button:disabled { opacity: .55; cursor: not-allowed; }
        .schedule-form [data-schedule-submit]:disabled { cursor: wait; }
        #schedule-progress { margin-top: 12px; }
        .schedule-actions-layout.has-schedule .actions-column .quick-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .schedule-actions-layout.has-schedule .actions-column .quick-actions form { flex: 0 1 max-content; min-width: 0; }
        .schedule-actions-layout.has-schedule .actions-column .quick-actions form button { width: auto; max-width: 100%; padding: 9px 12px; font-size: .76rem; white-space: normal; overflow-wrap: anywhere; }
        .schedule-actions-layout.has-schedule .actions-column #bulk-progress,
        .schedule-actions-layout.has-schedule .actions-column #inventory-refresh-progress { margin: 12px 0 0; }
        .agent-schedules { margin-top: 16px; border-top: 1px solid var(--line); padding-top: 12px; }
        .agent-schedules summary { color: var(--forest); cursor: pointer; font-size: .82rem; font-weight: 700; }
        .agent-schedules table { width: 100%; min-width: 540px; margin-top: 12px; }
        .agent-schedules th, .agent-schedules td { padding: 9px 10px; font-size: .78rem; }
        .agent-schedules .course-activity-select { width: 18px; height: 18px; margin: 0; accent-color: var(--forest); }
        .agent-schedules .course-activity-days { width: 86px; min-height: 36px; padding: 6px 8px; }
        .course-activity-form > button { min-height: 38px; margin-top: 10px; padding: 8px 12px; font-size: .76rem; background: var(--forest); color: #fff; }
        .course-activity-form > button:disabled { opacity: .55; cursor: not-allowed; }
        .course-activity-progress { margin: 8px 0 0; font-size: .75rem; }
        @media (max-width: 980px) {
            .schedule-actions-layout.has-schedule { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 640px) {
            .reminder-class { padding: 16px; }
            .schedule-grid { grid-template-columns: 1fr; }
            .schedule-form { grid-template-columns: minmax(0, 1fr); }
            .schedule-form-fields { grid-template-columns: 1fr; }
            .schedule-form [data-schedule-submit] { grid-column: 1; }
        }
        .review-copy { margin: 7px 0 22px; color: var(--muted); font-size: .86rem; }
        .composer { background: var(--paper); border: 1px solid var(--line); border-top: 3px solid var(--forest); border-radius: var(--card-radius); padding: 24px; margin: 0 0 22px; box-shadow: var(--card-shadow); }
        .composer h2 { color: var(--forest); }
        label { display: block; margin: 16px 0 6px; color: var(--forest); font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        input, textarea { width: 100%; border: 1px solid #cdc2dc; border-radius: var(--control-radius); padding: 11px 13px; font: inherit; color: var(--ink); background: #fff; }
        input:focus, textarea:focus { outline: 3px solid rgba(75, 42, 105, .18); border-color: var(--forest); }
        textarea { min-height: 150px; resize: vertical; line-height: 1.5; }
        button { border: 0; border-radius: var(--control-radius); padding: 12px 18px; margin-top: 18px; background: var(--coral); color: #fff; cursor: pointer; font: inherit; font-size: .84rem; font-weight: 700; transition: background .18s ease, transform .12s ease; }
        button:hover, button:focus-visible { background: var(--coral-dark); }
        button:active { transform: translateY(1px); }
        .table-wrap { overflow-x: auto; background: var(--paper); border: 1px solid var(--line); border-radius: var(--card-radius); box-shadow: var(--card-shadow); }
        table { width: 100%; border-collapse: collapse; min-width: 720px; font-size: .84rem; }
        th, td { text-align: left; padding: 13px 15px; border-bottom: 1px solid #ece5f3; }
        th { background: var(--mint); color: var(--forest); font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: #fbf9ff; }
        .muted { color: var(--muted); }
        /* Panel de estructura academica */
        .structure { margin-bottom: 46px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; align-items: start; }

        /* Pestanas desplegables de periodo, carrera, modulo y seccion A/B */
        .filter-card { background: var(--paper); border: 1px solid var(--line); border-top: 3px solid var(--forest); border-radius: var(--card-radius); box-shadow: var(--card-shadow); overflow: hidden; }
        .filter-card[open] { box-shadow: var(--card-shadow); }
        .filter-card > summary { display: flex; align-items: center; gap: 10px; padding: 15px 18px; cursor: pointer; list-style: none; user-select: none; }
        .filter-card > summary::-webkit-details-marker { display: none; }
        .filter-card > summary:hover, .filter-card > summary:focus-visible { background: var(--mint); }
        .filter-label { color: var(--muted); font-size: .68rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
        .filter-value { flex: 1; color: var(--forest); font-size: 1.05rem; font-weight: 600; }
        .filter-caret { color: var(--coral); font-size: .8rem; transition: transform .2s ease; }
        .filter-card[open] .filter-caret { transform: rotate(180deg); }
        .filter-card .hint { margin: 0 18px 12px; color: var(--muted); font-size: .74rem; }
        .filter-card .filter-list { padding: 0 18px 18px; }

        .filter-list { display: flex; flex-wrap: wrap; gap: 7px; max-height: 236px; overflow-y: auto; }
        .filter-chip { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--line); border-radius: 999px; background: var(--ivory); padding: 7px 13px; color: var(--ink); font-size: .78rem; text-decoration: none; transition: background .15s ease, border-color .15s ease, color .15s ease; }
        .filter-chip:hover, .filter-chip:focus-visible { background: var(--mint); border-color: var(--forest); }
        .filter-chip.active { background: var(--forest); border-color: var(--forest); color: var(--paper); }
        .filter-chip.active .count { color: #d9c9e8; }
        .filter-chip .count { color: var(--muted); font-size: .7rem; }
        .filter-chip.all { font-weight: 700; }
        .filter-empty { color: var(--muted); font-size: .78rem; font-style: italic; }
        .breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 22px; color: var(--muted); font-size: .8rem; }
        .breadcrumb a { color: var(--forest); text-decoration: none; font-weight: 700; }
        .breadcrumb a:hover, .breadcrumb a:focus-visible { text-decoration: underline; }
        .breadcrumb .sep { color: var(--coral); }
        .breadcrumb .current { color: var(--ink); font-weight: 700; }
        .inactivity { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: var(--card-gap); margin: 0 0 26px; }
        .inactivity-item { background: var(--paper); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 16px 18px; box-shadow: var(--card-shadow); }
        .inactivity-item strong { display: block; color: var(--coral-dark); font-size: 1.6rem; line-height: 1.1; }
        .inactivity-item span { color: var(--muted); font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; }
        .agent-group-title { margin: 0; color: var(--forest); font-size: 1.15rem; font-weight: 700; }
        .agent-bindings { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--card-gap); }
        .agent-binding { background: var(--paper); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 18px 20px; box-shadow: var(--card-shadow); }
        .agent-binding-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
        .agent-binding-head strong { color: var(--forest); font-size: .95rem; }
        .agent-binding-count { color: var(--muted); font-size: .72rem; }
        .course-details { margin-top: 26px; border: 1px solid var(--line); border-radius: var(--card-radius); background: var(--paper); overflow: hidden; }
        .course-details > summary { padding: 15px 20px; color: var(--forest); cursor: pointer; font-size: .8rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .course-details > summary:hover, .course-details > summary:focus-visible { background: var(--mint); }
        .course-details .table-wrap { border: 0; border-top: 1px solid var(--line); border-radius: 0; box-shadow: none; }

        /* Paralelos A/B */
        .parallel-strip { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 20px; }
        .parallel-pill { display: inline-flex; align-items: center; gap: 9px; border: 1px solid var(--line); border-radius: 999px; background: var(--paper); padding: 8px 16px; color: var(--ink); font-size: .8rem; text-decoration: none; transition: border-color .15s ease, background .15s ease; }
        .parallel-pill:hover, .parallel-pill:focus-visible { border-color: var(--forest); background: var(--mint); }
        .parallel-pill.active { background: var(--forest); border-color: var(--forest); color: var(--paper); }
        .parallel-pill.active .count { color: #d9c9e8; }
        .parallel-pill .badge { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: var(--coral); color: #2c183e; font-size: .72rem; font-weight: 700; }
        .parallel-pill.active .badge { background: var(--paper); color: var(--forest); }
        .parallel-pill .count { color: var(--muted); font-size: .72rem; }
        .parallel-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--card-gap); margin: 0 0 26px; }
        .parallel-column { background: var(--paper); border: 1px solid var(--line); border-top: 3px solid var(--forest); border-radius: var(--card-radius); box-shadow: var(--card-shadow); overflow: hidden; }
        .parallel-column[data-parallel="A"] { border-top-color: var(--forest); }
        .parallel-column[data-parallel="B"] { border-top-color: var(--coral); }
        .parallel-column-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 20px; border-bottom: 1px solid var(--line); background: linear-gradient(160deg, #fbf9ff, var(--paper)); }
        .parallel-column-head h3 { margin: 0; color: var(--forest); font-size: 1.15rem; }
        .parallel-column-head .metrics { display: flex; gap: 14px; color: var(--muted); font-size: .72rem; }
        .parallel-column-head .metrics b { color: var(--ink); font-size: .86rem; }
        .parallel-column-body { max-height: 340px; overflow-y: auto; }
        .parallel-course { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 11px 20px; border-bottom: 1px solid #f1ecf6; font-size: .8rem; }
        .parallel-course:last-child { border-bottom: 0; }
        .parallel-course .name { color: var(--ink); }
        .parallel-course .code { display: block; color: var(--muted); font-size: .68rem; letter-spacing: .03em; }
        .parallel-course .count { flex-shrink: 0; border-radius: 999px; background: var(--mint); color: var(--forest); padding: 3px 9px; font-size: .68rem; font-weight: 700; }
        .parallel-course.empty { color: var(--muted); font-style: italic; }

        /* Clasificacion de agentes */
        .agent-matrix { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--card-gap); margin: 0 0 26px; }
        .agent-card { background: var(--paper); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 22px; box-shadow: none; }
        .agent-card-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
        .agent-card-head .title { display: flex; align-items: center; gap: 10px; }
        .agent-card-head h3 { margin: 0; color: var(--forest); font-size: .98rem; }
        .agent-scope { display: block; margin: -4px 0 10px; color: var(--muted); font-size: .68rem; letter-spacing: .03em; }
        .agent-count-badge { border-radius: 999px; background: var(--forest); color: var(--paper); padding: 2px 9px; font-size: .66rem; font-weight: 700; }
        .threshold-dot { display: grid; place-items: center; width: 30px; height: 30px; border-radius: var(--control-radius); background: var(--mint); color: var(--forest); font-size: .82rem; font-weight: 700; }
        .status-pill { border-radius: 999px; padding: 3px 10px; font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .status-pill.on { background: #dcf5e5; color: #14713c; }
        .status-pill.off { background: #f2ecf7; color: #6d6480; }
        .tag-row { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }
        .tag { border-radius: 6px; background: var(--ivory); border: 1px solid var(--line); padding: 3px 8px; color: var(--muted); font-size: .68rem; }
        .tag.parallel-a { background: #efe7f8; border-color: #cdb9e6; color: #4b2a69; font-weight: 700; }
        .tag.parallel-b { background: #fbf1d9; border-color: #e8cf95; color: #8a6205; font-weight: 700; }
        .scope-line { margin: 6px 0 0; color: var(--muted); font-size: .74rem; }

        /* Ubicacion academica de cada agente */
        .location-details { margin-top: 14px; border: 1px solid var(--line); border-radius: var(--control-radius); overflow: hidden; }
        .location-details > summary { padding: 9px 13px; color: var(--forest); cursor: pointer; font-size: .72rem; font-weight: 700; }
        .location-details > summary:hover, .location-details > summary:focus-visible { background: var(--mint); }
        .location-list { max-height: 260px; overflow-y: auto; border-top: 1px solid var(--line); }
        .location-row { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; padding: 8px 13px; border-bottom: 1px solid #f4eff9; font-size: .74rem; }
        .location-row:last-child { border-bottom: 0; }
        .location-name { color: var(--ink); }
        .location-code { display: block; color: var(--muted); font-size: .66rem; letter-spacing: .02em; }
        .location-state { border-radius: 999px; background: var(--ivory); border: 1px solid var(--line); padding: 2px 8px; color: var(--muted); font-size: .64rem; }
        .agent-binding-count { margin: 0 0 12px; color: var(--muted); font-size: .72rem; }
        a:focus-visible, button:focus-visible { outline: 3px solid var(--coral); outline-offset: 3px; }
        @keyframes reveal { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .intro, main > * { animation: reveal .45s ease both; }
        main > *:nth-child(2) { animation-delay: .06s; }
        main > *:nth-child(3) { animation-delay: .12s; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
        @media (max-width: 820px) { .workspace { grid-template-columns: 1fr; } .parallel-column-body { max-height: none; } }
        @media (max-width: 640px) { .topbar, main { padding-left: 18px; padding-right: 18px; } .topbar { align-items: flex-start; } .header-actions { align-items: flex-end; flex-direction: column-reverse; gap: 10px; } .live { font-size: .65rem; } .connect { padding: 8px 10px; font-size: .75rem; } .intro { padding: 40px 18px 28px; } .summary { margin-bottom: 34px; } .campaigns { grid-template-columns: 1fr; } .section-heading { align-items: flex-start; flex-direction: column; gap: 5px; } .composer { padding: 18px; } .parallel-grid, .agent-matrix { grid-template-columns: 1fr; } }
    </style>
    <?= $dashboardStyles ?>
</head>
    <body>
    <header class="topbar">
        <div class="brand">
            <img class="brand-mark" src="/assets/logo-tesa.png" alt="Instituto Tecnológico San Antonio">
            <div>
                <strong>TESA</strong>
                <span>Agentes Inteligentes</span>
            </div>
        </div>
        <div class="header-actions">
            <?php if (is_admin_authenticated()): ?>
                <a class="btn ghost" href="/users.php">Administrar usuarios</a>
            <?php endif; ?>
            <span><?= htmlspecialchars((string) ($_SESSION['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            <a class="btn logout" href="/logout.php">Cerrar sesión</a>
        </div>
    </header>

    <main class="page-shell">
        <!-- Encabezado del panel y mensajes de estado del inventario/conexion. -->
        <section class="hero">
            <h1>Control de agentes</h1>
        </section>

        <?php if ($message !== null): ?>
            <p class="status<?= $messageType === 'error' ? ' error' : '' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>" aria-live="<?= $messageType === 'error' ? 'assertive' : 'polite' ?>"><?= $escape($message) ?></p>
        <?php elseif (($_GET['oauth'] ?? '') === 'success'): ?>
            <p class="status">Brightspace fue conectado correctamente.</p>
        <?php endif; ?>

        <?php if ($inventoryStatus !== null && $inventoryStatus['complete'] !== true): ?>
            <p class="status error" role="alert">
                <?php if ($inventoryStatus['complete'] === false): ?>
                    Inventario incompleto: se completaron <?= (int) $inventoryStatus['scanned_org_unit_count'] ?> de <?= (int) ($inventoryStatus['requested_org_unit_count'] ?? 0) ?> unidades organizativas; <?= count($inventoryStatus['failed_org_unit_ids']) ?> consultas fallaron. No se puede confirmar que estén todos los agentes. Revisa el registro de la aplicación para distinguir errores de conexión de respuestas de permisos (HTTP 403).
                <?php else: ?>
                    El inventario aún no tiene una verificación completa. Se actualizará en segundo plano antes de habilitar cambios masivos.
                <?php endif; ?>
            </p>
        <?php elseif ($inventoryStatus !== null && $inventoryStatus['fresh'] !== true): ?>
            <p class="status error" role="alert">
                La copia guardada del inventario venció. Se actualizará en segundo plano; los cambios masivos estarán disponibles cuando termine.
            </p>
        <?php endif; ?>

        <!-- Filtros academicos y controles para asignar fechas al modulo elegido. -->
        <section class="panel" aria-label="Estructura académica">
            <div class="panel-head">
                <h2>Filtrar estructura</h2>
                <span>Período → carrera → paralelo A/B</span>
            </div>

            <form method="get" class="filters" aria-label="Filtros académicos">
                <div class="filter-group">
                    <label for="filter-period">Período / Semestre</label>
                    <select class="filter-control" id="filter-period" name="period">
                        <option value="">Todos los períodos</option>
                        <?php foreach ($periods as $periodOption): ?>
                            <option value="<?= $escape($periodOption['period']) ?>" <?= $periodOption['period'] === $selectedPeriod ? 'selected' : '' ?>><?= $escape($periodOption['label'] ?? $periodOption['period']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter-career">Carrera</label>
                    <select class="filter-control" id="filter-career" name="career">
                        <option value="">Todas las carreras</option>
                        <?php foreach ($careers as $careerOption): ?>
                            <option value="<?= $escape($careerOption['career']) ?>" <?= $careerOption['career'] === $selectedCareer ? 'selected' : '' ?>><?= $escape($careerOption['label'] ?? $careerOption['career']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter-module">Paralelo</label>
                    <select class="filter-control" id="filter-module" name="parallel">
                        <option value="">Todos los paralelos</option>
                        <?php foreach ($parallelOptions as $parallelOption): ?>
                            <option value="<?= $escape($parallelOption['parallel']) ?>" <?= $parallelOption['parallel'] === $selectedParallel ? 'selected' : '' ?>><?= $escape($parallelOption['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="filter-submit" type="submit">Aplicar filtros</button>
                </div>
            </form>

            <?php $hasSelectedSchedule = in_array($selectedParallel, ['A', 'B'], true); ?>
            <div class="schedule-actions-layout<?= $hasSelectedSchedule ? ' has-schedule' : '' ?>">
                <?php if ($hasSelectedSchedule): ?>
                <div class="schedule-column">
                <div class="schedule-grid" aria-label="Asignar fechas de cronograma del módulo seleccionado">
                <?php foreach (['A' => 'Módulo A', 'B' => 'Módulo B'] as $parallel => $parallelLabel): ?>
                    <?php if ($parallel !== $selectedParallel) { continue; } ?>
                    <form method="post" class="schedule-form" data-schedule-form data-parallel="<?= $parallel ?>" data-count="<?= $scheduleAgentCounts[$parallel] ?>" data-ready="<?= $inventoryStatus !== null && $inventoryStatus['complete'] === true && $inventoryStatus['fresh'] === true ? 'true' : 'false' ?>">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="schedule_action" value="update_dates">
                        <input type="hidden" name="schedule_parallel" value="<?= $parallel ?>">
                        <input type="hidden" name="schedule_offset" value="0">
                        <input type="hidden" name="schedule_run_id" value="">
                        <h3><?= $parallelLabel ?></h3>
                        <div class="schedule-form-fields">
                            <?php foreach (['start' => ['label' => 'Fecha de inicio', 'name' => 'start_date'], 'end' => ['label' => 'Fecha final', 'name' => 'end_date']] as $dateKey => $dateField): ?>
                                <?php $dateId = 'schedule-' . $dateKey . '-' . $parallel; ?>
                                <div class="schedule-date-field" data-date-picker>
                                    <label for="<?= $dateId ?>-display"><?= $dateField['label'] ?></label>
                                    <div class="schedule-date-control">
                                        <input id="<?= $dateId ?>-display" type="text" data-date-display required inputmode="numeric" autocomplete="off" placeholder="DD/MM/AAAA" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?= $dateId ?>-calendar">
                                        <input id="<?= $dateId ?>" type="hidden" name="<?= $dateField['name'] ?>" data-date-value>
                                        <button class="schedule-date-trigger" type="button" data-calendar-trigger aria-label="Abrir calendario para <?= strtolower($dateField['label']) ?>" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?= $dateId ?>-calendar">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <rect x="3.5" y="5" width="17" height="15.5" rx="2" stroke="currentColor" stroke-width="1.7"/>
                                                <path d="M7.5 3.5v3M16.5 3.5v3M4 9.5h16M8 13h2m4 0h2M8 16.5h2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="date-popover" id="<?= $dateId ?>-calendar" data-calendar-popover role="dialog" aria-label="Calendario: <?= strtolower($dateField['label']) ?>" hidden>
                                        <div class="date-calendar-head">
                                            <strong class="date-calendar-title" data-calendar-title aria-live="polite"></strong>
                                            <div class="date-month-nav">
                                                <button type="button" data-calendar-previous aria-label="Mes anterior">‹</button>
                                                <button type="button" data-calendar-next aria-label="Mes siguiente">›</button>
                                            </div>
                                        </div>
                                        <div class="date-weekdays" aria-hidden="true">
                                            <span>Lu</span><span>Ma</span><span>Mi</span><span>Ju</span><span>Vi</span><span>Sá</span><span>Do</span>
                                        </div>
                                        <div class="date-days" data-calendar-days></div>
                                        <div class="date-calendar-footer">
                                            <button type="button" data-calendar-today>Hoy</button>
                                            <button type="button" data-calendar-close>Cerrar</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" data-schedule-submit <?= $scheduleAgentCounts[$parallel] === 0 || $inventoryStatus === null || $inventoryStatus['complete'] !== true || $inventoryStatus['fresh'] !== true ? 'disabled' : '' ?>>Aplicar a <?= $parallelLabel ?></button>
                    </form>
                <?php endforeach; ?>
                </div>
                <p id="schedule-progress" class="muted" aria-live="polite"></p>
                </div>
                <?php endif; ?>
                <div class="actions-column">
                    <div class="quick-actions">
                        <form method="post" data-bulk-form data-action-label="activar" data-count="<?= $bulkAgentCount ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="bulk_action" value="activate_selection">
                            <input type="hidden" name="bulk_offset" value="0">
                            <input type="hidden" name="bulk_run_id" value="">
                            <button type="submit" <?= $bulkAgentCount === 0 ? 'disabled' : '' ?>>Activar agentes</button>
                        </form>
                        <form method="post" data-bulk-form data-action-label="desactivar" data-count="<?= $bulkAgentCount ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="bulk_action" value="deactivate_selection">
                            <input type="hidden" name="bulk_offset" value="0">
                            <input type="hidden" name="bulk_run_id" value="">
                            <button type="submit" class="secondary" <?= $bulkAgentCount === 0 ? 'disabled' : '' ?>>Desactivar agentes</button>
                        </form>
                        <form method="post" data-inventory-refresh-form data-auto-refresh="<?= $inventoryStatus === null || $inventoryStatus['fresh'] !== true ? 'true' : 'false' ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="inventory_refresh_action" value="start">
                            <button class="inventory-refresh-button" type="submit">Actualizar inventario</button>
                        </form>
                    </div>
                    <p id="bulk-progress" class="muted" aria-live="polite"></p>
                    <p id="inventory-refresh-progress" class="muted" aria-live="polite"></p>
                </div>
            </div>
            <noscript><p class="muted">Activa JavaScript para procesar la activación, desactivación y actualización masiva de fechas de cronograma.</p></noscript>
            <noscript><p class="muted">Activa JavaScript para actualizar el inventario sin interrumpir la página.</p></noscript>
            <noscript><p class="muted">Activa JavaScript para procesar las fechas del cronograma por lotes.</p></noscript>
        </section>

        <!-- Tarjetas de clase: acciones de agentes, cronogramas y umbrales de actividad. -->
        <section class="panel" aria-labelledby="class-agents-title">
            <div class="panel-head">
                <h2 id="class-agents-title">Agentes por clase</h2>
                <span>Todos los agentes de cada clase se controlan juntos.</span>
            </div>

            <div class="shared-email-layout">
                <div class="shared-email-class-column">
            <?php if ($classReminderGroups === []): ?>
                <p class="muted">No se encontraron agentes para estos filtros.</p>
            <?php else: ?>
                <div class="reminder-class-list">
                    <?php foreach ($classReminderGroups as $reminderGroup): ?>
                        <?php
                        $orgUnitId = (int) $reminderGroup['org_unit_id'];
                        $activeCount = (int) $reminderGroup['active_count'];
                        $classAgentCount = count($reminderGroup['agents']);
                        $inactiveCount = $classAgentCount - $activeCount;
                        $activateAvailable = $inactiveCount > 0
                            && $inventoryStatus !== null
                            && $inventoryStatus['complete'] === true
                            && $inventoryStatus['fresh'] === true;
                        $deactivateAvailable = $activeCount > 0
                            && $inventoryStatus !== null
                            && $inventoryStatus['complete'] === true
                            && $inventoryStatus['fresh'] === true;
                        $courseName = trim($reminderGroup['course_name']) !== ''
                            ? $reminderGroup['course_name']
                            : 'Clase Brightspace';
                        $courseCode = trim($reminderGroup['course_code']);
                        $appendCourseCode = $courseCode !== ''
                            && stripos($courseName, $courseCode) === false;
                        ?>
                        <article
                            class="reminder-class"
                            id="reminder-class-<?= $escape($orgUnitId) ?>"
                            data-active-count="<?= $activeCount ?>"
                            data-agent-count="<?= $classAgentCount ?>"
                        >
                            <div class="reminder-class-head">
                                <h3><?= $escape($courseName) ?><?= $appendCourseCode ? ' · ' . $escape($courseCode) : '' ?></h3>
                            </div>

                            <p class="reminder-status" aria-label="Estado general de los agentes de esta clase">
                                <strong>Estado:</strong>
                                <span class="reminder-status-<?= $activeCount > 0 ? 'active' : 'inactive' ?>">Agentes <?= $activeCount > 0 ? 'Activos' : 'Inactivos' ?></span>
                            </p>

                            <div class="reminder-class-actions">
                                <form method="post" data-class-reminder-form data-class-action="activate" data-course-name="<?= $escape($courseName) ?>" data-action-label="activar" data-action-count="<?= $inactiveCount ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="class_action" value="activate">
                                    <input type="hidden" name="org_unit_id" value="<?= $escape($orgUnitId) ?>">
                                    <button
                                        type="submit"
                                        aria-label="Activar los agentes de <?= $escape($courseName) ?>"
                                        <?= $activateAvailable ? 'data-confirmable="true" disabled' : 'disabled' ?>
                                    >Activar</button>
                                </form>
                                <form method="post" data-class-reminder-form data-class-action="deactivate" data-course-name="<?= $escape($courseName) ?>" data-action-label="desactivar" data-action-count="<?= $activeCount ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="class_action" value="deactivate">
                                    <input type="hidden" name="org_unit_id" value="<?= $escape($orgUnitId) ?>">
                                    <button
                                        type="submit"
                                        class="class-deactivate"
                                        aria-label="Desactivar los agentes de <?= $escape($courseName) ?>"
                                        <?= $deactivateAvailable ? 'data-confirmable="true" disabled' : 'disabled' ?>
                                    >Desactivar</button>
                                </form>
                            </div>
                            <!-- Tabla por agente: fechas existentes y seleccion para editar actividad. -->
                            <details class="agent-schedules">
                                <summary>Ver fechas de cronograma (<?= count($reminderGroup['agents']) ?> agentes)</summary>
                                <?php
                                $courseActivityTargets = ReminderClassService::agentsWithCourseActivityForOrgUnit(
                                    $reminderGroup['agents'],
                                    $orgUnitId
                                );
                                $courseActivityReady = $inventoryStatus !== null
                                    && $inventoryStatus['complete'] === true
                                    && $inventoryStatus['fresh'] === true;
                                ?>
                                <form
                                    method="post"
                                    class="course-activity-form"
                                    data-course-activity-form
                                    data-org-unit-id="<?= $escape($orgUnitId) ?>"
                                    data-ready="<?= $courseActivityReady ? 'true' : 'false' ?>"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="org_unit_id" value="<?= $escape($orgUnitId) ?>">
                                    <div class="table-wrap">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Agente</th>
                                                    <th>Inicio</th>
                                                    <th>Fin</th>
                                                    <th>Seleccionar</th>
                                                    <th>Días de inactividad</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reminderGroup['agents'] as $classAgent): ?>
                                                    <?php
                                                    $classAgentId = (int) ($classAgent['AgentId'] ?? $classAgent['Id'] ?? 0);
                                                    $classAgentOrgUnitId = (int) ($classAgent['OrgUnitId'] ?? 0);
                                                    $schedule = is_array($classAgent['Schedule'] ?? null) ? $classAgent['Schedule'] : [];
                                                    $scheduleStart = $formatScheduleDate($schedule['StartDate'] ?? null);
                                                    $scheduleEnd = $formatScheduleDate($schedule['EndDate'] ?? null);
                                                    $courseActivity = $classAgent['Condition']['CourseActivity'] ?? null;
                                                    $courseActivityEditable = is_array($courseActivity);
                                                    $courseActivityDays = $courseActivity['Days'] ?? '';
                                                    $courseActivityDaysValue = filter_var($courseActivityDays, FILTER_VALIDATE_INT);
                                                    if ($courseActivityDaysValue === false || $courseActivityDaysValue <= 0) {
                                                        $courseActivityDaysValue = '';
                                                    }
                                                    $classAgentName = (string) ($classAgent['Name'] ?? ('Agente ' . $classAgentId));
                                                    ?>
                                                    <tr data-schedule-key="<?= $escape($classAgentOrgUnitId . ':' . $classAgentId) ?>">
                                                        <td><?= $escape($classAgentName) ?></td>
                                                        <td data-schedule-start><?= $escape($scheduleStart) ?></td>
                                                        <td data-schedule-end><?= $escape($scheduleEnd) ?></td>
                                                        <td>
                                                            <?php if ($courseActivityEditable): ?>
                                                                <input
                                                                    class="course-activity-select"
                                                                    type="checkbox"
                                                                    data-course-activity-select
                                                                    data-agent-id="<?= $escape($classAgentId) ?>"
                                                                    aria-label="Seleccionar <?= $escape($classAgentName) ?> para cambiar los días"
                                                                    <?= $courseActivityReady ? '' : 'disabled' ?>
                                                                >
                                                            <?php else: ?>
                                                                <span class="muted" aria-label="Sin condición de actividad editable">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($courseActivityEditable): ?>
                                                                <input
                                                                    class="course-activity-days"
                                                                    type="number"
                                                                    min="1"
                                                                    step="1"
                                                                    inputmode="numeric"
                                                                    value="<?= $escape($courseActivityDaysValue) ?>"
                                                                    data-course-activity-days
                                                                    data-agent-id="<?= $escape($classAgentId) ?>"
                                                                    aria-label="Días de inactividad para <?= $escape($classAgentName) ?>"
                                                                    required
                                                                    <?= $courseActivityReady ? '' : 'disabled' ?>
                                                                >
                                                            <?php else: ?>
                                                                <span class="muted">Sin condición editable</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button
                                        type="submit"
                                        data-course-activity-submit
                                        <?= $courseActivityTargets === [] || !$courseActivityReady ? 'disabled' : '' ?>
                                    >Aplicar días a seleccionados</button>
                                    <p class="muted course-activity-progress" data-course-activity-progress aria-live="polite"></p>
                                </form>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>
                <noscript><p class="muted">Activa JavaScript para confirmar y procesar la activación y desactivación de agentes.</p></noscript>
            <?php endif; ?>
                </div>

                <!-- La configuracion de correo se mantiene separada de las acciones de agentes. -->
                <aside class="shared-email-editor" aria-labelledby="shared-email-title">
                    <h3 id="shared-email-title">Configuración del correo</h3>
                    <?php if ($sharedEmailScopedDraft !== null && !empty($sharedEmailScopedDraft['updated_at'])): ?>
                        <p class="shared-email-updated">
                            Último guardado: <?= $escape(date('d/m/Y H:i', strtotime((string) $sharedEmailScopedDraft['updated_at']))) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($sharedEmailConfigError !== null): ?>
                        <p class="error"><?= $escape($sharedEmailConfigError) ?></p>
                    <?php endif; ?>
                    <form
                        method="post"
                        class="class-email-recipient"
                        data-career-email-apply-form
                        data-career-label="<?= $escape($emailScopeCareer !== '' ? CourseCatalogService::careerLabel($emailScopeCareer) : '') ?>"
                        data-period-label="<?= $escape($emailScopePeriod) ?>"
                        data-agent-count="<?= count($sharedEmailCareerTargets) ?>"
                        data-max-address-length="<?= EmailRecipientList::MAX_ADDRESS_LENGTH ?>"
                        data-resume-run-id="<?= $escape($careerEmailResumeRunId) ?>"
                        data-resume-failure-count="<?= $careerEmailResumeFailures ?>"
                        data-apply-available="<?= $emailScopeComplete && $sharedEmailCareerTargets !== [] && $careerEmailConfigError === null && !$unsupportedCareerEmailRun && !$pendingCareerEmailRunOtherScope && !$activePendingSharedMessageRun && $inventoryStatus !== null && $inventoryStatus['complete'] === true && $inventoryStatus['fresh'] === true && $agentService !== null ? 'true' : 'false' ?>"
                    >
                        <h4>Destinatarios CC</h4>
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="shared_email_scope_period" value="<?= $escape($emailScopePeriod) ?>">
                        <input type="hidden" name="shared_email_scope_career" value="<?= $escape($emailScopeCareer) ?>">
                        <label for="shared-email-career-cc">
                            Correos
                            <textarea
                                id="shared-email-career-cc"
                                name="career_email_cc"
                                maxlength="<?= EmailRecipientList::MAX_LENGTH ?>"
                                autocomplete="off"
                                placeholder="correo@ejemplo.org o {InitiatingUser}"
                                rows="3"
                                aria-describedby="career-email-cc-hint career-email-cc-validation career-email-cc-count"
                                <?= $emailScopeComplete && $careerEmailConfigError === null ? '' : 'disabled' ?>
                            ><?= $escape(mb_substr($careerEmailCcValue, 0, EmailRecipientList::MAX_LENGTH, 'UTF-8')) ?></textarea>
                        </label>
                        <p id="career-email-cc-hint" class="cc-recipient-hint">Separa correos con coma o punto y coma. También puedes usar {InitiatingUser} como único destinatario.</p>
                        <p id="career-email-cc-validation" class="class-email-cc-validation" data-cc-validation-feedback role="alert" aria-live="polite" hidden></p>
                        <p id="career-email-cc-count" class="cc-recipient-count" data-cc-recipient-count aria-live="polite" aria-atomic="true"><?= $careerEmailCcRecipientCount ?> <?= $careerEmailCcRecipientCount === 1 ? 'entrada' : 'entradas' ?></p>
                        <?php if ($careerEmailConfigError !== null): ?>
                            <p class="error" role="alert"><?= $escape($careerEmailConfigError) ?></p>
                        <?php elseif ($unsupportedCareerEmailRun): ?>
                            <p class="error" role="alert">Hay una actualización anterior de destinatarios por curso que no se puede reanudar con seguridad. Revisa Brightspace; después usa «Cerrar sesión» e inicia una sesión nueva antes de volver a aplicar cambios.</p>
                        <?php elseif ($pendingCareerEmailRunOtherScope): ?>
                            <p class="error" role="alert">Hay una actualización CC pendiente en otra carrera o período. Vuelve a esos filtros para reanudarla antes de iniciar otra.</p>
                        <?php elseif ($activePendingSharedMessageRun): ?>
                            <p class="error" role="alert">Hay una aplicación del mensaje compartido pendiente. Reanúdala y complétala antes de aplicar CC.</p>
                        <?php elseif (!$emailScopeComplete): ?>
                            <p class="muted">Selecciona carrera y período para configurar CC.</p>
                        <?php elseif ($sharedEmailCareerTargets === []): ?>
                            <p class="muted">No hay agentes con acción de correo para esta carrera y período.</p>
                        <?php elseif ($inventoryStatus === null || $inventoryStatus['complete'] !== true || $inventoryStatus['fresh'] !== true): ?>
                            <p class="muted">Se requiere un inventario completo antes de aplicar cambios.</p>
                        <?php endif; ?>
                        <button
                                                    type="submit"
                                                    class="class-email-apply-button"
                                                    data-class-email-apply-button
                                                    <?= $emailScopeComplete && $sharedEmailCareerTargets !== [] && $careerEmailConfigError === null && !$unsupportedCareerEmailRun && !$pendingCareerEmailRunOtherScope && !$activePendingSharedMessageRun && $inventoryStatus !== null && $inventoryStatus['complete'] === true && $inventoryStatus['fresh'] === true && $agentService !== null ? '' : 'disabled' ?>
                                                ><?= $careerEmailResumeRunId !== '' ? 'Reanudar aplicación CC' : 'Aplicar cambios CC' ?></button>
                        <p class="class-email-progress" data-class-email-progress role="status" aria-live="polite"></p>
                    </form>
                    <form method="post" class="shared-email-message-form" aria-label="Aplicar mensaje compartido">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="shared_email_scope_period" value="<?= $escape($emailScopePeriod) ?>">
                        <input type="hidden" name="shared_email_scope_career" value="<?= $escape($emailScopeCareer) ?>">
                        <input type="hidden" name="shared_email_draft_action" value="save">
                        <h4>Mensaje compartido</h4>
                        <div class="shared-email-fields">
                            <label for="shared-email-body">
                                Mensaje
                                <textarea
                                    id="shared-email-body"
                                    name="shared_email_body"
                                    maxlength="<?= SharedEmailDraftService::MAX_BODY_LENGTH ?>"
                                    rows="8"
                                    <?= $sharedEmailConfigError === null && $emailScopeComplete ? '' : 'disabled' ?>
                                ><?= $escape(mb_substr($sharedEmailBodyValue, 0, SharedEmailDraftService::MAX_BODY_LENGTH, 'UTF-8')) ?></textarea>
                            </label>
                        </div>
                        <div class="shared-email-actions">
                            <button
                                type="button"
                                class="shared-email-submit shared-email-apply-button"
                                data-shared-email-message-apply
                                data-resume-run-id="<?= $escape($pendingSharedMessageRunId) ?>"
                                data-resume-failure-count="<?= $pendingSharedMessageFailures ?>"
                                data-career-label="<?= $escape($emailScopeCareer !== '' ? CourseCatalogService::careerLabel($emailScopeCareer) : '') ?>"
                                data-period-label="<?= $escape($emailScopePeriod !== '' ? $emailScopePeriod : '') ?>"
                                data-agent-count="<?= count($sharedEmailCareerTargets) ?>"
                                <?= $sharedEmailConfigError === null && $emailScopeComplete && !$pendingSharedMessageRunOtherScope && !$activePendingCareerEmailRun && !$unsupportedCareerEmailRun && $inventoryStatus !== null && $inventoryStatus['complete'] === true && $inventoryStatus['fresh'] === true && $agentService !== null && $sharedEmailCareerTargets !== [] ? '' : 'disabled' ?>
                            ><?= $pendingSharedMessageRunId !== '' ? 'Reanudar mensaje' : 'Guardar y aplicar mensaje' ?></button>
                        </div>
                        <?php if ($pendingSharedMessageRunOtherScope): ?>
                            <p class="error" role="alert">Hay una aplicación del mensaje pendiente en otra carrera o período. Vuelve a esos filtros para reanudarla antes de iniciar otra.</p>
                        <?php elseif (!$emailScopeComplete): ?>
                            <p class="muted">Selecciona carrera y período para configurar el mensaje.</p>
                        <?php elseif ($activePendingCareerEmailRun && !$unsupportedCareerEmailRun): ?>
                            <p class="error" role="alert">Hay una aplicación CC pendiente. Complétala antes de aplicar el mensaje compartido.</p>
                        <?php endif; ?>
                        <p class="class-email-progress" data-shared-email-message-progress role="status" aria-live="polite"></p>
                    </form>
                </aside>
            </div>
        </section>

    </main>
    <script>
        // Aplica el borrador compartido y permite reanudar lotes interrumpidos.
        document.querySelectorAll('[data-shared-email-message-apply]').forEach((button) => {
            const form = button.closest('form');
            const body = form?.querySelector('[name="shared_email_body"]');
            const progress = form?.querySelector('[data-shared-email-message-progress]');
            if (!form || !body || !progress) {
                return;
            }

            let runId = button.dataset.resumeRunId || '';
            let previousFailures = Number(button.dataset.resumeFailureCount || 0);
            let startMayHaveReachedServer = false;

            const sendRequest = async (action, currentRunId = '') => {
                const formData = new FormData(form);
                formData.delete('shared_email_draft_action');
                formData.set('shared_email_message_apply_action', action);
                if (currentRunId !== '') {
                    formData.set('run_id', currentRunId);
                }
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                });
                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch {
                    throw new Error(`El servidor no devolvió una respuesta válida (HTTP ${response.status}).`);
                }
                if (response.status === 401 && result.login_required === true) {
                    const error = new Error(result.error || 'La sesión expiró. Inicia sesión para reanudar.');
                    error.loginRequired = true;
                    throw error;
                }
                if (result.run_unavailable === true) {
                    const error = new Error(result.error);
                    error.runUnavailable = true;
                    throw error;
                }
                if (!response.ok || result.success !== true) {
                    throw new Error(result.error || `No se pudo aplicar el mensaje (HTTP ${response.status}).`);
                }
                return result;
            };

            button.addEventListener('click', async () => {
                const resuming = runId !== '';
                const restart = resuming && previousFailures > 0;
                const originalLabel = button.textContent;
                button.disabled = true;
                button.textContent = resuming ? 'Reanudando mensaje…' : 'Guardando y aplicando mensaje…';
                progress.textContent = resuming
                    ? 'Reanudando la actualización del mensaje compartido…'
                    : 'Guardando el mensaje compartido y comenzando su aplicación…';
                let processed = 0;
                let succeeded = 0;
                let failed = previousFailures;
                try {
                    let result;
                    if (resuming) {
                        result = await sendRequest(restart ? 'restart' : 'continue', runId);
                    } else {
                        startMayHaveReachedServer = true;
                        result = await sendRequest('start');
                        startMayHaveReachedServer = false;
                        runId = String(result.run_id || '');
                        if (runId === '') {
                            throw new Error('El mensaje se guardó, pero no se recibió el identificador de actualización.');
                        }
                        button.dataset.resumeRunId = runId;
                    }

                    while (true) {
                        processed = Number(result.processed || 0);
                        succeeded = Number(result.succeeded || 0);
                        failed = Number(result.failed || 0);
                        if (result.paused === true) {
                            previousFailures = failed;
                            button.dataset.resumeFailureCount = String(failed);
                            progress.textContent = `${result.error}\nProgreso conservado: ${processed} de ${Number(result.total || count)} procesados; ${succeeded} verificados y ${failed} con error.`;
                            syncApplyButton();
                            button.textContent = originalLabel;
                            return;
                        }
                        if (result.done === true) {
                            break;
                        }
                        progress.textContent = `Mensaje compartido: ${processed} de ${Number(result.total || count)} agentes; ${succeeded} verificados y ${failed} con error.`;
                        result = await sendRequest('continue', runId);
                    }

                    runId = '';
                    previousFailures = 0;
                    delete button.dataset.resumeRunId;
                    button.dataset.resumeFailureCount = '0';
                    if (failed > 0) {
                        const details = Array.isArray(result.failures)
                            ? result.failures.slice(0, 5).map((failure) => `${failure.agent_name}: ${failure.error}`).join('\n')
                            : '';
                        progress.textContent = `Aplicación parcial del mensaje: ${succeeded} agentes verificados y ${failed} con error. El texto compartido quedó guardado.${details ? `\n${details}` : ''}`;
                    } else {
                        progress.textContent = `Mensaje guardado y aplicado: ${succeeded} de ${processed} agentes verificados.`;
                    }
                    syncApplyButton();
                    button.textContent = 'Guardar y aplicar mensaje';
                } catch (error) {
                    if (error.runUnavailable === true) {
                        runId = '';
                        delete button.dataset.resumeRunId;
                        previousFailures = 0;
                    }
                    progress.textContent = `No se pudo completar la aplicación del mensaje: ${error.message}`
                        + (startMayHaveReachedServer ? ' Recarga para recuperar el avance del servidor; no inicies otra aplicación antes de comprobarlo.' : '')
                        + (runId !== '' ? ' Puedes volver a pulsar para reanudar.' : '');
                    if (error.loginRequired === true) {
                        window.setTimeout(() => window.location.assign('/login.php?expired=1'), 1500);
                        return;
                    }
                    syncApplyButton();
                    button.textContent = originalLabel;
                }
            });
        });

        // Aplica el CC editado a los agentes y permite reanudar lotes interrumpidos.
        document.querySelectorAll('[data-career-email-apply-form]').forEach((applyForm) => {
            const button = applyForm.querySelector('button[type="submit"]');
            const recipientInput = applyForm.querySelector('[name="career_email_cc"]');
            const recipientCount = applyForm.querySelector('[data-cc-recipient-count]');
            const validationFeedback = applyForm.querySelector('[data-cc-validation-feedback]');
            if (!button || !recipientInput) {
                return;
            }

            const total = Number(applyForm.dataset.agentCount || 0);
            const applyAvailable = applyForm.dataset.applyAvailable === 'true';
            const syncApplyButton = () => {
                button.disabled = !applyAvailable;
            };
            const updateRecipientCountAndValidity = () => {
                const value = recipientInput.value;
                const trimmedValue = value.trim();
                const entries = value.split(/[;,]/).map((entry) => entry.trim());
                const nonEmptyEntries = entries.filter((entry) => entry !== '');
                if (recipientCount) {
                    const count = nonEmptyEntries.length;
                    recipientCount.textContent = `${count} ${count === 1 ? 'entrada' : 'entradas'}`;
                }

                let message = '';
                if (value.length > recipientInput.maxLength) {
                    message = 'La lista de destinatarios no puede superar 2.048 caracteres.';
                } else if (trimmedValue !== '' && trimmedValue !== '{InitiatingUser}') {
                    if (entries.some((entry) => entry === '')) {
                        message = 'Quita los separadores vacíos; cada correo debe tener un destinatario.';
                    } else if (entries.includes('{InitiatingUser}')) {
                        message = '{InitiatingUser} debe ser el único destinatario.';
                    } else {
                        const seen = new Set();
                        const emailValidator = document.createElement('input');
                        emailValidator.type = 'email';
                        const maxAddressLength = Number(applyForm.dataset.maxAddressLength || 254);
                        for (const entry of entries) {
                            emailValidator.value = entry;
                            if (entry.length > maxAddressLength || !emailValidator.checkValidity()) {
                                message = `Revisa la lista: cada entrada debe ser un correo válido de máximo ${maxAddressLength} caracteres.`;
                                break;
                            }
                            const normalized = entry.toLowerCase();
                            if (seen.has(normalized)) {
                                message = 'La lista no puede contener correos duplicados.';
                                break;
                            }
                            seen.add(normalized);
                        }
                    }
                }
                recipientInput.setCustomValidity(message);
                recipientInput.setAttribute('aria-invalid', message === '' ? 'false' : 'true');
                if (validationFeedback) {
                    validationFeedback.textContent = message;
                    validationFeedback.hidden = message === '';
                }
            };
            recipientInput.addEventListener('input', updateRecipientCountAndValidity);
            updateRecipientCountAndValidity();
            syncApplyButton();

            applyForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const progress = applyForm.querySelector('[data-class-email-progress]');
                if (!button || !progress) {
                    return;
                }

                let runId = String(applyForm.dataset.resumeRunId || '');
                const resuming = runId !== '';
                if (!resuming && !applyForm.reportValidity()) {
                    return;
                }

                const originalLabel = button.textContent;
                const previousFailures = Number(applyForm.dataset.resumeFailureCount || 0);
                const restartFailedRun = resuming && previousFailures > 0;
                let processed = 0;
                let succeeded = 0;
                let failed = 0;
                let startMayHaveReachedServer = false;
                button.disabled = true;
                button.textContent = resuming ? 'Reanudando…' : 'Aplicando CC…';
                progress.textContent = resuming
                    ? (restartFailedRun
                        ? 'Reiniciando la verificación de los agentes de esta carrera para recuperar los errores anteriores…'
                        : 'Reanudando la actualización anterior desde el último avance confirmado…')
                    : 'Aplicando el CC del campo a los agentes de esta carrera y período…';

                const sendRequest = async (action, currentRunId = '') => {
                    const formData = new FormData(applyForm);
                    formData.set('shared_email_apply_action', action);
                    if (currentRunId !== '') {
                        formData.set('run_id', currentRunId);
                    }

                    let lastFailure = null;
                    for (let attempt = 0; attempt < 3; attempt++) {
                        const response = await fetch(window.location.pathname + window.location.search, {
                            method: 'POST',
                            body: formData,
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        const responseText = await response.text();
                        let result;
                        try {
                            result = JSON.parse(responseText);
                        } catch {
                            const contentType = response.headers.get('content-type') || 'desconocido';
                            lastFailure = new Error(
                                `El servidor no devolvió JSON (HTTP ${response.status}, ${contentType}). Se intentará continuar de forma segura.`
                            );
                            if (action === 'continue' && attempt < 2) {
                                await new Promise((resolve) => window.setTimeout(resolve, 800 * (attempt + 1)));
                                continue;
                            }
                            throw lastFailure;
                        }
                        if (response.status === 401 && result.login_required === true) {
                            const loginError = new Error(result.error || 'La sesión expiró. Inicia sesión para reanudar.');
                            loginError.loginRequired = true;
                            throw loginError;
                        }
                        if (result.run_unavailable === true) {
                            const unavailableError = new Error(result.error);
                            unavailableError.runUnavailable = true;
                            throw unavailableError;
                        }
                        if (!response.ok || result.success !== true) {
                            throw new Error(result.error || `No se pudo actualizar Brightspace (HTTP ${response.status}).`);
                        }
                        return result;
                    }
                    throw lastFailure || new Error('No se pudo recuperar la respuesta del servidor.');
                };

                try {
                    let result;
                    if (resuming) {
                        result = await sendRequest(restartFailedRun ? 'restart' : 'continue', runId);
                    } else {
                        startMayHaveReachedServer = true;
                        result = await sendRequest('start');
                        startMayHaveReachedServer = false;
                        runId = String(result.run_id || '');
                        if (runId === '') {
                            throw new Error('El servidor no devolvió el identificador de la actualización.');
                        }
                        applyForm.dataset.resumeRunId = runId;
                        if (result.career) {
                            progress.textContent = `Aplicando los cambios a ${Number(result.total || total)} agentes de la carrera…`;
                        }
                    }

                    processed = Number(result.processed || 0);
                    succeeded = Number(result.succeeded || 0);
                    failed = Number(result.failed || 0);
                    if (result.paused === true) {
                        applyForm.dataset.resumeRunId = runId;
                        applyForm.dataset.resumeFailureCount = String(failed);
                        progress.textContent = `${result.error}\nProgreso conservado: ${processed} de ${Number(result.total || total)} procesados; ${succeeded} verificados y ${failed} con error. Corrige o espera la conexión DNS y vuelve a pulsar para continuar.`;
                        button.disabled = false;
                        button.textContent = originalLabel;
                        return;
                    }
                    progress.textContent = `Actualización iniciada. Procesando ${processed} de ${Number(result.total || total)} agentes…`;
                    while (!result.done) {
                        result = await sendRequest('continue', runId);
                        processed = Number(result.processed || 0);
                        succeeded = Number(result.succeeded || 0);
                        failed = Number(result.failed || 0);
                        if (result.paused === true) {
                            applyForm.dataset.resumeRunId = runId;
                            applyForm.dataset.resumeFailureCount = String(failed);
                            progress.textContent = `${result.error}\nProgreso conservado: ${processed} de ${Number(result.total || total)} procesados; ${succeeded} verificados y ${failed} con error. Corrige o espera la conexión DNS y vuelve a pulsar para continuar.`;
                            button.disabled = false;
                            button.textContent = originalLabel;
                            return;
                        }
                        const latestAgents = Array.isArray(result.batch_results)
                            ? result.batch_results.slice(-3).map((item) => item.success
                                ? `${item.agent_name}: confirmado`
                                : `${item.agent_name}: error — ${item.error}`)
                            : [];
                        progress.textContent = `${latestAgents.join('\n')}\nProgreso: ${processed} de ${Number(result.total || total)}; verificados: ${succeeded}; con error: ${failed}.`;
                    }

                    delete applyForm.dataset.resumeRunId;
                    applyForm.dataset.resumeFailureCount = '0';
                    if (failed > 0) {
                        const failures = Array.isArray(result.failures)
                            ? result.failures.slice(0, 5).map((failure) => `${failure.agent_name}: ${failure.error}`)
                            : [];
                        const omitted = failed > failures.length ? `\n…y ${failed - failures.length} error(es) más.` : '';
                        progress.textContent = `Actualización parcial de CC. ${succeeded} agentes verificados; ${failed} fallaron de ${processed}.${failures.length ? `\n${failures.join('\n')}` : ''}${omitted}`;
                    } else {
                        progress.textContent = `Actualización completada y verificada: ${succeeded} de ${processed} agentes.`;
                    }
                    button.disabled = false;
                    button.textContent = originalLabel;
                } catch (error) {
                    if (error.runUnavailable === true) {
                        delete applyForm.dataset.resumeRunId;
                        applyForm.dataset.resumeFailureCount = '0';
                    } else if (runId !== '') {
                        applyForm.dataset.resumeRunId = runId;
                    }
                    const currentStatus = processed > 0
                        ? ` Progreso confirmado: ${processed} procesados, ${succeeded} verificados y ${failed} con error.`
                        : '';
                    const resumeStatus = runId !== ''
                        ? ' Puedes pulsar de nuevo el botón para continuar desde el último lote confirmado; no se reiniciará toda la actualización.'
                        : '';
                    const startStatus = startMayHaveReachedServer
                        ? ' Recarga la página para comprobar si el servidor inició la actualización y reanúdala desde allí; no vuelvas a iniciar otra hasta verificarlo.'
                        : '';
                    const unavailableStatus = error.runUnavailable === true
                        ? ' El servidor perdió el avance de esta carrera. Revisa Brightspace antes de volver a aplicar la lista CC.'
                        : '';
                    progress.textContent = `No se pudo completar la actualización: ${error.message}.${currentStatus}${resumeStatus}${startStatus}${unavailableStatus}`;
                    if (error.loginRequired === true) {
                        progress.textContent += ' Serás llevado al inicio de sesión; al volver al panel podrás reanudar la ejecución guardada.';
                        window.setTimeout(() => {
                            window.location.assign('/login.php?expired=1');
                        }, 1500);
                        return;
                    }
                    button.disabled = false;
                    button.textContent = originalLabel;
                }
            });
        });

        // Guarda el identificador del refresco para poder continuar lotes del inventario.
        // Actualiza estado y avance de la lectura recuperable del inventario.
        document.querySelectorAll('[data-inventory-refresh-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                const progress = document.getElementById('inventory-refresh-progress');
                if (!button || !progress) {
                    return;
                }

                // Guarda el ID en esta pestana para reanudar un refresco interrumpido.
                const refreshStorageKey = `tesa-agent-inventory-refresh:${window.location.pathname}`;
                let refreshId = sessionStorage.getItem(refreshStorageKey) || '';
                button.disabled = true;
                button.textContent = refreshId !== '' ? 'Reanudando actualización…' : 'Iniciando actualización…';
                progress.textContent = refreshId !== ''
                    ? 'Reanudando la actualización guardada del inventario…'
                    : 'Preparando el inventario de Brightspace…';

                const sendRequest = async (action, refreshId = '') => {
                    const formData = new FormData(form);
                    formData.set('inventory_refresh_action', action);
                    if (refreshId !== '') {
                        formData.set('refresh_id', refreshId);
                    }

                    const response = await fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });
                    const responseText = await response.text();
                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch {
                        const contentType = response.headers.get('content-type') || 'desconocido';
                        throw new Error(`Respuesta no válida del servidor (HTTP ${response.status}, ${contentType}).`);
                    }
                    if (!response.ok || result.success !== true) {
                        throw new Error(result.error || `No se pudo actualizar el inventario (HTTP ${response.status}).`);
                    }
                    return result;
                };

                try {
                    let result;
                    if (refreshId === '') {
                        result = await sendRequest('start');
                        refreshId = String(result.refresh_id || '');
                        if (refreshId === '') {
                            throw new Error('El servidor no devolvió el identificador de la actualización.');
                        }
                        sessionStorage.setItem(refreshStorageKey, refreshId);
                        if (result.resumed === true) {
                            progress.textContent = `Se reanuda la actualización desde ${Number(result.processed || 0)} de ${Number(result.total || 0)} cursos.`;
                        }
                    } else {
                        result = await sendRequest('continue', refreshId);
                    }
                    while (!result.done) {
                        button.textContent = 'Actualizando inventario…';
                        result = await sendRequest('continue', refreshId);
                        progress.textContent = `Actualizando inventario: ${result.processed} de ${result.total} cursos consultados. Consultas con error: ${result.failed}.`;
                    }

                    sessionStorage.removeItem(refreshStorageKey);
                    progress.textContent = result.failed > 0
                        ? `Actualización terminada; hubo errores en ${result.failed} consultas. Se mostrará el resultado.`
                        : 'Inventario actualizado. Se mostrarán los agentes más recientes.';
                    window.location.reload();
                } catch (error) {
                    progress.textContent = `No se pudo completar la actualización: ${error.message}`;
                    button.disabled = false;
                    button.textContent = refreshId !== '' ? 'Reanudar actualización' : 'Actualizar inventario';
                }
            });

            if (form.dataset.autoRefresh === 'true') {
                window.setTimeout(() => {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.querySelector('button[type="submit"]')?.click();
                    }
                }, 0);
            }
        });

        // Deshabilita el boton mientras se procesa la accion de esta clase.
        // Envia acciones individuales por clase y evita dobles envios.
        document.querySelectorAll('[data-class-reminder-form]').forEach((form) => {
            const button = form.querySelector('button[data-confirmable="true"]');
            if (button) {
                button.disabled = false;
            }

            form.addEventListener('submit', () => {
                if (button) {
                    button.disabled = true;
                    button.textContent = 'Procesando…';
                }
            });
        });

        // Ejecuta activar/desactivar en lotes y refleja las confirmaciones en las tarjetas.
        document.querySelectorAll('[data-bulk-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const progress = document.getElementById('bulk-progress');
                if (!progress) {
                    return;
                }
                const total = Number(form.dataset.count || 0);
                const action = form.dataset.actionLabel || 'cambiar';

                const bulkButtons = document.querySelectorAll('[data-bulk-form] button[type="submit"]');
                bulkButtons.forEach((bulkButton) => { bulkButton.disabled = true; });
                progress.textContent = `Procesando ${action} de ${total} agentes en Brightspace…`;
                let processed = 0;
                let succeeded = 0;
                let failed = 0;
                let lastError = '';
                let runId = '';
                const formData = new FormData(form);
                formData.set('bulk_chunk', '1');

                // Refleja el nuevo estado solo cuando el servidor confirma la accion.
                const updateClassState = (orgUnitId, previousEnabled, enabled) => {
                    const classCard = document.getElementById(`reminder-class-${orgUnitId}`);
                    if (!classCard) {
                        return;
                    }

                    let activeCount = Number(classCard.dataset.activeCount || 0);
                    if (previousEnabled !== enabled) {
                        activeCount += enabled ? 1 : -1;
                    }
                    activeCount = Math.max(0, Math.min(activeCount, Number(classCard.dataset.agentCount || 0)));
                    classCard.dataset.activeCount = String(activeCount);

                    const status = classCard.querySelector('.reminder-status span');
                    if (status) {
                        const hasActiveAgents = activeCount > 0;
                        status.className = `reminder-status-${hasActiveAgents ? 'active' : 'inactive'}`;
                        status.textContent = `Agentes ${hasActiveAgents ? 'Activos' : 'Inactivos'}`;
                    }

                    const activateButton = classCard.querySelector('[data-class-action="activate"] button');
                    const deactivateButton = classCard.querySelector('[data-class-action="deactivate"] button');
                    if (activateButton) {
                        activateButton.disabled = activeCount >= Number(classCard.dataset.agentCount || 0);
                    }
                    if (deactivateButton) {
                        deactivateButton.disabled = activeCount === 0;
                    }
                };

                try {
                    while (true) {
                        formData.set('bulk_offset', String(processed));
                        formData.set('bulk_run_id', runId);
                        const response = await fetch(window.location.pathname + window.location.search, {
                            method: 'POST',
                            body: formData,
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        let result;
                        try {
                            result = await response.json();
                        } catch {
                            throw new Error('El servidor devolvió una respuesta no válida.');
                        }
                        if (!response.ok || result.success === undefined) {
                            throw new Error(result.error || 'El servidor no pudo continuar el lote.');
                        }

                        runId = result.run_id || runId;
                        succeeded = Number(result.succeeded || succeeded);
                        failed = Number(result.failed || failed);
                        if (result.error) {
                            lastError = result.error;
                        }
                        if (
                            result.success === true
                            && Number.isInteger(Number(result.org_unit_id))
                            && typeof result.previous_enabled === 'boolean'
                            && typeof result.enabled === 'boolean'
                        ) {
                            updateClassState(
                                Number(result.org_unit_id),
                                result.previous_enabled,
                                result.enabled
                            );
                        }
                        if (result.done) {
                            processed = Number(result.processed || processed);
                            progress.textContent = `Finalizado: ${succeeded} confirmados en Brightspace, ${failed} con error, de ${Number(result.total || 0)} agentes${lastError ? `. Último error: ${lastError}` : ''}.`;
                            bulkButtons.forEach((bulkButton) => {
                                bulkButton.disabled = Number(bulkButton.closest('form').dataset.count || 0) === 0;
                            });
                            break;
                        }

                        processed = Number(result.processed || processed + 1);
                        progress.textContent = `${action}: ${processed} de ${Number(result.total || total)} procesados (${succeeded} correctos, ${failed} con error).${result.error ? ` Último error: ${result.error}` : ''}`;
                    }
                } catch (error) {
                    progress.textContent = `La conexión se interrumpió después de ${processed} de ${total} agentes. El resultado del último agente podría ser incierto. Recarga la página para revisar los estados antes de volver a intentarlo. ${error.message}`;
                    bulkButtons.forEach((bulkButton) => {
                        bulkButton.disabled = Number(bulkButton.closest('form').dataset.count || 0) === 0;
                    });
                }
            });
        });

        // Calendario local para introducir fechas sin depender del formato del navegador.
        const dateCalendarMonths = new Intl.DateTimeFormat('es-EC', { month: 'long', year: 'numeric' });
        const dateCalendarLongDate = new Intl.DateTimeFormat('es-EC', { dateStyle: 'full' });
        const dateCalendarDisplay = new Intl.DateTimeFormat('es-EC', { day: '2-digit', month: '2-digit', year: 'numeric' });
        // Usa mediodia local para evitar desplazamientos de fecha por cambios horarios.
        const makeLocalDate = (year, month, day) => {
            const date = new Date(0);
            date.setHours(12, 0, 0, 0);
            date.setFullYear(year, month, day);
            return date;
        };
        const dateToIso = (date) => [
            String(date.getFullYear()).padStart(4, '0'),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
        // Acepta los formatos visibles y rechaza fechas inexistentes antes de enviarlas.
        const parseCalendarDate = (value) => {
            const normalized = String(value || '').trim();
            let year;
            let month;
            let day;
            let match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (match) {
                [, year, month, day] = match;
            } else {
                match = normalized.match(/^(\d{1,2})[/. -](\d{1,2})[/. -](\d{4})$/);
                if (!match) {
                    return null;
                }
                [, day, month, year] = match;
            }

            const date = makeLocalDate(Number(year), Number(month) - 1, Number(day));
            if (
                date.getFullYear() !== Number(year)
                || date.getMonth() !== Number(month) - 1
                || date.getDate() !== Number(day)
            ) {
                return null;
            }
            return date;
        };
        const calendarPickers = Array.from(document.querySelectorAll('[data-date-picker]'));
        let openCalendar = null;

        // Cierra el calendario activo y restaura el foco si se cerro con teclado.
        const closeCalendar = (picker, returnFocus = false) => {
            if (!picker) {
                return;
            }
            picker.querySelector('[data-calendar-popover]').hidden = true;
            picker.classList.remove('is-open');
            picker.querySelectorAll('[aria-expanded="true"]').forEach((control) => {
                control.setAttribute('aria-expanded', 'false');
            });
            if (openCalendar === picker) {
                openCalendar = null;
            }
            const panel = picker.closest('.panel');
            if (panel && (!openCalendar || !panel.contains(openCalendar))) {
                panel.classList.remove('has-open-calendar');
            }
            if (returnFocus) {
                picker.querySelector('[data-date-display]').focus();
            }
        };

        // Dibuja el mes visible y mantiene el formato local sin conversiones UTC.
        const renderCalendar = (picker) => {
            const year = Number(picker.dataset.viewYear);
            const month = Number(picker.dataset.viewMonth);
            const selectedIso = picker.querySelector('[data-date-value]').value;
            const todayIso = dateToIso(new Date());
            const titleDate = makeLocalDate(year, month, 1);
            picker.querySelector('[data-calendar-title]').textContent = dateCalendarMonths.format(titleDate);
            const firstWeekday = (titleDate.getDay() + 6) % 7;
            const daysInMonth = makeLocalDate(year, month + 1, 0).getDate();
            const cellCount = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;
            const daysContainer = picker.querySelector('[data-calendar-days]');
            daysContainer.replaceChildren();

            // Completa la cuadricula con dias previos y posteriores al mes mostrado.
            for (let index = 0; index < cellCount; index += 1) {
                const date = makeLocalDate(year, month, index - firstWeekday + 1);
                const iso = dateToIso(date);
                const dayButton = document.createElement('button');
                dayButton.type = 'button';
                dayButton.className = 'date-day';
                dayButton.dataset.calendarDate = iso;
                dayButton.textContent = String(date.getDate());
                dayButton.setAttribute('aria-label', dateCalendarLongDate.format(date));
                dayButton.setAttribute('aria-pressed', iso === selectedIso ? 'true' : 'false');
                if (date.getMonth() !== month) {
                    dayButton.classList.add('is-outside');
                }
                if (iso === todayIso) {
                    dayButton.classList.add('is-today');
                }
                if (iso === selectedIso) {
                    dayButton.classList.add('is-selected');
                }
                dayButton.addEventListener('click', () => {
                    const display = picker.querySelector('[data-date-display]');
                    const value = picker.querySelector('[data-date-value]');
                    value.value = iso;
                    display.value = dateCalendarDisplay.format(date);
                    display.setCustomValidity('');
                    closeCalendar(picker, true);
                });
                daysContainer.append(dayButton);
            }
        };

        // Solo puede haber un calendario abierto; al cambiar se cierra el anterior.
        const openDateCalendar = (picker, focusDay = true) => {
            if (openCalendar && openCalendar !== picker) {
                closeCalendar(openCalendar);
            }
            openCalendar = picker;
            picker.closest('.panel')?.classList.add('has-open-calendar');
            const selectedDate = parseCalendarDate(picker.querySelector('[data-date-value]').value)
                || parseCalendarDate(picker.querySelector('[data-date-display]').value)
                || new Date();
            picker.dataset.viewYear = String(selectedDate.getFullYear());
            picker.dataset.viewMonth = String(selectedDate.getMonth());
            renderCalendar(picker);
            picker.querySelector('[data-calendar-popover]').hidden = false;
            picker.classList.add('is-open');
            picker.querySelectorAll('[aria-haspopup="dialog"]').forEach((control) => {
                control.setAttribute('aria-expanded', 'true');
            });
            if (focusDay) {
                const activeDate = picker.querySelector('[data-date-value]').value || dateToIso(selectedDate);
                const dayButton = picker.querySelector(`[data-calendar-date="${activeDate}"]`);
                (dayButton || picker.querySelector('[data-calendar-days] button'))?.focus();
            }
        };

        calendarPickers.forEach((picker) => {
            const display = picker.querySelector('[data-date-display]');
            const value = picker.querySelector('[data-date-value]');
            const trigger = picker.querySelector('[data-calendar-trigger]');
            const setFromText = () => {
                const raw = display.value.trim();
                if (raw === '') {
                    value.value = '';
                    display.setCustomValidity('');
                    return;
                }
                const parsed = parseCalendarDate(raw);
                if (!parsed) {
                    value.value = '';
                    display.setCustomValidity('Escribe una fecha válida en formato DD/MM/AAAA.');
                    return;
                }
                value.value = dateToIso(parsed);
                display.setCustomValidity('');
            };

            display.addEventListener('click', () => openDateCalendar(picker));
            display.addEventListener('input', setFromText);
            display.addEventListener('change', setFromText);
            display.addEventListener('blur', () => {
                setFromText();
                const parsed = parseCalendarDate(value.value);
                if (parsed) {
                    display.value = dateCalendarDisplay.format(parsed);
                }
            });
            display.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown' || event.key === 'Enter') {
                    event.preventDefault();
                    openDateCalendar(picker);
                } else if (event.key === 'Escape' && openCalendar === picker) {
                    event.preventDefault();
                    closeCalendar(picker, true);
                }
            });
            trigger.addEventListener('click', () => openDateCalendar(picker));
            picker.querySelector('[data-calendar-previous]').addEventListener('click', () => {
                const date = makeLocalDate(Number(picker.dataset.viewYear), Number(picker.dataset.viewMonth) - 1, 1);
                picker.dataset.viewYear = String(date.getFullYear());
                picker.dataset.viewMonth = String(date.getMonth());
                renderCalendar(picker);
            });
            picker.querySelector('[data-calendar-next]').addEventListener('click', () => {
                const date = makeLocalDate(Number(picker.dataset.viewYear), Number(picker.dataset.viewMonth) + 1, 1);
                picker.dataset.viewYear = String(date.getFullYear());
                picker.dataset.viewMonth = String(date.getMonth());
                renderCalendar(picker);
            });
            picker.querySelector('[data-calendar-today]').addEventListener('click', () => {
                const today = new Date();
                const iso = dateToIso(today);
                value.value = iso;
                display.value = dateCalendarDisplay.format(today);
                display.setCustomValidity('');
                closeCalendar(picker, true);
            });
            picker.querySelector('[data-calendar-close]').addEventListener('click', () => closeCalendar(picker, true));
            picker.querySelector('[data-calendar-days]').addEventListener('keydown', (event) => {
                const focusedDate = parseCalendarDate(event.target.dataset.calendarDate || '');
                const dayOffsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
                if (!focusedDate || !Object.prototype.hasOwnProperty.call(dayOffsets, event.key)) {
                    return;
                }
                event.preventDefault();
                focusedDate.setDate(focusedDate.getDate() + dayOffsets[event.key]);
                picker.dataset.viewYear = String(focusedDate.getFullYear());
                picker.dataset.viewMonth = String(focusedDate.getMonth());
                renderCalendar(picker);
                picker.querySelector(`[data-calendar-date="${dateToIso(focusedDate)}"]`)?.focus();
            });
        });

        document.addEventListener('pointerdown', (event) => {
            if (openCalendar && !openCalendar.contains(event.target)) {
                closeCalendar(openCalendar);
            }
        });
        // Escape cierra el calendario y devuelve el foco al campo de fecha.
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && openCalendar) {
                closeCalendar(openCalendar, true);
            }
        });

        // Valida las fechas y envia los cambios de cronograma en lotes.
        const scheduleButtons = Array.from(document.querySelectorAll('[data-schedule-submit]'));
        const scheduleProgress = document.getElementById('schedule-progress');
        const scheduleTimeZone = document.documentElement.dataset.timezone || 'UTC';
        // Convierte fechas confirmadas por Brightspace a la zona horaria del panel.
        const displayScheduleDate = (value) => {
            if (typeof value !== 'string' || value === '') {
                return '—';
            }

            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }

            return new Intl.DateTimeFormat('es-EC', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                timeZone: scheduleTimeZone,
            }).format(parsed);
        };

        // Envia el cronograma por lotes y actualiza en pantalla las fechas confirmadas.
        document.querySelectorAll('[data-schedule-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (!form.reportValidity()) {
                    return;
                }

                const data = new FormData(form);
                const startDate = String(data.get('start_date') || '');
                const endDate = String(data.get('end_date') || '');
                if (!parseCalendarDate(startDate) || !parseCalendarDate(endDate)) {
                    scheduleProgress.textContent = 'Selecciona una fecha de inicio y una fecha final válidas.';
                    form.querySelector(`[name="${startDate === '' ? 'start_date' : 'end_date'}"]`)
                        ?.closest('[data-date-picker]')
                        ?.querySelector('[data-date-display]')
                        ?.focus();
                    return;
                }
                if (startDate > endDate) {
                    scheduleProgress.textContent = 'La fecha final debe ser igual o posterior a la fecha inicial.';
                    form.querySelector('[name="end_date"]').focus();
                    return;
                }

                const count = Number(form.dataset.count || 0);
                if (count === 0) {
                    scheduleProgress.textContent = 'No hay agentes con cronograma para actualizar en este módulo.';
                    return;
                }

                let offset = 0;
                let runId = '';
                let succeeded = 0;
                let failed = 0;
                let lastError = '';
                scheduleButtons.forEach((button) => {
                    button.disabled = true;
                });
                scheduleProgress.textContent = `Iniciando actualización de ${count} agentes del Módulo ${form.dataset.parallel}...`;

                try {
                    while (true) {
                        const requestData = new FormData();
                        requestData.set('csrf_token', String(data.get('csrf_token') || ''));
                        requestData.set('schedule_action', 'update_dates');
                        requestData.set('schedule_parallel', String(form.dataset.parallel || ''));
                        requestData.set('start_date', startDate);
                        requestData.set('end_date', endDate);
                        requestData.set('schedule_offset', String(offset));
                        if (runId !== '') {
                            requestData.set('schedule_run_id', runId);
                        }

                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: requestData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        let result;
                        try {
                            result = await response.json();
                        } catch {
                            throw new Error(`El servidor devolvió una respuesta no válida (HTTP ${response.status}).`);
                        }
                        if (!response.ok || !result || typeof result !== 'object') {
                            throw new Error(result && result.error ? result.error : `Error del servidor (HTTP ${response.status}).`);
                        }

                        runId = typeof result.run_id === 'string' ? result.run_id : runId;
                        succeeded = Number(result.succeeded || succeeded);
                        failed = Number(result.failed || failed);
                        if (result.error) {
                            lastError = String(result.error);
                        }

                        if (
                            result.success === true
                            && Number.isInteger(Number(result.agent_id))
                            && Number.isInteger(Number(result.org_unit_id))
                            && typeof result.start_date === 'string'
                            && typeof result.end_date === 'string'
                        ) {
                            const row = document.querySelector(
                                `[data-schedule-key="${Number(result.org_unit_id)}:${Number(result.agent_id)}"]`
                            );
                            if (row) {
                                const start = row.querySelector('[data-schedule-start]');
                                const end = row.querySelector('[data-schedule-end]');
                                if (start && end) {
                                    start.textContent = displayScheduleDate(result.start_date);
                                    end.textContent = displayScheduleDate(result.end_date);
                                }
                            }
                        }

                        if (result.done) {
                            scheduleProgress.textContent = `Finalizado: ${succeeded} confirmados en Brightspace, ${failed} con error, de ${Number(result.total || 0)} agentes${lastError ? `. Último error: ${lastError}` : ''}.`;
                            break;
                        }

                        offset = Number(result.processed || offset + 1);
                        scheduleProgress.textContent = `Módulo ${form.dataset.parallel}: ${offset} de ${Number(result.total || count)} procesados (${succeeded} confirmados, ${failed} con error).${result.error ? ` Último error: ${result.error}` : ''}`;
                    }
                } catch (error) {
                    const detail = error instanceof Error ? error.message : String(error);
                    scheduleProgress.textContent = `La conexión se interrumpió después de ${offset} de ${count} agentes. El resultado del último agente podría ser incierto. Verifica las fechas en Brightspace antes de volver a intentarlo. ${detail}`;
                } finally {
                    scheduleButtons.forEach((button) => {
                        const ownerForm = button.closest('form');
                        button.disabled = Number(ownerForm.dataset.count || 0) === 0 || ownerForm.dataset.ready !== 'true';
                    });
                }
            });
        });

        // Aplica los dias de actividad solo a los agentes marcados y confirma cada resultado.
        // Mantiene el valor independiente de cada agente y reporta resultados parciales.
        document.querySelectorAll('[data-course-activity-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const progress = form.querySelector('[data-course-activity-progress]');
                const submit = form.querySelector('[data-course-activity-submit]');
                const selected = Array.from(form.querySelectorAll('[data-course-activity-select]:checked'))
                    .map((checkbox) => {
                        const row = checkbox.closest('tr');
                        return {
                            checkbox,
                            name: row?.querySelector('td')?.textContent?.trim() || 'Agente',
                            input: row?.querySelector('[data-course-activity-days]'),
                            agentId: String(checkbox.dataset.agentId || ''),
                        };
                    });

                if (selected.length === 0) {
                    progress.textContent = 'Selecciona al menos un agente con condición editable.';
                    return;
                }

                for (const target of selected) {
                    if (
                        !target.input
                        || !target.input.checkValidity()
                        || !/^[1-9]\d*$/.test(target.input.value.trim())
                        || !Number.isSafeInteger(Number(target.input.value))
                    ) {
                        progress.textContent = `Ingresa un número entero positivo de días para ${target.name}.`;
                        target.input?.focus();
                        return;
                    }
                }

                submit.disabled = true;
                let confirmed = 0;
                let failed = 0;
                const failures = [];
                progress.textContent = `Aplicando los días individuales a ${selected.length} agentes seleccionados...`;

                for (let index = 0; index < selected.length; index++) {
                    const target = selected[index];
                    const requestData = new FormData();
                    requestData.set('csrf_token', String(form.querySelector('[name="csrf_token"]')?.value || ''));
                    requestData.set('course_activity_action', 'update_days');
                    requestData.set('org_unit_id', String(form.dataset.orgUnitId || ''));
                    requestData.set('agent_id', target.agentId);
                    requestData.set('days', target.input.value.trim());

                    try {
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: requestData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        let result;
                        try {
                            result = await response.json();
                        } catch {
                            throw new Error(`Respuesta no válida (HTTP ${response.status}).`);
                        }

                        if (
                            !response.ok
                            || !result
                            || result.success !== true
                            || Number(result.agent_id) !== Number(target.agentId)
                            || Number(result.org_unit_id) !== Number(form.dataset.orgUnitId)
                            || !Number.isSafeInteger(Number(result.days))
                            || Number(result.days) <= 0
                        ) {
                            throw new Error(result && result.error ? String(result.error) : `Error del servidor (HTTP ${response.status}).`);
                        }

                        target.input.value = String(result.days);
                        confirmed++;
                    } catch (error) {
                        failed++;
                        const detail = error instanceof Error ? error.message : String(error);
                        failures.push(`${target.name}: ${detail}`);
                    }

                    const processed = index + 1;
                    progress.textContent = `${processed} de ${selected.length} procesados (${confirmed} confirmados en Brightspace, ${failed} con error).`;
                }

                progress.textContent = `Finalizado: ${confirmed} confirmados en Brightspace, ${failed} con error.${failures.length ? ` ${failures.join(' | ')}` : ''}`;
                submit.disabled = form.dataset.ready !== 'true'
                    || form.querySelector('[data-course-activity-select]') === null;
            });
        });
    </script>
</body>
</html>
