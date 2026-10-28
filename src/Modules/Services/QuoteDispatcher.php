<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Support\Mailer;

/**
 * Transmet les demandes de devis aux professionnels (cron : php bin/console services:send-quotes). 5 essais maximum.
 */
final class QuoteDispatcher
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Mailer $mailer = new Mailer())
    {
    }

    /** @return array{sent: int, failed: int} */
    public function dispatch(int $batch = 100): array
    {
        $rows = Database::fetchAll(
            'SELECT q.id, q.name, q.email, q.phone, q.postal_code, q.preferred_date, q.message, q.attempts, sp.name AS provider_name, sp.contact_email
             FROM service_quote_requests q JOIN service_providers sp ON sp.id = q.provider_id
             WHERE q.status = \'pending\' AND q.attempts < :max ORDER BY q.id LIMIT :batch',
            ['max' => self::MAX_ATTEMPTS, 'batch' => $batch],
        );

        $result = ['sent' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            try {
                $to = $r['contact_email'] ?: (string) Config::get('services.fallback_email', '');
                if ($to === '') {
                    throw new \RuntimeException('Aucun destinataire (contact_email vide et SERVICES_QUOTE_FALLBACK_EMAIL non défini).');
                }
                $body = "Bonjour,\n\nNouvelle demande de devis reçue via myClermont pour {$r['provider_name']}.\n\n"
                    . "De : {$r['name']}\nE-mail : {$r['email']}\n"
                    . ($r['phone'] ? "Téléphone : {$r['phone']}\n" : '')
                    . ($r['postal_code'] ? "Code postal : {$r['postal_code']}\n" : '')
                    . ($r['preferred_date'] ? 'Date souhaitée : ' . date('d/m/Y', strtotime($r['preferred_date'])) . "\n" : '')
                    . "\nDemande :\n{$r['message']}\n\nRépondez directement à cet e-mail pour contacter cette personne.\n— L'équipe myClermont\n";

                if (!$this->mailer->send($to, "Demande de devis — {$r['name']}", $body, $r['email'])) {
                    throw new \RuntimeException('mail() a échoué.');
                }
                Database::execute("UPDATE service_quote_requests SET status = 'sent', sent_at = :d, attempts = attempts + 1 WHERE id = :id", ['d' => gmdate('Y-m-d H:i:s'), 'id' => $r['id']]);
                $result['sent']++;
            } catch (\Throwable $e) {
                Database::execute(
                    'UPDATE service_quote_requests SET attempts = attempts + 1, last_error = :e, status = :s WHERE id = :id',
                    ['e' => mb_substr($e->getMessage(), 0, 255), 's' => (int) $r['attempts'] + 1 >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'id' => $r['id']],
                );
                Logger::warning('Envoi de demande de devis en échec', ['quote_id' => $r['id'], 'error' => $e->getMessage()]);
                $result['failed']++;
            }
        }

        return $result;
    }
}
