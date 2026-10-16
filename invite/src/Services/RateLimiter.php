<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\TooManyRequestsException;

/** Fixed-window limiter backed by MySQL, so it works across app servers. */
final class RateLimiter
{
    /** Count one hit; true while still within the limit. */
    public function hit(string $key, int $max, int $windowSeconds): bool
    {
        $hash = hash('sha256', $key);
        $now = utcnow();
        $cutoff = utcnow(-$windowSeconds);
        Db::run(
            'INSERT INTO rate_limits (key_hash, hits, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = IF(window_start < ?, 1, hits + 1), window_start = IF(window_start < ?, ?, window_start)',
            [$hash, $now, $cutoff, $cutoff, $now]
        );
        return (int) Db::value('SELECT hits FROM rate_limits WHERE key_hash = ?', [$hash]) <= $max;
    }

    /** @throws TooManyRequestsException */
    public function enforce(string $key, int $max, int $windowSeconds): void
    {
        if (!$this->hit($key, $max, $windowSeconds)) {
            throw new TooManyRequestsException($windowSeconds);
        }
    }

    public function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $row = Db::one('SELECT hits, window_start FROM rate_limits WHERE key_hash = ?', [hash('sha256', $key)]);
        return $row !== null && $row['window_start'] >= utcnow(-$windowSeconds) && (int) $row['hits'] >= $max;
    }

    public function clear(string $key): void
    {
        Db::exec('DELETE FROM rate_limits WHERE key_hash = ?', [hash('sha256', $key)]);
    }

    public function purgeExpired(): int
    {
        return Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [utcnow(-86400)]);
    }
}
