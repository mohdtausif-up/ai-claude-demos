<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Session;

/** Password gates and brute-force protection for share links. */
final class AccessPolicyService
{
    public static function requiresPassword(array $link): bool
    {
        return $link['access_mode'] === 'password' && $link['password_hash'] !== null;
    }

    public static function isUnlocked(array $link): bool
    {
        if (!self::requiresPassword($link)) {
            return true;
        }
        $grant = Session::get('unlocked')[$link['public_id']] ?? null;
        // Grants are bound to the current password hash, so changing the password revokes them.
        return is_string($grant) && hash_equals(self::grantFor($link), $grant);
    }

    private static function grantFor(array $link): string
    {
        return hash_hmac('sha256', 'unlock|' . $link['public_id'] . '|' . $link['password_hash'], config('app.key'));
    }

    public static function unlock(array $link, string $password, Request $request): bool
    {
        $window = (int) config('app.security.password_window_seconds');
        $max = (int) config('app.security.password_attempts_per_window');
        $since = db_time(now()->modify("-{$window} seconds"));
        $ipHash = ip_hash();

        $failures = (int) Database::value(
            'SELECT COUNT(*) FROM password_attempts WHERE share_link_id = ? AND ip_hash = ? AND success = 0 AND created_at >= ?',
            [$link['id'], $ipHash, $since]
        );
        // A global ceiling per link stops distributed guessing.
        $globalFailures = (int) Database::value(
            'SELECT COUNT(*) FROM password_attempts WHERE share_link_id = ? AND success = 0 AND created_at >= ?',
            [$link['id'], $since]
        );
        if ($failures >= $max || $globalFailures >= $max * 20) {
            throw HttpException::tooManyRequests($window, 'Too many incorrect attempts. Try again in ' . intdiv($window, 60) . ' minutes.');
        }

        $ok = PasswordService::verify($password, (string) $link['password_hash']);
        Database::insert('password_attempts', [
            'share_link_id' => $link['id'], 'ip_hash' => $ipHash, 'success' => $ok ? 1 : 0, 'created_at' => db_time(),
        ]);
        if (!$ok) {
            AnalyticsService::recordView((int) $link['id'], 'password_failed', $request, AnalyticsService::viewerHash($request));
            return false;
        }

        if (PasswordService::needsRehash((string) $link['password_hash'])) {
            Database::update('share_links', ['password_hash' => PasswordService::hash($password)], 'id = ?', [$link['id']]);
            $link['password_hash'] = Database::value('SELECT password_hash FROM share_links WHERE id = ?', [$link['id']]);
        }
        $unlocked = Session::get('unlocked', []);
        $unlocked[$link['public_id']] = self::grantFor($link);
        Session::put('unlocked', $unlocked);
        return true;
    }
}
