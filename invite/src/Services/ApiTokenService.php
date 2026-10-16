<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Personal API tokens for /api/v1. The plaintext is shown once; only a SHA-256 hash is stored. */
final class ApiTokenService
{
    /** @return array{token:string,id:int} */
    public function create(int $userId, int $workspaceId, string $name, ?int $expiresInDays): array
    {
        $raw = 'inv_' . base64url(random_bytes(32));
        $id = Db::insert('api_tokens', [
            'user_id' => $userId, 'workspace_id' => $workspaceId, 'name' => mb_substr($name, 0, 100),
            'token_hash' => TokenService::hash($raw),
            'expires_at' => $expiresInDays ? utcnow($expiresInDays * 86400) : null,
            'created_at' => utcnow(),
        ]);
        return ['token' => $raw, 'id' => $id];
    }

    public function list(int $userId): array
    {
        return Db::all(
            'SELECT t.id, t.name, t.created_at, t.last_used_at, t.expires_at, t.revoked_at, w.name AS workspace_name
             FROM api_tokens t JOIN workspaces w ON w.id = t.workspace_id WHERE t.user_id = ? ORDER BY t.id DESC',
            [$userId]
        );
    }

    public function revoke(int $userId, int $id): void
    {
        Db::exec('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [utcnow(), $id, $userId]);
    }
}
