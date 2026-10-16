<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use App\Services\TokenService;

/** Bearer-token auth for /api/v1. The token pins the request to one workspace. */
final class ApiAuthenticate
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $req, callable $next): Response
    {
        $raw = $req->bearer();
        if ($raw === null) {
            throw new HttpException(401, 'Missing bearer token');
        }
        $row = Db::one(
            'SELECT t.id AS token_id, t.workspace_id AS token_workspace_id, t.last_used_at AS token_last_used_at, u.*
             FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > ?) AND u.status = \'active\'',
            [TokenService::hash($raw), utcnow()]
        );
        if (!$row) {
            throw new HttpException(401, 'Invalid or expired token');
        }
        $this->limiter->enforce('api:' . $row['token_id'], 120, 60);
        if ($row['token_last_used_at'] === null || $row['token_last_used_at'] < utcnow(-60)) {
            Db::exec('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [utcnow(), $row['token_id']]);
        }
        $req->attrs['api_workspace_id'] = (int) $row['token_workspace_id'];
        unset($row['token_id'], $row['token_workspace_id'], $row['token_last_used_at']);
        $req->attrs['user'] = $row;
        return $next($req);
    }
}
