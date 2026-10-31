<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Fuseau dans lequel les dates sont renvoyées à l'app (et les horaires du programme sont saisis)
    'timezone'  => 'Europe/Paris',

    // Lien du bouton « Partager » ({slug} est remplacé). Prévoir une page web / universal link.
    'share_url' => Env::get('EVENTS_SHARE_URL', 'https://myclermont.app/agenda/{slug}'),

    // Taille de page par défaut / maximale de la liste
    'per_page'  => 20,
    'max_per_page' => 50,

    // Durée de cache (APCu) des compteurs par catégorie affichés sur les filtres
    'counts_cache_ttl' => 60,
];
