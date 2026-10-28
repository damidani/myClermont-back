<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

/**
 * Envoi d'une notification push vers les appareils d'un utilisateur.
 * À implémenter pour la production : APNs (iOS, jeton .p8) et/ou Firebase Cloud Messaging (Android + iOS).
 */
interface PushSender
{
    /**
     * @param list<array{platform: string, push_token: string}> $devices
     * @return bool true si au moins un appareil a été notifié
     */
    public function send(array $devices, string $title, string $body, array $data, ?string $collapseKey): bool;
}
