<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'host'       => Env::get('DB_HOST', '127.0.0.1'),
    'port'       => (int) Env::get('DB_PORT', 3306),
    'database'   => Env::get('DB_DATABASE', 'myclermont'),
    'username'   => Env::get('DB_USERNAME', 'root'),
    'password'   => (string) Env::get('DB_PASSWORD', ''),
    'persistent' => (bool) Env::get('DB_PERSISTENT', true),
    'charset'    => 'utf8mb4',
];
