<?php

declare(strict_types=1);

namespace App\Services;

/** Housekeeping and time-based automation. Called by the worker about once a minute; every task is idempotent. */
final class Scheduler
{
    public function __construct(
        private RateLimiter $limiter,
        private QueueService $queue,
        private RetentionService $retention,
        private PollService $polls,
        private AutomationService $automation,
        private ReportService $reports
    ) {
    }

    public function tick(): void
    {
        $this->limiter->purgeExpired();
        $this->queue->purgeOld();
        $this->retention->purgeDeletedEvents();
        $this->polls->closeDue();
        $this->automation->run();
        $this->reports->sendDue();
    }
}
