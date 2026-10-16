<?php

declare(strict_types=1);

// Long-running scheduler (run under Supervisor/systemd or the docker "scheduler" service).
// Runs a cleanup pass every 30 seconds so expirations are never more than ~1 minute late.
require __DIR__ . '/../bootstrap.php';

use App\Jobs\CleanupJob;
use App\Support\Database;

$interval = (int) ($argv[1] ?? 30);
fwrite(STDOUT, "SSShare scheduler started (every {$interval}s)\n");

while (true) {
    $started = microtime(true);
    try {
        $result = (new CleanupJob())->run();
        if ($result['ended'] || $result['purged'] || $result['failed'] || $result['overdueLinks'] || $result['overdueJobs']) {
            fwrite(STDOUT, '[' . gmdate('c') . '] ' . json_encode($result) . "\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . gmdate('c') . '] cleanup failed: ' . $e->getMessage() . "\n");
        Database::reconnect();
    }
    $sleep = $interval - (microtime(true) - $started);
    if ($sleep > 0) {
        usleep((int) ($sleep * 1_000_000));
    }
}
