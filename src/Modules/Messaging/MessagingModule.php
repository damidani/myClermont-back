<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Module;
use App\Core\Router;

/**
 * Messagerie entre utilisateurs. Générique : une conversation porte sur un « contexte »
 * (une petite annonce aujourd'hui) déclaré par le module concerné via MessagingContexts.
 * Les conversations sont ouvertes par les modules (ex. POST /v1/classifieds/{id}/contact).
 *
 * Événement émis : messaging.message_sent {conversation_id, sender_id, recipient_ids}
 */
final class MessagingModule implements Module
{
    public function routes(Router $r): void
    {
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->get('/conversations', [MessagingController::class, 'inbox']);
            $r->get('/conversations/unread-count', [MessagingController::class, 'unreadCount']);
            $r->get('/conversations/{id}', [MessagingController::class, 'show']);
            $r->delete('/conversations/{id}', [MessagingController::class, 'archive']);
            $r->get('/conversations/{id}/messages', [MessagingController::class, 'messages']);
            $r->post('/conversations/{id}/messages', [MessagingController::class, 'send'], ['throttle:message,device']);
            $r->post('/conversations/{id}/read', [MessagingController::class, 'read']);
            $r->post('/conversations/{id}/report', [MessagingController::class, 'report']);
            $r->put('/users/{id}/block', [MessagingController::class, 'block']);
            $r->delete('/users/{id}/block', [MessagingController::class, 'unblock']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
