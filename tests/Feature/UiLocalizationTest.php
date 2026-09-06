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

        $this->withServerVariables($server)->get(route('landing'))
            ->assertOk()
            ->assertHeader('Content-Language', 'ka')
            ->assertSee('<html lang="ka">', false)
            ->assertSee('გაყიდე მეტი')
            ->assertSee('AI სავაჭრო ასისტენტი, სოციალური მედიის მენეჯერი და ქოფირაითერი')
            ->assertSee('ინტერფეისის ენა')
            ->assertSee('value="en"', false)
            ->assertSee('const updatePricing', false)
            ->assertDontSee('updateფასები', false);

        $this->withServerVariables($server)->post(route('ui-locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->withServerVariables($server)->get(route('landing'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Sell more.')
            ->assertDontSee('გაყიდე მეტი');
    }

    public function test_georgian_locale_applies_to_the_authenticated_admin(): void
    {
        $this->seed();
        $user = User::firstOrFail();

        $this->actingAs($user)
            ->withServerVariables(['GEOIP_COUNTRY_CODE' => 'GE'])
            ->get(route('onboarding'))
            ->assertOk()
            ->assertHeader('Content-Language', 'ka')
            ->assertSee('<html lang="ka">', false)
            ->assertSee('ბიზნესის გამართვა')
            ->assertSee('ინბოქსი')
            ->assertSee('პარამეტრები');
    }

    public function test_foreign_and_unknown_visitors_are_forced_to_english_without_a_switcher(): void
    {
        foreach (['US', 'XX'] as $country) {
            $this->withSession(['ui_locale' => 'ka'])
                ->withServerVariables(['GEOIP_COUNTRY_CODE' => $country])
                ->get(route('landing'))
                ->assertOk()
                ->assertSee('<html lang="en">', false)
                ->assertSee('Sell more.')
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
