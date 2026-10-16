<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AccountService;
use App\Services\CollectionService;
use App\Services\DeletionService;
use App\Services\ExpirationService;
use App\Services\MailService;
use App\Services\OcrService;
use App\Services\ResumableUploadService;
use App\Services\WebhookService;
use App\Support\Database;
use Throwable;

/**
 * One scheduler pass: end expired links and collections, purge due content, deliver webhooks
 * and email, index OCR text, build data exports, prune short-retention data, and verify that
 * nothing is overdue.
 */
final class CleanupJob
{
    public function run(): array
    {
        $ended = (new ExpirationService())->sweep();
        [$purged, $failed] = (new DeletionService())->runDue();

        $background = [];
        foreach ([
            'collections' => static fn () => (new CollectionService())->sweep(),
            'webhooks' => static fn () => (new WebhookService())->deliverDue()[0],
            'mail' => static fn () => MailService::deliver()[0],
            'ocr' => static fn () => (new OcrService())->indexPending(),
            'exports' => static fn () => (new AccountService())->buildPending(),
            'resumable' => static fn () => (new ResumableUploadService())->sweep(),
        ] as $task => $fn) {
            try {
                $background[$task] = $fn();
            } catch (Throwable $e) {
                $background[$task] = 'error';
                error_log("Background task $task failed: " . $e->getMessage());
            }
        }

        $now = db_time();
        // Operational data is kept only as long as it is needed.
        Database::execute('DELETE FROM rate_limits WHERE window_start < ? - INTERVAL 1 DAY', [$now]);
        Database::execute('DELETE FROM password_attempts WHERE created_at < ? - INTERVAL 1 DAY', [$now]);
        Database::execute('DELETE FROM idempotency_keys WHERE created_at < ? - INTERVAL 1 DAY', [$now]);
        Database::execute('DELETE FROM access_codes WHERE created_at < ? - INTERVAL 1 DAY', [$now]);
        Database::execute('DELETE FROM link_sessions WHERE last_seen_at < ? - INTERVAL 1 DAY', [$now]);
        Database::execute("DELETE FROM webhook_deliveries WHERE created_at < ? - INTERVAL 30 DAY AND status <> 'pending'", [$now]);
        Database::execute("DELETE FROM mail_outbox WHERE created_at < ? - INTERVAL 30 DAY AND status = 'sent'", [$now]);
        // Launch metrics need about a year of history; attempts carry no content.
        Database::execute('DELETE FROM upload_attempts WHERE created_at < ? - INTERVAL 400 DAY', [$now]);
        // Backups hold content that may since have expired, so they rotate quickly.
        $keep = max(1, (int) (getenv('BACKUP_KEEP_DAYS') ?: 7)) * 86400;
        foreach (glob(base_path('storage/backups/ssshare-*.sssbk')) ?: [] as $backup) {
            if (filemtime($backup) < time() - $keep) {
                @unlink($backup);
            }
        }

        // Automated expiration verification: anything here means deletion is falling behind.
        $overdueLinks = (int) Database::value(
            "SELECT COUNT(*) FROM share_links WHERE status IN ('active', 'paused') AND expires_at < ? - INTERVAL 5 MINUTE",
            [$now]
        );
        $overdueJobs = (int) Database::value(
            "SELECT COUNT(*) FROM deletion_jobs WHERE status = 'pending' AND run_after < ? - INTERVAL 5 MINUTE",
            [$now]
        );

        $result = compact('ended', 'purged', 'failed', 'overdueLinks', 'overdueJobs') + ['background' => $background];
        Database::execute(
            'INSERT INTO scheduler_heartbeats (task, last_run_at, last_result) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), last_result = VALUES(last_result)',
            ['cleanup', $now, substr(json_encode($result), 0, 255)]
        );
        return $result;
    }
}
