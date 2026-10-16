<?php

declare(strict_types=1);

namespace App\Support;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (!self::$resolved) {
            self::$resolved = true;
            $id = Session::get('user_id');
            if (is_int($id)) {
                self::$user = Database::first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$id]);
                if (self::$user === null) {
                    Session::forget('user_id');
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        self::$user = $user;
        self::$resolved = true;
    }

    /** Used by the API and CLI where there is no session. */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
        self::$resolved = true;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$resolved = true;
    }
}
