<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use Throwable;

/** Daily bytes served per owner, for admin storage and bandwidth monitoring. */
final class BandwidthService
{
    public static function record(?int $ownerId, int $bytes): void
    {
        try {
            Database::execute(
                'INSERT INTO bandwidth_usage (day, owner_user_id, bytes, requests) VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE bytes = bytes + VALUES(bytes), requests = requests + 1',
                [now()->format('Y-m-d'), $ownerId ?? 0, $bytes]
            );
        } catch (Throwable) {
            // Metering must never break delivery.
        }
    }

    public static function lastDays(int $days = 14): array
    {
        return Database::select(
            'SELECT day, SUM(bytes) AS bytes, SUM(requests) AS requests FROM bandwidth_usage WHERE day >= ? GROUP BY day ORDER BY day',
            [now()->modify("-{$days} days")->format('Y-m-d')]
        );
    }
}
