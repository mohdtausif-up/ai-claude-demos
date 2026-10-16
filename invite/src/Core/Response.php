<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** When set, called by send() after the headers to write the body incrementally (Server-Sent Events). */
    public $stream = null;

    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = []
    ) {
    }

    public static function stream(callable $fn, string $type = 'text/event-stream'): self
    {
        $r = new self(200, '', ['Content-Type' => $type . '; charset=utf-8', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
        $r->stream = $fn;
        return $r;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_out($data), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function text(string $body, string $type = 'text/plain', int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => $type . '; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        // Only same-site relative redirects; anything else is collapsed to the home page (open-redirect guard).
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, "\n") || str_contains($to, '\\')) {
            $to = '/';
        }
        return new self($status, '', ['Location' => $to]);
    }

    public static function download(string $body, string $filename, string $type): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self(200, $body, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v, true);
        }
        if ($this->stream !== null) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
