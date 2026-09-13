<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReel;
use App\Jobs\PollAiReelGeneration;
use App\Jobs\PublishAiReelDelivery;
use App\Models\AiReel;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiReelPromptWriter;
use App\Services\AiReelSourceImageStorage;
use App\Services\MetaGraphClient;
use App\Services\ReelCreditService;
use App\Services\ReelMusicService;
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
            ->assertSee('$1–$3 per generated Reel')
            ->assertSee('select its instrumental background music')
            ->assertSee('CC0 Public Domain license')
            ->assertSee('Bright &amp; upbeat — City Sunshine', false)
            ->assertSee('Minimum 5 seconds · Maximum 15 seconds')
            ->assertSee('15 seconds — 3 credits')
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

    public function test_runway_creates_silent_portrait_multi_shot_reels_for_selected_music_and_duration(): void
    {
        config()->set('services.runway.key', 'runway-test-key');
        Http::fake([
            'https://api.dev.runwayml.com/v1/recipes/multi_shot_video' => Http::sequence()
                ->push(['id' => 'five-second-task'])
                ->push(['id' => 'fifteen-second-task']),
        ]);

        $client = app(RunwayClient::class);
        $this->assertSame('five-second-task', $client->create('Product story', 'https://shop.example/product.jpg', 5));
        $this->assertSame('fifteen-second-task', $client->create('Custom story', null, 15));

        Http::assertSent(fn ($request) => $request['version'] === '2026-06'
            && $request['mode'] === 'auto'
            && $request['duration'] === 5
            && $request['ratio'] === '720:1280'
            && $request['audio'] === false
            && data_get($request->data(), 'firstFrame.uri') === 'https://shop.example/product.jpg'
            && $request->hasHeader('X-Runway-Version', '2024-11-06'));
        Http::assertSent(fn ($request) => $request['duration'] === 15
            && $request['ratio'] === '720:1280'
            && $request['audio'] === false
            && ! isset($request['firstFrame']));
    }

    public function test_runway_cancel_uses_the_task_delete_endpoint(): void
    {
        config()->set('services.runway.key', 'runway-test-key');
        Http::fake([
            'https://api.dev.runwayml.com/v1/tasks/task-to-stop' => Http::response(status: 204),
        ]);

        app(RunwayClient::class)->cancel('task-to-stop');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.dev.runwayml.com/v1/tasks/task-to-stop'
            && $request->hasHeader('X-Runway-Version', '2024-11-06'));
    }

    public function test_schedule_reserves_duration_priced_credits_and_uses_one_video_for_both_channels(): void
    {
        Queue::fake();
        [$user, $agent, $organization] = $this->tenant('reel-schedule');
        $this->connections($agent);
        foreach (range(1, 3) as $number) {
            $agent->products()->create($this->product('Product '.$number));
        }
        app(ReelCreditService::class)->grantPurchase($organization, 9, 'txn-seed');

        $this->actingAs($user)->post(route('ai-reels.schedules.store'), [
            'reel_count' => 3, 'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'duration_seconds' => 15,
            'music_track' => 'cinematic',
            'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi', 'timing_mode' => 'auto', 'ai_tone' => 'creative',
        ])->assertRedirect(route('ai-reels.index'));

        $this->assertSame(0, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseHas('ai_reel_schedules', ['duration_seconds' => 15, 'credits_per_reel' => 3, 'music_track' => 'cinematic']);
        $this->assertDatabaseCount('ai_reels', 3);
        $this->assertSame([3], AiReel::query()->pluck('credit_cost')->unique()->all());
        $this->assertSame(['cinematic'], AiReel::query()->pluck('music_track')->unique()->all());
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
        $reel->update([
            'status' => 'awaiting_approval', 'video_path' => 'reels/test.mp4',
            'caption' => 'Original generated caption ✨ #Original',
        ]);
        $this->artisan('legatus:dispatch-ai-reels')->assertSuccessful();
        $this->assertDatabaseHas('ai_reel_deliveries', ['ai_reel_id' => $reel->id, 'status' => 'scheduled']);

        $this->actingAs($user)->post(route('ai-reels.approve', $reel), [
            'caption' => 'Edited business caption 📚✨ Shop now! #Books #Legatus',
        ])->assertRedirect();
        $this->assertSame('ready', $reel->fresh()->status);
        $this->assertSame('Edited business caption 📚✨ Shop now! #Books #Legatus', $reel->fresh()->caption);
        $this->artisan('legatus:dispatch-ai-reels')->assertSuccessful();
        $this->assertDatabaseMissing('ai_reel_deliveries', ['ai_reel_id' => $reel->id, 'status' => 'scheduled']);
    }

    public function test_business_can_save_a_reel_draft_and_publish_it_later(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $agent] = $this->tenant('saved-custom-reel');
        $this->connections($agent);
        Storage::disk('local')->put('reels/saved.mp4', 'video');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook', 'instagram'],
            'status' => 'awaiting_approval', 'video_path' => 'reels/saved.mp4',
            'caption' => 'Generated caption',
        ]);
        foreach (['facebook', 'instagram'] as $provider) {
            $reel->deliveries()->create(['provider' => $provider, 'status' => 'scheduled']);
        }

        $this->actingAs($user)->post(route('ai-reels.save', $reel), [
            'caption' => 'Saved business caption ✨ #Later',
        ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']))
            ->assertSessionHas('reel_success', 'Reel saved for later. Nothing was published.');

        $reel->refresh();
        $this->assertSame('saved', $reel->status);
        $this->assertSame('Saved business caption ✨ #Later', $reel->caption);
        $this->assertNull($reel->approved_at);
        $this->assertNull($reel->scheduled_for);
        $this->artisan('legatus:dispatch-ai-reels')->assertSuccessful();
        Queue::assertNotPushed(PublishAiReelDelivery::class);

        $this->actingAs($user)->get(route('ai-reels.index', ['tab' => 'custom']))
            ->assertOk()
            ->assertSee('Saved draft. Edit the caption or publish whenever you are ready.')
            ->assertSee('Publish saved Reel')
            ->assertSee('Save changes');

        $this->actingAs($user)->post(route('ai-reels.approve', $reel), [
            'caption' => 'Final saved caption 🚀 #Publish',
        ])->assertRedirect();

        $reel->refresh();
        $this->assertSame('ready', $reel->status);
        $this->assertSame('Final saved caption 🚀 #Publish', $reel->caption);
        $this->assertNotNull($reel->approved_at);
        $this->assertNotNull($reel->scheduled_for);
    }

    public function test_reel_caption_is_sent_to_facebook_and_instagram(): void
    {
        Storage::fake('local');
        [, $agent] = $this->tenant('reel-caption-delivery');
        $this->connections($agent);
        Storage::disk('local')->put('reels/caption.mp4', 'video');
        $caption = 'Discover something special today 📚✨ Learn more! #Books #Reading';
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook', 'instagram'], 'status' => 'ready',
            'video_path' => 'reels/caption.mp4', 'caption' => $caption, 'scheduled_for' => now(),
        ]);
        $facebook = $reel->deliveries()->create(['provider' => 'facebook', 'status' => 'queued']);
        $instagram = $reel->deliveries()->create(['provider' => 'instagram', 'status' => 'queued']);
        $meta = \Mockery::mock(MetaGraphClient::class);
        $meta->shouldReceive('startFacebookReel')->once()->andReturn('facebook-video');
        $meta->shouldReceive('uploadFacebookReel')->once()->with(\Mockery::type('object'), 'facebook-video', \Mockery::type('string'));
        $meta->shouldReceive('finishFacebookReel')->once()->with(\Mockery::type('object'), 'facebook-video', $caption)->andReturn(['id' => 'facebook-post']);
        $meta->shouldReceive('createInstagramReelContainer')->once()->with(\Mockery::type('object'), \Mockery::type('string'), $caption)->andReturn('instagram-container');
        $meta->shouldReceive('instagramReelContainerStatus')->once()->with(\Mockery::type('object'), 'instagram-container')->andReturn('FINISHED');
        $meta->shouldReceive('publishInstagramReelContainer')->once()->with(\Mockery::type('object'), 'instagram-container')->andReturn(['id' => 'instagram-post']);

        (new PublishAiReelDelivery($facebook->id))->handle($meta);
        (new PublishAiReelDelivery($instagram->id))->handle($meta);
        (new PublishAiReelDelivery($instagram->id))->handle($meta);

        $this->assertDatabaseHas('ai_reel_deliveries', ['id' => $facebook->id, 'provider_post_id' => 'facebook-post', 'status' => 'published']);
        $this->assertDatabaseHas('ai_reel_deliveries', ['id' => $instagram->id, 'provider_post_id' => 'instagram-post', 'status' => 'published']);
    }

    public function test_luna_is_instructed_to_write_an_emoji_rich_grounded_reel_caption(): void
    {
        config()->set('services.openai.key', 'openai-test-key');
        [, $agent] = $this->tenant('reel-caption-copy');
        $product = $agent->products()->create($this->product('Caption Product'));
        $reel = $agent->aiReels()->create([
            'product_id' => $product->id, 'mode' => 'scheduled', 'providers' => ['facebook'],
            'status' => 'queued', 'language' => 'English',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'prompt' => 'A cohesive vertical multi-shot product story in warm cinematic light.',
                            'caption' => "Meet Caption Product 📚✨\n\nDiscover it today!\n\n#Books #Reading",
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ]),
        ]);

        $copy = app(AiReelPromptWriter::class)->write($reel);

        $this->assertStringContainsString('📚✨', $copy['caption']);
        $this->assertStringContainsString('#Books #Reading', $copy['caption']);
        $this->assertStringContainsString('https://shop.example/products/caption-product', $copy['caption']);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['input'], '2 to 5 relevant emojis')
            && str_contains((string) $request['input'], '2 to 5 relevant hashtags')
            && str_contains((string) $request['input'], 'do not invent claims'));
    }

    public function test_ten_second_custom_reel_costs_two_credits(): void
    {
        Queue::fake();
        [$user, $agent, $organization] = $this->tenant('ten-second-custom-reel');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 3, 'txn-ten-second-custom');

        $this->actingAs($user)->post(route('ai-reels.custom.store'), [
            'prompt' => 'Create a cinematic multi-shot brand story with clear motion and warm light.',
            'duration_seconds' => 10,
            'music_track' => 'modern',
            'providers' => ['facebook', 'instagram'],
        ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']));

        $reel = AiReel::firstOrFail();
        $this->assertSame(10, $reel->duration_seconds);
        $this->assertSame(2, $reel->credit_cost);
        $this->assertSame('modern', $reel->music_track);
        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        Queue::assertPushed(GenerateAiReel::class, 1);
    }

    public function test_unsupported_custom_reel_duration_is_rejected_without_charging(): void
    {
        Queue::fake();
        [$user, $agent, $organization] = $this->tenant('invalid-duration-custom-reel');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 3, 'txn-invalid-duration');

        $this->actingAs($user)->from(route('ai-reels.index', ['tab' => 'custom']))
            ->post(route('ai-reels.custom.store'), [
                'prompt' => 'Create a cinematic multi-shot brand story with clear motion and warm light.',
                'duration_seconds' => 12,
                'providers' => ['facebook'],
            ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']))
            ->assertSessionHasErrors('duration_seconds');

        $this->assertDatabaseCount('ai_reels', 0);
        $this->assertSame(3, app(ReelCreditService::class)->balance($organization));
        Queue::assertNothingPushed();
    }

    public function test_unknown_music_track_is_rejected_without_charging(): void
    {
        Queue::fake();
        [$user, $agent, $organization] = $this->tenant('invalid-reel-music');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-invalid-music');

        $this->actingAs($user)->from(route('ai-reels.index', ['tab' => 'custom']))
            ->post(route('ai-reels.custom.store'), [
                'prompt' => 'Create a cinematic brand story with clear movement and warm light.',
                'music_track' => 'unlicensed-upload',
                'providers' => ['facebook'],
            ])->assertRedirect(route('ai-reels.index', ['tab' => 'custom']))
            ->assertSessionHasErrors('music_track');

        $this->assertDatabaseCount('ai_reels', 0);
        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        Queue::assertNothingPushed();
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
            \Mockery::on(fn ($image) => is_string($image) && str_starts_with($image, 'data:image/jpeg;base64,')),
            5,
        )->andReturn('prepared-task');

        (new GenerateAiReel($reel->id))->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class), $this->availableMusic());

        $reel->refresh();
        $this->assertSame('prepared-task', $reel->runway_task_id);
        $this->assertNotNull($reel->source_image_path);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($reel->source_image_path));
        $this->assertSame([720, 1280], [$dimensions[0], $dimensions[1]]);
        Queue::assertPushed(PollAiReelGeneration::class);
    }

    public function test_uploaded_image_is_sent_directly_to_runway_without_a_public_fetch(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $agent] = $this->tenant('direct-reel-image');
        $uploaded = UploadedFile::fake()->image('uploaded.png', 720, 1280);
        $stored = app(AiReelSourceImageStorage::class)->store($uploaded);
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'source_image_path' => $stored['path'], 'source_image_url' => $stored['url'],
        ]);

        $input = app(AiReelSourceImageStorage::class)->prepareForRunway($reel);

        $this->assertStringStartsWith('data:image/jpeg;base64,', $input);
        $this->assertLessThan(5_000_000, strlen($input));
        $reel->refresh();
        Storage::disk('local')->assertExists($reel->source_image_path);
        Storage::disk('local')->assertMissing($stored['path']);
    }

    public function test_runway_retries_a_transient_server_error(): void
    {
        config([
            'services.runway.key' => 'runway-secret',
            'services.runway.base_url' => 'https://api.dev.runwayml.com/v1',
            'services.runway.api_version' => '2024-11-06',
        ]);
        Http::fakeSequence()
            ->push(['error' => 'Internal server error'], 500)
            ->push(['id' => 'recovered-task'], 200);

        $taskId = app(RunwayClient::class)->create('A simple camera push-in.', null, 5);

        $this->assertSame('recovered-task', $taskId);
        Http::assertSentCount(2);
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
            ->assertSee('Stop generation')
            ->assertSee(route('ai-reels.cancel', $reel), false)
            ->assertSee(route('ai-reels.status', $reel), false)
            ->assertSee('reel-spinner');

        $this->actingAs($user)->getJson(route('ai-reels.status', $reel))
            ->assertOk()
            ->assertJsonPath('status', 'generating');
    }

    public function test_queued_custom_reel_can_be_stopped_and_its_credit_is_returned(): void
    {
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('stop-queued-reel');
        app(ReelCreditService::class)->grantPurchase($organization, 2, 'txn-stop-queued');
        Storage::disk('local')->put('reel-inputs/stop-queued.jpg', 'image');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'duration_seconds' => 10, 'credit_cost' => 2,
            'source_image_path' => 'reel-inputs/stop-queued.jpg',
        ]);
        app(ReelCreditService::class)->debit($organization, 2, 'custom-reel:'.$reel->id);

        $this->actingAs($user)->post(route('ai-reels.cancel', $reel))
            ->assertRedirect()
            ->assertSessionHas('reel_success', 'Reel generation stopped. Nothing was published.');

        $reel->refresh();
        $this->assertSame('canceled', $reel->status);
        $this->assertNotNull($reel->credit_refunded_at);
        $this->assertSame(2, app(ReelCreditService::class)->balance($organization));
        Storage::disk('local')->assertMissing('reel-inputs/stop-queued.jpg');
    }

    public function test_running_custom_reel_is_canceled_at_runway_without_returning_used_credit(): void
    {
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('stop-running-reel');
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-stop-running');
        Storage::disk('local')->put('reel-inputs/stop-running.jpg', 'image');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'generating',
            'runway_task_id' => 'active-task', 'source_image_path' => 'reel-inputs/stop-running.jpg',
        ]);
        app(ReelCreditService::class)->debit($organization, 1, 'custom-reel:'.$reel->id);
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldReceive('cancel')->once()->with('active-task');
        $this->app->instance(RunwayClient::class, $runway);

        $this->actingAs($user)->post(route('ai-reels.cancel', $reel))
            ->assertRedirect()
            ->assertSessionHas('reel_success', 'Reel generation stopped. Nothing was published.');

        $reel->refresh();
        $this->assertSame('canceled', $reel->status);
        $this->assertNull($reel->credit_refunded_at);
        $this->assertSame(0, app(ReelCreditService::class)->balance($organization));
        Storage::disk('local')->assertMissing('reel-inputs/stop-running.jpg');
    }

    public function test_failed_runway_cancellation_keeps_the_reel_tracked_and_does_not_return_credit(): void
    {
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('failed-stop-running-reel');
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-failed-stop-running');
        Storage::disk('local')->put('reel-inputs/failed-stop-running.jpg', 'image');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'generating',
            'runway_task_id' => 'task-not-stopped', 'source_image_path' => 'reel-inputs/failed-stop-running.jpg',
        ]);
        app(ReelCreditService::class)->debit($organization, 1, 'custom-reel:'.$reel->id);
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldReceive('cancel')->once()->andThrow(new \RuntimeException('Runway unavailable'));
        $this->app->instance(RunwayClient::class, $runway);

        $this->actingAs($user)->post(route('ai-reels.cancel', $reel))
            ->assertRedirect()
            ->assertSessionHasErrors('reel');

        $reel->refresh();
        $this->assertSame('generating', $reel->status);
        $this->assertStringStartsWith('Runway cancellation failed:', $reel->last_error);
        $this->assertNull($reel->credit_refunded_at);
        $this->assertSame(0, app(ReelCreditService::class)->balance($organization));
        Storage::disk('local')->assertExists('reel-inputs/failed-stop-running.jpg');
    }

    public function test_business_cannot_stop_another_tenants_reel(): void
    {
        [$user] = $this->tenant('stop-own-reel');
        [, $otherAgent] = $this->tenant('stop-other-reel');
        $reel = $otherAgent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
        ]);

        $this->actingAs($user)->post(route('ai-reels.cancel', $reel))->assertNotFound();
        $this->assertSame('queued', $reel->fresh()->status);
    }

    public function test_generation_job_honors_a_stop_requested_while_the_prompt_is_prepared(): void
    {
        Queue::fake();
        Storage::fake('local');
        [, $agent] = $this->tenant('stop-during-prompt');
        Storage::disk('local')->put('reel-inputs/stop-during-prompt.jpg', 'image');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'source_image_path' => 'reel-inputs/stop-during-prompt.jpg',
        ]);
        $writer = \Mockery::mock(AiReelPromptWriter::class);
        $writer->shouldReceive('write')->once()->andReturnUsing(function () use ($reel): array {
            $reel->update(['status' => 'canceling']);

            return ['prompt' => 'A valid video prompt', 'caption' => 'Caption'];
        });
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldNotReceive('create');

        (new GenerateAiReel($reel->id))->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class), $this->availableMusic());

        $this->assertSame('canceled', $reel->fresh()->status);
        Storage::disk('local')->assertMissing('reel-inputs/stop-during-prompt.jpg');
        Queue::assertNothingPushed();
    }

    public function test_completed_preview_does_not_block_the_next_custom_reel(): void
    {
        [$user, $agent, $organization] = $this->tenant('next-custom-reel');
        $this->connections($agent);
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-next-preview');
        $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'awaiting_approval',
            'caption' => 'Ready caption 📚✨ #Books',
        ]);

        $this->actingAs($user)->get(route('ai-reels.index', ['tab' => 'custom']))
            ->assertOk()
            ->assertSee('Generate another preview')
            ->assertSee('Facebook & Instagram caption', false)
            ->assertSee('Ready caption 📚✨ #Books')
            ->assertSee('Approve caption & publish')
            ->assertSee('data-can-manage="1"', false)
            ->assertSee('data-pending="0"', false);
    }

    public function test_business_can_remove_an_unapproved_preview_without_a_credit_refund(): void
    {
        Storage::fake('local');
        [$user, $agent, $organization] = $this->tenant('remove-custom-reel');
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-remove-preview');
        Storage::disk('local')->put('reels/remove-preview.mp4', 'video');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'awaiting_approval',
            'video_path' => 'reels/remove-preview.mp4', 'generated_at' => now(),
        ]);
        app(ReelCreditService::class)->debit($organization, 1, 'custom-reel:'.$reel->id);

        $this->actingAs($user)->delete(route('ai-reels.destroy', $reel))
            ->assertRedirect(route('ai-reels.index', ['tab' => 'custom']))
            ->assertSessionHas('reel_success', 'Reel preview removed. Nothing was published.');

        $this->assertDatabaseMissing('ai_reels', ['id' => $reel->id]);
        Storage::disk('local')->assertMissing('reels/remove-preview.mp4');
        $this->assertSame(0, app(ReelCreditService::class)->balance($organization));
    }

    public function test_business_cannot_remove_another_tenants_preview(): void
    {
        [$user] = $this->tenant('remove-own-reel');
        [, $otherAgent] = $this->tenant('remove-other-reel');
        $reel = $otherAgent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'awaiting_approval',
        ]);

        $this->actingAs($user)->delete(route('ai-reels.destroy', $reel))->assertNotFound();
        $this->assertDatabaseHas('ai_reels', ['id' => $reel->id]);
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
        $music = \Mockery::mock(ReelMusicService::class);
        $music->shouldReceive('mix')->once()->with('video-contents', 'bright', 5)->andReturn('video-with-selected-music');

        (new PollAiReelGeneration($reel->id))->handle($runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class), $music);

        $reel->refresh();
        $this->assertSame('awaiting_approval', $reel->status);
        $this->assertNotNull($reel->video_path);
        Storage::disk('local')->assertExists($reel->video_path);
        $this->assertSame('video-with-selected-music', Storage::disk('local')->get($reel->video_path));
    }

    public function test_generation_failure_refunds_the_credit_once(): void
    {
        [$user, $agent, $organization] = $this->tenant('reel-refund');
        app(ReelCreditService::class)->grantPurchase($organization, 3, 'txn-refund');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'duration_seconds' => 15, 'credit_cost' => 3,
        ]);
        app(ReelCreditService::class)->debit($organization, 3, 'reel-test:'.$reel->id);
        $writer = \Mockery::mock(AiReelPromptWriter::class);
        $writer->shouldReceive('write')->once()->andThrow(new \RuntimeException('AI unavailable'));
        $runway = \Mockery::mock(RunwayClient::class);

        $job = new GenerateAiReel($reel->id);
        $job->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class), $this->availableMusic());
        app(ReelCreditService::class)->refund($reel->fresh(), 'duplicate-attempt');

        $this->assertSame(3, app(ReelCreditService::class)->balance($organization));
        $this->assertDatabaseCount('reel_credit_ledger', 3);
        $this->assertStringStartsWith('Reel copy preparation failed:', $reel->fresh()->last_error);
    }

    public function test_music_is_preflighted_before_paid_runway_generation(): void
    {
        [$user, $agent, $organization] = $this->tenant('reel-music-preflight');
        app(ReelCreditService::class)->grantPurchase($organization, 1, 'txn-music-preflight');
        $reel = $agent->aiReels()->create([
            'mode' => 'custom', 'providers' => ['facebook'], 'status' => 'queued',
            'music_track' => 'cinematic',
        ]);
        app(ReelCreditService::class)->debit($organization, 1, 'reel-test:'.$reel->id);

        $music = \Mockery::mock(ReelMusicService::class);
        $music->shouldReceive('ensureAvailable')->once()->with('cinematic')
            ->andThrow(new \RuntimeException('FFmpeg unavailable'));
        $writer = \Mockery::mock(AiReelPromptWriter::class);
        $writer->shouldNotReceive('write');
        $runway = \Mockery::mock(RunwayClient::class);
        $runway->shouldNotReceive('create');

        (new GenerateAiReel($reel->id))->handle(
            $writer,
            $runway,
            app(ReelCreditService::class),
            app(AiReelSourceImageStorage::class),
            $music,
        );

        $this->assertSame(1, app(ReelCreditService::class)->balance($organization));
        $this->assertSame('generation_failed', $reel->fresh()->status);
        $this->assertSame('Reel music preparation failed: FFmpeg unavailable', $reel->fresh()->last_error);
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

        (new GenerateAiReel($reel->id))->handle($writer, $runway, app(ReelCreditService::class), app(AiReelSourceImageStorage::class), $this->availableMusic());

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

    private function availableMusic(): ReelMusicService
    {
        $music = \Mockery::mock(ReelMusicService::class);
        $music->shouldReceive('ensureAvailable')->once()->with('bright');

        return $music;
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
