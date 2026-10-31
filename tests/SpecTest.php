<?php

declare(strict_types=1);

use App\Core\Contract;
use App\Core\Request;
use App\Core\Response;
use App\Security\AppleClient;
use App\Security\OidcVerifier;

/*
 * Spec v1 : contrat JSON, connexion Apple / Google (vérification des jetons d'identité).
 */

/** Jeton RS256 signé avec une clé de test, et la JWKS correspondante. */
function oidc_token(array $claims, string $kid = 'k1'): array
{
    static $key = null;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($key)['rsa'];
    $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $input = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid])) . '.' . $b64(json_encode($claims));
    openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);

    return [$input . '.' . $b64($sig), ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => $b64($d['n']), 'e' => $b64($d['e'])]]]];
}

return [
    'contrat : liste => items + nextCursor, clés en camelCase' => function () {
        $r = Contract::apply(Response::success([['created_at' => 'x', 'is_partner' => true]], 200, ['next_cursor' => 'abc', 'total' => 3]));
        assert_same(['items' => [['createdAt' => 'x', 'isPartner' => true]], 'nextCursor' => 'abc', 'total' => 3], $r->payload);
    },

    'contrat : erreur au format de la spec, motif détaillé conservé' => function () {
        $r = Contract::apply(Response::error('listing_not_found', 'Annonce introuvable.', 404));
        assert_same(['error' => ['code' => 'not_found', 'message' => 'Annonce introuvable.', 'details' => ['reason' => 'listing_not_found']]], $r->payload);
        $r = Contract::apply(Response::error('validation_failed', 'Le titre doit faire au moins 3 caractères.', 400, ['title' => 'Le titre doit faire au moins 3 caractères.']));
        assert_same('title', $r->payload['error']['details']['field']);
        assert_same('plus_required', Contract::apply(Response::error('premium_required', 'x', 402))->payload['error']['code']);
    },

    'contrat : objet sans données => 204, JSON brut intact' => function () {
        assert_same(204, Contract::apply(Response::success(null))->status);
        $raw = Contract::apply(Response::json([['start_at' => 1]]));
        assert_same([['start_at' => 1]], $raw->payload);
    },

    'entrée camelCase convertie, clés de données intactes' => function () {
        $req = new Request('POST', '/x', '', [], json_encode(['refreshToken' => 'a', 'ratings' => ['place-Jardin Lecoq' => 4]]), '127.0.0.1');
        assert_same(['refresh_token' => 'a', 'ratings' => ['place-Jardin Lecoq' => 4]], $req->json());
    },

    'Apple : jeton valide accepté, audience et signature vérifiées' => function () {
        [$token, $jwks] = oidc_token(['iss' => 'https://appleid.apple.com', 'aud' => 'com.myclermont.app', 'sub' => '001.abc',
                                      'iat' => time(), 'exp' => time() + 600, 'nonce' => hash('sha256', 'n0nce')]);
        OidcVerifier::$jwksLoader = static fn () => $jwks;
        try {
            assert_same('001.abc', OidcVerifier::verify($token, OidcVerifier::APPLE, ['com.myclermont.app'])['sub']);
            foreach ([['com.autre.app'], null] as $case) {
                try {
                    $case === null
                        ? OidcVerifier::verify(substr($token, 0, -4) . 'AAAA', OidcVerifier::APPLE, ['com.myclermont.app'])
                        : OidcVerifier::verify($token, OidcVerifier::APPLE, $case);
                    throw new LogicException('aurait dû être refusé');
                } catch (App\Exceptions\HttpException $e) {
                    assert_same(401, $e->getCode());
                }
            }
        } finally {
            OidcVerifier::$jwksLoader = null;
        }
    },

    'Apple : jeton expiré refusé' => function () {
        [$token, $jwks] = oidc_token(['iss' => 'https://appleid.apple.com', 'aud' => 'com.myclermont.app', 'sub' => 'x', 'iat' => time() - 7200, 'exp' => time() - 3600]);
        OidcVerifier::$jwksLoader = static fn () => $jwks;
        try {
            OidcVerifier::verify($token, OidcVerifier::APPLE, ['com.myclermont.app']);
            throw new LogicException('aurait dû être refusé');
        } catch (App\Exceptions\HttpException $e) {
            assert_same('invalid_identity_token', $e->errorCode);
        } finally {
            OidcVerifier::$jwksLoader = null;
        }
    },

    'Apple : client_secret ES256 vérifiable' => function () {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $raw = AppleClient::es256($pem, 'payload');
        assert_same(64, strlen($raw));
        // Reconversion r||s → DER puis vérification avec la clé publique
        $int = static function (string $b) { $b = ltrim($b, "\0"); if (ord($b[0]) > 0x7F) { $b = "\0" . $b; } return "\x02" . chr(strlen($b)) . $b; };
        $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
        $der = "\x30" . chr(strlen($der)) . $der;
        assert_same(1, openssl_verify('payload', $der, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
    },

    'routes de connexion montées (signées, validées)' => function () {
        seed_device();
        foreach (['/v1/auth/apple', '/v1/auth/google', '/v1/auth/email/start', '/v1/auth/email/verify', '/v1/auth/refresh'] as $path) {
            $res = app()->handle(signed_request('POST', $path, '{}'));
            assert_true(in_array($res->status, [400, 503], true), $path . ' => ' . $res->status);
        }
        assert_same(401, app()->handle(signed_request('GET', '/v1/me'))->status);
    },

    'filtre automatique : insultes (accords), liens suspects, texte normal accepté' => function () {
        assert_same('words', App\Modules\Moderation\ContentFilter::check('bande de connards'));
        assert_same('words', App\Modules\Moderation\ContentFilter::check('Espèce de C0NNARD'));
        assert_same('links', App\Modules\Moderation\ContentFilter::check('Très sympa, allez-y via bit.ly/xyz'));
        assert_same('links', App\Modules\Moderation\ContentFilter::check('Voir http://192.168.1.4/promo'));
        assert_same(null, App\Modules\Moderation\ContentFilter::check('Super concert à la Coopé, infos sur https://www.lacoope.org'));
        assert_same(null, App\Modules\Moderation\ContentFilter::check('Il y a une connexion wifi ? Je passe à Pascal'));
    },

    'notifications : pas d\'annonce entre 22 h et 8 h' => function () {
        $paris = new DateTimeZone('Europe/Paris');
        $at = static fn (string $d) => (new DateTimeImmutable($d, $paris))->getTimestamp();
        assert_same($at('2026-11-07 08:00'), App\Modules\Messaging\Push\Push::afterQuietHours($at('2026-11-06 23:15')));
        assert_same($at('2026-11-07 08:00'), App\Modules\Messaging\Push\Push::afterQuietHours($at('2026-11-07 06:30')));
        assert_same($at('2026-11-07 17:00'), App\Modules\Messaging\Push\Push::afterQuietHours($at('2026-11-07 17:00')));
    },

    'vidéo : durée lue dans l\'en-tête MP4' => function () {
        // atome mvhd version 0 : timescale 1000, durée 75 000 → 75 s
        $mvhd = 'mvhd' . "\0\0\0\0" . pack('N', 0) . pack('N', 0) . pack('N', 1000) . pack('N', 75000) . str_repeat("\0", 20);
        assert_same(75.0, App\Content\Uploads\ImageProcessor::videoDuration('....moov....' . $mvhd));
    },

    'Clermont IA : cartes seulement pour des résultats réellement renvoyés par les outils' => function () {
        $seen = ['event:concert-2026-11-07' => ['type' => 'event', 'slug' => 'concert-2026-11-07', 'title' => 'Concert', 'kind' => 'concert',
                 'venue_name' => 'La Coopé', 'starts_at' => gmdate('Y-m-d H:i:s', time() + 3 * 86400)]];
        $cards = App\Modules\Assistant\CardBuilder::cards(['event:concert-2026-11-07', 'place:inventé', 42], $seen);
        assert_same(1, count($cards), 'référence inventée ignorée');
        assert_same(['type' => 'event', 'id' => 'concert-2026-11-07'], $cards[0]['link']);
        assert_same(['#FF4F8B', '#7B5CFF'], $cards[0]['colors'], 'couleurs du type concert');
    },

    'Clermont IA : accroche « CE SOIR · 19 H 30 », « DEMAIN · 20 H »' => function () {
        $tz = new DateTimeZone('Europe/Paris');
        $now = new DateTimeImmutable('2026-10-31 10:00', $tz);
        $utc = static fn (string $local) => (new DateTimeImmutable($local, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        assert_same('CE SOIR · 19 H 30', App\Modules\Assistant\CardBuilder::when($utc('2026-10-31 19:30'), $now));
        assert_same('DEMAIN · 20 H', App\Modules\Assistant\CardBuilder::when($utc('2026-11-01 20:00'), $now));
        assert_same('MER. 4 NOV. · 18 H', App\Modules\Assistant\CardBuilder::when($utc('2026-11-04 18:00'), $now));
    },

    'App Store : transaction mal signée refusée' => function () {
        $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $fake = $b64(json_encode(['alg' => 'ES256', 'x5c' => ['QQ==', 'QQ==', 'QQ==']])) . '.' . $b64('{"productId":"x"}') . '.' . $b64(str_repeat('a', 64));
        try {
            App\Modules\Subscriptions\AppleJws::verify($fake);
            throw new LogicException('aurait dû être refusée');
        } catch (App\Exceptions\HttpException $e) {
            assert_same(400, $e->getCode());
        }
    },

    'trafic : incident TomTom Orbis v2 => format de l\'app' => function () {
        $i = App\Modules\Mobility\TrafficController::map([
            'geometry'   => ['type' => 'LineString', 'coordinates' => [[3.0535675173, 45.8258669645], [3.0532389467, 45.8255786007]]],
            'properties' => ['id' => 'TTI-1', 'iconCategory' => 'roadClosed', 'magnitudeOfDelay' => 'undefined', 'startTime' => '2026-03-02T13:16:00Z',
                             'endTime' => null, 'from' => 'Avenue de Clermont', 'to' => 'Rue de l\'Étrade', 'lengthInMeters' => 41.32,
                             'delayInSeconds' => null, 'roadNumbers' => [], 'events' => [['code' => 401, 'description' => 'Route fermée']]],
        ]);
        assert_same('closure', $i['kind']);
        assert_same('Route fermée', $i['title']);
        assert_same(4, $i['magnitude']);
        assert_same(41, $i['length']);
        assert_same([45.82587, 3.05357], $i['path'][0]);
        assert_same('2026-03-02T13:16:00Z', $i['start']);
        assert_same('other', App\Modules\Mobility\TrafficController::map(['geometry' => ['type' => 'Point', 'coordinates' => [3.08, 45.77]], 'properties' => ['id' => 'x', 'iconCategory' => 'unknown']])['kind']);
    },

    'brief §5 : variantes d\'insultes, liens dans les avis, pas de faux positifs' => function () {
        $F = App\Modules\Moderation\ContentFilter::class;
        foreach (['CONNNNARDS', 'c.o.n.n.a.r.d', 'enkulé', 'espèce de bâtard', 'bande de salopes', 'ferme ta gueule'] as $t) {
            assert_same('words', $F::check($t), $t);
        }
        foreach (['dispute entre voisins', 'Un connaisseur', 'Le concert était top', 'Super accueil, 4.5 sur 5', 'Un pouf très confortable'] as $t) {
            assert_same(null, $F::check($t), $t);
        }
        foreach (['allez-y via bit.ly/xyz', 'voir monsite.com', 'https://exemple.org/page', 't.co/abc'] as $t) {
            assert_true($F::hasLink($t), $t);
        }
        foreach (['Accueil au top', 'Place St.Pierre', '2,50 € le café', null] as $t) {
            assert_same(false, $F::hasLink($t), (string) $t);
        }
    },

    'brief §6 : ProfileBody => colonnes ; UserProfile valide le schéma' => function () {
        $P = App\Modules\Account\UserProfile::class;
        $body = App\Core\Request::snake(['firstName' => 'Camille', 'email' => 'camille@exemple.fr', 'password' => 'motdepasse1', 'phone' => '+33612345678',
            'gender' => 'nonBinary', 'genderDetail' => null, 'pronouns' => 'iel', 'pronounsDetail' => null, 'city' => 'Chamalières', 'postalCode' => '63400',
            'cityServed' => true, 'notifyCityLaunch' => false, 'marketingOptIn' => false, 'consentVersion' => '2026-11-01', 'ageConfirmed' => true]);
        $cols = $P::columns($body, true);
        assert_same('Camille', $cols['first_name']);
        assert_same(1, $cols['city_served']);
        $user = $cols + ['uid' => 'b278efc6-943a-4c36-a827-d09e71f2f75a', 'email' => 'camille@exemple.fr', 'password_hash' => 'x',
                         'consented_at' => '2026-11-15 09:12:00', 'created_at' => '2026-11-15 09:12:00'];
        $out = App\Core\Contract::camel($P::present($user));
        assert_same([], App\Support\AppSchemas::validate($out, App\Support\AppSchemas::userProfile()));
        assert_same('email', $out['provider']);
        assert_same(true, $P::present(['uid' => 'x', 'email' => 'abc@privaterelay.appleid.com', 'apple_sub' => 's', 'created_at' => '2026-01-01 00:00:00'])['email_is_private']);

        try {
            $P::columns(['age_confirmed' => false] + $body, true);
            throw new RuntimeException('ageConfirmed false accepté');
        } catch (App\Exceptions\ValidationException $e) {
            assert_same(422, $e->getCode());
            assert_true(str_contains($e->getMessage(), 'âge'));
        }
    },

    'brief §6 : mot de passe = 8 caractères, une lettre, un chiffre' => function () {
        $P = App\Modules\Account\UserProfile::class;
        assert_same('motdepasse1', $P::checkPassword('motdepasse1'));
        foreach (['court1', 'motdepasse', '12345678', null] as $bad) {
            assert_throws(fn () => $P::checkPassword($bad), 'validation_failed');
        }
    },

    'brief §8 : cartes Clermont IA au format de l\'app (2 couleurs, link typé, MapSpot complet)' => function () {
        $spot = ['id' => 'spot-1', 'name' => 'Le Bouchon', 'category' => 'restaurant', 'latitude' => 45.778, 'longitude' => 3.084, 'subtitle' => null,
                 'phone' => null, 'website' => null, 'is_partner' => true, 'demo_name' => null, 'nature_kind' => null];
        $seen = [
            'place:1' => ['type' => 'place', 'spot' => $spot, 'distance' => 350],
            'event:x' => ['type' => 'event', 'slug' => 'concert-2026-11-21', 'title' => 'Concert', 'kind' => 'concert', 'venue_name' => null, 'starts_at' => '2026-11-21 19:00:00'],
            'fuel:1'  => ['type' => 'fuel', 'id' => '63000019', 'fuel' => 'gazole', 'prices' => ['gazole' => ['price' => 1.79]], 'address' => '2 Avenue Marx Dormoy',
                          'city' => 'Clermont-Ferrand', 'latitude' => 45.77, 'longitude' => 3.08, 'distance' => 749],
        ];
        $answer = App\Core\Contract::camel(['text' => 'Voici :', 'follow_ups' => ['Que faire ce soir ?'],
            'cards' => App\Modules\Assistant\CardBuilder::cards(['place:1', 'event:x', 'fuel:1', 'invente:9'], $seen)]);
        assert_same([], App\Support\AppSchemas::validate($answer, App\Support\AppSchemas::assistantAnswer()));
        assert_same(3, count($answer['cards']), 'référence inventée ignorée');
        assert_same(['#FF4F8B', '#7B5CFF'], $answer['cards'][0]['colors']);
        assert_same([], App\Support\AppSchemas::validate($answer['cards'][0]['link']['spot'], App\Support\AppSchemas::mapSpot()));
        assert_same('concert-2026-11-21', $answer['cards'][1]['link']['id']);
        assert_same('fuelStation', $answer['cards'][2]['link']['type']);
        assert_same([], App\Support\AppSchemas::validate($answer['cards'][2]['link']['spot'], App\Support\AppSchemas::mapSpot()));
    },

    'brief §9 : clé de l\'app décodée de l\'URL sans altération, 200 caractères au plus' => function () {
        $F = App\Modules\Favorites\SpecFavoriteController::class;
        $key = "place-Cathédrale Notre-Dame-de-l'Assomption";
        assert_same($key, $F::key(rawurlencode($key)));
        assert_same('spot-apple-48213', $F::key('spot-apple-48213'));
        assert_throws(fn () => $F::key(str_repeat('a', 201)), 'validation_failed');
        assert_throws(fn () => $F::key(''), 'validation_failed');
    },

    'brief §10.1 : station carburant avec automate24h et prix par carburant' => function () {
        $st = App\Modules\Fuel\FuelService::map(['id' => 63000019, 'adresse' => '2 AVENUE MARX DORMOY', 'ville' => 'CLERMONT-FERRAND',
            'geom' => ['lat' => 45.771719, 'lon' => 3.080998], 'gazole_prix' => '2.49', 'gazole_maj' => '2026-10-29T21:00:00+00:00',
            'horaires_automate_24_24' => 'Oui']);
        $out = App\Core\Contract::camel($st + ['distance' => 749]);
        assert_same([], App\Support\AppSchemas::validate($out, App\Support\AppSchemas::fuelStation()));
        assert_same(true, $out['automate24h']);
        assert_same(['price' => 2.49, 'updatedAt' => '2026-10-29T21:00:00Z'], $out['prices']['gazole']);
        assert_same(false, isset($out['prices']['e10']), 'carburant absent omis');
    },

    'brief §11 : emploi, logement, bons plans, services, agenda valident les schémas de l\'app' => function () {
        $S = App\Support\AppSchemas::class;
        $C = static fn (array $a) => App\Core\Contract::camel($a);
        assert_same([], $S::validate($C(App\Modules\Jobs\JobPresenter::card(demo_job(['contract' => 'interim', 'summary' => null]))), $S::job()));
        assert_same('CDD', App\Modules\Jobs\JobPresenter::card(demo_job(['contract' => 'saisonnier']))['contract']);
        $h = $C(App\Modules\Housing\HousingPresenter::card(demo_listing(['cover_url' => 'https://picsum.photos/x', 'surface_m2' => null])));
        assert_same([], $S::validate($h, $S::housing()));
        assert_true(str_contains($h['images'][0], '/static/defaults/housing.jpg'), 'visuel par défaut au lieu de picsum');

        $deal = ['uid' => 'c78e01b5-aa03-4a75-84ca-752453b1586d', 'title' => 'Café offert pour un croissant acheté', 'merchant' => 'Café de Jaude',
                 'place_uid' => null, 'area' => 'Jaude', 'category' => 'cafe', 'original_cents' => 350, 'reduced_cents' => 180, 'discount_label' => null,
                 'exclusive_plus' => 0, 'image_url' => null, 'description' => null, 'conditions' => null, 'expires_at' => '2026-11-30 22:59:00'];
        $d = $C(App\Modules\Deals\DealController::present($deal));
        assert_same([], $S::validate($d, $S::deal()));
        assert_same(['Restaurants', '3,50 €', '1,80 €', '-49 %', "Jusqu'au 30 novembre"], [$d['category'], $d['original'], $d['reduced'], $d['discount'], $d['expires']]);

        $svc = ['uid' => 'b2f14441-982f-4ea8-9aef-7eedba04743b', 'name' => 'Pattes & Volcans', 'category_slug' => 'animaux', 'category_name' => 'Animaux',
                'category_icon' => 'paw', 'neighborhood_name' => null, 'city' => 'Clermont-Ferrand', 'description' => null, 'summary' => 'Garde et promenade.',
                'services' => '["Promenade","Garde"]', 'hours_label' => '7j/7', 'price_label' => 'À partir de 15 €', 'latitude' => null, 'longitude' => null,
                'logo_url' => null, 'badge_label' => null, 'notice' => null, 'rating_avg' => null, 'rating_count' => 0, 'display_rating' => null,
                'display_rating_label' => null, 'status' => 'published', 'external_url' => null, 'source_id' => 1, 'source_slug' => 'myclermont',
                'source_name' => 'myClermont', 'contact_url' => null, 'phone' => null, 'email' => null, 'website' => null];
        $sv = $C(App\Modules\Services\ServicePresenter::card($svc));
        assert_same([], $S::validate($sv, $S::service()));
        assert_same(['Animaux', 'wrench.adjustable.fill', 0.0], [$sv['category'], $sv['icon'], $sv['rating']]);

        $a = $C(App\Modules\Events\SpecEventController::agendaItem(['slug' => 'balade-des-volcans-2026-11-08', 'title' => 'Balade des volcans', 'kind' => 'famille',
            'category_slug' => 'nature', 'venue_name' => 'Chaîne des Puys', 'city' => 'Orcines', 'starts_at' => '2026-11-08 08:00:00', 'ends_at' => '2026-11-08 11:00:00',
            'all_day' => 0, 'price_label' => 'Gratuit', 'image_url' => null, 'summary' => null, 'description' => 'Sortie guidée.', 'latitude' => '45.7726', 'longitude' => '2.9644']));
        assert_same([], $S::validate($a, $S::agenda()));
        assert_same(['Nature', '3 h', '2026-11-08T09:00:00+01:00'], [$a['category'], $a['duration'], $a['date']]);
    },

    'brief §12 : post, commentaire et petite annonce valident les schémas de l\'app' => function () {
        $S = App\Support\AppSchemas::class;
        $C = static fn (array $a) => App\Core\Contract::camel($a);
        $post = ['id' => 7, 'uid' => 'd045a91b-fa20-44fd-a11c-c09199c2f7ff', 'user_id' => 3, 'type' => 'poll', 'body' => 'Vide-grenier en décembre ?',
                 'media_caption' => null, 'likes_count' => 3, 'comments_count' => 1, 'created_at' => '2026-11-16 18:20:00', 'neighborhood_name' => null,
                 'author_uid' => 'u1', 'author_username' => 'u1x', 'author_display_name' => 'Camille 2', 'author_first_name' => 'Camille', 'author_status' => 'active'];
        $comment = ['uid' => '7c1e2b0a-4d55-4a8e-9f3b-2a6c1d9e8f10', 'user_id' => 4, 'body' => 'Carrément !', 'created_at' => '2026-11-16 18:41:00',
                    'author_uid' => 'u2', 'author_username' => 'leo', 'author_display_name' => 'Léo', 'author_first_name' => null, 'author_status' => 'active'];
        $related = ['media' => [], 'liked' => [], 'votes' => [7 => 21], 'comments' => [7 => [$comment]],
                    'options' => [7 => [['id' => 20, 'label' => 'Oui', 'votes_count' => 12], ['id' => 21, 'label' => 'Non', 'votes_count' => 4]]]];
        $p = $C(App\Modules\Community\PostPresenter::post($post, $related, 3));
        assert_same([], $S::validate($p, $S::post()));
        assert_same(['Camille', 'Sondage', '', ['Oui', 'Non'], [12, 4], 1, 'Léo'], [$p['author'], $p['kind'], $p['neighborhood'], $p['pollOptions'], $p['votes'], $p['myVote'], $p['comments'][0]['author']]);

        $ad = $C(App\Modules\Classifieds\ClassifiedPresenter::card(demo_classified(['category_name' => 'Maison & déco', 'item_condition' => 'fair', 'badge_label' => null])));
        assert_same([], $S::validate($ad, $S::classified()));
        assert_same('Bon état', $ad['condition']);
    },
];
