<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ChannelConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs(User::first());
        $this->get('/app/channels')
            ->assertOk()
            ->assertSee('widget/legatus-demo.js')
            ->assertSee('Copy script')
            ->assertSee('data-channel="facebook"', false)
            ->assertSee('data-channel="instagram"', false)
            ->assertSee('data-status="disconnected"', false)
            ->assertDontSee('data-status="connected"', false)
            ->assertDontSee('access token', false)
            ->assertDontSee('webhook URL', false);
    }

    public function test_channels_page_has_a_three_step_setup_and_no_manual_credentials(): void
    {
        $this->seed();
        $this->actingAs(User::first());

        $this->get('/app/channels')
            ->assertOk()
            ->assertSeeInOrder(['ასწავლეთ', 'დაამატეთ საიტზე', 'დააკავშირეთ Meta'])
            ->assertSee('Facebook Messenger')
            ->assertSee('Instagram Direct')
            ->assertSee('Connect Facebook')
            ->assertSee('Connect Instagram')
            ->assertSee(route('channels.meta.connect', ['provider' => 'facebook']), false)
            ->assertSee(route('channels.meta.connect', ['provider' => 'instagram']), false)
            ->assertSee('Meta-ს ოფიციალურ გვერდზე')
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

        $this->get('/app/channels')
            ->assertOk()
            ->assertSee('data-channel="facebook" data-status="connected"', false)
            ->assertSee('Bukinistebi.ge')
            ->assertSee('დაკავშირებულია')
            ->assertSee('1/2 დაკავშირებული');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Connected · Bukinistebi.ge')
            ->assertSee('Instagram')
            ->assertSee('Not connected')
            ->assertDontSee('they are not part of this local demo');
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

        $this->get('/app/channels')
            ->assertOk()
            ->assertSee('data-channel="instagram" data-status="error"', false)
            ->assertSee('კავშირს ყურადღება სჭირდება')
            ->assertSee('ხელახლა დაკავშირება')
            ->assertDontSee('secret-provider-value');
    }
}
