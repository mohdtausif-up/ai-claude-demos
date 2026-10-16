<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\RateLimiter;
use App\Support\Session;

/**
 * Audience checks that run after the password gate: verified-email allowlists,
 * workspace-only links, owner-only drafts and concurrent-session caps.
 */
final class LinkGate
{
    /** Returns null when access may continue, otherwise the gate that must be passed. */
    public static function check(array $link, array $upload): ?string
    {
        $user = Auth::user();
        if ($upload['visibility'] === 'private' && (int) ($user['id'] ?? 0) !== (int) $upload['owner_user_id']) {
            return 'draft';
        }
        if ($link['audience'] === 'workspace') {
            if ($user === null) {
                return 'workspace_login';
            }
            if ($upload['workspace_id'] === null || WorkspaceService::role((int) $upload['workspace_id'], (int) $user['id']) === null) {
                return 'workspace_denied';
            }
        }
        if (in_array($link['audience'], ['emails', 'domains'], true)) {
            $verified = self::verifiedEmail($link);
            if ($verified === null && $user !== null && AccessRules::emailAllowed($link, $user['email'])) {
                $verified = $user['email'];
            }
            if ($verified === null) {
                return 'verify_email';
            }
        }
        if ($link['max_sessions'] !== null && !self::admitSession($link)) {
            return 'too_many_sessions';
        }
        return null;
    }

    public static function verifiedEmail(array $link): ?string
    {
        $value = Session::get('verified_emails')[$link['public_id']] ?? null;
        return is_string($value) && AccessRules::emailAllowed($link, $value) ? $value : null;
    }

    /**
     * Sends a one-time code when the email is allowed. The response is identical either way,
     * so the form cannot be used to discover who is on the list.
     */
    public static function requestCode(array $link, array $upload, string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::validation(['email' => 'Enter a valid email address.']);
        }
        RateLimiter::enforce('access-code:' . ip_hash(), 10, 3600, 'Too many code requests. Try again later.');
        RateLimiter::enforce('access-code:' . $link['id'] . ':' . hash('sha256', $email), 3, 900, 'A code was sent recently. Check your inbox.');
        if (!AccessRules::emailAllowed($link, $email)) {
            return;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::insert('access_codes', [
            'share_link_id' => $link['id'],
            'email' => $email,
            'code_hash' => hash_hmac('sha256', $code, config('app.key')),
            'expires_at' => db_time(now()->modify('+10 minutes')),
            'created_at' => db_time(),
        ]);
        MailService::queue($email, 'access_code', ['code' => $code, 'type' => $upload['content_type']]);
        Session::put('pending_email', [$link['public_id'] => $email]);
    }

    public static function verifyCode(array $link, string $code): bool
    {
        $email = Session::get('pending_email')[$link['public_id']] ?? null;
        if (!is_string($email)) {
            return false;
        }
        $row = Database::first(
            'SELECT * FROM access_codes WHERE share_link_id = ? AND email = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
            [$link['id'], $email, db_time()]
        );
        if ($row === null || (int) $row['attempts'] >= 5) {
            return false;
        }
        if (!hash_equals($row['code_hash'], hash_hmac('sha256', preg_replace('/\D/', '', $code), config('app.key')))) {
            Database::execute('UPDATE access_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            return false;
        }
        Database::update('access_codes', ['used_at' => db_time()], 'id = ?', [$row['id']]);
        $verified = Session::get('verified_emails', []);
        $verified[$link['public_id']] = $email;
        Session::put('verified_emails', $verified);
        AuditLogService::record('link.email_verified', 'share_link', $link['public_id'], ['email_hash' => hash('sha256', $email)]);
        return true;
    }

    /** Admits this browser session if it is already active or a slot is free (30-minute idle window). */
    private static function admitSession(array $link): bool
    {
        $sid = Session::id() !== '' ? Session::id() : ip_hash();
        $hash = hash_hmac('sha256', 'link-session|' . $link['id'] . '|' . $sid, config('app.key'));
        $now = db_time();
        $since = db_time(now()->modify('-30 minutes'));
        return Database::transaction(static function () use ($link, $hash, $now, $since) {
            Database::first('SELECT id FROM share_links WHERE id = ? FOR UPDATE', [$link['id']]);
            $existing = Database::value('SELECT 1 FROM link_sessions WHERE share_link_id = ? AND session_hash = ? AND last_seen_at >= ?', [$link['id'], $hash, $since]);
            if ($existing === null) {
                $active = (int) Database::value('SELECT COUNT(*) FROM link_sessions WHERE share_link_id = ? AND last_seen_at >= ?', [$link['id'], $since]);
                if ($active >= (int) $link['max_sessions']) {
                    return false;
                }
            }
            Database::execute(
                'INSERT INTO link_sessions (share_link_id, session_hash, created_at, last_seen_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)',
                [$link['id'], $hash, $now, $now]
            );
            return true;
        });
    }
}
