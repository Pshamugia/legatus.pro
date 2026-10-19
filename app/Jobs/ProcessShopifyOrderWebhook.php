<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Compatibility handler for order jobs queued before attribution was retired. */
class ProcessShopifyOrderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(public int $connectionId, public array $event) {}

    public function handle(): void
    {
        // Order attribution is no longer collected.
    }
}
