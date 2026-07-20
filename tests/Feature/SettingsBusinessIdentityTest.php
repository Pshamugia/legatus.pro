<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsBusinessIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_clearly_separates_business_and_assistant_names(): void
    {
        [$owner] = $this->tenant('brand-workspace', 'owner', 'Old Brand', 'Old Assistant');

        $this->actingAs($owner)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('ბიზნესის სახელი · Business name')
            ->assertSee('AI ასისტენტის სახელი · Assistant display name')
            ->assertSee('name="business_name" value="Old Brand"', false)
            ->assertSee('name="agent_name" value="Old Assistant"', false)
            ->assertSee('Ask [Business]')
            ->assertSee('Ask Bukinistebi.ge')
            ->assertSee('AI თანამშრომლის chat identity-ს')
            ->assertSee('მაგალითად, Nia');
    }

    public function test_owner_updates_both_names_without_changing_another_tenant(): void
    {
        [$owner, $agent] = $this->tenant('owner-workspace', 'owner', 'Old Brand', 'Old Assistant');
        [, $otherAgent] = $this->tenant('other-workspace', 'owner', 'Other Brand', 'Other Assistant');
        $agent->update(['settings' => array_merge($agent->settings ?? [], ['preserve_me' => 'yes'])]);

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
            'business_name' => 'Bukinistebi.ge',
            'agent_name' => 'Nia',
        ]))->assertRedirect()
            ->assertSessionHas('success', 'Business identity and AI assistant settings updated.');

        $agent->refresh();
        $this->assertSame('Bukinistebi.ge', $agent->business_name);
        $this->assertSame('Nia', $agent->name);
        $this->assertSame('yes', $agent->settings['preserve_me']);
        $this->assertSame('Bukinistebi.ge', $agent->organization->name);
        $this->assertSame('owner-workspace', $agent->organization->slug);
        $this->assertSame('Other Brand', $otherAgent->fresh()->business_name);
        $this->assertSame('Other Assistant', $otherAgent->fresh()->name);
        $this->assertSame('Other Brand', $otherAgent->fresh()->organization->name);
    }

    public function test_admin_can_update_identity_but_viewer_cannot(): void
    {
        [$admin, $adminAgent] = $this->tenant('admin-workspace', 'admin', 'Admin Brand', 'Admin Assistant');
        [$viewer, $viewerAgent] = $this->tenant('viewer-workspace', 'viewer', 'Viewer Brand', 'Viewer Assistant');

        $this->actingAs($admin)->put(route('settings.update'), $this->validSettings([
            'business_name' => 'Admin New Brand',
            'agent_name' => 'Admin New Assistant',
        ]))->assertRedirect();
        $this->assertSame('Admin New Brand', $adminAgent->fresh()->business_name);
        $this->assertSame('Admin New Assistant', $adminAgent->fresh()->name);

        $this->actingAs($viewer)->put(route('settings.update'), $this->validSettings([
            'business_name' => 'Forbidden Brand',
            'agent_name' => 'Forbidden Assistant',
        ]))->assertForbidden();
        $this->assertSame('Viewer Brand', $viewerAgent->fresh()->business_name);
        $this->assertSame('Viewer Assistant', $viewerAgent->fresh()->name);

        $this->actingAs($viewer)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="business_name" value="Viewer Brand"', false)
            ->assertSee('name="agent_name" value="Viewer Assistant"', false)
            ->assertSee('Only an owner or admin can change workspace settings.')
            ->assertDontSee('Save business & AI settings');
    }

    public function test_identity_validation_is_atomic_and_guest_must_sign_in(): void
    {
        [$owner, $agent] = $this->tenant('validation-workspace', 'owner', 'Safe Brand', 'Safe Assistant');

        $this->put(route('settings.update'), $this->validSettings())->assertRedirect(route('login'));

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
            'business_name' => str_repeat('B', 121),
            'agent_name' => str_repeat('A', 81),
        ]))->assertRedirect()
            ->assertSessionHasErrors(['business_name', 'agent_name']);

        $agent->refresh();
        $this->assertSame('Safe Brand', $agent->business_name);
        $this->assertSame('Safe Assistant', $agent->name);
    }

    public function test_unicode_identity_values_are_trimmed_and_control_characters_are_rejected(): void
    {
        [$owner, $agent] = $this->tenant('unicode-workspace', 'owner', 'Safe Brand', 'Safe Assistant');

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
            'business_name' => " \u{00A0}ბუკინისტები.ge\u{2003} ",
            'agent_name' => "  ნია \u{00A0}",
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ბუკინისტები.ge', $agent->fresh()->business_name);
        $this->assertSame('ნია', $agent->fresh()->name);

        $this->actingAs($owner)->put(route('settings.update'), $this->validSettings([
            'business_name' => "Unsafe\nBrand",
            'agent_name' => 'Still Safe',
        ]))->assertRedirect()->assertSessionHasErrors('business_name');
        $this->assertSame('ბუკინისტები.ge', $agent->fresh()->business_name);
        $this->assertSame('ნია', $agent->fresh()->name);
    }

    public function test_owner_can_only_remove_a_member_of_the_active_workspace(): void
    {
        [$owner, $agent] = $this->tenant('member-workspace', 'owner', 'Member Brand', 'Member Assistant');
        [$foreignUser, $foreignAgent] = $this->tenant('foreign-member-workspace', 'viewer', 'Foreign Brand', 'Foreign Assistant');
        $member = User::factory()->create();
        $agent->organization->users()->attach($member, ['role' => 'viewer']);

        $this->actingAs($owner)
            ->delete(route('team.remove', $foreignUser))
            ->assertNotFound();
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $foreignAgent->organization_id,
            'user_id' => $foreignUser->id,
        ]);

        $this->actingAs($owner)
            ->delete(route('team.remove', $member))
            ->assertRedirect();
        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $agent->organization_id,
            'user_id' => $member->id,
        ]);
    }

    /** @return array{User, Agent} */
    private function tenant(string $slug, string $role, string $businessName, string $agentName): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => $businessName, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => $role]);
        $agent = $organization->agents()->create([
            'name' => $agentName,
            'slug' => $slug.'-agent',
            'business_name' => $businessName,
            'tone' => 'warm',
            'channels' => ['web'],
            'settings' => [
                'handoff_threshold' => .72,
                'discount_limit' => 10,
                'delivery_policy' => null,
            ],
        ]);

        return [$user, $agent];
    }

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Updated Brand',
            'agent_name' => 'Updated Assistant',
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
