<?php

declare(strict_types=1);

use App\Admin\AdminKernel;
use App\Admin\AdminRequest;
use App\Admin\Crud;
use App\Admin\Html;
use App\Admin\Resource;
use App\Admin\Schema;
use App\Admin\Totp;
use App\Exceptions\ValidationException;

/** Schéma minimal injecté (comme lu dans INFORMATION_SCHEMA). */
function admin_schema(): void
{
    $col = static fn (string $type, array $o = []) => $o + ['type' => $type, 'unsigned' => false, 'nullable' => false, 'default' => null,
        'length' => null, 'auto' => false, 'enum' => null, 'bool' => false, 'comment' => ''];
    Schema::set([
        'places' => [
            'columns' => [
                'id' => $col('int', ['auto' => true, 'unsigned' => true]),
                'uid' => $col('char', ['length' => 36]),
                'name' => $col('varchar', ['length' => 10]),
                'category_id' => $col('smallint', ['unsigned' => true]),
                'price_cents' => $col('int', ['nullable' => true]),
                'status' => $col('enum', ['enum' => ['draft', 'published'], 'default' => 'draft']),
                'is_partner' => $col('tinyint', ['bool' => true, 'default' => '0']),
                'charges_included' => $col('tinyint', ['bool' => true, 'nullable' => true]),
                'opening_hours' => $col('json', ['nullable' => true]),
                'starts_at' => $col('datetime', ['nullable' => true]),
                'cover_url' => $col('varchar', ['nullable' => true, 'length' => 500]),
                'content_hash' => $col('char', ['nullable' => true]),
                'created_at' => $col('datetime'),
            ],
            'primary' => ['id'],
            'foreign' => ['category_id' => ['table' => 'place_categories', 'column' => 'id']],
        ],
        'user_badges' => ['columns' => ['user_id' => $col('bigint'), 'badge_code' => $col('varchar', ['length' => 40])], 'primary' => ['user_id', 'badge_code'], 'foreign' => []],
    ]);
}

function admin_request(string $method, string $path, array $post = [], array $cookies = [], ?string $origin = null): AdminRequest
{
    return new AdminRequest($method, $path, [], $post, $cookies, [], '127.0.0.1', 'test', $origin, 'admin.test');
}

return [
    'TOTP : vecteur RFC 6238, tolérance ±30 s, code à usage unique' => function () {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
        assert_same('287082', Totp::code($secret, 1)); // T = 59 s
        assert_same(1, Totp::verify($secret, '287 082', null, 59));
        assert_same(1, Totp::verify($secret, '287082', null, 89), 'code précédent encore accepté');
        assert_same(null, Totp::verify($secret, '287082', 1, 59), 'déjà utilisé');
        assert_same(null, Totp::verify($secret, '000000', null, 59));
        assert_same(32, strlen(Totp::generateSecret()));
    },

    'ressource : types de champs déduits du schéma, secrets cachés' => function () {
        admin_schema();
        $r = Resource::get('places');
        $kinds = array_map(static fn ($f) => $f['kind'], $r->fields);
        assert_same(['id' => 'integer', 'uid' => 'text', 'name' => 'text', 'category_id' => 'relation', 'price_cents' => 'money', 'status' => 'select',
            'is_partner' => 'bool', 'charges_included' => 'bool', 'opening_hours' => 'json', 'starts_at' => 'datetime', 'cover_url' => 'url', 'created_at' => 'datetime'], $kinds);
        assert_true($r->fields['id']['readonly'] && $r->fields['uid']['readonly'] && $r->fields['created_at']['readonly']);
        assert_true(!$r->allows('moderator') && $r->allows('editor') && $r->allows('admin'));
    },

    'saisie : conversions (€, heure de Paris → UTC, JSON) et erreurs' => function () {
        admin_schema();
        $crud = new Crud(Resource::get('places'));
        $data = $crud->parse(['name' => ' Jaude ', 'category_id' => '3', 'price_cents' => '12,50', 'status' => 'published', 'is_partner' => '1',
            'charges_included' => '', 'opening_hours' => '{ "always_open" : true }', 'starts_at' => '2026-07-14T22:30', 'cover_url' => ''], null);
        assert_same(['name' => 'Jaude', 'category_id' => 3, 'price_cents' => 1250, 'status' => 'published', 'is_partner' => 1, 'charges_included' => null,
            'opening_hours' => '{"always_open":true}', 'starts_at' => '2026-07-14 20:30:00', 'cover_url' => null], $data);

        try {
            $crud->parse(['name' => 'Beaucoup trop long', 'category_id' => 'x', 'status' => 'hacked', 'opening_hours' => '{', 'cover_url' => 'javascript:alert(1)'], null);
            throw new RuntimeException('aurait dû échouer');
        } catch (ValidationException $e) {
            assert_same(['name', 'category_id', 'status', 'opening_hours', 'cover_url'], array_keys($e->details));
        }
    },

    'clé composée encodée dans l\'URL et relue' => function () {
        admin_schema();
        $r = Resource::get('user_badges');
        $key = $r->keyOf(['user_id' => 4, 'badge_code' => 'welcome']);
        assert_same(['user_id' => '4', 'badge_code' => 'welcome'], $r->parseKey($key));
        assert_same(null, $r->parseKey('nimportequoi'));
    },

    'HTML : tout est échappé' => function () {
        admin_schema();
        $f = Resource::get('places')->fields['name'];
        assert_true(!str_contains(Html::field($f, '"><script>alert(1)</script>', null, null), '<script>'));
        assert_same('&lt;b&gt; &amp; &quot;', Html::e('<b> & "'));
    },

    'back-office : redirection vers la connexion, en-têtes de sécurité, CSRF' => function () {
        [$status, $headers] = (new AdminKernel())->handle(admin_request('GET', '/admin/r/users'));
        assert_same(303, $status);
        assert_same('/admin/login', $headers['Location']);
        assert_true(str_contains($headers['Content-Security-Policy'], "script-src 'self'"));
        assert_same('DENY', $headers['X-Frame-Options']);

        [$status, , $body] = (new AdminKernel())->handle(admin_request('GET', '/admin/login'));
        assert_same(200, $status);
        assert_true(str_contains($body, 'name="_csrf"'));

        // Formulaire de connexion sans le cookie CSRF : refusé avant toute vérification du mot de passe
        assert_same(419, (new AdminKernel())->handle(admin_request('POST', '/admin/login', ['_csrf' => 'abc', 'email' => 'a@b.fr', 'password' => 'x']))[0]);
        // POST venant d'un autre site
        assert_same(403, (new AdminKernel())->handle(admin_request('POST', '/admin/login', [], [], 'https://evil.example'))[0]);
    },
];
