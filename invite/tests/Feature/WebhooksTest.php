<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container;
use App\Core\Db;
use App\Core\ValidationException;
use App\Services\LinkService;
use App\Services\TokenService;
use App\Services\WebhookService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class WebhooksTest extends TestCase
{
    private $proc;
    private string $sinkFile = '';

    private function startSink(): void
    {
        $this->sinkFile = sys_get_temp_dir() . '/sink-' . bin2hex(random_bytes(3)) . '.jsonl';
        $port = random_int(20000, 29000);
        $this->proc = proc_open(['php', '-S', "127.0.0.1:$port", BASE_PATH . '/tests/tools/webhook_sink.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['SINK_FILE' => $this->sinkFile, 'PATH' => getenv('PATH')]);
        $this->sinkUrl = "http://127.0.0.1:$port/hook";
        for ($i = 0; $i < 30; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100000);
        }
        $this->fail('sink did not start');
    }

    private string $sinkUrl = '';

    public function __destruct()
    {
        if ($this->proc) {
            proc_terminate($this->proc);
        }
        @unlink($this->sinkFile);
    }

    /** @return list<array{headers:array,body:array}> */
    private function received(): array
    {
        $out = [];
        foreach (file($this->sinkFile, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $r = json_decode($l, true);
            $out[] = ['headers' => $r['headers'], 'body' => json_decode($r['body'], true), 'raw' => $r['body']];
        }
        return $out;
    }

    private function deliverAll(): void
    {
        foreach (Db::column("SELECT id FROM webhook_deliveries WHERE status = 'pending' AND payload_json <> '' ORDER BY id") as $id) {
            try {
                Container::get(WebhookService::class)->deliver((int) $id);
            } catch (\RuntimeException) {
            }
        }
    }

    public function testSignedDeliveryForGuestRsvpOpenCheckinAndCapacityEvents(): void
    {
        $this->startSink();
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['capacity' => '1', 'plus_one_limit' => '0']);
        $this->publish($host, $event);
        $page = $host->get('/app/settings/webhooks');
        $r = $host->post('/app/settings/webhooks', ['_csrf' => $page->csrf(), 'workspace' => Db::value('SELECT uuid FROM workspaces WHERE id = ?', [$event['workspace_id']]), 'url' => $this->sinkUrl, 'events' => ['guest.created', 'rsvp.created', 'rsvp.updated', 'invitation.opened', 'guest.checked_in', 'event.capacity_reached']]);
        $this->assertRedirect('/app/settings/webhooks', $r);
        $secretPage = $host->follow();
        preg_match('/value="(whsec_[A-Za-z0-9_-]+)"/', $secretPage->body, $m);
        $secret = $m[1];
        $this->assertSame($secret, Db::value('SELECT secret FROM webhook_endpoints ORDER BY id DESC LIMIT 1'));

        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Hook', 'email' => 'hook' . bin2hex(random_bytes(3)) . '@example.test']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $links = new LinkService(new TokenService());
        $g = new Http();
        $path = '/g/' . $links->guestToken($eg);
        $g->get($path . '?c=wa');
        $p = $g->get($path);
        $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending']);
        $p = $g->get($path);
        $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'maybe']);
        $p = $g->get($path);
        $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending']);
        $host->get($base . '/checkin');
        $qr = $links->qrToken($eg);
        $host->postJson($base . '/checkin/complete', ['code' => $qr], ['X-CSRF-Token: ' . $host->csrf(), 'X-Requested-With: fetch']);

        $this->deliverAll();
        $got = $this->received();
        $types = array_column(array_column($got, 'body'), 'type');
        foreach (['guest.created', 'invitation.opened', 'rsvp.created', 'rsvp.updated', 'event.capacity_reached', 'guest.checked_in'] as $t) {
            $this->assertTrue(in_array($t, $types, true), "received $t (got " . implode(',', $types) . ')');
        }
        // Signature: HMAC-SHA256 over "<t>.<body>" with the endpoint secret.
        foreach ($got as $d) {
            $this->assertTrue((bool) preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $d['headers']['x-invite-signature'] ?? '', $sm), 'signature header format');
            $this->assertSame(hash_hmac('sha256', $sm[1] . '.' . $d['raw'], $secret), $sm[2], 'signature verifies');
            $this->assertSame($d['body']['type'], $d['headers']['x-invite-event']);
        }
        $created = array_values(array_filter($got, fn ($d) => $d['body']['type'] === 'rsvp.created'))[0]['body']['data'];
        $this->assertSame($event['uuid'], $created['event_id']);
        $this->assertSame($eg['uuid'], $created['guest_id'], 'public UUIDs only');
        $this->assertSame(1, $created['headcount']);
        $this->assertTrue(!isset($created['id']) || !is_int($created['id']), 'no sequential ids');
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id WHERE e.workspace_id = ? AND d.status = 'pending'", [$event['workspace_id']]), 'everything delivered');
    }

    public function testOnlySubscribedEventsAreSentAndFailuresAreRetriedThenMarkedFailed(): void
    {
        $this->startSink();
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        Container::get(WebhookService::class)->create((int) $event['workspace_id'], $this->sinkUrl . '?status=500', ['guest.created']);
        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Retry', 'email' => 'r' . bin2hex(random_bytes(3)) . '@example.test']);
        $mine = 'SELECT COUNT(*) FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id WHERE e.workspace_id = ?';
        $this->assertSame(1, (int) Db::value($mine . ' AND d.event_type = ?', [$event['workspace_id'], 'guest.created']));
        $this->assertSame(0, (int) Db::value($mine . " AND d.event_type <> 'guest.created'", [$event['workspace_id']]), 'unsubscribed event types are not queued');
        $id = (int) Db::value('SELECT id FROM webhook_deliveries ORDER BY id DESC LIMIT 1');
        $svc = Container::get(WebhookService::class);
        try {
            $svc->deliver($id);
            $this->fail('500 must raise so the queue retries');
        } catch (\RuntimeException $e) {
            $this->assertContains('HTTP 500', $e->getMessage());
        }
        $row = Db::one('SELECT * FROM webhook_deliveries WHERE id = ?', [$id]);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame(500, (int) $row['response_code']);
        $job = Db::one("SELECT * FROM notification_jobs WHERE type = 'webhook.deliver' ORDER BY id DESC LIMIT 1");
        $this->assertSame(6, (int) $job['max_attempts'], 'queued with retries');
        $svc->markFailed($id);
        $this->assertSame('failed', Db::value('SELECT status FROM webhook_deliveries WHERE id = ?', [$id]));
        $this->assertContains('Failed', $host->get('/app/settings/webhooks')->body);
    }

    public function testUrlsPointingAtInternalAddressesAreRefused(): void
    {
        $svc = Container::get(WebhookService::class);
        putenv('WEBHOOK_ALLOW_PRIVATE=0');
        try {
            foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.5/hook', 'http://192.168.1.1/', 'http://[::1]/', 'ftp://example.com/x', 'http://user:pw@example.com/', 'javascript:alert(1)', 'not a url'] as $bad) {
                try {
                    $svc->assertSafeUrl($bad);
                    $this->fail("accepted $bad");
                } catch (ValidationException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            putenv('WEBHOOK_ALLOW_PRIVATE=1');
        }
    }

    public function testOnlyOwnersAndAdminsManageWebhooks(): void
    {
        [$owner] = $this->newHost();
        $event = $this->newEvent($owner);
        [$editor, $email] = $this->newHost();
        $team = '/app/events/' . $event['uuid'] . '/collaborators';
        $owner->submit($team, $team, ['email' => $email, 'role' => 'editor']);
        $page = $editor->get('/app/settings/webhooks');
        $ws = Db::value('SELECT uuid FROM workspaces WHERE id = ?', [$event['workspace_id']]);
        $editor->post('/app/settings/webhooks', ['_csrf' => $page->csrf(), 'workspace' => $ws, 'url' => 'http://127.0.0.1:9/x', 'events' => ['guest.created']]);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM webhook_endpoints WHERE workspace_id = ?', [$event['workspace_id']]), 'an editor cannot add webhooks to someone else\'s workspace');
    }
}