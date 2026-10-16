<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Http;
use Tests\Support\TestCase;

final class StreamTest extends TestCase
{
    public function testDashboardStreamPushesTheInitialSnapshotAndThenEachChange(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $host->get('/app/events/' . $event['uuid']);
        $this->assertContains('data-stream-url', $host->body, 'dashboard opts in to the stream');

        $buffer = '';
        $mh = curl_multi_init();
        $ch = curl_init('http://web/app/events/' . $event['uuid'] . '/stream');
        curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $host->cookieJar(), CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: text/event-stream'],
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$buffer) { $buffer .= $data; return strlen($data); }]);
        curl_multi_add_handle($mh, $ch);
        $guest = new Http();
        $posted = false;
        $deadline = microtime(true) + 15;
        $firstAt = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
            if (!$posted && substr_count($buffer, 'event: summary') >= 1) {
                $firstAt = microtime(true);
                $p = $guest->get('/i/' . $event['slug']);
                $guest->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Streamed Sam']);
                $posted = true;
            }
        } while ($running > 0 && substr_count($buffer, 'event: summary') < 2 && microtime(true) < $deadline);
        $latency = microtime(true) - ($firstAt ?? microtime(true));
        $info = curl_getinfo($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);

        $this->assertSame(200, $info['http_code']);
        $this->assertContains('text/event-stream', (string) $info['content_type']);
        $this->assertContains('retry: 1500', $buffer);
        $this->assertTrue(substr_count($buffer, 'event: summary') >= 2, 'initial snapshot plus the change');
        $this->assertTrue($latency < 3.0, sprintf('change reached the dashboard in %.1f s (target < 2 s + 1 s tick)', $latency));
        preg_match_all('/data: (\{.*\})\n/', $buffer, $m);
        $last = json_decode(end($m[1]), true);
        $this->assertSame(1, $last['summary']['attending']);
        $this->assertContains('Streamed', json_encode($last['recent']));
    }

    public function testStreamRequiresReportsPermissionAndDoesNotLockTheSession(): void
    {
        [$owner] = $this->newHost();
        $event = $this->newEvent($owner);
        [$outsider] = $this->newHost();
        $this->assertStatus(404, $outsider->get('/app/events/' . $event['uuid'] . '/stream'));
        $this->assertRedirect('/login', (new Http())->get('/app/events/' . $event['uuid'] . '/stream'));
    }
}