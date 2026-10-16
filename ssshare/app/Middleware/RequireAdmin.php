<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class RequireAdmin
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login?next=' . rawurlencode($request->path));
        }
        if (!Auth::isAdmin()) {
            // Do not reveal that the admin portal exists.
            throw HttpException::notFound('The page you were looking for does not exist.');
        }
        return $next($request);
    }
}
