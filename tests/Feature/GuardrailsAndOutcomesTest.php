<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Lead;
use App\Models\RecommendationEvent;
use App\Models\ShoppingProfile;
use App\Services\SalesToolbox;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuardrailsAndOutcomesTest extends TestCase
{
    use RefreshDatabase;

    private function salesContext(): array
    {
        $agent = Agent::create(['name' => 'Legatus', 'slug' => 'guardrail-agent', 'business_name' => 'Guardrail Store', 'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10]]);
        $product = $agent->products()->create(['name' => 'Verified Product', 'sku' => 'SAFE-1', 'price' => 100, 'stock' => 20]);
        $conversation = $agent->conversations()->create(['visitor_id' => 'guardrail-customer', 'status' => 'ai']);

        return [$agent, $product, $conversation];
    }

    public function test_discount_above_limit_is_blocked_and_handed_off(): void
    {
        [$agent, $product, $conversation] = $this->salesContext();
        $result = app(SalesToolbox::class)->execute('build_offer', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'discount_percent' => 18], $agent, $conversation);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['approval_required']);
        $this->assertSame(200.0, $result['total']);
        $this->assertSame('human', $conversation->fresh()->status);
        $this->assertNotNull($conversation->fresh()->suggested_reply);
        $this->assertSame('human_handoff', $conversation->fresh()->outcome);
    }

    public function test_allowed_discount_is_calculated_server_side_and_remains_non_binding(): void
    {
        [$agent, $product, $conversation] = $this->salesContext();
        $result = app(SalesToolbox::class)->execute('build_offer', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'discount_percent' => 10], $agent, $conversation);

        $this->assertTrue($result['ok']);
        $this->assertSame(180.0, $result['total']);
        $this->assertFalse($result['binding']);
        $this->assertTrue($result['requires_customer_confirmation']);
        $this->assertSame('offer_created', $conversation->fresh()->outcome);
    }

    public function test_contact_details_are_not_saved_without_explicit_consent(): void
    {
        [$agent, , $conversation] = $this->salesContext();
        $result = app(SalesToolbox::class)->execute('create_lead', ['name' => 'Customer', 'email' => 'customer@example.com', 'phone' => null, 'intent' => 'wholesale', 'notes' => null, 'consent' => false], $agent, $conversation);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_expired_lead_contact_data_is_anonymized(): void
    {
        [$agent, , $conversation] = $this->salesContext();
        $conversation->update(['customer_name' => 'Old Lead', 'context' => ['handoff' => ['email' => 'old@example.com']], 'handoff_summary' => 'Call +995555000000']);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'Email old@example.com or call +995555000000']);
        AgentRun::create(['agent_id' => $agent->id, 'conversation_id' => $conversation->id, 'model' => 'test', 'status' => 'completed', 'tools_used' => [['name' => 'create_lead', 'arguments' => ['name' => 'Old Lead', 'email' => 'old@example.com', 'phone' => '+995555000000']]]]);
        ShoppingProfile::create(['conversation_id' => $conversation->id, 'preferences' => ['recipient' => 'Old Lead']]);
        Lead::create(['agent_id' => $agent->id, 'conversation_id' => $conversation->id, 'name' => 'Old Lead', 'email' => 'old@example.com', 'phone' => '+995555000000', 'consent_at' => now()->subDays(100), 'retention_until' => now()->subDay(), 'status' => 'qualified']);

        $this->artisan('legatus:purge-expired-data')->assertSuccessful();

        $this->assertDatabaseHas('leads', ['agent_id' => $agent->id, 'name' => null, 'email' => null, 'phone' => null, 'status' => 'qualified']);
        $this->assertNull($conversation->fresh()->customer_name);
        $this->assertStringNotContainsString('old@example.com', json_encode($conversation->fresh()->context));
        $this->assertStringNotContainsString('+995555000000', $conversation->fresh()->handoff_summary);
        $this->assertStringNotContainsString('old@example.com', $conversation->messages()->firstOrFail()->content);
        $this->assertStringNotContainsString('old@example.com', json_encode(AgentRun::firstOrFail()->tools_used));
        $this->assertDatabaseMissing('shopping_profiles', ['conversation_id' => $conversation->id]);
    }

    public function test_expired_lead_pii_is_redacted_from_recommendation_payloads(): void
    {
        [$agent, , $conversation] = $this->salesContext();
        $recommendation = RecommendationEvent::create([
            'conversation_id' => $conversation->id,
            'query' => [
                'request' => 'Recommend a gift and email buyer@example.com',
                'contact' => 'buyer@example.com',
            ],
            'ranked_products' => [[
                'name' => 'Verified Product',
                'reason' => 'Call +995 555 123 456 to confirm the recipient.',
            ]],
        ]);
        Lead::create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'name' => 'Expired Buyer',
            'email' => 'buyer@example.com',
            'retention_until' => now()->subMinute(),
            'status' => 'qualified',
        ]);

        $this->artisan('legatus:purge-expired-data')->assertSuccessful();

        $payload = json_encode($recommendation->fresh()->only(['query', 'ranked_products']));
        $this->assertStringNotContainsString('buyer@example.com', $payload);
        $this->assertStringNotContainsString('+995 555 123 456', $payload);
        $this->assertStringContainsString('[redacted]', $payload);
        $this->assertDatabaseHas('recommendation_events', ['id' => $recommendation->id]);
    }

    public function test_failed_cleanup_rolls_back_and_keeps_the_lead_eligible_for_retry(): void
    {
        [$agent, , $conversation] = $this->salesContext();
        $conversation->update([
            'customer_name' => 'Retry Buyer',
            'context' => ['email' => 'retry@example.com'],
            'handoff_summary' => 'Call +995 555 987 654',
        ]);
        $message = $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'Contact retry@example.com',
            'metadata' => ['contact' => '+995 555 987 654'],
        ]);
        $run = AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'model' => 'test',
            'status' => 'completed',
            'tools_used' => [['name' => 'create_lead', 'arguments' => ['email' => 'retry@example.com']]],
        ]);
        $profile = ShoppingProfile::create([
            'conversation_id' => $conversation->id,
            'preferences' => ['recipient' => 'Retry Buyer'],
        ]);
        $recommendation = RecommendationEvent::create([
            'conversation_id' => $conversation->id,
            'query' => ['request' => 'Email retry@example.com'],
            'ranked_products' => [['reason' => 'Call +995 555 987 654']],
        ]);
        $lead = Lead::create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'name' => 'Retry Buyer',
            'email' => 'retry@example.com',
            'retention_until' => now()->subMinute(),
            'status' => 'qualified',
        ]);

        $failOnce = true;
        $eventName = 'eloquent.updating: '.get_class($conversation);
        Event::listen($eventName, function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Simulated cleanup interruption.');
            }
        });

        $this->artisan('legatus:purge-expired-data')->assertFailed();

        $this->assertNotNull($lead->fresh()->retention_until);
        $this->assertSame('retry@example.com', $lead->fresh()->email);
        $this->assertStringContainsString('retry@example.com', $message->fresh()->content);
        $this->assertStringContainsString('retry@example.com', json_encode($run->fresh()->tools_used));
        $this->assertStringContainsString('retry@example.com', json_encode($recommendation->fresh()->query));
        $this->assertDatabaseHas('shopping_profiles', ['id' => $profile->id]);

        $this->artisan('legatus:purge-expired-data')->assertSuccessful();
        Event::forget($eventName);

        $this->assertNull($lead->fresh()->retention_until);
        $this->assertNull($lead->fresh()->email);
        $this->assertStringNotContainsString('retry@example.com', $message->fresh()->content);
        $this->assertStringNotContainsString('retry@example.com', json_encode($run->fresh()->tools_used));
        $this->assertStringNotContainsString('retry@example.com', json_encode($recommendation->fresh()->query));
        $this->assertDatabaseMissing('shopping_profiles', ['id' => $profile->id]);
    }

    public function test_low_confidence_is_forced_to_human_by_the_server(): void
    {
        [$agent] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'low-confidence', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['text' => 'Maybe.', 'intent' => 'discovery', 'confidence' => .40, 'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => []])]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4]]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Can you decide?']);

        $response->assertOk()->assertJsonPath('handoff', true)->assertJsonPath('tools_used.0', 'server_guardrail');
        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'status' => 'human', 'outcome' => 'human_handoff']);
    }

    public function test_factual_price_answer_without_a_tool_is_blocked(): void
    {
        [$agent] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'ungrounded-price', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['text' => 'It costs 5 GEL.', 'intent' => 'price', 'confidence' => .99, 'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => []])]]]], 'usage' => []]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'What is the price?']);

        $response->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonPath('escalation_reason', 'Required verification tool was not called for the price intent.')
            ->assertJsonMissing(['text' => 'It costs 5 GEL.']);
        $this->assertDatabaseHas('agent_runs', ['response_id' => 'ungrounded-price']);
    }

    public function test_reservation_factual_claim_without_a_successful_hold_is_blocked(): void
    {
        [$agent] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'ungrounded-reservation', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => 'Done.',
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [],
                'sources' => [],
                'factual_claims' => [[
                    'type' => 'reservation',
                    'product_id' => null,
                    'amount' => null,
                    'quantity' => 1,
                    'reference' => 'reservation created',
                ]],
            ])]]]], 'usage' => []]);

        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Please reserve one.'])
            ->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonPath('escalation_reason', 'A reservation factual claim was not backed by a successful hold.')
            ->assertJsonMissing(['text' => 'Done.']);
    }

    public function test_empty_knowledge_search_cannot_authorize_an_unsupported_policy_claim(): void
    {
        [$agent] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'policy-search', 'output' => [['type' => 'function_call', 'name' => 'search_knowledge', 'call_id' => 'policy-call', 'arguments' => json_encode(['query' => 'refund return window'])]]])
            ->push(['data' => [['index' => 0, 'embedding' => [1.0, 0.0]]]])
            ->push(['id' => 'unsupported-policy', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => 'Our return policy allows refunds for 30 days.',
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [],
                'sources' => [],
                'factual_claims' => [[
                    'type' => 'policy',
                    'product_id' => null,
                    'amount' => null,
                    'quantity' => null,
                    'reference' => 'return policy',
                ]],
            ])]]]], 'usage' => []]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'What is your refund policy?'])
            ->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonPath('escalation_reason', 'Verification tool search_knowledge did not complete successfully.');

        $response->assertJsonMissing(['text' => 'Our return policy allows refunds for 30 days.']);
    }

    public function test_price_that_disagrees_with_the_tool_result_is_blocked(): void
    {
        [$agent, $product] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'verified-price-tool', 'output' => [['type' => 'function_call', 'name' => 'check_stock', 'call_id' => 'price-call', 'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 1])]]])
            ->push(['id' => 'wrong-price', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['text' => 'It costs 75 GEL.', 'intent' => 'price', 'confidence' => .99, 'handoff' => false, 'escalation_reason' => null, 'product_ids' => [$product->id], 'sources' => []])]]]], 'usage' => []]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'What is the price?'])
            ->assertOk()->assertJsonPath('handoff', true)->assertJsonPath('tools_used.1', 'server_guardrail')
            ->assertJsonMissing(['text' => 'It costs 75 GEL.']);

        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'status' => 'human']);
    }

    public function test_generic_intent_cannot_bypass_stock_tool_requirement(): void
    {
        [$agent, $product] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'stock-intent-bypass', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => "{$product->name} stock is 99 units.",
                'intent' => 'discovery',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [],
                'sources' => [],
                'factual_claims' => [['type' => 'stock', 'product_id' => $product->id, 'amount' => null, 'quantity' => 99, 'reference' => null]],
            ])]]]], 'usage' => []]);

        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Tell me about it.'])
            ->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonPath('escalation_reason', 'A stock claim requires a successful live stock check regardless of the model-selected intent.')
            ->assertJsonMissing(['text' => "{$product->name} stock is 99 units."]);
    }

    public function test_prefix_currency_claim_must_match_the_bound_product_fact(): void
    {
        [$agent, $product] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'prefix-price-tool', 'output' => [['type' => 'function_call', 'name' => 'check_stock', 'call_id' => 'prefix-price-call', 'arguments' => json_encode(['product_id' => $product->id, 'quantity' => 1])]]])
            ->push(['id' => 'prefix-price-final', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'text' => "{$product->name} costs ₾75.",
                'intent' => 'price',
                'confidence' => .99,
                'handoff' => false,
                'escalation_reason' => null,
                'product_ids' => [$product->id],
                'sources' => [],
                'factual_claims' => [
                    ['type' => 'product', 'product_id' => $product->id, 'amount' => null, 'quantity' => null, 'reference' => null],
                    ['type' => 'price', 'product_id' => $product->id, 'amount' => (float) $product->price, 'quantity' => null, 'reference' => null],
                ],
            ])]]]], 'usage' => []]);

        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'What is the price?'])
            ->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonMissing(['text' => "{$product->name} costs ₾75."]);
    }

    public function test_failed_tool_does_not_authorize_a_stock_claim(): void
    {
        [$agent] = $this->salesContext();
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'failed-stock-tool', 'output' => [['type' => 'function_call', 'name' => 'check_stock', 'call_id' => 'missing-product', 'arguments' => json_encode(['product_id' => 999999, 'quantity' => 1])]]])
            ->push(['id' => 'failed-stock-final', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['text' => 'Stock is 99 units.', 'intent' => 'stock', 'confidence' => .99, 'handoff' => false, 'escalation_reason' => null, 'product_ids' => [], 'sources' => []])]]]], 'usage' => []]);

        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'How many are available?'])
            ->assertOk()
            ->assertJsonPath('handoff', true)
            ->assertJsonPath('escalation_reason', 'Verification tool check_stock did not complete successfully.')
            ->assertJsonMissing(['text' => 'Stock is 99 units.']);
    }

    public function test_model_cannot_surface_a_product_outside_successful_tool_results(): void
    {
        [$agent, $verified] = $this->salesContext();
        $unverified = $agent->products()->create(['name' => 'Unavailable Product', 'sku' => 'NO-1', 'price' => 999, 'stock' => 0]);
        config(['services.openai.key' => 'test-key']);
        Http::fakeSequence()
            ->push(['results' => [['flagged' => false]]])
            ->push(['id' => 'recommend-tool', 'output' => [['type' => 'function_call', 'name' => 'recommend_products', 'call_id' => 'recommend-call', 'arguments' => json_encode(['query' => 'safe', 'budget' => 150, 'category' => null, 'mood' => null, 'occasion' => null, 'limit' => 3])]]])
            ->push(['id' => 'recommend-final', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['text' => "Choose {$unverified->name}.", 'intent' => 'recommendation', 'confidence' => .99, 'handoff' => false, 'escalation_reason' => null, 'product_ids' => [$unverified->id], 'sources' => []])]]]], 'usage' => []]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Recommend something safe.'])
            ->assertOk()->assertJsonPath('handoff', true)->assertJsonCount(0, 'products');

        $this->assertSame($verified->id, $agent->products()->where('stock', '>', 0)->firstOrFail()->id);
        $this->assertStringContainsString('not returned by a successful verification tool', $response->json('escalation_reason'));
    }

    public function test_ai_does_not_reply_after_human_ownership(): void
    {
        [$agent, , $conversation] = $this->salesContext();
        $identity = app(SignedVisitorToken::class)->issue($agent);
        $conversation->update(['visitor_id' => $identity['visitor_id']]);
        $conversation->update(['status' => 'human', 'handoff_reason' => 'Manager approval']);

        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Any update?', 'visitor_token' => $identity['token']])
            ->assertOk()->assertJsonPath('tools_used.0', 'human_queue');

        $this->assertSame(1, $conversation->messages()->where('role', 'customer')->count());
        $this->assertSame(0, AgentRun::where('conversation_id', $conversation->id)->count());
    }

    public function test_contact_details_are_redacted_from_the_transcript(): void
    {
        [$agent] = $this->salesContext();
        config(['services.openai.key' => null]);

        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Human please, email me at buyer@example.com or +995 555 123 456'])->assertOk();

        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $conversation = $agent->conversations()->where('visitor_id', $visitorId)->firstOrFail();
        $customerMessage = $conversation->messages()->where('role', 'customer')->firstOrFail();
        $this->assertStringContainsString('[email redacted]', $customerMessage->content);
        $this->assertStringContainsString('[phone redacted]', $customerMessage->content);
        $this->assertTrue($customerMessage->metadata['pii_redacted']);
    }
}
