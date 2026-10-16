<?php

declare(strict_types=1);

namespace App\Support;

final class Router
{
    /** @var array<int, array{method:string, regex:string, params:array, handler:array, middleware:array}> */
    private array $routes = [];
    private array $groupMiddleware = [];

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function patch(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    public function group(array $middleware, callable $routes): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = [...$previous, ...$middleware];
        $routes($this);
        $this->groupMiddleware = $previous;
    }

    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function ($m) use (&$params) {
            $params[] = $m[1];
            return '(' . ($m[2] ?? '[A-Za-z0-9_-]+') . ')';
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    /** @return array{handler:array, params:array, middleware:array} */
    public function match(Request $request): array
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $method = $request->method === 'HEAD' ? 'GET' : $request->method;
            if ($route['method'] !== $method) {
                $methodMismatch = true;
                continue;
            }
            return [
                'handler' => $route['handler'],
                'params' => array_combine($route['params'], array_slice($m, 1)) ?: [],
                'middleware' => $route['middleware'],
            ];
        }
        if ($methodMismatch) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed.');
        }
        throw HttpException::notFound('The page you were looking for does not exist.');
    }
}
