<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
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

        $this->get(route('data-deletion'))
            ->assertOk()
            ->assertSee('Data deletion instructions')
            ->assertSee('Disconnect')
            ->assertSee(config('legatus.privacy_email'));
    }
}
