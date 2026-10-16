<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** Per-request bag: authenticated user, resolved event, route middleware, … */
    public array $attrs = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        public readonly array $cookies,
        private readonly string $rawBody = ''
    ) {
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $path = '/' . trim(preg_replace('#/+#', '/', $path) ?? '/', '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            $_COOKIE,
            (string) file_get_contents('php://input')
        );
    }

    /** Build a request for tests. */
    public static function fake(string $method, string $path, array $post = [], array $query = [], array $server = []): self
    {
        return new self($method, $path, $query, $post, [], $server + ['REMOTE_ADDR' => '127.0.0.1'], []);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->server['CONTENT_TYPE'] ?? ''), 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'fetch'
            || $this->isJson();
    }

    private function jsonBody(): array
    {
        if ($this->json === null) {
            $decoded = $this->isJson() && $this->rawBody !== '' ? json_decode($this->rawBody, true) : null;
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    public function all(): array
    {
        return $this->jsonBody() + $this->post + $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    public function bearer(): ?string
    {
        $h = $this->header('Authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        return (($this->server['HTTPS'] ?? '') !== '' && $this->server['HTTPS'] !== 'off')
            || str_starts_with((string) env('APP_URL'), 'https://');
    }

    public function user(): ?array
    {
        return $this->attrs['user'] ?? null;
    }
}
