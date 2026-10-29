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
 * Favoris selon la spec v1 (› Compte : favoris, avis, My Clermont+).
 *
 *   GET    /me/favorites                               { items: [{ targetType, targetId, createdAt, preview: { title, subtitle, icon } }] }
 *   PUT    /me/favorites/{targetType}/{targetId}       204 (idempotent)
 *   DELETE /me/favorites/{targetType}/{targetId}       204
 *   POST   /me/favorites/import   keys[]               { imported, skipped }  (clés locales de l'app : spot-<id>, place-<nom>…)
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

        $items = [];
        foreach ($rows as $r) {
            $summary = $found[$r['type']][$r['target_id']]['summary'] ?? null;
            if ($summary === null || !in_array($r['type'], self::TYPES, true)) {
                continue; // élément supprimé ou type hors spec (repris par les anciennes routes /users/me/favorites)
            }
            $items[] = [
                'target_type' => $r['type'],
                'target_id'   => $r['target_id'],
                'created_at'  => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'] . ' UTC')),
                'preview'     => self::preview($r['type'], $summary),
            ];
        }

        return Response::success($items);
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

    /** Favoris enregistrés sur le téléphone avant la connexion : convertis en (targetType, targetId). */
    public function import(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['keys' => 'required|array|max:500']);
        $userId = (int) $request->attributes['user_id'];
        $imported = 0;
        $skipped = 0;
        foreach ($data['keys'] as $key) {
            $target = is_string($key) ? self::resolve($key) : null;
            if ($target === null) {
                $skipped++;
                continue;
            }
            (new FavoriteRepository())->add($userId, $target[0], $target[1]) ? $imported++ : $skipped++;
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
        if (!preg_match('/^([a-z]+)-(.+)$/', $key, $m)) {
            return null;
        }
        [$prefix, $value] = [$m[1], mb_substr($m[2], 0, 160)];
        if (in_array($prefix, ['spot', 'place'], true)) {
            $row = Database::fetch(
                "SELECT uid FROM places WHERE status = 'published' AND deleted_at IS NULL AND (uid = :a OR slug = :b OR demo_name = :c OR name = :d) LIMIT 1",
                ['a' => strtolower($value), 'b' => strtolower($value), 'c' => $value, 'd' => $value],
            );
            return $row ? ['place', $row['uid']] : null;
        }
        if (!in_array($prefix, self::TYPES, true) || strlen($value) > 64) {
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
