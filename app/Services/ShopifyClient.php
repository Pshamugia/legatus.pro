<?php

namespace App\Services;

use App\Models\CommerceConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyClient
{
    /** @var array<int, array<int, string|null>> */
    private array $pageCursors = [];

    public function catalog(CommerceConnection $connection, int $page, int $perPage): array
    {
        $cursor = $this->cursorForPage($connection, $page);
        $data = $this->graphql($connection, <<<'GRAPHQL'
query LegatusProducts($first: Int!, $after: String) {
  products(first: $first, after: $after, sortKey: ID) {
    nodes {
      id title descriptionHtml onlineStoreUrl status productType vendor tags updatedAt
      featuredMedia { preview { image { url } } }
      priceRangeV2 { minVariantPrice { amount currencyCode } }
      variants(first: 100) { nodes { id sku availableForSale inventoryQuantity price } }
    }
    pageInfo { hasNextPage endCursor }
  }
  productsCount(limit: 10000) { count }
}
GRAPHQL, ['first' => min(100, max(1, $perPage)), 'after' => $cursor]);
        $connectionData = data_get($data, 'products');
        $nodes = data_get($connectionData, 'nodes');
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new \RuntimeException('Shopify returned an invalid product list.');
        }
        $total = (int) data_get($data, 'productsCount.count', count($nodes));
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->pageCursors[$connection->id][$page + 1] = data_get($connectionData, 'pageInfo.endCursor');

        return [
            'data' => array_map(fn (array $product): array => $this->normalize($product), $nodes),
            'meta' => [
                'sync_mode' => 'authoritative_snapshot',
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
            ],
        ];
    }

    public function search(CommerceConnection $connection, string $query, array $filters): array
    {
        $data = $this->graphql($connection, <<<'GRAPHQL'
query LegatusProductSearch($first: Int!, $query: String!) {
  products(first: $first, query: $query) {
    nodes {
      id title descriptionHtml onlineStoreUrl status productType vendor tags updatedAt
      featuredMedia { preview { image { url } } }
      priceRangeV2 { minVariantPrice { amount currencyCode } }
      variants(first: 100) { nodes { id sku availableForSale inventoryQuantity price } }
    }
  }
}
GRAPHQL, [
            'first' => min(30, max(1, (int) ($filters['limit'] ?? 10))),
            'query' => $query,
        ]);
        $products = data_get($data, 'products.nodes');
        if (! is_array($products) || ! array_is_list($products)) {
            throw new \RuntimeException('Shopify returned an invalid product search response.');
        }
        $normalized = collect($products)->map(fn (array $product): array => $this->normalize($product));
        if ($filters['available_only'] ?? true) {
            $normalized = $normalized->filter(fn (array $product): bool => $product['in_stock'] && $product['purchasable']);
        }
        if (is_numeric($filters['max_price'] ?? null)) {
            $normalized = $normalized->filter(fn (array $product): bool => $product['price'] <= (float) $filters['max_price']);
        }

        return ['data' => $normalized->values()->all()];
    }

    public function availability(CommerceConnection $connection, string|int $productId): array
    {
        $data = $this->graphql($connection, <<<'GRAPHQL'
query LegatusProduct($id: ID!) {
  product(id: $id) {
    id title descriptionHtml onlineStoreUrl status productType vendor tags updatedAt
    featuredMedia { preview { image { url } } }
    priceRangeV2 { minVariantPrice { amount currencyCode } }
    variants(first: 100) { nodes { id sku availableForSale inventoryQuantity price } }
  }
}
GRAPHQL, ['id' => (string) $productId]);
        $product = data_get($data, 'product');
        if (! is_array($product)) {
            throw new \RuntimeException('Shopify product was not found.');
        }
        $normalized = $this->normalize($product);

        return ['data' => [
            'product_id' => $normalized['id'],
            'price' => $normalized['price'],
            'quantity' => $normalized['quantity'],
            'stock_precision' => 'exact',
            'in_stock' => $normalized['in_stock'],
            'purchasable' => $normalized['purchasable'],
            'currency' => $normalized['currency'],
            'checked_at' => now()->toIso8601String(),
        ]];
    }

    public function registerAttributionWebhooks(CommerceConnection $connection): void
    {
        $uri = route('webhooks.commerce.shopify', ['connection' => $connection->webhook_token]);
        foreach (['ORDERS_CREATE', 'ORDERS_UPDATED', 'REFUNDS_CREATE', 'APP_UNINSTALLED'] as $topic) {
            $data = $this->graphql($connection, <<<'GRAPHQL'
mutation LegatusWebhook($topic: WebhookSubscriptionTopic!, $uri: URL!) {
  webhookSubscriptionCreate(topic: $topic, webhookSubscription: {uri: $uri, format: JSON}) {
    webhookSubscription { id topic uri }
    userErrors { field message }
  }
}
GRAPHQL, ['topic' => $topic, 'uri' => $uri]);
            $errors = data_get($data, 'webhookSubscriptionCreate.userErrors', []);
            if (is_array($errors) && $errors !== []) {
                $message = (string) data_get($errors, '0.message', 'Shopify rejected a webhook subscription.');
                if (! str_contains(Str::lower($message), 'already')) {
                    throw new \RuntimeException($message);
                }
            }
        }
    }

    public function orderAttribution(CommerceConnection $connection, string|int $orderId): ?string
    {
        return $this->orderAttributionFacts($connection, $orderId)['attribution_token'];
    }

    /** @return array{attribution_token:?string,gross_amount:float,refunded_amount:float,currency:string,cancelled_at:?string} */
    public function orderAttributionFacts(CommerceConnection $connection, string|int $orderId): array
    {
        $id = str_starts_with((string) $orderId, 'gid://')
            ? (string) $orderId
            : 'gid://shopify/Order/'.preg_replace('/\D/', '', (string) $orderId);
        $data = $this->graphql($connection, <<<'GRAPHQL'
query LegatusOrderAttribution($id: ID!) {
  order(id: $id) {
    totalPriceSet { shopMoney { amount currencyCode } }
    totalRefundedSet { shopMoney { amount currencyCode } }
    cancelledAt
    customerJourneySummary {
      ready
      lastVisit { utmParameters { source medium campaign } landingPage }
      firstVisit { utmParameters { source medium campaign } landingPage }
    }
  }
}
GRAPHQL, ['id' => $id]);
        $order = data_get($data, 'order');
        if (! is_array($order)) {
            throw new \RuntimeException('Shopify order was not found.');
        }
        $journey = data_get($order, 'customerJourneySummary');
        if (! is_array($journey) || ($journey['ready'] ?? false) !== true) {
            throw new \RuntimeException('Shopify order attribution is not ready yet.');
        }
        $token = null;
        foreach (['lastVisit', 'firstVisit'] as $visit) {
            $source = data_get($journey, $visit.'.utmParameters.source');
            $campaign = trim((string) data_get($journey, $visit.'.utmParameters.campaign', ''));
            if ($source === 'legatus' && preg_match('/^[0-9a-f-]{36}$/iD', $campaign)) {
                $token = $campaign;
                break;
            }
        }

        return [
            'attribution_token' => $token,
            'gross_amount' => max(0, (float) data_get($order, 'totalPriceSet.shopMoney.amount', 0)),
            'refunded_amount' => max(0, (float) data_get($order, 'totalRefundedSet.shopMoney.amount', 0)),
            'currency' => strtoupper((string) data_get($order, 'totalPriceSet.shopMoney.currencyCode', '')),
            'cancelled_at' => filled($order['cancelledAt'] ?? null) ? (string) $order['cancelledAt'] : null,
        ];
    }

    public function graphql(CommerceConnection $connection, string $query, array $variables = []): array
    {
        $response = $this->request($connection)->post($this->endpoint($connection), [
            'query' => $query,
            'variables' => $variables,
        ])->throw()->json();
        if (! is_array($response)) {
            throw new \RuntimeException('Shopify returned an invalid GraphQL response.');
        }
        if (is_array($response['errors'] ?? null) && $response['errors'] !== []) {
            throw new \RuntimeException((string) data_get($response, 'errors.0.message', 'Shopify GraphQL request failed.'));
        }
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new \RuntimeException('Shopify GraphQL response contained no data.');
        }

        return $data;
    }

    private function request(CommerceConnection $connection): PendingRequest
    {
        $token = $this->accessToken($connection);
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Shopify access token is unavailable. Reconnect the store.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->connectTimeout(5)
            ->timeout(25);
    }

    private function accessToken(CommerceConnection $connection): string
    {
        $expiresAt = data_get($connection->settings, 'token_expires_at');
        if (! $expiresAt || now()->addMinutes(5)->lt(Carbon::parse($expiresAt))) {
            return (string) data_get(json_decode($connection->secret, true), 'access_token', '');
        }

        return Cache::lock('shopify-token-refresh:'.$connection->id, 20)->block(5, function () use ($connection): string {
            $connection->refresh();
            $expiresAt = data_get($connection->settings, 'token_expires_at');
            $credentials = json_decode($connection->secret, true);
            if ($expiresAt && now()->addMinutes(5)->lt(Carbon::parse($expiresAt))) {
                return (string) data_get($credentials, 'access_token', '');
            }
            $refreshToken = data_get($credentials, 'refresh_token');
            if (! is_string($refreshToken) || $refreshToken === '') {
                throw new \RuntimeException('Shopify refresh token is unavailable. Reconnect the store.');
            }
            $response = Http::asForm()->acceptJson()->post(rtrim($connection->base_url, '/').'/admin/oauth/access_token', [
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => config('services.shopify.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])->throw()->json();
            if (! is_array($response) || blank($response['access_token'] ?? null) || blank($response['refresh_token'] ?? null)) {
                throw new \RuntimeException('Shopify returned an invalid token refresh response.');
            }
            $settings = $connection->settings ?? [];
            $settings['token_expires_at'] = now()->addSeconds(max(60, (int) ($response['expires_in'] ?? 3600)))->toIso8601String();
            $settings['refresh_token_expires_at'] = now()->addSeconds(max(60, (int) ($response['refresh_token_expires_in'] ?? 7_776_000)))->toIso8601String();
            $connection->update([
                'secret' => json_encode([
                    'access_token' => $response['access_token'],
                    'refresh_token' => $response['refresh_token'],
                ], JSON_THROW_ON_ERROR),
                'settings' => $settings,
            ]);

            return (string) $response['access_token'];
        });
    }

    private function endpoint(CommerceConnection $connection): string
    {
        $version = (string) config('services.shopify.api_version', '2026-07');

        return rtrim($connection->base_url, '/').'/admin/api/'.$version.'/graphql.json';
    }

    private function cursorForPage(CommerceConnection $connection, int $page): ?string
    {
        if ($page === 1) {
            $this->pageCursors[$connection->id] = [1 => null];

            return null;
        }
        if (! array_key_exists($page, $this->pageCursors[$connection->id] ?? [])) {
            throw new \RuntimeException('Shopify catalog pages must be synchronized in sequence.');
        }

        return $this->pageCursors[$connection->id][$page];
    }

    private function normalize(array $product): array
    {
        $variants = collect(data_get($product, 'variants.nodes', []))->filter(fn ($variant): bool => is_array($variant));
        $quantity = max(0, (int) $variants->sum(fn (array $variant): int => max(0, (int) ($variant['inventoryQuantity'] ?? 0))));
        $available = $variants->contains(fn (array $variant): bool => (bool) ($variant['availableForSale'] ?? false));

        return [
            'id' => (string) ($product['id'] ?? ''),
            'name' => (string) ($product['title'] ?? ''),
            'sku' => (string) data_get($variants->first(), 'sku', ''),
            'price' => (float) data_get($product, 'priceRangeV2.minVariantPrice.amount', 0),
            'currency' => (string) data_get($product, 'priceRangeV2.minVariantPrice.currencyCode', 'USD'),
            'quantity' => $quantity,
            'stock_precision' => 'exact',
            'in_stock' => $available,
            'purchasable' => ($product['status'] ?? null) === 'ACTIVE' && $available,
            'url' => (string) ($product['onlineStoreUrl'] ?? ''),
            'image_url' => (string) data_get($product, 'featuredMedia.preview.image.url', ''),
            'category' => (string) ($product['productType'] ?? ''),
            'brand' => (string) ($product['vendor'] ?? ''),
            'tags' => (array) ($product['tags'] ?? []),
            'description' => (string) ($product['descriptionHtml'] ?? ''),
            'updated_at' => (string) ($product['updatedAt'] ?? ''),
        ];
    }
}
