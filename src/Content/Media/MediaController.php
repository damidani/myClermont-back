<?php

declare(strict_types=1);

namespace App\Content\Media;

use App\Core\Request;
use App\Core\Response;

/**
 * POST /v1/media — corps brut (image/jpeg, image/png, video/mp4, video/quicktime), signé comme toute requête.
 * Réservé aux utilisateurs connectés (les médias sont publics une fois publiés).
 */
final class MediaController
{
    public function upload(Request $request): Response
    {
        return Response::success(
            (new MediaStore())->store($request->body, $request->attributes['device'], $request->attributes['user_id']),
            201,
        );
    }
}
