<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductPagePrimaryImageResolver
{
    public function __construct(private readonly KnowledgeIngestionService $knowledge) {}

    public function resolve(Product $product, ?string $language = null): ?string
    {
        $localized = $language ? (array) data_get($product->metadata, "localized.{$language}", []) : [];
        $productUrl = (string) ($localized['product_url'] ?? data_get($product->metadata, 'product_url', ''));
        if (! $this->publicHttpUrl($productUrl)) {
            return null;
        }

        $cached = Cache::remember('product-page-primary-image:'.hash('sha256', $productUrl), now()->addHours(6), function () use ($productUrl): string {
            try {
                $body = $this->knowledge->fetchPublicUrl($productUrl, timeout: 8, retries: 1)->body();
                if ($body === '' || strlen($body) > 2_000_000) {
                    return '';
                }

                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
                $xpath = new \DOMXPath($dom);
                $node = $xpath->query('//*[@id="thumbnailImage" and @src]')->item(0);
                $source = trim((string) $node?->getAttribute('src'));
                $absolute = $this->sameOriginAbsoluteUrl($productUrl, $source);

                return $absolute ?? '';
            } catch (\Throwable $exception) {
                report($exception);

                return '';
            }
        });

        return $cached !== '' ? $cached : null;
    }

    private function sameOriginAbsoluteUrl(string $pageUrl, string $imageUrl): ?string
    {
        if ($imageUrl === '') {
            return null;
        }
        $page = parse_url($pageUrl);
        $origin = ($page['scheme'] ?? 'https').'://'.($page['host'] ?? '');
        if (str_starts_with($imageUrl, '//')) {
            $imageUrl = ($page['scheme'] ?? 'https').':'.$imageUrl;
        } elseif (str_starts_with($imageUrl, '/')) {
            $imageUrl = $origin.$imageUrl;
        } elseif (! preg_match('#^https?://#i', $imageUrl)) {
            $directory = rtrim(str_replace('\\', '/', dirname((string) ($page['path'] ?? '/'))), '/');
            $imageUrl = $origin.($directory !== '' ? '/'.ltrim($directory, '/') : '').'/'.$imageUrl;
        }

        return $this->publicHttpUrl($imageUrl) && parse_url($imageUrl, PHP_URL_HOST) === ($page['host'] ?? null)
            ? $imageUrl
            : null;
    }

    private function publicHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
