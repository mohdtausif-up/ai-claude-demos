<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DeletionService;
use App\Services\ShareLinkService;
use App\Services\Storage\LocalStorage;
use App\Services\Storage\StorageDriver;
use App\Services\StorageService;
use App\Services\UploadService;
use App\Support\Clock;
use App\Support\Database;
use RuntimeException;
use Tests\TestCase;

/** Acceptance: deletion is idempotent, retried, and failures are visible to admins. */
final class DeletionTest extends TestCase
{
    public function testDeletionIsIdempotent(): void
    {
        $result = (new UploadService())->createImage($this->png(), null, [], null);
        $deletion = new DeletionService();
        $deletion->deleteNow($this->upload($result), 'owner');
        $this->assertSame('deleted', $this->upload($result)['status']);

        // Re-queue and re-run: objects are already gone, the job must still succeed.
        Database::execute("UPDATE deletion_jobs SET status = 'pending', run_after = ? WHERE upload_id = ?", [db_time(), $result['upload']['id']]);
        [$done, $failed] = $deletion->runDue();
        $this->assertSame([1, 0], [$done, $failed]);
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM upload_files WHERE upload_id = ? AND deleted_at IS NULL', [$result['upload']['id']]));
        $this->assertSame(0, (int) $this->upload($result)['storage_bytes'], 'Quota is released');
    }

    public function testStorageFailuresRetryWithBackoffThenSurface(): void
    {
        $result = (new UploadService())->createImage($this->png(), null, [], null);
        StorageService::use(new class (config('app.storage.local_path')) implements StorageDriver {
            private LocalStorage $inner;

            public function __construct(string $root) { $this->inner = new LocalStorage($root); }
            public function put(string $key, string $contents): void { $this->inner->put($key, $contents); }
            public function get(string $key): string { return $this->inner->get($key); }
            public function stream(string $key): void { $this->inner->stream($key); }
            public function streamRange(string $key, int $s, int $e): void { $this->inner->streamRange($key, $s, $e); }
            public function size(string $key): int { return $this->inner->size($key); }
            public function exists(string $key): bool { return $this->inner->exists($key); }
            public function delete(string $key): void { throw new RuntimeException('S3 unavailable'); }
        });

        $deletion = new DeletionService();
        (new ShareLinkService())->revoke($this->link($result));
        $job = Database::first('SELECT * FROM deletion_jobs WHERE upload_id = ?', [$result['upload']['id']]);
        $this->assertSame('pending', $job['status']);
        $this->assertSame(1, (int) $job['attempts']);
        $this->assertContains('S3 unavailable', (string) $job['last_error']);
        $this->assertTrue(strtotime($job['run_after'] . ' UTC') > now()->getTimestamp(), 'Backoff scheduled');

        for ($i = 0; $i < DeletionService::MAX_ATTEMPTS; $i++) {
            Clock::travel(3601);
            $deletion->runDue();
        }
        $job = Database::first('SELECT * FROM deletion_jobs WHERE upload_id = ?', [$result['upload']['id']]);
        $this->assertSame('failed', $job['status'], 'Gives up after max attempts');
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM deletion_jobs WHERE status = 'failed' AND upload_id = ?", [$result['upload']['id']]), 'Visible to admin queue');
        $this->assertSame('revoked', $this->upload($result)['status'], 'Still inaccessible while purge is pending');

        // Storage recovers; an admin retry completes the deletion.
        StorageService::use(null);
        $deletion->retry((int) $job['id']);
        $deletion->runDue();
        $this->assertSame('deleted', $this->upload($result)['status']);
        $this->assertFalse(StorageService::driver()->exists($result['file']['object_key']));
    }

    public function testCleanupReportsOverdueWork(): void
    {
        $result = (new UploadService())->createText('x', null, [], null);
        (new DeletionService())->queue((int) $result['upload']['id'], 'test');
        Database::execute("UPDATE deletion_jobs SET status = 'pending', run_after = ? WHERE upload_id = ?", [db_time(now()->modify('-10 minutes')), $result['upload']['id']]);
        // Simulate a worker that died while holding the job.
        Database::execute("UPDATE deletion_jobs SET status = 'running', locked_at = ? WHERE upload_id = ?", [db_time(now()->modify('-20 minutes')), $result['upload']['id']]);
        $run = (new \App\Jobs\CleanupJob())->run();
        $this->assertTrue($run['purged'] >= 1, 'Stale running jobs are recovered');
        $this->assertTrue((bool) Database::value("SELECT last_run_at FROM scheduler_heartbeats WHERE task = 'cleanup'"), 'Heartbeat recorded');
    }
}
