<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Exceptions\ValidationException;

/**
 * Profil du parcours d'inscription de l'app iOS (brief back-end §6) : ProfileBody en entrée, UserProfile en sortie.
 * Les clés d'entrée arrivent déjà en snake_case (Request::snake).
 */
final class UserProfile
{
    public const GENDERS = ['woman', 'man', 'nonBinary', 'genderfluid', 'agender', 'transWoman', 'transMan', 'selfDescribed', 'undisclosed'];
    public const PRONOUNS = ['elle', 'il', 'iel', 'elleIl', 'custom', 'undisclosed'];

    /** Champs en lecture seule, ignorés s'ils sont renvoyés par l'app (PATCH /me avec le UserProfile entier) */
    private const READ_ONLY = ['id', 'provider', 'email', 'email_is_private', 'consented_at', 'created_at'];

    /**
     * Valide un ProfileBody et le convertit en colonnes de `users`.
     *
     * @param bool $complete true : inscription ou complétion (tous les champs obligatoires), false : modification partielle
     * @return array<string, mixed> colonnes
     */
    public static function columns(array $in, bool $complete, int $status = 422): array
    {
        $in = array_diff_key($in, array_flip(self::READ_ONLY));
        $has = static fn (string $k) => array_key_exists($k, $in);
        $str = static fn (string $k) => is_string($in[$k] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $in[$k])) : null;
        $errors = [];
        $cols = [];

        if ($complete || $has('first_name')) {
            $name = $str('first_name');
            if ($name === null || mb_strlen($name) < 2 || mb_strlen($name) > 40) {
                $errors['first_name'] = 'Ton prénom doit faire entre 2 et 40 caractères.';
            } else {
                $cols['first_name'] = $name;
            }
        }
        if ($complete || $has('city')) {
            $city = $str('city');
            if ($city === null || $city === '' || mb_strlen($city) > 100) {
                $errors['city'] = 'Indique ta ville.';
            } else {
                $cols['city'] = $city;
            }
        }
        if ($has('phone')) {
            $phone = $in['phone'] === null ? null : preg_replace('/[\s.\-]/', '', (string) $in['phone']);
            if ($phone !== null && $phone !== '' && !preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
                $errors['phone'] = 'Ton numéro de téléphone n\'est pas valide (ex. +33612345678).';
            } else {
                $cols['phone'] = $phone === '' ? null : $phone;
            }
        }
        if ($has('postal_code')) {
            $pc = $in['postal_code'] === null ? null : trim((string) $in['postal_code']);
            if ($pc !== null && $pc !== '' && !preg_match('/^\d{5}$/', $pc)) {
                $errors['postal_code'] = 'Le code postal doit faire 5 chiffres.';
            } else {
                $cols['postal_code'] = $pc === '' ? null : $pc;
            }
        }
        foreach (['gender' => self::GENDERS, 'pronouns' => self::PRONOUNS] as $field => $allowed) {
            if ($has($field)) {
                if ($in[$field] !== null && !in_array($in[$field], $allowed, true)) {
                    $errors[$field] = $field === 'gender' ? 'Genre inconnu.' : 'Pronoms inconnus.';
                } else {
                    $cols[$field] = $in[$field];
                }
            }
        }
        // Texte libre seulement pour « selfDescribed » / « custom »
        foreach (['gender_detail' => ['gender', 'selfDescribed'], 'pronouns_detail' => ['pronouns', 'custom']] as $field => [$parent, $free]) {
            if ($has($field) || array_key_exists($parent, $cols)) {
                $detail = $str($field);
                $cols[$field] = ($cols[$parent] ?? null) === $free && $detail !== '' ? ($detail === null ? null : mb_substr($detail, 0, $field === 'gender_detail' ? 60 : 40)) : null;
            }
        }
        foreach (['city_served', 'notify_city_launch', 'marketing_opt_in'] as $flag) {
            if ($has($flag)) {
                if (!is_bool($in[$flag])) {
                    $errors[$flag] = 'Valeur attendue : vrai ou faux.';
                } else {
                    $cols[$flag] = $in[$flag] ? 1 : 0;
                }
            }
        }
        if ($complete || $has('consent_version')) {
            $v = $str('consent_version');
            if ($v === null || !preg_match('/^[\w.\-]{1,20}$/', $v)) {
                $errors['consent_version'] = 'Accepte les conditions d\'utilisation pour continuer.';
            } else {
                $cols['consent_version'] = $v;
            }
        }
        if ($complete) {
            if (($in['age_confirmed'] ?? null) !== true) {
                $errors['age_confirmed'] = 'Tu dois confirmer avoir l\'âge requis pour créer un compte.';
            } else {
                $cols['age_confirmed_at'] = gmdate('Y-m-d H:i:s');
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors, $status);
        }

        return $cols;
    }

    /** Règle de l'app : 8 caractères minimum, au moins une lettre et un chiffre, rien de plus. */
    public static function checkPassword(mixed $password, int $status = 422): string
    {
        if (!is_string($password) || mb_strlen($password) < 8 || mb_strlen($password) > 128
            || !preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException(['password' => 'Ton mot de passe doit faire au moins 8 caractères, avec une lettre et un chiffre.'], $status);
        }

        return $password;
    }

    public static function isComplete(array $user): bool
    {
        return !empty($user['consent_version']) && !empty($user['first_name']) && !empty($user['city']);
    }

    public static function provider(array $user): string
    {
        return match (true) {
            !empty($user['apple_sub'])     => 'apple',
            !empty($user['password_hash']) => 'email',
            !empty($user['google_sub'])    => 'google',
            default                        => 'email',
        };
    }

    /** UserProfile (champs en snake_case, convertis par le contrat JSON) */
    public static function present(array $user): array
    {
        $email = (string) ($user['email'] ?? '');
        $iso = static fn (?string $d) => $d === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($d . ' UTC'));

        return [
            'id'                 => $user['uid'],
            'provider'           => self::provider($user),
            'first_name'         => (string) ($user['first_name'] ?? $user['display_name'] ?? ''),
            'email'              => $email,
            'email_is_private'   => str_ends_with(mb_strtolower($email), 'privaterelay.appleid.com'),
            'phone'              => $user['phone'] ?? null,
            'gender'             => $user['gender'] ?? null,
            'gender_detail'      => $user['gender_detail'] ?? null,
            'pronouns'           => $user['pronouns'] ?? null,
            'pronouns_detail'    => $user['pronouns_detail'] ?? null,
            'city'               => (string) ($user['city'] ?? ''),
            'postal_code'        => $user['postal_code'] ?? null,
            'city_served'        => (bool) ($user['city_served'] ?? false),
            'notify_city_launch' => (bool) ($user['notify_city_launch'] ?? false),
            'marketing_opt_in'   => (bool) ($user['marketing_opt_in'] ?? false),
            'consent_version'    => (string) ($user['consent_version'] ?? ''),
            'consented_at'       => $iso($user['consented_at'] ?? null) ?? $iso($user['created_at']),
            'created_at'         => $iso($user['created_at']),
        ];
    }
}
