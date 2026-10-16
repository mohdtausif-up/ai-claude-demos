<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\ApiAuthenticate;
use App\Middleware\Authenticate;
use App\Middleware\RequireAdmin;
use App\Middleware\VerifyCsrf;
use Throwable;

final class Kernel
{
    private const MIDDLEWARE = [
        'auth' => Authenticate::class,
        'admin' => RequireAdmin::class,
        'api' => ApiAuthenticate::class,
    ];

    public function __construct(private Router $router)
    {
    }

    public function handle(Request $req): Response
    {
        Session::start($req->isSecure());
        try {
            $route = $this->router->match($req->method, $req->path);
            $req->attrs['route_mw'] = $route['mw'];
            $stack = [new VerifyCsrf()];
            foreach ($route['mw'] as $name) {
                if (isset(self::MIDDLEWARE[$name])) {
                    $stack[] = Container::get(self::MIDDLEWARE[$name]);
                }
            }
            $core = static function (Request $r) use ($route): Response {
                [$class, $method] = $route['handler'];
                $out = Container::get($class)->$method($r, $route['params']);
                return $out instanceof Response ? $out : Response::html((string) $out);
            };
            $pipeline = array_reduce(
                array_reverse($stack),
                static fn (callable $next, object $mw) => static fn (Request $r) => $mw->handle($r, $next),
                $core
            );
            $res = $pipeline($req);
        } catch (Throwable $e) {
            $res = $this->errorResponse($req, $e);
        }
        return $this->decorate($req, $res);
    }

    private function decorate(Request $req, Response $res): Response
    {
        $res->headers += [
            'Content-Security-Policy' => Csp::header(),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-store',
        ];
        // Standard Server-Timing header: lets operators and browser dev tools see app vs database time per request.
        $total = (microtime(true) - (float) ($req->server['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000;
        $res->headers['Server-Timing'] = sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, Db::$queryMs, Db::$queries);
        if ($req->isSecure()) {
            $res->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $res;
    }

    private function errorResponse(Request $req, Throwable $e): Response
    {
        if ($e instanceof ValidationException && !$req->wantsJson()) {
            $old = array_filter(
                $e->old ?: $req->all(),
                static fn ($k) => !preg_match('/pass|_csrf|token|secret/i', (string) $k),
                ARRAY_FILTER_USE_KEY
            );
            Session::flash('_errors', $e->errors);
            Session::flash('_old', $old);
            Session::flash('error', $e->getMessage());
            return Response::redirect($e->redirect ?? $this->backPath($req));
        }

        $status = $e instanceof HttpException ? $e->status : 500;
        if ($status !== 500 && env('APP_DEBUG') && $status !== 419 && $status !== 401) {
            error_log(sprintf('[invite:debug] %d %s %s thrown at %s:%d', $status, $req->method, $req->path, basename($e->getFile()), $e->getLine()));
        }
        if ($status === 500) {
            error_log('[invite] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
        }
        $debug = env('APP_ENV') === 'local' && $status === 500;
        $message = $status === 500 ? ($debug ? $e->getMessage() : 'Something went wrong on our side. Please try again.') : $e->getMessage();

        if ($req->wantsJson()) {
            $codes = [400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 419 => 'csrf_mismatch', 422 => 'validation_failed', 429 => 'rate_limited'];
            $body = ['error' => ['code' => $codes[$status] ?? 'server_error', 'message' => $message]];
            if ($e instanceof ValidationException) {
                $body['error']['details'] = $e->errors;
            }
            $res = Response::json($body, $status);
        } else {
            try {
                $html = View::render('errors/error', ['status' => $status, 'message' => $message], 'layouts/guest');
            } catch (Throwable) {
                $html = '<h1>' . e((string) $status) . '</h1><p>' . e($message) . '</p>';
            }
            $res = Response::html($html, $status);
        }
        foreach ($e instanceof HttpException ? $e->headers : [] as $k => $v) {
            $res->header($k, $v);
        }
        return $res;
    }

    /** Where to send the user after a failed form: the referring page on this host, else the current path. */
    private function backPath(Request $req): string
    {
        $ref = (string) ($req->server['HTTP_REFERER'] ?? '');
        $p = parse_url($ref);
        $host = (string) ($req->server['HTTP_HOST'] ?? '');
        if ($p && isset($p['host']) && strcasecmp($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $host) === 0) {
            return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }
        return $req->path;
    }
}
