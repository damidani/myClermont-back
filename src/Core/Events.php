<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Bus d'événements synchrone entre modules.
 *
 *   Events::dispatch('account.registered', ['user_id' => 42]);
 *
 * Les écouteurs [Classe, méthode] ne sont instanciés qu'au moment du dispatch.
 * Une erreur dans un écouteur est journalisée sans faire échouer l'action principale
 * (une inscription ne doit pas échouer parce que l'attribution d'un badge a planté).
 */
final class Events
{
    /** @var array<string, list<array{0: class-string, 1: string}|callable>> */
    private static array $listeners = [];

    public static function listen(string $event, array|callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    public static function dispatch(string $event, array $payload = []): void
    {
        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                if (is_array($listener) && is_string($listener[0])) {
                    (new $listener[0]())->{$listener[1]}($payload);
                } else {
                    $listener($payload);
                }
            } catch (\Throwable $e) {
                Logger::error("Écouteur en échec pour {$event} : " . $e->getMessage(), [
                    'exception' => $e::class,
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }
}
