<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyOrderWebhook;
use App\Models\Agent;
use App\Models\CommerceAttribution;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommerceAttributionService;
use App\Services\ShopifyClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommerceAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribution_migration_preserves_legacy_tables_and_recovers_from_a_partial_run(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('commerce_attribution_events');
        Schema::dropIfExists('commerce_attribution_orders');
        Schema::dropIfExists('commerce_attributions');
        Schema::enableForeignKeyConstraints();
        Schema::create('commerce_attributions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('commerce_connection_id')->nullable();
            $table->string('status', 30)->default('initiated');
            $table->timestamps();
        });
        Schema::create('commerce_attribution_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('commerce_attribution_id');
            $table->string('provider_event_id', 191);
            $table->string('type', 40);
            $table->timestamps();
        });
        \DB::table('commerce_attributions')->insert([
            'id' => 77,
            'token' => '9afae51c-bfc4-4878-83db-686785d3dd2a',
            'agent_id' => 1,
            'conversation_id' => 1,
            'status' => 'initiated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('commerce_attribution_events')->insert([
            'id' => 88,
            'commerce_attribution_id' => 77,
            'provider_event_id' => 'legacy-event',
            'type' => 'converted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_17_000045_create_commerce_attribution_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('commerce_attributions', ['attribution_key', 'source_type', 'destination_url']));
        $this->assertTrue(Schema::hasColumns('commerce_attribution_events', ['provider', 'event_type', 'payload_hash']));
        $this->assertTrue(Schema::hasTable('commerce_attribution_orders'));
        $this->assertDatabaseHas('commerce_attributions_legacy_20261017', ['id' => 77]);
        $this->assertDatabaseHas('commerce_attribution_events_legacy_20261017', ['id' => 88]);
    }

    public function test_chat_product_link_records_an_anonymous_click_and_preserves_utm_attribution(): void
    {
        [, $agent] = $this->tenant('tracked-chat');
        $connection = $agent->commerceConnection()->create($this->connection());
        $product = $agent->products()->create([
            'commerce_connection_id' => $connection->id,
            'external_product_id' => '101',
            'name' => 'Verified product',
            'price' => 25,
            'stock' => 2,
            'is_active' => true,
            'metadata' => ['product_url' => 'https://store.example/product/101?variant=2#details'],
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'visitor-1',
            'channel' => 'widget',
            'status' => 'ai',
        ]);

        $tracked = app(CommerceAttributionService::class)->conversationProductUrl(
            $conversation,
            $product,
            'https://store.example/product/101?variant=2#details',
        );
        $attribution = CommerceAttribution::query()->firstOrFail();
        $this->assertSame(route('attribution.visit', $attribution->token), $tracked);

        $response = $this->get($tracked)->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://store.example/product/101?variant=2&', $location);
        $this->assertStringContainsString('utm_source=legatus', $location);
        $this->assertStringContainsString('utm_campaign='.$attribution->token, $location);
        $this->assertStringEndsWith('#details', $location);
        $this->assertSame(1, $attribution->fresh()->click_count);
        $this->assertNotNull($attribution->fresh()->first_clicked_at);
    }

    public function test_signed_woocommerce_order_webhook_is_idempotent_and_appears_in_tenant_analytics(): void
    {
        [$user, $agent] = $this->tenant('woo-revenue');
        $connection = $agent->commerceConnection()->create($this->connection());
        $attribution = $agent->commerceAttributions()->create([
            'token' => '01f7c8d8-1caa-4d90-8e22-06dad66ed729',
            'attribution_key' => hash('sha256', 'woo-revenue-attribution'),
            'commerce_connection_id' => $connection->id,
            'source_type' => 'chat',
            'source_id' => 55,
            'channel' => 'widget',
            'destination_url' => 'https://store.example/product/101',
            'destination_url_hash' => hash('sha256', 'https://store.example/product/101'),
        ]);
        $payload = json_encode([
            'id' => 987,
            'status' => 'completed',
            'total' => '84.50',
            'currency' => 'GEL',
            'date_paid_gmt' => '2026-10-17T08:00:00Z',
            'meta_data' => [
                ['key' => '_wc_order_attribution_utm_source', 'value' => 'legatus'],
                ['key' => '_wc_order_attribution_utm_campaign', 'value' => $attribution->token],
            ],
        ], JSON_THROW_ON_ERROR);
        $headers = [
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $payload, $connection->webhook_secret, true)),
            'X-WC-Webhook-Topic' => 'order.updated',
            'X-WC-Webhook-Delivery-ID' => 'woo-delivery-987',
            'Content-Type' => 'application/json',
        ];
        $url = route('webhooks.commerce.woocommerce', $connection->webhook_token);

        $this->call('POST', $url, [], [], [], $this->serverHeaders($headers), $payload)->assertNoContent();
        $this->call('POST', $url, [], [], [], $this->serverHeaders($headers), $payload)->assertNoContent();

        $this->assertDatabaseCount('commerce_attribution_orders', 1);
        $this->assertDatabaseCount('commerce_attribution_events', 1);
        $this->assertDatabaseHas('commerce_attribution_orders', [
            'agent_id' => $agent->id,
            'gross_amount' => 84.50,
            'refunded_amount' => 0,
            'currency' => 'GEL',
        ]);
        $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Attributed orders')
            ->assertSee('84.50 GEL')
            ->assertSee('Chat #55')
            ->assertSee('Customer chat · Widget');
    }

    public function test_invalid_woocommerce_signature_cannot_create_revenue(): void
    {
        [, $agent] = $this->tenant('woo-invalid');
        $connection = $agent->commerceConnection()->create($this->connection());

        $this->withHeaders([
            'X-WC-Webhook-Signature' => 'invalid',
            'X-WC-Webhook-Topic' => 'order.updated',
        ])->postJson(route('webhooks.commerce.woocommerce', $connection->webhook_token), ['id' => 1])
            ->assertUnauthorized();
        $this->assertDatabaseCount('commerce_attribution_orders', 0);
    }

    public function test_signed_shopify_webhook_queues_only_order_facts_and_records_customer_journey_attribution(): void
    {
        config()->set('services.shopify.client_secret', 'shopify-secret');
        [, $agent] = $this->tenant('shopify-revenue');
        $connection = $agent->commerceConnection()->create([
            'provider' => 'shopify',
            'name' => 'Shopify',
            'base_url' => 'https://merchant.myshopify.com',
            'key_id' => 'merchant.myshopify.com',
            'secret' => json_encode(['access_token' => 'shpat_access', 'refresh_token' => 'shprt_refresh'], JSON_THROW_ON_ERROR),
            'webhook_token' => '7ec9a41d-5f34-4b87-9902-51e77cd64289',
            'webhook_secret' => 'shopify-secret',
            'status' => 'active',
            'settings' => [
                'currency' => 'GEL',
                'attribution_status' => 'active',
                'token_expires_at' => now()->addHour()->toIso8601String(),
            ],
        ]);
        $attribution = $agent->commerceAttributions()->create([
            'token' => '19855a98-fc8a-46b1-97d8-d4eb45b31768',
            'attribution_key' => hash('sha256', 'shopify-revenue-attribution'),
            'commerce_connection_id' => $connection->id,
            'source_type' => 'reel',
            'source_id' => 8,
            'channel' => 'instagram',
            'destination_url' => 'https://merchant.example/products/101',
            'destination_url_hash' => hash('sha256', 'https://merchant.example/products/101'),
        ]);
        $payload = json_encode([
            'id' => 765,
            'email' => 'must-not-enter-the-queue@example.com',
            'total_price' => '120.00',
            'currency' => 'GEL',
            'financial_status' => 'paid',
            'processed_at' => '2026-10-17T09:00:00Z',
        ], JSON_THROW_ON_ERROR);
        Queue::fake();

        $this->call('POST', route('webhooks.commerce.shopify', $connection->webhook_token), [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $payload, 'shopify-secret', true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'merchant.myshopify.com',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'shopify-event-765',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertNoContent();

        $queued = null;
        Queue::assertPushed(ProcessShopifyOrderWebhook::class, function (ProcessShopifyOrderWebhook $job) use (&$queued): bool {
            $queued = $job;

            return ! array_key_exists('email', $job->event) && $job->event['order_id'] === '765';
        });
        Http::fake(fn (Request $request) => Http::response(['data' => ['order' => [
            'customerJourneySummary' => [
                'ready' => true,
                'lastVisit' => ['utmParameters' => ['source' => 'legatus', 'medium' => 'ai_assistant', 'campaign' => $attribution->token], 'landingPage' => '/products/101'],
                'firstVisit' => null,
            ],
        ]]]));
        $queued->handle(app(ShopifyClient::class), app(CommerceAttributionService::class));

        $this->assertDatabaseHas('commerce_attribution_orders', [
            'commerce_attribution_id' => $attribution->id,
            'gross_amount' => 120,
            'currency' => 'GEL',
        ]);
    }

    public function test_shopify_refund_uses_verified_order_totals_instead_of_incomplete_webhook_amounts(): void
    {
        config()->set('services.shopify.client_secret', 'shopify-secret');
        [, $agent] = $this->tenant('shopify-refund');
        $connection = $agent->commerceConnection()->create([
            'provider' => 'shopify',
            'name' => 'Shopify',
            'base_url' => 'https://merchant.myshopify.com',
            'key_id' => 'merchant.myshopify.com',
            'secret' => json_encode(['access_token' => 'shpat_access', 'refresh_token' => 'shprt_refresh'], JSON_THROW_ON_ERROR),
            'webhook_token' => '82f4ac8d-69df-4c2e-8d37-d6b195582b3c',
            'webhook_secret' => 'shopify-secret',
            'status' => 'active',
            'settings' => ['currency' => 'GEL', 'attribution_status' => 'active', 'token_expires_at' => now()->addHour()->toIso8601String()],
        ]);
        $attribution = $agent->commerceAttributions()->create([
            'token' => '5a5909be-d8e9-4ae1-9db1-165705a1305f',
            'attribution_key' => hash('sha256', 'shopify-refund-attribution'),
            'commerce_connection_id' => $connection->id,
            'source_type' => 'social_post',
            'source_id' => 22,
            'channel' => 'facebook',
            'destination_url' => 'https://merchant.example/products/22',
            'destination_url_hash' => hash('sha256', 'https://merchant.example/products/22'),
        ]);
        Http::fake(fn () => Http::response(['data' => ['order' => [
            'totalPriceSet' => ['shopMoney' => ['amount' => '120.00', 'currencyCode' => 'GEL']],
            'totalRefundedSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'GEL']],
            'cancelledAt' => null,
            'customerJourneySummary' => [
                'ready' => true,
                'lastVisit' => ['utmParameters' => ['source' => 'legatus', 'medium' => 'ai_assistant', 'campaign' => $attribution->token], 'landingPage' => '/products/22'],
                'firstVisit' => null,
            ],
        ]]]));
        $job = new ProcessShopifyOrderWebhook($connection->id, [
            'webhook_id' => 'refund-event-22',
            'topic' => 'refunds/create',
            'order_id' => '22',
            'gross_amount' => '0',
            'refund_amount' => '0',
            'currency' => 'USD',
            'financial_status' => '',
            'cancelled_at' => null,
            'occurred_at' => '2026-10-17T10:00:00Z',
            'payload_hash' => hash('sha256', 'refund-event-22'),
        ]);

        $job->handle(app(ShopifyClient::class), app(CommerceAttributionService::class));

        $this->assertDatabaseHas('commerce_attribution_orders', [
            'commerce_attribution_id' => $attribution->id,
            'status' => 'partially_refunded',
            'gross_amount' => 120,
            'refunded_amount' => 20,
            'currency' => 'GEL',
        ]);
    }

    /** @return array{User, Agent} */
    private function tenant(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => $slug, 'slug' => $slug, 'settings' => ['currency' => 'GEL']]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Assistant',
            'slug' => $slug.'-agent',
            'business_name' => $slug,
            'channels' => ['web'],
            'settings' => [],
        ]);

        return [$user, $agent];
    }

    private function connection(): array
    {
        return [
            'provider' => 'woocommerce',
            'name' => 'WooCommerce',
            'base_url' => 'https://store.example',
            'key_id' => 'ck_'.str_repeat('k', 32),
            'secret' => 'cs_'.str_repeat('s', 32),
            'webhook_token' => '8ac7897a-817f-433f-af84-56f6e7d74b25',
            'webhook_secret' => str_repeat('w', 64),
            'status' => 'active',
            'settings' => ['currency' => 'GEL', 'attribution_status' => 'active'],
        ];
    }

    private function serverHeaders(array $headers): array
    {
        return collect($headers)->mapWithKeys(fn ($value, $key): array => [
            'HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value,
        ])->all();
    }
}
