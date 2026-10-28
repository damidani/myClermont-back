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
 */
return [
    'sources' => [
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
