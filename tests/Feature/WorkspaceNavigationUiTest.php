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
        $active = $this->workspace($user, 'Bukinistebi.ge');
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
                ->assertSee('Bukinistebi.ge')
                ->assertSee('Business setup')
                ->assertSee('+ Add business')
                ->assertSee('method="post" action="'.route('logout').'"', false)
                ->assertSee(route('workspaces.switch', $other), false);
        }
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
