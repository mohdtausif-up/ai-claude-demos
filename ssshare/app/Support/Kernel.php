<?php

declare(strict_types=1);

namespace App\Support;

use App\Middleware\ApiAuthenticate;
use App\Middleware\RequireAdmin;
use App\Middleware\RequireAuth;
use App\Middleware\RequireGuest;
use App\Services\NotificationService;
use App\Services\SessionService;
use App\Services\WorkspaceService;
use Throwable;

final class Kernel
{
    private const MIDDLEWARE = [
        'auth' => RequireAuth::class,
        'guest' => RequireGuest::class,
        'admin' => RequireAdmin::class,
        'api' => ApiAuthenticate::class,
    ];

    /** Paths that may be indexed by search engines. Everything else is noindex. */
    private const INDEXABLE = ['/', '/docs/api', '/privacy'];

    private string $requestId;

    public function __construct(private readonly Router $router)
    {
        $this->requestId = bin2hex(random_bytes(8));
    }

    public function handle(Request $request): Response
    {
        // Stateless machine endpoints: no session, no CSRF, JSON errors.
        $isApi = str_starts_with($request->path, '/api/') || in_array($request->path, ['/mcp', '/billing/webhook'], true);
        $isRaw = str_starts_with($request->path, '/r/') || str_starts_with($request->path, '/i/');

        try {
            // Verified custom domains serve only share, media and collection pages.
            $host = strtolower((string) preg_replace('/:\d+$/', '', (string) $request->header('host', '')));
            $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
            if ($host !== '' && $host !== $appHost && !in_array($host, ['localhost', '127.0.0.1', 'web', 'app'], true)) {
                $workspace = WorkspaceService::byVerifiedDomain($host);
                if ($workspace !== null) {
                    View::share('customDomainWorkspace', $workspace);
                    if (!preg_match('#^/(s|r|i|c|assets)/#', $request->path)) {
                        return $this->secure($request, Response::redirect(config('app.url') . $request->path, 302));
                    }
                }
            }

            if (!$isApi && !$isRaw) {
                Session::start($request->isSecure());
                $user = Auth::user();
                if ($user !== null && !SessionService::validate((int) $user['id'])) {
                    // Revoked from another device.
                    Auth::logout();
                    Session::start($request->isSecure());
                    $user = null;
                }
                View::share('currentUser', $user);
                View::share('currentPath', $request->path);
                View::share('announcements', self::announcements());
                View::share('unreadNotifications', $user !== null ? NotificationService::unreadCount((int) $user['id']) : 0);
                View::share('currentWorkspace', WorkspaceService::current($user));
            }
            $maintenance = Settings::get('maintenance');
            if (is_array($maintenance) && !empty($maintenance['enabled']) && !Auth::isAdmin()
                && !preg_match('#^/(login|healthz|assets|admin|billing/webhook)#', $request->path)) {
                throw new HttpException(503, 'maintenance', $maintenance['message'] ?? 'SSShare is undergoing scheduled maintenance. Please try again shortly.', [], ['Retry-After' => '300']);
            }
            $route = $this->router->match($request);
            // The SAML response is POSTed cross-site by the IdP; it is authenticated by its XML signature.
            // /share-target only redirects (the service worker handles shared files locally).
            $csrfExempt = in_array($request->path, ['/sso/saml/acs', '/share-target'], true);
            if (!$isApi && !$isRaw && !$csrfExempt && $request->method !== 'GET' && $request->method !== 'HEAD') {
                Csrf::verify($request);
            }
            $response = $this->pipeline($request, $route);
        } catch (HttpException $e) {
            $response = $this->renderHttpException($request, $e, $isApi || $request->wantsJson());
        } catch (Throwable $e) {
            error_log(sprintf('[%s] %s in %s:%d', $this->requestId, $e->getMessage(), $e->getFile(), $e->getLine()));
            $message = config('app.debug') ? $e->getMessage() : 'Something went wrong on our side. Please try again.';
            $response = $this->renderHttpException($request, new HttpException(500, 'server_error', $message), $isApi || $request->wantsJson());
        }

        return $this->secure($request, $response);
    }

    private function pipeline(Request $request, array $route): Response
    {
        $core = function (Request $request) use ($route): Response {
            [$class, $method] = $route['handler'];
            return (new $class())->$method($request, $route['params']);
        };
        $next = $core;
        foreach (array_reverse($route['middleware']) as $name) {
            $class = self::MIDDLEWARE[$name];
            $inner = $next;
            $next = static fn (Request $r): Response => (new $class())->handle($r, $inner);
        }
        return $next($request);
    }

    private function renderHttpException(Request $request, HttpException $e, bool $json): Response
    {
        if ($json) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->details !== []) {
                $error['details'] = $e->details;
            }
            $error['request_id'] = $this->requestId;
            $response = Response::json(['error' => $error], $e->status);
        } else {
            if ($e->status === 401 && $e->errorCode === 'login_required') {
                return Response::redirect('/login?next=' . rawurlencode($request->path));
            }
            try {
                $html = View::render('errors/error', ['status' => $e->status, 'message' => $e->getMessage(), 'title' => self::title($e->status)]);
            } catch (Throwable) {
                $html = '<!doctype html><title>Error</title><p>' . e($e->getMessage()) . '</p>';
            }
            $response = Response::html($html, $e->status);
        }
        foreach ($e->headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        return $response;
    }

    private static function announcements(): array
    {
        try {
            return Database::select(
                'SELECT id, message, level FROM announcements WHERE starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY id DESC LIMIT 3',
                [db_time(), db_time()]
            );
        } catch (Throwable) {
            return [];
        }
    }

    private static function title(int $status): string
    {
        return match ($status) {
            402 => 'Upgrade required',
            503 => 'Temporarily unavailable',
            403 => 'Access denied',
            404 => 'Not found',
            405 => 'Not allowed',
            410 => 'No longer available',
            413 => 'Too large',
            419 => 'Session expired',
            422 => 'Please check your input',
            429 => 'Slow down',
            default => 'Something went wrong',
        };
    }

    private function secure(Request $request, Response $response): Response
    {
        $defaults = [
            'X-Request-Id' => $this->requestId,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), interest-cohort=()',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
                . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        ];
        if (!in_array($request->path, self::INDEXABLE, true)) {
            $defaults['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }
        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if (!isset($response->headers['Cache-Control'])) {
            $defaults['Cache-Control'] = 'no-store, private';
        }
        // Route-specific headers (e.g. a sandboxed CSP for raw media) take precedence.
        $response->headers = $response->headers + $defaults;
        return $response;
    }
}
