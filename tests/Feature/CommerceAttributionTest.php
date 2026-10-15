<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CommerceAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_conversion_and_refund_are_idempotent_and_update_verified_value(): void
    {
        [$connection, $conversation, $attribution] = $this->context();

        $completed = ['event_id' => 'evt-complete-1', 'type' => 'order.completed', 'attribution_token' => $attribution->token, 'order_reference' => 'PRIVATE-ORDER-7', 'amount' => 80, 'currency' => 'GEL'];
        $this->postSigned($connection, $completed, 'nonce-completed-12345')->assertOk();
        $this->postSigned($connection, $completed, 'nonce-completed-replay')->assertOk();

        $this->assertSame('verified_purchase', $conversation->fresh()->outcome);
        $this->assertSame('80.00', $conversation->fresh()->outcome_value);
        $this->assertSame(1, \DB::table('commerce_attribution_events')->count());
        $this->assertStringNotContainsString('PRIVATE-ORDER-7', (string) \DB::table('commerce_attributions')->value('order_reference_hash'));

        $refund = ['event_id' => 'evt-refund-1', 'type' => 'order.refunded', 'attribution_token' => $attribution->token, 'amount' => 30, 'currency' => 'GEL'];
        $this->postSigned($connection, $refund, 'nonce-refund-1234567')->assertOk();

        $this->assertSame('50.00', $conversation->fresh()->outcome_value);
        $this->assertSame('30.00', $attribution->fresh()->refunded_amount);
    }

    public function test_invalid_signature_unknown_token_and_replayed_nonce_fail_closed(): void
    {
        [$connection, , $attribution] = $this->context();
        $event = ['event_id' => 'evt-1', 'type' => 'order.completed', 'attribution_token' => $attribution->token, 'amount' => 10, 'currency' => 'GEL'];

        $this->postJson('/webhooks/commerce', $event, [
            'X-Legatus-Key' => $connection->key_id,
            'X-Legatus-Timestamp' => (string) now()->timestamp,
            'X-Legatus-Nonce' => 'nonce-invalid-12345',
            'X-Legatus-Signature' => str_repeat('0', 64),
        ])->assertUnauthorized();

        $unknown = array_merge($event, ['attribution_token' => (string) Str::uuid()]);
        $this->postSigned($connection, $unknown, 'nonce-unknown-12345')->assertNotFound();

        $this->postSigned($connection, $event, 'nonce-replay-123456')->assertOk();
        $this->postSigned($connection, $event, 'nonce-replay-123456')->assertStatus(409);
    }

    private function context(): array
    {
        $agent = Agent::create(['name' => 'Assistant', 'slug' => 'attribution-'.uniqid(), 'business_name' => 'Store']);
        $connection = $agent->commerceConnection()->create([
            'provider' => 'universal_api', 'name' => 'Store', 'base_url' => 'https://8.8.8.8',
            'key_id' => 'key-'.uniqid(), 'secret' => str_repeat('x', 32), 'status' => 'active',
        ]);
        $conversation = $agent->conversations()->create(['visitor_id' => uniqid('visitor-', true), 'status' => 'ai']);
        $attribution = CommerceAttribution::create([
            'agent_id' => $agent->id, 'conversation_id' => $conversation->id,
            'commerce_connection_id' => $connection->id, 'status' => 'initiated',
        ]);

        return [$connection, $conversation, $attribution];
    }

    private function postSigned($connection, array $payload, string $nonce)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $canonical = implode("\n", ['POST', '/webhooks/commerce', $timestamp, $nonce, hash('sha256', $body)]);

        return $this->call('POST', '/webhooks/commerce', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LEGATUS_KEY' => $connection->key_id,
            'HTTP_X_LEGATUS_TIMESTAMP' => $timestamp,
            'HTTP_X_LEGATUS_NONCE' => $nonce,
            'HTTP_X_LEGATUS_SIGNATURE' => hash_hmac('sha256', $canonical, $connection->secret),
        ], $body);
    }
}
