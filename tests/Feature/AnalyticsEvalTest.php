<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EvaluationRun;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EvalCaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsEvalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_tenant_analytics(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();

        $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('AI performance')
            ->assertSee('Automation rate');
    }

    public function test_analytics_does_not_include_another_tenants_runs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $otherAgent = $other->agents()->create(['name' => 'Hidden Agent', 'slug' => 'hidden', 'business_name' => 'Hidden']);
        AgentRun::create(['agent_id' => $otherAgent->id, 'model' => 'secret-model', 'status' => 'completed', 'input_tokens' => 999999]);

        $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()->assertDontSee('secret-model')->assertDontSee('999,999');
    }

    public function test_offline_evaluation_suite_is_repeatable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(EvalCaseSeeder::class);

        $this->artisan('legatus:eval')->assertSuccessful();

        $run = EvaluationRun::latest()->firstOrFail();
        $this->assertSame(10, $run->passed);
        $this->assertSame(0, $run->failed);
        $this->assertSame('offline', $run->mode);
    }

    public function test_evaluation_replaces_linked_eval_runs_and_cleans_legacy_local_orphans(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(EvalCaseSeeder::class);
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $oldConversation = $agent->conversations()->create([
            'visitor_id' => 'eval-stale-run',
            'channel' => 'eval',
            'status' => 'ai',
        ]);
        $oldRun = AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => $oldConversation->id,
            'provider' => 'local',
            'model' => 'deterministic-fallback',
            'status' => 'fallback',
        ]);
        $legacyOrphan = AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => null,
            'provider' => 'local',
            'model' => 'deterministic-fallback',
            'status' => 'fallback',
        ]);

        $this->artisan('legatus:eval')->assertSuccessful();

        $this->assertDatabaseMissing('agent_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseMissing('agent_runs', ['id' => $legacyOrphan->id]);
        $this->assertSame(0, AgentRun::where('agent_id', $agent->id)->whereNull('conversation_id')->count());
        $this->assertSame(10, AgentRun::where('agent_id', $agent->id)
            ->whereIn('conversation_id', $agent->conversations()->where('channel', 'eval')->select('conversations.id'))
            ->count());

        $this->artisan('legatus:eval')->assertSuccessful();
        $this->assertSame(0, AgentRun::where('agent_id', $agent->id)->whereNull('conversation_id')->count());
        $this->assertSame(10, AgentRun::where('agent_id', $agent->id)
            ->whereIn('conversation_id', $agent->conversations()->where('channel', 'eval')->select('conversations.id'))
            ->count());
    }

    public function test_analytics_excludes_same_tenant_runs_without_a_conversation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $orphan = AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => null,
            'model' => 'orphan-run-must-not-render',
            'status' => 'completed',
            'input_tokens' => 777777,
        ]);

        $response = $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()
            ->assertDontSee('orphan-run-must-not-render')
            ->assertDontSee('777,777');

        $this->assertFalse($response->viewData('runs')->contains($orphan));
    }

    public function test_analytics_lists_only_the_current_tenants_recent_customer_feedback(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'rated-customer',
            'customer_name' => 'Visible Customer',
            'channel' => 'website',
            'status' => 'ai',
        ]);
        $visible = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Visible response that needs review.',
            'feedback' => 'unhelpful',
        ]);

        $other = Organization::create(['name' => 'Other Feedback Org', 'slug' => 'other-feedback']);
        $otherAgent = $other->agents()->create(['name' => 'Other Agent', 'slug' => 'other-feedback-agent', 'business_name' => 'Other']);
        $otherConversation = $otherAgent->conversations()->create([
            'visitor_id' => 'hidden-rated-customer',
            'channel' => 'website',
            'status' => 'ai',
        ]);
        $otherConversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Hidden response from another tenant.',
            'feedback' => 'unhelpful',
        ]);

        $response = $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Recent customer feedback')
            ->assertSee('Visible response that needs review.')
            ->assertSee(route('inbox.index', ['conversation' => $conversation->id]), false)
            ->assertDontSee('Hidden response from another tenant.');

        $this->assertTrue($response->viewData('recentFeedback')->contains($visible));
        $this->assertGreaterThanOrEqual(1, $response->viewData('metrics')['unhelpful_responses']);
    }

    public function test_quality_center_is_actionable_and_tenant_scoped(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Visible broken source',
            'status' => 'failed',
            'error' => 'Visible synchronization error.',
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'quality-customer',
            'channel' => 'website',
            'status' => 'ai',
            'last_message_at' => now()->subMinutes(10),
        ]);
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'Do you offer a verified warranty?',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'model' => 'visible-quality-model',
            'status' => 'failed',
            'error' => 'Visible run error.',
        ]);
        $silentConversation = $agent->conversations()->create([
            'visitor_id' => 'completed-social-turn',
            'channel' => 'facebook',
            'status' => 'ai',
            'outcome' => 'no_reply_needed',
            'last_message_at' => now()->subMinutes(10),
        ]);
        $silentConversation->messages()->create([
            'role' => 'customer',
            'content' => 'Thank you, that is all.',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $other = Organization::create(['name' => 'Hidden Quality Org', 'slug' => 'hidden-quality']);
        $otherAgent = $other->agents()->create(['name' => 'Hidden Agent', 'slug' => 'hidden-quality-agent', 'business_name' => 'Hidden']);
        $otherAgent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Hidden broken source',
            'status' => 'failed',
            'error' => 'Hidden synchronization error.',
        ]);

        $response = $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Quality & knowledge center', false)
            ->assertSee('Suggested fixes')
            ->assertSee('Do you offer a verified warranty?')
            ->assertSee('Open the conversation')
            ->assertSee($source->name)
            ->assertSee('visible-quality-model')
            ->assertSee(route('knowledge.index'), false)
            ->assertDontSee('Thank you, that is all.')
            ->assertDontSee('Hidden broken source');

        $this->assertSame(1, $response->viewData('quality')['knowledge_issues']);
        $this->assertGreaterThanOrEqual(1, $response->viewData('quality')['actionable_issues']);
        $this->assertTrue($response->viewData('qualityIssues')->contains(
            fn (array $issue): bool => $issue['type'] === 'unanswered_question'
                && $issue['conversation_id'] === $conversation->id,
        ));
        $this->assertTrue($response->viewData('degradedRuns')->contains('id', AgentRun::latest('id')->value('id')));
    }
}
