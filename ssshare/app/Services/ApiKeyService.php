<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;

/** API keys are shown once and stored only as SHA-256 hashes. */
final class ApiKeyService
{
    public const SCOPES = ['uploads:read', 'uploads:write', 'folders:read', 'folders:write'];

    /** @return array{key:string, row:array} */
    public function create(array $user, string $name, array $scopes = self::SCOPES, ?string $expiresAt = null, string $environment = 'live'): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw HttpException::validation(['name' => 'Give the key a name up to 80 characters.']);
        }
        $scopes = array_values(array_intersect(self::SCOPES, $scopes));
        if ($scopes === []) {
            throw HttpException::validation(['scopes' => 'Choose at least one scope.']);
        }
        $active = (int) Database::value('SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
        if ($active >= 20) {
            throw HttpException::validation(['name' => 'You can have up to 20 active keys. Revoke one first.']);
        }

        // Sandbox (test) keys create real but short-lived shares that never count against quota.
        $environment = $environment === 'test' ? 'test' : 'live';
        $secret = 'sss_' . $environment . '_' . Ids::token(24);
        $id = Database::insert('api_keys', [
            'public_id' => Ids::publicId(12),
            'user_id' => $user['id'],
            'name' => $name,
            'environment' => $environment,
            'prefix' => substr($secret, 0, 13),
            'key_hash' => Ids::hashToken($secret),
            'scopes' => json_encode($scopes),
            'expires_at' => $expiresAt,
            'created_at' => db_time(),
        ]);
        $row = Database::first('SELECT * FROM api_keys WHERE id = ?', [$id]);
        AuditLogService::record('api_key.created', 'api_key', $row['public_id'], ['name' => $name, 'scopes' => $scopes, 'environment' => $environment]);
        return ['key' => $secret, 'row' => $row];
    }

    /** @return array{key:array, user:array} */
    public function authenticate(?string $authorization): array
    {
        if ($authorization === null || !preg_match('/^Bearer\s+(sss_(?:live|test)_[A-Za-z0-9_-]{20,})$/', trim($authorization), $m)) {
            throw new HttpException(401, 'unauthenticated', 'Provide an API key as "Authorization: Bearer sss_live_...".', [], ['WWW-Authenticate' => 'Bearer']);
        }
        $key = Database::first('SELECT * FROM api_keys WHERE key_hash = ?', [Ids::hashToken($m[1])]);
        if ($key === null || $key['revoked_at'] !== null) {
            throw new HttpException(401, 'invalid_api_key', 'This API key is invalid or has been revoked.', [], ['WWW-Authenticate' => 'Bearer']);
        }
        if ($key['expires_at'] !== null && strtotime($key['expires_at'] . ' UTC') <= now()->getTimestamp()) {
            throw new HttpException(401, 'api_key_expired', 'This API key has expired.', [], ['WWW-Authenticate' => 'Bearer']);
        }
        $user = Database::first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$key['user_id']]);
        if ($user === null) {
            throw new HttpException(403, 'account_suspended', 'The account that owns this key is suspended.');
        }
        Database::update('api_keys', ['last_used_at' => db_time()], 'id = ?', [$key['id']]);
        $key['scopes'] = json_decode($key['scopes'], true) ?: [];
        return ['key' => $key, 'user' => $user];
    }

    public function revoke(array $user, string $publicId): void
    {
        $changed = Database::execute(
            'UPDATE api_keys SET revoked_at = ? WHERE public_id = ? AND user_id = ? AND revoked_at IS NULL',
            [db_time(), $publicId, $user['id']]
        );
        if ($changed === 0) {
            throw HttpException::notFound('API key not found.');
        }
        AuditLogService::record('api_key.revoked', 'api_key', $publicId);
    }
}
