<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProductMerchandisingRule;
use App\Models\User;
use App\Services\SalesToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchandisingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_ranking_is_unchanged_and_active_rules_only_reorder_relevant_products(): void
    {
        [, $agent] = $this->tenant('merch-ranking', 'owner');
        $first = $agent->products()->create(['name' => 'Trail Shoe Alpha', 'category' => 'Shoes', 'description' => 'Light trail running shoe', 'price' => 50, 'stock' => 3, 'is_active' => true]);
        $second = $agent->products()->create(['name' => 'Trail Shoe Beta', 'category' => 'Shoes', 'description' => 'Light trail running shoe', 'price' => 50, 'stock' => 3, 'is_active' => true]);
        $unrelated = $agent->products()->create(['name' => 'Coffee Mug', 'category' => 'Kitchen', 'description' => 'Ceramic mug', 'price' => 12, 'stock' => 4, 'is_active' => true]);
        $conversation = $agent->conversations()->create(['visitor_id' => 'merch-visitor', 'status' => 'ai']);
        $arguments = ['query' => 'trail shoe', 'budget' => null, 'quantity' => null, 'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 5, 'exclude_product_ids' => []];

        $organic = app(SalesToolbox::class)->execute('recommend_products', $arguments, $agent, $conversation);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], collect($organic['recommendations'])->pluck('id')->all());
        $this->assertNotContains($unrelated->id, collect($organic['recommendations'])->pluck('id')->all());

        ProductMerchandisingRule::create(['agent_id' => $agent->id, 'product_id' => $second->id, 'recommendation_status' => 'promote', 'priority' => 100]);
        ProductMerchandisingRule::create(['agent_id' => $agent->id, 'product_id' => $first->id, 'recommendation_status' => 'exclude', 'priority' => 0]);
        $ranked = app(SalesToolbox::class)->execute('recommend_products', $arguments, $agent, $conversation);

        $this->assertSame([$second->id], collect($ranked['recommendations'])->pluck('id')->all());
        $this->assertNotContains($unrelated->id, collect($ranked['recommendations'])->pluck('id')->all());
    }

    public function test_expired_rules_have_no_effect(): void
    {
        [, $agent] = $this->tenant('merch-expired', 'owner');
        $product = $agent->products()->create(['name' => 'Blue Jacket', 'description' => 'Warm blue jacket', 'price' => 40, 'stock' => 2, 'is_active' => true]);
        ProductMerchandisingRule::create(['agent_id' => $agent->id, 'product_id' => $product->id, 'recommendation_status' => 'exclude', 'priority' => 0, 'ends_at' => now()->subMinute()]);
        $conversation = $agent->conversations()->create(['visitor_id' => 'expired-rule', 'status' => 'ai']);

        $result = app(SalesToolbox::class)->execute('recommend_products', ['query' => 'blue jacket', 'budget' => null, 'quantity' => null, 'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 5, 'exclude_product_ids' => []], $agent, $conversation);

        $this->assertSame([$product->id], collect($result['recommendations'])->pluck('id')->all());
    }

    public function test_management_is_tenant_scoped_and_viewers_cannot_change_rules(): void
    {
        [$owner, $agent] = $this->tenant('merch-owner', 'owner');
        [$viewer, $viewerAgent] = $this->tenant('merch-viewer', 'viewer');
        $product = $agent->products()->create(['name' => 'Owner Product', 'price' => 20, 'stock' => 1, 'is_active' => true]);
        $viewerProduct = $viewerAgent->products()->create(['name' => 'Viewer Product', 'price' => 30, 'stock' => 1, 'is_active' => true]);

        $this->actingAs($owner)->get(route('merchandising.index'))->assertOk()->assertSee('Owner Product')->assertDontSee('Viewer Product');
        $this->actingAs($owner)->put(route('merchandising.update', $viewerProduct), ['recommendation_status' => 'exclude', 'priority' => 0, 'campaign_label' => null])->assertNotFound();
        $this->actingAs($viewer)->put(route('merchandising.update', $viewerProduct), ['recommendation_status' => 'exclude', 'priority' => 0, 'campaign_label' => null])->assertForbidden();
        $this->assertDatabaseCount('product_merchandising_rules', 0);

        $this->actingAs($owner)->put(route('merchandising.update', $product), ['recommendation_status' => 'promote', 'priority' => 25, 'campaign_label' => 'Spring'])->assertRedirect();
        $this->assertDatabaseHas('product_merchandising_rules', ['agent_id' => $agent->id, 'product_id' => $product->id, 'priority' => 25]);
    }

    public function test_widget_returns_only_the_exact_active_product_page_campaign(): void
    {
        [, $agent] = $this->tenant('merch-proactive', 'owner');
        $product = $agent->products()->create([
            'name' => 'Campaign Product', 'price' => 25, 'stock' => 2, 'is_active' => true,
            'metadata' => ['product_url' => 'https://shop.example/products/campaign'],
        ]);
        ProductMerchandisingRule::create([
            'agent_id' => $agent->id, 'product_id' => $product->id,
            'recommendation_status' => 'promote', 'priority' => 20,
            'proactive_message' => 'Need help choosing this product?',
            'proactive_prompt' => 'Tell me more',
        ]);

        $this->postJson(route('widget.context', $agent), ['url' => 'https://shop.example/products/campaign'])
            ->assertOk()->assertExactJson(['message' => 'Need help choosing this product?', 'prompt' => 'Tell me more']);
        $this->postJson(route('widget.context', $agent), ['url' => 'https://shop.example/products/other'])
            ->assertOk()->assertExactJson(['message' => null, 'prompt' => null]);
        $this->get(route('widget.script', $agent))
            ->assertOk()->assertSee('legatus:page-context', false)->assertSee('legatus:proactive', false);
    }

    private function tenant(string $slug, string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => $slug, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => $role]);
        $agent = $organization->agents()->create(['name' => 'Assistant', 'slug' => $slug.'-agent', 'business_name' => $slug]);

        return [$user, $agent];
    }
}
