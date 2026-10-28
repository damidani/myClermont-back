<?php

declare(strict_types=1);

use App\Security\Signature;

/*
 * Vecteur de test de référence : les implémentations iOS et Android doivent
 * produire exactement la même chaîne et la même signature (voir docs/INTEGRATION_MOBILE.md).
 */
const VECTOR = [
    'secret'    => 'test-secret',
    'method'    => 'post',
    'path'      => '/v1/auth/login',
    'query'     => 'b=2&a=hello%20world&a=1',
    'timestamp' => '1700000000',
    'nonce'     => 'abcdef0123456789',
    'device'    => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
    'body'      => '{"email":"a@b.fr","password":"secret123"}',
];

return [
    'la query est triée et encodée RFC 3986' => function () {
        assert_same('a=1&a=hello%20world&b=2', Signature::canonicalQuery('b=2&a=hello%20world&a=1'));
        assert_same('a=x%2By&q=caf%C3%A9', Signature::canonicalQuery('q=caf%C3%A9&a=x%2By'));
        assert_same('q=a%20b', Signature::canonicalQuery('q=a+b'), '+ est un espace');
        assert_same('', Signature::canonicalQuery(''));
        assert_same('flag=', Signature::canonicalQuery('flag'));
        assert_same('n=10&n=9', Signature::canonicalQuery('n=9&n=10'), 'tri octet par octet, pas numérique');
    },

    'chaîne à signer conforme au protocole' => function () {
        $v = VECTOR;
        $expected = "POST\n/v1/auth/login\na=1&a=hello%20world&b=2\n1700000000\nabcdef0123456789\n"
            . "3f2504e0-4f89-41d3-9a0c-0305e82c3301\n" . hash('sha256', $v['body']);
        assert_same($expected, Signature::stringToSign($v['method'], $v['path'], $v['query'], $v['timestamp'], $v['nonce'], $v['device'], $v['body']));
    },

    'vecteur de référence (doit être identique sur iOS / Android)' => function () {
        $v = VECTOR;
        $toSign = Signature::stringToSign($v['method'], $v['path'], $v['query'], $v['timestamp'], $v['nonce'], $v['device'], $v['body']);
        assert_same('92f9f6b9bfa2c96ac205992da06013833125905d831c28ad58add58200e14c04', Signature::sign($v['secret'], $toSign));
    },

    'corps vide = SHA-256 de la chaîne vide' => function () {
        $s = Signature::stringToSign('GET', '/v1/users/me', '', '1', 'n', 'd', '');
        assert_true(str_ends_with($s, 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'));
    },

    'signature vérifiée / altération détectée' => function () {
        $v = VECTOR;
        $toSign = Signature::stringToSign($v['method'], $v['path'], $v['query'], $v['timestamp'], $v['nonce'], $v['device'], $v['body']);
        $sig = Signature::sign($v['secret'], $toSign);

        assert_true(Signature::verify($v['secret'], $toSign, $sig));
        assert_true(Signature::verify($v['secret'], $toSign, strtoupper($sig)), 'hex insensible à la casse');
        assert_true(!Signature::verify('autre', $toSign, $sig), 'mauvaise clé');

        $tampered = Signature::stringToSign($v['method'], $v['path'], $v['query'], $v['timestamp'], $v['nonce'], $v['device'], '{"email":"x@b.fr"}');
        assert_true(!Signature::verify($v['secret'], $tampered, $sig), 'corps modifié');
    },
];
