<?php

declare(strict_types=1);

namespace App\Content\Uploads;

use App\Content\Documents\DocumentStore;
use App\Content\Media\MediaStore;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;
use App\Support\Validator;

/**
 * Envoi de fichiers selon la spec v1 (› Envoi de fichiers) :
 *
 *  1. POST /uploads { purpose, mime, sizeBytes }   → { uploadId, uploadUrl, expiresAt }   (URL valable 10 minutes)
 *  2. PUT  uploadUrl  (corps = le fichier)          → 204   — l'app envoie directement, sans signature d'appareil : le jeton est dans l'URL
 *  3. POST /uploads/{id}/confirm                    → { uploadId, kind, url, width, height }
 *     le serveur vérifie le type réel et la taille, retire les métadonnées (position GPS), réduit les images à 1 600 px.
 *  4. Le post, l'annonce ou la candidature référence ensuite l'uploadId.
 */
final class UploadController
{
    /** purpose => types acceptés */
    private const PURPOSES = [
        'cv'         => ['application/pdf'],
        'post'       => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'video/mp4', 'video/quicktime'],
        'classified' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
        'avatar'     => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
        'place'      => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
    ];
    private const MAX = ['image' => 10 * 1024 * 1024, 'pdf' => 5 * 1024 * 1024, 'video' => 60 * 1024 * 1024];

    public function create(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'purpose'    => 'required|in:' . implode(',', array_keys(self::PURPOSES)),
            'mime'       => 'required|string|max:40',
            'size_bytes' => 'required|integer|min:1',
        ]);
        if (!in_array($data['mime'], self::PURPOSES[$data['purpose']], true)) {
            throw new ValidationException(['mime' => 'Type de fichier non accepté ici : ' . implode(', ', self::PURPOSES[$data['purpose']]) . '.']);
        }
        if ($data['purpose'] === 'place' && !in_array($request->attributes['role'] ?? 'user', ['partner', 'moderator', 'admin'], true)) {
            throw HttpException::forbidden('forbidden', 'Seuls les partenaires peuvent envoyer des photos de lieu.');
        }
        $max = self::MAX[self::family($data['mime'])];
        if ($data['size_bytes'] > $max) {
            throw new ValidationException(['size_bytes' => 'Fichier trop lourd (' . ($max >> 20) . ' Mo maximum).']);
        }

        $uid = Crypto::uuid4();
        $token = Crypto::randomToken(32);
        $expires = time() + 600;
        Database::insert(
            'INSERT INTO uploads (uid, user_id, device_id, purpose, mime, size_bytes, token_hash, expires_at, created_at)
             VALUES (:uid, :u, :d, :p, :m, :s, :t, :x, :c)',
            ['uid' => $uid, 'u' => $request->attributes['user_id'], 'd' => $request->attributes['device']['id'], 'p' => $data['purpose'],
             'm' => $data['mime'], 's' => $data['size_bytes'], 't' => Crypto::hashToken($token), 'x' => gmdate('Y-m-d H:i:s', $expires), 'c' => gmdate('Y-m-d H:i:s')],
        );

        return Response::success([
            'upload_id'  => $uid,
            'upload_url' => rtrim((string) Config::get('app.url'), '/') . '/v1/uploads/' . $uid . '/blob?t=' . $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
        ], 201);
    }

    /** PUT uploadUrl : authentifié par le jeton de l'URL (usage unique, 10 minutes). */
    public function receive(Request $request): Response
    {
        $upload = Database::fetch('SELECT * FROM uploads WHERE uid = :u', ['u' => strtolower((string) $request->param('id'))]);
        $token = (string) ($request->query()['t'] ?? '');
        if ($upload === null || $token === '' || !hash_equals($upload['token_hash'], Crypto::hashToken($token))
            || $upload['received_at'] !== null || strtotime($upload['expires_at'] . ' UTC') < time()) {
            throw HttpException::forbidden('forbidden', 'Lien d\'envoi invalide ou expiré.');
        }
        $bytes = $request->body;
        if ($bytes === '' || strlen($bytes) > self::MAX[self::family($upload['mime'])]) {
            throw new HttpException(413, 'payload_too_large', 'Fichier vide ou trop lourd.');
        }
        $key = gmdate('Ymd') . '/' . $upload['uid'];
        $path = self::pendingRoot() . '/' . $key;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, $bytes, LOCK_EX);
        Database::execute('UPDATE uploads SET storage_key = :k, received_at = :d WHERE id = :id', ['k' => $key, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $upload['id']]);

        return Response::noContent();
    }

    public function confirm(Request $request): Response
    {
        $upload = Database::fetch('SELECT * FROM uploads WHERE uid = :u AND user_id = :usr', ['u' => strtolower((string) $request->param('id')), 'usr' => $request->attributes['user_id']])
            ?? throw new HttpException(404, 'upload_not_found', 'Envoi introuvable.');
        if ($upload['confirmed_at'] !== null) {
            return Response::success(self::present($upload));
        }
        if ($upload['storage_key'] === null) {
            throw new HttpException(409, 'conflict', 'Le fichier n\'a pas encore été envoyé.');
        }
        $path = self::pendingRoot() . '/' . $upload['storage_key'];
        $bytes = (string) @file_get_contents($path);
        $device = $request->attributes['device'];
        $userId = (int) $request->attributes['user_id'];

        try {
            if ($upload['purpose'] === 'cv') {
                (new DocumentStore())->store($bytes, 'cv', $device, $userId, null, $upload['uid']);
                $result = ['kind' => 'document', 'url' => null, 'width' => null, 'height' => null];
            } else {
                $bytes = ImageProcessor::prepare($bytes, (string) MediaStore::detect($bytes) ?: '');
                $media = (new MediaStore())->store($bytes, $device, $userId, $upload['uid']);
                if ($media['kind'] === 'video') {
                    $duration = ImageProcessor::videoDuration($bytes);
                    if ($duration !== null && $duration > 60.5) {
                        (new MediaStore())->deleteByIds([(int) Database::fetch('SELECT id FROM media WHERE uid = :u', ['u' => $upload['uid']])['id']]);
                        throw new ValidationException(['file' => 'Vidéo trop longue (60 secondes maximum).']);
                    }
                }
                if (!in_array($media['mime'], self::PURPOSES[$upload['purpose']], true)) {
                    throw new HttpException(415, 'unsupported_media', 'Type de fichier non accepté ici.');
                }
                $result = ['kind' => $media['kind'], 'url' => $media['url'], 'width' => $media['width'], 'height' => $media['height']];
            }
        } finally {
            @unlink($path);
        }

        Database::execute('UPDATE uploads SET confirmed_at = :d, url = :url WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'url' => $result['url'], 'id' => $upload['id']]);

        return Response::success(['upload_id' => $upload['uid']] + $result);
    }

    private static function present(array $u): array
    {
        $m = Database::fetch('SELECT kind, width, height FROM media WHERE uid = :u', ['u' => $u['uid']]);

        return ['upload_id' => $u['uid'], 'kind' => $m['kind'] ?? 'document', 'url' => $u['url'],
                'width' => isset($m['width']) ? (int) $m['width'] : null, 'height' => isset($m['height']) ? (int) $m['height'] : null];
    }

    private static function family(string $mime): string
    {
        return $mime === 'application/pdf' ? 'pdf' : (str_starts_with($mime, 'video/') ? 'video' : 'image');
    }

    public static function pendingRoot(): string
    {
        return dirname(__DIR__, 3) . '/storage/uploads';
    }

    /** Fichiers envoyés mais jamais confirmés : supprimés après 1 h (cron media:purge). */
    public static function purge(): int
    {
        $n = 0;
        foreach (Database::fetchAll('SELECT id, storage_key FROM uploads WHERE confirmed_at IS NULL AND expires_at < :d', ['d' => gmdate('Y-m-d H:i:s', time() - 3600)]) as $u) {
            if ($u['storage_key']) {
                @unlink(self::pendingRoot() . '/' . $u['storage_key']);
            }
            Database::execute('DELETE FROM uploads WHERE id = :id', ['id' => $u['id']]);
            $n++;
        }

        return $n;
    }
}
