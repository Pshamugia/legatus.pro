<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_shows_professional_palettes_custom_inputs_and_live_preview(): void
    {
        [$owner] = $this->tenant('theme-ui', 'owner', [
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ]);

        $this->actingAs($owner)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Widget branding')
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
            ->assertSee('Powered by Legatus');
    }

    public function test_owner_saves_normalized_custom_theme_without_touching_another_tenant(): void
    {
        [$owner, $agent] = $this->tenant('theme-owner', 'owner');
        [, $otherAgent] = $this->tenant('theme-other', 'owner', [
            'preset' => 'plum',
            'primary' => '#581C87',
            'accent' => '#F0ABFC',
        ]);

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
            'widget_theme_preset' => 'custom',
            'widget_theme_primary' => '#123456',
            'widget_theme_accent' => '#abcdef',
        ]))->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Business identity and AI assistant settings updated.');

        $agent->refresh();
        $this->assertSame([
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ], $agent->settings['widget_theme']);
        $this->assertSame('keep', $agent->settings['preserve_me']);
        $this->assertSame('plum', $otherAgent->fresh()->settings['widget_theme']['preset']);
    }

    public function test_preset_submission_ignores_stale_custom_values(): void
    {
        [$owner, $agent] = $this->tenant('theme-preset', 'owner');

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
            'widget_theme_preset' => 'ocean',
            'widget_theme_primary' => '#fff;background:red',
            'widget_theme_accent' => 'not-a-color',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([
            'preset' => 'ocean',
            'primary' => '#164E63',
            'accent' => '#67E8F9',
        ], $agent->fresh()->settings['widget_theme']);
    }

    public function test_legacy_settings_submission_without_theme_fields_preserves_current_theme(): void
    {
        [$owner, $agent] = $this->tenant('theme-compatible', 'owner', [
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ]);

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ], $agent->fresh()->settings['widget_theme']);
    }

    public function test_custom_theme_rejects_malformed_or_insufficiently_contrasting_colors_atomically(): void
    {
        [$owner, $agent] = $this->tenant('theme-validation', 'owner');
        $originalSettings = $agent->settings;
        $invalidPalettes = [
            'short primary' => ['#FFF', '#ABCDEF'],
            'eight-digit accent' => ['#123456', '#12345678'],
            'missing hash' => ['123456', '#ABCDEF'],
            'CSS payload' => ['#123456;background:red', '#ABCDEF'],
            'insufficient contrast' => ['#111111', '#121212'],
        ];

        foreach ($invalidPalettes as $case => [$primary, $accent]) {
            $response = $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
                'business_name' => 'Must Not Save',
                'widget_theme_preset' => 'custom',
                'widget_theme_primary' => $primary,
                'widget_theme_accent' => $accent,
            ]))->assertRedirect();
            $this->assertTrue(session()->has('errors'), "{$case} should be rejected.");

            $agent->refresh();
            $this->assertSame('Theme Validation', $agent->business_name);
            $this->assertSame($originalSettings, $agent->settings);
        }

        $missingAccent = $this->validSettings([
            'widget_theme_preset' => 'custom',
            'widget_theme_primary' => '#123456',
        ]);
        unset($missingAccent['widget_theme_accent']);

        $this->actingAs($owner)->put(route('settings.update'), $missingAccent)
            ->assertRedirect()
            ->assertSessionHasErrors('widget_theme_accent');
        $this->assertSame($originalSettings, $agent->fresh()->settings);
    }

    public function test_admin_can_change_theme_while_viewer_cannot(): void
    {
        [$admin, $adminAgent] = $this->tenant('theme-admin', 'admin');
        [$viewer, $agent] = $this->tenant('theme-viewer', 'viewer');

        $this->actingAs($admin)->put(route('settings.update'), $this->validSettings([
            'widget_theme_preset' => 'midnight',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('midnight', $adminAgent->fresh()->settings['widget_theme']['preset']);

        $this->actingAs($viewer)->put(route('settings.update'), $this->validSettings([
            'widget_theme_preset' => 'custom',
            'widget_theme_primary' => '#123456',
            'widget_theme_accent' => '#ABCDEF',
        ]))->assertForbidden();

        $this->assertArrayNotHasKey('widget_theme', $agent->fresh()->settings);
    }

    public function test_widget_launcher_frame_and_demo_use_tenant_theme_without_stale_cache(): void
    {
        [, $agent] = $this->tenant('theme-render', 'owner', [
            'preset' => 'custom',
            'primary' => '#123456',
            'accent' => '#ABCDEF',
        ]);
        [, $otherAgent] = $this->tenant('theme-render-other', 'owner', [
            'preset' => 'ember',
            'primary' => '#7C2D12',
            'accent' => '#FDBA74',
        ]);

        $script = $this->get(route('widget.script', $agent))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public')
            ->assertSee('primary="#123456"', false)
            ->assertSee('accent="#ABCDEF"', false)
            ->assertSee("root.style.setProperty('--legatus-widget-primary',primary)", false)
            ->assertSee('background:var(--legatus-widget-primary)', false)
            ->assertDontSee('#7C2D12');
        $this->assertFalse($script->headers->has('Set-Cookie'));

        $this->get(route('widget.frame', $agent))
            ->assertOk()
            ->assertSee('--widget-primary:#123456', false)
            ->assertSee('--widget-accent:#ABCDEF', false)
            ->assertSee('--widget-primary-foreground:#FFFFFF', false)
            ->assertSee('--widget-accent-foreground:#000000', false);

        $this->get(route('chat.show', $agent))
            ->assertOk()
            ->assertSee('--chat-primary:#123456', false)
            ->assertSee('--chat-accent:#ABCDEF', false);

        $this->get(route('widget.script', $otherAgent))
            ->assertOk()
            ->assertSee('primary="#7C2D12"', false)
            ->assertSee('accent="#FDBA74"', false)
            ->assertDontSee('primary="#123456"', false);
    }

    public function test_malformed_stored_theme_fails_closed_before_rendering(): void
    {
        [, $agent] = $this->tenant('theme-unsafe', 'owner', [
            'preset' => 'custom',
            'primary' => '#123456;}</style><script>alert(1)</script>',
            'accent' => '#ABCDEF',
        ]);

        $resolved = $agent->widgetTheme();
        $this->assertSame('forest', $resolved['preset']);
        $this->assertSame('#163F33', $resolved['primary']);
        $this->assertSame('#D9FF72', $resolved['accent']);

        foreach ([route('widget.script', $agent), route('widget.frame', $agent), route('chat.show', $agent)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('alert(1)', false)
                ->assertDontSee('</style><script>', false);
        }
    }

    /** @return array{User, Agent} */
    private function tenant(string $slug, string $role, ?array $theme = null): array
    {
        $user = User::factory()->create();
        $businessName = str($slug)->headline()->toString();
        $organization = Organization::create(['name' => $businessName, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => $role]);
        $settings = [
            'preserve_me' => 'keep',
            'handoff_threshold' => .72,
            'discount_limit' => 10,
            'delivery_policy' => null,
        ];
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

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Updated Business',
            'agent_name' => 'Nia',
            'tone' => 'Warm and concise',
            'handoff_threshold' => 0.65,
            'discount_limit' => 10,
            'business_hours' => 'Monday-Friday, 09:00-18:00',
            'delivery_timezone' => 'Asia/Tbilisi',
            'delivery_local_cities' => 'თბილისი, Tbilisi',
            'delivery_cutoff' => '15:00',
            'delivery_local_days' => 1,
            'delivery_regional_min_days' => 2,
            'delivery_regional_max_days' => 4,
        ], $overrides);
    }
}
