<?php

declare(strict_types=1);

namespace App\Modules\Housing;

use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;
use App\Security\Crypto;
use App\Support\Validator;

final class HousingController
{
    private HousingRepository $listings;

    public function __construct()
    {
        $this->listings = new HousingRepository();
    }

    /**
     * GET /v1/housing — écran « Logement » (mode liste)
     *   ?transaction=rent|sale|colocation &furnished=1 &max_price=900 (€) &min_rooms=2
     *   &q=jaude &neighborhood_id=1 &property_type=apartment &sort=recent|price_asc|price_desc
     *   &bbox=minLat,minLng,maxLat,maxLng &limit=20 &cursor=…
     */
    public function index(Request $request): Response
    {
        $q = $request->query();
        $filters = $this->filters($q);
        $sort = (string) ($q['sort'] ?? 'recent');
        if (!isset(HousingRepository::SORTS[$sort])) {
            throw new ValidationException(['sort' => 'Tri inconnu (recent, price_asc, price_desc).']);
        }
        $limit = max(1, min((int) Config::get('housing.max_per_page', 50), (int) ($q['limit'] ?? Config::get('housing.per_page', 20))));

        $rows = $this->listings->search($filters, $sort, $limit, isset($q['cursor']) ? $this->decodeCursor((string) $q['cursor']) : null);
        $favorites = $this->favoriteIds($request, $rows);
        $counts = $this->listings->transactionCounts();
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $h) => HousingPresenter::card($h, $favorites === null ? null : in_array($h['uid'], $favorites, true)), $rows),
            200,
            [
                // « Tous 5 · Location 2 · Achat 2 · Colocation 1 »
                'transactions' => array_map(
                    static fn (string $code, string $label) => ['code' => $code, 'label' => $label, 'count' => $counts[$code] ?? 0],
                    array_keys((array) Config::get('housing.transactions')),
                    (array) Config::get('housing.transactions'),
                ),
                'all_count'   => array_sum($counts),
                // « 5 bien(s) » avec les filtres actuels (calculé seulement sur la 1re page)
                'total'       => isset($q['cursor']) ? null : $this->listings->count($filters),
                'next_cursor' => count($rows) === $limit && $last ? $this->encodeCursor($last, $sort) : null,
            ],
        );
    }

    /** GET /v1/housing/map — mode carte : épingles (mêmes filtres, bbox recommandée) */
    public function map(Request $request): Response
    {
        $pins = $this->listings->pins($this->filters($request->query()), (int) Config::get('housing.max_map_pins', 500));

        return Response::success(array_map(HousingPresenter::pin(...), $pins));
    }

    /** GET /v1/housing/filters — valeurs possibles des filtres et libellés (évite de les coder en dur dans l'app) */
    public function filtersCatalog(Request $request): Response
    {
        $list = static fn (array $items) => array_map(static fn ($code, $label) => ['code' => $code, 'label' => $label], array_keys($items), $items);

        return Response::success([
            'transactions'   => $list((array) Config::get('housing.transactions')),
            'property_types' => $list((array) Config::get('housing.property_types')),
            'features'       => array_map(
                static fn ($code, $f) => ['code' => $code, 'label' => $f[0], 'icon' => $f[1]],
                array_keys((array) Config::get('housing.features')),
                (array) Config::get('housing.features'),
            ),
            'sorts'          => [
                ['code' => 'recent', 'label' => 'Plus récentes'],
                ['code' => 'price_asc', 'label' => 'Prix croissant'],
                ['code' => 'price_desc', 'label' => 'Prix décroissant'],
            ],
        ]);
    }

    /** GET /v1/housing/{id} — fiche annonce */
    public function show(Request $request): Response
    {
        $listing = $this->findOrFail((string) $request->param('id'));
        $favorites = $this->favoriteIds($request, [$listing]);

        return Response::success(HousingPresenter::detail(
            $listing,
            $this->listings->images((int) $listing['id']),
            $favorites === null ? null : in_array($listing['uid'], $favorites, true),
        ));
    }

    /**
     * POST /v1/housing/{id}/contact — formulaire « Contacter l'annonceur »
     * La demande est enregistrée puis transmise par e-mail (php bin/console housing:send-contacts, en cron).
     */
    public function contact(Request $request): Response
    {
        $listing = $this->findOrFail((string) $request->param('id'));

        if ($listing['status'] !== 'published') {
            throw new HttpException(409, 'listing_inactive', 'Cette annonce n\'est plus disponible.');
        }
        $action = \App\Content\ContentSources::contact($listing);
        if ($action['mode'] === 'external') {
            // Annonce importée : le contact se fait sur le site d'origine
            throw new HttpException(409, 'contact_external', 'Cette annonce se contacte sur ' . $listing['source_name'] . '.', ['url' => $action['url']]);
        }

        $json = $request->json();
        $json['name'] ??= $json['full_name'] ?? null;   // app iOS : fullName
        $data = Validator::validate($json, [
            'name'    => 'required|string|min:2|max:120',
            'email'   => 'required|email|max:191',
            'phone'   => 'nullable|string|max:30',
            'message' => 'required|string|min:10|max:2000',
        ]);
        if (isset($data['phone']) && !preg_match('/^\+?[0-9 .()-]{6,30}$/', $data['phone'])) {
            throw new ValidationException(['phone' => 'Numéro de téléphone invalide.']);
        }

        $device = $request->attributes['device'];
        $recent = Database::fetch(
            'SELECT COUNT(*) AS n FROM housing_contact_requests WHERE device_id = :d AND listing_id = :l AND created_at > :since',
            ['d' => $device['id'], 'l' => $listing['id'], 'since' => gmdate('Y-m-d H:i:s', time() - 86400)],
        );
        if ((int) $recent['n'] >= (int) Config::get('housing.contact.max_per_device_per_listing_per_day', 1)) {
            throw new HttpException(429, 'contact_already_sent', 'Vous avez déjà contacté cet annonceur aujourd\'hui.');
        }

        $uid = Crypto::uuid4();
        Database::execute(
            'INSERT INTO housing_contact_requests (uid, listing_id, user_id, device_id, name, email, phone, message, created_at)
             VALUES (:uid, :l, :u, :d, :name, :email, :phone, :msg, :now)',
            [
                'uid' => $uid, 'l' => $listing['id'], 'u' => $request->attributes['user_id'] ?? null, 'd' => $device['id'],
                'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null,
                'msg' => $data['message'], 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );

        Events::dispatch('housing.contact_requested', [
            'user_id' => $request->attributes['user_id'] ?? null, 'listing_uid' => $listing['uid'], 'request_uid' => $uid,
        ]);

        return Response::success(['id' => $uid, 'status' => 'pending', 'message' => 'Votre demande a été transmise à l\'annonceur.'], 201);
    }

    private function findOrFail(string $id): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($listing = $this->listings->find($id)) === null) {
            throw new HttpException(404, 'listing_not_found', 'Annonce introuvable.');
        }

        return $listing;
    }

    private function filters(array $q): array
    {
        $errors = [];
        // Spec v1 : type = « Location » | « Colocation » | « Achat »
        if (isset($q['type']) && is_string($q['type']) && !isset($q['transaction'])) {
            $q['transaction'] = array_search(mb_strtolower($q['type']), array_map('mb_strtolower', (array) Config::get('housing.transactions')), true) ?: $q['type'];
        }
        $tx = isset($q['transaction']) && $q['transaction'] !== '' ? (string) $q['transaction'] : null;
        if ($tx !== null && !array_key_exists($tx, (array) Config::get('housing.transactions'))) {
            $errors['transaction'] = 'Valeur inconnue.';
        }
        $type = isset($q['property_type']) && $q['property_type'] !== '' ? (string) $q['property_type'] : null;
        if ($type !== null && !array_key_exists($type, (array) Config::get('housing.property_types'))) {
            $errors['property_type'] = 'Valeur inconnue.';
        }
        $bbox = null;
        if (isset($q['bbox'])) {
            $parts = array_map('trim', explode(',', (string) $q['bbox']));
            if (count($parts) !== 4 || array_filter($parts, static fn ($p) => !is_numeric($p))) {
                $errors['bbox'] = 'Format attendu : minLat,minLng,maxLat,maxLng.';
            } else {
                $bbox = array_map('floatval', $parts);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'transaction'     => $tx,
            'property_type'   => $type,
            'furnished'       => ($q['furnished'] ?? '') === '1',
            'max_price_cents' => isset($q['max_price']) && (int) $q['max_price'] > 0 ? (int) $q['max_price'] * 100 : null,
            'min_rooms'       => (int) ($q['min_rooms'] ?? $q['rooms'] ?? 0) > 0 ? (int) ($q['min_rooms'] ?? $q['rooms']) : null,
            'min_surface'     => isset($q['min_surface']) && (float) $q['min_surface'] > 0 ? (float) $q['min_surface'] : null,
            'district'        => isset($q['district']) && $q['district'] !== '' ? mb_substr((string) $q['district'], 0, 100) : null,
            'neighborhood_id' => isset($q['neighborhood_id']) ? (int) $q['neighborhood_id'] : null,
            'q'               => isset($q['q']) ? mb_substr(trim((string) $q['q']), 0, 100) : null,
            'bbox'            => $bbox,
        ];
    }

    /** @return list<string>|null null = non connecté */
    private function favoriteIds(Request $request, array $rows): ?array
    {
        $userId = $request->attributes['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }

        return (new FavoriteRepository())->filterFavorited($userId, 'housing', array_column($rows, 'uid'));
    }

    private function encodeCursor(array $row, string $sort): string
    {
        $value = $sort === 'recent' ? $row['published_at'] : (string) $row['price_cents'];

        return rtrim(strtr(base64_encode($sort . '|' . $value . '|' . $row['id']), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): array
    {
        $decoded = (string) base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!preg_match('/^(recent|price_asc|price_desc)\|([0-9: -]+)\|(\d+)$/', $decoded, $m)) {
            throw new ValidationException(['cursor' => 'Curseur invalide.']);
        }

        return [$m[1] === 'recent' ? $m[2] : (int) $m[2], (int) $m[3]];
    }
}
