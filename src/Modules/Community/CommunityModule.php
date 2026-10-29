<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Content\Media\MediaController;
use App\Core\Module;
use App\Core\Router;

/**
 * Communauté : fil d'actualité (texte, sondage, photos, photo + description, vidéo),
 * likes, commentaires, votes, signalements.
 *
 * Événements émis :
 *   community.post_created     {user_id, post_uid, type}
 *   community.comment_created  {user_id, post_uid, post_author_id}
 *   community.poll_voted       {user_id, post_uid}
 */
final class CommunityModule implements Module
{
    public function routes(Router $r): void
    {
        // Lecture sans compte ; connecté => is_liked, vote, is_me
        $r->group(['middleware' => ['auth:optional']], static function (Router $r): void {
            $r->get('/community/posts', [CommunityController::class, 'feed']);
            $r->get('/community/posts/{id}', [CommunityController::class, 'show']);
            $r->get('/community/posts/{id}/comments', [CommunityController::class, 'comments']);
        });

        // Publier, réagir : compte obligatoire
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/media', [MediaController::class, 'upload'], ['throttle:upload,device']);
            $r->post('/community/posts', [CommunityController::class, 'create'], ['throttle:post,device']);
            $r->delete('/community/posts/{id}', [CommunityController::class, 'delete']);
            $r->put('/community/posts/{id}/like', [CommunityController::class, 'like']);
            $r->delete('/community/posts/{id}/like', [CommunityController::class, 'unlike']);
            $r->put('/community/posts/{id}/vote', [CommunityController::class, 'vote']);
            // Spec v1 : POST pour aimer et voter
            $r->post('/community/posts/{id}/like', [CommunityController::class, 'like']);
            $r->post('/community/posts/{id}/vote', [CommunityController::class, 'vote']);
            $r->post('/community/posts/{id}/comments', [CommunityController::class, 'addComment'], ['throttle:comment,device']);
            $r->delete('/community/comments/{id}', [CommunityController::class, 'deleteComment']);
            $r->post('/community/posts/{id}/report', [CommunityController::class, 'report']);
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
