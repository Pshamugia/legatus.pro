<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaInboundMessage;
use App\Jobs\SendMetaMessage;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\Message;
use App\Models\MetaOAuthSelection;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChannelMessageDispatcher;
use App\Services\ConversationEngine;
use App\Services\CustomerImageCatalogMatcher;
use App\Services\MetaGraphClient;
use App\Services\PublicCommentGate;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MetaTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_id', 'meta-app-id');
        config()->set('meta.app_secret', 'meta-app-secret');
        config()->set('meta.verify_token', 'verify-legatus');
        config()->set('meta.graph_url', 'https://graph.facebook.test');
        config()->set('meta.dialog_url', 'https://www.facebook.test');
        config()->set('meta.graph_version', 'v25.0');
        config()->set('meta.retries', 0);
    }

    public function test_webhook_verification_requires_the_exact_secret(): void
    {
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')
            ->assertForbidden();

        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-legatus&hub.challenge=123')
            ->assertOk()
            ->assertSeeText('123')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_signed_inbound_webhook_is_persisted_queued_and_deduplicated(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-123');
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-123',
                'messaging' => [[
                    'sender' => ['id' => 'customer-456'],
                    'recipient' => ['id' => 'page-123'],
                    'timestamp' => 1784512800000,
                    'message' => ['mid' => 'mid.inbound.1', 'text' => 'რა ღირს?'],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret');

        $this->metaWebhook($body, 'sha256=invalid')->assertUnauthorized();
        $this->metaWebhook($body, $signature)
            ->assertOk()
            ->assertSeeText('EVENT_RECEIVED')
            ->assertHeaderMissing('Set-Cookie');
        $this->metaWebhook($body, $signature)->assertOk();

        $this->assertDatabaseCount('channel_messages', 1);
        $this->assertDatabaseHas('channel_messages', [
            'channel_connection_id' => $connection->id,
            'direction' => 'inbound',
            'provider_message_id' => 'mid.inbound.1',
            'provider_sender_id' => 'customer-456',
            'status' => 'received',
        ]);
        $storedPayload = DB::table('channel_messages')->value('payload');
        $this->assertStringNotContainsString('რა ღირს', $storedPayload);
        $this->assertSame('რა ღირს?', ChannelMessage::query()->firstOrFail()->payload['text']);
        Queue::assertPushed(ProcessMetaInboundMessage::class, fn ($job): bool => $job->delay instanceof \DateTimeInterface
            && $job->delay->getTimestamp() >= now()->addSeconds(2)->getTimestamp());
        Queue::assertPushed(ProcessMetaInboundMessage::class, 1);
        $this->assertNotNull($connection->fresh()->last_webhook_at);
    }

    public function test_dispatcher_and_send_job_deliver_ai_and_human_messages_to_graph_api(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-123');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'meta:facebook:'.$connection->id.':customer-456',
            'external_thread_id' => 'customer-456',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Piranesi ღირს 27.50 ₾.']);
        $human = $conversation->messages()->create(['role' => 'human', 'content' => 'ოპერატორიც აქ ვარ.']);

        $dispatcher = app(ChannelMessageDispatcher::class);
        $assistantDelivery = $dispatcher->dispatch($assistant);
        $humanDelivery = $dispatcher->dispatch($human);
        $dispatcher->dispatch($assistant);

        $this->assertNotNull($assistantDelivery);
        $this->assertNotNull($humanDelivery);
        $this->assertDatabaseCount('channel_messages', 2);
        Queue::assertPushed(SendMetaMessage::class, 2);

        Http::fake([
            'https://graph.facebook.test/*' => Http::sequence()
                ->push(['recipient_id' => 'customer-456', 'message_id' => 'mid.out.1'])
                ->push(['recipient_id' => 'customer-456', 'message_id' => 'mid.out.2']),
        ]);

        (new SendMetaMessage($assistantDelivery->id))->handle(app(MetaGraphClient::class));
        (new SendMetaMessage($humanDelivery->id))->handle(app(MetaGraphClient::class));

        $this->assertDatabaseHas('channel_messages', [
            'id' => $assistantDelivery->id,
            'provider_message_id' => 'mid.out.1',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'id' => $humanDelivery->id,
            'provider_message_id' => 'mid.out.2',
            'status' => 'sent',
        ]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.test/v25.0/page-123/messages?appsecret_proof='.hash_hmac('sha256', 'page-access-token', 'meta-app-secret')
            && $request->hasHeader('Authorization', 'Bearer page-access-token')
            && data_get($request->data(), 'recipient.id') === 'customer-456'
            && $request['messaging_type'] === 'RESPONSE');
    }

    public function test_queued_ai_reply_is_suppressed_if_a_human_takes_over_before_meta_delivery(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-human-race');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'meta-human-race',
            'external_thread_id' => 'customer-human-race',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Stale AI draft']);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $conversation->update(['status' => 'human', 'assigned_to' => 'Meta inbox']);
        Http::fake();

        (new SendMetaMessage($delivery->id))->handle(app(MetaGraphClient::class));

        $this->assertSame('ignored', $delivery->fresh()->status);
        $this->assertStringContainsString('human operator controls', $delivery->fresh()->failure_reason);
        Http::assertNothingSent();
    }

    public function test_instagram_webhook_and_send_support_the_linked_facebook_page_id(): void
    {
        Queue::fake();
        $connection = $this->connection('instagram', 'ig-business-123');
        $connection->update(['metadata' => ['facebook_page_id' => 'page-789']]);
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'page-789',
                'messaging' => [[
                    'sender' => ['id' => 'ig-customer-1'],
                    'recipient' => ['id' => 'page-789'],
                    'message' => ['mid' => 'ig-mid-in', 'text' => 'Hello'],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret');

        $this->metaWebhook($body, $signature)->assertOk();
        $this->assertDatabaseHas('channel_messages', [
            'channel_connection_id' => $connection->id,
            'provider_message_id' => 'ig-mid-in',
            'direction' => 'inbound',
        ]);

        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'ig-test-customer',
            'external_thread_id' => 'ig-customer-1',
            'channel' => 'instagram',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello from Legatus']);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);

        Http::fake(['https://graph.facebook.test/*' => Http::response(['message_id' => 'ig-mid-out'])]);
        (new SendMetaMessage($delivery->id))->handle(app(MetaGraphClient::class));

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.test/v25.0/page-789/messages?'));
    }

    public function test_instagram_page_subscription_uses_only_page_supported_webhook_fields(): void
    {
        $connection = $this->connection('instagram', 'ig-subscription');
        $connection->update(['metadata' => ['facebook_page_id' => 'page-subscription']]);
        Http::fake(['https://graph.facebook.test/*' => Http::response(['success' => true])]);

        app(MetaGraphClient::class)->subscribe($connection);

        Http::assertSent(function ($request): bool {
            $fields = (string) data_get($request->data(), 'subscribed_fields');

            return $request->method() === 'POST'
                && str_contains($request->url(), '/page-subscription/subscribed_apps?')
                && $fields === 'messages,messaging_postbacks,message_deliveries,message_reads'
                && ! str_contains($fields, 'messaging_seen');
        });
    }

    public function test_comment_webhook_stays_in_review_when_tenant_disables_automatic_replies(): void
    {
        Queue::fake();
        [$user, $agent] = $this->tenant();
        $agent->update(['settings' => ['automatic_comment_replies_enabled' => false]]);
        $connection = $agent->channelConnections()->create([
            'provider' => 'facebook',
            'status' => 'active',
            'external_account_id' => 'page-comments',
            'external_account_name' => 'Comment Page',
            'access_token' => 'page-access-token',
            'connected_at' => now(),
        ]);
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-comments',
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'item' => 'comment',
                        'verb' => 'add',
                        'comment_id' => 'comment-123',
                        'post_id' => 'page-comments_post-456',
                        'message' => 'Is this still available?',
                        'from' => ['id' => 'customer-commenter', 'name' => 'Interested Customer'],
                        'created_time' => 1784512800,
                    ],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret');

        $this->metaWebhook($body, $signature)->assertOk();
        $this->metaWebhook($body, $signature)->assertOk();

        $this->assertDatabaseCount('channel_messages', 1);
        $conversation = $agent->conversations()->sole();
        $this->assertSame('facebook_comment', $conversation->channel);
        $this->assertSame('human', $conversation->status);
        $this->assertSame('social_comment', data_get($conversation->context, 'source_type'));
        $this->assertSame('Is this still available?', $conversation->messages()->where('role', 'customer')->sole()->content);
        Queue::assertNothingPushed();

        $this->actingAs($user)->get(route('inbox.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Needs human')
            ->assertSee('Reply publicly to this comment')
            ->assertDontSee('Automatic public replies are active');
        $this->actingAs($user)->post(route('inbox.reply', $conversation), ['message' => 'Verified public reply'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('messages', ['content' => 'Verified public reply', 'role' => 'human']);
        $this->assertDatabaseHas('channel_messages', [
            'message_type' => 'comment_reply',
            'provider_recipient_id' => 'comment-123',
            'status' => 'queued',
        ]);
        Queue::assertPushed(SendMetaMessage::class, 1);
        $this->assertSame($connection->id, $conversation->channel_connection_id);
    }

    public function test_rejected_comment_webhook_logs_only_a_content_free_shape(): void
    {
        Log::spy();
        $payload = ['object' => 'page', 'entry' => [[
            'id' => 'private-page-id',
            'changes' => [[
                'field' => 'feed',
                'value' => [
                    'item' => 'comment',
                    'verb' => 'add',
                    'comment_id' => 'private-comment-id',
                    'post_id' => 'private-post-id',
                    'message' => 'Private customer comment text',
                ],
            ]],
        ]]];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);

            return $message === 'Meta webhook received.'
                && data_get($context, 'event_count') === 0
                && data_get($context, 'unmatched_change_shapes.0.field') === 'feed'
                && data_get($context, 'unmatched_change_shapes.0.has_comment_id') === true
                && data_get($context, 'unmatched_change_shapes.0.has_text') === true
                && data_get($context, 'unmatched_change_shapes.0.has_sender_id') === false
                && ! str_contains($encoded, 'Private customer comment text')
                && ! str_contains($encoded, 'private-comment-id')
                && ! str_contains($encoded, 'private-post-id');
        });
    }

    public function test_comment_webhook_is_queued_for_automatic_public_processing_by_default(): void
    {
        Queue::fake();
        [, $agent] = $this->tenant();
        $agent->channelConnections()->create([
            'provider' => 'facebook',
            'status' => 'active',
            'external_account_id' => 'page-auto-comments',
            'access_token' => 'page-access-token',
            'connected_at' => now(),
        ]);
        $payload = ['object' => 'page', 'entry' => [[
            'id' => 'page-auto-comments',
            'changes' => [[
                'field' => 'feed',
                'value' => [
                    'item' => 'comment',
                    'verb' => 'add',
                    'comment_id' => 'comment-auto-1',
                    'post_id' => 'page-auto-comments_post-1',
                    'from' => ['id' => 'commenter-auto'],
                    'created_time' => 1784512800,
                ],
            ]],
        ]]];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        $message = ChannelMessage::query()->sole();
        $this->assertSame('received', $message->status);
        $this->assertSame('comment', $message->message_type);
        $this->assertDatabaseCount('conversations', 0);
        Queue::assertPushed(ProcessMetaInboundMessage::class, fn ($job): bool => $job->channelMessageId === $message->id);
    }

    public function test_comment_job_uses_linked_post_product_context_and_queues_a_public_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://graph.facebook.test/v25.0/comment-product-1*' => Http::response([
                'id' => 'comment-product-1',
                'message' => 'Is this available?',
                'from' => ['id' => 'commenter-product', 'name' => 'Customer'],
            ]),
        ]);
        $connection = $this->connection('facebook', 'page-product-comments');
        $product = $connection->agent->products()->create([
            'name' => 'Verified Product',
            'description' => 'Verified description.',
            'price' => 25,
            'stock' => 3,
            'is_active' => true,
            'metadata' => ['product_url' => 'https://shop.example/products/verified'],
        ]);
        $schedule = $connection->agent->socialMediaSchedules()->create([
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'posts_per_day' => 1,
            'categories' => [],
            'providers' => ['facebook'],
            'timezone' => 'UTC',
            'status' => 'active',
        ]);
        $schedule->posts()->create([
            'agent_id' => $connection->agent_id,
            'product_id' => $product->id,
            'provider' => 'facebook',
            'status' => 'published',
            'scheduled_for' => now(),
            'published_at' => now(),
            'provider_post_id' => 'page-product-comments_post-77',
            'title' => $product->name,
            'description' => $product->description,
            'product_url' => 'https://shop.example/products/verified',
            'caption' => 'Published product',
        ]);
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'comment-product-1',
            'provider_sender_id' => 'commenter-product',
            'provider_recipient_id' => 'page-product-comments',
            'message_type' => 'comment',
            'status' => 'received',
            'payload' => [
                'text' => '',
                'post_id' => 'page-product-comments_post-77',
                'source' => 'social_comment',
            ],
            'received_at' => now(),
        ]);

        $engine = \Mockery::mock(ConversationEngine::class);
        $engine->shouldReceive('handle')->once()->withArgs(function ($agent, $text, $channel, $customerId) use ($product): bool {
            $conversation = $agent->conversations()->where('visitor_id', $customerId)->sole();
            $this->assertSame('facebook_comment', $channel);
            $this->assertSame([$product->id], data_get($conversation->context, 'last_catalog_product_ids'));

            return $text === 'Is this available?';
        })->andReturnUsing(function ($agent, $text, $channel, $customerId, $requestId): array {
            $conversation = $agent->conversations()->where('visitor_id', $customerId)->sole();
            $customer = $conversation->messages()->create([
                'role' => 'customer',
                'content' => $text,
                'request_id' => $requestId,
            ]);
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'Yes, it is available.',
                'confidence' => 1,
                'metadata' => ['handoff' => false, 'products' => []],
            ]);

            return [
                'conversation_id' => $conversation->id,
                'customer_message_id' => $customer->public_id,
                'message_id' => $assistant->public_id,
            ];
        });

        $gate = \Mockery::mock(PublicCommentGate::class);
        $gate->shouldReceive('decide')->once()->andReturn(['action' => 'reply', 'reason' => 'Safe public question.']);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            $engine,
            app(ChannelMessageDispatcher::class),
            null,
            $gate,
            app(MetaGraphClient::class),
        );

        $conversation = $connection->agent->conversations()->sole();
        $this->assertSame('facebook_comment', $conversation->channel);
        $this->assertSame('public_comment', data_get($conversation->context, 'reply_mode'));
        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'comment_reply',
            'provider_recipient_id' => 'comment-product-1',
            'status' => 'queued',
        ]);
        Queue::assertPushed(SendMetaMessage::class, 1);
        $this->assertTrue((bool) data_get($inbound->fresh()->payload, 'content_removed'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/comment-product-1?')
            && data_get($request->data(), 'fields') === 'id,message,from{id,name},created_time,parent{id}');
    }

    public function test_instagram_comment_includes_its_published_product_and_caption_context(): void
    {
        Queue::fake();
        $connection = $this->connection('instagram', 'ig-product-comments');
        $product = $connection->agent->products()->create([
            'name' => 'Verified Product',
            'description' => 'Verified description.',
            'price' => 25,
            'stock' => 3,
            'is_active' => true,
            'metadata' => ['product_url' => 'https://shop.example/products/verified'],
        ]);
        $schedule = $connection->agent->socialMediaSchedules()->create([
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'posts_per_day' => 1,
            'categories' => [],
            'providers' => ['instagram'],
            'timezone' => 'UTC',
            'status' => 'active',
        ]);
        $schedule->posts()->create([
            'agent_id' => $connection->agent_id,
            'product_id' => $product->id,
            'provider' => 'instagram',
            'status' => 'published',
            'scheduled_for' => now(),
            'published_at' => now(),
            'provider_post_id' => 'ig-product-post',
            'title' => $product->name,
            'product_url' => 'https://shop.example/products/verified',
            'caption' => 'Verified Product. https://shop.example/products/verified',
        ]);
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'ig-product-comment',
            'provider_sender_id' => 'ig-commenter',
            'provider_recipient_id' => 'ig-product-comments',
            'message_type' => 'comment',
            'status' => 'received',
            'payload' => ['text' => 'How much does it cost?', 'post_id' => 'ig-product-post'],
            'received_at' => now(),
        ]);

        $engine = \Mockery::mock(ConversationEngine::class);
        $engine->shouldNotReceive('handle');
        $gate = \Mockery::mock(PublicCommentGate::class);
        $gate->shouldReceive('decide')->once()->andReturn(['action' => 'review', 'reason' => 'Review test.']);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            $engine,
            app(ChannelMessageDispatcher::class),
            null,
            $gate,
        );

        $context = $connection->agent->conversations()->sole()->context;
        $this->assertSame($product->id, data_get($context, 'comment_product_id'));
        $this->assertSame([$product->id], data_get($context, 'last_catalog_product_ids'));
        $this->assertSame('Verified Product. https://shop.example/products/verified', data_get($context, 'comment_post_caption'));
    }

    public function test_reel_comment_uses_only_its_tenants_published_product_context(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-reel-comments');
        $product = $connection->agent->products()->create([
            'name' => 'Verified Reel Product', 'price' => 25, 'stock' => 3,
            'is_active' => true, 'metadata' => ['product_url' => 'https://shop.example/product'],
        ]);
        $reel = $connection->agent->aiReels()->create([
            'product_id' => $product->id, 'mode' => 'scheduled',
            'providers' => ['facebook'], 'status' => 'published',
            'caption' => 'Verified Reel Product: https://shop.example/product',
        ]);
        $reel->deliveries()->create([
            'provider' => 'facebook', 'status' => 'published',
            'provider_post_id' => 'reel-post-1',
        ]);
        $otherOrganization = Organization::query()->create(['name' => 'Other business', 'slug' => 'other-business']);
        $otherAgent = $otherOrganization->agents()->create([
            'name' => 'Other assistant', 'slug' => 'other-assistant',
            'business_name' => 'Other business', 'channels' => ['facebook'],
            'settings' => [], 'is_active' => true,
        ]);
        $otherProduct = $otherAgent->products()->create([
            'name' => 'Unrelated Product', 'price' => 99, 'stock' => 1, 'is_active' => true,
        ]);
        $otherReel = $otherAgent->aiReels()->create([
            'product_id' => $otherProduct->id, 'mode' => 'scheduled',
            'providers' => ['facebook'], 'status' => 'published',
            'caption' => 'Unrelated Product',
        ]);
        $otherReel->deliveries()->create([
            'provider' => 'facebook', 'status' => 'published',
            'provider_post_id' => 'reel-post-1',
        ]);
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound', 'provider_message_id' => 'reel-comment-1',
            'provider_sender_id' => 'reel-commenter',
            'provider_recipient_id' => 'page-reel-comments',
            'message_type' => 'comment', 'status' => 'received',
            'payload' => ['text' => 'ფასი მომწერეთ', 'post_id' => 'reel-post-1'],
            'received_at' => now(),
        ]);
        $engine = \Mockery::mock(ConversationEngine::class);
        $engine->shouldReceive('handle')->once()->withArgs(function ($agent, $text, $channel, $customerId) use ($product): bool {
            $conversation = $agent->conversations()->where('visitor_id', $customerId)->sole();
            $this->assertSame([$product->id], data_get($conversation->context, 'last_catalog_product_ids'));
            $this->assertSame($product->id, data_get($conversation->context, 'comment_product_id'));
            $this->assertStringContainsString('Verified Reel Product', (string) data_get($conversation->context, 'comment_post_caption'));

            return $text === 'ფასი მომწერეთ' && $channel === 'facebook_comment';
        })->andReturnUsing(function ($agent, $text, $channel, $customerId, $requestId): array {
            $conversation = $agent->conversations()->where('visitor_id', $customerId)->sole();
            $customer = $conversation->messages()->create(['role' => 'customer', 'content' => $text, 'request_id' => $requestId]);

            return ['conversation_id' => $conversation->id, 'customer_message_id' => $customer->public_id];
        });
        $gate = \Mockery::mock(PublicCommentGate::class);
        $gate->shouldReceive('decide')->once()->andReturn(['action' => 'reply', 'reason' => 'Safe public question.']);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            $engine, app(ChannelMessageDispatcher::class), null, $gate,
        );

        $this->assertSame('processed', $inbound->fresh()->status);
    }

    #[DataProvider('publicCommentNonReplyDecisions')]
    public function test_public_comment_gate_can_silence_noise_or_require_human_review(
        string $action,
        string $expectedStatus,
        string $expectedOutcome,
    ): void {
        Queue::fake();
        $connection = $this->connection('instagram', 'ig-gated-comments');
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'ig-gated-comment',
            'provider_sender_id' => 'ig-commenter',
            'provider_recipient_id' => 'ig-gated-comments',
            'message_type' => 'comment',
            'status' => 'received',
            'payload' => ['text' => 'Public comment text', 'post_id' => 'ig-post-9'],
            'received_at' => now(),
        ]);
        $engine = \Mockery::mock(ConversationEngine::class);
        $engine->shouldNotReceive('handle');
        $gate = \Mockery::mock(PublicCommentGate::class);
        $gate->shouldReceive('decide')->once()->andReturn([
            'action' => $action,
            'reason' => $action === 'review' ? 'This needs private human judgment.' : 'No answer is needed.',
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            $engine,
            app(ChannelMessageDispatcher::class),
            null,
            $gate,
        );

        $conversation = $connection->agent->conversations()->sole();
        $this->assertSame($expectedStatus, $conversation->status);
        $this->assertSame($expectedOutcome, $conversation->outcome);
        $this->assertSame('processed', $inbound->fresh()->status);
        $this->assertSame(0, $connection->channelMessages()->where('direction', 'outbound')->count());
        Queue::assertNothingPushed();
    }

    public static function publicCommentNonReplyDecisions(): array
    {
        return [
            'passive noise stays silent' => ['silent', 'ai', 'no_reply_needed'],
            'sensitive request needs human' => ['review', 'human', 'human_handoff'],
        ];
    }

    public function test_public_comment_safety_classifier_uses_structured_semantic_decision(): void
    {
        config()->set('services.openai.key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response([
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['action' => 'review', 'reason' => 'Customer-specific order issue.']),
                ]],
            ]],
        ])]);
        $connection = $this->connection('facebook', 'page-comment-classifier');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'comment-classifier-customer',
            'external_thread_id' => 'comment-classifier-1',
            'channel' => 'facebook_comment',
            'status' => 'ai',
            'context' => ['source_type' => 'social_comment', 'reply_mode' => 'public_comment'],
        ]);

        $decision = app(PublicCommentGate::class)->decide('Please check my specific order.', $conversation);

        $this->assertSame('review', $decision['action']);
        $this->assertSame('Customer-specific order issue.', $decision['reason']);
        Http::assertSent(function ($request): bool {
            $schema = data_get($request->data(), 'text.format.schema.properties.action.enum');

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $schema === ['reply', 'silent', 'review']
                && str_contains((string) data_get($request->data(), 'instructions'), 'public social-media comment');
        });
    }

    public function test_meta_send_job_replies_to_facebook_and_instagram_comments_on_public_endpoints(): void
    {
        Queue::fake();
        Http::fake([
            'https://graph.facebook.test/v25.0/fb-comment/comments*' => Http::response(['id' => 'fb-reply-id']),
            'https://graph.facebook.test/v25.0/ig-comment/replies*' => Http::response(['id' => 'ig-reply-id']),
        ]);

        [, $agent] = $this->tenant();
        foreach ([['facebook', 'fb-comment', 'fb-reply-id'], ['instagram', 'ig-comment', 'ig-reply-id']] as [$provider, $commentId, $replyId]) {
            $connection = $agent->channelConnections()->create([
                'provider' => $provider,
                'status' => 'active',
                'external_account_id' => $provider.'-comment-account',
                'access_token' => 'page-access-token',
                'connected_at' => now(),
            ]);
            $conversation = $connection->agent->conversations()->create([
                'channel_connection_id' => $connection->id,
                'visitor_id' => $provider.'-commenter',
                'external_thread_id' => $commentId,
                'channel' => $provider.'_comment',
                'status' => 'ai',
                'context' => ['source_type' => 'social_comment'],
            ]);
            $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Public answer']);
            $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);

            (new SendMetaMessage($delivery->id))->handle(app(MetaGraphClient::class));

            $this->assertSame('sent', $delivery->fresh()->status);
            $this->assertSame($replyId, $delivery->fresh()->provider_message_id);
        }

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/fb-comment/comments?')
            && data_get($request->data(), 'message') === 'Public answer');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig-comment/replies?')
            && data_get($request->data(), 'message') === 'Public answer');
    }

    public function test_facebook_comment_subscription_is_explicitly_capability_gated(): void
    {
        $connection = $this->connection('facebook', 'page-comment-subscription');
        config()->set('meta.comment_to_sale_enabled', true);
        Http::fake(['https://graph.facebook.test/*' => Http::response(['success' => true])]);

        app(MetaGraphClient::class)->subscribe($connection);

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'subscribed_fields') === 'messages,messaging_postbacks,message_deliveries,message_reads,feed'
        );
    }

    public function test_assistant_delivery_appends_only_three_safe_verified_product_links(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-products');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'product-customer',
            'external_thread_id' => 'customer-products',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'ეს ვარიანტები შეგირჩიეთ.',
            'metadata' => ['products' => [
                ['name' => 'One', 'url' => 'https://books.test/one'],
                ['name' => 'Unsafe', 'url' => 'javascript:alert(1)'],
                ['name' => 'Two', 'url' => 'https://books.test/two'],
                ['name' => 'Three', 'url' => 'http://books.test/three'],
                ['name' => 'Four', 'url' => 'https://books.test/four'],
            ]],
        ]);

        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $text = data_get($delivery->payload, 'text');

        $this->assertStringContainsString('One: https://books.test/one', $text);
        $this->assertStringContainsString('Two: https://books.test/two', $text);
        $this->assertStringContainsString('Three: http://books.test/three', $text);
        $this->assertStringNotContainsString('javascript:', $text);
        $this->assertStringNotContainsString('books.test/four', $text);
    }

    public function test_ambiguous_send_timeout_is_not_retried_or_automatically_resent(): void
    {
        Queue::fake();
        config()->set('meta.retries', 3);
        $connection = $this->connection('facebook', 'page-timeout');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'timeout-customer',
            'external_thread_id' => 'customer-timeout',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'This may have been delivered.']);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('timeout with access_token=must-not-leak');
        });

        $job = new SendMetaMessage($delivery->id);
        $job->handle(app(MetaGraphClient::class));
        $job->handle(app(MetaGraphClient::class));

        $delivery->refresh();
        $this->assertSame(1, $calls);
        $this->assertSame('delivery_unknown', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertTrue($delivery->payload['content_removed']);
        $this->assertArrayNotHasKey('text', $delivery->payload);
        $this->assertStringNotContainsString('access_token', $delivery->failure_reason);
        $this->assertStringNotContainsString('must-not-leak', $connection->fresh()->last_error);
    }

    public function test_inbound_job_reuses_the_shared_conversation_engine_and_queues_its_reply(): void
    {
        Queue::fake();
        config()->set('services.openai.key', null);
        config()->set('legatus.offline_fallback_enabled', true);
        $connection = $this->connection('instagram', 'ig-123');
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'ig-mid-1',
            'provider_sender_id' => 'ig-customer-9',
            'provider_recipient_id' => 'ig-123',
            'message_type' => 'text',
            'status' => 'received',
            'payload' => ['text' => 'გამარჯობა'],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $inbound->refresh();
        $this->assertSame('processed', $inbound->status);
        $this->assertNotNull($inbound->conversation_id);
        $this->assertNotNull($inbound->message_id);
        $this->assertDatabaseHas('conversations', [
            'id' => $inbound->conversation_id,
            'channel' => 'instagram',
            'channel_connection_id' => $connection->id,
            'external_thread_id' => 'ig-customer-9',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $inbound->conversation_id,
            'role' => 'assistant',
        ]);
        $assistant = Message::query()
            ->where('conversation_id', $inbound->conversation_id)
            ->where('role', 'assistant')
            ->firstOrFail();
        $this->assertStringContainsString('AI ასისტენტი', $assistant->content);
        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $inbound->conversation_id,
            'direction' => 'outbound',
            'provider_recipient_id' => 'ig-customer-9',
            'status' => 'queued',
        ]);
        $this->assertArrayNotHasKey('text', $inbound->payload);
        $this->assertTrue($inbound->payload['content_removed']);
        Queue::assertPushed(SendMetaMessage::class, 1);
    }

    public function test_inbound_jobs_wait_for_older_messages_from_the_same_customer(): void
    {
        Queue::fake();
        config()->set('services.openai.key', null);
        config()->set('legatus.offline_fallback_enabled', true);
        $connection = $this->connection('facebook', 'page-ordered-inbound');
        $older = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'ordered-mid-1',
            'provider_sender_id' => 'ordered-customer',
            'provider_recipient_id' => 'page-ordered-inbound',
            'message_type' => 'text',
            'status' => 'received',
            'payload' => ['text' => 'Named product request'],
            'received_at' => now()->subSeconds(2),
        ]);
        $newer = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'ordered-mid-2',
            'provider_sender_id' => 'ordered-customer',
            'provider_recipient_id' => 'page-ordered-inbound',
            'message_type' => 'text',
            'status' => 'received',
            'payload' => ['text' => 'Follow-up stock request'],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($newer->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $this->assertSame('received', $newer->fresh()->status);
        $this->assertNull($newer->fresh()->conversation_id);

        (new ProcessMetaInboundMessage($older->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );
        (new ProcessMetaInboundMessage($newer->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $this->assertSame('processed', $older->fresh()->status);
        $this->assertSame('processed', $newer->fresh()->status);
        $this->assertSame($older->fresh()->conversation_id, $newer->fresh()->conversation_id);
        $this->assertSame(
            ['Named product request', 'Follow-up stock request'],
            $older->fresh()->conversation->messages()->where('role', 'customer')->orderBy('id')->pluck('content')->all(),
        );
    }

    public function test_completed_customer_exchange_is_recorded_without_a_meta_reply(): void
    {
        Queue::fake();
        config()->set('services.openai.key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response(['output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => '{"silent":true}']],
        ]]])]);
        $connection = $this->connection('facebook', 'page-closure');
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'closure-mid-1',
            'provider_sender_id' => 'closure-customer',
            'provider_recipient_id' => 'page-closure',
            'message_type' => 'text',
            'status' => 'received',
            'payload' => ['text' => 'მადლობა ყურადღებისთვის. ❤️ წიგნი აიღეს.'],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $inbound->refresh();
        $this->assertSame('processed', $inbound->status);
        $this->assertNotNull($inbound->message_id);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $inbound->conversation_id,
            'role' => 'assistant',
        ]);
        $this->assertDatabaseMissing('channel_messages', [
            'conversation_id' => $inbound->conversation_id,
            'direction' => 'outbound',
        ]);
        Queue::assertNotPushed(SendMetaMessage::class);
    }

    public function test_plain_thanks_after_an_operator_reply_and_ai_release_never_triggers_an_ai_introduction(): void
    {
        Queue::fake();
        config()->set('services.openai.key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response(['output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => '{"silent":true}']],
        ]]])]);
        $connection = $this->connection('facebook', 'page-operator-thanks');
        $senderId = 'operator-thanks-customer';
        $conversation = $connection->agent->conversations()->create([
            'visitor_id' => "meta:facebook:{$connection->id}:{$senderId}",
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $conversation->messages()->create(['role' => 'human', 'content' => 'არა, ეს წიგნი ამჟამად არ გვაქვს.']);
        $conversation->messages()->create(['role' => 'system', 'content' => 'Conversation returned to Legatus.']);
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'operator-thanks-mid-1',
            'provider_sender_id' => $senderId,
            'provider_recipient_id' => 'page-operator-thanks',
            'message_type' => 'text',
            'status' => 'received',
            'payload' => ['text' => 'მადლობა'],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $this->assertSame('processed', $inbound->fresh()->status);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'role' => 'customer', 'content' => 'მადლობა']);
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(0, $connection->channelMessages()->where('direction', 'outbound')->count());
        Queue::assertNotPushed(SendMetaMessage::class);
        Http::assertSentCount(1);
    }

    public function test_expired_connection_inbound_is_redacted_without_falsely_pausing_ai(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-expired-inbound');
        $connection->update(['token_expires_at' => now()->subMinute()]);
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'expired-mid-1',
            'provider_sender_id' => 'expired-customer',
            'provider_recipient_id' => 'page-expired-inbound',
            'message_type' => 'text',
            'status' => 'received',
            'payload' => ['text' => 'Please call +995 555 123 456 about my order.'],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $inbound->refresh();
        $this->assertSame('failed', $inbound->status);
        $this->assertTrue($inbound->payload['content_removed']);
        $this->assertArrayNotHasKey('text', $inbound->payload);
        $this->assertDatabaseHas('conversations', [
            'id' => $inbound->conversation_id,
            'status' => 'ai',
            'assigned_to' => null,
            'channel_connection_id' => $connection->id,
            'external_thread_id' => 'expired-customer',
        ]);
        $customer = $inbound->message()->firstOrFail();
        $this->assertSame('customer', $customer->role);
        $this->assertStringContainsString('[phone redacted]', $customer->content);
        $this->assertStringNotContainsString('+995 555 123 456', $customer->content);
        $this->assertTrue((bool) data_get($customer->metadata, 'transport_failure'));
    }

    public function test_final_inbound_job_failure_preserves_customer_request_before_payload_minimization(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-final-failure');
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'failed-mid-1',
            'provider_sender_id' => 'failed-customer',
            'provider_recipient_id' => 'page-final-failure',
            'message_type' => 'text',
            'status' => 'processing',
            'payload' => ['text' => 'Email me at customer@example.com when ready.'],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->failed(new \RuntimeException('worker stopped'));

        $inbound->refresh();
        $this->assertSame('failed', $inbound->status);
        $this->assertTrue($inbound->payload['content_removed']);
        $this->assertDatabaseHas('conversations', [
            'id' => $inbound->conversation_id,
            'status' => 'ai',
            'assigned_to' => null,
        ]);
        $customer = $inbound->message()->firstOrFail();
        $this->assertSame('Email me at [email redacted] when ready.', $customer->content);
        $this->assertSame($inbound->idempotency_key, $customer->request_id);
        $assistant = $customer->conversation->messages()->where('role', 'assistant')->sole();
        $this->assertTrue((bool) data_get($assistant->metadata, 'automatic_processing_failure'));
        $this->assertDatabaseHas('channel_messages', [
            'message_id' => $assistant->id,
            'direction' => 'outbound',
            'status' => 'queued',
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->failed(new \RuntimeException('duplicate callback'));
        $this->assertSame(1, $customer->conversation->messages()->where('request_id', $inbound->idempotency_key)->count());
        $this->assertSame(1, $customer->conversation->messages()->where('role', 'assistant')->count());
    }

    public function test_final_inbound_failure_never_returns_a_human_controlled_conversation_to_ai(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-human-failure');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'meta:facebook:'.$connection->id.':human-failure-customer',
            'external_thread_id' => 'human-failure-customer',
            'channel' => 'facebook',
            'status' => 'human',
            'assigned_to' => 'Meta inbox',
        ]);
        $inbound = $connection->channelMessages()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'provider_message_id' => 'human-failure-mid',
            'provider_sender_id' => 'human-failure-customer',
            'provider_recipient_id' => 'page-human-failure',
            'message_type' => 'attachment',
            'status' => 'processing',
            'payload' => ['text' => '[Customer sent an image.]', 'attachments' => [['type' => 'image']]],
            'received_at' => now(),
        ]);

        (new ProcessMetaInboundMessage($inbound->id))->failed(new \RuntimeException('worker timed out'));

        $this->assertSame('human', $conversation->fresh()->status);
        $this->assertSame('Meta inbox', $conversation->fresh()->assigned_to);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
        ]);
    }

    public function test_operator_takeover_during_image_recognition_discards_the_late_ai_reply(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-image-human-race');
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound',
            'provider_message_id' => 'image-human-race-mid',
            'provider_sender_id' => 'image-human-race-customer',
            'provider_recipient_id' => 'page-image-human-race',
            'message_type' => 'attachment',
            'status' => 'received',
            'payload' => [
                'text' => 'Do you have this product?',
                'attachments' => [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/product.jpg']],
            ],
            'received_at' => now(),
        ]);
        $matcher = \Mockery::mock(CustomerImageCatalogMatcher::class);
        $matcher->shouldReceive('resolve')->once()->andReturnUsing(function ($agent, $conversation): array {
            $conversation->update(['status' => 'human', 'assigned_to' => 'Meta inbox']);

            return [
                'status' => 'not_found',
                'text' => 'Late and now contradictory AI reply.',
                'products' => [],
                'product_ids' => [],
                'tools_used' => [],
                'model' => 'test-vision',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'requests' => 1],
            ];
        });

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
            $matcher,
        );

        $conversation = $connection->conversations()->sole();
        $this->assertSame('human', $conversation->status);
        $this->assertSame('processed', $inbound->fresh()->status);
        $this->assertSame('human', data_get($inbound->fresh()->payload, 'image_recognition_status'));
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(0, $connection->channelMessages()->where('direction', 'outbound')->count());
    }

    public function test_attachment_only_and_ungrounded_postback_events_are_preserved_without_pausing_ai(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-unsupported-media');
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-unsupported-media',
                'messaging' => [
                    [
                        'sender' => ['id' => 'media-customer'],
                        'recipient' => ['id' => 'page-unsupported-media'],
                        'timestamp' => 1784512800000,
                        'message' => ['mid' => 'attachment-mid', 'attachments' => [['type' => 'image']]],
                    ],
                    [
                        'sender' => ['id' => 'media-customer'],
                        'recipient' => ['id' => 'page-unsupported-media'],
                        // Meta may deliver the caption/question much later than the image event.
                        'timestamp' => 1784513400000,
                        'postback' => ['payload' => 'INTERNAL_MACHINE_TOKEN'],
                    ],
                ],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();
        $inbound = $connection->channelMessages()->where('direction', 'inbound')->orderBy('id')->get();
        $this->assertCount(2, $inbound);
        $this->assertTrue((bool) data_get($inbound[0]->payload, 'requires_human'));
        $this->assertTrue((bool) data_get($inbound[1]->payload, 'requires_human'));

        foreach ($inbound as $record) {
            (new ProcessMetaInboundMessage($record->id))->handle(
                app(ConversationEngine::class),
                app(ChannelMessageDispatcher::class),
            );
        }

        $conversation = $connection->conversations()->firstOrFail();
        $this->assertSame('ai', $conversation->status);
        $this->assertSame(2, $conversation->messages()->where('role', 'customer')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(1, $connection->channelMessages()->where('direction', 'outbound')->count());
        $this->assertTrue((bool) data_get(
            $conversation->messages()->where('role', 'assistant')->sole()->metadata,
            'attachment_unavailable',
        ));
        $this->assertStringNotContainsString('INTERNAL_MACHINE_TOKEN', $conversation->messages()->pluck('content')->implode(' '));
    }

    public function test_passive_story_mention_is_preserved_without_an_automated_reply(): void
    {
        Queue::fake();
        $connection = $this->connection('instagram', 'instagram-story-account');
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'instagram-story-account',
                'messaging' => [[
                    'sender' => ['id' => 'story-author'],
                    'recipient' => ['id' => 'instagram-story-account'],
                    'timestamp' => 1784512800000,
                    'message' => [
                        'mid' => 'story-mention-mid',
                        'attachments' => [[
                            'type' => 'story_mention',
                            'payload' => ['url' => 'https://scontent.example/story.jpg'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        $inbound = $connection->channelMessages()->where('direction', 'inbound')->sole();
        $this->assertTrue((bool) data_get($inbound->payload, 'suppress_reply'));

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $conversation = $connection->conversations()->sole();
        $customer = $conversation->messages()->where('role', 'customer')->sole();
        $this->assertSame('processed', $inbound->fresh()->status);
        $this->assertTrue((bool) data_get($customer->metadata, 'automated_reply_suppressed'));
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(0, $connection->channelMessages()->where('direction', 'outbound')->count());
    }

    public function test_customer_image_is_visually_matched_then_verified_against_catalog_and_stock(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-image-limitation');
        $product = $connection->agent->products()->create([
            'name' => 'არტისტული ყვავილები', 'sku' => 'ART-2052', 'category' => 'პოეზია',
            'search_text' => 'არტისტული ყვავილები გალაკტიონ ტაბიძე', 'price' => 14, 'stock' => 12,
            'image' => 'https://catalog.example/artistuli.jpg',
            'metadata' => ['author' => 'გალაკტიონ ტაბიძე', 'product_url' => 'https://shop.example/books/2052'],
            'is_active' => true,
        ]);
        $imageUrl = 'https://scontent.xx.fbcdn.net/customer-product.jpg';
        $this->fakeCustomerImageVision($imageUrl, [
            $this->customerImageIdentity('არტისტული ყვავილები', 'გალაკტიონ ტაბიძე'),
            ['same_product' => true, 'matched_product_id' => $product->id, 'reason' => 'Exact title, author, and cover match.'],
        ]);
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-image-limitation',
                'messaging' => [[
                    'sender' => ['id' => 'image-customer'],
                    'recipient' => ['id' => 'page-image-limitation'],
                    'timestamp' => 1784512800000,
                    'message' => [
                        'mid' => 'image-limitation-mid',
                        'text' => 'ეს წიგნი გაქვთ?',
                        'attachments' => [['type' => 'image', 'payload' => ['url' => $imageUrl]]],
                    ],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        $inbound = $connection->channelMessages()->where('direction', 'inbound')->sole();
        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $conversation = $connection->conversations()->firstOrFail();
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertSame('ai', $conversation->status);
        $this->assertStringContainsString('ზუსტად ეს პროდუქტი გადავამოწმე', $assistant->content);
        $this->assertStringContainsString('ხელმისაწვდომია', $assistant->content);
        $this->assertSame('available', data_get($assistant->metadata, 'image_recognition_status'));
        $this->assertSame($product->id, data_get($assistant->metadata, 'products.0.id'));
        $this->assertTrue(Str::isUuid($assistant->request_id));
        $this->assertSame(36, strlen($assistant->request_id));
        $this->assertDatabaseHas('channel_messages', [
            'message_id' => $assistant->id,
            'direction' => 'outbound',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'route' => 'image_catalog_match',
            'status' => 'completed',
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/responses'));
    }

    #[DataProvider('splitImageProcessingOrders')]
    public function test_text_sent_with_a_customer_image_cannot_produce_a_false_catalog_denial(bool $imageEventFirst, bool $textJobFirst): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-split-image');
        $imageUrl = 'https://scontent.xx.fbcdn.net/book.jpg';
        $identity = $this->customerImageIdentity(null, null);
        $identity['identifying_text_readable'] = false;
        $identity['image_is_product'] = true;
        $identity['uncertainty_reason'] = 'The title is blurred.';
        $this->fakeCustomerImageVision($imageUrl, [$identity]);
        $imageEvent = [
            'sender' => ['id' => 'split-image-customer'],
            'recipient' => ['id' => 'page-split-image'],
            'timestamp' => $imageEventFirst ? 1784512800000 : 1784512801000,
            'message' => [
                'mid' => 'split-image-mid',
                'attachments' => [['type' => 'image', 'payload' => ['url' => $imageUrl]]],
            ],
        ];
        $textEvent = [
            'sender' => ['id' => 'split-image-customer'],
            'recipient' => ['id' => 'page-split-image'],
            'timestamp' => $imageEventFirst ? 1784512801000 : 1784512800000,
            'message' => [
                'mid' => 'split-image-question-mid',
                'text' => 'Do you have this product?',
            ],
        ];
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-split-image',
                'messaging' => $imageEventFirst ? [$imageEvent, $textEvent] : [$textEvent, $imageEvent],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        $records = $connection->channelMessages()->where('direction', 'inbound')->orderBy('id')->get();
        $this->assertCount(2, $records);

        $textRecord = $records->firstWhere('message_type', 'text');
        $imageRecord = $records->firstWhere('message_type', 'attachment');
        $processingOrder = $textJobFirst
            ? collect([$textRecord, $imageRecord])
            : collect([$imageRecord, $textRecord]);
        foreach ($processingOrder as $record) {
            (new ProcessMetaInboundMessage($record->id))->handle(
                app(ConversationEngine::class),
                app(ChannelMessageDispatcher::class),
            );
        }

        $conversation = $connection->conversations()->sole();
        $assistantMessages = $conversation->messages()->where('role', 'assistant')->get();
        $this->assertCount(1, $assistantMessages);
        $this->assertTrue($assistantMessages->every(
            fn ($message): bool => data_get($message->metadata, 'image_recognition_status') === 'uncertain'
                && str_contains($message->content, 'exact identity')
                && Str::isUuid($message->request_id)
                && strlen($message->request_id) === 36,
        ));
        $allReplies = $assistantMessages->pluck('content')->implode(' ');
        $this->assertStringNotContainsString('exact product was not found', $allReplies);
        $this->assertStringNotContainsString('similar alternatives', $allReplies);
        $this->assertSame('processed', $records->first()->fresh()->status);
        $this->assertSame('processed', $records->last()->fresh()->status);
    }

    public static function splitImageProcessingOrders(): array
    {
        return [
            'image event first and text job first' => [true, true],
            'image event first and image job first' => [true, false],
            'text event first and text job first' => [false, true],
            'text event first and image job first' => [false, false],
        ];
    }

    public function test_connect_route_uses_oauth_state_and_never_exposes_app_secret(): void
    {
        [$user] = $this->tenant();

        $response = $this->actingAs($user)->get('/app/channels/meta/facebook/connect');

        $response->assertRedirectContains('https://www.facebook.test/v25.0/dialog/oauth?');
        $response->assertSessionHas('meta_oauth.provider', 'facebook');
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertContains('pages_read_user_content', explode(',', (string) ($query['scope'] ?? '')));
        $this->assertStringNotContainsString('meta-app-secret', $location);
    }

    public function test_business_login_configuration_is_sent_without_exposing_a_secret(): void
    {
        [$user] = $this->tenant();
        config()->set('meta.login_config_id', 'business-login-config-123');

        $response = $this->actingAs($user)->get('/app/channels/meta/meta/connect');

        $response->assertRedirectContains('config_id=business-login-config-123');
        $response->assertRedirectContains('override_default_response_type=true');
        $this->assertStringNotContainsString('scope=', $response->headers->get('Location'));
        $this->assertStringNotContainsString('meta-app-secret', $response->headers->get('Location'));
    }

    public function test_oauth_callback_can_use_the_one_time_server_state_when_session_payload_is_lost(): void
    {
        [$user, $agent] = $this->tenant();
        $this->actingAs($user)->get('/app/channels/meta/meta/connect')->assertRedirect();
        $state = session('meta_oauth.state');
        session()->forget('meta_oauth');

        Http::fakeSequence()
            ->push(['access_token' => 'short-user-token'])
            ->push(['access_token' => 'long-user-token'])
            ->push(['data' => [[
                'id' => 'cache-state-page',
                'name' => 'Cache State Page',
                'access_token' => 'cache-state-page-token',
            ]]])
            ->push(['success' => true]);

        $this->get('/auth/meta/meta/callback?state='.urlencode($state).'&code=valid-code')
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('channel_connections', [
            'agent_id' => $agent->id,
            'provider' => 'facebook',
            'external_account_id' => 'cache-state-page',
        ]);
    }

    public function test_one_meta_oauth_connects_managed_facebook_page_and_linked_instagram_account(): void
    {
        [$user, $agent] = $this->tenant();

        $response = $this->actingAs($user)->get('/app/channels/meta/meta/connect');
        $response->assertRedirectContains('https://www.facebook.test/v25.0/dialog/oauth?');
        $response->assertSessionHas('meta_oauth.provider', 'meta');
        $state = session('meta_oauth.state');

        Http::fakeSequence()
            ->push(['access_token' => 'short-user-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-user-token', 'expires_in' => 5184000])
            ->push(['data' => [[
                'id' => 'managed-page',
                'name' => 'Bukinistebi',
                'access_token' => 'managed-page-token',
                'instagram_business_account' => [
                    'id' => 'linked-instagram',
                    'username' => 'bukinistebi.ge',
                ],
            ]]])
            ->push(['success' => true]);

        $this->get('/auth/meta/meta/callback?state='.urlencode($state).'&code=valid-code')
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Facebook Messenger')
                && str_contains($message, 'Instagram Direct'));

        $facebook = $agent->channelConnections()->where('provider', 'facebook')->firstOrFail();
        $instagram = $agent->channelConnections()->where('provider', 'instagram')->firstOrFail();
        $this->assertSame('managed-page', $facebook->external_account_id);
        $this->assertSame('linked-instagram', $instagram->external_account_id);
        $this->assertSame('managed-page', data_get($instagram->metadata, 'facebook_page_id'));
        $this->assertSame('managed-page-token', $facebook->access_token);
        $this->assertSame('managed-page-token', $instagram->access_token);
        Http::assertSentCount(4);
    }

    public function test_unified_meta_selection_never_offers_a_page_owned_by_another_workspace(): void
    {
        [$user, $agent] = $this->tenant();
        $otherUser = User::factory()->create();
        $otherOrganization = Organization::query()->create(['name' => 'Other business', 'slug' => 'other-business']);
        $otherOrganization->users()->attach($otherUser, ['role' => 'owner']);
        $otherAgent = $otherOrganization->agents()->create([
            'name' => 'Other assistant',
            'slug' => 'other-business-agent',
            'business_name' => 'Other business',
            'channels' => ['web', 'facebook', 'instagram'],
            'settings' => [],
            'is_active' => true,
        ]);
        $otherAgent->channelConnections()->create([
            'provider' => 'facebook',
            'status' => 'active',
            'external_account_id' => 'already-owned-page',
            'external_account_name' => 'Other page',
            'access_token' => 'other-page-token',
        ]);

        $this->actingAs($user)->get('/app/channels/meta/meta/connect')->assertRedirect();
        $state = session('meta_oauth.state');
        Http::fakeSequence()
            ->push(['access_token' => 'short-token'])
            ->push(['access_token' => 'long-token'])
            ->push(['data' => [[
                'id' => 'already-owned-page',
                'name' => 'Other page',
                'access_token' => 'returned-page-token',
            ]]]);

        $this->get('/auth/meta/meta/callback?state='.urlencode($state).'&code=valid-code')
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('channel_connections', [
            'agent_id' => $agent->id,
            'external_account_id' => 'already-owned-page',
        ]);
    }

    public function test_oauth_callback_discovers_subscribes_and_encrypts_a_page_connection(): void
    {
        [$user, $agent] = $this->tenant();
        $this->actingAs($user)->get('/app/channels/meta/facebook/connect')->assertRedirect();
        $state = session('meta_oauth.state');
        Http::fakeSequence()
            ->push(['access_token' => 'short-user-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-user-token', 'expires_in' => 5184000])
            ->push(['data' => [[
                'id' => 'page-oauth',
                'name' => 'Bukinistebi',
                'access_token' => 'page-oauth-token',
            ]]])
            ->push(['success' => true]);

        $this->get('/auth/meta/facebook/callback?state='.urlencode($state).'&code=valid-code')
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');

        $connection = $agent->channelConnections()->firstOrFail();
        $this->assertSame('active', $connection->status);
        $this->assertSame('page-oauth-token', $connection->access_token);
        $this->assertSame('page-oauth', $connection->external_account_id);
        $this->assertNull($connection->token_expires_at, 'The OAuth user-token expires_in must not be copied onto the Page token.');
        $rawToken = DB::table('channel_connections')->where('id', $connection->id)->value('access_token');
        $this->assertNotSame('page-oauth-token', $rawToken);
        $this->assertStringNotContainsString('page-oauth-token', $rawToken);
        Http::assertSentCount(4);
    }

    public function test_oauth_preserves_only_an_expiry_returned_for_the_page_token_itself(): void
    {
        [$user, $agent] = $this->tenant();
        $this->actingAs($user)->get('/app/channels/meta/facebook/connect')->assertRedirect();
        $state = session('meta_oauth.state');
        $pageExpiry = now()->addDay()->startOfSecond();
        Http::fakeSequence()
            ->push(['access_token' => 'short-user-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-user-token', 'expires_in' => 5184000])
            ->push(['data' => [[
                'id' => 'page-explicit-expiry',
                'name' => 'Bukinistebi',
                'access_token' => 'page-explicit-token',
                'access_token_expires_at' => $pageExpiry->timestamp,
            ]]])
            ->push(['success' => true]);

        $this->get('/auth/meta/facebook/callback?state='.urlencode($state).'&code=valid-code')
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');

        $connection = $agent->channelConnections()->firstOrFail();
        $this->assertSame($pageExpiry->timestamp, $connection->token_expires_at?->timestamp);
        $this->assertTrue($connection->isActive());
    }

    public function test_multiple_oauth_accounts_require_one_explicit_encrypted_selection(): void
    {
        [$user, $agent] = $this->tenant();
        $this->actingAs($user)->get('/app/channels/meta/facebook/connect')->assertRedirect();
        $state = session('meta_oauth.state');
        Http::fakeSequence()
            ->push(['access_token' => 'short-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-token', 'expires_in' => 5184000])
            ->push(['data' => [
                ['id' => 'page-one', 'name' => 'Page One', 'access_token' => 'page-token-one'],
                ['id' => 'page-two', 'name' => 'Page Two', 'access_token' => 'page-token-two'],
            ]])
            ->push(['success' => true]);

        $callback = $this->get('/auth/meta/facebook/callback?state='.urlencode($state).'&code=valid-code')
            ->assertRedirect();

        $this->assertDatabaseCount('channel_connections', 0);
        $pending = MetaOAuthSelection::query()->firstOrFail();
        $this->assertCount(2, $pending->candidates);
        $rawCandidates = DB::table('meta_oauth_selections')->where('id', $pending->id)->value('candidates');
        $this->assertStringNotContainsString('page-token-one', $rawCandidates);
        $this->assertStringNotContainsString('page-token-two', $rawCandidates);
        Http::assertSentCount(3);

        $selectionUrl = $callback->headers->get('Location');
        $this->get($selectionUrl)
            ->assertOk()
            ->assertSeeText('Page One')
            ->assertSeeText('Page Two')
            ->assertDontSee('page-token-one');
        $second = collect($pending->candidates)->firstWhere('external_account_id', 'page-two');
        $this->post($selectionUrl, ['candidate_id' => $second['candidate_id']])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');

        $connection = $agent->channelConnections()->firstOrFail();
        $this->assertSame('page-two', $connection->external_account_id);
        $this->assertSame('page-token-two', $connection->access_token);
        $this->assertDatabaseCount('channel_connections', 1);
        $this->assertDatabaseCount('meta_oauth_selections', 0);
        Http::assertSentCount(4);
    }

    public function test_disconnect_keeps_shared_page_subscription_until_the_last_active_sibling_is_removed(): void
    {
        [$user, $agent] = $this->tenant();
        $facebook = $agent->channelConnections()->create([
            'provider' => 'facebook',
            'status' => 'active',
            'external_account_id' => 'shared-page',
            'external_account_name' => 'Page',
            'access_token' => 'facebook-token',
        ]);
        $instagram = $agent->channelConnections()->create([
            'provider' => 'instagram',
            'status' => 'active',
            'external_account_id' => 'ig-business',
            'external_account_name' => 'Instagram',
            'access_token' => 'instagram-token',
            'metadata' => ['facebook_page_id' => 'shared-page'],
        ]);
        Http::fake(['https://graph.facebook.test/*' => Http::response(['success' => true])]);

        $this->actingAs($user)->delete('/app/channels/meta/'.$facebook->id)->assertRedirect();
        Http::assertSentCount(0);
        $this->assertDatabaseHas('channel_connections', ['id' => $instagram->id]);

        $this->delete('/app/channels/meta/'.$instagram->id)->assertRedirect();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/shared-page/subscribed_apps'));
    }

    public function test_native_meta_echo_becomes_one_human_inbox_message_without_looping_back_out(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-echo');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'meta:facebook:'.$connection->id.':customer-echo',
            'external_thread_id' => 'customer-echo',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-echo',
                'messaging' => [[
                    'sender' => ['id' => 'page-echo'],
                    'recipient' => ['id' => 'customer-echo'],
                    'timestamp' => 1784512800000,
                    'message' => ['mid' => 'native-echo-mid', 'is_echo' => true, 'text' => 'I replied from Meta Business Suite.'],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret');

        $this->metaWebhook($body, $signature)->assertOk();
        $this->metaWebhook($body, $signature)->assertOk();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'human',
            'content' => 'I replied from Meta Business Suite.',
        ]);
        $this->assertSame('human', $conversation->fresh()->status);
        $this->assertSame('Meta inbox', $conversation->fresh()->assigned_to);
        $this->assertDatabaseHas('channel_messages', [
            'provider_message_id' => 'native-echo-mid',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_native_human_echo_immediately_cancels_a_pending_ai_reply(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-echo-cancel');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'meta:facebook:'.$connection->id.':customer-echo-cancel',
            'external_thread_id' => 'customer-echo-cancel',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Late AI reply']);
        $pending = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-echo-cancel',
                'messaging' => [[
                    'sender' => ['id' => 'page-echo-cancel'],
                    'recipient' => ['id' => 'customer-echo-cancel'],
                    'timestamp' => 1784512800000,
                    'message' => ['mid' => 'native-cancel-mid', 'is_echo' => true, 'text' => 'We do not have this edition.'],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        $this->assertSame('human', $conversation->fresh()->status);
        $this->assertSame('ignored', $pending->fresh()->status);
        $this->assertStringContainsString('human operator controls', $pending->fresh()->failure_reason);
        $this->assertTrue((bool) data_get($pending->fresh()->payload, 'content_removed'));
    }

    public function test_app_echo_does_not_create_a_human_message_and_late_echo_cannot_downgrade_delivery(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-app-echo');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'app-echo-customer',
            'external_thread_id' => 'customer-app-echo',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Already delivered']);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $delivery->update(['provider_message_id' => 'app-echo-mid', 'status' => 'read', 'sent_at' => now()]);
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-app-echo',
                'messaging' => [[
                    'sender' => ['id' => 'page-app-echo'],
                    'recipient' => ['id' => 'customer-app-echo'],
                    'message' => ['mid' => 'app-echo-mid', 'is_echo' => true, 'app_id' => 'meta-app-id', 'text' => 'Already delivered'],
                ]],
            ]],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();

        $this->assertSame('read', $delivery->fresh()->status);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'role' => 'human']);
    }

    public function test_instagram_allows_georgian_text_by_character_count_and_stays_below_one_thousand_characters(): void
    {
        Queue::fake();
        $connection = $this->connection('instagram', 'ig-byte-limit');
        $connection->update(['metadata' => ['facebook_page_id' => 'page-byte-limit']]);
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'byte-limit-customer',
            'external_thread_id' => 'ig-byte-customer',
            'channel' => 'instagram',
            'status' => 'ai',
        ]);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => str_repeat('წ', 900),
            'metadata' => ['products' => [['name' => 'Book', 'url' => 'https://books.test/book']]],
        ]);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $this->assertStringContainsString('https://books.test/book', $delivery->payload['text']);
        Http::fake(['https://graph.facebook.test/*' => Http::response(['message_id' => 'ig-byte-mid'])]);

        (new SendMetaMessage($delivery->id))->handle(app(MetaGraphClient::class));

        Http::assertSent(function ($request): bool {
            $text = data_get($request->data(), 'message.text');

            return is_string($text)
                && mb_strlen($text, 'UTF-8') < 1000
                && strlen($text) > 1000
                && mb_check_encoding($text, 'UTF-8')
                && str_contains($text, 'https://books.test/book');
        });
    }

    public function test_instagram_character_budget_never_sends_a_partial_product_url(): void
    {
        Queue::fake();
        $connection = $this->connection('instagram', 'ig-atomic-link');
        $connection->update(['metadata' => ['facebook_page_id' => 'page-atomic-link']]);
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'atomic-link-customer',
            'external_thread_id' => 'ig-atomic-customer',
            'channel' => 'instagram',
            'status' => 'ai',
        ]);
        $url = 'https://books.test/products/'.str_repeat('a', 180);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => str_repeat('Georgian recommendation. ', 80),
            'metadata' => ['products' => [['name' => 'Long link book', 'url' => $url]]],
        ]);

        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $text = (string) data_get($delivery->payload, 'text');

        $this->assertLessThan(1000, mb_strlen($text, 'UTF-8'));
        $this->assertStringContainsString($url, $text);

        Http::fake(['https://graph.facebook.test/*' => Http::response(['message_id' => 'atomic-link-mid'])]);
        (new SendMetaMessage($delivery->id))->handle(app(MetaGraphClient::class));

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'message.text') === $text);
    }

    public function test_facebook_character_budget_never_sends_a_partial_product_url(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-atomic-link');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'facebook-atomic-customer',
            'external_thread_id' => 'facebook-atomic-customer',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $url = 'https://books.test/products/'.str_repeat('b', 220);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => str_repeat('Recommendation text. ', 150),
            'metadata' => ['products' => [['name' => 'Complete link book', 'url' => $url]]],
        ]);

        $delivery = app(ChannelMessageDispatcher::class)->dispatch($assistant);
        $text = (string) data_get($delivery->payload, 'text');

        $this->assertLessThanOrEqual(2000, mb_strlen($text, 'UTF-8'));
        $this->assertStringContainsString($url, $text);
    }

    public function test_meta_http_failure_classes_are_terminal_except_rate_limit(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-http-status');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'http-status-customer',
            'external_thread_id' => 'http-customer',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);

        $httpStatus = 400;
        Http::fake(function () use (&$httpStatus) {
            return Http::response(['error' => ['message' => 'provider error']], $httpStatus);
        });
        $permanentMessage = $conversation->messages()->create(['role' => 'human', 'content' => 'Permanent']);
        $permanent = app(ChannelMessageDispatcher::class)->dispatch($permanentMessage);
        (new SendMetaMessage($permanent->id))->handle(app(MetaGraphClient::class));
        $this->assertSame('failed', $permanent->fresh()->status);
        (new SendMetaMessage($permanent->id))->handle(app(MetaGraphClient::class));
        Http::assertSentCount(1);

        $httpStatus = 500;
        $ambiguousMessage = $conversation->messages()->create(['role' => 'human', 'content' => 'Ambiguous']);
        $ambiguous = app(ChannelMessageDispatcher::class)->dispatch($ambiguousMessage);
        (new SendMetaMessage($ambiguous->id))->handle(app(MetaGraphClient::class));
        $this->assertSame('delivery_unknown', $ambiguous->fresh()->status);

        $httpStatus = 429;
        $limitedMessage = $conversation->messages()->create(['role' => 'human', 'content' => 'Rate limited']);
        $limited = app(ChannelMessageDispatcher::class)->dispatch($limitedMessage);
        try {
            (new SendMetaMessage($limited->id))->handle(app(MetaGraphClient::class));
            $this->fail('A 429 response must remain eligible for a queue retry.');
        } catch (RequestException) {
            $this->assertSame('retrying', $limited->fresh()->status);
        }
    }

    public function test_read_receipt_requires_a_watermark_and_never_marks_queued_or_downgrades_read(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-read');
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'read-customer',
            'external_thread_id' => 'customer-read',
            'channel' => 'facebook',
            'status' => 'ai',
        ]);
        $queuedMessage = $conversation->messages()->create(['role' => 'human', 'content' => 'Queued']);
        $queued = app(ChannelMessageDispatcher::class)->dispatch($queuedMessage);
        $sentMessage = $conversation->messages()->create(['role' => 'human', 'content' => 'Sent']);
        $sent = app(ChannelMessageDispatcher::class)->dispatch($sentMessage);
        $sent->update(['provider_message_id' => 'read-mid', 'status' => 'sent', 'sent_at' => now()->subSecond()]);

        $withoutWatermark = ['object' => 'page', 'entry' => [['id' => 'page-read', 'messaging' => [[
            'sender' => ['id' => 'customer-read'], 'recipient' => ['id' => 'page-read'], 'read' => [],
        ]]]]];
        $body = json_encode($withoutWatermark);
        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();
        $this->assertSame('sent', $sent->fresh()->status);

        $withWatermark = ['object' => 'page', 'entry' => [['id' => 'page-read', 'messaging' => [[
            'sender' => ['id' => 'customer-read'], 'recipient' => ['id' => 'page-read'], 'read' => ['watermark' => now()->getTimestampMs()],
        ]]]]];
        $body = json_encode($withWatermark);
        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();
        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertSame('read', $sent->fresh()->status);

        $lateDelivery = ['object' => 'page', 'entry' => [['id' => 'page-read', 'messaging' => [[
            'delivery' => ['mids' => ['read-mid']], 'timestamp' => now()->getTimestampMs(),
        ]]]]];
        $body = json_encode($lateDelivery);
        $this->metaWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'))->assertOk();
        $this->assertSame('read', $sent->fresh()->status);
    }

    public function test_outbox_sweeper_recovers_stranded_rows_and_queue_retry_after_exceeds_job_timeout(): void
    {
        Queue::fake();
        config()->set('meta.outbox_stale_seconds', 15);
        $connection = $this->connection('facebook', 'page-outbox');
        $inbound = $connection->channelMessages()->create([
            'direction' => 'inbound', 'provider_message_id' => 'stranded-in', 'provider_sender_id' => 'customer',
            'provider_recipient_id' => 'page-outbox', 'status' => 'received', 'payload' => ['text' => 'Hello'],
        ]);
        $outbound = $connection->channelMessages()->create([
            'direction' => 'outbound', 'provider_recipient_id' => 'customer', 'status' => 'queued',
            'payload' => ['text' => 'Hello back'],
        ]);
        $processing = $connection->channelMessages()->create([
            'direction' => 'inbound', 'provider_message_id' => 'stranded-processing', 'provider_sender_id' => 'customer',
            'provider_recipient_id' => 'page-outbox', 'status' => 'processing', 'payload' => ['text' => 'Still processing'],
        ]);
        $sending = $connection->channelMessages()->create([
            'direction' => 'outbound', 'provider_recipient_id' => 'customer', 'status' => 'sending',
            'payload' => ['text' => 'Possibly sent', 'role' => 'assistant'],
        ]);
        $fresh = $connection->channelMessages()->create([
            'direction' => 'outbound', 'provider_recipient_id' => 'customer', 'status' => 'queued',
            'payload' => ['text' => 'Too fresh'],
        ]);
        $user = $connection->agent->organization->users()->firstOrFail();
        $expiredSelection = MetaOAuthSelection::query()->create([
            'agent_id' => $connection->agent_id,
            'user_id' => $user->id,
            'provider' => 'facebook',
            'selector_hash' => hash('sha256', 'expired-selection'),
            'candidates' => [['external_account_id' => 'expired', 'access_token' => 'expired-secret']],
            'expires_at' => now()->subMinute(),
        ]);
        DB::table('channel_messages')->whereIn('id', [$inbound->id, $processing->id])
            ->update(['updated_at' => now()->subMinutes(4)]);
        DB::table('channel_messages')->whereIn('id', [$outbound->id, $sending->id])
            ->update(['updated_at' => now()->subMinutes(2)]);

        $this->artisan('legatus:dispatch-channel-outbox')->assertSuccessful()->expectsOutput('Dispatched 3 eligible channel message(s).');

        Queue::assertPushed(ProcessMetaInboundMessage::class, fn ($job) => $job->channelMessageId === $inbound->id);
        Queue::assertPushed(ProcessMetaInboundMessage::class, fn ($job) => $job->channelMessageId === $processing->id);
        Queue::assertPushed(SendMetaMessage::class, fn ($job) => $job->channelMessageId === $outbound->id);
        Queue::assertNotPushed(SendMetaMessage::class, fn ($job) => $job->channelMessageId === $sending->id);
        Queue::assertNotPushed(SendMetaMessage::class, fn ($job) => $job->channelMessageId === $fresh->id);
        $this->assertSame('delivery_unknown', $sending->fresh()->status);
        $this->assertTrue($sending->fresh()->payload['content_removed']);
        $this->assertDatabaseMissing('meta_oauth_selections', ['id' => $expiredSelection->id]);
        $this->assertGreaterThan((new ProcessMetaInboundMessage($inbound->id))->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_outbox_sweeper_reports_queue_insertion_failure_and_leaves_the_durable_row_retryable(): void
    {
        $connection = $this->connection('facebook', 'page-outbox-failure');
        $outbound = $connection->channelMessages()->create([
            'direction' => 'outbound',
            'provider_recipient_id' => 'customer',
            'status' => 'queued',
            'payload' => ['text' => 'Retry me safely'],
        ]);
        $staleAt = now()->subMinutes(2)->startOfSecond();
        DB::table('channel_messages')->where('id', $outbound->id)->update(['updated_at' => $staleAt]);

        $bus = \Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->with(\Mockery::on(fn ($job) => $job instanceof SendMetaMessage && $job->channelMessageId === $outbound->id))
            ->andThrow(new \RuntimeException('Simulated queue outage with private infrastructure details.'));
        $this->app->instance(BusDispatcher::class, $bus);
        Log::spy();

        $this->artisan('legatus:dispatch-channel-outbox')
            ->expectsOutput('Dispatched 0 eligible channel message(s).')
            ->expectsOutput("Failed to enqueue 1 channel message(s). Durable rows remain pending for retry. Message IDs: {$outbound->id}.")
            ->assertFailed();

        $outbound->refresh();
        $this->assertSame('queued', $outbound->status);
        $this->assertTrue($outbound->updated_at->equalTo($staleAt));
        $this->assertNull($outbound->failure_reason);
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Failed to enqueue a durable channel outbox message.', \Mockery::on(
                fn (array $context): bool => $context === [
                    'channel_message_id' => $outbound->id,
                    'direction' => 'outbound',
                    'exception_class' => \RuntimeException::class,
                ],
            ));
    }

    public function test_operator_inbox_exposes_delivery_failure_without_provider_secrets(): void
    {
        [$user, $agent] = $this->tenant();
        $connection = $agent->channelConnections()->create([
            'provider' => 'facebook', 'status' => 'active', 'external_account_id' => 'page-inbox-status',
            'external_account_name' => 'Page', 'access_token' => 'secret-token',
        ]);
        $conversation = $agent->conversations()->create([
            'channel_connection_id' => $connection->id, 'visitor_id' => 'status-customer',
            'external_thread_id' => 'customer-status', 'channel' => 'facebook', 'status' => 'human',
        ]);
        $message = $conversation->messages()->create(['role' => 'human', 'content' => 'Status test']);
        $connection->channelMessages()->create([
            'conversation_id' => $conversation->id, 'message_id' => $message->id, 'direction' => 'outbound',
            'provider_recipient_id' => 'customer-status', 'status' => 'delivery_unknown',
            'failure_reason' => 'provider secret detail', 'payload' => ['content_removed' => true],
        ]);

        $this->actingAs($user)->get('/app/inbox?conversation='.$conversation->id)
            ->assertOk()
            ->assertSeeText('Delivery is uncertain. Check the native Meta inbox before resending.')
            ->assertDontSee('provider secret detail');
        $this->getJson('/app/inbox/'.$conversation->id.'/poll')
            ->assertOk()
            ->assertJsonPath('messages.0.delivery_status', 'delivery_unknown')
            ->assertJsonMissing(['provider secret detail']);
    }

    public function test_disconnect_deletes_local_credentials_even_when_meta_unsubscribe_fails(): void
    {
        [$user, $agent] = $this->tenant();
        $connection = $agent->channelConnections()->create([
            'provider' => 'facebook',
            'status' => 'active',
            'external_account_id' => 'page-disconnect',
            'external_account_name' => 'Bukinistebi',
            'access_token' => 'token-to-delete',
            'connected_at' => now(),
        ]);
        Http::fake(['https://graph.facebook.test/*' => Http::response(['error' => ['message' => 'offline']], 503)]);

        $this->actingAs($user)->delete('/app/channels/meta/'.$connection->id)
            ->assertRedirect(route('channels.index'));

        $this->assertDatabaseMissing('channel_connections', ['id' => $connection->id]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_starts_with($request->url(), 'https://graph.facebook.test/v25.0/page-disconnect/subscribed_apps?'));
    }

    public function test_reconciliation_recovers_a_recent_message_missed_by_the_webhook(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-reconcile');
        Http::fake(['https://graph.facebook.test/*' => Http::response([
            'data' => [[
                'id' => 'thread-1',
                'updated_time' => now()->toIso8601String(),
                'messages' => ['data' => [
                    [
                        'id' => 'mid-reconciled-inbound',
                        'message' => 'Do you have this product?',
                        'from' => ['id' => 'customer-reconcile', 'name' => 'Customer'],
                        'to' => ['data' => [['id' => 'page-reconcile']]],
                        'created_time' => now()->subMinute()->toIso8601String(),
                    ],
                    [
                        'id' => 'mid-page-outbound',
                        'message' => 'Previous reply',
                        'from' => ['id' => 'page-reconcile', 'name' => 'Page'],
                        'to' => ['data' => [['id' => 'customer-reconcile']]],
                        'created_time' => now()->subSeconds(30)->toIso8601String(),
                    ],
                ]],
            ]],
        ])]);

        $this->artisan('legatus:reconcile-meta-inbox --lookback=5')
            ->expectsOutput('Meta inbox reconciliation complete: 1 new message(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('channel_messages', [
            'channel_connection_id' => $connection->id,
            'direction' => 'inbound',
            'provider_message_id' => 'mid-reconciled-inbound',
            'provider_sender_id' => 'customer-reconcile',
            'status' => 'received',
        ]);
        $this->assertTrue((bool) data_get(ChannelMessage::query()->firstOrFail()->payload, 'reconciled_from_graph'));
        $this->assertNotEmpty(data_get($connection->fresh()->metadata, 'inbox_reconciled_at'));
        Queue::assertPushed(ProcessMetaInboundMessage::class, 1);

        $this->artisan('legatus:reconcile-meta-inbox --lookback=5')
            ->expectsOutput('Meta inbox reconciliation complete: 0 new message(s).')
            ->assertSuccessful();
        $this->assertDatabaseCount('channel_messages', 1);
    }

    public function test_reconciliation_preserves_the_encrypted_image_url_for_safe_vision_processing(): void
    {
        Queue::fake();
        $connection = $this->connection('facebook', 'page-reconciled-image');
        Http::fake(['https://graph.facebook.test/*' => Http::response([
            'data' => [[
                'id' => 'thread-image',
                'updated_time' => now()->toIso8601String(),
                'messages' => ['data' => [[
                    'id' => 'mid-reconciled-image',
                    'message' => 'Do you have this product?',
                    'from' => ['id' => 'customer-image', 'name' => 'Customer'],
                    'to' => ['data' => [['id' => 'page-reconciled-image']]],
                    'created_time' => now()->subMinute()->toIso8601String(),
                    'attachments' => ['data' => [[
                        'mime_type' => 'image/jpeg',
                        'name' => 'customer-product.jpg',
                        'image_data' => ['url' => 'https://scontent.example/private-image.jpg'],
                    ]]],
                ]]],
            ]],
        ])]);

        $this->artisan('legatus:reconcile-meta-inbox --lookback=5')
            ->expectsOutput('Meta inbox reconciliation complete: 1 new message(s).')
            ->assertSuccessful();

        $inbound = $connection->channelMessages()->where('direction', 'inbound')->sole();
        $this->assertSame('attachment', $inbound->message_type);
        $this->assertSame([['type' => 'image', 'url' => 'https://scontent.example/private-image.jpg']], $inbound->payload['attachments']);
        $this->assertStringNotContainsString('private-image.jpg', (string) DB::table('channel_messages')->where('id', $inbound->id)->value('payload'));

        (new ProcessMetaInboundMessage($inbound->id))->handle(
            app(ConversationEngine::class),
            app(ChannelMessageDispatcher::class),
        );

        $assistant = $connection->conversations()->sole()->messages()->where('role', 'assistant')->sole();
        $this->assertTrue((bool) data_get($assistant->metadata, 'image_recognition_failed'));
        $this->assertStringContainsString('could not process the photo reliably', $assistant->content);
        Http::assertSent(fn ($request): bool => str_contains((string) data_get($request->data(), 'fields'), 'attachments'));
    }

    private function metaWebhook(string $body, string $signature)
    {
        return $this->call('POST', '/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
    }

    private function customerImageIdentity(?string $name, ?string $creator): array
    {
        return [
            'image_is_product' => true,
            'identifying_text_readable' => $name !== null,
            'exact_name' => $name,
            'creator' => $creator,
            'brand' => null,
            'model' => null,
            'isbn' => null,
            'barcode' => null,
            'variant' => null,
            'visible_text' => array_values(array_filter([$name, $creator])),
            'uncertainty_reason' => '',
        ];
    }

    private function fakeCustomerImageVision(string $imageUrl, array $structuredResponses): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.image_recognition_model' => 'gpt-5.6-sol']);
        $image = UploadedFile::fake()->image('customer.jpg', 900, 1200);
        $bytes = file_get_contents($image->getRealPath());
        $responses = collect($structuredResponses);

        Http::fake(function ($request) use ($imageUrl, $bytes, $responses) {
            if ($request->url() === $imageUrl) {
                return Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_ends_with($request->url(), '/responses')) {
                return Http::response([
                    'id' => 'vision-'.str()->random(6),
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => json_encode($responses->shift(), JSON_UNESCAPED_UNICODE)]],
                    ]],
                    'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function connection(string $provider, string $externalId): ChannelConnection
    {
        [, $agent] = $this->tenant();

        return $agent->channelConnections()->create([
            'provider' => $provider,
            'status' => 'active',
            'external_account_id' => $externalId,
            'external_account_name' => 'Bukinistebi',
            'access_token' => 'page-access-token',
            'connected_at' => now(),
        ]);
    }

    private function tenant(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Bukinistebi', 'slug' => 'bukinistebi']);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Legatus',
            'slug' => 'bukinistebi-'.str()->random(8),
            'business_name' => 'Bukinistebi',
            'channels' => ['web', 'facebook', 'instagram'],
            'settings' => [],
            'is_active' => true,
        ]);

        return [$user, $agent];
    }
}
