<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingWidgetThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_shows_five_brand_palettes_custom_color_controls_and_live_preview(): void
    {
        [$owner] = $this->tenant('onboarding-theme-ui', [
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ]);

        $this->actingAs($owner)->get(route('onboarding'))
            ->assertOk()
            ->assertSee('Widget appearance & brand colors', false)
            ->assertSee('name="widget_theme_preset" value="forest"', false)
            ->assertSee('name="widget_theme_preset" value="ocean"', false)
            ->assertSee('name="widget_theme_preset" value="midnight"', false)
            ->assertSee('name="widget_theme_preset" value="plum"', false)
            ->assertSee('name="widget_theme_preset" value="ember"', false)
            ->assertSee('name="widget_theme_preset" value="custom"', false)
            ->assertSee('name="widget_theme_primary" value="#123456"', false)
            ->assertSee('name="widget_theme_accent" value="#ABCDEF"', false)
            ->assertSee('id="widget-theme-primary-picker" type="color"', false)
            ->assertSee('id="widget-theme-accent-picker" type="color"', false)
            ->assertSee('id="widget-theme-preview"', false)
            ->assertSee('id="widget-theme-status"', false)
            ->assertSee(route('settings.index'), false);
    }

    public function test_onboarding_saves_a_normalized_custom_theme_and_preserves_unrelated_settings(): void
    {
        [$owner, $agent] = $this->tenant('onboarding-theme-save');

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding([
            'widget_theme_preset' => 'custom',
            'widget_theme_primary' => '#123456',
            'widget_theme_accent' => '#abcdef',
        ]))->assertRedirect(route('channels.index'))
            ->assertSessionHasNoErrors();

        $agent->refresh();
        $this->assertSame([
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ], $agent->settings['widget_theme']);
        $this->assertSame('keep', $agent->settings['preserve_me']);
    }

    public function test_onboarding_preset_ignores_stale_custom_values_and_missing_theme_fields_preserve_the_theme(): void
    {
        [$owner, $agent] = $this->tenant('onboarding-theme-compatible', [
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ]);

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding())
            ->assertRedirect(route('channels.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame([
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ], $agent->fresh()->settings['widget_theme']);

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding([
            'widget_theme_preset' => 'ocean',
            'widget_theme_primary' => '#fff;background:red',
            'widget_theme_accent' => 'not-a-color',
        ]))->assertRedirect(route('channels.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame([
            'preset' => 'ocean',
            'primary' => '#164E63',
            'accent' => '#67E8F9',
        ], $agent->fresh()->settings['widget_theme']);
    }

    public function test_onboarding_rejects_unsafe_or_low_contrast_custom_colors_before_saving(): void
    {
        [$owner, $agent] = $this->tenant('onboarding-theme-validation');
        $originalSettings = $agent->settings;

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding([
            'business_name' => 'Must Not Save',
            'widget_theme_preset' => 'custom',
            'widget_theme_primary' => '#123456;background:red',
            'widget_theme_accent' => '#ABCDEF',
        ]))->assertRedirect()
            ->assertSessionHasErrors('widget_theme_primary');

        $this->actingAs($owner)->post(route('onboarding.store'), $this->validOnboarding([
            'business_name' => 'Must Not Save',
            'widget_theme_preset' => 'custom',
            'widget_theme_primary' => '#111111',
            'widget_theme_accent' => '#121212',
        ]))->assertRedirect()
            ->assertSessionHasErrors('widget_theme_accent');

        $agent->refresh();
        $this->assertSame('Onboarding Theme Validation', $agent->business_name);
        $this->assertSame($originalSettings, $agent->settings);
    }

    /** @return array{User, Agent} */
    private function tenant(string $slug, ?array $theme = null): array
    {
        $user = User::factory()->create();
        $businessName = str($slug)->headline()->toString();
        $organization = Organization::create(['name' => $businessName, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $settings = ['preserve_me' => 'keep'];
        if ($theme !== null) {
            $settings['widget_theme'] = $theme;
        }
        $agent = $organization->agents()->create([
            'name' => 'Nia',
            'slug' => $slug.'-agent',
            'business_name' => $businessName,
            'tone' => 'warm',
            'channels' => ['web'],
            'settings' => $settings,
        ]);

        return [$user, $agent];
    }

    private function validOnboarding(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Updated Business',
            'agent_name' => 'Nia',
            'description' => 'A focused business description.',
        ], $overrides);
    }
}
