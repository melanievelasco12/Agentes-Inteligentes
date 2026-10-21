<?php

declare(strict_types=1);

/** Persiste tokens OAuth cifrados en la base de datos local. */
final class OAuthTokenStore
{
    private const TOKEN_ID = 1;
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private PDO $database, private string $encryptionKey)
    {
        if ($this->encryptionKey === '') {
            throw new RuntimeException('APP_ENCRYPTION_KEY no está configurada.');
        }
    }

    public function save(array $tokens): void
    {
        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        if ($accessToken === '') {
            AppLogger::error('Attempted to save empty OAuth access token.');
            throw new RuntimeException('El token de acceso de Brightspace está vacío.');
        }

        $access = $this->encrypt($accessToken);
        $refreshToken = isset($tokens['refresh_token']) && $tokens['refresh_token'] !== null
            ? trim((string) $tokens['refresh_token'])
            : '';
        $refresh = $refreshToken !== '' ? $this->encrypt($refreshToken) : null;

        $expiresInSeconds = (int) ($tokens['expires_in'] ?? 3600);
        $expiresInSeconds = max($expiresInSeconds, 60);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresInSeconds);

        $statement = $this->database->prepare(
            'INSERT INTO oauth_tokens (
                id, access_token_ciphertext, access_token_iv, access_token_tag,
                refresh_token_ciphertext, refresh_token_iv, refresh_token_tag, expires_at
            ) VALUES (1, :access, :access_iv, :access_tag, :refresh, :refresh_iv, :refresh_tag, :expires)
            ON DUPLICATE KEY UPDATE
                access_token_ciphertext = VALUES(access_token_ciphertext),
                access_token_iv = VALUES(access_token_iv),
                access_token_tag = VALUES(access_token_tag),
                refresh_token_ciphertext = COALESCE(VALUES(refresh_token_ciphertext), refresh_token_ciphertext),
                refresh_token_iv = COALESCE(VALUES(refresh_token_iv), refresh_token_iv),
                refresh_token_tag = COALESCE(VALUES(refresh_token_tag), refresh_token_tag),
                expires_at = VALUES(expires_at)'
        );

        $statement->execute([
            'access' => $access['ciphertext'],
            'access_iv' => $access['iv'],
            'access_tag' => $access['tag'],
            'refresh' => $refresh['ciphertext'] ?? null,
            'refresh_iv' => $refresh['iv'] ?? null,
            'refresh_tag' => $refresh['tag'] ?? null,
            'expires' => $expiresAt,
        ]);

        AppLogger::info('OAuth tokens persisted successfully.', ['expires_at' => $expiresAt, 'has_refresh' => $refresh !== null]);
    }

    public function get(): ?array
    {
        $statement = $this->database->query('SELECT * FROM oauth_tokens WHERE id = 1 LIMIT 1');
        $token = $statement->fetch();

        if ($token === false) {
            AppLogger::warning('No OAuth tokens were found in storage.');
            return null;
        }

        try {
            return [
                'access_token' => $this->decrypt($token['access_token_ciphertext'], $token['access_token_iv'], $token['access_token_tag']),
                'refresh_token' => $token['refresh_token_ciphertext'] !== null
                    ? $this->decrypt($token['refresh_token_ciphertext'], $token['refresh_token_iv'], $token['refresh_token_tag'])
                    : null,
                'expires_at' => $token['expires_at'],
            ];
        } catch (Throwable $exception) {
            AppLogger::exception($exception, ['operation' => 'oauth_token_get']);
            throw $exception;
        }
    }

    private function encrypt(string $value): array
    {
        // GCM autentica el contenido y el IV aleatorio evita repetir parametros de cifrado.
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $key = hash('sha256', $this->encryptionKey, true);
        $ciphertext = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo cifrar el token OAuth.');
        }

        return ['ciphertext' => base64_encode($ciphertext), 'iv' => $iv, 'tag' => $tag];
    }

    private function decrypt(string $ciphertext, string $iv, string $tag): string
    {
        $key = hash('sha256', $this->encryptionKey, true);
        $value = openssl_decrypt(base64_decode($ciphertext), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($value === false) {
            throw new RuntimeException('No se pudo descifrar el token OAuth.');
        }

        return $value;
    }
}
