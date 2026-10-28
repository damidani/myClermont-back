<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception convertie automatiquement en réponse JSON d'erreur par App.
 * Le code HTTP est porté par getCode().
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly mixed $details = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function unauthorized(string $errorCode, string $message = 'Requête non autorisée.'): self
    {
        return new self(401, $errorCode, $message);
    }

    public static function forbidden(string $errorCode, string $message = 'Accès refusé.'): self
    {
        return new self(403, $errorCode, $message);
    }
}
