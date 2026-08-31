<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\PublicWebsiteCrawler;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class CrawlPublicWebsite implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('knowledge');
    }

    public function uniqueId(): string
    {
        return "knowledge-source:{$this->sourceId}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(PublicWebsiteCrawler $crawler): void
    {
        $source = KnowledgeSource::find($this->sourceId);

        if ($source?->type === 'url' && $source->isRefreshable()) {
            $crawler->crawl($source);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $source = KnowledgeSource::find($this->sourceId);
        if (! $source) {
            return;
        }

        $source->update([
            'status' => $source->chunks()->exists() ? 'ready' : 'failed',
            'progress' => $source->chunks()->exists() ? max(1, (int) $source->progress) : 0,
            'error' => 'Website synchronization stopped safely. Existing searchable knowledge remains available; retry the source shortly.',
        ]);
    }
}
