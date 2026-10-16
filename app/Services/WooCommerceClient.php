<?php

namespace App\Services;

use App\Models\CommerceConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WooCommerceClient
{
    public function currency(string $baseUrl, string $consumerKey, string $consumerSecret): string
    {
        $response = Http::acceptJson()
            ->withBasicAuth($consumerKey, $consumerSecret)
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting()
            ->get(rtrim($baseUrl, '/').'/wp-json/wc/v3/settings/general/woocommerce_currency');
        $currency = strtoupper((string) data_get($response->throw()->json(), 'value', ''));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    public function registerAttributionWebhooks(CommerceConnection $connection): void
    {
        $deliveryUrl = route('webhooks.commerce.woocommerce', ['connection' => $connection->webhook_token]);
        $existing = $this->request($connection)
            ->get($this->endpoint($connection, '/wp-json/wc/v3/webhooks'), ['per_page' => 100])
            ->throw()
            ->json();
        $existing = is_array($existing) ? $existing : [];

        foreach (['order.created', 'order.updated', 'refund.created'] as $topic) {
            $found = collect($existing)->contains(fn ($webhook): bool => is_array($webhook)
                && ($webhook['topic'] ?? null) === $topic
                && rtrim((string) ($webhook['delivery_url'] ?? ''), '/') === rtrim($deliveryUrl, '/'));
            if ($found) {
                continue;
            }

            $this->request($connection)->post($this->endpoint($connection, '/wp-json/wc/v3/webhooks'), [
                'name' => 'Legatus sales attribution · '.$topic,
                'topic' => $topic,
                'delivery_url' => $deliveryUrl,
                'secret' => $connection->webhook_secret,
                'status' => 'active',
            ])->throw();
        }
    }

    public function catalog(CommerceConnection $connection, int $page, int $perPage): array
    {
        $response = $this->request($connection)->get($this->endpoint($connection, '/wp-json/wc/v3/products'), [
            'page' => $page,
            'per_page' => $perPage,
            'status' => 'publish',
            'orderby' => 'id',
            'order' => 'asc',
        ]);
        $products = $this->jsonList($response);

        return [
            'data' => array_map(fn (array $product): array => $this->normalize($connection, $product), $products),
            'meta' => [
                'sync_mode' => 'authoritative_snapshot',
                'current_page' => $page,
                'last_page' => max(1, (int) $response->header('X-WP-TotalPages', 1)),
                'total' => max(count($products), (int) $response->header('X-WP-Total', count($products))),
            ],
        ];
    }

    public function search(CommerceConnection $connection, string $query, array $filters): array
    {
        $response = $this->request($connection)->get($this->endpoint($connection, '/wp-json/wc/v3/products'), array_filter([
            'search' => $query,
            'per_page' => min(30, max(1, (int) ($filters['limit'] ?? 10))),
            'status' => 'publish',
            'stock_status' => ($filters['available_only'] ?? 1) ? 'instock' : null,
            'max_price' => $filters['max_price'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        return ['data' => array_map(fn (array $product): array => $this->normalize($connection, $product), $this->jsonList($response))];
    }

    public function availability(CommerceConnection $connection, string|int $productId): array
    {
        $response = $this->request($connection)->get($this->endpoint(
            $connection,
            '/wp-json/wc/v3/products/'.rawurlencode((string) $productId),
        ));
        $product = $response->throw()->json();
        if (! is_array($product)) {
            throw new \RuntimeException('WooCommerce returned an invalid product response.');
        }
        $normalized = $this->normalize($connection, $product);

        return ['data' => [
            'product_id' => $normalized['id'],
            'price' => $normalized['price'],
            'quantity' => $normalized['quantity'],
            'stock_precision' => $normalized['stock_precision'],
            'in_stock' => $normalized['in_stock'],
            'purchasable' => $normalized['purchasable'],
            'currency' => $normalized['currency'],
            'checked_at' => now()->toIso8601String(),
        ]];
    }

    public function order(CommerceConnection $connection, string|int $orderId): array
    {
        $order = $this->request($connection)
            ->get($this->endpoint($connection, '/wp-json/wc/v3/orders/'.rawurlencode((string) $orderId)))
            ->throw()
            ->json();
        if (! is_array($order)) {
            throw new \RuntimeException('WooCommerce returned an invalid order response.');
        }

        return $order;
    }

    private function request(CommerceConnection $connection): PendingRequest
    {
        return Http::acceptJson()
            ->withBasicAuth($connection->key_id, $connection->secret)
            ->connectTimeout(5)
            ->timeout(20)
            ->withoutRedirecting();
    }

    private function endpoint(CommerceConnection $connection, string $path): string
    {
        return rtrim($connection->base_url, '/').$path;
    }

    /** @return list<array> */
    private function jsonList(Response $response): array
    {
        $response->throw();
        $data = $response->json();
        if (! is_array($data) || ! array_is_list($data)) {
            throw new \RuntimeException('WooCommerce returned an invalid product list.');
        }

        return $data;
    }

    private function normalize(CommerceConnection $connection, array $product): array
    {
        $managesStock = is_numeric($product['stock_quantity'] ?? null);
        $inStock = ($product['stock_status'] ?? null) === 'instock';
        $quantity = $managesStock ? max(0, (int) $product['stock_quantity']) : ($inStock ? 1 : 0);
        $categories = collect($product['categories'] ?? [])->pluck('name')->filter()->values();
        $tags = collect($product['tags'] ?? [])->pluck('name')->filter()->values()->all();
        $attributes = collect($product['attributes'] ?? [])->flatMap(fn ($attribute) => is_array($attribute)
            ? array_merge([(string) ($attribute['name'] ?? '')], (array) ($attribute['options'] ?? []))
            : [])->filter()->values()->all();
        $currency = strtoupper((string) data_get($connection->settings, 'currency', 'USD'));

        return [
            'id' => (string) ($product['id'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'sku' => (string) ($product['sku'] ?? ''),
            'price' => is_numeric($product['price'] ?? null) ? (float) $product['price'] : 0,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'GEL',
            'quantity' => $quantity,
            'stock_precision' => $managesStock ? 'exact' : 'availability_only',
            'in_stock' => $inStock,
            'purchasable' => (bool) ($product['purchasable'] ?? true) && $inStock,
            'url' => (string) ($product['permalink'] ?? ''),
            'image_url' => (string) data_get($product, 'images.0.src', ''),
            'category' => $categories->first(),
            'genres' => array_values(array_unique(array_merge($tags, $attributes))),
            'description' => (string) ($product['description'] ?? ''),
            'details' => (string) ($product['short_description'] ?? ''),
            'updated_at' => (string) ($product['date_modified_gmt'] ?? $product['date_modified'] ?? ''),
        ];
    }
}
