<?php

namespace App\Http\Controllers;

use App\Models\CommerceConnection;
use App\Services\CommerceAttributionService;
use App\Services\WooCommerceClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WooCommerceWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        CommerceConnection $connection,
        CommerceAttributionService $attributions,
        WooCommerceClient $wooCommerce,
    ): Response {
        abort_unless($connection->provider === 'woocommerce' && filled($connection->webhook_secret), 404);
        $body = $request->getContent();
        if (strlen($body) > 1_048_576) {
            abort(413);
        }
        $expected = base64_encode(hash_hmac('sha256', $body, $connection->webhook_secret, true));
        abort_unless(hash_equals($expected, (string) $request->header('X-WC-Webhook-Signature')), 401);
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422);
        }
        abort_unless(is_array($payload), 422);
        $topic = strtolower((string) $request->header('X-WC-Webhook-Topic', ''));

        if ($topic === 'refund.created') {
            $parentId = $payload['parent_id'] ?? null;
            if (! is_scalar($parentId) || (string) $parentId === '') {
                return response('', 204);
            }
            $refundAmount = abs((float) ($payload['amount'] ?? 0));
            $payload = $wooCommerce->order($connection, $parentId);
            $payload['_legatus_refund_amount'] = $refundAmount;
        }

        $token = $this->attributionToken($payload);
        $status = strtolower((string) ($payload['status'] ?? ''));
        if (! $token || ! in_array($status, ['processing', 'completed', 'refunded', 'cancelled'], true)) {
            return response('', 204);
        }
        $gross = max(0, (float) ($payload['total'] ?? 0));
        $refunded = $status === 'refunded'
            ? $gross
            : max(0, (float) ($payload['_legatus_refund_amount'] ?? $this->refundTotal($payload)));
        $normalizedStatus = match (true) {
            $status === 'cancelled' => 'cancelled',
            $refunded >= $gross && $gross > 0 => 'refunded',
            $refunded > 0 => 'partially_refunded',
            default => 'paid',
        };
        if ($normalizedStatus === 'cancelled') {
            $refunded = $gross;
        }
        $eventId = trim((string) $request->header('X-WC-Webhook-Delivery-ID'));
        if ($eventId === '') {
            $eventId = hash('sha256', $topic."\n".$body);
        }

        $attributions->recordOrder($connection, [
            'event_id' => $eventId,
            'event_type' => $topic ?: 'order.updated',
            'order_reference' => (string) ($payload['id'] ?? ''),
            'attribution_token' => $token,
            'gross_amount' => $gross,
            'refunded_amount' => $refunded,
            'currency' => (string) ($payload['currency'] ?? data_get($connection->settings, 'currency', 'USD')),
            'status' => $normalizedStatus,
            'occurred_at' => (string) ($payload['date_paid_gmt'] ?? $payload['date_created_gmt'] ?? now()->toIso8601String()),
            'payload_hash' => hash('sha256', $body),
        ]);

        return response('', 204);
    }

    private function attributionToken(array $order): ?string
    {
        $meta = collect($order['meta_data'] ?? [])->filter(fn ($item): bool => is_array($item));
        $source = $meta->first(fn (array $item): bool => ($item['key'] ?? null) === '_wc_order_attribution_utm_source');
        $campaign = $meta->first(fn (array $item): bool => ($item['key'] ?? null) === '_wc_order_attribution_utm_campaign');
        $token = is_array($campaign) ? trim((string) ($campaign['value'] ?? '')) : '';
        if (($source['value'] ?? null) !== 'legatus' || ! preg_match('/^[0-9a-f-]{36}$/iD', $token)) {
            return null;
        }

        return $token;
    }

    private function refundTotal(array $order): float
    {
        return collect($order['refunds'] ?? [])->sum(fn ($refund): float => is_array($refund)
            ? abs((float) ($refund['total'] ?? 0))
            : 0);
    }
}
