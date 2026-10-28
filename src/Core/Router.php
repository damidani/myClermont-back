<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;

/**
 * Routeur rapide :
 *  - routes statiques  => recherche en table de hachage, O(1)
 *  - routes dynamiques => une regex par route, testées seulement si aucune route statique ne correspond
 *
 * Paramètres : /v1/items/{id} ou avec contrainte /v1/items/{id:\d+}
 */
final class Router
{
    /** @var array<string, array<string, array{handler: array, middleware: string[]}>> */
    private array $static = [];

    /** @var array<string, list<array{regex: string, handler: array, middleware: string[]}>> */
    private array $dynamic = [];

    private string $prefix = '';

    /** @var string[] */
    private array $middleware = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param array{prefix?: string, middleware?: string[]} $options
     */
    public function group(array $options, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        $this->prefix .= $options['prefix'] ?? '';
        $this->middleware = [...$this->middleware, ...($options['middleware'] ?? [])];

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;
    }

    public function add(string $method, string $path, array $handler, array $middleware = []): void
    {
        $path = rtrim($this->prefix . $path, '/') ?: '/';
        $route = ['handler' => $handler, 'middleware' => [...$this->middleware, ...$middleware]];

        if (!str_contains($path, '{')) {
            $this->static[$method][$path] = $route;
            return;
        }

        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}#',
            static fn (array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $path,
        );
        $this->dynamic[$method][] = $route + ['regex' => '#^' . $regex . '$#'];
    }

    /**
     * @return array{handler: array, middleware: string[], params: array<string, string>}
     */
    public function match(string $method, string $path): array
    {
        if (isset($this->static[$method][$path])) {
            return $this->static[$method][$path] + ['params' => []];
        }

        foreach ($this->dynamic[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['handler' => $route['handler'], 'middleware' => $route['middleware'], 'params' => $params];
            }
        }

        // La route existe-t-elle pour une autre méthode ? => 405 plutôt que 404
        foreach ($this->static as $m => $routes) {
            if ($m !== $method && isset($routes[$path])) {
                throw new HttpException(405, 'method_not_allowed', 'Méthode non autorisée.');
            }
        }
        foreach ($this->dynamic as $m => $routes) {
            if ($m === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path)) {
                    throw new HttpException(405, 'method_not_allowed', 'Méthode non autorisée.');
                }
            }
        }

        throw new HttpException(404, 'not_found', 'Ressource introuvable.');
    }
}
