<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_landing_page_requires_consent_before_loading_google_analytics(): void
    {
        app()->instance('env', 'production');

        $response = $this->get(route('landing'));

        $response->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=', false)
            ->assertSee("const measurementId = 'G-VH5ZFXN6PW';", false)
            ->assertSee('analytics_storage: denied', false)
            ->assertSee('data-analytics-consent="granted"', false)
            ->assertSee('data-analytics-consent="denied"', false)
            ->assertDontSee('<script async src="https://www.googletagmanager.com', false);

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $matches);
        $this->assertStringContainsString('<script nonce="'.$matches[1].'">', (string) $response->getContent());
    }

    public function test_analytics_and_consent_ui_are_not_loaded_outside_production(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertDontSee('G-VH5ZFXN6PW', false)
            ->assertDontSee('analytics-consent-banner', false);
    }

    public function test_privacy_policy_explains_analytics_consent_and_withdrawal(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Google Analytics only after you allow analytics')
            ->assertSee('can be changed at any time through the privacy settings control');
    }
}
