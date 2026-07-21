<?php

namespace App\Services;

use App\Models\KnowledgeSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

class KnowledgeIngestionService
{
    public function __construct(private EmbeddingService $embeddings) {}

    public function ingest(KnowledgeSource $source): void
    {
        $source->refresh();
        $previousStatus = $source->status;
        $previousProgress = $source->progress;
        $hasLastKnownGoodVersion = $previousStatus === 'ready';

        DB::beginTransaction();

        try {
            $source->update(['status' => 'processing', 'progress' => 10, 'error' => null]);
            $this->assertConfiguredPayload($source);
            $source->chunks()->delete();
            $result = match ($source->type) {
                'csv' => $this->csv($source),'pdf' => $this->pdf($source),'url' => $this->url($source),default => throw new \InvalidArgumentException('Unsupported source type')
            };
            if ($source->chunks()->count() === 0) {
                throw new \RuntimeException('The source did not contain any useful products or searchable content.');
            }
            $source->update(['progress' => 80]);
            $this->embeddings->embedSource($source);
            $source->update($result + ['status' => 'ready', 'progress' => 100, 'last_synced_at' => now()]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $source->refresh()->update([
                'status' => $hasLastKnownGoodVersion ? $previousStatus : 'failed',
                'error' => Str::limit($e->getMessage(), 1500),
                'progress' => $hasLastKnownGoodVersion ? $previousProgress : 0,
            ]);

            throw $e;
        }
    }

    public function storeFile(UploadedFile $file, string $type): string
    {
        return $file->store("knowledge/{$type}", 'local');
    }

    public function deleteStoredFile(KnowledgeSource $source): void
    {
        if (! in_array($source->type, ['csv', 'pdf'], true) || blank($source->file_path)) {
            return;
        }

        $path = str_replace('\\', '/', trim($source->file_path));
        $segments = explode('/', $path);
        $expectedDirectory = "knowledge/{$source->type}/";

        if (str_starts_with($path, '/') || in_array('..', $segments, true) || ! str_starts_with($path, $expectedDirectory)) {
            return;
        }

        Storage::disk('local')->delete($path);
    }

    private function csv(KnowledgeSource $source): array
    {
        $path = storage_path('app/private/'.$source->file_path);
        $h = fopen($path, 'rb');
        if (! $h) {
            throw new \RuntimeException('CSV file cannot be opened.');
        }

        try {
            $source->agent->products()->where('metadata->source_id', $source->id)->update(['is_active' => false]);
            $firstLine = fgets($h);
            if ($firstLine === false) {
                throw new \RuntimeException('CSV header row is missing.');
            }
            $delimiter = $this->detectDelimiter($firstLine);
            rewind($h);
            $headers = fgetcsv($h, separator: $delimiter);
            if (! $headers) {
                throw new \RuntimeException('CSV header row is missing.');
            }
            $headers = array_map(function ($value) {
                $value = preg_replace('/^\x{FEFF}/u', '', (string) $value);

                return Str::snake(trim(mb_strtolower($value)));
            }, $headers);
            $found = $created = $updated = 0;
            while (($row = fgetcsv($h, separator: $delimiter)) !== false) {
                if (count(array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $row = array_slice(array_pad($row, count($headers), null), 0, count($headers));
                $data = array_combine($headers, $row);
                if (! $data) {
                    continue;
                }
                $name = trim($data['name'] ?? $data['title'] ?? $data['product'] ?? '');
                if ($name === '') {
                    continue;
                }
                $price = $this->number($data['price'] ?? '');
                if ($price <= 0) {
                    continue;
                }
                $found++;
                $sku = trim($data['sku'] ?? '');
                $query = $source->agent->products();
                $existing = $sku !== '' ? $query->where('sku', $sku)->first() : $query->where('name', $name)->first();
                $author = $this->catalogText($data['author'] ?? null, 255) ?: null;
                $genres = $this->catalogText($data['genres'] ?? $data['genre'] ?? null, 1000) ?: null;
                $isbn = $this->catalogText($data['isbn'] ?? $data['isbn_13'] ?? $data['isbn_10'] ?? null, 32) ?: null;
                $category = $this->catalogText($data['category'] ?? null, 255) ?: null;
                $description = $this->catalogText($data['description'] ?? null, 4000) ?: null;
                $values = [
                    'name' => $name,
                    'sku' => $sku ?: null,
                    'category' => $category,
                    'description' => $description,
                    'search_text' => $this->searchableProductText([$name, $sku, $category, $author, $genres, $isbn, $description]),
                    'price' => $price,
                    'stock' => max(0, (int) $this->number($data['stock'] ?? $data['quantity'] ?? 0)),
                    'image' => $data['image'] ?? $data['image_url'] ?? null,
                    'is_active' => true,
                    'metadata' => [
                        'source_id' => $source->id,
                        'author' => $author,
                        'genres' => $genres === null ? [] : preg_split('/\s*[,;|]\s*/u', $genres, -1, PREG_SPLIT_NO_EMPTY),
                        'isbn' => $isbn,
                    ],
                ];
                if ($existing) {
                    $existing->update($values);
                    $updated++;
                    $product = $existing;
                } else {
                    $product = $source->agent->products()->create($values);
                    $created++;
                }
                $this->chunk($source, 'product', $name, json_encode($product->only(['id', 'name', 'sku', 'category', 'description', 'price', 'stock']), JSON_UNESCAPED_UNICODE), ['product_id' => $product->id]);
            }
            if ($found === 0) {
                throw new \RuntimeException('The CSV catalog did not contain any valid product rows.');
            }
        } finally {
            fclose($h);
        }

        return ['items_found' => $found, 'items_created' => $created, 'items_updated' => $updated, 'content_hash' => hash_file('sha256', $path)];
    }

    private function pdf(KnowledgeSource $source): array
    {
        $path = storage_path('app/private/'.$source->file_path);
        $text = (new Parser)->parseFile($path)->getText();
        if (mb_strlen(trim($text)) < 30) {
            throw new \RuntimeException('No readable text found in PDF. Scanned PDFs require OCR.');
        }
        $chunks = $this->split($text);
        foreach ($chunks as $i => $content) {
            $this->chunk($source, 'policy', $source->name.' · '.($i + 1), $content, ['page_chunk' => $i + 1]);
        }

        return ['items_found' => count($chunks), 'items_created' => $source->chunks()->count(), 'items_updated' => 0, 'content_hash' => hash_file('sha256', $path)];
    }

    private function url(KnowledgeSource $source): array
    {
        $source->agent->products()->where('metadata->source_id', $source->id)->update(['is_active' => false]);
        $response = $this->fetchPublicUrl($source->url);
        $body = $response->body();
        if (strlen($body) > 5_000_000) {
            throw new \RuntimeException('Page exceeds the 5 MB safety limit.');
        }

        $json = $this->catalogJsonPayload($response->header('Content-Type'), $body);
        if ($json !== null) {
            $products = $this->catalogJsonProducts($json);
            $this->assertCompleteJsonCatalog($json, count($products));
            $result = $this->importUrlProducts($source, $products, $this->catalogPayloadCurrency($json));
            if ($result['found'] === 0) {
                throw new \RuntimeException('The JSON catalog did not contain any valid products with a name and price in the workspace currency.');
            }

            return [
                'items_found' => $result['found'],
                'items_created' => $result['created'],
                'items_updated' => $result['updated'],
                'content_hash' => hash('sha256', $body),
            ];
        }

        $webChunks = 0;
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
        $xpath = new \DOMXPath($dom);
        $products = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
            if (is_array($json)) {
                $products = array_merge($products, $this->findProducts($json));
                $this->assertCatalogItemLimit(count($products));
            }
        }
        $catalogParts = parse_url((string) $source->url);
        $origin = ($catalogParts['scheme'] ?? 'https').'://'.($catalogParts['host'] ?? '');
        $products = array_merge($products, $this->storefrontProductsFromHtml($body, $origin));
        $this->assertCatalogItemLimit(count($products));
        $result = $this->importUrlProducts($source, $products);
        foreach ($xpath->query('//script|//style|//nav|//footer') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $text = preg_replace('/\s+/u', ' ', trim($dom->textContent));
        foreach ($this->split($text) as $i => $content) {
            $this->chunk($source, 'webpage', $source->name.' · '.($i + 1), $content, ['url' => $source->url]);
            $webChunks++;
        }
        if ($result['found'] === 0 && $webChunks === 0) {
            throw new \RuntimeException('The website did not contain any accepted products or searchable content.');
        }

        return [
            'items_found' => $result['found'] ?: $webChunks,
            'items_created' => $result['created'],
            'items_updated' => $result['updated'],
            'content_hash' => hash('sha256', $body),
        ];
    }

    private function catalogJsonPayload(?string $contentType, string $body): ?array
    {
        $trimmed = ltrim($body);
        $looksLikeJson = str_contains(mb_strtolower((string) $contentType), 'json')
            || str_starts_with($trimmed, '{')
            || str_starts_with($trimmed, '[');
        if (! $looksLikeJson) {
            return null;
        }

        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The catalog URL returned invalid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new \RuntimeException('The catalog URL must return a JSON object or array.');
        }

        return $payload;
    }

    /** @return list<array> */
    private function catalogJsonProducts(array $payload): array
    {
        $jsonLdProducts = $this->findProducts($payload);
        if ($jsonLdProducts !== []) {
            $this->assertCatalogItemLimit(count($jsonLdProducts));

            return array_values($jsonLdProducts);
        }

        $products = $this->catalogProductsFromKnownShape($payload);
        $this->assertCatalogItemLimit(count($products));

        return $products;
    }

    /** @return list<array> */
    private function catalogProductsFromKnownShape(array $payload, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }
        if ($this->looksLikeCatalogProduct($payload)) {
            return [$payload];
        }

        foreach (['products', 'items', 'data', 'catalog', 'results'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }
            $products = $this->catalogProductsFromKnownShape($payload[$key], $depth + 1);
            if ($products !== []) {
                return $products;
            }
        }

        return [];
    }

    private function looksLikeCatalogProduct(array $product): bool
    {
        $hasName = array_key_exists('name', $product) || array_key_exists('title', $product);
        $hasPrice = array_key_exists('price', $product) || array_key_exists('offers', $product);

        return $hasName && $hasPrice;
    }

    private function assertCompleteJsonCatalog(array $payload, int $received): void
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $total = $meta['total'] ?? null;
        if ($total !== null) {
            if (! is_int($total) && ! (is_string($total) && ctype_digit($total))) {
                throw new \RuntimeException('The JSON catalog total must be a non-negative integer.');
            }
            $total = (int) $total;
            if ($total < 0) {
                throw new \RuntimeException('The JSON catalog total must be a non-negative integer.');
            }
            $this->assertCatalogItemLimit($total);
            if ($total !== $received) {
                throw new \RuntimeException('The catalog URL returned a partial product list. Use a URL that exposes the complete catalog.');
            }
        }

        $currentPage = $meta['current_page'] ?? 1;
        $lastPage = $meta['last_page'] ?? 1;
        if ((string) $currentPage !== '1' || (string) $lastPage !== '1') {
            throw new \RuntimeException('The catalog URL is paginated. Use a URL that exposes the complete catalog.');
        }
    }

    private function assertCatalogItemLimit(int $count): void
    {
        $maximum = max(1, min(10_000, (int) config('legatus.commerce_max_catalog_products', 10_000)));
        if ($count > $maximum) {
            throw new \RuntimeException("Catalog contains {$count} products; the safe limit is {$maximum}.");
        }
    }

    private function catalogPayloadCurrency(array $payload): ?string
    {
        $hasRootCurrency = array_key_exists('currency', $payload);
        $hasMetaCurrency = is_array($payload['meta'] ?? null) && array_key_exists('currency', $payload['meta']);
        if (! $hasRootCurrency && ! $hasMetaCurrency) {
            return null;
        }
        $currency = $hasRootCurrency ? $payload['currency'] : $payload['meta']['currency'];

        return is_scalar($currency) && ! is_bool($currency) ? (string) $currency : '';
    }

    /** @param list<array> $products
     * @return array{found: int, created: int, updated: int}
     */
    private function importUrlProducts(KnowledgeSource $source, array $products, ?string $payloadCurrency = null): array
    {
        $this->assertCatalogItemLimit(count($products));
        $found = $created = $updated = 0;

        foreach ($products as $product) {
            $values = $this->normalizeUrlProduct($source, $product, $payloadCurrency);
            if ($values === null) {
                continue;
            }

            $found++;
            $sku = $values['sku'];
            $existing = $sku
                ? $source->agent->products()->where('sku', $sku)->first()
                : $source->agent->products()->where('name', $values['name'])->first();
            if ($existing) {
                $existing->update($values);
                $updated++;
            } else {
                $existing = $source->agent->products()->create($values);
                $created++;
            }

            $this->chunk(
                $source,
                'product',
                $values['name'],
                json_encode($existing->only(['id', 'name', 'sku', 'category', 'description', 'price', 'stock']), JSON_UNESCAPED_UNICODE),
                ['product_id' => $existing->id, 'url' => $values['metadata']['product_url'] ?? $source->url],
            );
        }

        return compact('found', 'created', 'updated');
    }

    /**
     * Import product records discovered through a public storefront search.
     * The same strict price, currency, stock and text sanitization used by URL
     * ingestion applies here; public HTML never becomes trusted instructions.
     *
     * @param  list<array>  $products
     * @return array{found: int, created: int, updated: int}
     */
    public function importDiscoveredUrlProducts(KnowledgeSource $source, array $products): array
    {
        return DB::transaction(fn (): array => $this->importUrlProducts($source, $products));
    }

    /** @return list<array> */
    public function structuredProductsFromHtml(string $body): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body);
        $xpath = new \DOMXPath($dom);
        $products = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
            if (is_array($json)) {
                $products = array_merge($products, $this->findProducts($json));
                $this->assertCatalogItemLimit(count($products));
            }
        }

        return array_values($products);
    }

    /**
     * Read common server-rendered product cards from a public catalogue or
     * search results page. This is a conservative fallback: name, URL, price,
     * and an explicit purchasable control are required before a row is trusted.
     *
     * @return list<array>
     */
    public function storefrontProductsFromHtml(string $html, string $origin): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $cards = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " book-card ")]');
        $products = [];

        foreach ($cards as $card) {
            $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " book-title-strong ")]', $card)->item(0)
                ?? $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " book-hover-title ")]', $card)->item(0);
            $linkNode = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " card-link ")]', $card)->item(0);
            $authorNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " book-author-link ") or contains(concat(" ", normalize-space(@class), " "), " book-author-text ")]', $card)->item(0);
            $imageNode = $xpath->query('.//img[@src]', $card)->item(0);
            $cartNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " toggle-cart-btn ")]', $card)->item(0);
            $oldPriceNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " text-secondary ") or contains(translate(@style, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "line-through")]', $card)->item(0);
            $name = trim((string) ($titleNode?->getAttribute('title') ?: $titleNode?->textContent));
            $url = $this->absoluteCatalogUrl($origin, (string) $linkNode?->getAttribute('href'));
            $text = preg_replace('/\s+/u', ' ', trim((string) $card->textContent)) ?? '';
            preg_match('/₾\s*([0-9]+(?:[.,][0-9]{1,2})?)/u', $text, $priceMatch);
            if ($name === '' || $url === null || empty($priceMatch[1])) {
                continue;
            }

            preg_match('#/(\d+)(?:\?.*)?$#', $url, $idMatch);
            $products[] = [
                'name' => $name,
                'sku' => $idMatch[1] ?? null,
                'author' => trim((string) $authorNode?->textContent) ?: null,
                'category' => 'Books',
                'price' => str_replace(',', '.', $priceMatch[1]),
                'original_price' => $this->priceFromText((string) $oldPriceNode?->textContent),
                'priceCurrency' => 'GEL',
                'stock' => $cartNode ? 1 : 0,
                'stock_precision' => 'availability_only',
                'image' => $this->absoluteCatalogUrl($origin, (string) $imageNode?->getAttribute('src')),
                'url' => $url,
            ];
        }

        return array_slice($products, 0, 100);
    }

    public function storefrontOriginalPriceFromHtml(string $html): ?float
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $node = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " product-price ")]//*[contains(concat(" ", normalize-space(@class), " "), " old-price ")]')->item(0)
            ?? $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " old-price ")]')->item(0);

        return $this->priceFromText((string) $node?->textContent);
    }

    private function priceFromText(string $text): ?float
    {
        if (! preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)/u', $text, $match)) {
            return null;
        }

        $price = (float) str_replace(',', '.', $match[1]);

        return is_finite($price) && $price > 0 ? $price : null;
    }

    private function absoluteCatalogUrl(string $origin, string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/')) {
            $url = $origin.$url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    private function normalizeUrlProduct(KnowledgeSource $source, array $product, ?string $payloadCurrency): ?array
    {
        $offer = is_array($product['offers'] ?? null) ? $product['offers'] : [];
        if (array_is_list($offer)) {
            $offer = collect($offer)->first(fn ($candidate) => is_array($candidate)) ?? [];
        }
        $priceValue = $product['price'] ?? $offer['price'] ?? $offer['lowPrice'] ?? null;
        $priceObject = is_array($priceValue) ? $priceValue : [];
        $rawPrice = $priceObject['amount'] ?? $priceObject['value'] ?? $priceObject['price'] ?? $priceValue;
        if ($rawPrice === null || is_bool($rawPrice) || ! is_scalar($rawPrice)) {
            return null;
        }

        $name = $this->catalogText($product['name'] ?? $product['title'] ?? null, 255);
        $price = $this->number($rawPrice);
        if ($name === '' || ! is_finite($price) || $price <= 0 || $price > 99_999_999.99) {
            return null;
        }

        $workspaceCurrency = strtoupper((string) ($source->agent->organization?->settings['currency'] ?? 'GEL'));
        if (! preg_match('/^[A-Z]{3}$/', $workspaceCurrency)) {
            $workspaceCurrency = 'GEL';
        }
        $currencyValue = $product['currency']
            ?? $product['priceCurrency']
            ?? $product['price_currency']
            ?? $priceObject['currency']
            ?? $priceObject['currency_code']
            ?? $offer['priceCurrency']
            ?? $offer['currency']
            ?? $payloadCurrency
            ?? $this->currencyFromPrice($rawPrice);
        if ($currencyValue !== null && (! is_scalar($currencyValue) || is_bool($currencyValue))) {
            return null;
        }
        $currency = $currencyValue === null ? $workspaceCurrency : strtoupper(trim((string) $currencyValue));
        if (! preg_match('/^[A-Z]{3}$/', $currency) || $currency !== $workspaceCurrency) {
            return null;
        }

        $stock = $this->catalogStock($product, $offer);
        if ($stock === null) {
            return null;
        }

        $sku = $this->catalogText($product['sku'] ?? $product['id'] ?? null, 191) ?: null;
        $image = $this->catalogUrl($product['image_url'] ?? $product['image'] ?? null);
        $productUrl = $this->catalogUrl($product['url'] ?? null);
        $originalPrice = isset($product['original_price']) && is_scalar($product['original_price']) && ! is_bool($product['original_price'])
            ? $this->number($product['original_price'])
            : null;
        if ($originalPrice === null || ! is_finite($originalPrice) || $originalPrice <= $price || $originalPrice > 99_999_999.99) {
            $originalPrice = null;
        }

        $author = $this->catalogText($product['author'] ?? $product['brand']['name'] ?? null, 255) ?: null;
        $genres = $product['genres'] ?? $product['genre'] ?? $product['tags'] ?? [];
        $isbn = $this->catalogText($product['isbn'] ?? $product['isbn_13'] ?? $product['isbn_10'] ?? null, 32) ?: null;
        $category = $this->catalogText($product['category'] ?? null, 255) ?: null;
        $description = $this->catalogText($product['description'] ?? null, 4000) ?: null;

        return [
            'name' => $name,
            'sku' => $sku,
            'category' => $category,
            'description' => $description,
            'search_text' => $this->searchableProductText([
                $name, $sku, $category, $author, $genres, $isbn,
                $product['publisher'] ?? null, $description,
            ]),
            'price' => $price,
            'stock' => $stock,
            'image' => $image,
            'is_active' => true,
            'metadata' => [
                'source_id' => $source->id,
                'source_url' => $source->url,
                'product_url' => $productUrl,
                'external_id' => $this->catalogText($product['id'] ?? null, 191) ?: null,
                'author' => $author,
                'genres' => $this->searchableValues($genres, 120),
                'isbn' => $isbn,
                'currency' => $currency,
                'original_price' => $originalPrice,
                'discount_percent' => $originalPrice
                    ? round((1 - ($price / $originalPrice)) * 100, 1)
                    : null,
                'stock_precision' => ($product['stock_precision'] ?? null) === 'availability_only'
                    ? 'availability_only'
                    : 'exact',
                'text_trust' => 'untrusted_catalog_data',
            ],
        ];
    }

    private function searchableProductText(array $parts): ?string
    {
        $values = [];
        array_walk_recursive($parts, function ($value) use (&$values): void {
            $text = $this->catalogText($value, 4000);
            if ($text !== '') {
                $values[] = $text;
            }
        });
        $text = $this->catalogText(implode(' ', $values), 32_000);

        return $text === '' ? null : $text;
    }

    /** @return list<string> */
    private function searchableValues(mixed $values, int $limit): array
    {
        $result = [];
        $values = (array) $values;
        array_walk_recursive($values, function ($value) use (&$result, $limit): void {
            $text = $this->catalogText($value, $limit);
            if ($text !== '') {
                $result[] = $text;
            }
        });

        return array_values(array_unique($result));
    }

    private function currencyFromPrice(mixed $price): ?string
    {
        if (! is_string($price)) {
            return null;
        }

        return match (true) {
            str_contains($price, '₾'), str_contains(mb_strtolower($price), 'gel'), str_contains($price, 'ლარ') => 'GEL',
            str_contains($price, '$'), str_contains(mb_strtolower($price), 'usd') => 'USD',
            str_contains($price, '€'), str_contains(mb_strtolower($price), 'eur') => 'EUR',
            str_contains($price, '£'), str_contains(mb_strtolower($price), 'gbp') => 'GBP',
            default => null,
        };
    }

    private function catalogStock(array $product, array $offer): ?int
    {
        foreach (['stock', 'quantity'] as $field) {
            if (! array_key_exists($field, $product)) {
                continue;
            }
            $value = $product[$field];
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            if (is_int($value)) {
                return $value >= 0 && $value <= 4_294_967_295 ? $value : null;
            }
            if (is_float($value) && is_finite($value) && floor($value) === $value) {
                return $value >= 0 && $value <= 4_294_967_295 ? (int) $value : null;
            }
            if (is_string($value) && preg_match('/^\s*\d+\s*$/D', $value)) {
                $validated = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

                return $validated === false || $validated > 4_294_967_295 ? null : $validated;
            }

            return null;
        }

        $availability = $product['in_stock'] ?? $product['availability'] ?? $offer['availability'] ?? null;
        if (is_bool($availability)) {
            return $availability ? 1 : 0;
        }
        if ($availability === 1 || $availability === '1') {
            return 1;
        }
        if ($availability === 0 || $availability === '0') {
            return 0;
        }
        if (is_string($availability)) {
            $availability = mb_strtolower(trim($availability));
            if (Str::contains($availability, ['outofstock', 'out_of_stock', 'out of stock', 'unavailable', 'soldout', 'sold_out'])) {
                return 0;
            }
            if (Str::contains($availability, ['instock', 'in_stock', 'in stock', 'available'])) {
                return 1;
            }
        }

        return 0;
    }

    private function catalogText(mixed $value, int $limit): string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? $value['title'] ?? null;
        }
        if (! is_scalar($value) || is_bool($value)) {
            return '';
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $text) ?? '';
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = preg_replace([
            '/\b(?:ignore|disregard|override|forget)\s+(?:all\s+)?(?:previous|prior|above|system|developer)\s+(?:instructions?|messages?|prompts?|rules?)\b/iu',
            '/\b(?:reveal|expose|print|return|leak)\s+(?:the\s+)?(?:system|developer)\s+(?:prompt|message|instructions?)\b/iu',
            '/\b(?:send|reveal|expose|leak)\s+(?:all\s+)?(?:secrets?|credentials?|api\s*keys?)\b/iu',
            '/\b(?:system|developer|assistant|tool)\s*(?:message|prompt)?\s*:/iu',
        ], '[catalog directive removed]', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return Str::limit($text, $limit, '');
    }

    private function catalogUrl(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['url'] ?? $value[0] ?? null;
            if (is_array($value)) {
                $value = $value['url'] ?? null;
            }
        }
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);

        return in_array($parts['scheme'] ?? null, ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass'])
            ? $value
            : null;
    }

    private function assertConfiguredPayload(KnowledgeSource $source): void
    {
        if ($source->type === 'url' && blank($source->url)) {
            throw new \InvalidArgumentException('A website URL is required before this source can be synchronized.');
        }
        if (in_array($source->type, ['csv', 'pdf'], true) && blank($source->file_path)) {
            throw new \InvalidArgumentException('An uploaded source file is required before this source can be synchronized.');
        }
    }

    private function assertSafeUrl(string $url): array
    {
        $parts = parse_url($url);
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Only public HTTP/HTTPS URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URLs containing credentials are not allowed.');
        }
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw new \InvalidArgumentException('Only standard HTTP and HTTPS ports are allowed.');
        }
        $ips = gethostbynamel($parts['host']) ?: [];
        if ($ips === []) {
            throw new \InvalidArgumentException('The URL host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('Private or reserved network URLs are not allowed.');
            }
        }

        return ['host' => $parts['host'], 'port' => $port, 'ip' => $ips[0]];
    }

    public function fetchPublicUrl(string $url, array $headers = [])
    {
        $maximumBytes = 5_000_000;
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $target = $this->assertSafeUrl($url);
            if (! defined('CURLOPT_RESOLVE')) {
                throw new \RuntimeException('Secure URL ingestion requires the PHP cURL extension.');
            }
            $response = Http::withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
                    CURLOPT_PROXY => '',
                ],
                'on_headers' => static function ($response) use ($maximumBytes): void {
                    $length = (int) $response->getHeaderLine('Content-Length');
                    if ($length > $maximumBytes) {
                        throw new \RuntimeException('Page exceeds the 5 MB safety limit.');
                    }
                },
                'progress' => static function ($downloadTotal, $downloadedBytes) use ($maximumBytes): void {
                    if ($downloadedBytes > $maximumBytes || $downloadTotal > $maximumBytes) {
                        throw new \RuntimeException('Page exceeds the 5 MB safety limit.');
                    }
                },
            ])->timeout(20)->retry(2, 300)->withoutRedirecting()->withHeaders(array_merge([
                'User-Agent' => 'LegatusKnowledgeBot/1.0',
            ], $headers))->get($url);
            if ($response->redirect()) {
                $location = $response->header('Location');
                if (! $location) {
                    throw new \RuntimeException('Redirect response did not include a destination.');
                }
                $url = $this->absoluteRedirectUrl($url, $location);

                continue;
            }

            return $response->throw();
        }

        throw new \RuntimeException('The URL exceeded the maximum of three redirects.');
    }

    private function absoluteRedirectUrl(string $from, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($from);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin.'/'.ltrim($location, '/');
    }

    private function findProducts(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        $items = [];
        $type = $data['@type'] ?? null;
        if ($type === 'Product' || (is_array($type) && in_array('Product', $type, true))) {
            $items[] = $data;
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $items = array_merge($items, $this->findProducts($value));
            }
        }

        return $items;
    }

    private function split(string $text, int $size = 1200): array
    {
        $sentences = preg_split('/(?<=[.!?。])\s+/u', trim($text)) ?: [];
        $chunks = [];
        $current = '';
        foreach ($sentences as $sentence) {
            if (mb_strlen($current.' '.$sentence) > $size && $current !== '') {
                $chunks[] = trim($current);
                $current = '';
            }
            $current .= ' '.$sentence;
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c) > 20));
    }

    private function chunk(KnowledgeSource $s, string $kind, ?string $title, string $content, array $metadata = []): void
    {
        $hash = hash('sha256', Str::lower(trim($content)));
        $s->chunks()->updateOrCreate(['content_hash' => $hash], ['agent_id' => $s->agent_id, 'kind' => $kind, 'title' => $title, 'content' => $content, 'metadata' => $metadata]);
    }

    private function number(mixed $v): float
    {
        $value = trim(str_replace(["\u{00A0}", "\u{202F}"], '', (string) $v));
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = preg_replace('/[^0-9,.-]/u', '', $value) ?? '';
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $value = str_replace($thousands, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($lastComma !== false) {
            $value = $this->normalizeSingleSeparator($value, ',');
        } elseif ($lastDot !== false) {
            $value = $this->normalizeSingleSeparator($value, '.');
        }

        $number = is_numeric($value) ? (float) $value : 0.0;

        return $negative ? -abs($number) : $number;
    }

    private function detectDelimiter(string $header): string
    {
        $delimiters = [',', ';', "\t"];

        return collect($delimiters)
            ->sortByDesc(fn (string $delimiter) => count(str_getcsv($header, $delimiter)))
            ->first();
    }

    private function normalizeSingleSeparator(string $value, string $separator): string
    {
        if (substr_count($value, $separator) === 1) {
            $decimalDigits = strlen($value) - strrpos($value, $separator) - 1;

            return $decimalDigits > 0 && $decimalDigits <= 2
                ? str_replace($separator, '.', $value)
                : str_replace($separator, '', $value);
        }

        return str_replace($separator, '', $value);
    }
}
