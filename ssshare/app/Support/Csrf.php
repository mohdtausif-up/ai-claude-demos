<?php

declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token)) {
            $token = Ids::token(32);
            Session::put('_csrf', $token);
        }
        return $token;
    }

    public static function verify(Request $request): void
    {
        $submitted = $request->post['_csrf'] ?? $request->header('x-csrf-token') ?? '';
        $expected = Session::get('_csrf');
        if (!is_string($expected) || !is_string($submitted) || !hash_equals($expected, $submitted)) {
            throw new HttpException(419, 'csrf_mismatch', 'Your session expired. Refresh the page and try again.');
        }
    }
}
