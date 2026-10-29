<?php

declare(strict_types=1);

use App\Modules\Explore\ExplorePresenter;
use App\Modules\Explore\Link;
use App\Modules\Explore\WeatherService;

return [
    'en-tête : salutation selon l\'heure de Clermont et date courte' => function () {
        $tz = new DateTimeZone('Europe/Paris');
        $evening = ExplorePresenter::greeting('Alex', new DateTimeImmutable('2026-10-28 20:40', $tz));
        assert_same(['Bonsoir, Alex', 'mer. 28 oct.'], [$evening['greeting'], $evening['date_label']]);
        assert_same('Bonjour', ExplorePresenter::greeting(null, new DateTimeImmutable('2026-10-28 09:00', $tz))['greeting']);
        assert_same('Bonsoir', ExplorePresenter::greeting(null, new DateTimeImmutable('2026-10-28 02:00', $tz))['greeting']);
    },

    'météo : codes WMO convertis' => function () {
        assert_same('cloudy', WeatherService::fromWmo(3, true)['condition']);
        assert_same('moon', WeatherService::fromWmo(0, false)['icon']);
        assert_same('rain', WeatherService::fromWmo(81, true)['condition']);
        assert_same('snow', WeatherService::fromWmo(73, true)['condition']);
        assert_same('storm', WeatherService::fromWmo(95, true)['condition']);
    },

    'liens profonds validés' => function () {
        assert_same(['type' => 'event', 'id' => 'abc', 'url' => null], Link::from('event', 'abc', null));
        assert_same(null, Link::from('url', null, 'http://non-https.example'), 'HTTPS obligatoire');
        assert_same(null, Link::from('inconnu', 'x', null));
        assert_same(null, Link::from(null, null, null));
    },

    'stories : vue recalculée si la story a été modifiée depuis' => function () {
        $story = ['id' => 7, 'uid' => 's', 'title' => 'Les volcans', 'subtitle' => null, 'cover_url' => 'https://x', 'icon' => null, 'updated_at' => '2026-10-28 10:00:00'];
        assert_same(true, ExplorePresenter::story($story, [7 => '2026-10-28 11:00:00'])['seen']);
        assert_same(false, ExplorePresenter::story($story, [7 => '2026-10-28 09:00:00'])['seen'], 'modifiée après la vue');
        assert_same(null, ExplorePresenter::story($story, null)['seen'], 'non connecté');
    },

    'exclusivités : le code n\'apparaît jamais dans la carte ni la fiche' => function () {
        $offer = [
            'uid' => 'o1', 'partner_name' => 'Café', 'title' => 'Brunch', 'subtitle' => null, 'discount_label' => '-30 %', 'icon' => 'fork-knife',
            'image_url' => null, 'section_slug' => 'restaurants', 'section_name' => 'Restaurants', 'badge_label' => 'Exclusivité My Clermont',
            'ends_at' => null, 'max_claims' => 50, 'claims_count' => 48, 'premium_only' => 0, 'code' => 'SECRET30',
            'description' => null, 'conditions' => null, 'address' => null, 'latitude' => null, 'longitude' => null,
            'redemption_mode' => 'code', 'members_only' => 1, 'notice' => null, 'status' => 'published',
        ];
        $json = json_encode([ExplorePresenter::offer($offer), ExplorePresenter::offerDetail($offer, null, null)]);
        assert_true(!str_contains($json, 'SECRET30'));
        assert_same(2, ExplorePresenter::offer($offer)['stock_left']);
    },

    'routes : recherche validée, « Profiter de l\'offre » exige un compte' => function () {
        seed_device();
        assert_same('validation_failed', err(app()->handle(signed_request('GET', '/v1/explore/search'))));
        assert_same('unauthenticated', err(app()->handle(signed_request('POST', '/v1/explore/offers/0e8b3c4a-1111-4222-8333-444455556666/claim'))));
        assert_same('unauthenticated', err(app()->handle(signed_request('POST', '/v1/explore/stories/0e8b3c4a-1111-4222-8333-444455556666/view'))));
    },
];
