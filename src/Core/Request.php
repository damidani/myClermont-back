<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;

final class Request
{
    /** Paramètres de route ({id} ...) */
    public array $params = [];

    /** Données attachées par les middlewares (device, user_id, ...) */
    public array $attributes = [];

    private ?array $json = null;

    /**
     * @param array<string, string> $headers clés en minuscules
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $queryString,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $ip,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        // Les envois de fichiers (PDF) ont leur propre limite, plus haute
        $maxBody = match (true) {
            str_starts_with($path, '/v1/documents') => (int) Config::get('app.max_upload_size', 5242880),
            str_starts_with($path, '/v1/media')     => (int) Config::get('app.max_media_size', 52428800),
            default                                 => (int) Config::get('app.max_body_size', 1048576),
        };
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
            throw new HttpException(413, 'payload_too_large', 'Corps de requête trop volumineux.');
        }
        $body = (string) file_get_contents('php://input', false, null, 0, $maxBody + 1);
        if (strlen($body) > $maxBody) {
            throw new HttpException(413, 'payload_too_large', 'Corps de requête trop volumineux.');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            (string) ($_SERVER['QUERY_STRING'] ?? ''),
            $headers,
            $body,
            self::resolveIp($headers),
        );
    }

    /**
     * IP réelle du client. X-Forwarded-For n'est lu que si la connexion
     * vient d'un proxy de confiance (sinon il serait trivialement falsifiable).
     */
    public static function resolveIp(array $headers): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('app.trusted_proxies', []);
        if ($trusted && in_array($remote, $trusted, true) && isset($headers['x-forwarded-for'])) {
            $chain = array_map('trim', explode(',', $headers['x-forwarded-for']));
            // On remonte la chaîne depuis la droite en ignorant nos propres proxies
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if (!in_array($chain[$i], $trusted, true) && filter_var($chain[$i], FILTER_VALIDATE_IP)) {
                    return $chain[$i];
                }
            }
        }

        return $remote;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        return str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : null;
    }

    /** Corps JSON décodé (tableau associatif). */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->body === '') {
            return $this->json = [];
        }

        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'Le corps de la requête n\'est pas un JSON valide.');
        }
        if (!is_array($data)) {
            throw new HttpException(400, 'invalid_json', 'Le corps JSON doit être un objet.');
        }

        return $this->json = $data;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        parse_str($this->queryString, $query);
        return $query;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }
}
