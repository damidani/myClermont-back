<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

/**
 * Registre des « contextes » de conversation : chaque module qui utilise la messagerie
 * (petites annonces aujourd'hui) indique comment résumer l'élément concerné dans la boîte de réception.
 *
 *   MessagingContexts::register('classified', fn (array $ids) => [uid => ['title' => …, 'subtitle' => …, 'image_url' => …, 'status' => …]]);
 */
final class MessagingContexts
{
    /** @var array<string, \Closure(list<string>): array<string, array>> */
    private static array $resolvers = [];

    public static function register(string $type, \Closure $resolver): void
    {
        self::$resolvers[$type] = $resolver;
    }

    /**
     * @param array<string, list<string>> $idsByType
     * @return array<string, array<string, array>> type => uid => résumé
     */
    public static function resolve(array $idsByType): array
    {
        $out = [];
        foreach ($idsByType as $type => $ids) {
            $out[$type] = isset(self::$resolvers[$type]) ? (self::$resolvers[$type])(array_values(array_unique($ids))) : [];
        }

        return $out;
    }
}
