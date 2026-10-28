<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'transactions' => [
        'rent'       => 'Location',
        'sale'       => 'Achat',
        'colocation' => 'Colocation',
    ],

    'property_types' => [
        'apartment' => 'Appartement',
        'house'     => 'Maison',
        'studio'    => 'Studio',
        'room'      => 'Chambre',
        'loft'      => 'Loft',
        'other'     => 'Autre',
    ],

    // « Équipements et atouts » : code => [libellé, clé d'icône pour l'app]
    'features' => [
        'balcony'          => ['Balcon', 'sun'],
        'terrace'          => ['Terrasse', 'terrace'],
        'garden'           => ['Jardin', 'garden'],
        'equipped_kitchen' => ['Cuisine équipée', 'kitchen'],
        'near_tram'        => ['Proche tram', 'tram'],
        'double_glazing'   => ['Double vitrage', 'check'],
        'elevator'         => ['Ascenseur', 'elevator'],
        'parking'          => ['Parking', 'parking'],
        'cellar'           => ['Cave', 'cellar'],
        'fiber'            => ['Fibre', 'wifi'],
        'pets_allowed'     => ['Animaux acceptés', 'pet'],
        'accessible'       => ['Accessible PMR', 'accessible'],
        'student'          => ['Idéal étudiant', 'student'],
    ],

    // Encart « Revenus souvent demandés » (location et colocation uniquement)
    'income_ratio' => 3,
    'income_text'  => 'Règle courante des bailleurs : des revenus d\'environ 3 fois le loyer. Une garantie (Visale, garant) peut compléter le dossier.',

    // Formulaire de contact
    'contact' => [
        'max_per_device_per_listing_per_day' => 1,
        // Destinataire si l'annonce n'a pas d'e-mail (annonces saisies par l'équipe / partenaires sans e-mail)
        'fallback_email' => Env::get('HOUSING_CONTACT_FALLBACK_EMAIL', ''),
    ],

    'share_url'      => Env::get('HOUSING_SHARE_URL', 'https://myclermont.fr/logement/{id}'),
    'per_page'       => 20,
    'max_per_page'   => 50,
    'max_map_pins'   => 500,
    'counts_cache_ttl' => 60,
];
