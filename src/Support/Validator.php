<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;

/**
 * Validateur minimaliste.
 *
 *   $data = Validator::validate($request->json(), [
 *       'email'    => 'required|email|max:191',
 *       'password' => 'required|string|min:8|max:128',
 *       'platform' => 'required|in:ios,android',
 *       'model'    => 'nullable|string|max:100',
 *   ]);
 *
 * Retourne uniquement les champs déclarés (les champs inattendus sont ignorés).
 * Les chaînes sont « trimmées ».
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $present = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            if (!$present) {
                if (in_array('required', $rules, true)) {
                    $errors[$field] = 'Ce champ est obligatoire.';
                } elseif (array_key_exists($field, $data)) {
                    $clean[$field] = null; // champ explicitement vidé
                }
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = self::check($name, $arg, $value);
                if ($error !== null) {
                    $errors[$field] = $error;
                    continue 2;
                }
            }

            $clean[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    private static function check(string $rule, ?string $arg, mixed $value): ?string
    {
        return match ($rule) {
            'required', 'nullable' => null,
            'string'  => is_string($value) ? null : 'Doit être une chaîne de caractères.',
            'integer' => is_int($value) ? null : 'Doit être un entier.',
            'boolean' => is_bool($value) ? null : 'Doit être un booléen.',
            'email'   => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Adresse e-mail invalide.',
            'username' => is_string($value) && preg_match('/^(?!\.)[A-Za-z0-9_.]{3,30}(?<!\.)$/', $value) ? null : '3 à 30 caractères : lettres, chiffres, « _ » et « . ».',
            'uuid'    => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) ? null : 'UUID invalide.',
            'min'     => self::size($value) >= (int) $arg ? null : (is_string($value) ? "Au moins {$arg} caractères." : (is_array($value) ? "Au moins {$arg} éléments." : "Minimum {$arg}.")),
            'max'     => self::size($value) <= (int) $arg ? null : (is_string($value) ? "{$arg} caractères maximum." : (is_array($value) ? "{$arg} éléments maximum." : "Maximum {$arg}.")),
            'in'      => in_array($value, explode(',', (string) $arg), true) ? null : 'Valeur non autorisée.',
            default   => throw new \LogicException("Règle de validation inconnue : {$rule}"),
        };
    }

    private static function size(mixed $value): int|float
    {
        return match (true) {
            is_string($value) => mb_strlen($value),
            is_array($value)  => count($value),
            is_numeric($value) => $value + 0,
            default => 0,
        };
    }
}
