<?php

namespace App\Services;

use App\Models\CommerceConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CommerceConnectorClient
{
    public function __construct(private CommerceOriginValidator $origins) {}

    public function catalog(CommerceConnection $connection, int $page = 1, int $perPage = 100): array
    {
        return $this->request($connection, 'GET', '/api/legatus/v1/catalog', [
            'page' => $page,
            'per_page' => min(100, max(1, $perPage)),
        ]);
    }

    public function search(CommerceConnection $connection, string $query, array $filters = []): array
    {
        return $this->request($connection, 'GET', '/api/legatus/v1/products/search', array_filter([
            'q' => $query,
            'page' => 1,
            'per_page' => min(30, max(1, (int) ($filters['limit'] ?? 10))),
            'language' => $filters['language'] ?? null,
            'available_only' => $filters['available_only'] ?? 1,
            'min_price' => $filters['min_price'] ?? null,
            'max_price' => $filters['max_price'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function availability(CommerceConnection $connection, string|int $productId): array
    {
        return $this->request($connection, 'GET', '/api/legatus/v1/products/'.rawurlencode((string) $productId).'/availability');
    }

    public function deliveryQuote(CommerceConnection $connection, string $city): array
    {
        return $this->request($connection, 'POST', '/api/legatus/v1/delivery-quote', [], ['city' => $city]);
    }

    public function request(
        CommerceConnection $connection,
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
    ): array {
        $method = strtoupper($method);
        $queryString = $query === [] ? '' : http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $requestUri = $path.($queryString === '' ? '' : '?'.$queryString);
        $body = $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::random(32);
        $canonical = implode("\n", [$method, $requestUri, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $connection->secret);

        $target = $this->origins->connectionTarget($connection->base_url);
        $request = $this->http($target)->withHeaders([
            'X-Legatus-Key' => $connection->key_id,
            'X-Legatus-Timestamp' => $timestamp,
            'X-Legatus-Nonce' => $nonce,
            'X-Legatus-Signature' => $signature,
        ]);
        $url = $target['origin'].$requestUri;
        $response = $json === null
            ? $request->send($method, $url)
            : $request->withBody($body, 'application/json')->send($method, $url);

        $responseBody = $this->boundedBody($response);
        $response->throw();

        return $this->decodeJson($responseBody);
    }

    /** @param array{host: string, addresses: list<string>, is_ip_literal: bool} $target */
    private function http(array $target): PendingRequest
    {
        $options = ['stream' => true];
        if (! $target['is_ip_literal']) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new \RuntimeException('Secure commerce connections require the PHP cURL extension.');
            }

            $address = collect($target['addresses'])->first(fn (string $candidate): bool => ! str_contains($candidate, ':'))
                ?? $target['addresses'][0];
            $curlAddress = str_contains($address, ':') ? "[{$address}]" : $address;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$target['host']}:443:{$curlAddress}"]];
        }

        return Http::withOptions($options)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(4)
            ->withoutRedirecting()
            // A signed nonce is single-use. Job-level retries create a fresh request
            // and signature; transport-level replay would correctly be rejected.
            ->timeout(12);
    }

    private function boundedBody(Response $response): string
    {
        $maximumBytes = max(1024, (int) config('legatus.commerce_max_response_bytes', 5_242_880));
        $declaredLength = $response->header('Content-Length');
        if (is_string($declaredLength) && ctype_digit($declaredLength) && (int) $declaredLength > $maximumBytes) {
            throw new \RuntimeException('The commerce connector response exceeded the configured size limit.');
        }

        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (! $stream->eof()) {
            $remaining = $maximumBytes - strlen($body);
            $chunk = $stream->read(min(8192, $remaining + 1));
            $body .= $chunk;
            if (strlen($body) > $maximumBytes) {
                throw new \RuntimeException('The commerce connector response exceeded the configured size limit.');
            }
        }

        return $body;
    }

    private function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('The commerce connector response must be a JSON object.');
        }

        return $decoded;
    }
}
