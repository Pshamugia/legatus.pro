<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationSpamProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'legatus.registration_enabled' => true,
            'legatus.registration_turnstile_site_key' => null,
            'legatus.registration_turnstile_secret_key' => null,
        ]);
    }

    public function test_registration_requires_a_recent_form_and_an_empty_honeypot(): void
    {
        $this->post('/register', $this->registrationData())->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());

        $this->get('/register')->assertOk();
        $this->post('/register', $this->registrationData())->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());

        $this->withSession(['registration_form_issued_at' => now()->subHours(3)->timestamp])
            ->post('/register', $this->registrationData())->assertSessionHasErrors('email');

        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData() + ['company_website' => 'https://spam.example'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Organization::count());

        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData())->assertRedirect('/onboarding');
        $this->assertSame(1, User::count());
        $this->assertSame(1, Organization::count());
    }

    public function test_turnstile_is_required_and_checks_the_verified_hostname(): void
    {
        config([
            'legatus.registration_turnstile_site_key' => 'site-key',
            'legatus.registration_turnstile_secret_key' => 'secret-key',
            'app.url' => 'https://legatus.pro',
        ]);

        $page = $this->get('/register')->assertOk()->assertSee('cf-turnstile');
        $this->assertStringContainsString('https://challenges.cloudflare.com', (string) $page->headers->get('Content-Security-Policy'));

        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData())->assertSessionHasErrors('email');

        Http::fakeSequence()
            ->push(['success' => true, 'hostname' => 'other.example'])
            ->push(['success' => true, 'hostname' => 'legatus.pro']);
        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData() + ['cf-turnstile-response' => 'token'])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());

        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData() + ['cf-turnstile-response' => 'token'])
            ->assertRedirect('/onboarding');
        $this->assertSame(1, User::count());
    }

    public function test_partial_configuration_and_verification_failure_fail_closed(): void
    {
        config(['legatus.registration_turnstile_secret_key' => 'secret-key']);
        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData() + ['cf-turnstile-response' => 'token'])
            ->assertSessionHasErrors('email');

        config(['legatus.registration_turnstile_site_key' => 'site-key']);
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false])]);
        $this->withSession(['registration_form_issued_at' => now()->subSeconds(5)->timestamp])
            ->post('/register', $this->registrationData() + ['cf-turnstile-response' => 'token'])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
    }

    private function registrationData(): array
    {
        return [
            'name' => 'Business Owner',
            'email' => 'owner@example.com',
            'business_name' => 'Example Business',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
