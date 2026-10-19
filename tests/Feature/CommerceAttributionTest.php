<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyOrderWebhook;
use App\Models\CommerceAttribution;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConversationEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommerceAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_links_redirect_without_collecting_clicks_or_adding_tracking_parameters(): void
    {
        [, $agent] = $this->tenant();
        $url = 'https://store.example/product/101?variant=2#details';
        $link = $agent->commerceAttributions()->create([
            'token' => '01f7c8d8-1caa-4d90-8e22-06dad66ed729',
            'attribution_key' => hash('sha256', $url),
            'source_type' => 'chat',
            'source_id' => 1,
            'destination_url' => $url,
            'destination_url_hash' => hash('sha256', $url),
            'click_count' => 2,
        ]);

        $this->get(route('attribution.visit', $link->token))->assertRedirect($url);
        $this->assertSame(2, $link->fresh()->click_count);
    }

    public function test_chat_product_cards_keep_direct_store_urls_even_with_a_connected_store(): void
    {
        [, $agent] = $this->tenant();
        $connection = $agent->commerceConnection()->create($this->connection('woocommerce'));
        $url = 'https://store.example/product/101';
        $product = $agent->products()->create([
            'commerce_connection_id' => $connection->id,
            'external_product_id' => '101',
            'name' => 'Verified product',
            'price' => 25,
            'stock' => 2,
            'is_active' => true,
            'metadata' => ['product_url' => $url],
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'visitor-1', 'channel' => 'widget', 'status' => 'ai',
        ]);

        $cards = (new \ReflectionMethod(app(ConversationEngine::class), 'publicProducts'))
            ->invoke(app(ConversationEngine::class), [[
                'id' => $product->id, 'name' => $product->name,
                'price' => $product->price, 'stock' => $product->stock, 'url' => $url,
            ]], $conversation);

        $this->assertSame($url, $cards[0]['url']);
        $this->assertSame(0, CommerceAttribution::query()->count());
    }

    public function test_analytics_and_onboarding_do_not_present_sales_attribution_even_with_historical_data(): void
    {
        [$user, $agent] = $this->tenant();
        $connection = $agent->commerceConnection()->create($this->connection('woocommerce'));
        $link = $agent->commerceAttributions()->create([
            'token' => '01f7c8d8-1caa-4d90-8e22-06dad66ed729',
            'attribution_key' => hash('sha256', 'historical-order'),
            'commerce_connection_id' => $connection->id,
            'source_type' => 'chat',
            'source_id' => 1,
            'destination_url' => 'https://store.example/product/101',
            'destination_url_hash' => hash('sha256', 'https://store.example/product/101'),
            'click_count' => 3,
        ]);
        $agent->commerceAttributionOrders()->create([
            'commerce_attribution_id' => $link->id,
            'commerce_connection_id' => $connection->id,
            'provider' => 'woocommerce',
            'external_order_hash' => hash('sha256', 'historical-order'),
            'status' => 'paid',
            'gross_amount' => 84.5,
            'refunded_amount' => 0,
            'currency' => 'GEL',
            'converted_at' => now(),
        ]);

        $analytics = $this->actingAs($user)->get(route('analytics.index'))->assertOk()
            ->assertDontSee('Sales attribution')->assertDontSee('Attributed orders')
            ->assertDontSee('Attributed revenue')->assertDontSee('tracked clicks');
        $this->assertArrayNotHasKey('attribution_clicks', $analytics->viewData('metrics'));
        $this->get('/onboarding')->assertOk()
            ->assertDontSee('Orders & attribution')
            ->assertDontSee('order attribution');
    }

    public function test_old_signed_order_webhooks_are_acknowledged_without_recording_orders(): void
    {
        [, $agent] = $this->tenant();
        $connection = $agent->commerceConnection()->create($this->connection('woocommerce'));
        $body = json_encode(['id' => 987, 'status' => 'completed'], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, $connection->webhook_secret, true));

        $this->call('POST', route('webhooks.commerce.woocommerce', $connection->webhook_token), [], [], [], [
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature,
        ], $body)->assertNoContent();
        $this->assertDatabaseCount('commerce_attribution_orders', 0);

        $this->withHeader('X-WC-Webhook-Signature', 'invalid')
            ->postJson(route('webhooks.commerce.woocommerce', $connection->webhook_token), ['id' => 987])
            ->assertUnauthorized();
    }

    public function test_old_shopify_order_jobs_do_not_query_or_record_orders(): void
    {
        Queue::fake();
        (new ProcessShopifyOrderWebhook(1, ['order_id' => '123']))->handle();

        $this->assertDatabaseCount('commerce_attribution_orders', 0);
    }

    public function test_shopify_ignores_old_order_webhooks_but_still_handles_app_uninstall(): void
    {
        config()->set('services.shopify.client_secret', 'shopify-secret');
        [, $agent] = $this->tenant();
        $connection = $agent->commerceConnection()->create($this->connection('shopify'));
        $connection->update(['base_url' => 'https://merchant.myshopify.com']);
        Queue::fake();
        $url = route('webhooks.commerce.shopify', $connection->webhook_token);
        $body = json_encode(['id' => 123, 'financial_status' => 'paid'], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $body, 'shopify-secret', true));

        $this->call('POST', $url, [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'merchant.myshopify.com',
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        ], $body)->assertNoContent();
        Queue::assertNotPushed(ProcessShopifyOrderWebhook::class);
        $this->assertDatabaseCount('commerce_attribution_orders', 0);

        $uninstallBody = '{}';
        $uninstallSignature = base64_encode(hash_hmac('sha256', $uninstallBody, 'shopify-secret', true));
        $this->call('POST', $url, [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $uninstallSignature,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'merchant.myshopify.com',
            'HTTP_X_SHOPIFY_TOPIC' => 'app/uninstalled',
        ], $uninstallBody)->assertNoContent();
        $this->assertSame('disconnected', $connection->fresh()->status);
    }

    public function test_attribution_migration_preserves_legacy_tables_after_a_partial_run(): void
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
            'id' => 77, 'token' => '9afae51c-bfc4-4878-83db-686785d3dd2a',
            'agent_id' => 1, 'conversation_id' => 1, 'status' => 'initiated',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('commerce_attribution_events')->insert([
            'id' => 88, 'commerce_attribution_id' => 77, 'provider_event_id' => 'legacy-event',
            'type' => 'converted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_17_000045_create_commerce_attribution_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('commerce_attributions', ['attribution_key', 'source_type', 'destination_url']));
        $this->assertTrue(Schema::hasTable('commerce_attribution_orders'));
        $this->assertDatabaseHas('commerce_attributions_legacy_20261017', ['id' => 77]);
        $this->assertDatabaseHas('commerce_attribution_events_legacy_20261017', ['id' => 88]);
    }

    private function tenant(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Retired attribution', 'slug' => 'retired-attribution', 'settings' => ['currency' => 'GEL']]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Assistant', 'slug' => 'retired-attribution-agent',
            'business_name' => 'Retired attribution', 'channels' => ['web'], 'settings' => [],
        ]);

        return [$user, $agent];
    }

    private function connection(string $provider): array
    {
        return [
            'provider' => $provider, 'name' => $provider, 'base_url' => 'https://store.example',
            'key_id' => 'test-key', 'secret' => 'test-secret',
            'webhook_token' => '8ac7897a-817f-433f-af84-56f6e7d74b25',
            'webhook_secret' => str_repeat('w', 64), 'status' => 'active',
            'settings' => ['currency' => 'GEL', 'attribution_status' => 'active'],
        ];
    }
}
