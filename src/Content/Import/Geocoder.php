<?php

declare(strict_types=1);

namespace App\Content\Import;

use App\Cache\Cache;
use App\Core\Config;

/**
 * Position approximative (centre de la commune ou du quartier) à partir d'un libellé,
 * via le géocodeur national de l'IGN / Base Adresse Nationale (gratuit, sans clé).
 *
 * Jamais d'adresse exacte : une annonce importée est placée au centre de sa commune / de son quartier.
 * Résultats mis en cache 30 jours : un import de 500 annonces sur 10 communes = 10 appels.
 */
final class Geocoder
{
    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 10, retries: 1, delayMs: 120))
    {
    }

    /**
     * Adresse précise (événements : lieu public, l'adresse est affichée de toute façon).
     *
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    public function address(string $address): ?array
    {
        $address = trim($address);
        if (mb_strlen($address) < 3) {
            return null;
        }
        $key = 'geocode:addr:' . sha1(mb_strtolower($address));
        if (is_array($cached = Cache::store()->get($key))) {
            return $cached ?: null;
        }
        try {
            // Biais vers Clermont : les adresses sans ville (« 15 avenue du Roussillon ») tombent dans la métropole
            $data = $this->http->getJson(Config::get('imports.geocoder_url', 'https://data.geopf.fr/geocodage/search') . '?'
                . http_build_query(['q' => $address, 'limit' => 1, 'index' => 'address', 'lat' => 45.7772, 'lon' => 3.0870]));
        } catch (\Throwable) {
            return null;
        }
        $feature = $data['features'][0] ?? null;
        $result = $feature && ($feature['properties']['score'] ?? 0) >= 0.5
            ? [round((float) $feature['geometry']['coordinates'][1], 6), round((float) $feature['geometry']['coordinates'][0], 6)]
            : null;
        Cache::store()->set($key, $result ?? [], 30 * 86400);

        return $result;
    }

    /** @return array{0: float, 1: float}|null [latitude, longitude] */
    public function locate(?string $city, ?string $postalCode = null, ?string $district = null): ?array
    {
        $city = trim((string) $city);
        if ($city === '' && !$postalCode) {
            return null;
        }
        $query = trim(($district ? $district . ' ' : '') . $city);
        $key = 'geocode:' . sha1(mb_strtolower($query . '|' . $postalCode));
        $cached = Cache::store()->get($key);
        if (is_array($cached)) {
            return $cached ?: null;
        }

        $result = null;
        try {
            $params = ['q' => $query !== '' ? $query : (string) $postalCode, 'limit' => 1, 'index' => 'address'];
            if ($postalCode) {
                $params['postcode'] = $postalCode;
            }
            if (!$district) {
                $params['type'] = 'municipality';
            }
            $data = $this->http->getJson(Config::get('imports.geocoder_url', 'https://data.geopf.fr/geocodage/search') . '?' . http_build_query($params));
            $feature = $data['features'][0] ?? null;
            if ($feature && ($feature['properties']['score'] ?? 0) >= 0.4) {
                [$lon, $lat] = $feature['geometry']['coordinates'];
                $result = [round((float) $lat, 5), round((float) $lon, 5)];
            }
        } catch (\Throwable) {
            return null; // le géocodage est un bonus : jamais bloquant, et non mis en cache en cas de panne
        }
        Cache::store()->set($key, $result ?? [], 30 * 86400);

        return $result;
    }
}
