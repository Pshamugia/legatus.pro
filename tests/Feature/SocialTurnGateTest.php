<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\ConversationEngine;
use App\Services\SocialTurnGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialTurnGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_receipt_and_thank_you_is_recorded_without_an_outbound_reply(): void
    {
        $agent = Agent::create(['name' => 'Store', 'slug' => 'silent-closure', 'business_name' => 'Store']);
        config(['services.openai.key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response($this->decision(true))]);

        $engine = app(ConversationEngine::class);
        $reply = $engine->handle($agent, 'მადლობა ყურადღებისთვის. ❤️ წიგნი აიღეს.', 'facebook', 'customer-1', 'closing-message-1');

        $this->assertTrue($reply['silent']);
        $this->assertNull($reply['text']);
        $this->assertArrayNotHasKey('message_id', $reply);
        $this->assertSame(1, $agent->conversations()->firstOrFail()->messages()->where('role', 'customer')->count());
        $this->assertSame(0, $agent->conversations()->firstOrFail()->messages()->where('role', 'assistant')->count());
        $this->assertSame($reply, $engine->handle($agent, 'მადლობა ყურადღებისთვის. ❤️ წიგნი აიღეს.', 'facebook', 'customer-1', 'closing-message-1'));
        Http::assertSentCount(1);
    }

    public function test_thanks_with_a_new_request_is_not_silenced(): void
    {
        $agent = Agent::create(['name' => 'Store', 'slug' => 'request-after-thanks', 'business_name' => 'Store']);
        $conversation = $agent->conversations()->create(['visitor_id' => 'customer-2', 'status' => 'ai', 'channel' => 'facebook']);
        config(['services.openai.key' => 'test-key']);
        Http::fake(['api.openai.com/*' => Http::response($this->decision(false))]);

        $this->assertFalse(app(SocialTurnGate::class)->shouldStaySilent('მადლობა. კიდევ ერთი ნივთის მოძებნაში დამეხმარებით?', $conversation));
        Http::assertSentCount(1);
    }

    public function test_social_gate_does_not_run_for_an_ordinary_customer_request(): void
    {
        $agent = Agent::create(['name' => 'Store', 'slug' => 'ordinary-request', 'business_name' => 'Store']);
        $conversation = $agent->conversations()->create(['visitor_id' => 'customer-3', 'status' => 'ai', 'channel' => 'facebook']);
        config(['services.openai.key' => 'test-key']);
        Http::preventStrayRequests();

        $this->assertFalse(app(SocialTurnGate::class)->shouldStaySilent('სხვა ვარიანტი მაჩვენეთ', $conversation));
        Http::assertNothingSent();
    }

    private function decision(bool $silent): array
    {
        return ['output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => json_encode(['silent' => $silent])]],
        ]]];
    }
}
