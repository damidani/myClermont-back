<?php

declare(strict_types=1);

namespace App\Modules\Favorites;

use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Support\Validator;

/**
 * Favoris rangés sous la clé texte de l'app (brief iOS §9), y compris pour un objet inconnu du back (lieu Apple Plans).
 *
 *   GET    /me/favorites                               { items: [{ key, createdAt, (targetType, targetId, preview) }], nextCursor: null }
 *   PUT    /me/favorites/{key}                         204, idempotent ({key} encodé RFC 3986, 200 caractères au plus)
 *   DELETE /me/favorites/{key}                         204, idempotent
 *   POST   /me/favorites/import   { keys: [] }         { imported, skipped }, doublons ignorés
 *   PUT | DELETE /me/favorites/{targetType}/{targetId} routes typées de la spec v1, gardées pour les autres clients
 * Une clé qui désigne un objet connu (spot-<uid>, event-<slug>…) crée aussi le favori typé : rappels et notifications.
 */
final class SpecFavoriteController
{
    public const TYPES = ['place', 'event', 'deal', 'job', 'housing', 'service', 'classified'];

    private const ICONS = ['place' => 'mappin.circle.fill', 'event' => 'calendar', 'deal' => 'tag.fill', 'job' => 'briefcase.fill',
                           'housing' => 'house.fill', 'service' => 'wrench.and.screwdriver.fill', 'classified' => 'cart.fill'];

    public function index(Request $request): Response
    {
        $userId = (int) $request->attributes['user_id'];
        $rows = Database::fetchAll(
            'SELECT type, target_id, created_at FROM favorites WHERE user_id = :u ORDER BY created_at DESC LIMIT 500',
            ['u' => $userId],
        );
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['type']][] = $r['target_id'];
        }
        $found = [];
        foreach ($byType as $type => $ids) {
            $found[$type] = FavoriteTypes::get($type)?->find($ids) ?? [];
        }

        $iso = static fn (string $d) => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($d . ' UTC'));
        $items = [];
        foreach (Database::fetchAll('SELECT fav_key, created_at FROM app_favorites WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 1000', ['u' => $userId]) as $f) {
            $items[$f['fav_key']] = ['key' => $f['fav_key'], 'created_at' => $iso($f['created_at'])];
        }
        foreach ($rows as $r) {
            $summary = $found[$r['type']][$r['target_id']]['summary'] ?? null;
            if ($summary === null || !in_array($r['type'], self::TYPES, true)) {
                continue; // élément supprimé ou type hors spec (repris par les anciennes routes /users/me/favorites)
            }
            $typed = ['target_type' => $r['type'], 'target_id' => $r['target_id'], 'preview' => self::preview($r['type'], $summary)];
            $key = self::keyOf($r['type'], $r['target_id']);
            foreach ($items as $k => $item) {
                if (self::resolveCached($k) === [$r['type'], $r['target_id']]) {
                    $key = $k;
                    break;
                }
            }
            $items[$key] = ($items[$key] ?? ['key' => $key, 'created_at' => $iso($r['created_at'])]) + $typed;
        }

        return Response::success(array_values($items), 200, ['next_cursor' => null]);
    }

    /** PUT /me/favorites/{key} — clé de l'app, enregistrée même si le back ne connaît pas l'objet */
    public function putKey(Request $request): Response
    {
        $key = self::key((string) $request->param('key'));
        $userId = (int) $request->attributes['user_id'];
        Database::execute('INSERT IGNORE INTO app_favorites (user_id, fav_key, created_at) VALUES (:u, :k, :d)', ['u' => $userId, 'k' => $key, 'd' => gmdate('Y-m-d H:i:s')]);
        if (($target = self::resolve($key)) !== null && (new FavoriteRepository())->add($userId, $target[0], $target[1])) {
            Events::dispatch('favorite.added', ['user_id' => $userId, 'type' => $target[0], 'target_id' => $target[1], 'tags' => []]);
        }

        return Response::noContent();
    }

    /** DELETE /me/favorites/{key} */
    public function deleteKey(Request $request): Response
    {
        $key = self::key((string) $request->param('key'));
        $userId = (int) $request->attributes['user_id'];
        Database::execute('DELETE FROM app_favorites WHERE user_id = :u AND fav_key = :k', ['u' => $userId, 'k' => $key]);
        $target = self::resolve($key);
        if ($target !== null && (new FavoriteRepository())->remove($userId, $target[0], $target[1])) {
            Events::dispatch('favorite.removed', ['user_id' => $userId, 'type' => $target[0], 'target_id' => $target[1]]);
        }

        return Response::noContent();
    }

    /** Clé reçue dans l'URL (encodée RFC 3986) : décodée, 1 à 200 caractères. */
    public static function key(string $raw): string
    {
        $key = rawurldecode($raw);
        if ($key === '' || mb_strlen($key) > 200 || !mb_check_encoding($key, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new ValidationException(['key' => 'Clé de favori invalide (200 caractères au plus).']);
        }

        return $key;
    }

    /** Clé de l'app d'un favori typé (spot-…, event-…, classified_…, sinon l'identifiant brut) */
    private static function keyOf(string $type, string $id): string
    {
        return match ($type) {
            'place'      => 'spot-' . $id,
            'event'      => 'event-' . $id,
            'classified' => 'classified_' . $id,
            default      => $id,
        };
    }

    private static function resolveCached(string $key): ?array
    {
        static $cache = [];

        return $cache[$key] ??= self::resolve($key) ?? [];
    }

    public function put(Request $request): Response
    {
        [$type, $id] = $this->target($request);
        $found = FavoriteTypes::get($type)?->find([$id]) ?? [];
        if (!isset($found[$id])) {
            throw new HttpException(404, 'favorite_target_not_found', 'Cet élément n\'existe pas ou n\'est plus en ligne.');
        }
        $userId = (int) $request->attributes['user_id'];
        if ((new FavoriteRepository())->add($userId, $type, $id)) {
            Events::dispatch('favorite.added', ['user_id' => $userId, 'type' => $type, 'target_id' => $id, 'tags' => $found[$id]['tags'] ?? []]);
        }

        return Response::noContent();
    }

    public function delete(Request $request): Response
    {
        [$type, $id] = $this->target($request);
        $userId = (int) $request->attributes['user_id'];
        if ((new FavoriteRepository())->remove($userId, $type, $id)) {
            Events::dispatch('favorite.removed', ['user_id' => $userId, 'type' => $type, 'target_id' => $id]);
        }

        return Response::noContent();
    }

    /** Favoris enregistrés sur le téléphone avant la connexion : gardés sous leur clé ; favori typé en plus si l'objet est connu. */
    public function import(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['keys' => 'required|array|max:500']);
        $userId = (int) $request->attributes['user_id'];
        $imported = 0;
        $skipped = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($data['keys'] as $key) {
            if (!is_string($key) || $key === '' || mb_strlen($key) > 200 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
                $skipped++;
                continue;
            }
            $n = Database::execute('INSERT IGNORE INTO app_favorites (user_id, fav_key, created_at) VALUES (:u, :k, :d)', ['u' => $userId, 'k' => $key, 'd' => $now]);
            $n === 1 ? $imported++ : $skipped++;
            if (($target = self::resolve($key)) !== null) {
                (new FavoriteRepository())->add($userId, $target[0], $target[1]);
            }
        }

        return Response::success(['imported' => $imported, 'skipped' => $skipped]);
    }

    /**
     * Clé locale de l'app → [type, id], ou null si l'élément n'existe pas côté serveur.
     *   spot-<id> | place-<id ou nom>  → lieu (uid, slug, nom de démo ou nom)
     *   event-<slug>                    → événement
     *   job-, housing-, service-, classified-, deal-<uid>
     */
    public static function resolve(string $key): ?array
    {
        if (preg_match('/^classified_([0-9a-f-]{36})$/i', $key, $m)) {
            return self::resolve('classified-' . strtolower($m[1]));
        }
        // Identifiant brut (offre d'emploi, bon plan, service) : on cherche dans ces types
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            foreach (['job', 'deal', 'service', 'housing'] as $type) {
                if (isset((FavoriteTypes::get($type)?->find([strtolower($key)]) ?? [])[strtolower($key)])) {
                    return [$type, strtolower($key)];
                }
            }

            return null;
        }
        if (!preg_match('/^([a-z]+)-(.+)$/', $key, $m)) {
            return null;
        }
        [$prefix, $value] = [$m[1], mb_substr($m[2], 0, 160)];
        if (in_array($prefix, ['spot', 'place'], true)) {
            // uid / slug sont en ASCII : un nom accentué ne se compare qu'aux noms
            $ascii = preg_match('/^[\x20-\x7E]+$/', $value) === 1;
            $row = Database::fetch(
                "SELECT uid FROM places WHERE status = 'published' AND deleted_at IS NULL AND (app_id = :e OR "
                . ($ascii ? 'uid = :a OR slug = :b OR ' : '') . 'demo_name = :c OR name = :d) ORDER BY app_id = :e2 DESC LIMIT 1',
                ($ascii ? ['a' => strtolower($value), 'b' => strtolower($value)] : []) + ['c' => $value, 'd' => $value, 'e' => $value, 'e2' => $value],
            );
            return $row ? ['place', $row['uid']] : null;
        }
        if (!in_array($prefix, self::TYPES, true) || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return null;
        }
        $found = FavoriteTypes::get($prefix)?->find([$value]) ?? [];

        return isset($found[$value]) ? [$prefix, $value] : null;
    }

    /** Aperçu pour la liste « Mes favoris » sans recharger chaque objet. */
    private static function preview(string $type, array $s): array
    {
        $subtitle = $s['subtitle'] ?? $s['tagline'] ?? $s['venue'] ?? ($s['company']['name'] ?? $s['company_name'] ?? null)
                 ?? $s['district'] ?? $s['location'] ?? $s['price_label'] ?? null;

        return [
            'title'    => (string) ($s['title'] ?? $s['name'] ?? ''),
            'subtitle' => is_scalar($subtitle) ? (string) $subtitle : null,
            'icon'     => self::ICONS[$type] ?? 'star.fill',
        ];
    }

    /** @return array{0: string, 1: string} */
    private function target(Request $request): array
    {
        $type = (string) $request->param('targetType');
        $id = (string) $request->param('targetId');
        if (!in_array($type, self::TYPES, true) || FavoriteTypes::get($type) === null) {
            throw new ValidationException(['target_type' => 'Type inconnu : ' . implode(', ', self::TYPES) . '.']);
        }
        if (strlen($id) > 64) {
            throw new HttpException(404, 'favorite_target_not_found', 'Cet élément n\'existe pas.');
        }

        return [$type, $id];
    }
}
