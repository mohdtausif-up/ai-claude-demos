<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class RequireAuth
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'unauthenticated', 'Sign in to continue.');
            }
            return Response::redirect('/login?next=' . rawurlencode($request->path));
        }
        return $next($request);
    }
}
