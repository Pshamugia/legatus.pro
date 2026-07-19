<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use App\Services\SalesAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegatusCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_artisan_commands_use_the_legatus_namespace(): void
    {
        $commands = Artisan::all();

        foreach ([
            'legatus:bootstrap-demo-tenant',
            'legatus:eval',
            'legatus:sync-knowledge',
            'legatus:verify-openai',
            'legatus:purge-expired-data',
            'legatus:expire-reservations',
        ] as $command) {
            $this->assertArrayHasKey($command, $commands);
        }

        $this->assertArrayNotHasKey('nia:bootstrap-demo-tenant', $commands);
        $this->assertArrayNotHasKey('nia:eval', $commands);
        $this->assertArrayNotHasKey('nia:sync-knowledge', $commands);
        $this->assertArrayNotHasKey('nia:verify-openai', $commands);
    }

    public function test_demo_bootstrap_migrates_the_legacy_demo_login(): void
    {
        User::factory()->create(['email' => 'demo@nia.ai']);
        config(['legatus.demo_password' => 'Never-Print-This-Demo-Secret']);

        $this->assertSame(0, Artisan::call('legatus:bootstrap-demo-tenant'));
        $this->assertStringContainsString('Legatus demo workspace ready: demo@legatus.ai', Artisan::output());
        $this->assertStringNotContainsString('Never-Print-This-Demo-Secret', Artisan::output());

        $this->assertDatabaseHas('users', ['email' => 'demo@legatus.ai']);
        $this->assertDatabaseMissing('users', ['email' => 'demo@nia.ai']);
        $this->assertDatabaseHas('organizations', ['slug' => 'legatus-demo']);
        $this->assertDatabaseHas('organization_user', ['role' => 'owner']);
        $this->assertDatabaseHas('agents', ['slug' => 'legatus-demo', 'name' => 'Legatus']);
        $this->assertDatabaseCount('products', 12);
    }

    public function test_eval_resolves_a_slug_without_comparing_it_to_the_numeric_agent_id(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        DB::enableQueryLog();

        $this->assertSame(0, Artisan::call('legatus:eval', ['--agent' => $agent->slug]));

        $this->assertSlugWasNotBoundToAnIdComparison($agent->slug);
    }

    public function test_openai_verifier_resolves_a_slug_without_comparing_it_to_the_numeric_agent_id(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        config(['services.openai.key' => 'test-key']);
        $service = \Mockery::mock(SalesAgentService::class);
        $service->shouldReceive('reply')->once()->andReturnUsing(function (Agent $resolvedAgent, string $prompt, $conversation): array {
            AgentRun::create([
                'agent_id' => $resolvedAgent->id,
                'conversation_id' => $conversation->id,
                'model' => 'test-model',
                'status' => 'completed',
                'tools_used' => [
                    ['name' => 'check_stock'],
                ],
            ]);

            return [
                'text' => 'Verified test reply.',
                'intent' => 'stock',
                'handoff' => false,
                'tools_used' => ['check_stock'],
            ];
        });
        $this->app->instance(SalesAgentService::class, $service);
        DB::enableQueryLog();

        $this->assertSame(0, Artisan::call('legatus:verify-openai', ['--agent' => $agent->slug]));

        $this->assertSlugWasNotBoundToAnIdComparison($agent->slug);
    }

    private function assertSlugWasNotBoundToAnIdComparison(string $slug): void
    {
        $unsafeQuery = collect(DB::getQueryLog())->first(function (array $query) use ($slug): bool {
            return in_array($slug, $query['bindings'], true)
                && preg_match('/["`]?id["`]?\s*=\s*\?/i', $query['query']) === 1;
        });

        $this->assertNull($unsafeQuery, 'A slug was bound to a numeric id comparison: '.json_encode($unsafeQuery));
    }
}
