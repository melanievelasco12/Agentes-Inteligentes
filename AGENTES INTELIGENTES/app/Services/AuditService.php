<?php

declare(strict_types=1);

/** Registra acciones administrativas en la tabla de auditoria local. */
final class AuditService
{
    public function __construct(private PDO $database)
    {
    }

    public function record(string $action, array $context = []): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO audit_logs (actor, action, context, ip_address) VALUES (:actor, :action, :context, :ip_address)'
        );
        $statement->execute([
            'actor' => (string) ($_SESSION['admin_username'] ?? 'system'),
            'action' => $action,
            'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    }
}