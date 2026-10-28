<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Accès MySQL via PDO.
 *  - connexion paresseuse : aucune connexion si la route n'utilise pas la base
 *  - connexion persistante (réutilisée entre requêtes par le worker PHP-FPM)
 *  - requêtes préparées émulées : 1 aller-retour réseau au lieu de 2, sûr avec
 *    le charset déclaré dans le DSN ; les types natifs (int/float) sont conservés (PHP >= 8.1)
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    private static function connect(): PDO
    {
        $c = (array) Config::get('database');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);

        $pdo = new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => (bool) $c['persistent'],
            PDO::ATTR_TIMEOUT            => 3,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /** @return array<string, mixed>|null */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $stmt = self::run($sql, $params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::run($sql, $params);

        return $stmt->fetchAll();
    }

    /** Exécute une requête et retourne le nombre de lignes affectées. */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::run($sql, $params);

        return $stmt->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::execute($sql, $params);

        return (int) self::connection()->lastInsertId();
    }

    /**
     * Les paramètres sont liés avec leur type réel : indispensable pour LIMIT/OFFSET
     * (avec l'émulation, un entier passé à execute() serait envoyé comme chaîne '20').
     */
    private static function run(string $sql, array $params): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(
                is_int($key) ? $key + 1 : ':' . ltrim($key, ':'),
                $value,
                match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                },
            );
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
