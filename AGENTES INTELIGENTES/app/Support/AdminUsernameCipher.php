<?php

declare(strict_types=1);

/** Cifra el usuario administrativo con autenticacion para almacenarlo en .env. */
final class AdminUsernameCipher
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $username, string $encryptionKey): string
    {
        if (trim($username) === '' || $encryptionKey === '') {
            throw new InvalidArgumentException('El usuario admin y la clave de cifrado son obligatorios.');
        }

        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $ciphertext = openssl_encrypt(
            $username,
            self::CIPHER,
            hash('sha256', $encryptionKey, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($ciphertext)) {
            throw new RuntimeException('No se pudo cifrar el usuario administrador.');
        }

        return base64_encode(json_encode([
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ], JSON_THROW_ON_ERROR));
    }

    public static function decrypt(string $payload, string $encryptionKey): string
    {
        if ($payload === '' || $encryptionKey === '') {
            throw new InvalidArgumentException('El usuario cifrado y la clave son obligatorios.');
        }

        $decoded = base64_decode($payload, true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('El usuario administrador cifrado no tiene un formato válido.');
        }

        $ciphertext = base64_decode((string) ($data['ciphertext'] ?? ''), true);
        $iv = base64_decode((string) ($data['iv'] ?? ''), true);
        $tag = base64_decode((string) ($data['tag'] ?? ''), true);
        if (
            !is_string($ciphertext)
            || $ciphertext === ''
            || !is_string($iv)
            || strlen($iv) !== openssl_cipher_iv_length(self::CIPHER)
            || !is_string($tag)
            || strlen($tag) !== 16
        ) {
            throw new RuntimeException('El usuario administrador cifrado no tiene un formato válido.');
        }

        $username = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            hash('sha256', $encryptionKey, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($username) || trim($username) === '') {
            throw new RuntimeException('No se pudo descifrar el usuario administrador.');
        }

        return $username;
    }
}
