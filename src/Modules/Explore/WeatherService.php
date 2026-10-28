<?php

declare(strict_types=1);

namespace App\Modules\Explore;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Logger;

/**
 * Météo actuelle de Clermont (en-tête « ☁ 13° »). Un seul appel externe tous les quarts d'heure,
 * quel que soit le nombre d'utilisateurs. En cas de panne : null (l'app masque simplement la météo).
 */
final class WeatherService
{
    public function current(): ?array
    {
        $cached = Cache::store()->get('explore:weather');
        if (is_array($cached)) {
            return $cached;
        }

        $c = (array) Config::get('explore.weather');
        $url = $c['url'] . '?' . http_build_query([
            'latitude'  => $c['latitude'],
            'longitude' => $c['longitude'],
            'current'   => 'temperature_2m,weather_code,is_day',
            'timezone'  => Config::get('explore.timezone', 'Europe/Paris'),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;
        if ($code !== 200 || !isset($data['current']['temperature_2m'], $data['current']['weather_code'])) {
            Logger::warning('Météo indisponible', ['http' => $code]);
            // Échec mémorisé 2 minutes : pas d'appel en boucle quand le service est en panne
            Cache::store()->set('explore:weather', [], 120);
            return null;
        }

        $weather = self::fromWmo((int) $data['current']['weather_code'], (bool) ($data['current']['is_day'] ?? 1))
            + ['temperature' => (int) round((float) $data['current']['temperature_2m']), 'unit' => '°C'];
        Cache::store()->set('explore:weather', $weather, (int) $c['cache_ttl']);

        return $weather;
    }

    /**
     * Code météo WMO → condition + icône (l'app associe l'icône à un SF Symbol / Material Icon).
     *
     * @return array{condition: string, label: string, icon: string}
     */
    public static function fromWmo(int $code, bool $isDay): array
    {
        [$condition, $label, $icon] = match (true) {
            $code === 0                 => ['clear', 'Ensoleillé', $isDay ? 'sun' : 'moon'],
            in_array($code, [1, 2], true) => ['partly_cloudy', 'Éclaircies', $isDay ? 'cloud-sun' : 'cloud-moon'],
            $code === 3                 => ['cloudy', 'Nuageux', 'cloud'],
            in_array($code, [45, 48], true) => ['fog', 'Brouillard', 'fog'],
            $code >= 51 && $code <= 57  => ['drizzle', 'Bruine', 'cloud-drizzle'],
            $code >= 61 && $code <= 67, $code >= 80 && $code <= 82 => ['rain', 'Pluie', 'cloud-rain'],
            $code >= 71 && $code <= 77, $code === 85, $code === 86 => ['snow', 'Neige', 'snowflake'],
            $code >= 95                 => ['storm', 'Orage', 'cloud-bolt'],
            default                     => ['cloudy', 'Nuageux', 'cloud'],
        };

        return ['condition' => $condition, 'label' => $label, 'icon' => $icon];
    }
}
