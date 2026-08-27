<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ShoppingProfile;
use App\Services\SalesAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HybridModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_sol_only_behavior_is_preserved_until_the_hybrid_canary_is_enabled(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'hybrid-disabled-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        config([
            'services.openai.key' => 'test-key',
            'services.openai.model' => 'gpt-5.6-sol',
            'services.openai.primary_model' => 'gpt-5.6-luna',
            'services.openai.fallback_model' => 'gpt-5.6-sol',
            'services.openai.hybrid_enabled' => false,
            'services.openai.hybrid_rollout_percent' => 100,
            'services.openai.fallback_enabled' => true,
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response($this->strictResponse('sol-only', 'You are welcome.'));
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        $this->assertSame('conversation', $reply['intent']);
        $responseRequests = $this->responseRequests();
        $this->assertCount(1, $responseRequests);
        $this->assertSame('gpt-5.6-sol', $responseRequests[0]->data()['model']);
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'model' => 'gpt-5.6-sol',
            'route' => 'primary',
            'fallback_reason' => null,
        ]);
    }

    public function test_luna_handles_an_ordinary_turn_without_calling_sol_and_records_model_usage(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'luna-primary-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response($this->strictResponse('luna-success', 'You are welcome.', [
                'input_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 25, 'cache_write_tokens' => 5],
                'output_tokens' => 12,
                'output_tokens_details' => ['reasoning_tokens' => 3],
            ]));
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        $this->assertSame('conversation', $reply['intent']);
        $this->assertNotContains('model_fallback', $reply['tools_used']);
        $responseRequests = $this->responseRequests();
        $this->assertCount(1, $responseRequests);
        $this->assertSame('gpt-5.6-luna', $responseRequests[0]->data()['model']);

        $run = AgentRun::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame('gpt-5.6-luna', $run->model);
        $this->assertSame('primary', $run->route);
        $this->assertNull($run->fallback_reason);
        $this->assertSame(100, $run->input_tokens);
        $this->assertSame(12, $run->output_tokens);
        $this->assertSame([[
            'model' => 'gpt-5.6-luna',
            'requests' => 1,
            'input_tokens' => 100,
            'cached_input_tokens' => 25,
            'cache_write_tokens' => 5,
            'output_tokens' => 12,
            'reasoning_tokens' => 3,
            'stages' => ['responses.initial'],
        ]], $run->model_usage);
    }

    public function test_canary_assignment_is_pinned_for_the_conversation_and_disable_returns_to_sol(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'pinned-canary-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response($this->strictResponse('stable-route', 'You are welcome.'));
        });

        app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        config(['services.openai.hybrid_rollout_percent' => 1]);
        app(SalesAgentService::class)->reply($agent, 'Can you explain that further?', $conversation->fresh());

        config(['services.openai.hybrid_enabled' => false]);
        app(SalesAgentService::class)->reply($agent, 'What else can you do?', $conversation->fresh());

        $this->assertSame(
            ['gpt-5.6-luna', 'gpt-5.6-luna', 'gpt-5.6-sol'],
            $this->responseRequests()->map(fn ($request) => $request->data()['model'])->all(),
        );
        $this->assertSame('gpt-5.6-luna', data_get($conversation->fresh()->context, 'ai_model_route.selected_model'));
    }

    public function test_full_rollout_moves_a_conversation_previously_pinned_to_sol_onto_luna(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'legacy-sol-canary-customer',
            'status' => 'ai',
            'channel' => 'widget',
            'context' => [
                'ai_model_route' => [
                    'selected_model' => 'gpt-5.6-sol',
                    'established_model' => 'gpt-5.6-sol',
                    'candidate_model' => 'gpt-5.6-luna',
                ],
            ],
        ]);
        $this->enableHybridCanary();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response($this->strictResponse('migrated-to-luna', 'You are welcome.'));
        });

        app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        $responseRequests = $this->responseRequests();
        $this->assertCount(1, $responseRequests);
        $this->assertSame('gpt-5.6-luna', $responseRequests[0]->data()['model']);
        $this->assertSame('gpt-5.6-luna', data_get($conversation->fresh()->context, 'ai_model_route.selected_model'));
        $this->assertSame(100, data_get($conversation->fresh()->context, 'ai_model_route.rollout_percent'));
    }

    public function test_invalid_luna_output_calls_sol_once_on_a_fresh_chain(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'luna-fallback-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            if ($request->data()['model'] === 'gpt-5.6-luna') {
                return Http::response([
                    'id' => 'luna-invalid',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => '{}']],
                    ]],
                    'usage' => ['input_tokens' => 40, 'output_tokens' => 5],
                ]);
            }

            return Http::response($this->strictResponse('sol-recovery', 'You are welcome.', [
                'input_tokens' => 70,
                'output_tokens' => 9,
            ]));
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        $this->assertSame('conversation', $reply['intent']);
        $this->assertContains('model_fallback', $reply['tools_used']);
        $responseRequests = $this->responseRequests();
        $this->assertCount(2, $responseRequests);
        $this->assertSame(['gpt-5.6-luna', 'gpt-5.6-sol'], $responseRequests->map(fn ($request) => $request->data()['model'])->all());
        $fallbackRequest = $responseRequests[1]->data();
        $this->assertArrayNotHasKey('previous_response_id', $fallbackRequest);
        $this->assertArrayNotHasKey('tools', $fallbackRequest);
        $this->assertStringContainsString('structured_output_invalid', json_encode($fallbackRequest['input']));

        $run = AgentRun::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $this->assertSame('gpt-5.6-sol', $run->model);
        $this->assertSame('primary_to_fallback', $run->route);
        $this->assertSame('structured_output_invalid', $run->fallback_reason);
        $this->assertSame(110, $run->input_tokens);
        $this->assertSame(14, $run->output_tokens);
        $usage = collect($run->model_usage)->keyBy('model');
        $this->assertSame(1, $usage['gpt-5.6-luna']['requests']);
        $this->assertSame(1, $usage['gpt-5.6-sol']['requests']);
        $this->assertSame(['responses.fallback_structured_output_invalid'], $usage['gpt-5.6-sol']['stages']);
    }

    public function test_recoverable_luna_transport_failure_uses_one_fresh_sol_chain(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'luna-transport-fallback-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            if ($request->data()['model'] === 'gpt-5.6-luna') {
                return Http::response([
                    'error' => ['code' => 'model_not_available', 'message' => 'Temporary model outage.'],
                ], 503);
            }

            return Http::response($this->strictResponse('sol-after-outage', 'You are welcome.'));
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        $this->assertSame('conversation', $reply['intent']);
        $this->assertContains('model_fallback', $reply['tools_used']);
        $requests = $this->responseRequests();
        $this->assertGreaterThanOrEqual(2, $requests->where(fn ($request) => $request->data()['model'] === 'gpt-5.6-luna')->count());
        $this->assertSame('gpt-5.6-sol', $requests->last()->data()['model']);
        $this->assertArrayNotHasKey('previous_response_id', $requests->last()->data());
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'model' => 'gpt-5.6-sol',
            'route' => 'primary_to_fallback',
            'fallback_reason' => 'primary_transport_failure',
        ]);
    }

    public function test_shared_rate_limit_does_not_waste_a_sol_fallback_call(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'shared-rate-limit-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            return Http::response([
                'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Account rate limit.'],
            ], 429);
        });

        app(SalesAgentService::class)->reply($agent, 'What can you help me with?', $conversation);

        $models = $this->responseRequests()->map(fn ($request) => $request->data()['model'])->unique()->values()->all();
        $this->assertSame(['gpt-5.6-luna'], $models);
        $this->assertDatabaseMissing('agent_runs', [
            'conversation_id' => $conversation->id,
            'route' => 'primary_to_fallback',
        ]);
    }

    public function test_guardrail_rejection_uses_verified_evidence_in_one_fresh_sol_call(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $product = $agent->products()->firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'guardrail-fallback-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push([
                'id' => 'luna-search',
                'output' => [[
                    'type' => 'function_call',
                    'name' => 'search_products',
                    'call_id' => 'search-call',
                    'arguments' => json_encode(['query' => $product->name, 'category' => null, 'max_price' => null]),
                ]],
                'usage' => [],
            ])
            ->push($this->strictResponse('luna-rejected', "Yes, {$product->name} is available.", [] , [$product->id]))
            ->push($this->strictResponse('sol-grounded', "I found {$product->name} in the verified catalog.", [], [$product->id], [[
                'type' => 'product',
                'product_id' => $product->id,
                'amount' => null,
                'quantity' => null,
                'reference' => null,
            ]]));

        $reply = app(SalesAgentService::class)->reply($agent, "Do you have {$product->name}?", $conversation);

        $this->assertContains('search_products', $reply['tools_used']);
        $this->assertContains('model_fallback', $reply['tools_used']);
        $this->assertContains('guardrail_repair', $reply['tools_used']);
        $responseRequests = $this->responseRequests();
        $this->assertSame(['gpt-5.6-luna', 'gpt-5.6-luna', 'gpt-5.6-sol'], $responseRequests->map(fn ($request) => $request->data()['model'])->all());
        $fallbackRequest = $responseRequests[2]->data();
        $this->assertArrayNotHasKey('previous_response_id', $fallbackRequest);
        $this->assertArrayNotHasKey('tools', $fallbackRequest);
        $this->assertStringContainsString((string) $product->id, json_encode($fallbackRequest['input']));
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'model' => 'gpt-5.6-sol',
            'route' => 'primary_to_fallback',
            'fallback_reason' => 'guardrail_rejected',
        ]);
    }

    public function test_verified_empty_catalog_result_does_not_escalate_to_sol(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'verified-empty-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        $responseCall = 0;
        Http::fake(function ($request) use (&$responseCall) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }

            if (str_ends_with($request->url(), '/responses')) {
                $responseCall++;
                if ($responseCall === 1) {
                    return Http::response([
                        'id' => 'luna-empty-search',
                        'output' => [[
                            'type' => 'function_call',
                            'name' => 'recommend_products',
                            'call_id' => 'recommend-empty',
                            'arguments' => json_encode([
                                'query' => 'summer detective',
                                'budget' => null,
                                'category' => null,
                                'mood' => null,
                                'occasion' => null,
                                'limit' => 3,
                            ]),
                        ]],
                        'usage' => [],
                    ]);
                }

                return Http::response($this->strictResponse(
                    'luna-empty-final',
                    'No matching item was found in this business catalog.',
                ));
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $reply = app(SalesAgentService::class)->reply($agent, 'Recommend a summer detective.', $conversation);

        $this->assertNotContains('model_fallback', $reply['tools_used']);
        $this->assertSame([], $reply['products']->all());
        $models = $this->responseRequests()->map(fn ($request) => $request->data()['model'])->all();
        $this->assertNotEmpty($models);
        $this->assertSame([], array_values(array_filter($models, fn ($model) => $model === 'gpt-5.6-sol')));
    }

    public function test_state_changing_tool_is_discarded_from_luna_and_executed_once_by_sol(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'mutation-routing-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $this->enableHybridCanary();
        $arguments = [
            'budget' => 50,
            'occasion' => null,
            'mood' => null,
            'likes' => ['historical fiction'],
            'dislikes' => [],
            'recipient' => null,
        ];
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push([
                'id' => 'luna-mutation-proposal',
                'output' => [[
                    'type' => 'function_call',
                    'name' => 'save_shopping_preferences',
                    'call_id' => 'luna-preference-call',
                    'arguments' => json_encode($arguments),
                ]],
                'usage' => [],
            ])
            ->push([
                'id' => 'sol-mutation-authorized',
                'output' => [[
                    'type' => 'function_call',
                    'name' => 'save_shopping_preferences',
                    'call_id' => 'sol-preference-call',
                    'arguments' => json_encode($arguments),
                ]],
                'usage' => [],
            ])
            ->push($this->strictResponse('sol-mutation-final', 'I will use those preferences for this conversation.'));

        $reply = app(SalesAgentService::class)->reply(
            $agent,
            'Remember that my budget is 50 and I like historical fiction.',
            $conversation,
        );

        $this->assertContains('model_fallback', $reply['tools_used']);
        $this->assertContains('save_shopping_preferences', $reply['tools_used']);
        $this->assertSame(1, ShoppingProfile::where('conversation_id', $conversation->id)->count());
        $responseRequests = $this->responseRequests();
        $this->assertSame(['gpt-5.6-luna', 'gpt-5.6-sol', 'gpt-5.6-sol'], $responseRequests->map(fn ($request) => $request->data()['model'])->all());
        $this->assertArrayNotHasKey('previous_response_id', $responseRequests[1]->data());
        $this->assertSame('sol-mutation-authorized', $responseRequests[2]->data()['previous_response_id']);
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'model' => 'gpt-5.6-sol',
            'route' => 'primary_to_fallback',
            'fallback_reason' => 'state_changing_action',
        ]);
    }

    public function test_empty_exact_bundle_resolution_is_rechecked_by_sol_and_broadened_to_the_verified_entity_family(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update([
            'settings' => array_merge($agent->settings ?? [], [
                'catalog_search_url' => 'https://chapter.test/search?q={query}',
            ]),
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'semantic-dead-end-customer',
            'status' => 'ai',
            'channel' => 'widget',
        ]);
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'Do you have the complete twelve-volume Aurelius Testauthor set?',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'I could not find the complete set. Would individual volumes work?',
        ]);
        $products = collect(range(1, 15))->map(fn (int $volume) => $agent->products()->create([
            'name' => "Aurelius work volume {$volume}",
            'search_text' => "Aurelius Testauthor work volume {$volume}",
            'price' => 10 + $volume,
            'stock' => 1,
            'is_active' => true,
            'metadata' => ['author' => 'Aurelius Testauthor'],
        ]));
        $this->enableHybridCanary();

        Http::fake(function ($request) use ($products) {
            if (str_ends_with($request->url(), '/moderations')) {
                return Http::response(['results' => [['flagged' => false]]]);
            }
            if (data_get($request->data(), 'text.format.name') === 'catalog_follow_up') {
                $isSol = $request->data()['model'] === 'gpt-5.6-sol';

                return Http::response([
                    'id' => $isSol ? 'sol-semantic-resolution' : 'luna-semantic-resolution',
                    'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'is_delivery_request' => false,
                        'is_catalog_follow_up' => true,
                        'recommendation_scope' => 'none',
                        'recommendation_query' => null,
                        'recommendation_category' => null,
                        'recommendation_occasion' => null,
                        'resolved_query' => $isSol ? 'Aurelius Testauthor' : 'Aurelius Testauthor complete twelve volume set',
                        'catalog_match_scope' => $isSol ? 'entity_family' : 'exact_identity',
                        'exclude_product_ids' => [],
                        'expects_complete_set' => true,
                    ])]]]],
                    'usage' => [],
                ]);
            }

            return Http::response($this->strictResponse(
                'semantic-family-final',
                'I found individually available works by this author.',
                [],
                [],
                [],
            ));
        });

        $reply = app(SalesAgentService::class)->reply(
            $agent,
            'Individual volumes work too. How many are available?',
            $conversation,
        );

        $this->assertContains('semantic_resolution_fallback', $reply['tools_used']);
        $run = AgentRun::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
        $search = collect($run->tools_used)->firstWhere('name', 'search_products');
        $this->assertFalse($search['arguments']['_identity_match']);
        $this->assertTrue($search['arguments']['_return_all_matches']);
        $this->assertCount(15, $search['result']['products']);
        $this->assertFalse($reply['handoff']);
        $this->assertCount(15, $reply['products']);
        $models = $this->responseRequests()->map(fn ($request) => $request->data()['model'])->all();
        $this->assertSame(['gpt-5.6-luna', 'gpt-5.6-sol'], array_slice($models, 0, 2));
        $this->assertSame('gpt-5.6-luna', $models[array_key_last($models)]);
    }

    private function enableHybridCanary(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.openai.model' => 'gpt-5.6-sol',
            'services.openai.primary_model' => 'gpt-5.6-luna',
            'services.openai.fallback_model' => 'gpt-5.6-sol',
            'services.openai.hybrid_enabled' => true,
            'services.openai.hybrid_rollout_percent' => 100,
            'services.openai.fallback_enabled' => true,
            'services.openai.fallback_reasoning_effort' => 'low',
        ]);
    }

    private function strictResponse(
        string $id,
        string $text,
        array $usage = [],
        array $productIds = [],
        array $factualClaims = [],
    ): array
    {
        return [
            'id' => $id,
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode([
                    'text' => $text,
                    'intent' => 'conversation',
                    'confidence' => .99,
                    'handoff' => false,
                    'escalation_reason' => null,
                    'product_ids' => $productIds,
                    'sources' => [],
                    'factual_claims' => $factualClaims,
                ])]],
            ]],
            'usage' => $usage,
        ];
    }

    private function responseRequests()
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_ends_with($request->url(), '/responses'))
            ->values();
    }
}
