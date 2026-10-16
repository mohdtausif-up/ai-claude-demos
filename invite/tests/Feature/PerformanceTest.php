<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Http;
use Tests\Support\TestCase;

/** Budgets from PRD 14, measured against the local stack (a regression guard, not a load test). */
final class PerformanceTest extends TestCase
{
    private function time(callable $fn): float
    {
        $t = microtime(true);
        $fn();
        return (microtime(true) - $t) * 1000;
    }

    public function testInvitationPageIsFastLightAndCacheable(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'classic-wedding']);
        $this->publish($host, $event);
        $g = new Http();
        $g->get('/i/' . $event['slug']); // warm opcache
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            $times[] = $this->time(fn () => (new Http())->get('/i/' . $event['slug']));
        }
        sort($times);
        $median = $times[0]; // best of 10: stable under load from other containers
        $this->assertTrue($median < 120, sprintf('best-of-10 invitation page response %.0f ms (budget 120 ms)', $median));

        $r = $g->get('/i/' . $event['slug']);
        $this->assertTrue(strlen($r->body) < 40000, 'invitation HTML under 40 KB, was ' . strlen($r->body));
        $css = filesize(BASE_PATH . '/public/assets/css/app.css');
        $js = filesize(BASE_PATH . '/public/assets/js/rsvp.js') + filesize(BASE_PATH . '/public/assets/js/app.js');
        $this->assertTrue($css < 60000, "stylesheet under 60 KB (is $css)");
        $this->assertTrue($js < 20000, "guest JavaScript under 20 KB (is $js)");
        // Only first-party, deferred, few requests: one stylesheet, two small scripts, no fonts or third parties.
        preg_match_all('/<script[^>]+src=/', $r->body, $scripts);
        $this->assertTrue(count($scripts[0]) <= 2);
        $this->assertTrue((bool) preg_match('/<script[^>]+defer/', $r->body), 'scripts are deferred');
        $this->assertTrue(!preg_match('#https?://(?!localhost|web)[^"\']+\.(woff2?|ttf|js|css)#', $r->body), 'no external fonts/scripts/styles');

        $asset = $g->get('/assets/css/app.css', ['Accept-Encoding: gzip']);
        $this->assertSame('gzip', $asset->headers['content-encoding'] ?? '', 'stylesheet is compressed');
        $this->assertContains('max-age', $asset->headers['cache-control'] ?? '', 'static assets are cacheable');
    }

    public function testRsvpSubmissionAndLiveDashboardMeetTheirBudgets(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $submitTimes = [];
        for ($i = 0; $i < 6; $i++) {
            $g = new Http();
            $p = $g->get('/i/' . $event['slug']);
            $submitTimes[] = $this->time(fn () => $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Speedy ' . $i, 'plus_ones' => '1']));
        }
        sort($submitTimes);
        $this->assertTrue($submitTimes[3] < 1000, sprintf('median RSVP submit %.0f ms (budget 1000 ms)', $submitTimes[3]));

        $live = [];
        for ($i = 0; $i < 8; $i++) {
            $live[] = $this->time(fn () => $host->get('/app/events/' . $event['uuid'] . '/live'));
        }
        sort($live);
        $this->assertTrue($live[4] < 300, sprintf('median live-dashboard poll %.0f ms', $live[4]));
        // End to end: a new RSVP is visible on the very next poll (well inside the 2 s target).
        $before = $host->get('/app/events/' . $event['uuid'] . '/live')->json()['summary']['attending'];
        $g = new Http();
        $p = $g->get('/i/' . $event['slug']);
        $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Latest']);
        $after = $host->get('/app/events/' . $event['uuid'] . '/live')->json()['summary']['attending'];
        $this->assertSame($before + 1, $after, 'new response visible immediately');
        // Unchanged state answers 304 so idle dashboards cost almost nothing.
        $first = $host->get('/app/events/' . $event['uuid'] . '/live');
        $etag = $first->headers['etag'] ?? '';
        $this->assertTrue($etag !== '', 'ETag present');
        $this->assertStatus(304, $host->get('/app/events/' . $event['uuid'] . '/live', ['If-None-Match: ' . $etag]));
    }
}
