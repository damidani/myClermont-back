<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Content\Media\MediaStore;
use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;
use App\Modules\Messaging\MessagingPresenter;
use App\Modules\Messaging\MessagingService;
use App\Support\Validator;

final class ClassifiedController
{
    private ClassifiedRepository $classifieds;

    public function __construct()
    {
        $this->classifieds = new ClassifiedRepository();
    }

    /**
     * GET /v1/classifieds — écran « Petites annonces »
     *   ?category=maison-deco &q=vélo &free=1 &max_price=1000 (€) &neighborhood_id=2 &favorites=1
     *   &sort=recent|price_asc|price_desc &limit=20 &cursor=…
     */
    public function index(Request $request): Response
    {
        $q = $request->query();
        $userId = $request->attributes['user_id'] ?? null;
        $sort = (string) ($q['sort'] ?? 'recent');
        if (!isset(ClassifiedRepository::SORTS[$sort])) {
            throw new ValidationException(['sort' => 'Tri inconnu (recent, price_asc, price_desc).']);
        }
        $counts = $this->classifieds->counts();
        $category = ($q['category'] ?? '') !== '' ? (string) $q['category'] : null;
        if ($category !== null && !in_array($category, array_column($counts['categories'], 'slug'), true)) {
            throw new ValidationException(['category' => 'Catégorie inconnue.']);
        }
        $favorites = ($q['favorites'] ?? '') === '1';
        if ($favorites && $userId === null) {
            throw HttpException::unauthorized('unauthenticated', 'Connecte-toi pour voir tes favoris.');
        }

        $filters = [
            'category'           => $category,
            'q'                  => isset($q['q']) ? mb_substr(trim((string) $q['q']), 0, 100) : null,
            'free'               => ($q['free'] ?? '') === '1',
            'max_price_cents'    => isset($q['max_price']) && (int) $q['max_price'] > 0 ? (int) $q['max_price'] * 100 : null,
            'neighborhood_id'    => isset($q['neighborhood_id']) ? (int) $q['neighborhood_id'] : null,
            'favorites_of'       => $favorites ? $userId : null,
            'exclude_blocked_by' => $userId,
        ];
        $limit = max(1, min(50, (int) ($q['limit'] ?? Config::get('classifieds.per_page', 20))));
        $rows = $this->classifieds->search($filters, $sort, $limit, isset($q['cursor']) ? $this->decodeCursor((string) $q['cursor'], $sort) : null);
        $favIds = $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'classified', array_column($rows, 'uid'));
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $a) => ClassifiedPresenter::card($a, $favIds === null ? null : in_array($a['uid'], $favIds, true), $userId), $rows),
            200,
            [
                'categories'  => $counts['categories'],   // « Maison & déco 2 »…
                'all_count'   => $counts['all'],          // « 12 annonces », « Tout 12 »
                'free_count'  => $counts['free'],         // « 1 dons »
                'total'       => isset($q['cursor']) ? null : $this->classifieds->count($filters), // « 12 résultat(s) »
                'sort'        => $sort,
                'next_cursor' => count($rows) === $limit && $last ? $this->encodeCursor($last, $sort) : null,
            ],
        );
    }

    /** GET /v1/classifieds/filters — catégories, états, tris, limites (formulaire de dépôt) */
    public function filtersCatalog(Request $request): Response
    {
        $limits = (array) Config::get('classifieds.limits');

        return Response::success([
            'categories' => array_map(static fn (array $c) => array_diff_key($c, ['count' => 0]), $this->classifieds->counts()['categories']),
            'conditions' => array_map(static fn ($code, $label) => ['code' => $code, 'label' => $label], array_keys((array) Config::get('classifieds.conditions')), (array) Config::get('classifieds.conditions')),
            'sorts'      => [['code' => 'recent', 'label' => 'Plus récentes'], ['code' => 'price_asc', 'label' => 'Prix croissant'], ['code' => 'price_desc', 'label' => 'Prix décroissant']],
            'limits'     => ['title_max' => $limits['title_max'], 'description_max' => $limits['description_max'], 'photos_max' => $limits['photos_max'], 'price_max_euros' => $limits['price_max_euros']],
        ]);
    }

    /** GET /v1/classifieds/{id} */
    public function show(Request $request): Response
    {
        $a = $this->findOrFail((string) $request->param('id'));
        $userId = $request->attributes['user_id'] ?? null;
        $isMine = $userId !== null && (int) $a['user_id'] === $userId;

        $conversationId = null;
        if ($userId !== null && !$isMine) {
            $conversationId = Database::fetch(
                "SELECT c.uid FROM conversations c JOIN conversation_participants p ON p.conversation_id = c.id AND p.user_id = :u
                 WHERE c.context_type = 'classified' AND c.context_id = :a LIMIT 1",
                ['u' => $userId, 'a' => $a['uid']],
            )['uid'] ?? null;
        }

        return Response::success(ClassifiedPresenter::detail(
            $a,
            $this->classifieds->photos((int) $a['id']),
            $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, 'classified', [$a['uid']]) !== [],
            $userId,
            $a['user_id'] === null ? null : $this->classifieds->sellerStats((int) $a['user_id']),
            $conversationId,
        ));
    }

    /** POST /v1/classifieds — « Déposer une annonce » (gratuit) */
    public function create(Request $request): Response
    {
        $userId = $request->attributes['user_id'];
        $limits = (array) Config::get('classifieds.limits');
        if ($this->classifieds->activeCountForUser($userId) >= $limits['active_per_user']) {
            throw new HttpException(409, 'too_many_listings', "Tu as déjà {$limits['active_per_user']} annonces en ligne.");
        }

        $data = $this->validateListing($request->json(), $userId, false);
        $uid = $this->classifieds->create($userId, $data, $data['media_ids']);

        Events::dispatch('classifieds.published', ['user_id' => $userId, 'classified_uid' => $uid, 'is_free' => $data['is_free'], 'category' => $data['category_slug']]);

        $response = $this->show($this->withParam($request, $uid));
        $response->status = 201;

        return $response;
    }

    /** PATCH /v1/classifieds/{id} — modifier son annonce (champs envoyés uniquement) */
    public function update(Request $request): Response
    {
        $a = $this->mineOrFail($request);
        $json = $request->json();
        $data = $this->validateListing($json + [
            'title' => $a['title'], 'category' => $a['category_slug'], 'description' => $a['description'],
            'is_free' => (bool) $a['is_free'], 'price_cents' => (int) $a['price_cents'], 'condition' => $a['item_condition'],
            'location_label' => $a['location_label'], 'neighborhood_id' => $a['neighborhood_id'] === null ? null : (int) $a['neighborhood_id'],
        ], $request->attributes['user_id'], true, (int) $a['id']);

        $fields = [
            'title' => $data['title'], 'category_id' => $data['category_id'], 'description' => $data['description'],
            'price_cents' => $data['price_cents'], 'is_free' => $data['is_free'] ? 1 : 0, 'item_condition' => $data['condition'],
            'location_label' => $data['location_label'], 'neighborhood_id' => $data['neighborhood_id'],
        ];
        $this->classifieds->update((int) $a['id'], $fields, array_key_exists('media_ids', $json) ? $data['media_ids'] : null);

        return $this->show($request);
    }

    /**
     * PATCH /v1/classifieds/{id}/status {status: active|reserved|sold, renew?: true}
     * Marquer réservé / vendu, remettre en ligne, ou prolonger une annonce expirée.
     */
    public function status(Request $request): Response
    {
        $a = $this->mineOrFail($request, allowExpired: true);
        $data = Validator::validate($request->json(), ['status' => 'required|in:active,reserved,sold', 'renew' => 'nullable|boolean']);
        $fields = ['status' => $data['status']];
        if (!empty($data['renew']) || ($data['status'] === 'active' && $a['status'] === 'expired')) {
            $fields['expires_at'] = gmdate('Y-m-d H:i:s', time() + (int) Config::get('classifieds.duration_days', 60) * 86400);
        }
        $this->classifieds->update((int) $a['id'], $fields, null);
        if ($data['status'] === 'sold') {
            Events::dispatch('classifieds.sold', ['user_id' => $request->attributes['user_id'], 'classified_uid' => $a['uid'], 'is_free' => (bool) $a['is_free']]);
        }

        return $this->show($request);
    }

    /** DELETE /v1/classifieds/{id} */
    public function delete(Request $request): Response
    {
        $a = $this->mineOrFail($request, allowExpired: true);
        $mediaIds = array_column(Database::fetchAll('SELECT media_id FROM classified_media WHERE classified_id = :c AND media_id IS NOT NULL', ['c' => $a['id']]), 'media_id');
        $this->classifieds->update((int) $a['id'], ['status' => 'deleted'], []);
        (new MediaStore())->deleteByIds($mediaIds);

        return Response::noContent();
    }

    /** GET /v1/users/me/classifieds — « Mes annonces » (toutes, avec statut) */
    public function mine(Request $request): Response
    {
        $userId = $request->attributes['user_id'];

        return Response::success(array_map(
            static fn (array $a) => ClassifiedPresenter::card($a, null, $userId) + ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($a['expires_at'] . ' UTC'))],
            $this->classifieds->byUser($userId),
        ));
    }

    /**
     * POST /v1/classifieds/{id}/contact {message, client_id?} — bouton « Contacter »
     * Ouvre (ou retrouve) la conversation avec le vendeur et y envoie le premier message.
     */
    public function contact(Request $request): Response
    {
        $a = $this->findOrFail((string) $request->param('id'));
        $userId = $request->attributes['user_id'];
        if ($a['user_id'] === null) {
            throw new HttpException(409, 'contact_external', 'Cette annonce se contacte sur son site d\'origine.', ['url' => $a['external_url']]);
        }
        if ((int) $a['user_id'] === $userId) {
            throw new HttpException(409, 'own_classified', 'C\'est ton annonce.');
        }
        if (!in_array($a['status'], ['active', 'reserved'], true)) {
            throw new HttpException(409, 'classified_unavailable', 'Cette annonce n\'est plus disponible.');
        }
        $data = Validator::validate($request->json(), [
            'message'   => 'required|string|min:2|max:' . Config::get('messaging.message_max', 2000),
            'client_id' => 'nullable|string|max:64',
        ]);

        $messaging = new MessagingService();
        $conversationId = $messaging->open('classified', $a['uid'], $userId, 'buyer', (int) $a['user_id'], 'seller');
        $message = $messaging->send($conversationId, $userId, $data['message'], $data['client_id'] ?? null);
        $conversation = Database::fetch('SELECT uid FROM conversations WHERE id = :c', ['c' => $conversationId]);

        return Response::success([
            'conversation_id' => $conversation['uid'],
            'message'         => MessagingPresenter::message($message, $userId),
        ], 201);
    }

    /** POST /v1/classifieds/{id}/report {reason, details?} */
    public function report(Request $request): Response
    {
        $a = $this->findOrFail((string) $request->param('id'));
        $data = Validator::validate($request->json(), [
            'reason'  => 'required|in:scam,prohibited,duplicate,wrong_category,offensive,other',
            'details' => 'nullable|string|max:500',
        ]);
        if ($this->classifieds->report((int) $a['id'], $request->attributes['user_id'], $data['reason'], $data['details'] ?? null, (int) Config::get('classifieds.auto_hide_reports', 3))) {
            \App\Core\Logger::security('Annonce masquée après signalements', ['classified' => $a['uid']]);
        }

        return Response::success(['reported' => true, 'message' => 'Merci, notre équipe va vérifier cette annonce.']);
    }

    /** Validation commune création / modification. */
    private function validateListing(array $json, int $userId, bool $isUpdate, ?int $classifiedId = null): array
    {
        $limits = (array) Config::get('classifieds.limits');
        $data = Validator::validate($json, [
            'title'           => "required|string|min:{$limits['title_min']}|max:{$limits['title_max']}",
            'category'        => 'required|string|max:40',
            'description'     => "nullable|string|max:{$limits['description_max']}",
            'is_free'         => 'nullable|boolean',
            'price_cents'     => 'nullable|integer|min:0',
            'condition'       => 'required|in:' . implode(',', array_keys((array) Config::get('classifieds.conditions'))),
            'location_label'  => 'nullable|string|max:100',
            'neighborhood_id' => 'nullable|integer',
        ]);

        $errors = [];
        $category = Database::fetch('SELECT id, slug FROM classified_categories WHERE slug = :s', ['s' => $data['category']]);
        if ($category === null) {
            $errors['category'] = 'Catégorie inconnue.';
        }
        $isFree = (bool) ($data['is_free'] ?? false);
        $price = $isFree ? 0 : (int) ($data['price_cents'] ?? 0);
        if (!$isFree && ($price <= 0 || $price > $limits['price_max_euros'] * 100)) {
            $errors['price_cents'] = 'Indique un prix (ou coche « Je donne gratuitement »).';
        }

        // Localisation : saisie, sinon quartier du profil (« Par défaut : Centre-ville »)
        $hoodId = $data['neighborhood_id'] ?? null;
        $location = $data['location_label'] ?? null;
        $profile = Database::fetch('SELECT u.neighborhood_id, n.name FROM users u LEFT JOIN neighborhoods n ON n.id = u.neighborhood_id WHERE u.id = :u', ['u' => $userId]);
        if ($hoodId !== null) {
            $hood = Database::fetch('SELECT name FROM neighborhoods WHERE id = :id', ['id' => $hoodId]);
            if ($hood === null) {
                $errors['neighborhood_id'] = 'Quartier inconnu.';
            }
            $location ??= $hood['name'] ?? null;
        } elseif ($location === null) {
            $hoodId = $profile['neighborhood_id'] ?? null;
            $location = $profile['name'] ?? 'Clermont-Ferrand';
        }

        // Photos : 0 à 6. En modification, les photos déjà sur l'annonce peuvent être réutilisées (réordonnées).
        $mediaIds = [];
        if (array_key_exists('media_ids', $json)) {
            $input = is_array($json['media_ids']) ? array_values(array_unique($json['media_ids'])) : [];
            if (count($input) > $limits['photos_max']) {
                $errors['media_ids'] = "{$limits['photos_max']} photos maximum.";
            } else {
                $existing = $classifiedId === null ? [] : array_column(Database::fetchAll(
                    'SELECT m.uid, m.id FROM classified_media cm JOIN media m ON m.id = cm.media_id WHERE cm.classified_id = :c',
                    ['c' => $classifiedId],
                ), 'id', 'uid');
                $new = array_values(array_filter($input, static fn ($u) => is_string($u) && !isset($existing[strtolower($u)])));
                $claimed = array_column((new MediaStore())->claim($new, 'image', $userId, 'media_ids'), 'id', 'uid');
                foreach ($input as $u) {
                    $mediaIds[] = (int) ($existing[strtolower((string) $u)] ?? $claimed[strtolower((string) $u)]);
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title'           => $data['title'],
            'category_id'     => (int) $category['id'],
            'category_slug'   => $category['slug'],
            'description'     => $data['description'] ?? null,
            'is_free'         => $isFree,
            'price_cents'     => $price,
            'condition'       => $data['condition'],
            'location_label'  => $location,
            'neighborhood_id' => $hoodId,
            'media_ids'       => $mediaIds,
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + (int) Config::get('classifieds.duration_days', 60) * 86400),
        ];
    }

    private function findOrFail(string $id): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($a = $this->classifieds->find($id)) === null) {
            throw new HttpException(404, 'classified_not_found', 'Annonce introuvable.');
        }

        return $a;
    }

    private function mineOrFail(Request $request, bool $allowExpired = false): array
    {
        $a = $this->findOrFail((string) $request->param('id'));
        if ($a['user_id'] === null || (int) $a['user_id'] !== $request->attributes['user_id']) {
            throw HttpException::forbidden('not_owner', 'Ce n\'est pas ton annonce.');
        }
        if (!$allowExpired && $a['status'] === 'expired') {
            throw new HttpException(409, 'classified_expired', 'Annonce expirée : remets-la en ligne d\'abord.');
        }

        return $a;
    }

    private function withParam(Request $request, string $uid): Request
    {
        $request->params['id'] = $uid;

        return $request;
    }

    private function encodeCursor(array $row, string $sort): string
    {
        $value = $sort === 'recent' ? $row['published_at'] : (string) $row['price_cents'];

        return rtrim(strtr(base64_encode($sort . '|' . $value . '|' . $row['id']), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor, string $sort): array
    {
        $decoded = (string) base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!preg_match('/^(recent|price_asc|price_desc)\|([0-9: -]+)\|(\d+)$/', $decoded, $m) || $m[1] !== $sort) {
            throw new ValidationException(['cursor' => 'Curseur invalide.']);
        }

        return [$sort === 'recent' ? $m[2] : (int) $m[2], (int) $m[3]];
    }
}
