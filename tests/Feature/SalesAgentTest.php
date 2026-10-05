<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Support\SignedVisitorToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null]);
    }

    public function test_landing_page_is_available(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertSee('fonts/bpg_boxo-boxo.ttf', false)
            ->assertSee("html[lang=\"ka\"] body,html[lang=\"ka\"] body *{font-family:'Legatus Boxo'", false)
            ->assertSee('html[lang="ka"] body h1,html[lang="ka"] body h1 *', false)
            ->assertSee('Your business stays active — even while you rest.')
            ->assertSee('The annual plan includes a free online store.')
            ->assertSee('Every message is a potential order. Be there on time.')
            ->assertSee('In about an hour — an assistant that knows your business.')
            ->assertSee('Set the schedule once. Legatus handles daily posting.')
            ->assertSee('A post-ready image that preserves the real look of your product.')
            ->assertSee('One annual plan. Your online store and AI assistant together.')
            ->assertSee('Three steps to hand off the daily work.')
            ->assertSee('Your time should not be spent on every answer and every post.')
            ->assertSee('period=yearly&amp;package=chat_social', false)
            ->assertSee('href="#annual-offer"', false)
            ->assertSee('@media(max-width:700px)', false)
            ->assertDontSee('steward')
            ->assertDontSee('One platform. Three AI teammates.')
            ->assertDontSee('id="social-addon-monthly"', false)
            ->assertDontSee('პროდუქტი');

        $html = $response->getContent();
        $sections = ['id="product"', '<aside class="annual-banner"', 'id="communication"', 'id="business-knowledge"', 'id="social-media"', 'id="product-images"', 'id="annual-offer"', 'id="how-it-works"', 'id="start"'];
        $positions = array_map(fn (string $marker): int|false => strpos($html, $marker), $sections);

        foreach ($positions as $position) {
            $this->assertIsInt($position);
        }
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }

    public function test_landing_css_and_local_fonts_are_ready_before_body_rendering(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $bodyPosition = strpos($html, '<body>');
        $landingCssPosition = strpos($html, '.landing-hero h1');
        $boxoPreloadPosition = strpos($html, 'rel="preload" href="'.asset('fonts/bpg_boxo-boxo.ttf').'"');
        $archyPreloadPosition = strpos($html, 'rel="preload" href="'.asset('fonts/archyedt-bold-webfont.ttf').'"');

        $this->assertIsInt($bodyPosition);
        $this->assertIsInt($landingCssPosition);
        $this->assertIsInt($boxoPreloadPosition);
        $this->assertIsInt($archyPreloadPosition);
        $this->assertLessThan($bodyPosition, $landingCssPosition);
        $this->assertLessThan($bodyPosition, $boxoPreloadPosition);
        $this->assertLessThan($bodyPosition, $archyPreloadPosition);
        $this->assertStringContainsString('font-display:block', $html);
        $this->assertStringContainsString('display=block', $html);
        $this->assertStringNotContainsString('display=swap', $html);
    }

    public function test_demo_agent_answers_and_persists_a_conversation(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'რამდენი ღირს Piranesi?'])
            ->assertOk()->assertJsonPath('intent', 'price')->assertJsonPath('handoff', false);
        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertNotNull($visitorId);
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'intent' => 'price']);
        $conversation = $agent->conversations()->where('visitor_id', $visitorId)->firstOrFail();
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_customer_can_request_a_human(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $response = $this->postJson("/demo/{$agent->slug}/message", ['message' => 'ოპერატორთან დამაკავშირე'])
            ->assertOk()->assertJsonPath('handoff', true);
        $visitorId = app(SignedVisitorToken::class)->resolve($agent, $response->json('visitor_token'));
        $this->assertDatabaseHas('conversations', ['visitor_id' => $visitorId, 'status' => 'human']);
    }
}
