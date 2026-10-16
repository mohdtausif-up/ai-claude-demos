<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array,mw:list<string>}> */
    private array $routes = [];
    private string $prefix = '';
    /** @var list<string> */
    private array $groupMw = [];

    public function group(string $prefix, array $mw, callable $fn): void
    {
        $prevPrefix = $this->prefix;
        $prevMw = $this->groupMw;
        $this->prefix .= $prefix;
        $this->groupMw = [...$this->groupMw, ...$mw];
        $fn($this);
        $this->prefix = $prevPrefix;
        $this->groupMw = $prevMw;
    }

    public function get(string $path, array $handler, array $mw = []): void
    {
        $this->add('GET', $path, $handler, $mw);
    }

    public function post(string $path, array $handler, array $mw = []): void
    {
        $this->add('POST', $path, $handler, $mw);
    }

    public function add(string $method, string $path, array $handler, array $mw = []): void
    {
        $full = rtrim($this->prefix . $path, '/') ?: '/';
        $regex = preg_replace_callback(
            // {name} or {name:regex}; the regex may contain one level of {n,m} quantifier braces.
            '/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/',
            static fn ($m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $full
        );
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'mw' => [...$this->groupMw, ...$mw],
        ];
    }

    /**
     * @return array{handler:array,params:array<string,string>,mw:list<string>}
     * @throws HttpException 404 / 405
     */
    public function match(string $method, string $path): array
    {
        $methodMismatch = false;
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $r['handler'], 'params' => $params, 'mw' => $r['mw']];
        }
        throw $methodMismatch ? new HttpException(405) : new NotFoundException();
    }
}
