<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Connexion (spec v1 › Authentification et comptes) : Sign in with Apple (iOS), Google (Android), code par e-mail (secours).
 */
return [
    'apple' => [
        'bundle_id'   => Env::get('APPLE_BUNDLE_ID', 'com.myclermont.app'),          // aud du jeton d'identité
        'team_id'     => Env::get('APPLE_TEAM_ID', 'MQJ3CCT2BL'),
        'key_id'      => Env::get('APPLE_KEY_ID', ''),                               // clé « Sign in with Apple » (.p8)
        'private_key' => Env::get('APPLE_PRIVATE_KEY', ''),                          // contenu du .p8 (\n autorisés)
    ],

    'google' => [
        // Identifiants client OAuth acceptés comme audience (Android, iOS, web), séparés par des virgules
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('GOOGLE_CLIENT_ID', ''))))),
    ],

    'email_code' => [
        'ttl'          => 600,   // 10 minutes
        'max_attempts' => 5,     // au-delà, le code est détruit
        'per_email'    => [3, 600], // 3 codes par adresse toutes les 10 min
    ],

    // Suppression de compte : données effacées après ce délai (jours)
    'deletion_days' => 30,

    // Avatars symboles autorisés (SF Symbols)
    'avatar_symbols' => [
        'person.crop.circle.fill', 'person.circle.fill', 'face.smiling.fill', 'star.circle.fill', 'heart.circle.fill',
        'leaf.circle.fill', 'mountain.2.circle.fill', 'bicycle.circle.fill', 'cup.and.saucer.fill', 'music.note',
        'camera.circle.fill', 'pawprint.circle.fill', 'sun.max.circle.fill', 'moon.circle.fill', 'sparkles',
    ],
];
