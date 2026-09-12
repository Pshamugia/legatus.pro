<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReel;
use App\Jobs\PollAiReelGeneration;
use App\Models\AiReel;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiReelPromptWriter;
use App\Services\ReelCreditService;
use App\Services\RunwayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiReelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('paddle.client_token', 'test_client_token');
        config()->set('paddle.reel_credit_price', 'pri_reel_credit');
        config()->set('paddle.webhook_secret', 'pdl_ntfset_secret');
        config()->set('paddle.environment', 'sandbox');
    }

    public function test_reels_page_explains_separate_pricing_and_minimum_credit_purchase(): void
    {
        [$user, $agent] = $this->tenant('reel-page');
        $this->connections($agent);

        $response = $this->actingAs($user)->get(route('ai-reels.index'));

        $response->assertOk()
            ->assertSee('Create AI Reels')
            ->assertSee('$1 per generated Reel')
            ->assertSee('Minimum 10')
            ->assertSee('Schedule Facebook')
            ->assertSee('Instagram Reels')
            ->assertSee('Reels your way')
            ->assertSee('data-price-id="pri_reel_credit"', false);
        $this->assertStringContainsString('https://cdn.paddle.com', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_completed_paddle_transaction_grants_actual_reel_quantity_once(): void
    {
        [$user, $agent, $organization] = $this->tenant('reel-purchase');
        $payload = json_encode([
            'event_id' => 'evt_reels_1', 'event_type' => 'transaction.completed', 'occurred_at' => now()->toIso8601String(),
            'data' => [
                'id' => 'txn_reels_1', 'customer_id' => 'ctm_reels_1',
                'custom_data' => [
                    'billing_reference' => Crypt::encryptString((string) $organization->id),
                    'purchase_kind' => 'reel_credits',
                ],
                'items' => [['price' => ['id' => 'pri_reel_credit'], 'quantity' => 30]],
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.':'.$payload, 'pdl_ntfset_secret');

        foreach ([1, 2] as $_) {
            $this->call('POST', route('webhooks.paddle'), [], [], [], [
                'HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1={$signature}", 'CONTENT_TYPE' => 'application/json',
            ], $payload)->assertOk();
        }

        $this->assertSame(30, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseCount('reel_credit_ledger', 1);
    }

    public function test_paddle_transaction_below_the_minimum_does_not_grant_reel_credits(): void
    {
        [$user, $agent, $organization] = $this->tenant('reel-small-purchase');
        $payload = json_encode([
            'event_id' => 'evt_reels_small', 'event_type' => 'transaction.completed', 'occurred_at' => now()->toIso8601String(),
            'data' => [
                'id' => 'txn_reels_small',
                'custom_data' => [
                    'billing_reference' => Crypt::encryptString((string) $organization->id),
                    'purchase_kind' => 'reel_credits',
                ],
                'items' => [['price' => ['id' => 'pri_reel_credit'], 'quantity' => 9]],
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.':'.$payload, 'pdl_ntfset_secret');

        $this->call('POST', route('webhooks.paddle'), [], [], [], [
            'HTTP_PADDLE_SIGNATURE' => "ts={$timestamp};h1={$signature}", 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertSame(0, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseCount('reel_credit_ledger', 0);
    }

    public function test_runway_uses_model_compatible_portrait_ratios_and_api_version(): void
    {
        config()->set('services.runway.key', 'runway-test-key');
        Http::fake([
            'https://api.dev.runwayml.com/v1/image_to_video' => Http::sequence()
                ->push(['id' => 'product-task'])
                ->push(['id' => 'custom-task']),
        ]);

        $client = app(RunwayClient::class);
        $this->assertSame('product-task', $client->create('Product motion', 'https://shop.example/product.jpg', false));
        $this->assertSame('custom-task', $client->create('Custom scene', null, true));

        Http::assertSent(fn ($request) => $request['model'] === 'gen4_turbo'
            && $request['ratio'] === '768:1280'
            && $request['promptImage'] === 'https://shop.example/product.jpg'
            && $request->hasHeader('X-Runway-Version', '2024-11-06'));
        Http::assertSent(fn ($request) => $request['model'] === 'gen4.5'
            && $request['ratio'] === '720:1280'
            && ! isset($request['promptImage']));
    }

    public function test_schedule_reserves_one_credit_per_video_and_uses_one_video_for_both_channels(): void
    {
        Queue::fake();
        [$user, $agent, $organization] = $this->tenant('reel-schedule');
        $this->connections($agent);
        foreach (range(1, 3) as $number) {
            $agent->products()->create($this->product('Product '.$number));
        }
        app(ReelCreditService::class)->grantPurchase($organization, 3, 'txn-seed');

        $this->actingAs($user)->post(route('ai-reels.schedules.store'), [
            'reel_count' => 3, 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi', 'timing_mode' => 'auto', 'ai_tone' => 'creative',
        ])->assertRedirect(route('ai-reels.index'));

        $this->assertSame(0, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseCount('ai_reels', 3);
        $this->assertDatabaseCount('ai_reel_deliveries', 6);
        Queue::assertPushed(GenerateAiReel::class, 3);
    }

    public function test_schedule_is_rejected_without_enough_credits(): void
    {
        Queue::fake();
        [$user, $agent] = $this->tenant('reel-no-credit');
        $this->connections($agent);
        $agent->products()->create($this->product('Product'));

        $this->actingAs($user)->from(route('ai-reels.index'))->post(route('ai-reels.schedules.store'), [
            'reel_count' => 1, 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'providers' => ['facebook'], 'timezone' => 'Asia/Tbilisi', 'timing_mode' => 'auto', 'ai_tone' => 'creative',
        ])->assertRedirect(route('ai-reels.index'))->assertSessionHasErrors('credits');

        $this->assertDatabaseCount('ai_reel_schedules', 0);
        $this->assertDatabaseCount('ai_reels', 0);
    }

    public function test_custom_reel_requires_approval_before_it_can_be_dispatched(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('custom-reel');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-custom');

        $this->actingAs($user)->post(route('ai-reels.custom.store'), [
            'prompt' => 'Create a cinematic morning scene with slow camera movement and warm light.',
            'providers' => ['facebook', 'instagram'],
        ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']));
        $reel = AiReel::firstOrFail();
        $this->assertSame('queued', $reel->status);
        Queue::assertPushed(GenerateAiReel::class, 1);

        Storage::disk('local')->put('reels/test.mp4', 'video');
        $reel->update(['status' => 'awaiting_approval', 'video_path' => 'reels/test.mp4']);
        $this->artisan('legatus:dispatch-ai-reels')->assertSuccessful();
        $this->assertDatabaseHas('ai_reel_deliveries', ['ai_reel_id' => $reel->id, 'status' => 'scheduled']);

        $this->actingAs($user)->post(route('ai-reels.approve', $reel))->assertRedirect();
        $this->assertSame('ready', $reel->fresh()->status);
        $this->artisan('legatus:dispatch-ai-reels')->assertSuccessful();
        $this->assertDatabaseMissing('ai_reel_deliveries', ['ai_reel_id' => $reel->id, 'status' => 'scheduled']);
    }

    public function test_runway_success_is_downloaded_to_durable_storage_and_waits_for_custom_approval(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('runway-result');
        $reel = $agent->aiReels()->create(['mode' => 'custom', 'providers' => ['facebook'], 'status' => 'generating', 'runway_task_id' => 'task-1']);
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldReceive('task')->once()->with('task-1')->andReturn(['status' => 'SUCCEEDED', 'output' => ['https://runway.test/video.mp4']]);
        $runway->shouldReceive('download')->once()->andReturn('video-contents');

        (new PollAiReelGeneration($reel->id))->handle($runway, app(ReelCreditService::class));

        $reel->refresh();
        $this->assertSame('awaiting_approval', $reel->status);
        $this->assertNotNull($reel->video_path);
        Storage::disk('local')->assertExists($reel->video_path);
    }

    public function test_generation_failure_refunds_the_credit_once(): void
    {
        [$user, $agent, $organization] = $this->tenant('reel-refund');
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-refund');
        $reel = $agent->aiReels()->create(['mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued']);
        app(ReelCreditService::class)->debit($organization, 1, 'reel-test:'.$reel->id);
        $writer = \Mockery::mock(AiReelPromptWriter::class);
        $writer->shouldReceive('write')->once()->andThrow(new \RuntimeException('AI unavailable'));
        $runway = \Mockery::mock(RunwayClient::class);

        $job = new GenerateAiReel($reel->id);
        $job->handle($writer, $runway, app(ReelCreditService::class));
        app(ReelCreditService::class)->refund($reel->fresh(), 'duplicate-attempt');

        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseCount('reel_credit_ledger', 3);
    }

    private function tenant(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => $slug, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Assistant', 'slug' => $slug.'-agent', 'business_name' => $slug,
            'channels' => ['web', 'facebook', 'instagram'], 'settings' => [], 'is_active' => true,
        ]);

        return [$user, $agent, $organization];
    }

    private function connections($agent): void
    {
        foreach (['facebook' => 'page-1', 'instagram' => 'ig-1'] as $provider => $id) {
            $agent->channelConnections()->create([
                'provider' => $provider, 'status' => 'active', 'external_account_id' => $id,
                'external_account_name' => ucfirst($provider), 'access_token' => $provider.'-token', 'connected_at' => now(),
            ]);
        }
    }

    private function product(string $name): array
    {
        return [
            'name' => $name, 'category' => 'General', 'description' => 'Verified product description.',
            'price' => 20, 'stock' => 2, 'image' => 'https://shop.example/product.jpg', 'is_active' => true,
            'metadata' => ['product_url' => 'https://shop.example/products/'.str($name)->slug(), 'currency' => 'USD'],
        ];
    }
}
