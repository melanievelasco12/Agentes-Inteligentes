<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/auth.php';

// Invalida la sesion actual antes de regresar al formulario de acceso.
admin_logout();
AppLogger::info('Logout de administrador ejecutado.', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
header('Location: /login.php');
exit;
