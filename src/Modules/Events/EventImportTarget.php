<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Config;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import d'événements depuis une source externe (format : docs/IMPORTS.md › events).
 */
final class EventImportTarget implements ImportTarget
{
    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) $item['external_id'];
        $hash = ImportRunner::hash($item);

        $existing = Database::fetch(
            'SELECT id, content_hash FROM events WHERE source_id = :s AND external_id = :x',
            ['s' => $sourceId, 'x' => $externalId],
        );
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE events SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);
            return 'unchanged';
        }

        $data = $this->normalize($item);
        $data += ['hash' => $hash, 'synced' => $syncedAt, 'now' => gmdate('Y-m-d H:i:s')];

        return Database::transaction(function () use ($existing, $data, $sourceId, $externalId, $item, $autoPublish): string {
            if ($existing === null) {
                $data += [
                    'uid' => Crypto::uuid4(), 'source' => $sourceId, 'external_id' => $externalId,
                    'slug' => substr(self::slugify($data['title']), 0, 80) . '-' . substr(sha1($sourceId . ':' . $externalId), 0, 8),
                    'status' => $autoPublish ? 'published' : 'draft',
                ];
                $id = Database::insert(
                    'INSERT INTO events (uid, source_id, external_id, external_url, content_hash, last_synced_at, slug, category_id, title,
                                         description, image_url, image_caption, starts_at, ends_at, venue_name, city, address, latitude,
                                         longitude, price_label, price_min_cents, ticket_url, tags, notice, status, created_at, updated_at)
                     VALUES (:uid, :source, :external_id, :external_url, :hash, :synced, :slug, :category_id, :title, :description,
                             :image_url, :image_caption, :starts_at, :ends_at, :venue_name, :city, :address, :latitude, :longitude,
                             :price_label, :price_min_cents, :ticket_url, :tags, :notice, :status, :now, :now2)',
                    $data + ['now2' => $data['now']],
                );
                $result = 'created';
            } else {
                $id = (int) $existing['id'];
                unset($data['now']);
                Database::execute(
                    'UPDATE events SET external_url = :external_url, content_hash = :hash, last_synced_at = :synced, category_id = :category_id,
                            title = :title, description = :description, image_url = :image_url, image_caption = :image_caption,
                            starts_at = :starts_at, ends_at = :ends_at, venue_name = :venue_name, city = :city, address = :address,
                            latitude = :latitude, longitude = :longitude, price_label = :price_label, price_min_cents = :price_min_cents,
                            ticket_url = :ticket_url, tags = :tags, notice = :notice, updated_at = :updated,
                            status = IF(status = \'cancelled\', \'published\', status)
                     WHERE id = :id',
                    $data + ['updated' => gmdate('Y-m-d H:i:s'), 'id' => $id],
                );
                Database::execute('DELETE FROM event_schedule_items WHERE event_id = :e', ['e' => $id]);
                $result = 'updated';
            }

            foreach (array_values((array) ($item['programme'] ?? [])) as $pos => $step) {
                if (isset($step['time'], $step['label']) && preg_match('/^\d{2}:\d{2}$/', (string) $step['time'])) {
                    Database::execute(
                        'INSERT INTO event_schedule_items (event_id, time, label, position) VALUES (:e, :t, :l, :p)',
                        ['e' => $id, 't' => $step['time'], 'l' => mb_substr((string) $step['label'], 0, 160), 'p' => $pos],
                    );
                }
            }
            (new EventRepository())->forgetCounts();

            return $result;
        });
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        // Annulé plutôt que supprimé : les favoris et participations restent cohérents
        return Database::execute(
            'UPDATE events SET status = \'cancelled\', updated_at = :now
             WHERE source_id = :s AND status = \'published\' AND last_synced_at < :d AND ends_at >= :now2',
            ['s' => $sourceId, 'd' => $syncedBefore, 'now' => gmdate('Y-m-d H:i:s'), 'now2' => gmdate('Y-m-d H:i:s')],
        );
    }

    private function normalize(array $item): array
    {
        foreach (['title', 'category', 'starts_at', 'venue_name'] as $required) {
            if (empty($item[$required]) || !is_scalar($item[$required])) {
                throw new \InvalidArgumentException("champ {$required} manquant");
            }
        }

        $category = Database::fetch('SELECT id FROM event_categories WHERE slug = :s', ['s' => $item['category']])
            ?? throw new \InvalidArgumentException('catégorie inconnue : ' . $item['category']);

        $tz = new \DateTimeZone((string) Config::get('events.timezone', 'Europe/Paris'));
        $toUtc = static function (string $value) use ($tz): string {
            try {
                return (new \DateTimeImmutable($value, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                throw new \InvalidArgumentException('date invalide : ' . $value);
            }
        };
        $starts = $toUtc((string) $item['starts_at']);
        $ends = isset($item['ends_at']) ? $toUtc((string) $item['ends_at']) : gmdate('Y-m-d H:i:s', strtotime($starts . ' UTC') + 7200);
        if ($ends < $starts) {
            throw new \InvalidArgumentException('ends_at antérieur à starts_at');
        }

        $str = static fn (string $k, int $max) => isset($item[$k]) && is_scalar($item[$k]) && $item[$k] !== '' ? mb_substr((string) $item[$k], 0, $max) : null;
        $url = static fn (string $k) => isset($item[$k]) && filter_var($item[$k], FILTER_VALIDATE_URL) && str_starts_with((string) $item[$k], 'http') ? (string) $item[$k] : null;

        return [
            'category_id'     => (int) $category['id'],
            'title'           => mb_substr((string) $item['title'], 0, 160),
            'description'     => $str('description', 10000),
            'image_url'       => $url('image_url'),
            'image_caption'   => $str('image_caption', 120),
            'starts_at'       => $starts,
            'ends_at'         => $ends,
            'venue_name'      => mb_substr((string) $item['venue_name'], 0, 160),
            'city'            => $str('city', 100) ?? 'Clermont-Ferrand',
            'address'         => $str('address', 255),
            'latitude'        => isset($item['latitude']) && is_numeric($item['latitude']) ? (string) $item['latitude'] : null,
            'longitude'       => isset($item['longitude']) && is_numeric($item['longitude']) ? (string) $item['longitude'] : null,
            'price_label'     => $str('price_label', 80),
            'price_min_cents' => isset($item['price_min_cents']) && is_int($item['price_min_cents']) ? max(0, $item['price_min_cents']) : null,
            'ticket_url'      => $url('ticket_url'),
            'external_url'    => $url('url'),
            'tags'            => isset($item['tags']) && is_array($item['tags']) ? mb_substr(implode(',', array_filter($item['tags'], 'is_string')), 0, 255) : null,
            'notice'          => $str('notice', 255),
        ];
    }

    public static function slugify(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-') ?: 'element';
    }
}
