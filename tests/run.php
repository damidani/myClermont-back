<?php

declare(strict_types=1);

/*
 * Lanceur de tests sans dépendance : php tests/run.php
 * Chaque fichier tests/*Test.php retourne un tableau ['nom du test' => callable].
 * Les tests n'ont pas besoin de MySQL (store mémoire injecté).
 */

use App\Cache\Cache;
use App\Core\Config;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Configuration de test, indépendante du .env local
putenv('APP_ENV=testing');
putenv('APP_DEBUG=false');
putenv('APP_KEY=' . base64_encode(str_repeat('k', 32)));
putenv('JWT_SECRET=' . base64_encode(str_repeat('j', 64)));
putenv('JWT_ISSUER=test');
putenv('APP_SIGNING_KEYS=ios-test:ios:' . base64_encode(str_repeat('a', 32)));
putenv('CACHE_DRIVER=memory');
if (is_file($root . '/storage/cache/config.php')) {
    fwrite(STDERR, "Supprimez le cache de configuration avant les tests : php bin/console config:clear\n");
    exit(1);
}
Config::load($root);
Cache::setStore(new Tests\MemoryStore());
require __DIR__ . '/helpers.php';

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message ? $message . ' — ' : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message = 'condition fausse'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_throws(callable $fn, string $errorCode): void
{
    try {
        $fn();
    } catch (App\Exceptions\HttpException $e) {
        assert_same($errorCode, $e->errorCode, 'code d\'erreur');
        return;
    }
    throw new RuntimeException("Exception {$errorCode} attendue");
}

$passed = $failed = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    foreach (require $file as $name => $test) {
        try {
            $test();
            $passed++;
            echo "\033[32m✔\033[0m " . basename($file, '.php') . " › {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "\033[31m✘\033[0m " . basename($file, '.php') . " › {$name}\n    {$e->getMessage()}\n";
        }
    }
}

// Fixtures de l'app iOS : construites par les presenters, validées contre les schémas (tests/fixtures.php)
require __DIR__ . '/fixtures.php';
try {
    $errors = check_fixtures();
    if ($errors !== []) {
        throw new RuntimeException(implode("\n    ", $errors));
    }
    $passed++;
    echo "\033[32m✔\033[0m fixtures › chaque fixture valide le schéma de l'app iOS\n";
    if (in_array('--fixtures', $argv, true)) {
        echo '  ' . write_fixtures(dirname(__DIR__)) . " fixtures écrites dans fixtures/ et app-integration/fixtures/\n";
    }
} catch (Throwable $e) {
    $failed++;
    echo "\033[31m✘\033[0m fixtures › chaque fixture valide le schéma de l'app iOS\n    {$e->getMessage()}\n";
}

echo "\n{$passed} réussi(s), {$failed} échoué(s)\n";
exit($failed > 0 ? 1 : 0);
