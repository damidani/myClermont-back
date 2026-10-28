<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Config;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import de petites annonces d'une source autorisée (ressourcerie, partenaire...) — format : docs/IMPORTS.md › classifieds.
 * Ces annonces n'ont pas de vendeur myClermont : « Contacter » renvoie vers l'annonce d'origine.
 */
final class ClassifiedImportTarget implements ImportTarget
{
    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) $item['external_id'];
        $hash = ImportRunner::hash($item);
        $existing = Database::fetch('SELECT id, content_hash FROM classifieds WHERE source_id = :s AND external_id = :x', ['s' => $sourceId, 'x' => $externalId]);
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE classifieds SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);
            return 'unchanged';
        }

        foreach (['title', 'category'] as $required) {
            if (empty($item[$required]) || !is_string($item[$required])) {
                throw new \InvalidArgumentException("champ {$required} manquant");
            }
        }
        $category = Database::fetch('SELECT id FROM classified_categories WHERE slug = :s', ['s' => $item['category']])
            ?? throw new \InvalidArgumentException('catégorie inconnue : ' . $item['category']);
        $isFree = !empty($item['is_free']) || (int) ($item['price_cents'] ?? 0) === 0;
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'cat'    => (int) $category['id'],
            'title'  => mb_substr($item['title'], 0, 100),
            'descr'  => isset($item['description']) ? mb_substr((string) $item['description'], 0, 4000) : null,
            'price'  => $isFree ? 0 : max(0, (int) ($item['price_cents'] ?? 0)),
            'free'   => $isFree ? 1 : 0,
            'cond'   => array_key_exists($item['condition'] ?? '', (array) Config::get('classifieds.conditions')) ? $item['condition'] : 'good',
            'loc'    => mb_substr((string) ($item['location'] ?? 'Clermont-Ferrand'), 0, 100),
            'url'    => isset($item['url']) && str_starts_with((string) $item['url'], 'https://') ? (string) $item['url'] : null,
            'hash'   => $hash,
            'synced' => $syncedAt,
            'exp'    => gmdate('Y-m-d H:i:s', time() + (int) Config::get('classifieds.duration_days', 60) * 86400),
        ];
        $images = array_slice(array_values(array_filter((array) ($item['images'] ?? []), static fn ($u) => is_string($u) && str_starts_with($u, 'https://'))), 0, 6);

        return Database::transaction(function () use ($existing, $data, $sourceId, $externalId, $autoPublish, $images, $now): string {
            if ($existing === null) {
                $id = Database::insert(
                    'INSERT INTO classifieds (uid, user_id, source_id, external_id, external_url, content_hash, last_synced_at, category_id, title, description,
                                              price_cents, is_free, item_condition, location_label, status, published_at, expires_at, created_at, updated_at)
                     VALUES (:uid, NULL, :src, :xid, :url, :hash, :synced, :cat, :title, :descr, :price, :free, :cond, :loc, :status, :p, :exp, :c, :u)',
                    $data + ['uid' => Crypto::uuid4(), 'src' => $sourceId, 'xid' => $externalId, 'status' => $autoPublish ? 'active' : 'hidden', 'p' => $now, 'c' => $now, 'u' => $now],
                );
                $result = 'created';
            } else {
                $id = (int) $existing['id'];
                Database::execute(
                    "UPDATE classifieds SET external_url = :url, content_hash = :hash, last_synced_at = :synced, category_id = :cat, title = :title,
                            description = :descr, price_cents = :price, is_free = :free, item_condition = :cond, location_label = :loc,
                            expires_at = :exp, updated_at = :u, status = IF(status = 'expired', 'active', status) WHERE id = :id",
                    $data + ['u' => $now, 'id' => $id],
                );
                Database::execute('DELETE FROM classified_media WHERE classified_id = :c', ['c' => $id]);
                $result = 'updated';
            }
            foreach ($images as $pos => $url) {
                Database::execute('INSERT INTO classified_media (classified_id, url, position) VALUES (:c, :u, :p)', ['c' => $id, 'u' => $url, 'p' => $pos]);
            }
            (new ClassifiedRepository())->forgetCounts();

            return $result;
        });
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        $n = Database::execute(
            "UPDATE classifieds SET status = 'expired' WHERE source_id = :s AND status IN ('active', 'reserved') AND last_synced_at < :d",
            ['s' => $sourceId, 'd' => $syncedBefore],
        );
        (new ClassifiedRepository())->forgetCounts();

        return $n;
    }
}
