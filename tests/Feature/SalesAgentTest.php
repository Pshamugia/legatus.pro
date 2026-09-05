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
        $this->get('/')
            ->assertOk()
            ->assertSee('Sell more. Post smarter.')
            ->assertSee('Legatus is your AI Shopping Assistant, Social Media Manager, and Copywriter')
            ->assertSee('AI team for sales, content &amp; social media', false)
            ->assertSee('data-demo-tab="shopping"', false)
            ->assertSee('data-demo-tab="social"', false)
            ->assertSee('data-demo-tab="copywriter"', false)
            ->assertSee('One platform. Three AI teammates.')
            ->assertSee('AI Shopping Assistant')
            ->assertSee('Social Media Manager')
            ->assertSee('AI Copywriter')
            ->assertSee('Simple · Creative · Informative')
            ->assertSee('Why you can trust Legatus')
            ->assertSee('Grounded')
            ->assertSee('Observable')
            ->assertSee('Human-led')
            ->assertSee('Launch in 3 simple steps')
            ->assertSee('Connect your website')
            ->assertSee('Turn on your channels')
            ->assertSee('Delegate the routine')
            ->assertSee('Start with chat. Add social when you need it.')
            ->assertSee('Legatus Chat')
            ->assertSee('Add Social media manager')
            ->assertSee('id="social-addon-monthly"', false)
            ->assertSee('id="social-addon-six-months"', false)
            ->assertSee('id="social-addon-annual"', false)
            ->assertSee('class="social-addon-toggle"', false)
            ->assertSee('data-chat-price="$30"', false)
            ->assertSee('data-social-price="$60"', false)
            ->assertSee('data-chat-price="$162"', false)
            ->assertSee('data-social-price="$324"', false)
            ->assertSee('data-chat-price="$288"', false)
            ->assertSee('data-social-price="$576"', false)
            ->assertSee('Start free trial')
            ->assertSee('package=chat', false)
            ->assertSee("checkoutUrl.searchParams.set('package', toggle.checked ? 'chat_social' : 'chat')", false)
            ->assertSee('Automated publishing + AI Copywriter')
            ->assertSee('Available now')
            ->assertDontSee('Coming soon')
            ->assertDontSee('Legatus Creative')
            ->assertSee('script nonce=', false)
            ->assertDontSee('$99')
            ->assertSee('@media(max-width:600px)', false)
            ->assertDontSee('პროდუქტი');
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
