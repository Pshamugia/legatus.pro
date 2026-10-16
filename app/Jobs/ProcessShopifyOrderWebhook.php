<?php

namespace App\Jobs;

use App\Models\CommerceConnection;
use App\Services\CommerceAttributionService;
use App\Services\ShopifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessShopifyOrderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(public int $connectionId, public array $event) {}

    public function handle(ShopifyClient $shopify, CommerceAttributionService $attributions): void
    {
        $connection = CommerceConnection::query()->find($this->connectionId);
        if (! $connection || $connection->provider !== 'shopify' || $connection->status !== 'active') {
            return;
        }
        $status = strtolower((string) ($this->event['financial_status'] ?? ''));
        $topic = (string) ($this->event['topic'] ?? '');
        if (! in_array($status, ['paid', 'partially_refunded', 'refunded'], true)
            && $topic !== 'refunds/create'
            && blank($this->event['cancelled_at'] ?? null)) {
            return;
        }
        $facts = $shopify->orderAttributionFacts($connection, (string) $this->event['order_id']);
        $token = $facts['attribution_token'];
        if (! $token) {
            return;
        }
        $gross = $facts['gross_amount'] > 0
            ? $facts['gross_amount']
            : max(0, (float) $this->event['gross_amount']);
        $refunded = min($gross, $facts['refunded_amount'] > 0
            ? $facts['refunded_amount']
            : max(0, (float) ($this->event['refund_amount'] ?? 0)));
        $currency = preg_match('/^[A-Z]{3}$/D', $facts['currency'])
            ? $facts['currency']
            : strtoupper((string) $this->event['currency']);
        $normalizedStatus = match (true) {
            filled($facts['cancelled_at']) || filled($this->event['cancelled_at'] ?? null) => 'cancelled',
            $status === 'refunded' || ($gross > 0 && $refunded >= $gross) => 'refunded',
            $status === 'partially_refunded' || $refunded > 0 => 'partially_refunded',
            default => 'paid',
        };
        if ($normalizedStatus === 'cancelled') {
            $refunded = $gross;
        }

        $attributions->recordOrder($connection, [
            'event_id' => (string) $this->event['webhook_id'],
            'event_type' => $topic,
            'order_reference' => (string) $this->event['order_id'],
            'attribution_token' => $token,
            'gross_amount' => $gross,
            'refunded_amount' => $refunded,
            'currency' => $currency,
            'status' => $normalizedStatus,
            'refunded_is_total' => true,
            'occurred_at' => (string) $this->event['occurred_at'],
            'payload_hash' => (string) $this->event['payload_hash'],
        ]);
    }
}
