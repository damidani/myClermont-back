<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Client HTTP des imports : HTTPS uniquement, délais courts, taille plafonnée,
 * nouvelles tentatives sur 429 / 5xx (en respectant Retry-After), politesse entre deux appels.
 *
 * Les tests remplacent le réseau par HttpClient::fake(fn (string $method, string $url, array $headers, ?string $body) => [status, headers, body]).
 */
final class HttpClient
{
    public const USER_AGENT = 'myClermont-importer/2.0 (+https://myclermont.app/robots)';

    private static ?\Closure $fake = null;

    /** @var array<string, float> dernier appel par hôte (politesse) */
    private static array $lastCall = [];

    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
        private readonly int $maxBytes = 50_000_000,
        private readonly int $delayMs = 0,
    ) {
    }

    public static function fake(?\Closure $handler): void
    {
        self::$fake = $handler;
        self::$lastCall = [];
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('URL refusée (HTTPS requis) : ' . $url);
        }

        for ($attempt = 0; ; $attempt++) {
            $this->politeness($url);
            $response = self::$fake !== null
                ? array_combine(['status', 'headers', 'body'], (self::$fake)($method, $url, $headers, $body))
                : $this->send($method, $url, $headers, $body);

            $retryable = $response['status'] === 429 || $response['status'] >= 500 || $response['status'] === 0;
            if (!$retryable || $attempt >= $this->retries) {
                return $response;
            }
            $wait = (int) ($response['headers']['retry-after'] ?? 0) ?: 2 ** $attempt;
            if (self::$fake === null) {
                sleep(min($wait, 30));
            }
        }
    }

    /** GET qui doit réussir (2xx), sinon exception. */
    public function get(string $url, array $headers = []): array
    {
        $r = $this->request('GET', $url, $headers);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException("HTTP {$r['status']} sur " . self::redact($url));
        }

        return $r;
    }

    public function getJson(string $url, array $headers = []): mixed
    {
        return json_decode($this->get($url, array_merge(['Accept: application/json'], $headers))['body'], true, 128, JSON_THROW_ON_ERROR);
    }

    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        $responseHeaders = [];
        $size = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_ENCODING        => '',   // gzip / br acceptés
            CURLOPT_USERAGENT       => self::USER_AGENT,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HEADERFUNCTION  => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            // Coupe le téléchargement au-delà de maxBytes (flux anormal)
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => function ($ch, $dlTotal, $dlNow) use (&$size): int {
                $size = $dlNow;
                return $dlNow > $this->maxBytes ? 1 : 0;
            },
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            if ($size > $this->maxBytes) {
                throw new \RuntimeException('Réponse trop volumineuse : ' . self::redact($url));
            }
            return ['status' => 0, 'headers' => [], 'body' => $error];
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result];
    }

    private function politeness(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($this->delayMs > 0 && isset(self::$lastCall[$host]) && self::$fake === null) {
            $wait = self::$lastCall[$host] + $this->delayMs / 1000 - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        self::$lastCall[$host] = microtime(true);
    }

    /** URL sans paramètres (jetons éventuels) pour les messages d'erreur. */
    public static function redact(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}
