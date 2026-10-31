<?php

declare(strict_types=1);

/*
 * Fixtures de l'app iOS (brief back-end §2 à §12) : réponses construites par les vrais presenters à partir des
 * exemples du brief, validées contre src/Support/AppSchemas.php.
 *   php tests/run.php              valide les fixtures avec les autres tests
 *   php tests/run.php --fixtures   les écrit aussi dans fixtures/ et app-integration/fixtures/
 *
 * Les fonctions demo_job(), demo_listing() et demo_classified() viennent des fichiers *Test.php.
 */

use App\Core\Contract;
use App\Support\AppSchemas as S;

/** @return array<string, array{0: mixed, 1: array|null, 2: bool}> fichier => [corps, schéma d'un élément, liste ?] */
function app_fixtures(): array
{
    $list = static fn (array $items) => ['items' => array_values($items), 'nextCursor' => null];
    $c = static fn (array $a) => Contract::camel($a);

    $user = [
        'uid' => 'b278efc6-943a-4c36-a827-d09e71f2f75a', 'email' => 'camille@exemple.fr', 'password_hash' => 'x', 'first_name' => 'Camille',
        'display_name' => 'Camille', 'username' => 'u1', 'phone' => '+33612345678', 'gender' => 'nonBinary', 'gender_detail' => null,
        'pronouns' => 'iel', 'pronouns_detail' => null, 'city' => 'Chamalières', 'postal_code' => '63400', 'city_served' => 1,
        'notify_city_launch' => 0, 'marketing_opt_in' => 0, 'consent_version' => '2026-11-01', 'consented_at' => '2026-11-15 09:12:00',
        'created_at' => '2026-11-15 09:12:00', 'bio' => null, 'avatar_kind' => 'symbol', 'avatar_value' => 'person.crop.circle.fill',
        'notifications_enabled' => 1, 'role' => 'user', 'premium_until' => null, 'points' => 50, 'neighborhood_name' => null,
    ];
    $me = $c(App\Modules\Account\Repositories\UserRepository::presentSpec($user));
    $tokens = ['accessToken' => 'eyJhbGciOiJIUzI1NiIs…', 'refreshToken' => 'mc_rt_…', 'expiresIn' => 900];

    $event = $c(App\Modules\Events\SpecEventController::present([
        'slug' => 'marche-des-producteurs-2026-11-21', 'title' => 'Marché des producteurs', 'kind' => 'marche', 'venue_name' => 'Place Saint-Pierre',
        'address' => null, 'city' => 'Clermont-Ferrand', 'latitude' => '45.7797', 'longitude' => '3.0864', 'starts_at' => '2026-11-21 08:00:00',
        'ends_at' => '2026-11-21 12:00:00', 'schedule_label' => null, 'price_label' => 'Entrée libre', 'age_limit' => null, 'link_url' => null,
        'ticket_url' => null, 'external_url' => null, 'all_day' => 0, 'summary' => 'Fromages AOP, charcuterie et produits locaux.',
        'description' => null, 'image_url' => null, 'place_uid' => null,
    ]));

    $spot = ['id' => 'f3b0c9a2-5d1e-4b7a-9c2d-1e2f3a4b5c6d', 'name' => 'Le Bouchon Auvergnat', 'category' => 'restaurant', 'latitude' => 45.778,
             'longitude' => 3.084, 'subtitle' => 'Place de Jaude', 'phone' => null, 'website' => null, 'is_partner' => true, 'demo_name' => null, 'nature_kind' => null];

    $deal = $c(App\Modules\Deals\DealController::present([
        'uid' => 'c78e01b5-aa03-4a75-84ca-752453b1586d', 'title' => 'Café offert pour un croissant acheté', 'merchant' => 'Café de Jaude',
        'place_uid' => null, 'area' => 'Jaude', 'category' => 'cafe', 'original_cents' => 350, 'reduced_cents' => 180, 'discount_label' => null,
        'exclusive_plus' => 0, 'image_url' => null, 'description' => 'Un café offert pour tout croissant acheté.',
        'conditions' => 'Valable une fois par personne, du lundi au vendredi.', 'expires_at' => '2026-11-30 22:59:00',
    ]));

    $service = $c(App\Modules\Services\ServicePresenter::card([
        'uid' => 'b2f14441-982f-4ea8-9aef-7eedba04743b', 'name' => 'Pattes & Volcans', 'category_slug' => 'animaux', 'category_name' => 'Animaux',
        'category_icon' => 'pawprint.fill', 'neighborhood_name' => null, 'city' => 'Clermont-Ferrand', 'description' => 'Garde et promenade d\'animaux.',
        'summary' => null, 'services' => '["Promenade","Garde","Visites"]', 'hours_label' => '7j/7', 'price_label' => 'À partir de 15 €',
        'latitude' => '45.77', 'longitude' => '3.095', 'logo_url' => null, 'badge_label' => null, 'notice' => null, 'rating_avg' => '4.9',
        'rating_count' => 12, 'display_rating' => null, 'display_rating_label' => null, 'status' => 'published', 'external_url' => null,
        'source_id' => 1, 'source_slug' => 'myclermont', 'source_name' => 'myClermont', 'contact_url' => null, 'phone' => null, 'email' => null, 'website' => null,
    ]));

    $agenda = $c(App\Modules\Events\SpecEventController::agendaItem([
        'slug' => 'balade-des-volcans-2026-11-08', 'title' => 'Balade des volcans', 'kind' => null, 'category_slug' => 'nature',
        'venue_name' => 'Chaîne des Puys', 'city' => 'secteur Clermont', 'starts_at' => '2026-11-08 08:00:00', 'ends_at' => '2026-11-08 11:00:00',
        'all_day' => 0, 'price_label' => 'Gratuit', 'image_url' => null, 'summary' => 'Sortie guidée à travers les puys.', 'description' => null,
        'latitude' => '45.7726', 'longitude' => '2.9644',
    ]));

    $comment = ['uid' => '7c1e2b0a-4d55-4a8e-9f3b-2a6c1d9e8f10', 'user_id' => 4, 'body' => 'Carrément !', 'created_at' => '2026-11-16 18:41:00',
                'author_uid' => 'a9c1d2e3-0000-4000-8000-000000000004', 'author_username' => 'u4', 'author_display_name' => 'Léo',
                'author_first_name' => 'Léo', 'author_status' => 'active'];
    $post = $c(App\Modules\Community\PostPresenter::post(
        ['id' => 7, 'uid' => 'd045a91b-fa20-44fd-a11c-c09199c2f7ff', 'user_id' => 3, 'type' => 'poll', 'body' => 'Vide-grenier en décembre ?',
         'media_caption' => null, 'likes_count' => 3, 'comments_count' => 1, 'created_at' => '2026-11-16 18:20:00', 'neighborhood_name' => 'Montferrand',
         'author_uid' => 'b278efc6-943a-4c36-a827-d09e71f2f75a', 'author_username' => 'u1', 'author_display_name' => 'Camille',
         'author_first_name' => 'Camille', 'author_status' => 'active'],
        ['media' => [], 'liked' => [], 'votes' => [], 'comments' => [7 => [$comment]],
         'options' => [7 => [['id' => 20, 'label' => 'Oui', 'votes_count' => 12], ['id' => 21, 'label' => 'Non', 'votes_count' => 4]]]],
        null,
    ));

    $classified = $c(App\Modules\Classifieds\ClassifiedPresenter::card(demo_classified([
        'uid' => 'e38ba020-a771-4a14-bcc0-a5955cfef1ff', 'title' => 'Bureau en bois', 'description' => 'Bureau en bois massif, quelques traces d\'usage.',
        'price_cents' => 9000, 'is_free' => 0, 'location_label' => 'Clermont-Ferrand', 'badge_label' => null, 'category_slug' => 'maison-deco',
        'category_name' => 'Maison & déco', 'seller_first_name' => 'Camille', 'published_at' => '2026-10-28 09:31:09', 'created_at' => '2026-10-28 09:31:09',
    ])));

    $fuel = $c(App\Modules\Fuel\FuelService::map([
        'id' => 63000019, 'adresse' => '2 AVENUE MARX DORMOY', 'ville' => 'CLERMONT-FERRAND', 'geom' => ['lat' => 45.771719, 'lon' => 3.080998],
        'gazole_prix' => '2.49', 'gazole_maj' => '2026-10-29T21:00:00+00:00', 'e10_prix' => '2.19', 'e10_maj' => '2026-10-29T21:00:00+00:00',
        'horaires_automate_24_24' => 'Oui',
    ]) + ['distance' => 749]);

    $traffic = ['updatedAt' => '2026-11-15T08:40:00Z', 'incidents' => [$c(App\Modules\Mobility\TrafficController::map([
        'geometry'   => ['type' => 'LineString', 'coordinates' => [[3.0820, 45.7772], [3.0855, 45.7760]]],
        'properties' => ['id' => 'TTI-1234', 'iconCategory' => 'roadWorks', 'magnitudeOfDelay' => 'moderate', 'startTime' => '2026-11-15T07:00:00Z',
                         'endTime' => '2026-11-20T18:00:00Z', 'from' => 'Place de Jaude', 'to' => 'Boulevard François-Mitterrand', 'lengthInMeters' => 650,
                         'delayInSeconds' => 240, 'roadNumbers' => ['D2009'], 'events' => [['code' => 701, 'description' => 'Voie de droite neutralisée']]],
    ]) + ['distance' => 420])]];

    $assistant = $c(['text' => 'Trois adresses à moins de 5 minutes à pied :', 'follow_ups' => ['Que faire ce soir ?', 'Où se garer près de Jaude ?'],
        'cards' => App\Modules\Assistant\CardBuilder::cards(['place:1'], ['place:1' => ['type' => 'place', 'spot' => $spot, 'distance' => 350]])]);

    return [
        'events.json'            => [$list([$event]), S::event(), true],
        'event.json'             => [$event, S::event(), false],
        'me.json'                => [$me, S::userProfile(), false],
        'session.json'           => [$tokens + ['user' => $me], S::session(), false],
        'social-session-new.json'=> [$tokens + ['isNewUser' => true, 'user' => null, 'email' => 'abc123@privaterelay.appleid.com', 'firstName' => 'Camille'], null, false],
        'places.json'            => [$list([$c($spot)]), S::mapSpot(), true],
        'jobs.json'              => [$list([$c(App\Modules\Jobs\JobPresenter::card(demo_job([
            'uid' => '091602b3-5da6-48c9-92d1-b5f92cabb570', 'company_name' => 'Studio Volcan', 'badge_label' => null, 'category_icon' => 'iphone',
            'summary' => 'Conception d\'interfaces iOS, SwiftUI, tests et collaboration produit.', 'notice' => null,
        ])))]), S::job(), true],
        'housing.json'           => [$list([$c(App\Modules\Housing\HousingPresenter::card(demo_listing([
            'uid' => '6b043657-2615-4ee5-9336-550a44a2a9ba', 'image_caption' => null, 'notice' => null, 'badge_label' => null,
        ])))]), S::housing(), true],
        'deals.json'             => [$list([$deal]), S::deal(), true],
        'services.json'          => [$list([$service]), S::service(), true],
        'agenda.json'            => [$list([$agenda]), S::agenda(), true],
        'community-posts.json'   => [$list([$post]), S::post(), true],
        'comments.json'          => [$list([$post['comments'][0]]), S::comment(), true],
        'classifieds.json'       => [$list([$classified]), S::classified(), true],
        'fuel-stations.json'     => [$list([$fuel]) + ['updatedAt' => '2026-10-29T21:05:00Z'], S::fuelStation(), true],
        'traffic-incidents.json' => [$traffic, ['incidents' => 'list:' . json_encode(S::trafficIncident())], false],
        'assistant-answer.json'  => [$assistant + ['conversationId' => '5f0c7a1e-2b3d-4c5e-8f90-a1b2c3d4e5f6'], S::assistantAnswer(), false],
        'me-favorites.json'      => [$list([['key' => 'spot-apple-48213', 'createdAt' => '2026-11-15T09:12:00Z'],
                                            ['key' => "place-Cathédrale Notre-Dame-de-l'Assomption", 'createdAt' => '2026-11-14T18:02:00Z']]),
                                     ['key' => 'string', 'createdAt' => 'date'], true],
        'error-account-required.json' => [['error' => ['code' => 'unauthenticated', 'message' => 'Crée ton compte gratuit pour continuer avec Clermont IA.',
                                            'details' => ['reason' => 'account_required']]], null, false],
    ];
}

/** @return list<string> erreurs de validation des fixtures */
function check_fixtures(): array
{
    $errors = [];
    foreach (app_fixtures() as $file => [$body, $schema, $isList]) {
        if ($schema === null) {
            continue;
        }
        $targets = $isList ? $body['items'] : [$body];
        foreach ($targets as $i => $item) {
            foreach (S::validate($item, $schema) as $e) {
                $errors[] = "{$file}[{$i}] {$e}";
            }
        }
        if ($isList && !array_key_exists('nextCursor', $body)) {
            $errors[] = "{$file} : nextCursor manquant";
        }
    }

    return $errors;
}

function write_fixtures(string $root): int
{
    $n = 0;
    foreach (app_fixtures() as $file => [$body]) {
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        foreach (['fixtures', 'app-integration/fixtures'] as $dir) {
            file_put_contents("{$root}/{$dir}/{$file}", $json);
        }
        $n++;
    }

    return $n;
}
