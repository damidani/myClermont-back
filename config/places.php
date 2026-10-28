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
    'share_url' => Env::get('PLACES_SHARE_URL', 'https://myclermont.fr/lieux/{id}'),
];
