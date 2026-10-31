<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Destinataire des demandes de devis si la fiche n'a pas d'e-mail
    'fallback_email' => Env::get('SERVICES_QUOTE_FALLBACK_EMAIL', ''),
    'share_url'      => Env::get('SERVICES_SHARE_URL', 'https://myclermont.app/services/{id}'),
];
