<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Reservation;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiOrchestrationTest extends TestCase
{
    use RefreshDatabase;

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
        }
        $this->assertSame('resp_tool', $responseRequests[1]->data()['previous_response_id']);
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
