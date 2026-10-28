<?php

declare(strict_types=1);

namespace App\Modules\Explore;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;
use App\Modules\Premium\PremiumService;

final class ExploreController
{
    private ExploreRepository $explore;

    public function __construct()
    {
        $this->explore = new ExploreRepository();
    }

    /**
     * GET /v1/explore?section=restaurants — tout l'écran « Explorer » en un seul appel.
     *
     * La partie commune (stories, flash, offres, à découvrir) est mise en cache 60 s ;
     * seuls « vu », favoris et salutation sont calculés pour l'utilisateur.
     */
    public function home(Request $request): Response
    {
        $section = ($request->query()['section'] ?? '') !== '' ? (string) $request->query()['section'] : null;
        $sections = $this->sections();
        if ($section !== null && !in_array($section, array_column($sections, 'slug'), true)) {
            throw new ValidationException(['section' => 'Rubrique inconnue.']);
        }

        $cacheKey = 'explore:home:' . ($section ?? 'all');
        $public = Cache::isInMemory() ? Cache::store()->get($cacheKey) : null;
        if (!is_array($public)) {
            $pageSize = (int) Config::get('explore.discover_page_size', 20);
            $public = [
                'stories'    => $this->explore->stories(),
                'highlights' => array_map(ExplorePresenter::highlight(...), $this->explore->highlights()),
                'offers'     => $this->explore->offers($section, (int) Config::get('explore.offers_on_home', 10)),
                'items'      => $this->explore->items($section, $pageSize, 0),
                'items_total' => $this->explore->itemsCount($section),
            ];
            if (Cache::isInMemory()) {
                Cache::store()->set($cacheKey, $public, (int) Config::get('explore.home_cache_ttl', 60));
            }
        }

        $userId = $request->attributes['user_id'] ?? null;
        $username = $userId === null ? null : (Database::fetch('SELECT username FROM users WHERE id = :u', ['u' => $userId])['username'] ?? null);
        $viewed = $userId === null ? null : $this->explore->viewedStories($userId);
        $favOffers = $this->favorites($userId, 'offer', array_column($public['offers'], 'uid'));
        $favItems = $this->favorites($userId, 'explore_item', array_column($public['items'], 'uid'));

        return Response::success([
            'header'     => ExplorePresenter::greeting($username) + ['weather' => (new WeatherService())->current()],
            'sections'   => $sections,                                                              // puces « Tout · Restaurants · … »
            'stories'    => array_map(static fn (array $s) => ExplorePresenter::story($s, $viewed), $public['stories']),
            'flash'      => $public['highlights'][0] ?? null,                                       // « Événement Flash »
            'highlights' => $public['highlights'],
            'offers'     => array_map(static fn (array $o) => ExplorePresenter::offer($o, $favOffers === null ? null : in_array($o['uid'], $favOffers, true)), $public['offers']),
            'discover'   => [
                'items'     => array_map(static fn (array $i) => ExplorePresenter::item($i, $favItems === null ? null : in_array($i['uid'], $favItems, true)), $public['items']),
                'total'     => $public['items_total'],                                             // « 13 adresses »
                'next_page' => $public['items_total'] > count($public['items']) ? 2 : null,
            ],
        ]);
    }

    /** GET /v1/explore/discover?section=&page=2 — suite de « À découvrir » */
    public function discover(Request $request): Response
    {
        $q = $request->query();
        $section = ($q['section'] ?? '') !== '' ? (string) $q['section'] : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = (int) Config::get('explore.discover_page_size', 20);
        $rows = $this->explore->items($section, $size, ($page - 1) * $size);
        $total = $this->explore->itemsCount($section);
        $fav = $this->favorites($request->attributes['user_id'] ?? null, 'explore_item', array_column($rows, 'uid'));

        return Response::success(
            array_map(static fn (array $i) => ExplorePresenter::item($i, $fav === null ? null : in_array($i['uid'], $fav, true)), $rows),
            200,
            ['total' => $total, 'page' => $page, 'next_page' => $page * $size < $total ? $page + 1 : null],
        );
    }

    /** GET /v1/explore/stories/{id} — diapositives d'une story */
    public function story(Request $request): Response
    {
        $story = $this->explore->story((string) $request->param('id'));
        if ($story === null) {
            throw new HttpException(404, 'story_not_found', 'Story introuvable.');
        }
        $userId = $request->attributes['user_id'] ?? null;

        return Response::success(
            ExplorePresenter::story($story, $userId === null ? null : $this->explore->viewedStories($userId))
            + ['slides' => array_map(ExplorePresenter::slide(...), $this->explore->slides((int) $story['id']))],
        );
    }

    /** POST /v1/explore/stories/{id}/view — marque la story comme vue (anneau grisé) */
    public function storyViewed(Request $request): Response
    {
        $story = $this->explore->story((string) $request->param('id'));
        if ($story === null) {
            throw new HttpException(404, 'story_not_found', 'Story introuvable.');
        }
        $this->explore->markStoryViewed((int) $story['id'], $request->attributes['user_id']);

        return Response::noContent();
    }

    /** GET /v1/explore/offers?section= — toutes les exclusivités */
    public function offers(Request $request): Response
    {
        $section = ($request->query()['section'] ?? '') !== '' ? (string) $request->query()['section'] : null;
        $rows = $this->explore->offers($section, 100);
        $fav = $this->favorites($request->attributes['user_id'] ?? null, 'offer', array_column($rows, 'uid'));

        return Response::success(array_map(static fn (array $o) => ExplorePresenter::offer($o, $fav === null ? null : in_array($o['uid'], $fav, true)), $rows));
    }

    /** GET /v1/explore/offers/{id} */
    public function offer(Request $request): Response
    {
        $offer = $this->offerOrFail($request);
        $userId = $request->attributes['user_id'] ?? null;

        return Response::success(ExplorePresenter::offerDetail(
            $offer,
            $userId === null ? null : $this->favorites($userId, 'offer', [$offer['uid']]) !== [],
            $userId !== null && $this->explore->userClaims((int) $offer['id'], $userId) > 0 ? $this->claimPayload($offer) : null,
        ));
    }

    /**
     * POST /v1/explore/offers/{id}/claim — « Profiter de l'offre »
     * Révèle le code / l'écran à présenter. Rejouer la requête après la limite renvoie la même chose sans recompter.
     */
    public function claim(Request $request): Response
    {
        $offer = $this->offerOrFail($request);
        $userId = $request->attributes['user_id'];
        $active = $offer['status'] === 'published' && ($offer['ends_at'] === null || strtotime($offer['ends_at'] . ' UTC') > time())
            && ($offer['starts_at'] === null || strtotime($offer['starts_at'] . ' UTC') <= time());
        if (!$active) {
            throw new HttpException(409, 'offer_inactive', 'Cette offre n\'est plus disponible.');
        }
        if ($offer['premium_only'] && !PremiumService::isActiveById($userId)) {
            throw new HttpException(402, 'premium_required', 'Cette exclusivité est réservée aux membres premium.');
        }

        $already = $this->explore->userClaims((int) $offer['id'], $userId);
        if ($already >= (int) $offer['per_user_limit']) {
            return Response::success($this->claimPayload($offer) + ['already_claimed' => true]);
        }
        if (!$this->explore->claim((int) $offer['id'], $userId)) {
            throw new HttpException(409, 'offer_sold_out', 'Cette offre n\'est plus disponible : stock épuisé.');
        }
        // Le stock affiché sur l'accueil (cache 60 s) se met à jour seul ; la fiche, elle, est toujours exacte
        Events::dispatch('explore.offer_claimed', ['user_id' => $userId, 'offer_uid' => $offer['uid']]);

        return Response::success($this->claimPayload($offer) + ['already_claimed' => false], 201);
    }

    /** GET /v1/explore/search?q=brunch — recherche globale, résultats regroupés par module */
    public function search(Request $request): Response
    {
        $query = trim((string) ($request->query()['q'] ?? ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new ValidationException(['q' => 'Entre 2 et 100 caractères.']);
        }
        $groups = (new GlobalSearch())->search($query, (int) Config::get('explore.search_per_type', 5));

        return Response::success($groups, 200, ['query' => $query, 'count' => array_sum(array_map(static fn ($g) => count($g['items']), $groups))]);
    }

    /** GET /v1/explore/weather */
    public function weather(Request $request): Response
    {
        return Response::success((new WeatherService())->current());
    }

    private function claimPayload(array $offer): array
    {
        return [
            'redemption_mode' => $offer['redemption_mode'],
            'code'            => $offer['redemption_mode'] === 'code' ? $offer['code'] : null,
            'external_url'    => $offer['redemption_mode'] === 'external' ? $offer['external_url'] : null,
            // Mode « show » : écran à présenter en caisse (titre, réduction, partenaire, horodatage anti-capture)
            'show'            => $offer['redemption_mode'] === 'show' ? [
                'title' => $offer['title'], 'discount' => $offer['discount_label'], 'partner' => $offer['partner_name'],
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            ] : null,
            'conditions'      => $offer['conditions'],
        ];
    }

    private function offerOrFail(Request $request): array
    {
        $id = (string) $request->param('id');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($o = $this->explore->offer($id)) === null) {
            throw new HttpException(404, 'offer_not_found', 'Offre introuvable.');
        }

        return $o;
    }

    private function sections(): array
    {
        return array_map(static fn (array $s) => ['slug' => $s['slug'], 'name' => $s['name'], 'icon' => $s['icon'], 'is_chip' => (bool) $s['is_chip']], $this->explore->sections());
    }

    private function favorites(?int $userId, string $type, array $uids): ?array
    {
        return $userId === null ? null : (new FavoriteRepository())->filterFavorited($userId, $type, $uids);
    }
}
