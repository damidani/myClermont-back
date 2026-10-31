<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Schémas attendus par le décodeur iOS (brief back-end du 30/10/2026), vérifiés par les tests
 * et par `php bin/console schema:check` sur les vraies réponses.
 *
 * Règles : 'string', 'int', 'number', 'bool', 'array', 'string[]', 'int[]', 'enum:a|b', 'date', 'url', 'icon',
 * un sous-schéma (tableau), ou 'list:<schéma>' ; préfixe '?' = facultatif (absent ou null).
 * Les champs en plus sont ignorés, comme côté app.
 */
final class AppSchemas
{
    /** Noms SF Symbols autorisés pour les champs icon (liste blanche tenue ici). */
    public const ICONS = [
        'fork.knife', 'cup.and.saucer.fill', 'birthday.cake.fill', 'takeoutbag.and.cup.and.straw.fill', 'wineglass.fill',
        'iphone', 'laptopcomputer', 'desktopcomputer', 'briefcase.fill', 'hammer.fill', 'wrench.adjustable.fill',
        'wrench.and.screwdriver.fill', 'paintbrush.fill', 'house.fill', 'building.2.fill', 'cart.fill', 'bag.fill',
        'cross.case.fill', 'stethoscope', 'heart.fill', 'graduationcap.fill', 'book.fill', 'megaphone.fill',
        'person.2.fill', 'figure.run', 'dumbbell.fill', 'sportscourt.fill', 'pawprint.fill', 'car.fill', 'bicycle',
        'bus.fill', 'tram.fill', 'fuelpump.fill', 'scissors', 'sparkles', 'leaf.fill', 'tree.fill', 'mountain.2.fill',
        'music.note', 'theatermasks.fill', 'paintpalette.fill', 'camera.fill', 'ticket.fill', 'star.fill',
        'calendar', 'party.popper.fill', 'gift.fill', 'tag.fill', 'mappin.and.ellipse', 'map.fill', 'binoculars.fill',
        'sun.max.fill', 'cloud.sun.fill', 'drop.fill', 'fish.fill', 'basket.fill', 'truck.box.fill', 'shippingbox.fill',
        'chart.bar.fill', 'banknote.fill', 'globe', 'gearshape.fill', 'lightbulb.fill', 'bolt.fill', 'flame.fill',
        'building.columns.fill', 'figure.walk', 'figure.and.child.holdinghands', 'tshirt.fill', 'washer.fill',
    ];

    public const EVENT_KINDS = 'soiree|concert|spectacle|expo|famille|sport|marche|festival';
    public const MAP_CATEGORIES = 'incontournable|evenement|spot|restaurant|fastFood|cafe|boulangerie|culture|nature|sport|carburant|lieu';
    public const NATURE_KINDS = 'balade|panorama|peche|baignade|piqueNique|champignons|muguet';

    public static function event(): array
    {
        return [
            'id' => 'string', 'title' => 'string', 'kind' => 'enum:' . self::EVENT_KINDS, 'venue' => 'string', 'address' => 'string',
            'latitude' => '?number', 'longitude' => '?number', 'start' => 'date', 'end' => '?date', 'schedule' => '?string',
            'price' => '?string', 'ageLimit' => '?string', 'url' => '?string', 'allDay' => '?bool', 'summary' => 'string',
        ];
    }

    public static function userProfile(): array
    {
        return [
            'id' => 'string', 'provider' => 'enum:apple|google|email', 'firstName' => 'string', 'email' => 'string',
            'emailIsPrivate' => 'bool', 'phone' => '?string',
            'gender' => '?enum:woman|man|nonBinary|genderfluid|agender|transWoman|transMan|selfDescribed|undisclosed',
            'genderDetail' => '?string', 'pronouns' => '?enum:elle|il|iel|elleIl|custom|undisclosed', 'pronounsDetail' => '?string',
            'city' => 'string', 'postalCode' => '?string', 'cityServed' => 'bool', 'notifyCityLaunch' => 'bool',
            'marketingOptIn' => 'bool', 'consentVersion' => 'string', 'consentedAt' => 'date', 'createdAt' => 'date',
        ];
    }

    public static function session(): array
    {
        return ['accessToken' => 'string', 'refreshToken' => 'string', 'expiresIn' => '?int', 'user' => self::userProfile()];
    }

    public static function mapSpot(): array
    {
        return [
            'id' => 'string', 'name' => 'string', 'category' => 'enum:' . self::MAP_CATEGORIES, 'latitude' => 'number', 'longitude' => 'number',
            'subtitle' => 'string', 'phone' => '?string', 'website' => '?string', 'isPartner' => 'bool', 'demoName' => '?string',
            'natureKind' => '?enum:' . self::NATURE_KINDS,
        ];
    }

    public static function assistantAnswer(): array
    {
        return [
            'text' => 'string', 'followUps' => 'string[]',
            'cards' => 'list:' . json_encode([
                'icon' => 'icon', 'colors' => 'string[]', 'kicker' => 'string', 'title' => 'string', 'detail' => 'string',
                'badge' => '?string', 'isPartner' => 'bool', 'link' => 'array',
            ]),
        ];
    }

    public static function fuelStation(): array
    {
        return [
            'id' => 'string', 'address' => 'string', 'city' => 'string', 'latitude' => 'number', 'longitude' => 'number',
            'prices' => 'array', 'automate24h' => 'bool', 'distance' => '?int',
        ];
    }

    public static function trafficIncident(): array
    {
        return [
            'id' => 'string', 'kind' => 'enum:accident|jam|roadworks|closure|laneClosed|hazard|weather|breakdown|other', 'title' => 'string',
            'detail' => '?string', 'from' => '?string', 'to' => '?string', 'roads' => 'string[]', 'delay' => '?int', 'length' => '?int',
            'magnitude' => 'int', 'start' => '?date', 'end' => '?date', 'path' => 'array', 'distance' => '?int',
        ];
    }

    public static function job(): array
    {
        return [
            'id' => 'string', 'title' => 'string', 'company' => 'string', 'location' => 'string', 'salary' => 'string',
            'contract' => 'enum:CDI|CDD|Alternance|Stage|Freelance', 'workMode' => 'enum:Sur site|Hybride|Télétravail',
            'icon' => 'icon', 'summary' => 'string',
        ];
    }

    public static function housing(): array
    {
        return [
            'id' => 'string', 'title' => 'string', 'district' => 'string', 'price' => 'int', 'surface' => 'int', 'rooms' => 'int',
            'bedrooms' => 'int', 'type' => 'enum:Location|Achat|Colocation', 'furnished' => 'bool', 'images' => 'url[]',
            'features' => 'string[]', 'description' => 'string',
        ];
    }

    public static function deal(): array
    {
        return [
            'id' => 'string', 'title' => 'string', 'merchant' => 'string', 'area' => 'string',
            'category' => 'enum:Restaurants|Bien-être|Sorties|Sport', 'original' => 'string', 'reduced' => 'string',
            'discount' => 'string', 'exclusive' => 'bool', 'image' => 'url', 'description' => 'string', 'conditions' => 'string',
            'expires' => 'string',
        ];
    }

    public const SERVICE_CATEGORIES = 'Maison & travaux|Sport & bien-être|Informatique|Animaux|Auto & mobilité|Beauté & santé|Cours & formation|Services à domicile|Événementiel';

    public static function service(): array
    {
        return [
            'id' => 'string', 'name' => 'string', 'category' => 'enum:' . self::SERVICE_CATEGORIES, 'district' => 'string', 'icon' => 'icon',
            'description' => 'string', 'specialties' => 'string[]', 'hours' => 'string', 'price' => 'string', 'rating' => 'number',
            'latitude' => 'number', 'longitude' => 'number',
        ];
    }

    public static function agenda(): array
    {
        return [
            'id' => 'string', 'title' => 'string', 'category' => 'enum:Sport|Musique|Nature|Culture|Gastronomie|Famille', 'venue' => 'string',
            'date' => 'date', 'duration' => 'string', 'price' => 'string', 'image' => 'url', 'icon' => 'icon', 'description' => 'string',
            'program' => 'string[]', 'latitude' => 'number', 'longitude' => 'number',
        ];
    }

    public static function post(): array
    {
        return [
            'id' => 'string', 'author' => 'string', 'neighborhood' => 'string', 'date' => 'date',
            'kind' => 'enum:Texte|Sondage|Photos + texte|Photo + description|Vidéo', 'text' => 'string', 'mediaFiles' => 'url[]',
            'pollOptions' => 'string[]', 'votes' => 'int[]', 'myVote' => '?int', 'likes' => 'int', 'liked' => 'bool',
            'comments' => 'list:' . json_encode(self::comment()), 'pollQuestion' => '?string',
        ];
    }

    public static function comment(): array
    {
        return ['id' => 'string', 'author' => 'string', 'message' => 'string', 'date' => 'date'];
    }

    public const CLASSIFIED_CATEGORIES = 'Maison & déco|High-tech|Vélos & mobilité|Mode & accessoires|Sport & plein air|Jardin & bricolage|Livres & loisirs|Enfants & bébé|Musique|Jeux & collections|Dons & gratuit';

    public static function classified(): array
    {
        return [
            'id' => 'string', 'title' => 'string', 'category' => 'enum:' . self::CLASSIFIED_CATEGORIES, 'price' => 'int', 'district' => 'string',
            'description' => 'string', 'condition' => 'enum:Neuf|Comme neuf|Très bon état|Bon état|À réparer', 'seller' => 'string',
            'images' => 'url[]', 'created' => 'date', 'isDemo' => 'bool',
        ];
    }

    /** @return list<string> erreurs « chemin : problème » (vide si conforme) */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return ["{$path} : objet attendu"];
        }
        $errors = [];
        foreach ($schema as $field => $rule) {
            $p = "{$path}.{$field}";
            if (is_string($rule) && str_starts_with($rule, '?')) {
                if (!array_key_exists($field, $value) || $value[$field] === null) {
                    continue;
                }
                $rule = substr($rule, 1);
            } elseif (!array_key_exists($field, $value) || $value[$field] === null) {
                $errors[] = "{$p} : manquant ou null";
                continue;
            }
            $errors = [...$errors, ...self::check($value[$field], $rule, $p)];
        }

        return $errors;
    }

    private static function check(mixed $v, string|array $rule, string $p): array
    {
        if (is_array($rule)) {
            return self::validate($v, $rule, $p);
        }
        if (str_starts_with($rule, 'list:')) {
            if (!is_array($v) || !array_is_list($v)) {
                return ["{$p} : tableau attendu"];
            }
            $sub = json_decode(substr($rule, 5), true);
            $errors = [];
            foreach ($v as $i => $item) {
                $errors = [...$errors, ...self::validate($item, $sub, "{$p}[{$i}]")];
            }

            return $errors;
        }
        if (str_ends_with($rule, '[]')) {
            if (!is_array($v) || !array_is_list($v)) {
                return ["{$p} : tableau attendu"];
            }
            $errors = [];
            foreach ($v as $i => $item) {
                $errors = [...$errors, ...self::check($item, substr($rule, 0, -2), "{$p}[{$i}]")];
            }

            return $errors;
        }
        if (str_starts_with($rule, 'enum:')) {
            $allowed = explode('|', substr($rule, 5));

            return in_array($v, $allowed, true) ? [] : ["{$p} : " . json_encode($v, JSON_UNESCAPED_UNICODE) . ' hors de ' . implode(', ', $allowed)];
        }
        $ok = match ($rule) {
            'string' => is_string($v),
            'int'    => is_int($v),
            'number' => is_int($v) || is_float($v),
            'bool'   => is_bool($v),
            'array'  => is_array($v),
            'date'   => is_string($v) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(Z|[+-]\d\d:\d\d)$/', $v) === 1,
            'url'    => is_string($v) && str_starts_with($v, 'https://') && !str_contains($v, 'picsum.photos'),
            'icon'   => is_string($v) && in_array($v, self::ICONS, true),
            default  => false,
        };

        return $ok ? [] : ["{$p} : " . json_encode($v, JSON_UNESCAPED_UNICODE) . " n'est pas {$rule}"];
    }
}
