<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

use App\Core\Config;
use App\Core\Context;
use App\Core\Database;
use App\Modules\Premium\PremiumService;

/**
 * Moteur de gamification : une action => compteurs + points + badges débloqués automatiquement.
 *
 *   $service->record($userId, 'favorite.added', 'fav:place:123', ['favorites.total', 'favorites.tag.volcan']);
 *
 * Les gains sont ajoutés à "meta.rewards" de la réponse HTTP (si $notify), pour que l'app
 * affiche « +2 points » ou « Nouveau badge : Curieux ! ».
 */
final class GamificationService
{
    public function __construct(private readonly GamificationRepository $repo = new GamificationRepository())
    {
    }

    /**
     * @param string|null $uniqueKey identifiant du fait ; s'il a déjà été comptabilisé, rien ne se passe
     * @param list<string> $stats    compteurs à incrémenter
     * @param bool $notify           ajouter les gains à la réponse (false pour un autre utilisateur, ex. le parrain)
     * @return array{points: int, badges: list<array>}
     */
    public function record(int $userId, string $action, ?string $uniqueKey = null, array $stats = [], bool $notify = true): array
    {
        $rewards = Database::transaction(function () use ($userId, $action, $uniqueKey, $stats): array {
            $rewards = ['points' => 0, 'badges' => []];

            if ($uniqueKey !== null && !$this->repo->claimEvent($userId, $uniqueKey)) {
                return $rewards;
            }

            $premium = PremiumService::isActiveById($userId);
            $multiplier = (float) Config::get('gamification.premium_multiplier', 1);

            $points = BadgeRules::applyMultiplier((int) Config::get('gamification.points.' . $action, 0), $premium, $multiplier);
            $this->repo->addPoints($userId, $points, $action);
            $rewards['points'] += $points;

            $this->repo->incrementStats($userId, $stats);
            $this->unlockBadges($userId, $stats, $premium, $multiplier, $rewards);

            return $rewards;
        });

        if ($notify && ($rewards['points'] > 0 || $rewards['badges'] !== [])) {
            Context::pushMeta('rewards', ['action' => $action] + $rewards);
        }

        return $rewards;
    }

    /**
     * Réévalue tous les badges d'un utilisateur sans rien incrémenter
     * (après avoir abaissé un seuil dans la config). Utilisé par `bin/console gamification:recheck`.
     *
     * @return list<array> badges débloqués
     */
    public function recheckBadges(int $userId): array
    {
        return Database::transaction(function () use ($userId): array {
            $rewards = ['points' => 0, 'badges' => []];
            $catalog = (array) Config::get('gamification.badges', []);
            $this->unlockBadges(
                $userId,
                BadgeRules::watchedStats($catalog),
                PremiumService::isActiveById($userId),
                (float) Config::get('gamification.premium_multiplier', 1),
                $rewards,
            );

            return $rewards['badges'];
        });
    }

    /** Débloque les badges dont un des compteurs $changedStats atteint le seuil. */
    private function unlockBadges(int $userId, array $changedStats, bool $premium, float $multiplier, array &$rewards): void
    {
        // Seuls les badges dont un compteur vient de bouger sont réévalués
        $catalog = array_filter(
            (array) Config::get('gamification.badges', []),
            static fn (array $b) => in_array($b['rule']['stat'], $changedStats, true),
        );
        if ($catalog === []) {
            return;
        }

        $current = $this->repo->stats($userId, BadgeRules::watchedStats($catalog));
        foreach (BadgeRules::unlockable($catalog, $current, $this->repo->earnedCodes($userId)) as $code) {
            if (!$this->repo->awardBadge($userId, $code)) {
                continue;
            }
            $bonus = BadgeRules::applyMultiplier((int) $catalog[$code]['points'], $premium, $multiplier);
            $this->repo->addPoints($userId, $bonus, 'badge.' . $code);
            $rewards['points'] += $bonus;
            $rewards['badges'][] = GamificationRepository::presentBadge($code, $catalog[$code], gmdate('Y-m-d H:i:s'));
        }
    }
}
