<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use App\Services\PublicWebsiteCrawler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class EnrichPublicLanguageProducts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(public int $sourceId, public int $afterChunkId = 0)
    {
        $this->onQueue('knowledge');
    }

    public function uniqueId(): string
    {
        return "language-enrichment:{$this->sourceId}:{$this->afterChunkId}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("language-enrichment:{$this->sourceId}"))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(PublicWebsiteCrawler $crawler, KnowledgeIngestionService $ingestion): void
    {
        $source = KnowledgeSource::query()->find($this->sourceId);
        if (! $source || $source->type !== 'url' || $source->source_scope !== 'language' || ! filled($source->taxonomy_label)) {
            return;
        }

        $chunks = $source->chunks()->where('kind', 'product')
            ->where('id', '>', $this->afterChunkId)
            ->orderBy('id')->limit(20)->get(['id', 'metadata']);

        foreach ($chunks as $chunk) {
            $productId = (int) data_get($chunk->metadata, 'product_id');
            if ($productId <= 0) {
                continue;
            }
            $product = $source->agent->products()->find($productId);
            $url = data_get($product?->metadata, 'localized.'.trim((string) $source->taxonomy_label).'.product_url');
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            try {
                $response = $ingestion->fetchPublicUrl($url, ['Accept' => 'text/html'], 8, 1);
                $crawler->enrichProductPage($source, $url, $response->body(), $product);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($chunks->isNotEmpty()) {
            self::dispatch($source->id, (int) $chunks->last()->id);
        }
    }
}
