<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Single source of "now" so expiration logic can be tested with a frozen clock. */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    public static function travel(int $seconds): void
    {
        self::$frozen = self::now()->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}
