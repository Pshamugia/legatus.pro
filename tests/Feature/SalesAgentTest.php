<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null]);
    }

    public function test_landing_page_is_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Every conversation');
    }

    public function test_demo_agent_answers_and_persists_a_conversation(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'რამდენი ღირს Piranesi?'])
            ->assertOk()->assertJsonPath('intent', 'price')->assertJsonPath('handoff', false);
        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertNotNull($visitorId);
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'intent' => 'price']);
        $conversation = $agent->conversations()->where('visitor_id', $visitorId)->firstOrFail();
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_customer_can_request_a_human(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'ოპერატორთან დამაკავშირე'])
            ->assertOk()->assertJsonPath('handoff', true);
        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'status' => 'human']);
    }
}
