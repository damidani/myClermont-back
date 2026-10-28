<?php

declare(strict_types=1);

/*
 * Préchargement OPcache (php.ini : opcache.preload=/chemin/deploy/preload.php
 * et opcache.preload_user=www-data).
 * Toutes les classes de src/ sont compilées une seule fois au démarrage de PHP-FPM
 * et restent en mémoire partagée : aucun coût d'autoload à chaque requête.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        opcache_compile_file($file->getPathname());
    }
}
