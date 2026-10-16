<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;

/** Platform administrators only. Pair with 'auth' (route order: auth first). Everyone else sees a 404. */
final class RequireAdmin
{
    public function handle(Request $req, callable $next): Response
    {
        $user = $req->user();
        if (!$user || (int) $user['is_platform_admin'] !== 1) {
            throw new NotFoundException();
        }
        return $next($req);
    }
}
