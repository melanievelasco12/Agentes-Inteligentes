<?php

declare(strict_types=1);

/** Gestiona el borrador compartido heredado y valida sus campos de correo. */
final class SharedEmailDraftService
{
    public const MAX_RECIPIENT_LENGTH = 254;
    public const MAX_SUBJECT_LENGTH = 255;
    public const MAX_BODY_LENGTH = 10000;
    public const DYNAMIC_RECIPIENT_TOKEN = '{InitiatingUser}';

    public function __construct(private PDO $database)
    {
    }

    public function get(): array
    {
        $statement = $this->database->prepare(
            'SELECT recipient, subject, body, updated_by, updated_at
             FROM shared_email_draft
             WHERE id = :id'
        );
        $statement->execute(['id' => 1]);
        $draft = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($draft)) {
            return ['recipient' => '', 'subject' => '', 'body' => '', 'updated_by' => null, 'updated_at' => null];
        }

        $draft['body'] = self::normalizeBody((string) $draft['body']);
        return $draft;
    }

    /**
     * Keep the persisted subject when accepting editable recipient and body values.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $persistedDraft
     * @return array<string, mixed>
     */
    public static function withPersistedSubject(array $submitted, array $persistedDraft): array
    {
        $subject = $persistedDraft['subject'] ?? '';
        $submitted['subject'] = is_string($subject) ? $subject : '';
        if (is_string($submitted['body'] ?? null)) {
            $submitted['body'] = self::normalizeBody($submitted['body']);
        }

        return $submitted;
    }

    public static function normalizeBody(string $body): string
    {
        $body = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $body) ?? $body;
        $body = preg_replace('~<\s*/\s*li\s*>~i', "\n", $body) ?? $body;
        $body = preg_replace('~<\s*/\s*(?:p|div|h[1-6])\s*>~i', "\n\n", $body) ?? $body;
        $body = preg_replace('~<\s*(?:p|div|li|h[1-6])\b[^>]*>~i', '', $body) ?? $body;
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/\r\n?/', "\n", $body) ?? $body;
        $body = preg_replace('/[ \t]*\n[ \t]*/', "\n", $body) ?? $body;
        $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;

        return trim($body);
    }

    /**
     * Add the most common cached email-action values only to a never-saved
     * draft on its initial GET. This only shapes displayed values; it does not
     * persist them.
     *
     * @param array<string, mixed> $draft
     * @param array<int, array<string, mixed>> $agents
     * @return array<string, mixed>
     */
    public static function withInitialDefaults(array $draft, array $agents, bool $initialGet): array
    {
        if (!$initialGet || ($draft['updated_at'] ?? null) !== null) {
            return $draft;
        }

        $mostCommonPair = self::mostCommonEmailActionPair($agents);
        if ($mostCommonPair === null) {
            return $draft;
        }

        $draft['recipient'] = $mostCommonPair['recipient'];
        $draft['body'] = $mostCommonPair['body'];

        return $draft;
    }

    /**
     * @param array<int, array<string, mixed>> $agents
     * @return array{recipient: string, body: string}|null
     */
    public static function mostCommonEmailActionPair(array $agents): ?array
    {
        $pairs = [];

        foreach ($agents as $agent) {
            $emailAction = $agent['Action']['EmailAction'] ?? null;
            $days = filter_var($agent['Condition']['CourseActivity']['Days'] ?? null, FILTER_VALIDATE_INT);
            if (
                !is_array($emailAction)
                || ($emailAction['IsEnabled'] ?? false) !== true
                || !in_array($days, [8, 15, 30], true)
                || !is_string($emailAction['To'] ?? null)
                || !is_string($emailAction['Message'] ?? null)
                || trim($emailAction['To']) === ''
                || trim($emailAction['Message']) === ''
            ) {
                continue;
            }

            $recipient = $emailAction['To'];
            $body = self::normalizeBody($emailAction['Message']);
            $key = 'pair:' . serialize([$recipient, $body]);
            if (!isset($pairs[$key])) {
                $pairs[$key] = ['recipient' => $recipient, 'body' => $body, 'count' => 0];
            }
            $pairs[$key]['count']++;
        }

        if ($pairs === []) {
            return null;
        }

        $winnerKey = null;
        foreach ($pairs as $key => $pair) {
            if (
                $winnerKey === null
                || $pair['count'] > $pairs[$winnerKey]['count']
                || ($pair['count'] === $pairs[$winnerKey]['count'] && strcmp($key, $winnerKey) < 0)
            ) {
                $winnerKey = $key;
            }
        }

        return [
            'recipient' => $pairs[$winnerKey]['recipient'],
            'body' => $pairs[$winnerKey]['body'],
        ];
    }

    public function save(string $recipient, string $subject, string $body, string $updatedBy): bool
    {
        $recipient = trim($recipient);
        $body = self::normalizeBody($body);
        self::validateRecipient($recipient);
        self::validateMessage($subject, $body);

        if (mb_strlen($updatedBy, 'UTF-8') > 255) {
            $updatedBy = mb_substr($updatedBy, 0, 255, 'UTF-8');
        }

        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockSuffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $currentStatement = $this->database->prepare(
                'SELECT recipient, subject, body
                 FROM shared_email_draft
                 WHERE id = :id' . $lockSuffix
            );
            $currentStatement->execute(['id' => 1]);
            $current = $currentStatement->fetch(PDO::FETCH_ASSOC);
            $current ??= ['recipient' => '', 'subject' => '', 'body' => ''];
            $changed = $current['recipient'] !== $recipient
                || $current['subject'] !== $subject
                || $current['body'] !== $body;

            $suffix = match ($driver) {
                'mysql' => 'ON DUPLICATE KEY UPDATE
                    recipient = VALUES(recipient),
                    subject = VALUES(subject),
                    body = VALUES(body),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP',
                'sqlite' => 'ON CONFLICT(id) DO UPDATE SET
                    recipient = excluded.recipient,
                    subject = excluded.subject,
                    body = excluded.body,
                    updated_by = excluded.updated_by,
                    updated_at = CURRENT_TIMESTAMP',
                default => throw new RuntimeException('La base de datos no admite guardar este borrador.'),
            };
            $statement = $this->database->prepare(
                'INSERT INTO shared_email_draft (id, recipient, subject, body, updated_by, updated_at)
                 VALUES (1, :recipient, :subject, :body, :updated_by, CURRENT_TIMESTAMP)
                 ' . $suffix
            );
            $statement->execute([
                'recipient' => $recipient,
                'subject' => $subject,
                'body' => $body,
                'updated_by' => $updatedBy,
            ]);
            if ($ownsTransaction) {
                $this->database->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        return $changed;
    }

    public function saveMessage(string $subject, string $body, string $updatedBy): bool
    {
        $body = self::normalizeBody($body);
        self::validateMessage($subject, $body);
        if (mb_strlen($updatedBy, 'UTF-8') > 255) {
            $updatedBy = mb_substr($updatedBy, 0, 255, 'UTF-8');
        }

        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockSuffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $currentStatement = $this->database->prepare(
                'SELECT subject, body FROM shared_email_draft WHERE id = :id' . $lockSuffix
            );
            $currentStatement->execute(['id' => 1]);
            $current = $currentStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                throw new RuntimeException('No existe el borrador compartido que se debe actualizar.');
            }
            $changed = $current['subject'] !== $subject || $current['body'] !== $body;
            $statement = $this->database->prepare(
                'UPDATE shared_email_draft
                 SET subject = :subject, body = :body, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute([
                'subject' => $subject,
                'body' => $body,
                'updated_by' => $updatedBy,
                'id' => 1,
            ]);
            if ($ownsTransaction) {
                $this->database->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        return $changed;
    }

    private static function validateMessage(string $subject, string $body): void
    {
        if (
            mb_strlen($subject, 'UTF-8') > self::MAX_SUBJECT_LENGTH
            || preg_match('/[\r\n]/', $subject) === 1
        ) {
            throw new InvalidArgumentException('El asunto no puede superar 255 caracteres ni incluir saltos de línea.');
        }

        if (
            mb_strlen($body, 'UTF-8') > self::MAX_BODY_LENGTH
            || str_contains($body, "\0")
        ) {
            throw new InvalidArgumentException('El mensaje no puede superar 10.000 caracteres.');
        }
    }

    public static function validateRecipient(string $recipient): void
    {
        if (
            mb_strlen($recipient, 'UTF-8') > self::MAX_RECIPIENT_LENGTH
            || (
                $recipient !== self::DYNAMIC_RECIPIENT_TOKEN
                && filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            )
        ) {
            throw new InvalidArgumentException(
                'Ingresa una dirección de correo válida o {InitiatingUser} (máximo 254 caracteres).'
            );
        }
    }
}
