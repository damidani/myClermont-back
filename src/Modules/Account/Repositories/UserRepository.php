<?php

declare(strict_types=1);

namespace App\Modules\Account\Repositories;

use App\Core\Database;
use App\Modules\Premium\PremiumService;
use App\Security\Crypto;

final class UserRepository
{
    private const COLUMNS = 'u.id, u.uid, u.email, u.password_hash, u.username, u.display_name, u.bio, u.neighborhood_id, u.points,
                             u.avatar_kind, u.avatar_value, u.notifications_enabled, u.role, u.apple_sub, u.google_sub,
                             u.apple_refresh_encrypted, u.deleted_at,
                             u.premium_until, u.referral_code, u.referred_by, u.status, u.created_at, u.updated_at,
                             n.slug AS neighborhood_slug, n.name AS neighborhood_name';

    private const FROM = 'users u LEFT JOIN neighborhoods n ON n.id = u.neighborhood_id';

    /** Champs modifiables par l'utilisateur via PATCH /users/me */
    public const UPDATABLE = ['username', 'display_name', 'bio', 'neighborhood_id', 'avatar_kind', 'avatar_value', 'notifications_enabled'];

    /** Pseudos interdits (collision avec des routes ou usurpation) */
    public const RESERVED_USERNAMES = ['me', 'admin', 'administrateur', 'moderateur', 'support', 'myclermont', 'clermont', 'api', 'system', 'username-available'];

    public function findById(int $id): ?array
    {
        return Database::fetch('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE u.id = :id', ['id' => $id]);
    }

    /** Compte rattaché à un fournisseur d'identité (apple_sub, google_sub). */
    public function findBySub(string $column, string $sub): ?array
    {
        if (!in_array($column, ['apple_sub', 'google_sub'], true)) {
            throw new \InvalidArgumentException($column);
        }

        return Database::fetch('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE u.{$column} = :s", ['s' => $sub]);
    }

    /**
     * Compte créé à la première connexion Apple / Google / code e-mail.
     *
     * @param array{email?: ?string, apple_sub?: ?string, google_sub?: ?string, display_name?: ?string} $identity
     */
    public function createFromIdentity(array $identity): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert(
            'INSERT INTO users (uid, email, username, display_name, apple_sub, google_sub, referral_code, status, created_at, updated_at)
             VALUES (:uid, :email, :username, :display, :apple, :google, :ref, \'active\', :created, :updated)',
            [
                'uid' => Crypto::uuid4(), 'email' => isset($identity['email']) ? mb_strtolower($identity['email']) : null,
                'username' => 'u' . bin2hex(random_bytes(7)), // identifiant technique (ancien pseudo), jamais affiché
                'display' => $this->availableDisplayName($identity['display_name'] ?? null),
                'apple' => $identity['apple_sub'] ?? null, 'google' => $identity['google_sub'] ?? null,
                'ref' => self::newReferralCode(), 'created' => $now, 'updated' => $now,
            ],
        );

        return $this->findById($id) ?? throw new \RuntimeException('Création utilisateur échouée.');
    }

    public function linkSub(int $id, string $column, string $sub): void
    {
        if (in_array($column, ['apple_sub', 'google_sub'], true)) {
            Database::execute("UPDATE users SET {$column} = :s, updated_at = :d WHERE id = :id", ['s' => $sub, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
        }
    }

    public function displayNameTaken(string $name, int $exceptUserId = 0): bool
    {
        // utf8mb4_unicode_ci : « Élodie » = « elodie »
        return Database::fetch('SELECT 1 FROM users WHERE display_name = :n AND id <> :id', ['n' => $name, 'id' => $exceptUserId]) !== null;
    }

    /** Nom proposé (Apple fullName, début de l'e-mail) rendu unique : « Alex Clermont », « Alex Clermont 2 »… */
    private function availableDisplayName(?string $wanted): string
    {
        $base = trim((string) preg_replace('/\s+/u', ' ', (string) $wanted));
        $base = mb_strlen($base) >= 2 ? mb_substr($base, 0, 36) : 'Clermontois';
        for ($i = 1; $i < 1000; $i++) {
            $name = $i === 1 ? $base : $base . ' ' . $i;
            if (!$this->displayNameTaken($name)) {
                return $name;
            }
        }

        return $base . ' ' . random_int(1000, 9999);
    }

    public function findByEmail(string $email): ?array
    {
        return Database::fetch('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE u.email = :email', ['email' => mb_strtolower($email)]);
    }

    public function findByUsername(string $username): ?array
    {
        return Database::fetch('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE u.username = :u', ['u' => $username]);
    }

    public function findIdByReferralCode(string $code): ?int
    {
        $row = Database::fetch('SELECT id FROM users WHERE referral_code = :c AND status = \'active\'', ['c' => strtoupper($code)]);

        return $row === null ? null : (int) $row['id'];
    }

    public function usernameTaken(string $username, ?int $exceptUserId = null): bool
    {
        // La collation utf8mb4_unicode_ci rend la comparaison insensible à la casse et aux accents
        return Database::fetch(
            'SELECT 1 FROM users WHERE username = :u AND id <> :id',
            ['u' => $username, 'id' => $exceptUserId ?? 0],
        ) !== null;
    }

    public function create(string $email, string $password, string $username, ?int $neighborhoodId, ?int $referredBy): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = Database::insert(
            'INSERT INTO users (uid, email, password_hash, username, neighborhood_id, referral_code, referred_by, status, created_at, updated_at)
             VALUES (:uid, :email, :hash, :username, :hood, :ref, :by, \'active\', :created, :updated)',
            [
                'uid' => Crypto::uuid4(), 'email' => mb_strtolower($email), 'hash' => self::hashPassword($password),
                'username' => $username, 'hood' => $neighborhoodId, 'ref' => self::newReferralCode(), 'by' => $referredBy,
                'created' => $now, 'updated' => $now,
            ],
        );

        return $this->findById($id) ?? throw new \RuntimeException('Création utilisateur échouée.');
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): array
    {
        $fields = array_intersect_key($fields, array_flip(self::UPDATABLE));
        if ($fields !== []) {
            $sets = implode(', ', array_map(static fn (string $f) => "{$f} = :{$f}", array_keys($fields)));
            Database::execute(
                "UPDATE users SET {$sets}, updated_at = :updated_at WHERE id = :id",
                $fields + ['updated_at' => gmdate('Y-m-d H:i:s'), 'id' => $id],
            );
        }

        return $this->findById($id) ?? throw new \RuntimeException('Utilisateur introuvable.');
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        Database::execute('UPDATE users SET password_hash = :h, updated_at = :d WHERE id = :id', ['h' => $hash, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::passwordAlgo());
    }

    public static function passwordAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** 8 caractères sans ambiguïté visuelle (pas de 0/O, 1/I/L) : facile à dicter et à recopier. */
    public static function newReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** Objet « User » de la spec v1 (AuthSession.user, GET /me). Jamais d'e-mail ni d'identifiant Apple / Google. */
    public static function presentSpec(array $user): array
    {
        $plusActive = PremiumService::isActive($user);

        return [
            'id'                    => $user['uid'],
            'display_name'          => $user['display_name'] ?? $user['username'],
            'bio'                   => $user['bio'],
            'neighborhood'          => $user['neighborhood_name'] ?? null,
            'avatar'                => $user['avatar_kind'] === 'photo'
                ? ['kind' => 'photo', 'url' => $user['avatar_value']]
                : ['kind' => 'symbol', 'value' => $user['avatar_value'] ?? 'person.crop.circle.fill'],
            'notifications_enabled' => (bool) ($user['notifications_enabled'] ?? true),
            'role'                  => $user['role'] ?? 'user',
            'plus'                  => [
                'active'     => $plusActive,
                'expires_at' => $plusActive && $user['premium_until'] !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($user['premium_until'] . ' UTC')) : null,
            ],
            'points'                => (int) ($user['points'] ?? 0),
            'created_at'            => gmdate('Y-m-d\TH:i:s\Z', strtotime($user['created_at'] . ' UTC')),
        ];
    }

    /** Auteur d'un contenu (spec : { id, displayName, avatar }) ; compte supprimé => « Compte supprimé ». */
    public static function presentAuthor(?array $user): array
    {
        if ($user === null || ($user['status'] ?? 'active') === 'deleted') {
            return ['id' => null, 'display_name' => 'Compte supprimé', 'avatar' => ['kind' => 'symbol', 'value' => 'person.crop.circle.fill']];
        }

        return [
            'id'           => $user['uid'],
            'display_name' => $user['display_name'] ?? $user['username'],
            'avatar'       => ($user['avatar_kind'] ?? 'symbol') === 'photo'
                ? ['kind' => 'photo', 'url' => $user['avatar_value']]
                : ['kind' => 'symbol', 'value' => $user['avatar_value'] ?? 'person.crop.circle.fill'],
        ];
    }

    /**
     * @param list<array> $badges badges obtenus (GamificationRepository::badgesOf)
     * @param bool $private true = profil de l'utilisateur lui-même (e-mail, code parrainage)
     */
    public static function present(array $user, array $badges = [], bool $private = false): array
    {
        $data = [
            'id'           => $user['uid'],
            'username'     => $user['username'],
            'bio'          => $user['bio'],
            'neighborhood' => $user['neighborhood_id'] === null ? null : [
                'id'   => (int) $user['neighborhood_id'],
                'slug' => $user['neighborhood_slug'],
                'name' => $user['neighborhood_name'],
            ],
            'points'       => (int) $user['points'],
            'is_premium'   => PremiumService::isActive($user),
            'badges'       => $badges,
            'created_at'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($user['created_at'] . ' UTC')),
        ];

        if ($private) {
            $data['email'] = $user['email'];
            $data['referral_code'] = $user['referral_code'];
            $data['premium_until'] = $user['premium_until'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($user['premium_until'] . ' UTC'));
        }

        return $data;
    }
}
