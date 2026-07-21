<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Str;

class PublicStorefrontCatalog
{
    public function __construct(private KnowledgeIngestionService $ingestion) {}

    /** @return array{imported: int, did_you_mean: ?string, source: ?array} */
    public function discover(Agent $agent, string $query): array
    {
        $source = $this->catalogSource($agent);
        if (! $source || ! $this->validQuery($query)) {
            return ['imported' => 0, 'did_you_mean' => null, 'source' => null];
        }

        $parts = parse_url((string) $source->url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if ($origin === 'https://' && empty($parts['host'])) {
            return ['imported' => 0, 'did_you_mean' => null, 'source' => null];
        }

        // Natural questions often wrap the actual product identity in extra
        // conversational words. Try the full query first, then a bounded set
        // of strongest contiguous phrases. This is generic query relaxation,
        // not a list of hard-coded customer sentences.
        foreach ($this->queryCandidates($query) as $candidate) {
            try {
                $searchPage = $this->ingestion->fetchPublicUrl(
                    $origin.'/search?title='.rawurlencode($candidate),
                    ['Accept' => 'text/html'],
                );
                $products = $this->ingestion->storefrontProductsFromHtml($searchPage->body(), $origin);
                if ($products !== []) {
                    $products = $this->enrichOriginalPrices($products, $parts);
                    $result = $this->ingestion->importDiscoveredUrlProducts($source, $products);

                    return [
                        'imported' => (int) $result['found'],
                        'did_you_mean' => null,
                        'source' => $this->sourceEvidence((string) $source->url),
                    ];
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        try {
            $response = $this->ingestion->fetchPublicUrl(
                $origin.'/search/suggest?q='.rawurlencode($query),
                ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
            );
            $payload = $response->json();
        } catch (\Throwable $exception) {
            report($exception);

            return ['imported' => 0, 'did_you_mean' => null, 'source' => null];
        }

        if (! is_array($payload) || ! is_array($payload['items'] ?? null)) {
            return ['imported' => 0, 'did_you_mean' => null, 'source' => null];
        }

        $products = [];
        foreach (collect($payload['items'])->filter(fn ($item): bool => is_array($item))->take(12) as $item) {
            if (($item['sold'] ?? false) === true) {
                continue;
            }
            $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
            if (! $this->sameOriginProductUrl($url, $parts)) {
                continue;
            }

            try {
                $detail = $this->ingestion->fetchPublicUrl($url, ['Accept' => 'text/html']);
                foreach ($this->ingestion->structuredProductsFromHtml($detail->body()) as $product) {
                    $product['url'] ??= $url;
                    $product['author'] ??= $item['author'] ?? null;
                    $product['image'] ??= $item['image'] ?? null;
                    $product['availability'] ??= 'InStock';
                    $product['stock_precision'] = 'availability_only';
                    $products[] = $product;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $result = $products === []
            ? ['found' => 0]
            : $this->ingestion->importDiscoveredUrlProducts($source, $products);
        $suggestion = is_string($payload['didYouMean'] ?? null)
            ? trim($payload['didYouMean'])
            : null;

        return [
            'imported' => (int) ($result['found'] ?? 0),
            'did_you_mean' => $suggestion !== '' ? $suggestion : null,
            'source' => $this->sourceEvidence((string) $source->url),
        ];
    }

    private function sourceEvidence(string $url): array
    {
        return [
            'type' => 'public_storefront',
            'label' => parse_url($url, PHP_URL_HOST).' public catalog',
            'reference' => $url,
            'freshness' => now()->toIso8601String(),
        ];
    }

    private function catalogSource(Agent $agent)
    {
        $configured = trim((string) data_get($agent->settings, 'catalog_url', ''));

        return $agent->knowledgeSources()
            ->where('type', 'url')
            ->when($configured !== '', fn ($query) => $query->where('url', $configured))
            ->whereNotNull('url')
            ->orderByDesc('id')
            ->first();
    }

    private function validQuery(string $query): bool
    {
        $query = trim($query);

        return mb_strlen($query) >= 2
            && mb_strlen($query) <= 150
            && ! preg_match('/[\x00-\x1F\x7F]/', $query);
    }

    /** @return list<string> */
    private function queryCandidates(string $query): array
    {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        $tokens = preg_split('/[^\pL\pN%_+\-.]+/u', Str::lower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) <= 2) {
            return [$query];
        }

        $phrases = [];
        foreach ([3, 2] as $size) {
            for ($offset = 0; $offset + $size <= count($tokens); $offset++) {
                $slice = array_slice($tokens, $offset, $size);
                $phrases[] = [
                    'text' => implode(' ', $slice),
                    'weight' => array_sum(array_map('mb_strlen', $slice)),
                ];
            }
        }
        usort($phrases, fn (array $left, array $right): int => $right['weight'] <=> $left['weight']);

        return collect([$query])
            ->merge(collect($phrases)->pluck('text'))
            ->merge(collect($tokens)->sortByDesc(fn (string $token): int => mb_strlen($token)))
            ->filter(fn (string $candidate): bool => mb_strlen($candidate) >= 3)
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    private function sameOriginProductUrl(string $url, array $catalogParts): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === ($catalogParts['scheme'] ?? null)
            && Str::lower((string) ($parts['host'] ?? '')) === Str::lower((string) ($catalogParts['host'] ?? ''))
            && str_starts_with((string) ($parts['path'] ?? ''), '/books/');
    }

    /** @param list<array> $products
     * @return list<array>
     */
    private function enrichOriginalPrices(array $products, array $catalogParts): array
    {
        foreach (array_slice(array_keys($products), 0, 3) as $index) {
            $url = (string) ($products[$index]['url'] ?? '');
            if (($products[$index]['original_price'] ?? null) !== null || ! $this->sameOriginProductUrl($url, $catalogParts)) {
                continue;
            }

            try {
                $detail = $this->ingestion->fetchPublicUrl($url, ['Accept' => 'text/html']);
                $originalPrice = $this->ingestion->storefrontOriginalPriceFromHtml($detail->body());
                if ($originalPrice !== null && $originalPrice > (float) ($products[$index]['price'] ?? 0)) {
                    $products[$index]['original_price'] = $originalPrice;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $products;
    }
}
