<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    private static ?array $items = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$items ??= ['app' => require BASE_PATH . '/config/app.php'];
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** Runtime override, used by tests and CLI tools. */
    public static function set(string $key, mixed $value): void
    {
        self::get('app');
        $ref = &self::$items;
        foreach (explode('.', $key) as $segment) {
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }
}
