<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Jeu de repères gardé en mémoire (APCu) ; invalidé à chaque modification de lieu
    'pins_cache_ttl' => 3600,

    // Flux progressif : anneaux de distance (mètres) envoyés l'un après l'autre
    'stream_rings' => [1000, 3000, 10000],

    // Centre par défaut si l'app n'envoie pas de position (place de Jaude)
    'default_center' => ['lat' => 45.7773, 'lng' => 3.0816],

    'nearby_limit_max' => 100,
    'timezone' => 'Europe/Paris',
    // Avis Google (303b2796) : clé de l'API Places (New), restreinte à l'IP du serveur ; sans clé, /places/{id}/google-reviews répond 404
    'google_api_key' => Env::get('GOOGLE_PLACES_API_KEY', ''),
    'google_cache_seconds' => 86400,

    'share_url' => Env::get('PLACES_SHARE_URL', 'https://myclermont.app/lieux/{id}'),
];
