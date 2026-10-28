<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

/**
 * Traduit les événements des autres modules en actions de gamification.
 * Pour récompenser une nouvelle action : écouter son événement ici (voir GamificationModule::listeners)
 * et, si besoin, ajouter ses points dans config/gamification.php.
 */
final class GamificationListeners
{
    private GamificationService $service;

    public function __construct()
    {
        $this->service = new GamificationService();
    }

    public function onRegistered(array $e): void
    {
        $this->service->record($e['user_id'], 'account.registered', 'account.registered', ['account.registered']);

        if ($e['referred_by'] !== null) {
            // Le parrain gagne ses points sans les voir dans la réponse du filleul
            $this->service->record($e['referred_by'], 'referral.completed', 'referral:' . $e['user_id'], ['referrals'], notify: false);
        }
    }

    public function onLoggedIn(array $e): void
    {
        $this->service->record($e['user_id'], 'account.daily_login', 'daily:' . gmdate('Y-m-d'));
    }

    public function onProfileUpdated(array $e): void
    {
        $user = $e['user'];
        if ($user['bio'] !== null && trim($user['bio']) !== '' && $user['neighborhood_id'] !== null) {
            $this->service->record($e['user_id'], 'account.profile_completed', 'account.profile_completed');
        }
    }

    public function onClassifiedPublished(array $e): void
    {
        $this->service->record($e['user_id'], 'classifieds.counter', 'classified:' . $e['classified_uid'], ['classifieds.posted']);
        $this->service->record($e['user_id'], 'classifieds.published', 'classified-day:' . gmdate('Y-m-d'));
    }

    public function onClassifiedSold(array $e): void
    {
        if ($e['is_free']) {
            $this->service->record($e['user_id'], 'classifieds.given', 'given:' . $e['classified_uid'], ['classifieds.given']);
        }
    }

    public function onCommunityPost(array $e): void
    {
        // Les compteurs avancent à chaque publication ; les points une seule fois par jour (pas de course au spam)
        $this->service->record($e['user_id'], 'community.post_counter', 'post:' . $e['post_uid'], ['community.posts', 'community.posts.' . $e['type']]);
        $this->service->record($e['user_id'], 'community.posted', 'posted:' . gmdate('Y-m-d'));
    }

    public function onPollVoted(array $e): void
    {
        $this->service->record($e['user_id'], 'community.voted', 'vote:' . $e['post_uid']);
    }

    public function onEventParticipation(array $e): void
    {
        $this->service->record(
            $e['user_id'],
            'event.participated',
            'event:' . $e['event_uid'],
            ['events.participated', 'events.category.' . $e['category']],
        );
    }

    /** @param array{user_id: int, type: string, target_id: string, tags: list<string>} $e */
    public function onFavoriteAdded(array $e): void
    {
        $stats = ['favorites.total', 'favorites.type.' . $e['type']];
        foreach ($e['tags'] as $tag) {
            $stats[] = 'favorites.tag.' . $tag;
        }

        // Clé unique par élément : retirer puis remettre un favori ne rapporte rien de plus
        $this->service->record($e['user_id'], 'favorite.added', "fav:{$e['type']}:{$e['target_id']}", $stats);
    }
}
