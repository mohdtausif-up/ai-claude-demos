<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\Request;
use App\Support\Response;

final class RequireGuest
{
    public function handle(Request $request, callable $next): Response
    {
        return Auth::check() ? Response::redirect('/dashboard') : $next($request);
    }
}
