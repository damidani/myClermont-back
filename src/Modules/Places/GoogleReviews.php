<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Cache\Cache;
use App\Content\Import\HttpClient;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Avis Google d'un lieu (303b2796), lus côté serveur par l'API Places (New).
 * Conditions Google : seul le Place ID est gardé en base ; note et avis restent 24 h en cache, avec l'attribution.
 * Correspondance : recherche texte (nom + commune) autour de la position du lieu, acceptée à moins de 250 m.
 * Une correction manuelle se fait dans places.google_place_id (vide = pas de correspondance, ne plus chercher).
 */
final class GoogleReviews
{
    private const API = 'https://places.googleapis.com/v1';
    private const MAX_DISTANCE = 250;

    public static function configured(): bool
    {
        return (string) Config::get('places.google_api_key', '') !== '';
    }

    /** Réponse pour l'app, ou null (pas de clé, pas de correspondance, Google indisponible) */
    public static function forPlace(array $place): ?array
    {
        if (!self::configured()) {
            return null;
        }
        $googleId = self::googleId($place);
        if ($googleId === null) {
            return null;
        }
        $key = 'google:reviews:' . sha1($googleId);
        $cached = Cache::store()->get($key);
        if (is_array($cached)) {
            return $cached ?: null;
        }
        $details = self::call('GET', '/places/' . rawurlencode($googleId) . '?languageCode=fr', 'rating,userRatingCount,reviews,googleMapsUri');
        if ($details === null) {
            return null;
        }
        $out = [
            'average' => isset($details['rating']) ? round((float) $details['rating'], 1) : null,
            'total' => (int) ($details['userRatingCount'] ?? 0),
            'url' => $details['googleMapsUri'] ?? null,
            'attribution' => 'Google',
            'reviews' => array_values(array_map(static fn (array $r) => [
                'id' => (string) ($r['name'] ?? sha1(json_encode($r))),
                'author' => (string) ($r['authorAttribution']['displayName'] ?? ''),
                'rating' => (int) ($r['rating'] ?? 0),
                'text' => (string) ($r['text']['text'] ?? $r['originalText']['text'] ?? ''),
                'relative_time' => (string) ($r['relativePublishTimeDescription'] ?? ''),
                'profile_photo' => $r['authorAttribution']['photoUri'] ?? null,
                'author_url' => $r['authorAttribution']['uri'] ?? null,
                'language' => $r['text']['languageCode'] ?? $r['originalText']['languageCode'] ?? null,
            ], array_slice((array) ($details['reviews'] ?? []), 0, 5))),
        ];
        $out = $out['total'] > 0 || $out['reviews'] !== [] ? $out : [];
        Cache::store()->set($key, $out, (int) Config::get('places.google_cache_seconds', 86400));

        return $out ?: null;
    }

    /** Place ID Google du lieu, cherché une fois (puis tous les 30 jours si rien n'a été trouvé) */
    private static function googleId(array $place): ?string
    {
        if ($place['google_place_id'] !== null) {
            return $place['google_place_id'] !== '' ? $place['google_place_id'] : null;
        }
        if ($place['google_checked_at'] !== null && strtotime($place['google_checked_at'] . ' UTC') > time() - 30 * 86400) {
            return null;
        }
        if (!Cache::store()->add('google:match:' . $place['id'], 60)) {
            return null; // une recherche est déjà en cours pour ce lieu
        }
        $found = null;
        $lat = (float) $place['latitude'];
        $lon = (float) $place['longitude'];
        $r = self::call('POST', '/places:searchText', 'places.id,places.displayName,places.location', [
            'textQuery' => trim($place['name'] . ' ' . ($place['city'] ?: 'Clermont-Ferrand')),
            'languageCode' => 'fr', 'maxResultCount' => 3,
            'locationBias' => ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lon], 'radius' => 500.0]],
        ]);
        foreach ((array) ($r['places'] ?? []) as $g) {
            $d = self::meters($lat, $lon, (float) ($g['location']['latitude'] ?? 0), (float) ($g['location']['longitude'] ?? 0));
            if ($d <= self::MAX_DISTANCE) {
                $found = (string) $g['id'];
                break;
            }
        }
        if ($r !== null) {
            Database::execute('UPDATE places SET google_place_id = :g, google_checked_at = :d WHERE id = :id',
                ['g' => $found, 'd' => gmdate('Y-m-d H:i:s'), 'id' => $place['id']]);
        }

        return $found;
    }

    private static function call(string $method, string $path, string $fields, ?array $body = null): ?array
    {
        try {
            $r = (new HttpClient(timeout: 6, retries: 0, delayMs: 0))->request($method, self::API . $path, array_filter([
                'X-Goog-Api-Key: ' . Config::get('places.google_api_key'),
                'X-Goog-FieldMask: ' . $fields,
                $body !== null ? 'Content-Type: application/json' : null,
            ]), $body !== null ? json_encode($body) : null);
        } catch (\Throwable $e) {
            Logger::warning('Google Places : appel en échec', ['error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
        if ($r['status'] !== 200) {
            Logger::warning('Google Places : réponse inattendue', ['http' => $r['status'], 'body' => mb_substr((string) $r['body'], 0, 200)]);
            return null;
        }

        return json_decode((string) $r['body'], true) ?: [];
    }

    private static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $p / 2) ** 2 + cos($lat1 * $p) * cos($lat2 * $p) * sin(($lon2 - $lon1) * $p / 2) ** 2;

        return 12742000 * asin(min(1, sqrt($a)));
    }
}
