<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EmbedKnowledgeSource implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $sourceId) {}

    public function handle(EmbeddingService $embeddings): void
    {
        $source = KnowledgeSource::find($this->sourceId);
        if (! $source || $source->status !== 'processing') {
            return;
        }

        $processed = $embeddings->embedPendingBatch($source, 10);
        $remaining = $source->chunks()->whereNull('embedding')->count();
        $total = max(1, $source->chunks()->count());

        if ($remaining === 0 || $processed === 0) {
            $source->update([
                'status' => 'ready',
                'progress' => 100,
                'last_synced_at' => now(),
                'error' => null,
            ]);

            return;
        }

        $completed = $total - $remaining;
        $source->update([
            'progress' => min(99, 96 + (int) floor(($completed / $total) * 3)),
        ]);
        self::dispatch($source->id);
    }

    public function failed(?Throwable $exception): void
    {
        KnowledgeSource::whereKey($this->sourceId)->update([
            'status' => 'ready',
            'progress' => 100,
            'error' => 'Semantic indexing could not finish on this server. Lexical search remains available.',
            'last_synced_at' => now(),
        ]);
    }
}
