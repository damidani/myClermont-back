<?php

declare(strict_types=1);

namespace App\Modules\Places;

/**
 * Prépare les repères de la carte depuis le jeu en mémoire : filtre, distance, tri du plus proche au plus loin.
 * Aucun accès base (sauf la première fois, quand le jeu est mis en cache). ~1 ms pour quelques milliers de lieux.
 *
 * Format compact (tableaux plutôt qu'objets : ~3 fois moins de données) :
 *   "fields": ["id", "lat", "lng", "cat", "flags", "name", "dist"]
 *   "pins":   [["9c1f…", 45.77731, 3.08162, 0, 2, "Place de Jaude", 42], …]
 *   cat   = index dans "categories"     flags = 1 partenaire | 2 incontournable     dist = mètres (null sans position)
 */
final class PinService
{
    public const FIELDS = ['id', 'lat', 'lng', 'cat', 'flags', 'name', 'dist'];

    public function __construct(private readonly PlaceRepository $places = new PlaceRepository())
    {
    }

    /**
     * @param array{0: float, 1: float}|null $center position de l'utilisateur (ou centre de la carte)
     * @param 'partners'|'must_see'|null $filter
     */
    public function select(?array $center, ?string $category, ?string $filter): array
    {
        $set = $this->places->pinSet();
        $categoryIndex = $category === null ? null : array_search($category, array_column($set['categories'], 'slug'), true);
        if ($category !== null && $categoryIndex === false) {
            throw new \App\Exceptions\ValidationException(['category' => 'Catégorie inconnue.']);
        }
        $flag = match ($filter) {
            'partners' => PlaceRepository::FLAG_PARTNER,
            'must_see' => PlaceRepository::FLAG_MUST_SEE,
            null       => 0,
            default    => throw new \App\Exceptions\ValidationException(['filter' => 'Filtre inconnu (partners, must_see).']),
        };

        $pins = [];
        foreach ($set['pins'] as $pin) {
            if (($categoryIndex !== null && $pin[3] !== $categoryIndex) || ($flag !== 0 && ($pin[4] & $flag) === 0)) {
                continue;
            }
            $pin[6] = $center === null ? null : Geo::distance($center[0], $center[1], $pin[1], $pin[2]);
            $pins[] = $pin;
        }
        if ($center !== null) {
            usort($pins, static fn (array $a, array $b) => $a[6] <=> $b[6]);
        }

        return [
            'version'    => $set['version'],
            'categories' => $this->categoriesWithCounts($set),
            'filters'    => $this->filterCounts($set),
            'pins'       => $pins,
        ];
    }

    /** Puces : « Tous 179 », chaque catégorie avec son nombre de lieux. */
    private function categoriesWithCounts(array $set): array
    {
        $counts = array_count_values(array_column($set['pins'], 3));

        return array_map(
            static fn (int $i, array $c) => $c + ['count' => $counts[$i] ?? 0],
            array_keys($set['categories']),
            $set['categories'],
        );
    }

    /** « Partenaires 1 », « Incontournables 12 », « Tous 179 » */
    private function filterCounts(array $set): array
    {
        $partners = $mustSee = 0;
        foreach ($set['pins'] as $pin) {
            $partners += ($pin[4] & PlaceRepository::FLAG_PARTNER) ? 1 : 0;
            $mustSee += ($pin[4] & PlaceRepository::FLAG_MUST_SEE) ? 1 : 0;
        }

        return ['all' => count($set['pins']), 'partners' => $partners, 'must_see' => $mustSee];
    }
}
