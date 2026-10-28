<?php

declare(strict_types=1);

namespace App\Modules\Account\Repositories;

use App\Cache\Cache;
use App\Core\Database;

final class NeighborhoodRepository
{
    /** Liste quasi statique : gardée 1 h en cache mémoire. */
    public function all(): array
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('neighborhoods'))) {
            return $cached;
        }

        $rows = array_map(
            static fn (array $r) => ['id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name']],
            Database::fetchAll('SELECT id, slug, name FROM neighborhoods WHERE is_active = 1 ORDER BY sort_order, name'),
        );

        if (Cache::isInMemory()) {
            Cache::store()->set('neighborhoods', $rows, 3600);
        }

        return $rows;
    }

    public function exists(int $id): bool
    {
        foreach ($this->all() as $n) {
            if ($n['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
