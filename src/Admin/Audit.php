<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Database;
use App\Core\Logger;

/** Journal de toutes les actions du back-office (qui, quoi, quand, avant / après). */
final class Audit
{
    public static function log(?int $adminId, string $action, string $ip, ?string $resource = null, ?string $key = null, ?array $changes = null): void
    {
        try {
            Database::execute(
                'INSERT INTO admin_audit_log (admin_id, action, resource, record_key, changes, ip, created_at) VALUES (:a, :act, :r, :k, :c, :ip, :d)',
                ['a' => $adminId, 'act' => $action, 'r' => $resource, 'k' => $key, 'ip' => $ip, 'd' => gmdate('Y-m-d H:i:s'),
                 'c' => $changes !== null ? json_encode(self::redact($changes), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null],
            );
        } catch (\Throwable $e) {
            Logger::error('Audit admin impossible : ' . $e->getMessage(), ['action' => $action, 'resource' => $resource]);
        }
    }

    /** @return list<array> */
    public static function history(string $resource, string $key, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT l.action, l.changes, l.created_at, l.ip, a.name AS admin_name FROM admin_audit_log l LEFT JOIN admin_users a ON a.id = l.admin_id
             WHERE l.resource = :r AND l.record_key = :k ORDER BY l.id DESC LIMIT :n',
            ['r' => $resource, 'k' => $key, 'n' => $limit],
        );
    }

    /** Les secrets ne vont jamais dans le journal, même hachés. */
    private static function redact(array $changes): array
    {
        foreach ($changes as $column => $value) {
            if (preg_match('/password|secret|token/', (string) $column)) {
                $changes[$column] = '[modifié]';
            }
        }

        return $changes;
    }
}
