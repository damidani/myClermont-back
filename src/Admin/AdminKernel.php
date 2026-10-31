<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Config;
use App\Core\Logger;

/**
 * Point d'entrée du back-office (/admin), séparé de l'API mobile :
 * pas de signature d'appareil ici, mais une session navigateur + CSRF + rôles.
 */
final class AdminKernel
{
    /** @return array{0: int, 1: array<string, string>, 2: string} statut, en-têtes, corps */
    public function handle(AdminRequest $req): array
    {
        try {
            [$status, $headers, $body] = $this->dispatch($req);
        } catch (\Throwable $e) {
            Logger::error('Back-office : ' . $e->getMessage(), ['exception' => $e::class, 'file' => $e->getFile() . ':' . $e->getLine()]);
            $message = Config::get('app.debug') ? $e->getMessage() : 'Une erreur est survenue. Elle a été enregistrée dans les journaux.';
            [$status, $headers, $body] = [500, [], AdminController::page('Erreur', '<div class="card"><h1>Erreur</h1><p>' . Html::e($message) . '</p></div>', $req)];
        }

        return [$status, $headers + [
            'Content-Type'            => 'text/html; charset=utf-8',
            'Cache-Control'           => 'no-store',
            'X-Frame-Options'         => 'DENY',
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'same-origin',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            // Aucun script ni style en ligne : une injection HTML ne peut rien exécuter
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; "
                                       . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            'X-Robots-Tag'            => 'noindex, nofollow',
        ], $body];
    }

    public function run(): void
    {
        [$status, $headers, $body] = $this->handle(AdminRequest::fromGlobals());
        http_response_code($status);
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $body;
    }

    private function dispatch(AdminRequest $req): array
    {
        if (!Config::get('admin.enabled', true)) {
            return [404, [], 'Not found'];
        }
        $allowed = (array) Config::get('admin.allowed_ips', []);
        if ($allowed !== [] && !in_array($req->ip, $allowed, true)) {
            Logger::security('Back-office : IP refusée', ['ip' => $req->ip]);
            return [403, [], 'Accès refusé.'];
        }
        // Une requête POST venant d'un autre site est refusée (en plus du jeton CSRF)
        if ($req->method === 'POST' && $req->origin !== null && parse_url($req->origin, PHP_URL_HOST) !== parse_url('//' . $req->host, PHP_URL_HOST)) {
            return [403, [], 'Origine refusée.'];
        }

        $c = new AdminController($req);
        $path = substr($req->path, strlen('/admin')) ?: '/';
        $m = $req->method;

        // Pages publiques
        if ($path === '/login') {
            return $m === 'POST' ? $c->login() : $c->loginForm();
        }
        if ($path === '/login/2fa' && $m === 'POST') {
            return $c->loginTotp();
        }

        // Tout le reste exige une session
        $found = AdminAuth::session($req->cookies[AdminAuth::COOKIE] ?? null);
        if ($found === null) {
            return AdminController::redirect('/login');
        }
        [$req->session, $req->admin] = $found;
        if ($m === 'POST' && !hash_equals($req->session['csrf'], $req->input('_csrf'))) {
            return [419, [], AdminController::page('Session expirée', '<div class="card"><p>Le formulaire a expiré. Rechargez la page et recommencez.</p></div>', $req)];
        }
        // Double authentification imposée : seules les pages du compte restent accessibles tant qu'elle n'est pas activée
        if (Config::get('admin.require_2fa') && !$req->admin['has_2fa'] && !str_starts_with($path, '/account') && $path !== '/logout') {
            return AdminController::redirect('/account');
        }

        return match (true) {
            $path === '/' && $m === 'GET'                    => $c->dashboard(),
            $path === '/logout' && $m === 'POST'             => $c->logout(),
            $path === '/account' && $m === 'GET'             => $c->account(),
            $path === '/account/password' && $m === 'POST'   => $c->changePassword(),
            $path === '/account/2fa' && $m === 'POST'        => $c->twoFactor(),
            $path === '/account/sessions' && $m === 'POST'   => $c->revokeSessions(),
            $path === '/upload' && $m === 'POST'             => $c->upload(),
            $path === '/cache/clear' && $m === 'POST'        => $c->clearCache(),
            $path === '/moderation' && $m === 'GET'          => $c->moderation(),
            $path === '/moderation' && $m === 'POST'         => $c->moderate(),
            (bool) preg_match('#^/r/(\w+)(?:/(new|edit|delete|export\.csv))?$#', $path, $p) => $c->resource($p[1], $p[2] ?? 'list'),
            default => [404, [], AdminController::page('Introuvable', '<div class="card"><p>Page introuvable.</p></div>', $req)],
        };
    }
}
