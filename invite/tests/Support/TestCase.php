<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Db;

abstract class TestCase
{
    public int $assertions = 0;

    protected function fail(string $message): never
    {
        throw new \AssertionError($message);
    }

    protected function assertTrue(mixed $cond, string $msg = 'Expected true'): void
    {
        $this->assertions++;
        if (!$cond) {
            $this->fail($msg);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $this->fail(($msg ?: 'Expected to find') . ': "' . $needle . '" in ' . substr(strip_tags($haystack), 0, 300));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            $this->fail(($msg ?: 'Did not expect to find') . ': "' . $needle . '"');
        }
    }

    protected function assertStatus(int $expected, Http $http, string $msg = ''): void
    {
        $this->assertions++;
        if ($http->status !== $expected) {
            $this->fail(($msg ? "$msg: " : '') . "expected HTTP $expected, got {$http->status} (" . substr(trim(strip_tags($http->body)), 0, 200) . ')');
        }
    }

    protected function assertRedirect(string $startsWith, Http $http): void
    {
        $this->assertions++;
        if (!in_array($http->status, [301, 302, 303, 307], true) || !str_starts_with($http->location(), $startsWith)) {
            $this->fail("expected redirect to $startsWith, got HTTP {$http->status} Location: " . $http->location() . ' ' . substr(trim(strip_tags($http->body)), 0, 150));
        }
    }

    // ---- Fixtures ----------------------------------------------------------------------------------------------

    /** Register a fresh host and return [Http (logged in), email]. */
    protected function newHost(string $name = 'Test Host'): array
    {
        $email = 'host' . bin2hex(random_bytes(4)) . '@example.test';
        Db::exec('DELETE FROM rate_limits'); // tests register many hosts from one IP
        $http = new Http();
        $r = $http->submit('/register', '/register', ['name' => $name, 'email' => $email, 'password' => 'correct horse battery', 'accept_terms' => '1']);
        $this->assertRedirect('/app', $r);
        return [$http, $email];
    }

    /** Create an event as $host (logged-in Http); returns the event row. */
    protected function newEvent(Http $host, array $over = []): array
    {
        $fields = $over + [
            'template' => 'party-time', 'title' => 'Test Party ' . bin2hex(random_bytes(3)), 'category' => 'birthday',
            'start_at' => gmdate('Y-m-d\TH:i', strtotime('+10 days')), 'timezone' => 'Asia/Kolkata', 'language' => 'en',
            'visibility' => 'public', 'guest_list_visibility' => 'hidden', 'rsvp_open' => '1', 'allow_maybe' => '1', 'allow_waitlist' => '1',
            'allow_response_edit' => '1', 'collect_email' => '1', 'plus_one_limit' => '2', 'allow_children' => '1', 'max_children' => '3',
            'venue_name' => 'Hall', 'venue_address' => '1 Test Road',
        ];
        $r = $host->submit('/app/events/new', '/app/events', $fields);
        $this->assertRedirect('/app/events/', $r);
        $uuid = basename(parse_url($r->location(), PHP_URL_PATH));
        $event = Db::one('SELECT * FROM events WHERE uuid = ?', [$uuid]);
        $this->assertTrue($event !== null, 'event row created');
        return $event;
    }

    protected function publish(Http $host, array $event): void
    {
        $r = $host->submit('/app/events/' . $event['uuid'], '/app/events/' . $event['uuid'] . '/publish');
        $this->assertRedirect('/app/events/', $r);
    }

    /** Pretend the queue worker ran: drain due jobs synchronously. */
    protected function runWorker(): void
    {
        exec('php ' . escapeshellarg(BASE_PATH . '/bin/worker.php') . ' --once 2>&1', $out, $code);
        if ($code !== 0) {
            $this->fail('worker failed: ' . implode("\n", $out));
        }
    }

    protected function mailpit(string $path): array
    {
        $ch = curl_init('http://mailpit:8025/api/v1/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $res = (string) curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true) ?? [];
    }

    /** Find the newest Mailpit message sent to $email (waiting briefly), or null. */
    protected function mailTo(string $email): ?array
    {
        for ($i = 0; $i < 10; $i++) {
            $res = $this->mailpit('search?query=' . rawurlencode('to:' . $email));
            if (!empty($res['messages'][0])) {
                return $this->mailpit('message/' . $res['messages'][0]['ID']);
            }
            usleep(300000);
        }
        return null;
    }
}
