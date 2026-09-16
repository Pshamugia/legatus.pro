<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

class PublicProductAvailabilityVerifier
{
    /** @var array<int, bool|null> */
    private array $results = [];

    public function __construct(private readonly KnowledgeIngestionService $ingestion) {}

    public function verify(Product $product): ?bool
    {
        if (array_key_exists($product->id, $this->results)) {
            return $this->results[$product->id];
        }

        $url = data_get($product->metadata, 'product_url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->results[$product->id] = null;
        }

        try {
            $response = $this->ingestion->fetchPublicUrl($url, ['Accept' => 'text/html'], 6, 1);
            $body = $response->body();
            $origin = (parse_url($url, PHP_URL_SCHEME) ?: 'https').'://'.parse_url($url, PHP_URL_HOST);
            $available = $this->explicitPageAvailability($body);

            if ($available === null) {
                $storefront = $this->matchingRecord(
                    $this->ingestion->storefrontProductsFromHtml($body, $origin),
                    $product,
                    $url,
                );
                $available = $storefront ? $this->availability($storefront) : null;
            }

            if ($available === null) {
                $structured = $this->matchingRecord(
                    $this->ingestion->structuredProductsFromHtml($body),
                    $product,
                    $url,
                );
                $available = $structured ? $this->availability($structured) : null;
            }
            if ($available === null) {
                return $this->results[$product->id] = null;
            }

            $metadata = $product->metadata ?? [];
            $metadata['stock_precision'] = 'availability_only';
            $metadata['live_checked_at'] = now()->toIso8601String();
            $product->update([
                'stock' => $available ? max(1, (int) $product->stock) : 0,
                'metadata' => $metadata,
            ]);

            return $this->results[$product->id] = $available;
        } catch (\Throwable $exception) {
            report($exception);

            return $this->results[$product->id] = null;
        }
    }

    /**
     * Prefer an explicit zero-inventory control from the rendered product page
     * over stale structured data. Storefronts commonly expose the maximum
     * purchasable quantity in a language-neutral input even when their JSON-LD
     * still incorrectly advertises InStock.
     */
    private function explicitPageAvailability(string $body): ?bool
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
        $xpath = new \DOMXPath($dom);
        $inventoryFields = [
            'maxquantity',
            'maximumquantity',
            'maxstock',
            'stockquantity',
            'availablequantity',
            'inventoryquantity',
        ];

        foreach ($xpath->query('//*[@id or @name]') as $node) {
            $identifier = Str::lower($node->getAttribute('id') ?: $node->getAttribute('name'));
            $identifier = preg_replace('/[^a-z0-9]+/', '', $identifier) ?? '';
            if (! in_array($identifier, $inventoryFields, true)) {
                continue;
            }

            $value = trim($node->getAttribute('value'));
            if ($value !== '' && is_numeric($value) && (float) $value <= 0) {
                return false;
            }
        }

        return null;
    }

    private function matchingRecord(array $records, Product $product, string $url): ?array
    {
        $normalizedUrl = $this->normalizedUrl($url);
        $sku = trim((string) $product->sku);
        $name = Str::lower(Str::squish($product->name));

        return collect($records)->filter(fn ($record): bool => is_array($record))->first(function (array $record) use ($normalizedUrl, $sku, $name): bool {
            $recordUrl = $this->normalizedUrl($record['url'] ?? data_get($record, 'offers.url'));
            $recordSku = trim((string) ($record['sku'] ?? $record['id'] ?? ''));
            $recordName = Str::lower(Str::squish((string) ($record['name'] ?? $record['title'] ?? '')));

            return ($recordUrl !== '' && $recordUrl === $normalizedUrl)
                || ($sku !== '' && $recordSku === $sku)
                || ($name !== '' && $recordName === $name);
        });
    }

    private function availability(array $record): ?bool
    {
        if (is_bool($record['sold'] ?? null)) {
            return ! $record['sold'];
        }
        foreach (['stock', 'quantity'] as $field) {
            if (isset($record[$field]) && is_numeric($record[$field])) {
                return (float) $record[$field] > 0;
            }
        }

        $availability = $record['in_stock'] ?? $record['availability'] ?? data_get($record, 'offers.availability');
        if (is_bool($availability)) {
            return $availability;
        }
        if (! is_string($availability)) {
            return null;
        }
        $availability = Str::lower($availability);
        if (Str::contains($availability, ['outofstock', 'out_of_stock', 'out of stock', 'soldout', 'sold_out', 'unavailable'])) {
            return false;
        }
        if (Str::contains($availability, ['instock', 'in_stock', 'in stock', 'available'])) {
            return true;
        }

        return null;
    }

    private function normalizedUrl(mixed $url): string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return Str::lower((string) parse_url($url, PHP_URL_HOST))
            .rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
