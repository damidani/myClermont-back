<?php

declare(strict_types=1);

namespace App\Modules\Services;

use App\Content\ContentSources;
use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;
use App\Security\Crypto;
use App\Support\Validator;

final class ServiceController
{
    private ServiceRepository $services;

    public function __construct()
    {
        $this->services = new ServiceRepository();
    }

    /**
     * GET /v1/services — écran « Services »
     *   ?category=maison-travaux &q=plombier &sort=relevance|rating &favorites=1 &neighborhood_id &page=1 &limit=20
     */
    public function index(Request $request): Response
    {
        $q = $request->query();
        $sort = (string) ($q['sort'] ?? 'relevance');
        if (!in_array($sort, ServiceRepository::SORTS, true)) {
            throw new ValidationException(['sort' => 'Tri inconnu (relevance, rating).']);
        }
        $categories = $this->services->categoriesWithCounts();
        $category = ($q['category'] ?? '') !== '' ? (string) $q['category'] : null;
        if ($category !== null && !in_array($category, array_column($categories, 'slug'), true)) {
            throw new ValidationException(['category' => 'Catégorie inconnue.']);
        }

        $userId = $request->attributes['user_id'] ?? null;
        $favoritesOnly = ($q['favorites'] ?? '') === '1';
        if ($favoritesOnly && $userId === null) {
            throw HttpException::unauthorized('unauthenticated', 'Connecte-toi pour voir tes favoris.');
        }

        $filters = [
            'category'        => $category,
            'q'               => isset($q['q']) ? mb_substr(trim((string) $q['q']), 0, 100) : null,
            'favorites_of'    => $favoritesOnly ? $userId : null,
            'neighborhood_id' => isset($q['neighborhood_id']) ? (int) $q['neighborhood_id'] : null,
        ];
        $limit = max(1, min(50, (int) ($q['limit'] ?? 20)));
        $page = max(1, (int) ($q['page'] ?? 1));

        $rows = $this->services->search($filters, $sort, $limit, ($page - 1) * $limit);
        $favorites = $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'service', array_column($rows, 'uid'));
        $total = $this->services->count($filters);

        return Response::success(
            array_map(static fn (array $s) => ServicePresenter::card($s, $favorites === null ? null : in_array($s['uid'], $favorites, true)), $rows),
            200,
            [
                'categories' => $categories,                                       // « Tous 10 · Maison & travaux 2 · … »
                'all_count'  => array_sum(array_column($categories, 'count')),
                'total'      => $total,                                            // « 10 professionnel(s) »
                'page'       => $page,
                'next_page'  => $page * $limit < $total ? $page + 1 : null,
            ],
        );
    }

    /** GET /v1/services/categories */
    public function categories(Request $request): Response
    {
        return Response::success($this->services->categoriesWithCounts());
    }

    /** GET /v1/services/{id} */
    public function show(Request $request): Response
    {
        $provider = $this->findOrFail((string) $request->param('id'));
        $userId = $request->attributes['user_id'] ?? null;
        $isFavorite = $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'service', [$provider['uid']]) !== [];

        return Response::success(ServicePresenter::detail(
            $provider,
            $this->services->reviews((int) $provider['id'], 10),
            $isFavorite,
            $this->lastQuote($request, (int) $provider['id']),
        ));
    }

    /** POST /v1/services/{id}/quote — « Demander un devis » */
    public function requestQuote(Request $request): Response
    {
        $provider = $this->findOrFail((string) $request->param('id'));
        if ($provider['status'] !== 'published') {
            throw new HttpException(409, 'provider_inactive', 'Ce professionnel n\'est plus référencé.');
        }
        $contact = ContentSources::contact($provider);
        if ($contact['mode'] === 'external') {
            throw new HttpException(409, 'quote_external', 'La demande se fait sur ' . $provider['source_name'] . '.', ['url' => $contact['url']]);
        }

        $data = Validator::validate($request->json(), [
            'name'           => 'required|string|min:2|max:120',
            'email'          => 'required|email|max:191',
            'phone'          => 'nullable|string|max:30',
            'postal_code'    => 'nullable|string|max:5',
            'preferred_date' => 'nullable|string|max:10',
            'message'        => 'required|string|min:10|max:3000',
        ]);
        $errors = [];
        if (isset($data['phone']) && !preg_match('/^\+?[0-9 .()-]{6,30}$/', $data['phone'])) {
            $errors['phone'] = 'Numéro de téléphone invalide.';
        }
        if (isset($data['postal_code']) && !preg_match('/^\d{5}$/', $data['postal_code'])) {
            $errors['postal_code'] = 'Code postal invalide.';
        }
        if (isset($data['preferred_date']) && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['preferred_date']) || $data['preferred_date'] < gmdate('Y-m-d'))) {
            $errors['preferred_date'] = 'Date attendue au format AAAA-MM-JJ, à partir d\'aujourd\'hui.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $device = $request->attributes['device'];
        $recent = Database::fetch(
            'SELECT COUNT(*) AS n FROM service_quote_requests WHERE device_id = :d AND provider_id = :p AND created_at > :since',
            ['d' => $device['id'], 'p' => $provider['id'], 'since' => gmdate('Y-m-d H:i:s', time() - 86400)],
        );
        if ((int) $recent['n'] > 0) {
            throw new HttpException(429, 'quote_already_sent', 'Tu as déjà contacté ce professionnel aujourd\'hui.');
        }

        $uid = Crypto::uuid4();
        Database::execute(
            'INSERT INTO service_quote_requests (uid, provider_id, user_id, device_id, name, email, phone, postal_code, preferred_date, message, created_at)
             VALUES (:uid, :p, :u, :d, :name, :email, :phone, :cp, :date, :msg, :now)',
            [
                'uid' => $uid, 'p' => $provider['id'], 'u' => $request->attributes['user_id'] ?? null, 'd' => $device['id'],
                'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null,
                'cp' => $data['postal_code'] ?? null, 'date' => $data['preferred_date'] ?? null, 'msg' => $data['message'], 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );
        Events::dispatch('services.quote_requested', ['user_id' => $request->attributes['user_id'] ?? null, 'provider_uid' => $provider['uid']]);

        return Response::success(['id' => $uid, 'status' => 'pending', 'message' => 'Ta demande de devis a été transmise.'], 201);
    }

    /** PUT /v1/services/{id}/review — avis vérifié (après une demande de devis transmise) */
    public function review(Request $request): Response
    {
        $provider = $this->findOrFail((string) $request->param('id'));
        $data = Validator::validate($request->json(), [
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1500',
        ]);
        $quote = $this->lastQuote($request, (int) $provider['id']);
        if ($quote === null || $quote['status'] !== 'sent') {
            throw HttpException::forbidden('review_not_allowed', 'Seules les personnes ayant contacté ce professionnel via myClermont peuvent laisser un avis.');
        }

        $this->services->saveReview((int) $provider['id'], $request->attributes['user_id'], $data['rating'], $data['comment'] ?? null);
        Events::dispatch('services.review_saved', ['user_id' => $request->attributes['user_id'], 'provider_uid' => $provider['uid']]);

        return Response::success(ServicePresenter::rating($this->services->find($provider['uid'])) ?? []);
    }

    /** Dernière demande de devis de l'utilisateur (compte connecté) pour ce professionnel. */
    private function lastQuote(Request $request, int $providerId): ?array
    {
        $userId = $request->attributes['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }
        $row = Database::fetch(
            'SELECT uid, status, created_at FROM service_quote_requests WHERE user_id = :u AND provider_id = :p ORDER BY id DESC LIMIT 1',
            ['u' => $userId, 'p' => $providerId],
        );

        return $row === null ? null : [
            'id'         => $row['uid'],
            'status'     => $row['status'] === 'sent' ? 'sent' : 'processing',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC')),
        ];
    }

    private function findOrFail(string $id, bool $withContact = false): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($p = $this->services->find($id, $withContact)) === null) {
            throw new HttpException(404, 'provider_not_found', 'Professionnel introuvable.');
        }

        return $p;
    }
}
