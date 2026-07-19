<?php

namespace Tests\Feature;

use App\Models\Agent;
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
        $this->get("/widget/{$agent->slug}.js")->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')->assertSee('legatus-widget-root');
        $response = $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->assertSee('AI shopping assistant')
            ->assertSee('X-Legatus-Visitor-Token')
            ->assertSee('setInterval(pollHistory, 2500)', false)
            ->assertDontSee('name="csrf-token"', false)
            ->assertDontSee('visitor_id:');

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

    public function test_channels_page_contains_installation_snippet(): void
    {
        $this->seed();
        $this->actingAs(User::first());
        $this->get('/app/channels')->assertOk()->assertSee('widget/legatus-demo.js');
    }
}
