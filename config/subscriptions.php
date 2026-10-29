<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * My Clermont+ (spec v1 › My Clermont+ (StoreKit 2)).
 */
return [
    'apple' => [
        'bundle_id'   => Env::get('APPLE_BUNDLE_ID', 'com.myclermont.app'),
        'product_ids' => ['com.myclermont.app.plus.monthly'],
        // Empreinte SHA-256 du certificat racine « Apple Root CA - G3 » : la chaîne x5c des transactions doit s'y terminer
        'root_sha256' => strtolower(str_replace(':', '', (string) Env::get('APPLE_ROOT_CA_G3_SHA256', '63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179'))),
        'allow_sandbox' => (bool) Env::get('APPLE_ALLOW_SANDBOX', true),   // achats de test (TestFlight, Xcode)
    ],
];
