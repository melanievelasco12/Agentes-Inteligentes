<?php

declare(strict_types=1);

/** Guarda el borrador del mensaje compartido para una carrera y periodo. */
final class CareerPeriodEmailDraftService
{
    public function __construct(private PDO $database)
    {
    }

    /** @return array{recipient: string, body: string, updated_by: ?string, updated_at: ?string}|null */
    public function get(string $period, string $career): ?array
    {
        self::validateScope($period, $career);
        $statement = $this->database->prepare(
            'SELECT recipient, body, updated_by, updated_at
             FROM shared_email_scope_drafts
             WHERE period = :period AND career = :career'
        );
        $statement->execute(['period' => $period, 'career' => $career]);
        $draft = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($draft)) {
            return null;
        }

        $draft['body'] = SharedEmailDraftService::normalizeBody((string) $draft['body']);
        return $draft;
    }

    public function save(string $period, string $career, string $recipient, string $body, string $updatedBy): bool
    {
        self::validateScope($period, $career);
        $recipient = trim($recipient);
        $body = SharedEmailDraftService::normalizeBody($body);
        SharedEmailDraftService::validateRecipient($recipient);
        if (mb_strlen($body, 'UTF-8') > SharedEmailDraftService::MAX_BODY_LENGTH || str_contains($body, "\0")) {
            throw new InvalidArgumentException('El mensaje no puede superar 10.000 caracteres.');
        }
        if (mb_strlen($updatedBy, 'UTF-8') > 255) {
            $updatedBy = mb_substr($updatedBy, 0, 255, 'UTF-8');
        }

        $current = $this->get($period, $career);
        $changed = $current === null
            || $current['recipient'] !== $recipient
            || $current['body'] !== $body;
        $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $suffix = match ($driver) {
            'mysql' => 'ON DUPLICATE KEY UPDATE
                recipient = VALUES(recipient),
                body = VALUES(body),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP',
            'sqlite' => 'ON CONFLICT(period, career) DO UPDATE SET
                recipient = excluded.recipient,
                body = excluded.body,
                updated_by = excluded.updated_by,
                updated_at = CURRENT_TIMESTAMP',
            default => throw new RuntimeException('La base de datos no admite guardar borradores por carrera y período.'),
        };
        $statement = $this->database->prepare(
            'INSERT INTO shared_email_scope_drafts
                (period, career, recipient, body, updated_by, updated_at)
             VALUES (:period, :career, :recipient, :body, :updated_by, CURRENT_TIMESTAMP)
             ' . $suffix
        );
        $statement->execute([
            'period' => $period,
            'career' => $career,
            'recipient' => $recipient,
            'body' => $body,
            'updated_by' => $updatedBy,
        ]);

        return $changed;
    }

    public function saveBody(string $period, string $career, string $body, string $updatedBy): bool
    {
        // Actualiza solo el cuerpo y conserva el destinatario heredado de la fila.
        self::validateScope($period, $career);
        $body = SharedEmailDraftService::normalizeBody($body);
        if (mb_strlen($body, 'UTF-8') > SharedEmailDraftService::MAX_BODY_LENGTH || str_contains($body, "\0")) {
            throw new InvalidArgumentException('El mensaje no puede superar 10.000 caracteres.');
        }
        if (mb_strlen($updatedBy, 'UTF-8') > 255) {
            $updatedBy = mb_substr($updatedBy, 0, 255, 'UTF-8');
        }

        $current = $this->get($period, $career);
        $changed = $current === null || $current['body'] !== $body;
        $recipient = (string) ($current['recipient'] ?? '');
        $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $suffix = match ($driver) {
            'mysql' => 'ON DUPLICATE KEY UPDATE
                body = VALUES(body),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP',
            'sqlite' => 'ON CONFLICT(period, career) DO UPDATE SET
                body = excluded.body,
                updated_by = excluded.updated_by,
                updated_at = CURRENT_TIMESTAMP',
            default => throw new RuntimeException('La base de datos no admite guardar mensajes por carrera y período.'),
        };
        $statement = $this->database->prepare(
            'INSERT INTO shared_email_scope_drafts
                (period, career, recipient, body, updated_by, updated_at)
             VALUES (:period, :career, :recipient, :body, :updated_by, CURRENT_TIMESTAMP)
             ' . $suffix
        );
        $statement->execute([
            'period' => $period,
            'career' => $career,
            'recipient' => $recipient,
            'body' => $body,
            'updated_by' => $updatedBy,
        ]);

        return $changed;
    }

    private static function validateScope(string $period, string $career): void
    {
        if (
            trim($period) === ''
            || trim($career) === ''
            || mb_strlen($period, 'UTF-8') > 32
            || mb_strlen($career, 'UTF-8') > 64
        ) {
            throw new InvalidArgumentException('Selecciona una carrera y un período para guardar el mensaje.');
        }
    }
}
