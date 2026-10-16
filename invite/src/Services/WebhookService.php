<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;
use RuntimeException;

/**
 * Outbound webhooks. Events are queued per subscribed endpoint, signed with HMAC-SHA256
 * (header X-Invite-Signature: t=<unix>,v1=<hex of HMAC(secret, "<t>.<body>")>) and retried with backoff.
 * Target URLs are checked against private/loopback ranges (SSRF) at creation and again at delivery.
 */
final class WebhookService
{
    public const EVENTS = [
        'guest.created' => 'A guest was added', 'invitation.sent' => 'An invitation email was sent', 'invitation.opened' => 'A guest opened their invitation',
        'rsvp.created' => 'A guest responded', 'rsvp.updated' => 'A guest changed their response', 'guest.checked_in' => 'A guest was checked in',
        'event.capacity_reached' => 'An event reached capacity',
    ];

    public function __construct(private QueueService $queue)
    {
    }

    public function endpoints(int $workspaceId): array
    {
        $rows = Db::all('SELECT * FROM webhook_endpoints WHERE workspace_id = ? ORDER BY id DESC', [$workspaceId]);
        foreach ($rows as &$r) {
            $r['events'] = json_decode((string) $r['events_json'], true) ?: [];
        }
        return $rows;
    }

    /** @return array{uuid:string,secret:string} the secret is shown to the user once */
    public function create(int $workspaceId, string $url, array $events): array
    {
        $this->assertSafeUrl($url);
        $events = array_values(array_intersect(array_keys(self::EVENTS), $events));
        if (!$events) {
            throw new ValidationException(['events' => ['Choose at least one event.']]);
        }
        $secret = 'whsec_' . base64url(random_bytes(24));
        $uuid = uuid4();
        Db::insert('webhook_endpoints', ['uuid' => $uuid, 'workspace_id' => $workspaceId, 'url' => $url, 'secret' => $secret, 'events_json' => json_out($events), 'created_at' => utcnow()]);
        return ['uuid' => $uuid, 'secret' => $secret];
    }

    public function delete(int $workspaceId, string $uuid): void
    {
        Db::exec('DELETE FROM webhook_endpoints WHERE workspace_id = ? AND uuid = ?', [$workspaceId, $uuid]);
    }

    /** Resolve and reject anything that is not a public http(s) destination. */
    public function assertSafeUrl(string $url): void
    {
        $p = parse_url($url);
        $err = static fn (string $m) => new ValidationException(['url' => [$m]]);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || strlen($url) > 500) {
            throw $err('Enter a valid http(s) URL without credentials.');
        }
        if (env('WEBHOOK_ALLOW_PRIVATE') === '1') {
            return;
        }
        $ips = filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : (gethostbynamel($p['host']) ?: []);
        if (!$ips) {
            throw $err('That host name does not resolve.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw $err('Webhook URLs must point to a public address, not an internal one.');
            }
        }
    }

    /** Queue $type for every active endpoint of the workspace that subscribed to it. */
    public function dispatch(int $workspaceId, string $type, array $data): void
    {
        foreach (Db::all('SELECT id, events_json FROM webhook_endpoints WHERE workspace_id = ? AND active = 1', [$workspaceId]) as $ep) {
            if (!in_array($type, json_decode((string) $ep['events_json'], true) ?: [], true)) {
                continue;
            }
            $payload = json_out(['id' => uuid4(), 'type' => $type, 'created_at' => gmdate('c'), 'data' => $data]);
            $id = Db::insert('webhook_deliveries', ['endpoint_id' => $ep['id'], 'event_type' => $type, 'payload_json' => $payload, 'created_at' => utcnow()]);
            $this->queue->push('webhook.deliver', ['delivery_id' => $id], 0, 6);
        }
    }

    /** One HTTP attempt. @throws RuntimeException on a non-2xx answer or network failure (the queue retries) */
    public function deliver(int $deliveryId): void
    {
        $d = Db::one('SELECT d.*, e.url, e.secret, e.active FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id WHERE d.id = ?', [$deliveryId]);
        if (!$d || $d['status'] === 'delivered' || !(int) $d['active']) {
            return;
        }
        $this->assertSafeUrl($d['url']);
        $ts = time();
        $body = (string) $d['payload_json'];
        $sig = hash_hmac('sha256', $ts . '.' . $body, $d['secret']);
        $ch = curl_init($d['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Invite.com-Webhooks/1.0',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Invite-Event: ' . $d['event_type'], 'X-Invite-Delivery: ' . $d['id'], 'X-Invite-Signature: t=' . $ts . ',v1=' . $sig],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        Db::exec('UPDATE webhook_deliveries SET attempts = attempts + 1, response_code = ? WHERE id = ?', [$code ?: null, $deliveryId]);
        if ($code >= 200 && $code < 300) {
            Db::exec("UPDATE webhook_deliveries SET status = 'delivered', delivered_at = ?, last_error = NULL WHERE id = ?", [utcnow(), $deliveryId]);
            return;
        }
        $msg = $err !== '' ? $err : "HTTP $code";
        Db::exec('UPDATE webhook_deliveries SET last_error = ? WHERE id = ?', [mb_substr($msg, 0, 290), $deliveryId]);
        throw new RuntimeException("Webhook delivery failed: $msg");
    }

    public function markFailed(int $deliveryId): void
    {
        Db::exec("UPDATE webhook_deliveries SET status = 'failed' WHERE id = ? AND status = 'pending'", [$deliveryId]);
    }

    public function recent(int $workspaceId): array
    {
        return Db::all(
            'SELECT d.id, d.event_type, d.status, d.attempts, d.response_code, d.last_error, d.created_at, e.url FROM webhook_deliveries d JOIN webhook_endpoints e ON e.id = d.endpoint_id
             WHERE e.workspace_id = ? ORDER BY d.id DESC LIMIT 25',
            [$workspaceId]
        );
    }

    /** Helper used by services: workspace id of an event row. */
    public static function guestPayload(array $event, array $eg, array $extra = []): array
    {
        return ['event_id' => $event['uuid'], 'guest_id' => $eg['uuid'], 'first_name' => $eg['first_name'] ?? null, 'last_name' => $eg['last_name'] ?? null, 'email' => $eg['email'] ?? null] + $extra;
    }
}