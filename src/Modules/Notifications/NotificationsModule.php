<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Messaging\Push\Push;

/**
 * Notifications push selon la spec v1 : appareils et thèmes suivis (POST / PATCH / DELETE /devices),
 * réponses communauté (regroupées par 15 min), accusé de candidature, sorties du week-end, rappels, bons plans.
 */
final class NotificationsModule implements Module
{
    public function routes(Router $r): void
    {
        $r->post('/devices', [DeviceTopicsController::class, 'store'], ['auth:optional']);
        $r->patch('/devices/{id}', [DeviceTopicsController::class, 'update'], ['auth:optional']);
        $r->delete('/devices/{id}', [DeviceTopicsController::class, 'destroy']);
        $r->post('/live-activities', [DeviceTopicsController::class, 'liveActivity'], ['auth:optional']);
    }

    public function listeners(): array
    {
        return [
            'community.comment_created'  => [[self::class, 'onComment']],
            'jobs.application_submitted' => [[self::class, 'onApplication']],
        ];
    }

    public function boot(): void
    {
    }

    /** Réponse à mon post : une notification par tranche de 15 minutes, quel que soit le nombre de commentaires. */
    public function onComment(array $e): void
    {
        if (empty($e['post_author_id']) || (int) $e['post_author_id'] === (int) $e['user_id']) {
            return;
        }
        $name = Database::fetch('SELECT COALESCE(display_name, username) AS n FROM users WHERE id = :u', ['u' => $e['user_id']])['n'] ?? 'Quelqu\'un';
        Push::toUser((int) $e['post_author_id'], 'community.replies', 'Nouvelle réponse', "{$name} a commenté ta publication.", [
            'deeplink' => 'myclermont://community/post/' . $e['post_uid'], 'thread_id' => 'community:' . $e['post_uid'], 'delay' => 900,
        ]);
    }

    public function onApplication(array $e): void
    {
        if (empty($e['user_id'])) {
            return;
        }
        $title = Database::fetch('SELECT title FROM job_offers WHERE uid = :u', ['u' => $e['offer_uid']])['title'] ?? 'l\'offre';
        Push::toUser((int) $e['user_id'], 'jobs.applications', 'Candidature envoyée', "Ta candidature pour « {$title} » a bien été transmise.", [
            'deeplink' => 'myclermont://job/' . $e['offer_uid'], 'thread_id' => 'jobs',
        ]);
    }
}
