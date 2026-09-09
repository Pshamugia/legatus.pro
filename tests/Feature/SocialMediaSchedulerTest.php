<?php

namespace Tests\Feature;

use App\Jobs\PrepareSocialMediaSlot;
use App\Jobs\PublishSocialMediaPost;
use App\Models\Organization;
use App\Models\SocialMediaPost;
use App\Models\User;
use App\Services\KnowledgeIngestionService;
use App\Services\MetaGraphClient;
use App\Services\ProductPagePrimaryImageResolver;
use App\Services\SocialMediaAiCopywriter;
use App\Services\SocialMediaImageDesigner;
use App\Services\SocialMediaScheduler;
use App\Services\SocialMediaTemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SocialMediaSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.app_secret', 'meta-secret');
        config()->set('meta.graph_url', 'https://graph.facebook.test');
        config()->set('meta.graph_version', 'v25.0');
        config()->set('meta.retries', 0);
    }

    public function test_owner_builds_a_tenant_scoped_multi_channel_schedule_from_public_products(): void
    {
        [$user, $agent] = $this->tenant('scheduler-owner');
        $this->connections($agent);
        $selected = $agent->products()->create($this->product('Public Novel', 'Novel', 3));
        $selected->update(['metadata' => array_replace($selected->metadata, [
            'image' => 'https://shop.example/images/catalog-design.jpg',
        ])]);
        $agent->products()->create($this->product('Poetry Book', 'Poetry', 4));

        Http::fake(['https://shop.example/images/*' => Http::response('not-an-image')]);
        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'posts_per_day' => 1,
            'categories' => ['Novel'],
            'providers' => ['facebook', 'instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'))
            ->assertSessionHas('social_success', 'Schedule created successfully. Every slot will automatically skip unavailable or already-used products and select the next eligible product.');

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertSame(['Novel'], $schedule->categories);
        $this->assertCount(2, $schedule->posts);
        $this->assertSame([$selected->id], $schedule->posts->pluck('product_id')->unique()->values()->all());
        $this->assertTrue($schedule->posts->every(fn ($post) => str_contains($post->caption, 'https://shop.example/products/public-novel')));
        $this->assertTrue($schedule->posts->every(fn ($post) => $post->description === 'Verified public description.'));
        $this->assertTrue($schedule->posts->every(fn ($post) => str_contains($post->caption, 'Verified public description.')));
        $this->assertSame(1, $schedule->posts->where('provider', 'facebook')->count());
        $this->assertSame(1, $schedule->posts->where('provider', 'instagram')->count());

        $this->actingAs($user)->get(route('social-media.index'))
            ->assertOk()
            ->assertSee('Social media scheduler')
            ->assertSee('Choose Facebook, Instagram, or both connected channels.')
            ->assertSee('Public Novel')
            ->assertSee('Verified public description.')
            ->assertSee('Classic frame')
            ->assertSee('AI Copywriter')
            ->assertSee('Original content')
            ->assertSee('Save schedule')
            ->assertSee('Creating schedule…')
            ->assertSee('Creating your schedule. Please wait…')
            ->assertSee('data-schedule-submit-spinner', false)
            ->assertSee('Informative')
            ->assertSee('Luna writes up to 7 product posts per business each day')
            ->assertSee('3D catalog design')
            ->assertSee('Catalog design')
            ->assertSee('Catalog design preview')
            ->assertSee('Plain photo')
            ->assertDontSee('Storefront image')
            ->assertSee('https://shop.example/images/product.jpg')
            ->assertSee('https://shop.example/images/catalog-design.jpg')
            ->assertSee('.template-workspace[hidden]{display:none!important}', false)
            ->assertSee('Open public product');
    }

    public function test_live_preview_uses_primary_storefront_copy_instead_of_an_arbitrary_localization(): void
    {
        [$user, $agent] = $this->tenant('localized-preview');
        $product = $agent->products()->create($this->product('ქართული პროდუქტი', 'ქართული კატეგორია', 2));
        $product->update(['metadata' => array_replace_recursive($product->metadata, [
            'product_url' => 'https://shop.example/products/georgian-product',
            'localized' => [
                'English' => [
                    'name' => 'English product title',
                    'description' => 'English localized description.',
                    'product_url' => 'https://shop.example/products/georgian-product?lang=en',
                ],
            ],
        ])]);
        Http::fake(['https://shop.example/images/*' => Http::response('not-an-image')]);

        $this->actingAs($user)->get(route('social-media.index'))
            ->assertOk()
            ->assertSee('ქართული პროდუქტი')
            ->assertSee('https://shop.example/products/georgian-product')
            ->assertDontSee('English product title')
            ->assertDontSee('?lang=en');
    }

    public function test_live_preview_uses_the_businesses_first_configured_website_language(): void
    {
        [$user, $agent] = $this->tenant('primary-language-preview');
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'source_scope' => 'language',
            'taxonomy_label' => 'Georgian',
            'name' => 'Website language: Georgian',
            'url' => 'https://shop.example/?lang=ka',
            'status' => 'ready',
            'progress' => 100,
        ]);
        $product = $agent->products()->create($this->product('English base product', 'Books', 2));
        $product->update(['metadata' => array_replace_recursive($product->metadata, [
            'localized' => [
                'Georgian' => [
                    'name' => 'ქართული პროდუქტი',
                    'description' => 'ქართული პროდუქტის აღწერა.',
                    'category' => 'წიგნები',
                    'product_url' => 'https://shop.example/products/georgian-product?lang=ka',
                ],
            ],
        ])]);
        Http::fake(['https://shop.example/images/*' => Http::response('not-an-image')]);

        $response = $this->actingAs($user)->get(route('social-media.index'));
        $response->assertOk()
            ->assertSee('ქართული პროდუქტი')
            ->assertSee('?lang=ka')
            ->assertDontSee('English base product');
        $this->assertSame('ქართული პროდუქტის აღწერა.', $response->viewData('previewProduct')['description']);
    }

    public function test_products_without_imported_descriptions_remain_eligible_for_social_posts(): void
    {
        [$user, $agent] = $this->tenant('description-optional');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'source_scope' => 'language',
            'taxonomy_label' => 'Georgian',
            'name' => 'Website language: Georgian',
            'url' => 'https://shop.example/?lang=ka',
            'status' => 'ready',
            'progress' => 100,
        ]);
        $attributes = $this->product('Book Without Imported Description', 'General', 2);
        $attributes['description'] = null;
        $product = $agent->products()->create($attributes);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $this->assertSame($product->id, $agent->socialMediaPosts()->value('product_id'));
    }

    public function test_multi_channel_schedule_does_not_repeat_products_while_unused_products_remain(): void
    {
        [$user, $agent] = $this->tenant('cross-channel-product-rotation');
        $this->connections($agent);
        foreach (range(1, 4) as $number) {
            $agent->products()->create($this->product("Rotation Product {$number}", 'General', 2));
        }

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 2,
            'providers' => ['facebook', 'instagram'],
            'timezone' => 'Asia/Tbilisi',
            'copy_mode' => 'ai',
            'ai_tone' => 'simple',
        ])->assertSessionHasNoErrors();

        $posts = $agent->socialMediaPosts()->orderBy('scheduled_for')->orderBy('provider')->get();
        $this->assertCount(4, $posts);
        $this->assertCount(2, $posts->pluck('product_id')->unique());
        $this->assertTrue($posts->groupBy('scheduled_for')->every(
            fn ($slotPosts): bool => $slotPosts->pluck('product_id')->unique()->count() === 1
                && $slotPosts->pluck('provider')->sort()->values()->all() === ['facebook', 'instagram'],
        ));
    }

    public function test_one_schedule_keeps_future_slots_after_every_unused_product_has_been_reserved(): void
    {
        [$user, $agent] = $this->tenant('single-schedule-no-repeat');
        $this->connections($agent);
        foreach (range(1, 3) as $number) {
            $agent->products()->create($this->product("Finite Product {$number}", 'General', 2));
        }

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 5,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $posts = $agent->socialMediaPosts()->get();
        $this->assertCount(5, $posts);
        $this->assertCount(3, $posts->whereNotNull('product_id')->pluck('product_id')->unique());
        $this->assertCount(2, $posts->whereNull('product_id'));
    }

    public function test_linkedin_uses_the_same_product_slot_and_publishes_an_image_post(): void
    {
        [$user, $agent] = $this->tenant('linkedin-scheduler');
        $this->connections($agent);
        $agent->channelConnections()->create([
            'provider' => 'linkedin', 'status' => 'active', 'external_account_id' => '778899',
            'external_account_name' => 'Legatus Company', 'access_token' => 'linkedin-token', 'connected_at' => now(),
        ]);
        $product = $agent->products()->create($this->product('LinkedIn Product', 'General', 2));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['facebook', 'instagram', 'linkedin'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $posts = $agent->socialMediaPosts()->get();
        $this->assertSame(['facebook', 'instagram', 'linkedin'], $posts->pluck('provider')->sort()->values()->all());
        $this->assertSame([$product->id], $posts->pluck('product_id')->unique()->values()->all());

        $linkedinPost = $posts->firstWhere('provider', 'linkedin');
        $linkedinPost->update(['status' => 'queued']);
        config(['linkedin.api_url' => 'https://api.linkedin.test', 'linkedin.version' => '202606']);
        Http::fake([
            'https://shop.example/images/product.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'https://api.linkedin.test/rest/images*' => Http::response(['value' => [
                'uploadUrl' => 'https://upload.linkedin.test/image-1',
                'image' => 'urn:li:image:image-1',
            ]]),
            'https://upload.linkedin.test/image-1' => Http::response('', 201),
            'https://api.linkedin.test/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:123']),
        ]);

        (new PublishSocialMediaPost($linkedinPost->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $this->assertSame('published', $linkedinPost->fresh()->status);
        $this->assertSame('urn:li:share:123', $linkedinPost->fresh()->provider_post_id);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.linkedin.test/rest/posts'
            && $request['author'] === 'urn:li:organization:778899'
            && data_get($request->data(), 'content.media.id') === 'urn:li:image:image-1');
    }

    public function test_legacy_duplicate_is_skipped_before_it_can_be_published_again(): void
    {
        [$user, $agent] = $this->tenant('publish-time-duplicate-guard');
        $this->connections($agent);
        $agent->products()->create($this->product('Already Published Product', 'General', 2));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $published = $agent->socialMediaPosts()->firstOrFail();
        $published->update(['status' => 'published', 'published_at' => now()]);
        $duplicate = $published->replicate();
        $duplicate->status = 'queued';
        $duplicate->published_at = null;
        $duplicate->provider_post_id = null;
        $duplicate->scheduled_for = now();
        $duplicate->save();

        (new PublishSocialMediaPost($duplicate->id))->handle(
            app(MetaGraphClient::class),
            app(SocialMediaTemplateRenderer::class),
        );

        $this->assertSame('skipped', $duplicate->fresh()->status);
        $this->assertSame('This product was already published on this channel.', $duplicate->fresh()->failure_reason);
    }

    public function test_product_that_sells_out_after_scheduling_is_skipped_before_publish(): void
    {
        [$user, $agent] = $this->tenant('publish-time-stock-guard');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Later Sold Out Product', 'General', 2));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $post = $agent->socialMediaPosts()->firstOrFail();
        $post->update(['status' => 'queued']);
        $product->update(['stock' => 0]);

        (new PublishSocialMediaPost($post->id))->handle(
            app(MetaGraphClient::class),
            app(SocialMediaTemplateRenderer::class),
        );

        $this->assertSame('skipped', $post->fresh()->status);
        $this->assertSame(
            'The public product is no longer active, in stock, or publishable on this channel.',
            $post->fresh()->failure_reason,
        );
    }

    public function test_live_sold_out_product_is_replaced_in_the_same_due_slot(): void
    {
        [$user, $agent] = $this->tenant('live-stock-replacement');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'source_scope' => 'catalog',
            'name' => 'Refreshing catalog',
            'url' => 'https://shop.example/catalog',
            'status' => 'ready',
            'progress' => 64,
            'error' => 'The previous synchronization was interrupted.',
        ]);
        $soldOut = $agent->products()->create($this->product('Freshly Sold Out', 'General', 2));
        $soldOut->update(['metadata' => array_replace($soldOut->metadata, [
            'product_url' => 'https://example.com/products/freshly-sold-out',
        ])]);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $post = $schedule->posts()->firstOrFail();
        $replacement = $agent->products()->create($this->product('Live Available Replacement', 'General', 2));
        $replacement->update(['metadata' => array_replace($replacement->metadata, [
            'product_url' => 'https://example.com/products/live-available-replacement',
        ])]);
        Http::fake(function ($request) use ($soldOut, $replacement) {
            $soldOutUrl = data_get($soldOut->metadata, 'product_url');
            $replacementUrl = data_get($replacement->metadata, 'product_url');
            if ($request->url() === $soldOutUrl) {
                return Http::response($this->storefrontCard($soldOut->name, $soldOutUrl, false), 200, ['Content-Type' => 'text/html']);
            }
            if ($request->url() === $replacementUrl) {
                return Http::response($this->storefrontCard($replacement->name, $replacementUrl, true), 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('image', 200, ['Content-Type' => 'image/jpeg']);
        });

        $availableCard = app(KnowledgeIngestionService::class)->storefrontProductsFromHtml(
            $this->storefrontCard($replacement->name, data_get($replacement->metadata, 'product_url'), true),
            'https://shop.example',
        );
        $this->assertSame(1, data_get($availableCard, '0.stock'));
        $safeIds = app(SocialMediaScheduler::class)->prepareDueSlot($schedule->fresh('agent'), $post->scheduled_for);

        $this->assertSame([$post->id], $safeIds);
        $this->assertSame(0, $soldOut->fresh()->stock);
        $this->assertSame($replacement->id, $post->fresh()->product_id);
    }

    public function test_new_schedules_do_not_reuse_products_that_are_reserved_by_another_schedule(): void
    {
        [$user, $agent] = $this->tenant('cross-schedule-product-rotation');
        $this->connections($agent);
        foreach (range(1, 3) as $number) {
            $agent->products()->create($this->product("History Product {$number}", 'General', 2));
        }
        $payload = [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['facebook', 'instagram'],
            'timezone' => 'Asia/Tbilisi',
        ];

        $chosenProducts = collect();
        foreach (range(1, 3) as $iteration) {
            $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
            $schedule = $agent->socialMediaSchedules()->latest('id')->firstOrFail();
            $this->assertSame(1, $schedule->posts()->pluck('product_id')->unique()->count());
            $chosenProducts->push((int) $schedule->posts()->value('product_id'));
        }

        $this->assertCount(3, $chosenProducts->unique());
        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $waiting = $agent->socialMediaSchedules()->latest('id')->firstOrFail();
        $this->assertTrue($waiting->posts()->whereNotNull('product_id')->doesntExist());
        $this->assertSame(4, $agent->socialMediaSchedules()->count());
    }

    public function test_deleting_a_schedule_keeps_history_and_reuses_only_after_full_catalog_exhaustion(): void
    {
        [$user, $agent] = $this->tenant('durable-publication-history');
        $this->connections($agent);
        $original = $agent->products()->create($this->product('Durable Product', 'General', 2));
        $payload = [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ];

        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $post = $schedule->posts()->firstOrFail();
        $post->update([
            'status' => 'published',
            'published_at' => now(),
            'provider_post_id' => 'historical-instagram-post',
        ]);

        $this->actingAs($user)->delete(route('social-media.destroy', $schedule))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseHas('social_publication_identities', [
            'agent_id' => $agent->id,
            'provider' => 'instagram',
            'status' => 'published',
            'provider_post_id' => 'historical-instagram-post',
        ]);

        $url = data_get($original->metadata, 'product_url');
        $original->delete();
        $reimported = $agent->products()->create($this->product('Reimported Product Record', 'General', 2));
        $metadata = $reimported->metadata;
        $metadata['product_url'] = $url;
        $reimported->update(['metadata' => $metadata]);

        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $newSchedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertSame($reimported->id, $newSchedule->posts()->value('product_id'));
        $this->assertDatabaseHas('social_publication_cycles', [
            'agent_id' => $agent->id,
            'provider' => 'instagram',
            'current_cycle' => 2,
        ]);
    }

    public function test_an_incomplete_catalog_sync_cannot_trigger_a_new_rotation_cycle(): void
    {
        [$user, $agent] = $this->tenant('incomplete-catalog-cycle');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'source_scope' => 'catalog',
            'name' => 'Incomplete catalog',
            'url' => 'https://shop.example/catalog',
            'status' => 'ready',
            'progress' => 64,
            'error' => 'Website synchronization stopped safely.',
        ]);
        $agent->products()->create($this->product('Only Indexed Product', 'General', 2));
        $payload = [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ];

        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $firstPost = $agent->socialMediaPosts()->firstOrFail();
        $firstPost->update(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $secondSchedule = $agent->socialMediaSchedules()->latest('id')->firstOrFail();

        $this->assertNull($secondSchedule->posts()->value('product_id'));
        $this->assertDatabaseCount('social_publication_cycles', 0);
    }

    public function test_storefront_image_choice_is_visible_only_when_the_primary_image_contract_is_available(): void
    {
        [$user, $agent] = $this->tenant('storefront-image-choice');
        $product = $agent->products()->create($this->product('Storefront Product', 'General', 2));
        $this->mock(ProductPagePrimaryImageResolver::class)
            ->shouldReceive('resolve')
            ->once()
            ->withArgs(fn ($resolvedProduct): bool => $resolvedProduct->is($product))
            ->andReturn('https://shop.example/storage/books/exact-thumb-image.jpg');
        Http::fake(['https://shop.example/images/*' => Http::response('not-an-image')]);

        $this->actingAs($user)->get(route('social-media.index'))
            ->assertOk()
            ->assertSee('3D catalog design')
            ->assertSee('Catalog design')
            ->assertSee('Storefront image')
            ->assertSee('https://shop.example/storage/books/exact-thumb-image.jpg');
    }

    public function test_selected_image_design_is_rendered_as_a_public_cached_jpeg(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for social image rendering.');
        }

        Storage::fake('public');
        $source = imagecreatetruecolor(2, 2);
        imagefill($source, 0, 0, imagecolorallocate($source, 210, 80, 50));
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        Http::fake(['https://shop.example/product.png' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $url = app(SocialMediaImageDesigner::class)->render('https://shop.example/product.png', 'original');

        $this->assertStringContainsString('/media/social/', $url);
        $files = Storage::disk('public')->files('social-media');
        $this->assertCount(1, $files);
        $this->assertSame("\xFF\xD8", substr(Storage::disk('public')->get($files[0]), 0, 2));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('https://shop.example/product.png', app(SocialMediaImageDesigner::class)->render('https://shop.example/product.png', 'raw'));
    }

    public function test_catalog_design_renderer_is_used_by_preview_and_scheduled_posts(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for social image rendering.');
        }

        Storage::fake('public');
        [$user, $agent] = $this->tenant('catalog-design-rendering');
        $this->connections($agent);
        $agent->products()->create($this->product('Designed Product', 'General', 2));
        // A square crop can still be a flat cover photo. It must not be
        // mistaken for an already prepared square catalog composition.
        $source = imagecreatetruecolor(400, 400);
        imagefill($source, 0, 0, imagecolorallocate($source, 196, 172, 138));
        imagefilledrectangle($source, 115, 20, 285, 380, imagecolorallocate($source, 66, 69, 59));
        imagefilledrectangle($source, 125, 30, 275, 370, imagecolorallocate($source, 196, 172, 138));
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        Http::fake([
            'https://shop.example/images/product.jpg' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('', 404),
        ]);

        $this->actingAs($user)->get(route('social-media.index'))
            ->assertOk()
            ->assertSee('/media/social/', false);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['facebook'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('/media/social/', (string) $agent->socialMediaPosts()->sole()->image_url);
        $rendered = imagecreatefromstring(Storage::disk('public')->get(Storage::disk('public')->files('social-media')[0]));
        $leftBackground = imagecolorat($rendered, 250, 540);
        imagedestroy($rendered);
        $this->assertGreaterThan(220, ($leftBackground >> 16) & 0xFF);
        $this->assertGreaterThan(220, ($leftBackground >> 8) & 0xFF);
        $this->assertGreaterThan(220, $leftBackground & 0xFF);
    }

    public function test_catalog_design_keeps_an_already_prepared_square_artwork_intact(): void
    {
        $source = imagecreatetruecolor(400, 400);
        imagefilledrectangle($source, 0, 0, 399, 399, imagecolorallocate($source, 248, 248, 246));
        imagefilledrectangle($source, 120, 35, 360, 365, imagecolorallocate($source, 80, 60, 45));
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        Http::fake(['https://shop.example/prepared.png' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $url = app(SocialMediaImageDesigner::class)->render('https://shop.example/prepared.png', 'original');

        $this->assertSame('https://shop.example/prepared.png', $url);
    }

    public function test_three_d_catalog_design_remains_available_for_prepared_square_artwork(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for social image rendering.');
        }

        Storage::fake('public');
        $source = imagecreatetruecolor(400, 400);
        imagefilledrectangle($source, 0, 0, 399, 399, imagecolorallocate($source, 248, 248, 246));
        imagefilledrectangle($source, 120, 35, 360, 365, imagecolorallocate($source, 80, 60, 45));
        ob_start();
        imagepng($source);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        Http::fake(['https://shop.example/prepared-3d.png' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $url = app(SocialMediaImageDesigner::class)->render('https://shop.example/prepared-3d.png', 'three_d');

        $this->assertStringContainsString('/media/social/', $url);
        $this->assertCount(1, Storage::disk('public')->files('social-media'));
    }

    public function test_storefront_image_style_uses_the_exact_primary_image_when_the_product_page_exposes_it(): void
    {
        [$user, $agent] = $this->tenant('storefront-primary-image');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Primary Gallery Product', 'General', 2));
        $metadata = $product->metadata;
        $metadata['image'] = 'https://shop.example/images/generated-catalog-design.jpg';
        $product->update(['metadata' => $metadata]);
        $this->mock(ProductPagePrimaryImageResolver::class)
            ->shouldReceive('resolve')
            ->once()
            ->withArgs(fn ($resolvedProduct, $language): bool => $resolvedProduct->is($product) && $language === null)
            ->andReturn('https://shop.example/storage/books/exact-thumb-image.jpg');
        Http::fake(['https://shop.example/images/*' => Http::response('not-an-image')]);

        $this->actingAs($user)->put(route('social-media.templates.update'), [
            'templates' => [
                'facebook' => [
                    'body_template' => '{product_title} {product_url}',
                    'delivery_enabled' => false,
                    'image_style' => 'storefront',
                ],
                'instagram' => [
                    'body_template' => '{product_title} {product_url}',
                    'delivery_enabled' => false,
                    'image_style' => 'original',
                ],
            ],
        ])->assertRedirect(route('social-media.index'));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['facebook'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'));

        $post = $agent->socialMediaPosts()->sole();
        $this->assertSame('https://shop.example/storage/books/exact-thumb-image.jpg', $post->image_url);
    }

    public function test_styled_images_use_the_plain_storefront_photo_instead_of_catalog_design(): void
    {
        [$user, $agent] = $this->tenant('storefront-primary-image-framed');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Framed Gallery Product', 'General', 2));
        $metadata = $product->metadata;
        $metadata['image'] = 'https://shop.example/images/generated-catalog-design.jpg';
        $product->update(['metadata' => $metadata]);
        $this->mock(ProductPagePrimaryImageResolver::class)
            ->shouldReceive('resolve')
            ->once()
            ->withArgs(fn ($resolvedProduct, $language): bool => $resolvedProduct->is($product) && $language === null)
            ->andReturn('https://shop.example/images/plain-storefront-photo.jpg');
        Http::fake(['https://shop.example/images/*' => Http::response('not-an-image')]);

        $this->actingAs($user)->put(route('social-media.templates.update'), [
            'templates' => [
                'facebook' => [
                    'body_template' => '{product_title} {product_url}',
                    'delivery_enabled' => false,
                    'image_style' => 'framed',
                ],
                'instagram' => [
                    'body_template' => '{product_title} {product_url}',
                    'delivery_enabled' => false,
                    'image_style' => 'original',
                ],
            ],
        ])->assertRedirect(route('social-media.index'));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['facebook'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'));

        $this->assertSame(
            'https://shop.example/images/plain-storefront-photo.jpg',
            $agent->socialMediaPosts()->sole()->image_url,
        );
    }

    public function test_business_saves_and_snapshots_distinct_facebook_and_instagram_templates(): void
    {
        [$user, $agent] = $this->tenant('channel-templates');
        $this->connections($agent);
        $agent->products()->create($this->product('Template Product', 'General', 3));

        $this->actingAs($user)->put(route('social-media.templates.update'), [
            'templates' => [
                'facebook' => [
                    'body_template' => "📘 {product_title}\n{product_description}\n🚚 {delivery}\nBuy: {product_url}",
                    'delivery_enabled' => true,
                    'image_style' => 'framed',
                    'delivery_text' => 'Delivery in 1–2 business days.',
                ],
                'instagram' => [
                    'body_template' => "📸 {product_title}\n{category}\n🚚 {delivery}\nDetails: {product_url}",
                    'delivery_enabled' => false,
                    'image_style' => 'brand',
                    'delivery_text' => 'This must not appear.',
                ],
            ],
        ])->assertRedirect(route('social-media.index'));

        $this->assertDatabaseHas('social_media_templates', [
            'agent_id' => $agent->id,
            'provider' => 'facebook',
            'version' => 1,
            'delivery_enabled' => true,
            'image_style' => 'framed',
        ]);
        $this->assertDatabaseHas('social_media_templates', [
            'agent_id' => $agent->id,
            'provider' => 'instagram',
            'version' => 1,
            'delivery_enabled' => false,
            'image_style' => 'brand',
        ]);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['facebook', 'instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'));

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $facebook = $schedule->posts()->where('provider', 'facebook')->firstOrFail();
        $instagram = $schedule->posts()->where('provider', 'instagram')->firstOrFail();
        $this->assertStringContainsString('📘 Template Product', $facebook->caption);
        $this->assertStringContainsString('Delivery in 1–2 business days.', $facebook->caption);
        $this->assertStringContainsString('📸 Template Product', $instagram->caption);
        $this->assertStringNotContainsString('This must not appear.', $instagram->caption);
        $this->assertSame(1, data_get($schedule->template_snapshots, 'facebook.version'));
        $this->assertSame(1, data_get($schedule->template_snapshots, 'instagram.version'));
        $this->assertSame('framed', data_get($schedule->template_snapshots, 'facebook.image_style'));
        $this->assertSame('brand', data_get($schedule->template_snapshots, 'instagram.image_style'));
    }

    public function test_template_editor_rejects_unknown_fields_and_is_tenant_scoped(): void
    {
        [$firstUser, $firstAgent] = $this->tenant('template-first');
        [$secondUser] = $this->tenant('template-second');

        $invalid = [
            'templates' => [
                'facebook' => ['body_template' => '{product_title} {secret_token} {product_url}', 'delivery_enabled' => false],
                'instagram' => ['body_template' => '{product_title} {product_url}', 'delivery_enabled' => false],
            ],
        ];
        $this->actingAs($firstUser)->from(route('social-media.index'))->put(route('social-media.templates.update'), $invalid)
            ->assertRedirect(route('social-media.index'))->assertSessionHasErrors('templates.facebook.body_template');
        $this->assertDatabaseCount('social_media_templates', 0);

        $valid = $invalid;
        $valid['templates']['facebook']['body_template'] = 'FIRST-TENANT {product_title} {product_url}';
        $this->actingAs($firstUser)->put(route('social-media.templates.update'), $valid)
            ->assertRedirect(route('social-media.index'));

        $this->actingAs($secondUser)->get(route('social-media.index'))
            ->assertOk()->assertDontSee('FIRST-TENANT');
        $this->assertSame(2, $firstAgent->socialMediaTemplates()->count());
    }

    public function test_viewer_cannot_change_social_templates(): void
    {
        [, $agent] = $this->tenant('template-viewer');
        $viewer = User::factory()->create();
        $agent->organization->users()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->put(route('social-media.templates.update'), $this->templatePayload('VIEWER'))
            ->assertForbidden();

        $this->assertDatabaseCount('social_media_templates', 0);
    }

    public function test_template_changes_do_not_mutate_existing_schedule_captions(): void
    {
        [$user, $agent] = $this->tenant('template-snapshot');
        $this->connections($agent);
        $agent->products()->create($this->product('Snapshot Product', 'General', 2));
        $payload = $this->templatePayload('ORIGINAL');
        $this->actingAs($user)->put(route('social-media.templates.update'), $payload);
        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['facebook'], 'timezone' => 'UTC',
        ]);
        $post = $agent->socialMediaPosts()->firstOrFail();
        $this->assertStringContainsString('ORIGINAL', $post->caption);

        $this->actingAs($user)->put(route('social-media.templates.update'), $this->templatePayload('CHANGED'));
        $this->assertStringContainsString('ORIGINAL', $post->fresh()->caption);
        $this->assertStringNotContainsString('CHANGED', $post->fresh()->caption);
        $this->assertSame(1, data_get($post->schedule->template_snapshots, 'facebook.version'));
        $this->assertSame(2, $agent->socialMediaTemplates()->where('provider', 'facebook')->value('version'));
    }

    public function test_instagram_schedule_waits_for_a_publishable_product_instead_of_stopping(): void
    {
        [$user, $agent] = $this->tenant('missing-image');
        $this->connections($agent);
        $attributes = $this->product('No Image Product', 'General', 2);
        $attributes['image'] = null;
        $agent->products()->create($attributes);

        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['instagram'], 'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'))->assertSessionHasNoErrors();
        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertDatabaseHas('social_media_posts', [
            'social_media_schedule_id' => $schedule->id,
            'provider' => 'instagram',
            'status' => 'scheduled',
            'product_id' => null,
        ]);
    }

    public function test_a_waiting_slot_skips_an_invalid_product_and_uses_it_after_it_becomes_publishable(): void
    {
        [$user, $agent] = $this->tenant('waiting-slot-recovery');
        $this->connections($agent);
        $attributes = $this->product('Temporarily Invalid Product', 'General', 2);
        $attributes['image'] = null;
        $product = $agent->products()->create($attributes);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertSessionHasNoErrors();

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $post = $schedule->posts()->firstOrFail();
        $this->assertNull($post->product_id);

        $product->update(['image' => 'https://shop.example/images/recovered.jpg']);
        $safeIds = app(SocialMediaScheduler::class)
            ->prepareDueSlot($schedule->fresh('agent'), $post->scheduled_for);

        $this->assertSame([$post->id], $safeIds);
        $this->assertSame($product->id, $post->fresh()->product_id);
    }

    public function test_multi_channel_schedule_uses_only_products_eligible_for_every_selected_provider(): void
    {
        [$user, $agent] = $this->tenant('provider-eligibility');
        $this->connections($agent);
        $withoutImage = $this->product('Facebook Link Product', 'General', 2);
        $withoutImage['image'] = null;
        $facebookOnlyProduct = $agent->products()->create($withoutImage);
        $sharedProduct = $agent->products()->create($this->product('Shared Image Product', 'General', 2));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'));

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertNotContains($facebookOnlyProduct->id, $schedule->posts()->pluck('product_id'));
        $this->assertSame(
            [$sharedProduct->id],
            $schedule->posts()->pluck('product_id')->unique()->values()->all(),
        );
    }

    public function test_business_can_schedule_exact_daily_times_in_its_timezone(): void
    {
        [$user, $agent] = $this->tenant('custom-posting-times');
        $this->connections($agent);
        $agent->products()->create($this->product('Timed Product One', 'General', 3));
        $agent->products()->create($this->product('Timed Product Two', 'General', 3));
        $date = CarbonImmutable::now('Asia/Tbilisi')->addDay()->toDateString();

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => $date,
            'ends_on' => $date,
            'posts_per_day' => 2,
            'providers' => ['instagram'],
            'timezone' => 'Asia/Tbilisi',
            'timing_mode' => 'custom',
            'posting_times' => ['14:30', '02:55'],
        ])->assertRedirect(route('social-media.index'));

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertSame(['02:55', '14:30'], $schedule->posting_times);
        $this->assertSame([
            CarbonImmutable::parse("{$date} 02:55", 'Asia/Tbilisi')->utc()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse("{$date} 14:30", 'Asia/Tbilisi')->utc()->format('Y-m-d H:i:s'),
        ], $schedule->posts()->orderBy('scheduled_for')->get()->map(
            fn (SocialMediaPost $post) => $post->getRawOriginal('scheduled_for')
        )->all());

        $this->actingAs($user)->get(route('social-media.index'))
            ->assertOk()
            ->assertSee('02:55, 14:30')
            ->assertSee('02:55 Asia/Tbilisi')
            ->assertSee('14:30 Asia/Tbilisi');
    }

    public function test_custom_daily_times_must_match_post_count_and_be_unique(): void
    {
        [$user, $agent] = $this->tenant('invalid-posting-times');
        $this->connections($agent);
        $agent->products()->create($this->product('Validation Product', 'General', 3));
        $date = CarbonImmutable::now('Asia/Tbilisi')->addDay()->toDateString();

        $payload = [
            'starts_on' => $date, 'ends_on' => $date, 'posts_per_day' => 2,
            'providers' => ['facebook'], 'timezone' => 'Asia/Tbilisi',
            'timing_mode' => 'custom', 'posting_times' => ['10:00'],
        ];
        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), $payload)
            ->assertRedirect(route('social-media.index'))->assertSessionHasErrors('posting_times');

        $payload['posting_times'] = ['10:00', '10:00'];
        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), $payload)
            ->assertRedirect(route('social-media.index'))->assertSessionHasErrors('posting_times');
        $this->assertDatabaseCount('social_media_schedules', 0);
    }

    public function test_ai_copywriter_uses_original_content_after_seven_product_slots_per_business_day(): void
    {
        [$user, $agent] = $this->tenant('ai-daily-limit');
        $this->connections($agent);
        foreach (range(1, 7) as $number) {
            $agent->products()->create($this->product("AI Product {$number}", 'General', 5));
        }
        $date = CarbonImmutable::now('Asia/Tbilisi')->addDay()->toDateString();

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => $date, 'ends_on' => $date, 'posts_per_day' => 7,
            'providers' => ['facebook'], 'timezone' => 'Asia/Tbilisi',
            'copy_mode' => 'ai', 'ai_tone' => 'creative',
        ])->assertRedirect(route('social-media.index'));

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertSame('ai', $schedule->copy_mode);
        $this->assertSame('creative', $schedule->ai_tone);
        $this->assertCount(7, $schedule->posts);
        $this->assertSame(7, $schedule->posts->where('copy_mode', 'ai')->count());

        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), [
            'starts_on' => $date, 'ends_on' => $date, 'posts_per_day' => 1,
            'providers' => ['instagram'], 'timezone' => 'Asia/Tbilisi',
            'copy_mode' => 'ai', 'ai_tone' => 'simple',
        ])->assertRedirect(route('social-media.index'))->assertSessionHasNoErrors();

        $this->assertSame(2, $agent->socialMediaSchedules()->count());
        $this->assertSame('original', $agent->socialMediaPosts()->latest('id')->firstOrFail()->copy_mode);
    }

    public function test_ai_copywriter_keeps_an_oversized_schedule_and_keeps_both_channels_in_the_same_mode(): void
    {
        [$user, $agent] = $this->tenant('ai-request-limit');
        $this->connections($agent);
        foreach (range(1, 8) as $number) {
            $agent->products()->create($this->product("AI Product {$number}", 'General', 5));
        }

        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 8, 'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi',
            'copy_mode' => 'ai', 'ai_tone' => 'academic',
        ])->assertRedirect(route('social-media.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('social_media_schedules', 1);
        $this->assertSame(14, $agent->socialMediaPosts()->where('copy_mode', 'ai')->count());
        $this->assertSame(2, $agent->socialMediaPosts()->where('copy_mode', 'original')->count());
        $this->assertTrue($agent->socialMediaPosts()->get()->groupBy('scheduled_for')->every(
            fn ($slotPosts): bool => $slotPosts->pluck('copy_mode')->unique()->count() === 1,
        ));
    }

    public function test_luna_generates_a_verified_image_aware_caption_once_before_meta_publish(): void
    {
        [, $agent] = $this->tenant('ai-publisher');
        $this->connections($agent);
        config()->set('services.openai.key', 'test-openai-key');
        config()->set('services.openai.social_media_model', 'gpt-5.6-luna');
        $product = $agent->products()->create($this->product('Luna Product', 'General', 2));
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'UTC', 'status' => 'active',
            'copy_mode' => 'ai', 'ai_tone' => 'academic',
        ]);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => 'facebook',
            'status' => 'queued', 'scheduled_for' => now(), 'title' => $product->name,
            'description' => $product->description, 'product_url' => data_get($product->metadata, 'product_url'),
            'image_url' => $product->publicImageUrl(), 'caption' => 'Prepared fallback caption',
        ]);
        $layout = json_encode([
            'creative_copy' => 'A thoughtful new way to discover this catalog selection.',
            'selected_facts' => ['category', 'price'],
            'cta' => 'details',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'output' => [['content' => [['type' => 'output_text', 'text' => $layout]]]],
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
                ])
                ->push([
                    'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                        'supported' => true, 'unsupported_fragments' => [],
                    ])]]]],
                    'usage' => ['input_tokens' => 50, 'output_tokens' => 8],
                ]),
            'https://graph.facebook.test/*/photos*' => Http::response(['id' => 'ai-facebook-post']),
        ]);

        (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $fresh = $post->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertStringContainsString('A thoughtful new way', $fresh->caption);
        $this->assertStringContainsString('Luna Product', $fresh->caption);
        $this->assertStringNotContainsString('Verified public description.', $fresh->caption);
        $this->assertStringContainsString('20.00 GEL', $fresh->caption);
        $this->assertStringContainsString((string) data_get($product->metadata, 'product_url'), $fresh->caption);
        $this->assertSame('gpt-5.6-luna', $fresh->ai_model);
        $this->assertNotNull($fresh->ai_generation_attempted_at);
        $this->assertNotNull($fresh->ai_generated_at);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-5.6-luna'
            && data_get($request->data(), 'input.0.content.1.type') === 'input_image'
            && str_contains((string) data_get($request->data(), 'input.0.content.0.text'), 'Write original, channel-specific creative_copy'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/page-1/photos')
            && str_contains((string) $request['caption'], 'Luna Product'));
    }

    public function test_failed_ai_generation_falls_back_to_original_content_without_repeating_ai(): void
    {
        [, $agent] = $this->tenant('ai-generation-failure');
        $this->connections($agent);
        config()->set('services.openai.key', 'test-openai-key');
        $product = $agent->products()->create($this->product('Failure Product', 'General', 1));
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'UTC', 'status' => 'active',
            'copy_mode' => 'ai', 'ai_tone' => 'simple',
        ]);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => 'facebook',
            'status' => 'queued', 'scheduled_for' => now(), 'title' => $product->name,
            'description' => $product->description, 'product_url' => data_get($product->metadata, 'product_url'),
            'image_url' => $product->publicImageUrl(), 'caption' => 'Prepared fallback caption',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Unavailable']], 503),
            'https://graph.facebook.test/*/photos*' => Http::response(['id' => 'fallback-facebook-post']),
        ]);

        $job = new PublishSocialMediaPost($post->id);
        $job->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));
        $job->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('Prepared fallback caption', $post->fresh()->caption);
        $this->assertNotNull($post->fresh()->ai_generation_attempted_at);
        $this->assertNull($post->fresh()->ai_generated_at);
        Http::assertSentCount(2);
    }

    public function test_ai_copywriter_can_only_render_exact_catalog_values_for_any_product_type(): void
    {
        [, $agent] = $this->tenant('verified-ai-facts');
        config()->set('services.openai.key', 'test-openai-key');
        $attributes = $this->product('Artisan Cheese', 'Dairy', 4);
        $attributes['metadata']['manufacturer'] = 'Verified Farm';
        $attributes['metadata']['attributes'] = ['Milk: Goat', 'Weight: 500 g'];
        $product = $agent->products()->create($attributes);
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'UTC', 'status' => 'active',
            'copy_mode' => 'ai', 'ai_tone' => 'creative',
        ]);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => 'facebook',
            'status' => 'queued', 'scheduled_for' => now(), 'title' => $product->name,
            'description' => $product->description, 'product_url' => data_get($product->metadata, 'product_url'),
            'image_url' => $product->publicImageUrl(), 'caption' => 'Fallback', 'language' => 'English',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                        'creative_copy' => 'Bring something memorable to the table. Invented Dairy Ltd made this from cow milk.',
                        'selected_facts' => ['attribute_0', 'attribute_1'],
                        'cta' => 'visit',
                    ])]]]],
                    'usage' => ['input_tokens' => 80, 'output_tokens' => 10],
                ])
                ->push([
                    'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                        'supported' => false,
                        'unsupported_fragments' => ['Invented Dairy Ltd made this from cow milk.'],
                    ])]]]],
                    'usage' => ['input_tokens' => 40, 'output_tokens' => 8],
                ])
                ->push([
                    'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                        'creative_copy' => 'აღმოააჩინეთ კატალოგის გამორჩეული წიგნი. პიტერ ჰანდკეს ამ ნაწარმოებისთვის ნობელის პრემია აქვს.',
                        'selected_facts' => ['author'],
                        'cta' => 'details',
                    ])]]]],
                    'usage' => ['input_tokens' => 80, 'output_tokens' => 10],
                ])
                ->push([
                    'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                        'supported' => false,
                        'unsupported_fragments' => ['პიტერ ჰანდკეს ამ ნაწარმოებისთვის ნობელის პრემია აქვს.'],
                    ])]]]],
                    'usage' => ['input_tokens' => 40, 'output_tokens' => 8],
                ]),
        ]);

        $caption = app(SocialMediaAiCopywriter::class)->generate($post);

        $this->assertStringContainsString('Artisan Cheese', $caption);
        $this->assertStringContainsString('Manufacturer: Verified Farm', $caption);
        $this->assertStringContainsString('Milk: Goat', $caption);
        $this->assertStringContainsString('Weight: 500 g', $caption);
        $this->assertStringContainsString('Bring something memorable to the table.', $caption);
        $this->assertStringNotContainsString('Invented Dairy Ltd', $caption);
        $this->assertStringNotContainsString('cow milk', $caption);

        $bookAttributes = $this->product('პანსიონატი', 'რომანი', 1);
        $bookAttributes['metadata']['author'] = 'პიოტრ პაჟინსკი';
        $book = $agent->products()->create($bookAttributes);
        $bookPost = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $book->id, 'provider' => 'facebook',
            'status' => 'queued', 'scheduled_for' => now()->addMinute(), 'title' => $book->name,
            'description' => $book->description, 'product_url' => data_get($book->metadata, 'product_url'),
            'image_url' => $book->publicImageUrl(), 'caption' => 'Fallback', 'language' => 'Georgian',
        ]);
        $bookCaption = app(SocialMediaAiCopywriter::class)->generate($bookPost);
        $this->assertStringContainsString('პანსიონატი', $bookCaption);
        $this->assertStringContainsString('ავტორი: პიოტრ პაჟინსკი', $bookCaption);
        $this->assertStringContainsString('აღმოააჩინეთ კატალოგის გამორჩეული წიგნი.', $bookCaption);
        $this->assertStringNotContainsString('პიტერ ჰანდკე', $bookCaption);

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'text.format.schema.properties.creative_copy.type') === 'string'
            && in_array('manufacturer', data_get($request->data(), 'text.format.schema.properties.selected_facts.items.enum', []), true)
            && in_array('attribute_0', data_get($request->data(), 'text.format.schema.properties.selected_facts.items.enum', []), true));
    }

    public function test_social_schedule_filters_and_snapshots_the_selected_website_language(): void
    {
        [$user, $agent] = $this->tenant('localized-social-post');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Russian',
            'name' => 'Website language: Russian', 'url' => 'https://shop.example/?lang=ru',
            'status' => 'ready', 'progress' => 100,
        ]);
        $attributes = $this->product('ქართული წიგნი', 'Books', 3);
        $attributes['metadata']['localized'] = [
            'Russian' => [
                'name' => 'Русская книга', 'category' => 'Книги', 'description' => 'Русское описание.',
                'product_url' => 'https://shop.example/ru/book', 'image' => 'https://shop.example/images/russian-book.jpg',
            ],
        ];
        $attributes['metadata']['languages'] = ['Russian'];
        $agent->products()->create($attributes);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['instagram'], 'timezone' => 'Asia/Tbilisi',
            'languages' => ['Russian'],
        ])->assertRedirect(route('social-media.index'));

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $post = $schedule->posts()->sole();
        $this->assertSame(['Russian'], $schedule->languages);
        $this->assertSame('Russian', $post->language);
        $this->assertSame('Русская книга', $post->title);
        $this->assertSame('Русское описание.', $post->description);
        $this->assertSame('https://shop.example/ru/book', $post->product_url);
        $this->assertSame('https://shop.example/images/product.jpg', $post->image_url);
        $this->assertStringContainsString('Русская книга', $post->caption);

        Http::fake([
            'https://graph.facebook.test/*/media*' => Http::sequence()->push(['id' => 'localized-container'])->push(['id' => 'localized-post']),
        ]);
        $post->update(['status' => 'queued']);
        (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig-1/media?')
            && $request['image_url'] === 'https://shop.example/images/product.jpg'
            && str_contains((string) $request['caption'], 'Русская книга'));
        $this->assertSame('published', $post->fresh()->status);

        $this->actingAs($user)->get(route('social-media.index'))->assertOk()
            ->assertSee('Website languages')->assertSee('Russian');
    }

    public function test_primary_website_language_uses_the_base_catalog_record_without_a_duplicate_localization(): void
    {
        [$user, $agent] = $this->tenant('primary-language-base-catalog');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Primary language',
            'name' => 'Website language: Primary language', 'url' => 'https://shop.example',
            'status' => 'ready', 'progress' => 100,
        ]);
        $product = $agent->products()->create($this->product('Base Catalog Product', 'Books', 3));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi',
            'languages' => ['Primary language'],
        ])->assertSessionHasNoErrors();

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertSame(['Primary language'], $schedule->languages);
        $this->assertCount(2, $schedule->posts);
        $this->assertSame([$product->id], $schedule->posts->pluck('product_id')->unique()->values()->all());
        $this->assertTrue($schedule->posts->every(fn ($post): bool => $post->language === 'Primary language'));
        $this->assertTrue($schedule->posts->every(fn ($post): bool => $post->title === 'Base Catalog Product'));
        $this->assertTrue($schedule->posts->every(fn ($post): bool => $post->description === 'Verified public description.'));
    }

    public function test_existing_primary_language_placeholders_resolve_from_the_base_catalog_when_due(): void
    {
        [$user, $agent] = $this->tenant('primary-language-placeholder-recovery');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Primary language',
            'name' => 'Website language: Primary language', 'url' => 'https://shop.example',
            'status' => 'ready', 'progress' => 100,
        ]);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi',
            'languages' => ['Primary language'],
        ])->assertSessionHasNoErrors();

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertTrue($schedule->posts->every(fn ($post): bool => $post->product_id === null));
        $product = $agent->products()->create($this->product('Recovered Base Product', 'Books', 3));
        $post = $schedule->posts()->firstOrFail();

        $safeIds = app(SocialMediaScheduler::class)
            ->prepareDueSlot($schedule->fresh('agent'), $post->scheduled_for);

        $this->assertEqualsCanonicalizing($schedule->posts()->pluck('id')->all(), $safeIds);
        $this->assertTrue($schedule->posts()->get()->every(
            fn ($scheduledPost): bool => $scheduledPost->product_id === $product->id
                && $scheduledPost->language === 'Primary language'
                && $scheduledPost->title === 'Recovered Base Product',
        ));
    }

    public function test_primary_language_does_not_include_a_product_owned_by_a_secondary_language_source(): void
    {
        [$user, $agent] = $this->tenant('primary-language-excludes-secondary-source');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Primary language',
            'name' => 'Website language: Primary language', 'url' => 'https://shop.example',
            'status' => 'ready', 'progress' => 100,
        ]);
        $secondarySource = $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Secondary language',
            'name' => 'Website language: Secondary language', 'url' => 'https://shop.example/secondary',
            'status' => 'ready', 'progress' => 100,
        ]);
        $attributes = $this->product('Secondary Language Product', 'Books', 3);
        $attributes['metadata']['source_id'] = $secondarySource->id;
        $attributes['metadata']['source_url'] = $secondarySource->url;
        $attributes['metadata']['languages'] = ['Secondary language'];
        $attributes['metadata']['localized'] = [
            'Secondary language' => [
                'name' => 'Secondary Language Product',
                'description' => 'Secondary language description.',
                'product_url' => 'https://shop.example/secondary/product',
                'image' => 'https://shop.example/images/secondary-product.jpg',
            ],
        ];
        $secondaryProduct = $agent->products()->create($attributes);

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['facebook', 'instagram'], 'timezone' => 'Asia/Tbilisi',
            'languages' => ['Primary language'],
        ])->assertSessionHasNoErrors();

        $posts = $agent->socialMediaPosts()->get();
        $this->assertTrue($posts->every(fn ($post): bool => $post->product_id === null));
        $this->assertNotContains($secondaryProduct->id, $posts->pluck('product_id')->all());
    }

    public function test_due_slot_replaces_a_secondary_language_product_assigned_to_the_primary_language(): void
    {
        [, $agent] = $this->tenant('due-language-correction');
        $this->connections($agent);
        $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Primary language',
            'name' => 'Website language: Primary language', 'url' => 'https://shop.example',
            'status' => 'ready', 'progress' => 100,
        ]);
        $secondarySource = $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'language', 'taxonomy_label' => 'Secondary language',
            'name' => 'Website language: Secondary language', 'url' => 'https://shop.example/secondary',
            'status' => 'ready', 'progress' => 100,
        ]);
        $secondaryAttributes = $this->product('Wrong Language Product', 'General', 3);
        $secondaryAttributes['metadata']['source_id'] = $secondarySource->id;
        $secondaryAttributes['metadata']['localized'] = [
            'Secondary language' => ['name' => 'Wrong Language Product'],
        ];
        $secondary = $agent->products()->create($secondaryAttributes);
        $primary = $agent->products()->create($this->product('Correct Primary Product', 'General', 3));
        $schedule = $this->replacementSchedule($agent, ['facebook', 'instagram']);
        $schedule->update(['languages' => ['Primary language']]);
        $dueAt = now('UTC')->subMinute();
        foreach (['facebook', 'instagram'] as $provider) {
            $schedule->posts()->create([
                'agent_id' => $agent->id, 'product_id' => $secondary->id, 'provider' => $provider,
                'language' => 'Primary language', 'status' => 'scheduled', 'scheduled_for' => $dueAt,
                'title' => $secondary->name, 'description' => $secondary->description,
                'product_url' => data_get($secondary->metadata, 'product_url'),
                'image_url' => $secondary->publicImageUrl(), 'caption' => 'Wrong language caption',
            ]);
        }

        $safeIds = app(SocialMediaScheduler::class)->prepareDueSlot($schedule->fresh('agent'), $dueAt);

        $this->assertEqualsCanonicalizing($schedule->posts()->pluck('id')->all(), $safeIds);
        $this->assertTrue($schedule->posts()->get()->every(
            fn ($post): bool => $post->product_id === $primary->id
                && $post->language === 'Primary language'
                && $post->title === 'Correct Primary Product',
        ));
    }

    public function test_due_posts_are_claimed_once_and_published_through_the_correct_graph_endpoints(): void
    {
        Queue::fake();
        [, $agent] = $this->tenant('publisher');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Scheduled Product', 'General', 3));
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook', 'instagram'], 'timezone' => 'UTC', 'status' => 'active',
        ]);
        foreach (['facebook', 'instagram'] as $provider) {
            $schedule->posts()->create([
                'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => $provider,
                'status' => 'scheduled', 'scheduled_for' => now('UTC')->subMinute(), 'title' => $product->name,
                'description' => $product->description, 'product_url' => data_get($product->metadata, 'product_url'),
                'image_url' => $product->publicImageUrl(), 'caption' => 'Verified caption',
            ]);
        }

        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('1 social slot queued for preparation.')->assertSuccessful();
        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('0 social slots queued for preparation.')->assertSuccessful();
        Queue::assertPushed(PrepareSocialMediaSlot::class, 1);
        Queue::pushed(PrepareSocialMediaSlot::class)->first()->handle(app(SocialMediaScheduler::class));
        Queue::assertPushed(PublishSocialMediaPost::class, 2);

        Http::fake([
            'https://graph.facebook.test/*/photos*' => Http::response(['id' => 'fb-post-1']),
            'https://graph.facebook.test/*/media*' => Http::sequence()->push(['id' => 'container-1'])->push(['id' => 'ig-post-1']),
        ]);
        foreach (SocialMediaPost::query()->get() as $post) {
            (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));
        }

        $this->assertDatabaseHas('social_media_posts', ['provider' => 'facebook', 'status' => 'published', 'provider_post_id' => 'fb-post-1']);
        $this->assertDatabaseHas('social_media_posts', ['provider' => 'instagram', 'status' => 'published', 'provider_post_id' => 'ig-post-1']);
        $this->assertDatabaseHas('social_publication_identities', [
            'agent_id' => $agent->id,
            'provider' => 'facebook',
            'status' => 'published',
            'provider_post_id' => 'fb-post-1',
        ]);
        $this->assertDatabaseHas('social_publication_identities', [
            'agent_id' => $agent->id,
            'provider' => 'instagram',
            'status' => 'published',
            'provider_post_id' => 'ig-post-1',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/page-1/photos')
            && $request['url'] === 'https://shop.example/images/product.jpg'
            && str_contains((string) $request['caption'], 'https://shop.example/products/scheduled-product'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-1/media?') && $request['image_url'] === 'https://shop.example/images/product.jpg');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-1/media_publish') && $request['creation_id'] === 'container-1');
    }

    public function test_stale_slot_preparation_is_recovered_and_queued_again(): void
    {
        Queue::fake();
        [, $agent] = $this->tenant('stale-slot-preparation');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Recovered Product', 'General', 3));
        $schedule = $this->replacementSchedule($agent, ['facebook']);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'provider' => 'facebook',
            'status' => 'preparing',
            'scheduled_for' => now('UTC')->subMinutes(15),
            'title' => $product->name,
            'description' => $product->description,
            'product_url' => data_get($product->metadata, 'product_url'),
            'image_url' => $product->publicImageUrl(),
            'caption' => 'Prepared caption',
        ]);
        $post->forceFill(['updated_at' => now('UTC')->subMinutes(15)])->saveQuietly();

        $this->artisan('legatus:dispatch-social-posts')
            ->expectsOutput('1 social slot queued for preparation.')
            ->assertSuccessful();

        $this->assertSame('preparing', $post->fresh()->status);
        Queue::assertPushed(PrepareSocialMediaSlot::class, 1);
    }

    public function test_due_multi_channel_slot_replaces_an_already_published_product_before_queueing(): void
    {
        Queue::fake();
        [, $agent] = $this->tenant('replace-published-due-slot');
        $this->connections($agent);
        $publishedProduct = $agent->products()->create($this->product('Published Product', 'General', 3));
        $replacement = $agent->products()->create($this->product('Unused Replacement', 'General', 3));
        $schedule = $this->replacementSchedule($agent, ['facebook', 'instagram']);
        $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $publishedProduct->id, 'provider' => 'facebook',
            'status' => 'published', 'scheduled_for' => now('UTC')->subHours(2), 'published_at' => now('UTC')->subHours(2),
            'title' => $publishedProduct->name, 'description' => $publishedProduct->description,
            'product_url' => data_get($publishedProduct->metadata, 'product_url'), 'image_url' => $publishedProduct->publicImageUrl(),
            'caption' => 'Previously published',
        ]);
        $dueAt = now('UTC')->subMinute();
        foreach (['facebook', 'instagram'] as $provider) {
            $schedule->posts()->create([
                'agent_id' => $agent->id, 'product_id' => $publishedProduct->id, 'provider' => $provider,
                'status' => 'scheduled', 'scheduled_for' => $dueAt, 'title' => $publishedProduct->name,
                'description' => $publishedProduct->description,
                'product_url' => data_get($publishedProduct->metadata, 'product_url'),
                'image_url' => $publishedProduct->publicImageUrl(), 'caption' => 'Stale caption',
            ]);
        }

        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('1 social slot queued for preparation.')->assertSuccessful();
        Queue::assertPushed(PrepareSocialMediaSlot::class, 1);
        Queue::pushed(PrepareSocialMediaSlot::class)->first()->handle(app(SocialMediaScheduler::class));

        $duePosts = $schedule->posts()->where('scheduled_for', $dueAt)->get();
        $this->assertTrue($duePosts->every(fn ($post): bool => $post->status === 'queued'));
        $this->assertSame([$replacement->id], $duePosts->pluck('product_id')->unique()->values()->all());
        $this->assertTrue($duePosts->every(fn ($post): bool => str_contains($post->caption, 'Unused Replacement')));
        Queue::assertPushed(PublishSocialMediaPost::class, 2);
    }

    public function test_due_multi_channel_slot_replaces_a_product_that_sold_out_after_scheduling(): void
    {
        Queue::fake();
        [, $agent] = $this->tenant('replace-sold-out-due-slot');
        $this->connections($agent);
        $soldOut = $agent->products()->create($this->product('Sold Out Product', 'General', 1));
        $replacement = $agent->products()->create($this->product('In Stock Replacement', 'General', 3));
        $schedule = $this->replacementSchedule($agent, ['facebook', 'instagram']);
        $dueAt = now('UTC')->subMinute();
        foreach (['facebook', 'instagram'] as $provider) {
            $schedule->posts()->create([
                'agent_id' => $agent->id, 'product_id' => $soldOut->id, 'provider' => $provider,
                'status' => 'scheduled', 'scheduled_for' => $dueAt, 'title' => $soldOut->name,
                'description' => $soldOut->description, 'product_url' => data_get($soldOut->metadata, 'product_url'),
                'image_url' => $soldOut->publicImageUrl(), 'caption' => 'Stale caption',
            ]);
        }
        $soldOut->update(['stock' => 0]);

        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('1 social slot queued for preparation.')->assertSuccessful();
        Queue::assertPushed(PrepareSocialMediaSlot::class, 1);
        Queue::pushed(PrepareSocialMediaSlot::class)->first()->handle(app(SocialMediaScheduler::class));

        $duePosts = $schedule->posts()->where('scheduled_for', $dueAt)->get();
        $this->assertTrue($duePosts->every(fn ($post): bool => $post->status === 'queued'));
        $this->assertSame([$replacement->id], $duePosts->pluck('product_id')->unique()->values()->all());
        Queue::assertPushed(PublishSocialMediaPost::class, 2);
    }

    public function test_due_slot_is_skipped_only_when_no_unused_publishable_replacement_exists(): void
    {
        Queue::fake();
        [, $agent] = $this->tenant('exhausted-due-slot');
        $soldOut = $agent->products()->create($this->product('Only Product', 'General', 0));
        $schedule = $this->replacementSchedule($agent, ['facebook']);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $soldOut->id, 'provider' => 'facebook',
            'status' => 'scheduled', 'scheduled_for' => now('UTC')->subMinute(), 'title' => $soldOut->name,
            'description' => $soldOut->description, 'product_url' => data_get($soldOut->metadata, 'product_url'),
            'image_url' => $soldOut->publicImageUrl(), 'caption' => 'Stale caption',
        ]);

        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('1 social slot queued for preparation.')->assertSuccessful();
        Queue::assertPushed(PrepareSocialMediaSlot::class, 1);
        Queue::pushed(PrepareSocialMediaSlot::class)->first()->handle(app(SocialMediaScheduler::class));

        $this->assertSame('skipped', $post->fresh()->status);
        $this->assertSame('No unused publishable product was available to replace this slot.', $post->fresh()->failure_reason);
        Queue::assertNotPushed(PublishSocialMediaPost::class);
    }

    public function test_publishing_never_erases_a_prepared_description_when_localized_data_becomes_blank(): void
    {
        [, $agent] = $this->tenant('prepared-description');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Localized Product', 'General', 1));
        $metadata = $product->metadata;
        $metadata['localized']['Georgian'] = [
            'name' => 'ლოკალიზებული პროდუქტი',
            'description' => '',
            'product_url' => data_get($metadata, 'product_url'),
        ];
        $product->update(['description' => null, 'metadata' => $metadata]);
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['instagram'], 'timezone' => 'UTC', 'status' => 'active',
            'template_snapshots' => ['instagram' => [
                'body_template' => "{product_title}\n{product_description}\n{product_url}",
                'delivery_enabled' => false, 'delivery_text' => null, 'image_style' => 'raw',
            ]],
        ]);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => 'instagram',
            'language' => 'Georgian', 'status' => 'queued', 'scheduled_for' => now(),
            'title' => 'ლოკალიზებული პროდუქტი', 'description' => 'მომზადებული სრული აღწერა.',
            'product_url' => data_get($metadata, 'product_url'), 'image_url' => $product->publicImageUrl(),
            'caption' => 'Old caption',
        ]);
        Http::fake([
            'https://graph.facebook.test/*/media*' => Http::sequence()->push(['id' => 'description-container'])->push(['id' => 'description-post']),
        ]);

        (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $this->assertSame('მომზადებული სრული აღწერა.', $post->fresh()->description);
        $this->assertStringContainsString('მომზადებული სრული აღწერა.', $post->fresh()->caption);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/media?')
            && str_contains((string) $request['caption'], 'მომზადებული სრული აღწერა.'));
    }

    public function test_schedule_actions_cannot_cross_tenant_boundaries(): void
    {
        [$firstUser, $firstAgent] = $this->tenant('first-tenant');
        [$secondUser, $secondAgent] = $this->tenant('second-tenant');
        $schedule = $firstAgent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'UTC', 'status' => 'active',
        ]);

        $this->actingAs($secondUser)->patch(route('social-media.pause', $schedule))->assertNotFound();
        $this->actingAs($secondUser)->put(route('social-media.update', $schedule), [
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'timing_mode' => 'auto',
        ])->assertNotFound();
        $this->actingAs($secondUser)->delete(route('social-media.destroy', $schedule))->assertNotFound();
        $this->assertDatabaseHas('social_media_schedules', ['id' => $schedule->id, 'agent_id' => $firstAgent->id]);
    }

    public function test_schedule_dates_and_times_can_be_edited_without_changing_published_history(): void
    {
        [$user, $agent] = $this->tenant('editable-schedule');
        $this->connections($agent);
        foreach (range(1, 8) as $number) {
            $agent->products()->create($this->product("Editable Product {$number}", 'General', 10));
        }
        $originalDate = CarbonImmutable::now('Asia/Tbilisi')->addDay()->toDateString();
        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => $originalDate,
            'ends_on' => $originalDate,
            'posts_per_day' => 2,
            'providers' => ['facebook', 'instagram'],
            'timezone' => 'Asia/Tbilisi',
            'timing_mode' => 'custom',
            'posting_times' => ['10:00', '18:00'],
        ])->assertSessionHasNoErrors();

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $published = $schedule->posts()->orderBy('scheduled_for')->firstOrFail();
        $originalPublishedFor = $published->scheduled_for->copy();
        $published->update(['status' => 'published', 'published_at' => now()]);
        $newDate = CarbonImmutable::now('Asia/Tbilisi')->addDays(3)->toDateString();

        $this->actingAs($user)->put(route('social-media.update', $schedule), [
            'starts_on' => $newDate,
            'ends_on' => $newDate,
            'timing_mode' => 'custom',
            'posting_times' => ['11:15', '19:45'],
        ])->assertSessionHasNoErrors();

        $schedule->refresh();
        $this->assertSame($newDate, $schedule->starts_on->toDateString());
        $this->assertSame($newDate, $schedule->ends_on->toDateString());
        $this->assertSame(['11:15', '19:45'], $schedule->posting_times);
        $this->assertSame('published', $published->fresh()->status);
        $this->assertTrue($published->fresh()->scheduled_for->equalTo($originalPublishedFor));
        $this->assertSame(4, $schedule->posts()->where('status', 'scheduled')->count());
        $this->assertSame(
            [
                CarbonImmutable::parse("{$newDate} 11:15", 'Asia/Tbilisi')->utc()->format('Y-m-d H:i:s'),
                CarbonImmutable::parse("{$newDate} 19:45", 'Asia/Tbilisi')->utc()->format('Y-m-d H:i:s'),
            ],
            $schedule->posts()->where('status', 'scheduled')->orderBy('scheduled_for')->get()
                ->unique(fn ($post): string => $post->getRawOriginal('scheduled_for'))
                ->map(fn ($post): string => $post->getRawOriginal('scheduled_for'))->values()->all(),
        );
        $this->assertTrue($schedule->posts()->where('status', 'scheduled')->get()->groupBy('scheduled_for')->every(
            fn ($slotPosts): bool => $slotPosts->pluck('product_id')->unique()->count() === 1,
        ));
    }

    public function test_schedule_edit_rejects_duplicate_or_incomplete_posting_times(): void
    {
        [$user, $agent] = $this->tenant('invalid-edit-times');
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today()->addDay(), 'ends_on' => today()->addDay(), 'posts_per_day' => 2,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'Asia/Tbilisi', 'status' => 'active',
        ]);
        $date = now('Asia/Tbilisi')->addDays(2)->toDateString();

        $this->actingAs($user)->put(route('social-media.update', $schedule), [
            'starts_on' => $date,
            'ends_on' => $date,
            'timing_mode' => 'custom',
            'posting_times' => ['12:00', '12:00'],
        ])->assertSessionHasErrors('posting_times');

        $this->actingAs($user)->put(route('social-media.update', $schedule), [
            'starts_on' => $date,
            'ends_on' => $date,
            'timing_mode' => 'custom',
            'posting_times' => ['12:00'],
        ])->assertSessionHasErrors('posting_times');
    }

    public function test_deleting_a_schedule_removes_its_posts_and_releases_ai_capacity(): void
    {
        [$user, $agent] = $this->tenant('delete-ai-schedule');
        $this->connections($agent);
        foreach (range(1, 7) as $number) {
            $agent->products()->create($this->product("Replaceable AI Product {$number}", 'General', 10));
        }
        $date = CarbonImmutable::now('Asia/Tbilisi')->addDay()->toDateString();
        $payload = [
            'starts_on' => $date,
            'ends_on' => $date,
            'posts_per_day' => 7,
            'providers' => ['facebook'],
            'timezone' => 'Asia/Tbilisi',
            'copy_mode' => 'ai',
            'ai_tone' => 'simple',
        ];

        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $deletedSchedule = $agent->socialMediaSchedules()->firstOrFail();
        $deletedPostIds = $deletedSchedule->posts()->pluck('id');

        $this->actingAs($user)->delete(route('social-media.destroy', $deletedSchedule))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('social_media_schedules', ['id' => $deletedSchedule->id]);
        $this->assertSame(0, SocialMediaPost::query()->whereIn('id', $deletedPostIds)->count());

        $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(7, $agent->socialMediaPosts()->where('copy_mode', 'ai')->count());
        $this->assertSame(0, $agent->socialMediaPosts()->where('copy_mode', 'original')->count());
    }

    public function test_queued_post_is_skipped_when_its_product_is_no_longer_available(): void
    {
        [, $agent] = $this->tenant('stale-product');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Stale Product', 'General', 1));
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'UTC', 'status' => 'active',
        ]);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => 'facebook',
            'status' => 'queued', 'scheduled_for' => now(), 'title' => $product->name,
            'product_url' => data_get($product->metadata, 'product_url'), 'caption' => 'Old caption',
        ]);
        $product->update(['stock' => 0]);

        (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'status' => 'skipped']);
        Http::assertNothingSent();
    }

    public function test_invalid_template_snapshot_fails_without_calling_meta_or_retrying(): void
    {
        [, $agent] = $this->tenant('invalid-template-snapshot');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Template Failure Product', 'General', 1));
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => [], 'providers' => ['facebook'], 'timezone' => 'UTC', 'status' => 'active',
            'template_snapshots' => [
                'facebook' => [
                    'body_template' => '{product_title} {unknown_field} {product_url}',
                    'delivery_enabled' => false,
                    'delivery_text' => null,
                ],
            ],
        ]);
        $post = $schedule->posts()->create([
            'agent_id' => $agent->id, 'product_id' => $product->id, 'provider' => 'facebook',
            'status' => 'queued', 'scheduled_for' => now(), 'title' => $product->name,
            'product_url' => data_get($product->metadata, 'product_url'), 'caption' => 'Old caption',
        ]);

        (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id, 'status' => 'failed']);
        Http::assertNothingSent();
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

        return [$user, $agent];
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

    private function replacementSchedule($agent, array $providers)
    {
        $snapshots = collect($providers)->mapWithKeys(fn (string $provider): array => [$provider => [
            'body_template' => '{product_title} {product_url}',
            'delivery_enabled' => false,
            'delivery_text' => null,
            'image_style' => 'raw',
        ]])->all();

        return $agent->socialMediaSchedules()->create([
            'starts_on' => today(), 'ends_on' => today(), 'posts_per_day' => 1,
            'categories' => ['General'], 'languages' => [], 'providers' => $providers,
            'timezone' => 'UTC', 'status' => 'active', 'copy_mode' => 'original',
            'template_snapshots' => $snapshots,
        ]);
    }

    private function product(string $name, string $category, int $stock): array
    {
        $slug = str($name)->slug();

        return [
            'name' => $name, 'category' => $category, 'description' => 'Verified public description.',
            'price' => 20, 'stock' => $stock, 'image' => 'https://shop.example/images/product.jpg', 'is_active' => true,
            'metadata' => [
                'product_url' => "https://shop.example/products/{$slug}",
                'source_url' => 'https://shop.example/catalog',
                'genres' => [$category],
            ],
        ];
    }

    private function storefrontCard(string $name, string $url, bool $available): string
    {
        return '<html><body><article class="book-card">'
            .'<a class="card-link" href="'.$url.'"><strong class="book-title-strong" title="'.$name.'">'.$name.'</strong></a>'
            .'<span>₾ 20.00</span>'
            .($available ? '<button class="toggle-cart-btn">Add to cart</button>' : '<span>Sold out</span>')
            .'</article></body></html>';
    }

    private function templatePayload(string $marker): array
    {
        return ['templates' => [
            'facebook' => ['body_template' => "{$marker} {product_title} {product_url}", 'delivery_enabled' => false],
            'instagram' => ['body_template' => "{$marker} IG {product_title} {product_url}", 'delivery_enabled' => false],
        ]];
    }
}
