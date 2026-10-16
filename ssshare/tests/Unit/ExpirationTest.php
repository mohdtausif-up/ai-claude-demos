<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\CleanupJob;
use App\Services\ExpirationService;
use App\Services\ShareLinkService;
use App\Services\StorageService;
use App\Services\UploadService;
use App\Support\Clock;
use App\Support\Database;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/** Acceptance: minute-level expiry; expired content inaccessible and physically deleted. */
final class ExpirationTest extends TestCase
{
    private ExpirationService $expiration;
    private UploadService $uploads;

    public function setUp(): void
    {
        parent::setUp();
        $this->expiration = new ExpirationService();
        $this->uploads = new UploadService();
    }

    private function text(array $options = [], ?array $user = null): array
    {
        return $this->uploads->createText("hello world\nline two", 'plaintext', $options, $user);
    }

    public function testOneMinuteIsTheShortestPreset(): void
    {
        Clock::freeze(new DateTimeImmutable('2026-10-06 12:00:00', new DateTimeZone('UTC')));
        $policy = $this->expiration->buildPolicy(['expires_in' => '1m'], false);
        $this->assertSame('2026-10-06 12:01:00', db_time($policy['expires_at']));
        $this->assertHttpError('validation_failed', fn () => $this->expiration->buildPolicy(['expires_in' => '30'], false));
    }

    public function testGuestLimitsAndMemberNever(): void
    {
        $this->assertHttpError('validation_failed', fn () => $this->expiration->buildPolicy(['expires_in' => 'never'], false));
        $this->assertSame(null, $this->expiration->buildPolicy(['expires_in' => 'never'], true)['expires_at']);
        $this->assertHttpError('validation_failed', fn () => $this->expiration->buildPolicy(['expires_at' => '+40 days'], false));
        $this->assertHttpError('validation_failed', fn () => $this->expiration->buildPolicy(['expires_at' => '-1 hour'], true));
        $this->assertHttpError('validation_failed', fn () => $this->expiration->buildPolicy(['view_limit' => 0], true));
        $this->assertHttpError('validation_failed', fn () => $this->expiration->buildPolicy(['view_limit' => 1000], true));
    }

    public function testHumanReadableSummary(): void
    {
        $policy = [
            'expires_at' => new DateTimeImmutable('2026-10-07 18:00:00', new DateTimeZone('UTC')),
            'burn_after_view' => false, 'view_limit' => 10, 'view_limit_type' => 'unique',
            'download_limit' => null, 'inactive_after_seconds' => null,
        ];
        $this->assertSame(
            'This image will be deleted after 10 unique views or at 6:00 PM on October 7, 2026 (UTC), whichever occurs first.',
            ExpirationService::summary($policy)
        );
        $policy['expires_at'] = null;
        $policy['view_limit'] = null;
        $policy['burn_after_view'] = true;
        $this->assertSame('This text will be deleted after it is viewed once.', ExpirationService::summary($policy, 'text'));
    }

    public function testTimeExpiryBlocksAccessAndPhysicallyDeletes(): void
    {
        $result = $this->text(['expires_in' => '1m']);
        $file = UploadService::primaryFile((int) $result['upload']['id']);
        $this->assertTrue(StorageService::driver()->exists($file['object_key']));
        $this->assertTrue($this->expiration->access((int) $result['link']['id'], 'view', $this->request())['allowed']);

        Clock::travel(61);
        $access = $this->expiration->access((int) $result['link']['id'], 'view', $this->request());
        $this->assertFalse($access['allowed'], 'Expired content is inaccessible');
        $this->assertSame('expired', $this->link($result)['status']);
        $job = Database::first('SELECT * FROM deletion_jobs WHERE upload_id = ?', [$result['upload']['id']]);
        $this->assertSame('pending', $job['status'], 'Deletion queued');

        $run = (new CleanupJob())->run();
        $this->assertTrue($run['purged'] >= 1);
        $this->assertFalse(StorageService::driver()->exists($file['object_key']), 'Object removed from storage');
        $upload = $this->upload($result);
        $this->assertSame('deleted', $upload['status']);
        $this->assertSame('done', Database::value('SELECT status FROM deletion_jobs WHERE upload_id = ?', [$result['upload']['id']]));
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'upload.purged' AND target_id = ?", [$upload['uuid']]));
    }

    public function testSweeperExpiresLinksNobodyVisits(): void
    {
        $result = $this->text(['expires_in' => '5m']);
        Clock::travel(301);
        (new CleanupJob())->run();
        $this->assertSame('expired', $this->link($result)['status']);
        $this->assertSame('deleted', $this->upload($result)['status']);
    }

    public function testUniqueViewLimitDeduplicatesRepeatViewers(): void
    {
        $result = $this->text(['view_limit' => 2]);
        $id = (int) $result['link']['id'];
        $alice = fn () => $this->request('Mozilla/5.0 Firefox/130.0', '198.51.100.1');
        $bob = fn () => $this->request('Mozilla/5.0 Firefox/130.0', '198.51.100.2');

        $this->assertTrue($this->expiration->access($id, 'view', $alice())['unique']);
        $repeat = $this->expiration->access($id, 'view', $alice());
        $this->assertTrue($repeat['allowed']);
        $this->assertFalse($repeat['unique'], 'Repeat view within 30 minutes is not unique');
        $this->assertSame('active', $this->link($result)['status']);

        $last = $this->expiration->access($id, 'view', $bob());
        $this->assertTrue($last['allowed'] && $last['ended'], 'Second unique viewer is the last');
        $this->assertSame('consumed', $this->link($result)['status']);
        $this->assertFalse($this->expiration->access($id, 'view', $alice())['allowed']);
    }

    public function testTotalViewLimit(): void
    {
        $result = $this->text(['view_limit' => 2, 'view_limit_type' => 'total']);
        $id = (int) $result['link']['id'];
        $this->expiration->access($id, 'view', $this->request());
        $this->assertTrue($this->expiration->access($id, 'view', $this->request())['ended']);
        $this->assertFalse($this->expiration->access($id, 'view', $this->request())['allowed']);
    }

    public function testBurnAfterViewAllowsExactlyOneView(): void
    {
        $result = $this->text(['burn_after_view' => '1']);
        $id = (int) $result['link']['id'];
        $first = $this->expiration->access($id, 'view', $this->request());
        $this->assertTrue($first['allowed'] && $first['ended']);
        $this->assertFalse($this->expiration->access($id, 'view', $this->request())['allowed']);
        $job = Database::first('SELECT * FROM deletion_jobs WHERE upload_id = ?', [$result['upload']['id']]);
        $this->assertSame('pending', $job['status']);
        $grace = strtotime($job['run_after'] . ' UTC') - now()->getTimestamp();
        $this->assertTrue($grace > 0 && $grace <= 120, 'Purged after a short grace window');
    }

    public function testDownloadLimit(): void
    {
        $result = $this->text(['download_limit' => 1]);
        $id = (int) $result['link']['id'];
        $this->assertTrue($this->expiration->access($id, 'view', $this->request())['allowed']);
        $this->assertTrue($this->expiration->access($id, 'download', $this->request())['ended']);
        $this->assertFalse($this->expiration->access($id, 'view', $this->request())['allowed']);
    }

    public function testInactivityExpiry(): void
    {
        $result = $this->text(['inactive_after' => '1h', 'expires_in' => '7d']);
        Clock::travel(1800);
        $this->expiration->access((int) $result['link']['id'], 'view', $this->request());
        Clock::travel(3000);
        $this->expiration->sweep();
        $this->assertSame('active', $this->link($result)['status'], 'Activity resets the inactivity timer');
        Clock::travel(700);
        $this->expiration->sweep();
        $this->assertSame('expired', $this->link($result)['status']);
        $this->assertSame('inactivity', $this->link($result)['end_reason']);
    }

    public function testCompoundRuleWhicheverComesFirst(): void
    {
        // 24 hours or 10 views: time wins here.
        $result = $this->text(['expires_in' => '1d', 'view_limit' => 10]);
        for ($i = 0; $i < 3; $i++) {
            $this->expiration->access((int) $result['link']['id'], 'view', $this->request('UA' . $i, '192.0.2.' . ($i + 1)));
        }
        Clock::travel(86401);
        $this->assertFalse($this->expiration->access((int) $result['link']['id'], 'view', $this->request())['allowed']);
        $this->assertSame('time', $this->link($result)['end_reason']);

        // Same rules: views win here.
        $other = $this->text(['expires_in' => '1d', 'view_limit' => 2, 'view_limit_type' => 'total']);
        $this->expiration->access((int) $other['link']['id'], 'view', $this->request());
        $this->expiration->access((int) $other['link']['id'], 'view', $this->request());
        $this->assertSame('view_limit', $this->link($other)['end_reason']);

        $rules = Database::select('SELECT rule_type FROM expiration_rules WHERE share_link_id = ? ORDER BY priority', [$result['link']['id']]);
        $this->assertSame(['expires_at', 'max_unique_views'], array_column($rules, 'rule_type'));
    }

    public function testPauseResumeAndExtend(): void
    {
        $result = $this->text(['expires_in' => '10m']);
        $links = new ShareLinkService();
        $links->pause($this->link($result));
        $this->assertSame('paused', $this->expiration->access((int) $result['link']['id'], 'view', $this->request())['reason']);
        $links->resume($this->link($result));
        $this->assertTrue($this->expiration->access((int) $result['link']['id'], 'view', $this->request())['allowed']);

        $links->updateExpiry($this->link($result), ['expires_in' => '1h'], false);
        Clock::travel(1200);
        $this->assertTrue($this->expiration->access((int) $result['link']['id'], 'view', $this->request())['allowed'], 'Extended expiry applies');

        $links->pause($this->link($result));
        Clock::travel(3600);
        $this->assertHttpError('expired', fn () => $links->resume($this->link($result)), 409);
    }
}
