<?php

declare(strict_types=1);

/** Logger de aplicacion con niveles configurables y redaccion de datos sensibles. */
final class AppLogger
{
    private const DEFAULT_LOG_PATH = '/storage/logs/app.log';
    private const LEVELS = [
        'DEBUG' => 10,
        'INFO' => 20,
        'WARNING' => 30,
        'ERROR' => 40,
        'CRITICAL' => 50,
    ];

    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('CRITICAL', $message, $context);
    }

    public static function exception(Throwable $exception, array $context = []): void
    {
        $payload = $context + [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
        ];

        self::write('ERROR', $exception->getMessage(), $payload);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $threshold = self::threshold();
        $current = self::LEVELS[$level] ?? 20;

        if ($current < $threshold) {
            return;
        }

        $timestamp = date('c');
        $contextJson = self::formatContext($context);
        $line = '[' . $timestamp . '] ' . $level . ' ' . $message . ($contextJson !== '' ? ' ' . $contextJson : '');

        error_log($line);

        $logPath = self::resolvePath();
        if ($logPath === null) {
            return;
        }

        $directory = dirname($logPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function threshold(): int
    {
        $configured = strtoupper(trim((string) getenv('APP_LOG_LEVEL')));
        $level = $configured !== '' ? $configured : 'INFO';

        if ($level === 'DEBUG') {
            return self::LEVELS['DEBUG'];
        }
        if ($level === 'INFO') {
            return self::LEVELS['INFO'];
        }
        if ($level === 'WARNING') {
            return self::LEVELS['WARNING'];
        }
        if ($level === 'ERROR') {
            return self::LEVELS['ERROR'];
        }
        if ($level === 'CRITICAL') {
            return self::LEVELS['CRITICAL'];
        }

        return self::LEVELS['INFO'];
    }

    private static function resolvePath(): ?string
    {
        $path = trim((string) getenv('APP_LOG_PATH'));
        if ($path === '') {
            $path = PROJECT_ROOT . self::DEFAULT_LOG_PATH;
        }

        return $path !== '' ? $path : null;
    }

    private static function formatContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $sanitized = self::sanitizeContext($context);

        try {
            $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return '[context_unavailable]';
        }

        return $json !== '' ? '{' . $json . '}' : '';
    }

    private static function sanitizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (self::shouldRedact((string) $key)) {
                $context[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = self::sanitizeContext($value);
                continue;
            }

            if (is_object($value)) {
                $context[$key] = '[OBJECT]';
            }
        }

        return $context;
    }

    private static function shouldRedact(string $key): bool
    {
        $normalized = strtolower($key);
        $patterns = ['token', 'secret', 'password', 'authorization', 'cookie', 'code', 'key', 'refresh', 'access_token', 'refresh_token'];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
