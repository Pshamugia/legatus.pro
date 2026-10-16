<?php

namespace App\Services;

use App\Models\AiReel;
use App\Models\CommerceAttribution;
use App\Models\CommerceAttributionEvent;
use App\Models\CommerceAttributionOrder;
use App\Models\CommerceConnection;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\SocialMediaPost;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommerceAttributionService
{
    public function conversationProductUrl(Conversation $conversation, Product $product, string $destination): string
    {
        abort_unless($product->agent_id === $conversation->agent_id, 404);

        return $this->trackedUrl(
            agentId: (int) $conversation->agent_id,
            destination: $destination,
            sourceType: 'chat',
            sourceId: (int) $conversation->id,
            channel: $conversation->channel,
            conversationId: (int) $conversation->id,
            productId: (int) $product->id,
        );
    }

    public function socialPostUrl(SocialMediaPost $post, string $destination): string
    {
        return $this->trackedUrl(
            agentId: (int) $post->agent_id,
            destination: $destination,
            sourceType: 'social_post',
            sourceId: (int) $post->id,
            channel: $post->provider,
            productId: $post->product_id ? (int) $post->product_id : null,
        );
    }

    public function reelUrl(AiReel $reel, string $destination, ?string $provider = null): string
    {
        return $this->trackedUrl(
            agentId: (int) $reel->agent_id,
            destination: $destination,
            sourceType: 'reel',
            sourceId: (int) $reel->id,
            channel: $provider,
            productId: $reel->product_id ? (int) $reel->product_id : null,
        );
    }

    public function visit(CommerceAttribution $attribution): string
    {
        DB::transaction(function () use ($attribution): void {
            $locked = CommerceAttribution::query()->lockForUpdate()->findOrFail($attribution->id);
            $locked->update([
                'click_count' => $locked->click_count + 1,
                'first_clicked_at' => $locked->first_clicked_at ?: now(),
                'last_clicked_at' => now(),
            ]);
        });

        return $this->withToken($attribution->destination_url, $attribution->token);
    }

    /**
     * @param  array{event_id:string,event_type:string,order_reference:string,attribution_token:string,gross_amount:numeric-string|int|float,refunded_amount?:numeric-string|int|float,refunded_is_total?:bool,currency:string,status?:string,occurred_at?:string,payload_hash:string}  $data
     */
    public function recordOrder(CommerceConnection $connection, array $data): bool
    {
        if (blank($data['event_id'] ?? null)
            || blank($data['order_reference'] ?? null)
            || ! preg_match('/^[A-Z]{3}$/D', strtoupper((string) ($data['currency'] ?? '')))) {
            throw new \InvalidArgumentException('The commerce attribution event is incomplete.');
        }

        try {
            return DB::transaction(function () use ($connection, $data): bool {
                if (CommerceAttributionEvent::query()
                    ->where('provider', $connection->provider)
                    ->where('provider_event_id', $data['event_id'])
                    ->exists()) {
                    return false;
                }

                $attribution = CommerceAttribution::query()
                    ->where('agent_id', $connection->agent_id)
                    ->where('token', $data['attribution_token'])
                    ->lockForUpdate()
                    ->first();
                if (! $attribution) {
                    return false;
                }
                $orderHash = hash_hmac('sha256', $data['order_reference'], (string) config('app.key'));
                $currency = strtoupper($data['currency']);
                $gross = round(max(0, (float) $data['gross_amount']), 2);
                $refunded = round(max(0, (float) ($data['refunded_amount'] ?? 0)), 2);
                $status = in_array($data['status'] ?? 'paid', ['paid', 'partially_refunded', 'refunded', 'cancelled'], true)
                    ? ($data['status'] ?? 'paid')
                    : 'paid';

                $order = CommerceAttributionOrder::query()
                    ->where('commerce_connection_id', $connection->id)
                    ->where('external_order_hash', $orderHash)
                    ->lockForUpdate()
                    ->first();
                $values = [
                    'commerce_attribution_id' => $attribution->id,
                    'agent_id' => $connection->agent_id,
                    'commerce_connection_id' => $connection->id,
                    'provider' => $connection->provider,
                    'status' => $status,
                    'gross_amount' => $gross,
                    'refunded_amount' => min($gross, $refunded),
                    'currency' => $currency,
                    'refunded_at' => $refunded > 0 ? now() : null,
                ];
                if ($order) {
                    if ($gross === 0.0 && str_contains($data['event_type'], 'refund')) {
                        $values['gross_amount'] = (float) $order->gross_amount;
                    }
                    if (str_contains($data['event_type'], 'refund')) {
                        $values['refunded_amount'] = min(
                            (float) $values['gross_amount'],
                            ($data['refunded_is_total'] ?? false)
                                ? $refunded
                                : (float) $order->refunded_amount + $refunded,
                        );
                        $values['status'] = $values['refunded_amount'] >= (float) $values['gross_amount']
                            ? 'refunded'
                            : 'partially_refunded';
                        $values['refunded_at'] = now();
                    }
                    $order->update($values);
                } else {
                    $order = CommerceAttributionOrder::query()->create($values + [
                        'external_order_hash' => $orderHash,
                        'converted_at' => $this->occurredAt($data['occurred_at'] ?? null),
                    ]);
                }

                CommerceAttributionEvent::query()->create([
                    'commerce_connection_id' => $connection->id,
                    'commerce_attribution_id' => $attribution->id,
                    'provider' => $connection->provider,
                    'provider_event_id' => $data['event_id'],
                    'event_type' => $data['event_type'],
                    'payload_hash' => $data['payload_hash'],
                    'processed_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function trackedUrl(
        int $agentId,
        string $destination,
        string $sourceType,
        int $sourceId,
        ?string $channel = null,
        ?int $conversationId = null,
        ?int $productId = null,
    ): string {
        if (! $this->publicHttpUrl($destination)) {
            return $destination;
        }

        $connection = CommerceConnection::query()
            ->where('agent_id', $agentId)
            ->where('status', 'active')
            ->whereIn('provider', ['woocommerce', 'shopify'])
            ->first();
        if (! $connection || data_get($connection->settings, 'attribution_status') !== 'active') {
            return $destination;
        }
        $destinationHash = hash('sha256', $destination);
        $attributionKey = hash('sha256', implode('|', [
            $agentId,
            $sourceType,
            $sourceId,
            $channel ?: '-',
            $productId ?: '-',
            $destinationHash,
        ]));
        $attribution = CommerceAttribution::query()->firstOrCreate([
            'attribution_key' => $attributionKey,
        ], [
            'agent_id' => $agentId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'channel' => $channel,
            'product_id' => $productId,
            'destination_url_hash' => $destinationHash,
            'token' => (string) Str::uuid(),
            'commerce_connection_id' => $connection->id,
            'conversation_id' => $conversationId,
            'destination_url' => $destination,
        ]);

        return route('attribution.visit', ['attribution' => $attribution->token]);
    }

    private function withToken(string $url, string $token): string
    {
        $fragment = '';
        if (($position = strpos($url, '#')) !== false) {
            $fragment = substr($url, $position);
            $url = substr($url, 0, $position);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'legatus_attribution' => $token,
            'utm_source' => 'legatus',
            'utm_medium' => 'ai_assistant',
            'utm_campaign' => $token,
        ], '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    private function publicHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function occurredAt(?string $value): Carbon
    {
        try {
            return filled($value) ? Carbon::parse($value) : now();
        } catch (\Throwable) {
            return now();
        }
    }
}
