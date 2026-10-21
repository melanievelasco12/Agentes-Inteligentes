<?php

declare(strict_types=1);

// Suite de regresion autocontenida: los clientes fake evitan cambios en servicios externos.
require_once dirname(__DIR__) . '/app/Services/InactivityService.php';
require_once dirname(__DIR__) . '/app/Services/ReminderCampaignService.php';
require_once dirname(__DIR__) . '/app/Services/ReminderClassService.php';
require_once dirname(__DIR__) . '/app/Services/BrightspaceAgentService.php';
require_once dirname(__DIR__) . '/app/Services/CourseCatalogService.php';
require_once dirname(__DIR__) . '/app/Services/SharedEmailDraftService.php';
require_once dirname(__DIR__) . '/app/Services/CareerPeriodEmailDraftService.php';
require_once dirname(__DIR__) . '/app/Services/ClassEmailRecipientService.php';
require_once dirname(__DIR__) . '/app/Services/AuditService.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/app/Support/AdminUsernameCipher.php';
require_once dirname(__DIR__) . '/integrations/brightspace/OAuthTokenStore.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    // Comparacion estricta para detectar diferencias de tipo además del valor.
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

final class FakeInactivityService extends InactivityService
{
    public function __construct(private array $candidates)
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function candidatesForDays(int $days): array
    {
        return $this->candidates;
    }
}

class FakeBrightspaceClient implements BrightspaceAgentClient, BrightspaceBatchClient
{
    // Conserva solicitudes y agentes en memoria para verificar efectos y payloads de prueba.
    /** @var array<int, array<string, mixed>> */
    public array $agents;
    /** @var array<int, array{method: string, path: string, body?: array}> */
    public array $requests = [];
    public mixed $afterPut = null;

    public function __construct(array $agents)
    {
        $this->agents = $agents;
    }

    public function get(string $product, string $path, array $query = []): array
    {
        $this->requests[] = ['method' => 'GET', 'path' => $path];
        if (preg_match('~/agents/(\d+)/?$~', $path, $matches) !== 1) {
            throw new RuntimeException('Unexpected fake Brightspace GET: ' . $path);
        }

        $agentId = (int) $matches[1];
        if (!isset($this->agents[$agentId])) {
            throw new RuntimeException('Fake agent not found.');
        }

        return $this->agents[$agentId];
    }

    public function put(string $product, string $path, array $body): array
    {
        $this->requests[] = ['method' => 'PUT', 'path' => $path, 'body' => $body];
        if (preg_match('~/agents/(\d+)/?$~', $path, $matches) !== 1) {
            throw new RuntimeException('Unexpected fake Brightspace PUT: ' . $path);
        }

        $agentId = (int) $matches[1];
        $this->agents[$agentId] = $body;
        if (is_callable($this->afterPut)) {
            ($this->afterPut)($this->agents[$agentId]);
        }

        return [];
    }

    public function post(string $product, string $path, array $body = []): array
    {
        throw new RuntimeException('Unexpected fake Brightspace POST: ' . $path);
    }

    public function requestBatchJson(array $requests): array
    {
        $responses = [];
        foreach ($requests as $index => $request) {
            try {
                $data = match (strtoupper((string) ($request['method'] ?? ''))) {
                    'GET' => $this->get(
                        (string) ($request['product'] ?? ''),
                        (string) ($request['path'] ?? ''),
                        is_array($request['query'] ?? null) ? $request['query'] : []
                    ),
                    'PUT' => $this->put(
                        (string) ($request['product'] ?? ''),
                        (string) ($request['path'] ?? ''),
                        is_array($request['body'] ?? null) ? $request['body'] : []
                    ),
                    default => throw new RuntimeException('Unexpected fake Brightspace batch method.'),
                };
                $responses[$index] = ['status' => 200, 'data' => $data, 'error' => null];
            } catch (Throwable $exception) {
                $responses[$index] = ['status' => 500, 'data' => null, 'error' => $exception->getMessage()];
            }
        }

        return $responses;
    }
}

final class FakeAgentDetailNotFoundBrightspaceClient extends FakeBrightspaceClient
{
    public function get(string $product, string $path, array $query = []): array
    {
        if (preg_match('~/agents/\d+/?$~', $path) === 1) {
            throw new RuntimeException('Brightspace response HTTP 404.');
        }
        if (preg_match('~/agents/?$~', $path) === 1) {
            return ['Items' => array_values($this->agents)];
        }

        throw new RuntimeException('Unexpected fake Brightspace GET: ' . $path);
    }

    public function requestBatchJson(array $requests): array
    {
        $responses = [];
        foreach ($requests as $index => $request) {
            if (
                strtoupper((string) ($request['method'] ?? '')) === 'GET'
                && preg_match('~/agents/\d+/?$~', (string) ($request['path'] ?? '')) === 1
            ) {
                $responses[$index] = [
                    'status' => 404,
                    'data' => null,
                    'error' => 'Brightspace response HTTP 404.',
                ];
                continue;
            }

            $response = parent::requestBatchJson([$index => $request]);
            $responses[$index] = $response[$index];
        }

        return $responses;
    }
}

$campaignDatabase = new PDO('sqlite::memory:');
$campaignDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$campaignDatabase->exec("CREATE TABLE reminder_campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, inactivity_days INTEGER, subject TEXT, body TEXT, status TEXT, created_by TEXT)");
$campaignDatabase->exec("CREATE TABLE reminder_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, enrollment_id INTEGER, email TEXT, status TEXT)");
$candidates = [
    ['enrollment_id' => 10, 'institutional_email' => 'uno@estud.tesa.edu.ec'],
    ['enrollment_id' => 11, 'institutional_email' => 'dos@estud.tesa.edu.ec'],
];
$campaigns = new ReminderCampaignService($campaignDatabase, new FakeInactivityService($candidates));
assertSameValue(2, $campaigns->prepare(8), 'La campaÃ±a inicial debe crear destinatarios.');
assertSameValue(2, $campaigns->prepare(8), 'Actualizar una campaÃ±a no debe perder destinatarios pendientes.');
assertSameValue(2, (int) $campaignDatabase->query('SELECT COUNT(*) FROM reminder_recipients')->fetchColumn(), 'La campaÃ±a actualizada debe conservar sus destinatarios.');

$campaignDatabase->exec(
    "CREATE TABLE shared_email_draft (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        recipient TEXT NOT NULL DEFAULT '',
        subject TEXT NOT NULL DEFAULT '',
        body TEXT NOT NULL DEFAULT '',
        updated_by TEXT NULL,
        updated_at TEXT NULL
    )"
);
$campaignDatabase->exec("INSERT INTO shared_email_draft (id, recipient, subject, body) VALUES (1, '', '', '')");
$campaignDatabase->exec(
    'CREATE TABLE shared_email_scope_drafts (
        period TEXT NOT NULL,
        career TEXT NOT NULL,
        recipient TEXT NOT NULL,
        body TEXT NOT NULL,
        updated_by TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (period, career)
    )'
);
$campaignDatabase->exec(
    'CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor TEXT NOT NULL,
        action TEXT NOT NULL,
        context TEXT NOT NULL,
        ip_address TEXT NULL
    )'
);
$draftCampaignCount = (int) $campaignDatabase->query('SELECT COUNT(*) FROM reminder_campaigns')->fetchColumn();
$draftRecipientCount = (int) $campaignDatabase->query('SELECT COUNT(*) FROM reminder_recipients')->fetchColumn();
$sharedDraftService = new SharedEmailDraftService($campaignDatabase);
assertSameValue('', $sharedDraftService->get()['recipient'], 'El borrador global debe iniciar vacío y seguro.');
$defaultBody = "Mensaje más común";
$defaultRecipient = '{InitiatingUser}';
$defaultAgents = [
    [
        'Condition' => ['CourseActivity' => ['Days' => 8]],
        'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => $defaultRecipient, 'Message' => $defaultBody]],
    ],
    [
        'Condition' => ['CourseActivity' => ['Days' => '15']],
        'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => $defaultRecipient, 'Message' => $defaultBody]],
    ],
    [
        'Condition' => ['CourseActivity' => ['Days' => 30]],
        'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => 'otro@example.org', 'Message' => '<p>Otro</p>']],
    ],
    [
        'Condition' => ['CourseActivity' => ['Days' => 8]],
        'Action' => ['EmailAction' => ['IsEnabled' => false, 'To' => 'otro@example.org', 'Message' => '<p>Otro</p>']],
    ],
    [
        'Condition' => ['CourseActivity' => ['Days' => 31]],
        'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => 'otro@example.org', 'Message' => '<p>Otro</p>']],
    ],
    [
        'Condition' => ['CourseActivity' => ['Days' => 15]],
        'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => '', 'Message' => '<p>Sin destinatario</p>']],
    ],
];
$defaultDraft = SharedEmailDraftService::withInitialDefaults($sharedDraftService->get(), $defaultAgents, true);
assertSameValue($defaultRecipient, $defaultDraft['recipient'], 'El borrador nuevo debe usar el par (To, Message) válido más común.');
assertSameValue($defaultBody, $defaultDraft['body'], 'El borrador nuevo debe usar el cuerpo del par más común.');
assertSameValue(
    "Primera línea\n\nSegunda línea\n- Punto importante",
    SharedEmailDraftService::normalizeBody('<p>Primera línea</p><p>Segunda línea<br>- Punto importante</p>'),
    'El mensaje del agente debe mostrarse sin etiquetas HTML y conservar sus saltos de párrafo.'
);
assertSameValue(
    "<p>Primera línea</p>\n<p>Segunda línea<br />- Punto importante</p>",
    BrightspaceAgentService::formatEmailMessageAsHtml("Primera línea\n\nSegunda línea\n- Punto importante"),
    'El mensaje de texto debe convertirse a HTML con párrafos y saltos de línea para Brightspace.'
);
assertSameValue(
    '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    BrightspaceAgentService::formatEmailMessageAsHtml('<script>alert(1)</script>'),
    'El formato HTML del mensaje debe escapar texto y no permitir inyección de etiquetas.'
);
assertSameValue('', $defaultDraft['subject'], 'El asunto no debe inferirse de los agentes.');
assertSameValue(
    '',
    $sharedDraftService->get()['recipient'],
    'Mostrar valores iniciales no debe persistir automáticamente el borrador.'
);
assertSameValue(
    $defaultDraft,
    SharedEmailDraftService::withInitialDefaults($defaultDraft, $defaultAgents, false),
    'Una solicitud que no sea GET inicial debe conservar sus valores actuales.'
);
assertSameValue(
    null,
    SharedEmailDraftService::mostCommonEmailActionPair([
        ['Condition' => ['CourseActivity' => ['Days' => 8]], 'Action' => ['EmailAction' => ['IsEnabled' => false, 'To' => $defaultRecipient, 'Message' => $defaultBody]]],
        ['Condition' => ['CourseActivity' => ['Days' => 31]], 'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => $defaultRecipient, 'Message' => $defaultBody]]],
    ]),
    'Solo se deben considerar EmailAction activos de 8, 15 o 30 días.'
);
$tieAgents = [
    ['Condition' => ['CourseActivity' => ['Days' => 8]], 'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => 'z@example.org', 'Message' => 'Z']]],
    ['Condition' => ['CourseActivity' => ['Days' => 15]], 'Action' => ['EmailAction' => ['IsEnabled' => true, 'To' => 'a@example.org', 'Message' => 'A']]],
];
assertSameValue(
    SharedEmailDraftService::mostCommonEmailActionPair($tieAgents),
    SharedEmailDraftService::mostCommonEmailActionPair(array_reverse($tieAgents)),
    'Los empates deben resolverse de forma determinista, independientemente del orden del inventario.'
);
assertSameValue(
    true,
    $sharedDraftService->save('destino@example.org', 'Asunto compartido', "Mensaje\npara las clases", 'admin-test'),
    'El primer guardado del borrador compartido debe indicar cambios.'
);
assertSameValue(
    [
        'recipient' => 'destino@example.org',
        'subject' => 'Asunto compartido',
        'body' => "Mensaje\npara las clases",
        'updated_by' => 'admin-test',
    ],
    array_intersect_key($sharedDraftService->get(), array_flip(['recipient', 'subject', 'body', 'updated_by'])),
    'La configuración singleton debe poder recargarse sin depender de los filtros de clases.'
);
assertSameValue(
    false,
    (new SharedEmailDraftService($campaignDatabase))->save('destino@example.org', 'Asunto compartido', "Mensaje\npara las clases", 'admin-test'),
    'Volver a guardar los mismos valores debe marcar changed=false.'
);
assertSameValue(
    true,
    $sharedDraftService->saveMessage('Asunto compartido', 'Mensaje actualizado por carrera y período', 'admin-test'),
    'El mensaje compartido debe poder guardarse sin cambiar el destinatario global heredado.'
);
assertSameValue(
    'destino@example.org',
    $sharedDraftService->get()['recipient'],
    'Guardar el mensaje compartido no debe sobrescribir el destinatario.'
);
$persistedSharedDraft = $sharedDraftService->get();
$submittedDraftWithForgedSubject = SharedEmailDraftService::withPersistedSubject(
    [
        'recipient' => 'destino@example.org',
        'subject' => 'Asunto enviado por POST',
        'body' => 'Mensaje actualizado por carrera y período',
    ],
    $persistedSharedDraft
);
assertSameValue(
    'Asunto compartido',
    $submittedDraftWithForgedSubject['subject'],
    'El asunto recibido junto al borrador debe ignorarse y conservar el valor persistido.'
);
assertSameValue(
    false,
    $sharedDraftService->save(
        $submittedDraftWithForgedSubject['recipient'],
        $submittedDraftWithForgedSubject['subject'],
        $submittedDraftWithForgedSubject['body'],
        'admin-test'
    ),
    'Guardar destinatario y mensaje sin cambios no debe marcar cambios por un asunto manipulado en POST.'
);
assertSameValue(
    'Asunto compartido',
    $sharedDraftService->get()['subject'],
    'El guardado local debe conservar el asunto persistido aunque POST envíe otro.'
);
try {
    $sharedDraftService->save('no-es-un-correo', 'Asunto', 'Mensaje', 'admin-test');
    throw new RuntimeException('El borrador debe rechazar direcciones de correo inválidas.');
} catch (InvalidArgumentException) {
}
assertSameValue(
    'destino@example.org',
    $sharedDraftService->get()['recipient'],
    'Un correo inválido no debe reemplazar el borrador guardado.'
);
try {
    $sharedDraftService->save('{OtherToken}', 'Asunto', 'Mensaje', 'admin-test');
    throw new RuntimeException('El borrador debe rechazar tokens distintos al marcador permitido.');
} catch (InvalidArgumentException) {
}
assertSameValue(
    'destino@example.org',
    $sharedDraftService->get()['recipient'],
    'Un token rechazado no debe reemplazar el borrador guardado.'
);
$careerPeriodDraftService = new CareerPeriodEmailDraftService($campaignDatabase);
assertSameValue(
    true,
    $careerPeriodDraftService->save('26.S3', 'ENF', 'enf@example.org', 'Mensaje ENF S3', 'admin-test'),
    'El borrador debe guardarse por carrera y período.'
);
assertSameValue(
    true,
    $careerPeriodDraftService->save('26.S2', 'ENF', 'enf-s2@example.org', 'Mensaje ENF S2', 'admin-test'),
    'La misma carrera debe admitir configuraciones independientes por período.'
);
assertSameValue(
    ['recipient' => 'enf@example.org', 'body' => 'Mensaje ENF S3'],
    array_intersect_key(
        $careerPeriodDraftService->get('26.S3', 'ENF'),
        array_flip(['recipient', 'body'])
    ),
    'Destinatario y mensaje deben recuperarse por la carrera y período seleccionados.'
);
assertSameValue(
    ['recipient' => 'enf-s2@example.org', 'body' => 'Mensaje ENF S2'],
    array_intersect_key(
        $careerPeriodDraftService->get('26.S2', 'ENF'),
        array_flip(['recipient', 'body'])
    ),
    'Guardar otra combinación no debe sobrescribir la configuración existente.'
);
assertSameValue(
    true,
    $careerPeriodDraftService->saveBody('26.S3', 'ENF', 'Mensaje ENF compartido actualizado', 'admin-test'),
    'El texto del mensaje compartido debe poder actualizarse sin tocar la configuración por clase.'
);
assertSameValue(
    'enf@example.org',
    $careerPeriodDraftService->get('26.S3', 'ENF')['recipient'],
    'Guardar el mensaje compartido no debe reemplazar el destinatario heredado ni tratarlo como alcance global.'
);
assertSameValue(
    'Mensaje ENF compartido actualizado',
    $careerPeriodDraftService->get('26.S3', 'ENF')['body'],
    'El mensaje actualizado debe seguir guardado por carrera y período.'
);
$classEmailDatabase = new PDO('sqlite::memory:');
$classEmailDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$classEmailDatabase->exec('CREATE TABLE courses (
    id INTEGER PRIMARY KEY,
    brightspace_org_unit_id INTEGER NOT NULL UNIQUE,
    academic_period TEXT NOT NULL,
    active INTEGER NOT NULL
)');
$classEmailDatabase->exec('CREATE TABLE students (
    id INTEGER PRIMARY KEY,
    full_name TEXT NOT NULL,
    institutional_email TEXT NOT NULL,
    active INTEGER NOT NULL
)');
$classEmailDatabase->exec('CREATE TABLE enrollments (
    id INTEGER PRIMARY KEY,
    student_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    active INTEGER NOT NULL
)');
$classEmailDatabase->exec('CREATE TABLE shared_email_class_recipients (
    period TEXT NOT NULL,
    career TEXT NOT NULL,
    class_org_unit_id INTEGER NOT NULL,
    recipient TEXT NOT NULL,
    updated_by TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period, career, class_org_unit_id)
)');
$classEmailDatabase->exec("INSERT INTO courses VALUES
    (1, 100, '26.S3', 1), (2, 200, '26.S3', 1), (3, 300, '26.S2', 1)");
$classEmailDatabase->exec("INSERT INTO students VALUES
    (1, 'Ana Uno', 'ana@estud.tesa.edu.ec', 1),
    (2, 'Bruno Dos', 'bruno@estud.tesa.edu.ec', 1),
    (3, 'Carla Tres', 'carla@estud.tesa.edu.ec', 0)");
$classEmailDatabase->exec('INSERT INTO enrollments VALUES
    (1, 1, 1, 1), (2, 2, 2, 1), (3, 3, 1, 1)');
$classRecipientService = new ClassEmailRecipientService($classEmailDatabase);
assertSameValue(
    [['name' => 'Ana Uno', 'email' => 'ana@estud.tesa.edu.ec']],
    $classRecipientService->roster(100, '26.S3'),
    'La lista de estudiantes debe limitarse a las matrículas activas de la clase seleccionada.'
);
assertSameValue(
    [],
    $classRecipientService->roster(100, '26.S2'),
    'La lista no debe incluir estudiantes de otra clase ni otro período.'
);
assertSameValue(
    true,
    $classRecipientService->save('26.S3', 'ENF', 100, 'ana@estud.tesa.edu.ec', 'admin-test'),
    'El destinatario debe guardarse para la clase seleccionada.'
);
assertSameValue(
    true,
    $classRecipientService->save(
        '26.S3',
        'ENF',
        100,
        'ana@estud.tesa.edu.ec; coordinacion@example.org',
        'admin-test'
    ),
    'Se deben guardar varias direcciones separadas por punto y coma para la misma clase.'
);
assertSameValue(
    'ana@estud.tesa.edu.ec, coordinacion@example.org',
    $classRecipientService->get('26.S3', 'ENF', 100),
    'La lista guardada debe normalizarse al delimitador coma de Brightspace.'
);
assertSameValue(
    true,
    $classRecipientService->save('26.S3', 'ENF', 200, 'bruno@estud.tesa.edu.ec', 'admin-test'),
    'Otra clase debe mantener su destinatario independiente.'
);
assertSameValue(
    true,
    $classRecipientService->save('26.S3', 'MED', 100, 'otra@example.org', 'admin-test'),
    'El mismo OrgUnitId no debe filtrar a otra combinación de carrera.'
);
assertSameValue('ana@estud.tesa.edu.ec, coordinacion@example.org', $classRecipientService->get('26.S3', 'ENF', 100), 'La lista de destinatarios de ENF debe leerse solo para la clase 100.');
assertSameValue('bruno@estud.tesa.edu.ec', $classRecipientService->get('26.S3', 'ENF', 200), 'El destinatario de la clase 200 debe ser distinto del de la clase 100.');
assertSameValue('otra@example.org', $classRecipientService->get('26.S3', 'MED', 100), 'El destinatario de otra carrera debe conservarse por separado.');
$classEmailDatabase->exec(
    'CREATE TABLE shared_email_scope_drafts (
        period TEXT NOT NULL,
        career TEXT NOT NULL,
        recipient TEXT NOT NULL,
        body TEXT NOT NULL,
        updated_by TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (period, career)
    )'
);
$classScopeDrafts = new CareerPeriodEmailDraftService($classEmailDatabase);
$classScopeDrafts->saveBody('26.S3', 'ENF', 'Mensaje compartido para toda la carrera', 'admin-test');
assertSameValue(
    'ana@estud.tesa.edu.ec, coordinacion@example.org',
    $classRecipientService->get('26.S3', 'ENF', 100),
    'Guardar el mensaje de carrera y período no debe reemplazar el destinatario guardado para la clase 100.'
);
assertSameValue(
    'bruno@estud.tesa.edu.ec',
    $classRecipientService->get('26.S3', 'ENF', 200),
    'Guardar el mensaje compartido no debe reemplazar el destinatario independiente de la clase 200.'
);
assertSameValue(
    'Mensaje compartido para toda la carrera',
    $classScopeDrafts->get('26.S3', 'ENF')['body'],
    'El mensaje debe persistir independientemente de los destinatarios por clase.'
);
$classRecipientService->save('26.S3', 'ENF', 100, 'ana-nueva@estud.tesa.edu.ec', 'admin-test');
assertSameValue(
    'Mensaje compartido para toda la carrera',
    $classScopeDrafts->get('26.S3', 'ENF')['body'],
    'Guardar un destinatario por curso no debe reemplazar ni alterar el mensaje compartido.'
);
try {
    $classRecipientService->save('26.S3', 'ENF', 100, 'no-es-un-correo', 'admin-test');
    throw new RuntimeException('El destinatario por clase debe rechazar correos inválidos.');
} catch (InvalidArgumentException) {
}
assertSameValue('ana-nueva@estud.tesa.edu.ec', $classRecipientService->get('26.S3', 'ENF', 100), 'Un correo inválido no debe reemplazar el destinatario guardado de la clase.');
foreach ([
    'uno@example.org,',
    'uno@example.org;;dos@example.org',
    'uno@example.org, UNO@example.org',
    '{InitiatingUser}; uno@example.org',
    str_repeat('x', EmailRecipientList::MAX_LENGTH + 1),
] as $invalidRecipientList) {
    try {
        $classRecipientService->save('26.S3', 'ENF', 100, $invalidRecipientList, 'admin-test');
        throw new RuntimeException('La lista de destinatarios debe rechazar separadores vacíos, duplicados, tokens combinados y valores demasiado largos.');
    } catch (InvalidArgumentException) {
    }
}
assertSameValue(
    '{InitiatingUser}',
    EmailRecipientList::normalize(' {InitiatingUser} '),
    'El token dinámico permitido debe conservarse como destinatario individual.'
);
$longRecipientList = implode(', ', array_map(
    static fn (int $index): string => 'recipient' . $index . '@example.org',
    range(1, 30)
));
assertSameValue(
    true,
    $classRecipientService->save('26.S3', 'ENF', 100, $longRecipientList, 'admin-test'),
    'La capacidad de almacenamiento debe permitir una cantidad significativa de destinatarios.'
);
assertSameValue(
    $longRecipientList,
    $classRecipientService->get('26.S3', 'ENF', 100),
    'La base local debe conservar la lista completa sin truncarla.'
);
$currentEmailActionAgents = [
    ['Action' => ['EmailAction' => ['To' => 'primero@example.org; copia@example.org', 'Cc' => 'actual@example.org']]],
    ['Action' => ['EmailAction' => ['To' => 'PRIMERO@example.org', 'Cc' => 'otro@example.org']]],
    ['Action' => ['EmailAction' => ['To' => 'solo-para@example.org', 'Cc' => '']]],
];
assertSameValue(
    'actual@example.org, otro@example.org',
    EmailRecipientList::fromEmailActionCc($currentEmailActionAgents),
    'El campo editable debe derivarse únicamente de Cc de los agentes, unificando valores sin duplicados.'
);
assertSameValue(
    '',
    EmailRecipientList::fromEmailActionCc([
        ['Action' => ['EmailAction' => ['To' => 'solo-para@example.org', 'Cc' => '']]],
    ]),
    'Para no debe aparecer en el campo Cc.'
);
assertSameValue(
    [
        ['value' => 'uno@example.org, dos@example.org', 'agents' => ['Agente A', 'Agente B']],
        ['value' => '', 'agents' => ['Agente C']],
        ['value' => 'otro@example.org', 'agents' => ['Agente D']],
    ],
    EmailRecipientList::groupEmailActionCc([
        ['Name' => 'Agente A', 'Action' => ['EmailAction' => ['Cc' => 'uno@example.org; dos@example.org']]],
        ['Name' => 'Agente B', 'Action' => ['EmailAction' => ['Cc' => ' uno@example.org ,  dos@example.org ']]],
        ['Name' => 'Agente C', 'Action' => ['EmailAction' => ['Cc' => '  ']]],
        ['Name' => 'Agente D', 'Action' => ['EmailAction' => ['Cc' => 'otro@example.org']]],
    ]),
    'El desglose debe normalizar separadores y espacios, y conservar el grupo vacío como valor distinto.'
);
assertSameValue(
    1,
    count(EmailRecipientList::groupEmailActionCc([
        ['Name' => 'Agente A', 'Action' => ['EmailAction' => ['Cc' => 'mismo@example.org; otro@example.org']]],
        ['Name' => 'Agente B', 'Action' => ['EmailAction' => ['Cc' => ' mismo@example.org , otro@example.org ']]],
    ])),
    'No debe mostrarse una diferencia cuando solo cambia el formato de separadores o espacios.'
);
assertSameValue(
    '{InitiatingUser}',
    EmailRecipientList::fromEmailActionCc([
        ['Action' => ['EmailAction' => ['To' => 'ignorado@example.org', 'Cc' => '{InitiatingUser}']]],
    ]),
    'El valor dinámico debe cargarse desde Cc sin tomar Para.'
);
try {
    EmailRecipientList::fromEmailActionCc([
        ['Action' => ['EmailAction' => ['To' => 'ignorado@example.org', 'Cc' => '{InitiatingUser}']]],
        ['Action' => ['EmailAction' => ['To' => '', 'Cc' => 'copia@example.org']]],
    ]);
    throw new RuntimeException('No se deben combinar un token dinámico y correos explícitos de Cc.');
} catch (InvalidArgumentException) {
}
$classTargetAgents = [
    ['AgentId' => 1, 'OrgUnitId' => 100, 'Action' => ['EmailAction' => ['To' => 'old@example.org']]],
    ['AgentId' => 2, 'OrgUnitId' => 100, 'Action' => ['EmailAction' => ['To' => 'old@example.org']]],
    ['AgentId' => 3, 'OrgUnitId' => 200, 'Action' => ['EmailAction' => ['To' => 'old@example.org']]],
    ['AgentId' => 4, 'OrgUnitId' => 100, 'Action' => ['OtherAction' => []]],
];
assertSameValue(
    [1, 2],
    array_column(BrightspaceAgentService::agentsWithEmailActionForOrgUnit($classTargetAgents, 100), 'AgentId'),
    'La aplicación de destinatario debe seleccionar solo agentes con EmailAction de la clase indicada.'
);
assertSameValue(
    [1, 2, 3],
    array_column(BrightspaceAgentService::agentsWithEmailAction($classTargetAgents), 'AgentId'),
    'El mensaje global debe abarcar todos los agentes EmailAction del inventario, incluso en otras clases, carreras y períodos.'
);
assertSameValue(
    $sharedDraftService->withInitialDefaults($sharedDraftService->get(), $defaultAgents, true),
    $sharedDraftService->get(),
    'Un borrador guardado no debe reemplazarse por valores del inventario.'
);
assertSameValue(
    true,
    $sharedDraftService->save($defaultRecipient, '', $defaultBody, 'admin-test'),
    'El token dinámico exacto debe poder guardarse como destinatario literal.'
);
assertSameValue(
    $defaultRecipient,
    $sharedDraftService->get()['recipient'],
    'El token dinámico debe almacenarse literalmente sin resolverse.'
);
try {
    $sharedDraftService->save('destino@example.org', 'Asunto', str_repeat('x', SharedEmailDraftService::MAX_BODY_LENGTH + 1), 'admin-test');
    throw new RuntimeException('El borrador debe rechazar mensajes que superen el límite.');
} catch (InvalidArgumentException) {
}
$_SESSION['admin_username'] = 'admin-test';
(new AuditService($campaignDatabase))->record('shared_email_draft.saved', ['changed' => true]);
$draftAudit = $campaignDatabase->query('SELECT actor, action, context FROM audit_logs')->fetch(PDO::FETCH_ASSOC);
assertSameValue('admin-test', $draftAudit['actor'], 'La auditoría del borrador debe guardar el actor autenticado.');
assertSameValue('shared_email_draft.saved', $draftAudit['action'], 'La auditoría debe identificar el guardado del borrador compartido.');
assertSameValue('{"changed":true}', $draftAudit['context'], 'La auditoría solo debe guardar metadata no sensible del borrador.');
assertSameValue($draftCampaignCount, (int) $campaignDatabase->query('SELECT COUNT(*) FROM reminder_campaigns')->fetchColumn(), 'Guardar el borrador no debe preparar campañas ni activar una ruta de envío.');
assertSameValue($draftRecipientCount, (int) $campaignDatabase->query('SELECT COUNT(*) FROM reminder_recipients')->fetchColumn(), 'Guardar el borrador no debe alterar destinatarios de campañas.');

$reminderAgents = [
    [
        'AgentId' => 1,
        'OrgUnitId' => 10,
        'CourseName' => 'Curso de prueba',
        'Condition' => ['CourseActivity' => ['Days' => 8]],
        'IsEnabled' => true,
    ],
    [
        'AgentId' => 2,
        'OrgUnitId' => 10,
        'CourseName' => 'Nombre duplicado no divide la clase',
        'Condition' => ['CourseActivity' => ['Days' => '15']],
        'IsEnabled' => false,
    ],
    [
        'AgentId' => 3,
        'OrgUnitId' => 20,
        'CourseName' => 'Otra clase',
        'Condition' => ['CourseActivity' => ['Days' => 30]],
        'IsEnabled' => true,
    ],
    [
        'AgentId' => 4,
        'OrgUnitId' => 10,
        'Name' => 'Recordatorio 30 días por nombre solamente',
        'Threshold' => 30,
        'IsEnabled' => true,
    ],
    [
        'AgentId' => 5,
        'OrgUnitId' => 10,
        'Condition' => ['CourseActivity' => ['Days' => 9]],
        'IsEnabled' => true,
    ],
];
$reminderGroups = ReminderClassService::groupByOrgUnit($reminderAgents);
assertSameValue([10, 20], array_keys($reminderGroups), 'Los recordatorios se deben agrupar por OrgUnitId.');
assertSameValue(3, $reminderGroups[10]['active_count'], 'El grupo debe contar todos los agentes activos de la clase.');
assertSameValue(4, count($reminderGroups[10]['agents']), 'El grupo debe incluir recordatorios y otros agentes de la clase.');
assertSameValue(1, count($reminderGroups[10]['thresholds']['8']), 'El grupo debe mostrar el umbral de 8 días.');
assertSameValue(1, count($reminderGroups[10]['thresholds']['15']), 'El grupo debe mostrar el umbral de 15 días.');
assertSameValue([], $reminderGroups[10]['thresholds']['30'], 'Un nombre de agente no debe confirmar por sí solo el umbral.');
$classReminderTargets = ReminderClassService::agentsForOrgUnit($reminderAgents, 10);
assertSameValue(4, count($classReminderTargets), 'Las acciones de clase deben seleccionar todos los agentes de esa clase.');
$classActivationTargets = array_values(array_filter(
    $classReminderTargets,
    static fn (array $agent): bool => !(bool) ($agent['IsEnabled'] ?? false)
));
$classDeactivationTargets = array_values(array_filter(
    $classReminderTargets,
    static fn (array $agent): bool => (bool) ($agent['IsEnabled'] ?? false)
));
assertSameValue([2], array_column($classActivationTargets, 'AgentId'), 'La activación por clase debe limitarse a agentes inactivos de esa clase.');
assertSameValue([1, 4, 5], array_column($classDeactivationTargets, 'AgentId'), 'La desactivación por clase debe incluir todos los agentes activos de esa clase.');
assertSameValue([], ReminderClassService::agentsForOrgUnit($reminderAgents, 0), 'Una OrgUnitId inválida no debe producir agentes objetivo.');
assertSameValue(30, ReminderClassService::verifiedThreshold(['AccessConditionThreshold' => 30]), 'Un umbral explícito de Brightspace debe ser verificable.');
assertSameValue(true, ReminderClassService::isReminderCandidate(['Name' => 'Recordatorio 8 días']), 'Los candidatos detectados solo por nombre no deben conservar una acción individual.');
assertSameValue(
    'A',
    CourseCatalogService::parseCode('26.S3.ADENT1002.PR.A.3288')['parallel'],
    'El código del curso debe permitir recuperar el Módulo A cuando falte la clasificación del agente.'
);
assertSameValue('B', CourseCatalogService::normalizeParallel(' b '), 'La clasificación de módulos debe normalizar mayúsculas y espacios.');

$scheduleTimezone = new DateTimeZone(date_default_timezone_get());
$expectedScheduleStart = (new DateTimeImmutable('2026-02-10 00:00:00', $scheduleTimezone))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d\TH:i:s.000\Z');
$expectedScheduleEnd = (new DateTimeImmutable('2026-02-12 23:59:59', $scheduleTimezone))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d\TH:i:s.000\Z');
assertSameValue(
    ['StartDate' => $expectedScheduleStart, 'EndDate' => $expectedScheduleEnd],
    BrightspaceAgentService::normalizeScheduleDateRange('2026-02-10', '2026-02-12'),
    'El cronograma debe abarcar los días locales completos e inclusivos.'
);
foreach ([
    ['2026-02-30', '2026-03-01'],
    ['2026-2-10', '2026-02-12'],
    ['2026-02-12', '2026-02-10'],
] as [$invalidStartDate, $invalidEndDate]) {
    try {
        BrightspaceAgentService::normalizeScheduleDateRange($invalidStartDate, $invalidEndDate);
        throw new RuntimeException('El cronograma debe rechazar fechas inválidas o invertidas.');
    } catch (InvalidArgumentException) {
    }
}

$courseActivityScopeAgents = [
    ['AgentId' => 71, 'OrgUnitId' => 700, 'period' => '26.S3', 'career' => 'ENF', 'section' => 'A', 'Condition' => ['CourseActivity' => ['Days' => 8]]],
    ['AgentId' => 72, 'OrgUnitId' => 700, 'period' => '26.S3', 'career' => 'ENF', 'section' => 'A', 'Condition' => ['CourseActivity' => ['Days' => 15]]],
    ['AgentId' => 73, 'OrgUnitId' => 700, 'period' => '26.S2', 'career' => 'ENF', 'section' => 'A', 'Condition' => ['CourseActivity' => ['Days' => 30]]],
    ['AgentId' => 74, 'OrgUnitId' => 700, 'period' => '26.S3', 'career' => 'ADM', 'section' => 'A', 'Condition' => ['CourseActivity' => ['Days' => 8]]],
    ['AgentId' => 75, 'OrgUnitId' => 700, 'period' => '26.S3', 'career' => 'ENF', 'section' => 'B', 'Condition' => ['CourseActivity' => ['Days' => 8]]],
    ['AgentId' => 76, 'OrgUnitId' => 700, 'period' => '26.S3', 'career' => 'ENF', 'section' => 'A'],
    ['AgentId' => 77, 'OrgUnitId' => 701, 'period' => '26.S3', 'career' => 'ENF', 'section' => 'A', 'Condition' => ['CourseActivity' => ['Days' => 8]]],
];
$scopedCourseActivityTargets = ReminderClassService::agentsWithCourseActivityForScope(
    $courseActivityScopeAgents,
    700,
    '26.S3',
    'ENF',
    'A'
);
assertSameValue(
    [71, 72],
    array_column($scopedCourseActivityTargets, 'AgentId'),
    'La edición de días debe limitarse a la clase, período, carrera y paralelo seleccionados, y excluir agentes sin CourseActivity.'
);

$courseActivityAgent = [
    'AgentId' => 701,
    'OrgUnitId' => 700,
    'Name' => 'Recordatorio 8 días',
    'IsEnabled' => true,
    'Schedule' => [
        'StartDate' => '2026-02-01T00:00:00.000Z',
        'EndDate' => '2026-12-31T23:59:59.000Z',
        'IsEnabled' => true,
    ],
    'Condition' => [
        'CourseActivity' => ['Days' => 8, 'Operator' => 'LessThan'],
        'OtherCondition' => ['Type' => 'Enrollment', 'Value' => 'active'],
    ],
    'Action' => ['EmailAction' => ['To' => 'estudiantes@example.edu', 'Message' => '<p>Mensaje existente</p>']],
    'CustomSetting' => ['Enabled' => false, 'Value' => 'conservar'],
    'LastRunDate' => '2026-03-01T00:00:00Z',
    'NextRunDate' => '2026-03-08T00:00:00Z',
];
$courseActivitySecondAgent = $courseActivityAgent;
$courseActivitySecondAgent['AgentId'] = 702;
$courseActivitySecondAgent['Condition']['CourseActivity']['Days'] = 30;
$courseActivityClient = new FakeBrightspaceClient([
    701 => $courseActivityAgent,
    702 => $courseActivitySecondAgent,
]);
$courseActivityService = new BrightspaceAgentService($courseActivityClient, 700);
$confirmedCourseActivityAgent = $courseActivityService->setCourseActivityDays($courseActivityAgent, 15);
$courseActivityService->setCourseActivityDays($courseActivitySecondAgent, 8);
$courseActivityPuts = array_values(array_filter(
    $courseActivityClient->requests,
    static fn (array $request): bool => $request['method'] === 'PUT'
));
assertSameValue(15, $confirmedCourseActivityAgent['Condition']['CourseActivity']['Days'], 'El servicio debe confirmar los días leídos de Brightspace.');
assertSameValue(15, $courseActivityPuts[0]['body']['Condition']['CourseActivity']['Days'], 'El primer agente debe recibir su propio valor de días.');
assertSameValue(8, $courseActivityPuts[1]['body']['Condition']['CourseActivity']['Days'], 'El segundo agente debe conservar un valor de días independiente.');
assertSameValue(
    ['Operator' => 'LessThan'],
    array_diff_key($courseActivityPuts[0]['body']['Condition']['CourseActivity'], ['Days' => true]),
    'La edición de Days debe preservar los demás campos de CourseActivity.'
);
assertSameValue(
    $courseActivityAgent['Condition']['OtherCondition'],
    $courseActivityPuts[0]['body']['Condition']['OtherCondition'],
    'La edición debe preservar las otras condiciones del agente.'
);
assertSameValue($courseActivityAgent['Action'], $courseActivityPuts[0]['body']['Action'], 'La edición debe preservar las acciones existentes.');
assertSameValue($courseActivityAgent['Schedule'], $courseActivityPuts[0]['body']['Schedule'], 'La edición no debe cambiar el cronograma.');
assertSameValue($courseActivityAgent['IsEnabled'], $courseActivityPuts[0]['body']['IsEnabled'], 'La edición no debe cambiar el estado del agente.');
assertSameValue($courseActivityAgent['CustomSetting'], $courseActivityPuts[0]['body']['CustomSetting'], 'La edición debe preservar la configuración no relacionada.');

foreach ([0, -1, 1.5, '1.5', '0', '', '1e2'] as $invalidCourseActivityDays) {
    try {
        BrightspaceAgentService::normalizeCourseActivityDays($invalidCourseActivityDays);
        throw new RuntimeException('Los días de actividad deben ser un entero positivo.');
    } catch (InvalidArgumentException) {
    }
}
assertSameValue(8, BrightspaceAgentService::normalizeCourseActivityDays('8'), 'Los días enteros enviados como texto deben normalizarse.');

$agentWithoutCourseActivity = ['AgentId' => 703, 'OrgUnitId' => 700, 'Name' => 'Sin condición'];
$noCourseActivityClient = new FakeBrightspaceClient([703 => $agentWithoutCourseActivity]);
try {
    (new BrightspaceAgentService($noCourseActivityClient, 700))
        ->setCourseActivityDays($agentWithoutCourseActivity, 15);
    throw new RuntimeException('No se debe crear una condición CourseActivity inexistente.');
} catch (RuntimeException $exception) {
    assertSameValue(
        'El agente no tiene una condición de actividad del curso editable.',
        $exception->getMessage(),
        'Los agentes sin CourseActivity deben permanecer no editables.'
    );
}
assertSameValue([], $noCourseActivityClient->requests, 'Un agente sin CourseActivity no debe generar solicitudes a Brightspace.');

$verificationFailureClient = new FakeBrightspaceClient([704 => array_replace($courseActivityAgent, ['AgentId' => 704])]);
$verificationFailureClient->afterPut = static function () use ($verificationFailureClient): void {
    $verificationFailureClient->agents[704]['Condition']['CourseActivity']['Days'] = 8;
};
try {
    (new BrightspaceAgentService($verificationFailureClient, 700))
        ->setCourseActivityDays(array_replace($courseActivityAgent, ['AgentId' => 704]), 15);
    throw new RuntimeException('El servicio no debe confirmar cambios de días que Brightspace no guardó.');
} catch (RuntimeException $exception) {
    assertSameValue(
        true,
        str_contains($exception->getMessage(), 'no confirmó los días'),
        'La verificación debe fallar si Brightspace devuelve otro valor.'
    );
}

$courseActivity404Client = new FakeAgentDetailNotFoundBrightspaceClient([701 => $courseActivityAgent]);
$courseActivity404Confirmed = (new BrightspaceAgentService($courseActivity404Client, 700))
    ->setCourseActivityDays($courseActivityAgent, 22);
assertSameValue(
    22,
    $courseActivity404Confirmed['Condition']['CourseActivity']['Days'],
    'La verificación de Days debe usar la lista de la clase cuando el detalle individual devuelve 404.'
);

$courseActivityPutFailureClient = new class([705 => array_replace($courseActivityAgent, ['AgentId' => 705])]) extends FakeBrightspaceClient {
    public function put(string $product, string $path, array $body): array
    {
        throw new RuntimeException('Fake Brightspace PUT failed.');
    }
};
try {
    (new BrightspaceAgentService($courseActivityPutFailureClient, 700))
        ->setCourseActivityDays(array_replace($courseActivityAgent, ['AgentId' => 705]), 15);
    throw new RuntimeException('Un error de Brightspace no debe reportarse como éxito.');
} catch (RuntimeException $exception) {
    assertSameValue(
        'Fake Brightspace PUT failed.',
        $exception->getMessage(),
        'Los errores de actualización deben propagarse al resultado del agente.'
    );
}

$emailActionInventory = [
    ['AgentId' => 41, 'OrgUnitId' => 100, 'Name' => 'Correo activo', 'IsEnabled' => true, 'Action' => ['EmailAction' => ['IsEnabled' => true]]],
    ['AgentId' => 42, 'OrgUnitId' => 100, 'Name' => 'Correo desactivado', 'IsEnabled' => false, 'Action' => ['EmailAction' => ['IsEnabled' => false]]],
    ['AgentId' => 43, 'OrgUnitId' => 100, 'Name' => 'Sin EmailAction', 'Action' => []],
    ['AgentId' => 0, 'OrgUnitId' => 100, 'Action' => ['EmailAction' => []]],
    ['AgentId' => 44, 'OrgUnitId' => 0, 'Action' => ['EmailAction' => []]],
];
$emailActionTargets = BrightspaceAgentService::agentsWithEmailAction($emailActionInventory);
assertSameValue(
    [41, 42],
    array_column($emailActionTargets, 'AgentId'),
    'La selección global debe incluir cualquier agente con EmailAction, sin filtrar por estados.'
);
assertSameValue(
    [51, 55],
    array_column(
        BrightspaceAgentService::agentsWithEmailActionForCareerPeriod([
            ['AgentId' => 51, 'OrgUnitId' => 200, 'period' => '26.S3', 'career' => 'ENF', 'Name' => 'Clase A', 'Action' => ['EmailAction' => ['Cc' => 'enf@example.org']]],
            ['AgentId' => 52, 'OrgUnitId' => 200, 'period' => '26.S2', 'career' => 'ENF', 'Name' => 'Otro período', 'Action' => ['EmailAction' => []]],
            ['AgentId' => 53, 'OrgUnitId' => 201, 'period' => '26.S3', 'career' => 'ADM', 'Name' => 'Otra carrera', 'Action' => ['EmailAction' => []]],
            ['AgentId' => 54, 'OrgUnitId' => 200, 'period' => '26.S3', 'career' => 'ENF', 'Name' => 'Sin correo', 'Action' => []],
            ['AgentId' => 55, 'OrgUnitId' => 201, 'period' => '26.S3', 'career' => 'INST', 'Name' => 'Clase B de la carrera', 'Action' => ['EmailAction' => ['Cc' => 'enf@example.org']]],
        ], 'ENF', '26.S3'),
        'AgentId'
    ),
    'La actualización de correo debe incluir toda la carrera del período seleccionado y excluir otras carreras/períodos.'
);
$careerEmailActionInventory = [
    ['AgentId' => 51, 'OrgUnitId' => 200, 'period' => '26.S3', 'career' => 'ENF', 'Action' => ['EmailAction' => ['Cc' => 'enf@example.org']]],
    ['AgentId' => 52, 'OrgUnitId' => 200, 'period' => '26.S2', 'career' => 'ENF', 'Action' => ['EmailAction' => ['Cc' => 'otro-periodo@example.org']]],
    ['AgentId' => 53, 'OrgUnitId' => 201, 'period' => '26.S3', 'career' => 'ADM', 'Action' => ['EmailAction' => ['Cc' => 'otra-carrera@example.org']]],
];
$careerEmailActionAgents = BrightspaceAgentService::emailActionAgentsForCareerPeriod(
    $careerEmailActionInventory,
    'ENF',
    '26.S3'
);
assertSameValue(
    'enf@example.org',
    EmailRecipientList::fromEmailActionCc($careerEmailActionAgents),
    'El campo CC debe cargar los valores de Brightspace de la carrera y período seleccionados.'
);
$scopeTargetInventory = [
    ['AgentId' => 61, 'OrgUnitId' => 610, 'period' => '26.S3', 'career' => 'ENF', 'Action' => ['EmailAction' => []]],
    ['AgentId' => 62, 'OrgUnitId' => 610, 'period' => '26.S3', 'career' => 'ENF', 'Action' => ['EmailAction' => []]],
    ['AgentId' => 63, 'OrgUnitId' => 620, 'period' => '26.S3', 'career' => 'ENF', 'Action' => ['EmailAction' => []]],
    ['AgentId' => 64, 'OrgUnitId' => 630, 'period' => '26.S3', 'career' => 'ADM', 'Action' => ['EmailAction' => []]],
    ['AgentId' => 65, 'OrgUnitId' => 640, 'period' => '26.S2', 'career' => 'ENF', 'Action' => ['EmailAction' => []]],
];
$careerPeriodMessageTargets = BrightspaceAgentService::agentsWithEmailActionForCareerPeriod(
    $scopeTargetInventory,
    'ENF',
    '26.S3'
);
$classRecipientTargets = BrightspaceAgentService::agentsWithEmailActionForOrgUnit(
    array_values(array_filter(
        $scopeTargetInventory,
        static fn (array $agent): bool => $agent['period'] === '26.S3'
            && $agent['career'] === 'ENF'
    )),
    610
);
assertSameValue(
    [61, 62, 63],
    array_column($careerPeriodMessageTargets, 'AgentId'),
    'La aplicación del mensaje debe alcanzar todos los agentes EmailAction de la carrera y período, incluso en otras clases.'
);
assertSameValue(
    [61, 62],
    array_column($classRecipientTargets, 'AgentId'),
    'La aplicación del destinatario debe alcanzar solo los agentes EmailAction del curso seleccionado.'
);
assertSameValue(
    [],
    BrightspaceAgentService::agentsWithEmailActionForCareerPeriod($emailActionInventory, '', '26.S3'),
    'No se deben seleccionar agentes si no se indicó carrera.'
);
$originalEmailAgent = [
    'AgentId' => 41,
    'OrgUnitId' => 100,
    'Name' => 'Correo activo',
    'IsEnabled' => false,
    'Condition' => ['CourseActivity' => ['Days' => 8, 'CustomSetting' => ['mode' => 'strict']]],
    'Action' => [
        'EmailAction' => [
            'IsEnabled' => false,
            'To' => 'anterior@example.org',
            'Cc' => 'copia-anterior@example.org',
            'Subject' => 'Asunto individual que debe conservarse',
            'Message' => 'Mensaje anterior',
            'AdditionalProperty' => ['keep' => true],
        ],
        'OtherAction' => ['Enabled' => true, 'Value' => 17],
    ],
    'Schedule' => ['IsEnabled' => true, 'StartDate' => '2026-01-01T00:00:00.000Z'],
    'LastRunDate' => '2026-02-01T00:00:00.000Z',
    'NextRunDate' => '2026-02-02T00:00:00.000Z',
];
$applyDraftInput = SharedEmailDraftService::withPersistedSubject(
    [
        'recipient' => 'compartido@example.org',
        'subject' => 'Asunto enviado por POST',
        'body' => '<p>Mensaje común</p>',
    ],
    $persistedSharedDraft
);
assertSameValue(
    'Asunto compartido',
    $applyDraftInput['subject'],
    'El inicio de la aplicación en Brightspace debe ignorar el asunto enviado y usar el asunto persistido.'
);
$fakeBrightspace = new FakeBrightspaceClient([41 => $originalEmailAgent]);
$emailAgentService = new BrightspaceAgentService($fakeBrightspace, 100);
$confirmedEmailAgent = $emailAgentService->updateEmailAction(
    41,
    100,
    $applyDraftInput['recipient'],
    $applyDraftInput['body']
);
assertSameValue('compartido@example.org', $confirmedEmailAgent['Action']['EmailAction']['To'], 'El destinatario compartido debe verificarse en Brightspace.');
assertSameValue(
    '<p>Mensaje común</p>',
    $confirmedEmailAgent['Action']['EmailAction']['Message'],
    'El mensaje enviado a Brightspace debe incluir HTML para conservar el formato.'
);
assertSameValue('Asunto individual que debe conservarse', $confirmedEmailAgent['Action']['EmailAction']['Subject'], 'La actualización debe preservar cada asunto individual.');
assertSameValue(false, $confirmedEmailAgent['Action']['EmailAction']['IsEnabled'], 'La actualización debe preservar el estado de EmailAction.');
assertSameValue(false, $confirmedEmailAgent['IsEnabled'], 'La actualización debe preservar el estado del agente.');
assertSameValue($originalEmailAgent['Condition'], $confirmedEmailAgent['Condition'], 'La actualización debe preservar los otros ajustes de condición.');
assertSameValue($originalEmailAgent['Action']['OtherAction'], $confirmedEmailAgent['Action']['OtherAction'], 'La actualización debe preservar las otras acciones.');
assertSameValue($originalEmailAgent['Schedule'], $confirmedEmailAgent['Schedule'], 'La actualización debe preservar el cronograma.');
$messageOnlyClient = new FakeBrightspaceClient([41 => $originalEmailAgent]);
$messageOnlyAgent = (new BrightspaceAgentService($messageOnlyClient, 100))
    ->updateEmailActionMessage(41, 100, 'Mensaje compartido de carrera');
assertSameValue(
    'anterior@example.org',
    $messageOnlyAgent['Action']['EmailAction']['To'],
    'Aplicar el mensaje a carrera y período no debe cambiar el destinatario del agente.'
);
assertSameValue(
    '<p>Mensaje compartido de carrera</p>',
    $messageOnlyAgent['Action']['EmailAction']['Message'],
    'La aplicación de mensaje debe actualizar el contenido compartido.'
);
$secondBatchAgent = $originalEmailAgent;
$secondBatchAgent['AgentId'] = 45;
$secondBatchAgent['Name'] = 'Segundo correo activo';
$messageBatchClient = new FakeBrightspaceClient([
    41 => $originalEmailAgent,
    45 => $secondBatchAgent,
]);
$messageBatchResults = (new BrightspaceAgentService($messageBatchClient, 100))
    ->updateEmailActionMessages([
        ['AgentId' => 41, 'OrgUnitId' => 100, 'Name' => 'Correo activo'],
        ['AgentId' => 45, 'OrgUnitId' => 100, 'Name' => 'Segundo correo activo'],
    ], 'Mensaje compartido por lotes');
assertSameValue(
    [true, true],
    array_map(
        static fn (array $result): bool => is_array($result['confirmed_agent'] ?? null)
            && ($result['error'] ?? null) === null,
        $messageBatchResults
    ),
    'La actualización por lotes debe devolver confirmación individual para cada agente.'
);
assertSameValue(
    ['GET', 'GET', 'PUT', 'PUT', 'GET', 'GET'],
    array_column($messageBatchClient->requests, 'method'),
    'La actualización por lotes debe completar las fases de lectura, escritura y verificación.'
);
assertSameValue(
    [
        '<p>Mensaje compartido por lotes</p>',
        '<p>Mensaje compartido por lotes</p>',
    ],
    array_map(
        static fn (array $result): mixed => $result['confirmed_agent']['Action']['EmailAction']['Message'] ?? null,
        $messageBatchResults
    ),
    'Cada agente del lote debe recibir el mensaje y ser verificado.'
);
assertSameValue(
    ['anterior@example.org', 'anterior@example.org'],
    array_map(
        static fn (array $result): mixed => $result['confirmed_agent']['Action']['EmailAction']['To'] ?? null,
        $messageBatchResults
    ),
    'La actualización por lotes debe preservar los destinatarios individuales.'
);
$detail404BatchClient = new FakeAgentDetailNotFoundBrightspaceClient([41 => $originalEmailAgent]);
$detail404BatchResults = (new BrightspaceAgentService($detail404BatchClient, 100))
    ->updateEmailActionMessages([
        ['AgentId' => 41, 'OrgUnitId' => 100, 'Name' => 'Correo con lectura individual 404'],
    ], 'Mensaje verificado desde la lista');
assertSameValue(
    '<p>Mensaje verificado desde la lista</p>',
    $detail404BatchResults[0]['confirmed_agent']['Action']['EmailAction']['Message'] ?? null,
    'Si la lectura individual responde 404, la verificación debe poder confirmar el agente desde la lista de la clase.'
);
assertSameValue(
    null,
    $detail404BatchResults[0]['error'],
    'Un 404 de la ruta individual no debe marcar como fallida una actualización confirmada desde la lista.'
);
$changedSettingsBatchClient = new FakeBrightspaceClient([41 => $originalEmailAgent]);
$changedSettingsBatchClient->afterPut = static function (array &$agent): void {
    $agent['Action']['OtherAction']['Value'] = 18;
};
$changedSettingsBatchResult = (new BrightspaceAgentService($changedSettingsBatchClient, 100))
    ->updateEmailActionMessages([
        ['AgentId' => 41, 'OrgUnitId' => 100, 'Name' => 'Correo activo'],
    ], 'Mensaje compartido por lotes');
assertSameValue(
    null,
    $changedSettingsBatchResult[0]['confirmed_agent'],
    'Una respuesta que cambia otra configuración del agente no debe contar como confirmación válida.'
);
assertSameValue(
    true,
    str_contains((string) $changedSettingsBatchResult[0]['error'], 'Brightspace cambió otra configuración'),
    'La actualización por lotes debe informar si Brightspace altera otros ajustes.'
);
$recipientOnlyClient = new FakeBrightspaceClient([41 => $originalEmailAgent]);
$recipientOnlyClient->afterPut = static function (array &$agent): void {
    $agent['Action']['EmailAction']['Cc'] = str_replace(
        ', ',
        ';',
        $agent['Action']['EmailAction']['Cc']
    );
};
$recipientOnlyAgent = (new BrightspaceAgentService($recipientOnlyClient, 100))
    ->updateEmailActionCcRecipient(41, 100, 'carrera@example.org; copia@example.org');
assertSameValue(
    'anterior@example.org',
    $recipientOnlyClient->requests[1]['body']['Action']['EmailAction']['To'],
    'Al actualizar Cc, Para debe conservarse sin cambios en Brightspace.'
);
assertSameValue(
    'carrera@example.org, copia@example.org',
    $recipientOnlyClient->requests[1]['body']['Action']['EmailAction']['Cc'],
    'Brightspace debe recibir la lista en CC con delimitador de coma.'
);
assertSameValue(
    'anterior@example.org',
    $recipientOnlyAgent['Action']['EmailAction']['To'],
    'La verificación debe confirmar que Para se conservó.'
);
assertSameValue(
    'carrera@example.org, copia@example.org',
    EmailRecipientList::normalize($recipientOnlyAgent['Action']['EmailAction']['Cc']),
    'La verificación debe aceptar un CC equivalente devuelto con delimitador de punto y coma.'
);
assertSameValue(
    'Mensaje anterior',
    $recipientOnlyAgent['Action']['EmailAction']['Message'],
    'Aplicar CC no debe modificar el mensaje.'
);
assertSameValue(
    $originalEmailAgent['Action']['EmailAction']['Subject'],
    $recipientOnlyAgent['Action']['EmailAction']['Subject'],
    'Aplicar destinatarios debe conservar el asunto del agente.'
);
assertSameValue(
    $originalEmailAgent['Condition'],
    $recipientOnlyAgent['Condition'],
    'Aplicar destinatarios debe conservar las condiciones del agente.'
);
assertSameValue(
    $originalEmailAgent['Schedule'],
    $recipientOnlyAgent['Schedule'],
    'Aplicar destinatarios debe conservar el cronograma del agente.'
);
assertSameValue(
    $originalEmailAgent['Action']['OtherAction'],
    $recipientOnlyAgent['Action']['OtherAction'],
    'Aplicar destinatarios debe conservar otras acciones del agente.'
);
$clearRecipientClient = new FakeBrightspaceClient([41 => $originalEmailAgent]);
$clearedRecipientAgent = (new BrightspaceAgentService($clearRecipientClient, 100))
    ->updateEmailActionCcRecipient(41, 100, '');
assertSameValue(
    '',
    $clearRecipientClient->requests[1]['body']['Action']['EmailAction']['Cc'],
    'Un campo vacío debe permitir quitar todos los destinatarios CC.'
);
assertSameValue(
    'anterior@example.org',
    $clearedRecipientAgent['Action']['EmailAction']['To'],
    'Limpiar Cc no debe modificar el destinatario Para del agente.'
);
assertSameValue(
    ['GET', 'PUT', 'GET'],
    array_column($fakeBrightspace->requests, 'method'),
    'Cada agente debe actualizarse con una lectura fresca, una escritura y una lectura de verificación.'
);
foreach ([
    static function (array &$agent): void { $agent['IsEnabled'] = true; },
    static function (array &$agent): void { $agent['Action']['EmailAction']['IsEnabled'] = true; },
    static function (array &$agent): void { $agent['Action']['EmailAction']['Subject'] = 'Asunto modificado'; },
    static function (array &$agent): void { $agent['Schedule']['IsEnabled'] = false; },
] as $mutateUnrelatedSetting) {
    $fakeBrightspaceWithMutation = new FakeBrightspaceClient([41 => $originalEmailAgent]);
    $fakeBrightspaceWithMutation->afterPut = $mutateUnrelatedSetting;
    try {
        (new BrightspaceAgentService($fakeBrightspaceWithMutation, 100))->updateEmailAction(
            41,
            100,
            'compartido@example.org',
            '<p>Mensaje común</p>'
        );
        throw new RuntimeException('La actualización de correo debe detectar cambios no solicitados en otros ajustes.');
    } catch (RuntimeException $exception) {
        assertSameValue(
            true,
            str_contains($exception->getMessage(), 'cambió otra configuración'),
            'La comprobación de preservación debe rechazar cambios en ajustes ajenos al correo.'
        );
    }
}

$tokenStore = new OAuthTokenStore(new PDO('sqlite::memory:'), 'test-encryption-key');
$encrypt = new ReflectionMethod(OAuthTokenStore::class, 'encrypt');
$decrypt = new ReflectionMethod(OAuthTokenStore::class, 'decrypt');
$cipher = $encrypt->invoke($tokenStore, 'access-test');
assertSameValue('access-test', $decrypt->invoke($tokenStore, $cipher['ciphertext'], $cipher['iv'], $cipher['tag']), 'El token de acceso debe descifrarse correctamente.');

$credentialKey = 'test-admin-encryption-key';
$encryptedUsername = AdminUsernameCipher::encrypt('test-admin', $credentialKey);
assertSameValue(
    'test-admin',
    AdminUsernameCipher::decrypt($encryptedUsername, $credentialKey),
    'El usuario admin debe poder cifrarse y descifrarse con la clave configurada.'
);
putenv('APP_LOG_LEVEL=CRITICAL');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$usersDatabase = new PDO('sqlite::memory:');
$usersDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$usersDatabase->exec(
    "CREATE TABLE app_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        is_active INTEGER NOT NULL
    )"
);
$userInsert = $usersDatabase->prepare(
    'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (?, ?, ?, ?)'
);
$userInsert->execute(['test-admin', password_hash('test-password', PASSWORD_DEFAULT), 'admin', 1]);
$userInsert->execute(['test-operator', password_hash('operator-password', PASSWORD_DEFAULT), 'user', 1]);
$userInsert->execute(['disabled-admin', password_hash('disabled-password', PASSWORD_DEFAULT), 'admin', 0]);
assertSameValue(false, admin_login('test-admin', 'wrong-password', $usersDatabase), 'El login debe rechazar contraseñas incorrectas.');
assertSameValue(true, admin_login('test-admin', 'test-password', $usersDatabase), 'El login debe verificar la contraseña guardada en app_users.');
assertSameValue('test-admin', $_SESSION['admin_username'], 'La sesión debe almacenar el usuario solo tras login exitoso.');
assertSameValue(true, is_admin_authenticated(), 'Una cuenta con rol admin debe tener permisos administrativos.');
admin_logout();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
assertSameValue(false, admin_login('disabled-admin', 'disabled-password', $usersDatabase), 'El login debe rechazar cuentas desactivadas.');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
assertSameValue(true, admin_login('test-operator', 'operator-password', $usersDatabase), 'El login debe permitir cuentas operativas.');
assertSameValue(false, is_admin_authenticated(), 'Una cuenta operativa no debe recibir permisos administrativos.');
assertSameValue('user', $_SESSION['admin_role'], 'La sesión debe conservar el rol operativo.');
admin_logout();

echo "TESTS_OK campaign_refresh shared_email_draft email_scope_separation class_email_recipients class_roster_scope email_action_cc_from_brightspace cc_only_update email_action_apply reminder_class_grouping module_filter schedule_dates course_activity_days oauth_token_encryption user_roles\n";