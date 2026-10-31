<?php

declare(strict_types=1);

namespace App\Modules\Fuel;

use App\Core\Module;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\ValidationException;

/** GET /fuel/stations?lat=&lon=&radiusKm=&limit=&fuel=   → { items: FuelStation[], nextCursor: null, updatedAt } (cache 10 min) */
final class FuelModule implements Module
{
    public function routes(Router $r): void
    {
        $r->get('/fuel/stations', [self::class, 'stations']);
    }

    public function stations(Request $request): Response
    {
        $q = $request->query();
        if (!isset($q['lat'], $q['lon']) || !is_numeric($q['lat']) || !is_numeric($q['lon'])) {
            throw new ValidationException(['lat' => 'Position requise (lat, lon).']);
        }
        $radius = isset($q['radius_km']) && is_numeric($q['radius_km']) ? (float) $q['radius_km'] : 5.0;
        if ($radius <= 0 || $radius > 10) {
            throw new ValidationException(['radius_km' => 'Rayon de 10 km maximum.']);
        }
        $fuel = isset($q['fuel']) && $q['fuel'] !== '' ? (string) $q['fuel'] : null;
        if ($fuel !== null && !in_array($fuel, FuelService::FUELS, true)) {
            throw new ValidationException(['fuel' => 'Carburant inconnu : ' . implode(', ', FuelService::FUELS) . '.']);
        }
        $limit = isset($q['limit']) && ctype_digit((string) $q['limit']) ? min(100, max(1, (int) $q['limit'])) : 100;
        $data = (new FuelService())->stations((float) $q['lat'], (float) $q['lon'], $radius, $fuel);

        return Response::success(array_slice($data['items'], 0, $limit), 200, ['next_cursor' => null, 'updated_at' => $data['updated_at']])->withHeader('Cache-Control', 'private, max-age=600');
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
    }
}
