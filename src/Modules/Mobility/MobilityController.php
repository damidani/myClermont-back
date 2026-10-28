<?php

declare(strict_types=1);

namespace App\Modules\Mobility;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;

final class MobilityController
{
    /** GET /v1/mobility/destinations — « Destinations rapides » */
    public function destinations(Request $request): Response
    {
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get('mobility:destinations'))) {
            return Response::success($cached);
        }
        $rows = array_map(static fn (array $d) => [
            'id'        => $d['slug'],
            'name'      => $d['name'],
            'address'   => $d['address'],
            'icon'      => $d['icon'],
            'latitude'  => (float) $d['latitude'],
            'longitude' => (float) $d['longitude'],
        ], Database::fetchAll('SELECT slug, name, address, icon, latitude, longitude FROM mobility_destinations WHERE is_active = 1 ORDER BY sort_order'));
        if (Cache::isInMemory()) {
            Cache::store()->set('mobility:destinations', $rows, 3600);
        }

        return Response::success($rows);
    }

    /**
     * GET /v1/mobility/journeys — itinéraires en transports en commun
     *   ?from=45.7773,3.0816 &to=45.7789,3.1001 &datetime=2026-10-28T08:30:00+01:00 &represents=departure|arrival
     *
     * À pied / voiture / vélo : calculés par l'app avec le service de cartes du téléphone (MapKit, Google Maps),
     * voir docs/API.md › Mobilité.
     */
    public function journeys(Request $request): Response
    {
        $q = $request->query();
        [$fromLat, $fromLng] = $this->coordinates($q['from'] ?? null, 'from');
        [$toLat, $toLng] = $this->coordinates($q['to'] ?? null, 'to');

        $represents = (string) ($q['represents'] ?? 'departure');
        if (!in_array($represents, ['departure', 'arrival'], true)) {
            throw new ValidationException(['represents' => 'departure (« Partir à ») ou arrival (« Arriver avant »).']);
        }

        $at = new \DateTimeImmutable('now');
        if (isset($q['datetime'])) {
            try {
                $at = new \DateTimeImmutable((string) $q['datetime']);
            } catch (\Exception) {
                throw new ValidationException(['datetime' => 'Date ISO 8601 attendue, ex. 2026-10-28T08:30:00+01:00.']);
            }
            if ($at->getTimestamp() < time() - 86400 || $at->getTimestamp() > time() + 60 * 86400) {
                throw new ValidationException(['datetime' => 'Date entre hier et dans 60 jours.']);
            }
        }

        $journeys = (new NavitiaClient())->journeys($fromLat, $fromLng, $toLat, $toLng, $at, $represents);

        return Response::success($journeys, 200, [
            'count'   => count($journeys),
            'message' => $journeys === [] ? 'Aucun itinéraire en transports en commun trouvé pour ce trajet.' : null,
        ]);
    }

    /** GET /v1/mobility/places?q=jaude — autocomplétion des champs Départ / Arrivée (arrêts, adresses, lieux) */
    public function places(Request $request): Response
    {
        $query = trim((string) ($request->query()['q'] ?? ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new ValidationException(['q' => 'Entre 2 et 100 caractères.']);
        }

        return Response::success((new NavitiaClient())->places($query));
    }

    /** GET /v1/mobility/traffic — « Info trafic T2C » */
    public function traffic(Request $request): Response
    {
        if (!NavitiaClient::isConfigured()) {
            return Response::success([], 200, ['available' => false, 'message' => 'L\'info trafic n\'est pas encore disponible.']);
        }
        $items = (new NavitiaClient())->traffic();

        return Response::success($items, 200, ['available' => true, 'active_count' => count(array_filter($items, static fn ($i) => $i['status'] === 'active'))]);
    }

    /** "45.7773,3.0816" → [lat, lng], limité à la métropole */
    private function coordinates(mixed $value, string $field): array
    {
        if (!is_string($value) || !preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', $value, $m)) {
            throw new ValidationException([$field => 'Format attendu : latitude,longitude.']);
        }
        [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        $b = (array) Config::get('mobility.bounds');
        if ($lat < $b['min_lat'] || $lat > $b['max_lat'] || $lng < $b['min_lng'] || $lng > $b['max_lng']) {
            throw new ValidationException([$field => 'Point en dehors de la zone couverte (métropole clermontoise).']);
        }

        return [$lat, $lng];
    }
}
