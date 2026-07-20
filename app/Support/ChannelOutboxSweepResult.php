<?php

namespace App\Support;

final readonly class ChannelOutboxSweepResult
{
    /**
     * @param  list<int>  $failedMessageIds
     */
    public function __construct(
        public int $dispatched,
        public array $failedMessageIds = [],
    ) {}

    public function failed(): int
    {
        return count($this->failedMessageIds);
    }

    public function hasFailures(): bool
    {
        return $this->failedMessageIds !== [];
    }
}
