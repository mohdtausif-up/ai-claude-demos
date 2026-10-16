<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\TestCase;

require_once __DIR__ . '/HttpClient.php';

/** End-to-end checks through Nginx and PHP-FPM against the running application. */
final class WebFlowTest extends TestCase
{
    private string $base;

    public function setUp(): void
    {
        parent::setUp();
        $this->base = rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/');
    }

    private function client(string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126.0 Safari/537.36'): HttpClient
    {
        return new HttpClient($this->base, $ua);
    }

    /** Signs in a fresh member (member uploads have a higher rate limit than guests). */
    private function member(): HttpClient
    {
        $c = $this->client();
        $token = $c->csrf('/register');
        $r = $c->post('/register', ['_csrf' => $token, 'display_name' => 'HTTP Test', 'email' => 'http' . bin2hex(random_bytes(5)) . '@example.test', 'password' => 'a very good password']);
        $this->assertSame(303, $r['status'], 'Registration redirects');
        return $c;
    }

    private function share(HttpClient $c, array $fields, bool $text = false): array
    {
        $token = $c->csrf('/');
        $r = $c->post($text ? '/paste' : '/upload', $fields, ['X-CSRF-Token' => $token, 'Accept' => 'application/json']);
        $this->assertSame(201, $r['status'], 'Upload created: ' . substr($r['body'], 0, 300));
        return json_decode($r['body'], true)['data'];
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function rawSrc(string $html): ?string
    {
        return preg_match('#src="(/r/[^"]+)"#', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    public function testGuestPastesScreenshotAndGetsALink(): void
    {
        $guest = $this->client();
        $data = $this->share($guest, ['file' => HttpClient::file($this->png(), 'image/png', 'screenshot.png'), 'expires_in' => '1m']);
        $this->assertContains('/s/', $data['share_url']);
        $this->assertContains('/m/', $data['manage_url']);
        $this->assertSame(null, $data['links'][0]['view_limit']);

        $viewer = $this->client('Mozilla/5.0 Firefox/131.0');
        $page = $viewer->get($this->path($data['share_url']));
        $this->assertSame(200, $page['status']);
        $raw = $viewer->get($this->rawSrc($page['body']));
        $this->assertSame(200, $raw['status']);
        $this->assertSame('image/png', $raw['headers']['content-type']);
        $this->assertContains('sandbox', $raw['headers']['content-security-policy']);
        $this->assertContains('/i/', (string) $data['direct_url']);
        $this->assertSame(200, $viewer->get($this->path($data['direct_url']))['status']);
    }

    public function testCsrfIsRequired(): void
    {
        $c = $this->client();
        $c->get('/');
        $r = $c->post('/paste', ['content' => 'x'], ['Accept' => 'application/json']);
        $this->assertSame(419, $r['status']);
        $this->assertSame('csrf_mismatch', json_decode($r['body'], true)['error']['code']);
    }

    public function testPasswordProtectedMediaHasNoAlternateRoute(): void
    {
        $owner = $this->member();
        $data = $this->share($owner, ['file' => HttpClient::file($this->png(), 'image/png', 'secret.png'), 'password' => 'open-sesame']);
        $id = $data['link']['id'];
        $this->assertSame(null, $data['direct_url']);

        $stranger = $this->client('Mozilla/5.0 Safari/605.1.15');
        $page = $stranger->get("/s/$id");
        $this->assertSame(200, $page['status']);
        $this->assertContains('Password required', $page['body']);
        $this->assertSame(null, $this->rawSrc($page['body']), 'No media URL before unlocking');

        $this->assertSame(404, $stranger->get("/i/$id.png")['status'], 'No direct URL');
        $this->assertSame(403, $stranger->get("/r/$id.png")['status'], 'No unsigned raw URL');
        $this->assertSame(403, $stranger->get("/r/$id.png?f=1&e=" . (time() + 60) . '&p=view&s=forged')['status']);
        $this->assertSame(403, $stranger->get("/r/$id.png?f=1&e=" . (time() + 60) . '&p=owner&s=' . str_repeat('A', 43))['status']);
        // Guessing the owner preview URL with a different link ID fails the signature.
        parse_str((string) parse_url($data['preview_url'], PHP_URL_QUERY), $q);
        $this->assertSame(403, $stranger->get("/r/AAAAAAAAAA.png?" . http_build_query($q))['status']);

        $token = $stranger->csrf("/s/$id");
        $wrong = $stranger->post("/s/$id/unlock", ['_csrf' => $token, 'password' => 'nope']);
        $this->assertSame(303, $wrong['status']);
        $this->assertContains('That password is incorrect', $stranger->get("/s/$id")['body']);

        $token = $stranger->csrf("/s/$id");
        $stranger->post("/s/$id/unlock", ['_csrf' => $token, 'password' => 'open-sesame']);
        $unlocked = $stranger->get("/s/$id");
        $src = $this->rawSrc($unlocked['body']);
        $this->assertTrue($src !== null, 'Media appears after unlocking');
        $this->assertSame(200, $stranger->get($src)['status']);
    }

    public function testBurnAfterViewNeedsExplicitRevealAndThenDisappears(): void
    {
        $owner = $this->member();
        $data = $this->share($owner, ['content' => 'one-time secret: 7f3a', 'language' => 'plaintext', 'burn_after_view' => '1'], true);
        $id = $data['link']['id'];

        // Link unfurlers and prefetchers only ever GET: they must not consume it.
        $bot = $this->client('Slackbot-LinkExpanding 1.0');
        $this->assertContains('can be viewed once', $bot->get("/s/$id")['body']);
        $this->assertContains('can be viewed once', $bot->get("/s/$id")['body']);
        $this->assertNotContains('7f3a', $bot->get("/s/$id")['body']);

        // The owner's management page never consumes the share either.
        $manage = $owner->get($this->path($data['manage_url']));
        $this->assertSame(200, $manage['status']);
        $this->assertContains('Active', $manage['body']);

        $reader = $this->client();
        $token = $reader->csrf("/s/$id");
        $reveal = $reader->post("/s/$id/reveal", ['_csrf' => $token]);
        $this->assertSame(200, $reveal['status']);
        $this->assertContains('one-time secret: 7f3a', $reveal['body']);
        $this->assertContains('This was the last allowed view', $reveal['body']);

        $this->assertSame(410, $reader->get("/s/$id")['status']);
        $again = $this->client();
        $token = $again->csrf('/');
        $this->assertSame(410, $again->post("/s/$id/reveal", ['_csrf' => $token])['status']);
    }

    public function testManagementLinkRevokesInstantly(): void
    {
        $guest = $this->client();
        $data = $this->share($guest, ['content' => 'revoke me', 'language' => 'plaintext'], true);
        $viewer = $this->client('Mozilla/5.0 Firefox/131.0');
        $this->assertSame(200, $viewer->get($this->path($data['share_url']))['status']);

        $manager = $this->client();
        $managePath = $this->path($data['manage_url']);
        $token = $manager->csrf($managePath);
        $r = $manager->post($managePath . '/revoke', ['_csrf' => $token]);
        $this->assertSame(303, $r['status']);

        $gone = $viewer->get($this->path($data['share_url']));
        $this->assertSame(410, $gone['status']);
        $this->assertContains('revoked', $gone['body']);
        $this->assertSame(404, $manager->get(preg_replace('#/[^/]+$#', '/wrong-token', $managePath))['status'], 'Bad manage tokens are rejected');
    }

    public function testTextAndCodeSharing(): void
    {
        $c = $this->member();
        $data = $this->share($c, ['content' => "def greet(name):\n    return f\"<b>{name}</b>\"\n", 'language' => 'auto', 'expires_in' => '10m'], true);
        $this->assertSame('python', $data['file']['language']);
        $page = $c->get($this->path($data['share_url']));
        $this->assertContains('data-highlight="python"', $page['body']);
        $this->assertContains('&lt;b&gt;{name}&lt;/b&gt;', $page['body'], 'Snippet content is escaped');
        $this->assertContains('highlight.js', $page['body']);

        $md = $this->share($c, ['content' => "# Release notes\n\n- **fast**\n- <script>x</script>", 'language' => 'markdown'], true);
        $page = $c->get($this->path($md['share_url']));
        $this->assertContains('<h1>Release notes</h1>', $page['body']);
        $this->assertNotContains('<script>x</script>', $page['body']);
    }

    public function testNoJavascriptFormFallback(): void
    {
        $c = $this->client();
        $token = $c->csrf('/');
        $r = $c->post('/upload', ['_csrf' => $token, 'mode' => 'text', 'content' => 'posted without JS', 'expires_in' => '5m']);
        $this->assertSame(303, $r['status']);
        $this->assertContains('/m/', $r['headers']['location']);
        $manage = $c->get($r['headers']['location']);
        $this->assertContains('Your share is ready', $manage['body']);

        $bad = $c->post('/upload', ['_csrf' => $token, 'mode' => 'text', 'content' => 'x', 'expires_in' => 'forever']);
        $this->assertSame(303, $bad['status']);
        $this->assertContains('Choose an expiry', $c->get('/')['body'], 'Inline error beside the field');
    }

    public function testSecurityAndPrivacyHeaders(): void
    {
        $c = $this->client();
        $home = $c->get('/');
        $this->assertFalse(isset($home['headers']['x-robots-tag']), 'Homepage may be indexed');
        $this->assertContains("script-src 'self'", $home['headers']['content-security-policy']);
        $this->assertSame('nosniff', $home['headers']['x-content-type-options']);
        $this->assertSame('no-referrer', $home['headers']['referrer-policy']);
        $this->assertSame('DENY', $home['headers']['x-frame-options']);
        $cookie = implode(';', $home['cookies']);
        $this->assertContains('HttpOnly', $cookie);
        $this->assertContains('SameSite=Lax', $cookie);

        $data = $this->share($c, ['content' => 'headers', 'language' => 'plaintext'], true);
        $share = $c->get($this->path($data['share_url']));
        $this->assertSame('noindex, nofollow, noarchive', $share['headers']['x-robots-tag']);
        $this->assertContains('no-store', $share['headers']['cache-control']);
        $this->assertContains('Disallow: /s/', $c->get('/robots.txt')['body']);
    }

    public function testDangerousUploadsAreRejectedThroughTheWeb(): void
    {
        $c = $this->member();
        $token = $c->csrf('/');
        $r = $c->post('/upload', ['file' => HttpClient::file('<?php system($_GET[1]);', 'image/png', 'shell.png')], ['X-CSRF-Token' => $token, 'Accept' => 'application/json']);
        $this->assertSame(415, $r['status']);
        $this->assertSame('dangerous_file', json_decode($r['body'], true)['error']['code']);
        $r = $c->post('/upload', ['file' => HttpClient::file('<svg onload=alert(1)>', 'image/svg+xml', 'x.svg')], ['X-CSRF-Token' => $token, 'Accept' => 'application/json']);
        $this->assertSame(415, $r['status']);
    }

    public function testAdminPortalIsRestrictedAndAudited(): void
    {
        $member = $this->member();
        $this->assertSame(404, $member->get('/admin')['status'], 'Admin portal hidden from members');
        $this->assertSame(303, $this->client()->get('/admin/jobs')['status'], 'Guests are sent to sign in');

        $email = getenv('ADMIN_EMAIL');
        $password = getenv('ADMIN_PASSWORD');
        if (!$email || !$password) {
            return;
        }
        $admin = $this->client();
        $token = $admin->csrf('/login');
        $login = $admin->post('/login', ['_csrf' => $token, 'email' => $email, 'password' => $password, 'next' => '/admin']);
        $this->assertSame(303, $login['status']);
        $overview = $admin->get('/admin');
        $this->assertSame(200, $overview['status']);
        $this->assertContains('Background workers', $overview['body']);
        $this->assertContains('Failed (needs action)', $admin->get('/admin/jobs')['body']);
        $this->assertSame(200, $admin->get('/admin/reports')['status']);

        // Suspend a member: the action is recorded in the audit log.
        $users = $admin->get('/admin/users?q=http');
        preg_match('#/admin/users/([0-9a-f-]{36})/status#', $users['body'], $m);
        $token = $admin->csrf('/admin/users');
        $admin->post("/admin/users/{$m[1]}/status", ['_csrf' => $token, 'status' => 'suspended']);
        $this->assertContains('admin.user.suspended', $admin->get('/admin/audit?action=admin.user')['body']);
        $token = $admin->csrf('/admin/users');
        $admin->post("/admin/users/{$m[1]}/status", ['_csrf' => $token, 'status' => 'active']);
    }

    public function testCoreAccessibilityMarkup(): void
    {
        $c = $this->client();
        $share = $this->share($c, ['content' => 'a11y', 'language' => 'plaintext', 'password' => 'pass1234'], true);
        $pages = ['/', '/login', '/register', $this->path($share['share_url']), '/privacy'];
        foreach ($pages as $path) {
            $html = $c->get($path)['body'];
            $doc = new \DOMDocument();
            @$doc->loadHTML($html);
            $xpath = new \DOMXPath($doc);
            $this->assertSame('en', $doc->documentElement->getAttribute('lang'), "$path has a language");
            $this->assertSame(1, $xpath->query('//main[@id="main"]')->length, "$path has a main landmark");
            $this->assertSame(1, $xpath->query('//a[contains(@class,"skip-link")]')->length, "$path has a skip link");
            $this->assertTrue($xpath->query('//h1')->length >= 1, "$path has a heading");
            foreach ($xpath->query('//input[not(@type="hidden")] | //select | //textarea') as $control) {
                $id = $control->getAttribute('id');
                $labelled = $control->hasAttribute('aria-label')
                    || ($id !== '' && $xpath->query("//label[@for='$id']")->length > 0)
                    || $xpath->query('ancestor::label', $control)->length > 0;
                $this->assertTrue($labelled, "$path: control '" . ($control->getAttribute('name') ?: $id) . "' has a label");
            }
            foreach ($xpath->query('//img') as $img) {
                $this->assertTrue($img->hasAttribute('alt'), "$path: image has alt text");
            }
            foreach ($xpath->query('//button') as $button) {
                $this->assertTrue(trim($button->textContent) !== '' || $button->hasAttribute('aria-label'), "$path: button has an accessible name");
            }
        }
    }
}
