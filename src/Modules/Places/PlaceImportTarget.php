<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import de lieux (format : docs/IMPORTS.md › places) : partenaires, office de tourisme, données ouvertes.
 */
final class PlaceImportTarget implements ImportTarget
{
    private const COLUMNS = ['external_url', 'category_id', 'name', 'tagline', 'description', 'cover_url', 'latitude', 'longitude', 'address',
                             'postal_code', 'city', 'hours_label', 'opening_hours', 'access_label', 'parking_label', 'price_label', 'phone',
                             'website_url', 'facts', 'highlights', 'timeline', 'tip', 'sources_label', 'tags', 'keywords', 'is_partner', 'is_must_see'];

    private bool $changed = false;

    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) $item['external_id'];
        $hash = ImportRunner::hash($item);
        $existing = Database::fetch('SELECT id, content_hash FROM places WHERE source_id = :s AND external_id = :x', ['s' => $sourceId, 'x' => $externalId]);
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE places SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);
            return 'unchanged';
        }

        $data = $this->normalize($item) + ['hash' => $hash, 'synced' => $syncedAt];
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            Database::execute(
                'INSERT INTO places (uid, source_id, external_id, content_hash, last_synced_at, status, created_at, updated_at, ' . implode(', ', self::COLUMNS)
                . ') VALUES (:uid, :src, :xid, :hash, :synced, :status, :c, :u, ' . implode(', ', array_map(static fn ($c) => ':' . $c, self::COLUMNS)) . ')',
                $data + ['uid' => Crypto::uuid4(), 'src' => $sourceId, 'xid' => $externalId, 'status' => $autoPublish ? 'published' : 'draft', 'c' => $now, 'u' => $now],
            );
            $result = 'created';
        } else {
            Database::execute(
                'UPDATE places SET content_hash = :hash, last_synced_at = :synced, updated_at = :u, '
                . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", self::COLUMNS)) . ", status = IF(status = 'archived', 'published', status) WHERE id = :id",
                $data + ['u' => $now, 'id' => $existing['id']],
            );
            $result = 'updated';
        }
        if (!$this->changed) {
            $this->changed = true;
            register_shutdown_function(static fn () => (new PlaceRepository())->forgetPins());
        }

        return $result;
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        $n = Database::execute(
            "UPDATE places SET status = 'archived', updated_at = :now WHERE source_id = :s AND status = 'published' AND last_synced_at < :d",
            ['s' => $sourceId, 'd' => $syncedBefore, 'now' => gmdate('Y-m-d H:i:s')],
        );
        (new PlaceRepository())->forgetPins();

        return $n;
    }

    private function normalize(array $item): array
    {
        foreach (['name', 'category'] as $required) {
            if (empty($item[$required]) || !is_string($item[$required])) {
                throw new \InvalidArgumentException("champ {$required} manquant");
            }
        }
        if (!isset($item['latitude'], $item['longitude']) || !is_numeric($item['latitude']) || !is_numeric($item['longitude'])) {
            throw new \InvalidArgumentException('coordonnées manquantes');
        }
        $category = Database::fetch('SELECT id FROM place_categories WHERE slug = :s', ['s' => $item['category']])
            ?? throw new \InvalidArgumentException('catégorie inconnue : ' . $item['category']);

        $str = static fn (string $k, int $max) => isset($item[$k]) && is_scalar($item[$k]) && $item[$k] !== '' ? mb_substr((string) $item[$k], 0, $max) : null;
        $url = static fn (string $k) => isset($item[$k]) && filter_var($item[$k], FILTER_VALIDATE_URL) && str_starts_with((string) $item[$k], 'https://') ? (string) $item[$k] : null;
        $list = static fn (string $k) => isset($item[$k]) && is_array($item[$k]) && $item[$k] !== [] ? json_encode(array_slice($item[$k], 0, 20), JSON_UNESCAPED_UNICODE) : null;

        return [
            'external_url'  => $url('url'),
            'category_id'   => (int) $category['id'],
            'name'          => mb_substr($item['name'], 0, 120),
            'tagline'       => $str('tagline', 160),
            'description'   => $str('description', 10000),
            'cover_url'     => $url('cover_url'),
            'latitude'      => (string) round((float) $item['latitude'], 6),
            'longitude'     => (string) round((float) $item['longitude'], 6),
            'address'       => $str('address', 200),
            'postal_code'   => isset($item['postal_code']) && preg_match('/^\d{5}$/', (string) $item['postal_code']) ? (string) $item['postal_code'] : null,
            'city'          => $str('city', 100) ?? 'Clermont-Ferrand',
            'hours_label'   => $str('hours', 120),
            'opening_hours' => isset($item['opening_hours']) && is_array($item['opening_hours']) ? json_encode($item['opening_hours']) : null,
            'access_label'  => $str('access', 160),
            'parking_label' => $str('parking', 160),
            'price_label'   => $str('price_label', 80),
            'phone'         => $str('phone', 30),
            'website_url'   => $url('website_url'),
            'facts'         => $list('facts'),
            'highlights'    => $list('highlights'),
            'timeline'      => $list('timeline'),
            'tip'           => $str('tip', 400),
            'sources_label' => $str('sources', 300),
            'tags'          => isset($item['tags']) && is_array($item['tags']) ? mb_substr(implode(',', array_filter($item['tags'], 'is_string')), 0, 255) : null,
            'keywords'      => isset($item['keywords']) && is_array($item['keywords']) ? mb_substr(implode(' ', array_filter($item['keywords'], 'is_string')), 0, 500) : null,
            'is_partner'    => !empty($item['is_partner']) ? 1 : 0,
            'is_must_see'   => !empty($item['is_must_see']) ? 1 : 0,
        ];
    }
}
