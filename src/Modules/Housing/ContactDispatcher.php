<?php

declare(strict_types=1);

namespace App\Modules\Housing;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Support\Mailer;

/**
 * Transmet les demandes de contact aux annonceurs (cron : php bin/console housing:send-contacts).
 * Hors requête HTTP : l'utilisateur n'attend pas l'envoi de l'e-mail, et un serveur mail
 * indisponible n'empêche pas d'enregistrer la demande (nouvel essai au passage suivant, 5 max).
 */
final class ContactDispatcher
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Mailer $mailer = new Mailer())
    {
    }

    /** @return array{sent: int, failed: int} */
    public function dispatch(int $batch = 100): array
    {
        $rows = Database::fetchAll(
            'SELECT r.id, r.name, r.email, r.phone, r.message, r.attempts,
                    l.title, l.price_cents, l.transaction, l.contact_name, l.contact_email
             FROM housing_contact_requests r JOIN housing_listings l ON l.id = r.listing_id
             WHERE r.status = \'pending\' AND r.attempts < :max ORDER BY r.id LIMIT :batch',
            ['max' => self::MAX_ATTEMPTS, 'batch' => $batch],
        );

        $result = ['sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $to = $row['contact_email'] ?: (string) Config::get('housing.contact.fallback_email', '');
            try {
                if ($to === '') {
                    throw new \RuntimeException('Aucun destinataire (contact_email vide et HOUSING_CONTACT_FALLBACK_EMAIL non défini).');
                }
                if (!$this->mailer->send($to, 'Nouvelle demande pour « ' . $row['title'] . ' »', $this->body($row), $row['email'])) {
                    throw new \RuntimeException('mail() a échoué.');
                }
                Database::execute(
                    'UPDATE housing_contact_requests SET status = \'sent\', sent_at = :d, attempts = attempts + 1 WHERE id = :id',
                    ['d' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']],
                );
                $result['sent']++;
            } catch (\Throwable $e) {
                $final = (int) $row['attempts'] + 1 >= self::MAX_ATTEMPTS;
                Database::execute(
                    'UPDATE housing_contact_requests SET attempts = attempts + 1, last_error = :e, status = :s WHERE id = :id',
                    ['e' => mb_substr($e->getMessage(), 0, 255), 's' => $final ? 'failed' : 'pending', 'id' => $row['id']],
                );
                Logger::warning('Envoi de demande de contact en échec', ['request_id' => $row['id'], 'error' => $e->getMessage()]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function body(array $r): string
    {
        $price = HousingPresenter::priceLabel((int) $r['price_cents'], $r['transaction'] !== 'sale');

        return ($r['contact_name'] ? "Bonjour {$r['contact_name']},\n\n" : "Bonjour,\n\n")
            . "Vous avez reçu une demande via myClermont pour votre annonce :\n"
            . "{$r['title']} — {$price}\n\n"
            . "De : {$r['name']}\n"
            . "E-mail : {$r['email']}\n"
            . ($r['phone'] ? "Téléphone : {$r['phone']}\n" : '')
            . "\nMessage :\n{$r['message']}\n\n"
            . "Répondez directement à cet e-mail pour contacter cette personne.\n"
            . "— L'équipe myClermont\n";
    }
}
