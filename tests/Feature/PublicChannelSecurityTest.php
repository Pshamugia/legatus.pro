<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicChannelSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null]);
    }

    public function test_raw_visitor_id_cannot_hijack_an_existing_conversation(): void
    {
        $agent = $this->agent();
        $identity = app(SignedVisitorToken::class)->issue($agent);
        $owned = $agent->conversations()->create([
            'visitor_id' => $identity['visitor_id'],
            'status' => 'human',
        ]);
        $owned->messages()->create(['role' => 'customer', 'content' => 'Private conversation']);

        $response = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Trying a raw identifier',
            'visitor_id' => $identity['visitor_id'],
        ])->assertOk()->assertJsonStructure(['visitor_token']);

        $newVisitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertNotSame($identity['visitor_id'], $newVisitorId);
        $this->assertSame(1, $owned->messages()->count());
        $this->assertDatabaseHas('conversations', ['visitor_id' => $newVisitorId]);
    }

    public function test_tampered_or_cross_agent_token_is_rejected(): void
    {
        $agent = $this->agent();
        $otherAgent = $this->agent('other-agent');
        $identity = app(SignedVisitorToken::class)->issue($agent);
        $tampered = substr($identity['token'], 0, -1).(str_ends_with($identity['token'], 'A') ? 'B' : 'A');

        $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Hello',
            'visitor_token' => $tampered,
        ])->assertUnauthorized();
        $this->withHeader('X-Legatus-Visitor-Token', $identity['token'])
            ->getJson("/demo/{$otherAgent->slug}/history")
            ->assertUnauthorized();
    }

    public function test_expired_visitor_token_is_rejected(): void
    {
        $agent = $this->agent();
        $identity = app(SignedVisitorToken::class)->issue($agent);

        $this->travel(91)->days();

        $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Hello',
            'visitor_token' => $identity['token'],
        ])->assertUnauthorized();
    }

    public function test_operator_reply_is_restored_and_cursor_delivers_it_once(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $identity = app(SignedVisitorToken::class)->issue($agent);
        $conversation = $agent->conversations()->create([
            'visitor_id' => $identity['visitor_id'],
            'status' => 'human',
            'handoff_reason' => 'Manager approval',
        ]);

        $customer = $this->postJson("/demo/{$agent->slug}/message", [
            'message' => 'Any update?',
            'visitor_token' => $identity['token'],
        ])->assertOk()->assertJsonPath('handoff', true);

        $this->actingAs(User::where('email', 'demo@legatus.ai')->firstOrFail())
            ->post("/app/inbox/{$conversation->id}/reply", ['message' => 'Your approved offer is ready.'])
            ->assertRedirect();

        $first = $this->withHeader('X-Legatus-Visitor-Token', $identity['token'])
            ->getJson("/demo/{$agent->slug}/history?after=".$customer->json('cursor'))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.role', 'human')
            ->assertJsonPath('messages.0.text', 'Your approved offer is ready.');

        $this->withHeader('X-Legatus-Visitor-Token', $identity['token'])
            ->getJson("/demo/{$agent->slug}/history?after=".$first->json('cursor'))
            ->assertOk()
            ->assertJsonCount(0, 'messages');

        $this->withHeader('X-Legatus-Visitor-Token', $identity['token'])
            ->getJson("/demo/{$agent->slug}/history?after=0")
            ->assertOk()
            ->assertJsonCount(2, 'messages');
    }

    public function test_request_id_returns_the_original_response_after_cache_loss(): void
    {
        $agent = $this->agent();
        $requestId = (string) Str::uuid();
        $payload = ['message' => 'Hello', 'request_id' => $requestId];

        $first = $this->postJson("/demo/{$agent->slug}/message", $payload)->assertOk();
        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $first->json('visitor_token'));
        $conversation = $agent->conversations()->where('visitor_id', $visitorId)->firstOrFail();
        $conversation->update(['status' => 'closed']);
        Cache::flush();

        $second = $this->postJson("/demo/{$agent->slug}/message", $payload + [
            'visitor_token' => $first->json('visitor_token'),
        ])->assertOk();

        $second->assertExactJson($first->json());
        $this->assertSame(2, $conversation->messages()->count());
        $customer = $conversation->messages()->where('role', 'customer')->firstOrFail();
        $this->assertSame($requestId, $customer->request_id);
        $this->assertIsArray($customer->metadata['response_payload'] ?? null);
        $this->assertArrayNotHasKey('visitor_token', $customer->metadata['response_payload']);
    }

    public function test_same_ip_and_request_id_do_not_collide_across_visitors(): void
    {
        $agent = $this->agent();
        $payload = ['message' => 'Hello', 'request_id' => (string) Str::uuid()];

        $first = $this->postJson("/demo/{$agent->slug}/message", $payload)->assertOk();
        $second = $this->postJson("/demo/{$agent->slug}/message", $payload)->assertOk();

        $this->assertNotSame($first->json('visitor_token'), $second->json('visitor_token'));
        $this->assertNotSame($first->json('customer_message_id'), $second->json('customer_message_id'));
        $this->assertSame(2, $agent->conversations()->count());
        $this->assertSame(2, $agent->conversations()->withCount([
            'messages' => fn ($query) => $query->where('request_id', $payload['request_id']),
        ])->get()->sum('messages_count'));
    }

    public function test_feedback_requires_the_token_that_owns_the_message(): void
    {
        $agent = $this->agent();
        $owner = app(SignedVisitorToken::class)->issue($agent);
        $stranger = app(SignedVisitorToken::class)->issue($agent);
        $conversation = $agent->conversations()->create(['visitor_id' => $owner['visitor_id'], 'status' => 'ai']);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Verified answer']);

        $this->postJson("/demo/{$agent->slug}/messages/{$message->public_id}/feedback", [
            'feedback' => 'helpful',
            'visitor_token' => $stranger['token'],
        ])->assertNotFound();
        $this->postJson("/demo/{$agent->slug}/messages/{$message->public_id}/feedback", [
            'feedback' => 'helpful',
            'visitor_id' => $owner['visitor_id'],
        ])->assertUnauthorized();
        $this->postJson("/demo/{$agent->slug}/messages/{$message->public_id}/feedback", [
            'feedback' => 'helpful',
            'visitor_token' => $owner['token'],
        ])->assertOk();
    }

    public function test_inactive_agents_have_no_public_chat_or_widget_surface(): void
    {
        $agent = $this->agent();
        $agent->update(['is_active' => false]);

        $this->get("/demo/{$agent->slug}")->assertNotFound();
        $this->postJson("/demo/{$agent->slug}/message", ['message' => 'Hello'])->assertNotFound();
        $this->get("/widget/{$agent->slug}")->assertNotFound();
        $this->get("/widget/{$agent->slug}.js")->assertNotFound();
    }

    private function agent(string $slug = 'public-agent'): Agent
    {
        return Agent::create([
            'name' => 'Legatus',
            'slug' => $slug,
            'business_name' => 'Public Store',
            'is_active' => true,
        ]);
    }
}
