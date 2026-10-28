<?php

declare(strict_types=1);

namespace App\Cache;

use App\Core\Config;

/**
 * Fabrique du store actif : APCu si disponible (le plus rapide), sinon MySQL.
 */
final class Cache
{
    private static ?CacheStore $store = null;

    public static function store(): CacheStore
    {
        return self::$store ??= match (Config::get('app.cache_driver', 'auto')) {
            'apcu'  => new ApcuStore(),
            'mysql' => new MysqlStore(),
            default => self::apcuAvailable() ? new ApcuStore() : new MysqlStore(),
        };
    }

    /** Le cache appareils n'a d'intérêt qu'en mémoire : en mode MySQL on lit directement la table. */
    public static function isInMemory(): bool
    {
        return !self::store() instanceof MysqlStore;
    }

    /** Permet aux tests d'injecter un store. */
    public static function setStore(?CacheStore $store): void
    {
        self::$store = $store;
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
