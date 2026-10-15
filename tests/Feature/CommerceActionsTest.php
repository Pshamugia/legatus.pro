<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CommerceActionRequest;
use App\Services\SalesToolbox;
use App\Support\PrivacyRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommerceActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_connectors_keep_existing_tools_without_exposing_actions(): void
    {
        [$agent] = $this->context([]);

        $names = collect(app(SalesToolbox::class)->definitions($agent))->pluck('name');

        $this->assertTrue($names->contains('search_products'));
        $this->assertFalse($names->contains('lookup_order_status'));
        $this->assertFalse($names->contains('create_checkout'));
        $this->assertFalse($names->contains('prepare_commerce_action'));
        $this->assertFalse($names->contains('confirm_commerce_action'));
    }

    public function test_platform_support_agent_never_receives_store_action_tools(): void
    {
        [$agent] = $this->context(['order_status', 'cart_create', 'order_cancel']);
        $agent->update(['settings' => ['assistant_mode' => 'platform_support']]);

        $names = collect(app(SalesToolbox::class)->definitions($agent->fresh()))->pluck('name');

        $this->assertFalse($names->contains('lookup_order_status'));
        $this->assertFalse($names->contains('create_checkout'));
        $this->assertFalse($names->contains('prepare_commerce_action'));
    }

    public function test_order_status_requires_matching_contact_from_the_latest_customer_message(): void
    {
        [$agent, , $conversation] = $this->context(['order_status']);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'Where is order ORD-7?']);
        Http::fake(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders/status')
            ? Http::response(['data' => ['status' => 'shipped', 'carrier' => 'Verified Carrier', 'tracking_url' => 'https://8.8.8.8/track/7']])
            : Http::response([], 404));

        $blocked = app(SalesToolbox::class)->execute('lookup_order_status', [
            'order_reference' => 'ORD-7',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => null,
        ], $agent, $conversation);

        $this->assertFalse($blocked['ok']);
        Http::assertNothingSent();

        $raw = 'Order ORD-7, my email is buyer@example.com';
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => PrivacyRedactor::text($raw),
            'metadata' => ['contact_evidence' => PrivacyRedactor::contactEvidence($raw)],
        ]);
        $result = app(SalesToolbox::class)->execute('lookup_order_status', [
            'order_reference' => 'ORD-7',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => null,
        ], $agent, $conversation);

        $this->assertTrue($result['ok'], json_encode($result, JSON_UNESCAPED_SLASHES));
        $this->assertSame('shipped', $result['status']);
        $this->assertStringNotContainsString('buyer@example.com', json_encode($result));
    }

    public function test_checkout_uses_live_availability_and_returns_only_a_store_checkout_url(): void
    {
        [$agent, $connection, $conversation] = $this->context(['cart_create']);
        $product = $agent->products()->create([
            'commerce_connection_id' => $connection->id,
            'external_product_id' => 'remote-12',
            'name' => 'Verified Product',
            'price' => 12,
            'stock' => 3,
            'is_active' => true,
        ]);
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/legatus/v1/products/remote-12/availability' => Http::response(['data' => ['purchasable' => true, 'in_stock' => true, 'quantity' => 3]]),
                '/api/legatus/v1/carts' => Http::response(['data' => ['checkout_url' => 'https://8.8.8.8/checkout/cart-1']]),
                default => Http::response([], 404),
            };
        });

        $result = app(SalesToolbox::class)->execute('create_checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], $agent, $conversation);

        $this->assertTrue($result['ok']);
        $this->assertSame('https://8.8.8.8/checkout/cart-1', $result['checkout_url']);
        $this->assertTrue($result['requires_payment_confirmation']);
        $this->assertSame($product->id, $result['items'][0]['product_id']);
        $this->assertSame('checkout_created', $conversation->fresh()->outcome);
    }

    public function test_consequential_action_requires_a_separate_confirmation_and_executes_once(): void
    {
        [$agent, , $conversation] = $this->context(['shipping_address_update']);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'Please change the delivery address.']);
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/legatus/v1/actions/preview' => Http::response(['data' => [
                    'action_token' => 'secret-action-token',
                    'summary' => 'Change delivery address for order ORD-9.',
                    'expires_at' => now()->addMinutes(10)->toIso8601String(),
                ]]),
                '/api/legatus/v1/actions/execute' => Http::response(['data' => ['completed' => true, 'reference' => 'change-9']]),
                default => Http::response([], 404),
            };
        });

        $prepared = app(SalesToolbox::class)->execute('prepare_commerce_action', [
            'action' => 'update_shipping_address',
            'order_reference' => 'ORD-9',
            'details' => [
                'reason' => null, 'item_ids' => [], 'recipient' => 'Customer Name',
                'line1' => 'Private Street 1', 'line2' => null, 'city' => 'Tbilisi',
                'region' => null, 'postal_code' => '0100', 'country' => 'GE',
            ],
        ], $agent, $conversation);

        $this->assertTrue($prepared['ok']);
        $sameTurn = app(SalesToolbox::class)->execute('confirm_commerce_action', [
            'action_request_id' => $prepared['action_request_id'],
            'customer_confirmation' => 'Yes, confirm it.',
        ], $agent, $conversation);
        $this->assertFalse($sameTurn['ok']);

        $conversation->messages()->create(['role' => 'customer', 'content' => 'Yes, confirm it.']);
        $confirmed = app(SalesToolbox::class)->execute('confirm_commerce_action', [
            'action_request_id' => $prepared['action_request_id'],
            'customer_confirmation' => 'Yes, confirm it.',
        ], $agent, $conversation);
        $duplicate = app(SalesToolbox::class)->execute('confirm_commerce_action', [
            'action_request_id' => $prepared['action_request_id'],
            'customer_confirmation' => 'Yes, confirm it.',
        ], $agent, $conversation);

        $this->assertTrue($confirmed['ok']);
        $this->assertFalse($duplicate['ok']);
        $this->assertSame('completed', CommerceActionRequest::firstOrFail()->status);
        Http::assertSentCount(2);
        $stored = DB::table('commerce_action_requests')->value('payload');
        $this->assertStringNotContainsString('Private Street', $stored);
        $this->assertStringNotContainsString('secret-action-token', $stored);
    }

    public function test_unknown_execution_result_is_terminal_and_is_not_retried(): void
    {
        [$agent, , $conversation] = $this->context(['order_cancel']);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'Cancel order ORD-10 because it was accidental.']);
        Http::fakeSequence()
            ->push(['data' => ['action_token' => 'cancel-token', 'summary' => 'Cancel order ORD-10.', 'expires_at' => now()->addMinutes(10)->toIso8601String()]])
            ->push([], 500);

        $prepared = app(SalesToolbox::class)->execute('prepare_commerce_action', [
            'action' => 'cancel_order', 'order_reference' => 'ORD-10',
            'details' => ['reason' => 'Accidental order', 'item_ids' => [], 'recipient' => null, 'line1' => null, 'line2' => null, 'city' => null, 'region' => null, 'postal_code' => null, 'country' => null],
        ], $agent, $conversation);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'Confirm cancellation.']);

        $first = app(SalesToolbox::class)->execute('confirm_commerce_action', [
            'action_request_id' => $prepared['action_request_id'], 'customer_confirmation' => 'Confirm cancellation.',
        ], $agent, $conversation);
        $second = app(SalesToolbox::class)->execute('confirm_commerce_action', [
            'action_request_id' => $prepared['action_request_id'], 'customer_confirmation' => 'Confirm cancellation.',
        ], $agent, $conversation);

        $this->assertFalse($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('delivery_unknown', CommerceActionRequest::firstOrFail()->status);
        Http::assertSentCount(2);
    }

    private function context(array $capabilities): array
    {
        $agent = Agent::create(['name' => 'Assistant', 'slug' => 'commerce-'.uniqid(), 'business_name' => 'Connected Store']);
        $connection = $agent->commerceConnection()->create([
            'provider' => 'universal_api',
            'name' => 'Live store',
            'base_url' => 'https://8.8.8.8',
            'key_id' => 'test-key',
            'secret' => str_repeat('s', 32),
            'status' => 'active',
            'settings' => ['capabilities' => $capabilities],
        ]);
        $conversation = $agent->conversations()->create(['visitor_id' => uniqid('visitor-', true), 'status' => 'ai']);

        return [$agent, $connection, $conversation];
    }
}
