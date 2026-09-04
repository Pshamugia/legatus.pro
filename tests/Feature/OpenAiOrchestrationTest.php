<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Reservation;
use App\Services\SalesAgentService;
use App\Services\SalesToolbox;
use App\Services\OpenAiSalesOrchestrator;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_question_is_answered_from_manual_knowledge_even_when_model_skips_tools(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'text', 'source_scope' => 'delivery', 'name' => 'Customer information',
            'status' => 'ready', 'progress' => 100,
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'policy', 'title' => 'Customer information',
            'content' => 'თბილისის მასშტაბით მომსახურება ღირს 5 ლარი და სრულდება 1-2 სამუშაო დღეში.',
            'content_hash' => hash('sha256', 'manual-delivery-orchestration'),
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'delivery-intent', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_delivery_request' => true,
                    'delivery_request_type' => 'general_policy',
                    'is_catalog_follow_up' => false,
                    'catalog_scope_action' => 'none',
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    'resolved_query' => null,
                    'resolved_category' => null,
                    'catalog_match_scope' => 'exact_identity',
                    'exclude_product_ids' => [],
                    'expects_complete_set' => false,
                ])]],
            ]], 'usage' => []])
            ->push([
                'id' => 'delivery-final',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'text' => 'ვერ გადავამოწმე.', 'intent' => 'clarification', 'confidence' => .99,
                            'handoff' => false, 'escalation_reason' => null, 'product_ids' => [],
                            'sources' => [], 'factual_claims' => [],
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'სახლში მიწოდება შეიძლება?',
        ])->assertOk()->assertJsonPath('intent', 'delivery')->assertJsonPath('handoff', false);

        $this->assertStringContainsString('1-2 სამუშაო დღეში', $response->json('text'));
        $this->assertContains('calculate_delivery', $response->json('tools_used'));
        $this->assertNotContains('server_guardrail', $response->json('tools_used'));
    }

    public function test_semantic_delivery_intent_understands_natural_arrival_wording_without_keyword_rules(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'text', 'source_scope' => 'delivery', 'name' => 'Delivery terms',
            'status' => 'ready', 'progress' => 100,
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'policy', 'title' => 'Delivery terms',
            'content' => 'თბილისში მიტანის დრო არის 2 სამუშაო დღე.',
            'content_hash' => hash('sha256', 'semantic-delivery-intent'),
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'delivery-intent', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_delivery_request' => true,
                    'delivery_request_type' => 'general_policy',
                    'is_catalog_follow_up' => false,
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    'resolved_query' => null,
                    'exclude_product_ids' => [],
                    'expects_complete_set' => false,
                ])]],
            ]], 'usage' => []])
            ->push(['id' => 'delivery-answer', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'I cannot verify that.', 'intent' => 'clarification', 'confidence' => .8,
                    'handoff' => false, 'escalation_reason' => null, 'product_ids' => [],
                    'sources' => [], 'factual_claims' => [],
                ])]],
            ]], 'usage' => []]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'შეკვეთას როდის მოიტანენ?',
        ])->assertOk()->assertJsonPath('intent', 'delivery');

        $this->assertStringContainsString('2 სამუშაო დღე', $response->json('text'));
        $this->assertContains('calculate_delivery', $response->json('tools_used'));
        $resolverRequest = Http::recorded()->map(fn ($pair) => $pair[0])->first(
            fn ($request): bool => data_get($request->data(), 'text.format.name') === 'catalog_follow_up'
        );
        $this->assertTrue((bool) data_get($resolverRequest?->data(), 'text.format.schema.properties.is_delivery_request'));
    }

    public function test_semantic_delivery_intent_uses_prior_dialogue_for_an_indirect_follow_up(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'text', 'source_scope' => 'delivery', 'name' => 'Delivery terms',
            'status' => 'ready', 'progress' => 100,
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'policy', 'title' => 'Delivery terms',
            'content' => 'თბილისში მიტანის დრო არის 2 სამუშაო დღე.',
            'content_hash' => hash('sha256', 'contextual-delivery-intent'),
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'contextual-delivery-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'დღეს დილით შევუკვეთე პროდუქტი მითითებულ მისამართზე.',
        ]);
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'და როდის უნდა ველოდო?',
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'contextual-delivery-intent', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_delivery_request' => true,
                    'delivery_request_type' => 'general_policy',
                    'is_catalog_follow_up' => false,
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    'resolved_query' => null,
                    'exclude_product_ids' => [],
                    'expects_complete_set' => false,
                ])]],
            ]], 'usage' => []])
            ->push(['id' => 'contextual-delivery-answer', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'I cannot verify that.', 'intent' => 'clarification', 'confidence' => .8,
                    'handoff' => false, 'escalation_reason' => null, 'product_ids' => [],
                    'sources' => [], 'factual_claims' => [],
                ])]],
            ]], 'usage' => []]);

        $reply = app(OpenAiSalesOrchestrator::class)->respond($agent, $conversation, 'და როდის უნდა ველოდო?');

        $this->assertSame('delivery', $reply['intent']);
        $this->assertStringContainsString('2 სამუშაო დღე', $reply['text']);
        $this->assertContains('calculate_delivery', $reply['tools_used']);
        $resolverInput = Http::recorded()->map(fn ($pair) => $pair[0])->first(
            fn ($request): bool => data_get($request->data(), 'text.format.name') === 'catalog_follow_up'
        )?->data()['input'] ?? [];
        $this->assertStringContainsString(
            'დღეს დილით შევუკვეთე პროდუქტი მითითებულ მისამართზე.',
            json_encode($resolverInput, JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_existing_order_delivery_status_is_handed_off_instead_of_repeating_general_policy(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'text', 'source_scope' => 'delivery', 'name' => 'Delivery terms',
            'status' => 'ready', 'progress' => 100,
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'policy', 'title' => 'Delivery terms',
            'content' => 'Tbilisi delivery takes two business days.',
            'content_hash' => hash('sha256', 'existing-order-delivery-handoff'),
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'existing-order-customer', 'status' => 'ai', 'channel' => 'facebook',
        ]);
        $policy = 'Tbilisi delivery takes two business days.';
        $conversation->messages()->create(['role' => 'assistant', 'content' => $policy]);
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'I ordered two days ago. Can you check with the courier whether it will arrive today?',
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'existing-order-intent', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_delivery_request' => true,
                    'delivery_request_type' => 'existing_order_status',
                    'is_catalog_follow_up' => false,
                    'catalog_scope_action' => 'none',
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    'resolved_query' => null,
                    'resolved_category' => null,
                    'catalog_match_scope' => 'exact_identity',
                    'exclude_product_ids' => [],
                    'expects_complete_set' => false,
                ])]],
            ]], 'usage' => []]);

        $reply = app(OpenAiSalesOrchestrator::class)->respond(
            $agent,
            $conversation,
            'I ordered two days ago. Can you check with the courier whether it will arrive today?',
        );

        $this->assertTrue($reply['handoff']);
        $this->assertSame('handoff', $reply['intent']);
        $this->assertNotSame($policy, $reply['text']);
        $this->assertContains('resolve_delivery_context', $reply['tools_used']);
        $this->assertNotContains('calculate_delivery', $reply['tools_used']);
        $this->assertSame('human', $conversation->fresh()->status);
        $this->assertStringContainsString('order-tracking or courier-contact tool', $conversation->fresh()->handoff_reason);
        Http::assertSentCount(2);
    }

    public function test_recent_product_attributes_are_supplied_for_relational_follow_ups(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        $product->update(['metadata' => array_merge($product->metadata ?? [], [
            'author' => 'James Joyce',
        ])]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'entity-context-customer',
            'status' => 'ai',
            'channel' => 'facebook',
            'context' => ['last_catalog_product_ids' => [$product->id]],
        ]);

        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'contextualCatalogInstructions');
        $instructions = $method->invoke(
            app(OpenAiSalesOrchestrator::class),
            $agent,
            $conversation,
        );

        $this->assertStringContainsString('James Joyce', $instructions);
        $this->assertStringContainsString('mandatory query constraint', $instructions);
        $this->assertStringContainsString('Do not return a product whose corresponding attribute differs', $instructions);
    }

    public function test_semantic_follow_up_resolution_searches_the_prior_entity_without_repeating_the_prior_product(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $previous = $agent->products()->firstOrFail();
        $previous->update(['search_text' => 'Acme First', 'metadata' => ['brand' => 'Acme']]);
        $next = $agent->products()->create([
            'name' => 'Acme Second',
            'search_text' => 'Acme Second',
            'price' => 12,
            'stock' => 0,
            'is_active' => true,
            'metadata' => ['brand' => 'Acme'],
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'semantic-follow-up-customer',
            'status' => 'ai',
            'channel' => 'widget',
            'context' => [
                'last_catalog_product_ids' => [$previous->id],
                'active_catalog_scope' => [
                    'tool' => 'search_products',
                    'query' => 'Acme',
                    'catalog_match_scope' => 'entity_family',
                    'shown_product_ids' => [$previous->id],
                ],
            ],
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'context-resolution', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_catalog_follow_up' => true,
                    'catalog_scope_action' => 'continue',
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    // Simulate a weaker model interpreting the short turn in
                    // isolation. The server must retain the verified active
                    // shopping scope instead of allowing this drift.
                    'resolved_query' => 'Unrelated',
                    'catalog_match_scope' => 'exact_identity',
                    'exclude_product_ids' => [],
                    'expects_complete_set' => true,
                ])]],
            ]], 'usage' => []])
            ->push(['id' => 'follow-up-stock-call', 'output' => [[
                'type' => 'function_call',
                'name' => 'check_stock',
                'call_id' => 'follow-up-stock',
                'arguments' => json_encode(['product_id' => $next->id, 'quantity' => 1]),
            ]], 'usage' => []])
            ->push(['id' => 'follow-up-final', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'The additional verified catalog match is Acme Second.',
                    'intent' => 'discovery',
                    'confidence' => .99,
                    'handoff' => false,
                    'escalation_reason' => null,
                    'product_ids' => [$next->id],
                    'sources' => [],
                    'factual_claims' => [[
                        'type' => 'product', 'product_id' => $next->id, 'amount' => null,
                        'quantity' => null, 'reference' => null,
                    ]],
                ])]],
            ]], 'usage' => []]);

        $reply = app(SalesAgentService::class)->reply($agent, 'Anything else?', $conversation);

        $this->assertSame([$next->id], collect($reply['products'])->pluck('id')->all());
        $this->assertNotContains($previous->id, collect($reply['products'])->pluck('id')->all());
        $this->assertContains('resolve_catalog_context', $reply['tools_used']);
        $this->assertContains('search_products', $reply['tools_used']);
        $searchRun = AgentRun::where('agent_id', $agent->id)
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->firstOrFail();
        $searchCall = collect($searchRun->tools_used)->firstWhere('name', 'search_products');
        $this->assertSame('Acme', data_get($searchCall, 'arguments.query'));
        $this->assertSame([$previous->id], data_get($searchCall, 'arguments.exclude_product_ids'));
        $this->assertSame('Acme', data_get($conversation->fresh()->context, 'active_catalog_scope.query'));
        $contextRequest = Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($request): bool => data_get($request->data(), 'text.format.name') === 'catalog_follow_up');
        $this->assertNotNull($contextRequest);
        $this->assertSame('Anything else?', data_get(collect($contextRequest->data()['input'])->last(), 'content'));
        $this->assertStringContainsString('Recently shown product records', (string) $contextRequest->data()['instructions']);
        $this->assertStringContainsString('An open-ended recommendation is not a direct lookup', (string) $contextRequest->data()['instructions']);
        $this->assertStringContainsString('failed exact bundle lookup never proves that entity-family items are absent', (string) $contextRequest->data()['instructions']);
    }

    public function test_more_results_keeps_the_verified_category_scope_and_excludes_shown_products(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'category-continuation-customer',
            'status' => 'ai',
            'channel' => 'widget',
            'context' => [
                'last_catalog_product_ids' => [31, 32],
                'active_catalog_scope' => [
                    'tool' => 'search_products',
                    'query' => 'Biography',
                    'category' => 'Biography',
                    'catalog_match_scope' => 'entity_family',
                    'shown_product_ids' => [29, 30],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'mergeActiveCatalogScope');
        $resolved = $method->invoke(app(OpenAiSalesOrchestrator::class), $conversation, [
            'is_catalog_follow_up' => true,
            'catalog_scope_action' => 'continue',
            'recommendation_scope' => 'none',
            'resolved_query' => 'something else',
            'resolved_category' => null,
            'catalog_match_scope' => 'exact_identity',
            'exclude_product_ids' => [],
        ]);

        $this->assertSame('Biography', $resolved['resolved_query']);
        $this->assertSame('Biography', $resolved['resolved_category']);
        $this->assertSame('entity_family', $resolved['catalog_match_scope']);
        $this->assertSame([29, 30, 31, 32], $resolved['exclude_product_ids']);
    }

    #[DataProvider('crossIndustryMessages')]
    public function test_production_messages_use_semantic_orchestration_regardless_of_industry(string $message): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'semantic-routing-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        config([
            'services.openai.key' => 'test-key',
            'legatus.semantic_orchestration_enabled' => true,
        ]);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push([
                'id' => 'semantic_route',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'text' => 'გთხოვთ, დამიზუსტოთ რომელი მახასიათებელია თქვენთვის მნიშვნელოვანი.',
                            'intent' => 'clarification',
                            'confidence' => .99,
                            'handoff' => false,
                            'escalation_reason' => null,
                            'product_ids' => [],
                            'sources' => [],
                            'factual_claims' => [],
                        ]),
                    ]],
                ]],
                'usage' => [],
            ]);

        $reply = app(SalesAgentService::class)->reply(
            $agent,
            $message,
            $conversation,
        );

        $this->assertSame('clarification', $reply['intent'], json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'provider' => 'openai',
            'response_id' => 'semantic_route',
        ]);
        $this->assertDatabaseMissing('agent_runs', [
            'conversation_id' => $conversation->id,
            'model' => 'verified-catalog-responder',
        ]);
    }

    public static function crossIndustryMessages(): array
    {
        return [
            'retail product question' => ['Do you have this in another size and color?'],
            'restaurant dietary question' => ['Which option is suitable for someone avoiding dairy?'],
            'beauty appointment question' => ['Can I book the earliest available appointment tomorrow?'],
            'professional service question' => ['Which package fits a small company with five employees?'],
            'travel follow-up question' => ['Does that option include airport transfer too?'],
            'ambiguous contextual follow-up' => ['What can you tell me about this one?'],
            'natural seasonal recommendation' => ['რა წიგნს მირჩევდი ზაფხულში საკითხავად?'],
        ];
    }

    public function test_low_confidence_social_turn_is_not_rejected_by_commercial_verification(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'natural-gratitude-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'social-final', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'ძალიან მიხარია! მადლობა თქვენც 💚',
                    'intent' => 'conversation',
                    'confidence' => .55,
                    'handoff' => false,
                    'escalation_reason' => null,
                    'product_ids' => [],
                    'sources' => [],
                    'factual_claims' => [],
                ], JSON_UNESCAPED_UNICODE)]],
            ]], 'usage' => []]);

        $reply = app(SalesAgentService::class)->reply($agent, 'მშვენიერია. დიდი მადლობა <3', $conversation);

        $this->assertSame('conversation', $reply['intent']);
        $this->assertSame('ძალიან მიხარია! მადლობა თქვენც 💚', $reply['text']);
        $this->assertFalse($reply['handoff']);
        $this->assertSame([], $reply['tools_used']);
    }

    public function test_empty_business_catalog_recommendation_cannot_be_replaced_with_general_model_knowledge(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'catalog-boundary-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        config(['services.openai.key' => 'test-key']);
        $responseCall = 0;
        Http::fake(function ($request) use (&$responseCall) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                $responseCall++;
                if ($responseCall === 1) {
                    return Http::response(['id' => 'catalog-boundary-tool', 'output' => [[
                        'type' => 'function_call',
                        'name' => 'recommend_products',
                        'call_id' => 'recommend-empty',
                        'arguments' => json_encode(['query' => 'summer detective', 'budget' => null, 'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 3]),
                    ]], 'usage' => []]);
                }

                return Http::response(['id' => 'catalog-boundary-final', 'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'I could not verify a matching summer detective title in this catalog. Would you prefer a different setting or a broader mystery selection?',
                        'intent' => 'recommendation',
                        'confidence' => .92,
                        'handoff' => false,
                        'escalation_reason' => null,
                        'product_ids' => [],
                        'sources' => [],
                        'factual_claims' => [],
                    ], JSON_UNESCAPED_UNICODE)]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'ზაფხულისთვის კლასიკური დეტექტივი მირჩიე', $conversation);

        $this->assertSame('I could not verify a matching summer detective title in this catalog. Would you prefer a different setting or a broader mystery selection?', $reply['text']);
        $this->assertSame([], $reply['products']->all());
        $this->assertSame(['recommend_products'], $reply['tools_used']);
    }

    public function test_verified_catalog_typo_suggestion_cannot_be_ignored_by_the_model(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url', 'name' => 'Real catalog', 'url' => 'https://shop.example/catalog', 'status' => 'ready',
        ]);
        $product = $agent->products()->create([
            'name' => 'ვეფხისტყაოსანი', 'sku' => 'TYPO-TITLE-1', 'search_text' => 'ვეფხისტყაოსანი შოთა რუსთაველი',
            'price' => 15, 'stock' => 1, 'is_active' => true, 'metadata' => ['source_id' => $source->id, 'author' => 'შოთა რუსთაველი'],
        ]);
        $conversation = $agent->conversations()->create(['visitor_id' => 'title-typo-customer', 'status' => 'ai', 'channel' => 'widget']);
        config(['services.openai.key' => 'test-key']);
        $responseCall = 0;
        Http::fake(function ($request) use (&$responseCall) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                $responseCall++;
                if ($responseCall === 1) {
                    return Http::response(['id' => 'typo-tool', 'output' => [[
                        'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'typo-call',
                        'arguments' => json_encode(['query' => 'ვეფხვისტყაოსანი', 'budget' => null, 'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 3]),
                    ]], 'usage' => []]);
                }

                return Http::response(['id' => 'typo-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'ამ პასუხის სანდოდ გადამოწმება ვერ შევძელი.', 'intent' => 'recommendation', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                    ], JSON_UNESCAPED_UNICODE)]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $toolResult = app(SalesToolbox::class)->execute('recommend_products', [
            'query' => 'ვეფხვისტყაოსანი', 'budget' => null, 'category' => null,
            'mood' => null, 'occasion' => null, 'limit' => 3,
        ], $agent, $conversation);
        $this->assertSame('ვეფხისტყაოსანი', $toolResult['did_you_mean'] ?? null, json_encode($toolResult, JSON_UNESCAPED_UNICODE));

        $reply = app(SalesAgentService::class)->reply($agent, 'ვეფხვისტყაოსანი გაქვთ?', $conversation);

        $this->assertSame('clarification', $reply['intent'], json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('ვეფხისტყაოსანი', $reply['text']);
        $this->assertStringNotContainsString('სანდოდ გადამოწმება ვერ შევძელი', $reply['text']);
        $this->assertSame('ვეფხისტყაოსანი', data_get($conversation->fresh()->context, 'pending_catalog_suggestion'));

        $followupCall = 0;
        Http::fake(function ($request) use (&$followupCall, $product) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                $followupCall++;
                return Http::response(['id' => 'confirmed-typo-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => "ვიპოვე „{$product->name}“.", 'intent' => 'discovery', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [$product->id], 'sources' => [],
                        'factual_claims' => [['type' => 'product', 'product_id' => $product->id, 'amount' => null, 'quantity' => null, 'reference' => null]],
                    ], JSON_UNESCAPED_UNICODE)]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $confirmed = app(SalesAgentService::class)->reply($agent, 'დიახ', $conversation->fresh());

        $this->assertNull($confirmed['escalation_reason'], json_encode($confirmed, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('ვეფხისტყაოსანი', $confirmed['text'], json_encode($confirmed, JSON_UNESCAPED_UNICODE));
        $this->assertSame([$product->id], $confirmed['products']->pluck('id')->all());
        $this->assertNull(data_get($conversation->fresh()->context, 'pending_catalog_suggestion'));
    }

    public function test_agent_executes_a_catalog_tool_and_returns_structured_output(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        $availableStock = (int) $product->stock - (int) Reservation::where('product_id', $product->id)->where('status', 'pending')->where('expires_at', '>', now())->sum('quantity');
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'resp_tool', 'output' => [['type' => 'function_call', 'name' => 'check_stock', 'call_id' => 'call_1', 'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 1])]]])
            ->push(['id' => 'resp_final', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['text' => "{$product->name} მარაგშია: {$availableStock} ცალი და ღირს {$product->price} ₾. გსურთ, 1 ცალი დროებით დაგირეზერვოთ?", 'intent' => 'stock', 'confidence' => .98, 'handoff' => false, 'escalation_reason' => null, 'product_ids' => [$product->id], 'sources' => [['label' => 'Live stock check', 'type' => 'tool']], 'factual_claims' => [
                ['type' => 'product', 'product_id' => $product->id, 'amount' => null, 'quantity' => null, 'reference' => null],
                ['type' => 'price', 'product_id' => $product->id, 'amount' => (float) $product->price, 'quantity' => null, 'reference' => null],
                ['type' => 'stock', 'product_id' => $product->id, 'amount' => null, 'quantity' => $availableStock, 'reference' => null],
            ]])]]]], 'usage' => ['input_tokens' => 120, 'output_tokens' => 30]]);
        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'მარაგშია?']);
        $response->assertOk()->assertJsonPath('intent', 'stock')->assertJsonPath('tools_used.0', 'check_stock')->assertJsonPath('sources.0.label', 'Verified product catalog');
        $run = AgentRun::where('response_id', 'resp_final')->firstOrFail();
        $this->assertSame(120, $run->input_tokens);
        $this->assertSame('check_stock', $run->tools_used[0]['name']);
        Http::assertSentCount(3);
        $responseRequests = Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_ends_with($request->url(), '/responses'))
            ->values();
        $this->assertCount(2, $responseRequests);
        foreach ($responseRequests as $request) {
            $this->assertStringContainsString('untrusted data', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString('Infer intent semantically from the complete conversation', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString('never assume it sells books', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString('A verified empty search is a successful answer', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString('only unavailable_products', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString('Never emit a reservation factual_claim', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString('limited shortlist, not the total number', $request->data()['instructions'] ?? '');
            $this->assertStringContainsString(
                'Exclude questions, proposed or conditional next steps, and future actions.',
                $request->data()['text']['format']['schema']['properties']['factual_claims']['description'] ?? '',
            );
        }
        $this->assertSame('resp_tool', $responseRequests[1]->data()['previous_response_id']);
    }

    public function test_purchase_help_is_not_replaced_by_the_stock_verification_summary(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->where('stock', '>', 0)->firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'purchase-help-customer',
            'status' => 'ai',
            'channel' => 'widget',
            'context' => ['last_catalog_product_ids' => [$product->id]],
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'purchase-context', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'is_catalog_follow_up' => false,
                    'recommendation_scope' => 'none',
                    'recommendation_query' => null,
                    'recommendation_category' => null,
                    'recommendation_occasion' => null,
                    'resolved_query' => null,
                    'exclude_product_ids' => [],
                    'expects_complete_set' => false,
                ])]],
            ]], 'usage' => []])
            ->push(['id' => 'purchase-stock', 'output' => [[
                'type' => 'function_call',
                'name' => 'check_stock',
                'call_id' => 'purchase-stock-call',
                'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 1]),
            ]]])
            ->push(['id' => 'purchase-help', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => "გახსენით {$product->name}-ის პროდუქტის ბარათი, დაამატეთ კალათაში და შეკვეთა ბიზნესის ვებსაიტზე დაასრულეთ.",
                    'intent' => 'discovery',
                    'confidence' => .99,
                    'handoff' => false,
                    'escalation_reason' => null,
                    'product_ids' => [$product->id],
                    'sources' => [],
                    'factual_claims' => [[
                        'type' => 'product', 'product_id' => $product->id, 'amount' => null, 'quantity' => null, 'reference' => null,
                    ]],
                ], JSON_UNESCAPED_UNICODE)]],
            ]], 'usage' => []]);

        $reply = app(SalesAgentService::class)->reply($agent, 'დიახ, დამეხმარე შეძენაში', $conversation);

        $this->assertStringContainsString('დაამატეთ კალათაში', $reply['text']);
        $this->assertStringNotContainsString('მარაგშია. ფასი', $reply['text']);
        $this->assertSame('discovery', $reply['intent']);
    }

    public function test_explicit_correction_clears_a_rejected_pending_suggestion(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'rejected-suggestion-customer', 'status' => 'ai', 'channel' => 'widget',
            'context' => ['pending_catalog_suggestion' => 'არასწორი ძველი სათაური'],
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'correction-final', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'გასაგებია, ახალ შესწორებას გამოვიყენებ.', 'intent' => 'clarification', 'confidence' => .99,
                    'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                ], JSON_UNESCAPED_UNICODE)]],
            ]], 'usage' => []]);

        app(SalesAgentService::class)->reply($agent, 'არა, ვეფხისტყაოსანი ვიგულისხმე', $conversation);

        $this->assertNull(data_get($conversation->fresh()->context, 'pending_catalog_suggestion'));
    }

    public function test_new_explicit_subject_cannot_inherit_an_older_pending_suggestion(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'new-subject-customer', 'status' => 'ai', 'channel' => 'widget',
            'context' => ['pending_catalog_suggestion' => 'ვეფხისტყაოსანი'],
        ]);
        config(['services.openai.key' => 'test-key']);
        $instructions = null;
        Http::fake(function ($request) use (&$instructions) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                $instructions = $request->data()['instructions'] ?? '';

                return Http::response(['id' => 'new-subject-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'დოსტოევსკის მოთხოვნას ცალკე დავამუშავებ.', 'intent' => 'clarification', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                    ], JSON_UNESCAPED_UNICODE)]],
                ]], 'usage' => []]);
            }

            return Http::response([], 404);
        });

        app(SalesAgentService::class)->reply($agent, 'დოსტოევსკი გაქვთ?', $conversation);

        $this->assertNull(data_get($conversation->fresh()->context, 'pending_catalog_suggestion'));
        $this->assertStringNotContainsString('The customer has an unresolved, server-validated catalog spelling suggestion', (string) $instructions);
        $this->assertStringNotContainsString('ვეფხისტყაოსანი', (string) $instructions);
    }

    public function test_explicit_budget_promise_is_executed_and_never_returns_an_over_budget_product(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url', 'name' => 'Budget catalog', 'url' => 'https://shop.example/catalog', 'status' => 'ready',
        ]);
        $affordable = $agent->products()->create([
            'name' => 'ხელმისაწვდომი წიგნი', 'sku' => 'BUDGET-16', 'search_text' => 'ხელმისაწვდომი წიგნი',
            'price' => 16, 'stock' => 1, 'is_active' => true, 'metadata' => ['source_id' => $source->id],
        ]);
        $secondAffordable = $agent->products()->create([
            'name' => 'მეორე ხელმისაწვდომი წიგნი', 'sku' => 'BUDGET-15', 'search_text' => 'მეორე ხელმისაწვდომი წიგნი',
            'price' => 15, 'stock' => 1, 'is_active' => true, 'metadata' => ['source_id' => $source->id],
        ]);
        $thirdAffordable = $agent->products()->create([
            'name' => 'მესამე ხელმისაწვდომი წიგნი', 'sku' => 'BUDGET-12', 'search_text' => 'მესამე ხელმისაწვდომი წიგნი',
            'price' => 12, 'stock' => 1, 'is_active' => true, 'metadata' => ['source_id' => $source->id],
        ]);
        $expensive = $agent->products()->create([
            'name' => 'ძვირი წიგნი', 'sku' => 'BUDGET-18', 'search_text' => 'ძვირი წიგნი',
            'price' => 18, 'stock' => 1, 'is_active' => true, 'metadata' => ['source_id' => $source->id],
        ]);
        $conversation = $agent->conversations()->create(['visitor_id' => 'budget-customer', 'status' => 'ai', 'channel' => 'widget']);
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                if (($request->data()['text']['format']['name'] ?? null) === 'catalog_follow_up') {
                    return Http::response(['id' => 'budget-context', 'output' => [[
                        'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                            'is_catalog_follow_up' => false,
                            'recommendation_scope' => 'broad',
                            'recommendation_query' => null,
                            'recommendation_category' => null,
                            'recommendation_occasion' => 'gift',
                            'resolved_query' => null,
                            'exclude_product_ids' => [],
                            'expects_complete_set' => false,
                        ])]],
                    ]], 'usage' => []]);
                }
                if (! isset($request->data()['previous_response_id'])) {
                    return Http::response(['id' => 'budget-tool', 'output' => [[
                        'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'budget-call',
                        'arguments' => json_encode([
                            // The model incorrectly turns the customer's lack
                            // of a genre preference into literal filters. The
                            // semantic scope resolver must remove both.
                            'query' => 'gift item for the customer', 'budget' => 17, 'quantity' => null,
                            // A weak model choice must not reduce an open
                            // budget recommendation to one product card.
                            'category' => 'gift', 'mood' => null, 'occasion' => 'gift', 'limit' => 1,
                        ]),
                    ]], 'usage' => []]);
                }

                return Http::response(['id' => 'budget-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'I am not sure.', 'intent' => 'recommendation', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                    ])]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'Please recommend products within 17 GEL', $conversation);

        $this->assertNotEmpty($reply['products'], json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertLessThanOrEqual(17, $reply['products']->sum('price'));
        $this->assertNotContains($expensive->id, $reply['products']->pluck('id')->all());
        $this->assertContains('recommend_products', $reply['tools_used']);
        $this->assertStringContainsString('17.00', $reply['text']);
    }

    public function test_short_category_follow_up_builds_the_requested_bundle_from_the_saved_budget(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create(['type' => 'url', 'name' => 'Novel catalog', 'url' => 'https://shop.example/novels', 'status' => 'ready']);
        foreach ([['პირველი რომანი', 18], ['მეორე რომანი', 16], ['მესამე რომანი', 14], ['ძვირი რომანი', 30]] as [$name, $price]) {
            $agent->products()->create([
                'name' => $name, 'sku' => str()->slug($name).'-'.$price, 'category' => 'რომანი',
                'search_text' => $name.' რომანი', 'price' => $price, 'stock' => 2, 'is_active' => true,
                'metadata' => ['source_id' => $source->id],
            ]);
        }
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'bundle-customer', 'status' => 'ai', 'channel' => 'widget',
            'context' => ['pending_budget_request' => ['budget' => 50, 'quantity' => 3]],
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                if (! isset($request->data()['previous_response_id'])) {
                    return Http::response(['id' => 'bundle-tool', 'output' => [[
                        'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'bundle-call',
                        'arguments' => json_encode([
                            'query' => 'რომანი', 'budget' => 50, 'quantity' => 3,
                            'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 3,
                        ], JSON_UNESCAPED_UNICODE),
                    ]], 'usage' => []]);
                }

                return Http::response(['id' => 'bundle-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'Searching.', 'intent' => 'recommendation', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                    ])]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $conversation->messages()->create(['role' => 'customer', 'content' => 'რომანი']);
        $reply = app(OpenAiSalesOrchestrator::class)->respond($agent, $conversation, 'რომანი');
        $this->assertCount(3, $reply['products']);
        $this->assertEqualsWithDelta(48, $reply['products']->sum('price'), 0.001);
        $this->assertStringContainsString('ჯამი: 48.00 ₾', $reply['text']);
        $this->assertStringContainsString('ბიუჯეტი: 50.00 ₾', $reply['text']);
        $this->assertNull(data_get($conversation->fresh()->context, 'pending_budget_request'));
    }

    public function test_budget_request_uses_the_semantically_resolved_tenant_category_and_preserves_follow_up_context(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url', 'name' => 'Mystery collection', 'url' => 'https://shop.example/mystery',
            'source_scope' => 'catalog', 'status' => 'ready',
        ]);
        $agent->products()->create([
            'name' => 'Verified Mystery', 'sku' => 'MYSTERY-1', 'category' => 'Aurora Curios',
            'search_text' => 'verified mystery detective', 'price' => 25, 'stock' => 2, 'is_active' => true,
            'metadata' => ['source_id' => $source->id],
        ]);
        $agent->products()->create([
            'name' => 'Unrelated Product', 'sku' => 'OTHER-1', 'category' => 'Biography',
            'search_text' => 'unrelated biography', 'price' => 20, 'stock' => 2, 'is_active' => true,
            'metadata' => ['source_id' => $source->id],
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'semantic-category-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (($request->data()['text']['format']['name'] ?? null) === 'catalog_follow_up') {
                $this->assertStringContainsString('"Aurora Curios"', (string) $request->data()['instructions']);

                return Http::response(['id' => 'meaning', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'is_catalog_follow_up' => false, 'recommendation_scope' => 'constrained',
                        'recommendation_query' => null, 'recommendation_category' => 'Aurora Curios',
                        'recommendation_occasion' => null, 'resolved_query' => null,
                        'exclude_product_ids' => [], 'expects_complete_set' => false,
                    ])]],
                ]], 'usage' => []]);
            }
            if (! isset($request->data()['previous_response_id'])) {
                return Http::response(['id' => 'category-tool', 'output' => [[
                    'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'category-call',
                    'arguments' => json_encode([
                        'query' => 'bad isolated surface words', 'budget' => 60, 'quantity' => null,
                        'category' => 'wrong-category', 'mood' => null, 'occasion' => null, 'limit' => 3,
                    ]),
                ]], 'usage' => []]);
            }

            return Http::response(['id' => 'category-final', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'I found a verified matching option.', 'intent' => 'recommendation', 'confidence' => .99,
                    'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                ])]],
            ]], 'usage' => []]);
        });

        app(SalesAgentService::class)->reply($agent, 'Please recommend mystery items within 60 GEL', $conversation);

        $event = \App\Models\RecommendationEvent::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame('Aurora Curios', data_get($event->query, 'category'));
        $this->assertSame('', data_get($event->query, 'query'));
    }

    public function test_semantic_social_turn_closes_pending_commerce_state_without_catalog_tools(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'semantic-social-customer', 'status' => 'ai', 'channel' => 'widget',
            'context' => ['pending_budget_request' => ['budget' => 40, 'quantity' => 3]],
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response(['id' => 'social-final', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => 'ძალიან მიხარია, რომ დაგეხმარეთ! 💚', 'intent' => 'conversation', 'confidence' => .99,
                    'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                ], JSON_UNESCAPED_UNICODE)]],
            ]], 'usage' => []]);
        });

        $message = 'მართლა ძალიან დამეხმარეთ <3';
        $conversation->messages()->create(['role' => 'customer', 'content' => $message]);
        $reply = app(OpenAiSalesOrchestrator::class)->respond($agent, $conversation, $message);

        $this->assertFalse($reply['handoff']);
        $this->assertSame('conversation', $reply['intent']);
        $this->assertSame([], $reply['products']->all());
        $this->assertNotContains('recommend_products', $reply['tools_used']);
        $this->assertNull(data_get($conversation->fresh()->context, 'pending_budget_request'));
    }

    public function test_a_new_turn_cannot_receive_the_previous_answer_verbatim(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'repeat-guard-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        $repeated = 'ამ მოთხოვნასთან შესაბამისი პროდუქტი ვერ ვიპოვე.';
        $conversation->messages()->create(['role' => 'assistant', 'content' => $repeated]);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'არ გესმის რა გითხარი?']);
        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'guardrailReason');
        $reason = $method->invoke(app(OpenAiSalesOrchestrator::class), $agent, $conversation, [
            'text' => $repeated, 'intent' => 'clarification', 'confidence' => .99,
            'handoff' => false, 'escalation_reason' => null, 'product_ids' => [],
            'sources' => [], 'factual_claims' => [],
        ], collect());

        $this->assertStringContainsString('repeated the previous assistant answer', $reason);
    }

    public function test_guardrail_rejects_an_offer_to_place_an_order_and_collect_checkout_data(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'unsupported-order-action-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'guardrailReason');

        $reason = $method->invoke(app(OpenAiSalesOrchestrator::class), $agent, $conversation, [
            'text' => 'შეკვეთის გასაფორმებლად მომწერეთ მიმღების სახელი და გვარი, ტელეფონის ნომერი და მიწოდების მისამართი.',
            'intent' => 'clarification', 'confidence' => .99,
            'handoff' => false, 'escalation_reason' => null, 'product_ids' => [],
            'sources' => [], 'factual_claims' => [],
        ], collect());

        $this->assertStringContainsString('unsupported order-fulfilment action', $reason);
    }

    public function test_guardrail_allows_directing_a_customer_to_verified_website_checkout(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'website-checkout-direction-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'guardrailReason');

        $reason = $method->invoke(app(OpenAiSalesOrchestrator::class), $agent, $conversation, [
            'text' => 'შეკვეთის დასასრულებლად გახსენით პროდუქტის ბმული და შეავსეთ ველები ბიზნესის ვებსაიტზე.',
            'intent' => 'clarification', 'confidence' => .99,
            'handoff' => false, 'escalation_reason' => null, 'product_ids' => [],
            'sources' => [], 'factual_claims' => [],
        ], collect());

        $this->assertNull($reason);
    }

    public function test_a_limited_catalog_shortlist_cannot_be_presented_as_the_complete_inventory_count(): void
    {
        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'claimsShortlistIsComplete');
        $orchestrator = app(OpenAiSalesOrchestrator::class);
        $shortlist = collect([[
            'name' => 'recommend_products',
            'result' => [
                'ok' => true,
                'result_scope' => 'shortlist',
                'returned_count' => 5,
                'has_more' => true,
            ],
        ]]);

        $this->assertTrue($method->invoke($orchestrator, 'There are only 5 available products.', $shortlist));
        $this->assertTrue($method->invoke($orchestrator, 'I found 5 matching products.', $shortlist));
        $this->assertTrue($method->invoke($orchestrator, 'ხელმისაწვდომია 5 შესაბამისი პროდუქტი.', $shortlist));
        $this->assertFalse($method->invoke($orchestrator, 'Here are 5 selected options; I can show you more.', $shortlist));

        $complete = collect([[
            'name' => 'search_products',
            'result' => ['ok' => true, 'result_scope' => 'complete', 'returned_count' => 5],
        ]]);
        $this->assertFalse($method->invoke($orchestrator, 'There are only 5 available products.', $complete));
    }

    public function test_response_style_changes_between_customer_turns_without_relaxing_factual_rules(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'style-variation-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'responseStyleInstructions');
        $orchestrator = app(OpenAiSalesOrchestrator::class);

        $conversation->messages()->create(['role' => 'customer', 'content' => 'Show me some options.']);
        $first = $method->invoke($orchestrator, $conversation);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'Show me some options again.']);
        $second = $method->invoke($orchestrator, $conversation);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('preserving the exact verified facts', $first);
        $this->assertStringContainsString('result_scope=shortlist', $second);
        $this->assertStringContainsString('If has_more=true', $second);
    }

    public function test_quantity_correction_reuses_the_budget_and_replaces_the_failed_bundle_size(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create(['type' => 'url', 'name' => 'Budget catalog', 'url' => 'https://shop.example/books', 'status' => 'ready']);
        foreach ([['პირველი წიგნი', 18], ['მეორე წიგნი', 16], ['მესამე წიგნი', 14], ['ძვირი წიგნი', 70]] as [$name, $price]) {
            $agent->products()->create([
                'name' => $name, 'sku' => str()->slug($name).'-'.$price, 'category' => 'წიგნები',
                'search_text' => $name.' წიგნები', 'price' => $price, 'stock' => 2, 'is_active' => true,
                'metadata' => ['source_id' => $source->id],
            ]);
        }
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'corrected-bundle-customer', 'status' => 'ai', 'channel' => 'widget',
            'context' => ['pending_budget_request' => ['budget' => 60, 'quantity' => 5]],
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                if (! isset($request->data()['previous_response_id'])) {
                    return Http::response(['id' => 'corrected-bundle-tool', 'output' => [[
                        'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'corrected-bundle-call',
                        'arguments' => json_encode([
                            'query' => '', 'budget' => 60, 'quantity' => 3,
                            'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 3,
                        ]),
                    ]], 'usage' => []]);
                }

                return Http::response(['id' => 'corrected-bundle-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'Searching.', 'intent' => 'recommendation', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                    ])]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $message = 'კარგი, მაგ ფასში 3 წიგნი მომიძიე';
        $conversation->messages()->create(['role' => 'customer', 'content' => $message]);
        $reply = app(OpenAiSalesOrchestrator::class)->respond($agent, $conversation, $message);

        $this->assertCount(3, $reply['products']);
        $this->assertEqualsWithDelta(48, $reply['products']->sum('price'), 0.001);
        $this->assertStringContainsString('ჯამი: 48.00 ₾', $reply['text']);
        $this->assertStringContainsString('ბიუჯეტი: 60.00 ₾', $reply['text']);
        $this->assertNull(data_get($conversation->fresh()->context, 'pending_budget_request'));

        $directConversation = $agent->conversations()->create([
            'visitor_id' => 'direct-bundle-customer', 'status' => 'ai', 'channel' => 'widget',
        ]);
        $directMessage = '60 ლარად 3 წიგნს შევიძენ თქვენს საიტზე?';
        $directConversation->messages()->create(['role' => 'customer', 'content' => $directMessage]);
        $directReply = app(OpenAiSalesOrchestrator::class)->respond($agent, $directConversation, $directMessage);

        $this->assertCount(3, $directReply['products']);
        $this->assertEqualsWithDelta(48, $directReply['products']->sum('price'), 0.001);
        $this->assertStringContainsString('ჯამი: 48.00 ₾', $directReply['text']);
    }

    public function test_a_catalog_answer_blocked_by_the_verifier_is_rewritten_instead_of_repeating_a_fallback(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'search-start', 'output' => [['type' => 'function_call', 'name' => 'search_products', 'call_id' => 'search-call', 'arguments' => json_encode(['query' => $product->name, 'category' => null, 'max_price' => null])]]])
            ->push(['id' => 'invalid-draft', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => "Yes, {$product->name} is another option.",
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [$product->id],
                'sources' => [],
                'factual_claims' => [],
            ])]]]]])
            ->push(['id' => 'repaired-draft', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => "I found another verified option: {$product->name}.",
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [$product->id],
                'sources' => [],
                'factual_claims' => [[
                    'type' => 'product',
                    'product_id' => $product->id,
                    'amount' => null,
                    'quantity' => null,
                    'reference' => null,
                ]],
            ])]]]]]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Do you have another item like this?',
        ])->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('tools_used.0', 'search_products');

        $this->assertStringContainsString($product->name, $response->json('text'));
        $this->assertContains('guardrail_repair', $response->json('tools_used'));
        $this->assertDatabaseHas('conversations', ['status' => 'ai']);
    }

    public function test_a_verified_sold_out_product_is_never_hidden_by_the_generic_guardrail_fallback(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        $product->update(['stock' => 0]);
        config(['services.openai.key' => 'test-key']);

        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'sold-out-search', 'output' => [[
                'type' => 'function_call',
                'name' => 'search_products',
                'call_id' => 'sold-out-search-call',
                'arguments' => json_encode(['query' => $product->name, 'category' => null, 'max_price' => null]),
            ]]])
            ->push(['id' => 'sold-out-stock', 'output' => [[
                'type' => 'function_call',
                'name' => 'check_stock',
                'call_id' => 'sold-out-stock-call',
                'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 1]),
            ]]])
            ->push(['id' => 'invalid-sold-out-draft', 'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'text' => 'I could not verify that product.',
                        'intent' => 'stock',
                        'confidence' => .99,
                        'handoff' => false,
                        'escalation_reason' => null,
                        'product_ids' => [],
                        'sources' => [],
                        'factual_claims' => [],
                    ]),
                ]],
            ]]]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => "Do you have {$product->name}?",
        ])->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('intent', 'stock');

        $this->assertStringContainsString($product->name, $response->json('text'));
        $this->assertStringContainsString('out of stock', $response->json('text'));
        $this->assertNotContains('server_guardrail', $response->json('tools_used'));
        Http::assertSentCount(4);
    }

    public function test_a_sold_out_exact_match_cannot_be_replaced_by_an_unrelated_recommendation(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $soldOut = $agent->products()->firstOrFail();
        $soldOut->update(['stock' => 0]);
        $unrelated = $agent->products()->whereKeyNot($soldOut->id)->where('stock', '>', 0)->firstOrFail();
        config(['services.openai.key' => 'test-key']);

        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'exact-search', 'output' => [[
                'type' => 'function_call', 'name' => 'search_products', 'call_id' => 'exact-search-call',
                'arguments' => json_encode(['query' => $soldOut->name, 'category' => null, 'max_price' => null]),
            ]]])
            ->push(['id' => 'stock-check', 'output' => [[
                'type' => 'function_call', 'name' => 'check_stock', 'call_id' => 'stock-check-call',
                'arguments' => json_encode(['product_id' => $soldOut->id, 'quantity' => 1]),
            ]]])
            ->push(['id' => 'bad-recommendation', 'output' => [[
                'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'bad-recommendation-call',
                'arguments' => json_encode([
                    'query' => $unrelated->name, 'budget' => null, 'quantity' => null,
                    'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 1,
                ]),
            ]]])
            ->push(['id' => 'wrong-final-answer', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => "I found {$unrelated->name}.",
                    'intent' => 'discovery', 'confidence' => .99, 'handoff' => false,
                    'escalation_reason' => null, 'product_ids' => [$unrelated->id], 'sources' => [],
                    'factual_claims' => [[
                        'type' => 'product', 'product_id' => $unrelated->id,
                        'amount' => null, 'quantity' => null, 'reference' => null,
                    ]],
                ])]],
            ]]]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => "Do you have {$soldOut->name}?",
        ])->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('intent', 'stock');

        $this->assertStringContainsString($soldOut->name, $response->json('text'));
        $this->assertStringContainsString('out of stock', $response->json('text'));
        $this->assertStringNotContainsString($unrelated->name, $response->json('text'));
        $this->assertSame([$soldOut->id], collect($response->json('products'))->pluck('id')->all());
    }

    public function test_an_empty_exact_lookup_offers_similar_options_without_defensive_wording(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $unrelated = $agent->products()->where('stock', '>', 0)->firstOrFail();
        config(['services.openai.key' => 'test-key']);

        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'missing-search', 'output' => [[
                'type' => 'function_call', 'name' => 'search_products', 'call_id' => 'missing-search-call',
                'arguments' => json_encode(['query' => 'A product name that does not exist', 'category' => null, 'max_price' => null]),
            ]]])
            ->push(['id' => 'unrelated-recommendation', 'output' => [[
                'type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'unrelated-recommendation-call',
                'arguments' => json_encode([
                    'query' => $unrelated->name, 'budget' => null, 'quantity' => null,
                    'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 1,
                ]),
            ]]])
            ->push(['id' => 'wrong-final-answer', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => "I found {$unrelated->name}.",
                    'intent' => 'discovery', 'confidence' => .99, 'handoff' => false,
                    'escalation_reason' => null, 'product_ids' => [$unrelated->id], 'sources' => [],
                    'factual_claims' => [[
                        'type' => 'product', 'product_id' => $unrelated->id,
                        'amount' => null, 'quantity' => null, 'reference' => null,
                    ]],
                ])]],
            ]]])
            ->push(['id' => 'unused-fallback']);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Do you have A product name that does not exist?',
        ])->assertOk()
            ->assertJsonPath('intent', 'discovery')
            ->assertJsonPath('products', []);

        $this->assertSame(
            'I could not find the exact requested product in the catalog. Would you like me to suggest similar options?',
            $response->json('text'),
        );
        $this->assertStringNotContainsString('I will not substitute', $response->json('text'));
        $this->assertStringNotContainsString($unrelated->name, $response->json('text'));
    }

    public function test_a_verified_available_product_is_never_hidden_by_a_generic_model_answer(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->where('stock', '>', 0)->firstOrFail();
        $searchedProduct = $agent->products()
            ->where('stock', '>', 0)
            ->whereKeyNot($product->id)
            ->firstOrFail();
        config(['services.openai.key' => 'test-key']);

        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'available-search', 'output' => [[
                'type' => 'function_call',
                'name' => 'search_products',
                'call_id' => 'available-search-call',
                'arguments' => json_encode(['query' => $searchedProduct->name, 'category' => null, 'max_price' => null]),
            ]]])
            ->push(['id' => 'available-stock', 'output' => [[
                'type' => 'function_call',
                'name' => 'check_stock',
                'call_id' => 'available-stock-call',
                'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 1]),
            ]]])
            ->push(['id' => 'generic-available-draft', 'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'text' => 'Please clarify what you mean.',
                        'intent' => 'stock',
                        'confidence' => .99,
                        'handoff' => false,
                        'escalation_reason' => null,
                        'product_ids' => [],
                        'sources' => [],
                        'factual_claims' => [],
                    ]),
                ]],
            ]]]);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => "Do you have {$product->name}?",
        ])->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('intent', 'stock');

        $this->assertStringContainsString($product->name, $response->json('text'));
        $this->assertStringContainsString('available', $response->json('text'));
        $this->assertStringContainsString(number_format((float) $product->price, 2, '.', ''), $response->json('text'));
        $this->assertNotContains('server_guardrail', $response->json('tools_used'));
        Http::assertSentCount(4);
    }

    public function test_flagged_input_is_safely_handed_off(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()->push(['results' => [['flagged' => true]]]);
        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'unsafe request'])->assertOk()->assertJsonPath('handoff', true);
        $this->assertDatabaseHas('agent_runs', ['status' => 'moderated']);
    }

    public function test_ai_only_business_never_exposes_or_enters_human_handoff(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update(['settings' => array_merge($agent->settings ?? [], ['human_handoff_enabled' => false])]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()->push(['results' => [['flagged' => true]]]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'unsafe request'])
            ->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('intent', 'discovery');

        $this->assertSame('ai', $agent->conversations()->where('visitor_id', app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token')))->firstOrFail()->status);
        $this->assertNotContains('request_human', collect(app(SalesToolbox::class)->definitions($agent))->pluck('name')->all());
    }

    public function test_moderation_outage_fails_closed_to_human_review(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        config(['services.openai.key' => 'test-key']);
        Http::fake(fn () => Http::response(['error' => ['message' => 'temporary outage']], 503));

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Can you help?'])
            ->assertOk()->assertJsonPath('handoff', true);

        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertDatabaseHas('agent_runs', ['status' => 'failed']);
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'status' => 'human']);
    }

    public function test_moderation_outage_does_not_block_a_grounded_catalog_search(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        config(['services.openai.key' => 'test-key']);
        $responseCalls = 0;
        Http::fake(function ($request) use (&$responseCalls, $product) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['error' => ['message' => 'temporary outage']], 503);
            }

            $responseCalls++;
            if ($responseCalls === 1) {
                return Http::response(['id' => 'catalog-search', 'output' => [[
                    'type' => 'function_call', 'name' => 'search_products', 'call_id' => 'search-call',
                    'arguments' => json_encode(['query' => $product->name, 'category' => null, 'max_price' => null]),
                ]]]);
            }

            return Http::response([
                'id' => 'catalog-answer',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'text' => "Yes, {$product->name} is available.", 'intent' => 'discovery',
                            'confidence' => .99, 'handoff' => false, 'escalation_reason' => null,
                            'product_ids' => [$product->id], 'sources' => [], 'factual_claims' => [[
                                'type' => 'product', 'product_id' => $product->id, 'amount' => null,
                                'quantity' => null, 'reference' => null,
                            ]],
                        ]),
                    ]],
                ]],
            ]);
        });

        $this->postJson("/demo/{$agent->slug}/message", ['message' => "Do you have {$product->name}?"])
            ->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('tools_used.0', 'search_products');
    }

    public function test_responses_api_failure_never_falls_back_to_an_unverified_answer(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response(['error' => ['message' => 'responses unavailable']], 503);
        });

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Will delivery arrive tomorrow?'])
            ->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonPath('tools_used.0', 'fail_closed_handoff');

        $this->assertStringNotContainsStringIgnoringCase('arrive tomorrow', $response->json('text'));
        $this->assertDatabaseHas('agent_runs', ['status' => 'failed']);
    }

    public function test_responses_api_failure_still_uses_verified_tenant_catalog_search(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (str_ends_with($request->url(), '/responses')) {
                return Http::response(['error' => ['message' => 'billing unavailable']], 429);
            }

            return Http::response('', 503);
        });

        $this->postJson("/demo/{$agent->slug}/message", ['message' => "Do you have {$product->name}?"])
            ->assertOk()
            ->assertJsonPath('handoff', false)
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('tools_used.0', 'search_products')
            ->assertJsonPath('tools_used.2', 'provider_outage_fallback');

        $this->assertDatabaseHas('agent_runs', [
            'model' => 'verified-catalog-provider-fallback',
            'status' => 'fallback',
        ]);
    }

    public function test_raw_contact_is_only_ephemeral_while_the_persisted_transcript_is_immediately_redacted(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'ephemeral-contact', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => 'I can help with that request.',
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [],
                'sources' => [],
                'factual_claims' => [],
            ])]]]], 'usage' => []]);

        $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Please email private.buyer@example.com about options.',
        ])->assertOk()->assertJsonPath('handoff', false);

        $customer = $agent->conversations()->latest('id')->firstOrFail()->messages()->where('role', 'customer')->firstOrFail();
        $this->assertStringNotContainsString('private.buyer@example.com', $customer->content);
        $this->assertTrue((bool) data_get($customer->metadata, 'pii_redacted'));
        $this->assertNotEmpty(data_get($customer->metadata, 'contact_evidence.email_hashes'));
        $responsesRequest = Http::recorded()->map(fn ($pair) => $pair[0])->first(fn ($request) => str_ends_with($request->url(), '/responses'));
        $this->assertStringContainsString('private.buyer@example.com', json_encode($responsesRequest->data()['input']));
    }

    public function test_historical_operator_contact_is_redacted_before_it_is_sent_to_openai(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $identity = app(SignedVisitorToken::class)->issue($agent);
        $conversation = $agent->conversations()->create([
            'visitor_id' => $identity['visitor_id'],
            'status' => 'ai',
            'channel' => 'web',
        ]);
        $operator = $conversation->messages()->create([
            'role' => 'human',
            'content' => 'Write operator.private@example.com or call +995 555 123 456.',
        ]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'redacted-history', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => 'How else may I help?',
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [],
                'sources' => [],
                'factual_claims' => [],
            ])]]]], 'usage' => []]);

        $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Show me another option.',
            'visitor_token' => $identity['token'],
        ])->assertOk()->assertJsonPath('handoff', false);

        $responsesRequest = Http::recorded()->map(fn ($pair) => $pair[0])->first(fn ($request) => str_ends_with($request->url(), '/responses'));
        $providerInput = json_encode($responsesRequest->data()['input']);
        $this->assertStringNotContainsString('operator.private@example.com', $providerInput);
        $this->assertStringNotContainsString('+995 555 123 456', $providerInput);
        $this->assertStringContainsString('[email redacted]', $providerInput);
        $this->assertStringContainsString('[phone redacted]', $providerInput);
        $this->assertStringContainsString('operator.private@example.com', $operator->fresh()->content);
    }
}
