<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuditLogService;
use App\Services\ModerationService;
use App\Services\UploadService;
use App\Support\Auth;
use App\Support\Database;
use PDOException;
use Tests\TestCase;

/** Acceptance: workspace/admin actions produce audit records; abuse workflow. */
final class AuditModerationTest extends TestCase
{
    public function testAuditLogIsAppendOnly(): void
    {
        AuditLogService::record('test.event', 'thing', 'abc', ['k' => 'v']);
        $id = (int) Database::value("SELECT MAX(id) FROM audit_logs WHERE action = 'test.event'");
        $this->assertTrue($id > 0);
        $e = $this->assertThrows(fn () => Database::execute("UPDATE audit_logs SET action = 'tampered' WHERE id = ?", [$id]));
        $this->assertTrue($e instanceof PDOException);
        $this->assertContains('append-only', $e->getMessage());
        $this->assertThrows(fn () => Database::execute('DELETE FROM audit_logs WHERE id = ?', [$id]));
        $this->assertSame('test.event', Database::value('SELECT action FROM audit_logs WHERE id = ?', [$id]));
    }

    public function testUploadsAreAudited(): void
    {
        $result = (new UploadService())->createText('audited', null, ['password' => 'abcd1234'], null);
        $row = Database::first("SELECT * FROM audit_logs WHERE action = 'upload.created' AND target_id = ?", [$result['upload']['uuid']]);
        $this->assertSame('guest', $row['actor_type']);
        $this->assertSame(64, strlen((string) $row['ip_hash']), 'IP stored only as a hash');
        $this->assertNotContains($_SERVER['REMOTE_ADDR'], json_encode($row));
        $this->assertTrue(json_decode($row['metadata'], true)['password']);
    }

    public function testReportTakedownBlocksHashAndAudits(): void
    {
        $png = $this->png(33, 33);
        $result = (new UploadService())->createImage($png, null, [], null);
        $reportId = (new ModerationService())->report($this->link($result), 'malware', 'Phishing page screenshot', null);
        $report = Database::first('SELECT * FROM abuse_reports WHERE public_id = ?', [$reportId]);
        $this->assertSame('open', $report['status']);

        $admin = $this->user('admin');
        Auth::setUser($admin);
        (new ModerationService())->takedown($report, $admin, true, 'Confirmed phishing');

        $this->assertSame('actioned', Database::value('SELECT status FROM abuse_reports WHERE id = ?', [$report['id']]));
        $this->assertSame('deleted', $this->upload($result)['status']);
        $this->assertSame('takedown', $this->upload($result)['delete_reason']);
        $audit = Database::first("SELECT * FROM audit_logs WHERE action = 'admin.report.takedown' AND target_id = ?", [$reportId]);
        $this->assertSame('admin', $audit['actor_type']);
        $this->assertSame((int) $admin['id'], (int) $audit['actor_user_id']);

        // The same bytes cannot be uploaded again.
        $this->assertHttpError('content_blocked', fn () => (new UploadService())->createImage($png, null, [], null), 422);
    }

    public function testReportValidationAndRateLimit(): void
    {
        $result = (new UploadService())->createText('report me', null, [], null);
        $this->assertHttpError('validation_failed', fn () => (new ModerationService())->report($this->link($result), 'nope', null, null));
        // The rejected report above already used one of the 10 hourly reports.
        for ($i = 0; $i < 9; $i++) {
            (new ModerationService())->report($this->link($result), 'spam', null, null);
        }
        $this->assertHttpError('rate_limited', fn () => (new ModerationService())->report($this->link($result), 'spam', null, null), 429);
    }
}
