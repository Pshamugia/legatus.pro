<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaInboundMessage;
use App\Jobs\SendMetaMessage;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\MetaOAuthSelection;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChannelMessageDispatcher;
use App\Services\ConversationEngine;
use App\Services\MetaGraphClient;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

    public function test_expired_connection_inbound_is_redacted_and_preserved_for_the_human_inbox(): void
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
            'status' => 'human',
            'priority' => 'high',
            'assigned_to' => 'Meta inbox',
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
        $this->assertDatabaseHas('conversations', ['id' => $inbound->conversation_id, 'status' => 'human']);
        $customer = $inbound->message()->firstOrFail();
        $this->assertSame('Email me at [email redacted] when ready.', $customer->content);
        $this->assertSame($inbound->idempotency_key, $customer->request_id);

        (new ProcessMetaInboundMessage($inbound->id))->failed(new \RuntimeException('duplicate callback'));
        $this->assertSame(1, $customer->conversation->messages()->where('request_id', $inbound->idempotency_key)->count());
    }

    public function test_attachment_only_and_ungrounded_postback_events_go_to_a_human_without_ai_inference(): void
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
                        'timestamp' => 1784512801000,
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
        $this->assertSame('human', $conversation->status);
        $this->assertSame(2, $conversation->messages()->where('role', 'customer')->count());
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(0, $connection->channelMessages()->where('direction', 'outbound')->count());
        $this->assertStringNotContainsString('INTERNAL_MACHINE_TOKEN', $conversation->messages()->pluck('content')->implode(' '));
    }

    public function test_connect_route_uses_oauth_state_and_never_exposes_app_secret(): void
    {
        [$user] = $this->tenant();

        $response = $this->actingAs($user)->get('/app/channels/meta/facebook/connect');

        $response->assertRedirectContains('https://www.facebook.test/v25.0/dialog/oauth?');
        $response->assertSessionHas('meta_oauth.provider', 'facebook');
        $this->assertStringNotContainsString('meta-app-secret', $response->headers->get('Location'));
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
        DB::table('channel_messages')->whereIn('id', [$inbound->id, $outbound->id, $processing->id, $sending->id])
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

    private function metaWebhook(string $body, string $signature)
    {
        return $this->call('POST', '/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
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
