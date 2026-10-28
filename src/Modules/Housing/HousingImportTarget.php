<?php

declare(strict_types=1);

namespace App\Modules\Housing;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Config;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import d'annonces depuis une source externe (format : docs/IMPORTS.md › housing).
 */
final class HousingImportTarget implements ImportTarget
{
    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) $item['external_id'];
        $hash = ImportRunner::hash($item);
        $existing = Database::fetch(
            'SELECT id, content_hash, status FROM housing_listings WHERE source_id = :s AND external_id = :x',
            ['s' => $sourceId, 'x' => $externalId],
        );
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE housing_listings SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);
            return 'unchanged';
        }

        $data = $this->normalize($item) + ['hash' => $hash, 'synced' => $syncedAt, 'now' => gmdate('Y-m-d H:i:s')];
        $images = array_slice(array_values(array_filter((array) ($item['images'] ?? []), static fn ($u) => is_string($u) && str_starts_with($u, 'https://'))), 0, 20);

        return Database::transaction(function () use ($existing, $data, $sourceId, $externalId, $autoPublish, $images): string {
            $columns = array_keys($this->normalize([], true));
            if ($existing === null) {
                $status = $autoPublish ? 'published' : 'draft';
                $id = Database::insert(
                    'INSERT INTO housing_listings (uid, source_id, external_id, content_hash, last_synced_at, status, published_at, created_at, updated_at, '
                    . implode(', ', $columns) . ') VALUES (:uid, :source, :external_id, :hash, :synced, :status, :published, :now, :now2, '
                    . implode(', ', array_map(static fn ($c) => ':' . $c, $columns)) . ')',
                    $data + [
                        'uid' => Crypto::uuid4(), 'source' => $sourceId, 'external_id' => $externalId, 'status' => $status,
                        'published' => $autoPublish ? $data['now'] : null, 'now2' => $data['now'],
                    ],
                );
                $result = 'created';
            } else {
                $id = (int) $existing['id'];
                $now = $data['now'];
                unset($data['now']);
                Database::execute(
                    'UPDATE housing_listings SET content_hash = :hash, last_synced_at = :synced, updated_at = :updated, '
                    . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", $columns))
                    // Une annonce réapparue dans le flux est republiée
                    . ", status = IF(status = 'archived', 'published', status) WHERE id = :id",
                    $data + ['updated' => $now, 'id' => $id],
                );
                Database::execute('DELETE FROM housing_images WHERE listing_id = :l', ['l' => $id]);
                $result = 'updated';
            }

            foreach ($images as $pos => $url) {
                Database::execute('INSERT INTO housing_images (listing_id, url, position) VALUES (:l, :u, :p)', ['l' => $id, 'u' => mb_substr($url, 0, 500), 'p' => $pos]);
            }
            (new HousingRepository())->forgetCounts();

            return $result;
        });
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        // Archivée (pas supprimée) : reste visible dans les favoris avec le bouton Contacter désactivé
        $n = Database::execute(
            'UPDATE housing_listings SET status = \'archived\', updated_at = :now WHERE source_id = :s AND status = \'published\' AND last_synced_at < :d',
            ['s' => $sourceId, 'd' => $syncedBefore, 'now' => gmdate('Y-m-d H:i:s')],
        );
        (new HousingRepository())->forgetCounts();

        return $n;
    }

    /**
     * @param bool $columnsOnly retourne seulement les clés (pour construire les requêtes)
     */
    private function normalize(array $item, bool $columnsOnly = false): array
    {
        $keys = ['external_url', 'transaction', 'property_type', 'title', 'description', 'price_cents', 'charges_cents',
                 'charges_included', 'surface_m2', 'rooms', 'bedrooms', 'furnished', 'floor', 'energy_class', 'available_from',
                 'features', 'district_label', 'city', 'postal_code', 'latitude', 'longitude', 'image_caption', 'notice',
                 'contact_name', 'contact_email', 'contact_phone'];
        if ($columnsOnly) {
            return array_fill_keys($keys, null);
        }

        foreach (['title', 'transaction', 'price_cents'] as $required) {
            if (!isset($item[$required]) || $item[$required] === '') {
                throw new \InvalidArgumentException("champ {$required} manquant");
            }
        }
        if (!array_key_exists($item['transaction'], (array) Config::get('housing.transactions'))) {
            throw new \InvalidArgumentException('transaction inconnue : ' . $item['transaction']);
        }
        if (!is_int($item['price_cents']) || $item['price_cents'] <= 0) {
            throw new \InvalidArgumentException('price_cents doit être un entier positif');
        }

        $str = static fn (string $k, int $max) => isset($item[$k]) && is_scalar($item[$k]) && $item[$k] !== '' ? mb_substr((string) $item[$k], 0, $max) : null;
        $int = static fn (string $k) => isset($item[$k]) && is_numeric($item[$k]) ? (int) $item[$k] : null;
        $num = static fn (string $k) => isset($item[$k]) && is_numeric($item[$k]) ? (string) $item[$k] : null;
        $type = $item['property_type'] ?? 'apartment';
        $features = array_intersect((array) ($item['features'] ?? []), array_keys((array) Config::get('housing.features')));
        $email = isset($item['contact_email']) && filter_var($item['contact_email'], FILTER_VALIDATE_EMAIL) ? (string) $item['contact_email'] : null;
        $url = isset($item['url']) && filter_var($item['url'], FILTER_VALIDATE_URL) && str_starts_with((string) $item['url'], 'https://') ? (string) $item['url'] : null;
        $energy = isset($item['energy_class']) && preg_match('/^[A-G]$/', (string) $item['energy_class']) ? (string) $item['energy_class'] : null;
        $available = isset($item['available_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $item['available_from']) ? (string) $item['available_from'] : null;

        return [
            'external_url'     => $url,
            'transaction'      => $item['transaction'],
            'property_type'    => array_key_exists($type, (array) Config::get('housing.property_types')) ? $type : 'other',
            'title'            => mb_substr((string) $item['title'], 0, 160),
            'description'      => $str('description', 10000),
            'price_cents'      => $item['price_cents'],
            'charges_cents'    => $int('charges_cents'),
            'charges_included' => isset($item['charges_included']) ? (int) (bool) $item['charges_included'] : null,
            'surface_m2'       => $num('surface_m2'),
            'rooms'            => $int('rooms'),
            'bedrooms'         => $int('bedrooms'),
            'furnished'        => !empty($item['furnished']) ? 1 : 0,
            'floor'            => $int('floor'),
            'energy_class'     => $energy,
            'available_from'   => $available,
            'features'         => $features ? implode(',', $features) : null,
            'district_label'   => $str('district', 100),
            'city'             => $str('city', 100) ?? 'Clermont-Ferrand',
            'postal_code'      => isset($item['postal_code']) && preg_match('/^\d{5}$/', (string) $item['postal_code']) ? (string) $item['postal_code'] : null,
            'latitude'         => $num('latitude'),
            'longitude'        => $num('longitude'),
            'image_caption'    => $str('image_caption', 120),
            'notice'           => $str('notice', 255),
            'contact_name'     => $str('contact_name', 120),
            'contact_email'    => $email,
            'contact_phone'    => $str('contact_phone', 30),
        ];
    }
}
