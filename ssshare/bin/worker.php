<?php

declare(strict_types=1);

// Dedicated deletion worker for a separate worker pool. The scheduler also processes
// deletion jobs, so this is only needed when scaling cleanup independently.
require __DIR__ . '/../bootstrap.php';

use App\Services\DeletionService;
use App\Support\Database;

$deletion = new DeletionService();
fwrite(STDOUT, "SSShare deletion worker started\n");

while (true) {
    try {
        [$done, $failed] = $deletion->runDue(200);
        if ($done || $failed) {
            fwrite(STDOUT, '[' . gmdate('c') . "] purged=$done failed=$failed\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . gmdate('c') . '] worker error: ' . $e->getMessage() . "\n");
        Database::reconnect();
    }
    sleep(5);
}
