<?php

namespace Tests\Feature;

use App\Jobs\PublishSocialMediaPost;
use App\Models\Organization;
use App\Models\SocialMediaPost;
use App\Models\User;
use App\Services\MetaGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $agent->products()->create($this->product('Poetry Book', 'Poetry', 4));

        $this->actingAs($user)->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'posts_per_day' => 3,
            'categories' => ['Novel'],
            'providers' => ['facebook', 'instagram'],
            'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'));

        $schedule = $agent->socialMediaSchedules()->firstOrFail();
        $this->assertSame(['Novel'], $schedule->categories);
        $this->assertCount(12, $schedule->posts);
        $this->assertSame([$selected->id], $schedule->posts->pluck('product_id')->unique()->values()->all());
        $this->assertTrue($schedule->posts->every(fn ($post) => str_contains($post->caption, 'https://shop.example/products/public-novel')));
        $this->assertSame(6, $schedule->posts->where('provider', 'facebook')->count());
        $this->assertSame(6, $schedule->posts->where('provider', 'instagram')->count());

        $this->actingAs($user)->get(route('social-media.index'))
            ->assertOk()->assertSee('Social media scheduler')->assertSee('Public Novel')->assertSee('Open public product');
    }

    public function test_instagram_schedule_rejects_products_without_a_public_image(): void
    {
        [$user, $agent] = $this->tenant('missing-image');
        $this->connections($agent);
        $attributes = $this->product('No Image Product', 'General', 2);
        $attributes['metadata']['image'] = null;
        $agent->products()->create($attributes);

        $this->actingAs($user)->from(route('social-media.index'))->post(route('social-media.store'), [
            'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(),
            'posts_per_day' => 1, 'providers' => ['instagram'], 'timezone' => 'Asia/Tbilisi',
        ])->assertRedirect(route('social-media.index'))->assertSessionHasErrors('categories');
        $this->assertDatabaseCount('social_media_schedules', 0);
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
                'status' => 'scheduled', 'scheduled_for' => now()->subMinute(), 'title' => $product->name,
                'description' => $product->description, 'product_url' => data_get($product->metadata, 'product_url'),
                'image_url' => data_get($product->metadata, 'image'), 'caption' => 'Verified caption',
            ]);
        }

        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('2 social posts queued.')->assertSuccessful();
        $this->artisan('legatus:dispatch-social-posts')->expectsOutput('0 social posts queued.')->assertSuccessful();
        Queue::assertPushed(PublishSocialMediaPost::class, 2);

        Http::fake([
            'https://graph.facebook.test/*/feed*' => Http::response(['id' => 'fb-post-1']),
            'https://graph.facebook.test/*/media*' => Http::sequence()->push(['id' => 'container-1'])->push(['id' => 'ig-post-1']),
        ]);
        foreach (SocialMediaPost::query()->get() as $post) {
            (new PublishSocialMediaPost($post->id))->handle(app(MetaGraphClient::class));
        }

        $this->assertDatabaseHas('social_media_posts', ['provider' => 'facebook', 'status' => 'published', 'provider_post_id' => 'fb-post-1']);
        $this->assertDatabaseHas('social_media_posts', ['provider' => 'instagram', 'status' => 'published', 'provider_post_id' => 'ig-post-1']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/page-1/feed') && $request['link'] === 'https://shop.example/products/scheduled-product');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-1/media?') && $request['image_url'] === 'https://shop.example/images/product.jpg');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-1/media_publish') && $request['creation_id'] === 'container-1');
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
            'price' => 20, 'stock' => $stock, 'is_active' => true,
            'metadata' => [
                'product_url' => "https://shop.example/products/{$slug}",
                'source_url' => 'https://shop.example/catalog',
                'image' => 'https://shop.example/images/product.jpg',
                'genres' => [$category],
            ],
        ];
    }
}
