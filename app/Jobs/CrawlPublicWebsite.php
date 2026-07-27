<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\PublicWebsiteCrawler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CrawlPublicWebsite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct(public int $sourceId) {}

    public function handle(PublicWebsiteCrawler $crawler): void
    {
        $source = KnowledgeSource::find($this->sourceId);

        if ($source?->type === 'url' && $source->isRefreshable() && (int) $source->progress <= 1) {
            $crawler->crawl($source);
        }
    }
}
