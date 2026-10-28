<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Content\Documents\DocumentStore;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Support\Mailer;

/**
 * Transmet les candidatures aux recruteurs par e-mail, CV et lettre en pièces jointes
 * (cron : php bin/console jobs:send-applications). 5 essais maximum.
 */
final class ApplicationDispatcher
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Mailer $mailer = new Mailer(),
        private readonly DocumentStore $documents = new DocumentStore(),
    ) {
    }

    /** @return array{sent: int, failed: int} */
    public function dispatch(int $batch = 50): array
    {
        $rows = Database::fetchAll(
            'SELECT a.id, a.name, a.email, a.phone, a.message, a.attempts, a.cv_document_id, a.cover_document_id,
                    o.title, o.company_name, o.contract, o.contact_email
             FROM job_applications a JOIN job_offers o ON o.id = a.offer_id
             WHERE a.status = \'pending\' AND a.attempts < :max ORDER BY a.id LIMIT :batch',
            ['max' => self::MAX_ATTEMPTS, 'batch' => $batch],
        );

        $result = ['sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            try {
                $to = $row['contact_email'] ?: (string) Config::get('jobs.applications.fallback_email', '');
                if ($to === '') {
                    throw new \RuntimeException('Aucun destinataire (contact_email vide et JOBS_APPLICATION_FALLBACK_EMAIL non défini).');
                }

                $attachments = [];
                foreach (['cv_document_id', 'cover_document_id'] as $column) {
                    if ($row[$column] !== null) {
                        $file = $this->documents->read((int) $row[$column])
                            ?? throw new \RuntimeException("Document {$column} introuvable sur le disque.");
                        $attachments[] = $file;
                    }
                }

                $subject = "Candidature : {$row['title']} — {$row['name']}";
                if (!$this->mailer->send($to, $subject, $this->body($row), $row['email'], $attachments)) {
                    throw new \RuntimeException('mail() a échoué.');
                }

                Database::execute(
                    'UPDATE job_applications SET status = \'sent\', sent_at = :d, attempts = attempts + 1 WHERE id = :id',
                    ['d' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']],
                );
                $result['sent']++;
            } catch (\Throwable $e) {
                $final = (int) $row['attempts'] + 1 >= self::MAX_ATTEMPTS;
                Database::execute(
                    'UPDATE job_applications SET attempts = attempts + 1, last_error = :e, status = :s WHERE id = :id',
                    ['e' => mb_substr($e->getMessage(), 0, 255), 's' => $final ? 'failed' : 'pending', 'id' => $row['id']],
                );
                Logger::warning('Envoi de candidature en échec', ['application_id' => $row['id'], 'error' => $e->getMessage()]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function body(array $r): string
    {
        return "Bonjour,\n\n"
            . "Nouvelle candidature reçue via myClermont pour l'offre « {$r['title']} » ({$r['company_name']}).\n\n"
            . "Candidat·e : {$r['name']}\n"
            . "E-mail : {$r['email']}\n"
            . ($r['phone'] ? "Téléphone : {$r['phone']}\n" : '')
            . ($r['message'] ? "\nMessage :\n{$r['message']}\n" : '')
            . "\nLe CV" . ($r['cover_document_id'] ? ' et la lettre de motivation sont joints' : ' est joint') . " à cet e-mail.\n"
            . "Répondez directement à cet e-mail pour contacter la personne.\n\n"
            . "— L'équipe myClermont\n";
    }
}
