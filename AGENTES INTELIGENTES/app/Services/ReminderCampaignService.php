<?php

declare(strict_types=1);

/** Prepara campanas de revision sin enviar correos a los destinatarios. */
final class ReminderCampaignService
{
    public function __construct(private PDO $database, private InactivityService $inactivity)
    {
    }

    public function prepare(int $days, string $createdBy = 'manual'): int
    {
        $this->database->beginTransaction();

        try {
            $existing = $this->database->prepare('SELECT id FROM reminder_campaigns WHERE inactivity_days = :days AND status = \'review\' ORDER BY id LIMIT 1');
            $existing->execute(['days' => $days]);
            $campaignId = (int) $existing->fetchColumn();

            if ($campaignId > 0) {
                $clearRecipients = $this->database->prepare(
                    'DELETE FROM reminder_recipients
                     WHERE campaign_id = :campaign_id AND status = \'pending\''
                );
                $clearRecipients->execute(['campaign_id' => $campaignId]);
            }

            // Los destinatarios pendientes se eliminan antes de recalcular para que sigan siendo elegibles.
            $candidates = $this->inactivity->candidatesForDays($days);

            if ($candidates === [] && $campaignId === 0) {
                $this->database->commit();
                return 0;
            }

            if ($campaignId === 0) {
                $campaign = $this->database->prepare(
                    'INSERT INTO reminder_campaigns (name, inactivity_days, subject, body, status, created_by)
                     VALUES (:name, :days, :subject, :body, \'review\', :created_by)'
                );
                $campaign->execute([
                    'name' => 'Recordatorio de acceso - ' . $days . ' dÃ­as - ' . date('Y-m-d H:i:s'),
                    'days' => $days,
                    'subject' => 'Recordatorio de acceso a Brightspace',
                    'body' => "Hola {nombre},\n\nHemos identificado que no has ingresado a Brightspace durante {$days} dÃ­as. Por favor, revisa tus cursos.\n\nSaludos,\nTESA",
                    'created_by' => $createdBy,
                ]);
                $campaignId = (int) $this->database->lastInsertId();
            }

            $recipient = $this->database->prepare(
                'INSERT INTO reminder_recipients (campaign_id, enrollment_id, email, status)
                 VALUES (:campaign_id, :enrollment_id, :email, \'pending\')'
            );
            foreach ($candidates as $candidate) {
                $recipient->execute([
                    'campaign_id' => $campaignId,
                    'enrollment_id' => (int) $candidate['enrollment_id'],
                    'email' => (string) $candidate['institutional_email'],
                ]);
            }

            $this->database->commit();
            return count($candidates);
        } catch (Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }
    }
}