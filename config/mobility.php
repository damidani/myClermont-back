<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Incidents routiers (spec v1 › Trafic) : TomTom Incident Details v5, un appel pour toute la métropole
    'tomtom_key'            => Env::get('TOMTOM_API_KEY', ''),
    'traffic_cache_seconds' => (int) Env::get('TRAFFIC_CACHE_SECONDS', 300),

    /*
     * Transports en commun : API Navitia (https://navitia.io, compte gratuit avec quota).
     * La clé n'est jamais envoyée aux applications : le serveur fait l'appel et met en cache.
     * Vérifier dans la console Navitia la « coverage » couvrant Clermont-Ferrand / T2C
     * et, pour l'info trafic, l'identifiant du réseau T2C (ex. network:…).
     */
    'navitia' => [
        'token'      => Env::get('NAVITIA_TOKEN', ''),
        'base_url'   => 'https://api.navitia.io/v1',
        'coverage'   => Env::get('NAVITIA_COVERAGE', 'fr-se'),
        'network_id' => Env::get('NAVITIA_NETWORK_ID', ''),
        'timeout'    => 8,
    ],

    'cache_ttl' => [
        'journeys' => 60,
        'traffic'  => 120,
        'places'   => 86400,
    ],

    // Zone de recherche par défaut (Clermont Auvergne Métropole, approximatif)
    'bounds' => ['min_lat' => 45.65, 'max_lat' => 45.90, 'min_lng' => 2.90, 'max_lng' => 3.30],

    'timezone' => 'Europe/Paris',
];
