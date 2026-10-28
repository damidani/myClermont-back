<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Logger fichier (une ligne JSON par événement, facile à parser / envoyer vers un ELK, Loki...).
 * Un fichier par jour dans storage/logs/.
 */
final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** Événements de sécurité (signature invalide, rejeu, appareil bloqué...). */
    public static function security(string $message, array $context = []): void
    {
        self::write('security', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'time'    => gmdate('c'),
            'level'   => $level,
            'message' => $message,
            'request' => Context::$requestId,
            'context' => $context,
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            'uri'     => $_SERVER['REQUEST_URI'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $dir = dirname(__DIR__, 2) . '/storage/logs';
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
