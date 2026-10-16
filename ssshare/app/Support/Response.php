<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    /** @var null|callable */
    private $streamer = null;

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** Streams a stored object; content is never buffered in PHP memory. */
    public static function stream(callable $streamer, string $mime, int $length, array $headers = []): self
    {
        $response = new self('', 200, ['Content-Type' => $mime, 'Content-Length' => (string) $length] + $headers);
        $response->streamer = $streamer;
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}
