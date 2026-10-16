<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WebhookService;

final class WebhookJob implements JobHandler
{
    public function __construct(private WebhookService $webhooks)
    {
    }

    public function handle(array $p): void
    {
        $this->webhooks->deliver((int) $p['delivery_id']);
    }

    public function failed(array $p, string $error): void
    {
        $this->webhooks->markFailed((int) $p['delivery_id']);
    }
}