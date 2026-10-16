<?php

declare(strict_types=1);

namespace App\Core;

/** Cookie-session wrapper with next-request flash data (messages, old input, validation errors). */
final class Session
{
    private static bool $started = false;
    /** Flash data available during this request (written by the previous one). */
    private static array $now = [];

    public static function start(bool $secure): void
    {
        if (self::$started) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION = [];
            self::$started = true;
            return;
        }
        session_name('invite_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;
        self::$now = $_SESSION['_next'] ?? [];
        unset($_SESSION['_next']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_next'][$key] = $value;
    }

    /** Read flash data set by the previous request. */
    public static function peek(string $key, mixed $default = null): mixed
    {
        return self::$now[$key] ?? $default;
    }

    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function reset(): void
    {
        $_SESSION = [];
        self::$now = [];
        self::$started = false;
    }
}
