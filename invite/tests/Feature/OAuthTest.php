<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use Tests\Support\Http;
use Tests\Support\TestCase;

/** The real OAuth client against the app's own dev identity provider (OAUTH_MOCK=1). */
final class OAuthTest extends TestCase
{
    /** Walk the whole flow in a browser-like session. @return array{Http,Http} [browser, final response] */
    private function flow(string $email, array $opts = []): Http
    {
        $h = new Http();
        $login = $h->get('/login');
        $this->assertContains('Continue with Dev login', $login->body);
        $r = $h->get('/auth/mock');
        $this->assertStatus(303, $r);
        $authUrl = $r->location();
        $this->assertContains('code_challenge_method=S256', $authUrl);
        $this->assertContains('state=', $authUrl);
        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $q);
        $form = $h->get('/_mock-oauth/authorize?' . http_build_query($q));
        $this->assertStatus(200, $form);
        $post = $h->post('/_mock-oauth/approve', ['_csrf' => $form->csrf(), 'state' => $opts['state'] ?? $q['state'], 'redirect_uri' => $q['redirect_uri'], 'code_challenge' => $opts['challenge'] ?? $q['code_challenge'], 'email' => $email, 'name' => 'Olivia OAuth'] + (($opts['verified'] ?? true) ? ['verified' => '1'] : []));
        $this->assertStatus(303, $post);
        $cb = parse_url($post->location());
        return $h->get($cb['path'] . '?' . $cb['query']);
    }

    public function testNewUserIsCreatedVerifiedWithAWorkspaceAndCanSignInAgain(): void
    {
        $email = 'oauth' . bin2hex(random_bytes(4)) . '@example.test';
        $r = $this->flow($email);
        $this->assertRedirect('/app', $r);
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $this->assertTrue($user['email_verified_at'] !== null, 'provider-verified email');
        $this->assertSame(null, $user['password_hash']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM workspace_members WHERE user_id = ? AND role = ?', [$user['id'], 'owner']));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM oauth_identities WHERE user_id = ?', [$user['id']]));
        // Signing in again with the same identity reuses the account.
        $this->assertRedirect('/app', $this->flow($email));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM oauth_identities WHERE user_id = ?', [$user['id']]));
    }

    public function testExistingAccountsAreLinkedOnlyWhenTheProviderVerifiedTheEmail(): void
    {
        [, $email] = $this->newHost('Existing Eve');
        $id = (int) Db::value('SELECT id FROM users WHERE email = ?', [$email]);
        // Unverified provider email: refused (account-takeover protection).
        $r = $this->flow($email, ['verified' => false]);
        $this->assertRedirect('/login', $r);
        $this->assertContains('already exists', $r->follow()->body);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM oauth_identities WHERE user_id = ?', [$id]));
        // Verified: linked, and the user is signed into the existing account.
        $r = $this->flow($email);
        $this->assertRedirect('/app', $r);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM oauth_identities WHERE user_id = ?', [$id]));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]));
        $this->assertTrue(Db::value('SELECT email_verified_at FROM users WHERE id = ?', [$id]) !== null);
    }

    public function testUnverifiedProviderEmailCreatesAnUnverifiedAccountThatMustConfirm(): void
    {
        $email = 'unv' . bin2hex(random_bytes(4)) . '@example.test';
        $this->assertRedirect('/app', $this->flow($email, ['verified' => false]));
        $this->assertSame(null, Db::value('SELECT email_verified_at FROM users WHERE email = ?', [$email]));
        $this->runWorker();
        $this->assertTrue($this->mailTo($email) !== null, 'verification email sent');
    }

    public function testStateAndPkceAreEnforcedAndSuspendedUsersAreBlocked(): void
    {
        $email = 'bad' . bin2hex(random_bytes(4)) . '@example.test';
        // Wrong state (CSRF on the callback) is rejected.
        $r = $this->flow($email, ['state' => 'forged-state']);
        $this->assertRedirect('/login', $r);
        $this->assertContains('expired or was not started here', $r->follow()->body);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]));
        // A tampered PKCE challenge means the verifier no longer matches: the token exchange fails.
        $r = $this->flow($email, ['challenge' => base64url(hash('sha256', 'attacker', true))]);
        $this->assertRedirect('/login', $r);
        $this->assertContains('did not accept', $r->follow()->body);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]));
        // A callback with no session state at all.
        $this->assertRedirect('/login', (new Http())->get('/auth/mock/callback?code=abc&state=xyz'));
        // Suspended accounts cannot sign in this way.
        $ok = 'sus' . bin2hex(random_bytes(4)) . '@example.test';
        $this->flow($ok);
        Db::exec("UPDATE users SET status = 'suspended' WHERE email = ?", [$ok]);
        $r = $this->flow($ok);
        $this->assertRedirect('/login', $r);
        $this->assertContains('suspended', $r->follow()->body);
    }

    public function testTwoStepVerificationStillAppliesToOAuthSignIn(): void
    {
        $email = 'mfa' . bin2hex(random_bytes(4)) . '@example.test';
        $this->flow($email);
        $uid = (int) Db::value('SELECT id FROM users WHERE email = ?', [$email]);
        Db::insert('user_mfa', ['user_id' => $uid, 'secret_enc' => 'x', 'enabled_at' => utcnow()]);
        $r = $this->flow($email);
        $this->assertRedirect('/login/mfa', $r);
        $this->assertRedirect('/login', $r->get('/app'), 'not signed in until the second step');
    }

    public function testProviderEndpointsAreNotAvailableWhenNotConfigured(): void
    {
        $h = new Http();
        $this->assertStatus(404, $h->get('/auth/google'), 'no client id configured');
        $this->assertStatus(404, $h->get('/auth/microsoft/callback?code=x&state=y'));
        $this->assertStatus(404, $h->get('/auth/github'));
    }
}