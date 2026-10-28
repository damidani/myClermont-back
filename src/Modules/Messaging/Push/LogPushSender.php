<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Core\Logger;

/** Pilote de développement : journalise la notification au lieu de l'envoyer. */
final class LogPushSender implements PushSender
{
    public function send(array $devices, string $title, string $body, array $data, ?string $collapseKey): bool
    {
        Logger::info('Notification push (non envoyée : pilote log)', [
            'devices' => count($devices), 'title' => $title, 'body' => $body, 'data' => $data,
        ]);

        return true;
    }
}
