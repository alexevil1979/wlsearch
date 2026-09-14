<?php

declare(strict_types=1);

namespace Wlsearch\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->map('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->map('POST', $path, $handler);
    }

    public function map(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method)][$this->normalize($path)] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->normalize($path);
        $method = strtoupper($method);

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler === null) {
            // Simple param match: /api/v1/agent/tasks/{id}/result
            $handler = $this->matchParamRoute($method, $path);
        }

        if ($handler === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            return;
        }

        $handler();
    }

    private function matchParamRoute(string $method, string $path): ?callable
    {
        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            if (!str_contains($route, '{')) {
                continue;
            }
            $pattern = preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $route);
            $pattern = '#^' . $pattern . '$#';
            if (preg_match($pattern, $path, $m)) {
                return static function () use ($handler, $m): void {
                    array_shift($m);
                    $handler(...$m);
                };
            }
        }
        return null;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
