<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingCommerceAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_live_connector_is_shown_as_authoritative_and_saved_catalog_url_is_not_reingested(): void
    {
        [$owner, $agent] = $this->tenant('authoritative-catalog', [
            'catalog_url' => 'https://example.com/human-catalog',
        ]);
        $connection = $agent->commerceConnection()->create([
            'provider' => 'universal_api',
            'name' => 'Verified live catalog',
            'base_url' => 'https://8.8.8.8',
            'key_id' => 'verified-live-catalog',
            'secret' => str_repeat('s', 32),
            'status' => 'active',
            'last_sync_at' => now(),
        ]);
        $agent->products()->create([
            'commerce_connection_id' => $connection->id,
            'external_product_id' => 'live-1',
            'name' => 'Verified live product',
            'price' => 49,
            'stock' => 4,
            'is_active' => true,
        ]);
        $referenceSource = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'example.com product catalog',
            'url' => 'https://example.com/human-catalog',
            'status' => 'ready',
            'progress' => 100,
            'last_synced_at' => now()->subWeek(),
        ]);

        $this->actingAs($owner)->get(route('onboarding'))
            ->assertOk()
            ->assertSee('data-authoritative-commerce-catalog', false)
            ->assertSee('Verified live catalog is your live source of truth')
            ->assertSee('is not re-imported while this connection is active')
            ->assertSee('Saved reference')
            ->assertSee('live commerce catalog · source of truth')
            ->assertSee('authoritative');

        Http::preventStrayRequests();

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding([
            'catalog_url' => 'https://example.com/human-catalog',
        ]))->assertRedirect(route('channels.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warnings', [])
            ->assertSessionHas('success', 'Setup saved. Your live store connection remains the authoritative product catalog.');

        Http::assertNothingSent();
        $this->assertSame(
            $referenceSource->last_synced_at->toISOString(),
            $referenceSource->fresh()->last_synced_at->toISOString(),
        );
        $this->assertSame('https://example.com/human-catalog', $agent->fresh()->settings['catalog_url']);
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'commerce_connection_id' => $connection->id,
            'external_product_id' => 'live-1',
            'is_active' => true,
        ]);
    }

    public function test_unstructured_catalog_url_still_warns_before_a_live_connector_is_active(): void
    {
        [$owner, $agent] = $this->tenant('catalog-without-connector');
        Http::fake([
            'https://example.com/human-catalog' => Http::response('<html><body><h1>Our catalog</h1><p>Browse our products in store.</p></body></html>'),
        ]);

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding([
            'catalog_url' => 'https://example.com/human-catalog',
        ]))->assertRedirect(route('channels.index'))
            ->assertSessionHas(
                'warnings',
                fn (array $warnings): bool => count($warnings) === 1
                    && str_contains($warnings[0], 'did not expose structured products'),
            );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/human-catalog');
        $this->assertDatabaseHas('knowledge_sources', [
            'agent_id' => $agent->id,
            'url' => 'https://example.com/human-catalog',
            'status' => 'ready',
        ]);
        $this->assertSame(0, $agent->products()->count());
    }

    /** @return array{User, Agent} */
    private function tenant(string $slug, array $settings = []): array
    {
        $user = User::factory()->create();
        $businessName = str($slug)->headline()->toString();
        $organization = Organization::create(['name' => $businessName, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Nia',
            'slug' => $slug.'-agent',
            'business_name' => $businessName,
            'tone' => 'warm',
            'channels' => ['web'],
            'settings' => $settings,
        ]);

        return [$user, $agent];
    }

    private function validOnboarding(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Updated Business',
            'agent_name' => 'Nia',
            'description' => 'A focused business description.',
        ], $overrides);
    }
}
