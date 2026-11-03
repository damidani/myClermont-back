<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Cartes Apple Wallet (demande c06e670d) : billet d'événement et bon plan du compte (QR code présenté en caisse).
 * Certificat « Pass Type ID » créé par Samuel sur developer.apple.com (jamais dans le dépôt) :
 *   PASSKIT_CERT : chemin du certificat + clé privée au format PEM ; PASSKIT_CERT_PASSWORD ; PASSKIT_WWDR : certificat intermédiaire Apple WWDR (G4) en PEM.
 */
return [
    'pass_type_id' => Env::get('PASSKIT_PASS_TYPE_ID', 'pass.com.myclermont.app'),
    'team_id'      => Env::get('PASSKIT_TEAM_ID', 'MQJ3CCT2BL'),
    'cert'         => Env::get('PASSKIT_CERT', ''),
    'password'     => Env::get('PASSKIT_CERT_PASSWORD', ''),
    'wwdr'         => Env::get('PASSKIT_WWDR', ''),
    'organization' => 'My Clermont',
];
