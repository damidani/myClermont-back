<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Formats de publication : code => [libellé de la pastille, icône]
    'types' => [
        'text'   => ['Discussion', 'text'],
        'poll'   => ['Sondage', 'chart'],
        'photos' => ['Photos', 'photos'],
        'photo'  => ['Photo', 'photo'],
        'video'  => ['Vidéo', 'video'],
    ],

    // Filtres du fil (« Tout · Discussions · Sondages · Photos · Vidéos ») : code => [libellé, types inclus]
    'filters' => [
        'discussions' => ['Discussions', ['text']],
        'polls'       => ['Sondages', ['poll']],
        'photos'      => ['Photos', ['photos', 'photo']],
        'videos'      => ['Vidéos', ['video']],
    ],

    'limits' => [
        'body_max'         => 2000,
        'poll_options_min' => 2,
        'poll_options_max' => 6,
        'photos_max'       => 10,
        'comment_max'      => 1000,
    ],

    'media' => [
        'max_image_bytes' => 10 * 1024 * 1024,
    ],

    // Masquage automatique après N signalements (en attente de modération)
    'auto_hide_reports' => 5,

    'share_url' => Env::get('COMMUNITY_SHARE_URL', 'https://myclermont.fr/communaute/{id}'),
    'per_page'  => 20,
];
