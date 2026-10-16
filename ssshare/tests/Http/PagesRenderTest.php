<?php

declare(strict_types=1);

namespace Tests\Http;

use Tests\TestCase;

require_once __DIR__ . '/HttpClient.php';

/** Every page renders for the right audience (no server errors, correct status). */
final class PagesRenderTest extends TestCase
{
    private function base(): string
    {
        return rtrim(getenv('TEST_BASE_URL') ?: 'http://web', '/');
    }

    private function member(): HttpClient
    {
        $c = new HttpClient($this->base());
        $token = $c->csrf('/register');
        $c->post('/register', ['_csrf' => $token, 'display_name' => 'Pages', 'email' => 'pages' . bin2hex(random_bytes(5)) . '@example.test', 'password' => 'a very good password']);
        return $c;
    }

    private function assertRenders(HttpClient $c, string $path, int $status = 200): string
    {
        $r = $c->get($path);
        $this->assertSame($status, $r['status'], "GET $path");
        $this->assertNotContains('Something went wrong on our side', $r['body'], "$path renders without a server error");
        return $r['body'];
    }

    public function testPublicPages(): void
    {
        $c = new HttpClient($this->base());
        foreach (['/', '/pricing', '/privacy', '/docs/api', '/docs/integrations', '/login', '/register', '/sso', '/record', '/encrypt', '/edit'] as $path) {
            $this->assertRenders($c, $path);
        }
        $spec = json_decode($c->get('/openapi.json')['body'], true);
        $this->assertSame('3.1.0', $spec['openapi'] ?? null);
        $manifest = json_decode($c->get('/manifest.webmanifest')['body'], true);
        $this->assertSame('/share-target', $manifest['share_target']['action']);
        $sw = $c->get('/sw.js');
        $this->assertSame('/', $sw['headers']['service-worker-allowed']);
        $this->assertContains(':root', $c->get('/brand.css?c=ff0000')['body']);
        $this->assertSame(404, $c->get('/brand.css?c=red;}body{display:none')['status'], 'Brand colour input is validated');
    }

    public function testMemberPages(): void
    {
        $c = $this->member();
        foreach (['/dashboard', '/dashboard?scope=workspace&favorites=1', '/collections', '/workspace', '/account/security', '/account/privacy', '/account/billing', '/account/webhooks', '/account/api-keys', '/notifications', '/dashboard?tab=scheduled'] as $path) {
            $this->assertRenders($c, $path);
        }
        // A share's management page and its sections.
        $token = $c->csrf('/');
        $r = $c->post('/paste', ['content' => "line one\nline two", 'language' => 'plaintext', 'request_review' => '1'], ['X-CSRF-Token' => $token, 'Accept' => 'application/json']);
        $data = json_decode($r['body'], true)['data'];
        $manage = $this->assertRenders($c, '/manage/' . $data['id']);
        $this->assertContains('Views in the last 14 days', $manage);
        $this->assertContains('data-qr=', $manage);
        $this->assertRenders($c, (string) parse_url($data['share_url'], PHP_URL_PATH));
        $this->assertSame(404, $c->get('/admin')['status']);
    }

    public function testAdminPages(): void
    {
        $email = getenv('ADMIN_EMAIL');
        $password = getenv('ADMIN_PASSWORD');
        if (!$email || !$password) {
            $this->assertTrue(true);
            return;
        }
        $c = new HttpClient($this->base());
        $token = $c->csrf('/login');
        $c->post('/login', ['_csrf' => $token, 'email' => $email, 'password' => $password, 'next' => '/admin']);
        foreach (['/admin', '/admin/users', '/admin/reports', '/admin/jobs', '/admin/audit', '/admin/settings', '/admin/billing', '/admin/legal', '/admin/webhooks', '/admin/usage', '/admin/metrics', '/admin/metrics?days=7', '/admin/workspaces', '/admin/blocklist'] as $path) {
            $this->assertRenders($c, $path);
        }
    }
}
