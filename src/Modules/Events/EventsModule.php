<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Core\Module;
use App\Core\Router;
use App\Content\Import\ImportTargets;
use App\Modules\Favorites\FavoriteTypes;

/**
 * Agenda : liste filtrable par catégorie, fiche événement, programme, accès, calendrier,
 * participation (« Je participe ») et favoris (type "event").
 *
 * Événements émis :
 *   event.participation_added    {user_id, event_id, event_uid, category}
 *   event.participation_removed  {user_id, event_id}
 */
final class EventsModule implements Module
{
    public function routes(Router $r): void
    {
        // Consultation possible sans compte ; connecté => is_favorite / is_participating renseignés
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            // Spec v1 : Event (identifiant = slug)
            $r->get('/events', [SpecEventController::class, 'index']);
            $r->get('/events/categories', [EventController::class, 'categories']);
            $r->get('/events/agenda', [SpecEventController::class, 'agenda']);     // brief iOS §11.5 : agenda de Ma Ville
            $r->get('/events/agenda/full', [EventController::class, 'index']);     // liste enrichie historique (participants, favoris)
            $r->get('/events/{id}', [SpecEventController::class, 'show']);
            $r->get('/events/{id}/sheet', [EventController::class, 'show']);
            $r->get('/events/{id}/calendar.ics', [EventController::class, 'calendar']);
        });

        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/events/submissions', [SpecEventController::class, 'submit'], ['throttle:post']);
            $r->post('/events', [SpecEventController::class, 'store'], ['role:partner,admin']);
            $r->patch('/events/{id}', [SpecEventController::class, 'update']);
            $r->delete('/events/{id}', [SpecEventController::class, 'destroy']);
            $r->put('/events/{id}/participation', [EventController::class, 'participate']);
            $r->delete('/events/{id}/participation', [EventController::class, 'cancelParticipation']);
            $r->get('/users/me/events', [EventController::class, 'mine']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        FavoriteTypes::register('event', new EventFavoriteProvider());
        ImportTargets::register('events', new EventImportTarget());
    }
}
