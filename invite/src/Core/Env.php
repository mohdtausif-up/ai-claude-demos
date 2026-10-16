<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    /** Load KEY=VALUE pairs from a file. Real environment variables always win. */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            self::$values[trim($k)] = trim($v, " \t\"'");
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
        putenv($key . '=' . $value);
    }
}
