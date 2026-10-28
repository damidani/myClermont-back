<?php

declare(strict_types=1);

namespace App\Modules\Places;

use App\Core\Config;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;
use App\Support\Validator;

final class PlaceController
{
    private PlaceRepository $places;

    public function __construct()
    {
        $this->places = new PlaceRepository();
    }

    /**
     * GET /v1/places/pins?near=45.7773,3.0816&category=restaurants&filter=partners|must_see
     *
     * Tous les repères en une réponse compacte, du plus proche au plus loin.
     * En-tête If-None-Match: <ETag précédent> → 304 sans contenu si rien n'a changé.
     */
    public function pins(Request $request): Response
    {
        [$center, $category, $filter] = $this->pinParams($request);
        $selection = (new PinService($this->places))->select($center, $category, $filter);

        // L'ETag dépend des données ET de la requête (position arrondie à ~100 m : bouger un peu ne change rien)
        $etag = '"' . $selection['version'] . '-' . substr(sha1(json_encode([$category, $filter, $center === null ? null : [round($center[0], 3), round($center[1], 3)]])), 0, 8) . '"';
        if (trim((string) $request->header('if-none-match', '')) === $etag) {
            return Response::notModified($etag);
        }

        return Response::success([
            'version'    => $selection['version'],
            'fields'     => PinService::FIELDS,
            'categories' => $selection['categories'],
            'filters'    => $selection['filters'],
            'total'      => count($selection['pins']),
            'pins'       => $selection['pins'],
        ])->withHeader('ETag', $etag)->withHeader('Cache-Control', 'private, no-cache');
    }

    /**
     * GET /v1/places/pins/stream?near=… — même contenu, envoyé par anneaux de distance (Server-Sent Events) :
     *   event: meta   → catégories, compteurs, total
     *   event: pins   → { ring: 1000, pins: […] } puis 3000, 10000, puis le reste (ring: null)
     *   event: done
     * La connexion se ferme immédiatement après : aucun worker PHP n'est bloqué.
     */
    public function stream(Request $request): Response
    {
        [$center, $category, $filter] = $this->pinParams($request);
        $center ??= [(float) Config::get('places.default_center.lat'), (float) Config::get('places.default_center.lng')];
        $selection = (new PinService($this->places))->select($center, $category, $filter);
        $rings = (array) Config::get('places.stream_rings', [1000, 3000, 10000]);

        return Response::eventStream(static function (\Closure $emit) use ($selection, $rings): void {
            $emit('meta', [
                'version' => $selection['version'], 'fields' => PinService::FIELDS, 'categories' => $selection['categories'],
                'filters' => $selection['filters'], 'total' => count($selection['pins']),
            ]);

            $batch = [];
            $ringIndex = 0;
            foreach ($selection['pins'] as $pin) {
                while ($ringIndex < count($rings) && $pin[6] > $rings[$ringIndex]) {
                    $emit('pins', ['ring' => $rings[$ringIndex], 'pins' => $batch]);
                    $batch = [];
                    $ringIndex++;
                }
                $batch[] = $pin;
            }
            for (; $ringIndex < count($rings); $ringIndex++) {
                $emit('pins', ['ring' => $rings[$ringIndex], 'pins' => $batch]);
                $batch = [];
            }
            $emit('pins', ['ring' => null, 'pins' => $batch]);
            $emit('done', ['total' => count($selection['pins'])]);
        });
    }

    /**
     * GET /v1/places/nearby?near=…&category=&filter=&limit=20&offset=0 — liste « 179 lieux » (bas de la carte)
     * Cartes complètes, du plus proche au plus loin.
     */
    public function nearby(Request $request): Response
    {
        [$center, $category, $filter] = $this->pinParams($request);
        $q = $request->query();
        $limit = max(1, min((int) Config::get('places.nearby_limit_max', 100), (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $selection = (new PinService($this->places))->select($center, $category, $filter);
        $slice = array_slice($selection['pins'], $offset, $limit);

        return Response::success(
            $this->cards($request, array_column($slice, 0), array_column($slice, 6, 0)),
            200,
            ['total' => count($selection['pins']), 'next_offset' => $offset + $limit < count($selection['pins']) ? $offset + $limit : null],
        );
    }

    /** GET /v1/places/search?q=musée&near=… — barre « Restos, carburant, musées, parcs… » */
    public function search(Request $request): Response
    {
        $q = $request->query();
        $query = trim((string) ($q['q'] ?? ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new ValidationException(['q' => 'Entre 2 et 100 caractères.']);
        }
        $center = isset($q['near']) ? Geo::parse($q['near']) : null;
        $uids = $this->places->searchUids($query, 30);

        $distances = [];
        if ($center !== null) {
            foreach ($this->places->findManyByUid($uids) as $uid => $p) {
                $distances[$uid] = Geo::distance($center[0], $center[1], (float) $p['latitude'], (float) $p['longitude']);
            }
            usort($uids, static fn ($a, $b) => ($distances[$a] ?? PHP_INT_MAX) <=> ($distances[$b] ?? PHP_INT_MAX));
        }

        return Response::success($this->cards($request, $uids, $distances), 200, ['query' => $query, 'count' => count($uids)]);
    }

    /** GET /v1/places/{id}?near=… — fiche lieu */
    public function show(Request $request): Response
    {
        $place = $this->findOrFail((string) $request->param('id'));
        $userId = $request->attributes['user_id'] ?? null;
        $center = isset($request->query()['near']) ? Geo::parse($request->query()['near']) : null;

        return Response::success(PlacePresenter::detail(
            $place,
            $this->places->reviews((int) $place['id'], 10),
            $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'place', [$place['uid']]) !== [],
            $userId === null ? null : $this->places->myReview((int) $place['id'], $userId),
            $center === null ? null : Geo::distance($center[0], $center[1], (float) $place['latitude'], (float) $place['longitude']),
        ));
    }

    /** PUT /v1/places/{id}/review — « Donner mon avis » (un avis par personne, modifiable) */
    public function review(Request $request): Response
    {
        $place = $this->findOrFail((string) $request->param('id'));
        $data = Validator::validate($request->json(), ['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:1500']);
        $userId = $request->attributes['user_id'];

        $this->places->saveReview((int) $place['id'], $userId, $data['rating'], $data['comment'] ?? null);
        Events::dispatch('places.reviewed', ['user_id' => $userId, 'place_uid' => $place['uid']]);

        return Response::success(PlacePresenter::rating($this->findOrFail($place['uid'])));
    }

    /** @return list<array> cartes dans l'ordre des uids */
    private function cards(Request $request, array $uids, array $distances): array
    {
        $rows = $this->places->findManyByUid($uids);
        $userId = $request->attributes['user_id'] ?? null;
        $favorites = $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'place', $uids);

        $cards = [];
        foreach ($uids as $uid) {
            if (isset($rows[$uid])) {
                $cards[] = PlacePresenter::card($rows[$uid], $distances[$uid] ?? null, $favorites === null ? null : in_array($uid, $favorites, true));
            }
        }

        return $cards;
    }

    /** @return array{0: ?array, 1: ?string, 2: ?string} */
    private function pinParams(Request $request): array
    {
        $q = $request->query();
        $center = null;
        if (isset($q['near'])) {
            $center = Geo::parse($q['near']) ?? throw new ValidationException(['near' => 'Format attendu : latitude,longitude.']);
        }

        return [
            $center,
            ($q['category'] ?? '') !== '' ? (string) $q['category'] : null,
            ($q['filter'] ?? '') !== '' ? (string) $q['filter'] : null,
        ];
    }

    private function findOrFail(string $id): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($p = $this->places->find($id)) === null) {
            throw new HttpException(404, 'place_not_found', 'Lieu introuvable.');
        }

        return $p;
    }
}
