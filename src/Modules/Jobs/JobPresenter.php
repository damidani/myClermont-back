<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Content\ContentSources;
use App\Core\Config;

/**
 * Format JSON des offres, calqué sur les écrans :
 *   carte : icône de catégorie, NOUVEAU, contrat, titre, entreprise, résumé, ville, rythme, salaire, cœur
 *   fiche : + galerie « Découvre l'entreprise », pastilles, le poste, missions, profil, mention, Postuler
 */
final class JobPresenter
{
    public static function card(array $j, ?bool $isFavorite = null): array
    {
        $contracts = (array) Config::get('jobs.contracts');
        $modes = (array) Config::get('jobs.work_modes');
        $publishedAt = $j['published_at'] === null ? null : strtotime($j['published_at'] . ' UTC');

        return [
            'id'       => $j['uid'],
            'title'    => $j['title'],
            'company'  => ['name' => $j['company_name'], 'logo_url' => $j['company_logo_url']],
            'category' => ['slug' => $j['category_slug'], 'name' => $j['category_name'], 'icon' => $j['category_icon']],
            'contract' => [
                'code'  => $j['contract'],
                'label' => $contracts[$j['contract']][0] ?? $j['contract'],
                'color' => $contracts[$j['contract']][1] ?? null,
            ],
            'work_mode' => [
                'code'  => $j['work_mode'],
                'label' => $modes[$j['work_mode']][0] ?? $j['work_mode'],
                'icon'  => $modes[$j['work_mode']][1] ?? null,
            ],
            'summary'  => $j['summary'],
            'city'     => $j['city'],
            'salary'   => self::salary($j),
            'badge'    => $j['badge_label'],
            'is_new'   => $publishedAt !== null && $publishedAt > time() - (int) Config::get('jobs.new_days', 7) * 86400,
            'source'   => ContentSources::present($j),
            'is_favorite'  => $isFavorite,
            'published_at' => $publishedAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $publishedAt),
        ];
    }

    public static function detail(array $j, array $images, ?bool $isFavorite = null, ?array $application = null): array
    {
        $missions = $j['missions'] === null ? [] : (json_decode((string) $j['missions'], true) ?: []);

        return self::card($j, $isFavorite) + [
            'images'         => $images,
            'image_caption'  => $j['image_caption'],
            'description'    => $j['description'],
            'missions'       => array_values(array_filter($missions, 'is_string')),
            'profile'        => $j['profile'],
            'start_date'     => $j['start_date'],
            'duration'       => $j['duration_label'],
            'coordinates'    => $j['latitude'] === null ? null : ['latitude' => (float) $j['latitude'], 'longitude' => (float) $j['longitude']],
            'notice'         => $j['notice'],
            'is_active'      => $j['status'] === 'published',
            'apply'          => self::apply($j),
            // Déjà postulé depuis cet appareil / ce compte ?
            'my_application' => $application,
            'share_url'      => str_replace('{id}', $j['uid'], (string) Config::get('jobs.share_url')),
        ];
    }

    /**
     * Bouton « Postuler » :
     *   form     → formulaire myClermont (CV obligatoire, lettre selon cover_letter)
     *   external → ouvrir url (site carrière du recruteur, ou offre d'origine pour un import)
     *   none     → offre expirée
     */
    public static function apply(array $j): array
    {
        if ($j['status'] !== 'published') {
            return ['mode' => 'none', 'url' => null, 'label' => 'Offre expirée', 'cv' => null, 'cover_letter' => null];
        }
        if (!empty($j['application_url'])) {
            return ['mode' => 'external', 'url' => $j['application_url'], 'label' => 'Postuler sur le site du recruteur', 'cv' => null, 'cover_letter' => null];
        }
        $contact = ContentSources::contact($j);
        if ($contact['mode'] === 'external') {
            return ['mode' => 'external', 'url' => $contact['url'], 'label' => 'Postuler sur ' . $j['source_name'], 'cv' => null, 'cover_letter' => null];
        }

        return ['mode' => 'form', 'url' => null, 'label' => 'Postuler', 'cv' => 'required', 'cover_letter' => $j['cover_letter_mode']];
    }

    /** « 38 000–45 000 € / an », « À partir de 1 800 € / mois », « 11,88 € / heure »… */
    public static function salary(array $j): ?array
    {
        $min = $j['salary_min_cents'] === null ? null : (int) $j['salary_min_cents'];
        $max = $j['salary_max_cents'] === null ? null : (int) $j['salary_max_cents'];
        if ($j['salary_label'] === null && $min === null && $max === null) {
            return null;
        }

        $period = (array) Config::get('jobs.salary_periods');
        $suffix = $j['salary_period'] !== null ? ' / ' . ($period[$j['salary_period']] ?? $j['salary_period']) : '';
        $label = $j['salary_label'] ?? match (true) {
            $min !== null && $max !== null && $min !== $max => self::amount($min) . '–' . self::amount($max) . "\u{00A0}€" . $suffix,
            $min !== null && $max === null                  => 'À partir de ' . self::amount($min) . "\u{00A0}€" . $suffix,
            $min === null && $max !== null                  => "Jusqu'à " . self::amount($max) . "\u{00A0}€" . $suffix,
            default                                         => self::amount((int) $min) . "\u{00A0}€" . $suffix,
        };

        return ['min_cents' => $min, 'max_cents' => $max, 'period' => $j['salary_period'], 'label' => $label];
    }

    private static function amount(int $cents): string
    {
        return $cents % 100 === 0
            ? number_format(intdiv($cents, 100), 0, ',', "\u{202F}")
            : number_format($cents / 100, 2, ',', "\u{202F}");
    }
}
