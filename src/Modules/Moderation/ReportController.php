<?php

declare(strict_types=1);

namespace App\Modules\Moderation;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Modules\Messaging\MessagingService;
use App\Security\Crypto;
use App\Support\Validator;

/**
 * Signalements et blocages (spec v1 › Contenu publié par les utilisateurs, règle 1.2 de l'App Store).
 *
 *   POST   /reports              { targetType, targetId, reason, details? }   → 201 { id }
 *   POST   /me/blocks/{userId}   → 204
 *   DELETE /me/blocks/{userId}   → 204
 */
final class ReportController
{
    /** type => [table, colonne d'identifiant public] */
    public const TARGETS = [
        'post'       => ['community_posts', 'uid'],
        'comment'    => ['community_comments', 'uid'],
        'review'     => ['place_reviews', 'uid'],
        'classified' => ['classifieds', 'uid'],
        'user'       => ['users', 'uid'],
        'event'      => ['events', 'slug'],
        'message'    => ['messages', 'uid'],
    ];

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'target_type' => 'required|in:' . implode(',', array_keys(self::TARGETS)),
            'target_id'   => 'required|string|max:64',
            'reason'      => 'required|in:spam,scam,offensive,harassment,inappropriate,other',
            'details'     => 'nullable|string|max:500',
        ]);
        [$table, $column] = self::TARGETS[$data['target_type']];
        if (Database::fetch("SELECT 1 FROM {$table} WHERE {$column} = :id", ['id' => $data['target_id']]) === null) {
            throw new HttpException(404, 'not_found', 'Ce contenu n\'existe plus.');
        }

        $uid = Crypto::uuid4();
        $inserted = Database::execute(
            'INSERT IGNORE INTO reports (uid, user_id, target_type, target_id, reason, details, created_at) VALUES (:uid, :u, :t, :id, :r, :d, :c)',
            ['uid' => $uid, 'u' => $request->attributes['user_id'], 't' => $data['target_type'], 'id' => $data['target_id'],
             'r' => $data['reason'], 'd' => $data['details'] ?? null, 'c' => gmdate('Y-m-d H:i:s')],
        );
        if ($inserted > 0) {
            Logger::info('Signalement reçu', ['type' => $data['target_type'], 'target' => $data['target_id'], 'reason' => $data['reason']]);
        }

        // Déjà signalé par ce compte : réponse identique (idempotent)
        return Response::json(['id' => $inserted > 0 ? $uid : null, 'status' => 'received'], 201);
    }

    public function block(Request $request): Response
    {
        (new MessagingService())->block((int) $request->attributes['user_id'], (int) $this->user((string) $request->param('userId'))['id']);

        return Response::noContent();
    }

    public function unblock(Request $request): Response
    {
        (new MessagingService())->unblock((int) $request->attributes['user_id'], (int) $this->user((string) $request->param('userId'))['id']);

        return Response::noContent();
    }

    private function user(string $uid): array
    {
        $user = preg_match('/^[0-9a-f-]{36}$/i', $uid) ? Database::fetch('SELECT id FROM users WHERE uid = :u', ['u' => strtolower($uid)]) : null;

        return $user ?? throw new HttpException(404, 'user_not_found', 'Utilisateur introuvable.');
    }
}
