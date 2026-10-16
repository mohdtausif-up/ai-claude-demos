<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\ShareRepository;
use App\Services\AnalyticsService;
use App\Services\ApiKeyService;
use App\Services\ExpirationService;
use App\Services\McpServer;
use App\Services\MetricsService;
use App\Services\UploadService;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Database;
use App\Support\OpenApi;
use App\Support\Request;
use Tests\TestCase;

/** Scheduling, sandbox keys, launch metrics, country analytics, blocklist and the OpenAPI spec. */
final class LaunchReadinessTest extends TestCase
{
    public function testScheduledShareOpensLaterAndExpiryCountsFromStart(): void
    {
        $user = $this->user();
        $start = now()->modify('+2 hours');
        $result = (new UploadService())->createText('embargoed notes', 'plaintext', ['starts_at' => $start->format(DATE_ATOM), 'expires_in' => '1h'], $user);
        $link = $this->link($result);
        $this->assertSame($start->format('Y-m-d H:i:s'), $link['starts_at']);
        $this->assertSame($start->modify('+1 hour')->format('Y-m-d H:i'), substr((string) $link['expires_at'], 0, 16), 'A relative expiry counts from the start');

        $expiration = new ExpirationService();
        $this->assertSame('not_started', $expiration->access((int) $link['id'], 'view', $this->request())['reason']);
        $scheduled = ShareRepository::listForOwner((int) $user['id'], ['tab' => 'scheduled'], 1, 10);
        $this->assertSame(1, $scheduled['total']);

        Clock::travel(2 * 3600 + 5);
        $this->assertTrue($expiration->access((int) $link['id'], 'view', $this->request())['allowed'], 'Opens once the start time passes');
        $this->assertSame(0, ShareRepository::listForOwner((int) $user['id'], ['tab' => 'scheduled'], 1, 10)['total']);

        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createText('x', null, ['starts_at' => now()->modify('+1 day')->format(DATE_ATOM)], null), 422);
        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createText('x', null, [
            'starts_at' => now()->modify('+2 days')->format(DATE_ATOM), 'expires_at' => now()->modify('+1 day')->format(DATE_ATOM),
        ], $user), 422);
    }

    public function testSandboxKeysCapExpiryAndSkipQuota(): void
    {
        $user = $this->user();
        $created = (new ApiKeyService())->create($user, 'ci', ApiKeyService::SCOPES, null, 'test');
        $this->assertTrue(str_starts_with($created['key'], 'sss_test_'));
        $auth = (new ApiKeyService())->authenticate('Bearer ' . $created['key']);
        $this->assertSame('test', $auth['key']['environment']);

        $this->assertSame('1h', ExpirationService::sandboxOptions(['expires_in' => '30d'])['expires_in']);
        $this->assertSame('1h', ExpirationService::sandboxOptions(['expires_in' => 'never'])['expires_in']);
        $this->assertSame('10m', ExpirationService::sandboxOptions(['expires_in' => '10m'])['expires_in'], 'Shorter expiries are kept');
        $this->assertSame(null, ExpirationService::sandboxOptions(['expires_at' => now()->modify('+5 hours')->format(DATE_ATOM)])['expires_at']);

        $server = new McpServer($user, $auth['key']);
        $out = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'share_text', 'arguments' => ['content' => 'sandbox', 'expires_in' => '7d']]]);
        $this->assertSame(false, $out['result']['isError'] ?? false);
        $link = ShareRepository::findLinkByPublicId(basename($out['result']['structuredContent']['share_url']));
        $this->assertTrue(strtotime($link['expires_at'] . ' UTC') <= now()->getTimestamp() + 3600, 'Sandbox shares expire within an hour');
        $this->assertSame(null, Database::value('SELECT uploads FROM api_usage WHERE user_id = ?', [$user['id']]), 'Sandbox uploads do not use quota');
    }

    public function testUploadAttemptsFeedTheLaunchDashboard(): void
    {
        $user = $this->user();
        $before = MetricsService::dashboard();
        $result = (new UploadService())->createImage($this->png(), 'a.png', ['expires_in' => '7d', 'password' => 'secret1'], $user);
        $id = MetricsService::recordAttempt('web', 'image', $user, $result['upload'], true, null, 120, ['expires_in' => '7d', 'password' => 'secret1']);
        MetricsService::recordAttempt('web', 'image', $user, null, false, 'unsupported_type', 5, []);
        $this->assertTrue($id > 0);

        MetricsService::recordClientTiming($id, 1800, (int) $user['id']);
        MetricsService::recordClientTiming($id, 9999, (int) $user['id']); // only the first report counts
        $this->assertSame(1800, (int) Database::value('SELECT client_ms FROM upload_attempts WHERE id = ?', [$id]));
        MetricsService::recordClientTiming($id, 10, null);
        $this->assertSame(1800, (int) Database::value('SELECT client_ms FROM upload_attempts WHERE id = ?', [$id]), 'Other visitors cannot overwrite timings');
        $this->assertHttpError('validation_failed', fn () => MetricsService::recordClientTiming($id, -5, null), 422);

        $row = Database::first('SELECT non_default_expiry, password FROM upload_attempts WHERE id = ?', [$id]);
        $this->assertSame(['non_default_expiry' => 1, 'password' => 1], array_map('intval', $row));

        $after = MetricsService::dashboard();
        foreach (['upload_success', 'paste_to_link', 'custom_expiry', 'nps', 'retention_30', 'deletion_success'] as $key) {
            $this->assertTrue(array_key_exists($key, $after), "Dashboard has $key");
        }
        $this->assertTrue($after['upload_success']['value'] !== null && $after['upload_success']['value'] < 100.0, 'Failures lower the completion rate');
        $this->assertTrue($before !== $after);
    }

    public function testNpsIsOfferedQuarterlyAndValidated(): void
    {
        $user = $this->user();
        $this->assertFalse(MetricsService::npsDue((int) $user['id']), 'New accounts are not surveyed in their first week');
        Database::execute('UPDATE users SET created_at = ? WHERE id = ?', [db_time(now()->modify('-10 days')), $user['id']]);
        $this->assertTrue(MetricsService::npsDue((int) $user['id']));
        $this->assertHttpError('validation_failed', fn () => MetricsService::recordNps((int) $user['id'], 11, null), 422);
        MetricsService::recordNps((int) $user['id'], 9, '  Fast  ');
        $this->assertSame('Fast', Database::value('SELECT comment FROM nps_responses WHERE user_id = ?', [$user['id']]));
        $this->assertFalse(MetricsService::npsDue((int) $user['id']), 'Asked at most once per quarter');
    }

    public function testCountryComesOnlyFromAConfiguredEdgeHeader(): void
    {
        $request = new Request('GET', '/', [], [], [], ['user-agent' => 'Mozilla/5.0 Firefox/120.0', 'cf-ipcountry' => 'de']);
        $previous = config('app.analytics.country_header');
        try {
            Config::set('app.analytics.country_header', '');
            $this->assertSame(null, AnalyticsService::country($request), 'Ignored unless the operator trusts the header');
            Config::set('app.analytics.country_header', 'CF-IPCountry');
            $this->assertSame('DE', AnalyticsService::country($request));
            $this->assertSame(null, AnalyticsService::country(new Request('GET', '/', [], [], [], ['cf-ipcountry' => 'XX'])), 'Unknown country is not recorded');
            $this->assertSame(null, AnalyticsService::country(new Request('GET', '/', [], [], [], ['cf-ipcountry' => '<script>'])));

            $result = (new UploadService())->createText('hello', null, [], $this->user());
            AnalyticsService::recordView((int) $this->link($result)['id'], 'ok', $request, 'h1');
            $this->assertSame('DE', AnalyticsService::summary((int) $this->link($result)['id'])['countries'][0]['label']);
            $gpc = new Request('GET', '/', [], [], [], ['cf-ipcountry' => 'FR', 'sec-gpc' => '1']);
            AnalyticsService::recordView((int) $this->link($result)['id'], 'ok', $gpc, 'h2');
            $this->assertSame(1, count(AnalyticsService::summary((int) $this->link($result)['id'])['countries']), 'Global Privacy Control skips country too');
        } finally {
            Config::set('app.analytics.country_header', $previous);
        }
    }

    public function testBlockedHashesRejectReuploads(): void
    {
        $png = $this->png(33, 21);
        Database::execute('INSERT INTO blocked_hashes (sha256, reason, created_at) VALUES (?, ?, ?)', [hash('sha256', $png), 'test', db_time()]);
        $this->assertHttpError('content_blocked', fn () => (new UploadService())->createImage($png, 'x.png', [], null), 422);
        Database::execute('DELETE FROM blocked_hashes WHERE sha256 = ?', [hash('sha256', $png)]);
        $this->assertTrue((new UploadService())->createImage($png, 'x.png', [], null)['upload']['id'] > 0);
    }

    public function testOpenApiDocumentDescribesTheApi(): void
    {
        $doc = OpenApi::document();
        $this->assertSame('3.1.0', $doc['openapi']);
        foreach (['/api/v1/uploads', '/api/v1/uploads/{id}', '/api/v1/links/{id}', '/api/v1/uploads/resumable', '/api/v1/webhooks', '/api/v1/usage', '/mcp'] as $path) {
            $this->assertTrue(isset($doc['paths'][$path]), "Spec includes $path");
        }
        $ids = [];
        array_walk_recursive($doc, static function ($value, $key) use (&$ids) {
            if ($key === 'operationId') {
                $ids[] = $value;
            }
        });
        $this->assertSame(count($ids), count(array_unique($ids)), 'operationIds are unique');
        // Every $ref resolves.
        $refs = [];
        array_walk_recursive($doc, static function ($value, $key) use (&$refs) {
            if ($key === '$ref') {
                $refs[] = $value;
            }
        });
        foreach (array_unique($refs) as $ref) {
            [, , $section, $name] = explode('/', $ref);
            $this->assertTrue(isset($doc['components'][$section][$name]), "Resolves $ref");
        }
        $this->assertTrue(json_encode($doc) !== false);
    }
}
