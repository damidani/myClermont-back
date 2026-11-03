<?php

declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Database;

/**
 * Réglages notifications et e-mails modifiables depuis l'admin (c06e670d), stockés dans app_settings.
 */
final class Settings
{
    public const DEFAULTS = [
        'notifications' => ['daily_push_cap' => 8, 'group_gap_minutes' => 30, 'default_quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '08:00'],
                            'broadcasts_enabled' => true],
        'mail' => ['from_name' => 'My Clermont', 'reply_to' => null, 'footer' => "L'équipe My Clermont", 'broadcasts_enabled' => true],
    ];

    /** @var array<string, array> */
    private static array $cache = [];

    public static function get(string $section): array
    {
        if (!isset(self::$cache[$section])) {
            try {
                $row = Database::fetch('SELECT v FROM app_settings WHERE k = :k', ['k' => $section]);
            } catch (\PDOException) {
                $row = null;
            }
            self::$cache[$section] = array_replace_recursive(self::DEFAULTS[$section] ?? [], json_decode((string) ($row['v'] ?? ''), true) ?: []);
        }

        return self::$cache[$section];
    }

    public static function put(string $section, array $value, string $by): array
    {
        $merged = array_replace_recursive(self::DEFAULTS[$section], array_intersect_key($value, self::DEFAULTS[$section]));
        Database::execute('INSERT INTO app_settings (k, v, updated_at, updated_by) VALUES (:k, :v, :d, :b) ON DUPLICATE KEY UPDATE v = :v2, updated_at = :d2, updated_by = :b2',
            ['k' => $section, 'v' => json_encode($merged, JSON_UNESCAPED_UNICODE), 'd' => gmdate('Y-m-d H:i:s'), 'b' => $by,
             'v2' => json_encode($merged, JSON_UNESCAPED_UNICODE), 'd2' => gmdate('Y-m-d H:i:s'), 'b2' => $by]);
        unset(self::$cache[$section]);

        return self::get($section);
    }
}
