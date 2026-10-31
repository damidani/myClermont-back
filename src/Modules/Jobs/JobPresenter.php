<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Content\ContentSources;
use App\Core\Config;
use App\Support\AppSchemas;

/**
 * Format JSON des offres, calqué sur les écrans :
 *   carte : icône de catégorie, NOUVEAU, contrat, titre, entreprise, résumé, ville, rythme, salaire, cœur
 *   fiche : + galerie « Découvre l'entreprise », pastilles, le poste, missions, profil, mention, Postuler
 */
final class JobPresenter
{
    /**
     * Job de la spec v1 : id, title, company, location, salary, contract, workMode, icon, summary, description, publishedAt, expiresAt.
     * Champs en plus (ignorés par un décodeur qui ne les connaît pas) : lien d'origine et mode de candidature, source, logo, catégorie.
     */
    public static function card(array $j, ?bool $isFavorite = null): array
    {
        $contracts = (array) Config::get('jobs.contracts');
        $modes = (array) Config::get('jobs.work_modes');
        $publishedAt = $j['published_at'] === null ? null : strtotime($j['published_at'] . ' UTC');
        $salary = self::salary($j);
        $apply = self::apply($j);

        return [
            'id'               => $j['uid'],
            'title'            => (string) $j['title'],
            'company'          => (string) ($j['company_name'] ?? ''),
            'location'         => (string) ($j['city'] ?? ''),
            'salary'           => (string) ($salary['label'] ?? ''),
            // Brief iOS §11.1 : 5 valeurs seulement ; Intérim et Saisonnier (offres importées) sont des CDD, libellé exact dans contractLabel
            'contract'         => in_array($j['contract'], ['interim', 'saisonnier'], true) ? 'CDD' : ($contracts[$j['contract']][0] ?? 'CDD'),
            'contract_label'   => $contracts[$j['contract']][0] ?? $j['contract'],
            'work_mode'        => $modes[$j['work_mode']][0] ?? 'Sur site',        // « Sur site », « Hybride », « Télétravail »
            'icon'             => in_array($j['category_icon'], AppSchemas::ICONS, true) ? $j['category_icon'] : 'briefcase.fill',
            'summary'          => (string) ($j['summary'] ?? ''),
            'description'      => $j['description'],
            'published_at'     => $publishedAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $publishedAt),
            'expires_at'       => $j['expires_at'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($j['expires_at'] . ' UTC')),
            // En plus de la spec
            'category'         => $j['category_slug'],
            'company_logo_url' => $j['company_logo_url'],
            'salary_range'     => $salary === null ? null : ['min_cents' => $salary['min_cents'], 'max_cents' => $salary['max_cents'], 'period' => $salary['period']],
            'is_new'           => $publishedAt !== null && $publishedAt > time() - (int) Config::get('jobs.new_days', 7) * 86400,
            'badge'            => $j['badge_label'],
            'apply_mode'       => $apply['mode'],          // form (formulaire My Clermont) | external (ouvrir applyUrl) | none
            'apply_url'        => $apply['url'],           // offre d'origine (France Travail, Adzuna, site du recruteur…)
            'source'           => ContentSources::present($j),
            'is_favorite'      => $isFavorite,
        ];
    }

    public static function detail(array $j, array $images, ?bool $isFavorite = null, ?array $application = null): array
    {
        $missions = $j['missions'] === null ? [] : (json_decode((string) $j['missions'], true) ?: []);

        return self::card($j, $isFavorite) + [
            'images'         => $images,
            'image_caption'  => $j['image_caption'],
            'missions'       => array_values(array_filter($missions, 'is_string')),
            'profile'        => $j['profile'],
            'start_date'     => $j['start_date'],
            'duration'       => $j['duration_label'],
            'latitude'       => $j['latitude'] === null ? null : (float) $j['latitude'],
            'longitude'      => $j['longitude'] === null ? null : (float) $j['longitude'],
            'notice'         => $j['notice'],
            'cover_letter'   => $j['cover_letter_mode'],
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
