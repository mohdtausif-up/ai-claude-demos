<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UploadService;
use App\Support\Database;
use Tests\TestCase;

/** Acceptance: burn-after-view access is atomic under concurrent requests. */
final class ConcurrencyTest extends TestCase
{
    private const WORKERS = 16;

    private function race(int $linkId): array
    {
        $script = __DIR__ . '/../support/consume.php';
        $start = microtime(true) + 1.0; // all workers fire at the same instant
        $procs = [];
        $outputs = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $cmd = sprintf('%s %s %d %F %d', PHP_BINARY, escapeshellarg($script), $linkId, $start, $i);
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outputs[$i] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($outputs[$i][1]);
            $err = stream_get_contents($outputs[$i][2]);
            proc_close($proc);
            $decoded = json_decode(trim($out), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException("Worker $i failed: $out $err");
            }
            $results[] = $decoded;
        }
        return $results;
    }

    public function testBurnAfterViewIsAtomic(): void
    {
        $result = (new UploadService())->createText('only one of you will see this', null, ['burn_after_view' => true], null);
        $results = $this->race((int) $result['link']['id']);
        $allowed = array_filter($results, static fn ($r) => $r['allowed']);
        $this->assertSame(1, count($allowed), 'Exactly one concurrent request may view a burn-after-view share');
        $link = $this->link($result);
        $this->assertSame('consumed', $link['status']);
        $this->assertSame(1, (int) $link['view_count']);
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM view_events WHERE share_link_id = ? AND outcome = 'ok'", [$link['id']]));
    }

    public function testViewLimitIsNeverExceeded(): void
    {
        $result = (new UploadService())->createText('three views', null, ['view_limit' => 3, 'view_limit_type' => 'total'], null);
        $results = $this->race((int) $result['link']['id']);
        $this->assertSame(3, count(array_filter($results, static fn ($r) => $r['allowed'])));
        $this->assertSame(3, (int) $this->link($result)['view_count']);
    }
}
