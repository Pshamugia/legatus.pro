<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\KnowledgeChunk;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegatusSupportAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegatusSupportAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_bootstrap_creates_an_isolated_idempotent_platform_tenant(): void
    {
        config([
            'app.url' => 'https://legatus.example',
            'legatus.privacy_email' => 'support@legatus.example',
            'legatus.super_admin_email' => 'owner@legatus.example',
        ]);
        $owner = User::factory()->create(['email' => 'owner@legatus.example']);

        $this->artisan('legatus:bootstrap-support-assistant')
            ->expectsOutput('Legatus website assistant ready: legatus-support')
            ->assertSuccessful();
        $this->artisan('legatus:bootstrap-support-assistant')->assertSuccessful();

        $organization = Organization::where('slug', LegatusSupportAssistant::ORGANIZATION_SLUG)->firstOrFail();
        $agent = Agent::where('slug', LegatusSupportAssistant::AGENT_SLUG)->firstOrFail();

        $this->assertSame($organization->id, $agent->organization_id);
        $this->assertSame('platform_support', data_get($agent->settings, 'assistant_mode'));
        $this->assertSame(['web'], $agent->channels);
        $this->assertSame(['https://legatus.example', 'https://www.legatus.example'], data_get($agent->settings, 'widget_allowed_origins'));
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('agents', 1);
        $this->assertDatabaseCount('billing_access_grants', 1);
        $this->assertDatabaseCount('knowledge_sources', 1);
        $this->assertDatabaseCount('knowledge_chunks', 6);
        $this->assertTrue(KnowledgeChunk::where('content', 'like', '%$30%')->exists());
    }

    public function test_landing_embeds_the_official_support_widget_and_opens_it_from_the_page(): void
    {
        config(['app.url' => 'http://localhost']);
        $agent = app(LegatusSupportAssistant::class)->bootstrap();

        $response = $this->get('/')
            ->assertOk()
            ->assertSee('id="ask-legatus"', false)
            ->assertSee('Ask Legatus')
            ->assertSee('data-legatus-chat-open', false)
            ->assertSee('window.LegatusWidgetRequestedOpen = true', false)
            ->assertSee('/widget/install/'.$agent->id.'.js', false)
            ->assertSee('signature=', false);

        $this->assertStringContainsString('href="#ask-legatus"', $response->getContent());

        $this->get(URL::signedRoute('widget.install.script', ['agent' => $agent->id]))
            ->assertOk()
            ->assertSee('window.LegatusWidget={open:open,close:close,toggle:toggle}', false)
            ->assertSee('Ask Legatus');
    }

    public function test_platform_widget_uses_product_guide_copy_instead_of_store_shopping_prompts(): void
    {
        $agent = app(LegatusSupportAssistant::class)->bootstrap();

        $this->get('/widget/'.$agent->slug.'?lang=en')
            ->assertOk()
            ->assertSee('Legatus product guide')
            ->assertSee('How does Legatus work for a business?')
            ->assertSee('What plans and prices does Legatus offer?')
            ->assertSee('Which communication and publishing channels does Legatus support?')
            ->assertDontSee('data-q="Help me choose. Ask one useful clarifying question first', false);
    }

    public function test_platform_assistant_answers_pricing_from_its_verified_knowledge(): void
    {
        $agent = app(LegatusSupportAssistant::class)->bootstrap();
        config(['services.openai.key' => 'test-key']);

        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'support-context', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_delivery_request' => false,
                    'delivery_request_type' => 'none',
                    'is_human_request' => false,
                    'is_business_knowledge_request' => true,
                    'knowledge_query' => 'რა ღირს Legatus Chat?',
                    'knowledge_scope' => 'business',
                    'is_catalog_follow_up' => false,
                    'catalog_scope_action' => 'none',
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    'resolved_query' => null,
                    'resolved_queries' => [],
                    'resolved_category' => null,
                    'catalog_match_scope' => 'exact_identity',
                    'exclude_product_ids' => [],
                    'expects_complete_set' => false,
                ], JSON_UNESCAPED_UNICODE)]],
            ]], 'usage' => []])
            ->push(['data' => [['index' => 0, 'embedding' => [1.0, 0.0]]]])
            ->push(['id' => 'support-price-answer', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'ჩვენი Legatus Chat გეგმა თვეში $30 ღირს.',
                    'intent' => 'discovery',
                    'confidence' => .99,
                    'handoff' => false,
                    'escalation_reason' => null,
                    'clarification_next_tool' => null,
                    'clarification_missing_input' => null,
                    'product_ids' => [],
                    'sources' => [['label' => 'Plans and pricing', 'type' => 'policy']],
                    'factual_claims' => [[
                        'type' => 'policy',
                        'product_id' => null,
                        'amount' => 30,
                        'quantity' => null,
                        'reference' => 'Plans and pricing',
                    ]],
                ], JSON_UNESCAPED_UNICODE)]],
            ]], 'usage' => []]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'რა ღირს Legatus Chat?',
        ])->assertOk()->assertJsonPath('handoff', false);

        $this->assertSame('ჩვენი Legatus Chat გეგმა თვეში $30 ღირს.', $response->json('text'));
        $this->assertContains('search_knowledge', $response->json('tools_used'));
        $this->assertNotContains('search_products', $response->json('tools_used'));
        $this->assertSame('completed', AgentRun::where('agent_id', $agent->id)->latest('id')->value('status'));

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/responses')
            && str_contains((string) data_get($request->data(), 'instructions'), 'official Legatus product guide'));
    }
}
