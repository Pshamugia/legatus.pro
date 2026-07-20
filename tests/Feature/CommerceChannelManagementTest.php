<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CommerceChannelManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_verify_and_connect_a_store_without_exposing_the_secret(): void
    {
        [$user, $agent] = $this->tenant('owner-store', 'owner');
        $secret = str_repeat('private-web-secret-', 2);
        Http::fake(function (Request $request) use ($secret) {
            $parts = parse_url($request->url());
            $requestUri = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            $canonical = implode("\n", [
                $request->method(),
                $requestUri,
                $request->header('X-Legatus-Timestamp')[0],
                $request->header('X-Legatus-Nonce')[0],
                hash('sha256', $request->body()),
            ]);

            $this->assertSame('owner-key', $request->header('X-Legatus-Key')[0]);
            $this->assertSame(hash_hmac('sha256', $canonical, $secret), $request->header('X-Legatus-Signature')[0]);

            return Http::response($this->catalog('verified-book', 'Verified Book'));
        });

        $response = $this->actingAs($user)->post(route('channels.commerce.connect'), [
            'name' => 'Owner live catalog',
            'base_url' => 'https://8.8.8.8',
            'key_id' => 'owner-key',
            'secret' => $secret,
        ]);

        $response->assertRedirect(route('channels.index'))
            ->assertSessionHas('commerce_success', 'Store connected and 1 products verified.')
            ->assertSessionMissing('_old_input');
        $connection = $agent->commerceConnection()->firstOrFail();
        $this->assertSame('active', $connection->status);
        $this->assertSame($secret, $connection->secret);
        $this->assertNotSame($secret, DB::table('commerce_connections')->whereKey($connection->id)->value('secret'));
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'commerce_connection_id' => $connection->id,
            'external_product_id' => 'verified-book',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('channels.index'))
            ->assertOk()
            ->assertSee('data-commerce-status="active"', false)
            ->assertSee('Owner live catalog')
            ->assertSee('8.8.8.8')
            ->assertDontSee($secret)
            ->assertDontSee('value="'.$secret.'"', false);
    }

    public function test_invalid_form_never_flashes_the_shared_secret(): void
    {
        [$user, $agent] = $this->tenant('invalid-store', 'owner');
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->post(route('channels.commerce.connect'), [
            'name' => 'Invalid',
            'base_url' => 'http://127.0.0.1/internal',
            'key_id' => 'key',
            'secret' => 'too-short-secret',
        ]);

        $response->assertRedirect(route('channels.index'))
            ->assertSessionHasErrors('secret', null, 'commerce')
            ->assertSessionMissing('_old_input');
        $this->assertNull($agent->commerceConnection()->first());
        $this->assertStringNotContainsString('too-short-secret', serialize(session()->all()));
    }

    public function test_failed_replacement_preserves_the_verified_connection_and_logs_no_credentials(): void
    {
        [$user, $agent] = $this->tenant('replacement-store', 'admin');
        $oldSecret = str_repeat('o', 32);
        $newSecret = str_repeat('new-private-secret-', 2);
        $connection = $agent->commerceConnection()->create([
            'provider' => 'universal_api',
            'name' => 'Working store',
            'base_url' => 'https://1.1.1.1',
            'key_id' => 'old-key',
            'secret' => $oldSecret,
            'status' => 'active',
            'last_sync_at' => now()->subHour(),
        ]);
        Http::fake(['*' => Http::response(['message' => "credentials={$newSecret}"], 401)]);
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($newSecret): bool {
            $encoded = json_encode([$message, $context]);

            return ! str_contains($encoded, $newSecret)
                && ! array_key_exists('exception', $context)
                && $context['action'] === 'connect';
        });

        $response = $this->actingAs($user)->post(route('channels.commerce.connect'), [
            'name' => 'Broken replacement',
            'base_url' => 'https://8.8.8.8',
            'key_id' => 'new-key',
            'secret' => $newSecret,
        ]);

        $response->assertRedirect(route('channels.index'))
            ->assertSessionHas('commerce_error', 'The store rejected these credentials. Check the key ID and shared secret.')
            ->assertSessionMissing('_old_input');
        $connection->refresh();
        $this->assertSame('https://1.1.1.1', $connection->base_url);
        $this->assertSame('old-key', $connection->key_id);
        $this->assertSame($oldSecret, $connection->secret);
        $this->assertStringNotContainsString($newSecret, serialize(session()->all()));
    }

    public function test_manual_sync_uses_only_the_authenticated_tenant_connection(): void
    {
        [$user, $agent] = $this->tenant('sync-store-a', 'owner');
        [, $otherAgent] = $this->tenant('sync-store-b', 'owner');
        $connection = $agent->commerceConnection()->create($this->connectionAttributes('https://8.8.8.8', 'a-key'));
        $otherConnection = $otherAgent->commerceConnection()->create($this->connectionAttributes('https://1.1.1.1', 'b-key'));
        Http::fake(function (Request $request) {
            $this->assertStringStartsWith('https://8.8.8.8/', $request->url());

            return Http::response($this->catalog('a-product', 'Tenant A Product'));
        });

        $this->actingAs($user)->post(route('channels.commerce.sync'))
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('commerce_success');

        $this->assertNotNull($connection->fresh()->last_sync_at);
        $this->assertNull($otherConnection->fresh()->last_sync_at);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'external_product_id' => 'a-product']);
        $this->assertDatabaseMissing('products', ['agent_id' => $otherAgent->id, 'external_product_id' => 'a-product']);
    }

    public function test_disconnect_deactivates_only_the_current_tenant_imported_catalog(): void
    {
        [$user, $agent] = $this->tenant('disconnect-store-a', 'owner');
        [, $otherAgent] = $this->tenant('disconnect-store-b', 'owner');
        $connection = $agent->commerceConnection()->create($this->connectionAttributes('https://8.8.8.8', 'a-key'));
        $otherConnection = $otherAgent->commerceConnection()->create($this->connectionAttributes('https://1.1.1.1', 'b-key'));
        $product = $agent->products()->create([
            'commerce_connection_id' => $connection->id,
            'external_product_id' => 'a-product',
            'name' => 'A product',
            'price' => 10,
            'stock' => 1,
            'is_active' => true,
        ]);
        $otherProduct = $otherAgent->products()->create([
            'commerce_connection_id' => $otherConnection->id,
            'external_product_id' => 'b-product',
            'name' => 'B product',
            'price' => 20,
            'stock' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->delete(route('channels.commerce.disconnect'))
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('commerce_success', 'Store disconnected. 1 imported products were taken offline.');

        $this->assertDatabaseMissing('commerce_connections', ['id' => $connection->id]);
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('commerce_connections', ['id' => $otherConnection->id]);
        $this->assertTrue($otherProduct->fresh()->is_active);
    }

    public function test_viewers_cannot_change_commerce_connections_and_guests_must_sign_in(): void
    {
        [$viewer, $agent] = $this->tenant('viewer-store', 'viewer');
        $connection = $agent->commerceConnection()->create($this->connectionAttributes('https://8.8.8.8', 'viewer-key'));

        $this->post(route('channels.commerce.connect'))->assertRedirect(route('login'));
        $this->post(route('channels.commerce.sync'))->assertRedirect(route('login'));
        $this->delete(route('channels.commerce.disconnect'))->assertRedirect(route('login'));

        $this->actingAs($viewer)->post(route('channels.commerce.connect'), [
            'base_url' => 'https://1.1.1.1',
            'key_id' => 'new-key',
            'secret' => str_repeat('n', 32),
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('channels.commerce.sync'))->assertForbidden();
        $this->actingAs($viewer)->delete(route('channels.commerce.disconnect'))->assertForbidden();
        $this->assertDatabaseHas('commerce_connections', ['id' => $connection->id, 'key_id' => 'viewer-key']);

        $this->actingAs($viewer)->get(route('channels.index'))
            ->assertOk()
            ->assertSee('owner-ს ან admin-ს შეუძლია')
            ->assertDontSee('name="secret"', false)
            ->assertDontSee(route('channels.commerce.disconnect'), false);
    }

    /** @return array{User, Agent} */
    private function tenant(string $slug, string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => $slug, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => $role]);
        $agent = $organization->agents()->create([
            'name' => 'Assistant',
            'slug' => $slug.'-agent',
            'business_name' => $slug,
            'channels' => ['web'],
            'settings' => [],
        ]);

        return [$user, $agent];
    }

    private function connectionAttributes(string $baseUrl, string $keyId): array
    {
        return [
            'provider' => 'universal_api',
            'name' => 'Live catalog',
            'base_url' => $baseUrl,
            'key_id' => $keyId,
            'secret' => str_repeat('s', 32),
            'status' => 'active',
        ];
    }

    private function catalog(string $id, string $name): array
    {
        return [
            'data' => [[
                'id' => $id,
                'name' => $name,
                'price' => 25,
                'currency' => 'GEL',
                'quantity' => 3,
                'in_stock' => true,
                'purchasable' => true,
            ]],
            'meta' => [
                'sync_mode' => 'authoritative_snapshot',
                'current_page' => 1,
                'last_page' => 1,
                'total' => 1,
            ],
        ];
    }
}
