<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\TestCase;

require_once __DIR__ . '/HttpClient.php';

/** Acceptance: API requests return consistent structured errors. */
final class ApiTest extends TestCase
{
    private HttpClient $http;
    private string $key = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpClient(rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/'));
    }

    /** Creates an account and an API key through the web UI, like a real user. */
    private function key(array $scopes = ['uploads:read', 'uploads:write', 'folders:read', 'folders:write']): string
    {
        $web = new HttpClient(rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/'));
        $token = $web->csrf('/register');
        $web->post('/register', ['_csrf' => $token, 'display_name' => 'API', 'email' => 'api' . bin2hex(random_bytes(5)) . '@example.test', 'password' => 'a very good password']);
        $token = $web->csrf('/account/api-keys');
        $body = http_build_query(['_csrf' => $token, 'name' => 'test', 'expires' => '30d', 'scopes' => $scopes]);
        $web->post('/account/api-keys', $body, ['Content-Type' => 'application/x-www-form-urlencoded']);
        $page = $web->get('/account/api-keys');
        preg_match('/value="(sss_live_[A-Za-z0-9_-]+)"/', $page['body'], $m);
        $this->assertTrue(isset($m[1]), 'New key is shown once');
        $this->assertSame(0, preg_match('/sss_live_[A-Za-z0-9_-]{20,}/', $web->get('/account/api-keys')['body']), 'Key is not shown again');
        return $m[1];
    }

    private function api(string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $r = (new HttpClient(rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/')))
            ->request($method, $path, $body, ($this->key !== '' ? ['Authorization' => 'Bearer ' . $this->key] : []) + $headers);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    private function assertEnvelope(array $r, int $status, string $code): void
    {
        $this->assertSame($status, $r['status'], "HTTP status for $code: " . substr($r['body'], 0, 200));
        $this->assertSame($code, $r['json']['error']['code'] ?? null);
        $this->assertTrue(is_string($r['json']['error']['message'] ?? null), 'Error has a message');
        $this->assertSame(16, strlen($r['json']['error']['request_id'] ?? ''), 'Error has a request id');
        $this->assertContains('application/json', $r['headers']['content-type']);
    }

    public function testAuthenticationErrors(): void
    {
        $this->assertEnvelope($this->api('GET', '/api/v1/uploads'), 401, 'unauthenticated');
        $this->key = 'sss_live_' . str_repeat('Z', 32);
        $this->assertEnvelope($this->api('GET', '/api/v1/uploads'), 401, 'invalid_api_key');
        $this->key = '';
        $this->assertEnvelope($this->api('GET', '/api/v1/nothing-here'), 404, 'not_found');
    }

    public function testUploadLifecycle(): void
    {
        $this->key = $this->key();

        $created = $this->api('POST', '/api/v1/uploads', [
            'file' => HttpClient::file($this->withExif($this->jpeg()), 'image/jpeg', 'bug.jpg'),
            'expires_in' => '1h', 'view_limit' => '10', 'title' => 'Bug #42',
        ]);
        $this->assertSame(201, $created['status'], substr($created['body'], 0, 300));
        $data = $created['json']['data'];
        $this->assertSame('image/jpeg', $data['file']['mime']);
        $this->assertSame(['EXIF', 'GPS location', 'Comment'], $data['removed_metadata']);
        $this->assertSame(10, $data['link']['remaining_views']);
        $this->assertSame(null, $data['direct_url'], 'View-limited images have no direct URL');
        $this->assertContains('after 10 unique views', $data['expiration_summary']);
        foreach (['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'x-quota-used', 'x-quota-limit', 'x-quota-remaining'] as $h) {
            $this->assertTrue(isset($created['headers'][$h]), "Header $h present");
        }

        $id = $data['id'];
        $this->assertSame('Bug #42', $this->api('GET', "/api/v1/uploads/$id")['json']['data']['title']);
        $list = $this->api('GET', '/api/v1/uploads?per_page=5');
        $this->assertSame($id, $list['json']['data'][0]['id']);
        $this->assertSame(5, $list['json']['meta']['per_page']);

        $patched = $this->api('PATCH', "/api/v1/uploads/$id", json_encode(['title' => 'Renamed']), ['Content-Type' => 'application/json']);
        $this->assertSame('Renamed', $patched['json']['data']['title']);
        $this->assertEnvelope($this->api('PATCH', "/api/v1/uploads/$id", json_encode(['title' => str_repeat('x', 200)]), ['Content-Type' => 'application/json']), 422, 'validation_failed');

        // A second link with its own password; pausing it blocks the share page.
        $link = $this->api('POST', "/api/v1/uploads/$id/links", json_encode(['password' => 'pw-1234', 'expires_in' => '10m']), ['Content-Type' => 'application/json']);
        $this->assertSame(201, $link['status']);
        $this->assertTrue($link['json']['data']['password_protected']);
        $linkId = $link['json']['data']['id'];
        $paused = $this->api('PATCH', "/api/v1/links/$linkId", json_encode(['status' => 'paused']), ['Content-Type' => 'application/json']);
        $this->assertSame('paused', $paused['json']['data']['status']);
        $this->assertSame(403, (new HttpClient(rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/')))->get("/s/$linkId")['status']);
        $this->assertSame('revoked', $this->api('DELETE', "/api/v1/links/$linkId")['json']['data']['status']);

        $deleted = $this->api('DELETE', "/api/v1/uploads/$id");
        $this->assertSame('deleted', $deleted['json']['data']['status']);
        $this->assertSame('deleted', $this->api('GET', "/api/v1/uploads/$id")['json']['data']['status']);
        $this->assertEnvelope($this->api('GET', '/api/v1/uploads/00000000-0000-4000-8000-000000000000'), 404, 'not_found');
    }

    public function testValidationAndRejectedContent(): void
    {
        $this->key = $this->key();
        $r = $this->api('POST', '/api/v1/uploads', ['content' => 'x', 'view_limit' => '5000', 'expires_in' => 'eventually']);
        $this->assertEnvelope($r, 422, 'validation_failed');
        $this->assertTrue(isset($r['json']['error']['details']['view_limit'], $r['json']['error']['details']['expires_in']), 'Field-level details');

        $this->assertEnvelope($this->api('POST', '/api/v1/uploads', []), 422, 'validation_failed');
        $this->assertEnvelope($this->api('POST', '/api/v1/uploads', ['file' => HttpClient::file('<?php phpinfo();', 'image/png', 'a.png')]), 415, 'dangerous_file');
        $this->assertEnvelope($this->api('POST', '/api/v1/uploads', ['file' => HttpClient::file(random_bytes(64), 'image/png', 'a.png')]), 415, 'unsupported_type');
    }

    public function testIdempotencyKeys(): void
    {
        $this->key = $this->key();
        $idem = 'idem-' . bin2hex(random_bytes(8));
        $first = $this->api('POST', '/api/v1/uploads', ['content' => 'retry-safe', 'expires_in' => '5m'], ['Idempotency-Key' => $idem]);
        $this->assertSame(201, $first['status']);
        $replay = $this->api('POST', '/api/v1/uploads', ['content' => 'retry-safe', 'expires_in' => '5m'], ['Idempotency-Key' => $idem]);
        $this->assertSame(201, $replay['status']);
        $this->assertSame('true', $replay['headers']['idempotent-replayed'] ?? null);
        $this->assertSame($first['json']['data']['id'], $replay['json']['data']['id'], 'Same upload returned, not a duplicate');
        $this->assertEnvelope($this->api('POST', '/api/v1/uploads', ['content' => 'different', 'expires_in' => '5m'], ['Idempotency-Key' => $idem]), 422, 'idempotency_conflict');
    }

    public function testScopesAndFolders(): void
    {
        $this->key = $this->key(['uploads:read']);
        $this->assertEnvelope($this->api('POST', '/api/v1/uploads', ['content' => 'x']), 403, 'insufficient_scope');
        $this->assertSame(200, $this->api('GET', '/api/v1/uploads')['status']);

        $this->key = $this->key();
        $folder = $this->api('POST', '/api/v1/folders', json_encode(['name' => 'Bugs']), ['Content-Type' => 'application/json']);
        $this->assertSame(201, $folder['status']);
        $this->assertEnvelope($this->api('POST', '/api/v1/folders', json_encode(['name' => 'Bugs']), ['Content-Type' => 'application/json']), 409, 'conflict');
        $up = $this->api('POST', '/api/v1/uploads', ['content' => 'in a folder', 'folder_id' => $folder['json']['data']['id']]);
        $this->assertSame(201, $up['status']);
        $this->assertSame(1, $this->api('GET', '/api/v1/folders')['json']['data'][0]['active_uploads']);
        $usage = $this->api('GET', '/api/v1/usage')['json']['data'];
        $this->assertSame(1, $usage['api_uploads']['used']);
    }
}
