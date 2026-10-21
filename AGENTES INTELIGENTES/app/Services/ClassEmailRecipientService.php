<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailRecipientList.php';

/** Lee el padron activo y persiste destinatarios especificos por clase. */
final class ClassEmailRecipientService
{
    public function __construct(private PDO $database)
    {
    }

    /** @return array<int, array{name: string, email: string}> */
    public function roster(int $orgUnitId, string $period): array
    {
        // El padron excluye matriculas inactivas y correos fuera del dominio institucional.
        if ($orgUnitId <= 0 || trim($period) === '' || mb_strlen($period, 'UTF-8') > 32) {
            throw new InvalidArgumentException('Selecciona una clase y un período válidos.');
        }

        $statement = $this->database->prepare(
            'SELECT DISTINCT s.full_name AS name, s.institutional_email AS email
             FROM courses c
             INNER JOIN enrollments e ON e.course_id = c.id AND e.active = TRUE
             INNER JOIN students s ON s.id = e.student_id AND s.active = TRUE
             WHERE c.brightspace_org_unit_id = :org_unit_id
               AND c.academic_period = :period
               AND c.active = TRUE
               AND s.institutional_email LIKE \'%@estud.tesa.edu.ec\'
               AND TRIM(s.full_name) <> \'\'
             ORDER BY s.full_name, s.institutional_email'
        );
        $statement->execute(['org_unit_id' => $orgUnitId, 'period' => $period]);

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'email' => (string) $row['email'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function get(string $period, string $career, int $orgUnitId): ?string
    {
        self::validateScope($period, $career, $orgUnitId);
        $statement = $this->database->prepare(
            'SELECT recipient FROM shared_email_class_recipients
             WHERE period = :period AND career = :career AND class_org_unit_id = :org_unit_id'
        );
        $statement->execute([
            'period' => $period,
            'career' => $career,
            'org_unit_id' => $orgUnitId,
        ]);
        $recipient = $statement->fetchColumn();

        return $recipient === false ? null : (string) $recipient;
    }

    public function save(
        string $period,
        string $career,
        int $orgUnitId,
        string $recipient,
        string $updatedBy
    ): bool {
        self::validateScope($period, $career, $orgUnitId);
        $recipient = EmailRecipientList::normalize($recipient);
        if (mb_strlen($updatedBy, 'UTF-8') > 255) {
            $updatedBy = mb_substr($updatedBy, 0, 255, 'UTF-8');
        }

        $current = $this->get($period, $career, $orgUnitId);
        $changed = $current !== $recipient;
        $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $suffix = match ($driver) {
            'mysql' => 'ON DUPLICATE KEY UPDATE
                recipient = VALUES(recipient),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP',
            'sqlite' => 'ON CONFLICT(period, career, class_org_unit_id) DO UPDATE SET
                recipient = excluded.recipient,
                updated_by = excluded.updated_by,
                updated_at = CURRENT_TIMESTAMP',
            default => throw new RuntimeException('La base de datos no admite guardar destinatarios por clase.'),
        };
        $statement = $this->database->prepare(
            'INSERT INTO shared_email_class_recipients
                (period, career, class_org_unit_id, recipient, updated_by, updated_at)
             VALUES (:period, :career, :org_unit_id, :recipient, :updated_by, CURRENT_TIMESTAMP)
             ' . $suffix
        );
        $statement->execute([
            'period' => $period,
            'career' => $career,
            'org_unit_id' => $orgUnitId,
            'recipient' => $recipient,
            'updated_by' => $updatedBy,
        ]);

        return $changed;
    }

    private static function validateScope(string $period, string $career, int $orgUnitId): void
    {
        if (
            trim($period) === ''
            || trim($career) === ''
            || mb_strlen($period, 'UTF-8') > 32
            || mb_strlen($career, 'UTF-8') > 64
            || $orgUnitId <= 0
        ) {
            throw new InvalidArgumentException('Selecciona una clase, carrera y período válidos.');
        }
    }
}
