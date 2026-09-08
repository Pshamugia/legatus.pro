<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ChannelConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WidgetChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_script_and_frame_are_publicly_available(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $script = $this->get("/widget/{$agent->slug}.js")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertSee('legatus-widget-root')
            ->assertSee('e.origin===frameOrigin', false);
        $this->assertFalse($script->headers->has('Set-Cookie'));
        $response = $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->assertSee('AI shopping assistant')
            ->assertSee('new URL(product.url)', false)
            ->assertSee('X-Legatus-Visitor-Token')
            ->assertSee('setInterval(pollHistory, 2500)', false)
            ->assertSee("setConversationStatus(data.status)", false)
            ->assertSee('Human operator is handling this conversation')
            ->assertSee('if (data.text)', false)
            ->assertDontSee('name="csrf-token"', false)
            ->assertDontSee('visitor_id:');
        $this->assertFalse($response->headers->has('Set-Cookie'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors *', $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $matches);
        $this->assertStringContainsString('<script nonce="'.$matches[1].'">', (string) $response->getContent());
    }

    public function test_installation_snippet_uses_a_signed_identifier_instead_of_the_demo_slug(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $installUrl = URL::signedRoute('widget.install.script', ['agent' => $agent->getKey()]);

        $this->get($installUrl)
            ->assertOk()
            ->assertSee('legatus-widget-root');

        $this->actingAs(User::first())->get('/onboarding')
            ->assertOk()
            ->assertSee('/widget/install/'.$agent->getKey().'.js', false)
            ->assertSee('signature=', false)
            ->assertDontSee('/widget/legatus-demo.js', false);

        $this->get(preg_replace('/signature=[^&]+/', 'signature=invalid', $installUrl))
            ->assertForbidden();
    }

    public function test_non_widget_pages_cannot_be_framed(): void
    {
        $response = $this->get('/')->assertOk()->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_widget_framing_can_be_restricted_to_configured_origins(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        config(['legatus.widget_frame_ancestors' => 'https://shop.example, https://*.partner.example javascript:invalid']);

        $csp = (string) $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('frame-ancestors https://shop.example https://*.partner.example', $csp);
        $this->assertStringNotContainsString('javascript:invalid', $csp);
    }

    public function test_agent_widget_origins_override_the_global_wildcard(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update(['settings' => array_merge($agent->settings ?? [], [
            'widget_allowed_origins' => ['https://bukinistebi.ge', 'https://www.bukinistebi.ge'],
        ])]);
        config(['legatus.widget_frame_ancestors' => '*']);

        $csp = (string) $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString(
            'frame-ancestors https://bukinistebi.ge https://www.bukinistebi.ge',
            $csp,
        );
        $this->assertStringNotContainsString('frame-ancestors *', $csp);
    }

    public function test_channels_page_contains_installation_snippet(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $this->actingAs(User::first());
        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('/widget/install/'.$agent->getKey().'.js', false)
            ->assertSee('Copy universal script')
            ->assertSee('WordPress / WooCommerce')
            ->assertSee('Drupal')
            ->assertSee('Shopify')
            ->assertSee('Other / Custom website')
            ->assertSee('Check installation')
            ->assertSee('data-channel="facebook"', false)
            ->assertSee('data-channel="instagram"', false)
            ->assertSee('data-status="disconnected"', false)
            ->assertDontSee('data-status="connected"', false)
            ->assertDontSee('access token', false)
            ->assertDontSee('webhook URL', false);
    }

    public function test_owner_can_detect_platform_and_verify_the_universal_widget(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update(['settings' => array_merge($agent->settings ?? [], [
            'website' => 'https://example.com',
            'widget_allowed_origins' => ['https://example.com'],
        ])]);
        $this->actingAs(User::first());
        Http::fake([
            'https://example.com' => Http::response(
                '<html><head><meta name="generator" content="WordPress"></head><body><script src="'.URL::signedRoute('widget.install.script', ['agent' => $agent->getKey()]).'" async></script></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->post(route('channels.widget.detect-platform'))->assertRedirect('/onboarding#website-channel');
        $agent->refresh();
        $this->assertSame('wordpress', data_get($agent->settings, 'widget_installation.platform'));

        $this->post(route('channels.widget.verify'))->assertRedirect('/onboarding#website-channel');
        $agent->refresh();
        $this->assertTrue(data_get($agent->settings, 'widget_installation.installed'));
    }

    public function test_non_demo_widget_without_a_saved_domain_fails_closed(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update([
            'slug' => 'private-store',
            'settings' => array_merge($agent->settings ?? [], ['widget_allowed_origins' => []]),
        ]);
        config(['legatus.widget_frame_ancestors' => '*']);

        $csp = (string) $this->get('/widget/private-store')
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('frame-ancestors *', $csp);
    }

    public function test_legacy_saved_website_restricts_widget_without_the_origins_field(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update([
            'slug' => 'legacy-store',
            'settings' => array_merge($agent->settings ?? [], [
                'website' => 'https://legacy.example/shop',
                'widget_allowed_origins' => [],
            ]),
        ]);
        config(['legatus.widget_frame_ancestors' => '*']);

        $csp = (string) $this->get('/widget/legacy-store')
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString(
            'frame-ancestors https://legacy.example https://www.legacy.example',
            $csp,
        );
        $this->assertStringNotContainsString('frame-ancestors *', $csp);
    }

    public function test_business_setup_contains_clear_channel_blocks_and_no_manual_credentials(): void
    {
        $this->seed();
        $this->actingAs(User::first());

        $this->get('/onboarding')
            ->assertOk()
            ->assertSeeInOrder(['Customer channels', 'Website chat', 'Facebook and Instagram'])
            ->assertSee('Facebook Messenger')
            ->assertSee('Instagram Direct')
            ->assertSee('Connect Facebook and Instagram')
            ->assertSee(route('channels.meta.connect', ['provider' => 'meta']), false)
            ->assertSee('official page')
            ->assertDontSee('Paste token')
            ->assertDontSee('Webhook URL');
    }

    public function test_channels_page_shows_a_verified_connected_account_instead_of_a_connect_claim(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $this->actingAs(User::first());

        ChannelConnection::create([
            'agent_id' => $agent->id,
            'provider' => 'facebook',
            'status' => 'active',
            'external_account_id' => 'page-123',
            'external_account_name' => 'Bukinistebi.ge',
            'access_token' => 'encrypted-by-model-cast',
            'connected_at' => now(),
            'last_webhook_at' => now()->subMinute(),
        ]);

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('data-channel="facebook" data-status="connected"', false)
            ->assertSee('Bukinistebi.ge')
            ->assertSee('Connected')
            ->assertSee('1/2 connected');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Connected · Bukinistebi.ge')
            ->assertSee('Instagram')
            ->assertSee('Not connected')
            ->assertDontSee('they are not part of this local demo');
    }

    public function test_meta_management_remains_visible_when_both_accounts_are_connected(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $this->actingAs(User::first());

        foreach (['facebook' => 'Store Page', 'instagram' => '@store'] as $provider => $name) {
            ChannelConnection::create([
                'agent_id' => $agent->id,
                'provider' => $provider,
                'status' => 'active',
                'external_account_id' => $provider.'-123',
                'external_account_name' => $name,
                'access_token' => 'encrypted-by-model-cast',
                'connected_at' => now(),
            ]);
        }

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('2/2 connected')
            ->assertSee('Meta connection is active')
            ->assertSee('Manage or reconnect Meta')
            ->assertSee(route('channels.meta.connect', ['provider' => 'meta']), false);
    }

    public function test_meta_connection_errors_are_actionable_without_leaking_provider_details(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $this->actingAs(User::first());

        ChannelConnection::create([
            'agent_id' => $agent->id,
            'provider' => 'instagram',
            'status' => 'needs_attention',
            'external_account_id' => 'instagram-123',
            'external_account_name' => '@bukinistebi',
            'access_token' => 'encrypted-by-model-cast',
            'last_error' => 'Graph rejected access_token=secret-provider-value',
        ]);

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('data-channel="instagram" data-status="error"', false)
            ->assertSee('Needs attention')
            ->assertSee('Reconnect')
            ->assertDontSee('secret-provider-value');
    }

    public function test_legacy_channels_url_redirects_to_the_channel_section_in_business_setup(): void
    {
        $this->seed();
        $this->actingAs(User::first());

        $this->get('/app/channels')->assertRedirect('/onboarding#channels');
    }
}
