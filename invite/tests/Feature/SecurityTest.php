<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class SecurityTest extends TestCase
{
    private const XSS = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

    public function testHostAndGuestSuppliedTextIsEscapedEverywhere(): void
    {
        [$host] = $this->newHost('<b>Bold Host</b>');
        $event = $this->newEvent($host, [
            'title' => 'T' . self::XSS, 'host_names' => 'H' . self::XSS, 'venue_name' => 'V' . self::XSS,
            'venue_address' => 'A' . self::XSS, 'description' => 'D' . self::XSS,
        ]);
        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/questions', ['type' => 'short_text', 'label' => 'Q' . self::XSS]);
        $this->publish($host, $event);
        $guest = new Http();
        $p = $guest->get('/i/' . $event['slug']);
        $guest->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'G' . self::XSS,
            'comment' => 'C' . self::XSS, 'email' => 'x' . bin2hex(random_bytes(3)) . '@example.test']);

        $pages = [
            'invitation' => (new Http())->get('/i/' . $event['slug']),
            'dashboard' => $host->get('/app'), 'events' => $host->get('/app/events'), 'overview' => $host->get($base),
            'guests' => $host->get($base . '/guests'), 'guest' => $host->get($base . '/guests/' . Db::value('SELECT uuid FROM event_guests WHERE event_id = ?', [$event['id']])),
            'form' => $host->get($base . '/rsvp-form'), 'messages' => $host->get($base . '/messages'), 'reports' => $host->get($base . '/reports'),
            'details' => $host->get($base . '/details'), 'editor' => $host->get($base . '/invitation'), 'preview' => $host->get($base . '/preview'),
        ];
        foreach ($pages as $name => $r) {
            $this->assertStatus(200, $r, $name);
            $this->assertNotContains('<script>alert(1)</script>', $r->body, "$name: raw script tag");
            $this->assertNotContains('<img src=x onerror', $r->body, "$name: raw injected element");
        }
        $this->assertContains('&lt;script&gt;', $pages['invitation']->body, 'escaped rather than dropped');
        // The editor's JSON bootstrap cannot be broken out of with </script>.
        $this->assertNotContains('</script><script>', $pages['editor']->body);
    }

    public function testNoInlineScriptsStylesOrEventHandlersAnywhere(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        $this->publish($host, $event);
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'meal']);
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Pat', 'email' => 'pat' . bin2hex(random_bytes(3)) . '@example.test']);
        $anon = new Http();
        $publicPaths = ['/', '/templates', '/pricing', '/login', '/register', '/help/api', '/i/' . $event['slug']];
        $paths = [$base . '/preview', '/app', '/app/events', '/app/events/new', '/app/settings',
            $base, $base . '/details', $base . '/invitation', $base . '/guests', $base . '/guests/new', $base . '/guests/import', $base . '/rsvp-form', $base . '/messages',
            $base . '/reports', $base . '/collaborators', $base . '/sub-events', $base . '/checkin', $base . '/settings', $base . '/automations', $base . '/review', $base . '/album', $base . '/seating', $base . '/polls', '/app/settings/brand', '/app/settings/webhooks', '/app/settings/mfa'];
        foreach (array_merge($publicPaths, $paths) as $path) {
            $r = in_array($path, $publicPaths, true) ? $anon->get($path) : $host->get($path);
            $this->assertStatus(200, $r, $path);
            $csp = $r->headers['content-security-policy'] ?? '';
            $this->assertNotContains('unsafe-inline', $csp, "$path CSP");
            $this->assertNotContains('unsafe-eval', $csp);
            $this->assertTrue(!preg_match('/<[^>]+\son[a-z]+\s*=/i', $r->body), "$path has an inline event handler");
            $this->assertTrue(!preg_match('/<[a-z][^>]*\sstyle\s*=/i', preg_replace('#<svg.*?</svg>#s', '', $r->body)), "$path has an inline style attribute");
            // Every script tag is external or carries this response's nonce (JSON data blocks excepted).
            preg_match('/nonce-([A-Za-z0-9+\/=]+)/', $csp, $n);
            preg_match_all('/<script\b([^>]*)>/i', $r->body, $tags);
            foreach ($tags[1] as $attrs) {
                if (str_contains($attrs, 'application/json')) {
                    continue;
                }
                $this->assertTrue(str_contains($attrs, 'src=') && str_contains($attrs, 'nonce="' . ($n[1] ?? '?') . '"'), "$path: script without nonce ($attrs)");
            }
            $this->assertTrue(!preg_match('#(src|href)="https?://(?!localhost|web)[^"]*\.(js|css)"#i', $r->body), "$path loads third-party assets");
        }
    }

    public function testImageUploadsAreValidatedByContentNotName(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        $host->get($base . '/invitation');
        $headers = ['X-CSRF-Token: ' . $host->csrf(), 'Accept: application/json', 'X-Requested-With: fetch'];

        $png = sys_get_temp_dir() . '/ok-' . bin2hex(random_bytes(3)) . '.png';
        $im = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAIAAAD/gAIDAAAAUElEQVR42u3PMQEAAAgDINc/9Kx4gkCmDaM07wUCgUAgEAgEAoFAIBAIBAKBQCAQCAQCgUAgEAgEAoFAIBAIBAKBQCAQCAQCgUAgEAgELhwLvQGTw1N6mQAAAABJRU5ErkJggg==');
        file_put_contents($png, $png ? $im : '');
        $ok = $host->upload($base . '/media', 'file', $png, 'image/png', [], $headers);
        $this->assertStatus(201, $ok);
        $url = $ok->json()['url'];
        $this->assertTrue((bool) preg_match('#^/uploads/[0-9a-f-]{36}/[0-9a-f]{24}\.png$#', $url), 'random server-chosen name with detected extension');
        $served = (new Http())->get($url);
        $this->assertStatus(200, $served);
        $this->assertSame('nosniff', $served->headers['x-content-type-options'] ?? '');
        $this->assertContains('sandbox', $served->headers['content-security-policy'] ?? '');

        $cases = [
            'php disguised as jpg' => ['x.jpg', "<?php system(\$_GET['c']); ?>", 'image/jpeg'],
            'html' => ['x.html', '<html><script>alert(1)</script></html>', 'text/html'],
            'svg' => ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
        ];
        foreach ($cases as $label => [$name, $content, $mime]) {
            $f = sys_get_temp_dir() . '/' . bin2hex(random_bytes(3)) . '-' . $name;
            file_put_contents($f, $content);
            $r = $host->upload($base . '/media', 'file', $f, $mime, [], $headers);
            $this->assertStatus(422, $r, $label);
            @unlink($f);
        }
        // A valid PNG with PHP appended after the image data is refused.
        $poly = sys_get_temp_dir() . '/poly-' . bin2hex(random_bytes(3)) . '.png';
        file_put_contents($poly, $im . "\n<?php system('id'); ?>");
        $this->assertStatus(422, $host->upload($base . '/media', 'file', $poly, 'image/png', [], $headers), 'polyglot');
        @unlink($png);
        @unlink($poly);
        // Upload permission follows the invitation.edit ability; strangers cannot even tell the event exists.
        $again = sys_get_temp_dir() . '/again-' . bin2hex(random_bytes(3)) . '.png';
        file_put_contents($again, $im);
        [$outsider] = $this->newHost();
        $outsider->get('/app');
        $this->assertStatus(404, $outsider->upload($base . '/media', 'file', $again, 'image/png', [], ['X-CSRF-Token: ' . $outsider->csrf(), 'Accept: application/json']));
        @unlink($again);
        // Directly requesting a PHP file under uploads never executes it.
        $this->assertStatus(404, (new Http())->get('/uploads/x.php'));
    }

    public function testSessionIdChangesOnLoginAndCsrfIsPerSession(): void
    {
        [, $email] = $this->newHost();
        $h = new Http();
        $before = $h->get('/login');
        $c1 = $before->headers['set-cookie'] ?? '';
        preg_match('/invite_sid=([^;]+)/', $c1, $m1);
        $token1 = $h->csrf();
        $h->post('/login', ['_csrf' => $token1, 'email' => $email, 'password' => 'correct horse battery']);
        $jar = file_get_contents($h->cookieJar());
        preg_match('/invite_sid\s+(\S+)/', (string) $jar, $m2);
        $this->assertTrue(!empty($m1[1]) && !empty($m2[1]) && $m1[1] !== $m2[1], 'session id rotated at login (fixation defence)');
        // A CSRF token from one session is useless in another.
        $other = new Http();
        $other->get('/login');
        $r = $other->post('/login', ['_csrf' => $token1, 'email' => $email, 'password' => 'correct horse battery']);
        $this->assertStatus(419, $r);
        // Cross-origin POSTs are rejected even with a valid token.
        $h2 = new Http();
        $h2->get('/login');
        $r = $h2->post('/login', ['_csrf' => $h2->csrf(), 'email' => $email, 'password' => 'correct horse battery'], ['Origin: https://evil.example']);
        $this->assertStatus(419, $r);
    }

    public function testWrongMethodsAndMalformedInputFailCleanly(): void
    {
        $h = new Http();
        $this->assertStatus(405, $h->get('/logout'));
        $this->assertStatus(405, $h->request('PUT', '/login'));
        $this->assertStatus(404, $h->get('/i/' . str_repeat('a', 12)));
        $this->assertStatus(404, $h->get('/i/short'));
        foreach (['/g/' . rawurlencode('../../etc/passwd'), '/uploads/../../etc/passwd', '/assets/..%2f..%2fbootstrap.php'] as $evil) {
            $r = $h->get($evil);
            $this->assertTrue(in_array($r->status, [400, 404], true), 'traversal rejected: ' . $evil . ' -> ' . $r->status);
            $this->assertNotContains('<?php', $r->body);
        }
        $this->assertStatus(404, $h->get('/.env'));
        $this->assertStatus(404, $h->get('/composer.json'));
        $r = $h->get('/index.php/../bootstrap.php');
        $this->assertTrue(in_array($r->status, [400, 404], true));
        $this->assertNotContains('<?php', $r->body);
        $bad = $h->request('POST', '/api/v1/invitations/aaaaaaaaaaaa/rsvp', '{not json', ['Content-Type: application/json', 'Idempotency-Key: abcdefgh12']);
        $this->assertTrue(in_array($bad->status, [404, 422], true), 'malformed JSON is a clean client error, got ' . $bad->status);
        $this->assertNotContains('Stack trace', $bad->body);
        $this->assertNotContains('/var/www', $h->get('/g/' . str_repeat('A', 22) . '.1.' . str_repeat('A', 22))->body, 'no internal paths in errors');
    }

    public function testApiTokensAndAuthTokensAreNeverStoredOrLoggedInPlaintext(): void
    {
        [$host, $email] = $this->newHost();
        $this->runWorker();
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $this->assertSame(64, strlen((string) Db::value('SELECT token_hash FROM auth_tokens WHERE user_id = ? LIMIT 1', [$user['id']])), 'auth tokens stored as SHA-256 hashes');
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE meta LIKE '%inv\\_%' OR meta LIKE '%token%'"), 'no tokens in audit metadata');
        // Guest links are never persisted at all.
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('event_guests','guests','qr_tokens') AND column_name IN ('token','link','url')"), 'no plaintext link/token columns');
    }
}
