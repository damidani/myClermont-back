<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use App\Http\Middleware\Middleware;

final class App
{
    /**
     * @param list<Module> $modules
     */
    private function __construct(private readonly string $root, private readonly array $modules)
    {
    }

    public static function boot(string $root): self
    {
        Config::load($root);
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        return new self($root, self::bootModules());
    }

    /** @return list<Module> */
    public static function bootModules(): array
    {
        Events::reset();
        $modules = [];
        foreach ((array) Config::get('modules', []) as $class) {
            $module = new $class();
            foreach ($module->listeners() as $event => $listeners) {
                foreach ($listeners as $listener) {
                    Events::listen($event, $listener);
                }
            }
            $module->boot();
            $modules[] = $module;
        }

        return $modules;
    }

    /** Point d'entrée HTTP : lit la requête depuis les superglobales et envoie la réponse. */
    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
        } catch (\Throwable $e) {
            $this->finalize($this->renderException($e))->send();
            return;
        }

        $this->handle($request)->send();
    }

    public function handle(Request $request): Response
    {
        Context::reset();
        try {
            $router = new Router();
            (require $this->root . '/routes/api.php')($router, $this->modules);

            $route = $router->match($request->method, $request->path);
            $request->params = $route['params'];

            $response = $this->pipeline($route['middleware'], $route['handler'])($request);
        } catch (\Throwable $e) {
            $response = $this->renderException($e);
        }

        return $this->finalize($response);
    }

    /**
     * Construit la chaîne middleware1 -> middleware2 -> ... -> contrôleur.
     *
     * @param string[] $middleware alias, ex. "device" ou "throttle:10,60,ip"
     */
    private function pipeline(array $middleware, array $handler): \Closure
    {
        [$class, $method] = $handler;
        $next = static fn (Request $request): Response => (new $class())->$method($request);

        $aliases = (array) Config::get('app.middleware', []);
        foreach (array_reverse($middleware) as $definition) {
            [$alias, $args] = array_pad(explode(':', $definition, 2), 2, null);
            $mwClass = $aliases[$alias] ?? throw new \LogicException("Middleware inconnu : {$alias}");

            /** @var Middleware $instance */
            $instance = new $mwClass(...($args !== null ? explode(',', $args) : []));
            $next = static fn (Request $request): Response => $instance->handle($request, $next);
        }

        return $next;
    }

    private function renderException(\Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            $response = Response::error($e->errorCode, $e->getMessage(), $e->getCode(), $e->details);
            foreach ($e->headers as $name => $value) {
                $response->withHeader($name, $value);
            }
            return $response;
        }

        Logger::error($e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);

        return Config::get('app.debug')
            ? Response::error('server_error', $e->getMessage(), 500, ['exception' => $e::class, 'file' => $e->getFile() . ':' . $e->getLine()])
            : Response::error('server_error', 'Erreur interne du serveur.', 500);
    }

    private function finalize(Response $response): Response
    {
        if (($response->payload['success'] ?? false) === true && Context::meta() !== []) {
            $response->payload['meta'] = array_merge($response->payload['meta'] ?? [], Context::meta());
        }

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Cache-Control', $response->headers['Cache-Control'] ?? 'no-store')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('X-Server-Time', (string) time());
    }
}
