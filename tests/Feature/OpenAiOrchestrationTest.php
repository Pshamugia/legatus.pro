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
                        'text' => 'Summer detective fiction is usually light and entertaining.',
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

        $this->assertStringContainsString('ბიზნესის ვებსაიტზე', $reply['text']);
        $this->assertStringNotContainsString('light and entertaining', $reply['text']);
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
                return Http::response(['id' => 'budget-final', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'text' => 'I am not sure.', 'intent' => 'recommendation', 'confidence' => .99,
                        'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => [], 'factual_claims' => [],
                    ])]],
                ]], 'usage' => []]);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'დაახლოებით 17 ლარის ფარგლებში რა წიგნებს მირჩევ?', $conversation);

        $this->assertSame([$affordable->id], $reply['products']->pluck('id')->all());
        $this->assertNotContains($expensive->id, $reply['products']->pluck('id')->all());
        $this->assertContains('recommend_products', $reply['tools_used']);
        $this->assertStringContainsString('ბიუჯეტის ფარგლებში', $reply['text']);
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
            ->assertJsonPath('tools_used.0', 'search_products')
            ->assertJsonPath('tools_used.1', 'guardrail_repair');

        $this->assertStringContainsString($product->name, $response->json('text'));
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
