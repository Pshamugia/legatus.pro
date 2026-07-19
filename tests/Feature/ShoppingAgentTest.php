<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\SalesToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null]);
    }

    public function test_preferences_are_remembered_and_recommendations_are_traced(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create(['visitor_id' => 'shopper', 'status' => 'ai', 'channel' => 'web']);
        $tools = app(SalesToolbox::class);
        $saved = $tools->execute('save_shopping_preferences', ['budget' => 30, 'occasion' => 'gift', 'mood' => 'mysterious', 'likes' => ['magical realism'], 'dislikes' => [], 'recipient' => 'friend'], $agent, $conversation);
        $this->assertTrue($saved['ok']);
        $result = $tools->execute('recommend_products', ['query' => 'mysterious magical novel', 'budget' => 30, 'category' => null, 'mood' => 'mysterious', 'occasion' => 'gift', 'limit' => 3], $agent, $conversation);
        $this->assertTrue($result['ok']);
        $this->assertLessThanOrEqual(30, $result['recommendations'][0]['price']);
        $this->assertGreaterThan(0, $result['recommendations'][0]['stock']);
        $this->assertTrue(collect($result['recommendations'])->every(fn ($product) => $product['price'] <= 30 && $product['stock'] > 0));
        $this->assertDatabaseHas('shopping_profiles', ['conversation_id' => $conversation->id]);
        $this->assertDatabaseHas('recommendation_events', ['conversation_id' => $conversation->id]);
    }

    public function test_comparison_returns_only_verified_catalog_fields(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create(['visitor_id' => 'compare', 'status' => 'ai', 'channel' => 'web']);
        $ids = $agent->products()->take(2)->pluck('id')->all();
        $result = app(SalesToolbox::class)->execute('compare_products', ['product_ids' => $ids], $agent, $conversation);
        $this->assertCount(2, $result['products']);
        $this->assertArrayHasKey('price', $result['products'][0]);
        $this->assertArrayHasKey('stock', $result['products'][0]);
    }
}
