<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaInboundMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChannelMessageDispatcher;
use App\Services\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('whatsapp.app_secret', 'whatsapp-secret');
        config()->set('whatsapp.verify_token', 'verify-whatsapp');
        config()->set('whatsapp.graph_url', 'https://graph.whatsapp.test');
        config()->set('whatsapp.graph_version', 'v25.0');
    }

    public function test_webhook_verification_accepts_meta_query_parameters_after_php_normalization(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')
            ->assertForbidden();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-whatsapp&hub.challenge=123')
            ->assertOk()
            ->assertSeeText('123')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_signed_text_webhook_is_tenant_scoped_queued_and_deduplicated(): void
    {
        Queue::fake();
        $connection = $this->connection();
        $payload = $this->payload(['messages' => [[
            'from' => '995555111222', 'id' => 'wamid.in.1', 'timestamp' => (string) now()->timestamp,
            'type' => 'text', 'text' => ['body' => 'Do you have this product?'],
        ]]]);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'whatsapp-secret');

        $this->whatsappWebhook($body, 'sha256=wrong')->assertUnauthorized();
        $this->whatsappWebhook($body, $signature)->assertOk();
        $this->whatsappWebhook($body, $signature)->assertOk();

        $this->assertDatabaseCount('channel_messages', 1);
        $this->assertDatabaseHas('channel_messages', [
            'channel_connection_id' => $connection->id,
            'direction' => 'inbound',
            'provider_message_id' => 'wamid.in.1',
            'provider_sender_id' => '995555111222',
        ]);
        Queue::assertPushed(ProcessMetaInboundMessage::class, 1);
    }

    public function test_dispatcher_sends_whatsapp_reply_inside_customer_service_window(): void
    {
        Queue::fake();
        $connection = $this->connection();
        $connection->channelMessages()->create([
            'direction' => 'inbound', 'provider_message_id' => 'wamid.in.window',
            'provider_sender_id' => '995555111222', 'provider_recipient_id' => 'phone-123',
            'message_type' => 'text', 'status' => 'processed', 'payload' => ['content_removed' => true], 'received_at' => now(),
        ]);
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id,
            'visitor_id' => 'meta:whatsapp:'.$connection->id.':995555111222',
            'external_thread_id' => '995555111222', 'channel' => 'whatsapp', 'status' => 'ai',
        ]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Here is the verified product link.']);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($message);

        $this->assertNotNull($delivery);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Http::fake(['https://graph.whatsapp.test/*' => Http::response(['messages' => [['id' => 'wamid.out.1']]])]);
        (new SendWhatsAppMessage($delivery->id))->handle(app(WhatsAppCloudClient::class));

        $this->assertSame('sent', $delivery->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v25.0/phone-123/messages?')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '995555111222');
    }

    public function test_free_form_reply_fails_closed_outside_twenty_four_hour_window(): void
    {
        Queue::fake();
        $connection = $this->connection();
        $conversation = $connection->agent->conversations()->create([
            'channel_connection_id' => $connection->id, 'visitor_id' => 'old-window',
            'external_thread_id' => '995555000000', 'channel' => 'whatsapp', 'status' => 'human',
        ]);
        $message = $conversation->messages()->create(['role' => 'human', 'content' => 'Late free-form reply']);
        $delivery = app(ChannelMessageDispatcher::class)->dispatch($message);
        (new SendWhatsAppMessage($delivery->id))->handle(app(WhatsAppCloudClient::class));

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('24-hour', $delivery->fresh()->failure_reason);
        Http::assertNothingSent();
    }

    public function test_channels_page_exposes_whatsapp_without_promising_status_publishing(): void
    {
        [$user] = $this->tenant();
        config()->set('whatsapp.app_id', 'app-id');
        config()->set('whatsapp.configuration_id', 'config-id');

        $this->actingAs($user)->get('/onboarding')->assertOk()
            ->assertSeeText('WhatsApp Business')
            ->assertSeeText('Meta does not provide a supported public Cloud API for it.');
    }

    public function test_embedded_signup_persists_encrypted_tenant_connection_and_subscribes_waba(): void
    {
        [$user, $agent] = $this->tenant();
        config()->set('whatsapp.app_id', 'app-id');
        config()->set('whatsapp.configuration_id', 'config-id');
        Http::fake(['https://graph.whatsapp.test/*' => Http::sequence()
            ->push(['access_token' => 'embedded-token', 'expires_in' => 3600])
            ->push(['id' => 'phone-new', 'display_phone_number' => '+995 555 44 33 22', 'verified_name' => 'Verified Business'])
            ->push(['success' => true])]);

        $this->actingAs($user)->post(route('channels.whatsapp.store'), [
            'code' => 'one-time-code', 'waba_id' => 'waba-new', 'phone_number_id' => 'phone-new',
        ])->assertRedirect(route('channels.index'));

        $connection = $agent->channelConnections()->where('provider', 'whatsapp')->firstOrFail();
        $this->assertSame('active', $connection->status);
        $this->assertSame('embedded-token', $connection->access_token);
        $this->assertSame('+995 555 44 33 22', data_get($connection->metadata, 'display_phone_number'));
        $this->assertStringNotContainsString('embedded-token', (string) DB::table('channel_connections')->whereKey($connection->id)->value('access_token'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/waba-new/subscribed_apps?'));
    }

    private function whatsappWebhook(string $body, string $signature)
    {
        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
    }

    private function payload(array $value): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba-123', 'changes' => [['field' => 'messages', 'value' => array_merge([
                'metadata' => ['display_phone_number' => '+995 555 11 12 22', 'phone_number_id' => 'phone-123'],
                'contacts' => [['profile' => ['name' => 'Customer'], 'wa_id' => '995555111222']],
            ], $value)]],
        ]]];
    }

    private function connection(): ChannelConnection
    {
        [, $agent] = $this->tenant();

        return $agent->channelConnections()->create([
            'provider' => 'whatsapp', 'status' => 'active', 'external_account_id' => 'phone-123',
            'external_account_name' => 'Business WhatsApp', 'access_token' => 'whatsapp-token',
            'metadata' => ['waba_id' => 'waba-123', 'display_phone_number' => '+995 555 11 12 22'], 'connected_at' => now(),
        ]);
    }

    private function tenant(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Business', 'slug' => 'business-'.str()->random(8)]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Legatus', 'slug' => 'agent-'.str()->random(8), 'business_name' => 'Business',
            'channels' => ['web', 'whatsapp'], 'settings' => [], 'is_active' => true,
        ]);

        return [$user, $agent];
    }
}
