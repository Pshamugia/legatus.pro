<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Support\WidgetLocaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WidgetLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_georgia_country_localizes_launcher_frame_and_quick_chip_payloads_consistently(): void
    {
        $agent = $this->agent();
        config([
            'legatus.widget_country_server_key' => 'GEOIP_COUNTRY_CODE',
            'legatus.widget_country_header' => null,
            'legatus.widget_country_lookup_url' => null,
        ]);

        $server = ['GEOIP_COUNTRY_CODE' => 'GE'];
        $launcher = $this->withServerVariables($server)
            ->get(route('widget.script', $agent))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('Content-Language', 'ka');

        $this->assertLauncherCopy($launcher->getContent(), $agent, 'ka');
        $this->assertLauncherFrameLocale($launcher->getContent(), 'ka');

        $this->assertStringContainsString('no-store', (string) $launcher->headers->get('Cache-Control'));

        $frame = $this->withServerVariables($server)
            ->get(route('widget.frame', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'ka')
            ->assertSee('<html lang="ka">', false)
            ->assertSee(trans('widget.greeting', [
                'introduction' => trans('widget.introduction_custom', [
                    'assistant' => $agent->assistantDisplayName(),
                    'business' => $agent->business_name,
                ], 'ka'),
            ], 'ka'))
            ->assertSee(trans('widget.personal_advice', [], 'ka'))
            ->assertSee('data-q="'.e(trans('widget.personal_advice_prompt', [], 'ka')).'"', false)
            ->assertSee(trans('widget.delivery', [], 'ka'))
            ->assertSee('data-q="'.e(trans('widget.delivery_prompt', [], 'ka')).'"', false)
            ->assertDontSee(trans('widget.personal_advice', [], 'en'));

        $this->assertEmbeddedWidgetCopy($frame->getContent(), 'ka');
        $this->assertStringContainsString('no-store', (string) $frame->headers->get('Cache-Control'));

        $this->assertLocalizedDemo($agent, $server, 'ka');
    }

    public function test_non_georgia_country_uses_english_for_launcher_frame_and_quick_chip_payloads(): void
    {
        $agent = $this->agent();
        config([
            'legatus.widget_country_server_key' => 'GEOIP_COUNTRY_CODE',
            'legatus.widget_country_header' => null,
            'legatus.widget_country_lookup_url' => null,
        ]);

        $server = [
            'GEOIP_COUNTRY_CODE' => 'US',
            'HTTP_ACCEPT_LANGUAGE' => 'ka-GE,ka;q=0.9,en;q=0.8',
        ];
        $launcher = $this->withServerVariables($server)
            ->get(route('widget.script', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->assertLauncherCopy($launcher->getContent(), $agent, 'en');
        $this->assertLauncherFrameLocale($launcher->getContent(), 'en');
        $this->assertStringContainsString('no-store', (string) $launcher->headers->get('Cache-Control'));

        $frame = $this->withServerVariables($server)
            ->get(route('widget.frame', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('<html lang="en">', false)
            ->assertSee(trans('widget.personal_advice', [], 'en'))
            ->assertSee('data-q="'.e(trans('widget.personal_advice_prompt', [], 'en')).'"', false)
            ->assertSee(trans('widget.delivery', [], 'en'))
            ->assertSee('data-q="'.e(trans('widget.delivery_prompt', [], 'en')).'"', false)
            ->assertDontSee(trans('widget.personal_advice', [], 'ka'));

        $this->assertEmbeddedWidgetCopy($frame->getContent(), 'en');
        $this->assertStringContainsString('no-store', (string) $frame->headers->get('Cache-Control'));

        $this->assertLocalizedDemo($agent, $server, 'en');
    }

    public function test_unknown_country_falls_back_to_english_consistently(): void
    {
        $agent = $this->agent();
        config([
            'legatus.widget_country_server_key' => 'GEOIP_COUNTRY_CODE',
            'legatus.widget_country_header' => null,
            'legatus.widget_country_lookup_url' => null,
        ]);

        $server = [
            'GEOIP_COUNTRY_CODE' => 'XX',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ];

        $launcher = $this->withServerVariables($server)
            ->get(route('widget.script', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->assertLauncherCopy($launcher->getContent(), $agent, 'en');
        $this->assertLauncherFrameLocale($launcher->getContent(), 'en');

        $frame = $this->withServerVariables($server)
            ->get(route('widget.frame', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('<html lang="en">', false)
            ->assertSee(trans('widget.greeting', [
                'introduction' => trans('widget.introduction_custom', [
                    'assistant' => $agent->assistantDisplayName(),
                    'business' => $agent->business_name,
                ], 'en'),
            ], 'en'));

        $this->assertEmbeddedWidgetCopy($frame->getContent(), 'en');
        $this->assertStringContainsString('no-store', (string) $frame->headers->get('Cache-Control'));
    }

    public function test_untrusted_country_header_cannot_spoof_georgian_locale(): void
    {
        $agent = $this->agent();
        config([
            'legatus.widget_country_server_key' => null,
            'legatus.widget_country_header' => 'X-Country-Code',
            'legatus.widget_country_trusted_proxies' => '10.0.0.0/8',
            'legatus.widget_country_lookup_url' => null,
        ]);

        $server = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_COUNTRY_CODE' => 'GE',
            'HTTP_ACCEPT_LANGUAGE' => 'ka-GE,ka;q=0.9',
        ];

        $launcher = $this->withServerVariables($server)
            ->get(route('widget.script', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->assertLauncherCopy($launcher->getContent(), $agent, 'en');
        $this->assertLauncherFrameLocale($launcher->getContent(), 'en');

        $frame = $this->withServerVariables($server)
            ->get(route('widget.frame', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('<html lang="en">', false)
            ->assertSee(trans('widget.personal_advice', [], 'en'))
            ->assertDontSee(trans('widget.personal_advice', [], 'ka'));

        $this->assertEmbeddedWidgetCopy($frame->getContent(), 'en');
    }

    public function test_public_ip_lookup_is_cached_after_a_successful_georgia_response(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://geo.example/8.8.8.8' => Http::response(['country' => 'GE']),
        ]);
        config([
            'app.key' => 'base64:widget-locale-test-key',
            'legatus.widget_country_server_key' => null,
            'legatus.widget_country_header' => null,
            'legatus.widget_country_lookup_url' => 'https://geo.example/{ip}',
            'legatus.widget_country_cache_hours' => 24,
        ]);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '8.8.8.8']);
        $resolver = app(WidgetLocaleResolver::class);

        $this->assertSame('ka', $resolver->resolve($request));
        $this->assertSame('ka', $resolver->resolve($request));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://geo.example/8.8.8.8');
    }

    public function test_failed_public_ip_lookup_falls_back_to_english_and_is_negatively_cached(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://geo.example/1.1.1.1' => Http::response([], 503),
        ]);
        config([
            'app.key' => 'base64:widget-locale-test-key',
            'legatus.widget_country_server_key' => null,
            'legatus.widget_country_header' => null,
            'legatus.widget_country_lookup_url' => 'https://geo.example/{ip}',
            'legatus.widget_country_negative_cache_minutes' => 10,
        ]);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '1.1.1.1']);
        $resolver = app(WidgetLocaleResolver::class);

        $this->assertSame('en', $resolver->resolve($request));
        $this->assertSame('en', $resolver->resolve($request));
        Http::assertSentCount(1);
    }

    public function test_country_header_is_used_when_the_request_comes_from_a_trusted_proxy(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'legatus.widget_country_server_key' => null,
            'legatus.widget_country_header' => 'X-Country-Code',
            'legatus.widget_country_trusted_proxies' => '10.0.0.0/8',
            'legatus.widget_country_lookup_url' => 'https://geo.example/{ip}',
        ]);

        $request = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '10.12.34.56',
            'HTTP_X_COUNTRY_CODE' => 'GE',
        ]);

        $this->assertSame('ka', app(WidgetLocaleResolver::class)->resolve($request));
        Http::assertNothingSent();
    }

    private function agent(): Agent
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update([
            'name' => 'Nia',
            'business_name' => 'Bukinistebi.ge',
        ]);

        return $agent->fresh();
    }

    private function assertLauncherCopy(string $javascript, Agent $agent, string $locale): void
    {
        preg_match(
            '/launcherOpen=("(?:\\\\.|[^"\\\\])*")'.
            ',launcherLabel=("(?:\\\\.|[^"\\\\])*")'.
            ',frameTitle=("(?:\\\\.|[^"\\\\])*")/',
            $javascript,
            $matches,
        );

        $this->assertCount(4, $matches, 'The localized launcher copy was not embedded as JSON data.');
        $this->assertSame(
            trans('widget.launcher_open', ['business' => $agent->business_name], $locale),
            json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            trans('widget.launcher_label', ['business' => $agent->business_name], $locale),
            json_decode($matches[2], true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            trans('widget.frame_title', [
                'assistant' => $agent->assistantDisplayName(),
                'business' => $agent->business_name,
            ], $locale),
            json_decode($matches[3], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    private function assertLauncherFrameLocale(string $javascript, string $locale): void
    {
        preg_match('/var frameUrl=("(?:\\\\.|[^"\\\\])*")/', $javascript, $matches);

        $this->assertCount(2, $matches, 'The launcher frame URL was not embedded as JSON data.');
        $frameUrl = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        parse_str((string) parse_url($frameUrl, PHP_URL_QUERY), $query);
        $this->assertSame($locale, $query['lang'] ?? null);
    }

    private function assertEmbeddedWidgetCopy(string $html, string $locale): void
    {
        preg_match('/const widgetCopy\s*=\s*(\{[^\r\n]+\});/', $html, $matches);

        $this->assertCount(2, $matches, 'The localized widget copy dictionary was not embedded as JSON data.');
        $copy = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        foreach (['thinking', 'processing', 'preparing', 'timed_out', 'connection_interrupted'] as $key) {
            $this->assertSame(trans("widget.{$key}", [], $locale), $copy[$key] ?? null);
        }
    }

    private function assertLocalizedDemo(Agent $agent, array $server, string $locale): void
    {
        $introduction = trans('widget.introduction_custom', [
            'assistant' => $agent->assistantDisplayName(),
            'business' => $agent->business_name,
        ], $locale);

        $response = $this->withServerVariables($server)
            ->get(route('chat.show', $agent))
            ->assertOk()
            ->assertHeader('Content-Language', $locale)
            ->assertSee('class="chatwindow" lang="'.$locale.'"', false)
            ->assertSee('@media(max-width:600px)', false)
            ->assertSee('class="demo-actions"', false)
            ->assertSee(trans('widget.demo_greeting', ['introduction' => $introduction], $locale))
            ->assertSee(trans('widget.demo_status', [], $locale));

        foreach ([
            ['personal_shopper', 'personal_shopper_prompt'],
            ['price_stock', 'price_stock_prompt'],
            ['delivery', 'delivery_prompt'],
            ['wholesale_offer', 'wholesale_offer_prompt'],
            ['human_handoff', 'human_handoff_prompt'],
        ] as [$labelKey, $promptKey]) {
            $response
                ->assertSee(trans("widget.{$labelKey}", [], $locale))
                ->assertSee('data-q="'.e(trans("widget.{$promptKey}", [], $locale)).'"', false);
        }

        $this->assertEmbeddedWidgetCopy($response->getContent(), $locale);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
