<?php

declare(strict_types=1);

use App\Core\Router;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;
use App\Security\Jwt;
use App\Support\Validator;

return [
    'routeur : statique, dynamique, 404, 405' => function () {
        $r = new Router();
        $r->group(['prefix' => '/v1', 'middleware' => ['device']], function (Router $r) {
            $r->get('/items', ['C', 'index'], ['auth']);
            $r->get('/items/{id:\d+}', ['C', 'show']);
        });

        $m = $r->match('GET', '/v1/items');
        assert_same(['device', 'auth'], $m['middleware']);

        $m = $r->match('GET', '/v1/items/42');
        assert_same(['id' => '42'], $m['params']);

        assert_throws(fn () => $r->match('GET', '/v1/items/abc'), 'not_found');
        assert_throws(fn () => $r->match('DELETE', '/v1/items/42'), 'method_not_allowed');
    },

    'validateur' => function () {
        $data = Validator::validate(
            ['email' => '  a@b.fr ', 'name' => 'Léa', 'extra' => 'ignoré'],
            ['email' => 'required|email', 'name' => 'required|string|min:2|max:10', 'opt' => 'nullable|string'],
        );
        assert_same(['email' => 'a@b.fr', 'name' => 'Léa'], $data);

        try {
            Validator::validate(['email' => 'pas-un-email'], ['email' => 'required|email', 'password' => 'required']);
            throw new RuntimeException('ValidationException attendue');
        } catch (ValidationException $e) {
            assert_same(['email', 'password'], array_keys($e->details));
        }
    },

    'chiffrement des secrets appareils' => function () {
        $cipher = Crypto::encrypt('mon-secret');
        assert_true($cipher !== 'mon-secret');
        assert_same('mon-secret', Crypto::decrypt($cipher));
        assert_true(Crypto::encrypt('x') !== Crypto::encrypt('x'), 'nonce aléatoire');
    },

    'uuid v4' => function () {
        assert_true((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Crypto::uuid4()));
    },

    'JWT : valide, falsifié, expiré' => function () {
        $token = Jwt::encode(['iss' => 'test', 'sub' => '1', 'exp' => time() + 60]);
        assert_same('1', Jwt::decode($token)['sub']);

        [$h, $p, $s] = explode('.', $token);
        $forged = $h . '.' . rtrim(strtr(base64_encode('{"iss":"test","sub":"2","exp":9999999999}'), '+/', '-_'), '=') . '.' . $s;
        assert_throws(fn () => Jwt::decode($forged), 'invalid_token');

        assert_throws(fn () => Jwt::decode(Jwt::encode(['iss' => 'test', 'sub' => '1', 'exp' => time() - 1])), 'token_expired');
        assert_throws(fn () => Jwt::decode(Jwt::encode(['iss' => 'autre', 'sub' => '1', 'exp' => time() + 60])), 'invalid_token');
    },
];
