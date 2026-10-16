<?php

declare(strict_types=1);

// Queue worker + scheduler. Runs forever (docker `worker` service).
// Usage: php bin/worker.php [--once]   (--once: drain due jobs, run the scheduler once, exit; used by tests)

require __DIR__ . '/../bootstrap.php';

use App\Core\Container;
use App\Core\Db;
use App\Jobs\JobHandler;
use App\Services\QueueService;
use App\Services\Scheduler;

const JOB_HANDLERS = [
    'email.send' => App\Jobs\EmailJob::class,
    'import.process' => App\Jobs\ImportJob::class,
    'webhook.deliver' => App\Jobs\WebhookJob::class,
];

$once = in_array('--once', $argv ?? [], true);
$queue = Container::get(QueueService::class);
$scheduler = Container::get(Scheduler::class);
$lastTick = 0;
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running) { $running = false; });
}

echo "[worker] started\n";
while ($running) {
    try {
        if (time() - $lastTick >= 60 || $once) {
            $queue->reclaimStale();
            $scheduler->tick();
            $lastTick = time();
        }
        $job = $queue->reserve();
        if (!$job) {
            if ($once) {
                break;
            }
            sleep(2);
            continue;
        }
        $class = JOB_HANDLERS[$job['type']] ?? null;
        if (!$class) {
            $queue->fail(array_merge($job, ['max_attempts' => 1]), 'Unknown job type ' . $job['type']);
            continue;
        }
        /** @var JobHandler $handler */
        $handler = Container::get($class);
        try {
            $handler->handle($job['payload']);
            $queue->complete((int) $job['id']);
        } catch (Throwable $e) {
            $final = $queue->fail($job, $e->getMessage());
            fwrite(STDERR, sprintf("[worker] job %d (%s) failed attempt %d: %s\n", $job['id'], $job['type'], $job['attempts'], $e->getMessage()));
            if ($final) {
                try {
                    $handler->failed($job['payload'], $e->getMessage());
                } catch (Throwable $inner) {
                    fwrite(STDERR, '[worker] failure handler error: ' . $inner->getMessage() . "\n");
                }
            }
        }
    } catch (Throwable $e) {
        // Database hiccup etc.: back off, reconnect, keep the worker alive.
        fwrite(STDERR, '[worker] error: ' . $e->getMessage() . "\n");
        Db::reset();
        if ($once) {
            exit(1);
        }
        sleep(5);
    }
}
echo "[worker] stopped\n";
