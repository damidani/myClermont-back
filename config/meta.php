<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * GET /meta (spec v1) : ce que l'app lit au démarrage pour s'adapter sans nouvelle version.
 */
return [
    // Version minimale par plateforme : en dessous, l'app demande la mise à jour
    'min_app_version' => [
        'ios'     => Env::get('MIN_APP_VERSION_IOS', '0.9.1'),
        'android' => Env::get('MIN_APP_VERSION_ANDROID', '0.9.1'),
    ],

    // Drapeaux de fonctions (activables sans publier l'app)
    'features' => [
        'assistant'      => (bool) Env::get('FEATURE_ASSISTANT', true),
        'plus'           => (bool) Env::get('FEATURE_PLUS', false),       // paiement My Clermont+ (désactivé dans l'app actuelle)
        'classifiedsChat' => (bool) Env::get('FEATURE_CLASSIFIEDS_CHAT', true),
        'fuel'           => (bool) Env::get('FEATURE_FUEL', true),
        'traffic'        => Env::get('TOMTOM_API_KEY', '') !== '',
    ],

    // Libellés des états d'une petite annonce (spec : « Comme neuf », « Très bon état »…)
    'classified_conditions' => ['Comme neuf', 'Très bon état', 'Bon état', 'État correct'],
];
