<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Self-hosted, privacy-preserving CAPTCHA: a proof-of-work challenge the browser solves
 * automatically (no images, no third parties, no tracking). It is only required after
 * suspicious activity on a link, such as repeated wrong passwords.
 */
final class CaptchaService
{
    public const DIFFICULTY = 16; // leading zero bits; ~65k hashes, well under a second in a browser
    private const TTL = 300;

    public static function challenge(string $scope): string
    {
        $expires = now()->getTimestamp() + self::TTL;
        $nonce = bin2hex(random_bytes(8));
        $payload = "$scope|$expires|$nonce";
        return $payload . '|' . substr(hash_hmac('sha256', $payload, config('app.key')), 0, 32);
    }

    public static function verify(string $scope, string $challenge, string $solution): bool
    {
        $parts = explode('|', $challenge);
        if (count($parts) !== 4 || $parts[0] !== $scope || (int) $parts[1] < now()->getTimestamp()) {
            return false;
        }
        $expected = substr(hash_hmac('sha256', "{$parts[0]}|{$parts[1]}|{$parts[2]}", config('app.key')), 0, 32);
        if (!hash_equals($expected, $parts[3]) || !ctype_digit($solution) || strlen($solution) > 12) {
            return false;
        }
        // Each challenge can be used once.
        try {
            Database::insert('rate_limits', ['bucket' => 'captcha:' . hash('sha256', $challenge), 'hits' => 1, 'window_start' => db_time()]);
        } catch (\PDOException) {
            return false;
        }
        return self::leadingZeroBits(hash('sha256', $challenge . ':' . $solution, true)) >= self::DIFFICULTY;
    }

    public static function leadingZeroBits(string $binary): int
    {
        $bits = 0;
        foreach (str_split($binary) as $byte) {
            $b = ord($byte);
            if ($b === 0) {
                $bits += 8;
                continue;
            }
            return $bits + (7 - (int) floor(log($b, 2)));
        }
        return $bits;
    }

    /** Server-side solver, used by tests and the CLI. */
    public static function solve(string $challenge): string
    {
        for ($i = 0; ; $i++) {
            if (self::leadingZeroBits(hash('sha256', $challenge . ':' . $i, true)) >= self::DIFFICULTY) {
                return (string) $i;
            }
        }
    }
}
