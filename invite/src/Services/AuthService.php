<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;

final class AuthService
{
    public function __construct(
        private UserRepository $users,
        private WorkspaceRepository $workspaces,
        private RateLimiter $limiter,
        private Notifier $notifier,
        private AuditLogger $audit,
        private SessionTracker $sessions,
        private TotpService $totp
    ) {
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /** @throws ValidationException when the email is already registered */
    public function register(string $name, string $email, string $password): array
    {
        $email = mb_strtolower($email);
        $this->limiter->enforce('register:' . client_ip(), 10, 3600);
        if ($this->users->findByEmail($email)) {
            // Same message regardless of whether the account is verified: do not help enumerate accounts beyond this form.
            throw new ValidationException(['email' => ['An account with this email already exists. Try signing in.']], ['name' => $name, 'email' => $email]);
        }
        $isAdmin = strtolower((string) env('ADMIN_EMAIL', '')) === $email;
        $user = Db::transaction(function () use ($name, $email, $password, $isAdmin) {
            $user = $this->users->create($name, $email, self::hashPassword($password), $isAdmin, $isAdmin);
            $this->workspaces->create((int) $user['id'], $email, $name . "'s workspace");
            $this->workspaces->linkPending((int) $user['id'], $email);
            return $user;
        });
        $this->audit->log('user.registered', ['actor_user_id' => (int) $user['id'], 'entity_type' => 'user', 'entity_uuid' => $user['uuid']]);
        if (!$isAdmin) {
            $this->sendVerification($user);
        }
        return $user;
    }

    /** @return array<string,mixed>|null the user, or null on any failure (no hint which part was wrong) */
    public function attempt(string $email, string $password, string $ip, string $ua): ?array
    {
        $email = mb_strtolower($email);
        $keyEmail = 'login:email:' . $email;
        $keyIp = 'login:ip:' . $ip;
        if ($this->limiter->tooMany($keyEmail, 5, 900) || $this->limiter->tooMany($keyIp, 30, 900)) {
            throw new \App\Core\TooManyRequestsException(900);
        }
        $user = $this->users->findByEmail($email);
        // Always run a verification (against a dummy hash for unknown users) so timing does not reveal which emails exist.
        static $dummy = null;
        $dummy ??= self::hashPassword('dummy-password-for-timing');
        $hash = $user['password_hash'] ?? null;
        $valid = password_verify($password, $hash ?: $dummy);
        $ok = $user !== null && $hash !== null && $valid && $user['status'] === 'active';
        $this->recordLogin($user['id'] ?? null, $email, $ip, $ua, $ok);
        if (!$ok) {
            $this->limiter->hit($keyEmail, 5, 900);
            $this->limiter->hit($keyIp, 30, 900);
            return null;
        }
        $this->limiter->clear($keyEmail);
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $this->users->update((int) $user['id'], ['password_hash' => self::hashPassword($password)]);
        }
        return $user;
    }

    private function recordLogin(?int $userId, string $email, string $ip, string $ua, bool $ok): void
    {
        Db::insert('login_history', ['user_id' => $userId, 'email' => $email, 'ip' => $ip, 'user_agent' => mb_substr($ua, 0, 255), 'success' => $ok ? 1 : 0, 'created_at' => utcnow()]);
    }

    /** Password / magic-link success. With two-step verification on, park the user at the second step. @return bool true when fully signed in */
    public function beginLogin(array $user): bool
    {
        if ($this->totp->isEnabled((int) $user['id'])) {
            Session::regenerate();
            Session::put('mfa_pending', (int) $user['id']);
            Session::put('mfa_pending_until', time() + 600);
            return false;
        }
        $this->login($user);
        return true;
    }

    public function login(array $user): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        $this->sessions->start((int) $user['id'], client_ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $this->users->update((int) $user['id'], ['last_login_at' => utcnow()]);
    }

    public function logout(): void
    {
        if ($uid = Session::get('user_id')) {
            Db::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND session_hash = ?', [utcnow(), $uid, hash('sha256', session_id() ?: 'cli')]);
        }
        Session::destroy();
    }

    /** Issue a single-use token; only its hash is stored. Previous unused tokens of the same type are voided. */
    public function issueToken(int $userId, string $type, int $ttlSeconds): string
    {
        Db::exec("UPDATE auth_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL", [utcnow(), $userId, $type]);
        $raw = TokenService::randomToken();
        Db::insert('auth_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => TokenService::hash($raw),
            'expires_at' => utcnow($ttlSeconds),
            'created_at' => utcnow(),
        ]);
        return $raw;
    }

    /** Atomically consume a token. @return int|null the user id */
    public function consumeToken(string $raw, string $type): ?int
    {
        $hash = TokenService::hash($raw);
        $row = Db::one('SELECT id, user_id FROM auth_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > ?', [$hash, $type, utcnow()]);
        if (!$row) {
            return null;
        }
        return Db::exec('UPDATE auth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [utcnow(), $row['id']]) === 1 ? (int) $row['user_id'] : null;
    }

    public function sendVerification(array $user): void
    {
        $token = $this->issueToken((int) $user['id'], 'verify_email', 86400 * 3);
        $this->notifier->email($user['email'], $user['name'], 'Confirm your email for Invite.com', 'verify', [
            'name' => $user['name'], 'link' => app_url('verify/' . $token),
        ]);
    }

    /** Always behaves the same whether or not the account exists. */
    public function sendPasswordReset(string $email): void
    {
        $this->limiter->enforce('reset:' . client_ip(), 10, 3600);
        $user = $this->users->findByEmail($email);
        if ($user && $user['status'] === 'active') {
            $token = $this->issueToken((int) $user['id'], 'password_reset', 3600);
            $this->notifier->email($user['email'], $user['name'], 'Reset your Invite.com password', 'reset', [
                'name' => $user['name'], 'link' => app_url('reset/' . $token),
            ]);
        }
    }

    public function sendMagicLink(string $email): void
    {
        $this->limiter->enforce('magic:' . client_ip(), 10, 3600);
        $user = $this->users->findByEmail($email);
        if ($user && $user['status'] === 'active') {
            $token = $this->issueToken((int) $user['id'], 'magic_login', 900);
            $this->notifier->email($user['email'], $user['name'], 'Your Invite.com sign-in link', 'magic', [
                'name' => $user['name'], 'link' => app_url('magic/' . $token),
            ]);
        }
    }

    public function resetPassword(string $rawToken, string $newPassword): bool
    {
        $userId = $this->consumeToken($rawToken, 'password_reset');
        if ($userId === null) {
            return false;
        }
        $this->users->update($userId, ['password_hash' => self::hashPassword($newPassword), 'email_verified_at' => utcnow()]);
        $this->audit->log('user.password_reset', ['actor_user_id' => $userId, 'entity_type' => 'user']);
        return true;
    }

    public function verifyEmail(string $rawToken): bool
    {
        $userId = $this->consumeToken($rawToken, 'verify_email');
        if ($userId === null) {
            return false;
        }
        $this->users->update($userId, ['email_verified_at' => utcnow()]);
        return true;
    }
}
