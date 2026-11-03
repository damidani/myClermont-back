<?php

declare(strict_types=1);

namespace App\Docs;

use App\Cache\Cache;
use App\Core\Request;
use App\Core\Response;

/**
 * Documentation de l'API (65abb5dc) :
 *   /docs                      Swagger UI (lecture seule : « Try it out » désactivé, la production refuse les requêtes non signées)
 *   /api/v1/openapi.json       spec publique : routes de l'app
 *   /admin/openapi.json        spec complète (espace commerçant, administration, agents) : session du back-office
 */
final class DocsPage
{
    public static function spec(bool $full): string
    {
        $key = 'openapi:' . ($full ? 'full' : 'public') . ':' . OpenApi::VERSION;
        $json = Cache::store()->get($key);
        if (!is_string($json)) {
            $json = (string) json_encode((new OpenApi())->build($full), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            Cache::store()->set($key, $json, 600);
        }

        return $json;
    }

    public function publicSpec(Request $request): Response
    {
        return Response::raw(self::spec(false), 'application/json; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=600');
    }

    public static function run(): void
    {
        $ui = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14';
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https:; frame-ancestors 'none'");
        echo <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>API My Clermont</title><link rel="stylesheet" href="{$ui}/swagger-ui.css"></head>
<body><div id="ui"></div>
<script src="{$ui}/swagger-ui-bundle.js"></script><script src="{$ui}/swagger-ui-standalone-preset.js"></script>
<script>
SwaggerUIBundle({ dom_id: '#ui', supportedSubmitMethods: [], deepLinking: true, presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset], layout: 'StandaloneLayout',
  urls: [{ url: '/api/v1/openapi.json', name: "App (public)" }, { url: '/admin/openapi.json', name: 'Complète (connexion au back-office sur /admin)' }] });
</script></body></html>
HTML;
    }
}
