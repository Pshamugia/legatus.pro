<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Notifications\PlatformReliabilityAlert;
use App\Services\ResponseReliabilityGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResponseReliabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_contract_repairs_an_empty_invalid_reply_before_delivery(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'guard-empty', 'channel' => 'widget', 'status' => 'ai',
        ]);
        Log::spy();

        $reply = app(ResponseReliabilityGuard::class)->enforce($agent, $conversation, 'დამეხმარე არჩევაში', [
            'text' => null,
            'intent' => 'invented',
            'confidence' => 7,
            'handoff' => false,
            'products' => [],
            'sources' => 'invalid',
            'tools_used' => [],
        ]);

        $this->assertSame('clarification', $reply['intent']);
        $this->assertSame(1, substr_count($reply['text'], '?'));
        $this->assertSame(1.0, $reply['confidence']);
        $this->assertSame([], $reply['products']);
        $this->assertSame([], $reply['sources']);
        $this->assertContains('empty_text', $reply['reliability_issues']);
        $this->assertContains('invalid_intent', $reply['reliability_issues']);
        $this->assertContains('reliability_guard', $reply['tools_used']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_final_contract_removes_cross_tenant_and_unverified_product_cards(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'guard-products', 'channel' => 'instagram', 'status' => 'ai',
        ]);
        $other = Organization::create(['name' => 'Other reliability tenant', 'slug' => 'other-reliability']);
        $otherAgent = $other->agents()->create([
            'name' => 'Other assistant', 'slug' => 'other-reliability-agent',
            'business_name' => 'Other business', 'is_active' => true,
        ]);
        $foreign = $otherAgent->products()->create([
            'name' => 'Foreign product', 'sku' => 'FOREIGN-1', 'price' => 10,
            'stock' => 5, 'is_active' => true,
        ]);

        $reply = app(ResponseReliabilityGuard::class)->enforce($agent, $conversation, 'მირჩიე', [
            'text' => 'ეს ვარიანტი გამოგადგებათ.',
            'intent' => 'recommendation',
            'confidence' => .9,
            'handoff' => false,
            'products' => [$foreign],
            'sources' => [],
            'tools_used' => ['recommend_products'],
        ]);

        $this->assertSame([], $reply['products']);
        $this->assertContains('cross_tenant_or_inactive_product', $reply['reliability_issues']);
        $this->assertContains('reliability_guard', $reply['tools_used']);
    }

    public function test_clarification_contract_never_delivers_multiple_questions_or_products(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'guard-question', 'channel' => 'facebook', 'status' => 'ai',
        ]);
        $product = $agent->customerProducts()->firstOrFail();

        $reply = app(ResponseReliabilityGuard::class)->enforce($agent, $conversation, 'დამეხმარე არჩევაში', [
            'text' => 'ვისთვის გინდათ? რა ბიუჯეტი გაქვთ?',
            'intent' => 'clarification',
            'confidence' => .9,
            'handoff' => false,
            'products' => [$product],
            'sources' => [],
            'tools_used' => ['search_products'],
        ]);

        $this->assertSame(1, substr_count($reply['text'], '?'));
        $this->assertSame([], $reply['products']);
        $this->assertContains('clarification_with_products', $reply['reliability_issues']);
        $this->assertContains('invalid_clarification_question_count', $reply['reliability_issues']);
    }

    public function test_reliability_command_is_safe_repeatable_and_successful(): void
    {
        $this->seed();

        $this->artisan('legatus:reliability-check')->assertSuccessful();
        $this->artisan('legatus:reliability-check')->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['customer_name' => 'Synthetic reliability check']);
    }

    public function test_live_reliability_command_fails_fast_without_an_api_key(): void
    {
        $this->seed();
        config(['services.openai.key' => null]);

        $this->artisan('legatus:reliability-check --live')
            ->expectsOutputToContain('OPENAI_API_KEY is not configured.')
            ->assertFailed();
    }

    public function test_reliability_failure_alerts_super_admin_once_per_health_transition(): void
    {
        $this->seed();
        config([
            'services.openai.key' => null,
            'legatus.super_admin_email' => 'operator@example.com',
        ]);
        Cache::forget('legatus:reliability:last:live');
        Notification::fake();

        $this->artisan('legatus:reliability-check --live')->assertFailed();
        $this->artisan('legatus:reliability-check --live')->assertFailed();

        Notification::assertSentOnDemandTimes(PlatformReliabilityAlert::class, 1);
        Notification::assertSentOnDemand(
            PlatformReliabilityAlert::class,
            fn (PlatformReliabilityAlert $notification, array $channels, object $notifiable): bool => ! $notification->healthy
                && $notification->mode === 'live'
                && $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'operator@example.com',
        );
    }
}
