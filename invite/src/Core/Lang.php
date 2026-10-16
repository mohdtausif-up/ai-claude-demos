<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/** Guest-facing translations. Host UI is English; invitation pages follow the event/guest language. */
final class Lang
{
    public const RTL = ['ar', 'he', 'fa', 'ur'];
    public const SUPPORTED = ['en' => 'English', 'hi' => 'हिन्दी', 'ar' => 'العربية', 'es' => 'Español'];

    private static string $locale = 'en';
    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    public static function set(string $locale): void
    {
        self::$locale = isset(self::SUPPORTED[$locale]) ? $locale : 'en';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function dir(?string $locale = null): string
    {
        return in_array($locale ?? self::$locale, self::RTL, true) ? 'rtl' : 'ltr';
    }

    private static function load(string $locale): array
    {
        if (!isset(self::$cache[$locale])) {
            $f = BASE_PATH . '/lang/' . $locale . '.php';
            self::$cache[$locale] = is_file($f) ? (require $f) : [];
        }
        return self::$cache[$locale];
    }

    public static function get(string $key, array $vars = []): string
    {
        $s = self::load(self::$locale)[$key] ?? self::load('en')[$key] ?? $key;
        foreach ($vars as $k => $v) {
            $s = str_replace(':' . $k, (string) $v, (string) $s);
        }
        return (string) $s;
    }

    /** Date formatting with translated month/day names (ext-intl is not required). */
    public static function date(DateTimeImmutable $dt, string $fmt): string
    {
        if (self::$locale !== 'en') {
            $fmt = self::localizeFormat($dt, $fmt);
        }
        return $dt->format($fmt);
    }

    /** Replace the name-producing format characters (F M l D) with escaped translated literals. */
    private static function localizeFormat(DateTimeImmutable $dt, string $fmt): string
    {
        $t = self::load(self::$locale);
        $m = (int) $dt->format('n') - 1;
        $d = (int) $dt->format('N') - 1;
        $names = [
            'F' => $t['_months'][$m] ?? null, 'M' => $t['_months_short'][$m] ?? null,
            'l' => $t['_days'][$d] ?? null, 'D' => $t['_days_short'][$d] ?? null,
        ];
        $esc = static fn (string $s) => implode('', array_map(static fn ($c) => '\\' . $c, preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $out = '';
        $len = strlen($fmt);
        for ($i = 0; $i < $len; $i++) {
            $ch = $fmt[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= $ch . $fmt[++$i];
            } elseif (isset($names[$ch]) && $names[$ch] !== null) {
                $out .= $esc($names[$ch]);
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }
}
