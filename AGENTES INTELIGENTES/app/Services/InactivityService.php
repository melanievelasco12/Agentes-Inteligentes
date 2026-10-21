<?php

declare(strict_types=1);

/** Busca matriculas activas cuyo estudiante supera un umbral de inactividad. */
class InactivityService
{
    public const SUPPORTED_DAYS = [8, 15, 30];

    public function __construct(private PDO $database)
    {
    }

    public function candidatesForDays(int $days): array
    {
        // Se aceptan unicamente umbrales soportados para evitar campanas arbitrarias.
        if (!in_array($days, self::SUPPORTED_DAYS, true)) {
            throw new InvalidArgumentException('El perÃ­odo debe ser 8, 15 o 30 dÃ­as.');
        }

        $statement = $this->database->prepare(
            'SELECT e.id AS enrollment_id, s.id AS student_id, s.full_name, s.institutional_email,
                    c.id AS course_id, c.code AS course_code, c.name AS course_name, e.last_activity_at
             FROM enrollments e
             INNER JOIN students s ON s.id = e.student_id AND s.active = TRUE
             INNER JOIN courses c ON c.id = e.course_id AND c.active = TRUE
             WHERE e.active = TRUE
                               AND LOWER(s.institutional_email) LIKE \'%@estud.tesa.edu.ec\'
                    AND c.brightspace_org_unit_id = :agent_org_unit_id
            AND (e.last_activity_at IS NULL
                OR DATEDIFF(CURDATE(), DATE(e.last_activity_at)) >= :days)
               AND NOT EXISTS (
                   SELECT 1
                   FROM reminder_recipients rr
                   INNER JOIN reminder_campaigns rc ON rc.id = rr.campaign_id
                   WHERE rr.enrollment_id = e.id
                     AND rc.inactivity_days = :campaign_days
                     AND rr.status IN (\'pending\', \'sent\')
               )
             ORDER BY s.full_name, c.name'
        );
        $statement->execute([
            'days' => $days,
            'campaign_days' => $days,
            'agent_org_unit_id' => (int) getenv('BRIGHTSPACE_AGENT_ORG_UNIT_ID'),
        ]);

        return $statement->fetchAll();
    }
}