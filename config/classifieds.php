<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'conditions' => [
        // Libellés de l'app (brief iOS §12.2) ; « fair » (ancien « État correct ») est affiché « Bon état »
        'new'       => 'Neuf',
        'like_new'  => 'Comme neuf',
        'very_good' => 'Très bon état',
        'good'      => 'Bon état',
        'for_parts' => 'À réparer',
    ],

    'limits' => [
        'title_min'       => 3,
        'title_max'       => 100,
        'description_max' => 4000,
        'photos_max'      => 6,
        'price_max_euros' => 100000,
        'active_per_user' => 50,   // annonces en ligne simultanément par personne
    ],

    // Durée de mise en ligne, prolongeable
    'duration_days' => 60,

    // Masquage automatique après N signalements
    'auto_hide_reports' => 3,

    // Encart « Conseils de sécurité » de la fiche (modifiable sans mise à jour de l'app)
    'safety_tips' => [
        ['icon' => 'people', 'text' => 'Rencontre l\'autre personne dans un lieu public et fréquenté.'],
        ['icon' => 'eye',    'text' => 'Vérifie l\'objet avant de payer.'],
        ['icon' => 'euro',   'text' => 'Ne verse jamais d\'acompte sans avoir vu l\'objet.'],
        ['icon' => 'link',   'text' => 'Méfie-toi des liens de paiement reçus par message.'],
    ],

    'share_url' => Env::get('CLASSIFIEDS_SHARE_URL', 'https://myclermont.app/annonces/{id}'),
    'per_page'  => 20,
];
