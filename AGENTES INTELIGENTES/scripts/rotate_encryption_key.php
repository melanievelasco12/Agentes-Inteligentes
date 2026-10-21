<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once PROJECT_ROOT . '/config/database.php';
require_once PROJECT_ROOT . '/integrations/brightspace/OAuthTokenStore.php';

// Re-cifra los tokens OAuth antes de activar la clave nueva en .env.
$newKey = trim((string) getenv('NEW_APP_ENCRYPTION_KEY'));
$oldKey = trim((string) getenv('APP_ENCRYPTION_KEY'));
if ($oldKey === '' || $newKey === '' || $newKey === $oldKey) {
    throw new RuntimeException('Define NEW_APP_ENCRYPTION_KEY con una clave nueva y distinta.');
}

$database = Database::connection();
$oldStore = new OAuthTokenStore($database, $oldKey);
$tokens = $oldStore->get();
if ($tokens === null) {
    throw new RuntimeException('No hay tokens OAuth para volver a cifrar.');
}

$envPath = PROJECT_ROOT . '/.env';
$envContents = file_get_contents($envPath);
if (!is_string($envContents) || preg_match('/^\s*NEW_APP_ENCRYPTION_KEY\s*=/m', $envContents) === 1) {
    throw new RuntimeException('No se pudo preparar NEW_APP_ENCRYPTION_KEY en .env.');
}
$stagedEnv = rtrim($envContents, "\r\n") . PHP_EOL
    . 'NEW_APP_ENCRYPTION_KEY=' . $newKey . PHP_EOL;
$temporaryPath = $envPath . '.migrating';
$backupPath = $envPath . '.key-rotation-backup-' . bin2hex(random_bytes(6));
if (file_put_contents($temporaryPath, $stagedEnv, LOCK_EX) === false) {
    throw new RuntimeException('No se pudo preparar .env para la rotación.');
}
if (!rename($envPath, $backupPath)) {
    unlink($temporaryPath);
    throw new RuntimeException('No se pudo proteger .env; la rotación no se inició.');
}
if (!rename($temporaryPath, $envPath)) {
    rename($backupPath, $envPath);
    unlink($temporaryPath);
    throw new RuntimeException('No se pudo instalar el .env temporal; se restauró el original.');
}
if (!unlink($backupPath)) {
    throw new RuntimeException('Se preparó .env, pero no se pudo retirar el respaldo temporal.');
}

(new OAuthTokenStore($database, $newKey))->save([
    'access_token' => $tokens['access_token'],
    'refresh_token' => $tokens['refresh_token'],
    'expires_in' => max(60, strtotime($tokens['expires_at']) - time()),
]);
echo "TOKENS_REENCRYPTED\n";
echo "En .env sustituye APP_ENCRYPTION_KEY por NEW_APP_ENCRYPTION_KEY, elimina la variable temporal y reinicia PHP.\n";