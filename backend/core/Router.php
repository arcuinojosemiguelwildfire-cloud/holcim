<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tiny method + path router.
 *
 * Paths support named parameters: "/events/{id}". Parameters match a single
 * path segment; numeric constraints are enforced in controllers/validators.
 *
 * Each route may declare middleware: callables that receive the Request and
 * either return nothing (continue) or throw an HttpException (stop).
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, middleware: list<callable>}> */
    private array $routes = [];

    /** @param list<callable> $middleware */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $request = $request->withRouteParams(array_map('strval', $params));

            foreach ($route['middleware'] as $middleware) {
                $middleware($request);
            }

            $response = ($route['handler'])($request);
            if (!$response instanceof Response) {
                throw new \LogicException("Route {$route['method']} {$route['pattern']} did not return a Response.");
            }

            return $response;
        }

        throw $pathMatched ? HttpException::methodNotAllowed() : HttpException::notFound('Endpoint not found.');
    }

    /** @param list<callable> $middleware */
    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/');

        $this->routes[] = [
            'method' => $method,
            'pattern' => $path,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }
}
