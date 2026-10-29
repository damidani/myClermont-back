<?php

declare(strict_types=1);

return [
    'message_max' => 2000,

    // Interrogation périodique conseillée à l'app (secondes) quand une conversation est ouverte
    'poll_interval' => 5,

    /*
     * Envoi des notifications push. Pilote "log" : écrit dans storage/logs (développement).
     * Pour la production, implémenter App\Modules\Messaging\Push\PushSender (APNs / FCM) et l'indiquer ici.
     */
    // Envoi réel APNs / FCM dès que les clés sont configurées (config/push.php), sinon simple trace dans les logs
    'push_sender' => App\Core\Env::get('APNS_KEY', '') !== '' || App\Core\Env::get('FCM_SERVICE_ACCOUNT', '') !== ''
        ? App\Modules\Messaging\Push\NativePushSender::class
        : App\Modules\Messaging\Push\LogPushSender::class,
];
