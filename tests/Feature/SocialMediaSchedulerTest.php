<?php

namespace Tests\Feature;

use App\Jobs\PublishSocialMediaPost;
use App\Models\Organization;
use App\Models\SocialMediaPost;
use App\Models\User;
use App\Services\MetaGraphClient;
use App\Services\ProductPagePrimaryImageResolver;
use App\Services\SocialMediaImageDesigner;
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
        ])->assertRedirect(route('social-media.index'));

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
            ->assertSee('Informative')
            ->assertSee('Luna writes up to 7 product posts per business each day')
            ->assertSee('Catalog design')
            ->assertSee('Catalog design preview')
            ->assertSee('Plain photo')
            ->assertDontSee('Storefront image')
            ->assertSee('https://shop.example/images/product.jpg')
            ->assertSee('https://shop.example/images/catalog-design.jpg')
            ->assertSee('.template-workspace[hidden]{display:none!important}', false)
            ->assertSee('Open public product');
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

    public function test_new_schedules_prefer_unposted_products_then_restart_after_catalog_exhaustion(): void
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
        foreach (range(1, 4) as $iteration) {
            $this->actingAs($user)->post(route('social-media.store'), $payload)->assertSessionHasNoErrors();
            $schedule = $agent->socialMediaSchedules()->latest('id')->firstOrFail();
            $this->assertSame(1, $schedule->posts()->pluck('product_id')->unique()->count());
            $chosenProducts->push((int) $schedule->posts()->value('product_id'));
        }

        $this->assertCount(3, $chosenProducts->take(3)->unique());
        $this->assertContains($chosenProducts->last(), $chosenProducts->take(3));
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

    public function test_storefront_primary_image_contract_does_not_change_the_other_image_styles(): void
    {
        [$user, $agent] = $this->tenant('storefront-primary-image-framed');
        $this->connections($agent);
        $product = $agent->products()->create($this->product('Framed Gallery Product', 'General', 2));
        $metadata = $product->metadata;
        $metadata['image'] = 'https://shop.example/images/generated-catalog-design.jpg';
        $product->update(['metadata' => $metadata]);
        $this->mock(ProductPagePrimaryImageResolver::class)
            ->shouldNotReceive('resolve');
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
            'https://shop.example/images/generated-catalog-design.jpg',
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

    public function test_instagram_schedule_rejects_products_without_a_public_image(): void
    {
        [$user, $agent] = $this->tenant('missing-image');
        $this->connections($agent);
        $attributes = $this->product('No Image Product', 'General', 2);
        $attributes['image'] = null;
        $agent->products()->create($attributes);

        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['instagram'], 'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'))->assertSessionHasErrors('categories');
        $this->assertDatabaseCount('social_media_schedules', 0);
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
        $generated = 'Thoughtful Luna caption 📚 '.data_get($product->metadata, 'product_url');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['caption' => $generated])]]]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
            ]),
            'https://graph.facebook.test/*/photos*' => Http::response(['id' => 'ai-facebook-post']),
        ]);

        (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class), app(SocialMediaTemplateRenderer::class));

        $fresh = $post->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertSame($generated, $fresh->caption);
        $this->assertSame('gpt-5.6-luna', $fresh->ai_model);
        $this->assertNotNull($fresh->ai_generation_attempted_at);
        $this->assertNotNull($fresh->ai_generated_at);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-5.6-luna'
            && data_get($request->data(), 'input.0.content.1.type') === 'input_image'
            && str_contains((string) data_get($request->data(), 'input.0.content.0.text'), 'Do not invent benefits'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/page-1/photos')
            && str_contains((string) $request['caption'], $generated));
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

        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('2 social posts queued.')->assertSuccessful();
        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('0 social posts queued.')->assertSuccessful();
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
        Http::assertSent(fn ($request) => str_contains($request->url(), '/page-1/photos')
            && $request['url'] === 'https://shop.example/images/product.jpg'
            && str_contains((string) $request['caption'], 'https://shop.example/products/scheduled-product'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-1/media?') && $request['image_url'] === 'https://shop.example/images/product.jpg');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-1/media_publish') && $request['creation_id'] === 'container-1');
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
        $this->actingAs($secondUser)->delete(route('social-media.destroy', $schedule))->assertNotFound();
        $this->assertDatabaseHas('social_media_schedules', ['id' => $schedule->id, 'agent_id' => $firstAgent->id]);
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

    private function templatePayload(string $marker): array
    {
        return ['templates' => [
            'facebook' => ['body_template' => "{$marker} {product_title} {product_url}", 'delivery_enabled' => false],
            'instagram' => ['body_template' => "{$marker} IG {product_title} {product_url}", 'delivery_enabled' => false],
        ]];
    }
}
