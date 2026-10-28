<?php

declare(strict_types=1);

namespace App\Security;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Logger;
use App\Exceptions\HttpException;

/**
 * Vérifications communes à toutes les requêtes signées : fraîcheur du timestamp et unicité du nonce.
 */
final class NonceGuard
{
    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    public static function assertFreshTimestamp(?string $timestamp): void
    {
        if ($timestamp === null || !ctype_digit($timestamp)) {
            throw HttpException::unauthorized('invalid_timestamp', 'En-tête X-Timestamp manquant ou invalide.');
        }

        $window = (int) Config::get('security.signature_window', 300);
        if (abs(time() - (int) $timestamp) > $window) {
            // X-Server-Time (ajouté sur toutes les réponses) permet au client de corriger son décalage d'horloge
            throw HttpException::unauthorized('timestamp_out_of_window', 'Horloge de l\'appareil désynchronisée.');
        }
    }

    public static function assertValidNonce(?string $nonce): void
    {
        if ($nonce === null || !preg_match(self::NONCE_PATTERN, $nonce)) {
            throw HttpException::unauthorized('invalid_nonce', 'En-tête X-Nonce manquant ou invalide.');
        }
    }

    /**
     * À appeler APRÈS la vérification de signature : on ne stocke que des nonces authentiques,
     * un attaquant ne peut donc pas saturer le stockage avec des requêtes forgées.
     */
    public static function consume(string $scope, string $nonce): void
    {
        // Le nonce doit être mémorisé au moins aussi longtemps qu'un timestamp reste acceptable
        $ttl = 2 * (int) Config::get('security.signature_window', 300);

        if (!Cache::store()->add('nonce:' . $scope . ':' . $nonce, $ttl)) {
            Logger::security('Rejeu de requête détecté', ['scope' => $scope, 'nonce' => $nonce]);
            throw HttpException::unauthorized('replayed_request', 'Requête déjà traitée.');
        }
    }
}
