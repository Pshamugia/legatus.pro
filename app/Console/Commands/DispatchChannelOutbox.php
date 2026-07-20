<?php

namespace App\Console\Commands;

use App\Services\ChannelOutboxService;
use Illuminate\Console\Command;

class DispatchChannelOutbox extends Command
{
    protected $signature = 'legatus:dispatch-channel-outbox';

    protected $description = 'Re-dispatch stranded channel messages and purge expired Meta OAuth selections';

    public function handle(ChannelOutboxService $outbox): int
    {
        $result = $outbox->sweep();
        $this->info("Dispatched {$result->dispatched} eligible channel message(s).");

        if ($result->hasFailures()) {
            $ids = implode(', ', $result->failedMessageIds);
            $this->error("Failed to enqueue {$result->failed()} channel message(s). Durable rows remain pending for retry. Message IDs: {$ids}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
