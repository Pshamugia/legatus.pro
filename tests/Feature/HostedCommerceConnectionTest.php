<?php

namespace Tests\Feature;

use App\Models\CommerceInstallation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostedCommerceConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_woocommerce_connects_with_its_own_approval_screen_without_business_api_keys(): void
    {
        [$user, $agent] = $this->tenant('woo-install');

        $start = $this->actingAs($user)->post(route('channels.commerce.woocommerce.connect'), [
            'store_url' => 'https://8.8.8.8',
        ])->assertRedirect();
        $location = $start->headers->get('Location');
        $this->assertStringStartsWith('https://8.8.8.8/wc-auth/v1/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('read_write', $query['scope']);
        $this->assertSame(route('webhooks.commerce.woocommerce.authorize'), $query['callback_url']);

        $this->postJson(route('webhooks.commerce.woocommerce.authorize'), [
            'user_id' => $query['user_id'],
            'consumer_key' => 'ck_'.str_repeat('k', 40),
            'consumer_secret' => 'cs_'.str_repeat('s', 40),
            'key_permissions' => 'read_write',
        ])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('authorized', CommerceInstallation::query()->firstOrFail()->status);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/settings/general/woocommerce_currency')) {
                return Http::response(['value' => 'GEL']);
            }
            if (str_contains($request->url(), '/products')) {
                return Http::response([[
                    'id' => 101,
                    'name' => 'Woo product',
                    'sku' => 'W-101',
                    'price' => '31.50',
                    'stock_quantity' => 4,
                    'stock_status' => 'instock',
                    'purchasable' => true,
                    'permalink' => 'https://store.example/product/101',
                    'images' => [['src' => 'https://store.example/product.jpg']],
                    'categories' => [['name' => 'Category']],
                    'description' => '<p>Verified description</p>',
                ]], 200, ['X-WP-TotalPages' => '1', 'X-WP-Total' => '1']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/webhooks')) {
                return Http::response([]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/webhooks')) {
                return Http::response(['id' => random_int(1, 9999)], 201);
            }

            return Http::response([], 404);
        });

        $this->actingAs($user)->get(route('channels.commerce.woocommerce.complete', [
            'success' => '1',
            'user_id' => $query['user_id'],
        ]))->assertRedirect(route('channels.index'))->assertSessionHas('commerce_success');

        $connection = $agent->commerceConnection()->firstOrFail();
        $this->assertSame('woocommerce', $connection->provider);
        $this->assertSame('active', data_get($connection->settings, 'attribution_status'));
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'external_product_id' => '101',
            'stock' => 4,
        ]);
        $this->assertNull(CommerceInstallation::query()->firstOrFail()->credentials);
        Http::assertSentCount(6);
    }

    public function test_shopify_oauth_connects_catalog_and_webhooks_without_merchant_secrets(): void
    {
        config()->set('services.shopify.client_id', 'shopify-client');
        config()->set('services.shopify.client_secret', 'shopify-secret');
        config()->set('services.shopify.scopes', 'read_products,read_inventory,read_orders');
        [$user, $agent] = $this->tenant('shopify-install');
        $agent->organization()->update(['settings' => ['currency' => 'USD']]);

        $start = $this->actingAs($user)->post(route('channels.commerce.shopify.connect'), [
            'shop' => 'merchant.myshopify.com',
        ])->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $authorization);

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                return Http::response([
                    'access_token' => 'shpat_access',
                    'refresh_token' => 'shprt_refresh',
                    'expires_in' => 3600,
                    'refresh_token_expires_in' => 7776000,
                    'scope' => 'read_products,read_inventory,read_orders',
                ]);
            }
            $query = (string) data_get($request->data(), 'query', '');
            if (str_contains($query, 'query LegatusProducts')) {
                return Http::response(['data' => [
                    'products' => [
                        'nodes' => [[
                            'id' => 'gid://shopify/Product/101',
                            'title' => 'Shopify product',
                            'descriptionHtml' => '<p>Verified</p>',
                            'onlineStoreUrl' => 'https://merchant.example/products/101',
                            'status' => 'ACTIVE',
                            'productType' => 'Category',
                            'vendor' => 'Brand',
                            'tags' => [],
                            'updatedAt' => '2026-10-17T08:00:00Z',
                            'featuredMedia' => ['preview' => ['image' => ['url' => 'https://merchant.example/p.jpg']]],
                            'priceRangeV2' => ['minVariantPrice' => ['amount' => '45.00', 'currencyCode' => 'GEL']],
                            'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'S-1', 'availableForSale' => true, 'inventoryQuantity' => 3, 'price' => '45.00']]],
                        ]],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'cursor'],
                    ],
                    'productsCount' => ['count' => 1],
                ]]);
            }
            if (str_contains($query, 'mutation LegatusWebhook')) {
                return Http::response(['data' => ['webhookSubscriptionCreate' => [
                    'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'],
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected test request']]], 400);
        });

        $params = [
            'code' => 'authorization-code',
            'shop' => 'merchant.myshopify.com',
            'state' => $authorization['state'],
            'timestamp' => '1792224000',
        ];
        ksort($params);
        $params['hmac'] = hash_hmac('sha256', http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'shopify-secret');

        $this->actingAs($user)->get(route('channels.commerce.shopify.callback', $params))
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('commerce_success');

        $connection = $agent->commerceConnection()->firstOrFail();
        $this->assertSame('shopify', $connection->provider);
        $this->assertSame('active', data_get($connection->settings, 'attribution_status'));
        $this->assertSame('GEL', data_get($connection->settings, 'currency'));
        $this->assertStringNotContainsString('shpat_access', (string) \DB::table('commerce_connections')->whereKey($connection->id)->value('secret'));
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'external_product_id' => 'gid://shopify/Product/101',
            'stock' => 3,
        ]);
        Http::assertSentCount(6);
    }

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
}
