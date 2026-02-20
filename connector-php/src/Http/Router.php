<?php

declare(strict_types=1);

namespace SuiteSidecar\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        if (!in_array($method, ['GET', 'POST'], true)) {
            Response::error('method_not_allowed', 'Only GET and POST are supported', 405);
            return;
        }

        if (!isset($this->routes[$method][$path])) {
            Response::error('not_found', 'Route not found', 404);
            return;
        }

        call_user_func($this->routes[$method][$path]);
    }
}
