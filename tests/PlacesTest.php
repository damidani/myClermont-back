<?php

declare(strict_types=1);

use App\Cache\Cache;
use App\Modules\Places\Geo;
use App\Modules\Places\OpeningHours;
use App\Modules\Places\PinService;

/** Injecte un jeu de repères en cache (comme après le premier chargement depuis MySQL). */
function seed_pins(array $pins): void
{
    Cache::store()->set('places:pins', [
        'version'    => 'v-test',
        'categories' => [
            ['slug' => 'monuments', 'name' => 'Monuments', 'icon' => 'star', 'color' => '#D63A5E'],
            ['slug' => 'restaurants', 'name' => 'Restaurants', 'icon' => 'fork-knife', 'color' => '#E2703A'],
        ],
        'pins' => $pins,
    ], 600);
}

const JAUDE = [45.7773, 3.0816];

return [
    'distances et libellés' => function () {
        $d = Geo::distance(JAUDE[0], JAUDE[1], 45.7726, 2.9644); // place de Jaude → puy de Dôme
        assert_true($d > 9000 && $d < 9300, "distance obtenue : {$d} m");
        assert_same("120\u{00A0}m", Geo::label(118));
        assert_same("1,4\u{00A0}km", Geo::label(1420));
        assert_same("12\u{00A0}km", Geo::label(12345));
        assert_same(null, Geo::parse('abc'));
    },

    'horaires : ouvert / fermé / nuit / 24 h' => function () {
        $tz = new DateTimeZone('Europe/Paris');
        $hours = json_encode(['wed' => [['09:00', '12:00'], ['14:00', '19:00']], 'fri' => [['19:00', '02:00']]]);
        $at = static fn (string $d) => OpeningHours::status($hours, new DateTimeImmutable($d, $tz));
        assert_same(['open', "Ouvert · ferme à 12\u{00A0}h"], array_values($at('2026-10-28 10:00')));          // mercredi matin
        assert_same(['closed', "Fermé · ouvre à 14\u{00A0}h"], array_values($at('2026-10-28 13:00')));          // pause midi
        assert_same(['closed', 'Fermé'], array_values($at('2026-10-28 20:00')));
        assert_same('open', $at('2026-10-31 01:00')['state'], 'samedi 1 h : plage du vendredi qui passe minuit');
        assert_same('open', OpeningHours::status('{"always_open":true}', new DateTimeImmutable('now'))['state']);
        assert_same('unknown', OpeningHours::status(null, new DateTimeImmutable('now'))['state']);
    },

    'repères : filtres, compteurs, tri du plus proche au plus loin' => function () {
        seed_pins([
            ['far', 45.8000, 3.1500, 1, 0, 'Resto loin'],
            ['jaude', 45.7773, 3.0816, 0, 2, 'Place de Jaude'],
            ['near', 45.7780, 3.0830, 1, 1, 'Resto partenaire'],
        ]);
        $all = (new PinService())->select(JAUDE, null, null);
        assert_same(['jaude', 'near', 'far'], array_column($all['pins'], 0));
        assert_same(['all' => 3, 'partners' => 1, 'must_see' => 1], $all['filters']);
        assert_same([1, 2], array_column($all['categories'], 'count'));

        assert_same(['near'], array_column((new PinService())->select(JAUDE, null, 'partners')['pins'], 0));
        assert_same(['near', 'far'], array_column((new PinService())->select(JAUDE, 'restaurants', null)['pins'], 0));
        assert_same(null, (new PinService())->select(null, null, null)['pins'][0][6], 'sans position : pas de distance');
    },

    'vitesse : 5 000 lieux filtrés et triés en quelques millisecondes' => function () {
        mt_srand(1);
        $pins = [];
        for ($i = 0; $i < 5000; $i++) {
            $pins[] = ["p{$i}", 45.7 + mt_rand(0, 20000) / 100000, 3.0 + mt_rand(0, 20000) / 100000, $i % 2, $i % 3 === 0 ? 2 : 0, "Lieu {$i}"];
        }
        seed_pins($pins);
        $start = hrtime(true);
        $result = (new PinService())->select(JAUDE, null, null);
        $ms = (hrtime(true) - $start) / 1e6;
        $bytes = strlen(json_encode($result['pins']));
        echo sprintf("    → %d repères en %.1f ms, %d Ko JSON (%d Ko compressés)\n", count($result['pins']), $ms, $bytes / 1024, strlen(gzencode(json_encode($result['pins']))) / 1024);
        assert_true($ms < 100, "trop lent : {$ms} ms");
    },

    'API : ETag → 304 si rien n\'a changé' => function () {
        seed_device();
        seed_pins([['jaude', 45.7773, 3.0816, 0, 2, 'Place de Jaude']]);
        $first = app()->handle(signed_query('GET', '/v1/places/pins', 'near=45.7773,3.0816'));
        assert_same(200, $first->status);
        $etag = $first->headers['ETag'];
        $second = app()->handle(signed_query('GET', '/v1/places/pins', 'near=45.7773,3.0816', ['if-none-match' => $etag]));
        assert_same(304, $second->status);
        assert_same(null, $second->payload);
    },

    'API : flux SSE par anneaux de distance (1 km, 3 km, 10 km, reste)' => function () {
        seed_device();
        seed_pins([
            ['a', 45.7780, 3.0830, 0, 0, 'à 150 m'],
            ['b', 45.7900, 3.0900, 0, 0, 'à 1,5 km'],
            ['c', 45.7726, 2.9644, 0, 0, 'à 9 km'],
            ['d', 45.6000, 3.3000, 0, 0, 'à 25 km'],
        ]);
        $res = app()->handle(signed_query('GET', '/v1/places/pins/stream', 'near=45.7773,3.0816'));
        assert_same('text/event-stream; charset=utf-8', $res->headers['Content-Type']);
        $events = [];
        ($res->streamer)(function (string $event, array $data) use (&$events) { $events[] = [$event, $data]; });
        assert_same(['meta', 'pins', 'pins', 'pins', 'pins', 'done'], array_column($events, 0));
        assert_same([[1000, ['a']], [3000, ['b']], [10000, ['c']], [null, ['d']]],
            array_map(static fn ($e) => [$e[1]['ring'], array_column($e[1]['pins'], 0)], array_slice($events, 1, 4)));
    },
];
