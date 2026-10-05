<?php

namespace Tests\Feature;

use App\Jobs\PublishSocialMediaPost;
use App\Models\Organization;
use App\Services\MetaGraphClient;
use App\Services\SocialMediaScheduler;
use App\Services\SocialMediaTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThreadsPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_threads_shares_the_same_product_slot_and_publishes_an_image_post(): void
    {
        $organization = Organization::create(['name' => 'Threads Shop', 'slug' => 'threads-shop']);
        $agent = $organization->agents()->create([
            'name' => 'Assistant', 'slug' => 'threads-shop-agent', 'business_name' => 'Threads Shop',
            'channels' => ['web'], 'settings' => [], 'is_active' => true,
        ]);
        $product = $agent->customerProducts()->create([
            'name' => 'Verified Product', 'category' => 'General', 'description' => 'Verified description.',
            'price' => 20, 'stock' => 3, 'image' => 'https://shop.example/product.jpg', 'is_active' => true,
            'metadata' => ['product_url' => 'https://shop.example/products/verified', 'genres' => ['General']],
        ]);
        foreach (['facebook' => 'page-1', 'threads' => 'threads-1'] as $provider => $id) {
            $agent->channelConnections()->create([
                'provider' => $provider, 'status' => 'active', 'external_account_id' => $id,
                'external_account_name' => ucfirst($provider), 'access_token' => $provider.'-token',
                'token_expires_at' => $provider === 'threads' ? now()->addDays(6) : now()->addDays(30),
                'connected_at' => now(),
            ]);
        }

        $schedule = app(SocialMediaScheduler::class)->create($agent, [
            'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(),
            'posts_per_day' => 1, 'categories' => [], 'languages' => [],
            'providers' => ['facebook', 'threads'], 'timezone' => 'UTC',
            'timing_mode' => 'auto', 'posting_times' => null, 'copy_mode' => 'original',
            'ai_tone' => null, 'ai_photo_editor' => false,
        ]);
        $posts = $schedule->posts()->orderBy('provider')->get();
        $this->assertSame(['facebook', 'threads'], $posts->pluck('provider')->all());
        $this->assertSame(1, $posts->pluck('product_id')->unique()->count());
        $this->assertSame($product->id, $posts->first()->product_id);

        $threadsPost = $posts->firstWhere('provider', 'threads');
        $threadsPost->update(['status' => 'queued']);
        config(['threads.api_url' => 'https://graph.threads.test']);
        Http::fake([
            'https://shop.example/products/verified' => Http::response('<html><body>In stock</body></html>'),
            'https://graph.threads.test/refresh_access_token*' => Http::response([
                'access_token' => 'refreshed-threads-token', 'token_type' => 'bearer', 'expires_in' => 5184000,
            ]),
            'https://graph.threads.test/me/threads' => Http::response(['id' => 'container-1']),
            'https://graph.threads.test/container-1*' => Http::response(['id' => 'container-1', 'status' => 'FINISHED']),
            'https://graph.threads.test/me/threads_publish' => Http::response(['id' => 'thread-123']),
        ]);

        (new PublishSocialMediaPost($threadsPost->id))->handle(
            app(MetaGraphClient::class),
            app(SocialMediaTemplateRenderer::class),
        );

        $this->assertSame('published', $threadsPost->fresh()->status);
        $this->assertSame('thread-123', $threadsPost->fresh()->provider_post_id);
        $this->assertSame('refreshed-threads-token', $agent->channelConnections()->where('provider', 'threads')->firstOrFail()->access_token);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://graph.threads.test/me/threads'
            && $request->hasHeader('Authorization', 'Bearer refreshed-threads-token')
            && $request['media_type'] === 'IMAGE'
            && $request['image_url'] === 'https://shop.example/product.jpg'
            && str_contains((string) $request['text'], 'https://shop.example/products/verified'));
    }
}
