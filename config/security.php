<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * APP_SIGNING_KEYS="keyId:platform:secretBase64,..."
 * => ['ios-2026-1' => ['platform' => 'ios', 'secret' => '<binaire>'], ...]
 */
$appKeys = [];
foreach (array_filter(array_map('trim', explode(',', (string) Env::get('APP_SIGNING_KEYS', '')))) as $entry) {
    $parts = explode(':', $entry, 3);
    if (count($parts) !== 3) {
        continue;
    }
    [$keyId, $platform, $secret] = $parts;
    $decoded = base64_decode($secret, true);
    if ($decoded === false || strlen($decoded) < 32) {
        continue; // clé invalide ou trop courte : ignorée
    }
    $appKeys[$keyId] = ['platform' => $platform, 'secret' => $decoded];
}

[$deviceMax, $deviceWindow]     = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_DEVICE', '120,60')));
[$registerMax, $registerWindow] = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_REGISTER', '10,3600')));
[$loginMax, $loginWindow]       = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_LOGIN', '10,300')));
[$contactMax, $contactWindow]   = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_CONTACT', '10,3600')));
[$uploadMax, $uploadWindow]     = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_UPLOAD', '20,3600')));
[$postMax, $postWindow]         = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_POST', '10,3600')));
[$commentMax, $commentWindow]   = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_COMMENT', '30,3600')));
[$mobilityMax, $mobilityWindow] = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_MOBILITY', '60,3600')));
[$messageMax, $messageWindow]   = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_MESSAGE', '120,3600')));
[$listingMax, $listingWindow]   = array_map('intval', explode(',', (string) Env::get('RATE_LIMIT_LISTING', '10,86400')));

return [
    'app_keys'         => $appKeys,
    'signature_window' => (int) Env::get('SIGNATURE_WINDOW', 300),
    'platforms'        => ['ios', 'android'],

    // Durée de cache (APCu) d'un appareil chargé depuis la base
    'device_cache_ttl' => 30,
    // Fréquence max de mise à jour de devices.last_seen_at (évite une écriture par requête)
    'device_touch_interval' => 300,

    'jwt' => [
        'secret'      => (string) Env::get('JWT_SECRET', ''),
        'issuer'      => Env::get('JWT_ISSUER', 'api.myclermont.fr'),
        'access_ttl'  => (int) Env::get('JWT_ACCESS_TTL', 900),
        'refresh_ttl' => (int) Env::get('JWT_REFRESH_TTL', 2592000),
    ],

    'rate_limits' => [
        'device'   => [$deviceMax, $deviceWindow],
        'register' => [$registerMax, $registerWindow],
        'login'    => [$loginMax, $loginWindow],
        'contact'  => [$contactMax, $contactWindow],
        'upload'   => [$uploadMax, $uploadWindow],
        'post'     => [$postMax, $postWindow],
        'comment'  => [$commentMax, $commentWindow],
        'mobility' => [$mobilityMax, $mobilityWindow],
        'message'  => [$messageMax, $messageWindow],
        'listing'  => [$listingMax, $listingWindow],
    ],
];
