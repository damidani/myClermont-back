<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $errors champ => message
     */
    public function __construct(array $errors)
    {
        parent::__construct(422, 'validation_failed', 'Les données envoyées sont invalides.', $errors);
    }
}
