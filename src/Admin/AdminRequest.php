<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Request;

/** Requête du back-office : formulaire HTML classique (POST, cookies, fichiers). */
final class AdminRequest
{
    public ?array $admin = null;
    public ?array $session = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $files,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly ?string $origin,
        public readonly string $host,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_COOKIE, $_FILES,
            Request::resolveIp($headers), mb_substr($headers['user-agent'] ?? '', 0, 255), $headers['origin'] ?? null, $headers['host'] ?? '',
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($v) ? $v : $default;
    }
}
