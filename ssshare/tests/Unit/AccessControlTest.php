<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AccessPolicyService;
use App\Services\DeletionService;
use App\Services\ExpirationService;
use App\Services\SharePresenter;
use App\Services\ShareLinkService;
use App\Services\StorageService;
use App\Services\UploadService;
use App\Services\UrlSigningService;
use App\Support\Clock;
use App\Support\Database;
use Tests\TestCase;

/** Acceptance: passwords cannot be bypassed; owners can revoke instantly. */
final class AccessControlTest extends TestCase
{
    public function testPasswordIsHashedWithArgon2idAndNeverStoredRaw(): void
    {
        $result = (new UploadService())->createImage($this->png(), 'a.png', ['password' => 'hunter2-secret'], null);
        $link = $this->link($result);
        $this->assertTrue(str_starts_with($link['password_hash'], '$argon2id$'));
        $this->assertSame('password', $link['access_mode']);
        $dump = json_encode(Database::select('SELECT * FROM share_links WHERE id = ?', [$link['id']]));
        $this->assertNotContains('hunter2-secret', $dump);
    }

    public function testUnlockFlowAndBruteForceLockout(): void
    {
        $result = (new UploadService())->createText('secret', null, ['password' => 'right-password'], null);
        $link = $this->link($result);
        $this->assertFalse(AccessPolicyService::isUnlocked($link));
        $this->assertFalse(AccessPolicyService::unlock($link, 'wrong', $this->request()));
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM view_events WHERE share_link_id = ? AND outcome = 'password_failed'", [$link['id']]));
        $this->assertTrue(AccessPolicyService::unlock($link, 'right-password', $this->request()));
        $this->assertTrue(AccessPolicyService::isUnlocked($link));

        // Changing the password invalidates existing unlocks.
        (new ShareLinkService())->setPassword($link, 'new-password');
        $this->assertFalse(AccessPolicyService::isUnlocked($this->link($result)));

        $_SERVER['REMOTE_ADDR'] = '192.0.2.99';
        for ($i = 0; $i < 5; $i++) {
            AccessPolicyService::unlock($this->link($result), 'guess' . $i, $this->request());
        }
        $this->assertHttpError('rate_limited', fn () => AccessPolicyService::unlock($this->link($result), 'new-password', $this->request()), 429);
    }

    public function testNoDirectUrlForProtectedOrLimitedImages(): void
    {
        $uploads = new UploadService();
        $open = $uploads->createImage($this->png(), null, [], null);
        $this->assertTrue(SharePresenter::directUrl($this->link($open), $this->upload($open), $open['file']) !== null);

        foreach ([['password' => 'abcd1234'], ['burn_after_view' => '1'], ['view_limit' => 5], ['download_limit' => 3]] as $options) {
            $r = $uploads->createImage($this->png(), null, $options, null);
            $this->assertSame(null, SharePresenter::directUrl($this->link($r), $this->upload($r), $r['file']), 'No direct URL for ' . json_encode($options));
        }
    }

    public function testSignedUrlsExpireAndCannotBeTampered(): void
    {
        $url = UrlSigningService::rawUrl('abcDEF1234', 42, 'png', 'view', 60);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue(UrlSigningService::verify('abcDEF1234', 42, 'view', (int) $q['e'], $q['s']));
        $this->assertFalse(UrlSigningService::verify('abcDEF1234', 43, 'view', (int) $q['e'], $q['s']), 'Other file');
        $this->assertFalse(UrlSigningService::verify('zzzDEF1234', 42, 'view', (int) $q['e'], $q['s']), 'Other link');
        $this->assertFalse(UrlSigningService::verify('abcDEF1234', 42, 'owner', (int) $q['e'], $q['s']), 'Purpose escalation');
        $this->assertFalse(UrlSigningService::verify('abcDEF1234', 42, 'view', (int) $q['e'] + 1000, $q['s']), 'Extended expiry');
        Clock::travel(61);
        $this->assertFalse(UrlSigningService::verify('abcDEF1234', 42, 'view', (int) $q['e'], $q['s']), 'Expired');
    }

    public function testOwnerRevocationIsInstantAndDeletesContent(): void
    {
        $result = (new UploadService())->createImage($this->png(), null, ['expires_in' => '7d'], null);
        $key = $result['file']['object_key'];
        (new ShareLinkService())->revoke($this->link($result));

        $this->assertSame('revoked', $this->link($result)['status']);
        $this->assertFalse((new ExpirationService())->access((int) $result['link']['id'], 'view', $this->request())['allowed']);
        $this->assertSame('deleted', $this->upload($result)['status']);
        $this->assertFalse(StorageService::driver()->exists($key), 'Revocation also removes the stored object');
    }

    public function testDeleteNowRevokesAllLinks(): void
    {
        $user = $this->user();
        $uploads = new UploadService();
        $result = $uploads->createText('owned', null, [], $user);
        $extra = Database::transaction(fn () => $uploads->createLink((int) $result['upload']['id'], (new ExpirationService())->buildPolicy([], true), []));
        (new DeletionService())->deleteNow($this->upload($result), 'owner');
        $statuses = Database::select('SELECT status FROM share_links WHERE upload_id = ?', [$result['upload']['id']]);
        $this->assertSame(['revoked', 'revoked'], array_column($statuses, 'status'));
        $this->assertSame('deleted', $this->upload($result)['status']);
        $this->assertTrue($extra > 0);
    }

    public function testRevokingOneOfTwoLinksKeepsContent(): void
    {
        $uploads = new UploadService();
        $result = $uploads->createText('two links', null, [], $this->user());
        Database::transaction(fn () => $uploads->createLink((int) $result['upload']['id'], (new ExpirationService())->buildPolicy([], true), []));
        (new ShareLinkService())->revoke($this->link($result));
        $this->assertSame('active', $this->upload($result)['status']);
        $this->assertTrue(StorageService::driver()->exists($result['file']['object_key']));
    }
}
