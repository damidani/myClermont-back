<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Content\Import\ImportRunner;
use App\Content\Import\ImportTarget;
use App\Core\Config;
use App\Core\Database;
use App\Security\Crypto;

/**
 * Import d'offres d'emploi (format : docs/IMPORTS.md › jobs).
 */
final class JobImportTarget implements ImportTarget
{
    private const COLUMNS = ['external_url', 'application_url', 'category_id', 'contract', 'work_mode', 'title', 'company_name',
                             'company_logo_url', 'summary', 'description', 'missions', 'profile', 'city', 'latitude', 'longitude',
                             'salary_min_cents', 'salary_max_cents', 'salary_period', 'salary_label', 'start_date', 'duration_label',
                             'image_caption', 'notice', 'cover_letter_mode', 'contact_email', 'expires_at'];

    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string
    {
        $externalId = (string) $item['external_id'];
        $hash = ImportRunner::hash($item);
        $existing = Database::fetch('SELECT id, content_hash FROM job_offers WHERE source_id = :s AND external_id = :x', ['s' => $sourceId, 'x' => $externalId]);
        if ($existing !== null && $existing['content_hash'] === $hash) {
            Database::execute('UPDATE job_offers SET last_synced_at = :d WHERE id = :id', ['d' => $syncedAt, 'id' => $existing['id']]);
            return 'unchanged';
        }

        $data = $this->normalize($item) + ['hash' => $hash, 'synced' => $syncedAt];
        $images = array_slice(array_values(array_filter((array) ($item['images'] ?? []), static fn ($u) => is_string($u) && str_starts_with($u, 'https://'))), 0, 10);
        $now = gmdate('Y-m-d H:i:s');

        return Database::transaction(function () use ($existing, $data, $sourceId, $externalId, $autoPublish, $images, $now): string {
            if ($existing === null) {
                $id = Database::insert(
                    'INSERT INTO job_offers (uid, source_id, external_id, content_hash, last_synced_at, status, published_at, created_at, updated_at, '
                    . implode(', ', self::COLUMNS) . ') VALUES (:uid, :source, :xid, :hash, :synced, :status, :published, :c, :u, '
                    . implode(', ', array_map(static fn ($c) => ':' . $c, self::COLUMNS)) . ')',
                    $data + [
                        'uid' => Crypto::uuid4(), 'source' => $sourceId, 'xid' => $externalId, 'status' => $autoPublish ? 'published' : 'draft',
                        'published' => $autoPublish ? $now : null, 'c' => $now, 'u' => $now,
                    ],
                );
                $result = 'created';
            } else {
                $id = (int) $existing['id'];
                Database::execute(
                    'UPDATE job_offers SET content_hash = :hash, last_synced_at = :synced, updated_at = :u, '
                    . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", self::COLUMNS))
                    . ", status = IF(status = 'archived', 'published', status) WHERE id = :id",
                    $data + ['u' => $now, 'id' => $id],
                );
                Database::execute('DELETE FROM job_images WHERE offer_id = :o', ['o' => $id]);
                $result = 'updated';
            }
            foreach ($images as $pos => $url) {
                Database::execute('INSERT INTO job_images (offer_id, url, position) VALUES (:o, :u, :p)', ['o' => $id, 'u' => mb_substr($url, 0, 500), 'p' => $pos]);
            }
            (new JobRepository())->forgetCounts();

            return $result;
        });
    }

    public function unpublishMissing(int $sourceId, string $syncedBefore): int
    {
        $n = Database::execute(
            "UPDATE job_offers SET status = 'archived', updated_at = :now WHERE source_id = :s AND status = 'published' AND last_synced_at < :d",
            ['s' => $sourceId, 'd' => $syncedBefore, 'now' => gmdate('Y-m-d H:i:s')],
        );
        (new JobRepository())->forgetCounts();

        return $n;
    }

    private function normalize(array $item): array
    {
        foreach (['title', 'company_name', 'contract'] as $required) {
            if (empty($item[$required]) || !is_string($item[$required])) {
                throw new \InvalidArgumentException("champ {$required} manquant");
            }
        }
        if (!array_key_exists($item['contract'], (array) Config::get('jobs.contracts'))) {
            throw new \InvalidArgumentException('contrat inconnu : ' . $item['contract']);
        }

        $category = Database::fetch('SELECT id FROM job_categories WHERE slug = :s', ['s' => $item['category'] ?? 'autre'])
            ?? Database::fetch("SELECT id FROM job_categories WHERE slug = 'autre'");
        $str = static fn (string $k, int $max) => isset($item[$k]) && is_scalar($item[$k]) && $item[$k] !== '' ? mb_substr((string) $item[$k], 0, $max) : null;
        $int = static fn (string $k) => isset($item[$k]) && is_int($item[$k]) && $item[$k] >= 0 ? $item[$k] : null;
        $url = static fn (string $k) => isset($item[$k]) && filter_var($item[$k], FILTER_VALIDATE_URL) && str_starts_with((string) $item[$k], 'https://') ? (string) $item[$k] : null;
        $date = static fn (string $k) => isset($item[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $item[$k]) ? (string) $item[$k] : null;
        $missions = array_values(array_filter((array) ($item['missions'] ?? []), 'is_string'));

        return [
            'external_url'      => $url('url'),
            'application_url'   => $url('application_url'),
            'category_id'       => (int) $category['id'],
            'contract'          => $item['contract'],
            'work_mode'         => in_array($item['work_mode'] ?? null, ['onsite', 'hybrid', 'remote'], true) ? $item['work_mode'] : 'onsite',
            'title'             => mb_substr($item['title'], 0, 160),
            'company_name'      => mb_substr($item['company_name'], 0, 160),
            'company_logo_url'  => $url('company_logo_url'),
            'summary'           => $str('summary', 300),
            'description'       => $str('description', 20000),
            'missions'          => $missions ? json_encode(array_slice($missions, 0, 20), JSON_UNESCAPED_UNICODE) : null,
            'profile'           => $str('profile', 10000),
            'city'              => $str('city', 100) ?? 'Clermont-Ferrand',
            'latitude'          => isset($item['latitude']) && is_numeric($item['latitude']) ? (string) $item['latitude'] : null,
            'longitude'         => isset($item['longitude']) && is_numeric($item['longitude']) ? (string) $item['longitude'] : null,
            'salary_min_cents'  => $int('salary_min_cents'),
            'salary_max_cents'  => $int('salary_max_cents'),
            'salary_period'     => in_array($item['salary_period'] ?? null, ['year', 'month', 'hour'], true) ? $item['salary_period'] : null,
            'salary_label'      => $str('salary_label', 80),
            'start_date'        => $date('start_date'),
            'duration_label'    => $str('duration', 60),
            'image_caption'     => $str('image_caption', 120),
            'notice'            => $str('notice', 255),
            'cover_letter_mode' => in_array($item['cover_letter'] ?? null, ['none', 'optional', 'required'], true) ? $item['cover_letter'] : 'optional',
            'contact_email'     => isset($item['contact_email']) && filter_var($item['contact_email'], FILTER_VALIDATE_EMAIL) ? (string) $item['contact_email'] : null,
            'expires_at'        => ($d = $date('expires_at')) !== null ? $d . ' 23:59:59' : null,
        ];
    }
}
