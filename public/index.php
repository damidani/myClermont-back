<?php

declare(strict_types=1);

/*
 * Point d'entrée unique de l'API (et du back-office /admin).
 * Toutes les requêtes HTTP sont redirigées ici par Nginx/Apache.
 */

use App\Admin\AdminKernel;
use App\Core\App;

// Serveur de développement (composer serve) : fichiers statiques servis tels quels
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) && !str_ends_with($_SERVER['REQUEST_URI'], '.php')) {
    return false;
}

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

$app = App::boot(APP_ROOT);

// Back-office web (session navigateur), séparé de l'API mobile (requêtes signées)
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    (new AdminKernel())->run();
    return;
}

// Page web du lien « mot de passe oublié » (envoyé par e-mail)
if ($path === '/password/reset') {
    (new \App\Modules\Account\PasswordResetPage())->run();
    return;
}

$app->run();
