<?php

namespace App\Services;

use App\Models\CommerceConnection;

class CommerceWebhookProvisioner
{
    public function __construct(
        private WooCommerceClient $wooCommerce,
        private ShopifyClient $shopify,
    ) {}

    public function provision(CommerceConnection $connection): void
    {
        match ($connection->provider) {
            'woocommerce' => $this->wooCommerce->registerAttributionWebhooks($connection),
            'shopify' => $this->shopify->registerAttributionWebhooks($connection),
            default => throw new \InvalidArgumentException('This provider does not support automatic attribution webhooks.'),
        };
        $connection->update(['settings' => array_merge($connection->settings ?? [], [
            'attribution_status' => 'active',
            'attribution_model' => $connection->provider === 'shopify'
                ? 'shopify_customer_journey'
                : 'last_click',
            'attribution_webhooks_checked_at' => now()->toIso8601String(),
        ])]);
    }

    public function markPending(CommerceConnection $connection): void
    {
        $connection->update(['settings' => array_merge($connection->settings ?? [], [
            'attribution_status' => 'pending',
            'attribution_webhooks_checked_at' => now()->toIso8601String(),
        ])]);
    }
}
