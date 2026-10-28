<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import de fiches professionnels (format : docs/IMPORTS.md › services).
 */
final class ServiceImportTarget implements ImportTarget
{
    private const COLUMNS = ['external_url', 'category_id', 'name', 'summary', 'description', 'services', 'keywords', 'logo_url', 'cover_url',
                             'hours_label', 'price_label', 'city', 'latitude', 'longitude', 'service_radius_km', 'phone', 'website_url',
                             'display_rating', 'display_rating_label', 'notice', 'contact_email'];

    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) $item['external_id'];
        $hash = ImportRunner::hash($item);
        $existing = Database::fetch('SELECT id, content_hash FROM service_providers WHERE source_id = :s AND external_id = :x', ['s' => $sourceId, 'x' => $externalId]);
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE service_providers SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);
            return 'unchanged';
        }

        $data = $this->normalize($item) + ['hash' => $hash, 'synced' => $syncedAt];
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            Database::execute(
                'INSERT INTO service_providers (uid, source_id, external_id, content_hash, last_synced_at, status, published_at, created_at, updated_at, '
                . implode(', ', self::COLUMNS) . ') VALUES (:uid, :source, :xid, :hash, :synced, :status, :published, :c, :u, '
                . implode(', ', array_map(static fn ($c) => ':' . $c, self::COLUMNS)) . ')',
                $data + ['uid' => Crypto::uuid4(), 'source' => $sourceId, 'xid' => $externalId, 'status' => $autoPublish ? 'published' : 'draft',
                         'published' => $autoPublish ? $now : null, 'c' => $now, 'u' => $now],
            );
            $result = 'created';
        } else {
            Database::execute(
                'UPDATE service_providers SET content_hash = :hash, last_synced_at = :synced, updated_at = :u, '
                . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", self::COLUMNS))
                . ", status = IF(status = 'archived', 'published', status) WHERE id = :id",
                $data + ['u' => $now, 'id' => $existing['id']],
            );
            $result = 'updated';
        }
        (new ServiceRepository())->forgetCounts();

        return $result;
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        $n = Database::execute(
            "UPDATE service_providers SET status = 'archived', updated_at = :now WHERE source_id = :s AND status = 'published' AND last_synced_at < :d",
            ['s' => $sourceId, 'd' => $syncedBefore, 'now' => gmdate('Y-m-d H:i:s')],
        );
        (new ServiceRepository())->forgetCounts();

        return $n;
    }

    private function normalize(array $item): array
    {
        if (empty($item['name']) || !is_string($item['name'])) {
            throw new \InvalidArgumentException('champ name manquant');
        }
        $category = Database::fetch('SELECT id FROM service_categories WHERE slug = :s', ['s' => $item['category'] ?? ''])
            ?? throw new \InvalidArgumentException('catégorie inconnue : ' . ($item['category'] ?? '(vide)'));

        $str = static fn (string $k, int $max) => isset($item[$k]) && is_scalar($item[$k]) && $item[$k] !== '' ? mb_substr((string) $item[$k], 0, $max) : null;
        $num = static fn (string $k) => isset($item[$k]) && is_numeric($item[$k]) ? (string) $item[$k] : null;
        $url = static fn (string $k) => isset($item[$k]) && filter_var($item[$k], FILTER_VALIDATE_URL) && str_starts_with((string) $item[$k], 'https://') ? (string) $item[$k] : null;
        $services = array_values(array_filter((array) ($item['services'] ?? []), 'is_string'));
        $rating = isset($item['rating']) && is_numeric($item['rating']) && $item['rating'] >= 0 && $item['rating'] <= 5 ? (string) round((float) $item['rating'], 1) : null;

        return [
            'external_url'         => $url('url'),
            'category_id'          => (int) $category['id'],
            'name'                 => mb_substr($item['name'], 0, 160),
            'summary'              => $str('summary', 300),
            'description'          => $str('description', 10000),
            'services'             => $services ? json_encode(array_slice($services, 0, 30), JSON_UNESCAPED_UNICODE) : null,
            'keywords'             => isset($item['keywords']) && is_array($item['keywords']) ? mb_substr(implode(' ', array_filter($item['keywords'], 'is_string')), 0, 500) : null,
            'logo_url'             => $url('logo_url'),
            'cover_url'            => $url('cover_url'),
            'hours_label'          => $str('hours', 120),
            'price_label'          => $str('price_label', 80),
            'city'                 => $str('city', 100) ?? 'Clermont-Ferrand',
            'latitude'             => $num('latitude'),
            'longitude'            => $num('longitude'),
            'service_radius_km'    => $num('service_radius_km'),
            'phone'                => $str('phone', 30),
            'website_url'          => $url('website_url'),
            // Note de la source externe : affichée comme « indicative », jamais comme avis vérifiés myClermont
            'display_rating'       => $rating,
            'display_rating_label' => $rating !== null ? $str('rating_label', 60) ?? 'note de la source' : null,
            'notice'               => $str('notice', 255),
            'contact_email'        => isset($item['contact_email']) && filter_var($item['contact_email'], FILTER_VALIDATE_EMAIL) ? (string) $item['contact_email'] : null,
        ];
    }
}
