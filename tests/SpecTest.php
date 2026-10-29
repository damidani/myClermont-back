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
];
