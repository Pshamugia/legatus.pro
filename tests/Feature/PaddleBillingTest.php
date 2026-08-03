<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PaddleBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('paddle.billing_enforced', true);
        config()->set('paddle.environment', 'sandbox');
        config()->set('paddle.client_token', 'test_client_token');
        config()->set('paddle.webhook_secret', 'pdl_ntfset_secret');
        config()->set('paddle.prices', [
            'monthly' => 'pri_monthly',
            'six_months' => 'pri_six_months',
            'yearly' => 'pri_yearly',
        ]);
    }

    public function test_registration_goes_to_billing_and_unpaid_workspace_is_gated(): void
    {
        $response = $this->post('/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'business_name' => 'Example Store',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('billing.index'));
        $billing = $this->get('/billing')->assertOk()->assertSee('$162')->assertSee('2-day free trial');
        $csp = (string) $billing->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://cdn.paddle.com', $csp);
        $this->assertStringContainsString('frame-src', $csp);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+" src="https:\/\/cdn\.paddle\.com/', $billing->getContent());
        $this->get('/app')->assertRedirect(route('billing.index'));
    }

    public function test_signed_subscription_webhook_activates_access_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Paid Store', 'slug' => 'paid-store']);
        $organization->users()->attach($user, ['role' => 'owner']);
        $organization->agents()->create([
            'name' => 'AI Assistant',
            'slug' => 'paid-store-agent',
            'business_name' => 'Paid Store',
            'channels' => ['web'],
            'settings' => [],
        ]);

        $payload = json_encode([
            'event_id' => 'evt_test_1',
            'event_type' => 'subscription.created',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'id' => 'sub_test_1',
                'customer_id' => 'ctm_test_1',
                'status' => 'trialing',
                'custom_data' => ['billing_reference' => Crypt::encryptString((string) $organization->id)],
                'current_billing_period' => ['ends_at' => now()->addDays(2)->toIso8601String()],
                'items' => [['price' => ['id' => 'pri_monthly']]],
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.':'.$payload, 'pdl_ntfset_secret');

        $this->call('POST', '/webhooks/paddle', [], [], [], [
            'HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();
        $this->call('POST', '/webhooks/paddle', [], [], [], [
            'HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertDatabaseCount('paddle_webhook_events', 1);
        $this->assertDatabaseHas('paddle_subscriptions', [
            'organization_id' => $organization->id,
            'paddle_subscription_id' => 'sub_test_1',
            'status' => 'trialing',
        ]);

        $this->actingAs($user)->get('/app')->assertOk();
    }

    public function test_invalid_webhook_signature_is_rejected_without_persisting(): void
    {
        $this->withHeader('Paddle-Signature', 'ts='.time().';h1=invalid')
            ->postJson('/webhooks/paddle', ['event_id' => 'evt_bad', 'event_type' => 'subscription.created'])
            ->assertStatus(500);

        $this->assertDatabaseCount('paddle_webhook_events', 0);
        $this->assertDatabaseCount('paddle_subscriptions', 0);
    }
}
