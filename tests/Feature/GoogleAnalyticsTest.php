<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_loads_google_analytics_with_the_csp_nonce(): void
    {
        $response = $this->get(route('landing'));

        $response->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-VH5ZFXN6PW', false)
            ->assertSee("gtag('config', 'G-VH5ZFXN6PW');", false);

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $matches);
        $this->assertStringContainsString('<script nonce="'.$matches[1].'" async', (string) $response->getContent());
    }
}
