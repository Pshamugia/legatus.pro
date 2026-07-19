<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\RecommendationEvent;
use App\Models\Reservation;
use App\Models\User;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataAndFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_the_complete_judging_story_idempotently(): void
    {
        $this->seed();
        $this->artisan('legatus:bootstrap-demo-tenant')->assertSuccessful();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();

        $this->assertSame(12, $agent->products()->count());
        $this->assertSame(3, $agent->knowledgeSources()->where('status', 'ready')->count());
        $this->assertSame(6, $agent->conversations()->where('visitor_id', 'like', 'demo-%')->count());
        $this->assertSame(1, $agent->conversations()->where('outcome', 'qualified_lead')->count());
        $this->assertSame(1, $agent->conversations()->where('outcome', 'human_handoff')->count());
        $this->assertDatabaseHas('users', ['email' => 'demo@legatus.ai']);

        $reservationConversation = $agent->conversations()->where('visitor_id', 'demo-nino')->firstOrFail();
        $this->assertSame('ai', $reservationConversation->status);
        $this->assertSame('pending_reservation', $reservationConversation->outcome);
        $this->assertSame('27.50', $reservationConversation->outcome_value);
        $this->assertNull($reservationConversation->resolved_at);
        $this->assertTrue(Reservation::where('conversation_id', $reservationConversation->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists());
    }

    public function test_demo_story_shows_three_grounded_options_and_time_safe_delivery(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();

        $shoppingReply = $agent->conversations()->where('visitor_id', 'demo-mariam')->firstOrFail()
            ->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('Piranesi', $shoppingReply->content);
        $this->assertStringContainsString('Before the Coffee Gets Cold', $shoppingReply->content);
        $this->assertStringContainsString('Klara and the Sun', $shoppingReply->content);
        $this->assertStringContainsString('რომელი მიმართულება', $shoppingReply->content);

        $ranked = RecommendationEvent::where('conversation_id', $shoppingReply->conversation_id)
            ->firstOrFail()->ranked_products;
        $this->assertCount(3, $ranked);
        $this->assertTrue(collect($ranked)->every(fn ($product) => $product['price'] <= 30 && $product['stock'] > 0));

        $deliveryReply = $agent->conversations()->where('visitor_id', 'demo-ana')->firstOrFail()
            ->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('მომდევნო სამუშაო დღე', $deliveryReply->content);
        $this->assertStringNotContainsString('ხვალაა', $deliveryReply->content);

        $giorgiReply = $agent->conversations()->where('visitor_id', 'demo-giorgi')->firstOrFail()
            ->messages()->where('role', 'assistant')->latest('id')->firstOrFail();
        $labels = collect($giorgiReply->metadata['sources'])->pluck('label');
        $this->assertTrue($labels->contains('Verified product catalog'));
        $this->assertTrue($labels->contains('Wholesale policy · minimum quantity'));
    }

    public function test_customer_can_rate_only_an_assistant_message_from_the_same_agent(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $identity = app(SignedVisitorToken::class)->issue($agent);
        $conversation = $agent->conversations()->create(['visitor_id' => $identity['visitor_id'], 'status' => 'ai']);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Verified answer.']);

        $this->postJson("/demo/{$agent->slug}/messages/{$message->public_id}/feedback", ['feedback' => 'helpful', 'visitor_token' => $identity['token']])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'feedback' => 'helpful']);
    }

    public function test_real_dashboard_uses_seeded_outcomes(): void
    {
        $this->seed();
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();

        $this->actingAs($user)->get('/app')->assertOk()->assertSee('Qualified leads')->assertSee('Value influenced')->assertSee('272.50');
    }
}
