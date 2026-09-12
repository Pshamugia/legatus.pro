<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReel;
use App\Jobs\PollAiReelGeneration;
use App\Models\AiReel;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiReelPromptWriter;
use App\Services\AiReelSourceImageStorage;
use App\Services\ReelCreditService;
use App\Services\RunwayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertSee('3,000 characters')
            ->assertSee('Your description is too long. The maximum is 3,000 characters.')
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

    public function test_custom_reel_can_use_a_private_uploaded_image_instead_of_the_website_image(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('custom-reel-upload');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Upload Product'));
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-upload');

        $this->actingAs($user)->post(route('ai-reels.custom.store'), [
            'prompt' => 'Create a cinematic product scene with slow camera movement and warm light.',
            'reference_url' => data_get($product->metadata, 'product_url'),
            'source_image' => UploadedFile::fake()->image('my-product.png', 720, 1280),
            'providers' => ['facebook'],
        ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']));

        $reel = AiReel::firstOrFail();
        $this->assertSame($product->id, $reel->product_id);
        $this->assertNotNull($reel->source_image_path);
        $this->assertNotSame($product->publicImageUrl(), $reel->source_image_url);
        Storage::disk('local')->assertExists($reel->source_image_path);
        $this->get($reel->source_image_url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');
        Queue::assertPushed(GenerateAiReel::class);
    }

    public function test_custom_reel_rejects_an_uploaded_image_that_is_too_small_for_runway(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('small-reel-upload');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-small-upload');

        $this->actingAs($user)->from(route('ai-reels.index', ['tab' => 'custom']))
            ->post(route('ai-reels.custom.store'), [
                'prompt' => 'Create a cinematic product scene with slow camera movement and warm light.',
                'source_image' => UploadedFile::fake()->image('small.jpg', 400, 400),
                'providers' => ['facebook'],
            ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']))
            ->assertSessionHasErrors('source_image');

        $this->assertDatabaseCount('ai_reels', 0);
        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        Queue::assertNothingPushed();
    }

    public function test_custom_reel_rejects_a_brief_over_the_visible_character_limit(): void
    {
        Queue::fake();
        [$user, $agent] = $this->tenant('long-reel-brief');
        $this->connections($agent);

        $this->actingAs($user)->from(route('ai-reels.index', ['tab' => 'custom']))
            ->post(route('ai-reels.custom.store'), [
                'prompt' => str_repeat('ა', 3001),
                'providers' => ['facebook'],
            ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']))
            ->assertSessionHasErrors('prompt');

        $this->assertDatabaseCount('ai_reels', 0);
        Queue::assertNothingPushed();
    }

    public function test_small_website_image_is_prepared_as_a_portrait_jpeg_before_runway(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $agent] = $this->tenant('prepared-reel-image');
        $sourceFile = UploadedFile::fake()->image('website.jpg', 400, 400);
        Http::fake([
            'https://shop.example/small.jpg' => Http::response(file_get_contents($sourceFile->getRealPath()), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'source_image_url' => 'https://shop.example/small.jpg',
        ]);
        $writer = \Mockery::mock(AiReelPromptWriter::class);
        $writer->shouldReceive('write')->once()->andReturn([
            'prompt' => 'The camera moves slowly toward the stable product in warm studio light.',
            'caption' => 'Caption',
        ]);
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldReceive('create')->once()->with(
            \Mockery::type('string'),
            \Mockery::on(fn ($url) => is_string($url) && str_contains($url, '/media/reel-inputs/')),
            true,
        )->andReturn('prepared-task');

        (new GenerateAiReel($reel->id))->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class));

        $reel->refresh();
        $this->assertSame('prepared-task', $reel->runway_task_id);
        $this->assertNotNull($reel->source_image_path);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($reel->source_image_path));
        $this->assertSame([720, 1280], [$dimensions[0], $dimensions[1]]);
        Queue::assertPushed(PollAiReelGeneration::class);
    }

    public function test_custom_reel_page_and_status_endpoint_report_generation_progress(): void
    {
        [$user, $agent, $organization] = $this->tenant('custom-reel-progress');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-progress');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'generating',
        ]);

        $this->actingAs($user)->get(route('ai-reels.index', ['tab' => 'custom']))
            ->assertOk()
            ->assertSee('Your Reel is being generated. You will review it before publishing.')
            ->assertSee(route('ai-reels.status', $reel), false)
            ->assertSee('reel-spinner');

        $this->actingAs($user)->getJson(route('ai-reels.status', $reel))
            ->assertOk()
            ->assertJsonPath('status', 'generating');
    }

    public function test_custom_reel_status_is_tenant_scoped(): void
    {
        [$owner] = $this->tenant('status-owner');
        [, $otherAgent] = $this->tenant('status-other');
        $reel = $otherAgent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'generating',
        ]);

        $this->actingAs($owner)->getJson(route('ai-reels.status', $reel))->assertNotFound();
    }

    public function test_failed_generations_are_not_left_in_the_reel_gallery(): void
    {
        [$user, $agent] = $this->tenant('failed-reel-gallery');
        $this->connections($agent);
        $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'generation_failed',
            'user_prompt' => 'This failed prompt must not remain visible in the gallery.',
        ]);

        $this->actingAs($user)->get(route('ai-reels.index', ['tab' => 'custom']))
            ->assertOk()
            ->assertDontSee('This failed prompt must not remain visible in the gallery.')
            ->assertSee('Your custom Reel previews will appear here.');
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

        (new PollAiReelGeneration($reel->id))->handle($runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class));

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
        $job->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class));
        app(ReelCreditService::class)->refund($reel->fresh(), 'duplicate-attempt');

        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseCount('reel_credit_ledger', 3);
        $this->assertStringStartsWith('Reel copy preparation failed:', $reel->fresh()->last_error);
    }

    public function test_runway_request_failure_is_identified_and_refunded(): void
    {
        [$user, $agent, $organization] = $this->tenant('runway-request-refund');
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-runway-refund');
        Storage::fake('local');
        Storage::disk('local')->put('reel-inputs/test.jpg', 'image');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'source_image_path' => 'reel-inputs/test.jpg',
        ]);
        app(ReelCreditService::class)->debit($organization, 1, 'reel-test:'.$reel->id);
        $writer = \Mockery::mock(AiReelPromptWriter::class);
        $writer->shouldReceive('write')->once()->andReturn(['prompt' => 'A valid video prompt', 'caption' => 'Caption']);
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldReceive('create')->once()->andThrow(new \RuntimeException('HTTP 500'));

        (new GenerateAiReel($reel->id))->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class));

        $reel->refresh();
        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        $this->assertSame('A valid video prompt', $reel->generated_prompt);
        $this->assertSame('Caption', $reel->caption);
        $this->assertSame('Runway generation request failed: HTTP 500', $reel->last_error);
        Storage::disk('local')->assertMissing('reel-inputs/test.jpg');
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
