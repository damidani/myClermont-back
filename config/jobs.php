<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Puces de filtre et pastilles des cartes : code => [libellé, couleur]
    'contracts' => [
        'cdi'        => ['CDI', '#2E9E6E'],
        'cdd'        => ['CDD', '#E0882E'],
        'alternance' => ['Alternance', '#7B4FD6'],
        'stage'      => ['Stage', '#3A6EA5'],
        'freelance'  => ['Freelance', '#5B6B7A'],
        'interim'    => ['Intérim', '#B5542E'],
        'saisonnier' => ['Saisonnier', '#C9852B'],
    ],

    'work_modes' => [
        'onsite' => ['Sur site', 'building'],
        'hybrid' => ['Hybride', 'laptop'],
        'remote' => ['Télétravail', 'house'],
    ],

    'salary_periods' => ['year' => 'an', 'month' => 'mois', 'hour' => 'heure'],

    // Pastille « NOUVEAU » pendant N jours après publication
    'new_days' => 7,

    'applications' => [
        // Une candidature par offre et par appareil sur cette période
        'dedupe_days'    => 30,
        'fallback_email' => Env::get('JOBS_APPLICATION_FALLBACK_EMAIL', ''),
    ],

    // RGPD : durée de conservation des CV / lettres
    'documents' => [
        'unattached_ttl_hours' => 24,   // envoyé mais jamais utilisé dans une candidature
        'retention_days'       => 90,   // après la candidature (le recruteur l'a reçu par e-mail)
    ],

    'share_url'    => Env::get('JOBS_SHARE_URL', 'https://myclermont.app/emploi/{id}'),
    'per_page'     => 20,
    'max_per_page' => 50,
    'counts_cache_ttl' => 60,
];
