<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyOrderWebhook;
use App\Models\CommerceConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, CommerceConnection $connection): Response
    {
        abort_unless($connection->provider === 'shopify', 404);
        $body = $request->getContent();
        if (strlen($body) > 1_048_576) {
            abort(413);
        }
        $expected = base64_encode(hash_hmac('sha256', $body, (string) config('services.shopify.client_secret'), true));
        abort_unless(hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256')), 401);
        abort_unless(strtolower((string) $request->header('X-Shopify-Shop-Domain')) === parse_url($connection->base_url, PHP_URL_HOST), 401);
        $topic = strtolower((string) $request->header('X-Shopify-Topic'));
        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id')) ?: hash('sha256', $topic."\n".$body);
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422);
        }
        abort_unless(is_array($payload), 422);

        if ($topic === 'app/uninstalled') {
            $connection->agent->products()->where('commerce_connection_id', $connection->id)->update(['is_active' => false]);
            $connection->update(['status' => 'disconnected', 'last_error' => 'shopify_app_uninstalled']);

            return response('', 204);
        }

        ProcessShopifyOrderWebhook::dispatch($connection->id, [
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'order_id' => (string) ($payload['order_id'] ?? $payload['id'] ?? ''),
            'gross_amount' => (string) ($payload['total_price'] ?? $payload['amount'] ?? '0'),
            'currency' => (string) ($payload['currency'] ?? data_get($connection->settings, 'currency', 'USD')),
            'financial_status' => (string) ($payload['financial_status'] ?? ''),
            'cancelled_at' => $payload['cancelled_at'] ?? null,
            'refund_amount' => (string) collect($payload['refund_line_items'] ?? [])->sum(fn ($line): float => (float) data_get($line, 'subtotal', 0)),
            'occurred_at' => (string) ($payload['processed_at'] ?? $payload['created_at'] ?? now()->toIso8601String()),
            'payload_hash' => hash('sha256', $body),
        ])->onQueue('channels');

        return response('', 204);
    }
}
