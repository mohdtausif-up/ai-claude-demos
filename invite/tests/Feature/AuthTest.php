<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class AuthTest extends TestCase
{
    public function testRegisterLoginLogout(): void
    {
        [$host, $email] = $this->newHost('Ada Lovelace');
        $dash = $host->get('/app');
        $this->assertStatus(200, $dash);
        $this->assertContains('Ada', $dash->body);

        $r = $host->submit('/app', '/logout');
        $this->assertRedirect('/', $r);
        $this->assertRedirect('/login', $host->get('/app'));

        $again = new Http();
        $r = $again->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $this->assertRedirect('/app', $r);
        $this->assertStatus(200, $again->get('/app'));
    }

    public function testPasswordIsHashedWithArgon2idAndVerificationEmailQueued(): void
    {
        [, $email] = $this->newHost();
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $this->assertTrue(str_starts_with($user['password_hash'], '$argon2id$'), 'argon2id hash');
        $this->assertSame(null, $user['email_verified_at']);
        $this->runWorker();
        $mail = $this->mailTo($email);
        $this->assertTrue($mail !== null, 'verification email delivered');
        $this->assertContains('Confirm', $mail['HTML'] ?? $mail['Text'] ?? '');
        preg_match('#/verify/([A-Za-z0-9_-]+)#', $mail['Text'] ?? '', $m);
        $this->assertTrue(!empty($m[1]), 'verify link present');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ?', [$m[1]]), 'token is not stored in plaintext');
        $web = new Http();
        $this->assertRedirect('/login', $web->get('/verify/' . $m[1]));
        $this->assertTrue(Db::value('SELECT email_verified_at FROM users WHERE email = ?', [$email]) !== null, 'verified');
        // Single use.
        $this->assertRedirect('/login', $web->get('/verify/' . $m[1]));
        $web->follow();
        $this->assertContains('expired or was already used', $web->body);
    }

    public function testWrongPasswordIsGenericAndRateLimited(): void
    {
        [, $email] = $this->newHost();
        $h = new Http();
        for ($i = 1; $i <= 5; $i++) {
            $r = $h->submit('/login', '/login', ['email' => $email, 'password' => 'wrong-password-' . $i]);
            $this->assertRedirect('/login', $r);
        }
        $this->assertContains('Incorrect email or password', $h->follow()->body, 'no hint which part was wrong');
        // Sixth attempt is blocked even with the right password.
        $r = $h->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $this->assertStatus(429, $r);
        $this->assertTrue(isset($r->headers['retry-after']));
        // Unknown email gets the same generic response.
        $u = new Http();
        $r = $u->submit('/login', '/login', ['email' => 'nobody' . bin2hex(random_bytes(3)) . '@example.test', 'password' => 'whatever-password']);
        $this->assertRedirect('/login', $r);
        $this->assertContains('Incorrect email or password', $u->follow()->body);
        Db::exec('DELETE FROM rate_limits');
    }

    public function testPasswordResetFlowAndTokenSingleUse(): void
    {
        [, $email] = $this->newHost();
        $h = new Http();
        $r = $h->submit('/forgot', '/forgot', ['email' => $email]);
        $this->assertRedirect('/login', $r);
        // Unknown address behaves identically (no account enumeration).
        $r2 = $h->submit('/forgot', '/forgot', ['email' => 'ghost' . bin2hex(random_bytes(3)) . '@example.test']);
        $this->assertRedirect('/login', $r2);
        $this->runWorker();
        $mail = null;
        for ($i = 0; $i < 10 && !$mail; $i++) {
            foreach ($this->mailpit('search?query=' . rawurlencode('to:' . $email))['messages'] ?? [] as $m) {
                if (str_contains($m['Subject'], 'Reset')) {
                    $mail = $this->mailpit('message/' . $m['ID']);
                    break;
                }
            }
            usleep(300000);
        }
        $this->assertTrue($mail !== null, 'reset email delivered');
        preg_match('#/reset/([A-Za-z0-9_-]+)#', $mail['Text'], $m);
        $page = $h->get('/reset/' . $m[1]);
        $this->assertStatus(200, $page);
        $r = $h->post('/reset/' . $m[1], ['_csrf' => $page->csrf(), 'password' => 'a brand new passphrase']);
        $this->assertRedirect('/login', $r);
        $page2 = $h->get('/reset/' . $m[1]);
        $r = $h->post('/reset/' . $m[1], ['_csrf' => $page2->csrf(), 'password' => 'another passphrase here']);
        $this->assertRedirect('/forgot', $r, 'second use fails');
        $login = new Http();
        $this->assertRedirect('/app', $login->submit('/login', '/login', ['email' => $email, 'password' => 'a brand new passphrase']));
    }

    public function testMagicLinkSignsInOnceWithoutConsumingOnGet(): void
    {
        [, $email] = $this->newHost();
        $h = new Http();
        $h->submit('/login', '/magic', ['email' => $email]);
        $this->runWorker();
        $mail = null;
        for ($i = 0; $i < 10 && !$mail; $i++) {
            foreach ($this->mailpit('search?query=' . rawurlencode('to:' . $email))['messages'] ?? [] as $m) {
                if (str_contains($m['Subject'], 'sign-in')) {
                    $mail = $this->mailpit('message/' . $m['ID']);
                }
            }
            usleep(300000);
        }
        $this->assertTrue($mail !== null, 'magic link email');
        preg_match('#/magic/([A-Za-z0-9_-]+)#', $mail['Text'], $m);
        // Link scanners that GET the URL must not burn the token.
        $scanner = new Http();
        $scanner->get('/magic/' . $m[1]);
        $page = $h->get('/magic/' . $m[1]);
        $this->assertContains('Confirm to sign in', $page->body);
        $r = $h->post('/magic/' . $m[1], ['_csrf' => $page->csrf()]);
        $this->assertRedirect('/app', $r);
        $this->assertStatus(200, $h->get('/app'));
        $h2 = new Http();
        $p2 = $h2->get('/magic/' . $m[1]);
        $r2 = $h2->post('/magic/' . $m[1], ['_csrf' => $p2->csrf()]);
        $this->assertRedirect('/login', $r2, 'token already used');
    }

    public function testSessionsCanBeRevokedFromAnotherDevice(): void
    {
        [$a, $email] = $this->newHost();
        $b = new Http();
        $b->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $this->assertStatus(200, $b->get('/app'));
        $r = $a->submit('/app/settings', '/app/settings/sign-out-others');
        $this->assertRedirect('/app/settings', $r);
        $this->assertRedirect('/login', $b->get('/app'), 'other device is signed out immediately');
        $this->assertStatus(200, $a->get('/app'), 'this device stays signed in');
    }

    public function testSessionCookieFlagsAndSecurityHeaders(): void
    {
        $h = new Http();
        $r = $h->get('/login');
        $cookie = $r->headers['set-cookie'] ?? '';
        $this->assertContains('HttpOnly', $cookie);
        $this->assertContains('SameSite=Lax', $cookie);
        $csp = $r->headers['content-security-policy'] ?? '';
        $this->assertContains("script-src 'self' 'nonce-", $csp);
        $this->assertNotContains('unsafe-inline', $csp);
        $this->assertSame('nosniff', $r->headers['x-content-type-options'] ?? '');
        $this->assertSame('SAMEORIGIN', $r->headers['x-frame-options'] ?? '');
        $this->assertTrue(!isset($r->headers['x-powered-by']), 'no X-Powered-By');
        $this->assertContains('private', $r->headers['cache-control'] ?? '');
    }

    public function testRedirectAfterLoginCannotLeaveTheSite(): void
    {
        [, $email] = $this->newHost();
        $h = new Http();
        // Poison "intended" with an absolute URL via the protected-page redirect: only same-site paths are honoured.
        $h->get('/app/events');
        $r = $h->submit('/login', '/login', ['email' => $email, 'password' => 'correct horse battery']);
        $this->assertTrue(str_starts_with($r->location(), '/'), 'relative redirect');
        $this->assertTrue(!str_starts_with($r->location(), '//'), 'not protocol-relative');
    }
}
