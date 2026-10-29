<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'env'           => Env::get('APP_ENV', 'production'),
    'debug'         => (bool) Env::get('APP_DEBUG', false),
    'timezone'      => Env::get('APP_TIMEZONE', 'UTC'),
    'key'           => Env::get('APP_KEY', ''),
    'max_body_size' => (int) Env::get('MAX_BODY_SIZE', 1048576),
    'max_upload_size' => (int) Env::get('MAX_UPLOAD_SIZE', 5242880),
    'max_media_size'  => (int) Env::get('MAX_MEDIA_SIZE', 52428800),
    // URL publique des médias (photos / vidéos de la communauté), servis directement par Nginx
    'media_base_url'  => rtrim((string) Env::get('MEDIA_BASE_URL', 'https://api.myclermont.fr/media'), '/'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    'cache_driver'  => Env::get('CACHE_DRIVER', 'auto'),
    'mail_from'     => Env::get('MAIL_FROM', 'no-reply@myclermont.fr'),

    /*
     * Alias de middlewares utilisables dans routes/api.php.
     * "throttle:120,60,device" => new RateLimitMiddleware('120', '60', 'device')
     */
    'middleware' => [
        'app.signature' => App\Http\Middleware\AppSignatureMiddleware::class,
        'device'        => App\Http\Middleware\DeviceSignatureMiddleware::class,
        'auth'          => App\Http\Middleware\AuthMiddleware::class,
        'throttle'      => App\Http\Middleware\RateLimitMiddleware::class,
        'premium'       => App\Modules\Premium\PremiumMiddleware::class,
        'role'          => App\Http\Middleware\RoleMiddleware::class,
    ],
];
