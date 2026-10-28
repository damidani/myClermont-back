<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Sources importées automatiquement.  Lancer : php bin/console import:run <slug>   (ou import:all en cron)
 *
 * Chaque source doit aussi exister dans la table content_sources (nom affiché, logo, mode de contact,
 * publication automatique) : php bin/console source:add ...
 *
 *   target             module cible : events | housing
 *   importer           classe implémentant App\Content\Import\Importer
 *   options            options passées à l'importer
 *   unpublish_missing  dépublier les éléments disparus du flux (défaut true)
 *   every              fréquence en minutes (défaut 60) : le cron appelle import:all toutes les 5 min, seules les sources dues tournent
 *   enabled            false = jamais lancée par import:all
 *
 * Importeurs fournis (docs/IMPORTS.md) :
 *   FranceTravailImporter  offres d'emploi officielles (API France Travail)            → jobs
 *   PolirisImporter        export des logiciels d'agences immobilières (CSV / ZIP)     → housing
 *   JsonLdImporter         données schema.org des sites partenaires (JobPosting, logements) → jobs | housing
 *   JsonFeedImporter       flux JSON au format normalisé myClermont                    → toutes cibles
 */
return [
    // Géocodeur national (IGN / Base Adresse Nationale) : place les annonces sans coordonnées au centre de leur commune / quartier
    'geocoder_url' => Env::get('GEOCODER_URL', 'https://data.geopf.fr/geocodage/search'),

    'france_travail' => [
        // Domaine du code ROME de l'offre → catégorie myClermont (la clé la plus longue qui correspond gagne)
        'rome_categories' => [
            'M18' => 'tech', 'E11' => 'communication', 'E12' => 'communication', 'E13' => 'communication', 'M17' => 'communication',
            'D' => 'commerce', 'C' => 'administratif', 'M' => 'administratif',
            'G1' => 'culture', 'G15' => 'hotellerie', 'G16' => 'hotellerie', 'G17' => 'hotellerie', 'G18' => 'hotellerie',
            'J' => 'sante', 'K11' => 'sante', 'K12' => 'sante', 'K13' => 'sante', 'K21' => 'education',
            'H' => 'industrie', 'I' => 'industrie', 'F' => 'btp', 'B' => 'btp', 'N' => 'transport', 'L' => 'culture', 'E' => 'communication',
        ],
    ],

    'sources' => [
        // Offres d'emploi autour de Clermont-Ferrand — identifiants gratuits sur https://francetravail.io
        'france-travail' => [
            'target'   => 'jobs',
            'importer' => App\Content\Import\FranceTravailImporter::class,
            'enabled'  => Env::get('FRANCE_TRAVAIL_CLIENT_ID', '') !== '',
            'every'    => 60,
            'options'  => [
                'client_id'     => Env::get('FRANCE_TRAVAIL_CLIENT_ID', ''),
                'client_secret' => Env::get('FRANCE_TRAVAIL_CLIENT_SECRET', ''),
                'commune'       => Env::get('FRANCE_TRAVAIL_COMMUNE', '63113'),   // code INSEE de Clermont-Ferrand
                'distance'      => (int) Env::get('FRANCE_TRAVAIL_DISTANCE', 15), // km
            ],
        ],

        // Agrégateurs d'offres (Indeed, HelloWork, Meteojob, sites carrière…) via leurs API officielles.
        // Chaque offre garde son lien : « Postuler » renvoie l'utilisateur vers l'offre d'origine.
        'adzuna' => [
            'target'   => 'jobs',
            'importer' => App\Content\Import\AdzunaImporter::class,
            'enabled'  => Env::get('ADZUNA_APP_ID', '') !== '',
            'every'    => 360,   // offre gratuite : 250 appels / jour
            'options'  => [
                'app_id' => Env::get('ADZUNA_APP_ID', ''), 'app_key' => Env::get('ADZUNA_APP_KEY', ''),
                'where'  => 'Clermont-Ferrand', 'distance' => 15, 'max_pages' => 10,
            ],
        ],
        'jooble' => [
            'target'   => 'jobs',
            'importer' => App\Content\Import\JoobleImporter::class,
            'enabled'  => Env::get('JOOBLE_API_KEY', '') !== '',
            'every'    => 360,
            'options'  => ['key' => Env::get('JOOBLE_API_KEY', ''), 'location' => 'Clermont-Ferrand', 'radius' => 15, 'max_pages' => 10],
        ],

        // Exemple : agence immobilière partenaire qui exporte au format Poliris (passerelle « myClermont » dans son logiciel)
        // 'agence-dupont' => [
        //     'target'   => 'housing',
        //     'importer' => App\Content\Import\PolirisImporter::class,
        //     'every'    => 360,
        //     'options'  => ['url' => 'file:///var/www/myclermont/storage/imports/agence-dupont/annonces.zip', 'postal_codes' => ['63']],
        // ],

        // Exemple : site carrière d'une entreprise partenaire (offres publiées en schema.org JobPosting)
        // 'michelin-carrieres' => [
        //     'target'   => 'jobs',
        //     'importer' => App\Content\Import\JsonLdImporter::class,
        //     'every'    => 720,
        //     'options'  => ['kind' => 'jobs', 'urls' => ['https://exemple.fr/sitemap-offres.xml'], 'follow' => '#/offres/#', 'max_pages' => 300],
        // ],

        // Exemple : flux d'annonces d'une agence partenaire au format normalisé (docs/IMPORTS.md)
        // 'agence-exemple' => [
        //     'target'   => 'housing',
        //     'importer' => App\Content\Import\JsonFeedImporter::class,
        //     'options'  => [
        //         'url'     => 'https://partenaire.example/flux/myclermont.json',
        //         'headers' => ['Authorization: Bearer ' . Env::get('FEED_AGENCE_EXEMPLE_TOKEN', '')],
        //     ],
        // ],
    ],
];
