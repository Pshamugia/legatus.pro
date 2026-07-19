<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Lead;
use App\Models\RecommendationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JudgeFacingPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_eval_traffic_is_excluded_from_customer_views_and_business_metrics(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $evalConversation = $agent->conversations()->create([
            'visitor_id' => 'eval-hidden',
            'customer_name' => 'Evaluation fixture',
            'channel' => 'eval',
            'status' => 'human',
            'handoff_reason' => 'Synthetic handoff',
            'outcome' => 'qualified_lead',
            'outcome_value' => 999999,
            'last_message_at' => now(),
        ]);
        $evalConversation->messages()->create([
            'role' => 'assistant',
            'content' => 'This synthetic message must stay out of the customer inbox.',
            'feedback' => 'helpful',
        ]);
        Lead::create([
            'agent_id' => $agent->id,
            'conversation_id' => $evalConversation->id,
            'status' => 'qualified',
            'intent' => 'synthetic',
        ]);
        RecommendationEvent::create([
            'conversation_id' => $evalConversation->id,
            'query' => ['synthetic' => true],
            'ranked_products' => [],
        ]);
        $evalRun = AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => $evalConversation->id,
            'model' => 'eval-secret-model',
            'response_id' => 'eval-secret-response',
            'status' => 'completed',
            'input_tokens' => 999999,
            'output_tokens' => 999999,
            'latency_ms' => 999999,
        ]);

        $dashboard = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame(6, $dashboard->viewData('metrics')['conversations']);
        $this->assertSame(2, $dashboard->viewData('metrics')['qualified_leads']);
        $this->assertSame(272.5, $dashboard->viewData('metrics')['revenue_influenced']);

        $analytics = $this->get(route('analytics.index'))->assertOk()->assertDontSee('eval-secret-model');
        $this->assertSame(6, $analytics->viewData('metrics')['conversations']);
        $this->assertSame(2, $analytics->viewData('metrics')['qualified_leads']);
        $this->assertSame(272.5, $analytics->viewData('metrics')['revenue_influenced']);
        $this->assertFalse($analytics->viewData('runs')->contains($evalRun));

        $inbox = $this->get(route('inbox.index'))->assertOk()->assertDontSee('Evaluation fixture');
        $this->assertFalse($inbox->viewData('conversations')->contains($evalConversation));
        $this->get(route('inbox.poll', $evalConversation))->assertNotFound();
    }

    public function test_seeded_demo_data_is_labeled_and_real_connector_state_is_honest(): void
    {
        $this->seed();
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="products"', false)
            ->assertSee('12 active products')
            ->assertSee('Piranesi')
            ->assertSee('Simulated demo')
            ->assertSee('Not connected')
            ->assertDontSee('Connector ready');

        $this->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Simulated demo data')
            ->assertSee('eval excluded');

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('KA · EN')
            ->assertDontSee('KA · EN · RU');
    }

    public function test_dashboard_and_analytics_share_the_same_qualified_lead_definition(): void
    {
        $this->seed();
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        Lead::where('agent_id', Agent::where('slug', 'legatus-demo')->value('id'))->where('status', 'qualified')->firstOrFail()->update(['status' => 'won']);

        $dashboardCount = $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('metrics')['qualified_leads'];
        $analyticsCount = $this->get(route('analytics.index'))->assertOk()->viewData('metrics')['qualified_leads'];

        $this->assertSame(2, $dashboardCount);
        $this->assertSame($dashboardCount, $analyticsCount);
    }

    public function test_inbox_poll_returns_appendable_messages_without_internal_metadata(): void
    {
        $this->seed();
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $conversation = Agent::where('slug', 'legatus-demo')->firstOrFail()->conversations()->where('visitor_id', 'demo-mariam')->firstOrFail();

        $response = $this->actingAs($user)->getJson(route('inbox.poll', $conversation))
            ->assertOk()
            ->assertJsonPath('simulated', true)
            ->assertJsonPath('status', 'ai')
            ->assertJsonStructure(['messages' => [['id', 'role', 'content', 'confidence', 'sources', 'created_at']]]);

        $this->assertArrayNotHasKey('metadata', $response->json('messages.0'));
        $this->assertArrayNotHasKey('conversation_id', $response->json('messages.0'));
    }
}
