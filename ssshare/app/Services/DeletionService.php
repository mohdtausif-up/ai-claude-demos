<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use Throwable;

/**
 * Physical deletion of stored content. Jobs are idempotent (one per upload, deleting a
 * missing object succeeds) and retried with exponential backoff; jobs that keep failing
 * surface in the admin portal.
 */
final class DeletionService
{
    public const MAX_ATTEMPTS = 6;

    public function queue(int $uploadId, string $reason, int $delaySeconds = 0): void
    {
        $now = now();
        $runAfter = db_time($now->modify("+{$delaySeconds} seconds"));
        Database::execute(
            "INSERT INTO deletion_jobs (upload_id, reason, status, attempts, run_after, created_at, updated_at)
             VALUES (?, ?, 'pending', 0, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                run_after = IF(status = 'done', run_after, LEAST(run_after, VALUES(run_after))),
                updated_at = VALUES(updated_at)",
            [$uploadId, substr($reason, 0, 32), $runAfter, db_time($now), db_time($now)]
        );
        Database::execute('UPDATE uploads SET purge_at = COALESCE(LEAST(purge_at, ?), ?) WHERE id = ?', [$runAfter, $runAfter, $uploadId]);
    }

    /** Revokes every link and deletes the content now (owner or admin request). */
    public function deleteNow(array $upload, string $reason, string $uploadStatus = 'revoked'): void
    {
        Database::transaction(function () use ($upload, $reason, $uploadStatus) {
            $now = db_time();
            Database::execute('SELECT id FROM uploads WHERE id = ? FOR UPDATE', [$upload['id']]);
            Database::execute(
                "UPDATE share_links SET status = 'revoked', end_reason = ?, ended_at = ?, revoked_at = ?, updated_at = ?
                 WHERE upload_id = ? AND status IN ('active', 'paused')",
                [$reason, $now, $now, $now, $upload['id']]
            );
            Database::execute(
                "UPDATE uploads SET status = ?, soft_deleted_at = ?, updated_at = ? WHERE id = ? AND status <> 'deleted'",
                [$uploadStatus, $now, $now, $upload['id']]
            );
            $this->queue((int) $upload['id'], $reason);
        });

        $this->purgeNow((int) $upload['id']);
    }

    /** Runs an upload's due job immediately; the worker retries if storage is unavailable. */
    public function purgeNow(int $uploadId): void
    {
        $job = Database::first("SELECT * FROM deletion_jobs WHERE upload_id = ? AND status = 'pending' AND run_after <= ?", [$uploadId, db_time()]);
        if ($job !== null && $this->claim((int) $job['id'])) {
            $this->process($job);
        }
    }

    /** Processes due jobs. Returns [done, failed]. */
    public function runDue(int $limit = 100): array
    {
        $now = db_time();
        // Recover jobs left "running" by a crashed worker.
        Database::execute(
            "UPDATE deletion_jobs SET status = 'pending', locked_at = NULL WHERE status = 'running' AND locked_at < ? - INTERVAL 10 MINUTE",
            [$now]
        );
        $jobs = Database::select(
            "SELECT * FROM deletion_jobs WHERE status = 'pending' AND run_after <= ? ORDER BY run_after LIMIT $limit",
            [$now]
        );
        $done = $failed = 0;
        foreach ($jobs as $job) {
            if (!$this->claim((int) $job['id'])) {
                continue;
            }
            $this->process($job) ? $done++ : $failed++;
        }
        return [$done, $failed];
    }

    private function claim(int $jobId): bool
    {
        return Database::execute(
            "UPDATE deletion_jobs SET status = 'running', locked_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
            [db_time(), db_time(), $jobId]
        ) === 1;
    }

    public function process(array $job): bool
    {
        $uploadId = (int) $job['upload_id'];
        try {
            $upload = Database::first('SELECT * FROM uploads WHERE id = ?', [$uploadId]);
            // Content that was re-activated (e.g. an owner restored a link) is not purged.
            $live = (int) Database::value("SELECT COUNT(*) FROM share_links WHERE upload_id = ? AND status IN ('active', 'paused')", [$uploadId]);
            if ($upload === null || ($upload['status'] === 'active' && $live > 0)) {
                // Drop the job so a later expiry can queue a fresh one.
                Database::execute('DELETE FROM deletion_jobs WHERE id = ?', [$job['id']]);
                return true;
            }

            $storage = StorageService::driver();
            $files = Database::select('SELECT id, object_key FROM upload_files WHERE upload_id = ? AND deleted_at IS NULL', [$uploadId]);
            foreach ($files as $file) {
                $storage->delete($file['object_key']);
                if ($storage->exists($file['object_key'])) {
                    throw new \RuntimeException('Object still present after delete: ' . $file['object_key']);
                }
                Database::update('upload_files', ['deleted_at' => db_time()], 'id = ?', [$file['id']]);
            }

            Database::transaction(function () use ($upload, $uploadId, $job) {
                $now = db_time();
                Database::execute(
                    "UPDATE share_links SET status = 'revoked', end_reason = 'purged', ended_at = COALESCE(ended_at, ?), updated_at = ?
                     WHERE upload_id = ? AND status IN ('active', 'paused')",
                    [$now, $now, $uploadId]
                );
                Database::update('uploads', [
                    'status' => 'deleted',
                    'deleted_at' => $now,
                    'delete_reason' => $upload['delete_reason'] ?? substr($job['reason'], 0, 32),
                    'storage_bytes' => 0,
                    'updated_at' => $now,
                ], 'id = ?', [$uploadId]);
                $this->finish((int) $job['id'], 'done', null);
            });

            // Deletion completion is recorded without retaining any content.
            AuditLogService::record('upload.purged', 'upload', $upload['uuid'], ['reason' => $job['reason'], 'objects' => count($files)], 'system');
            return true;
        } catch (Throwable $e) {
            $attempts = (int) $job['attempts'] + 1;
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $backoff = min(3600, 30 * (2 ** ($attempts - 1)));
            Database::update('deletion_jobs', [
                'status' => $status,
                'attempts' => $attempts,
                'last_error' => substr($e->getMessage(), 0, 2000),
                'run_after' => db_time(now()->modify("+{$backoff} seconds")),
                'locked_at' => null,
                'updated_at' => db_time(),
            ], 'id = ?', [$job['id']]);
            return false;
        }
    }

    private function finish(int $jobId, string $status, ?string $error): void
    {
        Database::update('deletion_jobs', [
            'status' => $status,
            'last_error' => $error,
            'locked_at' => null,
            'completed_at' => db_time(),
            'updated_at' => db_time(),
        ], 'id = ?', [$jobId]);
    }

    public function retry(int $jobId): void
    {
        Database::execute(
            "UPDATE deletion_jobs SET status = 'pending', attempts = 0, run_after = ?, locked_at = NULL, updated_at = ? WHERE id = ? AND status IN ('failed', 'pending')",
            [db_time(), db_time(), $jobId]
        );
    }
}
