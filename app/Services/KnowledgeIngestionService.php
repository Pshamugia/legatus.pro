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
                $values = ['name' => $name, 'sku' => $sku ?: null, 'category' => $data['category'] ?? null, 'description' => $data['description'] ?? null, 'price' => $price, 'stock' => max(0, (int) $this->number($data['stock'] ?? $data['quantity'] ?? 0)), 'image' => $data['image'] ?? $data['image_url'] ?? null, 'is_active' => true, 'metadata' => ['source_id' => $source->id]];
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
        $html = $response->body();
        if (strlen($html) > 5_000_000) {
            throw new \RuntimeException('Page exceeds the 5 MB safety limit.');
        }
        $found = $created = $webChunks = 0;
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
            foreach ($this->findProducts($json) as $p) {
                $name = trim($p['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $offer = is_array($p['offers'] ?? null) ? $p['offers'] : [];
                if (array_is_list($offer)) {
                    $offer = collect($offer)->first(fn ($candidate) => is_array($candidate)) ?? [];
                }
                $currency = strtoupper((string) ($offer['priceCurrency'] ?? $p['priceCurrency'] ?? ''));
                $workspaceCurrency = strtoupper((string) ($source->agent->organization?->settings['currency'] ?? 'GEL'));
                if ($currency !== '' && $currency !== $workspaceCurrency) {
                    continue;
                }
                $rawPrice = $offer['price'] ?? $offer['lowPrice'] ?? null;
                if ($rawPrice === null) {
                    continue;
                }
                $price = $this->number($rawPrice);
                if ($price <= 0) {
                    continue;
                }
                $found++;
                $sku = $p['sku'] ?? null;
                $existing = $sku ? $source->agent->products()->where('sku', $sku)->first() : $source->agent->products()->where('name', $name)->first();
                $values = ['name' => $name, 'sku' => $sku, 'category' => $p['category'] ?? null, 'description' => strip_tags($p['description'] ?? ''), 'price' => $price, 'stock' => Str::contains($offer['availability'] ?? '', 'InStock') ? 1 : 0, 'image' => is_array($p['image'] ?? null) ? ($p['image'][0] ?? null) : ($p['image'] ?? null), 'is_active' => true, 'metadata' => ['source_id' => $source->id, 'source_url' => $source->url, 'currency' => $currency ?: $workspaceCurrency]];
                if ($existing) {
                    $existing->update($values);
                } else {
                    $existing = $source->agent->products()->create($values);
                    $created++;
                }
                $this->chunk($source, 'product', $name, json_encode($existing->only(['id', 'name', 'category', 'description', 'price', 'stock']), JSON_UNESCAPED_UNICODE), ['product_id' => $existing->id, 'url' => $source->url]);
            }
        }
        foreach ($xpath->query('//script|//style|//nav|//footer') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $text = preg_replace('/\s+/u', ' ', trim($dom->textContent));
        foreach ($this->split($text) as $i => $content) {
            $this->chunk($source, 'webpage', $source->name.' · '.($i + 1), $content, ['url' => $source->url]);
            $webChunks++;
        }
        if ($found === 0 && $webChunks === 0) {
            throw new \RuntimeException('The website did not contain any accepted products or searchable content.');
        }

        return ['items_found' => $found ?: $webChunks, 'items_created' => $created, 'items_updated' => max(0, $found - $created), 'content_hash' => hash('sha256', $html)];
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

    private function fetchPublicUrl(string $url)
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
            ])->timeout(20)->retry(2, 300)->withoutRedirecting()->withHeaders(['User-Agent' => 'LegatusKnowledgeBot/1.0'])->get($url);
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
