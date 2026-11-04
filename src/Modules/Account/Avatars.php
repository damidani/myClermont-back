<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Core\Config;
use App\Core\Database;
use App\Exceptions\ValidationException;
use App\Modules\Notifications\Notices;

/**
 * Photo de profil modérée (d262fe7d, règle App Store 1.2) : PUT /me/avatar la met en attente (avatar_pending_url) ;
 * elle n'est diffusée (avatar_kind = 'photo') qu'après validation dans /admin/moderation. Refus : l'auteur est prévenu.
 */
final class Avatars
{
    /** Met la photo en file de modération ; l'avatar public reste l'ancien en attendant */
    public static function submit(int $userId, mixed $url): void
    {
        if (!is_string($url) || !str_starts_with($url, (string) Config::get('app.media_base_url') . '/') || strlen($url) > 500) {
            throw new ValidationException(['url' => 'Envoie d\'abord la photo avec POST /uploads (purpose « avatar »).']);
        }
        Database::execute('UPDATE users SET avatar_pending_url = :u, avatar_pending_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id', ['u' => $url, 'id' => $userId]);
    }

    /** Retire la photo (publique et en attente) : retour à l'avatar par défaut */
    public static function remove(int $userId): void
    {
        Database::execute("UPDATE users SET avatar_kind = 'symbol', avatar_value = IF(avatar_kind = 'photo', NULL, avatar_value), avatar_pending_url = NULL, avatar_pending_at = NULL,
                           updated_at = UTC_TIMESTAMP() WHERE id = :id", ['id' => $userId]);
    }

    public static function approve(string $userUid): void
    {
        $u = self::pending($userUid);
        Database::execute("UPDATE users SET avatar_kind = 'photo', avatar_value = avatar_pending_url, avatar_pending_url = NULL, avatar_pending_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id",
            ['id' => $u['id']]);
    }

    public static function refuse(string $userUid, string $reason = ''): void
    {
        $u = self::pending($userUid);
        Database::execute('UPDATE users SET avatar_pending_url = NULL, avatar_pending_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $u['id']]);
        $reason = trim($reason);
        Notices::send((int) $u['id'], 'avatar_rejected', 'Photo de profil refusée',
            ($reason !== '' ? 'Motif : ' . mb_substr($reason, 0, 200) . '. ' : 'Elle ne respecte pas les règles de la communauté. ') . 'Tu peux en choisir une autre.',
            ['type' => 'url', 'url' => 'myclermont://profile', 'reason' => $reason !== '' ? $reason : null]);
    }

    /** État vu par l'utilisateur lui-même (GET /me) : { url, status: pending } ou null */
    public static function own(array $user): ?array
    {
        return !empty($user['avatar_pending_url']) ? ['url' => $user['avatar_pending_url'], 'status' => 'pending'] : null;
    }

    private static function pending(string $userUid): array
    {
        return Database::fetch('SELECT id FROM users WHERE uid = :u AND avatar_pending_url IS NOT NULL', ['u' => $userUid])
            ?? throw new \DomainException('Photo introuvable (déjà traitée ?).');
    }
}
