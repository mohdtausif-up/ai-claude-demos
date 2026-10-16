<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed-window counters stored in MySQL (swap for Redis in production at scale). */
final class RateLimiter
{
    /** @return array{allowed:bool, limit:int, remaining:int, reset:int} */
    public static function hit(string $bucket, int $max, int $windowSeconds): array
    {
        $now = db_time();
        $bucket = substr($bucket, 0, 190);
        // Both IF()s read the pre-update window_start, so they see the same window.
        Database::execute(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start <= ? - INTERVAL ? SECOND, 1, hits + 1),
                window_start = IF(window_start <= ? - INTERVAL ? SECOND, ?, window_start)',
            [$bucket, $now, $now, $windowSeconds, $now, $windowSeconds, $now]
        );
        $row = Database::first('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [$bucket]);
        $hits = (int) $row['hits'];
        $reset = strtotime($row['window_start'] . ' UTC') + $windowSeconds;

        return [
            'allowed' => $hits <= $max,
            'limit' => $max,
            'remaining' => max(0, $max - $hits),
            'reset' => $reset,
        ];
    }

    public static function enforce(string $bucket, int $max, int $windowSeconds, string $message = 'Too many requests. Please slow down.'): array
    {
        $result = self::hit($bucket, $max, $windowSeconds);
        if (!$result['allowed']) {
            throw HttpException::tooManyRequests(max(1, $result['reset'] - now()->getTimestamp()), $message);
        }
        return $result;
    }

    public static function clear(string $bucket): void
    {
        Database::execute('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
    }
}
