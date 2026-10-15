<?php

namespace App\Services;

final class CommerceIntegrationGuideService
{
    /** @return array{platform: string, label: string, summary: string, steps: list<string>} */
    public function guide(string $platform): array
    {
        return match ($platform) {
            'wordpress' => [
                'platform' => 'woocommerce',
                'label' => 'WooCommerce',
                'summary' => 'Use the public catalog for a no-code start, or install a server-side connector for live stock, checkout and order actions.',
                'steps' => [
                    'Start with the public catalog in Knowledge if product discovery is enough.',
                    'For live commerce, give the connector contract to the WordPress developer or plugin provider.',
                    'Create a unique Key ID and 32+ character secret inside the server-side connector, then verify the connection here.',
                ],
            ],
            'shopify' => [
                'platform' => 'shopify',
                'label' => 'Shopify',
                'summary' => 'A Shopify custom or approved app must expose the Legatus connector contract from a secure server.',
                'steps' => [
                    'Start with the public storefront catalog in Knowledge if live actions are not required.',
                    'For live commerce, give the connector contract to the Shopify app developer.',
                    'Keep Shopify Admin API tokens in the connector server; enter only its Legatus Key ID and shared secret here.',
                ],
            ],
            'magento' => [
                'platform' => 'magento',
                'label' => 'Magento / Adobe Commerce',
                'summary' => 'A server-side module can map Magento catalog and order APIs to the Legatus connector contract.',
                'steps' => [
                    'Use Knowledge for a public-catalog-only connection.',
                    'For live commerce, give the connector contract to the Magento module developer.',
                    'Verify the generated Key ID and shared secret here only after the module is enabled over HTTPS.',
                ],
            ],
            default => [
                'platform' => 'custom',
                'label' => 'Custom or other store',
                'summary' => 'Any commerce platform can connect through the same signed, tenant-safe API contract.',
                'steps' => [
                    'Use a public catalog in Knowledge when real-time actions are unnecessary.',
                    'For live commerce, give the connector contract to the store developer.',
                    'Expose the supported endpoints over HTTPS, create a unique Key ID and shared secret, then verify here.',
                ],
            ],
        };
    }

    /** @return array<string, mixed> */
    public function contract(): array
    {
        return [
            'name' => 'Legatus Universal Commerce Connector',
            'version' => '1.0',
            'transport' => [
                'base_url' => 'Public HTTPS origin only; paths are fixed by this contract.',
                'request_headers' => [
                    'X-Legatus-Key' => 'Connector-generated public key identifier.',
                    'X-Legatus-Timestamp' => 'Unix timestamp.',
                    'X-Legatus-Nonce' => 'Unique request nonce.',
                    'X-Legatus-Signature' => 'Lowercase hex HMAC-SHA256 using the shared secret.',
                ],
                'canonical_request' => "METHOD\nPATH_WITH_QUERY\nTIMESTAMP\nNONCE\nSHA256_RAW_BODY",
                'requirements' => [
                    'Reject stale timestamps and reused nonces.',
                    'Compare signatures in constant time.',
                    'Never expose store administrator credentials to Legatus.',
                    'Return JSON objects and use non-2xx status codes for failures.',
                ],
            ],
            'required_endpoints' => [
                ['method' => 'GET', 'path' => '/api/legatus/v1/catalog', 'query' => ['page', 'per_page'], 'purpose' => 'Paginated authoritative product snapshot.'],
                ['method' => 'GET', 'path' => '/api/legatus/v1/products/search', 'query' => ['query', 'category', 'max_price'], 'purpose' => 'Live tenant catalog search using the same safe product fields.'],
                ['method' => 'GET', 'path' => '/api/legatus/v1/products/{id}/availability', 'response_data' => ['purchasable' => true, 'in_stock' => true, 'quantity' => 4, 'price' => 19.99, 'currency' => 'GEL'], 'purpose' => 'Current price and purchasability.'],
                ['method' => 'GET', 'path' => '/api/legatus/v1/capabilities', 'response_data' => ['capabilities' => ['delivery_quote', 'order_status', 'cart_create']], 'purpose' => 'Explicitly advertise only implemented optional actions.'],
            ],
            'optional_endpoints' => [
                ['capability' => 'delivery_quote', 'method' => 'POST', 'path' => '/api/legatus/v1/delivery-quote', 'request' => ['city' => 'Tbilisi']],
                ['capability' => 'order_status', 'method' => 'POST', 'path' => '/api/legatus/v1/orders/status', 'request' => ['order_reference' => 'customer-supplied reference', 'contact' => 'customer-supplied matching contact']],
                ['capability' => 'cart_create', 'method' => 'POST', 'path' => '/api/legatus/v1/carts', 'request_fields' => ['items', 'idempotency_key', 'attribution_token'], 'response_data' => ['checkout_url' => 'https://store.example/checkout/cart-id']],
                ['capabilities' => ['cancel_order', 'return_order', 'change_shipping_address'], 'method' => 'POST', 'path' => '/api/legatus/v1/actions/preview', 'request_fields' => ['action', 'identity', 'input'], 'note' => 'Return a human-readable preview; do not mutate the order.'],
                ['capabilities' => ['cancel_order', 'return_order', 'change_shipping_address'], 'method' => 'POST', 'path' => '/api/legatus/v1/actions/execute', 'request_fields' => ['action', 'preview_reference', 'idempotency_key'], 'note' => 'Execute only the exact previously previewed action and return completed=true.'],
            ],
            'catalog_response' => [
                'data' => [[
                    'id' => 'stable-product-id',
                    'name' => 'Product name',
                    'description' => 'Verified product description',
                    'url' => 'https://store.example/products/stable-product-id',
                    'image_url' => 'https://store.example/images/stable-product-id.jpg',
                    'price' => 19.99,
                    'currency' => 'GEL',
                    'stock' => 4,
                    'is_active' => true,
                ]],
                'meta' => [
                    'sync_mode' => 'authoritative_snapshot',
                    'page' => 1,
                    'last_page' => 1,
                ],
            ],
            'commerce_events' => [
                'webhook' => '/webhooks/commerce',
                'events' => ['checkout.completed', 'checkout.refunded'],
                'note' => 'Sign webhooks with the same request authentication scheme. Provisioning never trusts a browser redirect.',
            ],
        ];
    }
}
