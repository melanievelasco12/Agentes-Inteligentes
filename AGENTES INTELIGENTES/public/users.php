<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/auth.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/app/Services/AuditService.php';

require_admin();

$database = Database::connection();
$audit = new AuditService($database);
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$message = null;
$messageType = 'status';
$currentUserId = (int) $_SESSION['admin_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $message = 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if ($action === 'create') {
            if (
                preg_match('/^[^\s\x00-\x1F\x7F]{3,190}$/u', $username) !== 1
                || !in_array($role, ['admin', 'user'], true)
                || strlen($password) < 12
                || strlen($password) > 4096
            ) {
                $message = 'Usa un usuario de 3 a 190 caracteres, una contraseña de 12 a 4.096 caracteres y un rol válido.';
                $messageType = 'error';
            } else {
                try {
                    $database->beginTransaction();
                    $statement = $database->prepare(
                        'INSERT INTO app_users (username, password_hash, role, is_active, created_by)
                         VALUES (:username, :password_hash, :role, TRUE, :created_by)'
                    );
                    $statement->execute([
                        'username' => $username,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => $role,
                        'created_by' => (string) $_SESSION['admin_username'],
                    ]);
                    $audit->record('user.created', ['username' => $username, 'role' => $role]);
                    $database->commit();
                    $message = 'La cuenta se creó correctamente.';
                } catch (PDOException $exception) {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                    if ((string) $exception->getCode() === '23000') {
                        $message = 'Ese nombre de usuario ya existe.';
                    } else {
                        AppLogger::exception($exception, ['context' => 'user_create']);
                        $message = 'No se pudo crear la cuenta. No se guardaron los cambios.';
                    }
                    $messageType = 'error';
                } catch (Throwable $exception) {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                    AppLogger::exception($exception, ['context' => 'user_create']);
                    $message = 'No se pudo crear la cuenta. No se guardaron los cambios.';
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'update') {
            $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if (
                $targetId === false
                || $targetId === null
                || $targetId < 1
                || !in_array($role, ['admin', 'user'], true)
                || ($password !== '' && (strlen($password) < 12 || strlen($password) > 4096))
                || ($targetId === $currentUserId && $isActive === 0)
            ) {
                $message = 'Revisa el usuario, el rol, el estado y la contraseña (mínimo 12 caracteres). No puedes desactivar tu propia cuenta.';
                $messageType = 'error';
            } else {
                try {
                    $database->beginTransaction();
                    $statement = $database->prepare('SELECT id, username, role, is_active FROM app_users WHERE id = :id FOR UPDATE');
                    $statement->execute(['id' => $targetId]);
                    $target = $statement->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($target)) {
                        throw new DomainException('La cuenta que intentas actualizar ya no existe.');
                    }

                    $removesActiveAdmin = $target['role'] === 'admin'
                        && (int) $target['is_active'] === 1
                        && ($role !== 'admin' || $isActive !== 1);
                    if ($removesActiveAdmin) {
                        $admins = $database->query(
                            "SELECT id FROM app_users WHERE role = 'admin' AND is_active = TRUE FOR UPDATE"
                        )->fetchAll(PDO::FETCH_COLUMN);
                        if (count($admins) <= 1) {
                            throw new DomainException('Debe permanecer al menos un administrador activo.');
                        }
                    }

                    $sql = 'UPDATE app_users SET role = :role, is_active = :is_active';
                    $parameters = [
                        'role' => $role,
                        'is_active' => $isActive,
                        'id' => $targetId,
                    ];
                    if ($password !== '') {
                        $sql .= ', password_hash = :password_hash';
                        $parameters['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $sql .= ' WHERE id = :id';
                    $database->prepare($sql)->execute($parameters);
                    $audit->record('user.updated', [
                        'username' => (string) $target['username'],
                        'role' => $role,
                        'is_active' => $isActive === 1,
                        'password_reset' => $password !== '',
                    ]);
                    $database->commit();

                    if ($targetId === $currentUserId) {
                        $_SESSION['admin_role'] = $role;
                    }
                    if ($targetId === $currentUserId && $role !== 'admin') {
                        header('Location: /');
                        exit;
                    }
                    $message = 'La cuenta se actualizó correctamente.';
                } catch (DomainException $exception) {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                    $message = $exception->getMessage();
                    $messageType = 'error';
                } catch (Throwable $exception) {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                    AppLogger::exception($exception, ['context' => 'user_update']);
                    $message = 'No se pudo actualizar la cuenta. No se guardaron los cambios.';
                    $messageType = 'error';
                }
            }
        } else {
            $message = 'La acción solicitada no es válida.';
            $messageType = 'error';
        }
    }
}

$users = $database->query(
    'SELECT id, username, role, is_active, created_at FROM app_users ORDER BY username'
)->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrar usuarios | Agentes Inteligentes TESA</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #241b2e; background: #f7f4fa; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px max(20px, calc((100% - 1120px) / 2)); background: #35144f; color: #fff; }
        header h1 { margin: 0; font-size: 1.2rem; }
        a { color: inherit; }
        header a { padding: 9px 12px; border: 1px solid #d7c4ed; border-radius: 6px; text-decoration: none; }
        main { width: min(1120px, calc(100% - 32px)); margin: 32px auto; }
        section { margin-bottom: 24px; padding: 24px; border: 1px solid #e4dbea; border-radius: 10px; background: #fff; }
        h2 { margin-top: 0; color: #4b2a69; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; align-items: end; }
        label { display: block; margin-bottom: 6px; font-size: .85rem; font-weight: 700; }
        input, select, button { width: 100%; min-height: 40px; padding: 9px 10px; border: 1px solid #cfc3d9; border-radius: 5px; font: inherit; }
        button { border: 0; background: #6d4bb4; color: #fff; cursor: pointer; font-weight: 700; }
        button:hover { background: #5f3fa3; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 9px; border-bottom: 1px solid #e9e2ef; text-align: left; vertical-align: middle; }
        .account-form { min-width: 720px; }
        .inline { display: flex; align-items: center; gap: 8px; }
        .inline input[type=checkbox] { width: 18px; min-height: 18px; }
        .save { min-width: 100px; }
        .message { margin-bottom: 20px; padding: 13px; border-left: 4px solid #4e8e63; background: #eaf5ed; }
        .error { border-color: #9c2f45; background: #f8e7e7; color: #7d2337; }
        .muted { color: #6d6480; font-size: .9rem; }
        @media (max-width: 640px) { header { align-items: flex-start; flex-direction: column; } section { padding: 17px; } }
    </style>
</head>
<body>
    <header>
        <h1>Administrar usuarios</h1>
        <a href="/">Volver al dashboard</a>
    </header>
    <main>
        <?php if ($message !== null): ?>
            <div class="message <?= $messageType === 'error' ? 'error' : '' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>"><?= $escape($message) ?></div>
        <?php endif; ?>
        <section>
            <h2>Crear cuenta</h2>
            <p class="muted">Las contraseñas deben tener al menos 12 caracteres. No reutilices contraseñas de otros servicios.</p>
            <form method="post" class="grid">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <div>
                    <label for="username">Usuario</label>
                    <input id="username" name="username" maxlength="190" autocomplete="off" required>
                </div>
                <div>
                    <label for="password">Contraseña inicial</label>
                    <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                </div>
                <div>
                    <label for="role">Rol</label>
                    <select id="role" name="role">
                        <option value="admin">Administrador</option>
                        <option value="user">Usuario operativo</option>
                    </select>
                </div>
                <div><button type="submit">Crear usuario</button></div>
            </form>
        </section>
        <section>
            <h2>Cuentas existentes</h2>
            <p class="muted">Los usuarios operativos pueden utilizar el dashboard y las funciones de Brightspace, pero no administrar cuentas.</p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Nueva contraseña (opcional)</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <?= $escape($user['username']) ?>
                                <?php if ((int) $user['id'] === $currentUserId): ?><span class="muted">(tu cuenta)</span><?php endif; ?>
                            </td>
                            <td colspan="4">
                                <form method="post" class="account-form grid">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="user_id" value="<?= $escape($user['id']) ?>">
                                    <div>
                                        <label for="role-<?= $escape($user['id']) ?>">Rol</label>
                                        <select id="role-<?= $escape($user['id']) ?>" name="role">
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                            <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Usuario operativo</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="password-<?= $escape($user['id']) ?>">Restablecer contraseña</label>
                                        <input id="password-<?= $escape($user['id']) ?>" name="password" type="password" minlength="12" autocomplete="new-password">
                                    </div>
                                    <div class="inline">
                                        <input id="active-<?= $escape($user['id']) ?>" name="is_active" type="checkbox" <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?> <?= (int) $user['id'] === $currentUserId ? 'disabled' : '' ?>>
                                        <label for="active-<?= $escape($user['id']) ?>">Activa</label>
                                        <?php if ((int) $user['id'] === $currentUserId && (int) $user['is_active'] === 1): ?>
                                            <input type="hidden" name="is_active" value="1">
                                        <?php endif; ?>
                                    </div>
                                    <div><button class="save" type="submit">Guardar</button></div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
