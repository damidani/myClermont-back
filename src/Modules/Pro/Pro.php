<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;
use App\Support\Mailer;

/**
 * Outils communs de l'espace commerçant : établissement courant, rôles, limites de la formule,
 * notifications, dates, e-mails.
 */
final class Pro
{
    public const ROLES = ['proprietaire', 'gerant', 'equipier'];

    /** Établissement de la session (attaché par ProAuthMiddleware). */
    public static function business(Request $request): array
    {
        return $request->attributes['pro_business'];
    }

    /** Relit l'établissement après une modification (formule, modules…). */
    public static function reload(Request $request): Request
    {
        $request->attributes['pro_business'] = Database::fetch('SELECT * FROM pro_businesses WHERE id = :id', ['id' => self::business($request)['id']]);

        return $request;
    }

    /** Nom de la fiche (affiché comme commerçant dans l'app : bons plans, offres, messages). */
    public static function displayName(array $business): string
    {
        return (string) (Database::fetch('SELECT name FROM places WHERE id = :id', ['id' => $business['place_id']])['name'] ?? $business['trade_name']);
    }

    public static function member(Request $request): array
    {
        return $request->attributes['pro_member'];
    }

    /** Fiche (places) de l'établissement. */
    public static function place(Request $request): array
    {
        $b = self::business($request);

        return Database::fetch('SELECT * FROM places WHERE id = :id', ['id' => $b['place_id']])
            ?? throw new HttpException(404, 'not_found', 'Fiche introuvable.');
    }

    /**
     * Rôle minimal requis : "gerant" (propriétaire ou gérant), "proprietaire".
     * L'équipier n'écrit que là où la route ne demande rien (codes, messages, avis).
     */
    public static function requireRole(Request $request, string $min): void
    {
        $role = self::member($request)['role'];
        $ok = match ($min) {
            'proprietaire' => $role === 'proprietaire',
            'gerant'       => in_array($role, ['proprietaire', 'gerant'], true),
            default        => true,
        };
        if (!$ok) {
            throw new HttpException(403, 'forbidden', 'Ton rôle ne permet pas cette action.', ['reason' => 'role']);
        }
    }

    /** @return array{active_deals: ?int, team: int, photos: int, stats_days: int, csv_export: bool, flash: bool} */
    public static function limits(array $business): array
    {
        return Config::get('pro.plans.' . $business['plan']) ?? Config::get('pro.plans.gratuit');
    }

    public static function planLimit(string $limit, string $message): HttpException
    {
        return new HttpException(403, 'forbidden', $message, ['reason' => 'plan_limit', 'limit' => $limit]);
    }

    /** Notification de l'espace pro ; l'e-mail suit si le propriétaire l'a demandé (pref = clé de NotificationPrefs). */
    public static function notify(int $businessId, string $kind, string $title, string $body, string $href, ?string $pref = null): void
    {
        Database::insert(
            'INSERT INTO pro_notifications (uid, business_id, kind, title, body, href, created_at) VALUES (:u, :b, :k, :t, :body, :h, :d)',
            ['u' => Crypto::uuid4(), 'b' => $businessId, 'k' => $kind, 't' => mb_substr($title, 0, 160), 'body' => mb_substr($body, 0, 500),
             'h' => $href, 'd' => gmdate('Y-m-d H:i:s')],
        );
        if ($pref === null) {
            return;
        }
        foreach (Database::fetchAll("SELECT email, notifications FROM pro_members WHERE business_id = :b AND status = 'actif' AND role IN ('proprietaire', 'gerant')", ['b' => $businessId]) as $m) {
            if ((self::prefs($m['notifications'])[$pref] ?? false) === true) {
                self::mail($m['email'], $title, $body . "\n\n" . Config::get('pro.front_url') . $href);
            }
        }
    }

    /** Notification au commerçant propriétaire d'un contenu (offre, prestation, logement) reçu depuis l'app. */
    public static function notifyOwner(string $table, int $id, string $kind, string $title, string $body, string $href, string $pref): void
    {
        $row = Database::fetch("SELECT business_id FROM {$table} WHERE id = :id", ['id' => $id]);
        if (($row['business_id'] ?? null) !== null) {
            self::notify((int) $row['business_id'], $kind, $title, mb_substr($body, 0, 300), $href, $pref);
        }
    }

    public static function prefs(?string $json): array
    {
        $defaults = ['new_review' => true, 'new_message' => true, 'new_application' => true, 'deal_codes' => false, 'weekly_report' => true, 'channel' => 'email'];

        return array_replace($defaults, array_intersect_key(json_decode((string) $json, true) ?: [], $defaults));
    }

    /** E-mail best effort : sans SMTP configuré, l'envoi échoue sans bloquer la requête. */
    public static function mail(string $to, string $subject, string $body): bool
    {
        try {
            return (new Mailer())->send($to, $subject, $body . "\n\n— MyClermont Pro");
        } catch (\Throwable) {
            return false;
        }
    }

    /** Établissement géré par un lieu (pour les notifications déclenchées par l'app). */
    public static function businessOfPlace(?int $placeId): ?array
    {
        return $placeId ? Database::fetch('SELECT * FROM pro_businesses WHERE place_id = :p AND deleted_at IS NULL', ['p' => $placeId]) : null;
    }

    public static function iso(?string $utc): ?string
    {
        return $utc ? gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC')) : null;
    }

    /** Date de Paris avec son décalage (+01:00 / +02:00), pour les événements. */
    public static function paris(?string $utc): ?string
    {
        return $utc ? (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i:sP') : null;
    }

    /** ISO 8601 (avec ou sans décalage ; sans = heure de Paris) → UTC « Y-m-d H:i:s ». */
    public static function toUtc(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $d = new \DateTimeImmutable((string) $value, new \DateTimeZone('Europe/Paris'));
        } catch (\Exception) {
            throw new ValidationException([$field => 'Date invalide.']);
        }

        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Chaîne bornée (null si absente). */
    public static function str(array $data, string $key, int $max, array &$errors, ?string $label = null): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            $errors[$key] = ($label ?? 'Ce champ') . ' doit être un texte.';
            return null;
        }
        $v = trim($data[$key]);
        if (mb_strlen($v) > $max) {
            $errors[$key] = ($label ?? 'Ce champ') . " : {$max} caractères au plus.";
        }

        return $v;
    }

    /** URL http(s) ou vide. */
    public static function url(mixed $v): bool
    {
        return $v === null || $v === '' || (is_string($v) && preg_match('#^https?://[^\s]+$#i', $v) && filter_var($v, FILTER_VALIDATE_URL));
    }

    /** Mot de passe : 8 caractères, une lettre et un chiffre. */
    public static function checkPassword(mixed $password, string $field = 'password'): string
    {
        if (!is_string($password) || mb_strlen($password) < 8 || mb_strlen($password) > 128 || !preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
            throw new ValidationException([$field => 'Le mot de passe doit faire au moins 8 caractères, avec au moins une lettre et un chiffre.']);
        }

        return $password;
    }

    /** Pagination par décalage : cursor = position suivante. */
    public static function page(Request $request, int $default = 30): array
    {
        $q = $request->query();

        return [min(100, max(1, (int) ($q['limit'] ?? $default))), isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : 0];
    }

    /** « Camille R. » */
    public static function shortName(?string $first, ?string $last = null, ?string $fallback = null): string
    {
        $first = trim((string) $first);
        if ($first === '') {
            return trim((string) $fallback) ?: 'Utilisateur';
        }
        $last = trim((string) $last);

        return $first . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : '');
    }

    /** Euros affichés : 48 → « 48 € », 12.5 → « 12,50 € » */
    public static function euros(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        return ($cents % 100 === 0 ? number_format($cents / 100, 0, ',', ' ') : number_format($cents / 100, 2, ',', ' ')) . ' €';
    }

    /** « 48 € », « 12,50 » → centimes */
    public static function cents(mixed $label): ?int
    {
        if (is_int($label) || is_float($label)) {
            return (int) round($label * 100);
        }
        if (!is_string($label) || !preg_match('/(\d[\d\s\x{202F}\x{00A0}]*(?:[.,]\d{1,2})?)/u', $label, $m)) {
            return null;
        }

        return (int) round((float) str_replace([',', ' ', "\u{202F}", "\u{00A0}"], ['.', '', '', ''], $m[1]) * 100);
    }
}
