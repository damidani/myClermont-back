<?php

declare(strict_types=1);

use App\Modules\Events\EventPresenter;
use App\Modules\Events\Ics;
use App\Modules\Favorites\FavoriteTypes;

function demo_event(array $override = []): array
{
    return $override + [
        'id' => 1, 'uid' => '0e8b3c4a-1111-4222-8333-444455556666', 'slug' => 'match-de-rugby',
        'title' => 'Match de rugby — soirée supporters', 'description' => "Une soirée autour du rugby clermontois.\nDémo.",
        'image_url' => 'https://cdn.example/rugby.jpg', 'image_caption' => 'Visuel d\'illustration · démo',
        'starts_at' => '2026-10-28 18:00:00', 'ends_at' => '2026-10-28 20:00:00', // UTC
        'venue_name' => 'Stade Marcel-Michelin', 'city' => 'Clermont-Ferrand', 'address' => '107 Avenue de la République',
        'latitude' => '45.789900', 'longitude' => '3.116200', 'neighborhood_id' => null,
        'price_label' => 'Billetterie à confirmer', 'price_min_cents' => null, 'ticket_url' => null,
        'capacity' => 100, 'participants_count' => 100, 'tags' => 'volcan', 'notice' => 'Événement fictif.',
        'status' => 'published', 'is_featured' => 1,
        'category_slug' => 'sport', 'category_name' => 'Sport', 'category_icon' => 'sport', 'category_color' => '#D63A5E',
    ];
}

return [
    'carte : heure de Paris, durée, tarif, état' => function () {
        $card = EventPresenter::card(demo_event());
        assert_same('2026-10-28T19:00:00+01:00', $card['starts_at'], 'UTC 18h = 19h à Paris en hiver');
        assert_same(120, $card['duration_minutes']);
        assert_same('Stade Marcel-Michelin', $card['venue']['name']);
        assert_same(45.7899, $card['venue']['latitude']);
        assert_same(false, $card['price']['is_free']);
        assert_same(null, $card['is_favorite'], 'non connecté => null');
    },

    'états : à venir, en cours, annulé' => function () {
        $fmt = static fn (int $t) => gmdate('Y-m-d H:i:s', $t);
        assert_same('upcoming', EventPresenter::card(demo_event(['starts_at' => $fmt(time() + 3600), 'ends_at' => $fmt(time() + 7200)]))['state']);
        assert_same('ongoing', EventPresenter::card(demo_event(['starts_at' => $fmt(time() - 60), 'ends_at' => $fmt(time() + 60)]))['state']);
        assert_same('cancelled', EventPresenter::card(demo_event(['status' => 'cancelled']))['state']);
    },

    'fiche : programme, complet, lien de partage' => function () {
        $d = EventPresenter::detail(demo_event(), [['time' => '18:00', 'label' => 'Accueil du public']], ['is_favorite' => true, 'is_participating' => false]);
        assert_same(true, $d['is_full']);
        assert_same(true, $d['is_favorite']);
        assert_same('Accueil du public', $d['programme'][0]['label']);
        assert_true(str_ends_with($d['share_url'], '/match-de-rugby'));
        assert_same('/v1/events/0e8b3c4a-1111-4222-8333-444455556666/calendar.ics', $d['calendar_url']);
    },

    'fichier calendrier .ics valide' => function () {
        $ics = Ics::build(demo_event());
        assert_true(str_starts_with($ics, "BEGIN:VCALENDAR\r\n"));
        assert_true(str_contains($ics, "DTSTART:20261028T180000Z\r\n"));
        assert_true(str_contains($ics, 'LOCATION:Stade Marcel-Michelin\, 107 Avenue'), 'virgules échappées');
        assert_true(str_contains($ics, 'GEO:45.789900;3.116200'));
        foreach (explode("\r\n", $ics) as $line) {
            assert_true(strlen($line) <= 75, 'ligne trop longue : ' . $line);
        }
    },

    'le type de favori "event" est enregistré' => function () {
        App\Core\App::bootModules();
        assert_true(in_array('event', FavoriteTypes::all(), true));
    },

    'participation : connexion obligatoire' => function () {
        seed_device();
        $res = app()->handle(signed_request('PUT', '/v1/events/0e8b3c4a-1111-4222-8333-444455556666/participation'));
        assert_same('unauthenticated', err($res));
    },

    'auth:optional refuse un jeton invalide' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/events/' . str_repeat('a', 130), '', ['headers' => ['authorization' => 'Bearer faux']]));
        assert_same('invalid_token', err($res));
    },

    'identifiant malformé => 404 sans requête SQL' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/events/' . str_repeat('a', 130)));
        assert_same('event_not_found', err($res));
    },
];
