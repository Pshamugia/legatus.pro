<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UiLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'legatus.widget_country_server_key' => 'GEOIP_COUNTRY_CODE',
            'legatus.widget_country_header' => null,
            'legatus.widget_country_lookup_url' => null,
        ]);
        Http::preventStrayRequests();
    }

    public function test_georgian_visitor_gets_georgian_by_default_and_can_choose_english(): void
    {
        $server = ['GEOIP_COUNTRY_CODE' => 'GE'];

        $response = $this->withServerVariables($server)->get(route('landing'))
            ->assertOk()
            ->assertHeader('Content-Language', 'ka')
            ->assertSee('<html lang="ka">', false)
            ->assertSee('Legatus — შენი AI')
            ->assertSee('მოურავი')
            ->assertSee('AI სავაჭრო ასისტენტი, სოციალური მედიის მენეჯერი და ქოფირაითერი')
            ->assertSee('$27 თვეში')
            ->assertSee('$24 თვეში')
            ->assertSee('data-social-note="Chat + Social · დარიცხვა ყოველ 6 თვეში · დაზოგეთ $36 ($54 თვეში)"', false)
            ->assertSee('data-social-note="Chat + Social · წლიური დარიცხვა · დაზოგეთ $144 ($48 თვეში)"', false)
            ->assertSee('ინტერფეისის ენა')
            ->assertSee('value="en"', false)
            ->assertSee('const updatePricing', false)
            ->assertDontSee('updateფასები', false);

        $response->assertSee('Legatus არის თქვენი AI სავაჭრო ასისტენტი');
        $response->assertSee("html[lang=\"ka\"] .hero h1", false)
            ->assertSee("fonts/archyedt-bold-webfont.ttf", false)
            ->assertSee('font-size:clamp(18px,3.15vw,42px)', false)
            ->assertSee('white-space:nowrap', false);
        $this->assertMatchesRegularExpression('/<div class="navlinks">.*class="ui-locale"/s', $response->getContent());

        $this->withServerVariables($server)->post(route('ui-locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->withServerVariables($server)->get(route('landing'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Legatus — your AI')
            ->assertSee('steward')
            ->assertDontSee('შენი AI მოურავი');
    }

    public function test_georgian_locale_applies_to_the_authenticated_admin(): void
    {
        $this->seed();
        $user = User::firstOrFail();

        $response = $this->actingAs($user)
            ->withServerVariables(['GEOIP_COUNTRY_CODE' => 'GE'])
            ->get(route('onboarding'))
            ->assertOk()
            ->assertHeader('Content-Language', 'ka')
            ->assertSee('<html lang="ka">', false)
            ->assertSee('ბიზნესის გამართვა')
            ->assertSee('ინბოქსი')
            ->assertSee('პარამეტრები');
        $this->assertMatchesRegularExpression('/class="menu app-primary-nav".*class="ui-locale is-sidebar"/s', $response->getContent());
    }

    public function test_foreign_and_unknown_visitors_are_forced_to_english_without_a_switcher(): void
    {
        foreach (['US', 'XX'] as $country) {
            $this->withSession(['ui_locale' => 'ka'])
                ->withServerVariables(['GEOIP_COUNTRY_CODE' => $country])
                ->get(route('landing'))
                ->assertOk()
                ->assertSee('<html lang="en">', false)
                ->assertSee('Legatus — your AI')
                ->assertSee('steward')
                ->assertDontSee('Interface language')
                ->assertDontSee('გაყიდე მეტი');
        }
    }

    public function test_foreign_visitor_cannot_enable_georgian_by_posting_the_locale_route(): void
    {
        $server = ['GEOIP_COUNTRY_CODE' => 'DE'];

        $this->withServerVariables($server)->post(route('ui-locale.update'), ['locale' => 'ka'])
            ->assertRedirect();

        $this->withServerVariables($server)->get(route('landing'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertDontSee('გაყიდე მეტი');
    }
}
