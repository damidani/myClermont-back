<?php

declare(strict_types=1);

use App\Cache\Cache;
use App\Core\App;
use App\Core\Request;
use App\Security\Crypto;
use App\Security\Jwt;
use App\Security\Signature;

/*
 * Tests de bout en bout du pipeline HTTP (routeur + middlewares), sans MySQL :
 * l'appareil est injecté directement dans le cache mémoire.
 */

return [
    'health est public' => function () {
        $res = app()->handle(new Request('GET', '/v1/health', '', [], '', '127.0.0.1'));
        assert_same(200, $res->status);
        assert_same('ok', $res->payload['data']['status']);
    },

    'route inconnue => 404 JSON' => function () {
        $res = app()->handle(new Request('GET', '/v1/nope', '', [], '', '127.0.0.1'));
        assert_same(404, $res->status);
        assert_same('not_found', $res->payload['error']['code']);
    },

    'appel direct sans signature => 401' => function () {
        $res = app()->handle(new Request('GET', '/v1/devices/me', '', [], '', '127.0.0.1'));
        assert_same(401, $res->status);
    },

    'requête signée valide => 200' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/devices/me'));
        assert_same(200, $res->status, json_encode($res->payload));
        assert_same(DEVICE_UID, $res->payload['data']['id']);
        assert_true(isset($res->headers['X-RateLimit-Remaining']));
    },

    'rejeu du même nonce => 401 replayed_request' => function () {
        seed_device();
        $req = signed_request('GET', '/v1/devices/me', '', ['nonce' => 'replay-nonce-0000001']);
        assert_same(200, app()->handle($req)->status);
        $res = app()->handle(signed_request('GET', '/v1/devices/me', '', ['nonce' => 'replay-nonce-0000001']));
        assert_same('replayed_request', $res->payload['error']['code']);
    },

    'timestamp hors fenêtre => 401' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/devices/me', '', ['timestamp' => time() - 3600]));
        assert_same('timestamp_out_of_window', $res->payload['error']['code']);
    },

    'mauvais secret => invalid_signature' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/devices/me', '', ['secret' => 'pirate']));
        assert_same('invalid_signature', $res->payload['error']['code']);
    },

    'corps modifié en transit => invalid_signature' => function () {
        seed_device();
        $res = app()->handle(signed_request('PATCH', '/v1/devices/me', '{"push_token":"a"}', ['sent_body' => '{"push_token":"b"}']));
        assert_same('invalid_signature', $res->payload['error']['code']);
    },

    'mauvais device token => invalid_device' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/devices/me', '', ['token' => 'faux']));
        assert_same('invalid_device', $res->payload['error']['code']);
    },

    'appareil bloqué => 403' => function () {
        seed_device('blocked');
        $res = app()->handle(signed_request('GET', '/v1/devices/me'));
        assert_same(403, $res->status);
    },

    'route auth sans Bearer => 401 unauthenticated' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/users/me'));
        assert_same('unauthenticated', $res->payload['error']['code']);
    },

    'jeton d\'un autre appareil => token_device_mismatch' => function () {
        seed_device();
        $jwt = Jwt::encode(['iss' => 'test', 'sub' => '1', 'did' => 'aaaaaaaa-0000-4000-8000-000000000000', 'typ' => 'access', 'exp' => time() + 60]);
        $res = app()->handle(signed_request('GET', '/v1/users/me', '', ['headers' => ['authorization' => 'Bearer ' . $jwt]]));
        assert_same('token_device_mismatch', $res->payload['error']['code']);
    },

    'enregistrement : clé application inconnue => 401' => function () {
        $res = app()->handle(new Request('POST', '/v1/devices/register', '', ['x-key-id' => 'nope'], '{}', '10.0.0.1'));
        assert_same('invalid_app_key', $res->payload['error']['code']);
    },
];
