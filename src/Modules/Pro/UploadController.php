<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Content\Media\MediaStore;
use App\Content\Uploads\ImageProcessor;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Photos envoyées depuis l'espace pro : même flux que l'app (spec v1 › Envoi de fichiers), avec la session web.
 *
 *   POST /pro/uploads { purpose: place|deal|event|housing|story, mime, sizeBytes } → { uploadId, uploadUrl, expiresAt }
 *   PUT  uploadUrl (corps = le fichier)                                     → 204   (route commune /uploads/{id}/blob)
 *   POST /pro/uploads/{id}/confirm                                          → { uploadId, kind, url, width, height }
 *
 * L'image est nettoyée (métadonnées, GPS) et réduite ; l'URL renvoyée va dans photos[].src, image, imageUrl ou images[].
 */
final class UploadController
{
    private const PURPOSES = ['place', 'deal', 'event', 'housing', 'story', 'verification'];
    /** Story (b5b4c116) : vidéo MP4 / MOV de 30 Mo et 15 s au plus */
    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];
    private const MAX_VIDEO = 30 * 1024 * 1024;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];
    private const MAX = 10 * 1024 * 1024;

    public function create(Request $request): Response
    {
        Pro::requireRole($request, 'gerant');
        $j = $request->json();
        if (!in_array($j['purpose'] ?? null, self::PURPOSES, true)) {
            throw new ValidationException(['purpose' => 'Usage : ' . implode(', ', self::PURPOSES) . '.']);
        }
        $video = $j['purpose'] === 'story' && in_array($j['mime'] ?? null, self::VIDEO_MIMES, true);
        if (!$video && !in_array($j['mime'] ?? null, self::MIMES, true)) {
            throw new ValidationException(['mime' => $j['purpose'] === 'story' ? 'Formats acceptés : JPEG, PNG, WebP, HEIC, MP4, MOV.' : 'Formats acceptés : JPEG, PNG, WebP, HEIC.']);
        }
        if (!is_int($j['size_bytes'] ?? null) || $j['size_bytes'] < 1 || $j['size_bytes'] > ($video ? self::MAX_VIDEO : self::MAX)) {
            throw new ValidationException(['size_bytes' => $video ? 'Vidéo trop lourde (30 Mo maximum).' : 'Photo trop lourde (10 Mo maximum).']);
        }
        $uid = Crypto::uuid4();
        $token = Crypto::randomToken(32);
        $expires = time() + 600;
        Database::insert(
            'INSERT INTO uploads (uid, member_id, purpose, mime, size_bytes, token_hash, expires_at, created_at) VALUES (:u, :m, :p, :mi, :s, :t, :x, :c)',
            ['u' => $uid, 'm' => Pro::member($request)['id'], 'p' => $j['purpose'], 'mi' => $j['mime'], 's' => $j['size_bytes'], 't' => Crypto::hashToken($token),
             'x' => gmdate('Y-m-d H:i:s', $expires), 'c' => gmdate('Y-m-d H:i:s')],
        );

        return Response::success(['id' => $uid, 'upload_id' => $uid,
            'upload_url' => rtrim((string) Config::get('app.url'), '/') . '/api/v1/uploads/' . $uid . '/blob?t=' . $token, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires)], 201);
    }

    public function confirm(Request $request): Response
    {
        $upload = Database::fetch('SELECT * FROM uploads WHERE uid = :u AND member_id = :m', ['u' => strtolower((string) $request->param('id')), 'm' => Pro::member($request)['id']])
            ?? throw new HttpException(404, 'not_found', 'Envoi introuvable.');
        if ($upload['confirmed_at'] === null) {
            if ($upload['storage_key'] === null) {
                throw new HttpException(409, 'conflict', 'Le fichier n\'a pas encore été envoyé.');
            }
            $path = \App\Content\Uploads\UploadController::pendingRoot() . '/' . $upload['storage_key'];
            try {
                $bytes = (string) @file_get_contents($path);
                $detected = (string) MediaStore::detect($bytes);
                $isVideo = $upload['purpose'] === 'story' && in_array($detected, self::VIDEO_MIMES, true);
                if (!$isVideo) {
                    $bytes = ImageProcessor::prepare($bytes, $detected);
                }
                $media = (new MediaStore())->store($bytes, ['id' => null], null, $upload['uid']);
                if ($media['kind'] !== 'image' && !($isVideo && $media['kind'] === 'video')) {
                    throw new HttpException(415, 'validation_failed', 'Seules les photos sont acceptées ici.');
                }
            } finally {
                @unlink($path);
            }
            // Photo d'un contenu pro : gardée (pas d'expiration des médias non rattachés)
            Database::execute('UPDATE media SET attached_at = :d WHERE uid = :u', ['d' => gmdate('Y-m-d H:i:s'), 'u' => $upload['uid']]);
            Database::execute('UPDATE uploads SET confirmed_at = :d, url = :url WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'url' => $media['url'], 'id' => $upload['id']]);
            $upload['url'] = $media['url'];
        }
        $m = Database::fetch('SELECT kind, width, height FROM media WHERE uid = :u', ['u' => $upload['uid']]);

        return Response::success(['id' => $upload['uid'], 'upload_id' => $upload['uid'], 'kind' => $m['kind'] ?? 'image', 'url' => $upload['url'],
            'width' => isset($m['width']) ? (int) $m['width'] : null, 'height' => isset($m['height']) ? (int) $m['height'] : null]);
    }
}
