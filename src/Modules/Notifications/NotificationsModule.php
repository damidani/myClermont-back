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
        // Boîte de notifications et préférences de l'app (4890352c)
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->get('/me/notifications', [NoticeController::class, 'index']);
            $r->post('/me/notifications/read', [NoticeController::class, 'read']);
            $r->post('/me/notifications/read-all', [NoticeController::class, 'readAll']);
            $r->delete('/me/notifications/{id}', [NoticeController::class, 'destroy']);
            $r->get('/me/notification-preferences', [NoticeController::class, 'preferences']);
            $r->put('/me/notification-preferences', [NoticeController::class, 'updatePreferences']);
        });
    }

    public function listeners(): array
    {
        return [
            'jobs.application_submitted' => [[self::class, 'onApplication']],
        ];
    }

    public function boot(): void
    {
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
