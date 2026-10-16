<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message !== '' ? $message : self::reason($status), $status);
    }

    public static function reason(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Authentication required',
            403 => 'You do not have permission to do that',
            404 => 'Not found',
            405 => 'Method not allowed',
            419 => 'Your session expired. Please go back, refresh and try again',
            422 => 'The submitted data was invalid',
            429 => 'Too many requests. Please slow down',
            default => 'Something went wrong',
        };
    }
}

final class NotFoundException extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(404, $message);
    }
}

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(403, $message);
    }
}

final class ValidationException extends HttpException
{
    /** Where a browser should be sent back to show the errors (defaults to the Referer). */
    public ?string $redirect = null;

    /** @param array<string,list<string>> $errors */
    public function __construct(public readonly array $errors, public readonly array $old = [], string $message = '')
    {
        parent::__construct(422, $message !== '' ? $message : 'Please correct the highlighted fields.');
    }

    public function redirectTo(string $to): static
    {
        $this->redirect = $to;
        return $this;
    }
}

final class TooManyRequestsException extends HttpException
{
    public function __construct(int $retryAfter = 60)
    {
        parent::__construct(429, '', ['Retry-After' => (string) $retryAfter]);
    }
}
