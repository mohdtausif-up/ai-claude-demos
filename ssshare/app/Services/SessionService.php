<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Session;
use App\Support\Ids;
use App\Support\Request;

/** Tracks signed-in sessions so users can see and revoke them ("security sessions"). */
final class SessionService
{
    private static function hash(string $sessionId): string
    {
        return hash_hmac('sha256', 'session|' . $sessionId, config('app.key'));
    }

    public static function record(array $user, Request $request, string $method): void
    {
        $sid = Session::id();
        if ($sid === '') {
            return;
        }
        $ua = $request->userAgent();
        Database::execute(
            'INSERT INTO user_sessions (public_id, user_id, session_hash, ip_hash, device, browser, method, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)',
            [Ids::publicId(12), $user['id'], self::hash($sid), ip_hash(), AnalyticsService::device($ua), AnalyticsService::browser($ua), $method, db_time(), db_time()]
        );
    }

    /** False when this session was revoked from another device. Touches last_seen at most once a minute. */
    public static function validate(int $userId): bool
    {
        $sid = Session::id();
        if ($sid === '') {
            return true;
        }
        $row = Database::first('SELECT id, revoked_at, last_seen_at FROM user_sessions WHERE session_hash = ? AND user_id = ?', [self::hash($sid), $userId]);
        if ($row === null) {
            return true; // sessions created before tracking existed
        }
        if ($row['revoked_at'] !== null) {
            return false;
        }
        if (strtotime($row['last_seen_at'] . ' UTC') < now()->getTimestamp() - 60) {
            Database::update('user_sessions', ['last_seen_at' => db_time()], 'id = ?', [$row['id']]);
        }
        return true;
    }

    public static function forUser(int $userId): array
    {
        $current = Session::id() !== '' ? self::hash(Session::id()) : '';
        return array_map(static fn ($s) => $s + ['current' => hash_equals($s['session_hash'], $current)], Database::select(
            'SELECT * FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > ? ORDER BY last_seen_at DESC',
            [$userId, db_time(now()->modify('-30 days'))]
        ));
    }

    public static function revoke(int $userId, string $publicId): void
    {
        Database::execute('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND public_id = ?', [db_time(), $userId, $publicId]);
    }

    public static function revokeOthers(int $userId): void
    {
        $current = Session::id() !== '' ? self::hash(Session::id()) : '';
        Database::execute('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND session_hash <> ? AND revoked_at IS NULL', [db_time(), $userId, $current]);
    }

    public static function end(): void
    {
        if (Session::id() !== '') {
            Database::execute('UPDATE user_sessions SET revoked_at = ? WHERE session_hash = ?', [db_time(), self::hash(Session::id())]);
        }
    }
}
