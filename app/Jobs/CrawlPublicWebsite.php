<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\PublicWebsiteCrawler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CrawlPublicWebsite implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public function __construct(public int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(PublicWebsiteCrawler $crawler): void
    {
        $source = KnowledgeSource::find($this->sourceId);

        if ($source?->type === 'url' && $source->isRefreshable()) {
            $crawler->crawl($source);
        }
    }
}
