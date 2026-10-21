<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AdminUsernameCipher.php';

// Sustituye el usuario de .env por su version cifrada y conserva el hash existente.
$envPath = dirname(__DIR__) . '/.env';
$contents = file_get_contents($envPath);
if (!is_string($contents)) {
    throw new RuntimeException('No se pudo leer el archivo .env.');
}

$values = [];
foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $values[trim($key)] = trim($value);
}

$username = $values['ADMIN_USERNAME'] ?? '';
$passwordHash = $values['ADMIN_PASSWORD_HASH'] ?? '';
$encryptionKey = $values['APP_ENCRYPTION_KEY'] ?? '';
if ($username === '' || $passwordHash === '' || $encryptionKey === '') {
    throw new RuntimeException('Falta el usuario, el hash de contraseña o la clave de cifrado en .env.');
}
if (isset($values['ADMIN_USERNAME_ENCRYPTED'])) {
    throw new RuntimeException('ADMIN_USERNAME_ENCRYPTED ya está configurado; no se modificó .env.');
}
$passwordInfo = password_get_info($passwordHash);
if (($passwordInfo['algoName'] ?? 'unknown') === 'unknown') {
    throw new RuntimeException('ADMIN_PASSWORD_HASH no es un hash de contraseña reconocido; no se modificó .env.');
}

$payload = AdminUsernameCipher::encrypt($username, $encryptionKey);
if (AdminUsernameCipher::decrypt($payload, $encryptionKey) !== $username) {
    throw new RuntimeException('No se pudo verificar el usuario administrador cifrado.');
}

$lines = preg_split('/\r\n|\n|\r/', $contents);
$updatedLines = [];
$replacedUsername = false;
foreach ($lines as $line) {
    if (preg_match('/^\s*ADMIN_USERNAME\s*=/', $line) === 1) {
        $updatedLines[] = 'ADMIN_USERNAME_ENCRYPTED=' . $payload;
        $replacedUsername = true;
    } else {
        $updatedLines[] = $line;
    }
}
if (!$replacedUsername) {
    throw new RuntimeException('No se encontró ADMIN_USERNAME para migrar.');
}
$updated = implode(PHP_EOL, $updatedLines);

$temporaryPath = $envPath . '.migrating';
// El reemplazo atomico permite recuperar el archivo original si la escritura falla.
$backupPath = $envPath . '.credentials-backup-' . bin2hex(random_bytes(6));
if (file_put_contents($temporaryPath, $updated, LOCK_EX) === false) {
    throw new RuntimeException('No se pudo preparar la actualización de .env.');
}
if (!rename($envPath, $backupPath)) {
    unlink($temporaryPath);
    throw new RuntimeException('No se pudo proteger el archivo .env original; no se modificó.');
}
if (!rename($temporaryPath, $envPath)) {
    rename($backupPath, $envPath);
    unlink($temporaryPath);
    throw new RuntimeException('No se pudo instalar el nuevo .env; se restauró el archivo original.');
}
if (!unlink($backupPath)) {
    throw new RuntimeException('El usuario se cifró, pero no se pudo retirar el respaldo temporal de .env.');
}

echo "ADMIN_USERNAME_ENCRYPTED configurado; ADMIN_PASSWORD_HASH validado y conservado.\n";
