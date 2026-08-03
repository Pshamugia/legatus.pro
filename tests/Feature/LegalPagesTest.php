<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_legal_pages_are_available_for_meta_configuration(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('OpenAI')
            ->assertSee('Meta');

        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('AI and commercial actions');

        $this->get(route('refund-policy'))
            ->assertOk()
            ->assertSee('Refund Policy')
            ->assertSee('14 days')
            ->assertSee(config('legatus.privacy_email'));

        $this->get(route('data-deletion'))
            ->assertOk()
            ->assertSee('Data deletion instructions')
            ->assertSee('Disconnect')
            ->assertSee(config('legatus.privacy_email'));

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('$30')
            ->assertSee('$162')
            ->assertSee('$288')
            ->assertSee(route('refund-policy'));
    }
}
