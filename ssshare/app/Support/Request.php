<?php

declare(strict_types=1);

namespace App\Support;

final class Request
{
    public array $attributes = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $headers,
        public readonly string $body = '',
    ) {
    }

    public static function capture(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        $contentType = $headers['content-type'] ?? '';
        $body = str_contains($contentType, 'multipart/form-data') ? '' : (string) file_get_contents('php://input');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $headers,
            $body,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** Value from JSON body, form body, then query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            if (str_contains($this->header('content-type', ''), 'application/json') && $this->body !== '') {
                $decoded = json_decode($this->body, true);
                $this->json = is_array($decoded) ? $decoded : [];
            }
        }
        return $this->json;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return null;
        }
        return $file;
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept', ''), 'application/json')
            || $this->header('x-requested-with') === 'fetch'
            || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on' || $this->header('x-forwarded-proto') === 'https';
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent', ''), 0, 512);
    }
}
