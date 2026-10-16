<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Database-backed job queue (notification_jobs). Workers claim rows with FOR UPDATE SKIP LOCKED. */
final class QueueService
{
    public function push(string $type, array $payload, int $delaySeconds = 0, int $maxAttempts = 5): int
    {
        return Db::insert('notification_jobs', [
            'type' => $type,
            'payload' => json_out($payload),
            'status' => 'pending',
            'max_attempts' => $maxAttempts,
            'available_at' => utcnow($delaySeconds),
            'created_at' => utcnow(),
        ]);
    }

    public function pushAt(string $type, array $payload, string $availableAtUtc, int $maxAttempts = 5): int
    {
        return Db::insert('notification_jobs', [
            'type' => $type,
            'payload' => json_out($payload),
            'status' => 'pending',
            'max_attempts' => $maxAttempts,
            'available_at' => $availableAtUtc,
            'created_at' => utcnow(),
        ]);
    }

    /** @return array<string,mixed>|null the claimed job (payload decoded) */
    public function reserve(): ?array
    {
        return Db::transaction(function () {
            $job = Db::one(
                "SELECT * FROM notification_jobs WHERE status = 'pending' AND available_at <= ? ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED",
                [utcnow()]
            );
            if (!$job) {
                return null;
            }
            Db::exec(
                "UPDATE notification_jobs SET status = 'processing', reserved_at = ?, attempts = attempts + 1 WHERE id = ?",
                [utcnow(), $job['id']]
            );
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['payload'] = json_decode((string) $job['payload'], true) ?: [];
            return $job;
        });
    }

    public function complete(int $id): void
    {
        Db::exec("UPDATE notification_jobs SET status = 'done', completed_at = ?, last_error = NULL WHERE id = ?", [utcnow(), $id]);
    }

    /** @return bool true when the job is permanently failed (attempts exhausted) */
    public function fail(array $job, string $error): bool
    {
        $final = (int) $job['attempts'] >= (int) $job['max_attempts'];
        if ($final) {
            Db::exec("UPDATE notification_jobs SET status = 'failed', last_error = ?, completed_at = ? WHERE id = ?", [str_limit($error, 480), utcnow(), $job['id']]);
        } else {
            $backoff = 30 * (2 ** ((int) $job['attempts'] - 1));
            Db::exec("UPDATE notification_jobs SET status = 'pending', last_error = ?, available_at = ? WHERE id = ?", [str_limit($error, 480), utcnow($backoff), $job['id']]);
        }
        return $final;
    }

    /** Jobs whose worker died mid-flight go back to pending. */
    public function reclaimStale(int $olderThanSeconds = 300): int
    {
        return Db::exec("UPDATE notification_jobs SET status = 'pending' WHERE status = 'processing' AND reserved_at < ?", [utcnow(-$olderThanSeconds)]);
    }

    public function purgeOld(int $days = 14): int
    {
        return Db::exec("DELETE FROM notification_jobs WHERE status = 'done' AND completed_at < ?", [utcnow(-$days * 86400)]);
    }
}
