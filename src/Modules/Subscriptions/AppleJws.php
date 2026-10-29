<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions;

use App\Core\Config;
use App\Exceptions\HttpException;

/**
 * Vérifie une signature JWS de l'App Store (transaction StoreKit 2, notification serveur v2), sans bibliothèque :
 *  1. la chaîne x5c [feuille, intermédiaire, racine] : chaque certificat est signé par le suivant ;
 *  2. la racine est bien « Apple Root CA - G3 » (empreinte SHA-256 épinglée) ;
 *  3. la feuille porte l'extension Apple de signature des reçus (OID 1.2.840.113635.100.6.11.1) ;
 *  4. la signature ES256 du JWS est valide avec la clé publique de la feuille.
 */
final class AppleJws
{
    private const RECEIPT_SIGNING_OID = '1.2.840.113635.100.6.11.1';

    /** @return array<string, mixed> contenu vérifié */
    public static function verify(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw self::invalid();
        }
        $header = json_decode(self::unb64($parts[0]), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'ES256' || !is_array($header['x5c'] ?? null) || count($header['x5c']) < 3) {
            throw self::invalid();
        }
        $pems = array_map(static fn (string $der) => "-----BEGIN CERTIFICATE-----\n" . chunk_split($der, 64, "\n") . "-----END CERTIFICATE-----\n", $header['x5c']);
        [$leaf, $intermediate, $root] = $pems;

        if (hash('sha256', (string) base64_decode($header['x5c'][2], true)) !== Config::get('subscriptions.apple.root_sha256')) {
            throw self::invalid();
        }
        foreach ([[$leaf, $intermediate], [$intermediate, $root], [$root, $root]] as [$cert, $issuer]) {
            if (openssl_x509_verify($cert, $issuer) !== 1) {
                throw self::invalid();
            }
        }
        $now = time();
        foreach ([$leaf, $intermediate] as $cert) {
            $info = openssl_x509_parse($cert);
            if ($info === false || $info['validFrom_time_t'] > $now + 300) {
                throw self::invalid();
            }
        }
        $leafInfo = openssl_x509_parse($leaf, false);
        if (!isset($leafInfo['extensions'][self::RECEIPT_SIGNING_OID]) && !str_contains(json_encode($leafInfo['extensions'] ?? []), self::RECEIPT_SIGNING_OID)) {
            // Selon la version d'OpenSSL, l'extension inconnue est listée par son OID : on la cherche aussi dans le DER
            if (!str_contains((string) base64_decode($header['x5c'][0], true), "\x06\x0A\x2A\x86\x48\x86\xF7\x63\x64\x06\x0B\x01")) {
                throw self::invalid();
            }
        }

        $sig = self::unb64($parts[2]);
        if (strlen($sig) !== 64 || openssl_verify($parts[0] . '.' . $parts[1], self::derSignature($sig), openssl_pkey_get_public($leaf), OPENSSL_ALGO_SHA256) !== 1) {
            throw self::invalid();
        }

        return json_decode(self::unb64($parts[1]), true) ?: throw self::invalid();
    }

    /** Signature JWS r||s (64 octets) → DER attendu par openssl_verify. */
    public static function derSignature(string $raw): string
    {
        $int = static function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '' || ord($b[0]) > 0x7F) {
                $b = "\0" . $b;
            }
            return "\x02" . chr(strlen($b)) . $b;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));

        return "\x30" . chr(strlen($seq)) . $seq;
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private static function invalid(): HttpException
    {
        return new HttpException(400, 'validation_failed', 'Transaction App Store invalide.', ['reason' => 'invalid_signature']);
    }
}
