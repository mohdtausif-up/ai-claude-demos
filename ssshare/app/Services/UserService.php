<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\RateLimiter;

final class UserService
{
    public function register(string $email, string $password, string $name): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (Database::value('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            $errors['email'] = 'An account with this email already exists.';
        }
        if (strlen($password) < 10) {
            $errors['password'] = 'Use at least 10 characters.';
        } elseif (strlen($password) > 200) {
            $errors['password'] = 'Use at most 200 characters.';
        }
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['display_name'] = 'Enter a name up to 80 characters.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $now = db_time();
        $id = Database::insert('users', [
            'uuid' => Ids::uuid(),
            'email' => $email,
            'password_hash' => PasswordService::hash($password),
            'display_name' => $name,
            'storage_quota_bytes' => (int) config('app.uploads.default_user_quota_bytes'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user = Database::first('SELECT * FROM users WHERE id = ?', [$id]);
        AuditLogService::record('user.registered', 'user', $user['uuid'], [], 'user', $id);
        return $user;
    }

    public function authenticate(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $max = (int) config('app.security.login_attempts_per_window');
        RateLimiter::enforce('login:ip:' . ip_hash(), $max * 3, 900, 'Too many sign-in attempts. Try again in 15 minutes.');
        RateLimiter::enforce('login:email:' . hash('sha256', $email), $max, 900, 'Too many sign-in attempts. Try again in 15 minutes.');

        $user = Database::first('SELECT * FROM users WHERE email = ?', [$email]);
        // Verify against a dummy hash when the user does not exist to keep timing uniform.
        static $dummy = null;
        $hash = $user['password_hash'] ?? ($dummy ??= PasswordService::hash(random_bytes(16)));
        $ok = PasswordService::verify($password, $hash) && $user !== null;

        if (!$ok) {
            AuditLogService::record('user.login_failed', 'user', $user['uuid'] ?? null, [], 'guest');
            throw new HttpException(422, 'invalid_credentials', 'Incorrect email or password.', ['email' => 'Incorrect email or password.']);
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'account_suspended', 'This account has been suspended.');
        }
        if (PasswordService::needsRehash($user['password_hash'])) {
            Database::update('users', ['password_hash' => PasswordService::hash($password)], 'id = ?', [$user['id']]);
        }
        Database::update('users', ['last_login_at' => db_time()], 'id = ?', [$user['id']]);
        RateLimiter::clear('login:email:' . hash('sha256', $email));
        AuditLogService::record('user.login', 'user', $user['uuid'], [], 'user', (int) $user['id']);
        return $user;
    }

    public static function storageUsed(int $userId): int
    {
        return (int) Database::value("SELECT COALESCE(SUM(storage_bytes), 0) FROM uploads WHERE owner_user_id = ? AND status <> 'deleted'", [$userId]);
    }
}
