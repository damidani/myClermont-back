<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Core\Events;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;
use App\Modules\Gamification\BadgeRules;
use App\Security\Jwt;
use App\Support\Validator;

return [
    'badges : seuils atteints, déjà obtenus ignorés' => function () {
        $catalog = (array) Config::get('gamification.badges');

        assert_same(['welcome'], BadgeRules::unlockable($catalog, ['account.registered' => 1], []));
        assert_same([], BadgeRules::unlockable($catalog, ['account.registered' => 1], ['welcome']));
        assert_same([], BadgeRules::unlockable($catalog, ['favorites.total' => 9], []), '9 favoris < 10');
        assert_same(['curious', 'volcano'], BadgeRules::unlockable($catalog, ['favorites.total' => 10, 'favorites.tag.volcan' => 5], []));
        assert_same(['ambassador'], BadgeRules::unlockable($catalog, ['referrals' => 3], []));
    },

    'les 6 badges demandés existent' => function () {
        $names = array_column((array) Config::get('gamification.badges'), 'name');
        foreach (['Bienvenue à Clermont', 'Curieux', 'Explorateur', 'Ambassadeur', 'Passion volcan', 'Gourmand'] as $name) {
            assert_true(in_array($name, $names, true), "badge manquant : {$name}");
        }
    },

    'multiplicateur premium' => function () {
        assert_same(15, BadgeRules::applyMultiplier(10, true, 1.5));
        assert_same(10, BadgeRules::applyMultiplier(10, false, 1.5));
        assert_same(0, BadgeRules::applyMultiplier(0, true, 1.5));
    },

    'validation du pseudo' => function () {
        foreach (['lea', 'Jean_Pierre.63', 'abc'] as $ok) {
            Validator::validate(['u' => $ok], ['u' => 'required|username']);
        }
        foreach (['ab', '.lea', 'lea.', 'léa', 'a b', str_repeat('x', 31)] as $ko) {
            try {
                Validator::validate(['u' => $ko], ['u' => 'required|username']);
                throw new RuntimeException("pseudo accepté à tort : {$ko}");
            } catch (ValidationException) {
            }
        }
    },

    'événements : un écouteur en échec ne casse pas l\'action' => function () {
        App::bootModules();
        $called = false;
        Events::listen('test.event', fn () => throw new RuntimeException('boom'));
        Events::listen('test.event', function () use (&$called) { $called = true; });
        Events::dispatch('test.event');
        assert_true($called, 'le second écouteur doit être appelé');
        App::bootModules();
    },

    'routes des modules montées et protégées' => function () {
        seed_device();
        foreach (['GET /v1/badges', 'GET /v1/users/me/points', 'GET /v1/users/me/favorites', 'GET /v1/users/me/premium'] as $route) {
            [$method, $path] = explode(' ', $route);
            $res = app()->handle(signed_request($method, $path));
            assert_same('unauthenticated', err($res) ?? null, $route);
        }
    },

    'favoris : type inconnu => 422 avec la liste des types' => function () {
        seed_device();
        FavoriteTypes::register('place', new class implements FavoriteProvider {
            public function find(array $ids): array { return []; }
        });
        $jwt = Jwt::encode(['iss' => 'test', 'sub' => '1', 'did' => DEVICE_UID, 'typ' => 'access', 'exp' => time() + 60]);
        $res = app()->handle(signed_request('PUT', '/v1/users/me/favorites/unicorn/42', '', ['headers' => ['authorization' => 'Bearer ' . $jwt]]));
        assert_same(400, $res->status);
        assert_true(str_contains($res->payload['error']['details']['fields']['type'], 'place'));
        FavoriteTypes::reset();
    },
];
