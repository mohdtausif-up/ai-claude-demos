<?php

declare(strict_types=1);

// Worker for ConcurrencyTest: waits until a shared start time, then tries to view a link.
putenv('DB_DATABASE=' . (getenv('TEST_DB_DATABASE') ?: 'ssshare_test'));
require __DIR__ . '/../../bootstrap.php';

[, $linkId, $startAt, $worker] = $argv;
$_SERVER['REMOTE_ADDR'] = '10.0.' . intdiv((int) $worker, 250) . '.' . ((int) $worker % 250 + 1);
App\Support\Database::pdo(); // connect before the start line

while (microtime(true) < (float) $startAt) {
    usleep(200);
}
$request = new App\Support\Request('GET', '/', [], [], [], ['user-agent' => 'Worker/' . $worker]);
$result = (new App\Services\ExpirationService())->access((int) $linkId, 'view', $request);
echo json_encode(['allowed' => $result['allowed'], 'reason' => $result['reason']]), "\n";
