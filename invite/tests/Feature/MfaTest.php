<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\TotpService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class MfaTest extends TestCase
{
    public function testTotpMatchesRfc6238AndBase32RoundTrips(): void
    {
        $secret = '12345678901234567890'; // RFC 6238 test secret
        $this->assertSame('287082', TotpService::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', TotpService::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', TotpService::code($secret, intdiv(1111111111, 30)));
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', TotpService::base32Encode($secret));
        $this->assertSame($secret, TotpService::base32Decode('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));
        $b = random_bytes(20);
        $this->assertSame($b, TotpService::base32Decode(TotpService::base32Encode($b)));
    }

    private function enable(Http $h): array
    {
        $page = $h->get('/app/settings/mfa');
        $this->assertStatus(200, $page);
        $this->assertContains('<svg', $page->body, 'QR code for the authenticator');
        preg_match('#<code>([A-Z2-7 ]+)</code>#', $page->body, $m);
        $secretB32 = str_replace(' ', '', $m[1]);
        $secret = TotpService::base32Decode($secretB32);
        $r = $h->post('/app/settings/mfa', ['_csrf' => $page->csrf(), 'code' => TotpService::code($secret, intdiv(time(), 30))]);
        $this->assertStatus(200, $r);
        preg_match_all('#<code>([0-9a-f]{5}-[0-9a-f]{5})</code>#', $r->body, $codes);
        $this->assertSame(8, count($codes[1]), 'eight recovery codes shown once');
        return [$secret, $codes[1]];
    }

    public function testEnrolmentSignInChallengeReplayAndRecoveryCodes(): void
    {
        [$host, $email] = $this->newHost();
        $userId = (int) Db::value('SELECT id FROM users WHERE email = ?', [$email]);
        [$secret, $recovery] = $this->enable($host);
        $this->assertTrue($this->totpEnabled($userId));
        $stored = Db::value('SELECT secret_enc FROM user_mfa WHERE user_id = ?', [$userId]);
        $this->assertNotContains(TotpService::base32Encode($secret), (string) $stored, 'secret is encrypted at rest');
        $this->assertSame(8, count(json_decode((string) Db::value('SELECT recovery_hashes FROM user_mfa WHERE user_id = ?', [$userId]), true)));
        $this->assertNotContains($recovery[0], (string) Db::value('SELECT recovery_hashes FROM user_mfa WHERE user_id = ?', [$userId]), 'recovery codes are hashed');

        // Password alone no longer signs in.
        $b = new Http();
        $r = $b->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $this->assertRedirect('/login/mfa', $r);
        $this->assertRedirect('/login', $b->get('/app'), 'not signed in yet');
        $page = $b->get('/login/mfa');
        $bad = $b->post('/login/mfa', ['_csrf' => $page->csrf(), 'code' => '000000']);
        $this->assertRedirect('/login/mfa', $bad);
        $this->assertContains('not valid', $b->follow()->body);
        // The code consumed at enrolment cannot be replayed; the next step's code works (clock-skew window).
        $replay = $b->post('/login/mfa', ['_csrf' => $b->csrf(), 'code' => TotpService::code($secret, intdiv(time(), 30))]);
        $this->assertRedirect('/login/mfa', $replay);
        $ok = $b->post('/login/mfa', ['_csrf' => $b->csrf(), 'code' => TotpService::code($secret, intdiv(time(), 30) + 1)]);
        $this->assertRedirect('/app', $ok);
        $this->assertStatus(200, $b->get('/app'));

        // A recovery code works exactly once.
        $c = new Http();
        $c->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $p = $c->get('/login/mfa');
        $this->assertRedirect('/app', $c->post('/login/mfa', ['_csrf' => $p->csrf(), 'code' => $recovery[0]]));
        $d = new Http();
        $d->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $p = $d->get('/login/mfa');
        $this->assertRedirect('/login/mfa', $d->post('/login/mfa', ['_csrf' => $p->csrf(), 'code' => $recovery[0]]), 'used code is dead');
        $this->assertRedirect('/app', $d->post('/login/mfa', ['_csrf' => $d->csrf(), 'code' => $recovery[1]]));
        $this->assertSame(6, (int) $this->recoveryLeft($userId));
    }

    private function totpEnabled(int $id): bool
    {
        return (bool) Db::value('SELECT 1 FROM user_mfa WHERE user_id = ? AND enabled_at IS NOT NULL', [$id]);
    }

    private function recoveryLeft(int $id): int
    {
        return count(json_decode((string) Db::value('SELECT recovery_hashes FROM user_mfa WHERE user_id = ?', [$id]), true));
    }

    public function testChallengeIsRateLimitedAndManagementNeedsPasswordAndCode(): void
    {
        [$host, $email] = $this->newHost();
        $userId = (int) Db::value('SELECT id FROM users WHERE email = ?', [$email]);
        [$secret] = $this->enable($host);
        $b = new Http();
        $b->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $p = $b->get('/login/mfa');
        $last = 0;
        for ($i = 0; $i < 10; $i++) {
            $last = $b->post('/login/mfa', ['_csrf' => $p->csrf(), 'code' => '1234' . $i . $i])->status;
        }
        $this->assertSame(429, $last, 'guessing codes is throttled');
        Db::exec('DELETE FROM rate_limits');

        // Turning it off needs both the password and a valid code.
        $s = $host->get('/app/settings');
        $this->assertContains('Two-step verification', $s->body);
        $r = $host->post('/app/settings/mfa/disable', ['_csrf' => $s->csrf(), 'password' => 'wrong password', 'code' => TotpService::code($secret, intdiv(time(), 30) + 1)]);
        $this->assertRedirect('/app/settings', $r);
        $this->assertTrue($this->totpEnabled($userId), 'wrong password: still on');
        $r = $host->post('/app/settings/mfa/disable', ['_csrf' => $s->csrf(), 'password' => 'correct horse battery', 'code' => '999999']);
        $this->assertTrue($this->totpEnabled($userId), 'wrong code: still on');
        $r = $host->post('/app/settings/mfa/disable', ['_csrf' => $s->csrf(), 'password' => 'correct horse battery', 'code' => TotpService::code($secret, intdiv(time(), 30) + 1)]);
        $this->assertRedirect('/app/settings', $r);
        $this->assertSame(false, $this->totpEnabled($userId));
        $x = new Http();
        $this->assertRedirect('/app', $x->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']), 'back to password-only');
        $this->assertTrue((int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = ? AND action IN ('mfa.enabled','mfa.disabled')", [$userId]) === 2);
    }
}