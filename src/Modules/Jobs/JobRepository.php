<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Cache\Cache;
use App\Content\ContentSources;
use App\Core\Config;
use App\Core\Database;

final class JobRepository
{
    private const COLUMNS = 'j.id, j.uid, j.external_url, j.application_url, j.contract, j.work_mode, j.title, j.company_name,
                             j.company_logo_url, j.summary, j.description, j.missions, j.profile, j.city, j.neighborhood_id,
                             j.latitude, j.longitude, j.salary_min_cents, j.salary_max_cents, j.salary_period, j.salary_label,
                             j.start_date, j.duration_label, j.badge_label, j.image_caption, j.notice, j.cover_letter_mode,
                             j.status, j.published_at, j.expires_at,
                             c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon, '
                          . ContentSources::SELECT;

    private const FROM = 'job_offers j JOIN job_categories c ON c.id = j.category_id JOIN content_sources s ON s.id = j.source_id';

    /** Offre visible : publiée et non expirée. */
    private const VISIBLE = "j.status = 'published' AND (j.expires_at IS NULL OR j.expires_at > :now_v)";

    /**
     * @param array{contract?: ?string, category?: ?string, work_mode?: ?string, q?: ?string, city?: ?string, min_salary_cents?: ?int} $f
     * @param array{0: string, 1: int}|null $after curseur [published_at, id]
     */
    public function search(array $f, int $limit, ?array $after): array
    {
        [$where, $params] = $this->where($f);
        if ($after !== null) {
            $where .= ' AND (j.published_at < :after_d OR (j.published_at = :after_d2 AND j.id < :after_id))';
            $params += ['after_d' => $after[0], 'after_d2' => $after[0], 'after_id' => $after[1]];
        }

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE {$where} ORDER BY j.published_at DESC, j.id DESC LIMIT :limit",
            $params + ['limit' => $limit],
        );
    }

    public function count(array $f): int
    {
        [$where, $params] = $this->where($f);

        return (int) Database::fetch('SELECT COUNT(*) AS n FROM ' . self::FROM . " WHERE {$where}", $params)['n'];
    }

    /** « Tous 4 · CDI 2 · CDD 1 · Alternance 1 » */
    public function contractCounts(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('jobs:counts'))) {
            return $cached;
        }

        $counts = array_fill_keys(array_keys((array) Config::get('jobs.contracts')), 0);
        foreach (Database::fetchAll('SELECT j.contract, COUNT(*) AS n FROM job_offers j WHERE ' . self::VISIBLE . ' GROUP BY j.contract', ['now_v' => gmdate('Y-m-d H:i:s')]) as $row) {
            $counts[$row['contract']] = (int) $row['n'];
        }

        if (Cache::isInMemory()) {
            Cache::store()->set('jobs:counts', $counts, (int) Config::get('jobs.counts_cache_ttl', 60));
        }

        return $counts;
    }

    public function categories(): array
    {
        return Database::fetchAll('SELECT slug, name, icon FROM job_categories ORDER BY sort_order');
    }

    /** Offre consultable (publiée ou archivée : elle reste visible depuis les favoris / candidatures). */
    public function find(string $uid, bool $withContact = false): ?array
    {
        return Database::fetch(
            'SELECT ' . self::COLUMNS . ($withContact ? ', j.contact_email' : '') . ' FROM ' . self::FROM
            . " WHERE j.uid = :u AND j.status IN ('published', 'archived')",
            ['u' => strtolower($uid)],
        );
    }

    public function findManyByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($uids) as $i => $uid) {
            $placeholders[] = ":u{$i}";
            $params["u{$i}"] = $uid;
        }

        return array_column(
            Database::fetchAll('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE j.uid IN (' . implode(', ', $placeholders) . ") AND j.status <> 'draft'", $params),
            null,
            'uid',
        );
    }

    public function images(int $offerId): array
    {
        return array_column(Database::fetchAll('SELECT url FROM job_images WHERE offer_id = :o ORDER BY position', ['o' => $offerId]), 'url');
    }

    /** Archive les offres dont la date d'expiration est passée (cron). */
    public function archiveExpired(): int
    {
        $n = Database::execute(
            "UPDATE job_offers SET status = 'archived', updated_at = :now WHERE status = 'published' AND expires_at IS NOT NULL AND expires_at <= :now2",
            ['now' => gmdate('Y-m-d H:i:s'), 'now2' => gmdate('Y-m-d H:i:s')],
        );
        $this->forgetCounts();

        return $n;
    }

    public function forgetCounts(): void
    {
        if (Cache::isInMemory()) {
            Cache::store()->delete('jobs:counts');
        }
    }

    /** @return array{0: string, 1: array} */
    private function where(array $f): array
    {
        $where = self::VISIBLE;
        $params = ['now_v' => gmdate('Y-m-d H:i:s')];

        foreach (['contract' => 'j.contract', 'work_mode' => 'j.work_mode', 'category' => 'c.slug'] as $key => $column) {
            if (!empty($f[$key])) {
                $where .= " AND {$column} = :{$key}";
                $params[$key] = $f[$key];
            }
        }
        if (!empty($f['city'])) {
            $where .= ' AND j.city = :city';
            $params['city'] = $f['city'];
        }
        if (!empty($f['min_salary_cents'])) {
            // Fourchette annuelle : on compare au maximum annoncé (ou au minimum si pas de maximum)
            $where .= " AND j.salary_period = 'year' AND COALESCE(j.salary_max_cents, j.salary_min_cents) >= :minsal";
            $params['minsal'] = $f['min_salary_cents'];
        }
        if (!empty($f['q'])) {
            // « Métier, entreprise, ville… » : chaque mot dans au moins un champ
            foreach (array_slice(preg_split('/\s+/', trim($f['q'])) ?: [], 0, 5) as $i => $word) {
                $like = '%' . addcslashes($word, '%_\\') . '%';
                $where .= " AND (j.title LIKE :q{$i}a OR j.company_name LIKE :q{$i}b OR j.city LIKE :q{$i}c OR j.summary LIKE :q{$i}d OR c.name LIKE :q{$i}e)";
                $params += ["q{$i}a" => $like, "q{$i}b" => $like, "q{$i}c" => $like, "q{$i}d" => $like, "q{$i}e" => $like];
            }
        }

        return [$where, $params];
    }
}
