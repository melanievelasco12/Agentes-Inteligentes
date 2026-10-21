# 📚 Agentes Inteligentes TESA

Aplicación PHP para conectar TESA con D2L Brightspace, sincronizar cursos y estudiantes, detectar inactividad y preparar campañas de recordatorio.

---

## 🌐 URL de producción

Este repositorio no define una URL de producción. En desarrollo local, la aplicación se abre en `http://localhost:8000/login.php`. Para publicar el sistema, configura el dominio del hosting con `public/` como document root y sigue la guía [DEPLOYMENT.md](./DEPLOYMENT.md).

---

## ✨ Funciones principales

La aplicación integra dos áreas de trabajo:

- **Brightspace:** OAuth 2.0, descubrimiento de agentes por unidad organizativa y acciones para activarlos, desactivarlos o ejecutarlos. Todos los agentes se agrupan por clase con controles para activar o desactivar juntos los de esa clase; las acciones masivas por selección se mantienen.
- **Base local:** catálogo académico, sincronización de matrículas, detección de inactividad y preparación de campañas.
- **Correo por carrera:** el mensaje compartido se guarda por carrera y período. El campo editable se carga exclusivamente desde `Cc` en las acciones de correo de Brightspace; no se guarda una lista local. Al aplicar, solo actualiza `Cc`; `To`, asunto, mensaje, estado, cronograma y demás ajustes se conservan. Admite varias direcciones separadas por coma o punto y coma (máximo 2.048 caracteres, sin duplicados) y `{InitiatingUser}` como único destinatario. No se envían correos desde el panel. En instalaciones existentes, aplica las migraciones pendientes `003_add_shared_email_draft.sql`, `005_scope_shared_email_drafts_by_career_period.sql`, `006_add_class_email_recipients.sql` y `007_expand_class_email_recipient_list.sql` cuando correspondan.

## 🚀 Inicio rápido en Windows

1. Instala PHP con `curl`, `openssl`, `pdo_mysql`, `pdo_sqlite` y `mbstring`, además de MySQL.
2. Configura `.env` en la raíz privada del proyecto con las credenciales de base de datos, Brightspace y la cuenta administrativa actual. No compartas ni subas ese archivo.
3. Crea la base con `database/schema.sql` y aplica únicamente las migraciones que aún no se hayan ejecutado.
4. Si migras una instalación existente, crea la tabla de usuarios e importa la cuenta actual con los pasos de la sección **Autenticación y permisos**.
5. Desde PowerShell, en la raíz del proyecto, inicia el servidor local:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start_local.ps1
   ```

   El script carga `.env`, habilita las extensiones PHP requeridas, configura la validación TLS con el bundle CA local y sirve `public/` en el puerto `8000`. Si el puerto ya está ocupado, usa el servidor existente o detén el proceso anterior antes de volver a iniciarlo.
6. Abre `http://localhost:8000/login.php` e inicia sesión. El dashboard está en `http://localhost:8000/`.

La conexión OAuth puede configurarse para importar un refresh token obtenido con Postman o para autorizar desde el panel con un callback registrado que coincida exactamente en Brightspace y `.env`. Consulta la sección **Configuración** y la guía [DEPLOYMENT.md](./DEPLOYMENT.md) antes de publicar el sistema.

## 🔐 Autenticación y permisos

El panel mantiene las cuentas en `app_users` y valida las contraseñas con hashes de `password_hash()`. Hay dos roles:

- **Administrador:** usa todas las funciones del dashboard y puede crear cuentas, asignar roles, activar o desactivar usuarios y restablecer contraseñas desde `/users.php`.
- **Usuario operativo:** puede usar el dashboard y ejecutar sus operaciones, pero no puede administrar cuentas.

Cada solicitud comprueba que la cuenta siga activa y actualiza el rol desde la base de datos; desactivar una cuenta revoca su acceso en la siguiente petición. Solo una cuenta administradora activa puede iniciar o completar la autorización OAuth de Brightspace. No se permite retirar el rol o desactivar al último administrador activo. Las acciones administrativas se registran en `audit_logs`.

### Migrar la cuenta administrativa actual

En una instalación existente, respalda primero la base de datos y desde la raíz del proyecto aplica la nueva migración y ejecuta el inicializador:

```powershell
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\009_create_app_users.sql"
php scripts/initialize_app_users.php
```

El inicializador importa la cuenta configurada actualmente en `ADMIN_USERNAME_ENCRYPTED` y `ADMIN_PASSWORD_HASH`, sin cambiar su contraseña. También prepara como cuentas administrativas desactivadas a `pmorales@tesa.edu.ec` y `nadmin1@tesa.edu.ec`, sin exponer sus contraseñas iniciales. Entra con la cuenta actual y abre `/users.php`: define una contraseña segura para cada cuenta nueva y actívalas. Después cambia el rol de la cuenta actual a **Usuario operativo**. Mantén al menos una cuenta administradora activa.

En una instalación nueva, `database/schema.sql` ya crea `app_users`. Configura la cuenta de bootstrap actual en `.env` y ejecuta `php scripts/initialize_app_users.php` una vez antes del primer inicio de sesión. No compartas contraseñas por chat ni las guardes en el repositorio.

La cuenta de Brightspace autorizada mediante OAuth también necesita los permisos efectivos correspondientes en las unidades consultadas. El scope OAuth solicitado no sustituye los permisos configurados en Brightspace.

## 🚦 Funciones disponibles

La implementación actual ya permite:

- Conectar Brightspace mediante OAuth 2.0.
- Cifrar y almacenar los tokens OAuth en MySQL.
- Proteger el panel con cuentas individuales y roles operativos o administrativos.
- Consultar agentes inteligentes usando el `OrgUnitId` de cada curso, activarlos, desactivarlos y solicitar su ejecución.
- Sincronizar el catálogo de ofertas y semestres desde la estructura de Brightspace.
- Sincronizar estudiantes y matrículas desde la unidad configurada.
- Registrar la última actividad disponible por matrícula.
- Detectar estudiantes con 8, 15 o 30 días de inactividad.
- Preparar campañas en estado `review`.
- Filtrar por período, una lista global de 17 carreras y paralelo A/B.
- Mantener períodos especiales (Urkund, Samples, Turnitin, Sandbox, Especial y D2L) aunque no tengan cursos.
- Ejecutar activaciones y desactivaciones masivas sin diálogo de confirmación, en solicitudes cortas de un agente por vez, con progreso.

El dashboard está enfocado en los filtros académicos y el control agrupado de agentes por clase. En el panel de correo, el campo Cc se llena exclusivamente con los valores `Cc` actuales de las acciones Brightspace de la carrera y período seleccionados; al aplicarlo, solo cambia `Cc` y conserva `To` y las demás opciones. Solo el mensaje compartido se guarda localmente. Las campañas se preparan en la base local, pero el panel no incluye una función para enviarlas. La suite cubre la actualización de campañas, el alcance de mensajes y la derivación/verificación de destinatarios desde las acciones de Brightspace, el cifrado OAuth y el agrupamiento de agentes.

Las acciones por clase y las acciones masivas requieren un inventario completo y vigente. La carga del dashboard usa la última caché disponible y nunca recorre todas las unidades de Brightspace dentro de una sola petición de página; si falta la caché o venció, inicia su actualización recuperable en segundo plano, en lotes de hasta 8 unidades, y mantiene deshabilitados los cambios masivos hasta terminar. Los errores transitorios de conexión, HTTP 429 y HTTP 5xx se reintentan hasta dos veces por unidad, con solicitudes individuales espaciadas; los errores de permisos no se reintentan. Los avances incompletos solo se reanudan si tienen menos de siete días. No se muestra un diálogo de confirmación para las acciones masivas: al pulsar el botón, la acción comienza directamente. Los botones por clase afectan a todos los agentes de esa clase; las acciones masivas revisan toda la selección filtrada directamente en Brightspace y solo cambian los agentes que lo necesitan, incluso si los estados de la caché quedaron desactualizados. Antes de cambiar un agente, el panel consulta su estado actual; después del `PUT`, lo vuelve a leer en Brightspace y solo entonces registra el estado confirmado en la caché. La aplicación del mensaje solo abarca la carrera y el período seleccionados, procesa grupos de hasta cuatro agentes con solicitudes concurrentes limitadas y escribe la caché una vez por grupo. Si una conexión falla, el grupo se pausa y se puede reanudar; los cambios ya confirmados se repiten de forma idempotente si hace falta. La cookie de sesión dura hasta cuatro horas y la expiración por inactividad sigue siendo de 30 minutos. Si Brightspace no confirma un cambio, el agente se cuenta como error y su estado guardado no se altera. Si se interrumpe una operación, puede quedar parcialmente aplicada; recarga el dashboard para comprobar los estados antes de volver a intentarlo.

## ⚙️ Requisitos

- PHP 8.0 o superior.
- Extensiones PHP: `curl` (incluido `curl_multi`), `openssl`, `pdo_mysql` y `mbstring`. El script de inicio local también comprueba `pdo_sqlite`.
- MySQL 8.0 recomendado (MySQL 5.7 o superior con soporte JSON).
- HTTPS en producción y un certificado CA actualizado para validar TLS en las conexiones a Brightspace.
- Directorios `storage/cache/` y `storage/logs/` escribibles por el usuario de PHP.
- Para producción, document root configurado exclusivamente en `public/`; nunca expongas la raíz privada del proyecto.

## 🏗️ Estructura del proyecto

```text
AGENTES INTELIGENTES/
├── .env                         # Configuración local; privado e ignorado por Git
├── .gitignore
├── DEPLOYMENT.md                # Publicación en hosting y rotación de secretos
├── README.md
├── app/
│   ├── Services/
│   │   ├── AgentClassificationService.php
│   │   ├── AgentDiscoveryService.php
│   │   ├── AuditService.php
│   │   ├── BrightspaceAgentService.php
│   │   ├── BrightspaceSyncService.php
│   │   ├── CareerPeriodEmailDraftService.php
│   │   ├── ClassEmailRecipientService.php
│   │   ├── CourseCatalogService.php
│   │   ├── InactivityService.php
│   │   ├── ReminderCampaignService.php
│   │   ├── ReminderClassService.php
│   │   ├── ReminderSenderService.php
│   │   └── SharedEmailDraftService.php
│   └── Support/
│       ├── AdminUsernameCipher.php
│       └── AppLogger.php
├── config/
│   ├── auth.php                 # Sesión y autenticación administrativa
│   ├── bootstrap.php            # Variables de entorno y configuración común
│   └── database.php             # Conexión PDO
├── database/
│   ├── migrations/
│   │   ├── 001_add_audit_logs.sql
│   │   ├── 002_add_academic_periods.sql
│   │   ├── 003_add_shared_email_draft.sql
│   │   ├── 004_add_scoped_email_recipients.sql
│   │   ├── 005_scope_shared_email_drafts_by_career_period.sql
│   │   ├── 006_add_class_email_recipients.sql
│   │   ├── 007_expand_class_email_recipient_list.sql
│   │   ├── 008_create_global_shared_email_draft.sql # Migración histórica
│   │   │   ├── 009_create_app_users.sql
│   └── schema.sql               # Esquema para una base nueva
├── integrations/
│   └── brightspace/
│       ├── BrightspaceBatchClient.php
│       ├── BrightspaceClient.php
│       ├── OAuthClient.php
│       └── OAuthTokenStore.php
├── public/
│   ├── assets/
│   │   ├── login-ai-background.png
│   │   └── logo-tesa.png
│   ├── oauth/
│   │   ├── authorize.php
│   │   └── callback.php
│   ├── index.php                # Dashboard y control de agentes
│   ├── login.php
│   ├── logout.php
│   └── users.php                # Gestión de cuentas solo para administradores
├── scripts/
│   ├── coverage_report.php
│   ├── import_postman_token.php
│   ├── inactivity_report.php
│   ├── initialize_app_users.php
│   ├── inspect_brightspace.php
│   ├── migrate_admin_username.php
│   ├── prepare_campaigns.php
│   ├── rotate_encryption_key.php
│   ├── start_local.ps1
│   ├── sync_brightspace.php
│   ├── sync_course_catalog.php
│   └── test_brightspace.php
├── storage/                     # Caché/logs de ejecución y CSV de importación versionados
│   ├── cache/
│   ├── exports/
│   ├── imports/
│   └── logs/
├── tests/
│   └── run.php                  # Suite automatizada del proyecto
```

No se usa Composer ni un framework PHP: las dependencias se cargan con `require_once`. `storage/cache/` y `storage/logs/` pueden estar vacíos en una instalación nueva; PHP necesita permisos de escritura en esos directorios. `storage/imports/` contiene CSV de importación versionados por el proyecto.

## 📡 Integración con Brightspace

La aplicación usa OAuth 2.0 para obtener y renovar tokens, que se guardan cifrados en MySQL. El cliente común construye las rutas versionadas `/d2l/api/{producto}/{versión}/...`; Learning Environment y Learning Platform se configuran por separado.

| Área | Uso |
|---|---|
| Estructura académica | Sincronizar ofertas de cursos y períodos oficiales desde Brightspace. |
| Matrículas y actividad | Consultar estudiantes matriculados y su última actividad disponible. |
| Agentes inteligentes | Consultar el inventario y activar, desactivar o ejecutar agentes por unidad organizativa. |
| Acciones de correo | Leer y actualizar únicamente `Cc` sin enviar mensajes desde el panel. |

El resultado depende tanto de los scopes de la aplicación OAuth como de los permisos efectivos de la cuenta autorizada en Brightspace.

## 🔄 Flujo de operación

```text
1. Configurar credenciales y conectar Brightspace por OAuth.
2. Guardar tokens cifrados en `oauth_tokens`.
3. Sincronizar ofertas y períodos con `scripts/sync_course_catalog.php`.
4. Sincronizar estudiantes, matrículas y última actividad con `scripts/sync_brightspace.php`.
5. Consultar candidatos a inactividad y preparar campañas con estado `review`.
6. Usar `public/index.php` para filtrar la estructura académica y controlar agentes.
```

## 🔒 Variables de entorno y políticas de producción

Para desplegar la aplicación con una configuración segura se recomienda:

- `APP_ENV`: `development`, `staging` o `production`.
- `APP_LOG_LEVEL`: `DEBUG`, `INFO`, `WARNING`, `ERROR` o `CRITICAL`.
- `APP_LOG_PATH`: ruta opcional del archivo de logs.
- `APP_TIMEZONE`: zona horaria del sistema.
- `APP_ENCRYPTION_KEY`: clave fuerte para cifrado de tokens OAuth.

En `production` se deben desactivar los errores visibles al usuario y dejar el logging en `INFO` o `WARNING`. El archivo [app/Support/AppLogger.php](./app/Support/AppLogger.php) redigita automáticamente campos sensibles como tokens, contraseñas, secretos y códigos OAuth antes de escribir en disco.

## 🧩 Componentes principales

### Cliente Brightspace

`BrightspaceClient` construye las rutas `/d2l/api/{producto}/{versión}` y realiza solicitudes GET, POST y PUT. Renueva automáticamente el token cuando está próximo a expirar. Para aplicar el mensaje al alcance de carrera y período seleccionado, procesa grupos de hasta cuatro agentes con solicitudes concurrentes limitadas y confirma cada cambio mediante lectura, escritura y verificación antes de avanzar.

`OAuthClient` usa el flujo Authorization Code y el endpoint oficial de tokens de Brightspace. `OAuthTokenStore` cifra los tokens con `aes-256-gcm` antes de guardarlos en la tabla `oauth_tokens`.

### Sincronización

La sincronización del catálogo y la de matrículas son procesos separados:

- `scripts/sync_course_catalog.php` usa LP `1.63` para recorrer de forma paginada la raíz institucional, guardar ofertas de curso y semestres oficiales en `courses` y `academic_periods`.
- La reconciliación conserva las ofertas con período y marca inactivas las unidades antiguas sin período que no aparezcan como ofertas vigentes; no elimina filas.
- `scripts/sync_brightspace.php` consulta la classlist de `BRIGHTSPACE_AGENT_ORG_UNIT_ID`, conserva los roles `Estudiante`/`Student` y correos `@estud.tesa.edu.ec`, y actualiza matrículas y última actividad.
- Ambas sincronizaciones registran su resultado en `synchronization_runs` y usan un bloqueo MySQL.

### Inactividad y campañas

`InactivityService` admite únicamente 8, 15 y 30 días. Excluye matrículas inactivas, estudiantes o cursos inactivos y destinatarios que ya tengan una campaña pendiente o enviada para el mismo período.

`ReminderCampaignService` crea o reutiliza una campaña en estado `review` y registra sus destinatarios en `reminder_recipients`.

`ReminderSenderService` valida la campaña, reemplaza `{nombre}`, intenta enviar mediante `PHP mail()` y marca cada destinatario como `sent` o `failed`.

### Panel web

`public/index.php` requiere una cuenta autenticada y permite activar, desactivar y ejecutar agentes de Brightspace con formularios CSRF. Las cuentas operativas pueden usar el dashboard, pero la gestión de usuarios está restringida a administradores en `public/users.php`. Para cada agente se usa su propia unidad organizativa; si Brightspace no permite el endpoint de detalle, se recupera desde la lista de agentes del curso. Las acciones exitosas se registran en `audit_logs`.

El inventario recorre todas las unidades de curso activas guardadas localmente y la unidad configurada en `BRIGHTSPACE_AGENT_ORG_UNIT_ID`, incluso cuando una unidad no tiene código de curso. También sigue las páginas indicadas por `Next`. La caché registra si todas las unidades respondieron; el panel advierte si hay unidades con `403`, `404`, errores de red o paginación incompleta. Una lista parcial no se presenta como una sincronización completa. Los errores `403` requieren conceder al usuario OAuth permisos de lectura de agentes para esa unidad; un `404` requiere confirmar que el OrgUnitId todavía existe y que admite ese endpoint.

El dashboard agrupa todos los agentes por clase y ofrece tres filtros:

- Período, incluyendo períodos especiales sin cursos.
- Una lista global de 17 carreras, independiente del período elegido.
- Módulo A/B; agrupa los cursos de la carrera, no filtra por asignatura individual.

Los filtros se pasan por la URL, por ejemplo `?period=26.S3&career=ENF&parallel=A`, y se conservan entre sí. Las cantidades de cursos no se muestran en los selectores. El dashboard no incluye paneles de campañas ni resúmenes laterales.

El mensaje compartido se guarda por carrera y período. El campo Cc muestra únicamente los valores `Cc` actuales de las acciones de correo Brightspace del alcance y permite modificarlos sin guardarlos en una tabla local. Al aplicar, solo cambia `Cc`; conserva `To`, asuntos, mensajes, estados, cronogramas y otras opciones. Si los agentes tenían valores Cc diferentes, el campo los reúne sin duplicados. La operación modifica la configuración del agente, pero no envía correos. Usa “Actualizar inventario” antes de editar si Cc se modificó directamente en Brightspace desde la última consulta.

En cada clase, el panel permite consultar las fechas de inicio y fin existentes de cada agente. Los datos se leen desde Brightspace al actualizar el inventario y se muestran en la zona horaria `APP_TIMEZONE`; usa “Actualizar inventario” para volver a consultar las fechas recientes. También se pueden asignar fechas manualmente en bloque desde controles separados para Módulo A y Módulo B. La acción respeta los filtros de período y carrera, procesa solo agentes con un cronograma existente y confirma cada cambio en Brightspace; conserva la frecuencia y los estados actuales del agente y del cronograma. El rango incluye ambos días completos según `APP_TIMEZONE`. La consulta individual sigue disponible debajo de cada clase y sus fechas se actualizan en pantalla cuando Brightspace confirma el cambio.

En la misma sección se muestra la condición “Actividad del curso” de cada agente que ya tenga `Condition.CourseActivity`. Se puede seleccionar uno o varios agentes de la clase y aplicarles un número entero de días; cada agente conserva su valor hasta seleccionarlo. Los agentes sin esa condición no se pueden seleccionar ni reciben una condición nueva. Esta acción no cambia las fechas del cronograma: requiere un inventario completo y vigente, valida la clase y los agentes contra el alcance del servidor, confirma cada actualización releyendo el agente en Brightspace y actualiza la caché solo después de confirmarla.

### Estructura académica

`CourseCatalogService` interpreta el código de unidad organizativa de Brightspace, que sigue el patrón institucional:

```text
26.S3.DIGMOD4003.PR.B.3956 - ATELIER DE ALTA COSTURA
└─┬──┘└──┬───┘ └┬┘ │  └─┬──┘
período carrera  módulo modalidad paralelo
```

El analizador tolera variantes como `DWEB-1001`, `EALOPTATIVA`, paralelos `A1`/`B1`, períodos S/V/E y separadores duplicados. Agrupa `DOSFT` con `DSOFT`, `INST` con `ENF` y `EALOPTATIVA` con `EAL`. Los períodos oficiales que no corresponden a un ciclo académico (Urkund, Samples, Turnitin, Sandbox, Especial y D2L) se muestran con cero cursos si aplica.

## 🗄️ Base de datos

El esquema de `database/schema.sql` crea estas tablas:

| Tabla | Uso |
|---|---|
| `app_users` | Cuentas, hashes de contraseña, roles y estado de acceso. |
| `app_users` | Cuentas, hashes de contraseña, roles y estado de acceso. |
| `oauth_tokens` | Token de acceso y refresh cifrados. |
| `students` | Estudiantes institucionales de Brightspace. |
| `courses` | Cursos y períodos académicos. |
| `academic_periods` | Períodos oficiales de Brightspace, incluso sin cursos. |
| `enrollments` | Relación estudiante-curso y última actividad. |
| `reminder_campaigns` | Campañas y estados de revisión/envío. |
| `reminder_recipients` | Destinatarios y resultado individual. |
| `shared_email_draft` | Borrador heredado de correo compartido. |
| `shared_email_scope_drafts` | Mensaje por carrera y período. |
| `shared_email_class_recipients` | Listas históricas de destinatarios por curso. |
| `synchronization_runs` | Historial básico de sincronizaciones. |
| `audit_logs` | Acciones administrativas sobre agentes. |

`database/schema.sql` incluye la estructura vigente para instalaciones nuevas. En bases existentes, aplica solo las migraciones que todavía falten: `001` y `002` agregan auditoría y períodos; `003` y `005` preparan el borrador por carrera/período; `006` y `007` crean y amplían la tabla histórica de listas por curso. `004` y `008` son históricas; no las ejecutes para habilitar el flujo actual.

Para crear la base de datos nueva desde PowerShell, ejecuta desde la raíz del proyecto:

```powershell
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\schema.sql"
```

## ⚙️ Configuración

Crear `.env` en la raíz del proyecto. El archivo ya está ignorado por Git; nunca se deben guardar credenciales reales en el repositorio.

Variables utilizadas por el código:

```dotenv
APP_ENV=development
APP_TIMEZONE=America/Guayaquil
APP_ENCRYPTION_KEY=clave-secreta-larga

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=agentes_inteligentes
DB_USERNAME=root
DB_PASSWORD=

BRIGHTSPACE_BASE_URL=https://tu-instituto.brightspace.com
BRIGHTSPACE_CLIENT_ID=
BRIGHTSPACE_CLIENT_SECRET=
BRIGHTSPACE_REDIRECT_URI=https://oauth.pstmn.io/v1/callback
BRIGHTSPACE_API_VERSION=1.93
BRIGHTSPACE_LP_API_VERSION=1.63
BRIGHTSPACE_SCOPE=core:*:* enrollment:orgunit:read orgunit:*:* user:*:* intelligentagents:agent:read intelligentagents:agent:update intelligentagents:runs:create intelligentagents:runs:read organizations:organization:read
BRIGHTSPACE_AGENT_ORG_UNIT_ID=
BRIGHTSPACE_AGENT_IDS=
POSTMAN_REFRESH_TOKEN=

MAIL_FROM_ADDRESS=
ADMIN_USERNAME_ENCRYPTED=base64-ciphertext-payload
ADMIN_PASSWORD_HASH=
```

`ADMIN_USERNAME_ENCRYPTED` y `ADMIN_PASSWORD_HASH` se usan únicamente para importar la cuenta administrativa inicial a `app_users`; retíralos de `.env` después de ejecutar `scripts/initialize_app_users.php`. Desde entonces, la autenticación consulta las cuentas y sus hashes en la base de datos. `POSTMAN_REFRESH_TOKEN` es temporal y solo se requiere al importar una autorización nueva; elimínalo de `.env` después de la importación.

El código no consume `APP_URL`, `MAIL_FROM_NAME` ni los ajustes SMTP `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_ENCRYPTION`; el remitente `MAIL_FROM_ADDRESS` solo lo usa el servicio de campañas, cuyo envío no está conectado a una pantalla del panel. La URI de redirección debe coincidir con la registrada en Brightspace. El flujo configurado para este proyecto usa Postman y `https://oauth.pstmn.io/v1/callback`; para autorizar desde el panel, registra en su lugar el callback exacto del sitio, por ejemplo `http://localhost:8000/oauth/callback.php` en local. Configura el Client ID y Client Secret de la misma aplicación OAuth y usa `POSTMAN_REFRESH_TOKEN` solo durante la importación CLI. Después de importar, quita ese valor de `.env`: el token renovable queda guardado cifrado en `oauth_tokens`. El scope `organizations:organization:read` también debe estar habilitado para el cliente OAuth en Brightspace; añadirlo solo al `.env` no concede el permiso.

Cuando `BRIGHTSPACE_REDIRECT_URI` apunta a Postman, el flujo OAuth desde el navegador del panel está intencionalmente bloqueado; usa `scripts/import_postman_token.php`. Para autorizar desde el panel en hosting, registra primero en Brightspace el callback HTTPS exacto del dominio y configura ese mismo valor en `BRIGHTSPACE_REDIRECT_URI`. El script local carga `.env` antes de iniciar PHP. En hosting, configura `APP_ENV` en el entorno del proceso PHP: `config/bootstrap.php` decide el modo de errores antes de leer `.env`; si no se define en el proceso, el valor predeterminado es `production`.

### Importar el refresh token de Postman al proyecto

1. En Postman, autoriza la aplicación Brightspace con **Authorization Code** y confirma que se haya emitido un refresh token.
2. Copia el **Refresh Token**, no el access token, en `POSTMAN_REFRESH_TOKEN` dentro del `.env` ignorado por Git. No lo pegues en un archivo PHP ni lo compartas.
3. Desde la carpeta del proyecto, ejecuta:

   ```powershell
   php scripts/import_postman_token.php
   ```

4. Si la importación tiene éxito, vacía `POSTMAN_REFRESH_TOKEN` en `.env`. El script ya habrá guardado los tokens cifrados en la base de datos. La aplicación los renovará automáticamente usando el refresh token almacenado; no necesita llamar a Postman en cada petición.
5. No ejecutes de nuevo la importación con un refresh token antiguo si Brightspace lo rota: vuelve a obtener uno nuevo en Postman. No uses **Share Token**.

El inventario de agentes se reutiliza durante siete días para que el panel no tenga que esperar un escaneo completo en cada inicio; por eso, después de ese plazo aparece el aviso de actualización. “Actualizar inventario” divide el escaneo en lotes consultados en solicitudes cortas y muestra el avance; los errores transitorios de red, HTTP 429 y HTTP 5xx se reintentan hasta dos veces. Si una solicitud se interrumpe, el botón permite reanudar el progreso guardado en esa pestaña mientras tenga menos de siete días. Al terminar, recarga el panel con los datos actualizados. El descubrimiento usa `intelligentagents:agent:read`, pero el scope OAuth no sustituye los permisos efectivos del usuario de Brightspace. La cuenta que autorizó la aplicación también necesita `IntelligentAgents.ManageAgents` en las unidades donde se consultan agentes. La caché registra el estado `complete`, las unidades escaneadas y las unidades fallidas; si hay errores `403`, `404`, de red o paginación, el panel advierte que el inventario es parcial y el registro `storage/logs/app.log` identifica los `org_unit_id` y estados HTTP observados. Los `403` requieren revisar permisos efectivos; los `404` requieren confirmar que la unidad existe y admite el endpoint. El servicio conserva agentes cacheados de unidades inaccesibles cuando existían datos anteriores para esa unidad, pero esa preservación no reemplaza una consulta exitosa a Brightspace.

`BRIGHTSPACE_API_VERSION` configura Learning Environment (`le`) y `BRIGHTSPACE_LP_API_VERSION` configura Learning Platform (`lp`); Brightspace negocia ambas versiones por separado.

### Proteger las credenciales del panel

El migrador anterior `scripts/migrate_admin_username.php` convierte la variable heredada `ADMIN_USERNAME` en `ADMIN_USERNAME_ENCRYPTED` para la importación inicial. La contraseña solo se conserva como hash generado con `password_hash()` y se comprueba con `password_verify()`. Tras importar la cuenta con `scripts/initialize_app_users.php`, los usuarios y hashes viven en `app_users`; retira las variables de bootstrap y no pongas contraseñas en el código ni en el README.

En instalaciones anteriores que tengan `ADMIN_USERNAME` en `.env`, ejecuta una vez desde la raíz:

```powershell
php scripts/migrate_admin_username.php
```

El migrador sustituye `ADMIN_USERNAME` por `ADMIN_USERNAME_ENCRYPTED`, valida que `ADMIN_PASSWORD_HASH` tenga un formato válido y conserva el hash existente. El archivo `.env` permanece local y está excluido de Git. Tras la migración, comprueba el inicio de sesión habitual en `/login.php`; no publiques el contenido de `.env`.

Si el login falla al iniciar PHP local en Windows, puede haber variables antiguas heredadas por la terminal o extensiones PHP deshabilitadas. Detén el servidor anterior con `Ctrl+C` e inicia el panel con `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start_local.ps1`. Este carga las variables desde `.env` (incluidas las credenciales cifradas), habilita las extensiones PHP que necesita el proyecto y configura `curl.cainfo`/`openssl.cafile` usando el bundle CA guardado en `extras\ssl\cacert.pem` dentro de la instalación de PHP. Si el archivo no existe, descárgalo desde `https://curl.se/ca/cacert.pem` a esa ruta. No desactives la validación TLS ni cambies las credenciales si el problema es únicamente de certificados o configuración del proceso PHP.

Si se cambia `APP_ENCRYPTION_KEY`, usa `scripts/rotate_encryption_key.php`: vuelve a cifrar los tokens OAuth y prepara `NEW_APP_ENCRYPTION_KEY`. Luego sustituye `APP_ENCRYPTION_KEY`, elimina la variable temporal y reinicia PHP. Las cuentas de `app_users` no dependen de esta clave.

El panel queda disponible en `/` únicamente después de iniciar sesión en `/login.php`. Para cerrar la sesión se usa `/logout.php`.

## 🔧 Actualizar el sistema

Antes de publicar cambios, respalda `.env` y la base de datos, valida la versión en un entorno de prueba y revisa si hay migraciones pendientes. En una base existente, aplica cada migración solo una vez y en el orden indicado en la sección **Base de datos**. No reemplaces las credenciales del servidor con las del entorno local.

Para conocer los pasos de publicación, rotación de claves y configuración segura del hosting, consulta [DEPLOYMENT.md](./DEPLOYMENT.md). El document root del dominio debe apuntar a `public/`.

## ▶️ Ejecución y pruebas

Desde la raíz del proyecto, iniciar el servidor local en Windows:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start_local.ps1
```

Para los comandos de sincronización:

```bash
php scripts/test_brightspace.php
php scripts/inspect_brightspace.php
php scripts/sync_course_catalog.php
php scripts/sync_brightspace.php
php scripts/inactivity_report.php
php scripts/prepare_campaigns.php
php scripts/coverage_report.php
php tests/run.php
```

En PowerShell/XAMPP, sustituye `php` por la ruta al ejecutable, por ejemplo `& 'C:\xampp\php\php.exe' .\scripts\test_brightspace.php`.

Antes de sincronizar, revisa la configuración y el destino, especialmente si se trata de Brightspace productivo. Para una base existente, haz respaldo y aplica solo las migraciones que aún falten. En el bloque siguiente, ejecuta únicamente los comandos de migraciones pendientes; no repitas una migración ya aplicada:

```powershell
# En una base existente, ejecuta cada migración aplicable solo si aún no se aplicó.
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\001_add_audit_logs.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\002_add_academic_periods.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\003_add_shared_email_draft.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\005_scope_shared_email_drafts_by_career_period.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\006_add_class_email_recipients.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\007_expand_class_email_recipient_list.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\009_create_app_users.sql"
```

La secuencia de borradores requiere `003` antes de `005`, y las tablas históricas de destinatarios por curso requieren `006` antes de `007`. El campo CC actual se deriva de las acciones cargadas desde Brightspace y no requiere migración ni tabla de destinatarios. `009` crea la tabla de cuentas. No ejecutes `004_add_scoped_email_recipients.sql` ni `008_create_global_shared_email_draft.sql` para habilitar el comportamiento actual. Para una base nueva, basta con `database/schema.sql`; no repitas las migraciones.

Después de aplicar `009` a una base existente, ejecuta una vez `php scripts/initialize_app_users.php` para importar la cuenta administrativa inicial y preparar las nuevas cuentas. Configura sus contraseñas y actívalas desde `/users.php` antes de cambiar la cuenta actual a usuario operativo. Después de iniciar el servidor, abre `http://localhost:8000/login.php`. La URI OAuth debe coincidir exactamente con la registrada en Brightspace.

## Análisis técnico y pendientes

### Riesgos prioritarios

1. **Las cuentas administrativas pueden operar el sistema completo.** Limita el rol administrador a personal autorizado, usa contraseñas únicas y publica el acceso únicamente por HTTPS en producción. Los intentos fallidos se limitan por sesión.
2. **El envío usa `PHP mail()` y no un cliente SMTP real.** Las variables `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` y `MAIL_ENCRYPTION` no son utilizadas por `ReminderSenderService`.
3. **La auditoría es parcial.** Se registran acciones sobre agentes en `audit_logs`; revisiones y envíos de campañas no tienen una auditoría completa.
4. **Las credenciales deben tratarse como comprometidas si el `.env` fue compartido.** Se deben rotar el secreto de Brightspace y `APP_ENCRYPTION_KEY`, revocar tokens existentes y generar valores nuevos.

### Riesgos de operación

- `ReminderSenderService` marca la campaña como `completed` aunque haya destinatarios fallidos.
- El envío no guarda `provider_message_id`, no implementa reintentos y no tiene límite de velocidad.
- No hay script ni pantalla que invoque actualmente `ReminderSenderService::send`; la preparación queda en estado `review`.
- La sincronización filtra por dominio institucional y por roles en español/inglés; cualquier cambio en Brightspace puede dejar registros fuera.
- Los períodos oficiales se sincronizan desde unidades `Semester`; las ofertas se asocian al período de su código. Los registros antiguos sin período que no sean ofertas vigentes quedan inactivos, no se borran.
- La sesión configura cookies `HttpOnly`, `SameSite=Lax`, solo-cookie y `Secure` cuando la petición llega por HTTPS (directamente o mediante `X-Forwarded-Proto=https`).

### Calidad y mantenimiento

- La suite automatizada está en `tests/run.php`; las migraciones SQL están en `database/migrations/`. No hay Composer/autoload ni capas `routes/`, `views/`, `Models/` o `Repositories`; `public/index.php` concentra la interfaz y parte de la lógica.
- Conviene añadir Composer, autoload PSR-4, pruebas unitarias/integración y más cobertura antes de ampliar el sistema.

## Próximos pasos recomendados

1. Rotar secretos expuestos y separar configuración de desarrollo y producción.
2. Integrar un proveedor SMTP autenticado y registrar el resultado del proveedor.
3. Completar la auditoría de campañas y cambios de estado.
4. Agregar pruebas para OAuth, sincronización, filtros de inactividad, `CourseCatalogService` y campañas.
5. Separar la interfaz web de la lógica y agregar una ruta segura para enviar campañas revisadas.
6. Incorporar reintentos controlados, límites de envío y métricas.

## Reglas de seguridad

- No subir `.env`, tokens, contraseñas ni claves de cifrado.
- No imprimir tokens ni secretos en logs o reportes.
- Mantener HTTPS en OAuth y en el panel de producción.
- Configurar el document root web en `public/` y mantener `.env`, `storage/`, `database/`, `scripts/` y `tests/` fuera del acceso web.
- Validar siempre roles, dominios institucionales y estados de campaña en el servidor.
- Requerir revisión administrativa antes de cualquier envío masivo.

## 📞 Contacto del proyecto

- **Desarrolladora:** Melanie Velasco
- **Repositorio:** [Agentes Inteligentes](https://github.com/melanievelasco12/Agentes-inteligentes-)
- **Institución:** Instituto Superior Tecnológico San Antonio (TESA)
- **Correo:** [velascomelanie063@gmail.com](mailto:velascomelanie063@gmail.com)
