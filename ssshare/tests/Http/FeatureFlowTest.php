<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Services\TotpService;
use Tests\TestCase;

require_once __DIR__ . '/HttpClient.php';

/** End-to-end flows for Phases 2-4 through Nginx, PHP-FPM, MySQL, SMTP (Mailpit) and S3/local storage. */
final class FeatureFlowTest extends TestCase
{
    private function base(): string
    {
        return rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/');
    }

    private function client(): HttpClient
    {
        return new HttpClient($this->base());
    }

    /** @return array{0:HttpClient, 1:string} signed-in client and its email */
    private function member(): array
    {
        $c = $this->client();
        $email = 'flow' . bin2hex(random_bytes(5)) . '@example.test';
        $token = $c->csrf('/register');
        $c->post('/register', ['_csrf' => $token, 'display_name' => 'Flow', 'email' => $email, 'password' => 'a very good password']);
        return [$c, $email];
    }

    private function json(HttpClient $c, string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $token = $c->csrf('/');
        $r = $c->request($method, $path, $body, $headers + ['X-CSRF-Token' => $token, 'Accept' => 'application/json']);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    /** Runs one scheduler pass against the application database (delivers queued mail). */
    private function runScheduler(): void
    {
        exec('env DB_DATABASE=' . escapeshellarg((string) (getenv('APP_DB_DATABASE') ?: 'ssshare')) . ' ' . PHP_BINARY . ' ' . escapeshellarg(base_path('bin/cleanup.php')) . ' 2>&1');
    }

    private function mailpitBody(string $to): ?string
    {
        for ($i = 0; $i < 10; $i++) {
            $search = json_decode((string) @file_get_contents('http://mailpit:8025/api/v1/search?query=' . rawurlencode('to:' . $to)), true);
            $id = $search['messages'][0]['ID'] ?? null;
            if ($id) {
                return json_decode((string) file_get_contents('http://mailpit:8025/api/v1/message/' . $id), true)['Text'] ?? null;
            }
            usleep(300000);
        }
        return null;
    }

    private function promote(string $email, string $plan): void
    {
        exec('env DB_DATABASE=ssshare ' . PHP_BINARY . ' -r ' . escapeshellarg('require "' . base_path('bootstrap.php') . '"; $id = App\Support\Database::value("SELECT id FROM users WHERE email = ?", ["' . $email . '"]); App\Services\PlanService::assignToUser((int) $id, "' . $plan . '");'));
    }

    public function testEmailAllowlistCodeArrivesBySmtp(): void
    {
        [$owner, $email] = $this->member();
        $this->promote($email, 'pro');
        $recipient = 'reader' . bin2hex(random_bytes(4)) . '@example.test';
        $created = $this->json($owner, 'POST', '/paste', ['content' => 'for one reader only', 'audience' => 'emails', 'audience_list' => $recipient]);
        $this->assertSame(201, $created['status'], $created['body']);
        $path = (string) parse_url($created['json']['data']['share_url'], PHP_URL_PATH);

        $reader = $this->client();
        $gate = $reader->get($path);
        $this->assertContains('Confirm it’s you', $gate['body']);
        $this->assertNotContains('for one reader only', $gate['body']);
        $token = $reader->csrf($path);
        $reader->post($path . '/verify', ['_csrf' => $token, 'email' => $recipient]);
        $this->runScheduler();
        $mail = $this->mailpitBody($recipient);
        $this->assertTrue($mail !== null, 'Code email delivered over SMTP');
        preg_match('/\b(\d{6})\b/', (string) $mail, $m);
        $token = $reader->csrf($path);
        $reader->post($path . '/verify', ['_csrf' => $token, 'code' => $m[1]]);
        $this->assertContains('for one reader only', $reader->get($path)['body']);
    }

    public function testTwoFactorSignIn(): void
    {
        [$c, $email] = $this->member();
        $token = $c->csrf('/account/security');
        $c->post('/account/security/2fa-begin', ['_csrf' => $token]);
        $page = $c->get('/account/security')['body'];
        preg_match('/secret=([A-Z2-7]+)/', html_entity_decode($page), $m);
        $secret = $m[1];
        $token = $c->csrf('/account/security');
        $c->post('/account/security/2fa-confirm', ['_csrf' => $token, 'code' => TotpService::code($secret, intdiv(time(), 30))]);
        $this->assertContains('Save these recovery codes now', $c->get('/account/security')['body'], 'Recovery codes shown once');

        $fresh = $this->client();
        $token = $fresh->csrf('/login');
        $r = $fresh->post('/login', ['_csrf' => $token, 'email' => $email, 'password' => 'a very good password']);
        $this->assertSame('/login/2fa', $r['headers']['location']);
        $this->assertSame(303, $fresh->get('/dashboard')['status'], 'Not signed in until the second factor');
        $token = $fresh->csrf('/login/2fa');
        $bad = $fresh->post('/login/2fa', ['_csrf' => $token, 'code' => '000000']);
        $this->assertSame('/login/2fa', $bad['headers']['location']);
        // The current step's code was spent on setup (no replays), so use the next step (±1 drift is accepted).
        $code = TotpService::code($secret, intdiv(time(), 30) + 1);
        $token = $fresh->csrf('/login/2fa');
        $ok = $fresh->post('/login/2fa', ['_csrf' => $token, 'code' => $code]);
        $this->assertSame('/dashboard', $ok['headers']['location']);
        $this->assertSame(200, $fresh->get('/dashboard')['status']);
        $this->assertContains('password+2fa', $fresh->get('/account/security')['body']);
    }

    public function testTestCheckoutUpgradesThePlan(): void
    {
        [$c] = $this->member();
        $token = $c->csrf('/account/billing');
        $r = $c->post('/account/billing/checkout', ['_csrf' => $token, 'plan' => 'pro']);
        $this->assertSame('/billing/test-checkout?plan=pro', $r['headers']['location']);
        $token = $c->csrf('/billing/test-checkout?plan=pro');
        $c->post('/billing/test-checkout', ['_csrf' => $token, 'plan' => 'pro']);
        $this->assertContains('You are on the <strong>Pro</strong> plan', $c->get('/account/billing')['body']);
        $bad = $this->client()->request('POST', '/billing/webhook', '{"id":"x","type":"checkout.session.completed"}', ['Content-Type' => 'application/json', 'Stripe-Signature' => 't=1,v1=' . str_repeat('a', 64)]);
        $this->assertSame(400, $bad['status'], 'Unsigned billing webhooks are rejected');
    }

    public function testMcpOverHttp(): void
    {
        [$c] = $this->member();
        $token = $c->csrf('/account/api-keys');
        $c->post('/account/api-keys', http_build_query(['_csrf' => $token, 'name' => 'mcp', 'scopes' => ['uploads:read', 'uploads:write', 'folders:read', 'folders:write']]), ['Content-Type' => 'application/x-www-form-urlencoded']);
        preg_match('/value="(sss_live_[A-Za-z0-9_-]+)"/', $c->get('/account/api-keys')['body'], $m);
        $key = $m[1];
        $call = fn (array $msg, ?string $auth = null) => $this->client()->request('POST', '/mcp', json_encode($msg), ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . ($auth ?? $key)]);

        $init = json_decode($call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])['body'], true);
        $this->assertSame('ssshare', $init['result']['serverInfo']['name']);
        $this->assertSame(202, $call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])['status']);
        $share = json_decode($call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'share_text', 'arguments' => ['content' => 'hello from MCP']]])['body'], true);
        $url = $share['result']['structuredContent']['share_url'];
        $this->assertContains('hello from MCP', $this->client()->get((string) parse_url($url, PHP_URL_PATH))['body']);
        $this->assertSame(401, $call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], 'sss_live_' . str_repeat('x', 30))['status']);
        $batch = json_decode($call([['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list']])['body'], true);
        $this->assertSame(2, count($batch), 'JSON-RPC batches');
    }

    public function testResumableUploadOverHttpResumesAfterInterruption(): void
    {
        $guest = $this->client();
        $bytes = $this->png(500, 400);
        $created = $this->json($guest, 'POST', '/uploads/resumable', ['size' => (string) strlen($bytes), 'filename' => 'big.png', 'expires_in' => '10m']);
        $this->assertSame(201, $created['status'], $created['body']);
        $s = $created['json']['data'];
        $half = intdiv(strlen($bytes), 2);
        $first = $this->json($guest, 'PATCH', $s['upload_url'], substr($bytes, 0, $half), ['Upload-Offset' => '0', 'X-Upload-Token' => $s['token'], 'Content-Type' => 'application/offset+octet-stream']);
        $this->assertSame($half, $first['json']['data']['offset']);
        // "Network failure": the client asks where to resume.
        $status = $this->json($guest, 'GET', $s['upload_url'], null, ['X-Upload-Token' => $s['token']]);
        $this->assertSame((string) $half, $status['headers']['upload-offset']);
        $wrong = $this->json($guest, 'PATCH', $s['upload_url'], 'xx', ['Upload-Offset' => '0', 'X-Upload-Token' => $s['token'], 'Content-Type' => 'application/offset+octet-stream']);
        $this->assertSame(409, $wrong['status']);
        $done = $this->json($guest, 'PATCH', $s['upload_url'], substr($bytes, $half), ['Upload-Offset' => (string) $half, 'X-Upload-Token' => $s['token'], 'Content-Type' => 'application/offset+octet-stream']);
        $this->assertSame(201, $done['status'], $done['body']);
        $this->assertSame(500, $done['json']['data']['file']['width']);
        $this->assertSame(404, $this->json($this->client(), 'GET', $s['upload_url'], null, ['X-Upload-Token' => 'wrong'])['status'], 'Sessions are private to their token');
    }

    public function testCommentsWithPinsAndReview(): void
    {
        [$owner] = $this->member();
        $created = $this->json($owner, 'POST', '/upload', ['file' => HttpClient::file($this->png(), 'image/png', 'ui.png'), 'anonymous_comments' => '1', 'comments_enabled' => '1', 'request_review' => '1']);
        $path = (string) parse_url($created['json']['data']['share_url'], PHP_URL_PATH);
        $guest = $this->client();
        $token = $guest->csrf($path);
        $r = $guest->post($path . '/comments', ['_csrf' => $token, 'name' => 'Reviewer', 'body' => 'Button is misaligned', 'pin_x' => '0.4', 'pin_y' => '0.6']);
        $this->assertSame(303, $r['status']);
        $page = $guest->get($path)['body'];
        $this->assertContains('Button is misaligned', $page);
        $this->assertContains('data-pin-x="0.40000"', $page);
        $token = $guest->csrf($path);
        $guest->post($path . '/review', ['_csrf' => $token, 'name' => 'Reviewer', 'decision' => 'changes_requested', 'note' => 'See pin']);
        $manage = $owner->get('/manage/' . $created['json']['data']['id'])['body'];
        $this->assertContains('Changes requested', $manage);
        $csv = $owner->get('/manage/' . $created['json']['data']['id'] . '/comments.csv');
        $this->assertContains('text/csv', $csv['headers']['content-type']);
        $this->assertContains('Button is misaligned', $csv['body']);
        $this->assertContains('commented on', $owner->get('/notifications')['body']);
    }

    public function testCollectionsPasswordAndZip(): void
    {
        [$c] = $this->member();
        $a = $this->json($c, 'POST', '/upload', ['file' => HttpClient::file($this->png(30, 30), 'image/png', 'a.png')])['json']['data'];
        $b = $this->json($c, 'POST', '/paste', ['content' => 'notes', 'title' => 'Notes'])['json']['data'];
        $token = $c->csrf('/collections');
        $r = $c->post('/collections', http_build_query(['_csrf' => $token, 'title' => 'Release', 'ids' => [$a['id'], $b['id']]]), ['Content-Type' => 'application/x-www-form-urlencoded']);
        preg_match('#/collections/([A-Za-z0-9]+)/edit#', $r['headers']['location'], $m);
        $id = $m[1];
        $token = $c->csrf("/collections/$id/edit");
        $c->post("/collections/$id/settings", ['_csrf' => $token, 'title' => 'Release', 'layout' => 'masonry', 'visibility' => 'unlisted', 'password' => 'opensesame', 'allow_download_all' => '1']);
        $visitor = $this->client();
        $this->assertContains('password protected', $visitor->get("/c/$id")['body']);
        $this->assertSame(403, $visitor->get("/c/$id/download")['status']);
        $token = $visitor->csrf("/c/$id");
        $visitor->post("/c/$id/unlock", ['_csrf' => $token, 'password' => 'opensesame']);
        $page = $visitor->get("/c/$id")['body'];
        $this->assertContains('gallery-masonry', $page);
        $zip = $visitor->get("/c/$id/download");
        $this->assertSame('application/zip', $zip['headers']['content-type']);
        $this->assertSame("PK\x03\x04", substr($zip['body'], 0, 4));
    }

    public function testEncryptedUploadAndVideoRangeRequests(): void
    {
        $guest = $this->client();
        $meta = json_encode(['v' => 1, 'alg' => 'AES-GCM', 'iv' => rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '='), 'header' => str_repeat('A', 40), 'header_iv' => rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=')]);
        $enc = $this->json($guest, 'POST', '/upload/encrypted', ['cipher' => HttpClient::file(random_bytes(500), 'application/octet-stream', 'cipher.bin'), 'meta' => $meta, 'expires_in' => '10m']);
        $this->assertSame(201, $enc['status'], $enc['body']);
        $page = $guest->get((string) parse_url($enc['json']['data']['share_url'], PHP_URL_PATH))['body'];
        $this->assertContains('data-zk-view', $page);
        $this->assertContains('zk.js', $page);

        $src = tempnam(sys_get_temp_dir(), 'v') . '.webm';
        exec(sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=1:size=160x120:rate=10 -c:v libvpx -b:v 100k %s', escapeshellarg($src)));
        $video = $this->json($guest, 'POST', '/upload', ['file' => HttpClient::file((string) file_get_contents($src), 'video/webm', 'clip.webm')]);
        @unlink($src);
        $this->assertSame(201, $video['status'], $video['body']);
        $this->assertSame('video', $video['json']['data']['type']);
        $viewer = $this->client();
        $share = $viewer->get((string) parse_url($video['json']['data']['share_url'], PHP_URL_PATH))['body'];
        preg_match('#<source src="([^"]+)"#', $share, $m);
        $media = html_entity_decode($m[1]);
        $range = $viewer->get($media, ['Range' => 'bytes=0-99']);
        $this->assertSame(206, $range['status'], 'Range requests for seeking');
        $this->assertSame(100, strlen($range['body']));
        $this->assertContains('bytes 0-99/', $range['headers']['content-range']);
    }
}
