<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Espace commerçant MyClermont Pro (front statique sur commercant.myclermont.app, API sous /api/v1/pro).
 */
return [
    'front_url'     => rtrim((string) Env::get('PRO_FRONT_URL', 'https://commercant.myclermont.app'), '/'),
    // Origines autorisées (CORS avec cookies). localhost:3000 seulement hors production.
    'origins'       => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('PRO_ORIGINS',
        'https://commercant.myclermont.app' . (Env::get('APP_ENV', 'production') === 'production' ? '' : ',http://localhost:3000')))))),
    'access_ttl'    => 900,                 // jeton d'accès : 15 min
    'remember_ttl'  => 30 * 86400,          // « rester connecté » : 30 jours
    'session_ttl'   => 12 * 3600,           // sinon : cookie de session, jeton valable 12 h
    'reset_ttl'     => 1800,                // lien de réinitialisation : 30 min
    'invite_ttl'    => 7 * 86400,           // invitation d'équipe : 7 jours
    // Passage Partenaire en libre-service : désactivé tant que le paiement n'est pas branché
    'subscribe_enabled' => (bool) Env::get('PRO_SUBSCRIBE_ENABLED', false),
    // Destinataire des demandes de rappel et des alertes de modération
    'team_email'    => Env::get('PRO_TEAM_EMAIL', 'contact@myclermont.app'),

    'plans' => [
        'gratuit'    => ['active_deals' => 1, 'team' => 1, 'photos' => 6, 'stats_days' => 60, 'csv_export' => false, 'flash' => false],
        'partenaire' => ['active_deals' => null, 'team' => 5, 'photos' => 30, 'stats_days' => 180, 'csv_export' => true, 'flash' => true],
    ],

    // Modules proposés à l'inscription selon l'activité (demande 2)
    'activity_modules' => [
        'restaurant' => ['bonsPlans', 'evenements', 'emplois'], 'cafe' => ['bonsPlans', 'evenements', 'emplois'],
        'commerce' => ['bonsPlans', 'evenements', 'emplois'], 'culture' => ['bonsPlans', 'evenements', 'emplois'],
        'sport' => ['bonsPlans', 'evenements', 'emplois'], 'hebergement' => ['bonsPlans', 'evenements', 'emplois'],
        'fastFood' => ['bonsPlans', 'emplois'], 'boulangerie' => ['bonsPlans', 'emplois'],
        'services' => ['devis', 'bonsPlans', 'emplois'], 'immobilier' => ['logements', 'emplois'],
    ],
];
