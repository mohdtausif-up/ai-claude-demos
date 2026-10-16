<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/** Runtime settings and feature flags managed from the admin portal. */
final class Settings
{
    private static array $cache = [];
    private static ?array $flags = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            try {
                $value = Database::value('SELECT value FROM settings WHERE `key` = ?', [$key]);
            } catch (Throwable) {
                $value = null;
            }
            self::$cache[$key] = $value === null ? null : json_decode($value, true);
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        Database::execute(
            'INSERT INTO settings (`key`, value, updated_by, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$key, json_encode($value), $userId, db_time()]
        );
        self::$cache[$key] = $value;
    }

    public static function feature(string $key): bool
    {
        if (self::$flags === null) {
            try {
                self::$flags = array_column(Database::select('SELECT `key`, enabled FROM feature_flags'), 'enabled', 'key');
            } catch (Throwable) {
                self::$flags = [];
            }
        }
        return (int) (self::$flags[$key] ?? 0) === 1;
    }

    public static function setFeature(string $key, bool $enabled, ?int $userId): void
    {
        Database::execute('UPDATE feature_flags SET enabled = ?, updated_by = ?, updated_at = ? WHERE `key` = ?', [$enabled ? 1 : 0, $userId, db_time(), $key]);
        self::$flags = null;
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$flags = null;
    }
}
