<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNavigationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authenticated_workspace_screen_exposes_the_active_business_and_account_controls(): void
    {
        $user = User::factory()->create(['name' => 'Workspace Owner']);
        $active = $this->workspace($user, 'bukinistebi.ge');
        $other = $this->workspace($user, 'Second Store');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $active->id]);

        foreach ([
            'dashboard',
            'onboarding',
            'knowledge.index',
            'inbox.index',
            'channels.index',
            'analytics.index',
            'settings.index',
            'workspaces.index',
        ] as $routeName) {
            $response = $this->get(route($routeName));

            $response->assertOk()
                ->assertSee('Active business')
                ->assertSee('bukinistebi.ge')
                ->assertSee('data-active-business="bukinistebi.ge"', false)
                ->assertSee('data-workspace-switcher="bukinistebi.ge"', false)
                ->assertSee('Workspace on Legatus')
                ->assertSee('Business setup')
                ->assertSee('+ Add business')
                ->assertSee('method="post" action="'.route('logout').'"', false)
                ->assertSee(route('workspaces.switch', $other), false);
        }
    }

    public function test_navigation_uses_the_active_workspace_name_instead_of_a_stale_agent_brand(): void
    {
        $user = User::factory()->create();
        $active = $this->workspace($user, 'bukinistebi.ge');
        $active->agents()->update(['business_name' => 'Outdated imported brand']);

        $response = $this->actingAs($user)
            ->withSession([TenantContext::SESSION_KEY => $active->id])
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('data-active-business="bukinistebi.ge"', false)
            ->assertSee('data-workspace-switcher="bukinistebi.ge"', false)
            ->assertDontSee('data-active-business="Outdated imported brand"', false)
            ->assertDontSee('data-workspace-switcher="Outdated imported brand"', false);
    }

    private function workspace(User $user, string $name): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
        ]);
        $organization->users()->attach($user, ['role' => 'owner']);
        Agent::create([
            'organization_id' => $organization->id,
            'name' => $name.' Assistant',
            'slug' => str($organization->slug)->append('-agent'),
            'business_name' => $name,
            'channels' => ['web'],
            'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10],
        ]);

        return $organization;
    }
}
