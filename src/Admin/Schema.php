<?php

declare(strict_types=1);

namespace App\Admin;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;

/**
 * Lecture du schéma MySQL (INFORMATION_SCHEMA) : colonnes, types, ENUM, clés primaires et étrangères.
 * C'est la seule source des noms de tables / colonnes utilisés dans le SQL du back-office :
 * aucun identifiant ne vient de la requête HTTP sans avoir été comparé à cette liste.
 */
final class Schema
{
    /** @var array<string, array>|null */
    private static ?array $tables = null;

    /**
     * @return array{columns: array<string, array>, primary: list<string>, foreign: array<string, array{table: string, column: string}>}|null
     */
    public static function table(string $table): ?array
    {
        return self::all()[$table] ?? null;
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }
        $cached = Cache::store()->get('admin:schema');
        if (is_array($cached)) {
            return self::$tables = $cached;
        }

        $db = (string) Config::get('database.database');
        $tables = [];
        foreach (Database::fetchAll(
            'SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE type, COLUMN_TYPE ctype, IS_NULLABLE nullable, COLUMN_DEFAULT def,
                    CHARACTER_MAXIMUM_LENGTH len, COLUMN_KEY ckey, EXTRA extra, COLUMN_COMMENT comment
             FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION',
            ['db' => $db],
        ) as $r) {
            $tables[$r['t']]['columns'][$r['c']] = [
                'type'     => strtolower($r['type']),
                'unsigned' => str_contains($r['ctype'], 'unsigned'),
                'nullable' => $r['nullable'] === 'YES',
                'default'  => $r['def'],
                'length'   => $r['len'] !== null ? (int) $r['len'] : null,
                'auto'     => str_contains($r['extra'], 'auto_increment'),
                'enum'     => preg_match("/^(enum|set)\((.*)\)$/i", $r['ctype'], $m) ? self::enumValues($m[2]) : null,
                'bool'     => strtolower($r['ctype']) === 'tinyint(1)',
                'comment'  => $r['comment'],
            ];
            $tables[$r['t']]['primary'] ??= [];
            $tables[$r['t']]['foreign'] ??= [];
        }
        foreach (Database::fetchAll(
            "SELECT TABLE_NAME t, COLUMN_NAME c, CONSTRAINT_NAME k, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME, ORDINAL_POSITION",
            ['db' => $db],
        ) as $r) {
            if ($r['k'] === 'PRIMARY') {
                $tables[$r['t']]['primary'][] = $r['c'];
            } elseif ($r['rt'] !== null) {
                $tables[$r['t']]['foreign'][$r['c']] = ['table' => $r['rt'], 'column' => $r['rc']];
            }
        }

        // MariaDB stocke JSON en LONGTEXT + contrainte json_valid() : on la repère pour proposer un champ JSON
        try {
            foreach (Database::fetchAll('SELECT TABLE_NAME t, CHECK_CLAUSE c FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = :db', ['db' => $db]) as $r) {
                if (preg_match('/json_valid\(`?(\w+)`?\)/i', (string) $r['c'], $m) && isset($tables[$r['t']]['columns'][$m[1]])) {
                    $tables[$r['t']]['columns'][$m[1]]['type'] = 'json';
                }
            }
        } catch (\PDOException) {
            // MySQL < 8.0.16 : pas de CHECK_CONSTRAINTS (et le type JSON y est déjà rapporté comme "json")
        }

        Cache::store()->set('admin:schema', $tables, 300);

        return self::$tables = $tables;
    }

    /** À appeler après une migration (php bin/console migrate le fait). */
    public static function forget(): void
    {
        self::$tables = null;
        Cache::store()->delete('admin:schema');
    }

    /** Tests : schéma injecté sans MySQL. */
    public static function set(?array $tables): void
    {
        self::$tables = $tables;
    }

    /** "'a','b','l''x'" → ['a', 'b', "l'x"] */
    private static function enumValues(string $list): array
    {
        preg_match_all("/'((?:[^']|'')*)'/", $list, $m);

        return array_map(static fn ($v) => str_replace("''", "'", $v), $m[1]);
    }
}
