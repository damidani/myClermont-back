<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Push;

use App\Core\Config;
use App\Core\Logger;

/** Envoie à chaque appareil par le bon canal : APNs (iOS), FCM (Android). Un canal non configuré est ignoré. */
final class NativePushSender implements PushSender
{
    public function send(array $devices, string $title, string $body, array $data, ?string $collapseKey): bool
    {
        $ok = false;
        foreach ($devices as $d) {
            try {
                $ok = match (true) {
                    $d['platform'] === 'ios' && Config::get('push.apns.key') => (new ApnsPushSender())->send($d['push_token'], $title, $body, $data, $collapseKey),
                    $d['platform'] === 'android' && Config::get('push.fcm.service_account') => (new FcmPushSender())->send($d['push_token'], $title, $body, $data, $collapseKey),
                    default => false,
                } || $ok;
            } catch (\Throwable $e) {
                Logger::warning('Push : envoi impossible', ['platform' => $d['platform'], 'error' => $e->getMessage()]);
            }
        }

        return $ok;
    }
}
