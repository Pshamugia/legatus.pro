<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorInboxTest extends TestCase
{
    use RefreshDatabase;

    private function conversation()
    {
        $this->seed();
        $this->actingAs(User::first());
        $agent = Agent::firstOrFail();
        $c = $agent->conversations()->create(['visitor_id' => 'handoff', 'customer_name' => 'Ana', 'channel' => 'instagram', 'status' => 'human', 'priority' => 'high', 'handoff_reason' => 'Discount approval', 'handoff_summary' => 'Customer wants ten copies and a custom discount.']);
        $c->messages()->create(['role' => 'customer', 'content' => 'Can I get a discount?']);

        return $c;
    }

    public function test_operator_can_take_over_reply_and_return_to_ai(): void
    {
        $c = $this->conversation();
        $this->get('/app/inbox?conversation='.$c->id)->assertOk()->assertSee('Discount approval');
        $this->post("/app/inbox/{$c->id}/take-over")->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $c->id, 'assigned_to' => 'Demo Owner']);
        $this->post("/app/inbox/{$c->id}/reply", ['message' => 'I can prepare a custom offer.'])->assertRedirect();
        $this->assertDatabaseHas('messages', ['conversation_id' => $c->id, 'role' => 'human']);
        $this->post("/app/inbox/{$c->id}/release")->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $c->id, 'status' => 'ai', 'assigned_to' => null]);
    }

    public function test_operator_can_close_a_conversation(): void
    {
        $c = $this->conversation();
        $this->post("/app/inbox/{$c->id}/close")->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $c->id, 'status' => 'closed']);
    }
}
