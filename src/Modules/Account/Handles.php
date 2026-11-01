<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Core\Database;
use App\Exceptions\HttpException;
use App\Modules\Moderation\ContentFilter;

/**
 * Pseudo public (@handle, demandes 1a584b24 et 8417181e) : seul nom d'un habitant montré aux autres, jamais son e-mail.
 *
 * - Attribué à la création du compte : partie locale de l'e-mail (prénom + 4 chiffres pour une adresse Apple masquée).
 * - Format : 3 à 30 caractères [a-z0-9._-], commence par une lettre ou un chiffre, finit par une lettre, un chiffre ou `_`,
 *   pas deux points à la suite. Unique sans tenir compte de la casse.
 * - Premier changement libre, puis un tous les 30 jours ; l'ancien pseudo reste réservé 30 jours.
 */
final class Handles
{
    public const COOLDOWN_DAYS = 30;

    /** Même expression que l'app : une adresse e-mail écrite dans un message n'est pas une mention */
    public const MENTION_REGEX = '/(?<![A-Za-z0-9._-])@([A-Za-z0-9](?:[A-Za-z0-9._-]{0,38}[A-Za-z0-9_])?)/';

    private const RESERVED = ['admin', 'administrateur', 'administration', 'moderation', 'moderateur', 'moderatrice', 'modo', 'support', 'aide', 'help',
        'contact', 'myclermont', 'clermont', 'clermontferrand', 'equipe', 'team', 'officiel', 'official', 'system', 'systeme', 'root', 'staff',
        'me', 'moi', 'api', 'null', 'undefined', 'anonyme', 'mairie', 'police', 'pompiers', 'samu', 'commercant', 'pro', 'noreply', 'no-reply'];

    /** null si le pseudo est valable, sinon 'invalid' ou 'reserved' */
    public static function check(string $handle): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,28}[a-z0-9_]$/', $handle) || str_contains($handle, '..')) {
            return 'invalid';
        }
        $core = preg_replace('/[^a-z]/', '', $handle);
        if (in_array($core, array_map(static fn ($r) => str_replace('-', '', $r), self::RESERVED), true)) {
            return 'reserved';
        }
        // Usurpation de l'équipe : « admin.clermont », « moderateur2 », « myclermont.officiel »…
        foreach (['admin', 'modera', 'myclermont', 'support', 'officiel', 'official', 'staff'] as $r) {
            if (str_starts_with($core, $r) || ($r === 'myclermont' && str_contains($core, $r))) {
                return 'reserved';
            }
        }
        // Vocabulaire de la modération des posts (« connard42 » compris)
        if (ContentFilter::check(trim((string) preg_replace('/[^a-z]+/', ' ', $handle))) === 'words' || ContentFilter::check($core) === 'words') {
            return 'reserved';
        }

        return null;
    }

    /** Pris par un autre compte, ou ancien pseudo d'un autre compte réservé depuis moins de 30 jours */
    public static function taken(string $handle, int $exceptUserId = 0): bool
    {
        return Database::fetch('SELECT 1 FROM users WHERE handle = :h AND id <> :u', ['h' => $handle, 'u' => $exceptUserId]) !== null
            || Database::fetch('SELECT 1 FROM user_handle_history WHERE handle = :h AND user_id <> :u AND released_at > :d',
                ['h' => $handle, 'u' => $exceptUserId, 'd' => gmdate('Y-m-d H:i:s', time() - 86400 * self::COOLDOWN_DAYS)]) !== null;
    }

    /** { available, reason: taken|invalid|reserved|null, suggestions } */
    public static function availability(string $raw, int $userId): array
    {
        $handle = mb_strtolower(trim($raw));
        $reason = self::check($handle) ?? (self::taken($handle, $userId) ? 'taken' : null);

        return ['handle' => $handle, 'available' => $reason === null, 'reason' => $reason,
            'suggestions' => $reason === 'taken' ? self::suggestions($handle, $userId) : []];
    }

    /** @return list<string> 3 pseudos libres au plus, proches de celui demandé */
    public static function suggestions(string $handle, int $userId): array
    {
        $base = rtrim(substr($handle, 0, 26), '.-');
        $parts = preg_split('/[._-]/', $handle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidates = [$base . '-2', $base . '63', $base . '-3', $base . '_cf', $base . random_int(10, 99)];
        if (count($parts) > 1) {
            $candidates[] = $parts[0] . '.' . substr($parts[1], 0, 1);
        }
        $out = [];
        foreach (array_unique($candidates) as $c) {
            if (count($out) < 3 && self::check($c) === null && !self::taken($c, $userId)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** Pseudo attribué automatiquement (création du compte, comptes existants) */
    public static function generate(?string $email, ?string $firstName, int $exceptUserId = 0): string
    {
        $email = mb_strtolower(trim((string) $email));
        $relay = str_ends_with($email, 'privaterelay.appleid.com');
        $local = $email !== '' && !$relay ? (string) strstr($email, '@', true) : '';
        $base = self::slug(explode('+', $local)[0]);
        if (strlen($base) < 3 || self::check($base) === 'reserved') {
            // Adresse Apple masquée, ou e-mail inutilisable : prénom + 4 chiffres
            $first = self::slug((string) preg_replace('/\s.*/u', '', trim((string) $firstName)));
            $first = strlen($first) >= 2 && self::check($first . '1234') === null ? substr($first, 0, 20) : 'habitant';
            for ($i = 0; $i < 50; $i++) {
                $h = $first . random_int(1000, 9999);
                if (!self::taken($h, $exceptUserId)) {
                    return $h;
                }
            }
        }
        $base = substr($base, 0, 27);
        $base = rtrim($base, '.-');
        if (self::check($base) !== null) {
            $base = rtrim(substr($base, 0, 22), '.-') . random_int(1000, 9999);
        }
        for ($i = 1; $i < 1000; $i++) {
            $h = $i === 1 ? $base : $base . '-' . $i;
            if (!self::taken($h, $exceptUserId)) {
                return $h;
            }
        }

        return substr($base, 0, 20) . bin2hex(random_bytes(4));
    }

    /** Minuscules sans accents, caractères [a-z0-9._-], bords et points doublés nettoyés */
    public static function slug(string $s): string
    {
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) {
            $s = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        }
        $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', 'ø' => 'o', 'ł' => 'l', ' ' => '.']);
        $s = (string) preg_replace('/[^a-z0-9._-]/', '', $s);
        $s = (string) preg_replace('/\.{2,}/', '.', $s);
        $s = ltrim($s, '._-');

        return rtrim($s, '.-');
    }

    /** Remplit le pseudo des comptes qui n'en ont pas (lancé par `migrate`, idempotent) */
    public static function backfill(): int
    {
        $n = 0;
        foreach (Database::fetchAll("SELECT id, email, first_name, display_name FROM users WHERE handle IS NULL AND status <> 'deleted' ORDER BY id") as $u) {
            $h = self::generate($u['email'], $u['first_name'] ?? $u['display_name'], (int) $u['id']);
            $n += Database::execute('UPDATE users SET handle = :h WHERE id = :id AND handle IS NULL', ['h' => $h, 'id' => $u['id']]);
        }

        return $n;
    }

    /** Date du prochain changement possible, ou null s'il est possible tout de suite */
    public static function changeAvailableAt(array $user): ?string
    {
        if (empty($user['handle_changed_at'])) {
            return null;
        }
        $at = strtotime($user['handle_changed_at'] . ' UTC') + 86400 * self::COOLDOWN_DAYS;

        return $at > time() ? gmdate('Y-m-d\TH:i:s\Z', $at) : null;
    }

    /** Changement demandé par l'utilisateur (PATCH /me) ; erreurs 409 / 422 / 429 au format de l'app */
    public static function change(array $user, mixed $raw): void
    {
        $handle = is_string($raw) ? mb_strtolower(trim($raw)) : '';
        if ($handle === ($user['handle'] ?? null)) {
            return;
        }
        if (($available = self::changeAvailableAt($user)) !== null) {
            $date = (new \DateTimeImmutable($available))->setTimezone(new \DateTimeZone('Europe/Paris'));
            $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            throw new HttpException(429, 'handle_change_too_soon',
                'Tu pourras changer de pseudo à partir du ' . ((int) $date->format('j') === 1 ? '1er' : $date->format('j')) . ' ' . $months[(int) $date->format('n')] . '.',
                ['retry_at' => $available]);
        }
        $reason = self::check($handle);
        if ($reason === 'invalid') {
            throw new HttpException(422, 'handle_invalid', 'Ton pseudo doit faire de 3 à 30 caractères : lettres minuscules, chiffres, point, tiret ou tiret bas, sans commencer par un symbole.');
        }
        if ($reason === 'reserved') {
            throw new HttpException(422, 'handle_reserved', 'Ce pseudo n\'est pas disponible, choisis-en un autre.');
        }
        if (self::taken($handle, (int) $user['id'])) {
            throw new HttpException(409, 'handle_taken', 'Ce pseudo est déjà pris.', ['suggestions' => self::suggestions($handle, (int) $user['id'])]);
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::transaction(static function () use ($user, $handle, $now): void {
            if (!empty($user['handle'])) {
                Database::execute('INSERT INTO user_handle_history (handle, user_id, released_at) VALUES (:h, :u, :d) ON DUPLICATE KEY UPDATE user_id = :u2, released_at = :d2',
                    ['h' => $user['handle'], 'u' => $user['id'], 'd' => $now, 'u2' => $user['id'], 'd2' => $now]);
            }
            Database::execute('DELETE FROM user_handle_history WHERE handle = :h', ['h' => $handle]);
            Database::execute('UPDATE users SET handle = :h, handle_changed_at = :d, updated_at = :d2 WHERE id = :id', ['h' => $handle, 'd' => $now, 'd2' => $now, 'id' => $user['id']]);
        });
    }

    /**
     * Mentions d'un texte : comptes actifs, sans l'auteur ni doublons, 10 au plus.
     *
     * @return list<array{id: int, uid: string, handle: string}>
     */
    public static function mentions(?string $text, int $authorId): array
    {
        if ($text === null || !preg_match_all(self::MENTION_REGEX, $text, $m)) {
            return [];
        }
        $handles = array_slice(array_values(array_unique(array_map('strtolower', $m[1]))), 0, 30);
        $out = [];
        foreach ($handles as $h) {
            $u = Database::fetch("SELECT id, uid, handle FROM users WHERE handle = :h AND status = 'active'", ['h' => $h]);
            if ($u !== null && (int) $u['id'] !== $authorId && count($out) < 10) {
                $out[] = ['id' => (int) $u['id'], 'uid' => $u['uid'], 'handle' => $u['handle']];
            }
        }

        return $out;
    }

    /** Pseudo affiché d'un auteur (repli pour un compte encore sans pseudo, jamais l'e-mail) */
    public static function display(?string $handle, ?string $fallback = null): string
    {
        return $handle !== null && $handle !== '' ? $handle : (trim((string) $fallback) !== '' ? (string) $fallback : 'habitant');
    }
}
