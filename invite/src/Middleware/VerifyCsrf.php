<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** CSRF defence for every state-changing browser request: synchroniser token plus an Origin check. */
final class VerifyCsrf
{
    public function handle(Request $req, callable $next): Response
    {
        if (in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($req);
        }
        $mw = $req->attrs['route_mw'] ?? [];
        if (array_intersect(['api', 'webhook', 'public_api'], $mw)) {
            return $next($req);
        }
        $origin = $req->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            $host = parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
            if (strcasecmp((string) $host, (string) ($req->server['HTTP_HOST'] ?? '')) !== 0) {
                throw new HttpException(419);
            }
        }
        $sent = (string) ($req->post['_csrf'] ?? $req->header('X-CSRF-Token') ?? '');
        if ($sent === '' || !hash_equals(Session::csrfToken(), $sent)) {
            throw new HttpException(419);
        }
        return $next($req);
    }
}
