<?php

declare(strict_types=1);

/** Envía una campaña revisada y registra el resultado de cada destinatario. */
final class ReminderSenderService
{
    public function __construct(private PDO $database)
    {
    }

    public function send(int $campaignId, string $subject, string $body): array
    {
        if (trim($subject) === '' || trim($body) === '') {
            throw new InvalidArgumentException('El asunto y el mensaje son obligatorios.');
        }

        $from = trim((string) getenv('MAIL_FROM_ADDRESS'));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_FROM_ADDRESS no está configurado correctamente.');
        }

        $campaign = $this->database->prepare('SELECT id FROM reminder_campaigns WHERE id = :id AND status = \'review\'');
        $campaign->execute(['id' => $campaignId]);
        if ($campaign->fetchColumn() === false) {
            throw new RuntimeException('La campaña no está disponible para envío manual.');
        }

        $this->database->prepare('UPDATE reminder_campaigns SET subject = :subject, body = :body, status = \'sending\', started_at = NOW() WHERE id = :id')
            ->execute(['subject' => $subject, 'body' => $body, 'id' => $campaignId]);

        $recipients = $this->database->prepare(
            'SELECT rr.id, rr.email, s.full_name
             FROM reminder_recipients rr
             INNER JOIN enrollments e ON e.id = rr.enrollment_id
             INNER JOIN students s ON s.id = e.student_id
             WHERE rr.campaign_id = :campaign_id AND rr.status = \'pending\'
               AND LOWER(rr.email) LIKE \'%@estud.tesa.edu.ec\''
        );
        $recipients->execute(['campaign_id' => $campaignId]);

        $sent = 0;
        $failed = 0;
        $update = $this->database->prepare('UPDATE reminder_recipients SET status = :status, error_message = :error, sent_at = CASE WHEN :sent_status = \'sent\' THEN NOW() ELSE NULL END WHERE id = :id');

        while ($recipient = $recipients->fetch()) {
            $personalBody = str_replace('{nombre}', (string) $recipient['full_name'], $body);
            $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $result = mail((string) $recipient['email'], $subject, $personalBody, $headers);
            $status = $result ? 'sent' : 'failed';
            $update->execute(['status' => $status, 'sent_status' => $status, 'error' => $result ? null : 'PHP mail() no aceptó el mensaje.', 'id' => $recipient['id']]);
            $result ? $sent++ : $failed++;
        }

        $this->database->prepare('UPDATE reminder_campaigns SET status = \'completed\', completed_at = NOW() WHERE id = :id')
            ->execute(['id' => $campaignId]);

        return ['sent' => $sent, 'failed' => $failed];
    }
}