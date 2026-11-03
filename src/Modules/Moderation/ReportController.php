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
 *   DELETE /me/blocks/{userId}   → 204 ({userId} = id ou pseudo)
 *   POST   /me/blocks            { authorId } ou { author: "<pseudo>" }        → 201 { blocked }
 *   GET    /me/blocks            → [{ id, handle, displayName, blockedAt }]
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

    /** Blocage depuis le fil communautaire (b708f663) : par id d'auteur (authorId) ou par pseudo affiché */
    public function blockAuthor(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['author_id' => 'nullable|string|max:64', 'author' => 'nullable|string|max:64']);
        $key = trim((string) ($data['author_id'] ?? $data['author'] ?? ''));
        if ($key === '') {
            throw new HttpException(400, 'validation_failed', 'Indique l\'auteur à bloquer.', ['author' => ['required']]);
        }
        $user = $this->user($key);
        if ((int) $user['id'] === (int) $request->attributes['user_id']) {
            throw new HttpException(400, 'validation_failed', 'Tu ne peux pas te bloquer toi-même.', ['author' => ['self']]);
        }
        (new MessagingService())->block((int) $request->attributes['user_id'], (int) $user['id']);

        return Response::json(['blocked' => true, 'author_id' => $user['uid']], 201);
    }

    public function blocks(Request $request): Response
    {
        return Response::success(array_map(static fn ($r) => ['id' => $r['uid'], 'handle' => $r['handle'],
            'display_name' => \App\Modules\Account\Handles::display($r['handle'], $r['display_name']), 'blocked_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC'))],
            Database::fetchAll('SELECT u.uid, u.handle, u.display_name, b.created_at FROM user_blocks b JOIN users u ON u.id = b.blocked_id
                                WHERE b.blocker_id = :u ORDER BY b.created_at DESC', ['u' => $request->attributes['user_id']])));
    }

    /** Compte par uid ou par pseudo (avec ou sans @) */
    private function user(string $uid): array
    {
        $uid = rawurldecode($uid);
        $user = preg_match('/^[0-9a-f-]{36}$/i', $uid)
            ? Database::fetch('SELECT id, uid FROM users WHERE uid = :u', ['u' => strtolower($uid)])
            : Database::fetch("SELECT id, uid FROM users WHERE handle = :h AND status <> 'deleted'", ['h' => ltrim($uid, '@')]);

        return $user ?? throw new HttpException(404, 'user_not_found', 'Utilisateur introuvable.');
    }
}
