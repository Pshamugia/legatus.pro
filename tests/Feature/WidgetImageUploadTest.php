<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\CustomerImageCatalogMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WidgetImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_accepts_a_transient_product_photo_and_replays_the_same_request_safely(): void
    {
        $agent = Agent::create([
            'name' => 'Shop Assistant',
            'slug' => 'photo-shop',
            'business_name' => 'Photo Shop',
            'channels' => ['web'],
            'is_active' => true,
        ]);
        $matcher = Mockery::mock(CustomerImageCatalogMatcher::class);
        $matcher->shouldReceive('resolveBytes')->once()->andReturn([
            'status' => 'not_found',
            'text' => 'I could not find an exact catalog match.',
            'product_ids' => [],
            'products' => [],
            'tools_used' => [],
            'model' => 'vision-test-model',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
        ]);
        $this->app->instance(CustomerImageCatalogMatcher::class, $matcher);
        $requestId = (string) Str::uuid();

        $first = $this->post(route('chat.image', $agent), [
            'image' => $this->tinyPng(),
            'message' => 'Do you have this exact product?',
            'request_id' => $requestId,
            'channel' => 'widget',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('text', 'I could not find an exact catalog match.')
            ->assertJsonPath('intent', 'image_product_lookup');

        $token = $first->json('visitor_token');
        $this->post(route('chat.image', $agent), [
            'image' => $this->tinyPng(),
            'message' => 'Do you have this exact product?',
            'visitor_token' => $token,
            'request_id' => $requestId,
            'channel' => 'widget',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('message_id', $first->json('message_id'));

        $conversation = $agent->conversations()->sole();
        $customer = $conversation->messages()->where('role', 'customer')->sole();
        $this->assertTrue((bool) data_get($customer->metadata, 'image_received'));
        $this->assertArrayNotHasKey('image', $customer->metadata);
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(1, AgentRun::where('agent_id', $agent->id)->where('route', 'image_catalog_match')->count());
    }

    public function test_widget_rejects_unsupported_files_and_platform_support_has_no_photo_endpoint(): void
    {
        $agent = Agent::create([
            'name' => 'Shop Assistant',
            'slug' => 'safe-photo-shop',
            'business_name' => 'Safe Shop',
            'channels' => ['web'],
            'is_active' => true,
        ]);

        $this->post(route('chat.image', $agent), [
            'image' => UploadedFile::fake()->createWithContent('payload.txt', 'not an image'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $agent->update(['settings' => ['assistant_mode' => 'platform_support']]);
        $this->post(route('chat.image', $agent), [
            'image' => $this->tinyPng(),
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->get(route('widget.frame', $agent))->assertOk()->assertDontSee('id="attach-image"', false);
    }

    private function tinyPng(): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent('product.png', $bytes ?: '');
    }
}
