<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** An error with an HTTP status and a stable machine-readable code. */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'You do not have access to this resource.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function validation(array $details, string $message = 'Some fields need attention.'): self
    {
        return new self(422, 'validation_failed', $message, $details);
    }

    public static function tooManyRequests(int $retryAfter, string $message = 'Too many requests. Please slow down.'): self
    {
        return new self(429, 'rate_limited', $message, [], ['Retry-After' => (string) $retryAfter]);
    }
}
