<?php

declare(strict_types=1);

namespace App\Jobs;

interface JobHandler
{
    /** @throws \Throwable to have the job retried with backoff */
    public function handle(array $payload): void;

    /** Called once when the job has exhausted its attempts. */
    public function failed(array $payload, string $error): void;
}
