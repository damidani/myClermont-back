<?php

declare(strict_types=1);

namespace App\Modules\City;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Database;
use App\Security\Crypto;

/** Import d'associations (JOAFE) : upsert par numéro RNA */
final class AssociationImportTarget implements ImportTarget
{
    private const COLUMNS = ['name', 'category', 'district', 'description', 'activities', 'website_url', 'created_on'];

    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) ($item['external_id'] ?? '');
        if ($externalId === '' || empty($item['name'])) {
            throw new \InvalidArgumentException('numéro RNA ou nom manquant');
        }
        $hash = ImportRunner::hash($item);
        $existing = Database::fetch('SELECT id, content_hash FROM associations WHERE source_id = :s AND external_id = :x', ['s' => $sourceId, 'x' => $externalId]);
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE associations SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);

            return 'unchanged';
        }
        $data = [
            'name' => mb_substr((string) $item['name'], 0, 200), 'category' => mb_substr((string) ($item['category'] ?? 'Vie locale'), 0, 40),
            'district' => isset($item['district']) ? mb_substr((string) $item['district'], 0, 100) : null,
            'description' => isset($item['description']) ? mb_substr((string) $item['description'], 0, 5000) : null,
            'activities' => json_encode(array_values(array_filter((array) ($item['activities'] ?? []), 'is_string')), JSON_UNESCAPED_UNICODE),
            'website_url' => $item['website_url'] ?? null, 'created_on' => $item['created_on'] ?? null,
        ] + ['hash' => $hash, 'synced' => $syncedAt];
        $now = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            Database::execute('INSERT INTO associations (uid, source_id, external_id, content_hash, last_synced_at, status, created_at, updated_at, ' . implode(', ', self::COLUMNS)
                . ') VALUES (:uid, :src, :xid, :hash, :synced, :status, :c, :u, ' . implode(', ', array_map(static fn ($c) => ':' . $c, self::COLUMNS)) . ')',
                $data + ['uid' => Crypto::uuid4(), 'src' => $sourceId, 'xid' => $externalId, 'status' => $autoPublish ? 'published' : 'draft', 'c' => $now, 'u' => $now]);

            return 'created';
        }
        Database::execute('UPDATE associations SET content_hash = :hash, last_synced_at = :synced, updated_at = :u, ' . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", self::COLUMNS))
            . " , status = IF(status = 'archived', 'published', status) WHERE id = :id", $data + ['u' => $now, 'id' => $existing['id']]);

        return 'updated';
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        return Database::execute("UPDATE associations SET status = 'archived', updated_at = UTC_TIMESTAMP() WHERE source_id = :s AND status = 'published' AND last_synced_at < :d",
            ['s' => $sourceId, 'd' => $syncedBefore]);
    }
}
