<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/app/Support/AdminUsernameCipher.php';

$encryptedUsername = trim((string) getenv('ADMIN_USERNAME_ENCRYPTED'));
$passwordHash = trim((string) getenv('ADMIN_PASSWORD_HASH'));
$encryptionKey = (string) getenv('APP_ENCRYPTION_KEY');

if ($encryptedUsername === '' || $passwordHash === '' || $encryptionKey === '') {
    throw new RuntimeException(
        'Configura ADMIN_USERNAME_ENCRYPTED, ADMIN_PASSWORD_HASH y APP_ENCRYPTION_KEY antes de migrar la cuenta actual.'
    );
}

if ((password_get_info($passwordHash)['algoName'] ?? 'unknown') === 'unknown') {
    throw new RuntimeException('ADMIN_PASSWORD_HASH no tiene un formato reconocido; no se modificó la base de datos.');
}

$username = trim(AdminUsernameCipher::decrypt($encryptedUsername, $encryptionKey));
if ($username === '') {
    throw new RuntimeException('El usuario administrativo configurado está vacío; no se modificó la base de datos.');
}

$database = Database::connection();
$findUser = $database->prepare('SELECT id FROM app_users WHERE username = :username LIMIT 1');
$findUser->execute(['username' => $username]);
if ($findUser->fetchColumn() === false) {
    $insert = $database->prepare(
        "INSERT INTO app_users (username, password_hash, role, is_active, created_by)
         VALUES (:username, :password_hash, 'admin', TRUE, 'migration')"
    );
    $insert->execute([
        'username' => $username,
        'password_hash' => $passwordHash,
    ]);
}

$provisionedAdmins = [
    'pmorales@tesa.edu.ec',
    'nadmin1@tesa.edu.ec',
];
$createPendingAdmin = $database->prepare(
    "INSERT INTO app_users (username, password_hash, role, is_active, created_by)
     VALUES (:username, :password_hash, 'admin', FALSE, 'migration')"
);
$createdCount = 0;

foreach ($provisionedAdmins as $adminUsername) {
    if (hash_equals($username, $adminUsername)) {
        throw new RuntimeException('Una cuenta nueva coincide con la cuenta administrativa existente; no se continuó.');
    }

    $findUser->execute(['username' => $adminUsername]);
    if ($findUser->fetchColumn() !== false) {
        continue;
    }

    $temporaryPasswordHash = password_hash(bin2hex(random_bytes(48)), PASSWORD_DEFAULT);
    $createPendingAdmin->execute([
        'username' => $adminUsername,
        'password_hash' => $temporaryPasswordHash,
    ]);
    $createdCount++;
}

echo "Cuenta existente importada. Cuentas administrativas nuevas preparadas: {$createdCount}. Inicia sesión con la cuenta actual, asigna contraseñas y actívalas en /users.php; después cambia el rol de la cuenta actual a usuario operativo.\n";
