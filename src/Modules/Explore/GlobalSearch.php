<?php

declare(strict_types=1);

namespace App\Modules\Explore;

use App\Core\Config;
use App\Core\Database;

/**
 * Recherche « Un lieu, une sortie, une envie… » : quelques résultats par module, regroupés.
 *
 * Explore est par nature un agrégateur : c'est le seul module qui lit les tables des autres.
 * Un module désactivé dans config/modules.php est automatiquement ignoré.
 * Pour ajouter un module à la recherche : une entrée dans sources() (requête + mise en forme).
 */
final class GlobalSearch
{
    public function search(string $query, int $perType): array
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', trim($query)) ?: [], static fn ($w) => mb_strlen($w) >= 2)), 0, 4);
        if ($words === []) {
            return [];
        }

        $enabled = array_map(static fn (string $c) => substr(strrchr($c, '\\'), 1), (array) Config::get('modules', []));
        $groups = [];
        foreach ($this->sources() as $type => $source) {
            if (!in_array($source['module'], $enabled, true)) {
                continue;
            }
            [$where, $params] = self::likeAll($source['columns'], $words);
            $rows = Database::fetchAll($source['sql'] . " AND ({$where}) ORDER BY " . $source['order'] . ' LIMIT :limit', $params + ['limit' => $perType] + ($source['params'] ?? []));
            if ($rows !== []) {
                $groups[] = ['type' => $type, 'label' => $source['label'], 'items' => array_map($source['map'], $rows)];
            }
        }

        return $groups;
    }

    private function sources(): array
    {
        $now = ['snow' => gmdate('Y-m-d H:i:s')];
        $item = static fn (string $type, string $id, string $title, ?string $subtitle, string $icon) => [
            'id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'icon' => $icon, 'link' => ['type' => $type, 'id' => $id, 'url' => null],
        ];

        return [
            'event' => [
                'module' => 'EventsModule', 'label' => 'Agenda', 'columns' => ['e.title', 'e.venue_name', 'c.name'],
                'sql' => "SELECT e.uid, e.title, e.venue_name, e.starts_at FROM events e JOIN event_categories c ON c.id = e.category_id
                          WHERE e.status = 'published' AND e.ends_at >= :snow", 'params' => $now, 'order' => 'e.starts_at',
                'map' => static fn ($r) => $item('event', $r['uid'], $r['title'], $r['venue_name'], 'calendar'),
            ],
            'place' => [
                'module' => 'PlacesModule', 'label' => 'Lieux', 'columns' => ['p.name', 'c.name', 'p.keywords', 'p.address'],
                'sql' => "SELECT p.uid, p.name, c.name AS category, c.icon FROM places p JOIN place_categories c ON c.id = p.category_id
                          WHERE p.status = 'published'", 'order' => 'p.is_must_see DESC, p.rating_count DESC',
                'map' => static fn ($r) => $item('place', $r['uid'], $r['name'], $r['category'], $r['icon']),
            ],
            'offer' => [
                'module' => 'ExploreModule', 'label' => 'Bons plans', 'columns' => ['o.title', 'o.partner_name', 'o.description'],
                'sql' => "SELECT o.uid, o.title, o.partner_name, o.discount_label FROM offers o
                          WHERE o.status = 'published' AND (o.ends_at IS NULL OR o.ends_at > :snow)", 'params' => $now, 'order' => 'o.sort_order',
                'map' => static fn ($r) => $item('offer', $r['uid'], $r['title'], $r['partner_name'] . ' · ' . $r['discount_label'], 'tag'),
            ],
            'service' => [
                'module' => 'ServicesModule', 'label' => 'Services', 'columns' => ['sp.name', 'sp.keywords', 'c.name'],
                'sql' => "SELECT sp.uid, sp.name, c.name AS category FROM service_providers sp JOIN service_categories c ON c.id = sp.category_id
                          WHERE sp.status = 'published'", 'order' => 'sp.rating_count DESC',
                'map' => static fn ($r) => $item('service', $r['uid'], $r['name'], $r['category'], 'wrench'),
            ],
            'housing' => [
                'module' => 'HousingModule', 'label' => 'Logement', 'columns' => ['h.title', 'h.district_label', 'h.city'],
                'sql' => "SELECT h.uid, h.title, h.price_cents, h.transaction FROM housing_listings h WHERE h.status = 'published'", 'order' => 'h.published_at DESC',
                'map' => static fn ($r) => $item('housing', $r['uid'], $r['title'],
                    \App\Modules\Housing\HousingPresenter::priceLabel((int) $r['price_cents'], $r['transaction'] !== 'sale'), 'house'),
            ],
            'job' => [
                'module' => 'JobsModule', 'label' => 'Emploi & stages', 'columns' => ['j.title', 'j.company_name', 'j.city'],
                'sql' => "SELECT j.uid, j.title, j.company_name, j.city FROM job_offers j WHERE j.status = 'published' AND (j.expires_at IS NULL OR j.expires_at > :snow)",
                'params' => $now, 'order' => 'j.published_at DESC',
                'map' => static fn ($r) => $item('job', $r['uid'], $r['title'], $r['company_name'] . ' · ' . $r['city'], 'briefcase'),
            ],
            'classified' => [
                'module' => 'ClassifiedsModule', 'label' => 'Petites annonces', 'columns' => ['a.title', 'a.description', 'a.location_label'],
                'sql' => "SELECT a.uid, a.title, a.price_cents, a.is_free FROM classifieds a WHERE a.status IN ('active', 'reserved') AND a.expires_at > :snow",
                'params' => $now, 'order' => 'a.published_at DESC',
                'map' => static fn ($r) => $item('classified', $r['uid'], $r['title'],
                    $r['is_free'] ? 'Gratuit' : \App\Modules\Classifieds\ClassifiedPresenter::priceLabel((int) $r['price_cents']), 'tag'),
            ],
            'discover' => [
                'module' => 'ExploreModule', 'label' => 'À découvrir', 'columns' => ['i.title', 'i.subtitle'],
                'sql' => "SELECT i.uid, i.title, i.subtitle, i.icon, i.link_type, i.link_id, i.link_url, s.icon AS section_icon
                          FROM explore_items i JOIN explore_sections s ON s.id = i.section_id WHERE i.status = 'published'", 'order' => 'i.sort_order',
                'map' => static fn ($r) => ['id' => $r['uid'], 'title' => $r['title'], 'subtitle' => $r['subtitle'], 'icon' => $r['icon'] ?? $r['section_icon'],
                    'link' => Link::from($r['link_type'], $r['link_id'], $r['link_url'])],
            ],
        ];
    }

    /** Chaque mot doit apparaître dans au moins une des colonnes. */
    private static function likeAll(array $columns, array $words): array
    {
        $clauses = [];
        $params = [];
        foreach ($words as $i => $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $or = [];
            foreach ($columns as $j => $column) {
                $or[] = "{$column} LIKE :w{$i}_{$j}";
                $params["w{$i}_{$j}"] = $like;
            }
            $clauses[] = '(' . implode(' OR ', $or) . ')';
        }

        return [implode(' AND ', $clauses), $params];
    }
}
