<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions;

use App\Core\Config;
use App\Core\Logger;

/**
 * App Store Server API (Get All Subscription Statuses), pour confirmer l'état réel d'un abonnement (9cdabedf).
 * Clé In-App Purchase (.p8) : APPSTORE_ISSUER_ID, APPSTORE_KEY_ID, APPSTORE_PRIVATE_KEY (contenu PEM ou chemin du fichier).
 * Sans clé configurée, configured() = false et l'appelant s'en tient à la transaction signée.
 */
final class AppStoreApi
{
    public static function configured(): bool
    {
        return Config::get('subscriptions.apple.issuer_id') && Config::get('subscriptions.apple.key_id') && self::key() !== null;
    }

    /**
     * Dernière transaction de l'abonnement, vérifiée : ['tx' => payload, 'renewal' => payload, 'status' => 1..5] ou null.
     * status Apple : 1 actif, 2 expiré, 3 relance de facturation, 4 période de grâce, 5 révoqué.
     */
    public static function latest(string $originalTransactionId, string $environment): ?array
    {
        if (!self::configured()) {
            return null;
        }
        $host = $environment === 'Sandbox' ? 'https://api.storekit-sandbox.itunes.apple.com' : 'https://api.storekit.itunes.apple.com';
        $ch = curl_init($host . '/inApps/v1/subscriptions/' . rawurlencode($originalTransactionId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::token()]]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($raw)) {
            Logger::warning('App Store Server API : réponse inattendue', ['http' => $code, 'environment' => $environment]);
            return null;
        }
        $best = null;
        foreach ((array) (json_decode($raw, true)['data'] ?? []) as $group) {
            foreach ((array) ($group['lastTransactions'] ?? []) as $last) {
                if ((string) ($last['originalTransactionId'] ?? '') !== $originalTransactionId || empty($last['signedTransactionInfo'])) {
                    continue;
                }
                $tx = AppleJws::verify((string) $last['signedTransactionInfo']);
                $best = ['tx' => $tx, 'renewal' => !empty($last['signedRenewalInfo']) ? AppleJws::verify((string) $last['signedRenewalInfo']) : [], 'status' => (int) ($last['status'] ?? 0)];
            }
        }

        return $best;
    }

    /** JWT ES256 de l'API serveur, valable 20 minutes */
    private static function token(): string
    {
        $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(json_encode(['alg' => 'ES256', 'kid' => Config::get('subscriptions.apple.key_id'), 'typ' => 'JWT'])) . '.'
            . $b64(json_encode(['iss' => Config::get('subscriptions.apple.issuer_id'), 'iat' => time(), 'exp' => time() + 1200,
                                'aud' => 'appstoreconnect-v1', 'bid' => Config::get('subscriptions.apple.bundle_id')]));
        openssl_sign($input, $der, self::key(), OPENSSL_ALGO_SHA256);

        return $input . '.' . $b64(self::rawSignature($der));
    }

    private static function key(): ?\OpenSSLAsymmetricKey
    {
        $k = (string) Config::get('subscriptions.apple.private_key', '');
        if ($k !== '' && !str_contains($k, 'BEGIN') && is_file($k)) {
            $k = (string) file_get_contents($k);
        }

        return $k === '' ? null : (openssl_pkey_get_private(str_replace('\n', "\n", $k)) ?: null);
    }

    /** DER (SEQUENCE de deux INTEGER) → r||s sur 64 octets */
    private static function rawSignature(string $der): string
    {
        $off = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$off + 1]);
            $out .= str_pad(substr(ltrim(substr($der, $off + 2, $len), "\0"), -32), 32, "\0", STR_PAD_LEFT);
            $off += 2 + $len;
        }

        return $out;
    }
}
