<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InputAndAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_auth_ui_has_no_demo_prefill_or_disabled_registration_link(): void
    {
        config([
            'legatus.demo_login_enabled' => false,
            'legatus.registration_enabled' => false,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('demo@legatus.ai')
            ->assertDontSee(route('register'), false)
            ->assertSee('Workspace access is currently invite-only.');

        $this->get(route('register'))->assertNotFound();

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('href="'.route('login').'"', false)
            ->assertDontSee('href="'.route('register').'"', false)
            ->assertDontSee('href="'.route('onboarding').'"', false)
            ->assertSee('Illustrative demo · seeded catalog');
    }

    public function test_local_demo_auth_ui_is_available_only_when_enabled(): void
    {
        config([
            'legatus.demo_login_enabled' => true,
            'legatus.registration_enabled' => true,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('demo@legatus.ai')
            ->assertSee(route('register'), false);

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('href="'.route('register').'"', false);
    }

    public function test_knowledge_upload_type_must_match_the_selected_parser(): void
    {
        Storage::fake('local');
        $this->seed();
        $user = User::firstOrFail();
        $agent = Agent::firstOrFail();
        $sourceCount = $agent->knowledgeSources()->count();

        $this->actingAs($user)->post(route('knowledge.store'), [
            'type' => 'csv',
            'name' => 'Disguised PDF',
            'file' => UploadedFile::fake()->create('catalog.pdf', 10, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasErrors('file');

        $this->post(route('knowledge.store'), [
            'type' => 'pdf',
            'name' => 'Disguised CSV',
            'file' => UploadedFile::fake()->createWithContent('catalog.csv', "name,price\nBook,20"),
        ])->assertRedirect()->assertSessionHasErrors('file');

        $this->post(route('knowledge.store'), [
            'type' => 'url',
            'url' => 'https://example.com/catalog',
            'file' => UploadedFile::fake()->createWithContent('extra.csv', "name,price\nBook,20"),
        ])->assertRedirect()->assertSessionHasErrors('file');

        $this->assertSame($sourceCount, $agent->knowledgeSources()->count());
    }

    public function test_delivery_settings_reject_comma_only_city_lists(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        $agent = Agent::firstOrFail();
        $originalName = $agent->name;

        $this->actingAs($user)->put(route('settings.update'), [
            'agent_name' => 'Must not be saved',
            'tone' => 'Warm and concise',
            'handoff_threshold' => 0.65,
            'discount_limit' => 10,
            'business_hours' => 'Monday-Friday, 09:00-18:00',
            'delivery_timezone' => 'Asia/Tbilisi',
            'delivery_local_cities' => ' , , ',
            'delivery_cutoff' => '15:00',
            'delivery_local_days' => 1,
            'delivery_regional_min_days' => 2,
            'delivery_regional_max_days' => 4,
        ])->assertRedirect()->assertSessionHasErrors('delivery_local_cities');

        $this->assertSame($originalName, $agent->fresh()->name);
    }
}
