# Producción y rotación de secretos

## Requisitos previos

- PHP 8.0 o superior, con `curl` (incluido `curl_multi`), `openssl`, `pdo_mysql` y `mbstring`.
- MySQL 8.0 recomendado (MySQL 5.7 o superior con soporte para columnas JSON).
- HTTPS válido y certificados CA instalados para que cURL valide Brightspace. No desactives `CURLOPT_SSL_VERIFYPEER`.
- Acceso para configurar variables de entorno y permisos de archivos/directorios.

## Publicar la aplicación

1. Sube el proyecto fuera del directorio público del hosting siempre que sea posible. Configura el **document root** del dominio directamente a la carpeta `public/` de este proyecto. No publiques la raíz que contiene `.env`, `storage/`, `database/`, `scripts/` y `tests/`.
2. Crea una base MySQL y un usuario dedicado para la aplicación con los permisos necesarios sobre esa base. No uses el usuario administrador de MySQL en producción.
3. Crea `.env` en la raíz privada del proyecto; **no subas el `.env` local**. Configura la conexión y las credenciales reales del entorno, al menos:

   ```dotenv
   APP_TIMEZONE=America/Guayaquil
   DB_HOST=host-mysql-del-proveedor
   DB_PORT=3306
   DB_DATABASE=base_de_datos
   DB_USERNAME=usuario_de_aplicacion
   DB_PASSWORD=contraseña_de_base_de_datos
   APP_ENCRYPTION_KEY=clave-aleatoria-larga-y-privada
   ADMIN_USERNAME_ENCRYPTED=usuario-cifrado
   ADMIN_PASSWORD_HASH=hash-de-contraseña
   BRIGHTSPACE_BASE_URL=https://tu-instituto.brightspace.com
   BRIGHTSPACE_CLIENT_ID=client-id
   BRIGHTSPACE_CLIENT_SECRET=client-secret
   BRIGHTSPACE_REDIRECT_URI=https://oauth.pstmn.io/v1/callback
   BRIGHTSPACE_API_VERSION=1.93
   BRIGHTSPACE_LP_API_VERSION=1.63
   BRIGHTSPACE_SCOPE=core:*:* enrollment:orgunit:read orgunit:*:* user:*:* intelligentagents:agent:read intelligentagents:agent:update intelligentagents:runs:create intelligentagents:runs:read organizations:organization:read
   BRIGHTSPACE_AGENT_ORG_UNIT_ID=id-de-unidad-organizativa
   ```

   Configura `APP_ENV=production` en las variables de entorno del proceso PHP/hosting, no dependas de incluirlo solo en `.env`: el bootstrap determina el modo de errores antes de cargar ese archivo. El mismo principio aplica a `APP_LOG_LEVEL` si necesitas fijarlo desde el arranque. Las variables del proceso tienen prioridad sobre `.env`; evita valores heredados que contradigan producción. Conserva `ADMIN_USERNAME_ENCRYPTED` y `ADMIN_PASSWORD_HASH` solo hasta importar la cuenta de bootstrap en `app_users`; después puedes retirarlos de `.env`. `APP_ENCRYPTION_KEY` protege los tokens OAuth, no las cuentas locales. No cambies la clave durante una copia o migración sin ejecutar antes la rotación descrita abajo. El código no utiliza `APP_URL`; la URL OAuth del panel se valida a partir de `BRIGHTSPACE_REDIRECT_URI`. `MAIL_FROM_ADDRESS` solo se necesita si se invoca el servicio CLI de envío de campañas; el panel actual no ofrece envío de campañas.
4. En Brightspace, conserva configurada la misma aplicación OAuth (Client ID/Secret) y callback registrado. El flujo configurado por defecto usa Postman y la importación segura del refresh token mediante CLI; si se cambia el dominio o se autoriza desde el panel, registra el callback HTTPS exacto `https://tu-dominio/oauth/callback.php` y configura ese mismo valor en `BRIGHTSPACE_REDIRECT_URI`. Si se usa Postman (`https://oauth.pstmn.io/v1/callback`), el flujo OAuth desde el panel está deshabilitado intencionalmente.
5. Importa `database/schema.sql` si es una base nueva. Para una base existente, haz primero una copia de seguridad y aplica solo las migraciones pendientes: `001` y `002` agregan auditoría y períodos; `003` debe preceder a `005` para el borrador por carrera/período; `006` debe preceder a `007` para conservar las listas históricas por curso; `009` crea las cuentas y roles locales. `004_add_scoped_email_recipients.sql` y `008_create_global_shared_email_draft.sql` son históricas y no habilitan la configuración vigente.
6. Ejecuta `php scripts/initialize_app_users.php` una vez para importar la cuenta de bootstrap. Entra con esa cuenta, define contraseñas para las dos cuentas administrativas iniciales en `/users.php`, actívalas y cambia el rol de la cuenta anterior a usuario operativo. Retira `ADMIN_USERNAME_ENCRYPTED` y `ADMIN_PASSWORD_HASH` de `.env` después de importar.
7. Asegura que el usuario de PHP pueda escribir en `storage/cache/` y `storage/logs/`. No des permisos de escritura globales a todo el proyecto.
8. Comprueba el login en `/login.php`, la conexión a Brightspace y los logs. Programa sincronizaciones CLI solo después de probarlas manualmente y confirmar que su resultado es correcto.

Las rutas `/oauth/authorize.php` y `/oauth/callback.php` requieren una sesión de administrador activa. Una cuenta operativa o una sesión caducada no puede iniciar la autorización ni intercambiar o guardar tokens OAuth.

Las activaciones y desactivaciones masivas se envían en solicitudes consecutivas, una por agente, en lugar de mantener una única solicitud larga. La pantalla muestra el avance y el resultado; si la conexión se interrumpe, recarga para revisar los estados de Brightspace antes de repetir la operación, porque algunos cambios pueden haberse aplicado.

El mensaje se guarda por carrera y período. El campo editable se carga únicamente desde `Cc` en las acciones de correo actuales de Brightspace y no se guarda en una tabla local. Al aplicarlo, el panel modifica solamente `Cc`; conserva `To`, asuntos, mensajes, estados, cronogramas y demás ajustes. Acepta correos separados por coma o punto y coma, con un límite de 2.048 caracteres, y `{InitiatingUser}` como valor único. La acción cambia la configuración del agente, pero no envía correos. Ante errores de conexión/DNS, el panel pausa y conserva el progreso para reanudarlo en el mismo alcance.

Las solicitudes asíncronas del panel devuelven HTTP 401 en JSON cuando caduca la sesión, en vez de seguir una redirección a HTML. Al volver a iniciar sesión se conserva la ejecución pendiente para reanudarla. Si la sesión del servidor ya no conserva el avance, revisa los agentes en Brightspace antes de iniciar una nueva aplicación completa.

El flujo actual usa el callback de Postman e importa el refresh token mediante `php scripts/import_postman_token.php`; con `BRIGHTSPACE_REDIRECT_URI=https://oauth.pstmn.io/v1/callback`, la autorización OAuth desde el navegador del proyecto está intencionalmente deshabilitada. No dependas de una URL temporal de ngrok para producción. Si no transfieres la base de datos con sus tokens OAuth cifrados, importa el refresh token en el servidor desde un entorno CLI seguro y elimina `POSTMAN_REFRESH_TOKEN` de `.env` inmediatamente después. Nunca pongas el refresh token en un archivo público o código PHP. Si prefieres autorizar desde el panel, registra primero en Brightspace el callback HTTPS exacto del hosting (`https://tu-dominio/oauth/callback.php`) y configura el mismo valor en `BRIGHTSPACE_REDIRECT_URI`.

Antes de actualizar la aplicación en producción, respalda tanto la base de datos como `.env`. Para probar una versión nueva, usa una copia de staging de la base y de los archivos; no ejecutes sincronizaciones de prueba contra datos productivos.

## Migraciones de bases existentes

Comprueba primero qué migraciones ya se aplicaron. Ejecuta únicamente las pendientes, en el orden indicado; no vuelvas a ejecutar ninguna:

```powershell
# Independientes; según la versión de la base existente:
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\001_add_audit_logs.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\002_add_academic_periods.sql"

# Borrador de mensaje por carrera y período:
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\003_add_shared_email_draft.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\005_scope_shared_email_drafts_by_career_period.sql"

# Destinatarios por clase:
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\006_add_class_email_recipients.sql"
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\007_expand_class_email_recipient_list.sql"

# Cuentas y roles locales:
cmd.exe /c "mysql -u root -p agentes_inteligentes < database\migrations\009_create_app_users.sql"
php scripts/initialize_app_users.php
```

La migración `003` crea la tabla de borrador inicial y `005` conserva el mensaje por carrera y período. `006` y `007` corresponden a las listas históricas por curso. El campo CC actual se deriva de las acciones de Brightspace y no requiere migración ni tabla de destinatarios. `004` creó una tabla anterior con alcance por módulo y `008` pertenece al antiguo borrador global; no las ejecutes para habilitar el comportamiento actual. El esquema vigente para instalaciones nuevas ya incluye las tablas actuales.

El panel guarda el mensaje por carrera y período y limita su aplicación a ese mismo alcance. La migración histórica `008_create_global_shared_email_draft.sql` no se debe aplicar para habilitar esta función; los borradores por carrera/período se conservan en `shared_email_scope_drafts`.

## Rotar APP_ENCRYPTION_KEY

1. Haz una copia de seguridad de la base de datos y programa una ventana de mantenimiento.
2. Añade temporalmente `NEW_APP_ENCRYPTION_KEY` al `.env` con una clave aleatoria nueva.
3. Ejecuta `php scripts/rotate_encryption_key.php`.
4. El script vuelve a cifrar los tokens OAuth y prepara `NEW_APP_ENCRYPTION_KEY`.
5. Sustituye `APP_ENCRYPTION_KEY` por `NEW_APP_ENCRYPTION_KEY` y elimina la variable temporal.
6. Reinicia PHP y verifica tanto el inicio de sesión del panel como la conexión con Brightspace.

No reemplaces la clave antes de que el script termine. Si la configuración de tokens no es válida, corrige el error antes de activar la clave nueva. Las cuentas guardadas en `app_users` no dependen de `APP_ENCRYPTION_KEY`.

## Rotar Brightspace

La rotación del secreto se hace en la consola administrativa de Brightspace. Actualiza `BRIGHTSPACE_CLIENT_SECRET` en `.env`, reinicia PHP y vuelve a completar OAuth. Revoca los tokens anteriores desde Brightspace si están disponibles.

## Producción estable

Usa un dominio propio con HTTPS, un servidor web (Apache o Nginx) y PHP-FPM/Apache PHP. Configura `BRIGHTSPACE_REDIRECT_URI` con el callback HTTPS fijo si la autorización se hará desde el panel. ngrok debe usarse únicamente para desarrollo.