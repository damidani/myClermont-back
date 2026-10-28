<?php

declare(strict_types=1);

return [
    // Partie commune de l'accueil (stories, flash, offres, à découvrir) gardée en cache mémoire
    'home_cache_ttl' => 60,

    'discover_page_size' => 20,
    'offers_on_home'     => 10,

    /*
     * Météo de l'en-tête : Open-Meteo (sans clé).
     * ⚠️ Gratuit pour un usage non commercial uniquement : pour une app commerciale, souscrire
     * leur offre API (ou changer de fournisseur dans WeatherService).
     */
    'weather' => [
        'latitude'  => 45.7772,
        'longitude' => 3.0870,
        'cache_ttl' => 900,
        'url'       => 'https://api.open-meteo.com/v1/forecast',
    ],

    'timezone' => 'Europe/Paris',

    // Recherche globale « Un lieu, une sortie, une envie… » : résultats par module
    'search_per_type' => 5,
];
