<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Notifications push (spec v1 › Notifications push et Live Activity).
 */
return [
    'apns' => [
        'key_id'  => Env::get('APNS_KEY_ID', ''),
        'team_id' => Env::get('APNS_TEAM_ID', 'MQJ3CCT2BL'),
        'key'     => Env::get('APNS_KEY', ''),             // contenu du .p8 (\n autorisés)
        'topic'   => Env::get('APNS_TOPIC', 'com.myclermont.app'),
        'sandbox' => (bool) Env::get('APNS_SANDBOX', false),   // true pour les builds de développement
    ],
    'fcm' => [
        'service_account' => Env::get('FCM_SERVICE_ACCOUNT', ''),   // chemin du JSON du compte de service Firebase
    ],

    // Thèmes que l'app peut suivre (devices.topics)
    'topics' => ['events.weekly', 'events.favorites', 'deals.new', 'community.replies', 'classifieds.messages', 'jobs.applications'],

    // Thèmes « annonce » : jamais entre 22 h et 8 h (heure de Paris) ; les réponses et messages partent tout de suite
    'quiet_topics' => ['events.weekly', 'deals.new'],
    'quiet_hours'  => [22, 8],
];
