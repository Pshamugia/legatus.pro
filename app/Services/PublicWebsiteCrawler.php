<?php

namespace App\Services;

use App\Jobs\EmbedKnowledgeSource;
use App\Models\KnowledgeSource;
use Illuminate\Support\Str;

class PublicWebsiteCrawler
{
    public function __construct(
        private KnowledgeIngestionService $ingestion,
    ) {}

    public function crawl(KnowledgeSource $source): void
    {
        $source->refresh();
        $startUrl = $this->normalizeUrl((string) $source->url);
        if ($startUrl === null) {
            throw new \RuntimeException('A valid public website URL is required.');
        }
        $host = parse_url($startUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \RuntimeException('A valid public website URL is required.');
        }

        $maximumPages = max(10, min(10_000, (int) config('legatus.public_crawl_max_pages', 5000)));
        $taxonomyOnly = $this->ingestion->taxonomyForSource($source) !== [];
        $classificationOnly = $taxonomyOnly || $source->source_scope === 'language';
        $listingOnly = $classificationOnly || $source->source_scope === 'catalog';
        if ($listingOnly) {
            // A named category/genre URL is a scoped collection, not another
            // request to crawl the business's entire domain.
            $maximumPages = min($maximumPages, max(10, (int) config('legatus.taxonomy_crawl_max_pages', 250)));
        }
        if ($source->source_scope === 'language') {
            // Language indexes also visit product pages for localized
            // descriptions. Keep each crawl inside shared-host worker limits.
            $maximumPages = min($maximumPages, 15);
        }
        $maximumProducts = max(1, min(10_000, (int) config('legatus.commerce_max_catalog_products', 10000)));
        $crawlStartedAt = now();
        $queue = [$startUrl];
        $queued = [$startUrl => true];
        $visited = [];
        $created = $updated = 0;
        $productCount = 0;
        $hash = hash_init('sha256');

        $source->update(['status' => 'processing', 'progress' => 1, 'error' => null]);

        if (! $listingOnly) {
            foreach ([
                $this->origin($startUrl).'/sitemap.xml',
                $this->origin($startUrl).'/sitemap_index.xml',
            ] as $sitemapUrl) {
                $this->enqueue($queue, $queued, $sitemapUrl, $host, $maximumPages);
            }
        }

        try {
            while ($queue !== [] && count($visited) < $maximumPages && $productCount < $maximumProducts) {
                $url = array_shift($queue);
                if (isset($visited[$url])) {
                    continue;
                }
                $visited[$url] = true;

                try {
                    $response = $this->ingestion->fetchPublicUrl($url, [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml,application/json',
                    ]);
                } catch (\Throwable) {
                    continue;
                }

                $body = $response->body();
                hash_update($hash, $url."\n".hash('sha256', $body));
                $contentType = mb_strtolower((string) $response->header('Content-Type'));

                if (str_contains($contentType, 'xml') || str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '.xml')) {
                    foreach ($this->sitemapUrls($body) as $discovered) {
                        $this->enqueue($queue, $queued, $discovered, $host, $maximumPages);
                    }
                    $this->progress($source, count($visited), count($queue), $maximumPages);

                    continue;
                }

                $products = array_merge(
                    $this->ingestion->structuredProductsFromHtml($body),
                    $this->ingestion->storefrontProductsFromHtml($body, $this->origin($url)),
                );
                if ($products !== []) {
                    $result = $this->ingestion->importDiscoveredUrlProducts($source, $products);
                    $created += $result['created'];
                    $updated += $result['updated'];
                    $productCount = $source->agent->products()
                        ->where('metadata->source_id', $source->id)
                        ->where('is_active', true)
                        ->count();
                }

                // Named category/genre sources are lightweight product indexes.
                // The listing pages already define membership, so crawling and
                // embedding every linked detail page would duplicate the site.
                $this->enrichMatchedProduct($source, $url, $body);
                if (! $listingOnly) {
                    $this->storeReadablePage($source, $url, $body, $products !== []);
                }

                foreach ($this->discoverLinks($body, $url, $products, $listingOnly, $taxonomyOnly) as $discovered) {
                    $this->enqueue($queue, $queued, $discovered, $host, $maximumPages);
                }

                $this->progress($source, count($visited), count($queue), $maximumPages);
                if (count($visited) % 25 === 0) {
                    gc_collect_cycles();
                }
            }

            if ($source->chunks()->count() === 0) {
                throw new \RuntimeException('The public website did not expose readable content.');
            }

            // Keep the last-known-good index live throughout the crawl. Rows
            // touched during this run retain their embeddings; only content
            // absent from a successfully completed crawl is retired here.
            $source->chunks()->where('updated_at', '<', $crawlStartedAt)->delete();
            if ($classificationOnly) {
                // A product may be owned by the main catalog and belong to many
                // taxonomy sources. Product chunks are that membership mapping.
                $productCount = $source->chunks()
                    ->where('kind', 'product')
                    ->get(['metadata'])
                    ->pluck('metadata.product_id')
                    ->filter()
                    ->unique()
                    ->count();
            } else {
                $source->agent->products()
                    ->where('metadata->source_id', $source->id)
                    ->where('updated_at', '<', $crawlStartedAt)
                    ->update(['is_active' => false]);
                $productCount = $source->agent->products()
                    ->where('metadata->source_id', $source->id)
                    ->where('is_active', true)
                    ->count();
            }

            $source->update([
                'status' => config('services.openai.key') && ! $listingOnly ? 'processing' : 'ready',
                'progress' => config('services.openai.key') && ! $listingOnly ? 96 : 100,
                'items_found' => $productCount,
                'items_created' => $created,
                'items_updated' => $updated,
                'content_hash' => hash_final($hash),
                'last_synced_at' => now(),
                'index_version' => $classificationOnly ? 2 : (int) $source->index_version,
                'error' => $queue === []
                    ? null
                    : "Crawl reached the configured {$maximumPages}-page safety limit.",
            ]);
            if (config('services.openai.key') && ! $listingOnly) {
                EmbedKnowledgeSource::dispatch($source->id);
            }
        } catch (\Throwable $exception) {
            $source->update([
                'status' => $source->chunks()->exists() ? 'ready' : 'failed',
                'progress' => $source->chunks()->exists() ? max(1, (int) $source->progress) : 0,
                'error' => Str::limit($exception->getMessage(), 1500),
            ]);

            throw $exception;
        }
    }

    private function storeReadablePage(KnowledgeSource $source, string $url, string $html, bool $productPage): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?: $source->name));
        $text = preg_replace('/\s+/u', ' ', trim((string) $dom->textContent)) ?? '';
        if (mb_strlen($text) < 30) {
            return;
        }

        $matchedProduct = $source->agent->products()
            ->where('metadata->source_id', $source->id)
            ->where('metadata->product_url', $url)
            ->first();
        if ($matchedProduct) {
            $productPage = true;
        }

        foreach ($this->split($text) as $index => $content) {
            $hash = hash('sha256', $url.'|'.$index.'|'.$content);
            $source->chunks()->updateOrCreate(
                ['content_hash' => $hash],
                [
                    'agent_id' => $source->agent_id,
                    'kind' => $productPage ? 'product_page' : 'webpage',
                    'title' => Str::limit($title, 255, ''),
                    'content' => $content,
                    'metadata' => ['url' => $url, 'page_chunk' => $index + 1],
                ],
            );
        }
    }

    /** @return list<string> */
    private function enrichMatchedProduct(KnowledgeSource $source, string $url, string $html): void
    {
        $product = $source->agent->products()
            ->where('metadata->source_id', $source->id)
            ->where('metadata->product_url', $url)
            ->first();
        if (! $product && $source->source_scope === 'language' && filled($source->taxonomy_label)) {
            $language = trim((string) $source->taxonomy_label);
            $productIds = $source->chunks()->where('kind', 'product')->get(['metadata'])
                ->pluck('metadata.product_id')->filter()->unique()->values();
            $product = $source->agent->products()->whereIn('id', $productIds)->get()->first(function ($candidate) use ($language, $url): bool {
                $localized = (array) data_get($candidate->metadata, 'localized', []);

                return data_get($localized[$language] ?? [], 'product_url') === $url;
            });
        }
        if (! $product) {
            return;
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $description = $this->productDescriptionFromHtml($html, $dom);
        if ($description === '') {
            return;
        }

        if ($source->source_scope === 'language' && filled($source->taxonomy_label)) {
            $metadata = $product->metadata ?? [];
            $metadata['localized'][trim((string) $source->taxonomy_label)]['description'] = $description;
            $product->update(['metadata' => $metadata]);

            return;
        }

        $metadata = $product->metadata ?? [];
        $originalPrice = $this->ingestion->storefrontOriginalPriceFromHtml($html);
        if ($originalPrice !== null && $originalPrice > (float) $product->price) {
            $metadata['original_price'] = $originalPrice;
        }
        $product->update([
            'description' => $description,
            'search_text' => trim(implode(' ', array_filter([$product->search_text, $description]))),
            'metadata' => $metadata,
        ]);
    }

    private function productDescriptionFromHtml(string $html, \DOMDocument $dom): string
    {
        if (preg_match('/<h[1-6][^>]*>\s*(?:აღწერა|description|описание)\s*<\/h[1-6]>(.*?)(?=<h[1-6]\b|<footer\b|$)/isu', $html, $matches)) {
            $section = preg_replace('/<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>/isu', ' ', $matches[1]) ?? '';
            $text = html_entity_decode(strip_tags($section), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
            if (mb_strlen($text) >= 20) {
                return Str::limit($text, 4000, '');
            }
        }

        return Str::limit(preg_replace('/\s+/u', ' ', trim((string) $dom->textContent)) ?? '', 4000, '');
    }

    private function discoverLinks(
        string $html,
        string $pageUrl,
        array $products,
        bool $listingOnly = false,
        bool $taxonomyOnly = false,
    ): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $links = [];

        if (! $taxonomyOnly) {
            foreach ($products as $product) {
                $links[] = $product['url'] ?? data_get($product, 'offers.url');
            }
        }

        $linkQuery = $listingOnly
            ? '//a[@href and (contains(concat(" ", normalize-space(@rel), " "), " next ") or contains(concat(" ", normalize-space(@class), " "), " pagination ") or @data-page)]'
            : '//a[@href]';
        foreach ($xpath->query($linkQuery) as $node) {
            $links[] = $node->getAttribute('href');
        }
        foreach ($xpath->query('//*[@data-next-page]') as $node) {
            $page = trim((string) $node->getAttribute('data-next-page'));
            if (ctype_digit($page) && (int) $page > 0) {
                $links[] = $this->pageUrl($pageUrl, (int) $page);
            }
        }

        return collect($links)
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn (string $url) => $this->absoluteUrl($pageUrl, $url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function pageUrl(string $url, int $page): string
    {
        $parts = parse_url($url);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['page'] = $page;

        return ($parts['scheme'].'://'.$parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/')
            .'?'.http_build_query($query);
    }

    /** @return list<string> */
    private function sitemapUrls(string $xml): array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            return [];
        }

        $urls = [];
        foreach ($dom->getElementsByTagName('loc') as $node) {
            $urls[] = trim($node->textContent);
        }

        return array_values(array_filter($urls));
    }

    private function enqueue(array &$queue, array &$queued, ?string $url, string $host, int $maximum): void
    {
        $url = $this->normalizeUrl($url);
        if ($url === null || isset($queued[$url]) || count($queued) >= $maximum * 2) {
            return;
        }
        if (mb_strtolower((string) parse_url($url, PHP_URL_HOST)) !== mb_strtolower($host)) {
            return;
        }
        if (preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4|mp3)(?:\?|$)/i', $url)) {
            return;
        }
        if (preg_match('#/(?:login|logout|register|admin|account|checkout|cart)(?:/|$)#i', (string) parse_url($url, PHP_URL_PATH))) {
            return;
        }
        $queued[$url] = true;
        $queue[] = $url;
    }

    private function absoluteUrl(string $pageUrl, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:mailto|tel|javascript):/i', $href)) {
            return null;
        }
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }
        $origin = $this->origin($pageUrl);
        if (str_starts_with($href, '//')) {
            return (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';

        return $origin.rtrim(str_replace('\\', '/', dirname($path)), '/').'/'.$href;
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (! in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = array_intersect_key($query, array_flip(['page', 'p', 'lang', 'locale']));
        ksort($query);
        $normalized = ($parts['scheme'].'://'.$parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/')
            .($query !== [] ? '?'.http_build_query($query) : '');

        return rtrim($normalized, '/') ?: $normalized;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return list<string> */
    private function split(string $text): array
    {
        $chunks = [];
        while (mb_strlen($text) > 1400) {
            $cut = mb_strrpos(mb_substr($text, 0, 1400), ' ');
            $cut = $cut && $cut > 500 ? $cut : 1400;
            $chunks[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if (mb_strlen($text) >= 30) {
            $chunks[] = $text;
        }

        return $chunks;
    }

    private function progress(KnowledgeSource $source, int $visited, int $queued, int $maximum): void
    {
        $denominator = max(1, min($maximum, $visited + $queued));
        $progress = min(95, max(2, (int) floor(($visited / $denominator) * 90)));
        if ($progress > (int) $source->progress) {
            $source->update(['progress' => $progress]);
        }
    }
}
