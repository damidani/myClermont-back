<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Database;

/**
 * Formule Partenaire offerte par l'équipe (back-office /admin/merchants et API staff) :
 * durée en mois (0 = sans limite), retour automatique en gratuit à l'échéance (cron pro:plans).
 */
final class OfferedPlans
{
    public const DURATIONS = [1 => '1 mois', 3 => '3 mois', 6 => '6 mois', 12 => '1 an', 0 => 'Sans limite'];

    public static function offer(array $b, int $months, string $by, string $note = ''): void
    {
        if (!array_key_exists($months, self::DURATIONS)) {
            throw new \DomainException('Durée invalide.');
        }
        // Prolonger une offre en cours part de sa date de fin
        $from = $b['plan'] === 'partenaire' && $b['plan_until'] !== null && strtotime($b['plan_until'] . ' UTC') > time() ? strtotime($b['plan_until'] . ' UTC') : time();
        $until = $months === 0 ? null : gmdate('Y-m-d H:i:s', strtotime("+{$months} months", $from));
        if ($b['plan'] !== 'partenaire') {
            AccountController::setPlan($b, 'partenaire');
        }
        Database::execute('UPDATE pro_businesses SET plan_until = :u, plan_offered_by = :by, plan_note = :n, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['u' => $until, 'by' => mb_substr($by, 0, 120), 'n' => mb_substr(trim($note), 0, 300) ?: null, 'id' => $b['id']]);
        self::notify($b, $until === null
            ? 'L\'équipe My Clermont t\'offre la formule Partenaire, sans limite de durée.'
            : 'L\'équipe My Clermont t\'offre la formule Partenaire jusqu\'au ' . self::date($until) . '.');
    }

    public static function revoke(array $b): void
    {
        AccountController::setPlan($b, 'gratuit');
        Database::execute('UPDATE pro_businesses SET plan_until = NULL, plan_offered_by = NULL, plan_note = NULL WHERE id = :id', ['id' => $b['id']]);
    }

    /** Offres arrivées à échéance → gratuit ; renvoie le nombre de comptes concernés */
    public static function expire(): int
    {
        $n = 0;
        foreach (Database::fetchAll("SELECT * FROM pro_businesses WHERE plan = 'partenaire' AND plan_until IS NOT NULL AND plan_until <= UTC_TIMESTAMP() AND deleted_at IS NULL") as $b) {
            self::revoke($b);
            self::notify($b, 'La formule Partenaire offerte par My Clermont est arrivée à échéance : ton compte repasse en formule gratuite.');
            $n++;
        }

        return $n;
    }

    private static function notify(array $b, string $text): void
    {
        Pro::notify((int) $b['id'], 'systeme', 'Formule Partenaire', $text, '/partenariat');
    }

    private static function date(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
    }
}
