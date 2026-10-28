<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Toutes les réponses suivent la même enveloppe JSON :
 *   succès : {"success": true,  "data": ..., "meta": ...}
 *   erreur : {"success": false, "error": {"code": "...", "message": "...", "details": ...}}
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status = 200,
        public ?array $payload = null,
        public array $headers = [],
    ) {
    }

    public static function success(mixed $data = null, int $status = 200, ?array $meta = null): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return new self($status, $payload);
    }

    public static function error(string $code, string $message, int $status = 400, mixed $details = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return new self($status, ['success' => false, 'error' => $error]);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /** Corps non-JSON (fichier .ics, etc.). */
    public static function raw(string $body, string $contentType, int $status = 200): self
    {
        $response = new self($status, null, ['Content-Type' => $contentType]);
        $response->rawBody = $body;

        return $response;
    }

    public ?string $rawBody = null;

    /** JSON déjà encodé (calcul de l'ETag) : évite de l'encoder deux fois. */
    public ?string $body = null;

    /** Écrit la réponse au fil de l'eau (Server-Sent Events) : chaque morceau est envoyé dès qu'il est prêt. */
    public ?\Closure $streamer = null;

    /**
     * @param \Closure(\Closure(string $event, array $data): void $emit): void $writer
     */
    public static function eventStream(\Closure $writer): self
    {
        $response = new self(200, null, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'X-Accel-Buffering' => 'no', // Nginx : ne pas mettre le flux en mémoire tampon
        ]);
        $response->streamer = $writer;

        return $response;
    }

    /** 304 : le client a déjà la bonne version (ETag). */
    public static function notModified(string $etag): self
    {
        return new self(304, null, ['ETag' => $etag]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->rawBody !== null) {
            echo $this->rawBody;
            return;
        }
        if ($this->streamer !== null) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ($this->streamer)(static function (string $event, array $data): void {
                echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                flush();
            });
            return;
        }
        if ($this->status === 304) {
            return;
        }
        if ($this->status === 204 || $this->payload === null) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $this->body ?? json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
