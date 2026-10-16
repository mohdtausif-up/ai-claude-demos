<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\ApiKeyService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\RateLimiter;
use App\Support\Request;
use App\Support\Response;

/** Bearer-key authentication, per-key rate limiting and usage accounting. */
final class ApiAuthenticate
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = (new ApiKeyService())->authenticate($request->header('authorization'));
        Auth::setUser($auth['user']);
        $request->attributes['api_key'] = $auth['key'];
        $request->attributes['user'] = $auth['user'];

        $perMinute = (int) config('app.api.requests_per_minute');
        $limit = RateLimiter::hit('api:' . $auth['key']['id'], $perMinute, 60);
        $headers = [
            'X-RateLimit-Limit' => (string) $limit['limit'],
            'X-RateLimit-Remaining' => (string) $limit['remaining'],
            'X-RateLimit-Reset' => (string) $limit['reset'],
        ];
        if (!$limit['allowed']) {
            throw new HttpException(429, 'rate_limited', 'Rate limit exceeded. Retry after the reset time.', [], $headers + [
                'Retry-After' => (string) max(1, $limit['reset'] - now()->getTimestamp()),
            ]);
        }

        Database::execute(
            'INSERT INTO api_usage (user_id, period, requests) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE requests = requests + 1',
            [$auth['user']['id'], now()->format('Y-m')]
        );

        $response = $next($request);
        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        return $response;
    }

    public static function requireScope(Request $request, string $scope): void
    {
        if (!in_array($scope, $request->attributes['api_key']['scopes'] ?? [], true)) {
            throw new HttpException(403, 'insufficient_scope', "This API key is missing the \"$scope\" scope.");
        }
    }
}
