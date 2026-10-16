<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Records browser sessions so they can be listed and revoked ("sign out other devices"). */
final class SessionTracker
{
    private static function hash(): string
    {
        return hash('sha256', session_id() ?: 'cli');
    }

    public function start(int $userId, string $ip, string $ua): void
    {
        Db::run(
            'INSERT INTO user_sessions (user_id, session_hash, ip, user_agent, created_at, last_seen_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE revoked_at = NULL, last_seen_at = VALUES(last_seen_at)',
            [$userId, self::hash(), $ip, mb_substr($ua, 0, 255), utcnow(), utcnow()]
        );
    }

    /** @return bool false when this session was revoked elsewhere and must be signed out */
    public function touch(int $userId, string $ip, string $ua): bool
    {
        $row = Db::one('SELECT id, revoked_at, last_seen_at FROM user_sessions WHERE session_hash = ? AND user_id = ?', [self::hash(), $userId]);
        if (!$row) {
            $this->start($userId, $ip, $ua); // session predates tracking
            return true;
        }
        if ($row['revoked_at'] !== null) {
            return false;
        }
        if ($row['last_seen_at'] < utcnow(-60)) {
            Db::exec('UPDATE user_sessions SET last_seen_at = ? WHERE id = ?', [utcnow(), $row['id']]);
        }
        return true;
    }

    public function list(int $userId): array
    {
        return Db::all(
            'SELECT id, ip, user_agent, created_at, last_seen_at, (session_hash = ?) AS is_current FROM user_sessions
             WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > ? ORDER BY last_seen_at DESC LIMIT 20',
            [self::hash(), $userId, utcnow(-86400 * 30)]
        );
    }

    public function revokeOthers(int $userId): int
    {
        return Db::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND session_hash <> ? AND revoked_at IS NULL', [utcnow(), $userId, self::hash()]);
    }

    public function revokeAll(int $userId): void
    {
        Db::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [utcnow(), $userId]);
    }

    public function revoke(int $userId, int $id): void
    {
        Db::exec('UPDATE user_sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND session_hash <> ?', [utcnow(), $id, $userId, self::hash()]);
    }
}
