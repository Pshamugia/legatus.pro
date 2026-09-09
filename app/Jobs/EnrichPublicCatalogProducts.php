<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use App\Services\PublicWebsiteCrawler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class EnrichPublicCatalogProducts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(public int $sourceId, public int $afterProductId = 0)
    {
        $this->onQueue('knowledge');
    }

    public function uniqueId(): string
    {
        return "catalog-enrichment:{$this->sourceId}:{$this->afterProductId}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("catalog-enrichment:{$this->sourceId}"))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(PublicWebsiteCrawler $crawler, KnowledgeIngestionService $ingestion): void
    {
        $source = KnowledgeSource::query()->find($this->sourceId);
        if (! $source || $source->type !== 'url' || $source->source_scope !== 'catalog') {
            return;
        }

        $products = $source->agent->products()
            ->where('metadata->source_id', $source->id)
            ->where('is_active', true)
            ->where('id', '>', $this->afterProductId)
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($products as $product) {
            $url = data_get($product->metadata, 'product_url');
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            try {
                $response = $ingestion->fetchPublicUrl($url, ['Accept' => 'text/html'], 8, 1);
                $crawler->enrichProductPage($source, $url, $response->body());
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($products->isNotEmpty()) {
            self::dispatch($source->id, (int) $products->last()->id);
        }
    }
}
