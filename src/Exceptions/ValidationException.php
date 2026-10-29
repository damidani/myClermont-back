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
        // Le premier message est affichable tel quel dans l'app (spec : « Le titre doit faire au moins 3 caractères. »)
        parent::__construct(400, 'validation_failed', (string) (reset($errors) ?: 'Les données envoyées sont invalides.'), $errors);
    }
}
