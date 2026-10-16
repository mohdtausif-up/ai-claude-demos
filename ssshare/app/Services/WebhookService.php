<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Crypto;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use Throwable;

/**
 * Outgoing webhooks. Generic hooks receive the JSON event signed with
 * `X-SSShare-Signature: t=<unix>,v1=<hex hmac-sha256(secret, "<t>.<body>")>`.
 * Slack and Microsoft Teams hooks receive a human-readable message instead.
 */
final class WebhookService
{
    public const MAX_ATTEMPTS = 8;

    /** @return array{secret:string, row:array} */
    public function create(array $user, string $url, array $events, string $kind = 'generic', ?int $workspaceId = null): array
    {
        PlanService::require($user, 'webhooks', 'Webhooks');
        $url = trim($url);
        $errors = [];
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 1000) {
            $errors['url'] = 'Enter a valid http(s) URL.';
        } elseif (!self::hostAllowed((string) parse_url($url, PHP_URL_HOST))) {
            $errors['url'] = 'Webhook URLs must point to a public internet address.';
        }
        $events = array_values(array_intersect(array_keys(EventService::EVENTS), $events)) ?: (in_array('*', $events, true) ? ['*'] : []);
        if ($events === []) {
            $errors['events'] = 'Choose at least one event.';
        }
        if (!in_array($kind, ['generic', 'slack', 'teams'], true)) {
            $errors['kind'] = 'Choose a webhook type.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ((int) Database::value('SELECT COUNT(*) FROM webhooks WHERE user_id = ?', [$user['id']]) >= 25) {
            throw HttpException::validation(['url' => 'You can have up to 25 webhooks.']);
        }
        $secret = 'whsec_' . Ids::token(24);
        $id = Database::insert('webhooks', [
            'public_id' => Ids::publicId(12), 'user_id' => $user['id'], 'workspace_id' => $workspaceId, 'kind' => $kind,
            'url' => $url, 'secret' => Crypto::encrypt($secret, 'webhook'), 'events' => json_encode($events),
            'created_at' => db_time(), 'updated_at' => db_time(),
        ]);
        $row = Database::first('SELECT * FROM webhooks WHERE id = ?', [$id]);
        AuditLogService::record('webhook.created', 'webhook', $row['public_id'], ['kind' => $kind, 'events' => $events, 'host' => parse_url($url, PHP_URL_HOST)]);
        return ['secret' => $secret, 'row' => $row];
    }

    public function delete(array $user, string $publicId): void
    {
        if (Database::execute('DELETE FROM webhooks WHERE public_id = ? AND user_id = ?', [$publicId, $user['id']]) === 0) {
            throw HttpException::notFound('Webhook not found.');
        }
        AuditLogService::record('webhook.deleted', 'webhook', $publicId);
    }

    /** Blocks loopback, private, link-local and metadata addresses (SSRF), unless explicitly allowed. */
    public static function hostAllowed(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        if (config('app.webhooks_allow_private')) {
            return true;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** Receiver-side verification helper (also documented for customers). */
    public static function verify(string $secret, string $header, string $body, int $tolerance = 300): bool
    {
        if (!preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $m) || abs(time() - (int) $m[1]) > $tolerance) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, $secret), $m[2]);
    }

    /** Delivers due webhooks. Returns [delivered, failed]. */
    public function deliverDue(int $limit = 50): array
    {
        $ok = $failed = 0;
        $due = Database::select(
            "SELECT d.*, w.url, w.secret, w.kind, w.public_id AS webhook_public_id FROM webhook_deliveries d
             JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.status = 'pending' AND d.next_attempt_at <= ? AND w.active = 1 ORDER BY d.id LIMIT $limit",
            [db_time()]
        );
        foreach ($due as $delivery) {
            $this->attempt($delivery) ? $ok++ : $failed++;
        }
        return [$ok, $failed];
    }

    public function attempt(array $delivery): bool
    {
        $attempts = (int) $delivery['attempts'] + 1;
        $status = 0;
        $response = '';
        try {
            [$body, $contentType] = $this->format($delivery);
            $timestamp = now()->getTimestamp();
            $secret = Crypto::decrypt($delivery['secret'], 'webhook');
            [$status, $response] = $this->post($delivery['url'], $body, [
                'Content-Type: ' . $contentType,
                'User-Agent: SSShare-Webhooks/1.0',
                'X-SSShare-Event: ' . $delivery['event'],
                'X-SSShare-Delivery: ' . $delivery['public_id'],
                'X-SSShare-Signature: ' . self::sign($secret, $timestamp, $body),
            ]);
        } catch (Throwable $e) {
            $response = $e->getMessage();
        }
        $success = $status >= 200 && $status < 300;
        $final = $success || $attempts >= self::MAX_ATTEMPTS;
        $backoff = [60, 300, 1800, 7200, 21600, 43200, 86400][$attempts - 1] ?? 86400;
        Database::update('webhook_deliveries', [
            'status' => $success ? 'success' : ($final ? 'failed' : 'pending'),
            'attempts' => $attempts,
            'response_code' => $status ?: null,
            'response_body' => substr($response, 0, 2000),
            'next_attempt_at' => db_time(now()->modify("+{$backoff} seconds")),
            'delivered_at' => $success ? db_time() : null,
        ], 'id = ?', [$delivery['id']]);
        if ($success) {
            Database::execute('UPDATE webhooks SET failure_count = 0, last_success_at = ? WHERE id = ?', [db_time(), $delivery['webhook_id']]);
        } else {
            // A hook that has failed 50 times in a row is switched off.
            Database::execute('UPDATE webhooks SET failure_count = failure_count + 1, active = IF(failure_count + 1 >= 50, 0, active) WHERE id = ?', [$delivery['webhook_id']]);
        }
        return $success;
    }

    public function redeliver(array $user, string $deliveryPublicId): void
    {
        $delivery = Database::first(
            'SELECT d.* FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.public_id = ? AND (w.user_id = ? OR ? = 1)',
            [$deliveryPublicId, $user['id'], $user['role'] === 'admin' ? 1 : 0]
        ) ?? throw HttpException::notFound('Delivery not found.');
        Database::update('webhook_deliveries', ['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => db_time()], 'id = ?', [$delivery['id']]);
    }

    /** @return array{0:string, 1:string} body and content type */
    private function format(array $delivery): array
    {
        if ($delivery['kind'] === 'generic') {
            return [$delivery['payload'], 'application/json'];
        }
        $event = json_decode($delivery['payload'], true);
        $upload = $event['data']['upload'];
        $label = $upload['title'] ?: ucfirst($upload['type']);
        $text = sprintf('*SSShare* · %s · %s', EventService::EVENTS[$event['type']] ?? $event['type'], $label);
        if (!empty($event['data']['link'])) {
            $text .= ' · ' . $event['data']['link'];
        }
        if (!empty($event['data']['comment'])) {
            $text .= "\n> " . mb_substr($event['data']['comment'], 0, 300);
        }
        $body = $delivery['kind'] === 'slack'
            ? ['text' => $text]
            : ['@type' => 'MessageCard', '@context' => 'https://schema.org/extensions', 'summary' => 'SSShare', 'text' => str_replace('*', '**', $text)];
        return [json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json'];
    }

    /** @return array{0:int, 1:string} */
    private function post(string $url, string $body, array $headers): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!self::hostAllowed($host)) {
            throw new \RuntimeException('Destination address is not allowed');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        // Pin the vetted address so DNS cannot change between the check and the request.
        if (!filter_var($host, FILTER_VALIDATE_IP) && !config('app.webhooks_allow_private')) {
            $ip = gethostbyname($host);
            $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https') ? 443 : 80);
            $opts[CURLOPT_RESOLVE] = ["$host:$port:$ip"];
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$status, $response === false ? $error : (string) $response];
    }
}
